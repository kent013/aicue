# 詳細設計レビュー Round 2

Round 1 の指摘 (Critical 6 / Warning 10 / Suggestion 1) に対する対応マトリクスと、
修正後の詳細設計 (全文) を示す。

Critical 6 件のうち 5 件は全面的に対応した。1 件 (主キー取得 gate への登録) は
**既存目録が自分で宣言している母集団の定義**であることを示して反論している。
その反論の妥当性を最優先で判定してほしい。

---

# 対応マトリクス: design-review Round 1

## 施策 1

### [Critical] 失効済み繰越行だけを持つ組織が永久に処理されない

- 判断: **対応する** (指摘が正しい。設計の中心的な穴だった)
- 根拠: `organizationsWithExpiredDetails()` が `kind != carry_forward` で組織を絞るので、
  繰越行が後日 `expires_at <= now` になっても、同組織に期限超過の取引明細が無ければ
  `carryForwardOrganization()` が呼ばれず `expiredScope()` に到達しない。
  「失効窓の有界化」が成立しない。
- 対応内容: **決着対象 (settlement scope) を 1 つの述語に定義し、
  組織の列挙・件数・処理・監視の 4 か所で共有する**。

  ```
  created_at <= 閾値
  AND ( kind != carry_forward
        OR (expires_at IS NOT NULL AND expires_at <= now) )
  ```

  実装は `settlementScope($threshold, $now)` と `settlementPredicate($query, $now)` の 2 つに切り、
  `whereHas` からも同じ述語を使う。`carryForward()` は自分が確定した `$now` を
  候補数と残数の両方へ渡す (実行中に時計が進んでも母集団がずれない)。
  `countExpired()` は purger の署名を保つため `$now` を受け取らず、呼び出し時点の現在時刻で判定する。

### [Critical] 主キー取得 gate の検出漏れを利用する形になっている

- 判断: **反論する** (根拠を設計へ明記して残す)
- 根拠: これは走査器の盲点ではなく、`DirectFetchInventory` が**自分で宣言している母集団の定義**である。
  同クラスの docblock に
  「ノイズは走査器の provenance フィルタ (**識別子引数が解決済みモデル由来のものを外す**) で落とす」
  と書かれている。本経路の識別子は payload / route parameter / token claim ではなく、
  **同一実行内の列挙で解決済みの `Organization` モデルの主キー**であり、外部入力に由来しない。
  同じ形は本リポジトリの既存経路に多数あり、代表例は
  **`TicketLedgerService::lockOrganizationRow()`** で**目録に登録されていない**
  (現行 v0 の畳み込みも同様)。ここだけ登録すると目録の意味が
  「解決済みモデル由来も載せる」へ変わり、`app/` 全域の `->whereKey($model->getKey())` の
  洗い出しが付いてくる (本 feature の射程外。思考原則 2 に反する)。
  走査器の変更も同じ理由で行わない (変更すれば負例・未解決形・空振り・docblock の 4 点が
  付いてくるが、それは別 TODO の仕事である)。
- 対応内容: 施策 1 に「主キー取得 gate との関係 — 登録しない判断の根拠」節を新設し、
  (1) 識別子の出自が外部入力でないこと (2) 既存の同形の先例 (3) `withTrashed()` を足しても
  候補 0 件のままである実測 (4) **将来 id 起点へ書き換えるならその時点で登録が要る**ことを
  実装の docblock にも書く、を明記した。

### [Warning] 「組織行ロックが二重繰越を構造で防ぐ」は範囲が広すぎる

- 判断: 対応する
- 対応内容: サービスの docblock に「ロックが守る範囲を誇張しない」節を追加し、
  **ロックが直列化するのは同じロックを取る経路だけ** (畳み込み同士 / reserve・commit・release・grant) /
  **冪等 insert はロックを取らない** / **その窓を閉じるのは件数照合とトランザクションの巻き戻し** /
  **二重の繰越行を防ぐのは「同一トランザクション内で削除 → 追記」という順序**、と書き分けた。

### [Warning] 収束短絡は N3 では実行されない

