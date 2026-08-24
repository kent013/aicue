# impl-review Round 2

Round 1 の指摘への対応と、Round 1 で不足していた受入条件の証跡を提出する。
**対応マトリクスの全文**は `devnotes/20260823-0022-boot-probe-runner-unification/codex-history/impl-review-decisions-round-1.md`
に保存した。要点は以下。

---

## [Critical] 1. P-14 / P-11 が正規化されていないパスを `isInside()` へ渡す → **対応した**

指摘のとおり fail-open だった。配下判定の**前に**通す検査を新設した:

```php
function externalFakeProbeAssertNormalizedPath(string $path, string $label): void
{
    expect(str_starts_with($path, DIRECTORY_SEPARATOR))
        ->toBeTrue("書き出し先 {$label} が絶対パスでない: {$path}");

    $segments = explode(DIRECTORY_SEPARATOR, $path);
    $suspicious = array_values(array_filter(
        $segments,
        static fn (string $segment): bool => $segment === '.' || $segment === '..',
    ));

    expect($suspicious)->toBe([], "書き出し先 {$label} が正規化されていない (. / .. を含む): {$path}");
}
```

P-11 (`config_cache`) と P-14 (書き出し先 8 種すべて) の両方から呼ぶ。
docblock に「なぜ配下判定の前に置くのか」(= `isInside` の契約は realpath 済みだが、
まだ存在しないファイルは realpath できない) を書いた。

## [Critical] 2. G-6 の短名一致が規約 (a) 違反 → **対応した (設計の前提が誤っていた)**

**指摘が正しい。** 詳細設計は「aicue は名前解決器を持たない」を根拠に字句の末尾要素一致を選んでいたが、
これは `nikic/php-parser` を直接依存に持たないことの言い換えにすぎず、aicue には
**`Tests\Support\PhpReferenceScanner`** が実在した。同クラスの docblock は

> 「emit する `name` は**必ず完全修飾名まで解決済み**である。PHP の名前解決規則をそのまま写す」
> 「(`AGENTS.md` の「静的検査 (gate) と走査器の共通規約」(a))」

と明記しており、`use` / group use / 別名 / 名前空間 / 未解決の 3 状態表現まで備えている
(`ExternalSeamInventoryTest` などが既に乗っている基盤)。設計の前提が事実として誤っていたので、
**設計より Codex の指摘と AGENTS.md を優先**して作り直した:

```php
function phpBootProbeCallsBootProbeRunner(string $relativePath, string $source): bool
{
    foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
        if ($site->kind !== ReferenceKind::StaticCall || $site->name !== 'run') {
            continue;
        }
        if (! $site->receiver->isResolved()) {
            continue;   // 未解決は「呼んでいる証拠」に数えない (存在を主張する検査のため)
        }
        if ($site->receiver->fqcn() === PHP_BOOT_PROBE_RUNNER_FQCN) {
            return true;
        }
    }
    return false;
}
```

見本表も差し替え、**両方向**を固定した:

| 検体 | 判定 | 旧実装との差 |
|---|---|---|
| `use …\BootProbeRunner; BootProbeRunner::run([]);` | あり | 同じ |
| `Tests\Support\Process\BootProbeRunner::run([]);` | あり | 同じ |
| `\Tests\Support\Process\BootProbeRunner::run([]);` | あり | 同じ |
| `use …\BootProbeRunner as Runner; Runner::run([]);` | **あり** | **旧は「なし」= 別名 1 つで黙っていた** |
| `use Other\BootProbeRunner; BootProbeRunner::run([]);` | **なし** | **旧は「あり」= 同名の別クラスを誤検出** |
| `Other\BootProbeRunner::run([]);` | なし | 旧は「あり」 |
| `BootProbeRunner::run([]);` (取り込み無し) | なし | 旧は「あり」 |
| `OtherBootProbeRunner::run(` / `BootProbeRunnerX::run(` / `BootProbeRunner::runner(` / `::RUN;` | なし | 同じ |
| `$runner::run([]);` (受け手が未解決) | なし | — |

**実測での裏取り**: 実ファイルの `use Tests\Support\Process\BootProbeRunner;` を
`use Tests\Support\ExternalFakes\BootProbeRunner;` (同名・別名前空間) へ差し替えると
**G-6 が赤**になることを確認した。**これはまさに Codex が挙げた回避形であり、旧実装では緑のまま通っていた。**
gate の docblock の「名前の解決を一切行わない」という記述も全面的に書き直した。

## [Critical] 3. 軸 B / C が素の部分一致で負例 3 形を持たない → **対応した (意味論は変えない)**

規約 (e) は**語彙一致**の条であり、軸 B / C は「文字列トークンの中にパスの綴りが現れるか」という
**部分文字列一致**なのでトークン完全一致へ寄せる対象ではない (寄せると
`__DIR__.'/fake-wiring-probe.php'` のような正当な形まで落ちる)。
一方で **「`not-bootstrap/app.php` をどう扱うかが固定されていない」という実質の指摘は正しい**ので、
**判定は変えずに期待値として固定**した:

