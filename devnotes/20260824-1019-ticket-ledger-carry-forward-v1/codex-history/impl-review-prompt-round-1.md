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


# 役割

あなたは Laravel 12 + Svelte 5 + Inertia アプリ **aicue** の実装レビュアーである。
本件 (TODO T259) は**課金チケット台帳 (追記専用 delta 台帳) の保持期限の決着方式**を
家系の正典 v1 (二段判定・収束繰越形) へ追従させる変更である。

# レビュー観点

1. **設計との一致性** — 詳細設計 (下記) の施策 1〜10 が実装に反映されているか。
   逸脱があれば「意図的な逸脱として妥当か」まで判定すること。
2. **正確性 (最優先)** — 残高保存が壊れる経路が無いか。
   - 第 2 段の寄与判定 (`expires_at IS NULL OR > now`) と削除枝 (`expires_at <= now`) が
     **厳密な補集合**になっているか (どちらの枝にも入らない行 / 両方に入る行が無いか)
   - 決着対象の述語 (`settlementPredicate`) と、実際に処理される集合が一致しているか
     (「数えているのに処理されない行」「処理されるのに数えられていない行」が無いか)
   - 集計と削除の間の窓 (件数照合) / トランザクション境界 / ロック順序
   - `int4` 範囲検査が**キャスト前**に効いているか
3. **PHPStan level 10 適合性** (型の widen・`@phpstan-ignore` は禁止事項)
4. **DTO / JsonResource パターン**
5. **テスト網羅性** — 新しい不変条件が機械で固定されているか。
   赤の起点 (N1/N2/N11/N12/N14/N18) が実際に v0 で赤になる性質を持つか。
   **偽グリーン (空振り)** が無いか。
6. **セキュリティ** — `withTrashed()` の導入がテナント境界を弱めていないか。
   `DirectFetchInventory` への登録が不要という判断 (識別子が解決済みモデル由来) が妥当か。
7. **静的 gate の規約適合** — AGENTS.md 「静的検査 (gate) と走査器の共通規約」(a)〜(e):
   完全修飾名での突合 / 解決できない形を落とす (fail-closed) / 負例で裏取り /
   集めた結果を判定に使う / 語彙一致はトークン完全一致。
   走査器の docblock が「保証しないもの」を誇張なく書けているか。
8. **文書の正本関係** — 同じ内容が 2 か所に書かれて食い違う形になっていないか。

DESIGN.md / Atomic Design の観点は**本件では対象外**である
(`resources/js` / `resources/css` の変更は 1 行も無い)。

