# Round 2: 指摘への対応と修正後の詳細設計

## 対応マトリクス (Round 1 の指摘に対する判断)

# 対応マトリクス: design-review Round 1

## [Critical] 施策 4/5: `failJob()` は terminal しか見ておらず、滞留述語を行ロック下で再評価していない
- 判断: 対応する (指摘は正しく、しかも**現行実装の欠陥**を突いている)
- 根拠: 現行 `recoverStale()` は候補列挙後に `failJob()` を呼ぶだけで、
  「queued/running かつ閾値超過」の再評価をしていない。候補の列挙後に worker が進捗を
  書いた running ジョブを、正常に動いているのに失敗確定できる窓がある。
  裁定 AG-083 が「誤回収の防止」として名指ししている事故そのもので、起きてもエラーにならない
- 対応内容: `AnalysisJobService::failStaleJob(int $id, CarbonImmutable $sweptAt): bool` と
  `RenderJobService::failStaleJob(...)` を新設し、`whereKey` + 滞留述語 + `lockForUpdate()` で
  1 行取れたときだけ失敗確定する。`failJob()` は公開のまま残し、両者の本体を private の
  `failLockedJob()` に切り出して 1 つにする (ロック順・通知の at-most-once・予約解放を複製しない)。
  stream からモデルの取り直しは消え、id を渡すだけになった。
  「候補列挙後に進捗が進んだ running ジョブは Skipped になり failed にならない」を
  fail-first の新規テストとして各施策のテスト計画へ追加

## [Warning] 施策 6: `release()` は reserved しか見ず、TTL 超過の再評価をしない
- 判断: 対応する (あわせて概念設計で予定した専用例外を**取りやめ**、設計を単純にした)
- 根拠: 滞留述語を WHERE に入れて再評価すれば、競合した予約は 1 行も返らず false になるので、
  「競合を表す例外型」を新設する必要そのものが消える。作らずに済むものは作らない
- 対応内容: `TicketLedgerService::releaseExpiredReservation(int $id, CarbonImmutable $sweptAt): bool`
  を新設 (whereKey + 滞留述語 + lockForUpdate)。`release()` は公開のまま残し、本体を
  private `releaseLockedReservation()` に切り出して共有。会計の述語
  (`expiredMonthlyHoldCondition`) は台帳サービスの中に閉じたままにし、候補列挙の口
  `expiredReservationIds()` も台帳サービス側に置く (stream へ複製しない)。
  `ReservationNotReleasableException` は**新設しない**

## [Critical] 施策 7/9: 新設メソッド名 `recoverStaleEvent` が撤去 gate の literal と衝突する
- 判断: 対応する (両方の側で直す)
- 対応内容: (1) 新設メソッド名を `recoverStuckEvent()` に変更し、撤去対象の字面を含めない。
  (2) 撤去 gate を素の部分文字列照合にしない — 検出単位を「撤去したコマンド名 (完全一致)」
  「撤去したクラス名 (FQCN と短縮名)」「撤去したメソッドの宣言・呼び出し形
  (`function x(` / `->x(` / `::x(`) をクラス名とセットで判定」の 3 種に限る。
  走査の基盤は既存の `PhpReferenceScanner` / `PhpTokenScan` を使い自前の正規表現を作らない

## [Warning] 施策 3: `withoutOverlapping()` の既定 (24 時間) に依存すると回収が長時間止まる
- 判断: 対応する
- 対応内容: `RecoveryStream::overlapExpiryMinutes()` (= 実行間隔の 2 倍) を新設し、
  Schedule で明示する。目録 gate が「既定ではなくこの値であること」を検査する。
  理由 (異常終了で残ったロックが丸 1 日回収を止める) を enum の docblock に書く

## [Warning] 施策 2: `min()` の結果を PHPStan が `positive-int` に絞れない
- 判断: 対応する
- 対応内容: `Assert::positiveInteger($pageSize)` を置いて型を閉じる

## [Suggestion] 施策 1: `cron('*/N')` の前提として cadence が 60 の約数であること
- 判断: 対応する
- 対応内容: Unit テストに `60 % cadenceMinutes() === 0` を追加し、enum の docblock に前提を書く

## [Suggestion] 施策 3: `format()` の `%d%s` と空文字引数が無意味
- 判断: 対応する
- 対応内容: 削除した (監視語彙を固定する出力に無駄な揺れを残さない)

## [Suggestion] 施策 8: `cleanup-failed` は手動確認の対象だと運用側へ明示する
- 判断: 対応する
- 対応内容: docs の監視項目の説明に「この件数は手動確認の対象」と書く旨をテスト計画・
  リスク欄へ追記

