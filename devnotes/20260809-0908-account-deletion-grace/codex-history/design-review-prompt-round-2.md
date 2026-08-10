# 詳細設計レビュー Round 2

Round 1 の [Critical] 6 件・[Warning] 7 件・[Suggestion] 3 件すべてに対応した。
対応マトリクス全文は devnotes/20260809-0908-account-deletion-grace/codex-history/design-review-decisions-round-1.md にある。

## [Critical] への対応

| 指摘 | 対応 |
|---|---|
| **B2 `config('account.deletion_grace_days')` の定義元が無い** | 施策 **B0** を新設。`config/account.php` (`deletion_grace_days => 30`、**env を使わない**) + `App\Support\Account\AccountDeletionGrace::days()` / `::purgeAfter()` を**唯一の解決点**にし、Service は config を直読しない。`AccountDeletionGraceConfigTest` で 30 固定 / 0 以下 fail-fast / 読み手 1 箇所 / `addDays(` 不使用を検査 |
| **B4 allowlist の `settings.account.destroy` が 30 日猶予を迂回できる** | **allowlist から外した**。予約中の意思は「30 日後に削除」であり、その状態で即時削除の口を開けると猶予が守ろうとしているもの (誤操作) をそのまま通す。「今すぐ消したい」なら**取消 → 即時削除**の 2 手。UI (B7) も予約中は削除ボタン群を出さずバナーだけにする。behavioral テスト「予約中は即時削除できない / 取消してからなら削除できる」を追加 |
| **B6 `via()` では二重 dispatch の 1 通を保証できない** | **主張の方を直した (dedup 機構は足さない)**。`ShouldBeUnique` は AGENTS.md ドメイン規約 11 が禁じており (unique lock は rollback で解放されず tx 内 dispatch と両立しない。`AutoRechargeTriggerJob` から撤去済み)、送達台帳の新設は思考原則 2 に反する。**一回性は既に永続状態遷移が担っている** — `requestAccountDeletion()` は予約中なら**通知を発火せず**冪等 no-op で返すため job が 1 つしか作られない (ドメイン規約 6)。`via()` の役割を**誤通知の防止であって dedup ではない**と docblock に明記。テストを「同一 payload job 2 つで 1 通」→「**予約 POST を 2 回叩いてもメール 1 通**」に差し替え。**job の再試行による重複は防がない**ことを保証しないものへ明記 |
| **C1b `SubscriptionItem` が enum に無い** | enum に **`SubscriptionItem` case を追加**。`clockStartColumn()` の契約を「自テーブル列名、または `{table}.{column}` の修飾名」に拡張し、子は `'subscriptions.ends_at'` を返す。gate の schema 照合も修飾名を解決する。purger は **6 本**、**実行順は子 → 親**を固定 |
| **C2b group key に `organization_id` が無い** | **`(organization_id, source, expires_at)`** に修正。実コードで確認した残高の粒度はこの 3 つで閉じる (`sumBalance()` は organization_id + source + expires_at。team/project 粒度は持たない)。**`source IS NULL` (legacy 行) は独立 group** として扱う (Purchased へ寄せると `sumActiveHolds` の legacy 除外規則と意味がズレる)。検証を 7 種に増やし「**組織ごとの残高が畳み込み前後で一致 (複数組織 fixture)**」を追加 |
| **C3a/C3b の自己矛盾 (blade の config 直読)** | blade を **`\App\Support\Legal\BillingRetention::years()` の直接呼び出し**へ変更。gate 検査 1 (config の読み手は `BillingRetention` のみ) と検査 2 (`years()` の呼び出し元 exact-fit 目録に blade を含む) が矛盾なく成立する。付録 A の文面案も修正済み |

## [Warning] への対応

| 指摘 | 対応 |
|---|---|
| A2 監査列が弱い | **列を 2 本に**した。`stripe_customer_redacted_id` (記録時点の `stripe_id` の写し) を追加。両列は同時に埋まり同時に null (片方だけの状態を作らない invariant をテストで固定)。`stripe_id` が null の組織には記録できない (写す値が無い) |
| B1 cast が mutable Carbon | 両列を **`'immutable_datetime'`** に。DTO 側でも `CarbonImmutable::instance()` で明示変換し二重に守る |
| B4 `logout` / `session.status` の遮断懸念 | 実読で両者が `auth`+`verified` group の**外**にあることを確認。ただし「今そう」と「これからもそう」は別なので **gate 検査 7 「`logout` / `session.status` が母集団 `U` に含まれない」** を追加 (group の中へ移されたら fail)。behavioral 「予約中でもログアウトできる」も追加 |
| B5 抽出条件と pending 定義のズレ | 抽出条件に **`whereNotNull('deletion_requested_at')`** を追加。さらに**片列だけの非正規行を検出し、0 件でなければ `report()` + `unexpectedFailures` に計上 (= FAILURE)** する fail-closed を追加 |
| B8 `executeAccountDeletionRequest` の分類矛盾 | `directLock` → **`delegatedToLocked`** へ。併せて **`delegatedToLocked` を「メソッド名 => 必須の委譲先呼び出し」の map へ一般化**する (現状は `joinOrganization(` のハードコード。既存 3 本の判定は等価のまま = テストの意味を弱めない) |
| C1c `--apply` が C1 にある | **C1 の signature から `--apply` を削除** (`--target` のみ)。`--apply` は C2 で初めて追加する。dry-run 専用であることが signature そのもので表現される |

## [Suggestion] への対応

- A1: PR-B で起点を足すときに**閉包目録の差分理由をコメントで残す**ことを B8 の作業項目に明記
- B7: 既存 atom (`Alert`/`Button`/`TextLink`/`DangerZone`) の再利用・**hex 直書きを増やさない**・
  Lucide のみ・component 階層の単方向 import をテスト観点に明記
- C2b: `kind` は新 case **`TicketLedgerKind::CarryForward`** (既存 kind へ相乗りしない)、
  取引追跡列 6 つを null、`idempotency_key` は `carry_forward:...` で
  **既存 signup unique が `LIKE 'signup_grant:%'` の部分 unique のため衝突しない** (migration 実読)。
  **`TicketLedgerKind` の TS 同期と表示分岐**を波及変更に明記

## mutation 表に 6 行追加

M13b (group key から organization_id を外す) / M17 (allowlist に即時削除を足す) /
M18 (`logout` を group 内へ移す) / M19 (予約の冪等 no-op を外す) /
M20 (抽出条件から `deletion_requested_at` を外す) / M21 (`deletion_grace_days` を 0 にする)。

---

## 改訂版 詳細設計書

# 詳細設計: account-deletion-grace

> 概念設計: `devnotes/20260809-0908-account-deletion-grace/conceptual-design.md` (Codex 合議 8 ラウンドで APPROVED)
> 一次入力: 同ディレクトリ `recon-brief.md` / lctl 台帳 feature `account-deletion-billing-guard` (標準形 v1 / 裁定 AG-128)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`) / **Pest** (`composer test`)
- **RefreshDatabase** は `tests/Pest.php` でグローバル適用 (`--parallel` 実行)。
  個別 `DatabaseTransactions` 禁止
- **テストデータは必ず Factory で生成** (`Model::create()` 手組み禁止)
- **DTO + JsonResource** パターン / **アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く (Service 委譲)、transaction は Service 内
- 月/年の加減算は `*NoOverflow` 明示 (`CarbonOverflowArithmeticGateTest` が検出)
- `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

### オーナー決定 (逸脱不可)

| 項目 | 値 |
|---|---|
| 猶予期間 | **30 日** |
| 課金取引記録の保持 | **7 年** |
| 猶予中の扱い | **凍結方式** (users 行の生死を変えない = SoftDeletes を使わない) |
| 規約文面 | **spirux の /privacy「取引関係書類等につき最長 7 年」に揃える。独自の法的主張を書かない** |
| `config/legal.php` の `consent_version` | **`draft-1` から動かさない** |
| 追記文面の位置づけ | **法務レビュー前の草案**。設計・実装・runbook・PR 説明の 4 箇所に明記する |

---

## 施策一覧

**5 PR に分割し A → B → C1 → C2 → C3 の順で直列に main へ入れる** (概念設計 §6)。

| # | 施策名 | 変更ファイル | PR | 優先度 |
|---|--------|------------|----|--------|
| A1 | 退会経路の依存閉包 gate | `tests/Architecture/AccountDeletionPathGateTest.php` (新) + fixture | A | Critical |
| A2 | redaction 記録列 2 本とコマンド | `database/migrations/*_add_stripe_customer_redaction_columns_to_organizations_table.php` (新) / `app/Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php` (新) / `app/Models/Organization.php` | A | High |
| A3 | 退会 runbook | `docs/account-deletion-runbook.md` (新) / `docs/architecture.md` | A | High |
| B0 | 猶予日数の単一出典 | `config/account.php` (新) / `app/Support/Account/AccountDeletionGrace.php` (新) / `tests/Architecture/AccountDeletionGraceConfigTest.php` (新) | B | Critical |
| B1 | 予約列 | `database/migrations/*_add_deletion_request_columns_to_users_table.php` (新) / `app/Models/User.php` | B | Critical |
| B2 | 予約 / 取消 (Service) | `app/Services/Organization/OrganizationMembershipService.php` / `app/DataTransferObjects/Account/AccountDeletionStateDto.php` (新) | B | Critical |
| B3 | 予約 / 取消 (HTTP) | `app/Http/Controllers/Settings/AccountDeletionRequestController.php` (新) / `routes/web.php` | B | Critical |
| B4 | 凍結 middleware (deny-by-default) | `app/Http/Middleware/EnsureAccountNotPendingDeletion.php` (新) / `app/Enums/Account/AccountDeletionFreezeAllowance.php` (新) / `bootstrap/app.php` / `routes/web.php` | B | Critical |
| B5 | 日次執行バッチ | `app/Console/Commands/Account/PurgeDeletionRequestsCommand.php` (新) / `routes/console.php` | B | Critical |
| B6 | 通知・監査 | `app/Enums/SecurityEventType.php` / `app/Notifications/Account/AccountDeletionRequestedNotification.php` (新) / `app/Services/Notification/NotificationCenterService.php` | B | High |
| B7 | UI | `app/Http/Controllers/Settings/ProfileController.php` / `resources/js/pages/Settings/Index.svelte` / `resources/js/types/account.ts` | B | High |
| B8 | 既存 gate 更新 | `tests/Architecture/RecentAuthRouteTest.php` / `ControllerAuthorizationGateTest.php` / `MembershipWriteLockInventoryTest.php` / `TenantBoundaryOrderingTest.php` / `JobExecutionDedupInventoryTest.php` | B | Critical |
| C1a | 保持年数の単一出典 | `config/legal.php` / `app/Support/Legal/BillingRetention.php` (新) | C1 | High |
| C1b | purge の対象目録と起算点 | `app/Enums/Billing/BillingRetentionTarget.php` (新) / `BillingRetentionExclusion.php` (新) / `app/Contracts/Billing/BillingRetentionPurger.php` (新) / `app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php` (新) / target ごとの purger **6 本** (新) | C1 | High |
| C1c | purge コマンド (**`--apply` を持たない**) | `app/Console/Commands/Billing/PurgeBillingRetentionCommand.php` (新) | C1 | High |
| C1d | 目録 gate + horizon | `tests/Architecture/BillingRetentionTargetInventoryTest.php` (新) / `tests/Feature/Billing/BillingRetentionHorizonTest.php` (新) | C1 | High |
| C2a | ledger reader 目録 | `tests/Architecture/TicketLedgerReaderInventoryTest.php` (新) | C2 | High |
| C2b | ledger 畳み込み | `app/Services/Billing/TicketLedgerCarryForwardService.php` (新) / migration (繰越列) / `TicketLedgerEntry` | C2 | High |
| C2c | 日次登録 + `--apply` | `routes/console.php` | C2 | High |
| C2d | 有効化 runbook | `docs/billing-retention-runbook.md` (新) | C2 | High |
| C3a | 規約文面 (草案) | `resources/views/legal/privacy.blade.php` | C3 | High |
| C3b | 三者一致 gate | `tests/Architecture/BillingRetentionSingleSourceTest.php` (新) / `tests/Feature/Legal/PrivacyRetentionDeclarationTest.php` (新) | C3 | High |

