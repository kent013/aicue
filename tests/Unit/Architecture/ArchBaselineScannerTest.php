<?php

declare(strict_types=1);

use App\Services\Capture\TakeThumbnailExtractor;
use App\Services\Manual\SopTextExtractor;
use App\Services\Storage\Fakes\FakeObjectStore;
use Pest\ArchPresets\Laravel;
use Pest\ArchPresets\Php;
use Pest\ArchPresets\Security;
use Tests\Support\Architecture\ArchBaseline;
use Tests\Support\Architecture\ArchSurfaceScanner;
use Tests\Support\Architecture\GlobalFunctionCallScanner;
use Tests\Support\Architecture\VendorArchPresetReader;
use Webmozart\Assert\Assert;

/*
 * Pest arch ベースライン (`tests/Architecture/ArchBaselineTest.php`) が使う
 * 3 走査器の positive / negative 固定。
 *
 * gate 自体が「例外の置き場が 1 つであること」「例外登録が腐っていないこと」を守る機構であり、
 * **走査器が壊れたら gate は静かに無力化する**。FakeWiringSourceScannerTest /
 * PrimaryKeyStaticQueryScannerTest と同じ位置づけで、
 * 「何を検出し、何を検出しないか」をここで恒久固定する。
 *
 * ★**合成入力はすべて nowdoc で与える** (実コードとして書かない)。理由は 2 つ:
 *   1. 本ファイル自身が S4 の母集団 (`tests/` 全数) に入るため、実コードで
 *      `\call_user_func(...)` や `->{$m}()` を書くと **S4 が即座に赤くなる**
 *   2. 合成入力なら「母集団が 0 件」と「違反が 0 件」を分離でき、
 *      実コードの件数に検出力を依存させない (AGENTS.md 共通規約 (b) の 3 番目)
 *   準拠実装: `tests/Unit/Architecture/FakeWiringSourceScannerTest.php`。
 *
 * ★**実コードとの結合確認だけは実ファイルを使う** (3 本のみ):
 *   FakeObjectStore (S2 の正例) / SopTextExtractor と TakeThumbnailExtractor
 *   (取り違えの負例。security preset の `extract` と綴りが一致する現実の分岐)。
 *
 * 新しい抜け道を見つけたら、gate を緩めるのではなく**ここにケースを足す**。
 */

/** リポジトリ root の絶対パス (worktree でも正しく解決する)。 */
function archBaselineRepositoryRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * 実クラスの定義ファイルの絶対パス (**クラスから解決する**)。
 *
 * ★実コードとの結合確認 3 本が読むのは「そのクラスの実物」であって特定の置き場所ではない。
 *   パスを直書きするとクラスの移設で静かに腐る上、`app/` 配下のソースパスの直書きは
 *   旧 URL 走査 (`tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php`) の
 *   検出語と綴りが衝突する (走査対象は URL だが、綴りの上では区別が付かない)。
 *   クラス名から引けば綴りの衝突は起きず、結合の意図もそのまま表せる。
 *
 * @param  class-string  $class
 */
function archBaselineClassFile(string $class): string
{
    $file = (new ReflectionClass($class))->getFileName();
    Assert::string($file, "定義ファイルを解決できません: {$class}");

    return $file;
}

// ---------------------------------------------------------------------------
// GlobalFunctionCallScanner (S2 用。使用の証明なので**狭く数える**)
// ---------------------------------------------------------------------------

test('1: FakeObjectStore の実ファイルで sha1 の素の呼び出しを 1 件以上数える', function (): void {
    $counts = GlobalFunctionCallScanner::countCallsInFile(
        archBaselineClassFile(FakeObjectStore::class),
        ['sha1'],
    );

    expect($counts)->toHaveKey('sha1')
        ->and($counts['sha1'])->toBeGreaterThanOrEqual(1);
});

test('2: 完全修飾の \\sha1( を数える', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Demo;

        final class Demo
        {
            public function run(string $key): string
            {
                return \sha1($key);
            }
        }
        PHP;

    expect(GlobalFunctionCallScanner::countCallsInSource($source, ['sha1']))->toBe(['sha1' => 1]);
});

test('3: メソッド宣言・メソッド呼び出しは数えない (実ファイル 2 本 + 合成入力)', function (): void {
    // 実クラスのメソッド宣言 (`public function extract(`) と
    // interface のメソッド宣言。どちらもグローバル関数呼び出しではない。
    $sopTextExtractor = GlobalFunctionCallScanner::countCallsInFile(
        archBaselineClassFile(SopTextExtractor::class),
        ['extract'],
    );
    $takeThumbnailExtractor = GlobalFunctionCallScanner::countCallsInFile(
        archBaselineClassFile(TakeThumbnailExtractor::class),
        ['extract'],
    );

    // メソッド呼び出し (`->extract(` / `?->extract(` / `::extract(`)
    $memberCalls = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(object $extractor): void
            {
                $extractor->extract('a', 'b', 'c');
                $extractor?->extract('a', 'b', 'c');
                Demo::extract('a');
                new extract();
            }
        }
        PHP;

    expect($sopTextExtractor)->toBe(['extract' => 0])
        ->and($takeThumbnailExtractor)->toBe(['extract' => 0])
        ->and(GlobalFunctionCallScanner::countCallsInSource($memberCalls, ['extract']))->toBe(['extract' => 0]);
});

