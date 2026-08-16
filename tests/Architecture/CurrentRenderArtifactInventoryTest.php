<?php

declare(strict_types=1);

use App\Enums\Security\RenderArtifactSelectionKind;
use App\Support\Security\RenderArtifactSelectionInventory;
use Tests\Support\PhpTokenScan;

/*
 * レンダ成果物の選択式の単一化 (T154) の deny-by-default 目録。
 *
 * 不変条件:
 *   app/ 配下で **render_jobs に対する succeeded 条件つきの直接クエリ**を書いてよいファイルは
 *   `RenderArtifactSelectionInventory` に登録されたものだけである (exact-fit)。
 *   そのうち「**受け取り対象を 1 件選ぶ**」区分 (Canonical) は
 *   `Services/Manual/CurrentRenderArtifact.php` ちょうど 1 ファイルに限る。
 *
 * 走査は PhpTokenScan::normalize() ベース (コメント / docblock 内の出現は数えない)。
 *
 * 検出 (母集団): 同一ファイル内に次の 2 群が**同居**するもの
 *   1. status 群: `JobStatus::Succeeded` の参照 **または** 文字列リテラル 'succeeded'
 *   2. query 根 群: `renderJobs(` / `RenderJob::` (静的呼び出し全般) /
 *      文字列リテラル 'render_jobs' (DB::table('render_jobs') 経路)
 *
 * 免除区分 (SupersessionCriterion) には**機械検査される前提**が付く:
 *   `latest(` / `orderByDesc(` を 1 度も持たず、かつ `where('id', '>' | '<', …)` の
 *   **連続 token 列**を持つ (= 世代の大小比較であって最新 1 件の選択ではない)。
 *   前提が崩れた瞬間に区分ごと再審査になる。
 *
 * 免除区分 (EagerLoadCandidate = 一覧が eager load する候補行の relation) の前提:
 *   `output_path` を 1 度も参照しない (= 受け取れるかを判断しない。決定は Canonical に残る)。
 *   候補行と Canonical が同じ行を指すことは behavioral な parity テストの担当で、
 *   ここが固定するのは「判断を持ち込んでいない」ことだけである。
 *
 * 保証しないもの (誇張しない):
 * - 閉じるのは基本的に**ファイル粒度**の直接クエリである。登録済みファイル内でメソッドを増やして
 *   選択式を書く経路は、**EagerLoadCandidate だけ**個数 pin (succeeded 条件 / `ofMany(` /
 *   `hasOne(` / `RenderKind::Render` が各 1) で赤くなるが、**他の区分では検出しない**
 *   (fail-first は behavioral テストが担う)
 * - 文字列変数経由 (`$s = 'suc'.'ceeded'`)・動的呼び出し・別ファイルへ切り出した同義式・
 *   repository を挟む間接経路には**沈黙する**
 * - succeeded 条件を伴わない別基準の選択 (表示用の最新 job など) は母集団に入らない
 * - 走査根は app/ のみ (routes/ / config/ は見ない)
 */
final class RenderArtifactSelectionScanner
{
    /** @return list<string> 母集団 (status 群 × query 根 群の同居) に該当する app/ 相対パス */
    public static function selectionFiles(): array
    {
        return self::scan(static fn (array $tokens): bool => self::hasSucceededStatusMarker($tokens)
            && self::hasRenderJobQueryRoot($tokens));
    }

