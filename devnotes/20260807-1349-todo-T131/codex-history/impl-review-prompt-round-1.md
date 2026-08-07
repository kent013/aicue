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

---

# system: 実装レビュアー

あなたは Laravel 12 (PHP 8.4) + Svelte 5 + Inertia.js のアプリ `aicue` の実装レビュアーである。
TODO **T131「ジョブ二重実行の所有権再検証を追加」** の実装差分をレビューせよ。
詳細設計は Codex による design-review 合議で **Round 7 に APPROVED 済み**である。

## レビュー観点

1. **設計との一致性** — 詳細設計の施策 S1〜S7 が、設計どおりに実装されているか。
   意図的な逸脱があるならそれは妥当か。
2. **正確性** — 競合・並行実行時に破れる不変条件はないか。preflight の配置規則
   (「外部呼び出しの直前」「検証と外部呼び出しの間に自前の書き込みを挟まない」) が守られているか。
   条件付き UPDATE の WHERE 条件・戻り値の扱いに穴はないか。
3. **PHPStan level 10 適合性** — 型の widen / `@phpstan-ignore` / baseline を使っていないか。
   null 安全性 (`Webmozart\Assert\Assert` の適切な使用)。
4. **DTO / JsonResource パターン** — `response()->json()` 直書きがないか
   (本差分は API / Inertia 応答を変更しない前提)。
5. **テスト網羅性** — 各施策にテストがあるか。Architecture gate が「本当に検出できる」か
   (mutation で赤化するか)。Feature テストが「配置」を behavioral に固定できているか。
   テストが実装と乖離していないか (fake が判定そのものを差し替えていないか)。
6. **セキュリティ** — ログに PII / 外部 payload が漏れないか。tenant キーの扱い。
7. **DESIGN.md 準拠 / Atomic Design 準拠** — 本差分に `resources/js` / `resources/css` の変更は
   **1 行も無い**ため該当なし。該当なしと判断してよい。

## 出力形式

- ファイルごとに判定を書く。
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する。
  - [Critical] = 不変条件が破れる / 本番で壊れる / 設計と矛盾する
  - [Warning] = 将来壊れやすい / 見落としの可能性がある
  - [Suggestion] = 好みの範囲
- 最後に **全体判定: APPROVED / CHANGES_REQUESTED** を 1 行で書く。

## 既に決着済みで蒸し返してはならない設計判断 (design-review Round 1〜7 で明示的に反論し、Codex も維持を了承済み)

1. `terminateInvoice()` に attempt 固定の idempotency key を**使わない**
   (Stripe 側の状態検査による冪等化のほうが期限が無く強い)。
2. `recordSuccessfulCharge()` の「grant → attempt 遷移」の**順序を変えない**
   (逆順は「Stripe で課金済みなのにチケット未付与」というより悪い不整合を生む)。
3. 期待集合 (`jobDedupRequiredExternalCalls()`) と目録 (`jobDedupGuarantees()`) を**同時に**
   書き換えた場合は宣言的 gate では検出できない — これは**性質として明記のうえ受容**している。
4. Manual 側には preflight collaborator (注入可能クラス) を**作らない**
   (既存 fake のフックで配置を赤化できるため。Billing だけが注入点を持たない構造だった)。
5. 入口の排他 (`ShouldBeUnique` の追加) は**スコープ外**。免除の inventory 登録として扱う。

これらへの再提案は [Suggestion] としても書かないこと。**上記以外**の観点で厳しく見てほしい。

---

# user

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
| S4 | auto-recharge の preflight (Stripe 呼び出し直前) + 中断時の invoice 終端 | `app/Services/Billing/AutoRechargeService.php`<br>`app/Support/JobExecution/AttemptOwnershipPreflight.php` (新)<br>`tests/Support/FakeAttemptOwnershipPreflight.php` (新)<br>`tests/Support/FakeAutoRechargeGateway.php` | High |
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
     * ★この event の**必須キー集合を 7 キーで固定**する
     *   (event / job_type / job_id / expected_status / actual_status / stage / external_call)。
     *   集計 schema を揃えるためであり、**PII を含まないドメイン固有キーの追加は可**
     *   (Billing は `attempt_ulid` を 1 本足す)。必須キーを欠くログを同じ event 名で出さない。
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
  - `logContext() の**必須**キー集合が仕様どおり (7 キー) で、PII 由来のキーを含まない`
    (Billing 側は PII-free なドメイン固有キー `attempt_ulid` を 1 本足すことが許される)
    — キー名に `email` / `name` / `user` を含まないことを機械的に検査
  - `logContext() の値がすべて scalar|null (payload オブジェクトを埋め込んでいない)`
  - `whileRunning() は expectedStatus に JobStatus::Running を入れる`
- 個別の `DatabaseTransactions` は使わない (DB を触らないテストだが `RefreshDatabase` の
  グローバル適用に従う)

### リスク

- 新 enum / 例外の追加のみで挙動を持たないため、後退リスクはほぼゼロ。
- `ExternalCallKind` に `LOG_EVENT` を置くのはやや変則 (enum + const)。
  代替 (専用の Support クラス新設) は「**ログ event 定数だけのために専用クラスを作る**」ことになり
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
  - **コンストラクタ**: `AttemptOwnershipPreflight $preflight` を追加 (Round 5 レビュー反映)
  - `executeAttemptLocked()` (L528-570): Stripe 2 呼び出しの直前へ preflight を挿入し、
    `stripe_invoice_id` の永続化を**条件付き UPDATE** 化する
  - `terminateInvoiceAfterOwnershipLost()` / `terminateUnattachedInvoice()` /
    `terminateInvoiceBestEffort()` を private メソッドとして新設
- **新規** `app/Support/JobExecution/AttemptOwnershipPreflight.php` —
  Billing の preflight を**注入可能な collaborator**として切り出す (Round 5 レビュー反映)
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
- テスト支援クラス (1): **新規** `tests/Support/FakeAttemptOwnershipPreflight.php` —
  `AttemptOwnershipPreflight` を継承した**競合注入シーム**。
  `public array $terminalizeAt = []` (この checkpoint に到達したら attempt 行を terminal 化する) /
  `public AutoRechargeAttemptStatus $terminalStatus = Canceled` /
  `public array $calls = []` (到達した checkpoint の記録) を持ち、`app()->instance()` で差し込む。
  **判定そのものは fake しない — 行を terminal 化してから `parent::stillPending()` へ委譲する**
  (Round 6 レビュー反映)。fake が作るのは「checkpoint の直前に停止側が terminal 化した」という
  **窓だけ**であり、`refresh()` / status 判定 / 所有権喪失ログは本番実装が実行する。
  これにより配置 (M16 / M17) と抑止後の後始末 (canceled → invoice 終端) を
  **同じ 1 本のテストで**決定論的に固定できる。詳細は下記テスト計画
- テスト支援クラス (2): `tests/Support/FakeAutoRechargeGateway.php` に
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
            if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoiceCreate)) {
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
        if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoicePay)) {
            $this->terminateInvoiceAfterOwnershipLost($attempt, $invoiceId);

            return;
        }

        $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);

        // …以降は現行のまま
    }

```

**注入可能な preflight collaborator** — `app/Support/JobExecution/AttemptOwnershipPreflight.php` (新規):

```php
<?php

declare(strict_types=1);

namespace App\Support\JobExecution;

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\Security\ExternalCallKind;
use App\Models\Billing\TicketAutoRechargeAttempt;
use Illuminate\Support\Facades\Log;

/**
 * auto-recharge attempt の所有権再検証 (preflight suppression。裁定 AG-082 標準形 (2))。
 *
 * ★ **なぜ独立クラスなのか** (Round 5 レビュー反映):
 *   Manual の 2 パイプラインは「外部呼び出しが複数回連続する」構造なので、
 *   既存 fake のフック (`ThrowingPromptFake::$onAttempt` / `FakeRenderComposer::$duringCompose`)
 *   で **preflight の配置そのものを behavioral に赤化できる**。
 *   一方 Billing は「冒頭の Pending guard → create → attach → pay」という直列で、
 *   guard と各 preflight の**間に注入点が 1 つも無い**ため、
 *   preflight 呼び出しを削除しても既存 fake では赤化しない。
 *   そこで preflight だけを差し替え可能な collaborator として切り出す
 *   (本番コードにテスト専用 closure を足さないため)。
 *   Manual 側は同じ理由が無いので**この形にしない** (利益の無い churn を作らない。
 *   AGENTS.md 思考原則 2)。
 *
 * ★ **非 final** にしてあるのは fake が override するためである
 *   (`App\Services\Render\RenderObjectStorage` と同じ作法。interface は新設しない)。
 *   ただし fake は**判定を差し替えない** — checkpoint 直前に attempt 行を terminal 化して
 *   `parent::stillPending()` へ委譲するだけの「競合注入シーム」である (Round 6 レビュー反映)。
 *   したがって本メソッドの refresh / status 判定 / ログはテストでも常に本実装が走る。
 */
class AttemptOwnershipPreflight
{
    /**
     * Stripe 呼び出しの直前に attempt の所有権 (= pending) を再検証する。
     *
     * @return bool 送信してよいか (false = 所有権喪失 → 呼び出し側が中断する)
     */
    public function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
    {
        $attempt->refresh();
        if ($attempt->status === AutoRechargeAttemptStatus::Pending) {
            return true; // アーリーリターン (正常系)
        }

        // Manual ドメインと**同じ必須キー集合**で観測する (集計の語彙を 1 本に保つ。
        // JobOwnershipLostException::logContext() と必須 7 キーが一致する。
        // Billing 固有の追加キーは PII-free な attempt_ulid の 1 本だけ)。
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
}
```

`AutoRechargeService` に戻る (後始末の 3 メソッドはサービス側に置く):

```php
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

**配置 (placement) と抑止 (suppression) の同時固定** — `FakeAttemptOwnershipPreflight` を
差し込んで行う (Round 5 / Round 6 レビュー反映。これらが M16 / M17 を赤化する)。

シームの動作 (`tests/Support/FakeAttemptOwnershipPreflight`):

```php
final class FakeAttemptOwnershipPreflight extends AttemptOwnershipPreflight
{
    /** @var list<ExternalCallKind> この checkpoint に到達したら attempt 行を terminal 化する */
    public array $terminalizeAt = [];

    /** terminal 化させる先 (canceled / failed / paid を切り替えて後始末の分岐を固定する) */
    public AutoRechargeAttemptStatus $terminalStatus = AutoRechargeAttemptStatus::Canceled;

    /** @var list<string> 到達した checkpoint の記録 (= 配置の観測) */
    public array $calls = [];

    public function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
    {
        $this->calls[] = $call->value;

        if (in_array($call, $this->terminalizeAt, true)) {
            // ★ 「checkpoint の**直前**に停止側 / 他ワーカーが terminal 化した」窓を作る。
            //   これが本設計で唯一足りていなかった注入点である (Round 6 レビュー反映)。
            TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'status' => $this->terminalStatus->value,
                    'updated_at' => CarbonImmutable::now(),
                ]);
        }

        // ★ **判定は fake しない**。refresh / status 判定 / 所有権喪失ログは本番実装が実行する。
        //   fake の責務は「窓を開けること」だけである。
        return parent::stillPending($attempt, $call);
    }
}
```

- [ ] `配置: create の直前に preflight がある (terminalizeAt=[StripeInvoiceCreate] で invoice を作らない)`
  - 期待: `$gateway->createdInvoices === []` かつ `$gateway->payCalls === []` かつ
    `$gateway->terminated === []` (未作成なので終端対象が無い)
  - 期待: `$fakePreflight->calls === ['stripe_invoice_create']` (pay checkpoint へ到達しない)
  - **M16**: create 直前の `stillPending()` 呼び出しを削除すると、行が Pending のままなので
    invoice が作成され attach も成功し、`createdInvoices` が 1 件になって**赤化する**
- [ ] `配置: pay の直前に preflight がある (terminalizeAt=[StripeInvoicePay] で pay しない)`
  - 順序: `preflight 1 は Pending で通過 → create → attach 1 行 → preflight 2 の直前に canceled 化 → 抑止`
  - 期待: `$gateway->createdInvoices` は 1 件・`$gateway->payCalls === []`
  - 期待: **`terminateInvoice` が作成された invoice id で 1 回呼ばれる**
    (`terminateInvoiceAfterOwnershipLost()` の `Canceled` 分岐。要件 ii)
  - 期待: `attempt->stripe_invoice_id` は attach 済み (DB に残る)
  - **M17**: pay 直前の `stillPending()` 呼び出しを削除すると terminal 化が起きず pay が走って**赤化する**
- [ ] `後始末: terminalStatus=Failed のとき terminateInvoice を呼ばない` (二重終端の抑止)
  - `terminateAndFail()` が既に終端済みという前提に立つ分岐 (`Canceled` 限定) の固定
- [ ] `後始末: terminalStatus=Paid のとき terminateInvoice を呼ばない` (void 不可の分類)
- [ ] `配置: 行が Pending のままなら (terminalizeAt=[]) create → pay が従来どおり進む` (回帰)
  - 期待: `$fakePreflight->calls === ['stripe_invoice_create', 'stripe_invoice_pay']`
    (= 2 つの checkpoint を**両方**通る)

**抑止 (suppression) の固定** — 実 `AttemptOwnershipPreflight` を使い、実行前の状態で再現する:

- [ ] `preflight 1: invoice 作成前に attempt が canceled → createAutoRechargeInvoice を呼ばない`
  - gateway fake の呼び出し記録が空であること
  - ※これは冒頭の Pending guard でも止まるため、**配置の保証は上の M16 が担う**
    (本ケースは「所有権喪失ログが出る」ことの確認が主目的)
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
- ※ `preflight 2` の後始末分岐 (paid / failed で `terminateInvoice` を呼ばない) は
  上の**配置セクション**へ移した (`terminalStatus` を切り替えて同じシームで固定する。
  Round 6 レビュー反映で注入点が 1 つに揃ったため、2 通りの再現手段を持たない)
- [ ] `preflight 2: terminateInvoice が例外を投げても課金処理へ進まない` (要件 v)
  - fake の `terminateInvoice` を throw させ、`payOffSessionInvoice` が 0 回であること
  - 台帳エントリが 1 件も増えていないこと
- [ ] `前提: terminateInvoice が失敗したら attempt は Pending のまま (Failed へ遷移しない)`
  - **Round 3 レビュー反映**。`terminateInvoiceAfterOwnershipLost()` が `Canceled` 限定でよいのは
    「Failed へ遷移した attempt は invoice 終端済み」という前提が成り立つからであり、
    その前提 (`terminateAndFail()` の順序) が変わったら本設計が壊れる。
    fake の `$failOnTerminate = true` で固定する
- [ ] `前提: Failed へ遷移した attempt は invoice が終端済みである`
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
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
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
     * 一意制約 (partial unique index) が 2 回目の**起票**そのものを拒否する。
     *
     * 適用条件: DB の制約で重複行が**書けない**こと (アプリ側の事前検査は根拠にならない)。
     * ★対象は「行の起票」である。効果 (計上・付与) の重複拒否は下の別 case を使う。
     */
    case DatabaseUniqueConstraint = 'database_unique_constraint';

