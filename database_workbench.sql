-- =============================================================
--  CatarataDAW — MySQL Workbench Schema
--  
--  A browser-based Digital Audio Workstation (DAW) built as a
--  final project for CPE 46 — RDBMS. This schema models the
--  complete data layer for multi-track music production, including
--  user authentication, project management, audio arrangement,
--  mixer settings, effects processing, collaboration, version
--  history, a reusable sample library, and audit logging.
--
--  16 Tables | 3 M:N Junction Tables | 1 1:1 Relationship
--
--  Relationships Overview:
--    1:N  — User→Project, User→Sample, Project→Track,
--           Track→AudioClip, Project→Snapshot, Project→Marker,
--           Project→ActionLog, Project→ExportLog, Snapshot→SnapshotClip
--    1:1  — Track↔TrackSettings (mixer state per track)
--    M:N  — Track↔Effect (via TrackEffect junction table)
--           User↔Project (via ProjectCollaborator junction table)
--           Sample↔Tag (via SampleTag junction table)
--
--  Author:  Arthur Catarata
--  Course:  CPE 46 — RDBMS
--  Date:    March 2026
-- =============================================================

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -------------------------------------------------------------
--  Schema: catarata_daw
-- -------------------------------------------------------------
CREATE SCHEMA IF NOT EXISTS `catarata_daw` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `catarata_daw`;


