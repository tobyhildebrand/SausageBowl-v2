-- ===========================================================================
-- db/build/001_initial_schema.sql
--
-- Builds the entire database from scratch.
-- Run this ONCE on a fresh, empty database.
-- WARNING: DROP TABLE statements will destroy existing data.
--
-- Usage:
--   mysql -u <user> -p <dbname> < db/build/001_initial_schema.sql
-- ===========================================================================

-- Future tables will be added here as features are implemented.
-- Each logical group (teams, players, picks, contracts, …) will get its own
-- clearly separated block with a header comment.

-- ---------------------------------------------------------------------------
-- app_settings: generic key-value store for application-level configuration
-- (e.g. Yahoo OAuth tokens, league settings synced from Yahoo).
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS app_settings;
CREATE TABLE app_settings (
    setting_key   VARCHAR(100)                        NOT NULL,
    setting_value TEXT                                NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- users: application users for commissioner/admin access.
-- Passwords are stored as PHP password_hash() outputs.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
    id            BIGINT UNSIGNED                     NOT NULL AUTO_INCREMENT,
    email         VARCHAR(190)                        NOT NULL,
    display_name  VARCHAR(100)                        NOT NULL,
    password_hash VARCHAR(255)                        NOT NULL,
    role          ENUM('commissioner', 'manager', 'viewer') NOT NULL DEFAULT 'viewer',
    is_active     TINYINT(1)                          NOT NULL DEFAULT 1,
    last_login_at DATETIME                            NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
