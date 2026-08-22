<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/** middleware 位置に現れた参照。alias 文字列とクラス参照を区別する。 */
final readonly class MiddlewareReference
{
    public function __construct(
        public string $relative,
        public int $line,
        public MiddlewareReferenceKind $kind,
        /** alias 文字列、または `X::class` の受け手の原文。 */
        public string $value,
        /** `ClassReference` のときの解決済み完全修飾名 (解決できない形は未解決へ入るので常に非 null)。 */
        public ?string $resolvedFqcn,
    ) {}

    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
    public function describe(): string
    {
        return sprintf(
            '%s:%d %s',
            $this->relative,
            $this->line,
            $this->resolvedFqcn ?? $this->value,
        );
    }
}
