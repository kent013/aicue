<?php

declare(strict_types=1);

use App\Support\PasskeyOriginCanonicalizer;

/*
 * パスキーの「許可する接続元」の正規形を決める唯一の場所の検査 (T216 施策 B)。
 *
 * 正規形へ寄せる変形は 3 つだけ (空白と大小文字 / 根の末尾スラッシュ 1 個 / 既定 port)。
 * それ以外の不正な値は **修復しない** (検証器 PasskeyConfigValidator が拒否する)。
 * ホスト名として妥当かどうかも見ない (DNS 名の規則を 2 か所に書かないため)。
 */

test('正規化は表のとおり (構造的な変形は 3 つだけ)', function (string $input, string $expected): void {
    expect(PasskeyOriginCanonicalizer::canonicalize($input))->toBe($expected);
})->with([
    // --- 正規形は不変 ---
    '正規形はそのまま' => ['https://app.example.com', 'https://app.example.com'],
    '既定でない port は残す' => ['https://app.example.com:8443', 'https://app.example.com:8443'],

    // --- 変形 1: 前後空白の除去と小文字化 ---
    '前後空白と大文字' => ['  HTTPS://App.Example.com  ', 'https://app.example.com'],

    // --- 変形 2: 根を表す末尾スラッシュ 1 個 (裁定 2026-08-04) ---
    '末尾スラッシュ' => ['https://app.example.com/', 'https://app.example.com'],

    // --- 変形 3: scheme に対応する既定 port ---
    'https の既定 port' => ['https://app.example.com:443', 'https://app.example.com'],
    'http の既定 port' => ['http://localhost:80', 'http://localhost'],
    '2 変形の同時適用' => ['https://app.example.com:443/', 'https://app.example.com'],
    'scheme と port の対応を取り違えない (http に 443)' => ['http://app.example.com:443', 'http://app.example.com:443'],
    'scheme と port の対応を取り違えない (https に 80)' => ['https://app.example.com:80', 'https://app.example.com:80'],

    // --- 修復しない (分解できない値は小文字化だけ) ---
    'path 付きは修復しない' => ['https://app.example.com/path', 'https://app.example.com/path'],
    'query 付きは修復しない' => ['https://app.example.com?x=1', 'https://app.example.com?x=1'],
    'fragment 付きは修復しない' => ['https://app.example.com#f', 'https://app.example.com#f'],
    '利用者情報付きは修復しない' => ['https://user@app.example.com', 'https://user@app.example.com'],
    '利用者情報付きから既定 port を落とさない' => ['https://user@app.example.com:443', 'https://user@app.example.com:443'],
    '角括弧の IPv6 から既定 port を落とさない' => ['https://[::1]:443', 'https://[::1]:443'],
    '末尾スラッシュは 1 個だけ落とす' => ['https://app.example.com//', 'https://app.example.com//'],
    '余分なコロンは分解できない' => ['https://app.example.com:8443:9', 'https://app.example.com:8443:9'],
    'ホスト欠落は分解できない' => ['https://:443', 'https://:443'],
    '非 ASCII は修復しない (キリル文字の а)' => ['https://аpp.example.com', 'https://аpp.example.com'],
    'scheme 欠落は分解できない' => ['app.example.com:443', 'app.example.com:443'],

    // --- 妥当性は見ない (分解できるので正規化はする。拒否は検証器の担当) ---
    'ハイフン開始のホストでも正規化はする' => ['https://-app.example.com:443', 'https://-app.example.com'],
    'IPv4 リテラルでも正規化はする' => ['https://192.0.2.1:443', 'https://192.0.2.1'],

    // --- 空要素を潰さない ---
    '空文字' => ['', ''],
    '空白のみ' => ['   ', ''],
]);

