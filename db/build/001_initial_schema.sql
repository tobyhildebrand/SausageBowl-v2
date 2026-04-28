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
