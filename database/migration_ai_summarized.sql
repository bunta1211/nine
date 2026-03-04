-- AI要約機能のためのマイグレーション
-- tasksテーブルのsourceカラムにai_summarizedを追加

-- sourceカラムを拡張（ENUM → VARCHAR）
-- ENUMの拡張は互換性の問題があるため、VARCHARに変更
ALTER TABLE tasks 
    MODIFY COLUMN source VARCHAR(20) DEFAULT 'manual';

-- 確認
SELECT DISTINCT source FROM tasks;


