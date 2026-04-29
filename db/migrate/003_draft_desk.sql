-- ===========================================================================
-- db/migrate/003_draft_desk.sql
--
-- Adds draft desk tables for commissioner-managed offline draft operations.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS draft_upcoming_settings (
    season_year INT                                 NOT NULL,
    round_count TINYINT UNSIGNED                    NOT NULL DEFAULT 8,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (season_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS draft_upcoming_order (
    season_year INT                                  NOT NULL,
    slot_no     TINYINT UNSIGNED                     NOT NULL,
    team_name   VARCHAR(120)                         NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (season_year, slot_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS draft_pick_overrides (
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

CREATE TABLE IF NOT EXISTS draft_future_pick_trades (
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