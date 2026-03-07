# 自前 Jitsi で通話を確実に繋ぐ（クイックスタート）

meet.jit.si（公開サーバー）では会議が自動開始されず「モデレーター待ち」になることがあります。**自前の Jitsi を構築**し、サーバー側で会議を即開始する設定にすると、**電話をかける→相手が「出る」→そのまま繋がる**ようにできます。

---

## 1. なぜ自前 Jitsi が必要か

- meet.jit.si は iframe 経由の `configOverwrite.startConference` を**尊重しない**ため、会議が開始されず「通話に参加しています」のままになることがある。
- 自前 Jitsi ではサーバーの `config.js` で **会議即開始**（everyoneIsModerator 等）を設定できるため、アプリ側の `startConference: true` が有効になり、追加操作なしで繋がる。

---

## 2. Docker で Jitsi を立てる（最小構成）

[Jitsi Meet - Self-Hosting Guide (Docker)](https://jitsi.github.io/handbook/docs/devops-guide/devops-guide-docker) に沿った最小手順です。

### 2.1 前提

- サーバー（VPS または EC2 等）に Docker / Docker Compose が入っていること
- ドメイン（例: `meet.social9.jp`）をこのサーバーに向けられること
- **HTTPS** が必須（WebRTC のため）。Let's Encrypt 推奨。

### 2.2 手順（要約）

1. **リリースを取得**
   ```bash
   wget $(wget -q -O - https://api.github.com/repos/jitsi/docker-jitsi-meet/releases/latest | grep "browser_download_url.*zip" | cut -d\" -f4)
   unzip docker-jitsi-meet-*.zip && cd docker-jitsi-meet-*
   ```

2. **環境変数を設定**
   ```bash
   cp env.example .env
   ./gen-passwords.sh
   ```
   `.env` で以下を設定:
   - `PUBLIC_URL=https://meet.あなたのドメイン`
   - `ENABLE_AUTH=0`（認証なしで使う場合。本番では認証を検討）
   - 必要に応じて `HTTP_PORT` / `HTTPS_PORT` を変更

3. **設定ディレクトリを作成**
   ```bash
   mkdir -p ~/.jitsi-meet-cfg/{web,transcripts,prosody/config,prosody/prosody-plugins-custom,jicofo,jvb}
   ```

4. **起動**
   ```bash
   docker compose up -d
   ```

5. **会議即開始の設定（重要）**  
   自前 Jitsi では `~/.jitsi-meet-cfg/web/config.js` を編集するか、環境変数で上書きします。  
   - **config.js を編集する場合**: `config.js` に以下を追加または変更し、web コンテナを再起動する。
     - `startConference: true` を有効にする、または
     - `everyoneIsModerator: true` で誰でも会議を開始できるようにする
   - Docker の `CONFIG` 用の環境変数があれば、それで `startConference` 等を指定する。

   （公式 Docker の最新では `ENABLE_AUTH=0` かつデフォルトで会議が開始される構成になっている場合もあります。繋がらない場合は上記を確認してください。）

6. **ファイアウォール**  
   - TCP 80 / 443（Web）
   - UDP 10000（JVB メディア）  
   を開放する。

---

## 3. Social9 アプリ側の設定

自前 Jitsi の URL が `https://meet.social9.jp` の場合:

1. **config/app.local.php** を作成（または既存を編集）し、以下を定義する。
   ```php
   define('JITSI_DOMAIN', 'meet.social9.jp');
   define('JITSI_BASE_URL', 'https://meet.social9.jp/');
   ```

2. Web サーバーを再読み込み（または PHP を再起動）する。

3. 通話を発信し、相手が「出る」を押すと、**追加のボタン操作なしで繋がる**ことを確認する。

---

## 4. 参照

- [PHONE_VIDEO_CALL_PLAN.md](PHONE_VIDEO_CALL_PLAN.md) — 8.2-B・8.6 の構成とフェーズ
- [CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md](CALL_CONNECT_AND_NOTIFICATION_FIX_PLAN.md) — 繋がらない主因と厳命事項
- [Jitsi Meet - Self-Hosting Guide (Docker)](https://jitsi.github.io/handbook/docs/devops-guide/devops-guide-docker)
