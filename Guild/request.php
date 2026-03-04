<?php
/**
 * Guild 依頼詳細ページ
 */

require_once __DIR__ . '/includes/common.php';
requireGuildLogin();

$requestId = (int)($_GET['id'] ?? 0);
if (!$requestId) {
    header('Location: requests.php');
    exit;
}

$pdo = getDB();
$userId = getGuildUserId();

// 依頼情報を取得
$stmt = $pdo->prepare("
    SELECT r.*, g.name as guild_name, g.logo_path as guild_logo,
           u.display_name as requester_name, u.avatar as requester_avatar
    FROM guild_requests r
    LEFT JOIN guild_guilds g ON r.guild_id = g.id
    LEFT JOIN users u ON r.requester_id = u.id
    WHERE r.id = ?
");
$stmt->execute([$requestId]);
$request = $stmt->fetch();

if (!$request) {
    header('Location: requests.php');
    exit;
}

$pageTitle = $request['title'];
$extraCss = ['request-detail.css'];
$extraJs = ['request-detail.js'];

// 立候補者一覧
$stmt = $pdo->prepare("
    SELECT a.*, u.display_name, u.avatar
    FROM guild_request_applications a
    INNER JOIN users u ON a.user_id = u.id
    WHERE a.request_id = ?
    ORDER BY a.created_at ASC
");
$stmt->execute([$requestId]);
$applications = $stmt->fetchAll();

// 担当者一覧
$stmt = $pdo->prepare("
    SELECT ra.*, u.display_name, u.avatar
    FROM guild_request_assignees ra
    INNER JOIN users u ON ra.user_id = u.id
    WHERE ra.request_id = ?
    ORDER BY ra.created_at ASC
");
$stmt->execute([$requestId]);
$assignees = $stmt->fetchAll();

// ユーザーの状態を判定
$isRequester = $request['requester_id'] == $userId;
$hasApplied = false;
$myApplication = null;
$isAssigned = false;
$myAssignment = null;

foreach ($applications as $app) {
    if ($app['user_id'] == $userId) {
        $hasApplied = true;
        $myApplication = $app;
        break;
    }
}

foreach ($assignees as $assignee) {
    if ($assignee['user_id'] == $userId) {
        $isAssigned = true;
        $myAssignment = $assignee;
        break;
    }
}

// ギルドでの役割
$guildRole = null;
if ($request['guild_id']) {
    $guildRole = getUserGuildRole($userId, $request['guild_id']);
}

// 編集権限
$canEdit = $isRequester || isGuildSystemAdmin();

// 承認権限（勤務交代用）
$canApproveShift = $request['request_type'] === 'shift_swap' && 
                   $guildRole && 
                   in_array($guildRole['role'], ['leader', 'sub_leader']);

require_once __DIR__ . '/templates/header.php';
?>

<div class="request-detail-page">
    <div class="request-detail-grid">
        <!-- メインコンテンツ -->
        <div class="request-main">
            <div class="card">
                <div class="card-body">
                    <!-- ヘッダー -->
                    <div class="request-detail-header">
                        <div class="request-badges">
                            <span class="request-type type-<?= h($request['request_type']) ?>">
                                <?= h(REQUEST_TYPES[$request['request_type']]['name_ja'] ?? $request['request_type']) ?>
                            </span>
                            <span class="request-status status-<?= h($request['status']) ?>">
                                <?php
                                $statusLabels = [
                                    'open' => '募集中',
                                    'in_progress' => '進行中',
                                    'pending_complete' => '完了待ち',
                                    'completed' => '完了',
                                    'cancelled' => 'キャンセル',
                                    'expired' => '期限切れ',
                                ];
                                echo $statusLabels[$request['status']] ?? $request['status'];
                                ?>
                            </span>
                        </div>
                        
                        <?php if ($canEdit && $request['status'] === 'open'): ?>
                        <div class="request-actions">
                            <a href="request-edit.php?id=<?= $requestId ?>" class="btn btn-secondary btn-sm">
                                <?= __('edit') ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <h1 class="request-detail-title"><?= h($request['title']) ?></h1>
                    
                    <!-- Earth報酬 -->
                    <div class="request-earth-display">
                        <span class="earth-icon">🌍</span>
                        <span class="earth-amount"><?= number_format($request['earth_amount']) ?></span>
                        <span class="earth-unit">Earth</span>
                        <span class="earth-yen">(<?= formatYen(earthToYen($request['earth_amount'])) ?>)</span>
                    </div>
                    
                    <!-- 詳細情報 -->
                    <div class="request-info-grid">
                        <div class="info-item">
                            <div class="info-label">ギルド</div>
                            <div class="info-value">
                                <?php if ($request['guild_logo']): ?>
                                <img src="<?= h($request['guild_logo']) ?>" alt="" class="guild-mini-logo">
                                <?php endif; ?>
                                <?= h($request['guild_name'] ?? '個人') ?>
                            </div>
                        </div>
                        
                        <div class="info-item">
                            <div class="info-label">依頼者</div>
                            <div class="info-value">
                                <?php if ($request['requester_avatar']): ?>
                                <img src="<?= h($request['requester_avatar']) ?>" alt="" class="user-mini-avatar">
                                <?php endif; ?>
                                <?= h($request['requester_name']) ?>
                            </div>
                        </div>
                        
                        <?php if ($request['deadline']): ?>
                        <div class="info-item">
                            <div class="info-label">期限</div>
                            <div class="info-value"><?= formatDate($request['deadline']) ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-item">
                            <div class="info-label">分配タイミング</div>
                            <div class="info-value">
                                <?php
                                $timingLabels = [
                                    'on_accept' => '受諾時',
                                    'on_date' => '期日指定',
                                    'on_complete' => '完了時',
                                ];
                                echo $timingLabels[$request['distribution_timing']] ?? $request['distribution_timing'];
                                if ($request['distribution_timing'] === 'on_date' && $request['distribution_date']) {
                                    echo ' (' . formatDate($request['distribution_date']) . ')';
                                }
                                ?>
                            </div>
                        </div>
                        
                        <?php if ($request['max_applicants'] > 1): ?>
                        <div class="info-item">
                            <div class="info-label">募集人数</div>
                            <div class="info-value">
                                <?= count($assignees) ?> / <?= $request['max_applicants'] == 0 ? '無制限' : $request['max_applicants'] ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- 説明 -->
                    <?php if ($request['description']): ?>
                    <div class="request-description">
                        <h3>詳細</h3>
                        <div class="description-content">
                            <?= nl2br(h($request['description'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- 受注資格 -->
                    <?php if ($request['required_qualifications']): ?>
                    <div class="request-qualifications">
                        <h3>受注資格</h3>
                        <div class="qualifications-content">
                            <?= nl2br(h($request['required_qualifications'])) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 立候補者リスト（依頼者・管理者のみ） -->
            <?php if (($isRequester || isGuildSystemAdmin()) && !empty($applications)): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h3><?= __('applicants') ?> (<?= count($applications) ?>)</h3>
                </div>
                <div class="card-body">
                    <div class="applicants-list">
                        <?php foreach ($applications as $app): ?>
                        <div class="applicant-item" data-id="<?= (int)$app['id'] ?>">
                            <div class="applicant-avatar">
                                <?php if ($app['avatar']): ?>
                                <img src="<?= h($app['avatar']) ?>" alt="">
                                <?php else: ?>
                                <span class="avatar-placeholder"><?= mb_substr($app['display_name'], 0, 1) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="applicant-info">
                                <div class="applicant-name"><?= h($app['display_name']) ?></div>
                                <?php if ($app['comment']): ?>
                                <div class="applicant-comment"><?= h($app['comment']) ?></div>
                                <?php endif; ?>
                                <div class="applicant-date"><?= formatDateTime($app['created_at']) ?></div>
                            </div>
                            <div class="applicant-status">
                                <?php if ($app['status'] === 'pending'): ?>
                                <button class="btn btn-success btn-sm accept-applicant-btn" 
                                        data-id="<?= (int)$app['id'] ?>">
                                    選定
                                </button>
                                <button class="btn btn-secondary btn-sm reject-applicant-btn" 
                                        data-id="<?= (int)$app['id'] ?>">
                                    見送り
                                </button>
                                <?php elseif ($app['status'] === 'accepted'): ?>
                                <span class="badge badge-success">選定済み</span>
                                <?php else: ?>
                                <span class="badge badge-danger">見送り</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- 担当者リスト -->
            <?php if (!empty($assignees)): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h3><?= __('assignees') ?> (<?= count($assignees) ?>)</h3>
                </div>
                <div class="card-body">
                    <div class="assignees-list">
                        <?php foreach ($assignees as $assignee): ?>
                        <div class="assignee-item">
                            <div class="assignee-avatar">
                                <?php if ($assignee['avatar']): ?>
                                <img src="<?= h($assignee['avatar']) ?>" alt="">
                                <?php else: ?>
                                <span class="avatar-placeholder"><?= mb_substr($assignee['display_name'], 0, 1) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="assignee-info">
                                <div class="assignee-name"><?= h($assignee['display_name']) ?></div>
                                <div class="assignee-earth"><?= formatEarth($assignee['earth_amount']) ?></div>
                            </div>
                            <div class="assignee-status">
                                <?php
                                $statusLabels = [
                                    'assigned' => '未着手',
                                    'in_progress' => '進行中',
                                    'completed' => '完了',
                                ];
                                $statusClass = [
                                    'assigned' => 'info',
                                    'in_progress' => 'warning',
                                    'completed' => 'success',
                                ];
                                ?>
                                <span class="badge badge-<?= $statusClass[$assignee['status']] ?? 'info' ?>">
                                    <?= $statusLabels[$assignee['status']] ?? $assignee['status'] ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- サイドバー -->
        <div class="request-sidebar">
            <!-- アクションカード -->
            <div class="card action-card">
                <div class="card-body">
                    <?php if ($request['status'] === 'open' && !$isRequester): ?>
                        <?php if ($hasApplied): ?>
                            <?php if ($myApplication['status'] === 'pending'): ?>
                            <p class="action-message">立候補済みです</p>
                            <button class="btn btn-danger btn-block withdraw-btn" data-id="<?= (int)$myApplication['id'] ?>">
                                立候補を取り消す
                            </button>
                            <?php elseif ($myApplication['status'] === 'accepted'): ?>
                            <p class="action-message success">立候補が承認されました！</p>
                            <?php endif; ?>
                        <?php elseif ($isAssigned): ?>
                            <p class="action-message success">この依頼を担当しています</p>
                            <?php if ($myAssignment['status'] === 'assigned'): ?>
                            <button class="btn btn-primary btn-block start-work-btn">
                                作業を開始
                            </button>
                            <?php elseif ($myAssignment['status'] === 'in_progress'): ?>
                            <button class="btn btn-success btn-block complete-work-btn">
                                完了報告
                            </button>
                            <?php endif; ?>
                        <?php elseif ($request['request_type'] === 'public'): ?>
                            <button class="btn btn-primary btn-block apply-btn" id="apply-btn">
                                <?= __('apply') ?>
                            </button>
                        <?php elseif (in_array($request['request_type'], ['designated', 'order'])): ?>
                            <button class="btn btn-primary btn-block accept-btn">
                                <?= __('accept') ?>
                            </button>
                            <?php if ($request['request_type'] !== 'order'): ?>
                            <button class="btn btn-secondary btn-block mt-2 decline-btn">
                                <?= __('decline') ?>
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php elseif ($isRequester && $request['status'] === 'open'): ?>
                        <p class="action-message">あなたが作成した依頼です</p>
                        <button class="btn btn-danger btn-block cancel-request-btn">
                            依頼をキャンセル
                        </button>
                    <?php elseif ($isRequester && $request['status'] === 'pending_complete'): ?>
                        <p class="action-message">完了報告を確認してください</p>
                        <button class="btn btn-success btn-block approve-complete-btn">
                            <?= __('approve_completion') ?>
                        </button>
                    <?php elseif ($request['status'] === 'completed'): ?>
                        <p class="action-message success">この依頼は完了しています</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 感謝送信（完了後） -->
            <?php if ($request['status'] === 'completed' && !$isRequester): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <h3>感謝を送る</h3>
                </div>
                <div class="card-body">
                    <form id="thanks-form">
                        <input type="hidden" name="request_id" value="<?= $requestId ?>">
                        <div class="form-group">
                            <label>メッセージ（任意）</label>
                            <textarea name="message" class="form-control" rows="3" 
                                      placeholder="ありがとうございました！"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">
                            💝 感謝を送る
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 立候補モーダル -->
<div class="modal-backdrop" id="apply-modal-backdrop"></div>
<div class="modal" id="apply-modal" style="width: 400px;">
    <div class="modal-header">
        <h3 class="modal-title">立候補</h3>
        <button class="modal-close" data-close>&times;</button>
    </div>
    <form id="apply-form">
        <div class="modal-body">
            <input type="hidden" name="request_id" value="<?= $requestId ?>">
            <div class="form-group">
                <label>コメント（任意）</label>
                <textarea name="comment" class="form-control" rows="3" 
                          placeholder="対応可能な日時や条件などがあればご記入ください"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close><?= __('cancel') ?></button>
            <button type="submit" class="btn btn-primary"><?= __('apply') ?></button>
        </div>
    </form>
</div>

<script>
const REQUEST_ID = <?= $requestId ?>;
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
