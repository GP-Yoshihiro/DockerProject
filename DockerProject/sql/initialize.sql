-- データベース作成時に文字コードを指定
CREATE DATABASE IF NOT EXISTS soshage_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE soshage_db;

-- 接続文字コードを明示
SET NAMES utf8mb4;

DROP TABLE IF EXISTS user_team_members;
DROP TABLE IF EXISTS user_teams;
DROP TABLE IF EXISTS user_box_items;
DROP TABLE IF EXISTS gacha_histories;
DROP TABLE IF EXISTS gacha_items;
DROP TABLE IF EXISTS gachas;
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
    def INT DEFAULT 10,
    -- レア度（星の数）: 5 = 最高レア … 1 = 最低。ガチャ結果の演出（光り方）に使う
    rarity TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- 画像ファイル名（htdocs/images/cards/ 内のファイル）。NULL のときは画像なし
    -- フォルダのパスはPHP側（lib/card_lib.php）で付けるので、置き場所を変えてもDBは直さなくてよい
    image VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ユーザー所持カードテーブル
CREATE TABLE user_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    card_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- 「あるユーザーの、あるカードの所持分」を探す用（強化画面の WHERE user_id = ? AND card_id = ?）
    -- user_id だけで検索する場合（所持カード一覧・チーム編成）にも左端の列として使われる
    INDEX idx_user_card (user_id, card_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サンプルデータ
INSERT INTO users (id, name) VALUES (1, 'テストユーザー');

-- マスタデータ
INSERT INTO cards (id, name, next_card_id, hp, atk, def, rarity, image) VALUES 
(1, '神レアアイテム', NULL, 500, 100, 100, 5, 'chara_1.svg'),
(2, '超レアアイテム', 1, 300, 50, 50, 4, 'chara_2.svg'),
(3, 'レアアイテム', 2, 200, 30, 30, 3, 'chara_3.svg'),
(4, '普通のアイテム', NULL, 150, 20, 20, 2, 'chara_4.svg'),
(5, 'ガラクタ', NULL, 100, 10, 10, 1, 'chara_5.svg');

-- 所持カードデータ
INSERT INTO user_cards (user_id, card_id) VALUES 
(1, 2),
(1, 2),
(1, 2),
(1, 3);


-- =========================================================
-- ガチャ関連テーブル
-- =========================================================

-- ガチャマスタ
-- start_at / end_at でガチャの開催期間を持たせる（end_at が NULL なら常設）
-- type: normal = 通常（重みで抽選） / box = ボックスガチャ（当たったアイテムの残数が減っていく）
CREATE TABLE gachas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    type ENUM('normal', 'box') NOT NULL DEFAULT 'normal',
    start_at DATETIME NOT NULL,
    end_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ガチャのラインナップ（どのガチャから、どのカードが、どの重みで出るか）
-- weight（重み）: 排出確率 = weight / 同じガチャの weight 合計
--   ボックスガチャでは「箱に最初に入っている個数」として使う
CREATE TABLE gacha_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gacha_id INT NOT NULL,
    card_id INT NOT NULL,
    weight INT UNSIGNED NOT NULL,
    -- 同じガチャに同じカードを二重登録させない（gacha_id で検索する際の索引も兼ねる）
    UNIQUE KEY uq_gacha_card (gacha_id, card_id),
    FOREIGN KEY (gacha_id) REFERENCES gachas(id),
    FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ガチャ履歴（1回の抽選で1行）
-- draw_id: 同じ「10連」で引いた10行に同じ値を入れ、1回の実行単位でまとめる
CREATE TABLE gacha_histories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    draw_id CHAR(32) NOT NULL,
    user_id INT NOT NULL,
    gacha_id INT NOT NULL,
    card_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- 「あるユーザーの履歴を新しい順に見る」用（WHERE user_id = ? ORDER BY created_at DESC）
    INDEX idx_user_created (user_id, created_at),
    -- 「ガチャごと・カードごとの排出数を集計する」用（WHERE gacha_id = ? GROUP BY card_id）
    INDEX idx_gacha_card (gacha_id, card_id),
    -- 「1回の10連の結果をまとめて取り出す」用（WHERE draw_id = ?）
    INDEX idx_draw (draw_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (gacha_id) REFERENCES gachas(id),
    FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ガチャマスタデータ
INSERT INTO gachas (id, name, type, start_at, end_at) VALUES
(1, '通常ガチャ', 'normal', '2026-01-01 00:00:00', NULL),
(2, '期間限定ガチャ（神レア確率アップ）', 'normal', '2026-11-01 00:00:00', '2026-12-31 23:59:59'),
(3, '終了済みガチャ', 'normal', '2026-01-01 00:00:00', '2026-01-31 23:59:59'),
(4, 'ボックスガチャ', 'box', '2026-01-01 00:00:00', NULL);

-- ラインナップ（重みの合計はどれも 100 にしてあるので、重み = 排出率(%)）
INSERT INTO gacha_items (gacha_id, card_id, weight) VALUES
-- 通常ガチャ
(1, 1, 1),
(1, 2, 4),
(1, 3, 15),
(1, 4, 30),
(1, 5, 50),
-- 期間限定ガチャ
(2, 1, 5),
(2, 2, 10),
(2, 3, 15),
(2, 4, 30),
(2, 5, 40),
-- 終了済みガチャ
(3, 1, 1),
(3, 2, 4),
(3, 3, 15),
(3, 4, 30),
(3, 5, 50),
-- ボックスガチャ（箱の中身: 合計100個）
(4, 1, 1),
(4, 2, 3),
(4, 3, 10),
(4, 4, 26),
(4, 5, 60);


-- =========================================================
-- 応用: ボックスガチャ・チーム編成
-- =========================================================

-- ユーザーごとのボックスの中身（残数）
-- 初めて引くときに gacha_items.weight（初期個数）からコピーして作る。リセット時も同様。
CREATE TABLE user_box_items (
    user_id INT NOT NULL,
    gacha_id INT NOT NULL,
    card_id INT NOT NULL,
    remaining INT UNSIGNED NOT NULL,
    -- 「あるユーザーの、あるボックスの中身」を取る（WHERE user_id = ? AND gacha_id = ?）ので
    -- この3列を主キーにすれば、そのまま検索用の索引になる
    PRIMARY KEY (user_id, gacha_id, card_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (gacha_id) REFERENCES gachas(id),
    FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- チーム
CREATE TABLE user_teams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- チームのメンバー（チームの何番目の枠に、どの所持カードを入れたか）
CREATE TABLE user_team_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_id INT NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    user_card_id INT NOT NULL,
    -- 1つの枠には1枚だけ
    UNIQUE KEY uq_team_position (team_id, position),
    -- 同じカード（インスタンス）を同じチームに2回入れない
    UNIQUE KEY uq_team_card (team_id, user_card_id),
    FOREIGN KEY (team_id) REFERENCES user_teams(id) ON DELETE CASCADE,
    -- 強化の素材にしてカードが消えたら、チームからも外れる
    FOREIGN KEY (user_card_id) REFERENCES user_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO user_teams (id, user_id, name) VALUES
(1, 1, 'チーム1'),
(2, 1, 'チーム2'),
(3, 1, 'チーム3');
