<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Enums\OrganizationRole;
use App\Exceptions\Organization\OrganizationSlugTakenException;
use App\Exceptions\Organization\ReservedOrganizationSlugException;
use App\Models\CustomTeam;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Support\Organization\AssignableOrganizationSlug;
use App\Support\Organization\OrganizationSlug;
use App\Support\Organization\OrganizationSlugConstraintViolation;
use App\Support\Organization\OrganizationSlugReservedWords;
use App\Support\Organization\SlugCandidate;
use App\Support\Organization\SlugCandidateOrigin;
use Generator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 組織生成の唯一の窓口。あらゆる経路 (画面 / 登録 / シーダー / Factory) がここを通ることで
 * Default Team パターンの不変条件「どの Organization にも Default Team がちょうど 1 つ」を
 * 構造的に担保する (docs/default-team-pattern.md)。
 *
 * ★識別名は値オブジェクト 1 本 (`AssignableOrganizationSlug`) を経由してのみ保存する
 *   (家系裁定 AG-039)。**値オブジェクトは値を捏造しない**ので、導出不能時の代替を決めるのは
 *   本 Service の責務である。
 * ★初期組織生成の冪等判定は「**所属組織が 0 件かどうか**」で行う (AG-038。種別フラグは撤去)。
 */
class OrganizationProvisioningService
{
    /** Fallback 候補 (`org-{乱数}`) を試す上限。無限ループを作らない。 */
    private const int FALLBACK_ATTEMPTS = 3;

    /**
     * 組織 + Laratrust Team + Default Team を原子的に生成し、creator を Owner にする。
     *
     * ★`$requestedSlug` は**利用者が明示した識別名**。矯正も代替もしない
     *   (構文違反・予約語はそのまま例外になり FormRequest 層の 422 へ、
     *   一意衝突は `OrganizationSlugTakenException` になり Controller の 422 へ)。
     * ★null のときは組織名から導出し、導出不能・予約語・衝突なら `org-{乱数}` へ倒す。
     *
     * @throws OrganizationSlugTakenException 利用者が明示した識別名が使用済み
     */
    public function provision(User $creator, string $name, ?string $requestedSlug = null): Organization
    {
        foreach ($this->candidates($requestedSlug, $name) as $candidate) {
            try {
                // ★1 試行 = 1 transaction 境界。外側 transaction の中なら savepoint、
                //   外側が無ければトップレベル transaction の rollback になる。
                //   どちらの場合も失敗試行の書き込み (Team / Default Team / role 付与) は残らない。
                return DB::transaction(fn (): Organization => $this->createWith($creator, $name, $candidate->slug));
            } catch (QueryException $e) {
                if (! OrganizationSlugConstraintViolation::isSlugTaken($e)) {
                    throw $e;   // 別の一意違反は隠さず再送出する
                }
                if ($candidate->origin === SlugCandidateOrigin::Requested) {
                    throw new OrganizationSlugTakenException($candidate->slug);
                }
                // Derived → Fallback、Fallback → 新しい乱数、は candidates() の generator が決める
            }
        }

        throw new RuntimeException('識別名の候補を使い切った');
    }

    /**
     * 登録時の初期組織生成 (冪等)。
     *
     * ★冪等判定は「**所属組織が 0 件かどうか**」で行う (家系裁定 AG-038。種別フラグは撤去した)。
     * ★判定はトランザクション内で**利用者の行を取り直して行ロック**し、ロック後のクエリで数える。
     *   呼び出し側が読み込み済みのリレーションに依存しない。
     */
    public function provisionInitialOrganization(User $user): Organization
    {
        return DB::transaction(function () use ($user): Organization {
            // ★binding/actor 由来の再取得 (payload の id ではない)。
            //   DirectFetchInventory に ActorBackedReload として登録済み。
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            /** @var Organization|null $existing */
            $existing = $locked->organizations()->orderBy('organizations.id')->first();

            return $existing ?? $this->provision($locked, "{$locked->name} の組織");
        });
    }

    /**
     * 保存を試みる識別名の候補列。**由来を型で持つ** (由来ごとに衝突時の遷移が違う)。
     *
     * - 利用者が明示した → その 1 件だけ (代替を作らない)
     * - 導出できた → 導出値 1 件、続けて Fallback を最大 3 件
     * - 導出できない / 導出値が予約語 → Fallback を最大 3 件
     *
     * @return Generator<int, SlugCandidate>
     */
    private function candidates(?string $requested, string $name): Generator
    {
        $reserved = OrganizationSlugReservedWords::load();

        if ($requested !== null) {
            // 利用者が明示した値は矯正も代替もしない (例外はそのまま FormRequest 層の 422 になる)
            yield new SlugCandidate(
                AssignableOrganizationSlug::promote(OrganizationSlug::fromString($requested), $reserved),
                SlugCandidateOrigin::Requested,
            );

            return;
        }

        $derived = OrganizationSlug::deriveFromName($name);
        if ($derived !== null) {
            try {
                yield new SlugCandidate(
                    AssignableOrganizationSlug::promote($derived, $reserved),
                    SlugCandidateOrigin::Derived,
                );
            } catch (ReservedOrganizationSlugException) {
                // 導出結果が予約語なら黙って使わず、フォールバックへ倒す
            }
        }

        for ($attempt = 0; $attempt < self::FALLBACK_ATTEMPTS; $attempt++) {
            yield new SlugCandidate(
                AssignableOrganizationSlug::promote(self::fallbackSlug(), $reserved),
                SlugCandidateOrigin::Fallback,
            );
        }
    }

    /** `org-{12 文字の小文字英数字}`。構文型を必ず通す (捏造した文字列を直接保存しない)。 */
    private static function fallbackSlug(): OrganizationSlug
    {
        return OrganizationSlug::fromString('org-'.Str::lower(Str::random(12)));
    }

    /**
     * 1 試行ぶんの生成。
     *
     * ★順序契約: `slug` を持つ Organization の **save() が成功した後**に
     *   `users()->attach()` と `addRole()` を行う。一意違反は save() で起きるので、
     *   この順序なら**失敗試行で Laratrust の role 付与が走らない**
     *   (role の cache は DB の rollback では戻らないため、そもそも走らせない)。
     * ★引数は**保存可能型**である。organizations.slug を書ける経路はこの型を受ける 1 本だけで、
     *   構文型や生文字列を渡す道は型で消えている (OrganizationSlugWritePathTest が固定する)。
     */
    private function createWith(User $creator, string $name, AssignableOrganizationSlug $slug): Organization
    {
        $team = new Team;
        $team->name = 'org-'.Str::lower(Str::random(12));
        $team->display_name = $name;
        $team->save();

        $organization = new Organization(['name' => $name]);
        $organization->laratrustTeam()->associate($team);
        // slug は $fillable 外 (保存可能型を受ける 1 本道でのみ書く)
        $organization->forceFill(['slug' => $slug->value]);
        $organization->save();

        // Default Team (不変条件: 組織ごとにちょうど 1 つ。is_default は $fillable 外)
        $defaultTeam = new CustomTeam(['name' => $name]);
        $defaultTeam->organization()->associate($organization);
        $defaultTeam->forceFill(['is_default' => true]);
        $defaultTeam->save();

        $organization->users()->attach($creator);
        $creator->addRole(OrganizationRole::Owner->value, $organization->laratrust_team_id);

        return $organization;
    }
}
