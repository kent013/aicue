<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\TakeRegistrationInput;
use App\DataTransferObjects\Capture\TakeRegistrationResult;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Enums\Capture\CaptureConflictType;
use App\Enums\Capture\TakeUploadReservationStatus;
use App\Enums\Manual\TakeStatus;
use App\Exceptions\Capture\CaptureConflictException;
use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use App\Models\VideoManual;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * テイク登録 (doc/10 §10.8-7 検証専用チケット / §10.8-4 予約確定 / (cut_id, client_take_id) 冪等)。
 *
 * 処理順序は概念設計 D4 の確定契約:
 * 1. チケット開封 (改竄 → 422) + claims.cut_id === route cut (不一致 → 404)
 * 2. 予約行を $cut->uploadReservations() から再取得 (無ければ 404) + claims 全フィールド一致検証
 * 3. 冪等ショートカット (既存 Take × 予約状態の 3 分岐。登録済み動画を誤削除しない)
 * 4. 予約 claim (pending → verifying の原子的 UPDATE。cron と競合しない)
 * 5. HeadObject 三点照合 (size / content_type / ChecksumSHA256)
 * 6. tx: VideoManual 行ロック → sibling shift + Take insert (先頭) + 予約 completed (CAS)
 */
class TakeRegistrationService
{
    public function __construct(
        private readonly UploadTicketCodec $codec,
        private readonly TakeObjectStorage $storage,
    ) {}

    public function register(Project $project, VideoManual $manual, Cut $cut, TakeRegistrationInput $input): TakeRegistrationResult
    {
        // 1. チケット開封 (改竄・期限切れは null → 422)
        $claims = $this->codec->open($input->ticket);
        if ($claims === null) {
            throw ValidationException::withMessages([
                'ticket' => ['アップロードチケットが無効です。再取得してください。'],
            ]);
        }
        if ($claims->cutId !== $cut->id || $claims->clientTakeId !== $input->clientTakeId) {
            // チケットの cut/take 対応が URL と不一致 → 存在を漏らさず 404 (§10.8-7)
            throw (new ModelNotFoundException)->setModel(TakeUploadReservation::class, [$claims->reservationId]);
        }

        // 2. 予約行の再解決は nested relation 経由のみ (cross-cut は 404。チケット値を代入に使わない)
        /** @var TakeUploadReservation $reservation */
        $reservation = $cut->uploadReservations()->whereKey($claims->reservationId)->firstOrFail();
        $this->assertClaimsMatchReservation($claims, $reservation);

        // 3. 冪等ショートカット (D4-1): 既存 Take × 予約の関係で分岐
        /** @var Take|null $existing */
        $existing = $cut->takes()->where('client_take_id', $input->clientTakeId)->first();
        if ($existing !== null) {
            return $this->resolveDuplicate($existing, $reservation);
        }
        // completed なのに Take 不在 = 整合性異常 (D2)。削除せず 409 で調査可能な状態を残す
        if ($reservation->status === TakeUploadReservationStatus::Completed) {
            throw new CaptureConflictException(CaptureConflictType::ReservationInconsistent);
        }

        // 4. 予約 claim (D4-2): 外部 I/O 中に DB ロックを持たない。cron は fresh verifying に触れない
        $claimed = TakeUploadReservation::query()
            ->whereKey($reservation->id)
            ->where('status', TakeUploadReservationStatus::Pending)
            ->where('expires_at', '>', now())
            ->update(['status' => TakeUploadReservationStatus::Verifying]);
        if ($claimed === 0) {
            $reservation->refresh();
            if ($reservation->status === TakeUploadReservationStatus::Verifying) {
                // fresh verifying = 別リクエストが検証中 → 409 (処理中・リトライ可能。422 と区別)
                throw new CaptureConflictException(CaptureConflictType::RegistrationInFlight);
            }

            // released / 期限切れ pending → 422 (upload-url 再取得)
            throw ValidationException::withMessages([
                'ticket' => ['アップロードチケットの有効期限が切れています。再取得してください。'],
            ]);
        }
        // claim は query UPDATE のため in-memory model へ反映する (以降の状態遷移 save を確実に dirty 化)
        $reservation->refresh();

        // 5. HeadObject 三点照合 (D2b/D4-3)
        $head = $this->storage->headObject($reservation->video_path);
        if ($head === null) {
            // PUT 未完了: 期限内なら pending へ戻し再送可能に (Quota 占有は継続)。
            // claim 後に期限超過した予約は released へ倒して 422 (曖昧な再試行を残さない)
            $revertTo = $reservation->expires_at->isFuture()
                ? TakeUploadReservationStatus::Pending
                : TakeUploadReservationStatus::Released;
            $reservation->forceFill(['status' => $revertTo])->save();

            throw ValidationException::withMessages(['ticket' => ['アップロードが完了していません。']]);
        }
        $checksumMismatch = $head->checksumSha256 !== null && $head->checksumSha256 !== $reservation->checksum_sha256;
        if ($head->contentLength !== $reservation->size_bytes
            || ($head->contentType !== null && $head->contentType !== $reservation->content_type)
            || $checksumMismatch) {
            // §10.8-7: 申告と不一致のオブジェクトは削除・拒否 (released 化 = Quota 解放)
            $reservation->forceFill(['status' => TakeUploadReservationStatus::Released])->save();
            $this->storage->delete($reservation->video_path);

            throw ValidationException::withMessages(['ticket' => ['アップロード内容が申告と一致しません。']]);
        }

        // 6. 確定 tx。unique 衝突 (並行二重送信) は tx rollback (CAS も巻き戻る) 後に
        //    冪等分岐へフォールバックする (pgsql は tx 内でエラー後のクエリ続行不可のため tx 外で catch)
        try {
            return $this->finalize($project, $manual, $cut, $input, $reservation);
        } catch (UniqueConstraintViolationException $exception) {
            /** @var Take|null $concurrent */
            $concurrent = $cut->takes()->where('client_take_id', $input->clientTakeId)->first();
            if ($concurrent === null) {
                throw $exception;
            }
            $reservation->refresh();

            return $this->resolveDuplicate($concurrent, $reservation);
        }
    }

