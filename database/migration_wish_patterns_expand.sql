-- Wish パターン拡充マイグレーション
-- 基本パターンを150-200に拡充

-- ============================================
-- 日本語パターン: 願望・希望
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
-- 〜したい系の拡張
('(.+)がしたい', 'desire', '願望', '〜がしたい形式', '旅行がしたい', '旅行がしたい', 1, 60, 0),
('(.+)をやりたい', 'desire', '願望', '〜をやりたい形式', 'プログラミングをやりたい', 'プログラミングをやりたい', 1, 60, 0),
('(.+)やりたい', 'desire', '願望', '〜やりたい形式', '料理やりたい', '料理やりたい', 1, 55, 0),
('(.+)をしてみたい', 'desire', '願望', '〜をしてみたい形式', 'スカイダイビングをしてみたい', 'スカイダイビングをしてみたい', 1, 60, 0),
('(.+)してみたい', 'desire', '願望', '〜してみたい形式', '一人旅してみたい', '一人旅してみたい', 1, 55, 0),
('(.+)を試したい', 'desire', '願望', '〜を試したい形式', '新しいレストランを試したい', '新しいレストランを試したい', 1, 60, 0),
('(.+)を体験したい', 'desire', '願望', '〜を体験したい形式', 'VRを体験したい', 'VRを体験したい', 1, 60, 0),

-- 〜ればいい系
('(.+)ればいいな', 'desire', '願望', '〜ればいいな形式', '晴れればいいな', '晴れればいいな', 1, 50, 0),
('(.+)といいな', 'desire', '願望', '〜といいな形式', '成功するといいな', '成功するといいな', 1, 50, 0),
('(.+)だといいな', 'desire', '願望', '〜だといいな形式', '合格だといいな', '合格だといいな', 1, 50, 0),
('(.+)ばいいのに', 'desire', '願望', '〜ばいいのに形式', '休みが増えればいいのに', '休みが増えればいいのに', 1, 50, 0),
('(.+)たらいいな', 'desire', '願望', '〜たらいいな形式', '会えたらいいな', '会えたらいいな', 1, 50, 0),

-- ============================================
-- 日本語パターン: 依頼・お願い
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(.+)してほしい', 'request', '依頼', '〜してほしい形式', '手伝ってほしい', '手伝ってほしい', 1, 65, 0),
('(.+)して欲しい', 'request', '依頼', '〜して欲しい形式', '確認して欲しい', '確認して欲しい', 1, 65, 0),
('(.+)してくれない', 'request', '依頼', '〜してくれない？形式', '送ってくれない', '送ってくれない', 1, 55, 0),
('(.+)してもらえる', 'request', '依頼', '〜してもらえる？形式', '教えてもらえる', '教えてもらえる', 1, 55, 0),
('(.+)してもらいたい', 'request', '依頼', '〜してもらいたい形式', '参加してもらいたい', '参加してもらいたい', 1, 60, 0),
('(.+)をお願い', 'request', '依頼', '〜をお願い形式', '確認をお願い', '確認をお願い', 1, 65, 0),
('(.+)お願いします', 'request', '依頼', '〜お願いします形式', 'よろしくお願いします', 'よろしくお願いします', 1, 60, 0),
('(.+)頼みたい', 'request', '依頼', '〜頼みたい形式', '仕事を頼みたい', '仕事を頼みたい', 1, 60, 0),
('(.+)を頼む', 'request', '依頼', '〜を頼む形式', '買い物を頼む', '買い物を頼む', 1, 55, 0),
('(.+)任せたい', 'request', '依頼', '〜任せたい形式', 'プロジェクトを任せたい', 'プロジェクトを任せたい', 1, 55, 0),

-- ============================================
-- 日本語パターン: 必要性
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(.+)が必要', 'need', '必要', '〜が必要形式', '休息が必要', '休息が必要', 1, 60, 0),
('(.+)を必要として', 'need', '必要', '〜を必要として形式', '助けを必要として', '助けを必要として', 1, 55, 0),
('(.+)しなければ', 'need', '必要', '〜しなければ形式', '勉強しなければ', '勉強しなければ', 1, 55, 0),
('(.+)しないと', 'need', '必要', '〜しないと形式', '急がないと', '急がないと', 1, 55, 0),
('(.+)しなきゃ', 'need', '必要', '〜しなきゃ形式', '片付けしなきゃ', '片付けしなきゃ', 1, 55, 0),
('(.+)しなくちゃ', 'need', '必要', '〜しなくちゃ形式', '買い物しなくちゃ', '買い物しなくちゃ', 1, 55, 0),
('(.+)する必要がある', 'need', '必要', '〜する必要がある形式', '確認する必要がある', '確認する必要がある', 1, 60, 0),
('(.+)すべき', 'need', '必要', '〜すべき形式', '改善すべき', '改善すべき', 1, 55, 0),
('(.+)べきだ', 'need', '必要', '〜べきだ形式', '謝るべきだ', '謝るべきだ', 1, 55, 0),

