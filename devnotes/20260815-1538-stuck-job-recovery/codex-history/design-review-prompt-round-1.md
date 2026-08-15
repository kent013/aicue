# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にPestテスト。既存テストの削除は禁止）
5. DTO パターンの遵守
6. 副作用・後退リスク
7. 波及変更の網羅性
8. セキュリティ（AGENTS.md のセキュリティ不変条件。とくに cross-org / 主キー同一性クエリの目録）
9. 運用可観測性（監視対象の語彙が変わることの影響）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: stuck-job-recovery (滞留回収の共通基盤への寄せ替え)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本改善の位置付け: 上記パイプラインの各段 (解析 / 撮影アップロード / レンダ) と、それが押さえる
資源 (チケット予約 / 支払い済みチケットの付与) が**止まったまま放置されない**ことを、
人手を介さずに担保する運用基盤である。

### 禁止事項 (AGENTS.md より。設計に効く核)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用
10. 既存テストの削除・上書き (本設計の最重要制約)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。`RefreshDatabase` は `tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 禁止、`--parallel` 実行
- テストデータは必ず Factory で生成
- DTO + JsonResource パターン (本設計は Console/Service 層のみで JsonResource は無関係)
- `declare(strict_types=1)` + 日本語コメント。transaction は Service 内
- 月/年の加減算は `*NoOverflow` を明示 (本設計は分単位のみで該当なし)
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

`devnotes/20260815-1538-stuck-job-recovery/conceptual-design.md` (Codex 合議 Round 4 で APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 回収の共通契約と語彙 | `app/Contracts/Recovery/StuckWorkStream.php` (新) / `app/Enums/Recovery/RecoveryStream.php` (新) / `app/Enums/Recovery/RecoveryOutcome.php` (新) / `app/DataTransferObjects/Recovery/StreamSweepResultDto.php` (新) | 高 |
| 2 | registry と sweeper (ページ送り・実効上限・例外) | `app/Services/Recovery/StuckWorkStreamRegistry.php` (新) / `app/Services/Recovery/StuckWorkRecoverySweeper.php` (新) | 高 |
| 3 | 入口コマンドと定期実行の差し替え | `app/Console/Commands/Operations/RecoverStuckWorkCommand.php` (新) / `routes/console.php` | 高 |
| 4 | 解析ジョブ stream への移設と旧実装撤去 | `app/Services/Recovery/Streams/StaleAnalysisJobStream.php` (新) / `app/Services/Manual/AnalysisJobService.php` | 高 |
| 5 | レンダジョブ stream への移設と旧実装撤去 | `app/Services/Recovery/Streams/StaleRenderJobStream.php` (新) / `app/Services/Manual/RenderJobService.php` | 高 |
| 6 | チケット予約 stream への移設と専用例外の新設 | `app/Services/Recovery/Streams/ExpiredTicketReservationStream.php` (新) / `app/Exceptions/Billing/ReservationNotReleasableException.php` (新) / `app/Services/Billing/TicketLedgerService.php` | 高 |
| 7 | Stripe webhook stream への移設と旧実装撤去 | `app/Services/Recovery/Streams/StaleWebhookEventStream.php` (新) / `app/Services/Billing/StripeWebhookProcessor.php` / `app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php` (削除) | 高 |
| 8 | 撮影アップロード予約 stream への移設と保持期間の分離 | `app/Services/Recovery/Streams/StaleUploadReservationStream.php` (新) / `app/Console/Commands/Capture/PurgeUploadReservationsCommand.php` (新) / `app/Services/Capture/StaleUploadReservationSweeper.php` (削除) | 高 |
| 9 | 目録 gate と撤去済み参照 gate | `tests/Architecture/StuckWorkRecoveryInventoryTest.php` (新) / `tests/Architecture/RetiredRecoveryReferenceGateTest.php` (新) / `tests/Support/Recovery/` (新) | 高 |
| 10 | 目録・docs の波及更新 | `tests/Support/Security/DirectFetchInventory.php` / `docs/architecture.md` / `docs/template-divergence.md` / `AGENTS.md` | 中 |

---

## 施策 1: 回収の共通契約と語彙

### 変更箇所

- 新規: `app/Contracts/Recovery/StuckWorkStream.php`
- 新規: `app/Enums/Recovery/RecoveryStream.php` (stream のキーと実行間隔)
- 新規: `app/Enums/Recovery/RecoveryOutcome.php` (結果の種類 5 値)
- 新規: `app/DataTransferObjects/Recovery/StreamSweepResultDto.php`

### 波及変更

- TypeScript 型定義: なし (フロントに露出しない)
- API Resource/DTO: なし (HTTP 経路を持たない)
- テストファイル: 施策 9 の目録 gate が enum の全 case を母集団にする

### 変更後コード

```php
// app/Enums/Recovery/RecoveryStream.php
declare(strict_types=1);

namespace App\Enums\Recovery;

/**
 * 滞留回収の対象系列。**キーはコマンド引数 (--stream) と Schedule と目録の同一性の基準**である
 * (定期実行は全て同じ work:recover-stuck なので、コマンド名では stream の欠落も重複も見えない)。
 */
enum RecoveryStream: string
{
    case AnalysisJob = 'analysis_job';
    case RenderJob = 'render_job';
    case TicketReservation = 'ticket_reservation';
    case WebhookEvent = 'webhook_event';
    case UploadReservation = 'upload_reservation';

    /** 定期実行の間隔 (分)。現行の cron の間隔をそのまま保存する */
    public function cadenceMinutes(): int
    {
        return match ($this) {
            self::AnalysisJob, self::RenderJob,
            self::TicketReservation, self::WebhookEvent => 5,
            self::UploadReservation => 10,
        };
    }
}
```

```php
// app/Enums/Recovery/RecoveryOutcome.php
declare(strict_types=1);

namespace App\Enums\Recovery;

/**
 * 回収 1 件の結果。**この 5 値がすべて**で、集計側は default の arm を持たない match で処理する。
 *
 * Recovered                 = 業務状態を前へ進めた
 * RecoveredWithCleanupFailure = 業務状態は前へ進めたが、付随する後始末に失敗した
 *                             (撮影アップロードの S3 削除失敗。件数を Recovered に畳まない)
 * Skipped                   = 競合・条件不成立で何もしなかった (正常事象。失敗ではない)
 * Deferred                  = 前へ進まなかったが次回の掃引へ残した (webhook の再実行失敗)
 * Escalated                 = 自動回収の対象外へ移し人手へ渡した (webhook の recovery_pending)
 */
enum RecoveryOutcome: string
{
    case Recovered = 'recovered';
    case RecoveredWithCleanupFailure = 'recovered_with_cleanup_failure';
    case Skipped = 'skipped';
    case Deferred = 'deferred';
    case Escalated = 'escalated';
}
```

```php
// app/Contracts/Recovery/StuckWorkStream.php
declare(strict_types=1);

namespace App\Contracts\Recovery;

use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use Carbon\CarbonImmutable;

/**
 * 滞留回収の 1 系列。**候補は主キーだけを返し、回収は id しか受け取らない**。
 * 行の内容を持ち回れないので、回収側は必ず行を取り直して述語を再評価することになる
 * (= 候補を集めた後に正常へ進んだものを誤って失敗にする事故が構造的に起きない)。
 *
 * $sweptAt は掃引の開始時刻で、候補列挙と再評価で**同じ現在時刻**を使うために渡す
 * (行の内容ではないので上の強制は壊れない)。
 */
interface StuckWorkStream
{
    public function stream(): RecoveryStream;

    /**
     * 候補の主キーを昇順で最大 $pageSize 件返す ($afterId より大きいものだけ)。
     *
     * @param  positive-int|null  $afterId  前ページの最後の主キー (先頭ページは null)
     * @param  positive-int  $pageSize
     * @return list<positive-int>
     */
    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array;

    /**
     * 1 件を回収する。**行を取り直して述語を再評価する責務はこの実装側にある**。
     *
     * 競合・条件不成立は例外ではなく RecoveryOutcome::Skipped を返す。
     * 例外を投げてよいのは本当の不変条件違反だけで、sweeper が report して次へ進む。
     *
     * @param  positive-int  $id
     */
    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome;

    /**
     * 1 回の掃引で処理する件数の上限 (null = 無制限)。
     * 撮影アップロードだけが 500 を申告する (S3 の I/O を有界にする既存の判断)。
     *
     * @return positive-int|null
     */
    public function sweepItemLimit(): ?int;
}
```

```php
// app/DataTransferObjects/Recovery/StreamSweepResultDto.php
declare(strict_types=1);

namespace App\DataTransferObjects\Recovery;

use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;

/**
 * stream 1 本の掃引結果。**任意メタデータ領域は持たせない**
 * (WebhookRecoveryResultDto と同じ方針。型で分からない領域を作ると id 等が運用ログへ漏れる)。
 *
 * $limitReached は「上限に達し、かつ**未処理の候補が実在する**」ときだけ true にする
 * (ちょうど上限件数で候補が尽きた場合は false = 打ち切りではない)。
 */