---

# PR-A: 決済事業者側データの扱い

## A1. 退会経路の依存閉包 gate

### 変更箇所
- 新規: `tests/Architecture/AccountDeletionPathGateTest.php`
- 新規: `tests/Architecture/Fixtures/AccountDeletionPath/` (正負 fixture 6 形)
- 新規: `app/Enums/Security/DeletionPathSeamExemption.php` (型付き免除)

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 既存 `tests/Feature/Auth/AccountDeletionTest.php` は**変更しない**
  (behavioral 2 本はそのまま残す。静的 gate と behavioral は**並存**させる)

### 現行コード
静的 gate は存在しない。原則を固定しているのは `AccountDeletionTest` の 2 本のみ:
「退会成功経路では決済事業者 API を呼ばない」「課金中でブロックされる経路でも決済事業者 API を呼ばない」。

### 設計

```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: **退会 (アカウント削除) 経路の依存閉包から決済事業者 SDK へ到達しない**。
 *
 * SoT = lctl 台帳 feature `account-deletion-billing-guard` の標準形 v1 (裁定 AG-128) と
 * docs/architecture.md §退会 (アカウント削除) の課金ガード。
 *
 * ★なぜ静的検査か (behavioral では捕まらない):
 *   既存の tests/Feature/Auth/AccountDeletionTest.php の 2 本は「その経路で今日呼ばれなかった」
 *   ことしか言えない。**新しい依存を注入した瞬間に沈黙する**。laravel-claude-template では実際に
 *   「依存閉包の抽出が型宣言だけの注入を素通りさせていた」fail-open が実装レビューで見つかっている。
 *
 * ★保証するもの:
 *   - 検査 1: 起点 3 つ (AccountController::destroy / OrganizationMembershipService::deleteAccount /
 *     PurgeDeletionRequestsCommand::handle) から辿れる app/ 内クラスの閉包が目録と exact-fit
 *   - 検査 2: 閉包内のどのクラスも決済事業者記号 (Stripe\* / Laravel\Cashier\Cashier::stripe /
 *     ->stripe() / Billable の stripe 系) を参照しない
 *   - 検査 3: 免除は DeletionPathSeamExemption (型付き enum) + 30 文字以上の根拠のみ
 *   - 検査 4: 空振り検知 (走査ファイル数 / 解決できた到達辺 / 閉包サイズが 0 でない)
 *   - 検査 5: 自己参照コントロール (本ファイル自身を走査して到達 0 件・記号 hit なし)
 *   - 検査 6-11: 正負 fixture 6 形 (型注入のみ / facade / static call /
 *     app()・resolve()・make() の literal 引数 / trait 経由 / 動的メソッド名)
 *
 * ★保証しないもの (誇張しない):
 *   - 文字列キーが変数の container 解決 (`$c->make($name)`)。受け手を解決できない
 *   - vendor 内部から出る通信 (Cashier の WebhookController は閉包の外)
 *   - 完全修飾 docblock だけで型宣言も import も無い受け手 (docblock 解析はしない)
 *   - 実行時 config による bind 差し替え
 *   - **これは検知であって遮断ではない**
 *
 * 解析は Tests\Support\PhpReferenceScanner に乗せる (namespace 解決 / alias / scope 追跡を
 * ExternalSeamInventoryTest / ExternalClientTimeoutInventoryTest と共有する。自前の走査器を作らない)。
 * DB 不使用 (Architecture lane は TestCase のみ)。
 */
```

- **起点 (roots)**: `app/Http/Controllers/Settings/AccountController.php::destroy` /
  `app/Services/Organization/OrganizationMembershipService.php::deleteAccount` /
  (PR-B 以降) `app/Console/Commands/Account/PurgeDeletionRequestsCommand.php::handle`。
  **PR-A の時点では 2 つ**、PR-B で 1 行足す (概念設計の依存順の根拠)。
- **閉包の辿り方**: 型宣言 (constructor / method parameter / promoted property / property) の FQCN、
  static call の受け手、facade、`app()`/`resolve()`/`make()` の literal 第 1 引数、`use` した trait。
- **免除 enum**:

```php
enum DeletionPathSeamExemption: string
{
    // 現時点で case は 0 本。閉包内に決済事業者記号は 1 つも無い。
    // case を足すときは 30 文字以上の根拠を inventory に書くこと。
}
```

### PHPStan 適合チェック
- [x] Architecture テストは Pest の `test()` 関数群。戻り値型は `void`
- [x] `PhpReferenceScanner` の戻り値 (`ReferenceScanResult`) をそのまま使い配列を組み立てない
- [x] `list<string>` / `array<string, string>` の型注釈を全ヘルパに付ける

### テスト計画
- [ ] 新規: `AccountDeletionPathGateTest` (上記 11 検査)
- [ ] **mutation で赤化を確認する手順** (§共通/mutation) を実施
- [ ] 既存 `AccountDeletionTest` は 1 行も変更しない (禁止事項 3)

### リスク
- 閉包が大きくなりすぎて exact-fit 目録の保守が重くなる。→ 起点を 3 つに限定し、
  閉包は `app/` 内に限る (`vendor/` は辿らない)。実測サイズを floor/cap 両方で pin する。

---

## A2. redaction 記録列とコマンド

### 変更箇所
- 新規 migration: `organizations.stripe_customer_redacted_at` (nullable timestamp) +
  **`organizations.stripe_customer_redacted_id` (nullable string)**
- 新規: `app/Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php`
- 変更: `app/Models/Organization.php` (casts に `stripe_customer_redacted_at => 'immutable_datetime'`)

### 波及変更
- TypeScript 型定義: なし (画面に出さない。運用専用)
- API Resource/DTO: なし
- テストファイル: 新規 `tests/Feature/Billing/MarkStripeCustomerRedactedCommandTest.php`

### 設計

```php
protected $signature = 'billing:mark-stripe-customer-redacted
    {organization : 組織 ID}
    {--apply : 実記録する (未指定は dry-run)}';
```

- **Stripe API は呼ばない**。人手 redaction の**実施記録**のみ。
- **1 回限り (冪等)**: `organizations` 行を `lockForUpdate()` した中で
  `stripe_customer_redacted_at === null` を再確認し、既記録なら
  「YYYY-MM-DD に記録済み」を表示して no-op (SUCCESS)。
- **記録は 2 列セット**: `stripe_customer_redacted_at` (実施日時) と
  **`stripe_customer_redacted_id` (記録時点の `stripe_id` の写し)**。
  日時だけだと「**どの** customer を redact 済みと記録したか」が後から検証できず、
  `stripe_id` が差し替わる経路が将来できたときに監査列として意味を失う。
  **両列は同時に埋まり同時に null である** (片方だけの状態を作らない invariant をテストで固定)。
- **`stripe_id` が null の組織には記録できない** (fail-closed)。写す値が無いため。
- **organization の解決は console 引数由来の主キー同一性クエリ**になるため、
  `ModelDirectFetchInvariantTest` + `DirectFetchInventory` へ登録する
  (AGENTS.md セキュリティ不変条件 3)。分類は「運用コマンドの明示 ID 指定 (cross-org の概念が無い)」。

### PHPStan 適合チェック
- [x] `$this->argument('organization')` は `mixed` → `Assert::stringNotEmpty()` で narrowing
- [x] 戻り値 `int` (`self::SUCCESS` / `self::FAILURE`)
- [x] `Organization::query()->whereKey(...)->lockForUpdate()->first()` の `?Organization` を
      `Assert::isInstanceOf` ではなく early return で扱う (不在は FAILURE)

### テスト計画
- [ ] 新規: dry-run は列を書かない
- [ ] 新規: `--apply` で `stripe_customer_redacted_at` と `stripe_customer_redacted_id` が**両方**入る
- [ ] 新規: **片方だけが埋まった状態を作れない** (2 列セットの invariant)
- [ ] 新規: 二重実行は no-op + 既記録日を表示 (SUCCESS)
- [ ] 新規: `stripe_id` が null の組織では FAILURE で記録しない
- [ ] 新規: **Stripe API を 1 回も呼ばない** (`FakeStripeGateway` / `Http::preventStrayRequests` で固定)

### リスク
- 列を作っても運用されず死蔵する → A3 の runbook で「detect バッチの出力 id を起点にする」
  手順を書き、完了条件に含める。

---

## A3. 退会 runbook

### 変更箇所
- 新規: `docs/account-deletion-runbook.md`
- 変更: `docs/architecture.md` §退会 (アカウント削除) の課金ガード (T115) から runbook へリンク

### 内容 (完了条件)
1. **対象組織の解決手順**: 日次 `billing:detect-orphan-billing-organizations` が `report()` する
   organization id を起点にする (新しい探索経路を作らない)。
2. **Stripe ダッシュボード側の操作手順**と実施者・実施日の残し方。
3. **二重実行時の表示** (既記録なら no-op)。
4. **一次情報 URL と確認日**。`docs/architecture.md` が「台帳側に一次情報の URL が pin されていない。
   数値を運用に効かせる前に一次情報を引き直せ」と書いているため、
   90 日 / 最大 30 日は**引き直して URL + 確認日をセットで書く**。
   引けなければ「**未 pin**」と明記し、**数値を運用に効かせない**。
5. **保証しないもの**: アプリからの自動 redaction は行わない。

---

# PR-B: 猶予期間つき削除 (凍結方式)

## B0. 猶予日数の単一出典

### 変更箇所
- 新規: `config/account.php`
- 新規: `app/Support/Account/AccountDeletionGrace.php`
- 新規: `tests/Architecture/AccountDeletionGraceConfigTest.php`

### 変更後コード

