<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Webmozart\Assert\Assert;

/*
 * 常設 hook 配線の台帳 (deny-by-default) と、hook スクリプトの実挙動ゲート。
 *
 * 本テストは 2 層で構成する:
 *  - 静的層 (S01〜S13e): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
 *    台帳に無い hook・イベント・トップレベルキーはすべて違反 = 配線の正本が 1 か所になる。
 *    ローカル層 (`.claude/settings.local.json`) のトップレベルも全数申告制で、申告に `hooks` は
 *    足せない。内側の上限と配線の時間切れは**数値を両方から取って比較**する。
 *    S12c は S12b の走査域が空振りしていないことの検査である。
 *  - 実起動層 (B01〜B46): hook スクリプトと起動子を**別プロセスで本当に起動**して、
 *    終了コード・標準出力の空・告知の回数・排他・敵対的な検索パス・symlink の置き場での
 *    振る舞いを実証する。静的検査だけでは「書いてあるが効いていない」を検出できない。
 *
 * -------------------------------------------------------------------------
 * **配線層が塞がないもの** (家系の正典 t3 の i14。緑であることを実際より強く読ませないために書く):
 *  1. **起動子 `/bin/bash` 自体を差し替えられる攻撃者**。起動子を絶対パスで書くのは検索パス経由の
 *     すり替えを防ぐためで、`/bin/bash` そのものを置き換えられる相手には何も効かない
 *  2. **`$CLAUDE_PROJECT_DIR` を含む環境変数を仕込める攻撃者**。起動先のパスはこの変数から
 *     組まれる。t3 の起動子は値を検証しない (B45 がその挙動を実挙動で見えるようにしている)。
 *     `-p` が塞ぐのは継承したシェル関数と `BASH_ENV` / `ENV` **だけ**である
 *  3. **リポジトリの外に置かれた設定層**。hook の設定は利用者層・管理者層にも置け、管理者は
 *     プロジェクト層の hook をまとめて無効化できる。リポジトリ内の検査からは原理的に見えない
 *
 * **索引更新の配線が覆わない編集経路** (i15):
 *  `matcher` は `Write|Edit` なので、**シェル経由の変更 (Bash ツール) は索引更新を起こさない**。
 *  **条件を満たす変更は次の編集時に回収される**。条件は「**追跡下のパス**であり、作業ツリーの内容が
 *  `HEAD~1` と違うこと」で、これを満たす限りシェルで変えたファイルも次の `Write` / `Edit` が
 *  起こす更新でまとめて索引へ入る。その間だけ索引が古いことは受容する。
 *  **根拠は外部ツールの実装である** — `code-review-graph==2.3.7` (`docker/Dockerfile` が版を固定)
 *  の `update` は既定で `git diff --name-only HEAD~1 --`、つまり**1 つ前のコミットから
 *  作業ツリーまで**の差分を対象にする (実読記録:
 *  `devnotes/20260824-1014-claude-hooks-wiring-t3/code-review-graph-diff-premise.md`)。
 *  **回収されない経路が 2 系統ある (受容する)**:
 *   (1) **未追跡の新規ファイル**。`git diff` は未追跡ファイルを列挙しない。これは作った道具に依らず、
 *       `Write` で作った新規ファイルも `git add` されるまで同じである
 *       = **照合条件に `Bash` を足しても塞がらない** (穴は matcher の選択と直交する)
 *   (2) **差分基準から外れた過去のコミットの変更**。コミットしたあと `Write` / `Edit` を挟まずに
 *       さらにコミットを重ねると `HEAD~1` からの差分に現れない
 *  どちらも配線層では塞げない (`--base` を変える経路も `git add` を起こす経路も配線には無い)。
 *  **無条件の「回収される」とは書かない**。
 *  **本テストはこの前提を機械検証しない** (差分の基準・除外規則・索引状態の更新はツール側の実装)。
 *  したがって**索引ツールを更新したら、matcher の意味論と併せてこの差分回収の前提も
 *  人手で再確認する** (確認項目は上記の実読記録の 5 点)。
 *  **撤回規則**: (a) **上の 2 系統以外**で索引へ入らない実測が出た、(b) 索引ツールの版を上げて
 *  差分基準や未追跡ファイルの扱いが変わった、(c) 上の 2 系統が**実害**として観測された
 *  (索引が古いままコード探索が誤った結果を返した) — このいずれかが起きたら、
 *  **`matcher` へ `Bash` を足すのではなく**、家系の未決論点へ差し戻す
 *  (`Bash` の hook 入力には編集対象のパスが無く対象外拡張子での早期打ち切りが原理的に効かないため、
 *  最頻ツールの呼び出しごとに索引更新の実プロセスが起きる = 正典が費用構造で外している)。
 *  差し戻す先は「セッション開始時に索引状態を出す任意の配線」と「配線の非同期実行」の 2 案である。
 * -------------------------------------------------------------------------
 *
 * 本テストは DB を触らない (ファイル読み取りと別プロセス起動のみ)。
 * 関数名を `claudeHooks` 接頭辞で始めるのは、Pest が全テストを 1 プロセスへ読み込むため
 * 素の名前が他の Architecture テストと衝突するからである。
 */

/** 設定ファイルのトップレベルに置いてよいキー (全数申告制)。 */
const CLAUDE_HOOKS_TOP_LEVEL_KEYS = ['hooks'];

/**
 * 配線台帳。ここに書かれた形と `.claude/settings.json` が完全一致しなければ落ちる。
 *
 * `matcher` の `Write|Edit` は **`Write` と `Edit` のときだけ**発火する。
 * 部分一致で将来の派生ツールを自動で拾うとは書かない (書くと嘘になる)。
 *
 * **拒否コードは台帳に持たない** (家系の正典 t3 の i7)。起動子は終了コードを写像しないので、
 * hook が返した値がそのまま harness へ届く — `PreToolUse` の **2 だけがブロック**で、
 * それ以外の非 0 はブロックしない異常として面に出る。
 *
 * @var array<string, list<array{matcher: string, script: string, timeout: int}>>
 */
const CLAUDE_HOOKS_WIRING = [
    'PreToolUse' => [
        [
            'matcher' => 'Bash',
            'script' => 'scripts/bughunt-worktree-hook.sh',
            'timeout' => 10,
        ],
    ],
    'PostToolUse' => [
        [
            'matcher' => 'Write|Edit',
            'script' => 'scripts/code-review-graph-update-hook.sh',
            'timeout' => 30,
        ],
    ],
];

/** bug-hunt ガードが拒否を表す終了コード (harness の唯一の拒否信号)。 */
const CLAUDE_HOOKS_DENY_EXIT_CODE = 2;

/**
 * 内側の上限の申告 (家系の正典 t3 の i8)。値そのものはスクリプト本文から取り出すので
 * **ここには書かない** — 書くのは「どの数値を持つ契約か」だけである
 * (数値を 2 か所に書くと必ず食い違う)。
 *
 * `body` / `kill` が false なのは、そのスクリプトが外部プロセスを 1 つも起こさないため
 * (bug-hunt ガードの判定は bash の組み込みだけで完結する)。
 *
 * @var array<string, array{stdin: bool, body: bool, kill: bool}>
 */
const CLAUDE_HOOKS_INNER_LIMIT_SHAPE = [
    'scripts/bughunt-worktree-hook.sh' => ['stdin' => true, 'body' => false, 'kill' => false],
    'scripts/code-review-graph-update-hook.sh' => ['stdin' => true, 'body' => true, 'kill' => true],
];

/**
 * ローカル層 (`.claude/settings.local.json`) のトップレベルに置いてよい項目 (全数申告制)。
 *
 * **現在は空である** = ローカル層はどのトップレベル項目も持てない。常設配線をローカルから
 * 無効化する経路を作らないためで、hook を止める個別の設定項目 (`disableAllHooks` 等) を
 * 名指しで並べる形は採らない — 全数申告は**未知の項目も拒む**ので上位互換であり、
 * 正本を持たない外部の設定スキーマへ追随し続ける負債を作らない (家系の正典 t3 の i10)。
 *
 * 置きたい項目が出たら**ここを同じ変更で更新する**。ただし `hooks` は申告に足せない
 * (S07c が固定する)。
 *
 * @var list<string>
 */
const CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS = [];

/**
 * 索引の対象外拡張子。`scripts/code-review-graph-update-hook.sh` の `SKIP_EXTENSIONS` と
 * 完全一致すること (索引ツールを更新したらここも棚卸しする)。
 *
 * @var list<string>
 */
const CLAUDE_HOOKS_SKIP_EXTENSIONS = ['md', 'txt', 'json', 'yaml', 'yml', 'lock', 'log'];

/** 検索パス安全化ブロックの開始・終了マーカー (2 本の hook で byte 一致する)。 */
const CLAUDE_HOOKS_PROLOGUE_BEGIN = '# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---';
const CLAUDE_HOOKS_PROLOGUE_END = '# ---8< /SHARED_PATH_PROLOGUE >8---';

/**
 * S12b の走査対象 (実行面のファイルのみ)。文書は走査しない —
 * 禁止を説明する文章にコマンド名が出るのは正常であり、走査すると必ず落ちるためである。
 *
 * **glob ごとに「非空が契約か」を申告する** (S12c が使う)。S12b は「禁止語句が 1 件も無いこと」を
 * 見るので、glob が当たらなくなっても緑になる。union 全体の非空だけを見ると、
 * 代表を持たない glob の改名・綴り間違い・対象移動を取りこぼす。
 * 値は次の 2 通りで、**3 通り目を作らない**:
 *  - 代表ファイルのリポジトリ相対パス = その glob は非空が契約であり、この 1 本が母集団に居ること
 *  - `null` = **0 件でも正常**な glob (理由を下のコメントに書く)
 *
 * `scripts/{下位ディレクトリ}/*.sh` が `null` なのは、恒久スクリプトを下位ディレクトリへ
 * 置く運用が現在なく、置いたときに走査域へ入るための先回りの glob だからである
 * (0 件は違反ではない。ファイルが増えれば S12b はそのまま走査する)。
 * **件数そのものは pin しない** (スクリプトの増減は日常の変更である)。
 *
 * @var array<string, string|null>
 */
const CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS = [
    'scripts/*.sh' => 'scripts/bug-hunt-shard.sh',
    'scripts/*/*.sh' => null,
    '.claude/settings*.json' => '.claude/settings.json',
    'docker/Dockerfile' => 'docker/Dockerfile',
    'composer.json' => 'composer.json',
    'package.json' => 'package.json',
    '.github/workflows/*' => '.github/workflows/ci.yml',
];

