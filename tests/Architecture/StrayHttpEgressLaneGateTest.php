<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\Security\StrayHttpEgressExemption;
use Tests\Support\StrayHttpRequestGuard;

/*
 * Architecture invariant: テストレーンの HTTP 出口が既定拒否であること (deny-by-default)。
 *
 * 背景 (SoT = devnotes/20260807-1235-stray-http-egress-deny/conceptual-design.md):
 * 裁定 AG-105 は「テストレーンの既定として Http::preventStrayRequests() を常時有効にする」
 * を必須とし、「テスト内で局所的に張って外す形は既定と認めない」と明示している。
 * 本 gate は tests/Pest.php をソース走査して**レーン既定であること**を機械強制する。
 *
 * ★解析は PhpToken でコメントを落としてから行う。文字列 grep にすると
 *   「本 gate の説明コメント」自身や tests/Pest.php の日本語コメントで偽緑になる
 *   (PcreUnicodeModifierGateTest / GlobalTestLockInventoryTest と同じ作法)。
 *
 * ★本 gate は「素の main では赤にならない」種類のテストである。空振りしていないことは
 *   (a) fixture ベースの負のコントロール (下部) と
 *   (b) 実装時の mutation 手順 (詳細設計 S4 §mutation) の 2 本で担保する。
 */

/** 既定配線が必須のレーン。 */
const STRAY_HTTP_EGRESS_REQUIRED_LANES = ['Feature', 'Unit', 'Architecture', 'Browser'];

/** opt-out 根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const STRAY_HTTP_EGRESS_REASON_MIN_LENGTH = 30;

/**
 * exemption 件数の上限。**現在値ちょうど** (exact fit)。
 * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに opt-out できる枠」
 *   になる。exact fit なら次の 1 本が必ずこの数値を変える差分として現れる。
 */
const STRAY_HTTP_EGRESS_EXEMPTION_CAP = 1;

/**
 * 走査対象から外すファイル (走査器自身)。
 * ★本 gate は検査語 (`allowStrayRequests` 等) をパターン文字列として持つため、
 *   自分を走査すると必ず自己一致する。GlobalTestLockInventoryTest が
 *   「ライブラリ本体は対象外」としたのと同じ扱い。
 */
const STRAY_HTTP_EGRESS_SCANNER_SELF = 'tests/Architecture/StrayHttpEgressLaneGateTest.php';

/**
 * userinfo 詐称で loopback を騙る URL (実測で許可パターンに glob 一致するもの)。
 * ★`http://127.0.0.1:80@api.frankfurter.dev/` は userinfo が `127.0.0.1:80` で
 *   **実ホストは `api.frankfurter.dev`**。guard の第 2 層がこれを stray に落とす契約。
 */
const STRAY_HTTP_EGRESS_SMUGGLED_URLS = [
    'http://127.0.0.1:80@api.frankfurter.dev/',
    'https://127.0.0.1:443@api.frankfurter.dev/v1/latest',
    'http://localhost:9@evil.example/x',
    'https://localhost:1@169.254.169.254/latest/meta-data/',
    // ★`http://[::1]:1@evil.example/` は**そもそも URI としてパースできない**ため入れない
    //   (Guzzle Uri が "Unable to parse URI" を投げる = リクエストを組み立てられない)。
    //   すなわち `[::1]:*` パターン経由の userinfo 詐称は到達不能である。
];

/**
 * opt-out 呼び出しを持つことが正しいと裁定したファイルの inventory
 * (型付き + 具体的根拠必須、単一 source of truth)。
 *
 * @return array<string, array{StrayHttpEgressExemption, non-empty-string}>
 */
function strayHttpEgressOptOutExemptions(): array
{
    return [
        'tests/Support/StrayHttpRequestGuard.php' => [
            StrayHttpEgressExemption::GuardDefinitionSite,
            'レーン既定 guard 本体。Http::allowStrayRequests() を呼ぶのは ALLOWED_URL_PATTERNS '
            .'(loopback リテラルのみ) を設定するためであり、allowStrayRequests(null) や '
            .'preventStrayRequests(false) は呼ばない = 既定拒否そのものは外していない。',
        ],
    ];
}

/**
 * PHP ソースを **トークン列** へ落とす (純関数)。以降の解析はすべてこの列の上で行う。
 *
 * `PhpToken::tokenize()` した結果から `T_COMMENT` / `T_DOC_COMMENT` を取り除くだけ
 * (空白は保持する — 位置関係の判定には使わないが、抜き出した本体を人間が読める形で
 *  エラーメッセージに載せるため)。
 *
 * ★**文字列 grep も、正規化した文字列に対する括弧カウントもやめた**。
 *   文字列に落とす方式は (a) literal 中の括弧で対応を誤認する、(b) literal 中の
 *   `function` をキーワードと誤認する、(c) 名前と `(` の間の空白/コメントで判定を外す、
 *   という 3 種類の穴を**個別に塞ぎ続ける**必要がある。トークン列で扱えば
 *   文字列の中身は文字列系トークンの内側に保持され、構文上の補間境界は専用トークン
 *   (`T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES`) で識別できる。
 *   キーワードは `T_FUNCTION` / `T_STATIC` の**トークン ID** で一意に判定でき、
 *   空白は「有意トークン」を辿るだけで自然に飛ばせる。穴の種類が構造的に消える。
 *
 * @return list<PhpToken>
 */
