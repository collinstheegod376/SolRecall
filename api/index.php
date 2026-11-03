<?php

declare(strict_types=1);

/**
 * Vercel Serverless Function Entry Point
 *
 * Forwards requests to public/index.php front controller.
 */

// Handle PHP sessions in serverless environments (use sys_get_temp_dir if needed)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.save_path', sys_get_temp_dir());
}

require_once __DIR__ . '/../public/index.php';
