# 詳細設計: ffmpeg-provisioning (dev/bughunt/CI 環境への ffmpeg 導入)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- **v1 スコープ**: 字幕のみ / 撮影は PWA / **動画合成は自前 ffmpeg** / 単一 Default Project。

→ 本 item は「動画合成は自前 ffmpeg」という v1 中核前提を非本番環境で成立させ、F-1-0b を恒久クローズする。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び（factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

→ 本 item はインフラ（Dockerfile / CI）＋テスト追加のみ。アプリコード・レスポンス契約・prompt・
   LLM 呼び出しには一切触れないため 4/5/6/7/8 は無関係。1（テスト必須）は施策 3/4 で担保。
   2（PHPStan）は新規テストが level 10 を通ることで担保。3 は本 item で DB 破壊操作をしない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** グローバル適用 + `--parallel`
  （個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成（本 item は DB を使わないため該当なし）
- **DTO + JsonResource** パターン（本 item はレスポンスを追加しないため該当なし）
- コードフォーマット: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260714-1050-ffmpeg-provisioning/conceptual-design.md`（APPROVED: conceptual-review Round 3）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | Dockerfile へ ffmpeg を導入 | `docker/Dockerfile` | High |
| 2 | CI へ ffmpeg+フォント導入と存在/解決の fail-fast 検証（層 1） | `.github/workflows/ci.yml` | High |
| 3 | Dockerfile 退行の静的ガード Architecture テスト（層 1-b） | `tests/Architecture/DockerfileProvisioningTest.php`（新規） | High |
| 4 | 実 ffmpeg 合成 smoke テスト（層 2・skip guard 付き） | `tests/Unit/Render/FfmpegVideoComposerSmokeTest.php`（新規） | High |

> 本 item は 4 施策すべてが independent かつ同一 PR で完結。アプリコードは変更しない。

---

## 施策 1: Dockerfile へ ffmpeg を導入

### 変更箇所
- ファイル: `docker/Dockerfile`（L8-24 の 1 つ目の `apt-get install` ブロック）

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 3（静的ガード）が本変更の存在を検証する

### 現行コード
```dockerfile
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    zip \
    unzip \
    sudo \
    locales \
    postgresql-client \
    procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
```

### 変更後コード
```dockerfile
# システムパッケージ (ffmpeg = v1 動画合成 FfmpegVideoComposer の render runtime 依存。ffprobe 同梱)
RUN apt-get update && apt-get install -y \
    git \
    curl \
    ffmpeg \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libicu-dev \
    zip \
    unzip \
    sudo \
    locales \
    postgresql-client \
    procps \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
```

> 注: 既存の `# システムパッケージ` コメント行（Dockerfile L7）を上記の 1 行に差し替える形で
> ffmpeg の役割を明示する（`RUN ... \` の継続行の途中に `#` コメントを差し込むと継続が壊れうるため、
> インラインコメントは使わずブロック直前のコメント行に集約する）。

- `ffmpeg` パッケージは `ffprobe` も同梱するため両バイナリが PATH（`/usr/bin`）に入る。
- 字幕焼き込み用フォント `Noto Sans CJK JP` は既存 2 つ目のブロック（L48-51 `fonts-noto-cjk`）で
  導入済みのため追加不要。
- **配置理由**: 「既存の apt install 行に追記する形が最小」（brief）。1 つ目の汎用システムパッケージ
  ブロックに `curl` の直後で追加する（alphabetical ではなくビルドキャッシュ層を増やさない最小差分）。

### PHPStan適合チェック
- 該当なし（Dockerfile）

### テスト計画
- 施策 3 の Architecture テストが `docker/Dockerfile` に `ffmpeg` 行が含まれることを静的検証。
- 施策 4 の smoke テストが実行環境（ffmpeg 導入済み）で実合成が疎通することを検証。

### リスク
- イメージサイズ増（ffmpeg 一式で数十 MB）。非本番イメージのため許容。
- ビルド時間微増。既存 apt レイヤに相乗りするため増分は小さい。