final readonly class StreamSweepResultDto
{
    /** @param  array<value-of<RecoveryOutcome>, int<0, max>>  $outcomes */
    public function __construct(
        public RecoveryStream $stream,
        public bool $applied,
        public int $candidates,
        public array $outcomes,
        public int $failures,
        public bool $limitReached,
    ) {}

    public function count(RecoveryOutcome $outcome): int
    {
        return $this->outcomes[$outcome->value] ?? 0;
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`array` は `list<positive-int>` / `array<value-of<...>, int<0, max>>` で narrowing)
- [x] null 安全 (`?int $afterId` は先頭ページのみ null。Assert は不要な形にする)
- [x] DTO を返している (配列返却は enum キーの件数表のみで、`array shape` を phpdoc で固定)
- [x] Generics の型パラメータが正しい (Collection を戻り値に使わず `list<>` で閉じる)

### テスト計画

- [ ] 新規 `tests/Unit/Recovery/RecoveryStreamEnumTest.php` — `cadenceMinutes()` が 5 stream すべてに
      値を返す (match の網羅。case 追加時に fail する)
- [ ] 新規 `tests/Unit/Recovery/StreamSweepResultDtoTest.php` — `count()` が未登録 outcome で 0 を返す /
      `limitReached` が構築値をそのまま保持する
- [ ] 個別の `DatabaseTransactions` を使わない (DB に触れない Unit テスト)

### リスク

- enum の case 追加が施策 9 の目録・施策 3 の Schedule ループの両方に波及する。
  これは意図した設計 (増やすときに必ず 2 箇所を見る) であり、gate の失敗メッセージで導線を出す

---

## 施策 2: registry と sweeper

### 変更箇所

- 新規: `app/Services/Recovery/StuckWorkStreamRegistry.php`
- 新規: `app/Services/Recovery/StuckWorkRecoverySweeper.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 新規 Feature テスト (下記) と施策 9 の gate

### 変更後コード

```php
// app/Services/Recovery/StuckWorkStreamRegistry.php
final class StuckWorkStreamRegistry
{
    /** @var array<value-of<RecoveryStream>, StuckWorkStream> */
    private array $streams;

    public function __construct(
        StaleAnalysisJobStream $analysisJobs,
        StaleRenderJobStream $renderJobs,
        ExpiredTicketReservationStream $ticketReservations,
        StaleWebhookEventStream $webhookEvents,
        StaleUploadReservationStream $uploadReservations,
    ) {
        // ★ 解決は enum 起点。stream 側の stream() と登録キーの不一致は下の Assert が落とす
        $this->streams = [];
        foreach ([$analysisJobs, $renderJobs, $ticketReservations, $webhookEvents, $uploadReservations] as $stream) {
            $this->streams[$stream->stream()->value] = $stream;
        }
        Assert::count($this->streams, count(RecoveryStream::cases()), 'stream の登録に重複または欠落があります');
    }

    public function get(RecoveryStream $stream): StuckWorkStream
    {
        return $this->streams[$stream->value];
    }

    /** @return list<StuckWorkStream> */
    public function all(): array
    {
        return array_values($this->streams);
    }
}
```

```php
// app/Services/Recovery/StuckWorkRecoverySweeper.php
final class StuckWorkRecoverySweeper
{
    /** 1 度に取り出す候補の件数 (メモリ上界であって、掃引全体の上限ではない) */
    private const int PAGE_SIZE = 200;

    /**
     * stream 1 本を掃引する。
     *
     * @param  positive-int|null  $limitOverride  手動実行の --limit
     */
    public function sweep(StuckWorkStream $stream, bool $apply, ?int $limitOverride = null): StreamSweepResultDto
    {
        $sweptAt = CarbonImmutable::now();
        $limit = $this->effectiveLimit($stream->sweepItemLimit(), $limitOverride);

        $outcomes = [];
        $candidates = 0;
        $failures = 0;
        $afterId = null;

        while ($limit === null || $candidates < $limit) {
            $pageSize = $limit === null ? self::PAGE_SIZE : min(self::PAGE_SIZE, $limit - $candidates);
            $ids = $stream->candidateIds($sweptAt, $afterId, $pageSize);
            if ($ids === []) {
                // 候補が尽きた = 打ち切りではない
                return $this->result($stream, $apply, $candidates, $outcomes, $failures, limitReached: false);
            }

            foreach ($ids as $id) {
                $candidates++;
                $afterId = $id;
                if (! $apply) {
                    continue; // dry-run は数えるだけ (recover を 1 度も呼ばない)
                }
                try {
                    $outcome = $stream->recover($id, $sweptAt);
                    $outcomes[$outcome->value] = ($outcomes[$outcome->value] ?? 0) + 1;
                } catch (Throwable $exception) {
                    // 1 件の失敗で掃引を止めない。ただし終了コードでは隠さない (呼び出し側が判定)
                    $failures++;
                    report($exception);
                }
            }
        }

        // 上限に達した。**未処理の候補が実在するときだけ**打ち切りとして記録する
        $hasMore = $stream->candidateIds($sweptAt, $afterId, 1) !== [];

        return $this->result($stream, $apply, $candidates, $outcomes, $failures, limitReached: $hasMore);
    }

    /** 実効上限 = min(--limit, stream の申告)。どちらも無指定なら無制限 */
    private function effectiveLimit(?int $streamLimit, ?int $override): ?int
    {
        return match (true) {
            $streamLimit === null => $override,
            $override === null => $streamLimit,
            default => min($streamLimit, $override),
        };
    }
}
```

### 設計上の要点 (レビュー済みの確定事項)

1. **ページ送りであり総件数の上限ではない**。先頭に居座って毎回例外になる行があっても、
   カーソルが跨いで前進するので後続に手が届く (総件数の上限にすると新しい滞留を作る)
2. **上限は 1 箇所でしか適用しない** (`effectiveLimit`)。stream 実装側は上限を知らない
3. **`limitReached` は「未処理の候補が実在する」ときだけ true**。ちょうど上限件数で
   候補が尽きたケースを打ち切りと報告しない (運用の誤読を作らない)
4. dry-run は `recover()` を 1 度も呼ばない。**回収されるはずの件数は出せない**
   (webhook の回収は受理そのものが書き込みのため)。出力には候補件数だけを出す
5. sweeper 自身はトランザクションを張らない (行ロックと再評価は各 stream の責務)

### PHPStan 適合チェック

- [x] `?int` の三分岐を `match(true)` で網羅 (null 安全)
- [x] `report()` の引数は `Throwable` で型が閉じる
- [x] 件数表は `array<value-of<RecoveryOutcome>, int<0, max>>` を phpdoc で宣言し DTO へ渡す

### テスト計画

- [ ] 新規 `tests/Feature/Recovery/StuckWorkRecoverySweeperTest.php` (fake stream を使う):
  - [ ] **公平性**: ページサイズを超える候補があり先頭の 1 件が毎回例外を投げても、
        後続の全件が同じ掃引で `recover` される (fail-first で先に書く)
  - [ ] **dry-run**: `apply=false` で `recover` が 1 度も呼ばれず、候補件数だけが数えられる
  - [ ] **例外の扱い**: 1 件の例外が掃引を止めず `failures` が 1 になる
  - [ ] **実効上限**: `min(--limit, sweepItemLimit)` が適用される (両方 null なら全件)
  - [ ] **打ち切りの区別**: 候補がちょうど上限件数のときは `limitReached=false`、
        上限より 1 件でも多いときは true
  - [ ] **同一の現在時刻**: `candidateIds` と `recover` に渡る `$sweptAt` が同一インスタンス
- [ ] 個別の `DatabaseTransactions` は使わない (グローバル `RefreshDatabase` に従う)

### リスク

- ページ送りは「候補の述語が真である間だけ id が単調に前進する」ことに依存する。
  回収に成功した行は候補から外れるので、同じ id を 2 度処理することはない
- 掃引中に新しい滞留が生まれると同じ掃引で拾われることがある (害はない。次回でも拾える)

---

## 施策 3: 入口コマンドと定期実行の差し替え

### 変更箇所

- 新規: `app/Console/Commands/Operations/RecoverStuckWorkCommand.php`
- 変更: `routes/console.php` (L26-40 / L42-73 / L200-230 付近の 5 ブロックを撤去し、1 ループへ)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `tests/Feature/Projects/AnalysisRecoverStaleJobsTest.php` /
  `tests/Feature/Manual/RenderStaleRecoveryTest.php` /
  `tests/Feature/Capture/StaleReservationSweepTest.php` /
  `tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` の
  **コマンド名を参照している箇所**を新コマンドへ張り替える (テストは 1 本も消さない)
- docs: `docs/architecture.md` の 5 箇所 (旧コマンド名) を新コマンドへ更新 (施策 10)

### 変更後コード

```php
// app/Console/Commands/Operations/RecoverStuckWorkCommand.php
final class RecoverStuckWorkCommand extends Command
{
    /**
     * --stream 省略時は全 stream を掃引する。--apply が無ければ dry-run (候補を数えるだけ)。
     * --limit は**手動実行の試し打ち用**の総件数上限で、付けると先頭側しか見ない。
     */
    protected $signature = 'work:recover-stuck
        {--stream= : 対象の系列 (省略時は全系列)}
        {--limit= : 1 系列あたりの処理件数上限 (手動実行用。既定は無制限)}
        {--apply : 実際に回収する (既定は dry-run)}';

    protected $description = '滞留した処理・予約を回収する (既定 dry-run)';

    public function handle(StuckWorkStreamRegistry $registry, StuckWorkRecoverySweeper $sweeper): int
    {
        $streams = $this->resolveStreams($registry); // 未知の --stream は FAILURE で返す
        if ($streams === null) {
            return self::FAILURE;
        }
        $limit = $this->resolveLimit(); // 1 未満・非数値は FAILURE

        $failures = 0;
        foreach ($streams as $stream) {
            $result = $sweeper->sweep($stream, (bool) $this->option('apply'), $limit);
            $failures += $result->failures;
            $this->line($this->format($result));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** 1 行 1 stream。dry-run のときは候補が「実際に回収される件数の上界」であることを明示する */
    private function format(StreamSweepResultDto $result): string
    {
        return sprintf(
            '%s: mode=%s candidates=%d%s recovered=%d cleanup-failed=%d skipped=%d deferred=%d escalated=%d errors=%d limit-reached=%s',
            $result->stream->value,
            $result->applied ? 'apply' : 'dry-run (candidates は回収件数の上界)',
            $result->candidates,
            $result->applied ? '' : '',
            $result->count(RecoveryOutcome::Recovered),
            $result->count(RecoveryOutcome::RecoveredWithCleanupFailure),
            $result->count(RecoveryOutcome::Skipped),
            $result->count(RecoveryOutcome::Deferred),
            $result->count(RecoveryOutcome::Escalated),
            $result->failures,
            $result->limitReached ? 'yes' : 'no',
        );
    }
}
```

```php
// routes/console.php (差し替え後。旧 5 ブロックは撤去する)
/*
|--------------------------------------------------------------------------
| 滞留回収 (AG-083 標準形 v1)
|--------------------------------------------------------------------------
| 系列ごとに 1 本ずつ登録する (実行間隔は RecoveryStream::cadenceMinutes が正本)。
| **--apply の付け忘れは回収が全面停止しても無音**なので、配線は
| StuckWorkRecoveryInventoryTest が stream キー単位で機械固定する。
|
| 監視対象 (必須): 各実行の出力と onFailure。
|   - errors > 0 が続く   = 特定の行で回収が失敗し続けている
|   - limit-reached=yes が続く = 上限で打ち切っており後続候補が残っている
|   - escalated の件数    = 自動回収の対象外として人手へ渡した件数 (webhook)
*/
foreach (RecoveryStream::cases() as $recoveryStream) {
    Schedule::command('work:recover-stuck --stream='.$recoveryStream->value.' --apply')
        ->cron('*/'.$recoveryStream->cadenceMinutes().' * * * *')
        ->onOneServer()
        ->withoutOverlapping()
        ->onFailure(static fn () => report(new RuntimeException(
            'work:recover-stuck --stream='.$recoveryStream->value.' 失敗 — 滞留が前へ進んでいない可能性',
        )));
}
```

### 設計上の要点

- **既定 dry-run + Schedule は `--apply` 明示**。付け忘れは無音の全面停止なので gate 必須 (施策 9)
- 実行間隔は現行を 1 分も変えない (5 分 ×4 / 10 分 ×1)。`cron()` 表記にするのは
  enum の値から機械的に組み立てるため (`everyFiveMinutes()` は動的に選べない)
- `onOneServer()` / `withoutOverlapping()` を**全系列に揃える** (現在は webhook と
  撮影アップロードだけが持つ)。これらは cache ロックを前提にする既存前提と同じ
- `onFailure` → `report()` を全系列に付ける (現在は webhook のみ)。回収が止まっていることが
  無音にならないようにする

### PHPStan 適合チェック

- [x] `$this->option()` の戻り値 (`mixed`) は `resolveLimit()` / `resolveStreams()` で
      `positive-int|null` / `list<StuckWorkStream>|null` へ narrowing してから使う
- [x] `RuntimeException` は `routes/console.php` に namespace 宣言が無いため
      **import しない** (`NoNonCompoundGlobalUseTest` の規約)
- [x] 網羅 `match` で outcome を出力 (default arm を作らない)

### テスト計画

- [ ] 新規 `tests/Feature/Console/RecoverStuckWorkCommandTest.php`:
  - [ ] `--apply` 無しで実行すると DB が 1 バイトも変わらない (滞留を作ってから実行して確認)
  - [ ] `--stream` に未知の値を渡すと FAILURE で、有効値の一覧が出力される
  - [ ] `--limit=0` / 非数値は FAILURE (誤操作で全件走行しない)
  - [ ] 出力に 5 系列の行が出る (`--stream` 省略時)
  - [ ] 1 件でも例外があれば終了コードが FAILURE (fake stream を差し込んで確認)
- [ ] 既存テストの張り替え (削除しない):
  - `AnalysisRecoverStaleJobsTest` の `artisan('analysis:recover-stale-jobs')` →
    `artisan('work:recover-stuck --stream=analysis_job --apply')`
  - `RenderStaleRecoveryTest` の command smoke も同様
  - `StaleReservationSweepTest` の cron テストは施策 8 で 2 本に分ける
- [ ] Schedule 登録の検査は施策 9 の gate が担う (本施策では重複して書かない)

### リスク

- **デプロイ時に旧コマンド名を叩く外部の仕掛け (手動 runbook / 監視) が残ると失敗する**。
  aicue にデプロイ定義は無く、cron は `routes/console.php` の Schedule のみなので影響は
  docs の記述だけ。施策 10 で同時に更新する
- `cron('*/5 * * * *')` は `everyFiveMinutes()` と同一表現 (Laravel の実装も同じ cron 式)

---

## 施策 4: 解析ジョブ stream への移設と旧実装撤去

### 変更箇所

- 新規: `app/Services/Recovery/Streams/StaleAnalysisJobStream.php`
- 変更: `app/Services/Manual/AnalysisJobService.php` (L168-204 の `recoverStale()` を撤去)

### 波及変更

- テストファイル: `tests/Feature/Projects/AnalysisRecoverStaleJobsTest.php` (5 テスト) /
  `tests/Feature/Projects/AnalysisPipelineTest.php` (3 テスト) /
  `tests/Feature/Notifications/ManualAnalysisNotificationTest.php` (1 テスト) の
  `app(AnalysisJobService::class)->recoverStale()` を stream 経由へ張り替える
- `tests/Support/Security/DirectFetchInventory.php` のキー更新 (施策 10)

### 現行コード

```php
// AnalysisJobService::recoverStale() (抜粋)
$threshold = CarbonImmutable::now()->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));
$staleIds = AnalysisJob::query()->where(/* queued: created_at / running: updated_at */)->pluck('id');
foreach ($staleIds as $id) {
    $job = AnalysisJob::query()->whereKey($id)->first();
    if ($job === null) { continue; }
    if ($this->failJob($job, '解析がタイムアウトしました。再実行してください。')) { $recovered++; }
}
return $recovered;
```

### 変更後コード

```php
// app/Services/Recovery/Streams/StaleAnalysisJobStream.php
/**
 * 滞留した AI 解析ジョブ。閾値は manual.analysis_stale_after_minutes (30 分) で、
 * queued は発生時刻 (created_at)、running は進捗時刻 (updated_at) を起点にする。
 * **閾値は config/manual.php に置いたまま**にする (ジョブの timeout < retry_after <
 * 予約 TTL <= 滞留閾値 の序列を AnalysisTimeBudgetInvariantTest が固定しているため。
 * 回収側 config へ移すと序列の情報源が 2 つに割れる)。
 */
final readonly class StaleAnalysisJobStream implements StuckWorkStream
{
    public function __construct(private AnalysisJobService $jobs) {}

    public function stream(): RecoveryStream
    {
        return RecoveryStream::AnalysisJob;
    }

    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
    {
        $threshold = $sweptAt->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));

        /** @var list<positive-int> $ids */
        $ids = AnalysisJob::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $queued) => $queued
                    ->where('status', JobStatus::Queued->value)->where('created_at', '<=', $threshold))
                ->orWhere(fn (Builder $running) => $running
                    ->where('status', JobStatus::Running->value)->where('updated_at', '<=', $threshold)))
            ->when($afterId !== null, fn (Builder $q) => $q->where('id', '>', $afterId))
            ->orderBy('id')
            ->limit($pageSize)
            ->pluck('id')
            ->all();

        return $ids;
    }

    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
    {
        $job = $this->lockCandidate($id);   // ★ 行を取り直す (述語の再評価は failJob の terminal guard)
        if ($job === null) {
            return RecoveryOutcome::Skipped;
        }

        return $this->jobs->failJob($job, '解析がタイムアウトしました。再実行してください。')
            ? RecoveryOutcome::Recovered
            : RecoveryOutcome::Skipped;   // 走査中に terminal へ先着された = 競合 (失敗ではない)
    }

    public function sweepItemLimit(): ?int
    {
        return null;
    }

    /** id は candidateIds が列挙した主キー。request からは受け取らない (DirectFetchInventory 登録済み) */
    private function lockCandidate(int $id): ?AnalysisJob
    {
        return AnalysisJob::query()->whereKey($id)->first();
    }
}
```

`AnalysisJobService::failJob()` は**変更しない** (pipeline の catch / `Job::failed` /
回収の 3 経路の合流点であり、行ロック + terminal guard による述語の再評価はここが正本)。

### PHPStan 適合チェック

- [x] `pluck('id')->all()` の戻り型は `list<positive-int>` を phpdoc で宣言
- [x] `failJob()` の戻り値 `bool` を網羅した三項で outcome に写像
- [x] `lockCandidate` は `?AnalysisJob` を返し null 分岐を明示

### テスト計画

- [ ] 既存 `AnalysisRecoverStaleJobsTest` の 5 テストを**維持**し、呼び出し口だけ
      `work:recover-stuck --stream=analysis_job --apply` へ張り替える
      (閾値の境界・冪等・terminal 先着・予約解放の検証内容は変えない)
- [ ] 既存 `AnalysisPipelineTest` の (D) 系 3 テスト (強制終了後の会計収束・順序非依存) は
      `app(StaleAnalysisJobStream::class)` 経由へ張り替えて維持
- [ ] 既存 `ManualAnalysisNotificationTest` の「recoverStale 経由の失敗も通知が 1 件発火する」を維持
- [ ] 新規: `candidateIds` がページ送りで昇順・`$afterId` より大きい id だけを返す

### リスク

- `failJob()` の呼び出し元が 1 つ減るだけで挙動は同じ。通知の発火条件も不変
- `AnalysisJobService` の docblock (L170 の「TicketLedgerService::releaseStale と同型」) は
  参照先が消えるため書き換えが必要 (撤去 gate が literal を検出する)

---

## 施策 5: レンダジョブ stream への移設と旧実装撤去

### 変更箇所

- 新規: `app/Services/Recovery/Streams/StaleRenderJobStream.php`
- 変更: `app/Services/Manual/RenderJobService.php` (L260-302 の `recoverStale()` を撤去)

### 波及変更

- テストファイル: `tests/Feature/Manual/RenderStaleRecoveryTest.php` (6 テスト) /
  `tests/Feature/Notifications/ManualRenderNotificationTest.php` (1 テスト)
- `tests/Support/Security/DirectFetchInventory.php` のキー更新 (施策 10)

### 変更後コード

施策 4 と同型。**閾値だけが 2 本に分かれる** (queued=10 分 / running=30 分) ので
`candidateIds()` の WHERE で 2 つの閾値を使う。`recover()` は
`failJob($job, RenderErrorCode::Timeout, '書き出しがタイムアウトしました。再実行してください。')`
の戻り値を `Recovered` / `Skipped` に写像する。`sweepItemLimit()` は `null`。

```php
$queuedThreshold  = $sweptAt->subMinutes(config()->integer('manual.render_queued_stale_after_minutes'));
$runningThreshold = $sweptAt->subMinutes(config()->integer('manual.render_stale_after_minutes'));
```

**`RenderJobService::reconcileOutputs()` は移設しない** (世代交代済み出力の消し込みであって
滞留の前進ではない)。施策 9 の目録に「回収ではない定期実行」として理由付きで登録する。

### PHPStan 適合チェック

- [x] `RenderErrorCode::Timeout` の enum 引数を明示
- [x] 2 つの閾値をそれぞれ `CarbonImmutable` で保持 (`subMinutes` は overflow 対象外)

### テスト計画

- [ ] 既存 `RenderStaleRecoveryTest` の 6 テストを維持 (queued 10 分 / running 30 分の境界・
      preview の扱い・冪等・command smoke)。command smoke は新コマンドへ張り替える
- [ ] 既存 `ManualRenderNotificationTest` の「recoverStale 経由の render 失敗も通知される」を維持
- [ ] 新規: preview と render の両方が同じ閾値規則で候補になることを stream 単位で確認

### リスク

- `RenderJobService` の docblock (L36 / L170 / L264) と `RunManualRender` の docblock (L24) が
  `recoverStale` を名指ししているため書き換えが要る (撤去 gate が検出する)

---

## 施策 6: チケット予約 stream への移設と専用例外の新設

### 変更箇所

- 新規: `app/Services/Recovery/Streams/ExpiredTicketReservationStream.php`
- 新規: `app/Exceptions/Billing/ReservationNotReleasableException.php`
- 変更: `app/Services/Billing/TicketLedgerService.php`
  (L589-626 の `releaseStale()` を撤去 / L644-658 `lockReservationRow()` の例外型を差し替え /
  `expiredMonthlyHoldCondition()` は stream から使えるよう public 化)

### 波及変更

- テストファイル: `tests/Feature/Billing/TicketLedgerTest.php` (releaseStale のテスト) /
  `tests/Feature/Billing/TicketCommitWinsTest.php` /
  `tests/Feature/Projects/AnalysisPipelineTest.php` (順序非依存 2 テスト)
- `tests/Support/Security/DirectFetchInventory.php` のキー更新 (施策 10)

### 現行コード

```php
// lockReservationRow (抜粋)
if ($requireReserved && $locked->status !== TicketReservationStatus::Reserved) {
    throw new LogicException("予約 {$locked->id} は reserved ではありません (status: {$locked->status->value})");
}
```

### 変更後コード

```php
// app/Exceptions/Billing/ReservationNotReleasableException.php
/**
 * 予約が reserved でないため解放できない (= 別の実行が先に commit / release した競合)。
 *
 * **LogicException を継承する**ので、既存の `catch (LogicException)`
 * (AnalysisJobService::failJob / RenderJobService::failJob の並行 release 握り) は
 * そのまま成立する。細分化の目的は、滞留回収が「競合 (Skipped)」と「本当の不変条件違反 (例外)」を
 * **型で**見分けられるようにすることである (メッセージ文字列で見分けない)。
 */
