Round 1 の指摘 4 件をすべて対応した。対応マトリクスと修正差分を送る。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Warning] FakeWiringProbeRunner::decode() が JSON を fail-closed で読んでいない
- 判断: 対応する
- 根拠: 指摘のとおり。解釈できない出力を `raw_output` に詰めて返すと、後続の表明が
  別の理由で落ちて「観測が成立していない」という本当の原因が隠れる。
- 対応内容: 空出力 / JSON として読めない / 配列でない、の 3 つをすべて `RuntimeException`
  にした (`JSON_THROW_ON_ERROR` + 生の出力を例外メッセージへ添える)。

## [Warning] ProductionEnvGuardTest の非文字列ケースで原値の復元が漏れる
- 判断: 対応する
- 根拠: 指摘のとおり。`$had` が true のときに元の値へ戻さず、テスト用の配列を残していた
  (このリポジトリでは現状 3 経路とも未設定なので実害は出ていないが、
  手元の環境に値が残っている開発者では後続テストへ漏れる)。
- 対応内容: 非文字列ケースを `withRawEnvironmentValue()` へ寄せ、退避と復元の経路を 1 本にした
  (ヘルパの値の型を `mixed` に広げた)。

## [Suggestion] writeEnvFile() が書き込み・クローズの失敗を見ていない
- 判断: 対応する
- 根拠: 書き切れていない環境ファイルで子を起こすと「観測できたつもりで設定が欠けている」
  状態になり、緑のまま観測が無意味になる (本 gate が最も避けたい形)。
- 対応内容: `fwrite` の書き込みバイト数と `fclose` の戻り値を検査し、
  どちらか欠ければ子を起こさず例外にした。

## [Suggestion] S-10 が S-9 と同じ述語を通していない (負のコントロールとして弱い)
- 判断: 対応する
- 根拠: 指摘のとおり。別の式で確かめると gate 本体の退行を映さない。
- 対応内容: 前提テストの紐づけ判定を純関数 `bughuntSeedPremiseViolations()` へ切り出し、
  S-9 と S-10 が同じ述語を通るようにした。負のコントロールも 1 通りから 5 通りへ広げた
  (参照しない別テスト / 実在しないパス / tests/Feature の外 / 紐づけ無し /
  ガードを要求しない区分への紐づけ) + 正のコントロール 1 通り。

---

## 修正差分 (Round 1 の指摘に対する差分のみ)

