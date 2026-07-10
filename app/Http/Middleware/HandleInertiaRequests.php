<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\ContactUrl;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly SeoManager $seoManager,
    ) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * 全ページ共有 props。
     * flash.visitKey は flash-to-toast の de-dup 用 (同一 flash の二重表示防止)。
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // admin guard (AdminUser) 追加により user() は union 型になるため、
        // Inertia (web guard) の共有 props は User のみを対象に narrowing する
        $user = $request->user();
        if (! $user instanceof User) {
            $user = null;
        }

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'emailVerified' => $user->hasVerifiedEmail(),
                    'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
                ],
            ],
            'organizations' => $this->organizationsProp($user),
            'currentOrganization' => $this->currentOrganizationProp($user),
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
            // 問い合わせ CTA の宛先 (内部 /contact / 外部 URL / mailto を config 駆動で切替)。
            'contact' => fn (): array => [
                'url' => app(ContactUrl::class)->resolve(),
                'kind' => app(ContactUrl::class)->kind()->value,
            ],
            // サーバ描画 <title> と同一文字列を共有し、SPA 遷移後の document.title 陳腐化を解消する
            // (resources/js/lib/document-title.ts が同期)。SeoManager は request-scoped で
            // SeoComposer と同じ実体 (二重 SoT を作らない)。controller の set / setPrivateTitle は
            // share 評価時点 (response 構築時) で反映済み。
            'title' => fn (): string => $this->seoManager->resolveDocumentTitle($request->route()?->getName()),
        ];
    }

    /**
     * ユーザー所属組織の一覧 (組織切替 UI 用。Phase 2c で sidebar に配線する)。
     *
     * @return list<array{id: int, name: string, isPersonal: bool}>
     */
    private function organizationsProp(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values($user->organizations()->get()
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'isPersonal' => $organization->is_personal,
            ])
            ->all());
    }

    /**
     * 現在の組織 + 自分のロール (organizationRole = laratrust_team_id 明示判定)。
     *
     * @return array{id: int, name: string, role: string|null}|null
     */
    private function currentOrganizationProp(?User $user): ?array
    {
        $organization = $user?->currentOrganization;
        if ($user === null || $organization === null) {
            return null;
        }

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'role' => $user->organizationRole($organization)?->value,
        ];
    }
}
