# 概念設計: bughunt-inventory-generator

bug-hunt の分母 (画面一覧 `screens.md` / 操作一覧 `operations.md`) を手書きから**生成物**へ移し、
ドリフト検査を「表に名前が載っているか」から「実装 + 注釈から再生成した結果と一致するか」へ作り替える。

- 家系台帳 feature: `bughunt-inventory-generation` (P2-13、aicue は pending)
- 正典 t1: 実装から生成する目録 + 人が書く注釈ファイル + 段階的なドリフト検査
- 本設計は **web 面の 2 目録の生成**までを扱う。機能カタログ (`capability-catalog.md`) は生成しない (後述)

---

## 背景・課題

### 事実 1: 目録は手書きで、同期は運用でしか担保されていない

`.claude/skills/app-bug-hunt/screens.md` (55 行) と `operations.md` (78 行) は
`php artisan route:list` から人が写した表である。実測 (本日 HEAD、`APP_ENV=local`) では
両表とも母集合と完全一致しているが、これは**直近 2 回の是正の結果**であって構造的な保証ではない。

- aicue:T134 (アプリ内招待受諾) の追加時に目録が同時更新されず、CI が exit 3 で停止 (aicue@6ced9f4)
- aicue:T142 (退会予約・取消の 2 route) で**同じ形の再発** (aicue@236329b)

2 巡連続で「機能を足した人が目録を直し忘れる」ことが実証されている。検知はできているが、
**直す作業が人手のまま**であり、直し方も「表の行を手で書き足す」なので同じ失敗が繰り返せる。

### 事実 2: 検査そのものに偽緑が 3 種類 + 母集合の誤分類が 1 種ある (`scripts/bug-hunt-inventory-check.sh` の実読)

| # | 弱点 | 帰結 |
|---|---|---|
| 1 | 照合が `grep -qF "${name}" "${file}"` の**ファイル全文の部分一致** | 表の行が 1 行も無くても、散文中に route 名が 1 度出ていれば「載っている」と誤判定する |
| 2 | 逆方向 (消失した route の行が残っている) は `echo` するだけで `drift` を立てない | 実装から消えた route の行が残り続け、分母が水増しされる |
| 3 | 母集合が 0 件でも `exit 0` | 抽出が壊れた走行 (空配列を返す等) が「差分なし」として緑になる |
| 4 | 面の判定が middleware 一覧を**文字列化して部分一致**している (`'web' not in str(middleware)`) | `throttle:webhook-stripe` のような値に `web` が含まれるため、web 面でない `cashier.webhook` が web 面として数えられていた (実測。名前の正規表現で後から落としているので結果は出ていない) |

加えて、対象外の宣言が name の正規表現 1 本 (`OUT_OF_SCOPE_PREFIXES`) に埋め込まれていて
**理由が書かれていない**。実測すると `recent-auth.sso.` / `passport.` / `sanctum.` / `default-livewire` の
4 つは 1 件も一致しない (死んだ除外) 一方、`social.*` や `two-factor.secret-key` のように
**意図して外している 13 route** はどこにも理由が残っていない。
家系の aigenba では同じ形の照合器が「記法を読めないだけの取りこぼし」を drift として報告し、
偽の欠落を量産した実績がある (2026-08-10 の巡回観測)。

### 事実 3: 画面側と操作側でフィルタが非対称

画面側の抽出は uri に `debug` を含む route を落とすが、操作側は落とさない。
結果、`debug.login-as` (POST) は表にあり `debug.login` (GET) は表に無い。
**同じ機構の対 (画面と操作) が別の扱いを受けている**が、どちらの表にもその判断は書かれていない。

### 事実 4: 母集合は実行環境に依存する

`routes/web.php` の debug 4 route は `app()->isLocal() || app()->runningUnitTests()` で
**route 登録そのものを囲って**いる。つまり `route:list` の結果は APP_ENV で変わるが、
現在の検査は抽出時の環境を記録も検証もしない。環境が変われば母集合が黙って変わる。

### 事実 5: T164 で「実際に叩けた route」を機械記録する経路ができた

