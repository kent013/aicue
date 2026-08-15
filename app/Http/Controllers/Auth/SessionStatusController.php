<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DataTransferObjects\Auth\SessionStatusDto;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\SessionStatusResource;
use App\Support\Auth\SessionEpoch;
use Illuminate\Http\Request;

/**
 * セッション有効性の軽量プローブ (詳細設計 施策 6)。
 *
 * bfcache から復元された認証済み画面を「秘匿したまま」再検証するために、
 * クライアント guard (resources/js/lib/bfcache-guard.ts) が pageshow 直後に叩く。
 *
 * auth グループの **外**に置く: auth 配下だと未認証時に 302/401 になり、guard 側で
 * 「セッション無効」と「endpoint 不在 / ネットワーク障害」を区別しにくくなる。
 * guest でも 200 + `authenticated: false` を返し、判定を明示 boolean 一本にする
 * (認証状態は同一オリジンの呼び出し元が cookie で既に知りうる情報であり、
 * これ自体は新たな情報露出にならない)。
 *
 * **秘匿を解く唯一の根拠がこの応答である**。復元された文書が持つ描画世代の印を
 * `X-Session-Epoch` ヘッダで受け取り、いまのセッションの世代と一致するかを
 * `sessionEpochMatches` で返す。認証済みでも世代が違えば画面側は開示せず読み直す。
 * **現世代の値そのものは応答に載せない** (一致か否かだけ分かればよい)。
 */
final class SessionStatusController extends Controller
{
    public function __invoke(Request $request): SessionStatusResource
    {
        // 照合に使うのは **要求ヘッダで運ばれた描画世代** だけである。
        // 要求の Cookie ヘッダに載る世代 cookie は画面側から書き換えられる値なので、
        // 一致判定には一切使わない (開示の根拠に client 側の状態を混ぜない)。
        // 受け取ったヘッダ値はログにも応答にも出さない (外部由来の可変文字列)。
        $submitted = $request->headers->get(SessionEpoch::HEADER_NAME);

        return SessionStatusResource::make(new SessionStatusDto(
            authenticated: $request->user() !== null,
            sessionEpochMatches: SessionEpoch::matches(
                is_string($submitted) ? $submitted : null,
                SessionEpoch::current($request),
            ),
        ));
    }
}
