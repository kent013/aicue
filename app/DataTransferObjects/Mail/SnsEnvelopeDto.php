<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Mail;

use Webmozart\Assert\Assert;

/**
 * SNS の外側 envelope。
 *
 * SNS の POST body は二重 JSON: envelope の `Message` 文字列の中に SES 通知 JSON が入る。
 * Controller は生配列を直接持ち回らず、本 DTO に parse して扱う。
 */
final readonly class SnsEnvelopeDto
{
    public function __construct(
        public string $type,        // SubscriptionConfirmation / Notification / UnsubscribeConfirmation
        public string $topicArn,
        public ?string $messageId,
        public ?string $token,      // SubscriptionConfirmation 用
        public ?string $message,    // Notification の SES JSON (文字列)
    ) {}

    /**
     * @param  array<string, mixed>  $raw  検証済の SNS envelope 配列
     */
    public static function fromArray(array $raw): self
    {
        $type = $raw['Type'] ?? null;
        $topicArn = $raw['TopicArn'] ?? null;
        Assert::string($type, 'SNS envelope Type must be string');
        Assert::string($topicArn, 'SNS envelope TopicArn must be string');

        return new self(
            type: $type,
            topicArn: $topicArn,
            messageId: self::nullableString($raw['MessageId'] ?? null),
            token: self::nullableString($raw['Token'] ?? null),
            message: self::nullableString($raw['Message'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