test('4: 接頭辞つき・打ち消しつき・接尾辞つきの 3 形を数えない', function (): void {
    $source = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(string $key): void
            {
                mysha1($key);
                not_sha1($key);
                sha1_file($key);
            }
        }
        PHP;

    expect(GlobalFunctionCallScanner::countCallsInSource($source, ['sha1']))->toBe(['sha1' => 0]);
});

test('5: 修飾名 Foo\\sha1( は別の関数なので数えない', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Demo;

        final class Demo
        {
            public function run(string $key): void
            {
                \App\Other\sha1($key);
                Other\sha1($key);
            }
        }
        PHP;

    expect(GlobalFunctionCallScanner::countCallsInSource($source, ['sha1']))->toBe(['sha1' => 0]);
});

test('6: 大文字小文字を区別する (SHA1( は数えない)', function (): void {
    // Pest 側の突き合わせが `$objectToSearch->name === $use` の完全一致なので、
    // Pest も `SHA1(` を検出しない。S2 は **Pest と同じ粒度**に揃える。
    $source = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(string $key): string
            {
                return SHA1($key);
            }
        }
        PHP;

    expect(GlobalFunctionCallScanner::countCallsInSource($source, ['sha1']))->toBe(['sha1' => 0]);
});

test('7: トークン化できない入力と不在パスは無言で 0 を返さず例外にする', function (): void {
    expect(fn (): array => GlobalFunctionCallScanner::countCallsInSource('<?php final class {{{', ['sha1']))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => GlobalFunctionCallScanner::countCallsInFile(
            dirname(archBaselineClassFile(FakeObjectStore::class)).'/NotExisting.php',
            ['sha1'],
        ))->toThrow(InvalidArgumentException::class);
});

test('8: 0 件でも対象名のキーを残す', function (): void {
    $source = <<<'PHP'
        <?php

        final class Demo {}
        PHP;

    expect(GlobalFunctionCallScanner::countCallsInSource($source, ['sha1', 'tempnam']))
        ->toBe(['sha1' => 0, 'tempnam' => 0]);
});

test('7b: 走査器の公開入口はすべてトークン化できない入力を例外にする (fail-closed)', function (): void {
    // ★共通入口 (`ArchTokenStream`) を信頼するだけにせず、**公開境界ごとに**固定する。
    //   将来どれかが共通入口を外しても、正例だけでは気付けないからである。
    $broken = '<?php final class {{{';

    expect(fn (): array => ArchSurfaceScanner::identifierSites($broken, ['preset']))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => ArchSurfaceScanner::functionNameSites($broken, ['arch']))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => ArchSurfaceScanner::dynamicMemberSites($broken))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => ArchSurfaceScanner::statementTokens($broken, 0))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => ArchSurfaceScanner::tokensBefore($broken, 1, 1))
        ->toThrow(RuntimeException::class)
        ->and(fn (): int => ArchSurfaceScanner::braceDepthAt($broken, 0))
        ->toThrow(RuntimeException::class)
        ->and(fn (): array => VendorArchPresetReader::forbiddenSymbolsFromSource($broken, 1))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// ArchSurfaceScanner::identifierSites (S4 用)
// ---------------------------------------------------------------------------

test('9: コメントと文字列リテラルの中身は識別子として数えない', function (): void {
    $source = <<<'PHP'
        <?php

        /**
         * 既製 preset の同名規則は構文木の扱い上ほぼ働かない。
         * 動的呼び出し (可変メソッド名・可変クラス名・call_user_func 系) には沈黙する。
         */
        final class Demo
        {
            public function run(): string
            {
                // preset を一括で使わないこと
                return 'preset と call_user_func は文字列である';
            }
        }
        PHP;

    expect(ArchSurfaceScanner::identifierSites($source, ['preset', 'call_user_func']))
        ->toBe(['preset' => [], 'call_user_func' => []]);
});

// ---------------------------------------------------------------------------
// ArchSurfaceScanner::statementTokens (S4 用)
// ---------------------------------------------------------------------------

/** 期待形のチェーンを 1 本だけ含む合成ソース。 */
function archBaselineExpectedChainSource(): string
{
    return <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        foreach (ArchBaseline::ruleIds() as $ruleId) {
            test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
                expect(ArchBaseline::symbolsOf($ruleId))
                    ->not->toBeUsed()
                    ->ignoring(ArchBaseline::exceptionsOf($ruleId));
            });
        }
        PHP;
}

