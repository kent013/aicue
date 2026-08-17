<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Http\FlashNotificationRelay;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/*
 * 「Inertia がユーザーへ届ける通知 flash キー」の単一出典 (SoT) ずれ検査。
 *
 * flash キー集合は「書き手 (controller/middleware の ->with()) / 中継 (FlashNotificationRelay) /
 * 共有 (HandleInertiaRequests) / 画面側の読み手 (flash-to-toast.ts)」の 4 箇所に現れる。
 * SoT (FlashNotificationRelay::NOTIFICATION_KEYS) と食い違うと、跳ね返りの中継から
 * 漏れた通知が中間 redirect で失効する (「押しても何も起きない」の再発)、または
 * 打ち間違いのキー ('succes' 等) の通知が誰にも読まれず消える。本検査は PHP 側 3 箇所を固定し、
 * 画面側の読み手との一致は tests/js/architecture/flash-keys-sync.test.ts が固定する。
 * 順序は契約にしない (集合として比較)。
 *
 * **母集団を広げて言わない**: 2 本目が見るのは「app/ の flash 書き手すべて」ではなく
 * 「走査器が拾えるリテラル書き手」である。動的キー (定数・変数経由) と `[a-z_]+` 以外の
 * キー (camelCase) は母集団に入らない。
 */

it('Inertia が共有する flash キー集合が FlashNotificationRelay の SoT と一致する', function (): void {
    $request = Request::create('/');
    $request->setLaravelSession(app('session.store'));

    $shared = app(HandleInertiaRequests::class)->share($request);
    $flash = $shared['flash'];
    Assert::isArray($flash);

    $actual = array_values(array_diff(array_keys($flash), ['visitKey']));
    $expected = FlashNotificationRelay::NOTIFICATION_KEYS;
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

/**
 * flash 書き手走査の allowlist (通知 toast ではない flash キー)。
 *
 * **初期件数 = 1**。「ユーザー通知」をここへ足してはならない (NOTIFICATION_KEYS へ足す)。
 * 増え始めたら内部状態の flash の SoT を別に立てるサインとして扱うこと。
 *
 * @return array<string, string> key => 除外理由
 */
function flashWriterScanAllowlist(): array
{
    return [
        'new_api_key' => 'API キー平文の発行直後 1 度きり表示に使う内部状態の flash (OrganizationApiKeyController)。ユーザー通知の toast ではなく、中継で延命してもならない',
    ];
}

/**
 * PHP ソースを「意味のあるトークン列」に落とす (空白・コメントを除去)。
 * 文字列リテラルは flash キーそのものなので残す。
 *
 * @return list<array{0: int, 1: string}> [token id (リテラルは -1), text]
 */
function flashWriterSignificantTokens(string $source): array
{
    $out = [];
    foreach (token_get_all($source) as $token) {
        if (is_string($token)) {
            $out[] = [-1, $token];

            continue;
        }
        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG], true)) {
            continue;
        }
        $out[] = [$token[0], $token[1]];
    }

    return $out;
}

/**
 * `$start` の開き記号に対応する閉じ記号の index を返す (無ければ null)。
 *
 * @param  list<array{0: int, 1: string}>  $tokens
 */
function flashWriterMatchingIndex(array $tokens, int $start, string $open, string $close): ?int
{
    $depth = 0;
    $count = count($tokens);
    for ($i = $start; $i < $count; $i++) {
        if ($tokens[$i][1] === $open) {
            $depth++;

            continue;
        }
        if ($tokens[$i][1] !== $close) {
            continue;
        }
        $depth--;
        if ($depth === 0) {
            return $i;
        }
    }

    return null;
}

/**
 * flash キーとして扱う文字列リテラルなら中身を返す (`[a-z_]+` 限定。
 * view composer の `seoHead` のような camelCase キーは対象外)。
 *
 * @param  array{0: int, 1: string}  $token
 */
function flashWriterLiteralKey(array $token): ?string
{
    if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }
    $key = trim($token[1], "'\"");

    return preg_match('/^[a-z_]+$/', $key) === 1 ? $key : null;
}

/**
 * `$withIndex` の呼び出しが属する文が `back()` / `redirect(` / `to_route(` を
 * 起点とするチェーンかを判定する (文頭 = 直前の `;` `{` `}` まで遡る)。
 *
 * 素のヘルパ呼び出しのみを起点と認める: 同名のメソッド/静的呼び出し
 * (`$driver->redirect()` / `Foo::redirect()`) は直前トークンが `->` / `?->` / `::` に
 * なるため棄却する (Socialite の `$driver->redirect()` チェーン等での誤検知を防ぐ)。
 *
 * @param  list<array{0: int, 1: string}>  $tokens
 */
