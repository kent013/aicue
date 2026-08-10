## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。


# あなたの役割

Laravel 12 + Svelte 5 + Inertia のアプリ (AI-CUE) の**実装レビュアー**である。
以下の詳細設計 (PR-A の節のみ) に対する実装差分をレビューせよ。

## レビュー観点

1. **設計との一致性**: PR-A (A1/A2/A3) の要求を満たしているか。**PR-B/C は本 PR の範囲外**であり、
   それらを実装していないことは欠陥ではない
2. **正確性**: 静的走査ロジックのバグ (取りこぼし = fail-open / 誤検出 = fail-closed だが運用を壊す)、
   トークン解析の境界条件、SQL/CHECK 制約、冪等性、トランザクション境界
3. **PHPStan level 10 適合**: 型の widen / ignore / baseline は禁止 (実際に `composer phpstan` は緑)
4. **DTO/JsonResource パターン**: 該当箇所があれば
5. **テスト網羅性**: 特に「**gate が空振りしていないこと**」「壊すと赤くなること」の担保
6. **セキュリティ**: テナント境界・主キー同一性クエリの目録登録・PII 非出力
7. **DESIGN.md 準拠 / Atomic Design 準拠**: 本 PR は `resources/js` / `resources/css` を
   **1 行も変更していない**ため該当なし (差分で確認してよい)
8. **誇張しない記述**: 「保証するもの / 保証しないもの」の記述が実装と一致しているか。
   実装より強い保証を謳っていたらそれは欠陥である

## 特に厳しく見てほしい点

- 依存閉包 gate (`AccountDeletionPathGateTest`) に **fail-open** が残っていないか。
  「決済事業者記号への到達を見落とす書き方」が他にないか具体的に挙げよ
- 閉包の到達辺の定義 (型宣言 / static 受け手 / 修飾名トークン / container literal) に
  抜けがないか。抜けがあるならそれが現実的に起きうる形かも述べよ
- Cashier API 名をリフレクションで導出し allowlist 3 件で差し引く方式の妥当性と危険性
- CHECK 制約 / 冪等記録コマンドの競合条件
- exact-fit 目録 (53 クラス) の保守性と、それが「信号を殺さない」か

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書く

---

# user

## 詳細設計書 (PR-A の節 + 共通の gate 書式 / mutation 手順)

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
  **両列は同時に埋まり同時に null である**。この不変条件は**アプリ層だけでなく DB 制約で守る** —
  将来の別コマンドや直接 UPDATE でも片側だけ書けてしまうと監査証跡として意味を失うため。
  migration に PostgreSQL の **CHECK 制約**を置く:
  `CHECK ((stripe_customer_redacted_at IS NULL AND stripe_customer_redacted_id IS NULL)
  OR (stripe_customer_redacted_at IS NOT NULL AND stripe_customer_redacted_id IS NOT NULL))`。
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
- [ ] 新規: **片方だけの INSERT/UPDATE が DB に拒否される** (CHECK 制約。アプリ層を迂回しても守られる)
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
| M22 | `purgeAfter()` を `addDaysNoOverflow` に戻す | 「2026-01-31 の 30 日後 = 2026-03-02」behavioral |
| M23 | 通知 `via()` を `fresh() ?? $notifiable` へ戻す | 「執行済み user へ送らない」テスト |
| M24 | redaction 記録の CHECK 制約を外し片列だけ UPDATE する | migration の DB 制約テスト |
| M25 | `recent-auth.confirm` を allowlist から外す | 到達性 (d) 移譲画面へ到達できない |
| M26 | `StripeWebhookEvent` の `anomalyClockColumn()` を null にする | 「未処理の古い webhook が failClosed に計上される」テスト |
| M27 | `AccountDeletionFreezeAllowance` に `billing.auto-recharge.update` を足す | 「予約中に auto-recharge 更新が遮断される」テスト |
| M28 | users の CHECK 制約を外し片列だけ UPDATE する | migration の DB 制約テスト |
| M29 | `PortalConfigurationSpec` の `subscription_update` を `true` にする | `AccountDeletionFreezeRouteGateTest` の**前提検査 3 点** (`--verify` は spec との一致しか見ないため、前提 pin が無いと赤化しない可能性がある。**どのテストが赤くなったかを実装ノートに記録する**) |

**手順**: 1 変異ずつ適用 → 対象テストが**赤いこと**を実測 → 変異を戻す →
全体が緑に戻ることを確認 (`git diff` が空であることも確認する)。

---

## 実装差分 (git diff)

```diff
diff --git a/app/Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php b/app/Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php
new file mode 100644
index 0000000..d24adad
--- /dev/null
+++ b/app/Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php
@@ -0,0 +1,99 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Billing;
+
+use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Support\Facades\DB;
+use Webmozart\Assert\Assert;
+
+/**
+ * 決済事業者側 customer の redaction (非表示化) の**実施記録**。
+ *
+ * ★**決済事業者 API を呼ばない**。redaction そのものは運用者がダッシュボードで行い、
+ *   本コマンドは「いつ・どの customer に対して実施したか」を自 DB に記録するだけである
+ *   (退会経路から決済事業者 API を呼ばない原則 = T115 / 標準形 v1 の必須 (1))。
+ *   手順の正本は `docs/account-deletion-runbook.md`。
+ *
+ * ★**記録は 2 列セット**。`stripe_customer_redacted_at` (実施日時) と
+ *   `stripe_customer_redacted_id` (記録時点の `stripe_id` の写し) を同時に書く。
+ *   日時だけだと「**どの** customer を redact 済みと記録したか」が事後に検証できない。
+ *   両列同時の不変条件は DB の CHECK 制約でも担保している (アプリ層を迂回しても守られる)。
+ *
+ * ★**1 回限り (冪等)**。行ロック下で既記録を再確認し、既に記録済みなら実施日を表示して
+ *   no-op で成功する (**上書きしない** — 最初の実施日が監査証跡だから)。
+ *
+ * ★`stripe_id` を持たない組織には記録できない (fail-closed)。写す値が無いため。
+ */
+class MarkStripeCustomerRedactedCommand extends Command
+{
+    protected $signature = 'billing:mark-stripe-customer-redacted
+        {organization : 組織 ID}
+        {--apply : 実記録する (未指定は dry-run)}';
+
+    protected $description = '決済事業者側 customer の redaction 実施を記録する (既定 dry-run。API は呼ばない)';
+
+    public function handle(): int
+    {
+        $organizationId = $this->argument('organization');
+        Assert::stringNotEmpty($organizationId, '組織 ID を指定してください');
+
+        if (! ctype_digit($organizationId)) {
+            $this->error("組織 ID は整数で指定してください: {$organizationId}");
+
+            return self::FAILURE;
+        }
+
+        return DB::transaction(function () use ($organizationId): int {
+            // 運用者が CLI で名指しした 1 組織を主キーで解決する (DirectFetchInventory 登録済み)。
+            // 判定と書き込みの間に別プロセスが割り込まないよう行ロック下で再評価する。
+            $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->first();
+            if (! $organization instanceof Organization) {
+                $this->error("組織が見つかりません: {$organizationId}");
+
+                return self::FAILURE;
+            }
+
+            $recordedAt = $organization->stripe_customer_redacted_at;
+            if ($recordedAt !== null) {
+                $this->info(
+                    $recordedAt->toDateString().' に記録済みです'
+                    .' (customer='.($organization->stripe_customer_redacted_id ?? '不明').')。何もしません。',
+                );
+
+                return self::SUCCESS;
+            }
+
+            $customerId = $organization->stripe_id;
+            if (! is_string($customerId) || $customerId === '') {
+                $this->error(
+                    "組織 {$organizationId} は決済事業者 customer を持ちません (stripe_id が空)。"
+                    .'記録すべき対象が無いため何もしません。',
+                );
+
+                return self::FAILURE;
+            }
+
+            if ($this->option('apply') !== true) {
+                $this->info(
+                    "[dry-run] 組織 {$organizationId} の customer={$customerId} を"
+                    .' redaction 実施済みとして記録します (--apply で実記録)。',
+                );
+
+                return self::SUCCESS;
+            }
+
+            $organization->forceFill([
+                'stripe_customer_redacted_at' => CarbonImmutable::now(),
+                'stripe_customer_redacted_id' => $customerId,
+            ])->save();
+
+            $this->info("組織 {$organizationId} の customer={$customerId} の redaction 実施を記録しました。");
+
+            return self::SUCCESS;
+        });
+    }
+}
diff --git a/app/Enums/Security/DeletionPathSeamExemption.php b/app/Enums/Security/DeletionPathSeamExemption.php
new file mode 100644
index 0000000..9debf1e
--- /dev/null
+++ b/app/Enums/Security/DeletionPathSeamExemption.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 退会 (アカウント削除) 経路の依存閉包から決済事業者記号へ到達することを認める免除。
+ *
+ * 母集団は `tests/Architecture/AccountDeletionPathGateTest.php` の検査 2 が出す hit で、
+ * 免除は **型付き case + 30 文字以上の根拠**のセットでのみ成立する
+ * (文字列で免除できると根拠なしに穴を開けられるため)。
+ *
+ * ★**現時点で case は 0 本**である。閉包内に決済事業者記号は 1 件も無く、
+ *   gate は「0 本ちょうど」を cap として pin している (余裕枠を持たせない)。
+ *   case を足すときは gate 側の `DELETION_PATH_SEAM_EXEMPTION_RATIONALES` へ
+ *   同じ value をキーに 30 文字以上の根拠を**同時に**登録する必要がある
+ *   (登録しなければ gate が赤くなる = 免除は必ずレビューを通る)。
+ *
+ * ★value の書式は `{クラス FQCN}#{記号}` とする (hit のキーと同じ形)。
+ *   例: `App\Services\Foo#Stripe\StripeClient`
+ */
+enum DeletionPathSeamExemption: string
+{
+    // 現時点で case は 0 本 (閉包内に決済事業者記号は 1 つも無い)。
+    // 足すときは上の docblock の手順に従うこと。
+}
diff --git a/app/Models/Organization.php b/app/Models/Organization.php
index 8829945..e933a7d 100644
--- a/app/Models/Organization.php
+++ b/app/Models/Organization.php
@@ -10,6 +10,7 @@
 use App\Models\Billing\Plan;
 use App\Models\Billing\TicketLedgerEntry;
 use App\Models\Billing\TicketReservation;
