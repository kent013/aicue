# Round 2: 概念設計の改訂

前ラウンドの指摘に対する判断は以下 (対応マトリクス全文)。

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

---

## 改訂後の概念設計 (全文)

# 概念設計: bughunt-route-capture-failclosed

## 背景・課題

bug-hunt の「操作到達カバレッジ」は、`.claude/skills/app-bug-hunt/coverage/correlate.py` が
**実行済み route の記録 (executed.json)** と **機構分母 (operations.md)** を突き合わせ、
「まだ通れていない操作」の worklist を出す仕組みである。

現状の aicue には **記録を作る側が 1 つも無い**。

- `--executed` は任意引数で、省略すると `load_executed(None)` が `present=False` の空記録を返す
  (`correlate.py` L289-296)。
- その結果、`correlate()` は in_scope の全機構を「未実行」として並べ、`main()` は **終了コード 0** で返す
  (`correlate.py` L660-670)。
- 実行済み記録を生成する経路 (browser 側の退避 → 正規化 → route 名解決、あるいはアプリ側の観測器) が
  リポジトリに存在しない。したがって **毎回この経路にしか入らない**。

つまり検査は「全機能が未実行である」という**内容の疑わしい報告を、成功として返している**。
自動化 (SKILL.md Phase 4 後のカバレッジ突合) は終了コードしか見ないので、この嘘をそのまま受け取る。

同種の事故は家系で実測されている: ある走行で対象 107 件のうち 105 件が誤って未実行として並んだ。
原因は表記ゆれではなく生成器の不在であった (lctl 台帳 bughunt-executed-route-capture の経緯)。

さらに現状の照合器は、渡された executed.json の **run_id を検査していない**。
別の走行の記録を渡しても静かに通る (findings 側だけ `--run-id` で絞っている非対称)。

## 改善アイデア

**(1) 照合器を fail-closed にする。** 主入力 (実行済み route の記録) が揃わない走行では
worklist を出さず、終了コード 3 で落とす。「検査が静かに嘘をつく」状態をまずここで消す。

**(2) 実行済み route をアプリ側で機械記録する。** bug-hunt 専用環境の serve プロセスに
観測器 (middleware) を 1 本足し、応答送出後に「解決された route 名・method・状態コード」を
shard ごとの JSONL へ追記する。走行後にそれを束ねて executed.json を作る。

記録の主体を**アプリ自身**に置く理由:

- **route 名を解決する必要が無い** — アプリのルーターが解決した結果 (`$request->route()->getName()`)
  をそのまま書くので、定義順・fallback・HEAD→GET の読み替え・405 の判定がアプリと食い違う余地が無い。
  コード索引 (code-review-graph) にも一切依存しない。
- **LLM を経路に入れない** — 探索エージェントが手で書く方式は、家系で実測された 105/107 の誤報の原因である。
- **変更系 (POST/PUT/DELETE) を取り違えない** — 後述のとおり serve のアクセスログは method を出力しない。

**(3) 生成器も fail-closed にする。** 記録が空・欠落・別 run の混入・記録器の失敗マーカーがある場合、
executed.json を作らずに終了コード 3 で落とす。照合器だけを閉じても、生成器が静かに空を作れば
同じ嘘が出るため、両端を閉じる。

**(4) 走行前の雑音を落とす。** provision の疎通確認 (`curl {url}/login`) が記録に混ざると
`login` が毎回「実行済み」になる。疎通確認の通過後に当該 shard の記録ファイルを空にしてから
探索エージェントへ引き渡す。route 名を持たない要求 (静的ファイル・404) は記録に載せず、
捨てた件数だけを残す。

### 採らなかった案と理由 (実測にもとづく)