function strayHttpEgressTokens(string $source): array
{
    return array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->is([T_COMMENT, T_DOC_COMMENT]),
    ));
}

/**
 * `$from` 以降で最初の**有意トークン** (`T_WHITESPACE` 以外) の index を返す (純関数)。
 *
 * @param  list<PhpToken>  $tokens
 */
function strayHttpEgressNextSignificant(array $tokens, int $from): ?int
{
    $total = count($tokens);
    for ($i = max($from, 0); $i < $total; $i++) {
        if (! $tokens[$i]->is(T_WHITESPACE)) {
            return $i;
        }
    }

    return null;
}

/**
 * `$openIndex` (開き括弧のトークン index) に対応する閉じ括弧の index を返す (純関数)。
 * トークン列上で深度を数えるため、文字列**内容**の括弧は文字列系トークンの内側にあり影響しない。
 *
 * ★波括弧 (`{` / `}`) を数えるときは、**補間の開始トークンも開始側に含める**:
 *
 *     $token->text === '{' || $token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)
 *
 *   補間の**終端は必ず単独の `}` トークン**であるのに対し、**開始側は 2 種類の専用トークン**に
 *   分かれる。開始側を数え落とすと深度が片側だけ減り、**closure の終端を早く見つけてしまう**。
 *
 *   ★実測 (PHP 8.4.24) で確認した `text` の値 — ここが判断の分かれ目なので事実を残す:
 *
 *     "value={$json}"  → T_ENCAPSED_AND_WHITESPACE("value=") / T_CURLY_OPEN("{")
 *                        / T_VARIABLE("$json") / }("}")
 *     "value=${json}"  → T_ENCAPSED_AND_WHITESPACE("value=") / T_DOLLAR_OPEN_CURLY_BRACES("${")
 *                        / T_STRING_VARNAME("json") / }("}")
 *
 *   すなわち `T_CURLY_OPEN` の `text` は `"{"` なので `text === '{'` でも偶然拾えるが、
 *   `T_DOLLAR_OPEN_CURLY_BRACES` の `text` は `"${"` で拾えない。実際に深度が壊れるのは
 *   後者 (`"${json}"` 形) である。前者を id でも判定するのは、text 一致に依存した暗黙の
 *   前提を契約から消すため (将来 `text` の表現が変わっても壊れない)。
 *
 *   終了側 (単独 `}`) は通常どおり深度を 1 減らすだけでよい。
 *   丸括弧 (`(` / `)`) の探索ではこの追加処理を行わない (補間に丸括弧の専用トークンは無い)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  non-empty-string  $open  `(` または `{`
 * @param  non-empty-string  $close  `)` または `}`
 */
