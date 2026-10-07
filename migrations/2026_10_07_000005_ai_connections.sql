-- @description ผู้ช่วย AI: เชื่อมต่อ API ได้หลายรายการต่อสถานศึกษา แต่ละรายการเลือกเปิดใช้งานบางโมเดล
-- @up
CREATE TABLE ai_connections (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  institution_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  provider VARCHAR(20) NOT NULL,
  base_url VARCHAR(500) NOT NULL,
  api_key TEXT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  -- Model ids the admin enabled for users (JSON array) and the one picked by default.
  models JSON NULL,
  default_model VARCHAR(200) NULL,
  temperature DECIMAL(3,2) NOT NULL DEFAULT 0.30,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_aic_inst (institution_id, sort),
  CONSTRAINT fk_aic_inst FOREIGN KEY (institution_id) REFERENCES institutions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The first version kept one connection in settings: move an institution's own one into the table.
INSERT INTO ai_connections (institution_id, name, provider, base_url, api_key, enabled, models, default_model, temperature)
SELECT m.institution_id, 'การเชื่อมต่อหลัก', COALESCE(p.svalue, 'custom'),
  COALESCE(NULLIF(b.svalue, ''), CASE p.svalue
    WHEN 'openrouter' THEN 'https://openrouter.ai/api/v1'
    WHEN 'google' THEN 'https://generativelanguage.googleapis.com/v1beta/openai'
    WHEN 'openai' THEN 'https://api.openai.com/v1'
    WHEN 'ollama' THEN 'http://localhost:11434/v1'
    WHEN 'lmstudio' THEN 'http://localhost:1234/v1'
    ELSE '' END),
  k.svalue, COALESCE(e.svalue, '0') = '1', JSON_ARRAY(m.svalue), m.svalue, COALESCE(t.svalue, '0.3')
FROM settings m
LEFT JOIN settings p ON p.institution_id = m.institution_id AND p.skey = 'ai_provider'
LEFT JOIN settings b ON b.institution_id = m.institution_id AND b.skey = 'ai_base_url'
LEFT JOIN settings k ON k.institution_id = m.institution_id AND k.skey = 'ai_api_key'
LEFT JOIN settings e ON e.institution_id = m.institution_id AND e.skey = 'ai_enabled'
LEFT JOIN settings t ON t.institution_id = m.institution_id AND t.skey = 'ai_temperature'
WHERE m.skey = 'ai_model' AND m.svalue <> '' AND m.institution_id > 0;

-- Connections are per institution only (no central default any more); assistant options stay in settings.
DELETE FROM settings WHERE skey IN ('ai_enabled', 'ai_provider', 'ai_base_url', 'ai_model', 'ai_api_key', 'ai_temperature')
  OR (institution_id = 0 AND skey LIKE 'ai\_%');

-- @down
DROP TABLE ai_connections;
