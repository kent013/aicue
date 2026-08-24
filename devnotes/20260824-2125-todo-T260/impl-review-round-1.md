仮説「名前解決・分割代入・構造判定の境界で fail-open が起きる」を中心に確認しました。実装本体の集約方針は設計と一致していますが、走査器に実際の見逃し経路があるため承認できません。

### `tests/Support/RawEnv/RawEnvDirectWriteScanner.php`

判定: 要修正

- [Critical] `analyseFileContext()` が `use function` の別名表をファイル全体で1つしか持っておらず、「名前空間ごとに取り込み対応表を持つ」という設計を実装していません。複数 namespace では `$context['unresolved']` を立てていますが、`classifyFunctionCall()` はその判定より先に、最後に上書きされた別名表から候補かどうかを絞っています。

  例えば次の形では、最初の `setRawEnv()` が検出されません。

  ```php
  namespace A;
  use function putenv as setRawEnv;
  setRawEnv('K=V');

  namespace B;
  use function Acme\noop as setRawEnv;
  ```

  後半の import が別名表を上書きし、最初の呼び出しは `putenv` 候補から外れて `null` になります。許可外ファイルでも gate を通過できる、i12 の直接的な fail-open です。名前空間ごとの文脈を保持し、呼び出し位置に対応する文脈で解決してください。

- [Critical] 分割代入の lvalue 判定が keyed destructuring と参照 target を見逃します。分割代入範囲に入ると、直前が `[`, `(`, `,` でなければ参照判定へ進まず `null` を返すため、少なくとも次が非検出になります。

  ```php
  ['key' => $_SERVER['K']] = $value;
  [&$_ENV['K']] = $value;
  ```

  前者は直前が `=>`、後者は `&` です。どちらも列挙対象である直接書き込みです。分割代入の各要素について、key 式と value target を分離して解析したうえで、参照記号を含む target を認識する必要があります。

### `tests/Unit/Architecture/RawEnvDirectWriteScannerTest.php`

判定: 要修正

- [Warning] 上記2件を捕捉する負例がありません。最低限、次を追加してください。

  - 複数 namespace で同じ別名が別の完全修飾関数へ上書きされるケース
  - keyed destructuring の value target
  - 参照つき destructuring target
  - keyed destructuring の key 側にある `$_SERVER` が読み出しなら誤検出しないケース

### `tests/Support/RawEnv/RawEnvGuardStructure.php`

判定: 要修正

- [Critical] `conditionMatches()` は変数・演算子・右辺らしいトークンが条件内のどこかに存在することしか確認していません。演算子が対象変数に結び付いていなくても通ります。

  ```php
  if (! $applied && $other === false) {
      $failed[] = $key;
  }
  ```

  この条件にも `$applied`、`T_IS_IDENTICAL`、`false` が存在するため、`$applied === false` と誤認します。また `$failed !== []` は右辺について `[` があることしか見ていません。

  動的に検査できない性質の唯一の代替保証なので、正規化した条件全体を `['$applied', '===', 'false']`、`['$failed', '!==', '[', ']']` と完全一致させるなど、対応関係を固定してください。

- [Warning] `constructions()` が `str_ends_with($name, '\\'.$class)` で短名一致しています。これは AGENTS.md の「クラス参照は完全修飾名で突き合わせる」に直接反します。`Vendor\RuntimeException` や別クラスを `RuntimeException` と誤認できます。`use` と別名を解決するか、受理構文を完全修飾名へ限定してそれ以外を未解決として落とす必要があります。

### `tests/Unit/Architecture/RawEnvGuardStructureTest.php`

判定: 要修正

- [Warning] `conditionMatches()` の結合関係を壊した負例と、同じ短名を持つ別クラスの負例がありません。現状の自己検査は上記の誤判定をすべて緑にします。
- [Suggestion] `callArguments()` の括弧不整合を例外にする分岐も、自己検査で直接固定すると fail-closed の根拠が明確になります。

### `tests/Unit/Support/RawEnv/RawEnvSnapshotContractTest.php`

判定: 要修正

- [Warning] h-3 は上記の不完全な `restoreStructureIsDeferred()` をそのまま使うため、「`$applied === false` のときだけ蓄積する」という契約を現状は固定できていません。走査器の修正と、条件の結合関係を崩した負例の追加が必要です。

### `tests/Architecture/RawEnvDirectWriteGateTest.php`

判定: 要修正

- [Warning] G4 が自己参照的です。走査側も検査側も `RAW_ENV_DIRECT_WRITE_EXCLUDED_PREFIX` を使っているため、この定数を `tests/` などへ変えても、床値を満たす限りG3〜G5は緑になり得ます。「除外は `devnotes/` だけ」を固定するなら、少なくとも定数値そのものを独立した期待値 `'devnotes/'` と比較してください。

### `docs/template-divergence.md`

判定: 要修正

- [Warning] D53 の次の保証は、同じエントリ内の許可3か所および走査器の保証範囲と矛盾します。

  > テストが生の環境変数 3 面を触る経路は `RawEnvSnapshot` の 1 本だけ

  契約テストは意図的に直接操作し、`tests/bootstrap.php` も直接操作します。また間接呼び出しなどは保証外です。「走査器が列挙した字句上の直接書き込みは、許可3か所以外に存在しない」に統一してください。

### 問題を認めなかったファイル

以下は提示差分の範囲では設計との不一致や追加の正確性問題を認めませんでした。

- `docs/app-integration-guide.md`
- `scripts/ci/pgsql_test_conn.php`
- `tests/Architecture/IntegrationGuideGateTableSyncTest.php`
- `tests/Feature/Auth/PasskeyOriginDeclarationTest.php`
- `tests/Feature/Config/ConfigHardeningTest.php`
- `tests/Feature/Support/ProductionEnvGuardTest.php`
- `tests/Support/RawEnv/RawEnvChannels.php`
- `tests/Support/RawEnv/RawEnvDirectWriteAllowance.php`
- `tests/Support/RawEnv/RawEnvSnapshot.php`
- `tests/Support/RawEnv/RawEnvWriteKind.php`
- `tests/Support/RawEnv/RawEnvWriteSite.php`
- `tests/Support/TemplateDivergence/LedgerPins.php`
- `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php`
- `tests/Unit/Support/Process/BootProbeRunnerTest.php`

PHPStan上の型 widen、虚偽の `@var`、`@phpstan-ignore` は提示差分には認めませんでした。DTO/JsonResource、DESIGN.md、Atomic Designはフロント/API変更がなく、想定どおり非該当です。

### 検証結果

- [Warning] 必須コマンドのうち `pnpm test` と `pnpm test:packages` の実行結果が提示されていません。AGENTS.md は全コマンド green を完了条件としているため、修正後にこの2本を含む全数結果が必要です。

CHANGES_REQUESTED