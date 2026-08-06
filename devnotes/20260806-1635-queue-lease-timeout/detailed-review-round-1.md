**全体判定: CHANGES_REQUESTED**

方針と確定値は概ね整っています。ただし、静的 gate が「守りたい不変条件」を取り逃がす穴が複数あります。特に施策 4 の接続経路検出は、このままだと `dispatch(...)->onConnection()` 系の逸脱を見逃します。

**施策 0: REQUEST_CHANGES**

[Warning] `retry_after` をリテラル化する意図は正しいですが、設計上のテストでは「env 上書きが無視されること」を固定できていません。  
修正案: `DB_QUEUE_RETRY_AFTER=1` を一時設定して `config/queue.php` を直接 require し、それでも `database.retry_after === 600` になる Architecture テストを追加してください。これで `env('DB_QUEUE_RETRY_AFTER', 600)` への後退を検出できます。

**施策 1: APPROVE**

mprocs の接続名明示と `--timeout < retry_after` の是正は妥当です。`logs` を理由付き除外 inventory に入れる方針もよいです。

**施策 2: REQUEST_CHANGES**

[Critical] self-test 追加コードの `php -r` が `vendor/autoload.php` を require していません。`config/queue.php` は `env()` を呼ぶため、単体 PHP 実行では失敗し得ます。  
修正案: 既存 [y2] と同様に `require "vendor/autoload.php";` を入れてください。

[Warning] `BUGHUNT_WORKER_TIMEOUTS[${conn}]` は、キー欠落時に `set -u` 環境で未定義参照になる可能性があります。  
修正案: 一度 `timeout="${BUGHUNT_WORKER_TIMEOUTS[${conn}]:-}"` に受け、空なら fail、その後に数値比較してください。

**施策 3: REQUEST_CHANGES**

[Critical] 規則 1 gate が `config/queue.php` の構文上の env 依存を固定していません。施策 0 と同じく、env override を与えても 600 のままかを検証するケースを追加してください。

[Warning] `bug-hunt の起動行は --timeout を配列経由で渡す` は `--timeout=1800` だけでなく `--timeout 1800` も禁止対象にしてください。  
修正案: 数値直書き検出を `--timeout(=|[[:space:]]+)[0-9]+` に広げる。

**施策 4: REQUEST_CHANGES**

[Critical] `接続の指定は $this->onConnection('リテラル') に限る` が「目録登録済みクラス内」の site だけを違反判定しており、Controller / Service 側の `dispatch(...)->onConnection(...)` を見逃します。  
修正案: `app/` 全体の connection declaration site を対象にし、許可条件を「inventory 登録済み queued class 内の `$this->onConnection('literal')` のみ」としてください。それ以外は class が null / 非 queued class でも fail です。

[Critical] `connection` プロパティ検出規則が粗く、`function foo(string $connection)` のような引数をプロパティ宣言と誤検出し得ます。  
修正案: token parser で class body depth と function body / parameter list を追跡し、クラス直下の property statement のみを対象にしてください。

[Critical] `declaredJobTimeout()` が default properties だけを見るため、`public int $timeout;` + constructor 代入や `$this->timeout = ...` を見逃します。  
修正案: `$timeout` は「正の int デフォルト値を持つプロパティ宣言のみ許可」とし、`$this->timeout =` 代入や default 不在の typed property は fail させてください。

[Warning] `onConnection` リテラルが複数ある場合の扱いが未定義です。  
修正案: 各 inventory class は 0 件または 1 件のみ許可し、複数検出は fail にしてください。

**施策 5: REQUEST_CHANGES**

[Warning] 実装方針は妥当ですが、`Worker` の構築依存と投入するテスト用 Job が未指定で、実装者が迷う余地があります。  
修正案: `app(\Illuminate\Queue\Worker::class)` を使うのか、匿名継承クラスの constructor に何を渡すのか、push/pop する最小 Job class を設計書に明記してください。

[Warning] 冒頭で「新規 2 テストはすべて Architecture」と書かれている一方、施策 5 は Feature + DB 使用です。  
修正案: 「施策 3/4 の新規 2 テストは Architecture。施策 5 は Feature」と明記を直してください。

**施策 6: APPROVE**

運用契約の記述は十分です。本番 supervisor が repo 外で CI 検知できない点も明記されており、後退リスクの扱いとして妥当です。

**施策 7: APPROVE**

コメントドリフト修正として妥当です。施策 0 の env 固定テストが入れば、コメントと設定の再ドリフトも検出しやすくなります。