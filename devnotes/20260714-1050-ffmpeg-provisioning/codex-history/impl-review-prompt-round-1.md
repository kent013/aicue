## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / **動画合成は自前 ffmpeg** / 単一 Default Project。

→ 本 item は「動画合成は自前 ffmpeg」という v1 中核前提を非本番環境(dev/bughunt/CI)で成立させ、bug-hunt F-1-0b を恒久クローズする。

## 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件（アプリ都合で緩めない）

tenant キー不信 / 子は親に属する(404 優先) / cross-org 不可 / untrusted 文字列は UserInput 型経由 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet / 課金冪等性 / 外部 URL 取得は SSRF 検査経由。

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

---

## system: あなたの役割

あなたは Laravel 12 (PHP 8.4) + Svelte 5 + Inertia アプリのシニアコードレビュアーである。本タスク「ffmpeg-provisioning (T039)」の実装差分をレビューせよ。これはインフラ(Dockerfile / CI)＋テスト追加のみの item で、アプリコード・レスポンス契約・prompt・LLM 呼び出し・UI・DB には一切触れない。

### レビュー観点
1. **設計との一致性**: 詳細設計書の 4 施策(Dockerfile へ ffmpeg / CI の fail-fast provision / Dockerfile 静的ガード Architecture テスト / 実 ffmpeg 合成 smoke テスト)が正しく実装されているか。
2. **正確性**: Dockerfile の apt 継続行が壊れていないか。ci.yml の YAML・シェルが正しいか(fc-match の family 判定ロジック含む)。テストのアサーションが意図通りか。正規表現(独立行アンカー `/^[ \t]*ffmpeg[ \t]*\\?[ \t]*$/m`)が誤検知・見逃しなく機能するか。
3. **PHPStan level 10 適合**: 戻り値型明示、null 安全(`file_get_contents` の false・`Process::run` 例外・`mkdir` 戻り値)、`config()->string()`/`config()->integer()` の型確定。
4. **テスト網羅性**: 静的ガードが退行(ffmpeg 行削除)を検出できるか。smoke テストが「日本語字幕焼き込みの最小合成が正常終了・mp4 出力・ffprobe 実測」を検証し、skip guard が未導入環境で確実に skip するか。既存 `FfmpegVideoComposerTest`(Process::fake) を破壊していないか。
5. **セキュリティ**: シェルインジェクション(`Process::run([...])` 配列引数)、一時ディレクトリの安全な生成・後始末(作成した work dir のみ削除、`sys_get_temp_dir()` 全 glob をしない)。
6. **禁止事項・不変条件**: 上記禁止事項に抵触しないか。特に「テストなしの実装完了」でないこと。

### 出力形式
- ファイルごとに判定を述べる。
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する。
- 最後に**全体判定を `APPROVED` または `CHANGES_REQUESTED`** で明記する。

備考: この diff は `resources/js/` `resources/css/` を含まないため DESIGN.md / Atomic Design 観点は N/A。

---

## user

### 詳細設計書（要約）

施策一覧:
1. Dockerfile へ ffmpeg を導入(`docker/Dockerfile` の 1 つ目の apt install ブロック、`curl` 直後に `ffmpeg` を追加。コメント行に役割を明示)。ffmpeg パッケージは ffprobe 同梱。字幕フォント `fonts-noto-cjk` は既存 2 つ目のブロックで導入済み。
2. CI へ ffmpeg+フォント導入と存在/解決の fail-fast 検証(`.github/workflows/ci.yml` の php ジョブ、Prepare environment の後・Pint の前)。`ffmpeg -version`/`ffprobe -version` に加え、`fc-match -f '%{family}\n' "Noto Sans CJK JP"` が Noto CJK family に解決することを grep で機械判定し、フォールバック時 exit 1。fontconfig も明示 install(design-review R1)。
3. Dockerfile 退行の静的ガード Architecture テスト(`tests/Architecture/DockerfileProvisioningTest.php` 新規)。CI は Dockerfile をビルドしないため、この静的ガードが ffmpeg / fonts-noto-cjk 行削除の退行を検出する唯一の機械的防御。独立パッケージ行アンカー正規表現(design-review R2)。`file_get_contents` の false は `Assert::string` で明示 fail + string へ narrow(design-review R1 Critical)。
4. 実 ffmpeg 合成 smoke テスト(`tests/Unit/Render/FfmpegVideoComposerSmokeTest.php` 新規、skip guard 付き)。Placeholder クリップ(黒背景+日本語字幕)を 1 本合成し、ffmpeg 本体・libass 字幕描画・concat・ffprobe 実測を一度に通す。既存 `FfmpegVideoComposerTest`(Process::fake) は変更しない。skip はローカル任意環境の便宜で、未導入 CI の赤化防止は施策 2 の層 1 が fail-fast で担う。work dir は一意生成し try/finally で作成分のみ削除(design-review R1)。`mkdir` 戻り値検証(design-review R1)。

