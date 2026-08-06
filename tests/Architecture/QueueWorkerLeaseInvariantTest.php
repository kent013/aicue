<?php

declare(strict_types=1);

use Illuminate\Support\Env;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\QueueLeaseConfig;
use Webmozart\Assert\Assert;

/*
 * 規則 1 (無条件): その接続で有効なワーカー / supervisor の `--timeout` は、
 * その接続の `retry_after` を **下回る**。
 *
 * DB driver のキューには実行中にリース (retry_after) を延長する API が無いため、
 * 「まだ走っている処理を落ちたと誤認させない」手段は設定の大小関係を保つことだけである。
 * 1 つのワーカーは同じ接続の複数種類のジョブを処理するため、`$timeout` を宣言した
 * ジョブが 1 本あってもワーカー側の制限時間は免除されない。
 *
 * ★ `queue:listen` 配下では **ジョブ側の `$timeout` がまったく効かない**
 *   (`Listener::createCommand()` は子 `queue:work --once` へ `--timeout` を渡さず、
 *    `Worker::runNextJob()` は SIGALRM を張らない)。dev (mprocs) / bug-hunt では
 *   ここで検査する `--timeout` が唯一の上限である。
 *
 * 検査対象は「リポジトリ内にあるワーカー起動定義」= `mprocs.yaml` (dev) と
 * `scripts/bug-hunt-shard.sh` (bug-hunt) の 2 面。本番 supervisor 定義はリポジトリに
 * 実体が無いため本 gate では検知できない (正本は docs/architecture.md の値表)。
 *
 * 運用契約: docs/architecture.md §キューのリース期間とワーカー制限時間の規約
 */

/**
 * ワーカー起動定義に `--timeout` を持つが **キューワーカーではない** proc。
 *
 * key = mprocs の proc 名 / value = なぜ規則 1 の対象外か。
 * ★ 黙って除外しない。理由を書けないものをここに足さないこと。
 *
 * @var array<string, string>
 */
const MPROCS_NON_WORKER_TIMEOUT_PROCS = [
    'logs' => 'php artisan pail はログ追尾クライアント。--timeout は追尾の打ち切り秒数であり、キューのリース (retry_after) とは無関係',
];

/**
 * mprocs の proc 定義を「proc 名 => shell 文字列」へ正規化する (純関数)。
 *
 * @return array<string, string>
 */
function queueLeaseMprocsShellCommands(string $yamlPath): array
{
    $yaml = Yaml::parseFile($yamlPath);
    Assert::isArray($yaml, 'mprocs.yaml が map ではありません');
    Assert::keyExists($yaml, 'procs', 'mprocs.yaml に procs がありません');

    $procs = $yaml['procs'];
    Assert::isArray($procs, 'mprocs.yaml の procs が map ではありません');

    $commands = [];
    foreach ($procs as $name => $definition) {
        Assert::string($name, 'mprocs.yaml の proc 名が文字列ではありません');
        Assert::isArray($definition, "mprocs.yaml の proc {$name} が map ではありません");
        Assert::keyExists($definition, 'shell', "mprocs.yaml の proc {$name} に shell がありません");

        // 配列 offset 式のままだと narrowing が保たれないためローカル変数へ移す
        $shell = $definition['shell'];
        Assert::string($shell, "mprocs.yaml の proc {$name} の shell が文字列ではありません");

        $commands[$name] = $shell;
    }

    return $commands;
}

/**
 * shell 文字列がキューワーカー起動かを判定し、接続名と --timeout を返す (純関数)。
 *
 * `queue:work` / `queue:listen` の **両方**をワーカーとして扱う
 * (どちらでも --timeout は「その接続で有効な制限時間」= 規則 1 の対象)。
 *
 * 正規表現ではなくトークン分割で足りる根拠: mprocs の shell は引用符・変数展開・
 * パイプを含まない単純な 1 コマンド行である。将来それが崩れたらケース 1/2 が
 * 「接続名が取れない」で落ちるので、黙って通ることはない。
 *
 * @return array{connection: string|null, timeout: int|null}|null ワーカーでなければ null
 */