// =============================================================================
// ヘルパ (静的層)
// =============================================================================

/** ファイルを読む (読めなければ明示 fail し string へ narrow する)。 */
function claudeHooksReadFile(string $path): string
{
    Assert::fileExists($path);
    $contents = file_get_contents($path);
    Assert::string($contents, "読み込めません: {$path}");

    return $contents;
}

/**
 * `.claude/settings.json` を配列として読む。
 *
 * @return array<string, mixed>
 */
function claudeHooksSettings(): array
{
    $decoded = json_decode(claudeHooksReadFile(base_path('.claude/settings.json')), true);
    Assert::isArray($decoded, '.claude/settings.json が JSON オブジェクトではない');

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * 起動子の正準形を台帳側で組み立てる (設定を書き換えたら必ずここと食い違って落ちる)。
 *
 * 起動子の仕事は**スクリプトを起こすこと 1 つだけ**である (家系の正典 t3 の i5 / i6 / i7):
 *  - `/bin/bash` の絶対パス (起動子自身が検索パスで解決される形を禁じる)
 *  - `-p` (特権モード。継承したシェル関数と `BASH_ENV` / `ENV` を無効化する)
 *  - `$CLAUDE_PROJECT_DIR` を根にした絶対パスでスクリプトを直に起動する
 * 引数・条件分岐・終了コードの写像・インラインのシェル片は 1 つも持たない。
 */
function claudeHooksExpectedCommand(string $script): string
{
    return '/bin/bash -p "$CLAUDE_PROJECT_DIR/'.$script.'"';
}

/**
 * 起動子の形の違反を列挙する (純関数。走査器)。
 *
 * **走査対象**: 設定ファイルから取り出した起動コマンド文字列 1 本と、台帳側が組み立てた文字列 1 本。
 * **判定**: 半角空白 1 文字を区切りとしてトークンへ割り、**トークンの完全一致**で見る
 * (部分文字列一致や正規表現の語境界に頼らない = AGENTS.md 静的検査の共通規約 (e))。
 * 期待するトークンは 3 個ちょうどで、順に `/bin/bash` / `-p` / `"$CLAUDE_PROJECT_DIR/<台帳のスクリプト>"`。
 *
 * **保証しないもの / fail-closed の倒し方**:
 *  - 区切りは**半角空白 1 文字だけ**である。タブ・改行・連続空白・引用符の内側の空白を含む形は
 *    「トークンへ割れない形」として**違反にする** (合格側へ倒さない)。したがって本走査は
 *    「引用の解釈が要る書き方」を許可しない = shell parser を持たない代わりに母集団を狭める
 *  - 起動先スクリプトの**中身**は見ない (隣接 feature の領分)。見るのは配線の文字列だけである
 *
 * @return list<string>
 */
function claudeHooksLauncherFormViolations(string $command, string $script): array
{
    $violations = [];

    // 解釈できない空白 (タブ・改行・連続空白) は割る前に落とす
    if (preg_match('/[\t\r\n]/', $command) === 1 || str_contains($command, '  ')) {
        $violations[] = "起動子をトークンへ割れない (タブ・改行・連続空白を含む): {$command}";

        return $violations;
    }

    $tokens = explode(' ', $command);
    $expected = ['/bin/bash', '-p', '"$CLAUDE_PROJECT_DIR/'.$script.'"'];

    if ($tokens !== $expected) {
        $violations[] = sprintf(
            '起動子が正準形でない (期待 %s / 実際 %s)',
            implode(' ', $expected),
            $command,
        );
    }

    // 「起動子が余計な仕事を持たない」ことを、正準形の一致とは**独立に**トークン語彙で見る。
    // 判定は別の純関数に置く (この分岐の検出力を単独で裏取りできるようにするため)。
    foreach (claudeHooksLauncherForbiddenTokens($command) as $forbidden) {
        $violations[] = "起動子が起動以外の仕事を持っている (禁止トークン {$forbidden}): {$command}";
    }

    return $violations;
}

/**
 * 起動子に現れてはならないトークンを列挙する (純関数。走査器)。
 *
 * **判定は半角空白で割ったトークンの完全一致**である (AGENTS.md 静的検査の共通規約 (e))。
 * 部分文字列一致にすると `xif` / `!if` / `ifx` のような無関係な語まで拾い、
 * 逆に許可語の除去を部分文字列で書くと本物の `if` まで消える。
 *
 * **区切りは半角空白 1 文字だけ**である。タブ・改行・連続空白を含む形は
 * 呼び出し側 (`claudeHooksLauncherFormViolations()`) が先に違反として落とす。
 *
 * @return list<string> 見つかった禁止トークン (出現順)
 */
function claudeHooksLauncherForbiddenTokens(string $command): array
{
    $vocabulary = ['-c', '&&', '||', ';', 'if', 'then', 'fi', 'exit', '[', 'eval', 'env', 'sh'];

    $found = [];
    foreach (explode(' ', $command) as $token) {
        if (in_array($token, $vocabulary, true)) {
            $found[] = $token;
        }
    }

    return $found;
}

/**
 * ローカル層の設定の違反を列挙する (純関数。走査器)。
 *
 * **走査対象**: `.claude/settings.local.json` の生バイト列。
 * **判定**: トップレベルの項目名の集合を申告と突き合わせる (値は見ない)。
 *
 * **fail-closed の 2 分類** (どちらも合格側へ倒さない):
 *  - JSON の構文が壊れている (`JsonException`)
 *  - 構文は正しいがトップレベルが JSON オブジェクトでない
 *
 * `json_decode(..., associative: true)` は使わない — 連想配列へ落とすと `{}` と `[]` が
 * どちらも `[]` になり、「オブジェクトでない」を検出できなくなる。
 *
 * @return list<string>
 */
function claudeHooksLocalSettingsViolations(string $json): array
{
    try {
        /** @var mixed $decoded */
        $decoded = json_decode(json: $json, associative: false, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return ['ローカル設定が JSON として壊れている: '.$exception->getMessage()];
    }

    if (! $decoded instanceof stdClass) {
        return ['ローカル設定のトップレベルが JSON オブジェクトでない'];
    }

    $keys = array_keys(get_object_vars($decoded));
    $violations = [];

    // `hooks` は申告の中身に関わらず必ず違反 (常設配線をローカルから殺す経路そのもの)
    if (in_array('hooks', $keys, true)) {
        $violations[] = 'ローカル設定に hooks がある (常設配線をローカルから無効化する経路を作らない)';
    }

    foreach (array_values(array_diff($keys, CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS, ['hooks'])) as $unexpected) {
        $violations[] = "ローカル設定に申告の無いトップレベル項目がある: {$unexpected}";
    }

    return $violations;
}

/**
 * 起動先が自分で諦める内側の上限を、スクリプト本文から**数値で**取り出す (純関数。走査器)。
 *
 * **走査対象**: 台帳の 2 スクリプトの本文。
 * **抽出する 3 値** (どれも**行全体の正準形**で当てる。行頭・行末を固定するのでコメント行
 * (`#` で始まる行) は候補にならない):
 *  - `stdin` … `IFS= read -r -N <bytes> -t <秒> input || true` の秒数 (標準入力を待つ上限)
 *  - `body`  … `readonly INNER_TIMEOUT_SECONDS=<秒>` (更新本体の上限)
 *  - `kill`  … `timeout -k <秒> "${INNER_TIMEOUT_SECONDS}" \` の猶予
 *              (TERM で終わらない相手を KILL するまで)
 *
 * **fail-closed** (見逃す方向へ倒さない = AGENTS.md 静的検査の共通規約 (b)):
 *  - 申告で必要な形は**ちょうど 1 件**であること。0 件 (数値以外・単位つき・変数展開・
 *    コメントアウト) と 2 件以上 (重複・囮の実行行) はどちらも違反にする
 *  - **候補走査 (`claudeHooksInnerLimitCandidateCounts()`) が申告対象と分類した行**のうち、
 *    正準形でないものが 1 件でも現れたら違反にする (候補の語彙と、その語彙が拾わない書き方 =
 *    絶対パス・別名・変数経由 は候補走査の docblock が正本)
 *  - 抽出できた値は**正の整数**であること (`0` は `timeout` の意味論を壊すので拒否する)
 *  - 台帳に無いスクリプトを渡されたら違反として返す (未知を黙って空で通さない)
 *
 * **保証しないもの**: 見るのは**行の形と数値だけ**であり、shell の制御フロー (その行が
 * 実際に実行されるか・別の待ちが挟まっているか) は見ない。したがって
 * 「実行時の上限を証明する」とは書けない — 主張できるのは
 * 「**明示された 3 つの上限の宣言**が配線の時間切れより小さい」までである。
 *
 * @return array{limits: array{stdin: ?int, body: ?int, kill: ?int}, violations: list<string>}
 */
function claudeHooksInnerLimits(string $body, string $script): array
{
    if (! array_key_exists($script, CLAUDE_HOOKS_INNER_LIMIT_SHAPE)) {
        return [
            'limits' => ['stdin' => null, 'body' => null, 'kill' => null],
            'violations' => ["{$script}: 内側の上限の申告が無い (台帳と申告を同じ変更で更新すること)"],
        ];
    }

    /** @var array{stdin: bool, body: bool, kill: bool} $shape */
    $shape = CLAUDE_HOOKS_INNER_LIMIT_SHAPE[$script];

    // 行全体の正準形。`^` と `$` を複数行モードで固定するので `# read …` は当たらない。
    // `kill` は**次の行の更新本体まで**含めて当てる (猶予が更新の起動に接続していることを見る)。
    $patterns = [
        'stdin' => '/^IFS= read -r -N \d+ -t (\d+) input \|\| true$/m',
        'body' => '/^readonly INNER_TIMEOUT_SECONDS=(\d+)$/m',
        // PHP の単一引用符では `\\\\` が正規表現の `\\` (= リテラルのバックスラッシュ 1 文字) になる
        'kill' => '/^timeout -k (\d+) "\$\{INNER_TIMEOUT_SECONDS\}" \\\\\n +code-review-graph update /m',
    ];

    $limits = ['stdin' => null, 'body' => null, 'kill' => null];
    $violations = [];
    $candidates = claudeHooksInnerLimitCandidateCounts($body);

    foreach ($patterns as $key => $pattern) {
        $count = preg_match_all($pattern, $body, $matches);
        Assert::integer($count, "{$script}: 内側の上限 [{$key}] の走査が失敗した");

        // **候補母集団と正準形の一致数が同じであること**。これが無いと、正準形の行 1 本と
        // 非正準の実行行 (別の変数で上限を渡す行など) が**併存**していても検出できない。
        if ($candidates[$key] !== $count) {
            $violations[] = "{$script}: 内側の上限 [{$key}] に正準形でない実行行がある"
                ." (候補 {$candidates[$key]} 件 / 正準形 {$count} 件)";

            continue;
        }

        if (! $shape[$key]) {
            if ($count > 0) {
                $violations[] = "{$script}: 申告に無い内側の上限 [{$key}] が {$count} 件現れた"
                    .' (申告を同じ変更で更新すること)';
            }

            continue;
        }
        if ($count !== 1) {
            $violations[] = "{$script}: 内側の上限 [{$key}] の宣言が 1 件でない (実測 {$count} 件)"
                .' — 数値として取り出せない形・重複・候補語彙に一致する囮の行は違反である';

            continue;
        }

        $value = (int) $matches[1][0];
        if ($value <= 0) {
            $violations[] = "{$script}: 内側の上限 [{$key}] が正の整数でない (実測 {$value})";

            continue;
        }

        $limits[$key] = $value;
    }

    return ['limits' => $limits, 'violations' => $violations];
}

/**
 * 内側の上限に関わる**候補行**の数を数える (純関数)。
 *
 * 正準形に一致する行だけを数えると「正準形 1 本 + 非正準の実行行」の併存を見逃す。
 * そこで**コメント行を除いた実行行**のうち、関連する語彙を持つ行を候補として別に数え、
 * 呼び出し側が「候補数 == 正準形の一致数」を要求する。
 *
 * **区切りの宣言**: 行は半角空白・タブで**トークン**へ割り、代入は最初の `=` で
 * 左辺と右辺へ割る。判定はトークンの**完全一致**である
 * (部分文字列一致に頼らない = AGENTS.md 静的検査の共通規約 (e))。候補の語彙は次の 3 つ:
 *  - `stdin` … トークンに `read` と `-t` の両方がある行
 *  - `body`  … 代入の左辺が `INNER_TIMEOUT_SECONDS` の行
 *  - `kill`  … トークンに `timeout` と `-k` の両方がある行
 *
 * **保証しないもの (誇張しない)**: 検出できるのは**宣言した語彙にトークン完全一致する
 * 非正準行の併存**だけである。同じ操作を別の書き方で行う行 — 絶対パス (`/usr/bin/timeout`)・
 * 別名・変数経由 (`"${TIMEOUT_BIN}"`) — は**候補にならないので併存を検出しない**。
 * 逆に `env timeout -k 2 …` は `timeout` と `-k` の両トークンを持つので**候補になる**
 * (行の先頭語だけを見る判定ではない)。
 * 語彙を増やして追いかけない (書き方の全数は列挙できない)。起動子の側で余計なトークンを
 * 禁じているのと違い、スクリプト本文は隣接 feature の領分なので、ここは
 * 「正準形の行が 1 本あること + 宣言した語彙の別行が無いこと」までを見る層である。
 *
 * @return array{stdin: int, body: int, kill: int}
 */
function claudeHooksInnerLimitCandidateCounts(string $body): array
{
    $counts = ['stdin' => 0, 'body' => 0, 'kill' => 0];

    foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue; // コメント行と空行は実行行ではない
        }

        $tokens = preg_split('/[ \t]+/', $trimmed) ?: [];
        if (in_array('read', $tokens, true) && in_array('-t', $tokens, true)) {
            $counts['stdin']++;
        }
        if (in_array('timeout', $tokens, true) && in_array('-k', $tokens, true)) {
            $counts['kill']++;
        }
        foreach ($tokens as $token) {
            if (str_contains($token, '=') && explode('=', $token, 2)[0] === 'INNER_TIMEOUT_SECONDS') {
                $counts['body']++;
            }
        }
    }

    return $counts;
}

