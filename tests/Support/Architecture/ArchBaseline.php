<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use App\Services\Help\McpToolScanner;
use App\Services\Manual\SopTextExtractor;
use App\Services\Storage\Fakes\FakeObjectStore;
use App\Support\ProductionEnvGuard;
use App\Support\QueueDispatchAtomicityGuard;
use Webmozart\Assert\Assert;

/**
 * Pest arch ベースラインの**値の置き場** (不変の定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・git 実行を一切持たない** (`Tests\Support\TemplateDivergence\LedgerPins` と同型)。
 * ★正典 v1 の「例外一覧の単一の置き場」に対応する。禁止語彙と例外の登録は
 *   **本クラスの定数だけが正本**であり、gate も走査器も値をここから読む。
 * ★**これは免除の一覧ではない**。`ignoring` に載る対象は
 *   「その 1 シンボルだけを見る規則」へ隔離され、波及半径は定義上 1 シンボルに閉じる。
 *
 * ★**語彙はすべて小文字で書く**。vendor の
 *   `Pest\Arch\Repositories\ObjectsRepository::allByNamespace()` は
 *   `function_exists($v) && (new ReflectionFunction($v))->getName() === $v` を関門にする。
 *   `getName()` が返すのは**宣言時の正規名**で、vendor preset が対象とする現行の
 *   組み込み関数・ヘルパではそれが小文字である。したがって
 *   **大文字混じりの綴りを書くと層が空になり黙って無効化される**。
 *   `ArchBaselineTest` の S3 が「語彙はすべて小文字」を機械で固定するのは、この
 *   **vendor 集合との一致を守るため**である
 *   (ユーザー定義関数一般について `getName()` が小文字を返すと主張しているわけではない)。
 *
 * ★**保証しないもの (検出力を誇張しない)**:
 *   本クラスが列挙する 97 語彙のうち、Pest arch が依存側の層を作れるのは
 *   (1) `Pest\Arch\Support\PhpCoreExpressions::getClass($v) !== null` の言語構文と
 *   (2) `function_exists($v) && (new ReflectionFunction($v))->getName() === $v` を満たす関数、
 *   の 2 つだけである。それ以外は層が空になり、**その語彙の規則は落ちようがない**。
 *   **活性判定は常に実行環境依存である** — polyfill やユーザー定義関数で
 *   `function_exists()` が真になり得るし、拡張やパッケージの有無でも変わる。
 *   **件数は pin しない** (環境差だけで赤くなる検査を作らないため)。
 *   分類の再計算方法は `ArchBaselineTest` の docblock に書いてある。
 */
