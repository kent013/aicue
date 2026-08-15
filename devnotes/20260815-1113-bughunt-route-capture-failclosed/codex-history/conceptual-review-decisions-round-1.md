# 対応マトリクス: conceptual-review Round 1

## [Critical] 「記録器が有効だが空 / 途中で壊れた」の区別が弱く fail-open の別形になる

- 判断: **対応する (一部反論)**
- 根拠: 指摘のとおり「照合器だけ fail-closed にしても、生成器が静かに空を作れば同じ嘘が出る」。
  ただし提示された 5 条件のうち「shard manifest に存在する shard の JSONL が欠けている」は
  manifest を読む依存を増やすので、**JSONL のファイル群を `--shard` 明示指定で受ける**形に置き換える
  (親は run の shard 数を知っているので、指定した shard の 1 つでも欠ければ落ちる = 同じ効果で依存が少ない)。
- 対応内容: `build_executed.py` の終了コード 3 条件を以下に確定して概念設計へ書いた。
  (a) 指定 shard の JSONL が存在しない、(b) 指定 run_id に一致する行が 1 件も無い、
  (c) 記録器の失敗マーカー (`{run}-{shard}.error`) がある、(d) 行の JSON が壊れている、
  (e) run_id が指定と不一致の行が混ざっている。

## [Warning] 書き込み失敗を Log::warning だけで握り潰すと部分欠測が静かに残る

- 判断: **対応する**
- 根拠: 応答非干渉 (観測器がアプリを壊さない) と検査 fail-closed は両立できる、という指摘に同意する。
- 対応内容: 記録器は追記失敗・JSON 化失敗を捕まえたら、同ディレクトリの失敗マーカー
  `{run}-{shard}.error` へ理由を追記する (best-effort)。`build_executed.py` はマーカーがあれば終了コード 3。
  **保証しないもの**: マーカー自体の書き込みも失敗する障害 (ディレクトリごと書けない等) と、
  行数を数えていないことによる「途中から欠測」は検出できない。これは詳細設計の「保証しないもの」に明記する。

## [Warning] gate 設計 (env flag だけでは no-op 契約として弱い)

- 判断: **対応する (path は env で受けない)**
- 根拠: 第 2 の門が必要という指摘に同意する。ただし出力先を env (`BUGHUNT_ROUTE_CAPTURE_PATH`) で
  受けると、env 由来の任意パスへ書ける口を新設することになる。既存 `BughuntCoverageMiddleware` が
  リクエストヘッダ `X-Bughunt-Run` を run 名の代替入力にしている作法も、パス組み立ての入力に
  untrusted を混ぜるので新しい記録器では踏襲しない。
- 対応内容: gate を
  (1) `config('bughunt.executed.enabled')` が真 (env 既定 false)、
  (2) `! app()->isProduction()`、
  (3) run / shard が空でなく `[A-Za-z0-9_.-]+` に完全一致、
  の 3 条件にする。出力先は `storage_path('bughunt-executed')` 固定でヘッダ入力を取らない。
  Feature テストは (1) を config override で立てて配線ごと検証する。

## [Warning] run_id 検査を記録側にも入れる

- 判断: **対応する**
- 根拠: 別 run の JSONL が混ざると executed.json の run_id 検査だけでは生成過程の混入を見落とす。
- 対応内容: JSONL の各行に `run_id` / `shard` を持たせ、`build_executed.py` は
  `--run-id` と不一致の行を見つけたら終了コード 3 で落とす (静かに捨てない)。
- 一部見送り: 行ごとの `recorded_at` は入れない。時間境界はファイル名 (run 単位) と
  「疎通確認の通過後に空にする」規約で既に決まっており、行の時刻を読む消費者が 1 つも無いため
  (今必要なものだけ作る)。

## [Warning] 422 を 403/404/500 と同じ blocked にすると worklist の解釈が粗くなる

- 判断: **対応する (語彙は増やさない形で)**
- 根拠: 診断情報として分ける価値があるという指摘に同意する。一方 `executed.json` の
  `status` 語彙 `ok|blocked|skipped` は照合器の既存契約で、`ok` の有無しか読まれない。
  新しい値を増やしても読む側が居らず、契約だけが複雑になる。
- 対応内容: JSONL には **HTTP 状態コードの生値**を残す。`executed.json` の各行は
  `status` (ok|blocked) に加えて `http_statuses` (その route × shard で観測した状態コードの一覧) を持つ。
  照合器は未知キーを無視するので契約は壊れず、422 と 403 は成果物の上で区別できる。

## [Warning] 静的 asset・疎通確認などの雑音

- 判断: **一部対応する / 一部反論する**
- 根拠: route 名なしを捨てる・疎通確認後に空にする、の 2 点は採る。
  一方「`build_executed.py` 側でも operations.md に載る route だけへ正規化する」は**採らない**。
  分母 (operations.md) を読む主体は照合器 1 つに限るのが現行設計で、生成器にも分母を持たせると
  同じ分母が 2 箇所に分かれて食い違う (照合器の `in_scope` 判定と二重管理になる)。
  operations.md に無い route は照合器で単に join されないので、実害は無い。
- 対応内容: route 名なしの要求は `executed_routes` に載せず、件数だけ `unresolved_count` として残す
  (捨てたことが見える形にする)。

## [Warning] 施策 6 の blast radius が別

- 判断: **対応する (縮小して同一 TODO に残す)**
- 根拠: 「今回追加する Python 自己テストをどこからも実行しない状態で完了扱いにするのは避ける」に同意。
  一方 AGENTS.md の検証コマンド台帳 (`VERIFICATION_COMMANDS` マーカー) や CI 定義への波及は別件。
- 対応内容: 施策 6 は `tests/Architecture/` の 1 ファイルに限定し、
  `python3 -m unittest` を実走させて終了コードを検査するだけにする
  (先例: `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` が python3 の存在を
  skip せず fail として固定している)。AGENTS.md / CI 定義は触らない。

## [Warning] 期待効果を「カバレッジ報告の信頼性回復」に絞る

- 判断: **対応する**
- 対応内容: 「探索品質が上がる」「本番抜けが減る」を二次効果として明記し、主張を絞った。

## [Warning] PHPStan level 10 / 専用 DTO か value object

- 判断: **一部反論する**
- 根拠: 境界検査を明示するという実質には同意する。ただし 4 フィールドの観測行のために
  DTO / value object を新設するのはオーバーエンジニアリング (思考原則 2)。
  既存の準拠実装 `BughuntCoverageMiddleware` は private static ヘルパの戻り型
  (`?string` / `string`) で level 10 を通しており、同じ作法で足りる。
- 対応内容: 詳細設計で、`$request->route()` が `Route|null`、`getName()` が `?string` である前提を
  明示し、`is_string` + 正規表現の境界検査を通した値だけを組み立てる形にする。DTO は作らない。

## [Suggestion] 使命との整合は間接だが本質的

- 判断: 見送る (指摘に反する変更は不要)。
