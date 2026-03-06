# ビデオ通話が繋がらない問題の検証とトラブルシューティング

通話が「通話に参加しています」のまま繋がらない場合の検証手順と原因の切り分けをまとめる。  
関連: [CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md](CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md)、[PHONE_VIDEO_CALL_PLAN.md](PHONE_VIDEO_CALL_PLAN.md)。

---

## 1. 現象の整理（コンソール・画面の手がかり）

| 現象 | コンソール／画面の手がかり |
|------|----------------------------|
| 「通話に参加しています」のまま繋がらない | スピナーが続き、相手の映像が表示されない。タイマー 00:00 のまま。 |
| **net::ERR_FAILED** | DevTools Console に `Failed to load resource: net::ERR_FAILED`。**どの URL が失敗しているかは Console だけでは分からない**ため、Network タブで失敗したリクエストの URL を確認する。 |
| **chrome-extension://invalid/ の net::ERR_FAILED** | 失敗しているリソースが `chrome-extension://invalid/:1` の場合。**ブラウザまたは拡張機能（例: DevTools の AI 機能）が無効な拡張 URL を読もうとして出るもので、アプリ・Jitsi の通話とは無関係**。通話が繋がらない原因としては無視してよい。気になる場合はシークレットウィンドウや拡張無効で試す。 |
| **Unrecognized feature: 'speaker-selection'** | `external_api.is:364`。Jitsi がブラウザに speaker-selection を要求しているが、未対応・未認識。 |
| **RECORDING OFF SOUND** | `[app:sounds] PLAY SOUND: no sound found for id: RECORDING OFF SOUND`。音声アセット不足。 |
| **web-hid non-compliant** | `[app:web-hid] sendDeviceReport: There are currently non-compliant conditions`。デバイス／HID の条件が非準拠。 |

既存ドキュメントで指摘している「meet.jit.si では startConference が効かず会議が開始されない」も、引き続き有力な要因。

---

## 2. 検証の観点（別の角度から）

### 2.1 ネットワーク・リソース（net::ERR_FAILED の正体）

- **目的**: どの URL が `net::ERR_FAILED` で失敗しているかを特定する。
- **重要**: 失敗している URL が **`chrome-extension://invalid/`** のときは、ブラウザや DevTools（例: Console Insights / AI assistance）が無効な拡張を参照して出るエラーであり、**通話の原因ではない**。無視してよい。
- **手順**:
  1. call.php を開き、DevTools の **Network** タブを開いた状態で通話を開始する。
  2. ステータスが "Failed" または "(failed)" のリクエストを記録する（URL・種類・Initiator）。
  3. 特に確認するもの:
     - `https://meet.jit.si/external_api.js` が 200 で読めているか。
     - meet.jit.si ドメインへの XHR/fetch/WebSocket で失敗していないか。
     - TURN/STUN 用のリクエスト（通常は Jitsi 内部）がブロックされていないか。
- **想定原因**: ファイアウォール・プロキシ・VPN・社内ネットのポリシーで meet.jit.si または WebRTC 関連がブロックされている。または、一時的なネット障害。

### 2.2 ブラウザ・権限（speaker-selection / web-hid）

- **目的**: speaker-selection や web-hid のエラーが、メディア接続を阻害しているかを切り分ける。
- **手順**:
  1. 別ブラウザ（例: Edge / Firefox）で同じ call.php を開き、コンソールに同じエラーが出るか、繋がるかどうかを比較する。
  2. シークレットウィンドウ（拡張無効）で試し、拡張機能の影響を除外する。
  3. Chrome の `chrome://flags` で WebRTC や関連フラグが無効になっていないか確認する。
- **想定**: speaker-selection は Jitsi 側の Permission API の使い方に起因することが多く、**会議自体の開始を止める直接原因ではならない**ことが多い。ただし、一部環境では音声デバイス選択が失敗し、結果として「参加したが音声が出ない」になる可能性はある。web-hid は会議接続の必須ではないため、多くの場合は警告にとどまる。

### 2.3 会議開始（モデレーター待ち）

