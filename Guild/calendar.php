<?php
/**
 * Guild カレンダーページ
 */

require_once __DIR__ . '/includes/common.php';

$pageTitle = __('calendar');
$extraCss = ['calendar.css'];
$extraJs = ['calendar.js'];

require_once __DIR__ . '/templates/header.php';

$pdo = getDB();
$userId = getGuildUserId();

// 表示月
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

// 月の日数と開始曜日
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDayOfWeek = date('w', mktime(0, 0, 0, $month, 1, $year));

// 前月・翌月
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

// カレンダーエントリを取得（テーブルがない場合は空配列）
$entries = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM guild_calendar_entries
        WHERE user_id = ? AND YEAR(entry_date) = ? AND MONTH(entry_date) = ?
    ");
    $stmt->execute([$userId, $year, $month]);
    $entriesRaw = $stmt->fetchAll();
    foreach ($entriesRaw as $entry) {
        $day = (int)date('j', strtotime($entry['entry_date']));
        $entries[$day] = $entry;
    }
} catch (PDOException $e) {
    // テーブルがない場合は無視
}

$weekdays = ['日', '月', '火', '水', '木', '金', '土'];
?>

<div class="page-header">
    <h1 class="page-title"><?= __('calendar') ?></h1>
</div>

<div class="calendar-nav">
    <a href="?year=<?= $prevYear ?>&month=<?= $prevMonth ?>" class="btn btn-secondary">← 前月</a>
    <span class="calendar-current"><?= $year ?>年<?= $month ?>月</span>
    <a href="?year=<?= $nextYear ?>&month=<?= $nextMonth ?>" class="btn btn-secondary">翌月 →</a>
</div>

<div class="calendar-grid">
    <!-- 曜日ヘッダー -->
    <?php foreach ($weekdays as $i => $day): ?>
    <div class="calendar-header <?= $i === 0 ? 'sunday' : ($i === 6 ? 'saturday' : '') ?>"><?= $day ?></div>
    <?php endforeach; ?>
    
    <!-- 空のセル（月初の曜日まで） -->
    <?php for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
    <div class="calendar-cell empty"></div>
    <?php endfor; ?>
    
    <!-- 日付セル -->
    <?php for ($day = 1; $day <= $daysInMonth; $day++): 
        $dayOfWeek = ($firstDayOfWeek + $day - 1) % 7;
        $entry = $entries[$day] ?? null;
        $entryType = $entry['entry_type'] ?? '';
    ?>
    <div class="calendar-cell <?= $dayOfWeek === 0 ? 'sunday' : ($dayOfWeek === 6 ? 'saturday' : '') ?> <?= $entryType ? 'has-entry' : '' ?>"
         data-date="<?= sprintf('%04d-%02d-%02d', $year, $month, $day) ?>"
         onclick="editEntry(this)">
        <span class="calendar-day"><?= $day ?></span>
        <?php if ($entry): ?>
        <span class="calendar-entry-type entry-<?= $entryType ?>">
            <?= $entryType === 'work' ? '勤' : ($entryType === 'holiday' ? '休' : ($entryType === 'paid_leave' ? '有' : '')) ?>
        </span>
        <?php endif; ?>
    </div>
    <?php endfor; ?>
</div>

<div class="calendar-legend">
    <span class="legend-item"><span class="legend-dot entry-work"></span>勤務</span>
    <span class="legend-item"><span class="legend-dot entry-holiday"></span>休日</span>
    <span class="legend-item"><span class="legend-dot entry-paid_leave"></span>有休</span>
</div>

<!-- 編集モーダル -->
<div class="modal-backdrop" id="modal-backdrop"></div>
<div class="modal" id="edit-modal">
    <div class="modal-header">
        <h3 class="modal-title" id="modal-date"></h3>
        <button class="modal-close" onclick="closeModal()">&times;</button>
    </div>
    <div class="modal-body">
        <div class="form-group">
            <label>種別</label>
            <select id="entry-type" class="form-select">
                <option value="">未設定</option>
                <option value="work">勤務</option>
                <option value="holiday">休日</option>
                <option value="paid_leave">有休</option>
            </select>
        </div>
        <div class="form-group">
            <label>勤務場所</label>
            <input type="text" id="entry-location" class="form-input" placeholder="勤務場所（任意）">
        </div>
        <div class="form-group">
            <label>メモ</label>
            <textarea id="entry-note" class="form-textarea" rows="2"></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal()">キャンセル</button>
        <button class="btn btn-primary" onclick="saveEntry()">保存</button>
    </div>
</div>

<script>
let currentDate = null;

function editEntry(cell) {
    currentDate = cell.dataset.date;
    document.getElementById('modal-date').textContent = currentDate;
    document.getElementById('edit-modal').classList.add('active');
    document.getElementById('modal-backdrop').classList.add('active');
}

function closeModal() {
    document.getElementById('edit-modal').classList.remove('active');
    document.getElementById('modal-backdrop').classList.remove('active');
    currentDate = null;
}

async function saveEntry() {
    const entryType = document.getElementById('entry-type').value;
    const location = document.getElementById('entry-location').value;
    const note = document.getElementById('entry-note').value;
    
    try {
        const response = await Guild.api('calendar.php?action=save', {
            method: 'POST',
            body: {
                date: currentDate,
                entry_type: entryType,
                location: location,
                note: note
            }
        });
        
        if (response.success) {
            location.reload();
        }
    } catch (error) {
        Guild.toast('保存に失敗しました', 'error');
    }
}

document.getElementById('modal-backdrop').addEventListener('click', closeModal);
</script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
