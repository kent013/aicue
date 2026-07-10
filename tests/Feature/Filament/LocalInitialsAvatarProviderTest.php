<?php

declare(strict_types=1);

use App\Filament\Support\LocalInitialsAvatarProvider;
use App\Models\AdminUser;
use Filament\Facades\Filament;

/*
 * admin アバターを外部 ui-avatars.com から取得せず、ローカル initials を data URI で返す。
 */

function decodeAvatarSvg(string $dataUri): string
{
    expect($dataUri)->toStartWith('data:image/svg+xml;base64,');
    $b64 = substr($dataUri, strlen('data:image/svg+xml;base64,'));
    $svg = base64_decode($b64, true);
    expect($svg)->toBeString();

    return (string) $svg;
}

it('returns a local data-URI SVG avatar without contacting ui-avatars.com', function (): void {
    $admin = AdminUser::factory()->create(['name' => 'Taro Yamada']);

    $url = (new LocalInitialsAvatarProvider)->get($admin);

    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and($url)->not->toContain('ui-avatars.com')
        ->and($url)->not->toContain('http');

    $svg = decodeAvatarSvg($url);
    // initials は氏名先頭 2 文字 (大文字化)。
    expect($svg)->toContain('>TA<');
});

it('escapes the <text> node content so no raw XML metacharacters are injected', function (): void {
    // 記号混じり氏名でも \p{L}\p{N} のみ残り、<text> 内に生の < > & " が出ない。
    $admin = AdminUser::factory()->create(['name' => '<b>&"x']);

    $svg = decodeAvatarSvg((new LocalInitialsAvatarProvider)->get($admin));

    // <text>...</text> の中身だけを取り出して危険文字非混入を検証 (<svg>/<text> タグ自体には反応しない)。
    preg_match('/<text[^>]*>(.*?)<\/text>/s', $svg, $m);
    $textContent = $m[1] ?? '';
    expect($textContent)->not->toContain('<')
        ->and($textContent)->not->toContain('>')
        ->and($textContent)->not->toContain('&')
        ->and($textContent)->not->toContain('"');
});

it('builds initials from a Japanese name without romanizing (kana not converted)', function (): void {
    $admin = AdminUser::factory()->create(['name' => '管理 太郎']);

    $svg = decodeAvatarSvg((new LocalInitialsAvatarProvider)->get($admin));

    // 空白除去後の先頭 2 文字 = 「管理」(カナ化はしない)。
    expect($svg)->toContain('>管理<');
});

it('falls back to ? for a name with no letters/digits', function (): void {
    $admin = AdminUser::factory()->create(['name' => '----']);

    $svg = decodeAvatarSvg((new LocalInitialsAvatarProvider)->get($admin));

    expect($svg)->toContain('>?<');
});

it('registers the local provider as the admin panel default (no ui-avatars egress)', function (): void {
    expect(Filament::getPanel('admin')->getDefaultAvatarProvider())
        ->toBe(LocalInitialsAvatarProvider::class);
});

it('resolves the admin user avatar through the panel to a local data URI (no ui-avatars egress)', function (): void {
    // Filament::getUserAvatarUrl は app(getDefaultAvatarProvider())->get($user) で解決する end-to-end 経路。
    // パネル設定 → LocalInitialsAvatarProvider → data URI を通しで固定し、外部 ui-avatars.com 送出が無いことを保証する。
    $admin = AdminUser::factory()->create(['name' => 'Ops Admin']);

    Filament::setCurrentPanel('admin');
    $url = Filament::getUserAvatarUrl($admin);

    expect($url)->toStartWith('data:image/svg+xml;base64,')
        ->and($url)->not->toContain('ui-avatars.com');
});
