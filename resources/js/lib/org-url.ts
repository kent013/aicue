/**
 * 組織 URL 配下のパスを組み立てる唯一の入口 (家系裁定 AG-037)。
 *
 * ★組織文脈は **URL だけ**で決まる。保持列も切替 endpoint も存在しないので、
 *   「いまどの組織か」の出所も **URL そのもの**にする (共有 prop から読むと、
 *   同じ事実の写しが 2 つになる = 2 方式の併存を小さく再発明することになる)。
 * ★**業務パスをベタ書きしない**。素の `/projects/...` `/billing` などは
 *   組織を持たない旧 URL であり、`LegacyOrganizationlessUrlAbsenceTest` が
 *   リポジトリ内に 1 件も無いことを固定する。
 * ★slug を**明示的に渡す形** (`orgUrl`) を基本とする。現在地から読む `currentOrgUrl` は
 *   「いま表示している組織の画面から、その組織の別画面へ行く」用途に限る。
 */

/** `/organizations/{slug}/…` から識別名を取り出す正規表現 (識別名の構文は AG-039b と対)。 */
const ORGANIZATION_PATH = /^\/organizations\/([a-z0-9]+(?:-[a-z0-9]+)*)(?:\/|$)/;

/** 明示した組織の URL を組み立てる (slug を名前付きで受ける = 引数ずれを型で防ぐ)。 */
export function orgUrl(organizationSlug: string, path: string): string {
    return `/organizations/${organizationSlug}${path}`;
}

/**
 * いま表示している URL の組織識別名。
 *
 * 組織 URL 配下でないところから呼ぶと**例外で落ちる** — 黙って
 * `/organizations/undefined/...` のような壊れた URL を作らないためである。
 * 組織文脈を持たない面 (個人設定など) では、そもそも組織 URL を組み立ててはいけない。
 */
export function currentOrganizationSlug(): string {
    const matched = ORGANIZATION_PATH.exec(window.location.pathname);
    if (matched === null) {
        throw new Error(
            `組織 URL の外で組織パスを組み立てようとしました: ${window.location.pathname}`,
        );
    }
    return matched[1];
}

/** いま表示している組織の URL を組み立てる。 */
export function currentOrgUrl(path: string): string {
    return orgUrl(currentOrganizationSlug(), path);
}
