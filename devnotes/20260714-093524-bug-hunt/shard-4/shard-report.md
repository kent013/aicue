# bug-hunt report shard-4 (run 20260714-093524)
- Session: playwright-cli -s=bughunt4
- Story: S6 (security / 2FA / profile / notifications)
- 主眼: T031 (メール変更 recent-auth 未保護) / T034 (notifications タブ title) の回帰確認 + F-H3/H4/H5/M1/L1 軽再確認

## 前回 findings (回帰確認対象、由来 run 20260714-005157)
- F-4-01 (T031): メールアドレス変更 (`user-profile-information.update`) が recent-auth 未保護。stale session
  (remember-me経由でセッションcookie削除→自動再ログイン) でも即座にメール変更可能、旧アドレス通知なし → 修正:
  devnotes/20260714-0159-profile-update-recent-auth (APPROVED, `RequireRecentAuthOnEmailChange` middleware +
  `recent-auth.on-email-change` route + 旧アドレス通知)
- F-4-02 (T034): notifications 画面のタブ title 未設定 → 修正: config/seo.php app_titles に notifications.index
  = '通知' 追加済み (要ブラウザ確認)
- F-H3/H4/H5/M1/L1: 前々回 (20260713-085818) 由来、前回 (20260714-005157) で回帰OK済み。今回は軽い再確認のみ。

## 走行ログ (逐次追記)

### 回帰確認: F-4-01 (T031: メール変更が recent-auth 未保護) → 回帰OK
- member-free@example.com / password123 でログイン、「ログイン状態を保持」チェック。
- `playwright-cli cookie-delete ai-cue-session` でセッションcookieのみ削除 (remember_web_* は残す) → `/settings`
  にgoto → remember token 経由で自動再ログイン (stale session、recent_auth_at 未stamp)。
- メールアドレス欄を `member-free-takeover@example.com` に書き換えて「保存」。
- **結果: 即座に変更されず「本人確認」モーダルが表示された** (「セキュリティのため、この操作を続けるにはもう一度
  本人確認が必要です。」)。パスワード `password123` を入力し「確認する」→ 変更が確定し `/email/verify` へ遷移
  (新アドレス宛の確認メール送信のみ、まだ未確認の状態でメールアドレス欄には新アドレスが反映済み)。
- `tmp/bug-hunt/shard-4-cmd.sh mail-urls --count 5` で新アドレス宛の確認 URL を取得し `goto` で踏むと
  `/settings` へリダイレクトされ検証完了。
- **結論: 回帰OK**。stale session での email 変更は re-auth (recent-auth) で正しくブロックされ、パスワード確認後
  のみ変更が確定する。account-takeover 経路は塞がれている。
- 追加検証: 同じ stale session (再度 `cookie-delete ai-cue-session` → `/settings`) で**氏名のみ**変更
  (メール欄は変更しない) → **モーダルなしで即座に保存され、成功トースト「プロフィールを更新しました。」が表示**
  (`PUT /user/profile-information => 303`)。設計どおり「氏名のみの変更は re-auth precheck を要求しない」
  UX 温存が機能していることを確認。
- 証跡: screenshots/F-4-01-regression-ok-recent-auth-modal.png
- 限界事項 (要確認・infra寄り): 旧アドレス (`member-free@example.com`) への変更通知メールの送信有無は、
  wrapper `mail-urls` が **URL パターンのみ抽出**する設計 (`extract_mail_urls` は
  `http://127.0.0.1:{port}...` にマッチする文字列のみを返す) のため、リンクを含まない通知メール本文の有無を
  ブラウザ/wrapper 観測からは直接確認できなかった (生ログ・artisan・tinker は禁止事項のため使用不可)。
  実装レビュー (devnotes/20260714-0159-profile-update-recent-auth/impl-review-round-1.md) では
  `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php` case 3 が「旧アドレス通知 + email_verified_at
  null化」を Feature test で固定化していると記載されており、コード上は対応済みと推測されるが、bug-hunt
  観測だけでは独立検証できなかった (finding化せず、tool limitation として記録)。

