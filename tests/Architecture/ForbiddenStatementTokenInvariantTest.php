<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\ForbiddenStatement\ForbiddenStatementExemption;
use Tests\Support\ForbiddenStatement\ForbiddenStatementKind;
use Tests\Support\ForbiddenStatement\ForbiddenStatementRootPolicy;
use Tests\Support\ForbiddenStatement\ForbiddenStatementScanner;
use Tests\Support\ForbiddenStatement\ForbiddenStatementSite;

/*
 * Architecture invariant: 禁止する文 (出力する文 / 飛び越す文 / 大域を持ち込む文 /
 * 開始タグ付きの出力記法) を書かない。
 *
 * 設計は devnotes/20260815-1537-forbidden-statement-token-gate/ が正本。
 * 家系の機能台帳 (lctl feature: forbidden-statement-token-gate) の移植である。
 *
 * なぜ字句 (トークン) 走査なのか: pest-plugin-arch はクラス / 関数の参照しか見えず、
 * これらは「文」なので原理的に拾えない。既製 preset の同名規則は構文木の扱い上ほぼ働かない。
 *
 * ★**隣接 gate との関係 (統合しない)**: `NoNonCompoundGlobalUseTest` は
 *   「namespace 宣言の無いファイルの非複合 use」という別の不変条件を、
 *   `*.blade.php` を除いた母集団に対して見る。本 gate は blade を**含めて**走査する
 *   (開始タグで開いた区間が見えるため。除外すると開始タグ付き出力記法の禁止に穴が残る)。
 *   母集団が違うので `Tests\Support\TrackedPhpSourceFiles` は共用しない —
 *   同クラスの docblock は blade を「免除ではなく規則の段階で対象外」と宣言しており、
 *   そこを広げると既存 2 gate の走査域が黙って変わる。列挙の**作法**だけを揃える。
 *
 * ★**保証範囲を誇張しない**: 効くのは字句として現れる 4 語彙だけである。
 *   名前の解決が要る出力 (書式つき出力 / 変数の内容の表示 / 標準出力への書き込み) や、
 *   テンプレートの地の文に埋め込まれた区間には**無言で効かない**
 *   (限界の完全な記述は `ForbiddenStatementScanner` の docblock が正本)。
 *
 * ★**この gate は「素の main では赤にならない」種類のテストである。**
 *   空振りしていないことは (a) `tests/Unit/Architecture/ForbiddenStatementScannerTest.php` の
 *   正例 / 取りこぼし対照と、(b) 実装時に踏んだ fail-first 手順 (設計 S5 §実装時に必ず踏む手順) の
 *   2 本で担保する。加えて G2 が走査ファイル数の床値を機械的に固定する。
 */

/** 例外・除外の根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
const FORBIDDEN_STATEMENT_REASON_MIN_LENGTH = 30;

/**
 * 例外の登録件数。**現在値ちょうど** (exact fit。`<=` ではなく `===` で照合する)。
 * ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに書ける枠」になる。
 * ★減った場合も赤にする (登録を消したなら、この値を変える差分が要る)。
 */
const FORBIDDEN_STATEMENT_EXEMPTION_COUNT = 1;

/**
 * 走査対象ファイル数の床値。
 * ★走査が空振り (0 件) でも「違反 0 件」で緑になってしまうのを止める。
 *   実測 1552 (追跡 PHP 1567 − 除外 devnotes 15) に対し余裕を持たせて 1400 を置く。
 */
const FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR = 1400;

/**
 * 置き場所の分類 (単一の出典)。
 *
 * ★どれにも分類していない置き場所が現れたら G4 が赤になる。走査根を列挙するだけにすると、
 *   新しいディレクトリを足したときに**黙って走査対象から外れる**。
 *
 * @return array<string, array{ForbiddenStatementRootPolicy, string}>
 *                                                                    キーは最上位ディレクトリ名 (リポジトリ直下は空文字列)。
 *                                                                    第 2 要素は理由 (ScannedNoExemption は空文字列でよい)。
 */
