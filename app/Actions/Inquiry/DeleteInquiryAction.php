<?php

declare(strict_types=1);

namespace App\Actions\Inquiry;

use App\Models\Inquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inquiry の単一の削除経路 (Filament 手動削除 / retention purge / 本人削除要請)。
 *
 * - hard delete (SoftDeletes 非使用 = PII を残さない)。model 単位 delete で CipherSweet の
 *   deleting フックを発火させ、共有 blind_indexes テーブルの該当行も同時にクリーンアップする
 *   (query builder 一括 delete はフック非発火 + blind_indexes 残留のため使わない)。
 * - 構造化ログ (inquiry.deleted) に「いつ・なぜ削除したか」を残す。PII は一切載せない
 *   (inquiry_id / status / source / reason のみ)。
 */
final class DeleteInquiryAction
{
    /**
     * @param  'retention'|'subject_request'|'manual'  $reason  削除理由 (保持期限 / 本人要請 / 手動)
     */
    public function __invoke(Inquiry $inquiry, string $reason): void
    {
        $snapshot = [
            'inquiry_id' => $inquiry->id,
            'type' => $inquiry->type->value,
            'status' => $inquiry->status->value,
            'source' => $inquiry->source?->value,
            'reason' => $reason,
            'created_at' => $inquiry->created_at?->toIso8601String(),
        ];

        DB::transaction(static function () use ($inquiry): void {
            $inquiry->delete();
        });

        Log::info('inquiry.deleted', $snapshot);
    }
}
