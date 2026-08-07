# アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 思考原則 — AGENTS.md より転記

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

# 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 前提: 概念設計は Codex レビュー Round 5 で APPROVED 済み

概念設計 (`conceptual-design.md`) の主要な確定事項:
- 結果の一回性は永続状態遷移 + 外部冪等性が担い、preflight (外部呼び出し直前の所有権再検証) は
  「既に失われた所有権を検出して送信を止める」抑止策であって保証ではない。
- claim token / `running --CAS--> sending` は**送信権競合を実際に閉じられる**が、
  送信結果不明を扱う新しい回収契約と状態機械の波及コストを伴うため、
  現行の `$timeout < stale 閾値` 序列の下で**明示的にリスク受容**して採らない。
- 入口の排他 (`ShouldBeUnique` の追加) はスコープ外 (再トリガー設計と衝突するため、免除登録で記録)。

## 詳細設計書

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
     * 所有権喪失ログの**固定 event 名**。
     *
     * ログ基盤で頻度を集計し「残余窓 1 が実際にどれだけ開いているか」を測るために固定する。
     * Manual / Billing の両方がこの 1 箇所を参照する (literal の直書きは
     * JobExecutionDedupInventoryTest が deny-by-default で検出する)。
     */
    public const string LOG_EVENT = 'job_ownership_lost';

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
- [ ] `preflight: 所有権喪失時に通知が飛ばない`
  - `Notification::fake()` (既存テストと同じ作法) で `assertNothingSent()`
- [ ] `preflight: 所有権喪失時に予約が二重 release されない`
  - 予約は cron 側の failJob が Released にしているため、
    `TicketReservation` の `status` が `Released` で **台帳エントリが増えていない**ことを検査
- [ ] `preflight: 所有権喪失は固定 event 名で warning ログに出る`
  - `Log::shouldReceive('warning')` ではなく `Log::spy()` + `event` キーの検査
    (既存の LLM 再試行 warning と混ざらないよう `logContext()['event']` で識別)
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

---

## S3. レンダパイプラインの preflight (S3 PUT 直前)

### 変更箇所

- `app/Services/Manual/RenderPipeline.php`
  - `run()` (L94-98): `updateProgress()` の**後**・`storage->upload()` の**直前**に preflight
  - `run()` の catch 節 (L113): `JobOwnershipLostException` 専用 catch を先に追加
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

- [ ] `preflight: compose 中に cron が failed 化 → S3 へ 1 件も PUT しない`
  - `$fake->duringCompose = fn () => app(RenderJobService::class)
    ->failJob($job->refresh(), RenderErrorCode::Timeout, 'stale')`
  - 期待: `Storage::disk('s3')->assertMissing($expectedOutputKey)`
  - 期待: `$job->refresh()->output_path === null` / `status === Failed`
- [ ] `preflight: 所有権喪失時に work dir が削除される (finally を通る)`
  - `storage_path("app/render/{$job->id}")` が存在しないことを検査
