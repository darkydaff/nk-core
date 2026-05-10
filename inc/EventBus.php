<?php
declare(strict_types=1);

/**
 * EventBus Service
 * Bridge between PHP and the WebSocket server (Centrifugo).
 */
class EventBus
{
    /**
     * Publish a message to a WebSocket channel
     */
    public static function publish(string $channel, array $data): bool
    {
        $apiKey = Config::get('CENTRIFUGO_API_KEY');
        $apiUrl = Config::get('CENTRIFUGO_API_URL', 'http://centrifugo:8000/api');

        if (!$apiKey) {
            // If WebSockets aren't configured yet, just fail silently or log
            return false;
        }

        try {
            $payload = json_encode([
                'method' => 'publish',
                'params' => [
                    'channel' => $channel,
                    'data' => $data
                ]
            ]);

            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: apikey ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 200) {
                Logger::error("EventBus: Centrifugo returned HTTP {$httpCode}", ['response' => $response]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Logger::error("EventBus: Failed to publish to Centrifugo", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Generate a connection token for the frontend (JWT)
     */
    public static function generateConnectionToken(string $userId): string
    {
        $secret = Config::get('CENTRIFUGO_TOKEN_HMAC_SECRET');
        if (!$secret) {
            throw new Exception("CENTRIFUGO_TOKEN_HMAC_SECRET not configured");
        }

        // Centrifugo expects sub (subject) to be the user ID as a string
        $payload = [
            'sub' => (string)$userId,
            'exp' => time() + 3600 // 1 hour
        ];

        return JWT::encode($payload, $secret);
    }

    /**
     * Generate a subscription token for a specific channel (Private channels)
     */
    public static function generateSubscriptionToken(string $userId, string $channel): string
    {
        $secret = Config::get('CENTRIFUGO_TOKEN_HMAC_SECRET');
        if (!$secret) {
            throw new Exception("CENTRIFUGO_TOKEN_HMAC_SECRET not configured");
        }

        $payload = [
            'sub' => (string)$userId,
            'channel' => $channel,
            'exp' => time() + 3600
        ];

        return JWT::encode($payload, $secret);
    }
}
