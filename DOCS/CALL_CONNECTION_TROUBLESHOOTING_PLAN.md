# ビデオ通話が繋がらない問題の多角的検証と解決策計画

通話が繋がらない原因の多角的再検証と、短期・中期・長期の解決策をまとめた計画書。  
既存: [CALL_VERIFICATION_AND_TROUBLESHOOTING.md](CALL_VERIFICATION_AND_TROUBLESHOOTING.md)、[CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md](CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md)。

## 現状の整理

- **現象**: 「通話に参加しています」のままスピナーが続き、相手の映像が表示されない。発信者に「ミーティングに参加」ボタン押下案内と 15 秒後の原因表示は実装済みだが、依然として繋がらないケースがある。
- **既知の主因**: **meet.jit.si では iframe 経由の `configOverwrite.startConference: true` が尊重されず**、会議が「モデレーター待ち」のまま開始されない。アプリ側では [call.php](../call.php) で `startConference: true` を送っているが、サーバー側で効かない。
- **補足**: コンソールの `chrome-extension://invalid/` の net::ERR_FAILED は通話と無関係。`favicon.ico` の 404 は通話接続の直接原因ではない（静的アセットの不足）。

---

## 1. 原因の多角的再検証（観点ごと）

### 1.1 会議が開始されない（モデレーター待ち）

| 検証項目 | 内容 |
|----------|------|
| **startConference の効き** | meet.jit.si は configOverwrite の `startConference` を **サーバー側で無視**することがある。Jitsi External API に「会議開始」をプログラムで叩くコマンドは **存在しない**（executeCommand に startMeeting 相当なし）。 |
| **ユーザー操作依存** | 発信者が Jitsi 画面内の「ミーティングに参加」を押さないと会議が start しない。案内は 3 秒後に表示済みだが、押し忘れ・ボタンが見つからない・モバイルでレイアウトが隠れる等で未実施の可能性。 |
| **着信者のタイミング** | 着信者が「出る」で join する時点で、発信者側でまだ会議が start していないと、同じ room_id に入っても「参加しています」のままになる。 |

**結論**: 根本要因は「meet.jit.si が会議を自動開始しない」こと。暫定は「発信者が必ず青いボタンを押す」運用。恒久は **自前 Jitsi で会議即開始を保証**する設定。

### 1.2 ネットワーク・リソース

| 検証項目 | 内容 |
|----------|------|
| **失敗 URL の特定** | net::ERR_FAILED は「どの URL が失敗したか」が Console だけでは分からない。**Network タブ**で Failed のリクエストを確認する必要がある。chrome-extension://invalid/ のみなら無視してよい。 |
| **external_api.js** | `https://meet.jit.si/external_api.js` が 200 で読めているか。本番の [config/app.php](../config/app.php) の JITSI_BASE_URL が正しいか。 |
| **WebSocket / XHR** | meet.jit.si ドメインへのシグナリング・メディア経路がファイアウォール・プロキシ・VPN でブロックされていないか。 |
| **TURN/STUN** | 厳しい NAT 環境では TURN が必要。meet.jit.si 単体では環境依存で、自前 Jitsi + coturn で改善可能（[PHONE_VIDEO_CALL_PLAN.md](PHONE_VIDEO_CALL_PLAN.md) 8.3）。 |

### 1.3 ブラウザ・権限

| 検証項目 | 内容 |
|----------|------|
| **マイク・カメラ** | 「マイクは正常に動作しています」と出ている場合は権限許可は取れている可能性が高い。 |
| **speaker-selection / web-hid** | コンソール警告は会議開始を止める直接原因ではならないことが多い。別ブラウザ・シークレットで比較すると切り分けになる。 |
| **拡張** | chrome-extension 系エラーは通話無関係。シークレットで試すと拡張の影響を除外できる。 |

### 1.4 設定・環境

| 検証項目 | 内容 |
|----------|------|
| **HTTPS** | call.php が `https://` で提供されているか。混合コンテンツでブロックされていないか。 |
| **room_id の一致** | 発信者は create の room_id、着信者は join の room_id で同じ値が返る設計（[api/calls.php](../api/calls.php)）。同線条件は満たしている。 |
| **leave の呼び出し** | 発信者がタブを閉じる前に leave が呼ばれていないと、call が ringing のまま残る。call.php の endCall / beforeunload で leave は実装済み。 |

### 1.5 その他（静的アセット）

| 検証項目 | 内容 |
|----------|------|
| **favicon.ico 404** | 通話の接続には無関係。ルートに favicon.ico を配置するか、[assets/icons/generate-icons.php](../assets/icons/generate-icons.php) 実行で `../../favicon.ico` にコピーされる。 |

