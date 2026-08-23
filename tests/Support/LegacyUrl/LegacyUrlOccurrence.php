<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/**
 * 旧 URL / 撤去 route 名の検出 1 件。
 *
 * ★`ruleId` は**構文文脈まで識別する安定 ID** である (単なる `legacy-path` にしない)。
 *   同じファイルの中で別の構文の出現と置き換わっても件数だけでは通らない形にするため。
 */
final readonly class LegacyUrlOccurrence
{
    public function __construct(
        /** リポジトリルート相対パス。 */
        public string $relative,
        /** 1 起点の行番号。 */
        public int $line,
        /** 検出規則の安定 ID (`LegacyUrlScanner::RULE_*`)。 */
        public string $ruleId,
        /** 一致した語 (旧パスの根、または撤去 route 名)。 */
        public string $matched,
    ) {}

    /** 失敗メッセージ用の 1 行表現。 */
    public function describe(): string
    {
        return "{$this->relative}:{$this->line} [{$this->ruleId}] {$this->matched}";
    }
}
