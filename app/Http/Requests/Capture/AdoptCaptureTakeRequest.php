<?php

declare(strict_types=1);

namespace App\Http\Requests\Capture;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * テイク採用 (POST .../takes/{take}/adopt)。adopt は body を一切使わない
 * (採用対象は URL の {take})。保護キー (adopted_take_id 等) の payload 混入は
 * tenant キー不信の入口防御として 422 で拒否する (defense-in-depth。bug-hunt F-1-03)。
 */
class AdoptCaptureTakeRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true; // 認可は controller の Gate::authorize (URL 整合 guard の後)
    }

    /**
     * body 入力は無い。保護キー混入だけを missing で拒否する (最小)。
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->protectedKeyMissingRules();
    }
}