- 判断: 対応する
- 対応内容: N3 を「回帰 (v0 でも緑になる)」と明記し、**N3b** を新設した
  (別の集約キーに期限超過の明細を置いて組織を列挙させ、既に繰越 1 行だけの集約キーの
  **id が不変**であることを見る。短絡条件を一時的に壊して赤を確認する手順も書いた)。

## 施策 2

### [Warning] `natural()` が緩く overflow で fail-closed にならない

- 判断: 対応する
- 対応内容: `Assert::integerish()` を**使わない**方針に変え、
  `decimalInt(mixed, property, positiveLimit, negativeLimit, message)` を共通の入口にした。
  `int` か `/\A-?[0-9]+\z/` に完全一致する 10 進文字列だけを受け、
  **PHP `int` へ変換する前に**上下限を 10 進文字列のまま比較する。
  件数は `PHP_INT_MAX` / `PHP_INT_MIN` を境界にしたうえで `Assert::natural`。
  bool / float / 指数表記 / 小数 / 空文字 / 前後空白はすべて例外。

### [Warning] 集約結果間の不変条件が不足

- 判断: 対応する
- 対応内容: `fromRow()` で `rowCount >= 1` と `0 <= carryForwardRows <= rowCount` を検査する
  (`Assert::greaterThanEq` / `Assert::lessThanEq` / `Assert::natural`)。

### [Warning] `fromRow(object)` + `propertyExists()` は契約が広すぎる

- 判断: 対応する
- 対応内容: 引数を **`stdClass`** に狭め、読み出しを `get_object_vars()` + `Assert::keyExists()` の
  2 段にした (private property の穴が構造的に消える)。
  呼び出し側 (`contributingGroups()`) で `Assert::isInstanceOf($row, stdClass::class)` を通す。

## 施策 3

### [Critical] `expiredRemaining` の定義と物理削除対象が矛盾

- 判断: 対応する (施策 1 の C1 と同一の是正)
- 対応内容: 共通定義を「**いま継続状態を表している**集約レコードは含まない」へ改め、
  **失効した繰越行は決着対象に含まれる**と明記した (除外すると fail-open になる理由も書いた)。
  固定するテストを 3 本に増やした
  (寄与中の繰越行だけなら 0 / 失効した繰越行だけなら 1 / 決着後は 0)。

## 施策 4

### [Critical] 「デプロイ順序の制約はない」は誤り

- 判断: **対応する** (指摘が正しい。旧コードは同列を SELECT / INSERT する)
- 対応内容: migration の docblock・施策 4 のリスク節・runbook の新節の 3 か所を
  「**新コード → drop migration** に固定 / drop 後に旧コードへ単純 rollback できない /
  戻すなら先に `down()` で列を戻す / migration 先行が避けられない基盤なら
  maintenance window か手順変更が必要」へ書き換えた。
  **順序の正本は runbook の手順節**とし、`docs/architecture.md` には順序を書かない
  (「順序制約なし」も書かない)。

### [Warning] `down()` の値非復元を rollback 手順にも明記

- 判断: 対応する
- 対応内容: runbook の新節に「`down()` は列を戻すが値は復元しない
  (既存の繰越行は終端が null として扱われる)」を書く。

## 施策 5

### [Critical] TLM-5 では変更操作すべてがトランザクション内にあることを証明できない

- 判断: 対応する
- 対応内容: TLM-5 を **5 条**に拡張した。
  (1) メソッド本体に `DB::transaction(` がちょうど 1 つ /
  (2) closure 内に `lockForUpdate(` /
  (3) closure 内に変更操作が 2 種類以上 (`delete(` 2 つ以上 + `appendCarryForward(` 1 つ) = 空振り検出 /
  (4) ロックが最初の変更操作より前 (語彙は変更語彙 ∪ `{appendCarryForward}`) /
  (5) closure の外側に変更操作が 1 つも無く、ファイル全体で `appendCarryForward(` の呼び出しは 1 件。
  負例に「**追記の呼び出しだけを closure の外へ移す**」を追加して 7 変異にした。

### [Warning] `Organization::query()->withTrashed()` の受け手認定案が fail-open

