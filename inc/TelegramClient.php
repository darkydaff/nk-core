<?php

class TelegramClient {
    private $botToken;
    private $chatId;
    private $proxy = [];

    public function __construct($botToken, $chatId, $proxy = []) {
        $this->botToken = $botToken;
        $this->chatId   = $chatId;
        $this->proxy    = $proxy;
    }

    /**
     * Send a document to the configured chat.
     */
    public function sendDocument($filePath, $caption = '') {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendDocument";
        
        $postData = [
            'chat_id'  => $this->chatId,
            'caption'  => $caption,
            'document' => new CURLFile($filePath)
        ];

        return $this->request($url, $postData);
    }

    private function request($url, $postData = []) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        // Handle Proxy
        if (!empty($this->proxy['enabled']) && $this->proxy['enabled'] == 'true') {
            $proxyType = strtolower($this->proxy['type'] ?? 'socks5');
            $proxyHost = $this->proxy['host'] ?? '';
            $proxyPort = $this->proxy['port'] ?? '';
            $proxyAuth = $this->proxy['auth'] ?? '';

            if ($proxyHost && $proxyPort) {
                curl_setopt($ch, CURLOPT_PROXY, "$proxyHost:$proxyPort");
                
                if ($proxyType === 'socks5') {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                } else {
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
                }

                if ($proxyAuth) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyAuth);
                }
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        if ($error) {
            throw new Exception("Telegram Request Failed: $error");
        }

        $result = json_decode($response, true);
        if (!$result || empty($result['ok'])) {
            $msg = $result['description'] ?? 'Unknown error';
            throw new Exception("Telegram API Error: $msg");
        }

        return $result;
    }
}
