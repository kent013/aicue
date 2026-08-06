<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webmozart\Assert\Assert;

/**
 * /oauth/authorize POST (approve) の先頭で、consent で選ばれた
 * organization_id を検証し、McpAuthCodeRepository が読む
 * request attribute `mcp_selected_organization_id` に int として詰める。
 *
 * - 非 member 組織を body に改ざんして送ってきても 403 で弾く。
 * - `organization_id` が無い場合は attribute を set しないので、
 *   McpAuthCodeRepository は organization 列を書かない (no-op、非 MCP 経路で安全)。
 */
final class McpConsentOrganizationBinder
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->input('organization_id');
        if ($raw === null || $raw === '') {
            // consent に organization_id が無ければ MCP フローではない、素通しする。
            $response = $next($request);
            Assert::isInstanceOf($response, Response::class);

            return $response;
        }

        // 既存の bool guard は必ず残す。filter_var(true, FILTER_VALIDATE_INT) は
        // 1 を返す (PHP 8.4 実測) ため、これが無いと `organization_id=true` が
        // 組織 id 1 として membership 判定に流れ、入力分類契約が崩れる。
        if (is_bool($raw)) {
            throw new HttpException(422, 'Invalid organization_id.');
        }

        // `is_numeric('1.5')` 由来の truncation 事故防止。
        // filter_var(FILTER_VALIDATE_INT) は `1.5` / `1e5` / `abc` / 先頭ゼロ (`001`) を reject し、
        // `min_range => 1` で 0 / 負数も拒否する。前後空白と符号 (`' 1'` / `'1 '` / `'+1'`) は
        // 許容され int へ正規化される (PHP 8.4 実測。ConsentOrganizationBinderTest が固定)。
        $orgId = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($orgId === false) {
            throw new HttpException(422, 'Invalid organization_id.');
        }

        $user = $request->user();
        if (! $user instanceof User) {
            // 通常は前段の Authenticate:web (priority list で本 middleware より前に走る) が
            // 未認証を弾くため到達しない。万一 middleware 順序が崩れても 500 ではなく
            // fail-secure に login へ倒し、回帰耐性を持たせる。
            return redirect()->guest(route('login'));
        }

        // membership は organization_user pivot が単一ソース。**組織を fetch してから**
        // 判定すると「不在 = 422 / 実在の非 member = 403」で組織の実在が 1 bit 漏れるため、
        // 整数として受理した id は 1 つ残らずここへ流し、同一の 403 に落とす (aicue:T118)。
        if (! $user->organizations()->whereKey($orgId)->exists()) {
            // consent 画面から非 member 組織を選べない UI ガードを迂回した場合の最終防御。
            throw new HttpException(403, 'You are not a member of the selected organization.');
        }

        $request->attributes->set('mcp_selected_organization_id', $orgId);

        $response = $next($request);
        Assert::isInstanceOf($response, Response::class);

        return $response;
    }
}
