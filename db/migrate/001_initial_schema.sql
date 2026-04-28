-- ===========================================================================
-- db/migrate/001_initial_schema.sql
--
-- Incremental migration – safe to run on a production database.
-- Does NOT drop or truncate existing tables.
-- Use CREATE TABLE IF NOT EXISTS and ALTER TABLE … ADD COLUMN IF NOT EXISTS.
--
-- Usage:
--   mysql -u <user> -p <dbname> < db/migrate/001_initial_schema.sql
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- app_settings: generic key-value store for application-level configuration.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(100)                        NOT NULL,
    setting_value TEXT                                NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
