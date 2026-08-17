/**
 * PHP 列挙 ⇔ TypeScript 値域の抽出に失敗したことを表す例外。
 *
 * **空集合を返して失敗を表さない** (空 vs 空が一致して素通りするため)。
 * 文面には必ず「対象の場所」と「落ちた理由」を入れる。
 */
export class EnumTsSyncError extends Error {
    constructor(where: string, reason: string) {
        super(`${where}: ${reason}`);
        this.name = "EnumTsSyncError";
    }
}
