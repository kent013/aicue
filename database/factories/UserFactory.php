<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
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
     * 2FA 有効・confirmed 状態のユーザーを生成する。
     *
     * Fortify の EnableTwoFactorAuthentication / ConfirmTwoFactorAuthentication
     * Action と同じカラム構造 (暗号化済みの本物の TOTP secret + recovery codes +
     * confirmed_at) を直接書き込む。recoveryCodes() /
     * hasEnabledTwoFactorAuthentication() / TOTP チャレンジがそのまま動く。
     */
    public function withTwoFactor(): static
    {
        return $this->afterCreating(function (User $user): void {
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
        });
    }
}