`BughuntExecutedRouteMiddleware` → `coverage/build_executed.py` → `coverage/correlate.py` の
経路が本日 main に入り、**主入力が揃わない走行は終了コード 3 で落とす** fail-closed の作法が
確立した (`docs/template-divergence.md` D14)。照合器の join キーは
`operations.md` の **name 列**であり、この表が手書きである限り
「打ち間違えた route 名は永久に未実行として出続ける」(照合器からは検出できない)。
分母を生成物にすると、この join キーが機械的に実在する route 名であることが保証される。

---

## 改善アイデア

**実装から導ける事実 (機械事実) と、人が決めた意味 (注釈・散文) を層に分ける。**

```
[抽出]   php artisan bughunt:inventory-scan   … route 定義 + 画面題名 + 抽出条件を JSON で標準出力へ
   ↓
[合成]   scripts/bug-hunt-inventory.py        … 機械事実 × 注釈 × 散文ノート (メモリ上)
   ↓
[描画]   同上 (generate)                       … screens.md / operations.md を書き出す
   ↓
[検査]   同上 (check)                          … メモリ上で再生成して byte 比較 (1 バイトも書かない)
```

| 層 | ファイル | 誰が書くか |
|---|---|---|
| 機械事実 | (`bughunt:inventory-scan` の標準出力。ファイルに残さない) | 実装 |
| 注釈 | `.claude/skills/app-bug-hunt/inventory/annotations.toml` | 人 |
| 散文 | `.claude/skills/app-bug-hunt/inventory/notes-screens.md` / `notes-operations.md` | 人 |
| 生成物 | `.claude/skills/app-bug-hunt/screens.md` / `operations.md` | 生成器 |

**中間の JSON はコミットしない**。読み手がいない成果物を置くとドリフト面が増えるだけで、
機械事実は生成・検査の実行中にだけ存在すればよい (`correlate.py` が読むのは `operations.md` の name 列である)。

### 母集合の定義 (集計単位を先に決める)

**数える単位は route オブジェクト**である (`Route::getRoutes()` が返す 1 件 = 1 行)。
method 別の内訳は route オブジェクトが持つ method 文字列ごとの排他分類であり、
1 route が複数 method を持つ場合でも 1 件としてしか数えない。

web 面の判定は次の 2 条件の**論理積**:

1. route に宣言された middleware の**要素として** group 名 `web` がある
   (注入した `Illuminate\Routing\Router` の `getRoutes()` から各 route を取り、
   `gatherMiddleware()` が返す**宣言のままの**一覧を見る。
   `Router::gatherRouteMiddleware()` は group を展開して `web` を消すので使わない。
   また**文字列化した部分一致にはしない** — `throttle:webhook-stripe` が `web` に一致してしまう)
2. uri の**先頭セグメント**が下の除外表に載っていない

| 先頭セグメント | 除外する面 | 実測 |
|---|---|---|
| `oauth` | Passport の OAuth 認可面 (機械連携のための認可・端末認可画面。運用導線の `organizations.api-keys.*` は web 面として目録に載る) | 8 route |
| `livewire` で始まるもの (`livewire-{hash}/…`) | Livewire の内部通信 (管理画面 `/admin` の裏方であり画面ではない。先頭セグメントに環境ごとのハッシュが付くため前方一致で見る) | 3 route |

**除外はこの 2 つだけにする**。`api` / `admin` (Filament) / `.well-known` / `storage` は
そもそも `web` group を宣言していないので条件 1 で外れる (実測 0 件)。
死んだ除外規則を並べておくと「将来 `api/` 配下に web 面の route ができた」ときに
黙って落としてしまうので、**条件 1 を通ったものは原則すべて目録に入れて注釈を要求する**
(新しい面が現れたら未注釈の drift として人の目に触れる)。

実測 (`APP_ENV=local`、本日 HEAD):

