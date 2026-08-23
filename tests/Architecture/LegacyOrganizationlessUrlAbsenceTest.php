<?php

declare(strict_types=1);

use Tests\Support\LegacyUrl\LegacyUrlAllowance;
use Tests\Support\LegacyUrl\LegacyUrlExtractionMode;
use Tests\Support\LegacyUrl\LegacyUrlScannedFile;
use Tests\Support\LegacyUrl\LegacyUrlScanner;
use Tests\Support\LegacyUrl\LegacyUrlScanRoots;

/*
 * 組織を持たない**旧 URL** と**撤去した route 名**がリポジトリに 1 件も残っていない
 * (家系裁定 AG-037 / 施策 10)。
 *
 * ## なぜ必要か
 *
 * 単位 B で業務 route を `/organizations/{organization:slug}/…` 配下へ移し、
 * **旧 URL からの転送を置かない**判断をした (思考原則 3: 後方互換の並走を残さない)。
 * したがってリポジトリ内に旧 URL が 1 つでも残っていれば、それは**壊れた導線**である
 * (画面のリンクなら 404、文書なら誤った案内)。
 *
 * ## 台帳は 2 つ (同じ台帳にしない)
 *
 * route 名は施策 5 で**維持**したので、検出対象は「URL 文字列」と「撤去 route 名」に分かれる。
 * 前者は `LegacyUrlScanner::legacyRoots()`、後者は `LegacyUrlScanner::removedRouteName()` が正本。
 *
 * ## 母集団と 4 分類
 *
 * 母集団は git 追跡下ファイル**全数**で、`LegacyUrlScanRoots` が
 * 「走査する / 走査しない (理由付き) / 自己検査専用 / 未分類」へ**排他的に**分ける。
 * **未分類が 1 件でもあれば赤**である (新しい置き場所・拡張子が黙って走査から外れない)。
 *
 * ## 自己検出の閉じ方
 *
 * 本 gate も走査器も**旧 URL 文字列を持たない** (断片を連結して組み立てる)。
 * 検出語をわざと持つ見本だけが「自己検査専用」へ名指しで入り、
 * その件数は `LegacyUrlSelfCheckPopulationTest` が完全一致で pin する。
 *
 * ## 保証しないもの
 *
 * 走査器 (`LegacyUrlScanner`) と母集団 (`LegacyUrlScanRoots`) の docblock が正本である。
 * リポジトリの外 (利用者のブックマーク・送信済みメール・ブラウザ履歴) は対象外である。
 */

/** 走査対象の抽出方式が 5 規則とも生きている (走査根が壊れても気付ける)。 */
test('母集団は空でなく、規則ごとに 1 件以上のファイルがある', function (): void {
    $population = LegacyUrlScanRoots::population();

    expect($population->scanned)->not->toBeEmpty();

    $counts = $population->scannedCountByRule();
    foreach ([
        LegacyUrlScanner::RULE_PHP_LITERAL,
        LegacyUrlScanner::RULE_SCRIPT_LITERAL,
        LegacyUrlScanner::RULE_BLADE_TEXT,
        LegacyUrlScanner::RULE_MARKDOWN_TEXT,
        LegacyUrlScanner::RULE_DATA_TEXT,
    ] as $rule) {
        expect($counts[$rule] ?? 0)->toBeGreaterThan(0, "規則 {$rule} の母集団が空です");
    }
});

test('未分類の置き場所・形式は 0 件 (分類漏れは赤)', function (): void {
    expect(LegacyUrlScanRoots::population()->unclassified)->toBe([]);
});

test('解決できないファイルは 0 件 (読めないまま黙って落とさない)', function (): void {
    expect(LegacyUrlScanRoots::population()->unresolved)->toBe([]);
});

test('走査しない分類と許可目録の理由はいずれも 30 文字以上', function (): void {
    $short = [];
    foreach (LegacyUrlScanRoots::notScannedPathReasons() as $path => $reason) {
        if (mb_strlen($reason) < 30) {
            $short[] = "not-scanned path {$path}";
        }
    }
    foreach (LegacyUrlScanRoots::notScannedExtensionReasons() as $extension => $reason) {
        if (mb_strlen($reason) < 30) {
            $short[] = "not-scanned extension {$extension}";
        }
    }
    foreach (LegacyUrlScanRoots::selfCheckOnlyReasons() as $path => $reason) {
        if (mb_strlen($reason) < 30) {
            $short[] = "self-check-only {$path}";
        }
    }
    foreach (LegacyUrlAllowance::entries() as $entry) {
        if (mb_strlen($entry['reason']) < 30) {
            $short[] = "allowance {$entry['path']}";
        }
    }

    expect($short)->toBe([]);
});

