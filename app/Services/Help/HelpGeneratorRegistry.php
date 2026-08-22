<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Services\Help\Generators\HelpGenerator;
use App\Services\Help\Generators\McpToolReferenceGenerator;
use Illuminate\Contracts\Container\Container;
use Webmozart\Assert\Assert;

/**
 * 生成器の**全数申告**。
 *
 * ★**許可一覧も除外の口も持たない**。この定数配列に載っているものが生成器のすべてであり、
 *   「台帳に載っていない生成器」は検査の対象から外れる = 存在できない (deny-by-default)。
 *   個別の生成器・個別の節を名指しして検査を免除する仕組みは本機構のどこにも無い。
 * ★**走査対象**: 本定数と `HelpRepository::sections()` が返す `generator` キーの 2 集合だけ。
 * ★**保証しないもの**: 生成器が出す本文の正しさは見ない (それは各生成器の単体検査の担当)。
 */
final class HelpGeneratorRegistry
{
    /**
     * 生成器のキー → 実装クラス。
     *
     * @var array<non-empty-string, class-string<HelpGenerator>>
     */
    public const array GENERATORS = [
        'mcp-tools' => McpToolReferenceGenerator::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<non-empty-string, HelpGenerator>
     */
    public function all(): array
    {
        $resolved = [];

        foreach (self::GENERATORS as $key => $class) {
            /** @var mixed $generator */
            $generator = $this->container->make($class);
            Assert::isInstanceOf($generator, HelpGenerator::class);
            Assert::same($generator->key(), $key, "生成器の key() が台帳のキーと一致しません: {$key}");

            $resolved[$key] = $generator;
        }

        return $resolved;
    }

    /**
     * 台帳と manifest の生成 entry が**完全一致**することを強制する (両方向)。
     *
     * @throws HelpManifestException
     */
    public function verifyRegistryIsFullyReferenced(HelpRepository $repository): void
    {
        $declared = [];
        foreach ($repository->sections() as $section) {
            if ($section->generatorKey !== null) {
                $declared[$section->generatorKey] = true;
            }
        }

        $missingInManifest = array_keys(array_diff_key(self::GENERATORS, $declared));
        $missingInRegistry = array_keys(array_diff_key($declared, self::GENERATORS));

        if ($missingInManifest !== []) {
            throw new HelpManifestException(
                '台帳に在る生成器が manifest に宣言されていません: '.implode(', ', $missingInManifest).
                ' — docs/help/manifest.json へ節を足すこと。',
            );
        }
        if ($missingInRegistry !== []) {
            throw new HelpManifestException(
                'manifest が宣言した生成器が台帳に在りません: '.implode(', ', $missingInRegistry).
                ' — HelpGeneratorRegistry::GENERATORS へ足すこと。',
            );
        }
    }
}