function queueLeaseParseWorkerCommand(string $shell): ?array
{
    $tokens = preg_split('/\s+/', trim($shell), -1, PREG_SPLIT_NO_EMPTY);
    Assert::isArray($tokens);

    if (! in_array('artisan', $tokens, true)) {
        return null;
    }

    $subcommandIndex = null;
    foreach ($tokens as $index => $token) {
        if ($token === 'queue:work' || $token === 'queue:listen') {
            $subcommandIndex = $index;
            break;
        }
    }

    if ($subcommandIndex === null) {
        return null;
    }

    // サブコマンドの次のトークンがオプションでなければ接続名
    $connection = null;
    $next = $tokens[$subcommandIndex + 1] ?? null;
    if (is_string($next) && ! str_starts_with($next, '-')) {
        $connection = $next;
    }

    $timeout = null;
    foreach ($tokens as $index => $token) {
        $raw = null;
        if (str_starts_with($token, '--timeout=')) {
            $raw = substr($token, strlen('--timeout='));
        } elseif ($token === '--timeout') {
            $raw = $tokens[$index + 1] ?? null;
        }

        if ($raw === null) {
            continue;
        }

        Assert::string($raw, "--timeout の値が取得できません: {$shell}");
        Assert::regex($raw, '/\A\d+\z/', "--timeout の値が数値ではありません: {$shell}");
        $timeout = (int) $raw;
        break;
    }

    return ['connection' => $connection, 'timeout' => $timeout];
}

/**
 * bash ソースから `declare -A BUGHUNT_WORKER_TIMEOUTS=( [conn]=N ... )` を抽出する (純関数)。
 *
 * @return array<string, int>
 */
function queueLeaseBughuntWorkerTimeouts(string $bashSource): array
{
    $matched = preg_match(
        '/declare\s+-A\s+BUGHUNT_WORKER_TIMEOUTS=\((?<body>[^)]*)\)/',
        $bashSource,
        $block,
    );
    Assert::same($matched, 1, 'bug-hunt の接続別 timeout 定義 (declare -A BUGHUNT_WORKER_TIMEOUTS) が見つかりません (一律値へ戻された可能性があります)');

    preg_match_all('/\[([A-Za-z0-9_-]+)\]=(\d+)/', $block['body'], $entries, PREG_SET_ORDER);
    Assert::notEmpty($entries, 'BUGHUNT_WORKER_TIMEOUTS に entry がありません');

    $timeouts = [];
    foreach ($entries as $entry) {
        $timeouts[$entry[1]] = (int) $entry[2];
    }

    return $timeouts;
}

/**
 * bash ソースから `BUGHUNT_WORKER_CONNECTIONS=(a b c)` を抽出する (純関数)。
 *
 * @return list<string>
 */
function queueLeaseBughuntWorkerConnections(string $bashSource): array
{
    $matched = preg_match(
        '/^BUGHUNT_WORKER_CONNECTIONS=\((?<body>[^)]*)\)/m',
        $bashSource,
        $block,
    );
    Assert::same($matched, 1, 'bug-hunt の BUGHUNT_WORKER_CONNECTIONS 定義が見つかりません');

    $connections = preg_split('/\s+/', trim($block['body']), -1, PREG_SPLIT_NO_EMPTY);
    Assert::isArray($connections);
    Assert::allString($connections);
    Assert::notEmpty($connections, 'BUGHUNT_WORKER_CONNECTIONS が空です');

    return array_values($connections);
}

/**
 * bash ソースから `名前() { ... }` の関数定義本体を切り出す (純関数)。
 *
 * ★ 波括弧カウントは使わない (`${...}` を関数ブロックと誤認する)。**行単位**で抽出する:
 *   - 開始行: `/^{名前}\(\)[ \t]*\{$/` — 0 件または 2 件以上なら fail
 *   - 終了行: 開始行より後で最初に現れる列頭 `/^\}$/` — 見つからなければ fail
 *   対象スクリプトはこの整形規約に従っている。
 */
function queueLeaseExtractBashFunction(string $bashSource, string $functionName): string
{
    $lines = preg_split('/\R/u', $bashSource);
    Assert::isArray($lines);

    $startPattern = '/\A'.preg_quote($functionName, '/').'\(\)[ \t]*\{\z/';
    $starts = [];
    foreach ($lines as $index => $line) {
        Assert::string($line);
        if (preg_match($startPattern, $line) === 1) {
            $starts[] = $index;
        }
    }

    Assert::count($starts, 1, "bash 関数 {$functionName} の定義行がちょうど 1 つ見つかりません (整形規約から外れた可能性があります)");

    $start = $starts[0];
    $end = null;
    for ($i = $start + 1, $count = count($lines); $i < $count; $i++) {
        if ($lines[$i] === '}') {
            $end = $i;
            break;
        }
    }

    Assert::integer($end, "bash 関数 {$functionName} の終端 (列頭の }) が見つかりません");

    return implode("\n", array_slice($lines, $start, $end - $start + 1));
}

