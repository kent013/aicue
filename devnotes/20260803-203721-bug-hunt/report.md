# bug-hunt 統合レポート — run 20260803-203721

- 実行日時: 2026-08-03 (JST) / worktree: `.claude/worktrees/tasks/bughunt-20260803` (`todo/bughunt-20260803`)
- モード: `--all --coverage --parallel=4 --deviate --real-llm` (既定)
- shard 構成: 1=S3→S7 (:8011) / 2=S1→S2 (:8012) / 3=S4→S5 (:8013) / 4=S6 (:8014)
- `verify-run`: **exit 0 = 全 4 shard 完遂** (欠落なし)
- 分類台帳: `findings.jsonl` (11 件)。adjudication registry 照合の結果、
  **既知 accepted 0 件 / ambiguous 0 件 / 未知・actionable 11 件**
  (`ledger/adjudications.jsonl` の 9 件はいずれも今回の species と一致せず)

## サマリ

| severity | 件数 | finding |
|---|---|---|
| **Critical** | 2 | F-4-01 (ログアウト後の PII 復元) / F-3-01 (プラン変更が恒久的に不能) |
| **High** | 4 | F-1-01 (AI 解析タイムアウト) / F-2-01 (認証待ち画面の無効ボタン) / F-2-02 (リセット失敗時の詰み) / F-3-04 (feedback バナーが one-shot でない) |
| Medium | 3 | F-3-02 (native validation) / F-3-03 (readonly の見た目) / F-3-05 (stale invalid) |
| Low | 1 | F-1-02 (削除後の flash 欠落) |
| 要確認 | 1 | F-4-02 (2FA 手動キー非表示) |

**今回の run の特徴**: Critical/High 6 件のうち **4 件が直前に実装・整備した領域** (P4 ゲート反転の
オンボーディング着地、P9 着地 feedback、bfcache guard、プラン比較画面) から出た。一方
**S7 (認可境界 / IDOR) は finding ゼロ** — cross-org は全て 404 (403 でも生エラーでもなく、
存在オラクル差分なし)、cross-cut adopt 404、撮影者ロールの 403、protected keys の 422 まで
網羅的に確認され、認可レイヤーは高品質だった。

## カバレッジ (4 shard の和集合)

- **画面**: 49 / 52 (screens.md)
  - 未走行 3: `two-factor.login` (2FA 有効なシードアカウントが無く到達不能) /
    `onboarding.billing-required` (実地未到達 = Q-2-01 参照。離脱ガード 2 方向のみ実地確認) /
    `capture.csrf-cookie` (裏で自動発行されるのみ、明示遷移なし)
- **操作**: 63 / 69 (operations.md)
  - 未実行 6: `two-factor.login.store` / `organizations.members.two-factor.reset` (いずれも 2FA 確定済み
    アカウントを用意できず) / `debug.login-as` (`app()->isLocal()===false` で route 未登録 = 設計通りの
    fail-safe) / `organizations.api-keys.sessions.revoke` (OAuth 接続セッションはブラウザ操作だけでは
    生成不能) / `projects.manuals.update` (時間予算) / `capture.takes.downloaded` (発火条件に未到達)
- **UI/UX (H11-H14)**: 全 shard で毎ステップ適用。H13 は 4 shard 合計 12 画面を
  mobile 375×667 / tablet 768×1024 で resize 確認 — **横スクロール・要素はみ出しはゼロ**
  (`scrollWidth === clientWidth` を実測)。H12 で F-3-03、H14 で F-4-02 を検出。

### カバレッジ計測の制約 (silent cap を作らないための明示)

- **code-reach カバレッジ (C3) は収集できていない**: 本環境に pcov 拡張が無く、
  `BughuntCoverageMiddleware` が二重 guard で no-op になったため (`--coverage` は指定済み。
  provision ログに warning あり)。`coverage/merge_pcov.py` は実行していない。
- **operation-reach 突合 (`coverage/correlate.py`) も未実行**: 必須引数の `--graph-db`
  (`.code-review-graph/graph.db`) が存在せず、`code-review-graph` CLI も未インストールのため。
  上記の画面/操作カバレッジは各 shard の自己申告を親が突き合わせた**手動集計**である。

---

## Critical

