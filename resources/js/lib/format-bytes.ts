/**
 * バイト数の可読表記 (Dashboard の残容量タイル / 課金画面の quota カードで共有)。
 *
 * 1024 進法 (KB/MB/GB は KiB/MiB/GiB の意) で、サーバ側の GiB 換算
 * (PricingService::storageGb = intdiv(bytes, 1024**3)) と同じ基数を使う。
 * 表示専用であり、上限判定には使わない (判定は常に生のバイト数で行う)。
 */
export function formatBytes(bytes: number): string {
    if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
    if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
    if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${bytes} B`;
}