設計上の既知トレードオフ(承認済み): 静的ガード正規表現は apt リストを 1 行整形するリファクタ時に更新要(design-review R2 が許容と明言)。実イメージビルド検証はコスト理由でスコープ外。

### 実装差分（git diff）

```diff
diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml
index baf69c1..f8ecac0 100644
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -23,6 +23,19 @@ jobs:
           cp .env.example .env
           php artisan key:generate
           php artisan passport:keys --force
+      # レンダー smoke テスト (施策 4) の前提。Dockerfile (dev/bughunt) と別に CI runner にも
+      # ffmpeg/ffprobe と字幕フォントを導入し、存在・フォント解決を fail-fast 検証する (層 1)。
+      - name: Provision ffmpeg for render smoke
+        run: |
+          sudo apt-get update
+          # fontconfig を明示 (fc-match の依存。ランナー差異で未導入の可能性をゼロにする。design-review R1)
+          sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig
+          ffmpeg -version
+          ffprobe -version
+          # fc-match の終了コードだけでなく、解決 family が Noto CJK であることを機械的に判定
+          # (代替フォントへのフォールバックを検出する。-f '%{family}' で family のみ抽出しノイズ耐性を上げる)
+          fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
+            || { echo "::error::Noto Sans CJK JP did not resolve to a Noto CJK family"; exit 1; }
       - name: Pint (code style)
         run: vendor/bin/pint --test
       - name: PHPStan
diff --git a/docker/Dockerfile b/docker/Dockerfile
index 53cb89d..4ea3b83 100644
--- a/docker/Dockerfile
+++ b/docker/Dockerfile
@@ -4,10 +4,11 @@ FROM php:8.4-cli
 ENV LC_ALL=C.UTF-8
 ENV LANG=C.UTF-8
 
-# システムパッケージ
+# システムパッケージ (ffmpeg = v1 動画合成 FfmpegVideoComposer の render runtime 依存。ffprobe 同梱)
 RUN apt-get update && apt-get install -y \
     git \
     curl \
+    ffmpeg \
     libpng-dev \
     libonig-dev \
     libxml2-dev \
diff --git a/tests/Architecture/DockerfileProvisioningTest.php b/tests/Architecture/DockerfileProvisioningTest.php
new file mode 100644
index 0000000..ff9cc86
--- /dev/null
+++ b/tests/Architecture/DockerfileProvisioningTest.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+use Webmozart\Assert\Assert;
+
+/*
+ * Dockerfile プロビジョニング不変条件 (ffmpeg-provisioning / bug-hunt F-1-0b) の static regression guard。
+ *
+ * v1 スコープ「動画合成は自前 ffmpeg」を dev/bughunt イメージで成立させ続けるための静的ガード。
+ * CI (ubuntu runner) は docker/Dockerfile をビルドしないため、この Architecture テストが
+ * Dockerfile からの ffmpeg / 字幕フォント削除という退行を検出する唯一の機械的防御になる
+ * (実イメージビルド検証はコスト理由でスコープ外)。
+ */
+
+/** docker/Dockerfile の内容 (apt install 行を走査する。読めない場合は Assert で明示 fail) */
+function dockerfileContents(): string
+{
+    $path = base_path('docker/Dockerfile');
+    Assert::fileExists($path);
+    $contents = file_get_contents($path);
+    Assert::string($contents, "docker/Dockerfile を読み込めません: {$path}"); // false を明示 fail + string へ narrow
+
+    return $contents;
+}
+
+test('static guard: docker/Dockerfile が apt パッケージとして ffmpeg を導入している (render runtime 退行防止)', function (): void {
+    // apt install ブロック内の独立パッケージ行として ffmpeg を検証する。行頭〜行末アンカー (/m) で
+    // コメント・別命令中の部分一致 ffmpeg を弾く (design-review R2: 貪欲マッチの誤検知回避)。
+    // 末尾 `\` はパッケージ位置により有無があるため optional (`\\?`)
+    expect(dockerfileContents())->toMatch('/^[ \t]*ffmpeg[ \t]*\\\\?[ \t]*$/m');
+});
+
+test('static guard: docker/Dockerfile が字幕焼き込み用 CJK フォント (fonts-noto-cjk) を導入している', function (): void {
+    // 字幕 (Noto Sans CJK JP) のフォント解決前提。tofu 化を防ぐ退行ガード (独立行アンカー)
+    expect(dockerfileContents())->toMatch('/^[ \t]*fonts-noto-cjk[ \t]*\\\\?[ \t]*$/m');
+});
diff --git a/tests/Unit/Render/FfmpegVideoComposerSmokeTest.php b/tests/Unit/Render/FfmpegVideoComposerSmokeTest.php
new file mode 100644
index 0000000..418b7cd
--- /dev/null
+++ b/tests/Unit/Render/FfmpegVideoComposerSmokeTest.php
@@ -0,0 +1,91 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
+use App\DataTransferObjects\Manual\Render\RenderClipSource;
+use App\DataTransferObjects\Manual\Render\RenderClipSpec;
+use App\DataTransferObjects\Manual\Render\RenderManifest;
+use App\Enums\Manual\RenderKind;
+use App\Services\Render\FfmpegVideoComposer;
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+
+/*
+ * 実 ffmpeg / ffprobe を用いた合成疎通 smoke (bug-hunt F-1-0b / ffmpeg-provisioning 層 2)。
+ * 既存 FfmpegVideoComposerTest は Process::fake でコマンド構造を検証する。本ファイルは
+ * 実バイナリを起動し「日本語字幕を焼き込んだ最小合成が正常終了し mp4 が出力される」ことを検証する。
+ * skip はローカル任意環境の便宜であり、CI では ffmpeg 導入を層 1 (ci.yml) が fail-fast で強制する。
+ */
+
+/** config 済みの ffmpeg / ffprobe が実行可能か (skip guard の指標。config 値を尊重・例外も未導入扱い) */
+function renderBinariesAvailable(): bool
+{
+    try {
+        foreach (['manual.render_ffmpeg_binary', 'manual.render_ffprobe_binary'] as $key) {
+            $binary = config()->string($key);
+            // バイナリ不在時に Process 実装差異で例外化しても「未導入」として確実に skip させる
+            if (! Process::run([$binary, '-version'])->successful()) {
+                return false;
+            }
+        }
+    } catch (Throwable) {
+        return false;
+    }
+
+    return true;
+}
+
+/** 一意な作業ディレクトリ (並列テスト安全。呼び出し側で try/finally 削除する) */
+function smokeWorkDir(): string
+{
+    $dir = sys_get_temp_dir().'/ffmpeg-smoke-'.bin2hex(random_bytes(8));
+    if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
+        throw new RuntimeException("smoke work dir を作成できません: {$dir}");
+    }
+
+    return $dir;
+}
+
+test('実 ffmpeg で日本語字幕を焼き込んだ最小合成が成功し mp4 を出力する', function (): void {
+    // 実エンコードを軽量化 (疎通が目的。画素比較はしない)
+    config()->set('manual.render_resolution', '320x240');
+    config()->set('manual.render_fps', 5);
+    config()->set('manual.preview_placeholder_seconds', 1);
+    config()->set('manual.render_subtitle_font', 'Noto Sans CJK JP'); // 再現性のため明示
+
+    $workDir = smokeWorkDir();
+
+    try {
+        $manifest = new RenderManifest(
+            renderJobId: 1,
+            kind: RenderKind::Preview,
+            scenarioVersion: 1,
+            outputKey: 'projects/1/manuals/1/previews/v1-1.mp4',
+            clips: [new RenderClipSpec(
+                cutId: 1,
+                label: '手順1',
+                source: RenderClipSource::Placeholder, // 素材ダウンロード不要 (黒背景 + 字幕)
+                takeVideoPath: null,
+                stillDisplaySeconds: null,
+                subtitlePrimary: null,
+                subtitleSecondary: 'これは疎通確認用の日本語字幕です。', // libass + フォント解決を通す
+            )],
+        );
+
+        $composed = app(FfmpegVideoComposer::class)->compose(
+            $manifest,
+            [], // Placeholder は localSources 不要
+            $workDir,
+            function (): void {},
+        );
+
+        expect($composed)->toBeInstanceOf(ComposedLocalVideo::class);
+        expect(is_file($composed->localPath))->toBeTrue();            // output.mp4 が存在
+        expect(filesize($composed->localPath))->toBeGreaterThan(0);   // 空でない
+        expect($composed->totalDurationMs)->toBeGreaterThan(0);       // ffprobe が尺を読めた
+    } finally {
+        // 作成した work dir のみを確実に削除 (sys_get_temp_dir 全 glob はしない。design-review R1)
+        File::deleteDirectory($workDir);
+    }
+})->skip(fn (): bool => ! renderBinariesAvailable(), 'ffmpeg/ffprobe 未導入のため skip (層 1 が CI で fail-fast)');

```

### テスト結果

- `composer test`(pest --parallel, RefreshDatabase グローバル適用): **1660 tests, 1658 passed, 2 skipped, 0 failed**(assertions 6930)。新規 smoke テストは ffmpeg 7.1 導入済み環境で実走(skip されず green)。
- `composer phpstan`(level 10): **No errors**。
- `vendor/bin/pint --test`: **passed**。
- 新規 3 テスト単体実行: Architecture 2 + smoke 1 = 3 passed(6 assertions, 実 ffmpeg エンコード ~9s)。
- TDD 確認: 施策 1(Dockerfile への ffmpeg 追加)適用前は Architecture テストの ffmpeg 行アサーションが fail し、適用後に green(fail→green を観測済み)。
- フロントエンド(pnpm lint / typecheck)は本 diff に無関係(FE ファイル変更ゼロ)。
