<?php

declare(strict_types=1);

use App\Support\Security\MassAssignmentProtectedKeys;
use Illuminate\Database\Eloquent\Model;

/*
 * mass-assignment 出口防御: ownership / actor / tenant / secret キーは
 * どの Model の $fillable にも含めない (明示代入 or relation 経由のみ)。
 */

/**
 * app/Models 配下 (サブ名前空間含む。Models\Billing 等) の全 Model を列挙する。
 *
 * @return list<class-string<Model>>
 */
function allModelClasses(): array
{
    $classes = [];
    $base = app_path('Models');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace([$base.'/', '.php', '/'], ['', '', '\\'], $file->getPathname());
        $class = 'App\\Models\\'.$relative;
        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('保護キーが $fillable に含まれる Model が存在しない', function (): void {
    $violations = [];

    foreach (allModelClasses() as $class) {
        $fillable = (new $class)->getFillable();
        foreach (MassAssignmentProtectedKeys::all() as $key) {
            if (in_array($key, $fillable, true)) {
                $violations[] = "{$class}: {$key}";
            }
        }
    }

    expect($violations)->toBe([]);
});

test('Model が mass-assignment 全開放 ($guarded=[] かつ $fillable=[]) になっていない', function (): void {
    $violations = [];

    foreach (allModelClasses() as $class) {
        $model = new $class;
        // $fillable が非空なら許可リスト方式で防御されている ($guarded は無関係)
        if ($model->getGuarded() === [] && $model->getFillable() === []) {
            $violations[] = $class;
        }
    }

    expect($violations)->toBe([]);
});