function strayHttpEgressMatchingIndex(array $tokens, int $openIndex, string $open, string $close): ?int
{
    $braces = $open === '{';
    $depth = 0;
    $total = count($tokens);

    for ($i = $openIndex; $i < $total; $i++) {
        $token = $tokens[$i];

        if ($token->text === $open
            || ($braces && ($token->is(T_CURLY_OPEN) || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)))
        ) {
            $depth++;

            continue;
        }

        if ($token->text === $close) {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * トークン列を `pest()->extend(` 単位のチャンクへ分解する (純関数)。
 * レーン名は `->in(` の引数にある `T_CONSTANT_ENCAPSED_STRING` から取る
 * (文字列 grep ではなくトークンから取るので、コメント内の `->in('Feature')` に反応しない)。
 *
 * @param  list<PhpToken>  $tokens
 * @return list<array{lanes: list<string>, tokens: list<PhpToken>}>
 */
function strayHttpEgressLaneChunks(array $tokens): array
{
    $chunks = [];
    $total = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        if (! $tokens[$i]->is(T_STRING) || strtolower($tokens[$i]->text) !== 'pest') {
            continue;
        }
        $paren = strayHttpEgressNextSignificant($tokens, $i + 1);
        if ($paren === null || $tokens[$paren]->text !== '(') {
            continue;
        }

        // 文の終端 (深度 0 の `;`) までを 1 チャンクとする。
        // closure 本体の `;` は括弧/波括弧の内側にあるため深度 0 にならない。
        $depth = 0;
        $end = null;
        for ($j = $i; $j < $total; $j++) {
            $token = $tokens[$j];
            if (in_array($token->text, ['(', '{', '['], true)
                || $token->is(T_CURLY_OPEN)
                || $token->is(T_DOLLAR_OPEN_CURLY_BRACES)
            ) {
                $depth++;

                continue;
            }
            if (in_array($token->text, [')', '}', ']'], true)) {
                $depth--;

                continue;
            }
            if ($depth === 0 && $token->text === ';') {
                $end = $j;
                break;
            }
        }
        if ($end === null) {
            continue;
        }

        /** @var list<PhpToken> $chunkTokens */
        $chunkTokens = array_values(array_slice($tokens, $i, $end - $i + 1));
        $chunks[] = [
            'lanes' => strayHttpEgressLanesOf($chunkTokens),
            'tokens' => $chunkTokens,
        ];

        $i = $end;
    }

    return $chunks;
}

/**
 * チャンクの `->in('Feature', 'Unit')` からレーン名を取り出す (純関数)。
 *
 * @param  list<PhpToken>  $tokens
 * @return list<string>
 */
function strayHttpEgressLanesOf(array $tokens): array
{
    $total = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        if (! $tokens[$i]->is(T_OBJECT_OPERATOR)) {
            continue;
        }
        $name = strayHttpEgressNextSignificant($tokens, $i + 1);
        if ($name === null || ! $tokens[$name]->is(T_STRING) || $tokens[$name]->text !== 'in') {
            continue;
        }
        $paren = strayHttpEgressNextSignificant($tokens, $name + 1);
        if ($paren === null || $tokens[$paren]->text !== '(') {
            continue;
        }
        $close = strayHttpEgressMatchingIndex($tokens, $paren, '(', ')');
        if ($close === null) {
            continue;
        }

        $lanes = [];
        for ($j = $paren + 1; $j < $close; $j++) {
            if ($tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING)) {
                $lanes[] = trim($tokens[$j]->text, "'\"");
            }
        }

        return $lanes;
    }

    return [];
}

/**
 * chunk 内の `->{$hook}(...)` の**引数が直接 closure リテラルであること**を確認し、
 * その本体トークン列を返す (純関数)。確認できなければ **null を返して fail-closed** にする。
 *
 * 契約:
 *  1. `->` + `T_STRING($hook)` の並びを見つけ、その次の有意トークンが `(` であること。
 *  2. `(` の**次の有意トークン**が `T_FUNCTION`、または `T_STATIC` に続く `T_FUNCTION` であること。
 *     ★ここが要。「引数**内**のどこかにある `function` を拾う」実装だと
 *       `->beforeEach(wrap(function () { install(...); }))` を配線済みと誤認する。
 *  3. その `T_FUNCTION` に対応する closure 本体の `{` を
 *     `strayHttpEgressMatchingIndex()` で閉じ、本体トークン列を返す。
 *  4. closure の `}` の**次の有意トークン**が、1 で開いた `(` に対応する `)` であること
 *     (= 引数は closure ちょうど 1 個。カンマ区切りの追加引数は**許可しない**)。
 *
 * ★アロー関数 `fn () => …` は**受け付けない** (null を返す)。
 *   レーン配線は複数文 (install / flush + reset) を要するのでブロック本体が必須であり、
 *   2 つの closure 形を両方パースする価値が無い (今必要なものだけ作る)。
 *
 * @param  list<PhpToken>  $tokens  chunk のトークン列
 * @param  non-empty-string  $hook  'beforeEach' または 'afterEach'
 * @return list<PhpToken>|null
 */
function strayHttpEgressHookBody(array $tokens, string $hook): ?array
{
    $total = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        if (! $tokens[$i]->is(T_OBJECT_OPERATOR)) {
            continue;
        }
        $name = strayHttpEgressNextSignificant($tokens, $i + 1);
        if ($name === null || ! $tokens[$name]->is(T_STRING) || $tokens[$name]->text !== $hook) {
            continue;
        }

        $paren = strayHttpEgressNextSignificant($tokens, $name + 1);
        if ($paren === null || $tokens[$paren]->text !== '(') {
            return null;
        }
        $parenClose = strayHttpEgressMatchingIndex($tokens, $paren, '(', ')');
        if ($parenClose === null) {
            return null;
        }

        $head = strayHttpEgressNextSignificant($tokens, $paren + 1);
        if ($head === null) {
            return null;
        }
        if ($tokens[$head]->is(T_STATIC)) {
            $head = strayHttpEgressNextSignificant($tokens, $head + 1);
            if ($head === null) {
                return null;
            }
        }
        if (! $tokens[$head]->is(T_FUNCTION)) {
            return null;
        }

        // closure のシグネチャ (引数 / use / 戻り型) を読み飛ばし、本体の `{` を見つける。
        $bodyOpen = null;
        for ($j = $head + 1; $j < $parenClose; $j++) {
            if ($tokens[$j]->text === '{') {
                $bodyOpen = $j;
                break;
            }
        }
        if ($bodyOpen === null) {
            return null;
        }

        $bodyClose = strayHttpEgressMatchingIndex($tokens, $bodyOpen, '{', '}');
        if ($bodyClose === null) {
            return null;
        }

        // 引数は closure ちょうど 1 個であること (追加引数を許可しない)
        if (strayHttpEgressNextSignificant($tokens, $bodyClose + 1) !== $parenClose) {
            return null;
        }

        /** @var list<PhpToken> $body */
        $body = array_values(array_slice($tokens, $bodyOpen + 1, $bodyClose - $bodyOpen - 1));

        return $body;
    }

    return null;
}