### F-4-01: ログアウト後にブラウザバックすると認証済み画面が PII 込みで復元される
- severity: **Critical** / story: S6-5 / shard 4 / 詳細: `shard-4/shard-report.md#F-4-01`
- **親による裏取り: 確定**。
  - ログアウトは `AppLayout.svelte:157` の `router.post("/logout")` = **Inertia の SPA visit**。
    フルドキュメント遷移ではないため `pagehide`/`pageshow` が発火しない。
  - `resources/js/lib/bfcache-guard.ts` は `win.addEventListener("pagehide"/"pageshow")` のみを購読し、
    復元マーカーも `documentElement` の属性。**Inertia 自身の history キャッシュによる popstate 復元は
    設計上まったくスコープ外**で、guard は一度も起動しない (shard の requests 観測と一致)。
  - サーバ側 (`NoStoreCacheHeadersForAuthenticatedPages`) は正しく `no-store` を付けているが、
    この経路はそもそもサーバに行かないため無関係。
- **契約との関係**: `docs/supported-browsers.md` / AGENTS.md ドメイン固有規約 #3 は
  「サーバ no-store baseline + クライアント bfcache guard の**セット**で守る」と書いているが、
  **Inertia SPA の履歴復元という第 3 の経路が両方の網から漏れている**。今回の doc 更新で
  S6 に追加した手順 5 が、追加直後にこの穴を検出した形。
- **改善アクション候補 (フレームワーク公式手段が使える)**: `inertiajs/inertia-laravel v3.1.0` は
  `EncryptHistoryMiddleware` と `ResponseFactory::clearHistory()` を同梱している (vendor 確認済み)。
  自前で popstate hook を足す前に、まず **(a) 認証済みページの history 暗号化 (`encryptHistory`) +
  (b) ログアウト時の `Inertia::clearHistory()`** という公式作法を検討すべき
  (AGENTS.md 思考原則 1「フレームワークのレンジ内でやる」)。
- 関連ファイル: `resources/js/lib/bfcache-guard.ts`, `resources/js/components/templates/AppLayout.svelte:156-170`,
  `bootstrap/app.php` (middleware 配線), `vendor/inertiajs/inertia-laravel/src/EncryptHistoryMiddleware.php`

### F-3-01: 契約済み組織はプラン変更を一度も完了できない (循環案内の行き止まり)
- severity: **Critical** / story: S5-3/S5-4 / shard 3 / 詳細: `shard-3/shard-report.md#F-1`
- **親による裏取り: 確定。しかも shard の報告より深刻**。
  - `SubscriptionService::startCheckoutLocked()` 段 1 が
    `Assert::true(! $existing->valid(), '既に有効なサブスクリプションがあります。プラン変更をご利用ください。')`
    で有効サブスクを一律拒否 (`app/Services/Billing/SubscriptionService.php:348-353`)。
  - `Plans.svelte` の `canSwitchTo()` は既存契約の有無を見ないため CTA は常に活性
    (`resources/js/pages/Billing/Plans.svelte:44-49`)。
  - Billing 配下の Service に **swap / subscription update 相当のメソッドは存在しない** (grep 済み)。
  - **さらに `PortalConfigurationSpec` は `subscription_update => ['enabled' => false]` を明示指定**し、
    docblock で「Portal からの out-of-band プラン変更を構造的に封じる」と宣言している
    (`app/Services/Billing/PortalConfigurationSpec.php:10,38`)。
    → **アプリ内にも Stripe Customer Portal にもプラン変更経路が存在しない**。
    エラー文言の「プラン変更をご利用ください」が指す先はどこにも無い。
  - shard-3 が「Portal 専用の意図的仕様か」を要確認としていたが、**Portal 側を意図的に閉じている以上
    その解釈は成り立たない**。仕様上そもそも欠落しているか、in-app swap の実装が P5〜P9 の
    どこかで積み残されたと考えるのが妥当。
- 阻害されたユーザージョブ: BILL-02 (プラン申込・変更)。既存有償契約者の upgrade/downgrade が全滅。
- 改善アクション候補: `SubscriptionService` に既存サブスクの swap (Stripe Subscription Update) 経路を
  実装し `startCheckout` から分岐する。暫定対応するなら `Plans.svelte` の CTA を非活性 + 実際に完了できる
  導線を提示する (ただし現状 Portal も閉じているため、暫定策だけでは行き先が作れない)。
- 関連ファイル: `app/Services/Billing/SubscriptionService.php:334-353`,
  `resources/js/pages/Billing/Plans.svelte:44-49`, `app/Services/Billing/PortalConfigurationSpec.php`

## High