    /**
     * 冪等キーの UNIQUE が、同じキーによる 2 回目の**効果確定**を拒否する。
     *
     * 適用条件: 効果 (台帳計上・付与など) の挿入が冪等キー付きで行われ、
     * DB の UNIQUE により 2 回目が 0 行挿入になり、呼び出し側がそれを検出できること。
     * ★起票の拒否 (`DatabaseUniqueConstraint`) とは対象が違うため別 case にする
     *   (AGENTS.md 思考原則 4)。準拠実装:
     *   `TicketLedgerService::grantAutoRecharge()` の `recharge:{invoiceId}`。
     */
    case IdempotentLedgerKeyUniqueConstraint = 'idempotent_ledger_key_unique_constraint';
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
     * @param  non-empty-list<PreflightRequirement>  $preflights  外部呼び出しごとの再検証点 (複数可)
     * @param  non-empty-string  $rationale  30 文字以上
     *
     * ★ `$mechanisms` が **複数登録できる**ことが要点 (Round 2 レビュー反映)。
     *   ExecuteAutoRechargeAttemptJob は「台帳付与の一回性 = invoice 単位の冪等キー UNIQUE」と
     *   「attempt 遷移の一回性 = 条件付き UPDATE」という**軸の違う 2 本の保証**を持つ。
     *   単一 mechanism で書くと、どちらか一方が enum の適用条件に一致しない
     *   誤った分類を型付き目録が保持してしまう。
     * ★ `$preflights` も **複数**である (Round 3 レビュー反映)。
     *   auto-recharge は `StripeInvoiceCreate` と `StripeInvoicePay` の **2 つ**の
     *   外部呼び出しを持つ。1 件しか登録しないと、もう一方の preflight を削除しても
     *   gate が green のままになり「目録からすべての外部呼び出しを辿れる」が成立しない。
     */
    public function __construct(
        public array $mechanisms,
        public array $preflights,
        public string $rationale,
    ) {
        Assert::notEmpty($mechanisms, '保証機構を 1 つ以上登録すること');
        Assert::notEmpty($preflights, 'preflight を 1 つ以上登録すること (無いなら NoExternalCall)');
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

**(4) gate 本体** — `tests/Architecture/JobExecutionDedupInventoryTest.php` (要点のみ)。

> **gate が保証するもの / しないもの** (Round 3/4 レビュー反映):
> - **保証する**: 母集団の全クラスが分類されている / 期待する外部呼び出し種別と
>   checkpoint 登録の**集合一致** / 再検証点の実在と制御方式に一致する戻り型 /
>   根拠 30 文字以上 / 免除 cap / `PreflightRequirement` 実装が 2 種に閉じている /
>   固定 event literal が 1 箇所に閉じている。
> - **保証しない**: preflight が**外部呼び出しの直前に置かれている**こと (Feature テストの担当)。
>   期待値 map と目録を**同時に**消す変更 (宣言的 gate の性質。1 箇所の削除では通らない
>   = レビューで必ず 2 箇所の差分が見えることが目的)。

```php
<?php

declare(strict_types=1);

// ★以下は「要点のみ」の掲載だが、**import はこの一覧を満たすこと** (Round 3 レビュー反映)。
use App\Enums\Security\ExternalCallKind;
use App\Enums\Security\JobDedupExemption;
use App\Enums\Security\JobDedupGuarantee;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Jobs\Manual\RunManualAnalysis;
use App\Jobs\Manual\RunManualRender;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Manual\RenderPipeline;
use App\Support\JobExecution\AttemptOwnershipPreflight;
use Tests\Support\JobDedup\ExemptionEntry;
use Tests\Support\JobDedup\GuaranteeEntry;
use Tests\Support\JobDedup\NoExternalCall;
use Tests\Support\JobDedup\PreflightCheckpoint;
use Tests\Support\JobDedup\PreflightControlFlow;
use Tests\Support\JobDedup\PreflightRequirement;
use Tests\Support\QueuedJobPopulation;
use Webmozart\Assert\Assert;

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
            preflights: [new PreflightCheckpoint(
                AnalysisPipeline::class, 'assertStillOwned',
                ExternalCallKind::LlmCompletion, PreflightControlFlow::ThrowsOnLoss,
            )],
            rationale: 'startJob が lockForUpdate + status===queued で running へ遷移させ、'
                .'finalize が同一 tx で materialize + tickets->commit + succeeded を原子化する。'
                .'LLM は冪等キーを持たないため、各呼び出しの直前に preflight を置く。',
        ),
        RunManualRender::class => new GuaranteeEntry(
            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
            preflights: [new PreflightCheckpoint(
                RenderPipeline::class, 'assertStillOwned',
                ExternalCallKind::ObjectStoragePut, PreflightControlFlow::ThrowsOnLoss,
            )],
            rationale: 'startJob / finalize が AnalysisPipeline と同型。S3 PUT は取り消せない'
                .'外部副作用なので、updateProgress の後・upload の直前に preflight を置く。',
        ),
        ExecuteAutoRechargeAttemptJob::class => new GuaranteeEntry(
            // ★軸の違う 2 本の保証を**両方**登録する (Round 2 レビュー反映)
            mechanisms: [
                // ★台帳の冪等キー UNIQUE は「起票の拒否」ではなく「効果確定の拒否」なので
                //   DatabaseUniqueConstraint とは別 case (Round 3 レビュー反映)
                JobDedupGuarantee::IdempotentLedgerKeyUniqueConstraint, // 付与の一回性 (invoice 単位)
                JobDedupGuarantee::ConditionalStatusUpdate,             // attempt 遷移の一回性 (attempt 単位)
            ],
            // ★外部呼び出しは 2 つある。両方を登録する (Round 3 レビュー反映)
            preflights: [
                new PreflightCheckpoint(
                    AttemptOwnershipPreflight::class, 'stillPending',
                    ExternalCallKind::StripeInvoiceCreate, PreflightControlFlow::ReturnsBoolean,
                ),
                new PreflightCheckpoint(
                    AttemptOwnershipPreflight::class, 'stillPending',
                    ExternalCallKind::StripeInvoicePay, PreflightControlFlow::ReturnsBoolean,
                ),
            ],
            rationale: '冪等キーが 2 本ある: 付与の一回性は台帳の recharge:{invoiceId} UNIQUE '
                .'(invoice 単位。付与は attempt 遷移より先に走るが、この UNIQUE が二重付与を拒否する)、'
                .'attempt 遷移の一回性は where status=pending の条件付き UPDATE (attempt 単位)。'
                .'org lock (TTL 180s) は best-effort で保証を担わない。',
        ),
        // AutoRechargeTriggerJob / DeleteTakeObjectsJob / DeleteRenderOutputsJob /
        // SyncBillingCustomerDetails / … 全 18 件をここか jobDedupExemptions() のどちらかへ登録する
    ];
}

/**
 * ジョブごとに **preflight を要求する外部呼び出しの種別** (期待値の正本)。
 *
 * ★ 目録 (`jobDedupGuarantees()`) とは**独立した宣言**である (Round 4 レビュー反映)。
 *   目録の中だけで閉じた検査 (非空 / 重複なし / 実在) は「登録漏れ」という**不在**を
 *   検出できない — checkpoint を 1 件削っても残りが要件を満たしてしまう。
 *   期待集合との一致を要求すれば、削除は必ず赤になる。
 *
 * ★ **この検査が保証しないこと**: 本 map と目録の**両方**を同時に消せば green のままになる。
 *   これは宣言的 gate の性質であり、目的は「1 箇所の削除では通らない = レビューで必ず
 *   2 箇所の差分が見える」ことである。
 *   より強い形 (サービスのソースを走査し、外部呼び出しの実在から期待集合を導出する) は、
 *   **preflight を意図的に持たない外部呼び出し** (所有権喪失**後**の後始末である
 *   `terminateInvoice`) を別分類する必要があり複雑さが跳ねるため今回は採らない
 *   (AGENTS.md 思考原則 2)。
 *
 * ★ 空リスト = 外部呼び出しを持たない (`preflights` は `NoExternalCall` ちょうど 1 件)。
 *
 * @return array<class-string, list<ExternalCallKind>>
 */
function jobDedupRequiredExternalCalls(): array
{
    return [
        RunManualAnalysis::class => [ExternalCallKind::LlmCompletion],
        RunManualRender::class => [ExternalCallKind::ObjectStoragePut],
        ExecuteAutoRechargeAttemptJob::class => [
            ExternalCallKind::StripeInvoiceCreate,
            ExternalCallKind::StripeInvoicePay,
        ],
        // jobDedupGuarantees() の全キーを漏れなく列挙する (キー集合一致を gate が検査する)
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
        foreach ($entry->preflights as $preflight) {
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
    }
});

test('保証機構は 1 つ以上・重複なしで登録されている', function (): void {
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect($entry->mechanisms)->not->toBeEmpty("{$class}: 保証機構が空です");

        // ★重複「値そのもの」を出す (順序依存の比較より失敗メッセージが読みやすい)
        $values = array_map(static fn (JobDedupGuarantee $m): string => $m->value, $entry->mechanisms);
        $duplicates = array_values(array_diff_assoc($values, array_unique($values)));
        expect($duplicates)->toBe([], "{$class}: 保証機構が重複しています: ".implode(', ', $duplicates));
    }
});

test('期待する外部呼び出し種別が全ジョブ分宣言されている (期待値の書き忘れ検出)', function (): void {
    $guaranteed = array_keys(jobDedupGuarantees());
    $required = array_keys(jobDedupRequiredExternalCalls());
    sort($guaranteed);
    sort($required);

    expect($required)->toBe($guaranteed,
        'jobDedupRequiredExternalCalls() は jobDedupGuarantees() の全クラスを列挙すること');
});

test('期待する外部呼び出し種別に重複がない', function (): void {
    foreach (jobDedupRequiredExternalCalls() as $class => $kinds) {
        $values = array_map(static fn (ExternalCallKind $k): string => $k->value, $kinds);
        $duplicates = array_values(array_diff_assoc($values, array_unique($values)));
        expect($duplicates)->toBe([], "{$class}: 期待種別が重複しています: ".implode(', ', $duplicates));
    }
});

test('登録済み checkpoint の種別集合が期待集合と一致する (登録漏れ / 余剰の検出)', function (): void {
    // ★ ここが completeness の要 (Round 4 レビュー反映)。目録の中だけで閉じた検査では
    //   「checkpoint を 1 件削った」ことを検出できない (残りが要件を満たしてしまう)。
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect($entry->preflights)->not->toBeEmpty("{$class}: preflight が空です");

        $checkpoints = array_values(array_filter(
            $entry->preflights,
            static fn (PreflightRequirement $p): bool => $p instanceof PreflightCheckpoint,
        ));
        $none = array_values(array_filter(
            $entry->preflights,
            static fn (PreflightRequirement $p): bool => $p instanceof NoExternalCall,
        ));

        $expectedKinds = array_map(
            static fn (ExternalCallKind $k): string => $k->value,
            jobDedupRequiredExternalCalls()[$class] ?? [],
        );
        $registeredKinds = array_map(
            static fn (PreflightCheckpoint $c): string => $c->externalCall->value,
            $checkpoints,
        );
        sort($expectedKinds);
        sort($registeredKinds);

        expect($registeredKinds)->toBe($expectedKinds,
            "{$class}: checkpoint の種別集合が期待 (jobDedupRequiredExternalCalls) と一致しません。"
            .'外部呼び出しを増減させたら両方を更新すること');

        if ($expectedKinds === []) {
            // 外部呼び出しを持たないジョブ: NoExternalCall ちょうど 1 件
            expect(count($none))->toBe(1, "{$class}: NoExternalCall は 1 件だけ登録すること");
            expect($checkpoints)->toBe([], "{$class}: 外部呼び出しなしと宣言しつつ checkpoint があります");

            continue;
        }

        // 外部呼び出しを持つジョブ: NoExternalCall と混在させない
        expect($none)->toBe([], "{$class}: NoExternalCall と PreflightCheckpoint を混在登録しています");
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
    // ★ single / double quote の **4 パターン**を検査する (Round 3 レビュー反映)。
    //   片方だけだと "job_ownership_lost" で gate を回避できる。
    $literals = [
        "'job_ownership_lost'", '"job_ownership_lost"',
        "'job_ownership_lost_cleanup'", '"job_ownership_lost_cleanup"',
    ];
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
| M8 | `app/Services/Manual/AnalysisPipeline.php` に `'job_ownership_lost'` を直書き | 「固定 event 名の literal は ExternalCallKind 以外に直書きされていない」 |
| M8b | 同じく **double quote** で `"job_ownership_lost"` を直書き | 同上 (quote 種別の取りこぼしが無いこと) |
| M8c | `ExecuteAutoRechargeAttemptJob` の `preflights` から `StripeInvoiceCreate` を削除 | 「登録済み checkpoint の種別集合が期待集合と一致する」 |
| M8d | `jobDedupRequiredExternalCalls()` から 1 クラス丸ごと削除 | 「期待する外部呼び出し種別が全ジョブ分宣言されている」 |
| M9 | 目録の免除を 1 件増やす (cap 到達) | 「免除件数が上限を超えない」 |
| M10 | `QUEUED_JOB_LEASE_INVENTORY` から 1 件削除 | 既存の「接続経路: キューに載る全クラスが目録に登録されている」(走査の委譲後も従来どおり検出できることの確認) |
| M11 | `AnalysisPipeline::writeProgress()` の `where('status', running)` を外す | S2 の「cron failed 後に step / progress が書き戻されない」 |
| M12 | `RenderPipeline::updateProgress()` の `where('status', running)` を外す | S3 の「cron failed 後に step / progress が書き戻されない」 |
| M13 | `stripe_invoice_id` の永続化を素の `save()` に戻す | S4 の「attach 0 行: invoice_id を書かず invoice を終端する」 |
| M14 | `terminateUnattachedInvoice()` を `Canceled` 限定にする | S4 の「attach 0 行: failed へ遷移していた場合も終端する」 |
| M15 | cleanup ログの event 名を `LOG_EVENT` に戻す | S4 の「event ごとのキー集合 schema」 |
| M16 | `createAutoRechargeInvoice()` 直前の `stillPending()` 呼び出しを削除 | S4 の「配置: create の直前に preflight がある」 |
| M17 | `payOffSessionInvoice()` 直前の `stillPending()` 呼び出しを削除 | S4 の「配置: pay の直前に preflight がある」 |

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
| 登録された**すべての** preflight checkpoint が実在し、制御方式 (`PreflightControlFlow`) に一致する戻り型を持つ (**存在まで**) | `JobExecutionDedupInventoryTest` |
| **期待する外部呼び出し種別 (`jobDedupRequiredExternalCalls()` が正本) と checkpoint 登録の集合一致** / `NoExternalCall` と混在しない | `JobExecutionDedupInventoryTest` |
| preflight が**外部呼び出しの直前に置かれている** (配置) | `AnalysisPipelineTest` / `RenderPipelineTest` / `AutoRechargeServiceTest` (追記分。fake が呼ばれないことで固定)<br>★**分担**: Architecture gate = 集合一致 + 実在 + 戻り型 / Feature テスト = 配置。<br>Manual は既存 fake のフック (`onAttempt` / `duringCompose`) で、**Billing は注入可能な `FakeAttemptOwnershipPreflight`** で配置を赤化する |
| 終端後にジョブ行の進捗を書き戻さない (条件付き UPDATE) | `AnalysisPipelineTest` / `RenderPipelineTest` (追記分) |
| 終端後に `stripe_invoice_id` を書き込まない (条件付き UPDATE) | `AutoRechargeServiceTest` (追記分) |
| 同一 invoice への付与は台帳に 1 件しか入らない | `AutoRechargeServiceTest` (追記分) |
| 免除は型付き enum + 30 文字以上の根拠 | `JobExecutionDedupInventoryTest` + value object の `Assert` |
| 入口の排他 TTL / uniqueFor < retry_after | `JobExclusionOrderingInvariantTest` |
| `$timeout < retry_after < 予約 TTL ≤ stale 閾値` | `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` (既存) |
| worker `--timeout` < `retry_after` | `QueueWorkerLeaseInvariantTest` (既存) |
| 所有権喪失時に LLM を呼ばない | `AnalysisPipelineTest` (追記分) |
| 所有権喪失時に S3 PUT しない | `RenderPipelineTest` (追記分) |
| 所有権喪失時に **invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する** | `AutoRechargeServiceTest` (追記分) |
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
| 補足 | S4 で `app/Support/JobExecution/AttemptOwnershipPreflight` を新設する (非 final。fake が override する前提で `RenderObjectStorage` と同じ作法)。**Manual 側には同じ collaborator を作らない** — 既存 fake のフックで配置を behavioral に赤化できるため、churn になるだけである (AGENTS.md 思考原則 2)。 |
| 実装順序 | S1 → S2 → S3 → S4 → S5 → S6 → S7 (S6 の目録は S2/S3/S4 の再検証点が実在してからでないと green にできない) |


## 実装差分 (git diff)

```diff
diff --git a/app/Enums/Security/ExternalCallKind.php b/app/Enums/Security/ExternalCallKind.php
new file mode 100644
index 0000000..c4e5c34
--- /dev/null
+++ b/app/Enums/Security/ExternalCallKind.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 所有権再検証 (preflight suppression) が守る外部呼び出しの種別。
+ *
+ * Manual ドメイン (例外経由) と Billing ドメイン (structured return) の**双方**が
+ * 同じ語彙を共有するためにここへ置く (`tests/Architecture/JobExecutionDedupInventoryTest.php`
+ * の目録もこの enum を使う。テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ *
+ * ★case を足すとき: 「取り消せない外部副作用を持つか」を基準にする。
+ *   ローカル CPU (ffmpeg) や冪等な読み取り (S3 GET) は本 enum の対象ではない。
+ *
+ * ★ログ event 定数を enum に同居させているのはやや変則だが、代替 (専用の Support クラス新設) は
+ *   「ログ event 定数だけのために専用クラスを作る」ことになり AGENTS.md 思考原則 2
+ *   (今必要なものだけ作る) に反するため採らない。
+ */
+enum ExternalCallKind: string
+{
+    /**
+     * 所有権喪失で**外部送信を抑止した**ときの固定 event 名。
+     *
+     * ログ基盤で頻度を集計し「残余窓 1 が実際にどれだけ開いているか」を測るために固定する。
+     * Manual / Billing の両方がこの 1 箇所を参照する (literal の直書きは
+     * JobExecutionDedupInventoryTest が deny-by-default で検出する)。
+     *
+     * ★この event の**必須キー集合を 7 キーで固定**する
+     *   (event / job_type / job_id / expected_status / actual_status / stage / external_call)。
+     *   集計 schema を揃えるためであり、**PII を含まないドメイン固有キーの追加は可**
+     *   (Billing は `attempt_ulid` を 1 本足す)。必須キーを欠くログを同じ event 名で出さない。
+     */
+    public const string LOG_EVENT = 'job_ownership_lost';
+
+    /**
+     * 所有権喪失**後の後始末** (open invoice の終端等) の固定 event 名。
+     *
+     * 抑止の記録とは schema が違う (expected/actual status も stage も持たない) ため
+     * 別 event にする。集計時は「抑止 → 後始末の成否」の 2 段で追える。
+     */
+    public const string CLEANUP_LOG_EVENT = 'job_ownership_lost_cleanup';
+
+    /** LLM 補完 (Prism 経由)。**provider 側に冪等キーが無い** = 呼んだら取り消せない */
+    case LlmCompletion = 'llm_completion';
+
+    /** オブジェクトストレージへの PUT (レンダ出力の S3 アップロード) */
+    case ObjectStoragePut = 'object_storage_put';
+
+    /** Stripe invoice の作成 (課金の前段。open invoice を残す) */
+    case StripeInvoiceCreate = 'stripe_invoice_create';
+
+    /** Stripe invoice の off-session 支払い (実際に金が動く) */
+    case StripeInvoicePay = 'stripe_invoice_pay';
+}
diff --git a/app/Enums/Security/JobDedupExemption.php b/app/Enums/Security/JobDedupExemption.php
new file mode 100644
index 0000000..2d0abf7
--- /dev/null
+++ b/app/Enums/Security/JobDedupExemption.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 「重複実行の保証を持たないことが正しい」と裁定された理由の分類。
+ *
+ * `tests/Architecture/JobExecutionDedupInventoryTest.php` が deny-by-default で
+ * 「保証側の登録」か「本 enum + 具体的根拠」かを機械強制する
+ * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ *
+ * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
+ *   当てはまる case が無ければ、それは「保証側を作るべきジョブ」である。
+ */
+enum JobDedupExemption: string
+{
+    /**
+     * 重複配信が受容されている送信系 (Mailable / Notification)。
+     *
+     * 適用条件 (**すべて**満たすこと):
+     *  - ドメイン状態を一切書かない (送信のみ)
+     *  - **重複受信時に受信者が誤った操作へ誘導されない**
+     *    (「気にならない」ではなく「二重の支払い操作等を招かない」まで確認する)
+     *  - `$tries` / retry 契約の上で at-least-once を受容済みである
+     *
+     * ★課金関連・失敗通知・セキュリティ通知を「配信系だから」で一括免除しない。
+     *   クラスごとに「何が重複配信されうるか・受信者に何が起きるか・なぜ受容できるか」を書く。
+     */
+    case DuplicateDeliveryAccepted = 'duplicate_delivery_accepted';
+
+    /**
+     * 削除が本質的に冪等で、2 回目の実行が no-op になるジョブ。
+     *
+     * 適用条件: 対象の不在を正常系として扱い、状態も課金も動かさない。
+     */
+    case IdempotentDeletion = 'idempotent_deletion';
+
+    /**
+     * 外部の最新状態を取り込むだけの同期ジョブ (last-writer-wins が正しい)。
+     *
+     * 適用条件: 書き込みが冪等な upsert で、順序が入れ替わっても収束先が同じ。
+     */
+    case ConvergentStateSync = 'convergent_state_sync';
+
+    /**
+     * 起票の重複を DB 制約が拒否するため、ジョブ側に保証を置く必要がないもの。
+     *
+     * 適用条件: 起票先に partial unique index があり、重複起票が例外になる。
+     * ★これは「保証がある」ではなく「保証の所在がジョブの外」であることの記録である。
+     */
+    case GuardedByDownstreamConstraint = 'guarded_by_downstream_constraint';
+}
diff --git a/app/Enums/Security/JobDedupGuarantee.php b/app/Enums/Security/JobDedupGuarantee.php
new file mode 100644
index 0000000..7383a47
--- /dev/null
+++ b/app/Enums/Security/JobDedupGuarantee.php
@@ -0,0 +1,58 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * **結果の一回性**を担う永続状態遷移の機構 (裁定 AG-082 の「保証側」)。
+ *
+ * ★これは preflight (外部呼び出し直前の再検証) とは**別概念**である。
+ *   preflight は「既に失われた所有権を検出して送信を止める」抑止策であり、
+ *   一回性そのものを保証しない。目録では別フィールドで持つ
+ *   (`tests/Support/JobDedup/GuaranteeEntry`)。
+ * ★case を足すとき: 「同じ行に対する 2 回目の確定を DB が構造的に拒否するか」を基準にする。
+ *   「先に検査してから書く」だけのものは保証ではない。
+ */
+enum JobDedupGuarantee: string
+{
+    /**
+     * 条件付き UPDATE (`where(status=…)->update(…)`) で 0 行更新なら後続を行わない。
+     *
+     * 適用条件: 遷移元 status を WHERE に含み、戻り値 (更新行数) で分岐している。
+     */
+    case ConditionalStatusUpdate = 'conditional_status_update';
+
+    /**
+     * 行ロック (`lockForUpdate`) + status guard を同一トランザクション内で行う。
+     *
+     * 適用条件: ロック取得と status 検査と確定の書き込みが**同じ tx** に入っている。
+     */
+    case PessimisticLockWithStatusGuard = 'pessimistic_lock_with_status_guard';
+
+    /**
+     * 予約行の CAS (pending→verifying→completed/released)。
+     *
+     * 適用条件: 各遷移が条件付き UPDATE で、対になる回収経路 (sweeper) が存在する。
+     */
+    case ReservationCas = 'reservation_cas';
+
+    /**
+     * 一意制約 (partial unique index) が 2 回目の**起票**そのものを拒否する。
+     *
+     * 適用条件: DB の制約で重複行が**書けない**こと (アプリ側の事前検査は根拠にならない)。
+     * ★対象は「行の起票」である。効果 (計上・付与) の重複拒否は下の別 case を使う。
+     */
+    case DatabaseUniqueConstraint = 'database_unique_constraint';
+
+    /**
+     * 冪等キーの UNIQUE が、同じキーによる 2 回目の**効果確定**を拒否する。
+     *
+     * 適用条件: 効果 (台帳計上・付与など) の挿入が冪等キー付きで行われ、
+     * DB の UNIQUE により 2 回目が 0 行挿入になり、呼び出し側がそれを検出できること。
+     * ★起票の拒否 (`DatabaseUniqueConstraint`) とは対象が違うため別 case にする
+     *   (AGENTS.md 思考原則 4)。準拠実装:
+     *   `TicketLedgerService::grantAutoRecharge()` の `recharge:{invoiceId}`。
+     */
+    case IdempotentLedgerKeyUniqueConstraint = 'idempotent_ledger_key_unique_constraint';
+}
diff --git a/app/Exceptions/Manual/JobOwnershipLostException.php b/app/Exceptions/Manual/JobOwnershipLostException.php
new file mode 100644
index 0000000..8dc6e45
--- /dev/null
+++ b/app/Exceptions/Manual/JobOwnershipLostException.php
@@ -0,0 +1,81 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Manual;
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Security\ExternalCallKind;
+use RuntimeException;
+
+/**
+ * 外部呼び出しの直前に所有権 (行の主キー, 進行中 status) を再検証して失われていた場合に投げる。
+ *
+ * 利用者は `App\Services\Manual\AnalysisPipeline` と `App\Services\Manual\RenderPipeline` の
+ * 2 つだけで、どちらも Manual ドメインであるため本 namespace に置く
+ * (Billing 側は例外を投げず structured return で閉じるので共用しない)。
+ *
+ * ★これは「異常」ではなく「正常だが観測したい事象」である。`report()` せず、
+ *   固定 event 名つきの `Log::warning` で観測する (無音で握らない)。
+ * ★コンテキストに PII (email / name) と外部 payload を**一切含めない**
+ *   (JobOwnershipLostContextTest が固定する)。
+ */
+final class JobOwnershipLostException extends RuntimeException
+{
+    /**
+     * @param  class-string  $jobType  所有権を失ったジョブ行のモデルクラス
+     * @param  non-empty-string  $stage  既存ドメイン step enum の値
+     *                                   (AnalysisStep / RenderStep。同じ語彙の enum を 2 本作らない)
+     */
+    private function __construct(
+        public readonly string $jobType,
+        public readonly int $jobId,
+        public readonly JobStatus $expectedStatus,
+        public readonly ?JobStatus $actualStatus,
+        public readonly string $stage,
+        public readonly ExternalCallKind $externalCall,
+    ) {
+        parent::__construct(sprintf(
+            '%s#%d: 所有権を失ったため %s を中止しました (期待 %s / 実際 %s)',
+            $jobType,
+            $jobId,
+            $externalCall->value,
+            $expectedStatus->value,
+            // 行が消えている (null) ケースを「missing」として文言に残す
+            $actualStatus instanceof JobStatus ? $actualStatus->value : 'missing',
+        ));
+    }
+
+    /**
+     * @param  class-string  $jobType
+     * @param  non-empty-string  $stage
+     */
+    public static function whileRunning(
+        string $jobType,
+        int $jobId,
+        ?JobStatus $actualStatus,
+        string $stage,
+        ExternalCallKind $externalCall,
+    ): self {
+        return new self($jobType, $jobId, JobStatus::Running, $actualStatus, $stage, $externalCall);
+    }
+
+    /**
+     * 構造化ログ用コンテキスト (PII を含まない)。
+     *
+     * @return array{event: string, job_type: string, job_id: int, expected_status: string,
+     *               actual_status: string|null, stage: string, external_call: string}
+     */
+    public function logContext(): array
+    {
+        return [
+            'event' => ExternalCallKind::LOG_EVENT,
+            'job_type' => $this->jobType,
+            'job_id' => $this->jobId,
+            'expected_status' => $this->expectedStatus->value,
+            'actual_status' => $this->actualStatus?->value,
+            'stage' => $this->stage,
+            'external_call' => $this->externalCall->value,
+        ];
+    }
+}
diff --git a/app/Services/Billing/AutoRechargeService.php b/app/Services/Billing/AutoRechargeService.php
index 0c09624..0fe9038 100644
--- a/app/Services/Billing/AutoRechargeService.php
+++ b/app/Services/Billing/AutoRechargeService.php
@@ -13,6 +13,7 @@
 use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
+use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Billing\CheckoutInProgressException;
 use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
 use App\Models\Billing\BillingCheckoutSession;
@@ -26,6 +27,7 @@
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Support\JobExecution\AttemptOwnershipPreflight;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Cache\LockTimeoutException;
 use Illuminate\Database\QueryException;
@@ -58,18 +60,30 @@
 final class AutoRechargeService
 {
     /**
-     * org lock の TTL。updateSettings (cancelPendingAttempts の terminateInvoice) と
-     * executeAttempt (invoice create/pay) の両方が lock 内で外向き Stripe API を呼ぶため、
-     * Stripe client timeout より十分長く統一する (TTL 失効による直列化の破れを防ぐ)。
-     * block 待機は短いまま (競合時は no-op / リコンサイル再試行)。
+     * org 単位 `Cache::lock` の TTL (秒)。updateSettings (cancelPendingAttempts の
+     * terminateInvoice) と executeAttempt (invoice create/pay) の両方が lock 内で外向き
+     * Stripe API を呼ぶため、Stripe client timeout より十分長く統一する
+     * (TTL 失効による直列化の破れを防ぐ)。block 待機は短いまま
+     * (競合時は no-op / リコンサイル再試行)。
+     *
+     * ★これは**入口の排他**であり、結果の一回性を保証しない (裁定 AG-082)。
+     *   保証は (a) 外部呼び出し直前の preflight、(b) `where status=pending` の条件付き UPDATE、
+     *   (c) Stripe idempotency key が担う。
+     * ★したがって値は「保証を代替できる長さ」ではなく**短い側**に倒す。
+     *   `JobExclusionOrderingInvariantTest` が
+     *   `LOCK_TTL_SECONDS < queue.connections.database.retry_after` を CI 固定する
+     *   (鍵の残留が正当な再実行を封鎖する時間が、キューの再配送間隔を超えない)。
+     *   **可視性が public なのは不変条件の契約としての意図的な公開**である
+     *   (T127 で既定キュー接続が分割されたら、上記テストの比較先を差し替えること)。
      */
-    private const int LOCK_TTL_SECONDS = 180;
+    public const int LOCK_TTL_SECONDS = 180;
 
     public function __construct(
         private readonly TicketLedgerService $tickets,
         private readonly TicketPricingService $pricing,
         private readonly AutoRechargeGatewayInterface $gateway,
         private readonly BillingNotificationDispatcher $notifications,
+        private readonly AttemptOwnershipPreflight $preflight,
     ) {}
 
     // ------------------------------------------------------------------
@@ -544,6 +558,12 @@ private function executeAttemptLocked(Organization $organization, TicketAutoRech
 
         $invoiceId = $attempt->stripe_invoice_id;
         if ($invoiceId === null) {
+            // ★ preflight 1: invoice 作成の直前。org lock は TTL 180 秒で切れうるため
+            //   (lock は best-effort。保証は本再検証と条件付き UPDATE と Stripe 冪等キー)。
+            if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoiceCreate)) {
+                return; // invoice 未作成なので収束は自明 (残す open invoice が無い)
+            }
+
             $invoiceId = $this->gateway->createAutoRechargeInvoice(
                 $organization,
                 $attempt->stripe_price_id,
@@ -551,8 +571,40 @@ private function executeAttemptLocked(Organization $organization, TicketAutoRech
                 $this->metadataFor($organization, $attempt),
                 $keyBase,
             );
+
             // invoice_id の永続化は pay より必ず前 (プロセス死でも迷子 invoice を作らない)。
-            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->save();
+            // ★ **条件付き UPDATE** にする: 素の save() だと停止側が先に canceled 化した
+            //   terminal 行へ invoice_id を後から書き込むことになり、状態機械の例外を作る。
+            //   0 行なら「attempt へ紐付けられなかった invoice」であり、
+            //   DB の値に依存せずローカルの $invoiceId で終端する。
+            $attached = TicketAutoRechargeAttempt::query()
+                ->whereKey($attempt->id)
+                ->where('status', AutoRechargeAttemptStatus::Pending->value)
+                ->update([
+                    'stripe_invoice_id' => $invoiceId,
+                    'updated_at' => CarbonImmutable::now(),
+                ]);
+
+            if ($attached !== 1) {
+                // ★ attach 失敗は **status を問わず**終端する。
+                //   この invoice ID を知っているのは自分だけであり、
+                //   terminal 化させた側は stripe_invoice_id === null を見ているため終端できない。
+                $this->terminateUnattachedInvoice($attempt->refresh(), $invoiceId);
+
+                return;
+            }
+            // in-memory 同期 (再 save しない)
+            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->syncOriginal();
+        }
+
+        // ★ preflight 2: pay の直前。**直前に自前の書き込み (invoice_id の永続化) を挟んだため
+        //   必ずもう一度検証する** (裁定 AG-082: 検証の後に自前の書き込みを挟むと、
+        //   接続断で旧担当が送信できる窓が開く)。
+        //   既存 invoice を再利用する経路 (上の if を通らない場合) でもここが唯一の関門になる。
+        if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoicePay)) {
+            $this->terminateInvoiceAfterOwnershipLost($attempt, $invoiceId);
+
+            return;
         }
 
         $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);
@@ -570,6 +622,89 @@ private function executeAttemptLocked(Organization $organization, TicketAutoRech
         $this->handleChargeFailure($organization, $attempt, $result->failureCode, $result->requiresAction());
     }
 
+    /**
+     * preflight 2 で中断したときの invoice 後始末。
+     *
+     * **canceled のときだけ**終端する:
+     *  - paid  … void できない (付与経路の管轄)
+     *  - failed… `terminateAndFail()` が **`stripe_invoice_id` を DB 経由で見えている状態**で
+     *    終端済み (attach 済みだからこの分岐に来ている)
+     *  - canceled … 停止側の `tryTerminateInvoice()` は `stripe_invoice_id === null` を
+     *    「invoice 未作成」と解釈して素通りするため、こちらの永続化が停止より後だと
+     *    **誰も void しない open invoice が残る**。ここで拾う。
+     *
+     * ★ attach に失敗した invoice は本メソッドではなく `terminateUnattachedInvoice()` の担当
+     *   (あちらは status を問わず終端する)。
+     */
+    private function terminateInvoiceAfterOwnershipLost(
+        TicketAutoRechargeAttempt $attempt,
+        string $invoiceId,
+    ): void {
+        if ($attempt->status !== AutoRechargeAttemptStatus::Canceled) {
+            return; // アーリーリターン
+        }
+
+        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
+    }
+
+    /**
+     * attempt 行へ紐付けられなかった (条件付き UPDATE が 0 行だった) invoice の後始末。
+     *
+     * ★ **status を問わず終端を試みる**。この invoice ID を知っているのは自分だけであり、
+     *   terminal 化させた側は `stripe_invoice_id === null` を見ているため終端できない。
+     *   canceled 限定にすると failed 経路で**誰も終端しない open invoice**が残る。
+     * ★ `paid` の可能性は `CashierAutoRechargeGateway::terminateInvoice()` の状態検査が
+     *   `Assert` で fail-closed に分類する (例外 → `terminated=false` としてログに残る)。
+     */
+    private function terminateUnattachedInvoice(
+        TicketAutoRechargeAttempt $attempt,
+        string $invoiceId,
+    ): void {
+        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
+    }
+
+    /**
+     * invoice の best-effort 終端 + 固定 event 名でのログ (上 2 つの共通部)。
+     *
+     * ★ `$invoiceId` を**引数で受ける**。attempt 行に永続化できなかった invoice も
+     *   終端したいため、DB の値に依存しない。
+     * ★ `tryTerminateInvoice($attempt)` を再利用しない理由: あちらは
+     *   `$attempt->stripe_invoice_id` を読むため「永続化できなかった invoice」を扱えず、
+     *   かつ独自の warning を出すのでログが二重になる。ここは固定 event の 1 行に閉じる。
+     * ★ `CashierAutoRechargeGateway::terminateInvoice()` は Stripe から retrieve して
+     *   void/deleted/404 → 成功扱い、paid → `Assert` で明示的な非成功、draft → delete、
+     *   open/uncollectible → void と**状態検査で冪等化**されている
+     *   (idempotency key より強い — 期限が無い)。
+     * ★ 失敗しても**課金処理へは進まない** (呼び出し側が無条件に return する)。
+     *   残った open invoice は reconcile の母集団外なので、運用契約 (docs/architecture.md) の
+     *   手動収束に委ねる。
+     * ★ **cleanup 専用の event 名**を使う。送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を
+     *   持つ契約であり、キー集合の違うログを同じ event 名に混ぜない。
+     */
+    private function terminateInvoiceBestEffort(
+        TicketAutoRechargeAttempt $attempt,
+        string $invoiceId,
+    ): void {
+        $terminated = true;
+        $error = null;
+        try {
+            $this->gateway->terminateInvoice($invoiceId);
+        } catch (Throwable $exception) {
+            $terminated = false;
+            $error = $exception->getMessage(); // paid 等の「明示的な非成功」もここに落ちる
+        }
+
+        Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
+            'event' => ExternalCallKind::CLEANUP_LOG_EVENT,
+            'job_type' => TicketAutoRechargeAttempt::class,
+            'job_id' => $attempt->id,
+            'attempt_ulid' => $attempt->attempt_ulid,
+            'invoice_id' => $invoiceId,
+            'terminated' => $terminated,
+            'error' => $error,
+        ]);
+    }
+
     /**
      * 課金成功の確定: 冪等付与 + attempt paid 遷移 + failure_count リセット。
      * webhook (invoice.paid) / 同期 pay / リコンサイル (ii) の全経路がここに合流する。
diff --git a/app/Services/Manual/AnalysisPipeline.php b/app/Services/Manual/AnalysisPipeline.php
index f7214f3..a03959a 100644
--- a/app/Services/Manual/AnalysisPipeline.php
+++ b/app/Services/Manual/AnalysisPipeline.php
@@ -11,8 +11,10 @@
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Manual\AnalysisStep;
 use App\Enums\Manual\JobStatus;
+use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Billing\InsufficientTicketsException;
 use App\Exceptions\Manual\AnalysisFailedException;
+use App\Exceptions\Manual\JobOwnershipLostException;
 use App\Exceptions\Manual\LlmOutputInvalidException;
 use App\Models\AnalysisJob;
 use App\Models\Organization;
@@ -106,6 +108,13 @@ public function run(int $analysisJobId): void
                 // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
                 $this->notifications->notifyAnalysisFinished($job->refresh());
             }
+        } catch (JobOwnershipLostException $exception) {
+            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
+            // failJob も通知もチケット release も呼ばない (すべて先着が済ませている)。
+            // report() しない — これは「正常だが観測したい事象」であり、固定 event 名で集計する。
+            Log::warning('解析ジョブの所有権を失ったため外部呼び出しを中止しました', $exception->logContext());
+
+            return;
         } catch (Throwable $exception) {
             report($exception);
             $this->jobs->failJob($job, $this->userMessageFor($exception));
@@ -161,7 +170,15 @@ private function ensureReservation(AnalysisJob $locked, Organization $organizati
         $locked->save();
     }
 
-    /** extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット) */
+    /**
+     * extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット)。
+     *
+     * ★ `SourceDocument::extracted_json` は**条件付き UPDATE にしない** (T131):
+     *   これは write-only の監査スナップショットであって状態機械の一部ではなく、guard には
+     *   job → document の join が要る。failed 行の document に抽出結果が残っても不整合にならない
+     *   (むしろ調査に役立つ)。「終端後の**ジョブ状態・進捗**書き込みの禁止」が対象を
+     *   ジョブ行に限っているのはこのためである。
+     */
     private function runExtractStep(
         AnalysisJob $job,
         SourceDocument $document,
@@ -169,6 +186,7 @@ private function runExtractStep(
         CarbonImmutable $deadline,
     ): ExtractedSopData {
         $extracted = $this->withBoundedRetry(
+            $job,
             $deadline,
             AnalysisStep::Extract,
             fn (): ExtractedSopData => ExtractedSopData::fromLlmText(
@@ -190,6 +208,7 @@ private function runDecomposeStep(
         CarbonImmutable $deadline,
     ): WorkDecompositionData {
         $decomposition = $this->withBoundedRetry(
+            $job,
             $deadline,
             AnalysisStep::Decompose,
             fn (): WorkDecompositionData => WorkDecompositionData::fromLlmText(
@@ -197,10 +216,12 @@ private function runDecomposeStep(
             ),
         );
 
-        $job->result_json = $decomposition->toArray();
-        $job->step = AnalysisStep::Generate;
-        $job->progress = 65;
-        $job->save();
+        // 終端後の自前書き込みを塞ぐ: 進捗と result_json は running のときだけ書く
+        $this->writeProgress($job, [
+            'result_json' => $decomposition->toArray(),
+            'step' => AnalysisStep::Generate->value,
+            'progress' => 65,
+        ]);
 
         return $decomposition;
     }
@@ -212,6 +233,7 @@ private function runGenerateStep(
         CarbonImmutable $deadline,
     ): GeneratedScenarioData {
         $generated = $this->withBoundedRetry(
+            $job,
             $deadline,
             AnalysisStep::Generate,
             fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(
@@ -299,18 +321,30 @@ private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): b
      * 「D + C」という単純な形に閉じている (概念設計 §時間 budget)。
      * 残り時間を timeout に渡す実装へ変えるとこのモデルが壊れる。
      *
+     * ★ preflight suppression (裁定 AG-082 標準形 (2)): **`$attempt()` の直前**で所有権を
+     *   再検証する。ここに 1 箇所置くだけで extract / decompose / generate の 3 段 ×
+     *   全リトライ試行を覆う (挿入点が 1 つ = 新しい段を足しても抜けようがない)。
+     *   deadline 判定 (時計の読み取り) は自前の書き込みではないため、
+     *   preflight と `$attempt()` の間に書き込みは 1 つも無い。
+     *
      * @template T
      *
      * @param  callable(): T  $attempt
      * @return T
      */
-    private function withBoundedRetry(CarbonImmutable $deadline, AnalysisStep $step, callable $attempt): mixed
-    {
+    private function withBoundedRetry(
+        AnalysisJob $job,
+        CarbonImmutable $deadline,
+        AnalysisStep $step,
+        callable $attempt,
+    ): mixed {
         $maxRetries = config()->integer('manual.analysis_llm_max_retries');
         for ($tryCount = 0; ; $tryCount++) {
             if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                 throw AnalysisFailedException::timedOut();
             }
+            // ★外部呼び出しの直前 (これより後に自前の書き込みを挟まない)
+            $this->assertStillOwned($job, $step);
             try {
                 return $attempt();
             } catch (Throwable $exception) {
@@ -385,14 +419,61 @@ private function extractHttpStatus(Throwable $exception): ?int
     }
 
     /**
-     * step/progress の表示用更新 (tx 不要の単発 update。状態機械は status のみが真実源。
-     * updated_at の更新が stale 判定の「最終 step 更新時刻」を兼ねる)。
+     * 所有権の再検証 (preflight suppression)。
+     *
+     * 所有権 = (行の主キー, `running`)。`startJob()` の `lockForUpdate + status === Queued`
+     * guard により 1 行が `running` になるのは高々 1 回で、再実行は新しい行を起票するため、
+     * `status` の再読込がそのまま所有権の再検証になる (claim token を持たない根拠は
+     * docs/architecture.md §ジョブの重複実行と結果の一回性)。
+     *
+     * 行が消えている (null) 場合も所有権喪失として扱う (deny-by-default)。
+     *
+     * @throws JobOwnershipLostException
+     */
+    private function assertStillOwned(AnalysisJob $job, AnalysisStep $step): void
+    {
+        $current = AnalysisJob::query()->whereKey($job->getKey())->first();
+        if ($current !== null && $current->status === JobStatus::Running) {
+            return; // アーリーリターン (正常系)
+        }
+
+        throw JobOwnershipLostException::whileRunning(
+            jobType: AnalysisJob::class,
+            jobId: $job->id,
+            actualStatus: $current?->status,
+            stage: $step->value,
+            externalCall: ExternalCallKind::LlmCompletion,
+        );
+    }
+
+    /**
+     * ジョブ行の進捗系列の更新 (status は書かない)。
+     *
+     * ★ **条件付き UPDATE (`where status=running`)** にする理由:
+     *   preflight で「terminal 化後は外部を呼ばない」ようにした以上、
+     *   「terminal 化後に自前の DB を書く」経路も同時に塞ぐ。素の `save()` だと
+     *   stale 回復 cron が failed にした行へ step/progress/updated_at を書き戻し、
+     *   「failed なのに progress=65」という不整合を作る。
+     * ★ `Builder::update()` は `updated_at` を自動付与する (stale 判定の
+     *   「最終 step 更新時刻」という意味は従来どおり。ただし terminal 行では動かない)。
+     * ★ 状態機械は status のみが真実源であり、本メソッドは status を書かない。
+     *   **array shape で書ける列を閉じている** — `status` 等の保護列を渡せないことを
+     *   PHPStan level 10 が静的に弾く。
+     *
+     * @param  array{step: string, progress: int, result_json?: array<string, mixed>}  $attributes
      */
+    private function writeProgress(AnalysisJob $job, array $attributes): void
+    {
+        AnalysisJob::query()
+            ->whereKey($job->getKey())
+            ->where('status', JobStatus::Running->value)
+            ->update($attributes);
+    }
+
+    /** step/progress の表示用更新 (条件付き UPDATE 経路へ寄せる)。 */
     private function updateProgress(AnalysisJob $job, AnalysisStep $step, int $progress): void
     {
-        $job->step = $step;
-        $job->progress = $progress;
-        $job->save();
+        $this->writeProgress($job, ['step' => $step->value, 'progress' => $progress]);
     }
 
     /** job → manual → project の導出 (payload 不信任。DB から relation 経由で再解決) */
diff --git a/app/Services/Manual/RenderPipeline.php b/app/Services/Manual/RenderPipeline.php
index cefbaad..221036a 100644
--- a/app/Services/Manual/RenderPipeline.php
+++ b/app/Services/Manual/RenderPipeline.php
@@ -16,7 +16,9 @@
 use App\Enums\Manual\RenderStep;
 use App\Enums\Manual\TakeStatus;
 use App\Enums\Manual\VideoManualStatus;
+use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Billing\InsufficientTicketsException;
+use App\Exceptions\Manual\JobOwnershipLostException;
 use App\Exceptions\Manual\RenderScenarioChangedException;
 use App\Jobs\Manual\DeleteRenderOutputsJob;
 use App\Models\Cut;
@@ -30,6 +32,7 @@
 use App\Services\Render\VideoComposer;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Log;
 use LogicException;
 use Throwable;
 use Webmozart\Assert\Assert;
@@ -93,6 +96,13 @@ public function run(int $renderJobId): void
             );
             $this->updateProgress($job, RenderStep::Concat, 90);
 
+            // ★ preflight suppression (裁定 AG-082 標準形 (2)): S3 PUT の直前で所有権を再検証する。
+            //   updateProgress() という**自前の書き込みの後**に置くことが要点
+            //   (書き込みの前に検証すると、書き込み中の接続断で旧担当が PUT できる窓が開く)。
+            //   ffmpeg compose / S3 GET の前には置かない — ローカル CPU と冪等な読み取りであり、
+            //   取り消せない外部副作用を持たないため (docs/architecture.md の残余窓 3)。
+            $this->assertStillOwned($job, RenderStep::Concat);
+
             // upload → finalize (terminal tx)
             $this->storage->upload($composed->localPath, $manifest->outputKey);
             $uploadedKey = $manifest->outputKey;
@@ -110,6 +120,12 @@ public function run(int $renderJobId): void
                     $this->notifications->notifyRenderFinished($job);
                 }
             }
+        } catch (JobOwnershipLostException $exception) {
+            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
+            // failJob も通知もチケット release も呼ばない。$uploadedKey は null のままなので
+            // finally の後始末は work dir の削除だけを行う (孤児オブジェクトを作らずに降りる)。
+            // return ではなく catch で受けるのは、片付け経路 (finally) を 1 本に保つため。
+            Log::warning('レンダジョブの所有権を失ったため出力アップロードを中止しました', $exception->logContext());
         } catch (Throwable $exception) {
             report($exception);
             $this->jobs->failJob($job, $this->errorCodeFor($exception), $this->userMessageFor($exception));
@@ -380,14 +396,45 @@ private function onClipComposed(RenderJob $job, int $composedClips, int $totalCl
     }
 
     /**
-     * step/progress の表示用更新 (tx 不要の単発 update。状態機械は status のみが真実源。
-     * updated_at の更新が stale 判定の「最終 step 更新時刻」を兼ねる)。
+     * 所有権の再検証 (preflight suppression)。AnalysisPipeline と同型
+     * (§10.8 方針: 共通抽象化しない。個別実装を見本に合わせる)。
+     *
+     * 所有権 = (行の主キー, `running`)。行が消えている (null) 場合も所有権喪失として扱う
+     * (deny-by-default)。
+     *
+     * @throws JobOwnershipLostException
+     */
+    private function assertStillOwned(RenderJob $job, RenderStep $step): void
+    {
+        $current = RenderJob::query()->whereKey($job->getKey())->first();
+        if ($current !== null && $current->status === JobStatus::Running) {
+            return; // アーリーリターン (正常系)
+        }
+
+        throw JobOwnershipLostException::whileRunning(
+            jobType: RenderJob::class,
+            jobId: $job->id,
+            actualStatus: $current?->status,
+            stage: $step->value,
+            externalCall: ExternalCallKind::ObjectStoragePut,
+        );
+    }
+
+    /**
+     * step/progress の表示用更新 (AnalysisPipeline::writeProgress と同型)。
+     *
+     * ★ **条件付き UPDATE (`where status=running`)**。compose は最大 25 分走り、
+     *   `onClipComposed()` から高頻度に呼ばれるため、terminal 化後の書き戻しが
+     *   最も起きやすい経路である (「failed なのに progress=62」を作らない)。
+     * ★ `Builder::update()` は `updated_at` を自動付与する (stale 判定の
+     *   「最終 step 更新時刻」という意味は従来どおり。ただし terminal 行では動かない)。
      */
     private function updateProgress(RenderJob $job, RenderStep $step, int $progress): void
     {
-        $job->step = $step;
-        $job->progress = $progress;
-        $job->save();
+        RenderJob::query()
+            ->whereKey($job->getKey())
+            ->where('status', JobStatus::Running->value)
+            ->update(['step' => $step->value, 'progress' => $progress]);
     }
 
     /** job → manual → project の導出 (payload 不信任。DB から relation 経由で再解決) */
diff --git a/app/Support/JobExecution/AttemptOwnershipPreflight.php b/app/Support/JobExecution/AttemptOwnershipPreflight.php
new file mode 100644
index 0000000..8011998
--- /dev/null
+++ b/app/Support/JobExecution/AttemptOwnershipPreflight.php
@@ -0,0 +1,63 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\JobExecution;
+
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Enums\Security\ExternalCallKind;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use Illuminate\Support\Facades\Log;
+
+/**
+ * auto-recharge attempt の所有権再検証 (preflight suppression。裁定 AG-082 標準形 (2))。
+ *
+ * ★ **なぜ独立クラスなのか**:
+ *   Manual の 2 パイプラインは「外部呼び出しが複数回連続する」構造なので、
+ *   既存 fake のフック (`ThrowingPromptFake::$onAttempt` / `FakeRenderComposer::$duringCompose`)
+ *   で **preflight の配置そのものを behavioral に赤化できる**。
+ *   一方 Billing は「冒頭の Pending guard → create → attach → pay」という直列で、
+ *   guard と各 preflight の**間に注入点が 1 つも無い**ため、
+ *   preflight 呼び出しを削除しても既存 fake では赤化しない。
+ *   そこで preflight だけを差し替え可能な collaborator として切り出す
+ *   (本番コードにテスト専用 closure を足さないため)。
+ *   Manual 側は同じ理由が無いので**この形にしない** (利益の無い churn を作らない。
+ *   AGENTS.md 思考原則 2)。
+ *
+ * ★ **非 final** にしてあるのはテスト側の競合注入シームが override するためである
+ *   (`App\Services\Render\RenderObjectStorage` と同じ作法。interface は新設しない)。
+ *   ただしシームは**判定を差し替えない** — checkpoint 直前に attempt 行を terminal 化して
+ *   `parent::stillPending()` へ委譲するだけである。
+ *   したがって本メソッドの refresh / status 判定 / ログはテストでも常に本実装が走る。
+ */
+class AttemptOwnershipPreflight
+{
+    /**
+     * Stripe 呼び出しの直前に attempt の所有権 (= pending) を再検証する。
+     *
+     * @return bool 送信してよいか (false = 所有権喪失 → 呼び出し側が中断する)
+     */
+    public function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
+    {
+        $attempt->refresh();
+        if ($attempt->status === AutoRechargeAttemptStatus::Pending) {
+            return true; // アーリーリターン (正常系)
+        }
+
+        // Manual ドメインと**同じ必須キー集合**で観測する (集計の語彙を 1 本に保つ。
+        // JobOwnershipLostException::logContext() と必須 7 キーが一致する。
+        // Billing 固有の追加キーは PII-free な attempt_ulid の 1 本だけ)。
+        Log::warning('auto-recharge: 所有権を失ったため Stripe 呼び出しを中止しました', [
+            'event' => ExternalCallKind::LOG_EVENT,
+            'job_type' => TicketAutoRechargeAttempt::class,
+            'job_id' => $attempt->id,
+            'expected_status' => AutoRechargeAttemptStatus::Pending->value,
+            'actual_status' => $attempt->status->value,
+            'stage' => 'execute_attempt',
+            'external_call' => $call->value,
+            'attempt_ulid' => $attempt->attempt_ulid,
+        ]);
+
+        return false;
+    }
+}
diff --git a/tests/Architecture/JobExclusionOrderingInvariantTest.php b/tests/Architecture/JobExclusionOrderingInvariantTest.php
new file mode 100644
index 0000000..9b1232a
--- /dev/null
+++ b/tests/Architecture/JobExclusionOrderingInvariantTest.php
@@ -0,0 +1,55 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
+use App\Services\Billing\AutoRechargeService;
+
+/*
+ * 入口の排他 (Cache::lock TTL / ShouldBeUnique の uniqueFor) の**序列**を CI 固定する。
+ *
+ * 裁定 AG-082: 入口の排他は best-effort であり、結果の一回性を保証しない。
+ * したがって「保証を代替できるほど長く」してはならない — 鍵が残留すると、
+ * 正当な再実行 (§10.8-1「再実行は analyze/render 再トリガーのみ」) を最大 TTL 秒ブロックする。
+ *
+ * ★比較先を「マジックナンバー」ではなく **その接続の retry_after** にしているのが要点。
+ *   鍵の残留がキューの再配送間隔を超えないことを保証すれば、封鎖時間が構造的に有界化される。
+ *
+ * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
+ */
+
+test('入口の排他: auto-recharge の org lock TTL は既定接続の retry_after を下回る', function (): void {
+    $retryAfter = config()->integer('queue.connections.database.retry_after');
+
+    expect(AutoRechargeService::LOCK_TTL_SECONDS)->toBeLessThan(
+        $retryAfter,
+        'org lock TTL がキューの再配送間隔以上です。ゴーストロックが同一ジョブの再配送より'
+        .'長く残ると、正当な再実行を封鎖します。TTL は保証を担わないため短い側に倒すこと。',
+    );
+});
+
+test('入口の排他: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回る', function (): void {
+    $retryAfter = config()->integer('queue.connections.database.retry_after');
+
+    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeLessThan(
+        $retryAfter,
+        'uniqueFor がキューの再配送間隔以上です。ShouldBeUnique の鍵は失敗や timeout で'
+        .'解放されないことがあるため (Laravel 公式)、残留時間を再配送間隔の内側に収めること。',
+    );
+});
+
+test('入口の排他: uniqueFor は正の値である (実質無効化の検出)', function (): void {
+    // 0 / 負値は「鍵を持たない」に等しく、ShouldBeUnique の宣言が静かに空洞化する
+    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeGreaterThan(0);
+});
+
+test('入口の排他: 比較先の前提 — auto-recharge の 2 ジョブは既定接続で動く', function (): void {
+    // ★ 接続 pin (T127: 既定キュー接続の分割) が入ると `database.retry_after` との比較が
+    //   意味を失う。前提が崩れた瞬間に赤くする。
+    //   ★ 他テストファイルのグローバル定数 (QUEUED_JOB_LEASE_INVENTORY) は参照しない —
+    //     Pest の --parallel はファイル単位でプロセスを分けるため未定義になりうる。
+    //     ジョブ実体から直接読めば単体で成立する。
+    expect((new AutoRechargeTriggerJob(1))->connection)->toBeNull();
+    expect((new ExecuteAutoRechargeAttemptJob(1))->connection)->toBeNull();
+});
diff --git a/tests/Architecture/JobExecutionDedupInventoryTest.php b/tests/Architecture/JobExecutionDedupInventoryTest.php
new file mode 100644
index 0000000..9d47219
--- /dev/null
+++ b/tests/Architecture/JobExecutionDedupInventoryTest.php
@@ -0,0 +1,531 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\ExternalCallKind;
+use App\Enums\Security\JobDedupExemption;
+use App\Enums\Security\JobDedupGuarantee;
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
+use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
+use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Jobs\Billing\SyncBillingCustomerDetails;
+use App\Jobs\Capture\DeleteTakeObjectsJob;
+use App\Jobs\Manual\DeleteRenderOutputsJob;
+use App\Jobs\Manual\RunManualAnalysis;
+use App\Jobs\Manual\RunManualRender;
+use App\Mail\InquiryAcknowledgementMail;
+use App\Mail\InquiryReceivedMail;
+use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
+use App\Notifications\Billing\AutoRechargeDisabledNotification;
+use App\Notifications\Billing\AutoRechargeEnabledNotification;
+use App\Notifications\Billing\AutoRechargeFailedNotification;
+use App\Notifications\Billing\PaymentFailedNotification;
+use App\Notifications\Billing\RenewalReminderNotification;
+use App\Services\Manual\AnalysisPipeline;
+use App\Services\Manual\RenderPipeline;
+use App\Support\JobExecution\AttemptOwnershipPreflight;
+use Tests\Support\JobDedup\ExemptionEntry;
+use Tests\Support\JobDedup\GuaranteeEntry;
+use Tests\Support\JobDedup\NoExternalCall;
+use Tests\Support\JobDedup\PreflightCheckpoint;
+use Tests\Support\JobDedup\PreflightControlFlow;
+use Tests\Support\JobDedup\PreflightRequirement;
+use Tests\Support\QueuedJobPopulation;
+use Webmozart\Assert\Assert;
+
+/*
+ * 裁定 AG-082「入口の排他 / 結果の一回性」の aicue 実装を deny-by-default で固定する。
+ *
+ * キューに載る全クラス (ShouldQueue 実装) は、次のいずれかに**必ず**分類される:
+ *   - 保証側: JobDedupGuarantee (永続状態遷移の機構) + PreflightRequirement + 30 文字以上の根拠
+ *   - 免除:   JobDedupExemption + 30 文字以上の根拠
+ * 未分類は fail (新しいジョブを足したら必ずここへ登録する)。
+ *
+ * ★母集団は QueuedJobLeaseInventoryTest と**同一の実装** (Tests\Support\QueuedJobPopulation)
+ *   を使う。2 実装に分けると片方だけ更新される drift が起きるため。
+ *
+ * ★ **この gate が保証するもの / しないもの**:
+ *   - 保証する: 母集団の全クラスが分類されている / 期待する外部呼び出し種別と checkpoint 登録の
+ *     **集合一致** / 再検証点の実在と制御方式に一致する戻り型 / 根拠 30 文字以上 / 免除 cap /
+ *     `PreflightRequirement` 実装が 2 種に閉じている / 固定 event literal が 1 箇所に閉じている。
+ *   - **保証しない**: preflight が**外部呼び出しの直前に置かれている**こと
+ *     (Reflection では検査できない = 名前だけ存在する空メソッドでも green になる。
+ *      配置の保証は Feature テスト = AnalysisPipelineTest / RenderPipelineTest /
+ *      AutoRechargeServiceTest が「所有権喪失時に fake が 1 回も呼ばれない」ことで担う)。
+ *     期待値 map と目録を**同時に**消す変更 (宣言的 gate の性質。1 箇所の削除では通らない
+ *     = レビューで必ず 2 箇所の差分が見えることが目的)。
+ *
+ * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
+ */
+
+/** @return array<class-string, GuaranteeEntry> */
+function jobDedupGuarantees(): array
+{
+    return [
+        RunManualAnalysis::class => new GuaranteeEntry(
+            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
+            preflights: [new PreflightCheckpoint(
+                AnalysisPipeline::class, 'assertStillOwned',
+                ExternalCallKind::LlmCompletion, PreflightControlFlow::ThrowsOnLoss,
+            )],
+            rationale: 'startJob が lockForUpdate + status===queued で running へ遷移させ、'
+                .'finalize が同一 tx で materialize + tickets->commit + succeeded を原子化する。'
+                .'LLM は冪等キーを持たないため、各呼び出しの直前に preflight を置く。',
+        ),
+        RunManualRender::class => new GuaranteeEntry(
+            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
+            preflights: [new PreflightCheckpoint(
+                RenderPipeline::class, 'assertStillOwned',
+                ExternalCallKind::ObjectStoragePut, PreflightControlFlow::ThrowsOnLoss,
+            )],
+            rationale: 'startJob / finalize が AnalysisPipeline と同型。S3 PUT は取り消せない'
+                .'外部副作用なので、updateProgress の後・upload の直前に preflight を置く。',
+        ),
+        ExecuteAutoRechargeAttemptJob::class => new GuaranteeEntry(
+            // ★軸の違う 2 本の保証を**両方**登録する
+            mechanisms: [
+                // ★台帳の冪等キー UNIQUE は「起票の拒否」ではなく「効果確定の拒否」なので
+                //   DatabaseUniqueConstraint とは別 case
+                JobDedupGuarantee::IdempotentLedgerKeyUniqueConstraint, // 付与の一回性 (invoice 単位)
+                JobDedupGuarantee::ConditionalStatusUpdate,             // attempt 遷移の一回性 (attempt 単位)
+            ],
+            // ★外部呼び出しは 2 つある。両方を登録する
+            preflights: [
+                new PreflightCheckpoint(
+                    AttemptOwnershipPreflight::class, 'stillPending',
+                    ExternalCallKind::StripeInvoiceCreate, PreflightControlFlow::ReturnsBoolean,
+                ),
+                new PreflightCheckpoint(
+                    AttemptOwnershipPreflight::class, 'stillPending',
+                    ExternalCallKind::StripeInvoicePay, PreflightControlFlow::ReturnsBoolean,
+                ),
+            ],
+            rationale: '冪等キーが 2 本ある: 付与の一回性は台帳の recharge:{invoiceId} UNIQUE '
+                .'(invoice 単位。付与は attempt 遷移より先に走るが、この UNIQUE が二重付与を拒否する)、'
+                .'attempt 遷移の一回性は where status=pending の条件付き UPDATE (attempt 単位)。'
+                .'org lock (TTL 180s) は best-effort で保証を担わない。',
+        ),
+        HandleAutoRechargeChargeFailureJob::class => new GuaranteeEntry(
+            mechanisms: [
+                JobDedupGuarantee::IdempotentLedgerKeyUniqueConstraint, // 順序逆転時の付与 (recordSuccessfulCharge)
+                JobDedupGuarantee::ConditionalStatusUpdate,             // failure_code 記録 / terminal 遷移
+            ],
+            preflights: [new NoExternalCall(
+                'preflight を要する (取り消せない) 外部副作用を持たない。retrieveInvoiceState は'
+                .'冪等な読み取りで、terminateInvoice は Stripe の状態検査 (void/deleted/404 は成功扱い、'
+                .'paid は明示的な非成功) で冪等化された**所有権喪失後の後始末**である。'
+                .'課金そのものを起こす経路は ExecuteAutoRechargeAttemptJob 側にしかない。',
+            )],
+            rationale: '永続化はすべて AutoRechargeService へ委譲され、'
+                .'recordSuccessfulCharge の付与は recharge:{invoiceId} UNIQUE、'
+                .'handleChargeFailure / transitionToTerminal は where status=pending の'
+                .'条件付き UPDATE で 1 attempt = 1 遷移に閉じる。',
+        ),
+    ];
+}
+
+/**
+ * ジョブごとに **preflight を要求する外部呼び出しの種別** (期待値の正本)。
+ *
+ * ★ 目録 (`jobDedupGuarantees()`) とは**独立した宣言**である。
+ *   目録の中だけで閉じた検査 (非空 / 重複なし / 実在) は「登録漏れ」という**不在**を
+ *   検出できない — checkpoint を 1 件削っても残りが要件を満たしてしまう。
+ *   期待集合との一致を要求すれば、削除は必ず赤になる。
+ *
+ * ★ **この検査が保証しないこと**: 本 map と目録の**両方**を同時に消せば green のままになる。
+ *   これは宣言的 gate の性質であり、目的は「1 箇所の削除では通らない = レビューで必ず
+ *   2 箇所の差分が見える」ことである。
+ *   より強い形 (サービスのソースを走査し、外部呼び出しの実在から期待集合を導出する) は、
+ *   **preflight を意図的に持たない外部呼び出し** (所有権喪失**後**の後始末である
+ *   `terminateInvoice`) を別分類する必要があり複雑さが跳ねるため今回は採らない
+ *   (AGENTS.md 思考原則 2)。
+ *
+ * ★ 空リスト = preflight を要する外部呼び出しを持たない (`preflights` は `NoExternalCall`
+ *   ちょうど 1 件)。
+ *
+ * @return array<class-string, list<ExternalCallKind>>
+ */
+function jobDedupRequiredExternalCalls(): array
+{
+    return [
+        RunManualAnalysis::class => [ExternalCallKind::LlmCompletion],
+        RunManualRender::class => [ExternalCallKind::ObjectStoragePut],
+        ExecuteAutoRechargeAttemptJob::class => [
+            ExternalCallKind::StripeInvoiceCreate,
+            ExternalCallKind::StripeInvoicePay,
+        ],
+        HandleAutoRechargeChargeFailureJob::class => [],
+    ];
+}
+
+/** @return array<class-string, ExemptionEntry> */
+function jobDedupExemptions(): array
+{
+    return [
+        AutoRechargeTriggerJob::class => new ExemptionEntry(
+            JobDedupExemption::GuardedByDownstreamConstraint,
+            '起票先の tar_attempts_org_pending_unique (partial unique) が「org に pending は 1 つ」を'
+            .'DB で拒否するため、重複配送は maybeCreateAttempt の pending 検査か unique violation で'
+            .'no-op に収束する。ジョブ自身は課金も状態確定も行わない。',
+        ),
+        ReuseSubscriptionPaymentMethodJob::class => new ExemptionEntry(
+            JobDedupExemption::ConvergentStateSync,
+            'サブスク決済カードの流用は「default PM を同じ値に設定して snapshot を書く」冪等な'
+            .'upsert であり、何度実行しても収束先が同じ。金銭は動かず、適格性 (autoEnableEligible) は'
+            .'org lock 内で fail-closed に再評価される。',
+        ),
+        SetDefaultPaymentMethodJob::class => new ExemptionEntry(
+            JobDedupExemption::ConvergentStateSync,
+            'setup 完了後の default PM 設定と PM snapshot 更新は同じ値への冪等な upsert で、'
+            .'再実行しても収束先が変わらない。自動有効化は org lock + lockForUpdate 下の'
+            .'同一 TX で確定し、金銭は動かさない。',
+        ),
+        SyncBillingCustomerDetails::class => new ExemptionEntry(
+            JobDedupExemption::ConvergentStateSync,
+            'Organization の name を Stripe customer へ写すだけの片方向同期で、'
+            .'書き込みは冪等な upsert。実行順が入れ替わっても最終的な収束先は'
+            .'「最後に読んだ組織名」で同じになる (last-writer-wins が正しい)。',
+        ),
+        DeleteTakeObjectsJob::class => new ExemptionEntry(
+            JobDedupExemption::IdempotentDeletion,
+            'payload は S3 キーの list のみで、存在しないキーの削除は no-op。'
+            .'ドメイン状態も課金も動かさないため、2 回目の実行は観測可能な差分を生まない。',
+        ),
+        DeleteRenderOutputsJob::class => new ExemptionEntry(
+            JobDedupExemption::IdempotentDeletion,
+            'output_path NULL / 最新 succeeded 自身 / prefix 不一致はすべて no-op で、'
+            .'S3 削除は存在しないキーに対して冪等。NULL 化は検証時の値と一致する行のみを'
+            .'更新する CAS なので、再実行で最新世代を誤って壊さない。',
+        ),
+        InquiryAcknowledgementMail::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            'お問い合わせ受付の自動返信メール。ドメイン状態を一切書かず、重複受信しても'
+            .'「受け付けました」が 2 通届くだけで受信者を誤った操作へ誘導しない'
+            .'(支払い・承認などの行動を要求しない)。',
+        ),
+        InquiryReceivedMail::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            '運営宛のお問い合わせ通知メール。ドメイン状態を書かず、重複受信しても'
+            .'運営が同一問い合わせを 2 度見るだけで、利用者側に影響も金銭的操作も生じない。',
+        ),
+        AutoRechargeActionRequiredNotification::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            'SCA (カード認証) の復旧導線通知。重複受信しても遷移先は同一 invoice の'
+            .'hosted_invoice_url で、認証済みなら Stripe 側が二重課金しない。'
+            .'追加の支払い操作を新規に発生させないため at-least-once を受容する。',
+        ),
+        AutoRechargeDisabledNotification::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            '連続失敗によるオートリチャージ自動停止のお知らせ。状態は既に確定済みで'
+            .'通知は事後報告にすぎず、重複受信しても受信者が行う操作 (カード更新) は冪等。',
+        ),
+        AutoRechargeEnabledNotification::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            'オートリチャージ自動有効化の事後通知。設定は既に確定済みで、'
+            .'重複受信しても課金は発生せず、受信者に求める行動もない (確認のみ)。',
+        ),
+        AutoRechargeFailedNotification::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            'オートリチャージ失敗のお知らせ。attempt は既に failed で確定しており、'
+            .'重複受信しても案内先は請求ページのカード更新導線のみで二重の支払い操作を招かない。',
+        ),
+        PaymentFailedNotification::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            'サブスク決済失敗のお知らせ。支払い自体は Stripe 側の請求書で一意に管理され、'
+            .'重複受信しても同じ請求書へ誘導するだけなので二重支払いにはならない。',
+        ),
+        RenewalReminderNotification::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            '契約更新のリマインダ。ドメイン状態を書かず、重複受信しても案内内容が同一で'
+            .'受信者に新たな支払い操作を発生させない (更新は Stripe の自動請求が行う)。',
+        ),
+    ];
+}
+
+/**
+ * 免除件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。
+ *
+ * 「以下」ではなく「一致」で固定するのは、増やすときも減らすときも必ずこの数字を
+ * 書き換えさせるためである (ModelDirectFetchInvariantTest と同じ作法)。
+ */
+function jobDedupExemptionCap(): int
+{
+    return 14;
+}
+
+/**
+ * case 別の件数 (分類の偏り検出)。**array_sum で全体 cap を導出しない**
+ * (全体 cap と case 別を独立に書かせることで、片方だけの書き換えを差分に必ず現す)。
+ *
+ * @return array<string, int>
+ */
+function jobDedupExemptionCapByCase(): array
+{
+    return [
+        JobDedupExemption::DuplicateDeliveryAccepted->value => 8,
+        JobDedupExemption::IdempotentDeletion->value => 2,
+        JobDedupExemption::ConvergentStateSync->value => 3,
+        JobDedupExemption::GuardedByDownstreamConstraint->value => 1,
+    ];
+}
+
+/**
+ * tests/Support/JobDedup/ 配下の PHP ファイル絶対パス一覧。
+ *
+ * @return list<string>
+ */
+function jobDedupSupportPhpFiles(): array
+{
+    $paths = glob(base_path('tests/Support/JobDedup').DIRECTORY_SEPARATOR.'*.php');
+    Assert::isArray($paths, 'tests/Support/JobDedup を走査できません');
+    sort($paths);
+
+    return array_values($paths);
+}
+
+/** tests/Support/JobDedup 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
+function jobDedupSupportClassNameForPath(string $path): string
+{
+    return 'Tests\\Support\\JobDedup\\'.basename($path, '.php');
+}
+
+test('キューに載る全クラスが保証側 or 免除に分類されている (未分類は fail)', function (): void {
+    $scanned = QueuedJobPopulation::shouldQueueClasses();
+    $classified = array_merge(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
+    sort($classified);
+
+    $missing = array_values(array_diff($scanned, $classified));
+    $stale = array_values(array_diff($classified, $scanned));
+
+    expect($missing)->toBe([], '未分類の ShouldQueue 実装がある: '.implode(', ', $missing));
+    expect($stale)->toBe([], '目録に実在しないクラスが残っている: '.implode(', ', $stale));
+});
+
+test('母集団の走査が縮んでいない (Job / Mailable / Notification の 3 系統が入る)', function (): void {
+    $scanned = QueuedJobPopulation::shouldQueueClasses();
+
+    expect($scanned)->toContain(RunManualAnalysis::class);
+    expect($scanned)->toContain(InquiryReceivedMail::class);
+    expect($scanned)->toContain(PaymentFailedNotification::class);
+});
+
+test('保証側と免除は排他 (同じクラスが両方に居ない)', function (): void {
+    $both = array_intersect(array_keys(jobDedupGuarantees()), array_keys(jobDedupExemptions()));
+
+    expect(array_values($both))->toBe([]);
+});
+
+/*
+ * ★ 「QUEUED_JOB_LEASE_INVENTORY と目録のキー集合が一致する」という直接検査は**置かない**。
+ *   (a) 両 gate が同じ `QueuedJobPopulation::shouldQueueClasses()` に対して
+ *       それぞれ対称差 = 空を要求するため、両方 green なら一致は必然 (推移律)。
+ *   (b) 他テストファイルのグローバル定数を参照すると、Pest の --parallel が
+ *       ファイル単位でプロセスを分けたとき未定義になりうる。
+ *   drift の構造的な防止は「母集団の走査実装を 1 本にしたこと」で達成されている。
+ */
+
+test('preflight の再検証点が実在し、登録された制御方式に一致する戻り型を持つ (※配置までは検査しない)', function (): void {
+    // ★ この gate が固定できるのは**再検証点の実在と戻り型まで**である。
+    //   「外部呼び出しの直前で呼ばれていること」は Reflection では検査できない
+    //   (名前だけ存在する空メソッドでも green になる)。
+    //   配置の保証は Feature テスト (所有権喪失時に LLM / S3 / Stripe fake が
+    //   1 回も呼ばれないこと) の担当である。
+    foreach (jobDedupGuarantees() as $class => $entry) {
+        foreach ($entry->preflights as $preflight) {
+            if (! $preflight instanceof PreflightCheckpoint) {
+                continue;
+            }
+
+            $reflection = new ReflectionClass($preflight->verifierClass);
+            expect($reflection->hasMethod($preflight->verifierMethod))->toBeTrue(
+                "{$class}: preflight 再検証点 {$preflight->verifierClass}::{$preflight->verifierMethod} が実在しません",
+            );
+
+            // ★ Manual (例外で中断 = void) と Billing (structured return = bool) を統合しないため、
+            //   目録が宣言した PreflightControlFlow と実際の戻り型の一致を検査する。
+            $expected = $preflight->expectedReturnType();
+            $returnType = $reflection->getMethod($preflight->verifierMethod)->getReturnType();
+            expect($returnType instanceof ReflectionNamedType && $returnType->getName() === $expected)->toBeTrue(
+                "{$class}: preflight 再検証点の戻り型が目録の制御方式 ({$expected}) と一致しません",
+            );
+        }
+    }
+});
+
+test('保証機構は 1 つ以上・重複なしで登録されている', function (): void {
+    foreach (jobDedupGuarantees() as $class => $entry) {
+        expect($entry->mechanisms)->not->toBeEmpty("{$class}: 保証機構が空です");
+
+        // ★重複「値そのもの」を出す (順序依存の比較より失敗メッセージが読みやすい)
+        $values = array_map(static fn (JobDedupGuarantee $m): string => $m->value, $entry->mechanisms);
+        $duplicates = array_values(array_diff_assoc($values, array_unique($values)));
+        expect($duplicates)->toBe([], "{$class}: 保証機構が重複しています: ".implode(', ', $duplicates));
+    }
+});
+
+test('期待する外部呼び出し種別が全ジョブ分宣言されている (期待値の書き忘れ検出)', function (): void {
+    $guaranteed = array_keys(jobDedupGuarantees());
+    $required = array_keys(jobDedupRequiredExternalCalls());
+    sort($guaranteed);
+    sort($required);
+
+    expect($required)->toBe(
+        $guaranteed,
+        'jobDedupRequiredExternalCalls() は jobDedupGuarantees() の全クラスを列挙すること',
+    );
+});
+
+test('期待する外部呼び出し種別に重複がない', function (): void {
+    foreach (jobDedupRequiredExternalCalls() as $class => $kinds) {
+        $values = array_map(static fn (ExternalCallKind $k): string => $k->value, $kinds);
+        $duplicates = array_values(array_diff_assoc($values, array_unique($values)));
+        expect($duplicates)->toBe([], "{$class}: 期待種別が重複しています: ".implode(', ', $duplicates));
+    }
+});
+
+test('登録済み checkpoint の種別集合が期待集合と一致する (登録漏れ / 余剰の検出)', function (): void {
+    // ★ ここが completeness の要。目録の中だけで閉じた検査では
+    //   「checkpoint を 1 件削った」ことを検出できない (残りが要件を満たしてしまう)。
+    foreach (jobDedupGuarantees() as $class => $entry) {
+        expect($entry->preflights)->not->toBeEmpty("{$class}: preflight が空です");
+
+        $checkpoints = array_values(array_filter(
+            $entry->preflights,
+            static fn (PreflightRequirement $p): bool => $p instanceof PreflightCheckpoint,
+        ));
+        $none = array_values(array_filter(
+            $entry->preflights,
+            static fn (PreflightRequirement $p): bool => $p instanceof NoExternalCall,
+        ));
+
+        $expectedKinds = array_map(
+            static fn (ExternalCallKind $k): string => $k->value,
+            jobDedupRequiredExternalCalls()[$class] ?? [],
+        );
+        $registeredKinds = array_map(
+            static fn (PreflightCheckpoint $c): string => $c->externalCall->value,
+            $checkpoints,
+        );
+        sort($expectedKinds);
+        sort($registeredKinds);
+
+        expect($registeredKinds)->toBe(
+            $expectedKinds,
+            "{$class}: checkpoint の種別集合が期待 (jobDedupRequiredExternalCalls) と一致しません。"
+            .'外部呼び出しを増減させたら両方を更新すること',
+        );
+
+        if ($expectedKinds === []) {
+            // preflight を要する外部呼び出しを持たないジョブ: NoExternalCall ちょうど 1 件
+            expect(count($none))->toBe(1, "{$class}: NoExternalCall は 1 件だけ登録すること");
+            expect($checkpoints)->toBe([], "{$class}: 外部呼び出しなしと宣言しつつ checkpoint があります");
+
+            continue;
+        }
+
+        // 外部呼び出しを持つジョブ: NoExternalCall と混在させない
+        expect($none)->toBe([], "{$class}: NoExternalCall と PreflightCheckpoint を混在登録しています");
+    }
+});
+
+test('PreflightRequirement の実装は 2 種類に閉じている (sealed 相当)', function (): void {
+    // PHP に sealed type が無いため gate が閉じる。3 つ目の実装が現れたら、
+    // 「外部呼び出しなし」を主張する新しい抜け道が増えていないか必ず再検討する。
+    // 走査対象は tests/Support/JobDedup/ 配下のみ (app/ 全走査はコストだけで意味がない)。
+    $found = [];
+    foreach (jobDedupSupportPhpFiles() as $path) {
+        $class = jobDedupSupportClassNameForPath($path);
+        if (! class_exists($class)) {
+            continue;
+        }
+
+        $reflection = new ReflectionClass($class);
+        if ($reflection->isInterface() || ! $reflection->implementsInterface(PreflightRequirement::class)) {
+            continue;
+        }
+        $found[] = $reflection->getName();
+    }
+    sort($found);
+
+    expect($found)->toBe([NoExternalCall::class, PreflightCheckpoint::class]);
+});
+
+test('目録の根拠は 30 文字以上 (constructor と gate の二重固定)', function (): void {
+    foreach (jobDedupGuarantees() as $class => $entry) {
+        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(
+            30,
+            "{$class}: 保証側の根拠は 30 文字以上で書くこと",
+        );
+
+        foreach ($entry->preflights as $preflight) {
+            if ($preflight instanceof NoExternalCall) {
+                expect(mb_strlen($preflight->rationale))->toBeGreaterThanOrEqual(
+                    30,
+                    "{$class}: 「外部呼び出しなし」の根拠は 30 文字以上で書くこと",
+                );
+            }
+        }
+    }
+
+    foreach (jobDedupExemptions() as $class => $entry) {
+        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(
+            30,
+            "{$class}: 免除の根拠は 30 文字以上で書くこと",
+        );
+    }
+});
+
+test('免除件数が全体 cap / case 別 cap と一致する (形骸化ガード)', function (): void {
+    $exemptions = jobDedupExemptions();
+
+    expect(count($exemptions))->toBe(
+        jobDedupExemptionCap(),
+        '免除件数が目録の宣言と一致しません。増減させたら jobDedupExemptionCap() も書き換えること '
+        .'(「以下」ではなく「一致」で固定するのは、1 件直しても数字が下がらない「黙って足せる枠」を作らないため)',
+    );
+
+    $byCase = [];
+    foreach ($exemptions as $entry) {
+        $byCase[$entry->exemption->value] = ($byCase[$entry->exemption->value] ?? 0) + 1;
+    }
+    ksort($byCase);
+    $declared = jobDedupExemptionCapByCase();
+    ksort($declared);
+
+    expect($byCase)->toBe($declared, 'case 別の免除件数が宣言と一致しません (分類の偏りは差分に必ず現すこと)');
+});
+
+test('固定 event 名の literal は ExternalCallKind 以外に直書きされていない', function (): void {
+    // literal の直書きが増えると、ログ基盤での集計語彙が静かに割れる。
+    // 2 つの event 名 (抑止 / 後始末) をまとめて検査する。
+    // ★ single / double quote の **4 パターン**を検査する
+    //   (片方だけだと "job_ownership_lost" で gate を回避できる)。
+    $literals = [
+        "'job_ownership_lost'", '"job_ownership_lost"',
+        "'job_ownership_lost_cleanup'", '"job_ownership_lost_cleanup"',
+    ];
+    $violations = [];
+
+    foreach (QueuedJobPopulation::appPhpFiles() as $path) {
+        if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), 'app/Enums/Security/ExternalCallKind.php')) {
+            continue; // 正本
+        }
+
+        $source = file_get_contents($path);
+        Assert::string($source, "ファイルを読み込めません: {$path}");
+
+        foreach ($literals as $literal) {
+            if (str_contains($source, $literal)) {
+                $violations[] = $path.' ('.$literal.')';
+            }
+        }
+    }
+
+    expect($violations)->toBe(
+        [],
+        '固定 event 名は ExternalCallKind::LOG_EVENT / CLEANUP_LOG_EVENT を参照すること: '
+        .implode(', ', $violations),
+    );
+});
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
index f41f9c3..e9be8de 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -20,7 +20,7 @@
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Notifications\Billing\PaymentFailedNotification;
 use App\Notifications\Billing\RenewalReminderNotification;
