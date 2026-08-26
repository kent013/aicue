<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
 * deploy 配線の band gate (T029 / deployer-pipeline)。
 *
 * `deploy/` は phpstan.neon の paths に含まれない (Deployer の `get()` が mixed を返すため
 * level 10 の負担が過大)。その代替として **3 層**で配線を機械保証する:
 *   1. `php -l` (構文)                                    ... W4
 *   2. `dep tree` / `dep deploy --plan` の完走             ... W5 / W12 / W13
 *   3. band inventory (task の配線位置の台帳。deny-by-default) ... W6 / W7
 *
 * 「band」= 「この task はパイプラインのどの区間に居なければならないか」の宣言である。
 * 区間を間違えると **実 deploy でしか壊れない** (例: verify を migrate の後に置くと
 * 「DB は進んだのに current は旧コード」という mixed state を作る)。実 deploy を受入基準に
 * 含めない設計なので、この gate が唯一の網になる。
 *
 * 実 deploy / 実 ssh は一切しない。使うのは Deployer のローカル完結サブコマンド
 * (`tree` / `deploy --plan` / `deploy:confirm-stage`) だけである
 * (`deploy:confirm-stage` の task body に `run()` が無いためリモート接続前に判定が終わる)。
 *
 * **ヘルパ名について**: Pest のテストファイルは namespace を持たないため、ここで定義する関数は
 * すべて **global 名前空間**に入る。同じプロセスに読み込まれる別の gate ファイル
 * (DeployCoordinateHygieneTest / DeploySkillContractTest) と衝突すると
 * `Cannot redeclare function` で **suite の収集自体が死ぬ** (実測)。汎用的な名前には
 * `deployPipeline` 接頭辞を付けて衝突を構造的に避けている。
 *
 * ─────────────────────────────────────────────────────────────────────
 * **家系正典 (laravel-claude-template の deployer-pipeline v1) からの差**
 *
 * 差は「aicue の実態に合わせた読み替え」に限り、検査の向き (deny-by-default) と
 * 強度は落としていない。1 件ずつ理由を該当箇所に書いてある:
 *   (1) Deployer の floor pin が 7.5 系 → **8.0 系** (aicue の composer.json / lock の実態)
 *   (2) PRE_MIGRATE_VERIFY の anchor が `artisan:config:cache` → **`artisan:optimize`**
 *       (Deployer 8 の laravel recipe は起動キャッシュ生成を optimize 一括で行う)
 *   (3) `supervisor_enabled` → **`queue_worker_restart_enabled`** (worker は systemd 常駐)
 *   (4) frontend の needle が corepack 経由の呼び出し形になっている
 *   (5) 正典の 2 task (submodules / cli-oauth) を採らない。**目録から消すのではなく
 *       {@see DEPLOY_TASK_OMITTED} へ「不在の申告」として登録し、W34 が不在を機械固定**する
 *   (6) runbook のパスが `docs/deployment-runbook.md`
 *   (7) W24 の ci.yml 側は「版を宣言していないこと」も適合として扱う
 *       (aicue の CI は packageManager を唯一の SoT にしている)
 *   (8) `deploy/**` の PHP に `declare(strict_types=1)` がある
 *       (`StrictTypesDeclarationGateTest` が追跡下 PHP 全数に要求し免除簿を持たない)
 * ─────────────────────────────────────────────────────────────────────
 *
 * ─────────────────────────────────────────────────────────────────────
 * **W22 / W33 が保証する範囲と、しない範囲** (過信しないための明示)
 *
 * 保証する: `scripts/deploy.sh` に対する **意図しないドリフトと未レビューの追加**。
 *   - `-f` の付け忘れ / 起動口の増加 / 起動を run_dep の外へ移すこと
 *   - 台帳に無いコマンドの追加 (構文を問わず)
 *   - 間接実行 (変数コマンド語 / `eval` / `sh -c` / `source` / backtick / プロセス置換 /
 *     `git -c alias.X=!cmd` のような allow 済みコマンドの実行面)
 *
 * 保証しない: **維持者が意図的に難読化して実行を隠すこと**。静的解析は shell の意味論を
 *   完全には再現できず、そして何より **同じリポジトリの維持者はこの gate 自身を編集できる**。
 *   したがってこの層は「レビューを強制する仕組み」であって sandbox ではない。
 *   本番デプロイの最終的な統制は権限管理 (誰が deploy できるか / 誰が main に push できるか) で行う
 *   — この境界は docs/production-deployment-runbook.md 第 10.2 節にも書いてある。
 * ─────────────────────────────────────────────────────────────────────
 */

/** Deployer 設定ファイル (B 形の `-f` に渡す正典パス)。 */
const DEPLOY_CONFIG_FILE = 'deploy/deploy.php';

/** host 座標の example (gitignore される hosts.yml の雛形。Deployer に parse させて機械検証する)。 */
const DEPLOY_HOSTS_EXAMPLE = 'deploy/hosts.example.yml';

/** 実運用の単一入口。 */
const DEPLOY_WRAPPER = 'scripts/deploy.sh';

/** composer.json に要求する Deployer の制約 (二層 floor pin の上段)。 */
const DEPLOY_DEPLOYER_CONSTRAINT = '^8.0';

/** composer.lock に要求する Deployer 実版の prefix (二層 floor pin の下段)。 */
const DEPLOY_DEPLOYER_LOCK_PREFIX = 'v8.0.';

/**
 * `deploy/tasks/*.php` が宣言する task の band 分類。
 *
 * **band 表そのものが順序不変条件の一覧である。** `deploy/tasks/` に task を足したら
 * 必ずここに登録する (未登録は W6 が fail させる。allowlist / skip の口は無い)。
 * 逆に、ここに載っているのに `deploy/**` のどこでも宣言されていない entry も fail する
 * (腐った台帳行を残さない)。
 */
const DEPLOY_TASK_BANDS = [
    'deploy:confirm-stage' => 'PRE_DEPLOY',
    'deploy:check_env' => 'PRE_SHARED',
    'build:frontend' => 'POST_VENDORS_PRE_SYMLINK',
    'deploy:verify' => 'PRE_MIGRATE_VERIFY',
    'deploy:restart' => 'POST_DEPLOY_AND_ROLLBACK',
];

/**
 * 家系正典が持つが **aicue が意図して採らない** task の台帳 (= 不在の申告)。
 *
 * band 台帳から行を消すだけにすると「そもそも検討していない」と区別が付かず、
 * 誰かが後から `deploy/tasks/` へ持ち込んでも band 未登録の一般 fail しか出ない。
 * ここに登録しておくと (a) 採らない理由がレビュー可能な形で残り、
 * (b) W34 が **その task 名が deploy/** に現れないこと**を機械固定し、
 * (c) 採りたくなった時点で「この行を消して band を決める」という手順が明示される。
 *
 * @var array<string, string> task 名 => 採らない理由 (前提が満たされていないこと)
 */
const DEPLOY_TASK_OMITTED = [
    'deploy:submodules' => 'aicue に git submodule が無い (.gitmodules 不在)。'.
        'Deployer の update_code が submodule を fetch しない穴を埋める task なので、'.
        'submodule を 1 つでも導入する時点で正典から移植して band PRE_VENDORS に登録する',
    'artisan:cli-oauth:ensure' => 'CLI OAuth client の冪等発行を deploy で保証する task だが、'.
        '正典が要求する有効化の前提 3 点を aicue は 1 つも満たさない。'.
        '(1) 未達 = oauth_clients に client_kind の部分 unique index が無い '.
        '(2026_07_02_010000_create_oauth_tables.php は client_kind を nullable string で置くだけ)。'.
        '(2) 未達 = 発行コマンド (cli:client) は「ちょうど 1 件」の厳密判定を持たず、'.
        '非 revoked client が複数あっても最古を再利用して成功する (fail しない)。'.
        '(3) 未達 = 復旧手段 (cli-oauth:status / cli-oauth:rotate 相当) が無い。'.
        'この band は「DB 先行 + 未 publish」の mixed state を意図的に許容する band なので、'.
        '止まったときの復旧手段が先に無い状態で配線してはならない。'.
        'CLI OAuth client は現状 `php artisan cli:client` を人手で 1 回叩いて発行する',
];

/**
 * band ごとの順序条件 (`dep tree deploy` 上の位置制約)。
 *
 * `after` = その anchor より **後**に現れること / `before` = **前**に現れること。
 * `first` = tree の先頭 (index 0) であること。
 * `in_rollback` = `dep tree rollback` にも現れること。
 * `optional` = **その band の task が tree に現れなくてもよい** (deploy.php が既定で require しない
 * opt-in band のみ)。これが無い band の task は **不在それ自体が違反**である
 * (require 行を外して順序検査ごと消す抜け道を封じる)。
 * anchor が tree に現れない場合もその条件を検査できないので fail させる (空振り防止)。
 *
 * @var array<string, array{after?: string, before?: string, first?: bool, in_rollback?: bool, optional?: bool}>
 */
const DEPLOY_BAND_ORDER = [
    'PRE_DEPLOY' => ['first' => true],
    // 正典に無い band。`shared/.env` の置き忘れを deploy:shared より前に止める
    // (deploy:shared は shared 側が空なら release 側の .env を shared へ据えてしまうため。
    // deploy/tasks/check-env.php の冒頭に機序を書いてある)。
    'PRE_SHARED' => ['before' => 'deploy:shared'],
    'POST_VENDORS_PRE_SYMLINK' => ['after' => 'deploy:vendors', 'before' => 'deploy:symlink'],
    // anchor が `artisan:config:cache` ではなく `artisan:optimize` なのは Deployer 8 の
    // recipe/laravel.php が既定 deploy で起動キャッシュ生成を optimize 一括で行うため
    // (config / event / route / view)。区間の意味は正典と同じ
    // = 「起動キャッシュを焼いた後・DB を進める前」。
    'PRE_MIGRATE_VERIFY' => ['after' => 'artisan:optimize', 'before' => 'artisan:migrate'],
    'POST_DEPLOY_AND_ROLLBACK' => ['after' => 'deploy:success', 'in_rollback' => true],
];

/**
 * recipe/laravel.php だけを読み込む基準設定 (自前 task を 1 つも定義しない)。
 *
 * これに対する `dep list --raw` が「recipe が元から持つ task 名の集合」になる。
 * override 台帳の entry が **実在する recipe task を指していること**の照合に使う。
 */
const DEPLOY_RECIPE_BASELINE = 'tests/Architecture/fixtures/deploy-recipe-baseline.php';

/**
 * recipe が既に定義している task を **再定義**しているものの台帳 (band ではない)。
 *
 * band は「新しい task をどの区間に置くか」の宣言だが、recipe task の上書きは
 * 「既存 task の実行対象・引数を変える」別種の操作なので分けて登録する。
 *
 * **この台帳は逃げ道ではない**。W6 は次の 2 つを機械検証する:
 *  1. reason が非空であること
 *  2. **key が実在する recipe task 名であること** (`DEPLOY_RECIPE_BASELINE` の
 *     `dep list --raw` に現れる)。これが無いと「新しい task をここに書けば band と W7 を
 *     まとめて迂回できる」= 検査されない escape hatch になる (Codex 指摘)
 */
const DEPLOY_RECIPE_TASK_OVERRIDES = [
    'artisan:migrate' => 'recipe/laravel.php の artisan:migrate に ->once() + ->select(roles=db) を足し、'.
        '多重 migrate を防ぐための再定義 (区間は recipe のまま)',
];

/** donor 2 本の和集合。web サーバーが書ける必要のあるディレクトリ (欠けると本番で 500)。 */
const DEPLOY_WRITABLE_DIRS = [
    'bootstrap/cache',
    'storage',
    'storage/app',
    'storage/app/public',
    'storage/framework',
    'storage/framework/cache',
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
];

/**
 * `dep <args>` を subprocess 実行する。
 *
 * SSH もホストも要らず 1 秒未満で完走する (使うのは tree / --plan / confirm-stage のみ)。
 * `PHP_BINARY` 経由で起動するのは、テスト実行に使っている PHP と同じ実行体を使うため
 * (shebang の `env php` が別版を拾うと結果が環境依存になる)。
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 * @return array{stdout: string, stderr: string, exitCode: int}
 */
function deployDepRun(array $args, array $env = []): array
{
    $command = array_merge([PHP_BINARY, base_path('vendor/bin/dep'), '--no-ansi'], $args);

    $result = Process::path(base_path())->env($env)->timeout(120)->run($command);

    return [
        'stdout' => $result->output(),
        'stderr' => $result->errorOutput(),
        'exitCode' => $result->exitCode() ?? -1,
    ];
}

/**
 * `-f deploy/deploy.php` を付けた `dep` を実行し、exit 0 を要求して stdout 行配列を返す。
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 * @return list<string>
 */
function deployDepLines(array $args, array $env = []): array
{
    $result = deployDepRun(array_merge(['-f', DEPLOY_CONFIG_FILE], $args), $env);

    expect($result['exitCode'])->toBe(
        0,
        'dep '.implode(' ', $args)." が異常終了した:\n".$result['stdout']."\n".$result['stderr']
    );

    $lines = [];
    foreach (explode("\n", $result['stdout']) as $line) {
        $lines[] = $line;
    }

    return $lines;
}

/**
 * `dep tree <task>` の行配列。
 *
 * **`//` 以降の注釈を落とすのが要点**。Deployer は `deploy:submodules  // before deploy:vendors`
 * のように **他の task 名を含む注釈**を出すため、素の行に task 名を当てると
 * 「submodules の行が deploy:vendors の行として先に見つかる」= 順序検査が空洞化する (実測)。
 *
 * @return list<string>
 */
function deployTreeLines(string $task): array
{
    $lines = deployDepLines(['tree', $task]);
    $stripped = [];

    foreach ($lines as $index => $line) {
        // 先頭の "The task-tree for X:" は本文ではないので落とす (index 0 の意味を保つため)。
        if ($index === 0 && str_starts_with($line, 'The task-tree for ')) {
            continue;
        }

        $position = strpos($line, '//');
        $stripped[] = $position === false ? $line : substr($line, 0, $position);
    }

    return $stripped;
}

/**
 * 罫線表を「行 → セル配列」に分解する (先頭のヘッダ行 = host 名の行は含めない)。
 *
 * @return array{hosts: list<string>, rows: list<list<string>>}
 */
function deployParsePlanTable(string $output): array
{
    $hosts = [];
    $rows = [];

    foreach (explode("\n", $output) as $line) {
        $trimmed = trim($line);
        if (! str_starts_with($trimmed, '│')) {
            continue;
        }

        $cells = [];
        foreach (explode('│', $trimmed) as $cell) {
            $cells[] = trim($cell);
        }

        // 先頭と末尾は罫線の外側 (空文字) なので落とす。
        array_shift($cells);
        array_pop($cells);

        if ($hosts === []) {
            $hosts = array_values($cells);

            continue;
        }

        $rows[] = array_values($cells);
    }

    return ['hosts' => $hosts, 'rows' => $rows];
}

