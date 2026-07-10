<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\CaptureManualDetailData;
use App\DataTransferObjects\Capture\CaptureSyncInput;
use App\DataTransferObjects\Capture\CaptureSyncResultData;
use App\DataTransferObjects\Capture\ClientTakeFingerprint;
use App\Models\Cut;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 一括同期 (doc/10 §10.3 / 概念設計 D8)。**読み取り専用** — 本エンドポイントは何も書かない。
 * payload の cut/client_take_id は照合専用: manual の relation 集合と突き合わせ、
 * (a) manual に属さない cut 参照 → 404 (存在を漏らさない)
 * (b) サーバ未登録の fingerprint → pending_upload として返す (クライアントが D2-D4 経路で送信)
 * (c) 登録済み fingerprint → 現在のサーバ状態を返す (冪等: 何度呼んでも同じ)
 */
class CaptureSyncService
{
    public function __construct(
        private readonly TakeObjectStorage $storage,
        private readonly UploadTicketCodec $codec,
    ) {}

    public function reconcile(User $user, VideoManual $manual, CaptureSyncInput $input): CaptureSyncResultData
    {
        $cutIds = $manual->cuts()->pluck('id');
        foreach ($input->fingerprints as $fingerprint) {
            if (! $cutIds->contains($fingerprint->cutId)) {
                // manual に属さない cut 参照は照合不一致 = 404 (tenant キー不信。代入には使わない)
                throw (new ModelNotFoundException)->setModel(Cut::class, [$fingerprint->cutId]);
            }
        }

        $existing = Take::query()
            ->whereIn('cut_id', $cutIds)
            ->get(['id', 'cut_id', 'client_take_id'])
            ->keyBy(static fn (Take $take): string => $take->cut_id.':'.$take->client_take_id);

        $pendingUpload = array_values(array_filter(
            $input->fingerprints,
            static fn (ClientTakeFingerprint $fingerprint): bool => ! $existing->has($fingerprint->cutId.':'.$fingerprint->clientTakeId),
        ));

        return new CaptureSyncResultData(
            pendingUpload: $pendingUpload, // 新規テイクのみ送信 (doc/05 §5.3)
            manual: CaptureManualDetailData::fromManual($manual, $user, $this->storage, $this->codec),
        );
    }
}
