<?php

declare(strict_types=1);

/*
 * `.env.example` の不変条件 (家系の裁定 AG-007 が定めた統合形)。
 *
 * このファイルは「読み物」ではなく**生きた既定値**である。3 つの経路が見本を
 * そのまま実環境にする — `composer setup` / composer.json の post-root-package-install /
 * scripts/setup-worktree.sh の復旧案内。よって見本の欠落・危険な値は
 * 「文書の不備」ではなく**実環境の不備**になる。
 *
 * 検査は 4 部品 + 2 つ:
 *   (a)   値の固定    — 行の完全一致で固定する (部分一致・コメント偽装を封鎖)
 *   (b)   キー網羅    — 必須キーを分類つきの台帳に持ち、存在を要求する (値は見ない)
 *   (c-1) 行の形式    — 非空・非コメント行は素の `KEY=` 形式のみ受理する
 *   (c-2) 重複        — 代入キーが全キー一意であることを要求する
 *   + 台帳の誠実性 (二重登録・台帳内の重複の禁止)
 *   + 反証の検査 (壊した入力を合成して解析器へ食わせる)
 *
 * ★本ファイルには**受理規則が逆向きの解析器が 2 つ同居する**。統合しない
 *   (統合すると片方の意図が壊れる):
 *
 *   |                      | envExampleParseContents (下) | collectUnresolvedEnvRefs (末尾) |
 *   |----------------------|------------------------------|---------------------------------|
 *   | 対象                 | `.env.example` の 1 枚だけ   | 見本 3 枚                       |
 *   | `export` つきの行     | **違反にする**               | 意図的に許容する                |
 *   | 先頭に空白のある代入 | **違反にする**               | 意図的に許容する                |
 *   | 見るもの             | キーと値・重複・行の形       | 値の中の `${VAR}` の解決可能性  |
 *
 *   `.env.example` については厳しい方 (行の形式の検査) が先に赤くなるので、
 *   緩い側の許容は残り 2 枚にしか意味を持たない。
 *
 * ★保証しないもの (誇張しない): 見るのは `.env.example` の中身だけで、実行中の `.env`・
 *   プロセスの環境変数・設定キャッシュには**無言で効かない**。キー網羅は存在だけを見る
 *   (空の値も通る)。`SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED` は本番起動時に
 *   要求されるが見本に 1 行も無いため**欠落を検出しない**。config の既定値と見本の値が
 *   食い違っていても検出しない (同期の検査ではなく**提示の検査**である)。
 *
 * 設計: devnotes/20260817-1309-todo-t213-env-example-gate-t1/
 */

/**
 * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルを読まない)。
 *
 * 行の分類:
 *   - 空白だけの行 → 実効値に影響しないので飛ばす
 *   - `^\s*#` の行 → コメント。同上
 *   - それ以外     → 素の代入行 `^[A-Z][A-Z0-9_]*=` **のみ**受理する
 *
 * ★これは dotenv の構文検査ではない。dotenv は `export FOO=1` も小文字のキーも読むが、
 *   本リポジトリの見本ファイルではそれらを許さない (存在検査・重複検査の母集合から
 *   外れたまま実効値だけを変えられる迂回になるため)。「見本に許す最小の書式」である。
 *
 * ★重複キーの値は**最初に現れた方**を記録する。dotenv は同一ファイル内の重複を
 *   **後に現れた方**で解決する。両者は食い違うので、重複が 1 件でもあると値の固定の検査は
 *   「実効値ではない値」を見ることになる。だから重複そのものを違反にする
 *   (どちらの解決順に合わせるかを選ばない)。
 *
 * 改行は CRLF / CR / LF のいずれでも行に割る (行末に CR を残さない)。
 * ★ただし**反証の表に CR 単独の行は無い** — 分割の規則が将来 CR 単独を落とすように弱っても
 *   赤くならない (保証範囲を誇張しないための注記)。
 * 値は前後の空白を落とさない (見本に書いてあるとおりを返す = 等号の後ろの空白は値の一部)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParseContents(string $contents): array
{
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $values = [];
    $duplicateKeys = [];
    $malformedLineNumbers = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            $malformedLineNumbers[] = $index + 1;

            continue;
        }
        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            // 同じキーが 3 回以上でも、重複の一覧にはキー名を 1 度だけ載せる (診断の安定)。
            if (! in_array($key, $duplicateKeys, true)) {
                $duplicateKeys[] = $key;
            }

            continue;
        }
        $values[$key] = $matches[2];
    }

    return [
        'values' => $values,
        'duplicateKeys' => $duplicateKeys,
        'malformedLineNumbers' => $malformedLineNumbers,
    ];
}

/**
 * `.env.example` を読んで解析する (**入出力のアダプタ**。判定は持たない)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParse(): array
{
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */

    return envExampleParseContents($contents);
}

