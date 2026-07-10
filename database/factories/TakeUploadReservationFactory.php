<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Capture\TakeUploadReservationStatus;
use App\Models\Cut;
use App\Models\TakeUploadReservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Webmozart\Assert\Assert;

/**
 * @extends Factory<TakeUploadReservation>
 */
class TakeUploadReservationFactory extends Factory
{
    /**
     * cut 未指定なら CutFactory に連鎖する (親 Factory 連鎖の規約)。
     * organization_id は configure() で cut→manual→project→org を辿ってサーバ導出する
     * (実装 Service の forceFill 導出と同じ経路。明示指定したい場合は forCut() を使う)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cut_id' => Cut::factory(),
            'client_take_id' => (string) Str::ulid(),
            'video_path' => 'projects/1/manuals/1/cuts/1/takes/'.(string) Str::ulid().'.mp4',
            'size_bytes' => fake()->numberBetween(100_000, 50_000_000),
            'content_type' => 'video/mp4',
            'checksum_sha256' => base64_encode(hash('sha256', fake()->uuid(), true)),
            'status' => TakeUploadReservationStatus::Pending->value,
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function configure(): static
    {
        // organization_id 未指定なら cut の親 chain から導出 (creating hook = insert 前に確定)
        return $this->afterMaking(function (TakeUploadReservation $reservation): void {
            if ($reservation->getAttribute('organization_id') !== null) {
                return;
            }
            $organization = $reservation->cut?->videoManual?->project?->organization;
            Assert::notNull($organization, 'TakeUploadReservationFactory: cut の親 chain から organization を導出できません');
            $reservation->forceFill(['organization_id' => $organization->id]);
        });
    }

    /** 指定カット配下に作る (organization は cut の親 chain から導出される) */
    public function forCut(Cut $cut): static
    {
        return $this->state(fn () => ['cut_id' => $cut->id]);
    }

    /** claim 中 (POST takes 検証中) の状態 */
    public function verifying(): static
    {
        return $this->state(fn () => ['status' => TakeUploadReservationStatus::Verifying->value]);
    }

    /** 登録完了済みの状態 */
    public function completed(): static
    {
        return $this->state(fn () => ['status' => TakeUploadReservationStatus::Completed->value]);
    }

    /** 解放済みの状態 */
    public function released(): static
    {
        return $this->state(fn () => ['status' => TakeUploadReservationStatus::Released->value]);
    }

    /** 期限切れの予約 */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