/**
 * トークン列に `StrayHttpRequestGuard::{$method}(` の**呼び出し**があるか (純関数)。
 *
 * クラス名トークン (`T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`) →
 * `T_DOUBLE_COLON` → `T_STRING($method)` → 次の有意トークンが `(` という並びで判定する。
 * ★文字列 grep にしないのが load-bearing: literal 中の同名テキストは
 *   `T_CONSTANT_ENCAPSED_STRING` 1 個なので一致しない = コメントや説明文で偽緑にならない。
 *
 * @param  list<PhpToken>  $tokens
 * @param  non-empty-string  $method
 */
function strayHttpEgressCallsGuard(array $tokens, string $method): bool
{
    $total = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }
        $class = $token->text;
        if ($class !== 'StrayHttpRequestGuard' && ! str_ends_with($class, '\\StrayHttpRequestGuard')) {
            continue;
        }

        $colon = strayHttpEgressNextSignificant($tokens, $i + 1);
        if ($colon === null || ! $tokens[$colon]->is(T_DOUBLE_COLON)) {
            continue;
        }
        $name = strayHttpEgressNextSignificant($tokens, $colon + 1);
        if ($name === null || ! $tokens[$name]->is(T_STRING) || $tokens[$name]->text !== $method) {
            continue;
        }
        $paren = strayHttpEgressNextSignificant($tokens, $name + 1);
        if ($paren !== null && $tokens[$paren]->text === '(') {
            return true;
        }
    }

    return false;
}

/**
 * レーン既定配線の違反一覧 (純関数)。
 *
 * 各チャンクについて:
 *  - `beforeEach` hook body が `StrayHttpRequestGuard::install(` を**呼んで**いる
 *  - `afterEach` hook body が `flushAndFailIfStray(` と `reset(` を呼んでいる
 *  - hook body が null (hook が無い / 引数が closure リテラルでない / 追加引数がある) なら
 *    **違反として扱う** (fail-closed。取り出せないものを「たぶん大丈夫」にしない)
 * さらに STRAY_HTTP_EGRESS_REQUIRED_LANES が全て、**違反の無いチャンク**で覆われている。
 *
 * @param  list<array{lanes: list<string>, tokens: list<PhpToken>}>  $chunks
 * @return list<string>
 */
function strayHttpEgressLaneViolations(array $chunks): array
{
    $violations = [];
    $covered = [];

    foreach ($chunks as $chunk) {
        $label = $chunk['lanes'] === [] ? '(レーン不明)' : implode(',', $chunk['lanes']);
        $chunkViolations = [];

        $before = strayHttpEgressHookBody($chunk['tokens'], 'beforeEach');
        if ($before === null) {
            $chunkViolations[] = "[{$label}] beforeEach の closure リテラル本体を取り出せない "
                .'(hook 不在 / closure リテラルでない / 追加引数あり) ため '
                .'StrayHttpRequestGuard::install() の配線を確認できない';
        } elseif (! strayHttpEgressCallsGuard($before, 'install')) {
            $chunkViolations[] = "[{$label}] beforeEach の closure 本体で "
                .'StrayHttpRequestGuard::install() を呼んでいない';
        }

        $after = strayHttpEgressHookBody($chunk['tokens'], 'afterEach');
        if ($after === null) {
            $chunkViolations[] = "[{$label}] afterEach の closure リテラル本体を取り出せない "
                .'(hook 不在 / closure リテラルでない / 追加引数あり) ため '
                .'StrayHttpRequestGuard::flushAndFailIfStray() / reset() の配線を確認できない';
        } else {
            if (! strayHttpEgressCallsGuard($after, 'flushAndFailIfStray')) {
                $chunkViolations[] = "[{$label}] afterEach の closure 本体で "
                    .'StrayHttpRequestGuard::flushAndFailIfStray() を呼んでいない';
            }
            if (! strayHttpEgressCallsGuard($after, 'reset')) {
                $chunkViolations[] = "[{$label}] afterEach の closure 本体で "
                    .'StrayHttpRequestGuard::reset() を呼んでいない';
            }
        }

        if ($chunkViolations === []) {
            foreach ($chunk['lanes'] as $lane) {
                $covered[] = $lane;
            }
        }

        foreach ($chunkViolations as $violation) {
            $violations[] = $violation;
        }
    }

    foreach (STRAY_HTTP_EGRESS_REQUIRED_LANES as $lane) {
        if (! in_array($lane, $covered, true)) {
            $violations[] = "必須レーン {$lane} が StrayHttpRequestGuard の既定配線で覆われていない";
        }
    }

    return $violations;
}

