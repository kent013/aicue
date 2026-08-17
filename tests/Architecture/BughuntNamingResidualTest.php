<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * 家系 (機能台帳 lctl の機能 bughunt-runtime) で 1 つに決まっている名前が、
 * 旧名へ戻らないことの固定。
 *
 * 裁定 AG-085 は「同じ関心事に名前が 2 つある状態」を、追従判断のたびに
 * 「欠落か別名か」の実読が発生することを理由に禁じている。2026-08-10 の裁定で
 * ファイル数の統合は撤回され、残る要件はこの名前の一意性だけである。
 *
 * ★保証範囲を誇張しない:
 *   - 見るのは**字面**である。旧名を分割して連結する書き方・別名の定数経由・
 *     動的に組み立てた文字列には**沈黙する**。
 *   - **丸ごと除外した分類 (c) の中では沈黙する**。分類 (b) は登録済みの件数だけを許容し、
 *     増減も旧名ごとの内訳の入れ替えも検出する (沈黙しない)。
 *   - 家系名が「正しい名前であること」は検査できない。正本は機能台帳であり、
 *     本検査が固定するのは「旧名が現役の資産に残っていないこと」だけである。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 退役した名前 → 家系の名前。
 *
 * 出典は機能台帳の機能 bughunt-runtime
 * (aigenba / metamovics / laravel-claude-template の実測記録)。
 *
 * @var array<string, string>
 */
const BUGHUNT_RETIRED_NAMES = [
    'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
    'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
];

/**
 * (b) 旧名を持つことが確認済みの**過去と現在の記録**と、**旧名ごとの**件数。
 *
 * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` /
 * `ROUTE_CACHE_PREMISE_KNOWN_MENTIONS` と同じ作法)。丸ごと除外にしないのは、
 * 除外したファイルの中で将来 旧名が再流入しても沈黙してしまうためである。
 *
 * ★合計ではなく**旧名ごと**に固定する。合計だけを見ると「片方を 1 件減らして
 *   もう片方を 1 件増やす」書き換えが緑のまま通る。
 *
 * - `docs/TODO-closed.md`: 完了した TODO の記録。T015 / T119 が当時作ったクラスの
 *   名前は当時の事実であり、書き換えると記録が嘘になる。
 * - `docs/TODO.md`: 本件 (T214) の登録行そのものが「どの名前をどの名前へ改名するか」を
 *   書いているため旧名を含む。これも記録であって現役の資産ではない。
 *
 * ★**TODO 台帳を動かすときは本 pin も同じ変更の中で更新する** (意図的な摩擦)。
 *   T214 をクローズすると登録行が `docs/TODO.md` から `docs/TODO-closed.md` へ移り、
 *   両ファイルの件数が同時に動く。そのときは「記録を書き換える」のではなく
 *   **pin の数を直す**のが正しい直し方である。
 *
 * @var array<string, array<string, int>>
 */
const BUGHUNT_NAMING_KNOWN_MENTIONS = [
    'docs/TODO-closed.md' => [
        'BughuntBillingSeeder' => 2,
        'FakeExternalsServiceProvider' => 3,
    ],
    'docs/TODO.md' => [
        'BughuntBillingSeeder' => 0,
        'FakeExternalsServiceProvider' => 0,
    ],
];

/**
 * (c) 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
 *
 * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、件数 pin が
 * 実務にならない (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで
 * 除外するのと同じ扱い)。
 *
 * ★**保証の穴として明記する**: ここでは旧名の再流入に沈黙する。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_EXCLUDED_PREFIXES = ['devnotes/'];

/**
 * (c) 丸ごと走査から外す唯一のファイル = 本テスト自身。
 *
 * 検出したい語を負のコントロールの入力として持つため、自分を走査すると必ず自分で赤くなる。
 * **保証の穴として明記する**: 本ファイルの中に旧名を書いても本検査は沈黙する。
 */
const BUGHUNT_NAMING_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';

/**
 * 走査の母集団が空振りでないことを確かめる代表パス (改名後に実在するもの)。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_SENTINEL_PATHS = [
    'bootstrap/providers.php',
    'scripts/bug-hunt-shard.sh',
    'database/seeders/BughuntStripeSyncSeeder.php',
    'app/Providers/BughuntFakesServiceProvider.php',
];

/** 母集団の下限 (これを下回ったら列挙そのものを疑う) */
const BUGHUNT_NAMING_MINIMUM_TRACKED_FILES = 500;

/**
 * 1 ファイル分の違反 (純関数 = 負のコントロールが**同じ述語**を通せる)。
 *
 * @return list<string>
 */
function bughuntNamingViolationsIn(string $relative, string $content): array
{
    if ($relative === BUGHUNT_NAMING_SELF_PATH) {
        return [];
    }

    foreach (BUGHUNT_NAMING_EXCLUDED_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return [];
        }
    }

    // 記録は「0 件」ではなく「pin した件数ちょうど」を旧名ごとに要求する。
    $pinned = BUGHUNT_NAMING_KNOWN_MENTIONS[$relative] ?? [];

    $violations = [];
    foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
        $count = substr_count($content, $retired);
        $allowed = $pinned[$retired] ?? 0;

        if ($count === $allowed) {
            continue;
        }

        $violations[] = $allowed === 0
            ? "{$relative}: {$retired} が {$count} 箇所残っている (家系名は {$canonical})"
            : "{$relative}: {$retired} の出現が {$count} 箇所 (pin は {$allowed} 箇所)";
    }

    return $violations;
}

