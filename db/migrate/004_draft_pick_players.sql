-- ===========================================================================
-- db/migrate/004_draft_pick_players.sql
--
-- Adds commissioner-entered drafted player names per draft board cell.
-- ===========================================================================

CREATE TABLE IF NOT EXISTS draft_pick_players (
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
