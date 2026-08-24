/**
 * 組織まわりの画面が受け取る型。
 *
 * ★`OrganizationEntryTarget` は PHP の `App\Enums\Organization\EntryTarget` の写しである。
 *   値集合の一致は `tests/js/architecture/enum-ts-sync.test.ts` の目録 (`ENUM_TS_RELATIONS`) が
 *   固定するので、**ここに値を足したら PHP 側にも足す** (逆も同じ)。
 */

/** 組織を確定したあとに向かう先 (App\Enums\Organization\EntryTarget の写し)。 */
export type OrganizationEntryTarget = "capture" | "dashboard";

/** 組織を選ぶ画面が 1 件ぶん受け取る組織 (OrganizationChoiceData と対)。 */
export interface OrganizationChoice {
    id: number;
    name: string;
    slug: string;
}

/** 組織を選ぶ画面 (Organizations/Choose) の props。 */
export interface OrganizationChoosePageProps {
    target: OrganizationEntryTarget;
    organizations: OrganizationChoice[];
}
