<?php

declare(strict_types=1);

use Tests\Support\PhpReferenceScanner;

/*
 * 「文言つきの 404 を投げていない」ことの**変更検知** (T158 / bug-hunt F-1-03)。
 *
 * T158 は JSON 404 の message を固定文言へ collapse する。つまり **404 に載せた文言は
 * JSON 経路では捨てられる**。いま文言つきの 404 が 0 件だからこそ「捨てても情報が失われない」
 * と言えるので、その前提が変わったら気づけるようにする。
 *
 * ★保証範囲を誇張しない: これは**列挙した直接記法の変更検知**であって、
 *   collapse の安全性の証明ではない (安全性は tests/Feature/Errors/JsonNotFoundMessageTest.php)。
 *   変数経由・動的生成・helper 経由の 404 は捕捉できない。
 *
 * ★実装は token ベース (正規表現へフォールバックしない)。named argument の引数順不同・
 *   複数行にまたがる呼び出し・ネストした引数式を構文的に扱い、
 *   コメントや文字列リテラル中の疑似コードは拾わない (normalize が comment を落とす)。
 *
 * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
 * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**走査根の非空が不変条件**である。
 * 3 本の走査根 (app / routes / bootstrap) はどれか 1 本が改名・移動しても、
 * 「文言つきの 404 は 1 件も無い」は違反ゼロのまま緑になる。
 * 「空振り検査」ケースが 3 本すべての生存 (実在かつ PHP ファイルを持つ) を固定し、
 * その直後の負のコントロールが「根を差し替えると母集団が空になる」ことを示す。
 * 検出器そのものの裏取りは末尾の記法ごとの正例 / 負例が担当する。
 */

/**
 * 呼び出しの引数を「トップレベルのカンマ」で分割して返す。
 *
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 * @param  int  $openIndex  `(` の位置
 * @return list<list<array{id: int|null, text: string, line: int}>>
 */
function messageCarrying404Arguments(array $tokens, int $openIndex): array
{
    $depth = 0;
    $current = [];
    $arguments = [];

    for ($i = $openIndex, $count = count($tokens); $i < $count; $i++) {
        $text = $tokens[$i]['text'];

        if (in_array($text, ['(', '[', '{'], true)) {
            $depth++;
            if ($depth === 1) {
                continue; // 呼び出しの `(` 自体は引数に含めない
            }
        }

        if (in_array($text, [')', ']', '}'], true)) {
            $depth--;
            if ($depth === 0) {
                if ($current !== []) {
                    $arguments[] = $current;
                }

                return $arguments;
            }
        }

        if ($depth === 1 && $text === ',') {
            $arguments[] = $current;
            $current = [];

            continue;
        }

        $current[] = $tokens[$i];
    }

    return $arguments;
}

/**
 * 引数列から「404 を status として渡し、かつ非空の message を渡している」かを判定する。
 *
 * named argument (`code:` / `statusCode:` / `message:`) は順不同で扱い、
 * 位置引数は helper ごとの位置で見る。
 *
 * @param  list<list<array{id: int|null, text: string, line: int}>>  $arguments
 * @param  int  $statusPosition  位置引数における status の index
 * @param  int  $messagePosition  位置引数における message の index
 */
function messageCarrying404Detected(array $arguments, int $statusPosition, int $messagePosition): bool
{
    $status = null;
    $message = null;
    $positional = 0;

    foreach ($arguments as $argument) {
        if ($argument === []) {
            continue;
        }

        // named argument は `name` `:` で始まる (T_STRING の直後に ':')
        if (count($argument) >= 2 && $argument[0]['id'] === T_STRING && $argument[1]['text'] === ':') {
            $name = $argument[0]['text'];
            $value = array_slice($argument, 2);
            if (in_array($name, ['code', 'statusCode', 'status'], true)) {
                $status = $value;
            }
            if ($name === 'message') {
                $message = $value;
            }

            continue;
        }

        if ($positional === $statusPosition) {
            $status = $argument;
        }
        if ($positional === $messagePosition) {
            $message = $argument;
        }
        $positional++;
    }

    if ($status === null || count($status) !== 1 || $status[0]['text'] !== '404') {
        return false;
    }

    if ($message === null || $message === []) {
        return false;
    }

    // 空文字リテラルは「文言なし」と同じなので拾わない
    if (count($message) === 1 && in_array($message[0]['text'], ["''", '""'], true)) {
        return false;
    }

    return true;
}

