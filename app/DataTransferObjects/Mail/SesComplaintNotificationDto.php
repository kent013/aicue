<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Mail;

use Carbon\CarbonImmutable;

/**
 * SES 苦情 (complaint) 通知。complainedRecipients を抑止対象とする。
 */
final readonly class SesComplaintNotificationDto
{
    /**
     * @param  list<string>  $recipients  complainedRecipients の emailAddress 群
     */
    public function __construct(
        public array $recipients,
        public ?string $mailMessageId,
        public ?CarbonImmutable $timestamp,
    ) {}

    /**
     * @param  array<string, mixed>  $message  SES 通知 JSON を decode した配列
     */
    public static function fromMessage(array $message): self
    {
        $complaint = $message['complaint'] ?? [];
        $complaint = is_array($complaint) ? $complaint : [];

        return new self(
            recipients: MailRecipientExtractor::emailAddresses($complaint['complainedRecipients'] ?? null),
            mailMessageId: MailRecipientExtractor::mailMessageId($message),
            timestamp: MailRecipientExtractor::timestamp($complaint['timestamp'] ?? null),
        );
    }
}
