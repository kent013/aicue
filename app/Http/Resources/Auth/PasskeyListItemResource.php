<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\DataTransferObjects\Auth\PasskeyListItemDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Settings/Security の passkey 一覧 1 件分 (Inertia prop)。
 *
 * `data` ラップはしない (Inertia prop は plain array で渡す)。
 *
 * @property-read PasskeyListItemDto $resource
 */
final class PasskeyListItemResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{id: int, name: string, authenticator: string|null, lastUsedAt: string|null, createdAt: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'authenticator' => $this->resource->authenticator,
            'lastUsedAt' => $this->resource->lastUsedAt,
            'createdAt' => $this->resource->createdAt,
        ];
    }
}