| 集合 | 件数 |
|---|---|
| 全 route オブジェクト | 211 |
| `web` group を宣言している route | 158 |
| web 面 (上の除外 11 件を引いたもの) | **147** |
| ├ 画面表 (GET / HEAD のみ) | 68 (内訳: `GET\|HEAD` 68) |
| └ 操作表 (非 GET を含む) | 79 (内訳: POST 51 / DELETE 15 / PATCH 10 / PUT 3) |
| 名前を持たない web 面 route | 0 |
| 名前が重複する web 面 route | 0 |

**画面表 ⊎ 操作表 = web 面**が成り立つ (68 + 79 = 147) ことを自己テストの不変条件として登録する。

### どの表に載るかは method から排他的に決める

- **非 GET メソッドを 1 つでも持つ route → 操作表**、**GET / HEAD だけの route → 画面表**
  (現行シェルは先頭 method で判定しており、`GET|POST` の route が現れると書き込み操作が分母から落ちる)
- 実測では GET と非 GET を併せ持つ route は **0 件**なので 1 route = 1 注釈で足りる。
  ただし将来現れたときに黙って画面の分母から落ちるのは分母生成器として危険なので、
  **併せ持つ route は段 2 で drift (exit 3) として拒否する** (必要になった時点で注釈モデルを広げる)
- **名前を持たない / 名前が重複する web 面 route は段 1 で exit 2**。
  `operations.md` の name 列は `correlate.py` の join キーなので、空名や重複があると
  「join キーが実在 route 名である」という保証が崩れる

### 対象外は 2 層に分ける (どちらも暗黙にしない)

| 層 | 何を意味するか | 宣言の場所 |
|---|---|---|
| 面が違う | bug-hunt のブラウザ走行が扱う面ではない (`web` group を宣言していない機械向け API / 管理画面、および除外表の 2 面) | 面の定義そのもの (上記 2 条件と除外表。根拠は 1 行ずつ書く) |
| web 面だが対象外 | ブラウザから到達しうるが、探索の分母に載せない (機械可読 route・外部 IdP へ出る遷移・秘密の開示 endpoint 等) | 注釈の区分 `外` + 30 文字以上の理由 |

**名前の正規表現による対象外指定 (`OUT_OF_SCOPE_PREFIXES`) は廃止する**。
今まで正規表現に沈んでいた 13 route (seo 4 / social 2 / two-factor の秘密開示 3 /
`password.confirmation` / debug 3) は、理由付きの行として目録に現れる。
web middleware を持たない `cashier.webhook` は面の判定で自然に外れ、
web middleware を持つ `webhooks.ses` は**操作表に載って区分 `外`** になる
(現行シェルは名前に `webhook` を含む route を暗黙に落としていた)。

### 検査は 4 段にする (それぞれ「何を守るのか」を 1 つに絞る)

| 段 | 守るもの | 破れたときの終了コード |
|---|---|---|
| 1 | **抽出の可用性**: 抽出コマンドが成功し、宣言した抽出条件で走り、web 面の母集合が 0 件でない | 2 (致命) |
| 2 | **注釈の定義域一致**: 注釈の集合 = 機械事実の集合 (未注釈の route も、実装から消えた route の注釈残置も許さない) + 語彙と理由の形式 | 3 (ドリフト) |
| 3 | **生成物の一致**: `screens.md` / `operations.md` を再生成して byte 比較 | 3 (ドリフト) |
| 4 | **機能カタログの参照整合**: `capability-catalog.md` の代表機構列の route 名が実在し、id が重複しない | 3 (ドリフト) |

- `check` は段 1 → 2 → 3 → 4 を通し、**1 バイトも書かない**
- `generate` は段 1 → 2 → 4 を通してから書く (段 3 は「既存生成物との差」なので generate では判定に使わない)

段 5 (未実行の列挙) は**作らない**。それは T164 で入った `correlate.py` の担当であり、
同じ一覧を 2 か所で計算しない。

### 生成器が入ったらドリフト検査は何を守るのか (この設計の要点)

生成方式に移ると、**実装から抽出できた route については**「表に行を書き忘れる」事故が
byte 比較で必ず検出される (人が行を書く場面が無くなる)。
代わりに検査が守る対象は次の 4 つへ移る。

