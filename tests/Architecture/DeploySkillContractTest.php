<?php

declare(strict_types=1);

/*
 * app-deploy skill の契約 gate (T030 / skill-deploy)。
 *
 * SKILL.md は「LLM が読んで従う手順書」であり、コードと違って型検査もテストも掛からない。
 * 放っておくと (1) 実座標 (host 名・ドメイン・開発機のパス) が焼き付き、
 * (2) 危険な実行導線 (`dep run` / `terraform apply` / 本番 URL への curl) が生え、
 * (3) 参照しているドキュメントが消えても誰も気付かない。
 * 本 gate はその 3 つを機械的に閉じる。
 *
 * **責務境界**:
 *  - 座標そのものの検出 (ドメイン / cloud ID / 実 IPv4 / 別アプリ名 / 開発機パス) の SoT は
 *    DeployCoordinateHygieneTest である。同 gate の scan root に
 *    `.claude/skills/app-deploy` が required=true で載っていることを S8 が pin する。
 *    S3 が skill 固有の needle を**重ねて**持つのは多層防御であり、hygiene gate 側の
 *    カテゴリ設定が将来緩められても skill だけは落ちるようにするため。
 *  - **実行導線** (S7) は hygiene gate の責務外なので本 gate が唯一の網である。
 *
 * **他の deploy gate と関数名を共有しない**: `composer test --parallel` の worker は
 * 複数のテストファイルを同一プロセスで読むため、同名の関数 / 定数を別ファイルで宣言すると
 * 再宣言の fatal error になる。したがって本ファイルの宣言はすべて `deploySkill*` /
 * `DEPLOY_SKILL_*` で prefix する (共有ヘルパを tests/Pest.php に置く形は、
 * gate 固有の needle 台帳を共有領域に漏らすことになるので採らない)。
 */

const DEPLOY_SKILL_ROOT = '.claude/skills/app-deploy';
const DEPLOY_SKILL_PATH = '.claude/skills/app-deploy/SKILL.md';
const DEPLOY_SKILL_HYGIENE_GATE = 'tests/Architecture/DeployCoordinateHygieneTest.php';

/** front matter の必須キー (t1 既存 skill と同形の 4 キー)。 */
const DEPLOY_SKILL_FRONT_MATTER_KEYS = ['name', 'description', 'user-invocable', 'argument-hint'];

/**
 * テンプレートの元になった別アプリ名 / org 名。
 *
 * gate 自身が needle を素の literal で持つと、本ファイルが座標 hygiene gate の走査対象に
 * なったときに自分で赤くなるため **文字列連結**で書く (self-allowlist を作らない)。
 */
const DEPLOY_SKILL_DONOR_NEEDLES = ['aigen'.'ba', 'spi'.'rux', 'engra'.'phia'];

/** ホスト側 / 開発機の絶対パスの needle (SKILL.md はリポジトリ相対でしか書かない)。 */
const DEPLOY_SKILL_FORBIDDEN_PATHS = ['/srv/', '/Users/', '/home/'];

/**
 * ドメイン検出に使う TLD (保守的)。
 *
 * SKILL.md はファイル名 (`deploy.php` / `hosts.yml` / `SKILL.md`) を大量に含むため、
 * 「TLD らしければドメイン」とすると全部誤検知する。SKILL.md に実ドメインが書かれる現実的な
 * 形は FQDN の散文なので、拡張子と衝突しない TLD だけを見る。
 */
const DEPLOY_SKILL_DOMAIN_TLDS = [
    'com', 'net', 'org', 'info', 'biz', 'io', 'ai', 'app', 'dev', 'cloud',
    'tv', 'xyz', 'site', 'online', 'store', 'shop', 'tech', 'co',
    'jp', 'us', 'uk', 'fr', 'eu', 'cn', 'kr', 'au', 'ca', 'nl', 'ru', 'br',
    'asia', 'tokyo',
];

/**
 * ドメイン allowlist (ベンダーのサービス endpoint / ツールチェーンのサイトのみ)。
 *
 * **RFC 2606 の予約ドメイン (`example.com` 等) は載せない**。座標 hygiene gate は
 * 一般ドキュメントの誤検知を避けるため予約ドメインを素通しするが、SKILL.md は
 * **ホストの例示を一切持たない** (host は `<host>` placeholder でしか書かない) ので、
 * ここを緩める必要が無い。`example.com` は IANA が実 IP を割り当てているため、
 * 手順書に「実在ホストの例」として残ると agent が実際に叩きうる (設計付録 11)。
 */
const DEPLOY_SKILL_DOMAIN_ALLOWLIST = [
    'amazonaws.com', 'amazonses.com', 'github.com', 'laravel.com', 'php.net',
];

/**
 * 「コマンド行」とみなす先頭語 (散文中の行を判定するために使う)。
 *
 * 判定は **小文字化してから**行う (Markdown は人間が書くため `Terraform apply` のような
 * 表記揺れが起こりうる。大小文字で素通りする穴を作らない)。
 */