---

## 施策 2: CI へ ffmpeg+フォント導入と存在/解決の fail-fast 検証（層 1）

### 変更箇所
- ファイル: `.github/workflows/ci.yml`（`php` ジョブ、`Prepare environment` の後・`Pest` の前）

### 波及変更
- TypeScript型定義: なし / API Resource/DTO: なし / テストファイル: なし

### 現行コード（php ジョブの該当部）
```yaml
      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      - name: Pint (code style)
        run: vendor/bin/pint --test
      - name: PHPStan
        run: composer phpstan
      - name: Pest
        run: composer test
```

### 変更後コード
```yaml
      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      # レンダー smoke テスト (施策 4) の前提。Dockerfile (dev/bughunt) と別に CI runner にも
      # ffmpeg/ffprobe と字幕フォントを導入し、存在・フォント解決を fail-fast 検証する (層 1)。
      - name: Provision ffmpeg for render smoke
        run: |
          sudo apt-get update
          # fontconfig を明示 (fc-match の依存。ランナー差異で未導入の可能性をゼロにする。design-review R1)
          sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig
          ffmpeg -version
          ffprobe -version
          # fc-match の終了コードだけでなく、解決 family が Noto CJK であることを機械的に判定
          # (代替フォントへのフォールバックを検出する。-f '%{family}' で family のみ抽出しノイズ耐性を上げる)
          fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
            || { echo "::error::Noto Sans CJK JP did not resolve to a Noto CJK family"; exit 1; }
      - name: Pint (code style)
        run: vendor/bin/pint --test
      - name: PHPStan
        run: composer phpstan
      - name: Pest
        run: composer test
```

- **fail-fast**: ffmpeg/ffprobe 未導入、または `fc-match` が Noto CJK 以外へフォールバックした場合、
  ステップが exit 1 で CI を落とす。これにより「skip guard で黙って通る」ことを層 1 で防ぐ。
- `frontend` ジョブには不要（レンダーは PHP 側）。

### テスト計画
- CI 実行時にこのステップ自体が検証（緑=導入・解決 OK）。ローカル worktree では既に ffmpeg 導入済み。

### リスク
- `apt-get install` 分の CI 時間増（数秒〜十数秒）。決定性のため許容。
- ubuntu runner の apt に `ffmpeg`/`fonts-noto-cjk` が無いことは通常ないが、あれば fail-fast で顕在化。

---

## 施策 3: Dockerfile 退行の静的ガード（層 1-b・Architecture テスト）

### 変更箇所
- ファイル: `tests/Architecture/DockerfileProvisioningTest.php`（新規）

### 波及変更
- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策自体が新規テスト

### 設計意図
CI（`ubuntu-latest`）は `docker/Dockerfile` をビルドしないため、CI の層 1 が緑でも Dockerfile から
`ffmpeg` 行が削除される退行は検出できない。これを補う静的ガード（ファイル走査）。
**実イメージをビルドして image 内で `ffmpeg -version` を叩く継続的検証はコスト理由でスコープ外**
とし、本 item は静的ガードで退行検出する（実イメージ検証との差はここで明記）。