final class ReservationNotReleasableException extends LogicException {}
```

```php
// ExpiredTicketReservationStream::recover
public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
{
    $reservation = $this->findCandidate($id);
    if ($reservation === null) {
        return RecoveryOutcome::Skipped;
    }

    try {
        // release() 内の行ロック + reserved 検査が述語の再評価そのもの
        $this->tickets->release($reservation);

        return RecoveryOutcome::Recovered;
    } catch (ReservationNotReleasableException) {
        return RecoveryOutcome::Skipped; // 並行 commit / release 済み = 正常事象
    }
    // ★ 他の LogicException / Throwable は握らない (sweeper が report して次へ進む)
}
```

候補列挙は現行 `releaseStale()` の WHERE をそのまま移設する
(`status=reserved` かつ (`expires_at <= $sweptAt` または失効 monthly hold))。
失効 monthly hold の条件式は `TicketLedgerService` に残し、public メソッドとして呼ぶ
(**会計の判定式を stream へ複製しない**)。

### PHPStan 適合チェック

- [x] `catch (ReservationNotReleasableException)` は変数を取らない形で unused を作らない
- [x] `expiredMonthlyHoldCondition()` の可視性変更に伴い `Builder` 型を phpdoc で明示
- [x] `findCandidate` は `?TicketReservation` を返す

### テスト計画

- [ ] 既存 `TicketLedgerTest` の「releaseStale は expires_at 超過の reserved だけを解放する」を
      維持し、stream 経由へ張り替える
- [ ] 既存 `TicketCommitWinsTest` (commit-wins と stale 解放の競合) を維持
- [ ] 既存 `AnalysisPipelineTest` の順序非依存 2 テスト
      (`failJob → 回収 → 解放` / 逆順で最終会計状態が同じ) を維持
- [ ] 新規: 候補列挙後に別プロセスが commit した予約は `Skipped` になり、
      コマンドは成功で終わる (運用アラートを鳴らさない)
- [ ] 新規: `release()` が reserved でない予約に対して
      `ReservationNotReleasableException` を投げる (型の細分化そのものの behavioral 固定)

### リスク

- 例外型の細分化は既存 `catch (LogicException)` を壊さない (継承関係)。
  ただし `expect(...)->toThrow(LogicException::class)` を書いている既存テストがあれば
  そのまま緑になる (サブクラスは instanceof を満たす)
- 失効 monthly hold の判定式を public 化すると「会計の判定式が外から呼べる」ようになる。
  呼び出し元を stream 1 箇所に限る旨を docblock に書き、施策 9 の目録に記録する

---

## 施策 7: Stripe webhook stream への移設と旧実装撤去

### 変更箇所

- 新規: `app/Services/Recovery/Streams/StaleWebhookEventStream.php`
- 変更: `app/Services/Billing/StripeWebhookProcessor.php`
  (L194-267 の `recoverStale()` を撤去し、1 件処理の口 `recoverStaleEvent()` を公開 /
  `claimStale()` を `whereKey` ベースへ)
- 削除: `app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php`
  (掃引全体の結果は `StreamSweepResultDto` が持つ)

### 波及変更

- テストファイル: `tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` /
  `tests/Feature/Billing/WebhookReplaySafetyTest.php`
- docs: `docs/architecture.md` §Stripe webhook の滞留回収 の監視対象 3 点の語彙更新 (施策 10)
- `tests/Support/Security/DirectFetchInventory.php` に新エントリ (whereKey 化するため)

### 変更後コード

```php
// StripeWebhookProcessor (公開する 1 件処理の口)
/**
 * 滞留 1 件の回収。**掃引 (列挙とループ) は滞留回収の共通基盤が持つ**ので、
 * 本メソッドは 1 件だけを受け持つ。判断材料と決着の規則は従来どおり:
 *   - 再実行してよいかは HandledStripeWebhookEvent::replaySafety() だけが決める
 *   - 回収の失敗は終局させない (received のまま次回へ回す = Deferred)
 *   - 対象外・試行上限は recovery_pending へ置いて止める (= Escalated)
 */
