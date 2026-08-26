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
| 加えて「子入口 (`child_entry`) は**環境ファイルの退避を字句として持ち**、裏取りの名指しは
| **実在するパス**である」(G-9)。
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
|  5. **環境ファイルの退避が実際に効く位置に在ること** (G-9 は字句の在否だけを見る。
|     位置の正しさは各経路の実挙動の検査が担う)
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
        'tests/Architecture/DeployPipelineWiringTest.php' => [
            'launches_app' => false,
            'subject' => 'Deployer の CLI (vendor/bin/dep) と `php -l` を子プロセスで起こして '
                .'デプロイ配線を測る。アプリは起こさない (使うのは tree / deploy --plan / '
                .'deploy:confirm-stage というローカル完結の read-only サブコマンドだけで、SSH もしない)',
            'recovery' => 'Laravel の Process facade の制限時間 (dep は 120 秒 / php -l は 60 秒。'
                .'超過すれば例外になる)',
            'reason' => 'テスト実行に使っている PHP と**同じ実行体**で dep を起こす必要がある '
                .'(shebang の `env php` が別版を拾うと配線の判定が環境依存になる)。'
                .'アプリの起動順序を測る経路ではないので共通の起動器 (BootProbeRunner) には載せない '
                .'— 載せると Laravel 固有の基底環境と書き出し先の予約という無関係な前提が付く '
                .'(PhpLintOracle / StrictTypesRuntimeProbe と同じ理由)',
        ],
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
            'reason' => 'テンプレートから取り込んだ共有ファイルである (T249 のローカル修正 1 件を除いて '
                .'バイト一致。修正の理由は当該 docblock)。起動器を通してしか子を起こさない',
        ],
        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php' => [
            'launches_app' => true,
            'subject' => '実プロセス 2 本を合図で同期させる並行テストの子を起こす (子はアプリを起動する)',
            'recovery' => '同 harness の runner (単一の絶対 deadline + 段階的強制終了。Symfony 側の制限時間は無効化)',
            'reason' => '別 feature (lctl: process-concurrency-test-harness) の正典 v1 が持つ回収規約に属する。'
                .'本 feature (subprocess-boot-probe-harness) の boundary は「子を 2 本立てて合図で同期させる '
                .'並行テスト」を明示的に除いているので、共通の起動器へは載せない',
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
 * `env_isolation` は**子入口だけが持つ**欄で、「リポジトリの `.env` を読まないことを
 * **何が守っているか**」を 3 値で分類する:
 *
 *  - `behavioural` : **実挙動の検査が在る** (子が読んだ環境ファイルの場所を実測して固定している)
 *  - `structural`  : **退避の呼び出しが在ることを字句で pin しているだけ** (G-9)。
 *    実挙動の裏取りは無いので、**この経路について「実際に読まない」とは主張しない**
 *  - `none`        : どちらも無い (**申告できる値だが、G-8 が 0 件で pin する**)
 *
 * `env_isolation_proof` は上の分類の根拠 (`behavioural` なら検査の名前)。
 * 子入口でない kind (`in_process` / `inventory`) は `env_isolation` を `null`、
 * 根拠を空文字にする (子が居ないので分類の対象が無い)。
 *
 * 詳細と、この分類で**何を主張しないか**は G-8 の docblock を読むこと。
 *
 * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', env_isolation: 'behavioural'|'structural'|'none'|null, env_isolation_proof: string, reason: non-empty-string}>
 */
