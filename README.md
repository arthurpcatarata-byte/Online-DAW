# CatarataDAW

A browser-based **Digital Audio Workstation (DAW)** built with PHP and vanilla JavaScript. Users can compose and organize music through projects, tracks, and audio clips — with a full multi-track arrangement view featuring drag-and-drop editing and real-time Web Audio API playback.

## Features

- **User Authentication** — Register, log in, and log out securely (bcrypt-hashed passwords)
- **Project Management** — Create and delete projects from a personal dashboard
- **Track Editor** — Add/delete audio clips per track with timeline visualization and playback
- **Arrangement View** — Full multi-track editor with:
  - Drag-and-drop clip repositioning (persisted via AJAX)
  - Per-track mute controls
  - Synchronized multi-track Web Audio API playback
  - Right-click to insert a sample at any position
- **Audio Upload** — Upload mp3, wav, ogg, m4a, aac, or flac files (max 10 MB) to attach to clips

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ |
| Database | MySQL / MariaDB (PDO) |
| Frontend | Vanilla JS — Web Audio API, Drag & Drop API, Fetch API |
| Styling | Custom dark-themed CSS |

## Database Schema

```
User → Project → Track → AudioClip
```

| Table | Key Columns |
|---|---|
| `User` | `user_id`, `username`, `email`, `password` |
| `Project` | `project_id`, `user_id` (FK), `project_name`, `created_date` |
| `Track` | `track_id`, `project_id` (FK), `track_name`, `track_type` |
| `AudioClip` | `clip_id`, `track_id` (FK), `start_time`, `duration`, `file_path` |

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
   $password = 'Catar@ta2026';
   ```

4. **Ensure `uploads/` is writable** by the web server:
   ```bash
   chmod 755 uploads/
   ```

5. **Open `index.php`** in your browser. You will be redirected to the login page.

## Project Structure

```
.
├── config.php          # DB connection, session, and global helpers
├── index.php           # Entry point — redirects based on auth state
├── login.php           # Login form
├── register.php        # Registration form
├── logout.php          # Session destruction
├── dashboard.php       # Project list and management
├── project.php         # Track list and management per project
├── track.php           # Per-track clip editor and playback
├── arrangement.php     # Full multi-track arrangement view
├── database.sql        # SQL schema (run once to set up tables)
├── api/
│   ├── add_clip.php    # AJAX: add a clip to a track
│   ├── delete_clip.php # AJAX: delete a clip (and its file)
│   └── update_clip.php # AJAX: update a clip's start time (drag-drop)
├── js/
│   ├── player.js       # DAWPlayer class for single-track playback
│   ├── arrangement.js  # Multi-track arrangement JS
│   └── main.js         # Shared UI utilities
├── css/
│   └── style.css       # Application styles
└── uploads/            # Uploaded audio files (auto-created if absent)
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
