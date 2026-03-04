/**
 * 共有フォルダ (Shared Folder) JavaScript
 * エクスプローラー風UI対応
 */
(function() {
'use strict';

var API = 'api/storage.php';
var currentConvId = 0;
var currentFolderId = null;
var currentFolderStack = [];
var searchTimeout = null;
var previewFileData = null;
var dragDropInitialized = false;

var MAX_ALBUM_PHOTOS = 50;
var STORAGE_VIEW_MODE_KEY = 'storageViewMode';

function getStorageViewMode() {
    try { return localStorage.getItem(STORAGE_VIEW_MODE_KEY) || 'text'; } catch (e) { return 'text'; }
}
function setStorageViewMode(mode) {
    try { localStorage.setItem(STORAGE_VIEW_MODE_KEY, mode); } catch (e) {}
    loadFolders();
}

// ============================================
// 初期化・表示切替
// ============================================

window.openStorageVault = function() {
    currentConvId = window.currentConversationId || 0;
    if (!currentConvId) return;
    currentFolderId = null;
    currentFolderStack = [];

    document.getElementById('messagesArea').style.display = 'none';
    document.getElementById('inputArea').style.display = 'none';
    var showBtn = document.getElementById('inputShowBtn');
    if (showBtn) showBtn.style.display = 'none';
    document.getElementById('storageVaultView').style.display = 'flex';

    if (location.hash !== '#storage') {
        history.replaceState(null, '', location.pathname + location.search + '#storage');
    }

    loadUsage();
    loadFolders();
    if (!dragDropInitialized) { setupDragDrop(); setupContextMenu(); dragDropInitialized = true; }
};

window.closeStorageVault = function() {
    document.getElementById('storageVaultView').style.display = 'none';
    document.getElementById('messagesArea').style.display = '';
    document.getElementById('inputArea').style.display = '';
    closeCtxMenu();

    if (location.hash === '#storage') {
        history.replaceState(null, '', location.pathname + location.search);
    }
};

document.addEventListener('DOMContentLoaded', function() {
    if (location.hash === '#storage') {
        setTimeout(function() { window.openStorageVault(); }, 300);
    }
    var folderInput = document.getElementById('svFolderNameInput');
    if (folderInput) {
        folderInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); window.submitNewFolder(); }
            if (e.key === 'Escape') { e.preventDefault(); window.closeFolderDialog(); }
        });
    }
});

// ============================================
// 容量表示
// ============================================

function loadUsage() {
    fetch(API + '?action=get_usage&conversation_id=' + currentConvId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return;
            var unlimited = !!(d.unlimited);
            var pct = unlimited ? 0 : Math.min(d.percent, 100);
            var cls = pct >= 90 ? 'sv-danger' : (pct >= 80 ? 'sv-warn' : '');

            var fill = document.getElementById('svUsageBarFillLg');
            fill.style.width = pct + '%';
            fill.className = 'sv-usage-bar-fill-lg' + (cls ? ' ' + cls : '');
            var planLabel = (d.plan_name === 'unlimited' || unlimited) ? '無制限' : d.plan_name;
            document.getElementById('svUsageTextLg').textContent = d.used_display + ' / ' + d.quota_display + (planLabel ? ' (' + planLabel + ')' : '');

            var rpFill = document.getElementById('svUsageBarFill');
            if (rpFill) {
                rpFill.style.width = pct + '%';
                rpFill.className = 'sv-usage-bar-fill' + (cls ? ' ' + cls : '');
            }
            var rpText = document.getElementById('svUsageText');
            if (rpText) rpText.textContent = d.used_display + ' / ' + d.quota_display;
        }).catch(function() {});
}

// ============================================
// フォルダ・ファイル一覧
// ============================================

function loadFolders() {
    var url = API + '?action=get_folders&conversation_id=' + currentConvId;
    if (currentFolderId) url += '&parent_id=' + currentFolderId;

    hideSearchResults();
    hideTrashView();

    var filesUrl = currentFolderId
        ? API + '?action=get_files&folder_id=' + currentFolderId
        : API + '?action=get_all_files&conversation_id=' + currentConvId;

    console.log('[Storage] loadFolders convId=' + currentConvId + ' folderId=' + currentFolderId);
    console.log('[Storage] foldersUrl=' + url);
    console.log('[Storage] filesUrl=' + filesUrl);

    Promise.all([
        fetch(url, { credentials: 'include' }).then(function(r) { return r.text(); }).then(function(t) { console.log('[Storage] get_folders raw:', t); try { return JSON.parse(t); } catch(e) { console.error('[Storage] get_folders JSON parse error', e); return { success: false, folders: [] }; } }),
        fetch(filesUrl, { credentials: 'include' }).then(function(r) { return r.text(); }).then(function(t) { console.log('[Storage] get_files raw:', t); try { return JSON.parse(t); } catch(e) { console.error('[Storage] get_files JSON parse error', e); return { success: false, files: [] }; } }).catch(function(e) { console.error('[Storage] get_files fetch error', e); return { success: true, files: [] }; }),
        !currentFolderId
            ? fetch(API + '?action=get_shared_folders&conversation_id=' + currentConvId, { credentials: 'include' }).then(function(r) { return r.json(); }).catch(function() { return { success: true, shared_folders: [] }; })
            : Promise.resolve({ success: true, shared_folders: [] })
    ]).then(function(results) {
        var foldersData = results[0];
        var filesData = results[1];
        var sharedData = results[2];

        renderBreadcrumbs();
        var grid = document.getElementById('svGrid');
        var emptyEl = document.getElementById('svEmpty');
        var toolbar = document.getElementById('svToolbar');
        grid.innerHTML = '';
        toolbar.style.display = 'flex';

        var folders = foldersData.success ? foldersData.folders : [];
        var files = filesData.success ? (filesData.files || []) : [];
        var shared = sharedData.success ? (sharedData.shared_folders || []) : [];
        console.log('[Storage] Render: folders=' + folders.length + ' files=' + files.length + ' shared=' + shared.length);

        var sharedSection = document.getElementById('svSharedSection');
        if (shared.length > 0 && !currentFolderId) {
            sharedSection.style.display = '';
            var sg = document.getElementById('svSharedGrid');
            sg.innerHTML = '';
            shared.forEach(function(f) {
                sg.appendChild(createFolderCard(f, true));
            });
        } else {
            sharedSection.style.display = 'none';
        }

        folders.forEach(function(f) {
            grid.appendChild(createFolderCard(f, false));
        });
        var viewMode = getStorageViewMode();
        if (viewMode === 'grid') {
            grid.classList.add('sv-grid-mode');
            var hdr = document.getElementById('svListHeader');
            if (hdr) hdr.style.display = 'none';
            files.forEach(function(f) {
                grid.appendChild(createFileCardOrGrid(f));
            });
        } else {
            grid.classList.remove('sv-grid-mode');
            var hdr2 = document.getElementById('svListHeader');
            if (hdr2) hdr2.style.display = 'flex';
            files.forEach(function(f) {
                grid.appendChild(createFileCard(f));
            });
        }

        var hasItems = (folders.length > 0 || files.length > 0);
        emptyEl.style.display = hasItems ? 'none' : '';
        var hdr = document.getElementById('svListHeader');
        if (hdr) hdr.style.display = hasItems ? 'flex' : 'none';
    }).catch(function(err) {
        console.error('[Storage] loadFolders error:', err);
    });
}

