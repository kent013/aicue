<?php

declare(strict_types=1);

namespace Tests\Support\JobDedup;

use App\Enums\Security\ExternalCallKind;

/**
 * 「この外部呼び出しの直前に、この再検証点がある」という目録上の宣言。
 *
 * ★ gate が固定できるのは **再検証点の実在と戻り型まで**である。
 *   「外部呼び出しの直前で呼ばれていること」(配置) は Feature テストの担当。
 */
final readonly class PreflightCheckpoint implements PreflightRequirement
{
    /**
     * @param  class-string  $verifierClass  再検証を行うクラス
     * @param  non-empty-string  $verifierMethod  再検証メソッド
     */
    public function __construct(
        public string $verifierClass,
        public string $verifierMethod,
        public ExternalCallKind $externalCall,
        public PreflightControlFlow $controlFlow,
    ) {}

    /** gate が要求する戻り型名 */
    public function expectedReturnType(): string
    {
        return match ($this->controlFlow) {
            PreflightControlFlow::ThrowsOnLoss => 'void',
            PreflightControlFlow::ReturnsBoolean => 'bool',
        };
    }
}
