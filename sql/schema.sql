-- JARVIS Platform Schema (MySQL 8+)

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  email VARCHAR(255) NOT NULL,
  phone_e164 VARCHAR(32) NULL,
  password_hash VARCHAR(255) NOT NULL,
  email_verify_token VARCHAR(128) NULL,
  email_verified_at DATETIME NULL,
  password_reset_token VARCHAR(128) NULL,
  password_reset_expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  timezone VARCHAR(64) NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'user',
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS preferences (
  user_id BIGINT UNSIGNED NOT NULL,
  default_slack_channel VARCHAR(64) NULL,
  instagram_watch_username VARCHAR(255) NULL,
  location_logging_enabled TINYINT(1) NOT NULL DEFAULT 1,
  notif_email TINYINT(1) NOT NULL DEFAULT 1,
  notif_sms TINYINT(1) NOT NULL DEFAULT 0,
  notif_inapp TINYINT(1) NOT NULL DEFAULT 1,
  last_instagram_check_at DATETIME NULL,
  last_instagram_story_check_at DATETIME NULL,
  last_weather_check_at DATETIME NULL,
  last_calendar_check_at DATETIME NULL,
  PRIMARY KEY(user_id),
  CONSTRAINT fk_pref_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oauth_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(32) NOT NULL,
  access_token TEXT NULL,
  refresh_token TEXT NULL,
  expires_at DATETIME NULL,
  scopes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_user_provider(user_id, provider),
  CONSTRAINT fk_oauth_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Devices table: used by mobile apps (iOS/Android) to register device tokens and report locations
CREATE TABLE IF NOT EXISTS devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  device_uuid VARCHAR(128) NOT NULL,
  platform VARCHAR(32) NOT NULL,
  push_provider VARCHAR(32) NULL,
  push_token TEXT NULL,
  last_location_lat DOUBLE NULL,
  last_location_lon DOUBLE NULL,
  last_location_at DATETIME NULL,
  last_seen_at DATETIME NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_device_user_uuid (user_id, device_uuid),
  KEY ix_devices_user(user_id),
  CONSTRAINT fk_device_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  channel_id VARCHAR(64) NULL,
  message_text TEXT NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'slack',
  provider_response_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_messages_user(user_id),
  CONSTRAINT fk_messages_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS command_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL,
  command_text TEXT NULL,
  jarvis_response TEXT NOT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_cmd_user(user_id),
  CONSTRAINT fk_cmd_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  entity VARCHAR(64) NULL,
  metadata_json JSON NULL,
  ip VARCHAR(64) NULL,
  user_agent TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_audit_user(user_id),
  CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(16) NOT NULL DEFAULT 'info',
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  metadata_json JSON NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL,
  PRIMARY KEY(id),
  KEY ix_notif_user(user_id),
  CONSTRAINT fk_notif_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  client_type VARCHAR(16) NOT NULL DEFAULT 'web',
  endpoint VARCHAR(255) NOT NULL,
  method VARCHAR(16) NOT NULL,
  request_json JSON NULL,
  response_json JSON NULL,
  status_code INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_api_user(user_id),
  CONSTRAINT fk_api_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS location_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  lat DOUBLE NOT NULL,
  lon DOUBLE NOT NULL,
  accuracy_m DOUBLE NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'browser',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_loc_user(user_id),
  CONSTRAINT fk_loc_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Simple geocode cache to avoid repeated reverse lookups for nearby coordinates
CREATE TABLE IF NOT EXISTS location_geocache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lat_round DOUBLE NOT NULL,
  lon_round DOUBLE NOT NULL,
  address_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY ux_latlon_round(lat_round, lon_round)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-user imported Google Calendar events
CREATE TABLE IF NOT EXISTS user_calendar_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  event_id VARCHAR(255) NOT NULL,
  summary VARCHAR(255) NULL,
  description TEXT NULL,
  start_dt DATETIME NULL,
  end_dt DATETIME NULL,
  location VARCHAR(255) NULL,
  raw_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY ux_user_event(user_id,event_id),
  KEY ix_cal_user(user_id),
  CONSTRAINT fk_cal_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Application settings / secrets (key-value store)
CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(128) NOT NULL,
  `value` TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure legacy columns exist when migrating from older installs
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(128) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_reset_expires_at DATETIME NULL;

-- Link audit log to voice inputs
ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS voice_input_id BIGINT UNSIGNED NULL;
-- (Foreign key addition skipped in pure Schema to avoid duplication errors on re-run, handled by app logic or migration scripts)

-- Voice input storage for deep dictation analysis
CREATE TABLE IF NOT EXISTS voice_inputs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  transcript TEXT NULL,
  duration_ms INT NULL,
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_voice_user(user_id),
  CONSTRAINT fk_voice_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lightweight pnut log table for offline / deep analysis
CREATE TABLE IF NOT EXISTS pnut_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  source VARCHAR(64) NOT NULL,
  payload_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_pnut_user(user_id),
  CONSTRAINT fk_pnut_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS home_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(64) NOT NULL,
  type VARCHAR(32) NOT NULL DEFAULT 'switch',
  status VARCHAR(32) NOT NULL DEFAULT 'off',
  metadata_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY ix_hd_user(user_id),
  CONSTRAINT fk_hd_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

