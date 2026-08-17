/**
 * T25 (起点を縮める改変の回帰) の土台。
 * **tsconfig.json の対象に残す** (除外すると拡張が全体 program にも載らず、
 * 「全体 program だから値が増える」という差が出せない)。
 */
export interface Registry {
    a: "a";
}