/**
 * 合成ソース内のチェーン開始位置 (`expect` の有意トークン添字) を取り出す。
 *
 * ★錨は `toBeUsed` の識別子 1 件。`arch` は禁止名になったので使えず、
 *   `expect` は件数で一意にならない。期待形の中での錨の位置は
 *   `EXPECTED_CHAIN_TOKENS` から引く (期待形の正本を 2 つにしない)。
 */
function archBaselineChainIndex(string $source): int
{
    $anchors = ArchSurfaceScanner::identifierSites($source, [ArchBaseline::CHAIN_ANCHOR_NAME])[ArchBaseline::CHAIN_ANCHOR_NAME];

    expect($anchors)->toHaveCount(1);

    $offset = array_search(ArchBaseline::CHAIN_ANCHOR_NAME, ArchBaseline::EXPECTED_CHAIN_TOKENS, true);

    expect($offset)->toBeInt();

    /** @var int $offset */
    return $anchors[0]['index'] - $offset;
}

test('10: 期待形のチェーンは EXPECTED_CHAIN_TOKENS と完全一致する', function (): void {
    $source = archBaselineExpectedChainSource();

    expect(ArchSurfaceScanner::statementTokens($source, archBaselineChainIndex($source)))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS);
});

test('11: 例外クラスを直書きしたチェーンは期待形と一致しない', function (): void {
    $source = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        foreach (ArchBaseline::ruleIds() as $ruleId) {
            test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
                expect(ArchBaseline::symbolsOf($ruleId))
                    ->not->toBeUsed()
                    ->ignoring([\App\Support\ProductionEnvGuard::class]);
            });
        }
        PHP;

    expect(ArchSurfaceScanner::statementTokens($source, archBaselineChainIndex($source)))
        ->not->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS);
});

test('12: ->not を欠いたチェーンは期待形と一致しない', function (): void {
    // 否定を落とすと「使われていること」を要求する真逆の規則になる。
    // ★`toBeUsed` そのものを落とした形は錨が消えるので、S4-2b の
    //   「識別子がちょうど 1 件」が先に赤くなる (こちらはトークン照合の担当外)。
    $source = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        foreach (ArchBaseline::ruleIds() as $ruleId) {
            test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
                expect(ArchBaseline::symbolsOf($ruleId))
                    ->toBeUsed()
                    ->ignoring(ArchBaseline::exceptionsOf($ruleId));
            });
        }
        PHP;

    expect(ArchSurfaceScanner::statementTokens($source, archBaselineChainIndex($source)))
        ->not->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS);
});

test('13: 開始位置が範囲外 / 文末に達しないまま EOF なら例外にする', function (): void {
    $source = archBaselineExpectedChainSource();

    // 文末 `;` に達する前に EOF になる形 (無言で EOF までの列を返さない)。
    // **構文としては正しい** PHP である (`;` を 1 つも含まないクラス宣言) ため、
    // トークン化の例外ではなくこの分岐が確かに到達する。
    $unterminated = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(): void {}
        }
        PHP;

    expect(fn (): array => ArchSurfaceScanner::statementTokens($source, 100_000))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => ArchSurfaceScanner::statementTokens($unterminated, 1))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// ArchSurfaceScanner::tokensBefore / braceDepthAt (S4 用。囲みの固定)
// ---------------------------------------------------------------------------

