<?php

declare(strict_types=1);

use App\Enums\Mcp\ToolName;
use App\Mcp\Tools\AppMcpTool;
use App\Mcp\Tools\WhoamiTool;
use App\Services\Help\Generators\McpToolReferenceGenerator;
use App\Services\Help\McpToolMetadata;
use App\Services\Help\McpToolScanner;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Tests\Support\Help\HelpTestTree;

/*
 * MCP ツール一覧の生成 (I2) と、vendor のメタデータの形が変わったら
 * **静かに欠けずに止まる** こと (I14) を固定する。
 */

afterEach(function (): void {
    HelpTestTree::cleanup();
});

/** 一時走査根を使う生成器を組み立てる。 */
function helpGeneratorOver(string $root): McpToolReferenceGenerator
{
    return new McpToolReferenceGenerator(new McpToolScanner($root), app());
}

test('生成器のキーは manifest と突き合わせる `mcp-tools` である', function (): void {
    expect(app(McpToolReferenceGenerator::class)->key())->toBe('mcp-tools');
});

test('出力は決定的である (同じ実装からは同じバイト列が出る)', function (): void {
    $generator = app(McpToolReferenceGenerator::class);

    expect($generator->generate())->toBe($generator->generate());
});

test('出力は先頭に自動生成の断り書きを持ち、末尾は改行 1 個で終わる', function (): void {
    $markdown = app(McpToolReferenceGenerator::class)->generate();

    expect($markdown)->toStartWith('<!-- 自動生成:')
        ->and($markdown)->toEndWith("\n")
        ->and(str_ends_with($markdown, "\n\n"))->toBeFalse();
});

test('パラメータを持つツールは表で、持たないツールは「パラメータなし。」で書かれる', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-shape');
    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureNoParamTool', 'Whoami');
    HelpTestTree::writeToolFixture(
        $root,
        'GeneratorFixtureParamTool',
        'ListProjects',
        'パラメータ付きの見本',
        "return ['project_id' => \$schema->integer()->description('Project ID')->required(), 'page' => \$schema->integer()];",
    );

    $markdown = helpGeneratorOver($root)->generate();

    expect($markdown)->toContain('現在のツール数: 2')
        ->and($markdown)->toContain('パラメータなし。')
        ->and($markdown)->toContain('| パラメータ | 型 | 必須 | 説明 |')
        ->and($markdown)->toContain('| `project_id` | integer | 必須 | Project ID |')
        ->and($markdown)->toContain('| `page` | integer | 任意 |  |');
});

test('説明の縦棒と改行は表を壊さないように無害化される', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-escape');
    HelpTestTree::writeToolFixture(
        $root,
        'GeneratorFixtureEscapeTool',
        'Whoami',
        "縦棒 | と\n改行を含む説明",
    );

    $markdown = helpGeneratorOver($root)->generate();

    expect($markdown)->toContain('縦棒 \\| と 改行を含む説明')
        ->and($markdown)->not->toContain("縦棒 | と\n改行");
});

test('ツールは name の昇順で並ぶ', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-order');
    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureOrderWhoamiTool', 'Whoami');
    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureOrderListItemsTool', 'ListItems');

    $markdown = helpGeneratorOver($root)->generate();

    expect(strpos($markdown, '## `list-items`'))->toBeLessThan((int) strpos($markdown, '## `whoami`'));
});

test('type が文字列の配列 (union / nullable) なら縦棒連結の表示文字列へ正規化される', function (): void {
    $metadata = McpToolMetadata::fromSchema(
        ['type' => 'object', 'properties' => ['nick' => ['type' => ['string', 'null']]]],
        WhoamiTool::class,
        'fixture',
        '',
    );

    expect($metadata->parameters[0]->type)->toBe('string|null');
});

test('type が未宣言なら (未宣言) へ正規化される (閉じた集合で弾かない)', function (): void {
    $metadata = McpToolMetadata::fromSchema(
        ['type' => 'object', 'properties' => ['loose' => ['description' => 'なんでも']]],
        WhoamiTool::class,
        'fixture',
        '',
    );

    expect($metadata->parameters[0]->type)->toBe('(未宣言)')
        ->and($metadata->parameters[0]->description)->toBe('なんでも')
        ->and($metadata->parameters[0]->required)->toBeFalse();
});

test('properties も required も無い schema はパラメータ 0 件として受け入れる', function (): void {
    $metadata = McpToolMetadata::fromSchema(['type' => 'object'], WhoamiTool::class, 'fixture', '');

    expect($metadata->parameters)->toBe([]);
});

