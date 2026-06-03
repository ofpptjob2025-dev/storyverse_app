<?php
/**
 * StoryVerse AI - Stories API
 * Handles story CRUD operations, searching, filtering, and community features
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';
$storyId = isset($_GET['story_id']) ? (int)$_GET['story_id'] : null;

switch ($action) {
    case 'create':
        handleCreateStory();
        break;
    case 'get':
        handleGetStory($storyId);
        break;
    case 'list':
        handleListStories();
        break;
    case 'search':
        handleSearchStories();
        break;
    case 'trending':
        handleTrendingStories();
        break;
    case 'update':
        handleUpdateStory($storyId);
        break;
    case 'delete':
        handleDeleteStory($storyId);
        break;
    case 'add-comment':
        handleAddComment($storyId);
        break;
    case 'get-comments':
        handleGetComments($storyId);
        break;
    case 'rate':
        handleRateStory($storyId);
        break;
    case 'get-rating':
        handleGetRating($storyId);
        break;
    case 'toggle-favorite':
        handleToggleFavorite($storyId);
        break;
    case 'get-favorites':
        handleGetFavorites();
        break;
    case 'is-favorite':
        handleIsFavorite($storyId);
        break;
    default:
        http_response_code(404);
        echo Response::error('Endpoint not found', 404);
}

/**
 * Create New Story
 */
function handleCreateStory() {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['title']) || empty($input['content'])) {
        echo Response::error('Title and content are required', 400);
        return;
    }

    $title = sanitizeInput($input['title']);
    $description = sanitizeInput($input['description'] ?? '');
    $content = $input['content']; // Keep HTML formatting
    $categoryId = isset($input['category_id']) ? (int)$input['category_id'] : null;
    $genre = sanitizeInput($input['genre'] ?? '');
    $theme = sanitizeInput($input['theme'] ?? '');
    $featuredImage = sanitizeInput($input['featured_image'] ?? '');
    $promptUsed = sanitizeInput($input['prompt_used'] ?? '');
    $aiGenerated = isset($input['ai_generated']) ? (bool)$input['ai_generated'] : false;

    // Calculate word count and reading time
    $wordCount = str_word_count(strip_tags($content));
    $readingTime = max(1, ceil($wordCount / 200)); // Average reading speed: 200 words per minute

    $db = getDB();
    $stmt = $db->prepare(
        'INSERT INTO stories (user_id, title, description, content, featured_image, category_id, genre, theme, word_count, reading_time_minutes, ai_generated, prompt_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'issssissiis',
        $userId,
        $title,
        $description,
        $content,
        $featuredImage,
        $categoryId,
        $genre,
        $theme,
        $wordCount,
        $readingTime,
        $aiGenerated,
        $promptUsed
    );

    if ($stmt->execute()) {
        $storyId = $stmt->insert_id;
        echo Response::success(
            ['story_id' => $storyId],
            'Story created successfully',
            201
        );
    } else {
        echo Response::error('Failed to create story: ' . $db->error, 500);
    }
}

/**
 * Get Story Details
 */
function handleGetStory($storyId) {
    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    $db = getDB();
    
    // Get story
    $stmt = $db->prepare(
        'SELECT s.*, u.username, u.profile_picture, u.id as author_id FROM stories s JOIN users u ON s.user_id = u.id WHERE s.id = ? AND s.is_published = TRUE'
    );
    $stmt->bind_param('i', $storyId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo Response::error('Story not found', 404);
        return;
    }

    $story = $result->fetch_assoc();

    // Get category
    if ($story['category_id']) {
        $catStmt = $db->prepare('SELECT name, slug FROM categories WHERE id = ?');
        $catStmt->bind_param('i', $story['category_id']);
        $catStmt->execute();
        $story['category'] = $catStmt->get_result()->fetch_assoc();
    }

    // Get images
    $imgStmt = $db->prepare('SELECT image_url, alt_text FROM story_images WHERE story_id = ? ORDER BY position');
    $imgStmt->bind_param('i', $storyId);
    $imgStmt->execute();
    $story['images'] = $imgStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get average rating
    $ratingStmt = $db->prepare(
        'SELECT COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as rating_count FROM ratings WHERE story_id = ?'
    );
    $ratingStmt->bind_param('i', $storyId);
    $ratingStmt->execute();
    $rating = $ratingStmt->get_result()->fetch_assoc();
    $story['average_rating'] = round((float)$rating['avg_rating'], 1);
    $story['rating_count'] = (int)$rating['rating_count'];

    // Get comment count
    $commentStmt = $db->prepare('SELECT COUNT(*) as count FROM comments WHERE story_id = ? AND is_approved = TRUE');
    $commentStmt->bind_param('i', $storyId);
    $commentStmt->execute();
    $story['comment_count'] = $commentStmt->get_result()->fetch_assoc()['count'];

    // Get favorite count
    $favStmt = $db->prepare('SELECT COUNT(*) as count FROM favorites WHERE story_id = ?');
    $favStmt->bind_param('i', $storyId);
    $favStmt->execute();
    $story['favorite_count'] = $favStmt->get_result()->fetch_assoc()['count'];

    // Increment view count
    $viewStmt = $db->prepare('UPDATE stories SET view_count = view_count + 1 WHERE id = ?');
    $viewStmt->bind_param('i', $storyId);
    $viewStmt->execute();

    echo Response::success($story);
}