-use Illuminate\Contracts\Queue\ShouldQueue;
+use Tests\Support\QueuedJobPopulation;
 use Tests\Support\QueueLeaseConfig;
 use Webmozart\Assert\Assert;
 
@@ -78,35 +78,16 @@
 /**
  * app/ 配下の ShouldQueue 実装クラスを列挙する (純関数)。
  *
- * 母集団判定の正本は `ReflectionClass::implementsInterface(ShouldQueue::class)` +
- * `isInstantiable()`。親クラス / trait 経由の実装も拾えるため、Job だけでなく
- * Mailable / Notification も自動的に母集団へ入る。
+ * ★母集団の走査実装は `Tests\Support\QueuedJobPopulation` に **1 本化**されている
+ *   (T131)。JobExecutionDedupInventoryTest と同じ母集団を見ることを構造的に保証し、
+ *   片方だけ更新される drift を根で断つため。判定の正本は
+ *   `ReflectionClass::implementsInterface(ShouldQueue::class)` + `isInstantiable()` で不変。
  *
  * @return list<class-string>
  */
 function jobLeaseShouldQueueClasses(): array
 {
-    $classes = [];
-    foreach (jobLeaseAppPhpFiles() as $path) {
-        $class = jobLeaseClassNameForPath($path);
-        if (! class_exists($class)) {
-            continue;
-        }
-
-        $reflection = new ReflectionClass($class);
-        if (! $reflection->isInstantiable()) {
-            continue;
-        }
-        if (! $reflection->implementsInterface(ShouldQueue::class)) {
-            continue;
-        }
-
-        $classes[] = $reflection->getName();
-    }
-
-    sort($classes);
-
-    return $classes;
+    return QueuedJobPopulation::shouldQueueClasses();
 }
 
 /**
@@ -116,34 +97,13 @@ function jobLeaseShouldQueueClasses(): array
  */
 function jobLeaseAppPhpFiles(): array
 {
-    $appPath = base_path('app');
-    $iterator = new RecursiveIteratorIterator(
-        new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS),
-    );
-
-    $paths = [];
-    foreach ($iterator as $file) {
-        Assert::isInstanceOf($file, SplFileInfo::class);
-        if (! $file->isFile() || $file->getExtension() !== 'php') {
-            continue;
-        }
-        $paths[] = $file->getPathname();
-    }
-
-    sort($paths);
-
-    return $paths;
+    return QueuedJobPopulation::appPhpFiles();
 }
 
 /** app/ 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
 function jobLeaseClassNameForPath(string $path): string
 {
-    $appPath = base_path('app').DIRECTORY_SEPARATOR;
-    Assert::startsWith($path, $appPath, "app/ 配下ではないパスです: {$path}");
-
-    $relative = substr($path, strlen($appPath), -strlen('.php'));
-
-    return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
+    return QueuedJobPopulation::classNameForPath($path);
 }
 
 /**
diff --git a/tests/Feature/Billing/AutoRechargeServiceTest.php b/tests/Feature/Billing/AutoRechargeServiceTest.php
index 97133dd..77d10db 100644
--- a/tests/Feature/Billing/AutoRechargeServiceTest.php
+++ b/tests/Feature/Billing/AutoRechargeServiceTest.php
@@ -7,6 +7,8 @@
 use App\Enums\Billing\AutoRechargeAttemptStatus;
 use App\Enums\Billing\AutoRechargeDisabledReason;
 use App\Enums\Billing\BillingNotificationType;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Security\ExternalCallKind;
 use App\Models\Billing\BillingNotification;
 use App\Models\Billing\TicketAutoRecharge;
 use App\Models\Billing\TicketAutoRechargeAttempt;
@@ -17,7 +19,10 @@
 use App\Services\Billing\AutoRechargeService;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
 use App\Services\Billing\TicketLedgerService;
+use App\Support\JobExecution\AttemptOwnershipPreflight;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Validation\ValidationException;
+use Tests\Support\FakeAttemptOwnershipPreflight;
 use Tests\Support\FakeAutoRechargeGateway;
 
 /*
@@ -452,3 +457,328 @@ function grantTickets(Organization $organization, int $amount): void
     // hard invariant: 実請求額 (attempt に pin した単価 × 数量) は同意上限を超えない
     expect($attempt->unit_amount * $attempt->quantity)->toBeLessThanOrEqual($consentedAmount);
 });
+
+/*
+ * ─────────────────────────────────────────────────────────────────────
+ * T131 / S4: Stripe 呼び出し直前の所有権再検証 (preflight suppression) と
+ *            中断時の invoice 終端 (裁定 AG-082)
+ *
+ * 配置 (placement) は `FakeAttemptOwnershipPreflight` (競合注入シーム) が固定する。
+ * シームは **verdict を差し替えない** — checkpoint 直前に attempt 行を terminal 化して
+ * `parent::stillPending()` へ委譲するだけなので、refresh / status 判定 /
+ * 所有権喪失ログは常に本番実装が実行する。
+ * ─────────────────────────────────────────────────────────────────────
+ */
+
+/**
+ * 抑止ログ (`job_ownership_lost`) の必須キー集合。
+ *
+ * ★他テストファイルのグローバル定数を参照しない (Pest の --parallel はファイル単位で
+ *   プロセスを分けるため未定義になりうる)。Manual 側 (JobOwnershipLostContextTest) と
+ *   同じ集合をここにも書き、両者が一致していることを人が読める形で残す。
+ *
+ * @var list<string>
+ */
+const AUTO_RECHARGE_OWNERSHIP_LOST_REQUIRED_KEYS = [
+    'event',
+    'job_type',
+    'job_id',
+    'expected_status',
+    'actual_status',
+    'stage',
+    'external_call',
+];
+
+/**
+ * preflight シームを差し込んだ setup (service 解決より前に instance() する)。
+ *
+ * @return array{Organization, User, FakeAutoRechargeGateway, AutoRechargeService, FakeAttemptOwnershipPreflight}
+ */
+function autoRechargePreflightSetup(): array
+{
+    $preflight = new FakeAttemptOwnershipPreflight;
+    app()->instance(AttemptOwnershipPreflight::class, $preflight);
+
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+
+    return [$organization, $owner, $gateway, $service, $preflight];
+}
+
+/** enabled 設定 + pending attempt を 1 件作る (残高 0 = 閾値割れ)。 */
+function autoRechargePendingAttempt(
+    Organization $organization,
+    User $owner,
+    AutoRechargeService $service,
+): TicketAutoRechargeAttempt {
+    $service->updateSettings($organization, $owner, true, 5, 50, new AutoRechargeConsentDto(
+        config()->string('billing.auto_recharge.consent_version'),
+    ));
+
+    $attempt = $service->maybeCreateAttempt($organization);
+    expect($attempt)->not->toBeNull();
+    assert($attempt instanceof TicketAutoRechargeAttempt);
+
+    return $attempt;
+}
+
+test('配置: create の直前に preflight がある (terminalizeAt=create で invoice を作らない)', function (): void {
+    Log::spy();
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoiceCreate];
+
+    $service->executeAttempt($attempt);
+
+    // create checkpoint で止まる = pay checkpoint へ到達しない
+    expect($preflight->calls)->toBe([ExternalCallKind::StripeInvoiceCreate->value]);
+    expect($gateway->createdInvoices)->toBe([]);
+    expect($gateway->payCalls)->toBe([]);
+    expect($gateway->terminated)->toBe([]); // 未作成なので終端対象が無い
+    expect($attempt->refresh()->stripe_invoice_id)->toBeNull();
+
+    // 所有権喪失ログ: Manual 側と必須 7 キーが一致し、Billing 固有の追加は attempt_ulid のみ
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use ($attempt): bool {
+            if (($context['event'] ?? null) !== ExternalCallKind::LOG_EVENT) {
+                return false;
+            }
+            $keys = array_keys($context);
+            sort($keys);
+            $expected = array_merge(AUTO_RECHARGE_OWNERSHIP_LOST_REQUIRED_KEYS, ['attempt_ulid']);
+            sort($expected);
+
+            return $keys === $expected
+                && $context['job_type'] === TicketAutoRechargeAttempt::class
+                && $context['job_id'] === $attempt->id
+                && $context['expected_status'] === 'pending'
+                && $context['actual_status'] === 'canceled'
+                && $context['stage'] === 'execute_attempt'
+                && $context['external_call'] === ExternalCallKind::StripeInvoiceCreate->value
+                && $context['attempt_ulid'] === $attempt->attempt_ulid;
+        })
+        ->once();
+});
+
+test('配置: pay の直前に preflight がある (terminalizeAt=pay で pay せず invoice を終端する)', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+
+    $service->executeAttempt($attempt);
+
+    // preflight 1 は Pending で通過 → create → attach 1 行 → preflight 2 直前に canceled 化
+    expect($preflight->calls)->toBe([
+        ExternalCallKind::StripeInvoiceCreate->value,
+        ExternalCallKind::StripeInvoicePay->value,
+    ]);
+    expect($gateway->createdInvoices)->toHaveCount(1);
+    expect($gateway->payCalls)->toBe([]);
+
+    $attempt->refresh();
+    $invoiceId = $attempt->stripe_invoice_id;
+    expect($invoiceId)->not->toBeNull(); // attach は成功している (DB に残る)
+    // 作成された invoice id で 1 回だけ終端される (Canceled 分岐)
+    expect($gateway->terminated)->toBe([$invoiceId]);
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Canceled);
+});
+
+test('後始末: terminalStatus=failed のとき terminateInvoice を呼ばない (二重終端の抑止)', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $preflight->terminalStatus = AutoRechargeAttemptStatus::Failed;
+
+    $service->executeAttempt($attempt);
+
+    // failed へ遷移させた側 (terminateAndFail) が既に終端済みという前提に立つ
+    expect($gateway->terminated)->toBe([]);
+    expect($gateway->payCalls)->toBe([]);
+});
+
+test('後始末: terminalStatus=paid のとき terminateInvoice を呼ばない (void 不可の分類)', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $preflight->terminalStatus = AutoRechargeAttemptStatus::Paid;
+
+    $service->executeAttempt($attempt);
+
+    expect($gateway->terminated)->toBe([]);
+    expect($gateway->payCalls)->toBe([]);
+});
+
+test('配置: 行が Pending のままなら create → pay が従来どおり進む (回帰)', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $gateway->payAmountPaid = $attempt->unit_amount * $attempt->quantity;
+
+    $service->executeAttempt($attempt);
+
+    // 2 つの checkpoint を**両方**通る
+    expect($preflight->calls)->toBe([
+        ExternalCallKind::StripeInvoiceCreate->value,
+        ExternalCallKind::StripeInvoicePay->value,
+    ]);
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Paid);
+    expect($gateway->payCalls)->toHaveCount(1);
+});
+
+test('preflight 2: terminateInvoice が例外を投げても課金処理へ進まない', function (): void {
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+    $gateway->failOnTerminate = true;
+
+    $service->executeAttempt($attempt);
+
+    expect($gateway->payCalls)->toBe([]);
+    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::Grant)->count())->toBe(0);
+});
+
+test('後始末ログは別 event 名 job_ownership_lost_cleanup を使い独自 schema を持つ', function (): void {
+    Log::spy();
+    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
+
+    $service->executeAttempt($attempt);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use ($attempt): bool {
+            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
+                return false;
+            }
+            $keys = array_keys($context);
+            sort($keys);
+            $expected = ['attempt_ulid', 'error', 'event', 'invoice_id', 'job_id', 'job_type', 'terminated'];
+
+            return $keys === $expected
+                && $context['terminated'] === true
+                && $context['error'] === null
+                && $context['attempt_ulid'] === $attempt->attempt_ulid;
+        })
+        ->once();
+
+    // 抑止ログと後始末ログが同じ event 名に混ざらない (同一 event = 同一集計 schema)
+    Log::shouldHaveReceived('warning')
+        ->withArgs(fn (string $message, array $context): bool => ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
+            && ! array_key_exists('invoice_id', $context))
+        ->once();
+});
+
+test('attach 0 行: invoice 作成成功と同時に canceled 化 → invoice_id を書かず invoice を終端する', function (): void {
+    // 実 preflight を使う (競合点は gateway の duringCreateInvoice hook が作る)
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $gateway->duringCreateInvoice = function () use ($attempt): void {
+        // Stripe 側の作成は成功したが、返る前に停止側が canceled 化した
+        TicketAutoRechargeAttempt::query()->whereKey($attempt->id)->update([
+            'status' => AutoRechargeAttemptStatus::Canceled->value,
+        ]);
+    };
+
+    $service->executeAttempt($attempt);
+
+    $attempt->refresh();
+    expect($attempt->stripe_invoice_id)->toBeNull();  // DB には書かない
+    expect($gateway->createdInvoices)->toHaveCount(1);
+    // DB に保存済みであることに依存せず、ローカルの invoice id で終端する
+    expect($gateway->terminated)->toHaveCount(1);
+    expect($gateway->payCalls)->toBe([]);
+});
+
+test('attach 0 行: failed へ遷移していた場合も invoice を終端する (status を問わない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $gateway->duringCreateInvoice = function () use ($attempt): void {
+        TicketAutoRechargeAttempt::query()->whereKey($attempt->id)->update([
+            'status' => AutoRechargeAttemptStatus::Failed->value,
+        ]);
+    };
+
+    $service->executeAttempt($attempt);
+
+    // failed へ遷移させた側は stripe_invoice_id === null を見ているため終端できない。
+    // ここで終端しないと「誰も終端しない open invoice」が残る
+    expect($gateway->terminated)->toHaveCount(1);
+    expect($attempt->refresh()->stripe_invoice_id)->toBeNull();
+});
+
+test('前提: Failed へ遷移した attempt は invoice が終端済みである', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $attempt->forceFill(['stripe_invoice_id' => 'in_precondition'])->save();
+    $gateway->invoiceStatuses['in_precondition'] = 'open';
+
+    $service->terminateAndFail($organization, $attempt);
+
+    // terminateAndFail は「invoice 終端成功 → failed 遷移」の順序を守る。
+    // この前提が崩れると terminateInvoiceAfterOwnershipLost の Canceled 限定が壊れる
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Failed);
+    expect($gateway->terminated)->toBe(['in_precondition']);
+});
+
+test('前提: terminateInvoice が失敗したら attempt は Pending のまま (Failed へ遷移しない)', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $attempt->forceFill(['stripe_invoice_id' => 'in_stuck_precondition'])->save();
+    $gateway->failOnTerminate = true;
+
+    $service->terminateAndFail($organization, $attempt);
+
+    expect($attempt->refresh()->status)->toBe(AutoRechargeAttemptStatus::Pending);
+});
+
+test('冪等キーは 2 本ある: 同一 invoice の付与は台帳 1 件・attempt 遷移も 1 回', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $due = $attempt->unit_amount * $attempt->quantity;
+
+    // 付与の一回性 = 台帳の recharge:{invoiceId} UNIQUE (invoice 単位)
+    // attempt 遷移の一回性 = where status=pending の条件付き UPDATE (attempt 単位)
+    $service->recordSuccessfulCharge($organization, $attempt, 'in_two_keys', $due, $due, 'pi_1');
+    $resolvedAt = $attempt->refresh()->resolved_at;
+    $service->recordSuccessfulCharge($organization, $attempt->fresh(), 'in_two_keys', $due, $due, 'pi_1');
+
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'recharge:in_two_keys')->count())->toBe(1);
+    $attempt->refresh();
+    expect($attempt->status)->toBe(AutoRechargeAttemptStatus::Paid);
+    // 2 回目は 0 行更新 = resolved_at が動かない
+    expect((string) $attempt->resolved_at?->toJSON())->toBe((string) $resolvedAt?->toJSON());
+});
+
+test('Stripe idempotency key は操作ごとに異なり attempt_ulid に pin されている', function (): void {
+    [$organization, $owner, $gateway, $service] = autoRechargeSetup();
+    $gateway->withDefaultPaymentMethod();
+    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
+    $gateway->payAmountPaid = $attempt->unit_amount * $attempt->quantity;
+
+    $service->executeAttempt($attempt);
+
+    // key base は attempt_ulid に pin される (attempt が変われば必ず別キーになる)
+    $expectedBase = "auto-recharge:{$attempt->attempt_ulid}";
+    expect($gateway->createdInvoices[0]['keyBase'])->toBe($expectedBase);
+    expect($gateway->payCalls[0]['keyBase'])->toBe($expectedBase);
+
+    // gateway 実装が組む 4 キーは互いに異なる (同一キーだと Stripe が別操作を replay 扱いする)。
+    // Stripe SDK へ到達させずに固定するため、実装ソースの接尾辞集合を検査する。
+    $source = file_get_contents(app_path('Services/Billing/CashierAutoRechargeGateway.php'));
+    expect($source)->toBeString();
+    $suffixes = ['invoice', 'item', 'finalize', 'pay'];
+    foreach ($suffixes as $suffix) {
+        expect($source)->toContain("{\$idempotencyKeyBase}:{$suffix}");
+    }
+    expect(count(array_unique($suffixes)))->toBe(4);
+});
diff --git a/tests/Feature/Manual/JobOwnershipLostContextTest.php b/tests/Feature/Manual/JobOwnershipLostContextTest.php
new file mode 100644
index 0000000..8181984
--- /dev/null
+++ b/tests/Feature/Manual/JobOwnershipLostContextTest.php
@@ -0,0 +1,109 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\AnalysisStep;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Security\ExternalCallKind;
+use App\Exceptions\Manual\JobOwnershipLostException;
+use App\Models\AnalysisJob;
+
+/*
+ * S1: 所有権喪失の共通語彙 (例外 + ExternalCallKind)。
+ *
+ * この例外は「異常」ではなく「正常だが観測したい事象」であり、固定 event 名の構造化ログとして
+ * 集計される。したがって守るべき契約は **ログ context の schema** である:
+ *  - 固定 event 名 (ExternalCallKind::LOG_EVENT) を含む
+ *  - 必須 7 キーが揃う
+ *  - PII (email / name / user) 由来のキーを含まない
+ *  - 値がすべて scalar|null (外部 payload オブジェクトを埋め込まない)
+ *
+ * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
+ */
+
+/** 抑止ログの必須キー集合 (Manual / Billing 双方がこれを満たす)。 */
+const JOB_OWNERSHIP_LOST_REQUIRED_KEYS = [
+    'event',
+    'job_type',
+    'job_id',
+    'expected_status',
+    'actual_status',
+    'stage',
+    'external_call',
+];
+
+test('logContext() が固定 event 名 job_ownership_lost を含む', function (): void {
+    $exception = JobOwnershipLostException::whileRunning(
+        jobType: AnalysisJob::class,
+        jobId: 42,
+        actualStatus: JobStatus::Failed,
+        stage: AnalysisStep::Extract->value,
+        externalCall: ExternalCallKind::LlmCompletion,
+    );
+
+    expect($exception->logContext()['event'])->toBe(ExternalCallKind::LOG_EVENT)
+        ->and(ExternalCallKind::LOG_EVENT)->toBe('job_ownership_lost');
+});
+
+test('logContext() の必須キー集合が仕様どおり (7 キー) で PII 由来のキーを含まない', function (): void {
+    $exception = JobOwnershipLostException::whileRunning(
+        jobType: AnalysisJob::class,
+        jobId: 42,
+        actualStatus: JobStatus::Failed,
+        stage: AnalysisStep::Decompose->value,
+        externalCall: ExternalCallKind::LlmCompletion,
+    );
+
+    $keys = array_keys($exception->logContext());
+    sort($keys);
+    $required = JOB_OWNERSHIP_LOST_REQUIRED_KEYS;
+    sort($required);
+
+    expect($keys)->toBe($required);
+
+    // PII (email / name) をキー名レベルで機械的に排除する
+    foreach ($keys as $key) {
+        expect($key)->not->toContain('email')
+            ->and($key)->not->toContain('name')
+            ->and($key)->not->toContain('user');
+    }
+});
+
+test('logContext() の値がすべて scalar|null (payload オブジェクトを埋め込んでいない)', function (): void {
+    $exception = JobOwnershipLostException::whileRunning(
+        jobType: AnalysisJob::class,
+        jobId: 7,
+        actualStatus: null, // 行が消えているケース
+        stage: AnalysisStep::Generate->value,
+        externalCall: ExternalCallKind::ObjectStoragePut,
+    );
+
+    $context = $exception->logContext();
+    foreach ($context as $key => $value) {
+        expect($value === null || is_scalar($value))->toBeTrue("{$key} が scalar|null ではない");
+    }
+
+    expect($context['actual_status'])->toBeNull()
+        ->and($context['job_id'])->toBe(7)
+        ->and($context['stage'])->toBe('generate')
+        ->and($context['external_call'])->toBe('object_storage_put');
+});
+
+test('whileRunning() は expectedStatus に JobStatus::Running を入れる', function (): void {
+    $exception = JobOwnershipLostException::whileRunning(
+        jobType: AnalysisJob::class,
+        jobId: 1,
+        actualStatus: JobStatus::Succeeded,
+        stage: AnalysisStep::Extract->value,
+        externalCall: ExternalCallKind::LlmCompletion,
+    );
+
+    expect($exception->expectedStatus)->toBe(JobStatus::Running)
+        ->and($exception->logContext()['expected_status'])->toBe('running')
+        ->and($exception->logContext()['actual_status'])->toBe('succeeded');
+});
+
+test('cleanup 用の event 名は抑止用と別である (schema の混線防止)', function (): void {
+    expect(ExternalCallKind::CLEANUP_LOG_EVENT)->toBe('job_ownership_lost_cleanup')
+        ->and(ExternalCallKind::CLEANUP_LOG_EVENT)->not->toBe(ExternalCallKind::LOG_EVENT);
+});
diff --git a/tests/Feature/Manual/RenderPipelineTest.php b/tests/Feature/Manual/RenderPipelineTest.php
index 9ffc24b..a03d446 100644
--- a/tests/Feature/Manual/RenderPipelineTest.php
+++ b/tests/Feature/Manual/RenderPipelineTest.php
@@ -12,6 +12,7 @@
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\RenderKind;
 use App\Enums\Manual\VideoManualStatus;