/**
 * 許可パターンが loopback ホストだけに閉じているかの違反一覧 (純関数)。
 *
 * 許容する形は `scheme://host` / `scheme://host/*` / `scheme://host:*` の 3 形のみ。
 * host は 127.0.0.1 / localhost / [::1] に限る。
 * これにより `http://127.0.0.1*` (末尾ワイルドカード) も `https://api.example.com/*` も弾かれる。
 *
 * @param  list<string>  $patterns
 * @return list<string>
 */
function strayHttpEgressPatternViolations(array $patterns): array
{
    $violations = [];
    foreach ($patterns as $pattern) {
        if (preg_match('#^https?://(?:127\.0\.0\.1|localhost|\[::1\])(?:/\*|:\*)?$#u', $pattern) !== 1) {
            $violations[] = "許可パターンが loopback に閉じていない: {$pattern}";
        }
    }

    return $violations;
}

/**
 * 1 ファイル分の opt-out 判定 (純関数。fixture でテストできる形に切り出す)。
 *
 * 検出対象 (**deny-by-default**):
 *  - `allowStrayRequests` の呼び出し — 引数を問わず全件。
 *    null 渡しは prevent 自体を OFF にし、配列渡しは既定の許可集合を**置換**する
 *    (merge ではない: `Factory::allowStrayRequests` は `array_values($only)` 代入)。
 *  - `preventStrayRequests` の呼び出しのうち **引数があるもの**全件。
 *    ★`preventStrayRequests(false)` の literal だけを見ると
 *      `preventStrayRequests($flag)` / `((bool) 0)` / `preventStrayRequests(prevent: false)` が
 *      素通りする。**引数ゼロだけを許可**し (レーン既定と同値の重複宣言)、
 *      有意トークンが 1 個でもあれば inventory 必須にする = 逃げ道を構造的に消す。
 */
function strayHttpEgressIsOptOutSource(string $source): bool
{
    $tokens = strayHttpEgressTokens($source);
    $total = count($tokens);

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (! $token->is(T_STRING)) {
            continue;
        }
        if ($token->text !== 'allowStrayRequests' && $token->text !== 'preventStrayRequests') {
            continue;
        }

        $paren = strayHttpEgressNextSignificant($tokens, $i + 1);
        if ($paren === null || $tokens[$paren]->text !== '(') {
            continue;
        }

        if ($token->text === 'allowStrayRequests') {
            return true;
        }

        $close = strayHttpEgressMatchingIndex($tokens, $paren, '(', ')');
        if ($close === null) {
            // 対応する `)` が取れない = 解析できない。fail-closed で opt-out 扱いにする。
            return true;
        }
        if (strayHttpEgressNextSignificant($tokens, $paren + 1) !== $close) {
            return true; // 引数が 1 個以上ある
        }
    }

    return false;
}

/**
 * tests/ 配下で opt-out 呼び出しを持つファイル一覧 (リポジトリルート相対、ソート済み)。
 * Finder でファイルを集め `strayHttpEgressIsOptOutSource()` に渡すだけの薄い層。
 * 走査器自身 (STRAY_HTTP_EGRESS_SCANNER_SELF) は除外する。
 *
 * @return list<string>
 */
function strayHttpEgressOptOutSites(): array
{
    $root = base_path();
    $finder = Finder::create()->files()->in($root.'/tests')->name('*.php');

    $sites = [];
    foreach ($finder as $file) {
        $relative = str_replace($root.'/', '', (string) $file->getRealPath());
        if ($relative === STRAY_HTTP_EGRESS_SCANNER_SELF) {
            continue;
        }
        $source = file_get_contents((string) $file->getRealPath());
        expect($source)->toBeString("テストソースを読めない: {$relative}");
        /** @var string $source */
        if (strayHttpEgressIsOptOutSource($source)) {
            $sites[] = $relative;
        }
    }

    sort($sites);

    return $sites;
}

