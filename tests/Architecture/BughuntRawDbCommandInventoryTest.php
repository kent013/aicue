<?php

declare(strict_types=1);

/*
 * bug-hunt harness の raw DB コマンド目録 (deny-by-default)。
 *
 * dev DB 防御の核は「createdb / dropdb は admin 経路 (pg_admin_for_provision) だけが実行し、
 * その中で DB 名 regex と admin role 明示を通る」ことである。スクリプトのどこかに
 * raw な createdb / dropdb が増えると、この一点集中が静かに崩れる。
 *
 * ★ 保証範囲を先に限定する: これは **literal な出現の検出**であって、
 *   変数展開 ($cmd) / 関数経由 / env 経由 / eval まで含めた「呼び出しが無いこと」の**証明ではない**。
 *   そこまで見るには bash の AST 相当の解析が要る。ここでは
 *   「うっかり dropdb と書いた行が増えていないか」を保守的に検出する。
 *
 * ★ なぜ「文字列リテラルを除外する」方式を採らないか: bash の字句解析なしに
 *   文字列中の dropdb を正しく除外することはできない。除外を試みると逆に**実行行を見落とす**
 *   穴を作る。そこで「literal が現れる行を全部数え、既知の目録と完全一致するか」という
 *   保守的な方式にする (inline コメントもメッセージも目録に載せる。冗長だが見落とさない)。
 */

/** 実行実体。各ちょうど 1 行存在しなければならない。 */
const BUGHUNT_RAW_DB_REQUIRED = [
    'op_cmd=(createdb -O bughunt' => 'admin 経路の createdb 実体 (OWNER bughunt 必須)',
    'op_cmd=(dropdb --if-exists' => 'admin 経路の dropdb 実体',
];

/**
 * 存在してよい行 (wrapper 呼び出し / メッセージ / inline コメント / self-test)。
 * key = 一意な識別部分文字列 / value = [出現回数, 理由]。
 */
const BUGHUNT_RAW_DB_ALLOWED = [
    'die 1 "guard_admin_provision: BUGHUNT_ADMIN_USER' => [1, 'admin role 未設定時のエラーメッセージ'],
    'local op=$1 db=$2' => [1, 'inline コメント `# op ∈ {createdb, dropdb}`'],
    '_out_pgids+=("${wpid}")' => [1, 'inline コメント (dropdb 直前の再確認用に pgid を残す)'],
    'pg_admin_for_provision createdb "${db}"' => [1, 'wrapper 経由の createdb 呼び出し (raw ではない)'],
    'pg_admin_for_provision dropdb "$(shard_db "${shard}")"' => [1, 'wrapper 経由の dropdb 呼び出し (raw ではない)'],
    'echo "warning: shard-${shard} の worker 停止に失敗' => [1, 'dropdb スキップの警告文'],
    'echo "warning: shard-${shard} の worker が dropdb 直前の再確認で残留' => [1, '再確認失敗時の警告文'],
    'echo "[f] createdb 実行コマンドに OWNER bughunt' => [1, 'self-test の見出し'],
    "grep -q 'createdb -O bughunt'" => [1, 'self-test の検査条件'],
    't_fail "createdb に OWNER bughunt' => [1, 'self-test の失敗メッセージ'],
    't_ok "createdb OWNER bughunt"' => [1, 'self-test の成功ログ'],
    't_fail "stop_shard_workers に process group 単位の停止' => [1, 'self-test の失敗メッセージ (dropdb と race)'],
    't_fail "cmd_teardown に worker 停止失敗時の dropdb 抑止が無い' => [1, 'self-test の失敗メッセージ'],
    'echo "[y7x] dropdb 到達制御' => [1, 'self-test の見出し'],
    'local y7h_marker=' => [1, 'self-test の marker パス (dropdb-called)'],
    'pg_admin_for_provision dropdb "$(shard_db 1)"' => [2, 'self-test 内の到達制御ケース 2 件 (y7h / y7j)'],
    't_fail "[y7h] 非 zombie 残留なのに dropdb wrapper が呼ばれた' => [1, 'self-test の失敗メッセージ'],
    't_fail "[y7j] 停止済みなのに dropdb 経路へ進まなかった"' => [1, 'self-test の失敗メッセージ'],
    't_fail "[y7j] dropdb が DB 名 guard を通っていない' => [1, 'self-test の失敗メッセージ'],
    't_fail "[y7i] cmd_teardown に dropdb 直前の再確認が無い"' => [1, 'self-test の失敗メッセージ'],
    't_ok "dropdb 到達制御' => [1, 'self-test の成功ログ'],
    'local y7q_marker=' => [1, 'self-test (y7q) の marker パス'],
    't_fail "[y7q] 停止失敗 shard の dropdb が呼ばれた"' => [1, 'self-test (y7q) の失敗メッセージ'],
    't_fail "[y7q] 停止対象なしの shard でも dropdb が呼ばれていない' => [1, 'self-test (y7q) の対照 (空振り防止) の失敗メッセージ'],
    'local y7r_marker=' => [1, 'self-test (y7r) の marker パス'],
    't_fail "[y7r] dropdb 直前の再確認で live が出たのに teardown が成功で終わった"' => [1, 'self-test (y7r) の失敗メッセージ'],
    't_fail "[y7r] 再確認で live を観測したのに dropdb が呼ばれた' => [1, 'self-test (y7r) の失敗メッセージ'],
];

