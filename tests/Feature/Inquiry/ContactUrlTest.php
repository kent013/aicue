<?php

declare(strict_types=1);

use App\Enums\Inquiry\InquirySource;
use App\Services\Marketing\ContactDestinationKind;
use App\Services\Marketing\ContactUrl;

test('未設定なら内部フォーム /contact を返す', function (): void {
    config()->set('services.marketing.contact_url', null);

    $contactUrl = new ContactUrl;
    expect($contactUrl->resolve())->toBe('/contact');
    expect($contactUrl->kind())->toBe(ContactDestinationKind::Internal);
});

test('内部 path / https / mailto は許可される', function (string $configured, ContactDestinationKind $kind): void {
    config()->set('services.marketing.contact_url', $configured);

    $contactUrl = new ContactUrl;
    expect($contactUrl->resolve())->toBe($configured);
    expect($contactUrl->kind())->toBe($kind);
})->with([
    'internal path' => ['/support/contact', ContactDestinationKind::Internal],
    'https' => ['https://forms.example.com/contact', ContactDestinationKind::External],
    'http' => ['http://forms.example.com/contact', ContactDestinationKind::External],
    'mailto' => ['mailto:support@example.com', ContactDestinationKind::Mailto],
]);

test('危険 scheme / protocol-relative は内部フォームへ fail-close する', function (string $configured): void {
    config()->set('services.marketing.contact_url', $configured);

    $contactUrl = new ContactUrl;
    expect($contactUrl->resolve())->toBe('/contact');
    expect($contactUrl->kind())->toBe(ContactDestinationKind::Internal);
})->with([
    'javascript scheme' => ['javascript:alert(1)'],
    'data scheme' => ['data:text/html,x'],
    'protocol-relative' => ['//evil.example.com'],
    'backslash protocol-relative' => ['/\\evil.example.com'],
    'relative path' => ['contact'],
]);

test('Inertia 共有 props に contact (url + kind) が載る', function (): void {
    config()->set('services.marketing.contact_url', 'https://forms.example.com/contact');

    $this->get('/contact')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('contact.url', 'https://forms.example.com/contact')
            ->where('contact.kind', ContactDestinationKind::External->value));
});

test('resolveForSource は内部 path のとき source を安全に付与する', function (?string $configured, string $expected): void {
    config()->set('services.marketing.contact_url', $configured);

    $contactUrl = new ContactUrl;
    expect($contactUrl->resolveForSource(InquirySource::Landing))->toBe($expected);
})->with([
    '既定 (/contact)' => [null, '/contact?source=landing'],
    'query 既存の内部 path' => ['/contact?foo=1', '/contact?foo=1&source=landing'],
    'fragment 付き内部 path' => ['/contact#form', '/contact?source=landing#form'],
    'query + fragment 付き内部 path' => ['/contact?foo=1#form', '/contact?foo=1&source=landing#form'],
]);

test('resolveForSource は外部 URL / mailto には source を付与しない (resolve と同値)', function (string $configured): void {
    config()->set('services.marketing.contact_url', $configured);

    $contactUrl = new ContactUrl;
    expect($contactUrl->resolveForSource(InquirySource::Pricing))->toBe($configured);
})->with([
    'https' => ['https://forms.example.com/contact'],
    'mailto' => ['mailto:support@example.com'],
]);

test('InquirySource::normalize は pricing を受理する (allowlist 追加)', function (): void {
    expect(InquirySource::normalize('pricing'))->toBe(InquirySource::Pricing);
    expect(InquirySource::Pricing->label())->toBe('料金プラン');
});