-- ============================================
-- 日本語パターン: 問題・困りごと（変換用）
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(.+)が壊れた', 'problem', '問題', '〜が壊れた→修理したい', '洗濯機が壊れた', '洗濯機を修理したい', 1, 45, 0),
('(.+)が故障', 'problem', '問題', '〜が故障→修理したい', 'エアコンが故障', 'エアコンを修理したい', 1, 45, 0),
('(.+)が動かない', 'problem', '問題', '〜が動かない→直したい', 'パソコンが動かない', 'パソコンを直したい', 1, 45, 0),
('(.+)で困って', 'problem', '問題', '〜で困って→解決したい', '仕事で困って', '仕事の問題を解決したい', 1, 45, 0),
('(.+)に困って', 'problem', '問題', '〜に困って→解決したい', 'お金に困って', 'お金の問題を解決したい', 1, 45, 0),
('(.+)がわからない', 'problem', '問題', '〜がわからない→知りたい', '使い方がわからない', '使い方を知りたい', 1, 45, 0),
('(.+)がない', 'problem', '問題', '〜がない→欲しい', '時間がない', '時間が欲しい', 1, 40, 0),
('(.+)が足りない', 'problem', '問題', '〜が足りない→欲しい', 'お金が足りない', 'お金が欲しい', 1, 45, 0),
('(.+)が不足', 'problem', '問題', '〜が不足→補充したい', '人手が不足', '人手を補充したい', 1, 45, 0),

-- ============================================
-- 日本語パターン: 予定・計画
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(.+)する予定', 'plan', '予定', '〜する予定形式', '来週旅行する予定', '来週旅行する予定', 1, 50, 0),
('(.+)するつもり', 'plan', '予定', '〜するつもり形式', '転職するつもり', '転職するつもり', 1, 50, 0),
('(.+)しようと思う', 'plan', '予定', '〜しようと思う形式', '勉強しようと思う', '勉強しようと思う', 1, 55, 0),
('(.+)しようかな', 'plan', '予定', '〜しようかな形式', '運動しようかな', '運動しようかな', 1, 50, 0),
('(.+)を計画', 'plan', '予定', '〜を計画形式', '旅行を計画', '旅行を計画', 1, 50, 0),
('(.+)を企画', 'plan', '予定', '〜を企画形式', 'イベントを企画', 'イベントを企画', 1, 50, 0),
('(.+)を検討', 'plan', '予定', '〜を検討形式', '導入を検討', '導入を検討', 1, 50, 0),

-- ============================================
-- 日本語パターン: 購入・入手
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(.+)を購入したい', 'purchase', '購入', '〜を購入したい形式', '新車を購入したい', '新車を購入したい', 1, 60, 0),
('(.+)を手に入れたい', 'purchase', '購入', '〜を手に入れたい形式', 'チケットを手に入れたい', 'チケットを手に入れたい', 1, 60, 0),
('(.+)をゲットしたい', 'purchase', '購入', '〜をゲットしたい形式', '限定品をゲットしたい', '限定品をゲットしたい', 1, 55, 0),
('(.+)を注文したい', 'purchase', '購入', '〜を注文したい形式', 'ピザを注文したい', 'ピザを注文したい', 1, 55, 0),
('(.+)を予約したい', 'purchase', '購入', '〜を予約したい形式', 'ホテルを予約したい', 'ホテルを予約したい', 1, 60, 0),