/**
 * 合成した hook 本文 (S13b / S13d 用)。**基準は実ファイルと同じ正準形**で、
 * 各データセットは `str_replace()` で**1 か所だけ**変異させる
 * (複数箇所が同時に壊れていると、狙った分岐を消しても別の理由で赤いままになる)。
 *
 * nowdoc (`<<<'BASH'`) を使うのでバックスラッシュはそのまま 1 文字として入る
 * (二重引用符のエスケープの曖昧さを持ち込まない)。基準本文には**囮のコメント行**を
 * 1 本入れてあり、コメントが候補にならないことが同時に固定される。
 */
function claudeHooksSyntheticUpdateHookBody(string $mutate = '', string $replacement = ''): string
{
    $body = <<<'BASH'
        #!/usr/bin/env bash
        # 囮: IFS= read -r -N 1048576 -t 5 input || true
        IFS= read -r -N 1048576 -t 5 input || true
        readonly INNER_TIMEOUT_SECONDS=20
        timeout -k 2 "${INNER_TIMEOUT_SECONDS}" \
            code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
        BASH;

    if ($mutate === '') {
        return $body;
    }

    // **変異元は本文にちょうど 1 か所**であること。`str_replace()` は全出現を置換するので、
    // 存在検査だけだと「1 か所だけ変異させる」が壊れる (基準本文には囮のコメント行があり、
    // 実行行と同じ文字列を含む。stdin の変異元は先頭に改行を付けて一意にする)。
    Assert::same(
        substr_count($body, $mutate),
        1,
        "合成本文の変異元が 1 か所でない: {$mutate}",
    );

    return str_replace($mutate, $replacement, $body);
}

/**
 * 内側の上限と配線の時間切れの**関係**を判定する (純関数)。
 *
 * S13 (実ファイル) と S13c (変異させた入力) の**両方がこの関数を呼ぶ**。
 * 比較を検査の中に直接書くと、比較を消しても変異テストが緑のままになる。
 *
 * 判定するのは「**明示された 3 上限の宣言の和** < 配線の時間切れ」であり、
 * 前処理・プロセス起動の時間は含まない (含められないので主張もしない)。
 *
 * @param  array{stdin: ?int, body: ?int, kill: ?int}  $limits
 * @return list<string>
 */
function claudeHooksInnerLimitRelationViolations(array $limits, int $harness, string $label): array
{
    $declared = array_filter($limits, static fn (?int $value): bool => $value !== null);
    if ($declared === []) {
        return ["{$label}: 内側の上限が 1 つも取れていない (関係を判定できない)"];
    }

    $sum = array_sum($declared);
    if ($sum >= $harness) {
        return [sprintf(
            '%s: 明示された内側の上限の和 %d 秒が配線の時間切れ %d 秒より内側でない',
            $label,
            $sum,
            $harness,
        )];
    }

    return [];
}

/**
 * 検索パス安全化ブロックを取り出す。マーカーが 1 組でなければ違反として文字列を返す。
 *
 * shell parser は作らない。見るのは (1) マーカーが 1 組 (2) ブロックの byte 列
 * (3) 開始マーカーより前が shebang・コメント・空行だけ、の 3 点だけである。
 *
 * @return array{block: string, violations: list<string>}
 */
function claudeHooksPrologueBlock(string $contents, string $label): array
{
    $violations = [];

    $beginCount = substr_count($contents, CLAUDE_HOOKS_PROLOGUE_BEGIN);
    $endCount = substr_count($contents, CLAUDE_HOOKS_PROLOGUE_END);
    if ($beginCount !== 1 || $endCount !== 1) {
        return [
            'block' => '',
            'violations' => ["{$label}: 検索パス安全化ブロックのマーカーが 1 組でない (begin={$beginCount} end={$endCount})"],
        ];
    }

    $begin = strpos($contents, CLAUDE_HOOKS_PROLOGUE_BEGIN);
    $end = strpos($contents, CLAUDE_HOOKS_PROLOGUE_END);
    Assert::integer($begin);
    Assert::integer($end);
    if ($end < $begin) {
        return ['block' => '', 'violations' => ["{$label}: 終了マーカーが開始マーカーより前にある"]];
    }

    $block = substr($contents, $begin, $end - $begin + strlen(CLAUDE_HOOKS_PROLOGUE_END));

    // 開始マーカーより前は shebang・コメント・空行だけであること
    // (= 最初の外部コマンド呼び出しより前にプロローグがある、が自動的に成立する)
    foreach (preg_split('/\r\n|\r|\n/', substr($contents, 0, $begin)) ?: [] as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $violations[] = "{$label}: 検索パス安全化ブロックより前に実行される行がある (".($index + 1)." 行目: {$trimmed})";
    }

    return ['block' => $block, 'violations' => $violations];
}

// =============================================================================
// ヘルパ (実起動層)
// =============================================================================

/** 実起動層で必要な外部コマンドの絶対パスを解決する。 */
function claudeHooksResolveExecutable(string $name): string
{
    foreach (['/usr/local/bin/', '/usr/bin/', '/bin/'] as $dir) {
        if (is_executable($dir.$name)) {
            return $dir.$name;
        }
    }

    throw new RuntimeException("実起動層に必要な外部コマンドが見つかりません: {$name}");
}

/**
 * sandbox を作る。実スクリプトを `$sandbox/scripts/` へ複製するので、
 * `BASH_SOURCE` から解決されるリポジトリルートは sandbox 側になり本物を汚さない。
 *
 * 検索パスは**システムディレクトリを一切含めない**。必要な外部コマンド
 * (`mkdir` / `flock` / `timeout` / `sleep`) だけを sandbox の bin へ symlink するので、
 * 「索引ツールが未導入」を実行環境に左右されずに作れる。
 */
function claudeHooksSandbox(): string
{
    $sandbox = sys_get_temp_dir().'/claude-hooks-'.bin2hex(random_bytes(8));
    File::makeDirectory($sandbox.'/scripts', 0700, true);

    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            File::copy(base_path($entry['script']), $sandbox.'/'.$entry['script']);
        }
    }

    // 3 種類の bin を用意する (索引ツールの有無 / timeout の有無を作り分ける)
    foreach (['bin', 'bin-notool', 'bin-notimeout'] as $binDir) {
        File::makeDirectory($sandbox.'/'.$binDir, 0700, true);
        foreach (['mkdir', 'flock', 'sleep'] as $name) {
            symlink(claudeHooksResolveExecutable($name), $sandbox.'/'.$binDir.'/'.$name);
        }
    }
    foreach (['bin', 'bin-notool'] as $binDir) {
        symlink(claudeHooksResolveExecutable('timeout'), $sandbox.'/'.$binDir.'/timeout');
    }

    return $sandbox;
}

