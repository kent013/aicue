<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Webmozart\Assert\Assert;

/**
 * 「キューに載るクラス」の母集団を決める**唯一の実装**。
 *
 * QueuedJobLeaseInventoryTest (接続 / リース期間の目録) と
 * JobExecutionDedupInventoryTest (重複実行の保証の目録) が**同じ母集団**を見ることを
 * 構造的に保証する (2 実装に分かれていると、片方だけ更新される drift が起きる)。
 *
 * 母集団判定の正本は `ReflectionClass::implementsInterface(ShouldQueue::class)` +
 * `isInstantiable()`。親クラス / trait 経由の実装も拾うため Job だけでなく
 * Mailable / Notification も自動的に母集団へ入る。
 *
 * ★ `class_exists()` により **autoload の副作用を伴う** (既存 QueuedJobLeaseInventoryTest の
 *   方式をそのまま移設したもの)。token parser / composer classmap へ寄せる案はあるが、
 *   既存 gate の振る舞いまで変わるため本設計では踏襲する (方式変更は独立した課題)。
 */
final class QueuedJobPopulation
{
    /** @return list<class-string> */
    public static function shouldQueueClasses(): array
    {
        $classes = [];
        foreach (self::appPhpFiles() as $path) {
            $class = self::classNameForPath($path);
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $classes[] = $reflection->getName();
        }

        sort($classes);

        return $classes;
    }

    /**
     * app/ 配下の `Illuminate\Mail\Mailable` subclass を **`ShouldQueue` 実装の有無を問わず**列挙する。
     *
     * ★ **なぜ `shouldQueueClasses()` と別に要るか**: Mailable は `ShouldQueue` を実装して
     *   いなくても `Mail::to(...)->queue()` / `Mail::queue()` でキューへ載る。このとき vendor の
     *   `SendQueuedMailable::__construct()` が
     *   `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
     *   **wrapper job へコピーする**ため、非 `ShouldQueue` な Mailable の
     *   `public $afterCommit = true;` / `implements ShouldQueueAfterCommit` がそのまま
     *   commit 後ずらしになる (QueueDispatchAtomicityInventoryTest の D3 / D5 が使う)。
     *
     * ★ **`isInstantiable()` は要求しない**。first-party の abstract な base Mailable は
     *   `$afterCommit` の既定値や宣言的迂回 interface を concrete subclass へ伝播させる
     *   carrier であり、除外すると 0 件 pin が抜ける。vendor の `Illuminate\Mail\Mailable`
     *   本体は `app/` 探索に入らないので母集団には現れない
     *   (`shouldQueueClasses()` 側の `isInstantiable()` は既存挙動なので変更しない)。
     *
     * @return list<class-string>
     */
    public static function mailableClasses(): array
    {
        $classes = [];
        foreach (self::appPhpFiles() as $path) {
            $class = self::classNameForPath($path);
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! $reflection->isSubclassOf(Mailable::class)) {
                continue;
            }

            $classes[] = $reflection->getName();
        }

        sort($classes);

        return $classes;
    }

    /**
     * app/ 配下の **読み込み可能な全クラス**を列挙する (interface / trait / enum を含む)。
     *
     * ★ D6 (`ShouldDispatchAfterCommit`) の母集団に使う。event クラスには marker interface が
     *   無く「どれが event か」を静的に決められないため、**app/ 全クラスという超集合**を
     *   母集団にして deny-by-default で見る (event 検出のヒューリスティクスを持たない)。
     *
     * @return list<class-string>
     */
    public static function appClasses(): array
    {
        $classes = [];
        foreach (self::appPhpFiles() as $path) {
            $class = self::classNameForPath($path);
            if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
                continue;
            }

            $classes[] = (new ReflectionClass($class))->getName();
        }

        sort($classes);

        return $classes;
    }

    /**
     * app/ 配下の PHP ファイル絶対パス一覧。
     *
     * @return list<string>
     */
    public static function appPhpFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];
        foreach ($iterator as $file) {
            Assert::isInstanceOf($file, SplFileInfo::class);
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $paths[] = $file->getPathname();
        }

        sort($paths);

        return $paths;
    }

    /** app/ 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
    public static function classNameForPath(string $path): string
    {
        $appPath = base_path('app').DIRECTORY_SEPARATOR;
        Assert::startsWith($path, $appPath, "app/ 配下ではないパスです: {$path}");

        $relative = substr($path, strlen($appPath), -strlen('.php'));

        return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    }
}
