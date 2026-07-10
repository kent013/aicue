<?php

declare(strict_types=1);

namespace App\Auth\Context;

use App\Http\Middleware\ResolveApiActor;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;

/**
 * 解決済みの REST API v1 actor。
 *
 * {@see ResolveApiActor} が actor 解決後に request attribute
 * `api_actor` へ staple する。controller / ability middleware は本 readonly
 * 値オブジェクトを読むだけで、`ApiKey|User` の union 生値を扱わない
 * (= 認可・tenancy 判断の single source)。
 *
 * `user` は **非 null**: API キーの issuedBy が削除済 (= null) の場合は middleware が
 * 403 で弾くため、ここに到達した時点で user は確定している。
 */
final readonly class ApiActorContext
{
    public function __construct(
        public ApiActorKind $kind,
        public User $user,
        public Organization $organization,
        public ApiScopeSet $scopes,
        public ?string $oauthSessionId,
        public ?ApiKey $apiKey,
    ) {}

    public function hasScope(string $scope): bool
    {
        return $this->scopes->has($scope);
    }

    public function organizationId(): int
    {
        return $this->organization->id;
    }

    public function isApiKey(): bool
    {
        return $this->kind === ApiActorKind::ApiKey;
    }

    public function isUserToken(): bool
    {
        return $this->kind === ApiActorKind::UserToken;
    }
}
