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

-- ---------------------------------------------------------------------------
-- draft_upcoming_settings: per-season config for the detailed upcoming board.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS draft_upcoming_settings;
CREATE TABLE draft_upcoming_settings (
    season_year INT                                 NOT NULL,
    round_count TINYINT UNSIGNED                    NOT NULL DEFAULT 8,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- draft_upcoming_order: default draft order for the upcoming draft season.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS draft_upcoming_order;
CREATE TABLE draft_upcoming_order (
    season_year INT                                  NOT NULL,
    slot_no     TINYINT UNSIGNED                     NOT NULL,
    team_name   VARCHAR(120)                         NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (season_year, slot_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- draft_pick_overrides: pick-owner changes for upcoming detailed board.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS draft_pick_overrides;
CREATE TABLE draft_pick_overrides (
    id                 BIGINT UNSIGNED                     NOT NULL AUTO_INCREMENT,
    season_year        INT                                 NOT NULL,
    round_no           TINYINT UNSIGNED                    NOT NULL,
    slot_no            TINYINT UNSIGNED                    NOT NULL,
    from_team_name     VARCHAR(120)                        NOT NULL,
    current_owner_name VARCHAR(120)                        NOT NULL,
    note               VARCHAR(255)                        NOT NULL DEFAULT '',
    created_by_user_id BIGINT UNSIGNED                     NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_draft_override_slot (season_year, round_no, slot_no),
    KEY idx_draft_override_season (season_year),
    KEY idx_draft_override_creator (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- draft_pick_players: commissioner-entered drafted player per board cell.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS draft_pick_players;
CREATE TABLE draft_pick_players (
    id                 BIGINT UNSIGNED                     NOT NULL AUTO_INCREMENT,
    season_year        INT                                 NOT NULL,
    round_no           TINYINT UNSIGNED                    NOT NULL,
    slot_no            TINYINT UNSIGNED                    NOT NULL,
    player_name        VARCHAR(120)                        NOT NULL,
    updated_by_user_id BIGINT UNSIGNED                     NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_draft_pick_player_slot (season_year, round_no, slot_no),
    KEY idx_draft_pick_players_season (season_year),
    KEY idx_draft_pick_players_updater (updated_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- draft_future_pick_trades: multi-year future pick trade ledger.
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS draft_future_pick_trades;
CREATE TABLE draft_future_pick_trades (
    id                 BIGINT UNSIGNED                 NOT NULL AUTO_INCREMENT,
    season_year        INT                             NOT NULL,
    round_no           TINYINT UNSIGNED                NULL,
    from_team_name     VARCHAR(120)                    NOT NULL,
    current_owner_name VARCHAR(120)                    NOT NULL,
    note               VARCHAR(255)                    NOT NULL DEFAULT '',
    created_by_user_id BIGINT UNSIGNED                 NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_future_trade_season (season_year),
    KEY idx_future_trade_creator (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
