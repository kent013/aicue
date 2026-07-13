# bug-hunt 統合レポート — run 20260713-085818

- 実行日時: 2026-07-13 (JST)
- モード: 既定 (`--all --coverage --parallel=4 --deviate`)、worktree `bughunt-20260713` から走行
- 対象: bughunt 隔離環境 shard 1..4 (`:8011`–`:8014` / DB `bug_hunt_1..4`)
- カバレッジ計装: `--coverage` 指定だが **pcov 未導入のため code-reach は no-op** (operation-reach のみ有効)
- 正本突合: screens.md 全 48 画面 / operations.md 全 65 操作

## シャード構成と走行結果

| shard | URL | ストーリー | 走行 | findings (C/H/M/L/要確認) |
|---|---|---|---|---|
| 1 | :8011 | S3, S7 | 完了 (S3 中核チェーンは環境ギャップで後半ブロック) | 0 / 2 / 0 / 0 / 3 |
| 2 | :8012 | S1, S2 | S1 完走 / S2 は F-C1 で全操作ブロック | 1 / 1 / 2 / 0 / 2 |
| 3 | :8013 | S4, S5 | S4/S5 画面完走、操作は一部 UI 不在で skip | 2 / 1 / 1 / 1 / 2 |
| 4 | :8014 | S6 | 完走 (逸脱 4 項目含む) | 0 / 3 / 2 / 1 / 0 |

verify-run: exit 0 (全 shard 完遂、欠落なし)。環境ハザード (B-HARNESS-01 相当) の発生なし。

## dedupe 後の findings サマリ

生 findings 20 件 → dedupe 後 **15 件** (重複クラスタ 3 群を統合)。

| severity | 件数 | finding |
|---|---|---|
| **Critical** | 3 | F-C1 (組織ナビ全滅→S2詰み), F-C2 (組織スイッチャ不在→切替後詰み), F-C3 (Freeプラン締め出し ※seeder起因) |
| **High** | 5 | F-H1 (登録特典チケット未付与), F-H2 (manuals stale alert), F-H3 (2FA無効化に再認証なし), F-H4 (パスワード変更で他セッション残存), F-H5 (唯一オーナー削除で組織孤児化) |
| **Medium** | 3 | F-M1 (保存成功フィードバック欠如×3画面), F-M2 (home ヘッダ 375px 折返し), F-M3 (projectメンバー管理UI不在) |
| **Low** | 2 | F-L1 (recovery code 二重トースト), F-L2 (タブtitle未設定6画面) |
| **要確認** | 4 | Q1 (S3 環境ギャップ3種: LLM/ffmpeg/S3region), Q2 (uncompromised 外部API疑い), Q3 (checkout冪等性 検証不能), Q4 (clipboard コピー失敗) |

> **adjudication consult 結果 (親のみ実施)**: 統合 findings 20 件を `validate_findings.py --annotate` に通した。
> **全件 `adjudication_status: none` = 既知の accepted 判定に該当せず、すべて未知/actionable**。
> known-accepted・ambiguous への振り分け対象なし。
> (validator は既存 `ledger/adjudications.jsonl` の A-004..A-008 に malformed species_key/condition の
> 警告を出したが、これは登録簿側の既存不整合であり今回の findings とは無関係。別途 ledger メンテ推奨。)

---

## Critical

### F-C1: 組織設定・請求・招待への恒常ナビ導線が皆無 → S2 (招待フロー) が完全に詰む
- severity: Critical / failure_class: ux_dead_end
- 由来: shard-2 F-04 (Critical) + shard-3 F-3-01 (High) を統合。**根本原因は共通** (`AppLayout.svelte` がサイドバー/組織メニューを「Phase 2」として未実装)。
- story/step: S2 全般, S4-1/S4-5, S5-1/2
- 再現手順: 任意のアカウント (multi-org@example.com 含む) でログイン → ヘッダー/ダッシュボード/プロジェクト各画面の `<a href>`・ボタンを走査しても organizations.settings / api-keys / billing / pricing / 招待管理 への恒常リンクが一つも無い。組織設定に到達できるのは「組織新規作成直後の一度きりリダイレクト」のみ。加えて共有 props の `currentOrganization` に `slug` が欠落しており、リンクを自力生成することもできない。
- 期待: オーナー/管理者はいつでも組織設定・請求・メンバー招待に到達できる。
- 実際: UI 上に導線が無く、S2 の中核操作 (organizations.invitations.store/revoke, members.update/destroy/two-factor.reset, invitations.accept.*) がすべて実行不能。
- 阻害されたユーザージョブ: 組織へのメンバー招待・権限管理・請求管理という運用の根幹が UI から一切行えない。
- 改善アクション候補: ヘッダー/サイドバーに組織メニュー (設定・請求・メンバー) を追加。`currentOrganization` shared prop に `slug` を含める。
- 証跡: shard-2/screenshots, shard-3/screenshots/S4-01-projects-no-nav.png。コード根拠 `resources/js/components/templates/AppLayout.svelte`。