/** 索引ツールの stub を置く (起動された事実と引数を `invoked.txt` へ追記する)。 */
function claudeHooksInstallToolStub(string $sandbox, string $tail = "exit 0\n"): void
{
    foreach (['bin', 'bin-notimeout'] as $binDir) {
        $path = $sandbox.'/'.$binDir.'/code-review-graph';
        File::put($path, "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> '{$sandbox}/invoked.txt'\n".$tail);
        chmod($path, 0700);
    }
}

/** 索引ツールが解決できる検索パス。 */
function claudeHooksPathWithTool(string $sandbox): string
{
    return $sandbox.'/bin';
}

/** 索引ツールだけが解決できない検索パス (「未導入」の再現)。 */
function claudeHooksPathWithoutTool(string $sandbox): string
{
    return $sandbox.'/bin-notool';
}

/** `timeout` だけが解決できない検索パス。 */
function claudeHooksPathWithoutTimeout(string $sandbox): string
{
    return $sandbox.'/bin-notimeout';
}

/**
 * 別プロセスで走らせて結果をそろえて返す。
 *
 * @param  list<string>  $command
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRun(array $command, string $input = '', ?string $cwd = null, int $timeout = 90): array
{
    $pending = Process::timeout($timeout)->input($input);
    if ($cwd !== null) {
        $pending = $pending->path($cwd);
    }

    $startedAt = microtime(true);
    $result = $pending->run($command);
    $elapsed = microtime(true) - $startedAt;

    $exitCode = $result->exitCode();
    Assert::integer($exitCode, '子プロセスの終了コードが取れない');

    return [
        'exitCode' => $exitCode,
        'output' => $result->output(),
        'errorOutput' => $result->errorOutput(),
        'elapsed' => $elapsed,
    ];
}

/**
 * 索引更新 hook を sandbox 内で起動する (環境は `env -i` で完全に作り直す)。
 *
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRunUpdateHook(string $sandbox, string $input, ?string $path = null, ?string $cwd = null): array
{
    return claudeHooksRun([
        '/usr/bin/env', '-i', 'PATH='.($path ?? claudeHooksPathWithTool($sandbox)),
        '/bin/bash', $sandbox.'/scripts/code-review-graph-update-hook.sh',
    ], $input, $cwd);
}

/**
 * bug-hunt ガードを sandbox 内で起動する。
 *
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRunBughuntHook(string $sandbox, string $input, ?string $path = null, ?string $cwd = null): array
{
    return claudeHooksRun([
        '/usr/bin/env', '-i', 'PATH='.($path ?? claudeHooksPathWithTool($sandbox)),
        '/bin/bash', $sandbox.'/scripts/bughunt-worktree-hook.sh',
    ], $input, $cwd);
}

/** 索引ツールの stub が起動された回数。 */
function claudeHooksInvocations(string $sandbox): int
{
    if (! is_file($sandbox.'/invoked.txt')) {
        return 0;
    }

    return count(array_filter(explode("\n", claudeHooksReadFile($sandbox.'/invoked.txt'))));
}

/** 告知の行数 (標準エラーの非空行)。 */
function claudeHooksWarningLines(string $stderr): int
{
    return count(array_filter(array_map(trim(...), explode("\n", $stderr)), fn (string $l): bool => $l !== ''));
}

/** PostToolUse の入力 payload。 */
function claudeHooksWritePayload(string $filePath, string $sessionId = 'sess-a'): string
{
    return json_encode([
        'session_id' => $sessionId,
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => $filePath],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * PreToolUse の入力 payload。
 *
 * `$escapeSlashes` を真にすると `/` を `\/` へ逃がす (JSON として正当な別表記)。
 * 許可シグナルの照合がこの表記でも取りこぼさないことを実証するために使う。
 */
function claudeHooksBashPayload(string $command, string $description = 'x', bool $escapeSlashes = false): string
{
    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
    if (! $escapeSlashes) {
        $flags |= JSON_UNESCAPED_SLASHES;
    }

    return json_encode([
        'session_id' => 'sess-a',
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command, 'description' => $description],
    ], $flags);
}

/**
 * 「含むこと」を理由付きで検査する。
 *
 * Pest の `toContain()` は可変長引数なので、第 2 引数を理由として渡すと
 * **もう 1 つの検索語**として扱われて必ず落ちる。理由を残したい箇所はこちらを使う。
 */
function claudeHooksExpectContains(string $haystack, string $needle, string $reason): void
{
    expect(str_contains($haystack, $needle))->toBeTrue("{$reason} (期待する文字列: {$needle})");
}

/** 「含まないこと」を理由付きで検査する。 */
function claudeHooksExpectNotContains(string $haystack, string $needle, string $reason): void
{
    expect(str_contains($haystack, $needle))->toBeFalse("{$reason} (現れてはならない文字列: {$needle})");
}

/**
 * S12b の走査域 (リポジトリ相対パスの昇順)。
 *
 * @param  string|null  $root  走査根の絶対パス (null = リポジトリルート)。
 *                             負のコントロールで別の根を渡すために引数化してある
 * @return list<string>
 */
function claudeHooksSelfWiringScanFiles(?string $root = null): array
{
    $files = [];
    foreach (array_keys(CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS) as $glob) {
        $files = [...$files, ...claudeHooksSelfWiringGlobFiles($glob, $root)];
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

/**
 * glob 1 本が当てるファイル (リポジトリ相対パス)。
 *
 * @param  string|null  $root  走査根の絶対パス (null = リポジトリルート)
 * @return list<string>
 */
function claudeHooksSelfWiringGlobFiles(string $glob, ?string $root = null): array
{
    $root = rtrim($root ?? base_path(), '/');

    $files = [];
    foreach (glob($root.'/'.$glob) ?: [] as $path) {
        if (is_file($path)) {
            $files[] = ltrim(str_replace($root, '', $path), '/');
        }
    }
    sort($files);

    return $files;
}

/** 台帳から起動子の実文字列を取り出す (台帳の写しではなく本物を走らせるため)。 */
function claudeHooksLauncherCommand(string $event): string
{
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);
    Assert::keyExists($settings['hooks'], $event);
    $group = $settings['hooks'][$event];
    Assert::isArray($group);
    Assert::isArray($group[0]);
    Assert::isArray($group[0]['hooks']);
    Assert::isArray($group[0]['hooks'][0]);
    $command = $group[0]['hooks'][0]['command'];
    Assert::string($command);

    return $command;
}

/** 設定ファイルから hook の時間切れを取り出す。 */
function claudeHooksHookTimeout(string $event): int
{
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);
    Assert::keyExists($settings['hooks'], $event);
    $group = $settings['hooks'][$event];
    Assert::isArray($group);
    Assert::isArray($group[0]);
    Assert::isArray($group[0]['hooks']);
    Assert::isArray($group[0]['hooks'][0]);
    $timeout = $group[0]['hooks'][0]['timeout'];
    Assert::integer($timeout);

    return $timeout;
}

/**
 * 起動子そのものを走らせる。`CLAUDE_PROJECT_DIR` を渡さないときは環境から消える。
 *
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRunLauncher(string $command, ?string $projectDir, ?string $cwd = null, string $input = ''): array
{
    $env = ['/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin'];
    if ($projectDir !== null) {
        $env[] = 'CLAUDE_PROJECT_DIR='.$projectDir;
    }

    return claudeHooksRun([...$env, '/bin/bash', '-c', $command], $input, $cwd);
}

/** 起動子の内側に置く「終了コードだけを返す」スクリプト。 */
function claudeHooksWriteExitStub(string $path, int $exitCode): void
{
    File::put($path, "#!/bin/bash\nexit {$exitCode}\n");
    chmod($path, 0700);
}

// =============================================================================
// 静的層
// =============================================================================

test('S01: .claude/settings.json が実在し有効な JSON であること', function (): void {
    expect(claudeHooksSettings())->toBeArray();
});

test('S02: .claude/settings.json が git 追跡下にあること (各自任せの見本方式へ戻さない)', function (): void {
    $result = Process::path(base_path())->timeout(30)
        ->run(['git', 'ls-files', '--error-unmatch', '.claude/settings.json']);

    expect($result->exitCode())->toBe(0, '.claude/settings.json が git 追跡下にない');
});

test('S03: トップレベルキーが台帳と完全一致すること (全数申告制)', function (): void {
    $keys = array_keys(claudeHooksSettings());
    sort($keys);
    $expected = CLAUDE_HOOKS_TOP_LEVEL_KEYS;
    sort($expected);

    expect($keys)->toBe($expected, '台帳に無いトップレベルキーは既定拒否 (台帳を同じ変更で更新すること)');
});

test('S04: hooks のイベント集合が台帳と完全一致すること', function (): void {
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);
    $events = array_keys($settings['hooks']);
    sort($events);
    $expected = array_keys(CLAUDE_HOOKS_WIRING);
    sort($expected);

    expect($events)->toBe($expected);
});

test('S05/S06: 各 hook の matcher / 起動文字列 / timeout が台帳と完全一致すること', function (): void {
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        $group = $settings['hooks'][$event];
        Assert::isArray($group);
        expect($group)->toHaveCount(count($entries), "{$event} の登録数が台帳と違う");

        foreach ($entries as $index => $entry) {
            $matcherGroup = $group[$index];
            Assert::isArray($matcherGroup);
            expect(array_keys($matcherGroup))->toBe(['matcher', 'hooks']);
            expect($matcherGroup['matcher'])->toBe($entry['matcher']);

            Assert::isArray($matcherGroup['hooks']);
            expect($matcherGroup['hooks'])->toHaveCount(1);
            $hook = $matcherGroup['hooks'][0];
            Assert::isArray($hook);
            expect(array_keys($hook))->toBe(['type', 'command', 'timeout']);
            expect($hook['type'])->toBe('command');
            expect($hook['timeout'])->toBe($entry['timeout']);
            expect($hook['command'])->toBe(
                claudeHooksExpectedCommand($entry['script']),
                "{$event} の起動文字列が台帳と 1 文字でも違う",
            );
        }
    }
});

test('S06b: 起動子が直呼び + privileged mode で、起動以外の仕事を 1 つも持たないこと', function (): void {
    // 設定の実文字列と台帳側の組み立ての**両方**を同じ述語に通す
    // (片方だけだと「台帳を緩めた」か「設定を緩めた」かのどちらかを取り逃がす)。
    $checked = 0;

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        foreach ($entries as $entry) {
            foreach ([
                '設定ファイル' => claudeHooksLauncherCommand($event),
                '台帳の組み立て' => claudeHooksExpectedCommand($entry['script']),
            ] as $source => $command) {
                $violations = claudeHooksLauncherFormViolations($command, $entry['script']);
                expect($violations)->toBe([], "{$event} ({$source}):\n".implode("\n", $violations));
            }
            $checked++;
        }
    }

    // 母集団が空でないこと (走査根の改名・台帳の空振りで緑にならないように)
    expect($checked)->toBe(2, '必須 2 配線を検査していない (i2)');
});

