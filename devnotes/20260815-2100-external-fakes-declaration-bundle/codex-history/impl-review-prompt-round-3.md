Round 2 の指摘 1 件を対応した。対応マトリクスと修正差分を送る。

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Warning] withRawEnvironmentValue() が指定しなかった経路を未設定化していない
- 判断: 対応する
- 根拠: 指摘のとおり。3 経路のうち 1 つだけを設定しても、実行環境に同じ変数が残っていれば
  違反が 2 件以上になり `toHaveCount(1)` が落ちる = テストがホスト環境依存になる。
  「3 経路とも未設定」のケースに至っては、未設定状態を構築しないまま名前だけ主張していた。
  さらに、二重判定が入ったことで**本ファイルのほぼ全ケース**が同じ依存を持つようになっていた
  (baseline の「violations は空」も、手元シェルに TESTING_FAKE_* があれば落ちる)。
- 対応内容: 2 段で直した。
  1. `withRawEnvironmentValue()` が**指定されなかった経路をテスト中だけ未設定化**し、
     `finally` で 3 経路とも原状復帰する。
  2. ファイル全体の前提として `beforeEach` で**対象 3 変数 × 3 経路をすべて未設定にし**、
     `afterEach` で戻す (退避先は Pest の TestCase へ動的プロパティを生やさないよう
     ファイル内の関数の static に置いた)。対象変数の一覧は
     `ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES` から導く (写経しない)。
  「3 経路とも未設定なら violation は出ない」は、どの経路も指定しない形で
  `withRawEnvironmentValue()` を通し、未設定が判定対象にならないことを明示的に固定する形にした。
- 実測: 汚染された環境 (`TESTING_FAKE_STORAGE=true TESTING_FAKE_EXTERNALS=true`) で
  `composer test -- --filter='ProductionEnvGuard'` を走らせ 48 passed を確認した
  (修正前はこの条件で落ちる形だった)。

---

## 修正差分

