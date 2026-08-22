<?php

declare(strict_types=1);

use Tests\Support\PhpReferenceScanner;
use Tests\Support\PhpTokenScan;
use Tests\Support\ReferenceKind;
use Tests\Support\TrackedPhpSourceFiles;

/*
| `tests/` 配下の**3 種類の字句参照**の全数申告 inventory —
|   (A) 定数 `PHP_BINARY` の参照 / (B) 文字列 `bootstrap/app.php` の参照 /
|   (C) 文字列 `fake-wiring-probe.php` (既存の子入口) の参照。
| lctl feature: subprocess-boot-probe-harness (正典 v1 の作法へ追従したあとの退行を検出する)。
| **本 gate は正典 v1 の 6 不変条件ではなく aicue 側の上積みである** (根拠: 正典テンプレートの
| 同型 gate と AGENTS.md 禁止事項 1)。
|
| **名前のとおり、これは「起動の全数」ではなく「参照の全数」の inventory である。**
| 「PHP の子プロセスを起こしうる箇所を漏れなく数える」ことは**していない**。
|
| ## 主張すること
|
| 「`PHP_BINARY` の字句参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
| 既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。
|
| ## 主張しないこと (名指しで書く)
|
|  1. 「アプリを子プロセスで起こす経路が共通の起動器ちょうど 1 本である」こと
|  2. 文字列リテラルの `'php'` / `env php` / シェルスクリプト経由 /
|     変数から取り出した実行体パスの検出
|  3. **起動呼び出しの分類** — 「どのクラスの `new` か」「`proc_open` かその別名か」といった
|     網羅的な分類は**行わない** (行えば「緑のまま嘘をつく」)。
|     G-6 が確かめるのは**共通の起動器への静的呼び出しが在ること**だけである
|  4. 文字列を分割して針を避ける形 (`'fake-wiring-'.'probe.php'`) の検出
|
| ## 軸ごとの名前解決の扱い (AGENTS.md §静的検査の共通規約 (a) / (b))
|
|  - **G-6 は完全修飾名で突き合わせる**。`Tests\Support\PhpReferenceScanner` が
|    `use` / group use / 別名つき取り込みを解いた FQCN を返すので、それを
|    `Tests\Support\Process\BootProbeRunner` と完全一致で比べる。
|    したがって `use … as Runner; Runner::run(` も**正しく検出する**一方、
|    **同名の別クラス** (`Other\BootProbeRunner::run(`) は**検出しない** (短名一致ではない)。
|    受け手が静的に確定できない形 (`$runner::run(` / `static::` 等) は
|    **「呼んでいる証拠」として数えない** — G-6 は存在を主張する検査なので、
|    未解決を証拠に数える方が危険側だからである
|  - **軸 A は名前トークンの末尾要素**で判定する。定数の参照には `PhpReferenceScanner` の
|    母集団 (クラス名の参照 / 構築 / 呼び出し) が対応しないためで、
|    ここは**拾いすぎる方向** ((b) の許す側) へ倒してある。
|    帰結として `Foo\PHP_BINARY` という**別の定数**も軸 A に入る
|    (申告を 1 行足せば済むので、見逃すより安全側である)
|
| **一元化そのものの証拠は載せ替えの実測 (`ExternalFakeBootProbeTest` の P-7〜P-15) であり、
| 本 gate は退行の検出器である。**
|
| ## 走査対象と走査の意味論
|
|  - 母集団は `Tests\Support\TrackedPhpSourceFiles` が返す **git 追跡下の `*.php`** のうち
|    `tests/` 配下 (**未追跡のファイルは母集団に入らない**。`TrackedPhpSourceFiles` の docblock)
|  - 判定は `Tests\Support\PhpTokenScan::normalize()` の上に建てる。
|    **コメント・docblock は正規化が落とすので数えない**
|  - 軸 A の「定数の参照」は**名前トークンの末尾要素の完全一致**で判定する
|    (`T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`)。区切りは `\` である。
|    `\PHP_BINARY` と `use const Foo\PHP_BINARY as X;` の別名 import も末尾要素で拾うので
|    fail-closed になる。接頭辞つき (`MY_PHP_BINARY`) / 打ち消しつき (`NOT_PHP_BINARY`) /
|    接尾辞つき (`PHP_BINARY_PATH`) は**別のトークン**なので拾わない
|    (AGENTS.md §静的検査の共通規約 (e) の 3 形。G-7 が両方向を固定する)
|  - 軸 B / 軸 C の「文字列の参照」は文字列トークン
|    (`T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`) の**素の部分文字列**一致である
|    (ヒアドキュメント・ナウドキュメントの本文を含む)
*/

