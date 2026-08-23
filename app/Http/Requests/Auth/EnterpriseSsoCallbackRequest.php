<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\EnterpriseSso\RejectionReason;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Support\EnterpriseSso\UniformLoginFailure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * 企業 SSO の戻り口の入力。
 *
 * ★**不正な入力では外向き取得を一切開始しない**。ここで弾く。
 * ★`code` と `error` は**排他**である (両方来た応答は仕様外なので受けない)。
 * ★**validation の失敗でも入力を 1 つも flash しない**。
 *   Laravel は validation の失敗時に、controller へ到達する**前に**入力を `_old_input` へ
 *   退避する (`Handler::invalid()` が `withInput()` を呼ぶ)。したがって
 *   「controller で `withInput()` を呼ばない」だけでは `code` / `state` がセッションに残る。
 *   `failedValidation()` で**応答を自分で組み立てて**この経路そのものを塞ぐ。
 * ★`code` / `state` は一般名なのでグローバルの `dontFlash` へは足さない
 *   — 他のフォームの入力復元まで黙って変えてしまうため (経路側で閉じる)。
 */
class EnterpriseSsoCallbackRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    /** 未認証で到達する経路である (ログインの戻り口)。認可は接続の状態が担う。 */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ★**入力を 1 つも flash しない**一様な失敗へ変換する。
     *
     * 既定の実装は `_old_input` へ入力を退避してから戻すので、
     * `code` (認可コード) と `state` がセッションに残る。
     * `ValidationException` に応答を持たせると `Handler` は既定の組み立てを行わない。
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException(
            $validator,
            UniformLoginFailure::response(RejectionReason::ProviderReturnedError),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', 'string', 'max:512'],
            'code' => ['nullable', 'string', 'max:4096', 'required_without:error', 'prohibits:error'],
            'error' => ['nullable', 'string', 'max:256'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'state' => '状態値',
            'code' => '認可コード',
            'error' => 'エラー',
        ];
    }

    /** IdP が error を返したか (一様な失敗として扱う)。 */
    public function providerReturnedError(): bool
    {
        return $this->string('error')->isNotEmpty();
    }

    public function stateValue(): string
    {
        return $this->string('state')->value();
    }

    public function codeValue(): string
    {
        return $this->string('code')->value();
    }
}