function createFolderCard(folder, isShared) {
    var row = document.createElement('div');
    row.className = 'sv-list-row sv-list-folder';
    row.dataset.folderId = folder.id;
    row.dataset.folderName = folder.name;
    row.dataset.itemType = 'folder';

    row.ondblclick = function() { navigateToFolder(folder.id, folder.name); };
    row.onclick = function(e) { selectItem(row, e); };

    var icon = isShared ? '📂' : '📁';
    var shareHtml = (isShared || folder.share_count > 0) ? '<span class="sv-list-share" title="共有">🔗</span>' : '';
    var dateStr = folder.created_at ? formatDate(folder.created_at) : '';
    var meta = (folder.file_count || 0) + ' ファイル';
    if (folder.subfolder_count) meta += ', ' + folder.subfolder_count + ' フォルダ';

    row.innerHTML =
        '<div class="sv-lr-name"><span class="sv-list-icon">' + icon + '</span><span class="sv-lr-name-text">' + escHtml(folder.name) + '</span>' + shareHtml + '</div>' +
        '<div class="sv-lr-date">' + dateStr + '</div>' +
        '<div class="sv-lr-type">ファイル フォルダー</div>' +
        '<div class="sv-lr-size">' + meta + '</div>';
    return row;
}

function createFileCard(file) {
    var row = document.createElement('div');
    row.className = 'sv-list-row sv-list-file';
    row.dataset.fileId = file.id;
    row.dataset.fileName = file.original_name;
    row.dataset.itemType = 'file';

    row.ondblclick = function() { previewFile(file.id); };
    row.onclick = function(e) { selectItem(row, e); };

    var mime = file.mime_type || '';
    var ext = (file.original_name || '').split('.').pop().toUpperCase();
    var icon = getFileIcon(mime, ext);
    var typeLabel = getFileTypeLabel(mime, ext);
    var dateStr = file.created_at ? formatDate(file.created_at) : '';
    var folderHint = (!currentFolderId && file.folder_name) ? '<span class="sv-list-folder-hint">' + escHtml(file.folder_name) + '</span>' : '';

    row.innerHTML =
        '<div class="sv-lr-name"><span class="sv-list-icon">' + icon + '</span><span class="sv-lr-name-text">' + escHtml(file.original_name) + '</span>' + folderHint + '</div>' +
        '<div class="sv-lr-date">' + dateStr + '</div>' +
        '<div class="sv-lr-type">' + typeLabel + '</div>' +
        '<div class="sv-lr-size">' + formatSize(file.file_size) + '</div>';
    return row;
}

function createFileCardOrGrid(file) {
    var mime = file.mime_type || '';
    if (mime.startsWith('image/')) return createFileCardGrid(file);
    return createFileCard(file);
}

function createFileCardGrid(file) {
    var row = document.createElement('div');
    row.className = 'sv-list-row sv-list-file sv-file-card-grid';
    row.dataset.fileId = file.id;
    row.dataset.fileName = file.original_name;
    row.dataset.itemType = 'file';

    row.ondblclick = function() { previewFile(file.id); };
    row.onclick = function(e) { selectItem(row, e); };

    var mime = file.mime_type || '';
    var isImage = mime.startsWith('image/');
    var dateStr = file.created_at ? formatDate(file.created_at) : '';
    var thumb = '';
    if (isImage) {
        thumb = '<div class="sv-grid-thumb-wrap"><img class="sv-grid-thumb" data-file-id="' + file.id + '" alt="" src="data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'80\' height=\'80\'/%3E"></div>';
    } else {
        var ext = (file.original_name || '').split('.').pop().toUpperCase();
        thumb = '<div class="sv-grid-thumb-wrap sv-grid-thumb-icon">' + getFileIcon(mime, ext) + '</div>';
    }
    row.innerHTML =
        thumb +
        '<div class="sv-grid-name">' + escHtml(file.original_name) + '</div>' +
        '<div class="sv-grid-meta">' + dateStr + ' · ' + formatSize(file.file_size) + '</div>';
    if (isImage) {
        var thumbImg = row.querySelector('.sv-grid-thumb');
        if (thumbImg) lazyLoadGridThumb(thumbImg);
    }
    return row;
}

function lazyLoadGridThumb(imgEl) {
    if (!imgEl || !imgEl.dataset.fileId) return;
    var fileId = imgEl.dataset.fileId;
    fetch(API + '?action=preview_file&file_id=' + fileId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success && d.url && imgEl.parentNode) imgEl.src = d.url;
        })
        .catch(function() {});
}

