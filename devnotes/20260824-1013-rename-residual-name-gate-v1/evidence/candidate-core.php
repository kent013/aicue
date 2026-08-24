<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * 家系 (機能台帳 lctl の feature `rename-residual-name-gate` / 正典 v1
 * 「出現特定式許可台帳 — 空縮退可」) の追従。
 * 改名で退いた旧名が、リポジトリの**現役の資産**に 1 件も残っていないことを機械で見張る。
 *
 * 裁定 AG-085 は「同じ関心事に名前が 2 つある状態」を、追従判断のたびに
 * 「欠落か別名か」の実読が発生することを理由に禁じている。2026-08-10 の裁定で
 * ファイル数の統合は撤回され、残る要件はこの名前の一意性だけである。
 *
 * 判定の骨子 (正典 v1):
 * - 母集団は **git 追跡下の全ファイル**。**内容とパス名の両方**を照合する
 *   (中身を直しただけのファイルが旧名のパスで復活する経路は内容走査では塞げない)。
 * - 旧名が現れてよいのは「いつ・誰が・何をしたかの記録」だけで、
 *   **出現を 1 つずつ特定する申告** (対象ファイル / 旧名 / その出現を一意に特定する
 *   周辺文字列 / 残す理由) を台帳に並べる。**行番号は使わない** (無関係な編集で動くため)。
 *   **件数は申告の本数から導く** (件数の pin を別に持たない = 二重管理を作らない)。
 * - 突き合わせは 3 方向で落ちる — 申告外の出現がある / 申告があるのに実物から消えた
 *   (周辺文字列が 1 回に特定できない) / 申告が同じ出現を二重に指している。
 *   この 3 つが「申告数と実出現数の不一致」を含意する。
 * - **パス名に申告の口は無い** (記録としてファイル名に旧名が要る事案は無いため 0 件固定)。
 *
 * ★保証範囲を誇張しない:
 *   - 見るのは**字面**である。旧名を分割して連結する書き方・別名の定数経由・
 *     動的に組み立てた文字列には**沈黙する**。
 *   - **丸ごと除外した 2 つ (`devnotes/` 配下と本ファイル自身) の中では沈黙する**。
 *     そこに旧名を書いても本検査は検出しない。
 *   - 申告について保証するのは「周辺文字列が実物にちょうど 1 回あり、それが指す出現の集合が
 *     実出現の集合と一致する」ことまでである。**その記録が意味として妥当かは人のレビュー**が見る。
 *   - 家系名が「正しい名前であること」は検査できない。正本は機能台帳であり、
 *     本検査が固定するのは「旧名が現役の資産に残っていないこと」だけである。
 *   - git 未追跡のファイルは母集団に入らない (境界は commit / CI であり、そこでは追跡下にある)。
 *
 * ★走査器共通規約 (AGENTS.md) との関係:
 *   - (a) は対象外 — クラス名を**連続した字面**として探す走査で、名前参照の解決を行わない。
 *   - (b) fail-closed — `git ls-files` の失敗と読めないファイルは**例外**にする (空集合にしない)。
 *   - (c) 検出力は N-4 の負のコントロールが**同じ純関数**を通して裏取りする。
 *   - (d) 集めた走査結果はすべて判定に使う (数えるだけの目録を持たない)。
 *   - (e) は対象外 — 区切り文字でトークン化した完全一致にすると、実在する接尾辞つきの出現
 *     (`docs/TODO-closed.md` の `FakeExternalsServiceProviderTest`) を**見逃す**方向へ倒れる。
 *     本検査は許可語の除去や否定形の語彙判定を持たないため (e) の母集団に入らない。
 *
 * ★`Tests\Support\TrackedPhpSourceFiles` / `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets`
 *   との関係: 3 者は同じ作法 (`git ls-files`) を使うが**母集団の定義が違う**兄弟である。
 *   前者は `.php` 全数、後者は走査根 8 本 (`docs/` と `.claude/` を見ない)、本検査は
 *   **追跡下の全ファイル**である。本 feature の主敵は規約文書・スキル・手順書に残る旧名なので
 *   `docs/` と `.claude/` を母集団から外せない。列挙を 2 本持つのではなく対象の定義が違う。
 *   関心事の境界 (撤去物の不在 = surface-removal-absence-gate / 旧名の残留 = 本検査) は
 *   機能台帳が名指しで分けている。
 *
 * ★申し送り: 裁定 AG-085 の 3 件目 (並列枠数上限の検査の名前) は feature `bughunt-runtime` の
 *   管轄で、本検査の写像には**入っていない**。将来その改名を行うときは写像へ 1 件足すこと。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 退役した名前 → 家系の名前。
 *
 * 出典は機能台帳の機能 bughunt-runtime
 * (aigenba / metamovics / laravel-claude-template の実測記録)。
 *
 * @var array<string, string>
 */
