-- Review Mangler — Phase 1 schema
-- See CLAUDE.md §9.4 for the full illustrative model. This is the subset
-- Phase 1 actually uses; delegates/notifications tables land in later phases.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    vacation_mode TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS titles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('film', 'book') NOT NULL,
    canonical_source VARCHAR(50) NOT NULL,   -- 'tmdb' or 'google_books'
    canonical_id VARCHAR(100) NOT NULL,       -- id from that source, for reliable dedup
    display_name VARCHAR(255) NOT NULL,
    creator_name VARCHAR(255) NULL,
    year VARCHAR(4) NULL,
    poster_url VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_canonical (canonical_source, canonical_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tracked_titles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title_id INT UNSIGNED NOT NULL,
    refresh_cadence_hours INT UNSIGNED NOT NULL DEFAULT 168, -- weekly default, §7.2
    last_fetched_at DATETIME NULL,
    next_fetch_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_user_title (user_id, title_id),
    CONSTRAINT fk_tt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_tt_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,               -- 'news', 'imdb', 'letterboxd', 'goodreads', 'amazon', ...
    domain VARCHAR(255) NOT NULL UNIQUE,
    fetch_type ENUM('api', 'scrape') NOT NULL DEFAULT 'api',
    health_status ENUM('ok', 'degraded', 'blocked') NOT NULL DEFAULT 'ok',
    last_checked_at DATETIME NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tracked_title_sources (
    tracked_title_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    muted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (tracked_title_id, source_id),
    CONSTRAINT fk_tts_tt FOREIGN KEY (tracked_title_id) REFERENCES tracked_titles(id) ON DELETE CASCADE,
    CONSTRAINT fk_tts_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    external_url VARCHAR(1000) NOT NULL,
    author VARCHAR(255) NULL,
    headline VARCHAR(500) NULL,
    raw_text MEDIUMTEXT NOT NULL,
    native_rating VARCHAR(20) NULL,           -- as given by the source, unnormalized
    dedup_key CHAR(40) NOT NULL,              -- sha1 of external_url, §7.2
    classification_status ENUM('pending', 'done', 'failed', 'skipped_empty') NOT NULL DEFAULT 'pending',
    fetched_at DATETIME NOT NULL,
    published_at DATETIME NULL,
    UNIQUE KEY uniq_dedup (dedup_key),
    KEY idx_title_status (title_id, classification_status),
    CONSTRAINT fk_r_title FOREIGN KEY (title_id) REFERENCES titles(id) ON DELETE CASCADE,
    CONSTRAINT fk_r_source FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS classifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id INT UNSIGNED NOT NULL UNIQUE,
    sentiment ENUM('positive', 'negative', 'mixed') NOT NULL,
    meanness_score TINYINT UNSIGNED NOT NULL, -- 1-5, §7.3
    constructive TINYINT(1) NOT NULL,
    personal_attack TINYINT(1) NOT NULL,      -- the field the seed use case cares about most
    content_tags JSON NULL,
    rubric_version VARCHAR(50) NOT NULL,
    classified_at DATETIME NOT NULL,
    CONSTRAINT fk_c_review FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS corrections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    classification_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    note VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_corr_classification FOREIGN KEY (classification_id) REFERENCES classifications(id) ON DELETE CASCADE,
    CONSTRAINT fk_corr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
