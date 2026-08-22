<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\DataTransferObjects\Organizations\OrganizationChoiceData;
use App\Enums\Organization\EntryTarget;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 組織文脈を持たない入口からの分岐 (家系裁定 AG-037 と矛盾しない形)。
 *
 * ★**状態を一切保存しない**。所属が 1 組織ならその組織へ転送、複数なら選ぶ画面、
 *   0 件なら組織作成へ。保持列も切替 endpoint も作らない。
 * ★複数所属で**自動選択しない** (自動選択は保持列の再発明であり、裁定が禁じる裏口そのもの)。
 * ★遷移先は入口ごとの**固定表**から選ぶ。query string で受け取らない (open redirect を作らない)。
 * ★`/app` と `/go` は **parameter を持たない固定 route** なので、backed enum を
 *   Controller 引数へ注入することはできない (Laravel の enum binding は route parameter に働く)。
 *   **現在の route 名を固定表へ写して EntryTarget を得る**。
 */
final class OrganizationEntryController extends Controller
{
    /** @var array<string, EntryTarget> route 名 → 遷移先の固定表 (query string で操作させない)。 */
    private const array TARGET_BY_ROUTE = [
        'capture.entry' => EntryTarget::Capture,   // GET /app (PWA の start_url)
        'app.entry' => EntryTarget::Dashboard,     // GET /go
    ];

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $routeName = $request->route()?->getName();
        // ★先に string へ絞ってから keyExists する。キャストした式だけを検査すると、
        //   後続の配列アクセスで PHPStan が ?string のままだと判断し得る。
        Assert::string($routeName);
        // 固定表に無い route から呼ばれたら配線ミス。fail-closed (500)。
        Assert::keyExists(self::TARGET_BY_ROUTE, $routeName);
        $target = self::TARGET_BY_ROUTE[$routeName];

        // ★membership を **1 回だけ**取得して使い回す。count() と sole() を別クエリにすると、
        //   その間に membership が変わったとき 0 件 / 複数件の例外になる。
        $organizations = $user->organizations()->orderBy('organizations.name')->get();

        if ($organizations->isEmpty()) {
            return redirect()->route('organizations.create');
        }

        if ($organizations->count() === 1) {
            // ★sole() は同じ Collection に対して呼ぶ (再クエリしない)。
            //   URL には **slug の文字列**を名前付きで渡す
            //   (モデルを渡すと getRouteKeyName()=id により id が入る)。
            return redirect()->route($target->routeName(), ['organization' => $organizations->sole()->slug]);
        }

        return Inertia::render('Organizations/Choose', [
            'target' => $target->value,
            'organizations' => OrganizationChoiceData::collect($organizations),
        ]);
    }
}