- [ ] `preflight: 所有権喪失時に failJob が二重に走らない (error / error_code が cron の値のまま)`
- [ ] `preflight: 所有権喪失時に完了通知が飛ばない` — `Notification::fake()` + `assertNothingSent()`
- [ ] `preflight: 所有権喪失時に DeleteRenderOutputsJob が dispatch されない` — `Queue::fake()`
- [ ] `preflight: preview (kind=preview) でも同じく PUT しない` (予約を持たない経路の回帰)
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
  - `executeAttemptLocked()` (L528-570): Stripe 2 呼び出しの直前へ preflight を挿入
  - `stillPending()` / `terminateInvoiceAfterOwnershipLost()` を private メソッドとして新設
  - `tryTerminateInvoice()` (L669): **変更しない** (再利用する)

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
  (既存の gateway fake を使う。`app/Services/Billing/Fakes/` に実体がある)

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
            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->save();
        }

        // ★ preflight 2: pay の直前。**直前に自前の書き込み ($attempt->save()) を挟んだため
        //   必ずもう一度検証する** (裁定 AG-082: 検証の後に自前の書き込みを挟むと、
        //   接続断で旧担当が送信できる窓が開く)。
        if (! $this->stillPending($attempt, ExternalCallKind::StripeInvoicePay)) {
            $this->terminateInvoiceAfterOwnershipLost($attempt);

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

        // Manual ドメインと同じ固定 event 名で観測する (集計の語彙を 1 本に保つ)。
        Log::warning('auto-recharge: 所有権を失ったため Stripe 呼び出しを中止しました', [
            'event' => ExternalCallKind::LOG_EVENT,
            'job_type' => TicketAutoRechargeAttempt::class,
            'job_id' => $attempt->id,
            'expected_status' => AutoRechargeAttemptStatus::Pending->value,
            'actual_status' => $attempt->status->value,
            'stage' => 'execute_attempt',
            'external_call' => $call->value,
            'attempt_ulid' => $attempt->attempt_ulid,
        ]);

        return false;
    }

    /**
     * preflight 2 で中断したときの invoice 後始末。
     *
     * **canceled のときだけ**終端する:
     *  - paid  … void できない (付与経路の管轄)
     *  - failed… `terminateAndFail()` が既に終端済み
     *  - canceled … 停止側の `tryTerminateInvoice()` は `stripe_invoice_id === null` を
     *    「invoice 未作成」と解釈して素通りするため、こちらの save が停止より後だと
     *    **誰も void しない open invoice が残る**。ここで拾う。
     *
     * 終端は best-effort (`tryTerminateInvoice` が Throwable を握って false を返す)。
     * 失敗しても**課金処理へは進まない** (呼び出し側が無条件に return する)。
     * 残った open invoice は reconcile の母集団外なので、運用契約 (docs/architecture.md) の
     * 手動収束に委ねる。
     */
    private function terminateInvoiceAfterOwnershipLost(TicketAutoRechargeAttempt $attempt): void
    {
        if ($attempt->status !== AutoRechargeAttemptStatus::Canceled) {
            return; // アーリーリターン
        }

        $terminated = $this->tryTerminateInvoice($attempt);

        Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
            'event' => ExternalCallKind::LOG_EVENT,
            'attempt_ulid' => $attempt->attempt_ulid,
            'invoice_id' => $attempt->stripe_invoice_id,
            'terminated' => $terminated,
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
  - **`terminateInvoice` が当該 attempt の `stripe_invoice_id` で 1 回呼ばれる** (要件 ii)
- [ ] `preflight 2: attempt が paid のときは terminateInvoice を呼ばない` (void 不可の分類)
- [ ] `preflight 2: attempt が failed のときは terminateInvoice を呼ばない` (二重終端の抑止)
- [ ] `preflight 2: terminateInvoice が例外を投げても課金処理へ進まない` (要件 v)
  - fake の `terminateInvoice` を throw させ、`payOffSessionInvoice` が 0 回であること
  - 台帳エントリが 1 件も増えていないこと
- [ ] `所有権喪失ログが固定 event 名 job_ownership_lost を含む` — `Log::spy()`
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
  S6 の gate が機械検査できる形に揃えるためで、コストは PK SELECT 1 本。
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
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (テストクロージャは `void`)
- [x] null 安全 — `config()->integer()` を使い `mixed` を作らない
- [x] DTO を返している (該当なし)
- [x] Generics — 該当なし
- [x] const の可視性変更は型に影響しない (`public const int`)

### テスト計画

上記 3 ケースがテスト本体。加えて:

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
final readonly class PreflightCheckpoint implements PreflightRequirement
{
    /**
     * @param  class-string  $verifierClass  再検証を行うクラス
     * @param  non-empty-string  $verifierMethod  再検証メソッド (void を返し、失われていたら中断する)
     */
    public function __construct(
        public string $verifierClass,
        public string $verifierMethod,
        public ExternalCallKind $externalCall,
    ) {}
}
```

```php
final readonly class NoExternalCall implements PreflightRequirement
{
    /** @param non-empty-string $rationale 「外部呼び出しを持たない」根拠 (30 文字以上) */
    public function __construct(public string $rationale)
    {
        Assert::greaterThanEq(mb_strlen($rationale), 30, '「外部呼び出しなし」の根拠は 30 文字以上で書くこと');
    }
}
```

```php
final readonly class GuaranteeEntry
{
    /** @param non-empty-string $rationale 30 文字以上 */
    public function __construct(
        public JobDedupGuarantee $mechanism,
        public PreflightRequirement $preflight,
        public string $rationale,
    ) {
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
            mechanism: JobDedupGuarantee::PessimisticLockWithStatusGuard,
            preflight: new PreflightCheckpoint(
                AnalysisPipeline::class, 'assertStillOwned', ExternalCallKind::LlmCompletion,
            ),
            rationale: 'startJob が lockForUpdate + status===queued で running へ遷移させ、'
                .'finalize が同一 tx で materialize + tickets->commit + succeeded を原子化する。'
                .'LLM は冪等キーを持たないため、各呼び出しの直前に preflight を置く。',
        ),
        RunManualRender::class => new GuaranteeEntry(
            mechanism: JobDedupGuarantee::PessimisticLockWithStatusGuard,
            preflight: new PreflightCheckpoint(
                RenderPipeline::class, 'assertStillOwned', ExternalCallKind::ObjectStoragePut,
            ),
            rationale: 'startJob / finalize が AnalysisPipeline と同型。S3 PUT は取り消せない'
                .'外部副作用なので、updateProgress の後・upload の直前に preflight を置く。',
        ),
        // ExecuteAutoRechargeAttemptJob / AutoRechargeTriggerJob / DeleteTakeObjectsJob /
        // DeleteRenderOutputsJob / … 全 18 件をここか jobDedupExemptions() のどちらかへ登録する
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

test('母集団が QueuedJobLeaseInventoryTest と一致する (drift 検出)', function (): void {
    // 走査実装は共有だが、**目録のキー集合**が一致していることも直接固定する
    // (片方の目録だけ更新された状態を、より読みやすい失敗メッセージで検出する)
    $lease = array_keys(QUEUED_JOB_LEASE_INVENTORY);
    $dedup = array_merge(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
    sort($lease);
    sort($dedup);

    expect($dedup)->toBe($lease);
});

test('preflight の再検証点が実在し void を返す', function (): void {
    foreach (jobDedupGuarantees() as $class => $entry) {
        $preflight = $entry->preflight;
        if (! $preflight instanceof PreflightCheckpoint) {
            continue;
        }

        $reflection = new ReflectionClass($preflight->verifierClass);
        expect($reflection->hasMethod($preflight->verifierMethod))->toBeTrue(
            "{$class}: preflight 再検証点 {$preflight->verifierClass}::{$preflight->verifierMethod} が実在しません",
        );

        $method = $reflection->getMethod($preflight->verifierMethod);
        $returnType = $method->getReturnType();
        expect($returnType instanceof ReflectionNamedType && $returnType->getName() === 'void')->toBeTrue(
            "{$class}: preflight 再検証点は void を返し、失われていたら中断すること",
        );
    }
});

test('PreflightRequirement の実装は 2 種類に閉じている (sealed 相当)', function (): void {
    // PHP に sealed type が無いため gate が閉じる。3 つ目の実装が現れたら、
    // 「外部呼び出しなし」を主張する新しい抜け道が増えていないか必ず再検討する。
    $found = [];
    foreach (QueuedJobPopulation::appPhpFiles() as $_) { /* app/ には無い */ }
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

test("固定 event 名 'job_ownership_lost' は ExternalCallKind::LOG_EVENT 以外に直書きされていない", function (): void {
    // literal の直書きが増えると、ログ基盤での集計語彙が静かに割れる
    $violations = [];
    foreach (QueuedJobPopulation::appPhpFiles() as $path) {
        $source = file_get_contents($path);
        Assert::string($source);
        if (! str_contains($source, "'job_ownership_lost'")) { continue; }
        if (str_ends_with($path, 'app/Enums/Security/ExternalCallKind.php')) { continue; }
        $violations[] = $path;
    }

    expect($violations)->toBe([], '固定 event 名は ExternalCallKind::LOG_EVENT を参照すること');
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
| M3 | `assertStillOwned` の戻り型を `void` → `bool` に変更 | 「preflight 再検証点は void を返し…」 |
| M4 | `NoExternalCall` の根拠を 10 文字にする | constructor の `Assert` + gate の 30 文字検査 |
| M5 | `AutoRechargeService::LOCK_TTL_SECONDS` を 700 にする | S5 の「org lock TTL は retry_after を下回る」 |
| M6 | `AutoRechargeTriggerJob::$uniqueFor` を 0 にする | S5 の「uniqueFor は正の値である」 |
| M7 | `tests/Support/JobDedup/` に 3 つ目の `PreflightRequirement` 実装を足す | 「実装は 2 種類に閉じている」 |
| M8 | `app/Services/Manual/AnalysisPipeline.php` に `'job_ownership_lost'` を直書き | 「固定 event 名は LOG_EVENT を参照すること」 |
| M9 | 目録の免除を 1 件増やす (cap 到達) | 「免除件数が上限を超えない」 |
| M10 | `QUEUED_JOB_LEASE_INVENTORY` から 1 件削除 | 「母集団が QueuedJobLeaseInventoryTest と一致する」 |

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
| preflight の再検証点が実在し void を返す | `JobExecutionDedupInventoryTest` |
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

## 関連する現行コード (抜粋)

### AnalysisPipeline::run / startJob (`app/Services/Manual/AnalysisPipeline.php` L82-136)

```php
    public function run(int $analysisJobId): void
    {
        // T0 = run() 入口。実時間 deadline (ソフト予算) は **メソッドの第 1 文**で確定させる
        // (findOrFail / startJob も deadline の内側に入る = 設計の T0 定義と一致させる)。
        // deadline は各 LLM 試行の「開始可否」だけを決め、走行中の呼び出しは中断しない
        // (中断は prompt YAML の client_options.timeout)。
        // ハード上限は RunManualAnalysis::$timeout (worker の SIGALRM)。
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
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
                $this->notifications->notifyAnalysisFinished($job->refresh());
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->userMessageFor($exception));
        }
    }

    /** 開始 tx: queued guard + 予約の冪等確保 (§10.8-1) + running へ */
    private function startJob(AnalysisJob $job): bool
    {
        return DB::transaction(function () use ($job): bool {
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Queued) {
                return false; // 重複配送 guard
            }

            $organization = $this->resolveOrganization($locked);
            $this->ensureReservation($locked, $organization); // 残高不足はここで throw → catch → failJob

            $locked->status = JobStatus::Running;
            $locked->step = AnalysisStep::Extract;
            $locked->progress = 10;
            $locked->save();
            $job->refresh();

            return true;
        });
    }
```

### AnalysisPipeline::runExtractStep / withBoundedRetry (`app/Services/Manual/AnalysisPipeline.php` L164-185)

```php
    /** extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット) */
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

        $document->extracted_json = $extracted->toArray();
        $document->save();
        $this->updateProgress($job, AnalysisStep::Decompose, 35);

        return $extracted;
    }

```

### AnalysisPipeline::finalize / withBoundedRetry (`app/Services/Manual/AnalysisPipeline.php` L249-328)

```php
    private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): bool
    {
        return DB::transaction(function () use ($job, $generated): bool {
            // ロック 1: job 行 (stale 回復 cron との直列化点)
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Running) {
                return false; // stale 回復 cron が先勝ち → materialize も commit もしない (無課金 succeeded 排除)
            }

            // ロック 2: manual 行 (共有ロック規約。親 relation 経由再解決 = 子∈親も担保)
            $project = $this->resolveProject($locked);
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()
                ->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

            // 導入/総括カットを terminal tx 内 (locked manual 参照) で決定的に前後付与する。
            // 再掲元は今回生成の steps のみ (DB 既存 cuts 不参照)。
            $steps = $this->bookend->wrap($lockedManual, $generated->toScenarioSteps());

            // cuts + version + status(analyzing→ready) はロック済み manual 前提メソッドで反映
            // (内側 transaction を張らない。analyzing guard 違反は LogicException → 全体 rollback)
            $this->scenarios->materializeIntoLockedManual($lockedManual, $steps);

            // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部。savepoint)
            $reservation = $locked->ticketReservation;
            Assert::notNull($reservation, 'startJob が必ず予約を付けている');
            // commit-wins: TTL 超過や stale releaser 先着 (Released) でも生存 hold は課金する
            // (二重課金は consume:{id} の UNIQUE が防ぐ)。失効 monthly hold のみ no-charge。
            // 戻り値 (TicketCommitResult) は可観測性のためのもので分岐には使わない
            $this->tickets->commit($reservation);

            $locked->status = JobStatus::Succeeded;
            $locked->progress = 100;
            $locked->save();

            return true;
        });
    }

    /**
     * LLM 段の共通有界リトライ。
     *
     * 打ち切り条件は 2 つ:
     *  (a) 試行回数 (config manual.analysis_llm_max_retries。計 1+N 試行)
     *  (b) 実時間 deadline (config manual.analysis_deadline_seconds)
     *
     * deadline の判定は **「deadline を過ぎたか」の真偽のみ**で行い、残り時間を
     * client timeout へ反映しない。これは意図的である: deadline の 1 秒前に開始した
     * 試行にも client timeout の全体 (C) を許すことで、job の worst-case を
     * 「D + C」という単純な形に閉じている (概念設計 §時間 budget)。
     * 残り時間を timeout に渡す実装へ変えるとこのモデルが壊れる。
     *
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
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
```

### RenderPipeline::run (`app/Services/Manual/RenderPipeline.php` L72-154)

```php
    public function run(int $renderJobId): void
    {
        $job = RenderJob::query()->findOrFail($renderJobId);
        $workDir = null;
        $uploadedKey = null;

        try {
            if (! $this->startJob($job)) {
                return; // 重複配送 / stale 回復後の遅延配送 → no-op
            }

            $manifest = $this->buildManifest($job);

            // compose (DB 外・ロック外)
            $workDir = $this->makeWorkDir($job);
            $localSources = $this->downloadSources($manifest, $workDir);
            $composed = $this->composer->compose(
                $manifest,
                $localSources,
                $workDir,
                fn (int $composedClips, int $totalClips) => $this->onClipComposed($job, $composedClips, $totalClips),
            );
            $this->updateProgress($job, RenderStep::Concat, 90);

            // upload → finalize (terminal tx)
            $this->storage->upload($composed->localPath, $manifest->outputKey);
            $uploadedKey = $manifest->outputKey;

            $result = new RenderResult(
                outputPath: $manifest->outputKey,
                clipDurationsMs: $composed->clipDurationsMs,
                totalDurationMs: $composed->totalDurationMs,
            );
            if ($this->finalize($job, $result)) {
                $uploadedKey = null; // succeeded に到達した出力は正 (後始末しない)
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (kind=render のみ。
                // finalize が $job->refresh() 済み。preview は通知しない)
                if ($job->kind === RenderKind::Render) {
                    $this->notifications->notifyRenderFinished($job);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->errorCodeFor($exception), $this->userMessageFor($exception));
        } finally {
            // アップロード済みで succeeded 未達 (失敗 / stale 先勝ち) の出力はベストエフォート削除
            if ($uploadedKey !== null) {
                try {
                    $this->storage->delete($uploadedKey);
                } catch (Throwable $cleanupException) {
                    report($cleanupException); // 孤児オブジェクトは reconcile 対象外のため記録だけ残す
                }
            }
            if ($workDir !== null) {
                File::deleteDirectory($workDir);
            }
        }
    }

    /** 開始 tx: queued guard + (render のみ) 予約の冪等確保 (§10.8-1) + running へ */
    private function startJob(RenderJob $job): bool
    {
        return DB::transaction(function () use ($job): bool {
            /** @var RenderJob $locked */
            $locked = RenderJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Queued) {
                return false; // 重複配送 guard
            }

            if ($locked->kind === RenderKind::Render) {
                $organization = $this->resolveOrganization($locked);
                $this->ensureReservation($locked, $organization); // 残高不足はここで throw → catch → failJob
            }

            $locked->status = JobStatus::Running;
            $locked->step = RenderStep::Compose;
            $locked->progress = 5;
            $locked->save();
            $job->refresh();

            return true;
        });
    }
```

### AutoRechargeService: LOCK_TTL / executeAttempt / executeAttemptLocked (`app/Services/Billing/AutoRechargeService.php` L505-572)

```php
     * updateSettings (停止 + pending キャンセル) と**同一の org lock**で直列化する。lock 内では
     * disable が割り込めないため、「enabled 確認 → invoice 作成 → invoice_id 保存 → pay」の
     * 全区間で停止後課金が構造的に起こらない。
     * lock 取得失敗は structured no-op — リコンサイル (i) が再実行する。
     */
    public function executeAttempt(TicketAutoRechargeAttempt $attempt): void
    {
        $organization = $attempt->organization;
        Assert::isInstanceOf($organization, Organization::class);

        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);

        try {
            $lock->block(10, function () use ($organization, $attempt): void {
                $this->executeAttemptLocked($organization, $attempt);
            });
        } catch (LockTimeoutException) {
            Log::info('auto-recharge: lock busy, skipping execution (reconcile will retry)', [
                'attempt_ulid' => $attempt->attempt_ulid,
            ]);
        }
    }

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

        if ($result->paid) {
            $amountPaid = $result->amountPaid;
            $amountDue = $result->amountDue;
            Assert::integer($amountPaid);
            Assert::integer($amountDue);
            $this->recordSuccessfulCharge($organization, $attempt, $invoiceId, $amountPaid, $amountDue, $result->paymentIntentId);

            return;
        }

        $this->handleChargeFailure($organization, $attempt, $result->failureCode, $result->requiresAction());
    }

```

### AutoRechargeService::recordSuccessfulCharge (条件付き UPDATE) (`app/Services/Billing/AutoRechargeService.php` L575-615)

```php
     * webhook (invoice.paid) / 同期 pay / リコンサイル (ii) の全経路がここに合流する。
     */
    public function recordSuccessfulCharge(
        Organization $organization,
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
        int $amountPaid,
        int $amountDue,
        ?string $paymentIntentId,
    ): void {
        // amount cross-check (fail-closed): attempt に pin した単価 × 数量 = 請求額 (amount_due)。
        // 実回収額 (amount_paid) は customer credit balance の適用で amount_due より小さくなり得る
        // 正当ケースがあるため照合対象にしない。台帳の purchase_amount には実回収額を記録する。
        $expected = $attempt->unit_amount * $attempt->quantity;
        if ($amountDue !== $expected) {
            throw new RuntimeException(
                "auto-recharge amount mismatch for invoice {$invoiceId}: expected due {$expected}, got {$amountDue}",
            );
        }

        DB::transaction(function () use ($organization, $attempt, $invoiceId, $amountPaid, $paymentIntentId): void {
            $this->tickets->grantAutoRecharge($organization, $attempt->quantity, $invoiceId, $amountPaid, $paymentIntentId);

            $updated = TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'status' => AutoRechargeAttemptStatus::Paid->value,
                    'stripe_payment_intent_id' => $paymentIntentId,
                    'resolved_at' => CarbonImmutable::now(),
                    'updated_at' => CarbonImmutable::now(),
                ]);

            if ($updated === 1) {
                TicketAutoRecharge::query()
                    ->where('organization_id', $organization->getKey())
                    ->update(['failure_count' => 0, 'updated_at' => CarbonImmutable::now()]);
            }
        });
    }

```

### AutoRechargeService::terminateAndCancel / tryTerminateInvoice (`app/Services/Billing/AutoRechargeService.php` L657-690)

```php
    /**
     * invoice 終端 → canceled 遷移 (決済手段の問題ではない破棄。failure_count 増分なし)。
     */
    public function terminateAndCancel(TicketAutoRechargeAttempt $attempt): void
    {
        if (! $this->tryTerminateInvoice($attempt)) {
            return;
        }

        $this->transitionToTerminal($attempt, AutoRechargeAttemptStatus::Canceled);
    }

    private function tryTerminateInvoice(TicketAutoRechargeAttempt $attempt): bool
    {
        if ($attempt->stripe_invoice_id === null) {
            return true; // invoice 未作成 = 課金され得ない
        }

        try {
            $this->gateway->terminateInvoice($attempt->stripe_invoice_id);

            return true;
        } catch (Throwable $e) {
            Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
                'attempt_ulid' => $attempt->attempt_ulid,
                'invoice_id' => $attempt->stripe_invoice_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
```

### CashierAutoRechargeGateway::payOffSessionInvoice / terminateInvoice (冪等キー派生と状態検査) (`app/Services/Billing/CashierAutoRechargeGateway.php` L60-182)

```php
        ];
    }

    public function createAutoRechargeInvoice(
        Organization $organization,
        string $priceId,
        int $quantity,
        array $metadata,
        string $idempotencyKeyBase,
    ): string {
        Assert::greaterThan($quantity, 0);

        $organization->createOrGetStripeCustomer();
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では invoice を作れません');

        $stripe = $organization->stripe();

        // 順序: draft invoice を先に作り、invoice 指定で item を作る (dangling invoice item 防止)。
        // auto_advance=false で Stripe の自動 finalize / 自動回収 (Smart Retries/dunning) を切る —
        // 「失敗 = void/delete による終端保証」の前提 (遅延成功の二重課金を構造的に排除)。
        $invoice = $stripe->invoices->create([
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'auto_advance' => false,
            'metadata' => $metadata,
        ], ['idempotency_key' => "{$idempotencyKeyBase}:invoice"]);

        $invoiceId = $invoice->id;
        Assert::stringNotEmpty($invoiceId, 'Stripe invoice id missing');

        // basil (2025-08-27) API: トップレベル 'price' は廃止。'pricing' => ['price' => ...] を使う。
        $stripe->invoiceItems->create([
            'customer' => $customerId,
            'invoice' => $invoiceId,
            'pricing' => ['price' => $priceId],
            'quantity' => $quantity,
            'metadata' => $metadata,
        ], ['idempotency_key' => "{$idempotencyKeyBase}:item"]);

        return $invoiceId;
    }

    public function payOffSessionInvoice(string $invoiceId, string $idempotencyKeyBase): OffSessionChargeResultDto
    {
        $stripe = Cashier::stripe();

        // Stripe invoice 状態機械: draft → finalize → open → pay → paid。
        // 既 finalize 済 (リコンサイル再実行) は invalid_request になり得るため許容して pay へ進む。
        try {
            $stripe->invoices->finalizeInvoice(
                $invoiceId,
                ['auto_advance' => false],
                ['idempotency_key' => "{$idempotencyKeyBase}:finalize"],
            );
        } catch (InvalidRequestException $e) {
            if (! str_contains((string) $e->getMessage(), 'finalized')) {
                throw $e;
            }
        }

        try {
            // basil API では Invoice に payment_intent が直載りしない。InvoicePayment を expand し
            // payments.data[].payment.payment_intent から PI id を解決する。
            $paid = $stripe->invoices->pay($invoiceId, [
                'off_session' => true,
                'expand' => ['payments.data.payment'],
            ], ['idempotency_key' => "{$idempotencyKeyBase}:pay"]);
        } catch (CardException $e) {
            // card_declined / authentication_required 等 → typed 失敗 (終端判断は Service 層)
            return OffSessionChargeResultDto::failed(
                $invoiceId,
                is_string($e->getStripeCode()) ? $e->getStripeCode() : null,
                is_string($e->getDeclineCode()) ? $e->getDeclineCode() : null,
            );
        }

        $amountPaid = $paid->amount_paid;
        $amountDue = $paid->amount_due;
        Assert::integer($amountPaid);
        Assert::integer($amountDue);

        return OffSessionChargeResultDto::paid($invoiceId, $amountPaid, $amountDue, $this->extractPaymentIntentId($paid));
    }

    public function terminateInvoice(string $invoiceId): void
    {
        $stripe = Cashier::stripe();

        try {
            $invoice = $stripe->invoices->retrieve($invoiceId);
        } catch (InvalidRequestException $e) {
            if ($e->getHttpStatus() === 404) {
                return; // 冪等: 存在しない (draft delete 済み含む) は成功扱い
            }

            throw $e;
        }

        $status = $invoice->status;

        if ($status === 'void' || $status === 'deleted') {
            return; // 冪等: 終端済み
        }

        // paid を誤って終端しない (付与経路の管轄)。uncollectible は Stripe 上 void 可能かつ
        // 後から支払われ得るため、終端保証の対象に含めて void する (放置すると遅延成功の穴になる)。
        Assert::true(
            $status === 'draft' || $status === 'open' || $status === 'uncollectible',
            "invoice {$invoiceId} は終端できない状態です (status={$status})",
        );

        if ($status === 'draft') {
            // draft は void 不可 (Stripe 制約) — delete で終端する
            $stripe->invoices->delete($invoiceId);

            return;
        }

        $stripe->invoices->voidInvoice($invoiceId);
    }

    public function retrieveInvoiceState(string $invoiceId): InvoiceStateDto
```

### AnalysisJobService::failJob / recoverStale (`app/Services/Manual/AnalysisJobService.php` L106-200)

```php

    /**
     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed / recoverStale の合流点。
     *
     * - terminal (succeeded/failed) 済みは no-op (terminal tx 勝ち・二重 fail を握る)
     * - manual は analyzing のときのみ復帰 (cuts があれば ready、無ければ draft)
     * - 予約は Reserved のみ release (並行 commit/release 済みは LogicException → 握って冪等)
     *
     * @return bool 実際に failed へ遷移させたか (terminal 済み no-op は false)
     */
    public function failJob(AnalysisJob $job, string $error): bool
    {
        $failed = DB::transaction(function () use ($job, $error): bool {
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status->isTerminal()) {
                return false;
            }

            // manual を先に lock で取得し (job → manual のロック順を維持)、失敗確定時の
            // scenario_version を job にスナップショットする (stale alert 判定の順序基準。T032)。
            /** @var VideoManual $manual */
            $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

            $locked->status = JobStatus::Failed;
            $locked->error = $error;
            $locked->scenario_version_at_terminal = $manual->scenario_version;
            $locked->save();

            // manual 復帰 (analyzing のときのみ。cuts があれば ready、無ければ draft = 概念設計 §4)
            if ($manual->status === VideoManualStatus::Analyzing) {
                $manual->forceFill([
                    'status' => $manual->cuts()->exists() ? VideoManualStatus::Ready : VideoManualStatus::Draft,
                ])->save();
            }

            // 予約 release (Reserved のみ。並行 commit/release 済みは LogicException → 握って冪等)
            $reservation = $locked->ticketReservation;
            if ($reservation !== null && $reservation->status === TicketReservationStatus::Reserved) {
                try {
                    $this->tickets->release($reservation);
                } catch (LogicException) {
                    // 並行 release/commit 済み
                }
            }

            return true;
        });

        // terminal 遷移が実際に起きたときだけ・commit 後に通知する (at-most-once。詳細設計
        // 「配信保証仕様」)。通知例外は NotificationCenterService 内 catch + report で
        // ジョブ本流を壊さない。二重 fail は上の terminal guard (false) が通知ごと握る
        if ($failed) {
            $this->notifications->notifyAnalysisFinished($job->refresh());
        }

        return $failed;
    }

    /**
     * stale ジョブの回復 (cron)。queued: dispatch 喪失、running: worker 異常終了。
     * failJob は行ロック + terminal guard で冪等 (TicketLedgerService::releaseStale と同型)。
     *
     * @return int 実際に回復 (failed 遷移) した件数 (走査中に terminal へ先着されたものは数えない)
     */
    public function recoverStale(): int
    {
        $threshold = CarbonImmutable::now()->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));
        $staleIds = AnalysisJob::query()
            ->where(function (Builder $query) use ($threshold): void {
                $query
                    ->where(function (Builder $query) use ($threshold): void {
                        $query->where('status', JobStatus::Queued->value)
                            ->where('created_at', '<=', $threshold);
                    })
                    ->orWhere(function (Builder $query) use ($threshold): void {
                        $query->where('status', JobStatus::Running->value)
                            ->where('updated_at', '<=', $threshold);
                    });
            })
            ->pluck('id');

        $recovered = 0;
        foreach ($staleIds as $id) {
            $job = AnalysisJob::query()->whereKey($id)->first();
            if ($job === null) {
                continue;
            }
            // failJob 内で行ロック + terminal guard 再検証するため、競合したジョブはそこで no-op (false)
            if ($this->failJob($job, '解析がタイムアウトしました。再実行してください。')) {
                $recovered++;
            }
        }

        return $recovered;
```

### QueuedJobLeaseInventoryTest: 目録定数と母集団走査 (S6 が委譲へ置き換える部分) (`tests/Architecture/QueuedJobLeaseInventoryTest.php` L44-147)

```php
/**
 * キューに載る全クラス (ShouldQueue 実装) の接続目録。
 *
 * value = `$this->onConnection('...')` で pin した接続名 / null = 既定接続。
 *
 * ★ deny-by-default: app/ の走査結果とこの目録の**対称差が空**であること。
 *   新しい Job / Mailable / Notification を足したら必ずここに登録する。
 * ★ null (既定接続) の entry は `$timeout` の宣言を禁止する
 *   (既定接続は QUEUE_CONNECTION 次第でどの接続にも化けるため、静的に retry_after と
 *    比較できない。`$timeout` が要るなら `onConnection()` で接続を pin する)。
 *
 * @var array<class-string, string|null>
 */
const QUEUED_JOB_LEASE_INVENTORY = [
    AutoRechargeTriggerJob::class => null,
    ExecuteAutoRechargeAttemptJob::class => null,
    HandleAutoRechargeChargeFailureJob::class => null,
    ReuseSubscriptionPaymentMethodJob::class => null,
    SetDefaultPaymentMethodJob::class => null,
    SyncBillingCustomerDetails::class => null,
    DeleteTakeObjectsJob::class => 'database-media',
    DeleteRenderOutputsJob::class => 'database-media',
    RunManualAnalysis::class => 'database-analysis',
    RunManualRender::class => 'database-render',
    InquiryAcknowledgementMail::class => null,
    InquiryReceivedMail::class => null,
    AutoRechargeActionRequiredNotification::class => null,
    AutoRechargeDisabledNotification::class => null,
    AutoRechargeEnabledNotification::class => null,
    AutoRechargeFailedNotification::class => null,
    PaymentFailedNotification::class => null,
    RenewalReminderNotification::class => null,
];

/**
 * app/ 配下の ShouldQueue 実装クラスを列挙する (純関数)。
 *
 * 母集団判定の正本は `ReflectionClass::implementsInterface(ShouldQueue::class)` +
 * `isInstantiable()`。親クラス / trait 経由の実装も拾えるため、Job だけでなく
 * Mailable / Notification も自動的に母集団へ入る。
 *
 * @return list<class-string>
 */
function jobLeaseShouldQueueClasses(): array
{
    $classes = [];
    foreach (jobLeaseAppPhpFiles() as $path) {
        $class = jobLeaseClassNameForPath($path);
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->isInstantiable()) {
            continue;
        }
        if (! $reflection->implementsInterface(ShouldQueue::class)) {
            continue;
        }

        $classes[] = $reflection->getName();
    }

    sort($classes);

    return $classes;
}

/**
 * app/ 配下の PHP ファイル絶対パス一覧 (純関数)。
 *
 * @return list<string>
 */
function jobLeaseAppPhpFiles(): array
{
    $appPath = base_path('app');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS),
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
function jobLeaseClassNameForPath(string $path): string
{
    $appPath = base_path('app').DIRECTORY_SEPARATOR;
    Assert::startsWith($path, $appPath, "app/ 配下ではないパスです: {$path}");

    $relative = substr($path, strlen($appPath), -strlen('.php'));

    return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
}
```

### ThrottleCoverageInventoryTest: 免除目録の作法 (cap / case 別 cap / 30 文字) (`tests/Architecture/ThrottleCoverageInventoryTest.php` L44-100)

```php

/** 母集団件数の下限 (空振り drift ガード。実測 70 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 60;
}

/** exemption 件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。 */
function throttleCoverageExemptionCap(): int
{
    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
    //   免除できる枠」になる。exact fit なら次の 1 本が必ず「この数値を変える差分」
    //   として現れ、個別理由・前提テスト追加要否・そもそも貼るべきでないかの
    //   再検討を強制できる。上げる前に必ず再検討すること。
    return 25;
}

/**
 * exemption の case 別上限 (分類の偏り検出)。全体 cap とは役割が違う
 * (全体 = セレクタの広さ / case 別 = どのカテゴリが膨らんだか)。
 * ★array_sum() で全体 cap を導出しない (両方を独立に検査する)。
 *
 * @return array<string, int> ThrottleCoverageExemption::value => 上限
 */
function throttleCoverageExemptionCapByCase(): array
{
    return [
        ThrottleCoverageExemption::StaticMetadataResponse->value => 4,
        ThrottleCoverageExemption::VendorMethodNotAllowedStub->value => 2,
        ThrottleCoverageExemption::SessionTeardownOnly->value => 2,
        ThrottleCoverageExemption::LocalOnlyDebugRoute->value => 1,
        ThrottleCoverageExemption::ComponentLevelLimiter->value => 1,
        ThrottleCoverageExemption::SignatureRequiredBeforeEffect->value => 1,
        // ★ここが膨らむ = 「貼るべき route を描画系として逃がした」疑い。
        ThrottleCoverageExemption::AuthViewRenderOnly->value => 13,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall->value => 1,
    ];
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function throttleCoverageReasonMinLength(): int
{
    return 30;
}

/**
 * throttle を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
 *
 * @return array<string, array{ThrottleCoverageExemption, string}>
 */
function throttleCoverageExemptions(): array
{
    $metadata = ThrottleCoverageExemption::StaticMetadataResponse;
    $stub = ThrottleCoverageExemption::VendorMethodNotAllowedStub;
    $teardown = ThrottleCoverageExemption::SessionTeardownOnly;
    $localOnly = ThrottleCoverageExemption::LocalOnlyDebugRoute;
    $component = ThrottleCoverageExemption::ComponentLevelLimiter;
```

### App\Enums\Security\ThrottleCoverageExemption (enum の書き方の見本) (`app/Enums/Security/ThrottleCoverageExemption.php` L1-40)

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 「保護対象群に属する route が throttle を持たないことが正しい」と裁定された理由の分類。
 *
 * `tests/Architecture/ThrottleCoverageInventoryTest.php` が deny-by-default で
 * 「throttle ちょうど 1 本」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   当てはまる case が無ければ、それは「throttle を貼るべき route」である。
 */
enum ThrottleCoverageExemption: string
{
    /**
     * 定数メタデータ応答。
     *
     * 適用条件: DB アクセス・暗号処理・外部呼び出し・メール送信・ファイル書込を一切伴わず、
     * 応答が config と url() だけで決まる。
     */
    case StaticMetadataResponse = 'static_metadata_response';

    /**
     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
     *
     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
     */
    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';

    /**
     * セッション破棄のみを行い、推測可能な秘密を一切扱わない route。
     *
     * 適用条件: 認証済みでのみ到達でき、失敗しても攻撃者が得る情報が無い。
     */
    case SessionTeardownOnly = 'session_teardown_only';

```

### AnalysisTimeBudgetInvariantTest (既存の時間 budget 連鎖。触らない) (`tests/Architecture/AnalysisTimeBudgetInvariantTest.php` L1-50)

```php
<?php

declare(strict_types=1);

use App\Jobs\Manual\RunManualAnalysis;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AnalysisBudget;

// Architecture lane は既定で DB を使わないが、本テストは予約 TTL を台帳の公開 API で
// 実測するため RefreshDatabase を明示適用する
uses(RefreshDatabase::class);

/*
 * 解析ジョブの時間 budget 連鎖を CI で固定する (config/定数を弄って連鎖を壊せない)。
 *
 * | 記号 | 項目 | 値 | 根拠 |
 * |---|---|---|---|
 * | C | client timeout (prompt YAML) | 360s | max_tokens 16000 飽和の実測 274s の約 1.31 倍 (運用上限) |
 * | D | パイプライン deadline | 1,080s = 3C | 全 3 段にフル ceiling の 1 回を許す最小値 |
 * | M₁ | finalize モデル予算 | 30s | terminal tx + commit/release + 通知 |
 * | S | 安全余白 | 90s | P (worker alarm → run() 入口) + タイマー精度 + シグナル配送 |
 * | T | job $timeout | 1,560s | D + C + M₁ + S |
 * | — | queue retry_after | 1,680s | T < retry_after |
 * | — | 予約 TTL | 1,800s | TicketLedgerService (変更しない) |
 * | — | stale 閾値 | 1,800s | manual.analysis_stale_after_minutes |
 *
 * **生成レート (token/s) は CI で pin しない**。実測に基づく運用上限であって
 * 保証値ではないため (概念設計 §実測)。CI が固定するのは順序関係と一貫性のみ。
 */
test('解析ジョブの時間 budget 連鎖 (timeout < retry_after < 予約TTL <= stale閾値)', function (): void {
    $timeout = (new RunManualAnalysis(1))->timeout;
    $retryAfter = config()->integer('queue.connections.database-analysis.retry_after');

    // 予約 TTL は台帳の公開 API (reserve) で実測する: 固定時刻で reserve し
    // expires_at − now を実 TTL とする (TicketLedgerService の private 定数を
    // ハードコード複製しない = 台帳側の TTL 変更をこのテストが実際に検出できる)
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [$organization] = createOrganizationWithOwner();
    $tickets = app(TicketLedgerService::class);
    $tickets->grant($organization, 1, '時間 budget テスト用');
    $reservation = $tickets->reserve($organization, 1);
    $ttlSeconds = (int) CarbonImmutable::now()->diffInSeconds($reservation->expires_at);

    $staleSeconds = config()->integer('manual.analysis_stale_after_minutes') * 60;
    expect($timeout)->toBeLessThan($retryAfter);
    expect($retryAfter)->toBeLessThan($ttlSeconds);
    expect($ttlSeconds)->toBeLessThanOrEqual($staleSeconds);
});
```

### RunManualAnalysis (既定接続でない = onConnection pin 済み) (`app/Jobs/Manual/RunManualAnalysis.php` L27-60)

```php
    use InteractsWithQueue;
    use Queueable;

    /** 自動再試行しない (§10.8-1。再実行は analyze 再トリガーのみ) */
    public int $tries = 1;

    /**
     * 時間 budget の worst-case (概念設計 §時間 budget の連鎖):
     *   deadline D (1,080s = 3 × client timeout) — AnalysisPipeline::run() 入口 (T0) を起点に
     *                                              各 LLM 試行の開始前に検査する
     *   + client timeout C (360s)                — deadline 直前に開始した 1 呼び出し分
     *   + finalize モデル予算 M₁ (30s)           — terminal tx + commit/release + 通知
     *   + 安全余白 S (90s)                       — P (worker が alarm を張ってから run() 入口
     *                                              = payload 復元/handler 解決/DI)
     *                                              + タイマー精度 + シグナル配送 + ログ
     *   = 1,560s
     * モデル上限 D + C + M₁ = 1,470s に対し 90 秒の明示的余白がある。
     * timeout (1,560) < retry_after (1,680) < 予約 TTL (1,800) ≤ stale 閾値 (1,800) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する。
     *
     * NOTE: 「3 段 × 3 試行 × timeout」という積のモデルは廃止した (リトライは deadline で
     *       打ち切るため、worst-case は積ではなく D + C になる)。
     */
    public int $timeout = 1560;

    public function __construct(public readonly int $analysisJobId)
    {
        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 600s のため。
        // Queueable trait が $connection プロパティを既に定義しているため、プロパティ再宣言でなく
        // onConnection() で指定する (typed 再宣言は trait composition エラーになる)
        $this->onConnection('database-analysis');
    }

    public function handle(AnalysisPipeline $pipeline): void
```

### AutoRechargeTriggerJob (唯一の ShouldBeUnique) (`app/Jobs/Billing/AutoRechargeTriggerJob.php` L20-45)

```php
 * 判定は Job 側に完全委譲 (reserve hot path で閾値を見ない)。**enabled 設定の存在確認で
 * 早期 return する** = opt-in 未設定の組織では何も起きない (既定 off の回帰点)。
 * 重複 dispatch は maybeCreateAttempt の pending 検査 / DB partial unique が吸収する。
 *
 * $tries = 1: 自動リトライしない (取りこぼしはリコンサイル (v) の管轄 — 二重課金面の安全側)。
 */
final class AutoRechargeTriggerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 30;

    public function __construct(public readonly int $organizationId) {}

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(AutoRechargeService $autoRecharge): void
    {
```

### ThrowingPromptFake (解析テストの注入点。onAttempt フック) (`tests/Support/ThrowingPromptFake.php` L26-60)

```php
    private int $index = 0;

    /**
     * @param  list<TextResponseFake|Throwable>  $script
     * @param  ?Closure(int):void  $onAttempt  試行ごとに呼ばれる (引数 = 1 始まりの試行番号)
     */
    public function __construct(
        private readonly array $script,
        private readonly ?Closure $onAttempt = null,
    ) {
        parent::__construct([]);
    }

    public function nextResponse(): TextResponseFake
    {
        $item = $this->script[$this->index] ?? throw new RuntimeException(
            'ThrowingPromptFake: script を使い切りました (想定より多く LLM が呼ばれています)'
        );
        $this->index++;

        if ($this->onAttempt !== null) {
            ($this->onAttempt)($this->index);
        }

        if ($item instanceof Throwable) {
            throw $item;
        }

        return $item;
    }

    /** 実際に LLM 呼び出しが試行された回数 */
    public function attemptCount(): int
    {
        return $this->index;
```

### FakeRenderComposer (レンダテストの注入点。duringCompose フック) (`tests/Feature/Manual/RenderPipelineTest.php` L43-80)

```php
/** テスト用の fake composer (実 ffmpeg に触れない。container swap で注入する) */
final class FakeRenderComposer implements VideoComposer
{
    public ?RenderManifest $lastManifest = null;

    /** @var array<int, string> */
    public array $lastSources = [];

    /** compose 中に呼ばれる hook (stale 競合等のインターリーブ細工用) */
    public ?Closure $duringCompose = null;

    /** 非 null なら compose がこの例外を投げる */
    public ?Throwable $throws = null;

    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
    {
        $this->lastManifest = $manifest;
        $this->lastSources = $localSources;
        if ($this->duringCompose !== null) {
            ($this->duringCompose)($manifest);
        }
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $durations = [];
        foreach ($manifest->clips as $index => $clip) {
            $durations[$clip->cutId] = 1_000 * ($index + 1);
            $onClipComposed($index + 1, count($manifest->clips));
        }
        $localPath = "{$workDir}/output.mp4";
        file_put_contents($localPath, 'fake-mp4');

        return new ComposedLocalVideo($localPath, $durations, (int) array_sum($durations));
    }
}

/**
```

### config/queue.php の retry_after (`config/queue.php` L36-90)

```php
        ],

        // 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
        // 静的 gate (QueueWorkerLeaseInvariantTest) は config をテスト環境の値で読むため、
        // env 上書きを残すと「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
        // 600s の根拠: この接続の既知の有限上限は ExecuteAutoRechargeAttemptJob の
        // Stripe 4〜5 呼び出し × SDK 上限 80s (Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT)
        // = 約 400s。ワーカー --timeout 540 (< 600) がそれを上回る
        // (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)。
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => 600,
            'after_commit' => false,
        ],

        // AI 解析専用 (RunManualAnalysis)。retry_after は job timeout (1,560s) より長く
        // 予約 TTL (1,800s) より短い (AnalysisTimeBudgetInvariantTest が連鎖を固定)。
        // 運用契約: worker は `php artisan queue:work database-analysis` を必須登録
        // (docs/architecture.md。滞留は analysis:recover-stale-jobs cron が回収)
        'database-analysis' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'analysis',
            'retry_after' => 1680,
            'after_commit' => false,
        ],

        // レンダ専用 (RunManualRender)。retry_after は job timeout (1,500s) より長く
        // 予約 TTL (1,800s) より短い (RenderTimeBudgetInvariantTest が連鎖を固定)。
        // 運用契約: worker は `php artisan queue:work database-render` を必須登録
        // (docs/architecture.md。滞留は render:recover-stale-jobs cron が回収)
        'database-render' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'render',
            'retry_after' => 1680,
            'after_commit' => false,
        ],

        // メディア掃除専用 (DeleteTakeObjectsJob)。運用契約: worker は
        // `php artisan queue:work database-media` を必須登録 (docs/architecture.md)
        'database-media' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'media',
            'retry_after' => 300,
            'after_commit' => false,
        ],

```

### JobStatus enum (`app/Enums/Manual/JobStatus.php` L1-24)

```php
<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 解析/レンダジョブの状態 (doc/10 §10.2)。
 * AnalysisJob / RenderJob が共用する。
 */
enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** terminal (成否確定) か。failJob / recoverStale の guard に使う */
    public function isTerminal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
```
