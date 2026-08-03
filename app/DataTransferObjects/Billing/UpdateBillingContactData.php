<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Http\Requests\Billing\UpdateBillingContactRequest;
use App\Support\EmailNormalizer;
use Webmozart\Assert\Assert;

/**
 * P9: 請求先更新の入力 DTO。
 *
 * email は正規化済みの非空文字 (blind index の検索入力と同一正規化)、
 * name は空文字を null に畳んだ任意宛名。
 */
final class UpdateBillingContactData
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $name,
    ) {}

    public static function fromRequest(UpdateBillingContactRequest $request): self
    {
        $email = EmailNormalizer::normalize($request->string('billing_contact_email')->toString());
        Assert::stringNotEmpty($email);

        $rawName = $request->input('billing_contact_name');
        $name = is_string($rawName) && trim($rawName) !== '' ? trim($rawName) : null;

        return new self($email, $name);
    }
}