test('13b: tokensBefore が直前のトークン列を返し、範囲外は例外にする', function (): void {
    $source = archBaselineExpectedChainSource();
    $index = archBaselineChainIndex($source);
    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);

    expect(ArchSurfaceScanner::tokensBefore($source, $index, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(fn (): array => ArchSurfaceScanner::tokensBefore($source, $index, $index + 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => ArchSurfaceScanner::tokensBefore($source, 100_000, 1))
        ->toThrow(InvalidArgumentException::class);
});

test('13g: tokensAfter が後置の実行修飾を見分け、範囲外は例外にする', function (): void {
    // ★**綴りも登録簿も一致したまま 7 本を評価させない形**。
    //   `test(…)` を閉じたあとに `->skip()` を後置すると、
    //   ヘッダー・表明の文・最上位の制御構造・打ち切りのどれも 1 文字も変わらず、
    //   description は Pest に登録される (= S4-3c の missing も空)。後置だけが違う。
    $expected = archBaselineExpectedChainSource();
    $skipped = str_replace('    });', '    })->skip();', $expected);

    // 置換が実際に起きたこと (負例が負例になっていること) を先に確かめる
    expect($skipped)->not->toBe($expected);

    $footerLength = count(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS);
    $afterOf = static fn (string $source): array => ArchSurfaceScanner::tokensAfter(
        $source,
        archBaselineChainIndex($source) + count(ArchBaseline::EXPECTED_CHAIN_TOKENS),
        $footerLength,
    );

    // 表明の文とヘッダーは**両者で完全に一致する** = 後置を見ないと区別できない
    $skippedIndex = archBaselineChainIndex($skipped);
    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);

    expect(ArchSurfaceScanner::statementTokens($skipped, $skippedIndex))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS)
        ->and(ArchSurfaceScanner::tokensBefore($skipped, $skippedIndex, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        // 後置だけが違う: 期待形は `} ) ; }` / 後置つきは `} ) -> skip`
        ->and($afterOf($expected))->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS)
        ->and($afterOf($skipped))->not->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS)
        ->and($afterOf($skipped))->toBe(['}', ')', '->', 'skip'])
        // 範囲外は黙って短い列を返さず例外にする (fail-closed)
        ->and(fn (): array => ArchSurfaceScanner::tokensAfter($expected, 100_000, 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => ArchSurfaceScanner::tokensAfter($expected, 0, 100_000))
        ->toThrow(InvalidArgumentException::class);
});

test('13c: 波括弧つきの囲みは深さで見抜けるが、波括弧なしの制御構文は見抜けない', function (): void {
    // ★チェーンの綴りは 1 文字も変えずに 7 本の表明を無効化する形。
    //   綴り列の照合だけでは見抜けないので braceDepthAt が要る。
    $topLevel = archBaselineExpectedChainSource();

    $guarded = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        if (false) {
            foreach (ArchBaseline::ruleIds() as $ruleId) {
                test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
                    expect(ArchBaseline::symbolsOf($ruleId))
                        ->not->toBeUsed()
                        ->ignoring(ArchBaseline::exceptionsOf($ruleId));
                });
            }
        }
        PHP;

    // ★**波括弧を持たない制御構文では深さが増えない** — 本走査器の限界であり、
    //   ここで固定しておく。到達可能性の保証は静的な深さでは得られないので、
    //   gate は S4-3c (Pest の登録簿への実行時問い合わせ) で別に証明している。
    $braceless = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        if (false)
            foreach (ArchBaseline::ruleIds() as $ruleId) {
                test(ArchBaseline::descriptionOf($ruleId), function () use ($ruleId): void {
                    expect(ArchBaseline::symbolsOf($ruleId))
                        ->not->toBeUsed()
                        ->ignoring(ArchBaseline::exceptionsOf($ruleId));
                });
            }
        PHP;

    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);
    $topLevelHeader = archBaselineChainIndex($topLevel) - $headerLength;
    $guardedHeader = archBaselineChainIndex($guarded) - $headerLength;
    $bracelessHeader = archBaselineChainIndex($braceless) - $headerLength;

    // 囲みの**綴り**は 3 つとも同じ (だから綴り照合では見抜けない)。
    expect(ArchSurfaceScanner::tokensBefore($guarded, $guardedHeader + $headerLength, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(ArchSurfaceScanner::tokensBefore($braceless, $bracelessHeader + $headerLength, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(ArchSurfaceScanner::braceDepthAt($topLevel, $topLevelHeader))->toBe(0)
        ->and(ArchSurfaceScanner::braceDepthAt($guarded, $guardedHeader))->toBe(1)
        // ★ここが限界の実測: 波括弧なしの `if (false)` では深さが 0 のままである。
        ->and(ArchSurfaceScanner::braceDepthAt($braceless, $bracelessHeader))->toBe(0);
});

test('13d: 文字列補間の開き波括弧も深さに数える', function (): void {
    // `{$a}` は T_CURLY_OPEN + `}` の組なので、開き側を数えないと深さが -1 になり、
    // その後ろにある最上位のチェーンが「囲まれている」と誤判定される。
    $source = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        $a = 'x';
        $label = "値は {$a} です";
        expect(ArchBaseline::symbolsOf($ruleId))->not->toBeUsed()->ignoring(ArchBaseline::exceptionsOf($ruleId));
        PHP;

    expect(ArchSurfaceScanner::braceDepthAt($source, archBaselineChainIndex($source)))->toBe(0);
});

test('13e: 最上位で実行を打ち切る文を拾い、本体の中の同じ語は拾わない', function (): void {
    $topLevel = <<<'PHP'
        <?php

        $a = 1;
        return;
        PHP;

    $inBodies = <<<'PHP'
        <?php

        function demo(): int
        {
            return 1;
        }

        $closure = function (): int {
            if (false) {
                return 2;
            }

            throw new RuntimeException('本体の中');
        };

        final class Demo
        {
            public function run(): int
            {
                return 3;
            }
        }
        PHP;

    $otherAborts = <<<'PHP'
        <?php

        exit;
        die();
        throw new RuntimeException('最上位');
        PHP;

    expect(ArchSurfaceScanner::topLevelAbortSites($topLevel))->toHaveCount(1)
        // 関数・クロージャ・制御構造の本体にある return / throw はファイルの実行を打ち切らない
        ->and(ArchSurfaceScanner::topLevelAbortSites($inBodies))->toBe([])
        ->and(ArchSurfaceScanner::topLevelAbortSites($otherAborts))->toHaveCount(3);
});

test('13f: 最上位の制御構造を拾い、本体の中の同じ語は拾わない', function (): void {
    // ★代替構文 (`if (…): … endif;`) は**波括弧を 1 つも増やさない**ので、
    //   深さの検査でも打ち切りトークンの検査でも現れない。ここだけが見える。
    $alternativeSyntax = <<<'PHP'
        <?php

        if (false):

        foreach ([1] as $i) {
            test('x', function (): void {});
        }

        endif;
        PHP;

    $braceLess = <<<'PHP'
        <?php

        if (false)
        foreach ([1] as $i) {
            test('x', function (): void {});
        }
        PHP;

    $inBodies = <<<'PHP'
        <?php

        declare(strict_types=1);

        test('x', function (): void {
            if (true) {
                foreach ([1] as $i) {
                    while (false) {
                    }
                }
            }

            try {
                $v = match (1) { default => 0 };
            } catch (RuntimeException $e) {
            }
        });
        PHP;

    $names = static fn (string $source): array => array_column(
        ArchSurfaceScanner::topLevelControlStructureSites($source),
        'name',
    );

    // 代替構文・波括弧なしのどちらでも、最上位の `if` と本来の `foreach` の 2 件になる
    expect($names($alternativeSyntax))->toBe(['if', 'foreach'])
        ->and($names($braceLess))->toBe(['if', 'foreach'])
        // 本体の中の制御構造は数えない。`declare` も制御構造ではないので数えない
        ->and($names($inBodies))->toBe([]);
});

test('13h: 最上位の関数呼び出しを拾い、メンバ呼び出しと本体の中は拾わない', function (): void {
    // ★**宣言を残したまま実行だけ止める形**。ファイル単位の `beforeEach` を 1 つ置くと
    //   そのファイルの全テストが skip されるが、綴りも波括弧の深さも登録内容も変わらない。
    //   最上位の呼び出しの一覧だけがここで変わる。
    $expected = archBaselineExpectedChainSource();
    $hooked = str_replace(
        'foreach (',
        "beforeEach(function (): void {\n    \$this->markTestSkipped('probe');\n});\n\nforeach (",
        $expected,
    );

    // 置換が実際に起きたこと (負例が負例になっていること) を先に確かめる
    expect($hooked)->not->toBe($expected);

    $memberCallsOnly = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        // 静的メソッド呼び出し・メソッド呼び出しは数えない (現行 Pest の file-scoped 入口は
        // 素の関数呼び出しから始まる。メンバ呼び出し経由の入口は保証範囲外)
        $ids = ArchBaseline::ruleIds();
        $first = $collection->first();

        test('x', function (): void {
            // 本体の中の素の関数呼び出しも数えない
            sprintf('%s', 'a');
            beforeEach(function (): void {});
        });
        PHP;

    // 合成した期待形は `test(` が `foreach` の**中** (深さ 1) にあるので最上位の呼び出しは 0 件。
    // hook を足すと最上位に 1 件現れる — これが唯一の差である。
    expect(ArchSurfaceScanner::topLevelCallNames($expected))->toBe([])
        ->and(ArchSurfaceScanner::topLevelCallNames($hooked))->toBe(['beforeeach'])
        // 静的メソッド呼び出し・メソッド呼び出し・本体の中の呼び出しは数えない
        ->and(ArchSurfaceScanner::topLevelCallNames($memberCallsOnly))->toBe(['test']);
});

// ---------------------------------------------------------------------------
// チェーン宿主ファイルの**外部**自己検査
// ---------------------------------------------------------------------------

test('38: チェーン宿主ファイルが外部から見て短絡されていない', function (): void {
    // ★**これは別ファイルに置くことが load-bearing である**。
    //   Pest のテストファイルは素のスクリプトなので、`ArchBaselineTest.php` の最上位を
    //   短絡させると禁止表明 7 本も S1〜S5 も**丸ごと登録されなくなる**。
    //   そのとき同ファイルの自己検査 (S4-3c を含む) も一緒に消えるので、
    //   **自己検査では原理的に検出できない**。ここが外側から見張る。
    //   短絡の形は 2 つあり、**片方だけでは塞がらない**:
    //     (a) 実行を打ち切る形 (`return;` を 1 行置く) → `topLevelAbortSites()` が 0 件を要求
    //     (b) 宣言を条件付きにして飛ばす形 (`if (false): … endif;` で丸ごと囲む)
    //         → 打ち切りトークンも波括弧も増えないので (a) にも深さの検査にも現れない。
    //           `topLevelControlStructureSites()` の**ちょうど 1 件**でだけ捕まる
    //   さらに、**登録はされるが評価されない**形も 2 つあり、外から閉じる:
    //     (c) 生成文の**後置** (`})->skip();`) → `EXPECTED_CHAIN_FOOTER_TOKENS` と exact-fit。
    //         この形はヘッダーも表明も最上位の構造も 1 文字も変えないので、
    //         (a)(b) にも S4-3c の登録簿問い合わせにも現れない
    //     (d) **ファイル単位の hook** (`beforeEach(fn () => $this->markTestSkipped())`) →
    //         最上位の呼び出しを `EXPECTED_TOP_LEVEL_CALL_NAMES` (= `test` だけ) に限る。
    //         この形は (a)(b)(c) のどれにも現れず、各テストの**登録内容も新品のまま**なので
    //         S4-3c の factory 差分にも出ない
    //         (実測: 41 本が skip され、**失敗 0 件** = Pest の出力に赤が 1 つも出ない)
    //
    // ★**保証しないもの**: 本ファイル自身が同じ手口で短絡された場合は検出できない
    //   (検査を外から見張る検査は無限に続くので置かない)。最後の砦は git のレビューである。
    $host = archBaselineRepositoryRoot().'/'.ArchBaseline::CHAIN_HOST_FILE;
    $source = file_get_contents($host);

    expect($source)->toBeString();

    /** @var string $source */
    $headerLength = count(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS);
    $start = archBaselineChainIndex($source);

    // 宿主ファイルの最上位にある制御構造は**チェーン自身の `foreach` ちょうど 1 つ**である。
    // 宿主へ最上位の制御構造を足すと (囲む意図が無くても) ここが赤くなる = deny-by-default。
    $controlSites = ArchSurfaceScanner::topLevelControlStructureSites($source);

    expect($controlSites)->toHaveCount(1)
        ->and($controlSites[0]['name'])->toBe('foreach')
        ->and($controlSites[0]['index'])->toBe($start - $headerLength)
        ->and(ArchSurfaceScanner::topLevelAbortSites($source))->toBe([])
        ->and(ArchSurfaceScanner::statementTokens($source, $start))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS)
        ->and(ArchSurfaceScanner::tokensBefore($source, $start, $headerLength))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_HEADER_TOKENS)
        ->and(ArchSurfaceScanner::braceDepthAt($source, $start - $headerLength))->toBe(0)
        ->and(ArchSurfaceScanner::tokensAfter(
            $source,
            $start + count(ArchBaseline::EXPECTED_CHAIN_TOKENS),
            count(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS),
        ))->toBe(ArchBaseline::EXPECTED_CHAIN_FOOTER_TOKENS)
        // 宿主ファイルの最上位の**素の関数呼び出しは `test` だけ** (deny-by-default)。
        // 現行 Pest のファイル単位の hook / 設定の入口 (`beforeEach` / `uses` / `pest` /
        // `describe` …) はすべて素の関数なので、この 1 件の契約で閉じる。
        ->and(ArchSurfaceScanner::topLevelCallNames($source))
        ->toBe(ArchBaseline::EXPECTED_TOP_LEVEL_CALL_NAMES);
});

