<?php

declare(strict_types=1);

namespace App\Services\Render;

use App\Models\VideoManual;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * レンダ出力 S3 オブジェクト操作の集約点 (TakeObjectStorage と同じ Storage::disk('s3') 経由)。
 * ダウンロード/アップロード/署名 URL/削除はすべて本クラス経由 (Feature テストは Storage::fake('s3'))。
 *
 * disk 解決は disk() の 1 箇所に集約する。fake (FakeRenderObjectStorage) は disk() を s3_fake へ
 * override するだけで read/download 系を切り替えられる (後方互換の並走を残さない)。
 */
class RenderObjectStorage
{
    /** 集約された disk 解決点 (fake は本メソッドを override する。挙動不変) */
    protected function disk(): Filesystem
    {
        return Storage::disk('s3');
    }

    /** S3 素材をローカル一時ファイルへ取得する (readStream → ローカル書き込み) */
    public function downloadToLocal(string $key, string $localPath): void
    {
        $stream = $this->disk()->readStream($key);
        if ($stream === null) {
            throw new RuntimeException("S3 オブジェクトを読めません: {$key}");
        }

        $local = fopen($localPath, 'wb');
        if ($local === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            if (stream_copy_to_stream($stream, $local) === false) {
                throw new RuntimeException("S3 オブジェクトのコピーに失敗しました: {$key}");
            }
        } finally {
            fclose($local);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** ローカル最終 mp4 を S3 出力キーへアップロードする (writeStream) */
    public function upload(string $localPath, string $key): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            $this->disk()->writeStream($key, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** preview 再生用の署名 GET URL (TTL は config manual.render_playback_url_ttl_minutes) */
    public function temporaryPlaybackUrl(string $key): string
    {
        return $this->disk()->temporaryUrl(
            $key,
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
        );
    }

    /**
     * DL 用署名 URL (attachment disposition)。filename 契約 (詳細レビュー Round 1 で明文化):
     * - filename は CR/LF・制御文字を除去し、Content-Disposition は
     *   RFC 5987 (filename*=UTF-8''...) + ASCII fallback (filename="...") の両建てで署名に含める
     * - ヘッダ注入 (改行) 不能であることを Unit テストで固定
     */
    public function temporaryDownloadUrl(string $key, string $filename): string
    {
        return $this->disk()->temporaryUrl(
            $key,
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
            ['ResponseContentDisposition' => $this->contentDisposition($filename)],
        );
    }

    /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    /** manual 配下のレンダ出力 prefix (DeleteRenderOutputsJob の過大削除防止に使う) */
    public function keyPrefixFor(VideoManual $manual): string
    {
        return "projects/{$manual->project_id}/manuals/{$manual->id}/";
    }

    /**
     * Content-Disposition 値の構築 (attachment 固定・ヘッダ注入不能)。
     * - 制御文字 (CR/LF 含む)・DEL を除去
     * - ASCII fallback: 非 ASCII を '_' 化し、quoted-string を壊す `"` `\` も '_' 化
     * - RFC 5987: UTF-8 percent-encoding (rawurlencode)
     */
    public function contentDisposition(string $filename): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename);
        Assert::string($sanitized, 'filename の制御文字除去に失敗しました');
        if ($sanitized === '') {
            $sanitized = 'video.mp4';
        }

        $asciiFallback = preg_replace('/[^\x20-\x7E]/u', '_', $sanitized);
        Assert::string($asciiFallback, 'filename の ASCII fallback 生成に失敗しました');
        $asciiFallback = str_replace(['"', '\\'], '_', $asciiFallback);

        $rfc5987 = rawurlencode($sanitized);

        return "attachment; filename=\"{$asciiFallback}\"; filename*=UTF-8''{$rfc5987}";
    }
}
