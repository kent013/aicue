<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Exceptions\Organization\InvalidOrganizationSlugException;
use App\Exceptions\Organization\SlugRenameLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateOrganizationSlugRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationSlugRenameLimiter;
use App\Support\Organization\AssignableOrganizationSlug;
use App\Support\Organization\OrganizationSlug;
use App\Support\Organization\OrganizationSlugConstraintViolation;
use App\Support\Organization\OrganizationSlugReservedWords;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * 識別名の変更 (家系裁定 AG-046)。
 *
 * ★層 2 (テナント境界 404 = binder) が層 3 (認可 403) より前である既存順序に乗る。
 *   binding の 404 だけでは **same-org の一般メンバーによる改名を防げない**ので
 *   `Gate::authorize('update')` を通す。
 * ★**競合の結果**だけをここで 422 へ変換する (入力の妥当性は FormRequest の担当)。
 *   識別できない `QueryException` は**再送出**する (隠さない)。
 */
final class OrganizationSlugController extends Controller
{
    public function update(
        UpdateOrganizationSlugRequest $request,
        Organization $organization,
        OrganizationSlugRenameLimiter $limiter,
    ): RedirectResponse {
        Gate::authorize('update', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $input = $request->validated('slug');
        Assert::string($input);

        // FormRequest のカスタムルールを通過している = 構文妥当かつ非予約語。
        // ここでの昇格は型を得るためであり、失敗し得ない経路ではあるが検査点は迂回しない。
        $slug = AssignableOrganizationSlug::promote(
            OrganizationSlug::fromString($input),
            OrganizationSlugReservedWords::load(),
        );

        try {
            $limiter->rename($organization, $slug, $user);
        } catch (InvalidOrganizationSlugException|SlugRenameLimitExceededException $e) {
            throw ValidationException::withMessages(['slug' => [$e->getMessage()]]);
        } catch (QueryException $e) {
            if (! OrganizationSlugConstraintViolation::isSlugTaken($e)) {
                throw $e;   // 別の一意違反は隠さず再送出する
            }
            throw ValidationException::withMessages(['slug' => ['この識別名は既に使われています。']]);
        }

        // ★旧 URL へ back() すると直後に 404 になる。**新しい識別名を明示して**遷移する。
        //   モデルをそのまま渡すと getRouteKeyName() = 'id' により URL に id が入る危険があるため、
        //   名前付き引数で slug の**文字列**を渡す。
        return redirect()
            ->route('organizations.settings', ['organization' => $slug->value])
            ->with('success', '組織の識別名を変更しました');
    }
}
