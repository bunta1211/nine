/**
 * メディア管理モジュール
 * 
 * ファイルアップロード、画像/動画表示、GIF検索
 * 
 * 使用例:
 * Chat.media.upload(file);
 * Chat.media.showPreview(url);
 * Chat.media.searchGif(query);
 */

(function(global) {
    'use strict';
    
    // チャット名前空間を確認
    global.Chat = global.Chat || {};
    
    // 設定（PDF・Office等は all に含め、添付送信で利用可能にする）
    const DEFAULT_MAX_SIZE = 10 * 1024 * 1024; // 10MB
    const ALLOWED_TYPES = {
        image: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        video: ['video/mp4', 'video/webm'],
        all: [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
            'video/mp4', 'video/webm', 'video/quicktime',
            'application/pdf',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain', 'text/csv',
            'application/zip', 'application/x-zip-compressed',
            'application/octet-stream'
        ]
    };
    
    // 内部状態
    let maxFileSize = DEFAULT_MAX_SIZE;
    let allowedTypes = ALLOWED_TYPES.all;
    let uploadInProgress = false;
    
    /**
     * 初期化
     * @param {Object} options - オプション
     */
    function init(options = {}) {
        if (options.maxFileSize) {
            maxFileSize = options.maxFileSize;
        }
        if (options.allowedTypes) {
            allowedTypes = options.allowedTypes;
        }
        
        console.log('[Media] Initialized');
    }
    
    /**
     * ファイルを検証
     * @param {File} file - ファイル
     * @returns {Object} { valid: boolean, error: string }
     */
    function validateFile(file) {
        if (!file) {
            return { valid: false, error: 'ファイルが選択されていません' };
        }
        
        if (file.size > maxFileSize) {
            const sizeMB = (maxFileSize / (1024 * 1024)).toFixed(0);
            return { valid: false, error: `ファイルサイズは${sizeMB}MB以下にしてください` };
        }
        
        if (!allowedTypes.includes(file.type)) {
            return { valid: false, error: '対応していないファイル形式です' };
        }
        
        return { valid: true };
    }
    
    /**
     * ファイルをアップロード
     * @param {File} file - ファイル
     * @param {Object} options - オプション
     * @returns {Promise<Object>} アップロード結果
     */
    async function upload(file, options = {}) {
        const validation = validateFile(file);
        if (!validation.valid) {
            return { success: false, error: validation.error };
        }
        
        if (uploadInProgress) {
            return { success: false, error: 'アップロード中です' };
        }
        
        uploadInProgress = true;
        
        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('file', file);
        
        if (options.conversationId) {
            formData.append('conversation_id', options.conversationId);
        }
        
        try {
            // プログレスコールバック
            if (options.onProgress) {
                // XHRでプログレスを取得
                return await uploadWithProgress(formData, options.onProgress);
            }
            
            const response = await fetch('api/upload.php', {
                method: 'POST',
                body: formData,
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });
            
            const text = await response.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                uploadInProgress = false;
                const txt = (text || '').trim();
                const isHtml = txt.length > 10 && (
                    txt.startsWith('<') || txt.toLowerCase().includes('<!doctype') || txt.toLowerCase().includes('<html')
                );
                const errMsg = isHtml
                    ? 'サーバーエラーが発生しました。ファイルは10MB以下にしてください。しばらく経ってからお試しください。'
                    : ('アップロードに失敗しました: ' + (text.substring(0, 60) || '不明なエラー'));
                return { success: false, error: errMsg };
            }
            
            uploadInProgress = false;
            return data;
        } catch (error) {
            uploadInProgress = false;
            console.error('[Media] Upload error:', error);
            return { success: false, error: 'アップロードに失敗しました' };
        }
    }
    
    /**
     * プログレス付きアップロード
     * @param {FormData} formData - フォームデータ
     * @param {Function} onProgress - プログレスコールバック
     * @returns {Promise<Object>}
     */
    function uploadWithProgress(formData, onProgress) {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    onProgress(percent);
                }
            });
            
            xhr.addEventListener('load', () => {
                uploadInProgress = false;
                try {
                    const data = JSON.parse(xhr.responseText);
                    resolve(data);
                } catch (e) {
                    resolve({ success: false, error: 'レスポンスの解析に失敗' });
                }
            });
            
            xhr.addEventListener('error', () => {
                uploadInProgress = false;
                resolve({ success: false, error: 'アップロードに失敗しました' });
            });
            
            xhr.open('POST', 'api/upload.php');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.send(formData);
        });
    }
    
    /**
     * 画像プレビューを表示
     * @param {string} url - 画像URL
     */
    function showPreview(url) {
        // モーダルを作成または取得
        let modal = document.getElementById('mediaPreviewModal');
        
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'mediaPreviewModal';
            modal.className = 'media-preview-modal';
            modal.innerHTML = `
                <div class="media-preview-backdrop" onclick="Chat.media.closePreview()"></div>
                <div class="media-preview-content">
                    <button class="media-preview-close" onclick="Chat.media.closePreview()">×</button>
                    <div class="media-preview-container"></div>
                </div>
            `;
            document.body.appendChild(modal);
        }
        
        const container = modal.querySelector('.media-preview-container');
        const fileType = Chat.utils ? Chat.utils.getFileType(url) : getFileTypeSimple(url);
        
        if (fileType === 'video') {
            container.innerHTML = `<video src="${url}" controls autoplay style="max-width:90vw; max-height:90vh;"></video>`;
        } else {
            container.innerHTML = `<img src="${url}" alt="Preview" style="max-width:90vw; max-height:90vh;">`;
        }
        
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    
    /**
     * プレビューを閉じる
     */
    function closePreview() {
        const modal = document.getElementById('mediaPreviewModal');
        if (modal) {
            modal.style.display = 'none';
            const video = modal.querySelector('video');
            if (video) {
                video.pause();
            }
        }
        document.body.style.overflow = '';
    }
    
    /**
     * シンプルなファイルタイプ判定
     * @param {string} url - URL
     * @returns {string} ファイルタイプ
     */
    function getFileTypeSimple(url) {
        const ext = url.split('.').pop().toLowerCase().split('?')[0];
        if (['mp4', 'webm', 'ogg', 'mov'].includes(ext)) return 'video';
        return 'image';
    }
    
    /**
     * GIF検索
     * @param {string} query - 検索クエリ
     * @returns {Promise<Array>} GIF配列
     */
    async function searchGif(query) {
        if (!query) return [];
        
        try {
            const response = await fetch(`api/gif.php?action=search&q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (data.success) {
                return data.gifs || [];
            } else {
                console.error('[Media] GIF search failed:', data.error);
                return [];
            }
        } catch (error) {
            console.error('[Media] GIF search error:', error);
            return [];
        }
    }
    
    /**
     * トレンドGIFを取得
     * @returns {Promise<Array>} GIF配列
     */
    async function getTrendingGifs() {
        try {
            const response = await fetch('api/gif.php?action=trending');
            const data = await response.json();
            
            if (data.success) {
                return data.gifs || [];
            }
            return [];
        } catch (error) {
            console.error('[Media] Trending GIFs error:', error);
            return [];
        }
    }
    
    /**
     * GIFピッカーを表示
     */
    function showGifPicker() {
        const picker = document.getElementById('gifPicker');
        if (picker) {
            picker.style.display = 'block';
            loadTrendingGifs();
        }
    }
    
    /**
     * GIFピッカーを非表示
     */
    function hideGifPicker() {
        const picker = document.getElementById('gifPicker');
        if (picker) {
            picker.style.display = 'none';
        }
    }
    
    /**
     * トレンドGIFを読み込んで表示
     */
    async function loadTrendingGifs() {
        const container = document.getElementById('gifResults');
        if (!container) return;
        
        container.innerHTML = '<div class="loading">読み込み中...</div>';
        
        const gifs = await getTrendingGifs();
        renderGifs(gifs);
    }
    
    /**
     * GIFを描画
     * @param {Array} gifs - GIF配列
     */
    function renderGifs(gifs) {
        const container = document.getElementById('gifResults');
        if (!container) return;
        
        if (gifs.length === 0) {
            container.innerHTML = '<div class="no-results">GIFが見つかりません</div>';
            return;
        }
        
        container.innerHTML = gifs.map(gif => `
            <div class="gif-item" onclick="Chat.media.selectGif('${gif.url}')">
                <img src="${gif.preview || gif.url}" alt="" loading="lazy">
            </div>
        `).join('');
    }
    
    /**
     * GIFを選択
     * @param {string} url - GIF URL
     */
    function selectGif(url) {
        hideGifPicker();
        
        // メッセージ入力欄にGIFを設定
        // 実際の実装はmessages.jsと連携
        if (window.insertGifToMessage) {
            window.insertGifToMessage(url);
        }
    }
    
    // 公開API
    Chat.media = {
        init,
        validateFile,
        upload,
        showPreview,
        closePreview,
        searchGif,
        getTrendingGifs,
        showGifPicker,
        hideGifPicker,
        renderGifs,
        selectGif
    };
    
    // グローバル関数との互換性
    global.uploadFile = upload;
    global.showMediaPreview = showPreview;
    global.closeMediaPreview = closePreview;
    global.searchGif = searchGif;
    global.toggleGifPicker = function() {
        const picker = document.getElementById('gifPicker');
        if (picker && picker.style.display === 'block') {
            hideGifPicker();
        } else {
            showGifPicker();
        }
    };
    
})(typeof window !== 'undefined' ? window : this);
