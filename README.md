# CatarataDAW

A browser-based **Digital Audio Workstation (DAW)** built with PHP and vanilla JavaScript. Users can compose and organize music through projects, tracks, and audio clips — with a full multi-track arrangement view featuring drag-and-drop editing, real-time Web Audio API playback, mixer controls, effects chains, collaboration, and more.

Built as a final project for **CPE 46 — RDBMS** to demonstrate a rich relational schema with 16 tables, M:N junction tables, and comprehensive CRUD operations.

## Features

- **User Authentication** — Register, log in, and log out securely (bcrypt-hashed passwords)
- **User Profiles** — Display name, bio, avatar upload, stats dashboard
- **Project Management** — Create/delete projects with BPM, time signature (num/den), and musical key
- **Track Editor** — Add/delete audio clips per track with timeline visualization and playback
- **Arrangement View** — Full multi-track editor with:
  - Drag-and-drop clip repositioning (persisted via AJAX)
  - Per-track mute/solo controls
  - Volume and pan mixer sliders (persisted to TrackSettings)
  - Copy/paste clips (Ctrl+C / Ctrl+V or right-click)
  - Synchronized multi-track Web Audio API playback with volume/pan/solo routing
  - Right-click to insert a sample at any position
  - Timeline markers with color coding
  - Effects chain management per track
  - Offline audio export (WAV) via OfflineAudioContext
- **Effects Chain** — Add/remove/reorder audio effects per track (Reverb, Delay, Chorus, Distortion, Compressor, EQ, Low-Pass, High-Pass)
- **Project Collaboration** — Share projects with other users via username/email, assign roles (editor/viewer)
- **Version History** — Create/restore snapshots of the entire arrangement
- **Sample Library** — Upload, search, tag, preview, and manage reusable audio samples
- **Timeline Markers** — Add labeled, color-coded markers at specific times
- **Action Logging** — Automatic undo/redo-style action log with JSON old/new data
- **Export Logging** — Track all project exports with format and file size
- **Audio Upload** — Upload mp3, wav, ogg, m4a, aac, or flac files (max 10 MB) to attach to clips

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ |
| Database | MySQL / MariaDB (PDO, prepared statements) |
| Frontend | Vanilla JS — Web Audio API, OfflineAudioContext, Drag & Drop API, Fetch API |
| Styling | Custom dark-themed CSS |

## Database Schema (EER)

16 tables with foreign keys, junction tables, and cascading deletes:

```
User ─┬─ Project ──┬── Track ──┬── AudioClip
      │            │           ├── TrackSettings  (1:1)
      │            │           └── TrackEffect ── Effect  (M:N junction)
      │            ├── ProjectCollaborator ── User  (M:N junction)
      │            ├── Snapshot ── SnapshotClip
      │            ├── Marker
      │            ├── ActionLog
      │            └── ExportLog
      └─ Sample ── SampleTag ── Tag  (M:N junction)
```

| Table | Purpose | Key Columns |
|---|---|---|
| `User` | Authentication + profiles | `user_id`, `username`, `email`, `password`, `display_name`, `bio`, `avatar_path` |
| `Project` | Music projects | `project_id`, `user_id` (FK), `project_name`, `bpm`, `time_sig_num/den`, `musical_key` |
| `Track` | Audio tracks | `track_id`, `project_id` (FK), `track_name`, `track_type` |
| `AudioClip` | Individual clips on timeline | `clip_id`, `track_id` (FK), `start_time`, `duration`, `file_path` |
| `TrackSettings` | Mixer state (1:1 with Track) | `volume`, `pan`, `is_muted`, `is_solo` |
| `Effect` | Master list of effects | `effect_id`, `effect_name`, `category` |
| `TrackEffect` | Effects chain (M:N junction) | `track_effect_id`, `track_id`, `effect_id`, `position`, `mix`, `is_active` |
| `ProjectCollaborator` | Sharing (M:N junction) | `user_id`, `project_id`, `role` |
| `Snapshot` | Version history | `snapshot_id`, `project_id`, `snapshot_name` |
| `SnapshotClip` | Snapshot contents | `snapshot_clip_id`, `snapshot_id`, FK to clip data |
| `Sample` | Reusable audio samples | `sample_id`, `user_id`, `sample_name`, `file_path` |
| `Tag` | Sample categories | `tag_id`, `tag_name` |
| `SampleTag` | Sample↔Tag (M:N junction) | `sample_id`, `tag_id` |
| `Marker` | Timeline markers | `marker_id`, `project_id`, `time`, `label`, `color` |
| `ActionLog` | Audit/undo log | `action_log_id`, `project_id`, `user_id`, `action_type`, `old_data`, `new_data` |
| `ExportLog` | Export history | `export_id`, `project_id`, `user_id`, `format`, `file_size` |