| 検体 | 軸 B | 軸 C | 意図 |
|---|---|---|---|
| `'vendor/bootstrap/app.php'` | **一致** | — | 接頭辞つき |
| `'not-bootstrap/app.php'` | **一致** | — | 打ち消しつき |
| `'bootstrap/app.php.bak'` | **一致** | — | 接尾辞つき |
| `'old-fake-wiring-probe.php'` | — | **一致** | 接頭辞つき |
| `'fake-wiring-probe.php.disabled'` | — | **一致** | 接尾辞つき |
| `'bootstrap/application.php'` | 不一致 | — | 下界 (針の一部だけでは一致しない) |
| `'fake-wiring-probe.txt'` | — | 不一致 | 下界 |

3 形とも**一致する = 申告が要る側へ倒れる**。**見逃す方向ではなく拾いすぎる方向**なので
規約 (b) の許す側であり、紛らわしい綴りを足した人には「1 行申告する」摩擦だけが掛かる。
この非対称を gate の docblock にも書いた。

## [Critical] 4. 自己検査 S9 / S10 の子がリポジトリの `.env` を読む → **本 TODO では実行できない (上流の議題)**

指摘の事実関係は認める。ただし 3 点の理由で本 TODO では変更しない:

1. **編集できない**。当該ファイルはテンプレートから**バイト一致で取り込んだ共有ファイル**であり、
   1 バイトでも変えると意図的逸脱の登録 (`LedgerPins::DIVERGENCE_ENTRY_COUNT` の更新を伴う) が要る。
   詳細設計は「取り込み 3 本を編集しない」を明示的な受入条件にしている
2. **今回のセキュリティ要件の対象は経路 1 である**。「子プロセスへ実資格情報を渡さない」は
   `fake-wiring-probe` の観測 (P-6 / P-7 / P-9) が担う契約で、そちらは専用の一時環境ファイルへ固定し、
   プロセス環境に `TESTING_FAKE_*` を 1 件も載せないことを**完全一致で pin**している。
   **この TODO でその保証は 1 ミリも後退していない** (むしろ P-7 は 4 段の完全一致 pin へ強化した)
3. **S9 / S10 の子は資格情報を外へ出さない**。報告するのは書き出し先のパス 8 種と `PHP_BINARY` だけで、
   設定値も鍵も出力しない。runner の基底が `APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE` を
   上書きするので DB / キューにも触れない

**上流 (家系の機能台帳 feature `subprocess-boot-probe-harness`) への申し送り候補として記録した。**
本セッションは台帳への書き込みを禁じられているため起票は行わない。
`BootProbeResult` の PHPDoc の食い違い (Warning) も同じ扱いで記録した
(**呼び出し側はこの誤りに依存していない** — `interpret()` は `exitCode === 124` を読まず
`timedOut` で判定し、その理由を docblock に書いてある)。

## [Warning] 5. P-10d が再帰作成した親を戻さない → **対応した**

作成前に「実在しない祖先」を深い順に列挙し、浅い順に 1 段ずつ作り、`finally` で深い順に戻す:

```php
$createdAncestors = [];   // 深い順
for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
    $createdAncestors[] = $candidate;
}
foreach (array_reverse($createdAncestors) as $directory) {
    expect(mkdir($directory, 0755))->toBeTrue("後始末の対象を作れない: {$directory}");
}
// … finally: foreach ($createdAncestors as $d) { rmdir($d); }
```

## [Suggestion] 6. P-10b が「子を起こさなかった」ことを直接観測していない → **対応した**

`toThrow(RuntimeException::class, '観測用の置き場所を使用できない')` として**失敗した段**まで固定した
(この message は置き場所の検査 = 子を起こす前だけが投げる)。

---

# [Critical] 7. 受入条件の証跡 (Round 1 で不足していた分)

## 7-1. テストレーン

