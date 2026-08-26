<?php

declare(strict_types=1);

use Tests\Support\PhpTokenScan;

/*
 * Architecture invariant: 起動後に route collection から named route を引いて
 * 加工するコードは **skip 判定を引数で受ける 2 つの binder に限る**。
 * 加えて、その **実行タイミングは専用の実行点 1 つ (`AfterRoutesLoaded`) に限る**。
 *
 * ★止めたい具体的失敗は 1 つで、**過去に実際に起きている**:
 *   新しい後付け経路を追加した人が cached 起動の skip 判定を書かず、
 *   `routesAreCached()` の起動で例外を投げて `php artisan route:list` が必ず落ちる
 *   (aicue T120。docs/TODO-closed.md の T120 行に記録)。
 *   後付け経路はこの 1 年で 3 本増えており (T120 / T121 / T124)、4 本目が足される
 *   確率は低くない。入口を 2 クラスに絞り、実行点を 1 クラスに絞れば、
 *   「経路の一覧が組み上がった後にだけ走り、cached 起動では走らない」形が自動的に効く。
 *
 * ★何を検査するか (3 つ):
 *   1. `app/` 配下の PHP ファイルに現れる `getByName(` / `refreshNameLookups(` の
 *      出現ファイルが allowlist の 2 ファイルだけであること (検査 1 / 検査 2)。
 *   2. `app/` 配下に **経路一覧を触る素の起動完了フック (`booted(`) の直呼び**が
 *      無いこと (検査 3。実行点クラス自身は対象外)。
 *      素の起動完了フックは **cached routes の読み込みより先に走る**ため、
 *      そこで経路名の fail-fast を行うと route:cache 済みの起動が丸ごと落ちる。
 *   3. 実行点クラスが実際に起動完了フックを持ち、2 binder がその実行点を経由していること
 *      (検査 4 / 検査 5。空振り green の排除)。
 *
 * ★何を検査しないか (誇張しない):
 *   - **docblock の主張が機序と一致していること**は検査しない。自然言語の主張は
 *     機械で照合できない。ここで守れるのは「後付けの**入口**と**実行点**が絞られている」までである。
 *   - **起動時の route cache 鮮度**は検査しない。本番デプロイは全ファイルを新規展開するため
 *     mtime が揃い、cache が古いソースから作られたかは起動時から判定できない
 *     (「作れるが作らない」ではなく **正しく作れない**)。
 *   - トークン走査であるため `$router->getRoutes()->{$m}(...)` のように変数越しに
 *     組み立てる書き方は**すり抜ける**。この gate は「うっかり」を止めるものであって、
 *     意図的な迂回を止めるものではない。
 *   - 検査 3 は **`booted(` の丸括弧の対応範囲に経路一覧のマーカーが在るか**しか見ない。
 *     フックの中から呼んだ別メソッドの中で経路を触る書き方は**すり抜ける**
 *     (検査 1 が「経路を引く実装は 2 binder だけ」を別軸で押さえている)。
 *   - 検査 5 は 2 binder が実行点の**名前を字句として持つ**ことまでである。
 *     実行点を経由せずに別の口から後付けしていないことは字句では決められない
 *     (分岐の契約は `tests/Unit/Support/Http/AfterRoutesLoadedTest.php` が振る舞いで固定する)。
 *
 * ★検査 1 / 検査 2 は**素の文字列走査**である (現在の出現は 3 ファイル・7 箇所のみ)。
 *   docblock 内の記述も検出対象になるため、allowlist 外のファイルで
 *   これらの識別子に**言及**するときは「メソッド名 + `(`」の形を避けること。
 *   検査 3 だけは `Tests\Support\PhpTokenScan` の正規化 (空白・コメントを落とす) の上に建てる —
 *   `booted(` は Model の `protected static function booted()` 定義と綴りが同じで、
 *   素の文字列走査では区別できないためである。
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

/**
 * 後付けの実行タイミングを決める唯一の実行点 (repo 相対)。
 *
 * 素の起動完了フックの直呼びの検査 (検査 3) からは**このファイルだけ**を外す。
 * 外すのは 1 件に限る (自分が禁じている形を自分が持つため)。
 */
const POST_BOOT_ROUTE_SCHEDULING_ENTRY = 'app/Support/Http/AfterRoutesLoaded.php';

/**
 * 実行点クラスの短名 (2 binder が字句として持つことを検査 5 が見る)。
 */