-- ============================================
-- 日本語パターン: 学習・習得
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(.+)を学びたい', 'learn', '学習', '〜を学びたい形式', 'プログラミングを学びたい', 'プログラミングを学びたい', 1, 60, 0),
('(.+)を習いたい', 'learn', '学習', '〜を習いたい形式', 'ピアノを習いたい', 'ピアノを習いたい', 1, 60, 0),
('(.+)を覚えたい', 'learn', '学習', '〜を覚えたい形式', '英単語を覚えたい', '英単語を覚えたい', 1, 60, 0),
('(.+)を勉強したい', 'learn', '学習', '〜を勉強したい形式', '歴史を勉強したい', '歴史を勉強したい', 1, 60, 0),
('(.+)をマスターしたい', 'learn', '学習', '〜をマスターしたい形式', '英語をマスターしたい', '英語をマスターしたい', 1, 60, 0),
('(.+)を身につけたい', 'learn', '学習', '〜を身につけたい形式', 'スキルを身につけたい', 'スキルを身につけたい', 1, 60, 0),
('(.+)を理解したい', 'learn', '学習', '〜を理解したい形式', '仕組みを理解したい', '仕組みを理解したい', 1, 55, 0),
('(.+)を知りたい', 'learn', '学習', '〜を知りたい形式', '真実を知りたい', '真実を知りたい', 1, 55, 0),

-- ============================================
-- 日本語パターン: 改善・変化
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(.+)を改善したい', 'improve', '改善', '〜を改善したい形式', '生活習慣を改善したい', '生活習慣を改善したい', 1, 60, 0),
('(.+)を直したい', 'improve', '改善', '〜を直したい形式', '悪い癖を直したい', '悪い癖を直したい', 1, 60, 0),
('(.+)を変えたい', 'improve', '改善', '〜を変えたい形式', '仕事を変えたい', '仕事を変えたい', 1, 60, 0),
('(.+)をやめたい', 'improve', '改善', '〜をやめたい形式', 'タバコをやめたい', 'タバコをやめたい', 1, 60, 0),
('(.+)を減らしたい', 'improve', '改善', '〜を減らしたい形式', '体重を減らしたい', '体重を減らしたい', 1, 60, 0),
('(.+)を増やしたい', 'improve', '改善', '〜を増やしたい形式', '収入を増やしたい', '収入を増やしたい', 1, 60, 0),
('(.+)になりたい', 'improve', '改善', '〜になりたい形式', '健康になりたい', '健康になりたい', 1, 55, 0),
('(.+)くなりたい', 'improve', '改善', '〜くなりたい形式', '強くなりたい', '強くなりたい', 1, 55, 0),

-- ============================================
-- 日本語パターン: 会いたい・連絡
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(.+)に会いたい', 'social', '社交', '〜に会いたい形式', '友達に会いたい', '友達に会いたい', 1, 60, 0),
('(.+)と会いたい', 'social', '社交', '〜と会いたい形式', '先生と会いたい', '先生と会いたい', 1, 60, 0),
('(.+)に連絡したい', 'social', '社交', '〜に連絡したい形式', '田中さんに連絡したい', '田中さんに連絡したい', 1, 55, 0),
('(.+)と話したい', 'social', '社交', '〜と話したい形式', '上司と話したい', '上司と話したい', 1, 55, 0),
('(.+)と相談したい', 'social', '社交', '〜と相談したい形式', '専門家と相談したい', '専門家と相談したい', 1, 55, 0),

-- ============================================
-- 英語パターン: 願望
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(?:I |i )?want to (.+)', 'desire', 'Desire', 'want to pattern', 'I want to travel', 'travel', 1, 60, 1),
('(?:I |i )?(?:would like|''d like) to (.+)', 'desire', 'Desire', 'would like to pattern', 'I would like to learn', 'learn', 1, 60, 1),
('(?:I |i )?wish (?:I could |to )?(.+)', 'desire', 'Desire', 'wish pattern', 'I wish I could fly', 'fly', 1, 55, 1),
('(?:I |i )?hope to (.+)', 'desire', 'Desire', 'hope to pattern', 'I hope to succeed', 'succeed', 1, 55, 1),
('(?:I |i )?dream of (.+)', 'desire', 'Desire', 'dream of pattern', 'I dream of becoming rich', 'becoming rich', 1, 50, 1),
('(?:I |i )?am looking for (.+)', 'desire', 'Desire', 'looking for pattern', 'I am looking for a job', 'a job', 1, 50, 1),
('(?:I |i )?need (.+)', 'need', 'Need', 'need pattern', 'I need help', 'help', 1, 60, 1),
('(?:I |i )?have to (.+)', 'need', 'Need', 'have to pattern', 'I have to study', 'study', 1, 55, 1),
('(?:I |i )?must (.+)', 'need', 'Need', 'must pattern', 'I must finish', 'finish', 1, 55, 1),
('(?:I |i )?should (.+)', 'need', 'Need', 'should pattern', 'I should exercise', 'exercise', 1, 50, 1),
('(?:I |i )?''m going to (.+)', 'plan', 'Plan', 'going to pattern', 'I''m going to travel', 'travel', 1, 50, 1),
('(?:I |i )?plan to (.+)', 'plan', 'Plan', 'plan to pattern', 'I plan to study abroad', 'study abroad', 1, 55, 1),
('(?:I |i )?intend to (.+)', 'plan', 'Plan', 'intend to pattern', 'I intend to quit', 'quit', 1, 55, 1),

