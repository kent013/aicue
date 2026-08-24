<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * 復号点の**公開面**を正規化して判定する純関数 (正典 i4 の「緩い入口を持たない」の実体)。
 *
 * ★**gate と負例が同じ経路を通る**ことが本クラスの存在理由である。gate だけが
 *   `LlmJson::class` を直接 Reflection し、負例 fixture が別ロジックでメソッド数を数える形にすると、
 *   **負例が本番 gate の検出力を証明しない** (詳細設計 §施策 7 の検査 7)。
 *
 * ## 保証しないもの
 *
 * - 見るのは**そのクラス自身が宣言した public メソッド**だけである
 *   (継承したメソッド・protected / private・プロパティ・定数は見ない)。
 * - 引数は**型と必須性**だけを比べる (名前・既定値・参照渡し・可変長は見ない)。
 * - 交差型 / 合併型は `ReflectionType` の文字列表現で比べる (構造では比べない)。
 */
final class DecodePointPublicSurface
{
    /**
     * 公開面 (メソッド名 => static か / 戻り値型 / 引数の型と必須性)。
     *
     * @param  class-string  $class
     * @return array<string, array{static: bool, returnType: string, parameters: list<array{type: string, optional: bool}>}>
     */
    public static function of(string $class): array
    {
        $reflection = new ReflectionClass($class);

        $surface = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue; // 継承したメソッドは公開面の宣言ではない
            }
            $surface[$method->getName()] = [
                'static' => $method->isStatic(),
                'returnType' => self::typeName($method->getReturnType()),
                'parameters' => array_values(array_map(
                    static fn (ReflectionParameter $parameter): array => [
                        'type' => self::typeName($parameter->getType()),
                        'optional' => $parameter->isOptional(),
                    ],
                    $method->getParameters(),
                )),
            ];
        }
        ksort($surface);

        return $surface;
    }

    /**
     * 復号点の受理契約に合う公開面かを判定する (deny-by-default)。
     *
     * - public メソッドは `decode` / `schemaViolation` の 2 つ**だけ** (完全一致)
     * - `decode` は `public static`・**必須の `string` 引数ちょうど 1 つ**・戻り値型 `array`
     * - `schemaViolation` は `public static`・戻り値型が指定の例外型
     *
     * @param  class-string  $class
     * @return list<string> 違反の説明 (空なら契約どおり)
     */
    public static function violations(string $class, string $expectedSchemaViolationReturn): array
    {
        $surface = self::of($class);
        $violations = [];

        $names = array_keys($surface);
        if ($names !== ['decode', 'schemaViolation']) {
            $violations[] = "{$class}: public メソッドが [".implode(', ', $names)
                .'] (decode / schemaViolation の 2 つと完全一致であること)';
        }

        $decode = $surface['decode'] ?? null;
        if ($decode === null) {
            $violations[] = "{$class}: decode が公開されていません";
        } else {
            if (! $decode['static']) {
                $violations[] = "{$class}::decode が static ではありません";
            }
            if ($decode['returnType'] !== 'array') {
                $violations[] = "{$class}::decode の戻り値型が {$decode['returnType']} (array であること)";
            }
            if ($decode['parameters'] !== [['type' => 'string', 'optional' => false]]) {
                $violations[] = "{$class}::decode の引数が「必須の string ちょうど 1 つ」ではありません";
            }
        }

        $schemaViolation = $surface['schemaViolation'] ?? null;
        if ($schemaViolation === null) {
            $violations[] = "{$class}: schemaViolation が公開されていません";
        } else {
            if (! $schemaViolation['static']) {
                $violations[] = "{$class}::schemaViolation が static ではありません";
            }
            if ($schemaViolation['returnType'] !== $expectedSchemaViolationReturn) {
                $violations[] = "{$class}::schemaViolation の戻り値型が {$schemaViolation['returnType']}"
                    ." ({$expectedSchemaViolationReturn} であること)";
            }
        }

        return $violations;
    }

    /** 型の正規化 (null 許容は先頭に `?` を付ける。型無しは `(none)`)。 */
    private static function typeName(?ReflectionType $type): string
    {
        if ($type === null) {
            return '(none)';
        }
        if (! $type instanceof ReflectionNamedType) {
            return (string) $type;
        }

        return ($type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null' ? '?' : '')
            .$type->getName();
    }
}
