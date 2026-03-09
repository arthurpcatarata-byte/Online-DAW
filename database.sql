-- =============================================================
--  CatarataDAW — Database Schema
--  Run this in phpMyAdmin (InfinityFree cPanel) BEFORE uploading
-- =============================================================

-- ── Core Tables ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `User` (
    `user_id`      INT          AUTO_INCREMENT PRIMARY KEY,
    `username`     VARCHAR(50)  NOT NULL UNIQUE,
    `email`        VARCHAR(100) NOT NULL UNIQUE,
    `password`     VARCHAR(255) NOT NULL,
    `display_name` VARCHAR(100) DEFAULT NULL,
    `bio`          TEXT         DEFAULT NULL,
    `avatar_path`  VARCHAR(255) DEFAULT NULL,
    `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Project` (
    `project_id`     INT          AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT          NOT NULL,
    `project_name`   VARCHAR(100) NOT NULL,
    `created_date`   DATE         NOT NULL,
    `bpm`            INT          NOT NULL DEFAULT 120,
    `time_sig_num`   INT          NOT NULL DEFAULT 4,
    `time_sig_den`   INT          NOT NULL DEFAULT 4,
    `musical_key`    VARCHAR(10)  DEFAULT NULL,
    FOREIGN KEY (`user_id`) REFERENCES `User`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Track` (
    `track_id`   INT          AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT          NOT NULL,
    `track_name` VARCHAR(100) NOT NULL,
    `track_type` VARCHAR(50)  NOT NULL DEFAULT 'other',
    FOREIGN KEY (`project_id`) REFERENCES `Project`(`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `AudioClip` (
    `clip_id`    INT           AUTO_INCREMENT PRIMARY KEY,
    `track_id`   INT           NOT NULL,
    `start_time` FLOAT         NOT NULL DEFAULT 0,
    `duration`   FLOAT         NOT NULL DEFAULT 0,
    `file_path`  VARCHAR(255)  DEFAULT NULL,
    FOREIGN KEY (`track_id`) REFERENCES `Track`(`track_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Mixer / Track Settings (1:1 with Track) ─────────────────

CREATE TABLE IF NOT EXISTS `TrackSettings` (
    `setting_id` INT   AUTO_INCREMENT PRIMARY KEY,
    `track_id`   INT   NOT NULL UNIQUE,
    `volume`     FLOAT NOT NULL DEFAULT 1.0,
    `pan`        FLOAT NOT NULL DEFAULT 0.0,
    `is_muted`   BOOLEAN NOT NULL DEFAULT FALSE,
    `is_solo`    BOOLEAN NOT NULL DEFAULT FALSE,
    FOREIGN KEY (`track_id`) REFERENCES `Track`(`track_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Effects Chain (M:N between Track and Effect) ─────────────

CREATE TABLE IF NOT EXISTS `Effect` (
    `effect_id`   INT          AUTO_INCREMENT PRIMARY KEY,
    `effect_name` VARCHAR(50)  NOT NULL UNIQUE,
    `category`    VARCHAR(50)  DEFAULT 'other'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `Effect` (`effect_name`, `category`) VALUES
    ('Reverb',     'spatial'),
    ('Delay',      'spatial'),
    ('Chorus',     'modulation'),
    ('Distortion', 'dynamics'),
    ('Compressor', 'dynamics'),
    ('EQ',         'filter'),
    ('Low-Pass',   'filter'),
    ('High-Pass',  'filter');

CREATE TABLE IF NOT EXISTS `TrackEffect` (
    `track_effect_id` INT   AUTO_INCREMENT PRIMARY KEY,
    `track_id`        INT   NOT NULL,
    `effect_id`       INT   NOT NULL,
    `position`        INT   NOT NULL DEFAULT 0,
    `mix`             FLOAT NOT NULL DEFAULT 0.5,
    `is_active`       BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (`track_id`)  REFERENCES `Track`(`track_id`)   ON DELETE CASCADE,
    FOREIGN KEY (`effect_id`) REFERENCES `Effect`(`effect_id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_track_effect_pos` (`track_id`, `position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Project Collaboration (M:N between User and Project) ─────

CREATE TABLE IF NOT EXISTS `ProjectCollaborator` (
    `collab_id`  INT         AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT         NOT NULL,
    `user_id`    INT         NOT NULL,
    `role`       VARCHAR(20) NOT NULL DEFAULT 'viewer',
    `added_at`   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `Project`(`project_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`)    REFERENCES `User`(`user_id`)       ON DELETE CASCADE,
    UNIQUE KEY `unique_collab` (`project_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Version History / Snapshots ──────────────────────────────

CREATE TABLE IF NOT EXISTS `Snapshot` (
    `snapshot_id`   INT          AUTO_INCREMENT PRIMARY KEY,
    `project_id`    INT          NOT NULL,
    `snapshot_name` VARCHAR(100) NOT NULL,
    `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `Project`(`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `SnapshotClip` (
    `snap_clip_id` INT          AUTO_INCREMENT PRIMARY KEY,
    `snapshot_id`  INT          NOT NULL,
    `track_id`     INT          NOT NULL,
    `start_time`   FLOAT        NOT NULL DEFAULT 0,
    `duration`     FLOAT        NOT NULL DEFAULT 0,
    `file_path`    VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (`snapshot_id`) REFERENCES `Snapshot`(`snapshot_id`) ON DELETE CASCADE,
    FOREIGN KEY (`track_id`)    REFERENCES `Track`(`track_id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sample Library & Tags ────────────────────────────────────

CREATE TABLE IF NOT EXISTS `Sample` (
    `sample_id`   INT          AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT          NOT NULL,
    `sample_name` VARCHAR(100) NOT NULL,
    `file_path`   VARCHAR(255) NOT NULL,
    `duration`    FLOAT        NOT NULL DEFAULT 0,
    `uploaded_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `User`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Tag` (
    `tag_id`   INT         AUTO_INCREMENT PRIMARY KEY,
    `tag_name` VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `Tag` (`tag_name`) VALUES
    ('kick'), ('snare'), ('hihat'), ('clap'), ('bass'),
    ('synth'), ('vocal'), ('guitar'), ('piano'), ('pad'),
    ('fx'), ('loop'), ('one-shot'), ('ambient');

CREATE TABLE IF NOT EXISTS `SampleTag` (
    `sample_id` INT NOT NULL,
    `tag_id`    INT NOT NULL,
    PRIMARY KEY (`sample_id`, `tag_id`),
    FOREIGN KEY (`sample_id`) REFERENCES `Sample`(`sample_id`) ON DELETE CASCADE,
    FOREIGN KEY (`tag_id`)    REFERENCES `Tag`(`tag_id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Timeline Markers ─────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `Marker` (
    `marker_id`  INT          AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT          NOT NULL,
    `time`       FLOAT        NOT NULL DEFAULT 0,
    `label`      VARCHAR(100) NOT NULL,
    `color`      VARCHAR(7)   NOT NULL DEFAULT '#f59e0b',
    FOREIGN KEY (`project_id`) REFERENCES `Project`(`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Undo / Redo Action Log ───────────────────────────────────

CREATE TABLE IF NOT EXISTS `ActionLog` (
    `log_id`      INT          AUTO_INCREMENT PRIMARY KEY,
    `project_id`  INT          NOT NULL,
    `user_id`     INT          NOT NULL,
    `action_type` VARCHAR(50)  NOT NULL,
    `entity_type` VARCHAR(50)  NOT NULL,
    `entity_id`   INT          DEFAULT NULL,
    `old_data`    JSON         DEFAULT NULL,
    `new_data`    JSON         DEFAULT NULL,
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `Project`(`project_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`)    REFERENCES `User`(`user_id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Project Export Log ───────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ExportLog` (
    `export_id`  INT          AUTO_INCREMENT PRIMARY KEY,
    `project_id` INT          NOT NULL,
    `user_id`    INT          NOT NULL,
    `format`     VARCHAR(20)  NOT NULL DEFAULT 'wav',
    `file_size`  BIGINT       DEFAULT NULL,
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`project_id`) REFERENCES `Project`(`project_id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`)    REFERENCES `User`(`user_id`)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
