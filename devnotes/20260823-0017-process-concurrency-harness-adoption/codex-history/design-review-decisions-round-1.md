# 対応マトリクス: design-review Round 1

Codex の指摘 24 件のうち **23 件を対応**、**1 件に反論**（実読で事実誤認を確認）。

---

## 施策 1

### [Critical] `present('entered-')` の glob が `.partial` を拾う
- 判断: **対応する**
- 根拠: 実在のバグ。`signal()` は `{target}.{random}.partial` を作るので、
  `entered-a.xxxx.partial` は `entered-*` に**一致する**。二重実行の判定が壊れる。
- 対応内容: prefix の glob をやめ、**親が割り当てた 2 つの完成ファイル名だけ**を調べる形に変えた
  (`present(list<string> $names)`)。`.partial` は構造的に候補へ入らない。
  併せて `entered` の中身も nonce + go token と照合する。

### [Critical] `ready` は存在だけでは不十分 (空・別 child・誤 nonce でも通る)
- 判断: **対応する**
- 対応内容: `ready-{childId}` の中身をその子へ割り当てた nonce と**完全一致**で検査する。
  一致しなければ go を出さない。

### [Warning] `path()` が `/` や `..` を受け入れてディレクトリ外を指せる
- 判断: **対応する**
- 対応内容: 合図名を**固定語彙** (`ready` / `go` / `entered` / `release` / `out`) と
  child ID の正規表現 (`/\A[a-z]\z/`) の組合せに限定し、パス区切りを含む名前を拒否する。

---

## 施策 2

### [Critical] cleanup が「cascade を利用する」止まりで残留ゼロの根拠が無い
- 判断: **対応する**
- 根拠: 指摘のとおり。実読で FK を全数確認したところ、
  `organizations.laratrust_team_id` は **restrictOnDelete** であり、
  「組織を消せば全部消える」は**成り立たない** (teams を先に消せない / 後に消す必要がある)。
  さらに `role_user.user_id` には FK が無い (polymorphic) ため、利用者を消しても連鎖しない。
  設計のまま実装すると**残留が出る**。
- 対応内容: `ConcurrencyFixtureKeys`（作った主キーを持つ値オブジェクト）を新設し、
  **FK 安全な削除順を明記**した。実読で確認した FK は次のとおり:
  - `organizations.laratrust_team_id` → `teams` **restrictOnDelete**（組織 → teams の順）
  - `organization_user` / `custom_teams` / `api_keys` の `organization_id` → cascade
  - `idempotency_keys.api_key_id` → cascade
  - `users.current_organization_id` → **nullOnDelete**
  - `role_user.team_id` → cascade（`role_user.user_id` には FK 無し）
  - `organizations` は softDeletes → **query builder で物理削除**する
  そのうえで **cleanup 後に 8 表の残留ゼロを別名接続から検査**する（cascade の当て推量を検査に置き換える）。

### [Warning] `create()` の callback 失敗時に別名接続の disconnect/purge が保証されない
- 判断: **対応する**
- 対応内容: `create()` の `finally` で既定接続名を戻すのに加え、
  **失敗時は別名接続を disconnect + purge** し、成功時だけ後続の読み取り・cleanup 用に維持する。

### [Warning] `issueApiKey()` は Factory ではない
- 判断: **対応する（記述の是正）**
- 根拠: 指摘のとおり。`issueApiKey()` は `ApiKey::generatePlainKey()` +
  `ApiKey::createForOrganization()` を使う。プレーンキーの生成規則がドメイン側にあるため
  Factory では作れない（Factory で作るとテストが本物のキー形式を持てない）。
- 対応内容: 「**Factory または既存の正規ドメイン生成 helper**」と正確に書き直し、
  `issueApiKey` を使う理由も添えた。`Model::create()` の手組みは引き続きしない。

---

## 施策 3

### [Critical] 409 の**種類**を観測していない（conflict でも緑になる）
- 判断: **対応する**
- 根拠: 決定的な指摘。`IdempotentRequest` は 409 を 3 コード返す
  （`idempotency_conflict` / `idempotency_in_progress` / `idempotency_indeterminate`）。
  status だけを見ると、body 違いによる **conflict でも同じ観測になる**。
  本テストが主張したいのは `in_progress` なので、これでは証明になっていない。
- 対応内容: 観測へ `error_code` / `request_hash` / `route_name` / `uri` / `api_key_id` / `go_token` を追加し、
  敗者について `idempotency_in_progress` を**完全一致**で検査する。
  さらに 2 子の `request_hash` が**一致する**ことも検査する（body 違いを構造的に排除する）。

