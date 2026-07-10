<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderClipSpec;
use App\Services\Render\AssSubtitleWriter;
use Illuminate\Support\Facades\Log;

/*
 * ASS 字幕生成の安全境界 (概念設計 §6):
 * - override tag ({\...}) 注入・リテラル \N \n \h・制御文字・zero-width の無効化
 * - 実改行とリテラル \N の共存 (正規化順の固定 = Round 2 Critical)
 * - 長さ上限のマルチバイト安全な切り詰め + 構造化ログ / BOM なし UTF-8
 */

function subtitleClip(?string $primary, string $secondary): RenderClipSpec
{
    return new RenderClipSpec(
        cutId: 1,
        label: '手順1',
        source: RenderClipSource::TakeVideo,
        takeVideoPath: 'takes/x.mp4',
        stillDisplaySeconds: null,
        subtitlePrimary: $primary,
        subtitleSecondary: $secondary,
    );
}

function writeSubtitleFile(RenderClipSpec $clip, int $durationMs = 5_000): string
{
    $dir = sys_get_temp_dir().'/ass-writer-test-'.uniqid();
    mkdir($dir);
    $path = "{$dir}/clip0.ass";
    app(AssSubtitleWriter::class)->write($clip, $durationMs, $path);

    return $path;
}

test('攻撃的入力: override tag / リテラル \\N / 制御文字が生成ファイルに現れない', function (): void {
    $attack = "{\\an8}悪意' : , のタグ\\Nリテラル\\n改行\\hと\x07制御\x1b文字";
    $path = writeSubtitleFile(subtitleClip(null, $attack));
    $contents = (string) file_get_contents($path);

    // override tag の { } は全角化され、ASS の制御構文として解釈されない
    expect($contents)->not->toContain('{\\an8}');
    expect($contents)->toContain('｛＼an8｝');
    // 入力由来のバックスラッシュは全角化 (リテラル \N \n \h の無効化)
    expect($contents)->not->toContain('悪意\\N');
    expect($contents)->toContain('＼N');
    expect($contents)->toContain('＼n');
    expect($contents)->toContain('＼h');
    // 制御文字は除去される
    expect($contents)->not->toContain("\x07");
    expect($contents)->not->toContain("\x1b");
});

test('実改行とリテラル \\N の共存: 実改行だけが ASS 改行 (\\N) として残る (正規化順の固定)', function (): void {
    $input = "A\\NB\nC\r\nD\rE"; // リテラル \N と LF / CRLF / 単独 CR の混在
    $path = writeSubtitleFile(subtitleClip(null, $input));
    $contents = (string) file_get_contents($path);

    $dialogue = collect(explode("\n", $contents))
        ->first(fn (string $line): bool => str_starts_with($line, 'Dialogue:'));
    expect($dialogue)->not->toBeNull();
    // リテラル \N は無効化 (＼N)、実改行 3 箇所が ASS 改行 \N になる
    expect($dialogue)->toContain('A＼NB\\NC\\ND\\NE');
});

test('zero-width 文字 (U+200B / U+202E / U+FEFF) は除去される', function (): void {
    $input = "手\u{200B}順\u{202E}の\u{FEFF}説明";
    $path = writeSubtitleFile(subtitleClip(null, $input));
    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('手順の説明');
    expect($contents)->not->toContain("\u{200B}");
    expect($contents)->not->toContain("\u{202E}");
    expect($contents)->not->toContain("\u{FEFF}");
});

test('極端な長文はマルチバイト安全に切り詰め + 構造化ログ (UTF-8 途中切断なし)', function (): void {
    Log::shouldReceive('warning')->atLeast()->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'render subtitle truncated'
            && $context['cut_id'] === 1,
    );

    $input = str_repeat('あ', 700); // 総 500 文字上限を超過
    $path = writeSubtitleFile(subtitleClip(null, $input));
    $contents = (string) file_get_contents($path);

    // UTF-8 として妥当 (途中切断なし)
    expect(mb_check_encoding($contents, 'UTF-8'))->toBeTrue();
    $dialogue = collect(explode("\n", $contents))
        ->first(fn (string $line): bool => str_starts_with($line, 'Dialogue:'));
    expect($dialogue)->not->toBeNull();
    // 1 行 100 文字上限 (改行なし入力は先頭行の切り詰めのみ = 100 文字)
    expect(mb_substr_count((string) $dialogue, 'あ'))->toBe(100);
});

test('出力は BOM なし UTF-8 (先頭 3 バイトが EF BB BF でない)', function (): void {
    $path = writeSubtitleFile(subtitleClip('5Nm', '締め付けトルク'));
    $contents = (string) file_get_contents($path);

    expect(str_starts_with($contents, "\xEF\xBB\xBF"))->toBeFalse();
    expect(mb_check_encoding($contents, 'UTF-8'))->toBeTrue();
});

test('通常の日本語字幕が Dialogue 行に保持される (secondary=下部 / primary=上部帯)', function (): void {
    $path = writeSubtitleFile(subtitleClip('5Nm', 'トルクレンチで締め付ける'), 12_340);
    $contents = (string) file_get_contents($path);

    expect($contents)->toContain('Style: Secondary,');
    expect($contents)->toContain('Style: Primary,');
    expect($contents)->toContain('Dialogue: 0,0:00:00.00,0:00:12.34,Secondary,,0,0,0,,トルクレンチで締め付ける');
    expect($contents)->toContain('Dialogue: 0,0:00:00.00,0:00:12.34,Primary,,0,0,0,,5Nm');
});

test('primary が null なら Primary の Dialogue 行を出力しない', function (): void {
    $path = writeSubtitleFile(subtitleClip(null, '本文のみ'));
    $contents = (string) file_get_contents($path);

    expect($contents)->toContain(',Secondary,');
    expect($contents)->not->toContain('Dialogue: 0,0:00:00.00,0:00:05.00,Primary');
});
