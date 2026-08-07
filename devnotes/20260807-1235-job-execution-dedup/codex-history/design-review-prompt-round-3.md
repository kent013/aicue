# 詳細設計レビュー Round 3

Round 2 の指摘 (Critical 5 件 / Warning 5 件 / Suggestion 1 件) を**すべて反映**しました。
特に:
- `void` 固定と Billing の `bool` の衝突 → `PreflightControlFlow` enum で型付きに分岐
- 単一 mechanism → `non-empty-list<JobDedupGuarantee>` へ変更 (複合保証を正確に表現)
- attach 失敗時の後始末を `terminateUnattachedInvoice()` (status 不問) と
  `terminateInvoiceAfterOwnershipLost()` (Canceled 限定) に分離
- cleanup ログを別 event 名 (`job_ownership_lost_cleanup`) へ分離
- テスト手順を `duringCreateInvoice` フックで実際の競合点に一致させた

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## S2 [Warning] `writeProgress(array<string, mixed>)` では `status` や保護列を渡せてしまう

- 判断: **対応する**
- 根拠: 妥当。「進捗書き込みは状態遷移を行わない」という境界が型で閉じていない。
- 対応内容: 引数を array shape
  `array{step: string, progress: int, result_json?: array<string, mixed>}` に固定する。
  PHPStan level 10 が `status` キーの混入を静的に弾く。

## S2 [Suggestion] 規約名「終端後の自前書き込みの禁止」は実態と合わない (`extracted_json` が例外)

- 判断: **対応する**
- 根拠: 妥当。例外を持つ規約に包括的な名前を付けると誤読される。
- 対応内容: 規約名を **「終端後のジョブ状態・進捗書き込みの禁止」** に改める
  (対象はジョブ行の `step` / `progress` / `result_json`。監査スナップショットは対象外と明示)。

## S4 / S6 [Critical] `PreflightCheckpoint` は `void` を要求するが `stillPending()` は `bool` を返す (gate が必ず落ちる)

- 判断: **対応する**
- 根拠: 完全に妥当。設計の**内部矛盾**であり、実装したら即赤になる。
  Manual (例外で中断) と Billing (structured return) を無理に統合しない方針は維持したいので、
  Codex 提案の前者 (制御方式を型として持つ) を採る。
- 対応内容: `Tests\Support\JobDedup\PreflightControlFlow` enum を新設し
  (`ThrowsOnLoss` / `ReturnsBoolean`)、`PreflightCheckpoint` に持たせる。
  gate の Reflection 検査を分岐させる:
  - `ThrowsOnLoss` → 戻り型が `void` であること
  - `ReturnsBoolean` → 戻り型が `bool` であること
  これで「どちらの制御方式かを目録が明示し、型がそれと一致する」ことまで固定できる。

## S4 [Critical] attach 失敗時に `Canceled` 以外だと新規 invoice が放置される

- 判断: **対応する**
- 根拠: 完全に妥当。`Failed` へ遷移させた経路は `stripe_invoice_id === null` を見ているため
  invoice ID を知らず終端できない。こちらも `Canceled` 限定にすると**誰も終端しない**。
- 対応内容: 2 つの後始末を**別メソッドに分ける**:
  - `terminateUnattachedInvoice($attempt, $invoiceId)` — **attach 失敗専用。status を問わず
    原則終端する** (この invoice ID を知っているのは自分だけだから)。
    `paid` の可能性は `CashierAutoRechargeGateway::terminateInvoice()` の状態検査が
    `Assert` で fail-closed に分類する (例外 → `terminated=false` としてログ)。
  - `terminateInvoiceAfterOwnershipLost($attempt, $invoiceId)` — **pay 前の所有権喪失専用。
    `Canceled` のみ終端**する (`Failed` は `terminateAndFail()` が invoice ID を
    DB 経由で見えている状態で終端済み)。

## S4 / S6 [Critical] `ExecuteAutoRechargeAttemptJob` の複合保証を単一 mechanism で表現できていない

- 判断: **対応する**
- 根拠: 完全に妥当。`ConditionalStatusUpdate` の適用条件は「0 行更新なら後続を行わない」だが、
  実際には付与が UPDATE より先に走る。**enum 自身の適用条件に一致しない登録**になっていた。
  反論 (順序は変えない) が受け入れられた以上、型がその複合構造を正確に持つべきである。
- 対応内容: `GuaranteeEntry::$mechanism` を **`$mechanisms: non-empty-list<JobDedupGuarantee>`**
  へ変更し、`ExecuteAutoRechargeAttemptJob` を
  `[JobDedupGuarantee::DatabaseUniqueConstraint, JobDedupGuarantee::ConditionalStatusUpdate]`
  で登録する (台帳付与の一回性 = invoice 単位 UNIQUE / attempt 遷移の一回性 = 条件付き UPDATE)。
  gate に「`mechanisms` が空でない」「重複がない」検査を足す。

## S4 [Warning] テスト手順が競合点と一致していない (invoice 作成の直前に canceled 化すると preflight 1 で止まる)

- 判断: **対応する**
- 根拠: 完全に妥当。私のテスト案では `attach 0 行` の経路を 1 度も通らない。
- 対応内容: `tests/Support/FakeAutoRechargeGateway` に
  `public ?Closure $duringCreateInvoice = null;` フックを追加し
  (`FakeRenderComposer::$duringCompose` と同じ作法)、
  **invoice ID を返す直前に attempt を terminal 化**させる。
  これで `preflight 1 成功 → Stripe 作成成功 → 並行 terminal 化 → attach 0 行 → 終端` を
  決定論的に再現する。`Canceled` と `Failed` の両方でケースを作る。

## S4 [Warning] cleanup ログが固定した最小 7 キーを満たしていない

- 判断: **対応する**
- 根拠: 妥当。「同じ event の全ログが同じ集計 schema」という説明と矛盾する。
- 対応内容: cleanup を**別 event 名**にする
  (`ExternalCallKind::CLEANUP_LOG_EVENT = 'job_ownership_lost_cleanup'`)。
  最小 7 キーは `LOG_EVENT` (= 送信抑止の記録) にのみ課し、
  cleanup は `event` / `job_type` / `job_id` / `attempt_ulid` / `invoice_id` / `terminated` /
  `error` の独自 schema とする。両者の schema をテストで固定する。

## S5 [Warning] 「上記 3 ケース」が実際は 4 ケース

- 判断: **対応する**
- 根拠: 事実誤り。
- 対応内容: 「上記 4 ケース」へ修正。

## S6 [Warning] 意味のない `foreach (QueuedJobPopulation::appPhpFiles() as $_)` ループ

- 判断: **対応する**
- 根拠: 妥当。走査コストだけが発生する死んだコード。
- 対応内容: 削除。sealed 検査は `tests/Support/JobDedup/` 配下だけを走査する。

## S7 [Warning] 規約↔テスト対応表が新しい型モデルと合っていない

- 判断: **対応する**
- 根拠: 妥当。「void を返す」は Billing に適用できない。
- 対応内容: 対応表の当該行を
  **「preflight の再検証点が実在し、登録された制御方式 (`PreflightControlFlow`) に
  一致する戻り型を持つ」** へ書き換える。


---

## 修正後の詳細設計書 (全文)

# 詳細設計: job-execution-dedup

> lctl feature id: `job-execution-deduplication` / 裁定 AG-082 追従
> 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex conceptual-review Round 5 で **APPROVED**)
> 実査ブリーフ: [`recon-brief.md`](./recon-brief.md)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。型を緩めて黙らせない・baseline 化しない
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行 (`tests/Pest.php` でグローバル適用済。
  **個別 `DatabaseTransactions` 使用禁止**)
- **テストデータは必ず Factory で生成** (`Model::create()` 手組み禁止)
- 新モデルを追加する設計では対応する Factory の作成も施策に含める
  → **本設計は新モデル・新マイグレーションを一切追加しない**
- **DTO + JsonResource** パターン (本設計は API / Inertia 応答を変更しない)
- **アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く、transaction は Service 内
- **コードフォーマット**: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`

---

## 設計の骨格 (概念設計からの確定事項)

| 用語 | 定義 | 担い手 |
|---|---|---|
| **結果の一回性** | ドメイン状態と課金計上が高々 1 回しか確定しない | 条件付き UPDATE / 悲観ロック + status guard / 予約 CAS (**既存**) |
| **外部呼び出し回数の一回性** | 外部 API を高々 1 回しか叩かない | 外部側の冪等キー (Stripe は既存 / LLM には無い) |
| **preflight suppression** | 既に所有権を失ったと判明しているケースの外部送信を送る手前で止める | **本設計で追加** |

**所有権 = (行の主キー, 進行中 status)**。`AnalysisJob` / `RenderJob` /
`TicketAutoRechargeAttempt` はいずれも単調な状態機械で、再実行は新しい行を起票するため、
`status` の再読込がそのまま所有権の再検証になる (claim token を導入しない根拠は概念設計)。

**preflight の配置規則 (本設計の不変条件)**:

> 外部呼び出しの**直前**に置く。再検証と外部呼び出しの間に**自前の書き込みを挟まない**。
> 自前の書き込みを挟んだ場合は、書き込みの**後**に再度 preflight を置く。

**終端後のジョブ状態・進捗書き込みの禁止 (Round 1/2 レビュー反映)**:

> preflight を置いた経路では、**terminal 化された後に旧ワーカーが自前の書き込みを行う**
> 経路も同時に塞ぐ。**ジョブ行**への進捗書き込み (`step` / `progress` / `result_json`) は
> `where('status', running)` の**条件付き UPDATE** にする
> (「failed なのに progress=65」という不整合を作らない。
> 副次的に `updated_at` の更新も止まるため、stale 判定の基準が terminal 行で動かない)。
>
> **対象はジョブ行に限る**。`SourceDocument::extracted_json` のような
> write-only の監査スナップショットは状態機械の一部ではないため対象外である
> (guard には join が要り、failed 行の document に抽出結果が残っても不整合にならない。
> むしろ調査に役立つ)。規約名を「終端後の**ジョブ状態・進捗**書き込みの禁止」と
> 限定しているのはこのためである。

**preflight の「配置」を保証するのは Feature テストである (Round 1 レビュー反映)**:

> Architecture gate (S6) が固定できるのは **再検証点の実在と戻り型まで**である。
> Reflection では「外部呼び出しの直前で呼ばれていること」を検査できない
> (名前だけ存在する空メソッドでも green になる)。
> **配置の保証は Feature テスト**が担う — 所有権喪失時に LLM / S3 / Stripe の fake が
> 1 回も呼ばれないことを behavioral に固定する。この役割分担を gate 自身の docblock に書く。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 所有権喪失の共通語彙 (例外 + `ExternalCallKind`) | `app/Enums/Security/ExternalCallKind.php` (新)<br>`app/Exceptions/Manual/JobOwnershipLostException.php` (新) | High |
| S2 | 解析パイプラインの preflight (LLM 呼び出し直前) | `app/Services/Manual/AnalysisPipeline.php` | High |
| S3 | レンダパイプラインの preflight (S3 PUT 直前) | `app/Services/Manual/RenderPipeline.php` | High |
| S4 | auto-recharge の preflight (Stripe 呼び出し直前) + 中断時の invoice 終端 | `app/Services/Billing/AutoRechargeService.php` | High |
| S5 | 排他 TTL / uniqueFor の序列を CI 固定 | `app/Services/Billing/AutoRechargeService.php` (const 可視性)<br>`tests/Architecture/JobExclusionOrderingInvariantTest.php` (新) | Medium |
| S6 | 横断 gate (deny-by-default 目録) + 母集団走査の 1 本化 | `app/Enums/Security/JobDedupGuarantee.php` (新)<br>`app/Enums/Security/JobDedupExemption.php` (新)<br>`tests/Support/QueuedJobPopulation.php` (新)<br>`tests/Support/JobDedup/*.php` (新)<br>`tests/Architecture/JobExecutionDedupInventoryTest.php` (新)<br>`tests/Architecture/QueuedJobLeaseInventoryTest.php` (走査の委譲) | High |
| S7 | 運用契約の文書化 (閉じない窓・所有者・規約↔テスト対応) | `docs/architecture.md`<br>`AGENTS.md` | Medium |

---

## S1. 所有権喪失の共通語彙 (例外 + `ExternalCallKind`)

### 変更箇所

- 新規: `app/Enums/Security/ExternalCallKind.php`
- 新規: `app/Exceptions/Manual/JobOwnershipLostException.php`

### 波及変更

- TypeScript 型定義: **なし** (Inertia Props / API 応答に現れない。ユーザー可視の状態は
  既存の `JobStatus` のままで、所有権喪失は「既に failed 済み」としてしか見えない)
- API Resource/DTO: **なし**
- テストファイル: S2 / S3 / S4 の Feature テスト、S6 の Architecture テストが参照する

### 現行コード

存在しない (`tests/` 全体で `ShouldBeUnique` / `uniqueFor` / lock TTL を参照するテストも 0 件)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 所有権再検証 (preflight suppression) が守る外部呼び出しの種別。
 *
 * Manual ドメイン (例外経由) と Billing ドメイン (structured return) の**双方**が
 * 同じ語彙を共有するためにここへ置く (`tests/Architecture/JobExecutionDedupInventoryTest.php`
 * の目録もこの enum を使う。テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★case を足すとき: 「取り消せない外部副作用を持つか」を基準にする。
 *   ローカル CPU (ffmpeg) や冪等な読み取り (S3 GET) は本 enum の対象ではない。
 */
