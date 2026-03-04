-- ユーザーアバターのスタイル・位置・サイズカラム追加
-- 実行日: 2026-01-28
-- 目的: ユーザーアバターの背景色・枠線スタイル、位置（X/Y座標）、サイズを保存するためのカラムを追加

-- usersテーブルにavatar_styleカラムを追加
ALTER TABLE users 
ADD COLUMN avatar_style VARCHAR(50) DEFAULT 'default' AFTER avatar_path;

-- usersテーブルにavatar_pos_xカラムを追加（X座標、-50〜50%）
ALTER TABLE users 
ADD COLUMN avatar_pos_x FLOAT DEFAULT 0 AFTER avatar_style;

-- usersテーブルにavatar_pos_yカラムを追加（Y座標、-50〜50%）
ALTER TABLE users 
ADD COLUMN avatar_pos_y FLOAT DEFAULT 0 AFTER avatar_pos_x;

-- usersテーブルにavatar_sizeカラムを追加
ALTER TABLE users 
ADD COLUMN avatar_size INT DEFAULT 100 AFTER avatar_pos_y;

-- コメント: 利用可能なスタイル
-- default: デフォルト（紫グラデーション）
-- white: 白
-- black: 黒
-- gray: グレー
-- red: 赤
-- orange: オレンジ
-- yellow: 黄色
-- green: 緑
-- blue: 青
-- purple: 紫
-- pink: ピンク

-- コメント: 位置（X/Y座標）
-- -50〜50の範囲（0が中央）
-- 1回の移動で7.5%ずつ移動

-- コメント: サイズ
-- 50〜150（100がデフォルト）
