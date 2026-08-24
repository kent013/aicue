# impl-review Round 3

Round 2 の 2 件の [Critical] と 1 件の [Suggestion] に対応した。
対応マトリクスの全文は `codex-history/impl-review-decisions-round-2.md`。

---

## [Critical] A. 全体テストの「2 回連続 green」 → **達成した**

上記のすべての修正を当てた**最終コード**で回し直した:

| 走行 | 結果 |
|---|---|
| final run 1 | **6500 tests / 6498 passed / 0 failed / 2 skipped / 5 risky** (598.3 秒) |
| final run 2 | **6500 tests / 6498 passed / 0 failed / 2 skipped / 5 risky** (602.6 秒) |

**連続 2 回とも green。** 間に赤は挟まっていない (連続が切れたら数え直す作りで回した)。

## [Critical] B. 自己検査 S9 / S10 がリポジトリの `.env` を読む → **実測して事実を確定し、封じ込めを機械化した**

### B-1. まず実測した (議論ではなくデータで詰めた)

S9 / S10 と同じ形の子を起こし、**秘密の値そのものは出さずに「非空かどうかと長さ」だけ**を報告させた:

| 設定キー | 子での状態 |
|---|---|
| `app.env` | 非空 (5 文字 = `local`) |
| `services.stripe.secret` | `null` |
| `cashier.secret` | 空 |
| `filesystems.disks.s3.secret` | 空 |
| `services.google.client_secret` | 空 |
| `mail.mailers.smtp.password` | `null` |
| **`database.connections.pgsql.password`** | **非空 (8 文字)** |
| **`ciphersweet.providers.string.key`** | **非空 (64 文字)** |
| 読んだ環境ファイル | **`.env`** |

**あなたが正しい。** 子はリポジトリの `.env` を読む。本チェックアウトでは外部サービスの資格情報は
たまたま空だったが、**DB のパスワードと実 `CIPHERSWEET_KEY` は子の設定に載った**。
**「空だった」のはこのチェックアウトの性質であって保証ではない** — この点は誇張せずに書いた。

併せて**非対称の機序**も確定した: 同一プロセスのテストが安全なのは `phpunit.xml` が
`<server name="STRIPE_SECRET" value="" force="true"/>` 等で秘密を強制的に無害化しているからで、
**`<server force>` は PHPUnit プロセスにしか効かず `proc_open` の子には及ばない**。

### B-2. それでも T249 で「除去」はできない

当該検体は**バイト一致で取り込んだ共有ファイルの中**にある。書き換えれば意図的逸脱の登録
(`LedgerPins::DIVERGENCE_ENTRY_COUNT` の更新を伴う) が必要になり、
T249 の受入条件「取り込み 3 本を編集しない」と正面から衝突する。

### B-3. そこで「別の構造的境界」を機械化した (= あなたの要求への回答)

除去できない以上、**この危険面が申告なしに増えないこと**を固定する方向で応じた。
軸 B の全数申告へ `boots_repository_env` を足し、**G-8** を新設した:

1. `true` の集合が `['tests/Unit/Support/Process/BootProbeRunnerTest.php']` と**完全一致**
   (増減のどちらでも赤)
2. `true` を申告してよいのは `tests/Unit/Support/Process/` 配下 =
   **バイト一致で取り込んだ共有ファイルだけ**。
   **aicue が自分で書いたファイルには `true` を申告できない** (言い訳が無いため)
3. `child_entry` 以外 (`in_process` / `inventory`) は必ず `false`

G-8 の docblock には、上の実測値・`<server force>` が子に及ばない機序・
`fake-wiring-probe.php` が専用環境ファイルで回避している対比・
**上流 (正典 v1) で解消して再取り込みすれば本 pin の `true` は 0 件になる**ことを逐語で書いた。

**これは「隠す」ためではなく「見えるところに置いて広がらないようにする」ための目録である。**
除去そのものは上流の議題として残る (本セッションは台帳への書き込みを禁じられているため起票はしない)。

**判定を仰ぎたい点**: 「取り込みは正典 v1 追従として受け入れ、危険面は G-8 で封じ込め、
除去は上流の議題」で妥当か。妥当でないなら、T249 の受入条件
(取り込み 3 本を編集しない) と衝突するため**この TODO は完了できない**という結論になる。
その場合は「T249 は保留し、先に正典側の修正を起こすべき」と明言してほしい。

## [Suggestion] C. 正規化判定の負例を恒久テストに → **対応した**

述語を純関数 `externalFakeProbeIsNormalizedAbsolutePath()` へ切り出し、
**P-16 として恒久のデータ駆動テスト 14 例**を置いた
(あなたの指摘どおり、helper を空実装にしても P-11 / P-14 は緑のままだった = 検出力が未確認だった):

- 正例 3 (実データと同じ形)
- 負例 `..` 3 形 / `.` 2 形 / 相対パス 3 形
- **紛らわしいが正当な 3 形** (`..hidden` / `.hidden` / `a..b`) —
  素の部分文字列で書いていたら誤って弾いていた形を正例として固定

## 性能測定の結論 (助言どおり書き換えた)

「最小値どうしの比較は事後的で偏りやすい」を受け入れ、結論を
**「(c) = 12.4 秒 (ばらつき 0.5 秒) は安定して測れている / 全体比較は環境の雑音により判定不能」**
までに留めた。**閾値は動かしていない。**

## 最終の検証結果

```
composer test          : 6500 tests / 6498 passed / 0 failed / 2 skipped  (2 回連続 green)
composer phpstan       : [OK] No errors
vendor/bin/pint --test : passed
pnpm lint / typecheck / build             : exit 0
pnpm test / test:packages                 : exit 0
pnpm typecheck:packages / build:packages  : exit 0

対象 4 ファイルのみ: 95 tests / 95 passed
  (BootProbeRunnerTest 14 / ExternalFakeBootProbeTest 32 / PhpBootProbeReferenceInventoryTest 44 /
   StrictTypesDeclarationScannerTest 5)
```

取り込んだ 3 ファイルの sha256 は**全工程を通じて取得時の値のまま**である。

以下に `tests/Architecture/` の最終差分を添付する (他のファイルは Round 2 から無変更)。

````diff
diff --git a/tests/Architecture/ExternalFakeBootProbeTest.php b/tests/Architecture/ExternalFakeBootProbeTest.php
index e555fffe..9aecfd03 100644
--- a/tests/Architecture/ExternalFakeBootProbeTest.php
+++ b/tests/Architecture/ExternalFakeBootProbeTest.php
@@ -4,8 +4,9 @@
 
 use App\Support\ExternalFakes\ExternalFakeBinding;
 use App\Support\ExternalFakes\ExternalFakeDeclaration;
-use Symfony\Component\Process\Exception\ProcessTimedOutException;
 use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
