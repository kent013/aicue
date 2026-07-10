<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 完成 mp4 ダウンロード (GET .../download?lang=)。
 * lang は v1 では任意 + `ja` のみ許可 (それ以外は 422。feature_multilang は後続。
 * クエリ形状だけ doc/10 §10.3 と互換に保つ)。
 */
class DownloadManualRequest extends FormRequest
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
            'lang' => ['sometimes', 'string', 'in:ja'],
            ...$this->protectedKeyMissingRules(),
        ];
    }
}