    /**
     * status 群: `JobStatus::Succeeded` (部分修飾・完全修飾も末尾セグメントで判定) または
     * 文字列リテラル 'succeeded'。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function hasSucceededStatusMarker(array $tokens): bool
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && trim($token['text'], "'\"") === 'succeeded') {
                return true;
            }
            if (self::classNameAt($tokens, $i) !== 'JobStatus') {
                continue;
            }
            if ($i + 2 >= $count || $tokens[$i + 1]['id'] !== T_DOUBLE_COLON) {
                continue;
            }
            if ($tokens[$i + 2]['id'] === T_STRING && $tokens[$i + 2]['text'] === 'Succeeded') {
                return true;
            }
        }

        return false;
    }

    /**
     * query 根 群: `renderJobs(` / `RenderJob::` / 文字列リテラル 'render_jobs'。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function hasRenderJobQueryRoot(array $tokens): bool
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && trim($token['text'], "'\"") === 'render_jobs') {
                return true;
            }
            if ($i + 1 >= $count) {
                continue;
            }
            if ($token['id'] === T_STRING && $token['text'] === 'renderJobs'
                && $tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(') {
                return true;
            }
            if (self::classNameAt($tokens, $i) === 'RenderJob' && $tokens[$i + 1]['id'] === T_DOUBLE_COLON) {
                return true;
            }
        }

        return false;
    }

    /**
     * SupersessionCriterion の前提: 「最新 1 件の選択」を行う道具を持たず、
     * 世代の大小比較 (`where('id', '>' | '<', …)`) を実際に持つこと。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function supersessionPremiseHolds(array $tokens): bool
    {
        return ! self::hasLatestSelector($tokens) && self::hasIdComparison($tokens);
    }

    /**
     * `latest(` / `orderByDesc(` の呼び出しが 1 度でもあるか。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function hasLatestSelector(array $tokens): bool
    {
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($tokens[$i]['id'] !== T_STRING
                || ! in_array($tokens[$i]['text'], ['latest', 'orderByDesc'], true)) {
                continue;
            }
            if ($tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(') {
                return true;
            }
        }

        return false;
    }

    /**
     * `where` `(` `'id'` `,` `'>'`(または `'<'`) の**連続**した token 列を持つか。
     *
     * 「id と > が同一ファイルに在る」ではなく**列そのもの**を照合する
     * (免除区分に「実は選択式」が紛れ込むのを機械的に防ぐ)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function hasIdComparison(array $tokens): bool
    {
        $count = count($tokens);
        for ($i = 0; $i + 4 < $count; $i++) {
            if ($tokens[$i]['id'] !== T_STRING || $tokens[$i]['text'] !== 'where') {
                continue;
            }
            if ($tokens[$i + 1]['id'] !== null || $tokens[$i + 1]['text'] !== '(') {
                continue;
            }
            if ($tokens[$i + 2]['id'] !== T_CONSTANT_ENCAPSED_STRING
                || trim($tokens[$i + 2]['text'], "'\"") !== 'id') {
                continue;
            }
            if ($tokens[$i + 3]['id'] !== null || $tokens[$i + 3]['text'] !== ',') {
                continue;
            }
            if ($tokens[$i + 4]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (in_array(trim($tokens[$i + 4]['text'], "'\""), ['>', '<'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * EagerLoadCandidate の前提: `output_path` を 1 度も参照しない
     * (受け取れるかの判断を持ち込んでいない = 決定は Canonical に残っている)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function hasOutputPathReference(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($token['id'] === T_STRING && $token['text'] === 'output_path') {
                return true;
            }
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && trim($token['text'], "'\"") === 'output_path') {
                return true;
            }
        }

        return false;
    }

    /**
     * status 群の出現**数** (JobStatus::Succeeded と 'succeeded' リテラルの合計)。
     * 候補行が 1 つだけであることを数で固定するために使う
     * (「1 ファイルまるごと免除」にしない = 同じファイルに 2 本目の選択式を足せない)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function countSucceededStatusMarkers(array $tokens): int
    {
        $count = 0;
        $total = count($tokens);
        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && trim($token['text'], "'\"") === 'succeeded') {
                $count++;

                continue;
            }
            if (self::classNameAt($tokens, $i) !== 'JobStatus') {
                continue;
            }
            if ($i + 2 < $total && $tokens[$i + 1]['id'] === T_DOUBLE_COLON
                && $tokens[$i + 2]['id'] === T_STRING && $tokens[$i + 2]['text'] === 'Succeeded') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * `{name}(` の呼び出し回数。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function countCalls(array $tokens, string $name): int
    {
        $count = 0;
        $total = count($tokens);
        for ($i = 0; $i < $total - 1; $i++) {
            if ($tokens[$i]['id'] === T_STRING && $tokens[$i]['text'] === $name
                && $tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * `{Enum}::{Case}` の参照回数 (部分修飾・完全修飾も末尾セグメントで判定)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function countEnumCaseReferences(array $tokens, string $enum, string $case): int
    {
        $count = 0;
        $total = count($tokens);
        for ($i = 0; $i < $total - 2; $i++) {
            if (self::classNameAt($tokens, $i) !== $enum) {
                continue;
            }
            if ($tokens[$i + 1]['id'] !== T_DOUBLE_COLON) {
                continue;
            }
            if ($tokens[$i + 2]['id'] === T_STRING && $tokens[$i + 2]['text'] === $case) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * `function {name}` の宣言があるか (候補行 relation の名前を pin する)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function declaresFunction(array $tokens, string $name): bool
    {
        $total = count($tokens);
        for ($i = 0; $i < $total - 1; $i++) {
            if ($tokens[$i]['id'] === T_FUNCTION
                && $tokens[$i + 1]['id'] === T_STRING && $tokens[$i + 1]['text'] === $name) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> 指定区分に登録された app/ 相対パス (昇順) */
    public static function filesOfKind(RenderArtifactSelectionKind $kind): array
    {
        $files = [];
        foreach (RenderArtifactSelectionInventory::entries() as $relative => $entry) {
            if ($entry['kind'] === $kind) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * token 位置 $i がクラス名参照であれば末尾セグメントを返す (部分修飾・完全修飾に対応)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function classNameAt(array $tokens, int $i): ?string
    {
        if (! in_array($tokens[$i]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        $segments = explode('\\', ltrim($tokens[$i]['text'], '\\'));

        return end($segments);
    }

    /**
     * @param  callable(list<array{id: int|null, text: string, line: int}>): bool  $matches
     * @return list<string>
     */
    private static function scan(callable $matches): array
    {
        $appDir = self::appDir();
        $found = [];
        foreach (self::phpFiles($appDir) as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException("Failed to read PHP source: {$path}");
            }
            if ($matches(PhpTokenScan::normalize($source))) {
                $found[] = substr($path, strlen($appDir) + 1);
            }
        }
        sort($found);

        return $found;
    }

    /** @return list<array{id: int|null, text: string, line: int}> */
    public static function tokensOf(string $relative): array
    {
        $path = self::appDir().'/'.$relative;
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException("Failed to read PHP source: {$path}");
        }

        return PhpTokenScan::normalize($source);
    }

    public static function appDir(): string
    {
        $appDir = realpath(__DIR__.'/../../app');
        if (! is_string($appDir)) {
            throw new RuntimeException('app/ ディレクトリを解決できません');
        }

        return $appDir;
    }

    /** @return list<string> */
    public static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}

test('ケース 1: 走査母集団が空でない (負のコントロール)', function (): void {
    expect(RenderArtifactSelectionScanner::phpFiles(RenderArtifactSelectionScanner::appDir()))->not->toBeEmpty();
    expect(RenderArtifactSelectionScanner::selectionFiles())->not->toBeEmpty(
        '走査が壊れて母集団が空です (規則が空振りしている = gate が常時緑になる)');
});

test('ケース 2: render_jobs の succeeded 直接クエリを持つ app/ のファイルはすべて目録に登録されている', function (): void {
    $registered = array_keys(RenderArtifactSelectionInventory::entries());
    $unregistered = array_values(array_diff(RenderArtifactSelectionScanner::selectionFiles(), $registered));

    expect($unregistered)->toBe([],
        'render_jobs の succeeded 条件つき直接クエリは CurrentRenderArtifact へ集約するか、'
        .'RenderArtifactSelectionInventory へ区分 + 根拠付きで登録してください (deny-by-default): '
        .implode(', ', $unregistered));
});

test('ケース 3: 目録の全エントリが実在の直接クエリを持つ (exact-fit)', function (): void {
    $selection = RenderArtifactSelectionScanner::selectionFiles();
    $stale = array_values(array_diff(array_keys(RenderArtifactSelectionInventory::entries()), $selection));

    expect($stale)->toBe([],
        '実体を失った目録エントリは削除してください (残置すると gate が常時緑になる): '.implode(', ', $stale));
});

test('ケース 4: Canonical は CurrentRenderArtifact ただ 1 ファイルである', function (): void {
    expect(RenderArtifactSelectionScanner::filesOfKind(RenderArtifactSelectionKind::Canonical))
        ->toBe(['Services/Manual/CurrentRenderArtifact.php']);
});

test('ケース 5: 目録の根拠は 30 文字以上ある', function (): void {
    foreach (RenderArtifactSelectionInventory::entries() as $relative => $entry) {
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30,
            "{$relative} の根拠が短すぎます (30 文字以上)");
    }
});

test('ケース 6: SupersessionCriterion は「最新 1 件の選択」を持たず世代の大小比較を持つ (前提の機械検査)', function (): void {
    $criterionFiles = RenderArtifactSelectionScanner::filesOfKind(
        RenderArtifactSelectionKind::SupersessionCriterion,
    );

    expect($criterionFiles)->not->toBeEmpty();
    foreach ($criterionFiles as $relative) {
        $tokens = RenderArtifactSelectionScanner::tokensOf($relative);
        expect(RenderArtifactSelectionScanner::hasLatestSelector($tokens))->toBeFalse(
            "{$relative} が latest( / orderByDesc( を持ちました。世代交代判定ではなく選択式に"
            .'なっている可能性があります (区分を再審査してください)');
        expect(RenderArtifactSelectionScanner::hasIdComparison($tokens))->toBeTrue(
            "{$relative} に where('id', '>' | '<', …) の世代比較がありません。"
            .'SupersessionCriterion の前提が崩れています');
    }
});

test('ケース 7: EagerLoadCandidate は Models/VideoManual.php ただ 1 ファイルである', function (): void {
    expect(RenderArtifactSelectionScanner::filesOfKind(RenderArtifactSelectionKind::EagerLoadCandidate))
        ->toBe(['Models/VideoManual.php']);
});

test('ケース 8: EagerLoadCandidate の前提 (受け取れるかを判断しない / 候補行はちょうど 1 本)', function (): void {
    $tokens = RenderArtifactSelectionScanner::tokensOf('Models/VideoManual.php');

    // (a) 受け取れるかの判断を持ち込んでいない (決定は Canonical に残る)
    expect(RenderArtifactSelectionScanner::hasOutputPathReference($tokens))->toBeFalse(
        'Models/VideoManual.php が output_path を参照しました。候補行の relation が「受け取れるか」の'
        .'判断を持ち始めた可能性があります (選択式の単一化が崩れるため区分を再審査してください)');

    // (b) 候補行はちょうど 1 本 (「1 ファイルまるごと免除」にしない = 2 本目の選択式を足せない)
    expect(RenderArtifactSelectionScanner::countSucceededStatusMarkers($tokens))->toBe(1,
        'succeeded 条件が 2 つ以上あります。候補行 relation が増えた可能性があるため区分を再審査してください');
    expect(RenderArtifactSelectionScanner::countCalls($tokens, 'ofMany'))->toBe(1,
        'ofMany( が 1 回ではありません (候補行の選び方が増減しています)');
    expect(RenderArtifactSelectionScanner::countCalls($tokens, 'hasOne'))->toBe(1,
        'hasOne( が 1 回ではありません (候補行 relation が増減しています)');

    // (c) 候補行の名前と対象種別を pin する (rename / kind 変更は再審査の合図)
    expect(RenderArtifactSelectionScanner::declaresFunction($tokens, 'latestSucceededRender'))->toBeTrue(
        '候補行 relation latestSucceededRender() が見つかりません (rename したら目録と parity テストを見直すこと)');
    expect(RenderArtifactSelectionScanner::countEnumCaseReferences($tokens, 'RenderKind', 'Render'))->toBe(1,
        '候補行が見る種別 (RenderKind::Render) の参照数が変わりました (preview を混ぜていないか再審査)');

    // **保証しないもの**: これは字句の検査であり、helper へ切り出した同義式・動的呼び出し・
    // 別ファイルへ移した候補 relation は捉えない (母集団の検査は ケース 2 が担う)。
});

test('scanner 自己検証: EagerLoadCandidate の前提検査 (output_path / 個数 / 宣言名)', function (): void {
    $propertyAccess = PhpTokenScan::normalize('<?php $p = $job->output_path;');
    $literal = PhpTokenScan::normalize("<?php \$q->whereNotNull('output_path');");
    $none = PhpTokenScan::normalize("<?php \$q->where('kind', 'render');");
    $commentOnly = PhpTokenScan::normalize("<?php\n// output_path はコメント\nclass Example {}");

    expect(RenderArtifactSelectionScanner::hasOutputPathReference($propertyAccess))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasOutputPathReference($literal))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasOutputPathReference($none))->toBeFalse();
    expect(RenderArtifactSelectionScanner::hasOutputPathReference($commentOnly))->toBeFalse();