function phpBootProbeAppBootEntryReferenceInventory(): array
{
    return [
        'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
            'kind' => 'child_entry',
            // 専用の 0600 環境ファイルへ固定して起動する (リポジトリの .env は読まない)。
            'env_isolation' => 'behavioural',
            'env_isolation_proof' => 'tests/Architecture/ExternalFakeBootProbeTest.php P-17 '
                .'(子が報告した環境ファイルの絶対パスが、起動側が用意した専用ファイルと完全一致する) '
                .'+ 同 P-8 (子で実際に効いた鍵が専用ファイルの使い捨て値と一致し、親の設定値とは一致しない)',
            'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
        ],
        'tests/Support/Concurrency/idempotency-claim-probe.php' => [
            'kind' => 'child_entry',
            // 段 8 で useEnvironmentPath() / loadEnvironmentFrom() を専用の一時 env ファイルへ向ける。
            // ★実挙動の裏取りは無い (この経路について「実際に読まない」とは主張しない)。
            'env_isolation' => 'structural',
            'env_isolation_proof' => '段 8 の $app->useEnvironmentPath() / loadEnvironmentFrom() を '
                .'G-9 が字句で pin するだけである。読んだ環境ファイルの場所を実測する検査は無い。'
                .'足すには子の観測 DTO (Tests\Support\Concurrency\ConcurrentProbeObservation) から '
                .'親までの 4 段を変えることになり、それは別 feature '
                .'(lctl: process-concurrency-test-harness) の契約なので本 TODO では行わない',
            'reason' => '実プロセス並行テストの子入口。別 feature (process-concurrency-test-harness) の持ち物である',
        ],
        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
            'kind' => 'child_entry',
            // ★T249 のローカル修正で、S9 / S10 の検体は起動前に環境ファイルの置き場所を
            //   起動器の一時ディレクトリへ逃がす (取り込み元の姿ではリポジトリの .env を読んでいた)。
            'env_isolation' => 'behavioural',
            'env_isolation_proof' => 'tests/Unit/Support/Process/BootProbeRunnerTest.php S9 '
                .'(子が報告した環境ファイルの絶対パスが <一時ディレクトリ>/.env と完全一致し、'
                .'その場所に環境ファイルが実在しないこと + 環境ファイルからしか来ない設定値 2 つが空であること)',
            'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
        ],
        'tests/TestCase.php' => [
            'kind' => 'in_process',
            // 同一プロセスなので phpunit.xml の <server force> が効く (秘密は無害化済み)。
            'env_isolation' => null,
            'env_isolation_proof' => '',
            'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
        ],
        'tests/Support/Cache/IsolatedApplicationProbe.php' => [
            'kind' => 'in_process',
            'env_isolation' => null,
            'env_isolation_proof' => '',
            'reason' => 'キャッシュ受け皿の結線を測るための第 2 のアプリを同一プロセスで組み立てる。子プロセスではない',
        ],
        'tests/Architecture/CacheGuardWiringGateTest.php' => [
            'kind' => 'inventory',
            'env_isolation' => null,
            'env_isolation_proof' => '',
            'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
        ],
        'tests/Architecture/BughuntExecutedRouteOrderingTest.php' => [
            'kind' => 'inventory',
            'env_isolation' => null,
            'env_isolation_proof' => '',
            'reason' => '記録器の位置を固定する検査が、違反時の直し方を案内する診断文にパス文字列を持つ',
        ],
        'tests/Architecture/InertiaErrorScreenContractTest.php' => [
            'kind' => 'inventory',
            'env_isolation' => null,
            'env_isolation_proof' => '',
            'reason' => '例外応答の最終整形スロットの登録位置を検査する側が、照合する場所としてパス文字列を持つ',
        ],
        'tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php' => [
            'kind' => 'inventory',
            'env_isolation' => null,
            'env_isolation_proof' => '',
            'reason' => '撤去表面の走査対象の定義が、走査根の 1 つとしてパス文字列を持つ',
        ],
        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
            'kind' => 'inventory',
            'env_isolation' => null,
            'env_isolation_proof' => '',
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
 * 正規化済みトークン列が**環境ファイルの退避の呼び出し**
 * `$app` `->` `useEnvironmentPath` `(` を持つか (4 トークンの**完全一致**)。
 *
 * ★受け手を `$app` に固定する。名前だけを見ると `$unrelated->useEnvironmentPath(…)` も
 *   証拠になってしまい、**存在を肯定する検査で拾いすぎる** (AGENTS.md §静的検査の共通規約 (b))。
 *   変数の型は字句では解決できないので、**受け手の綴りまで固定する**のが本 gate で取れる
 *   いちばん強い形である (摩擦は「子入口では `$app` という名前で受ける」だけ)。
 * ★語彙一致は区切り (`->`) で割ったトークンの完全一致で判定するので、
 *   接頭辞つき (`myUseEnvironmentPath`) / 打ち消しつき (`notUseEnvironmentPath`) /
 *   接尾辞つき (`useEnvironmentPathX`) は**別トークン**として落ちる (同規約 (e))。
 *
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 */
function phpBootProbeHasEnvironmentPathCall(array $tokens): bool
{
    $count = count($tokens);
    for ($i = 0; $i + 3 < $count; $i++) {
        if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== '$app') {
            continue;
        }

        if ($tokens[$i + 1]['id'] !== T_OBJECT_OPERATOR) {
            continue;
        }

        if ($tokens[$i + 2]['id'] !== T_STRING || $tokens[$i + 2]['text'] !== 'useEnvironmentPath') {
            continue;
        }

        if ($tokens[$i + 3]['id'] === null && $tokens[$i + 3]['text'] === '(') {
            return true;
        }
    }

    return false;
}