test('tests/Pest.php の全レーンが StrayHttpRequestGuard を既定配線していること', function (): void {
    $source = file_get_contents(base_path('tests/Pest.php'));
    expect($source)->toBeString();
    /** @var string $source */
    $chunks = strayHttpEgressLaneChunks(strayHttpEgressTokens($source));

    expect($chunks)->not->toBe([], 'tests/Pest.php から pest()->extend(...) チャンクを抽出できない');

    $violations = strayHttpEgressLaneViolations($chunks);

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('許可 URL パターンが loopback ホストだけに閉じていること', function (): void {
    $violations = strayHttpEgressPatternViolations(StrayHttpRequestGuard::ALLOWED_URL_PATTERNS);

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('許可判定が userinfo 詐称で loopback を騙る URL を拒否すること (第 2 層)', function (): void {
    // ★`ALLOWED_URL_PATTERNS` の `:*` は Str::is() では任意文字列に展開されるため、
    //   `http://127.0.0.1:80@api.frankfurter.dev/` (userinfo=127.0.0.1:80 / 実ホスト=外部) が
    //   **glob 単体では一致してしまう**。glob には「以降に @ を含まない」を表現する手段が無いので、
    //   guard はパース済みホストによる第 2 層を持つ契約になっている。
    //   本 gate はその契約 (= 許可集合が実質 loopback に閉じていること) を機械強制する。
    foreach (STRAY_HTTP_EGRESS_SMUGGLED_URLS as $url) {
        expect(StrayHttpRequestGuard::matchesAllowedPattern($url))
            ->toBeTrue("glob だけで弾けているなら第 2 層の前提が変わった: {$url}");
        expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl($url))
            ->toBeTrue("userinfo 詐称を第 2 層で拒否できていない: {$url}");
    }

    // 本物の loopback は通す (偽陽性側の固定)。第 2 層が「全部拒否」に退化していたらここが赤くなる。
    foreach (['http://127.0.0.1', 'http://127.0.0.1:8010/x?y=1', 'https://localhost/health', 'http://[::1]:8080/x'] as $url) {
        expect(StrayHttpRequestGuard::matchesAllowedPattern($url))->toBeTrue($url);
        expect(StrayHttpRequestGuard::isSmuggledLoopbackUrl($url))->toBeFalse($url);
    }
});

test('LOOPBACK_HOSTS が ALLOWED_URL_PATTERNS のホスト部と 1:1 対応していること', function (): void {
    // 片方だけ増やすと「pattern では許可されるが第 2 層で必ず落ちる」死んだ許可、または逆に
    // 「第 2 層は通すが pattern に無い」無意味な host が生まれる。単一 source of truth を機械固定する。
    $hosts = [];
    foreach (StrayHttpRequestGuard::ALLOWED_URL_PATTERNS as $pattern) {
        // ★Pest の expect() は PHPStan の shape narrowing にならないため、
        //   `!== 1` を明示分岐して throw する (そのあとの $matches[1] が string に確定する)。
        $matches = [];
        if (preg_match('#^https?://(127\.0\.0\.1|localhost|\[::1\])#u', $pattern, $matches) !== 1) {
            throw new RuntimeException("許可パターンからホスト部を取り出せない: {$pattern}");
        }
        $hosts[] = $matches[1];
    }
    $hosts = array_values(array_unique($hosts));
    sort($hosts);

    $declared = StrayHttpRequestGuard::LOOPBACK_HOSTS;
    sort($declared);

    expect($hosts)->toBe($declared);
});

test('opt-out 呼び出しを持つファイルが全て exemption inventory に登録済みであること (deny-by-default)', function (): void {
    $registered = array_keys(strayHttpEgressOptOutExemptions());
    $unregistered = array_values(array_diff(strayHttpEgressOptOutSites(), $registered));

    expect($unregistered)->toBe([], implode(PHP_EOL, array_map(
        static fn (string $path): string => "opt-out 呼び出しが inventory 未登録: {$path} "
            .'(Http::fake([...]) で解くか、strayHttpEgressOptOutExemptions() へ理由付きで登録する)',
        $unregistered,
    )));
});

test('exemption inventory に実在しないファイルが残っていないこと (形骸化ガード)', function (): void {
    $sites = strayHttpEgressOptOutSites();

    foreach (strayHttpEgressOptOutExemptions() as $path => $entry) {
        expect(file_exists(base_path($path)))->toBeTrue("inventory のファイルが実在しない: {$path}");
        expect(in_array($path, $sites, true))
            ->toBeTrue("inventory に登録されているが opt-out 呼び出しを持たない (登録を外すこと): {$path}");
    }
});

test('exemption の根拠が 30 文字以上であること', function (): void {
    foreach (strayHttpEgressOptOutExemptions() as $path => [$kind, $reason]) {
        expect($kind)->toBeInstanceOf(StrayHttpEgressExemption::class);
        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(
            STRAY_HTTP_EGRESS_REASON_MIN_LENGTH,
            "exemption の根拠が短すぎる ({$path}): {$reason}",
        );
    }
});

test('exemption 件数が上限 (exact fit) を超えていないこと', function (): void {
    expect(count(strayHttpEgressOptOutExemptions()))
        ->toBeLessThanOrEqual(
            STRAY_HTTP_EGRESS_EXEMPTION_CAP,
            'exemption を増やすには cap を明示的に引き上げる差分が必要 (再レビューの強制)',
        );
});

/*
 * 負のコントロール (実ファイルは書き換えない):
 * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
 */

test('負のコントロール: install を持たないレーンを検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $this->withoutVite();
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: install が afterEach 側にしかない配線を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $this->withoutVite();
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::install($this->app);
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: install が hook closure の外にある配線を検出する', function (): void {
    // 「beforeEach と afterEach の間にあれば OK」という位置ベースの実装だと素通りする形。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $this->withoutVite();
        })
        ->use(StrayHttpRequestGuard::install($app))
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: flush はあるが reset が無い配線を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('reset');
});