    $twoMarkers = PhpTokenScan::normalize(
        "<?php \$a = JobStatus::Succeeded; \$b = 'succeeded';",
    );
    expect(RenderArtifactSelectionScanner::countSucceededStatusMarkers($twoMarkers))->toBe(2);
    expect(RenderArtifactSelectionScanner::countSucceededStatusMarkers($none))->toBe(0);

    $calls = PhpTokenScan::normalize('<?php $q->ofMany([])->ofMany([]);');
    expect(RenderArtifactSelectionScanner::countCalls($calls, 'ofMany'))->toBe(2);
    expect(RenderArtifactSelectionScanner::countCalls($calls, 'hasOne'))->toBe(0);

    $kinds = PhpTokenScan::normalize('<?php $a = RenderKind::Render; $b = RenderKind::Preview;');
    expect(RenderArtifactSelectionScanner::countEnumCaseReferences($kinds, 'RenderKind', 'Render'))->toBe(1);
    expect(RenderArtifactSelectionScanner::countEnumCaseReferences($kinds, 'RenderKind', 'Preview'))->toBe(1);
    expect(RenderArtifactSelectionScanner::countEnumCaseReferences($none, 'RenderKind', 'Render'))->toBe(0);

    $declaration = PhpTokenScan::normalize('<?php class E { public function latestSucceededRender() {} }');
    expect(RenderArtifactSelectionScanner::declaresFunction($declaration, 'latestSucceededRender'))->toBeTrue();
    expect(RenderArtifactSelectionScanner::declaresFunction($declaration, 'other'))->toBeFalse();
});

