CREATE TABLE IF NOT EXISTS settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  price_per_participant DECIMAL(10,2) NOT NULL DEFAULT 95.00,
  payment_account_name VARCHAR(160) NOT NULL,
  payment_bank_name VARCHAR(160) NOT NULL,
  payment_sort_code VARCHAR(20) NOT NULL,
  payment_account_number VARCHAR(40) NOT NULL,
  payment_instructions TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (
  id,
  price_per_participant,
  payment_account_name,
  payment_bank_name,
  payment_sort_code,
  payment_account_number,
  payment_instructions
) VALUES (
  1,
  95.00,
  'SummitCraft Leadership Ltd',
  'Demo Bank',
  '00-00-00',
  '00000000',
  'Please make a bank transfer using the payment reference exactly as shown. Your place is held while payment is pending and confirmed after payment is received.'
)
ON DUPLICATE KEY UPDATE id = id;

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

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(40) NOT NULL PRIMARY KEY,
  programme VARCHAR(120) NOT NULL,
  title VARCHAR(180) NOT NULL,
  start_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_date DATE NOT NULL,
  end_time TIME NOT NULL,
  location VARCHAR(200) NOT NULL DEFAULT '',
  capacity INT UNSIGNED NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sessions_active_date (active, start_date, start_time),
  INDEX idx_sessions_programme (programme)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enquiries (
  id VARCHAR(40) NOT NULL PRIMARY KEY,
  full_name VARCHAR(160) NOT NULL,
  organisation VARCHAR(180) NOT NULL DEFAULT '',
  email VARCHAR(254) NOT NULL,
  phone VARCHAR(80) NOT NULL DEFAULT '',
  programme VARCHAR(120) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  hear_about VARCHAR(120) NOT NULL DEFAULT '',
  privacy_consent TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(40) NOT NULL DEFAULT 'new',
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_enquiries_created_at (created_at),
  INDEX idx_enquiries_email (email),
  INDEX idx_enquiries_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id VARCHAR(40) NOT NULL PRIMARY KEY,
  session_id VARCHAR(40) NOT NULL,
  session_title VARCHAR(180) NOT NULL,
  programme VARCHAR(120) NOT NULL,
  session_start_date DATE NOT NULL,
  session_start_time TIME NOT NULL,
  session_end_date DATE NOT NULL,
  session_end_time TIME NOT NULL,
  session_location VARCHAR(200) NOT NULL DEFAULT '',
  full_name VARCHAR(160) NOT NULL,
  organisation VARCHAR(180) NOT NULL DEFAULT '',
  email VARCHAR(254) NOT NULL,
  phone VARCHAR(80) NOT NULL DEFAULT '',
  participants INT UNSIGNED NOT NULL DEFAULT 1,
  message TEXT NULL,
  hear_about VARCHAR(120) NOT NULL DEFAULT '',
  privacy_consent TINYINT(1) NOT NULL DEFAULT 0,
  price_per_participant DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_reference VARCHAR(60) NOT NULL,
  status VARCHAR(40) NOT NULL DEFAULT 'pending_payment',
  payment_received_at DATETIME NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bookings_payment_reference (payment_reference),
  INDEX idx_bookings_session_status (session_id, status),
  INDEX idx_bookings_created_at (created_at),
  INDEX idx_bookings_email (email),
  INDEX idx_bookings_status (status),
  CONSTRAINT fk_bookings_session FOREIGN KEY (session_id) REFERENCES sessions(id)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

INSERT INTO sessions (id, programme, title, start_date, start_time, end_date, end_time, location, capacity, active, notes)
VALUES
  ('ses_ascent_20260815', 'The Ascent', 'The Ascent — Foundation Leadership Day', '2026-08-15', '09:30:00', '2026-08-15', '16:30:00', 'Peak District, UK', 12, 1, 'One-day foundation experience for emerging leaders.'),
  ('ses_ridgeline_20260912', 'The Ridgeline', 'The Ridgeline — Leadership Under Pressure', '2026-09-12', '09:00:00', '2026-09-13', '16:30:00', 'Lake District, UK', 10, 1, 'Multi-day hiking and reflection programme.'),
  ('ses_summit_20261010', 'The Summit', 'The Summit — Senior Leadership Experience', '2026-10-10', '08:30:00', '2026-10-11', '17:00:00', 'Snowdonia / Eryri, UK', 8, 1, 'Advanced experience for senior leaders.')
ON DUPLICATE KEY UPDATE id = id;