-- =============================================================
--  TABLE: User
--  Description: Stores registered users with authentication
--  credentials and optional profile information (display name,
--  bio, avatar). Every project, sample, and log entry traces
--  back to a User.
-- =============================================================
DROP TABLE IF EXISTS `User`;
CREATE TABLE `User` (
    `user_id`      INT          NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique user identifier',
    `username`     VARCHAR(50)  NOT NULL                COMMENT 'Login handle — must be unique',
    `email`        VARCHAR(100) NOT NULL                COMMENT 'User email address — must be unique',
    `password`     VARCHAR(255) NOT NULL                COMMENT 'bcrypt-hashed password',
    `display_name` VARCHAR(100) DEFAULT NULL            COMMENT 'Optional public display name',
    `bio`          TEXT         DEFAULT NULL            COMMENT 'Short biography / description',
    `avatar_path`  VARCHAR(255) DEFAULT NULL            COMMENT 'Relative path to uploaded avatar image',
    `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP COMMENT 'Account creation timestamp',
    PRIMARY KEY (`user_id`),
    UNIQUE INDEX `idx_user_username` (`username` ASC),
    UNIQUE INDEX `idx_user_email` (`email` ASC)
) ENGINE=InnoDB
  COMMENT='Registered users — authentication and profile data';


-- =============================================================
--  TABLE: Project
--  Description: A music project owned by a User. Contains global
--  audio settings like tempo (BPM), time signature, and musical
--  key. All tracks, snapshots, markers, and logs belong to a
--  Project. Cascade-deletes when the owning User is removed.
-- =============================================================
DROP TABLE IF EXISTS `Project`;
CREATE TABLE `Project` (
    `project_id`   INT          NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique project identifier',
    `user_id`      INT          NOT NULL                COMMENT 'FK → User.user_id — project owner',
    `project_name` VARCHAR(100) NOT NULL                COMMENT 'Display name of the project',
    `created_date` DATE         NOT NULL                COMMENT 'Date the project was created',
    `bpm`          INT          NOT NULL DEFAULT 120    COMMENT 'Beats per minute (tempo), range 20–300',
    `time_sig_num` INT          NOT NULL DEFAULT 4      COMMENT 'Time signature numerator (beats per measure)',
    `time_sig_den` INT          NOT NULL DEFAULT 4      COMMENT 'Time signature denominator (beat unit)',
    `musical_key`  VARCHAR(10)  DEFAULT NULL            COMMENT 'Musical key (e.g. C, Am, F#, Bbm)',
    PRIMARY KEY (`project_id`),
    INDEX `idx_project_user` (`user_id` ASC),
    CONSTRAINT `fk_project_user`
        FOREIGN KEY (`user_id`) REFERENCES `User` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Music projects — each owned by one User (1:N)';


-- =============================================================
--  TABLE: Track
--  Description: An individual audio track within a Project.
--  Each track has a name and a type classification (vocals,
--  drums, bass, guitar, piano, other). Tracks contain AudioClips
--  and have associated TrackSettings and TrackEffects.
-- =============================================================
DROP TABLE IF EXISTS `Track`;
CREATE TABLE `Track` (
    `track_id`   INT          NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique track identifier',
    `project_id` INT          NOT NULL                COMMENT 'FK → Project.project_id — parent project',
    `track_name` VARCHAR(100) NOT NULL                COMMENT 'Display name of the track',
    `track_type` VARCHAR(50)  NOT NULL DEFAULT 'other' COMMENT 'Type: vocals, drums, bass, guitar, piano, other',
    PRIMARY KEY (`track_id`),
    INDEX `idx_track_project` (`project_id` ASC),
    CONSTRAINT `fk_track_project`
        FOREIGN KEY (`project_id`) REFERENCES `Project` (`project_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Audio tracks — belong to a Project (1:N)';


-- =============================================================
--  TABLE: AudioClip
--  Description: A single audio clip placed on a Track's timeline.
--  Defined by its start time (seconds), duration, and an optional
--  file path to the uploaded audio file. Clips can be dragged,
--  copied, and pasted within the arrangement view.
-- =============================================================
DROP TABLE IF EXISTS `AudioClip`;
CREATE TABLE `AudioClip` (
    `clip_id`    INT          NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique clip identifier',
    `track_id`   INT          NOT NULL                COMMENT 'FK → Track.track_id — parent track',
    `start_time` FLOAT        NOT NULL DEFAULT 0      COMMENT 'Start position on timeline in seconds',
    `duration`   FLOAT        NOT NULL DEFAULT 0      COMMENT 'Clip length in seconds',
    `file_path`  VARCHAR(255) DEFAULT NULL             COMMENT 'Relative path to uploaded audio file',
    PRIMARY KEY (`clip_id`),
    INDEX `idx_clip_track` (`track_id` ASC),
    CONSTRAINT `fk_clip_track`
        FOREIGN KEY (`track_id`) REFERENCES `Track` (`track_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Audio clips on a track timeline — belong to a Track (1:N)';


-- =============================================================
--  TABLE: TrackSettings
--  Description: Mixer state for a Track (1:1 relationship).
--  Stores volume level (0–2), stereo pan position (-1 to +1),
--  mute flag, and solo flag. Created on-demand when the user
--  first adjusts any mixer control.
-- =============================================================
DROP TABLE IF EXISTS `TrackSettings`;
CREATE TABLE `TrackSettings` (
    `setting_id` INT     NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
    `track_id`   INT     NOT NULL                COMMENT 'FK → Track.track_id — UNIQUE (1:1 relationship)',
    `volume`     FLOAT   NOT NULL DEFAULT 1.0    COMMENT 'Volume level: 0.0 (silent) to 2.0 (boosted)',
    `pan`        FLOAT   NOT NULL DEFAULT 0.0    COMMENT 'Stereo pan: -1.0 (full left) to +1.0 (full right)',
    `is_muted`   BOOLEAN NOT NULL DEFAULT FALSE  COMMENT 'Whether the track is muted',
    `is_solo`    BOOLEAN NOT NULL DEFAULT FALSE  COMMENT 'Whether the track is soloed',
    PRIMARY KEY (`setting_id`),
    UNIQUE INDEX `idx_settings_track` (`track_id` ASC),
    CONSTRAINT `fk_settings_track`
        FOREIGN KEY (`track_id`) REFERENCES `Track` (`track_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Mixer settings per track — 1:1 with Track';


-- =============================================================
--  TABLE: Effect
--  Description: Master catalog of available audio effects.
--  Seeded with 8 built-in effects across spatial, modulation,
--  dynamics, and filter categories. Referenced by TrackEffect
--  junction table to build per-track effects chains.
-- =============================================================
DROP TABLE IF EXISTS `Effect`;
CREATE TABLE `Effect` (
    `effect_id`   INT         NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique effect identifier',
    `effect_name` VARCHAR(50) NOT NULL                COMMENT 'Display name of the effect (unique)',
    `category`    VARCHAR(50) DEFAULT 'other'         COMMENT 'Category: spatial, modulation, dynamics, filter',
    PRIMARY KEY (`effect_id`),
    UNIQUE INDEX `idx_effect_name` (`effect_name` ASC)
) ENGINE=InnoDB
  COMMENT='Master list of audio effects — referenced by TrackEffect';

-- Seed data: built-in effects
INSERT INTO `Effect` (`effect_name`, `category`) VALUES
    ('Reverb',     'spatial'),
    ('Delay',      'spatial'),
    ('Chorus',     'modulation'),
    ('Distortion', 'dynamics'),
    ('Compressor', 'dynamics'),
    ('EQ',         'filter'),
    ('Low-Pass',   'filter'),
    ('High-Pass',  'filter');


-- =============================================================
--  TABLE: TrackEffect  (Junction Table — M:N)
--  Description: Maps Effects onto Tracks to form an ordered
--  effects chain. Each entry specifies the effect's position
--  in the chain, its wet/dry mix level, and whether it is
--  currently active (bypassed or not).
--  Relationship: Track ←M:N→ Effect
-- =============================================================
DROP TABLE IF EXISTS `TrackEffect`;
CREATE TABLE `TrackEffect` (
    `track_effect_id` INT     NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
    `track_id`        INT     NOT NULL                COMMENT 'FK → Track.track_id',
    `effect_id`       INT     NOT NULL                COMMENT 'FK → Effect.effect_id',
    `position`        INT     NOT NULL DEFAULT 0      COMMENT 'Order in the effects chain (0 = first)',
    `mix`             FLOAT   NOT NULL DEFAULT 0.5    COMMENT 'Wet/dry mix: 0.0 (dry) to 1.0 (fully wet)',
    `is_active`       BOOLEAN NOT NULL DEFAULT TRUE   COMMENT 'Whether this effect is active or bypassed',
    PRIMARY KEY (`track_effect_id`),
    UNIQUE INDEX `idx_track_effect_pos` (`track_id` ASC, `position` ASC),
    INDEX `idx_te_effect` (`effect_id` ASC),
    CONSTRAINT `fk_te_track`
        FOREIGN KEY (`track_id`) REFERENCES `Track` (`track_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_te_effect`
        FOREIGN KEY (`effect_id`) REFERENCES `Effect` (`effect_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Junction table — effects chain per track (Track M:N Effect)';


-- =============================================================
--  TABLE: ProjectCollaborator  (Junction Table — M:N)
--  Description: Enables project sharing between Users. The
--  project owner can invite other users as "editor" or "viewer".
--  Each row represents one user's access to one project.
--  Relationship: User ←M:N→ Project
-- =============================================================
DROP TABLE IF EXISTS `ProjectCollaborator`;
CREATE TABLE `ProjectCollaborator` (
    `collab_id`  INT         NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
    `project_id` INT         NOT NULL                COMMENT 'FK → Project.project_id',
    `user_id`    INT         NOT NULL                COMMENT 'FK → User.user_id — the collaborator',
    `role`       VARCHAR(20) NOT NULL DEFAULT 'viewer' COMMENT 'Access role: editor or viewer',
    `added_at`   TIMESTAMP   DEFAULT CURRENT_TIMESTAMP COMMENT 'When the collaborator was added',
    PRIMARY KEY (`collab_id`),
    UNIQUE INDEX `idx_collab_unique` (`project_id` ASC, `user_id` ASC),
    INDEX `idx_collab_user` (`user_id` ASC),
    CONSTRAINT `fk_collab_project`
        FOREIGN KEY (`project_id`) REFERENCES `Project` (`project_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_collab_user`
        FOREIGN KEY (`user_id`) REFERENCES `User` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Junction table — project collaboration (User M:N Project)';


-- =============================================================
--  TABLE: Snapshot
--  Description: A saved version (snapshot) of a project's
--  arrangement at a point in time. Users can create snapshots
--  before making changes and restore them later. Each snapshot
--  contains a copy of all clips via SnapshotClip.
-- =============================================================
DROP TABLE IF EXISTS `Snapshot`;
CREATE TABLE `Snapshot` (
    `snapshot_id`   INT          NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique snapshot identifier',
    `project_id`    INT          NOT NULL                COMMENT 'FK → Project.project_id',
    `snapshot_name` VARCHAR(100) NOT NULL                COMMENT 'User-defined name for this version',
    `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP COMMENT 'When the snapshot was taken',
    PRIMARY KEY (`snapshot_id`),
    INDEX `idx_snapshot_project` (`project_id` ASC),
    CONSTRAINT `fk_snapshot_project`
        FOREIGN KEY (`project_id`) REFERENCES `Project` (`project_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Version history snapshots — belong to a Project (1:N)';


-- =============================================================
--  TABLE: SnapshotClip
--  Description: A frozen copy of an AudioClip stored within a
--  Snapshot. When a snapshot is created, every clip in the
--  project is duplicated here. Restoring a snapshot replaces
--  current AudioClips with these stored copies.
-- =============================================================
DROP TABLE IF EXISTS `SnapshotClip`;
CREATE TABLE `SnapshotClip` (
    `snap_clip_id` INT          NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
    `snapshot_id`  INT          NOT NULL                COMMENT 'FK → Snapshot.snapshot_id',
    `track_id`     INT          NOT NULL                COMMENT 'FK → Track.track_id — original track reference',
    `start_time`   FLOAT        NOT NULL DEFAULT 0      COMMENT 'Clip start position in seconds (copied)',
    `duration`     FLOAT        NOT NULL DEFAULT 0      COMMENT 'Clip duration in seconds (copied)',
    `file_path`    VARCHAR(255) DEFAULT NULL             COMMENT 'Audio file path (copied)',
    PRIMARY KEY (`snap_clip_id`),
    INDEX `idx_snapclip_snapshot` (`snapshot_id` ASC),
    INDEX `idx_snapclip_track` (`track_id` ASC),
    CONSTRAINT `fk_snapclip_snapshot`
        FOREIGN KEY (`snapshot_id`) REFERENCES `Snapshot` (`snapshot_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_snapclip_track`
        FOREIGN KEY (`track_id`) REFERENCES `Track` (`track_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Clip data frozen inside a snapshot — belong to Snapshot (1:N)';


-- =============================================================
--  TABLE: Sample
--  Description: Reusable audio samples uploaded by users to the
--  shared sample library. Samples can be searched, previewed,
--  and tagged for organization. Independent of any specific
--  project — available across all of a user's projects.
-- =============================================================
DROP TABLE IF EXISTS `Sample`;
CREATE TABLE `Sample` (
    `sample_id`   INT          NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique sample identifier',
    `user_id`     INT          NOT NULL                COMMENT 'FK → User.user_id — uploader',
    `sample_name` VARCHAR(100) NOT NULL                COMMENT 'Display name of the sample',
    `file_path`   VARCHAR(255) NOT NULL                COMMENT 'Relative path to the audio file',
    `duration`    FLOAT        NOT NULL DEFAULT 0      COMMENT 'Duration of the audio in seconds',
    `uploaded_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP COMMENT 'Upload timestamp',
    PRIMARY KEY (`sample_id`),
    INDEX `idx_sample_user` (`user_id` ASC),
    CONSTRAINT `fk_sample_user`
        FOREIGN KEY (`user_id`) REFERENCES `User` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Reusable audio sample library — belong to a User (1:N)';


-- =============================================================
--  TABLE: Tag
--  Description: Category labels for organizing samples (e.g.
--  kick, snare, hihat, bass, synth, vocal). Seeded with 14
--  common audio production tags. Used via the SampleTag
--  junction table.
-- =============================================================
DROP TABLE IF EXISTS `Tag`;
CREATE TABLE `Tag` (
    `tag_id`   INT         NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique tag identifier',
    `tag_name` VARCHAR(50) NOT NULL                COMMENT 'Tag label (unique)',
    PRIMARY KEY (`tag_id`),
    UNIQUE INDEX `idx_tag_name` (`tag_name` ASC)
) ENGINE=InnoDB
  COMMENT='Category tags for samples — referenced by SampleTag';

-- Seed data: common audio sample tags
INSERT INTO `Tag` (`tag_name`) VALUES
    ('kick'), ('snare'), ('hihat'), ('clap'), ('bass'),
    ('synth'), ('vocal'), ('guitar'), ('piano'), ('pad'),
    ('fx'), ('loop'), ('one-shot'), ('ambient');


-- =============================================================
--  TABLE: SampleTag  (Junction Table — M:N)
--  Description: Associates Samples with Tags in a many-to-many
--  relationship. A sample can have multiple tags, and each tag
--  can be applied to many samples. Composite primary key.
--  Relationship: Sample ←M:N→ Tag
-- =============================================================
DROP TABLE IF EXISTS `SampleTag`;
CREATE TABLE `SampleTag` (
    `sample_id` INT NOT NULL COMMENT 'FK → Sample.sample_id',
    `tag_id`    INT NOT NULL COMMENT 'FK → Tag.tag_id',
    PRIMARY KEY (`sample_id`, `tag_id`),
    INDEX `idx_st_tag` (`tag_id` ASC),
    CONSTRAINT `fk_st_sample`
        FOREIGN KEY (`sample_id`) REFERENCES `Sample` (`sample_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_st_tag`
        FOREIGN KEY (`tag_id`) REFERENCES `Tag` (`tag_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Junction table — sample tagging (Sample M:N Tag)';


-- =============================================================
--  TABLE: Marker
--  Description: Named, color-coded markers placed at specific
--  times on a project's timeline. Useful for marking sections
--  like "Intro", "Chorus", "Bridge", etc. Displayed on the
--  arrangement ruler.
-- =============================================================
DROP TABLE IF EXISTS `Marker`;
CREATE TABLE `Marker` (
    `marker_id`  INT          NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique marker identifier',
    `project_id` INT          NOT NULL                COMMENT 'FK → Project.project_id',
    `time`       FLOAT        NOT NULL DEFAULT 0      COMMENT 'Position on timeline in seconds',
    `label`      VARCHAR(100) NOT NULL                COMMENT 'Marker label (e.g. Intro, Chorus)',
    `color`      VARCHAR(7)   NOT NULL DEFAULT '#f59e0b' COMMENT 'Hex color code for the marker',
    PRIMARY KEY (`marker_id`),
    INDEX `idx_marker_project` (`project_id` ASC),
    CONSTRAINT `fk_marker_project`
        FOREIGN KEY (`project_id`) REFERENCES `Project` (`project_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Timeline markers — belong to a Project (1:N)';


-- =============================================================
--  TABLE: ActionLog
--  Description: Audit/undo log that records every significant
--  action performed on a project. Stores the action type,
--  affected entity, and JSON snapshots of old and new data
--  for potential undo/redo functionality.
-- =============================================================
DROP TABLE IF EXISTS `ActionLog`;
CREATE TABLE `ActionLog` (
    `log_id`      INT         NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique log entry identifier',
    `project_id`  INT         NOT NULL                COMMENT 'FK → Project.project_id',
    `user_id`     INT         NOT NULL                COMMENT 'FK → User.user_id — who performed this action',
    `action_type` VARCHAR(50) NOT NULL                COMMENT 'Action: create, update, delete, move, etc.',
    `entity_type` VARCHAR(50) NOT NULL                COMMENT 'What was affected: clip, track, marker, etc.',
    `entity_id`   INT         DEFAULT NULL            COMMENT 'PK of the affected entity (nullable)',
    `old_data`    JSON        DEFAULT NULL            COMMENT 'JSON snapshot of state before the action',
    `new_data`    JSON        DEFAULT NULL            COMMENT 'JSON snapshot of state after the action',
    `created_at`  TIMESTAMP   DEFAULT CURRENT_TIMESTAMP COMMENT 'When the action was performed',
    PRIMARY KEY (`log_id`),
    INDEX `idx_actionlog_project` (`project_id` ASC),
    INDEX `idx_actionlog_user` (`user_id` ASC),
    CONSTRAINT `fk_actionlog_project`
        FOREIGN KEY (`project_id`) REFERENCES `Project` (`project_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_actionlog_user`
        FOREIGN KEY (`user_id`) REFERENCES `User` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Audit/undo action log — tracks changes per project';


-- =============================================================
--  TABLE: ExportLog
--  Description: Records every time a user exports a project,
--  including the output format and file size. Useful for
--  tracking usage and providing export history in the UI.
-- =============================================================
DROP TABLE IF EXISTS `ExportLog`;
CREATE TABLE `ExportLog` (
    `export_id`  INT         NOT NULL AUTO_INCREMENT COMMENT 'Primary key — unique export log identifier',
    `project_id` INT         NOT NULL                COMMENT 'FK → Project.project_id',
    `user_id`    INT         NOT NULL                COMMENT 'FK → User.user_id — who exported',
    `format`     VARCHAR(20) NOT NULL DEFAULT 'wav'  COMMENT 'Export format: wav, mp3, etc.',
    `file_size`  BIGINT      DEFAULT NULL            COMMENT 'Exported file size in bytes',
    `created_at` TIMESTAMP   DEFAULT CURRENT_TIMESTAMP COMMENT 'When the export occurred',
    PRIMARY KEY (`export_id`),
    INDEX `idx_exportlog_project` (`project_id` ASC),
    INDEX `idx_exportlog_user` (`user_id` ASC),
    CONSTRAINT `fk_exportlog_project`
        FOREIGN KEY (`project_id`) REFERENCES `Project` (`project_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_exportlog_user`
        FOREIGN KEY (`user_id`) REFERENCES `User` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  COMMENT='Export history log — tracks project exports';


-- =============================================================
--  Restore settings
-- =============================================================
SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