dataset('vendor メタデータの想定外の形', [
    // [schema, 分岐固有の文言, 追加で必ず現れる語 (パラメータ名 / キー名)]
    '最上位の type が無い' => [
        ['properties' => ['a' => ['type' => 'string']]],
        '最上位の type',
        [],
    ],
    '最上位の type が object でない' => [
        ['type' => 'array', 'properties' => ['a' => ['type' => 'string']]],
        '最上位の type',
        [],
    ],
    '最上位に未知のキーがある (properties の改名)' => [
        ['type' => 'object', 'fields' => ['a' => ['type' => 'string']]],
        '最上位に未知のキーがあります',
        ['fields'],
    ],
    'type が数値' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 1]]],
        'の type が文字列でも文字列の配列でもありません',
        ['a'],
    ],
    'type が object (連想配列)' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => ['first' => 'string']]]],
        'の type が非空の list ではありません',
        ['a'],
    ],
    'type が空配列' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => []]]],
        'の type が非空の list ではありません',
        ['a'],
    ],
    'type の要素が文字列でない' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => ['string', 3]]]],
        'の type に非空の文字列でない要素があります',
        ['a'],
    ],
    'description が文字列でない' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'description' => ['x']]]],
        'の description が文字列ではありません',
        ['a'],
    ],
    'パラメータ定義が配列でない' => [
        ['type' => 'object', 'properties' => ['a' => 'string']],
        'の定義が配列ではありません',
        ['a'],
    ],
    'properties が配列でない' => [
        ['type' => 'object', 'properties' => 'nope'],
        'schema の properties が配列ではありません',
        [],
    ],
    'required が list でない' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a' => true]],
        'schema の required が list ではありません',
        [],
    ],
    'required の要素が空文字' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['']],
        'schema の required に非空の文字列でない要素があります',
        [],
    ],
    'required に重複がある' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a', 'a']],
        'schema の required に重複があります',
        ['a'],
    ],
    'required が properties に無い名前を指す' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['b']],
        'が properties にありません',
        ['b'],
    ],
    'required があるのに properties が無い' => [
        ['type' => 'object', 'required' => ['a']],
        'schema に required があるのに properties がありません',
        [],
    ],
]);

test('想定外の形は静かに欠けず、分岐ごとに固有の文言で止まる', function (array $schema, string $branchPhrase, array $expectedMentions): void {
    $call = fn (): McpToolMetadata => McpToolMetadata::fromSchema($schema, WhoamiTool::class, 'fixture', '');

    expect($call)->toThrow(RuntimeException::class);

    try {
        $call();
    } catch (RuntimeException $e) {
        $message = $e->getMessage();

        // 全負例で共通: 対象クラス名 / 何が起きたか (vendor の形が変わった) / 直し方 (直す先の型)
        expect($message)->toContain(WhoamiTool::class)
            ->and($message)->toContain('vendor')
            ->and($message)->toContain('McpToolMetadata');

        // ★分岐固有の文言 — これが無いと別の共通例外へ流れても緑になる (検出力の主張が崩れる)
        expect($message)->toContain($branchPhrase);

        // 特定できる負例のみ: パラメータ名 / キー名
        foreach ($expectedMentions as $mention) {
            expect($message)->toContain($mention);
        }
    }
})->with('vendor メタデータの想定外の形');

test('name が空文字のツールは止まる', function (): void {
    $tool = new class(app(McpIdempotencyService::class)) extends AppMcpTool
    {
        public function name(): string
        {
            return '';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        protected function toolName(): ToolName
        {
            return ToolName::Whoami;
        }

        protected function runTool(
            Request $request,
            McpAuthorizationContext $ctx,
        ): array {
            return [];
        }
    };

    expect(fn (): McpToolMetadata => McpToolMetadata::fromTool($tool, WhoamiTool::class))
        ->toThrow(RuntimeException::class, 'name() が空文字です');
});

test('パラメータ名に縦棒が入っていたら生成は止まる (静かに別名へ書き換えない)', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-pipe-name');
    HelpTestTree::writeToolFixture(
        $root,
        'GeneratorFixturePipeNameTool',
        'Whoami',
        'fixture tool',
        "return ['a|b' => \$schema->integer()];",
    );

    expect(fn (): string => helpGeneratorOver($root)->generate())
        ->toThrow(RuntimeException::class, '表を壊す文字');
});

test('パラメータ名に backtick が入っていたら生成は止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-backtick-name');
    HelpTestTree::writeToolFixture(
        $root,
        'GeneratorFixtureBacktickNameTool',
        'Whoami',
        'fixture tool',
        "return ['a'.chr(96).'b' => \$schema->integer()];",
    );

    expect(fn (): string => helpGeneratorOver($root)->generate())
        ->toThrow(RuntimeException::class, '表を壊す文字');
});
