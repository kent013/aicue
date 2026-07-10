<?php

declare(strict_types=1);

use App\Support\Seo\SeoTitle;

/*
 * SeoTitle: ページ固有名 → 完成タイトルの合成単一経路。
 */

beforeEach(function (): void {
    config(['seo.site_name' => 'Acme', 'seo.title_separator' => ' | ']);
});

it('通常の fragment は {fragment} | {site} を合成する', function (): void {
    expect(SeoTitle::compose('ダッシュボード'))->toBe('ダッシュボード | Acme');
});

it('null / 空 / 空白のみはサイト名のみ (先頭セパレータを残さない)', function (): void {
    expect(SeoTitle::compose(null))->toBe('Acme');
    expect(SeoTitle::compose(''))->toBe('Acme');
    expect(SeoTitle::compose('   '))->toBe('Acme');
});

it('fragment がサイト名と一致する場合は二重化しない', function (): void {
    expect(SeoTitle::compose('Acme'))->toBe('Acme');
});

it('fragment 前後の空白を trim する', function (): void {
    expect(SeoTitle::compose('  料金プラン  '))->toBe('料金プラン | Acme');
});