/**
 * 値の固定: 裁定 AG-007 が名指しする 2 件。
 * 緩めるには家系の機能台帳側の裁定変更が要る (本リポジトリ単独では動かせない)。
 *
 * ★形式はキーと値の組の**リスト**にする (キー付きの連想配列にしない)。
 *   連想配列のリテラルは同じ定数の中の重複キーをコンパイル時に後勝ちで無音に潰すため、
 *   「行を足しただけに見える差分」で既存の固定を反転できてしまう。
 *   リストなら重複がそのまま残り、下の誠実性の検査が同じ機構で捕まえられる。
 */
const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
];

/**
 * 値の固定: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増。個別に理由を書く)。
 * - ADMIN_MFA_REQUIRED=true: false にすると管理画面の二要素が実質無効になる。
 *   local の値が本番へ写る事故の側が危険なので、見本は安全側で固定する。
 * - MCP_STRICT_TRANSPORT=true: false にすると Origin を送らないクライアントを受け入れる
 *   (DNS 再バインドの面が広がる)。
 */
const ENV_EXAMPLE_VALUE_PINS_AICUE = [
    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
];

/**
 * 値の固定の台帳の合成 (重複した組を保持したまま連結する)。
 *
 * @return list<array{key: string, value: string}>
 */
function envExampleValuePinEntries(): array
{
    return array_merge(ENV_EXAMPLE_VALUE_PINS_AG007_CORE, ENV_EXAMPLE_VALUE_PINS_AICUE);
}

/**
 * キー網羅の台帳。分類ごとに定数を分ける (平らな 1 本の配列にしない)。
 * 削るときに「どの根拠を外すのか」がレビューで見えるようにするためである。
 *
 * ★台帳は**床**であって天井ではない。`.env.example` に任意のキーを足すことは責務外で、
 *   完全一致の集合にはしない。
 *
 * (i) 新しい環境を立てるときに要る座標。`composer setup` と
 *     `scripts/setup-worktree.sh` の案内が `.env.example` をそのまま `.env` にするため、
 *     ここが欠けると「動かない .env」が出来上がる。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = [
    'APP_NAME',
    'APP_ENV',
    'APP_KEY',
    'APP_URL',
    'APP_LOCALE',
    'DB_CONNECTION',
    'SESSION_DRIVER',
    'QUEUE_CONNECTION',
    'CACHE_STORE',
];

/**
 * (ii) 本番の起動時に検査される座標のうち、**現在 `.env.example` に素の代入行として
 *      提示済みのもの**。正本は app/Support/ProductionEnvGuard.php で、依存は一方向である
 *      (guard が変われば本台帳が古くなる。機械では結線しない — guard が読むのは config の
 *      キーであって環境変数名ではないため、結ぶには config の構文解析が要る)。
 *
 * ★これは guard の要求の**写しではない**。guard は SECURITY_HSTS_ENABLED /
 *   SECURITY_CSP_ENABLED も本番で true と要求するが、この 2 つは `.env.example` に
 *   1 行も無く、載せるには見本の書き方の判断が要るため本台帳には入れない
 *   (**この 2 件の欠落は検出しない**)。
 *
 * ★SESSION_SECURE_COOKIE / ADMIN_MFA_REQUIRED 等は値の固定の台帳が値ごと押さえるため
 *   ここには載せない (台帳をまたぐ二重登録は下の誠実性の検査が禁じる)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD = [
    'CIPHERSWEET_KEY',
    'STRIPE_WEBHOOK_SECRET',
    'DEBUG_LOGIN_USER',
    'DEBUG_LOGIN_PASSWORD',
    'PRIMARY_HOST',
    'TRUSTED_HOSTS_ADDITIONAL',
    'TRUSTED_HOSTS_WILDCARD_SUFFIXES',
    'TRUSTED_PROXIES',
    'PASSKEYS_USER_HANDLE_SECRET',
];

/**
 * (iii) 提示が無いと環境ごとに別の名前が発明されて食い違う座標
 *       (外部との統合の秘密と、アプリ固有の座標)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION = [
    'STRIPE_KEY',
    'STRIPE_SECRET',
    'OPENAI_API_KEY',
    'ANTHROPIC_API_KEY',
    'GEMINI_API_KEY',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'RECAPTCHA_SITE_KEY',
    'RECAPTCHA_SECRET_KEY',
    'MCP_ALLOWED_ORIGINS',
    'PASSPORT_PRIVATE_KEY',
    'PASSPORT_PUBLIC_KEY',
    'TEMPLATE_APP_SLUG',
    'LEGAL_CONSENT_VERSION',
];

/**
 * (iv) 撮影テイクとレンダ成果物の保管先。本リポジトリ固有の分類である。
 *      撮影 PWA は presigned URL で直接アップロードし、合成した動画も同じ保管先へ置く。
 *      ここが欠けた環境では**撮った映像を保存できない** = 使命の中核が動かない。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE = [
    'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY',
    'AWS_DEFAULT_REGION',
    'AWS_BUCKET',
];

/**
 * キー網羅の台帳の合成 (4 分類の連結)。
 *
 * @return list<string>
 */