const POST_BOOT_ROUTE_SCHEDULING_ENTRY_NAME = 'AfterRoutesLoaded';

/**
 * 起動完了フックの呼び出しを表す名前トークン。
 */
const POST_BOOT_ROUTE_BOOTED_TOKEN = 'booted';

/**
 * 経路一覧に触ったことを示す名前トークン (検査 3 の内側判定)。
 *
 * @var list<string>
 */
const POST_BOOT_ROUTE_COLLECTION_MARKERS = [
    'getRoutes',
    'getByName',
    'refreshNameLookups',
    'setCompiledRoutes',
];

/**
 * 「経路一覧を触る素の起動完了フックの直呼び」を検出する (純関数)。
 *
 * `booted(` 自体は経路と無関係な遅延結線にも使うため一律禁止にはしない
 * (実例: `PasskeyServiceProvider` が `Route::bind` を起動完了後に張り替える。
 * あれは cached 起動でも走る必要があるので、実行点へ移すと壊れる)。
 * **経路一覧を触る `booted(` だけ**を対象にすることで、死んだ条件にも過剰検出にもしない。
 *
 * 判定:
 *  1. 名前トークン `booted` の直後が `(` であること
 *  2. その直前が `function` でないこと (Model の `protected static function booted()` 定義を外す)
 *  3. 対応する `)` までの範囲に経路一覧のマーカーの名前トークンが在ること
 *
 * @return list<string> 違反の説明 (空なら違反なし)
 */
function postBootRouteMutationDirectBootedViolations(string $label, string $source): array
{
    $tokens = PhpTokenScan::normalize($source);
    $count = count($tokens);
    $violations = [];

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['text'] !== POST_BOOT_ROUTE_BOOTED_TOKEN) {
            continue;
        }
        if (($tokens[$i + 1]['text'] ?? null) !== '(') {
            continue;
        }
        if (($tokens[$i - 1]['text'] ?? null) === 'function') {
            continue;   // Model の booted() 定義は対象外
        }

        $depth = 0;
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j]['text'] === '(') {
                $depth++;
            }
            if ($tokens[$j]['text'] === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if (in_array($tokens[$j]['text'], POST_BOOT_ROUTE_COLLECTION_MARKERS, true)) {
                $violations[] = "{$label}:{$tokens[$i]['line']} 経路一覧を触る素の起動完了フックの直呼びがある";
                break;
            }
        }
    }

    return $violations;
}

/**
 * `app/` 配下のソース (repo 相対パス => 中身)。
 *
 * @return array<string, string>
 */
function postBootRouteMutationSources(): array
{
    $sources = [];

    foreach (postBootRouteMutationScanTargets() as $relative) {
        $source = file_get_contents(base_path().'/'.$relative);
        if ($source === false) {
            // 読めないファイルを黙って落とすと走査が縮む (fail-closed)。
            throw new RuntimeException('走査対象を読めなかった: '.$relative);
        }

        $sources[$relative] = $source;
    }

    return $sources;
}

/*
 * 検査 3: 実行点を迂回した素の起動完了フックが無いこと。
 *
 * 素の起動完了フックは **cached routes の読み込みより先に走る**ため、そこで経路名の
 * fail-fast を行うと route:cache 済みの起動が丸ごと落ちる (route:list も route:clear も
 * 落ちて復旧手段まで失う = T120)。実行点 (`AfterRoutesLoaded`) は cached 起動では
 * callback を呼ばないので、その形を作れない。
 */
test('経路一覧を触る素の起動完了フックの直呼びが app/ 配下に無い (実行点の迂回禁止)', function (): void {
    $violations = [];

    foreach (postBootRouteMutationSources() as $relative => $source) {
        if ($relative === POST_BOOT_ROUTE_SCHEDULING_ENTRY) {
            continue;
        }

        $violations = array_merge(
            $violations,
            postBootRouteMutationDirectBootedViolations($relative, $source),
        );
    }

    expect($violations)->toBe([], implode("\n", [
        '経路一覧を触る素の起動完了フックの直呼びがあります:',
        '  '.implode("\n  ", $violations),
        '',
        'App\Support\Http\AfterRoutesLoaded::schedule() を使うこと。',
        '素の起動完了フックは cached routes の読み込みより先に走るため、',
        'そこで経路名の fail-fast を行うと route:cache 済みの起動が丸ごと落ちる。',
    ]));
});