const DEPLOY_SKILL_COMMAND_WORDS = [
    'bash', 'sh', 'zsh', 'dep', 'vendor/bin/dep', 'terraform', 'curl', 'wget',
    'ssh', 'scp', 'rsync', 'php', 'composer', 'pnpm', 'npm',
];

/** deploy 起動コマンドの先頭語 (この行だけ引数 allowlist を適用する)。 */
const DEPLOY_SKILL_DEP_COMMAND_WORDS = ['bash', 'dep', 'vendor/bin/dep'];

/**
 * deploy 起動コマンド行に現れてよい bare word (deny-by-default)。
 *
 * フラグ (`--check`) / パス (`deploy/deploy.php`) / placeholder (`<host>`) /
 * `key=value` / 日本語は判定対象外なので、ここに載るのはサブコマンド語だけになる。
 * host 名は必ず `<host>` placeholder で書くため、未登録の bare word は host literal を疑う。
 */
const DEPLOY_SKILL_ALLOWED_ARGUMENTS = ['deploy', 'rollback', 'list', 'tree', 'all'];

/**
 * 実行導線として禁止するコマンド (label => 正規表現)。
 *
 * 「禁止事項として言及すること」は許す必要があるため、**コマンド行に現れるか**だけを見る
 * (散文中の inline code は対象外)。
 */
const DEPLOY_SKILL_FORBIDDEN_COMMANDS = [
    'dep run (実ホストへ SSH する)' => '#(?:vendor/bin/)?\bdep\b[^\n]*\brun\b#i',
    'terraform の状態に触るサブコマンド' => '/\bterraform\b[^\n]*\b(?:plan|apply|destroy|import|refresh|state)\b/i',
    'HTTP クライアント (本番 URL への到達)' => '/\b(?:curl|wget)\b/i',
    'リモート接続 (ssh / scp / rsync)' => '/\b(?:ssh|scp|rsync)\b/i',
];

/** rollback の正典起動形 (語だけの言及では「正典」を固定できないので完全形を pin する)。 */
const DEPLOY_SKILL_ROLLBACK_COMMAND = 'vendor/bin/dep -f deploy/deploy.php rollback <host>';

/**
 * `<prefix>-prd` 形の host literal。
 *
 * 環境名の単語自体 (`staging` / `production`) は禁止しない (argument-hint の例と説明文で使う)。
 * 禁止するのは **ハイフンで環境 suffix を繋いだ host 名の形**である。
 */
const DEPLOY_SKILL_RE_HOST_SUFFIX = '/\b[a-z][a-z0-9]*(?:-[a-z0-9]+)*-(?:prd|stg|prod|production|staging)\b/i';

/**
 * 値を持たない YAML mapping key だけの行。
 *
 * SKILL.md は hosts.yml の中身を貼らない (host 一覧は実ファイルを読んで得る) ため、
 * この形の行は**書式ごと**禁止する。「host 名かどうか」を意味的に判定するのは不可能なので
 * deny-by-default にしている (YAML の例が本当に必要になったら runbook 側に置く)。
 */
const DEPLOY_SKILL_RE_YAML_KEY = '/^\s*[a-z0-9][a-z0-9-]*:\s*$/i';

/**
 * リポジトリ相対パスのファイル内容。
 *
 * **不在は例外にする** (空文字を返してはならない)。禁止 needle の検査は空文字に対して必ず
 * pass するため、検査対象が消えた瞬間に gate が green になる = 偽グリーンになる。
 */
function deploySkillReadRepoFile(string $relative): string
{
    $absolute = base_path($relative);
    $contents = is_file($absolute) ? file_get_contents($absolute) : false;

    if (! is_string($contents)) {
        throw new RuntimeException('gate の検査対象が読めません: '.$relative);
    }

    return $contents;
}

/** SKILL.md の内容。 */
function deploySkillRead(): string
{
    return deploySkillReadRepoFile(DEPLOY_SKILL_PATH);
}

/**
 * 正規表現の全マッチ (グループ 0)。
 *
 * @return list<string>
 */
function deploySkillMatches(string $pattern, string $subject): array
{
    $count = preg_match_all($pattern, $subject, $found);
    if ($count === false || $count === 0) {
        return [];
    }

    $matches = [];
    foreach ($found[0] as $match) {
        if (is_string($match)) {
            $matches[] = $match;
        }
    }

    return $matches;
}

/**
 * 空白区切りの語。
 *
 * @return list<string>
 */
function deploySkillWords(string $line): array
{
    $split = preg_split('/\s+/', trim($line));
    if ($split === false) {
        return [];
    }

    $words = [];
    foreach ($split as $word) {
        if ($word !== '') {
            $words[] = $word;
        }
    }

    return $words;
}

/**
 * front matter (先頭の `---` 囲み) を `key => value` で返す。
 *
 * 不在 / 未終端なら空配列を返し、呼び出し側が fail させる (deny-by-default)。
 *
 * @return array<string, string>
 */