public function recoverStaleEvent(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
{
    $threshold = $sweptAt->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));

    $claim = $this->claimStale($id, $threshold);
    if ($claim === null) {
        return RecoveryOutcome::Skipped;
    }
    if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
        $this->reportRecoveryPending($claim);

        return RecoveryOutcome::Escalated;
    }

    try {
        $this->process($claim->type, $claim->payload);
    } catch (Throwable $exception) {
        report($exception);

        return $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
            ? RecoveryOutcome::Deferred
            : RecoveryOutcome::Skipped;
    }

    return $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
        ? RecoveryOutcome::Recovered
        : RecoveryOutcome::Skipped;
}
```

`claimStale()` は主キーで引く形へ変える (候補が `event_id` ではなく主キーになるため)。
**滞留の再検証を WHERE に入れる形は変えない** (ロック取得後に述語が再評価されるのが要点):

```php
$record = StripeWebhookEvent::query()
    ->whereKey($id)
    ->where('status', WebhookEventStatus::Received->value)
    ->where('updated_at', '<=', $threshold)
    ->lockForUpdate()
    ->first();
```

stream 側は候補列挙と委譲だけを持つ:

```php
public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
{
    $threshold = $sweptAt->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));
    // failed は Stripe の再送が再試行の駆動者なので拾わない (現行と同じ)
    return StripeWebhookEvent::query()
        ->where('status', WebhookEventStatus::Received->value)
        ->where('updated_at', '<=', $threshold)
        ->when($afterId !== null, fn (Builder $q) => $q->where('id', '>', $afterId))
        ->orderBy('id')->limit($pageSize)->pluck('id')->all();
}
```

### 結果の種類の対応 (docs の監視対象と 1 対 1)

| 旧 (WebhookRecoveryResultDto) | 新 (RecoveryOutcome) |
|---|---|
| `replayed` | `Recovered` |
| `retryScheduled` | `Deferred` |
| `movedToRecoveryPending` | `Escalated` |
| `skipped` | `Skipped` |

### PHPStan 適合チェック

- [x] `recoverStaleEvent` の戻り値は `RecoveryOutcome` (bool の三項を網羅)
- [x] `claimStale` のシグネチャ変更 (`string $eventId` → `int $id`) を呼び出し元 1 箇所に限る
- [x] `finalize()` は従来どおり `event_id` で引く (受理した世代を握る CAS の条件は変えない)

### テスト計画

- [ ] 既存 `StripeWebhookStaleRecoveryTest` の全テストを**維持**し、
      `recoverStale()` 呼び出しを stream / コマンド経由へ張り替える
      (再実行 → processed / 失敗 → received のまま Deferred / OrderSensitive → recovery_pending /
      未対応 type は processed / 世代を追い越された finalize は書かない、の各検証を変えない)
- [ ] 既存 `WebhookReplaySafetyTest` を維持
- [ ] 新規: 4 つの結果の種類が上表のとおりコマンド出力に現れる
      (運用監視の語彙が置き換わったことの behavioral 固定)

### リスク

- **監視の語彙が変わる**ため docs の更新が必須 (施策 10)。運用者が
  `retry-scheduled` を探して見つからない状態を作らない
- `claimStale` の引数変更で、テストが `event_id` 前提で組み立てている箇所は
  主キー経由に直す必要がある (テストの意味は変えない)

---

## 施策 8: 撮影アップロード予約 stream への移設と保持期間の分離

### 変更箇所

- 新規: `app/Services/Recovery/Streams/StaleUploadReservationStream.php`
- 新規: `app/Console/Commands/Capture/PurgeUploadReservationsCommand.php`
  (`capture:purge-upload-reservations`。日次)
- 削除: `app/Services/Capture/StaleUploadReservationSweeper.php`
- 変更: `routes/console.php` (旧 cron 撤去 + purge の Schedule 追加)

### 波及変更

- テストファイル: `tests/Feature/Capture/StaleReservationSweepTest.php` の 8 テストを
  **2 ファイルへ分けて全数維持** (回収 6 + 保持期間 2)。1 本も消さない
- `tests/Support/Security/DirectFetchInventory.php` のキー更新 (施策 10)
- docs: `docs/architecture.md` §撮影 PWA の孤児掃除 cron の記述更新 (施策 10)

### 現行コードの責務

`StaleUploadReservationSweeper::sweep()` に 3 つが同居している:
(a) 期限切れ pending / stale verifying の released 化 (CAS)、
(b) 未登録 S3 オブジェクトの削除、
(c) released/completed の retention 超過行の物理削除。

### 変更後コード

```php
// StaleUploadReservationStream::recover
public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
{
    $cutoff = $sweptAt->subMinutes(config()->integer('capture.stale_verifying_minutes'));

    // ★ 述語の再評価は条件付き UPDATE の WHERE that が担う (現行 CAS をそのまま移設)。
    //   行の内容を持ち回らないので、列挙後に登録処理が completed 化していれば 0 行更新になる。
    $reservation = $this->findCandidate($id);
    if ($reservation === null) {
        return RecoveryOutcome::Skipped;
    }
    $won = TakeUploadReservation::query()
        ->whereKey($id)
        ->where(/* pending: status + expires_at <= $sweptAt / verifying: status + updated_at < $cutoff */)
        ->update(['status' => TakeUploadReservationStatus::Released]);
    if ($won === 0) {
        return RecoveryOutcome::Skipped; // 登録処理が勝った (completed) = 正当な Take の実体
    }

    try {
        if ($this->storage->exists($reservation->video_path)) {
            $this->storage->delete($reservation->video_path); // 未登録オブジェクトの孤児削除
        }
    } catch (Throwable $exception) {
        // 枠の解放は巻き戻さない (利用者の枠を人質にしない)。件数として観測できる形で残す
        report($exception);

        return RecoveryOutcome::RecoveredWithCleanupFailure;
    }

    return RecoveryOutcome::Recovered;
}

/** S3 の存在確認・削除の I/O を有界にするための既存の上限 (500)。公平性は保証しない */
public function sweepItemLimit(): ?int
{
    return 500;
}
```

```php
// app/Console/Commands/Capture/PurgeUploadReservationsCommand.php
/**
 * 解放済み / 登録済みのアップロード予約のうち、保持期間 (capture.released_reservation_retention_days)
 * を超えた行を物理削除する。**これは滞留回収ではなく保持期間の決着**なので、
 * 回収 (work:recover-stuck) とは別の入口に分ける (既存の inquiry:purge / idempotency:prune と同じ位置付け)。
 */
protected $signature = 'capture:purge-upload-reservations';
```

### PHPStan 適合チェック

- [x] `storage->exists()` / `delete()` の例外は `Throwable` で捕捉して型を閉じる
- [x] `update()` の戻り値 `int` を 0 判定に使う (bool 変換しない)
- [x] 削除件数は `int` を返して出力する

### テスト計画

- [ ] 既存 `StaleReservationSweepTest` の 8 テストを分割して**全数維持**:
  - 回収側 (新 `tests/Feature/Capture/StaleUploadReservationRecoveryTest.php`):
    期限切れ pending の解放 + 削除 / `exists=false` なら delete を呼ばない /
    fresh verifying に触れない / stale verifying の解放 / 冪等 (2 回目は 0 件) /
    CAS 競合 (列挙後 completed 化された行は上書きも削除もしない)
  - 保持期間側 (新 `tests/Feature/Capture/PurgeUploadReservationsTest.php`):
    retention 超過の released/completed を物理削除 / fresh は残す
  - cron テストは 2 本に分ける (`work:recover-stuck --stream=upload_reservation --apply` と
    `capture:purge-upload-reservations`)
- [ ] 新規: S3 削除が例外になったとき、行は released のままで結果が
      `RecoveredWithCleanupFailure` として 1 件数えられる (掃引は止まらない)
- [ ] 新規: 500 件の上限に達し、かつ後続候補が実在するとき `limit-reached=yes` が出力される

### リスク

- **保持期間の削除が別コマンドになるため、実行間隔が 10 分毎から日次へ変わる**。
  物理削除は肥大防止であり緊急性が無いので日次で十分 (既存の purge 系 4 本と同じ)
- S3 削除失敗時に行が released のまま残るため、**未削除オブジェクトは自動では拾えない**。
  これは現行実装でも同じ (現行は例外が cron 全体を止めるので、むしろ悪化していない)。
  「保証しないもの」として docs に明記する

---

## 施策 9: 目録 gate と撤去済み参照 gate

### 変更箇所

- 新規: `tests/Architecture/StuckWorkRecoveryInventoryTest.php`
- 新規: `tests/Architecture/RetiredRecoveryReferenceGateTest.php`
- 新規: `tests/Support/Recovery/RecoveryStreamEntry.php` (stream ごとの申告)
- 新規: `tests/Support/Recovery/NonRecoveryScheduleEntry.php` (回収でない定期実行の申告)
- 新規: `app/Enums/Recovery/NonRecoveryScheduleReasonKind.php` (区分。理由の自由文は 30 文字以上)

### 目録 gate が固定すること (deny-by-default / exact-fit)

1. **registry の stream 集合 == `RecoveryStream` の全 case == 目録の申告集合**
   (未登録・重複・宣言だけで実装が無い、のいずれも落ちる)
2. **Schedule に載る `work:recover-stuck --stream=<key>` の集合が stream キーと一致する**。
   突き合わせはコマンド名ではなく **stream キー**で行う (全部が同じコマンド名のため)
3. 各 stream の Schedule が **`--apply` / `onOneServer()` / `withoutOverlapping()` /
   `onFailure()` の 4 点**と、目録が申告する実行間隔を持つ
   (**`--apply` の付け忘れは無音で回収を全面停止させるため、この検査が本 gate の主目的**)
4. 各 stream の `sweepItemLimit()` が目録の申告値と一致する
   (撮影アップロードだけ 500、他 4 本は無指定)
5. 各 stream が**取りうる結果の種類**を目録で申告している (自由文字列を作らない)
6. **Schedule に載っている全コマンド**は、上の stream 入口か、
   `NonRecoveryScheduleEntry` (区分 + 30 文字以上の理由) のどちらかに属する。
   未分類は fail = **6 本目の独自回収を素通しで足せない**

登録済みの「回収でない定期実行」(区分と理由の要旨):

| コマンド | 区分 | 理由の要旨 |
|---|---|---|
| `billing:reconcile-auto-recharge` | 外部との突き合わせ | Stripe を真実として収束させる 5 分岐で、DB の状態だけでは行き先が決まらない |
| `billing:reconcile-schedules` | 外部との突き合わせ | 予約 (Schedule) の作りかけを Stripe と突き合わせる |
| `billing:reconcile-subscription-status` | 外部との突き合わせ | 契約状態を Stripe を真実として日次で収束させる |
| `render:reconcile-outputs` | 生成物の後始末 | 世代交代済み出力の削除ジョブ再投入であり滞留の前進ではない |
| `billing:send-billing-reminders` | 通知 | 更新予告の送信 |
| `billing:detect-orphan-billing-organizations` | 検知のみ | 状態を書かず報告する |
| `inquiry:purge` / `idempotency:prune` / `account:purge-deletion-requests` / `billing:purge-retention-expired` / `capture:purge-upload-reservations` | 保持期間の決着 | 期限を過ぎた記録の削除・畳み込み |

### 撤去済み参照 gate が固定すること

- 撤去した 5 つのコマンド名 (`analysis:recover-stale-jobs` / `render:recover-stale-jobs` /
  `billing:release-stale-reservations` / `billing:recover-stale-webhook-events` /
  `capture:release-stale-upload-reservations`) と 5 つのメソッド名
  (`recoverStale` / `releaseStale` / `StaleUploadReservationSweeper` / `sweep()` の当該参照 /
  `WebhookRecoveryResultDto`) の literal が
  `app/` `routes/` `config/` `tests/` と docs の運用正本に**現れない**
- **走査対象から外す**: `devnotes/` と `docs/TODO-closed.md` (過去の記録であり書き換えさせない)

### 保証しないもの (誇張しない。gate の docblock に書く)

- 目録は**申告の集合一致**を見るだけで、`recover()` が実際に行ロック下で述語を
  再評価しているかは検査できない (それは各 stream の Feature テストが担う)
- Schedule の検査は**登録内容**を見るだけで、scheduler が実際に動いているかは検査できない
  (運用側の監視対象)
- 撤去 gate は literal 照合なので、動的に組み立てた文字列 (`'analysis:'.$suffix`) には沈黙する

### テスト計画

- [ ] gate 自身が vacuous でないことを示す: 目録から 1 件消す / `--apply` を外す /
      `sweepItemLimit` の値を変える、の 3 変異で赤くなることを実装時に手で確認し、
      失敗メッセージに次の作業 (登録先と理由) を書く
- [ ] 個別の `DatabaseTransactions` は使わない (Architecture レーン)

### リスク

- 新しい定期実行を足すたびに目録の更新が要る。これは意図した摩擦 (deny-by-default) であり、
  失敗メッセージで登録先を案内する

---

## 施策 10: 目録・docs の波及更新

### 変更箇所

- `tests/Support/Security/DirectFetchInventory.php` — 4 エントリのキーと分類を更新し 1 件追加
- `docs/architecture.md` — 5 箇所のコマンド名 + 監視対象の語彙 + 新セクション
- `docs/template-divergence.md` — 正典からの意図的な逸脱 3 件
- `AGENTS.md` — ドメイン固有規約に「滞留回収の単一入口と目録」を 1 項追加

### DirectFetchInventory の更新内容

旧 4 エントリ (`TicketLedgerService#releaseStale` / `StaleUploadReservationSweeper#sweep` /
`AnalysisJobService#recoverStale` / `RenderJobService#recoverStale`) は**メソッドごと消える**ので
削除し、移設先で登録し直す。

