# bug-hunt report shard-4 (S6: セキュリティ/2FA/プロフィール/機微操作の再認証/通知センター)
- run-id: 20260803-203721
- 対象 URL: http://127.0.0.1:8014
- DB: bug_hunt_4 (db-check: users=11)
- 実行ストーリー: S6 (完走。--deviate 込み)
- skip したステップ: なし。ただし「stale recent-auth (15分窓経過後) での再認証要求」は
  実時間 15 分待機が必要なため未検証 (下記「要確認/未検証」に記載。skip ではなく時間制約による未実施)。
  WebKit での bfcache 再現は本環境に webkit ブラウザ未インストールのため未実施 (Chromium で確認済み、
  webkit をインストールする操作は環境変更にあたるため見送った)。

## 画面カバレッジ (screens.md 対象: settings, settings.security, password.confirm, recent-auth.confirm, recent-auth.status, notifications.index, session.status)
- [x] settings (プロフィール編集・パスワード変更・アカウント削除を実操作)
- [x] settings.security (2FA 有効化/確認/リカバリコード再生成/無効化を実操作)
- [x] password.confirm (`/user/confirm-password` は `/recent-auth/confirm` へ統一redirectされる仕様を確認)
- [x] recent-auth.confirm (誤パスワード→バリデーション表示、正パスワード→確認完了を確認)
- [x] recent-auth.status (`GET /recent-auth/status` を直接 fetch して契約を確認)
- [x] notifications.index (空状態・招待通知の一覧表示を確認)
- [x] session.status (bfcache guard 文脈で観察。F-4-01 参照。`/user/two-factor-secret-key` 経由の
      直接 fetch でも契約確認)

## 操作カバレッジ (operations.md 対象: user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes, password.confirm.store, recent-auth.password, settings.account.destroy, notifications.read, notifications.read-all, notifications.open)
- [x] user-profile-information.update (氏名変更/不正メール(HTML5バリデーションで未送信)/メール変更→認証メール→検証完了まで実施)
- [x] user-password.update (弱いパスワードでバリデーションエラー→強いパスワードで成功を確認)
- [x] two-factor.enable (QR + 実 TOTP コードで有効化成功)
- [x] two-factor.confirm (上記に含む)
- [x] two-factor.regenerate-recovery-codes (確認ダイアログ→再生成→新コード表示)
- [x] two-factor.disable (確認ダイアログ→無効化成功)
- [x] password.confirm.store (`/recent-auth/confirm` への統一 redirect 経由で実質同等の動線を確認)
- [x] recent-auth.password (誤/正パスワードで確認)
- [x] notifications.read (個別既読化)
- [x] notifications.read-all (一括既読化、トースト1つのみ)
- [x] notifications.open (招待通知クリック→説明トースト表示、H3 無反応なし)
- [x] settings.account.destroy (①唯一オーナー状態での削除試行→サーバ側で正しく拒否、
      ②オーナー移譲後に削除実行→成功しログアウト+ログイン不可を確認。DB db-check で users 11→10 を確認)

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): 走行した全画面 (settings/security/notifications) で崩れ・overflow なし。
- H12 (アフォーダンス/状態): ボタンの有効/無効・トグル (パスワード表示/非表示) の状態表現は snapshot 上
  明確 (pressed 属性が正しく反映)。問題なし。
- H13 (レスポンシブ): `settings`(mobile 375x667)・`settings/security`(mobile 375x667)・
  `notifications`(tablet 768x1024) を確認 (screenshots/settings-mobile-375.png,
  screenshots/security-mobile-375.png, screenshots/notifications-tablet-768.png)。
  いずれも横スクロール・要素はみ出しなし。確認後 desktop (1280x800) に復帰済み。
- H14 (a11y基礎): 2FA QR コードの `<svg>` に alt/aria-label 相当のアクセシブルネームが無く、
  かつ画面上に代替テキスト (シークレットキー手動入力) も無い → F-4-02 として記録。それ以外の
  操作したボタン/リンクは snapshot 上 role/name が取得でき、キーボード到達性の明らかな欠落は
  観察されなかった。

## bfcache 復元時の秘匿・再検証 (最重要観点)
**重大な契約違反を検出 (F-4-01, Critical)。** 認証済み画面 (`/manage/users` → `/dashboard`) から
ログアウト → ブラウザバック (`playwright-cli go-back`) で `/dashboard` に戻ると、ログアウト前の
PII (ユーザー名・組織名・チケット残高) を含む画面が**そのまま可視状態で復元**された。
`playwright-cli requests` で確認した限り `GET /session/status` は**一度も発火しなかった**
(ログアウト直後の `POST /logout` → `GET /` の後、go-back 中の通信ログは 0 件)。3 秒待機しても
遅延発火なし。2 回独立に再現し同一結果 (owner-starter@example.com で再現、別ログインでも再現)。
一方、復元後にサイドバーのリンクを実際にクリックすると通常どおりサーバへリクエストが飛び
`/login` へ正しくリダイレクトされた (サーバ側の認可自体は健全)。問題は「クリックする前に画面に
中身が見えてしまう」秘匿の欠落そのもの。詳細は F-4-01 参照。
なお、Chromium はブラウザ本来の bfcache を無効化する設定のため、今回の復元は Inertia
(`@inertiajs/svelte`) 自身のクライアントサイド履歴キャッシュ (popstate 処理) によるものと推測され、
`bfcache-guard.ts` が購読する `window` の `pagehide`/`pageshow` イベント自体が発火していない可能性が高い
(F-4-01 の推定原因参照)。「セッションが有効な場合に秘匿が解除され通常操作に戻れるか」の逆方向は、
本 finding によりそもそも秘匿状態に入っていないため検証対象外 (常に非秘匿のまま復元される)。