/**
 * git 追跡下の全ファイル (repo 相対パス、昇順)。
 *
 * ★対象は拡張子を問わない (シェル / 文書 / 環境ひな型も見る) ため
 *   `Tests\Support\TrackedPhpSourceFiles` は使えない。共用クラスを新設せず本テスト内に閉じる。
 * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
 *
 * @return list<string>
 */
function bughuntNamingTrackedFiles(): array
{
    $process = new Process(['git', 'ls-files', '-z'], base_path());
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
            .$process->getErrorOutput()
        );
    }

    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '') {
            continue;
        }

        $files[] = $relative;
    }

    sort($files);

    return $files;
}

/**
 * 追跡下ファイルの中身を読む (読み取り失敗を空文字で握り潰さない)。
 *
 * 走査結果が空であることを「違反なし」と解釈する gate なので、読めなかったファイルは
 * 必ず名指しで落とす。
 */
function bughuntNamingSourceOf(string $relative): string
{
    $absolute = base_path($relative);
    $content = @file_get_contents($absolute);

    if (! is_string($content)) {
        throw new RuntimeException("追跡下ファイルを読み取れない (旧名の残留検査の走査対象): {$relative}");
    }

    return $content;
}

test('N-1 追跡下の現役資産に旧名が 1 つも残っておらず、記録は pin した件数ちょうどである', function (): void {
    $violations = [];

    foreach (bughuntNamingTrackedFiles() as $relative) {
        foreach (bughuntNamingViolationsIn($relative, bughuntNamingSourceOf($relative)) as $violation) {
            $violations[] = $violation;
        }
    }

    expect($violations)->toBe([]);
});

test('N-2 fail-closed: 走査の母集団が空振りしていない', function (): void {
    $files = bughuntNamingTrackedFiles();

    expect(count($files))->toBeGreaterThanOrEqual(
        BUGHUNT_NAMING_MINIMUM_TRACKED_FILES,
        '追跡下ファイルの列挙が少なすぎます (git ls-files が期待どおり動いていない可能性)',
    );

    foreach (BUGHUNT_NAMING_SENTINEL_PATHS as $sentinel) {
        expect($files)->toContain($sentinel);
    }
});

test('N-3 走査の外し方が意図どおり (ファイル数ではなく**定義の数**を固定する)', function (): void {
    // 丸ごと除外の定義は 2 つちょうど (接頭辞 devnotes/ が 1 件 + 本テスト自身が 1 件)。
    expect(BUGHUNT_NAMING_EXCLUDED_PREFIXES)->toBe(['devnotes/'])
        ->and(BUGHUNT_NAMING_SELF_PATH)->toBe('tests/Architecture/BughuntNamingResidualTest.php');

    // 件数 pin の定義は 2 ファイル分ちょうど (TODO 台帳の 2 冊)。旧名は 2 種とも書く。
    expect(array_keys(BUGHUNT_NAMING_KNOWN_MENTIONS))->toBe(['docs/TODO-closed.md', 'docs/TODO.md']);

    foreach (BUGHUNT_NAMING_KNOWN_MENTIONS as $pinned) {
        expect(array_keys($pinned))->toBe(array_keys(BUGHUNT_RETIRED_NAMES));
    }

    // 退役した名前は 2 つで、家系名と 1:1 に対応する。
    expect(BUGHUNT_RETIRED_NAMES)->toBe([
        'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
        'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
    ]);
});

test('N-4 負のコントロール: 同じ述語が検出する / しないの境界', function (): void {
    $retired = array_keys(BUGHUNT_RETIRED_NAMES);
    $seeder = $retired[0];
    $provider = $retired[1];

    // (a) 現役資産に旧名があれば検出する
    expect(bughuntNamingViolationsIn('app/Foo.php', "class Foo extends {$seeder} {}"))->toHaveCount(1);

    // (b) devnotes/ は丸ごと外れている (沈黙する)
    expect(bughuntNamingViolationsIn('devnotes/x/y.md', "{$seeder} {$provider}"))->toBe([]);

    // (c) 本テスト自身も丸ごと外れている (沈黙する)
    expect(bughuntNamingViolationsIn(BUGHUNT_NAMING_SELF_PATH, "{$seeder} {$provider}"))->toBe([]);

    // (d) pin したファイルで件数がずれたら検出する (少なくても多くても)
    // docs/TODO-closed.md の pin は T214 クローズ後の値 (seeder=2 / provider=3)。
    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$provider} {$provider} {$provider}"))->toBe([]);
    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$provider} {$provider} {$provider}"))->toHaveCount(1);
    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$seeder} {$provider} {$provider} {$provider}"))->toHaveCount(1);

    // (e) 合計は同じだが内訳が違う入力も検出する (旧名ごとに固定しているため)
    expect(bughuntNamingViolationsIn('docs/TODO-closed.md', "{$seeder} {$seeder} {$seeder} {$provider} {$provider}"))->toHaveCount(2);

    // (f) もう 1 冊の TODO 台帳 (docs/TODO.md は T214 クローズ後、旧名ともに 0 件) でも同じ境界が働く
    expect(bughuntNamingViolationsIn('docs/TODO.md', ''))->toBe([]);
    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder}"))->toHaveCount(1);
    expect(bughuntNamingViolationsIn('docs/TODO.md', "{$seeder} {$provider}"))->toHaveCount(2);
});

test('N-5 旧名のクラスは存在せず、家系名のクラスが存在する', function (): void {
    expect(class_exists('Database\Seeders\BughuntBillingSeeder'))->toBeFalse()
        ->and(class_exists('App\Providers\FakeExternalsServiceProvider'))->toBeFalse()
        ->and(class_exists('Database\Seeders\BughuntStripeSyncSeeder'))->toBeTrue()
        ->and(class_exists('App\Providers\BughuntFakesServiceProvider'))->toBeTrue();
});
