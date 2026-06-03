-- StoryVerse AI Database Schema
-- MySQL 5.7+

CREATE DATABASE IF NOT EXISTS storyverse_ai;
USE storyverse_ai;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    profile_picture VARCHAR(255),
    bio TEXT,
    language VARCHAR(5) DEFAULT 'en',
    is_active BOOLEAN DEFAULT TRUE,
    email_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_email (email),
    INDEX idx_username (username)
);

-- Categories Table
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    slug VARCHAR(100) UNIQUE NOT NULL,
    icon VARCHAR(255),
    color VARCHAR(7),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Stories Table
CREATE TABLE stories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    content LONGTEXT NOT NULL,
    featured_image VARCHAR(255),
    category_id INT,
    genre VARCHAR(100),
    theme VARCHAR(100),
    word_count INT,
    reading_time_minutes INT,
    ai_generated BOOLEAN DEFAULT TRUE,
    prompt_used TEXT,
    is_published BOOLEAN DEFAULT TRUE,
    view_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    INDEX idx_user_id (user_id),
    INDEX idx_category_id (category_id),
    INDEX idx_created_at (created_at),
    FULLTEXT INDEX ft_title_content (title, content)
);

-- Story Images Table (for multiple images per story)
CREATE TABLE story_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    image_url VARCHAR(255) NOT NULL,
    alt_text VARCHAR(255),
    position INT DEFAULT 0,
    ai_generated BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
    INDEX idx_story_id (story_id)
);

-- Comments Table
CREATE TABLE comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    is_approved BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_story_id (story_id),
    INDEX idx_user_id (user_id)
);

-- Ratings Table (1-5 star system)
CREATE TABLE ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    user_id INT NOT NULL,
    rating INT CHECK (rating >= 1 AND rating <= 5),
    review TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_story_user_rating (story_id, user_id),
    INDEX idx_story_id (story_id)
);

-- Favorites Table
CREATE TABLE favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    story_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_story_favorite (user_id, story_id),
    INDEX idx_user_id (user_id)
);

-- Reading History Table
CREATE TABLE reading_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    story_id INT NOT NULL,
    last_read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    view_count INT DEFAULT 1,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_story_history (user_id, story_id),
    INDEX idx_user_id (user_id)
);

-- Insert Sample Categories
INSERT INTO categories (name, description, slug, color) VALUES
('Fantasy', 'Magical worlds and adventures', 'fantasy', '#8B5CF6'),
('Science Fiction', 'Future and space stories', 'sci-fi', '#06B6D4'),
('Romance', 'Love and relationships', 'romance', '#EC4899'),
('Mystery', 'Detective and suspense', 'mystery', '#6366F1'),
('Adventure', 'Action-packed journeys', 'adventure', '#F59E0B'),
('Drama', 'Emotional and character-driven', 'drama', '#EF4444'),
('Horror', 'Scary and thrilling tales', 'horror', '#1F2937'),
('Comedy', 'Humorous and funny stories', 'comedy', '#FBBF24'),
('Thriller', 'Intense and suspenseful', 'thriller', '#DC2626'),
('Historical', 'Set in past time periods', 'historical', '#B45309');

-- Create Views for Analytics
CREATE VIEW user_story_stats AS
SELECT 
    u.id,
    u.username,
    COUNT(s.id) as total_stories,
    SUM(s.view_count) as total_views,
    AVG(r.rating) as avg_rating
FROM users u
LEFT JOIN stories s ON u.id = s.user_id
LEFT JOIN ratings r ON s.id = r.story_id
GROUP BY u.id;

CREATE VIEW trending_stories AS
SELECT 
    s.id,
    s.title,
    s.user_id,
    COUNT(DISTINCT f.user_id) as favorite_count,
    AVG(r.rating) as avg_rating,
    COUNT(DISTINCT c.id) as comment_count,
    s.view_count,
    s.created_at
FROM stories s
LEFT JOIN favorites f ON s.id = f.story_id
LEFT JOIN ratings r ON s.id = r.story_id
LEFT JOIN comments c ON s.id = c.story_id
WHERE s.is_published = TRUE
GROUP BY s.id
ORDER BY (COUNT(DISTINCT f.user_id) + AVG(COALESCE(r.rating, 0)) + s.view_count/100) DESC;

-- Create Indexes for Performance
CREATE INDEX idx_stories_published ON stories(is_published);
CREATE INDEX idx_comments_created ON comments(created_at);
CREATE INDEX idx_favorites_created ON favorites(created_at);
