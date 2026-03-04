-- ============================================
-- conversations に organization_id を追加（左パネル組織フィルタ用）
-- ============================================
-- 既に migration_to_phase1.sql を実行済みの場合は不要。
--
-- MySQL 8.0.12 以降: このファイルをそのまま実行可能。
-- MySQL 5.7 等で IF NOT EXISTS が使えない場合、以下を1回だけ実行（既にカラムがあるとエラーになります）:
--   ALTER TABLE conversations ADD COLUMN organization_id INT UNSIGNED NULL COMMENT '組織ID' AFTER icon_path;
-- ============================================

-- MySQL 8.0.12 以降: カラムが無い場合のみ追加
ALTER TABLE conversations
    ADD COLUMN IF NOT EXISTS `organization_id` INT UNSIGNED NULL COMMENT '組織に紐づくグループの場合の組織ID' AFTER `icon_path`;

-- インデックス（既にある場合はエラーになるため、必要に応じて手動で実行）
-- ALTER TABLE conversations ADD INDEX idx_organization (organization_id);
