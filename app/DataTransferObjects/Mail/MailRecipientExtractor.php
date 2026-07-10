<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Mail;

use Carbon\CarbonImmutable;

/**
 * SES 通知 JSON から recipient / messageId / timestamp を安全に取り出す共通 helper。
 *
 * Bounce / Complaint DTO で重複する生配列 → 型付き値の変換をここに集約する。
 */
final class MailRecipientExtractor
{
    /**
     * `[{"emailAddress": "a@b.com"}, ...]` 形から emailAddress 文字列の list を取り出す。
     *
     * @return list<string>
     */
    public static function emailAddresses(mixed $recipients): array
    {
        if (! is_array($recipients)) {
            return [];
        }

        $emails = [];
        foreach ($recipients as $recipient) {
            if (! is_array($recipient)) {
                continue;
            }
            $email = $recipient['emailAddress'] ?? null;
            if (is_string($email) && $email !== '') {
                $emails[] = $email;
            }
        }

        return $emails;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public static function mailMessageId(array $message): ?string
    {
        $mail = $message['mail'] ?? null;
        if (! is_array($mail)) {
            return null;
        }
        $messageId = $mail['messageId'] ?? null;

        return is_string($messageId) ? $messageId : null;
    }

    /**
     * ISO8601 timestamp を CarbonImmutable に。欠落 / 不正は null フォールバック (例外を投げない)。
     */
    public static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
