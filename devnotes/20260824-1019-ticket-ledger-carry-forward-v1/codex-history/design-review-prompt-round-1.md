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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は上に挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク (RefreshDatabase はグローバル適用、--parallel 実行)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- webmozart/assert 2.4.1 (Assert::integerish() は string|int|float を返す)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠 / 11. Atomic Design準拠 — 本件は UI/frontend 変更を含まないため対象外

【この案件の追加文脈】
- 家系の機能台帳 (lctl) が確定した正典 v1 (二段判定・収束繰越形) への追従である。
- 概念設計は Codex gpt-5.6-terra の 4 ラウンドで APPROVED 済み。
  その過程で「SoftDeletes された Organization が畳み込みの母集団から漏れる」という
  v0 の実在バグが判明し、同じ PR で是正する設計になっている。
- 追記専用台帳 (ticket_ledger_entries) の残高保存が最優先の不変条件である。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: ticket-ledger-carry-forward-v1 (追記専用台帳の畳み込みを正典 v1 へ追従)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md 正本の転記）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本施策に効く追加の規約**

- 思考原則 3「**後方互換の並走を残さない**」 → `carried_forward_through` 列・単調前進ロジック・
  繰越行の冪等キーは同じ PR で撤去する。
- ドメイン固有規約 17「NULL が初期状態を表す列の分類」 → 列を落としたら
  `NullableStateColumnRegistry` と件数 pin を同じ PR で直す。
- 「静的検査 (gate) と走査器の共通規約」(a)〜(e) と「新設・変更するときに同じ PR で揃える 4 点」。
- 月/年の加減算は `*NoOverflow` を明示する (`CarbonOverflowArithmeticGateTest`)。
- `declare(strict_types=1)` + 日本語コメント (git 追跡下の PHP 全数が対象)。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用済で個別 `DatabaseTransactions` 禁止
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `composer fix` (Pint)
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) — Codex `gpt-5.6-terra` Round 4 で **APPROVED**
- レビュー履歴: `conceptual-review-round-{1..4}.md` / `codex-history/conceptual-review-decisions-round-{1..3}.md`

## 前提の実測 (この設計が依拠する現物の確認)

| 事実 | 確認方法 |
|---|---|
| `groupQuery()` に `expires_at` と現在時刻を比べる述語が 0 件 | `app/Services/Billing/TicketLedgerCarryForwardService.php` L349-380 実読 |
| 繰越行の `created_at` は `CarbonImmutable::now()` | 同 L263 実読 |
| `delta` 列は `integer` (int4) / `description` は NOT NULL / `source` は nullable / `idempotency_key` は nullable UNIQUE / `organization_id` は `cascadeOnDelete` | `database/migrations/2026_06_11_091400_create_ticket_tables.php` L36-66 実読 |
| `Organization` は `SoftDeletes`。`app/` 配下に `forceDelete` の呼び出しは 0 件 | `app/Models/Organization.php` L74 / `grep -rn forceDelete app/` |
| `app/` 配下に `withTrashed(` / `onlyTrashed(` の出現は 0 件 | `grep -rn "withTrashed\|onlyTrashed" app/` |
| 表名リテラルの出現は 2 ファイル各 2 件。モデル参照 + 変更語彙は 2 ファイル (台帳 service 7 件 / 畳み込み 2 件)。削除語彙は畳み込み 1 ファイル 1 件 | `token_get_all` ベースの実測スクリプトで計数 |
| `Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()` は `PrimaryKeyStaticQueryScanner::candidates()` が **0 件** (id 起点にすると 1 件になる) | 同走査器を直接呼ぶ実測スクリプト |
| `webmozart/assert` は **2.4.1** で `integerish()` は `string\|int\|float` を返す (`(int) Assert::integerish(...)` が有効) | `composer.lock` / Reflection |
| 変更対象パスは `docs/template-fingerprints.json` のキーに **1 件も無い** (= テンプレート共有ファイルではない) | 同 JSON のキー突合 |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 畳み込みサービスを正典 v1 形へ差し替え + `Retention/` へ移設 | `app/Services/Billing/Retention/TicketLedgerCarryForwardService.php` (新規) / `app/Services/Billing/TicketLedgerCarryForwardService.php` (削除) / `app/Services/Billing/Retention/TicketLedgerEntryPurger.php` | 最高 |
| 2 | 集約結果の境界 DTO | `app/DataTransferObjects/Billing/CarryForwardGroup.php` (新規) | 最高 |
| 3 | `expiredRemaining` の共通定義の明文化 | `app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php` | 高 |
| 4 | `carried_forward_through` の撤去 | drop migration (新規) / `app/Models/Billing/TicketLedgerEntry.php` / `tests/Support/InitialState/NullableStateColumnRegistry.php` / `tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php` | 高 |
| 5 | 変更サイトの静的ゲート新設 | `tests/Architecture/TicketLedgerMutationSiteGateTest.php` (新規) / `tests/Support/Architecture/TicketLedgerMutationScanner.php` (新規) / `tests/Support/Architecture/TicketLedgerMutationInventory.php` (新規) / `tests/Unit/Architecture/TicketLedgerMutationScannerTest.php` (新規) | 高 |
| 6 | 読み手の目録の追随 | `tests/Architecture/TicketLedgerReaderInventoryTest.php` | 高 |
| 7 | 挙動テストの書き換え (テストファーストの起点) | `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` | 最高 |
| 8 | DTO の単体テスト | `tests/Unit/Billing/CarryForwardGroupTest.php` (新規) | 高 |
| 9 | 規約・文書の追随 | `AGENTS.md` / `docs/architecture.md` / `docs/billing-retention-runbook.md` | 中 |

---

## 施策 1: 畳み込みサービスを正典 v1 形へ差し替え + `Retention/` へ移設

### 変更箇所

- 削除: `app/Services/Billing/TicketLedgerCarryForwardService.php` (395 行)
- 新規: `app/Services/Billing/Retention/TicketLedgerCarryForwardService.php`
- 変更: `app/Services/Billing/Retention/TicketLedgerEntryPurger.php` (L10 の `use` を削除。同一 namespace になる)

### 波及変更

- TypeScript 型定義: **なし** (フロントに台帳 kind の型は存在せず、
  `TicketLedgerReaderInventoryTest` 検査 7 がその不在を deny-by-default で固定している)
- API Resource / DTO: 施策 2 (`CarryForwardGroup` 新設) / 施策 3 (`BillingRetentionPurgeResultDto` の docblock)
- テストファイル: 施策 5・6・7・8
- 参照の更新: `app/Models/Billing/TicketLedgerEntry.php` の docblock /
  `app/Enums/Billing/BillingRetentionTarget.php` L79 のコメント (どちらもクラス名の言及のみ。
  FQCN が `App\Services\Billing\Retention\...` へ変わるので文言を直す)
- **DI**: コンストラクタ注入のみで `app()` の文字列解決は無い (`TicketLedgerEntryPurger` の
  型宣言が namespace 変更に追随する)。`BillingRetentionTargetInventoryTest` の
  on-disk 走査は `BillingRetentionPurger` を実装するクラスだけを拾うので、
  同ディレクトリへ非 purger を置いても**母集団に入らない** (実読で確認済み)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\DataTransferObjects\Billing\CarryForwardGroup;
use App\Enums\Billing\BillingRetentionTarget;
use App\Enums\Billing\TicketLedgerKind;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 保持期限以前のチケット台帳の畳み込み (append-only 台帳に対する唯一の例外経路)。
 *
 * `ticket_ledger_entries` は delta 型の追記専用台帳で、残高は
 * 「未失効行の delta 合計 − reserved 予約の合計」である。古い行を単純に消すと残高が変わるため、
 * **判定を 2 段**に分ける。
 *
 *  - 第 1 段 (適格性): `created_at <= 閾値`。**これを満たさない行は 1 行も触らない**
 *  - 第 2 段 (処理方式。実行開始時に 1 度だけ確定した `$now` で判定する)
 *    - 寄与しない (`expires_at` が非 null かつ `expires_at <= now`) → **物理削除**
 *    - 寄与する (`expires_at` が null または `> now`) → **(組織, 出所, 失効時刻) ごとに
 *      delta を合算した繰越 1 行へ畳み込む**
 *
 * 第 2 段の述語は {@see \App\Services\Billing\TicketLedgerService} の残高集計条件
 * (`expires_at IS NULL OR expires_at > now`) の**厳密な補集合**である。ずらすと
 * 「どちらの枝にも入らない行」か「両方に入る行」が生まれる。
 *
 * 繰越行は説明・決済事業者の識別子・冪等キー・予約への参照・個別の付与時刻を一切引き継がない。
 * `created_at` は**畳み込んだ行の最大 `created_at`** = 集約の基準時刻である (実行時刻ではない)。
 * 実行時刻にすると繰越行が次回以降ずっと保持期限より新しい側に居座り、実行のたびに増える。
 * 集約の基準時刻なら次回も保持期限以前に留まるので、**集約キーごとに 1 行へ収束する**。
 * 合計 delta が 0 の集約キーは繰越行を作らず削除だけ行う。
 *
 * ★**母集団は論理削除済み組織も含む**。`Organization` は `SoftDeletes` であり、
 *   global scope の効く経路で組織を列挙すると**退会済み組織の台帳が永久に畳まれない**
 *   (期限超過が残り続けて保持期限の宣言が満たせなくなる)。よって列挙とロックの両方を
 *   `withTrashed()` 起点にする。`withTrashed(` の出現は
 *   `TicketLedgerMutationSiteGateTest` が本ファイルへ件数まで固定する
 *   (テナント境界を迂回する一般的な主キー取得へ転用させない)。
 *
 * ★**`countExpired()` の母集団は「取引明細」= 繰越行を除いた適格行**である。
 *   繰越行は取引記録ではなく継続状態を表す集約レコードなので、保持期限が消す対象ではない
 *   (語の正本は {@see BillingRetentionPurgeResultDto} の docblock)。
 *
 * 直列化は組織行の排他ロック ({@see \App\Services\Billing\TicketLedgerService} が
 * 残高判定の前に取るのと同じ点) で行う。組織 1 件 = 1 トランザクションで、
 * 1 組織の失敗は他の組織を止めない。
 *
 * **append-only との関係**: モデルは `updating` / `deleting` を例外化しているが、
 * Eloquent の一括削除はモデルイベントを発火しない。append-only は
 * 「業務経路では追記しかしない」という不変条件であり、
 * **保持期限の決着 (失効済み行の物理削除と残高寄与行の畳み込み) だけが唯一の例外**である。
 *
 * **保証しないこと**: 真の並行実行 (別 connection + barrier) での排他の実効性は測っていない。
 * 代わりに「台帳書き込みの既存経路と同じ組織行ロックを、変更より先に、同じトランザクションの
 * 内側で取ること」を静的に pin する。
 */
final class TicketLedgerCarryForwardService
{
    /** 繰越行の説明 (個別明細を引き継がない集約状態であることを示す固定文言)。 */
    public const string DESCRIPTION = '保持期限以前の明細の繰越 (集約)';

    /**
     * 繰越行が値を持つ列 (集約キー + 固定文言 + 主キー・時刻)。
     *
     * ★この 2 定数が「繰越行は明細を持たない」の**正本**である。
     *   `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` の列分類検査が
     *   「両者の和 == 実スキーマの全列」を deny-by-default で突き合わせるので、
     *   表に列を足したら必ずどちらかへ分類することになる。
     *
     * @var list<string>
     */
    public const array VALUED_COLUMNS = [
        'id', 'organization_id', 'delta', 'kind', 'source', 'expires_at', 'description', 'created_at',
    ];

    /** 繰越行では必ず NULL になる列 (取引の明細・決済事業者の識別子・冪等キー・予約参照)。 @var list<string> */
    public const array NULL_COLUMNS = [
        'reservation_id', 'granted_at', 'stripe_checkout_session_id', 'stripe_invoice_id',
        'payment_intent_id', 'purchase_amount', 'idempotency_key',
    ];

    /**
     * 保持期限以前の**取引明細**の件数 (繰越行は数えない)。
     *
     * 論理削除済み組織の行も数える (組織を結合しないので global scope は効かない)。
     * 列挙側 (`organizationsWithExpiredDetails`) も `withTrashed()` なので**両者の母集団は一致する**。
     */
    public function countExpired(CarbonImmutable $threshold): int
    {
        return $this->detailScope($threshold)->count();
    }

    /**
     * 保持期限以前の台帳を組織ごとに畳み込む。
     *
     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
     */
    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        // ★`$now` は 1 度だけ確定して全組織・全集約キーへ渡す。実行中に時計が進むと
        //   「失効済み」と「寄与する」のどちらの枝にも入らない行が生まれる。
        $now = CarbonImmutable::now();
        $candidates = $this->countExpired($threshold);
        $processed = 0;
        $unexpectedFailures = 0;