| 案 | 理由 |
|---|---|
| `php artisan serve` のアクセスログを解析する | **実物を確認した**: `tmp/bug-hunt/serve-0.log` は `2026-07-16 14:12:20 /login ... ~ 500.99ms` の形で、**method も状態コードも出力しない**。operations.md の分母は method で定義される (同一 URL の GET と POST は別機構) ため突合できない。Laravel の `ServeCommand::handleOutput` が整形時に落としている (vendor 実装を確認済み)。 |
| ブラウザ (`playwright-cli requests`) の通信履歴を退避する (家系の正典 t1) | 履歴は**ページ全読み込みで消える**ため、遷移のたびに LLM が退避コマンドを叩く規約が要る = 手書きと同じ「叩き忘れたら静かに欠測」の弱点が残る。出力も機械可読な構造を持たず番号付きの文字列行であり、正規化器 (parse_requests.py) と検体の維持が要る。aicue には走行実績が無く、検体を実測で得ていない状態でこの経路を積むと、契約が想定で固まる。 |
| 探索エージェントに executed.jsonl を手書きさせる | 上記 105/107 の誤報を起こした方式。採らない。 |

家系の正典 (t1) は browser 退避 3 段だが、**同じ不変条件を別の実装形で満たす前例が spirux にある**
(アプリ側の観測器で採る形。台帳は「t1 相当 (担う不変条件は充足。実装形は正典と異なる)」と評価)。
aicue は spirux と同じ形を採り、逸脱として理由を記録する。

## 期待効果

**主効果 (これだけを主張する): カバレッジ報告の信頼性回復。**

- 「実行済みの記録が無い走行」が成功として通らなくなる (終了コード 3 で落ちる)。
- 走行のたびに、どの操作を実際に叩けたかが機械の記録として devnotes に残る。
- 「未実行 worklist の逓減」という KPI が初めて意味を持つ (現状は毎回 100% 未実行なので逓減しない)。

二次効果 (本件の成否判定には使わない): 未検査の操作が検査済みとして扱われなくなることで、
詰み・認可漏れが本番へ抜ける確率が下がる。ただしこれは bug-hunt の探索そのものの質に依存する。

### 成功と判断する条件

1. `--executed` を渡さずに照合器を呼ぶと終了コード 3 で落ちる (負の対照テストで固定)。
2. 記録が空 / 別 run 混入 / 失敗マーカーありのいずれかで生成器が終了コード 3 で落ちる (同上)。
3. bug-hunt を 1 回走らせると、探索エージェントが何もしなくても executed.json が生成され、
   照合器が終了コード 0 で worklist を出す。

## 実装方針（概要）

| # | 施策 | 変更対象 |
|---|---|---|
| 1 | 照合器の fail-closed 化 (主入力検証 + 終了コード 3) | `coverage/correlate.py` / `coverage/test_correlate.py` |
| 2 | 実行済み route の記録器 (アプリ側 middleware) | `app/Http/Middleware/` 新規 / `config/bughunt.php` / `bootstrap/app.php` / Feature テスト |
| 3 | bug-hunt 環境への配線 (env 注入・疎通確認後の初期化) | `scripts/bug-hunt-shard.sh` |
| 4 | shard 別 JSONL を束ねて executed.json を作る | `coverage/build_executed.py` 新規 + 自己テスト |
| 5 | 手順と契約の文書更新 (旧 fail-open 記述の削除) | `SKILL.md` / `coverage/README.md` / `.claude/agents/bughunt-shard.md` |
| 6 | Python 自己テストを `composer test` のレーンへ結線 | `tests/Architecture/` 新規 |

施策 1 だけを入れると bug-hunt の Phase 4 が毎回落ちるため、1〜5 は同一 TODO で入れる
(後方互換の並走を残さない = 旧 fail-open 経路は同じ変更で消す)。
施策 6 は 1 ファイルに限定する (`python3 -m unittest` を実走させて終了コードを見るだけ。
AGENTS.md の検証コマンド台帳・CI 定義には触らない)。

### 記録器の門 (gate) — 未使用時に完全 no-op であること

3 条件をすべて満たしたときだけ動く。1 つでも欠ければ `terminate()` は即 return する。

1. `config('bughunt.executed.enabled')` が真 (`env('BUGHUNT_EXECUTED', false)` 由来。既定 false)
2. `! app()->isProduction()` (production では構造的に動かない)
3. run / shard が空でなく `[A-Za-z0-9_.-]+` に完全一致する (パス組み立ての入力を狭める)