test('S06c (負のコントロール): 起動子の形の走査が違反を実際に検出すること', function (string $command): void {
    expect(claudeHooksLauncherFormViolations($command, 'scripts/x.sh'))->not->toBe([]);
})->with([
    'インライン形 (-c)' => ['/bin/bash -p -c \'d=${CLAUDE_PROJECT_DIR:-}; exit 0\''],
    '追加引数' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh" --verbose'],
    '条件分岐' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"; if [ "$s" = 97 ]; then exit 2; fi'],
    'インラインのシェル片' => ['/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh" && printf done'],
    '起動子が検索パス解決' => ['bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"'],
    '特権モードが無い' => ['/bin/bash "$CLAUDE_PROJECT_DIR/scripts/x.sh"'],
    '相対パス' => ['/bin/bash -p "scripts/x.sh"'],
    'タブ区切り (解釈できない形)' => ["/bin/bash -p\t\"\$CLAUDE_PROJECT_DIR/scripts/x.sh\""],
]);

test('S06d (正のコントロール): 正典どおりの形は違反ゼロであること', function (): void {
    expect(claudeHooksLauncherFormViolations('/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"', 'scripts/x.sh'))
        ->toBe([]);
    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$CLAUDE_PROJECT_DIR/scripts/x.sh"'))->toBe([]);
});

test('S06e (語彙判定の裏取り): 禁止トークンの検出が単独で効いていること', function (): void {
    // S06c の負例はすべて「正準形でない」だけでも赤になるので、語彙判定の分岐は
    // **単独で**裏取りする (この検査があるので `claudeHooksLauncherForbiddenTokens()` を
    // 空実装にすると赤になる)。
    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p -c \'exit 0\''))->toBe(['-c']);
    expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$d/x.sh" ; if [ 1 ] ; then exit 2 ; fi'))
        ->toBe([';', 'if', '[', ';', 'then', 'exit', ';', 'fi']);

    // 区切りで割ったトークンの完全一致であること (接頭辞・打ち消し・接尾辞の 3 形は拾わない)
    foreach (['xif', '!if', 'ifx', 'exits', '-cx', 'ifexit'] as $lookalike) {
        expect(claudeHooksLauncherForbiddenTokens('/bin/bash -p "$d/x.sh" '.$lookalike))
            ->toBe([], "トークン完全一致でない判定になっている: {$lookalike}");
    }
});

test('S07: .claude/settings.local.json のトップレベルが全数申告どおりであること (i10)', function (): void {
    $path = base_path('.claude/settings.local.json');

    if (! is_file($path)) {
        // ファイルが無い = 上書きする経路が無い。**空振りではない**ことを明示する
        // (「存在するときは全キーを照合する」ことは S07b が合成入力で固定する)
        expect(CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS)->toBe([], 'ローカル層に置ける項目の申告が空でない');

        return;
    }

    $violations = claudeHooksLocalSettingsViolations(claudeHooksReadFile($path));
    expect($violations)->toBe([], implode("\n", $violations));
});

test('S07b (負のコントロール): ローカル層の走査が違反を実際に検出すること', function (string $json): void {
    expect(claudeHooksLocalSettingsViolations($json))->not->toBe([]);
})->with([
    'hooks を持つ' => ['{"hooks": {}}'],
    '申告に無い項目を持つ' => ['{"permissions": {"allow": []}}'],
    'トップレベルがオブジェクトでない' => ['[]'],
    'JSON の構文が壊れている' => ['{'],
]);

test('S07c: 空のオブジェクトは合格し、申告に hooks を足せないこと', function (): void {
    expect(claudeHooksLocalSettingsViolations('{}'))->toBe([]);
    expect(in_array('hooks', CLAUDE_HOOKS_LOCAL_TOP_LEVEL_KEYS, true))
        ->toBeFalse('申告に hooks を足してはならない (i10)');
});

test('S08: 見本ファイル方式が復活していないこと', function (): void {
    expect(is_file(base_path('.claude/settings.bughunt-hook.example.json')))
        ->toBeFalse('オプトインの見本ファイルは常設配線と並走させない (後方互換の並走を残さない)');
});

test('S09: 台帳の 2 スクリプトが実在し bash -n を通ること', function (): void {
    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            $path = base_path($entry['script']);
            expect(is_file($path))->toBeTrue("{$entry['script']} が無い");

            $result = Process::timeout(30)->run(['bash', '-n', $path]);
            expect($result->exitCode())->toBe(0, "{$entry['script']} が bash -n を通らない:\n".$result->errorOutput());
        }
    }
});

test('S10: 2 本の検索パス安全化ブロックが byte 一致し、どちらもファイル先頭にあること', function (): void {
    $blocks = [];
    $violations = [];

    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            $extracted = claudeHooksPrologueBlock(claudeHooksReadFile(base_path($entry['script'])), $entry['script']);
            $blocks[$entry['script']] = $extracted['block'];
            $violations = [...$violations, ...$extracted['violations']];
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
    expect(count(array_unique($blocks)))->toBe(1, '2 本の検索パス安全化ブロックが byte 一致していない');
    $block = reset($blocks);
    Assert::string($block);
    claudeHooksExpectContains($block, '_hook_sanitize_path', '安全化の実体がブロックに無い');
});

test('S11: 索引の対象外拡張子が台帳と完全一致すること', function (): void {
    $contents = claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh'));

    expect(preg_match("/^readonly SKIP_EXTENSIONS='([^']*)'$/m", $contents, $matches))
        ->toBe(1, 'SKIP_EXTENSIONS の宣言が見つからない');

    expect(preg_split('/\s+/', trim($matches[1])))->toBe(
        CLAUDE_HOOKS_SKIP_EXTENSIONS,
        '対象外拡張子が台帳と食い違う (索引ツールを更新したら両方を同じ変更で棚卸しすること)',
    );
});

test('S12a: 索引ツール自身に配線を書かせない明文が AGENTS.md にマーカー付きで存在すること', function (): void {
    $agents = claudeHooksReadFile(base_path('AGENTS.md'));

    expect($agents)->toContain('<!-- CLAUDE_HOOKS_WIRING:BEGIN -->');
    expect($agents)->toContain('<!-- CLAUDE_HOOKS_WIRING:END -->');

    $begin = strpos($agents, '<!-- CLAUDE_HOOKS_WIRING:BEGIN -->');
    $end = strpos($agents, '<!-- CLAUDE_HOOKS_WIRING:END -->');
    Assert::integer($begin);
    Assert::integer($end);
    $section = substr($agents, $begin, $end - $begin);

    foreach (['code-review-graph install', 'uninstall', '.claude/settings.json'] as $needle) {
        claudeHooksExpectContains($section, $needle, '常設 hook 配線の節に必要な記述が無い');
    }
    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            claudeHooksExpectContains($section, $entry['script'], '常設 hook 配線の節に hook の行が無い');
        }
    }
});

test('S12b: 実行面のファイルが索引ツールに配線を書かせる呼び出しを持たないこと', function (): void {
    $violations = [];

    foreach (claudeHooksSelfWiringScanFiles() as $relative) {
        if (preg_match('/code-review-graph\s+(install|init|uninstall)\b/', claudeHooksReadFile(base_path($relative))) === 1) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], "配線の正本が二重化する呼び出しがある:\n".implode("\n", $violations));
});

test('S12c (空振り検査): S12b の走査域が glob ごとの申告どおりであること', function (): void {
    $files = claudeHooksSelfWiringScanFiles();

    // 非空: glob がすべて外れても S12b は違反ゼロで緑になる
    expect($files)->not->toBe([], 'S12b の走査域が空です (glob が 1 つも当たっていません)');

    // glob ごと: 非空が契約の glob は代表ファイルを当てていること。
    // union 全体だけを見ると、代表を持たない glob が 1 本壊れても他が非空なら緑のままになる。
    foreach (CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS as $glob => $representative) {
        $matched = claudeHooksSelfWiringGlobFiles($glob);

        if ($representative === null) {
            continue; // 当たらないことが正常な glob (理由は台帳の docblock)
        }

        // `toContain()` は可変長引数なので理由は第 2 引数に渡せない (冒頭のヘルパと同じ理由)
        expect(in_array($representative, $matched, true))
            ->toBeTrue("glob [{$glob}] が代表ファイル {$representative} を当てていません");
    }
});

test('S12c の負のコントロール: 走査根を差し替えると走査域が空になる', function (): void {
    // 上の検査が空振りしていないことの裏取り。実在しない根を渡すと 0 件になる。
    expect(claudeHooksSelfWiringScanFiles(base_path('nonexistent-scan-root')))->toBe([]);
    foreach (array_keys(CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS) as $glob) {
        expect(claudeHooksSelfWiringGlobFiles($glob, base_path('nonexistent-scan-root')))->toBe([]);
    }
});

test('S13: 明示された内側の上限の和が配線の時間切れより小さいこと (数値を両方から取って比較する)', function (): void {
    // 申告の母集団が台帳とちょうど一致すること (申告の余剰・不足を黙って通さない)
    $ledgerScripts = [];
    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            $ledgerScripts[] = $entry['script'];
        }
    }
    sort($ledgerScripts);
    $declaredScripts = array_keys(CLAUDE_HOOKS_INNER_LIMIT_SHAPE);
    sort($declaredScripts);
    expect($declaredScripts)->toBe($ledgerScripts, '内側の上限の申告が台帳のスクリプト集合と一致しない');

    $checked = 0;

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        foreach ($entries as $entry) {
            $extracted = claudeHooksInnerLimits(claudeHooksReadFile(base_path($entry['script'])), $entry['script']);
            expect($extracted['violations'])->toBe([], implode("\n", $extracted['violations']));

            // 設定ファイル側の timeout も**設定から**取る (台帳の写しではなく実値を見る)
            $harness = claudeHooksHookTimeout($event);
            expect($harness)->toBe($entry['timeout'], "{$event}: 設定の timeout が台帳と違う");

            // 関係の判定は純関数へ (S13c が同じ関数を呼ぶ = **共通関数の中の**比較を
            // 消したり向きを逆にしたら負例が赤くなる)
            $violations = claudeHooksInnerLimitRelationViolations($extracted['limits'], $harness, $event);
            expect($violations)->toBe([], implode("\n", $violations));
            $checked++;
        }
    }

    expect($checked)->toBe(2, '必須 2 配線を検査していない (i2)');
});