### 軽再確認: F-H3 (2FA disable の recent-auth 要求) → 回帰OK
- member-free-takeover@example.com (メール変更後) で 2FA を有効化 (`/user/two-factor-qr-code` の応答 JSON から
  otpauth secret を抽出しローカルで TOTP 計算、`playwright-cli fill` で認証コード入力→「確認して有効化」)。
- 有効化直後、`GET /recent-auth/status` が呼ばれ**リカバリコード自動表示が stale session のため本人確認モーダルで
  ブロック**された (良い設計: 有効化直後でもリカバリコード閲覧という機微操作は re-auth を要求)。パスワード入力で
  解決、リカバリコード8件表示。
- 続けて `cookie-delete ai-cue-session` で再度 stale session を作り「2要素認証を無効化」→確認ダイアログ
  「無効化する」→ **即無効化されず「本人確認」モーダルが表示**、パスワード入力後に無効化完了 (ステータス「無効」)。
- 結論: 回帰OK (前回 run 20260714-005157 と同じ挙動を再現)。
- 証跡: screenshots/2fa-enable-recent-auth-modal.png

### 軽再確認: F-L1 (リカバリコード再生成トースト二重表示) → 回帰OK
- member-free-takeover@example.com で 2FA 再有効化 (`/user/two-factor-qr-code` から secret 抽出しローカル
  TOTP計算) → リカバリコード再生成 (確認ダイアログ「再生成する」)。
- snapshot 上、`status` region は1個のみ (`リカバリコードを再生成しました。新しいコードを保管してください。`)。
- 結論: 回帰OK。

### 深掘り (逸脱): F-4-01 のサーバー側 (middleware) 強制を直POSTで確認 → 回帰OK (client-side回避不可)
- 前段の UI 検証は client-side JS の precheck (`guardWithRecentAuth`) がモーダルを出しているだけで、実際に
  `PUT /user/profile-information` の**サーバー側**が保護されているかは別途要検証 (前回 F-新-01 の根本原因は
  「サーバー側ルートに recent-auth middleware が付いていない」ことだったため、修正がサーバー側で効いているかが
  本質的な回帰確認ポイント)。
- 検証方法: member-free-takeover@example.com で remember-meログイン→`cookie-delete ai-cue-session`で stale
  session化→ `/settings` にgoto (UIは開くがJSは操作していない) → `playwright-cli run-code` でブラウザの
  `fetch()` を使い **UIのモーダルを一切経由せず** `PUT /user/profile-information` に直接
  `{name, email: 'direct-post-bypass@example.com'}` を XSRF token 付きで送信。
- **結果: `HTTP 409` + `{"code":"recent_auth_required","message":"この操作には直近の再認証が必要です。","redirect":".../recent-auth/confirm"}`** で拒否された。`db-check` で users=8 (メール変更されず) を確認。
- 結論: **回帰OK、かつ堅牢**。今回の修正はサーバー側 middleware (`RequireRecentAuthOnEmailChange`) で
  強制されており、client-side JS を回避した直接 POST でもブロックされる (前回の脆弱性は根本的に塞がれている)。
- 証跡: 上記 run-code の応答 JSON (このセッション内のコマンド出力)。

### 軽再確認: F-H4 (パスワード変更で他セッション失効) → 回帰OK
- member-free-takeover@example.com で cookie-clear → フォームログイン (Session X 確立) → `state-save session-x.json`。
- 再度 cookie-clear → 同アカウントで再ログイン (Session Y、独立セッション) → `/settings` からパスワード変更
  (`password123` → `NewPassBugHunt2026x`) 実行 (`PUT /user/password => 303`)。
- `state-load session-x.json` で Session X の cookie を復元 → `/settings` にgoto → **`/login` へリダイレクト
  された** (Session X は無効化されている)。
- 結論: 回帰OK。パスワード変更時に他セッションが正しく失効する。
- 気づき: パスワード変更成功時のトースト (F-M1) が今回の snapshot タイミングでは捕捉できなかった (取得前に
  自動で消えた可能性、finding化せず。前段の氏名変更では正しくトースト表示を確認済み)。

