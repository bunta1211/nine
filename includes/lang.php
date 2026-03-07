<?php
/**
 * 多言語対応ファイル
 * 
 * 使用方法:
 * 1. このファイルをインクルード
 * 2. __('key') または t('key') で翻訳テキストを取得
 */

// セッションから言語を取得（デフォルト: 日本語）
function getCurrentLanguage() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return $_SESSION['language'] ?? 'ja';
}

// 言語を設定
function setLanguage($lang) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $validLanguages = ['ja', 'en', 'zh'];
    if (in_array($lang, $validLanguages)) {
        $_SESSION['language'] = $lang;
        return true;
    }
    return false;
}

// 翻訳辞書
$translations = [
    // ============================================
    // 共通
    // ============================================
    'app_name' => [
        'ja' => 'Social9',
        'en' => 'Social9',
        'zh' => 'Social9'
    ],
    'save' => [
        'ja' => '保存',
        'en' => 'Save',
        'zh' => '保存'
    ],
    'cancel' => [
        'ja' => 'キャンセル',
        'en' => 'Cancel',
        'zh' => '取消'
    ],
    'delete' => [
        'ja' => '削除',
        'en' => 'Delete',
        'zh' => '删除'
    ],
    'edit' => [
        'ja' => '編集',
        'en' => 'Edit',
        'zh' => '编辑'
    ],
    'back' => [
        'ja' => '戻る',
        'en' => 'Back',
        'zh' => '返回'
    ],
    'search' => [
        'ja' => '検索',
        'en' => 'Search',
        'zh' => '搜索'
    ],
    'loading' => [
        'ja' => '読み込み中...',
        'en' => 'Loading...',
        'zh' => '加载中...'
    ],
    'error' => [
        'ja' => 'エラー',
        'en' => 'Error',
        'zh' => '错误'
    ],
    'success' => [
        'ja' => '成功',
        'en' => 'Success',
        'zh' => '成功'
    ],
    'avatar' => [
        'ja' => 'アバター',
        'en' => 'Avatar',
        'zh' => '头像'
    ],
    'change_avatar' => [
        'ja' => 'アイコンを変更',
        'en' => 'Change Avatar',
        'zh' => '更换头像'
    ],
    'choose_sample' => [
        'ja' => 'サンプルから選択',
        'en' => 'Choose from samples',
        'zh' => '从示例中选择'
    ],
    'upload_image' => [
        'ja' => '画像をアップロード',
        'en' => 'Upload Image',
        'zh' => '上传图片'
    ],
    'or' => [
        'ja' => 'または',
        'en' => 'or',
        'zh' => '或者'
    ],
    
    // ============================================
    // 設定ページ
    // ============================================
    'settings' => [
        'ja' => '設定',
        'en' => 'Settings',
        'zh' => '设置'
    ],
    'back_to_chat' => [
        'ja' => 'チャットに戻る',
        'en' => 'Back to Chat',
        'zh' => '返回聊天'
    ],
    
    // 設定メニュー
    'settings_basic' => [
        'ja' => '基本設定',
        'en' => 'Basic Settings',
        'zh' => '基本设置'
    ],
    'settings_profile' => [
        'ja' => 'プロフィール',
        'en' => 'Profile',
        'zh' => '个人资料'
    ],
    'settings_notification' => [
        'ja' => '通知',
        'en' => 'Notifications',
        'zh' => '通知'
    ],
    'settings_talk' => [
        'ja' => 'トーク',
        'en' => 'Talk',
        'zh' => '聊天'
    ],
    'settings_call' => [
        'ja' => '通話',
        'en' => 'Calls',
        'zh' => '通话'
    ],
    'settings_friends' => [
        'ja' => '友だち管理',
        'en' => 'Friends',
        'zh' => '好友管理'
    ],
    'settings_data' => [
        'ja' => 'データ',
        'en' => 'Data',
        'zh' => '数据'
    ],
    'settings_advanced' => [
        'ja' => '詳細設定',
        'en' => 'Advanced',
        'zh' => '高级设置'
    ],
    'settings_shortcuts' => [
        'ja' => 'ショートカット',
        'en' => 'Shortcuts',
        'zh' => '快捷键'
    ],
    'settings_about' => [
        'ja' => 'Social9情報',
        'en' => 'About Social9',
        'zh' => '关于Social9'
    ],
    
    // 基本設定
    'full_name' => [
        'ja' => '氏名（本名）',
        'en' => 'Full Name',
        'zh' => '姓名（真名）'
    ],
    'display_name' => [
        'ja' => '表示名（ニックネーム）',
        'en' => 'Display Name (Nickname)',
        'zh' => '显示名（昵称）'
    ],
    'full_name_hint' => [
        'ja' => '※目上などに表示される名前',
        'en' => '* Name shown to superiors',
        'zh' => '※显示给上级的名字'
    ],
    'display_name_hint' => [
        'ja' => '※チャットで相手に見える名前',
        'en' => '* Name visible to chat partners',
        'zh' => '※聊天时对方看到的名字'
    ],
    'save_name' => [
        'ja' => '名前を保存',
        'en' => 'Save Name',
        'zh' => '保存名字'
    ],
    'language' => [
        'ja' => '言語 / Language',
        'en' => 'Language',
        'zh' => '语言 / Language'
    ],
    'language_hint' => [
        'ja' => '※インターフェースの言語',
        'en' => '* Interface language',
        'zh' => '※界面语言'
    ],
    'font' => [
        'ja' => 'フォント',
        'en' => 'Font',
        'zh' => '字体'
    ],
    'font_basic' => [
        'ja' => '基本フォント',
        'en' => 'Basic Font',
        'zh' => '基本字体'
    ],
    'reload_notice' => [
        'ja' => '言語を変更しました。ページをリロードしてください。',
        'en' => 'Language changed. Please reload the page.',
        'zh' => '语言已更改。请刷新页面。'
    ],
    'reload_button' => [
        'ja' => '今すぐリロード',
        'en' => 'Reload Now',
        'zh' => '立即刷新'
    ],
    
    // プロフィール
    'profile_image' => [
        'ja' => 'プロフィール画像',
        'en' => 'Profile Image',
        'zh' => '头像'
    ],
    'change_image' => [
        'ja' => '画像を変更',
        'en' => 'Change Image',
        'zh' => '更换图片'
    ],
    'status_message' => [
        'ja' => 'ステータスメッセージ',
        'en' => 'Status Message',
        'zh' => '状态消息'
    ],
    'status_message_placeholder' => [
        'ja' => 'ステータスメッセージを入力...',
        'en' => 'Enter status message...',
        'zh' => '输入状态消息...'
    ],
    'save_profile' => [
        'ja' => 'プロフィールを保存',
        'en' => 'Save Profile',
        'zh' => '保存个人资料'
    ],
    
    // 通知
    'notification_settings' => [
        'ja' => '通知設定',
        'en' => 'Notification Settings',
        'zh' => '通知设置'
    ],
    'message_notification' => [
        'ja' => 'メッセージ通知',
        'en' => 'Message Notifications',
        'zh' => '消息通知'
    ],
    'message_notification_desc' => [
        'ja' => '新しいメッセージを受信したときに通知',
        'en' => 'Notify when receiving new messages',
        'zh' => '收到新消息时通知'
    ],
    'sound_notification' => [
        'ja' => '通知音',
        'en' => 'Notification Sound',
        'zh' => '通知声音'
    ],
    'sound_notification_desc' => [
        'ja' => '通知時にサウンドを再生',
        'en' => 'Play sound on notification',
        'zh' => '通知时播放声音'
    ],
    'group_notification' => [
        'ja' => 'グループ通知',
        'en' => 'Group Notifications',
        'zh' => '群组通知'
    ],
    'group_notification_desc' => [
        'ja' => 'グループメッセージの通知',
        'en' => 'Notifications for group messages',
        'zh' => '群组消息通知'
    ],
    'save_notification' => [
        'ja' => '通知設定を保存',
        'en' => 'Save Notification Settings',
        'zh' => '保存通知设置'
    ],
    
    // トーク設定
    'talk_settings' => [
        'ja' => 'トーク設定',
        'en' => 'Talk Settings',
        'zh' => '聊天设置'
    ],
    'enter_to_send' => [
        'ja' => 'Enterキーで送信',
        'en' => 'Send with Enter Key',
        'zh' => '按Enter键发送'
    ],
    'enter_to_send_desc' => [
        'ja' => 'Enterで送信、Shift+Enterで改行',
        'en' => 'Enter to send, Shift+Enter for new line',
        'zh' => 'Enter发送，Shift+Enter换行'
    ],
    'link_preview' => [
        'ja' => 'リンクプレビュー',
        'en' => 'Link Preview',
        'zh' => '链接预览'
    ],
    'link_preview_desc' => [
        'ja' => 'URLのプレビューを表示',
        'en' => 'Show URL previews',
        'zh' => '显示URL预览'
    ],
    'read_receipt' => [
        'ja' => '既読表示',
        'en' => 'Read Receipt',
        'zh' => '已读显示'
    ],
    'read_receipt_desc' => [
        'ja' => 'メッセージの既読状態を表示',
        'en' => 'Show read status of messages',
        'zh' => '显示消息已读状态'
    ],
    'save_talk' => [
        'ja' => 'トーク設定を保存',
        'en' => 'Save Talk Settings',
        'zh' => '保存聊天设置'
    ],
    
    // 通話設定
    'call_settings' => [
        'ja' => '通話設定',
        'en' => 'Call Settings',
        'zh' => '通话设置'
    ],
    'camera' => [
        'ja' => 'カメラ',
        'en' => 'Camera',
        'zh' => '摄像头'
    ],
    'microphone' => [
        'ja' => 'マイク',
        'en' => 'Microphone',
        'zh' => '麦克风'
    ],
    'speaker' => [
        'ja' => 'スピーカー',
        'en' => 'Speaker',
        'zh' => '扬声器'
    ],
    'default_device' => [
        'ja' => 'デフォルト',
        'en' => 'Default',
        'zh' => '默认'
    ],
    'save_call' => [
        'ja' => '通話設定を保存',
        'en' => 'Save Call Settings',
        'zh' => '保存通话设置'
    ],
    
    // 友だち管理
    'friends_management' => [
        'ja' => '友だち管理',
        'en' => 'Friends Management',
        'zh' => '好友管理'
    ],
    'blocked_users' => [
        'ja' => 'ブロックリスト',
        'en' => 'Blocked Users',
        'zh' => '屏蔽列表'
    ],
    'no_blocked_users' => [
        'ja' => 'ブロックしているユーザーはいません',
        'en' => 'No blocked users',
        'zh' => '没有被屏蔽的用户'
    ],
    'hidden_users' => [
        'ja' => '非表示リスト',
        'en' => 'Hidden Users',
        'zh' => '隐藏列表'
    ],
    'no_hidden_users' => [
        'ja' => '非表示にしているユーザーはいません',
        'en' => 'No hidden users',
        'zh' => '没有被隐藏的用户'
    ],
    
    // データ
    'data_management' => [
        'ja' => 'データ管理',
        'en' => 'Data Management',
        'zh' => '数据管理'
    ],
    'storage_usage' => [
        'ja' => 'ストレージ使用量',
        'en' => 'Storage Usage',
        'zh' => '存储使用量'
    ],
    'clear_cache' => [
        'ja' => 'キャッシュをクリア',
        'en' => 'Clear Cache',
        'zh' => '清除缓存'
    ],
    'export_data' => [
        'ja' => 'データをエクスポート',
        'en' => 'Export Data',
        'zh' => '导出数据'
    ],
    'delete_all_data' => [
        'ja' => 'すべてのデータを削除',
        'en' => 'Delete All Data',
        'zh' => '删除所有数据'
    ],
    
    // 詳細設定
    'advanced_settings' => [
        'ja' => '詳細設定',
        'en' => 'Advanced Settings',
        'zh' => '高级设置'
    ],
    'developer_mode' => [
        'ja' => '開発者モード',
        'en' => 'Developer Mode',
        'zh' => '开发者模式'
    ],
    'developer_mode_desc' => [
        'ja' => 'デバッグ情報を表示',
        'en' => 'Show debug information',
        'zh' => '显示调试信息'
    ],
    'beta_features' => [
        'ja' => 'ベータ機能',
        'en' => 'Beta Features',
        'zh' => '测试功能'
    ],
    'beta_features_desc' => [
        'ja' => '実験的な機能を有効化',
        'en' => 'Enable experimental features',
        'zh' => '启用实验性功能'
    ],
    
    // ショートカット
    'shortcuts' => [
        'ja' => 'ショートカット',
        'en' => 'Shortcuts',
        'zh' => '快捷键'
    ],
    'shortcut_search' => [
        'ja' => '検索を開く',
        'en' => 'Open Search',
        'zh' => '打开搜索'
    ],
    'shortcut_send' => [
        'ja' => 'メッセージを送信',
        'en' => 'Send Message',
        'zh' => '发送消息'
    ],
    'shortcut_newline' => [
        'ja' => '改行',
        'en' => 'New Line',
        'zh' => '换行'
    ],
    'shortcut_settings' => [
        'ja' => '設定を開く',
        'en' => 'Open Settings',
        'zh' => '打开设置'
    ],
    'shortcut_new_chat' => [
        'ja' => '新しい会話',
        'en' => 'New Chat',
        'zh' => '新建会话'
    ],
    
    // Social9情報
    'about_social9' => [
        'ja' => 'Social9情報',
        'en' => 'About Social9',
        'zh' => '关于Social9'
    ],
    'version' => [
        'ja' => 'バージョン',
        'en' => 'Version',
        'zh' => '版本'
    ],
    'operator' => [
        'ja' => '運営',
        'en' => 'Operated by',
        'zh' => '运营'
    ],
    'about_description' => [
        'ja' => 'Social9は「すべての人々（1〜9）」のためのチャットアプリです。子どもから大人まで安心して使えて、地域の明るい豊かな社会を実現していくプラットフォームを目指しています。',
        'en' => 'Social9 is a chat app for "everyone (1-9)". We aim to be a platform that is safe for everyone from children to adults, and helps build a bright and prosperous community.',
        'zh' => 'Social9是一款面向"所有人（1〜9）"的聊天应用。我们致力于打造一个从儿童到成人都能安心使用的平台，实现光明富裕的社区。'
    ],
    'terms_of_service' => [
        'ja' => '利用規約',
        'en' => 'Terms of Service',
        'zh' => '服务条款'
    ],
    'privacy_policy' => [
        'ja' => 'プライバシーポリシー',
        'en' => 'Privacy Policy',
        'zh' => '隐私政策'
    ],
    'contact' => [
        'ja' => 'お問い合わせ',
        'en' => 'Contact',
        'zh' => '联系我们'
    ],
    
    // ============================================
    // チャットページ
    // ============================================
    'chat' => [
        'ja' => 'チャット',
        'en' => 'Chat',
        'zh' => '聊天'
    ],
    'message_placeholder' => [
        'ja' => 'メッセージを入力...',
        'en' => 'Type a message...',
        'zh' => '输入消息...'
    ],
    'send' => [
        'ja' => '送信',
        'en' => 'Send',
        'zh' => '发送'
    ],
    'online' => [
        'ja' => 'オンライン',
        'en' => 'Online',
        'zh' => '在线'
    ],
    'offline' => [
        'ja' => 'オフライン',
        'en' => 'Offline',
        'zh' => '离线'
    ],
    'members' => [
        'ja' => 'メンバー',
        'en' => 'Members',
        'zh' => '成员'
    ],
    'reply' => [
        'ja' => '返信',
        'en' => 'Reply',
        'zh' => '回复'
    ],
    'reaction' => [
        'ja' => 'リアクション',
        'en' => 'Reaction',
        'zh' => '表情'
    ],
    'memo' => [
        'ja' => 'メモ',
        'en' => 'Memo',
        'zh' => '备忘录'
    ],
    'wish' => [
        'ja' => 'タスク',
        'en' => 'Task',
        'zh' => '任务'
    ],
    'translate' => [
        'ja' => '翻訳',
        'en' => 'Translate',
        'zh' => '翻译'
    ],
    
    // ============================================
    // チャットページ追加
    // ============================================
    'new_chat' => [
        'ja' => '新規チャット',
        'en' => 'New Chat',
        'zh' => '新建聊天'
    ],
    'group' => [
        'ja' => 'グループ',
        'en' => 'Group',
        'zh' => '群组'
    ],
    'conversation' => [
        'ja' => '会話',
        'en' => 'Conversation',
        'zh' => '会话'
    ],
    'dm' => [
        'ja' => 'ダイレクトメッセージ',
        'en' => 'Direct Message',
        'zh' => '私信'
    ],
    'pinned' => [
        'ja' => 'ピン留め',
        'en' => 'Pinned',
        'zh' => '置顶'
    ],
    'all_chats' => [
        'ja' => 'すべてのチャット',
        'en' => 'All Chats',
        'zh' => '所有聊天'
    ],
    'no_messages' => [
        'ja' => 'メッセージがありません',
        'en' => 'No messages',
        'zh' => '没有消息'
    ],
    'type_message' => [
        'ja' => 'メッセージを入力...',
        'en' => 'Type a message...',
        'zh' => '输入消息...'
    ],
    'send_message' => [
        'ja' => '送信',
        'en' => 'Send',
        'zh' => '发送'
    ],
    'attach_file' => [
        'ja' => 'ファイルを添付',
        'en' => 'Attach file',
        'zh' => '附加文件'
    ],
    'show_more' => [
        'ja' => '他 %d 件を表示',
        'en' => 'Show %d more',
        'zh' => '显示其他 %d 项'
    ],
    'overview' => [
        'ja' => '概要',
        'en' => 'Overview',
        'zh' => '概览'
    ],
    'media' => [
        'ja' => 'メディア',
        'en' => 'Media',
        'zh' => '媒体'
    ],
    'files' => [
        'ja' => 'ファイル',
        'en' => 'Files',
        'zh' => '文件'
    ],
    'links' => [
        'ja' => 'リンク',
        'en' => 'Links',
        'zh' => '链接'
    ],
    'add_member' => [
        'ja' => 'メンバーを追加',
        'en' => 'Add Member',
        'zh' => '添加成员'
    ],
    'leave_group' => [
        'ja' => 'グループを退出',
        'en' => 'Leave Group',
        'zh' => '退出群组'
    ],
    'mute' => [
        'ja' => 'ミュート',
        'en' => 'Mute',
        'zh' => '静音'
    ],
    'unmute' => [
        'ja' => 'ミュート解除',
        'en' => 'Unmute',
        'zh' => '取消静音'
    ],
    
    // ============================================
    // メモページ
    // ============================================
    'memos' => [
        'ja' => 'メモ',
        'en' => 'Memos',
        'zh' => '备忘录'
    ],
    'new_memo' => [
        'ja' => '新規メモ',
        'en' => 'New Memo',
        'zh' => '新建备忘录'
    ],
    'no_memos' => [
        'ja' => 'メモがありません',
        'en' => 'No memos',
        'zh' => '没有备忘录'
    ],
    'memo_saved' => [
        'ja' => 'メモを保存しました',
        'en' => 'Memo saved',
        'zh' => '备忘录已保存'
    ],
    'memo_deleted' => [
        'ja' => 'メモを削除しました',
        'en' => 'Memo deleted',
        'zh' => '备忘录已删除'
    ],
    'go_to_original' => [
        'ja' => '元のメッセージへ',
        'en' => 'Go to original message',
        'zh' => '转到原始消息'
    ],
    
    // ============================================
    // 通知ページ
    // ============================================
    'notifications' => [
        'ja' => '通知',
        'en' => 'Notifications',
        'zh' => '通知'
    ],
    'language' => [
        'ja' => '言語',
        'en' => 'Language',
        'zh' => '语言'
    ],
    'no_notifications' => [
        'ja' => '通知がありません',
        'en' => 'No notifications',
        'zh' => '没有通知'
    ],
    'mark_all_read' => [
        'ja' => 'すべて既読にする',
        'en' => 'Mark all as read',
        'zh' => '全部标为已读'
    ],
    'today' => [
        'ja' => '今日',
        'en' => 'Today',
        'zh' => '今天'
    ],
    'yesterday' => [
        'ja' => '昨日',
        'en' => 'Yesterday',
        'zh' => '昨天'
    ],
    'this_week' => [
        'ja' => '今週',
        'en' => 'This Week',
        'zh' => '本周'
    ],
    'older' => [
        'ja' => 'それ以前',
        'en' => 'Older',
        'zh' => '更早'
    ],
    
    // ============================================
    // プロフィールページ
    // ============================================
    'profile' => [
        'ja' => 'プロフィール',
        'en' => 'Profile',
        'zh' => '个人资料'
    ],
    'edit_profile' => [
        'ja' => 'プロフィールを編集',
        'en' => 'Edit Profile',
        'zh' => '编辑个人资料'
    ],
    'bio' => [
        'ja' => '自己紹介',
        'en' => 'Bio',
        'zh' => '个人简介'
    ],
    'bio_placeholder' => [
        'ja' => '自己紹介を入力してください',
        'en' => 'Enter your bio',
        'zh' => '请输入个人简介'
    ],
    'prefecture' => [
        'ja' => '都道府県',
        'en' => 'Prefecture',
        'zh' => '都道府县'
    ],
    'select_prefecture' => [
        'ja' => '選択してください',
        'en' => 'Select',
        'zh' => '请选择'
    ],
    'city' => [
        'ja' => '市区町村',
        'en' => 'City',
        'zh' => '市区町村'
    ],
    'city_placeholder' => [
        'ja' => '市区町村を入力',
        'en' => 'Enter city',
        'zh' => '请输入市区町村'
    ],
    'phone' => [
        'ja' => '携帯電話',
        'en' => 'Mobile phone',
        'zh' => '手机'
    ],
    'phone_hint' => [
        'ja' => '個人を特定する検索に利用されます。ハイフンなしで入力',
        'en' => 'Used for user search. Enter without hyphens',
        'zh' => '用于用户搜索。请输入不含连字符的号码'
    ],
    'save_settings' => [
        'ja' => '設定を保存',
        'en' => 'Save Settings',
        'zh' => '保存设置'
    ],
    'location' => [
        'ja' => '居住地',
        'en' => 'Location',
        'zh' => '位置'
    ],
    'birthday' => [
        'ja' => '生年月日',
        'en' => 'Birthday',
        'zh' => '生日'
    ],
    'joined' => [
        'ja' => '登録日',
        'en' => 'Joined',
        'zh' => '注册日期'
    ],
    
    // ============================================
    // デザインページ
    // ============================================
    'design' => [
        'ja' => 'デザイン',
        'en' => 'Design',
        'zh' => '设计'
    ],
    'theme' => [
        'ja' => 'テーマ',
        'en' => 'Theme',
        'zh' => '主题'
    ],
    'style' => [
        'ja' => 'スタイル',
        'en' => 'Style',
        'zh' => '风格'
    ],
    'background' => [
        'ja' => '背景',
        'en' => 'Background',
        'zh' => '背景'
    ],
    'accent_color' => [
        'ja' => 'アクセントカラー',
        'en' => 'Accent Color',
        'zh' => '强调色'
    ],
    'preview' => [
        'ja' => 'プレビュー',
        'en' => 'Preview',
        'zh' => '预览'
    ],
    'apply' => [
        'ja' => '適用',
        'en' => 'Apply',
        'zh' => '应用'
    ],
    'reset' => [
        'ja' => 'リセット',
        'en' => 'Reset',
        'zh' => '重置'
    ],
    
    // ============================================
    // ログインページ
    // ============================================
    'login' => [
        'ja' => 'ログイン',
        'en' => 'Login',
        'zh' => '登录'
    ],
    'logout' => [
        'ja' => 'ログアウト',
        'en' => 'Logout',
        'zh' => '退出登录'
    ],
    'email' => [
        'ja' => 'メールアドレス',
        'en' => 'Email',
        'zh' => '电子邮件'
    ],
    'password' => [
        'ja' => 'パスワード',
        'en' => 'Password',
        'zh' => '密码'
    ],
    'remember_me' => [
        'ja' => 'ログイン状態を保持',
        'en' => 'Remember me',
        'zh' => '记住我'
    ],
    'forgot_password' => [
        'ja' => 'パスワードを忘れた方',
        'en' => 'Forgot password?',
        'zh' => '忘记密码？'
    ],
    'register' => [
        'ja' => '新規登録',
        'en' => 'Register',
        'zh' => '注册'
    ],
    'login_error' => [
        'ja' => 'メールアドレスまたはパスワードが正しくありません',
        'en' => 'Invalid email or password',
        'zh' => '电子邮件或密码错误'
    ],
    'email_auth' => [
        'ja' => 'メール認証',
        'en' => 'Email Authentication',
        'zh' => '邮箱验证'
    ],
    'password_login' => [
        'ja' => 'パスワードログイン',
        'en' => 'Password Login',
        'zh' => '密码登录'
    ],
    'send_auth_code' => [
        'ja' => '認証コードを送信',
        'en' => 'Send Auth Code',
        'zh' => '发送验证码'
    ],
    
    // ============================================
    // 通話ページ
    // ============================================
    'voice_call' => [
        'ja' => '音声通話',
        'en' => 'Voice Call',
        'zh' => '语音通话'
    ],
    'video_call' => [
        'ja' => 'ビデオ通話',
        'en' => 'Video Call',
        'zh' => '视频通话'
    ],
    'calling' => [
        'ja' => '発信中...',
        'en' => 'Calling...',
        'zh' => '呼叫中...'
    ],
    'incoming_call' => [
        'ja' => '着信',
        'en' => 'Incoming Call',
        'zh' => '来电'
    ],
    'end_call' => [
        'ja' => '通話終了',
        'en' => 'End Call',
        'zh' => '结束通话'
    ],
    'answer' => [
        'ja' => '応答',
        'en' => 'Answer',
        'zh' => '接听'
    ],
    'decline' => [
        'ja' => '拒否',
        'en' => 'Decline',
        'zh' => '拒绝'
    ],
    
    // ============================================
    // 共通UI要素
    // ============================================
    'confirm' => [
        'ja' => '確認',
        'en' => 'Confirm',
        'zh' => '确认'
    ],
    'close' => [
        'ja' => '閉じる',
        'en' => 'Close',
        'zh' => '关闭'
    ],
    'ok' => [
        'ja' => 'OK',
        'en' => 'OK',
        'zh' => '确定'
    ],
    'yes' => [
        'ja' => 'はい',
        'en' => 'Yes',
        'zh' => '是'
    ],
    'no' => [
        'ja' => 'いいえ',
        'en' => 'No',
        'zh' => '否'
    ],
    'create' => [
        'ja' => '作成',
        'en' => 'Create',
        'zh' => '创建'
    ],
    'update' => [
        'ja' => '更新',
        'en' => 'Update',
        'zh' => '更新'
    ],
    'add' => [
        'ja' => '追加',
        'en' => 'Add',
        'zh' => '添加'
    ],
    'remove' => [
        'ja' => '削除',
        'en' => 'Remove',
        'zh' => '移除'
    ],
    'select' => [
        'ja' => '選択',
        'en' => 'Select',
        'zh' => '选择'
    ],
    'all' => [
        'ja' => 'すべて',
        'en' => 'All',
        'zh' => '全部'
    ],
    'none' => [
        'ja' => 'なし',
        'en' => 'None',
        'zh' => '无'
    ],
    'more' => [
        'ja' => 'もっと見る',
        'en' => 'More',
        'zh' => '更多'
    ],
    'less' => [
        'ja' => '閉じる',
        'en' => 'Less',
        'zh' => '收起'
    ],
    'copy' => [
        'ja' => 'コピー',
        'en' => 'Copy',
        'zh' => '复制'
    ],
    'copied' => [
        'ja' => 'コピーしました',
        'en' => 'Copied',
        'zh' => '已复制'
    ],
    'share' => [
        'ja' => '共有',
        'en' => 'Share',
        'zh' => '分享'
    ],
    'download' => [
        'ja' => 'ダウンロード',
        'en' => 'Download',
        'zh' => '下载'
    ],
    'upload' => [
        'ja' => 'アップロード',
        'en' => 'Upload',
        'zh' => '上传'
    ],
    'refresh' => [
        'ja' => '更新',
        'en' => 'Refresh',
        'zh' => '刷新'
    ],
    'retry' => [
        'ja' => '再試行',
        'en' => 'Retry',
        'zh' => '重试'
    ],
    'saved' => [
        'ja' => '保存しました',
        'en' => 'Saved',
        'zh' => '已保存'
    ],
    'saving' => [
        'ja' => '保存中...',
        'en' => 'Saving...',
        'zh' => '保存中...'
    ],
    'deleted' => [
        'ja' => '削除しました',
        'en' => 'Deleted',
        'zh' => '已删除'
    ],
    'deleting' => [
        'ja' => '削除中...',
        'en' => 'Deleting...',
        'zh' => '删除中...'
    ],
    'are_you_sure' => [
        'ja' => '本当によろしいですか？',
        'en' => 'Are you sure?',
        'zh' => '您确定吗？'
    ],
    'action_cannot_undone' => [
        'ja' => 'この操作は取り消せません',
        'en' => 'This action cannot be undone',
        'zh' => '此操作无法撤销'
    ],
    
    // ============================================
    // 時間表現
    // ============================================
    'just_now' => [
        'ja' => 'たった今',
        'en' => 'Just now',
        'zh' => '刚刚'
    ],
    'minutes_ago' => [
        'ja' => '%d分前',
        'en' => '%d minutes ago',
        'zh' => '%d分钟前'
    ],
    'hours_ago' => [
        'ja' => '%d時間前',
        'en' => '%d hours ago',
        'zh' => '%d小时前'
    ],
    'days_ago' => [
        'ja' => '%d日前',
        'en' => '%d days ago',
        'zh' => '%d天前'
    ],
    
    // ============================================
    // エラーメッセージ
    // ============================================
    'error_occurred' => [
        'ja' => 'エラーが発生しました',
        'en' => 'An error occurred',
        'zh' => '发生错误'
    ],
    'network_error' => [
        'ja' => 'ネットワークエラー',
        'en' => 'Network error',
        'zh' => '网络错误'
    ],
    'server_error' => [
        'ja' => 'サーバーエラー',
        'en' => 'Server error',
        'zh' => '服务器错误'
    ],
    'session_expired' => [
        'ja' => 'セッションが切れました。再度ログインしてください',
        'en' => 'Session expired. Please login again',
        'zh' => '会话已过期，请重新登录'
    ],
    'permission_denied' => [
        'ja' => '権限がありません',
        'en' => 'Permission denied',
        'zh' => '权限不足'
    ],
    'not_found' => [
        'ja' => '見つかりません',
        'en' => 'Not found',
        'zh' => '未找到'
    ],
    'invalid_input' => [
        'ja' => '入力が正しくありません',
        'en' => 'Invalid input',
        'zh' => '输入无效'
    ],
    'required_field' => [
        'ja' => 'この項目は必須です',
        'en' => 'This field is required',
        'zh' => '此字段为必填项'
    ],
    
    // ============================================
    // 左パネル（チャット一覧）
    // ============================================
    'add_friend' => [
        'ja' => '+ 友達追加',
        'en' => '+ Add Friend',
        'zh' => '+ 添加好友'
    ],
    'add_group' => [
        'ja' => 'グループ追加',
        'en' => 'Add Group',
        'zh' => '添加群组'
    ],
    'all' => [
        'ja' => 'すべて',
        'en' => 'All',
        'zh' => '全部'
    ],
    'unread' => [
        'ja' => '未読',
        'en' => 'Unread',
        'zh' => '未读'
    ],
    'filter_friends' => [
        'ja' => '友達',
        'en' => 'Friends',
        'zh' => '好友'
    ],
    'filter_org_label' => [
        'ja' => '表示する組織',
        'en' => 'Organization',
        'zh' => '显示组织'
    ],
    'filter_org_all' => [
        'ja' => 'すべての組織',
        'en' => 'All organizations',
        'zh' => '所有组织'
    ],
    'show_less' => [
        'ja' => '表示を減らす',
        'en' => 'Show Less',
        'zh' => '收起'
    ],
    
    // ============================================
    // メッセージ入力欄
    // ============================================
    'message_input_placeholder' => [
        'ja' => 'ここにメッセージ内容を入力',
        'en' => 'Type your message here',
        'zh' => '在此输入消息内容'
    ],
    'shift_enter_hint' => [
        'ja' => '(Shift + Enterキーで送信)',
        'en' => '(Shift + Enter to send)',
        'zh' => '(Shift + Enter发送)'
    ],
    'enter_to_send_label' => [
        'ja' => 'Enterで送信',
        'en' => 'Enter to send',
        'zh' => 'Enter发送'
    ],
    
    // ============================================
    // 検索モーダル
    // ============================================
    'search_placeholder' => [
        'ja' => 'メッセージ・グループを検索。人を探す場合はメールアドレスまたは携帯番号を入力...',
        'en' => 'Search messages, groups. To find a person, enter email or phone number...',
        'zh' => '搜索消息、群组。找人请输入邮箱或手机号...'
    ],
    'messages' => [
        'ja' => 'メッセージ',
        'en' => 'Messages',
        'zh' => '消息'
    ],
    'users' => [
        'ja' => 'ユーザー',
        'en' => 'Users',
        'zh' => '用户'
    ],
    'groups' => [
        'ja' => 'グループ',
        'en' => 'Groups',
        'zh' => '群组'
    ],
    'recent_search' => [
        'ja' => '最近の検索',
        'en' => 'Recent Searches',
        'zh' => '最近搜索'
    ],
    'no_search_history' => [
        'ja' => '検索履歴はありません',
        'en' => 'No search history',
        'zh' => '没有搜索记录'
    ],
    'search_hint' => [
        'ja' => 'メッセージ、ユーザー、グループを検索できます',
        'en' => 'Search for messages, users, and groups',
        'zh' => '可以搜索消息、用户和群组'
    ],
    
    // ============================================
    // 通知ページ
    // ============================================
    'notification_list' => [
        'ja' => '通知一覧',
        'en' => 'Notification List',
        'zh' => '通知列表'
    ],
    'no_notifications_desc' => [
        'ja' => '新しい通知があるとここに表示されます',
        'en' => 'New notifications will appear here',
        'zh' => '新通知将显示在这里'
    ],
    'admin_notifications' => [
        'ja' => '運営',
        'en' => 'Admin',
        'zh' => '运营'
    ],
    'mentions' => [
        'ja' => 'メンション',
        'en' => 'Mentions',
        'zh' => '提及'
    ],
    
    // ============================================
    // タスク・メモページ（旧Wishをタスクに統一）
    // ============================================
    'wish_memo' => [
        'ja' => 'タスク・メモ',
        'en' => 'Task & Memo',
        'zh' => '任务・备忘录'
    ],
    'my_wish' => [
        'ja' => 'マイタスク',
        'en' => 'My Tasks',
        'zh' => '我的任务'
    ],
    'add_wish' => [
        'ja' => 'タスク追加',
        'en' => 'Add Task',
        'zh' => '添加任务'
    ],
    'no_wish' => [
        'ja' => 'タスクはありません',
        'en' => 'No tasks yet',
        'zh' => '还没有任务'
    ],
    'no_wish_desc' => [
        'ja' => '「タスク追加」ボタンで新しいタスクを作成しましょう',
        'en' => 'Create a new task with the "Add Task" button',
        'zh' => '点击"添加任务"按钮创建新任务'
    ],
    'open_task' => [
        'ja' => 'タスクを開く',
        'en' => 'Open Task',
        'zh' => '打开任务'
    ],
    'task_added' => [
        'ja' => 'タスクに追加しました',
        'en' => 'Added to task',
        'zh' => '已添加到任务'
    ],
    'my_memo' => [
        'ja' => 'マイメモ',
        'en' => 'My Memos',
        'zh' => '我的备忘录'
    ],
    'add_memo' => [
        'ja' => 'メモ追加',
        'en' => 'Add Memo',
        'zh' => '添加备忘录'
    ],
    
    // ============================================
    // デザインページ
    // ============================================
    'design_settings' => [
        'ja' => 'デザイン設定',
        'en' => 'Design Settings',
        'zh' => '设计设置'
    ],
    'theme_forest' => [
        'ja' => 'フォレスト',
        'en' => 'Forest',
        'zh' => '森林'
    ],
    'theme_ocean' => [
        'ja' => 'オーシャン',
        'en' => 'Ocean',
        'zh' => '海洋'
    ],
    'theme_sunset' => [
        'ja' => 'サンセット',
        'en' => 'Sunset',
        'zh' => '日落'
    ],
    'theme_lavender' => [
        'ja' => 'ラベンダー',
        'en' => 'Lavender',
        'zh' => '薰衣草'
    ],
    'theme_cherry' => [
        'ja' => 'チェリー',
        'en' => 'Cherry',
        'zh' => '樱桃'
    ],
    'theme_transparent' => [
        'ja' => '透明',
        'en' => 'Transparent',
        'zh' => '透明'
    ],
    'theme_transparent_light' => [
        'ja' => '透明（ライト）',
        'en' => 'Transparent (Light)',
        'zh' => '透明（亮）'
    ],
    'theme_transparent_dark' => [
        'ja' => '透明（ダーク）',
        'en' => 'Transparent (Dark)',
        'zh' => '透明（暗）'
    ],
    'background_image' => [
        'ja' => '背景画像',
        'en' => 'Background Image',
        'zh' => '背景图片'
    ],
    'custom' => [
        'ja' => 'カスタム',
        'en' => 'Custom',
        'zh' => '自定义'
    ],
    
    // ============================================
    // 右パネル（詳細）
    // ============================================
    'details' => [
        'ja' => '詳細',
        'en' => 'Details',
        'zh' => '详情'
    ],
    'group_management' => [
        'ja' => 'グループ管理',
        'en' => 'Group management',
        'zh' => '群组管理'
    ],
    'input_title' => [
        'ja' => 'タイトルを入力',
        'en' => 'Enter title',
        'zh' => '输入标题'
    ],
    'input_url' => [
        'ja' => 'URLを入力 (YouTube等)',
        'en' => 'Enter URL (YouTube, etc.)',
        'zh' => '输入URL (YouTube等)'
    ],
    'no_media' => [
        'ja' => 'メディアはありません',
        'en' => 'No media',
        'zh' => '没有媒体'
    ],
    'ai_consult' => [
        'ja' => 'AIに相談',
        'en' => 'Ask AI',
        'zh' => '咨询AI'
    ],
];