test('S13b (負のコントロール): 内側の上限の走査が違反を実際に検出すること', function (string $body, string $script): void {
    // **基準の合成本文から 1 か所だけ変異させる** (複数箇所が同時に壊れていると、
    // 狙った分岐を消しても別の理由で赤いままになり、分岐の裏取りにならない)。
    $extracted = claudeHooksInnerLimits($body, $script);
    expect($extracted['violations'])->not->toBe([]);
})->with([
    '必要な正準形が 0 件 (変数展開)' => [
        claudeHooksSyntheticUpdateHookBody(
            'readonly INNER_TIMEOUT_SECONDS=20',
            'readonly INNER_TIMEOUT_SECONDS=$FOO',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '必要な正準形が 2 件 (重複宣言)' => [
        claudeHooksSyntheticUpdateHookBody(
            'readonly INNER_TIMEOUT_SECONDS=20',
            "readonly INNER_TIMEOUT_SECONDS=20\nreadonly INNER_TIMEOUT_SECONDS=99",
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '正準形と非正準の実行行が併存する' => [
        claudeHooksSyntheticUpdateHookBody(
            'code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1',
            "code-review-graph update -q --repo \"\${repo_root}\" > /dev/null 2>&1\n"
                .'timeout -k "${OTHER}" 99 code-review-graph update -q',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '標準入力待ちが数値でない' => [
        claudeHooksSyntheticUpdateHookBody(
            // 先頭の改行で**実行行だけ**に一意化する (囮のコメント行は `# ` が前に付くので当たらない)
            "\nIFS= read -r -N 1048576 -t 5 input || true",
            "\nIFS= read -r -N 1048576 -t \"\${UNBOUNDED}\" input || true",
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '値が 0 (timeout の意味論が壊れる)' => [
        claudeHooksSyntheticUpdateHookBody('timeout -k 2 ', 'timeout -k 0 '),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '猶予が更新本体へ接続していない' => [
        claudeHooksSyntheticUpdateHookBody(
            'code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1',
            '    true',
        ),
        'scripts/code-review-graph-update-hook.sh',
    ],
    '申告に無い上限が現れた (検問側に本体の宣言がある)' => [
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/bughunt-worktree-hook.sh',
    ],
    '台帳に無いスクリプト' => [
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/unknown-hook.sh',
    ],
]);

test('S13c (負のコントロール): 関係の判定が崩れた数値を落とすこと', function (?int $stdin, ?int $body, ?int $kill, int $harness, bool $shouldFail): void {
    // **S13 と同じ関数**を呼ぶので、**共通関数の中の**比較を消したり向きを逆にしたらここが赤くなる
    // (S13 から呼び出しごと削除された場合はここでは分からない — それは S13 の本文を読むレビューの担当)。
    // dataset を `?int` の 3 引数に分けるのは、closure の `array` に要素型を書けないためである
    // (PHPStan level 10 は iterable value type の欠落を落とす)。
    $violations = claudeHooksInnerLimitRelationViolations(
        ['stdin' => $stdin, 'body' => $body, 'kill' => $kill],
        $harness,
        'テスト入力',
    );

    expect($violations === [])->toBe(! $shouldFail);
})->with([
    '索引更新の現行値 (27 < 30)' => [5, 20, 2, 30, false],
    '等しい (30 は内側でない)' => [5, 20, 5, 30, true],
    '超える (32 > 30)' => [5, 25, 2, 30, true],
    '検問の現行値 (5 < 10)' => [5, null, null, 10, false],
    '1 つも取れていない' => [null, null, null, 30, true],
]);

test('S13d (正のコントロール): 実ファイルと合成の基準本文から 3 値がちょうど取れること', function (): void {
    // 実ファイル
    $real = claudeHooksInnerLimits(
        claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh')),
        'scripts/code-review-graph-update-hook.sh',
    );
    expect($real['violations'])->toBe([], implode("\n", $real['violations']));
    expect($real['limits'])->toBe(['stdin' => 5, 'body' => 20, 'kill' => 2]);

    // 合成の基準本文 (変異していない = 違反ゼロ)。囮のコメント行があっても件数は増えない
    $synthetic = claudeHooksInnerLimits(
        claudeHooksSyntheticUpdateHookBody(),
        'scripts/code-review-graph-update-hook.sh',
    );
    expect($synthetic['violations'])->toBe([], implode("\n", $synthetic['violations']));
    expect($synthetic['limits'])->toBe(['stdin' => 5, 'body' => 20, 'kill' => 2]);

    // 検問側 (本体と猶予を持たない申告)
    $guard = claudeHooksInnerLimits(
        claudeHooksReadFile(base_path('scripts/bughunt-worktree-hook.sh')),
        'scripts/bughunt-worktree-hook.sh',
    );
    expect($guard['violations'])->toBe([], implode("\n", $guard['violations']));
    expect($guard['limits'])->toBe(['stdin' => 5, 'body' => null, 'kill' => null]);
});

test('S13e (候補計数の裏取り): 候補の語彙が区切りトークンの完全一致で判定されること', function (): void {
    // 候補計数だけを直接検査する (S13b は「併存を検出できる」ことしか示さないので、
    // **誤検出しない側**をここで固定する = AGENTS.md 静的検査の共通規約 (e) の 3 形)。
    // 正例
    expect(claudeHooksInnerLimitCandidateCounts('IFS= read -r -N 10 -t 5 input || true'))
        ->toBe(['stdin' => 1, 'body' => 0, 'kill' => 0]);
    expect(claudeHooksInnerLimitCandidateCounts('readonly INNER_TIMEOUT_SECONDS=20'))
        ->toBe(['stdin' => 0, 'body' => 1, 'kill' => 0]);
    expect(claudeHooksInnerLimitCandidateCounts('timeout -k 2 "${X}" \\'))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // 宣言した区切り: タブでも割れる
    expect(claudeHooksInnerLimitCandidateCounts("timeout\t-k\t2"))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // 行の先頭語だけを見る判定ではない (トークンのどこにあっても候補になる)
    expect(claudeHooksInnerLimitCandidateCounts('env timeout -k 2 "${X}"'))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 1]);

    // コメント行と空行は実行行ではない
    expect(claudeHooksInnerLimitCandidateCounts("# timeout -k 2\n\n   # readonly INNER_TIMEOUT_SECONDS=20"))
        ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0]);

    // 負例: 接頭辞つき・打ち消しつき・接尾辞つきは候補にしない
    foreach ([
        'xtimeout -k 2', '!timeout -k 2', 'timeoutx -k 2',
        'xread -r -t 5', '!read -r -t 5', 'readx -r -t 5',
        'XINNER_TIMEOUT_SECONDS=20', '!INNER_TIMEOUT_SECONDS=20', 'INNER_TIMEOUT_SECONDSX=20',
    ] as $lookalike) {
        expect(claudeHooksInnerLimitCandidateCounts($lookalike))
            ->toBe(['stdin' => 0, 'body' => 0, 'kill' => 0], "トークン完全一致でない判定になっている: {$lookalike}");
    }
});

// =============================================================================
// 実起動層: 索引更新 hook (B01〜B25)
// =============================================================================

test('B01: 正常な入力で索引の差分更新が 1 回だけ起動され、静かに 0 で終わること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/Models/User.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('', '標準出力は常に空でなければならない');
        expect($result['errorOutput'])->toBe('', '成功時は告知しない');
        expect(claudeHooksInvocations($sandbox))->toBe(1);
        claudeHooksExpectContains(
            claudeHooksReadFile($sandbox.'/invoked.txt'),
            'update -q --repo '.$sandbox,
            '差分更新が sandbox のルートを --repo で受け取っていない',
        );
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B02〜B05: 告知は理由ごと・セッションごとに 1 回だけであること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // B02: 索引ツール未導入 → 1 行だけ告知する
        $first = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/A.php', 'sess-1'), claudeHooksPathWithoutTool($sandbox),
        );
        expect($first['exitCode'])->toBe(0);
        expect($first['output'])->toBe('');
        expect(claudeHooksWarningLines($first['errorOutput']))->toBe(1);
        expect($first['errorOutput'])->toContain('未導入');

        // B03: 同じセッション・同じ理由 → 黙る
        $second = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/B.php', 'sess-1'), claudeHooksPathWithoutTool($sandbox),
        );
        expect(claudeHooksWarningLines($second['errorOutput']))->toBe(0, '同一セッション・同一理由で二重告知した');

        // B04: セッションが変われば再告知する
        $third = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/C.php', 'sess-2'), claudeHooksPathWithoutTool($sandbox),
        );
        expect(claudeHooksWarningLines($third['errorOutput']))->toBe(1);

        // B05: 同じセッションでも理由が違えば告知する (timeout 不在)
        claudeHooksInstallToolStub($sandbox);
        $fourth = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/D.php', 'sess-1'), claudeHooksPathWithoutTimeout($sandbox),
        );
        expect(claudeHooksWarningLines($fourth['errorOutput']))->toBe(1);
        expect($fourth['errorOutput'])->toContain('timeout');
        expect(claudeHooksInvocations($sandbox))->toBe(0, 'timeout が無いのに更新を起動した');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B06/B07: 敵対的な検索パスでもカレントの偽ツールを起動しないこと', function (string $path): void {
    $sandbox = claudeHooksSandbox();

    try {
        // カレントディレクトリに偽の索引ツールを置く (PATH に "." が残っていれば起動される)
        File::makeDirectory($sandbox.'/cwd', 0700, true);
        File::put($sandbox.'/cwd/code-review-graph', "#!/bin/bash\ntouch '{$sandbox}/FAKE-RAN'\n");
        chmod($sandbox.'/cwd/code-review-graph', 0700);

        $result = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/A.php'), $path, $sandbox.'/cwd',
        );

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(is_file($sandbox.'/FAKE-RAN'))->toBeFalse("検索パス [{$path}] でカレントの偽ツールが起動された");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'PATH が空' => [''],
    'PATH がカレント' => ['.'],
    'PATH が相対値' => ['relative/bin'],
    'PATH が存在しない絶対パス' => ['/nonexistent-claude-hooks'],
]);

test('B08/B09: 壊れた JSON でも空入力でも 0 で終わること', function (string $input): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $result = claudeHooksRunUpdateHook($sandbox, $input);

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    '壊れた JSON' => ['{"session_id": "sess-a", "tool_input": {'],
    '空入力' => [''],
]);