final class ArchBaseline
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /**
     * 規則の正本。
     *
     * `description` は禁止表明のテスト名になる。**検出力を主張しない規則は
     * その旨を description に書く** (テスト一覧に出るため)。
     *
     * @var array<string, array{description: string, symbols: list<string>, exceptions: list<class-string>, rationale: string}>
     */
    public const array RULES = [
        'AB-1' => [
            'description' => 'AB-1: php preset のデバッグ・出力・実行制御系 56 語彙 (例外なし)',
            'symbols' => [
                'debug_zval_dump', 'debug_backtrace', 'debug_print_backtrace', 'dump', 'ray', 'ds',
                'die', 'goto', 'global', 'var_dump', 'phpinfo', 'echo', 'print', 'print_r',
                'xdebug_break', 'xdebug_call_class', 'xdebug_call_file', 'xdebug_call_int',
                'xdebug_call_line', 'xdebug_code_coverage_started', 'xdebug_connect_to_client',
                'xdebug_debug_zval', 'xdebug_debug_zval_stdout', 'xdebug_dump_superglobals',
                'xdebug_get_code_coverage', 'xdebug_get_collected_errors', 'xdebug_get_function_count',
                'xdebug_get_function_stack', 'xdebug_get_gc_run_count',
                'xdebug_get_gc_total_collected_roots', 'xdebug_get_gcstats_filename',
                'xdebug_get_headers', 'xdebug_get_monitored_functions', 'xdebug_get_profiler_filename',
                'xdebug_get_stack_depth', 'xdebug_get_tracefile_name', 'xdebug_info',
                'xdebug_is_debugger_active', 'xdebug_memory_usage', 'xdebug_notify',
                'xdebug_peak_memory_usage', 'xdebug_print_function_stack', 'xdebug_set_filter',
                'xdebug_start_code_coverage', 'xdebug_start_error_collection',
                'xdebug_start_function_monitor', 'xdebug_start_gcstats', 'xdebug_start_trace',
                'xdebug_stop_code_coverage', 'xdebug_stop_error_collection',
                'xdebug_stop_function_monitor', 'xdebug_stop_gcstats', 'xdebug_stop_trace',
                'xdebug_time_index', 'xdebug_var_dump', 'trap',
            ],
            'exceptions' => [],
            'rationale' => '診断出力・実行制御の語彙。アプリコードは Log ファサードと例外で診断するため例外を要しない',
        ],
        'AB-2' => [
            'description' => 'AB-2: PHP 8 標準環境に組み込みが存在しない手続き API 16 語彙 (vendor 集合との整合用。現環境では検出力を主張しない)',
            'symbols' => [
                'ereg', 'eregi', 'mysql_connect', 'mysql_pconnect', 'mysql_query', 'mysql_select_db',
                'mysql_fetch_array', 'mysql_fetch_assoc', 'mysql_fetch_object', 'mysql_fetch_row',
                'mysql_num_rows', 'mysql_affected_rows', 'mysql_free_result', 'mysql_insert_id',
                'mysql_error', 'mysql_real_escape_string',
            ],
            'exceptions' => [],
            'rationale' => 'PHP 8 の標準環境には組み込みとして存在しないため書いても層が空になる。集合一致 (S5) を保つための受け皿であり検出力は主張しない',
        ],
        'AB-3' => [
            'description' => 'AB-3: laravel preset の開発補助語彙 4 語彙 (例外なし)',
            'symbols' => ['dd', 'ddd', 'env', 'exit'],
            'exceptions' => [],
            'rationale' => 'Laravel の開発補助。env() は config 層だけの作法で app 配下は config() 経由に統一済みのため例外を要しない',
        ],
        'AB-4' => [
            'description' => 'AB-4: security preset のうち例外を要しない 18 語彙',
            'symbols' => [
                'md5', 'uniqid', 'rand', 'mt_rand', 'str_shuffle', 'shuffle', 'array_rand', 'eval',
                'exec', 'shell_exec', 'system', 'passthru', 'create_function', 'unserialize',
                'extract', 'mb_parse_str', 'dl', 'assert',
            ],
            'exceptions' => [],
            'rationale' => '暗号・乱数・任意コード実行の語彙。乱数は Str::random と CipherSweet 経由に統一済みで例外を要しない',
        ],
        'AB-5' => [
            'description' => 'AB-5: sha1 のみ (例外 1 クラス)',
            'symbols' => ['sha1'],
            'exceptions' => [FakeObjectStore::class],
            'rationale' => 'ローカル fake のロックファイル名生成に使う。暗号用途ではなく衝突しない一意名が要るだけである',
        ],
        'AB-6' => [
            'description' => 'AB-6: tempnam のみ (例外 1 クラス)',
            'symbols' => ['tempnam'],
            'exceptions' => [SopTextExtractor::class],
            'rationale' => 'SOP 取込で表計算ファイルを一時ファイルへ落とす。生成直後に unlink する短命な経路である',
        ],
        'AB-7' => [
            'description' => 'AB-7: var_export のみ (例外 3 クラス)',
            'symbols' => ['var_export'],
            'exceptions' => [
                McpToolScanner::class,
                ProductionEnvGuard::class,
                QueueDispatchAtomicityGuard::class,
            ],
            'rationale' => 'fail-fast の診断メッセージで実測値を人間に見せる。出力先は例外メッセージだけで HTTP 応答本文へは出ない',
        ],
    ];

    /**
     * 規則ごとの対象シンボル数の pin。無断の増減で赤になる。
     *
     * @var array<string, int>
     */
    public const array SYMBOL_COUNT_PINS = [
        'AB-1' => 56,
        'AB-2' => 16,
        'AB-3' => 4,
        'AB-4' => 18,
        'AB-5' => 1,
        'AB-6' => 1,
        'AB-7' => 1,
    ];

    /** 7 規則の和集合の語彙数 (= vendor 3 preset の禁止語彙の和集合)。**総数 pin はここ 1 か所だけ**。 */
    public const int TOTAL_SYMBOL_COUNT = 97;

    /**
     * 名前が動的に決まるメンバ参照の目録 (ファイル => {count, rationale})。
     *
     * ★**これは arch の例外ではない**。「走査器が名前を解決できない形の在庫」であり、
     *   **人手で用途を確認して受容した未解決箇所**であって安全である証明ではない。
     * ★**同一ファイル内での置換は検出しない** (件数が変わらないため)。
     * ★**配列全体が空になることは許容する** (動的構文が 1 件も無い状態は望ましい)。
     *   ただし**登録行の `count` は 1 以上**でなければならない — `count: 0` の行は
     *   「かつて在ったが消えた」腐った登録である (S3 が固定)。
     *
     * @var array<string, array{count: int, rationale: string}>
     */
    public const array DYNAMIC_MEMBER_INVENTORY = [
        'tests/Feature/Billing/BillingAccessStateTest.php' => [
            'count' => 1,
            'rationale' => 'factory state 名をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Feature/Billing/BillingCheckoutSessionModelTest.php' => [
            'count' => 2,
            'rationale' => 'factory state 名をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Feature/Invitations/AcceptInvitationInAppTest.php' => [
            'count' => 1,
            'rationale' => 'factory state 名をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Feature/Invitations/PendingInvitationScopeTest.php' => [
            'count' => 1,
            'rationale' => 'factory state 名をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Feature/Organizations/TwoFactorEnforcementTest.php' => [
            'count' => 1,
            'rationale' => 'HTTP verb をデータセットで回す形。arch のチェーンとは無関係な業務テストである',
        ],
        'tests/Unit/Exceptions/AnalysisFailedExceptionTest.php' => [
            'count' => 1,
            'rationale' => '名前付きコンストラクタをデータセットで回す形。arch のチェーンとは無関係な単体テストである',
        ],
    ];

    /**
     * S4 が **`tests/` 全数で 0 件**に固定する名前 (arch 表明を宣言する糖衣構文)。
     *
     * ★**`arch()` は使わない**。`arch($description)` は `test($description)` を呼んで
     *   `TestCall` を返し、以降のチェーンを実行時 mixin (`Plugin::uses(Architectable::class)`)
     *   で解決する糖衣であり、**静的に型が付かない**
     *   (`vendor/bin/phpstan analyse --level=10` が `TestCall::expect()` を未定義と報告し、
     *   以降が `mixed` に落ちる)。本ベースラインは代わりに
     *   `test($description, fn)` の中で `expect(...)->not->toBeUsed()->ignoring(...)` を書く。
     *   規則の description がテスト名になる点は変わらず、**PHPStan は 0 エラー**になる。
     * ★0 件に固定するのは「2 本目の表明を別の書き方で足す」経路を塞ぐためでもある。
     *   **「ちょうど 1 件」より強い契約**である (数えるのではなく存在させない)。
     *
     * @var list<string>
     */
    public const array FORBIDDEN_DECLARATION_FUNCTIONS = ['arch'];

    /**
     * S4 が **`tests/` 全数でちょうど 1 件**に固定する名前 (**メンバ名として**現れるもの)。
     *
     * ★走査は `ArchSurfaceScanner::identifierSites()` (識別子トークンの完全一致)。
     *   メンバ名を動的にして綴りを回避する形は `dynamicMemberSites()` の
     *   exact-fit 目録が別途塞ぐ。
     *
     * @var list<string>
     */
    public const array SINGLE_MEMBER_NAMES = ['ignoring', 'toBeUsed'];

    /**
     * S4 が **`tests/` 全数で 0 件**に固定する名前 (callable 経由の実行語彙)。
     *
     * @var list<string>
     */
    public const array FORBIDDEN_CALLABLE_FUNCTIONS = [
        'call_user_func',
        'call_user_func_array',
        'forward_static_call',
        'forward_static_call_array',
    ];

    /**
     * S4 が `tests/` 全数で 0 件に固定する名前 (callable 化のメソッド)。
     *
     * `fromCallable` はメソッド名なので、走査契約は「呼び出し位置の末尾セグメント一致」
     * ではなく「メンバ名としての完全一致」で扱う。
     */
    public const string FORBIDDEN_CALLABLE_METHOD = 'fromCallable';

    /** S4 が `tests/` 全数で 0 件に固定する名前 (preset の一括使用)。 */
    public const string FORBIDDEN_PRESET_NAME = 'preset';

    /** チェーンを 1 本だけ持つ gate ファイル (S4 が位置まで固定する)。 */
    public const string CHAIN_HOST_FILE = 'tests/Architecture/ArchBaselineTest.php';

    /**
     * チェーンの位置を特定する錨になる綴り。
     *
     * ★`arch` を禁止名にした (`FORBIDDEN_DECLARATION_FUNCTIONS`) ので、
     *   位置の特定は「`tests/` 全数でちょうど 1 件」の**メンバ名**に取る。
     *   `expect` は `tests/` 全数に何百件もあるため錨にできない。
     * ★この綴りは `SINGLE_MEMBER_NAMES` にも `EXPECTED_CHAIN_TOKENS` にも現れる。
     *   3 か所の整合は S4 が実測で突き合わせる。
     */
    public const string CHAIN_ANCHOR_NAME = 'toBeUsed';

    /**
     * S4 が照合する arch チェーンの**囲み**の期待トークン列 (`arch` の直前 11 個)。
     *
     * ★チェーンの綴りだけを pin すると、`if (false) { … }` のような**実行されない位置**へ
     *   丸ごと移して 7 本の表明を無効化できる (綴りは 1 文字も変わらない)。
     *   囲みの綴りと**波括弧の深さ 0** を併せて固定することで、
     *   「ファイル最上位で全規則 ID をちょうど 1 周する」形だけを許す。
     *
     * @var list<string>
     */
    public const array EXPECTED_CHAIN_HEADER_TOKENS = [
        'foreach', '(', 'ArchBaseline', '::', 'ruleIds', '(', ')', 'as', '$ruleId', ')', '{',
        'test', '(', 'ArchBaseline', '::', 'descriptionOf', '(', '$ruleId', ')', ',',
        'function', '(', ')', 'use', '(', '$ruleId', ')', ':', 'void', '{',
    ];

    /**
     * S4 が照合する arch チェーンの期待トークン列 (綴りの列。空白とコメントは除く)。
     *
     * ★**この定数が期待形の唯一の正本**である。gate 側に写しを持たない。
     *
     * @var list<string>
     */
    public const array EXPECTED_CHAIN_TOKENS = [
        'expect', '(', 'ArchBaseline', '::', 'symbolsOf', '(', '$ruleId', ')', ')',
        '->', 'not', '->',
        'toBeUsed', '(', ')',
        '->', 'ignoring', '(', 'ArchBaseline', '::', 'exceptionsOf', '(', '$ruleId', ')', ')', ';',
    ];

    /**
     * S4 が照合する arch チェーンの**後置**の期待トークン列 (表明の文の直後 4 個)。
     *
     * 綴りは closure を閉じる `}` / `test(` を閉じる `)` / 文末の `;` / `foreach` を閉じる `}`。
     *
     * ★ヘッダーと表明の文だけを pin すると、**登録したあとに実行修飾を後置する**形
     *   (`})->skip();` / `})->todo();`) が**どの検査にも現れない**。
     *   ヘッダーも表明も 1 文字も変わらず、最上位の制御構造も打ち切りも増えず、
     *   7 本の description は**登録されている**ので S4-3c の missing も空になる。
     *   それでも closure は実行されず、禁止表明は 1 本も評価されない。
     *   **後置を exact-fit で閉じる**ことが、この形を塞ぐ唯一の静的手段である。
     *
     * @var list<string>
     */
    public const array EXPECTED_CHAIN_FOOTER_TOKENS = ['}', ')', ';', '}'];

    /**
     * 宿主ファイルの**最上位で呼んでよい関数名**の全体 (重複なし・昇順)。
     *
     * ★宿主ファイルの最上位で許す**素の関数呼び出しは `test` だけ**である
     *   (変数代入・クラス/関数宣言・メンバ呼び出しまで禁じるものではない)。
     *   Pest には宣言を残したまま実行だけ止めるファイル単位の入口があり
     *   (`beforeEach(fn () => $this->markTestSkipped())` を 1 つ置くと
     *   **そのファイルの全テストが skip される**)、この形は綴りにも波括弧の深さにも
     *   登録内容にも現れない。**最上位の呼び出しを `test` だけに限る**契約だけが捕まえる。
     *   `uses()` / `pest()` / `describe()` / `covers()` のような他の入口も同じ 1 つの契約で閉じる
     *   (禁止する名前の一覧を持たない = deny-by-default)。
     *
     * @var list<string>
     */
    public const array EXPECTED_TOP_LEVEL_CALL_NAMES = ['test'];

    /** @return list<string> */
    public static function ruleIds(): array
    {
        return array_keys(self::RULES);
    }

    public static function descriptionOf(string $ruleId): string
    {
        return self::rule($ruleId)['description'];
    }

    /** @return list<string> */
    public static function symbolsOf(string $ruleId): array
    {
        return self::rule($ruleId)['symbols'];
    }

    /** @return list<class-string> */
    public static function exceptionsOf(string $ruleId): array
    {
        return self::rule($ruleId)['exceptions'];
    }

    public static function rationaleOf(string $ruleId): string
    {
        return self::rule($ruleId)['rationale'];
    }

    /**
     * 動的メンバ目録 (`DYNAMIC_MEMBER_INVENTORY` の読み口)。
     *
     * ★gate は定数を直接読まず**必ずここを通す**。値の置き場と読み手の間に
     *   1 本の API を置くことで、目録の形を変えたときに読み手を全部たどれる。
     *
     * @return array<string, array{count: int, rationale: string}>
     */
    public static function dynamicMemberInventory(): array
    {
        return self::DYNAMIC_MEMBER_INVENTORY;
    }

    /**
     * 全規則の語彙の和集合 (重複なし・昇順)。
     *
     * @return list<string>
     */
    public static function allSymbols(): array
    {
        $symbols = [];
        foreach (self::RULES as $rule) {
            foreach ($rule['symbols'] as $symbol) {
                $symbols[] = $symbol;
            }
        }

        $symbols = array_values(array_unique($symbols));
        sort($symbols);

        return $symbols;
    }

    /**
     * 未知の規則 ID は**無言で空を返さず例外**にする。
     *
     * @return array{description: string, symbols: list<string>, exceptions: list<class-string>, rationale: string}
     */
    private static function rule(string $ruleId): array
    {
        Assert::keyExists(self::RULES, $ruleId, "未登録の規則 ID: {$ruleId}");

        return self::RULES[$ruleId];
    }
}
