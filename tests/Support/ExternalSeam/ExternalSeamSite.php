<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use Tests\Support\ScanScopeKind;

/** 外部到達点の 1 site。 */
final readonly class ExternalSeamSite
{
    public function __construct(
        public string $path,
        public int $line,
        public ExternalSeamRule $rule,
        /** 検出の根拠になった名前 (FQCN またはメソッド名) */
        public string $symbol,
        public ScanScopeKind $scopeKind,
        public ?string $class,
        public ?string $callable,
    ) {}

    /** 失敗メッセージ用の 1 行 (「なぜ母集団に入ったのか」が読める形)。 */
    public function describe(): string
    {
        return "{$this->path}:{$this->line} [{$this->rule->value}] {$this->symbol} "
            .'('.($this->callable ?? '(file scope)').')';
    }
}
