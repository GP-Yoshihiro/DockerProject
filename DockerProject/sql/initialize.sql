-- データベース作成時に文字コードを指定
CREATE DATABASE IF NOT EXISTS soshage_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE soshage_db;

-- 接続文字コードを明示
SET NAMES utf8mb4;

DROP TABLE IF EXISTS banner_histories;
DROP TABLE IF EXISTS user_gacha_pity;
DROP TABLE IF EXISTS banners;
DROP TABLE IF EXISTS user_w_engines;
DROP TABLE IF EXISTS w_engines;
DROP TABLE IF EXISTS shop_items;
DROP TABLE IF EXISTS game_battles;
DROP TABLE IF EXISTS user_stages;
DROP TABLE IF EXISTS stage_rewards;
DROP TABLE IF EXISTS stage_waves;
DROP TABLE IF EXISTS stages;
DROP TABLE IF EXISTS enemies;
DROP TABLE IF EXISTS user_items;
DROP TABLE IF EXISTS battle_rankings;
DROP TABLE IF EXISTS battle_histories;
DROP TABLE IF EXISTS user_team_members;
DROP TABLE IF EXISTS user_teams;
DROP TABLE IF EXISTS user_box_items;
DROP TABLE IF EXISTS gacha_histories;
DROP TABLE IF EXISTS gacha_items;
DROP TABLE IF EXISTS gachas;
DROP TABLE IF EXISTS user_cards;
DROP TABLE IF EXISTS cards;
DROP TABLE IF EXISTS skills;
DROP TABLE IF EXISTS items;
DROP TABLE IF EXISTS users;

-- ユーザーテーブル
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- スキルマスタ（カード・敵が使うスキル）
--   effect: damage_single 敵単体ダメージ / damage_all 敵全体ダメージ / heal_single 味方単体回復 / heal_all 味方全体回復
--           taunt 挑発（敵の攻撃を自分に集め、受けるダメージを軽減）/ buff_atk 味方全体の攻撃アップ / debuff_def 敵全体の防御ダウン
--   power:  ダメージ・回復は攻撃力に対する倍率(%)、taunt は軽減率(%)、buff_atk / debuff_def は効果量(%)
--   cooldown: 何ターンに1回使えるか / duration: 効果が続くターン数（taunt・buff・debuff のみ）
CREATE TABLE skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    effect ENUM('damage_single', 'damage_all', 'heal_single', 'heal_all', 'taunt', 'buff_atk', 'debuff_def') NOT NULL,
    power INT UNSIGNED NOT NULL,
    cooldown TINYINT UNSIGNED NOT NULL,
    duration TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- カードマスタテーブル
