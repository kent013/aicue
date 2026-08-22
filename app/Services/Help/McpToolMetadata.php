<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Mcp\Tools\AppMcpTool;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use RuntimeException;

/**
 * MCP ツール 1 本のメタデータ。**vendor の実行時出力を first-party の型へ閉じ込める境界**である。
 *
 * ★**正規化する。閉じた集合で弾かない** (正典の設計判断) — vendor の実行時出力は
 *   first-party の型保証の外にあり、閉集合で弾くと正当なツール定義が生成を止めるためである。
 * ★**静かに欠けるより止まる**。想定外の形は握り潰さず例外にし、
 *   例外には**対象クラス / 不正だった箇所 / 直し方**を必ず含める。
 * ★**組み立ての入口は 2 つ**である。`fromTool()` が実行時の経路、`fromSchema()` が
 *   直列化済みの schema を受ける境界そのものである (後者を public にしてあるのは、
 *   vendor が出しえない形も含めた負例を検査から与えられるようにするためで、
 *   **前者は後者へ委譲する** = 検査した経路と実行時の経路が同一になる)。
 * ★**最上位の形は pin する**。`type` が `'object'` であることと、最上位のキーが
 *   `type` / `properties` / `required` の 3 つに限られることを要求する。
 *   これを見ないと、vendor が `properties` を別のキー名へ変えたときに
 *   「パラメータ 0 件」として**静かに緑で通る** (I14 が名指しで防ごうとしている失敗の形)。
 *   vendor 更新で無害なキーが増えても止まるが、**止まるのが正しい側**である。
 * ★**保証しないもの**: 説明文・パラメータ説明の内容の妥当性は見ない (存在と型だけを見る)。
 */
