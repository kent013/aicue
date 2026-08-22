<?php

declare(strict_types=1);

namespace App\Services\Help\Generators;

use App\Mcp\Tools\AppMcpTool;
use App\Services\Help\McpToolMetadata;
use App\Services\Help\McpToolParameter;
use App\Services\Help\McpToolScanner;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * MCP ツール一覧の Markdown を実装から生成する (正典 AG-100 の還流対象 (2))。
 *
 * ★出力は**決定的**である (ツールは name 昇順、パラメータは schema の宣言順、
 *   日時・環境変数のような可変要素を一切含めない)。同じ実装からは同じバイト列が出る。
 * ★**説明と名前で扱いを分ける**。説明文は人が書いた散文なので表示用に無害化する
 *   (縦棒と改行を潰す)。**パラメータ名は無害化せず、表を壊す文字を含むなら例外で止める** —
 *   名前は first-party の schema のキー (識別子) であり、静かに別名へ書き換えると
 *   生成物の名前と実装の名前がずれる (この機構の目的そのものを壊す)。
 *   backtick は code span の中では逆斜線で逃がせないので、そもそも無害化できない。
 * ★**保証しないもの**: 説明文の質は見ない。サーバに登録されているかも見ない
 *   (走査集合と登録集合の一致は `McpToolReferencePopulationTest` の担当)。
 */
final class McpToolReferenceGenerator implements HelpGenerator
{
    public function __construct(
        private readonly McpToolScanner $scanner,
        private readonly Container $container,
    ) {}

    public function key(): string
    {
        return 'mcp-tools';
    }

    public function generate(): string
    {
        $metadata = [];

        foreach ($this->scanner->concreteToolClasses() as $class) {
            /** @var mixed $tool */
            $tool = $this->container->make($class);
            Assert::isInstanceOf($tool, AppMcpTool::class);

            $metadata[] = McpToolMetadata::fromTool($tool, $class);
        }

        usort($metadata, static fn (McpToolMetadata $a, McpToolMetadata $b): int => strcmp($a->name, $b->name));

        $lines = [
            '<!-- 自動生成: `php artisan help:build` が生成する。手で編集しない。 -->',
            '<!-- 生成器: mcp-tools ('.self::class.') -->',
            '',
            '# MCP ツールリファレンス',
            '',
            '本アプリが MCP サーバー (`App\Mcp\Servers\AppMcpServer`) 経由で公開しているツールの一覧である。',
            '実装 (`app/Mcp/Tools/`) から自動生成しているので、手書きの説明が実装からずれることはない。',
            '',
            '現在のツール数: '.count($metadata),
        ];

        foreach ($metadata as $tool) {
            $lines[] = '';
            $lines[] = '## `'.$tool->name.'`';
            $lines[] = '';
            $lines[] = self::escapeCell($tool->description);

            if ($tool->parameters === []) {
                $lines[] = '';
                $lines[] = 'パラメータなし。';

                continue;
            }

            $lines[] = '';
            $lines[] = '| パラメータ | 型 | 必須 | 説明 |';
            $lines[] = '|---|---|---|---|';
            foreach ($tool->parameters as $parameter) {
                $lines[] = self::parameterRow($parameter, $tool->className);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @throws RuntimeException 名前が表を壊す文字を含むとき */
    private static function parameterRow(McpToolParameter $parameter, string $className): string
    {
        if (preg_match('/[|`\r\n]/', $parameter->name) === 1) {
            throw new RuntimeException(
                "{$className}: パラメータ名 `{$parameter->name}` が表を壊す文字 ".
                '(縦棒 / backtick / 改行) を含みます — MCP ツールの schema のキーから取り除くこと '.
                '(名前は無害化しない。静かに別名へ書き換えると生成物と実装がずれる)。',
            );
        }

        return sprintf(
            '| `%s` | %s | %s | %s |',
            $parameter->name,
            self::escapeCell($parameter->type),
            $parameter->required ? '必須' : '任意',
            self::escapeCell($parameter->description),
        );
    }

    /** 表のセルを壊す縦棒と改行を無害化する (`docs/template-divergence.md` と同じ方針)。 */
    private static function escapeCell(string $value): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], $value);
    }
}