/**
 * `dep deploy --plan all` の表を分解する。
 *
 * 引数に `--plan` と selector `all` を **必ず**含める (実 deploy にならないことの構造的保証。
 * selector を省略すると Deployer が対話プロンプトで停止する = timeout する)。
 * hosts は `deploy/hosts.example.yml` を差し替えて読ませる (テンプレートに hosts.yml は無い)。
 *
 * @return array{hosts: list<string>, rows: list<list<string>>}
 */
function deployPlanTable(): array
{
    $lines = deployDepLines(
        ['deploy', '--plan', 'all'],
        ['DEPLOY_HOSTS_FILE' => DEPLOY_HOSTS_EXAMPLE],
    );

    return deployParsePlanTable(implode("\n", $lines));
}

/**
 * task 名が最初に現れる行番号。境界付き正規表現で誤マッチを防ぐ
 * (`deploy` が `deploy:vendors` に当たらないようにする)。
 *
 * @param  list<string>  $lines
 * @return int 見つからない場合は -1
 */
function deployIndexOf(array $lines, string $task): int
{
    $pattern = '/(?<![\w:.-])'.preg_quote($task, '/').'(?![\w:.-])/';

    foreach ($lines as $index => $line) {
        if (preg_match($pattern, $line) === 1) {
            return $index;
        }
    }

    return -1;
}

/**
 * hygiene gate (DeployCoordinateHygieneTest) の scan root 台帳から `required` を静的に読む。
 *
 * 同ファイルの関数を呼ばずにソースを読むのは、`composer test --parallel` が
 * テストファイル単位でプロセスを分けるため **別ファイルの関数が定義されている保証が無い**
 * ため (function_exists で分岐すると条件付き green = 空洞化する)。
 * 読めない / 判定できない場合は null を返し、呼び出し側が fail させる (deny-by-default)。
 */
function deployHygieneRootRequired(string $source, string $root): ?bool
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

/**
 * リポジトリ相対パスのファイル内容。
 *
 * **不在は例外にする** (空文字を返してはならない)。禁止 needle の検査は空文字に対して必ず pass するため、
 * 検査対象が消えた瞬間に gate が green になる = 偽グリーンになる。
 */
function deployPipelineRead(string $relative): string
{
    $absolute = base_path($relative);
    $contents = is_file($absolute) ? file_get_contents($absolute) : false;

    if (! is_string($contents)) {
        throw new RuntimeException("gate の検査対象が読めません: {$relative}");
    }

    return $contents;
}

/**
 * PHP ソースからコメント (行 / ブロック / docblock) を落とす。
 *
 * **静的 needle 検査の前処理として必須**である。理由は 2 方向:
 *  - 禁止 needle: `deploy/deploy.php` には「`require 'vendor/autoload.php'` は書かない」という
 *    **説明コメント自身**が書かれている。素のテキストに needle を当てると gate が自分の
 *    説明文で赤くなる (実測。同じことが `|| true` / `PROD_HOSTS=(` でも起きた)
 *  - 必須 needle: コメント行が必須 needle を満たしてしまうと、実コードから配線が消えても
 *    green になる (**偽グリーン**)。required 側でも同じ前処理を通す
 *
 * 落としたコメントは改行に置換する (行番号と「行内の別トークン」を壊さないため)。
 */
function deployStripPhpComments(string $source): string
{
    $stripped = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
            $stripped .= "\n";

            continue;
        }

        $stripped .= is_array($token) ? $token[1] : $token;
    }

    return $stripped;
}

/**
 * shell / YAML の **行頭** `#` コメント行を落とす。
 *
 * 行内 `#` は落とさない (URL や `$#` を壊すため)。行頭判定に限定するのは、
 * needle が説明文に現れる形だけを取り除ければ十分だからである。
 */
function deployPipelineStripShellComments(string $source): string
{
    $kept = [];

    foreach (explode("\n", $source) as $line) {
        $kept[] = str_starts_with(ltrim($line), '#') ? '' : $line;
    }

    return implode("\n", $kept);
}

/** コメントを落とした PHP ソース。 */
function deployPipelineReadPhpCode(string $relative): string
{
    return deployStripPhpComments(deployPipelineRead($relative));
}

/*
 * 静的 needle は **literal 一致ではなく正規表現**で書く。
 * `require_once` / 二重引用符 / 余分な空白 / `|| /bin/true` のような **等価表現**で
 * すり抜けられると、禁止事項の gate として意味を失う (Codex 指摘)。
 */

/** `require 'vendor/autoload.php'` とその等価表現。 */
const DEPLOY_RE_AUTOLOAD_REQUIRE = '#\b(?:require|include)(?:_once)?\s*\(?\s*[\'"][^\'"]*vendor/autoload\.php[\'"]#';

/** shell の無言 fail-open (`|| true` / `||true` / `|| /bin/true` / `|| :`)。 */
const DEPLOY_RE_SHELL_FAIL_OPEN = '#\|\|\s*(?::|(?:[\w./-]*/)?true\b)#';

/** `set('composer_options', ...)` とその等価表現。 */
const DEPLOY_RE_COMPOSER_OPTIONS = '/\bset\s*\(\s*[\'"]composer_options[\'"]/';

/** wrapper が `dep` に設定ファイルを渡していること (`-f "${DEPLOY_FILE}"` / `-f deploy/deploy.php`)。 */
const DEPLOY_RE_DEP_CONFIG_FLAG = '#-f\s+(?:"\$\{DEPLOY_FILE\}"|\$\{DEPLOY_FILE\}|\$DEPLOY_FILE|[\'"]?deploy/deploy\.php[\'"]?)#';

/** `set('<key>', <value>)` の空白ゆれを許す正規表現を組む。 */
function deployRePhpSet(string $key, string $value): string
{
    return '/\bset\s*\(\s*[\'"]'.preg_quote($key, '/').'[\'"]\s*,\s*'.preg_quote($value, '/').'\s*\)/';
}

/** `before('<a>', '<b>')` / `after('<a>', '<b>')` の空白・引用符ゆれを許す正規表現を組む。 */
function deployReHook(string $hook, string $anchor, string $task): string
{
    return '/\b'.preg_quote($hook, '/').'\s*\(\s*[\'"]'.preg_quote($anchor, '/')
        .'[\'"]\s*,\s*[\'"]'.preg_quote($task, '/').'[\'"]\s*\)/';
}

/**
 * dep に言及するが起動ではない単純コマンドの **完全一致台帳** (deny-by-default の例外)。
 *
 * 判定は「dep に言及する単純コマンドは原則すべて起動。`-f <設定ファイル>` を要求する」で、
 * 例外として認めるのは **本台帳と完全一致する**単純コマンドだけである
 * (比較は前後 trim + 連続空白の 1 個化のみ)。
 *
 * **なぜ正規表現による形状分類をやめたか** (実装中に 5 回の指摘を受けた経緯):
 * 「代入のみ」「`[[ ]]` 条件式」「`echo`/`die` の引数」といった *形* で例外を書くと、
 * shell の**実行構文を数え上げ続けること**になる。実際に
 * `$( )` → backtick → `<( )` / `>( )` と順に漏れが指摘された。列挙は必ず遅れる。
 *
 * 完全一致にすれば **未知の構文は原理的に例外へ入れない**。
 * 代償は「この 3 行を編集したら台帳も直す」ことだが、dep の起動口に触る変更は
 * レビューされるべきなので、その手間は仕様である。
 *
 * @var array<string, string> 単純コマンドの正規化テキスト => なぜ起動でないと言えるか
 */
const DEPLOY_WRAPPER_DEP_NON_INVOCATION = [
    'DEP_BIN="vendor/bin/dep"' => 'dep 実行体のパス定義 (起動ではない)',
    'if [[ ! -x "${DEP_BIN}" ]]' => 'dep 実行体の存在検査 (起動ではない)',
    'die "${DEP_BIN} がありません。composer install を実行してください (deployer/deployer は require-dev です)"' => 'dep 不在時の案内メッセージ (複数行文字列。scanner が引用符を跨いで 1 コマンドとして読む)',
];

/** `run_dep()` 本体の **正典形** (完全一致で pin する)。 */
const DEPLOY_WRAPPER_RUN_DEP_BODY = '"${DEP_BIN}" -f "${DEPLOY_FILE}" "$@"';

/**
 * **実効コマンド語**として禁止する語 (basename で照合)。
 *
 * dep 起動の検出は「`dep` という語の言及」に依存するので、語を経由しない実行を許すと
 * 検出が空洞化する。この wrapper にこれらを使う正当な理由は無いので **全面禁止**する。
 *
 * 「ラッパを 1 段追う」形は採らない — `sudo -u app "${RUNNER}"` のように
 * **引数を取るオプション**があると汎用に追えず、追い損ねた側に倒れる (実測)。
 * ラッパ自体を禁止すれば追う必要が無くなり、fail-closed になる。
 *
 * @var array<string, string> コマンド語 (basename) => 禁止理由
 */
const DEPLOY_WRAPPER_FORBIDDEN_COMMAND_WORDS = [
    'eval' => '任意文字列を実行する',
    'sh' => 'シェル経由の実行 (-c で任意文字列を実行できる)',
    'bash' => '同上',
    'zsh' => '同上',
    'ksh' => '同上',
    'dash' => '同上',
    'command' => 'コマンド語を間接化する',
    'builtin' => '同上',
    'exec' => '同上',
    'env' => '同上 (env FOO=1 CMD の形でコマンド語を隠せる)',
    'xargs' => '標準入力から任意コマンドを起動する',
    'nohup' => 'コマンド語を間接化する',
    'nice' => '同上',
    'setsid' => '同上',
    'time' => '同上',
    'sudo' => '同上 (加えて権限昇格を伴う)',
    'source' => '外部/生成スクリプトを読み込んで実行する',
    '.' => '同上 (source の POSIX 表記)',
];

/**
 * `scripts/deploy.sh` が実行してよい **実効コマンド語の全数台帳** (allowlist)。
 *
 * ここまでの経緯: W22 は「dep の起動が `-f` を伴い run_dep に 1 箇所だけあること」を
 * 検査するが、その根拠は「`dep` という語の検出」と「間接実行の禁止」だった。
 * この形は **shell の構文を数え上げ続ける**ことになり、実装中に
 * `$( )` → backtick → `<( )` → 変数コマンド語 → ラッパ (`command` / `env` / `X=1`) →
 * `source <( )` と **6 回続けて別の迂回経路**を指摘された。列挙は原理的に遅れる。
 *
 * そこで最後に **向きを反転**させた: wrapper が実行してよいコマンド語を全数登録し、
 * **台帳外の語が実効コマンド語として現れたら fail** にする。
 * これで「どんな構文であれ、新しい実行を足せば必ず落ちる」= 構文の列挙が不要になる。
 *
 * `scripts/deploy.sh` は小さく安定したスクリプトなので、この台帳の維持コストは小さい。
 * コマンドを 1 つ足すたびにレビューが要るのは **仕様**である (デプロイの単一入口なので)。
 *
 * @var array<string, string> 実効コマンド語 => 何のために使うか
 */
const DEPLOY_WRAPPER_ALLOWED_COMMAND_WORDS = [
    // shell の制御構造・キーワード
    'if' => '制御構造', 'then' => '制御構造', 'else' => '制御構造', 'fi' => '制御構造',
    'for' => '制御構造', 'do' => '制御構造', 'done' => '制御構造',
    'case' => '制御構造', 'esac' => '制御構造',
    '{' => 'コマンドグループ', '}' => 'コマンドグループ',
    '[[' => '条件式',
    'set' => 'set -euo pipefail (シェルオプション)',
    'exit' => '終了コードの明示',
    'true' => '`|| true` による no-match 許容 (grep の exit 1 を吸う)',
    '+' => '算術式 $(( A + B )) の分割断片 (コマンドではない)',
    // case のパターン (コマンド語ではないが分割の都合で先頭語になる)
    '*)' => 'case パターン', '-*)' => 'case パターン',
    '--check)' => 'case パターン', '--allow-dirty)' => 'case パターン',
    '--production)' => 'case パターン',
    // 自前の関数 (定義行と呼び出しの両方が現れる)
    'usage' => '自前関数: usage 表示', 'usage()' => '同関数の定義',
    'die' => '自前関数: エラー終了', 'die()' => '同関数の定義',
    'run_dep' => '自前関数: dep の唯一の起動口', 'run_dep()' => '同関数の定義',
    'strip_comments' => '自前関数: hosts.yml のコメント行除去',
    'strip_comments()' => '同関数の定義',
    '"${DEP_BIN}"' => 'run_dep 本体の正典形 (dep 実行体。W22 が本体を完全一致で pin する)',
    // 外部コマンド
    'cd' => 'リポジトリルートへ移動',
    'cat' => 'usage の heredoc 出力',
    'echo' => '進捗・警告の出力',
    'printf' => '算術チャレンジの出力と Deployer 出力の転記',
    'read' => '算術チャレンジの入力',
    'grep' => 'placeholder / 座標の検査',
    'git' => 'working tree・ブランチ・origin 同期の検査と push',
    // `$( )` の内側で実行されるもの (W33 は置換の内部も再帰的に検査する)
    'pwd' => 'REPO_ROOT の算出 ($( ) の内側)',
    'dirname' => 'スクリプト位置の算出 ($( ) の内側)',
];

/**
 * allowlist に載せた語のうち、**オプションで任意コマンドを実行できる**ものの禁止オプション台帳。
 *
 * allowlist は「コマンド語」単位なので、語だけ許してオプションを見ないと
 * `git -c alias.p='!vendor/bin/dep …' p` のように **allow 済みコマンドが内包する実行機構**が
 * 残る (Codex 指摘)。語を許す代わりに、その語が持つ実行面を閉じる。
 *
 * @var array<string, array<string, string>> コマンド語 => (禁止する語 / 語の接頭辞 => 理由)
 */
const DEPLOY_WRAPPER_FORBIDDEN_OPTIONS = [
    // `git` は形そのものを pin するので (DEPLOY_WRAPPER_ALLOWED_GIT_FORMS) ここには載せない。
    // 将来 allowlist に「オプションで任意実行できる語」を足すときはここに登録する。
];

/**
 * wrapper が実行してよい **`git` 呼び出し形の全数台帳** (完全一致。末尾のリダイレクトは除去して照合)。
 *
 * 「`git` という語を許してオプションだけ deny」方式では、実行チャネルが次々に出てくる:
 * `git -c alias.X='!cmd'` (argv) → `GIT_CONFIG_*` (env) →
 * `printf '[alias]…' >> .git/config` + `git p` (repo の設定状態) → hook … (Codex 指摘)。
 * `git` はそもそも「任意コマンドを実行するための設定面」を多数持つので、
 * **使う形を全数登録する**方が閉じる。wrapper が使う git は 6 形だけである。
 *
 * @var array<string, string> 正規化した git 呼び出し形 => 用途
 */
