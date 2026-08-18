<?php

declare(strict_types=1);

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\StoreInquiryRequest;
use Illuminate\Foundation\Http\FormRequest;

/*
 * mass-assignment 入口防御の deny-by-default inventory:
 * app/Http/Requests 配下の全 FormRequest は ProhibitsProtectedKeys を use し、
 * rules() の戻り値に保護キーの missing rule を含めなければならない。
 *
 * 例外を許す場合は ALLOWLIST に「クラス名 => 理由」を追記し、
 * docs/template-divergence.md に記録すること。
 *
 * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
 * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である
 * (app/Http/Requests が改名・移動すると違反ゼロのまま緑になる)。末尾の「空振り検査」ケースが
 * 非空・床値・代表クラスを固定し、その直後の負のコントロールが
 * 「走査根を差し替えると母集団が空になる = この検査は空振りしていない」ことを示す。
 */

const FORM_REQUEST_ALLOWLIST = [
    // 'App\Http\Requests\FooRequest' => '理由',
];

/**
 * @param  string|null  $base  走査根の絶対パス (null = app/Http/Requests)。
 *                             負のコントロールで別の根を渡すために引数化してある
 * @return list<class-string>
 */
function allFormRequestClasses(?string $base = null): array
{
    $classes = [];
    $base ??= app_path('Http/Requests');
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

test('空振り検査: FormRequest の母集団が空でない (走査根 app/Http/Requests が生きている)', function (): void {
    $classes = allFormRequestClasses();

    expect(is_dir(app_path('Http/Requests')))->toBeTrue('走査根 app/Http/Requests が存在しません');
    // 非空: 走査根が消えても違反ゼロで緑になる形を落とす
    expect($classes)->not->toBe([], '走査根 app/Http/Requests から FormRequest が 1 件も見つかりません');
    // 床値 (実測 34 件): 走査域が黙って狭まると赤くなる
    expect(count($classes))->toBeGreaterThanOrEqual(25);
    // 代表クラス: 直下とサブディレクトリの両方へ届いていること
    expect($classes)->toContain(StoreInquiryRequest::class);
    expect($classes)->toContain(StoreProjectRequest::class);
});

test('負のコントロール: 走査根を差し替えると FormRequest の母集団が空になる', function (): void {
    // 上の非空検査が空振りしていないことの裏取り。走査根の改名・移動を模して
    // 別ディレクトリ / 実在しないパスを渡すと母集団が 0 件になる = 非空検査が赤くなる。
    expect(allFormRequestClasses(app_path('Models')))->toBe([]);
    expect(allFormRequestClasses(app_path('Http/Requests-renamed')))->toBe([]);
});
