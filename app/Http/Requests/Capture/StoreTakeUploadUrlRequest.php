<?php

declare(strict_types=1);

namespace App\Http\Requests\Capture;

use App\DataTransferObjects\Capture\Sha256Checksum;
use App\DataTransferObjects\Capture\TakeUploadInput;
use App\Enums\Manual\MaterialType;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Support\Capture\TakeMaterialClassifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // 上限はまず**両者の最大**で受け、種別ごとの上限は after フックで判定する
            // (Rule::in を通る前に size の上限を種別で切り替えられないため)
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.self::maxAllowedBytes()],
            'content_type' => ['required', 'string', Rule::in(self::allowedContentTypes())],
            // base64(32bytes) = 44 文字。toTakeUploadInput() で Sha256Checksum::fromBase64 により厳密検証
            'checksum_sha256' => ['required', 'string', 'size:44', 'regex:%^[A-Za-z0-9+/]{43}=$%'],
            // サーバ生成キー / サーバ確定値 (payload から受けない)
            'video_path' => ['missing'],
            'material_type' => ['missing'],
        ], $this->protectedKeyMissingRules());
    }

    /**
     * 種別ごとのバイト上限 (静止画に 500 MiB を許さない)。
     * content_type が確定した後でないと判定できないため after フックで見る。
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return; // 型が確定していない段階では判定しない
                }
                $limit = TakeMaterialClassifier::fromContentType($this->string('content_type')->value())
                    === MaterialType::Still
                        ? config()->integer('capture.max_still_bytes')
                        : config()->integer('capture.max_take_bytes');
                if ($this->integer('size_bytes') > $limit) {
                    $validator->errors()->add('size_bytes', '選択したファイルのサイズが上限を超えています。');
                }
            },
        ];
    }

    /** @return list<string> */
    private static function allowedContentTypes(): array
    {
        /** @var list<string> $video */
        $video = array_values(config()->array('capture.allowed_video_content_types'));
        /** @var list<string> $still */
        $still = array_values(config()->array('capture.allowed_still_content_types'));

        return [...$video, ...$still];
    }

    private static function maxAllowedBytes(): int
    {
        return max(
            config()->integer('capture.max_take_bytes'),
            config()->integer('capture.max_still_bytes'),
        );
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
