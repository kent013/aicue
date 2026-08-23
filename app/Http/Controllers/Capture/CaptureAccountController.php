<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
 *
 * **表示専用**である。表示名・ログイン ID (= メールアドレス)・所属組織はすべて
 * HandleInertiaRequests が全ページへ共有している props (auth.user / currentOrganization) で
 * 賄えるため、**ページ固有 props を 1 つも返さない**。値を二重に持たない
 * (共有 prop と page prop が食い違う余地を作らない)。
 *
 * タイトルは**静的**なので `config('seo.app_titles')` の route 既定に置く
 * (`SeoManager::setPrivateTitle()` は動的な固有名 = マニュアル名等を controller から
 * 供給する用途。静的名をそちらへ置くと bug-hunt 目録の画面名も空欄になる)。
 *
 * current organization は resolveMemberCurrentOrganization() で解決する。これは
 * 共有 prop 側 (HandleInertiaRequests) が「current_organization_id が指す組織に
 * **非所属**なら null に倒す」のと**同じ述語**をサーバ側に置くためで、
 * 到達した画面では currentOrganization が非 null であることが保証される
 * (未設定・非所属はどちらも認可より前に 404 = 組織の存在を露出しない)。
 */
class CaptureAccountController extends Controller
{
    use ResolvesRouteOrganization;

    public function __invoke(Request $request, Organization $organization): Response
    {
        // current org 解決 + 在籍 guard。**戻り値は使わず副作用 (未設定 / 非所属で 404) のために呼ぶ**

        return Inertia::render('Capture/Account');
    }
}
