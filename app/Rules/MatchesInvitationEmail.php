<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\OrganizationInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 登録フォームの email が session に保管された招待 token の招待先 email と一致するかを検証する
 * (spirux の MatchesInvitationEmail 相当)。
 *
 * - session に invitation_token が無い / 不正型 / 空文字 → pass (通常登録)
 * - token が DB に存在しない / 失効 / 受諾済み / 取り消し済み → pass
 *   (validation 経路では弾かず、CreateNewUser の受諾処理が中立メッセージで処理する責務分離)
 * - email 不一致のみ validation failure を返す
 *
 * constructor は session 値を `mixed` で受け取り内部で fail-secure に narrow する。
 */
class MatchesInvitationEmail implements ValidationRule
{
    public function __construct(private readonly mixed $invitationToken) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($this->invitationToken) || $this->invitationToken === '') {
            return;
        }
        if (! is_string($value)) {
            return;
        }

        // 平文 token は DB 非保存。sha256 hash で照合する
        /** @var OrganizationInvitation|null $invitation */
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', OrganizationInvitation::hashToken($this->invitationToken))
            ->first();
        if ($invitation === null) {
            return;
        }

        // 受諾不能 (失効/受諾済/取り消し) は後段の受諾処理が中立メッセージで扱う
        if ($invitation->isAccepted() || $invitation->isRevoked() || $invitation->isExpired()) {
            return;
        }

        if ($invitation->email !== $value) {
            $fail('招待されたメールアドレスと一致しません。招待メール記載のアドレスをご確認ください。');
        }
    }
}
