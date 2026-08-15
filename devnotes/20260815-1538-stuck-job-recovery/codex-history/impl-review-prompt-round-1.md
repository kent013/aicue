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

あなたは Laravel 12 + PHP 8.4 + Svelte 5 のコードレビュアーである。
以下の詳細設計書に基づく実装差分をレビューせよ。

## レビュー観点

1. **設計との一致性**: 詳細設計書の施策 1〜10 が実装されているか。設計と食い違う箇所があれば、
   設計側が直されているか (本差分には detailed-design.md の更新も含む)
2. **正確性**: 並行実行時の破れ (行ロックと述語の再評価)、境界条件 (閾値ちょうど)、
   ページ送りの前進性、上限の適用箇所、例外時の掃引継続
3. **PHPStan level 10 適合性**: 型の widen・@phpstan-ignore・baseline は禁止 (差分に無いことを確認)
4. **DTO / JsonResource パターン**: 本差分は Console/Service 層のみで JsonResource は無関係
5. **テスト網羅性**: 既存テストが 1 本も消えていないか (張り替えのみか)。
   新設 gate が vacuous でないか。fail-first の観点が満たされているか
6. **セキュリティ**: クラス起点の主キー同一性クエリの分類 (DirectFetchInventory)、
   テナント越えの有無、運用ログへの id / PII 漏れ
7. **文書の誠実さ**: 「保証しないもの」を誇張していないか。造語を作っていないか。
   初見の人が辞書どおりの意味で読んで解釈できる日本語か

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に**全体判定を APPROVED または CHANGES_REQUESTED** で明示する

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
| 4 | 解析ジョブ stream への移設と滞留述語の再評価 | `app/Services/Recovery/Streams/StaleAnalysisJobStream.php` (新) / `app/Services/Manual/AnalysisJobService.php` | 高 |
| 5 | レンダジョブ stream への移設と滞留述語の再評価 | `app/Services/Recovery/Streams/StaleRenderJobStream.php` (新) / `app/Services/Manual/RenderJobService.php` | 高 |
| 6 | チケット予約 stream への移設と滞留述語の再評価 | `app/Services/Recovery/Streams/ExpiredTicketReservationStream.php` (新) / `app/Services/Billing/TicketLedgerService.php` | 高 |
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

    /**
     * 定期実行の間隔 (分)。現行の cron の間隔をそのまま保存する。
     * **60 の約数であること** (`cron('*␟/N * * * *')` 表記が毎時同じ刻みで回る前提。Unit テストで固定)
     */
    public function cadenceMinutes(): int
    {
        return match ($this) {
            self::AnalysisJob, self::RenderJob,
            self::TicketReservation, self::WebhookEvent => 5,
            self::UploadReservation => 10,
        };
    }

    /**
     * 多重起動を抑止するロックの有効期限 (分) = 実行間隔の 2 倍。
     *
     * **Laravel 既定 (24 時間) に任せない**。異常終了でロックが残ると、既定では丸 1 日
     * 回収が止まったまま無音になる (回収基盤が回収を止める)。2 倍にしてあるのは、
     * 前回の実行が長引いている間の重複起動は抑えつつ、取り残しが最大 2 周期で解けるようにするため。
     */
    public function overlapExpiryMinutes(): int
    {
        return $this->cadenceMinutes() * 2;
    }
}
```

> 上記コード中の `*␟/N` は `*` と `/` の連続を Markdown 上で分けて書いたもので、実装では `*/N`。

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
      値を返す (match の網羅。case 追加時に fail する) / **全 case で `60 % cadenceMinutes() === 0`**
      (cron の刻み表記の前提) / **`overlapExpiryMinutes() === cadenceMinutes() * 2`** (設計値の固定)
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
            // ★ positive-int を型で閉じる (level 10 は min() の結果を正に絞れない)
            $pageSize = $limit === null ? self::PAGE_SIZE : min(self::PAGE_SIZE, $limit - $candidates);
            Assert::positiveInteger($pageSize);
            // 契約違反 (要求より多く返す実装) があっても実効上限を超えないよう防御的に切る
            $ids = array_slice($stream->candidateIds($sweptAt, $afterId, $pageSize), 0, $pageSize);
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
  - [ ] **契約違反時も上限を防御する**: `$pageSize` より多く返す fake stream を渡しても
        実効上限を超えない (これは黙って切るだけの防御。**契約そのものの固定**は
        「各実装が要求件数以下を返す」という施策 4-8 の stream 単位テストが担う)
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
        // ★ 引数の解釈は例外で失敗を表す。`?int` を返す形にすると「未指定」と「不正値」が
        //   同じ null になり、不正値が無制限実行へ落ちる (Codex 合議 Round 2 の指摘)
        try {
            $streams = $this->resolveStreams($registry); // list<StuckWorkStream>
            $limit = $this->resolveLimit();              // positive-int|null (未指定のみ null)
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

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
            '%s: mode=%s candidates=%d recovered=%d cleanup-failed=%d skipped=%d deferred=%d escalated=%d errors=%d limit-reached=%s',
            $result->stream->value,
            $result->applied ? 'apply' : 'dry-run (candidates は回収件数の上界)',
            $result->candidates,
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
| 監視対象 (必須): 各実行の出力と onFailure。**5 つを見る**。
|   - errors > 0 が続く        = 特定の行で回収が失敗し続けている
|   - deferred > 0 が続く      = 再実行が失敗し続けている (webhook。**errors には出ない** —
|                                失敗は行に書き戻して次回へ回すため、errors=0 のまま滞留しうる)
|   - escalated の件数         = 自動回収の対象外として人手へ渡した件数 (webhook)
|   - cleanup-failed > 0       = S3 の孤児削除に失敗した件数 (**手動確認が要る**。
|                                行は解放済みなので自動では拾い直せない)
|   - limit-reached=yes が続く = 上限で打ち切っており後続候補が残っている
*/
foreach (RecoveryStream::cases() as $recoveryStream) {
    Schedule::command('work:recover-stuck --stream='.$recoveryStream->value.' --apply')
        ->cron('*/'.$recoveryStream->cadenceMinutes().' * * * *')
        ->onOneServer()
        // ★ 期限を明示する。既定 (24 時間) だと異常終了で残ったロックが丸 1 日回収を止める
        ->withoutOverlapping($recoveryStream->overlapExpiryMinutes())
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
  撮影アップロードだけが持つ)。これらは cache ロックを前提にする既存前提と同じ。
  **ロックの有効期限は明示する** (`overlapExpiryMinutes()` = 実行間隔の 2 倍)。
  Laravel の既定は 24 時間で、異常終了でロックが残ると回収が丸 1 日止まったまま無音になる。
  - **保証の限界を誇張しない**: 有効期限を過ぎると期限切れとしてロックが解けるので、
    **正常な実行がその時間を超えて走っている間は同一系列が並行実行されうる**。
    多重起動しても状態が壊れないことは各 stream の再評価が担保するが、
    「重複が起きない」とは書かない。想定最大実行時間が期限を下回っていることは
    運用の監視対象 (実行時間) として `docs/architecture.md` に書く
- `onFailure` → `report()` を全系列に付ける (現在は webhook のみ)。回収が止まっていることが
  無音にならないようにする

### PHPStan 適合チェック

- [x] `$this->option()` の戻り値 (`mixed`) は `resolveLimit()` / `resolveStreams()` で
      `positive-int|null` (null = 未指定のみ) / `list<StuckWorkStream>` へ narrowing してから使う
      (不正値は `InvalidArgumentException` で表すので、戻り型に「異常」を混ぜない)
- [x] `RuntimeException` は `routes/console.php` に namespace 宣言が無いため
      **import しない** (`NoNonCompoundGlobalUseTest` の規約)
- [x] 網羅 `match` で outcome を出力 (default arm を作らない)

### テスト計画

- [ ] 新規 `tests/Feature/Console/RecoverStuckWorkCommandTest.php`:
  - [ ] `--apply` 無しで実行すると DB が 1 バイトも変わらない (滞留を作ってから実行して確認)
  - [ ] `--stream` に未知の値を渡すと FAILURE で、有効値の一覧が出力される
  - [ ] `--limit=0` / 負値 / 非数値は FAILURE でメッセージが出る
        (誤操作が「無制限で走る」に落ちないこと。未指定との区別を behavioral に固定する)
  - [ ] 出力に 5 系列の行が出る (`--stream` 省略時)
  - [ ] **出力に監視対象の 5 語彙 (`errors` / `deferred` / `escalated` / `cleanup-failed` /
        `limit-reached`) が必ず含まれる** (運用の監視語彙が黙って消えないことの固定)
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

## 施策 4: 解析ジョブ stream への移設と滞留述語の再評価

### 変更箇所

- 新規: `app/Services/Recovery/Streams/StaleAnalysisJobStream.php`
- 変更: `app/Services/Manual/AnalysisJobService.php`
  (L168-204 の `recoverStale()` を撤去 / `failJob()` の本体を private へ切り出し /
  **`failStaleJob(int $id, CarbonImmutable $sweptAt): bool` を新設**)

### ★ 現行の欠陥 (この施策で直す)

現行の `recoverStale()` は候補を列挙したあと `failJob()` に委譲しているが、
`failJob()` が行ロック下で再評価するのは **terminal かどうかだけ**で、
**滞留の述語 (queued/running と経過時間) は再評価していない**。
そのため、候補の列挙後に worker が進捗を書いて `updated_at` を進めた running ジョブを、
**正常に動いているのに失敗として確定してしまう**窓がある
(裁定 AG-083 が「誤回収の防止」として名指ししている事故そのもので、
しかも起きてもエラーにならず静かに壊れる)。

共通契約へ寄せるこの機会に、**滞留の述語を行ロック下の WHERE で再評価する**形へ直す。
以降、候補列挙で使う述語と再評価で使う述語は**同じ 1 つの式**にする
(`$sweptAt` を共有するので閾値も同一)。

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
        // ★ 候補列挙も Service へ委譲する。滞留の述語を stream と Service に**複製しない**
        //   (片方だけ書き換えられると、今回塞ぐ誤回収がそのまま再発する)
        return $this->jobs->staleJobIds($sweptAt, $afterId, $pageSize);
    }

    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
    {
        // ★ 行ロック下で滞留の述語ごと再評価するのは Service 側の責務 (下の failStaleJob)。
        //   stream は id を渡すだけで、行も判定結果も持ち回らない
        return $this->jobs->failStaleJob($id, $sweptAt)
            ? RecoveryOutcome::Recovered
            : RecoveryOutcome::Skipped;   // 競合で前進済み / 進捗が進んだ = 失敗ではない
    }

    public function sweepItemLimit(): ?int
    {
        return null;
    }
}
```

```php
// app/Services/Manual/AnalysisJobService.php (新設と切り出し)

/**
 * 滞留ジョブの失敗確定 (回収経路の唯一の口)。
 *
 * **行ロックを取ったうえで滞留の述語ごと再評価する** — 候補を列挙してから
 * ロックを取るまでの間に worker が進捗を書いた running ジョブは 1 行も返らないので、
 * 正常に動いているものを失敗にしない (誤回収の防止)。
 *
 * @param  positive-int  $id  滞留回収の候補列挙 (StaleAnalysisJobStream::candidateIds) が返した主キー
 * @return bool 実際に failed へ遷移させたか
 */
public function failStaleJob(int $id, CarbonImmutable $sweptAt): bool
{
    $threshold = $sweptAt->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));

    // ★ 通知のためにモデルを引き直さない (クラス起点の主キークエリを 1 本増やさないため。
    //   Codex 合議 Round 3 の指摘)。トランザクションからロック済みモデルをそのまま返す
    $failed = DB::transaction(function () use ($id, $threshold): ?AnalysisJob {
        $locked = $this->lockStaleJob($id, $threshold);
        if ($locked === null) {
            return null; // 述語が成立しない (前進済み / terminal / 進捗が進んだ)
        }

        return $this->failLockedJob($locked, '解析がタイムアウトしました。再実行してください。')
            ? $locked
            : null;
    });

    if ($failed !== null) {
        $this->notifications->notifyAnalysisFinished($failed->refresh()); // 既存 failJob と同じ形
    }

    return $failed !== null;
}

/**
 * 滞留候補の主キーを昇順で返す (回収の候補列挙。述語は下の applyStalePredicate が唯一の正本)。
 *
 * @param  positive-int|null  $afterId
 * @param  positive-int  $pageSize
 * @return list<positive-int>
 */
public function staleJobIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
{
    /** @var list<positive-int> $ids */
    $ids = $this->applyStalePredicate(AnalysisJob::query(), $this->staleThreshold($sweptAt))
        ->when($afterId !== null, fn (Builder $q) => $q->where('id', '>', $afterId))
        ->orderBy('id')
        ->limit($pageSize)
        ->pluck('id')
        ->all();

    return $ids;
}

/** id は回収の候補列挙由来。**候補列挙と同じ述語**を WHERE に入れることでロック後の再評価になる */
private function lockStaleJob(int $id, CarbonImmutable $threshold): ?AnalysisJob
{
    return $this->applyStalePredicate(AnalysisJob::query()->whereKey($id), $threshold)
        ->lockForUpdate()
        ->first();
}

/**
 * 滞留の述語 (**この 1 か所だけが正本**)。
 * queued は発生時刻 (created_at)、running は進捗時刻 (updated_at) を起点にする。
 *
 * @param  Builder<AnalysisJob>  $query
 * @return Builder<AnalysisJob>
 */
private function applyStalePredicate(Builder $query, CarbonImmutable $threshold): Builder
{
    return $query->where(fn (Builder $q) => $q
        ->where(fn (Builder $queued) => $queued
            ->where('status', JobStatus::Queued->value)->where('created_at', '<=', $threshold))
        ->orWhere(fn (Builder $running) => $running
            ->where('status', JobStatus::Running->value)->where('updated_at', '<=', $threshold)));
}
```

`failJob(AnalysisJob $job, string $error)` は**公開のまま残す** (pipeline の catch と
`Job::failed` の合流点)。両者の本体は private な `failLockedJob(AnalysisJob $locked, string $error)`
に切り出して**1 つにする** — manual のロック順 (job → manual)、terminal guard、
予約解放、`scenario_version_at_terminal` の書き込みを 2 か所に複製しない。
通知は従来どおり**コミット後**に 1 回だけ発火する (at-most-once の契約は不変)。

### PHPStan 適合チェック

- [x] `pluck('id')->all()` の戻り型は `list<positive-int>` を phpdoc で宣言
- [x] `failStaleJob()` の戻り値 `bool` を網羅した三項で outcome に写像
- [x] `lockStaleJob` は `?AnalysisJob` を返し null 分岐を明示
- [x] トランザクションの戻り型を `?AnalysisJob` にして通知の引数型を閉じる
      (`findOrFail` での引き直しをしない = 主キー同一性クエリを増やさない)

### テスト計画

- [ ] 既存 `AnalysisRecoverStaleJobsTest` の 5 テストを**維持**し、呼び出し口だけ
      `work:recover-stuck --stream=analysis_job --apply` へ張り替える
      (閾値の境界・冪等・terminal 先着・予約解放の検証内容は変えない)
- [ ] 既存 `AnalysisPipelineTest` の (D) 系 3 テスト (強制終了後の会計収束・順序非依存) は
      `app(StaleAnalysisJobStream::class)` 経由へ張り替えて維持
- [ ] 既存 `ManualAnalysisNotificationTest` の「回収経由の失敗も通知が 1 件発火する」を維持
- [ ] **新規 (この施策の主眼。fail-first で書く)**: 候補として列挙したあとに worker が
      進捗を書いた running ジョブは `Skipped` になり、**failed にならない**
      (`candidateIds` で id を取る → `updated_at` を現在時刻へ進める → `recover` を呼ぶ、で再現)
- [ ] 新規: 候補列挙後に succeeded へ先着されたジョブも `Skipped` (terminal guard は従来どおり)
- [ ] 新規: `candidateIds` がページ送りで昇順・`$afterId` より大きい id だけを返し、
      **`$pageSize` を超える件数を返さない**
- [ ] **新規 (述語の単一正本)**: 候補として列挙された id は必ず `lockStaleJob` でも
      取得できる (queued 境界・running 境界の 4 点で、列挙とロックの結果が一致することを確認。
      `applyStalePredicate` を両方が使っていることの behavioral な裏取り)
- [ ] 既存の `failJob()` 経由のテスト (pipeline catch / `Job::failed`) が緑のままであること
      (本体を private へ切り出したことの回帰確認)

### リスク

- **挙動が 1 点変わる**: 候補列挙後に進捗が進んだ running ジョブを失敗にしなくなる。
  これは誤回収を止める方向の変更で、次の掃引でも滞留していれば回収される
- `failJob()` の本体切り出しは、manual のロック順・通知の at-most-once・予約解放の
  3 つの契約に触れる。既存テスト (pipeline / 通知 / 会計) が回帰の検出点になる
- `AnalysisJobService` の docblock (L170 の「TicketLedgerService::releaseStale と同型」) は
  参照先が消えるため書き換えが必要 (撤去 gate が検出する)

---

## 施策 5: レンダジョブ stream への移設と滞留述語の再評価

### 変更箇所

- 新規: `app/Services/Recovery/Streams/StaleRenderJobStream.php`
- 変更: `app/Services/Manual/RenderJobService.php`
  (L260-302 の `recoverStale()` を撤去 / `failJob()` の本体を private へ切り出し /
  **`failStaleJob(int $id, CarbonImmutable $sweptAt): bool` を新設**)

### 波及変更

- テストファイル: `tests/Feature/Manual/RenderStaleRecoveryTest.php` (6 テスト) /
  `tests/Feature/Notifications/ManualRenderNotificationTest.php` (1 テスト)
- `tests/Support/Security/DirectFetchInventory.php` のキー更新 (施策 10)

### 変更後コード

施策 4 と同型で、**同じ欠陥 (滞留述語を行ロック下で再評価していない) を同じ形で直す**。

- `RenderJobService::staleJobIds($sweptAt, $afterId, $pageSize): list<positive-int>` (候補列挙)
- `RenderJobService::failStaleJob(int $id, CarbonImmutable $sweptAt): bool` (ロック下の再評価 + 失敗確定)
- private `applyStalePredicate(Builder $query, ...)` を**両方が使う唯一の述語**にする。
  レンダは閾値が 2 本 (queued=10 分 / running=30 分) あり、述語を 2 か所に書くと
  ドリフトの危険が解析より高いので集約は必須
- **通知のためのモデル引き直しをしない**。トランザクションからロック済み `?RenderJob` を返し、
  commit 後に `$failed->refresh()` を通知へ渡す (施策 4 と同じ形。
  クラス起点の主キークエリを増やさないため = 目録の母集団を増やさない)
- stream (`StaleRenderJobStream`) は候補列挙も回収も Service へ委譲するだけになる
  (`sweepItemLimit()` は `null`)

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
- [ ] 既存 `ManualRenderNotificationTest` の「回収経由の render 失敗も通知される」を維持
- [ ] **新規 (fail-first)**: 候補列挙後に進捗が進んだ running レンダジョブは `Skipped` になり
      failed にならない (施策 4 と同じ再現手順)
- [ ] 新規 (述語の単一正本): **kind (preview / render) × 状態 (queued / running) の直積 4 通り**で、
      閾値の境界 (超過ちょうど / 1 分手前) の候補列挙とロック取得の結果が一致する
- [ ] 新規: `candidateIds` が `$pageSize` を超える件数を返さない

### リスク

- 施策 4 と同じ挙動変更 (誤回収を止める方向)。レンダは `manual.status` を
  `rendering → ready` へ戻す副作用があるため、**誤回収を止めることは編集ロックの
  誤解除も止める**ことになる (改善方向)
- `RenderJobService` の docblock (L36 / L170 / L264) と `RunManualRender` の docblock (L24) が
  旧メソッド名を名指ししているため書き換えが要る (撤去 gate が検出する)

---

## 施策 6: チケット予約 stream への移設と滞留述語の再評価

### 変更箇所

- 新規: `app/Services/Recovery/Streams/ExpiredTicketReservationStream.php`
- 変更: `app/Services/Billing/TicketLedgerService.php`
  (L589-626 の `releaseStale()` を撤去 / `release()` の本体を private へ切り出し /
  **`releaseExpiredReservation(int $id, CarbonImmutable $sweptAt): bool` を新設** /
  滞留述語 (`expires_at` 超過 または 失効 monthly hold) を組み立てる private を
  候補列挙と再評価で共有する)

### ★ 概念設計からの変更 (Codex 合議 Round 1 の指摘を受けて簡素化した)

概念設計では「競合だけを表す専用例外 `ReservationNotReleasableException` を新設し、
stream が `Skipped` へ変換する」としていた。**この例外は作らない**。
滞留述語を行ロック下の WHERE に入れて再評価する形にすると、競合した予約は 1 行も返らず
`false` が返るだけなので、例外を分類する必要そのものが消える (作らずに済むものは作らない)。
`release()` が投げる `LogicException` は従来どおり `failJob` の並行 release 握りが受ける。

### 波及変更

- テストファイル: `tests/Feature/Billing/TicketLedgerTest.php` (releaseStale のテスト) /
  `tests/Feature/Billing/TicketCommitWinsTest.php` /
  `tests/Feature/Projects/AnalysisPipelineTest.php` (順序非依存 2 テスト)
- `tests/Support/Security/DirectFetchInventory.php` のキー更新 (施策 10)

### 変更後コード

```php
// app/Services/Billing/TicketLedgerService.php (新設)
/**
 * 滞留予約の解放 (回収経路の唯一の口)。
 *
 * **行ロックを取ったうえで滞留の述語ごと再評価する** — reserved であることに加えて
 * 「TTL 超過 または 失効 monthly hold」を WHERE に入れるので、候補の列挙後に
 * commit / release された予約や、条件を満たさなくなった予約は 1 行も返らない。
 *
 * @param  positive-int  $id  候補列挙 (ExpiredTicketReservationStream::candidateIds) が返した主キー
 * @return bool 実際に解放したか
 */
public function releaseExpiredReservation(int $id, CarbonImmutable $sweptAt): bool
{
    return DB::transaction(function () use ($id, $sweptAt): bool {
        $locked = $this->lockExpiredReservation($id, $sweptAt); // whereKey + 滞留述語 + lockForUpdate
        if ($locked === null) {
            return false;
        }
        $this->releaseLockedReservation($locked); // release() と共有する本体 (org 行ロック → 台帳 → Released)

        return true;
    });
}
```

```php
// ExpiredTicketReservationStream::recover
public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
{
    return $this->tickets->releaseExpiredReservation($id, $sweptAt)
        ? RecoveryOutcome::Recovered
        : RecoveryOutcome::Skipped; // 並行 commit / release 済み = 正常事象 (失敗ではない)
}
```

- 候補列挙と再評価は**同じ述語ビルダ** (`TicketLedgerService` の private) を使う。
  失効 monthly hold の判定式 (`expiredMonthlyHoldCondition`) は会計の一部なので
  **stream へ複製しない**。候補列挙のために `candidateIds` 用の口
  (`expiredReservationIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): list<positive-int>`)
  を `TicketLedgerService` に置き、stream はそれを呼ぶだけにする
  (会計の述語が台帳サービスの中に閉じる)
- `release(TicketReservation $reservation)` は**公開のまま残す** (failJob からの解放経路)。
  本体は private `releaseLockedReservation()` に切り出して 1 つにする
  (ロック順 予約 → 組織、台帳への 0 行追記、`Released` 化を 2 か所に複製しない)

### PHPStan 適合チェック

- [x] `releaseExpiredReservation` は `bool` を返し、`DB::transaction` の戻り型を閉じる
- [x] `expiredReservationIds` の戻り型を `list<positive-int>` で宣言
- [x] `lockExpiredReservation` は `?TicketReservation` を返し null 分岐を明示

### テスト計画

- [ ] 既存 `TicketLedgerTest` の「releaseStale は expires_at 超過の reserved だけを解放する」を
      維持し、stream 経由へ張り替える
- [ ] 既存 `TicketCommitWinsTest` (commit-wins と stale 解放の競合) を維持
- [ ] 既存 `AnalysisPipelineTest` の順序非依存 2 テスト
      (`failJob → 回収 → 解放` / 逆順で最終会計状態が同じ) を維持
- [ ] **新規 (fail-first)**: 候補列挙後に別プロセスが commit した予約は `Skipped` になり、
      コマンドは成功で終わる (例外を投げない = 運用アラートを鳴らさない)
- [ ] 新規: 候補列挙後に `expires_at` が延長された予約 (述語が不成立になった行) は解放されない
- [ ] 新規: 失効 monthly hold の解放が従来どおり行われる (会計の述語を移設しても意味が変わらない)

### リスク

- `release()` の本体切り出しは commit-wins の契約 (`TicketCommitWinsTest`) に触れる。
  既存テストが回帰の検出点になる
- 会計の述語 (`expiredMonthlyHoldCondition`) を `TicketLedgerService` の外へ出さないため、
  候補列挙の口 (`expiredReservationIds`) が台帳サービスに増える。
  読み取り専用の走査であり `TicketLedgerReaderInventoryTest` の母集団に該当するか
  実装時に確認する (該当するなら目録へ登録する)

---

## 施策 7: Stripe webhook stream への移設と旧実装撤去

### 変更箇所

- 新規: `app/Services/Recovery/Streams/StaleWebhookEventStream.php`
- 変更: `app/Services/Billing/StripeWebhookProcessor.php`
  (L194-267 の `recoverStale()` を撤去し、1 件処理の口 `recoverStuckEvent()` を公開 /
  `claimStale()` を `whereKey` ベースへ)