**分類は `IdDerivedFromSameMethodQuery` から `IdSuppliedByInternalCaller` へ変わる** —
候補の列挙 (`candidateIds`) と主キーでの取り直し (`recover`) が別メソッドになるため、
「同一メソッド内の走査クエリ由来」という前提が成り立たなくなる (成り立たない分類を
そのまま流用しない)。新分類の適用条件に合わせ、各 stream は主キーの取り直しを
**private ヘルパ** (`findCandidate` / `lockCandidate`) に置き、`calledBy` に
`App\Services\Recovery\Streams\...::recover` を書く。

webhook は `claimStale()` を `whereKey` 化するため**新規に 1 件**登録する
(calledBy = `App\Services\Billing\StripeWebhookProcessor::recoverStaleEvent`)。

### docs/architecture.md の更新

- §AI 解析 / §レンダ / §撮影 PWA / §Stripe webhook の滞留回収 の
  コマンド名を `work:recover-stuck --stream=<key>` へ差し替える
- §Stripe webhook の滞留回収 の監視対象 3 点を新しい語彙へ言い換える
  (`retry-scheduled` → `deferred`、`recovery_pending 件数` → `escalated` と行の件数)
- 新セクション **§滞留回収の共通基盤** を追加し、次を正本として書く:
  stream 契約 / 実効上限とページ送りの違い / dry-run が数えるものの意味 /
  結果の種類 5 値 / 監視対象 (`errors` / `limit-reached` / `escalated`) /
  **保証しないもの** (撮影アップロードの 500 件上限は公平性を保証しない /
  S3 削除に失敗した孤児オブジェクトは自動では拾えない /
  dry-run の候補件数は実際に回収される件数の上界にすぎない /
  scheduler が動いていることは gate では保証しない)

### docs/template-divergence.md に記録する逸脱 3 件

1. **閾値を `config/recovery.php` に集約しない**。aicue は
   ジョブの `timeout` < `retry_after` < 予約 TTL ≤ 滞留閾値 の序列を
   既存の Architecture テスト 2 本で固定しており、回収側 config へ移すと
   序列の情報源が 2 つに割れるため。保証し続ける不変条件: 閾値はドメイン config に置き、
   序列テストを緑に保つ
2. **`recover()` に掃引開始時刻を渡す**。正典は id だけを渡すが、それだと候補列挙と
   再評価で現在時刻がずれ、境界の行が取りこぼされる。渡すのは行の内容ではないので
   「再取得と再評価の強制」は壊れない。保証し続ける不変条件: `recover()` の引数は
   主キーと時刻だけで、行・モデル・述語の判定結果を渡さない
3. **look-back / give-up の閾値を持たない**。look-back は「古すぎる滞留を永久に
   回収しない」無音の穴を作るため採らない。give-up に当たる機構は webhook の
   `attempts >= 8` が既に持つ。保証し続ける不変条件: 共通側が持つ上限は
   「1 掃引で扱う件数」だけで、それは対象を失敗として確定する条件ではない

### テスト計画

- [ ] `ModelDirectFetchInvariantTest` が新しい分類の適用条件 (private + 引数由来 +
      request accessor 無し + `calledBy` の実在) を満たすことを実走で確認する
- [ ] docs の更新は撤去 gate (施策 9) が旧コマンド名の残存を機械的に検出する
- [ ] `AGENTS.md` の追記に対応する gate は施策 9 の 2 本 (規約だけを増やさない)

### リスク

- `IdSuppliedByInternalCaller` は「呼び出し元の provenance を機械証明できない」case であり、
  根拠文の質に依存する。列挙が同一クラス内 (`candidateIds`) にあることを根拠文に明記する

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `routes/console.php`・課金/解析/レンダ/撮影の 4 ドメインの Service・Architecture テスト・docs を横断して触り、旧実装の撤去を同一 PR で行うため。他の設計 (同時進行の 5 件) と衝突しやすい |
| 競合リスク | `routes/console.php` と `docs/architecture.md` は他施策も触る可能性が高い。`tests/Support/Security/DirectFetchInventory.php` も共有目録なのでマージ時に注意 |

## 完了条件

- `composer test` / `composer phpstan` / `vendor/bin/pint --test` が全て緑
- 旧 5 コマンド・旧 5 メソッドがコードにも docs 正本にも 1 箇所も残っていない (撤去 gate が緑)
- 既存の回収系 Feature テストが 1 本も減っていない (張り替えのみ)


---

## 参考: 承認済みの概念設計 (Codex 合議 Round 4 で APPROVED)

# 概念設計: stuck-job-recovery (滞留回収の共通基盤への寄せ替え)

## 背景・課題

### 1. 台帳 (lctl) 側の事実

feature `stuck-job-recovery` の裁定 AG-083 (2026-08-06) は「雛形を置くだけではなく、実装そのものを
標準形 v1 へ統一する」と決めた。標準形が求めるのは 3 点 — **回収の共通基盤 / 既存回収の寄せ替え /
定期に外から叩く入口**。aicue のセルはこれまで「追従元 (laravel-claude-template) に共通基盤が
無いので寄せる先が存在しない」という理由で pending だった。

2026-08-10 の差分巡回でこの理由は失効した。laravel-claude-template:T076 が共通基盤を入れ、
既存の期限切れ予約解放を同一基盤へ移設して旧実装を撤去している (= 寄せ替えの参照実装が家系に
存在する)。したがって aicue の pending 理由は「基盤が無いから成立しない」ではなく
**「基盤はできたので着手できる (未着手)」** に変わった。

正典 (laravel-claude-template) の構成:

- `StuckWorkStream` 契約 — `candidateIds()` が主キーだけを返し `recover()` が id しか
  受け取らない。**行ロック下での述語再評価が構造的に強制される**のが要点
- `StuckWorkRecoverySweeper` / `StuckWorkStreamRegistry` — 走査枠と作用枠を分ける
- `work:recover-stuck {--stream} {--limit} {--apply}` — 既定 dry-run、Schedule 3 本
- `config/recovery.php` + `RecoveryThresholds` — stall は進捗時刻起点、give-up と look-back は
  発生時刻起点と起点を分ける
- deny-by-default の stream 台帳 gate / 撤去済み参照の再流入を止める gate

### 2. aicue 側の事実 (実読で確認した)

回収の入口は**現在 5 本**あり、それぞれ独立に実装されている
(監督セッションの観測は 3 本だったが、実読すると解析ジョブと撮影アップロード予約の
2 本が加わる。**寄せ替えの対象範囲はこの 5 本で確定させる**)。

| # | 入口 (routes/console.php) | 実体 | 周期 | 滞留の判定 | 述語の再評価方式 |
|---|---|---|---|---|---|
| 1 | `analysis:recover-stale-jobs` | `AnalysisJobService::recoverStale()` | 5 分 | queued: `created_at` / running: `updated_at` が 30 分超過 | `failJob()` 内の `lockForUpdate` + terminal guard |
| 2 | `render:recover-stale-jobs` | `RenderJobService::recoverStale()` | 5 分 | queued 10 分 / running 30 分 | 同上 |
| 3 | `billing:release-stale-reservations` | `TicketLedgerService::releaseStale()` | 5 分 | `expires_at` 超過 / 失効 monthly hold | `release()` 内の行ロック + status 検査 (不成立は `LogicException`) |
| 4 | `billing:recover-stale-webhook-events` | `StripeWebhookProcessor::recoverStale()` | 5 分 | `received` かつ `updated_at` が 15 分超過 | `claimStale()` の WHERE 付き `lockForUpdate` |
| 5 | `capture:release-stale-upload-reservations` | `StaleUploadReservationSweeper::sweep()` | 10 分 | pending の `expires_at` / verifying の `updated_at` | 条件付き UPDATE (CAS) |

5 本が共有しているもの: 候補を列挙 → 1 件ずつ取り直して条件を再確認 → 件数を返す、という同じ作法。

5 本でばらついているもの (= 今回の課題):

1. **述語の再評価が 3 通りの機構**で書かれている (行ロック + guard / 例外 / 条件付き UPDATE)。
   どれも正しいが、6 本目を書く人がどれを写すかは書き手次第で、**間違えても静かに壊れる**
   (正常に動いていたものを失敗にする事故はエラーにならない)
2. **1 回の実行で扱う件数の上限を持つのは 5 番だけ** (500 件)。他は全件を 1 回で処理する
3. **試し打ち (dry-run) の手段が 1 本も無い**。本番で「いま何が回収対象なのか」を
   副作用なしに見ることができない
4. **回収結果の語彙がばらばら** (件数 int が 4 本、4 つの内訳を持つ DTO が 1 本)
5. **回収の入口が存在することを機械的に強制する仕組みが無い**。定期実行を 1 本足すときに、
   それが回収なのか突き合わせなのか保持期間の削除なのかを申告させる場所が無く、
   **6 本目を素通しで足せる**
6. **cron の失敗が運用アラートに載るのは 4 番だけ**。1〜3・5 は失敗しても無音である
   (回収が止まっていることに誰も気づけない = 本 feature の存在理由そのものが穴になる)

### 3. なぜ今か

4 番 (Stripe webhook の滞留回収) は本日 T162 で入ったばかりで、その設計は
「既存 2 つと同じ作法を採り、共通の回収基盤は作らない」と明記して着地している
(`devnotes/20260815-1109-stripe-webhook-stuck-recovery/`)。本設計はその判断を正面から見直す。

T162 の判断は当時の前提 (aicue にも家系にも共通基盤が無い) の下では妥当だった。前提が変わった
今、同じ判断を続けると 6 本目・7 本目が同じ理由で増える。**3 本目が生えた直後の今が寄せ替えの
好機**であり、家系の他リポジトリ (motivation) では「基盤ができた直後に寄せ替えではなく 2 本目の
独自回収が増えた」という乖離の拡大が実際に観測されている (台帳 2026-08-12 の巡回記録)。

## 改善アイデア

**5 本の回収経路を 1 つの契約・1 つの入口・1 つの目録へ寄せ、旧実装を同じ PR で撤去する。**

### 取るもの (正典から aicue に持ち込む)

1. **stream 契約**: `candidateIds()` は主キーだけを返し、`recover()` は id (と掃引開始時刻) しか
   受け取らない。行の内容を持ち回れないので、**再取得と述語の再評価が構造的に強制される**。
   これが正典の核であり、aicue が本当に必要としている唯一の部分である
2. **走査枠と作用枠を分けた sweeper と registry**: 1 回の実行で扱う件数の上限と、
   結果の集計をここに 1 箇所だけ持つ
3. **単一の入口コマンド** `work:recover-stuck --stream= --limit= --apply` (既定 dry-run)
4. **deny-by-default の目録 gate**: 登録された stream の集合と、定期実行に載っている
   コマンドの集合を突き合わせ、どちらにも属さないものを落とす
5. **撤去済み参照の再流入を止める gate**: 撤去した 5 本のコマンド名とメソッド名が
   コードにも docs にも戻ってこないことを機械で固定する

### 取らないもの (aicue には要らない / 入れると悪くなる)

1. **`config/recovery.php` への閾値の集約**。aicue の滞留閾値は既にドメインの config にあり、
   **ジョブの `timeout` < キューの `retry_after` < 予約 TTL ≤ 滞留閾値**という序列を
   Architecture テスト 2 本 (`AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest`)
   が固定している。回収側の config へ移すと**序列の情報源が 2 つに割れる**。
   → stream が自分のドメインの config を読む形にする (正典からの意図的な逸脱として記録する)