1. **再生成の忘れ** (route を足したが `generate` を走らせていない) → 段 3 が byte 比較で捕まえる
2. **生成物の手編集** (生成物を直接いじって注釈へ戻さない) → 同上
3. **意味の欠落** (新しい route にストーリー割当も対象外理由も無い) → 段 2 が deny-by-default で捕まえる
4. **抽出の故障** (環境違い・母集合 0 件) → 段 1 が fail-closed で捕まえる

つまり検査は「目録の内容を守る器」から「**生成の前提と生成物の同一性を守る器**」へ役割が変わる。
CI のステップ名と起動行 (`bash scripts/bug-hunt-inventory-check.sh`) は据え置く
(`tests/js/architecture/ci-workflow-inventory.test.ts` の W16 が起動行を固定しているため)。

### 沈黙で空を出さない (T164 の作法へ揃える)

- `check` は**1 バイトも書かない**。`generate` は段 1 / 2 / 4 を通ったときだけ、
  **一時ファイル → `os.replace()`** で 2 ファイルを差し替える。
  保証するのは「**各ファイルについて書きかけの内容が露出しない**」ことまでで、
  2 ファイルの同時更新は保証しない (1 本目の置換後に停止すれば片方だけ新しくなる)。
  その部分更新は次の `check` が段 3 で exit 3 として検出する。
  ロールバック機構は作らない (生成物の性質に対して過剰)
- 母集合 0 件 / 抽出コマンドの非 0 終了 / 抽出条件の不一致 / 散文ノートの不在は **exit 2** で、生成物には触れない
- 注釈の欠落は **例外で落とさず drift 行として列挙して exit 3**
  (家系 spirux では同じ箇所が `KeyError` → exit 1 になり終了コード規約から外れた実績がある。先回りで塞ぐ)
- 想定外の例外は `traceback` を出して **exit 2**。0 に畳まない

### 抽出条件を宣言して固定する (母集合が環境で変わることへの手当て)

母集合を環境依存にしているのは debug 4 route の登録条件
(`app()->isLocal() || app()->runningUnitTests()`) だけである (routes/ と Providers を全数確認した)。
そこで、

- 抽出コマンドは**同じ述語**が成り立たない環境では非 0 終了する (production では走らない)
- 生成物のヘッダには**環境名ではなく抽出条件のラベル**を書く。
  local 実行と Pest 実行は同じ母集合になるので、ラベルも同一になり偽ドリフトが出ない
- 生成器は抽出結果に含まれる抽出条件を再検証し、期待と違えば **exit 2** (二重に確認する)

### 注釈に課す形式 (deny-by-default)

| 項目 | 規則 |
|---|---|
| 区分の語彙 | `通常` / `逸` / `終` / `外` の 4 語のみ。未知語は drift |
| 対象外の理由 | 区分が `外` / `終` なら **30 文字以上**の理由が必須 (本リポジトリの他の目録と同じ強さ) |
| ストーリー割当 | 区分 `通常` / `逸` なら S1..S7 のいずれか必須 |
| 画面の種別 | 画面表の route は `画面` / `JSON` のいずれか必須 (画面でないものが画面の分母に混ざるのを防ぐ) |
| 画面名 | `config('seo.app_titles')` から引く。**無ければ空欄** (題名の欠落は drift にしない) |

画面名を必須にしない判断は実測に基づく: 現行の対象内 50 画面 (付随 JSON GET 5 本を除く) のうち
`app_titles` に題名があるのは **33 件**で、17 件は無い。無い側には
公開ページ (`home` / `pricing` / `legal.*`) や、controller が実行時に固有名を供給する画面
(`projects.show` / `projects.manuals.show` 等) が含まれる。
`config/seo.php` はブラウザのタブ題名を出すための設定であって目録のための表ではないので、
**目録の都合で 17 件の記入を強制しない**。

---

## 3 列の機能カタログをどうするか (台帳が求める決着)

**aicue の 3 列 (`id` / `機能 (actor→outcome)` / `代表機構 (route name)`) を維持し、家系標準の 3 列
(`機能` / `対応する画面` / `対応する操作`) へは寄せない。** `docs/template-divergence.md` に逸脱として登録する。