```php
// config/account.php
/*
| 退会 (アカウント削除) の猶予日数。**env を使わない** — 環境ごとに変えてよい運用値ではなく、
| オーナーが確定したプロダクト判断である (config/idempotency.php の retention_hours /
| config/legal.php の billing_retention_years と同じ理由)。
| 唯一の解決点は App\Support\Account\AccountDeletionGrace。Service は config を直読しない。
*/
return [
    'deletion_grace_days' => 30,
];
```

```php
final class AccountDeletionGrace
{
    /** 猶予日数 (唯一の解決点)。0 以下は fail-fast。 */
    public static function days(): int
    {
        $days = config()->integer('account.deletion_grace_days');
        Assert::greaterThan($days, 0, '猶予日数は 1 以上でなければならない');

        return $days;
    }

    /** 予約時刻から執行期限を導く。**NoOverflow を明示する**。 */
    public static function purgeAfter(CarbonImmutable $requestedAt): CarbonImmutable
    {
        return $requestedAt->addDaysNoOverflow(self::days());
    }
}
```

### 波及変更
- TypeScript 型定義: なし (猶予日数は `AccountDeletionStateDto::graceDays()` の導出値として渡る)
- API Resource/DTO: なし
- テストファイル: `AccountDeletionGraceConfigTest`

### PHPStan 適合チェック
- [x] 戻り値の型が明示 (`int` / `CarbonImmutable`)
- [x] `config()->integer()` を使い `mixed` を持ち込まない
- [x] `Assert::greaterThan` で fail-fast

### テスト計画
- [ ] 新規: 値が **30** であること
- [ ] 新規: 0 以下なら例外 (fail-fast)
- [ ] 新規: `config('account.deletion_grace_days')` を読んでよいのは
      `AccountDeletionGrace` **1 箇所だけ** (token 走査 + exact-fit caller inventory。
      `LegalConsentVersionSingleSourceTest` と同じ書式)
- [ ] 新規: `addDays(` (overflow 版) を使っていないこと
      (`CarbonOverflowArithmeticGateTest` の母集団に入る)

### リスク
- なし (新規 config + 純関数)。

## B1. 予約列

### 変更箇所
- 新規 migration: `users.deletion_requested_at` / `users.deletion_purge_after` (ともに nullable timestamp)
- 変更: `app/Models/User.php` の `casts()`

### 波及変更
- TypeScript 型定義: B7 で `resources/js/types/account.ts` に `AccountDeletionState` を追加
- API Resource/DTO: B2 で `AccountDeletionStateDto` を新設
- テストファイル: B2 以降で使用。`UserFactory` に **state を 1 つ追加**
  (`pendingDeletion()`。テストデータは必ず Factory で作る規約)

### 変更後コード

```php
// database/migrations/2026_08_09_000100_add_deletion_request_columns_to_users_table.php
/**
 * 猶予期間つき退会 (凍結方式) の予約列。
 *
 * **SoftDeletes は使わない**。users 行の生死を変えないのが凍結方式の定義で、
 * FK cascade / nullOnDelete / CipherSweet の blind index (email_index) の一意照合 /
 * passkey / OAuth セッション / 招待の email 照合がすべて users 行の実在を前提にしている。
 *
 * `deletion_purge_after` は **絶対時刻**で持つ (猶予日数のスナップショットにしない)。
 * 不可逆な物理削除のため config 変更を既予約へ遡及させてはならず、絶対時刻なら
 * 1 列でそれが表現でき、バッチのクエリも `where deletion_purge_after <= now()` の 1 条件で済む。
 * 猶予日数は `purge_after - requested_at` で導出する (2 つの表現を持たない)。
 */
public function up(): void
{
    Schema::table('users', function (Blueprint $table): void {
        $table->timestamp('deletion_requested_at')->nullable()->after('remember_token');
        $table->timestamp('deletion_purge_after')->nullable()->after('deletion_requested_at');
        // 日次バッチの走査条件。部分 index (NULL を含めない) で通常ユーザーの行を index に載せない
        $table->index('deletion_purge_after');
    });
}
```

```php
// app/Models/User.php casts() へ追加
// **immutable_datetime** を使う (DTO が CarbonImmutable 前提のため。'datetime' だと
// mutable Carbon が返り、DTO の型と食い違う)。
'deletion_requested_at' => 'immutable_datetime',
'deletion_purge_after' => 'immutable_datetime',
```

- **`$fillable` には入れない** (保護キー。`forceFill` でのみ書く)。
  `MassAssignmentSafetyTest` / `ProhibitsProtectedKeys` の対象に入るため、
  **`MassAssignmentProtectedKeys` への登録が必要かを実装時に確認する**
  (`current_organization_id` / `terms_accepted_at` と同じ扱い)。

### PHPStan 適合チェック
- [x] `casts()` の戻り値型 `array<string, string>` に適合
- [x] `$user->deletion_purge_after` は `?CarbonImmutable` (cast が `immutable_datetime`)。
      `AccountDeletionStateDto::fromUser()` 側でも `CarbonImmutable::instance()` で明示変換し、
      cast 設定の変更に対して二重に守る

### テスト計画
- [ ] 新規: migration の up/down が通る (既存の migration テスト方式に従う)
- [ ] 新規: `UserFactory::pendingDeletion()` が両列を埋める
- [ ] 新規: **mass-assignment で両列を書けない** (`MassAssignmentSafetyTest` の母集団に入る)

### リスク
- 部分 index の書き方が pgsql 固有になる → まず素の index で入れ、性能問題が出てから絞る
  (思考原則 2。予約中ユーザーは常に極少数)。

---

## B2. 予約 / 取消 (Service)

### 変更箇所
- `app/Services/Organization/OrganizationMembershipService.php` に public メソッド 3 本を追加
- 新規: `app/DataTransferObjects/Account/AccountDeletionStateDto.php`

### なぜ `OrganizationMembershipService` か
責務ではなく**ロック順序**が理由である。予約列の書き込みは `lockForMembershipWrite`
(users 昇順 → organizations 昇順) と同じ順序に乗せる必要があり、順序の SoT を 2 クラスに分けると
デッドロックの余地が生まれる。`deleteAccount()` と同じクラスにあれば順序の交差が構造的に起きない。

### 変更後コード

```php
    /**
     * 退会の予約 (猶予期間つき削除)。**凍結方式**なので users 行の生死は変えない。
     *
     * 冪等: 既に予約中なら **`purge_after` を延長しない**で既存の予約をそのまま返す
     * (二重送信で猶予が伸び続けるのを防ぐ。取消 → 再予約は明示操作)。
     *
     * **予約時にブロッカーを評価しない**。予約は退会の意思表示であって削除ではなく、
     * ブロックされている人が予約すらできないと「解約待ちの間は退会予約もできない」詰みになる。
     * 権威判定は執行時 (deleteAccount のロック下再評価) が担う。
     *
     * @return AccountDeletionStateDto 予約後の状態 (通知とレスポンスが同じ値を見る)
     */
    public function requestAccountDeletion(User $user): AccountDeletionStateDto
    {
        return DB::transaction(function () use ($user): AccountDeletionStateDto {
            // canonical 共通ロック境界 (users 昇順 → organizations 昇順)。organizations は不要だが
            // 順序の起点を deleteAccount と揃える (新しいロック順序を作らない)。
            $this->lockForMembershipWrite([$this->keyOf($user)], []);

            $fresh = $user->fresh();
            Assert::isInstanceOf($fresh, User::class);

            $state = AccountDeletionStateDto::fromUser($fresh);
            if ($state->isPending()) {
                return $state; // 冪等 no-op (延長しない)
            }

            $requestedAt = CarbonImmutable::now();
            // 猶予日数の解決は AccountDeletionGrace 1 箇所だけ (B0)。Service は config を直読しない。
            $purgeAfter = AccountDeletionGrace::purgeAfter($requestedAt);
            $fresh->forceFill([
                'deletion_requested_at' => $requestedAt,
                'deletion_purge_after' => $purgeAfter,
            ])->save();

            $this->recorder->record(SecurityEventType::AccountDeletionRequested, $fresh);

            // ドメイン規約 11: 業務状態の保存とキュー投入は**同一トランザクション内**で行う
            // (afterCommit に依存しない)。通知側が送信直前に予約の生存を再確認する (B6)。
            $fresh->notify(new AccountDeletionRequestedNotification($requestedAt, $purgeAfter));
            $this->notifications->notifyAccountDeletionRequested($fresh, $purgeAfter);

            return AccountDeletionStateDto::fromUser($fresh);
        });
    }

    /**
     * 退会予約の取消。**誤操作救済の本体**であり、ブロッカーの有無に関わらず必ず成功する。
     * 冪等: 予約が無ければ no-op。
     */
    public function cancelAccountDeletion(User $user): AccountDeletionStateDto
    {
        return DB::transaction(function () use ($user): AccountDeletionStateDto {
            $this->lockForMembershipWrite([$this->keyOf($user)], []);

            $fresh = $user->fresh();
            Assert::isInstanceOf($fresh, User::class);

            if (! AccountDeletionStateDto::fromUser($fresh)->isPending()) {
                return AccountDeletionStateDto::fromUser($fresh); // 冪等 no-op
            }

            $fresh->forceFill([
                'deletion_requested_at' => null,
                'deletion_purge_after' => null,
            ])->save();

            $this->recorder->record(SecurityEventType::AccountDeletionCancelled, $fresh);

            return AccountDeletionStateDto::fromUser($fresh);
        });
    }

    /**
     * 予約の執行 (日次バッチ専用)。**期限到来をロック下で再確認してから**
     * 既存の deleteAccount() をそのまま呼ぶ。判定コードを分岐させない。
     *
     * @return bool true = 削除した / false = 期限未到来 or 予約が消えていた (抽出後の取消)
     *
     * @throws ValidationException 退会ブロッカーが立っている (呼び出し側が「業務上の保留」として捌く)
     */
    public function executeAccountDeletionRequest(User $user): bool
    {
        $executed = false;

        $this->deleteAccount($user, null, function (User $locked) use (&$executed): bool {
            // deleteAccount のロック取得後・ガード評価前に呼ばれる前提条件フック。
            $state = AccountDeletionStateDto::fromUser($locked);
            $executed = $state->isDue(CarbonImmutable::now());

            return $executed;
        });

        return $executed;
    }
```

**`deleteAccount()` の変更 (最小)**: 第 3 引数 `?\Closure $precondition = null` を足す。

```php
    /**
     * @param  (\Closure(): void)|null  $beforeDelete  例外を投げないこと (投げると削除全体が rollback)
     * @param  (\Closure(User): bool)|null  $precondition  ロック取得直後・ガード評価**前**に
     *        呼ばれる前提条件。false を返すと**ガードを評価せず**削除せずに正常終了する
     *        (バッチが「抽出後に取消された」を検出する口。null なら常に true)
     */
    public function deleteAccount(User $user, ?\Closure $beforeDelete = null, ?\Closure $precondition = null): void
```

