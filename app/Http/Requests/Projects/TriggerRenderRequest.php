<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * レンダ/プレビュートリガー (POST .../render, .../preview 共用)。本 endpoint は入力を受けない
 * (対象カット・チケット・org は全てサーバ導出) ため、
 * 保護キーの missing rule のみ張る (ticket_reservation_id 等の直送は 422)。
 */
class TriggerRenderRequest extends FormRequest
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
        return $this->protectedKeyMissingRules();
    }
}
