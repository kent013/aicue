<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/**
 * 旧 URL 走査の実母集団 (4 分類の結果)。
 *
 * ★**数える集合と実際に走査する集合は同一**である (別に数え直さない)。
 * ★`unclassified` と `unresolved` は利用側 gate が 0 件で固定する
 *   (未分類のまま走査から外れる経路と、読めないまま黙って落ちる経路を塞ぐ)。
 */
final readonly class LegacyUrlScanPopulation
{
    /**
     * @param  list<LegacyUrlScannedFile>  $scanned
     * @param  list<LegacyUrlScannedFile>  $selfCheckOnly
     * @param  array<string, string>  $notScanned  相対パス => 理由
     * @param  list<string>  $unclassified
     * @param  array<string, string>  $unresolved  相対パス => 理由
     */
    public function __construct(
        public array $scanned,
        public array $selfCheckOnly,
        public array $notScanned,
        public array $unclassified,
        public array $unresolved,
    ) {}

    /** 走査対象のうち抽出方式ごとの件数 (走査根が生きていることの pin に使う)。 @return array<string, int> */
    public function scannedCountByRule(): array
    {
        $counts = [];
        foreach ($this->scanned as $file) {
            $counts[$file->ruleId] = ($counts[$file->ruleId] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