+use Tests\Support\Process\BootProbeResult;
+use Tests\Support\Process\BootProbeRunner;
 
 /*
  * 別プロセスで「宣言した差し替えが実際に効いているか」を実測する
@@ -15,9 +16,23 @@
  * 「実際の起動 (遅延読み込み provider・設定の解決順) でも効いているか」までは示せない。
  * ここでは子プロセスを起こし、起動しきったアプリの container から解決して観測する。
  *
- * ★子プロセスへ実際の外部資格情報を渡さない。プロセスの環境変数は `env -i` で空にし、
- *   設定は専用の一時環境ファイル 1 つだけから読む。書いてよいキーに外部サービスの
- *   資格情報は 1 つも無く、鍵の 2 つは使い捨ての生成値である (P-6 / P-7 / P-8)。
+ * ★子の起こし方・回収・書き出し先の退避は共通の起動器
+ *   (`Tests\Support\Process\BootProbeRunner`) が持つ
+ *   (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+ *
+ * ★**子プロセスへ実際の外部資格情報を渡さない**。子の環境は**4 段**で組み立てる —
+ *   継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE`) →
+ *   ケース別 (`FakeWiringProbeRunner::CASE_ENV_KEYS` の 3 件) → 予約 (書き出し先 7 キー)。
+ *   統制点は `proc_open` へ渡す環境配列であり、開発者ローカルの env はそこで締め出される (P-7)。
+ *
+ * ★**使い捨て鍵の置き場所は 2 つに分かれる**。`APP_KEY` は**ケース別上書き**、
+ *   `CIPHERSWEET_KEY` は**環境ファイル**である (Laravel の環境変数リポジトリは immutable で、
+ *   プロセス環境に既に在る値を Dotenv は上書きしないため)。どちらも親の実鍵の複写ではないこと、
+ *   かつ**子で実際に効いた**ことを P-8 が digest で測る。
+ *
+ * ★**正典 v1 (5) の実働証明**は P-13 (実体) と P-14 (向き) が持つ。「書き出し先を退避した」は、
+ *   退避が効いていなければ既定の場所へ書かれて観測が緑のまま嘘になるので、
+ *   子が `storage_path()` 経由で置いた印が起動器の一時ディレクトリ配下に現れることまで測る。
  *
  * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
  * キャッシュが古いときの本番事故は ProductionEnvGuard の二重判定が受け持つ。
@@ -57,11 +72,12 @@ function externalFakeProbeBaseDirectories(?string $add = null): array
  *     exitCode: int,
  *     output: array<string, mixed>,
  *     envFileValues: array<string, string>,
+ *     caseEnvValues: array<string, string>,
  *     directory: string,
  *     directoryMode: int,
  *     envFileMode: int,
- *     configCachePath: string,
- *     configCacheExists: bool,
+ *     temporaryRoot: string,
+ *     writtenRelativePaths: list<string>,
  *     baseDirectory: string,
  * }
  */
@@ -90,12 +106,51 @@ function externalFakeProbeRun(string $case): array
         $cache[$case] = [...$result, 'baseDirectory' => $base];
     }
 
-    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, configCachePath: string, configCacheExists: bool, baseDirectory: string} $entry */
+    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, caseEnvValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, temporaryRoot: string, writtenRelativePaths: list<string>, baseDirectory: string} $entry */
     $entry = $cache[$case];
 
     return $entry;
 }
 
+/**
+ * 書き出し先が**正規化済みの絶対パス**であることを確かめる (`.` / `..` を 1 つも含まない)。
+ *
+ * ★`BootProbeRunner::isInside()` の契約は「両引数とも realpath 済み」である。ところが
+ *   書き出し先の多く (設定キャッシュ等) は**まだ存在しないファイル**なので realpath できず、
+ *   子が返す文字列をそのまま渡すことになる。ここを素通しにすると
+ *   `<一時 root>/../../<リポジトリ>/…` のような形が
+ *   「一時 root の配下かつリポジトリの外」と判定され、**実際にはリポジトリ内へ解決される**のに
+ *   P-11 / P-14 が緑のまま通る (fail-open)。
+ *   予約パスの組み立てに `..` が混じる退行を見逃さないため、配下判定の**前に**弾く。
+ */
+function externalFakeProbeIsNormalizedAbsolutePath(string $path): bool
+{
+    if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
+        return false;
+    }
+
+    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
+        if ($segment === '.' || $segment === '..') {
+            return false;
+        }
+    }
+
+    return true;
+}
+
+/**
+ * 上の述語で書き出し先を検査する (診断文つき)。
+ *
+ * ★述語そのものの検出力は P-16 が**恒久の負例**で裏取りする
+ *   (実データが常に正常なので、この helper を空実装にしても P-11 / P-14 は緑のままになる。
+ *   AGENTS.md §静的検査の共通規約 (c) の「検出力は負例で裏取りする」に当たる)。
+ */
+function externalFakeProbeAssertNormalizedPath(string $path, string $label): void
+{
+    expect(externalFakeProbeIsNormalizedAbsolutePath($path))
+        ->toBeTrue("書き出し先 {$label} が正規化された絶対パスでない: {$path}");
+}
+
 /**
  * 観測結果の `resolved` を「解決キー => 実際に解決されたクラス」として取り出す。
  *
@@ -182,13 +237,33 @@ function externalFakeProbeResolved(array $output): array
         ->and(array_values(array_diff($keys, FakeWiringProbeRunner::ALLOWED_ENV_FILE_KEYS)))->toBe([]);
 });
 
-test('P-7 子が実際に受け取ったプロセス環境が許可した 3 件ちょうどである', function (): void {
-    $keys = externalFakeProbeRun('fake')['output']['process_environment_keys'] ?? null;
+test('P-7 子が実際に受け取ったプロセス環境が 4 段の合成結果と完全一致する', function (): void {
+    // (0) 4 段の定数そのものをリテラルで pin する。実装側の定数から期待値を組み立てるだけだと、
+    //     実装と期待値を同時に変えたときに緑のまま通ってしまう。
+    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR'])
+        ->and(BootProbeRunner::RESERVED_ENV_KEYS)->toBe([
+            'LARAVEL_STORAGE_PATH',
+            'VIEW_COMPILED_PATH',
+            'APP_CONFIG_CACHE',
+            'APP_ROUTES_CACHE',
+            'APP_SERVICES_CACHE',
+            'APP_PACKAGES_CACHE',
+            'APP_EVENTS_CACHE',
+        ])
+        ->and(FakeWiringProbeRunner::CASE_ENV_KEYS)->toBe([
+            'FAKE_WIRING_PROBE_ENV_DIR',
+            'FAKE_WIRING_PROBE_ENV_FILE',
+            'APP_KEY',
+        ]);
+
+    $run = externalFakeProbeRun('fake');
+    $keys = $run['output']['process_environment_keys'] ?? null;
     expect($keys)->toBeArray();
     /** @var list<mixed> $keys */
     $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);
 
-    // (b) 危険な接頭辞が 1 件も無いこと
+    // (a) 危険な接頭辞が 1 件も無いこと (env -i の時代からの主張をそのまま維持する)。
+    //     TESTING_FAKE_* は**プロセス環境へ載せない** (0600 の環境ファイルの中だけに置く)。
     foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
         $leaked = array_values(array_filter(
             $actual,
@@ -197,19 +272,43 @@ function externalFakeProbeResolved(array $output): array
         expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
     }
 
-    // (a)(c) 許可した 3 件がすべて存在し、それ以外の余りが無いこと (deny-by-default)
-    $expected = FakeWiringProbeRunner::ALLOWED_PROCESS_ENV_KEYS;
+    // (b) 集合の完全一致 (deny-by-default)。「以下」ではないので 1 本足しただけで赤くなる。
+    $inherited = array_values(array_filter(
+        ['PATH', 'HOME', 'TMPDIR'],
+        static function (string $key): bool {
+            $value = getenv($key);
+
+            return is_string($value) && $value !== '';
+        },
+    ));
+    $expected = array_values(array_unique(array_merge(
+        $inherited,
+        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
+        ['FAKE_WIRING_PROBE_ENV_DIR', 'FAKE_WIRING_PROBE_ENV_FILE', 'APP_KEY'],
+        ['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE',
+            'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE'],
+    )));
     sort($actual);
     sort($expected);
 
     expect($actual)->toBe($expected);
 });
 