enum ExternalCallKind: string
{
    /**
     * 所有権喪失で**外部送信を抑止した**ときの固定 event 名。
     *
     * ログ基盤で頻度を集計し「残余窓 1 が実際にどれだけ開いているか」を測るために固定する。
     * Manual / Billing の両方がこの 1 箇所を参照する (literal の直書きは
     * JobExecutionDedupInventoryTest が deny-by-default で検出する)。
     *
     * ★この event の**キー集合は最小 7 キーで固定**する
     *   (event / job_type / job_id / expected_status / actual_status / stage / external_call)。
     *   集計 schema を揃えるため、キー集合の違うログを同じ event 名に混ぜない。
     */
    public const string LOG_EVENT = 'job_ownership_lost';

    /**
     * 所有権喪失**後の後始末** (open invoice の終端等) の固定 event 名。
     *
     * 抑止の記録とは schema が違う (expected/actual status も stage も持たない) ため
     * 別 event にする (Round 2 レビュー反映)。集計時は「抑止 → 後始末の成否」の
     * 2 段で追える。
     */
    public const string CLEANUP_LOG_EVENT = 'job_ownership_lost_cleanup';

    /** LLM 補完 (Prism 経由)。**provider 側に冪等キーが無い** = 呼んだら取り消せない */
    case LlmCompletion = 'llm_completion';

    /** オブジェクトストレージへの PUT (レンダ出力の S3 アップロード) */
    case ObjectStoragePut = 'object_storage_put';

    /** Stripe invoice の作成 (課金の前段。open invoice を残す) */
    case StripeInvoiceCreate = 'stripe_invoice_create';

    /** Stripe invoice の off-session 支払い (実際に金が動く) */
    case StripeInvoicePay = 'stripe_invoice_pay';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Security\ExternalCallKind;
use RuntimeException;

/**
 * 外部呼び出しの直前に所有権 (行の主キー, 進行中 status) を再検証して失われていた場合に投げる。
 *
 * 利用者は `App\Services\Manual\AnalysisPipeline` と `App\Services\Manual\RenderPipeline` の
 * 2 つだけで、どちらも Manual ドメインであるため本 namespace に置く
 * (Billing 側は例外を投げず structured return で閉じるので共用しない)。
 *
 * ★これは「異常」ではなく「正常だが観測したい事象」である。`report()` せず、
 *   固定 event 名つきの `Log::warning` で観測する (無音で握らない)。
 * ★コンテキストに PII (email / name) と外部 payload を**一切含めない**
 *   (JobOwnershipLostContextTest が固定する)。
 */
final class JobOwnershipLostException extends RuntimeException
{
    /**
     * @param  class-string  $jobType  所有権を失ったジョブ行のモデルクラス
     * @param  non-empty-string  $stage  既存ドメイン step enum の値
     *                                   (AnalysisStep / RenderStep。同じ語彙の enum を 2 本作らない)
     */
    private function __construct(
        public readonly string $jobType,
        public readonly int $jobId,
        public readonly JobStatus $expectedStatus,
        public readonly ?JobStatus $actualStatus,
        public readonly string $stage,
        public readonly ExternalCallKind $externalCall,
    ) {
        parent::__construct(sprintf(
            '%s#%d: 所有権を失ったため %s を中止しました (期待 %s / 実際 %s)',
            $jobType,
            $jobId,
            $externalCall->value,
            $expectedStatus->value,
            $actualStatus?->value ?? 'missing',
        ));
    }

    /**
     * @param  class-string  $jobType
     * @param  non-empty-string  $stage
     */
    public static function whileRunning(
        string $jobType,
        int $jobId,
        ?JobStatus $actualStatus,
        string $stage,
        ExternalCallKind $externalCall,
    ): self {
        return new self($jobType, $jobId, JobStatus::Running, $actualStatus, $stage, $externalCall);
    }

    /**
     * 構造化ログ用コンテキスト (PII を含まない)。
     *
     * @return array{event: string, job_type: string, job_id: int, expected_status: string,
     *               actual_status: string|null, stage: string, external_call: string}
     */
    public function logContext(): array
    {
        return [
            'event' => ExternalCallKind::LOG_EVENT,
            'job_type' => $this->jobType,
            'job_id' => $this->jobId,
            'expected_status' => $this->expectedStatus->value,
            'actual_status' => $this->actualStatus?->value,
            'stage' => $this->stage,
            'external_call' => $this->externalCall->value,
        ];
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`self` / 配列 shape)
- [x] null 安全 — `?JobStatus` を明示し `?->value` で扱う。`Assert` 不要 (引数で型が閉じている)
- [x] DTO を返している (配列返却は `logContext()` のみで、**array shape を PHPDoc で確定**している)
- [x] Generics の型パラメータ — 該当なし。`class-string` を明示

### テスト計画

- 新規 `tests/Feature/Manual/JobOwnershipLostContextTest.php`
  - `logContext() が固定 event 名 job_ownership_lost を含む`
  - `logContext() のキー集合が仕様どおり (7 キー) で、PII 由来のキーを含まない`
    — キー名に `email` / `name` / `user` を含まないことを機械的に検査
  - `logContext() の値がすべて scalar|null (payload オブジェクトを埋め込んでいない)`
  - `whileRunning() は expectedStatus に JobStatus::Running を入れる`
- 個別の `DatabaseTransactions` は使わない (DB を触らないテストだが `RefreshDatabase` の
  グローバル適用に従う)

### リスク

- 新 enum / 例外の追加のみで挙動を持たないため、後退リスクはほぼゼロ。
- `ExternalCallKind` に `LOG_EVENT` を置くのはやや変則 (enum + const)。
  代替 (専用の Support クラス新設) は「文字列 1 本のためにクラスを作る」ことになり
  AGENTS.md 思考原則 2 に反するため採らない。**この判断を docblock に残す**。

---

## S2. 解析パイプラインの preflight (LLM 呼び出し直前)

### 変更箇所

- `app/Services/Manual/AnalysisPipeline.php`
  - `run()` (L82-113): `JobOwnershipLostException` 専用 catch を `catch (Throwable)` の**前**に追加
  - `withBoundedRetry()` (L307-328): `$job` 引数を追加し、`$attempt()` の直前に preflight
  - `runExtractStep()` / `runDecomposeStep()` / `runGenerateStep()` (L171 / L192 / L216):
    `withBoundedRetry()` 呼び出しへ `$job` を渡す
  - `updateProgress()` (L391-396): **条件付き UPDATE 化** (`where status=running`)
  - `runDecomposeStep()` (L200-204): `result_json` / `step` / `progress` の書き込みを
    条件付き UPDATE 経路へ寄せる
  - `assertStillOwned()` を private メソッドとして新設

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Projects/AnalysisPipelineTest.php` へ**追記**
  (既存テストの削除・上書きはしない。`pipelineContext()` / `installThrowingLlm()` などの
  ヘルパは同ファイル内にあるため、別ファイルへ切り出すと `--parallel` 実行時に
  未定義関数になる恐れがある = 同ファイルへ追記する)
- 時間 budget: **変更なし**。`RunManualAnalysis::$timeout` / `manual.analysis_deadline_seconds` /
  `retry_after` のいずれにも触れないため `AnalysisTimeBudgetInvariantTest` は無影響
  (preflight は主キー 1 行の SELECT で、deadline D = 1,080s の内側に収まる)

### 現行コード

```php
    public function run(int $analysisJobId): void
    {
        $deadline = CarbonImmutable::now()
            ->addSeconds(config()->integer('manual.analysis_deadline_seconds'));

        $job = AnalysisJob::query()->findOrFail($analysisJobId);

        try {
            if (! $this->startJob($job)) {
                return; // 重複配送 / stale 回復後の遅延配送 → no-op
            }
            $document = $job->sourceDocument;
            Assert::notNull($document, 'trigger が必ず associate している');

            $text = $this->extractor->extract($document);
            $extracted = $this->runExtractStep($job, $document, $text, $deadline);
            $decomposition = $this->runDecomposeStep($job, $extracted, $deadline);
            $generated = $this->runGenerateStep($job, $decomposition, $deadline);
            if ($this->finalize($job, $generated)) {
                $this->notifications->notifyAnalysisFinished($job->refresh());
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->userMessageFor($exception));
        }
    }

    private function runExtractStep(
        AnalysisJob $job,
        SourceDocument $document,
        ExtractedText $text,
        CarbonImmutable $deadline,
    ): ExtractedSopData {
        $extracted = $this->withBoundedRetry(
            $deadline,
            AnalysisStep::Extract,
            fn (): ExtractedSopData => ExtractedSopData::fromLlmText(
                SopExtractPrompt::make($text->text)->executeSync(),
            ),
        );
        // …
    }

    private function withBoundedRetry(CarbonImmutable $deadline, AnalysisStep $step, callable $attempt): mixed
    {
        $maxRetries = config()->integer('manual.analysis_llm_max_retries');
        for ($tryCount = 0; ; $tryCount++) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                throw AnalysisFailedException::timedOut();
            }
            try {
                return $attempt();
            } catch (Throwable $exception) {
                // …
            }
        }
    }
```

### 変更後コード

```php
        } catch (JobOwnershipLostException $exception) {
            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
            // failJob も通知もチケット release も呼ばない (すべて先着が済ませている)。
            // report() しない — これは「正常だが観測したい事象」であり、固定 event 名で集計する。
            Log::warning('解析ジョブの所有権を失ったため外部呼び出しを中止しました', $exception->logContext());

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->userMessageFor($exception));
        }
```

```php
    /** extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット) */
    private function runExtractStep(
        AnalysisJob $job,
        SourceDocument $document,
        ExtractedText $text,
        CarbonImmutable $deadline,
    ): ExtractedSopData {
        $extracted = $this->withBoundedRetry(
            $job,
            $deadline,
            AnalysisStep::Extract,
            fn (): ExtractedSopData => ExtractedSopData::fromLlmText(
                SopExtractPrompt::make($text->text)->executeSync(),
            ),
        );
        // …以降は現行のまま
    }
```

```php
    /**
     * LLM 段の共通有界リトライ。
     *
     * 打ち切り条件は 2 つ:
     *  (a) 試行回数 (config manual.analysis_llm_max_retries。計 1+N 試行)
     *  (b) 実時間 deadline (config manual.analysis_deadline_seconds)
     *
     * ★ preflight suppression (裁定 AG-082 標準形 (2)): **`$attempt()` の直前**で所有権を
     *   再検証する。ここに 1 箇所置くだけで extract / decompose / generate の 3 段 ×
     *   全リトライ試行を覆う (挿入点が 1 つ = 新しい段を足しても抜けようがない)。
     *   deadline 判定 (時計の読み取り) は自前の書き込みではないため、
     *   preflight と `$attempt()` の間に書き込みは 1 つも無い。
     *
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    private function withBoundedRetry(
        AnalysisJob $job,
        CarbonImmutable $deadline,
        AnalysisStep $step,
        callable $attempt,
    ): mixed {
        $maxRetries = config()->integer('manual.analysis_llm_max_retries');
        for ($tryCount = 0; ; $tryCount++) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                throw AnalysisFailedException::timedOut();
            }
            $this->assertStillOwned($job, $step); // ★外部呼び出しの直前 (これより後に書き込みを挟まない)
            try {
                return $attempt();
            } catch (Throwable $exception) {
                if ($tryCount >= $maxRetries || ! $this->isTransient($exception)) {
                    throw $exception; // 打ち切り → run() の catch → failJob
                }
                Log::warning('AI 解析の LLM 呼び出しを再試行します', [
                    'step' => $step->value,
                    'attempt' => $tryCount + 1,
                    'max_attempts' => $maxRetries + 1,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    /**
     * 所有権の再検証 (preflight suppression)。
     *
     * 所有権 = (行の主キー, `running`)。`startJob()` の `lockForUpdate + status === Queued`
     * guard により 1 行が `running` になるのは高々 1 回で、再実行は新しい行を起票するため、
     * `status` の再読込がそのまま所有権の再検証になる (claim token を持たない根拠は
     * docs/architecture.md §ジョブの重複実行と結果の一回性)。
     *
     * 行が消えている (null) 場合も所有権喪失として扱う (deny-by-default)。
     *
     * @throws JobOwnershipLostException
     */
    private function assertStillOwned(AnalysisJob $job, AnalysisStep $step): void
    {
        $current = AnalysisJob::query()->whereKey($job->getKey())->first();
        if ($current !== null && $current->status === JobStatus::Running) {
            return; // アーリーリターン (正常系)
        }

        throw JobOwnershipLostException::whileRunning(
            jobType: AnalysisJob::class,
            jobId: $job->id,
            actualStatus: $current?->status,
            stage: $step->value,
            externalCall: ExternalCallKind::LlmCompletion,
        );
    }