function deploySkillFrontMatter(string $text): array
{
    $lines = explode("\n", $text);
    if (($lines[0] ?? '') !== '---') {
        return [];
    }

    $keys = [];
    for ($index = 1; $index < count($lines); $index++) {
        $line = $lines[$index];

        if (trim($line) === '---') {
            return $keys;
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9-]*)\s*:\s*(.*)$/', $line, $matches) === 1) {
            $keys[$matches[1]] = trim($matches[2]);
        }
    }

    // 終端の `---` が無い = front matter が壊れている。
    return [];
}

/**
 * 「コマンド行」を抽出する。
 *
 * 2 系統を対象にする:
 *  1. code block の中の非コメント行 — 全部コマンドとして扱う
 *  2. 散文中の行で、行頭のリストマーカー / backtick / `$ ` を剥がした結果が
 *     コマンド語で始まるもの (inline code は閉じ backtick までを取る)
 *
 * 散文中の inline code を「行頭にあるときだけ」対象にするのが要点である。
 * `**禁止**: \`dep run\` を実行しない` のような**禁止の言及**は素通しし、
 * `- \`dep run ...\`` のような**手順としての提示**は落とす。
 *
 * **code block の書式を 1 つに決め打ちしない** (Codex R1 / R2 指摘)。``` だけを見ると
 * `~~~` fence / blockquote (`> terraform apply`) / HTML の `<pre>`・`<code>` /
 * shell prompt 形 (`# terraform apply`) / 別のリストマーカー / 行継続へ
 * コマンドを逃がせてしまい、S7 が偽グリーンになる。したがって:
 *  - fence マーカーは ``` と ~~~ の両方を扱う (開いたマーカーと同種でのみ閉じる)
 *  - 行頭の blockquote マーカー (`>` の連続) は剥がしてから判定する
 *  - `pre` / `code` / `kbd` / `samp` の開閉タグは**属性付きも含めて丸ごと除去**する
 *    (fence へ変換する形は `<pre class="x">cmd</pre>` の 1 行完結形で中身を失う)
 *  - 4 桁以上のインデント (indented code block) は系統 2 の `^\s*` で吸収される
 *  - **shell の行継続 (`\` + 改行) は前処理で 1 行に畳む**
 *  - リストマーカーは `-` / `*` / `+` / `1.` / `1)` を扱う
 *  - **code block 内の `#` 始まりの行を素通しにしない**。Markdown の code block では
 *    `#` は「コメント」とも「root prompt」とも読めるうえ、いずれにせよ
 *    **禁止コマンドを手順として提示していること**に変わりが無い。prompt マーカー
 *    (`#` / `$` / `%` / `>`) を剥がしてから判定する
 *
 * @return list<string>
 */
function deploySkillCommandLines(string $text): array
{
    $commands = [];
    $fence = null;

    // shell の行継続を畳む (`terraform \` + 次行 `apply` で needle を割る経路を閉じる)。
    $text = preg_replace('/[ \t]*\\\\\n[ \t]*/', ' ', $text) ?? $text;

    foreach (explode("\n", $text) as $raw) {
        // blockquote マーカーを剥がす (fence 外へコマンドを逃がす経路を閉じる)。
        $line = preg_replace('/^\s*(?:>\s?)+/', '', $raw) ?? $raw;

        // HTML の code block タグは**属性付きも含めて丸ごと除去**する
        // (`<pre class="language-bash">terraform apply</pre>` のような 1 行完結形もあるため、
        // fence への変換ではなくタグ除去にする。除去後は系統 2 が拾う)。
        $line = preg_replace('#</?(?:pre|code|kbd|samp)(?:\s[^>]*)?>#i', '', $line) ?? $line;

        $trimmed = trim($line);

        if (preg_match('/^(`{3,}|~{3,})/', $trimmed, $marker) === 1) {
            $kind = str_starts_with($marker[1], '`') ? 'backtick' : 'tilde';

            if ($fence === null) {
                $fence = $kind;
            } elseif ($fence === $kind) {
                $fence = null;
            }

            continue;
        }

        if ($fence !== null) {
            // prompt / コメントマーカーを剥がす (`# terraform apply` を素通しにしない)。
            $inside = preg_replace('/^[#$%>]+[ \t]*/', '', $trimmed) ?? $trimmed;

            if (trim($inside) !== '') {
                $commands[] = trim($inside);
            }

            continue;
        }

        // 系統 2 でも **fence 内と同じ prompt マーカー正規化を行う** (Codex R3 指摘)。
        // `    # terraform apply` / `> # terraform apply` は fence が無くても
        // 「そのまま打てる形」なので、fence の内外で正規化を非対称にしてはならない。
        $candidate = preg_replace('/^\s*(?:[-*+]\s+|\d+[.)]\s+)?/', '', $line) ?? $line;
        $candidate = ltrim($candidate, '`');
        $candidate = preg_replace('/^[#$%]+[ \t]*/', '', $candidate) ?? $candidate;
        $candidate = ltrim($candidate, '`');

        $closing = strpos($candidate, '`');
        if ($closing !== false) {
            $candidate = substr($candidate, 0, $closing);
        }

        $candidate = trim($candidate);
        $words = deploySkillWords($candidate);

        if ($candidate !== '' && in_array(strtolower($words[0] ?? ''), DEPLOY_SKILL_COMMAND_WORDS, true)) {
            $commands[] = $candidate;
        }
    }

    return $commands;
}

