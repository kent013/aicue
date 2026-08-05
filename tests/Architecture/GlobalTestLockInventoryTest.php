<?php

declare(strict_types=1);

/*
 * Architecture invariant: 全テストレーンがグローバルテストロックを経由すること。
 *
 * 背景 (SoT = devnotes/20260804-2319-global-test-lock/conceptual-design.md):
 * 複数 worktree の並行実装でテストレーンが同時に走ると、PostgreSQL サーバ・実ブラウザ・
 * CPU/メモリを奪い合い、Browser lane の machine-wide な playwright 掃除が他レーンの
 * run-server を巻き込む。旧実装は worktree-local な flock (cross-worktree 排他ゼロ) かつ
 * flock -n (待たずに即エラー) だったため、これを scripts/global-test-lock.sh へ一本化した。
 *
 * worktree-local flock を「残さず削除する」判断が安全なのは、公式 entrypoint を
 * **全て確実に包めている場合に限る**。よって本テストは deny-by-default の inventory とする:
 * composer.json / package.json の test 系スクリプトは、明示 exemption に無い限り
 * ロック経由でなければ fail する (新レーン追加時に落ちて気づける)。
 *
 * 並行挙動そのものは scripts/verify-global-test-lock.sh (層 1) が検証する。
 * **本テストから層 1 を実行してはならない**: 本テストは composer test の内側
 * = グローバルロック保持中に走るため、自分自身と競合する。
 */

/** watch / 対話用途のため意図的にラップしない script と、その理由。 */
const GLOBAL_TEST_LOCK_EXEMPT = [
    'test:ui' => 'vitest --ui (常駐 UI サーバ)。無期限にロックを保持するため対象外',
    'test:watch' => 'vitest --watch (常駐 watch)。同上',
];

/** ロック経由と認められる呼び出し先 (これ自身がライブラリを source していることも検査する)。 */
const GLOBAL_TEST_LOCK_LANE_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * 構造検査の対象スクリプト = lane スクリプト 3 本 + 汎用ラッパ。
 * ラッパを対象外にすると、将来 `exec "$@"` へ戻されても層 2 は
 * 「存在し実行可能」だけで通過してしまう (ロックが即解放される致命的回帰を見逃す)。
 * ライブラリ本体 (scripts/global-test-lock.sh) は対象外 —
 * trap / exec fd リダイレクトを**正当に持つ唯一のファイル**だから。
 */
const GLOBAL_TEST_LOCK_GUARDED_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
    'scripts/with-global-test-lock.sh',
];

/**
 * JSON の scripts セクションを「script 名 => コマンド文字列」へ正規化する (純関数)。
 * composer.json は配列形式を採るため、改行連結して 1 文字列にする。
 *
 * @return array<string, string>
 */
function globalTestLockScriptsFromJson(string $json): array
{
    /** @var mixed $decoded */
    $decoded = json_decode($json, true);
    if (! is_array($decoded)) {
        return [];
    }

    /** @var mixed $scripts */
    $scripts = $decoded['scripts'] ?? null;
    if (! is_array($scripts)) {
        return [];
    }

    $normalized = [];
    /** @var mixed $command */
    foreach ($scripts as $name => $command) {
        $lines = is_array($command) ? $command : [$command];
        /** @var array<array-key, mixed> $lines */
        $normalized[(string) $name] = implode("\n", array_map(
            static fn (mixed $line): string => is_scalar($line) ? (string) $line : '',
            $lines,
        ));
    }

    return $normalized;
}