/**
 * 走査根 (リポジトリ相対パス)。
 *
 * @return list<string>
 */
function messageCarrying404Roots(): array
{
    return ['app', 'routes', 'bootstrap'];
}

/**
 * 走査対象ディレクトリの PHP ファイルから、文言つき 404 の site を集める。
 *
 * @return list<string>
 */
function messageCarrying404Sites(): array
{
    /** 呼び出し名 => [status の位置, message の位置] */
    $helpers = [
        'abort' => [0, 1],
        'abort_if' => [1, 2],
        'abort_unless' => [1, 2],
    ];
    /** クラス名 => [status の位置, message の位置] (new 経由) */
    $classes = [
        'HttpException' => [0, 1],
        'NotFoundHttpException' => [-1, 0], // status を取らない = message が第 1 引数
    ];

    $sites = [];

    foreach (messageCarrying404Roots() as $root) {
        foreach (PhpReferenceScanner::phpFiles(base_path($root), $root) as $relativePath => $source) {
            $tokens = PhpReferenceScanner::tokens($source);

            for ($i = 0, $count = count($tokens); $i < $count; $i++) {
                $text = $tokens[$i]['text'];
                $next = $tokens[$i + 1]['text'] ?? '';

                if ($next !== '(') {
                    continue;
                }

                if (isset($helpers[$text]) && $tokens[$i]['id'] === T_STRING) {
                    [$statusPosition, $messagePosition] = $helpers[$text];
                    $arguments = messageCarrying404Arguments($tokens, $i + 1);
                    if (messageCarrying404Detected($arguments, $statusPosition, $messagePosition)) {
                        $sites[] = "{$relativePath}:{$tokens[$i]['line']} {$text}";
                    }

                    continue;
                }

                if (! isset($classes[$text]) || ($tokens[$i - 1]['id'] ?? null) !== T_NEW) {
                    continue;
                }

                [$statusPosition, $messagePosition] = $classes[$text];
                $arguments = messageCarrying404Arguments($tokens, $i + 1);

                if ($statusPosition === -1) {
                    // NotFoundHttpException は status を取らないので message の非空だけを見る
                    $first = $arguments[0] ?? [];
                    if ($first !== [] && ! (count($first) === 1 && in_array($first[0]['text'], ["''", '""'], true))) {
                        $sites[] = "{$relativePath}:{$tokens[$i]['line']} new {$text}";
                    }

                    continue;
                }

                if (messageCarrying404Detected($arguments, $statusPosition, $messagePosition)) {
                    $sites[] = "{$relativePath}:{$tokens[$i]['line']} new {$text}";
                }
            }
        }
    }

    return $sites;
}

test('文言つきの 404 は 1 件も無い (JSON 経路では collapse され失われるため)', function (): void {
    expect(messageCarrying404Sites())->toBe([]);
});

test('空振り検査: 3 本の走査根がいずれも生きている (実在し PHP ファイルを持つ)', function (): void {
    foreach (messageCarrying404Roots() as $root) {
        $absolute = base_path($root);
        expect(is_dir($absolute))->toBeTrue("走査根 {$root} が存在しません");
        expect(PhpReferenceScanner::phpFiles($absolute, $root))
            ->not->toBe([], "走査根 {$root} に PHP ファイルがありません");
    }
});

