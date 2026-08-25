<?php

declare(strict_types=1);

/**
 * 招待の継続が使う session の鍵 (`invitation_token`) の literal を 1 ファイルへ閉じる
 * (正典 v1 i11 / aicue:T263 施策 B。テンプレート laravel-claude-template@5dd85a6 の
 * 同名テストを移植し、判定と fail-closed を強化)。
 *
 * 従来は controller (`InvitationAcceptanceController`) / 登録処理 (`CreateNewUser`) /
 * 会員サービス (`OrganizationMembershipService`) の 3 ファイルに**生の鍵文字列**が散在していた。
 * 鍵の literal がどこに現れてよいかを機械で固定する。
 *
 * ## 走査対象と判定
 *
 *  - `app/` 配下の `*.php` 全数を `token_get_all($source, TOKEN_PARSE)` で走査し、
 *    `T_CONSTANT_ENCAPSED_STRING` の**実行時値を復元**して `invitation_token` との
 *    完全一致でファイルを列挙する (引用符の除去だけでは `"\x69nvitation_token"` のような
 *    エスケープ表現をすり抜けられるため、二重引用符は stripcslashes()、単引用符は
 *    `\\` と `\'` の 2 種を 1 パスで解いて復元する)
 *  - コメント・DocComment 中の言及は数えない (説明文で書いた名前で赤くなると、
 *    gate を黙らせるために説明を消す誘因が生まれる)
 *
 * ## fail-closed (AGENTS.md 走査器規約 (b))
 *
 *  - 走査根 `app/` が存在しなければ fail (RecursiveDirectoryIterator 任せにしない)
 *  - `file_get_contents` が false のファイルは黙って continue せず fail
 *  - TOKEN_PARSE により構文解析不能 (ParseError) は握らず fail
 *  - 期待値は「ちょうど [SoT 1 ファイル]」の完全一致 (走査が空振りすれば [] ≠ [SoT] で赤 =
 *    母集団の非空を判定が内包する) に加え、走査した PHP ファイル数が 0 でないことも独立に固定
 *
 * ## 保証しないこと
 *
 *  - **動的に組み立てた鍵** (連結 `'invitation'.'_token'` / 変数 / sprintf) は検出できない
 *  - **`\u{}` unicode エスケープ表現** (stripcslashes は解かない) は検出できない
 *  - **別名の鍵で同じ担体を作る形**は検出できない (鍵の名前を変えれば通る)。
 *    これは「SoT の外に生の鍵 literal を書かない」という限定的な契約である
 *  - **heredoc / nowdoc 本文** (T_ENCAPSED_AND_WHITESPACE 等) は検出できない
 *  - **`tests/` 配下**は `withSession(['invitation_token' => ...])` で session を組む
 *    正当な利用者なので対象外
 */

/** 鍵の literal を書いてよい唯一のファイル (`app/` からの相対パス)。 */
const INVITATION_CONTINUATION_KEY_OWNER = 'Support/Auth/InvitationContinuation.php';

/**
 * PHP の文字列 literal トークン (T_CONSTANT_ENCAPSED_STRING の生テキスト) から実行時値を復元する。
 *
 * - 二重引用符: stripcslashes() (\x69 / \151 / \n 等を復元)
 * - 単引用符: `\\` と `\'` の 2 種のみを **1 パス**で解く (PHP の単引用符の意味論どおり。
 *   逐次 str_replace は `\\` と `\'` が隣接する入力で置換順により誤復元する)
 * - binary 接頭辞 (b'…' / B"…") は値に影響しないため剥がす
 *
 * @throws RuntimeException 引用符を判別できない形 (解決できない形は落とす — fail-closed)
 */
function invitationContinuationRestoreLiteralValue(string $raw): string
{
    if ($raw !== '' && (str_starts_with($raw, 'b') || str_starts_with($raw, 'B'))) {
        $raw = substr($raw, 1);
    }
    if (strlen($raw) < 2) {
        throw new RuntimeException("文字列 literal として復元できない形です: {$raw}");
    }

    $quote = $raw[0];
    $body = substr($raw, 1, -1);

    if ($quote === "'") {
        $restored = preg_replace_callback(
            '/\\\\([\\\\\'])/',
            static fn (array $matches): string => $matches[1],
            $body,
        );
        if ($restored === null) {
            throw new RuntimeException("単引用符 literal の復元に失敗しました: {$raw}");
        }

        return $restored;
    }

    if ($quote === '"') {
        return stripcslashes($body);
    }

    throw new RuntimeException("引用符を判別できない literal です: {$raw}");
}

/**
 * PHP ソース中で鍵 `invitation_token` を**文字列リテラルとして**書いている箇所を数える。
 * 構文解析不能 (ParseError) は握らず呼び出し側へ伝播させる (fail-closed)。
 */
function invitationContinuationKeyLiteralHits(string $source): int
{
    $count = 0;
    foreach (token_get_all($source, TOKEN_PARSE) as $token) {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        if (invitationContinuationRestoreLiteralValue($token[1]) === 'invitation_token') {
            $count++;
        }
    }

    return $count;
}