### 新規コード
```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * Dockerfile プロビジョニング不変条件 (ffmpeg-provisioning / bug-hunt F-1-0b) の static regression guard。
 *
 * v1 スコープ「動画合成は自前 ffmpeg」を dev/bughunt イメージで成立させ続けるための静的ガード。
 * CI (ubuntu runner) は docker/Dockerfile をビルドしないため、この Architecture テストが
 * Dockerfile からの ffmpeg / 字幕フォント削除という退行を検出する唯一の機械的防御になる
 * (実イメージビルド検証はコスト理由でスコープ外)。
 */

/** docker/Dockerfile の内容 (apt install 行を走査する。読めない場合は Assert で明示 fail) */
function dockerfileContents(): string
{
    $path = base_path('docker/Dockerfile');
    Assert::fileExists($path);
    $contents = file_get_contents($path);
    Assert::string($contents, "docker/Dockerfile を読み込めません: {$path}"); // false を明示 fail + string へ narrow

    return $contents;
}

test('static guard: docker/Dockerfile が apt パッケージとして ffmpeg を導入している (render runtime 退行防止)', function (): void {
    // apt install ブロック内の独立パッケージ行として ffmpeg を検証する。行頭〜行末アンカー (/m) で
    // コメント・別命令中の部分一致 ffmpeg を弾く (design-review R2: 貪欲マッチの誤検知回避)。
    // 末尾 `\` はパッケージ位置により有無があるため optional (`\\?`)
    expect(dockerfileContents())->toMatch('/^[ \t]*ffmpeg[ \t]*\\\\?[ \t]*$/m');
});

test('static guard: docker/Dockerfile が字幕焼き込み用 CJK フォント (fonts-noto-cjk) を導入している', function (): void {
    // 字幕 (Noto Sans CJK JP) のフォント解決前提。tofu 化を防ぐ退行ガード (独立行アンカー)
    expect(dockerfileContents())->toMatch('/^[ \t]*fonts-noto-cjk[ \t]*\\\\?[ \t]*$/m');
});
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`dockerfileContents(): string`）
- [x] null 安全（`Assert::string($contents)` で `file_get_contents` の `false` を明示 fail させ string へ narrow。
      サイレントな空文字化を避ける。design-review R1 Critical 反映）
- [x] DTO を返している: 該当なし（テスト）
- [x] Generics: 該当なし

### テスト計画
- [x] バグ修正の再現: 現行 `docker/Dockerfile`（ffmpeg 行なし）では 1 つ目の test が **fail**
  （施策 1 適用前に先に fail を観測 = テストファースト）。施策 1 適用後に green。
- [x] `fonts-noto-cjk` の test は現行 Dockerfile で既に green（退行防止として固定）。
- [x] Architecture lane（DB 非使用）に配置。`DatabaseTransactions` 不使用。

### リスク
- 正規表現の誤検知/見逃しトレードオフ。**独立パッケージ行アンカー**（`/^[ \t]*ffmpeg[ \t]*\\?[ \t]*$/m`）を
  採用し、コメント中・別命令中の部分一致 `ffmpeg` を弾く（design-review R2: 貪欲マッチ誤検知の回避）。
  代償として apt リストを 1 行へ整形するリファクタ時にはパターン更新が必要だが、**静的ガードとして許容**
  （R2 も許容と明言）。その場合も独立行が消えれば test が fail し、更新要否が顕在化する。

---

## 施策 4: 実 ffmpeg 合成 smoke テスト（層 2・skip guard 付き）

### 変更箇所
- ファイル: `tests/Unit/Render/FfmpegVideoComposerSmokeTest.php`（新規）

### 波及変更
- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: 本施策自体が新規テスト。既存 `FfmpegVideoComposerTest.php`（Process::fake）は変更しない
  （禁止事項 3「既存テストの削除・上書き」を回避。実バイナリ smoke は別ファイルに分離し役割を明示）。

### 設計意図
`FfmpegVideoComposer` が**実バイナリ**を発見し、**日本語字幕を 1 枚焼き込む最小合成**が成功して
mp4 を出力できることを検証する。Placeholder クリップ（黒背景 + 字幕。S3 素材ダウンロード不要）を
1 本合成することで、ffmpeg 本体・filtergraph・libass 字幕描画・concat・ffprobe 実測を一度に通す。
skip guard は**ローカル任意環境の便宜に限定**（CI/devcontainer/bughunt は導入済み前提で実走。
未導入 CI での赤化防止は skip ではなく施策 2 の層 1 が fail-fast で担う）。

### 新規コード
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

/*
 * 実 ffmpeg / ffprobe を用いた合成疎通 smoke (bug-hunt F-1-0b / ffmpeg-provisioning 層 2)。
 * 既存 FfmpegVideoComposerTest は Process::fake でコマンド構造を検証する。本ファイルは
 * 実バイナリを起動し「日本語字幕を焼き込んだ最小合成が正常終了し mp4 が出力される」ことを検証する。
 * skip はローカル任意環境の便宜であり、CI では ffmpeg 導入を層 1 (ci.yml) が fail-fast で強制する。
 */

/** config 済みの ffmpeg / ffprobe が実行可能か (skip guard の指標。config 値を尊重・例外も未導入扱い) */
function renderBinariesAvailable(): bool
{
    try {
        foreach (['manual.render_ffmpeg_binary', 'manual.render_ffprobe_binary'] as $key) {
            $binary = config()->string($key);
            // バイナリ不在時に Process 実装差異で例外化しても「未導入」として確実に skip させる
            if (! Process::run([$binary, '-version'])->successful()) {
                return false;
            }
        }
    } catch (\Throwable) {
        return false;
    }

    return true;
}

/** 一意な作業ディレクトリ (並列テスト安全。呼び出し側で try/finally 削除する) */
function smokeWorkDir(): string
{
    $dir = sys_get_temp_dir().'/ffmpeg-smoke-'.bin2hex(random_bytes(8));
    if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
        throw new \RuntimeException("smoke work dir を作成できません: {$dir}");
    }

    return $dir;
}

test('実 ffmpeg で日本語字幕を焼き込んだ最小合成が成功し mp4 を出力する', function (): void {
    // 実エンコードを軽量化 (疎通が目的。画素比較はしない)
    config()->set('manual.render_resolution', '320x240');
    config()->set('manual.render_fps', 5);
    config()->set('manual.preview_placeholder_seconds', 1);
    config()->set('manual.render_subtitle_font', 'Noto Sans CJK JP'); // 再現性のため明示

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
                source: RenderClipSource::Placeholder, // 素材ダウンロード不要 (黒背景 + 字幕)
                takeVideoPath: null,
                stillDisplaySeconds: null,
                subtitlePrimary: null,
                subtitleSecondary: 'これは疎通確認用の日本語字幕です。', // libass + フォント解決を通す
            )],
        );

        $composed = app(FfmpegVideoComposer::class)->compose(
            $manifest,
            [], // Placeholder は localSources 不要
            $workDir,
            function (): void {},
        );

        expect($composed)->toBeInstanceOf(ComposedLocalVideo::class);
        expect(is_file($composed->localPath))->toBeTrue();            // output.mp4 が存在
        expect(filesize($composed->localPath))->toBeGreaterThan(0);   // 空でない
        expect($composed->totalDurationMs)->toBeGreaterThan(0);       // ffprobe が尺を読めた
    } finally {
        // 作成した work dir のみを確実に削除 (sys_get_temp_dir 全 glob はしない。design-review R1)
        File::deleteDirectory($workDir);
    }
})->skip(fn (): bool => ! renderBinariesAvailable(), 'ffmpeg/ffprobe 未導入のため skip (層 1 が CI で fail-fast)');
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`renderBinariesAvailable(): bool` / `smokeWorkDir(): string` /
      テストクロージャは `: void`）
- [x] null 安全（`config()->string()` で型確定。`Process::run` の例外は `catch (\Throwable)` で吸収）
- [x] DTO を返している: `compose()` は `ComposedLocalVideo` DTO を返す（assert 済み）
- [x] Generics: 該当なし
- [x] `Process::run([...])` は配列引数（シェル連結なし）
- [x] `mkdir` の戻り値を検証（`if (! mkdir(...) && ! is_dir($dir)) throw`。design-review R1 反映）

### テスト計画
- [x] バグ修正の再現: ffmpeg 不在環境では従来 `compose()` が `not found` で例外だった。本テストは
      導入済み環境で **成功** を検証する回帰点。ffmpeg 未導入環境では skip（層 1 が別途 fail-fast）。
- [x] 既存 `tests/Unit/Render/FfmpegVideoComposerTest.php` は変更しない（Process::fake の構造検証を維持）。
- [x] 新規テスト: 「日本語字幕焼き込みを含む最小合成が正常終了・mp4 出力・ffprobe 実測できる」。
- [x] 個別 `DatabaseTransactions` 不使用（DB 非依存。グローバル RefreshDatabase の下で無害に走る）。
- [x] 一意 work dir（`random_bytes`）で `--parallel` 衝突なし。テスト内 `try/finally` で作成分のみ後始末。

### リスク
- 実エンコードのため実行時間が数百 ms〜数秒。解像度/fps/尺を最小化して緩和。
- 実行環境にフォントが無くても ffmpeg は代替フォントで成功する（tofu）。フォント解決の保証は
  施策 2 層 1 の `fc-match` が担う（本 smoke の合格条件には含めない = 過剰検証を避ける）。
- 後始末はテスト内 `try/finally` で**作成した `$workDir` のみ**削除する（`sys_get_temp_dir()` 全 glob は
  他プロセス干渉の余地があるため採らない。design-review R1 Warning 反映）。バイナリ未導入で skip
  された場合はそもそも work dir を作らない（skip guard がテスト本体前に評価される）。

---

## 波及変更まとめ

| 種別 | 影響 |
|------|------|
| TypeScript 型定義 | なし（フロント変更なし） |
| Inertia Props | なし |
| API Resource / DTO | なし（既存 `ComposedLocalVideo` を利用するのみ、変更しない） |
| ルート / コントローラ | なし |
| DB / マイグレーション / Factory | なし（DB 非依存） |
| 既存テスト | 変更なし（`FfmpegVideoComposerTest` は不変。smoke は別ファイル新規） |
| ドキュメント | 任意: `docs/architecture.md` のレンダー節に「非本番イメージは ffmpeg 導入済み」を追記可（必須ではない） |

## テスト計画（全体サマリー）

1. **層 1（CI・fail-fast）**: `ci.yml` の provision ステップが `ffmpeg -version` / `ffprobe -version` /
   `fc-match` の Noto CJK 解決を検証。未導入・未解決で CI 赤化。
2. **層 1-b（静的ガード）**: `DockerfileProvisioningTest` が `docker/Dockerfile` の `ffmpeg` /
   `fonts-noto-cjk` 行を静的検証。ffmpeg 行削除で赤化。
3. **層 2（実合成 smoke）**: `FfmpegVideoComposerSmokeTest` が実 ffmpeg で日本語字幕焼き込み合成の
   正常終了・mp4 出力・ffprobe 実測を検証（未導入環境は skip）。
4. 全体検証: `composer test` / `composer phpstan` / `vendor/bin/pint --test` が green。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存 Dockerfile / CI / テスト基盤への追記のみ。新規サブシステムを立てず、既存の
  レンダー資産（`FfmpegVideoComposer`・config キー・Architecture lane）に相乗りする最小差分。 |
| 競合リスク | 低。アプリコード・DB・ルートに触れないため他施策との干渉なし。`ci.yml` / `docker/Dockerfile`
  は現在 uncommitted 変更のある `docker-compose.yml` とは別ファイルで衝突しない。 |

## 使命・禁止事項チェック（最終）

- [x] 全施策が使命（v1「動画合成は自前 ffmpeg」）を非本番で成立させる方向に寄与
- [x] 禁止事項 1（テスト必須）: 施策 3/4 でテストを追加、施策 1/2 を検証
- [x] 禁止事項 2（PHPStan widen 禁止）: 新規テストは level 10 適合設計
- [x] 禁止事項 3（dev DB 破壊禁止）: 本 item は DB に触れない
- [x] 禁止事項 4-8: アプリコード・レスポンス・prompt・UI に触れないため無関係
- [x] 既存テスト（`FfmpegVideoComposerTest`）を削除・上書きしない
