<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * SES/SNS 通知テスト用の envelope / SES message ビルダー。
 *
 * Aws\Sns\Message の必須キー (Message/MessageId/Timestamp/TopicArn/Type/Signature/
 * SigningCertURL/SignatureVersion、確認系は SubscribeURL/Token) を満たす最小データを作る。
 */
final class SnsTestData
{
    public const TOPIC_ARN = 'arn:aws:sns:us-east-1:123456789012:ses-events';

    public const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-abc123.pem';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function notification(string $sesMessageJson, array $overrides = []): array
    {
        return array_merge([
            'Type' => 'Notification',
            'MessageId' => 'msg-'.bin2hex(random_bytes(6)),
            'TopicArn' => self::TOPIC_ARN,
            'Message' => $sesMessageJson,
            'Timestamp' => '2026-07-02T00:00:00.000Z',
            'SignatureVersion' => '1',
            'Signature' => 'ZmFrZQ==',
            'SigningCertURL' => self::CERT_URL,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function subscriptionConfirmation(array $overrides = []): array
    {
        return array_merge([
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => 'msg-'.bin2hex(random_bytes(6)),
            'TopicArn' => self::TOPIC_ARN,
            'Message' => 'You have chosen to subscribe.',
            'Timestamp' => '2026-07-02T00:00:00.000Z',
            'SignatureVersion' => '1',
            'Signature' => 'ZmFrZQ==',
            'SigningCertURL' => self::CERT_URL,
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription',
            'Token' => 'token-'.bin2hex(random_bytes(8)),
        ], $overrides);
    }

    /**
     * @param  list<string>  $emails
     */
    public static function bounceMessageJson(string $bounceType, array $emails, ?string $timestamp = '2026-07-02T00:00:00.000Z'): string
    {
        $recipients = array_map(static fn (string $e): array => ['emailAddress' => $e], $emails);

        return (string) json_encode([
            'notificationType' => 'Bounce',
            'bounce' => [
                'bounceType' => $bounceType,
                'bouncedRecipients' => $recipients,
                'timestamp' => $timestamp,
            ],
            'mail' => ['messageId' => 'ses-'.bin2hex(random_bytes(4))],
        ]);
    }

    /**
     * @param  list<string>  $emails
     */
    public static function complaintMessageJson(array $emails): string
    {
        $recipients = array_map(static fn (string $e): array => ['emailAddress' => $e], $emails);

        return (string) json_encode([
            'notificationType' => 'Complaint',
            'complaint' => [
                'complainedRecipients' => $recipients,
                'timestamp' => '2026-07-02T00:00:00.000Z',
            ],
            'mail' => ['messageId' => 'ses-'.bin2hex(random_bytes(4))],
        ]);
    }
}
