<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SecurityEventType;
use App\Models\AdminUser;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AdminUser の MFA (TOTP) を id 指定でリセットする break-glass コマンド。
 *
 * 認証装置の紛失等で管理画面にログインできなくなった運用者の復旧経路。
 * 監査証跡のため --reason (空白を除き 10 文字以上) を必須とし、
 * security_audit_events に記録する。リセット後は次回ログイン時に
 * MFA の再セットアップが強制される (AdminPanelProvider の isRequired 設定)。
 */
class ResetAdminMfaCommand extends Command
{
    protected $signature = 'admin:reset-mfa {id : 対象 AdminUser の id} {--reason= : 監査証跡に残す理由 (必須・10 文字以上)}';

    protected $description = '管理者 (AdminUser) の MFA を id 指定でリセットする (break-glass 用・reason 必須)';

    public function handle(SecurityEventRecorder $recorder): int
    {
        $id = (int) $this->argument('id');
        $reasonOption = $this->option('reason');

        if ($id <= 0) {
            $this->error('id が不正です。');

            return self::FAILURE;
        }

        if (! is_string($reasonOption)) {
            $this->error('--reason は必須です。');

            return self::FAILURE;
        }

        // 監査証跡の有用性のため理由は最低 10 文字 (マルチバイト文字数基準) を要求する
        $reason = trim($reasonOption);
        if ($reason === '' || mb_strlen($reason, 'UTF-8') < 10) {
            $this->error('--reason は必須です (空白を除き 10 文字以上)。');

            return self::FAILURE;
        }

        $admin = AdminUser::query()->find($id);
        if ($admin === null) {
            $this->error(sprintf('AdminUser が見つかりません: id=%d', $id));

            return self::FAILURE;
        }

        DB::transaction(static function () use ($admin, $reason, $recorder): void {
            // MFA の 2 列のみを null 化する (password 等の他カラムには触れない)
            $admin->app_authentication_secret = null;
            $admin->app_authentication_recovery_codes = null;
            $admin->save();

            $recorder->record(SecurityEventType::AdminMfaReset, null, [
                'admin_user_id' => $admin->id,
                'reason' => $reason,
                'executed_by' => 'cli:admin:reset-mfa',
            ]);
        });

        $this->info(sprintf('AdminUser id=%d の MFA をリセットしました。次回ログイン時に再セットアップが要求されます。', $admin->id));

        return self::SUCCESS;
    }
}
