<?php

declare(strict_types=1);

namespace App\Http\Responses\Passkey;

use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Laravel\Passkeys\Passkey as VendorPasskey;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * passkey 登録完了 (vendor contract の差し替え)。
 *
 * vendor 既定は `new JsonResponse([...])` を直に返し禁止事項 4 に触れる。
 * transport 契約 (詳細設計 4-d) により登録は Inertia の `router.post` で送るため、
 * `back()->with('success')` で一覧 prop を再取得させる (既存 2FA カードと同じ作法)。
 * 操作系 POST のため `redirect()->intended()` は使わない (禁止事項 7)。
 *
 * 成功 toast はサーバ flash を単一の源とし、client 側で楽観 toast を出さない
 * (リカバリコード再生成と同じ二重発火回避)。
 */
final class PasskeyRegistrationResponse implements PasskeyRegistrationResponseContract
{
    /**
     * vendor contract が要求する setter。
     *
     * 登録された passkey は **応答本文に載せない** (一覧は Inertia prop =
     * SecurityController が単一の源)。保持する必要が無いため保存もしない。
     */
    public function withPasskey(VendorPasskey $passkey): static
    {
        return $this;
    }

    /**
     * @param  Request  $request
     */
    public function toResponse($request): SymfonyResponse
    {
        return back()->with('success', 'パスキーを登録しました。');
    }
}
