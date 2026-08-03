<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DataTransferObjects\Auth\SessionStatusDto;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\SessionStatusResource;
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
 */
final class SessionStatusController extends Controller
{
    public function __invoke(Request $request): SessionStatusResource
    {
        return SessionStatusResource::make(
            new SessionStatusDto(authenticated: $request->user() !== null),
        );
    }
}
