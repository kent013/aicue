<?php

declare(strict_types=1);

namespace Tests\Support\ForbiddenStatement;

/**
 * 禁止する文が 1 つ見つかった位置 (走査器に依存しない中立表現)。
 *
 * ★既存の `Tests\Support\ReferenceSite` と同じ作法 (readonly の値オブジェクト)。
 */
final readonly class ForbiddenStatementSite
{
    public function __construct(
        /** リポジトリルートからの相対パス */
        public string $path,
        /** 1 起点の行番号 */
        public int $line,
        public ForbiddenStatementKind $kind,
    ) {}

    /** 失敗メッセージ用の 1 行表現。 */
    public function describe(): string
    {
        return "{$this->path}:{$this->line} → {$this->kind->label()}";
    }
}