```diff
diff --git a/tests/Feature/Support/ProductionEnvGuardTest.php b/tests/Feature/Support/ProductionEnvGuardTest.php
index c5b0d6c..27a80cd 100644
--- a/tests/Feature/Support/ProductionEnvGuardTest.php
+++ b/tests/Feature/Support/ProductionEnvGuardTest.php
@@ -2,10 +2,17 @@
 
 declare(strict_types=1);
 
+use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use App\Support\ProductionEnvGuard;
 use Laravel\Fortify\Features;
 
 beforeEach(function (): void {
+    // ★実環境変数の二重判定 (T177) が入ったため、**テストの前提として 3 変数 × 3 経路を
+    //   すべて未設定にする**。開発者の手元シェルや実行基盤に TESTING_FAKE_* が残っていると、
+    //   本ファイルのほぼ全ケースが余分な violation で落ちる (ホスト環境依存になる)。
+    //   原状復帰は afterEach が行う。
+    productionEnvGuardIsolateRawEnvironment();
+
     // production 必須項目の baseline (すべて有効値)。各テストで 1 項目ずつ崩す。
     config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
     config(['ciphersweet.providers.string.key' => str_repeat('a', 64)]);
@@ -33,6 +40,10 @@
     config(['fortify.passkeys.user_handle_secret_declared' => true]);
 });
 
+afterEach(function (): void {
+    productionEnvGuardRestoreRawEnvironment();
+});
+
 test('全 production 必須項目が埋まっていれば violations は空', function (): void {
     expect((new ProductionEnvGuard)->violations())->toBe([]);
 });
@@ -334,3 +345,243 @@
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
+ * ★**指定しなかった経路はテスト中だけ明示的に未設定化する**。実行環境に同じ変数が
+ *   残っていると「経路ごとに独立に検査する」という前提が崩れ、違反件数がホスト依存になる。
+ */
+
+/**
+ * 二重判定の対象になる環境変数 (宣言が正本)。
+ *
+ * @return list<string>
+ */
+function productionEnvGuardFakeFlagVariables(): array
+{
+    return array_values(ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES);
+}
+
+/**
+ * 3 経路の原値を退避する。
+ *
+ * @return array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}
+ */
+function productionEnvGuardCaptureRaw(string $variable): array
+{
+    return [
+        'hadServer' => array_key_exists($variable, $_SERVER),
+        'server' => $_SERVER[$variable] ?? null,
+        'hadEnv' => array_key_exists($variable, $_ENV),
+        'env' => $_ENV[$variable] ?? null,
+        'putenv' => getenv($variable),
+    ];
+}
+
+/**
+ * 退避した原値へ 3 経路を戻す。
+ *
+ * @param  array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}  $state
+ */
+function productionEnvGuardRestoreRaw(string $variable, array $state): void
+{
+    if ($state['hadServer']) {
+        $_SERVER[$variable] = $state['server'];
+    } else {
+        unset($_SERVER[$variable]);
+    }
+
+    if ($state['hadEnv']) {
+        $_ENV[$variable] = $state['env'];
+    } else {
+        unset($_ENV[$variable]);
+    }
+
+    if ($state['putenv'] === false) {
+        putenv($variable);
+    } else {
+        putenv("{$variable}={$state['putenv']}");
+    }
+}
+
+/** 3 経路をすべて未設定にする */
+function productionEnvGuardClearRaw(string $variable): void
+{
+    unset($_SERVER[$variable], $_ENV[$variable]);
+    putenv($variable);
+}
+
+/**
+ * ケース間で共有する退避先 (Pest の TestCase へ動的プロパティを生やさない)。
+ *
+ * @param  array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}>|null  $set
+ * @return array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}>
+ */
+function productionEnvGuardRawSnapshot(?array $set = null): array
+{
+    /** @var array<string, array{hadServer: bool, server: mixed, hadEnv: bool, env: mixed, putenv: string|false}> $snapshot */
+    static $snapshot = [];
+
+    if ($set !== null) {
+        $snapshot = $set;
+    }
+
+    return $snapshot;
+}
+
+/** テストの前提として対象変数の 3 経路をすべて未設定にする (原値は退避する) */
+function productionEnvGuardIsolateRawEnvironment(): void
+{
+    $snapshot = [];
+    foreach (productionEnvGuardFakeFlagVariables() as $variable) {
+        $snapshot[$variable] = productionEnvGuardCaptureRaw($variable);
+        productionEnvGuardClearRaw($variable);
+    }
+
+    productionEnvGuardRawSnapshot($snapshot);
+}
+
+/** 退避しておいた原値へ戻す */
+function productionEnvGuardRestoreRawEnvironment(): void
+{
+    foreach (productionEnvGuardRawSnapshot() as $variable => $state) {
+        productionEnvGuardRestoreRaw($variable, $state);
+    }
+}
+
+/**
+ * 指定した経路にだけ値を置き、**それ以外の経路は未設定にした状態で** callback を実行する。
+ *
+ * `$_SERVER` / `$_ENV` は mixed を持ちうるので値の型を絞らない
+ * (非文字列を入れるケースも同じ復元経路に乗せる = 復元漏れを作らない)。
+ *
+ * @param  array{server?: mixed, env?: mixed, putenv?: string}  $values  設定する経路と値
+ */
+function withRawEnvironmentValue(string $variable, array $values, Closure $callback): void
+{
+    $state = productionEnvGuardCaptureRaw($variable);
+    $hadServer = $state['hadServer'];
+    $hadEnv = $state['hadEnv'];
+    $originalServer = $state['server'];
+    $originalEnv = $state['env'];
+    $originalPutenv = $state['putenv'];
+
+    try {
+        // 指定されなかった経路は未設定にする (経路ごとの独立検査の前提を作る)。
+        if (array_key_exists('server', $values)) {
+            $_SERVER[$variable] = $values['server'];
+        } else {
+            unset($_SERVER[$variable]);
+        }
+
+        if (array_key_exists('env', $values)) {
+            $_ENV[$variable] = $values['env'];
+        } else {
+            unset($_ENV[$variable]);
+        }
+
+        if (array_key_exists('putenv', $values)) {
+            putenv("{$variable}={$values['putenv']}");
+        } else {
+            putenv($variable);
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
+    // beforeEach が 3 変数 × 3 経路を未設定にしている。ここでは明示的に 1 変数を
+    // 「どの経路も指定しない」形で通し、未設定が判定対象にならないことを固定する。
+    withRawEnvironmentValue('TESTING_FAKE_EXTERNALS', [], function (): void {
+        expect((new ProductionEnvGuard)->violations())->toBe([]);
+    });
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
```

---

## 修正後のテスト結果

- `composer test -- --filter='ProductionEnvGuard'`: 48 passed / 0 failed
- 汚染環境での再走行 (`TESTING_FAKE_STORAGE=true TESTING_FAKE_EXTERNALS=true`): 48 passed / 0 failed
- `vendor/bin/pint --test`: passed

残る指摘があれば挙げてほしい。無ければ全体判定を APPROVED で返してほしい。