2. **look-back (遡及の下限)**。入れると「古すぎる滞留は永久に回収されない」という
   新しい無音の穴が生まれる。件数が問題になった実測が無い段階で入れるのは
   「今必要なものだけ作る」に反する
3. **give-up の共通機構**。「自走をやめる条件」は webhook が既に持っている
   (`attempts >= 8` で `recovery_pending` へ置き、**失敗として確定はしない**)。
   共通側に持たせるのは「1 回の実行で扱う件数の上限」だけにし、上限に達しても
   対象を失敗にせず次回の実行へ残す (正典が言う区別と同じ)
4. **正典と同じ形の結果 DTO**。webhook は 4 つの内訳 (再実行済み / 次回へ回した /
   回収待ちへ置いた / 何もしなかった) を **docs/architecture.md が監視の必須項目として
   宣言済み**なので、共通側は「結果の種類ごとの件数」を stream ごとに保持する形にする

### 寄せ替えない経路 (目録に理由付きで登録し、混ぜない)

「滞留した業務状態を前へ進める」ことと、「外部と突き合わせる」「保持期間を決着させる」ことは
別の概念である (似ているからで統合しない)。次は stream にしない:

- `billing:reconcile-auto-recharge` / `billing:reconcile-schedules` /
  `billing:reconcile-subscription-status` — Stripe を真実として収束させる突き合わせ。
  DB の状態だけでは行き先が決まらない
- `render:reconcile-outputs` — 世代交代済みの出力を消し込む後始末であり、滞留の前進ではない
- `inquiry:purge` / `idempotency:prune` / `account:purge-deletion-requests` /
  `billing:purge-retention-expired` — 保持期間の決着
- `billing:detect-orphan-billing-organizations` / `billing:send-billing-reminders` — 検知・通知

### 5 番 (撮影アップロード予約) の扱い

現行の `sweep()` は 3 つの責務が 1 メソッドに同居している:
(a) 期限切れ予約の解放、(b) 未登録 S3 オブジェクトの削除、(c) 古い行の物理削除。
(a)(b) は滞留回収なので stream にし、(c) は保持期間の決着なので
`capture:purge-upload-reservations` (日次) へ分ける。aicue には既に保持期間の決着コマンドが
4 本あり、分けたほうが既存の作法に揃う。新コマンドの新設は**本改善の目的ではなく、
1 メソッドに同居した 3 責務を解体するために必要な最小限**である (機能追加ではない)。

外部副作用 (S3 削除) の扱いは次を契約とする:

- **候補の正本は DB だけ**にする (S3 を列挙しない)。stream は
  `take_upload_reservations` の主キーだけを候補にする
- 解放 (条件付き UPDATE) に勝った実行だけが S3 削除へ進む (現行の CAS をそのまま移設する)
- **S3 削除の失敗は解放を巻き戻さない**。行は解放済みのまま、結果の種類は
  `RecoveredWithCleanupFailure` とし (`Recovered` に畳まない)、削除失敗は `report()` にも
  載せる。件数がコマンド出力と結果 DTO に残るので、**未削除オブジェクトの増加を
  集計から観測できる** (枠の解放は人質にしない)。行は解放済みになるため
  次回の掃引では候補にならない = **未削除オブジェクトは自動では拾えない**ことを
  「保証しないもの」として docs に明記する

## 契約の確定事項 (Round 1 レビューを受けて明文化する)

概念段階で決めておかないと詳細設計が分岐するため、次を契約として先に固定する。

1. **候補列挙はページ送りにする (総件数の上限にはしない)**。契約は
   `candidateIds(CarbonImmutable $sweptAt, ?positive-int $afterId, positive-int $pageSize): list<positive-int>`
   とし、`id > $afterId` の主キー昇順で `pageSize` 件までを返す。sweeper は最後に見た id を
   次ページの開始点にして、候補が尽きるまで 1 回の掃引の中で回す。
   - **上限をページの大きさに限る理由**: 「1 回の掃引で N 件まで」という総件数の上限にすると、
     先頭に居座る 1 件が毎回例外になったとき、後続の行が永久に処理されない
     (回収基盤そのものが回収を止める)。ページ送りなら例外になった行を跨いで
     カーソルが前進するので、その掃引の中で全候補に手が届く
   - **総件数の上限は 2 つの出所しか持たず、sweeper が 1 箇所で適用する**
     (ページの大きさと混同しない):
     `実効上限 = min(コマンドの --limit, stream が申告する 1 掃引の上限)`。
     どちらも指定が無ければ無制限 (= 現行挙動)。
     `--limit` は**手動実行の試し打ち用**で、上限を付けた実行は先頭側しか見ないことを
     help に書く。stream 側の申告は registry の型付きメタデータ
     `sweepItemLimit(): ?positive-int` で持ち、目録 gate が
     「撮影アップロードだけ 500、他 4 本は無指定」を固定する
   - 唯一 stream 側の上限を持つのは撮影アップロード予約 stream で、**現行の 500 件上限を
     維持する** (S3 の存在確認・削除の I/O を有界にするための既存の判断)。
     この stream は**公平性を保証しない** — 恒常的に例外になる行が 500 件並べば
     後続は進まない。これは現行実装が既に持つ制約であり、隠さずに次の 3 つで扱う:
     (a) 上限内で例外が起きてもそのページの残りは処理する、
     (b) **上限に到達したこと**を結果 DTO とコマンド出力に必ず残す、
     (c) 「500 件上限は公平性を保証しない既存制約である」ことを
     `docs/architecture.md` に明記する。後続が未処理である事実を成功件数だけで隠さない
   - 候補の主キーはすべて bigint auto-increment なので `positive-int` に閉じる
     (`int|string` の union にはしない = PHPStan level 10 で緩まない)
2. **競合は例外ではなく結果の種類で返す**。`recover()` は述語が不成立になった競合
   (別プロセスが先に前進させた / 既に解放済み) を `Skipped` として返す。
   - `TicketLedgerService::release()` は現在いずれの不成立でも `LogicException` を投げるため、
     **競合だけを表す専用の例外型** `ReservationNotReleasableException` (`LogicException` を継承) を
     新設し、予約が reserved でないときだけこれを投げる。stream が `Skipped` へ変換するのは
     この型**だけ**で、他の `LogicException` は sweeper へ通す
     (メッセージ文字列で見分ける形にはしない)
   - 継承しているので、既存の `catch (LogicException)` 呼び出し
     (`AnalysisJobService::failJob` / `RenderJobService::failJob` の並行 release 握り) と
     既存テストはそのまま成立する (後方互換のための並走ではなく、型の細分化である)
   - 結果の種類は次の 5 つに閉じる。**stream ごとに「取りうる種類」を目録で申告**し、
     集計側は網羅 `match` で処理する (`default` の arm を作らない):
     `Recovered` (前へ進めた) / `RecoveredWithCleanupFailure` (業務状態は前へ進めたが
     付随する後始末に失敗した = 撮影アップロードの S3 削除失敗) /
     `Skipped` (競合・条件不成立で何もしなかった) / `Deferred` (前へ進まなかったが
     次回の掃引へ残した = webhook の再実行失敗) / `Escalated` (自動回収の対象外へ移し
     人手へ渡した = webhook の `recovery_pending`)
3. **1 件の失敗で掃引全体を止めない。ただし成功で隠さない**。sweeper は 1 件の
   `Throwable` を `report()` して次の候補へ進み、その実行で 1 件でも例外があれば
   コマンドの終了コードを失敗にする (= `Schedule::onFailure()` が発火する)
4. **dry-run が数えるのは候補件数だけ**で、`recover()` は 1 度も呼ばない。webhook の
   回収は受理そのものが書き込みなので、「回収されるはずの件数」を副作用なしに
   出すことはできない。出力には**実際に回収される件数の上界**であると明記する
   (できないことをできるように見せない)
5. **失敗通知の正本は `Schedule::onFailure()` → `report()`** とする (aicue の運用アラート
   経路は `report()` のみ。既存の webhook 回収・オートリチャージ突き合わせと同じ形)。
   **とくに `--apply` の付け忘れは回収が全面停止しても無音**なので、これは必須の gate である。
   gate が突き合わせるのは**コマンド名ではなく stream のキー**である
   (定期実行は全部が同じ `work:recover-stuck` なので、コマンド名の集合では
   stream の欠落も重複も検出できない):
   - registry に登録された stream キーの集合と、Schedule に載っている
     `--stream=<key>` の集合が**ちょうど一致する** (未登録・未定期実行・重複をそれぞれ落とす)
   - キーごとに `--apply` / `onOneServer()` / `withoutOverlapping()` / `onFailure()` の
     4 点と、目録が申告する実行間隔が付いていることを検査する
6. **撤去 gate の走査範囲**は `app/` `routes/` `config/` `tests/` と `docs/` の運用正本に限る。
   `devnotes/` と `docs/TODO-closed.md` は過去の記録なので対象外とする
   (歴史を書き換えさせない)

## 期待効果

- **使命への貢献**: 本改善が守るのは「SOP → シナリオ → 撮影 → レンダ」の各段が止まったときに、
  **人手を介さず前へ進む**という運用上の性質である。解析・レンダ・撮影アップロードの 3 本は
  パイプラインそのものの滞留で、課金予約の 2 本 (チケット予約 / Stripe webhook) は
  **その滞留で押さえたままになる利用枠と、支払い済みなのに付与されないチケットの回収**として
  同じ鎖に載っている。回収の入口が 5 本バラバラだと、1 本が静かに止まっても誰も気づけない。
  入口と目録を 1 つにすることで「止まった仕事が必ず前へ進む」ことの担保を 1 箇所にする
- **6 本目を素通しで足せなくする** (deny-by-default の目録)。これが本改善の主目的で、
  同型の実装が 4 リポジトリで独立に増えたという家系の実績がその必要性の証拠である
- **本番で試し打ちできる** (既定 dry-run)。回収は「正常に動いていたものを失敗にする」
  事故を起こしうる操作なので、副作用なしに対象を見られることに運用上の価値がある
- **cron の失敗が全 stream で運用アラートに載る** (現在は 1 本だけ)

## 実装方針（概要）

1. `App\Contracts\Recovery\StuckWorkStream` (契約) と
   `App\Enums\Recovery\RecoveryOutcome` (結果の語彙) を新設する
2. `App\Services\Recovery\StuckWorkStreamRegistry` (stream の解決) と
   `App\Services\Recovery\StuckWorkRecoverySweeper` (走査・上限・集計) を新設する