function forbiddenStatementRootPolicies(): array
{
    return [
        '' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'app' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'bootstrap' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'config' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'database' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'lang' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'public' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'resources' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],

        'routes' => [ForbiddenStatementRootPolicy::ScannedNoExemption, ''],
        'scripts' => [
            ForbiddenStatementRootPolicy::ScannedWithExemption,
            'artisan を通さず別プロセスで起動される運用スクリプトが置かれる。'
            .'標準出力が人間への唯一の伝達手段になる場合がある。',
        ],
        'tests' => [
            ForbiddenStatementRootPolicy::ScannedWithExemption,
            '別プロセスで起動される検体が置かれる。親プロセスへ結果を返す手段が'
            .'標準出力しかない場合がある。',
        ],
        'devnotes' => [
            ForbiddenStatementRootPolicy::Excluded,
            '設計時の調査に使う一時スクリプトの置き場所であり (AGENTS.md「一時スクリプトは '
            .'devnotes へ」)、アプリの実行経路にも CI にも載らない。恒久化するときは '
            .'scripts/ へ移すので、そこで本 gate の対象になる。',
        ],
    ];
}

/**
 * 禁止する文を書くことが正しいと裁定したファイルの目録
 * (型付き + 具体的根拠必須 + 件数の完全一致、単一の出典)。
 *
 * ★**例外に登録されたファイルも全語彙を走査する** (skip しない)。差し引けるのは
 *   ここに登録した (パス, 語彙) の組だけで、登録の無い語彙が現れたら 1 件残らず違反になる。
 *
 * @return array<string, array{
 *     exemption: ForbiddenStatementExemption,
 *     counts: array<string, int>,
 *     reason: non-empty-string,
 * }> counts のキーは ForbiddenStatementKind の値
 */
function forbiddenStatementExemptions(): array
{
    return [
        'scripts/ci/drop-test-db.php' => [
            'exemption' => ForbiddenStatementExemption::StandaloneCliStdout,
            'counts' => [ForbiddenStatementKind::EchoStatement->value => 23],
            'reason' => 'worktree のテスト DB を回収する運用スクリプト。artisan を通さない素の PHP '
                .'として php scripts/ci/drop-test-db.php で起動され、Laravel の Console 出力機構を'
                .'持たない。既定 dry-run の分類結果を人間へ提示することがこのスクリプトの機能そのもの'
                .'であり、HTTP 応答の組み立て経路には載らない。',
        ],
    ];
}

/**
 * 相対パスの最上位ディレクトリ名 (リポジトリ直下は空文字列)。
 */
function forbiddenStatementRootOf(string $relative): string
{
    $slash = strpos($relative, '/');

    return $slash === false ? '' : substr($relative, 0, $slash);
}

/**
 * git 追跡下の `*.php` を列挙する (`*.blade.php` を含む)。
 *
 * ★git が無い / 失敗した場合は**例外で落とす**。silent skip にすると
 *   「走査していないのに緑」になる (`NoNonCompoundGlobalUseTest` と同じ判断)。
 * ★既知の限界: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
 *   commit / CI であり、そこでは必ず追跡下にある。
 *
 * @return list<array{absolute: string, relative: string}> relative の昇順
 */
function forbiddenStatementTrackedFiles(): array
{
    $root = base_path();
    $process = new Process(['git', 'ls-files', '-z', '--', '*.php'], $root);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
            .$process->getErrorOutput()
        );
    }

    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '') {
            continue;
        }
        $absolute = $root.'/'.$relative;
        if (! is_file($absolute)) {
            continue; // 削除済みだが index に残っている等
        }
        $files[] = ['absolute' => $absolute, 'relative' => $relative];
    }

    usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

    return $files;
}

/**
 * 走査の実行結果 (12 本のテストで共有するため 1 度だけ計算する)。
 *
 * @return array{
 *     trackedTotal: int,
 *     scannedTotal: int,
 *     scannedBladeTotal: int,
 *     trackedRoots: list<string>,
 *     perRoot: array<string, array{tracked: int, scanned: bool, classified: bool}>,
 *     sites: list<ForbiddenStatementSite>,
 * }
 */
