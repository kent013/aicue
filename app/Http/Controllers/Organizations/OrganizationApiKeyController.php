<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 組織 API キーの一覧・発行・失効 (専用の API キー管理画面)。
 *
 * 平文キーは発行直後の 1 度きり表示 (flash 'new_api_key' 経由で index props に
 * 渡し、DB には secret の Argon2id ハッシュのみ保存する)。失効は revoked_at で表現する
 * (行削除しない)。OAuth セッションは同一境界の別タブ
 * ({@see OrganizationOauthSessionController}) が担う。
 */
class OrganizationApiKeyController extends Controller
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * API キー一覧 (manageApiKeys = owner / admin または直接付与メンバー)。
     * revoked も監査痕跡として表示する。
     */
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('manageApiKeys', $organization);

        $apiKeys = $organization->apiKeys()
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiKey $apiKey): array => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'keyPrefix' => $apiKey->key_prefix,
                'abilities' => array_values(array_filter(
                    is_array($apiKey->abilities) ? $apiKey->abilities : [],
                    static fn (mixed $a): bool => is_string($a),
                )),
                'lastUsedAt' => $apiKey->last_used_at?->toDateTimeString(),
                'revokedAt' => $apiKey->revoked_at?->toDateTimeString(),
                'createdAt' => $apiKey->created_at?->toDateString(),
            ])
            ->values()
            ->all();

        return Inertia::render('Organizations/ApiKeys/Index', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'apiKeys' => $apiKeys,
            // 発行直後の平文キー (flash 経由の 1 度きり。リロードで消える)
            'newApiKey' => $request->session()->get('new_api_key'),
        ]);
    }

    /** API キー発行 (manageApiKeys = owner / admin) */
    public function store(StoreApiKeyRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('manageApiKeys', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $name = $request->validated('name');
        Assert::string($name);
        $validatedAbilities = $request->validated('abilities');
        Assert::isArray($validatedAbilities);
        /** @var list<string> $abilities */
        $abilities = array_values(array_unique(array_filter($validatedAbilities, 'is_string')));

        $generated = ApiKey::generatePlainKey();
        $apiKey = ApiKey::createForOrganization(
            $organization,
            $user,
            $name,
            $abilities,
            $generated['prefix'],
            ApiKey::hashSecret($generated['secret']),
        );

        $this->recorder->record(SecurityEventType::ApiKeyIssued, $user, [
            'organization_id' => $organization->id,
            'api_key_id' => $apiKey->id,
            'api_key_name' => $apiKey->name,
            'abilities' => $abilities,
        ]);

        // 平文は発行直後の 1 度きり表示 (flash → settings props。再表示はできない)
        return back()
            ->with('success', 'API キーを発行しました')
            ->with('new_api_key', [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'plain' => $generated['plain'],
            ]);
    }

    /**
     * API キー失効 (revoked_at を立てる。行は監査痕跡として残す)。
     * {apiKey} は scopeBindings で $organization->apiKeys() 経由の解決
     * (他組織のキーは認可より前に 404)。
     */
    public function destroy(Request $request, Organization $organization, ApiKey $apiKey): RedirectResponse
    {
        Gate::authorize('manageApiKeys', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        if (! $apiKey->isRevoked()) {
            $apiKey->forceFill(['revoked_at' => now()])->save();

            $this->recorder->record(SecurityEventType::ApiKeyRevoked, $user, [
                'organization_id' => $organization->id,
                'api_key_id' => $apiKey->id,
                'api_key_name' => $apiKey->name,
            ]);
        }

        return back()->with('success', 'API キーを失効しました');
    }
}