3. `App\Services\Recovery\Streams\` 配下に stream を 5 本置く。中身は既存の実装を移設し、
   業務判定 (何を滞留とみなすか・どう前へ進めるか) は各ドメインの Service に残す
4. `App\Console\Commands\Operations\RecoverStuckWorkCommand` (`work:recover-stuck`) を新設し、
   `routes/console.php` の 5 本の `Artisan::command` と `Schedule` を撤去して、
   stream ごとの `Schedule` (現行の周期を 1 本ずつ保存) に置き換える
5. `AnalysisJobService::recoverStale()` / `RenderJobService::recoverStale()` /
   `TicketLedgerService::releaseStale()` / `StripeWebhookProcessor::recoverStale()` /
   `StaleUploadReservationSweeper::sweep()` を**同じ PR で撤去**する (並走させない)
6. Architecture テストを 2 本追加する (目録 gate / 撤去済み参照 gate)。
   目録 gate は Schedule の配線 (`--apply` / `onOneServer` / `withoutOverlapping` /
   `onFailure`) もあわせて固定する
7. 既存の Feature テストは 1 本も消さず、呼び出し先を stream・新コマンドへ張り替えて維持する

**フロントへの影響は無い** (Svelte / Inertia props / TypeScript 型 / API の表面は 1 つも変わらない)。
変更は Console・Service・テストの 3 層に閉じる。

**テストで固定する不変条件 (詳細設計で施策ごとに割り付ける)**:

- **公平性**: 先頭の候補が毎回例外になっても、候補数が 1 ページを超える後続の行が
  同じ掃引の中で処理される (ページ送りの契約)
- **上限の適用と可視化**: `実効上限 = min(--limit, stream の申告)` が sweeper 1 箇所で
  適用され、上限に到達した掃引は結果 DTO とコマンド出力に「到達した」ことを残す
  (撮影アップロードの 500 件がその唯一の常設ケース)
- **dry-run の副作用ゼロ**: `--apply` 無しの実行で `recover()` が 1 度も呼ばれない
- **競合の扱い**: 候補列挙後に別プロセスが前進させた行は `Skipped` になり、
  コマンドは成功で終わる (運用アラートを鳴らさない)
- **例外の扱い**: 1 件の例外は掃引を止めず、その実行の終了コードは失敗になる
- **Schedule の配線**: stream キーの集合一致と 4 点 (`--apply` / `onOneServer` /
  `withoutOverlapping` / `onFailure`) + 実行間隔
- **移設の等価性**: 既存 5 経路の Feature テスト (閾値・冪等・競合・順序非依存・通知) を
  そのまま維持し、呼び出し口だけ差し替えて緑にする

**実装順序 (fail-first を保つための固定順)**:
共通契約と sweeper のテスト → 契約と sweeper 本体 → 低リスクな stream 3 本
(解析 / レンダ / チケット予約) → webhook stream → 撮影アップロードの責務分割 →
旧入口 5 本の撤去 → Schedule 配線と目録・撤去の 2 gate。

## 制約・前提

- **既存テストの削除は禁止事項**。5 経路のテスト (閾値・冪等・競合・順序非依存・通知) は
  すべて維持する。呼び出し口の張り替えのみ行う
- **後方互換の並走を残さない** (思考原則 3)。旧メソッド・旧コマンド名は同じ PR で消す
- 閾値の値は 1 つも変えない (`AnalysisTimeBudgetInvariantTest` /
  `RenderTimeBudgetInvariantTest` の序列をそのまま緑に保つ)
- 定期実行の**周期も変えない** (5 分 ×4 / 10 分 ×1)。統合するのは実装契約と入口であって
  実行間隔ではない
- `tests/Support/Security/DirectFetchInventory.php` に登録済みの 3 エントリ
  (主キー同一性クエリの分類) は、移設先のファイルパスへ**キーを更新**する必要がある
- テンプレートからの意図的な逸脱は `docs/template-divergence.md` に記録する

## スコープ外

- 突き合わせ (reconcile) 系 4 本・保持期間の決着系 4 本・検知系 2 本の寄せ替え
- 閾値の値そのものの見直し
- キュー投入経路の原子性 (別 feature `queue-dispatch-outbox` の範囲。aicue は既に
  業務トランザクション内 dispatch で決着済み)
- 台帳 (lctl) への書き戻し (監督セッションの責務)


---

## 関連する現行コード

### routes/console.php (回収まわり抜粋)

```php
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 課金 cron
|--------------------------------------------------------------------------
| reserve TTL 超過のチケット予約を解放する (2 フェーズ消費の前提となる stale 解放)。
*/
Artisan::command('billing:release-stale-reservations', function (TicketLedgerService $tickets) {
    $released = $tickets->releaseStale();
    $this->info("released {$released} stale reservation(s)");
})->purpose('期限切れ (expires_at 超過) のチケット予約を解放する');

Schedule::command('billing:release-stale-reservations')->everyFiveMinutes();

/*
|--------------------------------------------------------------------------
| Stripe webhook の滞留回収
|--------------------------------------------------------------------------
| 本処理中にプロセスが落ちて status='received' のまま残った記録を再処理へ戻す。
| 放置すると Stripe の再送は claim() に弾かれて 200 で終わり、Stripe 側も配信成功と
| 判断して再送を打ち切るため、決済済みチケットの付与が**無音で失われる**。
|
| **監視対象 (必須)**: 本コマンドの report() と、次の 3 つの件数。
|   1. status='received' かつ updated_at が滞留の閾値より古い行の件数
|      (増え続ける = scheduler か本コマンドが動いていない)
|   2. 本コマンド出力の retry-scheduled 件数 (再実行が失敗し続けている)
|   3. status='recovery_pending' の件数 (自動再実行の対象外として置かれた行。
|      理由は recovery_reason 列)
| 詳細は docs/architecture.md の「Stripe webhook の滞留回収」が正本。
*/
Artisan::command('billing:recover-stale-webhook-events', function (StripeWebhookProcessor $webhooks) {
    $result = $webhooks->recoverStale();
    $this->info(sprintf(
        'replayed %d / retry-scheduled %d / moved-to-recovery-pending %d / skipped %d',
        $result->replayed,
        $result->retryScheduled,
        $result->movedToRecoveryPending,
        $result->skipped,
    ));
})->purpose('処理中に滞留した Stripe webhook 記録を再処理へ戻す');

Schedule::command('billing:recover-stale-webhook-events')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:recover-stale-webhook-events 失敗 — 決済済み・チケット未付与が滞留する可能性',
    )));

/*
|--------------------------------------------------------------------------
| 課金 daily バッチ
...
| レンダ cron
|--------------------------------------------------------------------------
| recover-stale-jobs: dispatch 喪失 (queued=10 分) と worker 異常終了 (running=30 分) の回復。
| reconcile-outputs: 出力世代の収束 (世代交代済みの output_path を削除 job へ再投入。
| stale 回復とは別責務のため command を分離する)。
*/
Artisan::command('render:recover-stale-jobs', function (RenderJobService $jobs) {
    $recovered = $jobs->recoverStale();
    $this->info("recovered {$recovered} stale render job(s)");
})->purpose('滞留したレンダジョブ (queued/running が閾値超過) を失敗確定し予約を解放する');

Schedule::command('render:recover-stale-jobs')->everyFiveMinutes();

Artisan::command('render:reconcile-outputs', function (RenderJobService $jobs) {
    $result = $jobs->reconcileOutputs();
    $this->info("dispatched {$result['dispatched']} delete job(s), skipped {$result['skipped']}");
})->purpose('世代交代済みのレンダ出力を走査し S3 削除ジョブを再投入する (最新 1 世代へ収束)');

Schedule::command('render:reconcile-outputs')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| 撮影 PWA cron (doc/10 §10.8-4 / 概念設計 D7)
|--------------------------------------------------------------------------
| 期限切れ pending / stale verifying のアップロード予約を released 化して
| bytes_pending を解放し、PUT 済みだが未登録の S3 孤児オブジェクトを削除する。
| fresh verifying (検証中) には触れない (登録処理の claim 契約と競合しない)。冪等。
*/
Artisan::command('capture:release-stale-upload-reservations', function (StaleUploadReservationSweeper $sweeper) {
    $released = $sweeper->sweep();
    $this->info("released {$released} stale upload reservation(s)");
})->purpose('期限切れのテイクアップロード予約を解放し S3 孤児オブジェクトを削除する');

Schedule::command('capture:release-stale-upload-reservations')->everyTenMinutes()->onOneServer()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| 冪等キーの保持期間 purge (T139)
|--------------------------------------------------------------------------
| 保持期間 (config idempotency.retention_hours) を超えた冪等キーを
| REST / MCP 両テーブルから物理削除する。claim 時の lazy delete だけでは
| 「二度と再送されなかったキー」が残り続け単調増加するため。
|
| **監視対象**: 本コマンドの report() (processing のまま期限切れ = 確定できなかった claim。
| プロセス強制終了か finalize 失敗の痕跡)。
```

### app/Services/Manual/AnalysisJobService.php::recoverStale

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
    }

```

### app/Services/Manual/RenderJobService.php::recoverStale

```php
    /**
     * stale ジョブの回復 (cron)。queued と running で閾値を分ける (概念設計 §5):
     * - queued: created_at が render_queued_stale_after_minutes (10 分) 超過
     *   (dispatch 喪失。render は enqueue 時点で編集を止めるため短 SLA で fail させる)
     * - running: updated_at が render_stale_after_minutes (30 分) 超過 (worker 異常終了)
     *
     * @return int 実際に回復 (failed 遷移) した件数
     */
    public function recoverStale(): int
    {
        $queuedThreshold = CarbonImmutable::now()
            ->subMinutes(config()->integer('manual.render_queued_stale_after_minutes'));
        $runningThreshold = CarbonImmutable::now()
            ->subMinutes(config()->integer('manual.render_stale_after_minutes'));

        $staleIds = RenderJob::query()
            ->where(function (Builder $query) use ($queuedThreshold, $runningThreshold): void {
                $query
                    ->where(function (Builder $query) use ($queuedThreshold): void {
                        $query->where('status', JobStatus::Queued->value)
                            ->where('created_at', '<=', $queuedThreshold);
                    })
                    ->orWhere(function (Builder $query) use ($runningThreshold): void {
                        $query->where('status', JobStatus::Running->value)
                            ->where('updated_at', '<=', $runningThreshold);
                    });
            })
            ->pluck('id');

        $recovered = 0;
        foreach ($staleIds as $id) {
            $job = RenderJob::query()->whereKey($id)->first();
            if ($job === null) {
                continue;
            }
            // failJob 内で行ロック + terminal guard 再検証するため、競合したジョブはそこで no-op (false)
            if ($this->failJob($job, RenderErrorCode::Timeout, '書き出しがタイムアウトしました。再実行してください。')) {
                $recovered++;
            }
        }

        return $recovered;
    }
```

### app/Services/Billing/TicketLedgerService.php (release / releaseStale / lockReservationRow)

```php
    public function release(TicketReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $locked = $this->lockReservationRow($reservation);
            $organization = $locked->organization;
            Assert::isInstanceOf($organization, Organization::class);
            $this->lockOrganizationRow($organization);

            $this->appendEntry(
                $organization,
                0,
                TicketLedgerKind::Release,
                $locked,
                "予約 {$locked->id} の解放",
            );

            $locked->status = TicketReservationStatus::Released;
            $locked->save();
        });

        $reservation->refresh();
    }

    /**
     * TTL (expires_at) 超過、または失効 monthly hold (consume_expires_at 経過) の reserved 予約を
     * 解放する (routes/console.php の billing:release-stale-reservations が 5 分毎に実行)。
     *
     * 失効 monthly hold を含めるのは、消費元の grant が既に失効している hold を拘束として
     * 残すと翌期間の残高を侵食するため (commit-wins も当該 hold は no-charge にする)。
     *
     * @return int 解放した予約数
     */
    public function releaseStale(): int
    {
        $now = CarbonImmutable::now();

        $staleIds = TicketReservation::query()
            ->where('status', TicketReservationStatus::Reserved)
            ->where(function (Builder $query) use ($now): void {
                $query->where('expires_at', '<=', $now)
                    ->orWhere(fn (Builder $expired) => $this->expiredMonthlyHoldCondition($expired, $now));
            })
            ->pluck('id');

        $released = 0;
        foreach ($staleIds as $id) {
            $reservation = TicketReservation::query()->whereKey($id)->first();
            if ($reservation === null) {
                continue;
            }
            // release 内で行ロック + 状態再検証するため、競合した予約はそこで弾かれる
            try {
                $this->release($reservation);
                $released++;
            } catch (LogicException) {
                // 並行 commit / release 済み: 解放不要
            }
        }

        return $released;
    }
...
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

        if ($requireReserved && $locked->status !== TicketReservationStatus::Reserved) {
            throw new LogicException(
                "予約 {$locked->id} は reserved ではありません (status: {$locked->status->value})",
            );
        }

        return $locked;
    }
```

### app/Services/Billing/StripeWebhookProcessor.php (recoverStale / claimStale / finalize)