function forbiddenStatementScanResult(): array
{
    /** @var array{trackedTotal: int, scannedTotal: int, scannedBladeTotal: int, trackedRoots: list<string>, perRoot: array<string, array{tracked: int, scanned: bool, classified: bool}>, sites: list<ForbiddenStatementSite>}|null $cache */
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $policies = forbiddenStatementRootPolicies();

    $trackedTotal = 0;
    $scannedTotal = 0;
    $scannedBladeTotal = 0;
    /** @var array<string, array{tracked: int, scanned: bool, classified: bool}> $perRoot */
    $perRoot = [];
    /** @var list<ForbiddenStatementSite> $sites */
    $sites = [];

    foreach (forbiddenStatementTrackedFiles() as $file) {
        $trackedTotal++;
        $root = forbiddenStatementRootOf($file['relative']);

        // 未分類の置き場所は走査しない (G4 が別途赤にする)。ここで走査してしまうと
        // 「分類漏れなのに緑」という状態が作れてしまう。
        $policy = $policies[$root][0] ?? null;
        $scanned = $policy === null ? false : match ($policy) {
            ForbiddenStatementRootPolicy::ScannedNoExemption,
            ForbiddenStatementRootPolicy::ScannedWithExemption => true,
            ForbiddenStatementRootPolicy::Excluded => false,
        };

        $perRoot[$root] ??= ['tracked' => 0, 'scanned' => $scanned, 'classified' => $policy !== null];
        $perRoot[$root]['tracked']++;

        if (! $scanned) {
            continue;
        }

        // ★読み取り失敗を skip にしない。git 追跡下のファイルが読めないのは環境異常であり、
        //   黙って走査から落とすと「走査していないのに緑」になる (fail-closed)。
        $source = file_get_contents($file['absolute']);
        if (! is_string($source)) {
            throw new RuntimeException(
                '走査対象の読み取りに失敗しました: '.$file['relative']
                .' (環境異常。silent skip にすると走査していないのに緑になる)'
            );
        }

        $scannedTotal++;
        if (str_ends_with($file['relative'], '.blade.php')) {
            $scannedBladeTotal++;
        }

        $sites = array_merge($sites, ForbiddenStatementScanner::sites($file['relative'], $source));
    }

    ksort($perRoot);

    $cache = [
        'trackedTotal' => $trackedTotal,
        'scannedTotal' => $scannedTotal,
        'scannedBladeTotal' => $scannedBladeTotal,
        'trackedRoots' => array_keys($perRoot),
        'perRoot' => $perRoot,
        'sites' => $sites,
    ];

    return $cache;
}

/**
 * 検出された site を `パス|語彙` で数える。
 *
 * @param  list<ForbiddenStatementSite>  $sites
 * @return array<string, int>
 */