function flashWriterStatementHasRedirectRoot(array $tokens, int $withIndex): bool
{
    for ($i = $withIndex - 1; $i >= 0; $i--) {
        $text = $tokens[$i][1];
        if (in_array($text, [';', '{', '}'], true)) {
            return false;
        }
        if ($tokens[$i][0] !== T_STRING
            || ! in_array($text, ['back', 'redirect', 'to_route'], true)
            || ($tokens[$i + 1][1] ?? '') !== '(') {
            continue;
        }
        $prev = $tokens[$i - 1] ?? null;
        if ($prev !== null && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            continue;
        }

        return true;
    }

    return false;
}

/**
 * 配列リテラルの「深さ 1」(= 外側の配列直下) にある flash キーを取り出す。
 * ネストした値配列の内側キー (`['meta' => ['reason' => …]]` の `reason`) は拾わない。
 *
 * @param  list<array{0: int, 1: string}>  $tokens
 * @return list<string>
 */
function flashWriterDepth1ArrayKeys(array $tokens, int $from, int $to): array
{
    $keys = [];
    $depth = 0;
    for ($i = $from; $i <= $to; $i++) {
        $text = $tokens[$i][1];
        if ($text === '[') {
            $depth++;

            continue;
        }
        if ($text === ']') {
            $depth = max(0, $depth - 1);

            continue;
        }
        if ($depth !== 1) {
            continue;
        }
        $next = $tokens[$i + 1] ?? null;
        if ($next === null || $next[0] !== T_DOUBLE_ARROW) {
            continue;
        }
        $key = flashWriterLiteralKey($tokens[$i]);
        if ($key !== null) {
            $keys[] = $key;
        }
    }

    return $keys;
}

/**
 * ソース 1 ファイル分から flash 書き手のキーをトークン走査で収集する (自己検証テストの対象)。
 *
 * 収集する形 (ユーザー通知 flash になり得る書き込み):
 *  - `->with('key', $value)` — redirect チェーン。第 2 引数必須で拾い、単一引数の
 *    Eloquent eager load (`->with('relation')`) は拾わない。第 2 引数が closure
 *    (`fn` / `function` / `static`) で始まる場合は constrained eager load とみなし除外する
 *  - `->with(['key' => $value, ...])` — 連想配列形の一括 flash。**同一文が `back()` /
 *    `redirect(` / `to_route(` を起点とするチェーンのときだけ**、配列の深さ 1 のキーのみ拾う
 *    (Socialite の `$driver->with(['prompt' => ...])` のような flash ではない fluent API と、
 *    ネスト値配列の内側キーを誤収集しないため)
 *  - `->flash('key', ...)` / `::flash('key', ...)` — session への直書き・facade 形
 *    (receiver を問わない)
 *
 * コメント・空白はトークン化で除去済み。動的キー (変数・定数経由) と、変数に受けた
 * RedirectResponse への後付け `with([...])` は検出できない (取りこぼす側に倒した網。
 * scalar 形は receiver に依らず捕捉する)。
 *
 * @return list<string>
 */
function flashWriterKeysFromSource(string $source): array
{
    $tokens = flashWriterSignificantTokens($source);
    $keys = [];
    $count = count($tokens);

    for ($i = 1; $i < $count; $i++) {
        if ($tokens[$i][0] !== T_STRING) {
            continue;
        }
        $name = $tokens[$i][1];
        $prev = $tokens[$i - 1];
        $open = $i + 1;
        if (($tokens[$open][1] ?? '') !== '(') {
            continue;
        }

        if ($name === 'with' && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            $first = $tokens[$open + 1] ?? null;
            if ($first === null) {
                continue;
            }

            // 連想配列形: back()/redirect(/to_route( 起点の文のみ、深さ 1 のキーを拾う
            if ($first[1] === '[') {
                if (! flashWriterStatementHasRedirectRoot($tokens, $i - 1)) {
                    continue;
                }
                $close = flashWriterMatchingIndex($tokens, $open + 1, '[', ']');
                if ($close === null) {
                    continue;
                }
                $keys = [...$keys, ...flashWriterDepth1ArrayKeys($tokens, $open + 1, $close)];

                continue;
            }

            // scalar 形: ->with('key', <closure 以外>)
            $key = flashWriterLiteralKey($first);
            if ($key === null || ($tokens[$open + 2][1] ?? '') !== ',') {
                continue;
            }
            $secondArg = $tokens[$open + 3] ?? null;
            if ($secondArg !== null && in_array($secondArg[0], [T_FN, T_STATIC, T_FUNCTION], true)) {
                continue;
            }
            $keys[] = $key;

            continue;
        }

        if ($name === 'flash' && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            $key = flashWriterLiteralKey($tokens[$open + 1] ?? [-1, '']);
            if ($key !== null) {
                $keys[] = $key;
            }
        }
    }

    return array_values(array_unique($keys));
}

