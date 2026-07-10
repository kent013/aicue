<?php

declare(strict_types=1);

/*
 * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン固有規約 1) の書き込み経路 inventory。
 *
 * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
 *   対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
 *
 * 経路 (メソッド粒度。docs/architecture.md と対):
 * | 経路 | 書いてよいもの |
 * |---|---|
 * | ScenarioService::save() | cuts / scenario_version / status (rendering·analyzing guard 付き) |
 * | ScenarioService::materializeIntoLockedManual() | cuts / scenario_version / status (analyzing→ready のみ) |
 * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
 * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定) |
 *
 * deny-by-default の token ベース静的走査 (PrismDirectDispatchScanner と同じ token_get_all 流儀。
 * コメント/docblock/文字列リテラル**内容**中の出現は無視する)。走査対象: app/ 配下の .php。
 *
 * 検出 1: 識別子/配列キー 'scenario_version' の出現 → allowlist 外のファイルなら fail
 * 検出 2: 書き込み形 `'status' => ... VideoManualStatus::...` / `->status = ... VideoManualStatus::...`
 *         (`VideoManualStatus::class` = cast 宣言は書き込みでないため除外) → allowlist 外なら fail
 * 検出 3: materializeIntoLockedManual の宣言は ScenarioService.php のみ、
 *         呼び出しは AnalysisPipeline.php のみ (ScenarioService 自身の中の呼び出しも fail =
 *         ファイル単位 allowlist の抜け穴を塞ぐ)
 */
final class ScenarioWritePathScanner
{
    /**
     * 検出 1 の allowlist (app/ 相対パス)。ScenarioDocumentData は読み取り shape の直列化のみ。
     * CaptureTakeService は adopt の 409 (ScenarioConflictException) に current_version を
     * 載せるための読み取りのみ (書き込みは検出 2 が別途 deny する)。
     */
    private const SCENARIO_VERSION_ALLOWED = [
        'Services/Manual/ScenarioService.php',
        'DataTransferObjects/Manual/ScenarioDocumentData.php',
        'Services/Capture/CaptureTakeService.php',
    ];

    /** 検出 2 の allowlist (app/ 相対パス) */
    private const STATUS_WRITE_ALLOWED = [
        'Services/Manual/ScenarioService.php',
        'Services/Manual/AnalysisJobService.php',
    ];

    /**
     * 検出 4a の allowlist: 識別子/配列キー 'adopted_take_id' の出現 (読み書き問わず)。
     * - CaptureTakeService: adopt / 削除時 null 化 (VideoManual 行ロック tx 内 = 唯一の書き込み経路)
     * - Cut.php: relation 宣言 (belongsTo 第 2 引数)
     * - CaptureCutData: 読み取り shape の直列化のみ
     * - MassAssignmentProtectedKeys: 保護キー台帳 (文字列リストのみ)
     */
    private const ADOPTED_TAKE_ID_ALLOWED = [
        'Services/Capture/CaptureTakeService.php',
        'Models/Cut.php',
        'DataTransferObjects/Capture/CaptureCutData.php',
        'Support/Security/MassAssignmentProtectedKeys.php',
    ];

    /**
     * 検出 4b の allowlist: 書き込み形 (`['adopted_take_id' => ...]` 配列キー /
     * `->adopted_take_id =` プロパティ代入)。CaptureCutData の配列キー出現は toArray() の
     * 読み取り直列化 (`'adopted_take_id' => $cut->adopted_take_id`) で、token パターンでは
     * 書き込み (forceFill の配列キー) と区別できないため allowlist に含める
     * (検出 4a が出現ファイル自体を 4 ファイルに固定しているため、新規ファイルへの
     * 書き込みはどちらの検出でも fail する)。
     */
    private const ADOPTED_TAKE_ID_WRITE_ALLOWED = [
        'Services/Capture/CaptureTakeService.php',
        'DataTransferObjects/Capture/CaptureCutData.php',
    ];

