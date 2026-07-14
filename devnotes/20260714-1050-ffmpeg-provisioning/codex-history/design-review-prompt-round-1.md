# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。v1 スコープ: 字幕のみ / 撮影は PWA / **動画合成は自前 ffmpeg** / 単一 Default Project。

# 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作を勝手に実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び（factory 経由のみ）
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 役割

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI 変更を含む場合）
11. Atomic Design準拠（UI 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# user: 詳細設計書

本 item は「dev/bughunt/CI 環境への ffmpeg 導入（動画レンダー疎通）」。アプリコード・DB・ルート・
レスポンス契約・prompt には一切触れず、Dockerfile / CI ワークフロー / テスト（Architecture + Unit smoke）
の追加のみ。概念設計は conceptual-review Round 3 で APPROVED 済み。

## 補足: 設計者が実環境（ffmpeg 7.1）で疎通確認済みの事実
- Placeholder クリップ（黒背景 + 日本語字幕）の実合成が正常終了、output.mp4 生成、ffprobe で尺 1.02s 取得。
- libass の fontselect ログで `Noto Sans CJK JP -> NotoSansCJK-Regular.ttc`（代替フォントでなく実解決）。
- `fc-match "Noto Sans CJK JP"` = `NotoSansCJK-Regular.ttc: "Noto Sans CJK JP" "Regular"`。

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
          sudo apt-get install -y ffmpeg fonts-noto-cjk
          ffmpeg -version
          ffprobe -version
          # fc-match の終了コードだけでなく、解決 family が Noto CJK であることを機械的に判定
          # (代替フォントへのフォールバックを検出する。conceptual-review R3 Suggestion)
          fc-match "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
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

/*
 * Dockerfile プロビジョニング不変条件 (ffmpeg-provisioning / bug-hunt F-1-0b)。
 *
 * v1 スコープ「動画合成は自前 ffmpeg」を dev/bughunt イメージで成立させ続けるための静的ガード。
 * CI (ubuntu runner) は docker/Dockerfile をビルドしないため、この Architecture テストが
 * Dockerfile からの ffmpeg / 字幕フォント削除という退行を検出する唯一の機械的防御になる
 * (実イメージビルド検証はコスト理由でスコープ外)。
 */

/** docker/Dockerfile の内容 (apt install 行を走査する) */
function dockerfileContents(): string
{
    $path = base_path('docker/Dockerfile');
    expect(is_file($path))->toBeTrue();

    return (string) file_get_contents($path);
}

test('docker/Dockerfile が動画合成用に ffmpeg を apt 導入している', function (): void {
    // apt-get install ブロック内の ffmpeg パッケージ行 (行頭空白 + ffmpeg + 行末 \ を許容)
    expect(dockerfileContents())->toMatch('/^\s*ffmpeg\s*\\\\?\s*$/m');
});

test('docker/Dockerfile が字幕焼き込み用の CJK フォントを導入している', function (): void {
    // 字幕 (Noto Sans CJK JP) のフォント解決前提。tofu 化を防ぐ
    expect(dockerfileContents())->toContain('fonts-noto-cjk');
});
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`dockerfileContents(): string`）
- [x] null 安全（`is_file` チェック後に `(string)` キャスト。`file_get_contents` の `false` を string 化）
- [x] DTO を返している: 該当なし（テスト）
- [x] Generics: 該当なし

### テスト計画
- [x] バグ修正の再現: 現行 `docker/Dockerfile`（ffmpeg 行なし）では 1 つ目の test が **fail**
  （施策 1 適用前に先に fail を観測 = テストファースト）。施策 1 適用後に green。
- [x] `fonts-noto-cjk` の test は現行 Dockerfile で既に green（退行防止として固定）。
- [x] Architecture lane（DB 非使用）に配置。`DatabaseTransactions` 不使用。

### リスク
- 正規表現が緩いと退行を見逃す。`ffmpeg` を独立行としてマッチさせ、コメント中の `ffmpeg` 誤検出を避ける
  ため行頭〜行末アンカー（`/m`）を使う。将来 apt 行を 1 行に連結する整形をした場合は正規表現の
  更新が必要（その場合も `toContain('ffmpeg')` へ緩めれば追従可能）。

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
use Illuminate\Support\Facades\Process;

/*
 * 実 ffmpeg / ffprobe を用いた合成疎通 smoke (bug-hunt F-1-0b / ffmpeg-provisioning 層 2)。
 * 既存 FfmpegVideoComposerTest は Process::fake でコマンド構造を検証する。本ファイルは
 * 実バイナリを起動し「日本語字幕を焼き込んだ最小合成が正常終了し mp4 が出力される」ことを検証する。
 * skip はローカル任意環境の便宜であり、CI では ffmpeg 導入を層 1 (ci.yml) が fail-fast で強制する。
 */

/** config 済みの ffmpeg / ffprobe が実行可能か (skip guard の指標。config 値を尊重) */
function renderBinariesAvailable(): bool
{
    foreach (['manual.render_ffmpeg_binary', 'manual.render_ffprobe_binary'] as $key) {
        $binary = config()->string($key);
        if (! Process::run([$binary, '-version'])->successful()) {
            return false;
        }
    }

    return true;
}

