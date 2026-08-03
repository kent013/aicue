# bug-hunt report shard-2 (run 20260803-203721)

- shard: 2 / URL: http://127.0.0.1:8012 / DB: bug_hunt_2
- 実行ストーリー: S1 (登録/ログインファネル) → S2 (招待フロー)
- 開始: db-check 済み (db: bug_hunt_2, users: 11) / 終了時: users: 19 (走行で作成した10アカウント + skip分)
- モード: LLM=real, storage=fake, mail=log, 決済/Captcha/SSO=fake, --deviate 有効
- findings: Critical 0 / High 2 (F-01, F-02) / Medium 0 / Low 0 / 要確認 1 (Q-01)
- 環境ハザード: なし (走行全体を通じて serve/DB は正常稼働)

## Critical/High サマリ (TODO 候補)
- F-01 [High]: 登録直後メール未認証のまま「あとで認証する（プラン選択へ進む）」を押すと `/onboarding/checkout` が `verified` ミドルウェアで弾かれ、無言で `/email/verify` に戻る。ボタンの存在条件と踏破可能条件が食い違っており、このボタンは常に無効。詳細は F-01 参照。関連ファイル: `resources/js/pages/Auth/VerifyEmail.svelte`, `app/Support/Auth/EmailVerificationContinuation.php`, `routes/web.php:169,357`。
- F-02 [High]: `/reset-password/{token}` が無効/再利用済みトークンで拒否されたとき、画面内にログイン/再リクエストへの導線が一切ない (ページ自体が footer 導線を持たない)。ブラウザの戻るボタンしか離脱手段がない。詳細は F-02 参照。関連ファイル: `resources/js/pages/Auth/ResetPassword.svelte` (`ForgotPassword.svelte` との footer 実装差分)。

## 進捗ログ (逐次)
- [開始] db-check OK
- S1 完走: home/pricing/contact/legal/register/verification/login/password reset/onboarding.checkout/billing 主要導線を実操作で確認。F-01, F-02 を検出。
  - onboarding checkout: Personal 無料 (later / auto_recharge 両方)、Starter 有償 (fake stripe neutral return) を実際に選択・送信して確認。トースト・チケット付与枚数・?plan= handoff (peek/reload 耐性/303 canonical/unknown・enterprise・巨大文字列の正規化) を確認、いずれも正常。
  - T069 サイドバー規約 (個人設定はポップアップのみ・左 nav 重複なし・通知ベル単一導線) を確認、問題なし。
  - H13: dashboard (mobile 375 ドロワー nav 含む) / billing (mobile 375, tablet 768) で resize 確認、崩れなし。
  - 逸脱: 認証メールリンク改竄 (id 差し替え) → 403 で正しく拒否。reset token 再利用 → 拒否されるが F-02 (戻り導線なし)。register/activate-personal 二重クリック → debounce により単一送信のみ (問題なし)。activate-personal を適格性不成立状態 (メンバー超過) で直 POST → 422 で明確なメッセージ、詰まらず。
  - skip: 2FA 分岐 (S1 手順 4) — ManualTestSeeder に 2FA 有効アカウントが無く、有効化は S6 範疇のため未検証 (理由: 対象アカウントなし)。
  - skip: `debug.login-as` (operations.md 記載) — bughunt.local env は `app()->isLocal()===false` のため route 自体が 404 で未登録 (設計通り、環境上到達不能)。インベントリ修正提案に記載。
- S2 完走: 招待送信 (編集者/撮影者、プロジェクト無しだと 422 で適切にブロックされることも確認) → 受諾 (未ログイン→register 誘導 + email prefill (T055) + 個人組織を作らず招待先組織に直接参加 (T030) を確認 / ログイン済みユーザーは `invitations.accept` 確認画面 → `invitations.accept.store` の 2 段階を確認) → ロール変更・削除・招待取消をすべて実操作で確認。
  - `organizations.members.update`: ロール変更 (編集者→撮影者) は反映確認 OK。
  - `organizations.members.destroy`: 確認ダイアログあり、削除後に一覧から消えることを確認。削除された本人でログインすると「組織未選択」の空状態 (組織作成 CTA 付き、詰みなし) に正しく遷移することも確認 (加点ポイント、想定外の良い挙動)。
  - `organizations.invitations.revoke`: 確認ダイアログあり、取消後は取消済みトークンで `invitations.accept` を開くと理由非開示の "この招待リンクは使用できません" 専用ページ (トークンオラクル対策) + ダッシュボードへ戻る導線あり。詰みなし。
  - `organizations.members.two-factor.reset`: UI ボタンは `member.twoFactorStatus==='enabled'` のときのみ表示 (`resources/js/pages/Admin/Users.svelte:201-212`)。ManualTestSeeder に 2FA 有効アカウントが無いため、到達には自分で 2FA を confirmed まで設定する必要があるが、TOTP secret は QR コード SVG 経由でしか渡らずデコードツールが無いため未確認 (下記 skip 参照)。
  - 逸脱: 取消済み招待リンクの再利用 → 拒否 (確認済み、上記)。招待受諾の二重クリック (dblclick) → 1 POST のみで二重参加なし (debounce 有効)。撮影者ロールで編集者専用操作 (`/projects/1/manuals/create` 直 URL) → 403 + 「ホームに戻る」導線あり、詰みなし。
  - billing-required 着地 (S2 手順5) は要確認 Q-01 参照 (自然な到達経路が見つからず、離脱ガード 2 方向のみ実地検証)。
  - H13: `manage/users` を mobile 375 / tablet 768 で resize 確認、崩れなし。