/**
 * ソースが**環境ファイルの退避**を持つか (実コード、または子へ渡す検体ソースの中)。
 *
 * 判定は 2 段で、**どちらもトークンの完全一致**である (素の部分文字列一致は使わない):
 *
 *  1. ソース自身の正規化トークン列に `$app->useEnvironmentPath(` が在る
 *     (`fake-wiring-probe.php` / `idempotency-claim-probe.php` のような実コード)
 *  2. 文字列トークン (ヒアドキュメント・ナウドキュメントの本文を含む) の中身を
 *     **PHP として字句解析し直し**、同じ 4 トークンの並びが在る
 *     (`BootProbeRunnerTest` のように、子へ渡す検体ソースを文字列で持つ形)
 *
 * ★段 2 を素の部分文字列一致で書くと `'useEnvironmentPath is required'` のような
 *   ただの散文や、`'$app->notUseEnvironmentPath(…)'` のような打ち消しつきまで通る。
 *   **文字列の中も字句解析して同じ規則で判定する** (AGENTS.md §静的検査の共通規約 (e))。
 * ★コメント・docblock は `PhpTokenScan::normalize()` が落とすので数えない。
 *
 * **主張しないこと**: 呼び出しが**実際に効く位置** (アプリ起動より前) に在ることは見ない。
 * 位置の正しさは各経路の実挙動の検査が担う (G-8 の `env_isolation` 参照)。
 */
