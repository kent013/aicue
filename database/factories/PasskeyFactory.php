<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Passkey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Passkey>
 */
final class PasskeyFactory extends Factory
{
    /** @var class-string<Passkey> */
    protected $model = Passkey::class;

    /**
     * WebAuthn ceremony を伴わないテスト (削除 / 一覧 / 手段カウント / 認可) 用の最小形。
     * 実 ceremony を検証するテストは vendor の WebAuthn helper で credential を生成すること。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            // credential_id は base64url unpadded
            // (VerifyPasskey が Base64UrlSafe::encodeUnpadded で照合する形式)
            'credential_id' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'credential' => ['type' => 'public-key'],
            'last_used_at' => null,
        ];
    }
}