> **命名の理由**: 新しい口の名前に撤去済みメソッド名 (`recoverStale`) を含めない。
> 含めると施策 9 の撤去 gate が**新設コード自身を落とす**か、gate を緩めることになる
> (Codex 合議 Round 1 の指摘)。`claimStale` は private のまま残るが、
> 撤去対象ではない (滞留の受理はこの機能の中核でありメソッドは生き続ける)。
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
public function recoverStuckEvent(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
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

- [x] `recoverStuckEvent` の戻り値は `RecoveryOutcome` (bool の三項を網羅)
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
// StaleUploadReservationStream
public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
{
    // 主キーのクエリは private ヘルパに 1 本だけ閉じる (DirectFetchInventory の登録単位と一致)
    $videoPath = $this->releaseIfStillStale($id, $sweptAt);
    if ($videoPath === null) {
        return RecoveryOutcome::Skipped; // 登録処理が勝った (completed) = 正当な Take の実体
    }

    try {
        if ($this->storage->exists($videoPath)) {
            $this->storage->delete($videoPath); // 未登録オブジェクトの孤児削除
        }
    } catch (Throwable $exception) {
        // 枠の解放は巻き戻さない (利用者の枠を人質にしない)。件数として観測できる形で残す
        report($exception);

        return RecoveryOutcome::RecoveredWithCleanupFailure;
    }

    return RecoveryOutcome::Recovered;
}

/**
 * 行ロック下で滞留の述語を再評価して解放し、削除対象のパスを返す (解放できなければ null)。
 *
 * ★ **条件付き UPDATE (CAS) ではなく行ロックにする** — CAS だと「更新」と「パスの読み取り」で
 *   主キーのクエリが 2 本になる (目録の母集団が増える。Codex 合議 Round 3 の指摘)。
 *   行ロック + 述語再評価なら 1 本で済み、他の 4 stream と同じ形にも揃う。
 *   直列化の効き方は CAS と同じ — 登録処理側の verifying→completed の更新は
 *   このロックが解けるまで待ち、解けた時点で述語を再評価して 0 行になる (正当な Take を消さない)。
 * ★ S3 の削除は**コミット後**に行う (行ロックを保持したまま外部 I/O を待たない)。
 * id は候補列挙が返した主キーで HTTP 入力を経由しない (DirectFetchInventory に登録)。
 */
private function releaseIfStillStale(int $id, CarbonImmutable $sweptAt): ?string
{
    $cutoff = $sweptAt->subMinutes(config()->integer('capture.stale_verifying_minutes'));

    return DB::transaction(function () use ($id, $sweptAt, $cutoff): ?string {
        $locked = TakeUploadReservation::query()
            ->whereKey($id)
            ->where(/* pending: status + expires_at <= $sweptAt / verifying: status + updated_at < $cutoff */)
            ->lockForUpdate()
            ->first();
        if ($locked === null) {
            return null; // 登録処理が勝った (completed) / 条件を満たさなくなった
        }

        $locked->forceFill(['status' => TakeUploadReservationStatus::Released])->save();

        return $locked->video_path;
    });
}

/**
 * S3 の存在確認・削除の入出力を有界にするための既存の上限 (500)。公平性は保証しない。
 * 実装では戻り型を `int` に narrowing する (interface の `?int` に対する共変。
 * `?int` のままだと「null を返さない」と PHPStan level 10 が指摘する)。
 */
public function sweepItemLimit(): int
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
- [x] `releaseIfStillStale()` は `?string` (削除対象のパス) を返し null 分岐を明示
- [x] 保持期間コマンドの削除件数は `int` を返して出力する

### テスト計画

- [ ] 既存 `StaleReservationSweepTest` の 8 テストを分割して**全数維持**:
  - 回収側 (新 `tests/Feature/Capture/StaleUploadReservationRecoveryTest.php`):
    期限切れ pending の解放 + 削除 / `exists=false` なら delete を呼ばない /
    fresh verifying に触れない / stale verifying の解放 / 冪等 (2 回目は 0 件) /
    **競合 (列挙後に completed 化された行は上書きも削除もしない)** —
    現行の CAS テストと同じ状況を、行ロック + 述語再評価の実装に対して確認する
    (検証内容は変えない。テストは消さない)。
    可能なら**別接続でロック待ちを起こし、ロック解放後に述語が再評価されて 0 行になる**
    ケースも 1 本持つ (CAS から行ロックへ変えた並行実行の契約を直接固定できる。
    並列レーンで不安定なら通常の競合テストに留める)
  - 保持期間側 (新 `tests/Feature/Capture/PurgeUploadReservationsTest.php`):
    retention 超過の released/completed を物理削除 / fresh は残す
  - cron テストは 2 本に分ける (`work:recover-stuck --stream=upload_reservation --apply` と
    `capture:purge-upload-reservations`)
- [ ] 新規: S3 削除が例外になったとき、行は released のままで結果が
      `RecoveredWithCleanupFailure` として 1 件数えられる (掃引は止まらない)
- [ ] 新規: 解放とパスの取得が**同じ行ロックの中**で行われ、S3 の削除はコミット後に走る
      (ロックを保持したまま外部 I/O を待たない)
- [ ] 新規: 500 件の上限に達し、かつ後続候補が実在するとき `limit-reached=yes` が出力される

### リスク

- **保持期間の削除が別コマンドになるため、実行間隔が 10 分毎から日次へ変わる**。
  物理削除は肥大防止であり緊急性が無いので日次で十分 (既存の purge 系 4 本と同じ)
- S3 削除失敗時に行が released のまま残るため、**未削除オブジェクトは自動では拾えない**。
  これは現行実装でも同じ (現行は例外が cron 全体を止めるので、むしろ悪化していない)。
  「保証しないもの」として docs に明記し、**`cleanup-failed` の件数は手動確認の対象である**と
  監視項目の説明に書く (件数が出るだけでは運用者が何をすべきか分からない)

---

## 施策 9: 目録 gate と撤去済み参照 gate

### 変更箇所

- 新規: `tests/Architecture/StuckWorkRecoveryInventoryTest.php`
- 新規: `tests/Architecture/RetiredRecoveryReferenceGateTest.php`
- 新規: `tests/Support/Recovery/RecoveryStreamEntry.php` (stream ごとの申告)
- 新規: `tests/Support/Recovery/NonRecoveryScheduleEntry.php` (回収でない定期実行の申告)
- 新規: `tests/Support/Recovery/StuckWorkRecoveryInventory.php` (上記 2 種の申告の置き場所。
  他の目録と同じく static クラスに置き、Pest のファイル読み込み順に依存する global 関数にしない)
- 新規: `app/Enums/Recovery/NonRecoveryScheduleReasonKind.php` (区分。理由の自由文は 30 文字以上)

### 目録 gate が固定すること (deny-by-default / exact-fit)

1. **registry の stream 集合 == `RecoveryStream` の全 case == 目録の申告集合**
   (未登録・重複・宣言だけで実装が無い、のいずれも落ちる)
2. **Schedule に載る `work:recover-stuck --stream=<key>` の集合が stream キーと一致する**。
   突き合わせはコマンド名ではなく **stream キー**で行う (全部が同じコマンド名のため)
3. 各 stream の Schedule が **`--apply` / `onOneServer()` / `withoutOverlapping()` /
   `onFailure()` の 4 点**と、目録が申告する実行間隔を持ち、
   **`withoutOverlapping()` の有効期限が既定 (24 時間) ではなく
   `overlapExpiryMinutes()` の値である**こと
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

**素の部分文字列照合にしない** (Codex 合議 Round 1 の指摘)。新設コードには
`StuckWorkRecoverySweeper::sweep()` のように、撤去対象と字面が重なる名前が実在するため、
単純な literal 走査は**新設コード自身を落とす**。検出単位は次の 3 種類に限る:

1. **撤去したコマンド名** (完全一致の文字列): `analysis:recover-stale-jobs` /
   `render:recover-stale-jobs` / `billing:release-stale-reservations` /
   `billing:recover-stale-webhook-events` / `capture:release-stale-upload-reservations`
2. **撤去したクラス名** (FQCN と短縮名の両方): `App\Services\Capture\StaleUploadReservationSweeper` /
   `App\DataTransferObjects\Billing\WebhookRecoveryResultDto`
3. **撤去したメソッドの宣言** (`function recoverStale(` / `function releaseStale(` の形)。
   `sweep` は新設クラスにも同名メソッドがあるため**メソッド名では判定しない**
   (クラス名の側 = 検出単位 2 で捕まえる)

**保証しないもの (Codex 合議 Round 2 の指摘を受けて範囲を狭めた)**:
インスタンス変数からの呼び出し (`$service->recoverStale()`) は、token だけでは
受信側のクラスを確定できない場合があるため**保証範囲に入れない**。
「呼び出しが残っていれば必ず落ちる」とは書かない — 撤去の担保は
(a) 旧メソッドの**宣言**が存在しないこと、(b) 旧クラス名・旧コマンド名が存在しないこと、
(c) `composer test` が緑であること (宣言が消えているので呼び出しが残れば実行時に落ちる) の
3 つの組み合わせで得る。
- 走査対象は `app/` `routes/` `config/` `tests/` `database/` と docs の運用正本
  (`AGENTS.md` / `DESIGN.md` を含む)。**実装時に `database/` を足した** — migration の docblock に
  旧コマンド名が 3 箇所実在し、直したあと再流入を止める先が無いと目録の意味が半分になるため。
  **走査対象から外す**: `devnotes/` と `docs/TODO-closed.md` (過去の記録であり書き換えさせない)
- 走査の基盤は既存の `Tests\Support\PhpReferenceScanner` / `PhpTokenScan` を使い、
  自前の正規表現を新設しない (既存の目録群と同じ土台に乗せる)

### 保証しないもの (誇張しない。gate の docblock に書く)

- 目録は**申告の集合一致**を見るだけで、`recover()` が実際に行ロック下で述語を
  再評価しているかは検査できない (それは各 stream の Feature テストが担う)
- 撤去 gate が保証するのは**宣言とクラス名・コマンド名の不在**まで。
  変数経由の呼び出しは token 走査では受信側クラスを確定できないため対象外 (上記)
- Schedule の検査は**登録内容**を見るだけで、scheduler が実際に動いているかは検査できない
  (運用側の監視対象)
- 撤去 gate は literal 照合なので、動的に組み立てた文字列 (`'analysis:'.$suffix`) には沈黙する

### テスト計画

- [ ] gate 自身が vacuous でないことを示す: 目録から 1 件消す / `--apply` を外す /
      `sweepItemLimit` の値を変える / `withoutOverlapping()` の期限を既定へ戻す /
      旧コマンド名を docs へ書き戻す / 旧メソッド宣言を復活させる、の 6 変異で
      赤くなることを実装時に手で確認し、失敗メッセージに次の作業 (登録先と理由) を書く
- [ ] 新設分類 `IdFromRecoveryCandidateEnumeration` の検査が vacuous でないこと:
      `entryPoint` を実在しない名前にする / stream 以外の app/ ファイルから `entryPoint` を呼ぶ /
      **StreamInternal 形の private ヘルパを別ファイルから呼ぶ**、の 3 変異で赤くなることを確認する
- [ ] 個別の `DatabaseTransactions` は使わない (Architecture レーン)

### リスク

- 新しい定期実行を足すたびに目録の更新が要る。これは意図した摩擦 (deny-by-default) であり、
  失敗メッセージで登録先を案内する

---

## 施策 10: 目録・docs の波及更新

### 変更箇所

- `app/Enums/Security/DirectFetchJustification.php` — 分類 case を 1 つ追加
- `app/Enums/Security/RecoveryFetchShape.php` (新) — 入口の形 2 値 (DomainService / StreamInternal)
- `tests/Support/Security/DirectFetchJustificationEntry.php` — 名前付きコンストラクタを 1 つ追加
- `tests/Architecture/ModelDirectFetchInvariantTest.php` — 新 case の検査ブロックを 1 本追加
- `tests/Support/Security/DirectFetchInventory.php` — 旧 4 エントリを削除し、移設先で 5 件登録
- `docs/architecture.md` — 5 箇所のコマンド名 + 監視対象の語彙 + 新セクション
- `docs/template-divergence.md` — 正典からの意図的な逸脱 3 件
- `AGENTS.md` — ドメイン固有規約に「滞留回収の単一入口と目録」を 1 項追加

### DirectFetchInventory の更新内容

旧 4 エントリ (`TicketLedgerService#releaseStale` / `StaleUploadReservationSweeper#sweep` /
`AnalysisJobService#recoverStale` / `RenderJobService#recoverStale`) は**メソッドごと消える**ので
削除し、移設先で登録し直す。

**既存の分類はどれも当てはまらないので、分類語彙を 1 つ増やす** (Codex 合議 Round 2 の指摘。
当てはまらない分類を「機械検査だけ通るから」と流用しない):

- `IdDerivedFromSameMethodQuery` は使えない — 候補の列挙と主キーでの取り直しが別メソッドになり、
  gate の `identityDerivedFromSameMethodQuery()` が実際に落ちる
- `IdSuppliedByInternalCaller` も使えない — 機械検査 (private + 引数由来 + request accessor 無し +
  `calledBy` 実在) は通ってしまうが、この case が求める人手の条件は
  「`calledBy` で identity が**解決済みモデルから**確定していること」であり、
  `failStaleJob(int $id)` はクラス外から生の id を受け取るので満たさない。
  通ってしまう分類を選ぶのは、gate に嘘を登録することと同じ
- `OperatorInvokedConsoleCommand` も使えない (`app/Console/Commands/` 配下という機械条件がある)

### 新設する分類 `IdFromRecoveryCandidateEnumeration`

```php
// app/Enums/Security/DirectFetchJustification.php に 1 case 追加
/**
 * identity が「滞留回収の候補列挙が返した主キー」である。
 *
 * 想定は AG-083 標準形の回収 stream (候補は主キーだけを返し、回収は id しか受け取らない形)。
 * 列挙と再取得が別メソッド・別クラスに分かれるため IdDerivedFromSameMethodQuery は使えず、
 * 公開の口が生の id を受け取るため IdSuppliedByInternalCaller の前提も満たさない。
 *
 * 適用条件 (すべて機械検査する):
 * - 主キークエリを含むメソッドが private で、identity がその引数である
 * - 同一メソッドに request accessor が 1 つも無い (HTTP 入力を経由しない)
 * - entry が申告する `entryPoint` (`Class::method`) が実在し、その本文が当該 private を呼ぶ
 * - 申告された stream が registry と回収目録の両方に登録済みである
 * - **入口の形 (`RecoveryFetchShape`) ごとの封じ込め検査**を通る (下記)
 */
case IdFromRecoveryCandidateEnumeration = 'id_from_recovery_candidate_enumeration';
```

**入口の形は 2 つある** (Codex 合議 Round 4 の指摘。撮影アップロードだけ構造が違う):

| 形 | 構造 | 封じ込め検査 |
|---|---|---|
| `DomainService` | `Stream::recover` → `Service::<entryPoint>` (public) → private ヘルパ | **`entryPoint` のメソッド名が `app/` 配下に現れるファイルの集合が、同じメソッド名を申告する全 entry の「宣言ファイル + 申告 stream のファイル」の合併と一致**すること |
| `StreamInternal` | `Stream::recover` → 同一 stream クラスの private ヘルパ | **private ヘルパのメソッド名が `app/` 配下に現れるファイルが、その stream のファイル 1 つだけ**であること (`entryPoint` は `Stream::recover` 自身) |

`recover()` の呼び出し元 (sweeper) は interface 経由の多態なので、**受信側クラスの型解決には
依存しない検査にする**。上のとおり「メソッド名が現れるファイルの集合」で判定すれば
静的に決定でき、型推論を必要としない。

> **実装時の修正 (2 つ → 合併)**: 解析とレンダは**同じ入口名 `failStaleJob` を別クラスで宣言する**
> (用途は同じで、クラスが違えば衝突しない)。素朴に「2 つだけ」と数えると、解析側の entry が
> レンダ側の 2 ファイルを「申告外」として落としてしまう。そこで **同じメソッド名を申告している
> entry すべての「宣言ファイル + stream ファイル」を合併した集合**と一致することを見る形にした。
> 検出力は落ちていない — 目録に無いファイルからその名前を呼べば依然として赤になる
> (実装時に変異 3 種で確認済み: 入口名を実在しない名前にする / 目録外のクラスから入口を呼ぶ /
> StreamInternal 形の private ヘルパを別ファイルから呼ぶ)。

**数えるのは PHP トークン上の識別子 (メソッド宣言と呼び出し) だけ**で、
コメントや文字列リテラルの中の同名は数えない (既存の `PhpTokenScan` を使う)。
将来の実装者が単純な文字列検索へ置き換えると偽陽性・偽陰性の両方が出るため、
この前提を検査の docblock とテスト名に明記する。

`DirectFetchJustificationEntry` に名前付きコンストラクタ
`recoveryCandidate(string $reason, string $entryPoint, string $stream, RecoveryFetchShape $shape)`
を追加し、`ModelDirectFetchInvariantTest` に上記 2 形の検査ブロックを足す
(既存 case の検査には触れない = 他の登録への影響が無いことを差分で示せる)。

**保証しないもの (docblock と失敗メッセージに書く)**: 文字列で組み立てた動的呼び出し
(`$service->{$method}()`) と、`app/` の外 (テスト等) からの呼び出しは対象外である。
「回収以外から呼ばれないことが証明されている」とは書かない。
ただし**メソッド名の出現ファイル集合**という決定可能な形にしてあるので、
「型を解決できないから沈黙する」という穴は無い (解決不能で素通しにはならない)。

### 登録するエントリ (5 件)

| 登録先 (private ヘルパ) | entryPoint | stream | 形 |
|---|---|---|---|
| `AnalysisJobService::lockStaleJob` | `App\Services\Manual\AnalysisJobService::failStaleJob` | `analysis_job` | DomainService |
| `RenderJobService::lockStaleJob` | `App\Services\Manual\RenderJobService::failStaleJob` | `render_job` | DomainService |
| `TicketLedgerService::lockExpiredReservation` | `App\Services\Billing\TicketLedgerService::releaseExpiredReservation` | `ticket_reservation` | DomainService |
| `StripeWebhookProcessor::claimStale` (whereKey 化) | `App\Services\Billing\StripeWebhookProcessor::recoverStuckEvent` | `webhook_event` | DomainService |
| `StaleUploadReservationStream::releaseIfStillStale` | `App\Services\Recovery\Streams\StaleUploadReservationStream::recover` | `upload_reservation` | StreamInternal |

**登録は「メソッド単位」ではなく「クエリの出現単位」で棚卸しする**
(目録のキーは `パス#メソッド#Model.whereKey:$id#N` の形で、同一メソッド内の 2 本目は `#2` になる)。
本設計では、実装時に主キー同一性クエリが**各 private ヘルパに 1 本ずつ**しか立たない形へ
そろえてある (通知のための引き直しを排し、撮影アップロードも CAS + 再取得の 2 本ではなく
行ロック 1 本にした)。実装時に 5 件を超えたら、増えた分も同じ分類で登録する
(数を合わせるために引き直しを闇に葬らない)。

候補列挙側 (`staleJobIds` / `expiredReservationIds` / stream の `candidateIds`) は
主キー同一性クエリではないので本目録の母集団に入らない (既存の走査と同じ扱い)。

### docs/architecture.md の更新

- §AI 解析 / §レンダ / §撮影 PWA / §Stripe webhook の滞留回収 の
  コマンド名を `work:recover-stuck --stream=<key>` へ差し替える
- §Stripe webhook の滞留回収 の監視対象 3 点を新しい語彙へ言い換え、
  **旧語彙から新語彙への対応表を docs に残す** (運用者が旧語彙で探して見つからない状態を作らない。
  監視がコード外 (人手の runbook / ログ検索) にある可能性を前提にする):

  | 旧 (撤去済みコマンドの出力) | 新 (`work:recover-stuck` の出力) |
  |---|---|
  | `replayed` | `recovered` |
  | `retry-scheduled` | `deferred` |
  | `moved-to-recovery-pending` | `escalated` |
  | `skipped` | `skipped` |
  | `recovered N stale analysis job(s)` / `render` | `recovered` |
  | `released N stale reservation(s)` / `upload reservation(s)` | `recovered` |
- 新セクション **§滞留回収の共通基盤** を追加し、次を正本として書く:
  stream 契約 / 実効上限とページ送りの違い / dry-run が数えるものの意味 /
  結果の種類 5 値 / **監視対象 5 つ** (`errors` / `deferred` / `escalated` /
  `cleanup-failed` / `limit-reached`。とくに **`deferred` は `errors` に出ない**ため
  独立した監視対象である) / 多重起動抑止の有効期限と「期限を超えた実行は並行しうる」限界 /
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

- [ ] `ModelDirectFetchInvariantTest` が新分類の適用条件 (private + 引数由来 +
      request accessor 無し + `entryPoint` の実在と呼び出し + **形ごとの封じ込め検査**
      (メソッド名が現れるファイル集合) + stream が registry と回収目録に登録済み) を
      すべて実走で検査する
- [ ] docs の更新は撤去 gate (施策 9) が旧コマンド名の残存を機械的に検出する
- [ ] `AGENTS.md` の追記に対応する gate は施策 9 の 2 本 (規約だけを増やさない)

### リスク

- 新分類 `IdFromRecoveryCandidateEnumeration` の封じ込め検査は
  **メソッド名が現れるファイル集合**で判定する (型推論に依存しないので「解決できないから素通し」は
  起きない)。ただし**文字列で組み立てた動的呼び出し**と **`app/` の外からの呼び出し**は
  対象外である。この限界を case の docblock と gate の失敗メッセージに書き、
  「回収以外から呼ばれないことが証明されている」とは書かない
- entryPoint のメソッド名が他の用途と衝突すると封じ込め検査が偽陽性を出す。
  名前は用途固有 (`failStaleJob` / `releaseExpiredReservation` / `recoverStuckEvent`) にする
- 分類語彙を増やすこと自体のコスト (enum + 名前付きコンストラクタ + 検査 1 本)。
  既存 case の検査には触れないため、他の登録への影響は差分で確認できる

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

## 実装差分 (app / tests / routes / config / database)

```diff
diff --git a/app/Console/Commands/Capture/PurgeUploadReservationsCommand.php b/app/Console/Commands/Capture/PurgeUploadReservationsCommand.php
new file mode 100644
index 0000000..4fa5ff9
--- /dev/null
+++ b/app/Console/Commands/Capture/PurgeUploadReservationsCommand.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Capture;
+
+use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Models\TakeUploadReservation;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Webmozart\Assert\Assert;
+
+/**
+ * 解放済み / 登録済みのアップロード予約のうち、保持期間
+ * (capture.released_reservation_retention_days) を超えた行を物理削除する。
+ *
+ * **これは滞留回収ではなく保持期間の決着**なので、回収 (work:recover-stuck) とは別の入口に
+ * 分ける (既存の inquiry:purge / idempotency:prune と同じ位置付け)。物理削除は肥大の防止で
+ * 緊急性が無いため日次で足りる。
+ */
+class PurgeUploadReservationsCommand extends Command
+{
+    /** @var string */
+    protected $signature = 'capture:purge-upload-reservations';
+
+    /** @var string */
+    protected $description = '保持期間を過ぎたアップロード予約 (released / completed) を物理削除する';
+
+    public function handle(): int
+    {
+        $cutoff = CarbonImmutable::now()
+            ->subDays(config()->integer('capture.released_reservation_retention_days'));
+
+        $deleted = TakeUploadReservation::query()
+            ->whereIn('status', [TakeUploadReservationStatus::Released, TakeUploadReservationStatus::Completed])
+            ->where('updated_at', '<', $cutoff)
+            ->delete();
+        Assert::integer($deleted, 'delete() は削除件数を返す');
+
+        $this->info("purged {$deleted} upload reservation(s)");
+
+        return self::SUCCESS;
+    }
+}
diff --git a/app/Console/Commands/Operations/RecoverStuckWorkCommand.php b/app/Console/Commands/Operations/RecoverStuckWorkCommand.php
new file mode 100644
index 0000000..4d6bf93
--- /dev/null
+++ b/app/Console/Commands/Operations/RecoverStuckWorkCommand.php
@@ -0,0 +1,126 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Operations;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\DataTransferObjects\Recovery\StreamSweepResultDto;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Recovery\StuckWorkRecoverySweeper;
+use App\Services\Recovery\StuckWorkStreamRegistry;
+use Illuminate\Console\Command;
+use InvalidArgumentException;
+use Webmozart\Assert\Assert;
+
+/**
+ * 滞留した処理・予約を回収する唯一の入口 (AG-083 標準形 v1)。
+ *
+ * --stream 省略時は全系列を掃引する。--apply が無ければ実行せず候補を数えるだけ。
+ * --limit は**手動実行の試し打ち用**の総件数上限で、付けると先頭側しか見ない。
+ */
+class RecoverStuckWorkCommand extends Command
+{
+    /** @var string */
+    protected $signature = 'work:recover-stuck
+        {--stream= : 対象の系列 (省略時は全系列)}
+        {--limit= : 1 系列あたりの処理件数上限 (手動実行用。既定は無制限)}
+        {--apply : 実際に回収する (既定は数えるだけ)}';
+
+    /** @var string */
+    protected $description = '滞留した処理・予約を回収する (既定は数えるだけ。回収するには --apply)';
+
+    public function handle(StuckWorkStreamRegistry $registry, StuckWorkRecoverySweeper $sweeper): int
+    {
+        // 引数の解釈は例外で失敗を表す。`?int` を返す形にすると「未指定」と「不正値」が
+        // 同じ null になり、不正値が無制限の実行へ落ちる
+        try {
+            $streams = $this->resolveStreams($registry);
+            $limit = $this->resolveLimit();
+        } catch (InvalidArgumentException $exception) {
+            $this->error($exception->getMessage());
+
+            return self::FAILURE;
+        }
+
+        $apply = (bool) $this->option('apply');
+
+        $failures = 0;
+        foreach ($streams as $stream) {
+            $result = $sweeper->sweep($stream, $apply, $limit);
+            $failures += $result->failures;
+            $this->line($this->format($result));
+        }
+
+        return $failures === 0 ? self::SUCCESS : self::FAILURE;
+    }
+
+    /**
+     * --stream の解決 (未指定は全系列)。
+     *
+     * @return list<StuckWorkStream>
+     */
+    private function resolveStreams(StuckWorkStreamRegistry $registry): array
+    {
+        $option = $this->option('stream');
+        if ($option === null || $option === '') {
+            return $registry->all();
+        }
+
+        $stream = RecoveryStream::tryFrom($option);
+        if ($stream === null) {
+            throw new InvalidArgumentException('--stream の値が不正です: '.$option.'。'.self::validStreamsHint());
+        }
+
+        return [$registry->get($stream)];
+    }
+
+    /**
+     * --limit の解決。**未指定のときだけ null** を返し、不正値は例外にする
+     * (誤操作が「無制限で走る」に落ちないようにする)。
+     *
+     * @return positive-int|null
+     */
+    private function resolveLimit(): ?int
+    {
+        $option = $this->option('limit');
+        if ($option === null) {
+            return null;
+        }
+        if (preg_match('/^[1-9][0-9]*$/', $option) !== 1) {
+            throw new InvalidArgumentException('--limit には 1 以上の整数を指定してください (指定値: '.$option.')');
+        }
+
+        $limit = (int) $option;
+        Assert::positiveInteger($limit); // 上の照合が 1 以上を保証する。型としても正に閉じる
+
+        return $limit;
+    }
+
+    /** 1 行 1 系列。数えるだけのときは候補が「実際に回収される件数の上界」であることを明示する */
+    private function format(StreamSweepResultDto $result): string
+    {
+        return sprintf(
+            '%s: mode=%s candidates=%d recovered=%d cleanup-failed=%d skipped=%d deferred=%d escalated=%d errors=%d limit-reached=%s',
+            $result->stream->value,
+            $result->applied ? 'apply' : 'dry-run (candidates は回収件数の上界)',
+            $result->candidates,
+            $result->count(RecoveryOutcome::Recovered),
+            $result->count(RecoveryOutcome::RecoveredWithCleanupFailure),
+            $result->count(RecoveryOutcome::Skipped),
+            $result->count(RecoveryOutcome::Deferred),
+            $result->count(RecoveryOutcome::Escalated),
+            $result->failures,
+            $result->limitReached ? 'yes' : 'no',
+        );
+    }
+
+    private static function validStreamsHint(): string
+    {
+        return '有効な値: '.implode(' / ', array_map(
+            static fn (RecoveryStream $stream): string => $stream->value,
+            RecoveryStream::cases(),
+        ));
+    }
+}
diff --git a/app/Contracts/Recovery/StuckWorkStream.php b/app/Contracts/Recovery/StuckWorkStream.php
new file mode 100644
index 0000000..fbb6ca7
--- /dev/null
+++ b/app/Contracts/Recovery/StuckWorkStream.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Contracts\Recovery;
+
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use Carbon\CarbonImmutable;
+
+/**
+ * 滞留回収の 1 系列。**候補は主キーだけを返し、回収は id しか受け取らない**。
+ *
+ * 行の内容を持ち回れないので、回収側は必ず行を取り直して述語を再評価することになる
+ * (= 候補を集めた後に正常へ進んだものを誤って失敗にする事故が構造的に起きない)。
+ *
+ * $sweptAt は掃引の開始時刻で、候補列挙と再評価で**同じ現在時刻**を使うために渡す
+ * (行の内容ではないので上の強制は壊れない)。
+ */
+interface StuckWorkStream
+{
+    public function stream(): RecoveryStream;
+
+    /**
+     * 候補の主キーを昇順で最大 $pageSize 件返す ($afterId より大きいものだけ)。
+     *
+     * @param  positive-int|null  $afterId  前ページの最後の主キー (先頭ページは null)
+     * @param  positive-int  $pageSize
+     * @return list<positive-int>
+     */
+    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array;
+
+    /**
+     * 1 件を回収する。**行を取り直して述語を再評価する責務はこの実装側にある**。
+     *
+     * 競合・条件不成立は例外ではなく RecoveryOutcome::Skipped を返す。
+     * 例外を投げてよいのは本当の不変条件違反だけで、掃引側が report して次へ進む。
+     *
+     * @param  positive-int  $id
+     */
+    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome;
+
+    /**
+     * 1 回の掃引で処理する件数の上限 (null = 無制限)。
+     * 撮影アップロードだけが 500 を申告する (S3 の入出力を有界にする既存の判断)。
+     *
+     * @return positive-int|null
+     */
+    public function sweepItemLimit(): ?int;
+}
diff --git a/app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php b/app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php
deleted file mode 100644
index 97f5ac3..0000000
--- a/app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php
+++ /dev/null
@@ -1,28 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\DataTransferObjects\Billing;
-
-/**
- * 滞留回収 1 実行分の結果。
- *
- * **任意メタデータ領域は持たせない** (`BillingRetentionPurgeResultDto` と同じ方針。
- * 型で分からない領域を作ると organization id 等が運用ログへ漏れる)。
- *
- * 件数の意味:
- *   replayed               = 再実行して processed まで終局した件数
- *   retryScheduled         = 再実行が失敗し received のまま次回の回収へ回した件数
- *   movedToRecoveryPending = 自動再実行の対象外として回収待ちへ置いた件数
- *   skipped                = 何もしなかった件数 (受理条件を満たさない / 行が無い /
- *                            書き込みが別の世代に追い越された)
- */
-final readonly class WebhookRecoveryResultDto
-{
-    public function __construct(
-        public int $replayed,
-        public int $retryScheduled,
-        public int $movedToRecoveryPending,
-        public int $skipped,
-    ) {}
-}
diff --git a/app/DataTransferObjects/Recovery/StreamSweepResultDto.php b/app/DataTransferObjects/Recovery/StreamSweepResultDto.php
new file mode 100644
index 0000000..5fc7114
--- /dev/null
+++ b/app/DataTransferObjects/Recovery/StreamSweepResultDto.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Recovery;
+
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+
+/**
+ * stream 1 本の掃引結果。**任意メタデータの領域は持たせない**
+ * (型で分からない領域を作ると主キー等が運用ログへ漏れる)。
+ *
+ * $limitReached は「上限に達し、かつ**未処理の候補が実在する**」ときだけ true にする
+ * (ちょうど上限件数で候補が尽きた場合は false = 打ち切りではない)。
+ */
+final readonly class StreamSweepResultDto
+{
+    /** @param  array<value-of<RecoveryOutcome>, int<0, max>>  $outcomes */
+    public function __construct(
+        public RecoveryStream $stream,
+        public bool $applied,
+        public int $candidates,
+        public array $outcomes,
+        public int $failures,
+        public bool $limitReached,
+    ) {}
+
+    public function count(RecoveryOutcome $outcome): int
+    {
+        return $this->outcomes[$outcome->value] ?? 0;
+    }
+}
diff --git a/app/Enums/Billing/BillingRetentionExclusion.php b/app/Enums/Billing/BillingRetentionExclusion.php
index 4fe79fa..1eb79d8 100644
--- a/app/Enums/Billing/BillingRetentionExclusion.php
+++ b/app/Enums/Billing/BillingRetentionExclusion.php
@@ -58,7 +58,7 @@ public function rationale(): string
             self::BillingNotification => 'メール送達の重複防止台帳。UNIQUE が冪等の調停者であり、消すと同じ請求書の通知が再送される。'
                 .'保持ポリシーの所有者は課金リマインダ機能である',
             self::TicketReservation => 'TTL で解放される一時状態であって取引記録ではない。'
-                .'所有者は既存の billing:release-stale-reservations である',
+                .'所有者は既存の滞留回収 (work:recover-stuck --stream=ticket_reservation) である',
             self::Plan => '価格カタログ (現在提供している商品の定義) であって取引の記録ではない',
             self::PlanPrice => 'Stripe Price のカタログ snapshot であって取引の記録ではない。過去行は価格改定の履歴として残す',
             self::TicketVolumePrice => 'チケット単価のカタログ snapshot であって取引の記録ではない。過去行は価格改定の履歴として残す',
diff --git a/app/Enums/Billing/WebhookReplaySafety.php b/app/Enums/Billing/WebhookReplaySafety.php
index 9b90e16..b7475f9 100644
--- a/app/Enums/Billing/WebhookReplaySafety.php
+++ b/app/Enums/Billing/WebhookReplaySafety.php
@@ -11,7 +11,7 @@
  * 「再実行すれば復旧する」ではない (復旧するかどうかは各ハンドラの事情による)。
  *
  * 分類の単一出典は `HandledStripeWebhookEvent::replaySafety()` の網羅 match で、
- * 滞留回収 (`StripeWebhookProcessor::recoverStale`) が自動再実行の可否に使う唯一の判断材料。
+ * 滞留回収 (`StripeWebhookProcessor::recoverStuckEvent`) が自動再実行の可否に使う唯一の判断材料。
  * **ハンドラに副作用を足したら分類を再審査すること** (順序に依存する書き込みを足したら
  * `OrderSensitive` へ移す)。
  */
diff --git a/app/Enums/Manual/JobStatus.php b/app/Enums/Manual/JobStatus.php
index 7d14b8c..78075d6 100644
--- a/app/Enums/Manual/JobStatus.php
+++ b/app/Enums/Manual/JobStatus.php
@@ -15,7 +15,7 @@ enum JobStatus: string
     case Succeeded = 'succeeded';
     case Failed = 'failed';
 
-    /** terminal (成否確定) か。failJob / recoverStale の guard に使う */
+    /** terminal (成否確定) か。failJob / 滞留回収の guard に使う */
     public function isTerminal(): bool
     {
         return $this === self::Succeeded || $this === self::Failed;
diff --git a/app/Enums/Recovery/NonRecoveryScheduleReasonKind.php b/app/Enums/Recovery/NonRecoveryScheduleReasonKind.php
new file mode 100644
index 0000000..c60698c
--- /dev/null
+++ b/app/Enums/Recovery/NonRecoveryScheduleReasonKind.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Recovery;
+
+/**
+ * 定期実行のうち「滞留回収ではない」ものの区分。
+ *
+ * 滞留回収の入口は work:recover-stuck ただ 1 本という規約を deny-by-default で保つため、
+ * Schedule に載る他のコマンドはすべてこの区分と 30 文字以上の理由付きで目録へ登録する。
+ * 未分類のコマンドが Schedule に現れたら目録 gate が落ちる (6 本目の独自回収を素通しで足せない)。
+ */
+enum NonRecoveryScheduleReasonKind: string
+{
+    /** 外部サービスを真実として自分の状態を収束させる (DB の状態だけでは行き先が決まらない) */
+    case ExternalReconciliation = 'external_reconciliation';
+
+    /** 生成物の後始末 (世代交代済みの出力の削除など。滞留の前進ではない) */
+    case ArtifactCleanup = 'artifact_cleanup';
+
+    /** 通知の送信 */
+    case Notification = 'notification';
+
+    /** 検知だけを行い状態を書かない */
+    case DetectionOnly = 'detection_only';
+
+    /** 保持期間の決着 (期限を過ぎた記録の削除・畳み込み) */
+    case RetentionSettlement = 'retention_settlement';
+}
diff --git a/app/Enums/Recovery/RecoveryOutcome.php b/app/Enums/Recovery/RecoveryOutcome.php
new file mode 100644
index 0000000..e4599aa
--- /dev/null
+++ b/app/Enums/Recovery/RecoveryOutcome.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Recovery;
+
+/**
+ * 回収 1 件の結果。**この 5 値がすべて**で、集計側は既定の分岐を持たない match で処理する。
+ *
+ * Recovered                   = 業務状態を前へ進めた
+ * RecoveredWithCleanupFailure = 業務状態は前へ進めたが、付随する後始末に失敗した
+ *                               (撮影アップロードの S3 削除失敗。件数を Recovered に畳まない)
+ * Skipped                     = 競合・条件不成立で何もしなかった (正常事象。失敗ではない)
+ * Deferred                    = 前へ進まなかったが次回の掃引へ残した (webhook の再実行失敗)
+ * Escalated                   = 自動回収の対象外へ移し人手へ渡した (webhook の recovery_pending)
+ */
+enum RecoveryOutcome: string
+{
+    case Recovered = 'recovered';
+    case RecoveredWithCleanupFailure = 'recovered_with_cleanup_failure';
+    case Skipped = 'skipped';
+    case Deferred = 'deferred';
+    case Escalated = 'escalated';
+}
diff --git a/app/Enums/Recovery/RecoveryStream.php b/app/Enums/Recovery/RecoveryStream.php
new file mode 100644
index 0000000..1260a63
--- /dev/null
+++ b/app/Enums/Recovery/RecoveryStream.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Recovery;
+
+/**
+ * 滞留回収の対象系列。
+ *
+ * **キーはコマンド引数 (--stream) と Schedule と目録の同一性の基準**である
+ * (定期実行はすべて同じ work:recover-stuck なので、コマンド名では stream の欠落も重複も見えない)。
+ */
+enum RecoveryStream: string
+{
+    case AnalysisJob = 'analysis_job';
+    case RenderJob = 'render_job';
+    case TicketReservation = 'ticket_reservation';
+    case WebhookEvent = 'webhook_event';
+    case UploadReservation = 'upload_reservation';
+
+    /**
+     * 定期実行の間隔 (分)。現行の cron の間隔をそのまま保存する。
+     *
+     * **60 の約数であること** (cron の刻み表記が毎時同じ間隔で回る前提。Unit テストで固定)。
+     */
+    public function cadenceMinutes(): int
+    {
+        return match ($this) {
+            self::AnalysisJob, self::RenderJob,
+            self::TicketReservation, self::WebhookEvent => 5,
+            self::UploadReservation => 10,
+        };
+    }
+
+    /**
+     * 多重起動を抑止するロックの有効期限 (分) = 実行間隔の 2 倍。
+     *
+     * **Laravel 既定 (24 時間) に任せない**。異常終了でロックが残ると、既定では丸 1 日
+     * 回収が止まったまま無音になる (回収基盤が回収を止める)。2 倍にしてあるのは、
+     * 前回の実行が長引いている間の重複起動は抑えつつ、取り残しが最大 2 周期で解けるようにするため。
+     *
+     * **保証範囲を誇張しない**: 有効期限を過ぎるとロックは期限切れとして解けるので、
+     * 正常な実行がこの時間を超えて走っている間は同一系列が並行実行されうる。
+     * 多重起動しても状態が壊れないことは各 stream の行ロック下の再評価が担保する。
+     */
+    public function overlapExpiryMinutes(): int
+    {
+        return $this->cadenceMinutes() * 2;
+    }
+}
diff --git a/app/Enums/Security/DirectFetchJustification.php b/app/Enums/Security/DirectFetchJustification.php
index 8aecbf5..cc92182 100644
--- a/app/Enums/Security/DirectFetchJustification.php
+++ b/app/Enums/Security/DirectFetchJustification.php
@@ -97,6 +97,28 @@ enum DirectFetchJustification: string
      */
     case IdSuppliedByInternalCaller = 'id_supplied_by_internal_caller';
 
+    /**
+     * identity が「滞留回収の候補列挙が返した主キー」である。
+     *
+     * 想定は滞留回収の標準形 (候補は主キーだけを返し、回収は id しか受け取らない形)。
+     * 列挙と再取得が別メソッド・別クラスに分かれるため IdDerivedFromSameMethodQuery は使えず、
+     * 公開の口が生の id を受け取るため IdSuppliedByInternalCaller の前提も満たさない。
+     *
+     * 適用条件 (すべて機械検査する):
+     * - 主キークエリを含むメソッドが private で、identity がその引数である
+     * - 同一メソッドに request accessor が 1 つも無い (HTTP 入力を経由しない)
+     * - entry が申告する `entryPoint` (`Class::method`) が実在し、その本文が当該 private を呼ぶ
+     * - 申告された系列が registry と回収の目録の両方に登録済みである
+     * - **入口の形 (`RecoveryFetchShape`) ごとの封じ込め検査**を通る
+     *
+     * **保証しないもの**: 文字列で組み立てた動的な呼び出し (`$service->{$method}()`) と、
+     * `app/` の外 (テスト等) からの呼び出しは対象外である。
+     * 「回収以外から呼ばれないことが証明されている」とは書かない。
+     * ただし封じ込めの検査は「メソッド名が現れるファイルの集合」という決定可能な形なので、
+     * 「型を解決できないから沈黙する」という穴は無い (解決不能で素通しにはならない)。
+     */
+    case IdFromRecoveryCandidateEnumeration = 'id_from_recovery_candidate_enumeration';
+
     /** local 専用の診断経路。route 登録自体が local 限定で production から到達不能。 */
     case LocalOnlyDiagnostics = 'local_only_diagnostics';
 
diff --git a/app/Enums/Security/RecoveryFetchShape.php b/app/Enums/Security/RecoveryFetchShape.php
new file mode 100644
index 0000000..97934e8
--- /dev/null
+++ b/app/Enums/Security/RecoveryFetchShape.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 滞留回収が主キーで行を取り直すときの「入口の形」。
+ *
+ * 形ごとに封じ込めの検査が違うため、目録の登録で明示させる
+ * (`tests/Architecture/ModelDirectFetchInvariantTest.php` が形ごとの検査を実走する。
+ * テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ */
+enum RecoveryFetchShape: string
+{
+    /**
+     * 回収の系列 → ドメインサービスの公開メソッド → 同クラスの private ヘルパ。
+     *
+     * 封じ込めの検査: 公開メソッドの名前が `app/` 配下に現れるファイルが、
+     * 宣言したファイルと申告した系列のファイルの組だけに収まっていること。
+     */
+    case DomainService = 'domain_service';
+
+    /**
+     * 回収の系列 → 同じ系列クラスの private ヘルパ (ドメインサービスを挟まない)。
+     *
+     * 封じ込めの検査: private ヘルパの名前が `app/` 配下に現れるファイルが、
+     * その系列のファイル 1 つだけであること。
+     */
+    case StreamInternal = 'stream_internal';
+}
diff --git a/app/Jobs/Manual/RunManualAnalysis.php b/app/Jobs/Manual/RunManualAnalysis.php
index f4a4926..2dc954a 100644
--- a/app/Jobs/Manual/RunManualAnalysis.php
+++ b/app/Jobs/Manual/RunManualAnalysis.php
@@ -19,7 +19,7 @@
  * - payload は analysisJobId のみ (モデル/チケット/org 値を payload に持たない = payload 不信任)
  * - 専用 connection database-analysis (retry_after=1680) で流す。運用契約:
  *   本番/ステージングは `php artisan queue:work database-analysis` を worker 定義に必須登録
- *   (docs/architecture.md。滞留は recoverStale cron が 30 分で failJob する)
+ *   (docs/architecture.md。滞留は work:recover-stuck --stream=analysis_job が 30 分で失敗確定する)
  */
 class RunManualAnalysis implements ShouldQueue
 {
diff --git a/app/Jobs/Manual/RunManualRender.php b/app/Jobs/Manual/RunManualRender.php
index b12af44..a43bfb1 100644
--- a/app/Jobs/Manual/RunManualRender.php
+++ b/app/Jobs/Manual/RunManualRender.php
@@ -21,7 +21,7 @@
  * - payload は renderJobId のみ (モデル/チケット/org 値を payload に持たない = payload 不信任)
  * - 専用 connection database-render (retry_after=1680) で流す。運用契約:
  *   本番/ステージングは `php artisan queue:work database-render` を worker 定義に必須登録
- *   (docs/architecture.md。滞留は recoverStale cron が queued=10 分 / running=30 分で failJob する)
+ *   (docs/architecture.md。滞留は work:recover-stuck --stream=render_job が queued=10 分 / running=30 分で失敗確定する)
  */
 class RunManualRender implements ShouldQueue
 {
diff --git a/app/Models/TakeUploadReservation.php b/app/Models/TakeUploadReservation.php
index 0611e3d..71f90f3 100644
--- a/app/Models/TakeUploadReservation.php
+++ b/app/Models/TakeUploadReservation.php
@@ -18,7 +18,7 @@
  * - bytes_pending = org 単位の「pending & 未失効」+「verifying 全件」の size_bytes 合計
  *   (StorageUsageService::bytesPending)
  * - status 遷移は TakeUploadService (insert) / TakeRegistrationService (claim/CAS) /
- *   StaleUploadReservationSweeper (released 化) のみが行う
+ *   滞留回収の StaleUploadReservationStream (released 化) のみが行う
  *
  * @property int $id
  * @property int $cut_id
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 23c6a0e..61fb515 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -5,7 +5,6 @@
 namespace App\Services\Billing;
 
 use App\DataTransferObjects\Billing\StaleWebhookClaimDto;
-use App\DataTransferObjects\Billing\WebhookRecoveryResultDto;
 use App\Enums\Billing\BillingNotificationType;
 use App\Enums\Billing\HandledStripeWebhookEvent;
 use App\Enums\Billing\SignupFundingChoice;
@@ -16,6 +15,7 @@
 use App\Enums\Billing\WebhookStaleClaimOutcome;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
+use App\Enums\Recovery\RecoveryOutcome;
 use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
 use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
 use App\Jobs\Billing\SetDefaultPaymentMethodJob;
@@ -28,6 +28,7 @@
 use App\Models\Organization;
 use App\Notifications\Billing\PaymentFailedNotification;
 use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Log;
 use Laravel\Cashier\Events\WebhookReceived;
@@ -55,7 +56,7 @@
  *    MAX_PROCESSING_ATTEMPTS 到達後は処理せず skip (= 200 terminal-ack) して
  *    恒久失敗イベントの無限 500 ストームを打ち切る (運用は failure_reason で調査する)
  * 5. 滞留回収: 本処理中にプロセスが落ちて received のまま残った行を
- *    recoverStale() が拾い直す (cron: billing:recover-stale-webhook-events)。
+ *    recoverStuckEvent() が拾い直す (定期実行: work:recover-stuck --stream=webhook_event)。
  *    再実行してよい種類かは HandledStripeWebhookEvent::replaySafety() が決め、
  *    対象外・上限到達は recovery_pending + recovery_reason へ置いて止める。
  *    終局書き込みは受理した世代 (attempts) を握っている実行だけが行う条件付き UPDATE。
@@ -84,7 +85,7 @@ class StripeWebhookProcessor
      * **`claim()` の直列化は本処理までは覆わない** (守るのは状態遷移だけで `process()` は
      * トランザクションの外で走る)。そこで落ちた行は `received` のまま残り、Stripe の再送も
      * `claim()` に弾かれて 200 で終わるため付与が無音で失われる。これを塞ぐのが
-     * `recoverStale()` である。運用契約の正本は `docs/architecture.md`
+     * `recoverStuckEvent()` である。運用契約の正本は `docs/architecture.md`
      * の「Stripe webhook の滞留回収」。
      *
      * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
@@ -192,78 +193,83 @@ private function finalize(
     }
 
     /**
-     * 処理中に滞留した webhook 記録の回収 (cron: billing:recover-stale-webhook-events)。
+     * 滞留した webhook 記録 1 件の回収 (定期実行: work:recover-stuck --stream=webhook_event)。
      *
-     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
-     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
-     *
-     * 作法は既存の滞留回収 (`RenderJobService::recoverStale` /
-     * `TicketLedgerService::releaseStale`) と同じ = 対象を列挙 → 1 件ずつ行ロックで
-     * 取り直して再検証 → 件数を返す。**共通の回収基盤は作らない** (ドメインごとの個別実装)。
+     * **掃引 (候補の列挙とループ) は滞留回収の共通基盤が持つ**ので、本メソッドは 1 件だけを
+     * 受け持つ。判断材料と決着の規則は従来どおり:
+     *   - 再実行してよいかは HandledStripeWebhookEvent::replaySafety() だけが決める
+     *   - 回収の失敗は終局させない (received のまま次回へ回す = Deferred)
+     *   - 対象外・試行上限は recovery_pending へ置いて止める (= Escalated)
      *
      * 通知 (`Log::warning` / `report()`) は**トランザクションの外**で出す
      * (状態が保存されていないのに通知だけ出る / 同じ行に複数回出るのを避ける)。
      * ただし commit 後に落ちれば 0 回になる = 送信を 1 回試みるだけで、
      * 厳密な一回配送は保証しない (常設の観測点は `recovery_pending` の件数のほう)。
+     *
+     * @param  positive-int  $id  滞留回収の候補列挙 (StaleWebhookEventStream::candidateIds) が返した主キー
      */
-    public function recoverStale(): WebhookRecoveryResultDto
+    public function recoverStuckEvent(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
     {
-        $threshold = CarbonImmutable::now()
-            ->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));
+        $threshold = self::staleThreshold($sweptAt);
 
-        /** @var list<string> $staleEventIds */
-        $staleEventIds = StripeWebhookEvent::query()
-            ->where('status', WebhookEventStatus::Received->value)
-            ->where('updated_at', '<=', $threshold)
-            ->orderBy('id')
-            ->pluck('event_id')
-            ->all();
+        $claim = $this->claimStale($id, $threshold);
+        if ($claim === null) {
+            return RecoveryOutcome::Skipped; // 行が消えた / 別の実行が先に進めた
+        }
 
-        $replayed = 0;
-        $retryScheduled = 0;
-        $movedToRecoveryPending = 0;
-        $skipped = 0;
+        if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
+            $this->reportRecoveryPending($claim);
 
-        foreach ($staleEventIds as $eventId) {
-            $claim = $this->claimStale($eventId, $threshold);
-            if ($claim === null) {
-                $skipped++; // 行が消えた / 別の実行が先に進めた
+            return RecoveryOutcome::Escalated;
+        }
 
-                continue;
-            }
+        try {
+            $this->process($claim->type, $claim->payload);
+        } catch (Throwable $exception) {
+            report($exception);
 
-            if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
-                $movedToRecoveryPending++;
-                $this->reportRecoveryPending($claim);
+            // **終局させない**: failed にすると回収対象 (received) から外れ、
+            // Stripe も配信成功と認識しているため二度と再試行されない。
+            // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
+            return $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
+                ? RecoveryOutcome::Deferred
+                : RecoveryOutcome::Skipped;
+        }
 
-                continue;
-            }
+        return $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
+            ? RecoveryOutcome::Recovered
+            : RecoveryOutcome::Skipped;
+    }
 
-            try {
-                $this->process($claim->type, $claim->payload);
-            } catch (Throwable $exception) {
-                report($exception);
-                // **終局させない**: failed にすると回収対象 (received) から外れ、
-                // Stripe も配信成功と認識しているため二度と再試行されない。
-                // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
-                $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
-                    ? $retryScheduled++
-                    : $skipped++;
-
-                continue;
-            }
+    /**
+     * 滞留候補の主キーを昇順で返す (回収の候補列挙)。
+     *
+     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
+     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
+     *
+     * @param  positive-int|null  $afterId
+     * @param  positive-int  $pageSize
+     * @return list<positive-int>
+     */
+    public function staleEventIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        /** @var list<positive-int> $ids */
+        $ids = StripeWebhookEvent::query()
+            ->where('status', WebhookEventStatus::Received->value)
+            ->where('updated_at', '<=', self::staleThreshold($sweptAt))
+            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
+            ->orderBy('id')
+            ->limit($pageSize)
+            ->pluck('id')
+            ->all();
 
-            $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
-                ? $replayed++
-                : $skipped++;
-        }
+        return $ids;
+    }
 
-        return new WebhookRecoveryResultDto(
-            replayed: $replayed,
-            retryScheduled: $retryScheduled,
-            movedToRecoveryPending: $movedToRecoveryPending,
-            skipped: $skipped,
-        );
+    /** 滞留とみなす境界時刻 (候補列挙と受理で同じ式を使う) */
+    private static function staleThreshold(CarbonImmutable $sweptAt): CarbonImmutable
+    {
+        return $sweptAt->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));
     }
 
     /**
@@ -276,13 +282,14 @@ public function recoverStale(): WebhookRecoveryResultDto
      * 滞留の再検証は**クエリの WHERE に入れる** (ロック取得後に PostgreSQL が述語を
      * 再評価するため、ロック待ちの間に他の実行が前進させた行は 1 行も返らない)。
      *
+     * @param  positive-int  $id  滞留回収の候補列挙 (staleEventIds) が返した主キー
      * @return StaleWebhookClaimDto|null 処置をしなかったとき (行が無い / 条件を満たさない) は null
      */
-    private function claimStale(string $eventId, CarbonImmutable $threshold): ?StaleWebhookClaimDto
+    private function claimStale(int $id, CarbonImmutable $threshold): ?StaleWebhookClaimDto
     {
-        return DB::transaction(function () use ($eventId, $threshold): ?StaleWebhookClaimDto {
+        return DB::transaction(function () use ($id, $threshold): ?StaleWebhookClaimDto {
             $record = StripeWebhookEvent::query()
-                ->where('event_id', $eventId)
+                ->whereKey($id)
                 ->where('status', WebhookEventStatus::Received->value)
                 ->where('updated_at', '<=', $threshold)
                 ->lockForUpdate()
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index d9c7883..59e67c1 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -38,8 +38,8 @@
  * - 直接デクリメントは書かない。消費を伴う処理は必ず reserve → (成功) commit / (失敗) release
  * - 全操作 transaction + organizations 行ロック (lockForUpdate) で残高判定の
  *   TOCTOU を防止する (並行 reserve のオーバーセル防止)
- * - reserve TTL 超過と失効 monthly hold は billing:release-stale-reservations cron
- *   (releaseStale) が解放する
+ * - reserve TTL 超過と失効 monthly hold は滞留回収 (work:recover-stuck --stream=ticket_reservation)
+ *   が releaseExpiredReservation 経由で解放する
  * - webhook 由来の付与 (grantMonthly / grantSignupGrant / grantPurchased) と
  *   返金逆仕訳 (clawback) は idempotency_key UNIQUE の冪等 insert で二重計上を防ぐ
  * - commit は **commit-wins**: reserve TTL 超過や stale releaser 先着でも生存 hold は課金する
@@ -567,62 +567,112 @@ public function release(TicketReservation $reservation): void
     {
         DB::transaction(function () use ($reservation): void {
             $locked = $this->lockReservationRow($reservation);
-            $organization = $locked->organization;
-            Assert::isInstanceOf($organization, Organization::class);
-            $this->lockOrganizationRow($organization);
-
-            $this->appendEntry(
-                $organization,
-                0,
-                TicketLedgerKind::Release,
-                $locked,
-                "予約 {$locked->id} の解放",
-            );
-
-            $locked->status = TicketReservationStatus::Released;
-            $locked->save();
+            $this->releaseLockedReservation($locked);
         });
 
         $reservation->refresh();
     }
 
     /**
-     * TTL (expires_at) 超過、または失効 monthly hold (consume_expires_at 経過) の reserved 予約を
-     * 解放する (routes/console.php の billing:release-stale-reservations が 5 分毎に実行)。
+     * 滞留予約の解放 (回収経路の唯一の口)。
+     *
+     * **行ロックを取ったうえで滞留の述語ごと再評価する** — reserved であることに加えて
+     * 「TTL 超過 または 失効 monthly hold」を WHERE に入れるので、候補の列挙後に
+     * commit / release された予約や、条件を満たさなくなった予約は 1 行も返らない。
+     * その結果、競合を表す専用例外は要らない (0 行 = false で表せる)。
      *
      * 失効 monthly hold を含めるのは、消費元の grant が既に失効している hold を拘束として
      * 残すと翌期間の残高を侵食するため (commit-wins も当該 hold は no-charge にする)。
      *
-     * @return int 解放した予約数
+     * @param  positive-int  $id  滞留回収の候補列挙 (expiredReservationIds) が返した主キー
+     * @return bool 実際に解放したか
      */
-    public function releaseStale(): int
+    public function releaseExpiredReservation(int $id, CarbonImmutable $sweptAt): bool
     {
-        $now = CarbonImmutable::now();
+        return DB::transaction(function () use ($id, $sweptAt): bool {
+            $locked = $this->lockExpiredReservation($id, $sweptAt);
+            if ($locked === null) {
+                return false; // 並行 commit / release 済み / 述語が不成立になった
+            }
 
-        $staleIds = TicketReservation::query()
-            ->where('status', TicketReservationStatus::Reserved)
-            ->where(function (Builder $query) use ($now): void {
-                $query->where('expires_at', '<=', $now)
-                    ->orWhere(fn (Builder $expired) => $this->expiredMonthlyHoldCondition($expired, $now));
-            })
-            ->pluck('id');
+            $this->releaseLockedReservation($locked);
 
-        $released = 0;
-        foreach ($staleIds as $id) {
-            $reservation = TicketReservation::query()->whereKey($id)->first();
-            if ($reservation === null) {
-                continue;
-            }
-            // release 内で行ロック + 状態再検証するため、競合した予約はそこで弾かれる
-            try {
-                $this->release($reservation);
-                $released++;
-            } catch (LogicException) {
-                // 並行 commit / release 済み: 解放不要
-            }
-        }
+            return true;
+        });
+    }
+
+    /**
+     * 滞留候補の主キーを昇順で返す (回収の候補列挙。述語は applyExpiredPredicate が唯一の正本)。
+     *
+     * 失効 monthly hold の判定式は会計の一部なので**この台帳サービスの中に閉じる**
+     * (回収 stream 側へ複製しない)。
+     *
+     * @param  positive-int|null  $afterId
+     * @param  positive-int  $pageSize
+     * @return list<positive-int>
+     */
+    public function expiredReservationIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        /** @var list<positive-int> $ids */
+        $ids = $this->applyExpiredPredicate(TicketReservation::query(), $sweptAt)
+            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
+            ->orderBy('id')
+            ->limit($pageSize)
+            ->pluck('id')
+            ->all();
+
+        return $ids;
+    }
 
-        return $released;
+    /**
+     * ロック済み予約の解放の本体 (release と releaseExpiredReservation が共有する 1 つの実装)。
+     *
+     * ロック順 (予約 → 組織)、台帳への 0 行追記、Released 化を 2 か所に複製しない。
+     */
+    private function releaseLockedReservation(TicketReservation $locked): void
+    {
+        $organization = $locked->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+        $this->lockOrganizationRow($organization);
+
+        $this->appendEntry(
+            $organization,
+            0,
+            TicketLedgerKind::Release,
+            $locked,
+            "予約 {$locked->id} の解放",
+        );
+
+        $locked->status = TicketReservationStatus::Released;
+        $locked->save();
+    }
+
+    /**
+     * id は回収の候補列挙由来。**候補列挙と同じ述語**を WHERE に入れることでロック後の再評価になる。
+     *
+     * @param  positive-int  $id
+     */
+    private function lockExpiredReservation(int $id, CarbonImmutable $sweptAt): ?TicketReservation
+    {
+        return $this->applyExpiredPredicate(TicketReservation::query()->whereKey($id), $sweptAt)
+            ->lockForUpdate()
+            ->first();
+    }
+
+    /**
+     * 滞留予約の述語 (**この 1 か所だけが正本**):
+     * reserved かつ「TTL (expires_at) 超過 または 失効 monthly hold (consume_expires_at 経過)」。
+     *
+     * @param  Builder<TicketReservation>  $query
+     * @return Builder<TicketReservation>
+     */
+    private function applyExpiredPredicate(Builder $query, CarbonImmutable $sweptAt): Builder
+    {
+        return $query
+            ->where('status', TicketReservationStatus::Reserved)
+            ->where(fn (Builder $outer) => $outer
+                ->where('expires_at', '<=', $sweptAt)
+                ->orWhere(fn (Builder $expired) => $this->expiredMonthlyHoldCondition($expired, $sweptAt)));
     }
 
     /** 残高判定・台帳追記の直列化点 (organizations 行ロック) */
@@ -705,7 +755,7 @@ private function sumBalance(Organization $organization, TicketSource $source, Ca
      *
      * reserve TTL 切れ (expires_at <= now) でも Reserved である限り枠を保持する: commit-wins は
      * TTL 超過でも課金するため、与信側で枠を再開放すると 30 分超ジョブ中に同じ枠が二重予約され
-     * 両方 commit でオーバーセルになる。枠の解放は releaseStale の Released 化に委ねる。
+     * 両方 commit でオーバーセルになる。枠の解放は 滞留回収 (releaseExpiredReservation) の Released 化に委ねる。
      * 失効 monthly hold のみ除外する (grant 自体が消えており commit-wins も no-charge のため)。
      *
      * legacy 行 (consume_source = null) はどちらの出所にも計上されない (aigenba verbatim)。
@@ -724,7 +774,7 @@ private function sumActiveHolds(Organization $organization, TicketSource $source
 
     /**
      * 「失効 monthly hold」の PHP 述語。query 版 expiredMonthlyHoldCondition と同一定義を共有し、
-     * commit / hold 集計 / releaseStale の判定を揃える。
+     * commit / hold 集計 / 滞留回収の判定を揃える。
      *
      * legacy 行 (consume_source = null) は先頭で false になる。
      * consume_source = monthly かつ consume_expires_at = null は「無期限 monthly からの消費」で、
diff --git a/app/Services/Capture/StaleUploadReservationSweeper.php b/app/Services/Capture/StaleUploadReservationSweeper.php
deleted file mode 100644
index 7151d22..0000000
--- a/app/Services/Capture/StaleUploadReservationSweeper.php
+++ /dev/null
@@ -1,80 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Services\Capture;
-
-use App\Enums\Capture\TakeUploadReservationStatus;
-use App\Models\TakeUploadReservation;
-use Illuminate\Contracts\Database\Eloquent\Builder;
-
-/**
- * 孤児掃除 (doc/10 §10.8-4 / 概念設計 D7): 回収対象は
- * (a) expires_at 超過の pending 予約
- * (b) stale な verifying 予約 (updated_at が閾値超過 = 登録リクエストの異常終了)
- * を released 化し (bytes_pending 解放)、S3 に PUT 済みだが未登録のオブジェクトを削除する。
- * **fresh な verifying には触れない** (登録処理の claim 契約と競合しない)。
- * 加えて released/completed の古い行 (retention 超過) を物理削除する。冪等。
- */
-class StaleUploadReservationSweeper
-{
-    /** 1 回の sweep が対象にする予約数の上限 (exists/delete の I/O 回数を抑える) */
-    private const int BATCH_LIMIT = 500;
-
-    public function __construct(
-        private readonly TakeObjectStorage $storage,
-    ) {}
-
-    /** @return int released 化した予約数 */
-    public function sweep(): int
-    {
-        // 時刻境界の一貫性: $now / $cutoff は冒頭で一度だけ生成し、一覧抽出と CAS 条件で共有する
-        $now = now()->toImmutable();
-        $cutoff = $now->subMinutes(config()->integer('capture.stale_verifying_minutes'));
-
-        /** @var list<TakeUploadReservation> $stale */
-        $stale = TakeUploadReservation::query()
-            ->where(function (Builder $query) use ($now, $cutoff): void {
-                $query->where(fn (Builder $q) => $q
-                    ->where('status', TakeUploadReservationStatus::Pending)
-                    ->where('expires_at', '<=', $now))
-                    ->orWhere(fn (Builder $q) => $q
-                        ->where('status', TakeUploadReservationStatus::Verifying)
-                        ->where('updated_at', '<', $cutoff));
-            })
-            ->limit(self::BATCH_LIMIT)
-            ->get()
-            ->all();
-
-        $released = 0;
-        foreach ($stale as $reservation) {
-            // CAS: 一覧取得後に登録処理が completed 化していたら 0 行更新 → 削除しない
-            // (登録確定側の verifying→completed CAS と対。勝者だけが後続処理を行う)
-            $won = TakeUploadReservation::query()
-                ->whereKey($reservation->id)
-                ->where(function (Builder $query) use ($reservation, $now, $cutoff): void {
-                    $reservation->status === TakeUploadReservationStatus::Pending
-                        ? $query->where('status', TakeUploadReservationStatus::Pending)
-                            ->where('expires_at', '<=', $now)
-                        : $query->where('status', TakeUploadReservationStatus::Verifying)
-                            ->where('updated_at', '<', $cutoff);
-                })
-                ->update(['status' => TakeUploadReservationStatus::Released]);
-            if ($won === 0) {
-                continue; // 登録処理が勝った (completed) → オブジェクトは正当な Take の実体
-            }
-            $released++;
-            if ($this->storage->exists($reservation->video_path)) {
-                $this->storage->delete($reservation->video_path); // 未登録オブジェクトの孤児削除
-            }
-        }
-
-        // released/completed の古い行の物理削除 (肥大防止。retention は config)
-        TakeUploadReservation::query()
-            ->whereIn('status', [TakeUploadReservationStatus::Released, TakeUploadReservationStatus::Completed])
-            ->where('updated_at', '<', $now->subDays(config()->integer('capture.released_reservation_retention_days')))
-            ->delete();
-
-        return $released;
-    }
-}
diff --git a/app/Services/Manual/AnalysisJobService.php b/app/Services/Manual/AnalysisJobService.php
index b84cb12..b5b4a1c 100644
--- a/app/Services/Manual/AnalysisJobService.php
+++ b/app/Services/Manual/AnalysisJobService.php
@@ -20,13 +20,14 @@
 use App\Services\Notification\NotificationCenterService;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Database\Query\Builder;
+use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Validation\ValidationException;
 use LogicException;
 use Webmozart\Assert\Assert;
 
 /**
- * AI 解析ジョブの状態機械 (trigger / failJob / recoverStale)。doc/10 §10.8-8。
+ * AI 解析ジョブの状態機械 (trigger / failJob / failStaleJob)。doc/10 §10.8-8。
  *
  * VideoManualStatus 遷移表 (本サービスが関与する遷移。詳細は docs/architecture.md):
  * - draft/ready → analyzing: trigger() (行ロック + from-state guard。violate → 409)
@@ -108,7 +109,7 @@ public function trigger(Project $project, VideoManual $manual, ?User $actor = nu
     }
 
     /**
-     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed / recoverStale の合流点。
+     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed の合流点。
      *
      * - terminal (succeeded/failed) 済みは no-op (terminal tx 勝ち・二重 fail を握る)
      * - manual は analyzing のときのみ復帰 (cuts があれば ready、無ければ draft)
@@ -121,38 +122,8 @@ public function failJob(AnalysisJob $job, string $error): bool
         $failed = DB::transaction(function () use ($job, $error): bool {
             /** @var AnalysisJob $locked */
             $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
-            if ($locked->status->isTerminal()) {
-                return false;
-            }
-
-            // manual を先に lock で取得し (job → manual のロック順を維持)、失敗確定時の
-            // scenario_version を job にスナップショットする (stale alert 判定の順序基準。T032)。
-            /** @var VideoManual $manual */
-            $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
-
-            $locked->status = JobStatus::Failed;
-            $locked->error = $error;
-            $locked->scenario_version_at_terminal = $manual->scenario_version;
-            $locked->save();
-
-            // manual 復帰 (analyzing のときのみ。cuts があれば ready、無ければ draft = 概念設計 §4)
-            if ($manual->status === VideoManualStatus::Analyzing) {
-                $manual->forceFill([
-                    'status' => $manual->cuts()->exists() ? VideoManualStatus::Ready : VideoManualStatus::Draft,
-                ])->save();
-            }
 
-            // 予約 release (Reserved のみ。並行 commit/release 済みは LogicException → 握って冪等)
-            $reservation = $locked->ticketReservation;
-            if ($reservation !== null && $reservation->status === TicketReservationStatus::Reserved) {
-                try {
-                    $this->tickets->release($reservation);
-                } catch (LogicException) {
-                    // 並行 release/commit 済み
-                }
-            }
-
-            return true;
+            return $this->failLockedJob($locked, $error);
         });
 
         // terminal 遷移が実際に起きたときだけ・commit 後に通知する (at-most-once。詳細設計
@@ -166,41 +137,135 @@ public function failJob(AnalysisJob $job, string $error): bool
     }
 
     /**
-     * stale ジョブの回復 (cron)。queued: dispatch 喪失、running: worker 異常終了。
-     * failJob は行ロック + terminal guard で冪等 (TicketLedgerService::releaseStale と同型)。
+     * 滞留ジョブの失敗確定 (回収経路の唯一の口)。
+     *
+     * **行ロックを取ったうえで滞留の述語ごと再評価する** — 候補を列挙してから
+     * ロックを取るまでの間に worker が進捗を書いた running ジョブは 1 行も返らないので、
+     * 正常に動いているものを失敗にしない (誤回収の防止)。
      *
-     * @return int 実際に回復 (failed 遷移) した件数 (走査中に terminal へ先着されたものは数えない)
+     * @param  positive-int  $id  滞留回収の候補列挙 (staleJobIds) が返した主キー
+     * @return bool 実際に failed へ遷移させたか
      */
-    public function recoverStale(): int
+    public function failStaleJob(int $id, CarbonImmutable $sweptAt): bool
     {
-        $threshold = CarbonImmutable::now()->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));
-        $staleIds = AnalysisJob::query()
-            ->where(function (Builder $query) use ($threshold): void {
-                $query
-                    ->where(function (Builder $query) use ($threshold): void {
-                        $query->where('status', JobStatus::Queued->value)
-                            ->where('created_at', '<=', $threshold);
-                    })
-                    ->orWhere(function (Builder $query) use ($threshold): void {
-                        $query->where('status', JobStatus::Running->value)
-                            ->where('updated_at', '<=', $threshold);
-                    });
-            })
-            ->pluck('id');
-
-        $recovered = 0;
-        foreach ($staleIds as $id) {
-            $job = AnalysisJob::query()->whereKey($id)->first();
-            if ($job === null) {
-                continue;
+        $threshold = $this->staleThreshold($sweptAt);
+
+        // 通知のためにモデルを引き直さない (クラス起点の主キークエリを 1 本増やさないため)。
+        // トランザクションからロック済みモデルをそのまま返す
+        $failed = DB::transaction(function () use ($id, $threshold): ?AnalysisJob {
+            $locked = $this->lockStaleJob($id, $threshold);
+            if ($locked === null) {
+                return null; // 述語が成立しない (前進済み / terminal / 進捗が進んだ)
             }
-            // failJob 内で行ロック + terminal guard 再検証するため、競合したジョブはそこで no-op (false)
-            if ($this->failJob($job, '解析がタイムアウトしました。再実行してください。')) {
-                $recovered++;
+
+            return $this->failLockedJob($locked, '解析がタイムアウトしました。再実行してください。')
+                ? $locked
+                : null;
+        });
+
+        if ($failed !== null) {
+            $this->notifications->notifyAnalysisFinished($failed->refresh()); // failJob と同じ形
+        }
+
+        return $failed !== null;
+    }
+
+    /**
+     * 滞留候補の主キーを昇順で返す (回収の候補列挙。述語は applyStalePredicate が唯一の正本)。
+     *
+     * @param  positive-int|null  $afterId
+     * @param  positive-int  $pageSize
+     * @return list<positive-int>
+     */
+    public function staleJobIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        /** @var list<positive-int> $ids */
+        $ids = $this->applyStalePredicate(AnalysisJob::query(), $this->staleThreshold($sweptAt))
+            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
+            ->orderBy('id')
+            ->limit($pageSize)
+            ->pluck('id')
+            ->all();
+
+        return $ids;
+    }
+
+    /**
+     * ロック済みジョブの失敗確定の本体 (failJob と failStaleJob が共有する 1 つの実装)。
+     *
+     * manual のロック順 (job → manual)、terminal guard、予約解放、
+     * scenario_version_at_terminal の書き込みを 2 か所に複製しないためにここへ集約する。
+     */
+    private function failLockedJob(AnalysisJob $locked, string $error): bool
+    {
+        if ($locked->status->isTerminal()) {
+            return false;
+        }
+
+        // manual を先に lock で取得し (job → manual のロック順を維持)、失敗確定時の
+        // scenario_version を job にスナップショットする (stale alert 判定の順序基準。T032)。
+        /** @var VideoManual $manual */
+        $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
+
+        $locked->status = JobStatus::Failed;
+        $locked->error = $error;
+        $locked->scenario_version_at_terminal = $manual->scenario_version;
+        $locked->save();
+
+        // manual 復帰 (analyzing のときのみ。cuts があれば ready、無ければ draft = 概念設計 §4)
+        if ($manual->status === VideoManualStatus::Analyzing) {
+            $manual->forceFill([
+                'status' => $manual->cuts()->exists() ? VideoManualStatus::Ready : VideoManualStatus::Draft,
+            ])->save();
+        }
+
+        // 予約 release (Reserved のみ。並行 commit/release 済みは LogicException → 握って冪等)
+        $reservation = $locked->ticketReservation;
+        if ($reservation !== null && $reservation->status === TicketReservationStatus::Reserved) {
+            try {
+                $this->tickets->release($reservation);
+            } catch (LogicException) {
+                // 並行 release/commit 済み
             }
         }
 
-        return $recovered;
+        return true;
+    }
+
+    /**
+     * id は回収の候補列挙由来。**候補列挙と同じ述語**を WHERE に入れることでロック後の再評価になる。
+     *
+     * @param  positive-int  $id
+     */
+    private function lockStaleJob(int $id, CarbonImmutable $threshold): ?AnalysisJob
+    {
+        return $this->applyStalePredicate(AnalysisJob::query()->whereKey($id), $threshold)
+            ->lockForUpdate()
+            ->first();
+    }
+
+    /** 滞留の閾値 (queued は発生時刻、running は進捗時刻を比べる相手) */
+    private function staleThreshold(CarbonImmutable $sweptAt): CarbonImmutable
+    {
+        return $sweptAt->subMinutes(config()->integer('manual.analysis_stale_after_minutes'));
+    }
+
+    /**
+     * 滞留の述語 (**この 1 か所だけが正本**)。
+     * queued は発生時刻 (created_at)、running は進捗時刻 (updated_at) を起点にする。
+     *
+     * @param  EloquentBuilder<AnalysisJob>  $query
+     * @return EloquentBuilder<AnalysisJob>
+     */
+    private function applyStalePredicate(EloquentBuilder $query, CarbonImmutable $threshold): EloquentBuilder
+    {
+        return $query->where(fn (Builder $outer) => $outer
+            ->where(fn (Builder $queued) => $queued
+                ->where('status', JobStatus::Queued->value)
+                ->where('created_at', '<=', $threshold))
+            ->orWhere(fn (Builder $running) => $running
+                ->where('status', JobStatus::Running->value)
+                ->where('updated_at', '<=', $threshold)));
     }
 
     /**
diff --git a/app/Services/Manual/AnalysisPipeline.php b/app/Services/Manual/AnalysisPipeline.php
index 7776839..de6f65e 100644
--- a/app/Services/Manual/AnalysisPipeline.php
+++ b/app/Services/Manual/AnalysisPipeline.php
@@ -274,7 +274,7 @@ private function runGenerateStep(
      *   - startJob:     analysis_jobs → (reserve: organizations)
      *   - finalize:     analysis_jobs → video_manuals → (commit: ticket_reservations → organizations)
      *   - failJob:      analysis_jobs → video_manuals → (release: ticket_reservations → organizations)
-     *   - releaseStale (billing cron): ticket_reservations → organizations (前方リソースを保持しない)
+     *   - 滞留予約の解放 (課金の定期実行): ticket_reservations → organizations (前方リソースを保持しない)
      *   - ScenarioService::save: video_manuals のみ
      * いずれもグローバル順の部分列であり循環待ちは構成できない。
      *
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index a5e01e7..fdf8993 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -33,7 +33,7 @@
 use Webmozart\Assert\Assert;
 
 /**
- * レンダジョブの状態機械 (trigger / triggerPreview / failJob / recoverStale)。doc/10 §10.8-8。
+ * レンダジョブの状態機械 (trigger / triggerPreview / failJob / failStaleJob)。doc/10 §10.8-8。
  * AnalysisJobService を見本にした個別実装 (§10.8 方針: 共通抽象化しない)。
  *
  * VideoManualStatus 遷移表 (本サービスが関与する遷移。詳細は docs/architecture.md):
@@ -167,7 +167,7 @@ public function triggerPreview(Project $project, VideoManual $manual, ?User $act
     }
 
     /**
-     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed / recoverStale の合流点。
+     * ジョブの失敗確定 (冪等)。pipeline catch / Job::failed の合流点。
      *
      * - terminal (succeeded/failed) 済みは no-op (terminal tx 勝ち・二重 fail を握る)
      * - kind=render のみ: manual が rendering のときのみ ready へ復帰
@@ -181,37 +181,8 @@ public function failJob(RenderJob $job, RenderErrorCode $code, string $error): b
         $failed = DB::transaction(function () use ($job, $code, $error): bool {
             /** @var RenderJob $locked */
             $locked = RenderJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
-            if ($locked->status->isTerminal()) {
-                return false;
-            }
-
-            // preview/render とも失敗確定時の scenario_version を snapshot する必要があるため、
-            // manual を常に lock で取得する (従来は kind=render のみ取得だった)。ロック順 job → manual。
-            /** @var VideoManual $manual */
-            $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
-
-            $locked->status = JobStatus::Failed;
-            $locked->error = $error;
-            $locked->error_code = $code;
-            $locked->scenario_version_at_terminal = $manual->scenario_version;
-            $locked->save();
-
-            // manual 復帰 (kind=render かつ rendering のときのみ。preview は status を触らない)
-            if ($locked->kind === RenderKind::Render && $manual->status === VideoManualStatus::Rendering) {
-                $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
-            }
 
-            // 予約 release (Reserved のみ。並行 commit/release 済みは LogicException → 握って冪等)
-            $reservation = $locked->ticketReservation;
-            if ($reservation !== null && $reservation->status === TicketReservationStatus::Reserved) {
-                try {
-                    $this->tickets->release($reservation);
-                } catch (LogicException) {
-                    // 並行 release/commit 済み
-                }
-            }
-
-            return true;
+            return $this->failLockedJob($locked, $code, $error);
         });
 
         // terminal 遷移が実際に起きたときだけ・commit 後に通知する (kind=render のみ。
@@ -227,6 +198,140 @@ public function failJob(RenderJob $job, RenderErrorCode $code, string $error): b
         return $failed;
     }
 
+    /**
+     * 滞留ジョブの失敗確定 (回収経路の唯一の口)。
+     *
+     * **行ロックを取ったうえで滞留の述語ごと再評価する** — 候補を列挙してから
+     * ロックを取るまでの間に worker が進捗を書いた running ジョブは 1 行も返らないので、
+     * 正常に動いているものを失敗にしない (誤回収の防止)。レンダは誤回収を止めることが
+     * **編集ロック (manual の rendering) の誤解除を止める**ことにもなる。
+     *
+     * @param  positive-int  $id  滞留回収の候補列挙 (staleJobIds) が返した主キー
+     * @return bool 実際に failed へ遷移させたか
+     */
+    public function failStaleJob(int $id, CarbonImmutable $sweptAt): bool
+    {
+        // 通知のためにモデルを引き直さない (クラス起点の主キークエリを 1 本増やさないため)。
+        // トランザクションからロック済みモデルをそのまま返す
+        $failed = DB::transaction(function () use ($id, $sweptAt): ?RenderJob {
+            $locked = $this->lockStaleJob($id, $sweptAt);
+            if ($locked === null) {
+                return null; // 述語が成立しない (前進済み / terminal / 進捗が進んだ)
+            }
+
+            return $this->failLockedJob(
+                $locked,
+                RenderErrorCode::Timeout,
+                '書き出しがタイムアウトしました。再実行してください。',
+            ) ? $locked : null;
+        });
+
+        if ($failed !== null) {
+            $failed->refresh();
+            if ($failed->kind === RenderKind::Render) {
+                $this->notifications->notifyRenderFinished($failed);
+            }
+        }
+
+        return $failed !== null;
+    }
+
+    /**
+     * 滞留候補の主キーを昇順で返す (回収の候補列挙。述語は applyStalePredicate が唯一の正本)。
+     *
+     * @param  positive-int|null  $afterId
+     * @param  positive-int  $pageSize
+     * @return list<positive-int>
+     */
+    public function staleJobIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        /** @var list<positive-int> $ids */
+        $ids = $this->applyStalePredicate(RenderJob::query(), $sweptAt)
+            ->when($afterId !== null, fn (EloquentBuilder $query) => $query->where('id', '>', $afterId))
+            ->orderBy('id')
+            ->limit($pageSize)
+            ->pluck('id')
+            ->all();
+
+        return $ids;
+    }
+
+    /**
+     * ロック済みジョブの失敗確定の本体 (failJob と failStaleJob が共有する 1 つの実装)。
+     *
+     * ロック順 (job → manual)、terminal guard、manual の復帰条件、予約解放、
+     * scenario_version_at_terminal の書き込みを 2 か所に複製しないためにここへ集約する。
+     */
+    private function failLockedJob(RenderJob $locked, RenderErrorCode $code, string $error): bool
+    {
+        if ($locked->status->isTerminal()) {
+            return false;
+        }
+
+        // preview/render とも失敗確定時の scenario_version を snapshot する必要があるため、
+        // manual を常に lock で取得する。ロック順 job → manual。
+        /** @var VideoManual $manual */
+        $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
+
+        $locked->status = JobStatus::Failed;
+        $locked->error = $error;
+        $locked->error_code = $code;
+        $locked->scenario_version_at_terminal = $manual->scenario_version;
+        $locked->save();
+
+        // manual 復帰 (kind=render かつ rendering のときのみ。preview は status を触らない)
+        if ($locked->kind === RenderKind::Render && $manual->status === VideoManualStatus::Rendering) {
+            $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
+        }
+
+        // 予約 release (Reserved のみ。並行 commit/release 済みは LogicException → 握って冪等)
+        $reservation = $locked->ticketReservation;
+        if ($reservation !== null && $reservation->status === TicketReservationStatus::Reserved) {
+            try {
+                $this->tickets->release($reservation);
+            } catch (LogicException) {
+                // 並行 release/commit 済み
+            }
+        }
+
+        return true;
+    }
+
+    /**
+     * id は回収の候補列挙由来。**候補列挙と同じ述語**を WHERE に入れることでロック後の再評価になる。
+     *
+     * @param  positive-int  $id
+     */
+    private function lockStaleJob(int $id, CarbonImmutable $sweptAt): ?RenderJob
+    {
+        return $this->applyStalePredicate(RenderJob::query()->whereKey($id), $sweptAt)
+            ->lockForUpdate()
+            ->first();
+    }
+
+    /**
+     * 滞留の述語 (**この 1 か所だけが正本**)。閾値が 2 本あるため複製の危険が解析より高い:
+     * - queued: created_at が render_queued_stale_after_minutes (10 分) 超過 (dispatch 喪失。
+     *   render は enqueue 時点で編集を止めるため短い期限で失敗させる)
+     * - running: updated_at が render_stale_after_minutes (30 分) 超過 (worker 異常終了)
+     *
+     * @param  EloquentBuilder<RenderJob>  $query
+     * @return EloquentBuilder<RenderJob>
+     */
+    private function applyStalePredicate(EloquentBuilder $query, CarbonImmutable $sweptAt): EloquentBuilder
+    {
+        $queuedThreshold = $sweptAt->subMinutes(config()->integer('manual.render_queued_stale_after_minutes'));
+        $runningThreshold = $sweptAt->subMinutes(config()->integer('manual.render_stale_after_minutes'));
+
+        return $query->where(fn (Builder $outer) => $outer
+            ->where(fn (Builder $queued) => $queued
+                ->where('status', JobStatus::Queued->value)
+                ->where('created_at', '<=', $queuedThreshold))
+            ->orWhere(fn (Builder $running) => $running
+                ->where('status', JobStatus::Running->value)
+                ->where('updated_at', '<=', $runningThreshold)));
+    }
+
     /**
      * finalize 専用: ロック済み manual へ cut_length_ms / total_length_ms / published を反映する。
      *
@@ -257,50 +362,6 @@ public function completeRenderIntoLockedManual(VideoManual $lockedManual, Render
         ])->save();
     }
 
-    /**
-     * stale ジョブの回復 (cron)。queued と running で閾値を分ける (概念設計 §5):
-     * - queued: created_at が render_queued_stale_after_minutes (10 分) 超過
-     *   (dispatch 喪失。render は enqueue 時点で編集を止めるため短 SLA で fail させる)
-     * - running: updated_at が render_stale_after_minutes (30 分) 超過 (worker 異常終了)
-     *
-     * @return int 実際に回復 (failed 遷移) した件数
-     */
-    public function recoverStale(): int
-    {
-        $queuedThreshold = CarbonImmutable::now()
-            ->subMinutes(config()->integer('manual.render_queued_stale_after_minutes'));
-        $runningThreshold = CarbonImmutable::now()
-            ->subMinutes(config()->integer('manual.render_stale_after_minutes'));
-
-        $staleIds = RenderJob::query()
-            ->where(function (Builder $query) use ($queuedThreshold, $runningThreshold): void {
-                $query
-                    ->where(function (Builder $query) use ($queuedThreshold): void {
-                        $query->where('status', JobStatus::Queued->value)
-                            ->where('created_at', '<=', $queuedThreshold);
-                    })
-                    ->orWhere(function (Builder $query) use ($runningThreshold): void {
-                        $query->where('status', JobStatus::Running->value)
-                            ->where('updated_at', '<=', $runningThreshold);
-                    });
-            })
-            ->pluck('id');
-
-        $recovered = 0;
-        foreach ($staleIds as $id) {
-            $job = RenderJob::query()->whereKey($id)->first();
-            if ($job === null) {
-                continue;
-            }
-            // failJob 内で行ロック + terminal guard 再検証するため、競合したジョブはそこで no-op (false)
-            if ($this->failJob($job, RenderErrorCode::Timeout, '書き出しがタイムアウトしました。再実行してください。')) {
-                $recovered++;
-            }
-        }
-
-        return $recovered;
-    }
-
     /**
      * 出力世代の収束 (reconciliation。概念設計 §5 の (b) 系統)。
      * 「output_path 非 NULL かつ同 manual・同 kind により新しい succeeded job が存在する」
diff --git a/app/Services/Recovery/Streams/ExpiredTicketReservationStream.php b/app/Services/Recovery/Streams/ExpiredTicketReservationStream.php
new file mode 100644
index 0000000..481cbf1
--- /dev/null
+++ b/app/Services/Recovery/Streams/ExpiredTicketReservationStream.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Recovery\Streams;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Billing\TicketLedgerService;
+use Carbon\CarbonImmutable;
+
+/**
+ * 期限切れのチケット予約 (TTL 超過 または 失効 monthly hold)。
+ *
+ * 会計の述語 (失効 monthly hold の判定式) は台帳サービスの中に閉じたままにする。
+ * 本 stream は候補の列挙も回収も台帳サービスへ委譲するだけである。
+ */
+final readonly class ExpiredTicketReservationStream implements StuckWorkStream
+{
+    public function __construct(private TicketLedgerService $tickets) {}
+
+    public function stream(): RecoveryStream
+    {
+        return RecoveryStream::TicketReservation;
+    }
+
+    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        return $this->tickets->expiredReservationIds($sweptAt, $afterId, $pageSize);
+    }
+
+    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
+    {
+        return $this->tickets->releaseExpiredReservation($id, $sweptAt)
+            ? RecoveryOutcome::Recovered
+            : RecoveryOutcome::Skipped; // 並行 commit / release 済み = 正常事象 (失敗ではない)
+    }
+
+    public function sweepItemLimit(): ?int
+    {
+        return null;
+    }
+}
diff --git a/app/Services/Recovery/Streams/StaleAnalysisJobStream.php b/app/Services/Recovery/Streams/StaleAnalysisJobStream.php
new file mode 100644
index 0000000..ccf930a
--- /dev/null
+++ b/app/Services/Recovery/Streams/StaleAnalysisJobStream.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Recovery\Streams;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Manual\AnalysisJobService;
+use Carbon\CarbonImmutable;
+
+/**
+ * 滞留した AI 解析ジョブ。閾値は manual.analysis_stale_after_minutes (30 分) で、
+ * queued は発生時刻 (created_at)、running は進捗時刻 (updated_at) を起点にする。
+ *
+ * **閾値は config/manual.php に置いたまま**にする (ジョブの timeout < retry_after <
+ * 予約 TTL <= 滞留閾値 の序列を AnalysisTimeBudgetInvariantTest が固定しているため。
+ * 回収側の設定へ移すと序列の情報源が 2 つに割れる)。
+ */
+final readonly class StaleAnalysisJobStream implements StuckWorkStream
+{
+    public function __construct(private AnalysisJobService $jobs) {}
+
+    public function stream(): RecoveryStream
+    {
+        return RecoveryStream::AnalysisJob;
+    }
+
+    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        // 候補列挙も Service へ委譲する。滞留の述語を stream と Service に**複製しない**
+        // (片方だけ書き換えられると、行ロック下の再評価で塞いだ誤回収がそのまま再発する)
+        return $this->jobs->staleJobIds($sweptAt, $afterId, $pageSize);
+    }
+
+    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
+    {
+        // 行ロック下で滞留の述語ごと再評価するのは Service 側の責務 (failStaleJob)。
+        // stream は id を渡すだけで、行も判定結果も持ち回らない
+        return $this->jobs->failStaleJob($id, $sweptAt)
+            ? RecoveryOutcome::Recovered
+            : RecoveryOutcome::Skipped;   // 競合で前進済み / 進捗が進んだ = 失敗ではない
+    }
+
+    public function sweepItemLimit(): ?int
+    {
+        return null;
+    }
+}
diff --git a/app/Services/Recovery/Streams/StaleRenderJobStream.php b/app/Services/Recovery/Streams/StaleRenderJobStream.php
new file mode 100644
index 0000000..ba9f32e
--- /dev/null
+++ b/app/Services/Recovery/Streams/StaleRenderJobStream.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Recovery\Streams;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Manual\RenderJobService;
+use Carbon\CarbonImmutable;
+
+/**
+ * 滞留したレンダジョブ。閾値は 2 本ある —
+ * queued は manual.render_queued_stale_after_minutes (10 分。dispatch 喪失)、
+ * running は manual.render_stale_after_minutes (30 分。worker 異常終了)。
+ *
+ * **閾値は config/manual.php に置いたまま**にする (解析と同じ理由。序列の情報源を割らない)。
+ */
+final readonly class StaleRenderJobStream implements StuckWorkStream
+{
+    public function __construct(private RenderJobService $jobs) {}
+
+    public function stream(): RecoveryStream
+    {
+        return RecoveryStream::RenderJob;
+    }
+
+    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        // 候補列挙も Service へ委譲する。滞留の述語を stream と Service に**複製しない**
+        return $this->jobs->staleJobIds($sweptAt, $afterId, $pageSize);
+    }
+
+    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
+    {
+        return $this->jobs->failStaleJob($id, $sweptAt)
+            ? RecoveryOutcome::Recovered
+            : RecoveryOutcome::Skipped;   // 競合で前進済み / 進捗が進んだ = 失敗ではない
+    }
+
+    public function sweepItemLimit(): ?int
+    {
+        return null;
+    }
+}
diff --git a/app/Services/Recovery/Streams/StaleUploadReservationStream.php b/app/Services/Recovery/Streams/StaleUploadReservationStream.php
new file mode 100644
index 0000000..bc068e0
--- /dev/null
+++ b/app/Services/Recovery/Streams/StaleUploadReservationStream.php
@@ -0,0 +1,128 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Recovery\Streams;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Models\TakeUploadReservation;
+use App\Services\Capture\TakeObjectStorage;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Support\Facades\DB;
+use Throwable;
+
+/**
+ * 撮影アップロードの滞留予約 (doc/10 §10.8-4)。回収対象は
+ * (a) expires_at 超過の pending 予約、(b) stale な verifying 予約
+ * (updated_at が閾値超過 = 登録リクエストの異常終了) で、released 化して bytes_pending を
+ * 解放し、S3 に PUT 済みだが未登録のオブジェクトを削除する。
+ * **fresh な verifying には触れない** (登録処理の claim 契約と競合しない)。
+ *
+ * 保持期間 (released/completed の古い行の物理削除) は**この回収には含まない**。
+ * あれは滞留の前進ではなく期限の決着なので capture:purge-upload-reservations が持つ。
+ */
+final readonly class StaleUploadReservationStream implements StuckWorkStream
+{
+    /** S3 の存在確認・削除の入出力を有界にするための既存の上限。公平性は保証しない */
+    private const int SWEEP_ITEM_LIMIT = 500;
+
+    public function __construct(private TakeObjectStorage $storage) {}
+
+    public function stream(): RecoveryStream
+    {
+        return RecoveryStream::UploadReservation;
+    }
+
+    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        /** @var list<positive-int> $ids */
+        $ids = self::applyStalePredicate(TakeUploadReservation::query(), $sweptAt)
+            ->when($afterId !== null, fn (Builder $query) => $query->where('id', '>', $afterId))
+            ->orderBy('id')
+            ->limit($pageSize)
+            ->pluck('id')
+            ->all();
+
+        return $ids;
+    }
+
+    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
+    {
+        $videoPath = $this->releaseIfStillStale($id, $sweptAt);
+        if ($videoPath === null) {
+            return RecoveryOutcome::Skipped; // 登録処理が勝った (completed) = 正当な Take の実体
+        }
+
+        try {
+            if ($this->storage->exists($videoPath)) {
+                $this->storage->delete($videoPath); // 未登録オブジェクトの孤児削除
+            }
+        } catch (Throwable $exception) {
+            // 枠の解放は巻き戻さない (利用者の枠を人質にしない)。件数として観測できる形で残す
+            report($exception);
+
+            return RecoveryOutcome::RecoveredWithCleanupFailure;
+        }
+
+        return RecoveryOutcome::Recovered;
+    }
+
+    /** @return positive-int */
+    public function sweepItemLimit(): int
+    {
+        return self::SWEEP_ITEM_LIMIT;
+    }
+
+    /**
+     * 行ロック下で滞留の述語を再評価して解放し、削除対象のパスを返す (解放できなければ null)。
+     *
+     * **条件付き UPDATE ではなく行ロックにする** — 条件付き UPDATE だと「更新」と
+     * 「パスの読み取り」で主キーのクエリが 2 本になる。行ロック + 述語の再評価なら 1 本で済み、
+     * 他の 4 stream と同じ形にも揃う。直列化の効き方は条件付き UPDATE と同じ —
+     * 登録処理側の verifying→completed の更新はこのロックが解けるまで待ち、解けた時点で
+     * 述語が再評価されて 0 行になる (正当な Take を消さない)。
+     *
+     * **S3 の削除はコミット後**に行う (行ロックを保持したまま外部の入出力を待たない)。
+     * id は候補列挙が返した主キーで HTTP 入力を経由しない (DirectFetchInventory に登録)。
+     *
+     * @param  positive-int  $id
+     */
+    private function releaseIfStillStale(int $id, CarbonImmutable $sweptAt): ?string
+    {
+        return DB::transaction(function () use ($id, $sweptAt): ?string {
+            $locked = self::applyStalePredicate(TakeUploadReservation::query()->whereKey($id), $sweptAt)
+                ->lockForUpdate()
+                ->first();
+            if ($locked === null) {
+                return null; // 登録処理が勝った (completed) / 条件を満たさなくなった
+            }
+
+            $locked->forceFill(['status' => TakeUploadReservationStatus::Released])->save();
+
+            return $locked->video_path;
+        });
+    }
+
+    /**
+     * 滞留の述語 (**この 1 か所だけが正本**)。候補列挙と行ロック下の再評価が同じ式を使う。
+     *
+     * @param  Builder<TakeUploadReservation>  $query
+     * @return Builder<TakeUploadReservation>
+     */
+    private static function applyStalePredicate(Builder $query, CarbonImmutable $sweptAt): Builder
+    {
+        $cutoff = $sweptAt->subMinutes(config()->integer('capture.stale_verifying_minutes'));
+
+        return $query->where(fn (Builder $outer) => $outer
+            ->where(fn (Builder $pending) => $pending
+                ->where('status', TakeUploadReservationStatus::Pending)
+                ->where('expires_at', '<=', $sweptAt))
+            ->orWhere(fn (Builder $verifying) => $verifying
+                ->where('status', TakeUploadReservationStatus::Verifying)
+                ->where('updated_at', '<', $cutoff)));
+    }
+}
diff --git a/app/Services/Recovery/Streams/StaleWebhookEventStream.php b/app/Services/Recovery/Streams/StaleWebhookEventStream.php
new file mode 100644
index 0000000..ebca177
--- /dev/null
+++ b/app/Services/Recovery/Streams/StaleWebhookEventStream.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Recovery\Streams;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Billing\StripeWebhookProcessor;
+use Carbon\CarbonImmutable;
+
+/**
+ * 本処理中にプロセスが落ちて received のまま残った Stripe webhook 記録。
+ *
+ * 放置すると Stripe の再送は受理側に弾かれて 200 で終わり、Stripe 側も配信成功と
+ * 判断して再送を打ち切るため、決済済みチケットの付与が**無音で失われる**。
+ *
+ * 5 値のうち 4 値を使う唯一の stream である (Recovered / Deferred / Escalated / Skipped)。
+ */
+final readonly class StaleWebhookEventStream implements StuckWorkStream
+{
+    public function __construct(private StripeWebhookProcessor $webhooks) {}
+
+    public function stream(): RecoveryStream
+    {
+        return RecoveryStream::WebhookEvent;
+    }
+
+    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+    {
+        return $this->webhooks->staleEventIds($sweptAt, $afterId, $pageSize);
+    }
+
+    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
+    {
+        return $this->webhooks->recoverStuckEvent($id, $sweptAt);
+    }
+
+    public function sweepItemLimit(): ?int
+    {
+        return null;
+    }
+}
diff --git a/app/Services/Recovery/StuckWorkRecoverySweeper.php b/app/Services/Recovery/StuckWorkRecoverySweeper.php
new file mode 100644
index 0000000..e6fce2d
--- /dev/null
+++ b/app/Services/Recovery/StuckWorkRecoverySweeper.php
@@ -0,0 +1,112 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Recovery;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\DataTransferObjects\Recovery\StreamSweepResultDto;
+use App\Enums\Recovery\RecoveryOutcome;
+use Carbon\CarbonImmutable;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 滞留回収の掃引。**走査 (候補の列挙とページ送り) だけを持ち、作用は stream 側に置く**。
+ *
+ * 設計上の要点:
+ * 1. **ページ送りであって総件数の上限ではない**。先頭に居座って毎回例外になる行があっても、
+ *    カーソルが跨いで前進するので後続に手が届く (総件数の上限にすると新しい滞留を作る)
+ * 2. **上限を適用するのは effectiveLimit の 1 箇所だけ**。stream 実装側は上限を知らない
+ * 3. **limitReached は「未処理の候補が実在する」ときだけ true**。ちょうど上限件数で
+ *    候補が尽きたケースを打ち切りと報告しない (運用の誤読を作らない)
+ * 4. 実行しない指定 (dry-run) は recover() を 1 度も呼ばない。**回収されるはずの件数は出せない**
+ *    (webhook の回収は受理そのものが書き込みのため)。出力には候補件数だけを出す
+ * 5. 掃引側はトランザクションを張らない (行ロックと述語の再評価は各 stream の責務)
+ */
+final class StuckWorkRecoverySweeper
+{
+    /** 1 度に取り出す候補の件数 (メモリの上界であって、掃引全体の上限ではない) */
+    private const int PAGE_SIZE = 200;
+
+    /**
+     * stream 1 本を掃引する。
+     *
+     * @param  bool  $apply  true で実際に回収する (false は候補を数えるだけ)
+     * @param  positive-int|null  $limitOverride  手動実行の --limit
+     */
+    public function sweep(StuckWorkStream $stream, bool $apply, ?int $limitOverride = null): StreamSweepResultDto
+    {
+        $sweptAt = CarbonImmutable::now();
+        $limit = $this->effectiveLimit($stream->sweepItemLimit(), $limitOverride);
+
+        /** @var array<value-of<RecoveryOutcome>, int<0, max>> $outcomes */
+        $outcomes = [];
+        $candidates = 0;
+        $failures = 0;
+        $afterId = null;
+
+        while ($limit === null || $candidates < $limit) {
+            // 残り件数を型で positive-int に閉じる (level 10 は min() の結果を正に絞れない)
+            $pageSize = $limit === null ? self::PAGE_SIZE : min(self::PAGE_SIZE, $limit - $candidates);
+            Assert::positiveInteger($pageSize);
+
+            // 契約違反 (要求より多く返す実装) があっても実効上限を超えないよう防御的に切る
+            $ids = array_slice($stream->candidateIds($sweptAt, $afterId, $pageSize), 0, $pageSize);
+            if ($ids === []) {
+                // 候補が尽きた = 打ち切りではない
+                return $this->result($stream, $apply, $candidates, $outcomes, $failures, limitReached: false);
+            }
+
+            foreach ($ids as $id) {
+                $candidates++;
+                $afterId = $id;
+                if (! $apply) {
+                    continue; // 実行しない指定は数えるだけ (recover を 1 度も呼ばない)
+                }
+                try {
+                    $outcome = $stream->recover($id, $sweptAt);
+                    $outcomes[$outcome->value] = ($outcomes[$outcome->value] ?? 0) + 1;
+                } catch (Throwable $exception) {
+                    // 1 件の失敗で掃引を止めない。ただし終了コードでは隠さない (呼び出し側が判定)
+                    $failures++;
+                    report($exception);
+                }
+            }
+        }
+
+        // 上限に達した。**未処理の候補が実在するときだけ**打ち切りとして記録する
+        $hasMore = $stream->candidateIds($sweptAt, $afterId, 1) !== [];
+
+        return $this->result($stream, $apply, $candidates, $outcomes, $failures, limitReached: $hasMore);
+    }
+
+    /** 実効上限 = min(--limit, stream の申告)。どちらも無指定なら無制限 */
+    private function effectiveLimit(?int $streamLimit, ?int $override): ?int
+    {
+        return match (true) {
+            $streamLimit === null => $override,
+            $override === null => $streamLimit,
+            default => min($streamLimit, $override),
+        };
+    }
+
+    /** @param  array<value-of<RecoveryOutcome>, int<0, max>>  $outcomes */
+    private function result(
+        StuckWorkStream $stream,
+        bool $apply,
+        int $candidates,
+        array $outcomes,
+        int $failures,
+        bool $limitReached,
+    ): StreamSweepResultDto {
+        return new StreamSweepResultDto(
+            stream: $stream->stream(),
+            applied: $apply,
+            candidates: $candidates,
+            outcomes: $outcomes,
+            failures: $failures,
+            limitReached: $limitReached,
+        );
+    }
+}
diff --git a/app/Services/Recovery/StuckWorkStreamRegistry.php b/app/Services/Recovery/StuckWorkStreamRegistry.php
new file mode 100644
index 0000000..4e1e647
--- /dev/null
+++ b/app/Services/Recovery/StuckWorkStreamRegistry.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Recovery;
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Recovery\Streams\ExpiredTicketReservationStream;
+use App\Services\Recovery\Streams\StaleAnalysisJobStream;
+use App\Services\Recovery\Streams\StaleRenderJobStream;
+use App\Services\Recovery\Streams\StaleUploadReservationStream;
+use App\Services\Recovery\Streams\StaleWebhookEventStream;
+use Webmozart\Assert\Assert;
+
+/**
+ * 滞留回収の系列一覧。**解決は RecoveryStream 起点**で行う。
+ *
+ * 登録キーは各 stream 自身の stream() が名乗る値なので、
+ * 「宣言と実装がずれる」形は構造的に作れない。重複・欠落は constructor の Assert が落とす。
+ */
+final class StuckWorkStreamRegistry
+{
+    /** @var array<value-of<RecoveryStream>, StuckWorkStream> */
+    private array $streams;
+
+    public function __construct(
+        StaleAnalysisJobStream $analysisJobs,
+        StaleRenderJobStream $renderJobs,
+        ExpiredTicketReservationStream $ticketReservations,
+        StaleWebhookEventStream $webhookEvents,
+        StaleUploadReservationStream $uploadReservations,
+    ) {
+        $this->streams = [];
+        foreach ([$analysisJobs, $renderJobs, $ticketReservations, $webhookEvents, $uploadReservations] as $stream) {
+            $this->streams[$stream->stream()->value] = $stream;
+        }
+
+        Assert::count(
+            $this->streams,
+            count(RecoveryStream::cases()),
+            'stream の登録に重複または欠落があります (RecoveryStream の case と 1 対 1 であること)',
+        );
+    }
+
+    public function get(RecoveryStream $stream): StuckWorkStream
+    {
+        return $this->streams[$stream->value];
+    }
+
+    /** @return list<StuckWorkStream> */
+    public function all(): array
+    {
+        return array_values($this->streams);
+    }
+}
diff --git a/config/billing.php b/config/billing.php
index df575ca..c5a1a14 100644
--- a/config/billing.php
+++ b/config/billing.php
@@ -52,7 +52,7 @@
     /*
     | webhook 処理の滞留判定 (分)。`stripe_webhook_events.status='received'` のまま
     | この時間を超えた行を「本処理中にプロセスが落ちた残留」とみなして回収する
-    | (billing:recover-stale-webhook-events)。
+    | (work:recover-stuck --stream=webhook_event)。
     |
     | env で振らない (環境ごとに変えてよい運用値ではない)。webhook の HTTP 処理は
     | 秒オーダーで終わるため、生存中のワーカーを追い越さない十分な余裕を取ってある。
diff --git a/config/queue.php b/config/queue.php
index 6085704..583a107 100644
--- a/config/queue.php
+++ b/config/queue.php
@@ -72,7 +72,7 @@
         // AI 解析専用 (RunManualAnalysis)。retry_after は job timeout (1,560s) より長く
         // 予約 TTL (1,800s) より短い (AnalysisTimeBudgetInvariantTest が連鎖を固定)。
         // 運用契約: worker は `php artisan queue:work database-analysis` を必須登録
-        // (docs/architecture.md。滞留は analysis:recover-stale-jobs cron が回収)
+        // (docs/architecture.md。滞留は work:recover-stuck --stream=analysis_job が回収)
         'database-analysis' => [
             'driver' => 'database',
             'connection' => env('DB_QUEUE_CONNECTION'),
@@ -85,7 +85,7 @@
         // レンダ専用 (RunManualRender)。retry_after は job timeout (1,500s) より長く
         // 予約 TTL (1,800s) より短い (RenderTimeBudgetInvariantTest が連鎖を固定)。
         // 運用契約: worker は `php artisan queue:work database-render` を必須登録
-        // (docs/architecture.md。滞留は render:recover-stale-jobs cron が回収)
+        // (docs/architecture.md。滞留は work:recover-stuck --stream=render_job が回収)
         'database-render' => [
             'driver' => 'database',
             'connection' => env('DB_QUEUE_CONNECTION'),
diff --git a/database/migrations/2026_06_11_091400_create_ticket_tables.php b/database/migrations/2026_06_11_091400_create_ticket_tables.php
index 110aa2d..dd24e7f 100644
--- a/database/migrations/2026_06_11_091400_create_ticket_tables.php
+++ b/database/migrations/2026_06_11_091400_create_ticket_tables.php
@@ -26,7 +26,7 @@ public function up(): void
             $table->integer('amount');
             // reserved | committed | released (App\Enums\Billing\TicketReservationStatus)
             $table->string('status')->default('reserved');
-            // reserve TTL。超過分は billing:release-stale-reservations cron が解放する
+            // reserve TTL。超過分は滞留回収 (work:recover-stuck --stream=ticket_reservation) が解放する
             $table->timestamp('expires_at');
             $table->timestamps();
 
diff --git a/database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php b/database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php
index 128b9d4..81154cf 100644
--- a/database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php
+++ b/database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php
@@ -17,7 +17,7 @@
      * (回収待ちの行はこの migration の時点で 1 件も存在しない)。
      * 自由文の failure_reason とは分ける (機械判定できる値と混ぜない)。
      *
-     * index: billing:recover-stale-webhook-events が 5 分ごとに
+     * index: work:recover-stuck --stream=webhook_event が 5 分ごとに
      * `status='received' AND updated_at <= 閾値` を引く。本表は保持期限 (7 年) まで
      * 残るため単調に増える = 全表走査にしない。
      * 監視で使う status='recovery_pending' の件数も同じ index の先頭列で効く。
diff --git a/routes/console.php b/routes/console.php
index b146a98..3fd1ba4 100644
--- a/routes/console.php
+++ b/routes/console.php
@@ -2,11 +2,8 @@
 
 declare(strict_types=1);
 
+use App\Enums\Recovery\RecoveryStream;
 use App\Services\Billing\AccountDeletionBillingGuard;
-use App\Services\Billing\StripeWebhookProcessor;
-use App\Services\Billing\TicketLedgerService;
-use App\Services\Capture\StaleUploadReservationSweeper;
-use App\Services\Manual\AnalysisJobService;
 use App\Services\Manual\RenderJobService;
 use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Foundation\Inspiring;
@@ -19,51 +16,33 @@
 
 /*
 |--------------------------------------------------------------------------
-| 課金 cron
+| 滞留回収 (AG-083 標準形 v1)
 |--------------------------------------------------------------------------
-| reserve TTL 超過のチケット予約を解放する (2 フェーズ消費の前提となる stale 解放)。
-*/
-Artisan::command('billing:release-stale-reservations', function (TicketLedgerService $tickets) {
-    $released = $tickets->releaseStale();
-    $this->info("released {$released} stale reservation(s)");
-})->purpose('期限切れ (expires_at 超過) のチケット予約を解放する');
-
-Schedule::command('billing:release-stale-reservations')->everyFiveMinutes();
-
-/*
-|--------------------------------------------------------------------------
-| Stripe webhook の滞留回収
-|--------------------------------------------------------------------------
-| 本処理中にプロセスが落ちて status='received' のまま残った記録を再処理へ戻す。
-| 放置すると Stripe の再送は claim() に弾かれて 200 で終わり、Stripe 側も配信成功と
-| 判断して再送を打ち切るため、決済済みチケットの付与が**無音で失われる**。
+| 系列ごとに 1 本ずつ登録する (実行間隔は RecoveryStream::cadenceMinutes が正本)。
+| **--apply の付け忘れは回収が全面停止しても無音**なので、配線は
+| StuckWorkRecoveryInventoryTest が系列のキー単位で機械固定する。
 |
-| **監視対象 (必須)**: 本コマンドの report() と、次の 3 つの件数。
-|   1. status='received' かつ updated_at が滞留の閾値より古い行の件数
-|      (増え続ける = scheduler か本コマンドが動いていない)
-|   2. 本コマンド出力の retry-scheduled 件数 (再実行が失敗し続けている)
-|   3. status='recovery_pending' の件数 (自動再実行の対象外として置かれた行。
-|      理由は recovery_reason 列)
-| 詳細は docs/architecture.md の「Stripe webhook の滞留回収」が正本。
+| 監視対象 (必須): 各実行の出力と onFailure。**5 つを見る**。
+|   - errors > 0 が続く        = 特定の行で回収が失敗し続けている
+|   - deferred > 0 が続く      = 再実行が失敗し続けている (webhook。**errors には出ない** —
+|                                失敗は行に書き戻して次回へ回すため、errors=0 のまま滞留しうる)
+|   - escalated の件数         = 自動回収の対象外として人手へ渡した件数 (webhook)
+|   - cleanup-failed > 0       = S3 の孤児削除に失敗した件数 (**手動確認が要る**。
+|                                行は解放済みなので自動では拾い直せない)
+|   - limit-reached=yes が続く = 上限で打ち切っており後続候補が残っている
+| 詳細は docs/architecture.md の「滞留回収の共通基盤」が正本。
 */
-Artisan::command('billing:recover-stale-webhook-events', function (StripeWebhookProcessor $webhooks) {
-    $result = $webhooks->recoverStale();
-    $this->info(sprintf(
-        'replayed %d / retry-scheduled %d / moved-to-recovery-pending %d / skipped %d',
-        $result->replayed,
-        $result->retryScheduled,
-        $result->movedToRecoveryPending,
-        $result->skipped,
-    ));
-})->purpose('処理中に滞留した Stripe webhook 記録を再処理へ戻す');
-
-Schedule::command('billing:recover-stale-webhook-events')
-    ->everyFiveMinutes()
-    ->onOneServer()
-    ->withoutOverlapping()
-    ->onFailure(static fn () => report(new RuntimeException(
-        'billing:recover-stale-webhook-events 失敗 — 決済済み・チケット未付与が滞留する可能性',
-    )));
+foreach (RecoveryStream::cases() as $recoveryStream) {
+    Schedule::command('work:recover-stuck --stream='.$recoveryStream->value.' --apply')
+        ->cron('*/'.$recoveryStream->cadenceMinutes().' * * * *')
+        ->onOneServer()
+        // 期限を明示する。既定 (24 時間) だと異常終了で残ったロックが丸 1 日回収を止める
+        ->withoutOverlapping($recoveryStream->overlapExpiryMinutes())
+        // RuntimeException は import しない (本ファイルは namespace 宣言が無く global 解決される)
+        ->onFailure(static fn () => report(new RuntimeException(
+            'work:recover-stuck --stream='.$recoveryStream->value.' 失敗 — 滞留が前へ進んでいない可能性',
+        )));
+}
 
 /*
 |--------------------------------------------------------------------------
@@ -179,33 +158,12 @@
 
 /*
 |--------------------------------------------------------------------------
-| AI 解析 cron
-|--------------------------------------------------------------------------
-| dispatch 喪失 (queued 滞留) と worker 異常終了 (running 滞留) の回復。
-| failJob は行ロック + terminal guard で冪等 (billing:release-stale-reservations と同型)。
-*/
-Artisan::command('analysis:recover-stale-jobs', function (AnalysisJobService $jobs) {
-    $recovered = $jobs->recoverStale();
-    $this->info("recovered {$recovered} stale analysis job(s)");
-})->purpose('滞留した解析ジョブ (queued/running が閾値超過) を失敗確定し予約を解放する');
-
-Schedule::command('analysis:recover-stale-jobs')->everyFiveMinutes();
-
-/*
-|--------------------------------------------------------------------------
-| レンダ cron
+| レンダ出力世代の収束
 |--------------------------------------------------------------------------
-| recover-stale-jobs: dispatch 喪失 (queued=10 分) と worker 異常終了 (running=30 分) の回復。
-| reconcile-outputs: 出力世代の収束 (世代交代済みの output_path を削除 job へ再投入。
-| stale 回復とは別責務のため command を分離する)。
+| 世代交代済みの output_path を削除 job へ再投入する。**滞留の前進ではない**ため
+| 滞留回収 (work:recover-stuck) には含めず、別コマンドのまま残す
+| (StuckWorkRecoveryInventoryTest の「回収でない定期実行」へ理由付きで登録している)。
 */
-Artisan::command('render:recover-stale-jobs', function (RenderJobService $jobs) {
-    $recovered = $jobs->recoverStale();
-    $this->info("recovered {$recovered} stale render job(s)");
-})->purpose('滞留したレンダジョブ (queued/running が閾値超過) を失敗確定し予約を解放する');
-
-Schedule::command('render:recover-stale-jobs')->everyFiveMinutes();
-
 Artisan::command('render:reconcile-outputs', function (RenderJobService $jobs) {
     $result = $jobs->reconcileOutputs();
     $this->info("dispatched {$result['dispatched']} delete job(s), skipped {$result['skipped']}");
@@ -215,18 +173,13 @@
 
 /*
 |--------------------------------------------------------------------------
-| 撮影 PWA cron (doc/10 §10.8-4 / 概念設計 D7)
+| 撮影アップロード予約の保持期間の決着 (doc/10 §10.8-4)
 |--------------------------------------------------------------------------
-| 期限切れ pending / stale verifying のアップロード予約を released 化して
-| bytes_pending を解放し、PUT 済みだが未登録の S3 孤児オブジェクトを削除する。
-| fresh verifying (検証中) には触れない (登録処理の claim 契約と競合しない)。冪等。
+| released / completed の古い行 (retention 超過) を物理削除する。**滞留の回収ではなく
+| 期限の決着**なので回収 (work:recover-stuck --stream=upload_reservation) とは入口を分ける。
+| 肥大の防止であって緊急性が無いため日次でよい (既存の purge 系と同じ扱い)。
 */
-Artisan::command('capture:release-stale-upload-reservations', function (StaleUploadReservationSweeper $sweeper) {
-    $released = $sweeper->sweep();
-    $this->info("released {$released} stale upload reservation(s)");
-})->purpose('期限切れのテイクアップロード予約を解放し S3 孤児オブジェクトを削除する');
-
-Schedule::command('capture:release-stale-upload-reservations')->everyTenMinutes()->onOneServer()->withoutOverlapping();
+Schedule::command('capture:purge-upload-reservations')->daily()->onOneServer();
 
 /*
 |--------------------------------------------------------------------------
diff --git a/tests/Architecture/ModelDirectFetchInvariantTest.php b/tests/Architecture/ModelDirectFetchInvariantTest.php
index aefb284..5881c1e 100644
--- a/tests/Architecture/ModelDirectFetchInvariantTest.php
+++ b/tests/Architecture/ModelDirectFetchInvariantTest.php
@@ -3,8 +3,12 @@
 declare(strict_types=1);
 
 use App\Enums\Security\DirectFetchJustification;
+use App\Enums\Security\RecoveryFetchShape;
 use App\Http\Middleware\LocalOnly;
+use App\Services\Recovery\StuckWorkStreamRegistry;
 use Illuminate\Support\Facades\Route;
+use Tests\Support\PhpTokenScan;
+use Tests\Support\Recovery\StuckWorkRecoveryInventory;
 use Tests\Support\Security\DirectFetchInventory;
 use Tests\Support\Security\DirectFetchJustificationEntry;
 use Tests\Support\Security\PrimaryKeyPredicateKind;
@@ -110,6 +114,7 @@ function modelDirectFetchAllowedPredicateKinds(): array
         DirectFetchJustification::IdDerivedFromTenantScopedQuery->value => [$single, $multi, $exclusion],
         DirectFetchJustification::IdDerivedFromSameMethodQuery->value => [$single, $multi],
         DirectFetchJustification::IdSuppliedByInternalCaller->value => [$single, $multi],
+        DirectFetchJustification::IdFromRecoveryCandidateEnumeration->value => [$single],
         DirectFetchJustification::AuthenticatedActorScope->value => [$single, $multi, $exclusion],
         DirectFetchJustification::QueuePayloadRehydration->value => [$single, $multi],
         DirectFetchJustification::LocalOnlyDiagnostics->value => [$single],
@@ -288,6 +293,139 @@ function modelDirectFetchCompact(string $source): string
         .'calledBy の実在呼び出しが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
 });
 
+/**
+ * `app/` 配下で、指定したメソッド名が**識別子として**現れるファイルの集合。
+ *
+ * **数えるのは PHP のトークン上の識別子 (メソッド宣言と呼び出し) だけ**で、
+ * コメントや文字列リテラルの中の同名は数えない。将来これを単純な文字列検索へ置き換えると
+ * 偽陽性・偽陰性の両方が出るので、この前提を崩さないこと。
+ *
+ * @return list<string> app/ 相対のファイルパス (昇順)
+ */
+function modelDirectFetchFilesMentioning(string $methodName): array
+{
+    $files = [];
+
+    foreach (DirectFetchInventory::sourceFiles() as $path => $source) {
+        if (! str_starts_with($path, 'app/')) {
+            continue;
+        }
+        foreach (PhpTokenScan::normalize($source) as $token) {
+            if ($token['id'] === T_STRING && $token['text'] === $methodName) {
+                $files[] = $path;
+
+                break;
+            }
+        }
+    }
+
+    sort($files);
+
+    return $files;
+}
+
+test('IdFromRecoveryCandidateEnumeration は private + 引数由来 + 入口の実在 + 形ごとの封じ込めを満たす', function (): void {
+    $pairs = modelDirectFetchPairsFor(DirectFetchJustification::IdFromRecoveryCandidateEnumeration);
+    $sources = DirectFetchInventory::sourceFiles();
+    $streams = StuckWorkRecoveryInventory::streams();
+    $registryKeys = array_map(
+        static fn (object $stream): string => $stream->stream()->value,
+        app(StuckWorkStreamRegistry::class)->all(),
+    );
+    $violations = [];
+
+    // DomainService 形は「入口のメソッド名が現れてよいファイル」を全 entry から先に集める
+    // (同じ入口名を複数のドメインが持つ場合があるため、entry 単位ではなく名前単位で束ねる)
+    $allowedByEntryPoint = [];
+    foreach ($pairs as [$candidate, $entry]) {
+        if ($entry->recoveryFetchShape() !== RecoveryFetchShape::DomainService) {
+            continue;
+        }
+        $method = modelDirectFetchMethodName($entry->entryPoint());
+        $declaring = modelDirectFetchClassPath($entry->entryPoint());
+        $streamFile = array_key_exists($entry->stream(), $streams)
+            ? 'app/'.str_replace('\\', '/', substr($streams[$entry->stream()]->implementation, 4)).'.php'
+            : null;
+        if ($method === null || $declaring === null || $streamFile === null) {
+            continue;
+        }
+        $allowedByEntryPoint[$method][] = $declaring;
+        $allowedByEntryPoint[$method][] = $streamFile;
+    }
+
+    foreach ($pairs as $key => [$candidate, $entry]) {
+        if (! PrimaryKeyStaticQueryScanner::methodIsPrivate($candidate)) {
+            $violations[] = $key.' — private メソッドでない (public は本 case を使えない)';
+        }
+        if (! PrimaryKeyStaticQueryScanner::identityDerivedFromMethodParameters($candidate)) {
+            $violations[] = $key.' — identity が引数由来でない: '.$candidate->identityArgument;
+        }
+        if (! PrimaryKeyStaticQueryScanner::methodIsFreeOfRequestAccessors($candidate)) {
+            $violations[] = $key.' — 同一メソッドに request accessor がある';
+        }
+
+        // 申告された系列が registry と回収の目録の両方に実在すること
+        if (! in_array($entry->stream(), $registryKeys, true)) {
+            $violations[] = $key.' — 申告された系列が registry に無い: '.$entry->stream();
+        }
+        if (! array_key_exists($entry->stream(), $streams)) {
+            $violations[] = $key.' — 申告された系列が回収の目録に無い: '.$entry->stream();
+        }
+
+        // 入口が実在し、その本文が当該 private を呼んでいること
+        $entryPath = modelDirectFetchClassPath($entry->entryPoint());
+        $entryMethod = modelDirectFetchMethodName($entry->entryPoint());
+        if ($entryPath === null || $entryMethod === null || ! array_key_exists($entryPath, $sources)) {
+            $violations[] = $key.' — entryPoint のクラスが実在しない: '.$entry->entryPoint();
+
+            continue;
+        }
+        $body = PrimaryKeyStaticQueryScanner::methodBody($sources[$entryPath], $entryMethod);
+        if ($body === null) {
+            $violations[] = $key.' — entryPoint のメソッドが実在しない: '.$entry->entryPoint();
+
+            continue;
+        }
+        if (! str_contains(modelDirectFetchCompact($body), '->'.$candidate->scopeName.'(')) {
+            $violations[] = $key.' — entryPoint の本文が '.$candidate->scopeName.'() を呼んでいない';
+        }
+
+        // 形ごとの封じ込め (メソッド名が現れるファイルの集合で判定する = 型推論に依存しない)
+        if ($entry->recoveryFetchShape() === RecoveryFetchShape::DomainService) {
+            $allowed = array_values(array_unique($allowedByEntryPoint[$entryMethod] ?? []));
+            sort($allowed);
+            $actual = modelDirectFetchFilesMentioning($entryMethod);
+            $unexpected = array_values(array_diff($actual, $allowed));
+            if ($unexpected !== []) {
+                $violations[] = $key.' — 入口 '.$entryMethod.'() が申告外のファイルから参照されている: '
+                    .implode(' / ', $unexpected);
+            }
+            $missing = array_values(array_diff($allowed, $actual));
+            if ($missing !== []) {
+                $violations[] = $key.' — 入口 '.$entryMethod.'() が申告したファイルに現れない: '
+                    .implode(' / ', $missing);
+            }
+
+            continue;
+        }
+
+        // StreamInternal: private ヘルパの名前が当該系列のファイル 1 つだけに現れること
+        $expected = ['app/'.$candidate->displayPath()];
+        $actual = modelDirectFetchFilesMentioning($candidate->scopeName);
+        if ($actual !== $expected) {
+            $violations[] = $key.' — private ヘルパ '.$candidate->scopeName.'() が系列のファイル以外にも現れる: '
+                .implode(' / ', $actual);
+        }
+    }
+
+    // ★保証しないもの: 文字列で組み立てた動的呼び出し ($service->{$method}()) と
+    //   app/ の外 (テスト等) からの呼び出しは対象外である。
+    //   「回収以外から呼ばれないことが証明されている」とは書かない。
+    expect($violations)->toBe([],
+        'IdFromRecoveryCandidateEnumeration は private + 引数由来 identity + request accessor 無し +'
+        .'実在する入口からの呼び出し + 形ごとの封じ込めが条件です。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
 test('AuthenticatedActorScope は同一メソッドに request accessor を持たない', function (): void {
     $violations = [];
 
diff --git a/tests/Architecture/RetiredRecoveryReferenceGateTest.php b/tests/Architecture/RetiredRecoveryReferenceGateTest.php
new file mode 100644
index 0000000..3670c92
--- /dev/null
+++ b/tests/Architecture/RetiredRecoveryReferenceGateTest.php
@@ -0,0 +1,170 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Finder\Finder;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * 撤去した滞留回収の実装が再流入していないことの gate (T171)。
+ *
+ * **素の部分文字列照合にしない**。新設コードには撤去対象と字面が重なる名前
+ * (StuckWorkRecoverySweeper::sweep など) が実在するため、単純な走査は新設コード自身を落とす。
+ * 検出単位は次の 3 種類に限る:
+ *   1. 撤去したコマンド名 (完全一致の文字列)
+ *   2. 撤去したクラス名 (完全修飾名と短縮名の両方)
+ *   3. 撤去したメソッドの**宣言** (`function recoverStale(` の形。呼び出しでは判定しない)
+ *
+ * **保証しないもの (誇張しない)**:
+ * - インスタンス変数からの呼び出し (`$service->recoverStale()`) は、字句だけでは受信側の
+ *   クラスを確定できないため**保証範囲に入れない**。「呼び出しが残っていれば必ず落ちる」とは
+ *   書かない。撤去の担保は (a) 旧メソッドの宣言が存在しないこと、(b) 旧クラス名・旧コマンド名が
+ *   存在しないこと、(c) composer test が緑であること (宣言が消えているので呼び出しが残れば
+ *   実行時に落ちる) の 3 つの組み合わせで得る
+ * - 動的に組み立てた文字列 (`'analysis:'.$suffix`) には沈黙する
+ * - 走査対象から外した場所 (devnotes / docs/TODO-closed.md) は過去の記録であり書き換えさせない
+ */
+
+/** 撤去したコマンド名 (完全一致の文字列で探す) */
+function retiredRecoveryCommandNames(): array
+{
+    return [
+        'analysis:recover-stale-jobs',
+        'render:recover-stale-jobs',
+        'billing:release-stale-reservations',
+        'billing:recover-stale-webhook-events',
+        'capture:release-stale-upload-reservations',
+    ];
+}
+
+/** 撤去したクラス (完全修飾名と短縮名の両方を探す) */
+function retiredRecoveryClassNames(): array
+{
+    return [
+        'App\Services\Capture\StaleUploadReservationSweeper',
+        'App\DataTransferObjects\Billing\WebhookRecoveryResultDto',
+    ];
+}
+
+/** 撤去したメソッド名 (宣言だけを探す。呼び出しでは判定しない) */
+function retiredRecoveryMethodNames(): array
+{
+    return ['recoverStale', 'releaseStale'];
+}
+
+/**
+ * 走査対象 (リポジトリ相対パス => 全文)。
+ *
+ * app / routes / config / tests / database と、運用の正本になる docs を見る。
+ * **devnotes と docs/TODO-closed.md は除く** (過去の記録なので書き換えさせない)。
+ *
+ * @return array<string, string>
+ */
+function retiredRecoveryScannedSources(): array
+{
+    $sources = [];
+
+    foreach (['app', 'routes', 'config', 'tests', 'database'] as $directory) {
+        foreach (Finder::create()->files()->in(base_path($directory))->name('*.php')->sortByName() as $file) {
+            $sources[$directory.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
+        }
+    }
+
+    foreach (Finder::create()->files()->in(base_path('docs'))->name('*.md')->sortByName() as $file) {
+        $relative = 'docs/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
+        if ($relative === 'docs/TODO-closed.md') {
+            continue;
+        }
+        $sources[$relative] = $file->getContents();
+    }
+
+    foreach (['AGENTS.md', 'DESIGN.md'] as $rootDoc) {
+        if (file_exists(base_path($rootDoc))) {
+            $sources[$rootDoc] = (string) file_get_contents(base_path($rootDoc));
+        }
+    }
+
+    return $sources;
+}
+
+test('撤去したコマンド名がコードにも運用の正本にも 1 箇所も残っていない', function (): void {
+    $violations = [];
+
+    foreach (retiredRecoveryScannedSources() as $path => $source) {
+        // 本 gate 自身は検出語彙そのものを持つので除く (自己参照で必ず落ちる)
+        if ($path === 'tests/Architecture/RetiredRecoveryReferenceGateTest.php') {
+            continue;
+        }
+        foreach (retiredRecoveryCommandNames() as $command) {
+            if (str_contains($source, $command)) {
+                $violations[] = $path.' — '.$command;
+            }
+        }
+    }
+
+    expect($violations)->toBe([],
+        '撤去済みのコマンド名が残っています。滞留回収の入口は work:recover-stuck ただ 1 本で、'
+        .'系列は --stream=<key> で指定します (docs/architecture.md の「滞留回収の共通基盤」に'
+        .'旧語彙からの対応表があります)。'.PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('撤去したクラス名がコードにも運用の正本にも 1 箇所も残っていない', function (): void {
+    $violations = [];
+
+    foreach (retiredRecoveryScannedSources() as $path => $source) {
+        if ($path === 'tests/Architecture/RetiredRecoveryReferenceGateTest.php') {
+            continue;
+        }
+        foreach (retiredRecoveryClassNames() as $class) {
+            $short = substr((string) strrchr($class, '\\'), 1);
+            if (str_contains($source, $class) || str_contains($source, $short)) {
+                $violations[] = $path.' — '.$class;
+            }
+        }
+    }
+
+    expect($violations)->toBe([],
+        '撤去済みのクラス名が残っています。撮影アップロードの回収は '
+        .'App\Services\Recovery\Streams\StaleUploadReservationStream が、'
+        .'掃引の結果は App\DataTransferObjects\Recovery\StreamSweepResultDto が引き継いでいます。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('撤去したメソッドの宣言が 1 つも存在しない (呼び出しでは判定しない)', function (): void {
+    $retired = retiredRecoveryMethodNames();
+    $violations = [];
+
+    foreach (retiredRecoveryScannedSources() as $path => $source) {
+        if (! str_ends_with($path, '.php')) {
+            continue;
+        }
+        if ($path === 'tests/Architecture/RetiredRecoveryReferenceGateTest.php') {
+            continue;
+        }
+
+        $tokens = PhpTokenScan::normalize($source);
+        $count = count($tokens);
+        for ($i = 0; $i < $count - 1; $i++) {
+            if ($tokens[$i]['id'] !== T_FUNCTION) {
+                continue;
+            }
+            $name = $tokens[$i + 1];
+            if ($name['id'] === T_STRING && in_array($name['text'], $retired, true)) {
+                $violations[] = $path.':'.$name['line'].' — function '.$name['text'].'()';
+            }
+        }
+    }
+
+    expect($violations)->toBe([],
+        '撤去済みメソッドの宣言が復活しています。滞留回収は共通基盤 (StuckWorkStream の実装) 経由で'
+        .'行い、ドメイン側は行ロック下で述語を再評価する 1 件処理の口だけを公開してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検出語彙そのものが空でない (gate が無力化されていない)', function (): void {
+    // 語彙を空にすると 3 本すべてが自動的に緑になるため、母集団の下限を固定する
+    expect(retiredRecoveryCommandNames())->toHaveCount(5);
+    expect(retiredRecoveryClassNames())->toHaveCount(2);
+    expect(retiredRecoveryMethodNames())->toHaveCount(2);
+    expect(count(retiredRecoveryScannedSources()))->toBeGreaterThan(100);
+});
diff --git a/tests/Architecture/StuckWorkRecoveryInventoryTest.php b/tests/Architecture/StuckWorkRecoveryInventoryTest.php
new file mode 100644
index 0000000..66f1653
--- /dev/null
+++ b/tests/Architecture/StuckWorkRecoveryInventoryTest.php
@@ -0,0 +1,253 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Recovery\StuckWorkStreamRegistry;
+use Illuminate\Console\Scheduling\Event;
+use Illuminate\Support\Facades\Exceptions;
+use Illuminate\Support\Facades\Schedule;
+use Tests\Support\Recovery\NonRecoveryScheduleEntry;
+use Tests\Support\Recovery\RecoveryStreamEntry;
+use Tests\Support\Recovery\StuckWorkRecoveryInventory;
+
+/*
+ * 滞留回収の目録 (deny-by-default / exact-fit)。
+ *
+ * 本 gate が固定すること:
+ * 1. registry の系列集合 == RecoveryStream の全 case == 目録の申告集合
+ * 2. Schedule に載る work:recover-stuck --stream=<key> の集合が系列のキーと一致する
+ *    (突き合わせは**コマンド名ではなく系列のキー**で行う。全部が同じコマンド名のため)
+ * 3. 各系列の Schedule が --apply / onOneServer / withoutOverlapping / onFailure の 4 点と
+ *    目録の実行間隔を持ち、多重起動抑止の有効期限が既定 (24 時間) ではないこと
+ *    (**--apply の付け忘れは無音で回収を全面停止させるため、この検査が本 gate の主目的**)
+ * 4. 各系列の sweepItemLimit() が目録の申告値と一致する
+ * 5. 各系列が取りうる結果の種類を目録で申告している
+ * 6. Schedule に載っている全コマンドが、上の回収の入口か NonRecoveryScheduleEntry
+ *    (区分 + 30 文字以上の理由) のどちらかに属する (未分類は fail)
+ *
+ * **保証しないもの (誇張しない)**:
+ * - 目録は申告の集合一致を見るだけで、recover() が実際に行ロック下で述語を再評価しているかは
+ *   検査できない (それは各系列の Feature テストが担う)
+ * - Schedule の検査は**登録内容**を見るだけで、定期実行の仕組みが実際に動いているかは
+ *   検査できない (運用側の監視対象)
+ */
+
+/** Schedule に登録された全イベント */
+function recoveryScheduledEvents(): array
+{
+    return array_values(Schedule::events());
+}
+
+/** イベントのコマンド文字列から artisan のコマンド名と引数部分を取り出す */
+function recoveryCommandLine(Event $event): string
+{
+    $command = (string) $event->command;
+    // "'/usr/bin/php' 'artisan' foo:bar --baz" の形から artisan 以降だけを残す
+    $position = strpos($command, "'artisan'");
+
+    return $position === false ? $command : trim(substr($command, $position + strlen("'artisan'")));
+}
+
+/** コマンド行の先頭 (引数を除いたコマンド名。artisan の引用符も外す) */
+function recoveryCommandName(Event $event): string
+{
+    $first = explode(' ', trim(recoveryCommandLine($event)))[0];
+
+    return trim($first, "'\"");
+}
+
+/** work:recover-stuck の登録だけを系列キー => Event で返す */
+function recoveryStreamEvents(): array
+{
+    $events = [];
+    foreach (recoveryScheduledEvents() as $event) {
+        if (recoveryCommandName($event) !== StuckWorkRecoveryInventory::RECOVERY_COMMAND) {
+            continue;
+        }
+        $line = recoveryCommandLine($event);
+        if (preg_match('/--stream=([a-z_]+)/', $line, $matches) !== 1) {
+            continue;
+        }
+        $events[$matches[1]][] = $event;
+    }
+
+    return $events;
+}
+
+test('registry の系列集合と RecoveryStream の全 case と目録の申告集合が一致する', function (): void {
+    $cases = array_map(static fn (RecoveryStream $stream): string => $stream->value, RecoveryStream::cases());
+    sort($cases);
+
+    $registered = array_map(
+        static fn (object $stream): string => $stream->stream()->value,
+        app(StuckWorkStreamRegistry::class)->all(),
+    );
+    sort($registered);
+
+    $declared = array_keys(StuckWorkRecoveryInventory::streams());
+    sort($declared);
+
+    expect($registered)->toBe($cases, 'registry の登録が RecoveryStream の case と一致していません');
+    expect($declared)->toBe($cases,
+        '滞留回収の目録 (StuckWorkRecoveryInventory::streams) に未登録の系列があります。'
+        .'系列を増やしたら目録・registry・Schedule の 3 つを同時に更新してください。');
+});
+
+test('目録の申告する実装クラスが registry の解決結果と一致する', function (): void {
+    $registry = app(StuckWorkStreamRegistry::class);
+    $violations = [];
+
+    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
+        $resolved = $registry->get($entry->stream);
+        if ($resolved::class !== $entry->implementation) {
+            $violations[] = $key.' — 目録: '.$entry->implementation.' / 実際: '.$resolved::class;
+        }
+    }
+
+    expect($violations)->toBe([], '目録の implementation が registry の解決結果と違います。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('各系列の 1 掃引の上限が目録の申告値と一致する', function (): void {
+    $registry = app(StuckWorkStreamRegistry::class);
+    $violations = [];
+
+    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
+        $actual = $registry->get($entry->stream)->sweepItemLimit();
+        if ($actual !== $entry->sweepItemLimit) {
+            $violations[] = $key.' — 目録: '.var_export($entry->sweepItemLimit, true).' / 実際: '.var_export($actual, true);
+        }
+    }
+
+    expect($violations)->toBe([], '1 掃引の上限が目録と食い違っています (上限を変えたら目録も変える)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('各系列が取りうる結果の種類を目録で申告している', function (): void {
+    foreach (StuckWorkRecoveryInventory::streams() as $key => $entry) {
+        expect($entry->possibleOutcomes)->not->toBe([], $key);
+        expect(mb_strlen($entry->description))
+            ->toBeGreaterThanOrEqual(RecoveryStreamEntry::DESCRIPTION_MIN_LENGTH, $key);
+    }
+});
+
+test('Schedule の work:recover-stuck は系列ごとにちょうど 1 本ずつ登録されている', function (): void {
+    $events = recoveryStreamEvents();
+
+    $keys = array_keys($events);
+    sort($keys);
+    $declared = array_keys(StuckWorkRecoveryInventory::streams());
+    sort($declared);
+
+    expect($keys)->toBe($declared,
+        'Schedule に載っている系列と目録の系列が一致しません '
+        .'(突き合わせはコマンド名ではなく系列のキーで行う。全系列が同じコマンド名のため)。');
+
+    foreach ($events as $key => $registered) {
+        expect($registered)->toHaveCount(1, $key.' の Schedule 登録が 1 本ではありません');
+    }
+});
+
+test('各系列の Schedule が --apply / onOneServer / withoutOverlapping / 実行間隔を持つ', function (): void {
+    $violations = [];
+
+    foreach (recoveryStreamEvents() as $key => $registered) {
+        $event = $registered[0];
+        $line = recoveryCommandLine($event);
+        $stream = RecoveryStream::from($key);
+
+        if (! str_contains($line, '--apply')) {
+            // ここが本 gate の主目的。--apply が落ちると回収は 1 件も実行されないのに
+            // 終了コードも出力も正常に見えるため、無音で全面停止する
+            $violations[] = $key.' — Schedule に --apply が無い (回収が 1 件も実行されない)';
+        }
+        if (! $event->onOneServer) {
+            $violations[] = $key.' — onOneServer() が無い';
+        }
+        if (! $event->withoutOverlapping) {
+            $violations[] = $key.' — withoutOverlapping() が無い';
+        }
+        if ($event->expiresAt !== $stream->overlapExpiryMinutes()) {
+            $violations[] = $key.' — 多重起動抑止の有効期限が '.$stream->overlapExpiryMinutes()
+                .' 分でない (実際: '.$event->expiresAt.' 分)。既定の 1440 分だと'
+                .'異常終了で残ったロックが丸 1 日回収を止める';
+        }
+        $expected = '*/'.$stream->cadenceMinutes().' * * * *';
+        if ($event->expression !== $expected) {
+            $violations[] = $key.' — 実行間隔が目録 (RecoveryStream::cadenceMinutes) と違う: '
+                .$event->expression.' (期待: '.$expected.')';
+        }
+    }
+
+    expect($violations)->toBe([], '滞留回収の Schedule 配線が契約を満たしていません。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('各系列の Schedule 失敗が報告される (onFailure が繋がっている)', function (): void {
+    $violations = [];
+
+    foreach (recoveryStreamEvents() as $key => $registered) {
+        $event = $registered[0];
+        $property = new ReflectionProperty(Event::class, 'afterCallbacks');
+        /** @var list<Closure> $callbacks */
+        $callbacks = $property->getValue($event);
+
+        Exceptions::fake();
+        $event->exitCode = 1;
+        foreach ($callbacks as $callback) {
+            $callback(app());
+        }
+
+        $messages = array_map(
+            static fn (Throwable $exception): string => $exception->getMessage(),
+            Exceptions::reported(),
+        );
+        $matched = array_filter(
+            $messages,
+            static fn (string $message): bool => str_contains($message, 'work:recover-stuck --stream='.$key),
+        );
+        if ($matched === []) {
+            $violations[] = $key.' — 失敗時に報告が出ない (onFailure が繋がっていない)';
+        }
+    }
+
+    expect($violations)->toBe([],
+        '回収が止まったことが無音にならないよう、全系列の Schedule に onFailure → report() を付けてください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('Schedule の全コマンドが回収の入口か非回収の申告のどちらかに属する (未分類は fail)', function (): void {
+    $declared = StuckWorkRecoveryInventory::nonRecoverySchedules();
+    $unclassified = [];
+    $seen = [];
+
+    foreach (recoveryScheduledEvents() as $event) {
+        $name = recoveryCommandName($event);
+        if ($name === StuckWorkRecoveryInventory::RECOVERY_COMMAND) {
+            continue; // 回収の入口 (上のテスト群が担当)
+        }
+        $seen[$name] = true;
+        if (! array_key_exists($name, $declared)) {
+            $unclassified[] = $name;
+        }
+    }
+
+    expect(array_values(array_unique($unclassified)))->toBe([],
+        '定期実行に未分類のコマンドがあります。滞留回収なら work:recover-stuck の系列として '
+        .'RecoveryStream へ足し、そうでなければ StuckWorkRecoveryInventory::nonRecoverySchedules() へ '
+        .'区分と 30 文字以上の理由付きで登録してください (6 本目の独自回収を素通しで足せない)。'
+        .PHP_EOL.implode(PHP_EOL, $unclassified));
+
+    $stale = array_values(array_diff(array_keys($declared), array_keys($seen)));
+    expect($stale)->toBe([],
+        '非回収の申告に、Schedule へ登録されていないコマンドが残っています (申告を消してください)。'
+        .PHP_EOL.implode(PHP_EOL, $stale));
+});
+
+test('非回収の申告はすべて区分と 30 文字以上の理由を持つ', function (): void {
+    foreach (StuckWorkRecoveryInventory::nonRecoverySchedules() as $name => $entry) {
+        expect(mb_strlen($entry->reason))
+            ->toBeGreaterThanOrEqual(NonRecoveryScheduleEntry::REASON_MIN_LENGTH, $name);
+    }
+});
diff --git a/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php b/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
index 0a556b3..8c45a41 100644
--- a/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
+++ b/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
@@ -5,6 +5,8 @@
 use App\Enums\Billing\PlanPriceKind;
 use App\Enums\Billing\WebhookEventStatus;
 use App\Enums\Billing\WebhookRecoveryReason;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
 use App\Models\Billing\Plan;
 use App\Models\Billing\StripeWebhookEvent;
 use App\Models\Billing\TicketCheckoutSession;
@@ -22,7 +24,7 @@
 use Webmozart\Assert\Assert;
 
 /*
- * 滞留 webhook の回収 (StripeWebhookProcessor::recoverStale) と、
+ * 滞留 webhook の回収 (StripeWebhookProcessor::recoverStuckEvent) と、
  * 受理した世代を握っている実行だけが行う終局書き込み (finalize の条件付き UPDATE)。
  *
  * 背景: claim() が直列化するのは状態遷移だけで process() はトランザクションの外にある。
@@ -192,9 +194,9 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(1);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(1);
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
 
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_purchase')->firstOrFail();
@@ -213,9 +215,9 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryInvoicePaidPayload('evt_stale_invoice'),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(1);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(1);
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
     expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_invoice')->firstOrFail()->status)
         ->toBe(WebhookEventStatus::Processed);
@@ -238,7 +240,7 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
     );
 
-    app(StripeWebhookProcessor::class)->recoverStale();
+    sweepStuckWorkStream(RecoveryStream::WebhookEvent);
     // 別 event_id での再通知 (event_id 冪等では防げない経路)
     event(new WebhookReceived(staleRecoveryTicketPurchasePayload('evt_resend_purchase', $organization)));
 
@@ -256,10 +258,10 @@ function assertRecoveryReasonInvariant(): void
         staleRecoverySubscriptionPayload('evt_stale_sub'),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->movedToRecoveryPending)->toBe(1);
-    expect($result->replayed)->toBe(0);
+    expect($result->count(RecoveryOutcome::Escalated))->toBe(1);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(0);
 
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_sub')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
@@ -286,9 +288,9 @@ function assertRecoveryReasonInvariant(): void
         attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS,
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->movedToRecoveryPending)->toBe(1);
+    expect($result->count(RecoveryOutcome::Escalated))->toBe(1);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_exhausted')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
     expect($record->recovery_reason)->toBe(WebhookRecoveryReason::AttemptsExhausted);
@@ -304,10 +306,10 @@ function assertRecoveryReasonInvariant(): void
         'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
     ]);
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(1);
-    expect($result->movedToRecoveryPending)->toBe(0);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(1);
+    expect($result->count(RecoveryOutcome::Escalated))->toBe(0);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Processed);
     expect($record->recovery_reason)->toBeNull();
@@ -324,7 +326,7 @@ function assertRecoveryReasonInvariant(): void
         'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
     ], attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
 
-    app(StripeWebhookProcessor::class)->recoverStale();
+    sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
     expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled_max')->firstOrFail()->status)
         ->toBe(WebhookEventStatus::Processed);
@@ -340,10 +342,10 @@ function assertRecoveryReasonInvariant(): void
         minutesAgo: 5,
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(0);
-    expect($result->skipped)->toBe(0);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(0);
+    expect($result->count(RecoveryOutcome::Skipped))->toBe(0);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_fresh')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Received);
     expect($record->attempts)->toBe(0);
@@ -363,9 +365,9 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryInvoicePaidPayload('evt_stale_retry'),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->retryScheduled)->toBe(1);
+    expect($result->count(RecoveryOutcome::Deferred))->toBe(1);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Received); // 終局させない
     expect($record->failure_reason)->toBe('付与処理の一時故障');
@@ -374,7 +376,7 @@ function assertRecoveryReasonInvariant(): void
     // 閾値を再び超えさせて繰り返すと attempts が上限まで進み、最後は回収待ちで止まる
     for ($i = 0; $i < StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS + 1; $i++) {
         pushBackWebhookUpdatedAt('evt_stale_retry', 60);
-        app(StripeWebhookProcessor::class)->recoverStale();
+        sweepStuckWorkStream(RecoveryStream::WebhookEvent);
     }
 
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
@@ -402,10 +404,10 @@ function assertRecoveryReasonInvariant(): void
         staleRecoveryInvoicePaidPayload('evt_stale_overtaken'),
     );
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->skipped)->toBe(1);
-    expect($result->retryScheduled)->toBe(0);
+    expect($result->count(RecoveryOutcome::Skipped))->toBe(1);
+    expect($result->count(RecoveryOutcome::Deferred))->toBe(0);
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_overtaken')->firstOrFail();
     expect($record->attempts)->toBe(5); // 追い越した側の値が残る
     expect($record->failure_reason)->toBeNull(); // 旧世代は何も書かない
@@ -453,7 +455,7 @@ function assertRecoveryReasonInvariant(): void
     expect($organization->refresh()->plan_code)->toBeNull();
 });
 
-test('回収の件数は処置と一致する (replayed / movedToRecoveryPending / skipped)', function (): void {
+test('回収の件数は処置と一致する (recovered / escalated / deferred / skipped)', function (): void {
     [$organization] = staleRecoveryFixture();
     Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
 
@@ -465,23 +467,25 @@ function assertRecoveryReasonInvariant(): void
     );
     staleWebhookRecord('evt_count_fresh', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_count_fresh', 'in_fresh'), minutesAgo: 5);
 
-    $result = app(StripeWebhookProcessor::class)->recoverStale();
+    $result = sweepStuckWorkStream(RecoveryStream::WebhookEvent);
 
-    expect($result->replayed)->toBe(1);
-    expect($result->movedToRecoveryPending)->toBe(1);
-    expect($result->retryScheduled)->toBe(0);
-    expect($result->skipped)->toBe(0);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(1);
+    expect($result->count(RecoveryOutcome::Escalated))->toBe(1);
+    expect($result->count(RecoveryOutcome::Deferred))->toBe(0);
+    expect($result->count(RecoveryOutcome::Skipped))->toBe(0);
     expect($organization->ticketLedgerEntries()->where('idempotency_key', 'monthly:in_stale_1')->count())->toBe(1);
     assertRecoveryReasonInvariant();
 });
 
-test('cron コマンドが滞留を回収し 4 件数を出力する', function (): void {
+test('定期実行のコマンドが滞留を回収し結果の種類ごとの件数を出力する', function (): void {
     [$organization] = staleRecoveryFixture();
     Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
     staleWebhookRecord('evt_cron', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_cron'));
 
-    $this->artisan('billing:recover-stale-webhook-events')
-        ->expectsOutputToContain('replayed 1 / retry-scheduled 0 / moved-to-recovery-pending 0 / skipped 0')
+    $this->artisan('work:recover-stuck --stream=webhook_event --apply')
+        ->expectsOutputToContain(
+            'webhook_event: mode=apply candidates=1 recovered=1 cleanup-failed=0 skipped=0 deferred=0 escalated=0 errors=0 limit-reached=no',
+        )
         ->assertExitCode(0);
 
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
diff --git a/tests/Feature/Billing/TicketCommitWinsTest.php b/tests/Feature/Billing/TicketCommitWinsTest.php
index 9160bed..85be11c 100644
--- a/tests/Feature/Billing/TicketCommitWinsTest.php
+++ b/tests/Feature/Billing/TicketCommitWinsTest.php
@@ -27,7 +27,7 @@ function commitWinsService(): TicketLedgerService
 
     $reservation = commitWinsService()->reserve($organization, 3);
     $this->travel(31)->minutes();
-    commitWinsService()->releaseStale();
+    releaseStaleTicketReservations();
     expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
 
     $result = commitWinsService()->commit($reservation);
@@ -82,7 +82,7 @@ function commitWinsService(): TicketLedgerService
     expect(commitWinsService()->balance($organization)->totalAvailable())->toBe(7);
 });
 
-test('releaseStale は TTL 未超過でも失効 monthly hold を解放する', function (): void {
+test('滞留回収は TTL 未超過でも失効 monthly hold を解放する', function (): void {
     [$organization] = createOrganizationWithOwner();
     // monthly 期限 (10 分後) < reserve TTL (30 分) にして「TTL 切れ」枝と切り分ける
     $expiresAt = CarbonImmutable::now()->addMinutes(10);
@@ -93,6 +93,6 @@ function commitWinsService(): TicketLedgerService
 
     $this->travel(11)->minutes(); // TTL (30 分) は未超過だが monthly hold は失効
 
-    expect(commitWinsService()->releaseStale())->toBe(1);
+    expect(releaseStaleTicketReservations())->toBe(1);
     expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
 });
diff --git a/tests/Feature/Billing/TicketLedgerTest.php b/tests/Feature/Billing/TicketLedgerTest.php
index 26a339b..209cf03 100644
--- a/tests/Feature/Billing/TicketLedgerTest.php
+++ b/tests/Feature/Billing/TicketLedgerTest.php
@@ -99,7 +99,7 @@ function ticketService(): TicketLedgerService
     expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);
 });
 
-test('releaseStale は expires_at 超過の reserved だけを解放する', function (): void {
+test('滞留回収は expires_at 超過の reserved だけを解放する', function (): void {
     [$organization] = createOrganizationWithOwner();
     ticketService()->grant($organization, 10, '初期付与');
 
@@ -110,7 +110,7 @@ function ticketService(): TicketLedgerService
     $this->travel(31)->minutes();
     $fresh = ticketService()->reserve($organization, 2);
 
-    $released = ticketService()->releaseStale();
+    $released = releaseStaleTicketReservations();
 
     expect($released)->toBe(1);
     expect($stale->refresh()->status)->toBe(TicketReservationStatus::Released);
diff --git a/tests/Feature/Billing/WebhookReplaySafetyTest.php b/tests/Feature/Billing/WebhookReplaySafetyTest.php
index 2698135..42b41fc 100644
--- a/tests/Feature/Billing/WebhookReplaySafetyTest.php
+++ b/tests/Feature/Billing/WebhookReplaySafetyTest.php
@@ -16,7 +16,7 @@
 /*
  * 保存済み payload を再実行してよいかの分類 (HandledStripeWebhookEvent::replaySafety)。
  *
- * 分類は滞留回収 (StripeWebhookProcessor::recoverStale) が自動再実行の可否に使う唯一の判断材料
+ * 分類は滞留回収 (StripeWebhookProcessor::recoverStuckEvent) が自動再実行の可否に使う唯一の判断材料
  * なので、網羅性と個々の値に加えて **SafeToReplay の前提** (付与が下位の冪等キーで冪等であること)
  * も behavioral に固定する。
  */
diff --git a/tests/Feature/Capture/PurgeUploadReservationsTest.php b/tests/Feature/Capture/PurgeUploadReservationsTest.php
new file mode 100644
index 0000000..405c604
--- /dev/null
+++ b/tests/Feature/Capture/PurgeUploadReservationsTest.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Models\TakeUploadReservation;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * アップロード予約の保持期間の決着 (capture:purge-upload-reservations)。
+ * 滞留の回収 (work:recover-stuck --stream=upload_reservation) とは責務が違うため入口が別。
+ */
+
+/** updated_at をモデルイベントなしで過去に倒す */
+function backdatePurgeCandidate(TakeUploadReservation $reservation, int $minutes): void
+{
+    DB::table('take_upload_reservations')
+        ->where('id', $reservation->id)
+        ->update(['updated_at' => now()->subMinutes($minutes)]);
+}
+
+test('retention 超過の released/completed 行は物理削除され、期限内の行は残る', function (): void {
+    $cut = Cut::factory()->create();
+    $oldReleased = TakeUploadReservation::factory()->forCut($cut)->released()->create();
+    $oldCompleted = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
+    backdatePurgeCandidate($oldReleased, 60 * 24 * 31); // 31 日前
+    backdatePurgeCandidate($oldCompleted, 60 * 24 * 31);
+    $freshReleased = TakeUploadReservation::factory()->forCut($cut)->released()->create();
+
+    $this->artisan('capture:purge-upload-reservations')
+        ->expectsOutputToContain('purged 2 upload reservation(s)')
+        ->assertSuccessful();
+
+    expect(TakeUploadReservation::query()->whereKey($oldReleased->id)->exists())->toBeFalse();
+    expect(TakeUploadReservation::query()->whereKey($oldCompleted->id)->exists())->toBeFalse();
+    expect(TakeUploadReservation::query()->whereKey($freshReleased->id)->exists())->toBeTrue();
+});
+
+test('pending / verifying は保持期間を過ぎていても削除されない (回収の対象であって決着済みではない)', function (): void {
+    $cut = Cut::factory()->create();
+    $pending = TakeUploadReservation::factory()->forCut($cut)->create();
+    $verifying = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
+    backdatePurgeCandidate($pending, 60 * 24 * 31);
+    backdatePurgeCandidate($verifying, 60 * 24 * 31);
+
+    $this->artisan('capture:purge-upload-reservations')->assertSuccessful();
+
+    expect(TakeUploadReservation::query()->whereKey($pending->id)->exists())->toBeTrue();
+    expect(TakeUploadReservation::query()->whereKey($verifying->id)->exists())->toBeTrue();
+});
diff --git a/tests/Feature/Capture/StaleReservationSweepTest.php b/tests/Feature/Capture/StaleReservationSweepTest.php
deleted file mode 100644
index 34168ae..0000000
--- a/tests/Feature/Capture/StaleReservationSweepTest.php
+++ /dev/null
@@ -1,149 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-use App\Enums\Capture\TakeUploadReservationStatus;
-use App\Models\Cut;
-use App\Models\TakeUploadReservation;
-use App\Services\Capture\StaleUploadReservationSweeper;
-use App\Services\Capture\TakeObjectStorage;
-use Illuminate\Support\Facades\Artisan;
-use Illuminate\Support\Facades\DB;
-use Illuminate\Support\Facades\Schedule;
-use Mockery\MockInterface;
-
-/*
- * 孤児掃除 (施策9): 期限切れ pending / stale verifying の released 化 + S3 孤児削除。
- * fresh verifying (検証中) には触れない。released/completed の retention 超過行は物理削除。
- */
-
-/** updated_at をモデルイベントなしで過去に倒す */
-function backdateReservation(TakeUploadReservation $reservation, int $minutes): void
-{
-    DB::table('take_upload_reservations')
-        ->where('id', $reservation->id)
-        ->update(['updated_at' => now()->subMinutes($minutes)]);
-}
-
-function mockSweeperStorage(bool $exists = true): MockInterface
-{
-    $mock = Mockery::mock(TakeObjectStorage::class);
-    $mock->shouldReceive('exists')->andReturn($exists)->byDefault();
-    $mock->shouldReceive('delete')->byDefault();
-    app()->instance(TakeObjectStorage::class, $mock);
-
-    return $mock;
-}
-
-test('期限切れ pending は released 化され、PUT 済みオブジェクトは削除される', function (): void {
-    $cut = Cut::factory()->create();
-    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
-    $mock = Mockery::mock(TakeObjectStorage::class);
-    $mock->shouldReceive('exists')->once()->with($stale->video_path)->andReturnTrue();
-    $mock->shouldReceive('delete')->once()->with($stale->video_path);
-    app()->instance(TakeObjectStorage::class, $mock);
-
-    $released = app(StaleUploadReservationSweeper::class)->sweep();
-
-    expect($released)->toBe(1);
-    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
-});
-
-test('PUT 未完了 (exists=false) の期限切れ pending は released のみで delete は呼ばれない', function (): void {
-    $cut = Cut::factory()->create();
-    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
-    $mock = Mockery::mock(TakeObjectStorage::class);
-    $mock->shouldReceive('exists')->once()->andReturnFalse();
-    $mock->shouldNotReceive('delete');
-    app()->instance(TakeObjectStorage::class, $mock);
-
-    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(1);
-    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
-});
-
-test('未失効 pending / fresh verifying / completed は触られない', function (): void {
-    $cut = Cut::factory()->create();
-    $pending = TakeUploadReservation::factory()->forCut($cut)->create();
-    $verifying = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
-    $completed = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
-    $mock = mockSweeperStorage();
-    $mock->shouldNotReceive('delete');
-
-    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(0);
-    expect($pending->fresh()?->status)->toBe(TakeUploadReservationStatus::Pending);
-    expect($verifying->fresh()?->status)->toBe(TakeUploadReservationStatus::Verifying);
-    expect($completed->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed);
-});
-
-test('stale verifying (updated_at が閾値超過) は released 化される', function (): void {
-    $cut = Cut::factory()->create();
-    $stale = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
-    backdateReservation($stale, 20); // 閾値 15 分超過
-    mockSweeperStorage();
-
-    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(1);
-    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
-});
-
-test('retention 超過の released/completed 行は物理削除され、期限内の行は残る', function (): void {
-    $cut = Cut::factory()->create();
-    $oldReleased = TakeUploadReservation::factory()->forCut($cut)->released()->create();
-    $oldCompleted = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
-    backdateReservation($oldReleased, 60 * 24 * 31); // 31 日前
-    backdateReservation($oldCompleted, 60 * 24 * 31);
-    $freshReleased = TakeUploadReservation::factory()->forCut($cut)->released()->create();
-    mockSweeperStorage();
-
-    app(StaleUploadReservationSweeper::class)->sweep();
-
-    expect(TakeUploadReservation::query()->whereKey($oldReleased->id)->exists())->toBeFalse();
-    expect(TakeUploadReservation::query()->whereKey($oldCompleted->id)->exists())->toBeFalse();
-    expect(TakeUploadReservation::query()->whereKey($freshReleased->id)->exists())->toBeTrue();
-});
-
-test('冪等: 2 回目の sweep は何もしない', function (): void {
-    $cut = Cut::factory()->create();
-    TakeUploadReservation::factory()->forCut($cut)->expired()->create();
-    mockSweeperStorage();
-
-    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(1);
-    expect(app(StaleUploadReservationSweeper::class)->sweep())->toBe(0);
-});
-
-test('CAS 競合: 一覧取得後に completed 化された予約は released 上書き・削除されない', function (): void {
-    $cut = Cut::factory()->create();
-    $first = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
-    $second = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
-
-    $mock = Mockery::mock(TakeObjectStorage::class);
-    // 1 件目の exists() 呼び出し中に 2 件目が登録処理に completed 化されるケースを再現
-    $mock->shouldReceive('exists')->andReturnUsing(function () use ($second): bool {
-        TakeUploadReservation::query()->whereKey($second->id)
-            ->update(['status' => TakeUploadReservationStatus::Completed]);
-
-        return true;
-    });
-    $deleted = [];
-    $mock->shouldReceive('delete')->andReturnUsing(function (string $path) use (&$deleted): void {
-        $deleted[] = $path;
-    });
-    app()->instance(TakeObjectStorage::class, $mock);
-
-    $released = app(StaleUploadReservationSweeper::class)->sweep();
-
-    expect($released)->toBe(1); // CAS 勝者は 1 件目のみ
-    expect($first->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
-    expect($second->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed); // 上書きされない
-    expect($deleted)->toBe([$first->video_path]); // completed のオブジェクトは削除されない
-});
-
-test('cron: capture:release-stale-upload-reservations コマンドが動き Schedule に登録されている', function (): void {
-    mockSweeperStorage();
-
-    Artisan::call('capture:release-stale-upload-reservations');
-    expect(Artisan::output())->toContain('released 0 stale upload reservation(s)');
-
-    $scheduled = collect(Schedule::events())
-        ->filter(fn ($event) => str_contains((string) $event->command, 'capture:release-stale-upload-reservations'));
-    expect($scheduled)->toHaveCount(1);
-});
diff --git a/tests/Feature/Capture/StaleUploadReservationRecoveryTest.php b/tests/Feature/Capture/StaleUploadReservationRecoveryTest.php
new file mode 100644
index 0000000..492a458
--- /dev/null
+++ b/tests/Feature/Capture/StaleUploadReservationRecoveryTest.php
@@ -0,0 +1,221 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Models\Cut;
+use App\Models\TakeUploadReservation;
+use App\Services\Capture\TakeObjectStorage;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schedule;
+use Mockery\MockInterface;
+
+/*
+ * 孤児掃除の回収側 (work:recover-stuck --stream=upload_reservation --apply):
+ * 期限切れ pending / stale verifying の released 化 + S3 孤児削除。
+ * fresh verifying (検証中) には触れない。保持期間の物理削除は
+ * capture:purge-upload-reservations の担当で本ファイルの範囲外
+ * (PurgeUploadReservationsTest が持つ)。
+ */
+
+/** updated_at をモデルイベントなしで過去に倒す */
+function backdateReservation(TakeUploadReservation $reservation, int $minutes): void
+{
+    DB::table('take_upload_reservations')
+        ->where('id', $reservation->id)
+        ->update(['updated_at' => now()->subMinutes($minutes)]);
+}
+
+function mockSweeperStorage(bool $exists = true): MockInterface
+{
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    $mock->shouldReceive('exists')->andReturn($exists)->byDefault();
+    $mock->shouldReceive('delete')->byDefault();
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    return $mock;
+}
+
+/**
+ * 滞留したアップロード予約を回収し、結果の種類ごとの件数を返す。
+ *
+ * @return array<value-of<RecoveryOutcome>, int>
+ */
+function recoverStaleUploadReservations(): array
+{
+    return sweepStuckWorkStream(RecoveryStream::UploadReservation)->outcomes;
+}
+
+test('期限切れ pending は released 化され、PUT 済みオブジェクトは削除される', function (): void {
+    $cut = Cut::factory()->create();
+    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    $mock->shouldReceive('exists')->once()->with($stale->video_path)->andReturnTrue();
+    $mock->shouldReceive('delete')->once()->with($stale->video_path);
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    expect(recoverStaleUploadReservations())->toBe([RecoveryOutcome::Recovered->value => 1]);
+    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
+});
+
+test('PUT 未完了 (exists=false) の期限切れ pending は released のみで delete は呼ばれない', function (): void {
+    $cut = Cut::factory()->create();
+    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    $mock->shouldReceive('exists')->once()->andReturnFalse();
+    $mock->shouldNotReceive('delete');
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    expect(recoverStaleUploadReservations())->toBe([RecoveryOutcome::Recovered->value => 1]);
+    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
+});
+
+test('未失効 pending / fresh verifying / completed は触られない', function (): void {
+    $cut = Cut::factory()->create();
+    $pending = TakeUploadReservation::factory()->forCut($cut)->create();
+    $verifying = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
+    $completed = TakeUploadReservation::factory()->forCut($cut)->completed()->create();
+    $mock = mockSweeperStorage();
+    $mock->shouldNotReceive('delete');
+
+    expect(recoverStaleUploadReservations())->toBe([]);
+    expect($pending->fresh()?->status)->toBe(TakeUploadReservationStatus::Pending);
+    expect($verifying->fresh()?->status)->toBe(TakeUploadReservationStatus::Verifying);
+    expect($completed->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed);
+});
+
+test('stale verifying (updated_at が閾値超過) は released 化される', function (): void {
+    $cut = Cut::factory()->create();
+    $stale = TakeUploadReservation::factory()->forCut($cut)->verifying()->create();
+    backdateReservation($stale, 20); // 閾値 15 分超過
+    mockSweeperStorage();
+
+    expect(recoverStaleUploadReservations())->toBe([RecoveryOutcome::Recovered->value => 1]);
+    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
+});
+
+test('冪等: 2 回目の回収は何もしない', function (): void {
+    $cut = Cut::factory()->create();
+    TakeUploadReservation::factory()->forCut($cut)->expired()->create();
+    mockSweeperStorage();
+
+    expect(recoverStaleUploadReservations())->toBe([RecoveryOutcome::Recovered->value => 1]);
+    expect(recoverStaleUploadReservations())->toBe([]);
+});
+
+test('競合: 候補列挙後に completed 化された予約は released 上書き・削除されない', function (): void {
+    $cut = Cut::factory()->create();
+    $first = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
+    $second = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
+
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    // 1 件目の exists() 呼び出し中に 2 件目が登録処理に completed 化されるケースを再現
+    $mock->shouldReceive('exists')->andReturnUsing(function () use ($second): bool {
+        TakeUploadReservation::query()->whereKey($second->id)
+            ->update(['status' => TakeUploadReservationStatus::Completed]);
+
+        return true;
+    });
+    $deleted = [];
+    $mock->shouldReceive('delete')->andReturnUsing(function (string $path) use (&$deleted): void {
+        $deleted[] = $path;
+    });
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    $counts = recoverStaleUploadReservations();
+
+    expect($counts)->toBe([
+        RecoveryOutcome::Recovered->value => 1,
+        RecoveryOutcome::Skipped->value => 1,
+    ]);
+    expect($first->fresh()?->status)->toBe(TakeUploadReservationStatus::Released);
+    expect($second->fresh()?->status)->toBe(TakeUploadReservationStatus::Completed); // 上書きされない
+    expect($deleted)->toBe([$first->video_path]); // completed のオブジェクトは削除されない
+});
+
+test('S3 削除の失敗は掃引を止めず、行は released のまま cleanup 失敗として数えられる', function (): void {
+    $cut = Cut::factory()->create();
+    $stale = TakeUploadReservation::factory()->forCut($cut)->expired()->create();
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    $mock->shouldReceive('exists')->andReturnTrue();
+    $mock->shouldReceive('delete')->andThrow(new RuntimeException('S3 削除に失敗'));
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    expect(recoverStaleUploadReservations())
+        ->toBe([RecoveryOutcome::RecoveredWithCleanupFailure->value => 1]);
+    expect($stale->fresh()?->status)->toBe(TakeUploadReservationStatus::Released); // 枠は解放したまま
+});
+
+test('解放とパスの取得は同じ行ロックの中で行われ、S3 削除はコミット後に走る', function (): void {
+    $cut = Cut::factory()->create();
+    TakeUploadReservation::factory()->forCut($cut)->expired()->create();
+
+    // RefreshDatabase がテスト全体を 1 段のトランザクションで包むため、基準値を先に取る
+    $baseline = DB::transactionLevel();
+
+    $levelDuringExists = null;
+    $mock = Mockery::mock(TakeObjectStorage::class);
+    $mock->shouldReceive('exists')->andReturnUsing(function () use (&$levelDuringExists): bool {
+        $levelDuringExists = DB::transactionLevel();
+
+        return false;
+    });
+    $mock->shouldNotReceive('delete');
+    app()->instance(TakeObjectStorage::class, $mock);
+
+    recoverStaleUploadReservations();
+
+    // 外部の入出力は解放のトランザクションの外 (行ロックを保持したまま待たない)
+    expect($levelDuringExists)->toBe($baseline);
+});
+
+test('定期実行: 回収と保持期間の決着が別コマンドで 1 本ずつ Schedule に登録されている', function (): void {
+    mockSweeperStorage();
+
+    $this->artisan('work:recover-stuck --stream=upload_reservation --apply')
+        ->expectsOutputToContain('upload_reservation: mode=apply candidates=0')
+        ->assertSuccessful();
+
+    $recovery = collect(Schedule::events())
+        ->filter(fn ($event) => str_contains((string) $event->command, 'work:recover-stuck --stream=upload_reservation'));
+    expect($recovery)->toHaveCount(1);
+
+    $purge = collect(Schedule::events())
+        ->filter(fn ($event) => str_contains((string) $event->command, 'capture:purge-upload-reservations'));
+    expect($purge)->toHaveCount(1);
+});
+
+test('上限に達し後続候補が実在するとき limit-reached=yes が出力される', function (): void {
+    $cut = Cut::factory()->create();
+    TakeUploadReservation::factory()->count(2)->forCut($cut)->expired()->create();
+    mockSweeperStorage(exists: false);
+
+    // 系列の申告 (500) は実効上限の min() 側で --limit に置き換わる。
+    // 上限に達したうえで**未処理の候補が実在する**ときだけ打ち切りとして出る
+    Artisan::call('work:recover-stuck', [
+        '--stream' => 'upload_reservation',
+        '--apply' => true,
+        '--limit' => '1',
+    ]);
+
+    $output = Artisan::output();
+    expect($output)->toContain('candidates=1 recovered=1');
+    expect($output)->toContain('limit-reached=yes');
+});
+
+test('候補がちょうど上限件数で尽きたときは limit-reached=no (打ち切りではない)', function (): void {
+    $cut = Cut::factory()->create();
+    TakeUploadReservation::factory()->forCut($cut)->expired()->create();
+    mockSweeperStorage(exists: false);
+
+    Artisan::call('work:recover-stuck', [
+        '--stream' => 'upload_reservation',
+        '--apply' => true,
+        '--limit' => '1',
+    ]);
+
+    expect(Artisan::output())->toContain('limit-reached=no');
+});
diff --git a/tests/Feature/Console/RecoverStuckWorkCommandTest.php b/tests/Feature/Console/RecoverStuckWorkCommandTest.php
new file mode 100644
index 0000000..88b53e9
--- /dev/null
+++ b/tests/Feature/Console/RecoverStuckWorkCommandTest.php
@@ -0,0 +1,111 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Recovery\RecoveryStream;
+use App\Models\AnalysisJob;
+use App\Models\Project;
+use App\Models\VideoManual;
+use App\Services\Manual\AnalysisJobService;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Support\Facades\Artisan;
+
+/*
+ * 滞留回収の唯一の入口 (work:recover-stuck) の振る舞い。
+ * 既定は実行しない (数えるだけ) / 引数の誤りは無制限実行に落とさない / 監視の語彙が消えない。
+ */
+
+/** 30 分超過した queued 解析ジョブを 1 件作る */
+function stuckWorkAnalysisJob(): AnalysisJob
+{
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
+
+    return AnalysisJob::factory()->forManual($manual)->create(['status' => 'queued']);
+}
+
+/**
+ * 解析ジョブの回収が必ず例外になるよう仕込む
+ * (registry と系列は本物のまま。ドメイン Service だけ差し替えて実配線を通す)。
+ */
+function makeAnalysisRecoveryThrow(int $candidateId): void
+{
+    $jobs = Mockery::mock(AnalysisJobService::class);
+    $jobs->shouldReceive('staleJobIds')
+        ->andReturnUsing(static fn (CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array => $afterId === null ? [$candidateId] : []);
+    $jobs->shouldReceive('failStaleJob')->andThrow(new RuntimeException('回収に失敗した'));
+    app()->instance(AnalysisJobService::class, $jobs);
+}
+
+test('--apply 無しでは DB が 1 バイトも変わらない (候補だけ数える)', function (): void {
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
+    $job = stuckWorkAnalysisJob();
+
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
+    $exitCode = Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job']);
+
+    expect($exitCode)->toBe(Command::SUCCESS);
+    expect(Artisan::output())
+        ->toContain('analysis_job: mode=dry-run (candidates は回収件数の上界) candidates=1 recovered=0');
+
+    expect($job->refresh()->status)->toBe(JobStatus::Queued);
+});
+
+test('--stream に未知の値を渡すと失敗し、有効な値の一覧が出る', function (): void {
+    $exitCode = Artisan::call('work:recover-stuck', ['--stream' => 'nope', '--apply' => true]);
+    $output = Artisan::output();
+
+    expect($exitCode)->toBe(Command::FAILURE);
+    expect($output)->toContain('--stream の値が不正です: nope');
+    foreach (RecoveryStream::cases() as $stream) {
+        expect($output)->toContain($stream->value);
+    }
+});
+
+test('--limit の不正値は失敗する (無制限実行へ落とさない)', function (string $limit): void {
+    $exitCode = Artisan::call('work:recover-stuck', [
+        '--stream' => 'analysis_job',
+        '--apply' => true,
+        '--limit' => $limit,
+    ]);
+
+    expect($exitCode)->toBe(Command::FAILURE);
+    expect(Artisan::output())->toContain('--limit には 1 以上の整数を指定してください');
+})->with(['0', '-1', 'abc', '1.5']);
+
+test('--limit の未指定と有効値は区別され、どちらも成功する', function (): void {
+    expect(Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job', '--apply' => true]))
+        ->toBe(Command::SUCCESS);
+    expect(Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job', '--apply' => true, '--limit' => '1']))
+        ->toBe(Command::SUCCESS);
+});
+
+test('--stream 省略時は 5 系列すべての行が出力される', function (): void {
+    expect(Artisan::call('work:recover-stuck'))->toBe(Command::SUCCESS);
+    $output = Artisan::output();
+
+    foreach (RecoveryStream::cases() as $stream) {
+        expect($output)->toContain($stream->value.': mode=dry-run');
+    }
+});
+
+test('出力に監視の語彙 5 つが必ず含まれる (黙って消えない)', function (): void {
+    Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job']);
+    $output = Artisan::output();
+
+    foreach (['errors=', 'deferred=', 'escalated=', 'cleanup-failed=', 'limit-reached='] as $vocabulary) {
+        expect($output)->toContain($vocabulary);
+    }
+});
+
+test('1 件でも例外があれば終了コードは失敗になる (掃引自体は止まらない)', function (): void {
+    makeAnalysisRecoveryThrow(candidateId: 1);
+
+    $exitCode = Artisan::call('work:recover-stuck', ['--stream' => 'analysis_job', '--apply' => true]);
+
+    expect($exitCode)->toBe(Command::FAILURE);
+    expect(Artisan::output())->toContain('errors=1');
+});
diff --git a/tests/Feature/Manual/RenderPipelineTest.php b/tests/Feature/Manual/RenderPipelineTest.php
index a03d446..00c5d1c 100644
--- a/tests/Feature/Manual/RenderPipelineTest.php
+++ b/tests/Feature/Manual/RenderPipelineTest.php
@@ -341,7 +341,7 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
     // 台帳に消費行が立てば課金は成立する (課金の真実源は台帳。status は一方向遷移を壊さない)
     [, , , $manual, $cut, $job, $fake] = renderPipelineContext();
     $fake->duringCompose = function () use ($job): void {
-        // finalize 前に予約が releaseStale cron で解放される競合を細工
+        // finalize 前に予約が滞留回収で解放される競合を細工
         $reservation = $job->refresh()->ticketReservation;
         if ($reservation !== null) {
             app(TicketLedgerService::class)->release($reservation);
diff --git a/tests/Feature/Manual/RenderStaleRecoveryTest.php b/tests/Feature/Manual/RenderStaleRecoveryTest.php
index b73ed6e..0a585fd 100644
--- a/tests/Feature/Manual/RenderStaleRecoveryTest.php
+++ b/tests/Feature/Manual/RenderStaleRecoveryTest.php
@@ -6,17 +6,19 @@
 use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\VideoManualStatus;
+use App\Enums\Recovery\RecoveryOutcome;
 use App\Models\Organization;
 use App\Models\Project;
 use App\Models\RenderJob;
 use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Billing\TicketLedgerService;
-use App\Services\Manual\RenderJobService;
+use App\Services\Recovery\Streams\StaleRenderJobStream;
 use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\DB;
 
 /*
- * stale 回復 (render:recover-stale-jobs。概念設計 §5):
+ * stale 回復 (work:recover-stuck --stream=render_job --apply。概念設計 §5):
  * - queued: created_at が 10 分 (render_queued_stale_after_minutes) 超過 → failJob (短 SLA)
  * - running: updated_at が 30 分 (render_stale_after_minutes) 超過 → failJob
  * - error_code=timeout / kind=render は rendering→ready 復帰 / preview は status 不変 /
@@ -47,7 +49,7 @@ function staleRecoveryContext(): array
     $freshQueued = RenderJob::factory()->forManual($manual)->preview()->create();
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:11:00'));
-    $recovered = app(RenderJobService::class)->recoverStale();
+    $recovered = recoverStaleRenderJobs();
 
     expect($recovered)->toBe(1);
     expect($staleQueued->refresh()->status)->toBe(JobStatus::Failed);
@@ -66,7 +68,7 @@ function staleRecoveryContext(): array
     $freshRunning = RenderJob::factory()->forManual($manual)->preview()->running()->create();
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
-    $recovered = app(RenderJobService::class)->recoverStale();
+    $recovered = recoverStaleRenderJobs();
 
     expect($recovered)->toBe(1);
     expect($staleRunning->refresh()->status)->toBe(JobStatus::Failed);
@@ -84,7 +86,7 @@ function staleRecoveryContext(): array
     ]);
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
-    app(RenderJobService::class)->recoverStale();
+    recoverStaleRenderJobs();
 
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
     expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
@@ -101,7 +103,7 @@ function staleRecoveryContext(): array
     $job = RenderJob::factory()->forManual($readyManual)->preview()->running()->create();
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
-    app(RenderJobService::class)->recoverStale();
+    recoverStaleRenderJobs();
 
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
     expect($readyManual->refresh()->status)->toBe(VideoManualStatus::Ready);
@@ -114,20 +116,92 @@ function staleRecoveryContext(): array
     $failed = RenderJob::factory()->forManual($manual)->failed()->create();
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 01:00:00'));
-    $recovered = app(RenderJobService::class)->recoverStale();
+    $recovered = recoverStaleRenderJobs();
 
     expect($recovered)->toBe(0);
     expect($succeeded->refresh()->status)->toBe(JobStatus::Succeeded);
     expect($failed->refresh()->status)->toBe(JobStatus::Failed);
 });
 
-test('render:recover-stale-jobs command smoke (回収件数を出力する)', function (): void {
+test('work:recover-stuck --stream=render_job command smoke (回収件数を出力する)', function (): void {
     [, , , $manual] = staleRecoveryContext();
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
     RenderJob::factory()->forManual($manual)->create();
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:11:00'));
-    $this->artisan('render:recover-stale-jobs')
-        ->expectsOutputToContain('recovered 1 stale render job(s)')
+    $this->artisan('work:recover-stuck --stream=render_job --apply')
+        ->expectsOutputToContain('render_job: mode=apply candidates=1 recovered=1')
         ->assertSuccessful();
 });
+
+/*
+ * 誤回収の防止 (T171): 候補を列挙してから行ロックを取るまでの間に worker が進捗を書いた
+ * running ジョブは失敗にしない。レンダは manual を rendering→ready へ戻す副作用があるため、
+ * 誤回収を止めることは**編集ロックの誤解除を止める**ことでもある。
+ */
+
+test('候補列挙後に進捗が進んだ running レンダジョブは Skipped で failed にならない', function (): void {
+    [, , , $manual] = staleRecoveryContext();
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
+    $job = RenderJob::factory()->forManual($manual)->running()->create();
+    $stream = app(StaleRenderJobStream::class);
+
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
+    $sweptAt = CarbonImmutable::now();
+    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$job->id]);
+
+    DB::table('render_jobs')->where('id', $job->id)->update(['updated_at' => $sweptAt]);
+
+    expect($stream->recover($job->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
+    expect($job->refresh()->status)->toBe(JobStatus::Running);
+    expect($manual->refresh()->status)->toBe(VideoManualStatus::Rendering); // 編集ロックも解けない
+});
+
+test('列挙とロック取得は同じ述語を使う (kind × 状態 × 閾値境界の直積で一致する)', function (): void {
+    [, , $project] = staleRecoveryContext();
+    $stream = app(StaleRenderJobStream::class);
+
+    // queued は 10 分 / running は 30 分。境界ちょうどは超過扱い (<=)
+    $cases = [
+        ['preview' => false, 'running' => false, 'boundary' => 10],
+        ['preview' => true, 'running' => false, 'boundary' => 10],
+        ['preview' => false, 'running' => true, 'boundary' => 30],
+        ['preview' => true, 'running' => true, 'boundary' => 30],
+    ];
+
+    foreach ($cases as $index => $case) {
+        $manual = VideoManual::factory()->forProject($project)->create([
+            'status' => VideoManualStatus::Ready->value,
+        ]);
+        $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
+        $factory = RenderJob::factory()->forManual($manual);
+        if ($case['preview']) {
+            $factory = $factory->preview();
+        }
+        if ($case['running']) {
+            $factory = $factory->running();
+        }
+        $job = $factory->create();
+
+        // 1 分手前 = 未超過 → 候補にも入らず、名指しの回収も Skipped
+        $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00')->addMinutes($case['boundary'] - 1));
+        $freshSweptAt = CarbonImmutable::now();
+        expect($stream->candidateIds($freshSweptAt, null, 50))->not->toContain($job->id);
+        expect($stream->recover($job->id, $freshSweptAt))->toBe(RecoveryOutcome::Skipped, "case {$index} (未超過)");
+
+        // 境界ちょうど = 超過 → 候補に入り、回収も成立する
+        $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00')->addMinutes($case['boundary']));
+        $staleSweptAt = CarbonImmutable::now();
+        expect($stream->candidateIds($staleSweptAt, null, 50))->toContain($job->id);
+        expect($stream->recover($job->id, $staleSweptAt))->toBe(RecoveryOutcome::Recovered, "case {$index} (超過)");
+    }
+});
+
+test('candidateIds は pageSize を超える件数を返さない', function (): void {
+    [, , , $manual] = staleRecoveryContext();
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
+    RenderJob::factory()->count(3)->forManual($manual)->create();
+
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:11:00'));
+    expect(app(StaleRenderJobStream::class)->candidateIds(CarbonImmutable::now(), null, 2))->toHaveCount(2);
+});
diff --git a/tests/Feature/Notifications/ManualAnalysisNotificationTest.php b/tests/Feature/Notifications/ManualAnalysisNotificationTest.php
index 516e91f..4381331 100644
--- a/tests/Feature/Notifications/ManualAnalysisNotificationTest.php
+++ b/tests/Feature/Notifications/ManualAnalysisNotificationTest.php
@@ -25,7 +25,7 @@
  * - 成功 (pipeline finalize true) → creator ∪ triggeredBy に各 1 件 (succeeded=true)
  * - creator = triggeredBy は dedup で 1 件のみ
  * - 失敗 (failJob true) → 1 件 (succeeded=false)。failJob 2 回目 no-op で二重発火しない
- * - recoverStale 経由の失敗も通知される
+ * - 滞留回収経由の失敗も通知される
  * - 退会済み (org 非所属) creator へは送らない / manual 削除競合は通知スキップ (例外なし)
  */
 
@@ -146,14 +146,14 @@ function fakeAnalysisLlmSuccess(): void
     expect(DB::table('notifications')->count())->toBe(1);
 });
 
-test('recoverStale 経由の失敗も通知が 1 件発火する', function (): void {
+test('滞留回収経由の失敗も通知が 1 件発火する', function (): void {
     [, $owner, , , $job] = analysisNotificationContext();
     $job->forceFill(['status' => JobStatus::Running->value])->save();
     // stale 閾値超過に細工 (updated_at を過去へ)
     DB::table('analysis_jobs')->where('id', $job->id)
         ->update(['updated_at' => now()->subHours(2)]);
 
-    expect(app(AnalysisJobService::class)->recoverStale())->toBe(1);
+    expect(recoverStaleAnalysisJobs())->toBe(1);
 
     $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
     expect($rows)->toHaveCount(1);
diff --git a/tests/Feature/Notifications/ManualRenderNotificationTest.php b/tests/Feature/Notifications/ManualRenderNotificationTest.php
index f8e5bc1..04cb6b5 100644
--- a/tests/Feature/Notifications/ManualRenderNotificationTest.php
+++ b/tests/Feature/Notifications/ManualRenderNotificationTest.php
@@ -26,7 +26,7 @@
  * レンダジョブ terminal 遷移の通知配線 (施策3/4):
  * - render 成功 (pipeline finalize true) / 失敗 (failJob true) → 1 件
  * - preview は成功/失敗とも通知 0
- * - failJob 2 回目 no-op で二重発火しない / recoverStale 経由の失敗通知
+ * - failJob 2 回目 no-op で二重発火しない / 滞留回収経由の失敗通知
  */
 
 /** テスト用 fake composer (実 ffmpeg に触れない) */
@@ -133,7 +133,7 @@ function renderNotificationContext(): array
     expect(DB::table('notifications')->count())->toBe(0);
 });
 
-test('recoverStale 経由の render 失敗も通知される', function (): void {
+test('滞留回収経由の render 失敗も通知される', function (): void {
     [, $owner, $project, $manual] = renderNotificationContext();
     $job = app(RenderJobService::class)->trigger($project, $manual, $owner);
     DB::table('render_jobs')->where('id', $job->id)->update([
@@ -141,7 +141,7 @@ function renderNotificationContext(): array
         'updated_at' => now()->subHours(2),
     ]);
 
-    expect(app(RenderJobService::class)->recoverStale())->toBe(1);
+    expect(recoverStaleRenderJobs())->toBe(1);
 
     $rows = DB::table('notifications')->where('notifiable_id', $owner->id)->get();
     expect($rows)->toHaveCount(1);
diff --git a/tests/Feature/Projects/AnalysisPipelineTest.php b/tests/Feature/Projects/AnalysisPipelineTest.php
index d8e3e97..96ffaf4 100644
--- a/tests/Feature/Projects/AnalysisPipelineTest.php
+++ b/tests/Feature/Projects/AnalysisPipelineTest.php
@@ -377,7 +377,7 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     $job->ticketReservation()->associate($reservation);
     $job->status = JobStatus::Running;
     $job->save();
-    // finalize 直前に予約が Released になる競合を細工 (releaseStale cron 相当)
+    // finalize 直前に予約が Released になる競合を細工 (滞留予約の解放と同じ状況)
     app(TicketLedgerService::class)->release($reservation);
 
     $generated = GeneratedScenarioData::fromLlmText(scenarioFixture());
@@ -685,7 +685,7 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(1);
 });
 
-test('(D) 強制終了 (commit 前): failed() を呼べなくても recoverStale が予約を Released へ収束させる', function (): void {
+test('(D) 強制終了 (commit 前): failed() を呼べなくても滞留回収が予約を Released へ収束させる', function (): void {
     $this->travelTo(CarbonImmutable::parse('2026-08-04 00:00:00'));
     [$organization, , , , , $job] = pipelineContext();
     $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
@@ -693,9 +693,9 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     $job->status = JobStatus::Running;
     $job->save();
 
-    // SIGALRM で failed() 自体が失敗したケース = cron だけが収束させる
+    // SIGALRM で failed() 自体が失敗したケース = 定期実行だけが収束させる
     $this->travelTo(CarbonImmutable::parse('2026-08-04 00:31:00'));
-    app(AnalysisJobService::class)->recoverStale();
+    recoverStaleAnalysisJobs();
 
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
     expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
@@ -715,7 +715,7 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Committed);
 });
 
-test('(D) 強制終了: failJob → recoverStale → releaseStale の順でも最終会計状態は Released', function (): void {
+test('(D) 強制終了: 失敗確定 → ジョブ回収 → 予約解放 の順でも最終会計状態は Released', function (): void {
     $this->travelTo(CarbonImmutable::parse('2026-08-04 00:00:00'));
     [$organization, , , , , $job] = pipelineContext();
     $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
@@ -725,15 +725,15 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     $this->travelTo(CarbonImmutable::parse('2026-08-04 00:31:00'));
 
     app(AnalysisJobService::class)->failJob($job, '解析が中断されました。再実行してください。');
-    app(AnalysisJobService::class)->recoverStale();
-    app(TicketLedgerService::class)->releaseStale();
+    recoverStaleAnalysisJobs();
+    releaseStaleTicketReservations();
 
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
     expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
     expect(TicketLedgerEntry::query()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(0);
 });
 
-test('(D) 強制終了: releaseStale → recoverStale → failJob の逆順でも最終会計状態は同じ', function (): void {
+test('(D) 強制終了: 予約解放 → ジョブ回収 → 失敗確定 の逆順でも最終会計状態は同じ', function (): void {
     $this->travelTo(CarbonImmutable::parse('2026-08-04 00:00:00'));
     [$organization, , , , , $job] = pipelineContext();
     $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
@@ -742,8 +742,8 @@ function installThrowingLlm(array $script, ?Closure $onAttempt = null): Throwing
     $job->save();
     $this->travelTo(CarbonImmutable::parse('2026-08-04 00:31:00'));
 
-    app(TicketLedgerService::class)->releaseStale();
-    app(AnalysisJobService::class)->recoverStale();
+    releaseStaleTicketReservations();
+    recoverStaleAnalysisJobs();
     app(AnalysisJobService::class)->failJob($job, '解析が中断されました。再実行してください。');
 
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
diff --git a/tests/Feature/Projects/AnalysisRecoverStaleJobsTest.php b/tests/Feature/Projects/AnalysisRecoverStaleJobsTest.php
index 64c9263..24139cc 100644
--- a/tests/Feature/Projects/AnalysisRecoverStaleJobsTest.php
+++ b/tests/Feature/Projects/AnalysisRecoverStaleJobsTest.php
@@ -5,16 +5,19 @@
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\VideoManualStatus;
+use App\Enums\Recovery\RecoveryOutcome;
 use App\Models\AnalysisJob;
 use App\Models\Organization;
 use App\Models\Project;
 use App\Models\VideoManual;
 use App\Services\Billing\TicketLedgerService;
 use App\Services\Manual\AnalysisPipeline;
+use App\Services\Recovery\Streams\StaleAnalysisJobStream;
 use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\DB;
 
 /*
- * stale 回復 cron (analysis:recover-stale-jobs。概念設計 §4):
+ * stale 回復の定期実行 (work:recover-stuck --stream=analysis_job --apply。概念設計 §4):
  * - queued (dispatch 喪失) / running (worker 異常終了) の閾値超過 → failJob
  * - 閾値内・terminal は対象外
  * - 回収後の遅延配送は queued guard で no-op
@@ -42,8 +45,8 @@ function staleJobContext(string $status = 'queued'): array
     $job->save();
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
-    $this->artisan('analysis:recover-stale-jobs')
-        ->expectsOutputToContain('recovered 1 stale analysis job(s)')
+    $this->artisan('work:recover-stuck --stream=analysis_job --apply')
+        ->expectsOutputToContain('analysis_job: mode=apply candidates=1 recovered=1')
         ->assertSuccessful();
 
     $job->refresh();
@@ -58,7 +61,7 @@ function staleJobContext(string $status = 'queued'): array
     [, $manual, $job] = staleJobContext('running');
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
-    $this->artisan('analysis:recover-stale-jobs')->assertSuccessful();
+    $this->artisan('work:recover-stuck --stream=analysis_job --apply')->assertSuccessful();
 
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
     expect($manual->refresh()->status)->toBe(VideoManualStatus::Draft);
@@ -70,8 +73,8 @@ function staleJobContext(string $status = 'queued'): array
     [, , $running] = staleJobContext('running');
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:10:00'));
-    $this->artisan('analysis:recover-stale-jobs')
-        ->expectsOutputToContain('recovered 0 stale analysis job(s)')
+    $this->artisan('work:recover-stuck --stream=analysis_job --apply')
+        ->expectsOutputToContain('analysis_job: mode=apply candidates=0 recovered=0')
         ->assertSuccessful();
 
     expect($queued->refresh()->status)->toBe(JobStatus::Queued);
@@ -84,8 +87,8 @@ function staleJobContext(string $status = 'queued'): array
     [, , $failed] = staleJobContext('failed');
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 01:00:00'));
-    $this->artisan('analysis:recover-stale-jobs')
-        ->expectsOutputToContain('recovered 0 stale analysis job(s)')
+    $this->artisan('work:recover-stuck --stream=analysis_job --apply')
+        ->expectsOutputToContain('analysis_job: mode=apply candidates=0 recovered=0')
         ->assertSuccessful();
 
     expect($succeeded->refresh()->status)->toBe(JobStatus::Succeeded);
@@ -97,7 +100,7 @@ function staleJobContext(string $status = 'queued'): array
     [, $manual, $job] = staleJobContext();
 
     $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
-    $this->artisan('analysis:recover-stale-jobs')->assertSuccessful();
+    $this->artisan('work:recover-stuck --stream=analysis_job --apply')->assertSuccessful();
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
 
     // 遅延配送 (queue 詰まりが解けて後から届いた) → LLM 呼び出しなしで即 return
@@ -106,3 +109,81 @@ function staleJobContext(string $status = 'queued'): array
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
     expect($manual->refresh()->cuts()->count())->toBe(0);
 });
+
+/*
+ * 誤回収の防止 (T171 で塞いだ欠陥): 候補を列挙してから行ロックを取るまでの間に
+ * worker が進捗を書いた running ジョブを、正常に動いているのに失敗として確定してしまう窓。
+ * 行ロック下で滞留の述語ごと再評価する形にしたので Skipped になる。
+ */
+
+test('候補列挙後に進捗が進んだ running ジョブは Skipped で failed にならない', function (): void {
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
+    [, , $job] = staleJobContext('running');
+    $stream = app(StaleAnalysisJobStream::class);
+
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
+    $sweptAt = CarbonImmutable::now();
+    $ids = $stream->candidateIds($sweptAt, null, 10);
+    expect($ids)->toBe([$job->id]);
+
+    // worker が進捗を書いた (updated_at が現在時刻へ進む)
+    DB::table('analysis_jobs')->where('id', $job->id)->update(['updated_at' => $sweptAt]);
+
+    expect($stream->recover($job->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
+    expect($job->refresh()->status)->toBe(JobStatus::Running);
+});
+
+test('候補列挙後に succeeded へ先着されたジョブは Skipped', function (): void {
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
+    [, , $job] = staleJobContext('running');
+    $stream = app(StaleAnalysisJobStream::class);
+
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
+    $sweptAt = CarbonImmutable::now();
+    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$job->id]);
+
+    DB::table('analysis_jobs')->where('id', $job->id)->update(['status' => JobStatus::Succeeded->value]);
+
+    expect($stream->recover($job->id, $sweptAt))->toBe(RecoveryOutcome::Skipped);
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+});
+
+test('candidateIds は昇順・afterId より大きい id だけ・pageSize を超えない', function (): void {
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
+    [, , $first] = staleJobContext();
+    [, , $second] = staleJobContext();
+    [, , $third] = staleJobContext();
+    $stream = app(StaleAnalysisJobStream::class);
+
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
+    $sweptAt = CarbonImmutable::now();
+
+    expect($stream->candidateIds($sweptAt, null, 2))->toBe([$first->id, $second->id]);
+    expect($stream->candidateIds($sweptAt, $first->id, 10))->toBe([$second->id, $third->id]);
+    expect($stream->candidateIds($sweptAt, $third->id, 10))->toBe([]);
+});
+
+test('列挙とロック取得は同じ述語を使う (閾値の境界 4 点で結果が一致する)', function (): void {
+    $stream = app(StaleAnalysisJobStream::class);
+
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
+    [, , $queuedStale] = staleJobContext();          // created_at = 00:00
+    [, , $runningStale] = staleJobContext('running'); // updated_at = 00:00
+
+    // 閾値 30 分ちょうど = 超過 (<=) → 両方が候補で、回収も成立する
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:30:00'));
+    $sweptAt = CarbonImmutable::now();
+    expect($stream->candidateIds($sweptAt, null, 10))->toBe([$queuedStale->id, $runningStale->id]);
+    expect($stream->recover($queuedStale->id, $sweptAt))->toBe(RecoveryOutcome::Recovered);
+    expect($stream->recover($runningStale->id, $sweptAt))->toBe(RecoveryOutcome::Recovered);
+
+    // 1 分手前 = 未超過 → 候補にも入らず、名指しで回収しても Skipped になる
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 01:00:00'));
+    [, , $queuedFresh] = staleJobContext();
+    [, , $runningFresh] = staleJobContext('running');
+    $this->travelTo(CarbonImmutable::parse('2026-07-11 01:29:00'));
+    $freshSweptAt = CarbonImmutable::now();
+    expect($stream->candidateIds($freshSweptAt, null, 10))->toBe([]);
+    expect($stream->recover($queuedFresh->id, $freshSweptAt))->toBe(RecoveryOutcome::Skipped);
+    expect($stream->recover($runningFresh->id, $freshSweptAt))->toBe(RecoveryOutcome::Skipped);
+});
diff --git a/tests/Feature/Recovery/StuckWorkRecoverySweeperTest.php b/tests/Feature/Recovery/StuckWorkRecoverySweeperTest.php
new file mode 100644
index 0000000..c019ab1
--- /dev/null
+++ b/tests/Feature/Recovery/StuckWorkRecoverySweeperTest.php
@@ -0,0 +1,162 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Contracts\Recovery\StuckWorkStream;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Recovery\StuckWorkRecoverySweeper;
+use Carbon\CarbonImmutable;
+
+/*
+ * 掃引 (走査枠) の契約。作用は差し替え可能な系列に閉じてあるので、DB に触れずに
+ * ページ送り・上限・例外の扱い・打ち切りの区別を直接固定できる。
+ */
+
+/** 主キーの列を持つだけの試験用の系列 */
+function fakeRecoveryStream(
+    array $ids,
+    ?int $sweepItemLimit = null,
+    ?Closure $onRecover = null,
+    ?int $overReturn = null,
+): StuckWorkStream {
+    return new class($ids, $sweepItemLimit, $onRecover, $overReturn) implements StuckWorkStream
+    {
+        /** @var list<array{int, CarbonImmutable}> */
+        public array $recovered = [];
+
+        /** @var list<CarbonImmutable> */
+        public array $sweptAtSeen = [];
+
+        public function __construct(
+            private array $ids,
+            private ?int $sweepItemLimit,
+            private ?Closure $onRecover,
+            private ?int $overReturn,
+        ) {}
+
+        public function stream(): RecoveryStream
+        {
+            return RecoveryStream::AnalysisJob;
+        }
+
+        public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
+        {
+            $this->sweptAtSeen[] = $sweptAt;
+            $remaining = array_values(array_filter(
+                $this->ids,
+                static fn (int $id): bool => $afterId === null || $id > $afterId,
+            ));
+
+            // 契約違反 (要求より多く返す) を再現するための細工
+            return array_slice($remaining, 0, $this->overReturn ?? $pageSize);
+        }
+
+        public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
+        {
+            $this->recovered[] = [$id, $sweptAt];
+            $this->sweptAtSeen[] = $sweptAt;
+
+            if ($this->onRecover !== null) {
+                return ($this->onRecover)($id);
+            }
+
+            return RecoveryOutcome::Recovered;
+        }
+
+        public function sweepItemLimit(): ?int
+        {
+            return $this->sweepItemLimit;
+        }
+    };
+}
+
+test('公平性: 先頭が毎回例外でも後続の全件が同じ掃引で回収される', function (): void {
+    // ページサイズ (200) を超える候補を作り、先頭の 1 件だけが必ず例外を投げる
+    $ids = range(1, 250);
+    $stream = fakeRecoveryStream($ids, onRecover: function (int $id): RecoveryOutcome {
+        if ($id === 1) {
+            throw new RuntimeException('この行は毎回失敗する');
+        }
+
+        return RecoveryOutcome::Recovered;
+    });
+
+    $result = app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: true);
+
+    expect($result->candidates)->toBe(250);
+    expect($result->failures)->toBe(1);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(249);
+    expect(array_map(static fn (array $call): int => $call[0], $stream->recovered))->toBe($ids);
+});
+
+test('実行しない指定は recover を 1 度も呼ばず候補件数だけを数える', function (): void {
+    $stream = fakeRecoveryStream([1, 2, 3]);
+
+    $result = app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: false);
+
+    expect($result->applied)->toBeFalse();
+    expect($result->candidates)->toBe(3);
+    expect($stream->recovered)->toBe([]);
+    expect($result->outcomes)->toBe([]);
+});
+
+test('1 件の例外は掃引を止めず failures に数えられる', function (): void {
+    $stream = fakeRecoveryStream([1, 2, 3], onRecover: function (int $id): RecoveryOutcome {
+        if ($id === 2) {
+            throw new RuntimeException('一時的な失敗');
+        }
+
+        return RecoveryOutcome::Recovered;
+    });
+
+    $result = app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: true);
+
+    expect($result->failures)->toBe(1);
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(2);
+});
+
+test('実効上限は min(--limit, 系列の申告)。両方無指定なら全件', function (): void {
+    $sweeper = app(StuckWorkRecoverySweeper::class);
+
+    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10)), apply: true)->candidates)->toBe(10);
+    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10)), apply: true, limitOverride: 4)->candidates)->toBe(4);
+    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10), sweepItemLimit: 3), apply: true)->candidates)->toBe(3);
+    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10), sweepItemLimit: 3), apply: true, limitOverride: 7)->candidates)->toBe(3);
+    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10), sweepItemLimit: 8), apply: true, limitOverride: 2)->candidates)->toBe(2);
+});
+
+test('打ち切りの区別: 候補がちょうど上限件数なら limitReached は false', function (): void {
+    $sweeper = app(StuckWorkRecoverySweeper::class);
+
+    $exact = $sweeper->sweep(fakeRecoveryStream(range(1, 5)), apply: true, limitOverride: 5);
+    expect($exact->candidates)->toBe(5);
+    expect($exact->limitReached)->toBeFalse();
+
+    $overflow = $sweeper->sweep(fakeRecoveryStream(range(1, 6)), apply: true, limitOverride: 5);
+    expect($overflow->candidates)->toBe(5);
+    expect($overflow->limitReached)->toBeTrue();
+});
+
+test('候補列挙と回収に渡る掃引開始時刻が同一である', function (): void {
+    $stream = fakeRecoveryStream([1, 2]);
+
+    app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: true);
+
+    expect($stream->sweptAtSeen)->not->toBe([]);
+    $first = $stream->sweptAtSeen[0];
+    foreach ($stream->sweptAtSeen as $seen) {
+        expect($seen->equalTo($first))->toBeTrue();
+    }
+});
+
+test('契約違反 (要求より多く返す系列) でも実効上限を超えない', function (): void {
+    // 要求は pageSize だが、系列が 10 件返してくる細工
+    $stream = fakeRecoveryStream(range(1, 10), overReturn: 10);
+
+    $result = app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: true, limitOverride: 3);
+
+    // これは黙って切るだけの防御であり、契約そのものの固定は各系列のテストが担う
+    expect($result->candidates)->toBe(3);
+    expect($stream->recovered)->toHaveCount(3);
+});
diff --git a/tests/Pest.php b/tests/Pest.php
index bf3e957..87db412 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -2,8 +2,11 @@
 
 declare(strict_types=1);
 
+use App\DataTransferObjects\Recovery\StreamSweepResultDto;
 use App\Enums\OrganizationRole;
 use App\Enums\ProjectRole;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
 use App\Models\ApiKey;
 use App\Models\Organization;
 use App\Models\Project;
@@ -12,6 +15,8 @@
 use App\Services\AI\Testing\CannedPromptFakeRegistrar;
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Organization\OrganizationProvisioningService;
+use App\Services\Recovery\StuckWorkRecoverySweeper;
+use App\Services\Recovery\StuckWorkStreamRegistry;
 use App\Services\Storage\Fakes\FakeObjectStore;
 use Carbon\CarbonImmutable;
 use Illuminate\Foundation\Testing\RefreshDatabase;
@@ -185,6 +190,38 @@ function createOrganizationWithOwner(string $name = 'テスト組織', bool $gra
     return [$organization, $owner];
 }
 
+/**
+ * 滞留回収を 1 系列ぶん実行する (実際に回収する指定)。
+ *
+ * 定期実行と同じ経路 (registry → sweeper → stream) を通すので、テストが
+ * 系列ごとの内部実装ではなく**運用で実際に走る形**を叩くことになる。
+ */
+function sweepStuckWorkStream(RecoveryStream $stream): StreamSweepResultDto
+{
+    return app(StuckWorkRecoverySweeper::class)->sweep(
+        app(StuckWorkStreamRegistry::class)->get($stream),
+        apply: true,
+    );
+}
+
+/** 滞留した解析ジョブを回収し、実際に前へ進めた件数を返す */
+function recoverStaleAnalysisJobs(): int
+{
+    return sweepStuckWorkStream(RecoveryStream::AnalysisJob)->count(RecoveryOutcome::Recovered);
+}
+
+/** 滞留したレンダジョブを回収し、実際に前へ進めた件数を返す */
+function recoverStaleRenderJobs(): int
+{
+    return sweepStuckWorkStream(RecoveryStream::RenderJob)->count(RecoveryOutcome::Recovered);
+}
+
+/** 期限切れのチケット予約を解放し、実際に解放した件数を返す */
+function releaseStaleTicketReservations(): int
+{
+    return sweepStuckWorkStream(RecoveryStream::TicketReservation)->count(RecoveryOutcome::Recovered);
+}
+
 /**
  * recent-auth (step-up) を確実に満たす fresh session 値。
  * 窓は config('auth.recent_auth_timeout')(既定 900s)。注入時点の elapsed≈0 で窓に対し十分 fresh。
diff --git a/tests/Support/Recovery/NonRecoveryScheduleEntry.php b/tests/Support/Recovery/NonRecoveryScheduleEntry.php
new file mode 100644
index 0000000..7e7e016
--- /dev/null
+++ b/tests/Support/Recovery/NonRecoveryScheduleEntry.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Recovery;
+
+use App\Enums\Recovery\NonRecoveryScheduleReasonKind;
+use Webmozart\Assert\Assert;
+
+/**
+ * 「滞留回収ではない定期実行」1 件分の申告 (区分 + 30 文字以上の理由)。
+ *
+ * Schedule に載っているのに未分類のコマンドがあると目録 gate が落ちるため、
+ * 6 本目の独自回収を素通しで足すことができない。
+ */
+final readonly class NonRecoveryScheduleEntry
+{
+    /** 理由の最低文字数 */
+    public const int REASON_MIN_LENGTH = 30;
+
+    public function __construct(
+        public string $commandName,
+        public NonRecoveryScheduleReasonKind $kind,
+        public string $reason,
+    ) {
+        Assert::stringNotEmpty($this->commandName);
+        Assert::minLength($this->reason, self::REASON_MIN_LENGTH);
+    }
+}
diff --git a/tests/Support/Recovery/RecoveryStreamEntry.php b/tests/Support/Recovery/RecoveryStreamEntry.php
new file mode 100644
index 0000000..7e83629
--- /dev/null
+++ b/tests/Support/Recovery/RecoveryStreamEntry.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Recovery;
+
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use Webmozart\Assert\Assert;
+
+/**
+ * 滞留回収の系列 1 件分の申告。
+ *
+ * 「何を回収するのか」「1 掃引の上限はいくつか」「どの結果の種類を取りうるのか」を
+ * **人が書いて宣言**し、`StuckWorkRecoveryInventoryTest` が実装・Schedule と突き合わせる。
+ */
+final readonly class RecoveryStreamEntry
+{
+    /** 説明文の最低文字数 (「同上」「N/A」を機械的に弾く) */
+    public const int DESCRIPTION_MIN_LENGTH = 30;
+
+    /**
+     * @param  class-string  $implementation  この系列を実装するクラス
+     * @param  positive-int|null  $sweepItemLimit  1 掃引で扱う件数の上限 (null = 無制限)
+     * @param  list<RecoveryOutcome>  $possibleOutcomes  この系列が取りうる結果の種類
+     */
+    public function __construct(
+        public RecoveryStream $stream,
+        public string $implementation,
+        public ?int $sweepItemLimit,
+        public array $possibleOutcomes,
+        public string $description,
+    ) {
+        Assert::classExists($this->implementation);
+        Assert::minLength($this->description, self::DESCRIPTION_MIN_LENGTH);
+        Assert::notEmpty($this->possibleOutcomes, '取りうる結果の種類を 1 つ以上申告してください');
+        Assert::uniqueValues(array_map(
+            static fn (RecoveryOutcome $outcome): string => $outcome->value,
+            $this->possibleOutcomes,
+        ));
+    }
+}
diff --git a/tests/Support/Recovery/StuckWorkRecoveryInventory.php b/tests/Support/Recovery/StuckWorkRecoveryInventory.php
new file mode 100644
index 0000000..b38107f
--- /dev/null
+++ b/tests/Support/Recovery/StuckWorkRecoveryInventory.php
@@ -0,0 +1,173 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Recovery;
+
+use App\Enums\Recovery\NonRecoveryScheduleReasonKind;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+use App\Services\Recovery\Streams\ExpiredTicketReservationStream;
+use App\Services\Recovery\Streams\StaleAnalysisJobStream;
+use App\Services\Recovery\Streams\StaleRenderJobStream;
+use App\Services\Recovery\Streams\StaleUploadReservationStream;
+use App\Services\Recovery\Streams\StaleWebhookEventStream;
+
+/**
+ * 滞留回収の目録 (単一の source of truth)。
+ *
+ * `StuckWorkRecoveryInventoryTest` が deny-by-default で、
+ * 「registry の系列集合 == RecoveryStream の全 case == 本目録の申告集合」と
+ * 「Schedule に載る全コマンドが回収の入口かここの非回収申告のどちらかに属する」を強制する。
+ */
+final class StuckWorkRecoveryInventory
+{
+    /** 回収コマンドの名前 (系列の指定と --apply を除いた部分) */
+    public const string RECOVERY_COMMAND = 'work:recover-stuck';
+
+    /**
+     * 系列ごとの申告。
+     *
+     * @return array<value-of<RecoveryStream>, RecoveryStreamEntry>
+     */
+    public static function streams(): array
+    {
+        $entries = [
+            new RecoveryStreamEntry(
+                stream: RecoveryStream::AnalysisJob,
+                implementation: StaleAnalysisJobStream::class,
+                sweepItemLimit: null,
+                possibleOutcomes: [RecoveryOutcome::Recovered, RecoveryOutcome::Skipped],
+                description: '投入待ちのまま動き出さない / 動き出したまま進まない AI 解析ジョブを失敗として'
+                    .'確定し、押さえていたチケット予約を解放する',
+            ),
+            new RecoveryStreamEntry(
+                stream: RecoveryStream::RenderJob,
+                implementation: StaleRenderJobStream::class,
+                sweepItemLimit: null,
+                possibleOutcomes: [RecoveryOutcome::Recovered, RecoveryOutcome::Skipped],
+                description: '滞留したレンダジョブを失敗として確定し、編集ロック (manual の rendering) と'
+                    .'チケット予約を解放する。閾値は投入待ちと実行中で分かれている',
+            ),
+            new RecoveryStreamEntry(
+                stream: RecoveryStream::TicketReservation,
+                implementation: ExpiredTicketReservationStream::class,
+                sweepItemLimit: null,
+                possibleOutcomes: [RecoveryOutcome::Recovered, RecoveryOutcome::Skipped],
+                description: '有効期限を過ぎたチケット予約と、消費元が失効した月次 hold を解放して'
+                    .'残高の拘束を解く (放置すると翌期間の残高を侵食する)',
+            ),
+            new RecoveryStreamEntry(
+                stream: RecoveryStream::WebhookEvent,
+                implementation: StaleWebhookEventStream::class,
+                sweepItemLimit: null,
+                possibleOutcomes: [
+                    RecoveryOutcome::Recovered,
+                    RecoveryOutcome::Deferred,
+                    RecoveryOutcome::Escalated,
+                    RecoveryOutcome::Skipped,
+                ],
+                description: '本処理中に落ちて受理済みのまま残った Stripe の通知を再実行する。'
+                    .'再実行してよい種類かは通知の分類が決め、対象外と試行上限は人手へ渡す',
+            ),
+            new RecoveryStreamEntry(
+                stream: RecoveryStream::UploadReservation,
+                implementation: StaleUploadReservationStream::class,
+                sweepItemLimit: 500,
+                possibleOutcomes: [
+                    RecoveryOutcome::Recovered,
+                    RecoveryOutcome::RecoveredWithCleanupFailure,
+                    RecoveryOutcome::Skipped,
+                ],
+                description: '期限切れの撮影アップロード予約を解放して容量の予約枠を戻し、'
+                    .'置かれたまま登録されていないファイルを削除する (削除の失敗は別の件数で数える)',
+            ),
+        ];
+
+        $indexed = [];
+        foreach ($entries as $entry) {
+            $indexed[$entry->stream->value] = $entry;
+        }
+
+        return $indexed;
+    }
+
+    /**
+     * 「滞留回収ではない定期実行」の申告 (コマンド名 => 申告)。
+     *
+     * @return array<string, NonRecoveryScheduleEntry>
+     */
+    public static function nonRecoverySchedules(): array
+    {
+        $entries = [
+            new NonRecoveryScheduleEntry(
+                'billing:reconcile-auto-recharge',
+                NonRecoveryScheduleReasonKind::ExternalReconciliation,
+                'チケット自動購入の未決の支払いを Stripe を真実として 5 分岐で収束させる。'
+                .'DB の状態だけでは行き先が決まらないため滞留の前進とは別の判断が要る',
+            ),
+            new NonRecoveryScheduleEntry(
+                'billing:reconcile-schedules',
+                NonRecoveryScheduleReasonKind::ExternalReconciliation,
+                '契約の予約 (Subscription Schedule) の作りかけを Stripe と突き合わせて直す。'
+                .'こちらの状態を進めるのではなく外部の状態に合わせる処理である',
+            ),
+            new NonRecoveryScheduleEntry(
+                'billing:reconcile-subscription-status',
+                NonRecoveryScheduleReasonKind::ExternalReconciliation,
+                '通知の欠落で固まった契約状態を Stripe を真実として日次で収束させる。'
+                .'金銭 (チケット) には触れず、状態の写しを合わせるだけである',
+            ),
+            new NonRecoveryScheduleEntry(
+                'render:reconcile-outputs',
+                NonRecoveryScheduleReasonKind::ArtifactCleanup,
+                '世代交代済みのレンダ出力を削除ジョブへ再投入する。止まった処理を前へ進めるのではなく'
+                .'不要になった生成物を片付ける処理なので滞留回収には含めない',
+            ),
+            new NonRecoveryScheduleEntry(
+                'billing:send-billing-reminders',
+                NonRecoveryScheduleReasonKind::Notification,
+                '更新予告の送信。業務状態は前へ進めず、通知台帳の重複防止キーで冪等に送るだけである',
+            ),
+            new NonRecoveryScheduleEntry(
+                'billing:detect-orphan-billing-organizations',
+                NonRecoveryScheduleReasonKind::DetectionOnly,
+                'Owner 不在かつ課金中の組織を検知して報告する。状態を 1 バイトも書かないので'
+                .'回収ではなく観測である',
+            ),
+            new NonRecoveryScheduleEntry(
+                'inquiry:purge',
+                NonRecoveryScheduleReasonKind::RetentionSettlement,
+                '保持期限を過ぎた問い合わせ記録の削除。期限の決着であって滞留の前進ではない',
+            ),
+            new NonRecoveryScheduleEntry(
+                'idempotency:prune',
+                NonRecoveryScheduleReasonKind::RetentionSettlement,
+                '保持期間を過ぎた冪等キーの物理削除。期限の決着であって滞留の前進ではない',
+            ),
+            new NonRecoveryScheduleEntry(
+                'account:purge-deletion-requests',
+                NonRecoveryScheduleReasonKind::RetentionSettlement,
+                '猶予期間を過ぎた退会予約の執行。利用者が申し込んだ予定の実行であって回収ではない',
+            ),
+            new NonRecoveryScheduleEntry(
+                'billing:purge-retention-expired',
+                NonRecoveryScheduleReasonKind::RetentionSettlement,
+                '保持期限 (7 年) を過ぎた課金記録の削除と畳み込み。期限の決着であって滞留の前進ではない',
+            ),
+            new NonRecoveryScheduleEntry(
+                'capture:purge-upload-reservations',
+                NonRecoveryScheduleReasonKind::RetentionSettlement,
+                '保持期間を過ぎた解放済み / 登録済みのアップロード予約の物理削除。肥大の防止であり、'
+                .'止まった処理を前へ進める回収とは責務が違うので入口を分けている',
+            ),
+        ];
+
+        $indexed = [];
+        foreach ($entries as $entry) {
+            $indexed[$entry->commandName] = $entry;
+        }
+
+        return $indexed;
+    }
+}
diff --git a/tests/Support/Security/DirectFetchInventory.php b/tests/Support/Security/DirectFetchInventory.php
index 3d7a0ae..b676296 100644
--- a/tests/Support/Security/DirectFetchInventory.php
+++ b/tests/Support/Security/DirectFetchInventory.php
@@ -4,6 +4,7 @@
 
 namespace Tests\Support\Security;
 
+use App\Enums\Security\RecoveryFetchShape;
 use Illuminate\Database\Eloquent\Model;
 use ReflectionClass;
 use SplFileInfo;
@@ -291,23 +292,49 @@ public static function inventory(): array
                 .'掛けると JOIN 先までロックするため、単一テーブルの主キーロックに落としている',
             ),
 
-            // --- 同一メソッド内の走査クエリ由来 (保守処理) ---
-            'Services/Billing/TicketLedgerService.php#releaseStale#TicketReservation.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
-                'id は同一メソッドが status / expires_at で列挙した TicketReservation の主キー。'
-                .'期限切れ予約の解放は全テナント横断の保守処理であり cron から呼ばれる (HTTP 入力を経由しない)',
-            ),
-            'Services/Capture/StaleUploadReservationSweeper.php#sweep#TakeUploadReservation.whereKey:$reservation->id#1' => DirectFetchJustificationEntry::sameMethodQuery(
-                'id は同一メソッドが status / expires_at で列挙した予約行の主キー。孤児オブジェクト回収は'
-                .'全テナント横断の保守処理で cron から呼ばれる。whereKey は CAS 更新の対象行指定に使っている',
-            ),
-            'Services/Manual/AnalysisJobService.php#recoverStale#AnalysisJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
-                'id は同一メソッドが status / 経過時間で列挙した AnalysisJob の主キー。'
-                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
-            ),
-            'Services/Manual/RenderJobService.php#recoverStale#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
-                'id は同一メソッドが status / 経過時間で列挙した RenderJob の主キー。'
-                .'stale ジョブの回復は全テナント横断の保守処理で cron から呼ばれる (HTTP 入力を経由しない)',
+            // --- 滞留回収の候補列挙が返した主キー (aicue:T171 で新設した分類) ---
+            'Services/Manual/AnalysisJobService.php#lockStaleJob#AnalysisJob.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は滞留回収の候補列挙 (staleJobIds) が status / 経過時間で選んだ AnalysisJob の主キー。'
+                .'全テナント横断の保守処理で定期実行から呼ばれ HTTP 入力を経由しない。'
+                .'候補列挙と同じ述語を WHERE に入れて行ロック下で再評価するため誤回収も起きない',
+                entryPoint: 'App\Services\Manual\AnalysisJobService::failStaleJob',
+                stream: 'analysis_job',
+                shape: RecoveryFetchShape::DomainService,
+            ),
+            'Services/Manual/RenderJobService.php#lockStaleJob#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は滞留回収の候補列挙 (staleJobIds) が status / 経過時間で選んだ RenderJob の主キー。'
+                .'全テナント横断の保守処理で定期実行から呼ばれ HTTP 入力を経由しない。'
+                .'投入待ちと実行中で閾値が分かれるが述語は 1 か所に集約してある',
+                entryPoint: 'App\Services\Manual\RenderJobService::failStaleJob',
+                stream: 'render_job',
+                shape: RecoveryFetchShape::DomainService,
+            ),
+            'Services/Billing/TicketLedgerService.php#lockExpiredReservation#TicketReservation.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は滞留回収の候補列挙 (expiredReservationIds) が選んだ TicketReservation の主キー。'
+                .'期限切れ予約の解放は全テナント横断の保守処理で定期実行から呼ばれる。'
+                .'失効した月次 hold の判定式は会計の一部なので台帳サービスの中に閉じている',
+                entryPoint: 'App\Services\Billing\TicketLedgerService::releaseExpiredReservation',
+                stream: 'ticket_reservation',
+                shape: RecoveryFetchShape::DomainService,
+            ),
+            'Services/Billing/StripeWebhookProcessor.php#claimStale#StripeWebhookEvent.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は滞留回収の候補列挙 (staleEventIds) が status / 経過時間で選んだ通知記録の主キー。'
+                .'受理は行ロック下で滞留の述語を再評価するため、待っている間に他の実行が'
+                .'前へ進めた行は 1 行も返らない。HTTP 入力を経由しない保守処理である',
+                entryPoint: 'App\Services\Billing\StripeWebhookProcessor::recoverStuckEvent',
+                stream: 'webhook_event',
+                shape: RecoveryFetchShape::DomainService,
+            ),
+            'Services/Recovery/Streams/StaleUploadReservationStream.php#releaseIfStillStale#TakeUploadReservation.whereKey:$id#1' => DirectFetchJustificationEntry::recoveryCandidate(
+                'id は同じ系列の候補列挙が status / 期限で選んだアップロード予約の主キー。'
+                .'解放とパスの取得を 1 本の行ロックで済ませており、登録処理が勝った行は'
+                .'述語の再評価で 0 行になる (正当なテイクの実体を消さない)',
+                entryPoint: 'App\Services\Recovery\Streams\StaleUploadReservationStream::recover',
+                stream: 'upload_reservation',
+                shape: RecoveryFetchShape::StreamInternal,
             ),
+
+            // --- 同一メソッド内の走査クエリ由来 (保守処理) ---
             'Services/Manual/RenderJobService.php#reconcileOutputs#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery(
                 'id は同一メソッドが output_path 非 NULL で列挙した RenderJob の主キー。'
                 .'世代交代済み出力の整合回復は全テナント横断の保守処理で cron から呼ばれる',
diff --git a/tests/Support/Security/DirectFetchJustificationEntry.php b/tests/Support/Security/DirectFetchJustificationEntry.php
index 0e75bd3..2d440f1 100644
--- a/tests/Support/Security/DirectFetchJustificationEntry.php
+++ b/tests/Support/Security/DirectFetchJustificationEntry.php
@@ -5,6 +5,7 @@
 namespace Tests\Support\Security;
 
 use App\Enums\Security\DirectFetchJustification;
+use App\Enums\Security\RecoveryFetchShape;
 use Webmozart\Assert\Assert;
 
 /**
@@ -54,6 +55,25 @@ public static function internalCaller(string $reason, string $calledBy): self
         ]);
     }
 
+    /**
+     * 滞留回収の候補列挙が返した主キーで行を取り直すエントリ。
+     *
+     * @param  string  $entryPoint  回収の入口 `Class::method` (この本文が当該 private を呼ぶ)
+     * @param  string  $stream  回収の系列キー (registry と回収の目録の両方に実在すること)
+     */
+    public static function recoveryCandidate(
+        string $reason,
+        string $entryPoint,
+        string $stream,
+        RecoveryFetchShape $shape,
+    ): self {
+        return new self(DirectFetchJustification::IdFromRecoveryCandidateEnumeration, $reason, [
+            'entryPoint' => $entryPoint,
+            'stream' => $stream,
+            'recoveryFetchShape' => $shape->value,
+        ]);
+    }
+
     /** @param  'authenticated_user'|'validated_token_claim'|'passport_token_record'  $actorSource */
     public static function authenticatedActor(string $reason, string $actorSource): self
     {
@@ -138,6 +158,21 @@ public function commandSignature(): string
         return $this->require('commandSignature');
     }
 
+    public function entryPoint(): string
+    {
+        return $this->require('entryPoint');
+    }
+
+    public function stream(): string
+    {
+        return $this->require('stream');
+    }
+
+    public function recoveryFetchShape(): RecoveryFetchShape
+    {
+        return RecoveryFetchShape::from($this->require('recoveryFetchShape'));
+    }
+
     public function verifiedBy(): string
     {
         return $this->require('verifiedBy');
diff --git a/tests/Unit/Recovery/RecoveryStreamEnumTest.php b/tests/Unit/Recovery/RecoveryStreamEnumTest.php
new file mode 100644
index 0000000..19e6947
--- /dev/null
+++ b/tests/Unit/Recovery/RecoveryStreamEnumTest.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Recovery\RecoveryStream;
+
+/*
+ * 滞留回収の系列 enum の値契約 (DB に触れない)。
+ */
+
+test('全 case が実行間隔を返す (match の網羅。case 追加時に落ちる)', function (): void {
+    foreach (RecoveryStream::cases() as $stream) {
+        expect($stream->cadenceMinutes())->toBeGreaterThan(0, $stream->value);
+    }
+});
+
+test('実行間隔は 60 の約数である (毎時同じ間隔で回る前提)', function (): void {
+    foreach (RecoveryStream::cases() as $stream) {
+        expect(60 % $stream->cadenceMinutes())->toBe(0,
+            $stream->value.' の実行間隔が 60 の約数でないと、cron の刻み表記が毎時同じ間隔にならない');
+    }
+});
+
+test('多重起動抑止の有効期限は実行間隔の 2 倍である', function (): void {
+    foreach (RecoveryStream::cases() as $stream) {
+        expect($stream->overlapExpiryMinutes())->toBe($stream->cadenceMinutes() * 2, $stream->value);
+    }
+});
+
+test('現行の実行間隔を保存している (5 分 4 本 / 10 分 1 本)', function (): void {
+    expect(RecoveryStream::AnalysisJob->cadenceMinutes())->toBe(5);
+    expect(RecoveryStream::RenderJob->cadenceMinutes())->toBe(5);
+    expect(RecoveryStream::TicketReservation->cadenceMinutes())->toBe(5);
+    expect(RecoveryStream::WebhookEvent->cadenceMinutes())->toBe(5);
+    expect(RecoveryStream::UploadReservation->cadenceMinutes())->toBe(10);
+});
diff --git a/tests/Unit/Recovery/StreamSweepResultDtoTest.php b/tests/Unit/Recovery/StreamSweepResultDtoTest.php
new file mode 100644
index 0000000..220dca5
--- /dev/null
+++ b/tests/Unit/Recovery/StreamSweepResultDtoTest.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Recovery\StreamSweepResultDto;
+use App\Enums\Recovery\RecoveryOutcome;
+use App\Enums\Recovery\RecoveryStream;
+
+test('count() は未登録の結果の種類に 0 を返す', function (): void {
+    $result = new StreamSweepResultDto(
+        stream: RecoveryStream::AnalysisJob,
+        applied: true,
+        candidates: 3,
+        outcomes: [RecoveryOutcome::Recovered->value => 2],
+        failures: 1,
+        limitReached: false,
+    );
+
+    expect($result->count(RecoveryOutcome::Recovered))->toBe(2);
+    expect($result->count(RecoveryOutcome::Skipped))->toBe(0);
+    expect($result->count(RecoveryOutcome::Deferred))->toBe(0);
+});
+
+test('limitReached は構築値をそのまま保持する (打ち切りの有無を推測しない)', function (): void {
+    $reached = new StreamSweepResultDto(
+        stream: RecoveryStream::UploadReservation,
+        applied: false,
+        candidates: 500,
+        outcomes: [],
+        failures: 0,
+        limitReached: true,
+    );
+    $notReached = new StreamSweepResultDto(
+        stream: RecoveryStream::UploadReservation,
+        applied: false,
+        candidates: 500,
+        outcomes: [],
+        failures: 0,
+        limitReached: false,
+    );
+
+    expect($reached->limitReached)->toBeTrue();
+    expect($notReached->limitReached)->toBeFalse();
+});

```

---

## 文書差分 (docs / AGENTS.md)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 91923dd..8f010d1 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -664,3 +664,26 @@ ## ドメイン固有規約
       「本番で意味のある権限差が既に存在する」とは書かない
     - 保証しないもの (撮影者は完成動画を観られない / gate はファイル粒度 / Browser lane は
       DOM 契約のみ 等) は `docs/architecture.md` §完成レンダ成果物の選択と受け取り口 が正本
+14. **滞留回収の単一入口と目録 (T171 / 家系の裁定 AG-083 標準形 v1)**: 止まったまま進まない
+    処理・予約を前へ進める入口は **`work:recover-stuck` ただ 1 本**で、対象は
+    `--stream=<key>` で指定する (`App\Enums\Recovery\RecoveryStream` が系列と実行間隔の正本)。
+    - **候補は主キーだけを返し、回収は主キーと掃引開始時刻しか受け取らない**
+      (`App\Contracts\Recovery\StuckWorkStream`)。行の内容を持ち回れないので、回収側は必ず
+      行を取り直して**候補列挙と同じ述語**を行ロック下で再評価することになる (誤回収の防止)。
+      述語は各ドメインの Service の private に 1 か所だけ置き、系列側へ複製しない
+    - 系列を増やすときは **enum の case / registry / 目録 / Schedule の 4 つを同時に**更新する
+      (`StuckWorkRecoveryInventoryTest` が deny-by-default で集合一致を強制)。
+      Schedule に載る他のコマンドは `NonRecoveryScheduleEntry` + 区分 + 30 文字以上の理由で
+      「回収ではない定期実行」として登録が必須 = **6 本目の独自回収を素通しで足せない**
+    - **既定は実行しない (数えるだけ)**。定期実行は `--apply` を明示する。付け忘れは回収が
+      全面停止しても無音なので、`--apply` / `onOneServer` / `withoutOverlapping` の**有効期限** /
+      `onFailure` の 4 点を上記 gate が機械固定する
+    - 撤去した旧実装 (5 コマンド / 2 クラス / 2 メソッド宣言) の再流入は
+      `RetiredRecoveryReferenceGateTest` が止める。**保証範囲を誇張しない** —
+      変数経由の呼び出し (`$service->recoverStale()`) は字句だけでは受信側クラスを確定できないため
+      対象外である
+    - 監視対象は 5 つ (`errors` / `deferred` / `escalated` / `cleanup-failed` / `limit-reached`)。
+      とくに **`deferred` は `errors` に出ない** (失敗を行に書き戻して次回へ回すため) ので
+      独立した監視対象である。保証しないもの (500 件上限は公平性を保証しない / S3 削除に
+      失敗した孤児は自動では拾えない / 実行しない指定の候補件数は上界にすぎない) は
+      `docs/architecture.md` §滞留回収の共通基盤 が正本
diff --git a/docs/architecture.md b/docs/architecture.md
index 693d7e7..e1db9b4 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -169,10 +169,10 @@ ## 主要 Service (テンプレート同梱)
 | `Manual/VideoManualService` | AI-CUE: 動画マニュアル create/updateMeta/delete/duplicate (created_by サーバ導出・category 保存時再解決。duplicate = 別名保存: 保存済み cuts を新 manual へ複製し takes/成果物/SOP は引き継がない) |
 | `Manual/ScenarioService` | AI-CUE: シナリオ (Cut 群) の document 単位保存 (VideoManual 行ロック → rendering/analyzing・楽観ロック guard → 2 段階 reconcile → version+1) + AI 解析結果の materialize (`materializeIntoLockedManual` = ロック済み前提メソッド)。§シナリオ整合の共有不変条件の準拠実装 |
 | `Manual/SourceDocumentService` | AI-CUE: SOP (SourceDocument) の保存。追記型 immutable (差し替え = 新規行)。専用 route 経路は VideoManual 行ロック + draft/ready guard、MIME は内容 sniff で再判定 (polyglot 対策) |
-| `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / recoverStale = stale 回復 cron 本体) |
+| `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / failStaleJob = 滞留回収の 1 件処理の口 (行ロック下で滞留の述語ごと再評価する)) |
 | `Manual/AnalysisPipeline` | AI-CUE: 解析パイプライン本体 (extract→decompose→generate→terminal tx)。チケット 2 フェーズ (予約冪等キー = analysis_jobs.ticket_reservation_id、materialize + commit + succeeded を単一 tx で原子化)。LLM 出力の有界リトライ (JSON 検証失敗のみ最大 2 回) |
 | `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + **SJIS 誤解釈 (pdfparser が定義済み CJK CMap 非対応のため CP932 を Windows-1252 として decode する) の区間単位復元** (**復元は日本語本文ゲートで拒否される文書にのみ適用する**。既に日本語として読める文書は 1 バイトも変更しない = 正当なテキストの不変性を構造で保証する。区間の採否は CP1252 可逆性 / SJIS-win 妥当性 / 全角日本語が 2 文字以上増える / 区間の過半数が日本語、の 4 条件をすべて満たすこと) + **日本語本文ゲート** (`manual.analysis_min_japanese_ratio` 未満は LLM に渡さず insufficientJapaneseText。評価対象は**正規化後・空白を除いた文字数**に占める日本語文字の比率。**閾値の変更は TODO 起票 + 実測の再提出を必須とする**) + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定)。0 バイトは媒体で弁別する (pdf = unextractable / plain・spreadsheet = tooShort) |
-| `Manual/RenderJobService` | AI-CUE: レンダの状態機械 (trigger = ready→rendering + render 冪等 + 採用テイク/尺/残高 guard / triggerPreview = Organization 行ロックで org 同時 preview 上限を直列化 / failJob = 冪等失敗確定 / completeRenderIntoLockedManual = ロック済み前提メソッド / recoverStale・reconcileOutputs = cron 本体) |
+| `Manual/RenderJobService` | AI-CUE: レンダの状態機械 (trigger = ready→rendering + render 冪等 + 採用テイク/尺/残高 guard / triggerPreview = Organization 行ロックで org 同時 preview 上限を直列化 / failJob = 冪等失敗確定 / completeRenderIntoLockedManual = ロック済み前提メソッド / failStaleJob = 滞留回収の 1 件処理の口 / reconcileOutputs = 出力世代の収束) |
 | `Manual/RenderPipeline` | AI-CUE: レンダパイプライン本体 (startJob→buildManifest→compose→upload→finalize)。チケット 2 フェーズ (予約冪等キー = render_jobs.ticket_reservation_id、complete + commit + succeeded を terminal tx で原子化)。version スナップショット固定 (§10.8-6) |
 | `Manual/CutSequencer` | AI-CUE: カット表示順 (step→配下 point) と表示ラベル (手順N/急所N-M) の導出 (読み取り専用) |
 | `Manual/CurrentRenderArtifact` | AI-CUE: 「いま受け取れるレンダ成果物はどれか」の唯一の選択式 (読み取り専用)。playback / download / 詳細画面 props の 3 消費者が同じ行を指す。保持ポリシーと同じ世代定義 (最新 succeeded の output_path が NULL なら旧世代へフォールバックしない)。§完成レンダ成果物の選択と受け取り口 |
@@ -267,7 +267,7 @@ ### キュー投入の原子性
 旧実装は commit 後に dispatch していたため、その間にプロセスが落ちると
 `RunManualAnalysis` / `RunManualRender` / `DeleteRenderOutputsJob` / `DeleteTakeObjectsJob` /
 Stripe webhook 由来の 2 ジョブが「保存済み・未投入」のまま残った。
-`recoverStale` は**再投入ではなく failJob へ倒す**ため、ユーザーの再実行なしには前進しない。
+滞留回収は**再投入ではなく失敗確定へ倒す**ため、ユーザーの再実行なしには前進しない。
 
 1. **0 件 pin (commit 後ずらしの機構を使わない)** — 次の 6 種は
    `QueueDispatchAtomicityInventoryTest` が deny-by-default で **0 件**に固定する。
@@ -559,8 +559,8 @@ ### AI 解析ジョブの運用契約
   (queue=analysis、retry_after=1680) で流れる。**本番/ステージングの worker プロセス定義・
   デプロイ手順・監視対象に `php artisan queue:work database-analysis --timeout=1620` を
   必須項目として登録する** (`--timeout` は規則 1。§キューのリース期間とワーカー制限時間の規約)
-  (専用 worker が居ないとジョブは滞留する。queued 滞留は `analysis:recover-stale-jobs` cron が
-  30 分で failJob するため、滞留 = 監視で気づける)
+  (専用 worker が居ないとジョブは滞留する。queued 滞留は滞留回収
+  (`work:recover-stuck --stream=analysis_job --apply`) が 30 分で失敗確定するため、滞留 = 監視で気づける)
 - 時間 budget の連鎖 `job timeout (1,560s) < retry_after (1,680s) < 予約 TTL (1,800s) ≤ stale 閾値 (1,800s)`
   は `AnalysisTimeBudgetInvariantTest` が CI 固定する。内訳は
   `deadline D (1,080s = 3 × client timeout) + client timeout C (360s) + finalize 予算 (30s) + 安全余白 (90s)`。
@@ -586,9 +586,9 @@ ### レンダジョブの運用契約
   (queue=render、retry_after=1680) で流れる。**本番/ステージングの worker プロセス定義・
   デプロイ手順・監視対象に `php artisan queue:work database-render --timeout=1620` を
   必須項目として登録する** (`--timeout` は規則 1。§キューのリース期間とワーカー制限時間の規約)
-  (専用 worker が居ないとジョブは滞留する。queued 滞留は `render:recover-stale-jobs` cron が
-  **10 分** (queued 短 SLA。enqueue 時点で編集を止めるため) / running 滞留は **30 分** で
-  failJob するため、滞留 = 監視で気づける)
+  (専用 worker が居ないとジョブは滞留する。queued 滞留は滞留回収
+  (`work:recover-stuck --stream=render_job --apply`) が **10 分** (queued 短 SLA。enqueue 時点で
+  編集を止めるため) / running 滞留は **30 分** で失敗確定するため、滞留 = 監視で気づける)
 - **worker ホスト要件**: ffmpeg / ffprobe バイナリ (`RENDER_FFMPEG_BINARY` /
   `RENDER_FFPROBE_BINARY`) と日本語対応フォント (`RENDER_SUBTITLE_FONT`。既定
   Noto Sans CJK JP) のインストールが前提 (Docker image 要件)。テストは `Process::fake()` で
@@ -951,6 +951,111 @@ ### 保証しないもの (誇張しない)
   subscriptions 行と食い違わない。未知 Price のときだけ据え置かれるが、その回復は本経路の
   責務ではない)。
 
+## 滞留回収の共通基盤 (T171 / 家系の裁定 AG-083 標準形 v1)
+
+止まったまま進まなくなった処理・予約を、原因を問わず「一定時間が過ぎても状態が変わっていない」
+ことだけを手がかりに前へ進める仕組み。**入口は `work:recover-stuck` ただ 1 本**で、
+対象の系列は `--stream=<key>` で指定する (`RecoveryStream` が系列とその実行間隔の正本)。
+
+### 系列の契約 (`App\Contracts\Recovery\StuckWorkStream`)
+
+- `candidateIds()` は**主キーだけ**を昇順で返し、`recover()` は**主キーと掃引開始時刻しか
+  受け取らない**。行の内容を持ち回れないので、回収側は必ず行を取り直して述語を再評価することに
+  なる (候補を集めた後に正常へ進んだものを誤って失敗にする事故が構造的に起きない)
+- 候補列挙と行ロック下の再評価は**同じ 1 つの述語**を使う (各ドメインの Service の private に
+  集約してある)。片方だけ書き換えられると誤回収が再発するため、複製を作らない
+- 競合・条件不成立は例外ではなく `RecoveryOutcome::Skipped` を返す。例外を投げてよいのは本当の
+  不変条件違反だけで、掃引側が報告して次の候補へ進む
+
+### 実効上限とページ送りの違い
+
+- 掃引はページ送り (1 度に 200 件ずつ取り出す) で行う。**これはメモリの上界であって掃引全体の
+  上限ではない**。先頭に居座って毎回例外になる行があっても、カーソルが跨いで前進するので
+  後続に手が届く
+- 実効上限 = `min(--limit, 系列の申告)`。適用箇所は 1 つだけで、系列の実装は上限を知らない。
+  現在 上限を申告しているのは撮影アップロード (500) だけである
+- `limit-reached=yes` は「上限に達し、かつ**未処理の候補が実在する**」ときだけ出す
+  (ちょうど上限件数で候補が尽きた場合は打ち切りではない)
+
+### 実行しない指定 (既定) が数えるもの
+
+`--apply` を付けない実行は `recover()` を 1 度も呼ばず、候補の件数だけを数える。
+**「回収されるはずの件数」は出せない** (webhook の回収は受理そのものが書き込みのため)。
+出力の `candidates` は実際に回収される件数の**上界**にすぎない。
+
+### 結果の種類 (`RecoveryOutcome`。この 5 値がすべて)
+
+| 種類 | 意味 |
+|---|---|
+| `recovered` | 業務状態を前へ進めた |
+| `recovered_with_cleanup_failure` | 前へ進めたが付随する後始末に失敗した (S3 の孤児削除) |
+| `skipped` | 競合・条件不成立で何もしなかった (正常事象。失敗ではない) |
+| `deferred` | 前へ進まなかったが次回の掃引へ残した (webhook の再実行失敗) |
+| `escalated` | 自動回収の対象外へ移し人手へ渡した (webhook の `recovery_pending`) |
+
+### 監視対象 (必須。**5 つを見る**)
+
+- `errors > 0` が続く = 特定の行で回収が失敗し続けている
+- `deferred > 0` が続く = 再実行が失敗し続けている。**`errors` には出ない** —
+  失敗を行に書き戻して次回へ回すため、`errors=0` のまま滞留しうる (独立した監視対象である)
+- `escalated` の件数 = 自動回収の対象外として人手へ渡した件数
+- `cleanup-failed > 0` = S3 の孤児削除に失敗した件数。**手動確認が要る** —
+  行は解放済みなので自動では拾い直せない
+- `limit-reached=yes` が続く = 上限で打ち切っており後続候補が残っている
+
+加えて各系列の Schedule には `onFailure` → `report()` が付いており、回収が止まったことが
+無音にならないようにしてある。
+
+### 多重起動の抑止と、その限界
+
+`onOneServer()` + `withoutOverlapping()` を全系列に揃えてある。**ロックの有効期限は明示する**
+(`RecoveryStream::overlapExpiryMinutes()` = 実行間隔の 2 倍)。Laravel の既定は 24 時間で、
+異常終了でロックが残ると回収が丸 1 日止まったまま無音になるためである。
+**保証の限界を誇張しない**: 有効期限を過ぎるとロックは期限切れとして解けるので、
+正常な実行がその時間を超えて走っている間は同一系列が並行実行されうる。多重起動しても状態が
+壊れないことは各系列の行ロック下の再評価が担保するが、「重複が起きない」とは書かない。
+**想定最大実行時間が有効期限を下回っていること**は運用の監視対象 (実行時間) である。
+
+### 目録 (deny-by-default)
+
+`StuckWorkRecoveryInventoryTest` が「registry の系列集合 == `RecoveryStream` の全 case ==
+目録の申告集合」と「Schedule に載る全コマンドが回収の入口か非回収の申告のどちらかに属する」を
+機械強制する。**`--apply` の付け忘れは無音で回収を全面停止させる**ため、その検査が本 gate の
+主目的である。撤去した旧実装の再流入は `RetiredRecoveryReferenceGateTest` が止める。
+
+### 旧語彙からの対応表 (運用者が旧語彙で探して見つからない状態を作らない)
+
+| 旧 (撤去済みコマンドの出力) | 新 (`work:recover-stuck` の出力) |
+|---|---|
+| `replayed` | `recovered` |
+| `retry-scheduled` | `deferred` |
+| `moved-to-recovery-pending` | `escalated` |
+| `skipped` | `skipped` |
+| `recovered N stale analysis job(s)` / render 側の同種 | `recovered` |
+| `released N stale reservation(s)` / upload reservation 側の同種 | `recovered` |
+
+コマンド名の対応は次のとおり (旧名は 5 本ともコードにも運用の正本にも残っていない):
+解析 → `--stream=analysis_job` / レンダ → `--stream=render_job` /
+チケット予約 → `--stream=ticket_reservation` / Stripe の通知 → `--stream=webhook_event` /
+撮影アップロード → `--stream=upload_reservation`。
+アップロード予約の**保持期間の決着**だけは回収ではないため `capture:purge-upload-reservations`
+(日次) に分けてある。
+
+### 保証しないもの (誇張しない)
+
+- 撮影アップロードの 500 件上限は**公平性を保証しない** (毎回同じ先頭側だけを見る可能性がある。
+  ページ送りが効くのは 1 回の掃引の中だけである)
+- S3 の削除に失敗した孤児オブジェクトは**自動では拾い直せない** (行は解放済みで候補から外れる)。
+  `cleanup-failed` の件数を見て手で確認する
+- 実行しない指定の候補件数は、実際に回収される件数の**上界**にすぎない
+- 目録は申告の集合一致を見るだけで、`recover()` が実際に行ロック下で述語を再評価しているかは
+  検査できない (それは各系列の Feature テストが担う)
+- Schedule の検査は登録内容を見るだけで、**定期実行の仕組みが実際に動いていることは保証しない**
+  (運用側の監視対象)
+- 滞留の閾値は各ドメインの設定 (`config/manual.php` / `config/billing.php` / `config/capture.php`)
+  に置いたままである。回収側の設定へ集約すると、ジョブの制限時間・再試行間隔・予約の有効期限との
+  序列を固定している既存テストと情報源が 2 つに割れるため
+
 ## Stripe webhook の滞留回収
 
 - **状態の意味**: `received` = 受理済み・未終局 (**処理中と次の回収待ちを兼ねる**。
@@ -970,14 +1075,14 @@ ## Stripe webhook の滞留回収
 - **処理対象外の種類**: `HandledStripeWebhookEvent` に無い type は通常経路と同じく
   再実行して `processed` にする (`process()` の `null` arm は構造的に no-op)。
   回収だけ別扱いにして運用ノイズを作らない
-- **監視対象 (必須項目として登録する)**: **`php artisan billing:recover-stale-webhook-events`
+- **監視対象 (必須項目として登録する)**: **`php artisan work:recover-stuck --stream=webhook_event --apply`
   (scheduler で 5 分ごと・`onOneServer()` + `withoutOverlapping()`)**。
   失敗は `onFailure` → `report()` で運用アラート経路に載る。観測点は 3 つ:
   1. `status='received'` かつ `updated_at <= now - 閾値` の件数
      (増え続ける = scheduler か本コマンドが動いていない)
-  2. 本コマンド出力の `retry-scheduled` 件数 (再実行が失敗し続けている)
+  2. 本コマンド出力の `deferred` 件数 (再実行が失敗し続けている)
   3. `status='recovery_pending'` の件数 (理由は `recovery_reason`:
-     `order_sensitive` / `attempts_exhausted`)
+     `order_sensitive` / `attempts_exhausted`)。出力の `escalated` はここへ移した件数である
 - **運用手順**: `recovery_reason` ごとに次の行動が違う。
   `order_sensitive` は Stripe ダッシュボードで現在の契約状態を確認する /
   `attempts_exhausted` は `failure_reason` があれば確認し、ログと Stripe 上の状態と
@@ -1013,7 +1118,7 @@ ## チケットスポット購入 (T007) の運用契約
   決済済み・未付与が確定した場合のみ tinker 等で `TicketLedgerService::grantPurchased()` を
   手動実行する (idempotency_key `purchase:{sessionId}` により再実行しても二重付与しない)。
   併せて `ticket_checkout_sessions` 行を completed 化する。
-  **滞留 (`received` のまま残った) 分は `billing:recover-stale-webhook-events` が回収する**ので、
+  **滞留 (`received` のまま残った) 分は `work:recover-stuck --stream=webhook_event` が回収する**ので、
   手動付与の前にその経路で決着していないかを確認する (§Stripe webhook の滞留回収)
 - **放棄 session の回収**: Stripe Checkout 自体の有効期限 (既定 24h) で Stripe 側が expire し、
   DB 行は checkout 開始時の期限切れ回収 (`status=pending AND expires_at <= now` → expired) で
@@ -1087,7 +1192,7 @@ ## アプリ内通知センター (T008) の運用契約
 - **配信保証は at-most-once** (重複なし・欠落あり得る)。正はジョブ status + 既存ポーリング UI で、
   通知は補助チャネル。terminal commit 直後〜通知 insert 間のプロセス停止の欠落窓 (数 ms) は許容し、
   outbox 台帳は作らない (送達保証が要件化したときに outbox へ移行する)。worker のジョブ実行中
-  停止は `recoverStale` → `failJob` 経由で失敗通知が発火する
+  停止は滞留回収 → 失敗確定の経路で失敗通知が発火する
 - **宛先導出**: ジョブ通知 = `manual.created_by` ∪ `triggered_by` (jobs 列。Auth からの明示代入のみ =
   `MassAssignmentProtectedKeys` 登録済み) を org 所属再確認 + dedup / 招待 = `whereBlind` 一致の
   既存ユーザーのみ (平文 token 非含有) / 残高低下 = org の owner/admin
@@ -1135,11 +1240,14 @@ ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
   プロセス定義・デプロイ手順・監視対象に `php artisan queue:work database-media --timeout=240`
   を必須項目として登録する** (専用 worker が居ないと削除ジョブは滞留する。`--timeout` は
   規則 1。§キューのリース期間とワーカー制限時間の規約)
-- **孤児掃除 cron**: `capture:release-stale-upload-reservations` (10 分毎・onOneServer) が
-  期限切れ pending / stale verifying (updated_at 15 分超過) を released 化して bytes_pending を
-  解放し、PUT 済み未登録の S3 オブジェクトを削除する (`Capture/StaleUploadReservationSweeper`。
-  fresh verifying には触れない = 登録処理の claim 契約と競合しない)。released/completed の
-  retention (30 日) 超過行は物理削除する
+- **孤児掃除の定期実行**: `work:recover-stuck --stream=upload_reservation --apply` (10 分毎・
+  onOneServer) が期限切れ pending / stale verifying (updated_at 15 分超過) を released 化して
+  bytes_pending を解放し、PUT 済み未登録の S3 オブジェクトを削除する
+  (`Recovery/Streams/StaleUploadReservationStream`。fresh verifying には触れない = 登録処理の
+  claim 契約と競合しない)。1 掃引の上限は 500 件 (S3 の入出力を有界にするため。公平性は保証しない)
+- **保持期間の決着は別コマンド**: released/completed の retention (30 日) 超過行の物理削除は
+  `capture:purge-upload-reservations` (日次・onOneServer) が行う。滞留の前進ではなく期限の決着
+  なので回収とは入口を分けている
 - **DL 済み削除不可 (D6)**: 詳細 GET が採用テイクの署名 DL URL と同時に発行する ACK トークン
   (Crypt 封緘・同 TTL) を `POST .../takes/{take}/downloaded` が検証して `takes.downloaded_at` を
   打刻する。非 null のテイクは DELETE 422
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index b645606..d8c97c5 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -711,3 +711,53 @@ ### 関連
   `tests/Architecture/PromptYamlContractTest.php`
 - 設計: `devnotes/20260815-1537-prompt-injection-defense/`
 - 契約の正本: `docs/architecture.md` §LLM プロンプト防御の窓口方式
+
+---
+
+## D17 ✅ 滞留回収の共通基盤を、閾値の置き場所と `recover()` の引数で正典から外す
+
+家系の裁定 AG-083 標準形 v1 (追従元 laravel-claude-template:T076) の共通基盤へ寄せ替えるにあたり、
+**3 点だけ**正典と形を変えた。骨格 (系列の契約 / 走査と作用の分離 / 既定は実行しない入口 /
+deny-by-default の目録 / 撤去済み参照の gate) はそのまま採っている。
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 滞留の閾値の置き場所 | 回収側の設定 (`config/recovery.php` + `RecoveryThresholds`) に集約 | 各ドメインの設定 (`config/manual.php` / `config/billing.php` / `config/capture.php`) に据え置き |
+| `recover()` の引数 | 主キーだけ | 主キー + **掃引開始時刻** |
+| 遡及の下限 (look-back) と自走をやめる上限 (give-up) | 系列ごとに設定で持つ | **持たない** |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **閾値**: 本アプリは「ジョブの制限時間 < 再試行間隔 < 予約の有効期限 ≤ 滞留の閾値」という
+   序列を既存の Architecture テスト 2 本 (`AnalysisTimeBudgetInvariantTest` /
+   `QueuedJobLeaseInventoryTest`) が固定している。回収側の設定へ移すと**序列の情報源が 2 つに
+   割れ**、片方だけ変えても検査が通る窓が開く。閾値はドメインの時間予算の一部である
+2. **掃引開始時刻**: 候補列挙と行ロック下の再評価で現在時刻がずれると、境界ちょうどの行を
+   取りこぼす (列挙では候補、再評価では未超過、の食い違い)。渡すのは**行の内容ではない**ので、
+   正典が狙う「行を取り直して述語を再評価させる」強制は壊れない
+3. **遡及の下限と自走の上限**: 遡及の下限は「古すぎる滞留を永久に回収しない」無音の穴を作る。
+   自走をやめる上限に当たる機構は Stripe の通知側が既に持っている
+   (`attempts >= MAX_PROCESSING_ATTEMPTS` で `recovery_pending` へ移す)。
+   今必要でないものを先回りして作らない (思考原則 2)
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「回収は必ず行を取り直し、候補列挙と同じ述語を行ロック下で再評価してから作用する」
+
+1. `recover()` の引数は**主キーと時刻だけ**で、行・モデル・述語の判定結果は渡さない
+2. 候補列挙と再評価は**同じ 1 つの述語**を共有する (各ドメインの Service の private に集約)
+3. 共通側が持つ上限は「1 掃引で扱う件数」だけで、それは**対象を失敗として確定する条件ではない**
+   (上限に達しても未処理分は終わらせず、次の掃引と人手の判断に委ねる)
+4. 閾値はドメインの設定に置き、序列を固定する既存テストを緑に保つ
+
+### 関連
+
+- 実装: `app/Contracts/Recovery/StuckWorkStream.php` /
+  `app/Services/Recovery/StuckWorkRecoverySweeper.php` /
+  `app/Services/Recovery/StuckWorkStreamRegistry.php` /
+  `app/Console/Commands/Operations/RecoverStuckWorkCommand.php` /
+  `app/Services/Recovery/Streams/`
+- gate: `tests/Architecture/StuckWorkRecoveryInventoryTest.php` /
+  `tests/Architecture/RetiredRecoveryReferenceGateTest.php`
+- 設計: `devnotes/20260815-1538-stuck-job-recovery/`
+- 契約の正本: `docs/architecture.md` §滞留回収の共通基盤

```

---

## テスト結果

- `composer test`: 4967 tests, 4965 passed, 2 skipped, 0 failed (20978 assertions)
- `composer phpstan`: No errors (level 10 / 938 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1501) / `pnpm build`: すべて green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106): すべて green

### 実装時に手で確認した変異 (gate が vacuous でないことの確認)

目録 gate (`StuckWorkRecoveryInventoryTest`):
- Schedule から `--apply` を外す → 赤
- `withoutOverlapping()` を既定 (24 時間) に戻す → 赤
- `onFailure` を外す → 赤
- 未分類のコマンドを Schedule に足す → 赤
- 目録の `sweepItemLimit` の申告値を 500 から 400 に変える → 赤
- 非回収の申告を 1 件消す → 赤

撤去 gate (`RetiredRecoveryReferenceGateTest`):
- 旧コマンド名 / 旧クラス名が残っている状態で赤になることを実測 (実装途中の実行で確認)

主キー同一性クエリの新分類 (`ModelDirectFetchInvariantTest`):
- `entryPoint` を実在しない名前にする → 赤
- 目録外のクラス (sweeper) から `failStaleJob` を呼ぶ → 赤
- StreamInternal 形の private ヘルパ (`releaseIfStillStale`) を別ファイルから呼ぶ → 赤