const BUGHUNT_RETIRED_NAMES = [
    'BughuntBillingSeeder' => 'BughuntStripeSyncSeeder',
    'FakeExternalsServiceProvider' => 'BughuntFakesServiceProvider',
];

/**
 * 旧名の出現を許す場所の申告台帳 (**出現を 1 つずつ特定する**)。
 *
 * `needle` = その出現を一意に特定する周辺文字列。実ファイルに**ちょうど 1 回**現れ、
 * 対象の旧名を**ちょうど 1 回**含むこと。`reason` = 残す理由 (30 文字以上)。
 * **件数は申告の本数**であり、別に pin しない。
 *
 * ★**記録を動かすときは同じ変更で申告も動かす** (意図的な摩擦)。作業台帳の行が
 *   `docs/TODO.md` から `docs/TODO-closed.md` へ移ったら、正しい直し方は
 *   「記録を書き換える」のではなく**申告を足す・移す・外す**である。
 * ★申告 0 件の登録は書かない (実物から消えた申告と区別できないため)。`docs/TODO.md` は
 *   現在旧名 0 件なので**登録そのものを持たない** — deny-by-default なので 1 つ現れれば赤になる。
 *
 * @var array<string, array<string, list<array{needle: string, reason: string}>>>
 */
const BUGHUNT_NAMING_DECLARED_OCCURRENCES = [
    'docs/TODO-closed.md' => [
        'BughuntBillingSeeder' => [
            [
                'needle' => '・BughuntBillingSeeder (有料プラン組織のみ active subscription',
                'reason' => 'T015 (bug-hunt 基盤整備) の完了行。当時作った seeder の名前は当時の事実であり、書き換えると記録が嘘になる',
            ],
            [
                'needle' => '`database/seeders/BughuntBillingSeeder` → `BughuntStripeSyncSeeder`',
                'reason' => 'T214 (家系名への改名) の完了行が持つ改名の対応表。旧名側を消すと何を何へ改名したのかが読めなくなる',
            ],
        ],
        'FakeExternalsServiceProvider' => [
            [
                'needle' => '・FakeExternalsServiceProvider (flag + 環境 allowlist',
                'reason' => 'T015 (bug-hunt 基盤整備) の完了行。当時作った provider の名前は当時の事実であり、書き換えると記録が嘘になる',
            ],
            [
                'needle' => '`FakeExternalsServiceProviderTest` は 6 test ではなく 8 test',
                'reason' => 'T119 (fake 配線の実証検査) の完了行が持つ台帳との食い違いの記録。当時のテストクラス名を指しており改名できない',
            ],
            [
                'needle' => '`app/Providers/FakeExternalsServiceProvider` → `BughuntFakesServiceProvider`',
                'reason' => 'T214 (家系名への改名) の完了行が持つ改名の対応表。旧名側を消すと何を何へ改名したのかが読めなくなる',
            ],
        ],
    ],
];

