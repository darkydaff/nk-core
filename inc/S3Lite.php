<?php

/**
 * A lightweight, memory-efficient S3 client for PHP.
 * Supports AWS Signature V4 with streaming to handle large backups without RAM issues.
 */
class S3Lite {
    private $key;
    private $secret;
    private $region;
    private $endpoint;
    private $bucket;

    public function __construct($key, $secret, $region, $endpoint, $bucket) {
        $this->key      = $key;
        $this->secret   = $secret;
        $this->region   = $region;
        $this->endpoint = rtrim($endpoint, '/');
        $this->bucket   = $bucket;
    }

    /**
     * Upload a file to S3 using streaming (Low Memory Usage).
     */
    public function putObject($sourceFile, $remotePath) {
        if (!file_exists($sourceFile)) {
            throw new Exception("File not found: $sourceFile");
        }

        $method  = 'PUT';
        $service = 's3';
        $host    = parse_url($this->endpoint, PHP_URL_HOST);
        $uri     = '/' . $this->bucket . '/' . ltrim($remotePath, '/');
        $url     = $this->endpoint . $uri;

        $t = time();
        $amzDate  = gmdate('Ymd\THis\Z', $t);
        $dateStamp = gmdate('Ymd', $t);

        // We use SHA256 of the file for the signature
        $payloadHash = hash_file('sha256', $sourceFile);

        $headers = [
            'Host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
            'Content-Type'         => 'application/x-gzip',
        ];

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders    = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim((string)($v ?? '')) . "\n";
            $signedHeaders    .= strtolower($k) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = "$method\n$uri\n\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $credentialScope  = "$dateStamp/$this->region/$service/aws4_request";
        $stringToSign     = "AWS4-HMAC-SHA256\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($this->secret, $dateStamp, $this->region, $service);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = "AWS4-HMAC-SHA256 Credential=$this->key/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        $curlHeaders = ["Authorization: $authHeader"];
        foreach ($headers as $k => $v) { $curlHeaders[] = "$k: $v"; }

        $ch = curl_init($url);
        $fileHandle = fopen($sourceFile, 'r');
        
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fileHandle);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($sourceFile));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes for large uploads

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        fclose($fileHandle);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("S3 Upload Failed (HTTP $httpCode): " . $response);
        }
        return true;
    }

    /**
     * List objects with S3 Signature V4.
     */
    public function listObjects($prefix = '') {
        $method = 'GET';
        $service = 's3';
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $uri = '/' . $this->bucket . '/';
        $query = 'list-type=2&prefix=' . urlencode($prefix);
        $url = $this->endpoint . $uri . '?' . $query;

        $t = time();
        $amzDate = gmdate('Ymd\THis\Z', $t);
        $dateStamp = gmdate('Ymd', $t);

        $headers = [
            'Host' => $host,
            'x-amz-content-sha256' => hash('sha256', ''),
            'x-amz-date'           => $amzDate,
        ];

        ksort($headers);
        $canonicalHeaders = ''; $signedHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim((string)($v ?? '')) . "\n";
            $signedHeaders    .= strtolower($k) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = "$method\n$uri\n$query\n$canonicalHeaders\n$signedHeaders\n" . hash('sha256', '');
        $credentialScope  = "$dateStamp/$this->region/$service/aws4_request";
        $stringToSign     = "AWS4-HMAC-SHA256\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($this->secret, $dateStamp, $this->region, $service);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = "AWS4-HMAC-SHA256 Credential=$this->key/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        $curlHeaders = ["Authorization: $authHeader"];
        foreach ($headers as $k => $v) { $curlHeaders[] = "$k: $v"; }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new Exception("S3 List Failed (HTTP $httpCode)");
        }

        $xml = new SimpleXMLElement($response);
        $files = [];
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $content) {
                $files[] = [
                    'key' => (string)$content->Key,
                    'size' => (int)$content->Size,
                    'last_modified' => (string)$content->LastModified
                ];
            }
        }
        return $files;
    }

    /**
     * Download object directly to disk.
     */
    public function getObject($remotePath, $localPath) {
        $method = 'GET';
        $service = 's3';
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $uri = '/' . $this->bucket . '/' . ltrim($remotePath, '/');
        $url = $this->endpoint . $uri;

        $t = time();
        $amzDate = gmdate('Ymd\THis\Z', $t);
        $dateStamp = gmdate('Ymd', $t);

        $headers = [
            'Host' => $host,
            'x-amz-content-sha256' => hash('sha256', ''),
            'x-amz-date' => $amzDate,
        ];

        ksort($headers);
        $canonicalHeaders = ''; $signedHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim((string)($v ?? '')) . "\n";
            $signedHeaders    .= strtolower($k) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = "$method\n$uri\n\n$canonicalHeaders\n$signedHeaders\n" . hash('sha256', '');
        $credentialScope  = "$dateStamp/$this->region/$service/aws4_request";
        $stringToSign     = "AWS4-HMAC-SHA256\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($this->secret, $dateStamp, $this->region, $service);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = "AWS4-HMAC-SHA256 Credential=$this->key/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        $curlHeaders = ["Authorization: $authHeader"];
        foreach ($headers as $k => $v) { $curlHeaders[] = "$k: $v"; }

        $ch = curl_init($url);
        $fp = fopen($localPath, 'w+');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($fp);

        if ($httpCode !== 200) {
            if (file_exists($localPath)) unlink($localPath);
            throw new Exception("S3 Download Failed (HTTP $httpCode)");
        }
        return true;
    }

    /**
     * Delete an object from S3.
     */
    public function deleteObject($remotePath) {
        $method = 'DELETE';
        $service = 's3';
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        $uri = '/' . $this->bucket . '/' . ltrim($remotePath, '/');
        $url = $this->endpoint . $uri;

        $t = time();
        $amzDate = gmdate('Ymd\THis\Z', $t);
        $dateStamp = gmdate('Ymd', $t);

        $headers = [
            'Host' => $host,
            'x-amz-content-sha256' => hash('sha256', ''),
            'x-amz-date' => $amzDate,
        ];

        ksort($headers);
        $canonicalHeaders = ''; $signedHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim((string)($v ?? '')) . "\n";
            $signedHeaders    .= strtolower($k) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = "$method\n$uri\n\n$canonicalHeaders\n$signedHeaders\n" . hash('sha256', '');
        $credentialScope  = "$dateStamp/$this->region/$service/aws4_request";
        $stringToSign     = "AWS4-HMAC-SHA256\n$amzDate\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        $signingKey = $this->getSignatureKey($this->secret, $dateStamp, $this->region, $service);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authHeader = "AWS4-HMAC-SHA256 Credential=$this->key/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        $curlHeaders = ["Authorization: $authHeader"];
        foreach ($headers as $k => $v) { $curlHeaders[] = "$k: $v"; }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 204 && $httpCode !== 200) {
            throw new Exception("S3 Delete Failed (HTTP $httpCode): " . $response);
        }
        return true;
    }

    private function getSignatureKey($key, $dateStamp, $regionName, $serviceName) {
        $kSecret = 'AWS4' . $key;
        $kDate   = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $regionName, $kDate, true);
        $kService = hash_hmac('sha256', $serviceName, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        return $kSigning;
    }
}