test('B10: 標準入力を閉じない相手に待ち続けないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        // 名前付きパイプの書き手を開いたまま何も書かない = 「閉じない producer」
        $script = <<<BASH
        set -u
        mkfifo '{$sandbox}/pipe'
        sleep 60 > '{$sandbox}/pipe' &
        writer=\$!
        '/bin/bash' '{$sandbox}/scripts/code-review-graph-update-hook.sh' < '{$sandbox}/pipe'
        code=\$?
        kill "\${writer}" 2>/dev/null
        exit "\${code}"
        BASH;

        $result = claudeHooksRun(['/bin/bash', '-c', $script]);

        expect($result['exitCode'])->toBe(0);
        expect($result['elapsed'])->toBeLessThan(30.0, '標準入力の待ちが時間切れで打ち切られていない');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B11: 1 MiB より後ろにしか手掛かりが無い入力でも待ち続けず 0 で終わること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $input = str_repeat('x', 1_200_000).claudeHooksWritePayload('docs/x.md');
        $result = claudeHooksRunUpdateHook($sandbox, $input);

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B12〜B14: 置き場・ロックが symlink なら何も書かずに終えること', function (string $case): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        File::makeDirectory($sandbox.'/decoy', 0700, true);

        match ($case) {
            '.claude が symlink' => symlink($sandbox.'/decoy', $sandbox.'/.claude'),
            '置き場が symlink' => (function () use ($sandbox): void {
                File::makeDirectory($sandbox.'/.claude', 0700, true);
                symlink($sandbox.'/decoy', $sandbox.'/.claude/code-review-graph-update-hook');
            })(),
            'ロックが symlink' => (function () use ($sandbox): void {
                File::makeDirectory($sandbox.'/.claude/code-review-graph-update-hook', 0700, true);
                symlink($sandbox.'/decoy/update.lock', $sandbox.'/.claude/code-review-graph-update-hook/update.lock');
            })(),
        };

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksInvocations($sandbox))->toBe(0, "{$case}: 更新が起動された");
        expect(File::files($sandbox.'/decoy'))->toBe([], "{$case}: symlink の先に書き込まれた");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with(['.claude が symlink', '置き場が symlink', 'ロックが symlink']);

test('B15: 置き場の親が書けなければ黙って 0 で終えること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        File::makeDirectory($sandbox.'/.claude', 0500, true);

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksInvocations($sandbox))->toBe(0);
    } finally {
        if (is_dir($sandbox.'/.claude')) {
            chmod($sandbox.'/.claude', 0700);
        }
        File::deleteDirectory($sandbox);
    }
});

test('B16: 他が更新中なら待たずに黙って終えること', function (): void {
    $sandbox = claudeHooksSandbox();
    $holder = null;

    try {
        claudeHooksInstallToolStub($sandbox);
        $stateDir = $sandbox.'/.claude/code-review-graph-update-hook';
        File::makeDirectory($stateDir, 0700, true);

        $holder = Process::timeout(60)->start(['/bin/bash', '-c', <<<BASH
            exec 9> '{$stateDir}/update.lock'
            flock -n 9 || exit 1
            : > '{$sandbox}/HELD'
            sleep 20
            BASH]);

        $waitedUntil = microtime(true) + 15.0;
        while (! is_file($sandbox.'/HELD') && microtime(true) < $waitedUntil) {
            usleep(20_000);
        }
        expect(is_file($sandbox.'/HELD'))->toBeTrue('ロック保持プロセスを起こせなかった');

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksInvocations($sandbox))->toBe(0, 'ロック競合中に更新が起動された');
        expect($result['elapsed'])->toBeLessThan(10.0, 'ロックを待ってしまっている (非ブロッキングでない)');
    } finally {
        $holder?->stop();
        File::deleteDirectory($sandbox);
    }
});

test('B17: 並行起動しても更新は 1 回だけであること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // 3 秒かかる更新にして、後続が確実にロック競合へ落ちるようにする
        claudeHooksInstallToolStub($sandbox, "exec '".claudeHooksResolveExecutable('sleep')."' 3\n");

        $startedAt = microtime(true);
        $processes = [];
        for ($i = 0; $i < 5; $i++) {
            $processes[] = Process::timeout(60)
                ->input(claudeHooksWritePayload("app/File{$i}.php", "sess-{$i}"))
                ->start([
                    '/usr/bin/env', '-i', 'PATH='.claudeHooksPathWithTool($sandbox),
                    '/bin/bash', $sandbox.'/scripts/code-review-graph-update-hook.sh',
                ]);
        }
        foreach ($processes as $process) {
            expect($process->wait()->exitCode())->toBe(0);
        }
        $elapsed = microtime(true) - $startedAt;

        expect(claudeHooksInvocations($sandbox))->toBe(1, '排他が効いておらず更新が重複起動された');
        expect($elapsed)->toBeLessThan(
            (float) claudeHooksHookTimeout('PostToolUse'),
            '呼び出し側 timeout を超えた',
        );
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B18: 終わらない更新を内側の時間切れで打ち切り、その旨を 1 行告知すること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox, "exec '".claudeHooksResolveExecutable('sleep')."' 120\n");

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksWarningLines($result['errorOutput']))->toBe(1);

        $inner = claudeHooksInnerLimits(
            claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh')),
            'scripts/code-review-graph-update-hook.sh',
        )['limits']['body'];
        Assert::integer($inner);
        expect($result['errorOutput'])->toContain("{$inner} 秒");

        // 実測の上限は**設定由来の値** (配線の時間切れ) を使う。根拠の無い余裕の数値を持ち込まない
        // (この stub は 120 秒眠るので、内側の時間切れが効いていなければ必ず超える)。
        // 数値の関係そのものは静的層 (S13) が見るので、ここは「内側が実際に発火する」ことだけを見る。
        expect($result['elapsed'])->toBeLessThan(
            (float) claudeHooksHookTimeout('PostToolUse'),
            '内側の時間切れが効いていない (配線の時間切れまで走ってしまっている)',
        );
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B19: 更新が失敗したらその旨を 1 行告知して 0 で終えること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox, "exit 3\n");

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksWarningLines($result['errorOutput']))->toBe(1);
        expect($result['errorOutput'])->toContain('終了コード 3');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B20: 細工されたセッション識別子で置き場の外にファイルを作らないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        $payload = '{"session_id":"../../'.basename($sandbox).'-escape","tool_input":{"file_path":"app/A.php"}}';
        $result = claudeHooksRunUpdateHook($sandbox, $payload, claudeHooksPathWithoutTool($sandbox));

        expect($result['exitCode'])->toBe(0);
        // 置き場に出来てよいのはロックと告知の目印だけで、いずれも識別子が正規化されたもの
        foreach (File::files($sandbox.'/.claude/code-review-graph-update-hook') as $file) {
            expect(in_array($file->getFilename(), ['update.lock', 'warned-tool-missing-unknown'], true))
                ->toBeTrue('置き場に想定外のファイルが出来た: '.$file->getFilename());
        }
        expect(glob(dirname($sandbox).'/*-escape') ?: [])->toBe([], '置き場の外にファイルが作られた');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B21/B22: 索引の対象外拡張子では副作用ゼロで終えること', function (string $filePath): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload($filePath));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect($result['errorOutput'])->toBe('');
        expect(claudeHooksInvocations($sandbox))->toBe(0, "{$filePath} で更新が起動された");
        expect(is_dir($sandbox.'/.claude'))->toBeFalse("{$filePath} で置き場が作られた (副作用ゼロでない)");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'docs/x.md' => ['docs/x.md'],
    '大文字の拡張子' => ['docs/x.MD'],
    'package.json' => ['package.json'],
    'pnpm-lock.yaml' => ['pnpm-lock.yaml'],
]);

test('B23/B24: 判定できない入力は更新する側へ倒すこと', function (string $filePath): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload($filePath));

        expect($result['exitCode'])->toBe(0);
        expect(claudeHooksInvocations($sandbox))->toBe(1, "{$filePath} で更新が起動されなかった");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'blade の複合拡張子' => ['resources/views/x.blade.php'],
    '拡張子なし' => ['Makefile'],
    'svelte' => ['resources/js/x.svelte'],
]);

test('B25: 作業ディレクトリと環境変数に依存せずリポジトリルートを解決すること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        // cwd を / にし、CLAUDE_PROJECT_DIR も渡さない (env -i なので元から無い)
        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'), null, '/');

        expect($result['exitCode'])->toBe(0);
        expect(claudeHooksReadFile($sandbox.'/invoked.txt'))->toContain('--repo '.$sandbox);
    } finally {
        File::deleteDirectory($sandbox);
    }
});

// =============================================================================
// 実起動層: bug-hunt ガード (B26〜B40b)
// =============================================================================

