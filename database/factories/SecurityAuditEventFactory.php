<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityAuditEvent>
 *
 * 監査行そのものを作る factory。**アプリの記録経路ではない**
 * (本番の記録は App\Services\Security\SecurityEventRecorder の 1 本道のみ)。
 * 過去時刻の行 (「3 か月前のログイン」等) をテストで用意するために置く。
 */
class SecurityAuditEventFactory extends Factory
{
    /**
     * user 未指定なら UserFactory に連鎖する (親 Factory 連鎖の規約)。
     * 既定は login / now() / guard=web。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => SecurityEventType::Login->value,
            'metadata' => ['guard' => 'web'],
            'ip_address' => fake()->ipv4(),
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    /** 記録対象の利用者を指定する (user_id は所有権キーのため state で明示代入する) */
    public function forUser(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    /** 種別を差し替える (login 以外を数えないことの検査に使う) */
    public function ofType(SecurityEventType $type): static
    {
        return $this->state(fn (): array => ['event_type' => $type->value]);
    }

    /**
     * 発生時刻を指定する (最新の 1 件が選ばれることの検査に使う)。
     *
     * ⚠ 引数が CarbonImmutable なのは**呼び出し側の都合**である。
     * SecurityAuditEvent の casts() は occurred_at を 'datetime' (**mutable** Carbon) と
     * 宣言しており、モデルから読み戻した値は mutable のままである
     * (「監査モデルが immutable を返す」と読まないこと。immutable になるのは
     *  LastLoginLookup が withCasts で作る別名 last_login_at だけである)。
     */
    public function occurredAt(CarbonImmutable $at): static
    {
        return $this->state(fn (): array => ['occurred_at' => $at]);
    }
}
