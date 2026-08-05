<?php

declare(strict_types=1);

namespace App\Http\Responses\Passkey;

use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * passkey 削除完了 (vendor contract の差し替え)。
 *
 * vendor 既定は `new JsonResponse(status: 204)` を直に返し禁止事項 4 に触れる。
 * transport 契約 (詳細設計 4-d) により削除は Inertia の `router.delete` で送るため、
 * `back()->with('success')` で一覧 prop を再取得させる。
 *
 * ⚠ 本応答は EnsureLoginMethodRemains が開いた **transaction の中**で生成される
 * (middleware が $next() を transaction 内で実行するため)。外部 I/O を持ち込まないこと。
 */
final class PasskeyDeletedResponse implements PasskeyDeletedResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): SymfonyResponse
    {
        return back()->with('success', 'パスキーを削除しました。');
    }
}
