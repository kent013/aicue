# 対応マトリクス: design-review Round 1

## [Critical] 施策2: `RuntimeException` の import が無い

- 判断: **対応する**
- 対応内容: `use RuntimeException;` を import 一覧へ追加した。

## [Critical] 施策2: 「handle 到達 = 全遮断 middleware 通過」が priority 固定だけでは不足

- 判断: **対応する (指摘が正しい)**
- 根拠: web グループの middleware は route 個別 middleware より**前**に並ぶため、
  priority list に載っていない route 個別の短絡 middleware
  (`recent-auth` / `ensure-login-method` / `verified.or-back` / `signed` 等) は
  **記録器より後ろで走る**。その状態で `recent-auth` が 302 を返すと、
  セッションに errors が無いので `ok` と誤記録される (まさに本件が消そうとしている偽陽性)。
- 対応内容:
  1. 短絡分類の目録を `tests/Support/Routing/MiddlewareShortCircuitInventory` へ**移設**し
     (純粋な移動。assert は変えない)、`TenantBoundaryOrderingTest` と新しい順序テストの
     両方から参照する (同じ分類を二重管理しない)。
  2. 新しい Architecture テストで、**記録器が付いている全 route** について
     「短絡しうる (`true`) と分類された middleware すべてより後ろ」を deny-by-default で固定する。
  3. 違反が出た middleware は `bootstrap/app.php` の priority list へ
     `appendToPriorityList($短絡middleware, 記録器)` を足して解消する。
     具体的な本数はテストが赤で示すので、設計では推測で列挙しない。

## [Warning] 施策2: `session()->has('errors')` が古い flash error を拾いうる

- 判断: **対応する (テストで固定し、限界を文書化する)**
- 根拠: `Store::save()` が `ageFlashData()` を呼び、**前リクエストで flash された errors は
  今回のリクエストの保存時に忘れられる**。保存は `StartSession::terminate()` で起き、
  terminate の呼び出し順は sorted 順 (StartSession が先) なので、記録器の terminate 時点では
  「今回 flash された errors」だけが残る。ただしこれは framework 内部の順序に依存する。
- 対応内容: Feature テストに
  「直前のリクエストでバリデーション不合格 → 次のリクエストの成功 302 が `ok` になる」を追加する
  (framework の挙動が変われば赤で気づく)。README にこの限界を明記する。

## [Warning] 施策2: `markFailure()` がディレクトリを作らない

- 判断: **対応する**
- 対応内容: `markFailure()` でも `dirname()` を `@mkdir(..., recursive)` する。

## [Warning] 施策3: 疎通確認の応答受信と `terminate()` の書き込みの競合

- 判断: **対応する**
- 根拠: `terminate()` は応答送出**後**に走るため、curl が返った直後に truncate すると
  `/login` の行が後から書かれて残りうる。`login` は分母に載っているので、
  毎回「実行済み」になる = 実害がある。
- 対応内容: truncate の前に**書き込みが止まったことを確認する** (ファイルサイズが
  0.2 秒間隔で 2 回連続一致するまで待つ。上限 3 秒)。待ち時間の値ではなく
  「止まったことの確認」が契約である。

## [Warning] 施策3: dryrun が storage に副作用を持つ

- 判断: **対応する (副作用は残し、明記する)**
- 根拠: dryrun で配線を検査できることの価値が上回る (自己テストが serve を起動しないため)。
- 対応内容: スクリプト内コメントと README に「dryrun でも記録ファイルの初期化だけは実行する
  (配線を自己テストから検査するため)」と明記する。

## [Critical] 施策4: JSONL 行の schema 検査が不足

- 判断: **対応する**
- 対応内容: 各行について
  `run_id == --run-id` / `shard == 処理中の shard` / `status ∈ {"ok","blocked"}` /
  `http_status` が int / `route_name` が None または非空 str / `method` が非空 str
  を検査し、違反は終了コード 3 (`capture_row_invalid`) にする。

## [Warning] 施策4: 失敗時に `--out` を作らない契約が途中失敗で破れる

- 判断: **対応する**
- 対応内容: 一時ファイルへ書き、成功時だけ `os.replace` で atomic rename する。
  失敗時は一時ファイルを消し、既存の `--out` は上書きしない。

## [Warning] 施策4: `route_name: null` だけの shard で生成器と照合器の契約が食い違う

- 判断: **対応する (生成器側へ揃える)**
- 対応内容: `capture_empty` の判定を「有効行が 0 件」ではなく
  **「名前付き route の行が 0 件」**にする。これで照合器の `executed_no_rows` と定義が一致する。

## [Suggestion] 施策1: `load_executed()` の型・値検査 / `shards` 非空必須

- 判断: **対応する**
- 対応内容: 検証を照合器側にも置く。`shards` が空なら `executed_shards_missing`、
  行の `route_name` / `shard` が非空 str でない、`status` が `ok|blocked` でない場合は
  `executed_row_invalid` として終了コード 3。
- **あわせて既存の fail-open を 1 つ消す**: `Executed.is_executed()` の
  「status 未記録の route は ok とみなす (旧形式の救済)」分岐を削除する。
  これは「status を持たない行を実行済みに数える」= fail-open であり、
  新しい検証では status 欠落行は入力エラーになるため到達不能になる。
  既存テスト `test_missing_status_treated_as_executed` は
  `test_row_without_status_is_rejected` へ**置き換える** (契約変更に伴う置換であり、
  検証意図を消すのではなく反転させる。設計書に明示する)。

## [Suggestion] 施策5: stale 語彙 gate に `skipped` を入れる

- 判断: **対応する**
- 対応内容: `status` の語彙を `ok|blocked` の 2 値に統一する。
  `skipped` は手書き時代の語彙なので skill 配下から消し、
  `test_naming_no_stale.py` のパターンに追加する。
  これに伴い `Executed.skipped_blocked_count()` と summary キー
  `skipped_blocked_count` を `blocked_count` へ改名する (README も同時更新)。

## [Suggestion] 施策6: 失敗時に stdout/stderr を Pest の失敗メッセージへ含める

- 判断: **対応する**
- 対応内容: `Process::getOutput() . getErrorOutput()` を `expect(...)->toBe(0, $output)` の
  メッセージに載せる (既存 `bhicRunSandbox()` と同じ形)。