    /**
     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
     */
    public static function findViolations(): array
    {
        $appDir = self::appDir();

        $violations = [
            'scenario_version' => [],
            'status_write' => [],
            'materialize_declaration' => [],
            'materialize_call' => [],
            'adopted_take_id' => [],
            'adopted_take_id_write' => [],
        ];

        foreach (self::phpFiles($appDir) as $path) {
            $relative = substr($path, strlen($appDir) + 1);
            $source = file_get_contents($path);
            if ($source === false) {
                throw new RuntimeException("Failed to read PHP source: {$path}");
            }

            if (self::containsScenarioVersionToken($source)
                && ! in_array($relative, self::SCENARIO_VERSION_ALLOWED, true)) {
                $violations['scenario_version'][] = $relative;
            }
            if (self::containsStatusWrite($source)
                && ! in_array($relative, self::STATUS_WRITE_ALLOWED, true)) {
                $violations['status_write'][] = $relative;
            }
            if (self::containsMaterializeDeclaration($source)
                && $relative !== 'Services/Manual/ScenarioService.php') {
                $violations['materialize_declaration'][] = $relative;
            }
            if (self::containsMaterializeCall($source)
                && $relative !== 'Services/Manual/AnalysisPipeline.php') {
                $violations['materialize_call'][] = $relative;
            }
            if (self::containsAdoptedTakeIdToken($source)
                && ! in_array($relative, self::ADOPTED_TAKE_ID_ALLOWED, true)) {
                $violations['adopted_take_id'][] = $relative;
            }
            if (self::containsAdoptedTakeIdWrite($source)
                && ! in_array($relative, self::ADOPTED_TAKE_ID_WRITE_ALLOWED, true)) {
                $violations['adopted_take_id_write'][] = $relative;
            }
        }

        foreach ($violations as $key => $files) {
            sort($files);
            $violations[$key] = $files;
        }

        return $violations;
    }