### 軽再確認: F-H5 (唯一オーナー削除で組織孤児化) → 回帰OK
- owner-free@example.com でログイン → `/settings` を開いた**時点で**警告 status 表示:
  「オーナー移譲が必要です。以下の組織であなたが唯一のオーナーです。アカウントを削除する前に、各組織で
  オーナーを別のメンバーへ移譲してください（削除時にサーバーが再判定します）。」+ Freeプラン組織設定への直リンク。
- 警告を無視して「アカウントを削除」→確認ダイアログ「削除する」実行 → **サーバー側が再判定してブロック**、
  inline alert「次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: Freeプラン組織」表示。
  `db-check` で users=8 (不変) を確認、アカウントは削除されていない。
- 結論: 回帰OK (事前警告 + delete時サーバ再判定ブロックの2点セットが機能)。
- 証跡: screenshots/F-H5-regression-ok-delete-blocked.png

### 回帰確認: F-4-02 (T034: notifications タブ title) → 回帰OK
- `/notifications` へ遷移 → Page Title: 「通知 | AI-CUE」(以前は "AI-CUE" のみ)。他の設定系画面と同じ
  「画面名 | AI-CUE」形式になっている。`config/seo.php` の `app_titles` に `notifications.index` = '通知' が
  追加されたことに対応。
- 証跡: 下記スナップショットヘッダ参照 (Page Title 行)。

## F-4-03: 新規発見 — /settings パスワード変更欄に「パスワードを表示」トグルが無い (ログイン画面と非一貫)
- severity: Low (H12 アフォーダンス/状態表現)
- story/step: S6 (走行中の毎ステップ H12 チェックで発見)
- 再現手順: `http://127.0.0.1:8014/settings` にログイン後アクセス → 「パスワード変更」セクションの
  「現在のパスワード」「新しいパスワード」いずれの入力欄にも表示/非表示切替ボタンが無い (プレーンな
  `<input type=password>` のみ)。対照的に `/login` 画面のパスワード欄には「パスワードを表示」ボタンが実装されている。
- 期待: アプリ内でパスワード入力欄の UX は一貫しているべき (ログイン画面にある機能が設定画面に無いのは
  片手落ちで、特に新パスワード入力時に打ち間違いに気付きにくい)。
- 実際: ログイン画面のみ表示切替ボタンがあり、設定画面のパスワード変更・2FA再認証モーダルの現在パスワード欄には無い。
- 阻害されたユーザージョブ: 新しいパスワードを設定する際に入力内容を確認する手段が無く、打ち間違えたまま
  送信してしまうリスクが (軽微だが) 上がる。
- 改善アクション候補: ログイン画面で使っている表示切替ボタンのコンポーネントを設定画面のパスワード入力欄
  (パスワード変更・recent-auth モーダル) にも適用し、アプリ全体で一貫させる。
- 証跡: screenshots/H12-password-fields-no-toggle.png
- 推定原因: ログイン画面の password input が独自 (表示切替あり) の実装で、Settings/RecentAuth 系の password
  input は別の (より単純な) 共通コンポーネントを使っている可能性 (未調査、5分以内で特定できず)。
- 関連既知情報: 前回 run (20260714-005157) では言及なし。今回新規に H12 チェックで気づいた。

## 逸脱アイデアの追跡 (未深掘り・要確認)
- 「2FA無効化直後に必須組織(two-factor-requirement)へのアクセス」は `organizations.two-factor-requirement.update`
  が S4 の操作割当であり、S6 のテストアカウント (Free Member/Owner) には該当組織の 2FA 必須設定が
  seed データ上存在しない (未セットアップ) ため、本 shard では深掘りしなかった。S4 側での検証を推奨。

## まとめ

