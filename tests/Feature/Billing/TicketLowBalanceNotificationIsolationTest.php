<?php

declare(strict_types=1);

use App\Models\Billing\TicketReservation;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| 低残高通知の隔離 (AG-127 の付随的副作用)
|--------------------------------------------------------------------------
|
| 通知は reserve の tx を抜けた最後に同期実行される。**通知チャネルの例外で reserve を
| 巻き戻さない**ことを固定する (NotificationCenterService::safely() 本体を通す =
| サービス全体を mock しない)。
|
| ★ 保証範囲を誇張しない: ここが保証するのは**アプリケーション層の例外分離だけ**である。
|   reserve が呼び出し側の tx にネストされている場合、通知 INSERT の SQL 層の失敗は
|   PostgreSQL の transaction abort を経て業務操作ごと失敗させうる (設計 §保証しないもの 6)。
*/

/** database channel を必ず throw する fake へ差し替える (呼び出し回数を記録する)。 */
final class ThrowingDatabaseChannel extends DatabaseChannel
{
    /** 実際に通知経路を通ったことを assert するためのカウンタ。 */
    public static int $calls = 0;

    public function send($notifiable, Notification $notification): void
    {
        self::$calls++;

        throw new RuntimeException('通知チャネルの意図的な失敗');
    }
}

test('通知チャネルが例外を投げても reserve は成功し予約行が残る', function (): void {
    Log::spy();
    ThrowingDatabaseChannel::$calls = 0;
    config()->set('billing.ticket_low_balance_threshold', 5);
    app()->bind(DatabaseChannel::class, ThrowingDatabaseChannel::class);

    [$organization] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');

    // 10 → 4 で閾値 5 を跨ぐ = 通知が走る (そして必ず throw する)
    $reservation = app(TicketLedgerService::class)->reserve($organization, 6);

    // ★ 「通知経路が全く走らなかった」場合でも緑になる偽グリーンを塞ぐ:
    //   fake channel が実際に呼ばれ、例外が握られたことまで固定する
    //   (owner/admin 宛のため 1 回以上。人数は組織構成に依存するので下限で見る)
    expect(ThrowingDatabaseChannel::$calls)->toBeGreaterThan(0);
    expect($reservation->amount)->toBe(6);
    expect(TicketReservation::query()->whereKey($reservation->getKey())->exists())->toBeTrue();
    expect(app(TicketLedgerService::class)->availableTrueBalance($organization))->toBe(4);
});
