<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\DataTransferObjects\Account\AccountDeletionStateDto;
use App\DataTransferObjects\Notification\AccountDeletionRequestedPayload;
use App\DataTransferObjects\Notification\InvitationReceivedPayload;
use App\DataTransferObjects\Notification\ManualJobPayload;
use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Notifications\InApp\AccountDeletionRequestedNotification;
use App\Notifications\InApp\InvitationReceivedNotification;
use App\Notifications\InApp\ManualAnalyzedNotification;
use App\Notifications\InApp\ManualRenderedNotification;
use App\Notifications\InApp\TicketBalanceLowNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * アプリ内通知センターの唯一の窓口 (発火・読み出し・既読化)。概念設計 20260711-2255。
 *
 * 発火の設計上の位置づけ:
 * - ジョブ / 招待 / 残高系はすべて既存 exactly-once 遷移 (terminal tx / org 行ロック) の
 *   **commit 後** に呼ばれる
 *   (terminal tx 内に通知 insert を入れない = 通知失敗がジョブ結果を rollback しない)
 * - **例外は退会予約 (notifyAccountDeletionRequested) の 1 本**で、こちらは予約の書き込みと
 *   同一 tx 内から呼ばれる (予約が rollback したら通知も残らないのが正しい)。
 *   失敗の吸収は同じく safely() が行う
 * - 配信保証は at-most-once (重複なし・欠落あり得る)。正はジョブ status + 既存ポーリング UI
 *   であり通知は補助チャネル (outbox 台帳は作らない。詳細設計「配信保証仕様」)
 * - 宛先・内容・organization_id は DB relation からの再解決のみ (payload 不信任)
 * - 通知内の例外は safely() で catch + report し、ジョブ本流を絶対に壊さない
 */
class NotificationCenterService
{
    // ── 発火 (すべて terminal 遷移 commit 後に呼ばれる) ──────────────────────

    /**
     * 解析 terminal 通知。宛先 = creator ∪ triggeredBy (org 所属を再確認して dedup)。
     * terminal 遷移と通知の間に manual/project が削除された競合は「通知スキップ」が仕様
     * (例外にしない。ManualAnalysisNotificationTest で固定)。
     */
    public function notifyAnalysisFinished(AnalysisJob $job): void
    {
        $this->safely(function () use ($job): void {
            $manual = $job->videoManual;
            if ($manual === null) {
                return; // manual 削除競合 → 通知スキップ
            }
            $project = $manual->project;
            Assert::isInstanceOf($project, Project::class);
            $organization = $project->organization;
            Assert::isInstanceOf($organization, Organization::class);

            $payload = new ManualJobPayload(
                projectId: $project->id,
                manualId: $manual->id,
                manualTitle: $manual->title,
                organizationName: $organization->name,
                succeeded: $job->status === JobStatus::Succeeded,
                error: $job->error,
            );

            foreach ($this->resolveRecipientsForManualJob($manual, $job->triggered_by, $organization) as $user) {
                $user->notify(new ManualAnalyzedNotification($organization->id, $payload));
            }
        });
    }

    /**
     * レンダ terminal 通知 (kind=render のみ。preview はノイズ・status 遷移も無いため通知 0)。
     */
    public function notifyRenderFinished(RenderJob $job): void
    {
        $this->safely(function () use ($job): void {
            if ($job->kind !== RenderKind::Render) {
                return; // preview は通知しない
            }
            $manual = $job->videoManual;
            if ($manual === null) {
                return; // manual 削除競合 → 通知スキップ
            }
            $project = $manual->project;
            Assert::isInstanceOf($project, Project::class);
            $organization = $project->organization;
            Assert::isInstanceOf($organization, Organization::class);

            $payload = new ManualJobPayload(
                projectId: $project->id,
                manualId: $manual->id,
                manualTitle: $manual->title,
                organizationName: $organization->name,
                succeeded: $job->status === JobStatus::Succeeded,
                error: $job->error,
            );

            foreach ($this->resolveRecipientsForManualJob($manual, $job->triggered_by, $organization) as $user) {
                $user->notify(new ManualRenderedNotification($organization->id, $payload));
            }
        });
    }

