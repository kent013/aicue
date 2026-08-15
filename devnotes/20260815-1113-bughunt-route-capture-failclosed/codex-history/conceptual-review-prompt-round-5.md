# Round 5: 概念設計の再改訂 (観測位置の変更)

# 対応マトリクス: conceptual-review Round 4

## [Critical] HTTP 状態と認証ユーザーだけでは正常な PRG と業務ゲート遮断を区別できない / 記録器を遮断 middleware の内側へ置け

- 判断: **対応する (提案どおり設計を変える)**
- 根拠: 指摘が正しい。`require-active-subscription` は業務 route 全体を保護しており
  (AGENTS.md ドメイン規約 4)、契約切れユーザーの POST が 302 で `onboarding.*` へ跳ぶのは
  端ケースではなく構造上頻出する。状態コードの近似を足し続けるより、
  **観測位置を業務遮断 middleware の内側にする**方が単純で正確である。
- 対応内容:
  1. 記録器を web グループの末尾に置き、`bootstrap/app.php` の priority list の鎖の最後
     (`RequireActiveSubscription` → `EnsureAccountNotPendingDeletion` → 記録器) に載せる。
     到達の定義を「認証・テナント境界 404・2FA 強制・メール検証・課金ゲート・退会凍結を
     すべて通過し controller 呼び出しの直前まで到達したこと」と概念設計に明文化した。
  2. 上流が短絡すれば記録器は走らない = 記録が無い = 未実行のまま worklist に残る。
  3. その結果、状態コードの写像から「GET の 3xx を一律 blocked」「`$request->user()` が null なら
     blocked」の 2 つの粗い近似を**削除**できた。残るのは
     2xx→ok / 3xx かつセッションに `errors`→blocked / 3xx その他→ok / それ以外→blocked の 4 行。
  4. **罠を 1 つ明記した**: route middleware の `terminate()` は
     `Kernel::terminateMiddleware` が `gatherRouteMiddleware()` の全件を回すため、
     実際に走ったかに関係なく呼ばれる。したがって `handle()` で目印を立て、
     `terminate()` は目印があるときだけ書く。
  5. 既知の偽陽性として残していた「認証済みのまま契約ゲートで遮断された変更系要求が ok」は
     **消えた**ので、その記述を概念設計から削除した。

## [Critical] 現状のままでは主効果を主張できない / 成功条件の追加

- 判断: **対応する**
- 対応内容: 成功条件 3 を
  「バリデーション不合格のリダイレクト・未認証のリダイレクト・契約ゲートで遮断された変更系要求が
   実行済みにならない (実 HTTP の Feature テストで固定)。記録器が遮断 middleware より内側にあることは
   Architecture テストで固定」へ書き換えた。

## [Warning] 「到達」の境界を明文化せよ

- 判断: **対応する**
- 対応内容: 上記 1 の引用ブロックとして概念設計へ明記した。

## [Suggestion] 順序保証はスコープ拡大ではない

- 判断: 受領。ただし**波及変更が 1 件増える**ことを設計に書いた:
  web グループへ middleware を足すと、解決後の middleware 列に現れるため
  `tests/Architecture/TenantBoundaryOrderingTest.php` の `middlewareShortCircuitInventory()` へ
  「透過 (`false`)」として登録が必要になる (deny-by-default で未分類は fail。
  既存の `BughuntCoverageMiddleware` も同じ形で登録されている)。

## 新たに明記した保証しない範囲

- 記録されるのは **web グループを通る要求だけ**。`api/*` と Filament 管理面 (`/admin`) は
  別スタックのため記録されず、分母に載っていれば未実行側に倒れる (過小申告の方向)。

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

- **route 名を解決する必要が無い** — 正常に解決された route については、アプリのルーターが確定した
  route 名 (`$request->route()->getName()`) をそのまま書くので、外部での route 名の再解決が要らない
  (定義順・fallback・HEAD→GET の読み替えを別実装で真似る必要が無い)。
  コード索引 (code-review-graph) にも一切依存しない。
  404 / 405 のように route 名を取得できない要求は `unresolved` として扱う
  (「405 の判定までアプリと一致する」とは言わない — 405 では名前付き route が解決されないことがある)。
- **LLM を経路に入れない** — 探索エージェントが手で書く方式は、家系で実測された 105/107 の誤報の原因である。
- **変更系 (POST/PUT/DELETE) を取り違えない** — 後述のとおり serve のアクセスログは method を出力しない。

**(3) 生成器も fail-closed にする。** 記録が空・欠落・別 run の混入・記録器の失敗マーカーがある場合、
executed.json を作らずに終了コード 3 で落とす。照合器だけを閉じても、生成器が静かに空を作れば
同じ嘘が出るため、両端を閉じる。

**(4) 走行前の雑音を落とす。** provision の疎通確認 (`curl {url}/login`) が記録に混ざると
`login` が毎回「実行済み」になる。疎通確認の通過後に当該 shard の記録ファイルを空にしてから
探索エージェントへ引き渡す。route 名を持たない要求 (静的ファイル・404) は記録に載せず、
捨てた件数を **shard ごとに**残す (特定 shard の設定不良や大量 404 を切り分けられるようにする)。

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
3. **バリデーション不合格のリダイレクト・未認証のリダイレクト・契約ゲートで遮断された
   変更系要求が「実行済み」にならない** (実 HTTP の Feature テストで固定する)。
   記録器が遮断 middleware より内側にあることは Architecture テストで固定する。
