<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Cut;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use App\Services\Manual\AdoptedReadyTakeCoverage;
use App\Services\Manual\DeterminedCutDuration;
use App\Services\Manual\DeterminedScenarioDuration;
use Illuminate\Support\Collection;
use Webmozart\Assert\Assert;

/**
 * 撮影詳細 (Capture/Show) の manual + cuts + takes ツリー。
 * 採用テイクのみ署名 DL URL と DL 済み ACK トークンを付与する
 * (doc/10 §10.3 / 概念設計 D6。**本メソッドが唯一の設定経路**)。
 *
 * メタ情報 (カテゴリ名 / 作成者名 / 更新日時 / 合計時間) は doc/05 §5.2 の要件。
 * 合計時間は**いま尺が確定している分**の合計であって完成動画の見込み尺ではない
 * (`DeterminedScenarioDuration` が唯一の所在)。
 */
final readonly class CaptureManualDetailData
{
    /**
     * @param  list<CaptureCutData>  $cuts
     * @param  string|null  $updatedAt  ISO 8601 文字列 (Carbon をそのまま props へ渡さない)
     */
    public function __construct(
        public VideoManual $manual,
        public array $cuts,
        public ?string $categoryName,
        public ?string $creatorName,
        public ?string $updatedAt,
        public DeterminedScenarioDuration $duration,
    ) {}

    public static function fromManual(VideoManual $manual, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec): self
    {
        // step 順 → 各 step 直後にその points (ScenarioDocumentData と同じ 1 パス整形)。
        // adoptedTake に加えて **takes も eager load 必須**
        // (無いと CaptureCutData::fromCut() 側でカットごとに再クエリが必要になり、
        // カット数に比例したクエリへ戻る)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->with(['adoptedTake', 'takes'])->orderBy('sort_order')->get();
        /** @var Collection<int, Collection<int, Cut>> $grouped */
        $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
        /** @var Collection<int, Cut> $empty */
        $empty = new Collection;

        $ackExpiry = now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes'))->getTimestamp();
        $cutData = [];
        /** @var list<int|null> $durationsMs 表示順に並べたカットごとの確定尺 */
        $durationsMs = [];
        foreach ($grouped->get(0) ?? $empty as $step) {
            self::appendCut($step, $user, $storage, $codec, $ackExpiry, $cutData, $durationsMs);
            foreach ($grouped->get($step->id) ?? $empty as $point) {
                self::appendCut($point, $user, $storage, $codec, $ackExpiry, $cutData, $durationsMs);
            }
        }

        return new self(
            manual: $manual,
            cuts: $cutData,
            // 表示目的のみ。User.name は CipherSweet PII のため検索には使わない
            // (一覧 CaptureManualSummaryData と同じ形。退会/削除で解決不可なら null)
            categoryName: $manual->category?->name,
            creatorName: $manual->creator?->name,
            updatedAt: $manual->updated_at?->toIso8601String(),
            duration: DeterminedScenarioDuration::fromCutDurations($durationsMs),
        );
    }

    /**
     * 1 カット分を直列化し、同時にそのカットの確定尺を積む。
     *
     * **採用済みかつ ready のテイクの解決式の実装は `AdoptedReadyTakeCoverage` 1 か所だけ**である
     * (署名 URL の発行条件と尺の算出条件を別々に組み立てると、片方だけ変わって乖離する)。
     * ここでは判定を 2 回呼ぶ (`appendCut` で 1 回 / `CaptureCutData::fromCut()` 内部で
     * `adoptedReadyTakeId` を作るのにもう 1 回) が、**実装が割れているわけではない**
     * (2 か所とも同じ 1 メソッドを呼ぶだけであり、式を書き直してはいない)。
     *
     * @param  list<CaptureCutData>  $cutData
     * @param  list<int|null>  $durationsMs
     *
     * @param-out list<CaptureCutData>  $cutData
     * @param-out list<int|null>  $durationsMs
     */
    private static function appendCut(
        Cut $cut,
        User $user,
        TakeObjectStorage $storage,
        UploadTicketCodec $codec,
        int $ackExpiry,
        array &$cutData,
        array &$durationsMs,
    ): void {
        $readyTakeId = AdoptedReadyTakeCoverage::readyTakeId($cut);
        $adopted = $readyTakeId === null ? null : $cut->adoptedTake;
        // 述語が非 null なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
        if ($readyTakeId !== null) {
            Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');
        }

        // CaptureCutData::fromCut() が takes の eager load 済みを自分で確認する
        // (ここでは relation に触れず Cut を渡すだけでよい)
        $cutData[] = $adopted === null
            ? CaptureCutData::fromCut($cut)
            : CaptureCutData::fromCut(
                $cut,
                adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
                adoptedAckToken: $codec->sealAck(new DownloadAckClaims(
                    takeId: $adopted->id,
                    userId: $user->id,
                    expiresAtTimestamp: $ackExpiry,
                )),
            );

        // 尺の式は DeterminedCutDuration が唯一の所在 (ここで組み立て直さない)
        $durationsMs[] = DeterminedCutDuration::milliseconds($cut, $adopted);
    }

    /**
     * @return array{id: int, title: string, status: string, category_name: string|null,
     *   creator_name: string|null, updated_at: string|null, total_duration_ms: int|null,
     *   undetermined_cut_count: int, cuts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->manual->id,
            'title' => $this->manual->title,
            'status' => $this->manual->status->value,
            'category_name' => $this->categoryName,
            'creator_name' => $this->creatorName,
            'updated_at' => $this->updatedAt,
            // 「確定している分の合計」であって完成動画の見込み尺ではない (DeterminedScenarioDuration)
            'total_duration_ms' => $this->duration->totalDurationMs,
            'undetermined_cut_count' => $this->duration->undeterminedCutCount,
            'cuts' => array_map(
                static fn (CaptureCutData $cut): array => $cut->toArray(),
                $this->cuts,
            ),
        ];
    }
}