```php
    /**
     * 受理した世代を握っている実行だけが行える条件付き書き込み (CAS)。
     *
     * `status='received'` かつ `attempts=受理時の値` の 1 行だけを更新する。
     * 0 件のときは**別の実行がその行を先に進めている** (滞留回収が claim し直した等) ので
     * 何も書かずに記録だけ残す — 旧ワーカーが新しい世代の結果を上書きしない
     * (ドメイン規約 6 の「条件付き UPDATE」)。
     *
     * `recovery_reason` は必ず NULL を置く
     * (不変条件: 非 NULL ⟺ status = recovery_pending)。
     *
     * **保証範囲を誇張しない**: これが守るのは `stripe_webhook_events` 行の世代だけである。
     * 旧ワーカーと回収側の `process()` は並行し得るので、付与の一回性は台帳の
     * `idempotency_key` UNIQUE と各ハンドラの終局 guard が担う。
     *
     * @param  WebhookEventStatus  $status  Processed (終局) / Failed (HTTP 経路の失敗) /
     *                                      Received (回収経路の失敗 = 終局させず次の回収へ回す)
     * @return bool 書き込めたら true
     */
    private function finalize(
        string $eventId,
        int $claimedAttempts,
        WebhookEventStatus $status,
        ?string $failureReason,
    ): bool {
        $updated = StripeWebhookEvent::query()
            ->where('event_id', $eventId)
            ->where('status', WebhookEventStatus::Received->value)
            ->where('attempts', $claimedAttempts)
            ->update([
                'status' => $status->value,
                'failure_reason' => $failureReason,
                'recovery_reason' => null,
                'processed_at' => $status === WebhookEventStatus::Processed
                    ? CarbonImmutable::now()
                    : null,
            ]);

        if ($updated !== 1) {
            Log::warning('stripe webhook: 別の実行が先に進めたため終局書き込みを見送った', [
                'event_id' => $eventId,
                'attempts' => $claimedAttempts,
                'status' => $status->value,
            ]);

            return false;
        }

        return true;
    }
...
    /**
     * 処理中に滞留した webhook 記録の回収 (cron: billing:recover-stale-webhook-events)。
     *
     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
     *
     * 作法は既存の滞留回収 (`RenderJobService::recoverStale` /
     * `TicketLedgerService::releaseStale`) と同じ = 対象を列挙 → 1 件ずつ行ロックで
     * 取り直して再検証 → 件数を返す。**共通の回収基盤は作らない** (ドメインごとの個別実装)。
     *
     * 通知 (`Log::warning` / `report()`) は**トランザクションの外**で出す
     * (状態が保存されていないのに通知だけ出る / 同じ行に複数回出るのを避ける)。
     * ただし commit 後に落ちれば 0 回になる = 送信を 1 回試みるだけで、
     * 厳密な一回配送は保証しない (常設の観測点は `recovery_pending` の件数のほう)。
     */
    public function recoverStale(): WebhookRecoveryResultDto
    {
        $threshold = CarbonImmutable::now()
            ->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));

        /** @var list<string> $staleEventIds */
        $staleEventIds = StripeWebhookEvent::query()
            ->where('status', WebhookEventStatus::Received->value)
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->pluck('event_id')
            ->all();

        $replayed = 0;
        $retryScheduled = 0;
        $movedToRecoveryPending = 0;
        $skipped = 0;

        foreach ($staleEventIds as $eventId) {
            $claim = $this->claimStale($eventId, $threshold);
            if ($claim === null) {
                $skipped++; // 行が消えた / 別の実行が先に進めた

                continue;
            }

            if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
                $movedToRecoveryPending++;
                $this->reportRecoveryPending($claim);

                continue;
            }

            try {
                $this->process($claim->type, $claim->payload);
            } catch (Throwable $exception) {
                report($exception);
                // **終局させない**: failed にすると回収対象 (received) から外れ、
                // Stripe も配信成功と認識しているため二度と再試行されない。
                // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
                $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
                    ? $retryScheduled++
                    : $skipped++;

                continue;
            }

            $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
                ? $replayed++
                : $skipped++;
        }

        return new WebhookRecoveryResultDto(
            replayed: $replayed,
            retryScheduled: $retryScheduled,
            movedToRecoveryPending: $movedToRecoveryPending,
            skipped: $skipped,
        );
    }

    /**
     * 滞留 1 件の受理。**状態遷移だけ**を 1 つのトランザクションで確定させ、
     * commit 後に要る値をスナップショットで返す (通知はここでは出さない)。
     *
     * `claim()` (Stripe 再送の受理) とは入口が別なので分けてある。
     * `claim()` は変更しない = `received` からの再受理は今までどおり起こらない。
     *
     * 滞留の再検証は**クエリの WHERE に入れる** (ロック取得後に PostgreSQL が述語を
     * 再評価するため、ロック待ちの間に他の実行が前進させた行は 1 行も返らない)。
     *
     * @return StaleWebhookClaimDto|null 処置をしなかったとき (行が無い / 条件を満たさない) は null
     */
    private function claimStale(string $eventId, CarbonImmutable $threshold): ?StaleWebhookClaimDto
    {
        return DB::transaction(function () use ($eventId, $threshold): ?StaleWebhookClaimDto {
            $record = StripeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->where('status', WebhookEventStatus::Received->value)
                ->where('updated_at', '<=', $threshold)
                ->lockForUpdate()
                ->first();

            if (! $record instanceof StripeWebhookEvent) {
                return null;
            }

            $reason = $this->recoveryReasonFor($record);
            if ($reason !== null) {
                $record->status = WebhookEventStatus::RecoveryPending;
                $record->recovery_reason = $reason;
                $record->save();

                return StaleWebhookClaimDto::movedToRecoveryPending(
                    $record->event_id,
                    $record->type,
                    $record->attempts,
                    $reason,
                );
            }

            // 世代を 1 つ進める (status は received のまま = 状態機械を増やさない)。
            // updated_at も進むので、次の実行は閾値を超えるまでこの行を拾わない。
            $record->attempts += 1;
            $record->save();

            return StaleWebhookClaimDto::claimedForReplay(
                $record->event_id,
                $record->type,
                $record->attempts,
                $record->payload,
            );
        });
    }

    /**
     * 自動再実行の対象外と判定する理由 (無ければ null = 再実行してよい)。
     *
     * DB の `type` 文字列は **`tryFrom()`** で境界変換する (`from()` は未知値で例外になり
     * cron 全体を止める)。`null` (本アプリが処理しない種類) は**再実行してよい側**に落ちる —
     * `process()` の `null` arm は構造的に no-op で、通常経路でも `processed` になるため
     * (同じ事実に 2 通りの決着を与えない)。
     */
    private function recoveryReasonFor(StripeWebhookEvent $record): ?WebhookRecoveryReason
    {
        $event = HandledStripeWebhookEvent::tryFrom($record->type);

        // 本アプリが処理しない種類は**必ず**通常経路と同じ決着にする (再実行 → no-op → processed)。
        // 試行上限より前に返すのが要点 — no-op に上限を適用して回収待ちへ置くと、
        // 「未対応 type は通常経路と同じ」という契約が上限到達時だけ破れる。
        if ($event === null) {
            return null;
        }

        if ($event->replaySafety() === WebhookReplaySafety::OrderSensitive) {
            return WebhookRecoveryReason::OrderSensitive;
        }

        if ($record->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
            return WebhookRecoveryReason::AttemptsExhausted;
        }

        return null;
    }
```

### app/Services/Capture/StaleUploadReservationSweeper.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Enums\Capture\TakeUploadReservationStatus;
use App\Models\TakeUploadReservation;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * 孤児掃除 (doc/10 §10.8-4 / 概念設計 D7): 回収対象は
 * (a) expires_at 超過の pending 予約
 * (b) stale な verifying 予約 (updated_at が閾値超過 = 登録リクエストの異常終了)
 * を released 化し (bytes_pending 解放)、S3 に PUT 済みだが未登録のオブジェクトを削除する。
 * **fresh な verifying には触れない** (登録処理の claim 契約と競合しない)。
 * 加えて released/completed の古い行 (retention 超過) を物理削除する。冪等。
 */
class StaleUploadReservationSweeper
{
    /** 1 回の sweep が対象にする予約数の上限 (exists/delete の I/O 回数を抑える) */
    private const int BATCH_LIMIT = 500;

    public function __construct(
        private readonly TakeObjectStorage $storage,
    ) {}

    /** @return int released 化した予約数 */
    public function sweep(): int
    {
        // 時刻境界の一貫性: $now / $cutoff は冒頭で一度だけ生成し、一覧抽出と CAS 条件で共有する
        $now = now()->toImmutable();
        $cutoff = $now->subMinutes(config()->integer('capture.stale_verifying_minutes'));

        /** @var list<TakeUploadReservation> $stale */
        $stale = TakeUploadReservation::query()
            ->where(function (Builder $query) use ($now, $cutoff): void {
                $query->where(fn (Builder $q) => $q
                    ->where('status', TakeUploadReservationStatus::Pending)
                    ->where('expires_at', '<=', $now))
                    ->orWhere(fn (Builder $q) => $q
                        ->where('status', TakeUploadReservationStatus::Verifying)
                        ->where('updated_at', '<', $cutoff));
            })
            ->limit(self::BATCH_LIMIT)
            ->get()
            ->all();

        $released = 0;
        foreach ($stale as $reservation) {
            // CAS: 一覧取得後に登録処理が completed 化していたら 0 行更新 → 削除しない
            // (登録確定側の verifying→completed CAS と対。勝者だけが後続処理を行う)
            $won = TakeUploadReservation::query()
                ->whereKey($reservation->id)
                ->where(function (Builder $query) use ($reservation, $now, $cutoff): void {
                    $reservation->status === TakeUploadReservationStatus::Pending
                        ? $query->where('status', TakeUploadReservationStatus::Pending)
                            ->where('expires_at', '<=', $now)
                        : $query->where('status', TakeUploadReservationStatus::Verifying)
                            ->where('updated_at', '<', $cutoff);
                })
                ->update(['status' => TakeUploadReservationStatus::Released]);
            if ($won === 0) {
                continue; // 登録処理が勝った (completed) → オブジェクトは正当な Take の実体
            }
            $released++;
            if ($this->storage->exists($reservation->video_path)) {
                $this->storage->delete($reservation->video_path); // 未登録オブジェクトの孤児削除
            }
        }

        // released/completed の古い行の物理削除 (肥大防止。retention は config)
        TakeUploadReservation::query()
            ->whereIn('status', [TakeUploadReservationStatus::Released, TakeUploadReservationStatus::Completed])
            ->where('updated_at', '<', $now->subDays(config()->integer('capture.released_reservation_retention_days')))
            ->delete();

        return $released;
    }
}

```

### tests/Support/Security/DirectFetchInventory.php (該当エントリ)

```php

            // --- 同一メソッド内の走査クエリ由来 (保守処理) ---
            'Services/Billing/TicketLedgerService.php#releaseStale#TicketReservation.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが status / expires_at で列挙した TicketReservation の主キー。'
                .'期限切れ予約の解放は全テナント横断の保守処理であり cron から呼ばれる (HTTP 入力を経由しない)',
            ),
            'Services/Capture/StaleUploadReservationSweeper.php#sweep#TakeUploadReservation.whereKey:$reservation->id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが status / expires_at で列挙した予約行の主キー。孤児オブジェクト回収は'
                .'全テナント横断の保守処理で cron から呼ばれる。whereKey は CAS 更新の対象行指定に使っている',
            ),
            'Services/Manual/AnalysisJobService.php#recoverStale#AnalysisJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが status / 経過時間で列挙した AnalysisJob の主キー。'
                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
            ),
            'Services/Manual/RenderJobService.php#recoverStale#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが status / 経過時間で列挙した RenderJob の主キー。'
                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
            ),
            'Services/Manual/RenderJobService.php#reconcileOutputs#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                'id は同一メソッドが output_path 非 NULL で列挙した RenderJob の主キー。'
```

### app/Enums/Security/DirectFetchJustification.php (IdSuppliedByInternalCaller の適用条件)

```php
     * 想定は stale 回収 / 整合回復のような**全テナント横断の保守走査**
     * (`$ids = RenderJob::query()->where('status', …)->pluck('id')` の各要素を引き直す形)。
     *
     * 適用条件 (全て満たすこと):
     * - identity の基底変数が同一メソッド内のクエリ結果変数から `foreach` 束縛 / 代入されている
     * - 同一メソッド内に request accessor が 1 つも無い (HTTP 入力を経由しない)
     * - 「テナント横断で走査すること」が仕様である (cron / scheduler 経由の保守処理)
     *
     * ★テナント越しの参照を正当化する case ではない。**走査元のクエリの WHERE 条件は
     *   本 gate の主張範囲外**である (主キー同一性クエリではないため)。
     *   走査元が request 由来の条件で絞られているなら本 case を使ってはならない。
     */
    case IdDerivedFromSameMethodQuery = 'id_derived_from_same_method_query';

    /**
     * identity が同一クラス内の呼び出し元で確定し、private ヘルパへ引数で渡されている。
     *
     * 適用条件 (全て満たすこと):
     * - 当該メソッドが **private** である (クラス外から直接呼べない)
     * - identity が当該メソッドの**引数**である
     * - 同一メソッド内に request accessor が 1 つも無い
     * - `calledBy` に呼び出し元 `Class::method` を書き、そこで identity が
     *   解決済みモデルから確定していることを根拠文で示す
     *
     * ★呼び出し元の provenance は機械証明できない (メソッドをまたぐデータフロー解析は
     *   走査器の範囲外)。private + 引数 + request accessor 無しで濫用を抑えるが、
     *   最終的には人手の根拠文に依存する。public メソッドには使えない。
     */
    case IdSuppliedByInternalCaller = 'id_supplied_by_internal_caller';

    /** local 専用の診断経路。route 登録自体が local 限定で production から到達不能。 */
```