/** 行頭 (前置空白可) の `#` で始まる行を除去する (純関数)。 */
function queueLeaseStripBashCommentLines(string $bash): string
{
    $lines = preg_split('/\R/u', $bash);
    Assert::isArray($lines);

    $kept = [];
    foreach ($lines as $line) {
        Assert::string($line);
        if (preg_match('/\A[ \t]*#/', $line) === 1) {
            continue;
        }
        $kept[] = $line;
    }

    return implode("\n", $kept);
}

/**
 * mprocs.yaml のワーカー proc (proc 名 => 解析結果)。
 *
 * @return array<string, array{connection: string|null, timeout: int|null}>
 */
function queueLeaseMprocsWorkers(): array
{
    $workers = [];
    foreach (queueLeaseMprocsShellCommands(base_path('mprocs.yaml')) as $name => $shell) {
        $parsed = queueLeaseParseWorkerCommand($shell);
        if ($parsed !== null) {
            $workers[$name] = $parsed;
        }
    }

    return $workers;
}

/** scripts/bug-hunt-shard.sh の中身。 */
function queueLeaseBughuntSource(): string
{
    $source = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
    Assert::string($source, 'scripts/bug-hunt-shard.sh を読み込めません');

    return $source;
}

test('規則 1: mprocs のキューワーカーは接続名を明示する', function (): void {
    $workers = queueLeaseMprocsWorkers();
    expect($workers)->not->toBeEmpty();

    foreach ($workers as $name => $worker) {
        expect($worker['connection'])->not->toBeNull(
            "規則 1: mprocs の proc {$name} が接続名を明示していない。既定接続に頼ると QUEUE_CONNECTION 次第で"
            .'どの retry_after と比較すべきかが静的に決まらないため、接続名を必ず書くこと'
            .' (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)',
        );
    }
});

test('規則 1: mprocs のキューワーカーは --timeout を明示し retry_after を下回る', function (): void {
    $retryAfters = QueueLeaseConfig::databaseConnections();

    foreach (queueLeaseMprocsWorkers() as $name => $worker) {
        $connection = $worker['connection'];
        expect($connection)->not->toBeNull("規則 1: mprocs の proc {$name} が接続名を明示していない");
        Assert::string($connection);
        expect(array_key_exists($connection, $retryAfters))->toBeTrue(
            "規則 1: mprocs の proc {$name} の接続 {$connection} が config/queue.php の driver=database 接続に存在しない",
        );

        $timeout = $worker['timeout'];
        expect($timeout)->not->toBeNull(
            "規則 1: mprocs の proc {$name} に --timeout がない。queue:listen ではジョブ側 \$timeout が効かないため、"
            .'ここが唯一の上限である',
        );
        Assert::integer($timeout);

        expect($timeout)->toBeGreaterThan(
            0,
            "規則 1: mprocs の proc {$name} の --timeout が 0 (無制限)。retry_after を必ず下回る有限値にすること",
        );
        expect($timeout)->toBeLessThan(
            $retryAfters[$connection],
            "規則 1: mprocs の proc {$name} の --timeout ({$timeout}) が接続 {$connection} の retry_after"
            ." ({$retryAfters[$connection]}) 以上。二重取得の窓が開く",
        );
    }
});

test('規則 1: mprocs のワーカーは driver=database の全接続を覆う', function (): void {
    $covered = [];
    foreach (queueLeaseMprocsWorkers() as $worker) {
        if (is_string($worker['connection'])) {
            $covered[] = $worker['connection'];
        }
    }
    sort($covered);

    $expected = array_keys(QueueLeaseConfig::databaseConnections());
    sort($expected);

    expect($covered)->toBe(
        $expected,
        '規則 1: driver=database の接続は dev ワーカーペイン (mprocs.yaml) を必ず持つ。'
        .'接続だけ増やしてワーカーを足し忘れるとジョブが黙って滞留する'
        .' (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)',
    );
});

