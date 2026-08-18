<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;

/**
 * AWS SDK ベースの SNS 署名検証実装。
 *
 * 署名の暗号検証 (canonical string / SignatureVersion / 証明書検証) は AWS SDK の
 * `MessageValidator` に委譲し、自前で再実装しない。wrapper の責務は 4 点:
 *  1. **SDK が実際に取りに行く URL** を特定し、値オブジェクトで書式検証する
 *     (両キー同時送信は拒否する。下の `effectiveCertUrl()` の docblock 参照)
 *  2. 証明書取得を `SnsCertificateFetcher` へ委譲する (SSRF 検査 / 直列化 / キャッシュ / PEM 確認)
 *  3. SDK が閉じ込めた URL 以外を要求してきたら取りに行かない (fail-closed)
 *  4. **署名検証が通った証明書だけ**をキャッシュへ昇格させる
 *
 * `MessageValidator` は証明書取得を `certClient` へ委譲できる。これを使い
 * **取得失敗 (一時障害) と署名不一致 (恒久) を確実に分ける**: certClient が投げた
 * `SnsVerificationUnavailableException` は `validate()` を素通りして伝播し、
 * `validate()` が投げる `InvalidSnsMessageException` は取得済みの証明書での検証失敗
 * = 署名不一致だけになる。SDK 既定の `file_get_contents` 再取得にも
 * 例外メッセージの判定にも依存しない。
 *
 * ★**certClient は決して false を返さない**。vendor は false を
 *   `InvalidSnsMessageException` に吸収するため、返すと一時障害が 403 に化ける。
 *
 * ★**解放から昇格までの窓**: ロックが包むのは外向き通信ちょうどで、署名検証はその後に走る。
 *   この間に届いた別の要求は同じ証明書をもう一度取りに行きうる。窓の長さは署名検証 1 回ぶん
 *   (取得に比べて無視できる) で、起きても取得が 1 回余分に走るだけである。
 *   ロックを署名検証まで伸ばしても同時外向き通信数の上界は改善せず、
 *   ロック寿命を伸ばす必要が出る (= 障害時に後続が 503 になる時間が延びる) ので伸ばさない。
 *
 * ★**保証しないもの**: 「SDK が検証済み URL 以外を要求する」分岐は現行 vendor
 *   (aws/aws-php-sns-message-validator 1.10.0) では到達しない
 *   (lambda キー単独の封筒は `convertLambdaMessage()` で同じ値が `SigningCertURL` に入るため)。
 *   将来の vendor 変更に対する砦であり、behavioral テストを持てない。
 */
final class AwsSnsSignatureVerifier implements SnsSignatureVerifier
{
    public function __construct(private readonly SnsCertificateFetcher $certificates) {}

    public function verify(Message $message): void
    {
        // 1) SDK が実際に取りに行く URL を特定し、型で書式検証する
        //    (不正は SnsCertificateUrl::fromString が SnsSignatureInvalidException を投げる = 403)。
        $url = SnsCertificateUrl::fromString($this->effectiveCertUrl($message));

        // **新しく取得した** PEM だけをここに載せる (キャッシュから返ったものは載せない)。
        /** @var string|null $fetched */
        $fetched = null;

        $validator = new MessageValidator(
            function (string $requested) use ($url, &$fetched): string {
                // SDK が検証済みの URL 以外を要求したら取りに行かない (最後の砦)。
                if ($requested !== $url->value) {
                    throw new SnsSignatureInvalidException('unexpected SigningCertURL requested');
                }

                $cached = $this->certificates->cached($url);
                if ($cached !== null) {
                    return $cached; // 正常時はここ。ロックも外向き通信も無い。
                }

                $certificate = $this->certificates->fetchSerialized($url);
                if (! $certificate->fromCache) {
                    $fetched = $certificate->pem;
                }

                return $certificate->pem;
            }
        );

        try {
            $validator->validate($message);
        } catch (InvalidSnsMessageException $e) {
            throw new SnsSignatureInvalidException('signature mismatch', 0, $e);
        }

        // ★昇格はここだけ。`validate()` を通ったあとであることが唯一の条件である。
        if (is_string($fetched)) {
            $this->certificates->rememberVerified($url, $fetched);
        }
    }

    /**
     * SDK (`MessageValidator`) が**実際に取得する** 証明書 URL。
     *
     * vendor の `MessageValidator::isLambdaStyle()` は `isset($message['SigningCertUrl'])` を
     * 先に判定し、真なら `convertLambdaMessage()` が `SigningCertUrl` の値で
     * `SigningCertURL` を**上書き**する。したがって `SigningCertURL` を先に読む実装だと、
     * **両キーを同時に送られたときに「検査した URL」と「取得する URL」が食い違い、
     * アプリ側の追加検証 (port 443 固定 / query 禁止 / path 形式 / 中国パーティション排除) を
     * 回避できる** (vendor 自身の検査は host 形式と `.pem` 終端しか見ない)。
     * この上書き順序は aws/aws-php-sns-message-validator 1.10.0 を実読して確認し、
     * `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` の vendor 契約テストが固定する。
     *
     * 対策は 2 段:
     *  1. **両キー同時存在は拒否する** (正当な SNS 通知はどちらか一方しか持たない。
     *     両方あるのは検査を食い違わせる意図しか無い)
     *  2. 単独のときは SDK と同じ実効キー (Lambda キー優先) を返す
     *
     * @throws SnsSignatureInvalidException
     */
    private function effectiveCertUrl(Message $message): string
    {
        $canonical = $message['SigningCertURL'] ?? null;
        $lambda = $message['SigningCertUrl'] ?? null;

        if ($canonical !== null && $lambda !== null) {
            throw new SnsSignatureInvalidException('conflicting SigningCertURL / SigningCertUrl');
        }

        // SDK は Lambda キーがあればそれで上書きするため、同じ優先順を採る。
        $url = $lambda ?? $canonical ?? '';

        return is_string($url) ? $url : '';
    }
}