### [Critical] `observed_go` が自己申告にすぎない
- 判断: **対応する**
- 根拠: そのとおり。子が go を待たずに走って最後に `true` と書いても親は検出できない。
- 対応内容: **go token 方式**へ変更した。親は**全 ready の nonce を検証した後に初めて**
  ランダムな go token を生成して `go` へ書く。子はそれを読み、`entered` と最終観測へ**含める**。
  **go token は事前の引数で渡さない**ので、go を待たずに正しい値を書くことはできない。

### [Warning] `entered_handler` と `handler_executions` の整合が未定義
- 判断: **対応する**
- 対応内容: 勝者 `entered=true / executions=1`、敗者 `entered=false / executions=0` を
  見本テストで固定する。

---

## 施策 4

### [Critical] `ALLOWED_PROCESS_ENV_KEYS` が宣言だけで、子側の実測検査が無い
- 判断: **対応する**
- 根拠: 指摘のとおり。`env -i` が壊れると親の `DB_URL` が子へ継承され、
  phpdotenv は immutable なので**既存の環境変数が env ファイルより優先**される。
  接続前ガードを迂回しうる実在の穴である。
  なお `FakeWiringProbeRunner` の probe は既に「子が実際に受け取った環境を自分で観測して返す」
  作法を持っており、そちらへ合わせるのが自然だった（踏襲漏れ）。
- 対応内容: 子の**最初期（autoload の直後・bootstrap の前）**に `getenv()` のキー集合を取り、
  許可 3 キーとの**完全一致**でなければ非ゼロ終了する。
  失敗経路の検査に「継承された `DB_URL` / `DB_DATABASE` を拒否する」負例を追加した。

### [Critical] 見本テストが DB 座標のうち database 名しか比較していない
- 判断: **対応する**
- 対応内容: driver / host / port / database / username / charset / sslmode を
  親が渡した値と**完全一致**で検査する。`url` は空文字以外を拒否し、非文字列も fail-closed にする。

### [Critical] `?array $processes` の注入では偽 process が workspace を知れない
- 判断: **対応する**
- 根拠: 実装できない設計だった。偽 process は ready / entered / out を**書く**必要があるので、
  barrier のディレクトリを知らなければ失敗経路検査 #4〜#7 が組めない。
- 対応内容: `ProbeLaunchSpec`（workspace / childId / nonce / 引数）と
  `ProbeProcessFactory`（spec を受けて `ProbeProcess` を作る）を新設し、
  runner は factory を注入で受け取る形にした。偽物も同じ spec を受け取る。

### [Critical] 正常終了コードの検査が無い
- 判断: **対応する**
- 対応内容: 回収条件に「**両 process の exit code が 0**」と
  「**stdout の JSON と原子的 out ファイルの中身が一致**」を追加した。

### [Warning] 待機ごとに締切が更新され、総時間が締切を大幅に超える
- 判断: **対応する**
- 根拠: そのとおり。「全体の締切」と書きながら実装は段ごとの締切だった。
- 対応内容: runner 開始時に**単一の絶対 deadline**（単調時計）を作り、
  すべての待機・release 後の終了待ち・回収へ**残り時間**を渡す。

### [Warning] `stopAndReap()` 一操作では「停止・kill・wait を要求した」と言えない
- 判断: **対応する（保証を狭めるのではなく、操作を分ける）**
- 対応内容: `ProbeProcess` を `signalTerminate()` / `signalKill()` / `wait(float): ?int` の
  3 操作へ分けた。失敗経路検査は**呼ばれた順序**を固定できる。
  これで保証文（「runner が停止・kill・wait をそれぞれ要求する」）が実際に成立する。

---

## 施策 5

### [Critical] route closure が `$nonce` / `$timeoutSeconds` を `use` していない
- 判断: **対応する**
- 根拠: 素のバグ。指摘どおり未定義変数になる。
- 対応内容: `use` に全依存を明示した（go token も渡す）。