final readonly class McpToolMetadata
{
    /**
     * vendor の直列化が出す最上位のキー。**これ以外が現れたら形が変わったとみなす**。
     *
     * @var list<string>
     */
    private const array KNOWN_TOP_LEVEL_KEYS = ['type', 'properties', 'required'];

    /**
     * @param  class-string<AppMcpTool>  $className
     * @param  non-empty-string  $name
     * @param  list<McpToolParameter>  $parameters  schema の宣言順
     */
    public function __construct(
        public string $className,
        public string $name,
        public string $description,
        public array $parameters,
    ) {}

    /**
     * @param  class-string<AppMcpTool>  $className
     *
     * @throws RuntimeException vendor のメタデータが想定外の形のとき
     */
    public static function fromTool(AppMcpTool $tool, string $className): self
    {
        $name = $tool->name();
        if ($name === '') {
            throw new RuntimeException("{$className}: name() が空文字です — ToolName enum の値を返すこと。");
        }

        /** @var array<string, mixed> $schema */
        $schema = JsonSchemaFactory::object($tool->schema(...))->toArray();

        return self::fromSchema($schema, $className, $name, $tool->description());
    }

    /**
     * 直列化済みの JSON Schema からメタデータを組み立てる (正規化の境界)。
     *
     * @param  array<string, mixed>  $schema
     * @param  class-string<AppMcpTool>  $className
     * @param  non-empty-string  $name
     *
     * @throws RuntimeException
     */
    public static function fromSchema(array $schema, string $className, string $name, string $description): self
    {
        return new self(
            className: $className,
            name: $name,
            description: $description,
            parameters: self::parametersFrom($schema, $className),
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<McpToolParameter>
     *
     * @throws RuntimeException
     */
    private static function parametersFrom(array $schema, string $className): array
    {
        $hint = 'vendor (laravel/mcp / illuminate/json-schema) の出力形が変わった可能性がある。'.
            'McpToolMetadata の正規化を新しい形に合わせて直すこと。';

        self::assertTopLevelShape($schema, $className, $hint);

        // vendor 実読: properties は 0 件のとき、required は必須 0 件のとき、いずれもキーごと消える。
        $hasProperties = array_key_exists('properties', $schema);
        $hasRequired = array_key_exists('required', $schema);

        // ★「required はあるが properties が無い」は vendor では起きえない形である。
        //   これを 0 件として黙って受けると、必須パラメータが**静かに欠ける**。
        if ($hasRequired && ! $hasProperties) {
            throw new RuntimeException(
                "{$className}: schema に required があるのに properties がありません — {$hint}",
            );
        }

        /** @var mixed $properties */
        $properties = $hasProperties ? $schema['properties'] : [];
        if (! is_array($properties)) {
            throw new RuntimeException("{$className}: schema の properties が配列ではありません — {$hint}");
        }

        $required = [];
        /** @var mixed $rawRequired */
        $rawRequired = $hasRequired ? $schema['required'] : [];
        if (! is_array($rawRequired) || ! array_is_list($rawRequired)) {
            throw new RuntimeException("{$className}: schema の required が list ではありません — {$hint}");
        }
        /** @var mixed $key */
        foreach ($rawRequired as $key) {
            if (! is_string($key) || $key === '') {
                throw new RuntimeException(
                    "{$className}: schema の required に非空の文字列でない要素があります — {$hint}",
                );
            }
            if (isset($required[$key])) {
                throw new RuntimeException(
                    "{$className}: schema の required に重複があります: {$key} — {$hint}",
                );
            }
            if (! array_key_exists($key, $properties)) {
                throw new RuntimeException(
                    "{$className}: schema の required `{$key}` が properties にありません — {$hint}",
                );
            }
            $required[$key] = true;
        }

        $parameters = [];
        /** @var mixed $definition */
        foreach ($properties as $name => $definition) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException(
                    "{$className}: schema の properties にパラメータ名が非空の文字列でない要素があります — {$hint}",
                );
            }
            if (! is_array($definition)) {
                throw new RuntimeException(
                    "{$className}: schema のパラメータ `{$name}` の定義が配列ではありません — {$hint}",
                );
            }

            $parameters[] = new McpToolParameter(
                name: $name,
                type: self::normalizeType($definition['type'] ?? null, $name, $className),
                required: isset($required[$name]),
                description: self::normalizeDescription($definition['description'] ?? null, $name, $className),
            );
        }

        return $parameters;
    }

    /**
     * 最上位の形を検査する。**「パラメータ 0 件」と「形が変わった」を区別するための段**である。
     *
     * @param  array<string, mixed>  $schema
     *
     * @throws RuntimeException
     */
    private static function assertTopLevelShape(array $schema, string $className, string $hint): void
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? null;
        if ($type !== 'object') {
            throw new RuntimeException(
                "{$className}: schema の最上位の type が 'object' ではありません — {$hint}",
            );
        }

        $unknown = array_values(array_diff(array_keys($schema), self::KNOWN_TOP_LEVEL_KEYS));
        if ($unknown !== []) {
            throw new RuntimeException(
                "{$className}: schema の最上位に未知のキーがあります: ".implode(', ', $unknown)." — {$hint}",
            );
        }
    }

    /**
     * 型を**表示用の文字列へ正規化する** (閉じた集合で弾かない)。
     *
     * @return non-empty-string
     *
     * @throws RuntimeException 文字列でも文字列の配列でもないとき
     */
    private static function normalizeType(mixed $type, string $name, string $className): string
    {
        if (is_string($type) && $type !== '') {
            return $type;
        }

        if (is_array($type)) {
            // ★union / nullable は **list** で来る (vendor 実読)。
            //   associative を受けてキーを捨てると、形の変化が静かに通る。
            if (! array_is_list($type) || $type === []) {
                throw new RuntimeException(
                    "{$className}: パラメータ `{$name}` の type が非空の list ではありません — ".
                    'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
                );
            }

            $parts = [];
            /** @var mixed $part */
            foreach ($type as $part) {
                if (! is_string($part) || $part === '') {
                    throw new RuntimeException(
                        "{$className}: パラメータ `{$name}` の type に非空の文字列でない要素があります — ".
                        'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
                    );
                }
                $parts[] = $part;
            }

            return implode('|', $parts);
        }

        if ($type === null) {
            return '(未宣言)';
        }

        throw new RuntimeException(
            "{$className}: パラメータ `{$name}` の type が文字列でも文字列の配列でもありません — ".
            'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
        );
    }

    /** @throws RuntimeException */
    private static function normalizeDescription(mixed $description, string $name, string $className): string
    {
        if ($description === null) {
            return '';
        }
        if (is_string($description)) {
            return $description;
        }

        throw new RuntimeException(
            "{$className}: パラメータ `{$name}` の description が文字列ではありません — ".
            'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeDescription を直すこと。',
        );
    }
}
