<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Inquiry;

use App\Enums\Inquiry\InquirySource;
use App\Enums\Inquiry\InquiryType;
use App\Http\Requests\StoreInquiryRequest;
use App\Support\EmailNormalizer;
use Webmozart\Assert\Assert;

/**
 * 公開フォームから受け付けた問い合わせの入力値 (正規化済み)。
 */
final readonly class CreateInquiryData
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $companyName,
        public string $message,
        public InquiryType $type,
        public ?InquirySource $source,
    ) {}

    public static function fromRequest(StoreInquiryRequest $request): self
    {
        $name = $request->string('name')->trim()->toString();
        // 保存前に正規化を保証する (blind index / rate limiter / 検索の同値規則を揃える)
        $email = EmailNormalizer::normalize($request->string('email')->toString());
        $message = $request->string('message')->toString();

        $companyName = $request->input('company_name');
        Assert::nullOrString($companyName);
        $companyName = is_string($companyName) && trim($companyName) !== ''
            ? trim($companyName)
            : null;

        $type = InquiryType::from($request->string('type')->toString());
        $source = InquirySource::normalize($request->input('source') !== null
            ? $request->string('source')->toString()
            : null);

        return new self(
            name: $name,
            email: $email,
            companyName: $companyName,
            message: $message,
            type: $type,
            source: $source,
        );
    }
}