差し込み位置は「step 3 の fresh 取得直後・`organizationsBlockingDeletion()` 呼び出しの**前**」。
false のときは**ブロッカー判定に入らず** return する (取消済みユーザーに対して
ブロッカー例外を出さない = バッチが「保留」と誤分類しない)。

### `AccountDeletionStateDto`

```php
final readonly class AccountDeletionStateDto
{
    public function __construct(
        public ?CarbonImmutable $requestedAt,
        public ?CarbonImmutable $purgeAfter,
    ) {}

    public static function fromUser(User $user): self { /* … */ }

    /** 予約中か (両列が揃っているときだけ true = 片方だけの非正規状態を pending と認めない) */
    public function isPending(): bool
    {
        return $this->requestedAt !== null && $this->purgeAfter !== null;
    }

    /** 執行期限が到来しているか */
    public function isDue(CarbonImmutable $now): bool
    {
        return $this->isPending() && $this->purgeAfter <= $now;
    }

    /** 猶予日数 (表示用。導出値であり列を持たない) */
    public function graceDays(): ?int { /* diffInDays */ }

    /**
     * Inertia props 形。日時は **ISO 8601 文字列** (`toIso8601String()`)。
     *
     * @return array{requestedAt: string|null, purgeAfter: string|null, graceDays: int|null}
     */
    public function toArray(): array { /* … */ }
}
```

### 波及変更
- TypeScript 型定義: `resources/js/types/account.ts` に `AccountDeletionState` を追加 (B7)
- API Resource/DTO: 本 DTO が新設分
- テストファイル: `tests/Feature/Auth/AccountDeletionGraceTest.php` (新)、
  `tests/Architecture/MembershipWriteLockInventoryTest.php` (B8)

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている (`AccountDeletionStateDto` / `bool` / `void`)
- [x] null 安全 (`Assert::isInstanceOf($fresh, User::class)` で `fresh()` の `?User` を narrowing)
- [x] DTO を返している (配列返却なし)
- [x] Closure の型は `(\Closure(User): bool)|null` で phpdoc に明示

### テスト計画
- [ ] 新規: 予約 → 両列が入る / SecurityEvent `account_deletion_requested` が 1 件
- [ ] 新規: **二重予約で `purge_after` が延びない** (冪等)
- [ ] 新規: 取消 → 両列が null / SecurityEvent `account_deletion_cancelled`
- [ ] 新規: **ブロッカーがあっても予約できる** (予約時に評価しない契約)
- [ ] 新規: **ブロッカーがあっても取消できる**
- [ ] 新規: 執行 → 期限到来なら削除 / 未到来なら false で無変更
- [ ] 新規: **抽出後に取消 → `executeAccountDeletionRequest` が false を返し削除しない**
- [ ] 新規: TOCTOU — 予約と `deleteAccount` の並行実行でロック順序が交差しない
      (既存 `MembershipWriteLockInventoryTest` の drift-guard + 実行順テスト)

### リスク
- `deleteAccount()` のシグネチャ変更が既存呼び出し (`AccountController::destroy`) に波及する
  → 第 3 引数は**省略可能**にするので既存呼び出しは無変更。
  既存 16 本のアサーションは崩れない (禁止事項 3)。

---

## B3. 予約 / 取消 (HTTP)

### 変更箇所
- 新規: `app/Http/Controllers/Settings/AccountDeletionRequestController.php`
- 変更: `routes/web.php` (`settings.account.destroy` の直下に 2 本)

### 変更後コード

```php
// routes/web.php (auth + verified group 内、settings.account.destroy の直後)

// 退会の予約 (猶予 30 日)。**UI の主導線**。即時削除と同水準の機微操作のため step-up 必須。
Route::post('/settings/account/deletion-request', [AccountDeletionRequestController::class, 'store'])
    ->middleware('recent-auth')
    ->name('settings.account.deletion-request.store');
// 退会予約の取消。**誤操作救済の本体**なので step-up を課さない
// (救済経路に関門を足すと「取り消せない」詰みの再生産になる。取消は権限を増やす操作ではない)。
Route::delete('/settings/account/deletion-request', [AccountDeletionRequestController::class, 'destroy'])
    ->name('settings.account.deletion-request.destroy');
```

```php
final class AccountDeletionRequestController extends Controller
{
    public function store(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $state = $membership->requestAccountDeletion($user);

        // 操作系 POST は back() で完結させる (禁止事項 7: intended() を使わない)
        return back()->with('success', "退会を予約しました。{$state->purgeAfterLabel()}までは取り消せます。");
    }

    public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $membership->cancelAccountDeletion($user);

        return back()->with('success', '退会の予約を取り消しました。');
    }
}
```

### 波及変更
- TypeScript 型定義: B7
- API Resource/DTO: なし (Inertia の flash と props)
- テストファイル: `tests/Feature/Auth/AccountDeletionGraceTest.php`

### 既存 gate への登録 (B8 と重複するがここで根拠を書く)
- **`RecentAuthRouteTest`**: `settings.account.deletion-request.store` を allowlist に追加。
  `destroy` は**追加しない** (救済経路に step-up を課さない)。
- **`ControllerAuthorizationGateTest`**: 2 本とも `$selfScoped` で登録。根拠:
  「対象は `$request->user()` 自身のみ。route に他者を指せる parameter が 1 つも無く、
  他人のアカウントへ到達する経路がコード上存在しない。予約は step-up (recent-auth) を必須にし、
  取消は権限を増やさない操作のため関門を置かない」。
- **`ThrottleCoverageInventoryTest`**: **登録不要**。実コードで確認した根拠 —
  母集団は S1 (未認証で到達しうる変更系。本 route は `Authenticate` 配下なので該当しない) ∪
  S2 (`api/`・`oauth/`・`.well-known/oauth-` prefix。該当しない) ∪
  S3 (`throttleCoverageAuthSurfacePattern()` に一致する route 名。パターンは
  `settings\.password\.` は含むが **`settings\.account\.` は含まない**) であり、
  `settings.account.deletion-request.*` はどれにも入らない。
  既存の `settings.account.destroy` も同じ理由で登録されていない。
  **recon-brief の「ThrottleCoverageInventoryTest の更新が要る」は誤りである** (実読で訂正)。
- **`LoginMethodRemovalRouteTest`**: 予約は認証手段を減らさないので**登録不要**
  (実装時に母集団定義を再確認する)。

### PHPStan 適合チェック
- [x] `$request->user()` の `?Authenticatable` を `Assert::isInstanceOf` で narrowing
- [x] 戻り値 `RedirectResponse` 明示
- [x] `response()->json()` を使わない (Inertia の back + flash)

### テスト計画
- [ ] 新規: step-up 無しでは予約できない (302 → recent-auth.confirm)
- [ ] 新規: step-up 済みなら予約でき flash が出る
- [ ] 新規: **step-up 無しでも取消できる** (救済経路)
- [ ] 新規: 未認証は 302 login
- [ ] 新規: 他人のアカウントを指す口が無い (route parameter 不在の構造的検証)

### リスク
- 取消に step-up を課さないことで、セッション奪取者が予約を取り消せる。
  → **これは受け入れる**。奪取者が取り消しても失われるのは「退会の意思」だけで、
  本人は再度予約できる。逆に取消に関門を付けると、**本人が救済できない**方が重い被害になる。
  設計判断として docblock に明記する。

---

## B4. 凍結 middleware (deny-by-default)

### 変更箇所
- 新規: `app/Http/Middleware/EnsureAccountNotPendingDeletion.php`
- 新規: `app/Enums/Account/AccountDeletionFreezeAllowance.php`
- 変更: `bootstrap/app.php` (alias 登録 + priority list の web 鎖へ append)
- 変更: `routes/web.php` (`auth` + `verified` group へ付与)
- 新規: `tests/Architecture/AccountDeletionFreezeRouteGateTest.php`

### 現行コード (priority list の web 鎖。`bootstrap/app.php` L233-254)

```php
foreach ([
    [EnsureProjectBelongsToCurrentOrganization::class, HandleInertiaRequests::class],
    // …
    [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
] as [$after, $append]) {
    $middleware->appendToPriorityList($after, $append);
}
```

### 変更後コード

```php
    [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
    // 退会予約中の凍結。**302 で短絡する**ため、テナント境界 404
    // (EnsureProjectBelongsToCurrentOrganization) より必ず後に置く。
    // 前に置くと「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
    // (AGENTS.md 不変条件 10)。課金ゲートの直後に置き、未契約組織のユーザーは
    // 課金ゲート → onboarding → 凍結 → /settings の 2 hop で取消 UI に着く。
    [RequireActiveSubscription::class, EnsureAccountNotPendingDeletion::class],
```

```php
// routes/web.php
Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(function (): void {
```

**route:cache 前提**: group への直付けで配線する。`RouteMiddlewareBinder` の後付けは使わない
(cached 起動では 1 本も効かず無音で保護が外れる = T135 / AGENTS.md 運用要件)。

```php
/**
 * 退会予約中 (凍結) の route allowlist。**deny-by-default**。
 *
 * ここに無い route は予約中に遮断され `/settings` (取消ボタンのある画面) へ 302 する。
 * **wildcard を書かない** (route 名の exact case のみ)。`billing.*` のような namespace 指定を
 * 許すと購入・新規契約・自動チャージ有効化まで一緒に通り、凍結の意味が消える。
 */
enum AccountDeletionFreezeAllowance: string
{
    // --- 取消に到達するための step-up ---
    case RecentAuthConfirm = 'recent-auth.confirm';
    case RecentAuthStatus = 'recent-auth.status';
    case RecentAuthPassword = 'recent-auth.password';
    // --- 取消 UI と取消そのもの ---
    case Settings = 'settings';
    case DeletionRequestDestroy = 'settings.account.deletion-request.destroy';
    // --- 退会ブロッカー (生きた課金責務) の解消 ---
    case BillingIndex = 'billing.index';
    case BillingPortal = 'billing.portal';
    case AutoRechargeUpdate = 'billing.auto-recharge.update';
    // --- 退会ブロッカー (孤児メンバー) の解消 ---
    case OrganizationSwitch = 'organizations.switch';
    case OrganizationSettings = 'organizations.settings';
    case TransferOwnership = 'organizations.transfer-ownership';
    case MemberUpdate = 'organizations.members.update';
    case MemberDestroy = 'organizations.members.destroy';
    case InvitationRevoke = 'organizations.invitations.revoke';
    // --- 予約・執行不能を知る手段 (読むだけ) ---
    case NotificationsIndex = 'notifications.index';
    case NotificationsReadAll = 'notifications.read-all';
    case NotificationsRead = 'notifications.read';

    /** 30 文字以上の根拠 (gate が長さを検査する)。 */
    public function rationale(): string { /* case ごとに 30 文字以上 */ }
}
```

**`settings.account.destroy` (即時削除) を入れない**: 予約中のユーザーが表明した意思は
「30 日後に削除」であり、その状態で即時削除の口を開けておくと**猶予が守ろうとしているもの
(誤操作) をそのまま通してしまう** (30 日猶予の迂回口になる)。「今すぐ消したい」なら
**取消 → 即時削除**の 2 手を踏む。一貫した状態機械でありユーザーに説明できる。
UI 側も予約中は削除ボタン群を出さず、バナー (取消 + 次の一手) だけを出す (B7)。

