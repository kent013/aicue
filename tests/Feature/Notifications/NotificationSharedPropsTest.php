<?php

declare(strict_types=1);

use App\DataTransferObjects\Notification\ManualJobPayload;
use App\Notifications\InApp\ManualAnalyzedNotification;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * shared props notifications.unreadCount (施策6):
 * - ログイン時: 全 org 横断の未読数が全 Inertia 応答へ共有される
 * - 未ログイン画面: 0 で共有される (欠落しない)
 */

test('ログイン時: unreadCount が共有される (既読分は数えない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $payload = new ManualJobPayload(1, 2, 'M', 'Org', true, null);
    $owner->notify(new ManualAnalyzedNotification($organization->id, $payload));
    $owner->notify(new ManualAnalyzedNotification($organization->id, $payload));
    $owner->notifications()->firstOrFail()->markAsRead();

    $this->actingAs($owner)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unreadCount', 1));
});

test('未ログイン画面でも unreadCount は 0 で共有される (欠落しない)', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unreadCount', 0));
});