---

## 2. 解決策の複数案（優先度順）

### 対策A: 即時できる UX・案内の強化（短期）

- 発信者向け案内を **より目立たせる**（「最初にやること」と明記・大きめフォント・必要に応じてアニメーション）。モバイルではオーバーレイをスクロール可能にして赤い原因表示に隠れないようにする。
- **接続チェックリスト**: 発信者に「1. 青い『ミーティングに参加』を押しましたか？ 2. まだなら Jitsi 画面の中央・下部を確認してください」を原因表示エリアに追加。
- [help/call-troubleshooting.php](../help/call-troubleshooting.php) で「発信者は必ず最初に『ミーティングに参加』を押す」を強調し、手順番号を追加。

### 対策B: 検証しやすくする（短期）

- 15 秒後の原因表示に「開発者向け: 繋がらない場合はブラウザの **Network タブ**で、meet.jit.si や social9.jp への Failed リクエストがないか確認してください」を一文追加。
- call.php の接続中オーバーレイに「詳細を表示」を置き、クリックで Jitsi ドメイン・room_id 等の簡易診断情報を表示する折りたたみを用意。

### 対策C: meet.jit.si の制約を回避する試み（中期・リスクあり）

- **prejoinPageEnabled: true** の検証（参加前確認画面が出る。影響範囲を要確認）。
- 8x8 Jitsi as a Service の検討（有料。PHONE_VIDEO_CALL_PLAN 参照）。

### 対策D: 根本対策 — 自前 Jitsi の構築（長期・推奨）

- [PHONE_VIDEO_CALL_PLAN.md](PHONE_VIDEO_CALL_PLAN.md) 8.2-B・8.6 のとおり、自前 Jitsi（JVB + Jicofo + Prosody）+ coturn を構築し、config.js で **everyoneIsModerator または startConference を保証**。
- [config/app.local.example.php](../config/app.local.example.php) で JITSI_DOMAIN / JITSI_BASE_URL を自前 Jitsi に切り替えるだけでアプリは対応済み。

### 対策E: その他の解消（軽微）

- ルートに `favicon.ico` を配置する（generate-icons で生成されるか、手元でデプロイ）。

---

## 3. 推奨実施順序

1. **すぐ実施**: 対策A（案内・チェックリスト強化）、対策E（favicon.ico）。対策B（Network タブ案内・詳細表示）は負荷が小さいため実施推奨。
2. **検証のみ**: 対策Cは仕様・コスト確認のうえで検証または検討。
3. **根本**: 対策D を [JITSI_SELFHOST_QUICKSTART.md](JITSI_SELFHOST_QUICKSTART.md) と PHONE_VIDEO_CALL_PLAN のフェーズに従い実施。

---

## 4. 参照ドキュメント

- [CALL_VERIFICATION_AND_TROUBLESHOOTING.md](CALL_VERIFICATION_AND_TROUBLESHOOTING.md) — 現象整理・検証手順・原因と対策の対応表
- [CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md](CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md) — 繋がらない主因・厳命事項・実装タスク
- [PHONE_VIDEO_CALL_PLAN.md](PHONE_VIDEO_CALL_PLAN.md) — 自前 Jitsi 構成（8.2-B）・会議即開始設定（8.6）
- [JITSI_SELFHOST_QUICKSTART.md](JITSI_SELFHOST_QUICKSTART.md) — 自前 Jitsi クイックスタート
- [help/call-troubleshooting.php](../help/call-troubleshooting.php) — ユーザー向けヘルプ

---

## 5. 実装タスク（対策A・B・E）

| # | 対策 | 対象ファイル | 内容 |
|---|------|--------------|------|
| 1 | A | call.php | 発信者案内を「最初にやること」と明記し、フォントをやや大きく。接続チェックリスト（1. 青いボタン押したか 2. 中央・下部を確認）を原因表示エリアに追加。 |
| 2 | A | help/call-troubleshooting.php | 「発信者は必ず最初に『ミーティングに参加』を押す」を手順番号で強調。 |
| 3 | B | call.php | 15 秒後の原因表示に「開発者向け: Network タブで Failed リクエストを確認」を追加。接続中オーバーレイに「詳細を表示」ボタンと、Jitsi ドメイン・room_id 等を表示する折りたたみを追加。 |
| 4 | E | ルート | favicon.ico を配置（assets/icons/favicon-32x32.png をコピーするか、既存生成スクリプトで生成）。 |

対策D は既存の PHONE_VIDEO_CALL_PLAN のフェーズに沿って実施する。