**`notifications.open` を入れない**: POST + 303 で**通知の遷移先へ飛ばす** route であり、
allowlist に入れると「通知経由なら業務 route / `dashboard` / checkout に到達できる」抜け道になる
(deny-by-default を自ら迂回する)。通知は `notifications.index` で読めるので rescue surface の役割は
満たされる。**「遷移先ごとに判定する」分岐は作らない** (凍結の判定点が 2 箇所に増える。思考原則 2)。

### middleware 本体

```php
final class EnsureAccountNotPendingDeletion
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request); // 未認証は auth middleware の責務
        }
        if (! AccountDeletionStateDto::fromUser($user)->isPending()) {
            return $next($request);
        }

        $name = $request->route()?->getName();
        if ($name !== null && AccountDeletionFreezeAllowance::tryFrom($name) !== null) {
            return $next($request);
        }

        // JSON/XHR は 409 Conflict (状態が操作と矛盾している)。402 (課金) とは別事由。
        if ($request->expectsJson()) {
            abort(Response::HTTP_CONFLICT, self::FROZEN_MESSAGE);
        }

        // 403 で突き放さず、取消ボタンのある画面で受ける (ドメイン規約 4 と同じ思想)。
        // 遮断理由の flash は積まない — 理由は着地ページ (/settings の予約バナー) が持つ。
        $request->session()->reflash();

        return redirect()->route('settings');
    }
}
```

### `AccountDeletionFreezeRouteGateTest` (6 検査)

`U` = 凍結 middleware が付いた全 route、`A` = enum の route 名集合。**`A ⊆ U`**。

1. **`A ⊆ U`** — allowlist に `U` 外の route 名を書けない
2. enum の route 名が**実在し、凍結 middleware を実際に持つ**
3. **middleware が実際に bypass する集合と `A` が exact-fit** (実装と宣言の一致)
4. **`U - A` の route は予約中に遮断される**ことを behavioral に**全件**検査
5. **`U` に無名 route があれば fail** (名前で allowlist を書けないため)
6. **enum は wildcard を持たない** (`*` を含む case があれば fail) / 各 case の
   `rationale()` が **30 文字以上**
7. **`logout` / `session.status` が `U` に含まれない** ことを固定する。
   両者は現在 `auth` + `verified` group の外にある (実読で確認) が、
   誰かが group の中へ移したら fail して気づける (認証回復・離脱の手段を凍結させない)
- 加えて **空振り検知**: `U` の件数 floor / `A` の件数 exact / 母集団 0 件で fail

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `TenantBoundaryOrderingTest` (順序契約に 1 行追加)

### PHPStan 適合チェック
- [x] `$request->route()?->getName()` の `?string` を早期 return で narrowing
- [x] `Closure(Request): Response` の phpdoc
- [x] `enum ... : string` の `tryFrom` は `?self` を返す (null 検査あり)

### テスト計画
- [ ] 新規: 予約中に `/projects` が `/settings` へ 302
- [ ] 新規: 予約中に `/settings` は 200、取消できる
- [ ] 新規: **予約中は `settings.account.destroy` (即時削除) が遮断される。
      取消してからなら削除できる** (30 日猶予の迂回口を作らない)
- [ ] 新規: **予約中でもログアウトできる** (`logout` は母集団 `U` の外)
- [ ] 新規: 予約中に `billing.portal` / `organizations.transfer-ownership` に到達できる
- [ ] 新規: **予約中に `billing.checkout` / `billing.tickets.checkout` /
      `billing.auto-recharge.setup` / `organizations.store` /
      `organizations.invitations.store` / `notifications.open` が遮断される**
- [ ] 新規: **未予約ユーザーには一切影響しない** (全 route が従来どおり)
- [ ] 新規: XHR は 409
- [ ] 新規 (到達性 4 本):
      (a) セッション切れ → 再ログイン → 取消完了、
      (b) recent-auth 期限切れ → 取消完了、
      (c) 2FA 必須組織のユーザー → 取消完了、
      (d) **予約バナー / `/settings` から 解約 / 移譲 / メンバー整理 / 招待取消の各画面へ到達できる**
- [ ] 新規: **テナント境界 404 が凍結 302 より前**であること
      (`TenantBoundaryOrderingTest` に順序を 1 行追加 + 他組織の `{project}` は
      予約中でも **404** であって 302 でないことを behavioral に固定)

### リスク
- 認証回復系まで凍結して詰む → **構造的に起きない**。凍結 middleware は
  `routes/web.php` の `auth` + `verified` group にのみ付き、Fortify / Passkeys が登録する
  ログイン・パスワード再設定・メール確認・2FA challenge は**この group の外**にある。
  この事実自体を gate の検査 (母集団 `U` の列挙) が可視化する。

---

## B5. 日次執行バッチ

### 変更箇所
- 新規: `app/Console/Commands/Account/PurgeDeletionRequestsCommand.php`
- 変更: `routes/console.php`

### 変更後コード

```php
protected $signature = 'account:purge-deletion-requests
    {--apply : 実削除する (未指定は dry-run)}';
```

```php
public function handle(OrganizationMembershipService $membership): int
{
    $apply = (bool) $this->option('apply');
    $due = 0;
    $deleted = 0;
    $blocked = 0;          // 業務上の保留 (ValidationException)
    $unexpected = 0;       // インフラ障害 / 不変条件違反

    // 片列だけの非正規行を due に数えないため両列を条件にする
    // (DTO の pending 定義「両列が揃う」と一致させる)。
    User::query()
        ->whereNotNull('deletion_requested_at')
        ->whereNotNull('deletion_purge_after')
        ->where('deletion_purge_after', '<=', CarbonImmutable::now())
        ->orderBy('id')
        ->chunkById(100, function (Collection $users) use (&$due, &$deleted, &$blocked, &$unexpected, $apply, $membership): void {
            foreach ($users as $user) {
                $due++;
                if (! $apply) {
                    continue;
                }
                try {
                    // ロック取得後に「予約が生きているか」「期限到来か」を再確認する
                    // (抽出後に取消されたユーザーを古いスナップショットで消さない)。
                    if ($membership->executeAccountDeletionRequest($user)) {
                        $deleted++;
                    }
                } catch (ValidationException $e) {
                    // 退会ブロッカー = **業務上の保留**。予約は維持し次へ進む。
                    $blocked++;
                    report($e);
                } catch (Throwable $e) {
                    // インフラ障害 / 不変条件違反 = **想定外**。継続はするが終了コードは FAILURE。
                    $unexpected++;
                    report($e);
                }
            }
        });

    $this->info("due={$due} deleted={$deleted} blocked={$blocked} unexpected={$unexpected}");

    // 終了コードは 2 分類。全件 DB 障害でも SUCCESS を返すと scheduler の失敗通知も
    // 終了コード監視も機能しなくなる (report() の成功自体も保証されない)。
    return $unexpected > 0 ? self::FAILURE : self::SUCCESS;
}
```

- **`chunkById`** を使う (走査中の削除で行が飛ばない)。`chunk` は使わない。
- **片列だけが埋まった非正規行を検出する** (`deletion_requested_at` のみ / `deletion_purge_after` のみ)。
  **0 件でなければ `report()` + `unexpectedFailures` に計上する** (黙って無視しない = fail-closed)。
  抽出条件から漏れた行が永久に放置されるのを防ぐ。
- **`whereNotNull('deletion_purge_after')`** は「クラス起点の主キー同一性クエリ」ではない
  (主キー等値でない) ため `DirectFetchInventory` の対象外。実装時に
  `ModelDirectFetchInvariantTest` の母集団定義で再確認する。
- ログには **件数のみ**。user id・email を出さない (PII 非出力。既存
  `billing:detect-orphan-billing-organizations` の報告契約と同水準)。

