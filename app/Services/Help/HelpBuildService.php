<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Services\Help\Generators\HelpGenerator;

/**
 * 生成と鮮度検査の判定を閉じ込める層 (コマンドは薄い引数解析層にする)。
 *
 * ★**`check()` は作業ツリーを 1 バイトも変えない**。書き込みは `build()` にしかない。
 * ★**手書きページは判定の母集団に入れない** (0 件でも緑)。
 *   見るのは「manifest が宣言した生成 entry」と「生成物ディレクトリ直下の実体」の 2 集合だけ。
 * ★**絶対パスをこの層で組み立てない**。読み書きの実体検査ごと `HelpRepository` に閉じる。
 * ★**保証しないもの**: 孤児を**削除しない** (人が消す)。生成器が出す本文の正しさは見ない。
 */
final class HelpBuildService
{
    public function __construct(
        private readonly HelpRepository $repository,
        private readonly HelpGeneratorRegistry $registry,
    ) {}

    /** 比較だけ行う (書き込みなし)。 */
    public function check(): HelpBuildReport
    {
        return $this->observe();
    }

    /** 生成物を書いてから、同じ規準でもう一度観測して返す。 */
    public function build(): HelpBuildReport
    {
        $this->registry->verifyRegistryIsFullyReferenced($this->repository);

        $generators = $this->registry->all();

        foreach ($this->repository->sections() as $section) {
            if ($section->generatorKey === null) {
                continue;
            }

            $this->repository->writeGenerated(
                $section,
                $this->generatorFor($generators, $section->generatorKey)->generate(),
            );
        }

        return $this->observe();
    }

    private function observe(): HelpBuildReport
    {
        $this->registry->verifyRegistryIsFullyReferenced($this->repository);

        $generators = $this->registry->all();
        $observations = [];
        $declared = [];

        foreach ($this->repository->sections() as $section) {
            if ($section->generatorKey === null) {
                continue; // 手書きページは鮮度検査の母集団外
            }

            $declared[$section->path] = true;
            $current = $this->repository->read($section);

            $state = match (true) {
                $current === null => HelpArtifactState::Missing,
                $current === $this->generatorFor($generators, $section->generatorKey)->generate() => HelpArtifactState::UpToDate,
                default => HelpArtifactState::Stale,
            };

            $observations[] = new HelpArtifactObservation($section->path, $state);
        }

        foreach ($this->repository->generatedArtifactPaths() as $path) {
            if (! isset($declared[$path])) {
                $observations[] = new HelpArtifactObservation($path, HelpArtifactState::Orphan);
            }
        }

        return new HelpBuildReport($observations);
    }

    /**
     * 台帳から生成器を取り出す。
     *
     * ★`verifyRegistryIsFullyReferenced()` が先に完全一致を強制しているので不在は起こらないが、
     *   **不在を暗黙に許す添字参照は書かない** (将来 verify を外したときに静かに壊れる)。
     *
     * @param  array<non-empty-string, HelpGenerator>  $generators
     * @param  non-empty-string  $key
     *
     * @throws HelpManifestException
     */
    private function generatorFor(array $generators, string $key): HelpGenerator
    {
        $generator = $generators[$key] ?? null;

        if ($generator === null) {
            throw new HelpManifestException(
                "manifest が宣言した生成器が台帳に在りません: {$key} — HelpGeneratorRegistry::GENERATORS へ足すこと。",
            );
        }

        return $generator;
    }
}