/**
 * 軸 A: `tests/` 配下で `PHP_BINARY` を参照してよいファイルの全数申告 (deny-by-default)。
 *
 * entry は 4 つの欄を独立に持つ (「件数合わせの allowlist」へ流れないための構造):
 *  - `launches_app`: アプリを起こすと申告するか (**補助的な申告値**。実際の起動経路の
 *    全数性を表すものではなく、「アプリを起こす」と申告する先が分散していないことだけを固定する)
 *  - `subject` / `recovery` / `reason`
 *
 * @return array<string, array{launches_app: bool, subject: non-empty-string, recovery: non-empty-string, reason: non-empty-string}>
 */
function phpBootProbeBinaryReferenceInventory(): array
{
    return [
        'tests/Support/Process/BootProbeRunner.php' => [
            'launches_app' => true,
            'subject' => 'アプリを子プロセスで起こして起動順序を測る (PHP_BINARY)',
            'recovery' => '本クラス自身 (制限時間・段階的強制終了・終了コードの保持・一時ディレクトリの後片付け)',
            'reason' => '共通の起動器そのもの (lctl feature: subprocess-boot-probe-harness)',
        ],
        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
            'launches_app' => false,
            'subject' => '起動器の自己検査。参照は期待値の比較と、子へ渡す検体文字列の中だけである',
            'recovery' => '起動器 (本ファイルは直接の起動 API を持たず、BootProbeRunner 経由でのみ子を起こす)',
            'reason' => 'バイト一致で取り込んだ共有ファイルなので編集しない。起動器を通してしか子を起こさない',
        ],
        'tests/Support/StrictTypesRuntimeProbe.php' => [
            'launches_app' => false,
            'subject' => '検体 PHP を子で読み込み declare(strict_types=1) の実効性を測る。アプリは起こさない',
            'recovery' => 'Symfony の Process (既定の制限時間つきで、超過すれば例外になる)',
            'reason' => '起動順序ではなく単一ファイルのコンパイル指令を測る層である。起動器に載せると '
                .'Laravel 固有の基底環境・書き出し先 7 キーの予約という無関係な前提が付く '
                .'(同じ理由で PhpLintOracle も載せていない)',
        ],
        'tests/Support/GlobalUse/PhpLintOracle.php' => [
            'launches_app' => false,
            'subject' => '`php -l` を真値として取り出す (構文検査のみ。アプリは起こさない)',
            'recovery' => '同クラス (Symfony Process が管を読み切り、終了コードが null なら例外にする)',
            'reason' => 'アプリを起動しないので環境の 3 段合成も書き出し先の退避も要らない',
        ],
        'tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php' => [
            'launches_app' => false,
            'subject' => 'テスト DB の用意スクリプトを起こす (DB へは接続しない)。アプリは起こさない',
            'recovery' => '同ファイルの helper (管を読み切って proc_close する)',
            'reason' => 'アプリの起動順序ではなくスクリプトの契約を測る層である '
                .'(lctl feature: php-test-pgsql-lane 側の関心事。本 feature とは distinct_from の関係)',
        ],
        'tests/Architecture/NoNonCompoundGlobalUseTest.php' => [
            'launches_app' => false,
            'subject' => '診断メッセージへ実行体のパスを載せるだけ (子は起こさない)',
            'recovery' => '該当なし (起動しない)',
            'reason' => '起動は PhpLintOracle が行い、本ファイルは失敗時の診断に PHP_BINARY を印字するだけである',
        ],
        'tests/Feature/Console/PipelineSmokeCommandTest.php' => [
            'launches_app' => false,
            'subject' => 'ffmpeg の代役として設定値へ実行体のパスを入れるだけ (テストから子は起こさない)',
            'recovery' => '該当なし (起動するのはアプリ側の合成経路であり、本 feature の射程外)',
            'reason' => 'アプリの起動順序を測る経路ではない (ffmpeg 起動の統制は '
                .'tests/Architecture/FfmpegProcessLaunchInventoryTest.php が持つ)',
        ],
    ];
}