/**
 * composer.json / package.json の test 系 script が全てロック経由かを検査する (純関数)。
 *
 * @param  array<string, string>  $scripts  script 名 => コマンド文字列 (配列形式は改行連結済み)
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneViolations(array $scripts): array
{
    $violations = [];

    foreach ($scripts as $name => $command) {
        if ($name !== 'test' && ! str_starts_with($name, 'test:')) {
            continue;
        }
        if (array_key_exists($name, GLOBAL_TEST_LOCK_EXEMPT)) {
            continue;
        }
        // 部分一致で通すと `with-global-test-lock.sh true && unlocked-test` のような
        // 「ラッパ名は含むが実体は無ロック」が素通りする。
        // **最終行 (= 実際に走るコマンド) が公式入口そのものであること**を要求し、
        // 同一行のシェル演算子で別コマンドを繋ぐことを禁止する。
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $command) ?: []),
            static fn (string $l): bool => $l !== '',
        ));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        if (preg_match('/(&&|\|\||;|(?<!\|)\|(?!\|))/', $last) === 1) {
            $violations[] = "script '{$name}' がロック配下のコマンドをシェル演算子で連結している: {$last}";

            continue;
        }

        $entrypoints = array_merge(['scripts/with-global-test-lock.sh'], GLOBAL_TEST_LOCK_LANE_SCRIPTS);
        $viaEntrypoint = false;
        foreach ($entrypoints as $entrypoint) {
            if (preg_match('#^bash\s+'.preg_quote($entrypoint, '#').'(?:\s|$)#', $last) === 1) {
                $viaEntrypoint = true;
                break;
            }
        }
        if (! $viaEntrypoint) {
            $violations[] = "script '{$name}' がグローバルテストロックを経由していない: {$last}";
        }
    }

    return $violations;
}

/**
 * shell ソースから **実行行だけ** を取り出す (純関数)。
 *
 * 全ての静的検査はこの結果を単一の解析入力として使う。変更後スクリプトは
 * 「旧 worktree-local な test.lock を廃止した」「flock -n をやめた」といった説明を
 * **コメントに書く**ため、生ソースを検査すると正しい実装が偽赤になる。
 *
 * 行頭 (空白を除く) が `#` の行だけを落とす。行末コメントの除去はしない —
 * `'#'` のような引用符内の `#` を壊してコードを誤って削るリスクの方が大きい。
 */
function globalTestLockCodeLines(string $source): string
{
    // `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
    // 文字途中で分断して「コメント断片がコードとして漏出する」(PcreUnicodeModifierGateTest)。
    $lines = preg_split('/\R/u', $source) ?: [];
    $code = array_filter(
        $lines,
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    );

    return implode("\n", $code);
}

/**
 * `CI` 環境変数の参照禁止を検査する対象 = ロック機構の全ファイル (ライブラリ本体を含む)。
 *
 * 「CI では素通り」の分岐は、**正しさが最も要求される場所に、ローカルでは一度も
 * 実行されないコードパス**を増やす。CI が検証しているものと開発者が走らせるものを
 * 同一に保つため、ロック機構は CI を特別扱いしない (概念設計 §CI の扱い)。
 */
const GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS = [
    'scripts/global-test-lock.sh',
    'scripts/with-global-test-lock.sh',
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * ロック機構が `CI` 環境変数を **参照していない** ことを検査する (純関数)。
 *
 * 契約は「分岐していないこと」ではなく「**参照していないこと**」= deny-by-default。
 * 分岐だけを狙うと `flag=$CI` → `if [ "$flag" ]` のような 2 段構えを取りこぼすし、
 * そもそもロック機構が CI を読む正当な用途が 1 つも無いため、参照自体を禁じる方が
 * 契約として単純である (安全側の偽陽性は許容する)。
 *
 * **保証範囲**: 検出するのは shell の **通常の直接参照** (変数展開 / `-v` / `printenv` /
 * `env | grep`)。`declare -p CI` や変数名を組み立てる間接参照まで意味論的に完全検出は
 * しない (それは静的検査の射程外)。回帰防止としてはこれで十分 —
 * CI バイパスを足す人が意図的に難読化して書く前提は取らない。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockCiReferenceViolations(string $path, string $source): array
{
    $code = globalTestLockCodeLines($source);

    // 参照の書き方は複数あるので、bash で実際に CI を読める形を網羅する。
    $patterns = [
        '/\$\{?CI\b/',                     // $CI / ${CI} / ${CI:-} / ${CI+x}
        '/(?:\[\[|\btest\b|\[)[^\n]*\s-v\s+["\']?CI["\']?/', // [[ -v CI ]] / test -v CI
        '/\bprintenv\b[^\n]*\bCI\b/',      // printenv CI
        '/\benv\b[^\n|]*\|[^\n]*\bCI\b/',  // env | grep CI
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return ["{$path} が CI 環境変数を参照している (CI を特別扱いしない = バイパス分岐を作らない)"];
        }
    }

    return [];
}