+use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Manual\RenderCompositionException;
 use App\Jobs\Manual\DeleteRenderOutputsJob;
 use App\Models\Billing\TicketLedgerEntry;
@@ -23,11 +24,14 @@
 use App\Models\Take;
 use App\Models\User;
 use App\Models\VideoManual;
+use App\Notifications\InApp\ManualRenderedNotification;
 use App\Services\Billing\TicketLedgerService;
 use App\Services\Manual\RenderJobService;
 use App\Services\Manual\RenderPipeline;
 use App\Services\Render\VideoComposer;
 use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\Notification;
 use Illuminate\Support\Facades\Queue;
 use Illuminate\Support\Facades\Storage;
 
@@ -393,3 +397,182 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
         ['expected_version' => 2, 'steps' => []],
     )->assertConflict()->assertJson(['conflict_type' => 'rendering']);
 });
+
+/*
+ * ─────────────────────────────────────────────────────────────────────
+ * T131 / S3: 所有権再検証 (preflight suppression) + 終端後の進捗書き戻し禁止
+ * (裁定 AG-082。詳細設計 devnotes/20260807-1235-job-execution-dedup)
+ * ─────────────────────────────────────────────────────────────────────
+ */
+
+/** stale 回復 cron が先着で書く文言 (preflight 経路が上書きしないことの固定に使う) */
+const RENDER_STALE_CRON_ERROR_MESSAGE = 'stale 回復 cron が失敗確定しました。';
+
+test('preflight: compose 中に cron が failed 化 → S3 へ 1 件も PUT しない', function (): void {
+    [, , , $manual, , $job, $fake] = renderPipelineContext();
+    $job = renderTriggeredJob($job);
+    $fake->duringCompose = function () use ($job): void {
+        app(RenderJobService::class)->failJob(
+            $job->refresh(),
+            RenderErrorCode::Timeout,
+            RENDER_STALE_CRON_ERROR_MESSAGE,
+        );
+    };
+
+    app(RenderPipeline::class)->run($job->id);
+
+    $expectedKey = "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v2-{$job->id}.mp4";
+    Storage::disk('s3')->assertMissing($expectedKey);
+    // 素材以外は S3 に増えていない (アップロード → 後始末削除ですらない = そもそも PUT していない)
+    expect(Storage::disk('s3')->allFiles())->toBe([Take::query()->firstOrFail()->video_path]);
+
+    $job->refresh();
+    expect($job->output_path)->toBeNull();
+    expect($job->status)->toBe(JobStatus::Failed);
+});
+
+test('preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない', function (): void {
+    [, , , , , $job, $fake] = renderPipelineContext();
+    $job = renderTriggeredJob($job);
+    /** @var array{step: mixed, progress: ?int, error: ?string, error_code: mixed, updated_at: string} $snapshot */
+    $snapshot = ['step' => null, 'progress' => null, 'error' => null, 'error_code' => null, 'updated_at' => ''];
+
+    $fake->duringCompose = function () use ($job, &$snapshot): void {
+        app(RenderJobService::class)->failJob(
+            $job->refresh(),
+            RenderErrorCode::Timeout,
+            RENDER_STALE_CRON_ERROR_MESSAGE,
+        );
+        $after = RenderJob::query()->findOrFail($job->id);
+        $snapshot = [
+            'step' => $after->step,
+            'progress' => $after->progress,
+            'error' => $after->error,
+            'error_code' => $after->error_code,
+            'updated_at' => (string) $after->updated_at?->toJSON(),
+        ];
+    };
+
+    app(RenderPipeline::class)->run($job->id);
+
+    $job->refresh();
+    // onClipComposed / concat の進捗書き戻しが terminal 行へ 1 バイトも入らない
+    expect($job->step)->toBe($snapshot['step']);
+    expect($job->progress)->toBe($snapshot['progress']);
+    expect((string) $job->updated_at?->toJSON())->toBe($snapshot['updated_at']);
+    // failJob が二重に走らない (error / error_code が cron の値のまま)
+    expect($job->error)->toBe($snapshot['error']);
+    expect($job->error_code)->toBe($snapshot['error_code']);
+    expect($job->error)->toBe(RENDER_STALE_CRON_ERROR_MESSAGE);
+    expect($job->error_code)->toBe(RenderErrorCode::Timeout);
+});
+
+test('preflight: 所有権喪失時に work dir が削除される (finally を通る)', function (): void {
+    [, , , , , $job, $fake] = renderPipelineContext();
+    $job = renderTriggeredJob($job);
+    $fake->duringCompose = function () use ($job): void {
+        app(RenderJobService::class)->failJob(
+            $job->refresh(),
+            RenderErrorCode::Timeout,
+            RENDER_STALE_CRON_ERROR_MESSAGE,
+        );
+    };
+
+    app(RenderPipeline::class)->run($job->id);
+
+    expect(is_dir(storage_path("app/render/{$job->id}")))->toBeFalse();
+});
+
+test('preflight: preview (kind=preview) でも同じく PUT しない', function (): void {
+    [, , $project, $manual, , , $fake] = renderPipelineContext(tickets: 0, trigger: false);
+    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
+    $fake->duringCompose = function () use ($previewJob): void {
+        app(RenderJobService::class)->failJob(
+            $previewJob->refresh(),
+            RenderErrorCode::Timeout,
+            RENDER_STALE_CRON_ERROR_MESSAGE,
+        );
+    };
+
+    app(RenderPipeline::class)->run($previewJob->id);
+
+    $expectedKey = "projects/{$manual->project_id}/manuals/{$manual->id}/previews/v2-{$previewJob->id}.mp4";
+    Storage::disk('s3')->assertMissing($expectedKey);
+    expect($previewJob->refresh()->output_path)->toBeNull();
+});
+
+test('preflight: 所有権喪失は固定 event 名で warning ログに出る', function (): void {
+    Log::spy();
+    [, , , , , $job, $fake] = renderPipelineContext();
+    $job = renderTriggeredJob($job);
+    $fake->duringCompose = function () use ($job): void {
+        app(RenderJobService::class)->failJob(
+            $job->refresh(),
+            RenderErrorCode::Timeout,
+            RENDER_STALE_CRON_ERROR_MESSAGE,
+        );
+    };
+
+    app(RenderPipeline::class)->run($job->id);
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use ($job): bool {
+            return ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
+                && ($context['job_type'] ?? null) === RenderJob::class
+                && ($context['job_id'] ?? null) === $job->id
+                && ($context['expected_status'] ?? null) === 'running'
+                && ($context['actual_status'] ?? null) === 'failed'
+                && ($context['stage'] ?? null) === 'concat'
+                && ($context['external_call'] ?? null) === ExternalCallKind::ObjectStoragePut->value;
+        })
+        ->once();
+});
+
+test('preflight: 所有権喪失時に完了通知が二重に飛ばない (先着の cron 分だけ)', function (): void {
+    Notification::fake();
+    [, $owner, , , , $job, $fake] = renderPipelineContext();
+    $job = renderTriggeredJob($job);
+    // 通知宛先を実在させる (宛先ゼロだと「飛んでいない」ことの検査が空振りする)
+    $job->triggeredBy()->associate($owner);
+    $job->save();
+    $fake->duringCompose = function () use ($job): void {
+        app(RenderJobService::class)->failJob(
+            $job->refresh(),
+            RenderErrorCode::Timeout,
+            RENDER_STALE_CRON_ERROR_MESSAGE,
+        );
+    };
+
+    app(RenderPipeline::class)->run($job->id);
+
+    Notification::assertSentTimes(ManualRenderedNotification::class, 1);
+});
+
+test('preflight: 所有権喪失時に DeleteRenderOutputsJob が dispatch されない', function (): void {
+    [, , , , , $job, $fake] = renderPipelineContext();
+    $job = renderTriggeredJob($job);
+    $fake->duringCompose = function () use ($job): void {
+        app(RenderJobService::class)->failJob(
+            $job->refresh(),
+            RenderErrorCode::Timeout,
+            RENDER_STALE_CRON_ERROR_MESSAGE,
+        );
+    };
+
+    app(RenderPipeline::class)->run($job->id);
+
+    Queue::assertNotPushed(DeleteRenderOutputsJob::class);
+});
+
+test('preflight: 行が消えていても所有権喪失として扱う (deny-by-default)', function (): void {
+    [, , , , , $job, $fake] = renderPipelineContext();
+    $job = renderTriggeredJob($job);
+    $fake->duringCompose = function () use ($job): void {
+        RenderJob::query()->whereKey($job->id)->delete();
+    };
+
+    app(RenderPipeline::class)->run($job->id);
+
+    expect(RenderJob::query()->whereKey($job->id)->exists())->toBeFalse();
+    expect(Storage::disk('s3')->allFiles())->toBe([Take::query()->firstOrFail()->video_path]);
+});
diff --git a/tests/Feature/Projects/AnalysisPipelineTest.php b/tests/Feature/Projects/AnalysisPipelineTest.php
index 95868c9..d8e3e97 100644
--- a/tests/Feature/Projects/AnalysisPipelineTest.php
+++ b/tests/Feature/Projects/AnalysisPipelineTest.php
@@ -9,6 +9,7 @@
 use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\ShotType;
 use App\Enums\Manual\VideoManualStatus;
