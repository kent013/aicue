<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\DataTransferObjects\Mail\SesBounceNotificationDto;
use App\DataTransferObjects\Mail\SesComplaintNotificationDto;
use App\Enums\EmailSuppressionReason;
use App\Models\EmailSuppression;
use App\Support\EmailHash;
use App\Support\EmailNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * メール送信抑止 (サプレッション) の記録・照合。
 *
 * SES の Permanent バウンス / 苦情を email_suppressions に upsert し、送信前チェックの
 * 照合 (`isSuppressed`) を提供する。平文 email はログに出さない (email_hash のみ)。
 */
final class EmailSuppressionService
{
    /** Permanent バウンスのみ記録。Transient / Undetermined は無視する。 */
    public function recordBounce(SesBounceNotificationDto $dto, ?string $messageId): void
    {
        if (! $dto->isPermanent()) {
            return;
        }
        foreach ($dto->permanentRecipients as $email) {
            $this->suppress($email, EmailSuppressionReason::Bounce, $messageId, $dto->timestamp);
        }
    }

    public function recordComplaint(SesComplaintNotificationDto $dto, ?string $messageId): void
    {
        foreach ($dto->recipients as $email) {
            $this->suppress($email, EmailSuppressionReason::Complaint, $messageId, $dto->timestamp);
        }
    }

    public function isSuppressed(string $email): bool
    {
        return EmailSuppression::query()
            ->where('email', EmailNormalizer::normalize($email))
            ->exists();
    }

    private function suppress(string $email, EmailSuppressionReason $reason, ?string $messageId, ?CarbonImmutable $at): void
    {
        $normalized = EmailNormalizer::normalize($email);
        if ($normalized === '') {
            return;
        }
        $hash = EmailHash::compute($normalized);
        $now = CarbonImmutable::now();

        // 高並行で同一アドレスの通知が同時着しても一意制約例外を出さないよう、
        // updateOrCreate (select→insert の非原子) ではなく upsert (DB 原子 UPSERT) を使う。
        // timestamps は自動補完に頼らず明示する: created_at は初回 insert 値、
        // updated_at / suppressed_at は毎回更新して「最後の通知時刻」を反映する。
        EmailSuppression::query()->upsert(
            [[
                'email' => $normalized,
                'email_hash' => $hash,
                'reason' => $reason->value,
                'provider_message_id' => $messageId,
                'suppressed_at' => $at ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            uniqueBy: ['email'],
            update: ['email_hash', 'reason', 'provider_message_id', 'suppressed_at', 'updated_at'],
        );

        Log::info('mail.suppression.recorded', [
            'email_hash' => $hash, // 平文は出さない
            'reason' => $reason->value,
        ]);
    }
}
