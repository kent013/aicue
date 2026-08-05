<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Throwable;
use Webmozart\Assert\Assert;

class UpdateUserPassword implements UpdatesUserPasswords
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * パスワード変更の検証と反映、および他デバイスのセッション・remember-me の失効。
     *
     * 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
     * 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)。
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default()],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        // 新パスワードを確定 (この後の logoutOtherDevices は保存済みハッシュに対し Hash::check する)。
        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();

        // 「そのユーザーが自分でパスワードを設定したか」の監査証跡。
        // SecurityEventType::PasswordChanged は enum に存在しながら記録経路が無かった
        // (/reset-password 経路は Illuminate の PasswordReset イベント → RecordSecurityEvent が
        //  既に購読済みのため本 Action だけが欠けていた)。
        // 将来、前方修正前に作られた legacy SSO ユーザーの phantom password
        // (docs/template-divergence.md D13) を判別する材料にもなる。
        // Fortify の PasswordUpdatedViaController ではなく Action 直記録にするのは、
        // vendor イベントの意味論 (「Fortify の Controller 経由」) に依存しないため。
        $this->recorder->record(SecurityEventType::PasswordChanged, $user);

        // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
        // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
        // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
        // web グループの AuthenticateSession による password_hash 照合が担保する (correctness の要)。
        // 渡すのは current_password ではなく保存直後の新 password。
        Auth::logoutOtherDevices($input['password']);

        // database driver の場合、当該 user の他 session 行を即時削除する (best-effort)。
        $this->deleteOtherSessionRecords($user);
    }

    /**
     * 現在の session を除き、当該 user の DB session 行を削除する (session driver=database 時のみ)。
     *
     * correctness は AuthenticateSession が担うため best-effort: 失敗しても report して継続する
     * (パスワード変更自体は成功しているため正常応答を維持する)。
     */
    private function deleteOtherSessionRecords(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        // session 未初期化文脈 (console/queue 等) では現在ID除外の前提が崩れるため何もしない。
        if (! session()->isStarted()) {
            return;
        }

        $connection = config('session.connection');
        $table = config('session.table', 'sessions');

        Assert::nullOrString($connection);
        Assert::string($table);

        try {
            DB::connection($connection)
                ->table($table)
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', session()->getId())
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