/**
 * 軸 B: `tests/` 配下でアプリの起動点 (`bootstrap/app.php`) を参照してよいファイルの全数申告。
 *
 * `kind` は 3 値:
 *  - `child_entry` : 子プロセスで読み込まれる入口 / 子へ渡す検体文字列
 *  - `in_process`  : 同一プロセスでのアプリ起動 (子プロセスではない)
 *  - `inventory`   : 検査定義・診断文としてパス文字列を保持するだけ
 *
 * `boots_repository_env` は「その経路で起きた**子**が、リポジトリの `.env` を読んで起動するか」。
 * **これは望ましさの宣言ではなく、危険面の目録である** (G-8 が件数と場所を pin する)。
 * 詳細は G-8 の docblock を読むこと。
 *
 * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', boots_repository_env: bool, reason: non-empty-string}>
 */
function phpBootProbeAppBootEntryReferenceInventory(): array
{
    return [
        'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
            'kind' => 'child_entry',
            // 専用の 0600 環境ファイルへ固定して起動する (リポジトリの .env は読まない)。
            'boots_repository_env' => false,
            'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
        ],
        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
            'kind' => 'child_entry',
            // ★S9 / S10 の検体はリポジトリ root を作業ディレクトリにして bootstrap/app.php を
            //   読むため、**リポジトリの .env がそのまま子の設定に載る** (実測で確認済み。G-8)。
            'boots_repository_env' => true,
            'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
        ],
        'tests/TestCase.php' => [
            'kind' => 'in_process',
            // 同一プロセスなので phpunit.xml の <server force> が効く (秘密は無害化済み)。
            'boots_repository_env' => false,
            'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
        ],
        'tests/Support/Cache/IsolatedApplicationProbe.php' => [
            'kind' => 'in_process',
            'boots_repository_env' => false,
            'reason' => 'キャッシュ受け皿の結線を測るための第 2 のアプリを同一プロセスで組み立てる。子プロセスではない',
        ],
        'tests/Architecture/CacheGuardWiringGateTest.php' => [
            'kind' => 'inventory',
            'boots_repository_env' => false,
            'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
        ],
        'tests/Architecture/BughuntExecutedRouteOrderingTest.php' => [
            'kind' => 'inventory',
            'boots_repository_env' => false,
            'reason' => '記録器の位置を固定する検査が、違反時の直し方を案内する診断文にパス文字列を持つ',
        ],
        'tests/Architecture/InertiaErrorScreenContractTest.php' => [
            'kind' => 'inventory',
            'boots_repository_env' => false,
            'reason' => '例外応答の最終整形スロットの登録位置を検査する側が、照合する場所としてパス文字列を持つ',
        ],
        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
            'kind' => 'inventory',
            'boots_repository_env' => false,
            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
        ],
    ];
}

/**
 * 軸 C: 子入口スクリプトのパスを参照してよいファイルの全数申告。
 *
 * `reference_kind` は 2 値: `runtime` (実行経路として子入口を起こす) / `inventory` (検査定義)。
 *
 * @return array<string, array{reference_kind: 'runtime'|'inventory', reason: non-empty-string}>
 */
