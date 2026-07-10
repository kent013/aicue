<?php

declare(strict_types=1);

namespace App\Http\Requests\Capture;

use App\DataTransferObjects\Capture\TakeRegistrationInput;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * テイク登録 (POST .../cuts/{cut}/takes)。チケットは検証専用 (代入に使わない)。
 */
class StoreCaptureTakeRequest extends FormRequest
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
            'ticket' => ['required', 'string', 'max:2048'],
            'client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
            'duration_ms' => ['nullable', 'integer', 'min:0', 'max:3600000'],
            'captured_at' => ['nullable', 'date'],
            // サーバ確定値 (payload から受けない)
            'video_path' => ['missing'],
            'size_bytes' => ['missing'],
            'status' => ['missing'],
            'sort_order' => ['missing'],
        ], $this->protectedKeyMissingRules());
    }

    public function toTakeRegistrationInput(): TakeRegistrationInput
    {
        return new TakeRegistrationInput(
            ticket: $this->string('ticket')->value(),
            clientTakeId: strtoupper($this->string('client_take_id')->value()),
            durationMs: $this->filled('duration_ms') ? $this->integer('duration_ms') : null,
            capturedAt: $this->date('captured_at')?->toImmutable(),
        );
    }
}