### F-C2: 組織スイッチャー UI が存在せず、組織が切り替わると元に戻れない (詰み)
- severity: Critical / failure_class: ux_dead_end
- 由来: shard-3 F-3-02。F-C1 と根本 (AppLayout Phase 2 未実装) を共有するが、症状は独立 (H2 詰み)。
- story/step: S4-1 (organizations.switch)
- 再現手順: owner-standard@example.com でログイン (current_org=Standard組織) → /organizations/create で新組織を作成 → 作成直後に current_org が新組織へ自動切替 → 全画面を走査しても組織を戻すスイッチャー UI が無い。`organizations.switch` route は実装済みだが呼ぶ UI が無い。
- 期待: 複数組織所属ユーザーは UI から組織を切り替えられ、誤切替からも戻れる。
- 実際: 一度切り替わると UI からは元の組織 (配下のプロジェクト/カテゴリ/APIキー) に二度と戻れない。
- 阻害されたユーザージョブ: 複数組織の運営・切替作業そのものが不能。
- 改善アクション候補: ヘッダー/サイドバーに組織スイッチャー (shared prop `organizations` 利用)、または `organizations.switch` を呼べる /organizations 一覧画面を用意。
- 証跡: shard-3/screenshots/S4-02-no-org-switcher.png。コード根拠 `AppLayout.svelte:12-13`。

### F-C3: Free プラン組織の全ユーザーが「支払い未確認」で中核機能から締め出される (※seeder 起因 / test_env)
- severity: Critical / failure_class: test_env
- 由来: shard-3 F-3-03 (Critical) + shard-1 F-00d (High) を統合。**同一根本原因**。
- story/step: S4-2 / S7 前提 / S3 前提にも波及
- 再現手順: owner-free / admin-free / member-free いずれかでログイン → /projects へ直接遷移 → `require-active-subscription` により /billing へ強制リダイレクトされ「お支払いが確認できないため一時停止」の赤 alert。role に依らず Free 組織の全員が影響。
- 根本原因: `database/seeders/ManualTestSeeder.php::createOrganization()` が Free プランにも `forceFill(['plan_code' => 'free'])` する。`BillingAccess` は `plan_code !== null` の場合に active な Stripe subscription を要求する設計だが、Free 組織には subscription 行が無く fail-closed で不許可になる (本来 free tier は `plan_code === null` で無条件許可)。
- 期待: Free プラン組織は無操作で中核機能に無償アクセスできる (BillingAccess の doc が明言)。
- 実際: Free 組織の全員が業務ルートから締め出される。
- 阻害されたユーザージョブ: Free 試用の入口が塞がり、S3/S4 の中核ジョブに一切着手できない。加えて **bug-hunt の走行自体を阻害** (S3/S7 で free 系 seed アカウントが使えず shard-1 は /register 経由で代替した)。
- 改善アクション候補: `ManualTestSeeder::createOrganization()` で Free プランは `plan_code` を `null` のままにする (forceFill をスキップ)。または Free 用に fake active subscription を投入。
- 関連既知情報: `devnotes/20260712-0927-bugfix-billing-free-access` (BillingAccess の free tier 許可導入)。**seeder が追従しておらず regression の疑い**。
- 証跡: shard-3/screenshots/S4-05-free-plan-blocked.png。

---

## High

### F-H1: 新規登録で「チケット10枚無料」を謳うが残高が 0 のまま
- severity: High / failure_class: data_integrity / 由来: shard-2 F-01
- 再現手順: /register で新規登録 → メール認証完了 → home/pricing が明記する「新規登録でチケット10枚が無料」に反し残高 0 枚。
- 阻害されたユーザージョブ: 特典を前提に登録したユーザーが即座に無料枠を使えない (広告との齟齬)。
- 改善アクション候補: 登録完了フックでの初期チケット付与、または表記の修正。
- 証跡: shard-2/screenshots。

