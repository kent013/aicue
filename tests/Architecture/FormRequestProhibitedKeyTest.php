<?php

declare(strict_types=1);

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/*
 * mass-assignment 入口防御の deny-by-default inventory:
 * app/Http/Requests 配下の全 FormRequest は ProhibitsProtectedKeys を use し、
 * rules() の戻り値に保護キーの missing rule を含めなければならない。
 *
 * 例外を許す場合は ALLOWLIST に「クラス名 => 理由」を追記し、
 * docs/template-divergence.md に記録すること。
 */

const FORM_REQUEST_ALLOWLIST = [
    // 'App\Http\Requests\FooRequest' => '理由',
];

/**
 * @return list<class-string>
 */
function allFormRequestClasses(): array
{
    $classes = [];
    $base = app_path('Http/Requests');
    if (! is_dir($base)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace([$base.'/', '.php', '/'], ['', '', '\\'], $file->getPathname());
        $class = 'App\\Http\\Requests\\'.$relative;
        if (class_exists($class)
            && is_subclass_of($class, FormRequest::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('全 FormRequest が ProhibitsProtectedKeys を use している (deny-by-default)', function (): void {
    $violations = [];

    foreach (allFormRequestClasses() as $class) {
        if (array_key_exists($class, FORM_REQUEST_ALLOWLIST)) {
            continue;
        }
        $traits = class_uses_recursive($class);
        if (! in_array(ProhibitsProtectedKeys::class, $traits, true)) {
            $violations[] = $class;
        }
    }

    expect($violations)->toBe([]);
});
