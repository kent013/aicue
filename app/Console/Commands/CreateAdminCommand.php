<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AdminUser;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * 運用者 AdminUser の発行 (初期セットアップ / break-glass)。
 *
 * 本番での初期 admin の正式発行経路は本コマンドのみ (env / seeder による投入は廃止済み。
 * AdminUserSeeder は local 開発専用)。2 人目以降は Filament 管理画面の
 * AdminUserResource から作成してもよい。
 *
 * パスワード強度は PasswordPolicy (Password::defaults() 配線済) を Validator で
 * **明示検証** する (hashed cast は検証しないため、コマンド側で必ず通す)。
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create {--email=} {--name=} {--password=}';

    protected $description = '管理者 (AdminUser) を作成する (初期セットアップ / break-glass 用)';

    public function handle(): int
    {
        $email = $this->option('email') ?? $this->ask('Email');
        $name = $this->option('name') ?? $this->ask('Name');

        if ($this->option('password') !== null) {
            // shell history / process list への平文露出リスクを毎回警告する
            $this->warn('WARNING: --password は shell history / process list に露出する可能性があります。対話入力 (--password 省略) を推奨します。');
        }
        $password = $this->option('password') ?? $this->secret(sprintf('Password (%s)', implode(' / ', PasswordPolicy::describe())));

        if (! is_string($email) || ! is_string($name) || ! is_string($password)) {
            $this->error('入力が不正です。');

            return self::FAILURE;
        }

        // 強度の SSOT は PasswordPolicy (AppServiceProvider で Password::defaults に配線済)
        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'string', 'email:rfc', 'max:255'],
                'name' => ['required', 'string', 'min:1', 'max:255'],
                'password' => ['required', 'string', ...PasswordPolicy::rules()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // 事前重複チェック (email は CipherSweet 暗号化カラムのため blind index で検索する)
        if (AdminUser::whereBlind('email', 'email_index', $email)->exists()) {
            $this->error(sprintf('AdminUser は既に存在します: %s', $email));

            return self::FAILURE;
        }

        try {
            // blind index の upsert は saved イベントで走るため、admin_users 行と
            // blind_indexes 行を同一 transaction で原子的に作る (unique 違反時に
            // email 重複の admin 行だけが残る中間状態を作らない)
            $admin = DB::transaction(fn (): AdminUser => AdminUser::create([
                'name' => $name,
                'email' => $email,
                'password' => $password, // 'hashed' cast で自動 hash
            ]));
        } catch (QueryException $e) {
            // 事前 exists チェックと insert の間に別プロセスが同 email を挿入する
            // race condition の救済。最終防衛線は blind_indexes の partial unique index
            // (PostgreSQL: SQLSTATE 23505 / SQLite・MySQL: 23000)。
            if (in_array($e->getCode(), ['23505', '23000'], true)) {
                $this->error(sprintf('AdminUser は既に存在します: %s', $email));

                return self::FAILURE;
            }

            throw $e;
        }

        $this->info(sprintf('AdminUser を作成しました: id=%d email=%s', $admin->id, $email));

        return self::SUCCESS;
    }
}
