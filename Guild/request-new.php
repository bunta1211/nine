<?php
/**
 * Guild 新規依頼作成ページ
 */

require_once __DIR__ . '/includes/common.php';
requireGuildLogin();

// 依頼停止期間チェック
if (isFreezeZPeriod()) {
    header('Location: requests.php');
    exit;
}

$pageTitle = __('new_request');
$extraCss = ['request-form.css'];
$extraJs = ['request-form.js'];

$pdo = getDB();
$userId = getGuildUserId();
$fiscalYear = getCurrentFiscalYear();

// 所属ギルドを取得
$userGuilds = getUserGuilds($userId);

// テンプレート一覧を取得
$stmt = $pdo->prepare("
    SELECT * FROM guild_request_templates 
    WHERE is_active = 1
    ORDER BY title ASC
");
$stmt->execute();
$templates = $stmt->fetchAll();

// ユーザーのEarth残高
$balance = getUserEarthBalance($userId, $fiscalYear);

require_once __DIR__ . '/templates/header.php';
?>

<div class="request-form-page">
    <div class="card">
        <div class="card-header">
            <h2><?= __('new_request') ?></h2>
        </div>
        <div class="card-body">
            <form id="request-form">
                <!-- 依頼種類 -->
                <div class="form-section">
                    <h3>依頼種類</h3>
                    <div class="request-type-grid">
                        <?php foreach (REQUEST_TYPES as $type => $info): ?>
                        <label class="type-option">
                            <input type="radio" name="request_type" value="<?= h($type) ?>"
                                   data-earth-source="<?= h($info['earth_source']) ?>"
                                   <?= $type === 'public' ? 'checked' : '' ?>>
                            <div class="type-card">
                                <div class="type-name"><?= h($info['name_ja']) ?></div>
                                <div class="type-source">
                                    <?= $info['earth_source'] === 'guild' ? 'ギルド予算' : '個人Earth' ?>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- テンプレート選択 -->
                <?php if (!empty($templates)): ?>
                <div class="form-section">
                    <h3>テンプレート（任意）</h3>
                    <select id="template-select" class="form-control">
                        <option value="">テンプレートを選択...</option>
                        <?php foreach ($templates as $tpl): ?>
                        <option value="<?= (int)$tpl['id'] ?>" 
                                data-title="<?= h($tpl['title']) ?>"
                                data-description="<?= h($tpl['description'] ?? '') ?>"
                                data-type="<?= h($tpl['request_type']) ?>"
                                data-earth="<?= (int)$tpl['default_earth'] ?>"
                                data-qualifications="<?= h($tpl['required_qualifications'] ?? '') ?>"
                                data-duration="<?= h($tpl['default_duration'] ?? '') ?>">
                            <?= h($tpl['title']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <!-- ギルド選択 -->
                <div class="form-section guild-section" id="guild-section">
                    <h3>ギルド</h3>
                    <select name="guild_id" id="guild-select" class="form-control" required>
                        <option value="">ギルドを選択...</option>
                        <?php foreach ($userGuilds as $guild): ?>
                        <option value="<?= (int)$guild['id'] ?>" 
                                data-budget="<?= (int)$guild['remaining_budget'] ?>"
                                data-role="<?= h($guild['role']) ?>">
                            <?= h($guild['name']) ?> 
                            (残予算: <?= number_format($guild['remaining_budget']) ?> Earth)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="budget-info" id="budget-info" style="display:none;">
                        残り予算: <strong id="remaining-budget">0</strong> Earth
                    </div>
                </div>
                
                <!-- 個人Earth表示 -->
                <div class="form-section personal-section" id="personal-section" style="display:none;">
                    <h3>個人Earth</h3>
                    <div class="balance-display">
                        保有Earth: <strong><?= number_format($balance['current_balance']) ?></strong> Earth
                    </div>
                </div>
                
                <!-- 基本情報 -->
                <div class="form-section">
                    <h3>依頼内容</h3>
                    
                    <div class="form-group">
                        <label for="title">タイトル <span class="required">*</span></label>
                        <input type="text" id="title" name="title" class="form-control" 
                               required maxlength="200" placeholder="依頼のタイトルを入力">
                    </div>
                    
                    <div class="form-group">
                        <label for="description">詳細説明</label>
                        <textarea id="description" name="description" class="form-control" 
                                  rows="5" placeholder="依頼の詳細を入力"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="earth_amount">報酬Earth額 <span class="required">*</span></label>
                        <div class="input-with-suffix">
                            <input type="number" id="earth_amount" name="earth_amount" 
                                   class="form-control" required min="1" value="100">
                            <span class="suffix">Earth</span>
                        </div>
                        <div class="form-help">
                            <span id="earth-yen">= ¥1,000</span>
                            <span id="large-request-warning" class="text-warning" style="display:none;">
                                ※10,000 Earth以上はシステム管理者の承認が必要です
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- 対象者（指名依頼・業務指令用） -->
                <div class="form-section target-section" id="target-section" style="display:none;">
                    <h3>対象者</h3>
                    <div id="target-users-container">
                        <button type="button" class="btn btn-secondary" id="add-target-btn">
                            ＋ 対象者を追加
                        </button>
                    </div>
                </div>
                
                <!-- 募集人数 -->
                <div class="form-section applicants-section" id="applicants-section">
                    <h3>募集人数</h3>
                    <div class="form-group">
                        <select name="max_applicants" class="form-control">
                            <option value="1">1人</option>
                            <option value="2">2人</option>
                            <option value="3">3人</option>
                            <option value="5">5人</option>
                            <option value="10">10人</option>
                            <option value="0">無制限</option>
                        </select>
                    </div>
                </div>
                
                <!-- 期限・タイミング -->
                <div class="form-section">
                    <h3>期限・タイミング</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="deadline">依頼期限</label>
                            <input type="date" id="deadline" name="deadline" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="distribution_timing">Earth分配タイミング</label>
                            <select name="distribution_timing" id="distribution_timing" class="form-control">
                                <option value="on_complete">完了時</option>
                                <option value="on_accept">受諾時</option>
                                <option value="on_date">期日指定</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group" id="distribution-date-group" style="display:none;">
                        <label for="distribution_date">分配日</label>
                        <input type="date" id="distribution_date" name="distribution_date" class="form-control">
                    </div>
                </div>
                
                <!-- 受注資格 -->
                <div class="form-section">
                    <h3>受注資格（任意）</h3>
                    <div class="form-group">
                        <textarea id="required_qualifications" name="required_qualifications" 
                                  class="form-control" rows="3" 
                                  placeholder="例：保育士資格必須、調理経験者"></textarea>
                    </div>
                </div>
                
                <!-- 勤務交代専用 -->
                <div class="form-section shift-section" id="shift-section" style="display:none;">
                    <h3>勤務交代情報</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="shift_date">交代対象日</label>
                            <input type="date" id="shift_date" name="shift_date" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label for="shift_time">勤務時間帯</label>
                            <input type="text" id="shift_time" name="shift_time" class="form-control"
                                   placeholder="例：9:00-18:00">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>交代者が見つからない場合</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="on_not_found" value="cancel" checked>
                                自動キャンセル
                            </label>
                            <label>
                                <input type="radio" name="on_not_found" value="extend">
                                期限延長
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- テンプレート保存オプション -->
                <div class="form-section">
                    <label class="checkbox-label">
                        <input type="checkbox" name="save_as_template" value="1">
                        この依頼をテンプレートとして保存する
                    </label>
                </div>
                
                <!-- 送信ボタン -->
                <div class="form-actions">
                    <a href="requests.php" class="btn btn-secondary"><?= __('cancel') ?></a>
                    <button type="submit" class="btn btn-primary" id="submit-btn">
                        依頼を作成
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const USER_BALANCE = <?= (int)$balance['current_balance'] ?>;
const LARGE_REQUEST_THRESHOLD = <?= LARGE_REQUEST_THRESHOLD ?>;
const EARTH_TO_YEN = <?= EARTH_TO_YEN ?>;
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