test('旧 URL と撤去 route 名は許可目録に登録したものを除いて 0 件', function (): void {
    $allowed = LegacyUrlAllowance::counts();
    $observed = [];
    $violations = [];

    foreach (LegacyUrlScanRoots::population()->scanned as $file) {
        foreach (LegacyUrlScanner::scanFile($file) as $occurrence) {
            $key = $occurrence->relative."\0".$occurrence->ruleId;
            $observed[$key] = ($observed[$key] ?? 0) + 1;
            if (! array_key_exists($key, $allowed)) {
                $violations[] = $occurrence->describe();
            }
        }
    }

    sort($violations);
    expect($violations)->toBe([]);

    // ★件数は完全一致 (増えても減っても赤 / 登録が実在しなくなっても赤)
    $mismatched = [];
    foreach ($allowed as $key => $count) {
        [$path, $rule] = explode("\0", $key);
        $actual = $observed[$key] ?? 0;
        if ($actual !== $count) {
            $mismatched[] = "{$path} [{$rule}] 登録 {$count} 件 / 実測 {$actual} 件";
        }
    }
    expect($mismatched)->toBe([]);
});

test('負例: 見本の旧 URL を検出できる (検出力の裏取り)', function (): void {
    $base = base_path('tests/Architecture/fixtures/legacy-url/');

    $markdown = new LegacyUrlScannedFile(
        relative: 'fixture.md',
        contents: (string) file_get_contents($base.'legacy-paths.md'),
        mode: LegacyUrlExtractionMode::PlainText,
        ruleId: LegacyUrlScanner::RULE_MARKDOWN_TEXT,
    );
    $php = new LegacyUrlScannedFile(
        relative: 'fixture.php',
        contents: (string) file_get_contents($base.'legacy-php-source.txt'),
        mode: LegacyUrlExtractionMode::SourceLiteral,
        ruleId: LegacyUrlScanner::RULE_PHP_LITERAL,
    );
    $script = new LegacyUrlScannedFile(
        relative: 'fixture.ts',
        contents: (string) file_get_contents($base.'legacy-script-source.txt'),
        mode: LegacyUrlExtractionMode::SourceLiteral,
        ruleId: LegacyUrlScanner::RULE_SCRIPT_LITERAL,
    );

    // Markdown: 旧パス 11 件 + 撤去 route 名 1 件
    expect(LegacyUrlScanner::scanFile($markdown))->toHaveCount(12);
    // PHP: リテラルの旧パス 2 件 (コメントは数えない) + 撤去 route 名 1 件
    expect(LegacyUrlScanner::scanFile($php))->toHaveCount(3);
    // script: リテラルの旧パス 1 件 (コメント / 組織 URL 組み立ての入口 / 組織 prefix は数えない)
    expect(LegacyUrlScanner::scanFile($script))->toHaveCount(1);
});

test('負例: 新 URL と紛らわしい語を誤検出しない (接頭辞・打ち消し・接尾辞の 3 形を含む)', function (): void {
    $allowed = new LegacyUrlScannedFile(
        relative: 'fixture.md',
        contents: (string) file_get_contents(base_path('tests/Architecture/fixtures/legacy-url/allowed-paths.md')),
        mode: LegacyUrlExtractionMode::PlainText,
        ruleId: LegacyUrlScanner::RULE_MARKDOWN_TEXT,
    );

    expect(array_map(
        static fn (object $occurrence): string => (string) $occurrence->describe(),
        LegacyUrlScanner::scanFile($allowed),
    ))->toBe([]);
});

test('種別ごとの割り当て: 拡張子は宣言した抽出方式と規則 ID へ 1:1 で写る', function (): void {
    // ★「どの種別をどう抽出するか」は分類表が唯一の正本である。ここが壊れると
    //   Blade / JSON / TOML が黙って別の方式で読まれ、検出力の裏取りが意味を失う。
    $expected = [
        'resources/views/app.blade.php' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_BLADE_TEXT],
        'app/Models/User.php' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_PHP_LITERAL],
        'resources/js/lib/org-url.ts' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'resources/js/pages/Dashboard.svelte' => [LegacyUrlExtractionMode::SourceLiteral, LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'docs/architecture.md' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_MARKDOWN_TEXT],
        'public/manifest.webmanifest' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT],
        '.claude/skills/app-bug-hunt/inventory/annotations.toml' => [LegacyUrlExtractionMode::PlainText, LegacyUrlScanner::RULE_DATA_TEXT],
    ];

    foreach ($expected as $path => [$mode, $rule]) {
        $classification = LegacyUrlScanRoots::classify($path);
        expect($classification)->not->toBeNull("{$path} が未分類です");
        expect($classification['mode'])->toBe($mode, "{$path} の抽出方式が宣言と違います");
        expect($classification['rule'])->toBe($rule, "{$path} の規則 ID が宣言と違います");
    }
});

test('負例: 未知の拡張子は未分類として落ちる (fail-closed)', function (): void {
    expect(LegacyUrlScanRoots::classify('resources/js/app.unknownext'))->toBeNull();
    expect(LegacyUrlScanRoots::classify('app/Models/User.php'))->not->toBeNull();
});
