<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/** 指定クラスのメソッド宣言 / 静的呼び出し。 */
final readonly class MethodReference
{
    public function __construct(
        public string $relative,
        public int $line,
        public MethodReferenceKind $kind,
    ) {}

    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
    public function describe(): string
    {
        return sprintf('%s:%d %s', $this->relative, $this->line, $this->kind->name);
    }
}