4. 記録器 → JSONL → executed.json → 照合器 の一連が自動テストで固定されている
   (記録器の配線は実 HTTP 要求で検証する。`terminate()` の直接呼び出しは配線検証にならない)。

**実 bug-hunt 走行はリリース後の運用確認であり、本 TODO の完了条件にしない**
(実 run は LLM 実呼び出しを伴い課金される)。

## 実装方針（概要）

| # | 施策 | 変更対象 |
|---|---|---|
| 1 | 照合器の fail-closed 化 (主入力検証 + 終了コード 3) | `coverage/correlate.py` / `coverage/test_correlate.py` |
| 2 | 実行済み route の記録器 (アプリ側 middleware。web グループ末尾 + priority 鎖の末尾) | `app/Http/Middleware/` 新規 / `config/bughunt.php` / `bootstrap/app.php` / Feature テスト / `tests/Architecture/TenantBoundaryOrderingTest.php` の分類 inventory |
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

### 「到達した」の境界 — 記録器を遮断 middleware の内側に置く

状態コードだけでは「正常な PRG リダイレクト」と「ゲートに遮断されたリダイレクト」を区別できない。
`require-active-subscription` は業務 route 全体を保護しているので、これは端ケースではない。

そこで**記録器の位置そのものを証拠にする**。記録器を web グループの末尾に置き、
`bootstrap/app.php` の priority list の鎖の**最後**
(`RequireActiveSubscription` → `EnsureAccountNotPendingDeletion` → 記録器) に載せる。

> **到達の定義**: 「認証・テナント境界 404・2FA 強制・メール検証・課金ゲート・退会凍結の
> すべてを通過し、controller 呼び出しの直前まで到達したこと」。
> これらのいずれかが短絡すれば記録器は**そもそも走らない** = その要求は記録されず、
> route は未実行のまま worklist に残る。これが正しい結果である。

この位置決めにより、状態コードの写像は次の 4 行で足りる (GET の 3xx を一律 blocked に倒すような
粗い近似も、遮断先 route 名の一覧を記録器に埋め込むことも不要になった):

| 条件 | status |
|---|---|
| 2xx | `ok` |
| 3xx かつセッションに `errors` がある | `blocked` (FormRequest 不合格。middleware より後に起きる) |
| 3xx (上記以外) | `ok` (controller が返した正常なリダイレクト) |
| それ以外 (1xx / 4xx / 5xx) | `blocked` |

JSONL には HTTP 状態コードの生値を残す。executed.json では上の写像に加えて
`http_statuses` (その route × shard で観測した状態コードの一覧) を添えるので、
422 と 403 は成果物の上で区別できる。照合器は `ok` の有無しか読まないので契約は変わらない。
**判定不能は過小申告 (`blocked`) 側へ倒す。**

**実装上の注意 (罠)**: route middleware の `terminate()` は、**その route に付いている限り
実際に走ったかどうかに関係なく呼ばれる** (`Kernel::terminateMiddleware` は
`gatherRouteMiddleware()` の全件を回す)。したがって `handle()` で目印を立て、
`terminate()` はその目印があるときだけ書く。目印が無ければ「上流で短絡された」ことを意味する。

**保証しない範囲**: 記録されるのは web グループを通る要求だけである。
`api/*` と Filament の管理面 (`/admin`) は別の middleware スタックを通るので記録されず、
分母に載っていれば未実行側に倒れる (過小申告の方向)。

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
(c) `executed_routes` が 0 件 (有効な観測行が無い)、
(d) `shards` 宣言と実際の行に現れる shard 集合が食い違う。

> **`ok` が 0 件は終了コード 3 にしない。** 全操作が 403 / 422 / 500 で跳ねた走行は、
> 主入力としては完全に成立している。正しい結果は「終了コード 0 で全件を未実行 worklist に残す」
> であり、これは 422 を未実行側へ倒す方針と整合する。
> `ok` / `blocked` の内訳は summary の `executed_ok_count` / `executed_blocked_count` に出すだけで、
> 終了コードには反映しない。

## 制約・前提

- `.claude/skills/app-bug-hunt/` 配下の Python は **標準ライブラリのみ** (AGENTS.md §bug-hunt)。
  検証は `python3 -m unittest`。
- 観測器は bug-hunt 未使用時に **完全 no-op** でなければならない (AGENTS.md §bug-hunt のオプトイン契約)。
  既存の `BughuntCoverageMiddleware` と同じく env フラグ既定 false を第 1 の門にする。
- dev DB 防御・`env -i` 隔離・`BUGHUNT_ORCHESTRATOR` の権限分離は本件で一切緩めない。
- 記録器は観測器であり、**アプリの応答を壊してはならない**。書き込み失敗は警告ログを出し、
  **同時に失敗マーカーを best-effort で記録する**。生成器がマーカーを検出したら終了コード 3
  (応答非干渉と検査の fail-closed は両立させる)。
- JSONL の 1 行は改行込みの 1 文字列に組み立て、`FILE_APPEND | LOCK_EX` の **1 回の追記**で書く
  (並行リクエストで行が混線して JSON が壊れると、正常な探索を観測基盤の競合で落とすことになる)。
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

本ラウンドが概念設計の最終ラウンドです。全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。実装の細部 (関数分割・具体的なコード) は次の詳細設計フェーズで詰めます。