/**
 * 丸ごと走査から外す置き場所 (repo 相対の接頭辞)。
 *
 * `devnotes/` の設計・レビュー・走行記録は 190 ファイル規模で旧名を含み、出現ごとの申告が
 * 実務にならない (`ForbiddenStatementTokenInvariantTest` が devnotes を理由付きで
 * 除外するのと同じ扱い)。
 *
 * ★**保証の穴として明記する**: ここでは旧名の再流入に沈黙する。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_EXCLUDED_PREFIXES = ['devnotes/'];

/**
 * 丸ごと走査から外す唯一のファイル = 本テスト自身。
 *
 * 申告の needle と負のコントロールの入力として旧名を持つため、自分を走査すると必ず自分で赤くなる。
 * **保証の穴として明記する**: 本ファイルの中に旧名を書いても本検査は沈黙する。
 * 骨抜きにならないことは (1) 申告が実出現と一致すること (N-3) と
 * (2) 家系名を実際に見つける正の対照 (N-2) の 2 つで担保する。
 */
const BUGHUNT_NAMING_SELF_PATH = 'tests/Architecture/BughuntNamingResidualTest.php';

/**
 * 置き換え先 (家系名) の番兵。家系名 => その名前が実在するファイル。
 *
 * 正典の要求「置き換え先が実在しかつ git 追跡下にあること」を満たす
 * (未追跡だと母集団に入らず走査が空振りする)。N-2 は**同じ読み取り機構**で
 * この名前を実際に見つけることまで確かめる (正の対照)。
 *
 * @var array<string, string>
 */
const BUGHUNT_NAMING_CANONICAL_SENTINELS = [
    'BughuntStripeSyncSeeder' => 'database/seeders/BughuntStripeSyncSeeder.php',
    'BughuntFakesServiceProvider' => 'app/Providers/BughuntFakesServiceProvider.php',
];

/**
 * 走査の母集団が空振りでないことを確かめる参照側の代表パス。
 *
 * @var list<string>
 */
const BUGHUNT_NAMING_SENTINEL_PATHS = [
    'bootstrap/providers.php',
    'scripts/bug-hunt-shard.sh',
];

/** 母集団の下限 (これを下回ったら列挙そのものを疑う) */
const BUGHUNT_NAMING_MINIMUM_TRACKED_FILES = 500;

/** 申告の理由に求める最小文字数 (本リポジトリの目録の作法に合わせる)。 */
const BUGHUNT_NAMING_MINIMUM_REASON_LENGTH = 30;

/**
 * `$haystack` 内の `$needle` の出現位置 (バイト位置、昇順)。
 *
 * ★重なり合う出現も**別の出現として数える** (見逃さない側へ倒す)。
 * ★空文字は出現位置を持たないので例外にする (申告の書き方の誤り)。
 *
 * @return list<int>
 */
function bughuntNamingOffsetsOf(string $haystack, string $needle): array
{
    if ($needle === '') {
        throw new RuntimeException('空文字は出現位置を持たない (旧名の残留検査の申告の書き方の誤り)');
    }

    $offsets = [];
    $from = 0;

    while (($at = strpos($haystack, $needle, $from)) !== false) {
        $offsets[] = $at;
        $from = $at + 1;
    }

    return $offsets;
}

/**
 * 1 ファイル分の違反 (純関数 = 負のコントロールが**同じ述語**を通せる)。
 *
 * 申告台帳は**引数で受ける** — 負のコントロールが実ファイルの内容に依存しないため。
 *
 * @param  array<string, array<string, list<array{needle: string, reason: string}>>>  $declarations
 * @return list<string>
 */