- 判断: 対応する
- 対応内容: **受理する構文を 2 形に固定**した。
  (A) `Organization::withTrashed()` (StaticCall の受け手が FQCN 解決) /
  (B) `Organization::query()->withTrashed(` の**トークン列そのものの一致**
  (`Organization` は import 表で `App\Models\Organization` に解決できること)。
  変数受け手・長い連鎖は**未解決として gate を落とす**。
  「同じファイルに `Organization::query()` が在る」を根拠にする案は撤回した。

### [Warning] TLM-3 の対象範囲の書き方

- 判断: 対応する
- 対応内容: 「**TLM-2 の候補ファイル**のうち削除語彙を持つのは 1 ファイルだけ
  (`app/` 全体の `delete(` を対象にするのではない)」へ明記した。

## 施策 6

- 判定: APPROVE。追加対応なし。

## 施策 7

### [Critical] 「失効済み繰越行だけが残った組織」の回帰テストが無い

- 判断: 対応する
- 対応内容: **N18** を新設した (指摘の 5 段をそのまま採用。
  繰越行は Factory で直に作らず**畳み込みの出力を使う**ことも明記した)。

### [Warning] N3 は v0 でも緑になる

- 判断: 対応する
- 対応内容: N3 を「回帰。テストファーストの赤の起点にしない」と明記し、
  短絡を検証する **N3b** を追加した。テストファースト手順の段 1 の一覧からも N3 を外した。

### [Warning] 時刻境界を扱うテストは時計を固定する

- 判断: 対応する
- 対応内容: 「時計の固定」節を新設し、`$this->freezeTime()` /
  `$this->travelTo(...)` (`InteractsWithTime`。テスト終了時に自動で戻る) を使うことと、
  既存の作法 (`$this->travelTo`) に揃えることを書いた。

### [Warning] DTO 修正に合わせた挙動テストの追加

- 判断: 一部対応する
- 根拠: 「失効済み繰越行の**削除失敗**」は stub を挟まないと作れない
  (DB レベルの delete を失敗させる手段が無い)。無理に作ると実装の内部へ結合したテストになる。
- 対応内容: **N19** として「失敗した組織があるとき publication-ready が誤って true にならない /
  他組織は処理される」を置き、**失敗の注入は範囲検査 (N8) で行う**。
  「DB レベルの削除失敗は再現しない」という限界をテストのコメントに書く。

## 施策 8

### [Warning] DTO の入力契約の負例が不足

- 判断: 対応する
- 対応内容: ケースを 18 → 26 に増やした
  (`row_count` の float / 指数表記 / bool / PHP 整数範囲超 / 0 /
  `carry_forward_rows > row_count` / `carry_forward_rows < 0` / 入力はすべて `stdClass`)。

## 施策 9

### [Critical] 「append-only の例外は畳み込み 1 ファイルだけ」が現行実装と矛盾

- 判断: 対応する
- 対応内容: AGENTS.md の規約案を 4 分割した
  (行の物理削除と残高スナップショットへの置換 = 畳み込み 1 ファイル /
  通常追記と限定 backfill = `TicketLedgerService` /
  許容される変更サイトの正本 = mutation inventory /
  削除語彙の許容 = 畳み込み 1 ファイル)。

### [Critical] デプロイ順序の文書も訂正

- 判断: 対応する (施策 4 と同一)
- 対応内容: 「順序制約なし」を architecture / runbook / migration のどこにも残さない。
  正本は runbook の新節。

### [Warning] 最終検証コマンドが AGENTS.md の必須一覧を満たしていない

