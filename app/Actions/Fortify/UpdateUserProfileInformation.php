<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Notifications\EmailChangedSecurityNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Webmozart\Assert\Assert;

/**
 * プロフィール (name / email) 更新。
 *
 * メール変更時 (Q11 決定):
 * - 旧アドレスへセキュリティ通知を送る (新アドレスは旧保持者に非開示。乗っ取り検知導線)
 * - email_verified_at を null 化して新アドレスの再検証を要求する
 * - email の一意性は whereBlind で明示チェック (暗号化カラムのため unique rule 不可)
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->validateWithBag('updateProfileInformation');

        $name = $validated['name'];
        $email = $validated['email'];
        Assert::string($name);
        Assert::string($email);

        if ($email === $user->email) {
            $user->forceFill(['name' => $name])->save();

            return;
        }

        if ($this->emailTakenByOther($email, $user)) {
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには変更できません。'],
            ])->errorBag('updateProfileInformation');
        }

        $oldEmail = $user->email;

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
        ])->save();

        // 旧アドレスへの on-demand セキュリティ通知 (アカウントを持たない宛先にも送れる経路)
        Notification::route('mail', $oldEmail)
            ->notify(new EmailChangedSecurityNotification);

        $user->sendEmailVerificationNotification();
    }

    /**
     * @phpstan-impure
     */
    private function emailTakenByOther(string $email, User $user): bool
    {
        return User::whereBlind('email', 'email_index', $email)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}