-- hp / atk / def / spd はレベル1のときの基礎値（レベル・凸による上昇は PHP 側 lib/card_growth_lib.php で計算）
CREATE TABLE cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    hp INT DEFAULT 100,
    atk INT DEFAULT 10,
    def INT DEFAULT 10,
    -- 素早さ: バトルで行動する順番（大きいほど先に動く）
    spd INT NOT NULL DEFAULT 100,
    -- レア度: 5 = S級キャラ / 3 = A級キャラ
    --   キャラと音動機を合わせた順番（演出の光り方にも使う）:
    --   5 Sキャラ ＞ 4 S音動機 ＞ 3 Aキャラ ＞ 2 A音動機 ＞ 1 B音動機
    rarity TINYINT UNSIGNED NOT NULL DEFAULT 3,
    -- 属性（敵の弱点なら与ダメージ1.5倍・耐性なら0.5倍）
    attribute ENUM('ether', 'fire', 'ice', 'physical', 'electric') NOT NULL,
    -- タイプ（凸で伸びるステータスとスキルが変わる）
    role ENUM('attacker', 'tank', 'healer', 'support') NOT NULL,
    skill_id INT NOT NULL,
    -- 画像ファイル名（htdocs/images/cards/ 内のファイル）。NULL・ファイルが無いときは代わりの画像
    -- ※ サーバー（Linux）では大文字・小文字が区別されるので、ファイル名は正確に合わせること
    -- フォルダのパスはPHP側（lib/card_lib.php）で付けるので、置き場所を変えてもDBは直さなくてよい
    image VARCHAR(255) DEFAULT NULL,
    -- 特殊演出（カットイン）用の画像ファイル名。設定したカードを引くと、カードがめくれる前に演出が入る
    -- NULL のカードは演出なし。画像が見つからないときも演出を飛ばしてそのまま進む
    cutin_image VARCHAR(255) DEFAULT NULL,
    -- アイコン・立ち絵（htdocs/images/ からのパス。例: zzz/icon/xxx.png）。NULL なら image を使う
    icon_image VARCHAR(255) DEFAULT NULL,
    stand_image VARCHAR(255) DEFAULT NULL,
    -- ガチャの排出: standard 常設（すり抜けでも出る）/ limited 限定（ピックアップのときだけ出る）
    pool ENUM('standard', 'limited') NOT NULL DEFAULT 'standard',
    -- FALSE にするとガチャから出なくなる（登録画面で切り替え）
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (skill_id) REFERENCES skills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- アイテムマスタ（ガチャ石・ゴールドなどの通貨もアイテムとして持つ）
--   category: currency 通貨 / evolution 進化素材（結晶）/ exp 経験値アイテム
CREATE TABLE items (
    id INT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    category ENUM('currency', 'evolution', 'exp', 'engine_exp', 'engine_evolution') NOT NULL,
    -- 進化素材の属性（そのカードの属性の結晶が必要）
    attribute ENUM('ether', 'fire', 'ice', 'physical', 'electric') DEFAULT NULL,
    -- 経験値アイテム（キャラ用 exp / 音動機用 engine_exp）を使ったときに得られる経験値
    exp_value INT UNSIGNED DEFAULT NULL,
    description VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ユーザー所持カードテーブル
-- 1ユーザーにつき同じカードは1枚だけ。ガチャで重複したら limit_break（凸）が増える
CREATE TABLE user_cards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    card_id INT NOT NULL,
    level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- 今のレベルでたまっている経験値（次のレベルまでの必要量は 10 × レベル + 20）
    exp INT UNSIGNED NOT NULL DEFAULT 0,
    -- 進化段階 0〜3（レベル上限 20 / 30 / 40 / 50）
    evolution TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- 凸数 0〜6（3凸・5凸で、最終進化後のレベル上限 +5）
    limit_break TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- 同じカードを2枚持たない（ガチャで「持っているか」を調べる索引も兼ねる）
    -- user_id だけで検索する場合（所持カード一覧・チーム編成）にも左端の列として使われる
    UNIQUE KEY uq_user_card (user_id, card_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ユーザーの所持アイテム
CREATE TABLE user_items (
    user_id INT NOT NULL,
    item_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, item_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サンプルデータ
INSERT INTO users (id, name) VALUES
(1, 'テストユーザー'),
-- 資料06: 対戦ランキング用のユーザー
(2, 'takahasi'),
(3, 'yamada'),
(4, 'azuma');

-- スキルマスタデータ（1〜10: カード用 / 101〜: 敵用）
INSERT INTO skills (id, name, effect, power, cooldown, duration) VALUES
(1, '聖なる光', 'damage_all', 160, 4, 0),
(2, '紅蓮の一撃', 'damage_single', 280, 3, 0),
(3, '戦術指揮', 'buff_atk', 30, 4, 3),
(4, 'サンダーボルト', 'damage_single', 240, 3, 0),
(5, '鉄壁の構え', 'taunt', 40, 4, 2),
(6, '癒しの氷雨', 'heal_all', 150, 4, 0),
(7, '旋風脚', 'damage_all', 130, 3, 0),
(8, '雷の盾', 'taunt', 30, 4, 2),
(9, 'ヒール', 'heal_single', 250, 3, 0),
(10, '氷結の呪い', 'debuff_def', 30, 4, 2),
(101, '地響き', 'damage_all', 90, 3, 0),
(102, '雷撃', 'damage_single', 180, 3, 0),
(103, '虚無の息吹', 'damage_all', 140, 3, 0),
(104, '凍える吐息', 'damage_all', 80, 4, 0);

-- カードマスタデータ（6〜10 は追加キャラ。画像は各自で htdocs/images/cards/ に置く）
-- S級: マリー・ミカ・アオイ / A級: それ以外
-- 新しいキャラは「登録画面」（/game/admin.php）から、htdocs/images/zzz/ の画像を選んで登録する
INSERT INTO cards (id, name, hp, atk, def, spd, rarity, attribute, role, skill_id, image, cutin_image) VALUES
(1, 'マリー', 810, 192, 72, 112, 5, 'ether', 'attacker', 1, 'mary.png', 'mary01.png'),
(2, 'ミカ', 702, 168, 63, 114, 5, 'fire', 'attacker', 2, 'mika.png', NULL),
(3, 'キサキ', 660, 114, 66, 105, 3, 'ether', 'support', 3, 'kisaki.png', NULL),
(4, 'ケイ', 504, 120, 45, 112, 3, 'electric', 'attacker', 4, 'kei.png', NULL),
(5, 'カズサ', 624, 68, 63, 89, 3, 'physical', 'tank', 5, 'kazusa.png', NULL),
(6, 'アオイ', 858, 119, 70, 95, 5, 'ice', 'healer', 6, 'aoi.png', NULL),
(7, 'レン', 594, 144, 54, 112, 3, 'physical', 'attacker', 7, 'ren.png', NULL),
(8, 'ツバサ', 858, 96, 84, 89, 3, 'electric', 'tank', 8, 'tsubasa.png', NULL),
(9, 'シオン', 616, 85, 50, 95, 3, 'fire', 'healer', 9, 'shion.png', NULL),
(10, 'ユズハ', 480, 81, 50, 107, 3, 'ice', 'support', 10, 'yuzuha.png', NULL);

-- アイテムマスタデータ
INSERT INTO items (id, name, category, attribute, exp_value, description) VALUES
(1, 'ガチャ石', 'currency', NULL, NULL, 'ガチャを引くのに使う（単発 300 / 10連 3000）'),
(2, 'ゴールド', 'currency', NULL, NULL, '進化に使う'),
(3, 'ショップメダル', 'currency', NULL, NULL, '完凸したカードがガチャで出ると手に入る。ショップで交換できる'),
(11, 'エーテルの結晶', 'evolution', 'ether', NULL, 'エーテル属性のカードの進化に使う'),
(12, '炎の結晶', 'evolution', 'fire', NULL, '炎属性のカードの進化に使う'),
(13, '氷の結晶', 'evolution', 'ice', NULL, '氷属性のカードの進化に使う'),
(14, '物理の結晶', 'evolution', 'physical', NULL, '物理属性のカードの進化に使う'),
(15, '電気の結晶', 'evolution', 'electric', NULL, '電気属性のカードの進化に使う'),
(21, '経験値ポーション（小）', 'exp', NULL, 100, 'カードに経験値 100'),
(22, '経験値ポーション（中）', 'exp', NULL, 500, 'カードに経験値 500'),
(23, '経験値ポーション（大）', 'exp', NULL, 2000, 'カードに経験値 2000'),
(31, 'チューニングパーツ（小）', 'engine_exp', NULL, 100, '音動機に経験値 100'),
(32, 'チューニングパーツ（中）', 'engine_exp', NULL, 500, '音動機に経験値 500'),
(33, 'チューニングパーツ（大）', 'engine_exp', NULL, 2000, '音動機に経験値 2000'),
(35, 'チューニングコア', 'engine_evolution', NULL, NULL, '音動機の進化に使う');

-- 所持カードデータ（テストユーザーの初期カード・対戦相手のサンプル）
INSERT INTO user_cards (id, user_id, card_id, level, exp, evolution, limit_break) VALUES
-- テストユーザー（初期カード）
(1, 1, 2, 1, 0, 0, 0),
(2, 1, 3, 1, 0, 0, 0),
(3, 1, 4, 1, 0, 0, 0),
(4, 1, 5, 1, 0, 0, 0),
(5, 1, 9, 1, 0, 0, 0),
-- takahasi（強い）
(6, 2, 1, 40, 0, 2, 2),
(7, 2, 6, 35, 0, 2, 0),
(8, 2, 8, 35, 0, 2, 1),
-- yamada
(9, 3, 7, 15, 0, 0, 1),
(10, 3, 4, 18, 0, 0, 0),
(11, 3, 10, 12, 0, 0, 0),
-- azuma
(12, 4, 5, 10, 0, 0, 0),
(13, 4, 9, 8, 0, 0, 0),
(14, 4, 3, 10, 0, 0, 0);

-- 所持アイテム（テストユーザーの初期アイテム）
INSERT INTO user_items (user_id, item_id, quantity) VALUES
(1, 1, 3000),
(1, 2, 1000),
(1, 21, 5),
(1, 31, 5);


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
(2, '期間限定ガチャ（UR確率アップ）', 'normal', '2026-11-01 00:00:00', '2026-12-31 23:59:59'),
(3, '終了済みガチャ', 'normal', '2026-01-01 00:00:00', '2026-01-31 23:59:59'),
(4, 'ボックスガチャ', 'box', '2026-01-01 00:00:00', NULL);

-- ラインナップ（重みの合計はどれも 100 にしてあるので、重み = 排出率(%)）
--   UR: マリー / SSR: ミカ・アオイ / SR: キサキ・レン・ツバサ / R: ケイ・シオン / N: カズサ・ユズハ
INSERT INTO gacha_items (gacha_id, card_id, weight) VALUES
-- 通常ガチャ（UR 1% / SSR 4% / SR 15% / R 30% / N 50%）
(1, 1, 1),
(1, 2, 2),
(1, 6, 2),
(1, 3, 5),
(1, 7, 5),
(1, 8, 5),
(1, 4, 15),
(1, 9, 15),
(1, 5, 25),
(1, 10, 25),
-- 期間限定ガチャ（UR 5% / SSR 10% / SR 15% / R 30% / N 40%）
(2, 1, 5),
(2, 2, 5),
(2, 6, 5),
(2, 3, 5),
(2, 7, 5),
(2, 8, 5),
(2, 4, 15),
(2, 9, 15),
(2, 5, 20),
(2, 10, 20),
-- 終了済みガチャ
(3, 1, 1),
(3, 2, 2),
(3, 6, 2),
(3, 3, 5),
(3, 7, 5),
(3, 8, 5),
(3, 4, 15),
(3, 9, 15),
(3, 5, 25),
(3, 10, 25),
-- ボックスガチャ（箱の中身: 合計100個）
(4, 1, 1),
(4, 2, 2),
(4, 6, 1),
(4, 3, 4),
(4, 7, 3),
(4, 8, 3),
(4, 4, 13),
(4, 9, 13),
(4, 5, 30),
(4, 10, 30);


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
(3, 1, 'チーム3'),
-- 対戦（PvP）の相手になるサンプルユーザーのチーム
(4, 2, 'takahasi のチーム'),
(5, 3, 'yamada のチーム'),
(6, 4, 'azuma のチーム');

INSERT INTO user_team_members (team_id, position, user_card_id) VALUES
-- テストユーザーのチーム1（カズサ・ミカ・シオン）
(1, 1, 4),
(1, 2, 1),
(1, 3, 5),
(4, 1, 8),
(4, 2, 6),
(4, 3, 7),
(5, 1, 9),
(5, 2, 10),
(5, 3, 11),
(6, 1, 12),
(6, 2, 14),
(6, 3, 13);


-- =========================================================
-- 資料06: 対戦履歴・対戦ランキング
-- =========================================================

-- 対戦履歴（1回のバトルで1行）
-- winner_user_id: 勝ったユーザー。NULL なら引き分け
CREATE TABLE battle_histories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    player1_user_id INT NOT NULL,
    player2_user_id INT NOT NULL,
    winner_user_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- 集計で「あるユーザーが参加したバトル」を探す用
    INDEX idx_player1 (player1_user_id),
    INDEX idx_player2 (player2_user_id),
    FOREIGN KEY (player1_user_id) REFERENCES users(id),
    FOREIGN KEY (player2_user_id) REFERENCES users(id),
    FOREIGN KEY (winner_user_id) REFERENCES users(id),
    -- 自分自身とは対戦しない／勝者は対戦した2人のどちらか
    CHECK (player1_user_id <> player2_user_id),
    CHECK (winner_user_id IS NULL OR winner_user_id IN (player1_user_id, player2_user_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 対戦ランキング（集計結果）
-- crontab から1分ごとに実行される htdocs/batch/summarize_battles.php が作り直す。
-- 画面（battle/ranking.php）はこの表を読むだけなので、対戦履歴が増えても表示は重くならない
CREATE TABLE battle_rankings (
    user_id INT PRIMARY KEY,
    rank_no INT UNSIGNED NOT NULL,
    wins INT UNSIGNED NOT NULL,
    losses INT UNSIGNED NOT NULL,
    draws INT UNSIGNED NOT NULL,
    -- 勝率(%) = 勝ち / (勝ち + 負け + 引き分け) × 100（小数第2位まで）
    win_rate DECIMAL(5, 2) NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_rank (rank_no),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サンプルの対戦履歴（集計すると資料の例と同じ成績になる）
--   takahasi: 12勝 0敗 3分 → 80.00%
--   yamada:    8勝13敗10分 → 25.81%
--   azuma:     9勝16敗13分 → 23.68%
INSERT INTO battle_histories (player1_user_id, player2_user_id, winner_user_id) VALUES
-- takahasi vs yamada: takahasi の勝ち × 4
(2, 3, 2),
(2, 3, 2),
(2, 3, 2),
(2, 3, 2),
-- takahasi vs azuma: takahasi の勝ち × 8
(2, 4, 2),
(2, 4, 2),
(2, 4, 2),
(2, 4, 2),
(2, 4, 2),
(2, 4, 2),
(2, 4, 2),
(2, 4, 2),
-- takahasi vs azuma: 引き分け × 3
(2, 4, NULL),
(2, 4, NULL),
(2, 4, NULL),
-- yamada vs azuma: yamada の勝ち × 8
(3, 4, 3),
(3, 4, 3),
(3, 4, 3),
(3, 4, 3),
(3, 4, 3),
(3, 4, 3),
(3, 4, 3),
(3, 4, 3),
-- yamada vs azuma: azuma の勝ち × 9
(3, 4, 4),
(3, 4, 4),
(3, 4, 4),
(3, 4, 4),
(3, 4, 4),
(3, 4, 4),
(3, 4, 4),
(3, 4, 4),
(3, 4, 4),
-- yamada vs azuma: 引き分け × 10
(3, 4, NULL),
(3, 4, NULL),
(3, 4, NULL),
(3, 4, NULL),
(3, 4, NULL),
(3, 4, NULL),
(3, 4, NULL),
(3, 4, NULL),
(3, 4, NULL),
(3, 4, NULL);


-- =========================================================
-- ゲーム: ステージ攻略（RPG）・対戦・ショップ
-- =========================================================

-- 敵マスタ
-- weakness: 弱点属性（この属性の攻撃で 1.5倍）/ resistance: 耐性属性（0.5倍）
-- image: htdocs/images/enemies/ 内のファイル名（各自で用意。無いときは代わりの画像）
CREATE TABLE enemies (
    id INT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    hp INT NOT NULL,
    atk INT NOT NULL,
    def INT NOT NULL,
    spd INT NOT NULL,
    weakness ENUM('ether', 'fire', 'ice', 'physical', 'electric') DEFAULT NULL,
    resistance ENUM('ether', 'fire', 'ice', 'physical', 'electric') DEFAULT NULL,
    skill_id INT DEFAULT NULL,
    image VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (skill_id) REFERENCES skills(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ステージマスタ（id の順に1本道。前のステージをクリアすると次が解放される）
--   target_turns: このターン数以内にクリアすると ★3
--   exp: 出撃した3人それぞれがもらえる経験値
CREATE TABLE stages (
    id INT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    recommended_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    target_turns TINYINT UNSIGNED NOT NULL,
    exp INT UNSIGNED NOT NULL,
    is_boss BOOLEAN NOT NULL DEFAULT FALSE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ステージのウェーブごとの敵（position: 並び順）
CREATE TABLE stage_waves (
    stage_id INT NOT NULL,
    wave TINYINT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    enemy_id INT NOT NULL,
    PRIMARY KEY (stage_id, wave, position),
    FOREIGN KEY (stage_id) REFERENCES stages(id),
    FOREIGN KEY (enemy_id) REFERENCES enemies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ステージの報酬
--   kind: first 初回クリア時のみ / repeat 毎回（初回も含む）
--   probability: もらえる確率(%)
CREATE TABLE stage_rewards (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stage_id INT NOT NULL,
    kind ENUM('first', 'repeat') NOT NULL,
    item_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    probability TINYINT UNSIGNED NOT NULL DEFAULT 100,
    INDEX idx_stage (stage_id),
    FOREIGN KEY (stage_id) REFERENCES stages(id),
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ユーザーごとのステージのクリア状況
CREATE TABLE user_stages (
    user_id INT NOT NULL,
    stage_id INT NOT NULL,
    best_stars TINYINT UNSIGNED NOT NULL DEFAULT 0,
    clear_count INT UNSIGNED NOT NULL DEFAULT 0,
    first_cleared_at DATETIME DEFAULT NULL,
    PRIMARY KEY (user_id, stage_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (stage_id) REFERENCES stages(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- バトル結果（ステージ・対戦）
-- サーバで計算したバトルのログ（JSON）を保存し、battle_view.php で JavaScript が演出として再生する
CREATE TABLE game_battles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    mode ENUM('stage', 'pvp') NOT NULL,
    stage_id INT DEFAULT NULL,
    opponent_user_id INT DEFAULT NULL,
    team_id INT NOT NULL,
    result ENUM('win', 'lose', 'draw') NOT NULL,
    stars TINYINT UNSIGNED NOT NULL DEFAULT 0,
    turns SMALLINT UNSIGNED NOT NULL,
    log_json MEDIUMTEXT NOT NULL,
    rewards_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_user_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (stage_id) REFERENCES stages(id),
    FOREIGN KEY (opponent_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ショップ（ショップメダルで交換）
CREATE TABLE shop_items (
    id INT PRIMARY KEY,
    item_id INT NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    price INT UNSIGNED NOT NULL,
    FOREIGN KEY (item_id) REFERENCES items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 敵マスタデータ
INSERT INTO enemies (id, name, hp, atk, def, spd, weakness, resistance, skill_id, image) VALUES
(1, 'スライム', 300, 70, 20, 80, 'physical', NULL, NULL, 'slime.png'),
(2, 'アイスバット', 450, 115, 35, 120, 'fire', 'ice', NULL, 'ice_bat.png'),
(3, 'フロストウルフ', 1100, 150, 55, 100, 'fire', 'ice', 104, 'frost_wolf.png'),
(4, 'スクラップドローン', 650, 160, 85, 115, 'electric', 'physical', NULL, 'scrap_drone.png'),
(5, 'アイアンゴーレム', 2800, 200, 140, 60, 'electric', 'physical', 101, 'iron_golem.png'),
(6, 'ボルトスピリット', 800, 195, 80, 125, 'ice', 'electric', NULL, 'volt_spirit.png'),
(7, 'サンダーナイト', 1800, 235, 130, 95, 'ether', 'electric', 102, 'thunder_knight.png'),
(8, 'シャドウ', 1500, 270, 125, 110, 'fire', 'ether', NULL, 'shadow.png'),
(9, 'ヴォイドドラゴン', 8000, 340, 170, 90, 'fire', 'ether', 103, 'void_dragon.png');

-- ステージマスタデータ
-- 推奨レベル・目標ターンは、バトルをシミュレーションして調整した値
--   （初期チーム カズサ・ミカ・シオン で: ステージ3 は Lv12〜15、ステージ4 は Lv30 前後、ボスは編成次第で Lv40〜45）
INSERT INTO stages (id, name, description, recommended_level, target_turns, exp, is_boss) VALUES
(1, '始まりの草原', 'スライムが住む穏やかな草原。まずはここで腕試し。', 1, 5, 150, FALSE),
(2, '凍てつく洞窟', '氷の魔物が潜む洞窟。炎の攻撃がよく効く。', 5, 8, 250, FALSE),
(3, '廃工場', '暴走した機械が動き回る工場跡。物理攻撃は効きにくい。', 15, 12, 400, FALSE),
(4, '雷鳴の塔', '雷の力に満ちた塔。電気の攻撃はほとんど効かない。', 30, 15, 600, FALSE),
(5, '【BOSS】虚無の竜', '全てを飲み込む竜が待ち受ける。エーテルの力は通じない。', 45, 18, 1000, TRUE);

INSERT INTO stage_waves (stage_id, wave, position, enemy_id) VALUES
(1, 1, 1, 1), (1, 1, 2, 1), (1, 1, 3, 1),
(2, 1, 1, 2), (2, 1, 2, 2),
(2, 2, 1, 2), (2, 2, 2, 3),
(3, 1, 1, 4), (3, 1, 2, 4), (3, 1, 3, 4),
(3, 2, 1, 5),
(4, 1, 1, 6), (4, 1, 2, 6),
(4, 2, 1, 6), (4, 2, 2, 7),
(4, 3, 1, 7), (4, 3, 2, 7),
(5, 1, 1, 8), (5, 1, 2, 8),
(5, 2, 1, 8), (5, 2, 2, 8), (5, 2, 3, 8),
(5, 3, 1, 9);

-- ステージ報酬（item_id: 1 ガチャ石 / 2 ゴールド / 11〜15 結晶 / 21〜23 経験値ポーション）
INSERT INTO stage_rewards (stage_id, kind, item_id, quantity, probability) VALUES
(1, 'first', 1, 300, 100), (1, 'first', 2, 1000, 100),
(1, 'repeat', 2, 300, 100), (1, 'repeat', 14, 1, 50), (1, 'repeat', 21, 1, 50), (1, 'repeat', 31, 1, 50),
(2, 'first', 1, 300, 100), (2, 'first', 2, 1500, 100),
(2, 'repeat', 2, 400, 100), (2, 'repeat', 12, 1, 100), (2, 'repeat', 13, 1, 50), (2, 'repeat', 21, 1, 50), (2, 'repeat', 31, 2, 100), (2, 'repeat', 35, 1, 20),
(3, 'first', 1, 300, 100), (3, 'first', 2, 2000, 100),
(3, 'repeat', 2, 600, 100), (3, 'repeat', 15, 2, 100), (3, 'repeat', 14, 1, 50), (3, 'repeat', 22, 1, 30), (3, 'repeat', 32, 1, 50), (3, 'repeat', 35, 1, 50),
(4, 'first', 1, 300, 100), (4, 'first', 2, 3000, 100),
(4, 'repeat', 2, 800, 100), (4, 'repeat', 13, 2, 100), (4, 'repeat', 11, 1, 100), (4, 'repeat', 15, 1, 50), (4, 'repeat', 22, 1, 50), (4, 'repeat', 32, 1, 100), (4, 'repeat', 35, 1, 100),
(5, 'first', 1, 1000, 100), (5, 'first', 2, 5000, 100),
(5, 'repeat', 2, 1500, 100), (5, 'repeat', 12, 2, 100), (5, 'repeat', 11, 2, 50), (5, 'repeat', 23, 1, 30), (5, 'repeat', 33, 1, 30), (5, 'repeat', 35, 2, 100);

-- ショップのラインナップ（価格はショップメダル）
INSERT INTO shop_items (id, item_id, quantity, price) VALUES
(1, 1, 300, 30),
(2, 11, 1, 5),
(3, 12, 1, 5),
(4, 13, 1, 5),
(5, 14, 1, 5),
(6, 15, 1, 5),
(7, 21, 3, 2),
(8, 22, 1, 3),
(9, 23, 1, 10),
(10, 2, 1000, 2),
(11, 32, 1, 3),
(12, 33, 1, 10),
(13, 35, 1, 5);


-- =========================================================
-- ゲーム: 音動機（キャラの装備）・ピックアップガチャ
-- =========================================================

-- 音動機マスタ
--   rarity: 4 = S級 / 2 = A級 / 1 = B級（キャラと合わせた順番: Sキャラ5 ＞ S音動機4 ＞ Aキャラ3 ＞ A音動機2 ＞ B音動機1）
--   image: htdocs/images/ からのパス（例: zzz/sound engine/A01.png）。NULL・ファイルが無いときは代わりの画像
--   base_atk: レベル1のときの攻撃力（装備したキャラの攻撃力に足す。レベルで上がる）
--   sub_stat / sub_value: サブステータス（HP・攻撃・防御のどれかを ○% アップ。進化で上がる）
--   effect_*: 誰が装備しても効く効果（凸で効果量アップ）
--     atk_pct 攻撃% / hp_pct HP% / def_pct 防御% / skill_dmg スキルのダメージ% / heal 回復量% / spd 素早さ
--     attr_dmg 属性汎用: effect_attribute と同じ属性のキャラが装備すると与ダメージ%
--   signature_card_id / signature_value: 専用効果。そのキャラが装備したときだけ、スキルの効果量 +○%
--     （ファイル名がキャラ名と同じ音動機は、登録画面で自動的にそのキャラ専用になる）
CREATE TABLE w_engines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    rarity TINYINT UNSIGNED NOT NULL,
    image VARCHAR(255) DEFAULT NULL,
    base_atk INT UNSIGNED NOT NULL,
    sub_stat ENUM('hp', 'atk', 'def') NOT NULL,
    sub_value TINYINT UNSIGNED NOT NULL,
    effect_type ENUM('atk_pct', 'hp_pct', 'def_pct', 'skill_dmg', 'heal', 'attr_dmg', 'spd') NOT NULL,
    effect_attribute ENUM('ether', 'fire', 'ice', 'physical', 'electric') DEFAULT NULL,
    effect_value SMALLINT UNSIGNED NOT NULL,
    signature_card_id INT DEFAULT NULL,
    signature_value SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    pool ENUM('standard', 'limited') NOT NULL DEFAULT 'standard',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    FOREIGN KEY (signature_card_id) REFERENCES cards(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 所持している音動機（1ユーザーにつき同じ音動機は1つ。重複は自動で凸、最大5凸）
-- equipped_user_card_id: 装備しているキャラ（所持カード）。1キャラに1つまで
CREATE TABLE user_w_engines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    w_engine_id INT NOT NULL,
    level TINYINT UNSIGNED NOT NULL DEFAULT 1,
    exp INT UNSIGNED NOT NULL DEFAULT 0,
    evolution TINYINT UNSIGNED NOT NULL DEFAULT 0,
    limit_break TINYINT UNSIGNED NOT NULL DEFAULT 0,
    equipped_user_card_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_engine (user_id, w_engine_id),
    UNIQUE KEY uq_equipped (equipped_user_card_id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (w_engine_id) REFERENCES w_engines(id),
    FOREIGN KEY (equipped_user_card_id) REFERENCES user_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ピックアップガチャ（バナー）
--   type: char 限定キャラ / engine 限定音動機 / standard 常設
--   天井のカウントは type ごとに持つので、ピックアップを変えても引き継がれる
CREATE TABLE banners (
    id INT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    type ENUM('char', 'engine', 'standard') NOT NULL UNIQUE,
    pickup_card_id INT DEFAULT NULL,
    pickup_engine_id INT DEFAULT NULL,
    FOREIGN KEY (pickup_card_id) REFERENCES cards(id),
    FOREIGN KEY (pickup_engine_id) REFERENCES w_engines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 天井の状態（ユーザー × ガチャの種類）
--   s_count: 前回のS級から何回引いたか（天井: 限定キャラ・常設 90 / 限定音動機 80）
--   a_count: 前回のA級以上から何回引いたか（10回目でA級以上が確定）
--   guaranteed: すり抜けたので、次のS級はピックアップ確定
CREATE TABLE user_gacha_pity (
    user_id INT NOT NULL,
    pity_type ENUM('char', 'engine', 'standard') NOT NULL,
    s_count INT UNSIGNED NOT NULL DEFAULT 0,
    a_count INT UNSIGNED NOT NULL DEFAULT 0,
    guaranteed BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (user_id, pity_type),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ピックアップガチャの履歴（1回の抽選で1行）
CREATE TABLE banner_histories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    banner_id INT NOT NULL,
    kind ENUM('card', 'engine') NOT NULL,
    item_id INT NOT NULL,
    tier TINYINT UNSIGNED NOT NULL,
    is_pickup BOOLEAN NOT NULL DEFAULT FALSE,
    -- S級が出たときの天井カウント（何連目で出たか）
    pull_no INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_user_created (user_id, created_at),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (banner_id) REFERENCES banners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 音動機マスタデータ
--   1〜3: 既存のS級キャラの専用音動機（画像なし）
--   11〜17: A01〜A07（A01〜A05 は属性汎用、A06 スキル、A07 回復）
--   21〜36: B01〜B16（B01〜B05 は属性汎用、B06〜 は汎用）
INSERT INTO w_engines (id, name, rarity, image, base_atk, sub_stat, sub_value, effect_type, effect_attribute, effect_value, signature_card_id, signature_value, pool) VALUES
(1, '聖光のタクト', 4, NULL, 48, 'atk', 12, 'atk_pct', NULL, 12, 1, 30, 'limited'),
(2, '紅蓮のギア', 4, NULL, 48, 'atk', 12, 'skill_dmg', NULL, 15, 2, 30, 'standard'),
(3, '氷雨のオルゴール', 4, NULL, 45, 'hp', 12, 'heal', NULL, 15, 6, 30, 'standard'),
(11, 'エーテル増幅器', 2, 'zzz/sound engine/A01.png', 40, 'atk', 10, 'attr_dmg', 'ether', 15, NULL, 0, 'standard'),
(12, '炎熱増幅器', 2, 'zzz/sound engine/A02.png', 40, 'atk', 10, 'attr_dmg', 'fire', 15, NULL, 0, 'standard'),
(13, '氷結増幅器', 2, 'zzz/sound engine/A03.png', 40, 'atk', 10, 'attr_dmg', 'ice', 15, NULL, 0, 'standard'),
(14, '衝撃増幅器', 2, 'zzz/sound engine/A04.png', 40, 'atk', 10, 'attr_dmg', 'physical', 15, NULL, 0, 'standard'),
(15, '雷電増幅器', 2, 'zzz/sound engine/A05.png', 40, 'atk', 10, 'attr_dmg', 'electric', 15, NULL, 0, 'standard'),
(16, '技巧の音叉', 2, 'zzz/sound engine/A06.png', 40, 'atk', 10, 'skill_dmg', NULL, 15, NULL, 0, 'standard'),
(17, '慈愛の音叉', 2, 'zzz/sound engine/A07.png', 38, 'hp', 10, 'heal', NULL, 15, NULL, 0, 'standard'),
(21, 'エーテル共振器', 1, 'zzz/sound engine/B01.png', 32, 'atk', 8, 'attr_dmg', 'ether', 10, NULL, 0, 'standard'),
(22, '炎熱共振器', 1, 'zzz/sound engine/B02.png', 32, 'atk', 8, 'attr_dmg', 'fire', 10, NULL, 0, 'standard'),
(23, '氷結共振器', 1, 'zzz/sound engine/B03.png', 32, 'atk', 8, 'attr_dmg', 'ice', 10, NULL, 0, 'standard'),
(24, '衝撃共振器', 1, 'zzz/sound engine/B04.png', 32, 'atk', 8, 'attr_dmg', 'physical', 10, NULL, 0, 'standard'),
(25, '雷電共振器', 1, 'zzz/sound engine/B05.png', 32, 'atk', 8, 'attr_dmg', 'electric', 10, NULL, 0, 'standard'),
(26, '攻撃の歯車', 1, 'zzz/sound engine/B06.png', 32, 'atk', 8, 'atk_pct', NULL, 8, NULL, 0, 'standard'),
(27, '生命の歯車', 1, 'zzz/sound engine/B07.png', 32, 'hp', 8, 'hp_pct', NULL, 10, NULL, 0, 'standard'),
(28, '守護の歯車', 1, 'zzz/sound engine/B08.png', 32, 'def', 8, 'def_pct', NULL, 10, NULL, 0, 'standard'),
(29, '技巧の歯車', 1, 'zzz/sound engine/B09.png', 32, 'atk', 8, 'skill_dmg', NULL, 10, NULL, 0, 'standard'),
(30, '治癒の歯車', 1, 'zzz/sound engine/B10.png', 32, 'hp', 8, 'heal', NULL, 10, NULL, 0, 'standard'),
(31, '疾風の歯車', 1, 'zzz/sound engine/B11.png', 32, 'atk', 8, 'spd', NULL, 5, NULL, 0, 'standard'),
(32, '攻撃のバネ', 1, 'zzz/sound engine/B12.png', 32, 'hp', 8, 'atk_pct', NULL, 6, NULL, 0, 'standard'),
(33, '生命のバネ', 1, 'zzz/sound engine/B13.png', 32, 'def', 8, 'hp_pct', NULL, 8, NULL, 0, 'standard'),
(34, '守護のバネ', 1, 'zzz/sound engine/B14.png', 32, 'hp', 8, 'def_pct', NULL, 8, NULL, 0, 'standard'),
(35, '技巧のバネ', 1, 'zzz/sound engine/B15.png', 32, 'def', 8, 'skill_dmg', NULL, 8, NULL, 0, 'standard'),
(36, '疾風のバネ', 1, 'zzz/sound engine/B16.png', 32, 'hp', 8, 'spd', NULL, 4, NULL, 0, 'standard');

-- マリーは限定キャラ（ピックアップのときだけ出る）。専用音動機も限定
UPDATE cards SET pool = 'limited' WHERE id = 1;

INSERT INTO banners (id, name, type, pickup_card_id, pickup_engine_id) VALUES
(1, 'Sキャラ ピックアップ', 'char', 1, NULL),
(2, 'モチーフ音動機 ピックアップ', 'engine', NULL, 1),
(3, '恒常ガチャ', 'standard', NULL, NULL);
