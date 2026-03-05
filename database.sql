-- =============================================================
--  CatarataDAW — Database Schema
--  Run this in phpMyAdmin (InfinityFree cPanel) BEFORE uploading
-- =============================================================

CREATE TABLE IF NOT EXISTS `User` (
    `user_id`    INT          AUTO_INCREMENT PRIMARY KEY,
    `username`   VARCHAR(50)  NOT NULL UNIQUE,
    `email`      VARCHAR(100) NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Project` (
    `project_id`   INT          AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT          NOT NULL,
    `project_name` VARCHAR(100) NOT NULL,
    `created_date` DATE         NOT NULL,
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