出力先は `storage_path('bughunt-executed')` **固定**で、env でパスを受け取らない。
既存 `BughuntCoverageMiddleware` はリクエストヘッダ `X-Bughunt-Run` を run 名の代替入力にしているが、
untrusted な値をパス組み立てへ混ぜる作法なので新しい記録器では踏襲しない。

### 状態コードの扱い

JSONL には HTTP 状態コードの生値を残す。executed.json では
`status` = 2xx/3xx なら `ok` / それ以外は `blocked` に写像し、加えて
`http_statuses` (その route × shard で観測した状態コードの一覧) を添える。
照合器は `ok` の有無しか読まないので契約は変わらず、
バリデーション不合格 (422) と認可拒否 (403) は成果物の上で区別できる。
**過小申告の方向へ倒す** — 422 は「操作に到達したが業務は成立していない」ため未実行側に残す。

### 終了コード規約 (両端 fail-closed)

先例は `scripts/bug-hunt-inventory-check.sh` (0=一致 / 3=ドリフト) と、その規約を実走で固定する
`tests/Architecture/BugHuntInventoryCheckInvariantTest.php`。同じ 3 を使う。

| 終了コード | 意味 |
|---|---|
| 0 | 正常 |
| 1 | 入力の読み込み・parse の失敗 (既存の照合器の挙動を維持) |
| 3 | **主入力の可用性違反** — 検査を成立させられない |

`build_executed.py` が 3 で落ちる条件: (a) 指定 shard の JSONL が存在しない、
(b) 指定 run_id に一致する行が 1 件も無い、(c) 記録器の失敗マーカー `{run}-{shard}.error` がある、
(d) 行の JSON が壊れている、(e) 指定と違う run_id の行が混ざっている。

`correlate.py` が 3 で落ちる条件: (a) `--executed` が渡されていない、
(b) executed.json の `run_id` が `--run-id` と一致しない、
(c) `ok` の行が 1 件も無い、(d) `shards` 宣言と実際の行に現れる shard 集合が食い違う。

## 制約・前提

- `.claude/skills/app-bug-hunt/` 配下の Python は **標準ライブラリのみ** (AGENTS.md §bug-hunt)。
  検証は `python3 -m unittest`。
- 観測器は bug-hunt 未使用時に **完全 no-op** でなければならない (AGENTS.md §bug-hunt のオプトイン契約)。
  既存の `BughuntCoverageMiddleware` と同じく env フラグ既定 false を第 1 の門にする。
- dev DB 防御・`env -i` 隔離・`BUGHUNT_ORCHESTRATOR` の権限分離は本件で一切緩めない。
- 記録器は観測器であり、**アプリの応答を壊してはならない** (書き込み失敗は警告ログのみ)。
- `php artisan serve --no-reload` は env をそのまま子へ渡す (既存の `BUGHUNT_PCOV*` 注入と同じ経路に乗る)。

## スコープ外

- **コード到達カバレッジ (pcov / merge_pcov.py)**: 別系統。触らない。
- **機構分母 (operations.md) の生成と注釈**: lctl feature bughunt-inventory-generation の担当。
- **所見台帳 (findings.jsonl / validate_findings.py)**: 別 feature。
- **偽造耐性**: 記録ファイルは worktree 内の書き込み可能な場所にあるため、
  「エージェントが書き換えていないこと」は保証しない (家系の他リポジトリも主張していない)。
- **記録の完全性**: 検出できるのは「1 件も記録が無い」「別 run が混ざった」「記録器が失敗マーカーを
  残せた」までである。**行数を数えていないので「途中から欠測した」は検出できない**し、
  失敗マーカー自体を書けない障害 (ディレクトリごと書けない等) も検出できない。
- **並列 4 shard での実走行による実測**: 本 TODO では fake provision と自己テストで閉じる。
  実 run は課金を伴うため、次回の bug-hunt 走行が初回のフル稼働になる。

---

上記の改訂と反論を踏まえ、再度レビューしてください。全体判定 (APPROVED / CHANGES_REQUESTED) を必ず明示してください。反論した 3 点 (recorded_at を持たない / build_executed に operations.md を読ませない / DTO を作らない) について、なお不可と考える場合は「その設計だと具体的にどの入力でどう壊れるか」を示してください。
