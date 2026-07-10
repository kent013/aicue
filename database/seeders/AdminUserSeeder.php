<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;

/**
 * local 開発専用の固定 AdminUser を投入する Seeder (冪等)。
 *
 * テンプレート判断: local 開発 DX のため固定値 seeder を維持するが、
 * 正式な admin 発行経路は `php artisan admin:create` コマンドである
 * (env ADMIN_INITIAL_* による初期 admin 投入は廃止済み)。
 * **本番では本 Seeder を使わず admin:create を使うこと**。
 * 誤って production / staging / CI で db:seed されても作成しないよう
 * local 環境以外では skip する。
 */
class AdminUserSeeder extends Seeder
{
    private const EMAIL = 'admin@example.com';

    private const PASSWORD = 'password12345';

    private const NAME = 'Local Admin';

    public function run(): void
    {
        // local 専用 (本番の初期 admin は admin:create コマンドで発行する)
        if (! app()->environment('local')) {
            return;
        }

        // email は CipherSweet 暗号化カラムのため firstOrCreate(['email' => ...]) では
        // 既存行に hit しない。blind index (whereBlind) で冪等化する
        // (再実行してもパスワードは上書きしない)
        $admin = AdminUser::whereBlind('email', 'email_index', self::EMAIL)->first()
            ?? AdminUser::create([
                'email' => self::EMAIL,
                'name' => self::NAME,
                'password' => self::PASSWORD, // hashed cast が自動でハッシュ化する
            ]);

        $this->command->info(sprintf(
            'AdminUser (local 開発用): %s (id=%d, password=%s)',
            self::EMAIL,
            $admin->id,
            self::PASSWORD,
        ));
    }
}
