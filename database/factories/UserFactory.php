<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Support\Account\AccountDeletionGrace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * SSO-only ユーザー (password を持たず外部 IdP でのみ認証する)。
     * email は IdP 側で検証済みの前提のため email_verified_at を立てる。
     * password 経路の可否判定は User::hasPassword() (fail-closed) が担う。
     */
    public function ssoOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'password' => null,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * 退会予約中 (凍結方式) のユーザー。**users 行の生死は変えない**ので、埋めるのは予約列 2 本だけ。
     *
     * 両列は同時に埋まる (DB の CHECK 制約 users_deletion_request_pair_check が片列だけを拒否する)。
     * `$purgeAfter` 未指定なら猶予日数の SSOT (AccountDeletionGrace) から導出する
     * = テストが猶予日数を独自に持たない。
     */
    public function pendingDeletion(?CarbonImmutable $requestedAt = null, ?CarbonImmutable $purgeAfter = null): static
    {
        return $this->state(function (array $attributes) use ($requestedAt, $purgeAfter): array {
            $requested = $requestedAt ?? CarbonImmutable::now();

            return [
                'deletion_requested_at' => $requested,
                'deletion_purge_after' => $purgeAfter ?? AccountDeletionGrace::purgeAfter($requested),
            ];
        });
    }

    /**
     * 2FA 有効・confirmed 状態のユーザーを生成する。
     *
     * Fortify の EnableTwoFactorAuthentication / ConfirmTwoFactorAuthentication
     * Action と同じカラム構造 (暗号化済みの本物の TOTP secret + recovery codes +
     * confirmed_at) を直接書き込む。recoveryCodes() /
     * hasEnabledTwoFactorAuthentication() / TOTP チャレンジがそのまま動く。
     */
    public function withTwoFactor(): static
    {
        return $this->afterCreating(static fn (User $user) => self::enableTwoFactorFor($user));
    }

    /**
     * 既存ユーザーを 2FA 準拠 (confirmed) 状態へ遷移させる。
     *
     * `withTwoFactor()` state と**同一の実装**を共有する (2 箇所に書かない)。
     * 「未準拠のまま作ったユーザーが、途中で準拠を達成する」導線を検証するテスト
     * (2FA 必須組織での退会予約の取消など) から呼ぶ。
     */
    public static function enableTwoFactorFor(User $user): void
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        /** @var Collection<int, string> $codes */
        $codes = Collection::times(8, fn (): string => RecoveryCode::generate());

        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                (string) json_encode($codes->all()),
            ),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }
}
