-- Incremental migration for anyone who already ran the Phase 1 db/schema.sql
-- against a live database. Safe to run once. If you're setting up fresh,
-- just use the current db/schema.sql instead — it already includes this.

ALTER TABLE tracked_titles
    ADD COLUMN notification_cadence ENUM('off', 'weekly', 'on_new_activity') NOT NULL DEFAULT 'off' AFTER refresh_cadence_hours,
    ADD COLUMN last_digest_sent_at DATETIME NULL AFTER notification_cadence;

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    tracked_title_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'digest',
    sent_at DATETIME NOT NULL,
    payload_summary VARCHAR(500) NULL,
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_tt FOREIGN KEY (tracked_title_id) REFERENCES tracked_titles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reddit and YouTube sources will be created automatically the next time
-- ingestion runs for each tracked title (SourceRegistry::ensureAllLinked),
-- so no manual `sources` inserts are needed here.
