<?php
$host   = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'pes_college_portal';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$port   = getenv('DB_PORT') ?: '3306';

$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Enable SSL if explicitly requested or connecting to a remote host
$isRemote = ($host !== 'localhost' && $host !== '127.0.0.1');
$useSsl   = (getenv('DB_SSL') === 'true') || $isRemote;

if ($useSsl) {
    $caBundle = __DIR__ . '/cacert.pem';
    if (file_exists($caBundle)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caBundle;
    } elseif (file_exists('/etc/pki/tls/certs/ca-bundle.crt')) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/pki/tls/certs/ca-bundle.crt';
    } elseif (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
    }

    // Set verify server cert flag without deprecation warnings across PHP versions
    if (defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = false;
    } elseif (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[@constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = false;
    }
}

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