it('flash 書き手のキーはすべて NOTIFICATION_KEYS か理由付き allowlist に属する', function (): void {
    $root = dirname(__DIR__, 2);
    $allowlist = flashWriterScanAllowlist();

    /** @var array<string, list<string>> $violations キー => 由来ファイル */
    $violations = [];
    /** @var iterable<SplFileInfo> $it */
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        Assert::string($source);

        foreach (flashWriterKeysFromSource($source) as $key) {
            if (in_array($key, FlashNotificationRelay::NOTIFICATION_KEYS, true) || array_key_exists($key, $allowlist)) {
                continue;
            }
            $violations[$key][] = ltrim(str_replace($root, '', $file->getPathname()), '/');
        }
    }

    ksort($violations);
    $lines = [];
    foreach ($violations as $key => $files) {
        $lines[] = sprintf("  '%s'  // %s", $key, implode(', ', array_unique($files)));
    }

    expect($lines)->toBe(
        [],
        "NOTIFICATION_KEYS にも writer allowlist にも無い flash キーの書き手:\n".implode("\n", $lines)
        ."\nユーザー通知なら FlashNotificationRelay::NOTIFICATION_KEYS へ、内部状態の flash なら理由付きで allowlist へ登録すること"
    );
});

it('writer 走査の allowlist は理由付きで初期 1 件から増えていない', function (): void {
    $allowlist = flashWriterScanAllowlist();

    expect($allowlist)->toHaveCount(1);
    foreach ($allowlist as $key => $reason) {
        expect($reason)->not->toBe('', "allowlist の {$key} に理由がない");
    }
});

describe('writer 走査ロジックの自己検証', function (): void {
    it('正例: ->with の scalar 形 / 連想配列形 / flash / facade 形をすべて拾う', function (): void {
        $source = <<<'PHP'
        <?php
        return back()->with('success', 'saved')
            ->with(['error' => 'x', 'info' => 'y']);
        $request->session()->flash('warning', 'w');
        $session->flash('custom_state', 'v');
        Session::flash('facade_key', 'z');
        PHP;

        expect(flashWriterKeysFromSource($source))
            ->toBe(['success', 'error', 'info', 'warning', 'custom_state', 'facade_key']);
    });

    it('正例: redirect()/to_route() 起点チェーンの連想配列形 with も拾う', function (): void {
        $source = <<<'PHP'
        <?php
        return redirect()->route('dashboard')->with(['success' => 'x']);
        return to_route('billing.index')->with(['redirect_array_key' => 'y']);
        PHP;

        expect(flashWriterKeysFromSource($source))->toBe(['success', 'redirect_array_key']);
    });

    it('連想配列形は深さ 1 のキーのみ拾う (ネスト値配列の内側キーは拾わない)', function (): void {
        $source = <<<'PHP'
        <?php
        return back()->with(['status' => 'ok', 'meta' => ['reason' => 'x']]);
        PHP;

        expect(flashWriterKeysFromSource($source))->toBe(['status', 'meta']);
    });

    it('負例: 単一引数の eager load / closure 第 2 引数の constrained eager load は拾わない', function (): void {
        $source = <<<'PHP'
        <?php
        $q->with('organization');
        $q->with('user', fn ($query) => $query->active());
        $q->with('items', static fn ($query) => $query->latest());
        $q->with('project', function ($query) { return $query; });
        PHP;

        expect(flashWriterKeysFromSource($source))->toBe([]);
    });

    it('負例: コメント内の flash 書き込みは拾わない', function (): void {
        $source = <<<'PHP'
        <?php
        // back()->with('commented_key', 'x') は既定の形
        /* Session::flash('doc_key', 'y') */
        /** `->with('docblock_key', $v)` の説明 */
        return back()->with('success', 'real');
        PHP;

        expect(flashWriterKeysFromSource($source))->toBe(['success']);
    });

    it('負例: camelCase キー (view composer の seoHead 等) は対象外', function (): void {
        $source = <<<'PHP'
        <?php
        $view->with('seoHead', $html);
        PHP;

        expect(flashWriterKeysFromSource($source))->toBe([]);
    });

    it('負例: back()/redirect(/to_route( 起点でない連想配列形 with (Socialite の OAuth 引数等) は拾わない', function (): void {
        $source = <<<'PHP'
        <?php
        $driver->with(['prompt' => 'login']);
        PHP;

        expect(flashWriterKeysFromSource($source))->toBe([]);
    });

    it('負例: 同名メソッド呼び出し ($driver->redirect() 等) は起点と誤認しない', function (): void {
        $source = <<<'PHP'
        <?php
        return $driver->redirect()->with(['prompt' => 'login']);
        return Foo::redirect()->with(['static_key' => 'x']);
        PHP;

        expect(flashWriterKeysFromSource($source))->toBe([]);
    });
});