+use App\Enums\Security\ExternalCallKind;
 use App\Models\AnalysisJob;
 use App\Models\Billing\TicketLedgerEntry;
 use App\Models\Billing\TicketReservation;
@@ -18,6 +19,7 @@
 use App\Models\SourceDocument;
 use App\Models\User;
 use App\Models\VideoManual;
+use App\Notifications\InApp\ManualAnalyzedNotification;
 use App\Services\Billing\TicketLedgerService;
 use App\Services\Manual\AnalysisJobService;
 use App\Services\Manual\AnalysisPipeline;
@@ -26,6 +28,8 @@
 use Illuminate\Http\Client\ConnectionException;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\Notification;
 use Illuminate\Support\Facades\Storage;
 use Kent013\PrismPrompt\Prompt;
 use Kent013\PrismPrompt\Testing\TextResponseFake;
@@ -160,6 +164,9 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
 
 const ANALYSIS_GENERIC_FAILURE_MESSAGE = '解析に失敗しました。時間をおいて再実行してください。';
 
+/** T131 / S2: stale 回復 cron が先着で書く文言 (preflight 経路が上書きしないことの固定に使う) */
+const STALE_CRON_ERROR_MESSAGE = 'stale 回復 cron が失敗確定しました。';
+
 test('成功パス: cuts materialize / ready / version+1 / succeeded / committed / スナップショット保存', function (): void {
     [$organization, , , $manual, $document, $job] = pipelineContext();
     fakeSuccessfulLlm();
@@ -743,3 +750,155 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
     expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(0);
 });