function getFileIcon(mime, ext) {
    if (mime.startsWith('image/')) return '🖼️';
    if (mime === 'application/pdf') return '📕';
    if (mime.startsWith('video/')) return '🎬';
    if (mime.startsWith('audio/')) return '🎵';
    if (mime.includes('spreadsheet') || mime.includes('excel') || ext === 'XLSX' || ext === 'XLS' || ext === 'CSV') return '📊';
    if (mime.includes('word') || mime.includes('document') || ext === 'DOCX' || ext === 'DOC') return '📝';
    if (mime.includes('presentation') || mime.includes('powerpoint') || ext === 'PPTX' || ext === 'PPT') return '📽️';
    if (mime.includes('zip') || mime.includes('archive') || mime.includes('compressed')) return '📦';
    return '📄';
}

function getFileTypeLabel(mime, ext) {
    if (mime.startsWith('image/jpeg') || ext === 'JPG' || ext === 'JPEG') return 'JPG ファイル';
    if (mime.startsWith('image/png') || ext === 'PNG') return 'PNG ファイル';
    if (mime.startsWith('image/gif') || ext === 'GIF') return 'GIF ファイル';
    if (mime.startsWith('image/webp') || ext === 'WEBP') return 'WEBP ファイル';
    if (mime.startsWith('image/')) return ext + ' ファイル';
    if (mime === 'application/pdf') return 'Chrome PDF Docum...';
    if (mime.startsWith('video/')) return ext + ' 動画';
    if (mime.startsWith('audio/')) return ext + ' 音声';
    if (mime.includes('spreadsheet') || mime.includes('excel') || ext === 'XLSX') return 'Microsoft Excel ワーク...';
    if (ext === 'XLS') return 'Microsoft Excel 97-2...';
    if (ext === 'CSV') return 'CSV ファイル';
    if (mime.includes('word') || ext === 'DOCX' || ext === 'DOC') return 'Microsoft Word 文書';
    if (mime.includes('presentation') || mime.includes('powerpoint') || ext === 'PPTX' || ext === 'PPT') return 'PowerPoint プレゼン...';
    if (mime.includes('zip')) return 'ZIP アーカイブ';
    return ext + ' ファイル';
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    var y = d.getFullYear();
    var m = ('0' + (d.getMonth() + 1)).slice(-2);
    var day = ('0' + d.getDate()).slice(-2);
    var h = ('0' + d.getHours()).slice(-2);
    var min = ('0' + d.getMinutes()).slice(-2);
    return y + '/' + m + '/' + day + ' ' + h + ':' + min;
}

// ============================================
// 選択状態管理
// ============================================

function selectItem(card, e) {
    if (e && e.target.closest('.sv-ctx-menu')) return;
    var grid = document.getElementById('svGrid');
    var allRows = grid.querySelectorAll('.sv-list-row');
    allRows.forEach(function(c) { c.classList.remove('sv-selected'); });
    card.classList.add('sv-selected');
}

// ============================================
// ナビゲーション
// ============================================

function navigateToFolder(folderId, folderName) {
    if (currentFolderId) {
        currentFolderStack.push({ id: currentFolderId });
    }
    currentFolderId = folderId;
    loadFolders();
}

window.navigateToFolderById = function(folderId) {
    if (folderId === null) {
        currentFolderId = null;
        currentFolderStack = [];
    } else {
        currentFolderId = folderId;
    }
    loadFolders();
};

function renderBreadcrumbs() {
    var bc = document.getElementById('svBreadcrumbs');
    if (!currentFolderId) {
        bc.innerHTML = '<span class="sv-bc-current">ルート</span>';
        return;
    }
    fetch(API + '?action=get_breadcrumbs&folder_id=' + currentFolderId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return;
            var html = '<a href="#" onclick="navigateToFolderById(null);return false;">ルート</a>';
            d.breadcrumbs.forEach(function(c, i) {
                html += '<span class="sv-bc-sep">/</span>';
                if (i === d.breadcrumbs.length - 1) {
                    html += '<span class="sv-bc-current">' + escHtml(c.name) + '</span>';
                } else {
                    html += '<a href="#" onclick="navigateToFolderById(' + c.id + ');return false;">' + escHtml(c.name) + '</a>';
                }
            });
            bc.innerHTML = html;
        });
}

// ============================================
// フォルダ操作
// ============================================

var pendingFolderParentId = null;

window.createNewFolder = function(parentId) {
    if (!currentConvId) currentConvId = window.currentConversationId || 0;
    if (!currentConvId) { alert('会話が選択されていません'); return; }

    pendingFolderParentId = (parentId !== undefined) ? parentId : currentFolderId;
    var dialog = document.getElementById('svFolderDialog');
    var input = document.getElementById('svFolderNameInput');
    if (!dialog || !input) return;
    input.value = '';
    dialog.style.display = 'flex';
    setTimeout(function() { input.focus(); }, 50);
};

window.closeFolderDialog = function() {
    var dialog = document.getElementById('svFolderDialog');
    if (dialog) dialog.style.display = 'none';
};

window.submitNewFolder = function() {
    var input = document.getElementById('svFolderNameInput');
    var name = (input ? input.value : '').trim();
    if (!name) { if (input) input.focus(); return; }

    closeFolderDialog();

    var fd = new FormData();
    fd.append('action', 'create_folder');
    fd.append('conversation_id', currentConvId);
    if (pendingFolderParentId) fd.append('parent_id', pendingFolderParentId);
    fd.append('name', name);

    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) loadFolders();
            else alert(d.message || 'フォルダ作成に失敗しました');
        })
        .catch(function(err) {
            console.error('[Storage] create_folder error:', err);
            alert('フォルダ作成中にエラーが発生しました');
        });
};

function renameFolder(folderId) {
    var card = document.querySelector('[data-folder-id="' + folderId + '"]');
    var currentName = card ? card.dataset.folderName : '';
    var name = prompt('新しいフォルダ名:', currentName);
    if (!name || !name.trim()) return;
    var fd = new FormData();
    fd.append('action', 'rename_folder');
    fd.append('folder_id', folderId);
    fd.append('name', name.trim());
    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) loadFolders();
            else alert(d.message);
        });
}

