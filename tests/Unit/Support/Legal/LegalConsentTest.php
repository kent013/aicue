<?php

declare(strict_types=1);

use App\Support\Legal\LegalConsent;

// 同意バージョンの単一解決点。空版で証跡を書かせないための fail-fast がここに集約されている。
// (呼び出し元の固定は tests/Architecture/LegalConsentVersionSingleSourceTest.php が担当)

it('config の同意バージョンをそのまま返す', function (): void {
    config(['legal.consent_version' => '2026-09-01']);

    expect(LegalConsent::version())->toBe('2026-09-01');
});

it('同意バージョンが空文字なら例外を投げる (空版の証跡を書かせない)', function (): void {
    config(['legal.consent_version' => '']);

    expect(fn (): string => LegalConsent::version())
        ->toThrow(InvalidArgumentException::class, 'legal.consent_version must be configured');
});

it('同意バージョンが未設定なら例外を投げる', function (): void {
    config(['legal.consent_version' => null]);

    // config()->string() が先に落とす (webmozart Assert と同じ InvalidArgumentException)
    expect(fn (): string => LegalConsent::version())
        ->toThrow(InvalidArgumentException::class);
});