/**
 * lane スクリプト / ラッパ本体が契約を守っているかを検査する (純関数)。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneScriptViolations(string $path, string $source): array
{
    $violations = [];
    $code = globalTestLockCodeLines($source);

    if (! str_contains($code, 'global-test-lock.sh')) {
        $violations[] = "{$path} が scripts/global-test-lock.sh を source していない";
    }
    // 旧 worktree-local ロックの残存 (後方互換の並走) を禁止する。
    if (str_contains($code, 'storage/framework/testing/test.lock')) {
        $violations[] = "{$path} に旧 worktree-local な test.lock が残っている";
    }
    if (preg_match('/app-vitest-/', $code) === 1) {
        $violations[] = "{$path} に旧 workspace-hash ロック (app-vitest-*) が残っている";
    }
    if (preg_match('/\bflock\s+-n\b/', $code) === 1) {
        $violations[] = "{$path} に flock -n (非ブロッキング取得) が残っている";
    }
    // 自己バイパスの禁止。
    if (preg_match('/GLOBAL_TEST_LOCK_DIR=/', $code) === 1) {
        $violations[] = "{$path} が GLOBAL_TEST_LOCK_DIR を設定している (自己バイパス禁止)";
    }
    // exec はロック fd を閉じてロックを即解放するため、ロック配下では使わない。
    // ただし `exec 3<>...` のような **fd リダイレクト形は正当** なので除外する
    // (run-browser-test.sh の /dev/tcp guard が使う)。
    if (preg_match('/^\s*exec\s+(?!\d*[<>])/m', $code) === 1) {
        $violations[] = "{$path} が exec を使っている (fd 7 が閉じてロックが即解放される)";
    }
    // EXIT trap の所有者はライブラリ 1 箇所。lane が自前で張ると _gtl_cleanup を
    // 上書きしてロックが解放されなくなる (逆順なら lane 側が消される)。
    // 後始末は global_test_lock_on_exit へ登録する。
    if (preg_match('/^\s*trap\b[^\n]*\bEXIT\b/m', $code) === 1) {
        $violations[] = "{$path} が自前で trap ... EXIT を張っている (global_test_lock_on_exit を使うこと)";
    }
    // ラッパ / lane は必ず acquire → run の順で公開 API を **実際に呼ぶ** こと。
    // str_contains ではコメント/文字列だけでも通ってしまうため、呼び出し形を正規表現で見る。
    $acquireAt = preg_match('/^\s*global_test_lock_acquire\b/m', $code, $mA, PREG_OFFSET_CAPTURE) === 1
        ? $mA[0][1]
        : null;
    $runAt = preg_match('/^\s*global_test_lock_run\b/m', $code, $mR, PREG_OFFSET_CAPTURE) === 1
        ? $mR[0][1]
        : null;

    if ($acquireAt === null) {
        $violations[] = "{$path} が global_test_lock_acquire を呼んでいない";
    }
    if ($runAt === null) {
        $violations[] = "{$path} が global_test_lock_run を呼んでいない";
    }
    if ($acquireAt !== null && $runAt !== null && $acquireAt > $runAt) {
        $violations[] = "{$path} が global_test_lock_run を acquire より前に呼んでいる";
    }

    return $violations;
}

test('scripts/global-test-lock.sh と with-global-test-lock.sh が存在し実行可能であること', function (): void {
    foreach (['scripts/global-test-lock.sh', 'scripts/with-global-test-lock.sh'] as $rel) {
        $path = base_path($rel);
        expect(file_exists($path))->toBeTrue("{$rel} が見つからない");
        expect(is_executable($path))->toBeTrue("{$rel} に実行権が無い");
    }
});

test('scripts/verify-global-test-lock.sh が存在し実行可能であること', function (): void {
    // 層 1 (並行挙動スイート) の存在だけを固定する。**実行はしない** —
    // 本テストはグローバルロック保持中に走るため、起動すると自己競合する。
    $path = base_path('scripts/verify-global-test-lock.sh');
    expect(file_exists($path))->toBeTrue('scripts/verify-global-test-lock.sh が見つからない');
    expect(is_executable($path))->toBeTrue('scripts/verify-global-test-lock.sh に実行権が無い');
});

test('composer.json の test 系 script が全てグローバルテストロック経由であること', function (): void {
    $json = file_get_contents(base_path('composer.json'));
    expect($json)->toBeString();
    /** @var string $json */
    $scripts = globalTestLockScriptsFromJson($json);
    expect($scripts)->not->toBe([]);
    expect(array_key_exists('test', $scripts))->toBeTrue('composer.json に test script が無い');
    expect(globalTestLockLaneViolations($scripts))->toBe([]);
});

test('package.json の test 系 script が全てグローバルテストロック経由であること', function (): void {
    $json = file_get_contents(base_path('package.json'));
    expect($json)->toBeString();
    /** @var string $json */
    $scripts = globalTestLockScriptsFromJson($json);
    expect($scripts)->not->toBe([]);
    expect(array_key_exists('test', $scripts))->toBeTrue('package.json に test script が無い');
    expect(globalTestLockLaneViolations($scripts))->toBe([]);
});