/**
 * 翻訳テキストを取得
 * @param string $key 翻訳キー
 * @param string|null $lang 言語（省略時はセッションの言語）
 * @return string 翻訳されたテキスト
 */
function __($key, $lang = null) {
    global $translations;
    
    if ($lang === null) {
        $lang = getCurrentLanguage();
    }
    
    if (isset($translations[$key][$lang])) {
        return $translations[$key][$lang];
    }
    
    // フォールバック: 日本語 → 英語 → キーそのまま
    if (isset($translations[$key]['ja'])) {
        return $translations[$key]['ja'];
    }
    if (isset($translations[$key]['en'])) {
        return $translations[$key]['en'];
    }
    
    return $key;
}

// エイリアス関数
function t($key, $lang = null) {
    return __($key, $lang);
}

/**
 * 言語オプションを取得
 */
function getLanguageOptions() {
    return [
        'ja' => '🇯🇵 日本語',
        'en' => '🇺🇸 English',
        'zh' => '🇨🇳 中文'
    ];
}

// ============================================
// 多言語名前取得ヘルパー関数
// ============================================

/**
 * グループ名などで name_en/name_zh が未設定のときの翻訳辞書（日本語 → 各言語）
 * 会話名・グループ名でよく使う語を登録。DBに name_en が無い場合のフォールバック用。
 * @return array [ '日本語' => [ 'en' => 'English', 'zh' => '中文' ], ... ]
 */
