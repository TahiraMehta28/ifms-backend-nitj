<?php
require_once __DIR__ . '/../vendor/autoload.php';

use MongoDB\Client;
use Dotenv\Dotenv;

// Initialize and use safeLoad() to avoid crashing if .env is missing (expected on Render)
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();


class Database {

    private $atlas_uri;
    private $db_name;
    private $client;

    public function __construct() {
        // Use multiple fallback sources for environment variables
        $this->atlas_uri = $_ENV['MONGODB_ATLAS_URI'] ?? $_SERVER['MONGODB_ATLAS_URI'] ?? getenv('MONGODB_ATLAS_URI') ?: '';
        $this->db_name   = $_ENV['MONGODB_DB_NAME'] ?? $_SERVER['MONGODB_DB_NAME'] ?? getenv('MONGODB_DB_NAME') ?: 'research_projects';
        $this->connect();
    }

    private function connect() {
        try {
            if (empty($this->atlas_uri)) {
                die(json_encode([
                    "success" => false,
                    "message" => "MongoDB Atlas URI not found in .env file"
                ]));
            }

            $this->client = new Client($this->atlas_uri);
            // $this->client->listDatabases();

            error_log("✅ MongoDB connected successfully");

        } catch (Exception $e) {
            error_log("❌ MongoDB connection failed: " . $e->getMessage());
            die(json_encode([
                "success" => false,
                "message" => "MongoDB connection failed"
            ]));
        }
    }

    public function getDatabase() {
        return $this->client->{$this->db_name};
    }
}

function getMongoDBConnection() {
    static $db = null;

    if ($db === null) {
        $database = new Database();
        $db = $database->getDatabase();
    }

    return $db;
}