function envExampleRequiredKeys(): array
{
    return array_merge(
        ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
        ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
        ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION,
        ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE,
    );
}

test('a: .env.example は安全側の既定値を行の完全一致で満たす', function (): void {
    $parsed = envExampleParse();

    // 失敗時に出すのは**キー名だけ**である (見本の実値を出力しない)。
    $violations = [];
    foreach (envExampleValuePinEntries() as $entry) {
        if (($parsed['values'][$entry['key']] ?? null) !== $entry['value']) {
            $violations[] = $entry['key'];
        }
    }

    expect($violations)->toBe([]);
});

test('b: .env.example は必須キーの台帳を網羅する', function (): void {
    $parsed = envExampleParse();

    $missing = array_values(array_diff(envExampleRequiredKeys(), array_keys($parsed['values'])));

    expect($missing)->toBe([]);
});

test('c-1: .env.example の非空・非コメント行は素の代入行 (KEY=) だけである', function (): void {
    $parsed = envExampleParse();

    // `export` つき・先頭に空白がある代入・小文字のキー・等号の**前**の空白は、
    // 存在検査と重複検査の母集合から外れたまま実効値だけを変えられる迂回になるので、
    // 行の形ごと禁じる。等号の**後ろ**の空白は値の一部なので違反にしない。
    // ★これは dotenv の構文検査ではない (dotenv はこれらを読む)。
    //   「本リポジトリの見本ファイルに許す最小の書式」である。
    expect($parsed['malformedLineNumbers'])->toBe([]);
});

test('c-2: .env.example の代入キーは一意である (重複で値の固定を無音で覆せなくする)', function (): void {
    $parsed = envExampleParse();

    expect($parsed['duplicateKeys'])->toBe([]);
});

test('台帳の誠実性: 値の固定とキー網羅の二重登録・台帳の中の重複が無い', function (): void {
    // 値の固定は存在の検査を含むので、キー網羅への二重登録は台帳の腐敗になる
    // (どちらを緩めたのか追えなくなる)。機械的に禁じる。
    $required = envExampleRequiredKeys();

    $pinKeys = [];
    foreach (envExampleValuePinEntries() as $entry) {
        $pinKeys[] = $entry['key'];
    }

    // 組のリスト形式は重複を保持するので、この一意性の検査 1 本で
    // 台帳の中 (同じ定数の中) と台帳の間 (2 つの定数にまたがる重複) の両方を捕まえられる。
    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
    expect(array_values(array_unique($required)))->toBe($required);
});

/*
 * 反証の検査 (データ駆動)。見本ファイルは現に適合しているため、台帳駆動の検査は
 * 書いた瞬間に緑になる。それでは「壊れたら赤くなる」ことを誰も確かめていない。
 * そこで解析を純粋関数に分けておき、**壊した入力を合成して食わせる**検査を恒久で置く
 * (見本ファイルを実際に壊さずに「壊れたら赤くなる」ことを示せる)。
 *
 * ★これは dotenv の構文検査ではない。本リポジトリの見本ファイルに許す最小の書式である。
 */