## 画面カバレッジ
走行 15 / 16 screens (S1: 14/15、S2: 2/2 コード上到達 [onboarding.billing-required はコードレビューで確認、実地未到達])
- 走行済み: home, register, login, dashboard, onboarding.checkout, verification.notice, verification.verify, password.request, password.reset, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms, invitations.accept
- 未走行: two-factor.login (ManualTestSeeder に 2FA 有効アカウントが無く到達条件を満たせない。理由: 対象アカウントなし)
- 実地未到達 (コードレビューのみ): onboarding.billing-required (Q-01 参照。招待 UI からは編集者/撮影者を未契約組織に追加できず、離脱ガード 2 方向のみ確認)

## 操作カバレッジ
実行 14 / 16 operations
- 実行済み: register.store, login.store, logout, password.email, password.update, verification.send, contact.store, onboarding.activate-personal, invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke, organizations.members.update, organizations.members.destroy
- skip: two-factor.login.store — 理由: 対象アカウントに 2FA 有効なユーザーが存在しない (S1 手順4 と同一制約)
- skip: debug.login-as — 理由: bughunt.local env で route 自体が 404 (isLocal() 前提の fail-safe 未登録。設計通り、環境上到達不能)
- skip: organizations.members.two-factor.reset — 理由: UI ボタンが 2FA 確定済みメンバーにのみ表示される仕様 (`Admin/Users.svelte:201-212`)。自分のアカウントで 2FA setup 画面までは到達し QR コード表示を確認したが、TOTP secret はブラウザ操作のみでは抽出できず (デコードツール圏外) confirmed 状態まで進められなかった。

## UI/UX 検証 (H11-H14)
- H11 (視覚破綻): 走行した全画面で崩れ・重なり・overflow なし。
- H12 (アフォーダンス/状態): onboarding checkout の「選択」/「選択中」ボタン文言、招待/削除の確認ダイアログ、フォームのエラー枠 (invalid 属性) いずれも判別可能。
- H13 (レスポンシブ): mobile 375×667 / tablet 768×1024 で dashboard (mobile ドロワー nav 含む)・billing・manage/users を確認、いずれも横スクロール・要素はみ出しなし。確認後 desktop (1280×800) に復帰済み。
- H14 (a11y 基礎): snapshot 上、フォーム要素は label/role/name が一貫して取得でき (aria 欠落の兆候なし)、見出し階層も heading level が各画面で妥当に見えた。詳細なコントラスト測定は未実施 (視覚的に問題は見受けられず)。

## findings

### F-01: 「あとで認証する（プラン選択へ進む）」ボタンが必ず自分自身へ跳ね返る (説明なしループ)
- severity: High
- story/step: S1-3, S1-5 (メール認証 → 課金オンボーディング着地)
- 再現手順:
  1. `http://127.0.0.1:8012/register` で新規登録 (例 名前=S1テストユーザー2 / email=s1-test-user2@example.com / password=Passw0rd!2026)
  2. `/email/verify` に着地。メールのリンクは踏まずに「あとで認証する（プラン選択へ進む）」ボタン (`data-testid=verify-email-continue`) をクリックする。
  3. ブラウザは `GET /onboarding/checkout` へ遷移するがサーバは 302 で即座に `/email/verify` へ送り返す (`requests` で確認: `[GET] /onboarding/checkout => [302] Found` → `[GET] /email/verify => [200] OK`)。画面は見た目上ほぼ何も変わらず同じ認証待ち画面に戻る。
