-- グループ招待トークン用カラム追加

-- conversationsテーブルにinvite_tokenカラムを追加
ALTER TABLE conversations ADD COLUMN invite_token VARCHAR(64) NULL AFTER invite_code;
ALTER TABLE conversations ADD INDEX idx_invite_token (invite_token);
