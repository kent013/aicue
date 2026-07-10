<?php

declare(strict_types=1);

namespace App\Auth\Context;

use App\Enums\ApiKeyAbility;
use App\Enums\OAuth\CliOAuthScope;
use Webmozart\Assert\Assert;

/**
 * actor に付与された scope / ability の集合。
 *
 * API キー経路では {@see ApiKeyAbility} 値、OAuth 経路では
 * {@see CliOAuthScope} 値を granted に持つ (両者は同じ string 値で
 * 対応する契約)。ability middleware は `has()` で「この actor がこの scope/ability を
 * 持つか」を判定する。
 */
final readonly class ApiScopeSet
{
    /** @var list<string> */
    public array $granted;

    /**
     * @param  list<string>  $granted
     */
    public function __construct(array $granted)
    {
        Assert::allString($granted);
        $this->granted = array_values(array_unique($granted));
    }

    public function has(string $scope): bool
    {
        return in_array($scope, $this->granted, true);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->granted;
    }
}
