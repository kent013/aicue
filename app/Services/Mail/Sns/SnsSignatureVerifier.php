<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Aws\Sns\Message;

/**
 * SNS 通知の署名検証。
 *
 * 暗号検証そのものは AWS SDK の MessageValidator に委譲し、本 interface の実装は
 * 「署名不正 (恒久)」と「検証不能 (一時障害)」を 2 種の例外に正規化する責務を持つ。
 * middleware はこの 2 例外だけを見て HTTP ステータスを出し分ける。
 */
interface SnsSignatureVerifier
{
    /**
     * @throws SnsSignatureInvalidException 署名不正 / 証明書 URL 不正 (恒久 → 403)
     * @throws SnsVerificationUnavailableException 証明書取得失敗等の一時障害 (→ 503 再試行)
     */
    public function verify(Message $message): void;
}