/**
 * 禁止された実行導線を返す (S7)。
 *
 * @return list<string>
 */
function deploySkillForbiddenCommands(string $text): array
{
    $hits = [];

    foreach (deploySkillCommandLines($text) as $command) {
        foreach (DEPLOY_SKILL_FORBIDDEN_COMMANDS as $label => $pattern) {
            if (preg_match($pattern, $command) === 1) {
                $hits[] = $label.': '.$command;
            }
        }
    }

    return array_values(array_unique($hits));
}

/**
 * host 名を書いている疑いのある箇所を返す (S6)。
 *
 * 3 系統:
 *  1. `<prefix>-prd` 形の host literal (行単位。`argument-hint:` 行は除外)
 *  2. deploy 起動コマンド行の未登録 bare word (host 名は `<host>` placeholder で書く)
 *  3. 値を持たない YAML mapping key の行 (hosts.yml の中身を貼る形を書式ごと禁止する)
 *
 * @return list<string>
 */
function deploySkillHostLiterals(string $text): array
{
    $hits = [];

    foreach (explode("\n", $text) as $raw) {
        // argument-hint は skill の起動例なので環境名を含みうる (設計の S6 注記)。
        if (preg_match('/^\s*argument-hint\s*:/', $raw) === 1) {
            continue;
        }

        foreach (deploySkillMatches(DEPLOY_SKILL_RE_HOST_SUFFIX, $raw) as $match) {
            $hits[] = 'host literal: '.$match;
        }

        if (preg_match(DEPLOY_SKILL_RE_YAML_KEY, $raw) === 1) {
            $hits[] = 'yaml mapping key (書式ごと禁止。YAML の例は runbook 側に置く): '.trim($raw);
        }
    }

    foreach (deploySkillCommandLines($text) as $command) {
        $words = deploySkillWords($command);
        if (! in_array(strtolower($words[0] ?? ''), DEPLOY_SKILL_DEP_COMMAND_WORDS, true)) {
            continue;
        }

        foreach (array_slice($words, 1) as $word) {
            // フラグ / パス / placeholder / key=value / 日本語は判定対象外。
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $word) !== 1) {
                continue;
            }

            if (in_array(strtolower($word), DEPLOY_SKILL_ALLOWED_ARGUMENTS, true)) {
                continue;
            }

            $hits[] = 'command argument: '.$word.' ('.$command.')';
        }
    }

    return array_values(array_unique($hits));
}

/**
 * allowlist 外のドメイン literal を返す。
 *
 * @return list<string>
 */
function deploySkillDomainHits(string $text): array
{
    $hits = [];
    $pattern = '/\b(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}\b/i';

    foreach (deploySkillMatches($pattern, $text) as $candidate) {
        $labels = explode('.', strtolower($candidate));
        $tld = $labels[count($labels) - 1];

        if (! in_array($tld, DEPLOY_SKILL_DOMAIN_TLDS, true)) {
            continue;
        }

        $registrable = implode('.', array_slice($labels, -2));
        if (in_array($registrable, DEPLOY_SKILL_DOMAIN_ALLOWLIST, true)) {
            continue;
        }

        $hits[] = $candidate;
    }

    return array_values(array_unique($hits));
}

/**
 * skill 本文に書かれてはならない座標を返す (S3)。
 *
 * @return list<string>
 */
function deploySkillCoordinateHits(string $text): array
{
    $hits = [];

    foreach (DEPLOY_SKILL_FORBIDDEN_PATHS as $needle) {
        if (str_contains($text, $needle)) {
            $hits[] = 'absolute path: '.$needle;
        }
    }

    foreach (DEPLOY_SKILL_DONOR_NEEDLES as $needle) {
        if (stripos($text, $needle) !== false) {
            $hits[] = 'donor name: '.$needle;
        }
    }

    foreach (deploySkillDomainHits($text) as $domain) {
        $hits[] = 'domain: '.$domain;
    }

    return array_values(array_unique($hits));
}

/**
 * 本文が参照している `docs/**.md` のパス。
 *
 * @return list<string>
 */
function deploySkillDocPaths(string $text): array
{
    return array_values(array_unique(
        deploySkillMatches('#\bdocs/[A-Za-z0-9_./-]+\.md\b#', $text)
    ));
}

/**
 * 座標 hygiene gate の scan root 台帳から `required` を静的に読む。
 *
 * 同 gate の関数を呼ばずにソースを読むのは、`composer test --parallel` が別ファイルの関数の
 * 定義を保証しないため (function_exists で分岐すると条件付き green = 空洞化する)。
 * 読めない / 判定できない場合は null を返し、呼び出し側が fail させる (deny-by-default)。
 */