function getGroupNameTranslationDictionary() {
    return [
        '事務局' => ['en' => 'Secretariat', 'zh' => '秘书处'],
        '労務' => ['en' => 'Labor', 'zh' => '劳动'],
        '労務グループ' => ['en' => 'Labor Service', 'zh' => '劳动服务'],
        '社内便' => ['en' => 'In-house Delivery', 'zh' => '内部邮件'],
        'アプリ開発' => ['en' => 'App Development', 'zh' => '应用开发'],
        '総務' => ['en' => 'General Affairs', 'zh' => '总务'],
        '人事' => ['en' => 'Human Resources', 'zh' => '人力资源'],
        '経理' => ['en' => 'Accounting', 'zh' => '会计'],
        '会計' => ['en' => 'Accounting', 'zh' => '会计'],
    ];
}

/**
 * グループ名の辞書フォールバック（name_en 未設定時）
 * @param string $nameJa 日本語の名前
 * @param string $lang 対象言語（en, zh など）
 * @return string 翻訳語。辞書に無い場合は空文字
 */
function getGroupNameTranslationFallback($nameJa, $lang) {
    if ($lang === 'ja' || trim($nameJa ?? '') === '') {
        return '';
    }
    $dict = getGroupNameTranslationDictionary();
    $nameJa = trim($nameJa);
    if (isset($dict[$nameJa][$lang])) {
        return $dict[$nameJa][$lang];
    }
    return '';
}

