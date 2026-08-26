<?php

declare(strict_types=1);

namespace App\Support\Media;

/**
 * ffmpeg / ffprobe の安全境界引数 (**バイナリの直後**に置く)。
 *
 * `-max_alloc` は 1 回の heap 確保の上限。**画素数爆弾** (小さいファイルで巨大な画素数を宣言する
 * 画像) が media キューの worker を OOM で落とし、キューを共有する他組織のサムネイル生成まで
 * 遅延させることを防ぐ。バイト数の上限 (`capture.max_still_bytes`) では止まらない別の軸である。
 *
 * 配置は「最初の -i より前」ではなく **バイナリ直後** に統一する。
 * ffprobe は入力を -i ではなく**位置引数**で受けるため、-i を基準にすると検査が空振りする。
 *
 * **保証しないもの**: プロセス全体の RSS 上限でも、同時実行数の上限でもない。
 * worker のメモリ cgroup 制限は置いていない (デプロイ定義 `deploy/deploy.php` は systemd unit を
 * restart するだけで unit の MemoryMax 等は持たない。docs/deployment-runbook.md の未対応事項)。
 */
final class FfmpegSafetyArguments
{
    /** @return list<string> */
    public static function all(): array
    {
        // config()->integer() で int を確定させてから明示的に文字列化する
        // (未型付けの config() 値をコマンド配列へ流さない = list<string> を保つ)
        return ['-max_alloc', (string) config()->integer('manual.ffmpeg_max_alloc_bytes')];
    }
}