test('lane スクリプトとラッパが契約 (source / 旧ロック不在 / flock -n 不在 / exec 不在 / 自前 EXIT trap 不在 / acquire+run 使用) を守ること', function (): void {
    foreach (GLOBAL_TEST_LOCK_GUARDED_SCRIPTS as $rel) {
        $source = file_get_contents(base_path($rel));
        expect($source)->toBeString();
        /** @var string $source */
        expect(globalTestLockLaneScriptViolations($rel, $source))->toBe([]);
    }
});

test('ロック機構が CI 環境変数を参照しないこと (CI バイパス禁止)', function (): void {
    foreach (GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS as $rel) {
        $source = file_get_contents(base_path($rel));
        expect($source)->toBeString();
        /** @var string $source */
        expect(globalTestLockCiReferenceViolations($rel, $source))->toBe([]);
    }
});

/*
 * 負のコントロール (実ファイルは書き換えない):
 * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
 */
test('負のコントロール: 未ラップの新レーンを検出する', function (): void {
    $violations = globalTestLockLaneViolations(['test:e2e' => 'pnpm exec playwright test']);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test:e2e');
});

test('負のコントロール: ラッパ名を含むだけの偽装 (演算子連結) を検出する', function (): void {
    $violations = globalTestLockLaneViolations([
        'test:e2e' => 'bash scripts/with-global-test-lock.sh true && pnpm exec playwright test',
    ]);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('連結');
});

test('負のコントロール: 旧 worktree-local ロックへ戻した lane スクリプトを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    LOCK_FILE="storage/framework/testing/test.lock"
    exec 9>"$LOCK_FILE"
    flock -n 9 || exit 1
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test.lock');
    expect(implode("\n", $violations))->toContain('flock -n');
});

test('負のコントロール: exec を復活させたラッパを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "$*"
    exec "$@"
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('exec');
});

test('負のコントロール: 自前 EXIT trap を張った lane スクリプトを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "lane"
    trap cleanup_orphan_playwright EXIT
    global_test_lock_run vendor/bin/pest
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('trap');
});

test('負のコントロール: exec の fd リダイレクト形は違反にしない', function (): void {
    $ok = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    (exec 3<>"/dev/tcp/127.0.0.1/8010") 2>/dev/null || true
    global_test_lock_acquire "lane"
    global_test_lock_run vendor/bin/pest
    SH;
    expect(globalTestLockLaneScriptViolations('fixture.sh', $ok))->toBe([]);
});

test('負のコントロール: CI 環境変数の参照を書き方によらず検出する', function (): void {
    // 「${CI} だけ見る」実装だと素通りする形を含めて固定する (Codex impl-review Round 2 の指摘)。
    $broken = [
        'expansion' => '        if [ "${CI:-}" = "true" ]; then exec "$@"; fi',
        'bracket-v' => '        if [[ -v CI ]]; then return 0; fi',
        'test-v' => '        if test -v CI; then return 0; fi',
        'printenv' => '        if [ "$(printenv CI)" = "true" ]; then return 0; fi',
        'env-grep' => '        if env | grep -q "^CI="; then return 0; fi',
        'indirect' => '        flag=$CI',
    ];
    foreach ($broken as $label => $line) {
        $violations = globalTestLockCiReferenceViolations('fixture.sh', "#!/usr/bin/env bash\n{$line}\n");
        expect($violations)->not->toBe([], "CI 参照 ({$label}) を検出できていない");
        expect(implode("\n", $violations))->toContain('CI 環境変数を参照している');
    }

    // コメント内の説明は違反にしない (実装が方針を説明できないと困るため)。
    $ok = <<<'SH'
    #!/usr/bin/env bash
    # CI バイパス分岐は作らない (${CI} で素通りさせない / printenv CI も見ない)
    global_test_lock_acquire "lane"
    SH;
    expect(globalTestLockCiReferenceViolations('fixture.sh', $ok))->toBe([]);
});

test('負のコントロール: 自己バイパス (GLOBAL_TEST_LOCK_DIR 設定) と acquire/run の順序違反を検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    GLOBAL_TEST_LOCK_DIR=/tmp/bypass
    global_test_lock_run vendor/bin/pest
    global_test_lock_acquire "lane"
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect(implode("\n", $violations))->toContain('自己バイパス');
    expect(implode("\n", $violations))->toContain('acquire より前');
});
