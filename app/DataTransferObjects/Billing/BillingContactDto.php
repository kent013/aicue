<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Models\Organization;

/**
 * P9: 請求先連絡先の表示値。
 *
 * `email` / `name` は組織に保存された正本 (CipherSweet 復号済み)。未設定の間は
 * `fallbackEmail` (owner email) が実際の通知宛先になることを UI が示せるようにする。
 *
 * @phpstan-type BillingContactShape array{email: string|null, name: string|null, fallbackEmail: string|null}
 */
final readonly class BillingContactDto
{
    public function __construct(
        public ?string $email,
        public ?string $name,
        /** 未設定時に実際の宛先となる owner email (表示は「未設定時の送信先」用途のみ) */
        public ?string $fallbackEmail,
    ) {}

    public static function fromOrganization(Organization $organization): self
    {
        $email = $organization->billing_contact_email;
        $name = $organization->billing_contact_name;

        return new self(
            email: is_string($email) && $email !== '' ? $email : null,
            name: is_string($name) && $name !== '' ? $name : null,
            fallbackEmail: $organization->billingContactEmail(),
        );
    }

    /**
     * @return BillingContactShape
     */
    public function toArray(): array
    {
        return [
            'email' => $this->email,
            'name' => $this->name,
            'fallbackEmail' => $this->fallbackEmail,
        ];
    }
}