test('B26/B28/B30〜B33/B40/B40b: provision の直叩きだけを拒否すること', function (string $command, int $expected): void {
    $sandbox = claudeHooksSandbox();

    try {
        $result = claudeHooksRunBughuntHook($sandbox, claudeHooksBashPayload($command));

        expect($result['exitCode'])->toBe($expected, "コマンド [{$command}] の判定が違う");
        expect($result['output'])->toBe('', '標準出力は常に空でなければならない');
        if ($expected === CLAUDE_HOOKS_DENY_EXIT_CODE) {
            expect($result['errorOutput'])->toContain('bug-hunt provision');
        } else {
            expect($result['errorOutput'])->toBe('');
        }
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'B26 無関係なコマンド' => ['ls -la', 0],
    'B28 main からの直叩き' => ['scripts/bug-hunt-shard.sh provision --shard 1', CLAUDE_HOOKS_DENY_EXIT_CODE],
    'B30 worktree から' => ['cd .claude/worktrees/tasks/x && scripts/bug-hunt-shard.sh provision', 0],
    'B31 明示解除' => ['BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
    'B32 self-test dryrun' => ['BUGHUNT_SELFTEST_DRYRUN=1 scripts/bug-hunt-shard.sh provision', 0],
    'B40 間に別語が入る言及' => ['scripts/bug-hunt-shard.sh scaffold x provision', 0],
    'B40b provision-all' => ['scripts/bug-hunt-shard.sh provision-all', CLAUDE_HOOKS_DENY_EXIT_CODE],
]);

test('B37: JSON が / を \\/ へ逃がしていても worktree の指紋を取りこぼさないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        $payload = claudeHooksBashPayload(
            'cd .claude/worktrees/tasks/x && scripts/bug-hunt-shard.sh provision',
            'x',
            escapeSlashes: true,
        );
        claudeHooksExpectContains($payload, '.claude\\/worktrees\\/', 'テスト入力が逃がし表記になっていない');

        expect(claudeHooksRunBughuntHook($sandbox, $payload)['exitCode'])
            ->toBe(0, '逃がし表記の worktree パスを許可シグナルとして拾えていない');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B33: 説明文だけに provision があっても誤発火しないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        $payload = claudeHooksBashPayload('echo hello', 'scripts/bug-hunt-shard.sh provision の説明');
        $result = claudeHooksRunBughuntHook($sandbox, $payload);

        expect($result['exitCode'])->toBe(0, '抽出値ではなく生入力で判定している');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B27/B29: 検索パスが壊れていても判定が変わらず、外部コマンドを 1 つも起こさないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // カレントに偽の判定用コマンドを置く (以前の実装はこれらに依存していた)
        File::makeDirectory($sandbox.'/cwd', 0700, true);
        foreach (['cat', 'grep', 'python3', 'printf'] as $name) {
            File::put($sandbox.'/cwd/'.$name, "#!/bin/bash\ntouch '{$sandbox}/FAKE-{$name}'\n");
            chmod($sandbox.'/cwd/'.$name, 0700);
        }

        // B27: 無関係なコマンド + 敵対的な検索パス → 0 のまま
        $passing = claudeHooksRunBughuntHook(
            $sandbox, claudeHooksBashPayload('ls -la'), '/nonexistent-claude-hooks', $sandbox.'/cwd',
        );
        expect($passing['exitCode'])->toBe(0);

        // B29: 拒否対象 + 空の検索パス → 無音の素通りをしない
        $denied = claudeHooksRunBughuntHook(
            $sandbox, claudeHooksBashPayload('scripts/bug-hunt-shard.sh provision'), '', $sandbox.'/cwd',
        );
        expect($denied['exitCode'])->toBe(CLAUDE_HOOKS_DENY_EXIT_CODE, '検索パスが壊れると拒否対象が黙って通っている');
        expect($denied['errorOutput'])->toContain('bug-hunt provision');

        expect(glob($sandbox.'/FAKE-*') ?: [])->toBe([], '判定経路が外部コマンドに依存している');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B34〜B36: JSON を解釈できないときは明示解除だけを見ること', function (string $input, int $expected): void {
    $sandbox = claudeHooksSandbox();

    try {
        $result = claudeHooksRunBughuntHook($sandbox, $input);

        expect($result['exitCode'])->toBe($expected);
        expect($result['output'])->toBe('');
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'B34 解除なし' => ['{"tool_input": {"comm scripts/bug-hunt-shard.sh provision', CLAUDE_HOOKS_DENY_EXIT_CODE],
    'B35 明示解除あり' => ['{"tool_input": {"comm BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
    'B36 痕跡だけ' => ['{"tool_input": {"comm .claude/worktrees/ scripts/bug-hunt-shard.sh provision', CLAUDE_HOOKS_DENY_EXIT_CODE],
]);

test('B38: 標準入力が空でも閉じない相手でも 0 で終えること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        expect(claudeHooksRunBughuntHook($sandbox, '')['exitCode'])->toBe(0);

        $script = <<<BASH
        set -u
        mkfifo '{$sandbox}/pipe'
        sleep 60 > '{$sandbox}/pipe' &
        writer=\$!
        '/bin/bash' '{$sandbox}/scripts/bughunt-worktree-hook.sh' < '{$sandbox}/pipe'
        code=\$?
        kill "\${writer}" 2>/dev/null
        exit "\${code}"
        BASH;

        $result = claudeHooksRun(['/bin/bash', '-c', $script]);
        expect($result['exitCode'])->toBe(0);
        expect($result['elapsed'])->toBeLessThan(30.0);
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B39: 1 MiB より後ろにしか対象語が無い入力では通す (受容済みの限界)', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        $input = str_repeat('x', 1_200_000).claudeHooksBashPayload('scripts/bug-hunt-shard.sh provision');
        $result = claudeHooksRunBughuntHook($sandbox, $input);

        expect($result['exitCode'])->toBe(0, '読み取り上限を超えた入力は通す (待ち続けないことを優先する)');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

// =============================================================================
// 実起動層: 起動子 (B41〜B46)
// =============================================================================

test('B41: PreToolUse の起動子が内側の終了コードをそのまま返すこと', function (int $inner): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksWriteExitStub($sandbox.'/scripts/bughunt-worktree-hook.sh', $inner);
        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $sandbox);

        expect($result['exitCode'])->toBe($inner, "内側が {$inner} なのに起動子が畳んでいる");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    '通過 (0)' => [0],
    'ブロックしない異常 (1)' => [1],
    '拒否 (2)' => [2],
    'ブロックしない異常 (3)' => [3],
    '旧拒否コード (97) が特別扱いされないこと' => [97],
    '実行不能 (127)' => [127],
]);

test('B42: PostToolUse の起動子も内側の終了コードをそのまま返すこと', function (int $inner): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksWriteExitStub($sandbox.'/scripts/code-review-graph-update-hook.sh', $inner);
        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PostToolUse'), $sandbox);

        expect($result['exitCode'])->toBe($inner);
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([[0], [1], [2], [3], [97], [127]]);   // **1 つも畳まない**契約なので 2 と 127 も落とさない

test('B43: 標準入力が起動子を通って内側へそのまま届くこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // 内側で標準入力を読んで書き出すスクリプト (payload が欠けたら中身が空になる)
        File::put($sandbox.'/scripts/bughunt-worktree-hook.sh', <<<BASH
        #!/bin/bash
        IFS= read -r -N 1048576 -t 5 payload || true
        printf '%s' "\${payload}" > '{$sandbox}/received.txt'
        exit 0
        BASH);
        chmod($sandbox.'/scripts/bughunt-worktree-hook.sh', 0700);

        $payload = claudeHooksBashPayload('ls -la');
        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $sandbox, input: $payload);

        expect($result['exitCode'])->toBe(0);
        expect(claudeHooksReadFile($sandbox.'/received.txt'))->toBe($payload, '標準入力が内側へ届いていない');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B44: 起動先が無い / 起動元の位置が未設定なら 127 で終わり、ブロックにならないこと', function (string $case): void {
    $sandbox = claudeHooksSandbox();
    $projectDir = $sandbox;

    try {
        match ($case) {
            '起動先が無い' => File::delete($sandbox.'/scripts/bughunt-worktree-hook.sh'),
            'CLAUDE_PROJECT_DIR が無い' => (function () use (&$projectDir): void {
                // 前提を明示する: 未設定だとパスが `/scripts/…` に潰れるので、
                // ホスト側にその実体が無いことを確かめてから 127 を期待する
                expect(is_file('/scripts/bughunt-worktree-hook.sh'))
                    ->toBeFalse('ホストに /scripts/bughunt-worktree-hook.sh が実在するため本ケースの前提が崩れている');
                $projectDir = null;
            })(),
        };

        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $projectDir);

        // 127 = bash がスクリプトを開けない。**2 ではない**ので Bash ツールはブロックされない
        expect($result['exitCode'])->toBe(127, "{$case}: 起動できなかったのに 127 で終わっていない");
        expect($result['exitCode'])->not->toBe(CLAUDE_HOOKS_DENY_EXIT_CODE);
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with(['起動先が無い', 'CLAUDE_PROJECT_DIR が無い']);

test('B45 (i14 の非保証の実証): 起動子は起動先も起動元の位置も検証しないこと', function (string $case): void {
    // 旧実装 (写像器) が持っていた 7 条件の検証は正典 t3 で撤去した。
    // **ここで実証するのは「明示的に非保証にした 4 形」だけ**であり、非保証の全体を
    // 網羅するものではない (全体の正本は冒頭 docblock の i14 の 3 点である)。
    $sandbox = claudeHooksSandbox();
    $projectDir = $sandbox;
    $cwd = null;

    try {
        claudeHooksWriteExitStub($sandbox.'/scripts/bughunt-worktree-hook.sh', 0);

        match ($case) {
            '起動元の位置が相対値' => (function () use ($sandbox, &$projectDir, &$cwd): void {
                $projectDir = basename($sandbox);
                $cwd = dirname($sandbox);
            })(),
            '起動元の位置が .. を含む' => $projectDir = dirname($sandbox).'/../'.basename(dirname($sandbox)).'/'.basename($sandbox),
            '起動先が symlink' => (function () use ($sandbox): void {
                claudeHooksWriteExitStub($sandbox.'/scripts/real-hook.sh', 0);
                File::delete($sandbox.'/scripts/bughunt-worktree-hook.sh');
                symlink($sandbox.'/scripts/real-hook.sh', $sandbox.'/scripts/bughunt-worktree-hook.sh');
            })(),
            'scripts が symlink' => (function () use ($sandbox): void {
                rename($sandbox.'/scripts', $sandbox.'/real-scripts');
                symlink($sandbox.'/real-scripts', $sandbox.'/scripts');
            })(),
        };

        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PreToolUse'), $projectDir, $cwd);

        expect($result['exitCode'])->toBe(0, "{$case}: 起動子が内側を起こしていない (正典 t3 では検証しないのが正)");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with(['起動元の位置が相対値', '起動元の位置が .. を含む', '起動先が symlink', 'scripts が symlink']);

test('B46: 起動子が環境からのシェル関数を内側へ継承させないこと (privileged mode)', function (): void {
    $sandbox = claudeHooksSandbox();
    $command = claudeHooksLauncherCommand('PreToolUse');

    try {
        // 内側で「注入した関数が見えるか」を自分で記録するスクリプト
        File::put($sandbox.'/scripts/bughunt-worktree-hook.sh', <<<BASH
        #!/bin/bash
        if [ "\$(type -t claude_hooks_probe)" = "function" ]; then
            touch '{$sandbox}/FUNC-LEAKED'
        fi
        exit 0
        BASH);
        chmod($sandbox.'/scripts/bughunt-worktree-hook.sh', 0700);

        $wrapper = "claude_hooks_probe() { :; }\nexport -f claude_hooks_probe\nexec ".$command;
        $result = claudeHooksRun([
            '/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'CLAUDE_PROJECT_DIR='.$sandbox,
            '/bin/bash', '-c', $wrapper,
        ]);

        expect($result['exitCode'])->toBe(0);
        expect(is_file($sandbox.'/FUNC-LEAKED'))
            ->toBeFalse('環境から注入したシェル関数が hook へ継承された (privileged mode が効いていない)');
    } finally {
        File::deleteDirectory($sandbox);
    }
});