// ---------------------------------------------------------------------------
// ArchSurfaceScanner::dynamicMemberSites (S4 用)
// ---------------------------------------------------------------------------

test('14: 名前が静的に決まらないメンバ参照 5 形を拾う', function (): void {
    $source = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(object $obj, string $m): void
            {
                $obj->{$m}();
                $obj?->{$m}();
                Demo::{$m}();
                $obj->$m();
                Demo::$m();
            }
        }
        PHP;

    expect(ArchSurfaceScanner::dynamicMemberSites($source))->toHaveCount(5);
});

test('15: 静的プロパティ参照は拾わない (( の有無だけで分かれる)', function (): void {
    // `Demo::$m();` (可変静的メソッド呼び出し) と `Demo::$violations;` (静的プロパティ) を
    // **隣接配置**して、判定が `(` の有無だけで分かれることを固定する。
    $source = <<<'PHP'
        <?php

        final class Demo
        {
            public static array $violations = [];

            public function run(string $m): void
            {
                Demo::$m();
                self::$violations = [];
                Demo::$violations = [];
            }
        }
        PHP;

    expect(ArchSurfaceScanner::dynamicMemberSites($source))->toHaveCount(1);
});

test('16: -> 側は波括弧内がリテラルでも拾う (広く数える)', function (): void {
    $source = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(object $obj): void
            {
                $obj->{'literal'}();
            }
        }
        PHP;

    expect(ArchSurfaceScanner::dynamicMemberSites($source))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// ArchSurfaceScanner::functionNameSites (S4 用。名前解決をしない = 拾いすぎへ倒す)
// ---------------------------------------------------------------------------

/**
 * `functionNameSites()` の結果を status 別の名前リストへ畳む。
 *
 * @return array{call: list<string>, import: list<string>}
 */
function archBaselineSiteSummary(string $source, string $functionName): array
{
    $summary = ['call' => [], 'import' => []];
    foreach (ArchSurfaceScanner::functionNameSites($source, [$functionName]) as $site) {
        $summary[$site['status']][] = $site['name'];
    }

    return $summary;
}

test('17: 完全修飾の呼び出しを call として拾う', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Demo;

        final class Demo
        {
            public function run(callable $fn): void
            {
                \call_user_func($fn);
            }
        }
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func')['call'])->toHaveCount(1);
});

