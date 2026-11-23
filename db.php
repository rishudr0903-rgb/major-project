<?php
// Suppress deprecation warnings on PHP 8.5+
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

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

// Enable multi-statements so auto-installer and dumps can run cleanly
$multiStmtOption = defined('Pdo\Mysql::ATTR_MULTI_STATEMENTS')
    ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS
    : (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS') ? @constant('PDO::MYSQL_ATTR_MULTI_STATEMENTS') : 1000);
$options[$multiStmtOption] = true;

// Enable SSL if explicitly requested or connecting to a remote host
$isRemote = ($host !== 'localhost' && $host !== '127.0.0.1');
$useSsl   = (getenv('DB_SSL') === 'true') || $isRemote;

if ($useSsl) {
    $caBundle = __DIR__ . '/cacert.pem';
    $sslCaOption = defined('Pdo\Mysql::ATTR_SSL_CA')
        ? \Pdo\Mysql::ATTR_SSL_CA
        : (defined('PDO::MYSQL_ATTR_SSL_CA') ? @constant('PDO::MYSQL_ATTR_SSL_CA') : 1002);

    if (file_exists($caBundle)) {
        $options[$sslCaOption] = $caBundle;
    } elseif (file_exists('/etc/pki/tls/certs/ca-bundle.crt')) {
        $options[$sslCaOption] = '/etc/pki/tls/certs/ca-bundle.crt';
    } elseif (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
        $options[$sslCaOption] = '/etc/ssl/certs/ca-certificates.crt';
    }

    $sslVerifyOption = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
        ? \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
        : (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? @constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') : 1006);
    $options[$sslVerifyOption] = false;
}

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Auto-initialize tables and seed data if users table does not exist
try {
    $pdo->query("SELECT 1 FROM users LIMIT 1");
} catch (Throwable $e) {
    $schemaFile = __DIR__ . '/database.sql';
    if (file_exists($schemaFile)) {
        try {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
        } catch (Throwable $initErr) {
            // Ignore error or log if already exists
        }
    }
}
