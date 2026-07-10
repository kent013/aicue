<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Api;

use App\Auth\Context\ApiActorContext;
use App\Models\ApiKey;

/**
 * `GET /api/v1/me` (whoami) のレスポンス shape。
 *
 * CLI / CI が「どの組織に・どの主体で・どの権限で」認証されているかを確認する
 * 最小コンテキストのみ返す (平文トークン・ユーザー email・課金情報は含めない)。
 *
 * actor 出し分け: API キー actor は `api_key` セクション、OAuth user-token actor は
 * `session` セクションを持ち、もう一方は null (actor_kind で判別)。
 */
final class MeDto
{
    /**
     * @param  array{id: int, name: string, abilities: list<string>}|null  $apiKey
     * @param  array{id: string, scopes: list<string>}|null  $session
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly string $organizationName,
        public readonly string $actorKind,
        public readonly ?array $apiKey,
        public readonly ?array $session,
    ) {}

    /**
     * @return array{
     *     organization: array{id: int, name: string},
     *     actor_kind: string,
     *     api_key: array{id: int, name: string, abilities: list<string>}|null,
     *     session: array{id: string, scopes: list<string>}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'organization' => [
                'id' => $this->organizationId,
                'name' => $this->organizationName,
            ],
            'actor_kind' => $this->actorKind,
            'api_key' => $this->apiKey,
            'session' => $this->session,
        ];
    }

    /**
     * 解決済み actor から出し分けて構築する (ApiKey|User の union はここにも漏らさない)。
     */
    public static function fromActor(ApiActorContext $actor): self
    {
        $organization = $actor->organization;

        if ($actor->apiKey instanceof ApiKey) {
            return new self(
                organizationId: $organization->id,
                organizationName: $organization->name,
                actorKind: $actor->kind->value,
                apiKey: [
                    'id' => $actor->apiKey->id,
                    'name' => $actor->apiKey->name,
                    'abilities' => $actor->scopes->all(),
                ],
                session: null,
            );
        }

        return new self(
            organizationId: $organization->id,
            organizationName: $organization->name,
            actorKind: $actor->kind->value,
            apiKey: null,
            session: $actor->oauthSessionId === null ? null : [
                'id' => $actor->oauthSessionId,
                'scopes' => $actor->scopes->all(),
            ],
        );
    }
}
