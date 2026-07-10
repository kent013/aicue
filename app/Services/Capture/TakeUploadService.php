<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\TakeUploadInput;
use App\DataTransferObjects\Capture\TakeUploadTicketData;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\QuotaKey;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TakeUploadReservation;
use App\Models\VideoManual;
use App\Services\Billing\QuotaService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * presigned PUT URL + 署名チケット発行 (doc/10 §10.3 / §10.8-4,-7 / 概念設計 D2,D3)。
 * 直列化点: Organization 行ロック (check→reserve の TOCTOU 防止)。
 */
class TakeUploadService
{
    public function __construct(
        private readonly QuotaService $quota,
        private readonly StorageUsageService $usage,
        private readonly TakeObjectStorage $storage,
        private readonly UploadTicketCodec $codec,
    ) {}

    public function issue(Organization $organization, Project $project, VideoManual $manual, Cut $cut, TakeUploadInput $input): TakeUploadTicketData
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(config()->integer('capture.upload_ticket_ttl_minutes'));

        $reservation = DB::transaction(function () use ($organization, $project, $manual, $cut, $input, $expiresAt): TakeUploadReservation {
            /** @var Organization $lockedOrg */
            $lockedOrg = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み経路で再解決 (cross は 404)。manual 状態 guard も同時に行う
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->firstOrFail();
            if (! in_array($lockedManual->status, [VideoManualStatus::Ready, VideoManualStatus::Published], true)) {
                throw ValidationException::withMessages([
                    'manual' => ['このマニュアルは現在撮影できません（解析中・書き出し中）。'],
                ]);
            }
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();

            // Quota: bytes_used + bytes_pending + size が上限を超えるなら 422 (QuotaExceededException)。
            // 加算合成は occupiedBytes() (overflow 安全) に委譲し、呼び出し側で生加算しない。
            // occupiedBytes() は pending→used の読み取り順が並行制御上の不変条件
            // (finalize は org ロックを取らないため。StorageUsageService の docblock 参照)
            $this->quota->checkAddition(
                $lockedOrg,
                QuotaKey::MaxStorageBytes,
                current: $this->usage->occupiedBytes($lockedOrg),
                addition: $input->sizeBytes,
            );

            // S3 キーはサーバ生成 (SourceDocumentService と同じ規約)
            $path = sprintf(
                'projects/%d/manuals/%d/cuts/%d/takes/%s.%s',
                $lockedManual->project_id,
                $lockedManual->id,
                $lockedCut->id,
                (string) Str::ulid(),
                self::extensionFor($input->contentType),
            );

            $reservation = $lockedCut->uploadReservations()->make([
                'client_take_id' => $input->clientTakeId,
                'video_path' => $path,
                'size_bytes' => $input->sizeBytes,
                'content_type' => $input->contentType,
                'checksum_sha256' => $input->checksum->base64,
                'expires_at' => $expiresAt,
            ]);
            $reservation->forceFill(['organization_id' => $lockedOrg->id])->save();

            return $reservation;
        });

        // presign は外部 I/O のため tx 外 (ロック保持時間を最小化)。checksum を署名条件に含める (D2b)
        $presigned = $this->storage->presignUpload(
            $reservation->video_path,
            $input->contentType,
            $input->sizeBytes,
            $input->checksum->base64,
            $expiresAt,
        );
        $ticket = $this->codec->seal(UploadTicketClaims::fromReservation($reservation));

        return new TakeUploadTicketData($presigned, $ticket, $reservation->client_take_id);
    }

    /** 許可 Content-Type → S3 キー拡張子 (config capture.allowed_video_content_types と対で保守) */
    private static function extensionFor(string $contentType): string
    {
        $extension = match ($contentType) {
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => null,
        };
        Assert::notNull($extension, "未許可の Content-Type です: {$contentType}");

        return $extension;
    }
}