/**
 * 多言語対応の名前を取得
 * エンティティの言語別カラムから現在の言語に対応した値を取得
 * フォールバック: 選択言語のカラム → グループ名辞書（name のみ）→ 日本語 → 英語 → 空
 * 
 * @param array $item エンティティデータ（name, name_en, name_zh などを含む）
 * @param string $field ベースフィールド名（デフォルト: 'name'）
 * @return string 現在の言語に対応した名前
 * 
 * 使用例:
 *   $groupName = getLocalizedName($conversation, 'name');
 *   $userName = getLocalizedName($user, 'display_name');
 */
function getLocalizedName($item, $field = 'name') {
    if (!is_array($item)) {
        return '';
    }
    
    $lang = getCurrentLanguage();
    
    // 日本語の場合はベースフィールドをそのまま返す
    if ($lang === 'ja') {
        return $item[$field] ?? '';
    }
    
    // 言語別カラム名
    $langField = $field . '_' . $lang;
    
    // 選択言語のカラムに値がある場合
    if (!empty($item[$langField])) {
        return $item[$langField];
    }
    
    // name フィールド用: DBに言語別が無い場合、よく使うグループ名の辞書でフォールバック（例: 事務局→Secretariat）
    $baseName = trim($item[$field] ?? '');
    if ($baseName !== '' && $field === 'name') {
        $fallback = getGroupNameTranslationFallback($baseName, $lang);
        if ($fallback !== '') {
            return $fallback;
        }
    }
    
    // 日本語（デフォルト）にフォールバック
    if ($baseName !== '') {
        return $baseName;
    }
    
    // 英語にフォールバック
    $enField = $field . '_en';
    if (!empty($item[$enField])) {
        return $item[$enField];
    }
    
    return '';
}