-- ============================================
-- 英語パターン: 依頼
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(?:can|could|would) you (?:please )?(.+?)(?:\\?)?$', 'request', 'Request', 'can you pattern', 'Could you help me?', 'help me', 1, 60, 1),
('(?:please |Please )(.+?)(?:\\?)?$', 'request', 'Request', 'please pattern', 'Please send the file', 'send the file', 1, 60, 1),
('(?:I |i )?(?:''d |would )?appreciate (?:it )?if you (?:could )?(.+)', 'request', 'Request', 'appreciate if pattern', 'I''d appreciate if you could review', 'review', 1, 55, 1),
('(?:I |i )?was wondering if you could (.+)', 'request', 'Request', 'wondering if pattern', 'I was wondering if you could assist', 'assist', 1, 55, 1),

-- ============================================
-- 中国語パターン: 願望
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(?:我)?想(?:要)?(.+)', 'desire', '愿望', '想要 pattern', '我想要学习', '学习', 1, 60, 1),
('(?:我)?希望(.+)', 'desire', '愿望', '希望 pattern', '我希望成功', '成功', 1, 60, 1),
('(?:我)?渴望(.+)', 'desire', '愿望', '渴望 pattern', '我渴望自由', '自由', 1, 55, 1),
('(?:我)?期待(.+)', 'desire', '愿望', '期待 pattern', '我期待明天', '明天', 1, 55, 1),
('(?:我)?盼望(.+)', 'desire', '愿望', '盼望 pattern', '我盼望见到你', '见到你', 1, 55, 1),

-- ============================================
-- 中国語パターン: 必要性
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(?:我)?需要(.+)', 'need', '需要', '需要 pattern', '我需要帮助', '帮助', 1, 60, 1),
('(?:我)?必须(.+)', 'need', '需要', '必须 pattern', '我必须工作', '工作', 1, 60, 1),
('(?:我)?应该(.+)', 'need', '需要', '应该 pattern', '我应该学习', '学习', 1, 55, 1),
('(?:我)?得(.+)', 'need', '需要', '得 pattern', '我得走了', '走了', 1, 50, 1),

-- ============================================
-- 中国語パターン: 依頼
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('请(?:你)?(.+)', 'request', '请求', '请 pattern', '请帮助我', '帮助我', 1, 60, 1),
('麻烦(?:你)?(.+)', 'request', '请求', '麻烦 pattern', '麻烦你帮忙', '帮忙', 1, 60, 1),
('能(?:不能|否)(.+)', 'request', '请求', '能不能 pattern', '能不能帮我', '帮我', 1, 55, 1),
('可以(.+)吗', 'request', '请求', '可以吗 pattern', '可以帮我吗', '帮我', 1, 55, 1),

-- ============================================
-- 中国語パターン: 計画
-- ============================================

INSERT IGNORE INTO wish_patterns (pattern, category, category_label, description, example_input, example_output, is_active, priority, extract_group) VALUES
('(?:我)?打算(.+)', 'plan', '计划', '打算 pattern', '我打算去旅行', '去旅行', 1, 55, 1),
('(?:我)?计划(.+)', 'plan', '计划', '计划 pattern', '我计划学习', '学习', 1, 55, 1),
('(?:我)?准备(.+)', 'plan', '计划', '准备 pattern', '我准备出发', '出发', 1, 55, 1),
('(?:我)?想买(.+)', 'purchase', '购买', '想买 pattern', '我想买手机', '手机', 1, 60, 1),
('(?:我)?想去(.+)', 'travel', '旅行', '想去 pattern', '我想去日本', '日本', 1, 60, 1),
('(?:我)?想(?:看|观看)(.+)', 'desire', '愿望', '想看 pattern', '我想看电影', '电影', 1, 60, 1),
('(?:我)?想吃(.+)', 'desire', '愿望', '想吃 pattern', '我想吃寿司', '寿司', 1, 60, 1),
('(?:我)?想学(.+)', 'learn', '学习', '想学 pattern', '我想学日语', '日语', 1, 60, 1);

-- パターン数を確認
SELECT COUNT(*) as total_patterns FROM wish_patterns;