-test('P-8 一時環境ファイルの鍵は親の設定値の複写ではない', function (): void {
-    $values = externalFakeProbeRun('fake')['envFileValues'];
+test('P-8 使い捨て鍵が子で実際に効き、親の設定値の複写ではない', function (): void {
+    $run = externalFakeProbeRun('fake');
 
-    expect($values['APP_KEY'] ?? null)->not->toBe(config('app.key'))
-        ->and($values['CIPHERSWEET_KEY'] ?? null)->not->toBe(config('ciphersweet.providers.string.key'));
+    $digests = $run['output']['key_digests'] ?? null;
+    expect($digests)->toBeArray();
+    /** @var array<string, mixed> $digests */
+
+    // (a) 子で効いた APP_KEY が、起動側が生成した使い捨て値と一致する
+    expect($digests['app'] ?? null)->toBe(hash('sha256', $run['caseEnvValues']['APP_KEY']));
+    // (b) 子で効いた CIPHERSWEET_KEY が、環境ファイルへ書いた使い捨て値と一致する
+    expect($digests['ciphersweet'] ?? null)->toBe(hash('sha256', $run['envFileValues']['CIPHERSWEET_KEY']));
+    // (c) いずれも親の設定値の複写ではない
+    expect($digests['app'])->not->toBe(hash('sha256', (string) config('app.key')))
+        ->and($digests['ciphersweet'])
+        ->not->toBe(hash('sha256', (string) config('ciphersweet.providers.string.key')));
 });
 
 test('P-9 一時ディレクトリ 0700 / 環境ファイル 0600 であり、違えば子を起こさない', function (): void {
@@ -225,7 +324,7 @@ function externalFakeProbeResolved(array $output): array
         ->toThrow(RuntimeException::class);
 });
 
-test('P-10 正常終了・非ゼロ終了・timeout のいずれでも一時ディレクトリが残らない', function (): void {
+test('P-10 正常終了・非ゼロ終了のいずれでも環境ファイルの置き場所が残らない', function (): void {
     foreach (['fake', 'real', 'production'] as $case) {
         $run = externalFakeProbeRun($case);
 
@@ -233,27 +332,124 @@ function externalFakeProbeResolved(array $output): array
             ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
             ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
     }
+});
 
-    // timeout でも finally を必ず通ること。
-    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
-    expect(mkdir($base, 0700))->toBeTrue();
+test('P-10b 作れない置き場所では子を起こさずに失敗し、残骸を残さない', function (): void {
+    $base = sys_get_temp_dir().'/fake-wiring-probe-readonly-'.bin2hex(random_bytes(6));
+    expect(mkdir($base, 0500))->toBeTrue();
 
     try {
-        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base, 0.01))
-            ->toThrow(ProcessTimedOutException::class);
+        // ★失敗の**段**まで固定する。message を見ないと「子を起こしたあとで別の理由で
+        //   落ちた」場合も緑になり、「子を起こさずに」の部分が主張だけになる。
+        //   この message は置き場所の検査 (= 子を起こす前) だけが投げる。
+        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base))
+            ->toThrow(RuntimeException::class, '観測用の置き場所を使用できない');
 
         expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
     } finally {
         rmdir($base);
     }
+})->skip(
+    // root で走ると 0500 でも書けてしまい、負のコントロールが成立しない。
+    // **成功扱いにはしない** — 測れていないことをテスト結果に出す。
+    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
+    'root では書き込み権限の負のコントロールを作れない',
+);
+
+test('P-10c 本体が例外を投げても置き場所が中身ごと消える (制限時間超過の後始末)', function (): void {
+    // 制限時間超過は interpret() が例外にする (P-15)。その例外が外側の finally を通ることを
+    // ここで決定的に測る (実 timeout を作るには子を 1 秒以上眠らせる必要があり、
+    // それは観測用スクリプトの責務を汚すので採らない)。
+    // ★空のディレクトリではなく**中身のある**状態で測る — 実際の制限時間超過では
+    //   .env.probe が既に書かれているので、再帰削除まで示さないと主張と距離がある。
+    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
+    expect(mkdir($base, 0700))->toBeTrue();
+
+    $created = null;
+
+    try {
+        expect(function () use ($base, &$created): mixed {
+            return FakeWiringProbeRunner::withEnvironmentDirectory(
+                $base,
+                static function (string $directory) use (&$created): mixed {
+                    $created = $directory;
+
+                    // 実際の走行と同じく環境ファイルを置き、さらに下位ディレクトリの中にも番兵を置く。
+                    expect(file_put_contents($directory.'/.env.probe', "APP_ENV=x\n"))->not->toBeFalse();
+                    expect(mkdir($directory.'/nested', 0700))->toBeTrue();
+                    expect(file_put_contents($directory.'/nested/sentinel.txt', 'x'))->not->toBeFalse();
+
+                    throw new RuntimeException('本体の失敗');
+                },
+            );
+        })->toThrow(RuntimeException::class);
+
+        // 置き場所は作られ (= 検査が空振りしていない)、中身ごと消えている。
+        expect($created)->toBeString()
+            ->and(is_dir((string) $created))->toBeFalse('置き場所が残っている')
+            ->and(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
+    } finally {
+        rmdir($base);
+    }
+});
+
+test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
+    // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
+    $base = base_path('storage/framework/testing');
+
+    // ★このテストが作った階層を**1 つ残らず**戻す (走行が生成物を残さないため)。
+    //   `mkdir(recursive)` + `rmdir($base)` だけだと、親を新規作成した環境
+    //   (新しい checkout など) で `storage/framework` が残る。
+    $createdAncestors = [];   // 深い順
+    for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
+        $createdAncestors[] = $candidate;
+    }
+    foreach (array_reverse($createdAncestors) as $directory) {
+        expect(mkdir($directory, 0755))->toBeTrue("後始末の対象を作れない: {$directory}");
+    }
+
+    try {
+        $before = glob($base.'/fake-wiring-probe-*');
+        expect($before)->toBeArray();
+
+        $bodyCalled = false;
+
+        expect(function () use ($base, &$bodyCalled): mixed {
+            return FakeWiringProbeRunner::withEnvironmentDirectory(
+                $base,
+                static function (string $directory) use (&$bodyCalled): mixed {
+                    $bodyCalled = true;
+
+                    return $directory;
+                },
+            );
+        })->toThrow(RuntimeException::class);
+
+        expect($bodyCalled)->toBeFalse('リポジトリ内なのに本体が呼ばれた')
+            ->and(glob($base.'/fake-wiring-probe-*'))->toBe($before, '拒否経路が残骸を残している');
+    } finally {
+        // 深い順に戻す (作った分だけ)。
+        foreach ($createdAncestors as $directory) {
+            rmdir($directory);
+        }
+    }
 });
 