function deleteFolder(folderId) {
    if (!confirm('このフォルダとその中のファイルを全て削除しますか？\nこの操作は取り消せません。')) return;
    var fd = new FormData();
    fd.append('action', 'delete_folder');
    fd.append('folder_id', folderId);
    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) { loadFolders(); loadUsage(); }
            else alert(d.message);
        });
}

// ============================================
// ファイルアップロード（署名付きURL方式）
// ============================================

window.handleStorageFileSelect = function(input) {
    if (!input.files || !input.files.length) return;
    uploadFiles(Array.from(input.files));
    input.value = '';
};

window.handleStorageAlbumSelect = function(input) {
    if (!input.files || !input.files.length) return;
    var files = Array.from(input.files).filter(function(f) { return f.type && f.type.startsWith('image/'); });
    if (files.length > MAX_ALBUM_PHOTOS) {
        files = files.slice(0, MAX_ALBUM_PHOTOS);
        alert('最大' + MAX_ALBUM_PHOTOS + '枚まで選択できます。先頭' + MAX_ALBUM_PHOTOS + '枚をアップロードします。');
    }
    if (!files.length) { alert('画像を選択してください'); input.value = ''; return; }
    ensureFolderAndUploadAlbum(files);
    input.value = '';
};

function ensureFolderAndUploadAlbum(fileList) {
    if (!currentConvId) currentConvId = window.currentConversationId || 0;
    if (!currentConvId) { alert('会話が選択されていません'); return; }
    var now = new Date();
    var y = now.getFullYear();
    var m = ('0' + (now.getMonth() + 1)).slice(-2);
    var d = ('0' + now.getDate()).slice(-2);
    var h = ('0' + now.getHours()).slice(-2);
    var min = ('0' + now.getMinutes()).slice(-2);
    var folderName = y + '-' + m + '-' + d + ' ' + h + ':' + min;
    var fd = new FormData();
    fd.append('action', 'create_folder');
    fd.append('conversation_id', currentConvId);
    if (currentFolderId) fd.append('parent_id', currentFolderId);
    fd.append('name', folderName);
    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success && res.folder_id) {
                currentFolderId = res.folder_id;
                currentFolderStack.push({ id: res.folder_id, name: folderName });
                doUploadFilesWithCompress(fileList, function() {
                    loadFolders();
                    loadUsage();
                });
            } else {
                alert(res.message || 'フォルダの作成に失敗しました');
            }
        })
        .catch(function() { alert('フォルダの作成に失敗しました'); });
}

function ensureFolderAndUpload(fileList) {
    if (!currentConvId) currentConvId = window.currentConversationId || 0;
    if (!currentConvId) { alert('会話が選択されていません'); return; }
    if (currentFolderId) {
        doUploadFiles(fileList);
        return;
    }
    fetch(API + '?action=get_folders&conversation_id=' + currentConvId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var existing = null;
            if (d.success && d.folders) {
                for (var i = 0; i < d.folders.length; i++) {
                    if (d.folders[i].name === 'アップロード') { existing = d.folders[i]; break; }
                }
            }
            if (existing) {
                currentFolderId = existing.id;
                loadFolders();
                doUploadFiles(fileList);
            } else {
                var fd = new FormData();
                fd.append('action', 'create_folder');
                fd.append('conversation_id', currentConvId);
                fd.append('name', 'アップロード');
                fetch(API, { method: 'POST', body: fd, credentials: 'include' })
                    .then(function(r) { return r.json(); })
                    .then(function(d2) {
                        if (d2.success) {
                            currentFolderId = d2.folder_id;
                            loadFolders();
                            doUploadFiles(fileList);
                        } else {
                            alert(d2.message || 'フォルダ作成に失敗しました');
                        }
                    });
            }
        });
}

function uploadFiles(fileList) {
    if (!currentFolderId) {
        ensureFolderAndUpload(fileList);
        return;
    }
    doUploadFiles(fileList);
}

function doUploadFiles(fileList) {
    var progEl = document.getElementById('svUploadProgress');
    progEl.style.display = '';
    progEl.innerHTML = '';

    var items = [];
    fileList.forEach(function(file) {
        var item = document.createElement('div');
        item.className = 'sv-upload-item';
        item.innerHTML =
            '<span class="sv-upload-item-name">' + escHtml(file.name) + '</span>' +
            '<div class="sv-upload-item-bar"><div class="sv-upload-item-fill" style="width:0%"></div></div>' +
            '<span class="sv-upload-item-status">⏳</span>';
        progEl.appendChild(item);
        items.push({ file: file, item: item });
    });

    (function run() {
        items.forEach(function(entry) {
            var file = entry.file;
            var itemEl = entry.item;
            var isImage = file.type && file.type.startsWith('image/');
            var compressFn = typeof window.compressImageForUpload === 'function' ? window.compressImageForUpload : null;
            if (isImage && compressFn) {
                compressFn(file, { maxSizeBytes: 5 * 1024 * 1024, maxDimension: 1920, quality: 0.85 })
                    .then(function(compressed) { uploadSingleFile(compressed, itemEl); })
                    .catch(function() { uploadSingleFile(file, itemEl); });
            } else {
                uploadSingleFile(file, itemEl);
            }
        });
    })();
}