function deploySkillHygieneRootRequired(string $source, string $root): ?bool
{
    $needle = "'".$root."' => [";
    $position = strpos($source, $needle);
    if ($position === false) {
        return null;
    }

    $window = substr($source, $position + strlen($needle), 400);

    // 次の root 定義に到達する前だけを見る (隣の entry の値を読まないため)。
    $next = strpos($window, "' => [");
    if ($next !== false) {
        $window = substr($window, 0, $next);
    }

    if (str_contains($window, "'required' => true")) {
        return true;
    }

    if (str_contains($window, "'required' => false")) {
        return false;
    }

    return null;
}

// ───────────────────────── S1-S2: 実在と front matter ─────────────────────────

test('S1: app-deploy skill の SKILL.md が実在する', function (): void {
    expect(is_dir(base_path(DEPLOY_SKILL_ROOT)))->toBeTrue();
    expect(is_file(base_path(DEPLOY_SKILL_PATH)))->toBeTrue();
    expect(trim(deploySkillRead()))->not->toBe('');
});

test('S2: front matter が t1 既存 skill と同形の 4 キーで name が app- 接頭辞である', function (): void {
    $front = deploySkillFrontMatter(deploySkillRead());

    expect(array_keys($front))->toBe(
        DEPLOY_SKILL_FRONT_MATTER_KEYS,
        'front matter のキー集合 / 順序が t1 の既存 skill と揃っていません'
    );

    expect($front['name'] ?? '')->toBe('app-deploy');
    expect($front['name'] ?? '')->toStartWith('app-');
    expect($front['user-invocable'] ?? '')->toBe('true');
    expect(trim($front['description'] ?? ''))->not->toBe('');
    expect(trim($front['argument-hint'] ?? ''))->not->toBe('');
});

// ───────────────────────── S3: 座標の非残存 ─────────────────────────

test('S3: SKILL.md に実座標 (絶対パス / 別アプリ名 / 実ドメイン) が無い', function (): void {
    expect(deploySkillCoordinateHits(deploySkillRead()))->toBe([]);
});

// ───────────────────────── S4-S5: 手順の完全性 ─────────────────────────

test('S4: rollback の正典コマンドと「反射的に打たない」判断が書かれている', function (): void {
    $text = deploySkillRead();

    // 「rollback という語がどこかにある」では正典を固定できない (Codex R1)。
    // **正典の起動形そのもの**がコマンド行として提示されていることを要求する。
    expect(deploySkillCommandLines($text))->toContain(
        DEPLOY_SKILL_ROLLBACK_COMMAND
    );
    expect($text)->toContain('反射的に rollback を打つのが最悪手');
    // 「コードは戻るが DB は戻らない」= forward-only migration との衝突を必ず書く。
    expect($text)->toContain('DB migration は戻らない');
});

