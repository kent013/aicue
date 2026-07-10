<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Mail\Sns\SnsSignatureInvalidException;
use App\Services\Mail\Sns\SnsSignatureVerifier;
use App\Services\Mail\Sns\SnsVerificationUnavailableException;
use Aws\Sns\Message;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * SNS 通知の署名検証 middleware。
 *
 * Stripe webhook と同じく無認証 + 署名検証 + CSRF 外で扱う。
 * 暗号検証は SnsSignatureVerifier に委譲し、結果に応じて HTTP ステータスを出し分ける:
 *  - 外側 JSON 不正 / 非 object / list 配列 / envelope 構造不備 → 400
 *  - 署名不正 / 証明書 URL 不正 → 403 (恒久。再送しても直らない)
 *  - 証明書取得の一時障害 → 503 (SNS が再試行する)
 *
 * 検証済 Aws\Sns\Message を request attribute (`sns_message`) に載せ、Controller の二重 decode を避ける。
 */
final class VerifySnsSignature
{
    public function __construct(private readonly SnsSignatureVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        // SNS は Content-Type: text/plain で配信するため raw body を decode する。
        $decoded = json_decode($request->getContent(), true);
        // 外側 JSON 不正 / 非 object / list 配列は不正リクエスト → 400 (アプリ例外化=500 しない)。
        if (! is_array($decoded) || array_is_list($decoded)) {
            return response('invalid payload', Response::HTTP_BAD_REQUEST);
        }
        /** @var array<string, mixed> $decoded */

        // SNS envelope の構造不備 (必須キー欠落・型不正) は AWS 由来でない不正 body → 400。
        // Message constructor が必須キー欠落で InvalidArgumentException を投げる。
        try {
            $message = new Message($decoded);
        } catch (\InvalidArgumentException) {
            return response('invalid payload', Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->verifier->verify($message);
        } catch (SnsSignatureInvalidException) {
            Log::warning('mail.sns.invalid_signature'); // 平文は出さない

            return response('invalid signature', Response::HTTP_FORBIDDEN);
        } catch (SnsVerificationUnavailableException) {
            // 一時障害は 503 (Service Unavailable) → SNS が再試行する。
            // 403 (恒久) に混ぜると正当通知を恒久ドロップ (= 抑止漏れ) するため必ず分離する。
            Log::warning('mail.sns.verification_unavailable');

            return response('verification unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // 検証済 Message を Controller に引き渡す (二重 decode 回避)。
        $request->attributes->set('sns_message', $message);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
