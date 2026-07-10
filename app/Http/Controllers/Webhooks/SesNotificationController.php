<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\DataTransferObjects\Mail\SesBounceNotificationDto;
use App\DataTransferObjects\Mail\SesComplaintNotificationDto;
use App\DataTransferObjects\Mail\SnsEnvelopeDto;
use App\Http\Controllers\Controller;
use App\Services\Mail\EmailSuppressionService;
use Aws\Sns\Message;
use Aws\Sns\SnsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SES/SNS 通知 (バウンス / 苦情) 受信エンドポイント。
 *
 * 署名検証は VerifySnsSignature middleware が担い、本 Controller は parse + 委譲に限定する。
 * レスポンスは webhook ack のため plain 200/403 (参照系 API でないため JsonResource 不要)。
 */
final class SesNotificationController extends Controller
{
    public function __construct(
        private readonly SnsClient $sns,
        private readonly EmailSuppressionService $suppressions,
    ) {}

    public function __invoke(Request $request): Response
    {
        // middleware (VerifySnsSignature) が検証済 Aws\Sns\Message を attribute に載せている。
        // middleware bypass 時の型安全性のため instanceof を確認し、欠落時は 400。
        $message = $request->attributes->get('sns_message');
        if (! $message instanceof Message) {
            return response('invalid payload', Response::HTTP_BAD_REQUEST);
        }
        /** @var array<string, mixed> $raw */
        $raw = $message->toArray();
        $envelope = SnsEnvelopeDto::fromArray($raw);

        // TopicArn allowlist 照合 (config 業務判定)。未許可 topic は購読も記録もしない。
        // allowlist が空のときは全通知を拒否する (fail-closed)。
        /** @var list<string> $allowed */
        $allowed = config('services.ses.sns_topic_arns', []);
        if (! in_array($envelope->topicArn, $allowed, true)) {
            return response('forbidden topic', Response::HTTP_FORBIDDEN);
        }

        return match ($envelope->type) {
            'SubscriptionConfirmation' => $this->confirm($envelope),
            'Notification' => $this->notification($envelope),
            default => response('', Response::HTTP_OK), // UnsubscribeConfirmation 等はログのみ
        };
    }

    private function confirm(SnsEnvelopeDto $envelope): Response
    {
        // Token 欠落は不正 payload → 400 (Assert で 500 にしない)。
        if ($envelope->token === null) {
            return response('invalid payload', Response::HTTP_BAD_REQUEST);
        }

        // SubscribeURL の直 GET は行わない (SSRF 回避)。署名検証通過後、SDK で確立する。
        $this->sns->confirmSubscription([
            'TopicArn' => $envelope->topicArn,
            'Token' => $envelope->token,
        ]);

        return response('', Response::HTTP_OK);
    }

    private function notification(SnsEnvelopeDto $envelope): Response
    {
        // Message 欠落は不正 payload → 400 (Assert で 500 にしない)。
        if ($envelope->message === null) {
            return response('invalid payload', Response::HTTP_BAD_REQUEST);
        }

        // 署名検証済 envelope の内側 SES JSON が壊れている = 異常だが、再送しても直らない。
        // 200 で破棄 + 警告ログ (リトライ嵐の回避)。
        try {
            $decoded = json_decode($envelope->message, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        // JSON は妥当でも object でない (scalar / list) ケースも破棄する
        // (scalar への配列アクセスは TypeError になるため)。
        if (! is_array($decoded) || array_is_list($decoded)) {
            Log::warning('mail.sns.malformed_inner_message', ['sns_message_id' => $envelope->messageId]);

            return response('', Response::HTTP_OK);
        }
        /** @var array<string, mixed> $decoded */
        $type = $decoded['notificationType'] ?? $decoded['eventType'] ?? null;

        match ($type) {
            'Bounce' => $this->suppressions->recordBounce(
                SesBounceNotificationDto::fromMessage($decoded),
                $envelope->messageId,
            ),
            'Complaint' => $this->suppressions->recordComplaint(
                SesComplaintNotificationDto::fromMessage($decoded),
                $envelope->messageId,
            ),
            default => null, // Delivery 等は無視
        };

        return response('', Response::HTTP_OK);
    }
}
