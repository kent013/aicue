<?php

declare(strict_types=1);

namespace App\DataTransferObjects\EnterpriseSso;

use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;

/**
 * 検証を通った ID トークンの claim のうち**本アプリが使うものだけ**。
 *
 * ★`firebase/php-jwt` の戻り値 (`stdClass`) を**信頼済みの型と見なさない**。
 *   各 claim について存在と具体型を再検査してからここへ入れる (`mixed` を DTO の中へ押し込めない)。
 *
 * ## `subject` の境界は 2 層で閉じる
 *
 *  1. **入力側 (ここ)** — バイト長 1〜255 / 制御文字を含まない
 *  2. **DB 側** — `enterprise_identities_subject_octet_length_check` /
 *     `enterprise_identities_subject_no_control_chars_check`
 *
 * ★2 層は**同じ集合**を見る (違う集合を見ていると片方だけ通る値が生まれて二層の意味が消える)。
 *   対象は **C0 制御文字 (U+0001〜U+001F) と DEL (U+007F) だけ**である。
 *   C1 制御文字 (U+0080〜U+009F) と Unicode の書式文字 (U+200B 等) は**許す**
 *   (「制御文字を一切通さない」とは言わない)。
 */
final readonly class VerifiedIdTokenClaims
{
    private function __construct(
        public string $issuer,
        public string $subject,
        public ?string $claimedEmail,
        public ?string $name,
    ) {}

    /**
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public static function of(
        string $issuer,
        string $subject,
        ?string $claimedEmail,
        ?string $name,
        int $maxSubjectLength,
    ): self {
        if (! self::isAcceptableSubject($subject, $maxSubjectLength)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenSubjectInvalid);
        }

        return new self($issuer, $subject, $claimedEmail, $name);
    }

    /** バイト長 1〜上限 / C0 制御文字と DEL を含まないこと。 */
    public static function isAcceptableSubject(string $subject, int $maxSubjectLength): bool
    {
        $length = strlen($subject);

        if ($length < 1 || $length > $maxSubjectLength) {
            return false;
        }

        return preg_match('/[\x01-\x1F\x7F]/', $subject) !== 1;
    }
}