/**
 * 多言語対応の説明を取得
 * getLocalizedNameのエイリアス（可読性のため）
 * 
 * @param array $item エンティティデータ
 * @param string $field ベースフィールド名（デフォルト: 'description'）
 * @return string 現在の言語に対応した説明
 */
function getLocalizedDescription($item, $field = 'description') {
    return getLocalizedName($item, $field);
}

/**
 * 多言語入力用のフォームフィールドを生成
 * 
 * @param string $fieldName フィールド名（例: 'name', 'display_name'）
 * @param array $values 現在の値（['ja' => '...', 'en' => '...', 'zh' => '...']）
 * @param bool $jaRequired 日本語を必須にするか
 * @return string HTMLフォーム
 */
function renderI18nInputFields($fieldName, $values = [], $jaRequired = true) {
    $labels = [
        'ja' => '🇯🇵 日本語',
        'en' => '🇺🇸 English',
        'zh' => '🇨🇳 中文'
    ];
    
    $placeholders = [
        'ja' => '',
        'en' => 'Optional',
        'zh' => '可选'
    ];
    
    $html = '<div class="i18n-input-group">';
    
    foreach ($labels as $lang => $label) {
        $inputName = $lang === 'ja' ? $fieldName : $fieldName . '_' . $lang;
        $value = htmlspecialchars($values[$lang] ?? '');
        $required = ($lang === 'ja' && $jaRequired) ? 'required' : '';
        $placeholder = $placeholders[$lang];
        $requiredMark = ($lang === 'ja' && $jaRequired) ? '<span class="required">*</span>' : '';
        
        $html .= <<<HTML
        <div class="form-group i18n-field">
            <label>{$label} {$requiredMark}</label>
            <input type="text" name="{$inputName}" value="{$value}" placeholder="{$placeholder}" {$required}>
        </div>
HTML;
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * 多言語入力用のテキストエリアを生成
 * 
 * @param string $fieldName フィールド名（例: 'description'）
 * @param array $values 現在の値
 * @param bool $jaRequired 日本語を必須にするか
 * @param int $rows 行数
 * @return string HTMLフォーム
 */
function renderI18nTextareaFields($fieldName, $values = [], $jaRequired = false, $rows = 3) {
    $labels = [
        'ja' => '🇯🇵 日本語',
        'en' => '🇺🇸 English',
        'zh' => '🇨🇳 中文'
    ];
    
    $placeholders = [
        'ja' => '',
        'en' => 'Optional',
        'zh' => '可选'
    ];
    
    $html = '<div class="i18n-input-group">';
    
    foreach ($labels as $lang => $label) {
        $inputName = $lang === 'ja' ? $fieldName : $fieldName . '_' . $lang;
        $value = htmlspecialchars($values[$lang] ?? '');
        $required = ($lang === 'ja' && $jaRequired) ? 'required' : '';
        $placeholder = $placeholders[$lang];
        $requiredMark = ($lang === 'ja' && $jaRequired) ? '<span class="required">*</span>' : '';
        
        $html .= <<<HTML
        <div class="form-group i18n-field">
            <label>{$label} {$requiredMark}</label>
            <textarea name="{$inputName}" placeholder="{$placeholder}" rows="{$rows}" {$required}>{$value}</textarea>
        </div>
HTML;
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * POSTデータから多言語フィールドを抽出
 * 
 * @param array $post POSTデータ
 * @param string $fieldName ベースフィールド名
 * @return array ['ja' => '...', 'en' => '...', 'zh' => '...']
 */
function extractI18nFields($post, $fieldName) {
    return [
        'ja' => trim($post[$fieldName] ?? ''),
        'en' => trim($post[$fieldName . '_en'] ?? ''),
        'zh' => trim($post[$fieldName . '_zh'] ?? '')
    ];
}

/**
 * 多言語フィールドをDBカラム形式に変換
 * 
 * @param array $i18nValues extractI18nFieldsの結果
 * @param string $fieldName ベースフィールド名
 * @return array DB更新用の配列 ['name' => '...', 'name_en' => '...', 'name_zh' => '...']
 */
function i18nToDbColumns($i18nValues, $fieldName) {
    return [
        $fieldName => $i18nValues['ja'],
        $fieldName . '_en' => $i18nValues['en'] ?: null,
        $fieldName . '_zh' => $i18nValues['zh'] ?: null
    ];
}