```

```php
    /** decompose 段: 作業分解表 + result_json 保存 (write-only 監査スナップショット) */
    private function runDecomposeStep(/* … */): WorkDecompositionData
    {
        $decomposition = $this->withBoundedRetry(/* … */);

        // 終端後の自前書き込みを塞ぐ: 進捗と result_json は running のときだけ書く
        $this->writeProgress($job, [
            'result_json' => $decomposition->toArray(),
            'step' => AnalysisStep::Generate->value,
            'progress' => 65,
        ]);

        return $decomposition;
    }

    /**
     * step/progress の表示用更新。
     *
     * ★ **条件付き UPDATE (`where status=running`)** にする理由 (Round 1 レビュー反映):
     *   preflight で「terminal 化後は外部を呼ばない」ようにした以上、
     *   「terminal 化後に自前の DB を書く」経路も同時に塞ぐ。素の `save()` だと
     *   stale 回復 cron が failed にした行へ step/progress/updated_at を書き戻し、
     *   「failed なのに progress=65」という不整合を作る。
     * ★ `Builder::update()` は `updated_at` を自動付与する (stale 判定の
     *   「最終 step 更新時刻」という意味は従来どおり。ただし terminal 行では動かない)。
     * ★ 状態機械は status のみが真実源であり、本メソッドは status を書かない。
     *   **array shape で書ける列を閉じている** (Round 2 レビュー反映) —
     *   `status` 等の保護列を渡せないことを PHPStan level 10 が静的に弾く。
     *
     * @param  array{step: string, progress: int, result_json?: array<string, mixed>}  $attributes
     */
    private function writeProgress(AnalysisJob $job, array $attributes): void
    {
        AnalysisJob::query()
            ->whereKey($job->getKey())
            ->where('status', JobStatus::Running->value)
            ->update($attributes);
    }

    private function updateProgress(AnalysisJob $job, AnalysisStep $step, int $progress): void
    {
        $this->writeProgress($job, ['step' => $step->value, 'progress' => $progress]);
    }
```

> **`SourceDocument::extracted_json` を guard しない理由**: これは write-only の
> 監査スナップショットであって状態機械の一部ではなく、guard には job → document の join が要る。
> failed 行の document に抽出結果が残っても不整合にならない (むしろ調査に役立つ)。
> この判断を `runExtractStep()` の docblock に残す。

> **抑止できる呼び出し回数** (概念設計 Round 4 の指摘への回答):
> `manual.analysis_llm_max_retries = N` のとき 1 段あたり最大 `N+1` 試行。
> extract の 1 試行目で terminal を検出した場合に抑止されるのは
> **残り `3(N+1) - 1` 試行**であり、検出時点に依存する。
> 「最大 3 回」のような固定値は主張しない。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`void` / `mixed` + `@return T`)
- [x] null 安全 — `first()` の `?AnalysisJob` を `!== null &&` で narrowing。
      `$current?->status` で null 伝播。`Assert` は不要 (型で閉じている)
- [x] DTO を返している (配列返却なし)
- [x] Generics — `@template T` / `@param callable(): T` は現行のまま維持
- [x] `$job->id` は `@property int $id` (`app/Models/AnalysisJob.php` L23) で int 確定。
      `getKey()` の `mixed` はクエリ引数としてのみ使い、例外へは `$job->id` を渡す

### テスト計画

`tests/Feature/Projects/AnalysisPipelineTest.php` へ **追記** (既存 test は 1 件も削除・改変しない)。
既存ヘルパ `pipelineContext()` / `installThrowingLlm($script, $onAttempt)` /
`successfulLlmScript()` をそのまま使う。テストデータは Factory (`pipelineContext()` が
`Project::factory()` / `VideoManual::factory()` / `AnalysisJob::factory()` を使用)。

- [ ] `preflight: extract の 1 回目直後に cron が failed 化 → 以降の LLM を 1 回も呼ばない`
  - `installThrowingLlm(successfulLlmScript(), onAttempt: fn (int $n) => $n === 1
    ? app(AnalysisJobService::class)->failJob($job->refresh(), 'stale') : null)`
  - 期待: `$fake->attemptCount() === 1` (decompose / generate は呼ばれない)
  - 期待: `$job->refresh()->status === JobStatus::Failed` かつ `error` が cron の文言のまま
    (preflight 経路が failJob を**上書きしない**こと)
- [ ] `preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない`
  - **Round 1 レビュー反映**。`failJob` 直後の `step` / `progress` / `updated_at` を控えておき、
    `run()` 完了後に**一致していること**を検査する (条件付き UPDATE 化の behavioral 固定)
- [ ] `preflight: 所有権喪失時に通知が飛ばない`
  - `Notification::fake()` (既存テストと同じ作法) で `assertNothingSent()`
- [ ] `preflight: 所有権喪失時に予約が二重 release されない`
  - 予約は cron 側の failJob が Released にしているため、
    `TicketReservation` の `status` が `Released` で **台帳エントリが増えていない**ことを検査
- [ ] `preflight: 所有権喪失は固定 event 名で warning ログに出る`
  - **メッセージ文字列に依存しない** (Round 1 レビュー反映)。`Log::spy()` +
    `shouldHaveReceived('warning')->withArgs(fn (string $m, array $c): bool =>
    ($c['event'] ?? null) === ExternalCallKind::LOG_EVENT)` で **context の `event` キー**を見る
    (既存の「LLM 呼び出しを再試行します」warning と混ざらない)
- [ ] `preflight: 正常系では 1 段あたり 1 回だけ追加 SELECT が入り、結果は不変`
  - 既存の成功パステストが green のままであることで担保 (新規 assert は置かない)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **リトライ経路の振る舞い変化**: これまで「旧ワーカーが最後まで走り finalize で false」だったものが
  「途中で例外 → 専用 catch → return」に変わる。`failJob` を呼ばないため
  **通知とチケット release の呼び出し回数が減る**(増えない)。
  cron 側が既に両方を済ませているため、ユーザーから見た最終状態は不変。
  → 上記テスト 2 件 (通知 / 予約) がこれを固定する。
- **DB クエリの増加**: 1 段 1 試行あたり主キー SELECT 1 本。
  最悪 `3 × (N+1)` 本 (N は既定の再試行回数)。実行時間への影響は無視できる。
- `withBoundedRetry()` のシグネチャ変更は private メソッドで呼び出し元 3 箇所のみ。
- **`updateProgress()` の条件付き UPDATE 化**: 正常系 (running) の挙動は不変だが、
  `save()` → `Builder::update()` になるため **model event が発火しなくなる**。
  `AnalysisJob` に `saving` / `saved` の observer / event listener が無いことを実装時に確認する
  (無ければ影響ゼロ。あれば設計を見直す)。
  in-memory の `$job` も更新されなくなるが、`finalize()` は `lockForUpdate()` で
  読み直すため load-bearing ではない。

---

## S3. レンダパイプラインの preflight (S3 PUT 直前)

### 変更箇所

- `app/Services/Manual/RenderPipeline.php`
  - `run()` (L94-98): `updateProgress()` の**後**・`storage->upload()` の**直前**に preflight
  - `run()` の catch 節 (L113): `JobOwnershipLostException` 専用 catch を先に追加
  - `updateProgress()` (L386-391): **条件付き UPDATE 化** (`where status=running`)。
    compose は長時間 (最大 25 分) で `onClipComposed()` から高頻度に呼ばれるため、
    **terminal 化後の進捗書き戻しが最も起きやすい経路**である (Round 1 レビュー反映)
  - `assertStillOwned()` を private メソッドとして新設

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Manual/RenderPipelineTest.php` へ**追記**
  (`FakeRenderComposer::$duringCompose` フックと `Storage::fake('s3')` を使う)
- 時間 budget: **変更なし** (`RenderTimeBudgetInvariantTest` に無影響)

### 現行コード

```php
            $manifest = $this->buildManifest($job);

            // compose (DB 外・ロック外)
            $workDir = $this->makeWorkDir($job);
            $localSources = $this->downloadSources($manifest, $workDir);
            $composed = $this->composer->compose(/* … */);
            $this->updateProgress($job, RenderStep::Concat, 90);

            // upload → finalize (terminal tx)
            $this->storage->upload($composed->localPath, $manifest->outputKey);
            $uploadedKey = $manifest->outputKey;
            // …
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->errorCodeFor($exception), $this->userMessageFor($exception));
        } finally {
            // アップロード済みで succeeded 未達の出力はベストエフォート削除
            if ($uploadedKey !== null) { /* … */ }
            if ($workDir !== null) {
                File::deleteDirectory($workDir);
            }
        }
```

### 変更後コード

```php
            $this->updateProgress($job, RenderStep::Concat, 90);

            // ★ preflight suppression (裁定 AG-082 標準形 (2)): S3 PUT の直前で所有権を再検証する。
            //   updateProgress() という**自前の書き込みの後**に置くことが要点
            //   (書き込みの前に検証すると、書き込み中の接続断で旧担当が PUT できる窓が開く)。
            //   ffmpeg compose / S3 GET の前には置かない — ローカル CPU と冪等な読み取りであり、
            //   取り消せない外部副作用を持たないため (docs/architecture.md の残余窓 3)。
            $this->assertStillOwned($job, RenderStep::Concat);

            // upload → finalize (terminal tx)
            $this->storage->upload($composed->localPath, $manifest->outputKey);
            $uploadedKey = $manifest->outputKey;
```

```php
        } catch (JobOwnershipLostException $exception) {
            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
            // failJob も通知もチケット release も呼ばない。$uploadedKey は null のままなので
            // finally の後始末は work dir の削除だけを行う (孤児オブジェクトを作らずに降りる)。
            Log::warning('レンダジョブの所有権を失ったため出力アップロードを中止しました', $exception->logContext());
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->errorCodeFor($exception), $this->userMessageFor($exception));
        } finally {
            // …現行のまま
        }
```

```php
    /**
     * 所有権の再検証 (preflight suppression)。AnalysisPipeline と同型
     * (§10.8 方針: 共通抽象化しない。個別実装を見本に合わせる)。
     *
     * @throws JobOwnershipLostException
     */
    private function assertStillOwned(RenderJob $job, RenderStep $step): void
    {
        $current = RenderJob::query()->whereKey($job->getKey())->first();
        if ($current !== null && $current->status === JobStatus::Running) {
            return; // アーリーリターン (正常系)
        }

        throw JobOwnershipLostException::whileRunning(
            jobType: RenderJob::class,
            jobId: $job->id,
            actualStatus: $current?->status,
            stage: $step->value,
            externalCall: ExternalCallKind::ObjectStoragePut,
        );
    }
```

```php
    /**
     * step/progress の表示用更新 (AnalysisPipeline::writeProgress と同型)。
     *
     * ★ **条件付き UPDATE (`where status=running`)**。compose は最大 25 分走り、
     *   `onClipComposed()` から高頻度に呼ばれるため、terminal 化後の書き戻しが
     *   最も起きやすい経路である (「failed なのに progress=62」を作らない)。
     */
    private function updateProgress(RenderJob $job, RenderStep $step, int $progress): void
    {
        RenderJob::query()
            ->whereKey($job->getKey())
            ->where('status', JobStatus::Running->value)
            ->update(['step' => $step->value, 'progress' => $progress]);
    }
```