test('正規化は冪等である (2 回掛けても変わらない)', function (string $input): void {
    $once = PasskeyOriginCanonicalizer::canonicalize($input);

    expect(PasskeyOriginCanonicalizer::canonicalize($once))->toBe($once);
})->with([
    'https://app.example.com',
    '  HTTPS://App.Example.com  ',
    'https://app.example.com/',
    'https://app.example.com:443',
    'https://app.example.com:443/',
    'http://localhost:80',
    'https://app.example.com:8443',
    'https://app.example.com/path',
    'https://app.example.com?x=1',
    'https://app.example.com#f',
    'https://user@app.example.com',
    'https://user@app.example.com:443',
    'https://[::1]:443',
    'https://app.example.com//',
    'https://app.example.com:8443:9',
    'https://:443',
    'https://аpp.example.com',
    'https://-app.example.com:443',
    'https://192.0.2.1:443',
    '',
]);

// --- 宣言 (CSV) からの列の組み立て ---

test('宣言が無ければ導出値 1 件へ倒れる (正規化して返す)', function (): void {
    expect(PasskeyOriginCanonicalizer::declaredList(null, 'https://app.example.com/'))
        ->toBe(['https://app.example.com']);
});

test('宣言が空文字・空白のみでも導出値 1 件へ倒れる (env にキーだけ残す運用を壊さない)', function (?string $declared): void {
    expect(PasskeyOriginCanonicalizer::declaredList($declared, 'https://app.example.com:443'))
        ->toBe(['https://app.example.com']);
})->with([
    '空文字' => '',
    '空白のみ' => '   ',
]);

test('宣言の CSV は 1 件ずつ正規形へ寄せる', function (): void {
    expect(PasskeyOriginCanonicalizer::declaredList(
        'https://a.example.com/, https://b.example.com:443',
        'https://derived.example.com',
    ))->toBe(['https://a.example.com', 'https://b.example.com']);
});

test('宣言の空要素は落とさない (設定の書き損じを起動時に表面化させる)', function (): void {
    expect(PasskeyOriginCanonicalizer::declaredList('https://a.example.com,,', 'https://derived.example.com'))
        ->toBe(['https://a.example.com', '', '']);
});

/*
 * 純粋性の固定。本クラスは config/fortify.php の**評価時**に呼ばれるため、
 * サービスコンテナ解決・設定の読み出し・例外送出のいずれも行ってはならない
 * (config 評価中にコンテナへ触ると解決順序に依存した無言の事故になる)。
 *
 * コメントに書いた語が誤検出されないよう、**コメントを除いた実コード字句だけ**を見る。
 */
test('正規化器は純粋な静的関数である (コンテナ解決・設定読み出し・例外送出を持たない)', function (): void {
    $path = (new ReflectionClass(PasskeyOriginCanonicalizer::class))->getFileName();
    expect($path)->toBeString();
    /** @var string $path */
    $source = file_get_contents($path);
    expect($source)->toBeString();
    /** @var string $source */
    // コメントに書いた語を誤検出しないよう、**呼び出しの形 (識別子 + 開き括弧)** だけを見る。
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $forbiddenCalls = ['app', 'config', 'env', 'resolve', 'container'];
    $seenCalls = [];
    $throws = 0;

    foreach ($tokens as $i => $token) {
        if (! is_array($token)) {
            continue;
        }
        if ($token[0] === T_THROW) {
            $throws++;

            continue;
        }
        if ($token[0] !== T_STRING || ! in_array(strtolower($token[1]), $forbiddenCalls, true)) {
            continue;
        }
        if (($tokens[$i + 1] ?? null) === '(') {
            $seenCalls[] = strtolower($token[1]);
        }
    }

    expect($throws)->toBe(0, '正規化器が例外を投げている (config 評価時に呼ばれるため許されない)');
    expect($seenCalls)->toBe([], '正規化器がコンテナ・設定・環境変数に触れている: '.implode(', ', $seenCalls));

    // 静的メソッドだけを持つ (インスタンス化して状態を持たない)。
    foreach ((new ReflectionClass(PasskeyOriginCanonicalizer::class))->getMethods() as $method) {
        expect($method->isStatic())->toBeTrue("{$method->getName()} が静的メソッドではない");
    }
});