### F-1-01: AI 解析が現実的サイズの SOP で必ずタイムアウトし、リトライもされない
- severity: **High** / story: S3-4/S3-5 / shard 1 / 詳細: `shard-1/shard-report.md#F-1`
- リポジトリ同梱のサンプル SOP (`doc/reference/sample-sop/AS_作業手順書.pdf`, 290KB) で
  generate 段が 120,002ms でタイムアウトし **2/2 で失敗**。194 バイトの極小 SOP では成功するため
  サイズ依存と切り分け済み。`AnalysisPipeline::withBoundedRetry` は `LlmOutputInvalidException`
  のみリトライし、`ConnectionException` (cURL error 28) は一切リトライせず即 failJob。
- `RunManualAnalysis.php` のコメントは「LLM 3 段 × 3 試行 × client timeout 120s = 1,080s」と
  **3 試行のリトライ予算がある前提**で書かれており、実装と矛盾している。
- **注記 (severity の解釈)**: 実 Anthropic API 接続下の観測なので、外部 API の一時的な不調と
  「アプリのリトライ設計の穴」を完全には分離できていない。ただし **同一環境で小サイズは成功 /
  大サイズは 2/2 失敗**という切り分けができており、リトライ非対象という実装事実も確認済みのため
  High を維持する。app-design 側で「実効タイムアウトの出所 (prism.php の 30s と観測値 120s の乖離)」を
  詰める必要がある。
- 関連ファイル: `app/Services/Manual/AnalysisPipeline.php`, `app/Jobs/Manual/RunManualAnalysis.php`, `config/prism.php`

### F-2-01: 認証待ち画面の「あとで認証する（プラン選択へ進む）」が構造的に常に無効
- severity: **High** / story: S1-3/S1-5 / shard 2 / 詳細: `shard-2/shard-report.md#F-01`
- **親による裏取り: 確定**。`EmailVerificationContinuation::resolveUrl()` は
  「session に組織 id があり、その組織に所属している」だけを条件に
  `route('onboarding.checkout')` を返す (`app/Support/Auth/EmailVerificationContinuation.php:35-52`)。
  しかし `/onboarding/checkout` は `Route::middleware(['auth', 'verified'])` グループ内
  (`routes/web.php:169, 357`) にあるため、**未認証ユーザーは必ず `verification.notice` へ差し戻される**。
  ボタンの表示条件 (所属) と踏破条件 (verified) が食い違っており、edge case ではなく恒常的に無効。
- しかも差し戻しが無言 (middleware は flash を積まない) なので H1 (説明なしリダイレクト) に該当。
- 関連ファイル: `resources/js/pages/Auth/VerifyEmail.svelte:51-56`,
  `app/Support/Auth/EmailVerificationContinuation.php:51`, `routes/web.php:169,357`

### F-2-02: パスワードリセットの無効トークン画面に離脱導線が一つもない
- severity: **High** / story: S1-7 / shard 2 / 詳細: `shard-2/shard-report.md#F-02`
- **親による裏取り: 確定**。兄弟ページ `ForgotPassword.svelte:48-52` は `AuthLayout` の
  `{#snippet footer()}` に「ログインに戻る」を渡しているが、`ResetPassword.svelte` は
  **footer snippet を一切渡していない**。`AuthLayout` のヘッダ「AI-CUE」も `<p>` でリンクではない。
- 結果、期限切れ・使用済みリンクを踏んだユーザーは「同じエラーが出るだけの行き止まり」に入り、
  ブラウザバック以外の離脱手段が無い (H2 相当)。ありふれた操作 (古いメールの再クリック) で到達する。
- 関連ファイル: `resources/js/pages/Auth/ResetPassword.svelte`, `resources/js/pages/Auth/ForgotPassword.svelte:48-52`

### F-3-04: P9 着地 feedback バナーが one-shot でなく、リロードで無限に復活する
- severity: **High** / story: S5-7 / shard 3 / 詳細: `shard-3/shard-report.md#F-4`
- **親による裏取り: 確定**。`Billing/Index.svelte:42-44` のコメントは
  「一度表示したら消える (リロードで query が落ちれば feedback は null で届く)」と書いているが、
  **ブラウザのリロードは query を保持したまま再送する**ので前提が誤り。
  同ファイルに `history.replaceState` / `router.replace` による query の scrub は存在しない (grep 済み)。
  サーバ側 `BillingController::resolveBillingFeedback()` は `?session_id=` を都度読むだけ。
- 実運用では決済戻りの URL が履歴・ブックマークに残るため、後日再訪するたびに
  「お支払いを確認しています」等の古い状態が再提示される (H10)。