## [Warning] 施策 9/10: `IdSuppliedByInternalCaller` の provenance が弱い
- 判断: 対応する
- 根拠: 滞留述語の再評価を Service へ寄せた結果、主キーのクエリは Service の private ヘルパに
  立つことになり、適用条件 (private + 引数由来 + request accessor 無し + calledBy 実在) を
  そのまま満たす形になった
- 対応内容: 登録先と `calledBy` の対応表を設計へ明記し、根拠文に
  「id は `<Stream>::candidateIds` が返した主キーで HTTP 入力を経由しない」
  「公開の口は掃引からのみ呼ばれ、その対応は目録 gate が stream キー単位で固定する」を
  必ず書くこととした

## [Warning] 施策 10: 監視語彙の変更がコード外の runbook / ログ検索に影響する
- 判断: 対応する
- 対応内容: `docs/architecture.md` に**旧語彙 → 新語彙の対応表**を残す
  (`replayed → recovered` / `retry-scheduled → deferred` /
  `moved-to-recovery-pending → escalated` / 旧 4 コマンドの件数出力 → `recovered`)

## [Suggestion] 施策 2: sweeper の長時間実行・異常終了時の挙動を docs に寄せる
- 判断: 対応する (上の `overlapExpiryMinutes` と同じ対応で満たす)
- 対応内容: ロックの有効期限と「取り残しは最大 2 周期で解ける」ことを
  `docs/architecture.md` §滞留回収の共通基盤 に書く


---

## 修正後の詳細設計 (全文)

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
      (cron の刻み表記の前提) / `overlapExpiryMinutes()` が cadence より大きい
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
| 監視対象 (必須): 各実行の出力と onFailure。
|   - errors > 0 が続く   = 特定の行で回収が失敗し続けている
|   - limit-reached=yes が続く = 上限で打ち切っており後続候補が残っている
|   - escalated の件数    = 自動回収の対象外として人手へ渡した件数 (webhook)
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
  Laravel の既定は 24 時間で、異常終了でロックが残ると回収が丸 1 日止まったまま無音になる
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

    $failed = DB::transaction(function () use ($id, $threshold): bool {
        $locked = $this->lockStaleJob($id, $threshold);
        if ($locked === null) {
            return false; // 述語が成立しない (前進済み / terminal / 進捗が進んだ)
        }

        return $this->failLockedJob($locked, '解析がタイムアウトしました。再実行してください。');
    });

    if ($failed) {
        $this->notifications->notifyAnalysisFinished(AnalysisJob::query()->findOrFail($id));
    }

    return $failed;
}