test('18: 修飾名の呼び出しも call として拾う (名前解決しない = 拾いすぎ)', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Demo;

        final class Demo
        {
            public function run(callable $fn): void
            {
                A\B\call_user_func($fn);
            }
        }
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func')['call'])->toHaveCount(1);
});

test('19: namespace 相対の呼び出しも call として拾う', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Demo;

        final class Demo
        {
            public function run(callable $fn): void
            {
                namespace\call_user_func($fn);
            }
        }
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func')['call'])->toHaveCount(1);
});

test('20: 大文字小文字を無視して call を拾う (迂回口を塞ぐ)', function (): void {
    $source = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(callable $fn): void
            {
                \CALL_USER_FUNC($fn);
            }
        }
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func')['call'])->toHaveCount(1);
});

test('21: 別名つき関数取り込みを import として拾う', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Demo;

        use function A\call_user_func as invoke;

        final class Demo {}
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func')['import'])->toHaveCount(1);
});

test('22: カンマ区切りの関数取り込みを import として拾う', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Demo;

        use function A\f, B\call_user_func as g;

        final class Demo {}
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func')['import'])->toHaveCount(1);
});

test('23: group use / mixed group use を import として拾う', function (): void {
    $groupUse = <<<'PHP'
        <?php

        namespace App\Demo;

        use function A\{f, call_user_func as g};

        final class Demo {}
        PHP;

    $mixedGroupUse = <<<'PHP'
        <?php

        namespace App\Demo;

        use A\{function call_user_func};

        final class Demo {}
        PHP;

    expect(archBaselineSiteSummary($groupUse, 'call_user_func')['import'])->toHaveCount(1)
        ->and(archBaselineSiteSummary($mixedGroupUse, 'call_user_func')['import'])->toHaveCount(1);
});