# 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明記する


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
| 10 | v1 用の変異表の作り直し | `devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md` (新規) | 中 |

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
use stdClass;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 保持期限以前のチケット台帳の畳み込み。
 *
 * **台帳の行を物理削除し、残高スナップショット 1 行へ置換する唯一の経路**である
 * (「台帳への変更の唯一の経路」ではない — `TicketLedgerService` は通常の追記と、
 * `payment_intent_id` を null → 値で埋める限定 backfill を持つ)。
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
 * ★**決着対象 (settlement scope) は 1 つの述語で定義する**。定義がずれると
 *   「数えているのに処理されない行」が生まれる。**共有の範囲は正確に言う** —
 *   `settlementPredicate()` を直接共有するのは
 *   **組織の列挙 (`organizationsWithSettlementTargets`) と件数・監視 (`settlementScope`)** の 2 経路である。
 *   **行の処理側は同じ集合を「厳密な補集合となる 2 枝」で実装する**
 *   (`expiredScope()` = 失効済み / `contributingGroups()` + `groupScope()` = 寄与する行)。
 *   処理側を同じ述語にできないのは、削除と集約で必要な形が違うからである
 *   (前者は 1 本の DELETE、後者は集約キーごとの GROUP BY)。
 *   **補集合であること (どちらの枝にも入らない行が無い / 両方に入る行が無い) は
 *   N1・N18・境界時刻テスト・変異表が固定する**。
 *
 *       created_at <= 閾値
 *       AND ( kind != carry_forward                                   -- 取引明細
 *             OR (expires_at IS NOT NULL AND expires_at <= now) )     -- 失効した繰越行
 *
 *   繰越行のうち**まだ寄与している (無期限 or 未来に失効) もの**だけが決着対象から外れる
 *   (継続状態を表す集約レコードであり、保持期限が消す対象ではない。
 *   語の正本は {@see BillingRetentionPurgeResultDto} の docblock)。
 *   **失効した繰越行は決着対象に戻る** — 残高に寄与しなくなった瞬間に物理削除の対象であり、
 *   これを外すと「失効済みの繰越行しか持たない組織」が永久に処理されない
 *   (= 失効窓の有界化が成立しない)。
 *
 * ★**母集団は論理削除済み組織も含む**。`Organization` は `SoftDeletes` であり、
 *   global scope の効く経路で組織を列挙すると**退会済み組織の台帳が永久に畳まれない**
 *   (期限超過が残り続けて保持期限の宣言が満たせなくなる)。よって列挙とロックの両方を
 *   `withTrashed()` 起点にする。`withTrashed(` の出現は
 *   `TicketLedgerMutationSiteGateTest` が本ファイルへ件数まで固定する
 *   (テナント境界を迂回する一般的な主キー取得へ転用させない)。
 *
 * 直列化は組織行の排他ロック ({@see \App\Services\Billing\TicketLedgerService} が
 * 残高判定の前に取るのと同じ点) で行う。組織 1 件 = 1 トランザクションで、
 * 1 組織の失敗は他の組織を止めない。
 *
 * ★**ロックが守る範囲を誇張しない**。組織行ロックが直列化するのは
 *   **同じロック (`lockOrganizationRow()`) を取る経路だけ**である —
 *   畳み込み同士と、`TicketLedgerService` のうち残高判定を伴う操作
 *   (`grant` / `reserve` / `commit` / `release`)。
 *   一方 `grantMonthly` / `grantPurchased` / `grantSignupGrant` / `clawback` の**冪等 insert は
 *   このロックを取らない** (実読で確認) ので、集計と削除の間に
 *   `created_at <= 閾値` の行が commit されうる。
 *   その窓を閉じるのは**ロックではなく件数照合とトランザクションの巻き戻し**である
 *   (`carryForwardOrganization` の手順 7)。二重の繰越行を防ぐのは
 *   「**同一トランザクション内で削除 → 追記**」という順序であり、
 *   ロックはそこへ他の畳み込みが割り込まないことだけを保証する。
 *
 * **append-only との関係**: モデルは `updating` / `deleting` を例外化しているが、
 * Eloquent の一括削除はモデルイベントを発火しない。append-only は
 * 「業務経路では追記しかしない」という不変条件であり、その例外は 2 種類ある —
 * **行の削除・置換は保持期限の決着 (本ファイル) だけ**、
 * **限定 metadata backfill は `TicketLedgerService::backfillPaymentIntentId()` だけ**である。
 * 許容される変更サイトの正本は
 * `Tests\Support\Architecture\TicketLedgerMutationInventory` である。
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
     * 保持期限以前の**決着対象**の件数 (寄与中の繰越行は数えない / 失効した繰越行は数える)。
     *
     * ★`BillingRetentionPurger` の署名に合わせて `$now` を受け取らない。dry-run 用の
     *   単発の観測なので、ここでは呼び出し時点の現在時刻で判定する。
     *   **1 回の実行の中で母集団を揃える必要がある `carryForward()` は、
     *   自分が確定した `$now` を `settlementScope()` へ直接渡す** (下記)。
     *
     * 論理削除済み組織の行も数える (組織を結合しないので global scope は効かない)。
     * 列挙側 (`organizationsWithSettlementTargets`) も `withTrashed()` なので
     * **両者の母集団は一致する**。
     */
    public function countExpired(CarbonImmutable $threshold): int
    {
        return $this->settlementScope($threshold, CarbonImmutable::now())->count();
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
        $candidates = $this->settlementScope($threshold, $now)->count();
        $processed = 0;
        $unexpectedFailures = 0;

        foreach ($this->organizationsWithSettlementTargets($threshold, $now) as $organization) {
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
            // 残数も**同じ `$now`** で数える (実行中に時計が進むと候補と残数の母集団がずれる)
            expiredRemaining: $this->settlementScope($threshold, $now)->count(),
        );
    }

    /**
     * **決着対象**の述語 (この 1 か所が唯一の定義。列挙・件数・監視が共有する)。
     *
     * 第 1 段の適格性 (`created_at <= 閾値`) を満たし、かつ
     * 「取引明細である」または「失効した繰越行である」行。
     *
     * @return EloquentBuilder<TicketLedgerEntry>
     */
    private function settlementScope(CarbonImmutable $threshold, CarbonImmutable $now): EloquentBuilder
    {
        return TicketLedgerEntry::query()
            ->where('created_at', '<=', $threshold)
            ->where(fn (EloquentBuilder $query): EloquentBuilder => $this->settlementPredicate($query, $now));
    }

    /**
     * 決着対象の内側の述語 (relation の `whereHas` からも同じものを使う)。
     *
     * @param  EloquentBuilder<TicketLedgerEntry>  $query
     * @return EloquentBuilder<TicketLedgerEntry>
     */
    private function settlementPredicate(EloquentBuilder $query, CarbonImmutable $now): EloquentBuilder
    {
        return $query
            ->where('kind', '!=', TicketLedgerKind::CarryForward->value)
            ->orWhere(fn (EloquentBuilder $expired): EloquentBuilder => $expired
                ->where('kind', TicketLedgerKind::CarryForward->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now));
    }

    /**
     * 決着対象を持つ組織 (id 昇順 = ロック順序の固定)。
     *
     * ★`withTrashed()` が必須である。退会 (論理削除) は課金記録の寿命を縮めない
     * (`docs/template-divergence.md` D23)。
     * ★述語は `settlementPredicate()` を共有する (列挙と件数で条件が分岐しない)。
     *
     * @return Collection<int, Organization>
     */
    private function organizationsWithSettlementTargets(
        CarbonImmutable $threshold,
        CarbonImmutable $now,
    ): Collection {
        return Organization::withTrashed()
            ->whereHas(
                'ticketLedgerEntries',
                fn (EloquentBuilder $query): EloquentBuilder => $query
                    ->where('created_at', '<=', $threshold)
                    ->where(fn (EloquentBuilder $inner): EloquentBuilder => $this->settlementPredicate($inner, $now)),
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
        // ★closure へは **`Organization` モデルそのもの**を渡す (id を先に取り出さない)。
        //   `whereKey($organization->getKey())` の形にすることで、識別子が
        //   **解決済みモデル由来**であることが走査器から見え、`DirectFetchInventory` の
        //   母集団に入らない (id を捕まえた `whereKey($organizationId)` にすると候補になる。
        //   実測は本設計の「主キー取得 gate との関係」節)。
        return DB::transaction(function () use ($organization, $threshold, $now): int {
            // 残高判定・台帳追記の直列化点 (TicketLedgerService::lockOrganizationRow と同じ点)。
            // 論理削除済み組織も対象なので withTrashed で取る。
            Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            $organizationId = $organization->getKey();
            Assert::integer($organizationId, '組織 id が解決できません (畳み込みは中止する)');

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
            // クエリビルダの行は stdClass である。境界 DTO は stdClass だけを受けるので
            // ここで型を確定させる (driver 差で別の型が来たら fail-closed で落とす)。
            Assert::isInstanceOf($row, stdClass::class, '集約行が stdClass ではない (畳み込みを中止する)');
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
| `groupQuery()` (単段の述語) | `settlementScope()` / `settlementPredicate()` / `expiredScope()` / `groupScope()` / `contributingGroups()` に分かれる |
| `aggregateGroup()` の `Assert::numeric` + `(int)` | 範囲検査つきの DTO 境界 (`CarryForwardGroup::fromRow`) が引き受ける |

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`int` / `Collection<int, Organization>` / `list<CarryForwardGroup>` / `EloquentBuilder<TicketLedgerEntry>`)
- [x] null 安全 (`Assert::integer($organizationId, ...)` で `getKey()` の `mixed` を絞る)
- [x] DTO を返している (`BillingRetentionPurgeResultDto` / `CarryForwardGroup`。配列返却は private の内部だけ)
- [x] Generics の型パラメータが正しい (`EloquentBuilder<TicketLedgerEntry>` / `Collection<int, Organization>`)
- 注意: `Organization::withTrashed()` は `app/` で初出である。larastan がモデルの soft-delete scope を
  静的に解決できない場合は **`Organization::query()->withTrashed()`** へ書き換える。
  施策 5 の gate が**受理するのはこの 2 形だけ**なので、どちらを選んでも gate は通る
  (変数受け手・長い連鎖へ流れたら gate が落とすので、その時は gate ではなく実装を直す)。
  **型を緩めて黙らせる (禁止事項 2) 方向へは倒さない**

### 主キー取得 gate (`ModelDirectFetchInvariantTest`) との関係 — 登録しない判断の根拠

`Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()` は
**クラス起点の主キー同一性クエリの形をしているが、`DirectFetchInventory` の母集団には入らない**。
これは走査器の盲点を突いているのではなく、**同目録が宣言している母集団の定義**である。

> (`tests/Support/Security/DirectFetchInventory.php` の docblock)
> 「ノイズは走査器の provenance フィルタ (**識別子引数が解決済みモデル由来のものを外す**) で落とす」

本経路の識別子は payload / route parameter / token claim ではなく、
**直前の列挙で解決済みの `Organization` モデルの主キー**である (テナント越境の入力が無い)。
同じ形は既に本リポジトリの至る所にあり、代表例は
**`TicketLedgerService::lockOrganizationRow()`** で、**目録に登録されていない**
(現行 v0 の畳み込みも同様)。ここだけ登録すると、目録の意味が
「解決済みモデル由来も載せる」へ変わり、`app/` 全域の `->whereKey($model->getKey())` を
洗い出す作業が付いてくる (本 feature の射程外であり、思考原則 2 に反する)。

**「走査器が withTrashed で解析に失敗しているだけではないか」への裏取り (4 形の実測)**

走査器 (`PrimaryKeyStaticQueryScanner::candidates()`) に 4 つの形をそのまま食わせて数えた。

| 形 | 候補 |
|---|---|
| A. closure が **`Organization` モデル**を捕まえ `whereKey($organization->getKey())` (= 本設計の採用形) | **0 件** |
| B. closure が **`int`** を捕まえ `whereKey($organizationId)` (= 当初案) | **1 件** |
| C. `whereKey($request->input('organization_id'))` (payload 由来。負のコントロール) | **1 件** |
| D. `withTrashed()` **無し**で `whereKey($organization->getKey())` | **0 件** |

- **A と D が同じ 0 件** → `withTrashed()` が解析を壊しているのではない
  (`withTrashed()` を外しても 0 件のまま)。
- **B が 1 件** → 走査器はこのファイル・このメソッドを**ちゃんと見ている**
  (母集団の外に落ちているわけではない)。
- **C が 1 件** → payload 由来へ変えれば検出される。**負のコントロールが点灯する**。

すなわち A が 0 件になる理由は **provenance 除外 (識別子が解決済みモデル由来)** であり、
解析の失敗ではない。この裏取りを根拠に **`DirectFetchInventory` の登録も走査器の変更も行わない**。

**設計上の帰結 (実装の docblock にも書く)**

- closure へは **id ではなくモデルを渡す** (`use ($organization, ...)`)。
  id を先に取り出して捕まえる形 (B) に書き換えると**候補が 1 件生まれる**ので、
  その時は `DirectFetchInventory` へ理由付きで登録すること
- 識別子の出自は「同一実行内の列挙結果」であり、**外部入力に由来しない**

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
use stdClass;
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
 * ★**列ごとの許容型** (bool / float / 指数表記 / 小数 / 空文字 / 前後空白つきは**すべて例外**):
 *   - `source`: `null` はそのまま保持する / **文字列だけ** `TicketSource::from()` へ渡す
 *     (未知の値は列挙型が例外にする) / それ以外の型は例外
 *   - `expires_at`: `null` / 文字列 / `DateTimeInterface`
 *   - `max_created_at`: **非 null** 必須。文字列 / `DateTimeInterface`
 *   - `delta_sum`: `int` または 10 進整数の文字列。**int4 の範囲を変換前に検査**する
 *   - `row_count` / `carry_forward_rows`: `int` または 10 進整数の文字列。
 *     **PHP 整数の範囲を変換前に検査**したうえで非負であること
 *
 * ★**集約結果どうしの不変条件**も境界で見る (壊れた集計が収束判定へ流れないように)。
 *     `rowCount >= 1` かつ `0 <= carryForwardRows <= rowCount`
 *
 * ★**引数は `stdClass` に狭める**。クエリビルダの `get()` が返すのは `stdClass` であり、
 *   任意 object を許すと「`propertyExists()` は true だが `get_object_vars()` には
 *   現れない private property」という穴が開く。読み出しは `get_object_vars()` +
 *   `Assert::keyExists()` の 2 段で行う (動的プロパティ参照 `$row->$name` は使わない —
 *   arch ベースラインの動的メンバ目録を太らせないため)。
 *
 * ★**想定外の余剰列は拒否しない**。集約 SQL は畳み込みサービスが組み立てるので余剰列は
 *   入らず、拒否すると driver が付ける内部列で偽赤になりうる。**列の欠落は例外**にする。
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
    public static function fromRow(stdClass $row): self
    {
        $source = self::nullableString($row, 'source');
        $maxCreatedAt = self::nullableTimestamp($row, 'max_created_at');
        Assert::notNull($maxCreatedAt, '集約の基準時刻 (max_created_at) が取得できない');

        $rowCount = self::natural($row, 'row_count');
        $carryForwardRows = self::natural($row, 'carry_forward_rows');
        // 集約結果どうしの整合 (壊れた集計が収束判定へ流れないようにする)
        Assert::greaterThanEq($rowCount, 1, '集約キーの行数が 1 未満である (集計が壊れている)');
        Assert::lessThanEq($carryForwardRows, $rowCount, '繰越行の数が集約キーの行数を超えている');

        return new self(
            $source === null ? null : TicketSource::from($source),
            self::nullableTimestamp($row, 'expires_at'),
            self::int4($row, 'delta_sum'),
            $maxCreatedAt,
            $rowCount,
            $carryForwardRows,
        );
    }

    /** 列の読み出しの唯一の口 (存在しない列は表明で落とす)。 */
    private static function value(stdClass $row, string $property): mixed
    {
        /** @var array<string, mixed> $values */
        $values = get_object_vars($row);
        Assert::keyExists($values, $property, "集計行に列 {$property} が無い");

        return $values[$property];
    }

    /** 文字列列 (列挙値の生表現)。 */
    private static function nullableString(stdClass $row, string $property): ?string
    {
        $value = self::value($row, $property);
        if ($value === null) {
            return null;
        }
        Assert::string($value, "集計行の列 {$property} が文字列ではない");

        return $value;
    }

    /** 日時列 (driver によって文字列 / DateTimeInterface で返る)。 */
    private static function nullableTimestamp(stdClass $row, string $property): ?CarbonImmutable
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
    private static function int4(stdClass $row, string $property): int
    {
        return self::decimalInt(
            self::value($row, $property),
            $property,
            '2147483647',
            '2147483648',
            self::rangeMessage($property),
        );
    }

    /** 非負整数 (件数)。PHP 整数の範囲も**変換前に**判定する。 */
    private static function natural(stdClass $row, string $property): int
    {
        $number = self::decimalInt(
            self::value($row, $property),
            $property,
            (string) PHP_INT_MAX,
            ltrim((string) PHP_INT_MIN, '-'),
            "集計行の列 {$property} が PHP 整数の範囲を超えた",
        );
        Assert::natural($number, "集計行の列 {$property} が負である");

        return $number;
    }

    /**
     * `int` か 10 進整数の文字列だけを受け、**PHP `int` へ変換する前に**上下限を判定する。
     *
     * bool / float / 指数表記 / 小数 / 空文字 / 前後空白つきはすべて例外にする
     * (`is_numeric()` や `Assert::integerish()` はこれらの一部を受理するので使わない)。
     *
     * @param  string  $positiveLimit  正側の上限の絶対値 (10 進文字列)
     * @param  string  $negativeLimit  負側の下限の絶対値 (10 進文字列)
     */
    private static function decimalInt(
        mixed $value,
        string $property,
        string $positiveLimit,
        string $negativeLimit,
        string $rangeMessage,
    ): int {
        if (is_int($value)) {
            // `int` で来た値は PHP 整数の範囲内が保証されているので、絶対値の桁比較だけで足りる
            Assert::true(
                self::withinLimit((string) $value, $positiveLimit, $negativeLimit),
                $rangeMessage,
            );

            return $value;
        }

        Assert::string($value, "集計行の列 {$property} が int でも文字列でもない");
        Assert::regex($value, '/\A-?[0-9]+\z/', "集計行の列 {$property} が 10 進整数の表記ではない");
        Assert::true(self::withinLimit($value, $positiveLimit, $negativeLimit), $rangeMessage);

        return (int) $value;
    }

    /** 10 進文字列のまま上下限と比較する (符号 → 桁数 → 辞書順)。 */
    private static function withinLimit(string $decimal, string $positiveLimit, string $negativeLimit): bool
    {
        $negative = str_starts_with($decimal, '-');
        $digits = ltrim($negative ? substr($decimal, 1) : $decimal, '0');
        if ($digits === '') {
            return true; // 0 (`-0` / `000` を含む)
        }
        $limit = $negative ? $negativeLimit : $positiveLimit;
        if (strlen($digits) !== strlen($limit)) {
            return strlen($digits) < strlen($limit);
        }

        return strcmp($digits, $limit) <= 0;
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
- [x] 引数を `stdClass` に狭め、読み出しは `get_object_vars()` + `Assert::keyExists()` の 2 段
- [x] `Assert::integerish()` は**使わない** (整数相当の float / 指数表記を受理しうるため)。
      `decimalInt()` が `int` か 10 進整数文字列だけを受ける

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
 *   明細除去の対象となるレコード数**であり、**いま継続状態を表している集約レコードは含まない**。
 *   台帳 (`ticket_ledger_entry`) では `kind = carry_forward` の繰越行のうち
 *   **まだ残高に寄与しているもの (無期限 / 失効時刻が未来) だけ**がその集約レコードに該当する。
 *   **失効した繰越行は決着対象に含まれる** — 残高に寄与しなくなった時点で物理削除の対象であり、
 *   除外したままにすると「失効済みの繰越行だけが残った組織」が
 *   永久に処理されないまま `remaining = 0` と報告される (fail-open)。
 *   他の 6 target は集約レコードを持たないので実効値は変わらない。
 *   **この定義が正本**であり、`docs/architecture.md` と
 *   `docs/billing-retention-runbook.md` はここを参照する (2 か所に書くと必ず食い違う)。
 *   **将来ほかの target が集約レコードを持ったら、この定義を読んで分類する義務がある。**
```

### 波及変更 (この語の利用箇所の全数)

| 利用箇所 | 影響 |
|---|---|
| `BillingRetentionPurgeResultDto::isPublicationReady()` | 定義変更で**意味が正しくなる** (退会組織の明細が残る限り false / 失効した繰越行が残っても false / 寄与中の繰越行では false にならない) |
| `BillingRetentionPurgeResultDto::dryRun()` | 変更なし (`expiredRemaining = candidates`) |
| `PurgeBillingRetentionCommand` の `remaining=` 行 / `合計:` 行 / `horizon:` 行 / 終了コード | 変更なし (DTO の値をそのまま出す) |
| `tests/Feature/Billing/BillingRetentionHorizonTest.php` (`isPublicationReady()` の突合) | 変更なし。**新定義で通る**ことを回帰として確認する |
| `tests/Feature/Billing/BillingRetentionPurgeTest.php` (`ticket_ledger_entry: expired=2 processed=2` / `horizon: OK`) | 変更なし。fixture の 2 行はどちらも明細なので期待値が一致する |
| `docs/billing-retention-runbook.md` §監視 / §3 の件数表 | 施策 9 で参照を追記 |

### テスト計画

- 施策 7 のテストで 3 本を固定する。
  - 寄与中の繰越行だけなら `countExpired() === 0` (かつ繰越行は実在する)
  - **失効した繰越行だけなら `countExpired() === 1`**
  - 決着後は `countExpired() === 0`
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
 * ★**破壊条件の要約 (この 2 行だけをここに置く)**:
 *   **コード先行が必須**である (drop 先行にすると、まだ動いている旧コードの
 *   `MAX(carried_forward_through)` の集計と繰越行の INSERT が `Undefined column` で落ちる)。
 *   **drop 後に旧コードへ単純 rollback できない**。
 *   → **手順・rollback・maintenance window の判断の正本は
 *   `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**である。
 *   ここに手順を写さない (2 か所に書くと必ず食い違う)。
 * ★`down()` は列を戻すだけで**値は復元しない** (新形の繰越行は集約終端を `created_at` で
 *   表すので、復元すると嘘の値になる)。旧コードを再稼働させると既存の繰越行は
 *   「終端が未記録 (null)」として扱われる。
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

- **デプロイ順序に制約がある**。新コードはこの列を読まない・書かないので
  「新コード → drop migration」は安全だが、**逆順 (drop 先行) は旧コードを壊す**
  (旧 `aggregateGroup()` が `MAX(carried_forward_through)` を SELECT し、
  旧 `carryForwardGroup()` が同列を INSERT するため `Undefined column`)。
  **正本関係を次のとおり固定する (4 層)** —
  1. **手順・rollback・maintenance window の判断の正本** =
     `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節
  2. **drop migration の docblock** = 「コード先行が必須」「drop 後に単純 rollback 不可」の
     **2 行だけ**を破壊条件の要約として持ち、詳細は runbook を参照する
     (migration を単独で読んだ人にも drop-first の危険が見えるようにするため)
  3. **`AGENTS.md` 規約 21** = 同じ**破壊条件の要約 1 行**を持ち、詳細は runbook を参照する
     (開発者が最初に読む規約で危険を見せる意味がある)
  4. **`docs/architecture.md`** = 順序を**一切書かず** runbook を参照するだけ
- **rollback の非対称**: `down()` は列を戻すが値は戻さない。旧コードへ戻すと
  既存の繰越行は終端が null として扱われる。この事実を runbook に書く。

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
  - `withTrashed(` / `onlyTrashed(` は同じ規則で数える。加えて**受理する構文を 2 形に固定**し、
    それ以外は**未解決として gate を落とす** (fail-closed):
      (A) `Organization::withTrashed()` — `PhpReferenceScanner` の StaticCall で
          受け手が `App\Models\Organization` に解決されるもの
      (B) `Organization::query()->withTrashed(` の**トークン列そのものの一致**
          (`T_STRING(Organization)` `::` `query` `(` `)` `->` `T_STRING(withTrashed)` `(`)。
          ここで `Organization` は import 表を解いて `App\Models\Organization` に解決できること
    変数受け手 (`$query->withTrashed()`) や長い連鎖は**受理しない** (同じファイルに
    `Organization::query()` が在ることを根拠に認定する形は fail-open なので採らない)

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
/** 畳み込みサービス (台帳の行を物理削除し残高スナップショットへ置換する唯一の経路)。 */
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
            'reason' => '行の物理削除と残高スナップショットへの置換を行う唯一の経路 (範囲削除 2 + 繰越行の save 1)',
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

/** 繰越行の追記の呼び出し (TLM-5 の 5 条が closure の内側にあることを要求する)。 */
public const string APPEND_CALL = 'appendCarryForward';
```

> **件数は「実測 → 申告」の順で確定させる**。上の値は現行コード + 施策 1 の変更後コードから
> 手計算した見込みであり、実装では**まず gate を赤で走らせて実測を出し、その値を申告する**
> (合わない場合は理由を読んで、コード側が正しいのか申告が正しいのかを判断する)。

### gate の検査 (TLM-1 〜 TLM-7)

| id | 検査 | 落ちるもの |
|---|---|---|
| TLM-1 | 表名リテラルの出現ファイルと件数が目録と**完全一致** | 表名を新しい場所へ書く |
| TLM-2 | モデル参照 + 変更語彙の同居ファイルと件数が目録と**完全一致** | 台帳を変更しうる場所を無申告で足す / 登録済みファイルに 2 本目の変更経路を足す |
| TLM-3 | **TLM-2 の候補ファイル (モデル参照 or 表名リテラルを持つファイル) のうち**、削除語彙を持つのは畳み込みサービス 1 ファイルだけ (`app/` 全体の `delete(` を対象にするのではない) | 業務経路に台帳の削除を足す |
| TLM-4 | `withTrashed(` / `onlyTrashed(` の出現ファイルと件数が目録と完全一致。かつ**すべての出現が受理する 2 形のいずれかで、受け手が `App\Models\Organization` に解決される** (それ以外は未解決として失敗) | テナント境界を迂回する一般的な主キー取得への転用 / 他モデルの soft-delete 運用の無申告追加 / 変数受け手への書き換え |
| TLM-5 | 畳み込みの**変更操作がすべて同一トランザクション closure の内側にあり、ロックがその先頭にある** (下記 5 条) | ロックの外出し・順序逆転・別メソッドへの逃がし・追記だけ closure の外へ出す |
| TLM-6 | 目録が陳腐化していない (対象ファイルが実在 / 理由が 30 文字以上) | 残置した幽霊登録 |
| TLM-7 | 空振り検知 (走査ファイル数が `SCAN_FLOOR` 超 / 検出が非空 / 目録が非空) | 走査根の改名・移動で走査が壊れたこと |

### TLM-5 の 5 条 (「ロック順序」だけでは足りない)

`save()` は `appendCarryForward()` へ分離してあるので、
「`carryForwardOrganization()` の中の `delete(` とロックの順」だけを見ると
**追記だけを closure の外へ出す変更を見逃す**。次の 5 条をすべて満たすことを要求する。

1. `LOCK_ORDER_METHOD` (`carryForwardOrganization`) の**本体の内側**に、
   受け手が `DB` ファサードに解決される `transaction(` が**ちょうど 1 つ**ある
2. その `transaction(` の引数範囲 (closure) の内側に `lockForUpdate(` がある
3. closure の内側に**変更操作が 2 種類以上ある** (`delete(` が 2 つ以上 +
   追記の呼び出し `appendCarryForward(` が 1 つ) — **空振り検出**を兼ねる
4. `lockForUpdate(` の位置が、closure 内の**最初の変更操作より前**である
   (変更操作の語彙は変更語彙 ∪ `{appendCarryForward}`)
5. `LOCK_ORDER_METHOD` の本体のうち **closure の外側**に変更操作が**1 つも無い**。
   さらにファイル全体で `appendCarryForward(` の**呼び出しは 1 件だけ**である
   (定義は「直前が `function`」で除外されるので数えない)

### 負例 (gate 内の合成入力。AGENTS.md の「同じ PR で揃える 4 点」の (1))

7 変異をすべて赤にする。

1. ロックがトランザクションの**外**
2. ロックが削除の**後ろ**
3. ロック語彙が**別メソッドにだけ**ある
4. `DB::transaction` ごと**別メソッドへ逃がす** (メソッド本体へ閉じ込めていないと素通りする)
5. 受け手が `DB` ファサード**でない** `transaction(` は数えない
6. コメント・文字列中の `delete(` は数えない
7. **追記の呼び出し (`appendCarryForward(`) だけを closure の外へ移す**

加えて **正例** (施策 1 の変更後コードそのもの) が緑になること、
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
- `withTrashed` の受理構文を 2 形に絞るので、実装で `Organization::query()->withTrashed()` を
  選んだ場合も gate は通る。**それ以外の書き方 (変数受け手・長い連鎖) は gate が落とす**ので、
  実装側がそこへ流れたら gate ではなく実装を直す (受理集合を広げない)。
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
| N3 | **収束 (回帰)**: 同じ閾値で 2 回実行しても繰越行は増えない | 2 回目の `processed` が 0、行数と id が不変。**注意: このテストは v0 でも緑になる** (v0 は繰越行の `created_at` を実行時刻にするので 2 回目の候補にならない) ので、テストファーストの赤の起点には使わない。回帰として残す |
| N3b | **短絡が効いている**: 別の集約キーに期限超過の明細を置いて組織を列挙させ、既に繰越 1 行だけの集約キーは**入れ替えが起きない** | 触られない側の繰越行の **id が不変** (短絡条件 `rowCount === 1 && carryForwardRows === 1` を一時的に壊すと赤になることを実装時に確認する) |
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
| N18 | **失効した繰越行だけが残った組織も決着する** (C1 の回帰) | (1) 古い明細から未来に失効する繰越行を作る → (2) 時計を失効後へ進める → (3) その組織に取引明細は 1 行も無い状態で再実行 → (4) 繰越行が物理削除される → (5) `candidates` / `expiredRemaining` が新定義どおり (実行前 1 / 実行後 0)。**N4 の初期明細を消すだけでは列挙漏れを検出できない**ので独立したテストにする |
| N19 | 失敗した組織があるとき publication-ready が誤って true にならない | N8 (範囲超過で巻き戻る組織) の結果で `isPublicationReady()` が false、`unexpectedFailures = 1`、他組織は処理済み。**DB レベルの削除失敗は再現しない** (stub を挟まないと作れない) ので、失敗の注入は範囲検査で行う — この限界をテストのコメントに書く |

### 時計の固定 (時刻境界を扱うテストの前提)

第 2 段の寄与判定はサービス内の `$now`、観測ヘルパの群 SUM、`balance()` の 3 か所が
それぞれ現在時刻を読む。実行中に失効境界を跨ぐと残高保存テストが不安定になるので、
**時刻境界に依存するテストは `$this->freezeTime()` で時計を止める**
(Laravel の `InteractsWithTime` はテスト終了時に自動で戻すので後始末は不要。
本リポジトリの既存の作法は `$this->travelTo(...)` で、同じ trait である)。
N18 のように「失効後へ進める」ものは `$this->travelTo($expiry->addSecond())` を使う。

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
- **N3b の「id が不変」**: 短絡が効いていることの証拠として使う (id が変われば入れ替えが起きている)。
- **N18 の fixture**: 繰越行そのものを Factory で作らない (畳み込みの出力を使う)。
  1 回目の畳み込みで未来に失効する繰越行を作らせ、そのあと時計を進める。
  Factory で `kind = carry_forward` を直に作ると「畳み込みが本当にその形を作るか」を
  検証していないことになる。

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
| 17 | 列の欠落 (`delta_sum` が無い) | 例外 (`get_object_vars()` + `Assert::keyExists()`) |
| 18 | 余剰列がある | **例外にしない** (通る) |
| 19 | `row_count = 1.0` (float) | 例外 |
| 20 | `row_count = '1e3'` | 例外 |
| 21 | `row_count = true` | 例外 |
| 22 | `row_count` が PHP 整数範囲を超える 10 進文字列 | 例外 (**キャスト前に落ちる**) |
| 23 | `row_count = 0` | 例外 (`rowCount >= 1` の集約不変条件) |
| 24 | `carry_forward_rows > row_count` | 例外 |
| 25 | `carry_forward_rows = -1` | 例外 |
| 26 | 入力は**すべて `stdClass`** で作る (`fromRow(stdClass)` に合わせる) | — |

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
    - **許容される変更の切り分け** (「変更の例外は畳み込みだけ」ではない — 実装と食い違わせない):
      - **行の物理削除と残高スナップショットへの置換**を書いてよいのは
        畳み込みサービス**ただ 1 ファイル**である (削除語彙の許容も同様)
      - **台帳への通常の追記**と、既存の限定 backfill (`payment_intent_id` の
        1 列だけを null → 値で埋める UPDATE) は `TicketLedgerService` が持つ
      - **許容される変更サイトの正本は mutation inventory** であり、本書には件数を写さない
      これは**人間向けの規約**であり、gate が証明するのは
      「対象構文の範囲で無申告の変更サイトを増やせない」ことまでである
      (呼び出し側と共通処理側で語彙が分かれる形は検出できない)。
    - **保持期限の母集団は論理削除済み組織も含む**。`Organization` は `SoftDeletes` なので
      global scope の効く経路で組織を列挙すると退会済み組織の台帳が永久に畳まれない。
    - **決着対象の定義は 1 つとする** (取引明細 + **失効した繰越行**。
      寄与中の繰越行だけが対象外)。**組織の列挙・件数・監視は同じ述語を直接共有し、
      処理側は「失効済み」と「寄与中」の厳密な補集合となる 2 枝で実装する**
      (削除は 1 本の DELETE、集約は集約キーごとの GROUP BY で必要な形が違うため)。
      **補集合性は Feature テストと変異表が固定する**。定義がずれると
      「数えているのに処理されない行」が生まれ、`horizon` が恒久的に NG になる。
    - **列を落とす migration はコード先行**である (drop 先行にすると旧コードが
      `Undefined column` で落ちる。これは破壊条件の要約であり、
      **順序・rollback・maintenance window の判断の正本は
      `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**である。
      本書に手順を写さない)。
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
- **デプロイ順序を書かない** (「順序制約なし」も書かない)。順序と rollback の正本は
  `docs/billing-retention-runbook.md` の手順節であり、architecture.md はそこを参照する
  (2 か所に書くと必ず食い違う)。
- **旧結論の残骸を全数で点検する**。実装の最後に
  `grep -rn "順序制約\|migration 先行\|コード先行\|drop 先行" AGENTS.md docs/ database/migrations/ devnotes/20260824-1019-ticket-ledger-carry-forward-v1/`
  を走らせる。**これは「0 件であること」の検査ではない** — 順序に触れてよい場所があるため、
  **許容される hit を明示し、それ以外を人が分類する確認**である。
  - **運用文書として許容されるのは 3 つだけ**:
    (1) `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節
    (手順の正本) / (2) drop migration の docblock (破壊条件の要約) /
    (3) `AGENTS.md` 規約 21 (破壊条件の要約 1 行)
  - **設計・レビュー履歴 (本設計ディレクトリ配下) は別枠**である
    (記録であり運用文書ではないので、順序の記述が残っていてよい)
  上記以外に hit があれば消すか正本への参照へ書き換える。
  とくに `docs/architecture.md` に hit が出たら**必ず消す** (順序を書かない側に決めたため)。
- **「唯一の例外」の語も全数で点検する**。`grep -rn "唯一の例外" app/ tests/ docs/ AGENTS.md` を走らせ、
  台帳に関する記述が「行の物理削除・残高スナップショットへの置換を行う唯一の経路」へ
  限定されていることを確認する (「台帳への変更の唯一の経路」と読める記述を残さない)。

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
- **新節: `carried_forward_through` 撤去のデプロイ順序 (この節が順序の正本)**
  - 順序は **「新コード → drop migration」に固定**する。
    逆順にすると旧コードが `MAX(carried_forward_through)` の SELECT と
    繰越行の INSERT で `Undefined column` になる
  - **drop 後に旧コードへ単純 rollback できない**。戻すなら
    先に `down()` で列を戻してから旧コードへ戻す
  - `down()` は列を戻すが**値は復元しない** (既存の繰越行は終端が null として扱われる)。
    さらに **v1 が作った繰越行は `idempotency_key` が null** なので、旧コードへ戻して
    同じ集約キーを再処理したときの挙動は旧状態と同一にならない
    (「**列の値が戻らない**」だけでなく「**アプリケーションの状態の意味も完全には復元されない**」)
  - migration 先行が避けられない基盤なら maintenance window か手順の変更が必要
    (本リポジトリにデプロイ定義は無いので、現状この手順書が唯一の担保である)

---

## テストファースト手順 (どのテストを先に赤くするか)

| 段 | 操作 | 期待する赤 |
|---|---|---|
| 0 | `grep -rn "TICKET_LEDGER" tests/` で既存のグローバル定数・関数名を確認する | (赤ではなく前提確認) |
| 1 | `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` に **N1 / N2 / N11 / N12 / N14 / N18** を追加する (v0 のコードのまま) | N1 (失効済みが消えない) / N2 (`created_at` が now) / N11 (`carried_forward_through` が未分類 + `idempotency_key` が非 null) / N12・N14 (退会組織が畳まれない) / N18 (失効した繰越行だけの組織が決着しない) が赤。**N3 はここに置かない** (v0 でも緑になるため赤の起点にならない) |
| 2 | `tests/Unit/Billing/CarryForwardGroupTest.php` を追加する | クラスが無いので赤 |
| 3 | `tests/Support/InitialState/NullableStateColumnRegistry.php` の entry を削除し件数 pin を 60 にする | `NullInitialStateColumnClassificationTest` NI-1/NI-2 (実スキーマに列が残っている) が赤 |
| 4 | 施策 2 (`CarryForwardGroup`) を実装 | 段 2 が緑になる |
| 5 | 施策 1 (サービスの差し替え + 移設) を実装 | 段 1 が緑になる。`TicketLedgerReaderInventoryTest` 検査 1 が**新たに赤** (missing/phantom) |
| 6 | 施策 6 (読み手の目録の追随) — まず走査域に `DataTransferObjects/Billing` を足して赤 (`CarryForwardGroup` が未登録) を確認し、その後に登録を足す | 検査 1 / 検査 3 が緑になる |
| 7 | 施策 4 の migration を追加 | 段 3 が緑になる |
| 8 | 施策 5 の走査器 → 自己検査 (`tests/Unit/Architecture/`) を先に赤で置き、走査器を実装して緑にする | 走査器の負例が先に赤 |
| 9 | 施策 5 の gate を**目録の件数を空 / 0 のまま**置いて赤にし、**実測値を読んで申告**する | TLM-1〜4 が実測との不一致で赤 → 申告して緑 |
| 10 | 施策 7 の残り (旧テストの置き換え + **N3 / N3b / N4〜N10 / N13 / N15〜N17 / N19** の追加) を反映。**N3b は短絡条件を一時的に壊して赤を確認**してから元に戻す | 旧 3 本の削除と引き継ぎ先の追加で緑。N3b は変異で赤になることを実測 |
| 11 | 施策 3 / 9 (docblock・文書) を反映 | `BillingRetentionHorizonTest` / `BillingRetentionPurgeTest` が**無変更で緑**であることを確認 |
| 12 | AGENTS.md の検証コマンド**全数**: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 全 green |

| 13 | `devnotes/20260824-1019-ticket-ledger-carry-forward-v1/mutation-evidence.md` に v1 用の変異表を実測で書く (施策 10) | — |

> **mutation 根拠の再取得**: T144 は `devnotes/20260810-1143-todo-T144/mutation-evidence.md` に
> 変異表 (MU1〜MU8) を残している。v1 では MU8 (`carried_forward_through` の単調性) が
> **概念ごと消える**ので、`devnotes/{本ディレクトリ}/mutation-evidence.md` に
> **v1 用の変異表を作り直す** (最低限: 第 2 段の述語を落とす / `created_at` を now に戻す /
> 短絡を外す / 範囲検査を外す / 件数照合を外す / `withTrashed()` を外す /
> **決着対象から失効した繰越行を外す** の 7 変異が
> それぞれどのテストで赤になるかを実測して記録する)。

## migration / 後方互換の扱い

- **並走を残さない**: `carried_forward_through` 列 / 単調前進ロジック / 繰越行の冪等キー /
  旧サービスのファイルを**すべて同じ PR で消す**。feature flag も設定分岐も置かない。
- **schema の歴史は残す**: 列を足した migration (2026_08_10_114500) は消さず、
  drop migration を新規に足す (消すと新規環境で drop が失敗する)。
- **デプロイ順序は「新コード → drop migration」に固定する**。
  drop 先行は旧コードを壊し (旧コードが `carried_forward_through` を SELECT / INSERT する)、
  **drop 後に旧コードへ単純 rollback することもできない** (戻すなら先に `down()` で列を戻す)。
  **正本関係 (4 層)**: 手順・rollback の正本は
  `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節。
  **drop migration の docblock と `AGENTS.md` 規約 21 は破壊条件の要約だけ**を持つ
  (単独で読んでも危険が見える)。**`docs/architecture.md` は順序を書かず runbook を参照するだけ**。
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


## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php b/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php
index 9da936af..5fcaa2ac 100644
--- a/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php
+++ b/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php
@@ -13,10 +13,22 @@
  * 領域を作ると、そこに organization id やメールアドレスが載って運用ログへ漏れる。
  *
  * 件数の関係:
- *   candidates      = 起算済み・期限超過の件数 (purge 前)
- *   processed       = 実際に削除 (C2 では畳み込み) した件数
+ *   candidates      = 保持期限を超えた**決着対象**の件数 (purge 前)
+ *   processed       = 実際に決着させた件数 (削除した行数。台帳では畳み込みで消えた行数)
  *   failClosed      = 安全のため残した件数 (起算不能の異常 + 参照中で消せないもの)
- *   expiredRemaining = purge 後に残った起算済み・期限超過の件数
+ *   expiredRemaining = purge 後に残った決着対象の件数
+ *
+ * ★**「決着対象」の共通定義**: 各 target の保持ポリシーにより**物理削除または不可逆な
+ *   明細除去の対象となるレコード数**であり、**いま継続状態を表している集約レコードは含まない**。
+ *   台帳 (`ticket_ledger_entry`) では `kind = carry_forward` の繰越行のうち
+ *   **まだ残高に寄与しているもの (無期限 / 失効時刻が未来) だけ**がその集約レコードに該当する。
+ *   **失効した繰越行は決着対象に含まれる** — 残高に寄与しなくなった時点で物理削除の対象であり、
+ *   除外したままにすると「失効済みの繰越行だけが残った組織」が
+ *   永久に処理されないまま `remaining = 0` と報告される (fail-open)。
+ *   他の 6 target は集約レコードを持たないので実効値は変わらない。
+ *   **この定義が正本**であり、`docs/architecture.md` と
+ *   `docs/billing-retention-runbook.md` はここを参照する (2 か所に書くと必ず食い違う)。
+ *   **将来ほかの target が集約レコードを持ったら、この定義を読んで分類する義務がある。**
  *
  * **`failClosed` は「安全に残した」であって「規約を満たした」ではない**。
  * 規約 (最長 N 年) を満たしたと言えるのは `expiredRemaining === 0` のときだけである。
diff --git a/app/DataTransferObjects/Billing/CarryForwardGroup.php b/app/DataTransferObjects/Billing/CarryForwardGroup.php
new file mode 100644
index 00000000..bd4b423d
--- /dev/null
+++ b/app/DataTransferObjects/Billing/CarryForwardGroup.php
@@ -0,0 +1,192 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\Billing\TicketSource;
+use Carbon\CarbonImmutable;
+use DateTimeInterface;
+use stdClass;
+use Webmozart\Assert\Assert;
+
+/**
+ * 畳み込みの集約キー 1 件ぶん (DB 集計結果の境界 DTO)。
+ *
+ * 集計は Eloquent ではなくクエリビルダで行い、**cast を通らない生値**を受け取ってから
+ * ここで型を確定させる。モデル経由で `selectRaw` すると `source` が列挙型へ cast され、
+ * その値をさらに `TicketSource::from()` へ渡す二重変換で実行時エラーになるためである。
+ *
+ * ★**範囲検査は PHP `int` へ変換する前に行う**。`delta` 列は int4 なので、
+ *   合計が `[-2147483648, 2147483647]` を外れたら fail-closed で落とす。driver が
+ *   数値文字列で返す場合に先にキャストすると、**PHP 整数範囲を超える値が壊れた後で**
+ *   検査することになるため、**10 進文字列のまま**符号 + 桁数 + 辞書順で比較する。
+ *
+ * ★**列ごとの許容型** (bool / float / 指数表記 / 小数 / 空文字 / 前後空白つきは**すべて例外**):
+ *   - `source`: `null` はそのまま保持する / **文字列だけ** `TicketSource::from()` へ渡す
+ *     (未知の値は列挙型が例外にする) / それ以外の型は例外
+ *   - `expires_at`: `null` / 文字列 / `DateTimeInterface`
+ *   - `max_created_at`: **非 null** 必須。文字列 / `DateTimeInterface`
+ *   - `delta_sum`: `int` または 10 進整数の文字列。**int4 の範囲を変換前に検査**する
+ *   - `row_count` / `carry_forward_rows`: `int` または 10 進整数の文字列。
+ *     **PHP 整数の範囲を変換前に検査**したうえで非負であること
+ *
+ * ★**集約結果どうしの不変条件**も境界で見る (壊れた集計が収束判定へ流れないように)。
+ *     `rowCount >= 1` かつ `0 <= carryForwardRows <= rowCount`
+ *
+ * ★**引数は `stdClass` に狭める**。クエリビルダの `get()` が返すのは `stdClass` であり、
+ *   任意 object を許すと「`propertyExists()` は true だが `get_object_vars()` には
+ *   現れない private property」という穴が開く。読み出しは `get_object_vars()` +
+ *   `Assert::keyExists()` の 2 段で行う (動的プロパティ参照 `$row->$name` は使わない —
+ *   arch ベースラインの動的メンバ目録を太らせないため)。
+ *
+ * ★**想定外の余剰列は拒否しない**。集約 SQL は畳み込みサービスが組み立てるので余剰列は
+ *   入らず、拒否すると driver が付ける内部列で偽赤になりうる。**列の欠落は例外**にする。
+ */
+final readonly class CarryForwardGroup
+{
+    public function __construct(
+        public ?TicketSource $source,
+        public ?CarbonImmutable $expiresAt,
+        public int $deltaSum,
+        public CarbonImmutable $maxCreatedAt,
+        public int $rowCount,
+        public int $carryForwardRows,
+    ) {}
+
+    /** 生の集計行 (stdClass) を型の確定した DTO へ変換する (level 10 の narrowing はここ 1 箇所)。 */
+    public static function fromRow(stdClass $row): self
+    {
+        $source = self::nullableString($row, 'source');
+        $maxCreatedAt = self::nullableTimestamp($row, 'max_created_at');
+        Assert::notNull($maxCreatedAt, '集約の基準時刻 (max_created_at) が取得できない');
+
+        $rowCount = self::natural($row, 'row_count');
+        $carryForwardRows = self::natural($row, 'carry_forward_rows');
+        // 集約結果どうしの整合 (壊れた集計が収束判定へ流れないようにする)
+        Assert::greaterThanEq($rowCount, 1, '集約キーの行数が 1 未満である (集計が壊れている)');
+        Assert::lessThanEq($carryForwardRows, $rowCount, '繰越行の数が集約キーの行数を超えている');
+
+        return new self(
+            $source === null ? null : TicketSource::from($source),
+            self::nullableTimestamp($row, 'expires_at'),
+            self::int4($row, 'delta_sum'),
+            $maxCreatedAt,
+            $rowCount,
+            $carryForwardRows,
+        );
+    }
+
+    /** 列の読み出しの唯一の口 (存在しない列は表明で落とす)。 */
+    private static function value(stdClass $row, string $property): mixed
+    {
+        /** @var array<string, mixed> $values */
+        $values = get_object_vars($row);
+        Assert::keyExists($values, $property, "集計行に列 {$property} が無い");
+
+        return $values[$property];
+    }
+
+    /** 文字列列 (列挙値の生表現)。 */
+    private static function nullableString(stdClass $row, string $property): ?string
+    {
+        $value = self::value($row, $property);
+        if ($value === null) {
+            return null;
+        }
+        Assert::string($value, "集計行の列 {$property} が文字列ではない");
+
+        return $value;
+    }
+
+    /** 日時列 (driver によって文字列 / DateTimeInterface で返る)。 */
+    private static function nullableTimestamp(stdClass $row, string $property): ?CarbonImmutable
+    {
+        $value = self::value($row, $property);
+        if ($value === null) {
+            return null;
+        }
+        if ($value instanceof DateTimeInterface) {
+            return CarbonImmutable::instance($value);
+        }
+        Assert::stringNotEmpty($value, "集計行の列 {$property} が日時として解釈できない");
+
+        return CarbonImmutable::parse($value);
+    }
+
+    /** int4 の範囲に収まる整数 (**変換前に**範囲を判定する)。 */
+    private static function int4(stdClass $row, string $property): int
+    {
+        return self::decimalInt(
+            self::value($row, $property),
+            $property,
+            '2147483647',
+            '2147483648',
+            "繰越行の {$property} が delta 列 (signed integer) の範囲を超えた (この組織の処理を巻き戻す)",
+        );
+    }
+
+    /** 非負整数 (件数)。PHP 整数の範囲も**変換前に**判定する。 */
+    private static function natural(stdClass $row, string $property): int
+    {
+        $number = self::decimalInt(
+            self::value($row, $property),
+            $property,
+            (string) PHP_INT_MAX,
+            ltrim((string) PHP_INT_MIN, '-'),
+            "集計行の列 {$property} が PHP 整数の範囲を超えた",
+        );
+        Assert::natural($number, "集計行の列 {$property} が負である");
+
+        return $number;
+    }
+
+    /**
+     * `int` か 10 進整数の文字列だけを受け、**PHP `int` へ変換する前に**上下限を判定する。
+     *
+     * bool / float / 指数表記 / 小数 / 空文字 / 前後空白つきはすべて例外にする
+     * (`is_numeric()` や `Assert::integerish()` はこれらの一部を受理するので使わない)。
+     *
+     * @param  string  $positiveLimit  正側の上限の絶対値 (10 進文字列)
+     * @param  string  $negativeLimit  負側の下限の絶対値 (10 進文字列)
+     */
+    private static function decimalInt(
+        mixed $value,
+        string $property,
+        string $positiveLimit,
+        string $negativeLimit,
+        string $rangeMessage,
+    ): int {
+        if (is_int($value)) {
+            // `int` で来た値は PHP 整数の範囲内が保証されているので、絶対値の桁比較だけで足りる
+            Assert::true(
+                self::withinLimit((string) $value, $positiveLimit, $negativeLimit),
+                $rangeMessage,
+            );
+
+            return $value;
+        }
+
+        Assert::string($value, "集計行の列 {$property} が int でも文字列でもない");
+        Assert::regex($value, '/\A-?[0-9]+\z/', "集計行の列 {$property} が 10 進整数の表記ではない");
+        Assert::true(self::withinLimit($value, $positiveLimit, $negativeLimit), $rangeMessage);
+
+        return (int) $value;
+    }
+
+    /** 10 進文字列のまま上下限と比較する (符号 → 桁数 → 辞書順)。 */
+    private static function withinLimit(string $decimal, string $positiveLimit, string $negativeLimit): bool
+    {
+        $negative = str_starts_with($decimal, '-');
+        $digits = ltrim($negative ? substr($decimal, 1) : $decimal, '0');
+        if ($digits === '') {
+            return true; // 0 (`-0` / `000` を含む)
+        }
+        $limit = $negative ? $negativeLimit : $positiveLimit;
+        if (strlen($digits) !== strlen($limit)) {
+            return strlen($digits) < strlen($limit);
+        }
+
+        return strcmp($digits, $limit) <= 0;
+    }
+}
diff --git a/app/Enums/Billing/BillingRetentionTarget.php b/app/Enums/Billing/BillingRetentionTarget.php
index d988ce5d..208f75c2 100644
--- a/app/Enums/Billing/BillingRetentionTarget.php
+++ b/app/Enums/Billing/BillingRetentionTarget.php
@@ -76,7 +76,7 @@ public function clockStartColumn(): string
             self::SubscriptionItem => 'subscriptions.ends_at',
             self::Subscription => 'ends_at',
             // 台帳は取引成立の時点で起算済み (null にならない)。
-            // 決着は物理削除ではなく畳み込み (App\Services\Billing\TicketLedgerCarryForwardService)
+            // 決着は物理削除ではなく畳み込み (App\Services\Billing\Retention\TicketLedgerCarryForwardService)
             self::TicketLedgerEntry => 'created_at',
         };
     }
diff --git a/app/Models/Billing/TicketLedgerEntry.php b/app/Models/Billing/TicketLedgerEntry.php
index bd83a27e..c37eda11 100644
--- a/app/Models/Billing/TicketLedgerEntry.php
+++ b/app/Models/Billing/TicketLedgerEntry.php
@@ -26,10 +26,11 @@
  * purchase_amount を持ち、返金 (charge.refunded) の逆仕訳 (clawback) の正本になる。
  *
  * 保持期間 (7 年) の決着は**物理削除ではなく畳み込み**である
- * (`TicketLedgerCarryForwardService`)。期限超過の取引行は
+ * (`App\Services\Billing\Retention\TicketLedgerCarryForwardService`)。判定は 2 段で、
+ * 保持期限以前の行のうち**既に失効したものは物理削除**、**まだ残高に寄与するもの**だけが
  * `(organization_id, source, expires_at)` ごとに合算され、`kind = carry_forward` の
- * **残高スナップショット 1 行**へ置換される。置換後の行は `carried_forward_through` に
- * 集約期間の終端を持ち、原取引の識別子を 1 つも持たない。
+ * **残高スナップショット 1 行**へ置換される。繰越行の `created_at` は
+ * **畳み込んだ行の最大 `created_at`** (集約の基準時刻) であり、原取引の識別子を 1 つも持たない。
  *
  * 全カラムが TicketLedgerService の内部状態のため $fillable は持たない (明示代入のみ)。
  *
@@ -42,7 +43,6 @@
  * @property string $description
  * @property CarbonImmutable|null $granted_at
  * @property CarbonImmutable|null $expires_at
- * @property CarbonImmutable|null $carried_forward_through
  * @property string|null $stripe_checkout_session_id
  * @property string|null $stripe_invoice_id
  * @property string|null $payment_intent_id
@@ -96,7 +96,6 @@ protected function casts(): array
             'purchase_amount' => 'integer',
             'granted_at' => 'immutable_datetime',
             'expires_at' => 'immutable_datetime',
-            'carried_forward_through' => 'immutable_datetime',
             'created_at' => 'immutable_datetime',
         ];
     }
diff --git a/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php b/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php
new file mode 100644
index 00000000..077b203f
--- /dev/null
+++ b/app/Services/Billing/Retention/TicketLedgerCarryForwardService.php
@@ -0,0 +1,451 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\DataTransferObjects\Billing\CarryForwardGroup;
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\TicketLedgerService;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Query\Builder as QueryBuilder;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use RuntimeException;
+use stdClass;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 保持期限以前のチケット台帳の畳み込み。
+ *
+ * **台帳の行を物理削除し、残高スナップショット 1 行へ置換する唯一の経路**である
+ * (「台帳への変更の唯一の経路」ではない — `TicketLedgerService` は通常の追記と、
+ * `payment_intent_id` を null → 値で埋める限定 backfill を持つ)。
+ *
+ * `ticket_ledger_entries` は delta 型の追記専用台帳で、残高は
+ * 「未失効行の delta 合計 − reserved 予約の合計」である。古い行を単純に消すと残高が変わるため、
+ * **判定を 2 段**に分ける。
+ *
+ *  - 第 1 段 (適格性): `created_at <= 閾値`。**これを満たさない行は 1 行も触らない**
+ *  - 第 2 段 (処理方式。実行開始時に 1 度だけ確定した `$now` で判定する)
+ *    - 寄与しない (`expires_at` が非 null かつ `expires_at <= now`) → **物理削除**
+ *    - 寄与する (`expires_at` が null または `> now`) → **(組織, 出所, 失効時刻) ごとに
+ *      delta を合算した繰越 1 行へ畳み込む**
+ *
+ * 第 2 段の述語は {@see TicketLedgerService} の残高集計条件
+ * (`expires_at IS NULL OR expires_at > now`) の**厳密な補集合**である。ずらすと
+ * 「どちらの枝にも入らない行」か「両方に入る行」が生まれる。
+ *
+ * 繰越行は説明・決済事業者の識別子・冪等キー・予約への参照・個別の付与時刻を一切引き継がない。
+ * `created_at` は**畳み込んだ行の最大 `created_at`** = 集約の基準時刻である (実行時刻ではない)。
+ * 実行時刻にすると繰越行が次回以降ずっと保持期限より新しい側に居座り、実行のたびに増える。
+ * 集約の基準時刻なら次回も保持期限以前に留まるので、**集約キーごとに 1 行へ収束する**。
+ * 合計 delta が 0 の集約キーは繰越行を作らず削除だけ行う。
+ *
+ * ★**決着対象 (settlement scope) は 1 つの述語で定義する**。定義がずれると
+ *   「数えているのに処理されない行」が生まれる。**共有の範囲は正確に言う** —
+ *   `settlementPredicate()` を直接共有するのは
+ *   **組織の列挙 (`organizationsWithSettlementTargets`) と件数・監視 (`settlementScope`)** の 2 経路である。
+ *   **行の処理側は同じ集合を「厳密な補集合となる 2 枝」で実装する**
+ *   (`expiredScope()` = 失効済み / `contributingGroups()` + `groupScope()` = 寄与する行)。
+ *   処理側を同じ述語にできないのは、削除と集約で必要な形が違うからである
+ *   (前者は 1 本の DELETE、後者は集約キーごとの GROUP BY)。
+ *   **補集合であること (どちらの枝にも入らない行が無い / 両方に入る行が無い) は
+ *   N1・N18・境界時刻テスト・変異表が固定する**。
+ *
+ *       created_at <= 閾値
+ *       AND ( kind != carry_forward                                   -- 取引明細
+ *             OR (expires_at IS NOT NULL AND expires_at <= now) )     -- 失効した繰越行
+ *
+ *   繰越行のうち**まだ寄与している (無期限 or 未来に失効) もの**だけが決着対象から外れる
+ *   (継続状態を表す集約レコードであり、保持期限が消す対象ではない。
+ *   語の正本は {@see BillingRetentionPurgeResultDto} の docblock)。
+ *   **失効した繰越行は決着対象に戻る** — 残高に寄与しなくなった瞬間に物理削除の対象であり、
+ *   これを外すと「失効済みの繰越行しか持たない組織」が永久に処理されない
+ *   (= 失効窓の有界化が成立しない)。
+ *
+ * ★**母集団は論理削除済み組織も含む**。`Organization` は `SoftDeletes` であり、
+ *   global scope の効く経路で組織を列挙すると**退会済み組織の台帳が永久に畳まれない**
+ *   (期限超過が残り続けて保持期限の宣言が満たせなくなる)。よって列挙とロックの両方を
+ *   `withTrashed()` 起点にする。`withTrashed(` の出現は
+ *   `TicketLedgerMutationSiteGateTest` が本ファイルへ件数まで固定する
+ *   (テナント境界を迂回する一般的な主キー取得へ転用させない)。
+ *
+ * 直列化は組織行の排他ロック ({@see TicketLedgerService} が
+ * 残高判定の前に取るのと同じ点) で行う。組織 1 件 = 1 トランザクションで、
+ * 1 組織の失敗は他の組織を止めない。
+ *
+ * ★**ロックが守る範囲を誇張しない**。組織行ロックが直列化するのは
+ *   **同じロックを取る経路だけ**である — 畳み込み同士と、`TicketLedgerService` のうち
+ *   残高判定を伴う操作 (`grant` / `reserve` / `commit` / `release`)。
+ *   一方 `grantMonthly` / `grantPurchased` / `grantSignupGrant` / `clawback` の**冪等 insert は
+ *   このロックを取らない**ので、集計と削除の間に `created_at <= 閾値` の行が commit されうる。
+ *   その窓を閉じるのは**ロックではなく件数照合とトランザクションの巻き戻し**である
+ *   (`carryForwardOrganization` の手順 7)。二重の繰越行を防ぐのは
+ *   「**同一トランザクション内で削除 → 追記**」という順序であり、
+ *   ロックはそこへ他の畳み込みが割り込まないことだけを保証する。
+ *
+ * **append-only との関係**: モデルは `updating` / `deleting` を例外化しているが、
+ * Eloquent の一括削除はモデルイベントを発火しない。append-only は
+ * 「業務経路では追記しかしない」という不変条件であり、その例外は 2 種類ある —
+ * **行の削除・置換は保持期限の決着 (本ファイル) だけ**、
+ * **限定 metadata backfill は `TicketLedgerService::backfillPaymentIntentId()` だけ**である。
+ * 許容される変更サイトの正本は
+ * `Tests\Support\Architecture\TicketLedgerMutationInventory` である。
+ *
+ * **保証しないこと**: 真の並行実行 (別 connection + barrier) での排他の実効性は測っていない。
+ * 代わりに「台帳書き込みの既存経路と同じ組織行ロックを、変更より先に、同じトランザクションの
+ * 内側で取ること」を静的に pin する。
+ */
+final class TicketLedgerCarryForwardService
+{
+    /** 繰越行の説明 (個別明細を引き継がない集約状態であることを示す固定文言)。 */
+    public const string DESCRIPTION = '保持期限以前の明細の繰越 (集約)';
+
+    /**
+     * 繰越行が値を持つ列 (集約キー + 固定文言 + 主キー・時刻)。
+     *
+     * ★この 2 定数が「繰越行は明細を持たない」の**正本**である。
+     *   `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` の列分類検査が
+     *   「両者の和 == 実スキーマの全列」を deny-by-default で突き合わせるので、
+     *   表に列を足したら必ずどちらかへ分類することになる。
+     *
+     * @var list<string>
+     */
+    public const array VALUED_COLUMNS = [
+        'id', 'organization_id', 'delta', 'kind', 'source', 'expires_at', 'description', 'created_at',
+    ];
+
+    /**
+     * 繰越行では必ず NULL になる列 (取引の明細・決済事業者の識別子・冪等キー・予約参照)。
+     *
+     * @var list<string>
+     */
+    public const array NULL_COLUMNS = [
+        'reservation_id', 'granted_at', 'stripe_checkout_session_id', 'stripe_invoice_id',
+        'payment_intent_id', 'purchase_amount', 'idempotency_key',
+    ];
+
+    /**
+     * 保持期限以前の**決着対象**の件数 (寄与中の繰越行は数えない / 失効した繰越行は数える)。
+     *
+     * ★`BillingRetentionPurger` の署名に合わせて `$now` を受け取らない。dry-run 用の
+     *   単発の観測なので、ここでは呼び出し時点の現在時刻で判定する。
+     *   **1 回の実行の中で母集団を揃える必要がある `carryForward()` は、
+     *   自分が確定した `$now` を `settlementScope()` へ直接渡す** (下記)。
+     *
+     * 論理削除済み組織の行も数える (組織を結合しないので global scope は効かない)。
+     * 列挙側 (`organizationsWithSettlementTargets`) も `withTrashed()` なので
+     * **両者の母集団は一致する**。
+     */
+    public function countExpired(CarbonImmutable $threshold): int
+    {
+        return $this->settlementScope($threshold, CarbonImmutable::now())->count();
+    }
+
+    /**
+     * 保持期限以前の台帳を組織ごとに畳み込む。
+     *
+     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
+     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
+     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
+     */
+    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
+    {
+        // ★`$now` は 1 度だけ確定して全組織・全集約キーへ渡す。実行中に時計が進むと
+        //   「失効済み」と「寄与する」のどちらの枝にも入らない行が生まれる。
+        $now = CarbonImmutable::now();
+        $candidates = $this->settlementScope($threshold, $now)->count();
+        $processed = 0;
+        $unexpectedFailures = 0;
+
+        foreach ($this->organizationsWithSettlementTargets($threshold, $now) as $organization) {
+            try {
+                $processed += $this->carryForwardOrganization($organization, $threshold, $now);
+            } catch (Throwable $e) {
+                $unexpectedFailures++;
+                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
+                Log::warning('ticket ledger carry forward failed', [
+                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
+                    'organization_id' => $organization->getKey(),
+                    'error_class' => $e::class,
+                ]);
+            }
+        }
+
+        return new BillingRetentionPurgeResultDto(
+            target: BillingRetentionTarget::TicketLedgerEntry,
+            candidates: $candidates,
+            processed: $processed,
+            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
+            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
+            // (「安全のため残した」ではなく「決着できなかった」である)。
+            failClosed: 0,
+            unexpectedFailures: $unexpectedFailures,
+            // 残数も**同じ `$now`** で数える (実行中に時計が進むと候補と残数の母集団がずれる)
+            expiredRemaining: $this->settlementScope($threshold, $now)->count(),
+        );
+    }
+
+    /**
+     * **決着対象**の述語 (この 1 か所が唯一の定義。列挙・件数・監視が共有する)。
+     *
+     * 第 1 段の適格性 (`created_at <= 閾値`) を満たし、かつ
+     * 「取引明細である」または「失効した繰越行である」行。
+     *
+     * @return EloquentBuilder<TicketLedgerEntry>
+     */
+    private function settlementScope(CarbonImmutable $threshold, CarbonImmutable $now): EloquentBuilder
+    {
+        return TicketLedgerEntry::query()
+            ->where('created_at', '<=', $threshold)
+            ->where(fn (EloquentBuilder $query): EloquentBuilder => $this->settlementPredicate($query, $now));
+    }
+
+    /**
+     * 決着対象の内側の述語 (relation の `whereHas` からも同じものを使う)。
+     *
+     * ★モデルの型引数で汎用化してある。`whereHas` の closure は
+     *   `EloquentBuilder<Model>` として渡ってくるので、台帳モデルに固定すると
+     *   列挙側と件数側で**同じ述語を共有できなくなる** (述語が 2 本に割れる)。
+     *
+     * @template TModel of Model
+     *
+     * @param  EloquentBuilder<TModel>  $query
+     * @return EloquentBuilder<TModel>
+     */
+    private function settlementPredicate(EloquentBuilder $query, CarbonImmutable $now): EloquentBuilder
+    {
+        return $query
+            ->where('kind', '!=', TicketLedgerKind::CarryForward->value)
+            ->orWhere(fn (EloquentBuilder $expired): EloquentBuilder => $expired
+                ->where('kind', TicketLedgerKind::CarryForward->value)
+                ->whereNotNull('expires_at')
+                ->where('expires_at', '<=', $now));
+    }
+
+    /**
+     * 決着対象を持つ組織 (id 昇順 = ロック順序の固定)。
+     *
+     * ★`withTrashed()` が必須である。退会 (論理削除) は課金記録の寿命を縮めない
+     * (`docs/template-divergence.md` D23)。
+     * ★述語は `settlementPredicate()` を共有する (列挙と件数で条件が分岐しない)。
+     *
+     * @return Collection<int, Organization>
+     */
+    private function organizationsWithSettlementTargets(
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+    ): Collection {
+        return Organization::withTrashed()
+            ->whereHas(
+                'ticketLedgerEntries',
+                fn (EloquentBuilder $query): EloquentBuilder => $query
+                    ->where('created_at', '<=', $threshold)
+                    ->where(fn (EloquentBuilder $inner): EloquentBuilder => $this->settlementPredicate($inner, $now)),
+            )
+            ->orderBy('id')
+            ->get();
+    }
+
+    /**
+     * 1 組織ぶんの畳み込み。**順序が契約である**:
+     *   1. トランザクションを開く
+     *   2. 組織行を `lockForUpdate`
+     *   3. 寄与しない (失効済み) 行の物理削除
+     *   4. 寄与する行を集約キーごとに **1 文**で集計 (件数 / 合計 / 最大 created_at / 繰越行数)
+     *   5. 既に繰越 1 行だけの集約キーは短絡 (収束)
+     *   6. 集約キーの行を削除
+     *   7. **件数照合** (不一致は例外 → 組織ごと巻き戻る)
+     *   8. 繰越行の追記 (合計 0 は作らない)
+     *
+     * @return int 決着した (消えた) 行数
+     */
+    private function carryForwardOrganization(
+        Organization $organization,
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+    ): int {
+        // ★closure へは **`Organization` モデルそのもの**を渡す (id を先に取り出さない)。
+        //   `whereKey($organization->getKey())` の形にすることで、識別子が
+        //   **解決済みモデル由来**であることが走査器から見え、`DirectFetchInventory` の
+        //   母集団に入らない (id を捕まえた `whereKey($organizationId)` にすると候補になる)。
+        return DB::transaction(function () use ($organization, $threshold, $now): int {
+            // 残高判定・台帳追記の直列化点 (TicketLedgerService::lockOrganizationRow と同じ点)。
+            // 論理削除済み組織も対象なので withTrashed で取る。
+            Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
+
+            $organizationId = $organization->getKey();
+            Assert::integer($organizationId, '組織 id が解決できません (畳み込みは中止する)');
+
+            // (a) 残高に寄与しない期限以前の行 (失効済み) → 物理削除。
+            //     繰越行が失効済みになった場合もここで消える (= 失効窓の有界化)。
+            $processed = $this->deletedCount($this->expiredScope($organizationId, $threshold, $now)->delete());
+
+            // (b) 残高に寄与する期限以前の行 → 集約キーごとに畳み込む。
+            //     処理順は**決定的**にする (集約キーの並び順)。1 つの集約キーで失敗したときに
+            //     どこまで進んでいたかが実行のたびに変わると、巻き戻しの契約を測れない。
+            foreach ($this->contributingGroups($organizationId, $threshold, $now) as $group) {
+                // 既に繰越 1 行だけなら何もしない (無駄な入れ替えを避ける = 収束の短絡)
+                if ($group->rowCount === 1 && $group->carryForwardRows === 1) {
+                    continue;
+                }
+
+                $deleted = $this->deletedCount(
+                    $this->groupScope($organizationId, $threshold, $now, $group)->delete(),
+                );
+
+                // **集計した集合と削除した集合が一致することを確認する**。
+                // 組織行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
+                // ロックを取らない冪等 insert である)。集計と削除の間に
+                // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を
+                // 削除が巻き込む** = その枚数ぶん残高が消える。件数の不一致で検出し、
+                // トランザクションごと巻き戻す (次回の実行で同じ組織を再処理して収束する)。
+                if ($deleted !== $group->rowCount) {
+                    throw new RuntimeException(
+                        '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
+                    );
+                }
+
+                $processed += $deleted;
+
+                // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
+                if ($group->deltaSum !== 0) {
+                    $this->appendCarryForward($organizationId, $group);
+                }
+            }
+
+            return $processed;
+        });
+    }
+
+    /** Eloquent の一括削除は driver 実装まで型が確定しないので境界で数値に確定させる。 */
+    private function deletedCount(mixed $result): int
+    {
+        Assert::integer($result, '削除件数が整数で返らない (畳み込みを中止する)');
+
+        return $result;
+    }
+
+    /**
+     * 残高に寄与しない (既に失効した) 期限以前の行。
+     *
+     * @return EloquentBuilder<TicketLedgerEntry>
+     */
+    private function expiredScope(
+        int $organizationId,
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+    ): EloquentBuilder {
+        return TicketLedgerEntry::query()
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold)
+            ->whereNotNull('expires_at')
+            ->where('expires_at', '<=', $now);
+    }
+
+    /**
+     * 集約キーごとの集計結果。
+     *
+     * ★**クエリビルダで集計する** (Eloquent 経由だと `source` が列挙型へ cast され、
+     *   その値をさらに `TicketSource::from()` へ渡す二重変換で実行時に落ちる)。
+     * ★**件数・合計・最大 created_at・繰越行数を 1 文で取る**。分けて発行すると文ごとに
+     *   snapshot が変わり (READ COMMITTED)、「合計には入っていないが件数には入っている」行が
+     *   生まれて残高保存の検査そのものが壊れる。
+     *
+     * @return list<CarryForwardGroup>
+     */
+    private function contributingGroups(
+        int $organizationId,
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+    ): array {
+        $rows = DB::table('ticket_ledger_entries')
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold)
+            ->where(function (QueryBuilder $query) use ($now): void {
+                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
+            })
+            ->groupBy('source', 'expires_at')
+            ->selectRaw(
+                'source, expires_at, SUM(delta) AS delta_sum, MAX(created_at) AS max_created_at, '
+                .'COUNT(*) AS row_count, SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) AS carry_forward_rows',
+                [TicketLedgerKind::CarryForward->value],
+            )
+            ->orderBy('source')
+            ->orderBy('expires_at')
+            ->get();
+
+        $groups = [];
+        foreach ($rows as $row) {
+            // クエリビルダの行は stdClass である。境界 DTO は stdClass だけを受けるので
+            // ここで型を確定させる (driver 差で別の型が来たら fail-closed で落とす)。
+            Assert::isInstanceOf($row, stdClass::class, '集約行が stdClass ではない (畳み込みを中止する)');
+            $groups[] = CarryForwardGroup::fromRow($row);
+        }
+
+        return $groups;
+    }
+
+    /**
+     * 集約キー 1 件ぶんの行 (削除対象)。**繰越行も含む** (合算して 1 行へ置き換えるため)。
+     *
+     * @return EloquentBuilder<TicketLedgerEntry>
+     */
+    private function groupScope(
+        int $organizationId,
+        CarbonImmutable $threshold,
+        CarbonImmutable $now,
+        CarryForwardGroup $group,
+    ): EloquentBuilder {
+        $query = TicketLedgerEntry::query()
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold)
+            ->where(function (EloquentBuilder $inner) use ($now): void {
+                $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now);
+            });
+
+        $query = $group->source === null
+            ? $query->whereNull('source')
+            : $query->where('source', $group->source->value);
+
+        return $group->expiresAt === null
+            ? $query->whereNull('expires_at')
+            : $query->where('expires_at', $group->expiresAt);
+    }
+
+    /**
+     * 繰越行の追記 (生成点で初期状態を明示代入する。AGENTS.md 実装規約)。
+     *
+     * 所有権キー (`organization_id`) と FK (`reservation_id`) は relation 経由で代入する。
+     */
+    private function appendCarryForward(int $organizationId, CarryForwardGroup $group): void
+    {
+        $entry = new TicketLedgerEntry;
+        $entry->organization()->associate($organizationId);
+        $entry->delta = $group->deltaSum;
+        $entry->kind = TicketLedgerKind::CarryForward;
+        $entry->source = $group->source;               // 出所は保存する (集約キー)
+        $entry->expires_at = $group->expiresAt;        // 残高の窓は保存する (集約キー)
+        $entry->description = self::DESCRIPTION;
+        $entry->reservation()->associate(null);        // 予約への参照は引き継がない
+        $entry->granted_at = null;                     // 個別の付与時刻は引き継がない
+        $entry->stripe_checkout_session_id = null;     // 決済事業者の識別子は引き継がない
+        $entry->stripe_invoice_id = null;
+        $entry->payment_intent_id = null;
+        $entry->purchase_amount = null;
+        $entry->idempotency_key = null;                // 冪等キーは引き継がない
+        // created_at を明示代入してから save する (Eloquent は CREATED_AT が dirty なら上書きしない)。
+        // これは集約の基準時刻であり、実行時刻ではない (収束の要)。
+        $entry->created_at = $group->maxCreatedAt;
+        $entry->save();
+    }
+}
diff --git a/app/Services/Billing/Retention/TicketLedgerEntryPurger.php b/app/Services/Billing/Retention/TicketLedgerEntryPurger.php
index 1dc23c37..6af86117 100644
--- a/app/Services/Billing/Retention/TicketLedgerEntryPurger.php
+++ b/app/Services/Billing/Retention/TicketLedgerEntryPurger.php
@@ -7,7 +7,6 @@
 use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
 use App\Enums\Billing\BillingRetentionTarget;
 use App\Services\Billing\Contracts\BillingRetentionPurger;
-use App\Services\Billing\TicketLedgerCarryForwardService;
 use Carbon\CarbonImmutable;
 
 /**
diff --git a/app/Services/Billing/TicketLedgerCarryForwardService.php b/app/Services/Billing/TicketLedgerCarryForwardService.php
deleted file mode 100644
index c3fe1840..00000000
--- a/app/Services/Billing/TicketLedgerCarryForwardService.php
+++ /dev/null
@@ -1,376 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Services\Billing;
-
-use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
-use App\Enums\Billing\BillingRetentionTarget;
-use App\Enums\Billing\TicketLedgerKind;
-use App\Enums\Billing\TicketSource;
-use App\Models\Billing\TicketLedgerEntry;
-use App\Models\Organization;
-use Carbon\CarbonImmutable;
-use Illuminate\Database\Eloquent\Builder;
-use Illuminate\Database\Eloquent\Collection;
-use Illuminate\Database\Query\Builder as QueryBuilder;
-use Illuminate\Support\Facades\DB;
-use Illuminate\Support\Facades\Log;
-use RuntimeException;
-use stdClass;
-use Throwable;
-use Webmozart\Assert\Assert;
-
-/**
- * チケット台帳 (`ticket_ledger_entries`) の**保持期間 (7 年) の畳み込み**。
- *
- * 台帳は append-only の残高の真実源であり、古い行を物理削除すると**残高が変わる**。
- * よって保持期間の決着は削除ではなく畳み込み — 保持期限より古い行を
- * `(organization_id, source, expires_at)` の組ごとに合算し、合計 `delta` を持つ
- * **繰越行 1 行**へ置換する。
- *
- * ★**`organization_id` を group key に必ず含める**。含め忘れると組織を跨いで残高を
- *   合算する重大バグになる。残高の粒度が実際にこの 3 つで閉じることは
- *   {@see TicketLedgerService::balance()} の集計条件 (organization_id + source
- *   (purchased は `source IS NULL` も含む) + `expires_at IS NULL or > now`) と対応する。
- *   **`source IS NULL` (legacy 行) は独立した group** として扱う (purchased へ寄せると
- *   `sumActiveHolds` の legacy 除外規則と意味がズレる)。
- *
- * ★**繰越行は「取引記録」ではなく「現在残高のスナップショット」である**。
- *   原取引の識別子 (説明 / stripe id / payment intent / 予約 id / 冪等キー / 個別日時) を
- *   1 つも引き継がない — 引き継いだら「7 年より古い取引の情報が残る」ことになり、
- *   保持期間の意味が消える。引き継ぐのは残高の粒度を決める 3 つ
- *   (`organization_id` / `source` / `expires_at`) だけである。
- *   性質の違いは `kind = carry_forward` として型に出す (既存 kind へ相乗りしない)。
- *
- * ★**append-only 不変条件との関係**: 本サービスは Eloquent の delete guard を迂回する
- *   Query Builder 直書きで行を消す**唯一の**経路である ({@see TicketLedgerService} の
- *   `backfillPaymentIntentId` と同じ閉じ込め方)。「計上の事後改竄をしない」という
- *   append-only の意図は保たれる — 個別行の値を書き換えるのではなく、
- *   **保持期限を超えた区間ごと残高スナップショットへ置換する**操作だからである。
- *
- * ★**保証しないもの (誇張しない)**:
- *   - 畳み込み後は**原取引が復元できない**。返金逆仕訳 (`clawbackPurchasedByPaymentIntent`) /
- *     消費の冪等キー (`consume:{reservationId}`) / signup grant の部分 UNIQUE index は
- *     いずれも**畳み込まれた行に対しては効かなくなる**。7 年より古い決済への遅延返金や
- *     7 年前の予約の commit は現実には起きないが、「index が守っている」と言えるのは
- *     畳み込み前の行までである (signup grant の**正本**は
- *     `organizations.signup_tickets_granted_at` の条件付き UPDATE で、これは畳み込まれない)
- *   - **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` は
- *     台帳から消える。未失効の monthly が完全に消費済みという組み合わせでのみ
- *     `nearestMonthlyExpiry` の探索結果が変わる (残高は不変。既知窓としてテストで固定)
- */
-final class TicketLedgerCarryForwardService
-{
-    /** 繰越行の冪等キーの接頭辞。 */
-    public const string IDEMPOTENCY_KEY_PREFIX = 'carry_forward:';
-
-    /**
-     * 繰越行の説明。
-     *
-     * ★詳細設計は `description` も null にすると書いているが、実列は **NOT NULL** である
-     *   (`2026_06_11_091400_create_ticket_tables.php` を実読で確認)。列を nullable へ変える
-     *   代わりに**取引追跡情報を一切含まない固定文言**を入れる。原取引の説明は残らないため
-     *   「個別取引が復元不能」という要件は満たす。
-     */
-    public const string CARRY_FORWARD_DESCRIPTION = '保持期間の繰越 (残高スナップショット)';
-
-    /** 冪等キー / 集約終端の日時表現 (UTC 正規化)。 */
-    private const string KEY_TIME_FORMAT = 'Y-m-d\TH:i:s\Z';
-
-    /** 冪等キーで null を表す明示トークン (空文字との衝突を避ける)。 */
-    private const string NULL_TOKEN = 'null';
-
-    /** 起算済み (台帳は `created_at` が起算点) かつ期限超過の行数。 */
-    public function countExpired(CarbonImmutable $threshold): int
-    {
-        return TicketLedgerEntry::query()
-            ->where('created_at', '<=', $threshold)
-            ->count();
-    }
-
-    /**
-     * 繰越行の冪等キー。
-     *
-     * 形は `carry_forward:{orgId}:{source}:{expiresAt}:{threshold}` で固定する。
-     * **null は明示トークン `'null'`**、日時は **UTC 正規化**。
-     * **同一 group を同じ閾値で再実行すれば同じキーになる** (= UNIQUE が二重の繰越行を弾く)。
-     *
-     * ★キーの第 4 要素は**その実行の閾値**であって `carried_forward_through` (集約終端) では
-     *   ない。両者は普段一致するが、保持年数を延ばして閾値が過去へ動いた場合だけ食い違う
-     *   (終端は単調に進むので前回値を保つ)。**冪等の単位は「同じ入力で同じ実行をしたか」**
-     *   なので、キーは入力である閾値で決める。
-     *
-     * 既存の signup grant 部分 UNIQUE index の述語 (`idempotency_key LIKE 'signup_grant:%'`) とは
-     * 接頭辞が異なるため衝突しない。
-     */
-    public static function idempotencyKeyFor(
-        int $organizationId,
-        ?TicketSource $source,
-        ?CarbonImmutable $expiresAt,
-        CarbonImmutable $threshold,
-    ): string {
-        return implode(':', [
-            rtrim(self::IDEMPOTENCY_KEY_PREFIX, ':'),
-            (string) $organizationId,
-            $source === null ? self::NULL_TOKEN : $source->value,
-            $expiresAt === null ? self::NULL_TOKEN : $expiresAt->utc()->format(self::KEY_TIME_FORMAT),
-            $threshold->utc()->format(self::KEY_TIME_FORMAT),
-        ]);
-    }
-
-    /**
-     * 保持期限より古い台帳行を組織ごとに畳み込む。
-     *
-     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
-     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
-     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
-     */
-    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
-    {
-        $candidates = $this->countExpired($threshold);
-        $processed = 0;
-        $unexpectedFailures = 0;
-
-        foreach ($this->organizationsWithExpiredEntries($threshold) as $organization) {
-            try {
-                $processed += DB::transaction(
-                    fn (): int => $this->carryForwardOrganization($organization, $threshold),
-                );
-            } catch (Throwable $e) {
-                $unexpectedFailures++;
-                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
-                Log::warning('ticket ledger carry forward failed', [
-                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
-                    'organization_id' => $organization->getKey(),
-                    'error_class' => $e::class,
-                ]);
-            }
-        }
-
-        return new BillingRetentionPurgeResultDto(
-            target: BillingRetentionTarget::TicketLedgerEntry,
-            candidates: $candidates,
-            processed: $processed,
-            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
-            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
-            // (「安全のため残した」ではなく「決着できなかった」である)。
-            failClosed: 0,
-            unexpectedFailures: $unexpectedFailures,
-            expiredRemaining: $this->countExpired($threshold),
-        );
-    }
-
-    /**
-     * 期限超過の台帳行を持つ組織 (id 昇順 = ロック順序の固定)。
-     *
-     * @return Collection<int, Organization>
-     */
-    private function organizationsWithExpiredEntries(CarbonImmutable $threshold): Collection
-    {
-        return Organization::query()
-            ->whereHas(
-                'ticketLedgerEntries',
-                fn (Builder $query): Builder => $query->where('created_at', '<=', $threshold),
-            )
-            ->orderBy('id')
-            ->get();
-    }
-
-    /**
-     * 1 組織ぶんの畳み込み (organizations 行ロック下)。
-     *
-     * @return int 畳み込んだ (置換で消えた) 行数
-     */
-    private function carryForwardOrganization(Organization $organization, CarbonImmutable $threshold): int
-    {
-        // 残高判定・台帳追記の直列化点。reserve / commit と同じロックを取る
-        // (畳み込みの最中に同じ組織の残高が動かないようにする)
-        Organization::query()
-            ->whereKey($organization->getKey())
-            ->lockForUpdate()
-            ->firstOrFail();
-
-        $organizationId = $organization->getKey();
-        if (! is_int($organizationId)) {
-            throw new RuntimeException('組織 id が解決できません (畳み込みは中止する)');
-        }
-
-        $processed = 0;
-        foreach ($this->expiredGroups($organizationId, $threshold) as $group) {
-            $processed += $this->carryForwardGroup(
-                $organizationId,
-                $group->source,
-                $group->expires_at,
-                $threshold,
-            );
-        }
-
-        return $processed;
-    }
-
-    /**
-     * 期限超過行の group key 一覧 (`source` / `expires_at` の相異なる組)。
-     *
-     * @return Collection<int, TicketLedgerEntry>
-     */
-    private function expiredGroups(int $organizationId, CarbonImmutable $threshold): Collection
-    {
-        return TicketLedgerEntry::query()
-            ->where('organization_id', $organizationId)
-            ->where('created_at', '<=', $threshold)
-            ->select(['source', 'expires_at'])
-            ->distinct()
-            ->get();
-    }
-
-    /**
-     * 1 group を繰越行へ置換する。
-     *
-     * @return int 置換で消えた行数
-     */
-    private function carryForwardGroup(
-        int $organizationId,
-        ?TicketSource $source,
-        ?CarbonImmutable $expiresAt,
-        CarbonImmutable $threshold,
-    ): int {
-        // **件数・合計・前回終端は 1 文で取る**。3 回に分けると文ごとに snapshot が変わり
-        // (READ COMMITTED)、「合計には入っていないが件数には入っている」行が生まれうる。
-        $aggregate = $this->aggregateGroup($organizationId, $source, $expiresAt, $threshold);
-        $total = $aggregate['total'];
-        $through = $this->resolveThrough($aggregate['previousThrough'], $threshold);
-
-        // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
-        if ($total !== 0) {
-            $inserted = DB::table('ticket_ledger_entries')->insertOrIgnore([
-                'organization_id' => $organizationId,
-                'delta' => $total,
-                'kind' => TicketLedgerKind::CarryForward->value,
-                'source' => $source?->value,
-                // --- ここから下は取引追跡情報。繰越行は 1 つも引き継がない ---
-                'reservation_id' => null,
-                'description' => self::CARRY_FORWARD_DESCRIPTION,
-                'granted_at' => null,
-                'stripe_checkout_session_id' => null,
-                'stripe_invoice_id' => null,
-                'payment_intent_id' => null,
-                'purchase_amount' => null,
-                // --- 残高の粒度と集約終端 ---
-                'expires_at' => $expiresAt?->toDateTimeString(),
-                'carried_forward_through' => $through->toDateTimeString(),
-                'idempotency_key' => self::idempotencyKeyFor($organizationId, $source, $expiresAt, $threshold),
-                'created_at' => CarbonImmutable::now()->toDateTimeString(),
-            ]);
-
-            // 冪等キーの衝突 = 同一 group を同一閾値で二重に畳み込もうとしている
-            // (通常は起きない。同じ閾値の再実行では対象行が既に消えているため)。
-            // 起きうるのは「畳み込み済みの group へ、閾値より古い created_at の行が
-            // 後から入った」ときで、既存の繰越行へ足し込むには UPDATE が要る。
-            // ここで原取引を消すと繰越行 1 行ぶんの残高が失われるため fail-closed で中止する
-            // (トランザクションごと巻き戻り、この組織は unexpectedFailures として報告される)。
-            if ($inserted !== 1) {
-                throw new RuntimeException('繰越行の冪等キーが衝突しました (畳み込みを中止して巻き戻す)');
-            }
-        }
-
-        // 繰越行の created_at は now (= 閾値より後) なので、この削除の対象にならない
-        $deleted = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)->delete();
-
-        // **集計した集合と削除した集合が一致することを確認する**。
-        // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
-        // ロックを取らない冪等 insert であり、backfill / 取り込みも同様)。集計と削除の間に
-        // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を削除が巻き込む** =
-        // その枚数ぶん残高が消える。件数の不一致で検出し、トランザクションごと巻き戻す。
-        if ($deleted !== $aggregate['rows']) {
-            throw new RuntimeException(
-                '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
-            );
-        }
-
-        return $deleted;
-    }
-
-    /**
-     * group の件数・合計・前回終端を **1 文で** 取る。
-     *
-     * 分けて発行すると文ごとに snapshot が変わる (READ COMMITTED) ため、
-     * 「合計には入っていないが件数には入っている」行が生まれ、残高保存の検査そのものが壊れる。
-     *
-     * @return array{rows: int, total: int, previousThrough: string|null}
-     */
-    private function aggregateGroup(
-        int $organizationId,
-        ?TicketSource $source,
-        ?CarbonImmutable $expiresAt,
-        CarbonImmutable $threshold,
-    ): array {
-        $row = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)
-            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(delta), 0) AS delta_total, MAX(carried_forward_through) AS previous_through')
-            ->first();
-
-        if (! $row instanceof stdClass) {
-            throw new RuntimeException('台帳 group の集計に失敗しました (畳み込みを中止する)');
-        }
-
-        Assert::numeric($row->row_count);
-        Assert::numeric($row->delta_total);
-        Assert::nullOrString($row->previous_through);
-
-        return [
-            'rows' => (int) $row->row_count,
-            'total' => (int) $row->delta_total,
-            'previousThrough' => $row->previous_through,
-        ];
-    }
-
-    /**
-     * この繰越が集約した期間の終端。
-     *
-     * 既に繰越行を含む group (再畳み込み) では**前回の終端と今回の閾値の大きい方**を採り、
-     * 単調に進むことを保証する (保持年数を延ばすと閾値は過去へ動くため、閾値をそのまま
-     * 採ると集約済みの範囲を過小申告することになる)。
-     */
-    private function resolveThrough(?string $previous, CarbonImmutable $threshold): CarbonImmutable
-    {
-        if ($previous === null || $previous === '') {
-            return $threshold;
-        }
-
-        $parsed = CarbonImmutable::parse($previous);
-
-        return $parsed->greaterThan($threshold) ? $parsed : $threshold;
-    }
-
-    /**
-     * group を指す Query Builder (呼ぶたびに作り直す = 集計で汚れない)。
-     *
-     * ★Eloquent ではなく Query Builder を使う。台帳モデルは delete を例外化しており
-     *   (append-only guard)、畳み込みはその唯一の例外だからである。迂回を 1 箇所に閉じ込め、
-     *   「どこで消しているか」をコードで見えるようにする。
-     */
-    private function groupQuery(
-        int $organizationId,
-        ?TicketSource $source,
-        ?CarbonImmutable $expiresAt,
-        CarbonImmutable $threshold,
-    ): QueryBuilder {
-        $query = DB::table('ticket_ledger_entries')
-            ->where('organization_id', $organizationId)
-            ->where('created_at', '<=', $threshold);
-
-        if ($source === null) {
-            $query->whereNull('source');
-        } else {
-            $query->where('source', $source->value);
-        }
-
-        if ($expiresAt === null) {
-            $query->whereNull('expires_at');
-        } else {
-            $query->where('expires_at', $expiresAt);
-        }
-
-        return $query;
-    }
-}
diff --git a/database/migrations/2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries.php b/database/migrations/2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries.php
new file mode 100644
index 00000000..42184116
--- /dev/null
+++ b/database/migrations/2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * 繰越行の集約終端を表す専用列 `carried_forward_through` を落とす。
+ *
+ * 正典 v1 (二段判定・収束繰越形) では**繰越行の `created_at` が集約の基準時刻**であり
+ * (畳み込んだ行の最大 `created_at`)、集約単位ごとに 1 行へ収束するため、
+ * 終端を別列で単調前進させる必要が無くなった。書き手のいない列を残さない
+ * (AGENTS.md 思考原則 3「後方互換の並走を残さない」)。
+ *
+ * ★**列を足した migration (2026_08_10_114500) は消さない**。消すと新規環境で
+ *   この drop が失敗する (schema の歴史は歴史として残す)。
+ * ★**破壊条件の要約 (この 2 行だけをここに置く)**:
+ *   **コード先行が必須**である (drop 先行にすると、まだ動いている旧コードの
+ *   `MAX(carried_forward_through)` の集計と繰越行の INSERT が `Undefined column` で落ちる)。
+ *   **drop 後に旧コードへ単純 rollback できない**。
+ *   → **手順・rollback・maintenance window の判断の正本は
+ *   `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**である。
+ *   ここに手順を写さない (2 か所に書くと必ず食い違う)。
+ * ★`down()` は列を戻すだけで**値は復元しない** (新形の繰越行は集約終端を `created_at` で
+ *   表すので、復元すると嘘の値になる)。旧コードを再稼働させると既存の繰越行は
+ *   「終端が未記録 (null)」として扱われる。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
+            $table->dropColumn('carried_forward_through');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
+            // 値は復元しない (旧形の意味を持つ値を作れないため、すべて null で戻す)
+            $table->timestamp('carried_forward_through')->nullable()->after('expires_at');
+        });
+    }
+};
diff --git a/tests/Architecture/TicketLedgerMutationSiteGateTest.php b/tests/Architecture/TicketLedgerMutationSiteGateTest.php
new file mode 100644
index 00000000..6ade87e4
--- /dev/null
+++ b/tests/Architecture/TicketLedgerMutationSiteGateTest.php
@@ -0,0 +1,449 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\Architecture\TicketLedgerMutationInventory;
+use Tests\Support\Architecture\TicketLedgerMutationScanner;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+ * Architecture invariant: **追記専用チケット台帳 (`ticket_ledger_entries`) を変更する場所は
+ * deny-by-default の目録制** (家系の正典 v1)。
+ *
+ * ★なぜ要るか:
+ *   台帳モデルは `updating` / `deleting` を Eloquent イベントで例外化しているが、
+ *   **Eloquent の一括削除 (`Builder::delete()` / Query Builder) はモデルイベントを発火しない**。
+ *   つまり append-only は**コード上の規律**であって、静的な検査が無いと
+ *   「**行の物理削除・残高スナップショットへの置換**を書いてよいのは畳み込み 1 ファイルだけ」は
+ *   担保されない (台帳への変更そのものは `TicketLedgerService` の追記と限定 backfill も持つ)。
+ *   目録は「変更しうる場所を宣言なしに増やせない」ための摩擦である。
+ *
+ * ★**グローバル定数・グローバル関数を 1 つも宣言しない**。既存の
+ *   `TicketLedgerReaderInventoryTest` がグローバル定数 `TICKET_LEDGER_TABLE` /
+ *   `TICKET_LEDGER_MODEL_IDENTIFIER` とグローバル関数 `ticketLedgerScanFiles()` を宣言しており、
+ *   Pest は同一プロセスでテストファイルを読み込むので同名を宣言すると
+ *   `Cannot redeclare` で Architecture レーン全体が落ちる。
+ *
+ * ★この gate が保証するもの:
+ *   - TLM-1: 表名リテラルの出現ファイルと件数が目録と**完全一致**
+ *   - TLM-2: モデル参照 + 変更語彙の同居ファイルと件数が目録と**完全一致**
+ *   - TLM-3: **TLM-2 の候補ファイル (モデル参照 or 表名リテラルを持つファイル) のうち**
+ *     削除語彙を持つのは畳み込みサービス 1 ファイルだけ
+ *     (`app/` 全体の `delete(` を対象にするのではない)
+ *   - TLM-4: `withTrashed(` / `onlyTrashed(` の出現ファイルと件数が目録と完全一致。かつ
+ *     **すべての出現が受理する 2 形のいずれか**で受け手が `App\Models\Organization` に解決される
+ *     (それ以外は**未解決として失敗**する = fail-closed)
+ *   - TLM-5: 畳み込みの**変更操作がすべて同一トランザクション closure の内側にあり、
+ *     ロックがその先頭にある** (5 条。負例 7 変異で裏取り)
+ *   - TLM-6: 目録が陳腐化していない (対象ファイルが実在 / 理由が 30 文字以上)
+ *   - TLM-7: 空振り検知 (走査ファイル数 / 検出の非空 / 目録の非空)
+ *
+ * ★この gate が保証しないもの (誇張しない): 正本は
+ *   {@see TicketLedgerMutationScanner} の docblock である (本ファイルに写さない)。
+ *   要点だけ言えば、**変更経路の全数性は主張しない** —
+ *   呼び出し側と共通処理側で語彙が分かれる形は検出できないため、
+ *   「append-only の例外は畳み込み 1 ファイルだけ」は**人間向けのドメイン規約**
+ *   (AGENTS.md ドメイン固有規約 21) として置き、gate がそれを証明するとは書かない。
+ */
+
+/**
+ * `app/` 配下の走査結果。
+ *
+ * @return array<string, array{
+ *     tableLiterals: int,
+ *     model: bool,
+ *     mutations: int,
+ *     deletes: int,
+ *     trashed: int,
+ *     trashedUnresolved: list<string>,
+ * }>
+ */
+function ticketLedgerMutationScan(): array
+{
+    /** @var array<string, array{tableLiterals: int, model: bool, mutations: int, deletes: int, trashed: int, trashedUnresolved: list<string>}>|null $cache */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $scanned = [];
+    foreach (TrackedPhpSourceFiles::all(base_path()) as $file) {
+        if (! str_starts_with($file['relative'], 'app/')) {
+            continue;
+        }
+        $source = file_get_contents($file['absolute']);
+        if ($source === false) {
+            throw new RuntimeException('走査対象を読めない: '.$file['relative']);
+        }
+        $tokens = TicketLedgerMutationScanner::tokenize($source, $file['relative']);
+        $trashed = TicketLedgerMutationScanner::trashedScopes($file['relative'], $source, $tokens);
+
+        $scanned[$file['relative']] = [
+            'tableLiterals' => TicketLedgerMutationScanner::tableLiteralCount($tokens),
+            'model' => TicketLedgerMutationScanner::referencesLedgerModel($file['relative'], $source, $tokens),
+            'mutations' => TicketLedgerMutationScanner::verbCount(
+                $tokens,
+                TicketLedgerMutationInventory::MUTATION_VERBS,
+            ),
+            'deletes' => TicketLedgerMutationScanner::verbCount(
+                $tokens,
+                TicketLedgerMutationInventory::DELETE_VERBS,
+            ),
+            'trashed' => $trashed['count'],
+            'trashedUnresolved' => $trashed['unresolved'],
+        ];
+    }
+
+    $cache = $scanned;
+
+    return $cache;
+}
+
+/**
+ * 目録の {path: {count, reason}} を {path: count} へ落とす。
+ *
+ * @param  array<string, array{count: int, reason: string}>  $sites
+ * @return array<string, int>
+ */
+function ticketLedgerMutationExpected(array $sites): array
+{
+    $expected = [];
+    foreach ($sites as $path => $entry) {
+        $expected[$path] = $entry['count'];
+    }
+    ksort($expected);
+
+    return $expected;
+}
+
+/** 畳み込みサービスのソース (TLM-5 と正例で使う)。 */
+function ticketLedgerCarryForwardSource(): string
+{
+    $source = file_get_contents(base_path(TicketLedgerMutationInventory::CARRY_FORWARD_FILE));
+    expect($source)->toBeString();
+
+    return (string) $source;
+}
+
+/**
+ * 合成入力に対して TLM-5 の 5 条を判定する (負例・正例で共有)。
+ *
+ * @return list<string>
+ */
+function ticketLedgerLockOrderViolations(string $source): array
+{
+    return TicketLedgerMutationScanner::lockOrderViolations(
+        TicketLedgerMutationScanner::tokenize($source, 'lock-order-fixture'),
+        'fixture.php',
+        $source,
+        TicketLedgerMutationInventory::LOCK_ORDER_METHOD,
+        TicketLedgerMutationInventory::APPEND_CALL,
+        TicketLedgerMutationInventory::MUTATION_VERBS,
+        TicketLedgerMutationInventory::DELETE_VERBS,
+    );
+}
+
+test('TLM-1: 表名リテラルの出現ファイルと件数が目録と完全一致する', function (): void {
+    $detected = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['tableLiterals'] > 0) {
+            $detected[$path] = $result['tableLiterals'];
+        }
+    }
+    ksort($detected);
+
+    expect($detected)->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::tableLiteralSites()),
+        '台帳の表名リテラルを持つファイル / 件数が目録と食い違います。'
+        .'Tests\Support\Architecture\TicketLedgerMutationInventory::tableLiteralSites() を'
+        .'理由付きで更新してください (件数は完全一致)。',
+    );
+});
+
+test('TLM-2: モデル参照と変更語彙を同居させるファイルと件数が目録と完全一致する', function (): void {
+    $detected = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['model'] && $result['mutations'] > 0) {
+            $detected[$path] = $result['mutations'];
+        }
+    }
+    ksort($detected);
+
+    expect($detected)->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::mutationSites()),
+        '台帳を変更しうる場所 (モデル参照 + 変更語彙) が目録と食い違います。'
+        .'Tests\Support\Architecture\TicketLedgerMutationInventory::mutationSites() を'
+        .'理由付きで更新してください (件数は完全一致 = 既存ファイルに 2 本目の変更経路を足しても赤になる)。',
+    );
+});
+
+test('TLM-3: 削除語彙を持ってよいのは畳み込みサービス 1 ファイルだけである', function (): void {
+    $detected = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        // 候補は「モデル参照 or 表名リテラル」を持つファイルに限る
+        // (app/ 全体の delete( を対象にすると台帳と無関係な hit で信号が死ぬ)
+        if (! $result['model'] && $result['tableLiterals'] === 0) {
+            continue;
+        }
+        if ($result['deletes'] > 0) {
+            $detected[$path] = $result['deletes'];
+        }
+    }
+    ksort($detected);
+
+    expect($detected)->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::deleteSites()),
+        '台帳を参照するファイルに削除語彙が増えました。append-only の例外は'
+        .'畳み込みサービス 1 ファイルだけです。',
+    );
+});
+
+test('TLM-4: 論理削除 scope の出現が目録と完全一致し、受理する 2 形に解決できる', function (): void {
+    $detected = [];
+    $unresolved = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['trashed'] > 0) {
+            $detected[$path] = $result['trashed'];
+        }
+        foreach ($result['trashedUnresolved'] as $entry) {
+            $unresolved[] = $entry;
+        }
+    }
+    ksort($detected);
+
+    expect($detected)->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::trashedScopeSites()),
+        'withTrashed( / onlyTrashed( の出現が目録と食い違います。テナント境界を迂回する'
+        .'一般的な主キー取得への転用を防ぐため、件数まで申告してください。',
+    );
+
+    expect($unresolved)->toBe([],
+        '受理する 2 形 (Organization::withTrashed() / Organization::query()->withTrashed()) '
+        .'以外の書き方が現れました。同じファイルに Organization::query() が在ることを根拠に'
+        .'認定する形は fail-open なので受理集合を広げず、実装側を直してください。'
+        .PHP_EOL.implode(PHP_EOL, $unresolved));
+});
+
+test('TLM-5 (正例): 畳み込みは変更操作をすべてトランザクション closure の内側に置きロックを先頭に取る', function (): void {
+    $violations = TicketLedgerMutationScanner::lockOrderViolations(
+        TicketLedgerMutationScanner::tokenize(
+            ticketLedgerCarryForwardSource(),
+            TicketLedgerMutationInventory::CARRY_FORWARD_FILE,
+        ),
+        TicketLedgerMutationInventory::CARRY_FORWARD_FILE,
+        ticketLedgerCarryForwardSource(),
+        TicketLedgerMutationInventory::LOCK_ORDER_METHOD,
+        TicketLedgerMutationInventory::APPEND_CALL,
+        TicketLedgerMutationInventory::MUTATION_VERBS,
+        TicketLedgerMutationInventory::DELETE_VERBS,
+    );
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('TLM-5 (負例): 7 変異がすべて赤になる', function (string $label, string $source): void {
+    expect(ticketLedgerLockOrderViolations($source))
+        ->not->toBe([], "変異「{$label}」を検出できていません (検出力が無い)");
+})->with([
+    // 1. ロックがトランザクションの外
+    ['ロックがトランザクションの外', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                return DB::transaction(function () use ($o): int {
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 2. ロックが削除の後ろ
+    ['ロックが削除の後ろ', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(function () use ($o): int {
+                    $n = $this->expiredScope($o)->delete();
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 3. ロック語彙が別メソッドにだけある
+    ['ロックが別メソッドにだけある', <<<'PHP'
+        <?php
+        final class S {
+            private function lockRow($o): void {
+                Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+            }
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(function () use ($o): int {
+                    $this->lockRow($o);
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 4. DB::transaction ごと別メソッドへ逃がす
+    ['トランザクションごと別メソッドへ逃がす', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return $this->run($o);
+            }
+            private function run($o): int {
+                return DB::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 5. 受け手が DB ファサードでない transaction( は数えない
+    ['受け手が DB ファサードでない', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return Connection::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+        }
+        PHP],
+    // 6. コメント・文字列中の削除語彙は数えない (= 空振り検出が発火する)
+    ['削除語彙がコメント・文字列だけ', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    // $this->expiredScope($o)->delete(); は消した
+                    $sql = 'delete(';
+                    $this->appendCarryForward($o);
+                    return 0;
+                });
+            }
+        }
+        PHP],
+    // 7. 追記の呼び出しだけを closure の外へ移す
+    ['追記だけ closure の外', <<<'PHP'
+        <?php
+        final class S {
+            private function carryForwardOrganization($o): int {
+                $n = DB::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $x = $this->expiredScope($o)->delete();
+                    $x += $this->groupScope($o)->delete();
+                    return $x;
+                });
+                $this->appendCarryForward($o);
+                return $n;
+            }
+        }
+        PHP],
+]);
+
+test('TLM-5 (正例の合成入力): 規定どおりの形は誤検出しない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Services\Billing\Retention;
+        use App\Models\Organization;
+        use Illuminate\Support\Facades\DB;
+        final class S {
+            private function carryForwardOrganization($o): int {
+                return DB::transaction(function () use ($o): int {
+                    Organization::withTrashed()->whereKey(1)->lockForUpdate()->firstOrFail();
+                    $n = $this->expiredScope($o)->delete();
+                    $n += $this->groupScope($o)->delete();
+                    $this->appendCarryForward($o);
+                    return $n;
+                });
+            }
+            private function appendCarryForward($o): void {}
+        }
+        PHP;
+
+    expect(ticketLedgerLockOrderViolations($source))->toBe([]);
+});
+
+test('TLM-6: 目録が陳腐化していない (対象ファイルが実在し理由が 30 文字以上)', function (): void {
+    $violations = [];
+    $inventories = [
+        'tableLiteralSites' => TicketLedgerMutationInventory::tableLiteralSites(),
+        'mutationSites' => TicketLedgerMutationInventory::mutationSites(),
+        'deleteSites' => TicketLedgerMutationInventory::deleteSites(),
+        'trashedScopeSites' => TicketLedgerMutationInventory::trashedScopeSites(),
+    ];
+
+    foreach ($inventories as $name => $sites) {
+        foreach ($sites as $path => $entry) {
+            if (! is_file(base_path($path))) {
+                $violations[] = "{$name}: 実在しないファイルが登録されている ({$path})";
+            }
+            if (mb_strlen($entry['reason']) < 30) {
+                $violations[] = "{$name}: 理由が 30 文字未満である ({$path})";
+            }
+            if ($entry['count'] < 1) {
+                $violations[] = "{$name}: 件数が 1 未満である ({$path})";
+            }
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('TLM-7: 空振り検知 (走査ファイル数 / 検出 / 目録が非空である)', function (): void {
+    $scanned = ticketLedgerMutationScan();
+    expect(count($scanned))->toBeGreaterThan(TicketLedgerMutationInventory::SCAN_FLOOR);
+
+    // 走査根が生きている (母集団に代表パスが居る)
+    expect($scanned)->toHaveKey(TicketLedgerMutationInventory::CARRY_FORWARD_FILE);
+    expect($scanned)->toHaveKey(TicketLedgerMutationInventory::LEDGER_SERVICE_FILE);
+
+    // 検出そのものが非空である (抽出条件の綴り間違いで全部 0 になっていない)
+    $withTable = array_filter($scanned, static fn (array $r): bool => $r['tableLiterals'] > 0);
+    $withModel = array_filter($scanned, static fn (array $r): bool => $r['model']);
+    $withMutation = array_filter($scanned, static fn (array $r): bool => $r['mutations'] > 0);
+    $withTrashed = array_filter($scanned, static fn (array $r): bool => $r['trashed'] > 0);
+    expect($withTable)->not->toBeEmpty();
+    expect($withModel)->not->toBeEmpty();
+    expect($withMutation)->not->toBeEmpty();
+    expect($withTrashed)->not->toBeEmpty();
+
+    // 目録が非空である
+    expect(TicketLedgerMutationInventory::tableLiteralSites())->not->toBeEmpty();
+    expect(TicketLedgerMutationInventory::mutationSites())->not->toBeEmpty();
+    expect(TicketLedgerMutationInventory::deleteSites())->not->toBeEmpty();
+    expect(TicketLedgerMutationInventory::trashedScopeSites())->not->toBeEmpty();
+});
+
+test('TLM-2 の負のコントロール: 未申告の変更サイトを混ぜると exact-fit が点灯する', function (): void {
+    $detected = [];
+    foreach (ticketLedgerMutationScan() as $path => $result) {
+        if ($result['model'] && $result['mutations'] > 0) {
+            $detected[$path] = $result['mutations'];
+        }
+    }
+    $detected['app/Services/Billing/UndeclaredLedgerMutator.php'] = 1;
+    ksort($detected);
+
+    expect($detected)->not->toBe(
+        ticketLedgerMutationExpected(TicketLedgerMutationInventory::mutationSites()),
+    );
+});
diff --git a/tests/Architecture/TicketLedgerReaderInventoryTest.php b/tests/Architecture/TicketLedgerReaderInventoryTest.php
index 6ed43b82..af58cd8c 100644
--- a/tests/Architecture/TicketLedgerReaderInventoryTest.php
+++ b/tests/Architecture/TicketLedgerReaderInventoryTest.php
@@ -75,6 +75,7 @@
     'Services/Billing',
     'Console/Commands/Billing',
     'Enums/Billing',
+    'DataTransferObjects/Billing',
 ];
 
 /**
@@ -88,6 +89,10 @@
  * @var array<string, array{string, string}>
  */
 const TICKET_LEDGER_READER_INVENTORY = [
+    'DataTransferObjects/Billing/CarryForwardGroup.php' => [
+        'aggregate',
+        '畳み込みの集約結果の境界型。列名リテラル (source / expires_at) で生の集計行を型へ確定させるだけで個別行は読まない',
+    ],
     'Models/Billing/TicketLedgerEntry.php' => [
         'row_detail',
         '台帳モデルそのもの。列定義と append-only guard (update/delete の例外化) を持つ',
@@ -102,11 +107,11 @@
     ],
     'Services/Billing/TicketLedgerService.php' => [
         'aggregate',
-        '台帳の唯一の書き込み窓口。残高は source / expires_at 別の SUM で読み、個別取引行の識別子には依存しない',
+        '台帳の通常の追記の窓口 (追記と payment_intent_id の限定 backfill)。残高は source / expires_at 別の SUM で読み、個別取引行の識別子には依存しない',
     ],
-    'Services/Billing/TicketLedgerCarryForwardService.php' => [
+    'Services/Billing/Retention/TicketLedgerCarryForwardService.php' => [
         'row_detail',
-        '保持期間の畳み込み本体。期限超過の個別取引行を残高スナップショット 1 行へ置換する唯一の経路',
+        '保持期限の畳み込み本体 (二段判定)。失効済みの個別取引行を物理削除し、寄与する行を残高スナップショット 1 行へ置換する唯一の経路',
     ],
     'Services/Billing/Retention/TicketLedgerEntryPurger.php' => [
         'aggregate',
diff --git a/tests/Feature/Billing/TicketLedgerCarryForwardTest.php b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
index 050277ef..265c82f4 100644
--- a/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
+++ b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
@@ -6,28 +6,39 @@
 use App\Enums\Billing\TicketSource;
 use App\Models\Billing\TicketLedgerEntry;
 use App\Models\Organization;
-use App\Services\Billing\TicketLedgerCarryForwardService;
+use App\Services\Billing\Retention\TicketLedgerCarryForwardService;
 use App\Services\Billing\TicketLedgerService;
 use App\Support\Legal\BillingRetention;
 use Carbon\CarbonImmutable;
 use Illuminate\Database\Events\QueryExecuted;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
 
 /*
- * 保持期間 (7 年) の台帳畳み込み (PR-C2 / C2b) の挙動。
+ * 保持期限の台帳畳み込み (家系の正典 v1 = 二段判定・収束繰越形) の挙動。
  *
  * ★畳み込みは**会計上の残高を保存する操作**である。1 枚でも増減したら重大な不具合なので、
- *   「畳み込み前後で 7 種の観測値が一致する」ことを本ファイルが機械固定する
- *   (詳細設計 C2b の検証 1〜7)。
+ *   「畳み込み前後で残高の観測値が一致する」ことを本ファイルが機械固定する。
+ *
+ * ★判定は 2 段である。
+ *   - 第 1 段 (適格性): `created_at <= 閾値`。満たさない行は 1 行も触らない
+ *   - 第 2 段 (寄与判定): 失効済み (`expires_at <= now`) は**物理削除**、
+ *     寄与する行 (`expires_at IS NULL` または `> now`) だけを
+ *     `(organization_id, source, expires_at)` ごとに合算した繰越 1 行へ畳み込む
  *
  * ★繰越行は「取引記録」ではなく**現在残高のスナップショット**である。原取引の識別子
- *   (説明 / stripe id / payment intent / 予約 id / 冪等キー) は 1 つも引き継がない
- *   — 引き継ぐと「7 年より古い取引の情報が残る」ことになり保持期間の意味が消える。
+ *   (説明 / stripe id / payment intent / 予約 id / 冪等キー) は 1 つも引き継がない。
+ *   `created_at` は**畳み込んだ行の最大 `created_at`** (集約の基準時刻) であり実行時刻ではない
+ *   — 実行時刻にすると繰越行が実行のたびに増え、集約単位ごとに 1 行へ収束しない。
  */
 
 /**
  * 台帳の残高粒度ごとの合計 (organization_id / source / expires_at)。
  *
+ * ★**寄与する行だけ**を数える (`expires_at` が NULL または未来)。v1 では失効済みの行は
+ *   繰越に含めず物理削除されるのが**正しい挙動**なので、生の全行 SUM の一致を要求すると
+ *   正典の要求と矛盾する。残高に効く枚数が 1 枚も動かないことがここでの不変条件である。
+ *
  * **合計 0 の group は落とす**。畳み込みは残高に寄与しない行を作らないため、
  * 「0 の group が消えること」は残高の変化ではない。
  *
@@ -35,8 +46,12 @@
  */
 function ledgerBalanceByGroup(): array
 {
+    $now = CarbonImmutable::now();
     $totals = [];
     foreach (TicketLedgerEntry::query()->get() as $entry) {
+        if ($entry->expires_at !== null && $entry->expires_at->lessThanOrEqualTo($now)) {
+            continue; // 失効済み = 残高に寄与しない
+        }
         $key = implode('|', [
             $entry->organization_id,
             $entry->source?->value ?? 'null',
@@ -75,7 +90,7 @@ function ledgerBalancesByOrganization(): array
 }
 
 /**
- * 3 組織ぶんの「7 年より古い取引 + 新しい取引」を並べる。
+ * 3 組織ぶんの「保持期限以前の取引 + 新しい取引」を並べる。
  *
  * @return array{Organization, Organization, Organization}
  */
@@ -103,7 +118,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     TicketLedgerEntry::factory()->forOrganization($a)->createdAt(CarbonImmutable::now())
         ->purchased()->delta(5)->create();
 
-    // --- 組織 B: 7 年より古いが**まだ失効していない** monthly (残高に効いている)
+    // --- 組織 B: 保持期限以前だが**まだ失効していない** monthly (残高に効いている)
     [$b] = createOrganizationWithOwner('組織B');
     $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
     TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
@@ -122,6 +137,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 }
 
 test('検証 1〜4・7: 畳み込み前後で残高が 1 枚も変わらない (組織 / source / 失効時刻の粒度)', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     seedCarryForwardLedger($threshold);
 
@@ -133,7 +149,9 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 
     // 空振り検知: 実際に畳み込まれた (0 件で green になっていない)
     expect($result->candidates)->toBeGreaterThan(0);
-    expect($result->processed)->toBe($result->candidates);
+    // v1 の `processed` は**削除した行数**である。再畳み込みで既存の繰越行を消した分を
+    // 含みうるので候補数と一致するとは限らない (下限だけを固定する)。
+    expect($result->processed)->toBeGreaterThanOrEqual($result->candidates);
     expect($result->unexpectedFailures)->toBe(0);
     expect($result->expiredRemaining)->toBe(0);
     expect($result->failClosed)->toBe(0);
@@ -146,6 +164,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 });
 
 test('検証 5: 畳み込み後も消費の出所と失効境界の選択が変わらない', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     [, $b] = seedCarryForwardLedger($threshold);
 
@@ -167,6 +186,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 });
 
 test('繰越行は残高の粒度 3 つだけを引き継ぎ、取引追跡情報を 1 つも残さない', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
@@ -185,8 +205,6 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect($carry->delta)->toBe(40);
     expect($carry->source)->toBe(TicketSource::Purchased);
     expect($carry->expires_at)->toBeNull();
-    expect($carry->carried_forward_through?->toDateTimeString())
-        ->toBe($threshold->toDateTimeString());
 
     // 取引追跡情報は 1 つも残っていない (原取引が復元不能である)
     expect($carry->reservation_id)->toBeNull();
@@ -195,12 +213,15 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect($carry->payment_intent_id)->toBeNull();
     expect($carry->purchase_amount)->toBeNull();
     expect($carry->stripe_invoice_id)->toBeNull();
+    expect($carry->idempotency_key)->toBeNull();
     expect($carry->description)->not->toContain('cs_test_secret');
-    expect($carry->idempotency_key)->not->toContain('cs_test_secret');
-    expect($carry->created_at->greaterThan($threshold))->toBeTrue();
+
+    // ★`created_at` は**集約の基準時刻** (畳み込んだ行の最大 created_at) であって実行時刻ではない
+    expect($carry->created_at->toDateTimeString())->toBe($old->toDateTimeString());
 });
 
 test('group key は (organization_id, source, expires_at) の 3 つで、組織を跨いで合算しない', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$first] = createOrganizationWithOwner('第一組織');
@@ -217,6 +238,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 });
 
 test('source が null の legacy 行は独立した group として畳み込まれる (purchased へ寄せない)', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
@@ -233,6 +255,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 });
 
 test('合計 0 の group は繰越行を作らない (残高に寄与しない行を増やさない)', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
@@ -246,91 +269,339 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect(TicketLedgerEntry::query()->count())->toBe(0);
 });
 
-test('冪等キーは group と閾値で決まり、再実行で同じ値になる (null は明示トークン / 日時は UTC)', function (): void {
-    $through = CarbonImmutable::parse('2019-03-04 05:06:07', 'Asia/Tokyo');
-    $expiresAt = CarbonImmutable::parse('2018-12-31 15:00:00', 'UTC');
-
-    $withValues = TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through);
-    $withNulls = TicketLedgerCarryForwardService::idempotencyKeyFor(42, null, null, $through);
+test('新しい取引 (閾値より後) は 1 行も畳み込まれない', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
 
-    expect($withValues)->toBe('carry_forward:42:monthly:2018-12-31T15:00:00Z:2019-03-03T20:06:07Z');
-    expect($withNulls)->toBe('carry_forward:42:null:null:2019-03-03T20:06:07Z');
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold->addSecond())->purchased()->delta(3)->create();
 
-    // 再実行で同じ値になる (同一入力 → 同一キー)
-    expect(TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through))
-        ->toBe($withValues);
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
 
-    // 既存の signup_grant 部分 UNIQUE index の述語 (LIKE 'signup_grant:%') と衝突しない
-    expect($withValues)->not->toStartWith('signup_grant:');
+    expect($result->candidates)->toBe(0);
+    expect($result->processed)->toBe(0);
+    expect(TicketLedgerEntry::query()->count())->toBe(1);
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::Grant);
 });
 
-test('繰越行はさらに畳み込める (carried_forward_through が単調に進む)', function (): void {
+test('境界: created_at が閾値ちょうどの行は畳み込まれる (<= で判定する)', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     [$organization] = createOrganizationWithOwner();
 
     TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($threshold->subYearsNoOverflow(2))->purchased()->delta(15)->create();
+        ->createdAt($threshold)->purchased()->delta(3)->create();
 
-    // 1 回目: 2 年前の閾値で畳み込む (繰越行の created_at はその時点)
-    $firstThreshold = $threshold->subYearNoOverflow();
-    app(TicketLedgerCarryForwardService::class)->carryForward($firstThreshold);
+    $service = app(TicketLedgerCarryForwardService::class);
+    expect($service->countExpired($threshold))->toBe(1);
 
-    $first = TicketLedgerEntry::query()->sole();
-    expect($first->kind)->toBe(TicketLedgerKind::CarryForward);
-    $firstThrough = $first->carried_forward_through;
-    expect($firstThrough)->not->toBeNull();
+    $service->carryForward($threshold);
 
-    // 繰越行を「古い行」に見せるため created_at だけを過去へずらす (append-only guard を迂回する
-    // Query Builder 直書き。fixture の都合であり本番経路には無い操作である)
-    DB::table('ticket_ledger_entries')
-        ->where('organization_id', $organization->getKey())
-        ->update(['created_at' => $threshold->subMonthNoOverflow()]);
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
+});
 
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($threshold->subMonthsNoOverflow(2))->purchased()->delta(5)->create();
+test('検証 6: 畳み込み後も signup grant の org 生涯 1 回は marker が守る', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['signup_tickets_granted_at' => $old])->save();
 
-    // 2 回目: 現在の閾値で再畳み込み
-    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($threshold->subMonthsNoOverflow(3))->delta(20)
+        ->idempotencyKey('signup_grant:org:'.$organization->getKey())->create();
 
-    expect($result->processed)->toBe(2);
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
 
-    $second = TicketLedgerEntry::query()->sole();
-    expect($second->delta)->toBe(20);
-    expect($second->carried_forward_through?->greaterThan($firstThrough))->toBeTrue();
+    // 畳み込みで signup_grant 行 (= 部分 UNIQUE index が守っていた行) は消える。
+    // 「org 生涯 1 回」の**正本は organizations.signup_tickets_granted_at の条件付き UPDATE** であり、
+    // それは畳み込みの対象ではないので残る (index は保険であって正本ではない)。
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(0);
+    expect($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
 });
 
-test('畳み込み済み group に古い行が後から入ったら fail-closed (残高を失わない)', function (): void {
-    // 冪等キーは (group, 閾値) で決まるので、同じ閾値で 2 度目の繰越行は insert されない。
-    // そこで原取引だけ消すと**繰越行 1 行ぶんの残高が消える**ため、丸ごと巻き戻して報告する。
+test('[既知窓] 合計 0 の未失効 monthly group は畳み込みで失効境界の情報を失う', function (): void {
+    // 保持期限以前の付与と消費が相殺し、かつ失効時刻が**まだ未来**という組み合わせでのみ起きる。
+    // 残高は変わらない (0 のまま) が、消費境界の探索 (nearestMonthlyExpiry) が見る
+    // 「delta>0 の未失効 monthly 行」が消えるため、次の予約の consume_expires_at が変わる。
+    // 残高保存を優先し、この窓は受容する。
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
 
     TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->purchased()->delta(30)->create();
+        ->monthly($liveExpiry)->delta(25)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->consumed(25, $liveExpiry)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(10)->create();
+
+    $service = app(TicketLedgerService::class);
+    $balanceBefore = $service->availableTrueBalance($organization);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 残高は保存される (これが最優先の不変条件)
+    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
+
+    // 一方で「未失効 monthly の失効境界」は消えている (既知窓)
+    expect(TicketLedgerEntry::query()
+        ->where('source', TicketSource::Monthly)
+        ->where('delta', '>', 0)
+        ->whereNotNull('expires_at')
+        ->count())->toBe(0);
+});
+
+/*
+ * ---------------------------------------------------------------------------
+ * 正典 v1 (二段判定・収束繰越形) が要求する不変条件 (T259)
+ * ---------------------------------------------------------------------------
+ */
+
+test('N1: 失効済みの明細は繰越に含めず物理削除される', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    // 期限以前 + 既に失効している monthly (残高に 1 枚も寄与していない)
+    $expired = $threshold->subMonthsNoOverflow(6);
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($expired)->delta(100)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($expired)->consumed(40, $expired)->create();
+    // 寄与する行 (無期限 purchased)
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(50)->create();
+
+    $service = app(TicketLedgerService::class);
+    $balanceBefore = $service->availableTrueBalance($organization);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 失効済みの群は**繰越行を 1 行も作らず**消える
+    expect(TicketLedgerEntry::query()->whereNotNull('expires_at')->count())->toBe(0);
+    // 寄与する群だけが繰越行になる
+    $entries = TicketLedgerEntry::query()->get();
+    expect($entries)->toHaveCount(1);
+    expect($entries->firstOrFail()->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($entries->firstOrFail()->delta)->toBe(50);
+    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
+});
+
+test('N2: 繰越行の created_at は畳み込んだ行の最大 created_at である', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+
+    $oldest = $threshold->subYearsNoOverflow(3);
+    $middle = $threshold->subYearNoOverflow();
+    $newest = $threshold->subMonthsNoOverflow(2);
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($oldest)->purchased()->delta(1)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($middle)->purchased()->delta(2)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($newest)->purchased()->delta(4)->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->delta)->toBe(7);
+    expect($carry->created_at->toDateTimeString())->toBe($newest->toDateTimeString());
+});
+
+test('N3: 収束 — 同じ閾値で 2 回実行しても繰越行は増えない', function (): void {
+    // ★このテストは v0 (繰越行の created_at = 実行時刻) でも緑になるため**赤の起点にはならない**。
+    //   収束の回帰として残す (N3b が短絡そのものを見る)。
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->legacy()->delta(6)->create();
 
     $service = app(TicketLedgerCarryForwardService::class);
     $service->carryForward($threshold);
-    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(30);
 
-    // 同じ group へ「閾値より古い」行が後から入る (取り込み遅延 / 手動投入)
+    $afterFirst = TicketLedgerEntry::query()->orderBy('id')->pluck('delta', 'id')->all();
+    expect($afterFirst)->toHaveCount(2);
+
+    $second = $service->carryForward($threshold);
+
+    expect($second->processed)->toBe(0);
+    expect($second->unexpectedFailures)->toBe(0);
+    expect(TicketLedgerEntry::query()->orderBy('id')->pluck('delta', 'id')->all())->toBe($afterFirst);
+});
+
+test('N3b: 既に繰越 1 行だけの集約キーは入れ替えられない (収束の短絡)', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
+
+    $converged = TicketLedgerEntry::query()->sole();
+    expect($converged->kind)->toBe(TicketLedgerKind::CarryForward);
+    $convergedId = $converged->getKey();
+
+    // **別の集約キー**に期限超過の明細を置いて、組織を再び列挙させる
     TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->purchased()->delta(7)->create();
+        ->monthly($liveExpiry)->delta(9)->create();
+
+    $service->carryForward($threshold);
+
+    // 触られない側の繰越行は id ごと不変である (入れ替えが起きていない)
+    $still = TicketLedgerEntry::query()->whereKey($convergedId)->first();
+    expect($still)->not->toBeNull();
+    expect($still?->delta)->toBe(15);
+    expect(TicketLedgerEntry::query()->count())->toBe(2);
+});
+
+test('N4: 有界性 — 失効済みの窓を N 個置いても畳み込み後の行数が N に依存しない', function (int $windows): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    for ($i = 1; $i <= $windows; $i++) {
+        TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+            ->monthly($threshold->subMonthsNoOverflow($i))->delta(10)->create();
+    }
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(50)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->delta(4)->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 残るのは「未失効の monthly」+「無期限の purchased」の 2 行だけ (窓の数に依存しない)
+    expect(TicketLedgerEntry::query()->count())->toBe(2);
+})->with([[1], [5]]);
+
+test('N5: 既存の繰越行と後から入った古い明細は 1 行へ合算される', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearsNoOverflow(2);
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(15)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
+
+    // 同じ集約キーへ「閾値より古い」明細が後から入る (取り込み遅延 / 手動投入)
+    $later = $threshold->subMonthNoOverflow();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($later)->purchased()->delta(5)->create();
 
     $result = $service->carryForward($threshold);
 
-    expect($result->unexpectedFailures)->toBe(1);
-    expect($result->processed)->toBe(0);
-    expect($result->expiredRemaining)->toBe(1);
-    // 残高は 1 枚も失われていない (30 + 7)
-    expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(37);
+    expect($result->unexpectedFailures)->toBe(0);
+    expect($result->processed)->toBe(2); // 既存の繰越行 + 新しい明細
+
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($carry->delta)->toBe(20);
+    expect($carry->created_at->toDateTimeString())->toBe($later->toDateTimeString());
+});
+
+test('N6: 閾値が過去へ動いても残高が保存され繰越行が増えない', function (): void {
+    // 保持年数を延ばす (7 年 → もっと長く) と閾値は過去へ動く。旧実装はここで集約範囲を
+    // 専用列で単調前進させていたが、v1 は集約単位ごとに 1 行へ収束するので概念ごと不要である。
+    // 守りたい実害 (集約の二重計上・行の増殖) を直接見る。
+    $this->freezeTime();
+    $now = CarbonImmutable::now();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($now->subYearsNoOverflow(12))->purchased()->delta(15)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($now->subYearsNoOverflow(11))->purchased()->delta(5)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    $balancesBefore = ledgerBalancesByOrganization();
+
+    // 1 回目: 新しい方の閾値 (now - 5 年)
+    $service->carryForward($now->subYearsNoOverflow(5));
+    expect(TicketLedgerEntry::query()->count())->toBe(1);
+
+    // 2 回目: **過去へ戻った**閾値 (now - 9 年)
+    $service->carryForward($now->subYearsNoOverflow(9));
+
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->delta)->toBe(20);
+    expect(ledgerBalancesByOrganization())->toBe($balancesBefore);
+});
+
+test('N7: 合計が int4 上限ちょうどなら畳み込める', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(2147483646)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(1)->create();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($result->unexpectedFailures)->toBe(0);
+    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(2147483647);
 });
 
-test('集計の後に古い行が割り込んだら fail-closed (削除が合計に無い行を巻き込まない)', function (): void {
-    // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
+test('N8 / N17 / N19: 合計が int4 の範囲を超えたらその組織だけ巻き戻る', function (int $first, int $second) {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$overflowing] = createOrganizationWithOwner('溢れる組織');
+    [$healthy] = createOrganizationWithOwner('健全な組織');
+
+    TicketLedgerEntry::factory()->forOrganization($overflowing)->createdAt($old)
+        ->purchased()->delta($first)->create();
+    TicketLedgerEntry::factory()->forOrganization($overflowing)->createdAt($old)
+        ->purchased()->delta($second)->create();
+    TicketLedgerEntry::factory()->forOrganization($healthy)->createdAt($old)
+        ->purchased()->delta(12)->create();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 溢れた組織は巻き戻る (行が 1 つも消えていない)
+    expect($result->unexpectedFailures)->toBe(1);
+    expect(TicketLedgerEntry::query()->where('organization_id', $overflowing->getKey())->count())->toBe(2);
+    expect(TicketLedgerEntry::query()->where('organization_id', $overflowing->getKey())
+        ->where('kind', TicketLedgerKind::CarryForward)->count())->toBe(0);
+
+    // ★N17: 1 組織の失敗は他の組織を止めない
+    expect($result->processed)->toBe(1);
+    $healthyRow = TicketLedgerEntry::query()->where('organization_id', $healthy->getKey())->sole();
+    expect($healthyRow->kind)->toBe(TicketLedgerKind::CarryForward);
+
+    // ★N19: 失敗した組織があるとき publication-ready が誤って true にならない。
+    //   **DB レベルの削除失敗は再現しない** (stub を挟まないと作れない) ので、
+    //   失敗の注入は範囲検査で行う。この限界を承知したうえでの回帰である。
+    expect($result->isPublicationReady())->toBeFalse();
+    expect($result->expiredRemaining)->toBe(2);
+})->with([
+    'int4 上限 +1' => [2147483647, 1],
+    'int4 下限 -1' => [-2147483648, -1],
+]);
+
+test('N10: 集計の後に古い明細が割り込んだら fail-closed (削除が合計に無い行を巻き込まない)', function (): void {
+    // 組織行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
     // ロックを取らない冪等 insert)。集計と削除の間に `created_at <= 閾値` の行が入ると、
     // **合計に入っていない行を削除が巻き込む** = その枚数ぶん残高が消える。
-    // ここでは繰越行の INSERT を観測した瞬間に割り込み行を差し込んで、その窓を再現する。
+    // v1 は「削除 → 追記」の順なので、**集約 SELECT (delta_sum) を観測した直後**に差し込む。
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
@@ -340,7 +611,7 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
 
     $injected = false;
     DB::listen(function (QueryExecuted $query) use ($organization, $old, &$injected): void {
-        if ($injected || ! str_contains($query->sql, 'insert into "ticket_ledger_entries"')) {
+        if ($injected || ! str_contains($query->sql, 'delta_sum')) {
             return;
         }
         $injected = true;
@@ -369,117 +640,141 @@ function seedCarryForwardLedger(CarbonImmutable $threshold): array
     expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(30);
 });
 
-test('閾値が過去へ戻っても carried_forward_through は後退しない (単調性)', function (): void {
-    // 保持年数を延ばす (7 年 → もっと長く) と閾値は過去へ動く。既に「ここまで畳み込んだ」と
-    // 記録した終端を、後から短い値で上書きすると**集約済みの範囲を過小申告する**ことになる。
+test('N11: 繰越行の列分類 (明細を 1 列も持たない)', function (): void {
+    $this->freezeTime();
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
-    $now = CarbonImmutable::now();
-
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($now->subYearsNoOverflow(12))->purchased()->delta(15)->create();
 
-    // 1 回目: 新しい方の閾値 (now - 5 年) で畳み込む
-    $laterThreshold = $now->subYearsNoOverflow(5);
-    app(TicketLedgerCarryForwardService::class)->carryForward($laterThreshold);
-    expect(TicketLedgerEntry::query()->sole()->carried_forward_through?->toDateTimeString())
-        ->toBe($laterThreshold->toDateTimeString());
-
-    // 繰越行を「古い行」に見せる (fixture の都合。append-only guard を迂回する直書き)
-    DB::table('ticket_ledger_entries')
-        ->where('organization_id', $organization->getKey())
-        ->update(['created_at' => $now->subYearsNoOverflow(10)]);
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(40)->idempotencyKey('purchase:cs_test_secret')
+        ->create(['description' => 'チケット購入 (checkout session: cs_test_secret)']);
 
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($now->subYearsNoOverflow(11))->purchased()->delta(5)->create();
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
 
-    // 2 回目: **過去へ戻った**閾値 (now - 9 年) で再畳み込み
-    $earlierThreshold = $now->subYearsNoOverflow(9);
-    app(TicketLedgerCarryForwardService::class)->carryForward($earlierThreshold);
+    /** @var array<string, mixed> $row */
+    $row = (array) DB::table('ticket_ledger_entries')->sole();
 
-    $carry = TicketLedgerEntry::query()->sole();
-    expect($carry->delta)->toBe(20);
-    expect($carry->carried_forward_through?->toDateTimeString())
-        ->toBe($laterThreshold->toDateTimeString()); // 後退していない
+    // (1) kind が厳密に carry_forward
+    expect($row['kind'])->toBe(TicketLedgerKind::CarryForward->value);
+    // (2) description が固定文言と厳密一致
+    expect($row['description'])->toBe(TicketLedgerCarryForwardService::DESCRIPTION);
+    // (3) NULL_COLUMNS の全列が NULL
+    foreach (TicketLedgerCarryForwardService::NULL_COLUMNS as $column) {
+        expect($row[$column])->toBeNull($column.' は繰越行では NULL でなければならない');
+    }
+    // (4) VALUED_COLUMNS ∪ NULL_COLUMNS が実スキーマの全列と完全一致 (= (5) 未分類の列は失敗)
+    $columns = Schema::getColumnListing('ticket_ledger_entries');
+    sort($columns);
+    $declared = array_merge(
+        TicketLedgerCarryForwardService::VALUED_COLUMNS,
+        TicketLedgerCarryForwardService::NULL_COLUMNS,
+    );
+    sort($declared);
+    expect($declared)->toBe($columns,
+        '表に列を足したら繰越行での扱い (値を持つ / 必ず NULL) を分類してください');
 });
 
-test('新しい取引 (閾値より後) は 1 行も畳み込まれない', function (): void {
+test('N12 / N13: 論理削除済み (退会済み) 組織の明細も畳み込まれ残高が保存される', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
 
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($threshold->addSecond())->purchased()->delta(3)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(33)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(7)->create();
+
+    $balanceBefore = app(TicketLedgerService::class)->availableTrueBalance($organization);
+
+    $organization->delete(); // 退会 (SoftDeletes)
+    expect(Organization::query()->whereKey($organization->getKey())->exists())->toBeFalse();
 
     $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
 
-    expect($result->candidates)->toBe(0);
-    expect($result->processed)->toBe(0);
-    expect(TicketLedgerEntry::query()->count())->toBe(1);
-    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::Grant);
+    expect($result->processed)->toBe(2);
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    // N13: 論理削除済み組織でも残高が保存される
+    expect($carry->delta)->toBe(40);
+    expect(app(TicketLedgerService::class)->availableTrueBalance($organization))->toBe($balanceBefore);
 });
 
-test('境界: created_at が閾値ちょうどの行は畳み込まれる (<= で判定する)', function (): void {
+test('N14: 論理削除済み組織の期限超過明細は expiredRemaining に現れ、畳み込み後に 0 になる', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
 
-    TicketLedgerEntry::factory()->forOrganization($organization)
-        ->createdAt($threshold)->purchased()->delta(3)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(12)->create();
+    $organization->delete();
 
     $service = app(TicketLedgerCarryForwardService::class);
     expect($service->countExpired($threshold))->toBe(1);
 
-    $service->carryForward($threshold);
+    $result = $service->carryForward($threshold);
 
-    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(1);
+    expect($result->unexpectedFailures)->toBe(0);
+    expect($result->expiredRemaining)->toBe(0);
+    expect($result->isPublicationReady())->toBeTrue();
 });
 
-test('検証 6: 畳み込み後も signup grant の org 生涯 1 回は marker が守る', function (): void {
+test('N15 / N16: 決着対象の件数は繰越行を数えず、取引明細が残っていれば 0 にならない', function (): void {
+    $this->freezeTime();
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
     [$organization] = createOrganizationWithOwner();
-    $organization->forceFill(['signup_tickets_granted_at' => $old])->save();
 
     TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->monthly($threshold->subMonthsNoOverflow(3))->delta(20)
-        ->idempotencyKey('signup_grant:org:'.$organization->getKey())->create();
+        ->purchased()->delta(21)->create();
 
-    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
 
-    // 畳み込みで signup_grant 行 (= 部分 UNIQUE index が守っていた行) は消える。
-    // 「org 生涯 1 回」の**正本は organizations.signup_tickets_granted_at の条件付き UPDATE** であり、
-    // それは畳み込みの対象ではないので残る (index は保険であって正本ではない)。
-    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(0);
-    expect($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
+    // N15: 畳み込み後は 0 かつ繰越行は実在する (寄与中の集約レコードは決着対象ではない)
+    expect($service->countExpired($threshold))->toBe(0);
+    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::CarryForward)->count())->toBe(1);
+
+    // N16: 繰越行以外の適格行が 1 行あれば 0 にならない
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(3)->create();
+    expect($service->countExpired($threshold))->toBe(1);
 });
 
-test('[既知窓] 合計 0 の未失効 monthly group は畳み込みで失効境界の情報を失う', function (): void {
-    // 7 年より古い付与と消費が相殺し、かつ失効時刻が**まだ未来**という組み合わせでのみ起きる。
-    // 残高は変わらない (0 のまま) が、消費境界の探索 (nearestMonthlyExpiry) が見る
-    // 「delta>0 の未失効 monthly 行」が消えるため、次の予約の consume_expires_at が変わる。
-    // 残高保存を優先し、この窓は受容する (詳細設計 C2b「合計 0 の繰越行を作らない」)。
+test('N18: 失効した繰越行だけが残った組織も決着する', function (): void {
+    // 繰越行は **畳み込みの出力**として作る (factory で kind=carry_forward を直に作ると
+    // 「畳み込みが本当にこの形を作るか」を検証していないことになる)。
+    $now = CarbonImmutable::now();
+    $this->travelTo($now);
     $threshold = BillingRetention::threshold();
     $old = $threshold->subYearNoOverflow();
-    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    $expiry = $now->addMonthsNoOverflow(2); // 実行時点ではまだ寄与している
     [$organization] = createOrganizationWithOwner();
 
     TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->monthly($liveExpiry)->delta(25)->create();
-    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->monthly($liveExpiry)->consumed(25, $liveExpiry)->create();
-    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
-        ->purchased()->delta(10)->create();
+        ->monthly($expiry)->delta(20)->create();
 
-    $service = app(TicketLedgerService::class);
-    $balanceBefore = $service->availableTrueBalance($organization);
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
 
-    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($carry->expires_at?->toDateTimeString())->toBe($expiry->toDateTimeString());
 
-    // 残高は保存される (これが最優先の不変条件)
-    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
+    // 時計を失効後へ進める (組織には取引明細が 1 行も無い状態)
+    $this->travelTo($expiry->addSecond());
+    $laterThreshold = BillingRetention::threshold();
 
-    // 一方で「未失効 monthly の失効境界」は消えている (既知窓)
-    expect(TicketLedgerEntry::query()
-        ->where('source', TicketSource::Monthly)
-        ->where('delta', '>', 0)
-        ->whereNotNull('expires_at')
-        ->count())->toBe(0);
+    expect($service->countExpired($laterThreshold))->toBe(1);
+
+    $result = $service->carryForward($laterThreshold);
+
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(1);
+    expect($result->expiredRemaining)->toBe(0);
+    expect(TicketLedgerEntry::query()->count())->toBe(0);
 });
diff --git a/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php b/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php
index a29b1285..a2bc0cf0 100644
--- a/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php
+++ b/tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php
@@ -76,7 +76,7 @@
 const NULL_INITIAL_STATE_TEMPORAL_TYPES = ['timestamp', 'timestamptz', 'date'];
 
 /** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
-const NULL_INITIAL_STATE_COLUMN_COUNT = 61;
+const NULL_INITIAL_STATE_COLUMN_COUNT = 60;
 
 /**
  * 「初期状態の目印」区分の列 (現在値ちょうど。増えるときも減るときもここを書き換える)。
diff --git a/tests/Support/Architecture/TicketLedgerMutationInventory.php b/tests/Support/Architecture/TicketLedgerMutationInventory.php
new file mode 100644
index 00000000..9099da8a
--- /dev/null
+++ b/tests/Support/Architecture/TicketLedgerMutationInventory.php
@@ -0,0 +1,117 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Architecture;
+
+/**
+ * 追記専用チケット台帳の**変更サイトの目録** (deny-by-default + 件数完全一致)。
+ *
+ * ★**グローバル定数・グローバル関数を 1 つも宣言しない**。既存の
+ *   `tests/Architecture/TicketLedgerReaderInventoryTest.php` が
+ *   グローバル定数 `TICKET_LEDGER_TABLE` 等とグローバル関数 `ticketLedgerScanFiles()` を
+ *   宣言しており、Pest は同一プロセスでテストファイルを読み込むため、同名を宣言すると
+ *   `Cannot redeclare` で Architecture レーン全体が落ちる。目録と走査器は
+ *   クラス定数 / static メソッドに置く (`DirectFetchInventory` / `LedgerPins` と同じ作法)。
+ *
+ * ★**件数は「実測 → 申告」の順で確定させる**。gate を赤で走らせて実測を読み、
+ *   その値を申告する。合わないときは理由を読んでコード側が正しいのか申告が正しいのかを
+ *   判断する (緩めない)。
+ */
+final class TicketLedgerMutationInventory
+{
+    /** 畳み込みサービス (台帳の行を物理削除し残高スナップショットへ置換する唯一の経路)。 */
+    public const string CARRY_FORWARD_FILE = 'app/Services/Billing/Retention/TicketLedgerCarryForwardService.php';
+
+    /** 台帳の書き込み窓口。 */
+    public const string LEDGER_SERVICE_FILE = 'app/Services/Billing/TicketLedgerService.php';
+
+    /** 変更語彙。 @var list<string> */
+    public const array MUTATION_VERBS = [
+        'save', 'delete', 'truncate', 'insert', 'insertOrIgnore', 'update', 'upsert', 'forceDelete',
+    ];
+
+    /** 削除語彙。 @var list<string> */
+    public const array DELETE_VERBS = ['delete', 'truncate', 'forceDelete'];
+
+    /** 母集団の下限 (走査根取り違えの補助検出。現在 933 ファイル)。 */
+    public const int SCAN_FLOOR = 500;
+
+    /** 畳み込みのロック順序を見るメソッド名。 */
+    public const string LOCK_ORDER_METHOD = 'carryForwardOrganization';
+
+    /** 繰越行の追記の呼び出し (TLM-5 の 5 条が closure の内側にあることを要求する)。 */
+    public const string APPEND_CALL = 'appendCarryForward';
+
+    /** インスタンス化しない (目録の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 表名リテラルを持ってよいファイル => {count, reason} (全数申告 + 件数完全一致)。
+     *
+     * @return array<string, array{count: int, reason: string}>
+     */
+    public static function tableLiteralSites(): array
+    {
+        return [
+            self::CARRY_FORWARD_FILE => [
+                'count' => 1,
+                'reason' => '畳み込みの集計 (cast を通さないクエリビルダ) の対象表。集計を 1 文で取るため表名を直に書く',
+            ],
+            self::LEDGER_SERVICE_FILE => [
+                'count' => 2,
+                'reason' => '冪等 insert (insertOrIgnore) と payment_intent_id の backfill UPDATE。どちらも caster を通さない',
+            ],
+        ];
+    }
+
+    /**
+     * モデル参照 + 変更語彙を同居させてよいファイル => {count, reason}
+     * (`count` は**変更語彙の出現数**)。
+     *
+     * @return array<string, array{count: int, reason: string}>
+     */
+    public static function mutationSites(): array
+    {
+        return [
+            self::CARRY_FORWARD_FILE => [
+                'count' => 3,
+                'reason' => '行の物理削除と残高スナップショットへの置換を行う唯一の経路 (範囲削除 2 + 繰越行の save 1)',
+            ],
+            self::LEDGER_SERVICE_FILE => [
+                'count' => 7,
+                'reason' => '台帳の追記 (appendEntry の save + 冪等 insert) と予約行の状態遷移 (save 4) と backfill の update 1。削除語彙は持たない',
+            ],
+        ];
+    }
+
+    /**
+     * 削除語彙を持ってよいファイル (畳み込み 1 ファイルだけ)。
+     *
+     * @return array<string, array{count: int, reason: string}>
+     */
+    public static function deleteSites(): array
+    {
+        return [
+            self::CARRY_FORWARD_FILE => [
+                'count' => 2,
+                'reason' => '失効済みの行の範囲削除と、集約キーごとの行の範囲削除。行の物理削除は append-only の唯一の例外である',
+            ],
+        ];
+    }
+
+    /**
+     * 論理削除の scope を使ってよいファイル => {count, reason}。
+     *
+     * @return array<string, array{count: int, reason: string}>
+     */
+    public static function trashedScopeSites(): array
+    {
+        return [
+            self::CARRY_FORWARD_FILE => [
+                'count' => 2,
+                'reason' => '退会 (論理削除) 済み組織の台帳も保持期限の対象である。組織の列挙と組織行ロックの 2 箇所だけ',
+            ],
+        ];
+    }
+}
diff --git a/tests/Support/Architecture/TicketLedgerMutationScanner.php b/tests/Support/Architecture/TicketLedgerMutationScanner.php
new file mode 100644
index 00000000..b73c1acc
--- /dev/null
+++ b/tests/Support/Architecture/TicketLedgerMutationScanner.php
@@ -0,0 +1,499 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Architecture;
+
+use RuntimeException;
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReferenceKind;
+
+/**
+ * 追記専用チケット台帳の**変更サイト**を検出する走査器 (純関数)。
+ *
+ * ## 走査対象
+ *
+ * - 母集団は利用側 gate が渡す (`Tests\Support\TrackedPhpSourceFiles::all()` のうち
+ *   `app/` 配下)。**同じ列挙を 2 本持たない**ため、ここでは列挙しない
+ * - トークン化は {@see ArchTokenStream::significantTokens()}
+ *   (`TOKEN_PARSE` + `ParseError` → 例外)。**解析できない入力は無言で空にせず落とす**
+ * - **モデル参照は 2 つの判定の和** (拾いすぎ側 = fail-closed):
+ *     (i) {@see PhpReferenceScanner::references()} が返す site のうち `name` が
+ *         `App\Models\Billing\TicketLedgerEntry` に一致する (NameReference / Construction)、
+ *         または StaticCall の receiver が同 FQCN に解決されるもの
+ *     (ii) 正規化トークン列に短名 `TicketLedgerEntry` が `T_STRING` として現れるもの
+ *   走査器は「型宣言 / `::class` / `instanceof` の位置を emit しない」と明言しているので、
+ *   そこは短名一致 (ii) で埋める。和なので判定は**拾いすぎ側**へ倒れる
+ * - **表名リテラル**は `T_CONSTANT_ENCAPSED_STRING` の引用符を外した値が
+ *   `ticket_ledger_entries` に**完全一致**する出現の数
+ * - **変更語彙 / 削除語彙**は「識別子 + 直後が `(`」かつ「直前が `function` でない」位置の数。
+ *   **区切りの宣言**: 判定は**トークン単位の完全一致**であり、部分文字列一致に頼らない
+ *   (`presave(` / `unsave(` / `saveAll(` はいずれも別トークンなので数えない)
+ * - **論理削除 scope** (`withTrashed(` / `onlyTrashed(`) は同じ規則で数え、加えて
+ *   **受理する構文を 2 形に固定**する。それ以外は**未解決として利用側に返す** (fail-closed):
+ *     (A) `Organization::withTrashed()` — 受け手が `App\Models\Organization` に解決される
+ *     (B) `Organization::query()->withTrashed(` — トークン列そのものの一致
+ *         (`T_STRING(Organization)` `::` `query` `(` `)` `->` `T_STRING(withTrashed)` `(`)
+ *   変数受け手 (`$query->withTrashed()`) や長い連鎖は**受理しない**
+ *   (同じファイルに `Organization::query()` が在ることを根拠に認定する形は fail-open)
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * 1. **呼び出し側に表名・共通 helper 側に削除語彙という「分離」は検出できない**
+ * 2. 定数・列挙型・変数を経由した表名 (`DB::table(self::TABLE)`) は追えない
+ * 3. 可変メソッド名 (`$row->{$verb}()`) / repository / service 境界を越える削除は追えない
+ * 4. 到達解析は行わない (到達不能なコードの語彙も数える)
+ * 5. **真の並行実行での排他の実効性は見ない** (見るのはトークン順の構造まで)
+ * 6. 受け手が完全に動的で、ファイル内にモデルの短名も表名リテラルも現れない形は検出しない
+ *
+ * したがって本走査器と利用側 gate が主張するのは
+ * 「**対象構文の範囲で**、モデル参照または表名リテラルと変更語彙が同一ファイルに現れる
+ * 変更サイトを deny-by-default で固定する」ことまでであり、**変更経路の全数性は主張しない**。
+ */
+final class TicketLedgerMutationScanner
+{
+    /** 台帳モデルの完全修飾名。 */
+    public const string LEDGER_MODEL = 'App\Models\Billing\TicketLedgerEntry';
+
+    /** 台帳モデルの短名 (拾いすぎ側の判定に使う)。 */
+    public const string LEDGER_MODEL_SHORT = 'TicketLedgerEntry';
+
+    /** 台帳の表名。 */
+    public const string LEDGER_TABLE = 'ticket_ledger_entries';
+
+    /** 組織モデルの完全修飾名 (論理削除 scope の受理形の受け手)。 */
+    public const string ORGANIZATION_MODEL = 'App\Models\Organization';
+
+    /** トランザクションの受け手として受理する facade。 */
+    public const string DB_FACADE = 'Illuminate\Support\Facades\DB';
+
+    /** 論理削除 scope の語彙。 @var list<string> */
+    public const array TRASHED_SCOPE_VERBS = ['withTrashed', 'onlyTrashed'];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 正規化済みトークン列 (解析できない入力は例外)。
+     *
+     * @return list<array{id: int|null, text: string, line: int}>
+     */
+    public static function tokenize(string $source, string $context): array
+    {
+        return ArchTokenStream::significantTokens($source, $context);
+    }
+
+    /**
+     * 表名リテラルの出現数 (引用符を外した値の完全一致)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function tableLiteralCount(array $tokens): int
+    {
+        $count = 0;
+        foreach ($tokens as $token) {
+            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+                continue;
+            }
+            if (self::literalValue($token['text']) === self::LEDGER_TABLE) {
+                $count++;
+            }
+        }
+
+        return $count;
+    }
+
+    /**
+     * 台帳モデルを参照しているか (完全修飾名の解決 ∪ 短名一致)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function referencesLedgerModel(string $relativePath, string $source, array $tokens): bool
+    {
+        foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
+            if ($site->kind === ReferenceKind::StaticCall) {
+                if ($site->receiver->is(self::LEDGER_MODEL)) {
+                    return true;
+                }
+
+                continue;
+            }
+            if ($site->name === self::LEDGER_MODEL) {
+                return true;
+            }
+        }
+
+        foreach ($tokens as $token) {
+            if ($token['id'] === T_STRING && $token['text'] === self::LEDGER_MODEL_SHORT) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 語彙の呼び出し位置の数 (識別子 + 直後が `(` かつ直前が `function` でない)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $verbs
+     */
+    public static function verbCount(array $tokens, array $verbs): int
+    {
+        return count(self::verbPositions($tokens, $verbs));
+    }
+
+    /**
+     * 語彙の呼び出し位置 (添字のリスト)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $verbs
+     * @return list<int>
+     */
+    public static function verbPositions(array $tokens, array $verbs): array
+    {
+        $positions = [];
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_STRING || ! in_array($tokens[$i]['text'], $verbs, true)) {
+                continue;
+            }
+            if (! ArchTokenStream::isPunctuation($tokens, $i + 1, '(')) {
+                continue;
+            }
+            if (($tokens[$i - 1]['id'] ?? null) === T_FUNCTION) {
+                continue; // メソッド定義であって呼び出しではない
+            }
+            $positions[] = $i;
+        }
+
+        return $positions;
+    }
+
+    /**
+     * 論理削除 scope の出現数と、**受理形に当てはまらなかった出現** (fail-closed の材料)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{count: int, unresolved: list<string>}
+     */
+    public static function trashedScopes(string $relativePath, string $source, array $tokens): array
+    {
+        $imports = PhpReferenceScanner::references($relativePath, $source)->imports;
+        $positions = self::verbPositions($tokens, self::TRASHED_SCOPE_VERBS);
+
+        $unresolved = [];
+        foreach ($positions as $i) {
+            if (self::isStaticOrganizationScope($tokens, $i, $imports)
+                || self::isOrganizationQueryChain($tokens, $i, $imports)) {
+                continue;
+            }
+            $unresolved[] = $relativePath.':'.$tokens[$i]['line'].' ('.$tokens[$i]['text'].')';
+        }
+
+        return ['count' => count($positions), 'unresolved' => $unresolved];
+    }
+
+    /**
+     * 畳み込みの「ロック → 変更」構造の違反 (TLM-5 の 5 条)。空配列なら適合。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  list<string>  $mutationVerbs
+     * @param  list<string>  $deleteVerbs
+     * @return list<string>
+     */
+    public static function lockOrderViolations(
+        array $tokens,
+        string $relativePath,
+        string $source,
+        string $method,
+        string $appendCall,
+        array $mutationVerbs,
+        array $deleteVerbs,
+    ): array {
+        $imports = PhpReferenceScanner::references($relativePath, $source)->imports;
+
+        $body = self::methodBodyRange($tokens, $method);
+        if ($body === null) {
+            return ["メソッド {$method}() の本体が見つからない (走査が壊れている可能性がある)"];
+        }
+        [$bodyStart, $bodyEnd] = $body;
+
+        // 条件 1: 本体の内側に DB ファサードの transaction( がちょうど 1 つ
+        $transactions = [];
+        foreach (self::verbPositions($tokens, ['transaction']) as $i) {
+            if ($i <= $bodyStart || $i >= $bodyEnd) {
+                continue;
+            }
+            if (! self::receiverIs($tokens, $i, self::DB_FACADE, $imports)) {
+                continue;
+            }
+            $transactions[] = $i;
+        }
+        if (count($transactions) !== 1) {
+            return [sprintf(
+                'メソッド %s() の中に DB ファサードの transaction( が %d 個ある (ちょうど 1 つであること)',
+                $method,
+                count($transactions),
+            )];
+        }
+
+        $closure = self::parenRange($tokens, $transactions[0] + 1);
+        if ($closure === null) {
+            return ["transaction( の引数範囲を閉じられない ({$method}())"];
+        }
+        [$closureStart, $closureEnd] = $closure;
+
+        $violations = [];
+
+        // 条件 2: closure の内側にロックがある
+        $locks = array_values(array_filter(
+            self::verbPositions($tokens, ['lockForUpdate']),
+            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
+        ));
+        if ($locks === []) {
+            $violations[] = 'トランザクション closure の内側に lockForUpdate( が無い';
+        }
+
+        // 条件 3: closure の内側に変更操作が 2 種類以上ある (空振り検出を兼ねる)
+        $deletes = array_values(array_filter(
+            self::verbPositions($tokens, $deleteVerbs),
+            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
+        ));
+        $appends = array_values(array_filter(
+            self::verbPositions($tokens, [$appendCall]),
+            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
+        ));
+        if (count($deletes) < 2) {
+            $violations[] = 'トランザクション closure の内側の削除語彙が 2 つ未満である (空振りの疑い)';
+        }
+        if (count($appends) !== 1) {
+            $violations[] = sprintf(
+                'トランザクション closure の内側の %s( が %d 個ある (ちょうど 1 つであること)',
+                $appendCall,
+                count($appends),
+            );
+        }
+
+        // 条件 4: ロックが closure 内の最初の変更操作より前にある
+        $operationVerbs = array_values(array_unique([...$mutationVerbs, $appendCall]));
+        $operations = array_values(array_filter(
+            self::verbPositions($tokens, $operationVerbs),
+            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
+        ));
+        if ($operations !== [] && $locks !== [] && $locks[0] > $operations[0]) {
+            $violations[] = 'lockForUpdate( が closure 内の最初の変更操作より後ろにある (順序が契約である)';
+        }
+
+        // 条件 5: 本体のうち closure の外側に変更操作が 1 つも無い
+        $outside = array_values(array_filter(
+            self::verbPositions($tokens, $operationVerbs),
+            static fn (int $i): bool => $i > $bodyStart && $i < $bodyEnd
+                && ($i < $closureStart || $i > $closureEnd),
+        ));
+        if ($outside !== []) {
+            $violations[] = sprintf(
+                'メソッド %s() のトランザクション closure の外側に変更操作が %d 個ある',
+                $method,
+                count($outside),
+            );
+        }
+
+        // 条件 5 (後段): ファイル全体で追記の呼び出しは 1 件だけ
+        $appendCallsInFile = self::verbCount($tokens, [$appendCall]);
+        if ($appendCallsInFile !== 1) {
+            $violations[] = sprintf(
+                'ファイル全体の %s( の呼び出しが %d 件ある (1 件であること)',
+                $appendCall,
+                $appendCallsInFile,
+            );
+        }
+
+        return $violations;
+    }
+
+    /**
+     * メソッド本体の `{` と `}` の添字 (見つからなければ null)。
+     *
+     * ★文字列補間の `{$x}` / `${x}` の開き側も深さに数える (閉じ `}` は単一文字トークンで
+     *   現れるため、数え漏らすと本体の範囲が途中で閉じる)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{int, int}|null
+     */
+    public static function methodBodyRange(array $tokens, string $method): ?array
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_FUNCTION) {
+                continue;
+            }
+            if (($tokens[$i + 1]['id'] ?? null) !== T_STRING || $tokens[$i + 1]['text'] !== $method) {
+                continue;
+            }
+            // 引数リストを飛ばし、戻り値型を読み飛ばして最初の `{` を探す
+            $paren = self::parenRange($tokens, $i + 2);
+            if ($paren === null) {
+                return null;
+            }
+            for ($j = $paren[1] + 1; $j < $count; $j++) {
+                if (ArchTokenStream::isPunctuation($tokens, $j, ';')) {
+                    return null; // 本体を持たない宣言 (abstract / interface)
+                }
+                if (ArchTokenStream::isPunctuation($tokens, $j, '{')) {
+                    $end = self::braceRange($tokens, $j);
+
+                    return $end === null ? null : [$j, $end];
+                }
+            }
+
+            return null;
+        }
+
+        return null;
+    }
+
+    /**
+     * `(` の添字から対応する `)` までの範囲。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @return array{int, int}|null
+     */
+    private static function parenRange(array $tokens, int $open): ?array
+    {
+        if (! ArchTokenStream::isPunctuation($tokens, $open, '(')) {
+            return null;
+        }
+        $depth = 0;
+        $count = count($tokens);
+        for ($i = $open; $i < $count; $i++) {
+            if (ArchTokenStream::isPunctuation($tokens, $i, '(')) {
+                $depth++;
+
+                continue;
+            }
+            if (ArchTokenStream::isPunctuation($tokens, $i, ')')) {
+                $depth--;
+                if ($depth === 0) {
+                    return [$open, $i];
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * `{` の添字から対応する `}` の添字 (文字列補間の開きも数える)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function braceRange(array $tokens, int $open): ?int
+    {
+        $depth = 0;
+        $count = count($tokens);
+        for ($i = $open; $i < $count; $i++) {
+            $id = $tokens[$i]['id'];
+            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
+                $depth++;
+
+                continue;
+            }
+            if (ArchTokenStream::isPunctuation($tokens, $i, '{')) {
+                $depth++;
+
+                continue;
+            }
+            if (ArchTokenStream::isPunctuation($tokens, $i, '}')) {
+                $depth--;
+                if ($depth === 0) {
+                    return $i;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 静的呼び出しの受け手が指定の完全修飾名に解決されるか。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<string, string>  $imports
+     */
+    private static function receiverIs(array $tokens, int $index, string $fqcn, array $imports): bool
+    {
+        if (($tokens[$index - 1]['id'] ?? null) !== T_DOUBLE_COLON) {
+            return false;
+        }
+
+        return self::resolvesTo($tokens[$index - 2] ?? null, $fqcn, $imports);
+    }
+
+    /**
+     * 受理形 (A) `Organization::withTrashed()`。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<string, string>  $imports
+     */
+    private static function isStaticOrganizationScope(array $tokens, int $index, array $imports): bool
+    {
+        return self::receiverIs($tokens, $index, self::ORGANIZATION_MODEL, $imports);
+    }
+
+    /**
+     * 受理形 (B) `Organization::query()->withTrashed(` のトークン列そのものの一致。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     * @param  array<string, string>  $imports
+     */
+    private static function isOrganizationQueryChain(array $tokens, int $index, array $imports): bool
+    {
+        if (($tokens[$index - 1]['id'] ?? null) !== T_OBJECT_OPERATOR) {
+            return false;
+        }
+        if (! ArchTokenStream::isPunctuation($tokens, $index - 2, ')')
+            || ! ArchTokenStream::isPunctuation($tokens, $index - 3, '(')) {
+            return false;
+        }
+        if (($tokens[$index - 4]['id'] ?? null) !== T_STRING || $tokens[$index - 4]['text'] !== 'query') {
+            return false;
+        }
+        if (($tokens[$index - 5]['id'] ?? null) !== T_DOUBLE_COLON) {
+            return false;
+        }
+
+        return self::resolvesTo($tokens[$index - 6] ?? null, self::ORGANIZATION_MODEL, $imports);
+    }
+
+    /**
+     * 名前トークンが完全修飾名へ解決されるか (import 表 / 完全修飾を解く)。
+     *
+     * @param  array{id: int|null, text: string, line: int}|null  $token
+     * @param  array<string, string>  $imports
+     */
+    private static function resolvesTo(?array $token, string $fqcn, array $imports): bool
+    {
+        if ($token === null) {
+            return false;
+        }
+        if ($token['id'] === T_NAME_FULLY_QUALIFIED) {
+            return ltrim($token['text'], '\\') === $fqcn;
+        }
+        if ($token['id'] !== T_STRING && $token['id'] !== T_NAME_QUALIFIED) {
+            return false;
+        }
+
+        return ($imports[mb_strtolower($token['text'])] ?? null) === $fqcn;
+    }
+
+    /** 引用符を外したリテラルの値。 */
+    private static function literalValue(string $text): string
+    {
+        $first = $text[0] ?? '';
+        if ($first !== "'" && $first !== '"') {
+            throw new RuntimeException('文字列リテラルの引用符が解釈できない: '.$text);
+        }
+
+        return substr($text, 1, -1);
+    }
+}
diff --git a/tests/Support/InitialState/NullableStateColumnRegistry.php b/tests/Support/InitialState/NullableStateColumnRegistry.php
index 2a000648..70ac5ea4 100644
--- a/tests/Support/InitialState/NullableStateColumnRegistry.php
+++ b/tests/Support/InitialState/NullableStateColumnRegistry.php
@@ -345,12 +345,6 @@ public static function entries(): array
                 '台帳の行を作る時点で有効期限を決めて書き込む。'
                 .'NULL は無期限の残高を意味し、進行段階ではない',
             ),
-            NullableStateColumnEntry::setAtCreation(
-                'ticket_ledger_entries',
-                'carried_forward_through',
-                '繰越の行を作るときに集約の終端として生成時に書き込む値である。'
-                .'NULL は繰越ではない行を意味し、進行段階ではない',
-            ),
             NullableStateColumnEntry::setAtCreation(
                 'ticket_ledger_entries',
                 'source',
diff --git a/tests/Unit/Architecture/TicketLedgerMutationScannerTest.php b/tests/Unit/Architecture/TicketLedgerMutationScannerTest.php
new file mode 100644
index 00000000..5f5776f7
--- /dev/null
+++ b/tests/Unit/Architecture/TicketLedgerMutationScannerTest.php
@@ -0,0 +1,204 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\Architecture\TicketLedgerMutationScanner;
+
+/*
+ * 走査器 {@see TicketLedgerMutationScanner} の自己検査 (負例と正例の両方向)。
+ *
+ * AGENTS.md 「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の (1) と (2)。
+ * gate 側 (`tests/Architecture/TicketLedgerMutationSiteGateTest.php`) は「実コードが目録と
+ * 一致するか」を見る。ここは**検出器そのものが正しく数えるか**を見る。
+ */
+
+/**
+ * 合成入力をトークン化する短縮形。
+ *
+ * @return list<array{id: int|null, text: string, line: int}>
+ */
+function tlmTokens(string $source): array
+{
+    return TicketLedgerMutationScanner::tokenize($source, 'scanner-self-test');
+}
+
+test('表名リテラルは完全一致だけを数える (接頭辞・接尾辞つきは数えない)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function f(): void {
+                DB::table('ticket_ledger_entries')->get();
+                DB::table('ticket_ledger_entries_backup')->get();
+                DB::table('archive_ticket_ledger_entries')->get();
+            }
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::tableLiteralCount(tlmTokens($source)))->toBe(1);
+});
+
+test('表名リテラルはコメント・docblock の中では数えない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        /** 台帳の表名は ticket_ledger_entries である。 */
+        final class R {
+            // 'ticket_ledger_entries' をここで消してはならない
+            public function f(): void {}
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::tableLiteralCount(tlmTokens($source)))->toBe(0);
+});
+
+test('変更語彙は接頭辞つき・打ち消しつき・接尾辞つきの 3 形を数えない ((e) の負例)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function f($q): void {
+                $q->presave();
+                $q->unsave();
+                $q->saveAll();
+                $q->save();
+            }
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['save']))->toBe(1);
+});
+
+test('変更語彙はメソッド定義 (function delete()) を数えない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function delete(): void {}
+            public function f($q): void { $q->delete(); }
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['delete']))->toBe(1);
+});
+
+test('変更語彙はコメント・文字列の中では数えない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function f(): void {
+                // $q->delete(); は書いてはならない
+                $sql = 'delete(';
+            }
+        }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::verbCount(tlmTokens($source), ['delete']))->toBe(0);
+});
+
+test('モデル参照は別名つき import を解決して拾う', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use App\Models\Billing\TicketLedgerEntry as Ledger;
+        final class R { public function f(): void { Ledger::query()->get(); } }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::referencesLedgerModel('app/Foo/R.php', $source, tlmTokens($source)))
+        ->toBeTrue();
+});
+
+test('モデル参照は同名の別クラスも短名一致の側で拾う (拾いすぎ側 = fail-closed)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use Other\TicketLedgerEntry;
+        final class R { public function f(): void { TicketLedgerEntry::query()->get(); } }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::referencesLedgerModel('app/Foo/R.php', $source, tlmTokens($source)))
+        ->toBeTrue();
+});
+
+test('モデル参照を持たないファイルは false になる (負のコントロール)', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        final class R { public function f($q): void { $q->save(); } }
+        PHP;
+
+    expect(TicketLedgerMutationScanner::referencesLedgerModel('app/Foo/R.php', $source, tlmTokens($source)))
+        ->toBeFalse();
+});
+
+test('トークン化できない入力は無言で空にせず例外になる ((b) fail-closed)', function (): void {
+    TicketLedgerMutationScanner::tokenize('<?php final class { ', 'scanner-self-test');
+})->throws(RuntimeException::class);
+
+test('メソッド本体の範囲は入れ子の波括弧・文字列補間で崩れない', function (): void {
+    $source = <<<'PHP'
+        <?php
+        final class R {
+            public function target(int $n): string {
+                if ($n > 0) { $label = "値は {$n} です"; } else { $label = '負'; }
+                foreach ([1, 2] as $i) { $label .= "{$i}"; }
+                return $label;
+            }
+            public function after($q): void { $q->delete(); }
+        }
+        PHP;
+
+    $tokens = tlmTokens($source);
+    $range = TicketLedgerMutationScanner::methodBodyRange($tokens, 'target');
+    expect($range)->not->toBeNull();
+
+    // `after()` の delete( は target() の本体の**外**にある
+    $deletes = TicketLedgerMutationScanner::verbPositions($tokens, ['delete']);
+    expect($deletes)->toHaveCount(1);
+    expect($range[0] < $deletes[0] && $deletes[0] < $range[1])->toBeFalse();
+});
+
+test('存在しないメソッドの本体範囲は null になる (呼び出し側が失敗させる材料)', function (): void {
+    $source = '<?php final class R { public function f(): void {} }';
+
+    expect(TicketLedgerMutationScanner::methodBodyRange(tlmTokens($source), 'missing'))->toBeNull();
+});
+
+test('論理削除 scope は受理する 2 形だけを解決済みとし、それ以外は未解決として返す', function (): void {
+    $accepted = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use App\Models\Organization;
+        final class R {
+            public function a(): void { Organization::withTrashed()->get(); }
+            public function b(): void { Organization::query()->withTrashed()->get(); }
+        }
+        PHP;
+
+    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $accepted, tlmTokens($accepted));
+    expect($result['count'])->toBe(2);
+    expect($result['unresolved'])->toBe([]);
+
+    $rejected = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        use App\Models\Organization;
+        final class R {
+            public function a($query): void { $query->withTrashed()->get(); }
+            public function b(): void { Organization::query()->where('id', 1)->withTrashed()->get(); }
+            public function c(): void { \App\Models\User::onlyTrashed()->get(); }
+        }
+        PHP;
+
+    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $rejected, tlmTokens($rejected));
+    expect($result['count'])->toBe(3);
+    expect($result['unresolved'])->toHaveCount(3);
+});
+
+test('論理削除 scope は完全修飾で書かれた組織モデルも受理する', function (): void {
+    $source = <<<'PHP'
+        <?php
+        namespace App\Foo;
+        final class R { public function a(): void { \App\Models\Organization::withTrashed()->get(); } }
+        PHP;
+
+    $result = TicketLedgerMutationScanner::trashedScopes('app/Foo/R.php', $source, tlmTokens($source));
+    expect($result['count'])->toBe(1);
+    expect($result['unresolved'])->toBe([]);
+});
diff --git a/tests/Unit/Billing/CarryForwardGroupTest.php b/tests/Unit/Billing/CarryForwardGroupTest.php
new file mode 100644
index 00000000..d1427664
--- /dev/null
+++ b/tests/Unit/Billing/CarryForwardGroupTest.php
@@ -0,0 +1,168 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\CarryForwardGroup;
+use App\Enums\Billing\TicketSource;
+use Carbon\CarbonImmutable;
+use Webmozart\Assert\InvalidArgumentException;
+
+/*
+ * 畳み込みの集約結果を受ける境界 DTO (`CarryForwardGroup`) の型確定と fail-closed。
+ *
+ * ★**範囲検査は PHP `int` へ変換する前**に行う。driver が数値文字列で返す場合、
+ *   先にキャストすると PHP 整数範囲を超えた値が**壊れた後で**検査することになる。
+ */
+
+/**
+ * 集計行 (クエリビルダの `get()` が返す stdClass) を組み立てる。
+ *
+ * @param  array<string, mixed>  $overrides
+ */
+function carryForwardRow(array $overrides = []): stdClass
+{
+    return (object) array_merge([
+        'source' => 'purchased',
+        'expires_at' => null,
+        'delta_sum' => 10,
+        'max_created_at' => '2020-01-02 03:04:05',
+        'row_count' => 2,
+        'carry_forward_rows' => 0,
+    ], $overrides);
+}
+
+test('1: delta_sum が int の正常値ならそのまま採る', function (): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => -42]))->deltaSum)->toBe(-42);
+});
+
+test('2: delta_sum が int4 の境界ちょうどなら通る', function (string $value, int $expected): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]))->deltaSum)->toBe($expected);
+})->with([
+    ['2147483647', 2147483647],
+    ['-2147483648', -2147483648],
+]);
+
+test('3: delta_sum が int4 の境界 +1 なら例外', function (string $value): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
+})->with([['2147483648'], ['-2147483649']])->throws(InvalidArgumentException::class);
+
+test('4: delta_sum が PHP 整数範囲を超える 10 進文字列ならキャスト前に落ちる', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => '9223372036854775808000']));
+})->throws(InvalidArgumentException::class);
+
+test('5: delta_sum が 10 進整数の表記でなければ例外', function (string $value): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
+})->with([['1e5'], ['1.5'], [''], [' 1'], ['1 '], ['-'], ['+1'], ['0x10']])
+    ->throws(InvalidArgumentException::class);
+
+test('6: delta_sum が int でも文字列でもなければ例外', function (mixed $value): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
+})->with([[true], [1.5], [null]])->throws(InvalidArgumentException::class);
+
+test('7: delta_sum の -0 / 000 は 0 として通る', function (string $value): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]))->deltaSum)->toBe(0);
+})->with([['-0'], ['000'], ['0']]);
+
+test('8: source が null なら null のまま保持する', function (): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['source' => null]))->source)->toBeNull();
+});
+
+test('9: source の文字列は列挙型へ確定する', function (): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['source' => 'monthly']))->source)
+        ->toBe(TicketSource::Monthly);
+});
+
+test('10: source が未知の値なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['source' => 'unknown']));
+})->throws(ValueError::class);
+
+test('11: source が非文字列なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['source' => 1]));
+})->throws(InvalidArgumentException::class);
+
+test('12: expires_at が null なら expiresAt は null', function (): void {
+    expect(CarryForwardGroup::fromRow(carryForwardRow(['expires_at' => null]))->expiresAt)->toBeNull();
+});
+
+test('13: expires_at は文字列でも DateTimeInterface でも CarbonImmutable になる', function (): void {
+    $fromString = CarryForwardGroup::fromRow(carryForwardRow(['expires_at' => '2021-05-06 07:08:09']));
+    expect($fromString->expiresAt)->toBeInstanceOf(CarbonImmutable::class);
+    expect($fromString->expiresAt?->toDateTimeString())->toBe('2021-05-06 07:08:09');
+
+    $fromObject = CarryForwardGroup::fromRow(
+        carryForwardRow(['expires_at' => new DateTimeImmutable('2021-05-06 07:08:09')]),
+    );
+    expect($fromObject->expiresAt)->toBeInstanceOf(CarbonImmutable::class);
+    expect($fromObject->expiresAt?->toDateTimeString())->toBe('2021-05-06 07:08:09');
+});
+
+test('14: max_created_at が null なら例外 (集約の基準時刻は必須)', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['max_created_at' => null]));
+})->throws(InvalidArgumentException::class);
+
+test('15: row_count / carry_forward_rows の数値文字列は整数へ確定する', function (): void {
+    $group = CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '3', 'carry_forward_rows' => '0']));
+    expect($group->rowCount)->toBe(3);
+    expect($group->carryForwardRows)->toBe(0);
+});
+
+test('16: row_count が負なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => -1]));
+})->throws(InvalidArgumentException::class);
+
+test('17: 列が欠けていたら例外', function (): void {
+    $row = carryForwardRow();
+    unset($row->delta_sum);
+    CarryForwardGroup::fromRow($row);
+})->throws(InvalidArgumentException::class);
+
+test('18: 余剰列があっても拒否しない', function (): void {
+    $group = CarryForwardGroup::fromRow(carryForwardRow(['driver_internal' => 'noise']));
+    expect($group->deltaSum)->toBe(10);
+});
+
+test('19: row_count が float なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 1.0]));
+})->throws(InvalidArgumentException::class);
+
+test('20: row_count が指数表記なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '1e3']));
+})->throws(InvalidArgumentException::class);
+
+test('21: row_count が bool なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => true]));
+})->throws(InvalidArgumentException::class);
+
+test('22: row_count が PHP 整数範囲を超える 10 進文字列ならキャスト前に落ちる', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '9223372036854775808']));
+})->throws(InvalidArgumentException::class);
+
+test('23: row_count が 0 なら例外 (集約キーは必ず 1 行以上ある)', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 0]));
+})->throws(InvalidArgumentException::class);
+
+test('24: carry_forward_rows が row_count を超えたら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 1, 'carry_forward_rows' => 2]));
+})->throws(InvalidArgumentException::class);
+
+test('25: carry_forward_rows が負なら例外', function (): void {
+    CarryForwardGroup::fromRow(carryForwardRow(['carry_forward_rows' => -1]));
+})->throws(InvalidArgumentException::class);
+
+test('26: 正常な行は全項目が型の確定した DTO になる', function (): void {
+    $group = CarryForwardGroup::fromRow(carryForwardRow([
+        'source' => 'monthly',
+        'expires_at' => '2030-01-01 00:00:00',
+        'delta_sum' => '123',
+        'max_created_at' => new DateTimeImmutable('2019-12-31 23:59:59'),
+        'row_count' => '4',
+        'carry_forward_rows' => '1',
+    ]));
+
+    expect($group->source)->toBe(TicketSource::Monthly);
+    expect($group->expiresAt?->toDateTimeString())->toBe('2030-01-01 00:00:00');
+    expect($group->deltaSum)->toBe(123);
+    expect($group->maxCreatedAt->toDateTimeString())->toBe('2019-12-31 23:59:59');
+    expect($group->rowCount)->toBe(4);
+    expect($group->carryForwardRows)->toBe(1);
+});

```

## テスト結果

- `composer phpstan` (level 10, 1116 files): **No errors**
- `vendor/bin/pest tests/Feature/Billing/TicketLedgerCarryForwardTest.php`: **27 passed**
- `vendor/bin/pest tests/Unit/Billing/CarryForwardGroupTest.php`: **39 passed**
- `vendor/bin/pest tests/Unit/Architecture/TicketLedgerMutationScannerTest.php`: **13 passed**
- `vendor/bin/pest tests/Architecture/TicketLedgerMutationSiteGateTest.php`: **16 passed**
- `vendor/bin/pest tests/Architecture/TicketLedgerReaderInventoryTest.php`: **8 passed**
- `vendor/bin/pest tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php`: **15 passed**
- `composer test` (全レーン): 実行中 (完了後に確認する)

## 変異による検出力の実測 (7 変異すべて赤)

| 変異 | 赤になったテスト |
|---|---|
| 第 2 段の寄与判定を落とす | N1 / N4 / N18 / 検証 1〜4・7 |
| 繰越行の created_at を実行時刻へ戻す | N2 / N5 / N18 / 繰越行の粒度テスト |
| 収束の短絡を外す | N3b |
| int4 の範囲検査を外す | DTO テスト 3 (Feature 側は赤にならない = 限界を記録済み) |
| 件数照合を外す | N10 |
| withTrashed() を外す | N12/N13 / N14 / TLM-4 / TLM-7 |
| 決着対象から失効した繰越行を外す | N18 |

## 特に見てほしい点

1. `settlementPredicate()` の `where(...)->orWhere(...)` が
   `settlementScope()` の外側の `where('created_at','<=',$threshold)` と
   **正しく括弧で閉じているか** (Eloquent の nested closure で包んでいるが、
   AND/OR の優先順位が壊れていないか)。
2. `contributingGroups()` の `groupBy('source','expires_at')` + `selectRaw` の
   bind 位置 (select binding が where binding より前に来る前提) が正しいか。
3. `TicketLedgerMutationScanner` の TLM-5 判定 (5 条) に、
   本文で主張していない検出力を主張している箇所が無いか。
4. `CarryForwardGroup::withinLimit()` の 10 進文字列比較に境界の穴が無いか。