### F-H2: manuals.show でアップロード/シナリオ保存成功後も直前の失敗 alert が残留 (H10)
- severity: High / failure_class: broken_flow / 由来: shard-1 F-01
- 再現手順: manuals.show で analyze 失敗 → 赤字 alert「手順書をアップロードしてください。」表示 → その後 SOP アップロード成功・シナリオ保存成功しても alert が消えない。手動リロードで消えるためクライアント側 stale local state。
- 阻害されたユーザージョブ: 成功したのに失敗表示が残り、ユーザーが操作の成否を誤認する。
- 改善アクション候補: 成功操作時に stale error state をクリアする。
- 証跡: shard-1/screenshots/F-01-stale-error-after-upload.png。

### F-H3: 2FA の無効化/リカバリコード再生成が step-up 再認証を一切要求しない
- severity: High / failure_class: authz_bypass / 由来: shard-4 F-05
- 再現手順: member-free@example.com でログイン直後に /settings/security で 2FA 有効化→無効化→再生成、いずれもパスワード再入力/recent-auth confirm を経ずに成功。`two-factor.disable`/`regenerate-recovery-codes` は Fortify 標準ルートで `recent-auth` 未付与、かつ `config/fortify.php` の `confirmPassword => false` で password.confirm も外れている。
- 阻害されたユーザージョブ: セッションハイジャック時、攻撃者がパスワード不知のまま 2FA を無効化し認証要素を弱体化できる。
- 改善アクション候補: `two-factor.disable`/`two-factor.regenerate-recovery-codes` に `recent-auth` middleware を付与。
- 証跡: `routes/web.php`, `config/fortify.php:166`, `vendor/laravel/fortify/routes/routes.php`。
- 補足: 意図的設計か実装漏れかは要確認 (Q 参照)。S6 ストーリーカードは 2FA 無効化を機微操作の代表例として明記しており矛盾するため High で記録。

### F-H4: パスワード変更が他セッション/remember-me を無効化しない
- severity: High / failure_class: authz_bypass / 由来: shard-4 F-06
- 再現手順 (コード確定): `app/Actions/Fortify/UpdateUserPassword.php` は current_password 検証 → ハッシュ更新のみで `Auth::logoutOtherDevices()` も remember_token 再生成も無い。
- 阻害されたユーザージョブ: 「乗っ取られたかもしれない」ためのパスワード変更が攻撃者セッションを排除できず無意味になる。
- 改善アクション候補: `UpdateUserPassword::update()` で `Auth::logoutOtherDevices($password)` を呼ぶ、または他セッション/remember_token を破棄。
- 証跡: `app/Actions/Fortify/UpdateUserPassword.php`。

### F-H5: 唯一のオーナーがアカウント削除しても組織孤児化の警告・ブロックが皆無
- severity: High / failure_class: broken_flow / 由来: shard-4 F-04
- 再現手順: owner-free@example.com (Free組織の唯一オーナー) で /settings → アカウント削除 → 警告一切なしで即削除。別セッションで member-free で再ログインすると dashboard に「管理者にプロジェクト作成を依頼してください」が残るが依頼先の管理者は存在しない。
- 阻害されたユーザージョブ: 組織全体が管理不能になり、残存メンバーは管理者権限操作を永久に行えない (組織丸ごとの機能不全)。
- 改善アクション候補: 唯一オーナー検出時に削除をブロックしオーナー移譲を要求、または明示警告。
- 証跡: shard-4 操作ログ、db-check (users 8→7)。

---

## Medium

### F-M1: 保存成功フィードバック (トースト/flash) が複数フォームで欠如
- severity: Medium / failure_class: ux_dead_end / 由来: shard-4 F-01(profile)・F-02(password) + shard-2 F-02(password reset) を統合 (同一パターン、複数画面)。
- 再現手順: /settings のプロフィール更新・パスワード変更、および password.update (reset) が機能的には成功するが成功トーストが出ない。アプリには動作するトースト機構がある (F-L1 で実証) にもかかわらず。
- 阻害されたユーザージョブ: 成否が判断できず二重送信・不安操作を誘発。特にパスワード変更は機微操作。
- 改善アクション候補: これらの成功時に共通のトースト/flash を表示。
- 証跡: shard-4/screenshots/profile-update-feedback.png ほか。

