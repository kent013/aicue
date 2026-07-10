<?php

declare(strict_types=1);

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