理由:

1. **id 列は finding 記録の語彙正本である**。`ledger/findings.schema.json` の必須フィールド
   `capability_tag` は capability-catalog の id を値に取る。家系標準の 3 列には id 列が無く、
   寄せると語彙の供給元が消える (`unknown` / `unmapped` の判定基準ごと壊れる)
2. **「機能 ↔ 画面 / 操作」は注釈側で route ごとに持つ方が単一になる**。カタログにも書くと
   同じ対応関係が 2 か所に載る (家系が AG-044 で明示的にやめた形)
3. カタログ本体は「機構を利用者価値で束ねた overlay であり MECE ではない」と自ら宣言しており、
   **実装から導けない**。生成対象にすると人が書いた責務境界の散文を注釈ファイルへ移すだけで、
   保守量は減らない

その代わり、機械で確かめられる部分だけを段 4 で守る (代表機構の route 名が実在すること / id が重複しないこと)。
**カタログの網羅性は検査しない** (overlay なので網羅を主張しない)。

---

## 期待効果

- **使命への貢献 (間接・補助的。誇張しない)**: 本設計が直接良くするのは「探索的バグハントの分母の信頼性」である。
  bug-hunt は撮影 PWA (現場作業者が触る面) の詰み・認可漏れを見つける装置で、分母が実装から取り残されると
  「ここまで回れた」という判断そのものが嘘になる。その品質保証の精度を上げる形で使命に効く
- 機能追加時の作業が「表を手で直す」から「**再生成 + 注釈 1 行**」に変わる。
  注釈は route ごとに 1 行なので、書くべきことが機械的に分かる (どのストーリーで通すか / なぜ対象外か)
- **定義した web 面の判定が抽出した母集合について**、上記の偽緑 3 種 (散文一致・消失 warning 止まり・
  母集合 0 件) と母集合の誤分類 1 種 (middleware の部分一致) が消える。
  面の判定そのものが route を取りこぼす可能性は残る
- 対象外 13 route が**理由付きの行**として目録に見えるようになる (今は正規表現の中に沈んでいる)
- `correlate.py` の join キー (name 列) が実在 route 名であることが構造的に保証される

## 実装方針 (概要)

| 種別 | ファイル | 変更 |
|---|---|---|
| 新規 | `app/Console/Commands/Bughunt/InventoryScanCommand.php` | `bughunt:inventory-scan` — Router から route を直接読み、`config('seo.app_titles')` と抽出条件を添えて JSON で標準出力へ。抽出条件を満たさない環境では非 0 終了 |
| 新規 | `app/DataTransferObjects/Bughunt/InventoryScanData.php` / `InventoryRouteData.php` | 走査結果の型 (PHPStan level 10 で形を固定)。Command は DTO を組み立てて JSON 化するだけ |
| 新規 | `scripts/bug-hunt-inventory.py` | 生成器兼検査器 (`generate` / `check`)。stdlib のみ |
| 改稿 | `scripts/bug-hunt-inventory-check.sh` | 判定を持たない薄い呼び出し (`python3 scripts/bug-hunt-inventory.py check`) |
| 新規 | `.claude/skills/app-bug-hunt/inventory/annotations.toml` | 人が書く注釈 (route ごと) |
| 新規 | `.claude/skills/app-bug-hunt/inventory/notes-screens.md` / `notes-operations.md` | 現行 md の散文節を移設 |
| 改稿 | `.claude/skills/app-bug-hunt/screens.md` / `operations.md` | 生成物になる (冒頭に「生成物。手で編集しない」と明記) |
| 改稿 | `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` | 新しい契約 (0/2/3・薄い呼び出し・fail-closed) へ作り替える |
| 新規 | `scripts/tests/test_bug_hunt_inventory.py` + `tests/Architecture/BughuntInventoryToolSelfTest.php` | 生成器の自己テストと、それを `composer test` に載せる配線 (既存 `BughuntCoverageToolSelfTest` と同じ形) |
| 追記 | `docs/template-divergence.md` | 機能カタログの 3 列維持 / 注釈が TOML であること |
| 追記 | `AGENTS.md` §bug-hunt / `scripts/README.md` | 目録が生成物であること・再生成の手順・恒久スクリプトの台帳登録 |