    /**
     * 招待の気づき通知 (既存ユーザー宛のみ)。whereBlind = CipherSweet 不変条件 6。
     * 受信者は当然まだ招待元 org に未所属のため所属確認はしない。
     */
    public function notifyInvitationReceived(OrganizationInvitation $invitation): void
    {
        $this->safely(function () use ($invitation): void {
            $organization = $invitation->organization;
            Assert::isInstanceOf($organization, Organization::class);

            /** @var User|null $user */
            $user = User::whereBlind('email', 'email_index', $invitation->email)->first();
            if ($user === null) {
                return; // 未登録 email はメールのみ (アプリ内通知なし)
            }

            $user->notify(new InvitationReceivedNotification(
                $organization->id,
                new InvitationReceivedPayload($organization->name),
            ));
        });
    }

    /**
     * 残高低下通知。宛先 = org の owner/admin (organizationRole = laratrust_team_id 明示判定)。
     * $balance は Reserved 拘束を含む実効残高 (クロス検知は TicketLedgerService::reserve)。
     */
    public function notifyTicketBalanceLow(Organization $organization, int $balance, int $threshold): void
    {
        $this->safely(function () use ($organization, $balance, $threshold): void {
            $payload = new TicketBalanceLowPayload($organization->name, $balance, $threshold);
            foreach ($organization->users()->get() as $user) {
                if ($user->organizationRole($organization)?->canManage() !== true) {
                    continue;
                }
                $user->notify(new TicketBalanceLowNotification($organization->id, $payload));
            }
        });
    }

    /**
     * 退会予約 (猶予期間つき削除) の気づき通知。
     *
     * ★呼び出し位置は **予約の書き込みと同一 tx 内** (他の発火とは違う)。予約が rollback したら
     *   通知も残らないのが正しい状態であるため。
     * ★アプリ内通知は org 文脈を必須とする (`AppNotification::organizationId()` が
     *   non-nullable)。退会は組織に属さない事象なので、**予約時点の current org** を表示文脈として
     *   写す。current org を持たないユーザーには**作らない** (メールだけが届く。
     *   org 文脈を捏造しない)。
     */
    public function notifyAccountDeletionRequested(User $user, CarbonImmutable $purgeAfter): void
    {
        $this->safely(function () use ($user, $purgeAfter): void {
            $organizationId = $user->current_organization_id;
            if (! is_int($organizationId)) {
                return;
            }

            $state = AccountDeletionStateDto::fromUser($user);
            $graceDays = $state->graceDays();
            if ($graceDays === null) {
                return; // 予約が成立していない (呼び出し順の異常) ときは作らない
            }

            $user->notify(new AccountDeletionRequestedNotification(
                $organizationId,
                new AccountDeletionRequestedPayload($purgeAfter->toIso8601String(), $graceDays),
            ));
        });
    }

    // ── 読み出し・既読化 (NotificationController から委譲) ────────────────────

    /**
     * 自分宛のみの一覧 (notifiable = 自分で構造的に閉じる。全 org 横断)。
     *
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    public function paginateFor(User $user, int $perPage = 20): LengthAwarePaginator
    {
        // Notifiable::notifications() は latest() 適用済みの morphMany (自分宛のみ)
        return $user->notifications()->paginate($perPage);
    }

    public function unreadCountFor(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * 自分宛以外は 404 (存在オラクル封じ。403 で存在を漏らさない)。relation 経由で解決する。
     * 非UUID は pgsql の uuid 比較 (22P02) に到達させず ModelNotFoundException に寄せる
     * (web 経路は route の whereUuid が先に 404 にするが、将来の別経路呼び出しにも防護を持たせる)。
     */
    public function findOwnOrFail(User $user, string $id): DatabaseNotification
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException)->setModel(DatabaseNotification::class, $id);
        }

        return $user->notifications()->whereKey($id)->firstOrFail();
    }

    public function markRead(DatabaseNotification $notification): void
    {
        $notification->markAsRead();
    }

    public function markAllRead(User $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }

    /**
     * ジョブ通知の宛先集合: creator ∪ triggeredBy を id で dedup し、org 所属を relation 経由で
     * 再確認する (退会済みユーザーへは送らない・cross-org を構造的に作らない)。
     *
     * @return list<User>
     */
    private function resolveRecipientsForManualJob(VideoManual $manual, ?int $triggeredById, Organization $organization): array
    {
        // creator (created_by は非 null) ∪ triggeredBy を id で dedup
        $ids = array_values(array_unique(array_filter(
            [$manual->created_by, $triggeredById],
            static fn (?int $id): bool => $id !== null,
        )));

        return array_values($organization->users()->whereIn('users.id', $ids)->get()->all());
    }

    /**
     * 通知失敗はジョブ本流を絶対に壊さない (catch + report。terminal tx の外でのみ呼ばれる前提)。
     */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