/**
 * List Stories with Pagination
 */
function handleListStories() {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : DEFAULT_PAGE;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : ITEMS_PER_PAGE;
    $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $sort = isset($_GET['sort']) ? sanitizeInput($_GET['sort']) : 'newest';
    $offset = ($page - 1) * $limit;

    $db = getDB();

    // Build query
    $query = 'SELECT s.id, s.title, s.description, s.featured_image, s.category_id, s.genre, s.word_count, s.reading_time_minutes, s.view_count, s.created_at, u.username, u.id as author_id FROM stories s JOIN users u ON s.user_id = u.id WHERE s.is_published = TRUE';
    $queryCount = 'SELECT COUNT(*) as count FROM stories WHERE is_published = TRUE';

    if ($categoryId) {
        $query .= ' AND s.category_id = ' . (int)$categoryId;
        $queryCount .= ' AND category_id = ' . (int)$categoryId;
    }

    // Sort
    switch ($sort) {
        case 'trending':
            $query .= ' ORDER BY (s.view_count + s.created_at) DESC';
            break;
        case 'popular':
            $query .= ' ORDER BY s.view_count DESC';
            break;
        case 'oldest':
            $query .= ' ORDER BY s.created_at ASC';
            break;
        default: // newest
            $query .= ' ORDER BY s.created_at DESC';
    }

    $query .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;

    $result = $db->query($query);
    $stories = $result->fetch_all(MYSQLI_ASSOC);

    $countResult = $db->query($queryCount);
    $total = $countResult->fetch_assoc()['count'];

    echo Response::success([
        'stories' => $stories,
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $limit,
        'total_pages' => ceil($total / $limit)
    ]);
}

/**
 * Search Stories
 */