    /**
     * 確定 tx: VideoManual 行ロック (sort_order 先頭採番の競合防止。cuts は書かない) +
     * 予約 completed 化の CAS (verifying → completed)。CAS 勝者だけが Take を作成する
     * (敗者 = sweeper が先に released 化していた場合は Take を作らず 422)。
     *
     * 注意: 本 tx は Organization 行ロックを取らないため issue() の Quota 判定とは
     * 直列化されない。過少計上を防ぐ担保は StorageUsageService::occupiedBytes() の
     * pending→used 読み取り順 (同 docblock 参照)。ここで org ロックを追加しないこと
     * (登録確定が Quota 発行と競合してロック待ちになるのを避ける設計)。
     */
    private function finalize(Project $project, VideoManual $manual, Cut $cut, TakeRegistrationInput $input, TakeUploadReservation $reservation): TakeRegistrationResult
    {
        return DB::transaction(function () use ($project, $manual, $cut, $input, $reservation): TakeRegistrationResult {
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();

            // CAS: sweeper が released 化済みなら 0 行更新 → Take を作成しない (オブジェクトは sweeper が削除済み)
            $won = TakeUploadReservation::query()
                ->whereKey($reservation->id)
                ->where('status', TakeUploadReservationStatus::Verifying)
                ->update(['status' => TakeUploadReservationStatus::Completed]);
            if ($won === 0) {
                throw ValidationException::withMessages([
                    'ticket' => ['アップロードチケットの有効期限が切れています。再取得してください。'],
                ]);
            }

            $lockedCut->takes()->increment('sort_order'); // 既存を後ろへ (先頭 = 0。行ロック下で競合なし)
            $take = $lockedCut->takes()->make([
                'client_take_id' => $reservation->client_take_id,
                'video_path' => $reservation->video_path,
                'size_bytes' => $reservation->size_bytes,   // 予約 = HeadObject 照合済み確定値
                'duration_ms' => $input->durationMs,        // クライアント申告 (表示用)
                'captured_at' => $input->capturedAt,
            ]);
            $take->forceFill(['status' => TakeStatus::Ready, 'sort_order' => 0])->save();

            return TakeRegistrationResult::created($take);
        });
    }

    /**
     * 既存 Take 発見時の分岐 (D4-1。登録済み動画を誤削除しない):
     * - 同一 completed 予約からの再送 (video_path 一致): 何も削除せず 200
     * - 別の pending/verifying 予約 (video_path 不一致): 予約 released + その予約のオブジェクトのみ削除して 200
     * - released 済み予約: 何もせず 200 (掃除済みの冪等再送)
     * - completed なのに path 矛盾: 削除せず 409 (整合性異常)
     */
    private function resolveDuplicate(Take $existing, TakeUploadReservation $reservation): TakeRegistrationResult
    {
        if ($reservation->status === TakeUploadReservationStatus::Completed) {
            if ($reservation->video_path === $existing->video_path) {
                return TakeRegistrationResult::existing($existing); // 応答喪失リトライ (何も削除しない)
            }

            throw new CaptureConflictException(CaptureConflictType::ReservationInconsistent);
        }

        if ($reservation->status === TakeUploadReservationStatus::Released) {
            return TakeRegistrationResult::existing($existing); // 掃除済み予約の再送 (オブジェクトは掃除側が削除済み)
        }

        // pending / verifying の別予約による重複: 予約を解放し、その予約のオブジェクトのみ削除
        $reservation->forceFill(['status' => TakeUploadReservationStatus::Released])->save();
        if ($reservation->video_path !== $existing->video_path) {
            $this->storage->delete($reservation->video_path);
        }

        return TakeRegistrationResult::existing($existing);
    }

    /** claims と予約行の全フィールド一致検証 (防御的。個別差分は漏らさず一括 422) */
    private function assertClaimsMatchReservation(UploadTicketClaims $claims, TakeUploadReservation $reservation): void
    {
        $matches = $claims->cutId === $reservation->cut_id
            && $claims->clientTakeId === $reservation->client_take_id
            && $claims->sizeBytes === $reservation->size_bytes
            && $claims->contentType === $reservation->content_type
            && $claims->checksumSha256 === $reservation->checksum_sha256
            && $claims->videoPath === $reservation->video_path
            && $claims->expiresAtTimestamp === $reservation->expires_at->getTimestamp();
        if (! $matches) {
            throw ValidationException::withMessages([
                'ticket' => ['アップロードチケットが無効です。再取得してください。'],
            ]);
        }
    }
}
