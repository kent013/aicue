<?php

declare(strict_types=1);

/*
 * テンプレート規約: アプリ slug のハードコード禁止。
 *
 * アプリ固有の識別子は config/template.php (TEMPLATE_APP_SLUG) と .env にのみ現れてよい。
 * コード中に slug を直書きすると、テンプレート派生アプリ間の copy-paste で別アプリの
 * 名前が混入する事故が起きる (spirux の tests/bootstrap.php に aigenba- が残っていた実例)。
 *
 * 検査: app/ routes/ database/ tests/ resources/js/ scripts/ の中に
 * config('template.slug') 以外の経路で slug 既定値が現れないこと。
 * 既定 slug は 'app' で一般語のため、ここでは「.env.example の TEMPLATE_APP_SLUG 値」を
 * 動的に取得して走査する (アプリが slug を変更した後も機能する)。
 */

test('アプリ slug がコードにハードコードされていない', function (): void {
    $envExample = file_get_contents(base_path('.env.example'));
    expect($envExample)->toBeString();
    /** @var string $envExample */
    preg_match('/^TEMPLATE_APP_SLUG=(.+)$/m', $envExample, $m);
    $slug = trim($m[1] ?? '');

    // 既定値 'app' は一般語のため走査対象外 (派生アプリが固有 slug を設定した時点で発動する)
    if ($slug === '' || $slug === 'app') {
        expect(true)->toBeTrue();

        return;
    }

    $directories = ['app', 'routes', 'database', 'resources/js', 'scripts'];
    $violations = [];

    foreach ($directories as $dir) {
        $path = base_path($dir);
        if (! is_dir($path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents !== false && str_contains($contents, $slug)) {
                $violations[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($violations)->toBe([], 'slug "'.$slug.'" のハードコードを検出: '.implode(', ', $violations));
});