function phpBootProbeChildEntryReferenceInventory(): array
{
    return [
        'tests/Support/ExternalFakes/FakeWiringProbeRunner.php' => [
            'reference_kind' => 'runtime',
            'reason' => '子入口を起こす唯一の呼び出し元。起こし方と回収は BootProbeRunner に委ねる',
        ],
        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
            'reference_kind' => 'inventory',
            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
        ],
    ];
}

/** 走査の針 (2 箇所に書かない)。 */
const PHP_BOOT_PROBE_APP_ENTRY_NEEDLE = 'bootstrap/app.php';

const PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE = 'fake-wiring-probe.php';

/** G-6 が完全修飾名で突き合わせる共通の起動器。 */
const PHP_BOOT_PROBE_RUNNER_FQCN = 'Tests\\Support\\Process\\BootProbeRunner';

/**
 * 名前トークンの末尾要素 (区切りは `\`)。
 *
 * `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` は 1 トークンで届くので、
 * 素の部分文字列一致ではなく区切りで割った完全一致で比べる
 * (AGENTS.md §静的検査の共通規約 (e))。
 */
function phpBootProbeLastNameSegment(string $name): string
{
    $segments = explode('\\', $name);

    return $segments[count($segments) - 1];
}

/**
 * ソースが定数 `$constant` を**名前として**参照しているか。
 *
 * 文字列リテラルの中の同じ綴りは数えない (トークン種別で区別する)。
 */
function phpBootProbeReferencesConstant(string $source, string $constant): bool
{
    foreach (PhpTokenScan::normalize($source) as $token) {
        if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        if (phpBootProbeLastNameSegment($token['text']) === $constant) {
            return true;
        }
    }

    return false;
}

/**
 * ソースの**文字列トークン**に `$needle` が現れるか
 * (ヒアドキュメント・ナウドキュメントの本文を含む。コメントは正規化が落とす)。
 */