    /** 識別子 (->scenario_version) または配列キー ('scenario_version') の出現 */
    public static function containsScenarioVersionToken(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }
            [$id, $value] = $token;
            if ($id === T_STRING && $value === 'scenario_version') {
                return true;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING && self::stringLiteralEquals($value, 'scenario_version')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 書き込み形の検出:
     * - `'status' => <式>` の式内 (depth 0 の `,`/`]`/`)` まで) に VideoManualStatus 識別子
     * - `->status = <式>` の式内 (`;` まで) に VideoManualStatus 識別子
     * `VideoManualStatus::class` (cast 宣言) は書き込みでないため除外する。
     */
    public static function containsStatusWrite(string $source): bool
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // パターン A: 'status' => ...
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                && self::stringLiteralEquals($token[1], 'status')) {
                $j = self::nextNonWhitespace($tokens, $i);
                if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW
                    && self::expressionUsesVideoManualStatus($tokens, $j, [',', ']', ')'])) {
                    return true;
                }
            }

            // パターン B: ->status = ...
            if (is_array($token) && $token[0] === T_OBJECT_OPERATOR) {
                $j = self::nextNonWhitespace($tokens, $i);
                if ($j === null || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== 'status') {
                    continue;
                }
                $k = self::nextNonWhitespace($tokens, $j);
                if ($k !== null && $tokens[$k] === '='
                    && self::expressionUsesVideoManualStatus($tokens, $k, [';'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 検出 4a: 識別子 (->adopted_take_id) または配列キー ('adopted_take_id') の出現 */
    public static function containsAdoptedTakeIdToken(string $source): bool
    {
        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }
            [$id, $value] = $token;
            if ($id === T_STRING && $value === 'adopted_take_id') {
                return true;
            }
            if ($id === T_CONSTANT_ENCAPSED_STRING && self::stringLiteralEquals($value, 'adopted_take_id')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 検出 4b: 書き込み形の検出 (検出 2 と同型の token パターン):
     * - `'adopted_take_id' => <式>` (forceFill/update の配列キー)
     * - `->adopted_take_id = <式>` (プロパティ代入)
     */
    public static function containsAdoptedTakeIdWrite(string $source): bool
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // パターン A: 'adopted_take_id' => ...
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                && self::stringLiteralEquals($token[1], 'adopted_take_id')) {
                $j = self::nextNonWhitespace($tokens, $i);
                if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_ARROW) {
                    return true;
                }
            }

            // パターン B: ->adopted_take_id = ...
            if (is_array($token) && $token[0] === T_OBJECT_OPERATOR) {
                $j = self::nextNonWhitespace($tokens, $i);
                if ($j === null || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== 'adopted_take_id') {
                    continue;
                }
                $k = self::nextNonWhitespace($tokens, $j);
                if ($k !== null && $tokens[$k] === '=') {
                    return true;
                }
            }
        }

        return false;
    }

    /** 宣言: `function materializeIntoLockedManual` */
    public static function containsMaterializeDeclaration(string $source): bool
    {
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $j = self::nextNonWhitespace($tokens, $i);
            if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING
                && $tokens[$j][1] === 'materializeIntoLockedManual') {
                return true;
            }
        }

        return false;
    }

    /** 呼び出し: `->materializeIntoLockedManual(` / `::materializeIntoLockedManual(` */
    public static function containsMaterializeCall(string $source): bool
    {
        $tokens = token_get_all($source);
        foreach ($tokens as $i => $token) {
            if (! is_array($token)
                || ! in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            $j = self::nextNonWhitespace($tokens, $i);
            if ($j === null || ! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING
                || $tokens[$j][1] !== 'materializeIntoLockedManual') {
                continue;
            }
            $k = self::nextNonWhitespace($tokens, $j);
            if ($k !== null && $tokens[$k] === '(') {
                return true;
            }
        }

        return false;
    }

    /**
     * 開始 token の直後から式の終端 (depth 0 の $terminators) まで走査し、
     * VideoManualStatus 識別子 (`::class` を除く) が使われていれば true。
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @param  list<string>  $terminators
     */
    private static function expressionUsesVideoManualStatus(array $tokens, int $start, array $terminators): bool
    {
        $count = count($tokens);
        $depth = 0;
        for ($i = $start + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                if ($token === '(' || $token === '[' || $token === '{') {
                    $depth++;

                    continue;
                }
                if ($token === ')' || $token === ']' || $token === '}') {
                    if ($depth === 0 && in_array($token, $terminators, true)) {
                        return false;
                    }
                    $depth--;

                    continue;
                }
                if ($depth === 0 && in_array($token, $terminators, true)) {
                    return false;
                }

                continue;
            }

            [$id, $value] = $token;
            if (! in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $segments = explode('\\', ltrim($value, '\\'));
            if (end($segments) !== 'VideoManualStatus') {
                continue;
            }
            // `VideoManualStatus::class` は casts() 宣言 (書き込みでない) → 除外
            $j = self::nextNonWhitespace($tokens, $i);
            if ($j !== null && is_array($tokens[$j]) && $tokens[$j][0] === T_DOUBLE_COLON) {
                $k = self::nextNonWhitespace($tokens, $j);
                if ($k !== null && is_array($tokens[$k]) && $tokens[$k][0] === T_CLASS) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    private static function stringLiteralEquals(string $literal, string $expected): bool
    {
        return trim($literal, "'\"") === $expected;
    }

    public static function appDir(): string
    {
        $appDir = realpath(__DIR__.'/../../app');
        if (! is_string($appDir)) {
            throw new RuntimeException('app/ ディレクトリを解決できません');
        }

        return $appDir;
    }

    /**
     * @return list<string>
     */
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

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextNonWhitespace(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from + 1; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}

test('scenario_version / status 書き込み / materialize の経路が inventory どおり (deny-by-default)', function (): void {
    $violations = ScenarioWritePathScanner::findViolations();

    expect($violations['scenario_version'])->toBe([],
        'scenario_version に触れてよいのは ScenarioService (書き込み) と ScenarioDocumentData (読み取り shape) のみです: '
        .implode(', ', $violations['scenario_version']));
    expect($violations['status_write'])->toBe([],
        'video_manuals.status の書き込みは ScenarioService / AnalysisJobService のロック済み経路のみです: '
        .implode(', ', $violations['status_write']));
    expect($violations['materialize_declaration'])->toBe([],
        'materializeIntoLockedManual の宣言は ScenarioService.php のみです: '
        .implode(', ', $violations['materialize_declaration']));
    expect($violations['materialize_call'])->toBe([],
        'materializeIntoLockedManual の呼び出しは AnalysisPipeline.php (terminal tx) のみです: '
        .implode(', ', $violations['materialize_call']));
    expect($violations['adopted_take_id'])->toBe([],
        'adopted_take_id に触れてよいのは CaptureTakeService (書き込み) / Cut (relation 宣言) / '
        .'CaptureCutData (読み取り shape) / MassAssignmentProtectedKeys (台帳) のみです: '
        .implode(', ', $violations['adopted_take_id']));
    expect($violations['adopted_take_id_write'])->toBe([],
        'adopted_take_id の書き込み形は CaptureTakeService のロック済み経路のみです: '
        .implode(', ', $violations['adopted_take_id_write']));
});

test('現行コードベースに宣言 1 箇所 + 呼び出し 1 箇所が実在する (degenerate PASS 防止)', function (): void {
    $appDir = ScenarioWritePathScanner::appDir();
    expect(ScenarioWritePathScanner::phpFiles($appDir))->not->toBeEmpty();

    $scenarioService = (string) file_get_contents($appDir.'/Services/Manual/ScenarioService.php');
    $pipeline = (string) file_get_contents($appDir.'/Services/Manual/AnalysisPipeline.php');
    expect(ScenarioWritePathScanner::containsMaterializeDeclaration($scenarioService))->toBeTrue();
    expect(ScenarioWritePathScanner::containsMaterializeCall($pipeline))->toBeTrue();
});

test('scanner 自己検証: コメント内の出現は無視する', function (): void {
    $source = <<<'PHP'
<?php
// $this->materializeIntoLockedManual($m, $s) はコメント
/** 'scenario_version' や 'status' => VideoManualStatus::Ready も docblock */
class Example
{
    public function note(): string
    {
        return 'materializeIntoLockedManual( という文字列リテラル';
    }
}
PHP;

    expect(ScenarioWritePathScanner::containsMaterializeCall($source))->toBeFalse();
    expect(ScenarioWritePathScanner::containsMaterializeDeclaration($source))->toBeFalse();
    expect(ScenarioWritePathScanner::containsScenarioVersionToken($source))->toBeFalse();
    expect(ScenarioWritePathScanner::containsStatusWrite($source))->toBeFalse();
});

test('scanner 自己検証: 呼び出し / 宣言 / 書き込み形を検出する', function (): void {
    $call = <<<'PHP'
<?php
class A { public function go($m, $s): void { $this->materializeIntoLockedManual($m, $s); } }
PHP;
    $declaration = <<<'PHP'
<?php
class B { public function materializeIntoLockedManual($m, array $s): void {} }
PHP;
    $arrayWrite = <<<'PHP'
<?php
use App\Enums\Manual\VideoManualStatus;
class C { public function go($m): void { $m->forceFill(['status' => VideoManualStatus::Ready])->save(); } }
PHP;
    $ternaryWrite = <<<'PHP'
<?php
use App\Enums\Manual\VideoManualStatus;
class D { public function go($m): void { $m->forceFill(['status' => $m->ok ? VideoManualStatus::Ready : VideoManualStatus::Draft])->save(); } }
PHP;
    $propertyWrite = <<<'PHP'
<?php
class E { public function go($m): void { $m->status = \App\Enums\Manual\VideoManualStatus::Ready; } }
PHP;
    $versionKey = <<<'PHP'
<?php
class F { public function go($m): void { $m->forceFill(['scenario_version' => 1])->save(); } }
PHP;

    expect(ScenarioWritePathScanner::containsMaterializeCall($call))->toBeTrue();
    expect(ScenarioWritePathScanner::containsMaterializeDeclaration($declaration))->toBeTrue();
    expect(ScenarioWritePathScanner::containsStatusWrite($arrayWrite))->toBeTrue();
    expect(ScenarioWritePathScanner::containsStatusWrite($ternaryWrite))->toBeTrue();
    expect(ScenarioWritePathScanner::containsStatusWrite($propertyWrite))->toBeTrue();
    expect(ScenarioWritePathScanner::containsScenarioVersionToken($versionKey))->toBeTrue();
});

test('scanner 自己検証: adopted_take_id の出現と書き込み形を検出する (検出 4)', function (): void {
    $arrayWrite = <<<'PHP'
<?php
class I { public function go($cut, $take): void { $cut->forceFill(['adopted_take_id' => $take->id])->save(); } }
PHP;
    $nullWrite = <<<'PHP'
<?php
class J { public function go($cut): void { $cut->forceFill(['adopted_take_id' => null])->save(); } }
PHP;
    $propertyWrite = <<<'PHP'
<?php
class K { public function go($cut, $take): void { $cut->adopted_take_id = $take->id; } }
PHP;
    $read = <<<'PHP'
<?php
class L { public function go($cut): ?int { return $cut->adopted_take_id; } }
PHP;
    $relation = <<<'PHP'
<?php
class M { public function adoptedTake() { return $this->belongsTo(Take::class, 'adopted_take_id'); } }
PHP;
    $comment = <<<'PHP'
<?php
// $cut->adopted_take_id = 1 や ['adopted_take_id' => 1] はコメント
class N {}
PHP;

    expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($arrayWrite))->toBeTrue();
    expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($nullWrite))->toBeTrue();
    expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($propertyWrite))->toBeTrue();
    expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($read))->toBeFalse();
    expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($relation))->toBeFalse();
    expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($comment))->toBeFalse();
    expect(ScenarioWritePathScanner::containsAdoptedTakeIdToken($read))->toBeTrue();
    expect(ScenarioWritePathScanner::containsAdoptedTakeIdToken($relation))->toBeTrue();
    expect(ScenarioWritePathScanner::containsAdoptedTakeIdToken($comment))->toBeFalse();
});

test('現行コードベースに adopted_take_id の書き込みが実在する (検出 4 の degenerate PASS 防止)', function (): void {
    $appDir = ScenarioWritePathScanner::appDir();
    $captureTakeService = (string) file_get_contents($appDir.'/Services/Capture/CaptureTakeService.php');

    expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($captureTakeService))->toBeTrue();
});

test('scanner 自己検証: cast 宣言 (VideoManualStatus::class) と読み取りは検出しない', function (): void {
    $cast = <<<'PHP'
<?php
use App\Enums\Manual\VideoManualStatus;
class G { protected function casts(): array { return ['status' => VideoManualStatus::class]; } }
PHP;
    $read = <<<'PHP'
<?php
class H { public function go($m): array { return ['status' => $m->status->value]; } }
PHP;

    expect(ScenarioWritePathScanner::containsStatusWrite($cast))->toBeFalse();
    expect(ScenarioWritePathScanner::containsStatusWrite($read))->toBeFalse();
});
