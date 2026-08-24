<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\DataTransferObjects\Organizations\SlugRenameQuotaDto;
use App\Exceptions\Organization\InvalidOrganizationSlugException;
use App\Exceptions\Organization\SlugRenameLimitExceededException;
use App\Models\Organization;
use App\Models\OrganizationSlugRename;
use App\Models\User;
use App\Support\Organization\AssignableOrganizationSlug;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * 識別名の改名 (家系裁定 AG-046 / 不変条件 I12・I13)。
 *
 * ★最終権威は**組織行を行ロックした後の再判定**である。事前判定 (画面表示のための残り回数) は
 *   早期拒否にすぎず、ここでの再判定が唯一の権威である。
 * ★30 日は**ローリング窓**である。境界は **`renamed_at > now - 30 日`** (境界を**含まない**)。
 *   包含にすると「最古 + 30 日」ちょうどの時刻でまだ窓内になり、
 *   画面が案内する nextAvailableAt に到達しても改名できない (案内と挙動が食い違う)。
 * ★**旧識別名は予約せず解放する** (I13)。履歴表に一意制約を張らないので、
 *   改名した直後から他の組織がその識別名を取れる。
 * ★同じ識別名への改名は 422 で拒否する (回数を消費させない。no-op を成功にすると
 *   利用者から見て「変えたのに変わっていない」になる)。
 *
 * **`subDays()` を使う (`subDaysNoOverflow()` ではない)**。AGENTS.md が `*NoOverflow` を
 * 必須にしているのは**月・年・四半期**の加減算であり、日の加減算に overflow は起きない。
 */
class OrganizationSlugRenameLimiter
{
    /** ローリング窓の長さ (日)。 */
    public const int WINDOW_DAYS = 30;

    /** 窓あたりの上限回数。 */
    public const int LIMIT = 5;

    /**
     * 画面表示のための残り回数 (**権威ではない**)。
     */
    public function quotaFor(Organization $organization): SlugRenameQuotaDto
    {
        $now = CarbonImmutable::now();
        $used = $this->usedWithinWindow($organization, $now);
        $remaining = max(0, self::LIMIT - $used->count());

        $oldest = $used->count() >= self::LIMIT ? $used->first() : null;
        $nextAvailableAt = $oldest instanceof OrganizationSlugRename
            ? $oldest->renamed_at->addDays(self::WINDOW_DAYS)
            : null;

        return new SlugRenameQuotaDto($remaining, $nextAvailableAt);
    }

    /**
     * 改名の実行。
     *
     * @throws InvalidOrganizationSlugException ロック後に同一識別名だと判明した
     * @throws SlugRenameLimitExceededException 窓内の回数上限に達している
     * @throws QueryException 一意制約違反 (識別名が他組織に取られていた) 等。
     *                        Controller が制約名まで見て 422 へ変換する
     */
    public function rename(Organization $organization, AssignableOrganizationSlug $slug, User $actor): void
    {
        DB::transaction(function () use ($organization, $slug, $actor): void {
            // ★binding 済みモデルの主キーで取り直す (DirectFetchInventory へ
            //   BindingBackedReload として登録済み。payload 由来の id ではない)
            $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            // ★基準時刻は **ロック取得後**に確定する。ロック待ちが起きたとき、待つ前の時刻で
            //   判定すると「既に窓外へ出た履歴」を数えて誤って拒否する。
            //   以降 cutoff と renamed_at はこの 1 つの値を使う。
            $now = CarbonImmutable::now();

            if ($locked->slug === $slug->value) {
                throw InvalidOrganizationSlugException::unchanged();
            }

            $used = $this->usedWithinWindow($locked, $now);

            if ($used->count() >= self::LIMIT) {
                // 次に改名できる時刻 = 窓内で最も古い履歴の renamed_at + 30 日。
                // ★count() >= LIMIT から first() の非 null 性を PHPStan は推論しないので、
                //   Assert で絞ってから使う (nullable を例外へ渡すと契約が弱くなる)。
                $oldest = $used->first();
                Assert::isInstanceOf($oldest, OrganizationSlugRename::class);

                throw new SlugRenameLimitExceededException($oldest->renamed_at->addDays(self::WINDOW_DAYS));
            }

            $from = $locked->slug;
            $locked->forceFill(['slug' => $slug->value])->save();

            // ★tenant/actor キーを mass assignment しない (セキュリティ不変条件 1)。
            //   relation で associate し、サーバ導出値だけを明示代入する。
            $rename = new OrganizationSlugRename;
            $rename->organization()->associate($locked);
            $rename->renamedBy()->associate($actor);
            $rename->forceFill(['from_slug' => $from, 'to_slug' => $slug->value, 'renamed_at' => $now]);
            $rename->save();
        });
    }

    /**
     * 窓内の履歴 (古い順)。境界は含まない (`renamed_at > now - 30 日`)。
     *
     * @return Collection<int, OrganizationSlugRename>
     */
    private function usedWithinWindow(Organization $organization, CarbonImmutable $now)
    {
        return $organization->slugRenames()
            ->where('renamed_at', '>', $now->subDays(self::WINDOW_DAYS))
            ->orderBy('renamed_at')
            ->get();
    }
}