| コマンド | 結果 |
|---|---|
| `composer test` (`--parallel`) | **6485 tests / 6483 passed / 0 failed / 2 skipped** (下記の 3 走行のうち 2 走行。残り 1 走行は無関係な flaky。7-3 参照) |
| `pnpm test` | exit 0 |
| `pnpm test:packages` | exit 0 |
| `composer phpstan` | `[OK] No errors` (解析対象 1010 ファイル) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` / `pnpm build` | いずれも exit 0 |
| `pnpm typecheck:packages` / `pnpm build:packages` | いずれも exit 0 |

取り込んだ 3 ファイルの sha256 は**全工程を通じて取得時の値のまま**である
(`composer fix` 実行後・測定用 stash の往復後にも再確認した)。

## 7-2. 実行時間の測定 — **判定を報告する前に、測定が成立していないことを先に言う**

詳細設計は「(a) 実装前の全体 / (b) 実装後の全体 / (c) 新規分だけ を各 3 回、中央値で
`(b) − (a) − (c)` が `(a)` の 5% 以内」と定めていた。実測:

| 測定 | 3 回の値 (秒) | 中央値 | **群内のばらつき (max−min)** |
|---|---|---|---|
| (a) 実装前の全体 | 618.7 / 666.0 / 765.8 | **666.0** | **147.0** |
| (b) 実装後の全体 | 610.7 / 728.6 / 747.2 | **728.6** | **136.5** |
| (c) 新規分だけ (57 tests) | 12.6 / 12.1 / 12.4 | **12.4** | 0.5 |

- 中央値による判定: `728.6 − 666.0 − 12.4 = **50.2 秒**` / 閾値 `666.0 × 5% = **33.3 秒**`
  → **閾値を超えている**
- **ただし (a) 群内のばらつきだけで 147.0 秒あり、閾値 33.3 秒の 4.4 倍である。**
  つまり **この環境では 5% の判定が成立しない** (残差 50.2 秒は完全に雑音の内側)
- **原因** (閾値は動かしていない): 測定中、同一ホスト上で**他の 4〜5 個の worktree
  (T244 / T245 / T247 / T250 / T251) が並行して実装セッションを回していた**。
  グローバルテストロックが直列化するのは**テストレーンだけ**で、各エージェントの
  PHPStan・pnpm build・codex 呼び出し・git 操作は並行して走るため、同一コードでも
  走行ごとに ±20% 変動する。ロック取得待ちだけで 1 走行あたり 20〜40 分掛かった
- **雑音の影響を最も受けない見方 (最小値どうし)**:
  `min(b) − min(a) − median(c) = 610.7 − 618.7 − 12.4 = **−20.4 秒**` (= 増分なし)
- **解釈**: (c) が 12.4 秒であることは安定して測れている (ばらつき 0.5 秒)。
  詳細設計が予想した「新規ファイルの固定コストを差し引いた残りはほぼ 0」と整合する。
  S5 の載せ替えを取りやめた以上、既存テストの実行経路は 1 行も変わっていない
  (差分は `tests/` 配下の新規 4 本と変更 4 本のみで、既存テストが読む共有コードは
  `FakeWiringProbeRunner` だけ。その利用者は `ExternalFakeBootProbeTest` 1 本である)

**判定を求めたい点**: この測定を「閾値超過につき CHANGES_REQUESTED」と読むべきか、
「環境の雑音により判定不能。ただし増分の実体は (c) = 12.4 秒で説明でき、
最小値比較では増分なし」と読むべきか。**閾値は動かしていない**。

## 7-3. 3 走行のうち 1 走行で出た赤 — **T249 とは無関係な flaky である**

`(b)` の 2 本目 (`after_3.log`) で 1 件だけ赤が出た:

```
P\Tests\Architecture\BughuntSelfTestExecutionTest:: bug-hunt harness の self-test が通ること
  FAIL: [y6b] 停止不能 group なのに rc=0
  FAIL: [y6b] 停止失敗時に pidfile が削除された (追跡情報喪失)
  error: shard-8 worker (database-media) pid=2931111 は存在するが所有確認できない
         — kill せず pidfile 保持
```

無関係であることの根拠:

1. **T249 の差分は bug-hunt を 1 行も触らない** (差分は `tests/Support/Process/` の新規 2 本、
   `tests/Unit/Support/Process/` の新規 1 本、`tests/Architecture/` の新規 1 本 + 変更 1 本、
   `tests/Support/ExternalFakes/` の変更 2 本、`tests/Support/StrictTypesRuntimeProbe.php` の
   docblock 1 か所のみ)
2. **実測の内訳は 6 勝 1 敗**である — 実装**前**の (a) 3 走行すべてで緑、
   実装**後**の (b) 3 走行のうち 2 走行で緑、さらに**名指しの再走行で緑**
   (`composer test -- --filter=BughuntSelfTestExecutionTest` → 3 tests / 3 passed)
3. **失敗した検査の性質が負荷依存**である。`[y6b]` は「停止できない**プロセスグループ**を
   停止しようとしたとき rc≠0 になり pidfile を保持する」ことを見る検査で、
   合成した pid の**所有確認**に依存する。上記のとおり測定中はホストが極めて高負荷で、
   pid の回転も速かった

**この赤を T249 の回帰とは見なしていない。** 見立てが違うなら指摘してほしい。

---

## 差分の再取得について

上記 1 / 2 / 3 / 5 / 6 の修正を当てた後、以下を再実行して全て緑であることを確認済み:

```
$ vendor/bin/pest tests/Architecture/ExternalFakeBootProbeTest.php \
                  tests/Architecture/PhpBootProbeReferenceInventoryTest.php