## findings
Critical 1 / High 0 / Medium 1 / Low 0 / 要確認 1 (走行中随時更新)

## F-4-01: ログアウト後にブラウザバックすると認証済みダッシュボードが PII 込みで完全復元される (bfcache/Inertia 履歴 guard の不備)
- severity: Critical
- story/step: S6-5 (bfcache 復元時の秘匿・再検証)
- 再現手順:
  1. `http://127.0.0.1:8014/login` で `owner-starter@example.com` / `password123` でログイン。
  2. `/manage/users` → `/dashboard` の順で遷移 (Inertia クライアントサイド遷移)。
  3. サイドバー下部のユーザーメニューから「ログアウト」をクリック (`POST /logout` → `/` へ 302)。
  4. ブラウザの「戻る」ボタン (`playwright-cli go-back` = `page.goBack()`) を押す。
- 期待: ストーリーカードの契約どおり、`GET /session/status` が呼ばれ `authenticated:false` を検知して
  秘匿 → `/login` へ hard navigation するはず (`resources/js/lib/bfcache-guard.ts` の設計コメント)。
- 実際: URL は `http://127.0.0.1:8014/dashboard` に戻り、ログアウト前のユーザー名「Starter Owner さん、
  ようこそ」・組織名「Starterプラン組織」・チケット残高「100」等の PII を含むダッシュボードが**そのまま
  中身の見える状態で復元される**。`playwright-cli requests` を確認したが `GET /session/status` は
  **一度も発火しない** (ログの直近エントリは `POST /logout` → `GET /` のみで、go-back 中の通信は 0 件)。
  `playwright-cli console` もエラー 0 件で、guard が「判定不能で秘匿維持」にすら入っていない
  (何の反応もしていない)。3 秒待っても遅延発火なし。2 回独立に再現 (別ログイン試行で再現性確認済み)。
  なお、この状態で実際にサイドバーのリンク (例:「メンバー」) をクリックすると `POST`/`GET` は実際に
  サーバへ飛び、サーバ側は正しく `/login` へリダイレクトする (サーバ側認可は健全)。問題は
  **クリック前に PII が画面に表示されてしまう**こと自体。
- 阻害されたユーザージョブ: 共有 PC・のぞき見 (shoulder surfing) がある環境でログアウトしたユーザーの
  「自分のセッション終了後は他人に自分のアカウント情報を見られない」という最低限のセキュリティ期待が
  破られる。特に組織名・チケット残高等の業務データが漏える。
- 改善アクション候補: `bfcache-guard.ts` は `window` の `pagehide`/`pageshow` (真のブラウザ bfcache) だけを
  監視しているが、今回の復元は Inertia (`@inertiajs/svelte`) 自身の SPA 内 `popstate` 処理による
  クライアントサイドの巻き戻り (Inertia 内部の history page cache) で発生しており、`pagehide`/`pageshow`
  イベント自体が発火していない可能性が高い (フルドキュメント破棄を伴わないため)。Inertia の
  `router.on('navigate')` や `router.on('popstate')` (Inertia がバック/フォワードナビゲーションを
  検知するタイミング) でも同様に `/session/status` を叩く／DOM を秘匿する経路を追加する必要がある。
  もしくは `Cache-Control: no-store` に加えて Inertia 側のクライアントキャッシュ (`only`/`except`/
  history state) 自体を認証状態変化時に無効化する。
- 証跡: screenshots/bfcache-after-logout-goback.png, screenshots/F-01-bfcache-logout-goback-repro2.png
  (いずれもログアウト後 go-back 直後の画面。PII 入りダッシュボードが表示されている)
  console: 0 errors / requests: `/session/status` 呼び出しなし (再現 2 回とも)
- 推定原因: `resources/js/lib/bfcache-guard.ts` の `onPageHide`/`onPageShow` は `window` の
  `pagehide`/`pageshow` イベントのみを購読 (`resources/js/app.ts` で `registerBfcacheGuard` を
  1 回だけ登録)。Inertia SPA 内の `popstate` によるページ切替はブラウザの文書ライフサイクル
  イベントを発火させない経路がある (Inertia 独自の page cache による復元)。ガードの設計コメント
  自体が「Safari の bfcache」のみを対象にしており、Inertia 自身のクライアントサイド履歴復元は
  スコープ外になっている可能性が高い (`resources/js/app.ts` 冒頭コメント参照)。
