<?php
/**
 * バリデーションクラス
 * 
 * 使用例:
 * $v = new Validator($input);
 * $v->required('email', 'メールアドレス')
 *   ->email('email')
 *   ->required('password', 'パスワード')
 *   ->minLength('password', 8, 'パスワード');
 * 
 * if (!$v->isValid()) {
 *     errorResponse('入力エラー', 400, ['errors' => $v->getErrors()]);
 * }
 */
class Validator {
    private $data = [];
    private $errors = [];
    
    /**
     * コンストラクタ
     * @param array $data バリデーション対象のデータ
     */
    public function __construct(array $data = []) {
        $this->data = $data;
    }
    
    /**
     * データをセット
     * @param array $data
     * @return $this
     */
    public function setData(array $data) {
        $this->data = $data;
        return $this;
    }
    
    /**
     * 必須チェック
     * @param string $field フィールド名
     * @param string|null $label 表示名
     * @return $this
     */
    public function required($field, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->addError($field, "{$label}は必須です");
        }
        
        return $this;
    }
    
    /**
     * メールアドレス形式チェック
     * @param string $field
     * @param string|null $label
     * @return $this
     */
    public function email($field, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: 'メールアドレス';
        
        if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "有効な{$label}を入力してください");
        }
        
        return $this;
    }
    
    /**
     * 最小文字数チェック
     * @param string $field
     * @param int $min
     * @param string|null $label
     * @return $this
     */
    public function minLength($field, $min, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value && mb_strlen($value) < $min) {
            $this->addError($field, "{$label}は{$min}文字以上で入力してください");
        }
        
        return $this;
    }
    
    /**
     * 最大文字数チェック
     * @param string $field
     * @param int $max
     * @param string|null $label
     * @return $this
     */
    public function maxLength($field, $max, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value && mb_strlen($value) > $max) {
            $this->addError($field, "{$label}は{$max}文字以内で入力してください");
        }
        
        return $this;
    }
    
    /**
     * 数値チェック
     * @param string $field
     * @param string|null $label
     * @return $this
     */
    public function numeric($field, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            $this->addError($field, "{$label}は数値で入力してください");
        }
        
        return $this;
    }
    
    /**
     * 整数チェック
     * @param string $field
     * @param string|null $label
     * @return $this
     */
    public function integer($field, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_INT)) {
            $this->addError($field, "{$label}は整数で入力してください");
        }
        
        return $this;
    }
    
    /**
     * 範囲チェック（数値）
     * @param string $field
     * @param int|float $min
     * @param int|float $max
     * @param string|null $label
     * @return $this
     */
    public function between($field, $min, $max, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value !== null && $value !== '' && is_numeric($value)) {
            if ($value < $min || $value > $max) {
                $this->addError($field, "{$label}は{$min}から{$max}の間で入力してください");
            }
        }
        
        return $this;
    }
    
    /**
     * 許可値リストチェック
     * @param string $field
     * @param array $allowed
     * @param string|null $label
     * @return $this
     */
    public function in($field, array $allowed, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
            $this->addError($field, "{$label}の値が不正です");
        }
        
        return $this;
    }
    
    /**
     * 正規表現チェック
     * @param string $field
     * @param string $pattern
     * @param string $message
     * @return $this
     */
    public function regex($field, $pattern, $message) {
        $value = $this->getValue($field);
        
        if ($value && !preg_match($pattern, $value)) {
            $this->addError($field, $message);
        }
        
        return $this;
    }
    
    /**
     * 日付形式チェック
     * @param string $field
     * @param string $format
     * @param string|null $label
     * @return $this
     */
    public function date($field, $format = 'Y-m-d', $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value) {
            $d = DateTime::createFromFormat($format, $value);
            if (!$d || $d->format($format) !== $value) {
                $this->addError($field, "{$label}は有効な日付形式で入力してください");
            }
        }
        
        return $this;
    }
    
    /**
     * 時間形式チェック
     * @param string $field
     * @param string|null $label
     * @return $this
     */
    public function time($field, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $value)) {
            $this->addError($field, "{$label}は有効な時間形式（HH:MM）で入力してください");
        }
        
        return $this;
    }
    
    /**
     * 配列チェック
     * @param string $field
     * @param string|null $label
     * @return $this
     */
    public function isArray($field, $label = null) {
        $value = $this->getValue($field);
        $label = $label ?: $field;
        
        if ($value !== null && !is_array($value)) {
            $this->addError($field, "{$label}は配列で指定してください");
        }
        
        return $this;
    }
    
    /**
     * 確認用フィールドとの一致チェック
     * @param string $field
     * @param string $confirmField
     * @param string|null $label
     * @return $this
     */
    public function confirmed($field, $confirmField, $label = null) {
        $value = $this->getValue($field);
        $confirmValue = $this->getValue($confirmField);
        $label = $label ?: $field;
        
        if ($value !== $confirmValue) {
            $this->addError($field, "{$label}が一致しません");
        }
        
        return $this;
    }
    
    /**
     * カスタムバリデーション
     * @param string $field
     * @param callable $callback function($value): bool
     * @param string $message
     * @return $this
     */
    public function custom($field, callable $callback, $message) {
        $value = $this->getValue($field);
        
        if (!$callback($value)) {
            $this->addError($field, $message);
        }
        
        return $this;
    }
    
    /**
     * バリデーション結果が有効か
     * @return bool
     */
    public function isValid() {
        return empty($this->errors);
    }
    
    /**
     * エラーメッセージを取得
     * @return array
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * 最初のエラーメッセージを取得
     * @return string|null
     */
    public function getFirstError() {
        if (empty($this->errors)) return null;
        
        $first = reset($this->errors);
        return is_array($first) ? reset($first) : $first;
    }
    
    /**
     * エラーをクリア
     * @return $this
     */
    public function clearErrors() {
        $this->errors = [];
        return $this;
    }
    
    /**
     * フィールドの値を取得
     * @param string $field
     * @return mixed
     */
    private function getValue($field) {
        return $this->data[$field] ?? null;
    }
    
    /**
     * エラーを追加
     * @param string $field
     * @param string $message
     */
    private function addError($field, $message) {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }
}


