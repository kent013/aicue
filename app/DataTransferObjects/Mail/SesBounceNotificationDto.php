<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Mail;

use Carbon\CarbonImmutable;

/**
 * SES バウンス通知。
 *
 * `bounceType=Permanent` のときの bouncedRecipients のみを抑止対象として扱う。
 * Transient / Undetermined は再送余地を残すため抑止しない。
 */
final readonly class SesBounceNotificationDto
{
    /**
     * @param  list<string>  $permanentRecipients  Permanent 時の emailAddress 群
     */
    public function __construct(
        public string $bounceType,
        public array $permanentRecipients,
        public ?string $mailMessageId,
        public ?CarbonImmutable $timestamp,
    ) {}

    /**
     * @param  array<string, mixed>  $message  SES 通知 JSON を decode した配列
     */
    public static function fromMessage(array $message): self
    {
        $bounce = $message['bounce'] ?? [];
        $bounce = is_array($bounce) ? $bounce : [];

        $bounceType = $bounce['bounceType'] ?? '';
        $bounceType = is_string($bounceType) ? $bounceType : '';

        $recipients = [];
        if ($bounceType === 'Permanent') {
            $recipients = MailRecipientExtractor::emailAddresses($bounce['bouncedRecipients'] ?? null);
        }

        return new self(
            bounceType: $bounceType,
            permanentRecipients: $recipients,
            mailMessageId: MailRecipientExtractor::mailMessageId($message),
            timestamp: MailRecipientExtractor::timestamp($bounce['timestamp'] ?? null),
        );
    }

    public function isPermanent(): bool
    {
        return $this->bounceType === 'Permanent';
    }
}
