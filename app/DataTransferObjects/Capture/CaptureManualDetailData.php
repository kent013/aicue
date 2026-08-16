<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Cut;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use App\Services\Manual\AdoptedReadyTakeCoverage;
use Illuminate\Support\Collection;
use Webmozart\Assert\Assert;

/**
 * 撮影詳細 (Capture/Show) の manual + cuts + takes ツリー。
 * 採用テイクのみ署名 DL URL と DL 済み ACK トークンを付与する
 * (doc/10 §10.3 / 概念設計 D6。**本メソッドが唯一の設定経路**)。
 */
final readonly class CaptureManualDetailData
{
    /**
     * @param  list<CaptureCutData>  $cuts
     */
    public function __construct(
        public VideoManual $manual,
        public array $cuts,
    ) {}

    public static function fromManual(VideoManual $manual, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec): self
    {
        // step 順 → 各 step 直後にその points (ScenarioDocumentData と同じ 1 パス整形)
        // adoptedTake は cut ごとに読むため eager load 必須 (無いと cuts 件数分の N+1 になる)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->get();
        /** @var Collection<int, Collection<int, Cut>> $grouped */
        $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
        /** @var Collection<int, Cut> $empty */
        $empty = new Collection;

        $ackExpiry = now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes'))->getTimestamp();
        $cutData = [];
        foreach ($grouped->get(0) ?? $empty as $step) {
            $cutData[] = self::cutWithAdoptedUrls($step, $user, $storage, $codec, $ackExpiry);
            foreach ($grouped->get($step->id) ?? $empty as $point) {
                $cutData[] = self::cutWithAdoptedUrls($point, $user, $storage, $codec, $ackExpiry);
            }
        }

        return new self($manual, $cutData);
    }

    /**
     * 使用できる採用テイクがあれば署名 DL URL + ACK トークン (DL URL と同 TTL) を発行して cut を直列化。
     *
     * 発行条件は AdoptedReadyTakeCoverage が唯一の所在である。非 ready の採用テイクへ
     * 署名 URL / ACK を出さない = `capture.takes.playback` が非 ready を 404 にしている
     * (状態秘匿) のと同じゲートに揃える (RenderPipeline::clipSpecFor と同じ書き方)。
     */
    private static function cutWithAdoptedUrls(Cut $cut, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec, int $ackExpiry): CaptureCutData
    {
        if (AdoptedReadyTakeCoverage::readyTakeId($cut) === null) {
            return CaptureCutData::fromCut($cut);
        }

        // 述語が非 null なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
        $adopted = $cut->adoptedTake;
        Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');

        return CaptureCutData::fromCut(
            $cut,
            adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
            adoptedAckToken: $codec->sealAck(new DownloadAckClaims(
                takeId: $adopted->id,
                userId: $user->id,
                expiresAtTimestamp: $ackExpiry,
            )),
        );
    }

    /**
     * @return array{id: int, title: string, status: string, cuts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->manual->id,
            'title' => $this->manual->title,
            'status' => $this->manual->status->value,
            'cuts' => array_map(
                static fn (CaptureCutData $cut): array => $cut->toArray(),
                $this->cuts,
            ),
        ];
    }
}
