<?php
/**
 * 全銀データ (Zengin Format) ヘルパー
 *
 * 口座振替データの生成（JIS全銀協フォーマット準拠）
 * レコード長: 120バイト固定（半角カナ・数字）
 */

require_once __DIR__ . '/../config/storage.php';

/**
 * 引落データ（全銀フォーマット）を生成する
 *
 * @param array $records 各レコードは [
 *   'bank_code'          => '9900',
 *   'bank_name_kana'     => 'ﾕｳﾁﾖ',
 *   'branch_code'        => '018',
 *   'branch_name_kana'   => 'ｾﾞﾛｲﾁﾊﾁ',
 *   'account_type'       => 1,
 *   'account_number'     => '1234567',
 *   'account_holder_kana'=> 'ﾔﾏﾀﾞ ﾀﾛｳ',
 *   'amount'             => 1500,
 * ]
 * @param string $transferDate MMDD形式
 * @return string 全銀データ文字列
 */
function generateZenginData(array $records, string $transferDate = ''): string {
    if (empty($transferDate)) {
        $transferDate = date('md', strtotime('+1 month'));
    }

    $lines = [];

    $lines[] = buildHeaderRecord($transferDate);

    $totalCount  = 0;
    $totalAmount = 0;
    foreach ($records as $rec) {
        $amount = (int) ($rec['amount'] ?? 0);
        if ($amount <= 0) continue;
        $lines[] = buildDataRecord($rec, $amount);
        $totalCount++;
        $totalAmount += $amount;
    }

    $lines[] = buildTrailerRecord($totalCount, $totalAmount);
    $lines[] = buildEndRecord();

    return implode("\r\n", $lines) . "\r\n";
}

function buildHeaderRecord(string $transferDate): string {
    $dataType    = '1';
    $codeType    = '21';
    $consignorCode = zenginPad(ZENGIN_CONSIGNOR_CODE, 10);
    $consignorName = zenginKana(ZENGIN_CONSIGNOR_NAME, 40);
    $tranDate    = zenginPad($transferDate, 4);
    $bankCode    = zenginPad(ZENGIN_BANK_CODE, 4);
    $bankName    = zenginKana(ZENGIN_BANK_NAME, 15);
    $branchCode  = zenginPad(ZENGIN_BRANCH_CODE, 3);
    $branchName  = zenginKana(ZENGIN_BRANCH_NAME, 15);
    $acctType    = zenginPad(ZENGIN_ACCOUNT_TYPE, 1);
    $acctNum     = zenginPad(ZENGIN_ACCOUNT_NUMBER, 7);

    $record = $dataType . $codeType . $consignorCode . $consignorName
            . $tranDate . $bankCode . $bankName . $branchCode . $branchName
            . $acctType . $acctNum;

    return str_pad($record, 120);
}

function buildDataRecord(array $rec, int $amount): string {
    $dataType     = '2';
    $bankCode     = zenginPad($rec['bank_code'] ?? '', 4);
    $bankName     = zenginKana($rec['bank_name_kana'] ?? '', 15);
    $branchCode   = zenginPad($rec['branch_code'] ?? '', 3);
    $branchName   = zenginKana($rec['branch_name_kana'] ?? '', 15);
    $dummy1       = '    ';
    $acctType     = zenginPad($rec['account_type'] ?? '1', 1);
    $acctNum      = zenginPad($rec['account_number'] ?? '', 7);
    $holderName   = zenginKana($rec['account_holder_kana'] ?? '', 30);
    $amountStr    = str_pad((string) $amount, 10, '0', STR_PAD_LEFT);
    $newCode      = '0';
    $customerNum  = zenginPad($rec['customer_number'] ?? '', 20);

    $record = $dataType . $bankCode . $bankName . $branchCode . $branchName
            . $dummy1 . $acctType . $acctNum . $holderName . $amountStr
            . $newCode . $customerNum;

    return str_pad($record, 120);
}

function buildTrailerRecord(int $count, int $totalAmount): string {
    $dataType = '8';
    $countStr = str_pad((string) $count, 6, '0', STR_PAD_LEFT);
    $amountStr = str_pad((string) $totalAmount, 12, '0', STR_PAD_LEFT);

    $record = $dataType . $countStr . $amountStr;
    return str_pad($record, 120);
}

function buildEndRecord(): string {
    return str_pad('9', 120);
}

/**
 * 半角カナ文字列を指定桁にパディング
 */
function zenginKana(string $str, int $len): string {
    $str = mb_convert_kana($str, 'kha', 'UTF-8');
    $str = mb_convert_encoding($str, 'SJIS', 'UTF-8');
    if (strlen($str) > $len) {
        $str = substr($str, 0, $len);
    }
    $str = str_pad($str, $len);
    return mb_convert_encoding($str, 'UTF-8', 'SJIS');
}

/**
 * 数字文字列を右寄せゼロ埋め
 */
function zenginPad(string $str, int $len): string {
    return str_pad(substr($str, 0, $len), $len, '0', STR_PAD_LEFT);
}