test('反証: 解析器は合成した本文を仕様どおりに分解する', /**
 * 型注記は closure に直接付ける (将来 tests/ を PHPStan の解析対象へ入れても
 * iterable の値の型が欠けないようにするため)。
 *
 * @param array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * } $expected
 */
    function (string $contents, array $expected): void {
        expect(envExampleParseContents($contents))->toBe($expected);
    })->with([
        // R1: コメント偽装。t0 の部分一致 (toContain) はこれを通していた = 偽グリーンの本体。
        'R1 コメント偽装した代入行は実効値にならない' => [
            '# SESSION_SECURE_COOKIE=true',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R2: 字下げしたコメントを形式違反にしない。
        'R2 先頭に空白のあるコメント行は違反ではない' => [
            '   # コメント',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R3: 正常系の下限 (空行を飛ばす)。
        'R3 素の代入行と空行' => [
            "A=1\n\nB=2",
            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R4: 重複の検出と、解析器が**先勝ち**で記録すること。
        'R4 重複キーを検出し最初の値を記録する' => [
            "A=1\nA=2",
            ['values' => ['A' => '1'], 'duplicateKeys' => ['A'], 'malformedLineNumbers' => []],
        ],
        // R5: 3 回以上でも重複の一覧はキー名 1 件だけ (診断の安定)。
        'R5 3 回以上の重複でも一覧は 1 件' => [
            "A=1\nA=2\nA=3",
            ['values' => ['A' => '1'], 'duplicateKeys' => ['A'], 'malformedLineNumbers' => []],
        ],
        // R6: 複数キーの重複を取りこぼさない。
        'R6 複数キーの重複をすべて挙げる' => [
            "A=1\nB=2\nA=3\nB=4",
            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => ['A', 'B'], 'malformedLineNumbers' => []],
        ],
        // R7〜R12: 存在検査・重複検査の母集合から外れたまま実効値だけを変えられる迂回を塞ぐ。
        'R7 export つきの行は形式違反' => [
            'export A=1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R8 先頭に空白のある代入は形式違反' => [
            '  A=1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R9 小文字のキーは形式違反' => [
            'a=1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R10 等号の前の空白は形式違反' => [
            'A =1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R11 素の区切り線は形式違反' => [
            '--- 区切り ---',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R12 数字始まりのキーは形式違反' => [
            '1A=1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        // R13: CRLF の行末の CR を値に残さない。
        'R13 CRLF でも行末の CR を値に残さない' => [
            "A=1\r\nB=2",
            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R14: 等号の**後ろ**の空白は値の一部である (R10 と対で「前だけを違反にする」ことを固定する)。
        'R14 値の前後の空白を落とさない' => [
            'A= 1 ',
            ['values' => ['A' => ' 1 '], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R15: 行番号が 1 始まりで正しいこと。
        'R15 形式違反の行番号は 1 始まり' => [
            "A=1\nexport B=2\nc=3",
            ['values' => ['A' => '1'], 'duplicateKeys' => [], 'malformedLineNumbers' => [2, 3]],
        ],
        // R16: 端 (空ファイル) で落ちない。
        'R16 空文字列' => [
            '',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
    ]);

/*
 * env ファイルの `${VAR}` nested variable は「同一ファイル内の先行定義 or 実行環境変数」しか
 * 解決できない (APP_ENV 別ロードでは他ファイルを継承しない)。自己参照 (VAR="${VAR}") や
 * 前方参照はリテラル文字列がそのまま画面に露出する事故になる (bug-hunt F-01 の実例:
 * .env.bughunt.local の APP_NAME="${APP_NAME}" が全画面のタイトル/ロゴ/フッターに露出)。
 *
 * 意図的に「実行環境からの外部注入」を期待する参照は ENV_EXTERNAL_REF_ALLOWLIST に
 * ファイル => 変数名 => 理由 で登録する (deny-by-default)。
 */

/** @var array<string, array<string, string>> */
const ENV_EXTERNAL_REF_ALLOWLIST = [
    // '.env.example' => ['SOME_VAR' => '理由'],
];

/**
 * @return array<int, array{file: string, line: int, ref: string}> 違反一覧
 */
function collectUnresolvedEnvRefs(string $relativePath): array
{
    $contents = file_get_contents(base_path($relativePath));
    expect($contents)->toBeString();
    /** @var string $contents */
    $defined = [];
    $violations = [];

    foreach (explode("\n", $contents) as $i => $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        // export プレフィックス付き定義も将来混在しうるため許容する
        if (preg_match('/^(?:export\s+)?([A-Z0-9_]+)=(.*)$/', $trimmed, $m) !== 1) {
            continue;
        }
        [$_, $key, $value] = $m;

        // 値の中の ${VAR} 参照を全て検査 (定義行より前に VAR 定義が無ければ違反)
        if (preg_match_all('/\$\{([A-Z0-9_]+)\}/', $value, $refs) > 0) {
            foreach ($refs[1] as $ref) {
                $allowed = ENV_EXTERNAL_REF_ALLOWLIST[$relativePath][$ref] ?? null;
                if ($allowed === null && ! array_key_exists($ref, $defined)) {
                    $violations[] = ['file' => $relativePath, 'line' => $i + 1, 'ref' => $ref];
                }
            }
        }

        // 定義の登録は参照検査の後 (VAR="${VAR}" の自己参照を違反にするため)
        $defined[$key] = true;
    }

    return $violations;
}

test('コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い', function (): void {
    $violations = [];
    foreach (['.env.example', '.env.bughunt.local.example', '.env.testing'] as $file) {
        $violations = array_merge($violations, collectUnresolvedEnvRefs($file));
    }
    expect($violations)->toBe([], '未解決の ${VAR} 参照: '.json_encode($violations, JSON_UNESCAPED_SLASHES));
});
