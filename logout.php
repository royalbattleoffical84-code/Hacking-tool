<?php
require_once __DIR__ . '/includes/bootstrap.php';
$lm = current_merchant(); if ($lm) log_activity((int)$lm['id'], 'logout');
$_SESSION = [];
if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']); }
session_destroy();
redirect(site_url('login.php'));