- 期待: ボタン文言どおり「プラン選択へ進む」(=課金オンボーディング画面) に遷移できる。少なくとも遷移できない理由 (メール認証が先に必要) が画面上に説明される。
- 実際: クリックしても認証待ち画面に戻るだけで、なぜ進めなかったのかの説明が一切ない。`/onboarding/checkout` は `Route::middleware(['auth','verified'])` 配下にあり (`routes/web.php:169,357`)、未認証ユーザーは構造的に必ず弾かれるため、このボタンは**常に**目的を果たせない (edge case ではなく恒常的に無効な導線)。
- 阻害されたユーザージョブ: 「メール認証を後回しにしてまずプランを見てみたい」というユーザーの意図が達成できない。しかも失敗が無言なので、ユーザーは「クリックが効いていない」「バグって固まった」と誤解し、離脱の原因になりうる。
- 改善アクション候補: (a) `EmailVerificationContinuation::resolveUrl` が返す導線は `verified` ゲートの外に置く (例: 課金オンボーディングの案内だけ見せて実際の選択は認証後にする専用のプレビュー画面にする)、または (b) ボタン自体を「メール認証が完了してから利用できます」という説明とともに無効化する、または (c) 認証待ち画面側で `/onboarding/checkout` へのアクセスが `verified` ミドルウェアで弾かれた場合に「メール認証が必要です」という flash message を出す。
- 証跡: screenshots/F-01-verify-later-loop.png、requests: `25. [GET] /onboarding/checkout => [302] Found` → `26. [GET] /email/verify => [200] OK`
- 推定原因: `resources/js/pages/Auth/VerifyEmail.svelte` の `continueUrl` ボタンが `route('onboarding.checkout')` (= `/onboarding/checkout`) を指すが (`app/Support/Auth/EmailVerificationContinuation.php:51`)、このルートは `routes/web.php:169` の `Route::middleware(['auth', 'verified'])->group(...)` 配下にあるため未認証ユーザーは Laravel 標準の `verified` ミドルウェアで forcibly `verification.notice` へ差し戻される。ボタンを出す条件 (`resolveUrl` の membership 確認) と実際に踏破できる条件 (`verified`) が食い違っている。
- 関連既知情報: 未調査 (devnotes/TODO.md 等に同種記録なし。P4 ゲート反転 / P7 `?plan=` handoff の実装レビューが必要そう)。

### F-02: パスワードリセットトークンが無効/再利用済みのとき、画面内に復帰導線が一つもない
- severity: High
- story/step: S1-7 (パスワード忘れ)、逸脱アイデア「reset トークンを使い回し/期限切れ → 弾かれるか」
- 再現手順:
  1. `owner-personal@example.com` で `/forgot-password` からリセットリンクを発行し、1 回使い切って正常にパスワードを変更する (再現手順の前提として: 一度目の /reset-password 送信は成功する)。
  2. 使用済みの同じリンク (`http://127.0.0.1:8012/reset-password/{token}?email=owner-personal%40example.com`) をブラウザで再度開き、新パスワードを入力して「パスワードをリセット」を送信する。
  3. サーバは正しく `このパスワード再設定トークンは無効です。` を表示して拒否する (弾かれる自体は正しい)。しかし画面には**ログインへ戻るリンクも、リセットをやり直すリンクも一切ない**。ヘッダーの「AI-CUE」もリンクではなく `<p>` (`resources/js/components/templates/AuthLayout.svelte:32`) で、遷移導線ではない。同じフォームの再送信ボタンが残っているだけで、何度押しても同じ拒否が繰り返される。
- 期待: 無効/期限切れトークンを検知したら「パスワード再設定をやり直す (`/forgot-password`) 」または「ログインに戻る」への明示的な導線がその場に出る (少なくとも `/forgot-password` にある「ログインに戻る」リンクと同水準)。
- 実際: `/reset-password/{token}` ページ自体に一切のナビゲーションリンクが無い (有効なトークンで初めて開いたときも同様で、この画面固有の設計)。ブラウザの戻るボタンでしか離脱できない。
- 阻害されたユーザージョブ: パスワードを忘れて再設定しようとしたユーザーが、古いメールのリンクを再クリックした・二重クリックした等のありふれた操作で「同じエラーが出るだけの行き止まり」に到達し、次に何をすればいいか画面から分からない (H2 相当: 進む導線がない)。
- 改善アクション候補: `ResetPassword.svelte` (`resources/js/pages/Auth/ResetPassword.svelte` 想定) にエラー時 (`errors.email` / token invalid) の分岐で「新しいリセットリンクをリクエストする (`/forgot-password`)」「ログインに戻る (`/login`)」の 2 リンクを追加する。
- 証跡: screenshots/F-02-reset-token-invalid-no-exit.png
- 推定原因: 確認済み。`resources/js/pages/Auth/ForgotPassword.svelte:48-52` は `AuthLayout` の `{#snippet footer()}` に `<TextLink href="/login">ログインに戻る</TextLink>` を渡しているが、`resources/js/pages/Auth/ResetPassword.svelte` (全 59 行) は `footer` snippet を一切渡していない (フォームと送信ボタンのみ)。同じ `AuthLayout` を使う兄弟ページ間で footer 導線の有無が一貫していない。
- 関連既知情報: 未調査。

