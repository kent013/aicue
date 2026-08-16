<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\Enums\Manual\MaterialType;
use App\Exceptions\Capture\TakeThumbnailExtractionException;
use App\Support\Media\FfmpegSafetyArguments;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * ffmpeg による 1 フレーム抽出 (実行は Process facade 経由。テストは Process::fake())。
 *
 * 安全境界 (入力は**利用者がアップロードした素材** = 動画または静止画である):
 * - 引数は配列で渡す (シェル連結なし)。入力・出力ともサーバ生成のパスだけで、
 *   利用者由来の文字列は 1 つも引数に入らない
 * - `-nostdin` で標準入力待ちに落ちない / `Process::timeout()` で実行を有界にする
 * - **`-protocol_whitelist file`** を明示し、細工されたファイルが外部参照を含む形式として
 *   probe された場合でもローカルファイル以外へ到達しないようにする
 *   (**観測事実**: 既存の `Render\FfmpegVideoComposer` はこの指定を持たない。入力の素性は同じだが、
 *   新設する側を弱い方へ揃える理由はないため本実装には付ける。既存側の後追いは別タスク)
 * - 出力寸法・品質は config 固定 = 巨大入力から巨大 JPEG を作らない。
 *   同一入力・同一バイナリなら出力は決定的である (容量計上の前提)
 */
final class FfmpegTakeThumbnailExtractor implements TakeThumbnailExtractor
{
    public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void
    {
        // 静止画に「1 秒地点」は無い。seek=0 の 1 回で決める
        // (動画既定の 1000ms を当てると 1 回目が必ず空振りし、無駄な ffmpeg 実行が 1 回増える)
        if ($material === MaterialType::Still) {
            $failure = $this->attempt($localSourcePath, $localThumbnailPath, 0);
            if ($failure !== null) {
                throw new TakeThumbnailExtractionException($failure);
            }

            return;
        }

        $seekMs = config()->integer('capture.thumbnail_seek_ms');

        $failure = $this->attempt($localSourcePath, $localThumbnailPath, $seekMs);
        if ($failure !== null && $seekMs > 0) {
            // 尺が seek より短いと 1 フレームも出力されない。先頭で 1 回だけ再試行する
            // (これ以上の探索はしない = 尺の推定に ffprobe を足さない)
            $failure = $this->attempt($localSourcePath, $localThumbnailPath, 0);
        }
        if ($failure !== null) {
            throw new TakeThumbnailExtractionException($failure);
        }
    }

    /** @return string|null 失敗理由 (null = 成功) */
    private function attempt(string $source, string $destination, int $seekMs): ?string
    {
        // ★ 実行の**前**に出力先を消す。`-y` は「既存があれば上書きしてよい」という許可であって、
        //   ffmpeg が必ず書き直すことの保証ではない。1 回目が非 0 終了しつつ非空ファイルを残し、
        //   2 回目が終了コード 0 のまま新しいフレームを出さない場合、下の実体検査が
        //   **1 回目の残骸を成功と誤認する**。削除できないこと自体も失敗として扱う。
        // ★ 素の `unlink()` を使わない — 失敗時に E_WARNING を出し、Laravel のエラーハンドラが
        //   `ErrorException` へ変換する環境では下の `return` へ到達せず、
        //   `TakeThumbnailExtractionException` への集約という契約から外れる。
        //   `File::delete()` なら、判定が**戻り値だけで閉じる**。
        if (File::isFile($destination) && ! File::delete($destination)) {
            return "failed to remove stale thumbnail output: {$destination}";
        }

        $edge = config()->integer('capture.thumbnail_max_edge');
        $result = Process::timeout(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))
            ->run([
                config()->string('manual.render_ffmpeg_binary'),
                ...FfmpegSafetyArguments::all(),
                '-nostdin', '-y',
                '-protocol_whitelist', 'file',
                '-ss', sprintf('%.3f', $seekMs / 1000),
                '-i', $source,
                '-frames:v', '1',
                '-vf', "scale={$edge}:{$edge}:force_original_aspect_ratio=decrease",
                '-q:v', (string) config()->integer('capture.thumbnail_jpeg_quality'),
                '-f', 'image2',
                $destination,
            ]);

        if (! $result->successful()) {
            return 'ffmpeg failed (thumbnail): '.mb_substr($result->errorOutput(), 0, 2000);
        }

        // 非 0 終了しないまま 0 バイトを吐く場合がある (seek が尺を超えたとき) ため実体を検査する
        $size = File::exists($destination) ? File::size($destination) : 0;
        if ($size === 0) {
            return "ffmpeg produced no frame (seek={$seekMs}ms)";
        }

        return null;
    }
}
