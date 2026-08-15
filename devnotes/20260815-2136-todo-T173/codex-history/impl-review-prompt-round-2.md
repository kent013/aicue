# Round 2: Round 1 の指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Warning] `RouteCacheExemptionPremiseTest` の外部ファイル丸ごと除外が死角になる

- 判断: **対応する**
- 根拠: 指摘のとおり。`tests/Feature/Security/RouteThrottleBinderTest.php` を丸ごと外すと、
  そのファイルへ将来 `Artisan::call('route:cache')` が入っても沈黙する。
  本リポジトリには既に「例外は件数付きで目録へ登録する」作法
  (`ForbiddenStatementExemption`) があり、そちらへ寄せるのが自然である。
- 対応内容:
  - 丸ごとの除外を **本テスト自身 1 件だけ** (`ROUTE_CACHE_PREMISE_SELF_PATH`) にした。
  - 説明として needle を持つ既存ファイルは
    `ROUTE_CACHE_PREMISE_KNOWN_MENTIONS`(パス => **件数**) の目録へ移し、
    新しいテスト「説明として needle を持つファイルの件数が完全一致で pin されている」で
    **増えても減っても赤**にした。
  - 件数を 1 → 2 に書き換えると実際に赤くなることを確認済み (確認後に戻した)。

## [Warning] AGENTS.md / guide / D19 の保証範囲の説明が除外を反映していない

- 判断: **対応する**
- 根拠: 保証範囲の記述は 3 つの文書で強度をそろえる方針であり、片方だけ強い書き方が残ると
  次の担当が読み違える。
- 対応内容: 3 文書すべてに次の 1 文を同じ言葉で足した —
  「説明として `route:cache` の語を持つ既存ファイルは件数を完全一致で pin して扱い
  (増減のどちらでも赤になる)、走査から丸ごと外れているのは同テスト自身の 1 件だけである
  (その 1 ファイルの中は見えない)」。
  テスト側の docblock にも同じ穴を明記した。

## [参考] 検査 1 / 検査 2 は指摘なし

- Codex は「検査 1 は同語反復ではない」「検査 2 の自己証明は十分」と判定した。変更していない。


## 修正後の全文差分 (HEAD からの差分。Round 1 との差ではなく現在の姿)

### tests/Architecture/RouteCacheExemptionPremiseTest.php