test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
    // 上の生存検査が空振りしていないことの裏取り。走査根の改名・移動を模して
    // 実在しないパスを渡すと母集団が 0 件になる = 生存検査が赤くなる。
    expect(PhpReferenceScanner::phpFiles(base_path('app-renamed'), 'app'))->toBe([]);
    expect(PhpReferenceScanner::phpFiles(base_path('bootstrap-renamed'), 'bootstrap'))->toBe([]);
});

/**
 * 与えたソースから、検出した site を「記法ラベル => 行番号の配列」で返す (自己検査用)。
 *
 * @return array<string, list<int>>
 */
function messageCarrying404DetectInSource(string $source): array
{
    $helpers = ['abort' => [0, 1], 'abort_if' => [1, 2], 'abort_unless' => [1, 2]];
    $classes = ['HttpException' => [0, 1], 'NotFoundHttpException' => [-1, 0]];

    $tokens = PhpReferenceScanner::tokens($source);
    $detected = [];

    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        $text = $tokens[$i]['text'];
        if (($tokens[$i + 1]['text'] ?? '') !== '(') {
            continue;
        }

        if (isset($helpers[$text]) && $tokens[$i]['id'] === T_STRING) {
            [$statusPosition, $messagePosition] = $helpers[$text];
            if (messageCarrying404Detected(messageCarrying404Arguments($tokens, $i + 1), $statusPosition, $messagePosition)) {
                $detected[$text][] = $tokens[$i]['line'];
            }

            continue;
        }

        if (! isset($classes[$text]) || ($tokens[$i - 1]['id'] ?? null) !== T_NEW) {
            continue;
        }

        [$statusPosition, $messagePosition] = $classes[$text];
        $arguments = messageCarrying404Arguments($tokens, $i + 1);

        if ($statusPosition === -1) {
            $first = $arguments[0] ?? [];
            if ($first !== [] && ! (count($first) === 1 && in_array($first[0]['text'], ["''", '""'], true))) {
                $detected["new {$text}"][] = $tokens[$i]['line'];
            }

            continue;
        }

        if (messageCarrying404Detected($arguments, $statusPosition, $messagePosition)) {
            $detected["new {$text}"][] = $tokens[$i]['line'];
        }
    }

    return $detected;
}

test('走査器は %s を検出する (自己検査。件数ではなく記法ごとに固定する)', function (string $label, string $snippet, bool $expected): void {
    // 検出器が壊れて常に空を返すと上のテストが無意味になるため、**記法ごとに**負例/正例を当てる。
    // 件数だけの assert では「どの記法を取りこぼしたか」が分からない (Round 1 [Warning])。
    $source = "<?php\n".$snippet."\n";

    $detected = messageCarrying404DetectInSource($source);

    expect($detected !== [])->toBe($expected);
})->with([
    // 拾うべき記法
    ['abort の位置引数', "abort(404, 'これは文言つき');", true],
    ['abort の named argument', "abort(404, message: 'named');", true],
    ['abort の複数行 + ネスト引数', "abort(\n    404,\n    message: sprintf('%s は不在', \$name),\n);", true],
    ['abort_if', "abort_if(\$missing, 404, '不在');", true],
    ['abort_unless', "abort_unless(\$found, 404, '不在');", true],
    ['new HttpException の named 順不同', "new HttpException(message: 'x', statusCode: 404);", true],
    ['new NotFoundHttpException', "new NotFoundHttpException('内部の説明');", true],
    // 拾ってはいけないもの
    ['文言なしの abort', 'abort(404);', false],
    ['別 status の abort', "abort(403, '別 status は対象外');", false],
    ['空文字の message', "abort(404, '');", false],
    ['コメント内の疑似コード', "// abort(404, 'コメント内は拾わない');", false],
    ['文字列リテラル内の疑似コード', "\$s = \"abort(404, 'literal')\";", false],
    ['status を取らない new HttpException (message 空)', 'new HttpException(404);', false],
]);