```diff
diff --git a/tests/Architecture/BughuntSeedWiringInvariantTest.php b/tests/Architecture/BughuntSeedWiringInvariantTest.php
new file mode 100644
index 0000000..c0fe429
--- /dev/null
+++ b/tests/Architecture/BughuntSeedWiringInvariantTest.php
@@ -0,0 +1,448 @@
+<?php
+
+declare(strict_types=1);
+
+use Database\Seeders\BughuntOAuthSeeder;
+use Illuminate\Database\Seeder;
+use Tests\Support\Bughunt\BughuntSeedRole;
+use Tests\Support\Bughunt\BughuntSeedWiringInventory;
+use Tests\Support\Bughunt\ShellFunctionWindow;
+
+/*
+ * Architecture invariant: bug-hunt の投入データ (seeder) の配線が目録と一致すること。
+ *
+ * 偽の外部サービスの配線は「登録漏れは無音で本物が動く」ことを理由に deny-by-default の
+ * 実証 gate を持っているのに、**投入データ側は同じ理由が当てはまるのに検査が 1 つも無かった**。
+ * 起きうる無音の事故は 3 つ (BughuntSeedWiringInventory の docblock に列挙)。
+ *
+ * SoT:
+ *   - scripts/bug-hunt-shard.sh の cmd_provision / cmd_reseed (実際に流す列)
+ *   - database/seeders/ の各 seeder (環境ガードの実体)
+ *   - tests/Support/Bughunt/BughuntSeedWiringInventory.php (区分の目録)
+ *
+ * **保証範囲を誇張しない**: 見るのは静的な字面である。条件の論理 (かつ / または) は読めないため、
+ * ガードを要求する区分には振る舞いテストを目録から紐づける (S-9)。
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * database/seeders 配下の Seeder クラス一覧 (実在するものだけ)。
+ *
+ * @return list<class-string>
+ */
+function bughuntSeedDeclaredSeederClasses(): array
+{
+    $root = base_path('database/seeders');
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
+    );
+
+    $classes = [];
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+
+        $relative = substr($file->getPathname(), strlen($root) + 1, -strlen('.php'));
+        $class = 'Database\\Seeders\\'.str_replace('/', '\\', $relative);
+
+        if (class_exists($class) && is_subclass_of($class, Seeder::class)) {
+            $classes[] = $class;
+        }
+    }
+
+    sort($classes);
+
+    return $classes;
+}
+
+/** bug-hunt の shard スクリプト本体 (読み取り失敗は例外で落ちる) */
+function bughuntSeedShardSource(): string
+{
+    $source = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
+    if (! is_string($source) || $source === '') {
+        throw new RuntimeException('scripts/bug-hunt-shard.sh が読めない');
+    }
+
+    return $source;
+}
+
+/**
+ * シェル関数の窓から `db:seed --class=<名前>` の列を出現順に取り出す。
+ *
+ * @return list<string> クラスの短い名前 (出現順)
+ */
+function bughuntSeedClassSequence(string $window): array
+{
+    $matches = [];
+    preg_match_all('/db:seed\s+--class=([A-Za-z0-9_]+)/', $window, $matches);
+
+    /** @var array{0: list<string>, 1: list<string>} $matches */
+    return $matches[1];
+}
+
+/** クラスの短い名前 (FQCN の末尾セグメント) */
+function bughuntSeedShortName(string $class): string
+{
+    $position = strrpos($class, '\\');
+
+    return $position === false ? $class : substr($class, $position + 1);
+}
+
+/**
+ * `run()` の最初の実効文の形を返す。
+ *
+ * - `first`: 最初の実効トークンの字句 (`if` なら 'if')
+ * - `condition`: 最初の実効文が `if` のときの条件式の字句 (それ以外は空文字)
+ * - `body`: 同じく `if` の本体の字句 (それ以外は空文字)
+ *
+ * @return array{first: string, condition: string, body: string}
+ */
+function bughuntSeedRunGuardShape(string $source): array
+{
+    /** @var list<array{id: int, text: string}> $tokens */
+    $tokens = [];
+    foreach (token_get_all($source) as $token) {
+        if (is_array($token)) {
+            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+                continue;
+            }
+            $tokens[] = ['id' => $token[0], 'text' => $token[1]];
+
+            continue;
+        }
+        $tokens[] = ['id' => -1, 'text' => $token];
+    }
+
+    $count = count($tokens);
+    $signature = null;
+    for ($i = 0; $i + 1 < $count; $i++) {
+        if ($tokens[$i]['id'] === T_FUNCTION
+            && $tokens[$i + 1]['id'] === T_STRING
+            && $tokens[$i + 1]['text'] === 'run') {
+            $signature = $i;
+            break;
+        }
+    }
+
+    if ($signature === null) {
+        throw new RuntimeException('run() の宣言が見つからない');
+    }
+
+    // 引数リストを読み飛ばし、本体の `{` を見つける。
+    $bodyStart = null;
+    $depth = 0;
+    for ($i = $signature; $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if ($text === '(') {
+            $depth++;
+        } elseif ($text === ')') {
+            $depth--;
+        } elseif ($text === '{' && $depth === 0) {
+            $bodyStart = $i;
+            break;
+        }
+    }
+
+    if ($bodyStart === null) {
+        throw new RuntimeException('run() の本体が見つからない');
+    }
+
+    $first = $tokens[$bodyStart + 1] ?? null;
+    if ($first === null) {
+        throw new RuntimeException('run() の本体が空である');
+    }
+
+    if ($first['id'] !== T_IF) {
+        return ['first' => $first['text'], 'condition' => '', 'body' => ''];
+    }
+
+    // 条件式 (`if` の直後の括弧)
+    $condition = '';
+    $conditionEnd = null;
+    $depth = 0;
+    for ($i = $bodyStart + 2; $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if ($text === '(') {
+            $depth++;
+            if ($depth === 1) {
+                continue;
+            }
+        } elseif ($text === ')') {
+            $depth--;
+            if ($depth === 0) {
+                $conditionEnd = $i;
+                break;
+            }
+        }
+        $condition .= $text;
+    }
+
+    if ($conditionEnd === null) {
+        throw new RuntimeException('if の条件式を読み取れない');
+    }
+
+    // 本体 (`{ … }`)
+    $body = '';
+    $depth = 0;
+    for ($i = $conditionEnd + 1; $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if ($text === '{') {
+            $depth++;
+            if ($depth === 1) {
+                continue;
+            }
+        } elseif ($text === '}') {
+            $depth--;
+            if ($depth === 0) {
+                break;
+            }
+        }
+        $body .= $text.' ';
+    }
+
+    return ['first' => 'if', 'condition' => $condition, 'body' => $body];
+}
+
+/**
+ * 前提テストの紐づけの違反一覧 (純関数 = 負のコントロールが同じ述語を通せる)。
+ *
+ * @param  class-string  $class  対象の seeder
+ * @param  string|null  $premise  紐づけられた前提テストのパス (repo ルート相対)
+ * @param  bool  $guardRequired  区分が環境ガードを要求するか
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function bughuntSeedPremiseViolations(string $class, ?string $premise, bool $guardRequired): array
+{
+    if (! $guardRequired) {
+        return $premise === null ? [] : ["ガードを要求しない区分に前提テストが紐づいている: {$class}"];
+    }
+
+    if ($premise === null) {
+        return ["前提テストが紐づいていない: {$class}"];
+    }
+
+    $violations = [];
+
+    if (! str_starts_with($premise, 'tests/Feature/')) {
+        $violations[] = "前提テストは tests/Feature/ 配下であること: {$premise}";
+    }
+
+    $path = base_path($premise);
+    if (! is_file($path)) {
+        return [...$violations, "前提テストが実在しない: {$premise}"];
+    }
+
+    $source = file_get_contents($path);
+    if (! is_string($source)) {
+        return [...$violations, "前提テストを読めない: {$premise}"];
+    }
+
+    if (! str_contains($source, bughuntSeedShortName($class))) {
+        $violations[] = "前提テストが対象 seeder を参照していない: {$premise}";
+    }
+
+    return $violations;
+}
+
+test('S-1 目録のキー集合が database/seeders の Seeder クラス集合と過不足なく一致する', function (): void {
+    $declared = bughuntSeedDeclaredSeederClasses();
+    $registered = array_keys(BughuntSeedWiringInventory::entries());
+
+    // 走査が壊れて「空母集団で緑」になるのを防ぐ (fail-closed)
+    expect($declared)->not->toBeEmpty();
+
+    sort($registered);
+
+    expect($registered)->toBe($declared);
+});
+
+test('S-2 各 entry の理由が 30 文字以上である', function (): void {
+    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
+        expect(mb_strlen($entry['reason']))
+            ->toBeGreaterThanOrEqual(30, "理由が短すぎる: {$class}");
+    }
+});
+
+test('S-3 cmd_provision と cmd_reseed の投入列が順序込みで一致する', function (): void {
+    $source = bughuntSeedShardSource();
+
+    $provision = bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_provision'));
+    $reseed = bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_reseed'));
+
+    // ★順序にも意味がある (ManualTestSeeder が先に走らないと BughuntOAuthSeeder は
+    //   代表ユーザーを見つけられず skip する)。並べ替えたいときは 2 か所を同時に直すこと。
+    expect($provision)->not->toBeEmpty()
+        ->and($reseed)->toBe($provision);
+});
+
+test('S-4 投入列の集合が目録の「bug-hunt で明示投入する」区分と過不足なく一致する', function (): void {
+    $sequence = bughuntSeedClassSequence(
+        ShellFunctionWindow::ofCommand(bughuntSeedShardSource(), 'cmd_provision')
+    );
+
+    $expected = array_map(
+        bughuntSeedShortName(...),
+        BughuntSeedWiringInventory::seededInBughunt()
+    );
+
+    $actual = array_values(array_unique($sequence));
+    sort($actual);
+    sort($expected);
+
+    expect($expected)->not->toBeEmpty()
+        ->and($actual)->toBe($expected);
+});
+
+test('S-5 BughuntOnly 区分は DatabaseSeeder の呼び出し列に現れない', function (): void {
+    $source = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));
+    expect($source)->toBeString();
+    /** @var string $source */
+    $bughuntOnly = [];
+    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
+        if ($entry['role'] === BughuntSeedRole::BughuntOnly) {
+            $bughuntOnly[] = bughuntSeedShortName($class);
+        }
+    }
+
+    expect($bughuntOnly)->not->toBeEmpty();
+
+    foreach ($bughuntOnly as $name) {
+        // ★見るのは字面である (DatabaseSeeder のソースに名前が現れないこと)。
+        //   通常経路へ載せる書き方は必ずクラス名を書くため、これで 3 つ目の事故を止められる。
+        expect($source)->not->toContain($name);
+    }
+});
+
+test('S-6 ガードを要求する区分は run() の最初の実効文が if で、判定語と早期 return を持つ', function (): void {
+    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
+        $markers = BughuntSeedWiringInventory::requiredGuardMarkers($entry['role']);
+        if ($markers === []) {
+            continue;
+        }
+
+        $file = (new ReflectionClass($class))->getFileName();
+        expect($file)->toBeString();
+        /** @var string $file */
+        $source = file_get_contents($file);
+        expect($source)->toBeString();
+        /** @var string $source */
+        $shape = bughuntSeedRunGuardShape($source);
+
+        expect($shape['first'])->toBe('if', "{$class} の run() の最初の実効文が if でない");
+
+        foreach ($markers as $marker) {
+            // ★`toContain()` は可変長の needle を取るため、失敗メッセージは
+            //   `toBeTrue()` 側へ渡す (第 2 引数を message と誤用しない)。
+            expect(str_contains($shape['condition'], $marker))
+                ->toBeTrue("{$class} のガード条件に {$marker} が無い");
+        }
+
+        expect(str_contains($shape['body'], 'return'))
+            ->toBeTrue("{$class} のガードに早期 return が無い");
+    }
+});
+
+test('S-7 fail-closed: 投入列も目録も空でないこと', function (): void {
+    $source = bughuntSeedShardSource();
+
+    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_provision')))->not->toBeEmpty()
+        ->and(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($source, 'cmd_reseed')))->not->toBeEmpty()
+        ->and(BughuntSeedWiringInventory::entries())->not->toBeEmpty()
+        ->and(BughuntSeedWiringInventory::seededInBughunt())->not->toBeEmpty();
+});
+
+test('S-8 負のコントロール: 投入列の欠落 / 並べ替え / ガードの後退を検出する', function (): void {
+    // (a) reseed から 1 行落とす
+    $dropped = <<<'SH'
+    cmd_provision() {
+        artisan_for_shard db:seed --class=ManualTestSeeder --force
+        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
+    }
+    cmd_reseed() {
+        artisan_for_shard db:seed --class=ManualTestSeeder --force
+    }
+    SH;
+    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($dropped, 'cmd_reseed')))
+        ->not->toBe(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($dropped, 'cmd_provision')));
+
+    // (b) 並びを入れ替える
+    $reordered = <<<'SH'
+    cmd_provision() {
+        artisan_for_shard db:seed --class=ManualTestSeeder --force
+        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
+    }
+    cmd_reseed() {
+        artisan_for_shard db:seed --class=BughuntOAuthSeeder --force
+        artisan_for_shard db:seed --class=ManualTestSeeder --force
+    }
+    SH;
+    expect(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($reordered, 'cmd_reseed')))
+        ->not->toBe(bughuntSeedClassSequence(ShellFunctionWindow::ofCommand($reordered, 'cmd_provision')));
+
+    // (c) ガードの前に 1 文入れる
+    $beforeGuard = "<?php\nclass X {\n public function run(): void {\n"
+        ."  \$this->command->info('start');\n"
+        ."  if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) { return; }\n"
+        ." }\n}\n";
+    expect(bughuntSeedRunGuardShape($beforeGuard)['first'])->not->toBe('if');
+
+    // (d) ガードの中に早期 return が無い
+    $noReturn = "<?php\nclass X {\n public function run(): void {\n"
+        ."  if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) !== true) { \$this->command->warn('skip'); }\n"
+        ." }\n}\n";
+    $shape = bughuntSeedRunGuardShape($noReturn);
+    expect($shape['first'])->toBe('if')
+        ->and($shape['body'])->not->toContain('return');
+
+    // (e) 正のコントロール: 判定語を落とすと条件照合が落ちる
+    expect($shape['condition'])->not->toContain('isBughuntDatabase');
+});
+
+test('S-9 ガードを要求する区分は対象 seeder を参照する前提テストを持つ', function (): void {
+    foreach (BughuntSeedWiringInventory::entries() as $class => $entry) {
+        $guardRequired = BughuntSeedWiringInventory::requiredGuardMarkers($entry['role']) !== [];
+
+        expect(bughuntSeedPremiseViolations($class, $entry['guardPremiseTest'], $guardRequired))
+            ->toBe([], "前提テストの紐づけが不正: {$class}");
+    }
+});
+
+test('S-10 負のコントロール: 前提テストの差し替え・不在・区分違いを検出する', function (): void {
+    // ★S-9 と**同じ述語**を通す (別の式で確かめると gate 本体の退行を映さない)。
+    $class = BughuntOAuthSeeder::class;
+
+    // (a) 実在するが対象 seeder を参照しない別のテストへ差し替える
+    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/ManualTestSeederTest.php', true))
+        ->not->toBe([]);
+
+    // (b) 実在しないパスへ差し替える
+    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/DoesNotExistTest.php', true))
+        ->not->toBe([]);
+
+    // (c) tests/Feature/ の外へ差し替える
+    expect(bughuntSeedPremiseViolations($class, 'tests/Architecture/BughuntSeedWiringInvariantTest.php', true))
+        ->not->toBe([]);
+
+    // (d) ガードを要求する区分なのに紐づけを外す
+    expect(bughuntSeedPremiseViolations($class, null, true))->not->toBe([]);
+
+    // (e) ガードを要求しない区分に紐づけを足す
+    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php', false))
+        ->not->toBe([]);
+
+    // (f) 正のコントロール: 正しい紐づけは違反 0 件 (同じ述語であることの確認)
+    expect(bughuntSeedPremiseViolations($class, 'tests/Feature/Database/BughuntOAuthSeederGuardTest.php', true))
+        ->toBe([]);
+});
+
+test('S-11 ShellFunctionWindow は cmd_ 以外の名前と不在を例外にする', function (): void {
+    $source = bughuntSeedShardSource();
+
+    expect(fn (): string => ShellFunctionWindow::ofCommand($source, 'require_orchestrator'))
+        ->toThrow(InvalidArgumentException::class);
+
+    expect(fn (): string => ShellFunctionWindow::ofCommand($source, 'cmd_does_not_exist'))
+        ->toThrow(RuntimeException::class);
+});
diff --git a/tests/Feature/Support/ProductionEnvGuardTest.php b/tests/Feature/Support/ProductionEnvGuardTest.php
index c5b0d6c..4ba19de 100644
--- a/tests/Feature/Support/ProductionEnvGuardTest.php
+++ b/tests/Feature/Support/ProductionEnvGuardTest.php
@@ -334,3 +334,130 @@
     expect($errors)->toHaveCount(1);
     expect($errors[0])->toContain('must be lists of strings');
 });
+
+/*
+ * 実環境変数の二重判定 (T177 施策 3)。
+ *
+ * 設定キャッシュを作った環境と出荷先が食い違うと、キャッシュ上は false でも、
+ * キャッシュが失われた起動で環境変数が読み直されて本番で偽物が立ちうる。
+ * そこで設定値とは独立に $_SERVER / $_ENV / getenv() の 3 経路を見る。
+ *
+ * ★原値の退避と復元は下のヘルパへ集約し、すべてのケースが try/finally で戻す
+ *   (putenv は空文字と未設定の差が環境で揺れるため、$_SERVER / $_ENV 側は
+ *    unset() と = '' を明示的に作り分ける)。
+ */
+
+/**
+ * 3 経路の原値を退避し、callback 実行後に必ず復元する。
+ *
+ * `$_SERVER` / `$_ENV` は mixed を持ちうるので値の型を絞らない
+ * (非文字列を入れるケースも同じ復元経路に乗せる = 復元漏れを作らない)。
+ *
+ * @param  array{server?: mixed, env?: mixed, putenv?: string}  $values  設定する経路と値
+ */
+function withRawEnvironmentValue(string $variable, array $values, Closure $callback): void
+{
+    $hadServer = array_key_exists($variable, $_SERVER);
+    $hadEnv = array_key_exists($variable, $_ENV);
+    $originalServer = $_SERVER[$variable] ?? null;
+    $originalEnv = $_ENV[$variable] ?? null;
+    $originalPutenv = getenv($variable);
+
+    try {
+        if (array_key_exists('server', $values)) {
+            $_SERVER[$variable] = $values['server'];
+        }
+        if (array_key_exists('env', $values)) {
+            $_ENV[$variable] = $values['env'];
+        }
+        if (array_key_exists('putenv', $values)) {
+            putenv("{$variable}={$values['putenv']}");
+        }
+
+        $callback();
+    } finally {
+        if ($hadServer) {
+            $_SERVER[$variable] = $originalServer;
+        } else {
+            unset($_SERVER[$variable]);
+        }
+
+        if ($hadEnv) {
+            $_ENV[$variable] = $originalEnv;
+        } else {
+            unset($_ENV[$variable]);
+        }
+
+        if ($originalPutenv === false) {
+            putenv($variable);
+        } else {
+            putenv("{$variable}={$originalPutenv}");
+        }
+    }
+}
+
+test('config が false でも $_SERVER に true が残っていれば violation', function (): void {
+    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => 'true'], function (): void {
+        $errors = (new ProductionEnvGuard)->violations();
+        expect($errors)->toHaveCount(1);
+        expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
+        expect($errors[0])->toContain('$_SERVER');
+    });
+});
+
+test('config が false でも $_ENV に true が残っていれば violation', function (): void {
+    withRawEnvironmentValue('TESTING_FAKE_LLM', ['env' => 'true'], function (): void {
+        $errors = (new ProductionEnvGuard)->violations();
+        expect($errors)->toHaveCount(1);
+        expect($errors[0])->toContain('$_ENV');
+    });
+});
+
+test('config が false でも getenv() に true が残っていれば violation', function (): void {
+    withRawEnvironmentValue('TESTING_FAKE_STORAGE', ['putenv' => 'true'], function (): void {
+        $errors = (new ProductionEnvGuard)->violations();
+        expect($errors)->toHaveCount(1);
+        expect($errors[0])->toContain('getenv()');
+    });
+});
+
+test('3 経路とも未設定なら violation は出ない', function (): void {
+    expect((new ProductionEnvGuard)->violations())->toBe([]);
+});
+
+test('無効と読める値 (false / 0 / 空文字) では violation は出ない', function (): void {
+    foreach (['false', 'FALSE', '(false)', '0', 'off', 'no', 'null', ''] as $value) {
+        withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => $value], function () use ($value): void {
+            expect((new ProductionEnvGuard)->violations())->toBe([], "無効と読めるはずの値: '{$value}'");
+        });
+    }
+});
+
+test('解釈できない値 (maybe / 非文字列) は安全側で violation', function (): void {
+    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => 'maybe'], function (): void {
+        expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
+    });
+
+    // 非文字列 (配列) も黙って捨てず違反にする。
+    // ★退避と復元は同じヘルパに乗せる (原値があった場合の戻し漏れを作らない)。
+    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', ['server' => ['true']], function (): void {
+        expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
+    });
+});
+
+test('未設定 / 空文字 / false を別ケースとして固定する', function (): void {
+    $variable = 'TESTING_FAKE_STORAGE';
+
+    // 未設定: 判定対象にしない
+    expect((new ProductionEnvGuard)->violations())->toBe([]);
+
+    // 空文字: 無効と読む
+    withRawEnvironmentValue($variable, ['server' => ''], function (): void {
+        expect((new ProductionEnvGuard)->violations())->toBe([]);
+    });
+
+    // 'false': 無効と読む
+    withRawEnvironmentValue($variable, ['server' => 'false'], function (): void {
+        expect((new ProductionEnvGuard)->violations())->toBe([]);
+    });
+});
diff --git a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
new file mode 100644
index 0000000..7002bdf
--- /dev/null
+++ b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
@@ -0,0 +1,301 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\ExternalFakes;
+
+use JsonException;
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
+ *
+ * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
+ * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
+ *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
+ *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
+ * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
+ *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
+ *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
+ *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
+ *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
+ * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
+ *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
+ *    並列実行と衝突しない)
+ *
+ * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
+ *   **使い捨ての値をその場で生成する** (観測は解決と経路の組み立てだけで、既存データの
+ *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
+ * ★それでも置き場所は保護する: 専用の一時ディレクトリを 0700 で作り、環境ファイルは
+ *   作成時点から 0600 にする。起動前に権限を確かめ、0600 でなければ**子を起こさずに失敗させる**。
+ *   後片付けは finally で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
+ *
+ * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
+ * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
+ * 本番混入防止は ProductionEnvGuard の二重判定が受け持つ)。
+ */
+final class FakeWiringProbeRunner
+{
+    /**
+     * 一時環境ファイルに書いてよいキー (deny-by-default)。
+     * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_ENV_FILE_KEYS = [
+        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
+        'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
+    ];
+
+    /**
+     * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
+     * `env -i` で空にしたうえでこの 3 つだけを載せる。
+     *
+     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
+     *   probe が自分で観測して返す。両方を突き合わせて初めて `env -i` の退行が映る。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_PROCESS_ENV_KEYS = [
+        'FAKE_WIRING_PROBE_ENV_DIR',
+        'FAKE_WIRING_PROBE_ENV_FILE',
+        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
+        'APP_CONFIG_CACHE',
+    ];
+
+    /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
+    private const string PROBE_APP_URL = 'http://127.0.0.1:65535';
+
+    /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
+    private const string ENV_FILE_NAME = '.env.probe';
+
+    /**
+     * 観測を 1 回走らせる。
+     *
+     * @param  string|null  $baseDirectory  一時ディレクトリを作る親 (省略時は sys_get_temp_dir())
+     * @return array{
+     *     exitCode: int,
+     *     output: array<string, mixed>,
+     *     envFileValues: array<string, string>,
+     *     directory: string,
+     *     directoryMode: int,
+     *     envFileMode: int,
+     *     configCachePath: string,
+     *     configCacheExists: bool,
+     * }
+     */
+    public static function run(
+        string $environment,
+        bool $fakeExternals,
+        bool $fakeStorage,
+        bool $fakeLlm,
+        ?string $baseDirectory = null,
+        float $timeout = 120.0,
+    ): array {
+        $base = $baseDirectory ?? sys_get_temp_dir();
+        $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($directory, 0700) || ! is_dir($directory)) {
+            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
+        }
+
+        try {
+            chmod($directory, 0700);
+
+            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
+            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
+            self::writeEnvFile($envFilePath, $values);
+
+            $directoryMode = self::mode($directory);
+            $envFileMode = self::mode($envFilePath);
+
+            // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
+            self::assertSafePermissions($directoryMode, $envFileMode);
+
+            $configCachePath = $directory.'/config-cache-absent.php';
+
+            $process = new Process(
+                [
+                    'env', '-i',
+                    'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
+                    'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
+                    'APP_CONFIG_CACHE='.$configCachePath,
+                    PHP_BINARY,
+                    self::probeScriptPath(),
+                ],
+                FakeClassCatalog::repoRoot(),
+                null,
+                null,
+                $timeout,
+            );
+            $process->run();
+
+            return [
+                'exitCode' => $process->getExitCode() ?? -1,
+                'output' => self::decode($process->getOutput()),
+                'envFileValues' => $values,
+                'directory' => $directory,
+                'directoryMode' => $directoryMode,
+                'envFileMode' => $envFileMode,
+                'configCachePath' => $configCachePath,
+                'configCacheExists' => file_exists($configCachePath),
+            ];
+        } finally {
+            self::removeDirectory($directory);
+        }
+    }
+
+    /**
+     * 一時環境ファイルへ書く内容 (許可キー以外は 1 つも作らない)。
+     *
+     * @return array<string, string>
+     */
+    public static function envFileValues(
+        string $environment,
+        bool $fakeExternals,
+        bool $fakeStorage,
+        bool $fakeLlm,
+    ): array {
+        // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
+        // 形式は現行の設定が受理する形に合わせる (妥当性は「子が起動できたこと」自体が示す)。
+        $values = [
+            'APP_ENV' => $environment,
+            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
+            'APP_URL' => self::PROBE_APP_URL,
+            'APP_DEBUG' => 'false',
+            'CIPHERSWEET_KEY' => bin2hex(random_bytes(32)),
+            'TESTING_FAKE_EXTERNALS' => $fakeExternals ? 'true' : 'false',
+            'TESTING_FAKE_STORAGE' => $fakeStorage ? 'true' : 'false',
+            'TESTING_FAKE_LLM' => $fakeLlm ? 'true' : 'false',
+        ];
+
+        foreach (array_keys($values) as $key) {
+            if (! in_array($key, self::ALLOWED_ENV_FILE_KEYS, true)) {
+                throw new RuntimeException("一時環境ファイルへ書けないキー: {$key}");
+            }
+        }
+
+        return $values;
+    }
+
+    /**
+     * 一時ディレクトリ 0700 / 環境ファイル 0600 でなければ例外にする (子を起こさない)。
+     */
+    public static function assertSafePermissions(int $directoryMode, int $envFileMode): void
+    {
+        if ($directoryMode !== 0700 || $envFileMode !== 0600) {
+            throw new RuntimeException(
+                '観測用の一時ファイルの権限が想定と違うため子プロセスを起こさない ('
+                .sprintf('dir=%04o file=%04o', $directoryMode, $envFileMode).')'
+            );
+        }
+    }
+
+    /** 観測用スクリプトの絶対パス */
+    public static function probeScriptPath(): string
+    {
+        return __DIR__.'/fake-wiring-probe.php';
+    }
+
+    /** 観測が組み立てる自ホストの host 部 (転送先の照合に使う) */
+    public static function probeAppHost(): string
+    {
+        $host = parse_url(self::PROBE_APP_URL, PHP_URL_HOST);
+        if (! is_string($host) || $host === '') {
+            throw new RuntimeException('観測用 APP_URL から host を取り出せない');
+        }
+
+        return $host;
+    }
+
+    /**
+     * @param  array<string, string>  $values
+     */
+    private static function writeEnvFile(string $path, array $values): void
+    {
+        // 'x' は既存ファイルがあれば失敗する (乗っ取られた置き場所へ書き足さない)。
+        $handle = fopen($path, 'x');
+        if ($handle === false) {
+            throw new RuntimeException("観測用の環境ファイルを作れない: {$path}");
+        }
+
+        // 中身を書く**前に**権限を絞る。
+        chmod($path, 0600);
+
+        $lines = '';
+        foreach ($values as $key => $value) {
+            $lines .= $key.'='.$value."\n";
+        }
+
+        // 書き切れなかった / 閉じられなかった環境ファイルで子を起こすと、
+        // 「観測できたつもりで設定が欠けている」状態になる。fail-closed で止める。
+        $written = fwrite($handle, $lines);
+        $closed = fclose($handle);
+
+        if ($written !== strlen($lines) || $closed === false) {
+            throw new RuntimeException("観測用の環境ファイルを書き切れなかった: {$path}");
+        }
+    }
+
+    private static function mode(string $path): int
+    {
+        clearstatcache(true, $path);
+        $permissions = fileperms($path);
+
+        return $permissions === false ? -1 : ($permissions & 0777);
+    }
+
+    /**
+     * 子の出力を読む。**解釈できない出力は黙って通さず例外にする** (fail-closed)。
+     *
+     * 出力が空・JSON でない・配列でない、のいずれも「観測が成立していない」ことを意味する。
+     * 中身を `raw_output` に詰めて返すと、後続の表明が別の理由で落ちて原因が隠れる。
+     *
+     * @return array<string, mixed>
+     */
+    private static function decode(string $output): array
+    {
+        if (trim($output) === '') {
+            throw new RuntimeException('観測用スクリプトが何も出力しなかった (観測が成立していない)');
+        }
+
+        try {
+            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
+        } catch (JsonException $e) {
+            throw new RuntimeException(
+                '観測用スクリプトの出力を JSON として読めない: '.$e->getMessage()."\n出力: ".$output,
+                previous: $e
+            );
+        }
+
+        if (! is_array($decoded)) {
+            throw new RuntimeException('観測用スクリプトの出力が配列ではない。出力: '.$output);
+        }
+
+        /** @var array<string, mixed> $decoded */
+        return $decoded;
+    }
+
+    private static function removeDirectory(string $directory): void
+    {
+        if (! is_dir($directory)) {
+            return;
+        }
+
+        foreach (scandir($directory) ?: [] as $entry) {
+            if ($entry === '.' || $entry === '..') {
+                continue;
+            }
+            $path = $directory.'/'.$entry;
+            if (is_dir($path)) {
+                self::removeDirectory($path);
+
+                continue;
+            }
+            unlink($path);
+        }
+
+        rmdir($directory);
+    }
+}
```

---

## 修正後のテスト結果

- `composer test -- --filter='BughuntSeedWiring|ExternalFakeBootProbe|ProductionEnvGuard'`: 71 passed / 0 failed
- `vendor/bin/pint --test`: passed
- (この後に全体の `composer test` と `composer phpstan` を再実行する)

残る指摘があれば挙げてほしい。無ければ全体判定を APPROVED で返してほしい。