function phpBootProbeReferencesStringNeedle(string $source, string $needle): bool
{
    foreach (PhpTokenScan::normalize($source) as $token) {
        if (! in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        if (str_contains($token['text'], $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * ソースが**共通の起動器**への静的呼び出し `BootProbeRunner::run(` を持つか。
 *
 * ★照合は**完全修飾名**で行う (AGENTS.md §静的検査の共通規約 (a))。
 *   `Tests\Support\PhpReferenceScanner` が `use` / group use / 別名つき取り込みを解いた
 *   FQCN を返すので、短名一致で同名の別クラスを拾うことも、別名 1 つで黙ることも無い。
 * ★受け手が静的に確定できない形 (`$runner::run(` / `static::` 等) は
 *   **証拠として数えない**。G-6 は「呼んでいる」ことを主張する検査なので、
 *   未解決を肯定側へ数える方が危険である。
 */
function phpBootProbeCallsBootProbeRunner(string $relativePath, string $source): bool
{
    foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
        if ($site->kind !== ReferenceKind::StaticCall || $site->name !== 'run') {
            continue;
        }

        if (! $site->receiver->isResolved()) {
            continue;
        }

        if ($site->receiver->fqcn() === PHP_BOOT_PROBE_RUNNER_FQCN) {
            return true;
        }
    }

    return false;
}

/**
 * 走査の母集団: git 追跡下の `tests/` 配下の `*.php` (相対パス => ソース)。
 *
 * @return array<string, string>
 */
function phpBootProbeTestSources(): array
{
    /** @var array<string, string>|null $cache */
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $sources = [];
    foreach (TrackedPhpSourceFiles::all(base_path()) as $file) {
        if (! str_starts_with($file['relative'], 'tests/')) {
            continue;
        }

        $source = file_get_contents($file['absolute']);
        if ($source === false) {
            // 読めないファイルを黙って落とすと走査が縮む (fail-closed)。
            throw new RuntimeException('走査対象を読めなかった: '.$file['relative']);
        }

        $sources[$file['relative']] = $source;
    }

    $cache = $sources;

    return $cache;
}

/**
 * 実測: 述語が真になった相対パスの昇順リスト。
 *
 * @param  callable(string): bool  $matches
 * @return list<string>
 */
function phpBootProbeMeasure(callable $matches): array
{
    $hits = [];
    foreach (phpBootProbeTestSources() as $relative => $source) {
        if ($matches($source)) {
            $hits[] = $relative;
        }
    }

    sort($hits);

    return $hits;
}

/** 申告のキーを昇順で取り出す。 @param array<string, mixed> $inventory @return list<string> */
function phpBootProbeDeclaredPaths(array $inventory): array
{
    $paths = array_keys($inventory);
    sort($paths);

    return $paths;
}

test('G-1 軸 A: PHP_BINARY を参照するファイルの集合が全数申告と完全一致する', function (): void {
    $measured = phpBootProbeMeasure(
        static fn (string $source): bool => phpBootProbeReferencesConstant($source, 'PHP_BINARY'),
    );

    expect($measured)->toBe(
        phpBootProbeDeclaredPaths(phpBootProbeBinaryReferenceInventory()),
        '未申告のファイルが PHP_BINARY を参照している、または申告が実体より多い。'
        .'足すときは launches_app / subject / recovery / reason の 4 欄を埋めること',
    );
});

test('G-2 軸 A: アプリを起こすと申告するのは共通の起動器ちょうど 1 件である', function (): void {
    $launching = array_keys(array_filter(
        phpBootProbeBinaryReferenceInventory(),
        static fn (array $entry): bool => $entry['launches_app'],
    ));

    expect($launching)->toBe(['tests/Support/Process/BootProbeRunner.php']);
});

test('G-3 軸 A: subject / recovery / reason の 3 欄がいずれも空でない', function (): void {
    foreach (phpBootProbeBinaryReferenceInventory() as $path => $entry) {
        expect(trim($entry['subject']))->not->toBe('', "subject が空: {$path}")
            ->and(trim($entry['recovery']))->not->toBe('', "recovery が空: {$path}")
            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
    }
});

test('G-4 軸 B: アプリの起動点を参照するファイルの集合が全数申告と完全一致し、kind が 3 値である', function (): void {
    $measured = phpBootProbeMeasure(
        static fn (string $source): bool => phpBootProbeReferencesStringNeedle(
            $source,
            PHP_BOOT_PROBE_APP_ENTRY_NEEDLE,
        ),
    );

    expect($measured)->toBe(
        phpBootProbeDeclaredPaths(phpBootProbeAppBootEntryReferenceInventory()),
        '未申告のファイルがアプリの起動点を参照している (kind と reason を 1 行足すこと)',
    );

    foreach (phpBootProbeAppBootEntryReferenceInventory() as $path => $entry) {
        // `toContain` は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
        expect(in_array($entry['kind'], ['child_entry', 'in_process', 'inventory'], true))
            ->toBeTrue("kind が 3 値の外: {$path}")
            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
    }
});

test('G-5 軸 C: 子入口を参照するファイルの集合が全数申告と完全一致し、reference_kind が 2 値である', function (): void {
    $measured = phpBootProbeMeasure(
        static fn (string $source): bool => phpBootProbeReferencesStringNeedle(
            $source,
            PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE,
        ),
    );

    expect($measured)->toBe(
        phpBootProbeDeclaredPaths(phpBootProbeChildEntryReferenceInventory()),
        '未申告のファイルが子入口スクリプトを参照している',
    );

    foreach (phpBootProbeChildEntryReferenceInventory() as $path => $entry) {
        // `toContain` は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
        expect(in_array($entry['reference_kind'], ['runtime', 'inventory'], true))
            ->toBeTrue("reference_kind が 2 値の外: {$path}")
            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
    }
});

test('G-6 軸 C: runtime はちょうど 1 件で、共通の起動器を実際に呼んでいる', function (): void {
    $runtime = array_keys(array_filter(
        phpBootProbeChildEntryReferenceInventory(),
        static fn (array $entry): bool => $entry['reference_kind'] === 'runtime',
    ));

    expect($runtime)->toBe(['tests/Support/ExternalFakes/FakeWiringProbeRunner.php']);

    $sources = phpBootProbeTestSources();
    foreach ($runtime as $path) {
        expect($sources)->toHaveKey($path);
        expect(phpBootProbeCallsBootProbeRunner($path, $sources[$path]))
            ->toBeTrue("{$path} が ".PHP_BOOT_PROBE_RUNNER_FQCN.'::run( を呼んでいない (子の起こし方が一元化から外れている)');
    }
});

/**
 * G-8: リポジトリの `.env` を読んで起動する**子**の目録 (危険面の pin)。
 *
 * ## 何を測っているか
 *
 * 共通の起動器は `proc_open` へ渡す環境配列で開発者ローカルの env を締め出すが、
 * **`.env` ファイルの読み込みまでは止めない**。子の作業ディレクトリはリポジトリ root なので、
 * 子が `bootstrap/app.php` を素で読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
 *
 * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査の S9 / S10 が使う検体でこれを確かめたところ、
 * 子の設定には `.env` 由来の値が入っていた — 外部サービスの資格情報
 * (Stripe / AWS / Google / SMTP) は本チェックアウトではいずれも空だったが、
 * **DB のパスワードと `CIPHERSWEET_KEY` は実値が載った**。
 * **「空だった」のはこのチェックアウトの性質であって、保証ではない。**
 *
 * ## なぜ止めずに目録にするのか
 *
 * 当該検体は**テンプレートからバイト一致で取り込んだ共有ファイル**の中にあり、
 * ここで書き換えると意図的逸脱の登録が要る (T249 の受入条件は「取り込み 3 本を編集しない」)。
 * したがって本 gate は**除去ではなく封じ込め**を担う —
 * この性質を持つ経路が**申告なしに増えない**ことだけを機械で固定する。
 *
 * ## 対比 (なぜ他の経路は false なのか)
 *
 *  - 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
 *    効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
 *    **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
 *    これが子と同一プロセスの非対称の正体である
 *  - `fake-wiring-probe.php` は専用の 0600 環境ファイルへ `useEnvironmentPath()` /
 *    `loadEnvironmentFrom()` で固定するので、リポジトリの `.env` を読まない
 *
 * ## 主張しないこと (誇張しない。Codex 実装レビュー Round 3 の指摘)
 *
 * **本検査が機械的に確かめるのは「申告」であって「実挙動」ではない。**
 * `boots_repository_env` の値と、その経路の子が実際に何を読むかを結び付ける検査は**持っていない**。
 * したがって次の退行は**本検査を通ってしまう**:
 *
 *  1. `fake-wiring-probe.php` から `useEnvironmentPath()` を落としつつ申告を `false` のままにする
 *  2. 新しい `child_entry` が `.env` を読むのに `false` と申告する
 *  3. 既存の `true` のファイルの中で、`.env` を読む検体を増やす (ファイル単位の件数は変わらない)
 *
 * **よって本検査はセキュリティ境界ではなく、上流課題を見える場所に置くための暫定の台帳である。**
 * 「危険面が申告なしに増えない」とは読めない (読めるのは「申告が黙って書き換わらない」までである)。
 *
 * ## 上流への申し送り (本検査では代替できない)
 *
 * 正典側 (lctl feature: subprocess-boot-probe-harness) で
 * 「アプリを起こす自己検査の子にも専用の環境ファイルを読ませる」ことを**先に**行うべきである。
 * 併せて「リポジトリの `.env` へ置いた番兵が子の設定に現れないこと」を測る自己検査があれば、
 * 実挙動の側で固定できる。解消されて再取り込みしたら、本 pin の `true` は 0 件になる。
 */
test('G-8 リポジトリの .env を読むと申告した経路は 1 件だけである (申告の pin。実挙動は測らない)', function (): void {
    $inventory = phpBootProbeAppBootEntryReferenceInventory();

    $bootsRepositoryEnv = array_keys(array_filter(
        $inventory,
        static fn (array $entry): bool => $entry['boots_repository_env'],
    ));

    // ★件数と場所を完全一致で pin する。増やすには「なぜその子が .env を読んでよいのか」を
    //   申告に書くことになり、レビューに必ず見える。
    expect($bootsRepositoryEnv)->toBe(
        ['tests/Unit/Support/Process/BootProbeRunnerTest.php'],
        'リポジトリの .env を読んで起動する子が増減している。'
        .'増やすなら G-8 の docblock を読み、なぜ専用の環境ファイルを使えないのかを申告すること',
    );

    // ★`true` を申告してよいのは**バイト一致で取り込んだ共有ファイル**だけである
    //   (aicue が自分で書いたファイルには、専用の環境ファイルを使わない言い訳が無い)。
    foreach ($bootsRepositoryEnv as $path) {
        expect(str_starts_with($path, 'tests/Unit/Support/Process/'))
            ->toBeTrue("aicue 所有のファイルがリポジトリの .env を読む子を持っている: {$path}");
    }

    // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
    //   定義上この危険面を持たない。取り違えを防ぐために両方向で固定する。
    foreach ($inventory as $path => $entry) {
        if ($entry['kind'] !== 'child_entry') {
            expect($entry['boots_repository_env'])
                ->toBeFalse("子プロセスではない経路に .env 読み込みが申告されている: {$path}");
        }
    }
});

test('G-7 走査が空振りしていない (走査根が実在し、3 軸の母集団が非空)', function (): void {
    expect(is_dir(base_path('tests')))->toBeTrue('走査根 tests/ が実在しない');

    $sources = phpBootProbeTestSources();
    expect(count($sources))->toBeGreaterThan(100, '母集団が縮んでいる (走査が壊れている可能性)');

    // 申告したパスは 3 軸とも実在する (改名・移動に気づかずに申告だけが残るのを防ぐ)。
    foreach ([
        phpBootProbeBinaryReferenceInventory(),
        phpBootProbeAppBootEntryReferenceInventory(),
        phpBootProbeChildEntryReferenceInventory(),
    ] as $inventory) {
        expect($inventory)->not->toBeEmpty();
        foreach (array_keys($inventory) as $path) {
            // `toHaveKey` の第 2 引数は**期待する値**なので、診断文は素の真偽で書く。
            expect(array_key_exists($path, $sources))
                ->toBeTrue("申告したパスが母集団に無い (改名・移動・git add 忘れ): {$path}");
        }
    }
});

test('G-7 走査器の見本検査: 3 軸の判定が見本表どおりである', function (
    string $sample,
    bool $axisA,
    bool $axisB,
    bool $axisC,
): void {
    expect(phpBootProbeReferencesConstant($sample, 'PHP_BINARY'))->toBe($axisA, "軸 A: {$sample}")
        ->and(phpBootProbeReferencesStringNeedle($sample, PHP_BOOT_PROBE_APP_ENTRY_NEEDLE))
        ->toBe($axisB, "軸 B: {$sample}")
        ->and(phpBootProbeReferencesStringNeedle($sample, PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE))
        ->toBe($axisC, "軸 C: {$sample}");
})->with([
    // [検体, 軸 A, 軸 B, 軸 C]
    ['<?php $x = [PHP_BINARY];', true, false, false],
    ['<?php // PHP_BINARY', false, false, false],
    ['<?php $s = "PHP_BINARY";', false, false, false],
    ['<?php use const PHP_BINARY as Runtime; $x = Runtime;', true, false, false],
    // 完全修飾・修飾つきの定数参照も末尾要素で拾う (fail-closed)。
    ['<?php $x = \PHP_BINARY;', true, false, false],
    ['<?php use const Foo\PHP_BINARY as Runtime; $x = Runtime;', true, false, false],
    // 接頭辞つき・打ち消しつき・接尾辞つきは別トークンなので拾わない。
    ['<?php $x = MY_PHP_BINARY;', false, false, false],
    ['<?php $x = NOT_PHP_BINARY;', false, false, false],
    ['<?php $x = PHP_BINARY_PATH;', false, false, false],
    ["<?php require 'bootstrap/app.php';", false, true, false],
    ['<?php // require bootstrap/app.php', false, false, false],
    ["<?php \$p = __DIR__.'/fake-wiring-probe.php';", false, false, true],
    // 文字列を分割して針を避ける形は**射程外**。限界を期待値として固定する。
    ['<?php $a = \'fake-wiring-\'."probe.php";', false, false, false],
    // ★軸 B / C は**素の部分文字列**一致である (軸 A の語彙一致とは判定が違う)。
    //   接頭辞つき・打ち消しつき・接尾辞つきは**いずれも一致する** = 申告が要る側へ倒れる。
    //   見逃す方向ではなく拾いすぎる方向なので (b) の許す側であり、
    //   紛らわしい綴りを足した人には「1 行申告する」という摩擦だけが掛かる。
    ["<?php \$p = 'vendor/bootstrap/app.php';", false, true, false],
    ["<?php \$p = 'not-bootstrap/app.php';", false, true, false],
    ["<?php \$p = 'bootstrap/app.php.bak';", false, true, false],
    ["<?php \$p = 'old-fake-wiring-probe.php';", false, false, true],
    ["<?php \$p = 'fake-wiring-probe.php.disabled';", false, false, true],
    // 針の一部だけでは一致しない (部分文字列一致の下界も固定する)。
    ["<?php \$p = 'bootstrap/app.phpx';", false, true, false],
    ["<?php \$p = 'bootstrap/application.php';", false, false, false],
    ["<?php \$p = 'fake-wiring-probe.txt';", false, false, false],
]);

test('G-7 走査器の見本検査: 共通の起動器への静的呼び出しを完全修飾名で判定する', function (
    string $sample,
    bool $expected,
): void {
    expect(phpBootProbeCallsBootProbeRunner('tests/Sample.php', $sample))->toBe($expected, $sample);
})->with([
    // --- 正例: 完全修飾名が起動器に解決される 3 形 ---
    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::run([]);', true],
    ['<?php Tests\Support\Process\BootProbeRunner::run([]);', true],
    ['<?php \Tests\Support\Process\BootProbeRunner::run([]);', true],
    // ★別名つき取り込みも**解決するので検出する** (短名一致では黙っていた形)。
    ['<?php use Tests\Support\Process\BootProbeRunner as Runner; Runner::run([]);', true],
    // --- 負例: 同名の別クラス (短名一致なら誤検出していた形) ---
    ['<?php use Other\BootProbeRunner; BootProbeRunner::run([]);', false],
    ['<?php Other\BootProbeRunner::run([]);', false],
    // 取り込みが無い短名は「現在の名前空間の下」に解決されるので起動器ではない。
    ['<?php BootProbeRunner::run([]);', false],
    // --- 負例: 接頭辞つき・接尾辞つきのクラス名 / 接尾辞つきのメソッド名 ---
    ['<?php use Tests\Support\Process\OtherBootProbeRunner; OtherBootProbeRunner::run([]);', false],
    ['<?php use Tests\Support\Process\BootProbeRunnerX; BootProbeRunnerX::run([]);', false],
    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::runner([]);', false],
    // --- 負例: 呼び出しではない形 ---
    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::RUN;', false],
    ['<?php use Tests\Support\Process\BootProbeRunner;', false],
    ['<?php // BootProbeRunner::run(', false],
    ['<?php $s = "BootProbeRunner::run(";', false],
    // --- 負例: 受け手が静的に確定できない形は**証拠に数えない** (存在を主張する検査のため) ---
    ['<?php $runner = Tests\Support\Process\BootProbeRunner::class; $runner::run([]);', false],
]);
