<?php

declare(strict_types=1);

use App\Listeners\Audit\RejectNonCriticalAudit;
use App\Models\AdminUser;
use App\Models\Concerns\AppliesCriticalActionContextToAudit;
use App\Models\ModelAudit;
use App\Models\Project;
use App\Support\CriticalActionContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Contracts\Auditor;
use OwenIt\Auditing\Events\Auditing;

/**
 * テスト専用の Auditable モデル (items テーブルを間借り)。
 *
 * テンプレートは Auditable なドメインモデルを同梱しないため、gating
 * (RejectNonCriticalAudit + AppliesCriticalActionContextToAudit) の検証には
 * このテストローカルモデルを使う。派生アプリでの実装手順の見本でもある:
 * implements Auditable + use AuditableTrait + use AppliesCriticalActionContextToAudit。
 */
final class AuditGatingTestItem extends Model implements AuditableContract
{
    use AppliesCriticalActionContextToAudit;
    use AuditableTrait {
        AppliesCriticalActionContextToAudit::transformAudit insteadof AuditableTrait;
    }

    protected $table = 'items';

    /** @var list<string> */
    protected $fillable = ['name', 'note'];
}

/**
 * items テーブルに Auditable なテストモデル行を作る (project_id は tenant キーのため明示代入)。
 */
function createAuditGatingTestItem(): AuditGatingTestItem
{
    $project = Project::factory()->create();

    $item = new AuditGatingTestItem(['name' => '監査対象アイテム']);
    $item->project_id = $project->id;
    $item->save();

    return $item;
}

beforeEach(function (): void {
    /** @var CriticalActionContext $ctx */
    $ctx = app(CriticalActionContext::class);
    $ctx->deactivate();
});

test('context inactive のとき audit は記録されない', function (): void {
    $item = createAuditGatingTestItem();

    $before = ModelAudit::count();
    $item->update(['name' => '変更後']);

    expect(ModelAudit::count())->toBe($before);
});

test('context inactive の変更は warning ログで可視化される', function (): void {
    $item = createAuditGatingTestItem();
    Log::spy();

    $item->update(['name' => '変更後']);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'critical_attribute_change_without_context'
                && $context['auditable_type'] === AuditGatingTestItem::class
                && in_array('name', $context['dirty_keys'], true)
                && array_key_exists('running_in_console', $context)
                && array_key_exists('running_in_queue', $context);
        })
        ->atLeast()
        ->once();
});

test('context active のとき audit が記録され context メタが注入される', function (): void {
    $item = createAuditGatingTestItem();

    /** @var CriticalActionContext $ctx */
    $ctx = app(CriticalActionContext::class);
    $ctx->run(
        actionName: 'item.rename',
        reason: 'テスト用の変更理由',
        callback: fn () => $item->update(['name' => '変更後']),
        adminActionId: 'uuid-test-1',
        ticketId: 'TICKET-9',
    );

    $audit = ModelAudit::query()->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->event)->toBe('updated');
    expect($audit->auditable_type)->toBe(AuditGatingTestItem::class);
    // AppliesCriticalActionContextToAudit trait による注入
    expect($audit->new_values)->toMatchArray([
        'name' => '変更後',
        '__reason' => 'テスト用の変更理由',
        '__ticket_id' => 'TICKET-9',
        '__admin_action_id' => 'uuid-test-1',
    ]);
    expect($audit->parsedTags())->toContain('source:admin_panel', 'action:item.rename', 'admin_action_id:uuid-test-1');
    expect($audit->displayReason())->toBe('テスト用の変更理由');
    expect($audit->displaySource())->toBe('admin_panel');
    expect($audit->displayAction())->toBe('item.rename');
    expect($audit->adminActionId())->toBe('uuid-test-1');
});

test('deleted event は new_values の null 意味論を保ち old_values に context メタを merge する', function (): void {
    $item = createAuditGatingTestItem();

    /** @var CriticalActionContext $ctx */
    $ctx = app(CriticalActionContext::class);
    $ctx->run(
        actionName: 'item.delete',
        reason: '削除理由',
        callback: fn () => $item->delete(),
    );

    $audit = ModelAudit::query()->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->event)->toBe('deleted');
    expect($audit->new_values)->toBe([]);
    expect($audit->old_values)->toMatchArray(['__reason' => '削除理由']);
    expect($audit->displayReason())->toBe('削除理由');
});

test('causer は admin guard 優先で解決され AdminUser が記録される', function (): void {
    $item = createAuditGatingTestItem();
    $admin = AdminUser::factory()->create();
    $this->actingAs($admin, 'admin');

    /** @var CriticalActionContext $ctx */
    $ctx = app(CriticalActionContext::class);
    $ctx->run(
        actionName: 'item.rename',
        reason: '管理操作',
        callback: fn () => $item->update(['name' => '管理者による変更']),
    );

    $audit = ModelAudit::query()->latest('id')->first();
    expect($audit)->not->toBeNull();
    expect($audit->user_type)->toBe(AdminUser::class);
    expect($audit->user_id)->toBe($admin->id);
});

test('AuditCustom (isCustomEvent) 経路は context 無しでも listener を通過する', function (): void {
    $item = createAuditGatingTestItem();

    /** @var RejectNonCriticalAudit $listener */
    $listener = app(RejectNonCriticalAudit::class);

    /** @var Auditor $auditor */
    $auditor = app(Auditor::class);

    $item->isCustomEvent = true;
    $event = new Auditing($item, $auditor->auditDriver($item));

    expect($listener->handle($event))->toBeTrue();
});

test('RejectNonCriticalAudit listener はちょうど 1 回だけ登録されている', function (): void {
    $listeners = Event::getListeners(Auditing::class);

    expect(count($listeners))->toBe(1);
});
