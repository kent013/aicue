<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Rules\OidcIssuerUrlRule;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 接続の更新の入力。**接続の秘密を扱ってよい唯一の前面**である (正典 v1 / I4)。
 *
 * ★**伏字の見本をそのまま更新値として受け付けない** — 未入力なら据え置きにする
 *   (伏字文字列がそのまま秘密として保存される事故を型と規則で消す)。
 *   画面は秘密の伏字を**描かない** (`SsoConnectionSummary` が持たない) ので、
 *   「見本が送られてくる」経路はそもそも存在しないが、**空文字は据え置き**として扱う。
 */
class UpdateSsoConnectionRequest extends FormRequest
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
            'display_name' => ['nullable', 'string', 'max:100'],
            'issuer' => ['nullable', 'string', 'max:255', new OidcIssuerUrlRule],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'display_name' => '表示名',
            'issuer' => '発行者 URL',
            'client_id' => 'クライアント ID',
            'client_secret' => 'クライアントシークレット',
        ];
    }

    public function displayNameValue(): ?string
    {
        $value = $this->string('display_name')->value();

        return $value === '' ? null : $value;
    }

    public function issuerValue(): ?OidcIssuerUrl
    {
        $value = $this->string('issuer')->value();

        return $value === '' ? null : OidcIssuerUrl::fromString($value);
    }

    public function clientIdValue(): ?string
    {
        $value = $this->string('client_id')->value();

        return $value === '' ? null : $value;
    }

    /** ★未入力 (空文字) は**据え置き**である。伏字が保存されることはない。 */
    public function clientSecretValue(): ?ConnectionSecret
    {
        $value = $this->string('client_secret')->value();

        return $value === '' ? null : ConnectionSecret::fromPlaintext($value);
    }
}
