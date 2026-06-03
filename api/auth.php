<?php
/**
 * StoryVerse AI - Authentication API
 * Handles user registration, login, logout, and token management
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';

switch ($action) {
    case 'register':
        handleRegister();
        break;
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'verify-token':
        handleVerifyToken();
        break;
    case 'refresh-token':
        handleRefreshToken();
        break;
    default:
        http_response_code(404);
        echo Response::error('Endpoint not found', 404);
}

/**
 * Handle User Registration
 */
function handleRegister() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // Validation
    if (empty($input['username']) || empty($input['email']) || empty($input['password'])) {
        echo Response::error('Username, email, and password are required', 400);
        return;
    }

    $username = sanitizeInput($input['username']);
    $email = sanitizeInput($input['email']);
    $password = $input['password'];
    $firstName = sanitizeInput($input['first_name'] ?? '');
    $lastName = sanitizeInput($input['last_name'] ?? '');

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo Response::error('Invalid email format', 400);
        return;
    }

    // Validate password strength
    if (strlen($password) < 8) {
        echo Response::error('Password must be at least 8 characters', 400);
        return;
    }

    // Check if user exists
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? OR username = ?');
    $stmt->bind_param('ss', $email, $username);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows > 0) {
        echo Response::error('Email or username already exists', 409);
        return;
    }

    // Hash password
    $hashedPassword = hashPassword($password);

    // Insert user
    $stmt = $db->prepare(
        'INSERT INTO users (username, email, password, first_name, last_name, language) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $defaultLang = sanitizeInput($input['language'] ?? 'en');
    $stmt->bind_param('ssssss', $username, $email, $hashedPassword, $firstName, $lastName, $defaultLang);

    if ($stmt->execute()) {
        $userId = $stmt->insert_id;
        $token = AuthMiddleware::createToken($userId);

        echo Response::success(
            [
                'user_id' => $userId,
                'username' => $username,
                'email' => $email,
                'token' => $token
            ],
            'Registration successful',
            201
        );
    } else {
        echo Response::error('Registration failed: ' . $db->error, 500);
    }
}

/**
 * Handle User Login
 */
function handleLogin() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // Validation
    if (empty($input['email']) || empty($input['password'])) {
        echo Response::error('Email and password are required', 400);
        return;
    }

    $email = sanitizeInput($input['email']);
    $password = $input['password'];
    $rememberMe = isset($input['remember_me']) ? (bool)$input['remember_me'] : false;

    // Find user
    $db = getDB();
    $stmt = $db->prepare('SELECT id, password, username, first_name, last_name, language FROM users WHERE email = ? AND is_active = TRUE');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo Response::error('Invalid email or password', 401);
        return;
    }

    $user = $result->fetch_assoc();

    // Verify password
    if (!verifyPassword($password, $user['password'])) {
        echo Response::error('Invalid email or password', 401);
        return;
    }

    // Create token
    $expiresIn = $rememberMe ? 604800 : 86400; // 7 days or 1 day
    $token = AuthMiddleware::createToken($user['id'], $expiresIn);

    // Update last login
    $stmt = $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $stmt->bind_param('i', $user['id']);
    $stmt->execute();

    // Set cookie if remember me
    if ($rememberMe) {
        setcookie(
            'auth_token',
            $token,
            time() + 604800,
            '/',
            $_SERVER['HTTP_HOST'],
            isset($_SERVER['HTTPS']),
            true
        );
    }

    echo Response::success(
        [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $email,
            'language' => $user['language'],
            'token' => $token
        ],
        'Login successful'
    );
}

/**
 * Handle User Logout
 */
function handleLogout() {
    // Clear auth cookie
    setcookie(
        'auth_token',
        '',
        time() - 3600,
        '/',
        $_SERVER['HTTP_HOST'],
        isset($_SERVER['HTTPS']),
        true
    );

    echo Response::success(null, 'Logout successful');
}

/**
 * Handle Token Verification
 */
function handleVerifyToken() {
    $token = AuthMiddleware::getToken();

    if ($token && AuthMiddleware::verifyToken($token)) {
        $user = AuthMiddleware::getCurrentUser();
        echo Response::success(['user_id' => $user['user_id']], 'Token is valid');
    } else {
        echo Response::error('Token is invalid or expired', 401);
    }
}

/**
 * Handle Token Refresh
 */
function handleRefreshToken() {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();
    $newToken = AuthMiddleware::createToken($userId);

    echo Response::success(['token' => $newToken], 'Token refreshed successfully');
}

?>