+
+/*
+ * ─────────────────────────────────────────────────────────────────────
+ * T131 / S2: 所有権再検証 (preflight suppression) + 終端後の進捗書き戻し禁止
+ * (裁定 AG-082。詳細設計 devnotes/20260807-1235-job-execution-dedup)
+ * ─────────────────────────────────────────────────────────────────────
+ */
+
+test('preflight: extract の 1 回目直後に cron が failed 化 → 以降の LLM を 1 回も呼ばない', function (): void {
+    [, , , , , $job] = pipelineContext();
+    $fake = installThrowingLlm(
+        script: successfulLlmScript(),
+        onAttempt: function (int $attempt) use ($job): void {
+            if ($attempt === 1) {
+                // stale 回復 cron 相当が extract の 1 試行目の最中に失敗確定する
+                app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
+            }
+        },
+    );
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    // decompose / generate の LLM は 1 回も呼ばれない (extract の 1 回だけ)
+    expect($fake->attemptCount())->toBe(1);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    // preflight 経路は failJob を呼ばない = cron の文言を上書きしない
+    expect($job->error)->toBe(STALE_CRON_ERROR_MESSAGE);
+});
+
+test('preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない', function (): void {
+    [, , , , , $job] = pipelineContext();
+    /** @var array{step: mixed, progress: ?int, updated_at: ?string} $snapshot */
+    $snapshot = ['step' => null, 'progress' => null, 'updated_at' => null];
+
+    installThrowingLlm(
+        script: successfulLlmScript(),
+        onAttempt: function (int $attempt) use ($job, &$snapshot): void {
+            if ($attempt !== 1) {
+                return;
+            }
+            app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
+            $after = AnalysisJob::query()->findOrFail($job->id);
+            $snapshot = [
+                'step' => $after->step,
+                'progress' => $after->progress,
+                'updated_at' => (string) $after->updated_at?->toJSON(),
+            ];
+        },
+    );
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    $job->refresh();
+    // 条件付き UPDATE (where status=running) により terminal 行へは 1 バイトも書かない
+    expect($job->step)->toBe($snapshot['step']);
+    expect($job->progress)->toBe($snapshot['progress']);
+    expect((string) $job->updated_at?->toJSON())->toBe($snapshot['updated_at']);
+    expect($job->result_json)->toBeNull(); // decompose の result_json も書かれない
+});
+
+test('preflight: 所有権喪失時に完了通知が二重に飛ばない (先着の cron 分だけ)', function (): void {
+    Notification::fake();
+    [, $owner, , , , $job] = pipelineContext();
+    // 通知宛先を実在させる (宛先ゼロだと「飛んでいない」ことの検査が空振りする)
+    $job->triggeredBy()->associate($owner);
+    $job->save();
+    installThrowingLlm(
+        script: successfulLlmScript(),
+        onAttempt: function (int $attempt) use ($job): void {
+            if ($attempt === 1) {
+                app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
+            }
+        },
+    );
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    // 先着 (cron) の failJob が 1 通送る。preflight 経路は failJob も通知も呼ばない
+    Notification::assertSentTimes(ManualAnalyzedNotification::class, 1);
+});
+
+test('preflight: 所有権喪失時に予約が二重 release されない', function (): void {
+    [, , , , , $job] = pipelineContext();
+    installThrowingLlm(
+        script: successfulLlmScript(),
+        onAttempt: function (int $attempt) use ($job): void {
+            if ($attempt === 1) {
+                app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
+            }
+        },
+    );
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    $job->refresh();
+    $reservation = $job->ticketReservation;
+    expect($reservation)->not->toBeNull();
+    expect($reservation?->status)->toBe(TicketReservationStatus::Released);
+    // 解放の監査痕跡は 1 件のみ (二重 release していない)
+    expect(TicketLedgerEntry::query()
+        ->where('reservation_id', $reservation?->getKey())
+        ->where('kind', TicketLedgerKind::Release)
+        ->count())->toBe(1);
+    expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(0);
+});
+
+test('preflight: 所有権喪失は固定 event 名で warning ログに出る', function (): void {
+    Log::spy();
+    [, , , , , $job] = pipelineContext();
+    installThrowingLlm(
+        script: successfulLlmScript(),
+        onAttempt: function (int $attempt) use ($job): void {
+            if ($attempt === 1) {
+                app(AnalysisJobService::class)->failJob($job->refresh(), STALE_CRON_ERROR_MESSAGE);
+            }
+        },
+    );
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    // メッセージ文字列には依存せず context の event キーで判定する
+    // (既存の「LLM 呼び出しを再試行します」warning と混ざらない)
+    Log::shouldHaveReceived('warning')
+        ->withArgs(function (string $message, array $context) use ($job): bool {
+            return ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
+                && ($context['job_type'] ?? null) === AnalysisJob::class
+                && ($context['job_id'] ?? null) === $job->id
+                && ($context['expected_status'] ?? null) === 'running'
+                && ($context['actual_status'] ?? null) === 'failed'
+                && ($context['external_call'] ?? null) === ExternalCallKind::LlmCompletion->value;
+        })
+        ->once();
+});
+
+test('preflight: 行が消えていても所有権喪失として扱う (deny-by-default)', function (): void {
+    [, , , , , $job] = pipelineContext();
+    $fake = installThrowingLlm(
+        script: successfulLlmScript(),
+        onAttempt: function (int $attempt) use ($job): void {
+            if ($attempt === 1) {
+                AnalysisJob::query()->whereKey($job->id)->delete();
+            }
+        },
+    );
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    expect($fake->attemptCount())->toBe(1);
+    expect(AnalysisJob::query()->whereKey($job->id)->exists())->toBeFalse();
+});
diff --git a/tests/Support/FakeAttemptOwnershipPreflight.php b/tests/Support/FakeAttemptOwnershipPreflight.php
new file mode 100644
index 0000000..0cc62e2
--- /dev/null
+++ b/tests/Support/FakeAttemptOwnershipPreflight.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Enums\Security\ExternalCallKind;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Support\JobExecution\AttemptOwnershipPreflight;
+use Carbon\CarbonImmutable;
+
+/**
+ * `AttemptOwnershipPreflight` の **競合注入シーム** (verdict の差し替えではない)。
+ *
+ * Billing の実行経路は「冒頭の Pending guard → invoice 作成 → attach → pay」という直列で、
+ * guard と各 preflight の**間に注入点が 1 つも無い**。そのため
+ * 「checkpoint の**直前**に停止側 / 他ワーカーが terminal 化した」窓を作れるのは
+ * preflight 自身だけである。本クラスはその窓を開けることだけを責務にし、
+ * **判定は本番実装 (`parent::stillPending()`) に委譲する**。
+ *
+ * ★この性質 (verdict を差し替えない) を壊さないこと。fake で判定そのものを差し替えると、
+ *   refresh / status 判定 / 所有権喪失ログがテストで一度も実行されなくなり、
+ *   テストが実装から乖離する。
+ */
+final class FakeAttemptOwnershipPreflight extends AttemptOwnershipPreflight
+{
+    /**
+     * この checkpoint に到達したら attempt 行を terminal 化する。
+     *
+     * @var list<ExternalCallKind>
+     */
+    public array $terminalizeAt = [];
+
+    /** terminal 化させる先 (canceled / failed / paid を切り替えて後始末の分岐を固定する) */
+    public AutoRechargeAttemptStatus $terminalStatus = AutoRechargeAttemptStatus::Canceled;
+
+    /**
+     * 到達した checkpoint の記録 (= 配置の観測)。
+     *
+     * @var list<string>
+     */
+    public array $calls = [];
+
+    public function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
+    {
+        $this->calls[] = $call->value;
+
+        if (in_array($call, $this->terminalizeAt, true)) {
+            // ★ 「checkpoint の**直前**に停止側 / 他ワーカーが terminal 化した」窓を作る。
+            TicketAutoRechargeAttempt::query()
+                ->whereKey($attempt->id)
+                ->where('status', AutoRechargeAttemptStatus::Pending->value)
+                ->update([
+                    'status' => $this->terminalStatus->value,
+                    'updated_at' => CarbonImmutable::now(),
+                ]);
+        }
+
+        // ★ **判定は fake しない**。refresh / status 判定 / 所有権喪失ログは本番実装が実行する。
+        //   fake の責務は「窓を開けること」だけである。
+        return parent::stillPending($attempt, $call);
+    }
+}
diff --git a/tests/Support/FakeAutoRechargeGateway.php b/tests/Support/FakeAutoRechargeGateway.php
index 5e175d4..d12d5e5 100644
--- a/tests/Support/FakeAutoRechargeGateway.php
+++ b/tests/Support/FakeAutoRechargeGateway.php
@@ -9,6 +9,7 @@
 use App\DataTransferObjects\Billing\OffSessionChargeResultDto;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Closure;
 use RuntimeException;
 
 /**
@@ -55,6 +56,15 @@ final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
     /** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
     public bool $failOnTerminate = false;
 
+    /**
+     * createAutoRechargeInvoice が invoice ID を返す**直前**に呼ばれる hook
+     * (`FakeRenderComposer::$duringCompose` と同じ作法)。
+     *
+     * 「Stripe 側の作成は成功したが、返る前に停止側が terminal 化した」
+     * = attach 0 行になる競合点を決定論的に再現するために使う。
+     */
+    public ?Closure $duringCreateInvoice = null;
+
     /** @var list<string> resolveSubscriptionPaymentMethod を要求された subscription id (T1004) */
     public array $resolvedSubscriptions = [];
 