function bughuntNamingViolationsIn(string $relative, string $content, array $declarations): array
{
    if ($relative === BUGHUNT_NAMING_SELF_PATH) {
        return [];
    }

    foreach (BUGHUNT_NAMING_EXCLUDED_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return [];
        }
    }

    $violations = [];

    foreach (BUGHUNT_RETIRED_NAMES as $retired => $canonical) {
        // (1) パス名の照合 — 申告の口は無い (0 件固定)。
        if (str_contains($relative, $retired)) {
            $violations[] = sprintf(
                'パス名に旧名が復活している: %s (旧名 %s / 家系名 %s) — パスごと家系名へ改名すること'
                .' (パス名には申告の口が無い)',
                $relative,
                $retired,
                $canonical
            );
        }

        // (2) 内容の照合 — 実出現の位置集合と、申告が指す位置集合を突き合わせる。
        $actual = bughuntNamingOffsetsOf($content, $retired);
        $declared = [];

        foreach ($declarations[$relative][$retired] ?? [] as $entry) {
            $inner = bughuntNamingOffsetsOf($entry['needle'], $retired);

            if (count($inner) !== 1) {
                $violations[] = sprintf(
                    '申告の周辺文字列が旧名をちょうど 1 回含まない: %s / 旧名 %s / 周辺文字列 "%s" (含む回数 %d)'
                    .' / 理由: %s — 出現を 1 つだけ指す文字列に書き直すこと',
                    $relative,
                    $retired,
                    $entry['needle'],
                    count($inner),
                    $entry['reason']
                );

                continue;
            }

            $hits = bughuntNamingOffsetsOf($content, $entry['needle']);

            if (count($hits) !== 1) {
                $violations[] = sprintf(
                    '申告が出現を特定できない: %s / 旧名 %s / 周辺文字列 "%s" が %d 回 (ちょうど 1 回であること)'
                    .' / 理由: %s — 記録を書き換えるのではなく、申告を足す・移す・外すこと',
                    $relative,
                    $retired,
                    $entry['needle'],
                    count($hits),
                    $entry['reason']
                );

                continue;
            }

            $declared[] = $hits[0] + $inner[0];
        }

        sort($declared);

        // 有効な申告位置は構築上必ず実出現位置に含まれる (周辺文字列は本文にちょうど 1 回・
        // 旧名をちょうど 1 回) ため、逆向きの差分は常に空になる。だから片方向だけを見る。
        $undeclared = array_values(array_diff($actual, $declared));

        if ($undeclared !== []) {
            $violations[] = sprintf(
                '申告外の出現がある: %s / 旧名 %s (家系名 %s) / 実出現 %d 件・申告 %d 件'
                .' / 未申告の位置 %s — 改名の取りこぼしなら家系名へ直すこと。記録として残すなら、'
                .'記録を書き換えるのではなく、申告を足す・移す・外すこと',
                $relative,
                $retired,
                $canonical,
                count($actual),
                count($declared),
                implode(', ', array_map('strval', $undeclared))
            );
        }

        if (count($declared) !== count(array_unique($declared))) {
            $violations[] = sprintf(
                '申告が同じ出現を二重に指している: %s / 旧名 %s / 実出現 %d 件・申告 %d 件'
                .' — 記録を書き換えるのではなく、申告を足す・移す・外すこと',
                $relative,
                $retired,
                count($actual),
                count($declared)
            );
        }
    }

    return $violations;
}

/**
 * git 追跡下の全ファイル (repo 相対パス、昇順)。
 *
 * ★対象は拡張子を問わない (シェル / 文書 / 環境ひな型も見る)。
 * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
 *
 * @return list<string>
 */
function bughuntNamingTrackedFiles(): array
{
    $process = new Process(['git', 'ls-files', '-z'], base_path());
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

        $files[] = $relative;
    }

    sort($files);

    return $files;
}

/**
 * 追跡下ファイルの中身を読む (読み取り失敗を空文字で握り潰さない)。
 *
 * 走査結果が空であることを「違反なし」と解釈する gate なので、読めなかったファイルは
 * 必ず名指しで落とす。
 */
function bughuntNamingSourceOf(string $relative): string
{
    $absolute = base_path($relative);
    $content = @file_get_contents($absolute);

    if (! is_string($content)) {
        throw new RuntimeException("追跡下ファイルを読み取れない (旧名の残留検査の走査対象): {$relative}");
    }

    return $content;
}