```php
// routes/console.php (既存の作法に揃える)
/*
|--------------------------------------------------------------------------
| 退会予約の執行 (猶予期間つき削除)
|--------------------------------------------------------------------------
| deletion_purge_after を過ぎた退会予約を執行する。判定は既存の
| OrganizationMembershipService::deleteAccount() が行う (課金ガードのロック下再評価をそのまま継承)。
| 退会ブロッカーは業務上の保留として次へ進み、想定外例外があれば FAILURE で終わる。
|
| **監視対象**: 本コマンドの終了コードと report()。
*/
Schedule::command('account:purge-deletion-requests --apply')->daily()->onOneServer();
```

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/AccountDeletionGraceTest.php` に執行系を追加

### PHPStan 適合チェック
- [x] `$this->option('apply')` は `mixed` → `(bool)` ではなく `Assert::boolean()` か
      `$this->option()` の bool 化ヘルパを使う (実装時に既存 Command の作法へ揃える)
- [x] `chunkById` の callback 引数 `Collection<int, User>` を phpdoc で明示
- [x] 戻り値 `int`

### テスト計画
- [ ] 新規: dry-run は 1 人も削除しない
- [ ] 新規: 期限到来ユーザーが削除される / 未到来は残る (境界: 1 秒前 / 1 秒後)
- [ ] 新規: **抽出後に取消 → 削除されない**
- [ ] 新規: **同日 2 回実行で二重削除・二重通知が起きない**
- [ ] 新規: **1 人目でブロッカー例外が出ても 2 人目は削除される** (失敗分離)
- [ ] 新規: **ブロッカーだけなら終了コード SUCCESS**
- [ ] 新規: **想定外例外が 1 件でもあれば終了コード FAILURE** (走査は最後まで続く)
- [ ] 新規: **片列だけの非正規行があれば report + FAILURE** (削除もしない)
- [ ] 新規: **決済事業者 API を呼ばない** (`Http::preventStrayRequests` + fake gateway)
- [ ] 新規: ログに user id / email が出ない

### リスク
- 大量の期限到来ユーザーで実行時間が伸びる → chunk 100 + 1 人ずつ独立 tx。
  タイムアウトしても次回が続きから拾う (状態は DB 側にある)。

---

## B6. 通知・監査

### 変更箇所
- `app/Enums/SecurityEventType.php` に 2 case
  (`AccountDeletionRequested` / `AccountDeletionCancelled`)
- 新規: `app/Notifications/Account/AccountDeletionRequestedNotification.php`
- `app/Services/Notification/NotificationCenterService.php` にアプリ内通知 1 本
- `app/Enums/NotificationType.php` に 1 case (+ `resources/js/types/notification.ts` 同期)

### 設計 (メール通知)

```php
final class AccountDeletionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CarbonImmutable $requestedAt,
        private readonly CarbonImmutable $purgeAfter,
    ) {}

    /**
     * 送信直前に予約の生存を再確認する。**これは誤通知の防止であって dedup ではない**。
     *
     * **dispatch の位置だけでは誤通知を防げない** — 「dispatch がどこか」と
     * 「job が参照する状態・実行可能時点」は別問題である。aicue は QueueDispatchAtomicityGuard が
     * driver=database / キュー DB = 業務 DB / after_commit=false を全環境の起動時に
     * fail-closed 検査するため commit 前実行は構造的に起きないが、**それは前提であって保証ではない**。
     *
     * 取消済み・再予約で値が変わった・user 不在なら **送らない** (via が空配列を返す)。
     *
     * **一回性を担うのはここではない**: 同じ (requestedAt, purgeAfter) を持つ job が 2 つあれば
     * 両方とも 'mail' を返す。一回性は **永続状態遷移**が担う —
     * `requestAccountDeletion()` は既に予約中なら**通知を発火せず**冪等 no-op で返すため、
     * 二重送信では job が 1 つしか作られない (AGENTS.md ドメイン規約 6
     * 「入口の排他は best-effort、結果の一回性は永続状態遷移が担う」)。
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }
        $state = AccountDeletionStateDto::fromUser($notifiable->fresh() ?? $notifiable);

        return $state->matches($this->requestedAt, $this->purgeAfter) ? ['mail'] : [];
    }
}
```

- **`ShouldQueue` + 予約 tx 内 dispatch** (AGENTS.md ドメイン規約 11)。
  spirux の申し送り「通知を afterCommit で外へ出せ」は **aicue の規約と逆なので採らない**。
- **`ShouldBeUnique` は使わない**。AGENTS.md ドメイン規約 11 が禁じている
  (unique lock は dispatch 時に取得され rollback で解放されないため業務 tx 内 dispatch と両立しない。
  `AutoRechargeTriggerJob` から撤去済みの先例がある)。**送達台帳も新設しない** (思考原則 2)。
- **`JobExecutionDedupInventoryTest` への登録が必要**。分類は「保証側 (`JobDedupGuarantee`)」で、
  保証の実体は **`deletion_requested_at` の存在による永続状態遷移** (= 予約中は再発火しない)。
  実装時に enum の既存 case を確認し、無ければ根拠 30 文字以上で追加する。
- **保証しないもの (誇張しない)**: 配送は **at-most-once**。
  **job の再試行による重複送信は防がない** (Laravel の retry は同一 job を再実行する)。
  「同一 payload の job を 2 つ投入しても 1 通」とは**主張しない**。

### 波及変更
- TypeScript 型定義: `resources/js/types/notification.ts` (`NotificationTypeTsSyncInvariantTest` が同期を強制)
- API Resource/DTO: なし
- テストファイル: `SecurityEventCoverageTest` (新 case 2 つの記録経路)、
  `InAppNotificationTypeInvariantTest`

### PHPStan 適合チェック
- [x] `via()` の戻り値 `list<string>`
- [x] `$notifiable->fresh()` の `?Model` を null 合体 + instanceof で narrowing
- [x] `CarbonImmutable` の readonly promoted property

### テスト計画
- [ ] 新規: 予約でメールが 1 通送られる
- [ ] 新規: **予約 → 即取消 → worker 実行 → メール 0 通** (via の再確認)
- [ ] 新規: **予約 POST を 2 回叩いてもメールは 1 通** (Service 層の冪等 no-op が一回性を担う)
- [ ] 新規: **再予約時に古い job が送られない** (requestedAt/purgeAfter の一致検査)
- [ ] 新規: SecurityEvent 2 種が記録される
- [ ] 新規: アプリ内通知が 1 件作られる

### リスク
- `via()` で DB を引くため、キュー実行時に user が既に消えている (執行済み) 場合がある
  → `fresh()` が null なら送らない (fail-closed)。

---

## B7. UI

### 変更箇所
- `app/Http/Controllers/Settings/ProfileController.php` (props に `accountDeletionState` を追加)
- `resources/js/pages/Settings/Index.svelte` (予約バナー + 主導線の入れ替え)
- `resources/js/types/account.ts` (`AccountDeletionState` 型)

### 変更後コード (props)

```php
return Inertia::render('Settings/Index', [
    'accountDeletionBlockers' => /* 既存のまま */,
    // 退会予約の状態 (予約中なら取消バナーを出す)。ISO 8601 文字列 + 導出 graceDays。
    'accountDeletionState' => AccountDeletionStateDto::fromUser($user)->toArray(),
    'hasPassword' => $user->hasPassword(),
]);
```

```ts
/** PHP: App\DataTransferObjects\Account\AccountDeletionStateDto::toArray() と対 */
export interface AccountDeletionState {
    requestedAt: string | null;
    purgeAfter: string | null;
    graceDays: number | null;
}
```

### UI の契約
- **予約中**: DangerZone の先頭に `Alert type="warning"` の予約バナー。内容は
  (a) `purgeAfter` の日付、(b) **「毎日 1 回自動で再試行する」**旨、
  (c) **取消ボタン** (primary)、(d) ブロッカーがあれば既存 `accountDeletionBlockers` の
  「次の一手」リンク群 (解約 / 移譲 / 切替) をそのまま表示。
- **未予約**: **主ボタンは「30 日後に削除 (取り消せます)」**、
  副導線として **「今すぐ完全に削除する (取り消せません)」** を ghost/link で置く。
- **条件未充足で disabled にしない** (禁止事項 8)。押下時にサーバがエラーを返し、
  既存の blocker 表示が「次の一手」を出す。

### 波及変更
- TypeScript 型定義: `resources/js/types/account.ts`
- API Resource/DTO: `AccountDeletionStateDto`
- テストファイル: `tests/js/pages/SettingsIndex.test.ts` (component)、
  `tests/Browser/` (主導線の視覚的優先度)

### テスト計画
- [ ] 新規 (component): 予約中はバナーと取消ボタンが出る
- [ ] 新規 (component): **未予約時、予約が primary ボタンで即時削除が副導線**である
      (「UI 主導線が本当に予約へ移る」ことを口約束にしない)
- [ ] 新規 (component): ブロッカーがあってもボタンは `disabled` にならない (禁止事項 8)
- [ ] 新規 (Browser): 予約 → バナー表示 → 取消 の一巡
- [ ] 既存 `tests/Browser/FlashToastTest.php` (即時削除 → home の GuestLayout 着地) は**変更しない**
- [ ] 既存 atom/molecule (`Alert` / `Button` / `TextLink` / `DangerZone`) を再利用し、
      **hex 直書きを増やさない** (DESIGN.md が canonical。ds-purity テストの対象)。
      アイコンは `@lucide/svelte` のみ (SVG 直書きを新設しない)。
      component 階層の単方向 import (`atoms → molecules → organisms → features → templates → pages`) を守る
- [ ] **予約中は削除ボタン群を出さない** (バナー + 取消 + 次の一手のみ。
      B4 で `settings.account.destroy` を凍結対象にしたことと UI を一致させる)

### リスク
- 既存 Browser テストが「削除ボタン = 即時削除」を前提にしている可能性
  → 即時削除ボタンは残るので `testId` を変えない。主導線の追加は既存 selector を壊さない
  (spirux は既定を予約に変えて BrowserPest が赤くなった。同じ轍を踏まない)。

---

## B8. 既存 gate の更新 (まとめ)

| gate | 変更内容 | 根拠 |
|---|---|---|
| `RecentAuthRouteTest` | `settings.account.deletion-request.store` を allowlist へ | 即時削除と同水準の機微操作 |
| `ControllerAuthorizationGateTest` | 新 route 2 本を `$selfScoped` で登録 | 他者を指す parameter が無い |
| `MembershipWriteLockInventoryTest` | `requestAccountDeletion` / `cancelAccountDeletion` → **`directLock`** / `executeAccountDeletionRequest` → **`delegatedToLocked`**。併せて **`delegatedToLocked` を「メソッド名 => 必須の委譲先呼び出し」の map へ一般化**する (現状は `joinOrganization(` のハードコード。既存 3 本の判定は等価のまま = テストの意味を弱めない) | 前 2 者は自 tx 冒頭で `lockForMembershipWrite(` を呼ぶ。3 番目は `deleteAccount(` へ委譲する |
| `TenantBoundaryOrderingTest` | 凍結 middleware の順序を 1 行追加 | 404 が 302 より前 |
| `JobExecutionDedupInventoryTest` | 通知 job を登録 | `ShouldQueue` 実装の全クラスが対象 |
| `SecurityEventCoverageTest` | 新 case 2 つの記録経路 | case 追加時は同一 PR で配線 |
| `AccountDeletionPathGateTest` (A1) | 起点に `PurgeDeletionRequestsCommand::handle` を追加。**閉包目録の差分に理由コメントを残す** | 執行経路も依存閉包の対象 |
| `NotificationTypeTsSyncInvariantTest` | 新 NotificationType 1 件 | TS 同期 |
| **`ThrottleCoverageInventoryTest`** | **変更不要** | 母集団 S1/S2/S3 のいずれにも入らない (§B3 で実読確認) |

---

# PR-C1: 保持期間の基盤整備 (非公開)

## C1a. 保持年数の単一出典

```php
// config/legal.php へ追加
/*
| 課金取引記録の保持年数。**env を使わない** — 環境ごとに変えてよい運用値ではなく、
| 法務文書 (/privacy) が宣言する値そのものである (config/idempotency.php の
| retention_hours と同じ理由)。値の変更は規約文面の変更と同義であり、
| App\Support\Legal\BillingRetention が唯一の解決点として読む。
*/
'billing_retention_years' => 7,
```

```php
final class BillingRetention
{
    /** 保持年数 (唯一の解決点)。0 以下は fail-fast。 */
    public static function years(): int { /* config()->integer + Assert::greaterThan(0) */ }

    /** 保持期限の閾値 (これより古い起算日時は期限超過)。*NoOverflow を使う。 */
    public static function threshold(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subYearsNoOverflow(self::years());
    }
}
```

- `subYearsNoOverflow` を使う (`CarbonOverflowArithmeticGateTest` が暗黙 overflow を禁止)。

## C1b. purge の対象目録と起算点

```php
enum BillingRetentionTarget: string
{
    case StripeWebhookEvent = 'stripe_webhook_event';
    case BillingCheckoutSession = 'billing_checkout_session';
    case TicketCheckoutSession = 'ticket_checkout_session';
    case TicketAutoRechargeAttempt = 'ticket_auto_recharge_attempt';
    case SubscriptionItem = 'subscription_item';
    case Subscription = 'subscription';
    case TicketLedgerEntry = 'ticket_ledger_entry'; // C2 で purger を実装する

    /**
     * 起算列 (保持期間の clock start)。
     * **自テーブルの列名、または `{table}.{column}` の修飾名**を返す
     * (子 target は親テーブルの列を起算点にするため修飾名が要る)。
     */
    public function clockStartColumn(): string { /* … */ }

    /** 30 文字以上の根拠。 */
    public function rationale(): string { /* … */ }

    /** C1 時点で purger 未実装 (C2 で解消する)。 */
    public function isPendingCarryForward(): bool
    {
        return $this === self::TicketLedgerEntry;
    }
}
```

| case | 起算点 (実在列で確認済み) | 方式 |
|---|---|---|
| `StripeWebhookEvent` | `processed_at` (未処理の古い行は fail-closed) | 物理削除 |
| `BillingCheckoutSession` | `completed_at` (null かつ古い = fail-closed) | 物理削除 |
| `TicketCheckoutSession` | `completed_at` (同上) | 物理削除 |
| `TicketAutoRechargeAttempt` | `resolved_at` (同上) | 物理削除 |
| `Subscription` | **自身の `ends_at`** (`'ends_at'`)。null = 継続中 = 起算未到来 (対象外) | 物理削除 (親。子の後) |
| `SubscriptionItem` | **親 Subscription の `ends_at`** (`'subscriptions.ends_at'` = 修飾名) | 物理削除 (子。親より先) |
| `TicketLedgerEntry` | `created_at` (起算済み) | **C2 の畳み込み** |

```php
enum BillingRetentionExclusion: string
{
    case BillingNotification = 'billing_notification';
    case TicketReservation = 'ticket_reservation';
    case Plan = 'plan';
    case PlanPrice = 'plan_price';
    case TicketVolumePrice = 'ticket_volume_price';
    case OrganizationQuota = 'organization_quota';
    case TicketAutoRecharge = 'ticket_auto_recharge';

    public function rationale(): string { /* 30 文字以上 */ }
}
```

除外の根拠 (要約。実装では 30 文字以上を各 case に書く):
- `BillingNotification` — メール送達の重複防止台帳。`(type, invoice_id)` / `(type, dedup_key)` の
  UNIQUE が冪等の調停者で、行を消すと同じ請求書の通知が再送される。取引そのものの記録ではなく、
  保持ポリシーの所有者は課金リマインダ feature。
- `TicketReservation` — TTL で解放される一時状態。所有者は既存 `billing:release-stale-reservations`。
- `Plan` / `PlanPrice` / `TicketVolumePrice` — 価格カタログであって取引記録ではない。
- `OrganizationQuota` / `TicketAutoRecharge` — 現在の設定値であって取引記録ではない。

```php
interface BillingRetentionPurger
{
    public function target(): BillingRetentionTarget;

    public function countExpired(CarbonImmutable $threshold): int;

    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto;
}
```

```php
final readonly class BillingRetentionPurgeResultDto
{
    public function __construct(
        public BillingRetentionTarget $target,       // enum 型で保持 (string 化は表示境界だけ)
        public int $candidates,                      // 対象件数
        public int $processed,                       // 削除または畳み込み件数
        public int $failClosed,                      // 安全のため残した件数
        public int $unexpectedFailures,              // 想定外失敗件数
        public int $expiredRemaining,                // purge 後に残った期限超過件数
    ) {}

    public function hasFailClosedRecords(): bool { return $this->failClosed > 0; }

    public function hasUnexpectedFailures(): bool { return $this->unexpectedFailures > 0; }

    /** C3 (規約公開) に進んでよいか。**分類を問わず期限超過 0 件**が条件。 */
    public function isPublicationReady(): bool
    {
        return $this->failClosed === 0
            && $this->unexpectedFailures === 0
            && $this->expiredRemaining === 0;
    }
}
```

**`array<string, mixed>` や任意メタデータ領域は持たせない**。

## C1c. purge コマンド (dry-run のみ)

```php
protected $signature = 'billing:purge-retention-expired
    {--target= : 対象を 1 つに絞る (BillingRetentionTarget の value)}';
```

- **C1 の signature に `--apply` は存在しない** (dry-run 専用であることを signature そのもので表現する。
  「規約が宣言していない年数を先に運用へ効かせない」の機械化)。`--apply` は **C2 で追加する**。
- **C1 では `routes/console.php` に登録しない**。
- 出力は **target 別の件数のみ** (organization id / メール / 金額を出さない)。
- 終了コードは B5 と同じ 2 分類 (`hasUnexpectedFailures()` で判定。DTO に閉じる)。

## C1d. 目録 gate + horizon

`BillingRetentionTargetInventoryTest` (Architecture):
1. **母集団 exact-fit**: `app/Models/Billing/` の全モデル + `SubscriptionItem` が
   `BillingRetentionTarget` ∪ `BillingRetentionExclusion` のどちらかに**ちょうど 1 回**現れる
2. 各 case の `rationale()` が **30 文字以上**
3. 各 target の `clockStartColumn()` が**実在する列**である (schema 照合)。
   **修飾名 (`{table}.{column}`) も解決して照合する**
4. **target と purger 実装クラスの exact-fit 対応** (`isPendingCarryForward()` を除く)。
   C1 時点の purger は **6 本** (`StripeWebhookEvent` / `BillingCheckoutSession` /
   `TicketCheckoutSession` / `TicketAutoRechargeAttempt` / `SubscriptionItem` / `Subscription`)
4b. **実行順が子 → 親** (`SubscriptionItem` → `Subscription`) であることを固定する
5. 空振り検知 (母集団件数 floor / 0 件で fail)
6. 負のコントロール (fixture のダミーモデルを分類しないと fail することを確認)

`BillingRetentionHorizonTest` (Feature):
- **postcondition**: purger を実行した後に、起算済み・期限超過の行が全 target で **0 件**
  (**`failClosed` を除外しない**)
- **負のコントロール**: わざと古い行を作ると赤くなる
- **保証しないもの**: 本番で日次処理が止まっていないことは保証しない
  (責務は Command の件数報告 + `FAILURE` 終了コード + scheduler 運用)
- C1 では `TicketLedgerEntry` を対象から外す (`isPendingCarryForward()` を見る)。
  **C1 は規約に何も宣言せず日次も回さない**ので、この未了が利用者に見える不整合にならない。

### テスト計画 (C1 全体)
- [ ] 新規: 各 target の**境界テスト** (起算日時が閾値の 1 秒前 / 1 秒後 → 片方だけ消える)
- [ ] 新規: 起算列が **null かつ古い行は fail-closed** (削除されず `failClosed` に計上)
- [ ] 新規: `Subscription` の `ends_at` が null (継続中) は**何年経っても対象外**
- [ ] 新規: `SubscriptionItem` は親の `ends_at` で判定し、**子 → 親**の順で消える
- [ ] 新規: 参照中の `Subscription` は fail-closed で残り件数が report される
- [ ] 新規: dry-run は 1 行も消さない
- [ ] 新規: 出力に PII が無い
- [ ] 新規: `BillingRetention::years()` が 0 以下なら fail-fast

---

# PR-C2: 保持期間の実処理の有効化

## C2a. ledger reader 目録

`TicketLedgerReaderInventoryTest` (Architecture)。走査入口は **4 つ**:
モデル参照 (`TicketLedgerEntry`) / table 名 (`'ticket_ledger_entries'`) / relation 名 /
主要列名 (`delta` / `source` / `expires_at`)。正負 fixture と空振り検知を同梱する。

**保証範囲 (誇張しない)**: 目録が保証するのは「読んでいる場所を宣言なしに増やせない」ことだけで、
動的 relation・変数 table 名・DB facade の動的組み立ては取りこぼしうる。
**最終保証は C2b の挙動テスト側**である。

## C2b. ledger 畳み込み

### 設計

保持期限より古い行を **`(organization_id, source, expires_at)` の組ごと**に合算し、
合計 `delta` を持つ 1 行の**繰越行**へ置換する。

> **`organization_id` を group key に必ず含める**。含め忘れると**組織を跨いで残高を合算する**
> 重大バグになる (Codex 詳細レビュー Round 1 の [Critical])。
> 実コードで確認した残高の粒度はこの 3 つで閉じる — `sumBalance()` は
> `where organization_id` + `source` (Purchased は `source IS NULL` も含む) +
> `expires_at IS NULL or > now` で合算しており、team / project 粒度は持たない。
> **`source IS NULL` (legacy 行) は独立した group として扱う** (Purchased へ寄せると
> `sumActiveHolds` の legacy 除外規則と意味がズレる)。

- **繰越行は「取引記録」ではなく「現在残高のスナップショット」と定義する**。
  新しい `created_at` を持つ取引記録のままだと、7 年後にまた畳み込まれて保持時計が
  永久に更新され続け、規約との対応が崩れる。性質を変えることで境界を明示する。
- **取引追跡情報を引き継がない**: 原取引 ID / 説明 / 個別日時 / `stripe_invoice_id` は残さない。
- **引き継ぐのは残高の粒度を決める 3 つだけ**: `organization_id` と `source` と `expires_at`。
- **`kind` は新 case `TicketLedgerKind::CarryForward`** にする (既存 kind へ相乗りしない。
  思考原則 4「別物の概念を似ているからで統合しない」)。
- **取引追跡列はすべて null**: `description` / `reservation_id` / `stripe_checkout_session_id` /
  `payment_intent_id` / `purchase_amount` / `granted_at` (実カラムを migration 実読で確認済み)。
- **`idempotency_key` は `carry_forward:{orgId}:{source}:{expiresAt}:{through}`**。
  既存の signup unique index は **`idempotency_key LIKE 'signup_grant:%'` の部分 unique** なので
  衝突しない (`2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php` を実読)。
- **`carried_forward_through`** (この繰越が集約した期間の終端) を型付きで持つ (新列)。
- **繰越行の再畳み込みを許す** (スナップショット同士の合算は情報を増やさない)。
- **合計 0 の繰越行を作らない** (残高に寄与しない行を増やさない)。
- **再畳み込み後も `carried_forward_through` が単調に進む**。
- 組織の行ロック下で行い、`reserve` / `commit` と同じロック順序に乗せる。

### 波及変更 (C2b)
- TypeScript 型定義: **`TicketLedgerKind` に case を足すため TS 側の対応型と表示分岐を確認する**
  (`resources/js/types/` の該当型 + 台帳表示 UI。enum TS 同期テストがあれば同時更新)
- API Resource/DTO: `TicketLedgerEntry` を載せる Resource/DTO があれば新 kind の表示を確認
- テストファイル: `TicketLedgerReaderInventoryTest` (C2a) / 既存 `TicketLedgerService` テスト群

### 検証 (7 種を畳み込み前後で比較)
1. 総残高 2. 利用可能残高 (`availableTrueBalance`) 3. 有効期限別残高
4. `source` 別残高 5. `debit`/`reserve`/`commit`/`release` の選択順序
6. 外部キー・重複防止キー (`signup_grant` unique 等)・監査表示
7. **組織ごとの残高が畳み込み前後で一致する (複数組織 fixture)**

加えて **個別取引情報が復元不能であることをテストで固定する**
(畳み込み後に原取引の識別子が 1 つも残っていないこと)。

## C2c. 日次登録 + `--apply`

```php
Schedule::command('billing:purge-retention-expired --apply')->daily()->onOneServer();
```

**schedule は C2 のデプロイ時点から有効**である。runbook の初回 `--apply` は
「初回を能動的に完走させて結果を確認する」ためのもので、schedule を抑止する意味ではない
(抑止機構を新設しない = 思考原則 2)。

## C2d. 有効化 runbook (`docs/billing-retention-runbook.md`)

**公開順序 (C2 の完了条件)**:
1. C1 の dry-run で target 別件数と想定外失敗を確認する
2. C2 (ledger 畳み込み込み) をデプロイする
3. `--apply` を実行する
4. apply 後の horizon 検査で **「期限超過件数 0 (`failClosed` を含む。分類を問わない)」** を確認する
5. **4 が満たされて初めて C3 (文面公開) を出す**
6. 日次 scheduler を**継続監視へ移す**

- **C3 チェックリスト**: 初回 apply の出力 (target 別件数 / `failClosed` = 0 / 想定外失敗 = 0) の
  **証跡を C3 の PR 説明へ貼る**ことを必須項目にする。
- `failClosed` が長期継続したときの解消手順 (参照元の特定 → 参照の解消 → 再実行、
  件数が単調増加しているときの初動)。
- **`failClosed` は「安全に残した」であって「規約を満たした」ではない**ことを明記する。
- **監視対象**: `docs/architecture.md` の監視対象リストへ本コマンドを追加する
  (`failClosed` の継続・増加を正常成功として扱わない)。

---

# PR-C3: 保持期間の公開 (極小 PR)

## C3a. 規約文面 (法務レビュー前の**草案**)

`resources/views/legal/privacy.blade.php` の「3. 第三者提供」と「4. 開示・訂正・削除」の間に
新しい節を挿入する。**文面案は本設計の §付録 A に全文を置く** (オーナーが目視確認できるように)。

- **年数の数値は `\App\Support\Legal\BillingRetention::years()` から描画する**
  (**blade が config を直読しない**。「config を読んでよいのは `BillingRetention` 1 箇所だけ」という
  C3b の検査 1 と整合させる)。blade に `7` の literal を書かない (三者一致の要)。
- `data-legal-retention="billing-records"` のマーカー要素を持たせる。
- **`config/legal.php` の `consent_version` は `draft-1` から動かさない** (オーナー決定)。

## C3b. 三者一致 gate

`BillingRetentionSingleSourceTest` (Architecture)。`LegalConsentVersionSingleSourceTest` と同じ
token 走査 + exact-fit caller inventory の書式:
1. `config('legal.billing_retention_years')` を読んでよいのは `BillingRetention` **1 箇所だけ**
2. `BillingRetention::years()` / `::threshold()` の呼び出し元が **exact-fit の目録**と一致
   (**privacy blade** / purger 群 / horizon テスト)。blade も呼び出し元として目録に載る
3. blade に保持年数の literal (`7` / `７` / `七`) が現れない
4. 空振り検知 + 負のコントロール (fixture ソースで点灯する)

`PrivacyRetentionDeclarationTest` (Feature)。`GET /privacy` を実際に叩き 4 点を検査:
(a) `data-legal-retention="billing-records"` マーカーの存在、
(b) 保持期間の**節見出し**の存在、
(c) 先例由来の固定文言 **「取引関係書類等」** の存在、
(d) **その要素内に** config 由来の年数が現れること。
「節ごと消えた」も「数字だけ別の文脈に残った」も検出できる。

**保証しないもの (誇張しない)**:
- 文面の日本語が法的に正しいか / 7 年が法令上妥当か (**法務レビューの仕事**。本追記は草案)
- 散文部分の意味と実処理の一致 (機械が見るのは数値 1 つとマーカーの存在だけ)
- purge 対象テーブルの網羅性 (inventory への人間の申告)
- 「文面が変わったのに版が上がっていない」こと (`consent_version` を動かさないため)

---

# 共通: 検査が空振りしないことの保証

新設する全 gate に以下を必ず同梱する (本リポジトリの gate 書式)。

| 手段 | 内容 |
|---|---|
| **母集団 floor** | 走査ファイル数 / route 数 / 目録件数が 0 でないことを下限で pin。0 件なら fail |
| **exact-fit cap** | 免除・allowlist の件数を**現在値ちょうど**で pin (余裕を 1 でも持たせない。
`ThrottleCoverageInventoryTest` の cap コメントと同じ理由 — 余裕枠は「根拠なしに免除できる枠」になる) |
| **負のコントロール** | fixture ソース (nowdoc 内。code token にならない) を検出器に当てて**点灯すること**を確認 |
| **自己参照コントロール** | gate ファイル自身を走査して hit 0 件 (説明コメントで偽赤にならない) |
| **正の自己検証** | 実ファイルで検出器が実際に点灯すること (検出器が死んでいないこと) |

# 共通: mutation で赤化を確認する手順

**実装完了の条件**は「テストが緑」ではなく「**壊すと赤くなることを実測した**」である。
各 gate について以下を**実行し、結果を実装ノートに記録する**。

| # | 変異 (実施後は必ず戻す) | 赤くなるべきテスト |
|---|---|---|
| M1 | `AccountDeletionPathGateTest` の起点から `deleteAccount` を外す | 空振り検知 (閉包サイズ floor) |
| M2 | `OrganizationMembershipService` に `Stripe\StripeClient` を型注入するだけの private property を足す | 依存閉包 gate 検査 2 |
| M3 | 同じ注入を `app('cashier.stripe')` の literal 呼び出しで書く | 同上 (fixture 4 形目) |
| M4 | `AccountDeletionFreezeAllowance` から `settings` を削る | 到達性テスト (取消に到達できない) |
| M5 | 同 enum に `dashboard` を足す | exact-fit 検査 3 |
| M6 | 凍結 middleware を priority list で `EnsureProjectBelongsToCurrentOrganization` より**前**へ動かす | `TenantBoundaryOrderingTest` + 他組織 `{project}` が 302 になる behavioral |
| M7 | `PurgeDeletionRequestsCommand` の終了コードを常に `SUCCESS` にする | 「想定外例外で FAILURE」テスト |
| M8 | `deleteAccount` の precondition 差し込み位置をブロッカー判定の**後**へ動かす | 「抽出後に取消 → 削除しない」テスト |
| M9 | 通知の `via()` から予約生存の再確認を外す | 「予約 → 即取消 → メール 0 通」テスト |
| M10 | `BillingRetentionTarget` から `Subscription` を削る | 目録 exact-fit (母集団の分類漏れ) |
| M11 | `Subscription` の起算列を `ends_at` → `created_at` に変える | 「継続中は何年経っても対象外」テスト |
| M12 | `TicketLedgerEntry` を C1 の horizon 対象に入れる | horizon (期限超過が残る) |
| M13 | 畳み込みで `source` を捨てて 1 行に合算する | 6 種比較の「source 別残高」 |
| M13b | 畳み込みの group key から `organization_id` を外す | 7 種比較の「組織ごとの残高一致」(複数組織 fixture) |
| M14 | privacy blade の年数を literal `7` に書き換える | 三者一致 gate 検査 3 |
| M15 | privacy の保持期間の節ごと削除する | `PrivacyRetentionDeclarationTest` (a)(b)(c)(d) |
| M16 | `BillingRetentionPurgeResultDto::isPublicationReady()` から `failClosed === 0` を外す | 公開条件テスト |
| M17 | `AccountDeletionFreezeAllowance` に `settings.account.destroy` を足す | 「予約中は即時削除できない」テスト |
| M18 | `logout` を `auth`+`verified` group の中へ移す | 凍結 gate 検査 7 (`U` に含まれないこと) |
| M19 | `requestAccountDeletion` の冪等 no-op を外し予約中でも通知を発火させる | 「予約 POST 2 回でメール 1 通」テスト |
| M20 | 執行バッチの抽出条件から `whereNotNull('deletion_requested_at')` を外す | 「片列だけの非正規行を due に数えない」テスト |
| M21 | `config/account.php` の `deletion_grace_days` を 0 にする | `AccountDeletionGraceConfigTest` の fail-fast |

**手順**: 1 変異ずつ適用 → 対象テストが**赤いこと**を実測 → 変異を戻す →
全体が緑に戻ることを確認 (`git diff` が空であることも確認する)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | migration 3 / middleware 1 (priority list と group への配線) / route 2 / command 3 / Architecture gate 5 / 既存 gate 更新 8 に及ぶ。`bootstrap/app.php` の priority list・`routes/web.php` の group・`routes/console.php`・`docs/architecture.md` はどれも他タスクと競合しやすい中心ファイルである |
| 競合リスク | `routes/console.php` (スケジュール追加) / `bootstrap/app.php` (middleware 配線) / `docs/architecture.md` (節追加) / `config/legal.php` — いずれも並行タスクが触りうる。**5 PR は直列**に入れ、並行 worktree は作らない |

## 台帳への報告

**C3 完了後に 1 回**。条件は 5 つすべての成立:
(a) C2 デプロイ済み / (b) 初回 `--apply` 完走 /
(c) **`failClosed` を含む期限超過件数が 0** / (d) C3 マージ・デプロイ済み /
(e) 三者一致 gate が green。
A/B/C1/C2 の途中で `implemented` を主張しない。

**併せて台帳へ訂正を出す** (recon-brief の申し送り):
feature_yaml の boundary が「aicue は route `settings.account.destroy` 相当を ProfileController が受ける」と
書いているのは**誤り**。実際に `DELETE /settings/account` を受けるのは
`app/Http/Controllers/Settings/AccountController.php::destroy` で、`ProfileController` は
`/settings` の props を組み立てる読み取り側である。

---

## 付録 A: `privacy.blade.php` へ追記する文面案 (法務レビュー前の**草案**)

> **この文面は法務レビュー前の草案である。** 家系の先例 (spirux の /privacy
> 「取引関係書類等につき最長 7 年」) に揃えたものであり、独自の法的主張を書き起こしていない。
> **「実装が宣言する年数」と「法務が確定する年数」が一致することの確認は人間の仕事である。**
> `config/legal.php` の `consent_version` は本追記では `draft-1` から動かさない
> (版の確定はリリース時のオーナー判断)。

```blade
        <h2 id="retention">4. 保有期間</h2>
        <p data-legal-retention="billing-records">
            当社は、取得した個人情報を利用目的の達成に必要な期間に限り保有し、
            当該期間の経過後は遅滞なく消去または匿名化します。ただし、
            <strong>ご契約およびお支払いに関する取引関係書類等については、
            法令に定める保存期間に従い、取引の終了時から最長{{ \App\Support\Legal\BillingRetention::years() }}年間</strong>
            保有します。
        </p>
        <p>
            保有期間の起算点は取引の終了時（ご契約の終了日、お支払いの確定日等）です。
            継続中のご契約に関する記録は、当該契約が終了するまで保有します。
        </p>
```

> 追記に伴い、既存の「4. 開示・訂正・削除」以降の見出し番号を 1 つずつ繰り下げる
> (`4.` → `5.`)。見出し番号の付け替えは文面の意味を変えないが、
> `PrivacyRetentionDeclarationTest` は**番号ではなく `data-legal-retention` 属性と
> 「取引関係書類等」という語**で検査する (並べ替えに耐えるため)。
