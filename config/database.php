<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

// 1. Load Environmental Variables
try {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
} catch (Exception $e) {
    // Dotenv errors allowed in production if vars are in system environment
}

/**
 * MySQL Database Connector using PDO
 */
class MySQLDatabase {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        $this->host     = $_ENV['MYSQL_HOST'] ?? 'localhost';
        $this->db_name  = $_ENV['MYSQL_DB_NAME'] ?? 'ifms_db';
        $this->username = $_ENV['MYSQL_USER'] ?? 'root';
        $this->password = $_ENV['MYSQL_PASS'] ?? '';
    }

    public function connect() {
        if ($this->conn !== null) return $this->conn;

        try {
            $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            return $this->conn;
        } catch (PDOException $e) {
            error_log("❌ MySQL connection failed: " . $e->getMessage());
            header('Content-Type: application/json');
            die(json_encode([
                "success" => false, 
                "message" => "Database connection failed. Check your MySQL configuration."
            ]));
        }
    }

    public function getConnection() {
        return $this->connect();
    }
}

/**
 * Shared MySQL connection instance
 */
function getMySQLConnection() {
    static $mysql_db = null;
    if ($mysql_db === null) {
        $database = new MySQLDatabase();
        $mysql_db = $database->getConnection();
    }
    return $mysql_db;
}

/**
 * Legacy compatibility: Redirect getMongoDBConnection to MySQL if needed, 
 * or purely migrate all files to getMySQLConnection which we have done.
 * We'll keep a dummy or just remove it.
 * REMOVED MongoDB logic to ensure no legacy code accidentally triggers it.
 */
?>