## skip
- two-factor.login (screen) / two-factor.login.store (operation): ManualTestSeeder のテストアカウントに 2FA 有効なユーザーが存在しないため、S1 手順 4 の「2FA 有効なら」分岐に到達できなかった。2FA 自体の有効化フロー (QR コード表示・確認コード入力まで) は S1テストユーザー5 で実施し正常動作を確認したが、confirmed 状態まで進めるには TOTP コード生成が必要でブラウザ操作のみでは完了できず、ログイン時の 2FA チャレンジ画面は検証できなかった。
- debug.login-as (operation): bughunt.local env は `app()->isLocal()===false` のため `/debug/login*` route 自体が未登録 (404)。設計通りの fail-safe (`routes/web.php` の `if (app()->isLocal() || app()->runningUnitTests())` guard) であり、環境ハザードではない。
- organizations.members.two-factor.reset (operation): 上記 2FA 制約と同じ理由で、リセット対象となる「2FA 確定済みメンバー」を用意できなかった。UI 条件分岐 (`canResetTwoFactor`) はコードレビューで確認済み (owner は誰でも、admin は非同格以上のみ)。
- onboarding.billing-required (screen、実質): S2 手順 5 の想定シナリオ (未契約組織 + 編集者/撮影者メンバー) を招待 UI から自然に再現できなかった。詳細は Q-01。離脱ガード方向 (billed org member / 未契約 owner が直叩きした場合の redirect) は実地検証済み。

## 要確認 (仕様不明で severity 未確定)

### Q-01: S2 手順 5 の「未契約組織の非管理 member」シナリオは招待 UI から到達できない可能性
- story/step: S2-5 (`onboarding.billing-required`)
- 内容: story は「招待先組織が未契約の状態で、`manageBilling` を持たない member (編集者/撮影者) が
  業務画面へ行こうとすると `/billing-required` に着地する」を検証対象にしているが、実装を辿ると
  以下の構造的制約があり、**新規登録直後の未契約組織に対して編集者/撮影者を招待すること自体ができない**:
  - `OrganizationMembershipService::inviteMember()` (`app/Services/Organization/OrganizationMembershipService.php:58-64`) は
    編集者/撮影者ロールの招待に Default Project の存在を要求し、無ければ
    `編集者・撮影者を招待するには、先にプロジェクトを作成してください。` で 422 拒否する。
  - プロジェクト作成 (`/projects/create`) は `require-active-subscription` ゲート配下 (`routes/web.php:404`) のため、
    組織が未契約のうちはプロジェクトを作れない。
  - 招待ロールは 管理者/編集者/撮影者 の 3 値のみ (`app/Enums/AdminConsoleRole.php`)。「権限なしメンバー」を
    直接招待する導線が無く、管理者ロールは `manageBilling=true` になる (`OrganizationPolicy::manageBilling`)。
  - 結果として、`onboarding.billing-required` に実際に着地しうるのは「かつて契約していて編集者/撮影者を
    招待済みの組織が、後から契約を失った (解約・支払い失敗等)」場合に限られると推測される。本 bughunt 環境は
    決済 fake (`FakeStripeGateway` は "neutral return" のみで実際の解約/ダウングレードを起こさない、
    `app/Services/Billing/Fakes/FakeStripeGateway.php`) のため、この状態を wrapper/ブラウザ操作だけで
    作る手段が見つからなかった (DB 直接操作は禁止のため未実施)。
- 実施した代替検証: `/billing-required` への直叩き離脱ガード 2 方向は実際に確認し、いずれも正常
  (詳細は進捗ログ参照。ループなし)。画面自体の「行き先のない詰みでないか」は
  `resources/js/pages/Onboarding/BillingRequired.svelte` のコードレビューで確認 (AppLayout 内で
  サイドバーのダッシュボード導線が常に生き、オーナー連絡先 + お問い合わせ導線も出る。詰みなし)。
  ただし実際にこのロールの組織で操作して「操作阻害」が起きないかは未検証。
- 確認したいこと: (a) この「未契約 + 編集者/撮影者メンバー」の状態は意図通り「後から失注した組織」限定の
  シナリオか (story の記述をその旨に修正すべきか)、(b) もしそうなら bughunt 環境で意図的にこの状態を
  再現する手段 (reseed 用の fixture 追加等) を用意する価値があるか。
- 関連ファイル: `app/Http/Controllers/Onboarding/BillingRequiredController.php`,
  `app/Services/Organization/OrganizationMembershipService.php`,
  `resources/js/pages/Onboarding/BillingRequired.svelte`