function doUploadFilesWithCompress(fileList, onAllDone) {
    var progEl = document.getElementById('svUploadProgress');
    progEl.style.display = '';
    progEl.innerHTML = '';

    var items = [];
    fileList.forEach(function(file) {
        var item = document.createElement('div');
        item.className = 'sv-upload-item';
        item.innerHTML =
            '<span class="sv-upload-item-name">' + escHtml(file.name) + '</span>' +
            '<div class="sv-upload-item-bar"><div class="sv-upload-item-fill" style="width:0%"></div></div>' +
            '<span class="sv-upload-item-status">⏳</span>';
        progEl.appendChild(item);
        items.push({ file: file, item: item });
    });

    var compressFn = typeof window.compressImageForUpload === 'function' ? window.compressImageForUpload : null;
    var pending = items.length;
    function checkDone() {
        pending--;
        if (pending <= 0 && typeof onAllDone === 'function') onAllDone();
    }
    items.forEach(function(entry) {
        var file = entry.file;
        var itemEl = entry.item;
        var isImage = file.type && file.type.startsWith('image/');
        if (isImage && compressFn) {
            compressFn(file, { maxSizeBytes: 5 * 1024 * 1024, maxDimension: 1920, quality: 0.85 })
                .then(function(compressed) { uploadSingleFile(compressed, itemEl); checkDone(); })
                .catch(function() { uploadSingleFile(file, itemEl); checkDone(); });
        } else {
            uploadSingleFile(file, itemEl);
            checkDone();
        }
    });
    if (items.length === 0 && typeof onAllDone === 'function') onAllDone();
}

function uploadSingleFile(file, itemEl) {
    var fill = itemEl.querySelector('.sv-upload-item-fill');
    var status = itemEl.querySelector('.sv-upload-item-status');

    var fd = new FormData();
    fd.append('action', 'request_upload');
    fd.append('folder_id', currentFolderId);
    fd.append('filename', file.name);
    fd.append('file_size', file.size);
    fd.append('content_type', file.type || 'application/octet-stream');

    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) {
                status.textContent = '✗';
                status.title = d.message;
                fill.style.width = '100%';
                fill.style.background = '#ef4444';
                return;
            }

            var xhr = new XMLHttpRequest();
            xhr.open('PUT', d.upload_url, true);
            xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    fill.style.width = Math.round(e.loaded / e.total * 100) + '%';
                }
            };

            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    var cfd = new FormData();
                    cfd.append('action', 'confirm_upload');
                    cfd.append('file_id', d.file_id);
                    fetch(API, { method: 'POST', body: cfd, credentials: 'include' })
                        .then(function(r) { return r.json(); })
                        .then(function(confirmData) {
                            if (confirmData && confirmData.success) {
                                status.textContent = '✓';
                                fill.style.background = '#22c55e';
                            } else {
                                status.textContent = '⚠';
                                fill.style.background = '#f59e0b';
                                console.error('[Storage] confirm_upload failed:', confirmData);
                            }
                            loadFolders();
                            loadUsage();
                        })
                        .catch(function(err) {
                            console.error('[Storage] confirm_upload error:', err);
                            status.textContent = '⚠';
                            loadFolders();
                            loadUsage();
                        });
                } else {
                    status.textContent = '✗';
                    fill.style.background = '#ef4444';
                    console.error('[Storage] S3 upload failed:', xhr.status, xhr.responseText);
                }
            };

            xhr.onerror = function() {
                status.textContent = '✗';
                fill.style.background = '#ef4444';
            };

            xhr.send(file);
        })
        .catch(function() {
            status.textContent = '✗';
            fill.style.background = '#ef4444';
        });
}

// ============================================
// ファイル操作
// ============================================

function deleteFile(fileId) {
    if (!confirm('このファイルをゴミ箱に移動しますか？')) return;
    var fd = new FormData();
    fd.append('action', 'delete_file');
    fd.append('file_id', fileId);
    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) { loadFolders(); loadUsage(); }
            else alert(d.message);
        });
}

function downloadFile(fileId) {
    fetch(API + '?action=download_file&file_id=' + fileId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success && d.url) {
                var a = document.createElement('a');
                a.href = d.url;
                a.download = d.original_name || 'download';
                a.target = '_blank';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
        });
}

// ============================================
// プレビュー
// ============================================

function previewFile(fileId) {
    fetch(API + '?action=preview_file&file_id=' + fileId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) return;
            previewFileData = d;
            var modal = document.getElementById('svPreviewModal');
            var content = document.getElementById('svPreviewContent');
            document.getElementById('svPreviewTitle').textContent = d.original_name;

            var mime = d.mime_type || '';
            if (mime.startsWith('image/')) {
                content.innerHTML = '<img src="' + d.url + '" alt="">';
            } else if (mime === 'application/pdf') {
                content.innerHTML = '<iframe src="' + d.url + '"></iframe>';
            } else if (mime.startsWith('video/')) {
                content.innerHTML = '<video src="' + d.url + '" controls autoplay></video>';
            } else {
                var ext = (d.original_name || '').split('.').pop().toUpperCase();
                content.innerHTML = '<div class="sv-preview-generic"><p style="font-size:48px">📄</p><p>' + escHtml(d.original_name) + '</p><p>' + ext + ' ファイル</p><button onclick="downloadPreviewFile()" style="margin-top:16px;padding:10px 24px;background:#3b82f6;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:14px">ダウンロード</button></div>';
            }

            modal.style.display = 'flex';
        });
}

window.closeStoragePreview = function() {
    document.getElementById('svPreviewModal').style.display = 'none';
    document.getElementById('svPreviewContent').innerHTML = '';
    previewFileData = null;
};

