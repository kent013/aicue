<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * メールアドレスの昇格の開始 (確認メールの発行)。
 *
 * ★認可は「自分の資源」なので Gate を通さない (controller が `Auth::id()` だけを使う)。
 * ★ここで受けるのは**宛先のメールアドレスだけ**である。利用者を選ぶ入力を受けない。
 */
class StoreEmailPromotionRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'email' => 'メールアドレス',
        ];
    }

    public function emailValue(): string
    {
        return $this->string('email')->value();
    }
}
