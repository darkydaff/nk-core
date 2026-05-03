-- Normalize panel locales to en/uk/ru only
-- and guarantee key coverage for Ukrainian/Russian from English baseline.

-- Ensure required locales exist and are active
INSERT INTO languages (code, name, native_name, is_active) VALUES
('en', 'English', 'English', 1),
('uk', 'Ukrainian', 'Українська', 1),
('ru', 'Russian', 'Русский', 1)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    native_name = VALUES(native_name),
    is_active = 1;

-- Keep only en/uk/ru languages enabled
UPDATE languages
SET is_active = CASE WHEN code IN ('en', 'uk', 'ru') THEN 1 ELSE 0 END;

-- Remove translation rows for locales we no longer support
DELETE FROM translations
WHERE locale NOT IN ('en', 'uk', 'ru');

-- Backfill missing Ukrainian keys from English
INSERT INTO translations (locale, category, key_name, translation)
SELECT 'uk', en.category, en.key_name, en.translation
FROM translations en
LEFT JOIN translations uk
    ON uk.locale = 'uk'
    AND uk.category = en.category
    AND uk.key_name = en.key_name
WHERE en.locale = 'en'
  AND uk.id IS NULL;

-- Backfill missing Russian keys from English
INSERT INTO translations (locale, category, key_name, translation)
SELECT 'ru', en.category, en.key_name, en.translation
FROM translations en
LEFT JOIN translations ru
    ON ru.locale = 'ru'
    AND ru.category = en.category
    AND ru.key_name = en.key_name
WHERE en.locale = 'en'
  AND ru.id IS NULL;

-- Replace empty values in uk/ru with English fallback
UPDATE translations target
INNER JOIN translations en
    ON en.locale = 'en'
    AND en.category = target.category
    AND en.key_name = target.key_name
SET target.translation = en.translation
WHERE target.locale IN ('uk', 'ru')
  AND (target.translation IS NULL OR TRIM(target.translation) = '');
