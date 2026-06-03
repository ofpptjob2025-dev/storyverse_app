<?php
/**
 * StoryVerse AI - Users API
 * Handles user profile management, updates, and user-related queries
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;

switch ($action) {
    case 'get-profile':
        handleGetProfile($userId);
        break;
    case 'update-profile':
        handleUpdateProfile();
        break;
    case 'change-password':
        handleChangePassword();
        break;
    case 'upload-avatar':
        handleUploadAvatar();
        break;
    case 'get-user-stories':
        handleGetUserStories($userId);
        break;
    case 'get-statistics':
        handleGetStatistics($userId);
        break;
    default:
        http_response_code(404);
        echo Response::error('Endpoint not found', 404);
}

/**
 * Get User Profile
 */
function handleGetProfile($userId) {
    if (!$userId) {
        echo Response::error('User ID is required', 400);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, username, email, first_name, last_name, profile_picture, bio, language, created_at FROM users WHERE id = ? AND is_active = TRUE'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo Response::error('User not found', 404);
        return;
    }

    $user = $result->fetch_assoc();

    // Get user statistics
    $statsStmt = $db->prepare(
        'SELECT COUNT(*) as story_count, SUM(view_count) as total_views FROM stories WHERE user_id = ? AND is_published = TRUE'
    );
    $statsStmt->bind_param('i', $userId);
    $statsStmt->execute();
    $stats = $statsStmt->get_result()->fetch_assoc();

    $user['story_count'] = (int)$stats['story_count'];
    $user['total_views'] = (int)($stats['total_views'] ?? 0);

    echo Response::success($user);
}

/**
 * Update User Profile
 */
function handleUpdateProfile() {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $firstName = sanitizeInput($input['first_name'] ?? '');
    $lastName = sanitizeInput($input['last_name'] ?? '');
    $bio = sanitizeInput($input['bio'] ?? '');
    $language = sanitizeInput($input['language'] ?? 'en');

    // Validate language
    if (!in_array($language, SUPPORTED_LANGUAGES)) {
        echo Response::error('Invalid language', 400);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare(
        'UPDATE users SET first_name = ?, last_name = ?, bio = ?, language = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('ssssi', $firstName, $lastName, $bio, $language, $userId);

    if ($stmt->execute()) {
        echo Response::success(['user_id' => $userId], 'Profile updated successfully');
    } else {
        echo Response::error('Failed to update profile', 500);
    }
}

/**
 * Change Password
 */
function handleChangePassword() {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['current_password']) || empty($input['new_password'])) {
        echo Response::error('Current password and new password are required', 400);
        return;
    }

    // Validate new password strength
    if (strlen($input['new_password']) < 8) {
        echo Response::error('New password must be at least 8 characters', 400);
        return;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!verifyPassword($input['current_password'], $user['password'])) {
        echo Response::error('Current password is incorrect', 401);
        return;
    }

    $newPassword = hashPassword($input['new_password']);
    $stmt = $db->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $newPassword, $userId);

    if ($stmt->execute()) {
        echo Response::success(null, 'Password changed successfully');
    } else {
        echo Response::error('Failed to change password', 500);
    }
}

/**
 * Upload Avatar
 */
function handleUploadAvatar() {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    if (!isset($_FILES['avatar'])) {
        echo Response::error('No file uploaded', 400);
        return;
    }

    $file = $_FILES['avatar'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($fileExt, ALLOWED_EXTENSIONS)) {
        echo Response::error('Invalid file type', 400);
        return;
    }

    if ($file['size'] > MAX_UPLOAD_SIZE) {
        echo Response::error('File too large', 400);
        return;
    }

    // Create unique filename
    $filename = 'avatar_' . $userId . '_' . time() . '.' . $fileExt;
    $filepath = UPLOAD_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        echo Response::error('Failed to upload file', 500);
        return;
    }

    // Update user profile picture
    $db = getDB();
    $stmt = $db->prepare('UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $filename, $userId);

    if ($stmt->execute()) {
        echo Response::success(
            ['profile_picture' => $filename],
            'Avatar uploaded successfully'
        );
    } else {
        echo Response::error('Failed to update profile picture', 500);
    }
}

/**
 * Get User Stories
 */
function handleGetUserStories($userId) {
    if (!$userId) {
        echo Response::error('User ID is required', 400);
        return;
    }

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : DEFAULT_PAGE;
    $limit = ITEMS_PER_PAGE;
    $offset = ($page - 1) * $limit;

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, title, description, featured_image, category_id, genre, word_count, view_count, created_at FROM stories WHERE user_id = ? AND is_published = TRUE ORDER BY created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->bind_param('iii', $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $stories = $result->fetch_all(MYSQLI_ASSOC);

    // Get total count
    $countStmt = $db->prepare('SELECT COUNT(*) as count FROM stories WHERE user_id = ? AND is_published = TRUE');
    $countStmt->bind_param('i', $userId);
    $countStmt->execute();
    $count = $countStmt->get_result()->fetch_assoc()['count'];

    echo Response::success(
        [
            'stories' => $stories,
            'total' => (int)$count,
            'page' => $page,
            'per_page' => $limit,
            'total_pages' => ceil($count / $limit)
        ]
    );
}

/**
 * Get User Statistics
 */
function handleGetStatistics($userId) {
    if (!$userId) {
        echo Response::error('User ID is required', 400);
        return;
    }

    $db = getDB();

    // Total stories
    $storiesStmt = $db->prepare('SELECT COUNT(*) as count FROM stories WHERE user_id = ? AND is_published = TRUE');
    $storiesStmt->bind_param('i', $userId);
    $storiesStmt->execute();
    $totalStories = $storiesStmt->get_result()->fetch_assoc()['count'];

    // Total views
    $viewsStmt = $db->prepare('SELECT COALESCE(SUM(view_count), 0) as total FROM stories WHERE user_id = ? AND is_published = TRUE');
    $viewsStmt->bind_param('i', $userId);
    $viewsStmt->execute();
    $totalViews = $viewsStmt->get_result()->fetch_assoc()['total'];

    // Average rating
    $ratingStmt = $db->prepare('SELECT COALESCE(AVG(rating), 0) as avg FROM ratings r JOIN stories s ON r.story_id = s.id WHERE s.user_id = ?');
    $ratingStmt->bind_param('i', $userId);
    $ratingStmt->execute();
    $avgRating = $ratingStmt->get_result()->fetch_assoc()['avg'];

    // Total comments
    $commentsStmt = $db->prepare('SELECT COUNT(*) as count FROM comments c JOIN stories s ON c.story_id = s.id WHERE s.user_id = ?');
    $commentsStmt->bind_param('i', $userId);
    $commentsStmt->execute();
    $totalComments = $commentsStmt->get_result()->fetch_assoc()['count'];

    echo Response::success([
        'total_stories' => (int)$totalStories,
        'total_views' => (int)$totalViews,
        'average_rating' => round((float)$avgRating, 1),
        'total_comments' => (int)$totalComments
    ]);
}

?>