/**
 * `app/` 配下の *.php を走査し、鍵 literal を含むファイルの一覧と走査ファイル数を返す。
 *
 * 走査根と読み取り処理は fail-closed 分岐を負例で裏取りできるよう引数へ切り出してある
 * (省略時は実運用の値。IC-4 が「走査根不存在」「読み取り失敗」の両分岐を例外で固定する)。
 *
 * @param  string|null  $appRoot  走査根 (null = 実リポジトリの app/)
 * @param  (callable(string): (string|false))|null  $readFile  読み取り (null = file_get_contents)
 * @return array{files: list<string>, scanned: int} files は `app/` からの相対パス (昇順)
 */
function invitationContinuationKeyLiteralScan(?string $appRoot = null, ?callable $readFile = null): array
{
    // 走査根の既定は base_path('app') (他 gate と同じ家風。素の '/app' literal は
    // LegacyOrganizationlessUrlAbsenceTest が旧 URL として検出するため書かない)
    $appRoot ??= base_path('app');
    $readFile ??= static fn (string $path): string|false => file_get_contents($path);

    if (! is_dir($appRoot)) {
        throw new RuntimeException("走査根が存在しません: {$appRoot}");
    }

    $files = [];
    $scanned = 0;

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = $readFile($file->getPathname());
        if ($source === false) {
            // 黙って continue しない (見逃す方向へ倒さない)
            throw new RuntimeException("読めないファイルがあります: {$file->getPathname()}");
        }
        $scanned++;

        if (invitationContinuationKeyLiteralHits($source) > 0) {
            $files[] = str_replace($appRoot.'/', '', $file->getPathname());
        }
    }

    sort($files);

    return ['files' => array_values(array_unique($files)), 'scanned' => $scanned];
}

test('IC-1: invitation_token の literal は継続クラス 1 ファイルにしか現れない', function (): void {
    $scan = invitationContinuationKeyLiteralScan();

    // 走査の空振り検査 (母集団の非空)。期待値の完全一致だけでも [] ≠ [SoT] で赤になるが、
    // 「走査したファイル数が 0」という故障様態を独立に区別できるようにする
    expect($scan['scanned'])->toBeGreaterThan(0);

    expect($scan['files'])->toBe(
        [INVITATION_CONTINUATION_KEY_OWNER],
        '招待の継続の session の鍵が SoT の外に書かれています。'
        .'App\\Support\\Auth\\InvitationContinuation の remember / resolve / forget を通してください。',
    );
});

test('IC-2: 検出器の負例と正例 — コメント中の言及は数えず、literal は 3 形とも数える', function (): void {
    // 負例: コメント / DocComment 中の言及は数えない
    $commentOnly = "<?php\n// session の invitation_token を読む\n/** invitation_token */\n";
    expect(invitationContinuationKeyLiteralHits($commentOnly))->toBe(0);

    // 正例 1: 単引用符 literal
    $singleQuoted = "<?php\n\$s->put('invitation_token', \$t);\n";
    expect(invitationContinuationKeyLiteralHits($singleQuoted))->toBe(1);

    // 正例 2: 二重引用符 literal
    $doubleQuoted = "<?php\n\$s->put(\"invitation_token\", \$t);\n";
    expect(invitationContinuationKeyLiteralHits($doubleQuoted))->toBe(1);

    // 正例 3: \x エスケープ形 (引用符の除去だけの判定ではすり抜ける)
    $escaped = "<?php\n\$s->put(\"\\x69nvitation_token\", \$t);\n";
    expect(invitationContinuationKeyLiteralHits($escaped))->toBe(1);
});

test('IC-3: 単引用符復元器は `\\\\` と `\\\'` が隣接しても誤復元しない (置換順の罠の負例)', function (): void {
    // 逐次 str_replace('\\\'', ...) → str_replace('\\\\', ...) のような実装は
    // 隣接エスケープで壊れる。1 パス復元が PHP の意味論どおりであることを固定する
    $raw = <<<'RAW'
'\\\'invitation_token'
RAW;
    $expected = <<<'VALUE'
\'invitation_token
VALUE;
    expect(invitationContinuationRestoreLiteralValue($raw))->toBe($expected);

    // 復元値は鍵と一致しない (= 検出対象に数えない) ことも固定する
    expect(invitationContinuationRestoreLiteralValue($raw))->not->toBe('invitation_token');
});

test('IC-4b: fail-closed — 走査根不存在・読み取り失敗は黙って除外せず例外にする (負例)', function (): void {
    // 走査根の不存在 (改名・移動で走査が壊れたら緑のまま黙らない)
    expect(static fn (): array => invitationContinuationKeyLiteralScan('/nonexistent-invitation-scan-root'))
        ->toThrow(RuntimeException::class, '走査根が存在しません');

    // 読み取り失敗 (この分岐が continue へ弱体化したら赤になる)
    expect(static fn (): array => invitationContinuationKeyLiteralScan(null, static fn (string $path): string|false => false))
        ->toThrow(RuntimeException::class, '読めないファイルがあります');
});

test('IC-4: fail-closed — 構文解析不能・復元不能な形は握らず fail する', function (): void {
    // 構文解析不能 (TOKEN_PARSE): ParseError を握らない
    expect(static fn (): int => invitationContinuationKeyLiteralHits('<?php class {'))
        ->toThrow(ParseError::class);

    // 引用符を判別できない生テキストは RuntimeException (黙って 0 扱いにしない)
    expect(static fn (): string => invitationContinuationRestoreLiteralValue('`bad`'))
        ->toThrow(RuntimeException::class);
});