        foreach ($this->organizationsWithExpiredDetails($threshold) as $organization) {
            try {
                $processed += $this->carryForwardOrganization($organization, $threshold, $now);
            } catch (Throwable $e) {
                $unexpectedFailures++;
                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
                Log::warning('ticket ledger carry forward failed', [
                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
                    'organization_id' => $organization->getKey(),
                    'error_class' => $e::class,
                ]);
            }
        }

        return new BillingRetentionPurgeResultDto(
            target: BillingRetentionTarget::TicketLedgerEntry,
            candidates: $candidates,
            processed: $processed,
            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
            // (「安全のため残した」ではなく「決着できなかった」である)。
            failClosed: 0,
            unexpectedFailures: $unexpectedFailures,
            expiredRemaining: $this->countExpired($threshold),
        );
    }

    /**
     * 第 1 段の適格性 + 「取引明細である」(繰越行を除く)。
     *
     * @return EloquentBuilder<TicketLedgerEntry>
     */
    private function detailScope(CarbonImmutable $threshold): EloquentBuilder
    {
        return TicketLedgerEntry::query()
            ->where('created_at', '<=', $threshold)
            ->where('kind', '!=', TicketLedgerKind::CarryForward->value);
    }

    /**
     * 期限超過の取引明細を持つ組織 (id 昇順 = ロック順序の固定)。
     *
     * ★`withTrashed()` が必須である。退会 (論理削除) は課金記録の寿命を縮めない
     * (`docs/template-divergence.md` D23)。
     *
     * @return Collection<int, Organization>
     */
    private function organizationsWithExpiredDetails(CarbonImmutable $threshold): Collection
    {
        return Organization::withTrashed()
            ->whereHas(
                'ticketLedgerEntries',
                fn (EloquentBuilder $query): EloquentBuilder => $query
                    ->where('created_at', '<=', $threshold)
                    ->where('kind', '!=', TicketLedgerKind::CarryForward->value),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * 1 組織ぶんの畳み込み。**順序が契約である**:
     *   1. トランザクションを開く
     *   2. 組織行を `lockForUpdate`
     *   3. 寄与しない (失効済み) 行の物理削除
     *   4. 寄与する行を集約キーごとに **1 文**で集計 (件数 / 合計 / 最大 created_at / 繰越行数)
     *   5. 既に繰越 1 行だけの集約キーは短絡 (収束)
     *   6. 集約キーの行を削除
     *   7. **件数照合** (不一致は例外 → 組織ごと巻き戻る)
     *   8. 繰越行の追記 (合計 0 は作らない)
     *
     * @return int 決着した (消えた) 行数
     */
    private function carryForwardOrganization(
        Organization $organization,
        CarbonImmutable $threshold,
        CarbonImmutable $now,
    ): int {
        $organizationId = $organization->getKey();
        Assert::integer($organizationId, '組織 id が解決できません (畳み込みは中止する)');

        return DB::transaction(function () use ($organizationId, $threshold, $now): int {
            // 残高判定・台帳追記の直列化点 (TicketLedgerService::lockOrganizationRow と同じ点)。
            // 論理削除済み組織も対象なので withTrashed で取る。
            Organization::withTrashed()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            // (a) 残高に寄与しない期限以前の行 (失効済み) → 物理削除。
            //     繰越行が失効済みになった場合もここで消える (= 失効窓の有界化)。
            $processed = $this->deletedCount($this->expiredScope($organizationId, $threshold, $now)->delete());

            // (b) 残高に寄与する期限以前の行 → 集約キーごとに畳み込む。
            //     処理順は**決定的**にする (集約キーの並び順)。1 つの集約キーで失敗したときに
            //     どこまで進んでいたかが実行のたびに変わると、巻き戻しの契約を測れない。
            foreach ($this->contributingGroups($organizationId, $threshold, $now) as $group) {
                // 既に繰越 1 行だけなら何もしない (無駄な入れ替えを避ける = 収束の短絡)
                if ($group->rowCount === 1 && $group->carryForwardRows === 1) {
                    continue;
                }

                $deleted = $this->deletedCount(
                    $this->groupScope($organizationId, $threshold, $now, $group)->delete(),
                );

                // **集計した集合と削除した集合が一致することを確認する**。
                // 組織行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
                // ロックを取らない冪等 insert である)。集計と削除の間に
                // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を
                // 削除が巻き込む** = その枚数ぶん残高が消える。件数の不一致で検出し、
                // トランザクションごと巻き戻す (次回の実行で同じ組織を再処理して収束する)。
                if ($deleted !== $group->rowCount) {
                    throw new RuntimeException(
                        '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
                    );
                }

                $processed += $deleted;

                // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
                if ($group->deltaSum !== 0) {
                    $this->appendCarryForward($organizationId, $group);
                }
            }

            return $processed;
        });
    }

    /** Eloquent の一括削除は driver 実装まで型が確定しないので境界で数値に確定させる。 */
    private function deletedCount(mixed $result): int
    {
        return (int) Assert::integerish($result);
    }

    /**
     * 残高に寄与しない (既に失効した) 期限以前の行。
     *
     * @return EloquentBuilder<TicketLedgerEntry>
     */
    private function expiredScope(
        int $organizationId,
        CarbonImmutable $threshold,
        CarbonImmutable $now,
    ): EloquentBuilder {
        return TicketLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);
    }

    /**
     * 集約キーごとの集計結果。
     *
     * ★**クエリビルダで集計する** (Eloquent 経由だと `source` が列挙型へ cast され、
     *   その値をさらに `TicketSource::from()` へ渡す二重変換で実行時に落ちる)。
     * ★**件数・合計・最大 created_at・繰越行数を 1 文で取る**。分けて発行すると文ごとに
     *   snapshot が変わり (READ COMMITTED)、「合計には入っていないが件数には入っている」行が
     *   生まれて残高保存の検査そのものが壊れる。
     *
     * @return list<CarryForwardGroup>
     */
    private function contributingGroups(
        int $organizationId,
        CarbonImmutable $threshold,
        CarbonImmutable $now,
    ): array {
        $rows = DB::table('ticket_ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold)
            ->where(function (QueryBuilder $query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->groupBy('source', 'expires_at')
            ->selectRaw(
                'source, expires_at, SUM(delta) AS delta_sum, MAX(created_at) AS max_created_at, '
                .'COUNT(*) AS row_count, SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) AS carry_forward_rows',
                [TicketLedgerKind::CarryForward->value],
            )
            ->orderBy('source')
            ->orderBy('expires_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = CarryForwardGroup::fromRow($row);
        }

        return $groups;
    }

    /**
     * 集約キー 1 件ぶんの行 (削除対象)。**繰越行も含む** (合算して 1 行へ置き換えるため)。
     *
     * @return EloquentBuilder<TicketLedgerEntry>
     */
    private function groupScope(
        int $organizationId,
        CarbonImmutable $threshold,
        CarbonImmutable $now,
        CarryForwardGroup $group,
    ): EloquentBuilder {
        $query = TicketLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold)
            ->where(function (EloquentBuilder $inner) use ($now): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });

        $query = $group->source === null
            ? $query->whereNull('source')
            : $query->where('source', $group->source->value);

        return $group->expiresAt === null
            ? $query->whereNull('expires_at')
            : $query->where('expires_at', $group->expiresAt);
    }

    /**
     * 繰越行の追記 (生成点で初期状態を明示代入する。AGENTS.md 実装規約)。
     *
     * 所有権キー (`organization_id`) と FK (`reservation_id`) は relation 経由で代入する。
     */
    private function appendCarryForward(int $organizationId, CarryForwardGroup $group): void
    {
        $entry = new TicketLedgerEntry;
        $entry->organization()->associate($organizationId);
        $entry->delta = $group->deltaSum;
        $entry->kind = TicketLedgerKind::CarryForward;
        $entry->source = $group->source;               // 出所は保存する (集約キー)
        $entry->expires_at = $group->expiresAt;        // 残高の窓は保存する (集約キー)
        $entry->description = self::DESCRIPTION;
        $entry->reservation()->associate(null);        // 予約への参照は引き継がない
        $entry->granted_at = null;                     // 個別の付与時刻は引き継がない
        $entry->stripe_checkout_session_id = null;     // 決済事業者の識別子は引き継がない
        $entry->stripe_invoice_id = null;
        $entry->payment_intent_id = null;
        $entry->purchase_amount = null;
        $entry->idempotency_key = null;                // 冪等キーは引き継がない
        // created_at を明示代入してから save する (Eloquent は CREATED_AT が dirty なら上書きしない)。
        // これは集約の基準時刻であり、実行時刻ではない (収束の要)。
        $entry->created_at = $group->maxCreatedAt;
        $entry->save();
    }
}
```

### 撤去する v0 の要素 (同じ PR で消す)

| 撤去するもの | 理由 |
|---|---|
| `IDEMPOTENCY_KEY_PREFIX` / `idempotencyKeyFor()` / `KEY_TIME_FORMAT` / `NULL_TOKEN` | 繰越行は冪等キーを持たない。二重の繰越行は「同一トランザクション内で削除 → 追記」と組織行ロックが構造で防ぐ |
| `CARRY_FORWARD_DESCRIPTION` | `DESCRIPTION` へ改名 (正典の名前に揃える) |
| `resolveThrough()` / `carried_forward_through` の書き込み | 集約の終端は繰越行の `created_at` が表す (施策 4 で列ごと落とす) |
| `expiredGroups()` (モデルの distinct select) | `contributingGroups()` (クエリビルダ集計 + 境界 DTO) が置き換える |
| `groupQuery()` (単段の述語) | `expiredScope()` / `groupScope()` / `contributingGroups()` の 3 つに分かれる |
| `aggregateGroup()` の `Assert::numeric` + `(int)` | 範囲検査つきの DTO 境界 (`CarryForwardGroup::fromRow`) が引き受ける |

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`int` / `Collection<int, Organization>` / `list<CarryForwardGroup>` / `EloquentBuilder<TicketLedgerEntry>`)
- [x] null 安全 (`Assert::integer($organizationId, ...)` で `getKey()` の `mixed` を絞る)
- [x] DTO を返している (`BillingRetentionPurgeResultDto` / `CarryForwardGroup`。配列返却は private の内部だけ)
- [x] Generics の型パラメータが正しい (`EloquentBuilder<TicketLedgerEntry>` / `Collection<int, Organization>`)
- 注意: `Organization::withTrashed()` は `app/` で初出である。larastan がモデルの soft-delete scope を
  静的に解決できない場合は **`Organization::query()->withTrashed()`** へ書き換える
  (施策 5 の gate は `withTrashed(` のトークン出現で数えるのでどちらでも検出できる。
  ただし FQCN 受け手の照合は静的呼び出し形のときだけ効くので、書き換えたら gate の
  受け手照合を「同ファイル内の `Organization::query()` の存在」に読み替える判断を
  gate の docblock に書く)。**型を緩めて黙らせる (禁止事項 2) 方向へは倒さない**

### リスク

- **`processed` の意味が変わる**。v0 は「置換で消えた行数」で `candidates` と一致していたが、
  v1 は「削除した行数」であり、再畳み込みで既存の繰越行を消した分を含むので
  `processed >= candidates` になりうる。→ 既存テストの `processed === candidates` を
  施策 7 で書き換える (`processed >= candidates` + 個別ケースの実数固定)。
- **`orderBy('expires_at')` の NULL 順序は driver 依存**。決定性は「同じ driver では毎回同じ」で
  足りる (契約は失敗時の途中状態の再現性)。テストで順序そのものを固定しない。
- **`selectRaw` の binding 位置**。Laravel は select binding を where binding より前に並べるので
  `SUM(CASE WHEN kind = ? ...)` は正しく束縛される。ここは Feature テストが実挙動で受ける。

---

## 施策 2: 集約結果の境界 DTO (`CarryForwardGroup`)

### 変更箇所

- 新規: `app/DataTransferObjects/Billing/CarryForwardGroup.php`

### 波及変更

- TypeScript 型定義: なし (サーバ内部の境界型で、Inertia / API に出ない)
- テストファイル: 施策 8 (`tests/Unit/Billing/CarryForwardGroupTest.php`)
- 読み手の目録: 施策 6 (列名リテラルを持つので登録が要る)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\TicketSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Webmozart\Assert\Assert;

/**
 * 畳み込みの集約キー 1 件ぶん (DB 集計結果の境界 DTO)。
 *
 * 集計は Eloquent ではなくクエリビルダで行い、**cast を通らない生値**を受け取ってから
 * ここで型を確定させる。モデル経由で `selectRaw` すると `source` が列挙型へ cast され、
 * その値をさらに `TicketSource::from()` へ渡す二重変換で実行時エラーになるためである。
 *
 * ★**範囲検査は PHP `int` へ変換する前に行う**。`delta` 列は int4 なので、
 *   合計が `[-2147483648, 2147483647]` を外れたら fail-closed で落とす。driver が
 *   数値文字列で返す場合に先にキャストすると、**PHP 整数範囲を超える値が壊れた後で**
 *   検査することになるため、**10 進文字列のまま**符号 + 桁数 + 辞書順で比較する。
 *
 * ★**列ごとの許容型**:
 *   - `source`: `null` はそのまま保持する / **文字列だけ** `TicketSource::from()` へ渡す
 *     (未知の値は列挙型が例外にする) / それ以外の型は例外
 *   - `expires_at`: `null` / 文字列 / `DateTimeInterface`
 *   - `max_created_at`: **非 null** 必須。文字列 / `DateTimeInterface`
 *   - `delta_sum`: `int` または 10 進整数の文字列のみ (bool / float / 指数表記 / 小数 /
 *     空文字 / 前後空白は例外)
 *   - `row_count` / `carry_forward_rows`: 非負整数
 *
 * ★**想定外の余剰列は拒否しない**。集約 SQL は畳み込みサービスが組み立てるので余剰列は
 *   入らず、拒否すると driver が付ける内部列で偽赤になりうる。**列の欠落は例外**にする。
 *
 * ★動的プロパティ参照 (`$row->$name`) は使わない (`get_object_vars()` 経由)。
 *   arch ベースラインの動的メンバ目録を太らせないためである。
 */
final readonly class CarryForwardGroup
{
    /** `delta` 列 (int4) の下限。 */
    private const int DELTA_MIN = -2147483648;

    /** `delta` 列 (int4) の上限。 */
    private const int DELTA_MAX = 2147483647;

    public function __construct(
        public ?TicketSource $source,
        public ?CarbonImmutable $expiresAt,
        public int $deltaSum,
        public CarbonImmutable $maxCreatedAt,
        public int $rowCount,
        public int $carryForwardRows,
    ) {}

    /** 生の集計行 (stdClass) を型の確定した DTO へ変換する (level 10 の narrowing はここ 1 箇所)。 */
    public static function fromRow(object $row): self
    {
        $source = self::nullableString($row, 'source');
        $maxCreatedAt = self::nullableTimestamp($row, 'max_created_at');
        Assert::notNull($maxCreatedAt, '集約の基準時刻 (max_created_at) が取得できない');

        return new self(
            $source === null ? null : TicketSource::from($source),
            self::nullableTimestamp($row, 'expires_at'),
            self::int4($row, 'delta_sum'),
            $maxCreatedAt,
            self::natural($row, 'row_count'),
            self::natural($row, 'carry_forward_rows'),
        );
    }

    /** 動的プロパティ読み出しの唯一の口 (存在しない列は表明で落とす)。 */
    private static function value(object $row, string $property): mixed
    {
        Assert::propertyExists($row, $property, "集計行に列 {$property} が無い");

        /** @var array<string, mixed> $values */
        $values = get_object_vars($row);

        return $values[$property];
    }

    /** 文字列列 (列挙値の生表現)。 */
    private static function nullableString(object $row, string $property): ?string
    {
        $value = self::value($row, $property);
        if ($value === null) {
            return null;
        }
        Assert::string($value, "集計行の列 {$property} が文字列ではない");

        return $value;
    }

    /** 日時列 (driver によって文字列 / DateTimeInterface で返る)。 */
    private static function nullableTimestamp(object $row, string $property): ?CarbonImmutable
    {
        $value = self::value($row, $property);
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        Assert::stringNotEmpty($value, "集計行の列 {$property} が日時として解釈できない");

        return CarbonImmutable::parse($value);
    }

    /** int4 の範囲に収まる整数 (**変換前に**範囲を判定する)。 */
    private static function int4(object $row, string $property): int
    {
        $value = self::value($row, $property);

        if (is_int($value)) {
            Assert::range($value, self::DELTA_MIN, self::DELTA_MAX, self::rangeMessage($property));

            return $value;
        }

        Assert::string($value, "集計行の列 {$property} が int でも文字列でもない");
        Assert::regex($value, '/\A-?[0-9]+\z/', "集計行の列 {$property} が 10 進整数の表記ではない");
        Assert::true(self::withinInt4($value), self::rangeMessage($property));

        return (int) $value;
    }

    /** 10 進文字列のまま int4 境界と比較する (符号 → 桁数 → 辞書順)。 */
    private static function withinInt4(string $decimal): bool
    {
        $negative = str_starts_with($decimal, '-');
        $digits = ltrim($negative ? substr($decimal, 1) : $decimal, '0');
        if ($digits === '') {
            return true; // 0 (`-0` / `000` を含む)
        }
        $limit = $negative ? '2147483648' : '2147483647';
        if (strlen($digits) !== strlen($limit)) {
            return strlen($digits) < strlen($limit);
        }

        return strcmp($digits, $limit) <= 0;
    }

    /** 非負整数 (件数)。 */
    private static function natural(object $row, string $property): int
    {
        $value = self::value($row, $property);
        $number = (int) Assert::integerish($value, "集計行の列 {$property} が整数として解釈できない");
        Assert::natural($number, "集計行の列 {$property} が負である");

        return $number;
    }

    private static function rangeMessage(string $property): string
    {
        return "繰越行の {$property} が delta 列 (signed integer) の範囲を超えた (この組織の処理を巻き戻す)";
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示 / `private static` helper もすべて型つき
- [x] null 安全 (`Assert::notNull` / `Assert::string` / `Assert::regex` / `Assert::natural`)
- [x] DTO を返している (`self`)
- [x] `Assert::integerish()` の戻り値 (`string|int|float`) をキャストして `int` に確定

### リスク

- `Assert::regex` は `preg_match` を使う。`PcreUnicodeModifierGateTest` が
  `u` 修飾子の要否を見張る可能性があるので、実装時に同 gate の対象条件を確認する
  (`\A-?[0-9]+\z` は ASCII のみで、10 進整数の判定に Unicode 解釈は不要。
  対象になるなら gate の規約に従って修飾子か例外登録を選ぶ)。

---

## 施策 3: `expiredRemaining` の共通定義の明文化

### 変更箇所

- `app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php` の docblock (件数の関係の節)

### 変更後コード (docblock の差分)

```php
 * 件数の関係:
 *   candidates      = 保持期限を超えた**決着対象**の件数 (purge 前)
 *   processed       = 実際に決着させた件数 (削除した行数。台帳では畳み込みで消えた行数)
 *   failClosed      = 安全のため残した件数 (起算不能の異常 + 参照中で消せないもの)
 *   expiredRemaining = purge 後に残った決着対象の件数
 *
 * ★**「決着対象」の共通定義**: 各 target の保持ポリシーにより**物理削除または不可逆な
 *   明細除去の対象となるレコード数**であり、**継続状態を表す集約レコードは含まない**。
 *   台帳 (`ticket_ledger_entry`) では `kind = carry_forward` の**繰越行**がその集約レコードに
 *   該当する (取引の明細を 1 つも持たない残高のスナップショットであり、畳み込みの結果として
 *   保持期限以前に留まり続ける)。他の 6 target は集約レコードを持たないので実効値は変わらない。
 *   **この定義が正本**であり、`docs/architecture.md` と
 *   `docs/billing-retention-runbook.md` はここを参照する (2 か所に書くと必ず食い違う)。
```

### 波及変更 (この語の利用箇所の全数)

| 利用箇所 | 影響 |
|---|---|
| `BillingRetentionPurgeResultDto::isPublicationReady()` | 定義変更で**意味が正しくなる** (退会組織の明細が残る限り false、繰越行では false にならない) |
| `BillingRetentionPurgeResultDto::dryRun()` | 変更なし (`expiredRemaining = candidates`) |
| `PurgeBillingRetentionCommand` の `remaining=` 行 / `合計:` 行 / `horizon:` 行 / 終了コード | 変更なし (DTO の値をそのまま出す) |
| `tests/Feature/Billing/BillingRetentionHorizonTest.php` (`isPublicationReady()` の突合) | 変更なし。**新定義で通る**ことを回帰として確認する |
| `tests/Feature/Billing/BillingRetentionPurgeTest.php` (`ticket_ledger_entry: expired=2 processed=2` / `horizon: OK`) | 変更なし。fixture の 2 行はどちらも明細なので期待値が一致する |
| `docs/billing-retention-runbook.md` §監視 / §3 の件数表 | 施策 9 で参照を追記 |

### テスト計画

- 施策 7 のテストで「畳み込み後に `countExpired()` が 0 かつ繰越行が実在する」
  「繰越行以外の適格行が 1 行あれば 0 にならない」の 2 本を固定する。
- 既存の `BillingRetentionHorizonTest` / `BillingRetentionPurgeTest` を**無変更で緑**にする
  (変更が要るなら定義がまだ間違っている、という判定に使う)。

### リスク

- 語の意味を「集約レコードを除く」へ一般化したので、将来ほかの target が集約レコードを
  持ったときに**この定義を読んで分類する**必要がある。docblock にその義務を書く。

---

## 施策 4: `carried_forward_through` の撤去

### 変更箇所

- 新規: `database/migrations/2026_08_24_XXXXXX_drop_carried_forward_through_from_ticket_ledger_entries.php`
- `app/Models/Billing/TicketLedgerEntry.php`: `@property` 行の削除 / `casts()` の 1 行削除 / docblock の説明差し替え
- `tests/Support/InitialState/NullableStateColumnRegistry.php`: L348-353 の entry 削除
- `tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php`: `NULL_INITIAL_STATE_COLUMN_COUNT` を **61 → 60**

### migration

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 繰越行の集約終端を表す専用列 `carried_forward_through` を落とす。
 *
 * 正典 v1 (二段判定・収束繰越形) では**繰越行の `created_at` が集約の基準時刻**であり
 * (畳み込んだ行の最大 `created_at`)、集約単位ごとに 1 行へ収束するため、
 * 終端を別列で単調前進させる必要が無くなった。書き手のいない列を残さない
 * (AGENTS.md 思考原則 3「後方互換の並走を残さない」)。
 *
 * ★**列を足した migration (2026_08_10_114500) は消さない**。消すと新規環境で
 *   この drop が失敗する (schema の歴史は歴史として残す)。
 * ★`down()` は列を戻すだけで**値は復元しない** (繰越行の終端は新形では別の意味を持つため、
 *   復元すると嘘の値になる)。この非対称を docblock に明記する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
            $table->dropColumn('carried_forward_through');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
            // 値は復元しない (旧形の意味を持つ値を作れないため、すべて null で戻す)
            $table->timestamp('carried_forward_through')->nullable()->after('expires_at');
        });
    }
};
```

### 波及変更

- `NullInitialStateColumnClassificationTest` の NI-1 / NI-2 (実スキーマとの両方向突合) は
  列が両側から消えるので整合する。`NULL_INITIAL_STATE_MARKER_COLUMNS` /
  `NULL_INITIAL_STATE_UNDECIDED_COLUMNS` には**含まれていない** (区分は `setAtCreation`) ので
  一覧の pin は変更不要。**件数 pin だけを 61 → 60 にする**。
- `docs/architecture.md` L2379 の「(`carried_forward_through` に集約期間の終端だけを持つ)」を
  施策 9 で差し替える。
- **dev DB への破壊操作はしない** (禁止事項 3)。`php artisan migrate` の実行は
  実装フェーズの通常手順 (worktree のテスト DB は `RefreshDatabase` が作る)。

### PHPStan 適合チェック

- [x] `@property CarbonImmutable|null $carried_forward_through` を消すので、
      残った参照があれば level 10 が落とす (= 撤去漏れの検出器になる)

### テスト計画

- 先に `NullInitialStateColumnClassificationTest` を**赤にする** (registry から entry を消し、
  件数 pin を 60 にした時点で、スキーマにまだ列がある = 両方向突合が落ちる)。
  その赤を migration で緑にする = テストファーストの順序。
- `TicketLedgerCarryForwardTest` の `carried_forward_through` 参照 4 箇所を削除
  (施策 7 の対応表を参照)。

### リスク

- 既存環境 (dev / 将来の production) には列が存在するので、**migration を当てる前に
  新コードを動かすと `Undefined column` にはならない** (新コードはこの列を書かない・読まない)。
  つまりデプロイ順序の制約は無い。逆順 (migration 先行) でも新旧どちらのコードも動く。
  この「順序制約が無い」ことを migration の docblock に書く。

---

## 施策 5: 変更サイトの静的ゲート新設

### 変更箇所 (すべて新規)

- `tests/Support/Architecture/TicketLedgerMutationScanner.php` — 走査器 (純関数)
- `tests/Support/Architecture/TicketLedgerMutationInventory.php` — 目録 (定数と読み口だけ)
- `tests/Architecture/TicketLedgerMutationSiteGateTest.php` — gate
- `tests/Unit/Architecture/TicketLedgerMutationScannerTest.php` — 走査器の自己検査

### ★命名衝突の回避 (これを外すと Architecture レーン全体が落ちる)

既存の `tests/Architecture/TicketLedgerReaderInventoryTest.php` は
**グローバル定数 `TICKET_LEDGER_TABLE` / `TICKET_LEDGER_MODEL_IDENTIFIER` と
グローバル関数 `ticketLedgerScanFiles()` を宣言している**。Pest は同一プロセスで
テストファイルを読み込むので、新 gate が同名の定数・関数を宣言すると
**`Cannot redeclare` で致命的に落ちる**。よって新 gate は
**グローバル定数・グローバル関数を 1 つも宣言しない** — 目録と走査器は
`Tests\Support\Architecture\` のクラス定数 / static メソッドに置く
(`DirectFetchInventory` / `LedgerPins` と同じ作法)。

### 走査器の契約 (docblock の骨子)

```
走査対象:
  - 母集団は `Tests\Support\TrackedPhpSourceFiles::all(base_path())` のうち
    `app/` 直下に居る `*.php` (git 追跡下)。**同じ列挙を 2 本持たない**
  - トークン化は `Tests\Support\Architecture\ArchTokenStream::significantTokens()`
    (`TOKEN_PARSE` + `ParseError` → 例外)。**解析できない入力は無言で空にせず落とす**
  - モデル参照は 2 つの判定の**和**である (拾いすぎ側 = fail-closed)
      (i) `Tests\Support\PhpReferenceScanner::references()` が返す site のうち
          `name` が `App\Models\Billing\TicketLedgerEntry` に一致する
          (NameReference / Construction / StaticCall)、または `receiver` が同 FQCN に解決される
      (ii) 正規化トークン列に短名 `TicketLedgerEntry` が `T_STRING` として現れる
  - 表名リテラルは `T_CONSTANT_ENCAPSED_STRING` の引用符を外した値が
    `ticket_ledger_entries` に**完全一致**する出現の数
  - 変更語彙 / 削除語彙は「識別子 + 直後が `(`」かつ「直前が `function` でない」位置の数
    (区切りの宣言: 判定はトークン単位の完全一致であり、部分文字列一致に頼らない)
  - `withTrashed(` / `onlyTrashed(` は同じ規則で数え、静的呼び出し形のときは受け手の FQCN も見る

保証しないもの (誇張しない):
  1. **呼び出し側に表名・共通 helper 側に削除語彙という「分離」は検出できない**
  2. 定数・列挙型・変数を経由した表名 (`DB::table(self::TABLE)`) は追えない
  3. 可変メソッド名 (`$row->{$verb}()`) / repository / service 境界を越える削除は追えない
  4. 到達解析は行わない (到達不能なコードの語彙も数える)
  5. **真の並行実行での排他の実効性は見ない** (見るのはトークン順の構造まで)
  6. 受け手が完全に動的で、ファイル内にモデルの短名も表名リテラルも現れない形は検出しない
  したがって本 gate は「**対象構文の範囲で**、モデル参照または表名リテラルと変更語彙が
  同一ファイルに現れる変更サイトを deny-by-default で固定する」ものであり、
  **変更経路の全数性は主張しない**。
```

### 目録 (`TicketLedgerMutationInventory`) の初期値 — 実測に基づく

```php
/** 畳み込みサービス (append-only の唯一の例外)。 */
public const string CARRY_FORWARD_FILE = 'app/Services/Billing/Retention/TicketLedgerCarryForwardService.php';

/** 台帳の書き込み窓口。 */
public const string LEDGER_SERVICE_FILE = 'app/Services/Billing/TicketLedgerService.php';

/** 表名リテラルを持ってよいファイル => {count, reason} (全数申告 + 件数完全一致)。 */
public static function tableLiteralSites(): array
{
    return [
        self::CARRY_FORWARD_FILE => [
            'count' => 1,
            'reason' => '畳み込みの集計 (cast を通さないクエリビルダ) の対象表。集計を 1 文で取るため表名を直に書く',
        ],
        self::LEDGER_SERVICE_FILE => [
            'count' => 2,
            'reason' => '冪等 insert (insertOrIgnore) と payment_intent_id の backfill UPDATE。どちらも caster を通さない',
        ],
    ];
}

/** モデル参照 + 変更語彙を同居させてよいファイル => {count, reason} (count は**変更語彙の出現数**)。 */
public static function mutationSites(): array
{
    return [
        self::CARRY_FORWARD_FILE => [
            'count' => 3,
            'reason' => '保持期限の決着の唯一の例外経路 (失効済み行と集約キーごとの範囲削除 2 + 繰越行の save 1)',
        ],
        self::LEDGER_SERVICE_FILE => [
            'count' => 7,
            'reason' => '台帳の追記 (appendEntry の save + 冪等 insert) と予約行の状態遷移 (save 4) と backfill の update 1。削除語彙は持たない',
        ],
    ];
}

/** 変更語彙。 @var list<string> */
public const array MUTATION_VERBS = ['save', 'delete', 'truncate', 'insert', 'insertOrIgnore', 'update', 'upsert', 'forceDelete'];

/** 削除語彙。 @var list<string> */
public const array DELETE_VERBS = ['delete', 'truncate', 'forceDelete'];

/** 論理削除の scope を使ってよいファイル => {count, reason}。 */
public static function trashedScopeSites(): array
{
    return [
        self::CARRY_FORWARD_FILE => [
            'count' => 2,
            'reason' => '退会 (論理削除) 済み組織の台帳も保持期限の対象である。組織の列挙と組織行ロックの 2 箇所だけ',
        ],
    ];
}

/** 母集団の下限 (走査根取り違えの補助検出。現在 934 ファイル)。 */
public const int SCAN_FLOOR = 500;

/** 畳み込みのロック順序を見るメソッド名。 */
public const string LOCK_ORDER_METHOD = 'carryForwardOrganization';
```

> **件数は「実測 → 申告」の順で確定させる**。上の値は現行コード + 施策 1 の変更後コードから
> 手計算した見込みであり、実装では**まず gate を赤で走らせて実測を出し、その値を申告する**
> (合わない場合は理由を読んで、コード側が正しいのか申告が正しいのかを判断する)。

### gate の検査 (TLM-1 〜 TLM-7)

| id | 検査 | 落ちるもの |
|---|---|---|
| TLM-1 | 表名リテラルの出現ファイルと件数が目録と**完全一致** | 表名を新しい場所へ書く |
| TLM-2 | モデル参照 + 変更語彙の同居ファイルと件数が目録と**完全一致** | 台帳を変更しうる場所を無申告で足す / 登録済みファイルに 2 本目の変更経路を足す |
| TLM-3 | 削除語彙を持つのは畳み込みサービス 1 ファイルだけ | 業務経路に削除を足す |
| TLM-4 | `withTrashed(` / `onlyTrashed(` の出現ファイルと件数が目録と完全一致。かつ畳み込みサービス内の出現は受け手が `App\Models\Organization` に解決される (静的呼び出し形の場合) | テナント境界を迂回する一般的な主キー取得への転用 / 他モデルの soft-delete 運用の無申告追加 |
| TLM-5 | 畳み込みは**ロックを変更より先に、同じトランザクションの内側**で取る (`LOCK_ORDER_METHOD` の本体に閉じ込めて判定) | ロックの外出し・順序逆転・別メソッドへの逃がし |
| TLM-6 | 目録が陳腐化していない (対象ファイルが実在 / 理由が 30 文字以上) | 残置した幽霊登録 |
| TLM-7 | 空振り検知 (走査ファイル数が `SCAN_FLOOR` 超 / 検出が非空 / 目録が非空) | 走査根の改名・移動で走査が壊れたこと |

### 負例 (gate 内の合成入力。AGENTS.md の「同じ PR で揃える 4 点」の (1))

`LOCK_ORDER_METHOD` のロック順序判定に対して 6 変異をすべて赤にする。

1. ロックがトランザクションの**外**
2. ロックが削除の**後ろ**
3. ロック語彙が**別メソッドにだけ**ある
4. `DB::transaction` ごと**別メソッドへ逃がす** (メソッド本体へ閉じ込めていないと素通りする)
5. 受け手が `DB` ファサード**でない** `transaction(` は数えない
6. コメント・文字列中の `delete(` は数えない

加えて **正例** (現行の畳み込みサービスの実ソース) が緑になること、
**メソッド定義 (`function delete()`) を変更語彙として数えないこと**を固定する。

### 走査器の自己検査 (`tests/Unit/Architecture/TicketLedgerMutationScannerTest.php`)

- 表名リテラル: 完全一致だけを数える (`ticket_ledger_entries_backup` は数えない)
- 変更語彙: 接頭辞つき (`presave(`) / 打ち消しつき (`unsave(`) / 接尾辞つき (`saveAll(`) の
  **3 形を数えない**こと ((e) の負例 3 形)
- モデル参照: 別名つき import (`use ... as Ledger;` + `Ledger::query()`) を**解決して拾う** /
  同名の別クラス (`use Other\TicketLedgerEntry;`) は**短名一致の側で拾う** (拾いすぎ側)
- トークン化できない入力 (壊れた PHP) で**例外**になること ((b))
- メソッド本体の範囲取得が入れ子の波括弧・文字列補間で崩れないこと

### PHPStan 適合チェック

- [x] 走査器・目録は `final` + `private function __construct()` (純関数 / 定数の置き場)
- [x] 配列 shape を PHPDoc で宣言 (`array<string, array{count: int, reason: string}>` /
      `list<array{id: int|null, text: string, line: int}>`)
- [x] `ReceiverName` は `isResolved()` / `isUnresolved()` で分岐する (`fqcn()` を素で呼ばない)

### リスク

- **既存 gate との定数衝突** (上述)。実装の最初に `grep -rn "TICKET_LEDGER" tests/` で確認する。
- 件数 pin は変更のたびに書き換えが要る。それが目的 (レビューに見える) なので緩めない。

---

## 施策 6: 読み手の目録の追随

### 変更箇所

`tests/Architecture/TicketLedgerReaderInventoryTest.php`

1. `TICKET_LEDGER_READER_INVENTORY` のキーを
   `'Services/Billing/TicketLedgerCarryForwardService.php'` →
   `'Services/Billing/Retention/TicketLedgerCarryForwardService.php'` へ変更
   (読み方 `row_detail` と根拠はそのまま。文言だけ「二段判定」に合わせて更新)
2. `TICKET_LEDGER_COLUMN_SCAN_DIRS` に **`'DataTransferObjects/Billing'`** を追加
3. `TICKET_LEDGER_READER_INVENTORY` に
   `'DataTransferObjects/Billing/CarryForwardGroup.php' => ['aggregate', '畳み込みの集約結果の境界型。列名リテラル (source / expires_at) で生の集計行を型へ確定させるだけで個別行は読まない']` を追加

### 根拠 (走査域を広げる判断)

- `app/DataTransferObjects/` を実 grep した結果、`'source'` / `'expires_at'` / `'delta'` の
  リテラルを持つのは `FxSnapshotDto.php` (直下) / `Inquiry/` / `Admin/` / `Invitations/` で、
  **`DataTransferObjects/Billing/` には 1 件も無い**。よって走査域を
  `DataTransferObjects/Billing` に広げても**巻き添えは `CarryForwardGroup` だけ**であり、
  信号は死なない (入口 4 の走査域を絞っている理由と両立する)。
- 逆に広げないと、台帳の列名を持つ新しいファイルが**目録の外**に生まれる。
  「読んでいる場所を宣言なしに増やせない」という目録の目的から外れる。

### 波及変更

- 検査 3 の件数は `count(TICKET_LEDGER_READER_INVENTORY)` で自動追随する (定数の書き換え不要)。
- 検査 7 (フロントに台帳 kind の型が無いこと) / 検査 8 (kind の件数 6) は**変更なし**
  (kind は増やさない)。

### テスト計画

- 施策 1 の移設をした時点で検査 1 が**赤になる** (missing + phantom)。それを目録の更新で緑にする。
- 施策 2 の DTO 追加をした時点で、走査域を広げるまでは検出されない。
  **走査域の追加を先に入れて赤を確認**してから登録を足す (テストファースト)。

### リスク

- `DataTransferObjects/Billing` に将来 `'source'` を使う別 DTO が来ると登録が要る。
  それは目録の意図どおりである。

---

## 施策 7: 挙動テストの書き換え (テストファーストの起点)

### 変更箇所

`tests/Feature/Billing/TicketLedgerCarryForwardTest.php` (全面改訂)

### 旧テスト → 新テストの対応表 (**対応先の無い削除を 1 件も作らない**)

| 旧テスト (v0) | 守っていた不変条件 | v1 での扱い |
|---|---|---|
| 検証 1〜4・7: 畳み込み前後で残高が 1 枚も変わらない | 残高保存 (組織 / source / 失効時刻の粒度) | **維持**。ただし観測関数 `ledgerBalanceByGroup()` を「**寄与する行だけ**の群 SUM」へ定義変更する (失効済みの群は v1 で消えるのが正しい挙動であり、生の全行 SUM の一致は v1 の要求と矛盾する)。`ledgerBalancesByOrganization()` (`balance()` / `availableTrueBalance()`) は**そのまま**一致を要求する |
| 検証 5: 消費の出所と失効境界の選択が変わらない | 消費順序の保存 | **維持** (無変更) |
| 繰越行は残高の粒度 3 つだけを引き継ぎ取引追跡情報を残さない | 明細の非引き継ぎ | **強化**。`carried_forward_through` の assert を削除し、代わりに**列分類検査 5 条** (下記) へ置き換える。`created_at` の assert は「実行時刻より後」→「**畳み込んだ行の最大 created_at と一致**」へ差し替え |
| group key は 3 つで組織を跨いで合算しない | 組織跨ぎ禁止 | **維持** (無変更) |
| source が null の legacy 行は独立した group | legacy の独立扱い | **維持** (無変更) |
| 合計 0 の group は繰越行を作らない | 0 行を作らない | **維持** (`processed` の期待値のみ確認) |
| **冪等キーは group と閾値で決まり再実行で同じ値になる** | 二重の繰越行を作らない | **削除**。機構ごと撤去する。**引き継ぐ新テスト**: 「同じ閾値で 2 回実行しても繰越行は 1 行のまま増えない (収束)」+ 「繰越行の `idempotency_key` は null」。二重防止は「同一トランザクション内の削除 → 追記」と組織行ロックが構造で担う |
| **繰越行はさらに畳み込める (`carried_forward_through` が単調に進む)** | 再畳み込みで終端が後退しない | **削除**。列ごと撤去する。**引き継ぐ新テスト**: 「既存の繰越行 + 後から入った古い明細が **1 行へ合算**される (delta が合計になり、`created_at` が最大値になる)」 |
| **閾値が過去へ戻っても `carried_forward_through` は後退しない (単調性)** | 集約済み範囲の過小申告を防ぐ | **削除**。v1 は集約範囲を列で表さないので概念が消える。**引き継ぐ新テスト**: 「保持年数を延ばして閾値が過去へ動いても、残高が保存され繰越行が増えない」(守りたかった実害 = 集約の二重計上・行の増殖を直接見る) |
| 畳み込み済み group に古い行が後から入ったら fail-closed | 残高消失の防止 | **挙動が変わる**。v1 は繰越行も同じ集約キーの削除対象に含めるので、**fail-closed ではなく 1 行へ合算**される (改善)。テストを「合算される」へ書き換え、v0 の期待値 (`unexpectedFailures = 1`) は**この経路では消える**。fail-closed の経路は下の割り込みテストが引き続き担う |
| 集計の後に古い行が割り込んだら fail-closed | 件数一致検査 | **維持 (差し込み点を変える)**。v1 は「削除 → 追記」の順なので、`insert` を観測して差し込むと**件数照合の後**になる。差し込み点を**集約 SELECT の観測時** (`delta_sum` を含む SQL) に変え、集計と削除の間の窓を再現する |
| 新しい取引 (閾値より後) は 1 行も畳み込まれない | 第 1 段の適格性 | **維持** (無変更) |
| 境界: `created_at` が閾値ちょうどの行は畳み込まれる | 境界包含 | **維持** (無変更) |
| 検証 6: signup grant の org 生涯 1 回は marker が守る | marker が正本 | **維持** (無変更。期限切れ monthly なので v1 では物理削除されるが assert は「台帳から消える」なので通る) |
| [既知窓] 合計 0 の未失効 monthly group は失効境界の情報を失う | 既知窓の明示 | **維持** (無変更) |

### 新規テスト (v1 の要求を直接固定する)

| # | テスト | 検証内容 |
|---|---|---|
| N1 | 失効済みの明細は繰越に含めず物理削除される | 期限以前 + `expires_at <= now` の行が消え、その群の繰越行が**作られない**。`balance()` / `availableTrueBalance()` は不変 |
| N2 | 繰越行の `created_at` は畳み込んだ行の**最大 `created_at`** である | 3 行 (created_at が異なる) を畳み込み、繰越行の `created_at` が最大値と一致 |
| N3 | **収束**: 同じ閾値で 2 回実行しても繰越行は増えない | 2 回目の `processed` が 0、行数と id が不変 (短絡が効いている) |
| N4 | **有界性**: 失効済みの窓を N 個置いても畳み込み後の行数が N に依存しない | N=1 と N=5 で畳み込み後の行数が同じ (未失効の群 + 無期限の群のみ) |
| N5 | 既存の繰越行 + 後から入った古い明細は 1 行へ合算される | delta が合計、`created_at` が最大、行数 1 |
| N6 | 閾値が過去へ動いても残高が保存され繰越行が増えない | 旧「単調性」テストの引き継ぎ |
| N7 | 合計が int4 上限ちょうど (2147483647) なら畳み込める | `unexpectedFailures = 0`、繰越行の delta が上限値 |
| N8 | 合計が int4 上限 +1 なら**その組織だけ**巻き戻る | `unexpectedFailures = 1` / `processed = 0` / 台帳の行が 1 行も消えていない / **他の組織は処理される** |
| N9 | 合計が int4 下限 −1 でも同じ (負側) | 同上 |
| N10 | 集計と削除の間に古い明細が割り込んだら fail-closed | `unexpectedFailures = 1`、元の残高が 1 枚も減っていない |
| N11 | **繰越行の列分類 5 条** | (1) `kind` が厳密に `carry_forward` (2) `description` が `DESCRIPTION` と厳密一致 (3) `NULL_COLUMNS` の全列が NULL (4) `VALUED_COLUMNS ∪ NULL_COLUMNS` が**実スキーマの全列と完全一致** (5) 列を足したら未分類として失敗する |
| N12 | 論理削除済み (退会済み) 組織の明細も畳み込まれる | `$organization->delete()` (soft) 後に畳み込み、明細が消え繰越行ができる |
| N13 | 論理削除済み組織でも残高が保存される | `balance()` が畳み込み前後で一致 |
| N14 | 論理削除済み組織の期限超過明細は `expiredRemaining` に現れ、畳み込み後に 0 になる | v0 のバグ (永久に 0 にならない) の回帰 |
| N15 | 畳み込み後に `countExpired()` が 0 かつ繰越行が実在する | `expiredRemaining` の定義の固定 |
| N16 | 繰越行以外の適格行が 1 行あれば `countExpired()` は 0 でない | 同上 (逆方向) |
| N17 | 1 組織の失敗が他の組織を止めない | N8 と組み合わせて 2 組織で確認 |

### 実スキーマの列一覧の取り方 (N11 の (4))

```php
$columns = Schema::getColumnListing('ticket_ledger_entries');
sort($columns);
$declared = array_merge(
    TicketLedgerCarryForwardService::VALUED_COLUMNS,
    TicketLedgerCarryForwardService::NULL_COLUMNS,
);
sort($declared);
expect($declared)->toBe($columns, '表に列を足したら繰越行での扱い (値を持つ / 必ず NULL) を分類してください');
```

### PHPStan / 規約適合チェック

- [x] fixture は Factory 経由 (`TicketLedgerEntry::factory()` / `createOrganizationWithOwner()`)
- [x] 個別の `DatabaseTransactions` を使わない (`RefreshDatabase` はグローバル適用)
- [x] `BillingRetention::threshold()` を使い続ける
      (`BillingRetentionConfigSingleSourceTest` の `BILLING_RETENTION_CALLERS` に
      本ファイルが**既に登録済み**なので、同ファイル = 採用時債務パスを**触らない**)
- [x] 年月の加減算は `*NoOverflow` を使う (`CarbonOverflowArithmeticGateTest`)
- [x] int4 境界の fixture は `->delta(2147483647)` などで Factory の state を使う

### リスク

- **N8 / N9 の fixture 作成**: 合計を int4 超にするには 2 行以上必要
  (1 行の `delta` は int4 に収まる必要がある)。`delta(2147483647)` + `delta(1)` で
  合計 2147483648 を作る。**同じ集約キー**に置くこと。
- **N10 の差し込み点**: `DB::listen` は実行後に発火するので、集約 SELECT を観測した直後に
  差し込めば削除より前になる。SQL の判定文字列は `delta_sum` を使う
  (`insert into` を見る v0 の判定では**窓を再現できない**)。
- **N3 の「id が不変」**: 短絡が効いていることの証拠として使う (id が変われば入れ替えが起きている)。

---

## 施策 8: DTO の単体テスト

### 変更箇所

- 新規: `tests/Unit/Billing/CarryForwardGroupTest.php`

### テスト計画

| # | 入力 | 期待 |
|---|---|---|
| 1 | `delta_sum` が `int` の正常値 | そのまま採る |
| 2 | `delta_sum = '2147483647'` / `'-2147483648'` | 通る (境界ちょうど) |
| 3 | `delta_sum = '2147483648'` / `'-2147483649'` | 例外 (境界 +1) |
| 4 | `delta_sum = '9223372036854775808000'` (PHP 整数範囲超) | 例外 (**キャスト前に落ちる**) |
| 5 | `delta_sum = '1e5'` / `'1.5'` / `''` / `' 1'` / `'-'` | すべて例外 (10 進整数の表記でない) |
| 6 | `delta_sum = true` / `1.5` (float) | 例外 (int でも文字列でもない) |
| 7 | `delta_sum = '-0'` / `'000'` | 0 として通る |
| 8 | `source = null` | `source` は `null` のまま |
| 9 | `source = 'monthly'` | `TicketSource::Monthly` |
| 10 | `source = 'unknown'` | 例外 (未知の出所) |
| 11 | `source = 1` (非文字列) | 例外 |
| 12 | `expires_at = null` | `expiresAt` は `null` |
| 13 | `expires_at` が文字列 / `DateTimeImmutable` | どちらも `CarbonImmutable` |
| 14 | `max_created_at = null` | 例外 (集約の基準時刻は必須) |
| 15 | `row_count = '3'` / `carry_forward_rows = '0'` | 整数へ確定 |
| 16 | `row_count = -1` | 例外 (非負整数でない) |
| 17 | 列の欠落 (`delta_sum` が無い) | 例外 (`propertyExists`) |
| 18 | 余剰列がある | **例外にしない** (通る) |

---

## 施策 9: 規約・文書の追随

### 9-a. `AGENTS.md` ドメイン固有規約の追加 (規約 21)

`## ドメイン固有規約` の末尾へ 1 項追加する (`AGENTS.md` は
`docs/template-fingerprints.json` のキーに**無い** = テンプレート共有ファイルではないので
逸脱登録は要らない)。

```
21. **追記専用チケット台帳の変更サイトの目録 (家系の正典 v1)**: `ticket_ledger_entries` は
    delta 型の追記専用台帳で、残高は行の合計である。モデルは `updating` / `deleting` を
    例外化しているが、**Eloquent の一括削除はモデルイベントを発火しない**。よって
    表名リテラルを持つファイル / 台帳モデル参照と変更語彙を同居させるファイル /
    論理削除の scope を使うファイルを、`Tests\Support\Architecture\TicketLedgerMutationInventory`
    へ**件数まで全数申告**する (`TicketLedgerMutationSiteGateTest` が deny-by-default で強制)。
    - **保持期限の決着は削除ではなく畳み込み**である。判定は 2 段 —
      第 1 段 (適格性 = `created_at <= 閾値`) を満たさない行は 1 行も触らず、
      第 2 段 (寄与判定) で失効済みは物理削除・寄与する行は
      `(組織, 出所, 失効時刻)` ごとに合算した繰越 1 行へ畳み込む。
      **繰越行の `created_at` は畳み込んだ行の最大 `created_at`** であり実行時刻ではない
      (実行時刻にすると繰越行が実行のたびに増える)。
    - **`append-only` の例外は畳み込み 1 ファイルだけ**である。これは**人間向けの規約**であり、
      gate が証明するのは「対象構文の範囲で無申告の変更サイトを増やせない」ことまでである
      (呼び出し側と共通処理側で語彙が分かれる形は検出できない)。
    - **保持期限の母集団は論理削除済み組織も含む**。`Organization` は `SoftDeletes` なので
      global scope の効く経路で組織を列挙すると退会済み組織の台帳が永久に畳まれない。
    - **保証しないものの正本は gate の docblock** であり、本書には写さない。
      運用の説明は `docs/architecture.md` §課金記録の保持期間、
      畳み込みで失われるものは `docs/billing-retention-runbook.md` §7。
```

### 9-b. `docs/architecture.md`

- 「**台帳 (`ticket_ledger_entries`) だけ方式が違う理由**」の段を二段判定・収束繰越形へ差し替える。
  - `carried_forward_through` の記述を削除し、「繰越行の `created_at` = 集約の基準時刻」へ
  - 「失効済みの行は繰越に含めず物理削除する (= 繰越行の有界化)」を追記
  - 「母集団は論理削除済み組織も含む」を追記
  - `App\Services\Billing\TicketLedgerCarryForwardService` → `App\Services\Billing\Retention\...`
  - `candidates` / `expiredRemaining` の語は **DTO の docblock が正本**である旨の参照を置く
- 「台帳を読む場所は目録制」の段へ「**書き込む場所も目録制**
  (`TicketLedgerMutationSiteGateTest`)」を 1 文追記する。

### 9-c. `docs/billing-retention-runbook.md`

- §3 の件数表の `processed` / `remaining` の説明に DTO の共通定義への参照を追記。
- §7 (畳み込みで失われるもの) に「**失効済みの明細は繰越にも残らず物理削除される**」を追記。
- **新節: 申し送り (繰越行の保持分類)**
  - 技術設計上の分類: 繰越行は取引関係書類ではなく「継続中の契約に紐づく現在残高」
  - 機械で固定しているもの: 列分類 5 条 (明細を持たないこと) / 収束 / 有界性
  - 機械で固定していないもの: **法的分類**
  - **確認事項 4 点** (「契約終了」と `Organization` の削除のタイミング差 /
    契約終了後も `Organization` を残す場合の繰越行の扱い /
    集約済みの `delta` `source` `expires_at` `created_at` が「取引関係書類等」に当たるか /
    契約終了後に残高を保持する必要があるか)
  - **実態**: `Organization` は `SoftDeletes` で `app/` に `forceDelete` は無いので、
    繰越行は退会後も残る (D23 の設計どおり)。`/privacy` の文面は本 PR では変更しない
  - **再判定条件**: 法務が台帳の行そのものを取引関係書類と判定したとき /
    繰越行へ取引情報を載せる要件が出たとき
  - **許容されない場合の退路**: 残高を台帳とは別の表で持つ再設計 (本 feature の射程外)

---

## テストファースト手順 (どのテストを先に赤くするか)

| 段 | 操作 | 期待する赤 |
|---|---|---|
| 0 | `grep -rn "TICKET_LEDGER" tests/` で既存のグローバル定数・関数名を確認する | (赤ではなく前提確認) |
| 1 | `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` に **N1 / N2 / N3 / N11 / N12 / N14** を追加する (v0 のコードのまま) | N1 (失効済みが消えない) / N2 (`created_at` が now) / N3 (2 回目で行が増える or 短絡が無い) / N11 (`carried_forward_through` が未分類 + `idempotency_key` が非 null) / N12・N14 (退会組織が畳まれない) が赤 |
| 2 | `tests/Unit/Billing/CarryForwardGroupTest.php` を追加する | クラスが無いので赤 |
| 3 | `tests/Support/InitialState/NullableStateColumnRegistry.php` の entry を削除し件数 pin を 60 にする | `NullInitialStateColumnClassificationTest` NI-1/NI-2 (実スキーマに列が残っている) が赤 |
| 4 | 施策 2 (`CarryForwardGroup`) を実装 | 段 2 が緑になる |
| 5 | 施策 1 (サービスの差し替え + 移設) を実装 | 段 1 が緑になる。`TicketLedgerReaderInventoryTest` 検査 1 が**新たに赤** (missing/phantom) |
| 6 | 施策 6 (読み手の目録の追随) — まず走査域に `DataTransferObjects/Billing` を足して赤 (`CarryForwardGroup` が未登録) を確認し、その後に登録を足す | 検査 1 / 検査 3 が緑になる |
| 7 | 施策 4 の migration を追加 | 段 3 が緑になる |
| 8 | 施策 5 の走査器 → 自己検査 (`tests/Unit/Architecture/`) を先に赤で置き、走査器を実装して緑にする | 走査器の負例が先に赤 |
| 9 | 施策 5 の gate を**目録の件数を空 / 0 のまま**置いて赤にし、**実測値を読んで申告**する | TLM-1〜4 が実測との不一致で赤 → 申告して緑 |
| 10 | 施策 7 の残り (旧テストの置き換え) を反映 | 旧 3 本の削除と引き継ぎ先の追加で緑 |
| 11 | 施策 3 / 9 (docblock・文書) を反映 | `BillingRetentionHorizonTest` / `BillingRetentionPurgeTest` が**無変更で緑**であることを確認 |
| 12 | `composer phpstan` / `vendor/bin/pint --test` / `composer test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` | 全 green |

> **mutation 根拠の再取得**: T144 は `devnotes/20260810-1143-todo-T144/mutation-evidence.md` に
> 変異表 (MU1〜MU8) を残している。v1 では MU8 (`carried_forward_through` の単調性) が
> **概念ごと消える**ので、`devnotes/{本ディレクトリ}/mutation-evidence.md` に
> **v1 用の変異表を作り直す** (最低限: 第 2 段の述語を落とす / `created_at` を now に戻す /
> 短絡を外す / 範囲検査を外す / 件数照合を外す / `withTrashed()` を外す の 6 変異が
> それぞれどのテストで赤になるかを実測して記録する)。

## migration / 後方互換の扱い

- **並走を残さない**: `carried_forward_through` 列 / 単調前進ロジック / 繰越行の冪等キー /
  旧サービスのファイルを**すべて同じ PR で消す**。feature flag も設定分岐も置かない。
- **schema の歴史は残す**: 列を足した migration (2026_08_10_114500) は消さず、
  drop migration を新規に足す (消すと新規環境で drop が失敗する)。
- **デプロイ順序の制約は無い**: 新コードはこの列を読まず書かないので、
  コード先行でも migration 先行でも動く。この事実を migration の docblock に書く。
- **dev DB への破壊操作はしない** (禁止事項 3)。`migrate:fresh` 等はエージェント判断で実行しない。

## docs/template-divergence.md の登録/更新/削除の要否 (乖離台帳の確認段)

**結論: 登録の追加・更新・削除は不要。`LedgerPins` の 3 定数も変更しない。**

判定の根拠 (`app-design` スキル Phase 3-0 の手順どおり):

1. **共有ファイルかどうか**: 変更対象パスを `docs/template-fingerprints.json` の
   `entries` のキーと突き合わせた結果、**1 件も該当しない**
   (`app/Services/Billing/**` / `app/DataTransferObjects/Billing/**` /
   `app/Models/Billing/TicketLedgerEntry.php` / `tests/Architecture/TicketLedger*` /
   `tests/Support/InitialState/**` / `tests/Feature/InitialState/**` /
   `tests/Support/Architecture/TicketLedgerMutation*` / `database/migrations/**` /
   `docs/architecture.md` / `docs/billing-retention-runbook.md` / `AGENTS.md` の全件)。
   したがって指紋台帳との突合 (`TemplateDivergenceFingerprintTest`) は発火しない。
2. **採用時債務一覧 (`adoption-debt.tsv`)**: 課金保持に関わる債務パスは
   `tests/Architecture/BillingRetentionConfigSingleSourceTest.php` /
   `tests/Architecture/BillingRetentionTargetInventoryTest.php` の 2 件だが、
   **どちらも変更しない**。
   - 前者の `BILLING_RETENTION_CALLERS` は
     `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` を**既に含む**。
     書き換え後も同ファイルが `BillingRetention::threshold()` を使い続けるので**編集不要**。
   - 後者の on-disk 走査は `BillingRetentionPurger` を**実装するクラスだけ**を拾うので、
     `app/Services/Billing/Retention/` へ非 purger (畳み込みサービス) を置いても
     母集団に入らず**編集不要** (実読で確認)。
   → `mutatedDebtPaths` は発火しない。
3. **テンプレートに無い領域への上積みか**: 本件は**テンプレート正典の形へ収束させる変更**
   (置き場・二段判定・境界 DTO・変更サイト gate はいずれも正典側に実在する) であり、
   逸脱を増やす変更ではない。よって「登録するか迷ったら登録する」の対象にもならない。
4. **既存の D23 (課金記録は退会後も 7 年保持)**: 対象パスに畳み込みサービスは**含まれていない**。
   本 PR で D23 の対象パスは変わらないので、**登録の更新は不要**
   (対象パスの重複禁止の制約にも触れない)。ただし
   **D23 の「揃え続ける不変条件」が実装で守られていなかった** (退会組織が畳まれない) ことを
   施策 7 の N12〜N14 が新たに機械で受けるので、その事実は runbook の申し送りに書く。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) migration を含み schema を変える (2) Architecture gate を新設し既存 gate 2 本の目録を書き換える (3) 挙動テストを全面改訂する — いずれも「途中まで入った状態」で他作業と混ぜるとテストレーンが赤のまま長く残る。`docs/TODO.md` の Open は T249 (起動 probe の共通 runner 一元化) だけで、課金・台帳・保持期限のいずれにも触れないため**衝突は無い**が、独立した worktree で最後まで通してからマージする |
| 競合リスク | 低。ただし `AGENTS.md` / `docs/architecture.md` は他 TODO も触りうるので、マージ時の衝突は文書側で発生しうる (コードの衝突は無い) |

## 残るリスクと監視

| リスク | 扱い |
|---|---|
| 法的分類 (繰越行 = 残高) が覆る | runbook の申し送りに再判定条件を書く。覆ったら残高を別表へ持つ再設計 (射程外) |
| `Organization::withTrashed()` が larastan で解決できない | `Organization::query()->withTrashed()` へ書き換える。**型を緩めない** |
| 静的 gate が呼び出し側と共通処理側の語彙分離を検出できない | docblock で明記し、主張をその範囲へ狭める。取りこぼしは PR レビューの義務 |
| 真の並行実行での排他の実効性 | 測らない (構造の pin まで)。既存の方針を変えない |
| `processed` の意味が v0 と変わる | runbook の件数表に「削除した行数」であることを明記 |

---

## 関連する現行コード

### app/Services/Billing/TicketLedgerCarryForwardService.php (現行 v0 / 全文)

```php
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Enums\Billing\BillingRetentionTarget;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * チケット台帳 (`ticket_ledger_entries`) の**保持期間 (7 年) の畳み込み**。
 *
 * 台帳は append-only の残高の真実源であり、古い行を物理削除すると**残高が変わる**。
 * よって保持期間の決着は削除ではなく畳み込み — 保持期限より古い行を
 * `(organization_id, source, expires_at)` の組ごとに合算し、合計 `delta` を持つ
 * **繰越行 1 行**へ置換する。
 *
 * ★**`organization_id` を group key に必ず含める**。含め忘れると組織を跨いで残高を
 *   合算する重大バグになる。残高の粒度が実際にこの 3 つで閉じることは
 *   {@see TicketLedgerService::balance()} の集計条件 (organization_id + source
 *   (purchased は `source IS NULL` も含む) + `expires_at IS NULL or > now`) と対応する。
 *   **`source IS NULL` (legacy 行) は独立した group** として扱う (purchased へ寄せると
 *   `sumActiveHolds` の legacy 除外規則と意味がズレる)。
 *
 * ★**繰越行は「取引記録」ではなく「現在残高のスナップショット」である**。
 *   原取引の識別子 (説明 / stripe id / payment intent / 予約 id / 冪等キー / 個別日時) を
 *   1 つも引き継がない — 引き継いだら「7 年より古い取引の情報が残る」ことになり、
 *   保持期間の意味が消える。引き継ぐのは残高の粒度を決める 3 つ
 *   (`organization_id` / `source` / `expires_at`) だけである。
 *   性質の違いは `kind = carry_forward` として型に出す (既存 kind へ相乗りしない)。
 *
 * ★**append-only 不変条件との関係**: 本サービスは Eloquent の delete guard を迂回する
 *   Query Builder 直書きで行を消す**唯一の**経路である ({@see TicketLedgerService} の
 *   `backfillPaymentIntentId` と同じ閉じ込め方)。「計上の事後改竄をしない」という
 *   append-only の意図は保たれる — 個別行の値を書き換えるのではなく、
 *   **保持期限を超えた区間ごと残高スナップショットへ置換する**操作だからである。
 *
 * ★**保証しないもの (誇張しない)**:
 *   - 畳み込み後は**原取引が復元できない**。返金逆仕訳 (`clawbackPurchasedByPaymentIntent`) /
 *     消費の冪等キー (`consume:{reservationId}`) / signup grant の部分 UNIQUE index は
 *     いずれも**畳み込まれた行に対しては効かなくなる**。7 年より古い決済への遅延返金や
 *     7 年前の予約の commit は現実には起きないが、「index が守っている」と言えるのは
 *     畳み込み前の行までである (signup grant の**正本**は
 *     `organizations.signup_tickets_granted_at` の条件付き UPDATE で、これは畳み込まれない)
 *   - **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` は
 *     台帳から消える。未失効の monthly が完全に消費済みという組み合わせでのみ
 *     `nearestMonthlyExpiry` の探索結果が変わる (残高は不変。既知窓としてテストで固定)
 */
final class TicketLedgerCarryForwardService
{
    /** 繰越行の冪等キーの接頭辞。 */
    public const string IDEMPOTENCY_KEY_PREFIX = 'carry_forward:';

    /**
     * 繰越行の説明。
     *
     * ★詳細設計は `description` も null にすると書いているが、実列は **NOT NULL** である
     *   (`2026_06_11_091400_create_ticket_tables.php` を実読で確認)。列を nullable へ変える
     *   代わりに**取引追跡情報を一切含まない固定文言**を入れる。原取引の説明は残らないため
     *   「個別取引が復元不能」という要件は満たす。
     */
    public const string CARRY_FORWARD_DESCRIPTION = '保持期間の繰越 (残高スナップショット)';

    /** 冪等キー / 集約終端の日時表現 (UTC 正規化)。 */
    private const string KEY_TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** 冪等キーで null を表す明示トークン (空文字との衝突を避ける)。 */
    private const string NULL_TOKEN = 'null';

    /** 起算済み (台帳は `created_at` が起算点) かつ期限超過の行数。 */
    public function countExpired(CarbonImmutable $threshold): int
    {
        return TicketLedgerEntry::query()
            ->where('created_at', '<=', $threshold)
            ->count();
    }

    /**
     * 繰越行の冪等キー。
     *
     * 形は `carry_forward:{orgId}:{source}:{expiresAt}:{threshold}` で固定する。
     * **null は明示トークン `'null'`**、日時は **UTC 正規化**。
     * **同一 group を同じ閾値で再実行すれば同じキーになる** (= UNIQUE が二重の繰越行を弾く)。
     *
     * ★キーの第 4 要素は**その実行の閾値**であって `carried_forward_through` (集約終端) では
     *   ない。両者は普段一致するが、保持年数を延ばして閾値が過去へ動いた場合だけ食い違う
     *   (終端は単調に進むので前回値を保つ)。**冪等の単位は「同じ入力で同じ実行をしたか」**
     *   なので、キーは入力である閾値で決める。
     *
     * 既存の signup grant 部分 UNIQUE index の述語 (`idempotency_key LIKE 'signup_grant:%'`) とは
     * 接頭辞が異なるため衝突しない。
     */
    public static function idempotencyKeyFor(
        int $organizationId,
        ?TicketSource $source,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $threshold,
    ): string {
        return implode(':', [
            rtrim(self::IDEMPOTENCY_KEY_PREFIX, ':'),
            (string) $organizationId,
            $source === null ? self::NULL_TOKEN : $source->value,
            $expiresAt === null ? self::NULL_TOKEN : $expiresAt->utc()->format(self::KEY_TIME_FORMAT),
            $threshold->utc()->format(self::KEY_TIME_FORMAT),
        ]);
    }

    /**
     * 保持期限より古い台帳行を組織ごとに畳み込む。
     *
     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
     */
    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        $candidates = $this->countExpired($threshold);
        $processed = 0;
        $unexpectedFailures = 0;

        foreach ($this->organizationsWithExpiredEntries($threshold) as $organization) {
            try {
                $processed += DB::transaction(
                    fn (): int => $this->carryForwardOrganization($organization, $threshold),
                );
            } catch (Throwable $e) {
                $unexpectedFailures++;
                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
                Log::warning('ticket ledger carry forward failed', [
                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
                    'organization_id' => $organization->getKey(),
                    'error_class' => $e::class,
                ]);
            }
        }

        return new BillingRetentionPurgeResultDto(
            target: BillingRetentionTarget::TicketLedgerEntry,
            candidates: $candidates,
            processed: $processed,
            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
            // (「安全のため残した」ではなく「決着できなかった」である)。
            failClosed: 0,
            unexpectedFailures: $unexpectedFailures,
            expiredRemaining: $this->countExpired($threshold),
        );
    }

    /**
     * 期限超過の台帳行を持つ組織 (id 昇順 = ロック順序の固定)。
     *
     * @return Collection<int, Organization>
     */
    private function organizationsWithExpiredEntries(CarbonImmutable $threshold): Collection
    {
        return Organization::query()
            ->whereHas(
                'ticketLedgerEntries',
                fn (Builder $query): Builder => $query->where('created_at', '<=', $threshold),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * 1 組織ぶんの畳み込み (organizations 行ロック下)。
     *
     * @return int 畳み込んだ (置換で消えた) 行数
     */
    private function carryForwardOrganization(Organization $organization, CarbonImmutable $threshold): int
    {
        // 残高判定・台帳追記の直列化点。reserve / commit と同じロックを取る
        // (畳み込みの最中に同じ組織の残高が動かないようにする)
        Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $organizationId = $organization->getKey();
        if (! is_int($organizationId)) {
            throw new RuntimeException('組織 id が解決できません (畳み込みは中止する)');
        }

        $processed = 0;
        foreach ($this->expiredGroups($organizationId, $threshold) as $group) {
            $processed += $this->carryForwardGroup(
                $organizationId,
                $group->source,
                $group->expires_at,
                $threshold,
            );
        }

        return $processed;
    }

    /**
     * 期限超過行の group key 一覧 (`source` / `expires_at` の相異なる組)。
     *
     * @return Collection<int, TicketLedgerEntry>
     */
    private function expiredGroups(int $organizationId, CarbonImmutable $threshold): Collection
    {
        return TicketLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold)
            ->select(['source', 'expires_at'])
            ->distinct()
            ->get();
    }

    /**
     * 1 group を繰越行へ置換する。
     *
     * @return int 置換で消えた行数
     */
    private function carryForwardGroup(
        int $organizationId,
        ?TicketSource $source,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $threshold,
    ): int {
        // **件数・合計・前回終端は 1 文で取る**。3 回に分けると文ごとに snapshot が変わり
        // (READ COMMITTED)、「合計には入っていないが件数には入っている」行が生まれうる。
        $aggregate = $this->aggregateGroup($organizationId, $source, $expiresAt, $threshold);
        $total = $aggregate['total'];
        $through = $this->resolveThrough($aggregate['previousThrough'], $threshold);

        // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
        if ($total !== 0) {
            $inserted = DB::table('ticket_ledger_entries')->insertOrIgnore([
                'organization_id' => $organizationId,
                'delta' => $total,
                'kind' => TicketLedgerKind::CarryForward->value,
                'source' => $source?->value,
                // --- ここから下は取引追跡情報。繰越行は 1 つも引き継がない ---
                'reservation_id' => null,
                'description' => self::CARRY_FORWARD_DESCRIPTION,
                'granted_at' => null,
                'stripe_checkout_session_id' => null,
                'stripe_invoice_id' => null,
                'payment_intent_id' => null,
                'purchase_amount' => null,
                // --- 残高の粒度と集約終端 ---
                'expires_at' => $expiresAt?->toDateTimeString(),
                'carried_forward_through' => $through->toDateTimeString(),
                'idempotency_key' => self::idempotencyKeyFor($organizationId, $source, $expiresAt, $threshold),
                'created_at' => CarbonImmutable::now()->toDateTimeString(),
            ]);

            // 冪等キーの衝突 = 同一 group を同一閾値で二重に畳み込もうとしている
            // (通常は起きない。同じ閾値の再実行では対象行が既に消えているため)。
            // 起きうるのは「畳み込み済みの group へ、閾値より古い created_at の行が
            // 後から入った」ときで、既存の繰越行へ足し込むには UPDATE が要る。
            // ここで原取引を消すと繰越行 1 行ぶんの残高が失われるため fail-closed で中止する
            // (トランザクションごと巻き戻り、この組織は unexpectedFailures として報告される)。
            if ($inserted !== 1) {
                throw new RuntimeException('繰越行の冪等キーが衝突しました (畳み込みを中止して巻き戻す)');
            }
        }

        // 繰越行の created_at は now (= 閾値より後) なので、この削除の対象にならない
        $deleted = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)->delete();

        // **集計した集合と削除した集合が一致することを確認する**。
        // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
        // ロックを取らない冪等 insert であり、backfill / 取り込みも同様)。集計と削除の間に
        // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を削除が巻き込む** =
        // その枚数ぶん残高が消える。件数の不一致で検出し、トランザクションごと巻き戻す。
        if ($deleted !== $aggregate['rows']) {
            throw new RuntimeException(
                '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
            );
        }

        return $deleted;
    }

    /**
     * group の件数・合計・前回終端を **1 文で** 取る。
     *
     * 分けて発行すると文ごとに snapshot が変わる (READ COMMITTED) ため、
     * 「合計には入っていないが件数には入っている」行が生まれ、残高保存の検査そのものが壊れる。
     *
     * @return array{rows: int, total: int, previousThrough: string|null}
     */
    private function aggregateGroup(
        int $organizationId,
        ?TicketSource $source,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $threshold,
    ): array {
        $row = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)
            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(delta), 0) AS delta_total, MAX(carried_forward_through) AS previous_through')
            ->first();

        if (! $row instanceof stdClass) {
            throw new RuntimeException('台帳 group の集計に失敗しました (畳み込みを中止する)');
        }

        Assert::numeric($row->row_count);
        Assert::numeric($row->delta_total);
        Assert::nullOrString($row->previous_through);

        return [
            'rows' => (int) $row->row_count,
            'total' => (int) $row->delta_total,
            'previousThrough' => $row->previous_through,
        ];
    }

    /**
     * この繰越が集約した期間の終端。
     *
     * 既に繰越行を含む group (再畳み込み) では**前回の終端と今回の閾値の大きい方**を採り、
     * 単調に進むことを保証する (保持年数を延ばすと閾値は過去へ動くため、閾値をそのまま
     * 採ると集約済みの範囲を過小申告することになる)。
     */
    private function resolveThrough(?string $previous, CarbonImmutable $threshold): CarbonImmutable
    {
        if ($previous === null || $previous === '') {
            return $threshold;
        }

        $parsed = CarbonImmutable::parse($previous);

        return $parsed->greaterThan($threshold) ? $parsed : $threshold;
    }

    /**
     * group を指す Query Builder (呼ぶたびに作り直す = 集計で汚れない)。
     *
     * ★Eloquent ではなく Query Builder を使う。台帳モデルは delete を例外化しており
     *   (append-only guard)、畳み込みはその唯一の例外だからである。迂回を 1 箇所に閉じ込め、
     *   「どこで消しているか」をコードで見えるようにする。
     */
    private function groupQuery(
        int $organizationId,
        ?TicketSource $source,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $threshold,
    ): QueryBuilder {
        $query = DB::table('ticket_ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold);

        if ($source === null) {
            $query->whereNull('source');
        } else {
            $query->where('source', $source->value);
        }

        if ($expiresAt === null) {
            $query->whereNull('expires_at');
        } else {
            $query->where('expires_at', $expiresAt);
        }

        return $query;
    }
}
```

### app/Services/Billing/Retention/TicketLedgerEntryPurger.php (現行 / 全文)

```php
<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Enums\Billing\BillingRetentionTarget;
use App\Services\Billing\Contracts\BillingRetentionPurger;
use App\Services\Billing\TicketLedgerCarryForwardService;
use Carbon\CarbonImmutable;

/**
 * チケット台帳の purger (**物理削除ではなく畳み込み**)。
 *
 * 他の target は行を消して決着させるが、台帳は残高の真実源であり、消すと残高が変わる。
 * よってここは {@see AbstractBillingRetentionPurger} を継承せず、畳み込み
 * ({@see TicketLedgerCarryForwardService}) への薄い adapter に徹する。
 *
 * ★`countFailClosed()` は常に 0 である。台帳は補助時計 (起算不能の異常検出) を持たず
 *   (`created_at` は必ず入る)、参照されて消せない行も無い。決着できなかった組織は
 *   `unexpectedFailures` として報告され、その行は `expiredRemaining` に残る
 *   — 「安全のため残した」と「決着できなかった」を混同しない。
 */
final class TicketLedgerEntryPurger implements BillingRetentionPurger
{
    public function __construct(
        private readonly TicketLedgerCarryForwardService $carryForward,
    ) {}

    public function target(): BillingRetentionTarget
    {
        return BillingRetentionTarget::TicketLedgerEntry;
    }

    public function countExpired(CarbonImmutable $threshold): int
    {
        return $this->carryForward->countExpired($threshold);
    }

    public function countFailClosed(CarbonImmutable $threshold): int
    {
        return 0;
    }

    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        return $this->carryForward->carryForward($threshold);
    }
}
```

### app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php (現行 / 全文)

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\BillingRetentionTarget;

/**
 * 保持期間 purge の 1 target 分の結果。
 *
 * **任意メタデータ領域 (`array<string, mixed>`) は持たせない** — 何が入るか型で分からない
 * 領域を作ると、そこに organization id やメールアドレスが載って運用ログへ漏れる。
 *
 * 件数の関係:
 *   candidates      = 起算済み・期限超過の件数 (purge 前)
 *   processed       = 実際に削除 (C2 では畳み込み) した件数
 *   failClosed      = 安全のため残した件数 (起算不能の異常 + 参照中で消せないもの)
 *   expiredRemaining = purge 後に残った起算済み・期限超過の件数
 *
 * **`failClosed` は「安全に残した」であって「規約を満たした」ではない**。
 * 規約 (最長 N 年) を満たしたと言えるのは `expiredRemaining === 0` のときだけである。
 */
final readonly class BillingRetentionPurgeResultDto
{
    public function __construct(
        public BillingRetentionTarget $target,
        public int $candidates,
        public int $processed,
        public int $failClosed,
        public int $unexpectedFailures,
        public int $expiredRemaining,
    ) {}

    /**
     * dry-run (1 行も消さない) の結果。
     *
     * 何も消していないのだから残存 = 候補である (楽観的に 0 と報告しない)。
     */
    public static function dryRun(
        BillingRetentionTarget $target,
        int $candidates,
        int $failClosed,
    ): self {
        return new self(
            target: $target,
            candidates: $candidates,
            processed: 0,
            failClosed: $failClosed,
            unexpectedFailures: 0,
            expiredRemaining: $candidates,
        );
    }

    public function hasFailClosedRecords(): bool
    {
        return $this->failClosed > 0;
    }

    public function hasUnexpectedFailures(): bool
    {
        return $this->unexpectedFailures > 0;
    }

    /**
     * 規約文面の公開 (PR-C3) に進んでよいか。
     *
     * **分類を問わず期限超過 0 件**が条件である。`failClosed` を除外して「安全に残したものは
     * 数えない」とすると、規約が宣言した年数を超えた記録が残ったまま「準拠した」と言えてしまう。
     */
    public function isPublicationReady(): bool
    {
        return $this->failClosed === 0
            && $this->unexpectedFailures === 0
            && $this->expiredRemaining === 0;
    }
}
```

### app/Models/Billing/TicketLedgerEntry.php (現行 / 全文)

```php
<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Database\Factories\Billing\TicketLedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * チケット台帳エントリ (残高の真実源)。
 *
 * **append-only 不変条件**: 一度書いた行は更新も削除もしない (課金の監査痕跡)。
 * - updated_at を持たない (UPDATED_AT = null)
 * - update / delete は Eloquent イベントで例外化
 *
 * 期限付き付与: expires_at 到達で残高計算 (balance) から外れる。冪等付与は
 * idempotency_key (UNIQUE) で二重付与を防ぐ。買い切り購入行は payment_intent_id /
 * purchase_amount を持ち、返金 (charge.refunded) の逆仕訳 (clawback) の正本になる。
 *
 * 保持期間 (7 年) の決着は**物理削除ではなく畳み込み**である
 * (`TicketLedgerCarryForwardService`)。期限超過の取引行は
 * `(organization_id, source, expires_at)` ごとに合算され、`kind = carry_forward` の
 * **残高スナップショット 1 行**へ置換される。置換後の行は `carried_forward_through` に
 * 集約期間の終端を持ち、原取引の識別子を 1 つも持たない。
 *
 * 全カラムが TicketLedgerService の内部状態のため $fillable は持たない (明示代入のみ)。
 *
 * @property int $id
 * @property int $organization_id
 * @property int $delta
 * @property TicketLedgerKind $kind
 * @property TicketSource|null $source
 * @property int|null $reservation_id
 * @property string $description
 * @property CarbonImmutable|null $granted_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $carried_forward_through
 * @property string|null $stripe_checkout_session_id
 * @property string|null $stripe_invoice_id
 * @property string|null $payment_intent_id
 * @property int|null $purchase_amount
 * @property string|null $idempotency_key
 * @property CarbonImmutable $created_at
 */
class TicketLedgerEntry extends Model
{
    /** @use HasFactory<TicketLedgerEntryFactory> */
    use HasFactory;

    /** append-only のため updated_at を持たない */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('ticket_ledger_entries は append-only です (update 禁止)');
        });
        static::deleting(function (): never {
            throw new LogicException('ticket_ledger_entries は append-only です (delete 禁止)');
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<TicketReservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(TicketReservation::class, 'reservation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'kind' => TicketLedgerKind::class,
            'source' => TicketSource::class,
            'purchase_amount' => 'integer',
            'granted_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'carried_forward_through' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
```

### app/Services/Billing/TicketLedgerService.php (抜粋: 残高集計と直列化点)

```php
    private function lockOrganizationRow(Organization $organization): void
    {
        Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 予約行をロックする。
     *
     * $requireReserved = true (既定) は reserved 状態を検証する (release の一方向遷移の強制)。
     * commit は commit-wins のため false で呼び、status 検査を行わない
     * (二重課金は consume:{id} の UNIQUE が防ぐ)。
     */
    private function lockReservationRow(TicketReservation $reservation, bool $requireReserved = true): TicketReservation
    {
        $locked = TicketReservation::query()
            ->whereKey($reservation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

    private function availableBySource(Organization $organization, CarbonImmutable $now): array
    {
        $monthly = $this->sumBalance($organization, TicketSource::Monthly, $now);
        $purchased = $this->sumBalance($organization, TicketSource::Purchased, $now);

        return [
            max($monthly - $this->sumActiveHolds($organization, TicketSource::Monthly, $now), 0),
            max($purchased - $this->sumActiveHolds($organization, TicketSource::Purchased, $now), 0),
        ];
    }

    /**
     * 出所バケットの生残高 (未失効行の delta 合計。負を許容)。
     *
     * purchased バケットは `source IS NULL` 行を畳み込む。AI-CUE の台帳には出所を持たない行
     * (P5 以前の消費行 / 手動 grant / adjustment / release) が既存し、台帳は append-only で
     * backfill できないため。両バケットから落とすと過去消費が帳消しになり over-grant する
     * (null 行はいずれも無期限で purchased と寿命特性が一致する)。
     */
    private function sumBalance(Organization $organization, TicketSource $source, CarbonImmutable $now): int
    {
        return (int) TicketLedgerEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where(function (Builder $query) use ($source): void {
                $query->where('source', $source);
                if ($source === TicketSource::Purchased) {
                    $query->orWhereNull('source');
                }
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->sum('delta');
    }

    /**
     * 当該出所を消費する active hold の拘束枚数。
     *
     * reserve TTL 切れ (expires_at <= now) でも Reserved である限り枠を保持する: commit-wins は
     * TTL 超過でも課金するため、与信側で枠を再開放すると 30 分超ジョブ中に同じ枠が二重予約され
     * 両方 commit でオーバーセルになる。枠の解放は 滞留回収 (releaseExpiredReservation) の Released 化に委ねる。
     * 失効 monthly hold のみ除外する (grant 自体が消えており commit-wins も no-charge のため)。
     *
     * legacy 行 (consume_source = null) はどちらの出所にも計上されない (aigenba verbatim)。
     * その結果 legacy 行が reserve を拘束しない窓が TTL 30 分だけ開くが、balance() の
     * activeReservations は legacy も計上するため表示は保守側になる。
     */
    private function sumActiveHolds(Organization $organization, TicketSource $source, CarbonImmutable $now): int
    {
        return (int) TicketReservation::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', TicketReservationStatus::Reserved)
            ->where('consume_source', $source)
```

### tests/Architecture/TicketLedgerReaderInventoryTest.php (抜粋: 目録と走査域の定数)

```php
 * 入口 4 (列名リテラル) の走査範囲。
 *
 * `source` / `expires_at` は他テーブルにも実在する一般名のため、app/ 全体では信号が死ぬ。
 * 課金ディレクトリに限ることで「台帳の近所で列名だけ使う新規経路」を捕まえる。
 */
const TICKET_LEDGER_COLUMN_SCAN_DIRS = [
    'Models/Billing',
    'Services/Billing',
    'Console/Commands/Billing',
    'Enums/Billing',
];

/**
 * 台帳を読む / 触る場所の目録 (app_path からの相対パス => [読み方, 根拠])。
 *
 * 読み方の語彙:
 * - `aggregate`   … 集計 (SUM / COUNT / MAX) でしか読まない。畳み込みに影響されない
 * - `row_detail`  … 個別取引行の属性に依存する。畳み込みで**情報が失われる**側
 * - `other_table` … 台帳ではない同名列を持つ別テーブルの経路 (入口 4 の巻き添え)
 *
 * @var array<string, array{string, string}>
 */
const TICKET_LEDGER_READER_INVENTORY = [
    'Models/Billing/TicketLedgerEntry.php' => [
        'row_detail',
        '台帳モデルそのもの。列定義と append-only guard (update/delete の例外化) を持つ',
    ],
    'Models/Organization.php' => [
        'aggregate',
        'relation 定義 (ticketLedgerEntries) のみ。行の中身は読まず件数・合算の入口を提供する',
    ],
    'Enums/Billing/BillingRetentionTarget.php' => [
        'aggregate',
        '保持期間の目録で台帳を target として宣言する。モデルクラスと起算列名の参照のみ',
    ],
    'Services/Billing/TicketLedgerService.php' => [
        'aggregate',
        '台帳の唯一の書き込み窓口。残高は source / expires_at 別の SUM で読み、個別取引行の識別子には依存しない',
    ],
    'Services/Billing/TicketLedgerCarryForwardService.php' => [
        'row_detail',
        '保持期間の畳み込み本体。期限超過の個別取引行を残高スナップショット 1 行へ置換する唯一の経路',
    ],
    'Services/Billing/Retention/TicketLedgerEntryPurger.php' => [
        'aggregate',
        '保持期間 purger の adapter。件数の集計と畳み込みサービスへの委譲だけを行う',
    ],
    'Models/Billing/TicketReservation.php' => [
        'other_table',
        'ticket_reservations の expires_at (予約 TTL) であり台帳の失効時刻ではない。入口 4 の巻き添え',
    ],
    'Models/Billing/TicketCheckoutSession.php' => [
        'other_table',
        'ticket_checkout_sessions の expires_at (Checkout Session の失効) であり台帳ではない',
    ],
    'Services/Billing/TicketCheckoutService.php' => [
        'other_table',
        'ticket_checkout_sessions の expires_at を扱う購入手続きの経路であり台帳は読まない',
    ],
];

/** 読み方の語彙 (exact-fit)。 */
const TICKET_LEDGER_READ_MODES = ['aggregate', 'row_detail', 'other_table'];

/** 走査ファイル数の下限 (degenerate PASS 防止)。 */
const TICKET_LEDGER_SCAN_FLOOR = 200;

/**
 * PHP ソースから台帳への参照入口を検出する。
 *
 * コメント / docblock は code token ではないので拾わない (説明文で偽赤にならない)。
 * 文字列リテラルは table 名・relation 名・列名の照合に要るので**値だけ**見る
 * (中身を PHP として解釈はしない)。
```

### tests/Feature/Billing/TicketLedgerCarryForwardTest.php (現行の観測ヘルパ)

```php
/**
 * 台帳の残高粒度ごとの合計 (organization_id / source / expires_at)。
 *
 * **合計 0 の group は落とす**。畳み込みは残高に寄与しない行を作らないため、
 * 「0 の group が消えること」は残高の変化ではない。
 *
 * @return array<string, int>
 */
function ledgerBalanceByGroup(): array
{
    $totals = [];
    foreach (TicketLedgerEntry::query()->get() as $entry) {
        $key = implode('|', [
            $entry->organization_id,
            $entry->source?->value ?? 'null',
            $entry->expires_at?->toIso8601String() ?? 'null',
        ]);
        $totals[$key] = ($totals[$key] ?? 0) + $entry->delta;
    }

    ksort($totals);

    return array_filter($totals, static fn (int $total): bool => $total !== 0);
}

/**
 * 組織ごとの表示残高 + 与信残高。
 *
 * @return array<int, array{monthly: int, purchased: int, holds: int, available: int}>
 */
function ledgerBalancesByOrganization(): array
{
    $service = app(TicketLedgerService::class);
    $out = [];
    foreach (Organization::query()->orderBy('id')->get() as $organization) {
        $balance = $service->balance($organization);
        $id = $organization->getKey();
        expect($id)->toBeInt();
        $out[$id] = [
            'monthly' => $balance->monthlyRemaining,
            'purchased' => $balance->purchasedRemaining,
            'holds' => $balance->activeReservations,
            'available' => $service->availableTrueBalance($organization),
        ];
    }

    return $out;
}
```

### database/migrations/2026_06_11_091400_create_ticket_tables.php (抜粋: 台帳の列定義)

```php

        Schema::create('ticket_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // 正 = 付与 / 負 = 消費 (commit)・クローバック。release は監査用の 0 行
            $table->integer('delta');
            // grant | reserve_commit | release | adjustment | clawback (App\Enums\Billing\TicketLedgerKind)
            $table->string('kind');
            // 付与系エントリの出所 (monthly | purchased。App\Enums\Billing\TicketSource)。消費・解放行は null
            $table->string('source')->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained('ticket_reservations')->restrictOnDelete();
            $table->string('description');
            // 付与時刻 (付与系エントリのみ。監査用)
            $table->timestamp('granted_at')->nullable();
            // 期限付き付与の失効時刻。null = 無期限。balance() は未失効行のみ合算する
            $table->timestamp('expires_at')->nullable();
            // 買い切り購入の Stripe Checkout Session (grantPurchased の冪等キー由来)
            $table->string('stripe_checkout_session_id')->nullable();
            // 返金逆仕訳 (clawback) の正本キー。charge.refunded の payment_intent と照合する
            $table->string('payment_intent_id')->nullable();
            // 購入時の元決済額 (最小通貨単位)。部分返金の按分分母
            $table->unsignedInteger('purchase_amount')->nullable();
            // 冪等付与のキー (grantMonthly / grantPurchased / clawback)。手動 grant / 消費行は null
            // (UNIQUE は NULL を複数許容するので既存経路と共存できる)
            $table->string('idempotency_key')->nullable()->unique();
            // append-only のため updated_at は持たない
            $table->timestamp('created_at');

            $table->index(['organization_id', 'kind']);
            $table->index('payment_intent_id');
        });

        $this->createTicketVolumePricesTable();
```
