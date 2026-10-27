-- データベース作成時に文字コードを指定
CREATE DATABASE IF NOT EXISTS soshage_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE soshage_db;

-- 接続文字コードを明示
SET NAMES utf8mb4;

DROP TABLE IF EXISTS user_cards;
DROP TABLE IF EXISTS cards;
DROP TABLE IF EXISTS users;

-- ユーザーテーブル
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- カードマスタテーブル
CREATE TABLE cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    next_card_id INT DEFAULT NULL,
    hp INT DEFAULT 100,
    atk INT DEFAULT 10,
    def INT DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ユーザー所持カードテーブル
CREATE TABLE user_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    card_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サンプルデータ
INSERT INTO users (id, name) VALUES (1, 'テストユーザー');

-- マスタデータ
INSERT INTO cards (id, name, next_card_id, hp, atk, def) VALUES 
(1, '神レアアイテム', NULL, 500, 100, 100),
(2, '超レアアイテム', 1, 300, 50, 50),
(3, 'レアアイテム', 2, 200, 30, 30);

-- 所持カードデータ
INSERT INTO user_cards (user_id, card_id) VALUES 
(1, 2),
(1, 2),
(1, 2),
(1, 3);