const DEPLOY_WRAPPER_ALLOWED_GIT_FORMS = [
    'git status --porcelain' => 'working tree が clean かの判定',
    'git status --short' => 'dirty な変更の一覧表示',
    'git rev-parse --abbrev-ref HEAD' => '現在ブランチ名の取得',
    'git fetch origin "${BRANCH}" --quiet' => 'origin の最新化 (同期判定の前提)',
    'git merge-base --is-ancestor "origin/${BRANCH}" HEAD' => 'origin がローカルより先行していないかの判定',
    'git push origin "${BRANCH}"' => 'Deployer が clone する前提としての push',
];

/**
 * wrapper 全域で **構文ごと禁止**する実行構文 (needle => 禁止理由)。
 *
 * コマンド語の判定では捕まらない「コマンド語そのものを生成する」経路を落とす。
 * `$( )` は wrapper が正当に使う (`REPO_ROOT="$(cd …)"`) ので禁止できないが、
 * **コマンド語の位置で使えば「変数展開を含む」判定で捕まる**ので閉じている。
 *
 * @var array<string, string>
 */
const DEPLOY_WRAPPER_FORBIDDEN_SYNTAX = [
    '`' => 'backtick コマンド置換 (コマンド語を組み立てて実行できる)',
    '<(' => 'プロセス置換 (生成したスクリプトを source / 読み込みで実行できる)',
    '>(' => '同上',
    '.git/' => 'git の設定 (config の alias) や hook を書き換えて任意コマンドを実行させられる',
];

/**
 * shell の **行内**コメント (`#` 以降) を落とす。
 *
 * これが無いと W22 の `-f` 検査が **コメントで満たされる**:
 * `dep deploy prd  # use -f deploy/deploy.php` が pass してしまう (Codex 指摘)。
 * required needle をコメントで満たせてはならない、というのは PHP 側と同じ原則である。
 *
 * 引用符の内側の `#` は落とさない (`grep -vE '^[[:space:]]*#'` のような正当なコードを壊すため)。
 * コメント開始と認めるのは **行頭 / 空白直後** の `#` だけである (`${x#y}` 等を守る)。
 */
function deployPipelineStripShellInlineComment(string $line): string
{
    $inSingle = false;
    $inDouble = false;
    $length = strlen($line);

    for ($index = 0; $index < $length; $index++) {
        $char = $line[$index];

        if ($char === "'" && ! $inDouble) {
            $inSingle = ! $inSingle;

            continue;
        }

        if ($char === '"' && ! $inSingle) {
            $inDouble = ! $inDouble;

            continue;
        }

        if ($char === '#' && ! $inSingle && ! $inDouble
            && ($index === 0 || preg_match('/\s/', $line[$index - 1]) === 1)) {
            return substr($line, 0, $index);
        }
    }

    return $line;
}

/**
 * shell ソース全体を「行番号つき単純コマンド」に分解する単一パス scanner。
 *
 * **行単位ではなくソース全体を走る**のが要点。理由は 2 つある:
 *  - 行単位で「起動か否か」を判定すると `if [[ -n "$x" ]] && dep deploy prd; then` のような
 *    混在行が丸ごと 1 分類になる (Codex 指摘)
 *  - `die "…\n  cp …\n  \$EDITOR …"` のような **複数行文字列**は行単位で見ると
 *    2 行目以降が独立したコマンドに見える (実測。`\$EDITOR …` が「変数をコマンドとして実行」と
 *    誤検出された)。引用符の状態を行を跨いで持たないと正しく読めない
 *
 * コメント除去も同じ scanner で行う (文字列内の `#` を落とさないため)。
 * 分割は `;` `&&` `||` `|` `&` と **改行** (いずれも引用符外・`$( )` 外)。
 * リダイレクトの `&` (`2>&1` / `>&2`) は区切りにしない。
 *
 * @return list<array{line: int, segment: string}> segment は正規化済み (空は除外)
 */
/**
 * **quote 付き** heredoc (`<<'WORD'` / `<<"WORD"`) の本体を空行に置き換える (行数は保つ)。
 *
 * quote 付き heredoc の本体は完全に inert なデータなので、コマンドとして読むと
 * `usage: bash …` のような説明文がノイズになる。
 *
 * **quote 無し heredoc (`<<WORD`) の本体は落とさない**。bash は本文中の `$( … )` を
 * **実行する**ので、落とすと
 * `cat <<EOF` + `$($(printf …) -f … deploy)` + `EOF` の形で実行を隠せる (Codex 指摘)。
 * 本文を残すと散文が「コマンド」として台帳検査に掛かり fail するが、それは
 * **fail-closed 側の誤検知**であり「quote 付きで書く」ことを促す方向なので受け入れる
 * (本 wrapper は `<<'USAGE'` の quote 付きのみを使う)。
 */
function deployStripHeredocBodies(string $source): string
{
    $kept = [];
    $terminator = null;

    foreach (explode("\n", $source) as $line) {
        if ($terminator !== null) {
            $kept[] = '';
            if (trim($line) === $terminator) {
                $terminator = null;
            }

            continue;
        }

        $kept[] = $line;

        // quote 付きのみ (`<<'WORD'` / `<<"WORD"`)。quote 無しは inert ではないので追跡しない。
        if (preg_match('/<<-?\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\s*$/', $line, $matches) === 1) {
            $terminator = $matches[2];
        }
    }

    return implode("\n", $kept);
}

/**
 * 単純コマンドの中の **コマンド置換 `$( … )` の中身**を取り出す (トップレベルのみ。ネストは再帰で拾う)。
 *
 * `$( )` は wrapper が正当に使うため禁止できないが、**その内側でもコマンドが実行される**。
 * 内側を検査対象にしないと
 * `echo "$($(printf '%s%s' 'vendor/bin/' 'dep') -f … deploy)"` のように
 * 外側だけ許可語 (`echo`) にして実行を隠せる (Codex 指摘)。
 *
 * 算術展開 `$(( … ))` はコマンドではないので取り出さない。
 * 単一引用符の内側は展開されないので対象外。
 *
 * @return list<string>
 */