function handleSearchStories() {
    $query = isset($_GET['q']) ? sanitizeInput($_GET['q']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : DEFAULT_PAGE;
    $limit = ITEMS_PER_PAGE;
    $offset = ($page - 1) * $limit;

    if (empty($query)) {
        echo Response::error('Search query is required', 400);
        return;
    }

    $db = getDB();

    // Use FULLTEXT search if query is long enough
    if (strlen($query) >= 3) {
        $stmt = $db->prepare(
            'SELECT s.id, s.title, s.description, s.featured_image, s.category_id, s.view_count, s.created_at, u.username FROM stories s JOIN users u ON s.user_id = u.id WHERE s.is_published = TRUE AND MATCH(s.title, s.content) AGAINST(? IN BOOLEAN MODE) LIMIT ? OFFSET ?'
        );
        $queryTerm = '+' . str_replace(' ', ' +', $query) . '*';
        $stmt->bind_param('sii', $queryTerm, $limit, $offset);
    } else {
        // Fallback to LIKE search
        $searchTerm = '%' . $query . '%';
        $stmt = $db->prepare(
            'SELECT s.id, s.title, s.description, s.featured_image, s.category_id, s.view_count, s.created_at, u.username FROM stories s JOIN users u ON s.user_id = u.id WHERE s.is_published = TRUE AND (s.title LIKE ? OR s.description LIKE ?) LIMIT ? OFFSET ?'
        );
        $stmt->bind_param('ssii', $searchTerm, $searchTerm, $limit, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $stories = $result->fetch_all(MYSQLI_ASSOC);

    // Get total count
    $countStmt = $db->prepare(
        'SELECT COUNT(*) as count FROM stories s WHERE s.is_published = TRUE AND (s.title LIKE ? OR s.description LIKE ?)'
    );
    $searchTerm = '%' . $query . '%';
    $countStmt->bind_param('ss', $searchTerm, $searchTerm);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['count'];

    echo Response::success([
        'stories' => $stories,
        'total' => (int)$total,
        'page' => $page,
        'query' => $query
    ]);
}

/**
 * Get Trending Stories
 */
function handleTrendingStories() {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 6;

    $db = getDB();
    $result = $db->query(
        'SELECT * FROM trending_stories LIMIT ' . (int)$limit
    );
    $stories = $result->fetch_all(MYSQLI_ASSOC);

    echo Response::success(['stories' => $stories]);
}

/**
 * Update Story
 */
function handleUpdateStory($storyId) {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    // Check ownership
    $db = getDB();
    $stmt = $db->prepare('SELECT user_id FROM stories WHERE id = ?');
    $stmt->bind_param('i', $storyId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0 || $result->fetch_assoc()['user_id'] != $userId) {
        echo Response::error('Unauthorized', 403);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $title = sanitizeInput($input['title'] ?? '');
    $description = sanitizeInput($input['description'] ?? '');
    $content = $input['content'] ?? '';

    $updateStmt = $db->prepare(
        'UPDATE stories SET title = ?, description = ?, content = ?, updated_at = NOW() WHERE id = ?'
    );
    $updateStmt->bind_param('sssi', $title, $description, $content, $storyId);

    if ($updateStmt->execute()) {
        echo Response::success(['story_id' => $storyId], 'Story updated successfully');
    } else {
        echo Response::error('Failed to update story', 500);
    }
}

/**
 * Delete Story
 */
function handleDeleteStory($storyId) {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    // Check ownership
    $db = getDB();
    $stmt = $db->prepare('SELECT user_id FROM stories WHERE id = ?');
    $stmt->bind_param('i', $storyId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0 || $result->fetch_assoc()['user_id'] != $userId) {
        echo Response::error('Unauthorized', 403);
        return;
    }

    $deleteStmt = $db->prepare('DELETE FROM stories WHERE id = ?');
    $deleteStmt->bind_param('i', $storyId);

    if ($deleteStmt->execute()) {
        echo Response::success(null, 'Story deleted successfully');
    } else {
        echo Response::error('Failed to delete story', 500);
    }
}

/**
 * Add Comment
 */
function handleAddComment($storyId) {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['content'])) {
        echo Response::error('Comment content is required', 400);
        return;
    }

    $content = sanitizeInput($input['content']);
    $db = getDB();

    $stmt = $db->prepare('INSERT INTO comments (story_id, user_id, content) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $storyId, $userId, $content);

    if ($stmt->execute()) {
        echo Response::success(
            ['comment_id' => $stmt->insert_id],
            'Comment added successfully',
            201
        );
    } else {
        echo Response::error('Failed to add comment', 500);
    }
}

/**
 * Get Comments
 */
function handleGetComments($storyId) {
    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : DEFAULT_PAGE;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $offset = ($page - 1) * $limit;

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT c.id, c.content, c.created_at, u.id as user_id, u.username, u.profile_picture FROM comments c JOIN users u ON c.user_id = u.id WHERE c.story_id = ? AND c.is_approved = TRUE ORDER BY c.created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->bind_param('iii', $storyId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $comments = $result->fetch_all(MYSQLI_ASSOC);

    // Get total count
    $countStmt = $db->prepare('SELECT COUNT(*) as count FROM comments WHERE story_id = ? AND is_approved = TRUE');
    $countStmt->bind_param('i', $storyId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['count'];

    echo Response::success([
        'comments' => $comments,
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $limit
    ]);
}

/**
 * Rate Story (1-5 stars)
 */
function handleRateStory($storyId) {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input['rating']) || $input['rating'] < 1 || $input['rating'] > 5) {
        echo Response::error('Rating must be between 1 and 5', 400);
        return;
    }

    $rating = (int)$input['rating'];
    $review = sanitizeInput($input['review'] ?? '');

    $db = getDB();

    // Check if rating exists
    $checkStmt = $db->prepare('SELECT id FROM ratings WHERE story_id = ? AND user_id = ?');
    $checkStmt->bind_param('ii', $storyId, $userId);
    $checkStmt->execute();
    $existingRating = $checkStmt->get_result()->fetch_assoc();

    if ($existingRating) {
        // Update existing rating
        $stmt = $db->prepare('UPDATE ratings SET rating = ?, review = ?, updated_at = NOW() WHERE story_id = ? AND user_id = ?');
        $stmt->bind_param('isii', $rating, $review, $storyId, $userId);
    } else {
        // Insert new rating
        $stmt = $db->prepare('INSERT INTO ratings (story_id, user_id, rating, review) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('iiis', $storyId, $userId, $rating, $review);
    }

    if ($stmt->execute()) {
        echo Response::success(['rating' => $rating], 'Rating saved successfully');
    } else {
        echo Response::error('Failed to save rating', 500);
    }
}

/**
 * Get Story Rating
 */
function handleGetRating($storyId) {
    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    $userId = null;
    $token = AuthMiddleware::getToken();
    if ($token && AuthMiddleware::verifyToken($token)) {
        $userId = AuthMiddleware::getUserId();
    }

    $db = getDB();

    $stmt = $db->prepare(
        'SELECT COALESCE(AVG(rating), 0) as avg_rating, COUNT(*) as rating_count FROM ratings WHERE story_id = ?'
    );
    $stmt->bind_param('i', $storyId);
    $stmt->execute();
    $ratingData = $stmt->get_result()->fetch_assoc();

    $userRating = null;
    if ($userId) {
        $userStmt = $db->prepare('SELECT rating FROM ratings WHERE story_id = ? AND user_id = ?');
        $userStmt->bind_param('ii', $storyId, $userId);
        $userStmt->execute();
        $result = $userStmt->get_result();
        if ($result->num_rows > 0) {
            $userRating = $result->fetch_assoc()['rating'];
        }
    }

    echo Response::success([
        'average_rating' => round((float)$ratingData['avg_rating'], 1),
        'rating_count' => (int)$ratingData['rating_count'],
        'user_rating' => $userRating
    ]);
}

/**
 * Toggle Favorite
 */
function handleToggleFavorite($storyId) {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo Response::error('Method not allowed', 405);
        return;
    }

    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    $db = getDB();

    // Check if already favorited
    $checkStmt = $db->prepare('SELECT id FROM favorites WHERE story_id = ? AND user_id = ?');
    $checkStmt->bind_param('ii', $storyId, $userId);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();

    if ($existing) {
        // Remove from favorites
        $stmt = $db->prepare('DELETE FROM favorites WHERE story_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $storyId, $userId);
        $isFavorite = false;
        $message = 'Removed from favorites';
    } else {
        // Add to favorites
        $stmt = $db->prepare('INSERT INTO favorites (story_id, user_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $storyId, $userId);
        $isFavorite = true;
        $message = 'Added to favorites';
    }

    if ($stmt->execute()) {
        echo Response::success(['is_favorite' => $isFavorite], $message);
    } else {
        echo Response::error('Failed to toggle favorite', 500);
    }
}

/**
 * Get User Favorites
 */
function handleGetFavorites() {
    AuthMiddleware::requireAuth();
    $userId = AuthMiddleware::getUserId();

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : DEFAULT_PAGE;
    $limit = ITEMS_PER_PAGE;
    $offset = ($page - 1) * $limit;

    $db = getDB();
    $stmt = $db->prepare(
        'SELECT s.id, s.title, s.description, s.featured_image, s.category_id, s.view_count, s.created_at, u.username FROM stories s JOIN favorites f ON s.id = f.story_id JOIN users u ON s.user_id = u.id WHERE f.user_id = ? AND s.is_published = TRUE ORDER BY f.created_at DESC LIMIT ? OFFSET ?'
    );
    $stmt->bind_param('iii', $userId, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $stories = $result->fetch_all(MYSQLI_ASSOC);

    // Get total count
    $countStmt = $db->prepare('SELECT COUNT(*) as count FROM favorites WHERE user_id = ?');
    $countStmt->bind_param('i', $userId);
    $countStmt->execute();
    $total = $countStmt->get_result()->fetch_assoc()['count'];

    echo Response::success([
        'stories' => $stories,
        'total' => (int)$total,
        'page' => $page,
        'per_page' => $limit
    ]);
}

/**
 * Check if Story is Favorite
 */
function handleIsFavorite($storyId) {
    if (!$storyId) {
        echo Response::error('Story ID is required', 400);
        return;
    }

    $userId = null;
    $token = AuthMiddleware::getToken();
    if ($token && AuthMiddleware::verifyToken($token)) {
        $userId = AuthMiddleware::getUserId();
    }

    $isFavorite = false;
    if ($userId) {
        $db = getDB();
        $stmt = $db->prepare('SELECT id FROM favorites WHERE story_id = ? AND user_id = ?');
        $stmt->bind_param('ii', $storyId, $userId);
        $stmt->execute();
        $isFavorite = $stmt->get_result()->num_rows > 0;
    }

    echo Response::success(['is_favorite' => $isFavorite]);
}

?>
