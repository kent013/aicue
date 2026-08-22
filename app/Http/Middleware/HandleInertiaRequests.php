<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Organizations\CurrentOrganizationData;
use App\Models\Organization;
use App\Models\User;
use App\Services\Marketing\ContactUrl;
use App\Services\Organization\OrganizationMembershipService;
use App\Support\Auth\SessionEpoch;
use App\Support\Http\FlashNotificationRelay;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
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
     * 通知 flash のキー集合の SoT は FlashNotificationRelay::NOTIFICATION_KEYS。
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
            'currentOrganization' => $this->currentOrganizationProp($request, $user),
            // 通知センターの未読数 (全 org 横断・自分宛のみ)。closure = Inertia partial reload で
            // 省略可能 (将来の router.reload({ only: ['notifications'] }) ポーリング拡張にも使える)
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
            // 自分宛の受諾可能な招待の件数 (全画面横断の気づき。裁定 AG-113 必須要素 (b)(c))。
            // ★件数は受諾の解決・一覧と**同一 scope** から算出する
            //   (ずれると「件数は出るのに受諾できない」が起きる)。
            // ★未ログイン・未 verified・email 空は pendingInvitationCountFor が
            //   DB を一切引かずに 0 を返す (全リクエストで評価されるため実効的な負荷契約)。
            // app() 解決にするのはコンストラクタ注入を増やさないため (contact prop と同じ流儀)。
            // ★キー名を 'invitations' にしない: ページ prop 'invitations' (Admin/Users の
            //   招待一覧) と衝突し、その画面だけ共有 prop が配列で上書きされて
            //   横断の気づきが黙って消える (通知の unreadCount と同じ衝突クラス)。
            'invitationInbox' => [
                'pendingCount' => fn (): int => app(OrganizationMembershipService::class)
                    ->pendingInvitationCountFor($user),
            ],
            // 通知キー集合の SoT は FlashNotificationRelay::NOTIFICATION_KEYS
            // (FlashNotificationRelayDriftTest が一致を固定)。visitKey は通知ではなく
            // 二重表示を抑える見分け用のため中継の対象外で別建て
            'flash' => [
                ...$this->notificationFlashProps($request),
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
            // 描画世代: この応答の内容がどのセッション世代のものかを、内容と同じ 1 通で運ぶ。
            // **常に載せる** (Inertia の部分再読み込みで省略されると印だけ古くなるため)。
            // これを cookie から読む形にすると「内容は A・印は B」の取り違えが起きる。
            //
            // **closure で渡す (即値にしない)**。vendor の Inertia\Middleware は
            // $next($request) の**前**に Inertia::share($this->share($request)) を呼ぶため、
            // 即値だと「要求前のセッション ID」で固定される。AlwaysProp は callable を
            // 応答構築時に解決する (ResolvesCallables) ので、closure なら
            // 世代 cookie ($next の後に導出) と同じ時点のセッション ID になる。
            SessionEpoch::SHARED_PROP_KEY => Inertia::always(
                fn (): ?string => SessionEpoch::current($request),
            ),
        ];
    }

    /**
     * 通知の一時メッセージ (キー集合は FlashNotificationRelay::NOTIFICATION_KEYS から導出)。
     *
     * @return array<string, mixed>
     */
    private function notificationFlashProps(Request $request): array
    {
        $flash = [];
        foreach (FlashNotificationRelay::NOTIFICATION_KEYS as $key) {
            $flash[$key] = $request->session()->get($key);
        }

        return $flash;
    }

    /**
     * ユーザー所属組織の一覧。
     *
     * ★**切替の入力ではない** (家系裁定 AG-037 は切替 endpoint を禁じる)。
     *   組織文脈は URL だけで決まるので、この一覧は「別の組織の URL への**リンク**」を
     *   描くためだけに使う。slug を含めるのはそのためである。
     *
     * @return list<array{id: int, name: string, slug: string}>
     */
    private function organizationsProp(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values($user->organizations()->orderBy('organizations.name')->get()
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ])
            ->all());
    }

    /**
     * 現在の組織 + 自分のロール + ナビ表示に必要な最小権限フラグ。
     *
     * ★**URL の binding からのみ導出する** (家系裁定 AG-037)。組織 route 以外では必ず null で、
     *   「所属している組織のどれか」を裏口から選ばない。保持列は撤去済みである。
     * ★binder (`MembershipScopedOrganizationBinder`) が「認証済みユーザーが所属する組織」へ
     *   スコープして解決するので、ここに届く時点で membership は成立している。
     *   それでも `isMemberOf` を再確認するのは二重防御である (binder の配線が外れたときに
     *   slug/name を露出しない)。
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     role: string|null,
     *     canManageMembers: bool,
     *     canManageApiKeys: bool
     * }|null
     */
    private function currentOrganizationProp(Request $request, ?User $user): ?array
    {
        $organization = $request->route('organization');
        if ($user === null || ! $organization instanceof Organization) {
            return null;
        }

        // 二重防御: binding が非所属 org を返したら共有しない (存在秘匿)。
        if (! $user->isMemberOf($organization)) {
            return null;
        }

        return CurrentOrganizationData::forMember($user, $organization)->toArray();
    }
}
