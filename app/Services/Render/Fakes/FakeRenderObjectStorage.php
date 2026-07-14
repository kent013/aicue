<?php

declare(strict_types=1);

namespace App\Services\Render\Fakes;

use App\Services\Render\RenderObjectStorage;
use App\Services\Storage\Fakes\FakeObjectStore;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * RenderObjectStorage の fake。disk() を s3_fake へ差し替え、署名 URL / upload の sidecar 生成 / delete を override。
 * contentDisposition() / keyPrefixFor() / downloadToLocal() は親を継承 (契約不変。downloadToLocal は disk() 経由)。
 * take footage は FakeTakeObjectStorage と同一 s3_fake disk・同一 key で読める。
 */
final class FakeRenderObjectStorage extends RenderObjectStorage
{
    public function __construct(private readonly FakeObjectStore $store) {}

    /** 親の disk() を s3_fake へ差し替え (downloadToLocal は親実装を継承したまま fake disk を読む) */
    protected function disk(): Filesystem
    {
        return Storage::disk(FakeObjectStore::DISK);
    }

    public function upload(string $localPath, string $key): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }
        try {
            // sidecar (content_type=video/mp4) を必ず生成する = GET DL の contract を満たす。
            // render 成果物は完成 mp4 固定。
            $this->store->putStreamWithMeta($key, $stream, 'video/mp4');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function temporaryPlaybackUrl(string $key): string
    {
        return URL::temporarySignedRoute(
            'bughunt.storage.get',
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
            ['key' => $key],
        );
    }

    public function temporaryDownloadUrl(string $key, string $filename): string
    {
        // filename のみ signed パラメータに載せる (verbatim disposition は流さない)。
        // Content-Disposition は GET コントローラが contentDisposition() で再生成する。
        return URL::temporarySignedRoute(
            'bughunt.storage.get',
            now()->addMinutes(config()->integer('manual.render_playback_url_ttl_minutes')),
            ['key' => $key, 'filename' => $filename],
        );
    }

    public function delete(string $key): void
    {
        $this->store->delete($key);
    }
}