Track types: `vocals`, `drums`, `bass`, `guitar`, `piano`, `other`

## Setup

### Requirements

- PHP 8+ with the `pdo_mysql` extension enabled
- MySQL or MariaDB server
- A writable `uploads/` directory

### Installation

1. **Clone / copy** the project files to your web server's document root.

2. **Create the database** by importing `database.sql` into MySQL (e.g., via phpMyAdmin):
   ```bash
   mysql -u root -p < database.sql
   ```

3. **Configure the database connection** in `config.php`. Uncomment and fill in the appropriate block for your environment:

   ```php
   // Local development
   $host = 'localhost';
   $dbname = 'catarata_daw';
   $username = 'catdaw';
   $password = 'your_password';
   ```

4. **Ensure `uploads/` is writable** by the web server:
   ```bash
   chmod 755 uploads/
   ```

5. **Open `index.php`** in your browser. You will be redirected to the login page.

## Project Structure

```
.
├── config.php              # DB connection, session, and global helpers
├── index.php               # Entry point — redirects based on auth state
├── login.php               # Login form
├── register.php            # Registration form
├── logout.php              # Session destruction
├── dashboard.php           # Project list and management (BPM, key, time sig)
├── project.php             # Track list, collaboration, snapshots, markers
├── track.php               # Per-track clip editor and playback
├── arrangement.php         # Full multi-track arrangement view with mixer
├── profile.php             # User profile management and avatar upload
├── samples.php             # Sample library — upload, search, tag, preview
├── database.sql            # Full 16-table SQL schema
├── api/
│   ├── add_clip.php        # AJAX: add a clip to a track
│   ├── delete_clip.php     # AJAX: delete a clip (and its file)
│   ├── update_clip.php     # AJAX: update a clip's start time (drag-drop)
│   ├── track_settings.php  # AJAX: get/update mixer settings (volume, pan, solo)
│   ├── track_effects.php   # AJAX: manage effects chain per track
│   ├── collaborators.php   # AJAX: manage project sharing and roles
│   ├── snapshots.php       # AJAX: create/restore/delete arrangement snapshots
│   ├── markers.php         # AJAX: CRUD for timeline markers
│   ├── action_log.php      # AJAX: read/write action audit log
│   ├── export_log.php      # AJAX: log project exports
│   └── samples.php         # AJAX: upload/search/tag samples
├── js/
│   ├── player.js           # DAWPlayer class for single-track playback
│   ├── arrangement.js      # Multi-track arrangement JS (mixer, effects, export)
│   └── main.js             # Shared UI utilities
├── css/
│   └── style.css           # Application styles (dark DAW theme)
└── uploads/                # Uploaded audio files and avatars
```

## API Endpoints

All endpoints require an authenticated session and verify resource ownership before acting. Responses are JSON.

| Endpoint | Method | Parameters | Description |
|---|---|---|---|
| `api/add_clip.php` | POST | `track_id`, `start_time`, `duration`, `audio_file` (optional) | Adds a clip to a track |
| `api/delete_clip.php` | POST | `clip_id` | Deletes a clip and its uploaded file |
| `api/update_clip.php` | POST | `clip_id`, `start_time` | Updates a clip's start time after drag-drop |

## Security

- Passwords hashed with `password_hash()` (bcrypt)
- All SQL queries use PDO prepared statements
- Every CRUD operation verifies ownership via `track → project → user_id` joins
- File uploads restricted to an extension whitelist and 10 MB size limit; stored under randomized names with `uniqid()`
- XSS protection via `htmlspecialchars()` wrapper `h()` on all rendered output
