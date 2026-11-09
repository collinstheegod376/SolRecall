<?php

declare(strict_types=1);

/**
 * Vercel Serverless Function Entry Point
 *
 * Forwards requests to public/index.php front controller.
 */

// Handle PHP sessions in serverless environments
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', sys_get_temp_dir());
}

require_once __DIR__ . '/../app/bootstrap.php';
ensure_session_started();

require_once __DIR__ . '/../public/index.php';
