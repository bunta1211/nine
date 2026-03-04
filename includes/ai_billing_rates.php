<?php
/**
 * AI利用料金表・その他サービス料金表の算出と表示
 *
 * 弊社コストに AI_BILLING_MARKUP_RATE（20%乗せ）をかけた請求単価を表示する。
 * 呼び出し元で config/ai_config.php を require しておくこと。
 */

/**
 * AI利用料金の行リストを返す（種別・単位・弊社コスト・請求単価）
 *
 * @return array<int, array{label: string, unit: string, cost_jpy: int|float, price_jpy: int|float}>
 */
function getAiBillingRates(): array {
    $rate = defined('AI_BILLING_MARKUP_RATE') ? (float) AI_BILLING_MARKUP_RATE : 1.2;
    $usdJpy = defined('USD_TO_JPY_RATE') ? (float) USD_TO_JPY_RATE : 154;
    $inputPer1M = defined('OPENAI_INPUT_COST_PER_1M') ? (float) OPENAI_INPUT_COST_PER_1M : 2.5;
    $outputPer1M = defined('OPENAI_OUTPUT_COST_PER_1M') ? (float) OPENAI_OUTPUT_COST_PER_1M : 10.0;

    $chatCost = defined('AI_CHAT_COST_JPY_PER_1K_CHARS') ? (float) AI_CHAT_COST_JPY_PER_1K_CHARS : 0;
    $taskCost = defined('AI_TASK_MEMO_SEARCH_COST_JPY_PER_REQUEST') ? (float) AI_TASK_MEMO_SEARCH_COST_JPY_PER_REQUEST : 0;

    $transInputCostJpy = (int) round($inputPer1M * $usdJpy);
    $transOutputCostJpy = (int) round($outputPer1M * $usdJpy);

    $rows = [
        [
            'label' => 'AI秘書チャット',
            'unit'  => '1,000文字あたり',
            'cost_jpy'  => $chatCost,
            'price_jpy' => (int) round($chatCost * $rate),
        ],
        [
            'label' => '翻訳（入力）',
            'unit'  => '100万トークンあたり',
            'cost_jpy'  => $transInputCostJpy,
            'price_jpy' => (int) round($transInputCostJpy * $rate),
        ],
        [
            'label' => '翻訳（出力）',
            'unit'  => '100万トークンあたり',
            'cost_jpy'  => $transOutputCostJpy,
            'price_jpy' => (int) round($transOutputCostJpy * $rate),
        ],
        [
            'label' => 'タスク・メモ検索',
            'unit'  => '1回あたり',
            'cost_jpy'  => $taskCost,
            'price_jpy' => (int) round($taskCost * $rate),
        ],
    ];
    return $rows;
}

/**
 * その他サービス（SMS・メール・Places API）の料金行。単価が定義されていて > 0 のもののみ返す。
 *
 * @return array<int, array{label: string, unit: string, cost_jpy: int|float, price_jpy: int|float}>
 */
function getOtherServiceRates(): array {
    if (!defined('AI_BILLING_MARKUP_RATE')) {
        return [];
    }
    $rate = (float) AI_BILLING_MARKUP_RATE;
    $rows = [];

    if (defined('SMS_COST_JPY_PER_MESSAGE') && (float) SMS_COST_JPY_PER_MESSAGE > 0) {
        $c = (float) SMS_COST_JPY_PER_MESSAGE;
        $rows[] = [
            'label' => 'SMS送信',
            'unit'  => '1通あたり',
            'cost_jpy'  => $c,
            'price_jpy' => (int) round($c * $rate),
        ];
    }
    if (defined('MAIL_COST_JPY_PER_MESSAGE') && (float) MAIL_COST_JPY_PER_MESSAGE > 0) {
        $c = (float) MAIL_COST_JPY_PER_MESSAGE;
        $rows[] = [
            'label' => 'メール送信',
            'unit'  => '1通あたり',
            'cost_jpy'  => $c,
            'price_jpy' => (int) round($c * $rate),
        ];
    }
    if (defined('PLACES_API_COST_JPY_PER_REQUEST') && (float) PLACES_API_COST_JPY_PER_REQUEST > 0) {
        $c = (float) PLACES_API_COST_JPY_PER_REQUEST;
        $rows[] = [
            'label' => 'Places API（お店検索）',
            'unit'  => '1リクエストあたり',
            'cost_jpy'  => $c,
            'price_jpy' => (int) round($c * $rate),
        ];
    }
    return $rows;
}

/**
 * AI利用料金表のテーブルHTMLを出力する
 *
 * @param string $tableClass テーブルに付与するCSSクラス（例: sb-table）
 */
function renderAiBillingTable(string $tableClass = 'sb-table'): void {
    $rows = getAiBillingRates();
    $markup = defined('AI_BILLING_MARKUP_RATE') ? (float) AI_BILLING_MARKUP_RATE : 1.2;
    ?>
    <table class="<?= htmlspecialchars($tableClass) ?>">
        <thead>
            <tr>
                <th>種別</th>
                <th>単位</th>
                <th>弊社コスト（円）</th>
                <th>請求単価（税込・円）</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td><?= htmlspecialchars($r['unit']) ?></td>
                <td><?= $r['cost_jpy'] > 0 ? '¥' . number_format($r['cost_jpy']) : '要設定' ?></td>
                <td>¥<?= number_format($r['price_jpy']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin:8px 0 0;font-size:12px;color:#6b7280;">請求単価 = 弊社コスト × <?= $markup ?>（20%乗せ）</p>
    <?php
}

/**
 * その他サービス料金表のテーブルHTMLを出力する（行がある場合のみ）
 *
 * @param string $tableClass テーブルに付与するCSSクラス
 * @return bool 表示した場合 true
 */
function renderOtherServiceTable(string $tableClass = 'sb-table'): bool {
    $rows = getOtherServiceRates();
    if (empty($rows)) {
        return false;
    }
    $markup = defined('AI_BILLING_MARKUP_RATE') ? (float) AI_BILLING_MARKUP_RATE : 1.2;
    ?>
    <table class="<?= htmlspecialchars($tableClass) ?>">
        <thead>
            <tr>
                <th>種別</th>
                <th>単位</th>
                <th>弊社コスト（円）</th>
                <th>請求単価（税込・円）</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['label']) ?></td>
                <td><?= htmlspecialchars($r['unit']) ?></td>
                <td>¥<?= number_format($r['cost_jpy']) ?></td>
                <td>¥<?= number_format($r['price_jpy']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="margin:8px 0 0;font-size:12px;color:#6b7280;">請求単価 = 弊社コスト × <?= $markup ?></p>
    <?php
    return true;
}
