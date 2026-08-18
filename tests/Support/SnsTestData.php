<?php

declare(strict_types=1);

namespace Tests\Support;

use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use OpenSSLAsymmetricKey;
use Webmozart\Assert\Assert;

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
     * Lambda 形式の封筒 (`SigningCertURL` を持たず `SigningCertUrl` だけを持つ)。
     *
     * ★`notification()` は常に `SigningCertURL` を入れるため、override で `SigningCertUrl` を
     *   足すと**両キー同時**になってしまう。ここで明示的に外す。
     *
     * 既定値を先に入れてから override を適用し、**最後に canonical キーを外す**
     * (先に CERT_URL を代入すると override が無視され、先に unset すると override で
     *  canonical キーを戻せてしまい「lambda キーだけ」という契約が壊れる。
     *  両キーの封筒が要るときは notification() へ SigningCertUrl を足す)。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function lambdaStyleNotification(string $sesMessageJson, array $overrides = []): array
    {
        $payload = self::notification($sesMessageJson);
        $payload['SigningCertUrl'] = self::CERT_URL;
        $payload = array_merge($payload, $overrides);
        unset($payload['SigningCertURL']);

        return $payload;
    }

    /**
     * **PEM として有効なテスト証明書**。
     *
     * 「取得は成功したが署名段で落ちる」ことを示すテストで、`notification()`
     * (Signature がダミー値の封筒) と組み合わせて使う — 証明書が有効でも署名が合わないので
     * 署名段で落ちる。
     * ★`signedNotification()` と**同じ鍵対**なので「署名と一致しない証明書」ではない。
     *   一致しないのは封筒側のダミー署名である。
     */
    public static function certificatePem(): string
    {
        return self::keyPair()['pem'];
    }

    /**
     * 署名検証が**通る**通知と、それに対応する証明書 PEM。
     *
     * 署名対象の文字列は vendor の `MessageValidator::getStringToSign()` から得る
     * (署名仕様を自前で再実装しない)。SignatureVersion 1 は SHA1 が仕様である。
     *
     * @param  array<string, mixed>  $overrides
     * @return array{payload: array<string, mixed>, pem: string}
     */
    public static function signedNotification(string $sesMessageJson, array $overrides = []): array
    {
        $pair = self::keyPair();
        $payload = self::notification($sesMessageJson, $overrides);

        $stringToSign = (new MessageValidator)->getStringToSign(new Message($payload));

        $signature = '';
        Assert::true(
            openssl_sign($stringToSign, $signature, $pair['key'], OPENSSL_ALGO_SHA1),
            'テスト用の署名を作れません',
        );

        $payload['Signature'] = base64_encode($signature);

        return ['payload' => $payload, 'pem' => $pair['pem']];
    }

    /**
     * テスト用の鍵と自己署名証明書 (プロセス内で 1 度だけ作る)。
     *
     * 鍵生成は数百 ms かかるため静的に持ち回す。`openssl_x509_read()` は有効期限を見ないので、
     * 期限そのものはテストの成否に影響しない。
     *
     * @return array{key: OpenSSLAsymmetricKey, pem: string}
     */
    private static function keyPair(): array
    {
        /** @var array{key: OpenSSLAsymmetricKey, pem: string}|null $cached */
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        Assert::notFalse($key, 'テスト用の鍵を生成できません');

        $csr = openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], $key, ['digest_alg' => 'sha256']);
        Assert::notFalse($csr, 'テスト用の証明書要求を生成できません');

        $certificate = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        Assert::notFalse($certificate, 'テスト用の証明書を生成できません');

        $pem = '';
        Assert::true(openssl_x509_export($certificate, $pem), 'テスト用の証明書を PEM へ書き出せません');

        return $cached = ['key' => $key, 'pem' => $pem];
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
