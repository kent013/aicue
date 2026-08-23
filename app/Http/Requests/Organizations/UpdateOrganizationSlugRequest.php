<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Organization;
use App\Rules\AssignableOrganizationSlugRule;
use App\Support\Organization\OrganizationSlug;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * 識別名の変更 (家系裁定 AG-046)。認可は Controller 冒頭の `Gate::authorize('update')` が担う
 * (FormRequest は validation 単独責務 = テンプレート規約)。
 *
 * ★構文違反・予約語・**同一識別名**の 3 つをここで 422 にする。
 *   ロック後にしか分からない競合 (回数上限・一意衝突・検証後に値が変わった同一識別名) は
 *   Controller が変換する。
 */
class UpdateOrganizationSlugRequest extends FormRequest
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
        return array_merge([
            'slug' => [
                'required',
                'string',
                'max:'.OrganizationSlug::MAX_LENGTH,
                new AssignableOrganizationSlugRule,
                $this->rejectUnchanged(),
            ],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * 現在値と同じ識別名は 422 で拒否する (回数を消費させない。no-op を成功にすると
     * 利用者から見て「変えたのに変わっていない」になる)。
     *
     * ★これは**早期拒否**であり権威ではない。検証後からロック取得までに値が変わり得るので、
     *   最終判定は Service がロック後に再度行う。
     */
    private function rejectUnchanged(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $organization = $this->route('organization');
            if (! $organization instanceof Organization || ! is_string($value)) {
                return;
            }
            if (Str::lower($value) === $organization->slug) {
                $fail('現在の識別名と同じ値には変更できません。');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['slug' => '識別名'];
    }
}
