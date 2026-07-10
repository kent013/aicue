<?php

declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Yaml\Yaml;

/**
 * メールテーマ (template.css) の同期契約。
 *
 * メール HTML は tokens.css / Tailwind を参照できず hex 直書きになるため、テーマ CSS の色は
 * DESIGN.md front matter (canonical token 定義) との機械照合で drift を検出する。
 *
 * テンプレート初期状態の template.css は Laravel 標準 default.css のコピーであり、
 * DESIGN.md token とは一致しない。よって色 parity の本検査は skeleton (skip) で同梱する。
 * アプリでテーマを定義したら (template.css の色を DESIGN.md token へ差し替えたら)
 * skip を外して有効化すること (docs/ses-mail-runbook.md 参照)。
 */

/**
 * DESIGN.md の YAML front matter から colors を厳密パースして返す。
 *
 * @return array<string, string>
 */
function mailThemeDesignColors(): array
{
    $content = file_get_contents(base_path('DESIGN.md'));
    expect($content)->toBeString();
    assert(is_string($content));

    expect(preg_match('/\A---\R(.*?)\R---\R/s', $content, $matches))->toBe(1);

    $front = Yaml::parse($matches[1]);
    expect($front)->toBeArray()->toHaveKey('colors');
    assert(is_array($front));
    expect($front['colors'])->toBeArray();
    assert(is_array($front['colors']));

    /** @var array<string, string> $colors */
    $colors = $front['colors'];

    // DESIGN.md 側のフォーマット崩れ検出: 全 token 値が #RRGGBB であること
    foreach ($colors as $value) {
        expect($value)->toMatch('/\A#[0-9A-Fa-f]{6}\z/');
    }

    return $colors;
}

/**
 * ファイル中の hex color literal (#RGB/#RRGGBB) を小文字正規化して列挙する。
 *
 * @return list<string>
 */
function mailThemeExtractHexColors(string $path): array
{
    $content = file_get_contents($path);
    expect($content)->toBeString();
    assert(is_string($content));

    preg_match_all('/#[0-9A-Fa-f]{6}\b|#[0-9A-Fa-f]{3}\b/', $content, $matches);

    return array_values(array_unique(array_map('strtolower', $matches[0])));
}

function mailThemeCssPath(): string
{
    return resource_path('views/vendor/mail/html/themes/template.css');
}

test('template.css がメールテーマとして publish されている', function (): void {
    expect(file_exists(mailThemeCssPath()))->toBeTrue();
});

test('markdown mail のテーマとして template が設定されている', function (): void {
    expect(config('mail.markdown.theme'))->toBe('template');
    expect(config('mail.markdown.paths'))->toContain(resource_path('views/vendor/mail'));
});

test('DESIGN.md front matter の colors がパースできる (テーマ差替の前提)', function (): void {
    expect(mailThemeDesignColors())->not->toBeEmpty();
});

test('MailMessage のレンダリング結果に template テーマがインライン化される', function (): void {
    $rendered = (new MailMessage)
        ->subject('テスト')
        ->greeting('こんにちは')
        ->line('本文テスト')
        ->action('確認する', 'https://example.com')
        ->line('以上です')
        ->render();
    $rendered = (string) $rendered;

    // template.css の primary ボタン色が CssToInlineStyles でインライン化されること
    // (初期状態は default.css コピーのため Laravel 標準の #18181b。
    //  アプリでテーマを差し替えたら期待値も DESIGN.md token に更新する)
    expect(stripos($rendered, '18181b'))->not->toBeFalse('template テーマがインライン化されていない');
});

// ---- 色 parity skeleton (アプリでテーマを定義したら skip を外す) ----

test('template.css の使用色は DESIGN.md token のみで構成される (skeleton)', function (): void {
    $allowed = array_values(array_unique(array_map('strtolower', mailThemeDesignColors())));
    $used = mailThemeExtractHexColors(mailThemeCssPath());

    expect($used)->not->toBeEmpty();

    foreach ($used as $hex) {
        expect(in_array($hex, $allowed, true))
            ->toBeTrue("色 {$hex} は DESIGN.md token に存在しない (ハードコード禁止)");
    }
})->skip('テンプレート初期状態は default.css コピーのため未適用。アプリのテーマ差替後に有効化する');

test('template.css は box-shadow / グラデーションを使わない (email 互換 + シンプル優先)', function (): void {
    $css = file_get_contents(mailThemeCssPath());
    assert(is_string($css));

    // コメント中の言及は許容し、宣言 (`box-shadow:`) / 関数 (`gradient(`) の使用のみ禁止する
    expect($css)->not->toContain('box-shadow:');
    expect(stripos($css, 'gradient('))->toBeFalse();
});
