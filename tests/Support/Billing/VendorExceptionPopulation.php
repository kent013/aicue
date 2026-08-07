<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use FilesystemIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 分類対象となる vendor 例外クラスの母集団 (Stripe SDK / Cashier)。
 *
 * ★`vendor/stripe/stripe-php/lib/Exception/*.php` (**直下のみ**) と
 *   `vendor/laravel/cashier/src/Exceptions/*.php` を glob → クラス名へ変換 →
 *   `class_exists()` → interface / abstract を除外する。
 * ★`composer update` で例外クラスが増減すると gate が赤くなる。これは
 *   **意図した費用**であり「外部の語彙が増えたことを人間に必ず知らせる」ための仕掛けである
 *   (復旧手順は `docs/architecture.md` §オートリチャージの失敗分類)。
 */
final class VendorExceptionPopulation
{
    /**
     * 母集団から外す Stripe のサブ名前空間 (根拠付き。gate がサブディレクトリ集合と突き合わせる)。
     *
     * @var array<string, string>
     */
    public const array EXCLUDED_STRIPE_SUBNAMESPACES = [
        'OAuth' => 'Stripe Connect の OAuth 専用。本アプリは Connect を使わないため gateway 経路から到達しない',
    ];

    /** @return list<class-string<Throwable>> */
    public static function classes(): array
    {
        $classes = array_merge(self::stripeClasses(), self::cashierClasses());
        sort($classes);

        return array_values($classes);
    }

    /** @return list<class-string<Throwable>> */
    public static function stripeClasses(): array
    {
        return self::concreteThrowables(
            base_path('vendor/stripe/stripe-php/lib/Exception'),
            'Stripe\\Exception\\',
        );
    }

    /** @return list<class-string<Throwable>> */
    public static function cashierClasses(): array
    {
        return self::concreteThrowables(
            base_path('vendor/laravel/cashier/src/Exceptions'),
            'Laravel\\Cashier\\Exceptions\\',
        );
    }

    /**
     * ディレクトリ**直下**のサブディレクトリ名一覧 (除外宣言との突き合わせ用)。
     *
     * @return list<string>
     */
    public static function subdirectories(string $directory): array
    {
        $names = [];
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            Assert::isInstanceOf($entry, SplFileInfo::class);
            if ($entry->isDir()) {
                $names[] = $entry->getFilename();
            }
        }

        sort($names);

        return $names;
    }

    /**
     * ディレクトリ直下の `*.php` のうち、具象 Throwable クラスだけを返す。
     *
     * @return list<class-string<Throwable>>
     */
    private static function concreteThrowables(string $directory, string $namespace): array
    {
        $paths = glob($directory.DIRECTORY_SEPARATOR.'*.php');
        Assert::isArray($paths, "vendor 例外ディレクトリを走査できません: {$directory}");

        $classes = [];
        foreach ($paths as $path) {
            $class = $namespace.basename($path, '.php');
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isInterface() || $reflection->isAbstract()) {
                continue;
            }
            if (! $reflection->implementsInterface(Throwable::class)) {
                continue;
            }

            /** @var class-string<Throwable> $name */
            $name = $reflection->getName();
            $classes[] = $name;
        }

        sort($classes);

        return array_values($classes);
    }
}