window.downloadPreviewFile = function() {
    if (previewFileData && previewFileData.url) {
        var a = document.createElement('a');
        a.href = previewFileData.url;
        a.download = previewFileData.original_name || 'download';
        a.target = '_blank';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
};

// ============================================
// 検索
// ============================================

window.storageSearch = function(keyword) {
    clearTimeout(searchTimeout);
    if (!keyword.trim()) { hideSearchResults(); return; }
    searchTimeout = setTimeout(function() {
        fetch(API + '?action=search&conversation_id=' + currentConvId + '&keyword=' + encodeURIComponent(keyword.trim()), { credentials: 'include' })
            .then(function(r) { return r.json(); })
            .then(function(d) { if (d.success) showSearchResults(d.folders, d.files); });
    }, 300);
};

function showSearchResults(folders, files) {
    var el = document.getElementById('svSearchResults');
    var hdr = document.getElementById('svListHeader');
    var grid = document.getElementById('svGrid');
    var empty = document.getElementById('svEmpty');
    if (hdr) hdr.style.display = 'none';
    if (grid) grid.style.display = 'none';
    empty.style.display = 'none';
    el.style.display = '';
    el.innerHTML = '';

    if (folders.length === 0 && files.length === 0) {
        el.innerHTML = '<p style="color:#9ca3af;text-align:center;padding:40px">検索結果がありません</p>';
        return;
    }

    folders.forEach(function(f) {
        var item = document.createElement('div');
        item.className = 'sv-search-result-item';
        item.onclick = function() { navigateToFolderById(f.id); document.getElementById('svSearchInput').value = ''; };
        item.innerHTML = '<span class="sv-search-result-icon">📁</span><div class="sv-search-result-info"><div class="sv-search-result-name">' + escHtml(f.name) + '</div></div>';
        el.appendChild(item);
    });

    files.forEach(function(f) {
        var item = document.createElement('div');
        item.className = 'sv-search-result-item';
        item.onclick = function() { previewFile(f.id); };
        item.innerHTML = '<span class="sv-search-result-icon">📄</span><div class="sv-search-result-info"><div class="sv-search-result-name">' + escHtml(f.original_name) + '</div><div class="sv-search-result-path">' + escHtml(f.folder_name || '') + ' - ' + formatSize(f.file_size) + '</div></div>';
        el.appendChild(item);
    });
}

function hideSearchResults() {
    document.getElementById('svSearchResults').style.display = 'none';
    var hdr = document.getElementById('svListHeader');
    var grid = document.getElementById('svGrid');
    if (hdr) hdr.style.display = '';
    if (grid) grid.style.display = '';
}

// ============================================
// ゴミ箱
// ============================================

window.openStorageTrash = function() {
    var trashView = document.getElementById('svTrashView');
    var hdr = document.getElementById('svListHeader');
    var grid = document.getElementById('svGrid');
    var empty = document.getElementById('svEmpty');
    var toolbar = document.getElementById('svToolbar');
    if (hdr) hdr.style.display = 'none';
    if (grid) grid.style.display = 'none';
    empty.style.display = 'none';
    toolbar.style.display = 'none';
    trashView.style.display = '';

    fetch(API + '?action=get_trash&conversation_id=' + currentConvId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var tg = document.getElementById('svTrashGrid');
            tg.innerHTML = '';
            if (!d.success || !d.files.length) {
                tg.innerHTML = '<p style="color:#9ca3af;padding:20px;text-align:center">ゴミ箱は空です</p>';
                return;
            }
            d.files.forEach(function(f) {
                var row = document.createElement('div');
                row.className = 'sv-list-row sv-list-file';
                var ext = (f.original_name || '').split('.').pop().toUpperCase();
                var mime = f.mime_type || '';
                var icon = getFileIcon(mime, ext);
                var typeLabel = getFileTypeLabel(mime, ext);
                row.innerHTML =
                    '<div class="sv-lr-name"><span class="sv-list-icon">' + icon + '</span><span class="sv-lr-name-text">' + escHtml(f.original_name) + '</span></div>' +
                    '<div class="sv-lr-date"><span class="sv-trash-days">残り' + f.days_remaining + '日</span></div>' +
                    '<div class="sv-lr-type">' + typeLabel + '</div>' +
                    '<div class="sv-lr-size">' + formatSize(f.file_size) + '</div>' +
                    '<div class="sv-lr-action"><button class="sv-restore-btn" onclick="event.stopPropagation();restoreFile(' + f.id + ')">復元</button></div>';
                tg.appendChild(row);
            });
        });
};

function hideTrashView() { document.getElementById('svTrashView').style.display = 'none'; }

window.restoreFile = function(fileId) {
    var fd = new FormData();
    fd.append('action', 'restore_file');
    fd.append('file_id', fileId);
    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.success) openStorageTrash(); else alert(d.message); });
};

window.emptyTrash = function() {
    if (!confirm('ゴミ箱を空にしますか？完全に削除され、復元できなくなります。')) return;
    var fd = new FormData();
    fd.append('action', 'empty_trash');
    fd.append('conversation_id', currentConvId);
    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.success) { openStorageTrash(); loadUsage(); } });
};

// ============================================
// 共有モーダル
// ============================================

window.openShareModal = function(folderId) {
    closeCtxMenu();
    var modal = document.getElementById('svShareModal');
    var body = document.getElementById('svShareBody');
    body.innerHTML = '<p style="color:#9ca3af">読込中...</p>';
    modal.style.display = 'flex';
    modal.dataset.folderId = folderId;

    Promise.all([
        fetch(API + '?action=get_shares&folder_id=' + folderId, { credentials: 'include' }).then(function(r) { return r.json(); }),
        fetch('api/conversations.php?action=list', { credentials: 'include' }).then(function(r) { return r.json(); })
    ]).then(function(results) {
        var shares = results[0].success ? results[0].shares : [];
        var convs = (results[1].conversations || results[1].data || []).filter(function(c) { return c.type === 'group'; });
        var sharedMap = {};
        shares.forEach(function(s) { sharedMap[s.shared_with_conversation_id] = s; });

        var html = '';
        convs.forEach(function(c) {
            var shared = sharedMap[c.id];
            html += '<div class="sv-share-group"><span class="sv-share-group-name">' + escHtml(c.name || c.title || 'Group #' + c.id) + '</span><div class="sv-share-group-actions">';
            if (shared) {
                html += '<select onchange="updateShare(' + folderId + ',' + c.id + ',this.value)"><option value="read"' + (shared.permission === 'read' ? ' selected' : '') + '>閲覧のみ</option><option value="readwrite"' + (shared.permission === 'readwrite' ? ' selected' : '') + '>編集可</option></select>';
                html += '<button onclick="removeShare(' + folderId + ',' + c.id + ')" style="color:#dc2626">解除</button>';
            } else {
                html += '<button onclick="addShare(' + folderId + ',' + c.id + ')">共有</button>';
            }
            html += '</div></div>';
        });
        body.innerHTML = html || '<p style="color:#9ca3af">共有可能なグループがありません</p>';
    });
};

