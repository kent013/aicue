<?php

declare(strict_types=1);

namespace App\Http\Requests\Capture;

use App\DataTransferObjects\Capture\CaptureSyncInput;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Webmozart\Assert\Assert;

/**
 * 一括同期 (POST .../manuals/{manual}/sync)。照合専用 payload (概念設計 D8)。
 * 入力名は保護キー cut_id と別名の `cut` (Category の `category` 入力名と同じ境界規約)。
 * ネスト位置の保護キー直送 (takes.*.cut_id) も 422。
 */
class SyncCaptureTakesRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true; // 認可は controller の Gate::authorize (URL 整合 guard の後)
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'takes' => ['present', 'array', 'max:500'],
            'takes.*.cut' => ['required', 'integer'],
            'takes.*.client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
            'takes.*.cut_id' => ['missing'], // ネスト位置の保護キー直送も 422
        ], $this->protectedKeyMissingRules());
    }

    public function toCaptureSyncInput(): CaptureSyncInput
    {
        $takes = $this->validated('takes');
        Assert::isArray($takes);

        return CaptureSyncInput::fromValidated($takes);
    }
}