/** 一意な作業ディレクトリ (並列テスト安全。afterEach で後始末) */
function smokeWorkDir(): string
{
    $dir = sys_get_temp_dir().'/ffmpeg-smoke-'.bin2hex(random_bytes(8));
    mkdir($dir, 0o755, true);

    return $dir;
}

test('実 ffmpeg で日本語字幕を焼き込んだ最小合成が成功し mp4 を出力する', function (): void {
    // 実エンコードを軽量化 (疎通が目的。画素比較はしない)
    config()->set('manual.render_resolution', '320x240');
    config()->set('manual.render_fps', 5);
    config()->set('manual.preview_placeholder_seconds', 1);

    $workDir = smokeWorkDir();

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
})->skip(fn (): bool => ! renderBinariesAvailable(), 'ffmpeg/ffprobe 未導入のため skip (層 1 が CI で fail-fast)');

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/ffmpeg-smoke-*') ?: [] as $dir) {
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }
});
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`renderBinariesAvailable(): bool` / `smokeWorkDir(): string` /
      テストクロージャは `: void`）
- [x] null 安全（`config()->string()` で型確定。`glob()` の `false` を `?: []` で吸収）
- [x] DTO を返している: `compose()` は `ComposedLocalVideo` DTO を返す（assert 済み）
- [x] Generics: 該当なし
- [x] `Process::run([...])` は配列引数（シェル連結なし）

### テスト計画
- [x] バグ修正の再現: ffmpeg 不在環境では従来 `compose()` が `not found` で例外だった。本テストは
      導入済み環境で **成功** を検証する回帰点。ffmpeg 未導入環境では skip（層 1 が別途 fail-fast）。
- [x] 既存 `tests/Unit/Render/FfmpegVideoComposerTest.php` は変更しない（Process::fake の構造検証を維持）。
- [x] 新規テスト: 「日本語字幕焼き込みを含む最小合成が正常終了・mp4 出力・ffprobe 実測できる」。
- [x] 個別 `DatabaseTransactions` 不使用（DB 非依存。グローバル RefreshDatabase の下で無害に走る）。
- [x] 一意 work dir（`random_bytes`）で `--parallel` 衝突なし。`afterEach` で後始末。

### リスク
- 実エンコードのため実行時間が数百 ms〜数秒。解像度/fps/尺を最小化して緩和。
- 実行環境にフォントが無くても ffmpeg は代替フォントで成功する（tofu）。フォント解決の保証は
  施策 2 層 1 の `fc-match` が担う（本 smoke の合格条件には含めない = 過剰検証を避ける）。
- `sys_get_temp_dir()` 直下の後始末を `afterEach` の glob で行うため、他プロセスの同名 dir を
  消さないよう prefix（`ffmpeg-smoke-`）を十分に一意化する（`random_bytes(8)` = 16 hex）。

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


---

## 関連する現行コード（抜粋）

### app/Services/Render/FfmpegVideoComposer.php（compose / planPlaceholder / runFfmpeg / probeDurationMs）
```php
public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
{
    $totalClips = count($manifest->clips);
    $clipDurationsMs = [];
    $clipFiles = [];
    foreach ($manifest->clips as $index => $clip) {
        $clipFile = "clip{$index}.mp4";
        $this->composeClip($clip, $localSources, $workDir, $index, $clipFile);
        $clipFiles[] = $clipFile;
        $clipDurationsMs[$clip->cutId] = $this->probeDurationMs("{$workDir}/{$clipFile}");
        $onClipComposed($index + 1, $totalClips);
    }
    $outputFile = 'output.mp4';
    $this->concat($workDir, $clipFiles, $outputFile);
    return new ComposedLocalVideo(
        localPath: "{$workDir}/{$outputFile}",
        clipDurationsMs: $clipDurationsMs,
        totalDurationMs: (int) array_sum($clipDurationsMs),
    );
}
// planPlaceholder: 黒背景 color=black:s={W}x{H}:d={sec} + anullsrc、filter "fps={fps},subtitles={assFile},format=yuv420p"、durationMs = sec*1000。sourceFor 呼び出しなし（localSources 不要）。
// runFfmpeg: config()->string('manual.render_ffmpeg_binary') を Process::path($workDir)->run([$binary, ...$args])。非 0 は RenderCompositionException。
// probeDurationMs: config()->string('manual.render_ffprobe_binary') を Process::run で実行し duration を ms 化。
```

### config/manual.php（L43-45）
```php
'render_ffmpeg_binary' => env('RENDER_FFMPEG_BINARY', 'ffmpeg'),
'render_ffprobe_binary' => env('RENDER_FFPROBE_BINARY', 'ffprobe'),
'render_subtitle_font' => env('RENDER_SUBTITLE_FONT', 'Noto Sans CJK JP'),
```

### docker/Dockerfile（現行 1 つ目 apt ブロック）
```dockerfile
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    ... \
    procps \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
# 2 つ目ブロックで fonts-noto-cjk を導入済み
```

### .github/workflows/ci.yml（php ジョブ現行）
```yaml
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.4", coverage: none }
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
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

### tests/Pest.php（Unit lane は RefreshDatabase グローバル適用 + StrayLlmCallGuard。Architecture lane は DB なし）
### 既存 tests/Unit/Render/FfmpegVideoComposerTest.php は全て Process::fake（実バイナリに触れない）。本 item では変更しない。