function phpBootProbeMentionsEnvironmentPathRelocation(string $source): bool
{
    $tokens = PhpTokenScan::normalize($source);

    if (phpBootProbeHasEnvironmentPathCall($tokens)) {
        return true;
    }

    foreach ($tokens as $token) {
        if (! in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        $body = $token['text'];
        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && strlen($body) >= 2) {
            // 引用符を落とす (中身だけを字句解析する)。
            $body = substr($body, 1, -1);
        }

        if (phpBootProbeHasEnvironmentPathCall(PhpTokenScan::normalize('<?php '.$body))) {
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

/**
 * G-2: 「アプリを起こす」と申告してよい起こし手の**完全一致 pin**。
 *
 * ★**1 件ではなく 2 件である**。本 feature (subprocess-boot-probe-harness) の boundary は
 *   「子を 2 本立てて合図で同期させる並行テスト」を明示的に**除いて**おり、そちらは別 feature
 *   (lctl: process-concurrency-test-harness) が自分の回収規約 (単一の絶対 deadline) を持つ。
 *   両者を 1 本の起動器へ統合するのは「別物の概念を似ているからで統合する」ことになる
 *   (AGENTS.md 思考原則 4)。
 * ★したがって本検査が固定するのは**申告先の集合そのもの**であり、
 *   「起動経路が 1 本である」ことではない (それは字句走査では裏が取れない。冒頭の
 *   「主張しないこと」1 を参照)。3 本目が現れたら**どちらの feature の規約に属するのか**を
 *   申告に書くことになり、レビューに必ず見える。
 */
test('G-2 軸 A: アプリを起こすと申告する起こし手が完全一致で pin されている', function (): void {
    $launching = array_keys(array_filter(
        phpBootProbeBinaryReferenceInventory(),
        static fn (array $entry): bool => $entry['launches_app'],
    ));
    sort($launching);

    expect($launching)->toBe([
        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php',
        'tests/Support/Process/BootProbeRunner.php',
    ]);
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
 * G-8: 子入口の**環境ファイル隔離の分類**と、**実挙動が未検証の経路の完全一致 pin**。
 *
 * ## 何を守っているか
 *
 * 共通の起動器は `proc_open` へ渡す環境配列で開発者ローカルの env を締め出すが、
 * **`.env` ファイルの読み込みまでは止めない**。子の作業ディレクトリはリポジトリ root なので、
 * 子が `bootstrap/app.php` を**素で**読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
 * これは正典 v1 (2) の「開発者ローカルの環境変数を入力集合から外す」を、
 * 環境変数ではなく**環境ファイル**の経路で迂回してしまう形である。
 *
 * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査 S9 / S10 の検体を取り込み元の姿
 * (環境ファイルの置き場所を移さない形) で走らせると、子の設定に `.env` 由来の
 * **DB のパスワードと実 `CIPHERSWEET_KEY`** が載った。外部サービスの資格情報
 * (Stripe / AWS / Google / SMTP) は本チェックアウトではいずれも空だったが、
 * **「空だった」のはこのチェックアウトの性質であって保証ではない。**
 * この実測を受けて S9 / S10 の検体には**起動前に環境ファイルの置き場所を一時ディレクトリへ
 * 逃がす 1 行**を入れた (取り込み元からの意図的な逸脱。理由は当該 docblock)。
 *
 * ## 何を機械で固定しているか
 *
 *  1. `env_isolation` が `none` の子入口は**ちょうど 0 件**である (完全一致 pin)。
 *     退避も裏取りも無い子入口を足すには申告を書き換えることになり、レビューに必ず見える
 *  2. `child_entry` は `env_isolation` を `behavioural` / `structural` のどちらかで申告し、
 *     **根拠の欄 (`env_isolation_proof`) を必ず持つ** (空では通らない)
 *  3. **`structural` の集合は完全一致で pin する** — 実挙動の裏取りが無い経路が
 *     黙って増えないようにするため。**この集合について「実際に `.env` を読まない」とは
 *     主張しない** (下の「主張しないこと」を参照)
 *  4. `child_entry` 以外 (`in_process` / `inventory`) は定義上この分類の対象でないので、
 *     `env_isolation` が `null` であること・根拠が空であることを両方向で固定する
 *     (取り違えの検出)
 *
 * ## 対比 (なぜ同一プロセスは対象外なのか)
 *
 * 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
 * 効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
 * **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
 * これが子と同一プロセスの非対称の正体である。
 *
 * ## 主張しないこと (誇張しない)
 *
 * **「子はリポジトリの `.env` を読まない」を全経路について主張しない。**
 * 主張できるのは `env_isolation: behavioural` の経路だけで、そちらの根拠は本検査ではなく
 * **名指しされた実挙動の検査そのもの**である:
 *
 *  - `tests/Unit/Support/Process/BootProbeRunnerTest.php` の S9 — 子が報告した環境ファイルの
 *    絶対パスが `<一時ディレクトリ>/.env` と完全一致し、そこに実在しないこと
 *  - `tests/Architecture/ExternalFakeBootProbeTest.php` の P-17 / P-8 — 子が報告した
 *    環境ファイルの絶対パスが起動側の専用ファイルと完全一致し、効いた鍵がその中身と一致すること
 *
 * `env_isolation: structural` の経路 (現在 1 件) については
 * **「実際に読まない」とは主張しない** — 分かっているのは「退避の呼び出しが字句として在る」
 * ことだけである (G-9)。呼び出しが**効く位置**に在るかも、他の値を読んでいないかも見ていない。
 *
 * さらに、本検査が機械で確かめるのは**申告と根拠の記載**であって、
 * 名指しした検査が実際に何を測っているかではない。したがって次は本検査を通る:
 *
 *  1. `env_isolation_proof` に**実在はするが何も測っていない**検査名を書く
 *     (実在しない名前は G-9 が落とす)
 *  2. 既存の `child_entry` の中で、`.env` を読む検体を**増やす** (ファイル単位の申告は変わらない)
 */
test('G-8 退避も裏取りも無い子入口は 0 件で、実挙動の裏取りが無い経路は完全一致で pin されている', function (): void {
    $inventory = phpBootProbeAppBootEntryReferenceInventory();

    $childEntries = [];
    $structuralOnly = [];

    foreach ($inventory as $path => $entry) {
        if ($entry['kind'] !== 'child_entry') {
            // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
            //   定義上この分類の対象ではない。取り違えを防ぐために両方向で固定する。
            expect($entry['env_isolation'])
                ->toBeNull("子が居ない経路に env_isolation が申告されている: {$path}")
                ->and(trim($entry['env_isolation_proof']))
                ->toBe('', "子が居ない経路に根拠の記載がある (kind の取り違え): {$path}");

            continue;
        }

        $childEntries[] = $path;

        // ★分類は 2 値のどちらかで、根拠の記載を必ず持つ (申告だけで済ませない)。
        expect(in_array($entry['env_isolation'], ['behavioural', 'structural'], true))
            ->toBeTrue("child_entry の env_isolation が behavioural / structural の外: {$path}")
            ->and(trim($entry['env_isolation_proof']))
            ->not->toBe('', "child_entry に env_isolation の根拠が無い: {$path}");

        if ($entry['env_isolation'] === 'structural') {
            $structuralOnly[] = $path;
        }
    }

    sort($structuralOnly);

    // ★**実挙動の裏取りが無い子入口**の集合を完全一致で pin する。
    //   増やすには申告を書き換えることになり、「なぜ実挙動で測らないのか」がレビューに必ず見える。
    //   減らす (behavioural へ上げる) ときも同じ。
    expect($structuralOnly)->toBe(
        ['tests/Support/Concurrency/idempotency-claim-probe.php'],
        '実挙動の裏取りを持たない子入口が増減している。'
        .'足すなら G-8 の docblock を読み、なぜ実挙動で測れないのかを根拠の欄に書くこと',
    );

    // ★母集団が空のまま緑になる形を塞ぐ (AGENTS.md §静的検査の共通規約 (b) の 3 点目)。
    expect($childEntries)->not->toBe([], 'child_entry が 1 件も無い (走査か申告が壊れている)');
});

/**
 * G-9: `child_entry` は**環境ファイルの退避の呼び出しを字句として持つ** (G-8 の申告への機械の裏打ち)。
 *
 * G-8 が見るのは申告と根拠の記載までである。そこへ**2 つだけ機械の裏打ち**を足す:
 *
 *  1. `child_entry` の申告ファイルは `$app->useEnvironmentPath(` を**トークンの完全一致**で持つ
 *     (実コード、または子へ渡す検体ソースの文字列の中。判定は
 *     `phpBootProbeMentionsEnvironmentPathRelocation()`)。Laravel が読む環境ファイルは
 *     この呼び出しでしか動かないので、**持たない子入口は既定でリポジトリの `.env` を読む**
 *     = 新しい子入口を素直に足すと赤になる
 *  2. `env_isolation_proof` が**検査を名指ししている場合**、その先頭語は
 *     **実在するパス**である (走査母集団の中に在る)。実在しない検査名で申告を通す形を塞ぐ。
 *     `structural` の根拠は検査名ではなく散文なので、この検査は
 *     **`behavioural` の entry にだけ**適用する
 *
 * **主張しないこと**:
 *
 *  - 呼び出しが**実際に効く位置** (アプリ起動より前) に在ること。字句では決められないので、
 *    位置の正しさは実挙動の検査 (`BootProbeRunnerTest` の S9 /
 *    `ExternalFakeBootProbeTest` の P-17) が担う
 *  - **受け手が本当に Laravel の Application であること**。変数の型は字句では解決できないので、
 *    受け手は**綴り (`$app`) で固定している**。別名で受ける子入口は赤になる (拾いすぎない側)
 *  - 名指しした検査が**実際に何を測っているか** (実在の確認までである)
 *  - 文字列側の判定は「**子へ実際に渡される**検体ソース」に限定できない。
 *    申告ファイルの中の**使われていない文字列**に同じ 4 トークンを置いても通る
 *    (見本表の「単一引用符の中」の正例がまさにその形である)。字句走査で
 *    「その文字列が子へ渡されるか」を追うことはできないので、ここは**保証外**にしてある。
 *    `structural` の経路について実挙動を主張していないのはこのためでもある
 */
test('G-9 child_entry は退避の呼び出しを字句として持ち、behavioural の名指しは実在パスである', function (): void {
    $sources = phpBootProbeTestSources();
    $childEntries = 0;

    foreach (phpBootProbeAppBootEntryReferenceInventory() as $path => $entry) {
        if ($entry['kind'] !== 'child_entry') {
            continue;
        }

        $childEntries++;

        expect($sources)->toHaveKey($path);
        expect(phpBootProbeMentionsEnvironmentPathRelocation($sources[$path]))
            ->toBeTrue(
                "child_entry が環境ファイルの退避 (\$app->useEnvironmentPath( ) を持っていない: {$path}"
            );

        if ($entry['env_isolation'] !== 'behavioural') {
            // `structural` の根拠は検査名ではなく散文なので、実在確認の対象にしない。
            continue;
        }

        // 名指しは「パス + 括弧つきの説明」の形なので、先頭語をパスとして見る。
        $named = strtok(trim($entry['env_isolation_proof']), " \t");
        expect(is_string($named) ? $named : '')->not->toBe('', "env_isolation_proof が空: {$path}");
        expect(array_key_exists((string) $named, $sources))
            ->toBeTrue("env_isolation_proof が実在しない検査を名指ししている: {$path} => {$named}");
    }

    // 母集団が空のまま緑になる形を塞ぐ。
    expect($childEntries)->toBeGreaterThan(0, 'child_entry が 1 件も無い (走査か申告が壊れている)');
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

test('G-7 走査器の見本検査: 環境ファイルの退避の字句判定 (名前・文字列の両方 / 3 形の否定)', function (
    string $sample,
    bool $expected,
): void {
    expect(phpBootProbeMentionsEnvironmentPathRelocation($sample))->toBe($expected, $sample);
})->with([
    // --- 正例: 実コード / 単一引用符の中 / ナウドキュメントの本文 (3 分岐すべて) ---
    ['<?php $app->useEnvironmentPath($dir);', true],
    ["<?php \$code = '\$app->useEnvironmentPath(\$dir);';", true],
    ["<?php \$code = <<<'PHP'
\$app->useEnvironmentPath(\$dir);
PHP;", true],
    // --- 負例: コメントだけ (正規化が落とす) ---
    ['<?php // useEnvironmentPath', false],
    ['<?php /** useEnvironmentPath */ $x = 1;', false],
    // --- 負例: 接頭辞つき・打ち消しつき・接尾辞つきの**名前** (実コード側) ---
    ['<?php $app->myUseEnvironmentPath($dir);', false],
    ['<?php $app->notUseEnvironmentPath($dir);', false],
    ['<?php $app->useEnvironmentPathX($dir);', false],
    // --- 負例: 同じ 3 形を**文字列の中**でも落とす (段 2 が部分文字列一致でないことの裏取り) ---
    ["<?php \$code = '\$app->myUseEnvironmentPath(\$dir);';", false],
    ["<?php \$code = '\$app->notUseEnvironmentPath(\$dir);';", false],
    ["<?php \$code = '\$app->useEnvironmentPathX(\$dir);';", false],
    // --- 負例: 文字列の中の散文・呼び出しでない形 ---
    ["<?php \$msg = 'useEnvironmentPath is required';", false],
    ["<?php \$s = 'useEnvironmentPath';", false],
    // --- 負例: 受け手が \$app でない (存在を肯定する検査なので拾いすぎない) ---
    ['<?php $unrelated->useEnvironmentPath($dir);', false],
    ["<?php \$code = '\$unrelated->useEnvironmentPath(\$dir);';", false],
    // --- 負例: 呼び出しでない形 (`(` が続かない) ---
    ['<?php $app->useEnvironmentPath;', false],
    // --- 負例: 退避を持たない子入口 (これが G-9 で赤になる形) ---
    ["<?php \$app = require 'bootstrap/app.php'; \$app->make(Kernel::class)->bootstrap();", false],
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