```diff
diff --git a/tests/Architecture/RouteCacheExemptionPremiseTest.php b/tests/Architecture/RouteCacheExemptionPremiseTest.php
new file mode 100644
index 0000000..40a04e4
--- /dev/null
+++ b/tests/Architecture/RouteCacheExemptionPremiseTest.php
@@ -0,0 +1,487 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+
+/*
+ * 逸脱 D19 (経路キャッシュ起動での後付けは「走らせない」側を維持する) を
+ * 許している**前提そのもの**を機械で固定する。
+ *
+ * ★これは「デプロイの正しさ」を事前検査する仕組みではない (AGENTS.md が禁じているのはそちら)。
+ *   固定するのは、D19 を許している前提に対して**いま定義できるトリップワイヤ**である。
+ *   同じ形は tests/Feature/Security/ThrottleExemptionPremiseTest.php /
+ *   tests/Feature/Security/IdempotencyExemptionPremiseTest.php に前例がある。
+ *
+ * ★赤くなったときに求めるのは「デプロイを正しくすること」ではなく、
+ *   「D19 を読み直して、専用の実行点クラスへの移行か、毎デプロイ再生成の機械強制かを
+ *   同じ PR で決めること」である。
+ *
+ * ★2 つの検査は対等ではない。前提を本当に決めるのは検査 B (`route:cache` が実行されないこと) で、
+ *   検査 A (デプロイ定義が無いこと) は**早期の気づき**のための粗い網である。
+ *   デプロイ定義があっても `route:cache` を打たなければ前提は崩れず、逆に定義が無くても
+ *   `route:cache` を打てば崩れる。したがって A は網羅を主張しない。
+ *
+ * ★保証範囲を誇張しない:
+ *   - PHP は**コメントと docblock を落とした後のコードと文字列リテラル**を走査する。
+ *   - 見ないもの: Markdown の説明文 / 動的に連結した文字列 / リポジトリの外から与えられる実行手順。
+ *   - 検出するのは「`artisan` と `optimize` の間が空白だけ」の書き方までである。
+ *     間にオプションが挟まる形 (`artisan --env=production optimize`) は**拾わない**。
+ *     シェルの文法を正規表現で解析し始めると際限がないため、ここで線を引いている。
+ *   - 起動時の cache の鮮度も、デプロイ手順の正しさも検査しない。
+ *   - 検査 A は新しい CI 基盤やファイル名を網羅できない (`.github/workflows/*.yml` の中身も見ない)。
+ *   - **丸ごと走査から外しているのは本テスト自身 1 件だけ**である (検出したい語を負のコントロールの
+ *     入力として持つため)。したがって**本ファイルの中の実行記述には沈黙する**。
+ *     説明として needle を持つ他のファイルは、丸ごと外さずに**件数を完全一致で pin** して扱う。
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * 本逸脱の登録番号。`docs/template-divergence.md` / `AGENTS.md` /
+ * `docs/app-integration-guide.md` と本テストの結線はこの 1 か所を通す
+ * (番号を 2 か所に書かない)。
+ */
+const ROUTE_CACHE_DIVERGENCE_ID = 'D19';
+
+/**
+ * 検査 B の走査から**丸ごと**外す唯一のファイル (repo 相対)。
+ *
+ * 本テスト自身だけである。検出したい語を負のコントロールの入力として持つため、
+ * 自分を走査すると必ず自分で赤くなる。
+ *
+ * ★**保証の穴として明記する**: 本ファイルの中に `route:cache` の実行記述を書いても
+ *   検査 B は沈黙する。丸ごとの除外はこの 1 件に限り、他のファイルは
+ *   {@see ROUTE_CACHE_PREMISE_KNOWN_MENTIONS} の**件数 pin** で扱う。
+ */
+const ROUTE_CACHE_PREMISE_SELF_PATH = 'tests/Architecture/RouteCacheExemptionPremiseTest.php';
+
+/**
+ * 説明文として needle を持つことが確認済みのファイルと、その**件数**。
+ *
+ * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` と同じ作法)。
+ * ファイル単位の除外にしないのは、除外したファイルの中に将来の実行記述が紛れ込んでも
+ * 沈黙してしまうためである (deny-by-default の粒度を落とさない)。
+ *
+ * - `RouteThrottleBinderTest`: テスト名の文字列に「route:cache 下の再適用が冪等」という
+ *   **説明**が入っている。実行ではないが、コメントを落としても文字列リテラルとして残る。
+ *
+ * @var array<string, int>
+ */
+const ROUTE_CACHE_PREMISE_KNOWN_MENTIONS = [
+    'tests/Feature/Security/RouteThrottleBinderTest.php' => 1,
+];
+
+/**
+ * 走査の母集団が空振りでないことを確かめる代表パス。
+ *
+ * @var list<string>
+ */
+const ROUTE_CACHE_PREMISE_SENTINEL_PATHS = [
+    'composer.json',
+    '.github/workflows/ci.yml',
+    'scripts/bug-hunt-shard.sh',
+];
+
+/** 走査の母集団の下限 (これを下回ったら列挙そのものを疑う)。 */
+const ROUTE_CACHE_PREMISE_MINIMUM_TRACKED_FILES = 500;
+
+/**
+ * git 追跡下の全ファイル (repo 相対パス、昇順)。
+ *
+ * ★`Tests\Support\TrackedPhpSourceFiles` は `*.php` 専用なので使えない。対象が
+ *   拡張子を問わないため、共用クラスを新設せず本テスト内に閉じる (今必要なものだけ作る)。
+ * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
+ *
+ * @return list<string>
+ */
+function routeCachePremiseTrackedFiles(): array
+{
+    $process = new Process(['git', 'ls-files', '-z'], base_path());
+    $process->run();
+
+    if (! $process->isSuccessful()) {
+        throw new RuntimeException(
+            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
+            .$process->getErrorOutput()
+        );
+    }
+
+    $files = [];
+    foreach (explode("\0", $process->getOutput()) as $relative) {
+        if ($relative === '') {
+            continue;
+        }
+
+        $files[] = $relative;
+    }
+
+    sort($files);
+
+    return $files;
+}
+
+/**
+ * PHP ソースからコメントと docblock を落とす (行番号を保つ)。
+ *
+ * 落とした部分は**同じ改行数**へ置き換える。単にトークンを連ねると、違反の報告で
+ * 出すファイル名と行番号が実際の位置とずれる。
+ */
+function routeCachePremiseStripPhpComments(string $source): string
+{
+    $stripped = '';
+
+    foreach (token_get_all($source) as $token) {
+        if (! is_array($token)) {
+            $stripped .= $token;
+
+            continue;
+        }
+
+        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
+            $stripped .= str_repeat("\n", substr_count($token[1], "\n"));
+
+            continue;
+        }
+
+        $stripped .= $token[1];
+    }
+
+    return $stripped;
+}
+
+/**
+ * 検査 B の判定 (純関数)。`route:cache` の実行と、空白だけを挟む `artisan optimize` を探す。
+ *
+ * 素の `optimize` は `route:cache` を含む複合コマンドなので対象に入れる。`optimize:clear`
+ * (消す側。bug-hunt が使う) は直後が `:` なので一致しない。
+ *
+ * @return list<array{line: int, needle: string}> 1 起点の行番号と一致した語
+ */
+function routeCachePremiseViolations(string $contents): array
+{
+    $patterns = [
+        'route:cache' => '/route:cache/',
+        'artisan optimize' => '/artisan\s+optimize(?!:)/',
+    ];
+
+    $violations = [];
+
+    foreach ($patterns as $needle => $pattern) {
+        if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
+            throw new RuntimeException("正規表現の実行に失敗しました: {$pattern}");
+        }
+
+        foreach ($matches[0] as $match) {
+            $violations[] = [
+                'line' => substr_count(substr($contents, 0, (int) $match[1]), "\n") + 1,
+                'needle' => $needle,
+            ];
+        }
+    }
+
+    usort($violations, fn (array $a, array $b): int => $a['line'] <=> $b['line']);
+
+    return $violations;
+}
+
+/**
+ * 検査 A の判定 (純関数)。デプロイ定義の実体とみなすパスを返す。
+ *
+ * @param  list<string>  $paths
+ * @return list<string>
+ */
+function routeCachePremiseDeployDefinitionPaths(array $paths): array
+{
+    $directories = ['deploy/', 'ansible/', '.ebextensions/', 'k8s/', 'kubernetes/', 'helm/', 'charts/'];
+    $exactNames = [
+        'Procfile', 'fly.toml', 'render.yaml', 'app.yaml', 'vercel.json',
+        'railway.json', 'captain-definition', '.gitlab-ci.yml', '.travis.yml',
+        'azure-pipelines.yml', 'Jenkinsfile',
+    ];
+    $ciDirectories = ['.circleci/', '.buildkite/'];
+
+    $matched = [];
+
+    foreach ($paths as $path) {
+        $basename = basename($path);
+
+        foreach ([...$directories, ...$ciDirectories] as $prefix) {
+            if (str_starts_with($path, $prefix)) {
+                $matched[] = $path;
+
+                continue 2;
+            }
+        }
+
+        if (in_array($basename, $exactNames, true)) {
+            $matched[] = $path;
+
+            continue;
+        }
+
+        if (str_ends_with($path, '.tf') || str_ends_with($path, '.tfvars')) {
+            $matched[] = $path;
+
+            continue;
+        }
+
+        if (str_starts_with($basename, 'docker-compose') && str_ends_with($basename, '.yml')
+            && str_contains($basename, 'prod')) {
+            $matched[] = $path;
+
+            continue;
+        }
+
+        if (str_starts_with($path, '.github/workflows/')) {
+            $lowered = strtolower($basename);
+            foreach (['deploy', 'release', 'cd'] as $hint) {
+                if (str_contains($lowered, $hint)) {
+                    $matched[] = $path;
+
+                    continue 2;
+                }
+            }
+        }
+    }
+
+    return $matched;
+}
+
+/**
+ * 検査 B の走査結果を、ファイル単位で "path:line (needle)" の一覧にして返す。
+ *
+ * @return array<string, list<string>> repo 相対パス => 一致の一覧
+ */
+function routeCachePremiseScanByFile(): array
+{
+    $byFile = [];
+
+    foreach (routeCachePremiseTrackedFiles() as $relative) {
+        if (str_ends_with($relative, '.md')) {
+            continue;
+        }
+
+        if ($relative === ROUTE_CACHE_PREMISE_SELF_PATH) {
+            continue;
+        }
+
+        $absolute = base_path().'/'.$relative;
+        if (! is_file($absolute)) {
+            continue; // 削除済みだが index に残っている等
+        }
+
+        $contents = file_get_contents($absolute);
+        if (! is_string($contents)) {
+            throw new RuntimeException("読み取れないファイル: {$relative}");
+        }
+
+        if (str_ends_with($relative, '.php')) {
+            $contents = routeCachePremiseStripPhpComments($contents);
+        }
+
+        foreach (routeCachePremiseViolations($contents) as $violation) {
+            $byFile[$relative][] = "{$relative}:{$violation['line']} ({$violation['needle']})";
+        }
+    }
+
+    return $byFile;
+}
+
+/**
+ * 件数 pin 済みのファイルを除いた、想定外の一致の一覧。
+ *
+ * @return list<string>
+ */
+function routeCachePremiseScanFindings(): array
+{
+    $findings = [];
+
+    foreach (routeCachePremiseScanByFile() as $relative => $matches) {
+        if (array_key_exists($relative, ROUTE_CACHE_PREMISE_KNOWN_MENTIONS)) {
+            continue; // 件数の一致は別のテストが完全一致で検査する
+        }
+
+        foreach ($matches as $match) {
+            $findings[] = $match;
+        }
+    }
+
+    sort($findings);
+
+    return $findings;
+}
+
+/**
+ * `docs/template-divergence.md` の当該逸脱の節 (見出しから次の見出しまで)。
+ */
+function routeCachePremiseDivergenceSection(): string
+{
+    $document = file_get_contents(base_path().'/docs/template-divergence.md');
+    expect($document)->toBeString();
+
+    $heading = '## '.ROUTE_CACHE_DIVERGENCE_ID.' ';
+    $start = strpos((string) $document, $heading);
+    expect($start)->toBeInt(
+        'docs/template-divergence.md に ['.ROUTE_CACHE_DIVERGENCE_ID.'] の見出しがありません',
+    );
+
+    $rest = substr((string) $document, (int) $start);
+    $next = strpos($rest, "\n## ", 1);
+
+    return $next === false ? $rest : substr($rest, 0, $next);
+}
+
+/*
+ * 2-1: 検査 A (早期の気づき)。
+ */
+test('デプロイ定義の実体が追跡下に 1 件も無い (D19 の前提の早期の気づき)', function (): void {
+    $matched = routeCachePremiseDeployDefinitionPaths(routeCachePremiseTrackedFiles());
+
+    expect($matched)->toBe([], implode("\n", [
+        'デプロイ定義とみなされるファイルが追跡下にあります:',
+        '  '.implode("\n  ", $matched),
+        '',
+        'これは意図した摩擦です。'.ROUTE_CACHE_DIVERGENCE_ID.' (docs/template-divergence.md) を読み直し、',
+        '  (1) 経路の一覧が組み上がった後に走らせる専用の実行点クラスへ移行する',
+        '  (2) 毎デプロイの `php artisan route:cache` 再生成を機械強制する',
+        'のどちらを採るかを同じ PR で決めてください。',
+        'デプロイと無関係な名前 (例: 文書公開の workflow) で一致した場合は、',
+        '本テストの検査条件と '.ROUTE_CACHE_DIVERGENCE_ID.' の文章を同じ PR で直してください。',
+    ]));
+});
+
+/*
+ * 2-2: 検査 B (本体)。前提を本当に決めるのはこちら。
+ */
+test('route:cache を実行する記述が追跡下に 1 件も無い (D19 の主前提)', function (): void {
+    $findings = routeCachePremiseScanFindings();
+
+    expect($findings)->toBe([], implode("\n", [
+        '`route:cache` を実行する記述が追跡下にあります:',
+        '  '.implode("\n  ", $findings),
+        '',
+        ROUTE_CACHE_DIVERGENCE_ID.' は「経路キャッシュ起動では後付けを走らせない」側の契約を、',
+        '`route:cache` が実行されないことを前提に許しています。前提が崩れた以上、',
+        '専用の実行点クラスへの移行か、毎デプロイ再生成の機械強制かを同じ PR で決めてください。',
+    ]));
+});
+
+/*
+ * 2-2b: 説明文として needle を持つファイルは**件数を完全一致で pin** する。
+ *       増えても減っても赤にすることで、ファイル単位の除外が作る死角を無くす。
+ */
+test('説明として needle を持つファイルの件数が完全一致で pin されている', function (): void {
+    $byFile = routeCachePremiseScanByFile();
+
+    $actual = [];
+    foreach (ROUTE_CACHE_PREMISE_KNOWN_MENTIONS as $relative => $expected) {
+        $actual[$relative] = count($byFile[$relative] ?? []);
+    }
+
+    expect($actual)->toBe(ROUTE_CACHE_PREMISE_KNOWN_MENTIONS, implode("\n", [
+        '説明文として needle を持つと登録したファイルの件数が変わりました。',
+        '増えた場合: 増えた 1 件が本当に「説明」なのかを確認してください。',
+        '  実行記述なら '.ROUTE_CACHE_DIVERGENCE_ID.' の前提が崩れています。',
+        '減った場合: 登録が不要になったので目録から外してください (空振り green を残さない)。',
+        '実際の一致: '.json_encode($byFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
+    ]));
+});
+
+/*
+ * 2-3: 走査の母集団が空振りでないこと (床値と代表パスの pin)。
+ */
+test('走査の母集団が空振りでない (床値と代表パスの pin)', function (): void {
+    $tracked = routeCachePremiseTrackedFiles();
+
+    expect(count($tracked))->toBeGreaterThanOrEqual(
+        ROUTE_CACHE_PREMISE_MINIMUM_TRACKED_FILES,
+        '追跡下ファイルの列挙が少なすぎます (git ls-files が期待どおり動いていない可能性)',
+    );
+
+    foreach (ROUTE_CACHE_PREMISE_SENTINEL_PATHS as $sentinel) {
+        expect(in_array($sentinel, $tracked, true))->toBeTrue(
+            "代表パス [{$sentinel}] が列挙に含まれません。走査域が変わっていないか確認してください。",
+        );
+    }
+});
+
+/*
+ * 2-4: 負のコントロール。判定関数の**検出範囲の境界**を固定する。
+ *      最後の 1 件は「安全だから許す」ではなく「いまの検出器の境界を見えるようにする」ためである。
+ */
+test('検出器の境界が固定されている', function (string $sample, bool $shouldDetect): void {
+    $detected = routeCachePremiseViolations($sample) !== [];
+
+    expect($detected)->toBe($shouldDetect, "入力: {$sample}");
+})->with([
+    'php artisan route:cache は検出する' => ['php artisan route:cache', true],
+    'Artisan::call の route:cache は検出する' => ["Artisan::call('route:cache');", true],
+    'artisan optimize は検出する' => ['php artisan optimize', true],
+    '空白が複数の artisan optimize も検出する' => ["php artisan   optimize\n", true],
+    'artisan optimize:clear は検出しない' => ['php artisan optimize:clear --except=cache', false],
+    'オプションを挟む artisan optimize は検出しない' => ['php artisan --env=production optimize', false],
+    '無関係な文字列は検出しない' => ['php artisan migrate --force', false],
+]);
+
+/*
+ * 2-5: 負のコントロール。コメントは落とすが文字列リテラルは残す、の両方向と、
+ *      落とした後も行番号がずれないことを固定する。
+ */
+test('PHP のコメント中の記述は違反にせず、文字列リテラル中の記述は違反にする', function (): void {
+    $commentOnly = <<<'PHP'
+        <?php
+
+        // ここでは route:cache の契約を説明しているだけである
+        /* php artisan optimize についての説明 */
+        /** route:cache の docblock */
+        $value = 1;
+        PHP;
+
+    expect(routeCachePremiseViolations(routeCachePremiseStripPhpComments($commentOnly)))->toBe([]);
+
+    $literal = <<<'PHP'
+        <?php
+
+        /*
+         * 複数行にまたがる説明。
+         * route:cache について書いてある。
+         */
+        Artisan::call('route:cache');
+        PHP;
+
+    $violations = routeCachePremiseViolations(routeCachePremiseStripPhpComments($literal));
+
+    expect($violations)->toHaveCount(1);
+    expect($violations[0]['needle'])->toBe('route:cache');
+    // 元の文字列で `Artisan::call` は 7 行目にある (コメントを落としても行番号は動かない)
+    expect($violations[0]['line'])->toBe(7);
+});
+
+/*
+ * 2-6: D19 と本テストの結線が切れていないこと。
+ *      **保証範囲を誇張しない**: これは「参照が切れていないこと」までで、
+ *      文章の意味が検査と一致していることは機械では見られない。
+ */
+test('逸脱の登録と本テストの結線が切れていない', function (): void {
+    $section = routeCachePremiseDivergenceSection();
+
+    // ★`toContain()` は可変長 needle を取るためメッセージ引数を持てない。bool へ落として理由を書く。
+    expect(str_contains($section, 'RouteCacheExemptionPremiseTest.php'))->toBeTrue(
+        ROUTE_CACHE_DIVERGENCE_ID.' の節が本テストのファイル名を書いていません',
+    );
+
+    foreach (['route:cache', 'artisan optimize', 'デプロイ定義'] as $keyword) {
+        expect(str_contains($section, $keyword))->toBeTrue(
+            ROUTE_CACHE_DIVERGENCE_ID.' の節に検査条件の要点 ['.$keyword.'] がありません',
+        );
+    }
+
+    foreach (['AGENTS.md', 'docs/app-integration-guide.md'] as $referrer) {
+        $document = file_get_contents(base_path().'/'.$referrer);
+        expect($document)->toBeString();
+        expect(str_contains((string) $document, ROUTE_CACHE_DIVERGENCE_ID))->toBeTrue(
+            "{$referrer} が ".ROUTE_CACHE_DIVERGENCE_ID.' を参照していません',
+        );
+    }
+});

```

### AGENTS.md / docs / detailed-design.md

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 6ecff45..84c916b 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -127,6 +127,21 @@ ## セキュリティ不変条件(アプリ都合で緩めない)
 > よって現在この要件は**人手でのみ守られている**。**デプロイ基盤を作る PR は、
 > 本要件と TRUSTED_PROXIES 運用要件 (T108) の 2 つを実装するまで完了にできない**。
 > 存在しない基盤のための preflight 機構を先回りして作らないこと(思考原則 2)。
+> 家系の正典はこの後付けを「経路の一覧が組み上がった後に走らせる専用の実行点」へ集約する形だが、
+> **本リポジトリは「経路キャッシュ起動では走らせない」側を選んでいる**。この判断は
+> `docs/template-divergence.md` **D19** に登録済みである。
+> 判断の主前提に対するトリップワイヤとして、**追跡下に直接書かれた `route:cache`** と、
+> **`artisan` と `optimize` の間が空白だけの実行記述**が無いことを
+> `tests/Architecture/RouteCacheExemptionPremiseTest.php` が機械で固定する。
+> **動的に組み立てた文字列・オプションを挟む書き方・リポジトリの外にある手順は対象外**である。
+> 説明として `route:cache` の語を持つ既存ファイルは**件数を完全一致で pin** して扱い
+> (増減のどちらでも赤になる)、走査から丸ごと外れているのは**同テスト自身の 1 件だけ**である
+> (自分が検出したい語を負のコントロールの入力として持つため。その 1 ファイルの中は見えない)。
+> 同テストは既知のデプロイ定義が増えたことも早期に知らせるが、そちらは網羅を主張しない
+> (新しい CI 基盤やファイル名は拾えない)。**どちらかで赤くなったら D19 を読み直すこと。**
+> 焼き込みの入力に後付けが載っていることと、欠けたときに保護が実際に外れることは
+> `tests/Feature/Security/RouteCacheBakedProtectionTest.php` が固定する
+> (同一プロセス内の実測であり、**cached 起動そのものの再現ではない**)。
 
 ## テストレーンの外部 HTTP 出口 (既定拒否)
 
diff --git a/devnotes/20260815-2100-route-cache-middleware-attach/detailed-design.md b/devnotes/20260815-2100-route-cache-middleware-attach/detailed-design.md
index db2b050..dc495af 100644
--- a/devnotes/20260815-2100-route-cache-middleware-attach/detailed-design.md
+++ b/devnotes/20260815-2100-route-cache-middleware-attach/detailed-design.md
@@ -210,8 +210,20 @@ ### 何を検査するか
   誤検出するため、**`.php` は `token_get_all()` でコメントと docblock を落としてから**走査する。
   落とした後に残るのは実行するコードと文字列リテラルだけである。
 - **自己言及の除外**: 本テスト自身は needle を文字列リテラルとして持つので、
-  **自分 1 ファイルだけを名指しで除外**する（`PostBootRouteMutationInventoryTest` と同じ allowlist の作法）。
-  除外はこの 1 件に限り、増やすときはレビューで必ず見える形にする。
+  **自分を名指しで除外**する（`PostBootRouteMutationInventoryTest` と同じ allowlist の作法）。
+  丸ごとの除外はこの 1 件に限る。**その 1 ファイルの中の実行記述には沈黙する**ことを
+  docblock と文書の両方に穴として書く。
+  - **実装時に判明したため設計を更新（説明として語を持つ既存ファイルの扱い）**: 既存の
+    `tests/Feature/Security/RouteThrottleBinderTest.php` が、テスト名の文字列に
+    「route:cache 下の再適用が冪等」という**説明**を持っている。実行ではないが、
+    コメントを落としても文字列リテラルとして残るため検出される。
+    needle を「`artisan` に続く `route:cache`」へ狭めれば済むが、それでは
+    `Artisan::call('route:cache')` を拾えなくなり、`.php` を除外しない理由
+    （Codex Round 1 の指摘）に正面から反する。
+    かといって**ファイル単位で丸ごと除外すると、そのファイルへ将来の実行記述が
+    紛れ込んでも沈黙する**（実装レビュー Round 1 の指摘）。よって
+    **件数を完全一致で pin する目録**（`ForbiddenStatementExemption` と同じ作法）にする。
+    増えても減っても赤になるので、deny-by-default の粒度が落ちない。
 - **行番号を失わない**: コメントを落とすときはトークンを単に連ねず、
   **落とす部分を同じ改行数を持つ空白へ置き換える**。こうしないと 2-2 の
   「ファイル名と行番号を列挙する」が実際の位置とずれる。
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 10c8156..19722ea 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -506,6 +506,21 @@ ### §7c vendor route への後付け機構と route:cache の契約
 デプロイ基盤を作る PR が**必ず実装しなければならない要件**である(AGENTS.md の運用要件ブロック)。
 今その基盤を先回りして作らない(思考原則 2)。
 
+家系の正典が採る「経路の一覧が組み上がった後に走らせる専用の実行点へ集約する」形へ**移行しない**
+判断は、`docs/template-divergence.md` の **D19** に登録済みである。主前提は
+「`route:cache` が実行されないこと」で、`tests/Architecture/RouteCacheExemptionPremiseTest.php` が
+**追跡下に直接書かれた `route:cache`** と **`artisan` と `optimize` の間が空白だけの実行記述**が
+無いことを機械で固定する。検出できるのは直接書かれた文字列までで、動的に組み立てた実行・
+オプションを挟む書き方・リポジトリの外にある手順は対象外である。
+説明として `route:cache` の語を持つ既存ファイルは**件数を完全一致で pin** して扱い
+(増減のどちらでも赤になる)、走査から丸ごと外れているのは**同テスト自身の 1 件だけ**である
+(自分が検出したい語を負のコントロールの入力として持つため。その 1 ファイルの中は見えない)。
+デプロイ定義の検出も
+同テストが併せて行うが、そちらは**早期の気づき**であって網羅を主張しない。
+焼き込みの入力に後付けが欠落なく載ることと、欠けたときに保護が実際に外れることは
+`tests/Feature/Security/RouteCacheBakedProtectionTest.php` が実測で固定する
+(同一プロセス内で完結する検査であり、**cached 起動そのものの再現ではない**)。
+
 **新しい後付け経路を足すとき**: 必ず上記 2 binder のどちらかを通す。
 `PostBootRouteMutationInventoryTest` が deny-by-default で強制する
 (`app/` 配下で起動後に named route を名前で引くコードを allowlist 2 ファイルに限る)。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 9d71920..5661a87 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -809,3 +809,80 @@ ### 関連
 - gate: `tests/Architecture/ClaudeHooksWiringTest.php`
 - 設計: `devnotes/20260815-1539-claude-hooks-settings-wiring/`
 - 規約の正本: `AGENTS.md` §常設 hook 配線
+
+---
+
+## D19 ✅ 経路キャッシュ起動での middleware 後付けは「走らせない」側の契約を維持する (専用の実行点クラスへは移行しない)
+
+家系の正典 (機能台帳 `route-cache-safe-middleware-attach` の v1) は、経路の一覧が組み上がった後に
+走らせたい処理を**専用の実行点クラス 1 つ**へ集約し、経路キャッシュ起動でも後付けを効かせる形である。
+本アプリはそこへ**移行しない**。この判断を逸脱として登録する。
+
+| 観点 | 家系の正典 / テンプレート | 本アプリ |
+|---|---|---|
+| 実行点 | 専用クラス 1 つ (`AfterRoutesLoaded` 相当) へ集約 | 2 つの binder が各々 `Application::booted()` を使う |
+| 経路キャッシュ起動での契約 | 容器の `routes` 束縛の張り替えを捕まえ、読み込まれた直後に後付けを走らせる | **走らせない**。実効は `route:cache` 生成時の焼き込み |
+| 入口の絞り込み | 素の起動完了フックの直呼びを走査で禁止 | `PostBootRouteMutationInventoryTest` が入口を 2 binder に絞る (deny-by-default) |
+| 経路キャッシュ起動での実証 | 別プロセスで起動して後付けの残存を確認 | 同一プロセスで「焼き込みの入力」と「欠落時の剥落」を確認 (別プロセス起動は導入しない) |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **前提が今は成立している**。本リポジトリにデプロイ定義の実体は無く、`route:cache` を実行する
+   記述も追跡下に 1 件も無い。ただし言えるのは「**いま定めた走査条件で検出される発生経路が無い**」
+   までである (「発生確率がゼロ」とも「管理下に発生経路が無い」とも書かない。走査条件が拾わない
+   書き方は `tests/Architecture/RouteCacheExemptionPremiseTest.php` の説明が列挙する)。
+2. **毎デプロイ再生成の機械強制は今は採れない**。`AGENTS.md` の運用要件 (route:cache) が
+   「存在しない基盤のための preflight 機構を先回りして作らないこと (思考原則 2)」と明記しており、
+   デプロイ基盤そのものが無い段階で preflight を作るのは、その規約に正面から反する。
+3. **正典の形は Laravel 13 では 4 つの問題を同時に解く必要がある** — 容器の `routes` 束縛の
+   張り替えの捕捉 / その束縛がまだ無いときに張り替えが発火しない穴の手当て / 経路一覧の実体ごとの
+   冪等 / cached 起動で起動を止めると `route:list` も `route:clear` も落ちて復旧手段を失う問題への
+   例外設計。実証には別プロセスで起動する検査基盤も要る。
+   **正典を採る利益は「運用要件を 1 つ消せること」であり、その運用要件が効く相手 (デプロイ基盤) が
+   まだ無い**。基盤を作る PR で実物の手順と突き合わせて設計する方が確実である。
+4. **これは保留ではなく明示の判断である**。期限で自然解消せず、**前提が崩れたときに解消する**。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「後付けした保護は、実効の経路に必ず載る」
+> 「後付けの入口は 2 つの binder に限られる」
+> 「経路名が消えたら起動を止める (無防備なまま公開しない)」
+
+担い手は次のとおりで、新設したのは下 3 行だけである (既存目録と二重管理にしない)。
+
+| 不変条件 | 担い手 |
+|---|---|
+| どの route に何を付けるべきか (対象と件数) | `ThrottleCoverageInventoryTest` / `RecentAuthRouteTest` / `TwoFactorStepUpInventoryTest` / `PasskeyRouteProtectionTest` |
+| 後付けの入口が 2 binder に限られること | `PostBootRouteMutationInventoryTest` |
+| 後付けの契約 (cached では resolver すら呼ばない / 経路が引けなければ起動を止める / 冪等 / 列の順) | `RouteMiddlewareBinderTest` / `RouteThrottleBinderTest` |
+| 実際に付いた middleware 列が、直列化の準備を通しても焼き込みの入力へ欠落なく移ること | `tests/Feature/Security/RouteCacheBakedProtectionTest.php` (検査 1) |
+| 焼き込みが欠けた経路一覧では保護が実際に外れること | `tests/Feature/Security/RouteCacheBakedProtectionTest.php` (検査 2) |
+| この逸脱を許す前提 (直接書かれた `route:cache` / 空白だけを挟む `artisan optimize` が無いこと。デプロイ定義の不在は早期の気づき) | `tests/Architecture/RouteCacheExemptionPremiseTest.php` |
+
+**保証範囲を誇張しない**: `RouteCacheBakedProtectionTest` が固定するのは同一プロセス内の
+「直列化の準備 → compile」までで、**cached 起動そのものの再現ではない**。
+`RouteCacheExemptionPremiseTest` が見るのは追跡下の文字列までで、動的に組み立てた実行・
+オプションを挟む書き方・リポジトリの外にある手順には沈黙する。
+説明として `route:cache` の語を持つ既存ファイルは**件数を完全一致で pin** して扱い
+(増減のどちらでも赤になる)、走査から丸ごと外れているのは**同テスト自身の 1 件だけ**である
+(自分が検出したい語を負のコントロールの入力として持つため。その 1 ファイルの中は見えない)。
+また**デプロイ定義の検出は網羅を主張しない** (新しい CI 基盤やファイル名は拾えない)。
+主前提を固定するのは `route:cache` 側の検査である。
+
+### 再検討の条件 (解消条件)
+
+- リポジトリに**デプロイ定義**の実体が入ったとき
+- `route:cache` (または `artisan optimize`) を実行する記述が入ったとき
+- 家系の機能台帳の裁定が変わったとき
+
+前の 2 つは `RouteCacheExemptionPremiseTest` の検査条件と**同じ言葉**で書いてある。
+どちらかで赤くなったら、正典の形への移行か毎デプロイ再生成の機械強制かを**同じ PR で**決めること。
+
+### 関連
+
+- 実装: `app/Support/Http/RouteMiddlewareBinder.php` / `app/Support/Http/RouteThrottleBinder.php`
+- gate: `tests/Architecture/RouteCacheExemptionPremiseTest.php` /
+  `tests/Feature/Security/RouteCacheBakedProtectionTest.php` /
+  `tests/Architecture/PostBootRouteMutationInventoryTest.php`
+- 設計: `devnotes/20260815-2100-route-cache-middleware-attach/`
+- 契約の正本: `docs/app-integration-guide.md` §7c

```

## テスト結果 (再実行)

- `tests/Architecture/RouteCacheExemptionPremiseTest.php`: 13 passed / 29 assertions
- 件数 pin の負の確認: 目録の件数を 1 → 2 に書き換えると当該テストだけが赤くなることを実行して確認し、値を戻した
- `tests/Feature/Security/RouteCacheBakedProtectionTest.php`: 13 passed (変更なし)
- `composer phpstan` / `vendor/bin/pint --test`: 変更前後とも green (再実行はコミット前に全数で行う)

## 確認してほしいこと

1. 件数 pin (`ROUTE_CACHE_PREMISE_KNOWN_MENTIONS`) が deny-by-default の粒度を回復できているか
2. 丸ごと除外を本テスト自身 1 件に限り、その穴を docblock と 3 文書に明記した形で十分か
3. 他に残っている過大な主張・空振りの余地がないか

全体判定 (APPROVED / CHANGES_REQUESTED) を明記してください。
