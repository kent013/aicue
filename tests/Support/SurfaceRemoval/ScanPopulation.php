<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/**
 * 実走査母集団 + 未解決 + バイナリ除外。
 *
 * ★**数える集合と走査する集合を分けない**。gate が空振り検査に使う件数は、
 *   本体の検査が実際に走査した `$files` そのものから数える。
 * ★`$unresolved` と `$binaryExcluded` は**利用側 gate が空を要求する**。
 *   捨てた事実を型の上に残すことで、無言の fail-open を作らない。
 */
final readonly class ScanPopulation
{
    /**
     * @param  list<ScannedFile>  $files  実走査母集団
     * @param  array<string, string>  $unresolved  相対パス => 理由
     * @param  list<string>  $binaryExcluded  NUL を含むため外した相対パス
     */
    public function __construct(
        public array $files,
        public array $unresolved,
        public array $binaryExcluded,
    ) {}

    /** @return list<ScannedFile> PHP ソースとして扱うファイル */
    public function php(): array
    {
        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => $f->isPhp));
    }

    /** @return list<ScannedFile> PHP ソースとして扱わないファイル */
    public function nonPhp(): array
    {
        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => ! $f->isPhp));
    }

    /** @return list<ScannedFile> 指定した走査根に属するファイル */
    public function inRoot(string $root): array
    {
        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => $f->root === $root));
    }

    /** @return list<string> 実走査母集団の相対パス */
    public function relativePaths(): array
    {
        return array_values(array_map(static fn (ScannedFile $f): string => $f->relative, $this->files));
    }
}