test('24: arch 宣言の糖衣は呼び出しでも別名取り込みでも検出する', function (): void {
    // ★S4-2 は `arch` を **tests/ 全数で 0 件**に固定する。素の呼び出し・完全修飾・
    //   `use function … as …` の別名取り込みのいずれでも検出できることを固定する
    //   (綴りを隠して 2 本目の表明を足す経路を塞ぐ)。
    $plainAndQualified = <<<'PHP'
        <?php

        arch('1 本目')->expect(['sha1'])->not->toBeUsed();
        \arch('2 本目')->expect(['md5'])->not->toBeUsed();
        PHP;

    $aliasImport = <<<'PHP'
        <?php

        use function Pest\arch as architectureRule;

        architectureRule('別名で作った表明')->expect(['sha1'])->not->toBeUsed();
        PHP;

    expect(archBaselineSiteSummary($plainAndQualified, 'arch')['call'])->toHaveCount(2)
        ->and(archBaselineSiteSummary($aliasImport, 'arch')['import'])->toHaveCount(1);
});

test('25: 呼び出し側の接頭辞つき・打ち消しつき・接尾辞つきの 3 形を拾わない', function (): void {
    $source = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(callable $fn): void
            {
                mycall_user_func($fn);
                not_call_user_func($fn);
                call_user_func_x($fn);
            }
        }
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func')['call'])->toBe([]);
});

test('25b: 取り込み側の 3 形も拾わない (セグメントの完全一致であって部分文字列一致ではない)', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Demo;

        use function A\mycall_user_func;
        use function A\not_call_user_func;
        use function A\call_user_func_x;

        final class Demo {}
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func')['import'])->toBe([]);
});

test('25c: 名前空間側の中間セグメントは取り込みとして拾わない', function (): void {
    // `Pest\Arch\Support\Composer` の `Arch` は記号を取り込まない中間セグメントである。
    // ここを拾うと Pint の fully_qualified_strict_types が生む正当なクラス取り込みで
    // gate が赤くなる (実際に ArchBaselineTest がこの形の use を持つ)。
    // 一方で**別名は名前トークンそのもの**なので、末尾セグメントだけ見ても取りこぼさない。
    $intermediateSegment = <<<'PHP'
        <?php

        use Pest\Arch\Repositories\ObjectsRepository;
        use Pest\Arch\Support\Composer;

        final class Demo {}
        PHP;

    $aliasedFunction = <<<'PHP'
        <?php

        use function Some\Namespaced\helper as arch;

        final class Demo {}
        PHP;

    expect(archBaselineSiteSummary($intermediateSegment, 'arch')['import'])->toBe([])
        ->and(archBaselineSiteSummary($aliasedFunction, 'arch')['import'])->toHaveCount(1);
});