- **併せて確認された良い点**: cross-org の session_id を付けてもバナーは出ない (org スコープ +
  intent 検証の fail-closed が正しく効いている)。
- 関連ファイル: `resources/js/pages/Billing/Index.svelte:42-60`,
  `app/Http/Controllers/Billing/BillingController.php:449-511`

## Medium / Low

| id | 概要 | 裏取り |
|---|---|---|
| F-3-02 (M) | 請求先メールが `type="email"` のため native (英語) validation が先に発火し、日本語のサーバ検証 UX に到達しない (`BillingContactForm.svelte:87`) | shard 報告のみ (コード位置は確認) |
| F-3-03 (M/H12) | member (manageBilling なし) のオートリチャージ入力欄が実質 readonly なのに通常入力欄と同じ見た目 | shard 報告のみ (原因未特定) |
| F-3-05 (M) | オートリチャージの範囲エラーが値を直しても消えない stale-invalid。`inputError` が `$state` で `ensureValidRange()` (押下時のみ) からしか更新されない一方、`rangeError` は `$derived` (`AutoRechargeCard.svelte:46,84,161-163`) | **確定**。T041/T044 で確立した stale-invalid 解消パターンから、新規実装の本カードだけが外れている |
| F-1-02 (L) | 動画マニュアル削除後のリダイレクト先に成功 flash が出ない (一覧からの消失のみ) | **【2026-08-04 追記: 誤検知と確定】** T095 の実装フェーズで、**現行コードのまま** Browser テスト (Chromium / WebKit 両レーン) を走らせたところ着地マーカーと同一時間窓で `toast-success` が可視になり PASS した。success toast は 4 秒で auto-dismiss するため、bug-hunt driver の snapshot がその窓の後に来ていたことによる観測 artifact。`ledger/adjudications.jsonl` の **A-001** に false_positive として登録済み (4-gate 一致を実測確認)。**コード修正は行っていない** |

## 要確認 (仕様確認待ち。バグと断定しない)

- **F-4-02**: 2FA 有効化画面が QR コードのみで、手動入力用シークレットキーを画面に出していない。
  backend の `/user/two-factor-secret-key` は実際に値を返す (shard が fetch で確認済み)。
  カメラ不可環境・スクリーンリーダー利用者が 2FA を有効化できない可能性。
  QR の `<svg>` にアクセシブルネームが無い点も併せて (H14)。**「QR のみで十分」が意図した仕様かを確認したい。**
- **Q-2-01**: S2 手順 5 の「未契約組織 + manageBilling なし member」シナリオは、
  招待 UI から自然に到達できない。編集者/撮影者の招待には Default Project が必須
  (`OrganizationMembershipService::inviteMember()`) で、プロジェクト作成は課金ゲート配下
  (`routes/web.php:404`) のため。→ `onboarding.billing-required` に実際に着地しうるのは
  「かつて契約していて後から失注した組織」に限られる可能性が高い。
  **story の記述をその旨に直すか、bughunt 用 fixture を足すかを判断したい。**
- **stale recent-auth (15 分窓経過後) の挙動**: 実時間待機が必要なため本 run では未検証。
  コード上は `RequireRecentAuth` の配線を確認済み (既存 Feature テストのカバレッジに委ねる想定)。
- **最後のオーナーの自己除名**: UI 上ボタンは出ないが、直 POST での防御は未検証。
- **F-1-01 の実効タイムアウト値の出所**: 観測 120s に対し `config/prism.php` の `request_timeout` は 30s。
  `laravel-ssrf-pin` の transport 側 deadline が絡む可能性があるが未特定。

## インベントリ修正提案 (今回は正本へ反映していない)

- **`projects.manuals.jobs.show` / `projects.manuals.render-jobs.show` の区分**: ブラウザで直接 GET すると
  Inertia ページではなく生 JSON (`content-type: application/json`) が返る。実態は
  `projects.manuals.show` 内の JS が非同期ポーリングする API であり、screens.md の「画面」区分と
  実態がずれている (shard-1 提案)。**誤りと断定できないため今回は screens.md を変更していない。**
  次回インベントリ更新時に「非 Inertia の GET」注記へ移すかを判断する。
- 他の 3 shard からはインベントリ修正提案なし (screens.md / operations.md の割当は実態と一致)。

## 環境ハザード

- **EH-1 (非停止、shard 1)**: Anthropic API への `cURL error 28` タイムアウト 2 回
  (2026-08-03 11:48:56 / 11:54:25 JST、いずれも 120,002ms、route `projects.manuals.analyze`)。
  再現性を追加検証したうえで F-1-01 として finding 化済み (二重計上しない)。
  serve / DB は健全だったため走行は継続。
