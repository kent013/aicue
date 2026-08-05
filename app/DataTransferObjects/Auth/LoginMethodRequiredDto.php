<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * ログイン手段が 0 になる操作を拒否したときに、純 XHR へ返す 422 ボディ。
 *
 * Inertia には返さない (Inertia protocol は 422 JSON を解釈できず無言失敗するため、
 * `back()->withErrors()` の 302 を返す。EnsureLoginMethodRemains::reject() 参照)。
 */
final readonly class LoginMethodRequiredDto
{
    /**
     * 422 契約の判別子。クライアントは status だけでなく code 厳格一致で
     * 自分宛ての応答のみ処理する (他の 422 契約との誤食防止)。
     */
    public const string CODE = 'login_method_required';

    public function __construct(
        public string $message,
    ) {}
}
