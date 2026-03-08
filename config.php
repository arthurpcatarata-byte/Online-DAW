<?php
// =============================================================
//  CatarataDAW — Configuration
//  IMPORTANT: Update DB credentials before uploading!
// =============================================================

session_start();

// ── Database Credentials ─────────────────────────────────────
// Fill in these values from your InfinityFree cPanel
// (MySQL Databases section)
define('DB_HOST', 'sql100.byetcluster.com');
define('DB_NAME', 'if0_41310365_epiz_12345678_dawdb');
define('DB_USER', 'if0_41310365');
define('DB_PASS', 'lprcxJl8ByQKP');

// ── PDO Connection ────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;padding:2rem;background:#1a1a2e;color:#ff6b6b;">
        <h2>⚠ Database Connection Error</h2>
        <p>Could not connect to the database. Please check your <code>config.php</code> credentials.</p>
    </div>');
}

// ── Helper Functions ──────────────────────────────────────────

/** Check if the current visitor is logged in */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

/** Redirect to login if not authenticated */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/** Safe HTML output */
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/** Emoji icon for each track type */
function getTrackIcon(string $type): string {
    return match (strtolower($type)) {
        'vocals' => '🎤',
        'drums'  => '🥁',
        'bass'   => '🎸',
        'guitar' => '🎵',
        'piano'  => '🎹',
        default  => '🎼',
    };
}

/** Format float seconds to mm:ss.cc */
function formatTime(float $sec): string {
    $mins = floor($sec / 60);
    $secs = fmod($sec, 60);
    return sprintf('%02d:%05.2f', $mins, $secs);
}