function forbiddenStatementCountByPathAndKind(array $sites): array
{
    $counts = [];
    foreach ($sites as $site) {
        $key = $site->path.'|'.$site->kind->value;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    return $counts;
}

// ---------------------------------------------------------------------------
// G1: 違反そのもの
// ---------------------------------------------------------------------------

test('走査対象に禁止する文が存在しない (目録の登録分を除く)', function (): void {
    $result = forbiddenStatementScanResult();

    // 目録の (パス, 語彙) ごとに、登録件数を上限として差し引く。
    // ★実測と登録が食い違う場合は G8 が落とす。ここでは負にならないよう
    //   小さいほうを引いて「残り」を見せる。
    $remaining = [];
    foreach (forbiddenStatementExemptions() as $path => $entry) {
        foreach ($entry['counts'] as $kindValue => $count) {
            $remaining[$path.'|'.$kindValue] = $count;
        }
    }

    $violations = [];
    foreach ($result['sites'] as $site) {
        $key = $site->path.'|'.$site->kind->value;
        if (($remaining[$key] ?? 0) > 0) {
            $remaining[$key]--;

            continue;
        }
        $violations[] = $site->describe();
    }

    expect($violations)->toBe([],
        '禁止する文を検出しました。'.PHP_EOL
        .implode(PHP_EOL, $violations).PHP_EOL
        .'応答の組み立ては Inertia / JsonResource / Response で行ってください。'
        .'どうしても必要なら forbiddenStatementExemptions() へ理由付きで登録してください '
        .'(登録できるのは scripts / tests のみ)。');
});

// ---------------------------------------------------------------------------
// G2 / G3: 走査が空振りしていないこと
// ---------------------------------------------------------------------------

test('走査が空振りしていない (走査ファイル数が床値以上)', function (): void {
    $result = forbiddenStatementScanResult();

    $breakdown = [];
    foreach ($result['perRoot'] as $root => $info) {
        $label = $root === '' ? '(直下)' : $root;
        // ★「分類の上で除外した」のと「分類漏れで走査外になった」のを言い分ける。
        //   後者は G4 が別途赤にするが、床値割れの原因を 1 目で読めるようにする。
        $state = match (true) {
            $info['scanned'] => '(走査)',
            $info['classified'] => '(除外)',
            default => '(未分類→走査外!)',
        };
        $breakdown[] = $label.'='.$info['tracked'].$state;
    }

    $message = '走査対象が床値 ('.FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR.') を下回りました: '
        .'走査 '.$result['scannedTotal'].' 件'.PHP_EOL
        .'  追跡 PHP 総数: '.$result['trackedTotal'].' 件'.PHP_EOL
        .'  除外された数: '.($result['trackedTotal'] - $result['scannedTotal']).' 件'.PHP_EOL
        .'  置き場所ごとの内訳: '.implode(' ', $breakdown).PHP_EOL
        .'分類 (forbiddenStatementRootPolicies) が意図せず除外側へ倒れていないか確認してください。';

    expect($result['scannedTotal'])->toBeGreaterThan(0, $message);
    expect($result['scannedTotal'])->toBeGreaterThanOrEqual(FORBIDDEN_STATEMENT_SCANNED_FILE_FLOOR, $message);
});

test('テンプレート (blade) も走査している', function (): void {
    $result = forbiddenStatementScanResult();

    expect($result['scannedBladeTotal'])->toBeGreaterThan(0,
        'blade が 1 件も走査されていません。開始タグで開いた区間を見るために '
        .'resources を走査対象から外さないでください。');
});

// ---------------------------------------------------------------------------
// G4 / G5 / G6: 置き場所の分類
// ---------------------------------------------------------------------------

test('追跡 PHP の置き場所がすべて分類済みである', function (): void {
    $result = forbiddenStatementScanResult();
    $classified = array_keys(forbiddenStatementRootPolicies());

    $unclassified = array_values(array_diff($result['trackedRoots'], $classified));

    expect($unclassified)->toBe([],
        '分類されていない置き場所が見つかりました: '.implode(', ', $unclassified).PHP_EOL
        .'forbiddenStatementRootPolicies() へ「走査する / 例外可 / 除外 (理由必須)」の'
        .'いずれかで登録してください (未分類は黙って走査対象から外れます)。');
});

test('除外の登録が形骸化していない (除外した置き場所に追跡 PHP が実在する)', function (): void {
    $result = forbiddenStatementScanResult();

    foreach (forbiddenStatementRootPolicies() as $root => [$policy, $reason]) {
        if ($policy !== ForbiddenStatementRootPolicy::Excluded) {
            continue;
        }

        // ★`toContain()` は可変長で「すべて含む」を検査するため、第 2 引数へ説明文を渡すと
        //   説明文そのものが needle になる。真偽値へ落としてから検査する。
        expect(in_array($root, $result['trackedRoots'], true))->toBeTrue(
            "除外に登録した置き場所 {$root} に追跡 PHP がありません。登録を外してください。");
    }
});

test('除外と例外可の置き場所に 30 文字以上の理由がある', function (): void {
    foreach (forbiddenStatementRootPolicies() as $root => [$policy, $reason]) {
        $needsReason = match ($policy) {
            ForbiddenStatementRootPolicy::ScannedWithExemption,
            ForbiddenStatementRootPolicy::Excluded => true,
            ForbiddenStatementRootPolicy::ScannedNoExemption => false,
        };
        if (! $needsReason) {
            continue;
        }

        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(FORBIDDEN_STATEMENT_REASON_MIN_LENGTH,
            "置き場所 {$root} の理由が短すぎます (".FORBIDDEN_STATEMENT_REASON_MIN_LENGTH.' 文字以上)。');
    }
});

// ---------------------------------------------------------------------------
// G7〜G12: 例外の目録
// ---------------------------------------------------------------------------

test('例外の登録先ファイルが実在する', function (): void {
    foreach (forbiddenStatementExemptions() as $path => $entry) {
        expect(file_exists(base_path($path)))->toBeTrue(
            "例外に登録されたファイルが存在しません: {$path} (登録を外してください)");
    }
});

test('例外の実測件数が登録件数と完全一致する', function (): void {
    $measured = forbiddenStatementCountByPathAndKind(forbiddenStatementScanResult()['sites']);

    foreach (forbiddenStatementExemptions() as $path => $entry) {
        foreach ($entry['counts'] as $kindValue => $count) {
            expect($measured[$path.'|'.$kindValue] ?? 0)->toBe($count,
                "例外の実測件数が登録と一致しません: {$path} の {$kindValue} は登録 {$count} 件、"
                .'実測 '.($measured[$path.'|'.$kindValue] ?? 0).' 件です。'
                .'増減はどちらも再レビューが要ります (目録の件数を更新するか、文を消してください)。');
        }
    }
});

test('例外の根拠が 30 文字以上である', function (): void {
    foreach (forbiddenStatementExemptions() as $path => $entry) {
        expect(mb_strlen($entry['reason']))->toBeGreaterThanOrEqual(FORBIDDEN_STATEMENT_REASON_MIN_LENGTH,
            "例外 {$path} の根拠が短すぎます (".FORBIDDEN_STATEMENT_REASON_MIN_LENGTH.' 文字以上)。');
    }
});

test('例外の登録件数が現在値と完全一致する', function (): void {
    expect(count(forbiddenStatementExemptions()))->toBe(FORBIDDEN_STATEMENT_EXEMPTION_COUNT,
        '例外を増やす / 減らすには FORBIDDEN_STATEMENT_EXEMPTION_COUNT を変える差分が要ります '
        .'(再レビューの強制)。');
});

test('例外を登録できるのは例外可の置き場所だけである', function (): void {
    $policies = forbiddenStatementRootPolicies();

    foreach (forbiddenStatementExemptions() as $path => $entry) {
        $root = forbiddenStatementRootOf($path);

        expect($policies[$root][0] ?? null)->toBe(ForbiddenStatementRootPolicy::ScannedWithExemption,
            "例外を登録できない置き場所です: {$path} (例外可は scripts / tests のみ)。");
    }
});

test('例外の登録内容そのものが正しい', function (): void {
    $kindValues = array_map(
        static fn (ForbiddenStatementKind $kind): string => $kind->value,
        ForbiddenStatementKind::cases()
    );

    foreach (forbiddenStatementExemptions() as $path => $entry) {
        expect($entry['counts'])->not->toBe([], "例外 {$path} の件数が空です。");

        foreach ($entry['counts'] as $kindValue => $count) {
            // ★`toContain()` の第 2 引数は説明文ではなく追加の needle になるため使わない。
            expect(in_array($kindValue, $kindValues, true))->toBeTrue(
                "例外 {$path} に未知の語彙キーがあります: {$kindValue}");
            expect($count)->toBeGreaterThanOrEqual(1,
                "例外 {$path} の {$kindValue} が 1 件未満です (0 件の登録は痕跡なので外してください)。");
        }
    }
});