### F-M2: home ヘッダーナビが mobile 375px で単語途中折返し (H13)
- severity: Medium / failure_class: other / 由来: shard-2 F-03
- 再現手順: home を 375px 幅で表示 → ハンバーガーメニューが無く header nav が単語途中で折返し、第一印象の可読性が低下。
- 改善アクション候補: モバイル幅でハンバーガーメニュー化。
- 証跡: shard-2/screenshots。

### F-M3: プロジェクト個別メンバー管理 (projects.members.store/destroy) の UI が存在しない
- severity: Medium / failure_class: broken_flow / 由来: shard-3 F-3-04
- 再現手順: /projects/{id} にプロジェクト単位のメンバー追加/削除フォームが無い。`ProjectMemberController` は store/destroy 実装済み (flash 文言まで用意) だが呼ぶ UI が無い。Default Project 以外では編集者/撮影者の個別アサインが不可能。
- 改善アクション候補: プロジェクト詳細/編集画面にメンバー管理 UI を追加。
- 証跡: コード根拠 (resources/js 全文検索で該当 UI 無し)。

---

## Low

### F-L1: リカバリコード再生成で同一操作にトーストが 2 重表示 (H10)
- severity: Low / 由来: shard-4 F-03
- 再現手順: /settings/security で 2FA 有効化後「リカバリコードを再生成」→ status ロールのトーストが2つ同時表示 (文言も微妙に異なる)。サーバ flash + クライアント楽観トーストの二重発火の疑い。
- 改善アクション候補: 発火元を1箇所に統一。
- 証跡: shard-4/screenshots/recovery-codes-double-toast.png。

### F-L2: 6 画面でブラウザタブ title がサイト名のみ (`app_titles` 未登録)
- severity: Low / 由来: shard-3 F-3-05
- 対象: projects.categories.index, manage.users.index, organizations.api-keys.index, organizations.api-keys.sessions.index, organizations.onboarding.cli, organizations.onboarding.mcp。
- 改善アクション候補: `config/seo.php` の `app_titles` に上記 6 route を追加。
- 証跡: 各画面 `Page Title: AI-CUE`。

---

## 要確認 (仕様確認が必要 — severity 未付与)

- **Q1 (S3 環境ギャップ 3種)** — shard-1 F-00/F-00b/F-00c。bughunt 環境で (1) LLM(Anthropic) 呼び出しが fake されず実 API 401、(2) ffmpeg バイナリ未導入、(3) S3 互換ストレージの region 未設定で upload-url が 500。これにより S3 の「AI解析→撮影→レンダー」中核チェーンの後半がほぼブロックされ、S3/S7 の実データ検証が制限された。根本は `FakeExternalsServiceProvider` に LLM fake bind が無い / ホストに ffmpeg 不在 / `.env.bughunt.local` の S3 disk 設定不足。**bug-hunt 基盤の整備課題** (アプリバグではない)。
- **Q2 (uncompromised 外部 API 疑い)** — shard-2。パスワードの `uncompromised()` 検証が実 HaveIBeenPwned API を叩いている可能性 (サーバ側のためブラウザ network からは確認不可)。禁止事項4 (実外部サービス不使用) の観点で要確認。
- **Q3 (checkout 冪等性 検証不能)** — shard-3 Q-3-02。fake checkout harness (FakeTicketCheckoutGateway/FakeSubscriptionCheckoutGateway) が「決済完了を一切シミュレートしない neutral return」のため、二重課金・冪等性シナリオが UI 経由で検証できない。Webhook 相当を注入できる wrapper サブコマンドの追加を提案。
- **Q4 (clipboard コピー失敗)** — shard-3 Q-3-01。onboarding/cli・mcp の「コピー」ボタンが毎回失敗表示。ヘッドレスブラウザの clipboard permission 制約の可能性が高く環境起因と推定。

---

## 良好だった確認 (finding なし)

