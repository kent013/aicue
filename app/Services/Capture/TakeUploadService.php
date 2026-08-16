<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\TakeUploadInput;
use App\DataTransferObjects\Capture\TakeUploadTicketData;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Enums\Capture\TakeUploadReservationStatus;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\QuotaKey;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TakeUploadReservation;
use App\Models\VideoManual;
use App\Services\Billing\QuotaService;
use App\Support\Capture\TakeMaterialClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

            // 素材種別の整合 (受け入れは非対称):
            // - still カット: 画像も動画も受ける (動画は先頭フレーム抽出で従来どおり合成できる)
            // - video / 未指定カット: 動画のみ。画像は 422 で押下時にエラー表示 (禁止事項 8 の通り
            //   ボタンを disabled にはしない)。入口で止めるのは「指示と違う素材で容量を消費させない」ため。
            // 一方でレンダ側は take の実体を優先する (EffectiveMaterialType)。採用後に
            // cut.material_type を video へ戻す編集ができるので、入口検証だけでは不整合を防げない。
            if (TakeMaterialClassifier::fromContentType($input->contentType) === MaterialType::Still
                && $lockedCut->material_type !== MaterialType::Still) {
                throw ValidationException::withMessages([
                    'content_type' => ['このカットは動画で撮影する設定です。静止画を使う場合はシナリオ編集で素材を「静止画」に変更してください。'],
                ]);
            }

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
                TakeMaterialClassifier::extensionFor($input->contentType),
            );

            $reservation = $lockedCut->uploadReservations()->make([
                'client_take_id' => $input->clientTakeId,
                'video_path' => $path,
                'size_bytes' => $input->sizeBytes,
                'content_type' => $input->contentType,
                'checksum_sha256' => $input->checksum->base64,
                'expires_at' => $expiresAt,
            ]);
            // organization_id は保護キー、status は保護状態列のため $fillable 外 (forceFill で代入)。
            // status は**初期状態の明示代入**であり状態遷移ではない (AGENTS.md ドメイン規約 2 の
            // 「直接 UPDATE を書かない」は pending→verifying 以降の CAS の話。ドメイン規約 1 (ii) と
            // 同じ理由で、DB カラム default に依存すると (a) migration default 変更でこの経路の
            // 意味だけが黙って変わり (b) save() 直後の in-memory instance の status が null になる)。
            $reservation->forceFill([
                'organization_id' => $lockedOrg->id,
                'status' => TakeUploadReservationStatus::Pending,
            ])->save();

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
}