test('S5: 参照している docs パスがすべて実在する', function (): void {
    $paths = deploySkillDocPaths(deploySkillRead());

    expect($paths)->not->toBe([], 'docs への参照が 1 つもありません (空振り)');

    $missing = [];
    foreach ($paths as $path) {
        if (! is_file(base_path($path))) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([]);
});

// ───────────────────────── S6-S7: 座標と実行導線 ─────────────────────────

test('S6: host 名を列挙せず deploy/hosts.yml を読む指示になっている', function (): void {
    $text = deploySkillRead();

    expect(deploySkillHostLiterals($text))->toBe([]);

    // `toContain` は可変長 needle を取るのでメッセージを渡せない (str_contains で判定する)。
    expect(str_contains($text, 'host 一覧は必ず `deploy/hosts.yml` を読んで得る'))->toBeTrue(
        'host 一覧を deploy/hosts.yml から得る指示が見つかりません'
    );
});

test('S7: 危険な実行導線 (dep run / terraform / 本番 URL / ssh) を持たない', function (): void {
    expect(deploySkillForbiddenCommands(deploySkillRead()))->toBe([]);
});

// ───────────────────────── S8: hygiene gate の反転 ─────────────────────────

test('S8: hygiene gate の scan root で app-deploy が required=true である', function (): void {
    $source = deploySkillReadRepoFile(DEPLOY_SKILL_HYGIENE_GATE);

    expect(deploySkillHygieneRootRequired($source, DEPLOY_SKILL_ROOT))->toBeTrue(
        'hygiene gate の scan root '.DEPLOY_SKILL_ROOT.' が required=true になっていません '.
        '(T030 で成果物を作ったので反転させる)'
    );
});

// ───────────────────────── S9: 検出器の自己テスト ─────────────────────────

test('S9: front matter パーサの自己テスト', function (): void {
    $valid = "---\nname: app-deploy\ndescription: x\nuser-invocable: true\nargument-hint: \"y\"\n---\n\n# body\n";
    expect(array_keys(deploySkillFrontMatter($valid)))->toBe(DEPLOY_SKILL_FRONT_MATTER_KEYS);
    expect(deploySkillFrontMatter($valid)['argument-hint'])->toBe('"y"');

    // 先頭が `---` でない / 終端が無い場合は空 (呼び出し側が fail する)
    expect(deploySkillFrontMatter("# body\nname: app-deploy\n"))->toBe([]);
    expect(deploySkillFrontMatter("---\nname: app-deploy\n"))->toBe([]);
});

test('S9: コマンド行抽出の自己テスト', function (): void {
    // fence の中は全部コマンド行。**`#` 始まりも素通しにしない** (Codex R2 の Critical):
    // code block の `#` は root prompt とも読めるうえ、どちらにせよ禁止コマンドの提示である。
    expect(deploySkillCommandLines("```\nterraform apply\n```\n"))->toBe(['terraform apply']);
    expect(deploySkillCommandLines("```bash\n# terraform apply\n```\n"))->toBe(['terraform apply']);
    expect(deploySkillCommandLines("```bash\n$ terraform apply\n```\n"))->toBe(['terraform apply']);
    // マーカーを剥がした結果が空になる行は落とす (罫線だけの行など)
    expect(deploySkillCommandLines("```\n#\n```\n"))->toBe([]);
    // 見出し行 (報告テンプレの `## 配布結果` 等) はマーカーを剥がして評価され、needle に当たらない
    expect(deploySkillCommandLines("```\n## 配布結果\n```\n"))->toBe(['配布結果']);

    // 散文: 行頭の backtick / `$ ` / リストマーカーを剥がしてコマンド語で始まるもの
    expect(deploySkillCommandLines('$ bash scripts/deploy.sh <host>'))->toBe(['bash scripts/deploy.sh <host>']);
    expect(deploySkillCommandLines('- `bash scripts/deploy.sh <host>` を実行する'))
        ->toBe(['bash scripts/deploy.sh <host>']);

    // 散文の途中に現れる inline code は「手順の提示」ではないので対象外
    expect(deploySkillCommandLines('**禁止**: `dep run` を skill の判断で実行しない'))->toBe([]);
    expect(deploySkillCommandLines('確認は `bash scripts/deploy.sh <host> --check` に任せる'))->toBe([]);

    // ── code block の書式ごとの逃げ道を塞げていること (Codex R1 の Critical) ──
    expect(deploySkillCommandLines("~~~\nterraform apply\n~~~\n"))->toBe(['terraform apply']);
    expect(deploySkillCommandLines("~~~bash\nterraform apply\n~~~\n"))->toBe(['terraform apply']);
    expect(deploySkillCommandLines('> terraform apply'))->toBe(['terraform apply']);
    expect(deploySkillCommandLines('> > terraform apply'))->toBe(['terraform apply']);
    expect(deploySkillCommandLines("<pre>\nterraform apply\n</pre>\n"))->toBe(['terraform apply']);
    expect(deploySkillCommandLines('<code>terraform apply</code>'))->toBe(['terraform apply']);
    // 4 桁インデントの code block
    expect(deploySkillCommandLines('    terraform apply'))->toBe(['terraform apply']);
    // 異種マーカーでは fence が閉じない (``` の中の ~~~ を終端と誤読しない)
    expect(deploySkillCommandLines("```\n~~~\nterraform apply\n```\n"))->toBe(['terraform apply']);
    // 大小文字の表記揺れ
    expect(deploySkillCommandLines('$ Terraform apply'))->toBe(['Terraform apply']);

    // ── リストマーカーの変種と行継続 (Codex R2 の Critical) ──
    expect(deploySkillCommandLines('+ `terraform apply` を実行する'))->toBe(['terraform apply']);
    expect(deploySkillCommandLines('1) `terraform apply` を実行する'))->toBe(['terraform apply']);
    expect(deploySkillCommandLines('2. `terraform apply` を実行する'))->toBe(['terraform apply']);
    // shell の行継続は 1 行に畳む (needle を改行で割れないようにする)
    expect(deploySkillCommandLines("```\nterraform \\\n  apply\n```\n"))->toBe(['terraform apply']);
    expect(deploySkillCommandLines("```\nbash scripts/deploy.sh \\\n  someconcretehost --check\n```\n"))
        ->toBe(['bash scripts/deploy.sh someconcretehost --check']);

    // ── fence 外でも prompt マーカーを正規化する (Codex R3 の Critical) ──
    expect(deploySkillCommandLines('    # terraform apply'))->toBe(['terraform apply']);
    expect(deploySkillCommandLines('> # terraform apply'))->toBe(['terraform apply']);
    expect(deploySkillCommandLines('    % terraform apply'))->toBe(['terraform apply']);
    expect(deploySkillCommandLines('    # bash scripts/deploy.sh someconcretehost --check'))
        ->toBe(['bash scripts/deploy.sh someconcretehost --check']);
    // Markdown の見出しはマーカーを剥がしても command 語にならないので素通しする
    expect(deploySkillCommandLines('## Phase 3: 実行 (非本番ホストのみ agent が起動してよい)'))->toBe([]);

    // ── 属性付き HTML タグ (Codex R3 の Warning) ──
    expect(deploySkillCommandLines('<pre class="language-bash">terraform apply</pre>'))
        ->toBe(['terraform apply']);
    expect(deploySkillCommandLines('<code class="language-shell">terraform apply</code>'))
        ->toBe(['terraform apply']);
    expect(deploySkillCommandLines("<pre class=\"x\">\nterraform apply\n</pre>"))->toBe(['terraform apply']);
});

test('S9: 禁止実行導線の検出器の自己テスト', function (): void {
    // negative: 禁止事項としての言及は素通しする
    expect(deploySkillForbiddenCommands('**禁止**: `dep run` を skill の判断で実行しない'))->toBe([]);
    expect(deploySkillForbiddenCommands('**禁止**: `terraform apply` を叩く導線を作ること'))->toBe([]);
    expect(deploySkillForbiddenCommands("```\nvendor/bin/dep -f deploy/deploy.php deploy --plan <host>\n```"))
        ->toBe([]);
    expect(deploySkillForbiddenCommands("```\nvendor/bin/dep -f deploy/deploy.php rollback <host>\n```"))
        ->toBe([]);
    // `run` を含む別語 (runbook) を誤検出しない
    expect(deploySkillForbiddenCommands("```\nbash scripts/deploy.sh <host> --check\n```"))->toBe([]);
    expect(deploySkillForbiddenCommands('- `docs/production-deployment-runbook.md` を参照する'))->toBe([]);

    // positive: 実行導線は落とす
    expect(deploySkillForbiddenCommands('$ vendor/bin/dep -f deploy/deploy.php run \'ls\' <host>'))
        ->not->toBe([]);
    expect(deploySkillForbiddenCommands("```bash\nterraform apply\n```"))->not->toBe([]);
    expect(deploySkillForbiddenCommands("```bash\nterraform -chdir=infra/terraform plan\n```"))->not->toBe([]);
    expect(deploySkillForbiddenCommands("```\ncurl -I https://<PRIMARY_HOST>/up\n```"))->not->toBe([]);
    expect(deploySkillForbiddenCommands("```\nssh <host> systemctl status php-fpm\n```"))->not->toBe([]);

    // positive: code block の書式を変えても逃げられない (Codex R1 の Critical)
    expect(deploySkillForbiddenCommands("~~~bash\nterraform apply\n~~~"))->not->toBe([]);
    expect(deploySkillForbiddenCommands('> terraform apply'))->not->toBe([]);
    expect(deploySkillForbiddenCommands("<pre>\nvendor/bin/dep -f deploy/deploy.php run 'ls' <host>\n</pre>"))
        ->not->toBe([]);
    expect(deploySkillForbiddenCommands('    curl -I https://<PRIMARY_HOST>/up'))->not->toBe([]);

    // positive: 大小文字の表記揺れでも逃げられない
    expect(deploySkillForbiddenCommands("```\nTerraform Apply\n```"))->not->toBe([]);
    expect(deploySkillForbiddenCommands("```\nCURL -I https://<PRIMARY_HOST>/up\n```"))->not->toBe([]);
    expect(deploySkillForbiddenCommands("```\nSSH <host> systemctl status php-fpm\n```"))->not->toBe([]);
    expect(deploySkillForbiddenCommands("```\nVENDOR/BIN/DEP -f deploy/deploy.php RUN 'ls' <host>\n```"))
        ->not->toBe([]);

    // positive: shell prompt 形 / 別リストマーカー / 行継続でも逃げられない (Codex R2 の Critical)
    expect(deploySkillForbiddenCommands("```\n# terraform apply\n```"))->not->toBe([]);
    expect(deploySkillForbiddenCommands("```\n# vendor/bin/dep -f deploy/deploy.php run 'ls' <host>\n```"))
        ->not->toBe([]);
    expect(deploySkillForbiddenCommands('+ `terraform apply`'))->not->toBe([]);
    expect(deploySkillForbiddenCommands('1) `terraform apply`'))->not->toBe([]);
    expect(deploySkillForbiddenCommands("```\nterraform \\\n  apply\n```"))->not->toBe([]);

    // positive: fence 外の prompt 形 / 属性付き HTML タグでも逃げられない (Codex R3)
    expect(deploySkillForbiddenCommands('    # terraform apply'))->not->toBe([]);
    expect(deploySkillForbiddenCommands('> # terraform apply'))->not->toBe([]);
    expect(deploySkillForbiddenCommands('<pre class="language-bash">terraform apply</pre>'))->not->toBe([]);
    expect(deploySkillForbiddenCommands(
        '<code class="language-shell">vendor/bin/dep -f deploy/deploy.php run \'ls\' &lt;host&gt;</code>'
    ))->not->toBe([]);
});

test('S9: host literal 検出器の自己テスト', function (): void {
    // negative: 環境名の単語 / placeholder / hosts.yml を読む指示は素通しする
    expect(deploySkillHostLiterals('argument-hint: "<host> [--check]  例: /app-deploy staging"'))->toBe([]);
    expect(deploySkillHostLiterals('host 一覧は必ず `deploy/hosts.yml` を読んで得る'))->toBe([]);
    expect(deploySkillHostLiterals('本番 / staging へのアプリ配布規約'))->toBe([]);
    expect(deploySkillHostLiterals("```\nbash scripts/deploy.sh <host> [--check] [--allow-dirty] [--production]\n```"))
        ->toBe([]);
    expect(deploySkillHostLiterals("```\nvendor/bin/dep -f deploy/deploy.php deploy --plan <host>\n```"))->toBe([]);
    expect(deploySkillHostLiterals("```\nvendor/bin/dep -f deploy/deploy.php tree deploy\n```"))->toBe([]);

    // positive: host literal / 具体 host を引数に取るコマンド / host ブロックの貼り付け
    expect(deploySkillHostLiterals('bash scripts/deploy.sh myapp-prd'))->not->toBe([]);
    expect(deploySkillHostLiterals("```\nbash scripts/deploy.sh myapp-stg\n```"))->not->toBe([]);
    expect(deploySkillHostLiterals("```\nbash scripts/deploy.sh someconcretehost --check\n```"))->not->toBe([]);
    expect(deploySkillHostLiterals("```yaml\nhosts:\n```"))->not->toBe([]);
    // dry-run / read-only のコマンドでも具体 host を書かせない (Codex R1 の Critical)
    expect(deploySkillHostLiterals("```\nvendor/bin/dep -f deploy/deploy.php deploy --plan myhost\n```"))
        ->not->toBe([]);
    expect(deploySkillHostLiterals("```\nvendor/bin/dep -f deploy/deploy.php deploy --plan staging-host\n```"))
        ->not->toBe([]);
    expect(deploySkillHostLiterals("```\nvendor/bin/dep -f deploy/deploy.php rollback myapp-prd\n```"))
        ->not->toBe([]);
    // 大文字の host suffix も落とす
    expect(deploySkillHostLiterals('bash scripts/deploy.sh MyApp-PRD'))->not->toBe([]);
    expect(deploySkillHostLiterals("```yaml\nMyApp-Prd:\n```"))->not->toBe([]);
    // shell prompt 形 / 行継続で具体 host を隠せない (Codex R2 の Critical)
    expect(deploySkillHostLiterals("```\n# bash scripts/deploy.sh someconcretehost --check\n```"))
        ->not->toBe([]);
    expect(deploySkillHostLiterals("```\nbash scripts/deploy.sh \\\n  someconcretehost --check\n```"))
        ->not->toBe([]);
    // fence 外の prompt 形 / 属性付き HTML タグでも具体 host を隠せない (Codex R3)
    expect(deploySkillHostLiterals('    # bash scripts/deploy.sh someconcretehost --check'))->not->toBe([]);
    expect(deploySkillHostLiterals('<pre class="x">bash scripts/deploy.sh someconcretehost</pre>'))
        ->not->toBe([]);
});

test('S9: 座標検出器の自己テスト', function (): void {
    // negative: リポジトリ相対パス / ファイル名 / 予約ドメインは素通しする
    expect(deploySkillCoordinateHits('`deploy/hosts.yml` を読む'))->toBe([]);
    expect(deploySkillCoordinateHits('`docs/production-deployment-runbook.md` を参照'))->toBe([]);
    expect(deploySkillCoordinateHits('`tests/Architecture/DeploySkillContractTest.php`'))->toBe([]);
    expect(deploySkillCoordinateHits('`package.json` の `packageManager`'))->toBe([]);
    // ベンダー endpoint / ツールチェーンのサイトは座標ではない
    expect(deploySkillCoordinateHits('SES の endpoint は email.amazonaws.com である'))->toBe([]);

    // positive: 絶対パス / 別アプリ名 / 実ドメイン
    expect(deploySkillCoordinateHits('deploy_path は /srv/app である'))->not->toBe([]);
    expect(deploySkillCoordinateHits('cd /Users/someone/repo'))->not->toBe([]);
    expect(deploySkillCoordinateHits('home is /home/someone'))->not->toBe([]);
    expect(deploySkillCoordinateHits(DEPLOY_SKILL_DONOR_NEEDLES[0].' の手順を参照'))->not->toBe([]);
    expect(deploySkillCoordinateHits('app.some-real-domain.jp へ配布する'))->not->toBe([]);
    // **予約ドメインも落とす**: SKILL.md はホストの例示を一切持たない (設計付録 11)
    expect(deploySkillCoordinateHits('APP_URL=https://app.example.com'))->not->toBe([]);
});

test('S9: docs パス抽出の自己テスト', function (): void {
    expect(deploySkillDocPaths('`docs/a-b.md` と `docs/sub/c.md`'))->toBe(['docs/a-b.md', 'docs/sub/c.md']);
    expect(deploySkillDocPaths('docs ディレクトリ'))->toBe([]);
});

test('S9: hygiene gate 台帳リーダの自己テスト', function (): void {
    $ledger = "        '".DEPLOY_SKILL_ROOT."' => [\n"
        ."            'required' => true,\n"
        ."            'reason' => '',\n"
        ."        ],\n"
        ."        'other' => [\n"
        ."            'required' => false,\n"
        ."            'reason' => 'later',\n"
        ."        ],\n";

    expect(deploySkillHygieneRootRequired($ledger, DEPLOY_SKILL_ROOT))->toBeTrue();
    expect(deploySkillHygieneRootRequired($ledger, 'other'))->toBeFalse();
    // 未登録 root は null (呼び出し側が fail する)
    expect(deploySkillHygieneRootRequired($ledger, 'missing'))->toBeNull();
});