window.closeShareModal = function() { document.getElementById('svShareModal').style.display = 'none'; };

// ============================================
// パスワード設定モーダル
// ============================================

window.openPasswordModal = function(folderId) {
    closeCtxMenu();
    var modal = document.getElementById('svPasswordModal');
    var input = document.getElementById('svPasswordInput');
    if (!modal || !input) return;
    modal.dataset.folderId = folderId;
    input.value = '';
    modal.style.display = 'flex';
    setTimeout(function() { input.focus(); }, 100);
};

window.closePasswordModal = function() {
    var modal = document.getElementById('svPasswordModal');
    if (modal) modal.style.display = 'none';
};

window.submitFolderPassword = function() {
    var modal = document.getElementById('svPasswordModal');
    var input = document.getElementById('svPasswordInput');
    if (!modal || !input) return;
    var folderId = modal.dataset.folderId;
    if (!folderId) return;
    var password = (input.value || '').trim();

    var fd = new FormData();
    fd.append('action', 'set_folder_password');
    fd.append('folder_id', folderId);
    fd.append('password', password);

    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.success) {
                alert(d.message || '設定しました');
                closePasswordModal();
            } else {
                alert(d.message || '設定に失敗しました');
            }
        })
        .catch(function() { alert('通信エラー'); });
};

window.addShare = function(folderId, convId) {
    var fd = new FormData();
    fd.append('action', 'share_folder'); fd.append('folder_id', folderId);
    fd.append('conversation_id', convId); fd.append('permission', 'read');
    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.success) openShareModal(folderId); else alert(d.message); });
};

window.updateShare = function(folderId, convId, perm) {
    var fd = new FormData();
    fd.append('action', 'share_folder'); fd.append('folder_id', folderId);
    fd.append('conversation_id', convId); fd.append('permission', perm);
    fetch(API, { method: 'POST', body: fd, credentials: 'include' });
};

window.removeShare = function(folderId, convId) {
    var fd = new FormData();
    fd.append('action', 'unshare_folder'); fd.append('folder_id', folderId); fd.append('conversation_id', convId);
    fetch(API, { method: 'POST', body: fd, credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.success) openShareModal(folderId); });
};

// ============================================
// 権限モーダル
// ============================================

window.openPermModal = function() {
    closeCtxMenu();
    var modal = document.getElementById('svPermModal');
    var body = document.getElementById('svPermBody');
    body.innerHTML = '<p style="color:#9ca3af">読込中...</p>';
    modal.style.display = 'flex';

    fetch(API + '?action=get_permissions&conversation_id=' + currentConvId, { credentials: 'include' })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d.success) { body.innerHTML = '<p>' + (d.message || '') + '</p>'; return; }
            var html = '';
            var labels = { can_create_folder: 'フォルダ作成', can_delete_folder: 'フォルダ削除', can_upload: 'アップロード', can_delete_file: 'ファイル削除' };
            d.members.forEach(function(m) {
                html += '<div class="sv-perm-row"><span class="sv-perm-name">' + escHtml(m.name) + '</span><div class="sv-perm-toggles">';
                Object.keys(labels).forEach(function(key) {
                    html += '<label class="sv-perm-toggle"><input type="checkbox"' + (m[key] ? ' checked' : '') + ' onchange="savePerm(' + m.user_id + ',\'' + key + '\',this.checked)">' + labels[key] + '</label>';
                });
                html += '</div></div>';
            });
            body.innerHTML = html;
        });
};

window.closePermModal = function() { document.getElementById('svPermModal').style.display = 'none'; };

window.savePerm = function(userId, key, val) {
    var fd = new FormData();
    fd.append('action', 'update_permission'); fd.append('conversation_id', currentConvId);
    fd.append('user_id', userId); fd.append(key, val ? 1 : 0);
    fetch(API, { method: 'POST', body: fd, credentials: 'include' });
};

// ============================================
// 右クリック コンテキストメニュー（エクスプローラー風）
// ============================================

var ctxEl = null;

function setupContextMenu() {
    var content = document.getElementById('svContent');
    if (!content) return;

    content.addEventListener('contextmenu', function(e) {
        var vaultView = document.getElementById('storageVaultView');
        if (!vaultView || vaultView.style.display === 'none') return;

        e.preventDefault();
        closeCtxMenu();

        var folderCard = e.target.closest('.sv-list-folder');
        var fileCard = e.target.closest('.sv-list-file');

        if (folderCard) {
            showFolderContextMenu(e, folderCard);
        } else if (fileCard) {
            showFileContextMenu(e, fileCard);
        } else {
            showBackgroundContextMenu(e);
        }
    });
}

function showFolderContextMenu(e, card) {
    var folderId = parseInt(card.dataset.folderId);
    selectItem(card, e);

    ctxEl = document.createElement('div');
    ctxEl.className = 'sv-ctx-menu';
    ctxEl.innerHTML =
        '<button class="sv-ctx-item" data-action="open"><span class="sv-ctx-icon">📂</span>開く</button>' +
        '<div class="sv-ctx-sep"></div>' +
        '<button class="sv-ctx-item" data-action="rename"><span class="sv-ctx-icon">✏️</span>名前の変更</button>' +
        '<button class="sv-ctx-item" data-action="share"><span class="sv-ctx-icon">🔗</span>共有設定</button>' +
        '<button class="sv-ctx-item" data-action="password"><span class="sv-ctx-icon">🔐</span>パスワード設定</button>' +
        '<div class="sv-ctx-sep"></div>' +
        '<button class="sv-ctx-item sv-ctx-danger" data-action="delete"><span class="sv-ctx-icon">🗑️</span>削除</button>';

    ctxEl.querySelector('[data-action="open"]').onclick = function() { navigateToFolder(folderId); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="rename"]').onclick = function() { renameFolder(folderId); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="share"]').onclick = function() { openShareModal(folderId); };
    ctxEl.querySelector('[data-action="password"]').onclick = function() { openPasswordModal(folderId); };
    ctxEl.querySelector('[data-action="delete"]').onclick = function() { deleteFolder(folderId); closeCtxMenu(); };

    positionCtxMenu(e);
}

