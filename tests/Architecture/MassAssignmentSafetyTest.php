<?php

declare(strict_types=1);

use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Models\User;
use App\Support\Security\MassAssignmentProtectedKeys;
use Illuminate\Database\Eloquent\Model;

/*
 * mass-assignment 出口防御: ownership / actor / tenant / secret キーは
 * どの Model の $fillable にも含めない (明示代入 or relation 経由のみ)。
 *
 * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
 * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である
 * (app/Models が改名・移動すると違反ゼロのまま緑になる)。末尾の
 * 「空振り検査」ケースが非空・床値・代表クラスを固定し、その直後の負のコントロールが
 * 「走査根を差し替えると母集団が空になる = この検査は空振りしていない」ことを示す。
 */

/**
 * app/Models 配下 (サブ名前空間含む。Models\Billing 等) の全 Model を列挙する。
 *
 * @param  string|null  $base  走査根の絶対パス (null = app/Models)。
 *                             負のコントロールで別の根を渡すために引数化してある
 * @return list<class-string<Model>>
 */
function allModelClasses(?string $base = null): array
{
    $classes = [];
    $base ??= app_path('Models');
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

test('空振り検査: Model の母集団が空でない (走査根 app/Models が生きている)', function (): void {
    $classes = allModelClasses();

    // 非空: 走査根が消えても違反ゼロで緑になる形を落とす
    expect($classes)->not->toBe([], '走査根 app/Models から Model が 1 件も見つかりません');
    // 床値 (実測 40 件): 走査域が黙って狭まると赤くなる
    expect(count($classes))->toBeGreaterThanOrEqual(30);
    // 代表クラス: サブ名前空間 (Models\Billing) まで届いていること
    expect($classes)->toContain(User::class);
    expect($classes)->toContain(Organization::class);
    expect($classes)->toContain(Subscription::class);
});

test('負のコントロール: 走査根を差し替えると Model の母集団が空になる', function (): void {
    // 上の非空検査が空振りしていないことの裏取り。走査根の改名・移動を模して
    // 別ディレクトリ / 実在しないパスを渡すと母集団が 0 件になる = 非空検査が赤くなる。
    expect(allModelClasses(app_path('Console')))->toBe([]);
    expect(allModelClasses(app_path('Models-renamed')))->toBe([]);
});