### [Critical] `loadEnvironmentFrom()` は env をその場で解析しない
- 判断: **対応する**
- 根拠: そのとおり。bootstrap 前の DB 名検査に使う値の取得手段が未定義だった。
- 対応内容: 子は bootstrap 前に**専用 env ファイルを自前の厳格パーサで解析**し、
  キー集合・型・DB 名を検証する。書き手（親）側も
  **値に改行 / CR を含む場合は書かずに例外**にし、`KEY="value"`（`"` と `\` をエスケープ）の
  1 形式だけを使う（キー注入を構造的に不可能にする）。

### [Critical] cache の array 固定は **ready を出す前**に子自身が検査すべき
- 判断: **対応する**
- 根拠: 正典 v1 の要素 (3) は「**守りたい層以外を無効化してから測る**」であり、
  測った後に「実は無効化できていなかった」と分かって赤くなるのでは要素を満たさない。
  指摘が正典の読みとして正しい。
- 対応内容: bootstrap 後・request 前に、既定 store と解決 driver がともに `array` であること、
  および実効 DB 座標が宣言と一致することを検査し、**違えば ready を出さずに非ゼロ終了**する。

### [Warning] plain API key を argv で渡すとプロセス一覧から読める
- 判断: **対応する**
- 対応内容: 秘密（plain API key / body）は **0600 の入力ファイル**へ置き、
  argv には秘密でない値（workspace パス・childId・入力ファイル名）だけを載せる。

### [Warning] 「本番同等の middleware 列」は言い過ぎ（throttle / project-in-org / ability が無い）
- 判断: **対応する**
- 対応内容: 「**冪等 middleware の前提を満たす最小 probe 経路**」へ表現を狭めた。
  不足 middleware を足す案は採らない（測りたいのは claim の調停であり、
  throttle を挟むと 2 本の到達が乱れて目的に反する）。この判断も明記した。

---

## 施策 6

### [Critical] `[201, 409]` と handler 合計 1 では in_progress を証明できない
- 判断: **対応する**（施策 3 と同一の対応）

### [Critical] `response_status` が NOT NULL なので初回 claim が失敗するのでは
- 判断: **反論する（事実誤認）**
- 根拠: **実読で確認した**。プロンプトへ添付したのは初期 migration だけだったため
  そう見えたが、後続の
  `database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php` が
  ```php
  $table->unsignedSmallInteger('response_status')->nullable()->change();
  ```
  で **nullable 化している**。同 migration の `down()` にも
  「戻す時点で claim 行 (response_status = null) が残っていると ALTER が失敗する」
  という記述があり、`null` の claim 行が正当な状態であることを裏づけている。
  したがって前提は成立しており、`database/` を変更する必要は無い。
- 対応内容: **指摘の実質は正しい**（実効 schema を設計書から追えるべき）ので、
  詳細設計に「実効 schema」節を追加し、後続 migration を名指しで参照するようにした。

### [Critical] cleanup closure が省略記号のまま
- 判断: **対応する**（施策 2 と同一の対応。削除順・条件・根拠・残留検査を展開した）

### [Warning] 「最大 4 接続」は suite 全体では誤り
- 判断: **対応する（記述の是正）**
- 根拠: 私の記述は「本テストが増やす接続」の話だったが、
  「同時最大 4 本」と書けば suite 全体の話に読める。曖昧だった。
- 対応内容: 「**当該 worker 内で最大 4 本**（既定 1 + 別名 1 + 子 2）。
  suite 全体では他 worker の接続にこの**うち 3 本**（別名 1 + 子 2）が加算される」と書き分けた。

---

## 施策 7

### [Critical] 現在の注入点では fake が workspace を知れず #4〜#7 を実装できない
- 判断: **対応する**（施策 4 の `ProbeLaunchSpec` / `ProbeProcessFactory` 導入で解消）

### [Warning] 「存在するが読めない」テストは root 実行では chmod 000 でも読めて不安定
- 判断: **対応する**
- 根拠: devcontainer は root 実行になりうるので実在のリスク。
- 対応内容: `ProcessBarrier` の読み取りを**注入可能な小さな読み手**へ分離し
  （コンストラクタで `callable(string): string|false` を受ける。既定は `file_get_contents`）、
  `false` を決定的に返す偽物で検査する。権限に依存しない。

### [Warning] 失敗経路が不足
- 判断: **対応する（全 9 件を追加）**
- 対応内容: ready nonce 不一致 / 未知 child ID の entered / `.partial` を完成扱いしない /
  go token 未観測 / stdout と out の不一致 / 非ゼロ終了 / 全体 deadline が段ごとに更新されない /
  未知 `DB_*` の混入拒否 / env 値の改行によるキー注入拒否 — を検査表へ追加した（計 21 件）。

---

## 施策 8

### [Warning] docblock の是正は in_progress の完全一致観測が入ってからにすべき
- 判断: **対応する**
- 対応内容: 施策 8 に「**施策 3・5・6 の完了を前提とする**」と依存を明記した
  （施策一覧の順序でも後ろに置く）。

---

## 施策 9

### [Warning] ファイル数の台帳が内部で一致していない
- 判断: **対応する**
- 根拠: そのとおり。`OutOfTransactionFixturesTest.php` が施策一覧に無く、本数も合っていなかった。
- 対応内容: 新規・変更ファイルを**全件列挙し直し**、
  fingerprint 判定・実装モード・本数の記述をすべて同じ母集団から書き直した
  （新規 16 本 = 支援 13 + テスト 3 / 変更 3 本）。

### [Suggestion] D7 の再判定記録に実更新日と feature revision を併記
- 判断: **対応する**
- 対応内容: feature revision (`14-3117f6369f21`) を再判定記録へ併記する形にした。
