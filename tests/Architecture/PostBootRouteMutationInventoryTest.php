<?php

declare(strict_types=1);

/*
 * Architecture invariant: 起動後に route collection から named route を引いて
 * 加工するコードは **skip 判定を引数で受ける 2 つの binder に限る**。
 *
 * ★止めたい具体的失敗は 1 つで、**過去に実際に起きている**:
 *   新しい後付け経路を追加した人が cached 起動の skip 判定を書かず、
 *   `routesAreCached()` の起動で例外を投げて `php artisan route:list` が必ず落ちる
 *   (aicue T120。docs/TODO-closed.md の T120 行に記録)。
 *   後付け経路はこの 1 年で 3 本増えており (T120 / T121 / T124)、4 本目が足される
 *   確率は低くない。入口を 2 クラスに絞れば、その 2 クラスが持つ
 *   「skip 判定を引数で受ける純粋関数」の形が自動的に効く。
 *
 * ★何を検査するか: `app/` 配下の PHP ファイルに現れる `getByName(` /
 *   `refreshNameLookups(` の出現ファイルが allowlist の 2 ファイルだけであること。
 *
 * ★何を検査しないか (誇張しない):
 *   - **docblock の主張が機序と一致していること**は検査しない。自然言語の主張は
 *     機械で照合できない。ここで守れるのは「後付けの**入口**が 1 本に絞られている」までである。
 *   - **起動時の route cache 鮮度**は検査しない。本番デプロイは全ファイルを新規展開するため
 *     mtime が揃い、cache が古いソースから作られたかは起動時から判定できない
 *     (「作れるが作らない」ではなく **正しく作れない**)。
 *   - トークン走査であるため `$router->getRoutes()->{$m}(...)` のように変数越しに
 *     組み立てる書き方は**すり抜ける**。この gate は「うっかり」を止めるものであって、
 *     意図的な迂回を止めるものではない。
 *
 * ★素の文字列走査で足りる理由: 現在の出現は 3 ファイル・7 箇所のみで、
 *   コメント中の記述を誤検出しないための `token_get_all()` 除外は**今は入れない**
 *   (思考原則 2: 今必要なものだけ作る)。必要になってから入れる。
 *   ただし docblock 内の記述も検出対象になるため、allowlist 外のファイルで
 *   これらの識別子に**言及**するときは「メソッド名 + `(`」の形を避けること。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 後付け経路の唯一の入口として許可されたファイル (repo 相対)。
 *
 * 増やすときは「skip 判定を引数で受ける純粋関数になっているか」を必ず review すること。
 * これは**意図した摩擦**である。
 *
 * @var list<string>
 */
const POST_BOOT_ROUTE_MUTATION_ALLOWLIST = [
    'app/Support/Http/RouteMiddlewareBinder.php',
    'app/Support/Http/RouteThrottleBinder.php',
];

/**
 * 後付けの痕跡となるトークン。
 *
 * @var list<string>
 */
const POST_BOOT_ROUTE_MUTATION_TOKENS = [
    'getByName(',
    'refreshNameLookups(',
];

/**
 * `app/` 配下の PHP ファイル一覧 (repo 相対パス)。
 *
 * @return list<string>
 */
function postBootRouteMutationScanTargets(): array
{
    $root = base_path();
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $absolute = $file->getRealPath();
        if (! is_string($absolute)) {
            continue;
        }

        $files[] = ltrim(str_replace($root, '', $absolute), '/');
    }

    sort($files);

    return $files;
}

/**
 * 対象トークンを含むファイルの一覧 (repo 相対パス)。
 *
 * @return list<string>
 */
function postBootRouteMutationOffenders(): array
{
    $offenders = [];

    foreach (postBootRouteMutationScanTargets() as $relative) {
        $source = file_get_contents(base_path().'/'.$relative);
        expect($source)->toBeString("読み取れないファイル: {$relative}");

        foreach (POST_BOOT_ROUTE_MUTATION_TOKENS as $token) {
            if (str_contains($source, $token)) {
                $offenders[] = $relative;
                break;
            }
        }
    }

    return $offenders;
}

test('起動後の named route 加工は 2 つの binder だけが行う (deny-by-default)', function (): void {
    $unexpected = array_values(array_diff(postBootRouteMutationOffenders(), POST_BOOT_ROUTE_MUTATION_ALLOWLIST));

    expect($unexpected)->toBe([], implode("\n", [
        '起動後に named route を引いて加工するコードが allowlist 外にあります:',
        '  '.implode("\n  ", $unexpected),
        '',
        '後付けは RouteThrottleBinder / RouteMiddlewareBinder 経由にすること。',
        'cached 起動で例外を投げると `php artisan route:list` が必ず落ちる (T120 の事故)。',
        '両 binder は skip 判定を引数で受ける純粋関数の形になっており、この形が回帰を防いでいる。',
    ]));
});

/*
 * negative control: allowlist の実装が消えたり改名されたときに、
 * 上の検査が「対象 0 件だから green」という空振りにならないことを固定する。
 */
test('allowlist の 2 ファイルは実際に後付けトークンを含む (空振り green の排除)', function (): void {
    $offenders = postBootRouteMutationOffenders();

    foreach (POST_BOOT_ROUTE_MUTATION_ALLOWLIST as $allowed) {
        // ★`toContain()` は可変長 needle を取るためメッセージ引数を持てない。
        //   bool へ落としてから toBeTrue() で理由を書く。
        expect(in_array($allowed, $offenders, true))->toBeTrue(
            "allowlist の [{$allowed}] が後付けトークンを 1 つも含みません。"
            .'実装が消えた / 改名された場合は allowlist も同時に更新すること。',
        );
    }
});