test('26: メンバ名と関数宣言は拾わない', function (): void {
    $source = <<<'PHP'
        <?php

        final class Demo
        {
            public function run(object $obj): void
            {
                $obj->call_user_func('a');
                $obj?->call_user_func('a');
                Foo::call_user_func('a');
            }
        }

        function call_user_func(): void {}
        PHP;

    expect(archBaselineSiteSummary($source, 'call_user_func'))->toBe(['call' => [], 'import' => []]);
});

test('26b: ignoring / toBeUsed を functionNameSites は 0 件で返す (メンバ名だから)', function (): void {
    $source = archBaselineExpectedChainSource();

    expect(ArchSurfaceScanner::functionNameSites($source, ArchBaseline::SINGLE_MEMBER_NAMES))->toBe([]);
});

test('26c: 同じソースで identifierSites は ignoring / toBeUsed を各 1 件取る', function (): void {
    $sites = ArchSurfaceScanner::identifierSites(
        archBaselineExpectedChainSource(),
        ArchBaseline::SINGLE_MEMBER_NAMES,
    );

    expect($sites['ignoring'])->toHaveCount(1)
        ->and($sites['toBeUsed'])->toHaveCount(1);
});

test('26d: 同じ行に複数の呼び出しがあっても添字から文を一意に切り出せる', function (): void {
    // 行番号では一意にならない形 (1 行に 2 つの呼び出しが同居する)。
    // ★S4 が行番号ではなく有意トークンの添字を使う理由の裏取りである。
    $source = <<<'PHP'
        <?php

        use Tests\Support\Architecture\ArchBaseline;

        $length = strlen('abc'); expect(ArchBaseline::symbolsOf($ruleId))->not->toBeUsed()->ignoring(ArchBaseline::exceptionsOf($ruleId));
        PHP;

    expect(ArchSurfaceScanner::statementTokens($source, archBaselineChainIndex($source)))
        ->toBe(ArchBaseline::EXPECTED_CHAIN_TOKENS);
});

// ---------------------------------------------------------------------------
// VendorArchPresetReader (S5 用)
// ---------------------------------------------------------------------------

test('28: 3 preset が非空で代表語彙を含み、和集合が ArchBaseline::allSymbols() と一致する', function (): void {
    $php = VendorArchPresetReader::forbiddenSymbolsOf(Php::class);
    $security = VendorArchPresetReader::forbiddenSymbolsOf(Security::class);
    $laravel = VendorArchPresetReader::forbiddenSymbolsOf(Laravel::class);

    $union = array_values(array_unique([...$php, ...$security, ...$laravel]));
    sort($union);

    expect($php)->not->toBeEmpty()
        ->and($security)->not->toBeEmpty()
        ->and($laravel)->not->toBeEmpty()
        ->and($php)->toContain('dump')
        ->and($security)->toContain('sha1')
        ->and($laravel)->toContain('env')
        ->and($union)->toBe(ArchBaseline::allSymbols());
});

test('29: 抽出できない形はすべて例外にする (fail-closed)', function (): void {
    $noArray = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect('App\Providers')->not->toBeUsed();
            }
        }
        PHP;

    $twoArrays = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect(['sha1'])->not->toBeUsed();
                expect(['md5'])->not->toBeUsed();
            }
        }
        PHP;

    $variableElement = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect(['sha1', $name])->not->toBeUsed();
            }
        }
        PHP;

    $keyedElement = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect(['a' => 'sha1'])->not->toBeUsed();
            }
        }
        PHP;

    $spread = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect(['sha1', ...$more])->not->toBeUsed();
            }
        }
        PHP;

    $nested = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect(['sha1', ['md5']])->not->toBeUsed();
            }
        }
        PHP;

    $doubleQuoted = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect(["sha1"])->not->toBeUsed();
            }
        }
        PHP;

    $unknownEscape = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect(['sha\n1'])->not->toBeUsed();
            }
        }
        PHP;

    foreach ([$noArray, $twoArrays, $variableElement, $keyedElement, $spread, $nested, $doubleQuoted, $unknownEscape] as $source) {
        expect(fn (): array => VendorArchPresetReader::forbiddenSymbolsFromSource($source, 1))
            ->toThrow(RuntimeException::class);
    }
});

test('30: バックスラッシュと単引用符のエスケープを解く', function (): void {
    $source = <<<'PHP'
        <?php

        final class Preset
        {
            public function execute(): void
            {
                expect(['a\\b', 'c\'d'])->not->toBeUsed();
            }
        }
        PHP;

    expect(VendorArchPresetReader::forbiddenSymbolsFromSource($source, 1))
        ->toBe(['a\\b', "c'd"]);
});