@@ -113,6 +123,10 @@ public function createAutoRechargeInvoice(
         $invoiceId = 'in_'.substr(hash('sha256', $idempotencyKeyBase), 0, 24);
         $this->invoiceStatuses[$invoiceId] ??= 'draft';
 
+        if ($this->duringCreateInvoice !== null) {
+            ($this->duringCreateInvoice)($invoiceId);
+        }
+
         return $invoiceId;
     }
 
diff --git a/tests/Support/JobDedup/ExemptionEntry.php b/tests/Support/JobDedup/ExemptionEntry.php
new file mode 100644
index 0000000..8f05e0e
--- /dev/null
+++ b/tests/Support/JobDedup/ExemptionEntry.php
@@ -0,0 +1,20 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\JobDedup;
+
+use App\Enums\Security\JobDedupExemption;
+use Webmozart\Assert\Assert;
+
+/** 「重複実行の保証を持たないことが正しい」と裁定されたジョブの目録エントリ。 */
+final readonly class ExemptionEntry
+{
+    /** @param non-empty-string $rationale 30 文字以上 */
+    public function __construct(
+        public JobDedupExemption $exemption,
+        public string $rationale,
+    ) {
+        Assert::greaterThanEq(mb_strlen($rationale), 30, '免除の根拠は 30 文字以上で書くこと');
+    }
+}
diff --git a/tests/Support/JobDedup/GuaranteeEntry.php b/tests/Support/JobDedup/GuaranteeEntry.php
new file mode 100644
index 0000000..ceef0d7
--- /dev/null
+++ b/tests/Support/JobDedup/GuaranteeEntry.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\JobDedup;
+
+use App\Enums\Security\JobDedupGuarantee;
+use Webmozart\Assert\Assert;
+
+/** 保証側 (結果の一回性を持つ) ジョブの目録エントリ。 */
+final readonly class GuaranteeEntry
+{
+    /**
+     * @param  non-empty-list<JobDedupGuarantee>  $mechanisms  永続状態遷移の機構 (複数可)
+     * @param  non-empty-list<PreflightRequirement>  $preflights  外部呼び出しごとの再検証点 (複数可)
+     * @param  non-empty-string  $rationale  30 文字以上
+     *
+     * ★ `$mechanisms` が **複数登録できる**ことが要点。
+     *   ExecuteAutoRechargeAttemptJob は「台帳付与の一回性 = invoice 単位の冪等キー UNIQUE」と
+     *   「attempt 遷移の一回性 = 条件付き UPDATE」という**軸の違う 2 本の保証**を持つ。
+     *   単一 mechanism で書くと、どちらか一方が enum の適用条件に一致しない
+     *   誤った分類を型付き目録が保持してしまう。
+     * ★ `$preflights` も **複数**である。auto-recharge は `StripeInvoiceCreate` と
+     *   `StripeInvoicePay` の **2 つ**の外部呼び出しを持つ。1 件しか登録しないと、
+     *   もう一方の preflight を削除しても gate が green のままになり
+     *   「目録からすべての外部呼び出しを辿れる」が成立しない。
+     */
+    public function __construct(
+        public array $mechanisms,
+        public array $preflights,
+        public string $rationale,
+    ) {
+        Assert::notEmpty($mechanisms, '保証機構を 1 つ以上登録すること');
+        Assert::notEmpty($preflights, 'preflight を 1 つ以上登録すること (無いなら NoExternalCall)');
+        Assert::greaterThanEq(mb_strlen($rationale), 30, '保証側の根拠は 30 文字以上で書くこと');
+    }
+}
diff --git a/tests/Support/JobDedup/NoExternalCall.php b/tests/Support/JobDedup/NoExternalCall.php
new file mode 100644
index 0000000..6b2ece1
--- /dev/null
+++ b/tests/Support/JobDedup/NoExternalCall.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\JobDedup;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * 「preflight を要する外部呼び出しを持たない」という目録上の宣言。
+ *
+ * ★ここでいう「外部呼び出し」は `App\Enums\Security\ExternalCallKind` の定義域、すなわち
+ *   **取り消せない外部副作用を持つ呼び出し**である。ローカル CPU (ffmpeg)・冪等な読み取り
+ *   (S3 GET / invoice retrieve)・状態検査で冪等化された終端 (invoice void/delete) は
+ *   定義域外であり、本 case で宣言してよい。**その旨を根拠文に必ず書く**
+ *   (「外部 API を 1 本も呼ばない」と「preflight を要する呼び出しが無い」は別の主張)。
+ */
+final readonly class NoExternalCall implements PreflightRequirement
+{
+    /**
+     * @param  non-empty-string  $rationale  「preflight を要する外部呼び出しを持たない」根拠 (30 文字以上)
+     *
+     * ★根拠文は日本語のため `mb_strlen()` で**文字数**を数える (`strlen()` はバイト数になる)。
+     *   mbstring は Laravel の必須拡張であり、既存 ThrottleCoverageInventoryTest も同方式。
+     */
+    public function __construct(public string $rationale)
+    {
+        Assert::greaterThanEq(mb_strlen($rationale), 30, '「外部呼び出しなし」の根拠は 30 文字以上で書くこと');
+    }
+}
diff --git a/tests/Support/JobDedup/PreflightCheckpoint.php b/tests/Support/JobDedup/PreflightCheckpoint.php
new file mode 100644
index 0000000..9b994a1
--- /dev/null
+++ b/tests/Support/JobDedup/PreflightCheckpoint.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\JobDedup;
+
+use App\Enums\Security\ExternalCallKind;
+
+/**
+ * 「この外部呼び出しの直前に、この再検証点がある」という目録上の宣言。
+ *
+ * ★ gate が固定できるのは **再検証点の実在と戻り型まで**である。
+ *   「外部呼び出しの直前で呼ばれていること」(配置) は Feature テストの担当。
+ */
+final readonly class PreflightCheckpoint implements PreflightRequirement
+{
+    /**
+     * @param  class-string  $verifierClass  再検証を行うクラス
+     * @param  non-empty-string  $verifierMethod  再検証メソッド
+     */
+    public function __construct(
+        public string $verifierClass,
+        public string $verifierMethod,
+        public ExternalCallKind $externalCall,
+        public PreflightControlFlow $controlFlow,
+    ) {}
+
+    /** gate が要求する戻り型名 */
+    public function expectedReturnType(): string
+    {
+        return match ($this->controlFlow) {
+            PreflightControlFlow::ThrowsOnLoss => 'void',
+            PreflightControlFlow::ReturnsBoolean => 'bool',
+        };
+    }
+}
diff --git a/tests/Support/JobDedup/PreflightControlFlow.php b/tests/Support/JobDedup/PreflightControlFlow.php
new file mode 100644
index 0000000..5ab84e4
--- /dev/null
+++ b/tests/Support/JobDedup/PreflightControlFlow.php
@@ -0,0 +1,21 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\JobDedup;
+
+/**
+ * preflight が所有権喪失をどう伝えるか。
+ *
+ * ★ Manual (例外で中断) と Billing (structured return) を**無理に統合しない**
+ *   (AGENTS.md 思考原則 4)。どちらであるかを目録が明示し、
+ *   gate はそれに一致する戻り型を要求する。
+ */
+enum PreflightControlFlow
+{
+    /** 失われていたら例外を投げる (戻り型 void)。Manual ドメイン */
+    case ThrowsOnLoss;
+
+    /** 送信してよいかを bool で返す (戻り型 bool)。Billing ドメイン */
+    case ReturnsBoolean;
+}
diff --git a/tests/Support/JobDedup/PreflightRequirement.php b/tests/Support/JobDedup/PreflightRequirement.php
new file mode 100644
index 0000000..de338e0
--- /dev/null
+++ b/tests/Support/JobDedup/PreflightRequirement.php
@@ -0,0 +1,16 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\JobDedup;
+
+/**
+ * preflight (外部呼び出し直前の再検証) の要求。
+ *
+ * ★実装は `PreflightCheckpoint` と `NoExternalCall` の **2 つだけ**に閉じる。
+ *   PHP には sealed type が無いため、実装集合の一致は
+ *   JobExecutionDedupInventoryTest が deny-by-default で検査する
+ *   (nullable にして「null = 外部呼び出しなし」とすると、新しい外部呼び出しを足しても
+ *    目録が green のままになりうる)。
+ */
+interface PreflightRequirement {}
diff --git a/tests/Support/QueuedJobPopulation.php b/tests/Support/QueuedJobPopulation.php
new file mode 100644
index 0000000..2a7543e
--- /dev/null
+++ b/tests/Support/QueuedJobPopulation.php
@@ -0,0 +1,90 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use FilesystemIterator;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use RecursiveDirectoryIterator;
+use RecursiveIteratorIterator;
+use ReflectionClass;
+use SplFileInfo;
+use Webmozart\Assert\Assert;
+
+/**
+ * 「キューに載るクラス」の母集団を決める**唯一の実装**。
+ *
+ * QueuedJobLeaseInventoryTest (接続 / リース期間の目録) と
+ * JobExecutionDedupInventoryTest (重複実行の保証の目録) が**同じ母集団**を見ることを
+ * 構造的に保証する (2 実装に分かれていると、片方だけ更新される drift が起きる)。
+ *
+ * 母集団判定の正本は `ReflectionClass::implementsInterface(ShouldQueue::class)` +
+ * `isInstantiable()`。親クラス / trait 経由の実装も拾うため Job だけでなく
+ * Mailable / Notification も自動的に母集団へ入る。
+ *
+ * ★ `class_exists()` により **autoload の副作用を伴う** (既存 QueuedJobLeaseInventoryTest の
+ *   方式をそのまま移設したもの)。token parser / composer classmap へ寄せる案はあるが、
+ *   既存 gate の振る舞いまで変わるため本設計では踏襲する (方式変更は独立した課題)。
+ */
+final class QueuedJobPopulation
+{
+    /** @return list<class-string> */
+    public static function shouldQueueClasses(): array
+    {
+        $classes = [];
+        foreach (self::appPhpFiles() as $path) {
+            $class = self::classNameForPath($path);
+            if (! class_exists($class)) {
+                continue;
+            }
+
+            $reflection = new ReflectionClass($class);
+            if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(ShouldQueue::class)) {
+                continue;
+            }
+
+            $classes[] = $reflection->getName();
+        }
+
+        sort($classes);
+
+        return $classes;
+    }
+
+    /**
+     * app/ 配下の PHP ファイル絶対パス一覧。
+     *
+     * @return list<string>
+     */
+    public static function appPhpFiles(): array
+    {
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS),
+        );
+
+        $paths = [];
+        foreach ($iterator as $file) {
+            Assert::isInstanceOf($file, SplFileInfo::class);
+            if (! $file->isFile() || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $paths[] = $file->getPathname();
+        }
+
+        sort($paths);
+
+        return $paths;
+    }
+
+    /** app/ 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
+    public static function classNameForPath(string $path): string
+    {
+        $appPath = base_path('app').DIRECTORY_SEPARATOR;
+        Assert::startsWith($path, $appPath, "app/ 配下ではないパスです: {$path}");
+
+        $relative = substr($path, strlen($appPath), -strlen('.php'));
+
+        return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
+    }
+}

```

## テスト結果

- `composer test`: **3481 passed / 0 failed / 2 skipped** (3483 tests, 13303 assertions)
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1224 passed / 124 files) / `pnpm build`: すべて green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 passed): すべて green

### mutation による赤化確認 (詳細設計 S6 の M1〜M17。全件実施し、すべて元へ戻した)

| # | mutation | 赤化したテスト |
|---|---|---|
| M1 | 目録から RunManualAnalysis を削除 | 「キューに載る全クラスが保証側 or 免除に分類されている」ほか 2 件 |
| M2 | assertStillOwned をリネーム | 「preflight の再検証点が実在し…」 |
| M3 | assertStillOwned の戻り型 void→bool | 「…戻り型が目録の制御方式 (void) と一致しません」 |
| M3b | stillPending の戻り型 bool→void | 「…戻り型が目録の制御方式 (bool) と一致しません」 |
| M3c | mechanisms を空配列 | GuaranteeEntry の Assert (7 テスト error) |
| M4 | NoExternalCall の根拠を 10 文字 | constructor の Assert (7 テスト error) |
| M5 | LOCK_TTL_SECONDS=700 | 「org lock TTL は retry_after を下回る」 |
| M6 | uniqueFor=0 | 「uniqueFor は正の値である」 |
| M7 | 3 つ目の PreflightRequirement 実装を追加 | 「実装は 2 種類に閉じている」 |
| M8 / M8b | event 名 literal を single / double quote で直書き | 「固定 event 名の literal は…直書きされていない」 |
| M8c | preflights から StripeInvoiceCreate を削除 | 「登録済み checkpoint の種別集合が期待集合と一致する」 |
| M8d | 期待値 map から 1 クラス削除 | 「期待する外部呼び出し種別が全ジョブ分宣言されている」 |
| M9 | 免除を 1 件増やす | 「免除件数が全体 cap / case 別 cap と一致する」 |
| M10 | QUEUED_JOB_LEASE_INVENTORY から 1 件削除 | 「接続経路: キューに載る全クラスが目録に登録されている」 |
| M11 | AnalysisPipeline::writeProgress の where を外す | 「cron failed 後に step / progress が書き戻されない」 |
| M12 | RenderPipeline::updateProgress の where を外す | 同上 (Render) |
| M13 | stripe_invoice_id を素の save() に戻す | 「attach 0 行: invoice_id を書かず invoice を終端する」ほか |
| M14 | terminateUnattachedInvoice を Canceled 限定 | 「attach 0 行: failed へ遷移していた場合も終端する」 |
| M15 | cleanup ログの event 名を LOG_EVENT へ | 「後始末ログは別 event 名…」 |
| M16 | create 直前の stillPending() を削除 | 「配置: create の直前に preflight がある」ほか 2 件 |
| M17 | pay 直前の stillPending() を削除 | 「配置: 行が Pending のままなら create → pay が進む (回帰)」ほか 5 件 |

## 実装上の判断 (設計から補った点。ここを重点的に見てほしい)

1. 詳細設計は保証側の例として 3 ジョブ (RunManualAnalysis / RunManualRender /
   ExecuteAutoRechargeAttemptJob) しか示していない。母集団 18 件の残りを分類した結果、
   **HandleAutoRechargeChargeFailureJob を「免除」ではなく「保証側 + NoExternalCall」**に置いた。
   理由: 永続化はすべて AutoRechargeService へ委譲され、付与は `recharge:{invoiceId}` UNIQUE、
   遷移は `where status=pending` の条件付き UPDATE で守られている = **保証を持っている**ため、
   「保証を持たないことが正しい」という免除の定義に当てはまらない。
   その代わり、この Job が呼ぶ `retrieveInvoiceState` (冪等な読み取り) と `terminateInvoice`
   (状態検査で冪等化された所有権喪失**後**の後始末) は preflight の対象外であるとして
   `NoExternalCall` の根拠文に明記した。この分類は妥当か。
2. 残り 14 件の免除分類 (DuplicateDeliveryAccepted 8 / IdempotentDeletion 2 /
   ConvergentStateSync 3 / GuardedByDownstreamConstraint 1) と各根拠は妥当か。
   特に **課金関連の通知 4 件**を DuplicateDeliveryAccepted にした根拠
   (「重複受信しても二重の支払い操作を招かない」) は十分か。
3. 免除 cap を「以下」ではなく **exact 一致**で固定した (全体 14 / case 別も一致)。
4. Feature テストで他テストファイルのグローバル定数を参照していない
   (Pest `--parallel` はファイル単位でプロセスを分けるため)。
   その代償として必須キー集合の定数が Manual 側と Billing 側に**二重に**存在する。
   この重複は許容範囲か、より良い形があるか。
