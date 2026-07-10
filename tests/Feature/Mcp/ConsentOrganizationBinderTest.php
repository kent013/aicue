<?php

declare(strict_types=1);

use App\Http\Middleware\McpConsentOrganizationBinder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * consent 画面からの organization 改ざん耐性 (WP23)。
 *
 * 組織選択は Blade dropdown で UI ガードするが、body を直接改変すれば
 * 非 member 組織の id も送れる。McpConsentOrganizationBinder で最終防御する。
 */

beforeEach(function (): void {
    [$this->memberOrg, $this->user] = createOrganizationWithOwner('所属組織');
    [$this->nonMemberOrg] = createOrganizationWithOwner('非所属組織');

    $this->middleware = new McpConsentOrganizationBinder;
});

test('member の組織を選ぶと mcp_selected_organization_id attribute が set される', function (): void {
    $request = Request::create('/oauth/authorize', 'POST', [
        'organization_id' => $this->memberOrg->id,
    ]);
    $request->setUserResolver(fn () => $this->user);

    $hit = false;
    $this->middleware->handle($request, function (Request $r) use (&$hit) {
        $hit = true;

        return response('ok');
    });

    expect($hit)->toBeTrue();
    expect($request->attributes->get('mcp_selected_organization_id'))->toBe($this->memberOrg->id);
});

test('非 member 組織の id を body に仕込むと 403 (改ざん防御)', function (): void {
    $request = Request::create('/oauth/authorize', 'POST', [
        'organization_id' => $this->nonMemberOrg->id,
    ]);
    $request->setUserResolver(fn () => $this->user);

    try {
        $this->middleware->handle($request, fn () => response('ok'));
        $this->fail('Expected HttpException to be thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(403);
        expect($e->getMessage())->toContain('not a member');
    }

    expect($request->attributes->get('mcp_selected_organization_id'))->toBeNull();
});

test('organization_id が数値でない場合は 422 (body injection)', function (): void {
    $request = Request::create('/oauth/authorize', 'POST', [
        'organization_id' => 'abc',
    ]);
    $request->setUserResolver(fn () => $this->user);

    try {
        $this->middleware->handle($request, fn () => response('ok'));
        $this->fail('Expected HttpException to be thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
});

test('存在しない organization_id は 422', function (): void {
    $request = Request::create('/oauth/authorize', 'POST', [
        'organization_id' => 999_999,
    ]);
    $request->setUserResolver(fn () => $this->user);

    try {
        $this->middleware->handle($request, fn () => response('ok'));
        $this->fail('Expected HttpException to be thrown.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }
});

test('organization_id 欠落 (非 MCP oauth フロー) は素通しで attribute も set しない', function (): void {
    $request = Request::create('/oauth/authorize', 'POST', []);
    $request->setUserResolver(fn () => $this->user);

    $hit = false;
    $this->middleware->handle($request, function (Request $r) use (&$hit) {
        $hit = true;

        return response('ok');
    });

    expect($hit)->toBeTrue();
    expect($request->attributes->get('mcp_selected_organization_id'))->toBeNull();
});