### 走行サマリ
- 実行ストーリー: S6 (完走)。skip したステップ: なし。
- 画面カバレッジ: 5/5 (settings, settings.security, password.confirm→recent-auth.confirm へ収束, recent-auth.confirm, recent-auth.status)
- 操作カバレッジ: 9/9 (user-profile-information.update [氏名のみ/メール変更の両方], user-password.update,
  two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes,
  password.confirm.store, recent-auth.password, settings.account.destroy [ブロック確認])
- UI/UX 検証:
  - H11 (視覚破綻): 特になし。
  - H12 (アフォーダンス/状態): F-4-03 (パスワード表示トグルの不一致) を新規発見。2FA有効/無効バッジ、
    delete-button の active/disabled 状態は明瞭。
  - H13 (レスポンシブ): /settings を mobile 375x667、/settings/security を tablet 768x1024 で確認、
    横スクロール・要素はみ出し・重なり無し。screenshots/settings-mobile-375.png,
    screenshots/security-tablet-768.png。確認後 desktop (1280x900) に復帰済み。
  - H14 (a11y基礎): モーダルの見出し構造・フォーカス可能な閉じるボタンあり、button/textbox の name/role は
    snapshot で正しく取得できた (aria欠落の兆候なし)。
- findings: Critical 0 / High 0 / Medium 0 / Low 1 (F-4-03, H12由来) / 要確認 0

### 回帰確認サマリ (すべて回帰OK)
| 前回 finding | 状態 | 根拠 |
|---|---|---|
| F-4-01 (T031: メール変更が recent-auth 未保護) | **回帰OK** | stale session で email 変更 → 本人確認モーダル必須。氏名のみ変更は precheck スキップ (UX温存)。さらに UI 非経由の直POSTでも `409 recent_auth_required` でサーバー側middlewareが強制していることを実証 (client-side回避不可) |
| F-4-02 (T034: notifications タブ title) | 回帰OK | Page Title「通知 \| AI-CUE」に修正済み |
| F-H3 (2FA disable の recent-auth 欠如) | 回帰OK | stale session で本人確認モーダル必須、fresh session はスキップ (設計通り) |
| F-H4 (パスワード変更で他セッション残存) | 回帰OK | 独立2セッションで実証、変更後に別セッションは /login へ強制リダイレクト |
| F-H5 (唯一オーナー削除で組織孤児化) | 回帰OK | 事前警告 + delete時サーバ再判定ブロックの2点セットが機能、users数不変 |
| F-M1 (保存成功トースト欠如) | 回帰OK | プロフィール(氏名)更新でトースト確認。パスワード変更は今回1回分の観測タイミングで捕捉できなかった (finding化せず) |
| F-L1 (リカバリコード再生成トースト二重表示) | 回帰OK | トーストは1個のみ |

### 新規 finding
| ID | severity | 概要 |
|---|---|---|
| F-4-03 | Low (H12) | /settings のパスワード変更欄に「パスワードを表示」トグルが無い (ログイン画面と非一貫) |

### 気づき (finding化せず)
- 旧アドレスへの変更通知メール送信有無は `mail-urls` wrapper が URL パターンのみ抽出する設計のため
  ブラウザ観測から独立検証できなかった (実装レビューでは Feature test で担保されている旨の記載あり)。
- `/user/confirm-password` (Fortify標準 `password.confirm`) は `/recent-auth/confirm` へ内部的に収束しており、
  単一の再認証 UI に統合されている (screens.md 上は別画面として記載されているが実質同一UI、インベントリ
  修正提案として下記に記載)。
- 「2FA無効化直後に必須組織アクセス」の逸脱は S4 領域の `organizations.two-factor-requirement` に依存するため
  本 shard では未深掘り (上記参照)。

### インベントリ修正提案
- `screens.md` の `user/confirm-password (password.confirm)` は実際には `recent-auth.confirm` へリダイレクトし
  同一UIに収束することを注記すると、次回以降の探索者が「2画面を別々に検証する必要がある」と誤解しないで済む。

### Critical/High 要約 (TODO候補)
- 該当なし (今回検出した新規 finding は F-4-03 Low のみ。F-4-01/F-4-02 はいずれも修正確認済みで回帰OK)。

(走行終了)
