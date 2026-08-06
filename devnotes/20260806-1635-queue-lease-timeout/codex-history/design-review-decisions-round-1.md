# 対応マトリクス: design-review Round 1

全 6 指摘 (Critical 4 / Warning 5 / 判定 REQUEST_CHANGES 4 施策) に対し、**全件対応**した。反論はゼロ。

## [Critical] 施策 2: self-test の `php -r` が `vendor/autoload.php` を require していない

- 判断: **対応する**
- 根拠: `config/queue.php` は `env()` を呼ぶ。既存 [y2] が `require "vendor/autoload.php";` を
  入れているのはまさにこの理由。写経漏れだった。
- 対応内容: (y4) の `php -r` に `require "vendor/autoload.php";` を追加し、
  失敗時は `__php_failed__` 相当で fail させる (既存 [y2] と同じ流儀)。

## [Critical] 施策 3: `config/queue.php` の env 依存を固定していない (施策 0 と同根)

- 判断: **対応する**
- 対応内容: 規則 1 gate に
  `規則 1: database の retry_after は env で上書きできない` を追加する。
  `Illuminate\Support\Env::getRepository()->set('DB_QUEUE_RETRY_AFTER', '1')` を
  try/finally で仕掛けたうえで `config/queue.php` を**新規 require** し、
  それでも 600 であることを検証する
  (`env('DB_QUEUE_RETRY_AFTER', 600)` への後退を検出できる)。

## [Critical] 施策 4: `dispatch(...)->onConnection()` を見逃す

- 判断: **対応する** (設計の書き方が曖昧で、Codex の読み方が正しい)
- 対応内容: 走査対象を **`app/` 配下の全 PHP ファイル**と明記し、
  許可条件を「**目録登録済み queued class 内の `$this->onConnection('リテラル')` のみ**」と
  書き直す。宣言元クラスが `null` (クラス外) でも、queued でないクラス
  (Controller / Service) 内でも、`onConnection` 系 site が 1 つでもあれば**違反**。

## [Critical] 施策 4: `connection` プロパティ検出が `function foo(string $connection)` を誤検出する

- 判断: **対応する**
- 対応内容: トークン走査に **brace depth と paren depth の追跡**を規定する。
  - クラス宣言の `{` で `classDepth` を記録し、`$connection` / `$timeout` を
    プロパティ宣言とみなすのは **`braceDepth === classDepth + 1` かつ `parenDepth === 0`**
    のときだけ (= クラス直下の property statement)。
  - 関数のパラメータリスト内 (`parenDepth > 0`) と関数本体
    (`braceDepth > classDepth + 1`) は対象外。

## [Critical] 施策 4: `declaredJobTimeout()` が constructor 代入の `$timeout` を見逃す

- 判断: **対応する**
- 対応内容: `$timeout` の許容形を
  「**正の int デフォルト値を持つプロパティ宣言 (`public int $timeout = N;`) のみ**」に限定する。
  トークン走査側で
  - `$this->timeout = ...` 代入 → **違反** (「実行時に決まる `$timeout` は静的検査できない」)
  - デフォルト値の無い `$timeout` プロパティ宣言 → **違反**
  を検出する。`Reflection::getDefaultProperties()` はこの制約下でのみ信頼できる、と明記する。
  (実査: 現状 `RunManualAnalysis` / `RunManualRender` の 2 件だけで、
  どちらも `public int $timeout = N;` の形なので既存コードは通る。)

## [Warning] 施策 0: env 上書きが無視されることをテストで固定していない

- 判断: **対応する** (上記 Critical と同じテストで閉じる)
- 対応内容: 施策 0 のテスト計画に、規則 1 gate 側へ追加するケースを参照として書く
  (テストを 2 箇所に分けない)。

## [Warning] 施策 2: `set -u` 下での未定義参照

- 判断: **対応する**
- 対応内容: `local timeout="${BUGHUNT_WORKER_TIMEOUTS[${conn}]:-}"` に一度受け、
  空なら fail、その後に数値比較する。**起動行も同じ形**にする
  (`${BUGHUNT_WORKER_TIMEOUTS[${conn}]}` の直参照をやめる)。

## [Warning] 施策 3: 数値直書き検出が `--timeout 1800` (空白区切り) を素通しする

- 判断: **対応する**
- 対応内容: 禁止パターンを `--timeout(=|[[:space:]]+)[0-9]+` に広げる。
  `parseQueueWorkerCommand()` 側も `--timeout N` (空白区切り) を既に扱う規定なので整合する。

## [Warning] 施策 4: `onConnection` リテラルが複数ある場合の扱いが未定義

- 判断: **対応する**
- 対応内容: 各目録クラスにつき `onConnection` site は **0 件または 1 件のみ**許可し、
  複数検出は fail (「接続を 2 回指定している = どちらが効くか読み手に分からない」)。

## [Warning] 施策 5: `Worker` の構築依存とテスト用 Job が未指定

- 判断: **対応する**
- 対応内容:
  - 匿名継承クラスをやめ、**`Closure::bind()` で `app('queue.worker')` の
    protected メソッドを呼ぶ**形に変更する (constructor 依存を書かずに済む)。
  - テスト用 Job を `tests/Support/Queue/` に 2 本新設して明記する
    (`app/` 配下ではないので施策 4 の目録走査を汚さない)。
  - `Queue::connection('database')->push(...)` → `pop()` の手順を明記する
    (テスト env は `QUEUE_CONNECTION=sync` なので接続名を必ず明示する)。

## [Warning] 施策 5: 「新規 2 テストはすべて Architecture」の記述と矛盾

- 判断: **対応する**
- 対応内容: 「施策 3 / 4 の 2 テストは Architecture (DB 不使用)、施策 5 は Feature
  (`RefreshDatabase`)」と書き分ける。
