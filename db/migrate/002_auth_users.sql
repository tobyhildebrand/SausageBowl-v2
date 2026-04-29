-- ===========================================================================
-- db/migrate/002_auth_users.sql
--
-- Adds DB-backed application users for commissioner/admin access.
-- Run this on existing databases that were created before auth support.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS users (
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