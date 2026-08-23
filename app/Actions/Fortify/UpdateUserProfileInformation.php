<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Notifications\EmailChangedSecurityNotification;
use App\Services\Security\SecurityEventRecorder;
use App\Support\EmailHash;
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
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

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

        // 監査 metadata 用の鍵つきハッシュは**保存の前**に算出する。EmailHash は
        // config('app.key') が文字列であることを要求するため、前提が崩れているなら
        // 不可逆な状態変更 (アドレスの書き換え・確認済みの解除・旧アドレスへの通知) が
        // 起きる前に落ちるほうが安全である。
        // ★旧アドレスは **null になりうる** — 企業 SSO でしか入れない利用者は使えるメールを
        //   1 件も持たない (T253 / A3)。宛先が無いので旧アドレスの鍵つきハッシュも通知も無い。
        $auditMetadata = [
            'old_email_hash' => $oldEmail === null ? null : EmailHash::compute($oldEmail),
            'new_email_hash' => EmailHash::compute($email),
        ];

        // ★`email_verified_at` は**必ず消す** (T253 / A3 の規約)。
        //   企業 SSO の利用者は `email = null` かつ `email_verified_at != null` という状態を持つので、
        //   ここで消さないと**別経路で入れたメールが自動的に確認済みになる**。
        //   メールを確認済みにしてよいのはメール昇格 (E1) の確定だけである。
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
        ])->save();

        // 監査証跡。SecurityEventType::EmailChanged は enum に存在しながら記録経路が
        // 無かった (T108 S7 の SecurityEventCoverageTest が deny-by-default で検出)。
        // 通知 (検知導線) と監査ログ (事後追跡) は同じ事象の両輪なので同じ場所で記録する。
        //
        // metadata には**生アドレスと鍵なしの変換値は載せない** (家系の裁定 AG-195)。
        // 載せる 2 値は HMAC-SHA256 (鍵は app.key) で、鍵を持たない者には乱数、
        // 鍵保持者でも復元はできず、手元の候補アドレスとの一致確認にだけ使える。
        // 乗っ取り調査で「どのアドレスからどのアドレスへ変わったか」を追うための値である
        // (users.email は上書きされるため、旧アドレスは他のどこにも残らない)。
        // **観測専用**である。この 2 値で分岐する処理は 1 つも作らない。
        $this->recorder->record(SecurityEventType::EmailChanged, $user, $auditMetadata);

        // 旧アドレスへの on-demand セキュリティ通知 (アカウントを持たない宛先にも送れる経路)。
        // ★旧アドレスが無い (企業 SSO のみの利用者) なら送り先が無いので送らない。
        if ($oldEmail !== null) {
            Notification::route('mail', $oldEmail)
                ->notify(new EmailChangedSecurityNotification);
        }

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
