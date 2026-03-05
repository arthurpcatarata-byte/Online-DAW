<?php
require_once 'config.php';
// Redirect based on login state
header('Location: ' . (isLoggedIn() ? 'dashboard.php' : 'login.php'));
exit;
