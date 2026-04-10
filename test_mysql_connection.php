<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Load .env
try {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
} catch (Exception $e) {
    die("Error loading .env: " . $e->getMessage());
}

require_once __DIR__ . '/config/mysql_database.php';

echo "Testing MySQL connection...\n";
echo "Host: " . ($_ENV['MYSQL_HOST'] ?? 'localhost') . "\n";
echo "DB: " . ($_ENV['MYSQL_DB_NAME'] ?? 'ifms_db') . "\n";
echo "User: " . ($_ENV['MYSQL_USER'] ?? 'root') . "\n";

try {
    $db = getMySQLConnection();
    if ($db) {
        echo "✅ Connection successful!\n";
        
        // Try a simple query
        $stmt = $db->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables found: " . count($tables) . "\n";
        foreach ($tables as $table) {
            echo " - $table\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n";
}
