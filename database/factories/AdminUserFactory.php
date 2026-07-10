<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    /**
     * factory 共通パスワードのハッシュキャッシュ (毎回の bcrypt を避ける)。
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * MFA (TOTP) 設定済み state。
     *
     * `app_authentication_secret` は 16 文字 Base32 (Filament の `generateSecret()` と同等の固定値)。
     * `app_authentication_recovery_codes` は plain code を `Hash::make()` した hash 配列
     * (Filament の保存形式と同じ) を recoveryCodeCount(8) に合わせて 8 個格納する。
     * cast (`encrypted` / `encrypted:array`) は AdminUser の casts() で適用されるため plain 値を渡す。
     */
    public function withMfa(): static
    {
        return $this->state(fn (array $attributes): array => [
            'app_authentication_secret' => 'JBSWY3DPEHPK3PXP',
            'app_authentication_recovery_codes' => collect(range(1, 8))
                ->map(fn (int $i): string => Hash::make(sprintf('test-recovery-code-%02d', $i)))
                ->all(),
        ]);
    }
}
