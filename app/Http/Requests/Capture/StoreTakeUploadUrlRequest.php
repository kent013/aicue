<?php

declare(strict_types=1);

namespace App\Http\Requests\Capture;

use App\DataTransferObjects\Capture\Sha256Checksum;
use App\DataTransferObjects\Capture\TakeUploadInput;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * presigned upload-url 発行 (POST .../cuts/{cut}/takes/upload-url)。
 * cut_id / organization_id / video_path 等の保護キーは payload に存在するだけで 422。
 */
class StoreTakeUploadUrlRequest extends FormRequest
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
            'client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'], // ULID
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.config()->integer('capture.max_take_bytes')],
            'content_type' => ['required', 'string', Rule::in(config()->array('capture.allowed_video_content_types'))],
            // base64(32bytes) = 44 文字。toTakeUploadInput() で Sha256Checksum::fromBase64 により厳密検証
            'checksum_sha256' => ['required', 'string', 'size:44', 'regex:%^[A-Za-z0-9+/]{43}=$%'],
            // サーバ生成キー (payload から受けない)
            'video_path' => ['missing'],
        ], $this->protectedKeyMissingRules());
    }

    public function toTakeUploadInput(): TakeUploadInput
    {
        return new TakeUploadInput(
            clientTakeId: strtoupper($this->string('client_take_id')->value()),
            sizeBytes: $this->integer('size_bytes'),
            contentType: $this->string('content_type')->value(),
            checksum: Sha256Checksum::fromBase64($this->string('checksum_sha256')->value()),
        );
    }
}
