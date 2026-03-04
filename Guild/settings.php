<?php
/**
 * Guild 設定ページ
 */

require_once __DIR__ . '/includes/common.php';

$pageTitle = __('settings');

require_once __DIR__ . '/templates/header.php';

$currentUser = getCurrentUser();
?>

<div class="page-header">
    <h1 class="page-title"><?= __('settings') ?></h1>
</div>

<div class="settings-container">
    <!-- プロフィール設定 -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">プロフィール</h2>
        </div>
        <div class="card-body">
            <form id="profile-form">
                <div class="form-group">
                    <label>入社日</label>
                    <input type="date" name="hire_date" class="form-input" 
                           value="<?= h($currentUser['hire_date'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>資格</label>
                    <textarea name="qualifications" class="form-textarea" rows="3"
                              placeholder="保有資格を入力"><?= h($currentUser['qualifications'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>技能</label>
                    <textarea name="skills" class="form-textarea" rows="3"
                              placeholder="得意な業務やスキルを入力"><?= h($currentUser['skills'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>講師可能レッスン</label>
                    <textarea name="teachable_lessons" class="form-textarea" rows="3"
                              placeholder="講師として担当可能なレッスンを入力"><?= h($currentUser['teachable_lessons'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>
    
    <!-- 受付状況設定 -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">依頼受付状況</h2>
        </div>
        <div class="card-body">
            <form id="availability-form">
                <div class="availability-grid">
                    <div class="availability-item">
                        <label>本日</label>
                        <select name="availability_today" class="form-select">
                            <option value="available" <?= ($currentUser['availability_today'] ?? '') === 'available' ? 'selected' : '' ?>>受付中</option>
                            <option value="limited" <?= ($currentUser['availability_today'] ?? '') === 'limited' ? 'selected' : '' ?>>余裕あり</option>
                            <option value="unavailable" <?= ($currentUser['availability_today'] ?? '') === 'unavailable' ? 'selected' : '' ?>>不可</option>
                        </select>
                        <input type="number" name="availability_today_percent" class="form-input" 
                               min="0" max="100" value="<?= (int)($currentUser['availability_today_percent'] ?? 100) ?>">%
                    </div>
                    <div class="availability-item">
                        <label>今週</label>
                        <select name="availability_week" class="form-select">
                            <option value="available" <?= ($currentUser['availability_week'] ?? '') === 'available' ? 'selected' : '' ?>>受付中</option>
                            <option value="limited" <?= ($currentUser['availability_week'] ?? '') === 'limited' ? 'selected' : '' ?>>余裕あり</option>
                            <option value="unavailable" <?= ($currentUser['availability_week'] ?? '') === 'unavailable' ? 'selected' : '' ?>>不可</option>
                        </select>
                        <input type="number" name="availability_week_percent" class="form-input"
                               min="0" max="100" value="<?= (int)($currentUser['availability_week_percent'] ?? 100) ?>">%
                    </div>
                    <div class="availability-item">
                        <label>今月</label>
                        <select name="availability_month" class="form-select">
                            <option value="available" <?= ($currentUser['availability_month'] ?? '') === 'available' ? 'selected' : '' ?>>受付中</option>
                            <option value="limited" <?= ($currentUser['availability_month'] ?? '') === 'limited' ? 'selected' : '' ?>>余裕あり</option>
                            <option value="unavailable" <?= ($currentUser['availability_month'] ?? '') === 'unavailable' ? 'selected' : '' ?>>不可</option>
                        </select>
                        <input type="number" name="availability_month_percent" class="form-input"
                               min="0" max="100" value="<?= (int)($currentUser['availability_month_percent'] ?? 100) ?>">%
                    </div>
                    <div class="availability-item">
                        <label>来月以降</label>
                        <select name="availability_next" class="form-select">
                            <option value="available" <?= ($currentUser['availability_next'] ?? '') === 'available' ? 'selected' : '' ?>>受付中</option>
                            <option value="limited" <?= ($currentUser['availability_next'] ?? '') === 'limited' ? 'selected' : '' ?>>余裕あり</option>
                            <option value="unavailable" <?= ($currentUser['availability_next'] ?? '') === 'unavailable' ? 'selected' : '' ?>>不可</option>
                        </select>
                        <input type="number" name="availability_next_percent" class="form-input"
                               min="0" max="100" value="<?= (int)($currentUser['availability_next_percent'] ?? 100) ?>">%
                    </div>
                </div>
                <div class="form-group">
                    <label>受付不可期間（終了日）</label>
                    <input type="date" name="unavailable_until" class="form-input"
                           value="<?= h($currentUser['unavailable_until'] ?? '') ?>">
                </div>
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>
    
    <!-- 通知設定 -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">通知設定</h2>
        </div>
        <div class="card-body">
            <form id="notification-form">
                <div class="toggle-group">
                    <label class="toggle-item">
                        <input type="checkbox" name="notify_new_request" 
                               <?= ($currentUser['notify_new_request'] ?? 1) ? 'checked' : '' ?>>
                        <span>新着依頼</span>
                    </label>
                    <label class="toggle-item">
                        <input type="checkbox" name="notify_assigned"
                               <?= ($currentUser['notify_assigned'] ?? 1) ? 'checked' : '' ?>>
                        <span>依頼への採用</span>
                    </label>
                    <label class="toggle-item">
                        <input type="checkbox" name="notify_approved"
                               <?= ($currentUser['notify_approved'] ?? 1) ? 'checked' : '' ?>>
                        <span>完了承認</span>
                    </label>
                    <label class="toggle-item">
                        <input type="checkbox" name="notify_earth_received"
                               <?= ($currentUser['notify_earth_received'] ?? 1) ? 'checked' : '' ?>>
                        <span>Earth受取</span>
                    </label>
                    <label class="toggle-item">
                        <input type="checkbox" name="notify_thanks"
                               <?= ($currentUser['notify_thanks'] ?? 1) ? 'checked' : '' ?>>
                        <span>感謝メッセージ</span>
                    </label>
                    <label class="toggle-item">
                        <input type="checkbox" name="email_notifications"
                               <?= ($currentUser['email_notifications'] ?? 1) ? 'checked' : '' ?>>
                        <span>メール通知 (18時)</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary">保存</button>
            </form>
        </div>
    </div>
    
    <!-- 表示設定 -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">表示設定</h2>
        </div>
        <div class="card-body">
            <div class="form-group">
                <label>言語</label>
                <select id="language-select" class="form-select" onchange="changeLanguage(this.value)">
                    <?php foreach (SUPPORTED_LANGUAGES as $code => $name): ?>
                    <option value="<?= $code ?>" <?= getCurrentLanguage() === $code ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>テーマ</label>
                <button type="button" class="btn btn-secondary" onclick="Guild.toggleTheme()">
                    ダークモード切り替え
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.settings-container {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-lg);
    max-width: 800px;
}

.availability-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--spacing-md);
    margin-bottom: var(--spacing-md);
}

.availability-item {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-xs);
}

.availability-item .form-input {
    width: 80px;
    display: inline-block;
}

.toggle-group {
    display: flex;
    flex-direction: column;
    gap: var(--spacing-sm);
    margin-bottom: var(--spacing-md);
}

.toggle-item {
    display: flex;
    align-items: center;
    gap: var(--spacing-sm);
    cursor: pointer;
}

.toggle-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
}
</style>

<script>
function changeLanguage(lang) {
    window.location.href = '?lang=' + lang;
}

document.getElementById('profile-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    
    try {
        await Guild.api('settings.php?action=update&section=profile', {
            method: 'POST',
            body: data
        });
        Guild.toast('保存しました', 'success');
    } catch (error) {
        Guild.toast('保存に失敗しました', 'error');
    }
});

document.getElementById('availability-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData);
    
    try {
        await Guild.api('settings.php?action=update&section=availability', {
            method: 'POST',
            body: data
        });
        Guild.toast('保存しました', 'success');
    } catch (error) {
        Guild.toast('保存に失敗しました', 'error');
    }
});

document.getElementById('notification-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = {
        notify_new_request: formData.has('notify_new_request') ? 1 : 0,
        notify_assigned: formData.has('notify_assigned') ? 1 : 0,
        notify_approved: formData.has('notify_approved') ? 1 : 0,
        notify_earth_received: formData.has('notify_earth_received') ? 1 : 0,
        notify_thanks: formData.has('notify_thanks') ? 1 : 0,
        email_notifications: formData.has('email_notifications') ? 1 : 0
    };
    
    try {
        await Guild.api('settings.php?action=update&section=notifications', {
            method: 'POST',
            body: data
        });
        Guild.toast('保存しました', 'success');
    } catch (error) {
        Guild.toast('保存に失敗しました', 'error');
    }
});
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
