<?php

declare(strict_types=1);

namespace App\Http\Requests\Capture;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DL 済み ACK (POST .../takes/{take}/downloaded)。署名 ACK トークンのみを受ける (概念設計 D6)。
 */
class MarkTakeDownloadedRequest extends FormRequest
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
            'ack_token' => ['required', 'string', 'max:2048'],
            // サーバ打刻 (payload から受けない)
            'downloaded_at' => ['missing'],
        ], $this->protectedKeyMissingRules());
    }
}