/** id は回収の候補列挙由来。滞留の述語を WHERE に入れることでロック後の再評価になる */
private function lockStaleJob(int $id, CarbonImmutable $threshold): ?AnalysisJob
{
    return AnalysisJob::query()
        ->whereKey($id)
        ->where(fn (Builder $q) => $q
            ->where(fn (Builder $queued) => $queued
                ->where('status', JobStatus::Queued->value)->where('created_at', '<=', $threshold))
            ->orWhere(fn (Builder $running) => $running
                ->where('status', JobStatus::Running->value)->where('updated_at', '<=', $threshold)))
        ->lockForUpdate()
        ->first();
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
- [x] `findOrFail` の戻り値は `AnalysisJob` に narrowing される (通知の引数型が閉じる)

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
- [ ] 新規: `candidateIds` がページ送りで昇順・`$afterId` より大きい id だけを返す
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
`RenderJobService::failStaleJob(int $id, CarbonImmutable $sweptAt): bool` を新設し、
`whereKey` + 滞留述語 + `lockForUpdate()` で 1 行取れたときだけ
`RenderErrorCode::Timeout` で失敗確定する。**閾値が 2 本に分かれる** (queued=10 分 /
running=30 分) ので、候補列挙と再評価の両方で同じ 2 つの閾値式を使う。
`recover()` は戻り値を `Recovered` / `Skipped` に写像するだけ。`sweepItemLimit()` は `null`。

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
- [ ] 新規: preview と render の両方が同じ閾値規則で候補になることを stream 単位で確認

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
// StaleUploadReservationStream::recover
public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
{
    $cutoff = $sweptAt->subMinutes(config()->integer('capture.stale_verifying_minutes'));

    // ★ 述語の再評価は条件付き UPDATE の WHERE が担う (現行 CAS をそのまま移設)。
    //   **先に UPDATE してから**行を読む — 列挙後に登録処理が completed 化していれば
    //   0 行更新になり、正当な Take の実体を消さない。
    $won = TakeUploadReservation::query()
        ->whereKey($id)
        ->where(/* pending: status + expires_at <= $sweptAt / verifying: status + updated_at < $cutoff */)
        ->update(['status' => TakeUploadReservationStatus::Released]);
    if ($won === 0) {
        return RecoveryOutcome::Skipped; // 登録処理が勝った (completed) = 正当な Take の実体
    }

    $reservation = $this->findReleased($id); // video_path を読むためだけの再取得 (解放後なので安全)
    if ($reservation === null) {
        return RecoveryOutcome::Recovered;   // 解放は済んでいる (行が消えた = 削除対象も無い)
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
  「保証しないもの」として docs に明記し、**`cleanup-failed` の件数は手動確認の対象である**と
  監視項目の説明に書く (件数が出るだけでは運用者が何をすべきか分からない)

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
3. **撤去したメソッドの宣言と呼び出し** (`function <name>(` / `->:<name>(` / `::<name>(` の形):
   `recoverStale` / `releaseStale` / `sweep` は**クラス名とセットの呼び出し形でのみ**判定する
   (`StaleUploadReservationSweeper::sweep` の呼び出しは撤去対象、
   `StuckWorkRecoverySweeper::sweep` は対象外)
- 走査対象は `app/` `routes/` `config/` `tests/` と docs の運用正本。
  **走査対象から外す**: `devnotes/` と `docs/TODO-closed.md` (過去の記録であり書き換えさせない)
- 走査の基盤は既存の `Tests\Support\PhpReferenceScanner` / `PhpTokenScan` を使い、
  自前の正規表現を新設しない (既存の目録群と同じ土台に乗せる)

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

- `tests/Support/Security/DirectFetchInventory.php` — 旧 4 エントリを削除し、移設先で 5 件登録
  (分類も `IdDerivedFromSameMethodQuery` → `IdSuppliedByInternalCaller` へ変わる)
- `docs/architecture.md` — 5 箇所のコマンド名 + 監視対象の語彙 + 新セクション
- `docs/template-divergence.md` — 正典からの意図的な逸脱 3 件
- `AGENTS.md` — ドメイン固有規約に「滞留回収の単一入口と目録」を 1 項追加

### DirectFetchInventory の更新内容

旧 4 エントリ (`TicketLedgerService#releaseStale` / `StaleUploadReservationSweeper#sweep` /
`AnalysisJobService#recoverStale` / `RenderJobService#recoverStale`) は**メソッドごと消える**ので
削除し、移設先で登録し直す。

**分類は `IdDerivedFromSameMethodQuery` から `IdSuppliedByInternalCaller` へ変わる** —
候補の列挙 (`candidateIds`) と主キーでの取り直しが別メソッド・別クラスになるため、
「同一メソッド内の走査クエリ由来」という前提が成り立たなくなる (成り立たない分類を
そのまま流用しない)。

滞留述語の再評価を各ドメインの Service へ寄せた結果 (施策 4-6)、主キーのクエリが立つ場所は
**stream ではなく Service の private ヘルパ**になる。新分類の適用条件
(private + 引数由来 + request accessor 無し + `calledBy` の実在) をそのまま満たす:

| 登録先 (private ヘルパ) | calledBy |
|---|---|
| `AnalysisJobService::lockStaleJob` | `App\Services\Manual\AnalysisJobService::failStaleJob` |
| `RenderJobService::lockStaleJob` | `App\Services\Manual\RenderJobService::failStaleJob` |
| `TicketLedgerService::lockExpiredReservation` | `App\Services\Billing\TicketLedgerService::releaseExpiredReservation` |
| `StripeWebhookProcessor::claimStale` (whereKey 化) | `App\Services\Billing\StripeWebhookProcessor::recoverStuckEvent` |
| `StaleUploadReservationStream::releaseIfStillStale` (CAS) / `findReleased` | `App\Services\Recovery\Streams\StaleUploadReservationStream::recover` |

**根拠文には provenance を明記する** (この分類は機械証明ができないため):
「id は滞留回収の候補列挙 (`<Stream>::candidateIds`) が返した主キーで、HTTP 入力を経由しない。
公開の口 (`failStaleJob` 等) は滞留回収の掃引からのみ呼ばれ、その対応は
`StuckWorkRecoveryInventoryTest` が stream キー単位で固定している」。

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

再レビューしてください。施策ごとの判定 (APPROVE / REQUEST_CHANGES) と全体判定
(APPROVED / CHANGES_REQUESTED) を明示し、未解消の [Critical] / [Warning] があれば
根拠と修正案を日本語で示してください。
