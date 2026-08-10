# 対応マトリクス: design-review Round 1

## 施策 1
- [Suggestion] import 省略 → **対応する**。`use App\Support\BughuntDatabaseGuard;` を明記。

## 施策 2
- [Warning] since/until 境界未確定 → **対応する**。`since <= created_at < until` の半開区間で固定。
  日付のみの入力は `--since` = その日の 00:00:00、`--until` = **翌日 00:00:00 (排他)** =
  「その日を含む」。テストで境界を固定する。
- [Warning] `date(created_at)` の timezone → **対応する**。`config('app.timezone')` は `UTC` 固定
  (実読) で、`created_at` は UTC の `timestamp` 列。よって **UTC 日付**で切ると明文化し、
  レポートの注記にも出す (「日次は UTC 基準」)。driver 分岐は作らない (テスト DB は pgsql 固定)。
- [Suggestion] 表示スケールの揺れ → **対応する**。表示側で USD 6 桁 / JPY 2 桁に整形する
  (`number_format`)。DTO は `numeric-string` のまま保持し、丸めない。

## 施策 3
- [Warning] 日付 parse エラーの終了コード未定義 → **対応する**。parse 不能 / `since >= until` は
  `self::INVALID` (2)。テストに入れる。
- [Warning] `--json` の shape が暗黙 → **対応する**。DTO に `toArray(): array{...}` を実装し
  (`FxSnapshotDto::toArray()` の既存作法と同型)、command は `json_encode($dto->toArray())` だけを行う。
  enum は `->value`、Carbon は `toIso8601String()` (null は null) と明文化。

## 施策 5
- [Critical] Default Project の preflight/fixture 矛盾 → **対応する** (指摘どおり)。
  **`--check` は DB を一切変更しない**。preflight は `project=existing #id` または
  `project=will-create` を表示するだけにし、実作成は fixture 段でのみ行う。
- [Critical] fake / 重い service の解決タイミング → **対応する** (指摘どおり)。
  コマンドの **constructor は引数を持たない**。`FakeObjectStore` を含む全依存は
  fail-secure 4 条件を通過した**後**に `app(...)` で遅延解決する、を設計条件として明記する。
- [Warning] actor の解決条件 → **対応する**。対象 org 所属 user を preflight で解決し、
  不在なら preflight failure。Laratrust team context は不要 (呼ぶ Service は権限判定を持たず、
  認可は Controller 層の責務) である旨を明記する。
- [Warning] LLM 失敗分類と retry 成功 → **対応する** (指摘どおり。誤検知の実害がある)。
  `llm-evidence` は **成功行** (`failure_reason IS NULL` ∧ `input_tokens > 0`) を数える。
  `SmokeFailureClass::Llm` は**パイプラインが実際に失敗したときの分類にのみ**使う。
- [Suggestion] テイク動画の生成方法 → **対応する**。ffmpeg コマンド行と
  size / content-type / SHA-256(base64) の取り方を設計に書く。

## 施策 6
- [Critical] fake 解決タイミング → **対応する** (施策 5 と同一対応)。allowlist のコメントに
  「`handle()` の fail-secure 通過後にしか fake を解決しない」という**実装条件**まで書く。

## 施策 7
- [Critical] `--run-id` を artisan へ転送すると Symfony Console が落ちる → **対応する** (指摘どおり)。
  script 専用 option (`--shard` / `--run-id`) は script 側で消費し artisan へ渡さない。
  option の対応表を設計に載せる。
- [Critical] `--real-llm` 制約との接続 → **対応する**。`pipeline-smoke` は**フラグを要求しない**。
  サブコマンド内部で `build_mode_env` (既定 `LLM_MODE=real` / `STORAGE_MODE=fake`) →
  `assert_llm_key_present` を呼ぶ。既存の「モードフラグは provision 系専用」は**変更しない**
  (`pipeline-smoke --real-llm` は従来どおり die 2 = 意図の重複表明を許さない)。
- [Warning] option 境界 → **対応する** (上の対応表で解消)。

## 施策 9
- [Warning] `--check` が CI の ffmpeg 有無に依存 → **対応する**。テストは
  `manual.render_ffmpeg_binary` / `render_ffprobe_binary` を `PHP_BINARY` へ差し替えて
  (`php -version` は終了コード 0)、preflight の**分岐だけ**を固定する。
- [Warning] `--run-id` 非転送の回帰テスト → **対応する**。`self-test` の dryrun ケースに追加。
- [Suggestion] invalid date / `since >= until` のテスト → **対応する** (施策 3 と対)。

## 施策 4 / 施策 8
- APPROVE。変更なし。