注釈ファイルの形式は **TOML** にする。AGENTS.md が Python ツールを stdlib のみと定めており、
本環境に PyYAML は無い (実測) 一方、`tomllib` は標準ライブラリにある。
家系の実装は `annotations.yaml` だが、YAML を読むには依存追加か自前パーサが要り、
どちらも「自前機構を作らない」に反する。逸脱として登録する。

## 制約・前提

- **`operations.md` の列形式は変えない**: `| method | route | name | story | 区分 |` の 5 列。
  `correlate.py` がヘッダ名から列位置を決めて join する (name 列が join キー)。
  生成器はこの形式を出力契約として守る
- **区分の語彙を固定する**: 現行の表は全行 `通常` だが、`correlate.py` の語彙集合には `通常` が無い
  (未知語は in_scope 扱いに落ちるので実害は出ていない)。生成器側で語彙を
  `通常` / `逸` / `終` / `外` に pin し、未知語を drift にする。
  意味を持つ 2 語 (`外` = 分母外 / `逸` = 未実行でも警告しない) が `correlate.py` の定数と
  一致することは自己テストで機械照合する
- 抽出は**抽出条件を宣言して固定する**。生成物のヘッダに書くのは**環境名ではなく抽出条件のラベル**で、
  `check` は抽出結果のラベルが期待と一致するときだけ成立させる (不一致は exit 2)
- 走査対象は **web 面のみ** (`api/` / Filament `/admin` / MCP / oauth / livewire は対象外)。
  T164 の記録器は web グループ全体を観測するので、集合としては
  **目録の母集合 ⊆ 記録器が観測しうる route** であり、両者は一致しない。
  目録に無い route が記録に現れても `correlate.py` は無視する (未実行 worklist は目録側の集合で作る)
- **PHP 側の型**: `InventoryRouteData` の method / middleware / 題名は array shape で宣言し、
  `config('seo.app_titles')` は Command 境界で `is_array` とキー・値の型を検証してから DTO へ渡す。
  JSON 化は `JSON_THROW_ON_ERROR` を使い、失敗は非 0 終了へ写像する
- 既存の CI ステップ名・起動行は変えない (`ci-workflow-inventory.test.ts` W16)
- 初期の注釈ファイルは現行 2 表から機械的に起こす (一度きりの移行スクリプトは `devnotes/` に置く)

## スコープ外

- **`capability-catalog.md` の生成** (上記のとおり検査のみ)
- **未実行リストの算出** (T164 の `correlate.py` が持つ。同じ一覧を 2 か所で作らない)
- **面 (surface) パリティ登録簿**。家系の一次観測は「専用の登録簿が事故を見つけた例は 1 件も無い /
  手書き起点なので登録漏れに fail-open」であり、作る根拠が無い
- **api / MCP / 管理画面の目録化**。web 面の分母だけを扱う (保証範囲を誇張しない)
- **stories/ の前付け化** (家系 feature `bughunt-story-structure` の担当)
- **画面の到達可能性の保証**。目録が緑でも「その画面が開ける」ことは示さない

## 保証しないもの (誇張しない)

- 抽出対象は web 面だけである。`api/` / Filament `/admin` / MCP / oauth / livewire の面には**沈黙する**
- 注釈の**内容**の妥当性は機械が見ない (語彙・形式・定義域まで。ストーリー割当が適切かはレビューの責務)
- 画面題名の欠落は検出しない (`config('seo.app_titles')` は目録のために存在するものではない)
- GET / HEAD と非 GET を併せ持つ route は**現在の注釈モデルでは表現せず、段 2 で drift として拒否する**
  (必要になった時点で分類モデルを設計し直す。黙って片方の分母から落とさない)
- 機能カタログの**網羅性**は見ない (代表機構の実在と id の一意性まで)
- 目録が緑でも、その操作が実際に叩けるかは示さない (それは T164 の記録と `correlate.py` の担当)