+use Carbon\CarbonImmutable;
 use Database\Factories\OrganizationFactory;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
@@ -53,8 +54,15 @@
  * のみ (保存値は EmailNormalizer 正規化済みのため検索入力も同一正規化を通すこと)。
  * 両列とも $fillable 外 (UpdateBillingContactAction が明示代入する)。
  *
+ * T141: 決済事業者側 customer の redaction 実施記録 (stripe_customer_redacted_at /
+ * stripe_customer_redacted_id) は**人手操作の記録専用**で $fillable 外
+ * (MarkStripeCustomerRedactedCommand が forceFill で明示代入する)。両列は同時に埋まり
+ * 同時に NULL で、この不変条件は DB の CHECK 制約でも担保している。
+ *
  * @property string|null $billing_contact_email
  * @property string|null $billing_contact_name
+ * @property CarbonImmutable|null $stripe_customer_redacted_at
+ * @property string|null $stripe_customer_redacted_id
  */
 class Organization extends Model implements CipherSweetEncrypted
 {
@@ -272,6 +280,10 @@ protected function casts(): array
             'personal_declared_at' => 'immutable_datetime',
             'personal_declared_by_user_id' => 'integer',
             'signup_tickets_granted_at' => 'immutable_datetime',
+            // T141: 決済事業者側 customer の redaction 実施記録。人手操作の記録専用で
+            // $fillable 外 (MarkStripeCustomerRedactedCommand が forceFill で明示代入する)。
+            // 両列は同時に埋まり同時に NULL (DB の CHECK 制約でも担保)。
+            'stripe_customer_redacted_at' => 'immutable_datetime',
         ];
     }
 }
diff --git a/database/factories/OrganizationFactory.php b/database/factories/OrganizationFactory.php
index bb5cce8..9d906dc 100644
--- a/database/factories/OrganizationFactory.php
+++ b/database/factories/OrganizationFactory.php
@@ -100,6 +100,19 @@ public function withBillingContact(?string $email = null, ?string $name = null):
         ]);
     }
 