test('規則 1: --timeout を持つ非ワーカー proc は理由付きで除外登録されている', function (): void {
    $nonWorkers = [];
    foreach (queueLeaseMprocsShellCommands(base_path('mprocs.yaml')) as $name => $shell) {
        if (! str_contains($shell, '--timeout')) {
            continue;
        }
        if (queueLeaseParseWorkerCommand($shell) !== null) {
            continue;
        }
        $nonWorkers[] = $name;
    }
    sort($nonWorkers);

    $registered = array_keys(MPROCS_NON_WORKER_TIMEOUT_PROCS);
    sort($registered);

    expect($nonWorkers)->toBe(
        $registered,
        '規則 1: --timeout を持つ非ワーカー proc は MPROCS_NON_WORKER_TIMEOUT_PROCS へ理由付きで登録すること (黙って除外しない)',
    );

    foreach (MPROCS_NON_WORKER_TIMEOUT_PROCS as $name => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(
            20,
            "規則 1: {$name} の除外理由が短すぎる (なぜ retry_after と無関係かを書くこと)",
        );
    }
});

test('規則 1: bug-hunt の listener timeout は接続ごとに retry_after を下回る', function (): void {
    $source = queueLeaseBughuntSource();
    $retryAfters = QueueLeaseConfig::databaseConnections();
    $timeouts = queueLeaseBughuntWorkerTimeouts($source);

    foreach (queueLeaseBughuntWorkerConnections($source) as $connection) {
        expect(array_key_exists($connection, $timeouts))->toBeTrue(
            "規則 1: BUGHUNT_WORKER_TIMEOUTS に {$connection} の値がない (listener timeout 未定義では規則 1 の検査対象から漏れる)",
        );
    }

    foreach ($timeouts as $connection => $timeout) {
        expect(array_key_exists($connection, $retryAfters))->toBeTrue(
            "規則 1: BUGHUNT_WORKER_TIMEOUTS の {$connection} が config/queue.php の driver=database 接続に存在しない",
        );
        expect($timeout)->toBeGreaterThan(0, "規則 1: BUGHUNT_WORKER_TIMEOUTS[{$connection}] が正の整数でない");
        expect($timeout)->toBeLessThan(
            $retryAfters[$connection],
            "規則 1: bug-hunt の listener timeout ({$timeout}) が接続 {$connection} の retry_after"
            ." ({$retryAfters[$connection]}) 以上。二重取得の窓が開く",
        );
    }
});

test('規則 1: bug-hunt の起動行は --timeout を配列経由で渡す', function (): void {
    // ★ 検査範囲は start_shard_workers の関数本体から **コメント行を除いたもの**に限定する。
    //   全文を対象にすると、説明コメント中の旧値 (`--timeout=1800`) を自分で拾って必ず失敗する。
    //   self-test 側が `declare -f start_shard_workers` を対象にしているのと範囲を揃えている。
    $body = queueLeaseStripBashCommentLines(
        queueLeaseExtractBashFunction(queueLeaseBughuntSource(), 'start_shard_workers'),
    );

    expect(str_contains($body, '--timeout="${wtimeout}"'))->toBeTrue(
        '規則 1: start_shard_workers が BUGHUNT_WORKER_TIMEOUTS 由来の値で --timeout を渡していない',
    );
    expect(preg_match('/--timeout(?:=|\s+)\d+/', $body))->toBe(
        0,
        '規則 1: start_shard_workers に --timeout の数値直書きが復活している (接続別の値を潰し、'
        .'retry_after が短い接続 (database-media) で二重取得の窓を開く)',
    );
});

test('規則 1: database の retry_after は env で上書きできない', function (): void {
    // 静的 gate は config をテスト環境の値で読むため、env 上書きが残ると
    // 「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
    $repository = Env::getRepository();
    $hadOriginal = $repository->has('DB_QUEUE_RETRY_AFTER');
    $original = $hadOriginal ? $repository->get('DB_QUEUE_RETRY_AFTER') : null;

    $repository->set('DB_QUEUE_RETRY_AFTER', '1');

    try {
        $connections = QueueLeaseConfig::databaseConnections();
        expect($connections)->toHaveKey('database');
        expect($connections['database'])->toBe(
            600,
            '規則 1: config/queue.php の database.retry_after が env で上書きされた。'
            .'env('."'DB_QUEUE_RETRY_AFTER'".') ではなくリテラル 600 で持つこと',
        );
    } finally {
        if ($hadOriginal && is_string($original)) {
            $repository->set('DB_QUEUE_RETRY_AFTER', $original);
        } else {
            $repository->clear('DB_QUEUE_RETRY_AFTER');
        }
    }
});