- 関連既知情報: devnotes 未確認。ストーリーカード `stories/S6-security-2fa-profile.md` 手順 5 に
  今回追加されたばかりの契約そのものであり、今回の run でこの契約が破られていることを検出した。

## F-4-02 (要確認): 2FA 有効化画面に QR コード以外の手動シークレットキー入力手段が UI に露出していない
- severity: 要確認 (a11y 観点では Medium 相当、機能ブロックの可能性もあり要仕様確認)
- story/step: S6-2 (2FA 有効化)
- 再現手順: `owner-personal@example.com` でログイン → `/settings/security` → 「有効化」をクリック。
- 期待: (未確認。一般的な2FA UXでは QR 読み取りに加え「読み取れない場合」用の手動入力キー表示がある
  ことが多い。本アプリの設計意図が未確認のため「要確認」とする)
- 実際: 画面には QR コード (SVG, alt/aria-label なし) のみが表示され、テキストでのシークレットキー
  表示は画面上のどこにもない (`document.body.innerText` に含まれない)。しかし backend には
  `GET /user/two-factor-secret-key` (Fortify 標準) が存在し `{"secretKey":"..."}` を実際に返す
  (`fetch` で直接確認: `N2CDNJRZUTPLFOSJ` が返った)。つまりバックエンドの手動入力用シークレットは
  用意されているのに、フロントエンドがそれを画面に出していない。
- 阻害されたユーザージョブ: カメラが使えない環境・QR コードを認識しづらい認証アプリ・スクリーン
  リーダーユーザーなど、QR スキャンに依存できないユーザーが 2FA を有効化できない可能性がある。
- 改善アクション候補: QR コード画像の近くに「コードを読み取れない場合はキーを手動入力」的なテキスト
  表示 (`/user/two-factor-secret-key` の値) を追加する。QR の `<svg>` に `role="img"` +
  アクセシブルネームも付与する (H14)。
- 証跡: screenshots/2fa-enable-qr.png
- 推定原因: フロント (2FA enable コンポーネント) が secret-key エンドポイントを呼んでいない、または
  呼んでいても表示に反映していない (未調査、5 分で特定できず)。
- 関連既知情報: なし (初見)。設計文書での明示的な「QRのみでよい」の記述有無は未確認のため severity は
  「要確認」に留める。

## 要確認 (仕様確認待ち。バグと断定しない)
- **stale recent-auth (15分窓経過後) での機微操作の再挙動は未検証**: `config/auth.php` の
  `recent_auth_timeout` (既定 900 秒) 経過後、2FA無効化・アカウント削除・オーナー移譲などが
  実際に `/recent-auth/confirm` へ差し戻されるかは実時間 15 分待機が必要なため本 run では未確認。
  コード上は `RequireRecentAuth` / `attachRecentAuthToSensitiveRoutes` により two-factor.disable /
  two-factor.regenerate-recovery-codes / user-profile-information.update (email 変更時のみ) に
  ミドルウェアが後付けされていることをソース確認済みで、設計は健全に見える。実行時の動作確認は
  親 (orchestrator) 側で長時間セッションとして再走行するか、テスト側 (RecentAuthRouteTest 等) の
  既存カバレッジに委ねることを推奨する。
- **メール変更時の旧アドレスへのセキュリティ通知**: `EmailChangedSecurityNotification` は
  `mail` チャネルのみ (in-app 通知なし) のため `tmp/bug-hunt/shard-4-cmd.sh mail-urls` では
  URL を含まない本文を検出できず、実際に旧アドレス (`owner-personal@example.com`) 宛に送信された
  かどうかを本 run のツールでは確定できなかった (mail-urls は URL 抽出のみ対応)。コード上は
  `via()` が `['mail']` を返す実装を確認済み。

## インベントリ修正提案
- 特になし。screens.md / operations.md の S6 割当ては実態と一致していた。`session.status` は
  screens.md の注記どおり「画面ではないがプローブ」として扱われており、今回の F-4-01 はまさに
  このプローブが**呼ばれないこと自体**が問題であり、インベントリ記述自体に誤りはない。

## Critical/High サマリ (TODO 候補)
- **F-4-01 [Critical]**: ログアウト後のブラウザバックで認証済み画面 (PII 込み) が中身の見える状態で
  復元される。再現: `stories/S6-security-2fa-profile.md` 手順5 に従い go-back。阻害ジョブ:
  「ログアウト後に自分のアカウント情報を他人に見られない」という最低限のセキュリティ期待。
  改善: `bfcache-guard.ts` の `pagehide`/`pageshow` 購読に加え、Inertia の `router.on('navigate')`/
  内部履歴キャッシュ復元イベントでも `/session/status` プローブと秘匿を発火させる。
  関連ファイル: `resources/js/lib/bfcache-guard.ts`, `resources/js/app.ts`。
  証跡: shard-4/screenshots/bfcache-after-logout-goback.png, F-01-bfcache-logout-goback-repro2.png
