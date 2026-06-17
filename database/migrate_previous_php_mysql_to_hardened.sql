-- Optional migration if you already imported the earlier PHP/MySQL prototype schema.
-- If you are starting fresh, use database/schema.sql instead.

USE summitcraft_leadership;

CREATE TABLE IF NOT EXISTS programme_prices (
  programme VARCHAR(120) NOT NULL PRIMARY KEY,
  price_per_participant DECIMAL(10,2) NOT NULL DEFAULT 95.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO programme_prices (programme, price_per_participant)
VALUES
  ('The Ascent', 95.00),
  ('The Ridgeline', 295.00),
  ('The Summit', 495.00)
ON DUPLICATE KEY UPDATE price_per_participant = programme_prices.price_per_participant;

DELETE FROM programme_prices
WHERE programme NOT IN ('The Ascent', 'The Ridgeline', 'The Summit');

ALTER TABLE enquiries
  ADD COLUMN IF NOT EXISTS privacy_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER hear_about,
  ADD COLUMN IF NOT EXISTS ip_hash CHAR(64) NULL AFTER status,
  ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL AFTER ip_hash,
  ADD INDEX IF NOT EXISTS idx_enquiries_status (status);

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS privacy_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER hear_about,
  ADD COLUMN IF NOT EXISTS payment_received_at DATETIME NULL AFTER status,
  ADD COLUMN IF NOT EXISTS ip_hash CHAR(64) NULL AFTER payment_received_at,
  ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL AFTER ip_hash,
  ADD INDEX IF NOT EXISTS idx_bookings_status (status);

CREATE TABLE IF NOT EXISTS admin_sessions (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  csrf_hash CHAR(64) NOT NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  last_seen_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_sessions_username (username),
  INDEX idx_admin_sessions_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(80) NOT NULL,
  identifier_hash CHAR(64) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  locked_until DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rate_limits_action_identifier (action, identifier_hash),
  INDEX idx_rate_limits_locked_until (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_username VARCHAR(100) NOT NULL DEFAULT '',
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(60) NOT NULL DEFAULT '',
  entity_id VARCHAR(80) NOT NULL DEFAULT '',
  details_json JSON NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_logs_created_at (created_at),
  INDEX idx_audit_logs_action (action),
  INDEX idx_audit_logs_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email_type VARCHAR(80) NOT NULL,
  entity_id VARCHAR(80) NOT NULL DEFAULT '',
  recipient_email VARCHAR(254) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'queued',
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  INDEX idx_email_logs_created_at (created_at),
  INDEX idx_email_logs_status (status),
  INDEX idx_email_logs_entity (entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
