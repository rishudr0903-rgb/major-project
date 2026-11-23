<?php
// Universal Vercel serverless entry router for pes-portal
declare(strict_types=1);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove leading slash and optional prefix
$path = ltrim((string) $path, '/');
if (strpos($path, 'pes-portal/') === 0) {
    $path = substr($path, 11);
}

$baseDir = dirname(__DIR__);

// Default to index.php if root
if ($path === '' || $path === '/') {
    $target = $baseDir . DIRECTORY_SEPARATOR . 'index.php';
} else {
    // Check if target file exists
    $candidate = realpath($baseDir . DIRECTORY_SEPARATOR . $path);
    if ($candidate && strpos($candidate, $baseDir) === 0 && is_file($candidate)) {
        $target = $candidate;
    } else {
        // Try appending .php
        $candidatePhp = realpath($baseDir . DIRECTORY_SEPARATOR . $path . '.php');
        if ($candidatePhp && strpos($candidatePhp, $baseDir) === 0 && is_file($candidatePhp)) {
            $target = $candidatePhp;
        } else {
            http_response_code(404);
            echo "404 Not Found";
            exit;
        }
    }
}

// Serve static assets if routed here
$mimeTypes = [
    'css' => 'text/css',
    'js' => 'application/javascript',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
];

$extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
if (isset($mimeTypes[$extension])) {
    header("Content-Type: " . $mimeTypes[$extension]);
    readfile($target);
    exit;
}

// Change working directory to base directory so relative requires work seamlessly
chdir($baseDir);
require $target;