test('負のコントロール: 必須レーン (Architecture) が 1 つも覆われていない場合を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('Architecture');
});

test('負のコントロール: コメント内の install 記述では配線と認めない', function (): void {
    // ★これが無いと「// StrayHttpRequestGuard::install($this->app); を入れる予定」という
    //   コメントだけで gate が緑になる (最も現実的な偽緑シナリオ)。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            // StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            // StrayHttpRequestGuard::flushAndFailIfStray();
            // StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: 文字列リテラル中の install 記述では配線と認めない', function (): void {
    // ★トークン ID ではなく文字列 grep で判定する実装だと、これが素通りする。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $todo = 'StrayHttpRequestGuard::install($this->app);';
        })
        ->afterEach(function (): void {
            $todo = 'StrayHttpRequestGuard::flushAndFailIfStray(); StrayHttpRequestGuard::reset();';
        })
        ->in('Feature', 'Unit');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('install');
});

test('負のコントロール: hook 引数がネストした closure の場合を配線と認めない', function (): void {
    // ★「引数**内**のどこかにある function を拾う」実装だと素通りする。beforeEach に渡るのは
    //   wrap(...) の戻り値であり、この closure が hook として登録される保証は無い。
    //   引数が closure リテラルでない形 ($callback 変数渡し) も同様に fail-closed。
    $wrapped = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(wrap(function (): void {
            StrayHttpRequestGuard::install($this->app);
        }))
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $variable = str_replace(
        "wrap(function (): void {\n        StrayHttpRequestGuard::install(\$this->app);\n    })",
        '$callback',
        $wrapped,
    );

    // アロー関数も受け付けない (ブロック本体が必須 = 契約どおり fail-closed)
    $arrow = str_replace(
        "wrap(function (): void {\n        StrayHttpRequestGuard::install(\$this->app);\n    })",
        'fn () => StrayHttpRequestGuard::install($this->app)',
        $wrapped,
    );

    // str_replace が空振りしていたら 3 形とも同じ入力になり、テストが空振りする
    expect($variable)->not->toBe($wrapped);
    expect($arrow)->not->toBe($wrapped);

    foreach (['wrapped' => $wrapped, 'variable' => $variable, 'arrow' => $arrow] as $label => $source) {
        $violations = strayHttpEgressLaneViolations(
            strayHttpEgressLaneChunks(strayHttpEgressTokens($source)),
        );
        expect($violations)->not->toBe([], "hook 引数の形 ({$label}) を fail-closed にできていない");
        expect(implode("\n", $violations))->toContain('install');
    }
});

