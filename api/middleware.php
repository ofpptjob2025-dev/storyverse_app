<?php
/**
 * StoryVerse AI - Authentication Middleware
 */

require_once __DIR__ . '/config.php';

class AuthMiddleware {
    private static $token = null;
    private static $user = null;

    /**
     * Verify JWT Token
     */
    public static function verifyToken($token) {
        if (empty($token)) {
            return false;
        }

        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }

            list($header, $payload, $signature) = $parts;
            $data = json_decode(base64_decode($payload), true);

            if (!isset($data['user_id']) || !isset($data['exp'])) {
                return false;
            }

            if ($data['exp'] < time()) {
                return false;
            }

            $expectedSignature = hash_hmac(
                'sha256',
                $header . '.' . $payload,
                JWT_SECRET,
                true
            );
            $expectedSignature = rtrim(strtr(base64_encode($expectedSignature), '+/', '-_'), '=');

            if (!hash_equals($signature, $expectedSignature)) {
                return false;
            }

            self::$user = $data;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get Token from Request
     */
    public static function getToken() {
        if (self::$token !== null) {
            return self::$token;
        }

        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
                self::$token = $matches[1];
                return self::$token;
            }
        }

        if (isset($_COOKIE['auth_token'])) {
            self::$token = $_COOKIE['auth_token'];
            return self::$token;
        }

        return null;
    }

    /**
     * Get Current User
     */
    public static function getCurrentUser() {
        return self::$user;
    }

    /**
     * Get Current User ID
     */
    public static function getUserId() {
        return self::$user ? self::$user['user_id'] : null;
    }

    /**
     * Require Authentication
     */
    public static function requireAuth() {
        $token = self::getToken();
        
        if (!$token || !self::verifyToken($token)) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized. Please login.'
            ]);
            exit;
        }

        return true;
    }

    /**
     * Create JWT Token
     */
    public static function createToken($userId, $expiresIn = 86400) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + $expiresIn
        ]);

        $headerEncoded = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $payloadEncoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

        $signature = hash_hmac(
            'sha256',
            $headerEncoded . '.' . $payloadEncoded,
            JWT_SECRET,
            true
        );
        $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }
}

// CORS Handler
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

?>