- **S7 認可境界/IDOR (shard-1)**: read/write nested route の cross-org 404、categories.reorder の存在オラクル、ProhibitsProtectedKeys、project_member ロールの 403 境界、隣接 ID 総当り — いずれも **重大な IDOR/認可バグは未検出** (良好)。ただし take/render 系は Q1 環境ギャップにより実データ越境検証は skip。
- **S1 セキュリティ (shard-2)**: 改竄した verify-link user-id → 403、再利用/期限切れ password-reset token → 拒否、重複メール登録 → 非列挙的な汎用エラー、contact 二重送信 (dblclick) → POST 1回のみ。
- **S6 (shard-4)**: メール変更は旧アドレスへ通知し新アドレスの再検証を要求、重複メールは列挙回避、通知空状態の文言は良好、H11-H14 (mobile375/tablet768、2FA QR 画面含む) でレイアウト破綻なし。

---

## インベントリ修正提案 (採用は Phase 後に反映)

1. **新ルート追記済み** (本 run で反映): `notifications.index`(S6), `billing.tickets.show`(S5) を screens.md に、`billing.tickets.checkout`(S5), `notifications.read/read-all/open`(S6) を operations.md に追加しドリフト解消。
2. **S4 ストーリーカードの記述不一致** (shard-3 指摘): `manage.users.index` の「ID英数20字・PW直接入力でユーザー作成」という記述は実装 (招待メールベース) と不一致。カード更新を提案。
3. **S6 通知の状態依存化** (shard-4 指摘): notifications.read/open の実データ検証には通知が最低1件必要。ManualTestSeeder に in-app notification を仕込むか、S6 を S2 実施後の状態依存ストーリーにする。
4. **ledger メンテ**: `ledger/adjudications.jsonl` の A-004..A-008 に malformed species_key/condition。validator 警告が出るため整形を推奨。

---

## カバレッジ

### 画面 (screens.md 全 48)
- S1 (14): 14/14 走行 (shard-2)。
- S2 (1 = invitations.accept): 部分走行 (invalid/missing-token パスのみ。招待受諾の正常系は F-C1 で到達不能)。
- S3 (12): 全画面 訪問済み (shard-1)。ただし take/render-job 系の実データ表示は Q1 環境ギャップで未達。
- S4 (11): 11/11 走行 (shard-3)。
- S5 (3): 3/3 走行 (shard-3、purchase-tickets 新画面含む)。
- S6 (6): 6/6 走行 (shard-4、notifications 新画面含む)。
- S7: S3 画面を authz 視点で再走 (shard-1)。
- **未走行画面: なし** (全 48 画面に到達。S2/S3 の一部は操作レベルで環境/導線制約により部分検証)。

### 操作 (operations.md 全 65)
- S1 (9): 7 実行 / 2 skip (理由記載) — shard-2。
- S2 (6): **0 実行** (全て F-C1 により UI 到達不能で skip) — shard-2。
- S3 (15): UI 到達分は実行したが analyze/render/take 系は Q1 環境ギャップで完走不可 — shard-1。
- S4 (20): 15 実行 + 1 代替実行 (switch を fetch 直叩き) + skip (members×2 は UI 不在=F-M3、api-keys.sessions.revoke はセッション0件、debug.login-as は 404 by design) — shard-3。
- S5 (3): checkout×2 は導線/バリデーションのみ確認 (Q3 fake harness により完走不可)、portal は実行 — shard-3。
- S6 (12): 12/12 実行 (notifications 系は 0 件環境のため既読反映は代替検証=404安全確認) — shard-4。
- **未実行の主因**: (a) F-C1 組織ナビ不在 → S2 全操作、(b) Q1 環境ギャップ → S3 中核、(c) Q3 fake harness → S5 checkout 完走。いずれも report に理由記載済み。

## Critical/High TODO 候補サマリ (app-design → app-todo-add 引き渡し用)

1. **F-C1** 組織設定/請求/招待の恒常ナビ導線を追加 (S2 全体をアンブロック)。`AppLayout.svelte`, `currentOrganization` shared prop に slug 追加。
2. **F-C2** 組織スイッチャー UI を追加 (切替後の詰み解消)。`AppLayout.svelte`。
3. **F-C3** `ManualTestSeeder` の Free プラン `plan_code` を null に (bug-hunt 基盤修正 + 本番影響は BillingAccess 契約の再確認)。regression 疑い。
4. **F-H1** 登録時チケット10枚付与 or 表記修正。
5. **F-H2** manuals.show の成功操作で stale error state をクリア。
6. **F-H3** 2FA disable/regenerate に recent-auth 付与。
7. **F-H4** パスワード変更で logoutOtherDevices。
8. **F-H5** 唯一オーナー削除の警告/ブロック + オーナー移譲導線。
