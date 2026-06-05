<?php
declare(strict_types=1);

/**
 * Translator class for multi-language support
 * Supports automatic translation using external services
 */
class Translator
{
    private static ?string $currentLanguage = null;
    private static array $translations = [];
    private static array $englishFallback = [];
    private static array $supportedLanguages = [];

    /**
     * Initialize translator
     */
    public static function init(): void
    {
        self::normalizeLocales();

        // Load supported languages
        self::loadSupportedLanguages();

        // Detect language from session, cookie, or browser
        self::detectLanguage();

        // Load translations for current language
        self::loadTranslations(self::$currentLanguage);
    }

    /**
     * Load supported languages from database
     */
    private static function loadSupportedLanguages(): void
    {
        $pdo = DB::conn();
        $stmt = $pdo->query("SELECT code, name, native_name FROM languages WHERE is_active = 1 AND code IN ('en', 'uk', 'ru') ORDER BY FIELD(code, 'en', 'uk', 'ru')");
        self::$supportedLanguages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Keep only en/uk/ru active and backfill missing keys from English.
     */
    private static function normalizeLocales(): void
    {
        $pdo = DB::conn();

        $pdo->exec("
            INSERT INTO languages (code, name, native_name, is_active) VALUES
            ('en', 'English', 'English', 1),
            ('uk', 'Ukrainian', 'Українська', 1),
            ('ru', 'Russian', 'Русский', 1)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                native_name = VALUES(native_name),
                is_active = 1
        ");

        $pdo->exec("UPDATE languages SET is_active = CASE WHEN code IN ('en','uk','ru') THEN 1 ELSE 0 END");

        foreach (['uk', 'ru'] as $locale) {
            $stmt = $pdo->prepare("
                INSERT INTO translations (locale, category, key_name, translation)
                SELECT ?, en.category, en.key_name, en.translation
                FROM translations en
                LEFT JOIN translations t
                    ON t.locale = ?
                    AND t.category = en.category
                    AND t.key_name = en.key_name
                WHERE en.locale = 'en' AND t.id IS NULL
            ");
            $stmt->execute([$locale, $locale]);
        }

        self::seedEssentialTranslations();
    }

    /**
     * Ensure core status and common translations exist for all primary locales.
     * This acts as an automatic migration for critical UI components.
     */
    private static function seedEssentialTranslations(): void
    {
        $pdo = DB::conn();
        $essential = [
            'status.online' => ['en' => 'Online', 'uk' => 'В мережі', 'ru' => 'В сети'],
            'status.offline' => ['en' => 'Offline', 'uk' => 'Офлайн', 'ru' => 'Оффлайн'],
            'status.revoked' => ['en' => 'Revoked', 'uk' => 'Відкликано', 'ru' => 'Отозван'],
            'status.provisioning' => ['en' => 'Provisioning', 'uk' => 'Налаштування', 'ru' => 'Настройка'],
            'status.deleting' => ['en' => 'Deleting', 'uk' => 'Видалення', 'ru' => 'Удаление'],
            'status.verifying' => ['en' => 'Verifying', 'uk' => 'Перевірка', 'ru' => 'Проверка'],
            'status.error' => ['en' => 'Error', 'uk' => 'Помилка', 'ru' => 'Ошибка'],
            'status.never' => ['en' => 'Never Connected', 'uk' => 'Не підключався', 'ru' => 'Не подключался'],
            'status.all' => ['en' => 'All Status', 'uk' => 'Усі статуси', 'ru' => 'Все статусы'],
            // Also seed common filter labels to ensure consistency
            'common.status_all' => ['en' => 'All Status', 'uk' => 'Усі статуси', 'ru' => 'Все статусы'],
            'common.status_online' => ['en' => 'Online', 'uk' => 'В мережі', 'ru' => 'В сети'],
            'common.status_offline' => ['en' => 'Offline', 'uk' => 'Офлайн', 'ru' => 'Оффлайн'],
            'common.status_revoked' => ['en' => 'Revoked', 'uk' => 'Відкликано', 'ru' => 'Отозван'],
            // New Map & Deployment keys
            'map.fleet_distribution' => ['en' => 'Client Distribution', 'uk' => 'Розподіл клієнтів', 'ru' => 'Распределение клиентов'],
            'deployment.progress' => ['en' => 'Deployment Progress', 'uk' => 'Прогрес розгортання', 'ru' => 'Прогресс развертывания'],
            'deployment.waiting_to_start' => ['en' => 'Waiting to start...', 'uk' => 'Очікування запуску...', 'ru' => 'Ожидание запуска...'],
            'deployment.cancel' => ['en' => 'Cancel Deployment', 'uk' => 'Скасувати розгортання', 'ru' => 'Отменить развертывание'],
            'servers.initiating' => ['en' => 'Initiating', 'uk' => 'Ініціалізація', 'ru' => 'Инициализация'],
            'servers.deploying' => ['en' => 'Deploying', 'uk' => 'Розгортання', 'ru' => 'Развертывание'],
            'servers.deploy_step_hint' => ['en' => 'Tailoring kernel parameters for high-performance VPN throughput...', 'uk' => 'Налаштування параметрів ядра для високої пропускної здатності VPN...', 'ru' => 'Настройка параметров ядра для высокой пропускной способности VPN...'],
            'servers.deployment_in_progress' => ['en' => 'Deployment In Progress', 'uk' => 'Розгортання триває', 'ru' => 'Выполняется развертывание'],
            'servers.deploying_to' => ['en' => 'Provisioning node:', 'uk' => 'Налаштування вузла:', 'ru' => 'Настройка узла:'],
            'settings.speed_limits' => ['en' => 'Default Speed Limits', 'uk' => 'Обмеження швидкості за замовчуванням', 'ru' => 'Ограничения скорости по умолчанию'],
            'settings.speed_limits_desc' => ['en' => 'Set default upload and download speed limits for VPN clients. Individual client overrides will take precedence.', 'uk' => 'Встановіть обмеження швидкості завантаження та вивантаження за замовчуванням для клієнтів VPN. Індивідуальні налаштування клієнта мають пріоритет.', 'ru' => 'Установите ограничения скорости отдачи и скачивания по умолчанию для клиентов VPN. Индивидуальные настройки клиента имеют приоритет.'],
            'settings.speed_limit_up' => ['en' => 'Default Upload Speed (Mbps)', 'uk' => 'Швидкість вивантаження за замовчуванням (Мбіт/с)', 'ru' => 'Скорость отдачи по умолчанию (Мбит/с)'],
            'settings.speed_limit_down' => ['en' => 'Default Download Speed (Mbps)', 'uk' => 'Швидкість завантаження за замовчуванням (Мбіт/с)', 'ru' => 'Скорость скачивания по умолчанию (Мбит/с)'],
            'settings.speed_limit_help' => ['en' => 'Use 0 for unlimited speed.', 'uk' => 'Використовуйте 0 для необмеженої швидкості.', 'ru' => 'Используйте 0 для неограниченной скорости.'],
            'clients.speed_limit' => ['en' => 'Speed Limits', 'uk' => 'Обмеження швидкості', 'ru' => 'Ограничения скорости'],
            'clients.speed_limit_up' => ['en' => 'Upload Limit (Mbps)', 'uk' => 'Ліміт вивантаження (Мбіт/с)', 'ru' => 'Лимит отдачи (Мбит/с)'],
            'clients.speed_limit_down' => ['en' => 'Download Limit (Mbps)', 'uk' => 'Ліміт завантаження (Мбіт/с)', 'ru' => 'Лимит скачивания (Мбит/с)'],
            'clients.speed_limit_placeholder_inherit' => ['en' => 'Inherit default (%s Mbps)', 'uk' => 'Успадкувати за замовчуванням (%s Мбіт/с)', 'ru' => 'Наследовать по умолчанию (%s Мбит/с)'],
            'clients.speed_limit_placeholder_inherit_unlimited' => ['en' => 'Inherit default (Unlimited)', 'uk' => 'Успадкувати за замовчуванням (Необмежено)', 'ru' => 'Наследовать по умолчанию (Безлимитно)'],
            'clients.speed_limit_placeholder_unlimited' => ['en' => 'Unlimited (0)', 'uk' => 'Необмежено (0)', 'ru' => 'Безлимитно (0)'],
            'clients.speed_limit_help' => ['en' => 'Enter custom speed in Mbps, 0 for unlimited, or leave empty to inherit settings.', 'uk' => 'Введіть швидкість у Мбіт/с, 0 для необмеженої або залиште порожнім, щоб успадкувати.', 'ru' => 'Введите скорость в Мбит/с, 0 для неограниченной или оставьте пустым, чтобы наследовать.'],
        ];

        $stmt = $pdo->prepare("INSERT INTO translations (locale, category, key_name, translation) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE translation = VALUES(translation)");

        foreach ($essential as $key => $locales) {
            $parts = explode('.', $key, 2);
            $cat = $parts[0];
            $kn = $parts[1];
            foreach ($locales as $locale => $text) {
                $stmt->execute([$locale, $cat, $kn, $text]);
            }
        }
    }

    /**
     * Detect user's preferred language
     */
    private static function detectLanguage(): void
    {
        // 1. Check session
        if (isset($_SESSION['language'])) {
            if (self::isSupported($_SESSION['language'])) {
                self::$currentLanguage = $_SESSION['language'];
                return;
            } else {
                unset($_SESSION['language']);
            }
        }

        // 2. Check cookie
        if (isset($_COOKIE['language'])) {
            if (self::isSupported($_COOKIE['language'])) {
                self::$currentLanguage = $_COOKIE['language'];
                $_SESSION['language'] = self::$currentLanguage;
                return;
            } else {
                setcookie('language', '', time() - 3600, '/');
            }
        }

        // 3. Check browser language
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
            if (self::isSupported($browserLang)) {
                self::$currentLanguage = $browserLang;
                $_SESSION['language'] = self::$currentLanguage;
                return;
            }
        }

        // 4. Default to English
        self::$currentLanguage = 'en';
        $_SESSION['language'] = 'en';
    }

    /**
     * Check if language is supported
     */
    public static function isSupported(string $code): bool
    {
        foreach (self::$supportedLanguages as $lang) {
            if ($lang['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load translations for specific language
     */
    private static function loadTranslations(string $languageCode): void
    {
        $pdo = DB::conn();

        // Load target language
        $stmt = $pdo->prepare('SELECT CONCAT(category, ".", key_name) as trans_key, translation FROM translations WHERE locale = ?');
        $stmt->execute([$languageCode]);
        $translations = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        self::$translations = $translations ?: [];

        // Load fallback if not English
        if ($languageCode !== 'en') {
            $stmt = $pdo->prepare('SELECT CONCAT(category, ".", key_name) as trans_key, translation FROM translations WHERE locale = "en"');
            $stmt->execute();
            self::$englishFallback = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        } else {
            self::$englishFallback = [];
        }
    }

    public static function translate(string $key, array $params = [], ?string $default = null): string
    {
        $translation = self::$translations[$key] ?? self::$englishFallback[$key] ?? ($default ?: $key);

        if (!empty($params)) {
            return sprintf($translation, ...$params);
        }

        return $translation;
    }

    /**
     * Short alias for translate()
     */
    public static function t(string $key, array $params = [], ?string $default = null): string
    {
        return self::translate($key, $params, $default);
    }

    /**
     * Get current language code
     */
    public static function getCurrentLanguage(): string
    {
        return self::$currentLanguage ?? 'en';
    }

    /**
     * Set current language
     */
    public static function setLanguage(string $code): bool
    {
        if (!self::isSupported($code)) {
            return false;
        }

        self::$currentLanguage = $code;
        $_SESSION['language'] = $code;
        setcookie('language', $code, time() + 31536000, '/'); // 1 year

        // Reload translations
        self::loadTranslations($code);

        return true;
    }

    /**
     * Get all supported languages
     */
    public static function getSupportedLanguages(): array
    {
        return self::$supportedLanguages;
    }

    /**
     * Auto-translate missing keys using AI (OpenRouter API)
     * 
     * @param string $targetLang Target language code
     * @param string $key Translation key
     * @param string $sourceText Source text (English)
     * @return bool Success status
     */
    public static function autoTranslate(string $targetLang, string $key, string $sourceText): bool
    {
        if ($targetLang === 'en') {
            return false; // English is source language
        }

        try {
            // Language mapping
            $langNames = [
                'ru' => 'Russian',
                'uk' => 'Ukrainian'
            ];

            $targetLanguage = $langNames[$targetLang] ?? 'English';

            // Use OpenRouter API with multiple free model candidates
            $translatedText = self::translateWithAI($sourceText, $targetLanguage);

            if (!$translatedText || $translatedText === $sourceText) {
                \Logger::error("Translation failed for '{$sourceText}' to {$targetLang}");
                return false;
            }

            // Save to database
            $pdo = DB::conn();
            // Split key into category and key_name (e.g., "common.speed" -> "common" + "speed")
            $parts = explode('.', $key, 2);
            $category = $parts[0] ?? 'common';
            $keyName = $parts[1] ?? $key;

            $stmt = $pdo->prepare('
                INSERT INTO translations (locale, category, key_name, translation)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE translation = VALUES(translation)
            ');

            return $stmt->execute([$targetLang, $category, $keyName, $translatedText]);

        } catch (Exception $e) {
            \Logger::error("Auto-translation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Translate text using AI with model fallback
     */
    private static function translateWithAI(string $text, string $targetLanguage): ?string
    {
        // Use reliable paid models with fallback
        $models = [
            'anthropic/claude-3.5-sonnet',
            'openai/gpt-4o-mini',
            'google/gemini-pro-1.5'
        ];

        foreach ($models as $model) {
            try {
                $result = self::callOpenRouter($model, $text, $targetLanguage);
                if ($result && $result !== $text) {
                    return $result;
                }
            } catch (Exception $e) {
                \Logger::error("Model {$model} failed: " . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Get OpenRouter API key from database
     */
    private static function getOpenRouterKey(): ?string
    {
        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare("SELECT api_key FROM api_keys WHERE service_name = 'openrouter' AND is_active = 1 LIMIT 1");
            $stmt->execute();
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            \Logger::error('Failed to get OpenRouter API key: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Call OpenRouter API
     */
    private static function callOpenRouter(string $model, string $text, string $targetLanguage): ?string
    {
        $apiKey = self::getOpenRouterKey();

        if (!$apiKey) {
            \Logger::error('OpenRouter API key not configured');
            return null;
        }

        $messages = [
            [
                'role' => 'system',
                'content' => "You are a professional translator. Translate the given English text to {$targetLanguage}. Return ONLY the translation, no explanations or additional text. Keep the same tone and style. If there are parameters in curly braces like {param}, keep them unchanged."
            ],
            [
                'role' => 'user',
                'content' => "Translate to {$targetLanguage}: {$text}"
            ]
        ];

        $data = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => 200,
            'temperature' => 0.1
        ];

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://amnez.ia',
            'X-Title: Amnezia VPN Panel'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            \Logger::error("OpenRouter API error: HTTP {$httpCode} - Model: {$model}");
            return null;
        }

        $result = json_decode($response, true);

        if (!isset($result['choices'][0]['message']['content'])) {
            \Logger::error("OpenRouter API error: No content in response - Model: {$model}");
            return null;
        }

        return trim($result['choices'][0]['message']['content']);
    }

    /**
     * Translate all missing keys for a language
     * 
     * @param string $targetLang Target language code
     * @return array Statistics (total, translated, failed)
     */
    public static function translateMissingKeys(string $targetLang): array
    {
        if ($targetLang === 'en') {
            return ['total' => 0, 'translated' => 0, 'failed' => 0];
        }

        $pdo = DB::conn();

        // Get all English keys
        $stmt = $pdo->query("SELECT CONCAT(category, '.', key_name) as trans_key, translation FROM translations WHERE locale = 'en'");
        $englishKeys = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Get existing translations for target language
        $stmt = $pdo->prepare("SELECT CONCAT(category, '.', key_name) FROM translations WHERE locale = ?");
        $stmt->execute([$targetLang]);
        $existingKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stats = [
            'total' => count($englishKeys),
            'translated' => count($existingKeys),
            'failed' => 0
        ];

        // Find missing keys
        $missingKeys = [];
        foreach ($englishKeys as $key => $value) {
            if (!in_array($key, $existingKeys)) {
                $missingKeys[$key] = $value;
            }
        }

        if (empty($missingKeys)) {
            return $stats;
        }

        // Try batch translation first
        $batchResult = self::translateBatch($missingKeys, $targetLang);

        if ($batchResult) {
            foreach ($batchResult as $key => $translatedText) {
                if (isset($missingKeys[$key]) && $translatedText) {
                    self::setTranslation($targetLang, $key, $translatedText);
                    $stats['translated']++;
                }
            }
            return $stats;
        }

        // Fallback to individual translation
        foreach ($missingKeys as $key => $value) {
            if (self::autoTranslate($targetLang, $key, $value)) {
                $stats['translated']++;
                sleep(3); // 3 second delay between requests to avoid rate limits
            } else {
                $stats['failed']++;
                sleep(2); // Also delay on failure
            }
        }

        return $stats;
    }

    /**
     * Batch translate multiple texts at once (more efficient)
     */
    private static function translateBatch(array $texts, string $targetLang): ?array
    {
        if (empty($texts) || !is_array($texts)) {
            return null;
        }

        try {
            $langNames = [
                'ru' => 'Russian',
                'uk' => 'Ukrainian'
            ];

            $targetLanguage = $langNames[$targetLang] ?? 'English';

            // Prepare texts for JSON
            $textsForJson = [];
            foreach ($texts as $key => $text) {
                $textsForJson[] = [
                    'key' => $key,
                    'text' => $text
                ];
            }

            $jsonTexts = json_encode($textsForJson, JSON_UNESCAPED_UNICODE);

            $models = [
                'anthropic/claude-3.5-sonnet',
                'openai/gpt-4o-mini',
                'google/gemini-pro-1.5'
            ];

            foreach ($models as $model) {
                try {
                    $result = self::callOpenRouterBatch($model, $jsonTexts, $targetLanguage);

                    if ($result && is_array($result)) {
                        // Validate results
                        $translations = [];
                        foreach ($result as $item) {
                            if (isset($item['key']) && isset($item['text']) && isset($texts[$item['key']])) {
                                $translations[$item['key']] = $item['text'];
                            }
                        }

                        if (count($translations) > 0) {
                            \Logger::error("Batch translation successful: " . count($translations) . " texts to {$targetLang}");
                            return $translations;
                        }
                    }
                } catch (Exception $e) {
                    \Logger::error("Batch translation with {$model} failed: " . $e->getMessage());
                    continue;
                }
            }

            return null;
        } catch (Exception $e) {
            \Logger::error('Batch translation error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Call OpenRouter API for batch translation
     */
    private static function callOpenRouterBatch(string $model, string $jsonTexts, string $targetLanguage): ?array
    {
        $apiKey = self::getOpenRouterKey();

        if (!$apiKey) {
            \Logger::error('OpenRouter API key not configured');
            return null;
        }

        $messages = [
            [
                'role' => 'system',
                'content' => "You are a professional translator. Translate the given English texts to {$targetLanguage}. Return ONLY a JSON array with objects containing 'key' and 'text' fields. Each 'text' should contain only the translated text. Keep the same tone and style. If there are parameters in curly braces like {param}, keep them unchanged. Do not add any explanations or additional text outside the JSON."
            ],
            [
                'role' => 'user',
                'content' => "Translate these English texts to {$targetLanguage}:\n{$jsonTexts}"
            ]
        ];

        $data = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => 4000,
            'temperature' => 0.1
        ];

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://amnez.ia',
            'X-Title: Amnezia VPN Panel'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            \Logger::error("OpenRouter batch API error: HTTP {$httpCode}");
            return null;
        }

        $result = json_decode($response, true);

        if (!isset($result['choices'][0]['message']['content'])) {
            return null;
        }

        $responseText = trim($result['choices'][0]['message']['content']);

        // Remove markdown code blocks if present
        if (strpos($responseText, '```json') !== false) {
            $responseText = preg_replace('/```json\s*/', '', $responseText);
            $responseText = preg_replace('/\s*```/', '', $responseText);
            $responseText = trim($responseText);
        }

        $translatedJson = json_decode($responseText, true);

        if (!is_array($translatedJson)) {
            \Logger::error("Batch translation: Invalid JSON response");
            return null;
        }

        return $translatedJson;
    }

    /**
     * Get translation statistics
     */
    public static function getStatistics(): array
    {
        $pdo = DB::conn();

        $stmt = $pdo->query("
            SELECT 
                l.code,
                l.name,
                l.native_name,
                COUNT(t.id) as translated_count,
                (SELECT COUNT(*) FROM translations WHERE locale = 'en') as total_count
            FROM languages l
            LEFT JOIN translations t ON l.code = t.locale
            WHERE l.is_active = 1
            GROUP BY l.code, l.name, l.native_name
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add or update translation
     */
    public static function setTranslation(string $languageCode, string $key, string $value): bool
    {
        $pdo = DB::conn();
        // Split key into category and key_name
        $parts = explode('.', $key, 2);
        $category = $parts[0] ?? 'common';
        $keyName = $parts[1] ?? $key;

        $stmt = $pdo->prepare('
            INSERT INTO translations (locale, category, key_name, translation)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE translation = VALUES(translation)
        ');

        return $stmt->execute([$languageCode, $category, $keyName, $value]);
    }

    /**
     * Export translations to JSON file
     */
    public static function exportToJson(string $languageCode): string
    {
        $pdo = DB::conn();
        $stmt = $pdo->prepare('SELECT CONCAT(category, ".", key_name) as trans_key, translation FROM translations WHERE locale = ?');
        $stmt->execute([$languageCode]);

        $translations = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Import translations from JSON file
     */
    public static function importFromJson(string $languageCode, string $json): bool
    {
        $translations = json_decode($json, true);

        if (!is_array($translations)) {
            return false;
        }

        $pdo = DB::conn();
        $pdo->beginTransaction();

        try {
            foreach ($translations as $key => $value) {
                self::setTranslation($languageCode, $key, $value);
            }

            $pdo->commit();
            return true;
        } catch (Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }

    /**
     * Save API key for translation service
     */
    public static function saveApiKey(string $serviceName, string $apiKey): bool
    {
        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare('
                INSERT INTO api_keys (service_name, api_key, is_active)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE api_key = VALUES(api_key), updated_at = NOW()
            ');
            return $stmt->execute([$serviceName, $apiKey]);
        } catch (Exception $e) {
            \Logger::error('Failed to save API key: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get API key for service
     */
    public static function getApiKey(string $serviceName): ?string
    {
        try {
            $pdo = DB::conn();
            $stmt = $pdo->prepare("SELECT api_key FROM api_keys WHERE service_name = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$serviceName]);
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
}
