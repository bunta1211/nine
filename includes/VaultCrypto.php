<?php
/**
 * 金庫データの AES-256-GCM 暗号化／復号
 * VAULT_MASTER_KEY + user_id から鍵を導出し、payload を暗号化する
 */
class VaultCrypto {
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LEN = 16;
    private const KEY_BYTES = 32;

    /**
     * ユーザーごとの暗号化鍵を導出
     */
    public static function getEncryptionKey(int $userId): string {
        $master = defined('VAULT_MASTER_KEY') ? VAULT_MASTER_KEY : '';
        if ($master === '') {
            throw new RuntimeException('VAULT_MASTER_KEY が設定されていません');
        }
        $raw = hash_hmac('sha256', 'vault:' . $userId, $master, true);
        return substr($raw, 0, self::KEY_BYTES);
    }

    /**
     * 平文を暗号化
     * @return array{iv: string, cipher: string} IV（12バイト=24hex）と暗号文+タグ（hex）
     */
    public static function encrypt(string $plaintext, int $userId): array {
        $key = self::getEncryptionKey($userId);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );
        if ($cipher === false) {
            throw new RuntimeException('金庫の暗号化に失敗しました');
        }
        return [
            'iv' => bin2hex($iv),
            'cipher' => bin2hex($cipher . $tag),
        ];
    }

    /**
     * 暗号文を復号
     * @param string $cipherHex 暗号文+タグ（hex）
     * @param string $ivHex IV（24文字 hex）
     */
    public static function decrypt(string $cipherHex, string $ivHex, int $userId): string {
        $key = self::getEncryptionKey($userId);
        $iv = hex2bin($ivHex);
        $cipherTag = hex2bin($cipherHex);
        if ($iv === false || strlen($iv) !== 12 || $cipherTag === false || strlen($cipherTag) < self::TAG_LEN) {
            throw new RuntimeException('金庫データが不正です');
        }
        $tag = substr($cipherTag, -self::TAG_LEN);
        $cipher = substr($cipherTag, 0, -self::TAG_LEN);
        $plain = openssl_decrypt(
            $cipher,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new RuntimeException('金庫の復号に失敗しました');
        }
        return $plain;
    }
}