result=passed tests=61 passed=61 failed=0
```

修正後の差分は下記に添付する。

## 修正後の差分 (tests/Architecture/ と tests/Support/ExternalFakes/ のみ。取り込み 3 本は無変更)

````diff
diff --git a/tests/Architecture/ExternalFakeBootProbeTest.php b/tests/Architecture/ExternalFakeBootProbeTest.php
index e555fffe..f7a2fff1 100644
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
@@ -90,12 +106,37 @@ function externalFakeProbeRun(string $case): array
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
+function externalFakeProbeAssertNormalizedPath(string $path, string $label): void
+{
+    expect(str_starts_with($path, DIRECTORY_SEPARATOR))
+        ->toBeTrue("書き出し先 {$label} が絶対パスでない: {$path}");
+
+    $segments = explode(DIRECTORY_SEPARATOR, $path);
+    $suspicious = array_values(array_filter(
+        $segments,
+        static fn (string $segment): bool => $segment === '.' || $segment === '..',
+    ));
+
+    expect($suspicious)->toBe([], "書き出し先 {$label} が正規化されていない (. / .. を含む): {$path}");
+}
+
 /**
  * 観測結果の `resolved` を「解決キー => 実際に解決されたクラス」として取り出す。
  *
@@ -182,13 +223,33 @@ function externalFakeProbeResolved(array $output): array
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
@@ -197,19 +258,43 @@ function externalFakeProbeResolved(array $output): array
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
@@ -225,7 +310,7 @@ function externalFakeProbeResolved(array $output): array
         ->toThrow(RuntimeException::class);
 });
 
-test('P-10 正常終了・非ゼロ終了・timeout のいずれでも一時ディレクトリが残らない', function (): void {
+test('P-10 正常終了・非ゼロ終了のいずれでも環境ファイルの置き場所が残らない', function (): void {
     foreach (['fake', 'real', 'production'] as $case) {
         $run = externalFakeProbeRun($case);
 
@@ -233,27 +318,124 @@ function externalFakeProbeResolved(array $output): array
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
 });
 
-test('P-11 設定キャッシュの指し先は一時ディレクトリ配下の絶対パスで、存在しない', function (): void {
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
+});
+
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
@@ -261,3 +443,60 @@ function externalFakeProbeResolved(array $output): array
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
diff --git a/tests/Architecture/PhpBootProbeReferenceInventoryTest.php b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
new file mode 100644
index 00000000..bdfb1b9a
--- /dev/null
+++ b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
@@ -0,0 +1,536 @@
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
+ * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', reason: non-empty-string}>
+ */
+function phpBootProbeAppBootEntryReferenceInventory(): array
+{
+    return [
+        'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
+            'kind' => 'child_entry',
+            'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
+        ],
+        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
+            'kind' => 'child_entry',
+            'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
+        ],
+        'tests/TestCase.php' => [
+            'kind' => 'in_process',
+            'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
+        ],
+        'tests/Support/Cache/IsolatedApplicationProbe.php' => [
+            'kind' => 'in_process',
+            'reason' => 'キャッシュ受け皿の結線を測るための第 2 のアプリを同一プロセスで組み立てる。子プロセスではない',
+        ],
+        'tests/Architecture/CacheGuardWiringGateTest.php' => [
+            'kind' => 'inventory',
+            'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
+        ],
+        'tests/Architecture/BughuntExecutedRouteOrderingTest.php' => [
+            'kind' => 'inventory',
+            'reason' => '記録器の位置を固定する検査が、違反時の直し方を案内する診断文にパス文字列を持つ',
+        ],
+        'tests/Architecture/InertiaErrorScreenContractTest.php' => [
+            'kind' => 'inventory',
+            'reason' => '例外応答の最終整形スロットの登録位置を検査する側が、照合する場所としてパス文字列を持つ',
+        ],
+        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
+            'kind' => 'inventory',
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
diff --git a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
index 7002bdf6..5e13009e 100644
--- a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
+++ b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
@@ -6,30 +6,58 @@
 
 use JsonException;
 use RuntimeException;
-use Symfony\Component\Process\Process;
+use Tests\Support\Process\BootProbeResult;
+use Tests\Support\Process\BootProbeRunner;
 
 /**
  * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
  *
- * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
- * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
- *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
- *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
- * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
- *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
- *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
- *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
- *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
- * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
- *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
- *    並列実行と衝突しない)
+ * ★**子の起こし方・回収・書き出し先の退避は共通の起動器**
+ *   (`Tests\Support\Process\BootProbeRunner`) が持つ
+ *   (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+ *   本クラスに残るのは「観測用の環境ファイルを安全に用意すること」と
+ *   「子の出力を解釈すること」の 2 つだけである。
  *
- * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
- *   **使い捨ての値をその場で生成する** (観測は解決と経路の組み立てだけで、既存データの
- *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
- * ★それでも置き場所は保護する: 専用の一時ディレクトリを 0700 で作り、環境ファイルは
- *   作成時点から 0600 にする。起動前に権限を確かめ、0600 でなければ**子を起こさずに失敗させる**。
- *   後片付けは finally で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
+ * ## 1. 子の環境は 4 段で決まる
+ *
+ * 継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE`) →
+ * ケース別 (本クラスの `CASE_ENV_KEYS` の 3 件) → 予約 (書き出し先 7 キー。起動器が決める)。
+ * **統制点は `proc_open` へ渡す環境配列**である — 子はその配列だけを受け取るので、
+ * 開発者ローカルの env (`TESTING_FAKE_*` / DB 資格情報など) はここで締め出される。
+ * 後ろの段が前の段に勝つので、ケース別上書きは基底に勝つ。
+ *
+ * ## 2. 使い捨て鍵の置き場所は 2 つに分かれる
+ *
+ * `APP_KEY` は**ケース別上書き**、`CIPHERSWEET_KEY` は**環境ファイル**に置く。
+ * Laravel の環境変数リポジトリは **immutable** で、**プロセス環境に既に在る値を Dotenv は
+ * 上書きしない**ためである。起動器の基底が `APP_KEY` を載せる以上、環境ファイルへ書いた
+ * 使い捨て鍵は無視される (設計時に子プロセスで実測して確定した)。
+ * どちらの鍵も**親の実鍵を複写しない** — 起動のたびにその場で生成する
+ * (観測は解決と経路の組み立てだけで、既存データの復号も DB 接続もしないため実鍵は要らない)。
+ *
+ * ## 3. 一時ディレクトリが 2 つある
+ *
+ *  - **外側**: 本クラスが作る**環境ファイルの置き場**。0700 で作り、環境ファイルは 0600。
+ *    起動前に実効の権限を確かめ、違えば**子を起こさずに失敗させる**。
+ *    後片付けは `withEnvironmentDirectory()` の `finally` が行い、本体がどう終わっても通る
+ *  - **内側**: 起動器が作る**書き出し先の退避先**。子の storage / 設定キャッシュ等はここへ向く
+ *
+ * どちらも**リポジトリの外**であることを起動前に確かめる (正典 v1 (5) の fail-closed)。
+ * 境界の判定は `BootProbeRunner::isInside()` を使う (規則を 2 か所で持たない)。
+ *
+ * ## 4. 設定キャッシュの退避先は起動器の予約鍵である
+ *
+ * `APP_CONFIG_CACHE` ほか 7 キーは起動器が一時ディレクトリから導く**予約鍵**なので、
+ * 本クラスからは渡せない (渡すと `BootProbeRunner::run()` が例外にする)。
+ *
+ * ## 5. 取り込んだ `BootProbeRunner` の docblock の訂正 (向こうはバイト一致なので直せない)
+ *
+ * | 取り込んだ記述 | aicue での実際 |
+ * |---|---|
+ * | 「外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 **15**)」 | aicue の外部到達点の目録は **セキュリティ不変条件 9** である |
+ * | 「同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`」 | aicue では `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない) |
+ *
+ * **趣旨 (`tests/` 専用であり `app/` へ持ち出さない) は aicue でもそのまま成り立つ。**
  *
  * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
  * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