function deployExtractCommandSubstitutions(string $segment): array
{
    $found = [];
    $length = strlen($segment);
    $inSingle = false;
    $inDouble = false;

    for ($index = 0; $index < $length; $index++) {
        $char = $segment[$index];

        if ($char === '\\' && ! $inSingle) {
            $index++;

            continue;
        }

        if ($char === "'" && ! $inDouble) {
            $inSingle = ! $inSingle;

            continue;
        }

        if ($char === '"' && ! $inSingle) {
            $inDouble = ! $inDouble;

            continue;
        }

        if ($inSingle || $char !== '$' || ($segment[$index + 1] ?? '') !== '(') {
            continue;
        }

        // `$((` は算術展開。コマンドではないので中身を取り出さず読み飛ばす。
        $isArithmetic = ($segment[$index + 2] ?? '') === '(';
        $depth = $isArithmetic ? 2 : 1;
        $start = $index + ($isArithmetic ? 3 : 2);

        $cursor = $start;
        $innerSingle = false;
        $innerDouble = false;
        for (; $cursor < $length; $cursor++) {
            $inner = $segment[$cursor];

            if ($inner === '\\' && ! $innerSingle) {
                $cursor++;

                continue;
            }

            if ($inner === "'" && ! $innerDouble) {
                $innerSingle = ! $innerSingle;

                continue;
            }

            if ($inner === '"' && ! $innerSingle) {
                $innerDouble = ! $innerDouble;

                continue;
            }

            if ($innerSingle || $innerDouble) {
                continue;
            }

            if ($inner === '(') {
                $depth++;

                continue;
            }

            if ($inner === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        if ($depth === 0) {
            $inner = substr($segment, $start, $cursor - $start);

            if ($isArithmetic) {
                // 算術展開そのものはコマンドではないが、**その内部で `$( )` は実行される**
                // (`$(( $(cmd) + 1 ))`)。丸ごと skip すると実行を隠せる (Codex 指摘)。
                foreach (deployExtractCommandSubstitutions($inner) as $nested) {
                    $found[] = $nested;
                }
            } else {
                $found[] = $inner;
            }

            $index = $cursor;
        }
    }

    return $found;
}

/**
 * ソースを単純コマンドに分解し、**コマンド置換の内側も再帰的に**含めて返す。
 *
 * @return list<array{line: int, segment: string}>
 */
function deployShellCommands(string $rawSource): array
{
    $all = [];

    foreach (deployShellCommandsFlat($rawSource) as $entry) {
        $all[] = $entry;

        foreach (deployExtractCommandSubstitutions($entry['segment']) as $inner) {
            foreach (deployShellCommands($inner) as $nested) {
                // 行番号は外側の単純コマンドのものを引き継ぐ。
                $all[] = ['line' => $entry['line'], 'segment' => $nested['segment']];
            }
        }
    }

    return $all;
}

/**
 * ソースを単純コマンドに分解する (コマンド置換の内側は展開しない平坦版)。
 *
 * @return list<array{line: int, segment: string}>
 */
function deployShellCommandsFlat(string $rawSource): array
{
    $source = deployStripHeredocBodies($rawSource);
    $segments = [];
    $current = '';
    $line = 1;
    $startLine = 1;
    $inSingle = false;
    $inDouble = false;
    $depth = 0;
    $length = strlen($source);

    for ($index = 0; $index < $length; $index++) {
        $char = $source[$index];

        // バックスラッシュ escape: 次の 1 文字は状態を変えない (`\$` / `\"` 等)。
        if ($char === '\\' && ! $inSingle && $index + 1 < $length) {
            $next = $source[$index + 1];
            if ($next === "\n") {
                // 行継続: 論理行は続く。
                $line++;
                $index++;

                continue;
            }

            $current .= $char.$next;
            $index++;

            continue;
        }

        if ($char === "'" && ! $inDouble) {
            $inSingle = ! $inSingle;
            $current .= $char;

            continue;
        }

        if ($char === '"' && ! $inSingle) {
            $inDouble = ! $inDouble;
            $current .= $char;

            continue;
        }

        if ($char === "\n") {
            $line++;

            if ($inSingle || $inDouble) {
                // 文字列内の改行は内容 (コマンド境界ではない)。
                $current .= ' ';

                continue;
            }

            $normalized = deployNormalizeSegment($current);
            if ($normalized !== '') {
                $segments[] = ['line' => $startLine, 'segment' => $normalized];
            }
            $current = '';
            $startLine = $line;

            continue;
        }

        if (! $inSingle && ! $inDouble) {
            // 行頭 / 空白直後の `#` から行末まではコメント。
            if ($char === '#' && ($current === '' || preg_match('/\s$/', $current) === 1)) {
                while ($index + 1 < $length && $source[$index + 1] !== "\n") {
                    $index++;
                }

                continue;
            }

            if ($char === '$' && ($source[$index + 1] ?? '') === '(') {
                $depth++;
                $current .= '$(';
                $index++;

                continue;
            }

            if ($char === ')' && $depth > 0) {
                $depth--;
                $current .= $char;

                continue;
            }

            $isRedirection = $char === '&'
                && in_array(substr($current, -1), ['>', '<'], true);

            if ($depth === 0 && ! $isRedirection
                && ($char === ';' || $char === '|' || $char === '&')) {
                $normalized = deployNormalizeSegment($current);
                if ($normalized !== '') {
                    $segments[] = ['line' => $startLine, 'segment' => $normalized];
                }
                $current = '';
                $startLine = $line;

                // `&&` / `||` の 2 文字目を読み飛ばす。
                if (($source[$index + 1] ?? '') === $char) {
                    $index++;
                }

                continue;
            }
        }

        if ($current === '' && preg_match('/\S/', $char) === 1) {
            $startLine = $line;
        }

        $current .= $char;
    }

    $normalized = deployNormalizeSegment($current);
    if ($normalized !== '') {
        $segments[] = ['line' => $startLine, 'segment' => $normalized];
    }

    return $segments;
}

/**
 * shell の **字句的な引用/エスケープを取り除いた**形を返す。
 *
 * shell では次はすべて同じ文字列になる:
 *   `.git/config` / `'.g''it/config'` / `.g\it/config` / `.g$'it/config'` /
 *   `vendor/bin/dep` / `vendor/bin/'de'p` / `vendor/bin/de\p`
 * raw ソースに needle を当てると **この字句だけでどの検査も回避できる** (Codex 指摘)。
 * パターン照合の前に必ず通す。
 *
 * 落とすのは (a) `$'` / `$"` の `$` (ANSI-C / locale 引用の導入子)、(b) 引用符 `'` `"`、
 * (c) バックスラッシュ。`"${VAR}"` は `${VAR}` になるので変数展開の判定は影響を受けない。
 */
function deployResolveShellConcatenation(string $segment): string
{
    // `$'…'` / `$"…"` の導入子 `$` を先に落とす (引用符を落とした後だと区別できない)。
    $resolved = (string) preg_replace('/\$(?=[\'"])/', '', $segment);

    return str_replace(["'", '"', '\\'], '', $resolved);
}

/**
 * 単純コマンドが dep の実行体に言及しているか。
 *
 * 終端は `(?![\w./-])` で判定する。「空白 / 引用符 / 行末」に限定すると
 * `dep>/tmp/x deploy` のように **リダイレクトが実行体に直接くっついた**正当な起動を
 * 取りこぼす (Codex 指摘)。逆に `deployer` / `deploy.php` のような語の一部は
 * 直後が単語構成文字なので除外される。
 *
 * 照合は隣接文字列連結を解いた形に対して行う (`vendor/bin/'de'p` を取りこぼさないため)。
 */
function deployWrapperSegmentMentionsDep(string $rawSegment): bool
{
    $segment = deployResolveShellConcatenation($rawSegment);

    $patterns = [
        // 終端 lookahead に `/` を含むため、区切り文字は `#` を使う (`/` 区切りだと早期終端する)。
        '/\$\{?DEP_BIN\}?/',                        // ${DEP_BIN} / $DEP_BIN
        '#\.?/?vendor/bin/dep(?![\w./-])#',         // vendor/bin/dep / ./vendor/bin/dep
        '#(?:^|[\s;&|(){}`])dep(?![\w./-])#',       // 素の dep (前後の境界つき)
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $segment) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * `run_dep()` 関数本体の行範囲 (1-indexed / 定義行と閉じ括弧行を含む)。
 *
 * 「`run_dep()` が存在する」と「dep 起動がちょうど 1 つ」を別々に検査すると、
 * `run_dep(){ :; }` のダミーを残したまま起動を別の場所に移せる (Codex 指摘)。
 * **数えた起動がこの範囲内にあること**まで結び付けるために範囲を取る。
 *
 * @return array{start: int, end: int}|null
 */
function deployWrapperRunDepRange(string $source): ?array
{
    $lines = explode("\n", $source);
    $start = null;
    $depth = 0;

    foreach ($lines as $index => $line) {
        $code = deployPipelineStripShellInlineComment($line);

        if ($start === null) {
            if (preg_match('/^run_dep\(\)\s*\{/', $code) !== 1) {
                continue;
            }

            $start = $index + 1;
        }

        $depth += substr_count($code, '{') - substr_count($code, '}');

        if ($depth <= 0) {
            return ['start' => $start, 'end' => $index + 1];
        }
    }

    return null;
}

/**
 * wrapper 内で dep に言及している **単純コマンド**の一覧。
 *
 * @return list<array{line: int, segment: string}>
 */
function deployWrapperDepSegments(string $source): array
{
    $found = [];

    foreach (deployShellCommands($source) as $entry) {
        if (deployWrapperSegmentMentionsDep($entry['segment'])) {
            $found[] = $entry;
        }
    }

    return $found;
}

/**
 * 単純コマンドを **quote 対応**で語に分割する。
 *
 * 素の `preg_split('/\s+/')` だと `REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"` が
 * 複数語に割れ、2 語目 (`"$(dirname`) を「実効コマンド語」と誤認する (実測の誤検知)。
 * 引用符内と `$( )` 内では空白で割らない。
 *
 * @return list<string>
 */
function deployShellWords(string $segment): array
{
    $words = [];
    $current = '';
    $inSingle = false;
    $inDouble = false;
    $depth = 0;
    $length = strlen($segment);

    for ($index = 0; $index < $length; $index++) {
        $char = $segment[$index];

        if ($char === '\\' && ! $inSingle && $index + 1 < $length) {
            $current .= $char.$segment[$index + 1];
            $index++;

            continue;
        }

        if ($char === "'" && ! $inDouble) {
            $inSingle = ! $inSingle;
            $current .= $char;

            continue;
        }

        if ($char === '"' && ! $inSingle) {
            $inDouble = ! $inDouble;
            $current .= $char;

            continue;
        }

        if (! $inSingle && ! $inDouble) {
            // 括弧はすべて深さとして数える。`$(` だけを数えると
            // `A=$(( (RANDOM % 900) + 100 ))` の内側の `)` で深さが 0 に戻り、
            // 以降の空白で語が割れて `+` が「コマンド語」に見える (実測の誤検知)。
            // 配列代入 `DEP_CMD=("${X}" -f y)` も同じ理由で 1 語として保つ必要がある。
            if ($char === '(') {
                $depth++;
                $current .= $char;

                continue;
            }

            if ($char === ')' && $depth > 0) {
                $depth--;
                $current .= $char;

                continue;
            }

            if ($depth === 0 && preg_match('/\s/', $char) === 1) {
                if ($current !== '') {
                    $words[] = $current;
                    $current = '';
                }

                continue;
            }
        }

        $current .= $char;
    }

    if ($current !== '') {
        $words[] = $current;
    }

    return $words;
}

/**
 * 単純コマンドの **実効コマンド語** (先頭の代入群を剥がした後の語)。
 *
 * 「先頭語が変数展開か」だけを見ると `X=1 "${RUNNER}" …` が素通りする (Codex 指摘)。
 * 代入 (env 前置を含む) を剥がしてから判定する。ラッパ (`command` / `env` / `sudo` …) は
 * **剥がさず、それ自体を禁止語として扱う** (追う必要を無くす)。
 * 判定できない場合 (代入だけの単純コマンド等) は空文字を返す。
 */
function deployWrapperEffectiveCommandWord(string $segment): string
{
    // 先頭に立つ shell キーワードは「その後ろの語が実行される」ことを意味するので剥がす。
    // 剥がさないと `if ! git merge-base …` の実効コマンド語が `if` になり、
    // **git がそもそも検査対象に見えなくなる** (実測。`git` の形 pin が空振りしていた)。
    // 単独で現れる `then` / `}` 等は剥がさない (後続の語が無いので実行対象ではない)。
    $keywords = ['if', 'elif', 'while', 'until', '!', 'then', 'else', 'do', '{'];

    $words = deployShellWords($segment);
    $index = 0;
    $count = count($words);

    while ($index < $count - 1
        && (in_array($words[$index], $keywords, true)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*\+?=/', $words[$index]) === 1)) {
        $index++;
    }

    // 末尾が代入だけの場合 (単独代入) は実行ではない。
    if ($index < $count && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\+?=/', $words[$index]) === 1) {
        return '';
    }

    return $words[$index] ?? '';
}

/**
 * wrapper 内の **間接実行**を列挙する (行番号つき)。
 *
 * dep の起動検出は「`dep` という語への言及」を根拠にしている。語を経由しない実行
 * (`eval` / `sh -c` / 変数をコマンド語として展開) を許すと、`DEP_CMD=("${DEP_BIN}" -f …)` と
 * 積んで `"${DEP_CMD[@]}"` で実行する形が **検出そのものを迂回する** (Codex 指摘)。
 * この wrapper では間接実行を使う理由が無いので**全面禁止**する。
 *
 * @return list<string>
 */
function deployWrapperIndirectExecutions(string $source): array
{
    $offenders = [];

    foreach (deployShellCommands($source) as $entry) {
        $segment = $entry['segment'];

        // run_dep の本体だけは変数展開をコマンド語に使う (pin 済みの正典形)。
        if ($segment === DEPLOY_WRAPPER_RUN_DEP_BODY) {
            continue;
        }

        // **構文ごと禁止**するもの (コマンド語を組み立てて実行する経路)。
        // 実効コマンド語の判定だけでは捕まらない形がここに落ちる:
        //   `` `printf '%s%s' 'vendor/bin/' 'dep'` -f … deploy ``
        //   `source <(printf '%s' 'vendor/bin/de' 'p …')`
        // 照合は **隣接文字列連結を解いた形**に対して行う
        // (`'.g''it/config'` を `.git/config` として捕まえる)。
        $resolved = deployResolveShellConcatenation($segment);
        foreach (DEPLOY_WRAPPER_FORBIDDEN_SYNTAX as $needle => $why) {
            if (str_contains($resolved, $needle)) {
                $offenders[] = $entry['line'].': '.$segment.' ('.$needle.': '.$why.')';

                continue 2;
            }
        }

        // **env 前置つき実行 (`VAR=x cmd`) は全面禁止**。
        // `git` の実行面は argv (`-c alias.X=!cmd`) だけでなく **env** にもある
        // (`GIT_CONFIG_COUNT` / `GIT_CONFIG_KEY_0` / `GIT_SSH_COMMAND` / `GIT_EXEC_PATH` …)。
        // 危険な変数名を列挙すると `LD_PRELOAD` / `BASH_ENV` / `IFS` … と際限がないので、
        // **env 前置そのもの**を禁じて channel ごと閉じる (Codex 指摘)。
        // 単独の代入 (`HOST="${arg}"`) は実行ではないので影響しない。
        $effective = deployWrapperEffectiveCommandWord($segment);
        if ($effective === '') {
            continue;
        }

        // 先頭キーワードを剥がした残りに代入が挟まっていれば env 前置つき実行である。
        $words = deployShellWords($segment);
        $keywords = ['if', 'elif', 'while', 'until', '!', 'then', 'else', 'do', '{'];
        $rest = array_values(array_filter(
            $words,
            fn (string $word): bool => ! in_array($word, $keywords, true)
        ));
        if (($rest[0] ?? '') !== $effective
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*\+?=/', (string) ($rest[0] ?? '')) === 1) {
            $offenders[] = $entry['line'].': '.$segment.
                ' (env 前置つき実行は禁止: GIT_CONFIG_* / LD_PRELOAD 等の env チャネルを一括で閉じる)';

            continue;
        }

        // 禁止ラッパ (basename で照合。`/bin/bash -c …` のようなフルパス形も捕まえる)。
        $basename = basename(trim($effective, '"\''));
        $reason = DEPLOY_WRAPPER_FORBIDDEN_COMMAND_WORDS[$basename] ?? null;
        if ($reason !== null) {
            $offenders[] = $entry['line'].': '.$segment.' ('.$basename.': '.$reason.')';

            continue;
        }

        // コマンド語が変数展開 = 間接実行。
        if (str_contains($effective, '$')) {
            $offenders[] = $entry['line'].': '.$segment.' (変数展開をコマンド語に使っている)';

            continue;
        }

        // `git` は **呼び出し形を完全一致で pin** する。
        // 「git を許してオプションだけ deny」だと `-c alias` → `GIT_CONFIG_*` →
        // repo config への追記 → hook … と実行チャネルが次々出てくる (Codex 指摘)。
        // 使う形を全数登録すれば、`git <任意のサブコマンド>` (alias 起動を含む) が閉じる。
        if ($basename === 'git') {
            // 先頭キーワード (`if ! …`) とリダイレクト (`>&2`) を落として台帳と照合する。
            $normalized = preg_replace('/\s*>&?\d*\s*$/', '', implode(' ', $rest)) ?? '';
            if (! array_key_exists(trim($normalized), DEPLOY_WRAPPER_ALLOWED_GIT_FORMS)) {
                $offenders[] = $entry['line'].': '.$segment.
                    ' (台帳外の git 呼び出し形。DEPLOY_WRAPPER_ALLOWED_GIT_FORMS に用途付きで登録すること)';
            }

            continue;
        }

        // 他の allow 済みコマンドが内包する実行機構を閉じる。
        foreach (DEPLOY_WRAPPER_FORBIDDEN_OPTIONS[$basename] ?? [] as $option => $why) {
            foreach (deployShellWords($segment) as $word) {
                $bare = trim($word, '"\'');
                if ($bare === $option || str_starts_with($bare, $option)) {
                    $offenders[] = $entry['line'].': '.$segment.' ('.$basename.' '.$option.': '.$why.')';

                    continue 3;
                }
            }
        }
    }

    return $offenders;
}

/** 単純コマンドを台帳比較用に正規化する (前後 trim + 連続空白の 1 個化)。 */
function deployNormalizeSegment(string $segment): string
{
    return trim((string) preg_replace('/\s+/', ' ', $segment));
}

/** その単純コマンドが「起動ではない」と台帳で認められているか (**完全一致**)。 */
function deployWrapperLineIsNonInvocation(string $segment): bool
{
    return array_key_exists(
        deployNormalizeSegment($segment),
        DEPLOY_WRAPPER_DEP_NON_INVOCATION
    );
}

/**
 * `deploy/**` の PHP ファイル一覧 (リポジトリ相対)。
 *
 * **再帰**で列挙する。固定の 2 階層 glob にすると、将来ネストしたファイルが
 * W4 / W6 / W17 / W20 から見えなくなる (契約と実装が乖離する。Codex 指摘)。
 *
 * @return list<string>
 */
function deployPhpFiles(): array
{
    $root = base_path('deploy');
    if (! is_dir($root)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];
    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = ltrim(str_replace(base_path(), '', $entry->getPathname()), '/');
        }
    }

    sort($files);

    return $files;
}

/**
 * recipe/laravel.php が元から持つ task 名の集合 (`dep list --raw` の第 1 カラム)。
 *
 * @return list<string>
 */
function deployRecipeTaskNames(): array
{
    $result = deployDepRun(['-f', DEPLOY_RECIPE_BASELINE, 'list', '--raw']);

    expect($result['exitCode'])->toBe(
        0,
        'recipe 基準の list に失敗した: '.$result['stderr']
    );

    $names = [];
    foreach (explode("\n", $result['stdout']) as $line) {
        // `--raw` は "name<空白>description" 形式。名前だけを取る。
        if (preg_match('/^([A-Za-z][\w:.-]*)\s/', $line, $matches) === 1) {
            $names[] = $matches[1];
        }
    }

    return array_values(array_unique($names));
}

/**
 * ソース中の `task('name'` 宣言名を返す。
 *
 * @return list<string>
 */
function deployDeclaredTasks(string $source): array
{
    $count = preg_match_all("/\btask\(\s*'([^']+)'/", $source, $found);
    if ($count === false || $count === 0) {
        return [];
    }

    $names = [];
    foreach ($found[1] as $name) {
        if (is_string($name)) {
            $names[] = $name;
        }
    }

    return $names;
}

// ───────────────────────── W1-W4: 前提の実在 ─────────────────────────

test('W1: vendor/bin/dep が実在する (不在は skip せず fail)', function (): void {
    // 不在時に skip すると「Deployer を入れ忘れた PR」が黙って green になる。
    expect(is_file(base_path('vendor/bin/dep')))->toBeTrue(
        'vendor/bin/dep がありません。composer install を実行してください (deployer/deployer は require-dev)'
    );
});

test('W2: Deployer の版が二層 floor pin されている', function (): void {
    /** @var array{'require-dev'?: array<string, string>} $composer */
    $composer = json_decode(deployPipelineRead('composer.json'), true);
    expect($composer['require-dev']['deployer/deployer'] ?? null)->toBe(DEPLOY_DEPLOYER_CONSTRAINT);

    /** @var array{packages?: list<array{name: string, version: string}>, 'packages-dev'?: list<array{name: string, version: string}>} $lock */
    $lock = json_decode(deployPipelineRead('composer.lock'), true);
    $locked = null;
    foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
        if ($package['name'] === 'deployer/deployer') {
            $locked = $package['version'];
        }
    }

    expect($locked)->toBeString();
    expect(str_starts_with((string) $locked, DEPLOY_DEPLOYER_LOCK_PREFIX))->toBeTrue(
        "composer.lock の deployer 版が {$locked} です (".DEPLOY_DEPLOYER_LOCK_PREFIX.'x を要求)。'.
        'メジャー / マイナーを動かす PR では recipe の既定 deploy 構成が変わりうるので、'.
        'DEPLOY_BAND_ORDER の anchor (artisan:optimize) が実在するかを dep tree deploy で再確認すること'
    );
});

test('W3: deploy 成果物 3 点が実在する', function (): void {
    foreach ([DEPLOY_CONFIG_FILE, DEPLOY_HOSTS_EXAMPLE, DEPLOY_WRAPPER] as $relative) {
        expect(is_file(base_path($relative)))->toBeTrue($relative.' がありません');
    }
});

test('W4: deploy/**/*.php が php -l を通る', function (): void {
    $files = deployPhpFiles();
    expect($files)->not->toBe([], 'deploy/ に PHP ファイルがありません (空振り)');

    $failures = [];
    foreach ($files as $relative) {
        $result = Process::path(base_path())->timeout(60)->run([PHP_BINARY, '-l', $relative]);
        if (! $result->successful()) {
            $failures[] = $relative.': '.trim($result->output().$result->errorOutput());
        }
    }

    expect($failures)->toBe([]);
});

// ───────────────────── W5-W13: Deployer 自身に解決させる検査 ─────────────────────

test('W5: dep tree deploy / tree rollback / deploy --plan all が exit 0 で完走する', function (): void {
    // deployDepLines が exit 0 を要求するので、ここは「呼べること」+ 出力が空でないことを見る。
    expect(deployTreeLines('deploy'))->not->toBe([]);
    expect(deployTreeLines('rollback'))->not->toBe([]);
    expect(deployPlanTable()['rows'])->not->toBe([]);
});

test('W6: deploy/**/*.php の全 task 宣言が台帳に登録されている (deny-by-default)', function (): void {
    // **`deploy/tasks/` に限定しない**。deploy.php 側に task を足しても未登録検出に入らないと
    // deny-by-default を名乗れない (実際 deploy:confirm-stage / artisan:migrate は deploy.php 宣言)。
    $declared = [];
    foreach (deployPhpFiles() as $relative) {
        foreach (deployDeclaredTasks(deployPipelineRead($relative)) as $name) {
            $declared[] = $name;
        }
    }

    $declared = array_values(array_unique($declared));
    expect($declared)->not->toBe([], 'deploy/ に task 宣言がありません (空振り)');

    $known = array_merge(array_keys(DEPLOY_TASK_BANDS), array_keys(DEPLOY_RECIPE_TASK_OVERRIDES));

    $unregistered = array_values(array_diff($declared, $known));
    expect($unregistered)->toBe(
        [],
        'band 台帳 (DEPLOY_TASK_BANDS) にも override 台帳にも無い task があります。'.
        '新規 task なら band を決めて登録し、recipe task の再定義なら override 台帳に理由付きで登録してください'
    );

    // 逆方向: 台帳に載っているのに deploy/** のどこでも宣言されていない = 腐った行。
    $stale = array_values(array_diff($known, $declared));
    expect($stale)->toBe([], '台帳に宣言されていない task が残っています (行を消すか task を書く)');

    // override 台帳の reason 非空 (「なぜ再定義するのか」を書かせる)。
    $noReason = [];
    foreach (DEPLOY_RECIPE_TASK_OVERRIDES as $task => $reason) {
        if (trim($reason) === '') {
            $noReason[] = $task;
        }
    }
    expect($noReason)->toBe([]);

    // **override 台帳が escape hatch になっていないことの証明**:
    // 各 entry が実在する recipe task を指していること。新しい task をここに書いても
    // recipe に無ければ fail するので、band + W7 をまとめて迂回できない。
    $recipeTasks = deployRecipeTaskNames();
    expect($recipeTasks)->not->toBe([], 'recipe 基準の task 一覧を取得できませんでした (空振り)');
    // 基準が本当に「recipe だけ」であることの裏: 自前 task は含まれない。
    expect($recipeTasks)->toContain('deploy:vendors');
    expect($recipeTasks)->not->toContain('deploy:confirm-stage');

    $notRecipe = array_values(array_diff(array_keys(DEPLOY_RECIPE_TASK_OVERRIDES), $recipeTasks));
    expect($notRecipe)->toBe(
        [],
        'override 台帳に recipe 由来でない task が登録されています。'.
        '新規 task は band 台帳 (DEPLOY_TASK_BANDS) に登録してください'
    );
});

test('W7: band 所属 task が dep tree deploy 上で band の順序条件を満たす', function (): void {
    $deployTree = deployTreeLines('deploy');
    $rollbackTree = deployTreeLines('rollback');

    $violations = [];

    foreach (DEPLOY_TASK_BANDS as $task => $band) {
        $order = DEPLOY_BAND_ORDER[$band] ?? null;
        if ($order === null) {
            $violations[] = "{$task}: band {$band} に順序条件の定義がありません";

            continue;
        }

        $index = deployIndexOf($deployTree, $task);
        if ($index < 0) {
            // **不在を黙って許さない**。opt-in band (deploy.php が既定で require しないもの) 以外は
            // tree に現れないこと自体が違反である。ここで continue するだけにすると
            // 「require 行を消せば順序検査ごと消える」= 配線を外して green にできてしまう。
            if (($order['optional'] ?? false) !== true) {
                $violations[] = "{$task}: band {$band} は必須配線ですが tree deploy に現れません ".
                    '(deploy/deploy.php の require が外れていないか)';
            }

            continue;
        }

        if (($order['first'] ?? false) === true && $index !== 0) {
            $violations[] = "{$task}: band {$band} は tree 先頭 (index 0) であるべきですが index {$index} です";
        }

        foreach (['after', 'before'] as $direction) {
            $anchor = $order[$direction] ?? null;
            if ($anchor === null) {
                continue;
            }

            $anchorIndex = deployIndexOf($deployTree, $anchor);
            if ($anchorIndex < 0) {
                $violations[] = "{$task}: anchor {$anchor} が tree に現れません (検査が空振りしている)";

                continue;
            }

            if ($direction === 'after' && $index <= $anchorIndex) {
                $violations[] = "{$task}: {$anchor} より後であるべきですが index {$index} <= {$anchorIndex} です";
            }

            if ($direction === 'before' && $index >= $anchorIndex) {
                $violations[] = "{$task}: {$anchor} より前であるべきですが index {$index} >= {$anchorIndex} です";
            }
        }

        if (($order['in_rollback'] ?? false) === true && deployIndexOf($rollbackTree, $task) < 0) {
            $violations[] = "{$task}: band {$band} は rollback にも配線されているべきですが tree rollback に現れません";
        }
    }

    expect($violations)->toBe([]);
});

test('W8: deploy:confirm-stage が tree deploy の index 0 に現れる', function (): void {
    // 本番ゲートが先頭にあること = 「何かが動く前に止まる」ことの位置的保証。
    expect(deployIndexOf(deployTreeLines('deploy'), 'deploy:confirm-stage'))->toBe(0);
});

test('W9: deploy:verify が起動キャッシュ生成の後・migrate の前にある', function (): void {
    $tree = deployTreeLines('deploy');

    $verify = deployIndexOf($tree, 'deploy:verify');
    // Deployer 8 の laravel recipe は config / event / route / view を artisan:optimize で一括生成する
    // (正典 = Deployer 7.5 系では artisan:config:cache が個別に現れていた)。
    $bootCache = deployIndexOf($tree, 'artisan:optimize');
    $migrate = deployIndexOf($tree, 'artisan:migrate');

    expect($bootCache)->toBeGreaterThan(-1);
    expect($migrate)->toBeGreaterThan(-1);
    expect($verify)->toBeGreaterThan($bootCache);
    // migrate の後に置くと「DB は進んだのに current は旧コード」の mixed state になる。
    expect($verify)->toBeLessThan($migrate);
});

test('W10: build:frontend が vendors の後・symlink の前で、build:packages が build より先', function (): void {
    $tree = deployTreeLines('deploy');

    $frontend = deployIndexOf($tree, 'build:frontend');
    $vendors = deployIndexOf($tree, 'deploy:vendors');
    $symlink = deployIndexOf($tree, 'deploy:symlink');

    expect($vendors)->toBeGreaterThan(-1);
    expect($symlink)->toBeGreaterThan(-1);
    expect($frontend)->toBeGreaterThan($vendors);
    expect($frontend)->toBeLessThan($symlink);

    // workspace package の dist/ が無いとアプリ側 import が ERR_MODULE_NOT_FOUND で落ちる。
    // needle が正典 (素の `run('pnpm run build')`) と違うのは aicue が corepack 経由で呼ぶため。
    // アプリ build 側は **閉じ引用符まで**を needle に含める (`pnpm run build:packages` を
    // 先に拾って順序検査が空洞化するのを防ぐ)。
    $source = deployPipelineRead('deploy/tasks/frontend.php');
    $packages = strpos($source, 'pnpm run build:packages');
    $app = strpos($source, "pnpm run build'");

    expect($packages)->toBeInt('build:packages の呼び出しが見つかりません');
    expect($app)->toBeInt('アプリ build の呼び出しが見つかりません');
    expect($packages)->toBeLessThan($app);
});

test('W11: deploy:restart が tree rollback にも現れる', function (): void {
    // コードだけ戻して php-fpm / worker を再起動しないと旧コードが動き続ける。
    expect(deployIndexOf(deployTreeLines('rollback'), 'deploy:restart'))->toBeGreaterThan(-1);
});

test('W12: deploy --plan all に全セルが - の行が存在しない (死んだ selector 検出)', function (): void {
    $table = deployPlanTable();

    expect($table['hosts'])->not->toBe([], 'plan 表の host 行を解析できませんでした');

    $dead = [];
    foreach ($table['rows'] as $index => $cells) {
        $names = array_values(array_filter($cells, fn (string $cell): bool => $cell !== '-'));
        if ($names === []) {
            $dead[] = 'row '.$index;
        }
    }

    expect($dead)->toBe(
        [],
        "0 host にマッチする selector (死んだ ->select()) があります。plan:\n".
        implode("\n", array_map(fn (array $c): string => implode(' | ', $c), $table['rows']))
    );
});

test('W13: artisan:migrate が plan 表のちょうど 1 セルに現れる (once の効果)', function (): void {
    $table = deployPlanTable();

    $occurrences = 0;
    foreach ($table['rows'] as $cells) {
        foreach ($cells as $cell) {
            if ($cell === 'artisan:migrate') {
                $occurrences++;
            }
        }
    }

    expect($occurrences)->toBe(1, 'migrate が複数 host で走る形になっています (->once() を確認)');
});

// ───────────────────────── W14-W20: deploy.php の静的契約 ─────────────────────────

test('W14: deploy.php が vendor/autoload.php を require していない', function (): void {
    // Deployer は自身の autoloader を持つ。composer autoload を読んでも App\* は解決できない。
    // `require_once` / 二重引用符 / 括弧付きの等価表現も落とす (literal 一致では抜ける)。
    expect(deployPipelineReadPhpCode(DEPLOY_CONFIG_FILE))->not->toMatch(DEPLOY_RE_AUTOLOAD_REQUIRE);
});

test('W15: restart.php に無言 fail-open (|| true / || : 等) が無い', function (): void {
    // `||true` / `||  true` / `|| /bin/true` / `|| :` すべてを落とす。
    expect(deployPipelineReadPhpCode('deploy/tasks/restart.php'))->not->toMatch(DEPLOY_RE_SHELL_FAIL_OPEN);
});

test('W16: 再起動フラグの既定値が宣言されている', function (): void {
    $source = deployPipelineReadPhpCode(DEPLOY_CONFIG_FILE);

    expect($source)->toMatch(deployRePhpSet('php_fpm_reload_enabled', 'true'));
    // worker を常駐させる host は hosts.yml で明示 true 宣言する (無言 skip を作らない)。
    // フラグ名が正典の `supervisor_enabled` と違うのは、aicue の worker が supervisor ではなく
    // systemd unit として常駐するため (契約は同じ = 既定 false + host が明示 true)。
    expect($source)->toMatch(deployRePhpSet('queue_worker_restart_enabled', 'false'));
    // 正典の supervisor 系フラグが残っていないこと (systemd へ移した以上、
    // 読まれないフラグを宣言だけ残すと「宣言したのに効かない」事故になる)。
    expect($source)->not->toMatch('/\bset\s*\(\s*[\'"]supervisor_/');
});

test('W17: composer_options を再定義していない (recipe 既定の --no-dev を保つ)', function (): void {
    foreach (deployPhpFiles() as $relative) {
        expect(deployPipelineReadPhpCode($relative))->not->toMatch(DEPLOY_RE_COMPOSER_OPTIONS);
    }
});

test('W18: writable_dirs が donor 和集合 10 件をすべて含む', function (): void {
    $source = deployPipelineReadPhpCode(DEPLOY_CONFIG_FILE);

    $missing = [];
    foreach (DEPLOY_WRITABLE_DIRS as $dir) {
        if (! str_contains($source, "'".$dir."',")) {
            $missing[] = $dir;
        }
    }

    expect($missing)->toBe([]);
});

test('W19: deploy:failed で unlock される', function (): void {
    expect(deployPipelineReadPhpCode(DEPLOY_CONFIG_FILE))
        ->toMatch(deployReHook('after', 'deploy:failed', 'deploy:unlock'));
});

test('W20: rollback に artisan:cli-oauth:ensure を配線していない', function (): void {
    // OAuth client は release 非依存の DB 行。revoke すれば全 CLI 利用者が強制ログアウトされる。
    foreach (deployPhpFiles() as $relative) {
        expect(deployPipelineReadPhpCode($relative))
            ->not->toMatch(deployReHook('after', 'rollback', 'artisan:cli-oauth:ensure'));
    }
});

// ───────────────────────── W21-W22: wrapper の静的契約 ─────────────────────────

test('W21: wrapper の TTY 拒否が --production 分岐の中・push より前にある', function (): void {
    $source = deployPipelineStripShellComments(deployPipelineRead(DEPLOY_WRAPPER));

    // 本番判定の知識は hosts.yml の stage + deploy:confirm-stage に置く (host 名を焼かない)。
    expect($source)->not->toContain('PROD_HOSTS=(');

    // 存在だけでは dead code / 分岐外への移動を許してしまうので **位置関係**まで固定する。
    // (TTY 拒否の意味そのものは deploy:confirm-stage 側の W27 が振る舞いで pin する。
    //  wrapper 側は静的検査しかできないため、構造の妥当性までを担当する。)
    $productionBranch = strpos($source, '"${PRODUCTION}" -eq 1');
    $ttyCheck = strpos($source, '[[ ! -t 0 ]]');
    $push = strpos($source, 'git push origin');

    expect($productionBranch)->toBeInt('--production 分岐が見つかりません');
    expect($ttyCheck)->toBeInt('非 TTY 拒否 ([[ ! -t 0 ]]) が見つかりません');
    expect($push)->toBeInt('git push が見つかりません');
    expect($ttyCheck)->toBeGreaterThan($productionBranch);
    expect($ttyCheck)->toBeLessThan($push);
});

test('W22: wrapper の dep 起動は run_dep のちょうど 1 箇所で -f を伴う (B 形)', function (): void {
    $source = deployPipelineRead(DEPLOY_WRAPPER);

    expect($source)->toContain('DEPLOY_FILE="'.DEPLOY_CONFIG_FILE.'"');

    // 起動口を関数 1 つに絞る規約 (`-f` の付け忘れを構造的に防ぐ)。
    $runDep = deployWrapperRunDepRange($source);
    expect($runDep)->not->toBeNull('run_dep() の定義が見つかりません');

    // **run_dep 本体を完全一致で pin する**。本体をダミーにして実行を別所へ移す形
    // (`DEP_CMD=(...)` に積んで `"${DEP_CMD[@]}"` で実行する等) を封じる。
    $body = [];
    foreach (deployShellCommands($source) as $entry) {
        if ($entry['line'] > $runDep['start'] && $entry['line'] < $runDep['end']) {
            $body[] = $entry['segment'];
        }
    }
    expect($body)->toBe(
        [DEPLOY_WRAPPER_RUN_DEP_BODY],
        'run_dep() の本体は正典形 1 行だけであるべきです'
    );

    // **間接実行の全面禁止** (語を経由しない実行を許すと dep 起動の検出が空洞化する)。
    expect(deployWrapperIndirectExecutions($source))->toBe([]);

    $segments = deployWrapperDepSegments($source);
    // 空振り検出: dep に言及する単純コマンドが 1 つも無いなら検査は無意味。
    expect($segments)->not->toBe([], 'dep への言及が 1 つもありません (検査が空振りしている)');

    // **deny-by-default**: 台帳で「起動でない」と認めた形状以外は -f を要求する。
    // 判定は **単純コマンド単位** (行単位だと `[[ … ]] && dep …` のような混在行が丸ごと skip される)。
    // 行内コメントは除去済み (コメントで -f を満たせないようにする)。
    $invocations = [];
    $offenders = [];
    $outsideRunDep = [];
    foreach ($segments as $entry) {
        if (deployWrapperLineIsNonInvocation($entry['segment'])) {
            continue;
        }

        $label = $entry['line'].': '.trim($entry['segment']);
        $invocations[] = $label;

        if (preg_match(DEPLOY_RE_DEP_CONFIG_FLAG, $entry['segment']) !== 1) {
            $offenders[] = $label;
        }

        // **数えた起動が run_dep の本体にあること**まで結び付ける。
        // これが無いと `run_dep(){ :; }` のダミーを残して起動を別所に移せる。
        if ($entry['line'] < $runDep['start'] || $entry['line'] > $runDep['end']) {
            $outsideRunDep[] = $label;
        }
    }

    // ルートで素の `dep deploy` は `Command "deploy" is not defined.` になる (7.5.12 実測文言)。
    expect($offenders)->toBe([]);
    expect($outsideRunDep)->toBe(
        [],
        "run_dep() の外で dep を起動しています (起動は run_dep 経由に集約する):\n".
        implode("\n", $outsideRunDep)
    );
    // **起動箇所はちょうど 1 つ** であること。
    // 「-f が付いていること」だけを要求すると起動口が増えても気付けないが、件数を固定すれば
    // 起動を 1 つ足した時点で fail する (台帳の分類漏れも同時に検出できる)。
    expect($invocations)->toHaveCount(
        1,
        "dep の起動箇所は run_dep の 1 箇所であるべきです:\n".implode("\n", $invocations)
    );
});

// ───────────────────────── W23: パーサ自己テスト ─────────────────────────

test('W23: パーサ自己テスト (plan 表 / task index / hygiene 台帳読み)', function (): void {
    // ── deployParsePlanTable ──
    $table = "┌────┬────┐\n"
        ."│ h1 │ h2 │\n"
        ."├────┼────┤\n"
        ."│ a  │ a  │\n"
        ."│ -  │ -  │\n"
        ."│ b  │ -  │\n"
        ."└────┴────┘\n";

    $parsed = deployParsePlanTable($table);
    expect($parsed['hosts'])->toBe(['h1', 'h2']);
    expect($parsed['rows'])->toBe([['a', 'a'], ['-', '-'], ['b', '-']]);

    // 全セル `-` の行が検出できること (W12 の根拠)
    $dead = array_values(array_filter(
        $parsed['rows'],
        fn (array $cells): bool => array_values(array_filter($cells, fn (string $c): bool => $c !== '-')) === []
    ));
    expect($dead)->toBe([['-', '-']]);

    // ちょうど 1 セルの検出 (W13 の根拠)
    $once = 0;
    foreach ($parsed['rows'] as $cells) {
        foreach ($cells as $cell) {
            if ($cell === 'b') {
                $once++;
            }
        }
    }
    expect($once)->toBe(1);

    // ── deployIndexOf: 境界付きマッチ ──
    $lines = ['├── deploy', '├── deploy:vendors', '├── build:frontend'];
    expect(deployIndexOf($lines, 'deploy'))->toBe(0);
    expect(deployIndexOf($lines, 'deploy:vendors'))->toBe(1);
    expect(deployIndexOf($lines, 'build:frontend'))->toBe(2);
    expect(deployIndexOf($lines, 'artisan:migrate'))->toBe(-1);
    // 部分一致で誤ヒットしないこと
    expect(deployIndexOf(['├── deploy:vendors'], 'deploy:vendor'))->toBe(-1);

    // ── deployTreeLines の注釈除去が効くこと (実測の落とし穴) ──
    // 注釈を残すと submodules の行が deploy:vendors として先に見つかる。
    $annotated = ['├── deploy:submodules  // before deploy:vendors', '├── deploy:vendors'];
    expect(deployIndexOf($annotated, 'deploy:vendors'))->toBe(0);
    $strippedAnnotated = array_map(
        fn (string $line): string => ($p = strpos($line, '//')) === false ? $line : substr($line, 0, $p),
        $annotated
    );
    expect(deployIndexOf($strippedAnnotated, 'deploy:vendors'))->toBe(1);

    // ── deployDeclaredTasks ──
    expect(deployDeclaredTasks("task('a:b', function () {});\ntask( 'c:d' , foo());"))->toBe(['a:b', 'c:d']);
    expect(deployDeclaredTasks("before('deploy', 'x');"))->toBe([]);

    // ── deployStripPhpComments: 説明コメント中の needle を落とし、実コードは残す ──
    $php = "<?php\n"
        ."// `require 'vendor/autoload.php'` は書かない\n"
        ."/* run('x || true'); と書いてはならない */\n"
        ."/** @var int \$x */\n"
        ."require 'recipe/laravel.php';\n"
        ."set('supervisor_enabled', false);\n";

    $code = deployStripPhpComments($php);
    expect($code)->not->toContain("require 'vendor/autoload.php'");
    expect($code)->not->toContain('|| true');
    expect($code)->not->toContain('@var');
    // 実コードは残る (required 側の needle が消えてはならない)
    expect($code)->toContain("require 'recipe/laravel.php';");
    expect($code)->toContain("set('supervisor_enabled', false);");
    // 逆に、コメントが required needle を満たす偽グリーンを作らないこと
    expect(deployStripPhpComments("<?php\n// set('supervisor_enabled', false);\n"))
        ->not->toContain("set('supervisor_enabled', false)");

    // ── deployPipelineStripShellComments: 行頭 # のみ落とす ──
    $shell = "#!/usr/bin/env bash\n"
        ."# donor の PROD_HOSTS=( ... ) は持たない\n"
        ."  # インデントされたコメントも落とす\n"
        ."if [[ ! -t 0 ]]; then\n"
        ."echo \"a#b\"\n";

    $shellCode = deployPipelineStripShellComments($shell);
    expect($shellCode)->not->toContain('PROD_HOSTS=(');
    expect($shellCode)->not->toContain('インデントされたコメント');
    expect($shellCode)->toContain('[[ ! -t 0 ]]');
    // 行内 # は壊さない
    expect($shellCode)->toContain('"a#b"');

    // ── deployWrapperDepLines + 非起動台帳: 未知の書き方は fail 側に倒れる ──
    $wrapper = "DEP_BIN=\"vendor/bin/dep\"\n"                              //  1: 変数定義 (非起動)
        ."if [[ ! -x \"\${DEP_BIN}\" ]]; then\n"                           //  2: 存在検査 (非起動)
        // 3: 案内メッセージ (非起動)。**引用符は閉じる** — 閉じないと scanner が以降を
        //    文字列として飲み込む (それが正しい挙動。実測して気付いた)
        ."    die \"\${DEP_BIN} がありません。composer install を実行してください (deployer/deployer は require-dev です)\"\n"
        ."\"\${DEP_BIN}\" -f \"\${DEPLOY_FILE}\" deploy \"\${HOST}\"\n"    //  4: 正しい起動
        ."# \"\${DEP_BIN}\" deploy  (コメントは対象外)\n"                    //  5: コメント (行ごと除去)
        ."\"\${DEP_BIN}\" deploy \"\${HOST}\"\n"                           //  6: -f 無し
        ."vendor/bin/dep deploy prd\n"                                     //  7: 別表記 + -f 無し
        ."./vendor/bin/dep -f deploy/deploy.php tree deploy\n"             //  8: 別表記 + -f あり
        ."  dep deploy prd\n"                                              //  9: 素の dep
        ."if ! OUT=\"\$(\"\${DEP_BIN}\" -f \"\${DEPLOY_FILE}\" deploy:confirm-stage x)\"; then\n" // 10: 捕捉付き起動
        ."if vendor/bin/dep deploy prd; then\n"                            // 11: if 直後 (-f 無し)
        ."while dep deploy prd; do\n"                                      // 12: while 直後 (-f 無し)
        ."VAR=1 dep deploy prd\n"                                          // 13: env 前置 (-f 無し)
        ."{ dep deploy prd; }\n";                                          // 14: グループ (-f 無し)

    // ── deployShellCommands: 混在行の分割 ──
    $commandsOf = fn (string $source): array => array_column(deployShellCommands($source), 'segment');

    expect($commandsOf("echo start; dep deploy prd\n"))->toBe(['echo start', 'dep deploy prd']);
    expect($commandsOf("if [[ -n \"\$x\" ]] && dep deploy prd; then\n"))
        ->toBe(['if [[ -n "$x" ]]', 'dep deploy prd', 'then']);

    // 引用符内の区切り文字では分割しない
    expect($commandsOf("grep -nE \"^set'(a|b)'\" f\n"))->toBe(['grep -nE "^set\'(a|b)\'" f']);

    // `$( )` は 1 語として保ちつつ、**内側も再帰的に**コマンドとして列挙する
    // (外側だけ許可語にして実行を隠す形を封じる)
    expect($commandsOf("OUT=\"\$(dep deploy a; echo b)\"\n"))
        ->toBe(['OUT="$(dep deploy a; echo b)"', 'dep deploy a', 'echo b']);
    // 二重の入れ子も辿る
    expect($commandsOf("echo \"\$(\$(printf x) -f y deploy)\"\n"))
        ->toBe(['echo "$($(printf x) -f y deploy)"', '$(printf x) -f y deploy', 'printf x']);

    // ── deployExtractCommandSubstitutions ──
    expect(deployExtractCommandSubstitutions('a "$(b c)" d'))->toBe(['b c']);
    expect(deployExtractCommandSubstitutions('a "$(b "$(c)")"'))->toBe(['b "$(c)"']);
    // 算術展開それ自体はコマンドではないので取り出さない
    expect(deployExtractCommandSubstitutions('A=$(( (RANDOM % 900) + 100 ))'))->toBe([]);
    // ただし **算術展開の内側の `$( )` は実行される**ので取り出す
    expect(deployExtractCommandSubstitutions('echo "$(( $(dep deploy) + 1 ))"'))->toBe(['dep deploy']);
    // 単一引用符の内側は展開されない
    expect(deployExtractCommandSubstitutions("echo '\$(dep deploy)'"))->toBe([]);

    // ── heredoc: quote 付きは inert、quote 無しは展開される ──
    // quote 付き (`<<'W'`) の本体は落とす
    expect($commandsOf("cat <<'W'\nusage: bash x\nW\n"))->toBe(['cat <<\'W\'']);
    // **quote 無し (`<<W`) の本体は落とさない** (本文の `$( )` が実行されるため)
    expect($commandsOf("cat <<W\n\$(dep deploy)\nW\n"))
        ->toBe(['cat <<W', '$(dep deploy)', 'dep deploy', 'W']);

    // 行内コメントは落とすが、引用符内の `#` と `${x#y}` は守る
    expect($commandsOf("dep deploy prd  # use -f deploy/deploy.php\n"))->toBe(['dep deploy prd']);
    expect($commandsOf("grep -vE '^[[:space:]]*#' \"\$1\"\n"))->toBe(["grep -vE '^[[:space:]]*#' \"\$1\""]);
    expect($commandsOf("echo \"\${x#y}\"\n"))->toBe(['echo "${x#y}"']);

    // **複数行文字列は 1 コマンド**として読む (行単位だと 2 行目以降が別コマンドに見え、
    // `\$EDITOR file` が「変数をコマンドとして実行」と誤検出される。実測した誤検知)
    expect($commandsOf("die \"line1\n  \\\$EDITOR file\n  end\"\n"))
        ->toBe(['die "line1 \$EDITOR file end"']);

    // 行番号は「そのコマンドが始まる行」
    expect(deployShellCommands("a\nb\n"))->toBe([
        ['line' => 1, 'segment' => 'a'],
        ['line' => 2, 'segment' => 'b'],
    ]);

    // ── 非起動台帳は **完全一致** (未知の shell 構文が原理的に例外へ入れないこと) ──
    // 台帳と一致する 3 つ (空白ゆれは正規化して吸収する)
    expect(deployWrapperLineIsNonInvocation('DEP_BIN="vendor/bin/dep"'))->toBeTrue();
    expect(deployWrapperLineIsNonInvocation('  if [[ ! -x "${DEP_BIN}" ]]  '))->toBeTrue();
    expect(deployWrapperLineIsNonInvocation(
        'die "${DEP_BIN} がありません。composer install を実行してください   (deployer/deployer は require-dev です)"'
    ))->toBeTrue();

    // ── quote 対応の語分割 (代入の値が複数語に割れないこと) ──
    expect(deployShellWords('REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"'))
        ->toBe(['REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"']);
    expect(deployShellWords('X=1 "${RUNNER}" deploy prd'))->toBe(['X=1', '"${RUNNER}"', 'deploy', 'prd']);
    // 算術展開と配列代入は 1 語として保つ (内側の空白で割ると `+` / `-f` がコマンド語に見える)
    expect(deployShellWords('A=$(( (RANDOM % 900) + 100 ))'))->toBe(['A=$(( (RANDOM % 900) + 100 ))']);
    expect(deployShellWords('DEP_ARGS+=(-o production_ack=1)'))->toBe(['DEP_ARGS+=(-o production_ack=1)']);

    // ── 実効コマンド語 (代入群 / ラッパを剥がす) ──
    expect(deployWrapperEffectiveCommandWord('git push origin x'))->toBe('git');
    expect(deployWrapperEffectiveCommandWord('X=1 "${RUNNER}" deploy'))->toBe('"${RUNNER}"');
    // ラッパは剥がさない (それ自体が禁止語なので追う必要が無い)
    expect(deployWrapperEffectiveCommandWord('command "${RUNNER}" deploy'))->toBe('command');
    expect(deployWrapperEffectiveCommandWord('env FOO=1 "${RUNNER}" deploy'))->toBe('env');
    expect(deployWrapperEffectiveCommandWord('sudo -u app "${RUNNER}" deploy'))->toBe('sudo');
    expect(deployWrapperEffectiveCommandWord('/bin/bash -c "dep deploy"'))->toBe('/bin/bash');
    // 代入だけの行は「実行」ではないので判定不能 (空)
    expect(deployWrapperEffectiveCommandWord('REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"'))->toBe('');

    // ── 間接実行の検出 (語を経由しない実行を許さない) ──
    foreach ([
        "\"\${DEP_CMD[@]}\"\n",                  // 配列展開をコマンド語に
        "\$CMD deploy prd\n",                    // 素の変数
        "eval \"\$CMD\"\n",                      // eval
        "bash -c \"dep deploy\"\n",              // シェルへの -c
        "echo x | xargs dep deploy\n",           // xargs
        "command \"\${RUNNER}\" deploy prd\n",   // command ラッパ
        "env FOO=1 \"\${RUNNER}\" deploy prd\n", // env ラッパ + 代入
        "X=1 \"\${RUNNER}\" deploy prd\n",       // env 前置
        "nohup nice \"\${RUNNER}\" deploy\n",    // 複数ラッパ
        "/bin/bash -c \"dep deploy\"\n",         // フルパスのシェル
        "exec \"\${RUNNER}\" deploy\n",          // exec
        "time \"\${RUNNER}\" deploy\n",          // time
        "`printf '%s%s' 'vendor/bin/' 'dep'` -f x deploy\n", // backtick でコマンド語を組み立てる
        "OUT=\"`date`\"\n",                      // backtick は用途を問わず禁止
        "source <(printf '%s' 'vendor/bin/de' 'p -f x deploy')\n", // 生成スクリプトを source
        ". <(printf '%s' 'x')\n",                // source の POSIX 表記
        "source ./generated.sh\n",               // source 自体を禁止語にしている
        "echo <(dep deploy)\n",                  // プロセス置換は用途を問わず禁止
    ] as $source) {
        expect(deployWrapperIndirectExecutions($source))->not->toBe(
            [],
            '間接実行を検出できていない: '.trim($source)
        );
    }

    // **env チャネル**: env 前置つき実行はコマンドを問わず禁止
    expect(deployWrapperIndirectExecutions("GIT_CONFIG_COUNT=1 GIT_CONFIG_KEY_0=alias.p git p\n"))
        ->not->toBe([]);
    expect(deployWrapperIndirectExecutions("GIT_SSH_COMMAND=/tmp/x git fetch origin\n"))->not->toBe([]);
    expect(deployWrapperIndirectExecutions("LD_PRELOAD=/tmp/x.so git status\n"))->not->toBe([]);
    expect(deployWrapperIndirectExecutions("PATH=/tmp git status\n"))->not->toBe([]);
    // 単独の代入は実行ではないので素通し
    expect(deployWrapperIndirectExecutions("HOST=\"\${arg}\"\n"))->toBe([]);
    expect(deployWrapperIndirectExecutions("DEP_ARGS+=(-o production_ack=1)\n"))->toBe([]);

    // git は **形を全数 pin**。台帳外の形はすべて違反 (alias 起動 / exec-path / 任意サブコマンド)
    expect(deployWrapperIndirectExecutions("git -c alias.p='!dep deploy' p\n"))->not->toBe([]);
    expect(deployWrapperIndirectExecutions("git --exec-path=/tmp status\n"))->not->toBe([]);
    expect(deployWrapperIndirectExecutions("git fetch --upload-pack=/tmp/x origin\n"))->not->toBe([]);
    expect(deployWrapperIndirectExecutions("git p\n"))->not->toBe([]);
    expect(deployWrapperIndirectExecutions("git config --local alias.p '!dep'\n"))->not->toBe([]);
    // repo の設定状態を書き換える経路も構文ごと禁止
    expect(deployWrapperIndirectExecutions("printf '[alias]' >> .git/config\n"))->not->toBe([]);
    // **字句 (連結 / エスケープ / $'…') で needle を割っても捕まる**
    foreach ([
        "printf '[remote]' >> '.g''it/config'",   // 隣接引用符連結
        'printf x >> .g\\it/config',              // バックスラッシュエスケープ
        "printf x >> .g\$'it/config'",            // ANSI-C 引用
    ] as $line) {
        expect(deployWrapperIndirectExecutions($line."\n"))->not->toBe(
            [],
            '字句で割った .git/ を検出できていない: '.$line
        );
    }

    expect(deployResolveShellConcatenation("'.g''it/config'"))->toBe('.git/config');
    expect(deployResolveShellConcatenation('.g\\it/config'))->toBe('.git/config');
    expect(deployResolveShellConcatenation(".g\$'it/config'"))->toBe('.git/config');
    expect(deployResolveShellConcatenation("vendor/bin/'de'p"))->toBe('vendor/bin/dep');
    expect(deployResolveShellConcatenation('vendor/bin/de\\p'))->toBe('vendor/bin/dep');
    // 変数展開は壊さない (`"${VAR}"` -> `${VAR}`)
    expect(deployResolveShellConcatenation('"${DEPLOY_FILE}"'))->toBe('${DEPLOY_FILE}');

    // dep の言及検出も字句を解いた形で行う
    expect(deployWrapperSegmentMentionsDep("vendor/bin/'de'p -f x deploy"))->toBeTrue();
    expect(deployWrapperSegmentMentionsDep('vendor/bin/de\\p -f x deploy'))->toBeTrue();
    // 台帳に載っている形は素通し (末尾のリダイレクトは無視して照合する)
    expect(deployWrapperIndirectExecutions("git status --porcelain\n"))->toBe([]);
    expect(deployWrapperIndirectExecutions("git status --short >&2\n"))->toBe([]);
    expect(deployWrapperIndirectExecutions("git fetch origin \"\${BRANCH}\" --quiet\n"))->toBe([]);
    expect(deployWrapperIndirectExecutions("if ! git merge-base --is-ancestor \"origin/\${BRANCH}\" HEAD\n"))
        ->toBe([]);

    // ── 先頭キーワードを剥がした実効コマンド語 (`if !` が git を隠さないこと) ──
    expect(deployWrapperEffectiveCommandWord('if ! git merge-base --is-ancestor a HEAD'))->toBe('git');
    expect(deployWrapperEffectiveCommandWord('if [[ -z "${HOST}" ]]'))->toBe('[[');
    expect(deployWrapperEffectiveCommandWord('{ usage'))->toBe('usage');
    expect(deployWrapperEffectiveCommandWord('then'))->toBe('then');

    // 代入は実行ではない / 通常のコマンドは素通し / run_dep 本体は正典形として許す
    expect(deployWrapperIndirectExecutions("DEP_CMD=(\"\${DEP_BIN}\" -f x)\n"))->toBe([]);
    expect(deployWrapperIndirectExecutions("git push origin \"\${BRANCH}\"\n"))->toBe([]);
    expect(deployWrapperIndirectExecutions("REPO_ROOT=\"\$(cd \"\$(dirname \"\$0\")/..\" && pwd)\"\n"))->toBe([]);
    expect(deployWrapperIndirectExecutions(DEPLOY_WRAPPER_RUN_DEP_BODY."\n"))->toBe([]);

    // 台帳に無いものはすべて起動候補になる。以下は過去の指摘で漏れていた形すべて:
    foreach ([
        'VAR=1 dep deploy prd',                              // env 前置つき起動
        'if vendor/bin/dep deploy prd',                       // if 直後
        '{ dep deploy prd',                                   // グループ
        '"${DEP_BIN}" deploy \'[[x]]\'',                      // 引数に [[ ]]
        'echo "$(dep deploy prd)"',                           // $( ) 置換
        'printf \'%s\' "$(vendor/bin/dep deploy prd)"',       // 同 (printf)
        'OUT="$(dep deploy prd)"',                            // 同 (代入)
        'echo "`dep deploy prd`"',                            // backtick 置換
        'if [[ "`dep deploy prd`" ]]',                        // 同 (条件式)
        'OUT="`vendor/bin/dep deploy prd`"',                  // 同 (代入)
        'echo <(dep deploy prd)',                             // プロセス置換
        'OUT=<(vendor/bin/dep deploy prd)',                   // 同 (代入)
        'if [[ -e <(dep deploy prd) ]]',                      // 同 (条件式)
        'echo "run vendor/bin/dep manually"',                 // 台帳に無いメッセージ
        'DEP_BIN="./vendor/bin/dep"',                         // 台帳と 1 文字違いの代入
    ] as $segment) {
        expect(deployWrapperLineIsNonInvocation($segment))->toBeFalse(
            "台帳外の segment が非起動と認められた: {$segment}"
        );
    }

    // ── 統合: 言及のある単純コマンドのうち -f を欠くものが offender になること ──
    $mixed = $wrapper
        ."if [[ -n \"\$x\" ]] && dep deploy prd; then\n"                   // 15: 混在 (test + 起動)
        ."echo start; dep deploy prd\n"                                    // 16: 混在 (echo + 起動)
        ."dep deploy prd  # use -f deploy/deploy.php\n"                    // 17: コメントで -f を偽装
        ."\"\${DEP_BIN}\" deploy '[[x]]'\n"                                // 18: 引数に [[ ]] を含む起動
        ."echo \"\$(dep deploy prd)\"\n"                                   // 19: echo 内のコマンド置換
        ."OUT=\"\$(vendor/bin/dep deploy prd)\"\n"                         // 20: 代入内のコマンド置換
        ."dep>/tmp/x deploy prd\n"                                         // 21: 実行体直後のリダイレクト
        ."vendor/bin/dep>/tmp/x deploy prd\n"                              // 22: 同 (フルパス)
        ."echo \"`dep deploy prd`\"\n"                                     // 23: backtick 置換 (echo)
        ."if [[ \"`dep deploy prd`\" ]]; then\n";                          // 24: backtick 置換 (条件式)

    $offenders = [];
    foreach (deployWrapperDepSegments($mixed) as $entry) {
        if (deployWrapperLineIsNonInvocation($entry['segment'])) {
            continue;
        }
        if (preg_match(DEPLOY_RE_DEP_CONFIG_FLAG, $entry['segment']) !== 1) {
            $offenders[] = $entry['line'];
        }
    }
    // 19 / 20 が 2 回出るのは `$( )` の **外側と内側の両方**が違反として数えられるため
    // (外側 `echo "$(dep …)"` は禁止構文ではないが内側の `dep …` は -f 無しの起動)。
    expect($offenders)->toBe([6, 7, 9, 11, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 20, 21, 22, 23, 24]);

    // 語の一部は言及として拾わない (終端を広げても誤検出しないこと)
    expect(deployWrapperSegmentMentionsDep('DEPLOY_FILE="deploy/deploy.php"'))->toBeFalse();
    expect(deployWrapperSegmentMentionsDep('run_dep deploy prd'))->toBeFalse();
    expect(deployWrapperSegmentMentionsDep('cat vendor/bin/deployer.md'))->toBeFalse();
    // リダイレクトが直接くっついた起動は拾う
    expect(deployWrapperSegmentMentionsDep('dep>/tmp/x deploy'))->toBeTrue();
    expect(deployWrapperSegmentMentionsDep('vendor/bin/dep>/tmp/x deploy'))->toBeTrue();

    // ── deployWrapperRunDepRange: 起動を run_dep 本体に結び付けるための範囲 ──
    $withRunDep = "DEP_BIN=x\n"
        ."run_dep() {\n"
        ."    \"\${DEP_BIN}\" -f \"\${DEPLOY_FILE}\" \"\$@\"\n"
        ."}\n"
        ."run_dep deploy prd\n";

    expect(deployWrapperRunDepRange($withRunDep))->toBe(['start' => 2, 'end' => 4]);
    expect(deployWrapperRunDepRange("echo no function here\n"))->toBeNull();

    // 非起動台帳の各 entry に reason があること (空の逃げ道を作らない)
    foreach (DEPLOY_WRAPPER_DEP_NON_INVOCATION as $reason) {
        expect(trim($reason))->not->toBe('');
    }

    // ── 静的 needle の正規表現: 等価表現を取りこぼさない ──
    foreach ([
        "require 'vendor/autoload.php';",
        'require "vendor/autoload.php";',
        "require_once 'vendor/autoload.php';",
        "require_once('vendor/autoload.php');",
        "include 'vendor/autoload.php';",
    ] as $variant) {
        expect($variant)->toMatch(DEPLOY_RE_AUTOLOAD_REQUIRE);
    }
    expect("require 'recipe/laravel.php';")->not->toMatch(DEPLOY_RE_AUTOLOAD_REQUIRE);

    foreach (['x || true', 'x ||true', 'x ||   true', 'x || /bin/true', 'x || /usr/bin/true', 'x || :'] as $variant) {
        expect($variant)->toMatch(DEPLOY_RE_SHELL_FAIL_OPEN);
    }
    expect('sudo systemctl reload php-fpm')->not->toMatch(DEPLOY_RE_SHELL_FAIL_OPEN);
    // `|| truthy` のような別語を誤検出しない
    expect('x || truename')->not->toMatch(DEPLOY_RE_SHELL_FAIL_OPEN);

    foreach ([
        "set('composer_options', 'x');",
        'set("composer_options", "x");',
        "set ( 'composer_options' , 'x');",
    ] as $variant) {
        expect($variant)->toMatch(DEPLOY_RE_COMPOSER_OPTIONS);
    }
    expect('// composer_options は変更しない')->not->toMatch(DEPLOY_RE_COMPOSER_OPTIONS);

    expect("set('supervisor_enabled', false);")->toMatch(deployRePhpSet('supervisor_enabled', 'false'));
    expect('set( "supervisor_enabled" , false );')->toMatch(deployRePhpSet('supervisor_enabled', 'false'));
    expect("set('supervisor_enabled', true);")->not->toMatch(deployRePhpSet('supervisor_enabled', 'false'));

    expect("after('deploy:failed', 'deploy:unlock');")->toMatch(deployReHook('after', 'deploy:failed', 'deploy:unlock'));
    expect('after( "deploy:failed" , "deploy:unlock" );')->toMatch(deployReHook('after', 'deploy:failed', 'deploy:unlock'));
    expect("before('deploy:failed', 'deploy:unlock');")->not->toMatch(deployReHook('after', 'deploy:failed', 'deploy:unlock'));

    // ── deployHygieneRootRequired ──
    $ledger = "        'deploy' => [\n"
        ."            'required' => true,\n"
        ."            'reason' => '',\n"
        ."        ],\n"
        ."        'scripts/deploy.sh' => [\n"
        ."            'required' => false,\n"
        ."            'reason' => 'later',\n"
        ."        ],\n";

    expect(deployHygieneRootRequired($ledger, 'deploy'))->toBeTrue();
    expect(deployHygieneRootRequired($ledger, 'scripts/deploy.sh'))->toBeFalse();
    expect(deployHygieneRootRequired($ledger, 'infra'))->toBeNull();
    // 隣の entry の値を読まないこと (窓の切り方の検証)
    expect(deployHygieneRootRequired("        'deploy' => [\n        ],\n        'x' => [\n            'required' => true,\n", 'deploy'))
        ->toBeNull();
});

// ───────────────────── W24-W25: 家系内の SoT 整合 ─────────────────────

test('W24: pnpm 版の SoT が package.json / mise.toml / ci.yml で一致する', function (): void {
    /** @var array{packageManager?: string} $package */
    $package = json_decode(deployPipelineRead('package.json'), true);
    $declared = $package['packageManager'] ?? '';
    expect($declared)->toMatch('/^pnpm@\d+\.\d+\.\d+$/');
    $version = substr($declared, strlen('pnpm@'));

    // mise.toml: "npm:pnpm" = "X"
    expect(preg_match('/^\s*"npm:pnpm"\s*=\s*"([^"]+)"/m', deployPipelineRead('mise.toml'), $mise))->toBe(1);
    expect($mise[1])->toBe(
        $version,
        'mise.toml の pnpm 版が package.json の packageManager と食い違っています '.
        '(runbook が「host の pnpm を packageManager に合わせよ」と指示するため SoT が割れてはならない)'
    );

    // ci.yml: pnpm/action-setup が version を **宣言していない**ことを要求する。
    //
    // **正典との差**: 正典 (テンプレート) は各 job が `with: version: X` を書くので
    // 「宣言された版が packageManager と一致する」ことを検査していた。aicue の CI は
    // version を書かず pnpm/action-setup に packageManager を読ませる形なので、
    // 一致検査は空振りする (抽出 0 件で `toBeGreaterThan(0)` が落ちる)。
    // **緩めるのではなく向きを変える**: 版を宣言していないこと自体が
    // 「SoT は packageManager 1 か所」という強い保証なので、そちらを pin する。
    // 将来 version を書く job を足すなら、その版が packageManager と一致することも要求する。
    $ci = deployPipelineRead('.github/workflows/ci.yml');
    $occurrences = substr_count($ci, 'pnpm/action-setup');
    expect($occurrences)->toBeGreaterThan(0, 'ci.yml に pnpm/action-setup が見つかりません (空振り)');

    $declared = preg_match_all('/pnpm\/action-setup@v\d+\s*\n\s*with:\s*\n\s*version:\s*(\S+)/', $ci, $found);

    $mismatched = [];
    foreach ($found[1] as $ciVersion) {
        if ($ciVersion !== $version) {
            $mismatched[] = $ciVersion;
        }
    }

    expect($mismatched)->toBe(
        [],
        'ci.yml の pnpm/action-setup が package.json の packageManager と違う版を宣言しています'
    );

    // 宣言 0 件 (= packageManager が唯一の SoT) か、全件一致のどちらかであること。
    // 「一部の job だけが版を宣言している」状態は SoT が割れる前段なので許さない。
    expect($declared === 0 || $declared === $occurrences)->toBeTrue(
        'ci.yml の pnpm/action-setup のうち一部だけが version を宣言しています '.
        "(宣言 {$declared} 件 / 出現 {$occurrences} 件)。全件書くか全件書かないかに揃えてください"
    );
});

test('W25: hygiene gate の scan root で deploy と scripts/deploy.sh が required=true である', function (): void {
    $source = deployPipelineRead('tests/Architecture/DeployCoordinateHygieneTest.php');
    expect($source)->not->toBe('', 'hygiene gate のソースを読めませんでした');

    foreach (['deploy', DEPLOY_WRAPPER] as $root) {
        expect(deployHygieneRootRequired($source, $root))->toBeTrue(
            "hygiene gate の scan root '{$root}' が required=true になっていません ".
            '(T029 で成果物を作ったので反転させる)'
        );
    }
});

// ───────────────── W26-W29: 本番ゲートの振る舞い (SSH しない) ─────────────────

/**
 * `deploy:confirm-stage` を example hosts に対して実行する。
 *
 * task body に `run()` が無いためリモート接続前にローカルで判定が終わる
 * (存在しない hostname でも即 fail / pass する。実測済み)。
 *
 * **テストは常に非 TTY で走る**ので、本番 host は必ず fail する。したがって
 * 「通る / 通らない」だけでなく **どの段で止まったか** (エラー識別子) を見る。
 * これが本番ゲートの段階を固定する唯一の手段である
 * (pty を用意して「本番 deploy が到達可能」を直接証明する形は移植性が無いため採らない)。
 *
 * @param  list<string>  $extra
 * @return array{exitCode: int, output: string}
 */
function deployConfirmStage(string $host, array $extra = []): array
{
    $args = array_merge(['-f', DEPLOY_CONFIG_FILE, 'deploy:confirm-stage', $host], $extra);
    $result = deployDepRun($args, ['DEPLOY_HOSTS_FILE' => DEPLOY_HOSTS_EXAMPLE]);

    return [
        'exitCode' => $result['exitCode'],
        'output' => $result['stdout'].$result['stderr'],
    ];
}

test('W26: 本番 host への ack 無し実行は ack 不足で fail する', function (): void {
    $result = deployConfirmStage('<APP>-prd');

    expect($result['exitCode'])->not->toBe(0);
    expect($result['output'])->toContain('PRODUCTION_ACK_MISSING');
});

test('W27: 本番 host は ack を受理した上で非 TTY として fail する', function (): void {
    // **ack は公開 option なのでそれ単体では人間の確認の証明にならない**。
    // ここで TTY 由来の識別子が出ることが「ack は通過し、残る条件は TTY だけ」の証明であり、
    // 同時に `dep deploy <prd> -o production_ack=1` 直叩き (agent / CI / pipe) が
    // 止まることの証明でもある。
    $result = deployConfirmStage('<APP>-prd', ['-o', 'production_ack=1']);

    expect($result['exitCode'])->not->toBe(0);
    expect($result['output'])->toContain('PRODUCTION_REQUIRES_TTY');
    expect($result['output'])->not->toContain('PRODUCTION_ACK_MISSING');
});

test('W28: 非本番 host への ack 付き実行は逆方向の不一致で fail する', function (): void {
    // host と意思表示の不一致はどちら向きも「間違えている」。
    $result = deployConfirmStage('<APP>-stg', ['-o', 'production_ack=1']);

    expect($result['exitCode'])->not->toBe(0);
    expect($result['output'])->toContain('PRODUCTION_ACK_ON_NON_PRODUCTION');
});

test('W29: 非本番 host への ack 無し実行は通る (非 TTY でも通ること = 到達可能性)', function (): void {
    // 非本番の deploy は agent / CI から実行できる。TTY 要求を本番に限定できている裏。
    expect(deployConfirmStage('<APP>-stg')['exitCode'])->toBe(0);
});

test('W31: 本番ゲートの 3 条件が deploy.php に揃っている', function (): void {
    // 振る舞い (W26-W29) が固定するのは「その識別子で止まる」ことなので、
    // 3 条件が **同じ task 内に揃っている**ことも静的に確認する (条件を消した改変の検出)。
    $source = deployPipelineReadPhpCode(DEPLOY_CONFIG_FILE);

    expect($source)->toContain('PRODUCTION_ACK_MISSING');
    expect($source)->toContain('PRODUCTION_ACK_ON_NON_PRODUCTION');
    expect($source)->toContain('PRODUCTION_REQUIRES_TTY');
    // TTY 判定は stream_isatty (core 関数)。ext-posix に依存しない。
    expect($source)->toMatch('/stream_isatty\s*\(\s*STDIN\s*\)/');
});

// ───────────────────────── W30: runbook の drift ─────────────────────────

test('W30: scheduler / queue worker の常駐要件が runbook に現れる', function (): void {
    $console = deployPipelineRead('routes/console.php');
    // runbook のパスが正典 (docs/production-deployment-runbook.md) と違うのは、
    // aicue の運用正本が docs/deployment-runbook.md である (production 環境をまだ持たない)。
    $runbook = deployPipelineRead('docs/deployment-runbook.md');

    $count = preg_match_all("/Schedule::command\(\s*'([^']+)'/", $console, $found);
    expect($count)->toBeGreaterThan(0, 'routes/console.php に Schedule::command がありません (空振り)');

    $missing = [];
    foreach ($found[1] as $command) {
        // 引数付きコマンドは **全文一致**を要求する (先頭トークンだけでは意図が落ちる)。
        if (! str_contains($runbook, (string) $command)) {
            $missing[] = $command;
        }
    }

    expect($missing)->toBe(
        [],
        'cron が止まると動かないコマンドが runbook に載っていません (常駐要件の可視化)'
    );

    // scheduler timer と queue worker の常駐記述そのもの。
    expect($runbook)->toContain('schedule:run');
    expect($runbook)->toContain('QUEUE_CONNECTION');
    // 正典は supervisor 前提なので `supervisor_enabled` を要求していた。
    // aicue は systemd なのでフラグ名がそのまま置き換わる (要求の意味は同じ =
    // 「worker 再起動の有効化フラグが runbook に説明されていること」)。
    expect($runbook)->toContain('queue_worker_restart_enabled');
});

test('W35: 起動キャッシュの再生成 (経路キャッシュを含む) が毎デプロイ走る', function (): void {
    // AGENTS.md の運用要件 (route:cache) は「経路キャッシュの再生成を守るのはデプロイ定義であり、
    // その正本は docs/deployment-runbook.md」と宣言している。**その宣言を機械側で受け止める**のが
    // 本ケースである (宣言だけだと、誰かが recipe 既定の deploy を自前の task 列へ置き換えて
    // 起動キャッシュ生成を落としても静かに通ってしまう)。
    //
    // Deployer 8 の laravel recipe は config / event / **経路** / view を artisan:optimize で
    // 一括生成する。古い cache を配ると保護が無音で外れる (AGENTS.md の該当節が実測を持つ) ので、
    // **release ごとに焼き直すこと**が不変条件である。
    $tree = deployTreeLines('deploy');

    expect(deployIndexOf($tree, 'artisan:optimize'))->toBeGreaterThan(
        -1,
        'artisan:optimize が deploy tree にありません。経路キャッシュが再生成されない配布は '.
        'AGENTS.md の運用要件 (route:cache) に違反します '.
        '(recipe 既定の deploy を自前の task 列へ置き換えた場合はここで落ちる)'
    );

    // 「消す側」だけが残って生成が消えた形を拒否する (optimize:clear は cache を捨てる task)。
    expect(deployIndexOf($tree, 'artisan:optimize:clear'))->toBe(
        -1,
        'deploy tree に artisan:optimize:clear があります (焼いた cache を捨てて配布することになる)'
    );

    // plan 表でも「1 host も対象にならない」形になっていないこと (死んだ selector を付けた場合)。
    $occurrences = 0;
    foreach (deployPlanTable()['rows'] as $cells) {
        foreach ($cells as $cell) {
            if ($cell === 'artisan:optimize') {
                $occurrences++;
            }
        }
    }
    expect($occurrences)->toBeGreaterThan(0, 'artisan:optimize が 0 host にマッチしています');
});

test('W34: 採らないと決めた正典 task が deploy/** に現れない (不在の申告)', function (): void {
    // 台帳の誠実性: 全 entry に「なぜ採らないか」が書かれていること。
    $noReason = [];
    foreach (DEPLOY_TASK_OMITTED as $task => $reason) {
        if (trim($reason) === '') {
            $noReason[] = $task;
        }
    }
    expect($noReason)->toBe([]);

    // band 台帳と omitted 台帳が同じ task を持たないこと (両立しない宣言を作らない)。
    expect(array_values(array_intersect(
        array_keys(DEPLOY_TASK_OMITTED),
        array_merge(array_keys(DEPLOY_TASK_BANDS), array_keys(DEPLOY_RECIPE_TASK_OVERRIDES))
    )))->toBe([]);

    // 実体の不在: 宣言も配線 (before/after の引数) も現れないこと。
    // 「採らない」と書いたまま黙って持ち込まれるのを防ぐ (持ち込むなら台帳から消して band を決める)。
    $found = [];
    foreach (deployPhpFiles() as $relative) {
        $code = deployPipelineReadPhpCode($relative);
        foreach (array_keys(DEPLOY_TASK_OMITTED) as $task) {
            if (str_contains($code, "'".$task."'") || str_contains($code, '"'.$task.'"')) {
                $found[] = $relative.': '.$task;
            }
        }
    }

    expect($found)->toBe(
        [],
        "採らないと申告した task が deploy/ に現れています:\n".implode("\n", $found)."\n".
        '採用するなら DEPLOY_TASK_OMITTED から行を消し、DEPLOY_TASK_BANDS に band を登録してください '.
        '(前提が満たされたことを同じ PR で示すこと)'
    );
});

test('W33: wrapper が実行するコマンド語が全数台帳の中に収まっている (allowlist)', function (): void {
    // **これが W22 群の最終防壁である**。dep の起動検出は構文の列挙に依存するので、
    // 「実行してよいコマンド語」を全数登録して台帳外を fail にすることで、
    // **どんな構文で書かれた新しい実行も必ず落ちる**ようにする (向きの反転)。
    $seen = [];
    foreach (deployShellCommands(deployPipelineRead(DEPLOY_WRAPPER)) as $entry) {
        $word = deployWrapperEffectiveCommandWord($entry['segment']);
        if ($word === '') {
            continue;
        }

        if (! array_key_exists($word, DEPLOY_WRAPPER_ALLOWED_COMMAND_WORDS)) {
            $seen[] = $entry['line'].': '.$word.'   ('.$entry['segment'].')';
        }
    }

    expect($seen)->toBe(
        [],
        "台帳外のコマンド語が scripts/deploy.sh に現れました。\n".
        "コマンドを増やすなら DEPLOY_WRAPPER_ALLOWED_COMMAND_WORDS に用途付きで登録してください:\n".
        implode("\n", $seen)
    );

    // 台帳の誠実性: 全 entry に用途が書かれていること (空の逃げ道を作らない)。
    $noReason = [];
    foreach (DEPLOY_WRAPPER_ALLOWED_COMMAND_WORDS as $word => $reason) {
        if (trim($reason) === '') {
            $noReason[] = $word;
        }
    }
    expect($noReason)->toBe([]);

    // 台帳に **禁止語が混ざっていない**こと (allowlist と denylist の矛盾を防ぐ)。
    $conflicts = array_values(array_intersect(
        array_keys(DEPLOY_WRAPPER_ALLOWED_COMMAND_WORDS),
        array_keys(DEPLOY_WRAPPER_FORBIDDEN_COMMAND_WORDS)
    ));
    expect($conflicts)->toBe([]);
});
