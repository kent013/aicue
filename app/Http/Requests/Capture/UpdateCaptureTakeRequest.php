<?php

declare(strict_types=1);

namespace App\Http\Requests\Capture;

use App\DataTransferObjects\Capture\CaptureTakeUpdateInput;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * テイクのコメント・並べ替え (PATCH .../takes/{take})。
 * sort_order は入力名から排除 (受けない。position をサーバ再採番する)。
 */
class UpdateCaptureTakeRequest extends FormRequest
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
            'comment' => ['nullable', 'string', 'max:2000'],
            'position' => ['nullable', 'integer', 'min:0'],
            // サーバ採番・サーバ確定値 (payload から受けない)
            'sort_order' => ['missing'],
            'status' => ['missing'],
            'video_path' => ['missing'],
            'size_bytes' => ['missing'],
            'downloaded_at' => ['missing'],
        ], $this->protectedKeyMissingRules());
    }

    public function toCaptureTakeUpdateInput(): CaptureTakeUpdateInput
    {
        return new CaptureTakeUpdateInput(
            hasComment: $this->exists('comment'),
            comment: $this->filled('comment') ? $this->string('comment')->value() : null,
            position: $this->filled('position') ? $this->integer('position') : null,
        );
    }
}
