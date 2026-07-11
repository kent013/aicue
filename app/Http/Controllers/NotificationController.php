<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\Notification\NotificationListItemData;
use App\Enums\Notification\NotificationType;
use App\Models\User;
use App\Services\Notification\NotificationCenterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 通知センター (一覧 / open 遷移 / 既読化)。薄い Controller + Service 委譲。
 *
 * - {notification} は implicit binding を使わず Service が $user->notifications() 経由で
 *   解決する (cross-user は構造的に 404 = 存在オラクル封じ。403 で存在を漏らさない。
 *   1 param ルートのため NestedRouteIdorDefenseTest の inventory 対象外)
 * - open は POST + 303 (GET にしない = prefetch/リンクプレビューによる意図しない既読化防止)
 * - open は認可判断 (Gate) を一切複製しない。行うのは (a) 自通知の organization_id と
 *   current org の突合 (自分のデータ同士のルーティング判断) と (b) org→project→manual の
 *   relation 連鎖による存在解決のみ (「認可より前の 404」層の再利用。Gate::authorize は
 *   遷移先 projects.manuals.show が唯一の判断点)。(b) と遷移の間の TOCTOU
 *   (redirect 直後の削除) は遷移先の標準 404 が受ける (残余は許容)
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCenterService $notifications) {}

    /** 通知一覧 (全 org 横断 = 自分宛のみで構造的に閉じる) */
    public function index(Request $request): Response
    {
        $user = $this->authedUser($request);
        $paginator = $this->notifications->paginateFor($user);

        $items = [];
        foreach ($paginator->items() as $notification) {
            Assert::isInstanceOf($notification, DatabaseNotification::class);
            $items[] = NotificationListItemData::fromNotification($notification)->toArray();
        }

        return Inertia::render('Notifications/Index', [
            'notifications' => $items,
            // 既存 ManualListItem のページャ shape (ProjectController::manualRows) と同形
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** 既読化 + 遷移先のサーバ解決 (POST + 303。開けない場合は一覧へ明示 redirect) */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $this->authedUser($request);
        $found = $this->notifications->findOwnOrFail($user, $notification); // cross-user 404
        $this->notifications->markRead($found);

        $item = NotificationListItemData::fromNotification($found);

        // 遷移はすべて 303 (POST → GET の意味論を明示。Inertia の POST visit とも整合)
        return match (true) {
            // manual 系: 通知 org ≠ current org → 案内して一覧へ (自動 org 切替はしない = 驚き最小)
            $item->isManualJob() && ! $this->belongsToCurrentOrg($user, $item) => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', 'この通知は別の組織のものです。組織を切り替えてから開いてください。'),
            // manual 系: current org → project → manual の relation 連鎖で現存する → manual 画面へ
            $item->isManualJob() && $this->manualStillExists($user, $item) => redirect()
                ->route('projects.manuals.show', [$item->projectId(), $item->manualId()], 303),
            $item->isManualJob() => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', '対象の動画マニュアルは削除されています。'),
            $item->type === NotificationType::TicketBalanceLow => redirect()
                ->route('billing.tickets.show', [], 303),
            $item->type === NotificationType::InvitationReceived => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', '招待はメールの受諾リンクから参加してください。'),
            // 未知 type (enum⇔DB ドリフト時の防御): 既読化のみ・汎用文言
            default => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', 'この通知には開ける対象がありません。'),
        };
    }

    /** 1 件既読化 (back() 完結) */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $this->authedUser($request);
        $this->notifications->markRead($this->notifications->findOwnOrFail($user, $notification));

        return back();
    }

    /** 一括既読化 (back() 完結) */
    public function readAll(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($this->authedUser($request));

        return back()->with('success', 'すべての通知を既読にしました');
    }

    /** admin guard 追加で user() は union になるため User へ narrowing する */
    private function authedUser(Request $request): User
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return $user;
    }

    /** 通知の org 文脈 (organization_id 列) が current org と一致するか (認可判断ではない) */
    private function belongsToCurrentOrg(User $user, NotificationListItemData $item): bool
    {
        return $item->organizationId !== null
            && $item->organizationId === $user->current_organization_id;
    }

    /**
     * current org → projects() → manuals の relation 連鎖による存在解決 (exists() 1 クエリ。
     * 認可判断なし = 「認可より前の 404」層の再利用)。
     */
    private function manualStillExists(User $user, NotificationListItemData $item): bool
    {
        $organization = $user->currentOrganization;
        if ($organization === null) {
            return false;
        }

        return $organization->projects()
            ->whereKey($item->projectId())
            ->whereHas('manuals', fn (Builder $query): Builder => $query->whereKey($item->manualId()))
            ->exists();
    }
}