function showFileContextMenu(e, card) {
    var fileId = parseInt(card.dataset.fileId);
    selectItem(card, e);

    ctxEl = document.createElement('div');
    ctxEl.className = 'sv-ctx-menu';
    ctxEl.innerHTML =
        '<button class="sv-ctx-item" data-action="preview"><span class="sv-ctx-icon">👁️</span>プレビュー</button>' +
        '<button class="sv-ctx-item" data-action="download"><span class="sv-ctx-icon">⬇️</span>ダウンロード</button>' +
        '<div class="sv-ctx-sep"></div>' +
        '<button class="sv-ctx-item sv-ctx-danger" data-action="delete"><span class="sv-ctx-icon">🗑️</span>削除</button>';

    ctxEl.querySelector('[data-action="preview"]').onclick = function() { previewFile(fileId); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="download"]').onclick = function() { downloadFile(fileId); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="delete"]').onclick = function() { deleteFile(fileId); closeCtxMenu(); };

    positionCtxMenu(e);
}

function showBackgroundContextMenu(e) {
    ctxEl = document.createElement('div');
    ctxEl.className = 'sv-ctx-menu';
    ctxEl.innerHTML =
        '<button class="sv-ctx-item" data-action="new-folder"><span class="sv-ctx-icon">📁</span>新しいフォルダ</button>' +
        '<button class="sv-ctx-item" data-action="upload"><span class="sv-ctx-icon">📤</span>ファイルをアップロード</button>' +
        '<button class="sv-ctx-item" data-action="album"><span class="sv-ctx-icon">🖼️</span>アルバムで追加（最大50枚）</button>' +
        '<div class="sv-ctx-sep"></div>' +
        '<div class="sv-ctx-item sv-ctx-submenu-label" style="pointer-events:none;color:#9ca3af;font-size:11px">表示を変更</div>' +
        '<button class="sv-ctx-item" data-action="view-text"><span class="sv-ctx-icon">📋</span>テキストで見る</button>' +
        '<button class="sv-ctx-item" data-action="view-grid"><span class="sv-ctx-icon">🖼️</span>画像で小さく見る</button>' +
        '<div class="sv-ctx-sep"></div>' +
        '<button class="sv-ctx-item" data-action="refresh"><span class="sv-ctx-icon">🔄</span>最新の情報に更新</button>' +
        '<div class="sv-ctx-sep"></div>' +
        '<button class="sv-ctx-item" data-action="perm"><span class="sv-ctx-icon">🔒</span>メンバー権限</button>';

    ctxEl.querySelector('[data-action="new-folder"]').onclick = function() { createNewFolder(); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="upload"]').onclick = function() { document.getElementById('svFileInput').click(); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="album"]').onclick = function() { var inp = document.getElementById('svAlbumInput'); if (inp) inp.click(); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="view-text"]').onclick = function() { setStorageViewMode('text'); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="view-grid"]').onclick = function() { setStorageViewMode('grid'); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="refresh"]').onclick = function() { loadFolders(); loadUsage(); closeCtxMenu(); };
    ctxEl.querySelector('[data-action="perm"]').onclick = function() { openPermModal(); };

    positionCtxMenu(e);
}

function positionCtxMenu(e) {
    document.body.appendChild(ctxEl);
    var rect = ctxEl.getBoundingClientRect();
    var x = e.clientX;
    var y = e.clientY;
    if (x + rect.width > window.innerWidth) x = window.innerWidth - rect.width - 8;
    if (y + rect.height > window.innerHeight) y = window.innerHeight - rect.height - 8;
    ctxEl.style.left = x + 'px';
    ctxEl.style.top = y + 'px';

    setTimeout(function() {
        document.addEventListener('click', closeCtxMenu, { once: true });
    }, 10);
}

function closeCtxMenu() {
    if (ctxEl && ctxEl.parentNode) ctxEl.parentNode.removeChild(ctxEl);
    ctxEl = null;
}
window.closeCtxMenu = closeCtxMenu;

// ============================================
// ドラッグ&ドロップ（中央パネル限定）
// ============================================

function setupDragDrop() {
    var svContent = document.getElementById('svContent');
    if (!svContent) return;
    var counter = 0;

    svContent.addEventListener('dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
        counter++;
        svContent.classList.add('sv-drop-active');
    });

    svContent.addEventListener('dragleave', function(e) {
        e.stopPropagation();
        counter--;
        if (counter <= 0) { counter = 0; svContent.classList.remove('sv-drop-active'); }
    });

    svContent.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        e.dataTransfer.dropEffect = 'copy';
    });

    svContent.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        counter = 0;
        svContent.classList.remove('sv-drop-active');
        if (e.dataTransfer.files && e.dataTransfer.files.length) {
            uploadFiles(Array.from(e.dataTransfer.files));
        }
    });

    document.addEventListener('dragover', function(e) {
        if (!isVaultOpen()) return;
        var sv = document.getElementById('svContent');
        if (sv && !sv.contains(e.target)) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'none';
        }
    });

    document.addEventListener('drop', function(e) {
        if (!isVaultOpen()) return;
        var sv = document.getElementById('svContent');
        if (sv && !sv.contains(e.target)) {
            e.preventDefault();
        }
    });
}

function isVaultOpen() {
    var v = document.getElementById('storageVaultView');
    return v && v.style.display !== 'none';
}

// ============================================
// ユーティリティ
// ============================================

function escHtml(str) {
    var d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

function formatSize(bytes) {
    bytes = parseInt(bytes) || 0;
    if (bytes === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(1024));
    i = Math.min(i, units.length - 1);
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
}

})();