+    /**
+     * 決済事業者側 customer を持つ組織 (Cashier の `stripe_id` が入っている状態)。
+     *
+     * redaction 記録 (T141) は `stripe_id` の写しを残すため、記録対象の組織は
+     * customer を持っていることが前提になる (持たない組織は fail-closed で記録不可)。
+     */
+    public function withStripeCustomer(?string $customerId = null): static
+    {
+        return $this->state(fn (): array => [
+            'stripe_id' => $customerId ?? 'cus_'.Str::lower(Str::random(14)),
+        ]);
+    }
+
     /** 初回無償チケット付与済み (org 単位 1 回マーカーが立っている) 組織 */
     public function signupGranted(): static
     {
diff --git a/database/migrations/2026_08_10_000100_add_stripe_customer_redaction_columns_to_organizations_table.php b/database/migrations/2026_08_10_000100_add_stripe_customer_redaction_columns_to_organizations_table.php
new file mode 100644
index 0000000..d9a575f
--- /dev/null
+++ b/database/migrations/2026_08_10_000100_add_stripe_customer_redaction_columns_to_organizations_table.php
@@ -0,0 +1,53 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * T141 (標準形 v1 / 裁定 AG-128 の必須 (1)): 決済事業者側 customer の redaction **実施記録**。
+ *
+ * ★アプリは redaction を**実行しない**。人手 (決済事業者ダッシュボード) で行った操作を
+ *   `billing:mark-stripe-customer-redacted` が自 DB に記録するだけである
+ *   (退会経路から決済事業者 API を呼ばない原則 = T115)。
+ *
+ * ★**記録は 2 列セット**である:
+ *   - `stripe_customer_redacted_at`: 実施日時
+ *   - `stripe_customer_redacted_id`: 記録時点の `stripe_id` の写し
+ *   日時だけだと「**どの** customer を redact 済みと記録したか」が事後に検証できず、
+ *   `stripe_id` が差し替わる経路が将来できたときに監査列として意味を失う。
+ *
+ * ★両列は**同時に埋まり同時に NULL** である。この不変条件はアプリ層だけでなく
+ *   **PostgreSQL の CHECK 制約**で守る (将来の別コマンドや直接 UPDATE で片側だけ書けると
+ *   監査証跡として意味を失うため)。
+ */
+return new class extends Migration
+{
+    private const string CONSTRAINT = 'organizations_stripe_customer_redaction_pair_check';
+
+    public function up(): void
+    {
+        Schema::table('organizations', function (Blueprint $table): void {
+            $table->timestamp('stripe_customer_redacted_at')->nullable();
+            $table->string('stripe_customer_redacted_id')->nullable();
+        });
+
+        DB::statement(
+            'ALTER TABLE organizations ADD CONSTRAINT '.self::CONSTRAINT.' CHECK ('
+            .'(stripe_customer_redacted_at IS NULL AND stripe_customer_redacted_id IS NULL)'
+            .' OR (stripe_customer_redacted_at IS NOT NULL AND stripe_customer_redacted_id IS NOT NULL))',
+        );
+    }
+
+    public function down(): void
+    {
+        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
+
+        Schema::table('organizations', function (Blueprint $table): void {
+            $table->dropColumn(['stripe_customer_redacted_at', 'stripe_customer_redacted_id']);
+        });
+    }
+};
diff --git a/docs/account-deletion-runbook.md b/docs/account-deletion-runbook.md
new file mode 100644
index 0000000..9ac751c
--- /dev/null
+++ b/docs/account-deletion-runbook.md
@@ -0,0 +1,124 @@
+# 退会 (アカウント削除) runbook — 決済事業者側 customer の redaction
+
+> 対象読者: 運用担当者。
+> 関連: `docs/architecture.md` §退会 (アカウント削除) の課金ガード (T115) /
+> lctl 台帳 feature `account-deletion-billing-guard` 標準形 v1 (裁定 AG-128) の必須 (1)。
+
+## 0. この runbook が扱う範囲
+
+**アプリは決済事業者側の顧客データを自動で消さない**。退会経路から決済事業者 API を
+呼ばないのが T115 からの原則である (自 DB と外部サービスの二重書き込みを避ける /
+解約を代行しない)。この原則は静的 gate
+`tests/Architecture/AccountDeletionPathGateTest.php` と behavioral 2 本
+(`tests/Feature/Auth/AccountDeletionTest.php`) が並存して固定している。
+
+したがって決済事業者側の非表示化 (redaction) は **人手**で行い、
+その**実施記録だけ**をアプリに残す。本 runbook はその手順である。
+
+### 保証しないもの (誇張しない)
+
+- **アプリからの自動 redaction は行わない**。実施はダッシュボード / 事業者 API 操作であり、
+  アプリはその事実を記録するだけである。
+- 記録列は「**実施したと運用者が申告した**」ことの証跡であって、事業者側で実際に
+  非表示化が完了したことの検証ではない (完了確認は事業者側の job status を見る)。
+- 静的 gate は**検知であって遮断ではない**。実行時の外部通信を止める機構ではない。
+
+## 1. 対象組織の解決手順
+
+**新しい探索経路を作らない**。起点は既存の日次バッチである:
+
+```bash
+php artisan billing:detect-orphan-billing-organizations
+```
+
+このコマンドは「Owner 不在かつ生きた課金責務が残る組織 (課金孤児)」を検出し、
+**件数と organization id のみ**を `report()` する (組織名・メール等の PII は載せない)。
+この id が redaction 検討の入口になる。
+
+退会本人からの削除要請で個別に対象が判明した場合も、対象は organization id で名指しする。
+
+## 2. 決済事業者 (Stripe) 側の操作
+
+> **一次情報 (2026-08-10 確認)**
+>
+> - 非表示にする手順・対象オブジェクト・処理時間: <https://docs.stripe.com/privacy/redaction>
+> - 削除要請の扱いと非表示化の位置づけ: <https://docs.stripe.com/privacy/deletion-requests>
+>
+> 引用 (要旨):
+> - 「不正利用とリスクを防ぐために、**ほとんどの取引は作成から 90 日後に**削除できます」
+>   (失敗した取引は直ちに / サンドボックス取引は即時 / 返金された取引は返金完了時点)。
+> - 「すべての関連データを非同期で識別して編集するには、**最大 30 日**かかる場合があります。
+>   この間、ジョブの `status` フィールドは `validating` または `redacting` です」。
+> - 「顧客を削除する予定がある場合は、削除を遅らせる可能性のある新しい取引を防ぐために、
+>   **まず顧客を削除**してください」。
+>
+> **注意 (2026-08-10 時点の観測)**: RedactionJob は**公開プレビュー**と明記されている。
+> 一般提供の状態・API 形状は変わりうるので、実施前に必ず上記 URL を開き直すこと。
+> 本 runbook の数値は上の 2 URL からの引き写しであり、**事業者仕様が変われば無効になる**。
+
+手順:
+
+1. 対象組織の `stripe_id` (customer id) を確認する。
+   ```bash
+   php artisan tinker --execute="echo App\Models\Organization::query()->whereKey(<ID>)->value('stripe_id');"
+   ```
+2. Stripe ダッシュボード / API で **まず Customer を削除**する
+   (新しい取引が発生して redaction が遅延するのを防ぐため。一次情報の推奨手順)。
+3. redaction job を作成し、検証エラーを解消してから実行する。
+   **90 日の待機が必要な取引が残っている場合、その期間は job が通らない**。
+   通らないことは異常ではない — 期間経過後に再実施する。
+4. **redaction は取り消せない**。非表示にした取引は不審請求の申し立てで自動的に敗訴になり、
+   返金もできなくなる。返金が必要な場合は**返金を先に**行う (一次情報の警告)。
+5. job の完了 (最大 30 日) を待つ。
+
+## 3. 実施の記録 (アプリ側)
+
+実施したら**必ず記録する**。記録が無いと、後から「どの customer を redact 済みか」を
+検証できない。
+
+```bash
+# 既定は dry-run (何も書かない)
+php artisan billing:mark-stripe-customer-redacted <organization_id>
+
+# 実記録
+php artisan billing:mark-stripe-customer-redacted <organization_id> --apply
+```
+
+記録されるのは 2 列セットである:
+
+| 列 | 内容 |
+|---|---|
+| `organizations.stripe_customer_redacted_at` | 実施日時 |
+| `organizations.stripe_customer_redacted_id` | 記録時点の `stripe_id` の写し |
+
+- **日時だけでは足りない**。「**どの** customer を redact したか」が事後に検証できないと、
+  `stripe_id` が差し替わる経路が将来できたときに監査列として意味を失う。
+- **両列は同時に埋まり同時に NULL** である。これはアプリ層だけでなく **PostgreSQL の
+  CHECK 制約** (`organizations_stripe_customer_redaction_pair_check`) が担保しており、
+  アプリを迂回した直接 UPDATE でも片側だけは書けない。
+- **このコマンドは決済事業者 API を呼ばない** (記録専用)。
+- `stripe_id` を持たない組織には記録できない (fail-closed。写す値が無いため)。
+
+### 二重実行したとき
+
+既に記録済みなら **no-op で成功**し、実施日と customer id を表示する。
+**上書きしない** — 最初の実施日が監査証跡だからである。
+
+```
+YYYY-MM-DD に記録済みです (customer=cus_xxx)。何もしません。
+```
+
+## 4. 実施者・実施日の残し方
+
+- アプリが持つのは「いつ・どの customer に対して実施したか」までである。
+  **誰が実施したかはアプリに残らない** (CLI 実行者を記録する仕組みを持たない)。
+- 実施者・実施理由・事業者側 job id は**運用チケット側**に残すこと。
+  本 runbook の URL と確認日、対象 organization id、コマンド出力を貼り付ける。
+
+## 5. 監視
+
+- `billing:detect-orphan-billing-organizations` の `report()` は既に監視対象である
+  (`docs/architecture.md` の監視対象リスト)。
+- 同じ organization id が**日をまたいで再報告され続ける**場合、redaction 待ち (90 日 / 最大 30 日)
+  なのか、対応が止まっているのかを本 runbook の手順で切り分ける。
+  再報告そのものは抑制状態を持たない冪等な観測であり、異常ではない。
diff --git a/docs/architecture.md b/docs/architecture.md
index 219be93..be9e504 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -942,6 +942,30 @@ ## 退会 (アカウント削除) の課金ガード (T115)
   の handover / 裁定 AG-033 (**確認日 2026-08-05**。一次情報は決済事業者 (Stripe) の公式
   ドキュメントだが、**台帳側に一次情報の URL が pin されていない**)。数値を運用に効かせる前に
   一次情報を引き直し、URL と確認日をここへ追記すること。事業者仕様変更時に更新する対象である
+- **一次情報の pin (T141。上の「URL が pin されていない」を解消した追記)**:
+  <https://docs.stripe.com/privacy/redaction> と
+  <https://docs.stripe.com/privacy/deletion-requests> を **2026-08-10 に確認**した。
+  90 日は「**取引**は作成から 90 日後に非表示にできる」(失敗した取引は直ちに / サンドボックスは即時 /
+  返金済みは返金完了時点)、最大 30 日は「関連データを非同期で識別して編集するのに最大 30 日」を指す。
+  **customer 単体の待機期間ではない**点に注意 (上の運用注記の要約より条件が細かい)。
+  なお RedactionJob は同日時点で**公開プレビュー**と明記されている。手順・保証しないもの・
+  実施記録コマンドは **`docs/account-deletion-runbook.md` が正本**
+- **redaction の実施記録 (T141)**: 実施は人手で行い、アプリは記録だけ持つ。
+  `organizations.stripe_customer_redacted_at` (実施日時) と
+  `organizations.stripe_customer_redacted_id` (記録時点の `stripe_id` の写し) の **2 列セット**で、
+  記録経路は `billing:mark-stripe-customer-redacted` (既定 dry-run / `--apply` で実記録 /
+  既記録なら no-op。**決済事業者 API を呼ばない**)。日時だけだと「**どの** customer を
+  redact したか」が事後に検証できないため 2 列必要で、**両列同時**の不変条件は
+  PostgreSQL の CHECK 制約 (`organizations_stripe_customer_redaction_pair_check`) が
+  アプリ層を迂回した UPDATE に対しても担保する
+- **「決済事業者 API を呼ばない」の静的 gate (T141)**:
+  `tests/Architecture/AccountDeletionPathGateTest.php` が退会経路の**依存閉包**を走査し、
+  閉包内のクラスが決済事業者記号へ到達しないことを固定する (免除は
+  `App\Enums\Security\DeletionPathSeamExemption` + 30 文字以上の根拠。現在 0 件)。
+  behavioral 2 本は「その経路で今日呼ばれなかった」しか言えず**新しい依存を注入した瞬間に沈黙する**
+  ため、静的 gate と behavioral は**並存**させる (behavioral 側は変更しない)。
+  **保証しないもの**は gate 冒頭 docblock が正本 (変数 container 解決 / vendor 内部の通信 /
+  docblock のみの受け手 / 実行時 bind 差し替え。**そもそも検知であって遮断ではない**)
 - **決済手段の前提**: subscription Checkout は `payment_method_types` を指定せず Stripe
   ダッシュボード設定に委ねている。**非同期決済 (コンビニ払い等) を有効化する場合、`incomplete` を
   退会ガードで通過させている判断を再確認すること** (滞留時間が伸びるため)
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
new file mode 100644
index 0000000..4622528
--- /dev/null
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -0,0 +1,911 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\DeletionPathSeamExemption;
+use Laravel\Cashier\Billable;
+use Laravel\Cashier\Subscription;
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReferenceKind;
+use Tests\Support\ReferenceScanResult;
+use Tests\Support\ReferenceSite;
+use Webmozart\Assert\Assert;
+
+/*
+ * Architecture invariant: **退会 (アカウント削除) 経路の依存閉包から決済事業者 SDK へ到達しない**。
+ *
+ * SoT = lctl 台帳 feature `account-deletion-billing-guard` の標準形 v1 (裁定 AG-128) と
+ * docs/architecture.md §退会 (アカウント削除) の課金ガード (T115)。
+ *
+ * ★なぜ静的検査か (behavioral では捕まらない):
+ *   既存の tests/Feature/Auth/AccountDeletionTest.php の 2 本
+ *   (「退会成功経路では決済事業者 API を呼ばない」「課金中でブロックされる経路でも呼ばない」) は
+ *   **その経路で今日呼ばれなかった**ことしか言えない。新しい依存を注入した瞬間に沈黙する
+ *   (注入しただけで呼ばれない依存は behavioral では観測できず、次の変更で呼ばれた時に初めて壊れる)。
+ *   laravel-claude-template では実際に「依存閉包の抽出が**型宣言だけの注入**を素通りさせていた」
+ *   fail-open が実装レビューで見つかっている。よって静的 gate と behavioral 2 本は**並存**させる
+ *   (behavioral 側は 1 行も変更しない)。
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: 起点から辿れる app/ 内クラスの閉包が目録 (DELETION_PATH_CLOSURE) と exact-fit
+ *   - 検査 2: 閉包内のどのクラスも決済事業者記号を参照しない
+ *     (Stripe\* / Laravel\Cashier\Cashier / Cashier Billable・Subscription の API メソッド名 /
+ *      名前に stripe を含む呼び出し / 決済 binding の container literal)
+ *   - 検査 3: 免除は DeletionPathSeamExemption (型付き enum) + 30 文字以上の根拠のみ。現在 0 本ちょうど
+ *   - 検査 4: 空振り検知 (走査ファイル数 / 解決できた到達辺 / 閉包サイズが 0 でない・閉包が実在クラス)
+ *   - 検査 5: 自己参照コントロール (本ファイル自身を走査して到達 0 件・記号 hit なし)
+ *   - 検査 6: 閉包内に**動的メソッド名の呼び出しが 0 件** (`->{$m}()` / `::$m()` は名前が字句的に
+ *     確定せず記号照合を迂回できるため、閉包内では deny-by-default で 0 件に pin する)
+ *   - 検査 7: Cashier API 名の導出が生きている (ローカル判定 allowlist は exact-fit + 根拠つき)
+ *   - 検査 8-13: 正負 fixture 6 形
+ *     (型注入のみ / facade / static call / app()・resolve()・make() の literal 引数 /
+ *      trait 経由 / 動的メソッド名)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **文字列キーが変数の container 解決** (`$c->make($name)`)。受け手を解決できない
+ *   - **vendor 内部から出る通信**。Cashier の WebhookController / Billable の内部実装は閉包の外
+ *   - **完全修飾 docblock だけで型宣言も import も無い受け手** (docblock 解析はしない)
+ *   - **実行時 config による bind 差し替え** (静的走査は bind 先を知らない)
+ *   - **`use Billable;` のような trait 取り込み**そのもの。PhpReferenceScanner はクラス本体の
+ *     `use` を import として扱い site を出さないため、trait 名は記号照合に載らない
+ *     (帰結として Cashier の**構造的な取り込み**は検出せず、**呼び出し**だけを見る)
+ *   - **`Laravel\Cashier\` 名前空間の型参照そのもの** (`Subscription extends CashierSubscription` /
+ *     `use Billable;`) は記号にしない。接頭辞走査は値オブジェクト・例外・モデル継承を巻き込んで
+ *     信号を殺すため (ExternalSeamScanner が同じ理由で接頭辞走査を禁じている)
+ *   - **これは検知であって遮断ではない**。実行時の外部通信を止める機構ではない
+ *
+ * ★閉包の粒度は**クラス**である (起点は method 名で指すが、閉包はクラス単位で辿る)。
+ *   同一クラス内の private メソッド経由の到達 (`deleteAccount` → `$this->organizationsBlockingDeletion()`)
+ *   を落とさないための意図的な過大近似 = fail-closed。method 粒度にすると
+ *   「private メソッドへ移せば gate を迂回できる」抜け道ができる。
+ *
+ * 解析は Tests\Support\PhpReferenceScanner に乗せる (namespace 解決 / alias / scope 追跡を
+ * ExternalSeamInventoryTest / ExternalClientTimeoutInventoryTest と共有する。自前の走査器を作らない)。
+ * 走査は正規化済みトークン列に対して行うため、**この説明コメント自身では偽赤にならない** (検査 5)。
+ * DB 不使用 (Architecture lane は TestCase のみ)。
+ */
+
+/**
+ * 退会経路の起点 (`FQCN::method`)。
+ *
+ * ★**PR-A の時点では 2 つ**である。PR-B (猶予期間つき削除) で日次執行バッチ
+ *   `App\Console\Commands\Account\PurgeDeletionRequestsCommand::handle` を 3 つ目として足す。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_ROOTS = [
+    'App\Http\Controllers\Settings\AccountController::destroy',
+    'App\Services\Organization\OrganizationMembershipService::deleteAccount',
+];
+
+/**
+ * 起点から辿れる app/ 内クラスの閉包 (exact-fit の目録)。
+ *
+ * ★増減はどちらも赤くする。増えたら「退会経路の依存が広がった」ことのレビューを、
+ *   減ったら「走査が壊れた / 起点が外れた」ことの検出を意図している。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_CLOSURE = [
+    'App\DataTransferObjects\Invitations\PendingInvitationForUserDto',
+    'App\DataTransferObjects\Notification\InvitationReceivedPayload',
+    'App\DataTransferObjects\Notification\ManualJobPayload',
+    'App\DataTransferObjects\Notification\TicketBalanceLowPayload',
+    'App\DataTransferObjects\Organizations\AccountDeletionBlockerDto',
+    'App\Enums\AccountDeletionBlockReason',
+    'App\Enums\AccountDeletionBlockerAction',
+    'App\Enums\AdminConsoleRole',
+    'App\Enums\Billing\PlanPriceKind',
+    'App\Enums\Billing\ScheduleSetupStatus',
+    'App\Enums\Billing\SubscriptionState',
+    'App\Enums\Billing\TicketLedgerKind',
+    'App\Enums\Billing\TicketReservationStatus',
+    'App\Enums\Billing\TicketSource',
+    'App\Enums\CheckoutIntent',
+    'App\Enums\CheckoutSessionStatus',
+    'App\Enums\Manual\AnalysisStep',
+    'App\Enums\Manual\JobStatus',
+    'App\Enums\Manual\RenderErrorCode',
+    'App\Enums\Manual\RenderKind',
+    'App\Enums\Manual\RenderStep',
+    'App\Enums\Manual\VideoManualStatus',
+    'App\Enums\Notification\NotificationType',
+    'App\Enums\OrganizationRole',
+    'App\Enums\ProjectRole',
+    'App\Enums\SecurityEventType',
+    'App\Enums\TwoFactorStatus',
+    'App\Http\Controllers\Controller',
+    'App\Http\Controllers\Settings\AccountController',
+    'App\Models\AnalysisJob',
+    'App\Models\Billing\BillingCheckoutSession',
+    'App\Models\Billing\OrganizationQuota',
+    'App\Models\Billing\Plan',
+    'App\Models\Billing\Subscription',
+    'App\Models\Billing\TicketLedgerEntry',
+    'App\Models\Billing\TicketReservation',
+    'App\Models\Organization',
+    'App\Models\OrganizationInvitation',
+    'App\Models\Project',
+    'App\Models\RenderJob',
+    'App\Models\SecurityAuditEvent',
+    'App\Models\User',
+    'App\Models\VideoManual',
+    'App\Notifications\InApp\InvitationReceivedNotification',
+    'App\Notifications\InApp\ManualAnalyzedNotification',
+    'App\Notifications\InApp\ManualRenderedNotification',
+    'App\Notifications\InApp\TicketBalanceLowNotification',
+    'App\Notifications\OrganizationInvitationNotification',
+    'App\Services\Billing\AccountDeletionBillingGuard',
+    'App\Services\Notification\NotificationCenterService',
+    'App\Services\Organization\OrganizationMembershipService',
+    'App\Services\Project\DefaultProjectResolver',
+    'App\Services\Security\SecurityEventRecorder',
+];
+
+/**
+ * Cashier の API 表面から**除外**するメソッド名 (小文字) => ローカル処理である根拠。
+ *
+ * ★決済 API 名の集合は `Laravel\Cashier\Billable` / `Laravel\Cashier\Subscription` の
+ *   public メソッドから**リフレクションで導出**する (Cashier が API を増やしたら自動で
+ *   母集団に入る = fail-closed)。ここに載せた名前だけがその母集団から外れる。
+ * ★走査は受け手を解決しない (`PhpReferenceScanner` の MethodCall は名前だけ)。よって
+ *   ここに載るのは「Cashier と同名だが実際には決済到達でない呼び出し」の allowlist である。
+ * ★`stripe` を含む名前は載せられない (検査 7 が拒否する)。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_CASHIER_LOCAL_METHODS = [
+    'subscriptions' => 'Billable が生やす subscriptions リレーションの取得。AccountDeletionBillingGuard は '
+        .'ローカル subscriptions 行を読むだけで決済事業者 API を呼ばない (T115 の設計そのもの)',
+    'active' => 'OrganizationInvitation の「有効な招待か」を判定するローカル述語。Cashier Subscription の '
+        .'同名メソッドとは無関係で、招待テーブルの列 (accepted_at / expires_at) だけを見る',
+    'user' => 'Request::user() / SecurityEventRecorder の actor 取得。Cashier Subscription の owner 取得と '
+        .'同名なだけで、認証済み actor をローカルに読むだけの呼び出しである',
+];
+
+/**
+ * 決済 binding とみなす container literal (小文字で部分一致)。
+ *
+ * `app('cashier.stripe')` のように **文字列キーで client を取り出す**形を捕まえる。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_CONTAINER_LITERAL_MARKERS = ['stripe', 'cashier'];
+
+/**
+ * 免除 (case value => 30 文字以上の根拠)。**現在 0 件ちょうど**。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_SEAM_EXEMPTION_RATIONALES = [];
+
+/**
+ * mutation 被覆表 (設計 §共通/mutation の本 gate 該当分)。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_MUTATION_COVERAGE = [
+    'M1' => '起点から deleteAccount を外すと閉包が縮み検査 1 (exact-fit) が赤くなる',
+    'M2' => 'OrganizationMembershipService へ Stripe\StripeClient を型注入するだけの property を足すと検査 2 が赤くなる',
+    'M3' => '同じ注入を app(\'cashier.stripe\') の literal 呼び出しで書くと検査 2 が赤くなる',
+];
+
+/** @var list<string> */
+const DELETION_PATH_MUTATION_IDS = ['M1', 'M2', 'M3'];
+
+/**
+ * app/ 配下 1 ファイルぶんの走査結果。
+ *
+ * @return array{class: string, edges: list<string>, payment: list<string>, dynamic: list<string>}
+ */
+function deletionPathScanSource(string $relativePath, string $source): array
+{
+    $result = PhpReferenceScanner::references($relativePath, $source);
+    $tokens = PhpReferenceScanner::tokens($source);
+
+    $class = deletionPathClassFromPath($relativePath);
+    $edges = deletionPathEdges($result, $tokens);
+    $payment = deletionPathPaymentHits($relativePath, $result, $tokens);
+    $dynamic = deletionPathDynamicCallSites($relativePath, $tokens);
+
+    // container literal は到達辺にもなる (`app(App\Foo::class)` は NameReference で拾えるが、
+    // `app('App\Foo')` は文字列なので site を出さない)。
+    foreach (deletionPathContainerLiterals($tokens) as $literal) {
+        if (str_starts_with($literal, 'App\\')) {
+            $edges[] = $literal;
+        }
+    }
+
+    return [
+        'class' => $class,
+        'edges' => array_values(array_unique($edges)),
+        'payment' => $payment,
+        'dynamic' => $dynamic,
+    ];
+}
+
+/**
+ * PSR-4 (`app/` => `App\`) でファイルパスからクラス FQCN を導く。
+ */
+function deletionPathClassFromPath(string $relativePath): string
+{
+    $withoutExtension = preg_replace('/\.php$/', '', $relativePath);
+    Assert::string($withoutExtension);
+
+    return str_replace('/', '\\', 'App'.substr($withoutExtension, strlen('app')));
+}
+
+/**
+ * 到達辺 (`App\` で始まる参照先 FQCN)。
+ *
+ * 型宣言 / `new` / `::class` / instanceof は NameReference・Construction として、
+ * 静的呼び出しの受け手は StaticCall の receiver として拾う。
+ * import を辺に数えるのは意図的な過大近似 = fail-closed (使われていない import も辺にする)。
+ *
+ * ★**import は alias マップ (`ReferenceScanResult::$imports`) から取らず、正規化トークン列の
+ *   修飾名トークンから直接取る**。`PhpReferenceScanner` はクラス本体の `use SomeTrait;` も
+ *   `use` 文として処理するため、**同名の短縮キーで先頭の import を上書きし FQCN を失う**
+ *   (`use App\Models\Concerns\Foo;` + `use Foo;` → alias マップは `foo => 'Foo'`)。
+ *   alias マップだけを見ると **trait 経由の到達辺が丸ごと消える** = fail-open になる
+ *   (実測: 本 gate の fixture 5 形目で発覚)。トークンを直接見れば上書きの影響を受けない。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathEdges(ReferenceScanResult $result, array $tokens): array
+{
+    $names = [];
+    foreach ($result->sites as $site) {
+        if ($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction) {
+            $names[] = $site->name;
+        }
+        if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null) {
+            $names[] = $site->receiver;
+        }
+    }
+    foreach ($tokens as $token) {
+        if ($token['id'] === T_NAME_QUALIFIED || $token['id'] === T_NAME_FULLY_QUALIFIED) {
+            $names[] = ltrim($token['text'], '\\');
+        }
+    }
+
+    return array_values(array_unique(array_filter(
+        $names,
+        static fn (string $name): bool => str_starts_with($name, 'App\\'),
+    )));
+}
+
+/**
+ * 決済事業者記号の hit (人間が読める記述子)。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathPaymentHits(string $relativePath, ReferenceScanResult $result, array $tokens): array
+{
+    $apiMethods = deletionPathPaymentApiMethods();
+    /** @var array<string, string> $hits 重複排除キー => 記述子 */
+    $hits = [];
+
+    foreach ($result->sites as $site) {
+        $hit = deletionPathClassifySite($site, $apiMethods);
+        if ($hit !== null) {
+            $hits[$hit['key']] = $relativePath.':'.$site->line.' '.$hit['descriptor'];
+        }
+    }
+
+    // import だけを持ち site を出さないファイル (`use Stripe\StripeClient;` のみ) も拾う。
+    // alias マップではなくトークンを見る (辺の収集と同じ理由。上書きの影響を受けない)。
+    foreach ($tokens as $token) {
+        if ($token['id'] !== T_NAME_QUALIFIED && $token['id'] !== T_NAME_FULLY_QUALIFIED) {
+            continue;
+        }
+        $name = ltrim($token['text'], '\\');
+        if (deletionPathIsPaymentNamespace($name)) {
+            $hits['name:'.$name] ??= $relativePath.':'.$token['line'].' name '.$name;
+        }
+    }
+
+    foreach (deletionPathContainerLiterals($tokens) as $literal) {
+        $lower = mb_strtolower($literal);
+        foreach (DELETION_PATH_CONTAINER_LITERAL_MARKERS as $marker) {
+            if (str_contains($lower, $marker)) {
+                $hits['literal:'.$literal] = $relativePath.' container literal '.$literal;
+
+                break;
+            }
+        }
+    }
+
+    return array_values($hits);
+}
+
+/**
+ * site 1 件が決済事業者記号かを判定し、重複排除キーと記述子を返す (該当しなければ null)。
+ *
+ * @param  array<string, string>  $apiMethods  小文字メソッド名 => 正規表記
+ * @return array{key: string, descriptor: string}|null
+ */
+function deletionPathClassifySite(ReferenceSite $site, array $apiMethods): ?array
+{
+    if (($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction)
+        && deletionPathIsPaymentNamespace($site->name)
+    ) {
+        return ['key' => 'name:'.$site->name, 'descriptor' => 'name '.$site->name];
+    }
+
+    if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null
+        && deletionPathIsPaymentNamespace($site->receiver)
+    ) {
+        return [
+            'key' => 'static:'.$site->receiver.'::'.$site->name.':'.$site->line,
+            'descriptor' => 'static call '.$site->receiver.'::'.$site->name.'()',
+        ];
+    }
+
+    if ($site->kind !== ReferenceKind::MethodCall && $site->kind !== ReferenceKind::StaticCall) {
+        return null;
+    }
+
+    $lower = mb_strtolower($site->name);
+    if (str_contains($lower, 'stripe')) {
+        return [
+            'key' => 'call:'.$lower.':'.$site->line,
+            'descriptor' => 'call ->'.$site->name.'()',
+        ];
+    }
+    if (array_key_exists($lower, $apiMethods)) {
+        return [
+            'key' => 'call:'.$lower.':'.$site->line,
+            'descriptor' => 'cashier api call ->'.$site->name.'()',
+        ];
+    }
+
+    return null;
+}
+
+/**
+ * 決済事業者の名前空間か (**接頭辞走査は Stripe SDK だけ**。Cashier は facade 1 本に限定する)。
+ */
+function deletionPathIsPaymentNamespace(string $fqcn): bool
+{
+    return str_starts_with($fqcn, 'Stripe\\') || $fqcn === 'Laravel\Cashier\Cashier';
+}
+
+/**
+ * Cashier の API 表面とみなすメソッド名 (小文字 => 正規表記)。
+ *
+ * @return array<string, string>
+ */
+function deletionPathPaymentApiMethods(): array
+{
+    /** @var array<string, string>|null $cache */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $methods = [];
+    foreach ([Billable::class, Subscription::class] as $target) {
+        $reflection = new ReflectionClass($target);
+        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
+            if (! str_starts_with($method->getDeclaringClass()->getName(), 'Laravel\Cashier')) {
+                continue; // Eloquent 由来の継承メソッドは Cashier の API 表面ではない
+            }
+            $methods[mb_strtolower($method->getName())] = $method->getName();
+        }
+    }
+
+    foreach (array_keys(DELETION_PATH_CASHIER_LOCAL_METHODS) as $local) {
+        unset($methods[$local]);
+    }
+
+    $cache = $methods;
+
+    return $methods;
+}
+
+/**
+ * `app('...')` / `resolve('...')` / `->make('...')` の literal 第 1 引数。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathContainerLiterals(array $tokens): array
+{
+    $literals = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['id'] !== T_STRING) {
+            continue;
+        }
+        if (! in_array(mb_strtolower($tokens[$i]['text']), ['app', 'resolve', 'make'], true)) {
+            continue;
+        }
+        $open = $tokens[$i + 1] ?? null;
+        $argument = $tokens[$i + 2] ?? null;
+        if ($open === null || $argument === null) {
+            continue;
+        }
+        if ($open['id'] !== null || $open['text'] !== '(') {
+            continue;
+        }
+        if ($argument['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+
+        $literals[] = deletionPathUnquote($argument['text']);
+    }
+
+    return array_values(array_unique($literals));
+}
+
+/**
+ * 文字列リテラルトークンから値を取り出す。
+ *
+ * ★`stripcslashes()` を通さない。単引用符の `'App\Foo'` に掛けると `\F` が escape として
+ *   消費され `AppFoo` になり、**クラス名の literal が丸ごと辺から落ちる**。
+ */
+function deletionPathUnquote(string $token): string
+{
+    $quote = $token[0] ?? "'";
+    $inner = substr($token, 1, -1);
+
+    return $quote === "'"
+        ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner)
+        : stripcslashes($inner);
+}
+
+/**
+ * 動的メソッド名の呼び出し (`->{$m}()` / `->$m()` / `::{$m}()` / `::$m()`)。
+ *
+ * ★literal の `->{'stripe'}()` は名前が字句的に確定するので動的とみなさない
+ *   (通常の呼び出しと同じ扱いにできるが、実測 0 件なので記号照合には載せず動的からも外す)。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathDynamicCallSites(string $relativePath, array $tokens): array
+{
+    $sites = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $id = $tokens[$i]['id'];
+        if ($id !== T_OBJECT_OPERATOR && $id !== T_NULLSAFE_OBJECT_OPERATOR && $id !== T_DOUBLE_COLON) {
+            continue;
+        }
+
+        $next = $tokens[$i + 1] ?? null;
+        if ($next === null) {
+            continue;
+        }
+
+        // `->$m(` 形
+        if ($next['id'] === T_VARIABLE) {
+            $after = $tokens[$i + 2] ?? null;
+            if ($after !== null && $after['id'] === null && $after['text'] === '(') {
+                $sites[] = $relativePath.':'.$tokens[$i]['line'].' '.$tokens[$i]['text'].$next['text'].'()';
+            }
+
+            continue;
+        }
+
+        // `->{expr}(` 形 (literal は除く)
+        if ($next['id'] === null && $next['text'] === '{') {
+            $inner = $tokens[$i + 2] ?? null;
+            $closing = $tokens[$i + 3] ?? null;
+            $isLiteral = $inner !== null && $inner['id'] === T_CONSTANT_ENCAPSED_STRING
+                && $closing !== null && $closing['id'] === null && $closing['text'] === '}';
+            if (! $isLiteral) {
+                $sites[] = $relativePath.':'.$tokens[$i]['line'].' '.$tokens[$i]['text'].'{...}()';
+            }
+        }
+    }
+
+    return array_values(array_unique($sites));
+}
+
+/**
+ * app/ 全体の走査結果 (1 回だけ実行してテスト間で使い回す)。
+ *
+ * @return array{
+ *     files: int,
+ *     edges: array<string, list<string>>,
+ *     payment: array<string, list<string>>,
+ *     dynamic: array<string, list<string>>,
+ *     edgeCount: int,
+ * }
+ */
+function deletionPathScanApp(): array
+{
+    /**
+     * @var array{
+     *     files: int,
+     *     edges: array<string, list<string>>,
+     *     payment: array<string, list<string>>,
+     *     dynamic: array<string, list<string>>,
+     *     edgeCount: int,
+     * }|null $cache
+     */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $files = PhpReferenceScanner::phpFiles(base_path('app'), 'app');
+
+    $edges = [];
+    $payment = [];
+    $dynamic = [];
+    $edgeCount = 0;
+
+    foreach ($files as $relativePath => $source) {
+        $scan = deletionPathScanSource($relativePath, $source);
+        $edges[$scan['class']] = $scan['edges'];
+        $payment[$scan['class']] = $scan['payment'];
+        $dynamic[$scan['class']] = $scan['dynamic'];
+        $edgeCount += count($scan['edges']);
+    }
+
+    $cache = [
+        'files' => count($files),
+        'edges' => $edges,
+        'payment' => $payment,
+        'dynamic' => $dynamic,
+        'edgeCount' => $edgeCount,
+    ];
+
+    return $cache;
+}
+
+/**
+ * 起点から辿れる app/ 内クラスの閉包 (ソート済み)。
+ *
+ * @return list<string>
+ */
+function deletionPathClosure(): array
+{
+    $edges = deletionPathScanApp()['edges'];
+
+    $queue = [];
+    foreach (DELETION_PATH_ROOTS as $root) {
+        $queue[] = deletionPathRootClass($root);
+    }
+
+    $seen = [];
+    while ($queue !== []) {
+        $class = array_shift($queue);
+        if (array_key_exists($class, $seen) || ! array_key_exists($class, $edges)) {
+            continue;
+        }
+        $seen[$class] = true;
+        foreach ($edges[$class] as $next) {
+            $queue[] = $next;
+        }
+    }
+
+    $closure = array_keys($seen);
+    sort($closure);
+
+    return $closure;
+}
+
+/** `FQCN::method` からクラス部分を取り出す。 */
+function deletionPathRootClass(string $root): string
+{
+    $position = strpos($root, '::');
+
+    return $position === false ? $root : substr($root, 0, $position);
+}
+
+/** `FQCN::method` からメソッド部分を取り出す。 */
+function deletionPathRootMethod(string $root): string
+{
+    $position = strpos($root, '::');
+
+    return $position === false ? '' : substr($root, $position + 2);
+}
+
+// ---------------------------------------------------------------------------
+// 検査
+// ---------------------------------------------------------------------------
+
+test('検査 1: 退会経路の依存閉包は目録と exact-fit で一致する', function (): void {
+    $closure = deletionPathClosure();
+    $inventory = DELETION_PATH_CLOSURE;
+    sort($inventory);
+
+    $missing = array_values(array_diff($closure, $inventory));
+    $stale = array_values(array_diff($inventory, $closure));
+
+    expect(['未登録' => $missing, '残骸' => $stale])->toBe(['未登録' => [], '残骸' => []],
+        '退会経路の依存閉包が変わりました。DELETION_PATH_CLOSURE を更新する前に'
+        .'「この依存は本当に退会経路に必要か」「決済事業者へ到達しないか」をレビューしてください。');
+});
+
+test('検査 2: 閉包内のどのクラスも決済事業者記号を参照しない', function (): void {
+    $payment = deletionPathScanApp()['payment'];
+    $exemptions = array_map(
+        static fn (DeletionPathSeamExemption $case): string => $case->value,
+        DeletionPathSeamExemption::cases(),
+    );
+
+    $violations = [];
+    foreach (deletionPathClosure() as $class) {
+        foreach ($payment[$class] ?? [] as $hit) {
+            if (in_array($class.'#'.$hit, $exemptions, true)) {
+                continue;
+            }
+            $violations[] = $class.' : '.$hit;
+        }
+    }
+
+    expect($violations)->toBe([],
+        '退会経路の依存閉包から決済事業者記号へ到達しています。退会経路は決済事業者 API を呼びません '
+        .'(T115: 自 DB と外部サービスの二重書き込みを避ける / 解約を代行しない)。'
+        .'やむを得ない場合のみ DeletionPathSeamExemption へ 30 文字以上の根拠つきで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査 3: 免除は型付き enum + 30 文字以上の根拠で、現在 0 件ちょうど', function (): void {
+    $cases = array_map(
+        static fn (DeletionPathSeamExemption $case): string => $case->value,
+        DeletionPathSeamExemption::cases(),
+    );
+    $keys = array_keys(DELETION_PATH_SEAM_EXEMPTION_RATIONALES);
+    sort($cases);
+    sort($keys);
+
+    expect($keys)->toBe($cases,
+        'DeletionPathSeamExemption に case を足したら DELETION_PATH_SEAM_EXEMPTION_RATIONALES へも'
+        .'同じ value をキーに根拠を登録してください (免除を型と根拠の両方で縛るための二重宣言です)。');
+
+    $short = [];
+    foreach (DELETION_PATH_SEAM_EXEMPTION_RATIONALES as $value => $rationale) {
+        if (mb_strlen($rationale) < 30) {
+            $short[] = $value.': 根拠が '.mb_strlen($rationale).' 文字';
+        }
+    }
+    expect($short)->toBe([], implode(PHP_EOL, $short));
+
+    // exact-fit cap: 余裕枠は「根拠なしに免除できる枠」になるため 1 でも持たせない。
+    expect($cases)->toHaveCount(0,
+        '免除は現在 0 件です。増やす場合はこの cap も同時に更新し、増やした理由をレビューで残してください。');
+});
+
+test('検査 4: 走査が空振りしていない', function (): void {
+    $scan = deletionPathScanApp();
+
+    expect($scan['files'])->toBeGreaterThan(300, '走査対象ファイルが想定より少ない (ディレクトリ構成の変更を疑う)');
+    expect($scan['edgeCount'])->toBeGreaterThan(0, '到達辺を 1 件も解決できていない (走査が死んでいる)');
+
+    $closure = deletionPathClosure();
+    expect(count($closure))->toBeGreaterThan(1, '閉包が起点だけになっている (辺の解決が死んでいる)');
+
+    // 起点が実在すること (クラス名 / メソッド名のタイポで空振りしない)。
+    foreach (DELETION_PATH_ROOTS as $root) {
+        $class = deletionPathRootClass($root);
+        $method = deletionPathRootMethod($root);
+        expect(class_exists($class))->toBeTrue("起点クラスが実在しません: {$class}");
+        expect(method_exists($class, $method))->toBeTrue("起点メソッドが実在しません: {$root}");
+        expect($closure)->toContain($class);
+    }
+
+    // 閉包の要素がすべて実在の型であること (PSR-4 導出の健全性)。
+    $unresolved = array_values(array_filter(
+        $closure,
+        static fn (string $class): bool => ! class_exists($class) && ! interface_exists($class)
+            && ! trait_exists($class) && ! enum_exists($class),
+    ));
+    expect($unresolved)->toBe([], '閉包に実在しない型が含まれます (PSR-4 導出の破綻): '.implode(', ', $unresolved));
+});
+
+test('検査 5: 自己参照コントロール (本 gate 自身は到達 0 件・記号 hit なし)', function (): void {
+    $self = 'tests/Architecture/AccountDeletionPathGateTest.php';
+    $source = file_get_contents(base_path($self));
+    Assert::string($source, '本 gate 自身を読み込めません');
+
+    $scan = deletionPathScanSource($self, $source);
+
+    // 説明コメント・nowdoc fixture は正規化で落ちるため記号 hit にならない。
+    expect($scan['payment'])->toBe([],
+        '本 gate 自身が決済事業者記号を持っています (コメントで偽赤になっていないか確認してください)。');
+    expect($scan['dynamic'])->toBe([]);
+});
+
+test('検査 6: 閉包内に動的メソッド名の呼び出しは 0 件', function (): void {
+    $dynamic = deletionPathScanApp()['dynamic'];
+
+    $violations = [];
+    foreach (deletionPathClosure() as $class) {
+        foreach ($dynamic[$class] ?? [] as $site) {
+            $violations[] = $class.' : '.$site;
+        }
+    }
+
+    expect($violations)->toBe([],
+        '退会経路の閉包に動的メソッド名の呼び出しがあります。名前が字句的に確定しないため'
+        .'決済事業者記号の照合を迂回できます (deny-by-default で 0 件に pin しています)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査 7: Cashier API 名の導出が生きており、ローカル allowlist は exact-fit で根拠つき', function (): void {
+    $api = deletionPathPaymentApiMethods();
+
+    // 導出が死んでいないこと (Cashier の API 表面は数十件ある)。
+    expect(count($api))->toBeGreaterThan(50, 'Cashier API 名の導出が壊れています (リフレクションの前提を確認)');
+    expect($api)->toHaveKey('newsubscription')
+        ->and($api)->toHaveKey('charge')
+        ->and($api)->toHaveKey('cancelnow');
+
+    $violations = [];
+    foreach (DELETION_PATH_CASHIER_LOCAL_METHODS as $name => $rationale) {
+        if (str_contains($name, 'stripe')) {
+            $violations[] = "{$name}: 名前に stripe を含むメソッドは allowlist へ載せられません";
+        }
+        if (mb_strlen($rationale) < 30) {
+            $violations[] = "{$name}: 根拠が ".mb_strlen($rationale).' 文字';
+        }
+        if (! method_exists(Billable::class, $name)
+            && ! method_exists(Subscription::class, $name)
+        ) {
+            $violations[] = "{$name}: Cashier に実在しないメソッド名です (残骸)";
+        }
+        if (array_key_exists($name, $api)) {
+            $violations[] = "{$name}: allowlist が効いていません";
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+// ---------------------------------------------------------------------------
+// 正負コントロール fixture (6 形)
+// fixture は nowdoc (文字列トークン) なので本ファイルの走査では code にならない (検査 5)。
+// ---------------------------------------------------------------------------
+
+test('負のコントロール 1 形目: 型宣言だけの注入を検出する', function (): void {
+    // laravel-claude-template で実際に fail-open していた形。呼び出しが 1 つも無くても赤くする。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use Stripe\StripeClient;
+    class Fixture {
+        public function __construct(private readonly StripeClient $stripeClient) {}
+        public function run(\Stripe\Customer $customer): void {}
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['payment'])->not->toBe([]);
+    expect(implode(PHP_EOL, $scan['payment']))->toContain('Stripe\StripeClient')
+        ->and(implode(PHP_EOL, $scan['payment']))->toContain('Stripe\Customer');
+});
+
+test('負のコントロール 2 形目: facade 経由の client 取得を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use Laravel\Cashier\Cashier;
+    class Fixture {
+        public function run(): void { Cashier::stripe()->customers->delete('cus_1'); }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['payment'])->not->toBe([]);
+    expect(implode(PHP_EOL, $scan['payment']))->toContain('Laravel\Cashier\Cashier');
+});
+
+test('負のコントロール 3 形目: 完全修飾の static 呼び出しを検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(): void { \Stripe\Customer::retrieve('cus_1'); }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['payment'])->not->toBe([]);
+    expect(implode(PHP_EOL, $scan['payment']))->toContain('Stripe\Customer');
+});
+
+test('負のコントロール 4 形目: app() / resolve() / make() の literal 引数を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(\Illuminate\Contracts\Container\Container $container): void {
+            app('cashier.stripe');
+            resolve('stripe.client');
+            $container->make('Stripe\StripeClient');
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['payment'])->toHaveCount(3);
+    expect(implode(PHP_EOL, $scan['payment']))->toContain('cashier.stripe');
+});
+
+test('負のコントロール 5 形目: trait / import 経由の到達を辺として拾う', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use App\Support\Billing\SomeBillingTrait;
+    class Fixture {
+        use SomeBillingTrait;
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['edges'])->toContain('App\Support\Billing\SomeBillingTrait');
+});
+
+test('負のコントロール 5 形目 (b): クラス本体の use が先頭 import を上書きしても辺を失わない', function (): void {
+    // ★`PhpReferenceScanner` の alias マップは `use App\...\Foo;` と クラス本体の `use Foo;` を
+    //   同じ短縮キーで扱うため、後者が前者を上書きして FQCN を失う。alias マップを辺に使うと
+    //   **trait 経由の到達が丸ごと消える** (fail-open)。トークン直読みでこれを防いでいることを固定する。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Models;
+    use App\Models\Concerns\ShadowedTrait;
+    class Fixture {
+        use ShadowedTrait;
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Models/Fixture.php', $fixture);
+    // 前提の実測: alias マップ側は上書きで短縮名に潰れている (この前提が崩れたら本テストは不要になる)。
+    expect($result->imports['shadowedtrait'] ?? null)->toBe('ShadowedTrait');
+
+    $scan = deletionPathScanSource('app/Models/Fixture.php', $fixture);
+    expect($scan['edges'])->toContain('App\Models\Concerns\ShadowedTrait');
+});
+
+test('負のコントロール 6 形目: 動的メソッド名を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(object $billable, string $method): void {
+            $billable->{$method}();
+            $billable->$method();
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['dynamic'])->toHaveCount(2);
+});
+
+test('正のコントロール: コメント・文字列中の決済事業者記号を誤検出しない', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    /** Stripe\StripeClient を呼ばないことがこのクラスの契約である (Cashier::stripe も同様)。 */
+    class Fixture {
+        public function run(): void {
+            $note = 'Stripe\StripeClient';
+            // Cashier::stripe()->customers->delete($id);
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['payment'])->toBe([]);
+});
+
+test('検査: mutation 被覆表のキー集合が想定 mutation ID と一致する', function (): void {
+    expect(array_keys(DELETION_PATH_MUTATION_COVERAGE))->toBe(DELETION_PATH_MUTATION_IDS);
+});
diff --git a/tests/Feature/Billing/MarkStripeCustomerRedactedCommandTest.php b/tests/Feature/Billing/MarkStripeCustomerRedactedCommandTest.php
new file mode 100644
index 0000000..83f44d9
--- /dev/null
+++ b/tests/Feature/Billing/MarkStripeCustomerRedactedCommandTest.php
@@ -0,0 +1,131 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * 決済事業者側 customer の redaction (非表示化) **実施記録** コマンド。
+ *
+ * ★このコマンドは決済事業者 API を呼ばない。人手 (ダッシュボード操作) で行った redaction を
+ *   自 DB に記録するだけである (退会経路から決済事業者 API を呼ばない原則 = T115 / 標準形 v1)。
+ * ★記録は 2 列セット (`stripe_customer_redacted_at` / `stripe_customer_redacted_id`)。
+ *   日時だけでは「**どの** customer を redact したか」が事後に検証できないため。
+ *   両列同時の不変条件は**アプリ層だけでなく DB の CHECK 制約**でも守る。
+ */
+
+test('dry-run は列を書かない', function (): void {
+    $organization = Organization::factory()->withStripeCustomer('cus_dryrun')->create();
+
+    $this->artisan('billing:mark-stripe-customer-redacted', ['organization' => (string) $organization->getKey()])
+        ->expectsOutputToContain('dry-run')
+        ->assertExitCode(0);
+
+    $organization->refresh();
+    expect($organization->stripe_customer_redacted_at)->toBeNull()
+        ->and($organization->stripe_customer_redacted_id)->toBeNull();
+});
+
+test('--apply で実施日時と customer id が両方入る', function (): void {
+    $organization = Organization::factory()->withStripeCustomer('cus_applied')->create();
+
+    $this->artisan('billing:mark-stripe-customer-redacted', [
+        'organization' => (string) $organization->getKey(),
+        '--apply' => true,
+    ])->assertExitCode(0);
+
+    $organization->refresh();
+    expect($organization->stripe_customer_redacted_at)->not->toBeNull()
+        ->and($organization->stripe_customer_redacted_id)->toBe('cus_applied');
+});
+
+test('片列だけの UPDATE は DB の CHECK 制約が拒否する (アプリ層を迂回しても守られる)', function (): void {
+    $organization = Organization::factory()->withStripeCustomer('cus_check')->create();
+
+    // ★RefreshDatabase のテスト用トランザクションを巻き添えに abort させないよう、
+    //   違反する UPDATE は入れ子トランザクション (savepoint) の中で起こす。
+    $update = fn (array $values) => DB::transaction(
+        fn () => DB::table('organizations')->where('id', $organization->getKey())->update($values),
+    );
+
+    // 日時だけ入れる
+    expect(fn () => $update(['stripe_customer_redacted_at' => now()]))->toThrow(QueryException::class);
+
+    // customer id だけ入れる
+    expect(fn () => $update(['stripe_customer_redacted_id' => 'cus_check']))->toThrow(QueryException::class);
+
+    $organization->refresh();
+    expect($organization->stripe_customer_redacted_at)->toBeNull()
+        ->and($organization->stripe_customer_redacted_id)->toBeNull();
+});
+
+test('両列同時の UPDATE は CHECK 制約を通る (制約が正当な書き込みまで塞いでいない)', function (): void {
+    $organization = Organization::factory()->withStripeCustomer('cus_both')->create();
+
+    DB::table('organizations')
+        ->where('id', $organization->getKey())
+        ->update([
+            'stripe_customer_redacted_at' => now(),
+            'stripe_customer_redacted_id' => 'cus_both',
+        ]);
+
+    $organization->refresh();
+    expect($organization->stripe_customer_redacted_id)->toBe('cus_both');
+});
+
+test('二重実行は no-op で既記録日を表示する (SUCCESS)', function (): void {
+    $organization = Organization::factory()->withStripeCustomer('cus_twice')->create();
+    $arguments = ['organization' => (string) $organization->getKey(), '--apply' => true];
+
+    $this->artisan('billing:mark-stripe-customer-redacted', $arguments)->assertExitCode(0);
+    $organization->refresh();
+    $recordedAt = $organization->stripe_customer_redacted_at;
+    expect($recordedAt)->not->toBeNull();
+
+    $this->travel(1)->days();
+
+    $this->artisan('billing:mark-stripe-customer-redacted', $arguments)
+        ->expectsOutputToContain('記録済み')
+        ->assertExitCode(0);
+
+    $organization->refresh();
+    // 2 回目で日時が上書きされない (最初の実施日が監査証跡として残る)
+    expect($organization->stripe_customer_redacted_at?->toIso8601String())
+        ->toBe($recordedAt?->toIso8601String());
+});
+
+test('stripe_id が無い組織では FAILURE で 1 列も書かない (fail-closed)', function (): void {
+    $organization = Organization::factory()->create();
+    expect($organization->stripe_id)->toBeNull();
+
+    $this->artisan('billing:mark-stripe-customer-redacted', [
+        'organization' => (string) $organization->getKey(),
+        '--apply' => true,
+    ])->assertExitCode(1);
+
+    $organization->refresh();
+    expect($organization->stripe_customer_redacted_at)->toBeNull()
+        ->and($organization->stripe_customer_redacted_id)->toBeNull();
+});
+
+test('存在しない組織 ID は FAILURE', function (): void {
+    $this->artisan('billing:mark-stripe-customer-redacted', ['organization' => '999999', '--apply' => true])
+        ->assertExitCode(1);
+});
+
+test('決済事業者 API を 1 回も呼ばない', function (): void {
+    $organization = Organization::factory()->withStripeCustomer('cus_no_api')->create();
+    // 期待を設定しない mock = 1 度でも呼ばれたら fail (AccountDeletionTest と同じ形)
+    $this->mock(StripeGatewayInterface::class);
+
+    $this->artisan('billing:mark-stripe-customer-redacted', [
+        'organization' => (string) $organization->getKey(),
+        '--apply' => true,
+    ])->assertExitCode(0);
+
+    $organization->refresh();
+    expect($organization->stripe_customer_redacted_id)->toBe('cus_no_api');
+});
diff --git a/tests/Support/Security/DirectFetchInventory.php b/tests/Support/Security/DirectFetchInventory.php
index 0bd40ae..df14633 100644
--- a/tests/Support/Security/DirectFetchInventory.php
+++ b/tests/Support/Security/DirectFetchInventory.php
@@ -157,6 +157,12 @@ public static function inventory(): array
                 .'HTTP から到達不能で scheduler / queue からも呼ばれず、--reason を監査ログへ残す',
                 commandSignature: 'admin:reset-mfa {id} {--reason=}',
             ),
+            'Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php#handle#Organization.whereKey:$organizationId#1' => DirectFetchJustificationEntry::operatorConsole(
+                '運用者が CLI で組織を id で名指しし、決済事業者側 customer の redaction 実施を記録する保守コマンド。'
+                .'HTTP から到達不能で scheduler / queue からも呼ばれず、cross-org の概念が無い (対象は常に 1 組織)。'
+                .'行ロック下で既記録を再確認するため主キーで引いている',
+                commandSignature: 'billing:mark-stripe-customer-redacted {organization} {--apply}',
+            ),
 
             // --- 認証済み actor / 検証済み token claim 由来 ---
             'Http/Controllers/Api/V1/Me/RevokeSessionController.php#destroy#OauthSession.find:$sessionId#1' => DirectFetchJustificationEntry::authenticatedActor(

```

## mutation 実測記録 (実装ノート)

# T141 (PR-A) mutation evidence — 「壊すと赤くなること」の実測

> 詳細設計 `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` §共通: mutation で赤化を確認する手順。
> **実装完了の条件は「テストが緑」ではなく「壊すと赤くなることを実測した」**である。
> PR-A に該当する mutation は **M1 / M2 / M3 / M24** の 4 本。
> 各 mutation は 1 つずつ適用 → 実測 → 戻す、を行い、最後に `git status --short` で残留 0 を確認した。

実行コマンド: `composer test -- <対象テストファイル>` (グローバルテストロック配下)。

---

## M1 (設計どおりでは**赤くならなかった**。設計の予測と実測がずれた例)

| 項目 | 内容 |
|---|---|
| 変異 | `AccountDeletionPathGateTest` の `DELETION_PATH_ROOTS` から `OrganizationMembershipService::deleteAccount` を外す |
| 設計の予測 | 空振り検知 (閉包サイズ floor) が赤くなる |
| **実測** | **16 tests / 16 passed = 緑のまま** |

**なぜずれたか (辻褄を合わせずに記録する)**:
`AccountController::destroy` は `OrganizationMembershipService` を**引数の型宣言で受け取る**。
閉包はクラス粒度で辿るので、`deleteAccount` を起点から外しても
`AccountController` 経由で `OrganizationMembershipService` に到達し、**閉包が 1 件も変わらない**。
すなわち設計が想定した「起点を 1 つ外せば閉包が縮む」という前提が、この 2 起点の関係
(片方がもう片方を型で参照している) では成立しない。

これは gate の欠陥ではなく **mutation の設計ミス**である。閉包の到達判定が生きていることは
M1' が示す。なお PR-B で 3 つ目の起点 (`PurgeDeletionRequestsCommand::handle`) を足すときは、
その起点が他の 2 起点から到達不能なら M1 相当が成立する (足した側で再確認すること)。

## M1' (M1 の代替。実際に赤くなる形へ置き換えて実測)

| 項目 | 内容 |
|---|---|
| 変異 | `DELETION_PATH_ROOTS` から `AccountController::destroy` を外す (他方から到達不能な起点を外す) |
| 赤くなったテスト | **検査 1: 退会経路の依存閉包は目録と exact-fit で一致する** |
| 実測 | 16 tests / 15 passed / **1 failed** |

失敗メッセージ (要点):

```
残骸 => [
  'App\Http\Controllers\Controller',
  'App\Http\Controllers\Settings\AccountController',
]
```

---

## M2

| 項目 | 内容 |
|---|---|
| 変異 | `OrganizationMembershipService` に `private ?\Stripe\StripeClient $mutationProbe = null;` を追加 (**型宣言だけ。呼び出しは 1 つも書かない**) |
| 赤くなったテスト | **検査 2: 閉包内のどのクラスも決済事業者記号を参照しない** |
| 実測 | 16 tests / 15 passed / **1 failed** |

```
App\Services\Organization\OrganizationMembershipService :
  app/Services/Organization/OrganizationMembershipService.php:39 name Stripe\StripeClient
```

これが本 gate の存在理由そのものである。behavioral 2 本
(`AccountDeletionTest`) はこの変異では**緑のまま**である
(型注入しただけで呼ばれていないため、実行時には観測されない)。

## M3

| 項目 | 内容 |
|---|---|
| 変異 | `deleteAccount` の中に `$probe = app('cashier.stripe');` を追加 (container の literal 解決) |
| 赤くなったテスト | **検査 2: 閉包内のどのクラスも決済事業者記号を参照しない** |
| 実測 | 16 tests / 15 passed / **1 failed** |

```
App\Services\Organization\OrganizationMembershipService :
  app/Services/Organization/OrganizationMembershipService.php container literal cashier.stripe
```

## M24

| 項目 | 内容 |
|---|---|
| 変異 | redaction 記録 migration から CHECK 制約 (`organizations_stripe_customer_redaction_pair_check`) の `DB::statement` を削除 |
| 赤くなったテスト | **片列だけの UPDATE は DB の CHECK 制約が拒否する (アプリ層を迂回しても守られる)** |
| 実測 | 8 tests / 7 passed / **1 failed** — `Exception "Illuminate\Database\QueryException" not thrown.` |

---

## 実装中に mutation とは別に発見した fail-open (修正済み)

`tests/Support/PhpReferenceScanner` の alias マップ (`ReferenceScanResult::$imports`) は、
**クラス本体の `use SomeTrait;` を先頭の `use App\...\SomeTrait;` と同じ短縮キーで上書きする**。
結果として alias マップの値が短縮名 (`'SomeTrait'`) に潰れ、**FQCN が失われる**。

閉包の到達辺を alias マップから取ると **trait 経由の到達が丸ごと消える (fail-open)**。
本 gate は辺を**正規化トークン列の修飾名トークンから直接**取ることでこれを回避しており、
`負のコントロール 5 形目 (b)` が「alias マップは潰れている / それでも辺は残る」を両方 pin している。

**走査器そのものは変更していない** (他 gate の振る舞い保存のため)。この非対称は
`ExternalSeamInventoryTest` / `ExternalClientTimeoutInventoryTest` にも同じ形で存在しうるが、
両目録は「決済 / facade / client 構築」の **site** を見ており trait 到達を辺に使っていないため、
本件で挙動が変わる箇所は無い (実測でも app/ の閉包メンバーは 1 件も増減しなかった)。

---

## 後始末

全 mutation を戻した後、`git diff` に mutation の残留が無いことを確認済み
(`git status --short` の差分は本 PR の実装ファイルのみ)。


## テスト結果

- `composer phpstan` : OK (841 files, No errors)
- `composer test` : 4140 tests / 4138 passed / 2 skipped / 0 failed (17785 assertions)
- `vendor/bin/pint --test` : passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (130 files / 1292 tests) / `pnpm build` : すべて green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (10 files / 106 tests) : green
- `composer test:browser` : **実行していない** (本 PR は UI / route を 1 行も変更していないため)
