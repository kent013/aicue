<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;

/*
 * optimize:clear の拡張タスク目録 (deny-by-default)。
 *
 * bug-hunt の provision は `optimize:clear --except=cache` を叩く (dev DB の cache 表に
 * 触れないようにするため)。標準タスクのうち DB に触る cache:clear は除外したが、
 * ServiceProvider::$optimizeClearCommands 経由で **パッケージが登録した clear コマンド** も
 * 同時に実行される。ここが増えると「dev DB を触らない」前提が静かに崩れる。
 *
 * ★ これは証明ではなく **検出** である。集合が増えたら赤くなる。
 * ★ 保証しないもの: 既存の同名コマンド (filament:optimize-clear / icons:clear) の内部実装が
 *   依存更新によって DB 接続を始めても、集合検査は赤くならない (集合の増減しか見ていない)。
 *   そのため rationale は **package version 更新時に再確認する** 運用とする。
 */

/** key = $optimizeClearCommands のキー / value = [コマンド, 登録元, 非 DB と判断した理由]。 */
const BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST = [
    'filament' => [
        'filament:optimize-clear',
        'filament/support',
        'Filament の component / blade キャッシュ (ファイル) の破棄。DB を触らない',
    ],
    'blade-icons' => [
        'icons:clear',
        'blade-ui-kit/blade-icons',
        'アイコンキャッシュ (ファイル) の破棄。DB を触らない',
    ],
];

test('optimize:clear の拡張タスクが既知の allowlist と完全一致すること', function (): void {
    $registered = ServiceProvider::$optimizeClearCommands;

    expect(array_keys($registered))
        ->toEqualCanonicalizing(
            array_keys(BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST),
            '$optimizeClearCommands の集合が変わった。増えた clear コマンドが DB を触らないかを'
            .'人が判断してから allowlist に足すこと (bug-hunt の provision がこれを実行する)',
        );

    foreach (BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST as $key => [$command, $package, $rationale]) {
        expect($registered[$key])->toBe(
            $command,
            "allowlist の登録コマンドが変わった: {$key} ({$package})",
        );
        expect($rationale)->not->toBe('', "rationale が空: {$key}");
    }
});

test('bug-hunt の provision が optimize:clear から cache タスクを外していること', function (): void {
    // --except は OptimizeClearCommand::handle() の $exceptions->hasAny([$command, $key]) により
    // キー名 'cache' とコマンド名 'cache:clear' の両方に一致する。
    $script = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));

    // 実行行だけを対象にする (self-test は同じ語を検査文字列として持つため、
    // 単に 'optimize:clear' を含む行を数えると self-test 側まで拾ってしまう)。
    $lines = array_values(array_filter(
        explode("\n", $script),
        fn (string $line): bool => str_contains($line, 'php artisan optimize:clear')
            && preg_match('/^\s*#/', $line) !== 1,
    ));

    expect($lines)->toHaveCount(1, 'optimize:clear の実行行が 1 行ではない');
    expect($lines[0])->toContain('--except=cache');
    expect($lines[0])->toContain('env -i');
});