- **目的**: meet.jit.si で会議が開始されず「参加しています」のまま止まっていないかを確認する。
- **手順**:
  1. 発信者・着信者の**両方**の画面で、Jitsi 内に「私はホストです」ボタンが出ていないか確認する。
  2. 出ている場合、**発信者側**で「私はホストです」を押し、その後着信者が「ミーティングに参加」を押して、映像・音声が繋がるか試す。
  3. それでも繋がらない場合は、2.1 のネットワーク失敗（シグナリングやメディア経路の失敗）を疑う。
- **既知**: CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md のとおり、meet.jit.si では configOverwrite.startConference が効かないため、**自前 Jitsi で会議即開始（everyoneIsModerator 等）を設定することが確実に繋ぐための前提**とされている。

### 2.4 環境（HTTPS・ドメイン・本番）

- **目的**: 混合コンテンツやドメイン違いでスクリプトがブロックされていないか確認する。
- **手順**:
  1. 本番の call.php の URL が **https** であることを確認する（`https://social9.jp/call.php?call_id=...`）。
  2. call.php の `<script src="...">` が、実際に `https://meet.jit.si/external_api.js` のような HTTPS URL になっているか確認する（config は config/app.php の JITSI_BASE_URL）。
  3. 本番サーバーに config/app.local.php で JITSI_BASE_URL を上書きしていないか確認し、上書きしている場合はその URL が正しく HTTPS でアクセス可能か確認する。

---

## 3. 原因と対策の対応表

| 想定原因 | 検証方法 | 対策 |
|----------|----------|------|
| **chrome-extension://invalid/ の net::ERR_FAILED** | Network で失敗 URL が `chrome-extension://invalid/` か確認 | **通話とは無関係**。ブラウザ・拡張の仕様。無視してよい。コンソールを気にしないならシークレットや拡張無効で試す。 |
| 会議が開始されない（モデレーター待ち） | 画面に「私はホストです」が出ているか確認。発信者が押してから着信者が参加する | 暫定: 発信者で「私はホストです」を手動で押す。恒久: 自前 Jitsi で everyoneIsModerator 等を設定（PHONE_VIDEO_CALL_PLAN.md 8.2-B）。 |
| あるリソースの net::ERR_FAILED | Network タブで失敗している URL を特定 | ファイアウォール・プロキシ・VPN で meet.jit.si や WebRTC がブロックされていないか確認。別ネット（例: スマホのテザリング）で試す。 |
| external_api.js の読み込み失敗 | Network で external_api.js が (failed) か 200 か | HTTPS 混合・CSP・ネット障害を確認。本番の JITSI_BASE_URL が正しいか確認。 |
| speaker-selection / web-hid による影響 | 別ブラウザ・シークレットで再現するか | 多くの場合は接続の直接原因ではない。自前 Jitsi では config で不要な機能をオフにできる場合あり。 |
| TURN/STUN の不足やブロック | Jitsi の接続品質ダイアログやコンソールの ICE ログ | 自前 Jitsi で TURN（coturn）を設定する（PHONE_VIDEO_CALL_PLAN.md）。meet.jit.si 単体では環境依存。 |

---

## 4. 通話が繋がらないときに確認すること（チェックリスト）

1. **Network タブで Failed なリソースの URL を記録する**  
   DevTools → Network を開き、call.php で通話を開始した状態で、ステータスが Failed のリクエストの URL を控える。  
   **失敗している URL が `chrome-extension://invalid/` だけの場合は、ブラウザ側のもので通話の原因ではないので無視してよい。**

2. **発信者・着信者双方で「私はホストです」の有無を確認し、発信者で押してから参加を試す**  
   Jitsi の画面内に「私はホストです」が出ている場合は、発信者側でクリックしてから、着信者が「ミーティングに参加」を押す。

3. **別ブラウザ・別ネットワークで試す**  
   別ブラウザ（Edge / Firefox）や、別ネット（スマホテザリングなど）で同じ手順を試し、再現するかどうかを確認する。

4. **本番の call.php が HTTPS か、JITSI_BASE_URL が正しいか確認する**  
   アドレスバーの URL が `https://` であること。JITSI_BASE_URL が meet.jit.si または自前 Jitsi の正しい HTTPS URL であること。

---

## 5. 参照

- [CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md](CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md) — 繋がらない主因・厳命事項・実装タスク
- [PHONE_VIDEO_CALL_PLAN.md](PHONE_VIDEO_CALL_PLAN.md) — 自前 Jitsi 構築（8.2-B）・会議即開始設定
