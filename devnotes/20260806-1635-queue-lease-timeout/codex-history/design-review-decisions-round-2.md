# 対応マトリクス: design-review Round 2

Critical 2 / Warning 5 のすべてに**対応**した。反論はゼロ。

## [Critical] 施策 3 ケース 6: bash 全文検索だと自分のコメント中の `--timeout=1800` を拾って必ず失敗する

- 判断: **対応する** (指摘のとおり。設計どおり実装すると初日から赤くなる致命的な穴)
- 根拠: 施策 2 で入れるコメント自身が「旧実装は 3 接続すべてに `--timeout=1800`」と書く。
  self-test 側が `declare -f start_shard_workers` を対象にしているのは
  **まさにこれを避けるため**で、Architecture テストだけ全文にしていたのが不整合だった。
- 対応内容: 検査対象を **`start_shard_workers` の関数定義本体からコメント行を除去したもの**に
  限定する。helper を 2 本追加:
  - `extractBashFunction(string $source, string $name): string`
  - `stripBashCommentLines(string $bash): string` (行頭の空白 + `#` で始まる行を除去)
  検査は「`--timeout="${wtimeout}"` を含む」かつ
  「`/--timeout(?:=|\s+)\d+/` にマッチしない」の 2 点。

## [Critical] 施策 4 ケース 3: timeout site まで違反にしてしまい正当な `$timeout` 宣言が落ちる

- 判断: **対応する** (指摘のとおり。`connectionDeclarationSites()` を
  接続と timeout の両方に使うよう拡張したときに母集団の絞り込みを書き忘れた)
- 対応内容: ケース 3 の母集団を**接続関連 kind のみ**に限定する:
  `['onConnection', 'viaConnections', 'viaConnection', 'connectionProperty', 'connectionAssignment']`。
  timeout 関連 kind (`timeoutProperty` / `timeoutAssignment`) はケース 5 だけで扱う。

## [Warning] 施策 4 ケース 5: `app/` 全クラスの `$timeout` を禁止するのは行き過ぎ

- 判断: **対応する**
- 根拠: 規則 2 の対象は `ShouldQueue` クラスである。キューと無関係なクラス
  (HTTP client wrapper 等) の `$timeout` を禁止するのは本不変条件と無関係な副作用。
- 対応内容: ケース 5 の母集団を「**site の `class` が目録キーに含まれるもの**」に限定する。

## [Warning] 施策 4: 単一の `classBodyDepth` では匿名クラス / ネスト宣言で誤帰属する

- 判断: **対応する**
- 対応内容: クラススコープを **`{class: class-string|null, bodyDepth: int}` のスタック**で管理する。
  - 名前付きクラス宣言 → `{class: 'Ns\\Name', bodyDepth: 開き `{` の braceDepth}` を push
  - **匿名クラス (`new class`) も `{class: null, ...}` として push** し、
    その内部の site を外側の queued class に帰属させない
  - 対応する `}` (braceDepth が push 時の値に戻る) で pop
  - site の `class` は**スタック最上位**の値

## [Warning] 施策 2: `wt` / `conn_rt` の数値形式を確認せずに算術比較している

- 判断: **対応する**
- 対応内容: 比較前に `=~ ^[0-9]+$` で形式検査し、不正値は「invariant failure」ではなく
  「値が正の整数でない」という別メッセージで `t_fail` させる (bash の算術評価エラーにしない)。

## [Warning] 施策 3 ケース 7: `finally` の無条件 `clear()` が既存の env 値を破壊する

- 判断: **対応する**
- 対応内容: 実行前に `$repository->has('DB_QUEUE_RETRY_AFTER')` と元の値を保存し、
  `finally` では「元が存在 → `set()` で復元 / 存在しない → `clear()`」に分岐する。

## [Warning] 施策 5: `Closure::bind()` は `Closure|null` を返し PHPStan level 10 で扱いにくい

- 判断: **対応する** (指摘のとおり。`Closure::bind()` の戻りの null 分岐と
  クロージャ内 `$this` の型付けで無駄な `Assert` が増える)
- 対応内容: **`ReflectionMethod::invoke()`** に切り替える
  (PHP 8.1 以降は非 public メソッドでも `setAccessible()` 不要)。
  `$worker` は `app('queue.worker')` のまま = constructor 依存回避という目的も維持できる。