test('負のコントロール: closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない', function (): void {
    // ★正しい配線が literal 由来の括弧で偽赤にならないこと (偽陽性側の固定)。
    // ★`${json}` 形 (T_DOLLAR_OPEN_CURLY_BRACES) を必ず含める。`{$json}` 形だけだと
    //   T_CURLY_OPEN の text が "{" のため補間開始を数え落とす実装でも緑になり、
    //   この負のコントロールが空振りする。
    $fixture = <<<'PHP'
    <?php
    pest()->extend(TestCase::class)
        ->beforeEach(function (): void {
            $json = '{"enabled":true}';
            $unbalanced = '} ) { (';
            $interpolated = "value={$json}";
            $legacyInterpolated = "value=${json}";
            $doc = <<<'INNER'
            { unbalanced brace in heredoc
            INNER;
            StrayHttpRequestGuard::install($this->app);
        })
        ->afterEach(function (): void {
            StrayHttpRequestGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::reset();
        })
        ->in('Feature', 'Unit', 'Architecture', 'Browser');
    PHP;

    $violations = strayHttpEgressLaneViolations(
        strayHttpEgressLaneChunks(strayHttpEgressTokens($fixture)),
    );
    expect($violations)->toBe([], 'literal 由来の括弧で closure の終端を誤認している');
});

test('strayHttpEgressMatchingIndex: 補間の } を closure 終端と誤認しない', function (): void {
    // ★アルゴリズムの核を単体で固定する。
    //   補間開始トークンを開始側に数えない実装だと、返る index が補間の `}` になり
    //   closure 本体が途中で切れる。
    //
    // ★入力は 2 形とも回す。**赤を出せるのは `${json}` 形だけ**である:
    //   実測 (PHP 8.4.24) で T_CURLY_OPEN の text は "{" なので `{$json}` 形は
    //   text 比較だけの実装でも偶然通る = それだけで固定すると空振りテストになる。
    //   T_DOLLAR_OPEN_CURLY_BRACES の text は "${" で text 比較に掛からない。
    //   両方入れるのは「2 形とも契約どおり」を示すため (前者は回帰の保険)。
    $sources = [
        'dollar-open-curly (この形だけが修正前の実装で赤くなる)' => '<?php function () { $a = "value=${json}"; guard(); }',
        'curly-open' => '<?php function () { $a = "value={$json}"; guard(); }',
    ];

    foreach ($sources as $label => $source) {
        $tokens = strayHttpEgressTokens($source);

        $open = null;
        foreach ($tokens as $i => $token) {
            if ($token->text === '{') { // closure 本体の `{` (補間開始トークンより前にある)
                $open = $i;
                break;
            }
        }
        expect($open)->not->toBeNull($label);
        /** @var int $open */
        $close = strayHttpEgressMatchingIndex($tokens, $open, '{', '}');
        expect($close)->not->toBeNull($label);
        /** @var int $close */

        // 対応先は closure 末尾の `}` = その後ろに有意トークンが残らない
        expect(strayHttpEgressNextSignificant($tokens, $close + 1))->toBeNull($label);
        // 本体に guard() 呼び出しが含まれている (補間の } で切れていない)
        // ★Pest の toContain() は可変長 needle でメッセージ引数を取らないため、
        //   ラベルを失わないよう str_contains + toBeTrue(message) で書く。
        $body = array_slice($tokens, $open + 1, $close - $open - 1);
        $bodyText = implode('', array_map(static fn (PhpToken $t): string => $t->text, $body));
        expect(str_contains($bodyText, 'guard'))
            ->toBeTrue("{$label}: 補間の } を closure 終端と誤認し本体が途中で切れている");
    }
});

test('負のコントロール: 末尾ワイルドカード 1 本 (http://127.0.0.1*) と外部ドメインを検出する', function (): void {
    foreach (['http://127.0.0.1*', 'https://api.frankfurter.dev/*', '*', 'http://127.0.0.1.evil.example/*'] as $pattern) {
        $violations = strayHttpEgressPatternViolations([$pattern]);
        expect($violations)->not->toBe([], "許可パターン ({$pattern}) を検出できていない");
        expect(implode("\n", $violations))->toContain('loopback に閉じていない');
    }

    // 正しい 3 形は違反にしない (偽陽性側の固定)
    expect(strayHttpEgressPatternViolations([
        'http://127.0.0.1', 'http://127.0.0.1/*', 'http://127.0.0.1:*', 'https://[::1]:*',
    ]))->toBe([]);
});

test('負のコントロール: preventStrayRequests の非 literal opt-out を書き方によらず検出する', function (): void {
    // ★literal `false` だけを見る実装だと variable / cast / named が素通りする。
    $optOuts = [
        'literal' => 'Http::preventStrayRequests(false);',
        'variable' => 'Http::preventStrayRequests($flag);',
        'cast' => 'Http::preventStrayRequests((bool) 0);',
        'named' => 'Http::preventStrayRequests(prevent: false);',
        'spaced-comment' => 'Http::preventStrayRequests /* 理由 */ (false);',
        'nested-paren' => "Http::preventStrayRequests(str_contains(\$s, ')'));",
        'allow-null' => 'Http::allowStrayRequests();',
        'allow-array' => "Http::allowStrayRequests(['*']);",
    ];
    foreach ($optOuts as $label => $line) {
        expect(strayHttpEgressIsOptOutSource("<?php\n{$line}\n"))
            ->toBeTrue("opt-out ({$label}) を検出できていない");
    }
});

test('負のコントロール: 名前と ( の間の空白/コメント・引数中の ) で opt-out 判定を誤らない', function (): void {
    // 誤検出側 (false であるべきもの) を固定する。
    // レーン既定と同値の重複宣言 (無引数) は opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\n"))->toBeFalse();
    // 空白・改行を跨いだ無引数も opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests\n    (\n    );\n"))
        ->toBeFalse();
    // 無引数呼び出しの後ろに別の括弧があっても opt-out と誤検出しない
    expect(strayHttpEgressIsOptOutSource("<?php\nHttp::preventStrayRequests();\nfoo(bar());\n"))
        ->toBeFalse();
    // コメント内・文字列リテラル内の記述も opt-out ではない
    expect(strayHttpEgressIsOptOutSource("<?php\n// Http::allowStrayRequests(['*']) は使わない\n"))
        ->toBeFalse();
    expect(strayHttpEgressIsOptOutSource("<?php\n\$doc = 'Http::allowStrayRequests([]) は禁止';\n"))
        ->toBeFalse();
});
