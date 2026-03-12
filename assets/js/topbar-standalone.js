/**
 * トップバー用スタンドアロンスクリプト
 * チャット以外のページ（memos.php, tasks.php 等）で共通トップバーを使うときに読み込む
 */
(function() {
    'use strict';

    function focusTopBarSearch() {
        var el = document.getElementById('topBarSearchInput');
        if (el) {
            el.removeAttribute('readonly');
            el.value = '';
            el.focus();
        }
    }

    function toggleAppMenu(e) {
        if (e) e.stopPropagation();
        var dropdown = document.getElementById('appDropdown');
        if (!dropdown) return;
        var isShow = dropdown.style.display === 'block';
        dropdown.style.display = isShow ? 'none' : 'block';
        closeUserMenu();
        var ld = document.getElementById('languageDropdown');
        if (ld) ld.classList.remove('show');
        var td = document.getElementById('taskDropdown');
        if (td) td.style.display = 'none';
        var nd = document.getElementById('notificationDropdown');
        if (nd) nd.style.display = 'none';
    }

    function toggleTaskMenu(e) {
        if (e) e.stopPropagation();
        window.location.href = 'tasks.php';
    }

    function toggleNotificationMenu(e) {
        if (e) e.stopPropagation();
        window.location.href = 'notifications.php';
    }

    function toggleLanguageMenu(e) {
        if (e) e.stopPropagation();
        var dropdown = document.getElementById('languageDropdown');
        if (dropdown) dropdown.classList.toggle('show');
        closeUserMenu();
        var td = document.getElementById('taskDropdown');
        if (td) td.style.display = 'none';
        var nd = document.getElementById('notificationDropdown');
        if (nd) nd.style.display = 'none';
        var ad = document.getElementById('appDropdown');
        if (ad) ad.style.display = 'none';
    }

    function toggleTaskMemoButtons() {
        var buttons = document.getElementById('taskMemoButtons');
        var toggleBtn = document.getElementById('toggleTaskMemoBtn');
        if (!buttons || !toggleBtn) return;
        buttons.classList.toggle('hidden');
        var isHidden = buttons.classList.contains('hidden');
        try { localStorage.setItem('taskMemoHidden', isHidden); } catch (err) {}
    }

    function toggleUserMenu(e) {
        if (e) e.stopPropagation();
        var container = e && e.target && e.target.closest ? e.target.closest('.user-menu-container') : null;
        var dropdown = container ? container.querySelector('.user-dropdown') : document.getElementById('userDropdown');
        if (!dropdown) return;
        document.querySelectorAll('.user-dropdown').forEach(function(d) {
            if (d !== dropdown) d.classList.remove('show');
        });
        dropdown.classList.toggle('show');
        var ld = document.getElementById('languageDropdown');
        if (ld) ld.classList.remove('show');
    }

    function closeUserMenu() {
        document.querySelectorAll('.user-dropdown.show').forEach(function(d) { d.classList.remove('show'); });
    }

    function toggleRightPanel() {
        var rightPanel = document.getElementById('rightPanel');
        if (!rightPanel) return;
        rightPanel.classList.toggle('collapsed');
        var isCollapsed = rightPanel.classList.contains('collapsed');
        try { localStorage.setItem('memosRightPanelCollapsed', isCollapsed); } catch (e) {}
    }

    function openUserAvatarModal() {
        window.location.href = 'settings.php';
    }

    async function changeLanguage(lang) {
        try {
            var response = await fetch('api/language.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ language: lang })
            });
            var raw = await response.text();
            var data = {};
            try { data = raw ? JSON.parse(raw) : {}; } catch (_) {}
            if (data.success) {
                var el = document.getElementById('languageDropdown');
                if (el) el.classList.remove('show');
                location.reload();
            } else {
                alert(data.error || '言語の変更に失敗しました');
            }
        } catch (e) {
            console.error('Language change error:', e);
            alert('エラーが発生しました');
        }
    }

    async function logout() {
        if (!confirm('ログアウトしますか？')) return;
        try {
            var response = await fetch('api/auth.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'logout' })
            });
            var data = await response.json();
            if (data.success) {
                location.href = 'index.php';
            } else {
                alert(data.message || 'ログアウトに失敗しました');
            }
        } catch (e) {
            location.href = 'api/auth.php?action=logout';
        }
    }

    function switchAccount() {
        if (!confirm('現在のアカウントからログアウトして、別のアカウントでログインしますか？')) return;
        fetch('api/auth.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'logout' })
        }).then(function() {
            location.href = 'index.php?switch=1';
        }).catch(function() {
            location.href = 'api/auth.php?action=logout';
        });
    }

    async function loadTaskDropdown() {
        var list = document.getElementById('taskDropdownList');
        var memoList = document.getElementById('memoDropdownList');
        
        if (list) {
            list.innerHTML = '<div class="task-dropdown-loading">読み込み中...</div>';
            try {
                var response = await fetch('api/tasks.php?action=list&limit=10&my_tasks_only=1&type=task');
                var data = await response.json();
                if (!data.success || !data.tasks || data.tasks.length === 0) {
                    list.innerHTML = '<div class="task-dropdown-empty">タスクがありません</div>';
                } else {
                    var html = '';
                    data.tasks.forEach(function(task) {
                        var isCompleted = task.status === 'completed';
                        var title = (task.title || '').replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
                        html += '<div class="task-dropdown-item' + (isCompleted ? ' completed' : '') + '">' +
                            '<div class="task-dropdown-content"><div class="task-dropdown-title">' + title + '</div></div></div>';
                    });
                    list.innerHTML = html;
                }
            } catch (err) {
                list.innerHTML = '<div class="task-dropdown-empty">読み込みに失敗しました</div>';
            }
        }
        
        if (memoList) {
            memoList.innerHTML = '<div class="task-dropdown-loading">読み込み中...</div>';
            try {
                var response2 = await fetch('api/tasks.php?action=list&limit=5&type=memo');
                var data2 = await response2.json();
                if (!data2.success || !data2.tasks || data2.tasks.length === 0) {
                    memoList.innerHTML = '<div class="task-dropdown-empty">メモがありません</div>';
                } else {
                    var html2 = '';
                    data2.tasks.forEach(function(memo) {
                        var title = (memo.title || '').replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
                        var preview = ((memo.content || '').substring(0, 60)).replace(/[&<>"']/g, function(c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
                        var color = memo.color || '#ffffff';
                        var isPinned = (memo.is_pinned == 1 || memo.is_pinned === '1');
                        html2 += '<a href="tasks.php?tab=memos" class="task-dropdown-item memo-item" style="border-left:3px solid ' + color + ';text-decoration:none;color:inherit;">' +
                            '<div class="task-dropdown-content"><div class="task-dropdown-title">' + (isPinned ? '📌 ' : '') + title + '</div>' +
                            (preview ? '<div class="task-dropdown-meta">' + preview + '</div>' : '') + '</div></a>';
                    });
                    memoList.innerHTML = html2;
                }
            } catch (err) {
                memoList.innerHTML = '<div class="task-dropdown-empty">読み込みに失敗しました</div>';
            }
        }
    }

    async function updateTaskMemoBadge() {
        try {
            var response = await fetch('api/tasks.php?action=count&my_tasks_only=1&type=task');
            var data = await response.json();
            var badge = document.getElementById('taskMemoBadge') || document.getElementById('taskBadge');
            if (badge && data.success) {
                var count = data.count || 0;
                if (count > 0) {
                    badge.textContent = count;
                    badge.style.display = 'inline-flex';
                } else {
                    badge.style.display = 'none';
                }
            }
        } catch (err) {}
    }

    window.focusTopBarSearch = focusTopBarSearch;
    window.toggleAppMenu = toggleAppMenu;
    window.toggleTaskMenu = toggleTaskMenu;
    window.toggleNotificationMenu = toggleNotificationMenu;
    window.toggleLanguageMenu = toggleLanguageMenu;
    window.toggleTaskMemoButtons = toggleTaskMemoButtons;
    window.toggleUserMenu = toggleUserMenu;
    window.closeUserMenu = closeUserMenu;
    window.toggleRightPanel = toggleRightPanel;
    window.openUserAvatarModal = openUserAvatarModal;
    window.changeLanguage = changeLanguage;
    window.logout = logout;
    window.switchAccount = switchAccount;
    window.loadTaskDropdown = loadTaskDropdown;
    window.updateTaskMemoBadge = updateTaskMemoBadge;
    window.updateTaskBadge = updateTaskMemoBadge;
    window.updateMemoBadge = updateTaskMemoBadge;

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.language-selector')) {
            var el = document.getElementById('languageDropdown');
            if (el) el.classList.remove('show');
        }
        if (!e.target.closest('.user-menu-container')) closeUserMenu();
        if (!e.target.closest('.task-menu-container')) {
            var td = document.getElementById('taskDropdown');
            if (td) td.style.display = 'none';
        }
        if (!e.target.closest('.notification-menu-container')) {
            var nd = document.getElementById('notificationDropdown');
            if (nd) nd.style.display = 'none';
        }
        if (!e.target.closest('.app-menu-container')) {
            var ad = document.getElementById('appDropdown');
            if (ad) ad.style.display = 'none';
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        updateTaskMemoBadge();
        var rightPanel = document.getElementById('rightPanel');
        if (rightPanel) {
            try {
                if (localStorage.getItem('memosRightPanelCollapsed') === 'true') rightPanel.classList.add('collapsed');
            } catch (e) {}
        }
        // 上パネル: 初期値は開いた状態。localStorage で「収納」を選んでいるときだけ非表示
        var taskMemoEl = document.getElementById('taskMemoButtons');
        var toggleTaskMemoBtn = document.getElementById('toggleTaskMemoBtn');
        if (taskMemoEl && toggleTaskMemoBtn) {
            try {
                if (localStorage.getItem('taskMemoHidden') === 'true') taskMemoEl.classList.add('hidden');
            } catch (e) {}
        }
        var searchInput = document.getElementById('topBarSearchInput');
        if (searchInput) {
            var searchBox = searchInput.closest('.search-box');
            if (searchBox) {
                var decoy = document.createElement('input');
                decoy.type = 'text';
                decoy.name = 'email';
                decoy.autocomplete = 'email';
                decoy.setAttribute('aria-hidden', 'true');
                decoy.tabIndex = -1;
                decoy.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px;';
                searchBox.insertBefore(decoy, searchInput);
            }
            searchInput.value = '';
            searchInput.removeAttribute('value');
            searchInput.setAttribute('autocomplete', 'off');
            searchInput.setAttribute('readonly', 'readonly');
            searchInput.addEventListener('focus', function onceFocus() {
                searchInput.removeAttribute('readonly');
                if (/@/.test(searchInput.value)) searchInput.value = '';
                searchInput.removeEventListener('focus', onceFocus);
            }, { once: true });
            var clearCount = 0;
            var clearIntervalId = setInterval(function() {
                if (/@/.test(searchInput.value)) searchInput.value = '';
                searchInput.removeAttribute('readonly');
                clearCount++;
                if (clearCount >= 15) clearInterval(clearIntervalId);
            }, 200);
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var q = (searchInput.value || '').trim();
                    window.location.href = q.length > 0 ? 'chat.php?q=' + encodeURIComponent(q) : 'chat.php';
                }
            });
        }
    });
})();