> **`return` ではなく catch 節で降りる理由**: `run()` は `finally` で work dir を必ず削除する。
> `catch` で受けて自然に `finally` へ流すことで、片付け経路を 1 本に保つ
> (現行の `RenderScenarioChangedException` 等と同じ流れ)。
> `RenderPipeline` は `Log` facade を現在 import していないため、`use Illuminate\Support\Facades\Log;` を追加する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`void`)
- [x] null 安全 — `first()` の `?RenderJob` を narrowing、`?->status` で伝播
- [x] DTO を返している (配列返却なし)
- [x] Generics — 該当なし
- [x] `$job->id` は `@property int $id` で int 確定

### テスト計画

`tests/Feature/Manual/RenderPipelineTest.php` へ **追記**。既存の `FakeRenderComposer`
(`duringCompose` フック) と `Storage::fake('s3')` をそのまま使う。テストデータは既存の
Factory 経路 (`RenderJob::factory()` / `Take::factory()` / `Cut::factory()`)。

**主契約** (これが落ちたら設計が壊れている):

- [ ] `preflight: compose 中に cron が failed 化 → S3 へ 1 件も PUT しない`
  - `$fake->duringCompose = fn () => app(RenderJobService::class)
    ->failJob($job->refresh(), RenderErrorCode::Timeout, 'stale')`
  - 期待: `Storage::disk('s3')->assertMissing($expectedOutputKey)`
  - 期待: `$job->refresh()->output_path === null` / `status === Failed`
- [ ] `preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない`
  - **Round 1 レビュー反映**。`duringCompose` で failJob した直後の
    `step` / `progress` / `error` / `error_code` を控え、`run()` 完了後に一致を検査する
    (compose のクリップごとの `onClipComposed()` が書き戻さないこと)
- [ ] `preflight: 所有権喪失時に failJob が二重に走らない (error / error_code が cron の値のまま)`
- [ ] `preflight: 所有権喪失時に work dir が削除される (finally を通る)`
  - `storage_path("app/render/{$job->id}")` が存在しないことを検査
- [ ] `preflight: preview (kind=preview) でも同じく PUT しない` (予約を持たない経路の回帰)

**補助契約** (実装の内部事情に依存するため主契約から分離。Round 1 レビュー反映):

- [ ] `preflight: 所有権喪失時に完了通知が飛ばない` — `Notification::fake()` + `assertNothingSent()`
- [ ] `preflight: 所有権喪失時に DeleteRenderOutputsJob が dispatch されない` — `Queue::fake()`
  - **主契約は「S3 に PUT していない」「`output_path` が null」**であり、
    dispatch の有無は `finalize()` の内部実装に依存する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`finally` の `$uploadedKey` が null のまま**なので、既存の「後始末 delete」経路には入らない。
  これは意図どおり (そもそも PUT していない)。
- compose 完了後〜preflight 前に terminal 化した場合、ffmpeg の計算は無駄になる。
  これは受容 (無駄な計算の削減は本設計の目的ではない。概念設計スコープ外)。

---

## S4. auto-recharge の preflight (Stripe 呼び出し直前) + 中断時の invoice 終端

### 変更箇所

- `app/Services/Billing/AutoRechargeService.php`
  - `executeAttemptLocked()` (L528-570): Stripe 2 呼び出しの直前へ preflight を挿入し、
    `stripe_invoice_id` の永続化を**条件付き UPDATE** 化する
  - `stillPending()` / `terminateInvoiceAfterOwnershipLost()` を private メソッドとして新設
  - `tryTerminateInvoice()` (L669) / `terminateAndCancel()` / `terminateAndFail()` /
    `recordSuccessfulCharge()` / `handleChargeFailure()`: **いずれも変更しない**

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- 外部ゲートウェイ interface (`AutoRechargeGatewayInterface`): **変更しない**
  - Codex Round 3 要件 (i)「終端操作にも attempt に固定した idempotency key を使う」は
    **反論する**: `terminateInvoice()` は Stripe から invoice を `retrieve` して
    `void`/`deleted` → 成功扱い、`404` → 成功扱い、`paid` → `Assert` で明示的な非成功、
    `draft` → delete、`open`/`uncollectible` → void と**状態検査で冪等化**されている
    (`CashierAutoRechargeGateway::terminateInvoice()` L145-180)。
    idempotency key は「24 時間以内の再送」しか重複排除しないのに対し、状態検査は
    期限がなく**より強い**。既存の冪等化を捨てて key へ寄せる理由がない。
  - 要件 (ii)「void 対象の invoice ID が当該 attempt に保存された値と一致する」は
    `tryTerminateInvoice($attempt)` が `$attempt->stripe_invoice_id` のみを読むため
    **構造的に満たされる** (`stillPending()` が直前に `refresh()` 済み)。
  - 要件 (iii)「already void / paid の分類」は上記のとおり既存実装が満たす。
- テストファイル: `tests/Feature/Billing/AutoRechargeServiceTest.php` へ**追記**
  (`Tests\Support\FakeAutoRechargeGateway` を `app()->instance()` で注入する既存作法)
- テスト支援クラス: `tests/Support/FakeAutoRechargeGateway.php` に
  **`public ?Closure $duringCreateInvoice = null;` フックを追加**する
  (`FakeRenderComposer::$duringCompose` と同じ作法。既存プロパティ
  `$createdInvoices` / `$payCalls` / `$terminated` / `$failOnTerminate` は変更しない)。
  `attach 0 行` の競合点を決定論的に再現するために必要
- **台帳側の保証 (変更しない。明記のみ)**: `TicketLedgerService::grantAutoRecharge()` は
  `insertIdempotent($organization, "recharge:{$stripeInvoiceId}", …)` を使う。
  冪等キーは **invoice 単位で UNIQUE** であり、同じ invoice への付与は何度呼んでも 1 件しか入らない。
  したがって `recordSuccessfulCharge()` が「grant → attempt の条件付き UPDATE」の順であることは
  **矛盾ではなく、冪等キーが 2 本ある**ことを意味する (Round 1 レビューへの反論):
  - 付与の一回性 … 台帳の `recharge:{invoiceId}` UNIQUE (**invoice 単位**)
  - attempt 遷移の一回性 … `where('status','pending')->update()` (**attempt 単位**)

  順序を入れ替える (attempt 遷移が 1 行のときだけ grant する) と、canceled 化と実課金が
  競合した場合に「**Stripe で課金済みなのにチケット未付与**」という**より悪い**不整合が生じる。
  現行順序は「取られた金は必ず台帳に載せる」という意図的な設計であり、**変更しない**。
  この 2 本立てを S6 の目録の根拠文へ記録し、Feature テストで固定する。

### 現行コード

```php
    private function executeAttemptLocked(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        // lock 取得後に fresh 再読込 (停止側のキャンセルが先行していたら no-op)。
        $attempt->refresh();
        if ($attempt->status !== AutoRechargeAttemptStatus::Pending) {
            return;
        }

        // 停止後課金の禁止: lock 内で enabled を確認 (以降 disable は本実行の完了まで割り込めない)。
        if (! $this->isEnabledFor($organization)) {
            $this->terminateAndCancel($attempt);

            return;
        }

        $keyBase = $this->idempotencyKeyBase($attempt);

        $invoiceId = $attempt->stripe_invoice_id;
        if ($invoiceId === null) {
            $invoiceId = $this->gateway->createAutoRechargeInvoice(
                $organization,
                $attempt->stripe_price_id,
                $attempt->quantity,
                $this->metadataFor($organization, $attempt),
                $keyBase,
            );
            // invoice_id の永続化は pay より必ず前 (プロセス死でも迷子 invoice を作らない)。
            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->save();
        }

        $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);

        if ($result->paid) { /* … */ }

        $this->handleChargeFailure(/* … */);
    }
```

### 変更後コード