- それ以外の shard では環境ハザードなし。全 shard が開始時・終了時の `db-check` に成功。

## 走行基盤についての申し送り (アプリのバグではない)

- `scripts/setup-worktree.sh` は **`.env.bughunt.local` をコピーしない**が、
  `.claude/skills/app-bug-hunt/SKILL.md` Phase 0a は「setup-worktree.sh が `.env.bughunt.local` を
  親からコピーする」と記述している。今回は親から手動でコピーして走行した。
  **SKILL.md の記述を実装に合わせるか、setup-worktree.sh のコピー対象に足すかを決める必要がある。**
- pcov 未導入のため `--coverage` は no-op (上記「カバレッジ計測の制約」)。
- `code-review-graph` 未インストールのため operation-reach 突合を実行できていない。

---

## TODO 候補 (app-design → app-todo-add に渡せる粒度)

| 優先 | 一行サマリ | 阻害されたユーザージョブ | 関連ファイル |
|---|---|---|---|
| 1 | **ログアウト後のブラウザバックで認証済み画面が PII 込みで復元される**。Inertia の SPA 履歴復元が bfcache guard とサーバ no-store の両方を素通りする。Inertia 公式の `encryptHistory` / `clearHistory()` の採用を第一候補に検討する | ログアウト後に自分のアカウント情報を他人に見られないという最低限のセキュリティ期待 (AGENTS.md ドメイン固有規約 #3 の契約違反) | `resources/js/lib/bfcache-guard.ts`, `AppLayout.svelte:156-170`, `bootstrap/app.php` |
| 2 | **契約済み組織のプラン変更経路が in-app にも Stripe Portal にも存在しない**。`startCheckoutLocked` が有効サブスクを一律拒否し、その文言が指す「プラン変更」機能自体が未実装。Portal 側も `subscription_update` を意図的に無効化済み | BILL-02 (プラン申込・変更) が既存契約者で全滅 | `SubscriptionService.php:334-353`, `Plans.svelte:44-49`, `PortalConfigurationSpec.php` |
| 3 | **AI 解析の generate 段が provider/connection 例外をリトライせず、現実的サイズの SOP で 2/2 失敗**。実効タイムアウト値の出所の特定も要る | North Star の起点「SOP から AI がカット設計する」が実運用サイズで機能しない | `AnalysisPipeline.php`, `RunManualAnalysis.php`, `config/prism.php` |
| 4 | **認証待ち画面の「あとで認証する」ボタンが `verified` ゲートで常に跳ね返される** (表示条件と踏破条件の不一致、しかも無言) | メール認証を後回しにしてプランを見る導線が恒常的に機能しない | `VerifyEmail.svelte:51-56`, `EmailVerificationContinuation.php:51`, `routes/web.php:169,357` |
| 5 | **パスワードリセットの無効トークン画面に離脱導線がない** (`ResetPassword.svelte` だけ `AuthLayout` の footer snippet を渡していない) | 古いリセットリンクを踏んだユーザーが行き止まりに入る | `ResetPassword.svelte`, `ForgotPassword.svelte:48-52` |
| 6 | **P9 着地 feedback バナーが one-shot 契約を満たさない** (URL の `session_id` を scrub しない)。設計コメントの前提「リロードで query が落ちる」自体が誤り | 決済直後の状態把握。古い「処理中」表示が何度でも再出現する | `Billing/Index.svelte:42-60`, `BillingController.php:449-511` |
| 7 (小) | オートリチャージの stale-invalid (F-3-05) / 請求先メールの native validation (F-3-02) / member 向け readonly の見た目 (F-3-03) / 削除後 flash 欠落 (F-1-02) — いずれも既存の確立パターン (T041/T044) に揃える小修正 | 入力 UX の一貫性 | `AutoRechargeCard.svelte`, `BillingContactForm.svelte:87` |

## 生成物

- 統合レポート (本ファイル): `devnotes/20260803-203721-bug-hunt/report.md`
- 分類台帳: `devnotes/20260803-203721-bug-hunt/findings.jsonl`
- shard レポート: `devnotes/20260803-203721-bug-hunt/shard-{1,2,3,4}/shard-report.md`
- 証跡 screenshot: `devnotes/20260803-203721-bug-hunt/shard-{1,2,3,4}/screenshots/` (計 29 枚)
- manifest: `devnotes/20260803-203721-bug-hunt/manifest.json`
