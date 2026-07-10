<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 暗号化 email の一意性検証 rule。
 *
 * users.email は CipherSweet 暗号化カラムのため `unique:users,email` では検証できない。
 * blind index (whereBlind) 照合で既存登録の有無を判定する。
 */
class UniqueEncryptedEmail implements ValidationRule
{
    /**
     * @param  int|null  $ignoreId  更新時に自分自身を一意性チェックから除外する User id
     * @param  string|null  $message  上書きメッセージ。未指定時は account enumeration を
     *                                抑止する中立既定。当該メールの登録有無を露呈しないため、
     *                                公開・認証済みのどの経路から rule を使っても safe-by-default で漏らさない。
     *                                admin 画面など enumeration リスクが無い文脈 (= 既に全 user を閲覧可能) では
     *                                呼び出し側が明示的に「既に使用されています」等の親切な文言を opt-in する。
     */
    public function __construct(
        private ?int $ignoreId = null,
        private ?string $message = null,
    ) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $exists = User::whereBlind('email', 'email_index', $value)
            ->when($this->ignoreId, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($exists) {
            // 既定は enumeration 中立 (手段非開示)。登録有無を露呈する文言は
            // 呼び出し側が安全な文脈で明示 opt-in する。
            $fail($this->message ?? 'このメールアドレスは使用できません。');
        }
    }
}