-test('P-11 設定キャッシュの指し先は一時ディレクトリ配下の絶対パスで、存在しない', function (): void {
+test('P-11 設定キャッシュの退避先が一時ディレクトリ配下で、書かれていない', function (): void {
     $run = externalFakeProbeRun('fake');
 
-    expect(str_starts_with($run['configCachePath'], '/'))->toBeTrue()
-        ->and(str_starts_with($run['configCachePath'], $run['directory'].'/'))->toBeTrue()
-        ->and($run['configCacheExists'])->toBeFalse();
+    $targets = $run['output']['write_targets'] ?? null;
+    expect($targets)->toBeArray();
+    /** @var array<string, mixed> $targets */
+    $configCache = $targets['config_cache'] ?? null;
+    expect($configCache)->toBeString();
+    /** @var string $configCache */
+    // 配下判定の前に正規化を確かめる (`..` 経由でリポジトリへ戻る形を通さない)。
+    externalFakeProbeAssertNormalizedPath($configCache, 'config_cache');
+
+    expect(BootProbeRunner::isInside($run['temporaryRoot'], $configCache))->toBeTrue()
+        // 設定キャッシュ**無し**の起動を観測している (書かれていたら前提が崩れている)。
+        ->and($run['writtenRelativePaths'])->not->toContain('bootstrap-cache/config.php');
 });
 
 test('P-12 宣言の型: 観測が読む swaps() は ExternalFakeBinding の列である', function (): void {
@@ -261,3 +457,87 @@ function externalFakeProbeResolved(array $output): array
         expect($swap)->toBeInstanceOf(ExternalFakeBinding::class);
     }
 });
+
+test('P-13 実働証明(実体): 子が storage_path() 経由で書いた印が一時ディレクトリ配下に現れる', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    expect($run['writtenRelativePaths'])
+        ->toContain('storage/'.FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
+});
+
+test('P-14 実働証明(向き): 子が解決した書き出し先が 1 件残らず一時ディレクトリ配下でリポジトリの外', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    $targets = $run['output']['write_targets'] ?? null;
+    expect($targets)->toBeArray();
+    /** @var array<string, mixed> $targets */
+    $repositoryRoot = realpath(base_path());
+    expect($repositoryRoot)->toBeString();
+    /** @var string $repositoryRoot */
+    $expectedKeys = ['storage', 'config_cache', 'routes_cache', 'services_cache',
+        'packages_cache', 'events_cache', 'view_compiled', 'log_path'];
+    expect(array_keys($targets))->toBe($expectedKeys, '観測点の集合が変わっている');
+
+    foreach ($expectedKeys as $key) {
+        $path = $targets[$key];
+        expect($path)->toBeString();
+        /** @var string $path */
+
+        // ★配下判定の**前に**正規化を確かめる。isInside は realpath 済みを前提にするので、
+        //   `..` を含む形は「一時 root 配下かつリポジトリ外」と誤判定されうる (fail-open)。
+        externalFakeProbeAssertNormalizedPath($path, $key);
+
+        // 区切り文字を境界にした配下判定 (素の前方一致は /a と /ab を取り違える)。
+        // isInside は同一パスも true にするので、base_path() 自身も「外ではない」に入る。
+        expect(BootProbeRunner::isInside($run['temporaryRoot'], $path))
+            ->toBeTrue("書き出し先 {$key} が一時ディレクトリの外を指している: {$path}")
+            ->and(BootProbeRunner::isInside($repositoryRoot, $path))
+            ->toBeFalse("書き出し先 {$key} がリポジトリ側を指している: {$path}");
+    }
+});
+
+test('P-15 fail-closed: interpret() は観測が成立していない結果を沈黙させない', function (): void {
+    $make = static fn (string $stdout, bool $timedOut, int $exitCode): BootProbeResult => new BootProbeResult(
+        stdout: $stdout, stderr: '', exitCode: $exitCode, timedOut: $timedOut,
+        elapsedSeconds: 0.1, temporaryRoot: '/tmp/boot-probe-x',
+        writtenRelativePaths: [], pid: 1,
+    );
+
+    $call = static fn (BootProbeResult $result): array => FakeWiringProbeRunner::interpret(
+        $result, [], [], '/tmp/dir', 0700, 0600,
+    );
+
+    // (a) 制限時間超過は通常の非ゼロ終了と区別して例外にする (fail-open 防止)
+    expect(fn (): array => $call($make('{"resolved":{}}', true, 124)))->toThrow(RuntimeException::class);
+    // (b) 空出力 / (c) JSON でない / (d) トップレベルが配列でない
+    expect(fn (): array => $call($make('', false, 0)))->toThrow(RuntimeException::class);
+    expect(fn (): array => $call($make('not json', false, 0)))->toThrow(RuntimeException::class);
+    expect(fn (): array => $call($make('"scalar"', false, 0)))->toThrow(RuntimeException::class);
+});
+
+test('P-16 正規化判定の検出力: 正常な絶対パスは通り、`..` / `.` / 相対パスは弾く', function (
+    string $path,
+    bool $expected,
+): void {
+    expect(externalFakeProbeIsNormalizedAbsolutePath($path))->toBe($expected, $path);
+})->with([
+    // --- 正例 (実データと同じ形。これが false になると P-11 / P-14 が偽レッドになる) ---
+    ['/tmp/boot-probe-abc/storage', true],
+    ['/tmp/boot-probe-abc/bootstrap-cache/config.php', true],
+    ['/tmp/boot-probe-abc/storage/framework/views', true],
+    // --- 負例: `..` でリポジトリ側へ戻れる形 (これを通すと P-11 / P-14 が fail-open) ---
+    ['/tmp/boot-probe-abc/../../workspace/bootstrap/cache/config.php', false],
+    ['/tmp/boot-probe-abc/..', false],
+    ['/../tmp/boot-probe-abc/storage', false],
+    // --- 負例: `.` セグメント ---
+    ['/tmp/boot-probe-abc/./storage', false],
+    ['/tmp/./boot-probe-abc/storage', false],
+    // --- 負例: 相対パス (絶対パス前提が崩れた形) ---
+    ['tmp/boot-probe-abc/storage', false],
+    ['./storage', false],
+    ['../storage', false],
+    // --- 紛らわしいが正当な形 (素の部分文字列判定なら誤って弾く 3 形) ---
+    ['/tmp/boot-probe-abc/..hidden', true],
+    ['/tmp/boot-probe-abc/.hidden', true],
+    ['/tmp/boot-probe-abc/a..b/storage', true],
+]);
diff --git a/tests/Architecture/PhpBootProbeReferenceInventoryTest.php b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
new file mode 100644
index 00000000..3a34bd21
--- /dev/null
+++ b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
@@ -0,0 +1,622 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\PhpTokenScan;
+use Tests\Support\ReferenceKind;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+| `tests/` 配下の**3 種類の字句参照**の全数申告 inventory —
+|   (A) 定数 `PHP_BINARY` の参照 / (B) 文字列 `bootstrap/app.php` の参照 /
+|   (C) 文字列 `fake-wiring-probe.php` (既存の子入口) の参照。
+| lctl feature: subprocess-boot-probe-harness (正典 v1 の作法へ追従したあとの退行を検出する)。
+| **本 gate は正典 v1 の 6 不変条件ではなく aicue 側の上積みである** (根拠: 正典テンプレートの
+| 同型 gate と AGENTS.md 禁止事項 1)。
+|
+| **名前のとおり、これは「起動の全数」ではなく「参照の全数」の inventory である。**
+| 「PHP の子プロセスを起こしうる箇所を漏れなく数える」ことは**していない**。
+|
+| ## 主張すること
+|
+| 「`PHP_BINARY` の字句参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
+| 既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。
+|
+| ## 主張しないこと (名指しで書く)
+|
+|  1. 「アプリを子プロセスで起こす経路が共通の起動器ちょうど 1 本である」こと
+|  2. 文字列リテラルの `'php'` / `env php` / シェルスクリプト経由 /
+|     変数から取り出した実行体パスの検出
+|  3. **起動呼び出しの分類** — 「どのクラスの `new` か」「`proc_open` かその別名か」といった
+|     網羅的な分類は**行わない** (行えば「緑のまま嘘をつく」)。
+|     G-6 が確かめるのは**共通の起動器への静的呼び出しが在ること**だけである
+|  4. 文字列を分割して針を避ける形 (`'fake-wiring-'.'probe.php'`) の検出
+|
+| ## 軸ごとの名前解決の扱い (AGENTS.md §静的検査の共通規約 (a) / (b))
+|
+|  - **G-6 は完全修飾名で突き合わせる**。`Tests\Support\PhpReferenceScanner` が
+|    `use` / group use / 別名つき取り込みを解いた FQCN を返すので、それを
+|    `Tests\Support\Process\BootProbeRunner` と完全一致で比べる。
+|    したがって `use … as Runner; Runner::run(` も**正しく検出する**一方、
+|    **同名の別クラス** (`Other\BootProbeRunner::run(`) は**検出しない** (短名一致ではない)。
+|    受け手が静的に確定できない形 (`$runner::run(` / `static::` 等) は
+|    **「呼んでいる証拠」として数えない** — G-6 は存在を主張する検査なので、
+|    未解決を証拠に数える方が危険側だからである
+|  - **軸 A は名前トークンの末尾要素**で判定する。定数の参照には `PhpReferenceScanner` の
+|    母集団 (クラス名の参照 / 構築 / 呼び出し) が対応しないためで、
+|    ここは**拾いすぎる方向** ((b) の許す側) へ倒してある。
+|    帰結として `Foo\PHP_BINARY` という**別の定数**も軸 A に入る
+|    (申告を 1 行足せば済むので、見逃すより安全側である)
+|
+| **一元化そのものの証拠は載せ替えの実測 (`ExternalFakeBootProbeTest` の P-7〜P-15) であり、
+| 本 gate は退行の検出器である。**
+|
+| ## 走査対象と走査の意味論
+|
+|  - 母集団は `Tests\Support\TrackedPhpSourceFiles` が返す **git 追跡下の `*.php`** のうち
+|    `tests/` 配下 (**未追跡のファイルは母集団に入らない**。`TrackedPhpSourceFiles` の docblock)
+|  - 判定は `Tests\Support\PhpTokenScan::normalize()` の上に建てる。
+|    **コメント・docblock は正規化が落とすので数えない**
+|  - 軸 A の「定数の参照」は**名前トークンの末尾要素の完全一致**で判定する
+|    (`T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`)。区切りは `\` である。
+|    `\PHP_BINARY` と `use const Foo\PHP_BINARY as X;` の別名 import も末尾要素で拾うので
+|    fail-closed になる。接頭辞つき (`MY_PHP_BINARY`) / 打ち消しつき (`NOT_PHP_BINARY`) /
+|    接尾辞つき (`PHP_BINARY_PATH`) は**別のトークン**なので拾わない
+|    (AGENTS.md §静的検査の共通規約 (e) の 3 形。G-7 が両方向を固定する)
+|  - 軸 B / 軸 C の「文字列の参照」は文字列トークン
+|    (`T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`) の**素の部分文字列**一致である
+|    (ヒアドキュメント・ナウドキュメントの本文を含む)
+*/
+
+/**
+ * 軸 A: `tests/` 配下で `PHP_BINARY` を参照してよいファイルの全数申告 (deny-by-default)。
+ *
+ * entry は 4 つの欄を独立に持つ (「件数合わせの allowlist」へ流れないための構造):
+ *  - `launches_app`: アプリを起こすと申告するか (**補助的な申告値**。実際の起動経路の
+ *    全数性を表すものではなく、「アプリを起こす」と申告する先が分散していないことだけを固定する)
+ *  - `subject` / `recovery` / `reason`
+ *
+ * @return array<string, array{launches_app: bool, subject: non-empty-string, recovery: non-empty-string, reason: non-empty-string}>
+ */
+function phpBootProbeBinaryReferenceInventory(): array
+{
+    return [
+        'tests/Support/Process/BootProbeRunner.php' => [
+            'launches_app' => true,
+            'subject' => 'アプリを子プロセスで起こして起動順序を測る (PHP_BINARY)',
+            'recovery' => '本クラス自身 (制限時間・段階的強制終了・終了コードの保持・一時ディレクトリの後片付け)',
+            'reason' => '共通の起動器そのもの (lctl feature: subprocess-boot-probe-harness)',
+        ],
+        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
+            'launches_app' => false,
+            'subject' => '起動器の自己検査。参照は期待値の比較と、子へ渡す検体文字列の中だけである',
+            'recovery' => '起動器 (本ファイルは直接の起動 API を持たず、BootProbeRunner 経由でのみ子を起こす)',
+            'reason' => 'バイト一致で取り込んだ共有ファイルなので編集しない。起動器を通してしか子を起こさない',
+        ],
+        'tests/Support/StrictTypesRuntimeProbe.php' => [
+            'launches_app' => false,
+            'subject' => '検体 PHP を子で読み込み declare(strict_types=1) の実効性を測る。アプリは起こさない',
+            'recovery' => 'Symfony の Process (既定の制限時間つきで、超過すれば例外になる)',
+            'reason' => '起動順序ではなく単一ファイルのコンパイル指令を測る層である。起動器に載せると '
+                .'Laravel 固有の基底環境・書き出し先 7 キーの予約という無関係な前提が付く '
+                .'(同じ理由で PhpLintOracle も載せていない)',
+        ],
+        'tests/Support/GlobalUse/PhpLintOracle.php' => [
+            'launches_app' => false,
+            'subject' => '`php -l` を真値として取り出す (構文検査のみ。アプリは起こさない)',
+            'recovery' => '同クラス (Symfony Process が管を読み切り、終了コードが null なら例外にする)',
+            'reason' => 'アプリを起動しないので環境の 3 段合成も書き出し先の退避も要らない',
+        ],
+        'tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php' => [
+            'launches_app' => false,
+            'subject' => 'テスト DB の用意スクリプトを起こす (DB へは接続しない)。アプリは起こさない',
+            'recovery' => '同ファイルの helper (管を読み切って proc_close する)',
+            'reason' => 'アプリの起動順序ではなくスクリプトの契約を測る層である '
+                .'(lctl feature: php-test-pgsql-lane 側の関心事。本 feature とは distinct_from の関係)',
+        ],
+        'tests/Architecture/NoNonCompoundGlobalUseTest.php' => [
+            'launches_app' => false,
+            'subject' => '診断メッセージへ実行体のパスを載せるだけ (子は起こさない)',
+            'recovery' => '該当なし (起動しない)',
+            'reason' => '起動は PhpLintOracle が行い、本ファイルは失敗時の診断に PHP_BINARY を印字するだけである',
+        ],
+        'tests/Feature/Console/PipelineSmokeCommandTest.php' => [
+            'launches_app' => false,
+            'subject' => 'ffmpeg の代役として設定値へ実行体のパスを入れるだけ (テストから子は起こさない)',
+            'recovery' => '該当なし (起動するのはアプリ側の合成経路であり、本 feature の射程外)',
+            'reason' => 'アプリの起動順序を測る経路ではない (ffmpeg 起動の統制は '
+                .'tests/Architecture/FfmpegProcessLaunchInventoryTest.php が持つ)',
+        ],
+    ];
+}
+
+/**
+ * 軸 B: `tests/` 配下でアプリの起動点 (`bootstrap/app.php`) を参照してよいファイルの全数申告。
+ *
+ * `kind` は 3 値:
+ *  - `child_entry` : 子プロセスで読み込まれる入口 / 子へ渡す検体文字列
+ *  - `in_process`  : 同一プロセスでのアプリ起動 (子プロセスではない)
+ *  - `inventory`   : 検査定義・診断文としてパス文字列を保持するだけ
+ *
+ * `boots_repository_env` は「その経路で起きた**子**が、リポジトリの `.env` を読んで起動するか」。
+ * **これは望ましさの宣言ではなく、危険面の目録である** (G-8 が件数と場所を pin する)。
+ * 詳細は G-8 の docblock を読むこと。
+ *
+ * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', boots_repository_env: bool, reason: non-empty-string}>
+ */
+function phpBootProbeAppBootEntryReferenceInventory(): array
+{
+    return [
+        'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
+            'kind' => 'child_entry',
+            // 専用の 0600 環境ファイルへ固定して起動する (リポジトリの .env は読まない)。
+            'boots_repository_env' => false,
+            'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
+        ],
+        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
+            'kind' => 'child_entry',
+            // ★S9 / S10 の検体はリポジトリ root を作業ディレクトリにして bootstrap/app.php を
+            //   読むため、**リポジトリの .env がそのまま子の設定に載る** (実測で確認済み。G-8)。
+            'boots_repository_env' => true,
+            'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
+        ],
+        'tests/TestCase.php' => [
+            'kind' => 'in_process',
+            // 同一プロセスなので phpunit.xml の <server force> が効く (秘密は無害化済み)。
+            'boots_repository_env' => false,
+            'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
+        ],
+        'tests/Support/Cache/IsolatedApplicationProbe.php' => [
+            'kind' => 'in_process',
+            'boots_repository_env' => false,
+            'reason' => 'キャッシュ受け皿の結線を測るための第 2 のアプリを同一プロセスで組み立てる。子プロセスではない',
+        ],
+        'tests/Architecture/CacheGuardWiringGateTest.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
+        ],
+        'tests/Architecture/BughuntExecutedRouteOrderingTest.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'reason' => '記録器の位置を固定する検査が、違反時の直し方を案内する診断文にパス文字列を持つ',
+        ],
+        'tests/Architecture/InertiaErrorScreenContractTest.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'reason' => '例外応答の最終整形スロットの登録位置を検査する側が、照合する場所としてパス文字列を持つ',
+        ],
+        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
+        ],
+    ];
+}
+
+/**
+ * 軸 C: 子入口スクリプトのパスを参照してよいファイルの全数申告。
+ *
+ * `reference_kind` は 2 値: `runtime` (実行経路として子入口を起こす) / `inventory` (検査定義)。
+ *
+ * @return array<string, array{reference_kind: 'runtime'|'inventory', reason: non-empty-string}>
+ */
+function phpBootProbeChildEntryReferenceInventory(): array
+{
+    return [
+        'tests/Support/ExternalFakes/FakeWiringProbeRunner.php' => [
+            'reference_kind' => 'runtime',
+            'reason' => '子入口を起こす唯一の呼び出し元。起こし方と回収は BootProbeRunner に委ねる',
+        ],
+        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
+            'reference_kind' => 'inventory',
+            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
+        ],
+    ];
+}
+
+/** 走査の針 (2 箇所に書かない)。 */
+const PHP_BOOT_PROBE_APP_ENTRY_NEEDLE = 'bootstrap/app.php';
+
+const PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE = 'fake-wiring-probe.php';
+
+/** G-6 が完全修飾名で突き合わせる共通の起動器。 */
+const PHP_BOOT_PROBE_RUNNER_FQCN = 'Tests\\Support\\Process\\BootProbeRunner';
+
+/**
+ * 名前トークンの末尾要素 (区切りは `\`)。
+ *
+ * `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` は 1 トークンで届くので、
+ * 素の部分文字列一致ではなく区切りで割った完全一致で比べる
+ * (AGENTS.md §静的検査の共通規約 (e))。
+ */
+function phpBootProbeLastNameSegment(string $name): string
+{
+    $segments = explode('\\', $name);
+
+    return $segments[count($segments) - 1];
+}
+
+/**
+ * ソースが定数 `$constant` を**名前として**参照しているか。
+ *
+ * 文字列リテラルの中の同じ綴りは数えない (トークン種別で区別する)。
+ */
+function phpBootProbeReferencesConstant(string $source, string $constant): bool
+{
+    foreach (PhpTokenScan::normalize($source) as $token) {
+        if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+            continue;
+        }
+
+        if (phpBootProbeLastNameSegment($token['text']) === $constant) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * ソースの**文字列トークン**に `$needle` が現れるか
+ * (ヒアドキュメント・ナウドキュメントの本文を含む。コメントは正規化が落とす)。
+ */
+function phpBootProbeReferencesStringNeedle(string $source, string $needle): bool
+{
+    foreach (PhpTokenScan::normalize($source) as $token) {
+        if (! in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
+            continue;
+        }
+
+        if (str_contains($token['text'], $needle)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * ソースが**共通の起動器**への静的呼び出し `BootProbeRunner::run(` を持つか。
+ *
+ * ★照合は**完全修飾名**で行う (AGENTS.md §静的検査の共通規約 (a))。
+ *   `Tests\Support\PhpReferenceScanner` が `use` / group use / 別名つき取り込みを解いた
+ *   FQCN を返すので、短名一致で同名の別クラスを拾うことも、別名 1 つで黙ることも無い。
+ * ★受け手が静的に確定できない形 (`$runner::run(` / `static::` 等) は
+ *   **証拠として数えない**。G-6 は「呼んでいる」ことを主張する検査なので、
+ *   未解決を肯定側へ数える方が危険である。
+ */
+function phpBootProbeCallsBootProbeRunner(string $relativePath, string $source): bool
+{
+    foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
+        if ($site->kind !== ReferenceKind::StaticCall || $site->name !== 'run') {
+            continue;
+        }
+
+        if (! $site->receiver->isResolved()) {
+            continue;
+        }
+
+        if ($site->receiver->fqcn() === PHP_BOOT_PROBE_RUNNER_FQCN) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * 走査の母集団: git 追跡下の `tests/` 配下の `*.php` (相対パス => ソース)。
+ *
+ * @return array<string, string>
+ */
+function phpBootProbeTestSources(): array
+{
+    /** @var array<string, string>|null $cache */
+    static $cache = null;
+
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $sources = [];
+    foreach (TrackedPhpSourceFiles::all(base_path()) as $file) {
+        if (! str_starts_with($file['relative'], 'tests/')) {
+            continue;
+        }
+
+        $source = file_get_contents($file['absolute']);
+        if ($source === false) {
+            // 読めないファイルを黙って落とすと走査が縮む (fail-closed)。
+            throw new RuntimeException('走査対象を読めなかった: '.$file['relative']);
+        }
+
+        $sources[$file['relative']] = $source;
+    }
+
+    $cache = $sources;
+
+    return $cache;
+}
+
+/**
+ * 実測: 述語が真になった相対パスの昇順リスト。
+ *
+ * @param  callable(string): bool  $matches
+ * @return list<string>
+ */
+function phpBootProbeMeasure(callable $matches): array
+{
+    $hits = [];
+    foreach (phpBootProbeTestSources() as $relative => $source) {
+        if ($matches($source)) {
+            $hits[] = $relative;
+        }
+    }
+
+    sort($hits);
+
+    return $hits;
+}
+
+/** 申告のキーを昇順で取り出す。 @param array<string, mixed> $inventory @return list<string> */
+function phpBootProbeDeclaredPaths(array $inventory): array
+{
+    $paths = array_keys($inventory);
+    sort($paths);
+
+    return $paths;
+}
+
+test('G-1 軸 A: PHP_BINARY を参照するファイルの集合が全数申告と完全一致する', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesConstant($source, 'PHP_BINARY'),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeBinaryReferenceInventory()),
+        '未申告のファイルが PHP_BINARY を参照している、または申告が実体より多い。'
+        .'足すときは launches_app / subject / recovery / reason の 4 欄を埋めること',
+    );
+});
+
+test('G-2 軸 A: アプリを起こすと申告するのは共通の起動器ちょうど 1 件である', function (): void {
+    $launching = array_keys(array_filter(
+        phpBootProbeBinaryReferenceInventory(),
+        static fn (array $entry): bool => $entry['launches_app'],
+    ));
+
+    expect($launching)->toBe(['tests/Support/Process/BootProbeRunner.php']);
+});
+
+test('G-3 軸 A: subject / recovery / reason の 3 欄がいずれも空でない', function (): void {
+    foreach (phpBootProbeBinaryReferenceInventory() as $path => $entry) {
+        expect(trim($entry['subject']))->not->toBe('', "subject が空: {$path}")
+            ->and(trim($entry['recovery']))->not->toBe('', "recovery が空: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-4 軸 B: アプリの起動点を参照するファイルの集合が全数申告と完全一致し、kind が 3 値である', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesStringNeedle(
+            $source,
+            PHP_BOOT_PROBE_APP_ENTRY_NEEDLE,
+        ),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeAppBootEntryReferenceInventory()),
+        '未申告のファイルがアプリの起動点を参照している (kind と reason を 1 行足すこと)',
+    );
+
+    foreach (phpBootProbeAppBootEntryReferenceInventory() as $path => $entry) {
+        // `toContain` は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
+        expect(in_array($entry['kind'], ['child_entry', 'in_process', 'inventory'], true))
+            ->toBeTrue("kind が 3 値の外: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-5 軸 C: 子入口を参照するファイルの集合が全数申告と完全一致し、reference_kind が 2 値である', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesStringNeedle(
+            $source,
+            PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE,
+        ),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeChildEntryReferenceInventory()),
+        '未申告のファイルが子入口スクリプトを参照している',
+    );
+
+    foreach (phpBootProbeChildEntryReferenceInventory() as $path => $entry) {
+        // `toContain` は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
+        expect(in_array($entry['reference_kind'], ['runtime', 'inventory'], true))
+            ->toBeTrue("reference_kind が 2 値の外: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-6 軸 C: runtime はちょうど 1 件で、共通の起動器を実際に呼んでいる', function (): void {
+    $runtime = array_keys(array_filter(
+        phpBootProbeChildEntryReferenceInventory(),
+        static fn (array $entry): bool => $entry['reference_kind'] === 'runtime',
+    ));
+
+    expect($runtime)->toBe(['tests/Support/ExternalFakes/FakeWiringProbeRunner.php']);
+
+    $sources = phpBootProbeTestSources();
+    foreach ($runtime as $path) {
+        expect($sources)->toHaveKey($path);
+        expect(phpBootProbeCallsBootProbeRunner($path, $sources[$path]))
+            ->toBeTrue("{$path} が ".PHP_BOOT_PROBE_RUNNER_FQCN.'::run( を呼んでいない (子の起こし方が一元化から外れている)');
+    }
+});
+
+/**
+ * G-8: リポジトリの `.env` を読んで起動する**子**の目録 (危険面の pin)。
+ *
+ * ## 何を測っているか
+ *
+ * 共通の起動器は `proc_open` へ渡す環境配列で開発者ローカルの env を締め出すが、
+ * **`.env` ファイルの読み込みまでは止めない**。子の作業ディレクトリはリポジトリ root なので、
+ * 子が `bootstrap/app.php` を素で読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
+ *
+ * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査の S9 / S10 が使う検体でこれを確かめたところ、
+ * 子の設定には `.env` 由来の値が入っていた — 外部サービスの資格情報
+ * (Stripe / AWS / Google / SMTP) は本チェックアウトではいずれも空だったが、
+ * **DB のパスワードと `CIPHERSWEET_KEY` は実値が載った**。
+ * **「空だった」のはこのチェックアウトの性質であって、保証ではない。**
+ *
+ * ## なぜ止めずに目録にするのか
+ *
+ * 当該検体は**テンプレートからバイト一致で取り込んだ共有ファイル**の中にあり、
+ * ここで書き換えると意図的逸脱の登録が要る (T249 の受入条件は「取り込み 3 本を編集しない」)。
+ * したがって本 gate は**除去ではなく封じ込め**を担う —
+ * この性質を持つ経路が**申告なしに増えない**ことだけを機械で固定する。
+ *
+ * ## 対比 (なぜ他の経路は false なのか)
+ *
+ *  - 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
+ *    効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
+ *    **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
+ *    これが子と同一プロセスの非対称の正体である
+ *  - `fake-wiring-probe.php` は専用の 0600 環境ファイルへ `useEnvironmentPath()` /
+ *    `loadEnvironmentFrom()` で固定するので、リポジトリの `.env` を読まない
+ *
+ * ## 上流への申し送り
+ *
+ * 正典側 (lctl feature: subprocess-boot-probe-harness) で
+ * 「アプリを起こす自己検査の子にも専用の環境ファイルを読ませる」ことを検討すべきである。
+ * 解消されて再取り込みしたら、本 pin の `true` は 0 件になる。
+ */
+test('G-8 リポジトリの .env を読んで起動する子は、申告した 1 件だけである', function (): void {
+    $inventory = phpBootProbeAppBootEntryReferenceInventory();
+
+    $bootsRepositoryEnv = array_keys(array_filter(
+        $inventory,
+        static fn (array $entry): bool => $entry['boots_repository_env'],
+    ));
+
+    // ★件数と場所を完全一致で pin する。増やすには「なぜその子が .env を読んでよいのか」を
+    //   申告に書くことになり、レビューに必ず見える。
+    expect($bootsRepositoryEnv)->toBe(
+        ['tests/Unit/Support/Process/BootProbeRunnerTest.php'],
+        'リポジトリの .env を読んで起動する子が増減している。'
+        .'増やすなら G-8 の docblock を読み、なぜ専用の環境ファイルを使えないのかを申告すること',
+    );
+
+    // ★`true` を申告してよいのは**バイト一致で取り込んだ共有ファイル**だけである
+    //   (aicue が自分で書いたファイルには、専用の環境ファイルを使わない言い訳が無い)。
+    foreach ($bootsRepositoryEnv as $path) {
+        expect(str_starts_with($path, 'tests/Unit/Support/Process/'))
+            ->toBeTrue("aicue 所有のファイルがリポジトリの .env を読む子を持っている: {$path}");
+    }
+
+    // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
+    //   定義上この危険面を持たない。取り違えを防ぐために両方向で固定する。
+    foreach ($inventory as $path => $entry) {
+        if ($entry['kind'] !== 'child_entry') {
+            expect($entry['boots_repository_env'])
+                ->toBeFalse("子プロセスではない経路に .env 読み込みが申告されている: {$path}");
+        }
+    }
+});
+
+test('G-7 走査が空振りしていない (走査根が実在し、3 軸の母集団が非空)', function (): void {
+    expect(is_dir(base_path('tests')))->toBeTrue('走査根 tests/ が実在しない');
+
+    $sources = phpBootProbeTestSources();
+    expect(count($sources))->toBeGreaterThan(100, '母集団が縮んでいる (走査が壊れている可能性)');
+
+    // 申告したパスは 3 軸とも実在する (改名・移動に気づかずに申告だけが残るのを防ぐ)。
+    foreach ([
+        phpBootProbeBinaryReferenceInventory(),
+        phpBootProbeAppBootEntryReferenceInventory(),
+        phpBootProbeChildEntryReferenceInventory(),
+    ] as $inventory) {
+        expect($inventory)->not->toBeEmpty();
+        foreach (array_keys($inventory) as $path) {
+            // `toHaveKey` の第 2 引数は**期待する値**なので、診断文は素の真偽で書く。
+            expect(array_key_exists($path, $sources))
+                ->toBeTrue("申告したパスが母集団に無い (改名・移動・git add 忘れ): {$path}");
+        }
+    }
+});
+
+test('G-7 走査器の見本検査: 3 軸の判定が見本表どおりである', function (
+    string $sample,
+    bool $axisA,
+    bool $axisB,
+    bool $axisC,
+): void {
+    expect(phpBootProbeReferencesConstant($sample, 'PHP_BINARY'))->toBe($axisA, "軸 A: {$sample}")
+        ->and(phpBootProbeReferencesStringNeedle($sample, PHP_BOOT_PROBE_APP_ENTRY_NEEDLE))
+        ->toBe($axisB, "軸 B: {$sample}")
+        ->and(phpBootProbeReferencesStringNeedle($sample, PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE))
+        ->toBe($axisC, "軸 C: {$sample}");
+})->with([
+    // [検体, 軸 A, 軸 B, 軸 C]
+    ['<?php $x = [PHP_BINARY];', true, false, false],
+    ['<?php // PHP_BINARY', false, false, false],
+    ['<?php $s = "PHP_BINARY";', false, false, false],
+    ['<?php use const PHP_BINARY as Runtime; $x = Runtime;', true, false, false],
+    // 完全修飾・修飾つきの定数参照も末尾要素で拾う (fail-closed)。
+    ['<?php $x = \PHP_BINARY;', true, false, false],
+    ['<?php use const Foo\PHP_BINARY as Runtime; $x = Runtime;', true, false, false],
+    // 接頭辞つき・打ち消しつき・接尾辞つきは別トークンなので拾わない。
+    ['<?php $x = MY_PHP_BINARY;', false, false, false],
+    ['<?php $x = NOT_PHP_BINARY;', false, false, false],
+    ['<?php $x = PHP_BINARY_PATH;', false, false, false],
+    ["<?php require 'bootstrap/app.php';", false, true, false],
+    ['<?php // require bootstrap/app.php', false, false, false],
+    ["<?php \$p = __DIR__.'/fake-wiring-probe.php';", false, false, true],
+    // 文字列を分割して針を避ける形は**射程外**。限界を期待値として固定する。
+    ['<?php $a = \'fake-wiring-\'."probe.php";', false, false, false],
+    // ★軸 B / C は**素の部分文字列**一致である (軸 A の語彙一致とは判定が違う)。
+    //   接頭辞つき・打ち消しつき・接尾辞つきは**いずれも一致する** = 申告が要る側へ倒れる。
+    //   見逃す方向ではなく拾いすぎる方向なので (b) の許す側であり、
+    //   紛らわしい綴りを足した人には「1 行申告する」という摩擦だけが掛かる。
+    ["<?php \$p = 'vendor/bootstrap/app.php';", false, true, false],
+    ["<?php \$p = 'not-bootstrap/app.php';", false, true, false],
+    ["<?php \$p = 'bootstrap/app.php.bak';", false, true, false],
+    ["<?php \$p = 'old-fake-wiring-probe.php';", false, false, true],
+    ["<?php \$p = 'fake-wiring-probe.php.disabled';", false, false, true],
+    // 針の一部だけでは一致しない (部分文字列一致の下界も固定する)。
+    ["<?php \$p = 'bootstrap/app.phpx';", false, true, false],
+    ["<?php \$p = 'bootstrap/application.php';", false, false, false],
+    ["<?php \$p = 'fake-wiring-probe.txt';", false, false, false],
+]);
+
+test('G-7 走査器の見本検査: 共通の起動器への静的呼び出しを完全修飾名で判定する', function (
+    string $sample,
+    bool $expected,
+): void {
+    expect(phpBootProbeCallsBootProbeRunner('tests/Sample.php', $sample))->toBe($expected, $sample);
+})->with([
+    // --- 正例: 完全修飾名が起動器に解決される 3 形 ---
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::run([]);', true],
+    ['<?php Tests\Support\Process\BootProbeRunner::run([]);', true],
+    ['<?php \Tests\Support\Process\BootProbeRunner::run([]);', true],
+    // ★別名つき取り込みも**解決するので検出する** (短名一致では黙っていた形)。
+    ['<?php use Tests\Support\Process\BootProbeRunner as Runner; Runner::run([]);', true],
+    // --- 負例: 同名の別クラス (短名一致なら誤検出していた形) ---
+    ['<?php use Other\BootProbeRunner; BootProbeRunner::run([]);', false],
+    ['<?php Other\BootProbeRunner::run([]);', false],
+    // 取り込みが無い短名は「現在の名前空間の下」に解決されるので起動器ではない。
+    ['<?php BootProbeRunner::run([]);', false],
+    // --- 負例: 接頭辞つき・接尾辞つきのクラス名 / 接尾辞つきのメソッド名 ---
+    ['<?php use Tests\Support\Process\OtherBootProbeRunner; OtherBootProbeRunner::run([]);', false],
+    ['<?php use Tests\Support\Process\BootProbeRunnerX; BootProbeRunnerX::run([]);', false],
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::runner([]);', false],
+    // --- 負例: 呼び出しではない形 ---
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::RUN;', false],
+    ['<?php use Tests\Support\Process\BootProbeRunner;', false],
+    ['<?php // BootProbeRunner::run(', false],
+    ['<?php $s = "BootProbeRunner::run(";', false],
+    // --- 負例: 受け手が静的に確定できない形は**証拠に数えない** (存在を主張する検査のため) ---
+    ['<?php $runner = Tests\Support\Process\BootProbeRunner::class; $runner::run([]);', false],
+]);
````
