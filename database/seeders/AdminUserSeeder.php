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
 * 許可環境以外では skip する。許可は二段構え:
 * - local: 無条件 (開発 DX)
 * - bughunt.local: DB 名 guard (^bug_hunt(_[1-8])?$) 必須 (bug-hunt 管理画面探索用)
 */
class AdminUserSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;

    private const EMAIL = 'admin@example.com';

    private const PASSWORD = 'password12345';

    private const NAME = 'Local Admin';

    public function run(): void
    {
        // local (無条件) と bughunt.local (bug_hunt DB のみ) 専用。
        // bughunt.local でも DB 名を検証するのは、誤って dev DB を向いた
        // APP_ENV=bughunt.local 実行で既知資格情報の admin を dev DB に作らないため
        // (bughunt seeder 群の fail-secure と同強度)。本番の初期 admin は admin:create。
        if (! $this->shouldSeed()) {
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

    private function shouldSeed(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return app()->environment('bughunt.local') && $this->isBughuntDatabase();
    }
}