@@ -37,31 +65,44 @@
  */
 final class FakeWiringProbeRunner
 {
+    /**
+     * 子が実働証明の印を書く先 (`storage_path()` からの相対パス)。
+     *
+     * ★正典 v1 (5) の実働証明の観測点。退避が効いていなければ印はリポジトリ側へ落ち、
+     *   起動器の `writtenRelativePaths` に現れない = P-13 が赤になる。
+     */
+    public const string MARKER_RELATIVE_PATH = 'app/private/fake-wiring-probe-marker.txt';
+
     /**
      * 一時環境ファイルに書いてよいキー (deny-by-default)。
-     * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
+     * 実資格情報のキーは 1 つも無く、鍵は使い捨ての生成値である。
+     *
+     * ★`APP_KEY` は**ここに置けない**。Laravel の環境変数リポジトリは immutable で、
+     *   プロセス環境に既に在る値を Dotenv は上書きしない。BootProbeRunner の基底が
+     *   `APP_KEY` を載せる以上、ここへ書いても無視される (設計時に子プロセスで実測)。
+     *   使い捨て `APP_KEY` は CASE_ENV_KEYS 側 (ケース別上書き) が運ぶ。
      *
      * @var list<string>
      */
     public const array ALLOWED_ENV_FILE_KEYS = [
-        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
+        'APP_ENV', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
         'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
     ];
 
     /**
-     * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
-     * `env -i` で空にしたうえでこの 3 つだけを載せる。
+     * BootProbeRunner へ渡す**ケース別上書き**のキー (正典 v1 (2) の第 3 段)。
      *
-     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
-     *   probe が自分で観測して返す。両方を突き合わせて初めて `env -i` の退行が映る。
+     * ★`TESTING_FAKE_*` はここに**無い**。偽物の宣言はプロセス環境へ 1 件も載せず、
+     *   0600 の環境ファイルの中だけに置く (P-7 の危険接頭辞の禁止をそのまま維持する)。
+     * ★`APP_CONFIG_CACHE` ほかの書き出し先は runner の**予約鍵**なので渡さない (渡すと例外)。
+     * ★この一覧は P-7 がリテラルで完全一致 pin する (増やすと赤になる)。
      *
      * @var list<string>
      */
-    public const array ALLOWED_PROCESS_ENV_KEYS = [
+    public const array CASE_ENV_KEYS = [
         'FAKE_WIRING_PROBE_ENV_DIR',
         'FAKE_WIRING_PROBE_ENV_FILE',
-        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
-        'APP_CONFIG_CACHE',
+        'APP_KEY',
     ];
 
     /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
@@ -70,19 +111,91 @@ final class FakeWiringProbeRunner
     /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
     private const string ENV_FILE_NAME = '.env.probe';
 
+    /**
+     * 環境ファイルの置き場所を 0700 で用意し、**本体がどう終わっても必ず消す**足場。
+     *
+     * ★`run()` の `finally` をここへ切り出したのは、**後始末そのものを検査から直接呼べるように**
+     *   するためである (P-10c)。制限時間超過の経路は「`interpret()` が例外を投げる」(P-15) と
+     *   「本体が例外を投げれば中身ごと消える」(P-10c) の合成で覆う。
+     *   **プロセスの挙動を偽装する注入の継ぎ目ではない** — 起こし方も回収も BootProbeRunner のままである。
+     *
+     * ★**リポジトリの中には作らない** (正典 v1 (5) の fail-closed)。内側の退避先は
+     *   BootProbeRunner が同じ検査を持つが、外側 (この環境ファイルの置き場) にも同じ境界が要る。
+     *   判定は BootProbeRunner::isInside() を使う (境界規則を 2 か所で持たない)。
+     * ★権限は callback を呼ぶ**前に**実効値で確かめる。どの失敗でも作った置き場所を消してから投げる。
+     *
+     * @template T
+     *
+     * @param  callable(string): T  $body  引数は作った置き場所の絶対パス
+     * @return T
+     */
+    public static function withEnvironmentDirectory(?string $baseDirectory, callable $body): mixed
+    {
+        $base = $baseDirectory ?? sys_get_temp_dir();
+
+        // ★`Webmozart\Assert` を使わない — あちらは InvalidArgumentException を投げるので、
+        //   呼び出し側の例外契約が RuntimeException と 2 本立てになってしまう。
+        //   この境界は明示検査で RuntimeException に統一する。
+        if (! str_starts_with($base, DIRECTORY_SEPARATOR)) {
+            throw new RuntimeException("観測用の置き場所は絶対パスであること: {$base}");
+        }
+
+        if (! is_dir($base) || ! is_writable($base)) {
+            throw new RuntimeException("観測用の置き場所を使用できない: {$base}");
+        }
+
+        $created = rtrim($base, DIRECTORY_SEPARATOR).'/fake-wiring-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($created, 0700) || ! is_dir($created)) {
+            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$created}");
+        }
+
+        try {
+            $directory = realpath($created);
+            if (! is_string($directory) || $directory === '') {
+                throw new RuntimeException("観測用の一時ディレクトリを正規化できない: {$created}");
+            }
+
+            // 正典 (5) の fail-closed。ここを緩めると環境ファイルがリポジトリへ落ちる。
+            // ★両辺とも realpath 済みで比べる (FakeClassCatalog::repoRoot() は dirname() の結果で
+            //   正規化されていないため、symlink 越しだと素の比較が取り違える)。
+            $repositoryRoot = realpath(FakeClassCatalog::repoRoot());
+            if (! is_string($repositoryRoot) || $repositoryRoot === '') {
+                throw new RuntimeException('リポジトリ root を正規化できない');
+            }
+
+            if (BootProbeRunner::isInside($repositoryRoot, $directory)) {
+                throw new RuntimeException(
+                    "観測用の一時ディレクトリがリポジトリ内にある: {$directory}"
+                );
+            }
+
+            // 実効の権限で確かめる (chmod の戻り値だけでは umask 等の影響を捕まえられない)。
+            if (! chmod($directory, 0700) || self::mode($directory) !== 0700) {
+                throw new RuntimeException("観測用の一時ディレクトリを 0700 にできない: {$directory}");
+            }
+
+            return $body($directory);
+        } finally {
+            self::removeDirectory($created);
+        }
+    }
+
     /**
      * 観測を 1 回走らせる。
      *
-     * @param  string|null  $baseDirectory  一時ディレクトリを作る親 (省略時は sys_get_temp_dir())
+     * @param  string|null  $baseDirectory  環境ファイルの置き場を作る親 (省略時は sys_get_temp_dir())
+     * @param  positive-int  $timeoutSeconds
      * @return array{
      *     exitCode: int,
      *     output: array<string, mixed>,
      *     envFileValues: array<string, string>,
+     *     caseEnvValues: array<string, string>,
      *     directory: string,
      *     directoryMode: int,
      *     envFileMode: int,
-     *     configCachePath: string,
-     *     configCacheExists: bool,
+     *     temporaryRoot: string,
+     *     writtenRelativePaths: list<string>,
      * }
      */
     public static function run(
@@ -91,59 +204,108 @@ public static function run(
         bool $fakeStorage,
         bool $fakeLlm,
         ?string $baseDirectory = null,
-        float $timeout = 120.0,
+        int $timeoutSeconds = 120,
     ): array {
-        $base = $baseDirectory ?? sys_get_temp_dir();
-        $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));
+        // 置き場所の作成・リポジトリ外の fail-closed・0700 の確認・後片付けは helper が持つ。
+        return self::withEnvironmentDirectory(
+            $baseDirectory,
+            static function (string $directory) use ($environment, $fakeExternals, $fakeStorage, $fakeLlm, $timeoutSeconds): array {
+                $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
+                $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
+                self::writeEnvFile($envFilePath, $values);
+
+                $directoryMode = self::mode($directory);
+                $envFileMode = self::mode($envFilePath);
+
+                // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
+                self::assertSafePermissions($directoryMode, $envFileMode);
+
+                $caseEnv = self::caseEnvValues($directory);
 
-        if (! mkdir($directory, 0700) || ! is_dir($directory)) {
-            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
+                // 子の起こし方・回収・書き出し先の退避は共通 runner が持つ
+                // (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+                $result = BootProbeRunner::run([self::probeScriptPath()], $caseEnv, $timeoutSeconds);
+
+                return self::interpret($result, $values, $caseEnv, $directory, $directoryMode, $envFileMode);
+            },
+        );
+    }
+
+    /**
+     * ケース別上書きの中身 (使い捨て鍵はここで作る)。
+     *
+     * @return array<string, string>
+     */
+    public static function caseEnvValues(string $directory): array
+    {
+        $values = [
+            'FAKE_WIRING_PROBE_ENV_DIR' => $directory,
+            'FAKE_WIRING_PROBE_ENV_FILE' => self::ENV_FILE_NAME,
+            // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
+            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
+        ];
+
+        foreach (array_keys($values) as $key) {
+            if (! in_array($key, self::CASE_ENV_KEYS, true)) {
+                throw new RuntimeException("ケース別上書きに置けないキー: {$key}");
+            }
         }
 
-        try {
-            chmod($directory, 0700);
-
-            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
-            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
-            self::writeEnvFile($envFilePath, $values);
-
-            $directoryMode = self::mode($directory);
-            $envFileMode = self::mode($envFilePath);
-
-            // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
-            self::assertSafePermissions($directoryMode, $envFileMode);
-
-            $configCachePath = $directory.'/config-cache-absent.php';
-
-            $process = new Process(
-                [
-                    'env', '-i',
-                    'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
-                    'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
-                    'APP_CONFIG_CACHE='.$configCachePath,
-                    PHP_BINARY,
-                    self::probeScriptPath(),
-                ],
-                FakeClassCatalog::repoRoot(),
-                null,
-                null,
-                $timeout,
+        return $values;
+    }
+
+    /**
+     * runner の結果を観測結果へ翻訳する (**純関数**。子を起こさずに負例を測れる)。
+     *
+     * ★fail-closed を 4 つ持つ:
+     *   1. 制限時間超過 (`timedOut`) は**通常の非ゼロ終了と区別して例外**にする。
+     *      false や非ゼロ終了へ落とすと「観測できなかった」ことが沈黙する (fail-open)
+     *   2. 出力が空 → 例外 (観測が成立していない)
+     *   3. JSON として読めない → 例外
+     *   4. トップレベルが配列でない → 例外
+     * ★判定には `timedOut` を使い、`exitCode === 124` を直接読まない
+     *   (終了要求を受けてから自分で `exit(0)` する子は `timedOut` かつ `exitCode === 0` になりうる)。
+     *
+     * @param  array<string, string>  $envFileValues
+     * @param  array<string, string>  $caseEnv
+     * @return array{
+     *     exitCode: int,
+     *     output: array<string, mixed>,
+     *     envFileValues: array<string, string>,
+     *     caseEnvValues: array<string, string>,
+     *     directory: string,
+     *     directoryMode: int,
+     *     envFileMode: int,
+     *     temporaryRoot: string,
+     *     writtenRelativePaths: list<string>,
+     * }
+     */
+    public static function interpret(
+        BootProbeResult $result,
+        array $envFileValues,
+        array $caseEnv,
+        string $directory,
+        int $directoryMode,
+        int $envFileMode,
+    ): array {
+        if ($result->timedOut) {
+            throw new RuntimeException(
+                '観測用の子プロセスが制限時間を超えて強制終了された (観測が成立していない)。'
+                ."終了コード: {$result->exitCode} / 標準エラー: ".$result->stderr
             );
-            $process->run();
-
-            return [
-                'exitCode' => $process->getExitCode() ?? -1,
-                'output' => self::decode($process->getOutput()),
-                'envFileValues' => $values,
-                'directory' => $directory,
-                'directoryMode' => $directoryMode,
-                'envFileMode' => $envFileMode,
-                'configCachePath' => $configCachePath,
-                'configCacheExists' => file_exists($configCachePath),
-            ];
-        } finally {
-            self::removeDirectory($directory);
         }
+
+        return [
+            'exitCode' => $result->exitCode,
+            'output' => self::decode($result->stdout),
+            'envFileValues' => $envFileValues,
+            'caseEnvValues' => $caseEnv,
+            'directory' => $directory,
+            'directoryMode' => $directoryMode,
+            'envFileMode' => $envFileMode,
+            'temporaryRoot' => $result->temporaryRoot,
+            'writtenRelativePaths' => $result->writtenRelativePaths,
+        ];
     }
 
     /**
@@ -161,7 +323,6 @@ public static function envFileValues(
         // 形式は現行の設定が受理する形に合わせる (妥当性は「子が起動できたこと」自体が示す)。
         $values = [
             'APP_ENV' => $environment,
-            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
             'APP_URL' => self::PROBE_APP_URL,
             'APP_DEBUG' => 'false',
             'CIPHERSWEET_KEY' => bin2hex(random_bytes(32)),
diff --git a/tests/Support/ExternalFakes/fake-wiring-probe.php b/tests/Support/ExternalFakes/fake-wiring-probe.php
index 8c18778b..f0009799 100644
--- a/tests/Support/ExternalFakes/fake-wiring-probe.php
+++ b/tests/Support/ExternalFakes/fake-wiring-probe.php
@@ -6,14 +6,22 @@
 use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Illuminate\Contracts\Console\Kernel;
 use Illuminate\Foundation\Application;
+use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
 use Webmozart\Assert\Assert;
 
 /*
  * 別プロセスで「宣言した差し替えが実際に効いているか」を観測して JSON を書き出す。
  *
- * ★責務は 4 つだけ: DB へ接続しない / container から解決する /
- *   転送先 URL を組み立てて読む / 終了コードを返す。
- *   HTTP サーバもブラウザも起動しない。
+ * ★責務は 6 つだけ:
+ *   1. DB へ接続しない
+ *   2. container から解決する
+ *   3. 転送先 URL を組み立てて読む (**偽物が有効なときだけ**)
+ *   4. **実働証明の印を storage_path() 経由で 1 本書く** (正典 v1 (5))
+ *   5. **起動しきったアプリが解決した書き出し先 8 種と、効いた鍵 2 種の digest を報告する**
+ *   6. 終了コードを返す
+ * ★**観測しないもの**: HTTP サーバもブラウザも起動しない /
+ *   設定キャッシュ**有り**の起動は観測しない / 外部へ 1 度も通信しない
+ *   (転送先は組み立てて URL を読むだけ)。
  * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md §禁止する文)。
  * ★読み込む環境ファイルを**専用の一時ファイルだけ**に固定する (親のチェックアウトの
  *   .env / .env.bughunt.local を読ませない = 実資格情報が子の設定へ入らない)。
@@ -45,6 +53,19 @@
 
     $app->make(Kernel::class)->bootstrap();
 
+    /*
+     * ★正典 v1 (5) の**実働証明**の観測点 (lctl feature: subprocess-boot-probe-harness)。
+     *   「書き出し先を環境変数で退避した」ことは、退避が**効いていなければ**既定の場所
+     *   (リポジトリの storage/) へ書かれ、観測は緑のまま嘘になる。そこで
+     *   Laravel の storage_path() 経由で印を 1 本置き、それが起動器の一時ディレクトリ配下に
+     *   現れたことを呼び出し側 (P-13) が確かめる。
+     *   置き場所 (storage/app/private) は起動器が事前に掘っている。
+     */
+    $markerPath = $app->storagePath(FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
+    if (file_put_contents($markerPath, 'fake-wiring-probe') === false) {
+        throw new RuntimeException("観測の印を書けない: {$markerPath}");
+    }
+
     $resolved = [];
     foreach (ExternalFakeDeclaration::swaps() as $swap) {
         $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
@@ -71,6 +92,23 @@
         'resolved' => $resolved,
         'redirect_host' => $redirectHost,
         'process_environment_keys' => $processEnvironmentKeys,
+        // ★P-14 (向き): 起動しきったアプリが解決した書き出し先。呼び出し側が
+        //   「1 件残らず一時ディレクトリ配下で、リポジトリの外」であることを確かめる。
+        'write_targets' => [
+            'storage' => $app->storagePath(),
+            'config_cache' => $app->getCachedConfigPath(),
+            'routes_cache' => $app->getCachedRoutesPath(),
+            'services_cache' => $app->getCachedServicesPath(),
+            'packages_cache' => $app->getCachedPackagesPath(),
+            'events_cache' => $app->getCachedEventsPath(),
+            'view_compiled' => (string) config('view.compiled'),
+            'log_path' => (string) config('logging.channels.single.path'),
+        ],
+        // ★P-8 (使い捨て鍵が子で効いたこと)。鍵そのものは出力しない (テスト出力へ鍵を流さない)。
+        'key_digests' => [
+            'app' => hash('sha256', (string) config('app.key')),
+            'ciphersweet' => hash('sha256', (string) config('ciphersweet.providers.string.key')),
+        ],
     ], JSON_THROW_ON_ERROR));
 
     exit(0);
````
