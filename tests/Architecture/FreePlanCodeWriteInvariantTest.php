<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| free_plan_code 書き込み経路の invariant
|--------------------------------------------------------------------------
|
| `organizations.free_plan_code` は課金状態 (free entitlement) を確定させる状態キーのため、
| 書き込み (array key 代入 / プロパティ代入) は PersonalPlanService に閉じる。値域
| ('personal' のみ) を DB check constraint ではなくアプリ側定数
| (PersonalPlanService::FREE_PLAN_CODE) で守る前提の機械的補助。
| 読み取り (`->free_plan_code` の比較) は対象外。
|
| 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
| 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
| 列名の改名・走査根の移動で母集団が 0 件になると、窓口が消えても違反ゼロで緑になる。
| 末尾の「空振り検査」ケースが母集団を allowlist と完全一致で pin し (= 非空)、
| その直後の負のコントロールが「一致するもののない根では母集団が空になる」ことを示す。
*/

/** 走査で母集団に入る唯一のファイル (窓口)。 */
const FREE_PLAN_CODE_WRITE_ALLOWLIST = [
    'app/Services/Billing/PersonalPlanService.php',
];

/**
 * 走査根配下で free_plan_code への書き込みを持つファイル (リポジトリ相対パス)。
 *
 * 書き込みパターン: array key 代入 ('free_plan_code' => / "free_plan_code" =>) と
 * プロパティ代入 (->free_plan_code = 値。=== / !== 比較は除外)。
 *
 * @param  string  $absoluteRoot  走査根の絶対パス (負のコントロールで差し替えるため引数化)
 * @return list<string>
 */
function freePlanCodeWriteFiles(string $absoluteRoot): array
{
    if (! is_dir($absoluteRoot)) {
        return [];
    }

    $finder = Finder::create()
        ->in($absoluteRoot)
        ->files()
        ->name('*.php')
        ->contains('/([\'"])free_plan_code\1\s*=>|->free_plan_code\s*=[^=]/');

    $files = [];
    foreach ($finder as $file) {
        $files[] = str_replace(base_path().'/', '', (string) $file->getRealPath());
    }
    sort($files);

    return $files;
}

test('app/ 内の free_plan_code 書き込みは PersonalPlanService に閉じる', function (): void {
    $violations = array_values(array_diff(
        freePlanCodeWriteFiles(base_path('app')),
        FREE_PLAN_CODE_WRITE_ALLOWLIST,
    ));

    expect($violations)->toBe([], 'free_plan_code の書き込みは PersonalPlanService 経由に限定してください: '.implode(', ', $violations));
});

test('空振り検査: 走査の母集団が窓口 1 本と完全一致する (走査根が生きている)', function (): void {
    // 母集団が空 = 窓口そのものを検出できていない状態であり、上の検査は無条件に緑になる。
    // 完全一致で pin することで「非空」と「窓口が母集団に居ること」を同時に固定する。
    expect(freePlanCodeWriteFiles(base_path('app')))->toBe(FREE_PLAN_CODE_WRITE_ALLOWLIST);
});

test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
    // 上の pin が空振りしていないことの裏取り。走査根の改名・移動を模して
    // 一致するもののないディレクトリ / 実在しないパスを渡すと母集団が 0 件になる。
    expect(freePlanCodeWriteFiles(base_path('config')))->toBe([]);
    expect(freePlanCodeWriteFiles(base_path('app-renamed')))->toBe([]);
});
