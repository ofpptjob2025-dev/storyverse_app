<?php
/**
 * StoryVerse AI - Database Configuration
 * This file contains all database and application configuration
 */

// Database Configuration
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'storyverse_ai');
define('DB_PORT', getenv('DB_PORT') ?: 3306);

// Application Settings
define('APP_NAME', 'StoryVerse AI');
define('APP_URL', getenv('APP_URL') ?: 'http://localhost:8000');
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('APP_DEBUG', getenv('APP_DEBUG') ?: true);

// Security Settings
define('JWT_SECRET', getenv('JWT_SECRET') ?: 'your-super-secret-key-change-in-production');
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// File Upload Settings
define('MAX_UPLOAD_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('UPLOAD_DIR', __DIR__ . '/../uploads/');

// API Settings
define('API_RATE_LIMIT', 100); // requests
define('API_RATE_LIMIT_WINDOW', 3600); // per hour
define('API_RESPONSE_FORMAT', 'json');

// Pagination
define('ITEMS_PER_PAGE', 12);
define('DEFAULT_PAGE', 1);

// AI Story Generation Settings
define('AI_WORDS_MIN', 500);
define('AI_WORDS_MAX', 5000);
define('AI_STORIES_PER_DAY_FREE', 3);
define('AI_STORIES_PER_DAY_PREMIUM', 50);

// Languages
define('SUPPORTED_LANGUAGES', ['en', 'ar', 'fr']);
define('DEFAULT_LANGUAGE', 'en');

// Error Handling
error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? 1 : 0);

// Set Headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

// CORS Headers (adjust for production)
if (APP_ENV === 'development') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Database Connection Class
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $this->connection = new mysqli(
                DB_HOST,
                DB_USER,
                DB_PASS,
                DB_NAME,
                DB_PORT
            );

            if ($this->connection->connect_error) {
                throw new Exception('Connection Error: ' . $this->connection->connect_error);
            }

            $this->connection->set_charset('utf8mb4');
        } catch (Exception $e) {
            die(json_encode(['error' => $e->getMessage()]));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function prepare($query) {
        return $this->connection->prepare($query);
    }

    public function query($query) {
        return $this->connection->query($query);
    }

    public function escape($string) {
        return $this->connection->real_escape_string($string);
    }

    public function close() {
        $this->connection->close();
    }
}

// Helper Function for Database Access
function getDB() {
    return Database::getInstance()->getConnection();
}

// Response Helper Class
class Response {
    public static function success($data = null, $message = 'Success', $statusCode = 200) {
        http_response_code($statusCode);
        return json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    public static function error($message = 'Error', $statusCode = 400, $data = null) {
        http_response_code($statusCode);
        return json_encode([
            'success' => false,
            'message' => $message,
            'data' => $data
        ]);
    }
}

// Utility Functions
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

?>
