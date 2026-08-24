<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Support\EnterpriseSso\AttemptFingerprint;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * メールアドレスの昇格の確定。
 *
 * ★確定は **POST だけ**である (GET の確認画面は状態を変えない)。
 *   署名付き GET のリンクだけだと、メールクライアントの先読みやプレビューで
 *   **利用者が意図せず確定してしまう**。
 * ★**validation の失敗でも入力を 1 つも flash しない**。
 *   Laravel は validation の失敗時に、controller へ到達する**前に**入力を `_old_input` へ
 *   退避するので、「controller で `withInput()` を呼ばない」だけでは
 *   **トークンがセッションに残る**。`failedValidation()` で応答を自分で組み立てて塞ぐ。
 * ★`token` は一般名なのでグローバルの `dontFlash` へは足さず、**経路側で閉じる**。
 */
class ConfirmEmailPromotionRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    /** 失敗の行き先 (照合の失敗と同じ)。 */
    public const string FAILURE_ROUTE = 'settings.security';

    /** 失敗のエラーキー (照合の失敗と同じ)。 */
    public const string ERROR_KEY = 'email_promotion';

    /** 失敗の文言 (照合の失敗と**同じ**。区別を外から読み取れないようにする)。 */
    public const string FAILURE_MESSAGE = 'この確認リンクは無効か、有効期限が切れています。'
        .'もう一度手続きをやり直してください。';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * ★**入力を 1 つも flash しない**失敗へ変換する (トークンをセッションに残さない)。
     *
     * 行き先と文言は「無効なトークン」と**同じ**である
     * (validation で落ちたか照合で落ちたかを外から区別できない = 存在を漏らさない)。
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator, redirect()->route(self::FAILURE_ROUTE)->withErrors([
            self::ERROR_KEY => self::FAILURE_MESSAGE,
        ]));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        // 長さの上限は指紋の元になる一時値の実長 (base64url 43 文字) に十分な余裕を持たせる。
        return [
            'token' => ['required', 'string', 'max:'.(AttemptFingerprint::HEX_LENGTH * 4)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'token' => '確認トークン',
        ];
    }

    public function tokenValue(): string
    {
        return $this->string('token')->value();
    }
}