```php
    private function executeAttemptLocked(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        // lock 取得後に fresh 再読込 (停止側のキャンセルが先行していたら no-op)。
        $attempt->refresh();
        if ($attempt->status !== AutoRechargeAttemptStatus::Pending) {
            return;
        }

        // 停止後課金の禁止: lock 内で enabled を確認 (以降 disable は本実行の完了まで割り込めない)。
        if (! $this->isEnabledFor($organization)) {
            $this->terminateAndCancel($attempt);

            return;
        }

        $keyBase = $this->idempotencyKeyBase($attempt);

        $invoiceId = $attempt->stripe_invoice_id;
        if ($invoiceId === null) {
            // ★ preflight 1: invoice 作成の直前。org lock は TTL 180 秒で切れうるため
            //   (lock は best-effort。保証は本再検証と条件付き UPDATE と Stripe 冪等キー)。
            if (! $this->stillPending($attempt, ExternalCallKind::StripeInvoiceCreate)) {
                return; // invoice 未作成なので収束は自明 (残す open invoice が無い)
            }

            $invoiceId = $this->gateway->createAutoRechargeInvoice(
                $organization,
                $attempt->stripe_price_id,
                $attempt->quantity,
                $this->metadataFor($organization, $attempt),
                $keyBase,
            );

            // invoice_id の永続化は pay より必ず前 (プロセス死でも迷子 invoice を作らない)。
            // ★ **条件付き UPDATE** にする (Round 1 レビュー反映): 素の save() だと
            //   停止側が先に canceled 化した terminal 行へ invoice_id を後から書き込むことになり、
            //   状態機械の例外を作る。0 行なら「attempt へ紐付けられなかった invoice」であり、
            //   DB の値に依存せずローカルの $invoiceId で終端する。
            $attached = TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'stripe_invoice_id' => $invoiceId,
                    'updated_at' => CarbonImmutable::now(),
                ]);

            if ($attached !== 1) {
                // ★ attach 失敗は **status を問わず**終端する (Round 2 レビュー反映)。
                //   この invoice ID を知っているのは自分だけであり、
                //   terminal 化させた側は stripe_invoice_id === null を見ているため終端できない。
                $this->terminateUnattachedInvoice($attempt->refresh(), $invoiceId);

                return;
            }
            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->syncOriginal(); // in-memory 同期 (再 save しない)
        }

        // ★ preflight 2: pay の直前。**直前に自前の書き込み (invoice_id の永続化) を挟んだため
        //   必ずもう一度検証する** (裁定 AG-082: 検証の後に自前の書き込みを挟むと、
        //   接続断で旧担当が送信できる窓が開く)。
        //   既存 invoice を再利用する経路 (上の if を通らない場合) でもここが唯一の関門になる。
        if (! $this->stillPending($attempt, ExternalCallKind::StripeInvoicePay)) {
            $this->terminateInvoiceAfterOwnershipLost($attempt, $invoiceId);

            return;
        }

        $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);

        // …以降は現行のまま
    }

    /**
     * Stripe 呼び出しの直前に attempt の所有権 (= pending) を再検証する
     * (preflight suppression。裁定 AG-082 標準形 (2))。
     *
     * @return bool 送信してよいか (false = 所有権喪失 → 呼び出し側が中断する)
     */
    private function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
    {
        $attempt->refresh();
        if ($attempt->status === AutoRechargeAttemptStatus::Pending) {
            return true; // アーリーリターン (正常系)
        }

        // Manual ドメインと**同じ最小キー集合**で観測する (集計の語彙を 1 本に保つ。
        // JobOwnershipLostException::logContext() と 7 キーが一致する。
        // Billing 固有の追加キーは attempt_ulid の 1 本だけに限定する)。
        Log::warning('auto-recharge: 所有権を失ったため Stripe 呼び出しを中止しました', [
            'event' => ExternalCallKind::LOG_EVENT,
            'job_type' => TicketAutoRechargeAttempt::class,
            'job_id' => $attempt->id,
            'expected_status' => AutoRechargeAttemptStatus::Pending->value,
            'actual_status' => $attempt->status->value,
            'stage' => 'execute_attempt',
            'external_call' => $call->value,
            'attempt_ulid' => $attempt->attempt_ulid, // Billing 固有 (PII ではない ULID)
        ]);

        return false;
    }

    /**
     * preflight 2 で中断したときの invoice 後始末。
     *
     * **canceled のときだけ**終端する:
     *  - paid  … void できない (付与経路の管轄)
     *  - failed… `terminateAndFail()` が **`stripe_invoice_id` を DB 経由で見えている状態**で
     *    終端済み (attach 済みだからこの分岐に来ている)
     *  - canceled … 停止側の `tryTerminateInvoice()` は `stripe_invoice_id === null` を
     *    「invoice 未作成」と解釈して素通りするため、こちらの永続化が停止より後だと
     *    **誰も void しない open invoice が残る**。ここで拾う。
     *
     * ★ attach に失敗した invoice は本メソッドではなく `terminateUnattachedInvoice()` の担当
     *   (あちらは status を問わず終端する。Round 2 レビュー反映)。
     */
    private function terminateInvoiceAfterOwnershipLost(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        if ($attempt->status !== AutoRechargeAttemptStatus::Canceled) {
            return; // アーリーリターン
        }

        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
    }

    /**
     * attempt 行へ紐付けられなかった (条件付き UPDATE が 0 行だった) invoice の後始末。
     *
     * ★ **status を問わず終端を試みる** (Round 2 レビュー反映)。
     *   この invoice ID を知っているのは自分だけであり、terminal 化させた側は
     *   `stripe_invoice_id === null` を見ているため終端できない。
     *   canceled 限定にすると failed 経路で**誰も終端しない open invoice**が残る。
     * ★ `paid` の可能性は `CashierAutoRechargeGateway::terminateInvoice()` の状態検査が
     *   `Assert` で fail-closed に分類する (例外 → `terminated=false` としてログに残る)。
     */
    private function terminateUnattachedInvoice(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
    }

    /**
     * invoice の best-effort 終端 + 固定 event 名でのログ (上 2 つの共通部)。
     *
     * ★ `$invoiceId` を**引数で受ける** (Round 1 レビュー反映)。
     *   attempt 行に永続化できなかった invoice も終端したいため、DB の値に依存しない。
     * ★ `tryTerminateInvoice($attempt)` を再利用しない理由: あちらは
     *   `$attempt->stripe_invoice_id` を読むため「永続化できなかった invoice」を扱えず、
     *   かつ独自の warning を出すのでログが二重になる。ここは固定 event の 1 行に閉じる。
     * ★ `CashierAutoRechargeGateway::terminateInvoice()` は Stripe から retrieve して
     *   void/deleted/404 → 成功扱い、paid → `Assert` で明示的な非成功、draft → delete、
     *   open/uncollectible → void と**状態検査で冪等化**されている
     *   (idempotency key より強い — 期限が無い)。
     * ★ 失敗しても**課金処理へは進まない** (呼び出し側が無条件に return する)。
     *   残った open invoice は reconcile の母集団外なので、運用契約 (docs/architecture.md) の
     *   手動収束に委ねる。
     * ★ **cleanup 専用の event 名**を使う (Round 2 レビュー反映)。
     *   送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を持つ契約であり、
     *   キー集合の違うログを同じ event 名に混ぜない。
     */
    private function terminateInvoiceBestEffort(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        $terminated = true;
        $error = null;
        try {
            $this->gateway->terminateInvoice($invoiceId);
        } catch (Throwable $exception) {
            $terminated = false;
            $error = $exception->getMessage(); // paid 等の「明示的な非成功」もここに落ちる
        }

        Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
            'event' => ExternalCallKind::CLEANUP_LOG_EVENT,
            'job_type' => TicketAutoRechargeAttempt::class,
            'job_id' => $attempt->id,
            'attempt_ulid' => $attempt->attempt_ulid,
            'invoice_id' => $invoiceId,
            'terminated' => $terminated,
            'error' => $error,
        ]);
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`bool` / `void`)
- [x] null 安全 — `$attempt->status` は `@property AutoRechargeAttemptStatus $status` で非 null。
      `$attempt->stripe_invoice_id` は `?string` だがログへ渡すだけ
- [x] DTO を返している (配列返却なし。ログ配列は Monolog context)
- [x] Generics — 該当なし
- [x] `Assert` — 追加不要 (既存の `Assert::isInstanceOf($organization, ...)` を維持)

### テスト計画

`tests/Feature/Billing/AutoRechargeServiceTest.php` へ **追記** (既存 test は改変しない)。
テストデータは Factory (`TicketAutoRechargeAttempt::factory()` /
`TicketAutoRecharge::factory()` / `createOrganizationWithOwner()`)。

- [ ] `preflight 1: invoice 作成前に attempt が canceled → createAutoRechargeInvoice を呼ばない`
  - gateway fake の呼び出し記録が空であること
- [ ] `preflight 2: invoice 作成後・pay 前に attempt が canceled → payOffSessionInvoice を呼ばない`
  - fake の `createAutoRechargeInvoice` は 1 回・`payOffSessionInvoice` は 0 回
  - **`terminateInvoice` が作成された invoice id で 1 回呼ばれる** (要件 ii)
- [ ] `attach 0 行: invoice 作成成功と同時に canceled 化 → invoice_id を書かず invoice を終端する`
  - **Round 1/2 レビュー反映**。競合点を正確に再現するため、
    `tests/Support/FakeAutoRechargeGateway` に `public ?Closure $duringCreateInvoice = null;`
    を追加し (`FakeRenderComposer::$duringCompose` と同じ作法)、
    **invoice ID を返す直前**に attempt を terminal 化させる。
    再現する順序: `preflight 1 成功 → Stripe 作成成功 → 並行 terminal 化 → attach 0 行 → 終端`
  - 期待: `stripe_invoice_id` が **DB 上 null のまま**
  - 期待: `terminateInvoice` が作成された invoice id で 1 回呼ばれる
    (= DB に保存済みであることに依存しない終端)
  - 期待: `payOffSessionInvoice` は 0 回
- [ ] `attach 0 行: failed へ遷移していた場合も invoice を終端する`
  - **Round 2 レビュー反映**。`terminateUnattachedInvoice()` は **status を問わない**。
    canceled 限定にすると、invoice ID を知らない failed 経路で
    **誰も終端しない open invoice** が残るため
- [ ] `preflight 2: attempt が paid のときは terminateInvoice を呼ばない` (void 不可の分類)
- [ ] `preflight 2: attempt が failed のときは terminateInvoice を呼ばない` (二重終端の抑止)
- [ ] `preflight 2: terminateInvoice が例外を投げても課金処理へ進まない` (要件 v)
  - fake の `terminateInvoice` を throw させ、`payOffSessionInvoice` が 0 回であること
  - 台帳エントリが 1 件も増えていないこと
- [ ] `同一 invoice への grantAutoRecharge は台帳へ 1 件しか入らない`
  - **Round 1 レビュー反映**。`recordSuccessfulCharge()` を同一 invoice id で 2 回呼び、
    `TicketLedgerEntry` が 1 件しか増えないこと (`recharge:{invoiceId}` UNIQUE の behavioral 固定)
- [ ] `所有権喪失ログが固定 event 名 job_ownership_lost を含み、キー集合が Manual 側と一致する`
  - `Log::spy()` + context の `event` キーで判定 (メッセージ文字列に依存しない)
  - 最小 7 キー (`event` / `job_type` / `job_id` / `expected_status` / `actual_status` /
    `stage` / `external_call`) が揃い、Billing 固有の追加は `attempt_ulid` のみであること
  - **PII (email / name) 由来のキーを含まないこと** (Manual 側と同じ検査を Billing にも置く)
- [ ] `後始末ログは別 event 名 job_ownership_lost_cleanup を使い、独自 schema を持つ`
  - **Round 2 レビュー反映**。`event` / `job_type` / `job_id` / `attempt_ulid` /
    `invoice_id` / `terminated` / `error` の 7 キーであること
  - **抑止ログ (`job_ownership_lost`) と後始末ログが同じ event 名に混ざらないこと**
    (同一 event = 同一集計 schema という契約を守る)
- [ ] `Stripe idempotency key が操作ごとに異なり attempt_ulid に pin されている`
  - `CashierAutoRechargeGateway` が組む 4 キー
    (`{base}:invoice` / `{base}:item` / `{base}:finalize` / `{base}:pay`) が
    互いに異なり、いずれも `auto-recharge:{attempt_ulid}` を prefix に持つ
  - **これは Codex Round 2 Suggestion への対応**。既存実装の性質を初めて固定する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **preflight 1 は既存の冒頭 `refresh()` + Pending 検査と近接**しており、実質的な追加検出は
  `isEnabledFor()` の実行時間分だけである。それでも置くのは
  「すべての外部呼び出しの直前に preflight がある」という不変条件を
  **読み手が目録から辿れる形に揃える**ためで、コストは PK SELECT 1 本。
  (配置そのものを保証するのは Feature テストであり、S6 の gate ではない。)
- **新しい外部呼び出しを 1 つ増やす** (`terminateInvoice`)。これは
  「中断したのに open invoice を残す」ほうが有害という判断。
  ただし**新しい残余窓は作らない** — 終端の成否にかかわらず課金へは進まない。
- `tryTerminateInvoice()` は `Assert` 失敗 (paid) も `Throwable` として握るため、
  誤って paid で呼ぶと無害だが紛らわしい警告が出る。→ `Canceled` 限定で呼ぶ設計にした。

---

## S5. 排他 TTL / uniqueFor の序列を CI 固定

### 変更箇所

- `app/Services/Billing/AutoRechargeService.php` L66:
  `private const int LOCK_TTL_SECONDS = 180;` → **`public const int LOCK_TTL_SECONDS = 180;`**
  (値は変えない。AGENTS.md 思考原則「仕組みが機能していない段階で値を弄るな」)
- 新規 `tests/Architecture/JobExclusionOrderingInvariantTest.php`

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 新規 Architecture テスト 1 本のみ。
  既存の `QueueWorkerLeaseInvariantTest` / `QueuedJobLeaseInventoryTest` /
  `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` は**触らない**
  (対象が「リース側の序列」で母集団が交わらない)

### 現行コード

```php
    private const int LOCK_TTL_SECONDS = 180;
```

`uniqueFor` 側 (`app/Jobs/Billing/AutoRechargeTriggerJob.php`):

```php
    public int $uniqueFor = 30;
```

これらの序列を検査するテストは `tests/` 全体に **0 件**。

### 変更後コード

```php
    /**
     * org 単位 `Cache::lock` の TTL (秒)。
     *
     * ★これは**入口の排他**であり、結果の一回性を保証しない (裁定 AG-082)。
     *   保証は (a) 外部呼び出し直前の preflight、(b) `where status=pending` の条件付き UPDATE、
     *   (c) Stripe idempotency key が担う。
     * ★したがって値は「保証を代替できる長さ」ではなく**短い側**に倒す。
     *   `JobExclusionOrderingInvariantTest` が
     *   `LOCK_TTL_SECONDS < queue.connections.database.retry_after` を CI 固定する
     *   (鍵の残留が正当な再実行を封鎖する時間が、キューの再配送間隔を超えない)。
     */
    public const int LOCK_TTL_SECONDS = 180;
```

```php
<?php

declare(strict_types=1);

use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Services\Billing\AutoRechargeService;

/*
 * 入口の排他 (Cache::lock TTL / ShouldBeUnique の uniqueFor) の**序列**を CI 固定する。
 *
 * 裁定 AG-082: 入口の排他は best-effort であり、結果の一回性を保証しない。
 * したがって「保証を代替できるほど長く」してはならない — 鍵が残留すると、
 * 正当な再実行 (§10.8-1「再実行は analyze/render 再トリガーのみ」) を最大 TTL 秒ブロックする。
 *
 * ★比較先を「マジックナンバー」ではなく **その接続の retry_after** にしているのが要点。
 *   鍵の残留がキューの再配送間隔を超えないことを保証すれば、封鎖時間が構造的に有界化される。
 *
 * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
 */

test('入口の排他: auto-recharge の org lock TTL は既定接続の retry_after を下回る', function (): void {
    $retryAfter = config()->integer('queue.connections.database.retry_after');

    expect(AutoRechargeService::LOCK_TTL_SECONDS)->toBeLessThan(
        $retryAfter,
        'org lock TTL がキューの再配送間隔以上です。ゴーストロックが同一ジョブの再配送より'
        .'長く残ると、正当な再実行を封鎖します。TTL は保証を担わないため短い側に倒すこと。',
    );
});

test('入口の排他: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回る', function (): void {
    $retryAfter = config()->integer('queue.connections.database.retry_after');

    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeLessThan(
        $retryAfter,
        'uniqueFor がキューの再配送間隔以上です。ShouldBeUnique の鍵は失敗や timeout で'
        .'解放されないことがあるため (Laravel 公式)、残留時間を再配送間隔の内側に収めること。',
    );
});

test('入口の排他: uniqueFor は正の値である (実質無効化の検出)', function (): void {
    // 0 / 負値は「鍵を持たない」に等しく、ShouldBeUnique の宣言が静かに空洞化する
    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeGreaterThan(0);
});