- 判断: 対応する
- 対応内容: 段 12 を AGENTS.md のマーカー内の**全 10 コマンド**へ差し替えた
  (`pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加)。

### [Suggestion] `mutation-evidence.md` を施策一覧へ

- 判断: 対応する
- 対応内容: 施策 10 として一覧へ追加し、テストファースト手順の段 13 に置いた。
  変異は 7 つ (決着対象から失効した繰越行を外す変異を追加)。

---

## 修正後の詳細設計 (全文)

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
 * ★**決着対象 (settlement scope) は 1 つの述語で定義し、組織の列挙・件数・処理・監視の
 *   4 か所すべてで共有する**。定義がずれると「数えているのに処理されない行」が生まれる。
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
 *   **同じロックを取る経路だけ**である —
 *   畳み込み同士 / `TicketLedgerService` の `reserve` `commit` `release` `grant` (残高判定を伴う操作)。
 *   一方 `grantMonthly` / `grantPurchased` / `grantSignupGrant` / `clawback` の**冪等 insert は
 *   このロックを取らない**ので、集計と削除の間に `created_at <= 閾値` の行が commit されうる。
 *   その窓を閉じるのは**ロックではなく件数照合とトランザクションの巻き戻し**である
 *   (`carryForwardOrganization` の手順 7)。二重の繰越行を防ぐのは
 *   「**同一トランザクション内で削除 → 追記**」という順序であり、
 *   ロックはそこへ他の畳み込みが割り込まないことだけを保証する。
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
  静的に解決できない場合は **`Organization::query()->withTrashed()`** へ書き換える
  (施策 5 の gate は `withTrashed(` のトークン出現で数えるのでどちらでも検出できる。
  ただし FQCN 受け手の照合は静的呼び出し形のときだけ効くので、書き換えたら gate の
  受け手照合を「同ファイル内の `Organization::query()` の存在」に読み替える判断を
  gate の docblock に書く)。**型を緩めて黙らせる (禁止事項 2) 方向へは倒さない**

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

したがって **`DirectFetchInventory` の登録も走査器の変更も行わない**。
代わりに以下を設計上の事実として残す。

- 識別子の出自は「同一実行内の列挙結果」であり、**外部入力に由来しない**
- `withTrashed()` を足しても走査器の候補は 0 件のまま (実測)
- **もし将来 id 起点 (`foldOrganization(int $id)`) へ書き換えるなら、その時点で候補が 1 件生まれる**
  ので `DirectFetchInventory` へ理由付きで登録すること (この一文を実装の docblock にも書く)

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
 * ★**デプロイ順序は「新コード → この drop」に固定する**。逆順 (drop 先行) にすると、
 *   まだ旧コードが動いている間に `MAX(carried_forward_through)` の集計と
 *   繰越行の INSERT が `Undefined column` で落ちる。
 * ★**drop 後に旧コードへ単純 rollback できない**。戻す必要があるなら
 *   **先に `down()` で列を戻してから**旧コードへ戻す。`down()` は列を戻すだけで
 *   **値は復元しない** (新形の繰越行は集約終端を `created_at` で表すので、
 *   復元すると嘘の値になる) — したがって旧コードを再稼働させると、
 *   既存の繰越行は「終端が未記録 (null)」として扱われる。
 * ★migration 先行が構造的に避けられない基盤なら、maintenance window か
 *   デプロイ手順の変更が必要である (本リポジトリにデプロイ定義は無いので、
 *   現時点では手順書 = `docs/billing-retention-runbook.md` が唯一の担保である)。
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
  順序と rollback 手順を **migration の docblock / `docs/billing-retention-runbook.md` /
  `docs/architecture.md` の 3 か所で食い違わせない** (正本は runbook の手順節、
  他はそこを参照する)。
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
| 17 | 列の欠落 (`delta_sum` が無い) | 例外 (`propertyExists`) |
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
    - **決着対象の述語は 1 か所だけに置く**。組織の列挙・件数・処理・監視が同じ述語を共有する
      (取引明細 + **失効した繰越行**。寄与中の繰越行だけが対象外)。
      ずらすと「数えているのに処理されない行」が生まれ、`horizon` が恒久的に NG になる。
    - **列を落とす migration はコード先行**である (drop 先行にすると旧コードが
      `Undefined column` で落ちる)。順序と rollback 手順の正本は
      `docs/billing-retention-runbook.md`。
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
  - `down()` は列を戻すが**値は復元しない** (既存の繰越行は終端が null として扱われる)
  - migration 先行が避けられない基盤なら maintenance window か手順の変更が必要
    (本リポジトリにデプロイ定義は無いので、現状この手順書が唯一の担保である)

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