/*
 * 検査 4: 空振り green の排除 (実行点が実体を持っていること)。
 */
test('実行点クラスが実際に起動完了フックを持つ (空振り green の排除)', function (): void {
    $sources = postBootRouteMutationSources();

    expect(array_key_exists(POST_BOOT_ROUTE_SCHEDULING_ENTRY, $sources))->toBeTrue(
        '実行点クラスが実在しません: '.POST_BOOT_ROUTE_SCHEDULING_ENTRY,
    );

    $tokens = PhpTokenScan::normalize($sources[POST_BOOT_ROUTE_SCHEDULING_ENTRY]);
    $found = false;
    foreach ($tokens as $index => $token) {
        if ($token['text'] === POST_BOOT_ROUTE_BOOTED_TOKEN && ($tokens[$index + 1]['text'] ?? null) === '(') {
            $found = true;
            break;
        }
    }

    expect($found)->toBeTrue(
        '実行点クラスが起動完了フックを呼んでいません。実装が変わったなら本検査群を読み直すこと '
        .'(検査 3 の除外が「見るものが無いから緑」へ化けるのを防ぐための表明)。',
    );
});

/*
 * 検査 5: 2 binder が実行点を経由していること (字句)。
 */
test('2 つの binder が実行点の名前を字句として持つ', function (): void {
    $sources = postBootRouteMutationSources();

    foreach (POST_BOOT_ROUTE_MUTATION_ALLOWLIST as $allowed) {
        expect(array_key_exists($allowed, $sources))->toBeTrue("allowlist の [{$allowed}] が実在しません");

        $referenced = false;
        foreach (PhpTokenScan::normalize($sources[$allowed]) as $token) {
            if ($token['text'] === POST_BOOT_ROUTE_SCHEDULING_ENTRY_NAME) {
                $referenced = true;
                break;
            }
        }

        expect($referenced)->toBeTrue(
            "[{$allowed}] が実行点 (".POST_BOOT_ROUTE_SCHEDULING_ENTRY_NAME.') を参照していません。'
            .'後付けの実行タイミングは実行点 1 つに委ねること。',
        );
    }
});

/*
 * 検査 3 の検出力 (負のコントロール)。検出側と非検出側の両方を固定する。
 */
test('負のコントロール: 経路一覧を触る起動完了フックの直呼びを検出する', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Providers;
    class Bad {
        public function boot(): void {
            $this->app->booted(function (): void {
                $routes = app('router')->getRoutes();
                $routes->getByName('foo')->middleware('throttle:bar');
            });
        }
    }
    PHP;

    expect(postBootRouteMutationDirectBootedViolations('fixture', $source))
        ->toHaveCount(1)
        ->and(implode("\n", postBootRouteMutationDirectBootedViolations('fixture', $source)))
        ->toContain('素の起動完了フックの直呼び');
});

test('負のコントロール: 経路一覧を触らない起動完了フックは検出しない (過剰検出を作らない)', function (): void {
    // `PasskeyServiceProvider` の Route::bind 差し替えがこの形である
    // (cached 起動でも走る必要があるので、実行点へ移してはならない)。
    $source = <<<'PHP'
    <?php
    namespace App\Providers;
    use Illuminate\Support\Facades\Route;
    class Ok {
        public function boot(): void {
            $this->app->booted(static function (): void {
                Route::bind('passkey', 'App\Http\Routing\SelfScopedPasskeyBinder');
            });
        }
    }
    PHP;

    expect(postBootRouteMutationDirectBootedViolations('fixture', $source))->toBe([]);
});

test('負のコントロール: Model の booted() 定義は検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Models;
    class Thing {
        protected static function booted(): void {
            static::creating(static function (self $model): void {
                $model->getRoutes();
            });
        }
    }
    PHP;

    expect(postBootRouteMutationDirectBootedViolations('fixture', $source))->toBe([]);
});

test('負のコントロール: コメントの中の記述は検出しない', function (): void {
    $source = <<<'PHP'
    <?php
    namespace App\Providers;
    class Documented {
        // $this->app->booted(fn () => $router->getRoutes()) は書かないこと。
        /** 素の booted( の中で getByName( を呼ぶと cached 起動が落ちる。 */
        public function boot(): void {}
    }
    PHP;

    expect(postBootRouteMutationDirectBootedViolations('fixture', $source))->toBe([]);
});