test('scanner 自己検証: コメント / docblock 内の出現は数えない', function (): void {
    $tokens = PhpTokenScan::normalize(<<<'PHP'
    <?php
    // RenderJob::query()->where('status', JobStatus::Succeeded->value) はコメント
    /** 'render_jobs' と 'succeeded' も docblock */
    class Example {}
    PHP);

    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($tokens))->toBeFalse();
    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($tokens))->toBeFalse();
});

test('scanner 自己検証: status 群と query 根 群の各経路を検出する', function (): void {
    $enum = PhpTokenScan::normalize('<?php $b = $job->status === JobStatus::Succeeded;');
    $qualified = PhpTokenScan::normalize('<?php $b = \App\Enums\Manual\JobStatus::Succeeded;');
    $literal = PhpTokenScan::normalize("<?php \$q->where('status', 'succeeded');");
    $otherCase = PhpTokenScan::normalize('<?php $b = JobStatus::Failed;');

    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($enum))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($qualified))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($literal))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($otherCase))->toBeFalse();

    $relation = PhpTokenScan::normalize('<?php $q = $manual->renderJobs()->get();');
    $staticCall = PhpTokenScan::normalize('<?php $q = RenderJob::query();');
    $staticOther = PhpTokenScan::normalize('<?php $q = RenderJob::whereKey(1);');
    $table = PhpTokenScan::normalize("<?php \$q = DB::table('render_jobs');");
    $unrelated = PhpTokenScan::normalize('<?php $q = $manual->cuts()->get();');

    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($relation))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($staticCall))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($staticOther))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($table))->toBeTrue();
    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($unrelated))->toBeFalse();
});

test('scanner 自己検証: 前提検査は latest( の存在と id 大小比較の不在を捉える', function (): void {
    $supersession = PhpTokenScan::normalize(
        "<?php \$b = RenderJob::query()->where('id', '>', \$job->id)->exists();",
    );
    $olderGeneration = PhpTokenScan::normalize(
        "<?php \$b = RenderJob::query()->where('id', '<', \$job->id)->get();",
    );
    $selection = PhpTokenScan::normalize("<?php \$j = \$manual->renderJobs()->latest('id')->first();");
    $descSelection = PhpTokenScan::normalize("<?php \$j = \$manual->renderJobs()->orderByDesc('id')->first();");
    // 「id と > が同一ファイルに在る」だけでは前提を満たさない (列そのものを照合する)
    $scattered = PhpTokenScan::normalize("<?php \$q->where('kind', 'render')->where('progress', '>', 0);");

    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($supersession))->toBeTrue();
    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($olderGeneration))->toBeTrue();
    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($selection))->toBeFalse();
    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($descSelection))->toBeFalse();
    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($scattered))->toBeFalse();
    expect(RenderArtifactSelectionScanner::hasIdComparison($scattered))->toBeFalse();
});
