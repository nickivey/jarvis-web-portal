<?php
// Router script for PHP built-in server
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files and existing scripts in public/ directly
if (file_exists(__DIR__ . $path) && is_file(__DIR__ . $path)) {
    return false;
}

// Route /api requests to the root index.php
if (strpos($path, '/api/') === 0) {
    require_once __DIR__ . '/../index.php';
    return;
}

// Otherwise, 404
http_response_code(404);
echo "Not Found";
