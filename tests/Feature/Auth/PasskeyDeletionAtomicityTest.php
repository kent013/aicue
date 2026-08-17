<?php

declare(strict_types=1);

use App\Enums\SecurityEventType;
use App\Models\Passkey;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Passkeys\Actions\DeletePasskey;
use Laravel\Passkeys\Events\PasskeyDeleted;

/*
 * パスキー削除の原子性 (台帳 auth-passkey-hardening 施策 3b / aicue:T216 施策 C)。
 *
 * パッケージ側の削除処理は「行を消してからイベントを発火する」形で、2 つを
 * トランザクションで包まない。本アプリはこの性質を **EnsureLoginMethodRemains が
 * 削除 route 全体をトランザクションで包むこと**で埋めている。
 * ここではその 2 つを実挙動で固定する (性質の固定は PasskeyPackageContractTest)。
 *
 * ⚠ 巻き戻るのは「**同期の購読が、削除と同じトランザクションの中で失敗したとき**」だけである。
 *   購読が commit 後へ回されていたら (キュー投入 / commit 後実行) 削除は確定済みになる。
 *   その形が入らないことはキュー投入の原子性のゲートが別途固定しているが、
 *   本テストの保証ではない (誇張しない)。
 */

test('パッケージ側の削除処理を単体で呼ぶと、購読側が失敗しても行は消えている (非原子性の実挙動)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    $passkey = Passkey::factory()->for($user)->create();

    Event::listen(PasskeyDeleted::class, function (): void {
        throw new RuntimeException('listener failure');
    });

    expect(fn () => app(DeletePasskey::class)($user, $passkey))
        ->toThrow(RuntimeException::class, 'listener failure');

    // ★包まれていないので行は消えたまま = これが埋め合わせの必要な状態である
    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
});

test('HTTP 削除経路では同期購読の失敗で削除ごと巻き戻る (関門がトランザクション境界)', function (): void {
    $user = User::factory()->ssoOnly()->create();
    // 手段が残る状態 (passkey 2 本) にして EnsureLoginMethodRemains を通す
    $passkeys = Passkey::factory()->count(2)->for($user)->create();
    $target = $passkeys->firstOrFail();

    Event::listen(PasskeyDeleted::class, function (): void {
        throw new RuntimeException('listener failure');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->delete("/user/passkeys/{$target->getKey()}"))
        ->toThrow(RuntimeException::class, 'listener failure');

    // 行も監査記録も同じ transaction で巻き戻る
    expect(Passkey::query()->whereKey($target->getKey())->exists())->toBeTrue();
    expect(SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::PasskeyDeleted->value)
        ->count())->toBe(0);
});