test('入口の排他: 比較先の前提 — auto-recharge の 2 ジョブは既定接続で動く', function (): void {
    // ★ Round 1 レビュー反映: 接続 pin (T127: 既定キュー接続の分割) が入ると
    //   `database.retry_after` との比較が意味を失う。前提が崩れた瞬間に赤くする。
    //   ★ 他テストファイルのグローバル定数 (QUEUED_JOB_LEASE_INVENTORY) は参照しない —
    //     Pest の --parallel はファイル単位でプロセスを分けるため未定義になりうる。
    //     ジョブ実体から直接読めば単体で成立する。
    expect((new AutoRechargeTriggerJob(1))->connection)->toBeNull();
    expect((new ExecuteAutoRechargeAttemptJob(1))->connection)->toBeNull();
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (テストクロージャは `void`)
- [x] null 安全 — `config()->integer()` を使い `mixed` を作らない
- [x] DTO を返している (該当なし)
- [x] Generics — 該当なし
- [x] const の可視性変更は型に影響しない (`public const int`)

### テスト計画

上記 4 ケースがテスト本体。加えて:

- [ ] **mutation で赤化を確認**する (下記 §gate の受け入れ手順 M5)
- [ ] 既存 `tests/Feature/Billing/AutoRechargeServiceTest.php` は無影響
      (const の可視性変更のみで値は不変)
- [ ] 個別の `DatabaseTransactions` を使っていない (DB を触らない)

### リスク

- `LOCK_TTL_SECONDS` を public にすると外部から参照可能になる。
  意図的な公開 (不変条件の契約) であることを docblock に書くことで濫用を抑える。
- T127 (既定キュー接続の分割) が入ると `queue.connections.database.retry_after` の意味が変わる。
  そのときは本テストの比較先を差し替える必要がある → docblock に T127 との関係を明記する。

---

## S6. 横断 gate (deny-by-default 目録) + 母集団走査の 1 本化

### 変更箇所

- 新規 `app/Enums/Security/JobDedupGuarantee.php` — **永続状態遷移の機構**の分類
- 新規 `app/Enums/Security/JobDedupExemption.php` — **免除**の分類
- 新規 `tests/Support/QueuedJobPopulation.php` — 母集団走査の**唯一の実装**
- 新規 `tests/Support/JobDedup/PreflightRequirement.php` (interface)
- 新規 `tests/Support/JobDedup/PreflightControlFlow.php` (enum)
- 新規 `tests/Support/JobDedup/PreflightCheckpoint.php` (`final readonly`)
- 新規 `tests/Support/JobDedup/NoExternalCall.php` (`final readonly`)
- 新規 `tests/Support/JobDedup/GuaranteeEntry.php` (`final readonly`)
- 新規 `tests/Support/JobDedup/ExemptionEntry.php` (`final readonly`)
- 新規 `tests/Architecture/JobExecutionDedupInventoryTest.php`
- 変更 `tests/Architecture/QueuedJobLeaseInventoryTest.php` — 走査 3 関数を
  `QueuedJobPopulation` への**委譲に置き換える** (目録定数 `QUEUED_JOB_LEASE_INVENTORY` と
  既存テストケースは 1 件も変更しない)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: `QueuedJobLeaseInventoryTest.php` の走査関数のみ (テストケースは不変)。
  **母集団の走査実装を 1 本にすることで、2 つの目録が別々の母集団を見て drift する
  リスクを根で断つ** (recon-brief のリスク (1) への構造的回答)

### 現行コード

`tests/Architecture/QueuedJobLeaseInventoryTest.php` L87-147:

```php
function jobLeaseShouldQueueClasses(): array
{
    $classes = [];
    foreach (jobLeaseAppPhpFiles() as $path) {
        $class = jobLeaseClassNameForPath($path);
        if (! class_exists($class)) { continue; }
        $reflection = new ReflectionClass($class);
        if (! $reflection->isInstantiable()) { continue; }
        if (! $reflection->implementsInterface(ShouldQueue::class)) { continue; }
        $classes[] = $reflection->getName();
    }
    sort($classes);

    return $classes;
}

function jobLeaseAppPhpFiles(): array { /* RecursiveIteratorIterator で app/ 走査 */ }

function jobLeaseClassNameForPath(string $path): string { /* PSR-4 変換 */ }
```

### 変更後コード

**(1) 母集団走査の 1 本化** — `tests/Support/QueuedJobPopulation.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use Illuminate\Contracts\Queue\ShouldQueue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Webmozart\Assert\Assert;

/**
 * 「キューに載るクラス」の母集団を決める**唯一の実装**。
 *
 * QueuedJobLeaseInventoryTest (接続 / リース期間の目録) と
 * JobExecutionDedupInventoryTest (重複実行の保証の目録) が**同じ母集団**を見ることを
 * 構造的に保証する (2 実装に分かれていると、片方だけ更新される drift が起きる)。
 *
 * 母集団判定の正本は `ReflectionClass::implementsInterface(ShouldQueue::class)` +
 * `isInstantiable()`。親クラス / trait 経由の実装も拾うため Job だけでなく
 * Mailable / Notification も自動的に母集団へ入る。
 *
 * ★ `class_exists()` により **autoload の副作用を伴う** (既存 QueuedJobLeaseInventoryTest の
 *   方式をそのまま移設したもの)。token parser / composer classmap へ寄せる案はあるが、
 *   既存 gate の振る舞いまで変わるため本設計では踏襲する (方式変更は独立した課題)。
 */
final class QueuedJobPopulation
{
    /** @return list<class-string> */
    public static function shouldQueueClasses(): array
    {
        $classes = [];
        foreach (self::appPhpFiles() as $path) {
            $class = self::classNameForPath($path);
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $classes[] = $reflection->getName();
        }

        sort($classes);

        return $classes;
    }

    /** @return list<string> app/ 配下の PHP ファイル絶対パス一覧 */
    public static function appPhpFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];
        foreach ($iterator as $file) {
            Assert::isInstanceOf($file, SplFileInfo::class);
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $paths[] = $file->getPathname();
        }

        sort($paths);

        return $paths;
    }

    /** app/ 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
    public static function classNameForPath(string $path): string
    {
        $appPath = base_path('app').DIRECTORY_SEPARATOR;
        Assert::startsWith($path, $appPath, "app/ 配下ではないパスです: {$path}");

        $relative = substr($path, strlen($appPath), -strlen('.php'));

        return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    }
}
```

`QueuedJobLeaseInventoryTest.php` 側は本体を**委譲へ置き換えるだけ**
(呼び出し側 (`jobLeaseAllSites()` 等) とテストケースは無変更):

```php
/** @return list<class-string> 母集団の実装は Tests\Support\QueuedJobPopulation に 1 本化されている */
function jobLeaseShouldQueueClasses(): array
{
    return QueuedJobPopulation::shouldQueueClasses();
}

/** @return list<string> */
function jobLeaseAppPhpFiles(): array
{
    return QueuedJobPopulation::appPhpFiles();
}

function jobLeaseClassNameForPath(string $path): string
{
    return QueuedJobPopulation::classNameForPath($path);
}
```

**(2) 分類 enum** — `app/Enums/Security/JobDedupGuarantee.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * **結果の一回性**を担う永続状態遷移の機構 (裁定 AG-082 の「保証側」)。
 *
 * ★これは preflight (外部呼び出し直前の再検証) とは**別概念**である。
 *   preflight は「既に失われた所有権を検出して送信を止める」抑止策であり、
 *   一回性そのものを保証しない。目録では別フィールドで持つ
 *   (`tests/Support/JobDedup/GuaranteeEntry`)。
 * ★case を足すとき: 「同じ行に対する 2 回目の確定を DB が構造的に拒否するか」を基準にする。
 *   「先に検査してから書く」だけのものは保証ではない。
 */
enum JobDedupGuarantee: string
{
    /**
     * 条件付き UPDATE (`where(status=…)->update(…)`) で 0 行更新なら後続を行わない。
     *
     * 適用条件: 遷移元 status を WHERE に含み、戻り値 (更新行数) で分岐している。
     */
    case ConditionalStatusUpdate = 'conditional_status_update';

    /**
     * 行ロック (`lockForUpdate`) + status guard を同一トランザクション内で行う。
     *
     * 適用条件: ロック取得と status 検査と確定の書き込みが**同じ tx** に入っている。
     */
    case PessimisticLockWithStatusGuard = 'pessimistic_lock_with_status_guard';

    /**
     * 予約行の CAS (pending→verifying→completed/released)。
     *
     * 適用条件: 各遷移が条件付き UPDATE で、対になる回収経路 (sweeper) が存在する。
     */
    case ReservationCas = 'reservation_cas';

    /**
     * 一意制約 (partial unique index) が 2 回目の起票そのものを拒否する。
     *
     * 適用条件: DB の制約で重複が**書けない**こと (アプリ側の事前検査は根拠にならない)。
     */
    case DatabaseUniqueConstraint = 'database_unique_constraint';
}
```

`app/Enums/Security/JobDedupExemption.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「重複実行の保証を持たないことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/JobExecutionDedupInventoryTest.php` が deny-by-default で
 * 「保証側の登録」か「本 enum + 具体的根拠」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「保証側を作るべきジョブ」である。
 */
enum JobDedupExemption: string
{
    /**
     * 重複配信が受容されている送信系 (Mailable / Notification)。
     *
     * 適用条件 (**すべて**満たすこと):
     *  - ドメイン状態を一切書かない (送信のみ)
     *  - **重複受信時に受信者が誤った操作へ誘導されない**
     *    (「気にならない」ではなく「二重の支払い操作等を招かない」まで確認する)
     *  - `$tries` / retry 契約の上で at-least-once を受容済みである
     *
     * ★課金関連・失敗通知・セキュリティ通知を「配信系だから」で一括免除しない。
     *   クラスごとに「何が重複配信されうるか・受信者に何が起きるか・なぜ受容できるか」を書く。
     */
    case DuplicateDeliveryAccepted = 'duplicate_delivery_accepted';

    /**
     * 削除が本質的に冪等で、2 回目の実行が no-op になるジョブ。
     *
     * 適用条件: 対象の不在を正常系として扱い、状態も課金も動かさない。
     */
    case IdempotentDeletion = 'idempotent_deletion';

    /**
     * 外部の最新状態を取り込むだけの同期ジョブ (last-writer-wins が正しい)。
     *
     * 適用条件: 書き込みが冪等な upsert で、順序が入れ替わっても収束先が同じ。
     */
    case ConvergentStateSync = 'convergent_state_sync';

    /**
     * 起票の重複を DB 制約が拒否するため、ジョブ側に保証を置く必要がないもの。
     *
     * 適用条件: 起票先に partial unique index があり、重複起票が例外になる。
     * ★これは「保証がある」ではなく「保証の所在がジョブの外」であることの記録である。
     */
    case GuardedByDownstreamConstraint = 'guarded_by_downstream_constraint';
}
```

**(3) 目録の value object** — `tests/Support/JobDedup/`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support\JobDedup;

/**
 * preflight (外部呼び出し直前の再検証) の要求。
 *
 * ★実装は `PreflightCheckpoint` と `NoExternalCall` の **2 つだけ**に閉じる。
 *   PHP には sealed type が無いため、実装集合の一致は
 *   JobExecutionDedupInventoryTest が deny-by-default で検査する
 *   (nullable にして「null = 外部呼び出しなし」とすると、新しい外部呼び出しを足しても
 *    目録が green のままになりうる)。
 */
interface PreflightRequirement {}
```

```php
/**
 * preflight が所有権喪失をどう伝えるか (Round 2 レビュー反映)。
 *
 * ★ Manual (例外で中断) と Billing (structured return) を**無理に統合しない**
 *   (AGENTS.md 思考原則 4)。どちらであるかを目録が明示し、
 *   gate はそれに一致する戻り型を要求する。
 */
enum PreflightControlFlow
{
    /** 失われていたら例外を投げる (戻り型 void)。Manual ドメイン */
    case ThrowsOnLoss;

    /** 送信してよいかを bool で返す (戻り型 bool)。Billing ドメイン */
    case ReturnsBoolean;
}
```

```php
final readonly class PreflightCheckpoint implements PreflightRequirement
{
    /**
     * @param  class-string  $verifierClass  再検証を行うクラス
     * @param  non-empty-string  $verifierMethod  再検証メソッド
     */
    public function __construct(
        public string $verifierClass,
        public string $verifierMethod,
        public ExternalCallKind $externalCall,
        public PreflightControlFlow $controlFlow,
    ) {}

    /** gate が要求する戻り型名 */
    public function expectedReturnType(): string
    {
        return match ($this->controlFlow) {
            PreflightControlFlow::ThrowsOnLoss => 'void',
            PreflightControlFlow::ReturnsBoolean => 'bool',
        };
    }
}
```

```php
final readonly class NoExternalCall implements PreflightRequirement
{
    /**
     * @param  non-empty-string  $rationale  「外部呼び出しを持たない」根拠 (30 文字以上)
     *
     * ★根拠文は日本語のため `mb_strlen()` で**文字数**を数える (`strlen()` はバイト数になる)。
     *   mbstring は Laravel の必須拡張であり、既存 ThrottleCoverageInventoryTest も同方式。
     */
    public function __construct(public string $rationale)
    {
        Assert::greaterThanEq(mb_strlen($rationale), 30, '「外部呼び出しなし」の根拠は 30 文字以上で書くこと');
    }
}
```

```php
final readonly class GuaranteeEntry
{
    /**
     * @param  non-empty-list<JobDedupGuarantee>  $mechanisms  永続状態遷移の機構 (複数可)
     * @param  non-empty-string  $rationale  30 文字以上
     *
     * ★ **複数登録できる**ことが要点 (Round 2 レビュー反映)。
     *   ExecuteAutoRechargeAttemptJob は「台帳付与の一回性 = invoice 単位 UNIQUE」と
     *   「attempt 遷移の一回性 = 条件付き UPDATE」という**軸の違う 2 本の保証**を持つ。
     *   単一 mechanism で書くと、どちらか一方が enum の適用条件に一致しない
     *   誤った分類を型付き目録が保持してしまう。
     */
    public function __construct(
        public array $mechanisms,
        public PreflightRequirement $preflight,
        public string $rationale,
    ) {
        Assert::notEmpty($mechanisms, '保証機構を 1 つ以上登録すること');
        Assert::uniqueValues(
            array_map(static fn (JobDedupGuarantee $m): string => $m->value, $mechanisms),
            '同じ保証機構を重複登録しないこと',
        );
        Assert::greaterThanEq(mb_strlen($rationale), 30, '保証側の根拠は 30 文字以上で書くこと');
    }
}
```

```php
final readonly class ExemptionEntry
{
    /** @param non-empty-string $rationale 30 文字以上 */
    public function __construct(
        public JobDedupExemption $exemption,
        public string $rationale,
    ) {
        Assert::greaterThanEq(mb_strlen($rationale), 30, '免除の根拠は 30 文字以上で書くこと');
    }
}
```

**(4) gate 本体** — `tests/Architecture/JobExecutionDedupInventoryTest.php` (要点のみ):

```php
<?php

declare(strict_types=1);

use App\Enums\Security\ExternalCallKind;
use App\Enums\Security\JobDedupExemption;
use App\Enums\Security\JobDedupGuarantee;
use App\Jobs\Manual\RunManualAnalysis;
use App\Jobs\Manual\RunManualRender;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Manual\RenderPipeline;
use Tests\Support\JobDedup\ExemptionEntry;
use Tests\Support\JobDedup\GuaranteeEntry;
use Tests\Support\JobDedup\NoExternalCall;
use Tests\Support\JobDedup\PreflightCheckpoint;
use Tests\Support\JobDedup\PreflightRequirement;
use Tests\Support\QueuedJobPopulation;

/*
 * 裁定 AG-082「入口の排他 / 結果の一回性」の aicue 実装を deny-by-default で固定する。
 *
 * キューに載る全クラス (ShouldQueue 実装) は、次のいずれかに**必ず**分類される:
 *   - 保証側: JobDedupGuarantee (永続状態遷移の機構) + PreflightRequirement + 30 文字以上の根拠
 *   - 免除:   JobDedupExemption + 30 文字以上の根拠
 * 未分類は fail (新しいジョブを足したら必ずここへ登録する)。
 *
 * ★母集団は QueuedJobLeaseInventoryTest と**同一の実装** (Tests\Support\QueuedJobPopulation)
 *   を使う。2 実装に分けると片方だけ更新される drift が起きるため。
 *
 * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
 */

/** @return array<class-string, GuaranteeEntry> */
function jobDedupGuarantees(): array
{
    return [
        RunManualAnalysis::class => new GuaranteeEntry(
            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
            preflight: new PreflightCheckpoint(
                AnalysisPipeline::class, 'assertStillOwned',
                ExternalCallKind::LlmCompletion, PreflightControlFlow::ThrowsOnLoss,
            ),
            rationale: 'startJob が lockForUpdate + status===queued で running へ遷移させ、'
                .'finalize が同一 tx で materialize + tickets->commit + succeeded を原子化する。'
                .'LLM は冪等キーを持たないため、各呼び出しの直前に preflight を置く。',
        ),
        RunManualRender::class => new GuaranteeEntry(
            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
            preflight: new PreflightCheckpoint(
                RenderPipeline::class, 'assertStillOwned',
                ExternalCallKind::ObjectStoragePut, PreflightControlFlow::ThrowsOnLoss,
            ),
            rationale: 'startJob / finalize が AnalysisPipeline と同型。S3 PUT は取り消せない'
                .'外部副作用なので、updateProgress の後・upload の直前に preflight を置く。',
        ),
        ExecuteAutoRechargeAttemptJob::class => new GuaranteeEntry(
            // ★軸の違う 2 本の保証を**両方**登録する (Round 2 レビュー反映)
            mechanisms: [
                JobDedupGuarantee::DatabaseUniqueConstraint,  // 付与の一回性 (invoice 単位)
                JobDedupGuarantee::ConditionalStatusUpdate,   // attempt 遷移の一回性 (attempt 単位)
            ],
            preflight: new PreflightCheckpoint(
                AutoRechargeService::class, 'stillPending',
                ExternalCallKind::StripeInvoicePay, PreflightControlFlow::ReturnsBoolean,
            ),
            rationale: '冪等キーが 2 本ある: 付与の一回性は台帳の recharge:{invoiceId} UNIQUE '
                .'(invoice 単位。付与は attempt 遷移より先に走るが、この UNIQUE が二重付与を拒否する)、'
                .'attempt 遷移の一回性は where status=pending の条件付き UPDATE (attempt 単位)。'
                .'org lock (TTL 180s) は best-effort で保証を担わない。',
        ),
        // AutoRechargeTriggerJob / DeleteTakeObjectsJob / DeleteRenderOutputsJob /
        // SyncBillingCustomerDetails / … 全 18 件をここか jobDedupExemptions() のどちらかへ登録する
    ];
}

/** @return array<class-string, ExemptionEntry> */
function jobDedupExemptions(): array { /* 配信系 8 件ほか */ }

/** 免除件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。 */
function jobDedupExemptionCap(): int { /* 実装時に確定 */ }

/** @return array<string, int> case 別上限 (分類の偏り検出)。array_sum で全体 cap を導出しない */
function jobDedupExemptionCapByCase(): array { /* 実装時に確定 */ }

test('キューに載る全クラスが保証側 or 免除に分類されている (未分類は fail)', function (): void {
    $scanned = QueuedJobPopulation::shouldQueueClasses();
    $classified = array_merge(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
    sort($classified);

    expect(array_values(array_diff($scanned, $classified)))->toBe([], '未分類の ShouldQueue 実装がある');
    expect(array_values(array_diff($classified, $scanned)))->toBe([], '目録に実在しないクラスが残っている');
});

test('保証側と免除は排他 (同じクラスが両方に居ない)', function (): void {
    $both = array_intersect(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
    expect(array_values($both))->toBe([]);
});

/*
 * ★ 「QUEUED_JOB_LEASE_INVENTORY と目録のキー集合が一致する」という直接検査は**置かない**。
 *   (a) 両 gate が同じ `QueuedJobPopulation::shouldQueueClasses()` に対して
 *       それぞれ対称差 = 空を要求するため、両方 green なら一致は必然 (推移律)。
 *   (b) 他テストファイルのグローバル定数を参照すると、Pest の --parallel が
 *       ファイル単位でプロセスを分けたとき未定義になりうる。
 *   drift の構造的な防止は「母集団の走査実装を 1 本にしたこと」で達成されている。
 */

test('preflight の再検証点が実在し、登録された制御方式に一致する戻り型を持つ (※配置までは検査しない)', function (): void {
    // ★ この gate が固定できるのは**再検証点の実在と戻り型まで**である。
    //   「外部呼び出しの直前で呼ばれていること」は Reflection では検査できない
    //   (名前だけ存在する空メソッドでも green になる)。
    //   配置の保証は Feature テスト (所有権喪失時に LLM / S3 / Stripe fake が
    //   1 回も呼ばれないこと) の担当である。
    foreach (jobDedupGuarantees() as $class => $entry) {
        $preflight = $entry->preflight;
        if (! $preflight instanceof PreflightCheckpoint) {
            continue;
        }

        $reflection = new ReflectionClass($preflight->verifierClass);
        expect($reflection->hasMethod($preflight->verifierMethod))->toBeTrue(
            "{$class}: preflight 再検証点 {$preflight->verifierClass}::{$preflight->verifierMethod} が実在しません",
        );

        // ★ Manual (例外で中断 = void) と Billing (structured return = bool) を統合しないため、
        //   目録が宣言した PreflightControlFlow と実際の戻り型の一致を検査する。
        $expected = $preflight->expectedReturnType();
        $returnType = $reflection->getMethod($preflight->verifierMethod)->getReturnType();
        expect($returnType instanceof ReflectionNamedType && $returnType->getName() === $expected)->toBeTrue(
            "{$class}: preflight 再検証点の戻り型が目録の制御方式 ({$expected}) と一致しません",
        );
    }
});

test('保証機構は 1 つ以上・重複なしで登録されている', function (): void {
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect($entry->mechanisms)->not->toBeEmpty("{$class}: 保証機構が空です");
        $values = array_map(static fn (JobDedupGuarantee $m): string => $m->value, $entry->mechanisms);
        expect(array_values(array_unique($values)))->toBe($values, "{$class}: 保証機構が重複しています");
    }
});

test('PreflightRequirement の実装は 2 種類に閉じている (sealed 相当)', function (): void {
    // PHP に sealed type が無いため gate が閉じる。3 つ目の実装が現れたら、
    // 「外部呼び出しなし」を主張する新しい抜け道が増えていないか必ず再検討する。
    // 走査対象は tests/Support/JobDedup/ 配下のみ (app/ 全走査はコストだけで意味がない)。
    $found = [];
    foreach (jobDedupSupportPhpFiles() as $path) {
        $class = jobDedupSupportClassNameForPath($path);
        if (! class_exists($class)) { continue; }
        $reflection = new ReflectionClass($class);
        if ($reflection->isInterface() || ! $reflection->implementsInterface(PreflightRequirement::class)) {
            continue;
        }
        $found[] = $reflection->getName();
    }
    sort($found);

    expect($found)->toBe([NoExternalCall::class, PreflightCheckpoint::class]);
});

test('目録の根拠は 30 文字以上 (constructor と gate の二重固定)', function (): void { /* … */ });

test('免除件数が全体 cap / case 別 cap を超えない (形骸化ガード)', function (): void { /* … */ });

test('固定 event 名の literal は ExternalCallKind 以外に直書きされていない', function (): void {
    // literal の直書きが増えると、ログ基盤での集計語彙が静かに割れる。
    // 2 つの event 名 (抑止 / 後始末) をまとめて検査する。
    $literals = ["'job_ownership_lost'", "'job_ownership_lost_cleanup'"];
    $violations = [];

    foreach (QueuedJobPopulation::appPhpFiles() as $path) {
        if (str_ends_with($path, 'app/Enums/Security/ExternalCallKind.php')) {
            continue; // 正本
        }

        $source = file_get_contents($path);
        Assert::string($source, "ファイルを読み込めません: {$path}");

        foreach ($literals as $literal) {
            if (str_contains($source, $literal)) {
                $violations[] = $path.' ('.$literal.')';
            }
        }
    }

    expect($violations)->toBe([],
        '固定 event 名は ExternalCallKind::LOG_EVENT / CLEANUP_LOG_EVENT を参照すること: '
        .implode(', ', $violations));
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (全関数に `@return` shape / スカラー型)
- [x] null 安全 — `getReturnType()` の `?ReflectionType` を `instanceof ReflectionNamedType` で narrowing。
      `file_get_contents()` の `string|false` は `Assert::string()` で確定
- [x] DTO を返している — 目録の値は `final readonly` value object (tuple 配列を使わない)
- [x] Generics — `array<class-string, GuaranteeEntry>` を PHPDoc で明示
- [x] `Webmozart\Assert\Assert` を value object の constructor で使用

### テスト計画 (gate 自身の受け入れ = mutation 手順)

> **問題**: この gate は「素の main では常に green」であり、そのままでは
> 「本当に検出できるのか」が確認できない。以下の mutation を **1 つずつ手で入れて赤化を確認し、
> 必ず元へ戻す**。結果 (mutation → 失敗したテスト名) を実装 PR の説明に記録する。

| # | mutation | 期待する赤 |
|---|---|---|
| M1 | `jobDedupGuarantees()` から `RunManualAnalysis` の entry を 1 行削除 | 「未分類の ShouldQueue 実装がある」 |
| M2 | `AnalysisPipeline::assertStillOwned` を `assertStillOwnedX` にリネーム | 「preflight 再検証点が実在しません」 |
| M3 | `assertStillOwned` の戻り型を `void` → `bool` に変更 | 「戻り型が目録の制御方式 (void) と一致しません」 |
| M3b | `stillPending` の戻り型を `bool` → `void` に変更 | 「戻り型が目録の制御方式 (bool) と一致しません」 |
| M3c | `ExecuteAutoRechargeAttemptJob` の `mechanisms` を空配列にする | `GuaranteeEntry` の `Assert::notEmpty` + 「保証機構は 1 つ以上・重複なし」 |
| M4 | `NoExternalCall` の根拠を 10 文字にする | constructor の `Assert` + gate の 30 文字検査 |
| M5 | `AutoRechargeService::LOCK_TTL_SECONDS` を 700 にする | S5 の「org lock TTL は retry_after を下回る」 |
| M6 | `AutoRechargeTriggerJob::$uniqueFor` を 0 にする | S5 の「uniqueFor は正の値である」 |
| M7 | `tests/Support/JobDedup/` に 3 つ目の `PreflightRequirement` 実装を足す | 「実装は 2 種類に閉じている」 |
| M8 | `app/Services/Manual/AnalysisPipeline.php` に `'job_ownership_lost'` を直書き | 「固定 event 名は LOG_EVENT を参照すること」 |
| M9 | 目録の免除を 1 件増やす (cap 到達) | 「免除件数が上限を超えない」 |
| M10 | `QUEUED_JOB_LEASE_INVENTORY` から 1 件削除 | 既存の「接続経路: キューに載る全クラスが目録に登録されている」(走査の委譲後も従来どおり検出できることの確認) |
| M11 | `AnalysisPipeline::writeProgress()` の `where('status', running)` を外す | S2 の「cron failed 後に step / progress が書き戻されない」 |
| M12 | `RenderPipeline::updateProgress()` の `where('status', running)` を外す | S3 の「cron failed 後に step / progress が書き戻されない」 |
| M13 | `stripe_invoice_id` の永続化を素の `save()` に戻す | S4 の「attach 0 行: invoice_id を書かず invoice を終端する」 |
| M14 | `terminateUnattachedInvoice()` を `Canceled` 限定にする | S4 の「attach 0 行: failed へ遷移していた場合も終端する」 |
| M15 | cleanup ログの event 名を `LOG_EVENT` に戻す | S4 の「event ごとのキー集合 schema」 |

その他:

- [ ] `composer test` 全体が green (10,000 件規模。ホスト全体グローバルロック下で走るため
      検証サイクルが長い — **待つ。kill しない**)
- [ ] 個別の `DatabaseTransactions` を使っていない (gate は DB を触らない)

### リスク

- **登録漏れで CI が即赤になる** (deny-by-default の設計どおり)。18 件全数の分類を
  最初のコミットで揃える必要がある。→ 母集団を共有実装にしたことで、
  `QueuedJobLeaseInventoryTest` の目録をそのまま写せば漏れは起きない。
- `QueuedJobLeaseInventoryTest` の走査関数を委譲へ置き換えるため、**既存 gate の
  振る舞いが変わっていないこと**を「既存テストケースが 1 件も落ちないこと」で確認する
  (テストケース自体は 1 行も変更しない)。
- Pest のグローバル関数名衝突: 新 gate の関数は `jobDedup*` prefix で統一し、
  既存の `jobLease*` と重複させない。

---

## S7. 運用契約の文書化

### 変更箇所

- `docs/architecture.md` — §キューのリース期間とワーカー制限時間の規約 (L245) の**直後**に
  **§ジョブの重複実行と結果の一回性** を新設
- `AGENTS.md` — ドメイン固有規約に項目 6 を追加

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: **なし** (文書の存在自体を検査する gate は作らない —
  規約の各文がどのテストで保証されるかを本文の対応表で示す方が、
  文書の存在だけを見る gate より実質的である)

### 変更後コード (docs/architecture.md の新設節の構成)

```markdown
### ジョブの重複実行と結果の一回性

1. **2 層の役割** — 入口の排他 (ShouldBeUnique / Cache::lock) は best-effort。
   結果の一回性は永続状態遷移 (条件付き UPDATE / 悲観ロック + status guard / 予約 CAS) と
   外部側の冪等性が担う。preflight (外部呼び出し直前の再検証) は
   「既に失われた所有権を検出して送信を止める」抑止策であり、保証ではない。
2. **所有権の定義** — (行の主キー, 進行中 status)。claim token を持たない理由。
3. **preflight の配置規則** — 外部呼び出しの直前。自前の書き込みを挟んだら書き込みの後に再度置く。
4. **保証層の全体像** (auto-recharge の 4 層表)。
5. **閉じない窓 (4 つ)** — 送信権競合 / 送信結果不明 (S3・Stripe の同型窓を含む) /
   LLM に冪等キーが無いこと / queue:listen では $timeout が効かないこと。
6. **序列** — LOCK_TTL / uniqueFor < retry_after、$timeout < stale 閾値。
   その成立前提 5 点 (pcntl 有効・遅延なし・時計ずれ・シグナル順序・supervisor)。
7. **運用契約 (所有者)** —
   - `event = job_ownership_lost` の連続発生は「ワーカーの停止・再開 / 序列の前提崩れ」の兆候。
   - **恒久回収を持たない open invoice 2 種**の監視と手動収束の所有者・手順:
     (a) 所有権喪失後の void 失敗で残ったもの、
     (b) invoice 作成成功 → stripe_invoice_id 保存前のワーカー死亡で残ったもの。
     どちらも Stripe metadata の `recharge_attempt_ulid` から attempt を逆引きできる。
     `reconcile()` は DB の pending attempt を走査するため**母集団外**である。
8. **規約 ↔ テスト対応表** (下記)。
```

**規約 ↔ テストの対応表** (AGENTS.md 禁止事項 1 = 不変条件はテスト登録まで含めて実装済み):

| 規約の文 | 保証するテスト |
|---|---|
| キューに載る全クラスが保証側 or 免除に分類される | `JobExecutionDedupInventoryTest` |
| preflight の再検証点が実在し、登録された制御方式 (`PreflightControlFlow`) に一致する戻り型を持つ (**存在まで**) | `JobExecutionDedupInventoryTest` |
| preflight が**外部呼び出しの直前に置かれている** (配置) | `AnalysisPipelineTest` / `RenderPipelineTest` / `AutoRechargeServiceTest` (追記分。fake が呼ばれないことで固定) |
| 終端後にジョブ行の進捗を書き戻さない (条件付き UPDATE) | `AnalysisPipelineTest` / `RenderPipelineTest` (追記分) |
| 終端後に `stripe_invoice_id` を書き込まない (条件付き UPDATE) | `AutoRechargeServiceTest` (追記分) |
| 同一 invoice への付与は台帳に 1 件しか入らない | `AutoRechargeServiceTest` (追記分) |
| 免除は型付き enum + 30 文字以上の根拠 | `JobExecutionDedupInventoryTest` + value object の `Assert` |
| 入口の排他 TTL / uniqueFor < retry_after | `JobExclusionOrderingInvariantTest` |
| `$timeout < retry_after < 予約 TTL ≤ stale 閾値` | `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` (既存) |
| worker `--timeout` < `retry_after` | `QueueWorkerLeaseInvariantTest` (既存) |
| 所有権喪失時に LLM を呼ばない | `AnalysisPipelineTest` (追記分) |
| 所有権喪失時に S3 PUT しない | `RenderPipelineTest` (追記分) |
| 所有権喪失時に Stripe を呼ばず invoice を終端する | `AutoRechargeServiceTest` (追記分) |
| ログコンテキストに PII を含めない | `JobOwnershipLostContextTest` |
| 固定 event 名の literal が 1 箇所に閉じる | `JobExecutionDedupInventoryTest` |

### AGENTS.md への追記 (ドメイン固有規約 項目 6)

```markdown
6. **ジョブの重複実行と結果の一回性**: 入口の排他 (`ShouldBeUnique` / `Cache::lock`) は
   **best-effort であり保証を担わない**。結果の一回性は永続状態遷移 (条件付き UPDATE /
   悲観ロック + status guard / 予約 CAS) と外部側の冪等キーが担う。
   **取り消せない外部副作用 (LLM 呼び出し / S3 PUT / Stripe 課金) の直前には
   所有権の再検証 (preflight) を置く**。検証と外部呼び出しの間に自前の書き込みを挟まない
   (挟んだら書き込みの後にもう一度置く)。キューに載る全クラス (`ShouldQueue` 実装) は
   `JobExecutionDedupInventoryTest` の目録へ「保証側 (`JobDedupGuarantee` + preflight)」か
   「免除 (`JobDedupExemption` + 30 文字以上の根拠)」で登録が必須 (deny-by-default)。
   排他 TTL / `uniqueFor` は保証を代替できる長さに伸ばさない
   (`JobExclusionOrderingInvariantTest` が `retry_after` 未満を固定)。
   **閉じない窓と運用上の所有者**は `docs/architecture.md`
   §ジョブの重複実行と結果の一回性 が正本。
```

### PHPStan 適合チェック

- 文書のみのため該当なし (`composer phpstan` に影響しない)。

### テスト計画

- [ ] `AGENTS.md` の VERIFICATION_COMMANDS マーカーに触れていないこと
      (`verification-commands-doc-sync.test.ts` が deny-by-default で検査する)
- [ ] **`AGENTS.md` の既存項番を renumber していないこと** — セキュリティ不変条件 1-10 と
      ドメイン固有規約 1-5 は他文書から番号ではなく項目名で参照される契約だが、
      既存参照 (`docs/app-integration-guide.md` §7 / stripe webhook migration) を壊さないため
      **追記は末尾 (項目 6) のみ**とする
- [ ] `docs/architecture.md` の既存節 (ロック順序 / キューのリース期間) を書き換えていないこと
- [ ] 上記の対応表がすべて実在するテストを指していること (実装時に目視 + `composer test` で確認)

### リスク

- 文書のみのため後退リスクは無いが、**書いた規約が守られない**リスクはある。
  → 対応表で「どのテストが保証するか」を明示することで形骸化を抑える。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 新モデル・新マイグレーション・新 API・新画面が一切なく、既存 3 サービスへの局所的な挿入 (preflight) と新規テスト群のみ。既存の状態機械・時間 budget・キュー接続トポロジ・DTO / Inertia Props に一切触れないため、他の作業と衝突する面が狭い。S1 (語彙) → S2/S3/S4 (挿入) → S5/S6 (gate) → S7 (文書) の順で段階的にコミットでき、各段で `composer test` が green を保てる。 |
| 競合リスク | **T127 (既定キュー接続の分割)** と `config/queue.php` の `database.retry_after` を共有する (S5 の比較先)。T127 が先に入ると S5 の比較先を差し替える必要がある → S5 の docblock に T127 との関係を明記して、どちらが先でも気付ける形にする。<br>**T124/T125/T126** (throttle / 外部 SDK timeout) とはファイルが重ならない。<br>`tests/Architecture/QueuedJobLeaseInventoryTest.php` を触るのは走査 3 関数の委譲のみで、目録定数とテストケースは無変更 — 同ファイルを触る他タスクがある場合のみ調整が要る。 |
| 実装順序 | S1 → S2 → S3 → S4 → S5 → S6 → S7 (S6 の目録は S2/S3/S4 の再検証点が実在してからでないと green にできない) |


---

## 追加の現行コード (Round 2 の指摘の検証に使ったもの)

### `tests/Support/FakeAutoRechargeGateway` の既存プロパティ (フック追加先)

```php
    /** @var list<array{...}> */
    public array $createdInvoices = [];
    /** @var list<array{...}> */
    public array $payCalls = [];
    /** @var list<string> */
    public array $terminated = [];
    public bool $failOnTerminate = false;
    public ?OffSessionChargeResultDto $payResult = null;
    // …
    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string { /* 記録して invoice id を返す */ }
```

`tests/Feature/Billing/AutoRechargeServiceTest.php` の注入作法:

```php
/** @return array{Organization, User, FakeAutoRechargeGateway, AutoRechargeService} */
function autoRechargeSetup(): array
{
    [$organization, $owner] = createOrganizationWithOwner();

    $gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $gateway);

    return [$organization, $owner, $gateway, app(AutoRechargeService::class)];
}
```

### `AutoRechargeGatewayInterface::terminateInvoice()` の署名 (変更しない)

```php
    public function terminateInvoice(string $invoiceId): void;
```