/**
 * 行頭コメントを除いた行のうち、単語境界の createdb / dropdb を含むものを返す。
 *
 * @return list<string>
 */
function bughuntRawDbLiteralLines(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    expect($lines)->toBeArray();

    $hits = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;   // 行頭コメント (冒頭の説明文で偽陽性になるため除外)
        }
        if (preg_match('/\b(createdb|dropdb)\b/', $line) === 1) {
            $hits[] = trim($line);
        }
    }

    return $hits;
}

test('createdb / dropdb の実行実体が admin 経路にちょうど 1 行ずつ存在すること', function (): void {
    $hits = bughuntRawDbLiteralLines(base_path('scripts/bug-hunt-shard.sh'));

    foreach (BUGHUNT_RAW_DB_REQUIRED as $key => $reason) {
        $count = count(array_filter($hits, fn (string $line): bool => str_contains($line, $key)));

        expect($count)->toBe(1, "必須実行行が 1 行ではない: '{$key}' ({$reason}) → {$count} 行");
    }
});

test('createdb / dropdb の literal が目録と完全一致すること', function (): void {
    $hits = bughuntRawDbLiteralLines(base_path('scripts/bug-hunt-shard.sh'));

    // key => 期待件数 (必須 + 許可)
    $expected = [];
    foreach (BUGHUNT_RAW_DB_REQUIRED as $key => $_reason) {
        $expected[$key] = 1;
    }
    foreach (BUGHUNT_RAW_DB_ALLOWED as $key => [$count, $_reason]) {
        expect($expected)->not->toHaveKey($key, "目録に重複キー: '{$key}'");
        $expected[$key] = $count;
    }

    // 1. 各行がちょうど 1 つの目録キーに一致すること (未知の行 / 曖昧な行を弾く)
    $unknown = [];
    $matched = [];
    foreach ($hits as $line) {
        $keys = array_values(array_filter(
            array_keys($expected),
            fn (string $key): bool => str_contains($line, $key),
        ));

        if ($keys === []) {
            $unknown[] = $line;

            continue;
        }

        expect($keys)->toHaveCount(
            1,
            "1 行が複数の目録キーに一致した (識別キーが曖昧): {$line} → ".implode(' / ', $keys),
        );
        $matched[$keys[0]] = ($matched[$keys[0]] ?? 0) + 1;
    }

    expect($unknown)->toBe([], "目録に無い createdb/dropdb の literal 行が増えている:\n".implode("\n", $unknown));

    // 2. 件数が目録と一致すること (行が消えた場合も検出する)
    expect($matched)->toEqual($expected, '目録の期待件数と実際の出現件数が一致しない');

    // 3. 合計件数も突き合わせる (必須 2 + 許可分)
    expect(count($hits))->toBe(array_sum($expected));
});
