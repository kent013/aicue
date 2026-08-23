<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Rules\OidcIssuerUrlRule;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 接続の登録の入力。**接続の秘密を扱ってよい唯一の前面**である (正典 v1 / I4)。
 *
 * ★`client_secret` は `bootstrap/app.php` の `dontFlash` に登録済みである
 *   (登録しないと validation 失敗時に秘密が old input としてセッションに残る)。
 * ★validation の応答・監査ログ・例外・要求の記録にも含めない。
 * ★認可は route の `Gate::authorize` が担う (FormRequest では判定しない)。
 */
class StoreSsoConnectionRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'login_slug' => [
                'required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9-]*[a-z0-9]\z/',
                Rule::unique('organization_oidc_connections', 'login_slug'),
            ],
            'display_name' => ['required', 'string', 'max:100'],
            'issuer' => ['required', 'string', 'max:255', new OidcIssuerUrlRule],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'login_slug' => '識別名',
            'display_name' => '表示名',
            'issuer' => '発行者 URL',
            'client_id' => 'クライアント ID',
            'client_secret' => 'クライアントシークレット',
        ];
    }

    public function loginSlugValue(): string
    {
        return $this->string('login_slug')->value();
    }

    public function displayNameValue(): string
    {
        return $this->string('display_name')->value();
    }

    public function issuerValue(): OidcIssuerUrl
    {
        return OidcIssuerUrl::fromString($this->string('issuer')->value());
    }

    public function clientIdValue(): string
    {
        return $this->string('client_id')->value();
    }

    /** ★平文が現れる**唯一の**場所。値型へ包んですぐ渡す (素の文字列を持ち回らない)。 */
    public function clientSecretValue(): ConnectionSecret
    {
        return ConnectionSecret::fromPlaintext($this->string('client_secret')->value());
    }
}
