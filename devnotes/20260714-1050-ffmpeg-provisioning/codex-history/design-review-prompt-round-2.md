# 詳細設計レビュー Round 2

Round 1 の Critical/Warning に全対応しました。残があれば指摘、なければ APPROVED を明示してください。

## 対応サマリー（Round 1 → 対応）

### 施策2（ci.yml）
- [Warning] fc-match の fontconfig 依存 → `sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig` に
  `fontconfig` を明示追加。
- [Suggestion] family のみ判定 → `fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK'`。

### 施策3（DockerfileProvisioningTest）
- [Critical] `(string)` キャストが読み込み失敗を空文字化 → `Webmozart\Assert\Assert::string($contents)` で
  false を明示 fail + string へ narrow。
- [Warning] 正規表現が独立行依存 → apt 文脈込みの柔軟パターン `/apt-get install -y[\s\S]*\bffmpeg\b/` /
  `/apt-get install[\s\S]*fonts-noto-cjk/` に変更。
- [Suggestion] テスト名に static/regression guard を明記。

### 施策4（FfmpegVideoComposerSmokeTest）
- [Critical] Process::run の例外化で skip 判定が落ちうる → `renderBinariesAvailable()` を
  `try { ... } catch (\Throwable) { return false; }` で包み「未導入なら確実に skip」。
- [Warning] afterEach の全 glob 削除 → 廃止。テスト内 `try/finally` で作成した `$workDir` のみ削除。
- [Warning] mkdir 未検証 → `if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) { throw ... }`。
- [Suggestion] `render_subtitle_font` をテスト内明示セット → 追加。

## 修正後の該当コード

### tests/Architecture/DockerfileProvisioningTest.php（新規）
```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/** docker/Dockerfile の内容 (apt install 行を走査する。読めない場合は Assert で明示 fail) */
function dockerfileContents(): string
{
    $path = base_path('docker/Dockerfile');
    Assert::fileExists($path);
    $contents = file_get_contents($path);
    Assert::string($contents, "docker/Dockerfile を読み込めません: {$path}");

    return $contents;
}

test('static guard: docker/Dockerfile が apt-get install で ffmpeg を導入している (render runtime 退行防止)', function (): void {
    expect(dockerfileContents())->toMatch('/apt-get install -y[\s\S]*\bffmpeg\b/');
});

test('static guard: docker/Dockerfile が字幕焼き込み用 CJK フォント (fonts-noto-cjk) を導入している', function (): void {
    expect(dockerfileContents())->toMatch('/apt-get install[\s\S]*fonts-noto-cjk/');
});
```

### tests/Unit/Render/FfmpegVideoComposerSmokeTest.php（新規）
```php
<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderClipSpec;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Enums\Manual\RenderKind;
use App\Services\Render\FfmpegVideoComposer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function renderBinariesAvailable(): bool
{
    try {
        foreach (['manual.render_ffmpeg_binary', 'manual.render_ffprobe_binary'] as $key) {
            $binary = config()->string($key);
            if (! Process::run([$binary, '-version'])->successful()) {
                return false;
            }
        }
    } catch (\Throwable) {
        return false;
    }

    return true;
}

function smokeWorkDir(): string
{
    $dir = sys_get_temp_dir().'/ffmpeg-smoke-'.bin2hex(random_bytes(8));
    if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
        throw new \RuntimeException("smoke work dir を作成できません: {$dir}");
    }

    return $dir;
}

test('実 ffmpeg で日本語字幕を焼き込んだ最小合成が成功し mp4 を出力する', function (): void {
    config()->set('manual.render_resolution', '320x240');
    config()->set('manual.render_fps', 5);
    config()->set('manual.preview_placeholder_seconds', 1);
    config()->set('manual.render_subtitle_font', 'Noto Sans CJK JP');

    $workDir = smokeWorkDir();

    try {
        $manifest = new RenderManifest(
            renderJobId: 1,
            kind: RenderKind::Preview,
            scenarioVersion: 1,
            outputKey: 'projects/1/manuals/1/previews/v1-1.mp4',
            clips: [new RenderClipSpec(
                cutId: 1,
                label: '手順1',
                source: RenderClipSource::Placeholder,
                takeVideoPath: null,
                stillDisplaySeconds: null,
                subtitlePrimary: null,
                subtitleSecondary: 'これは疎通確認用の日本語字幕です。',
            )],
        );

        $composed = app(FfmpegVideoComposer::class)->compose($manifest, [], $workDir, function (): void {});

        expect($composed)->toBeInstanceOf(ComposedLocalVideo::class);
        expect(is_file($composed->localPath))->toBeTrue();
        expect(filesize($composed->localPath))->toBeGreaterThan(0);
        expect($composed->totalDurationMs)->toBeGreaterThan(0);
    } finally {
        File::deleteDirectory($workDir);
    }
})->skip(fn (): bool => ! renderBinariesAvailable(), 'ffmpeg/ffprobe 未導入のため skip (層 1 が CI で fail-fast)');
```

### ci.yml provision ステップ（修正後）
```yaml
      - name: Provision ffmpeg for render smoke
        run: |
          sudo apt-get update
          sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig
          ffmpeg -version
          ffprobe -version
          fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
            || { echo "::error::Noto Sans CJK JP did not resolve to a Noto CJK family"; exit 1; }
```

## 補足（実環境確認済み事実）
- 設計者が ffmpeg 7.1 環境で Placeholder+日本語字幕の実合成を確認: 正常終了・output.mp4 生成・
  ffprobe 尺取得・libass が `Noto Sans CJK JP -> NotoSansCJK-Regular.ttc` を実解決（tofu でない）。
