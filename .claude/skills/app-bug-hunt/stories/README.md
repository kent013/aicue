# シナリオカード (stories/) — 書式の正本

bug-hunt はここに置いたカードを 1 枚ずつ実走する。カードは「利用者が実際に辿るジャーニー」を
1 本ずつ記述したもので、**どの画面・操作・機能を消化するか (割当) の正本もカードの前付け**である。

> **割当を `inventory/annotations.toml` に書かない。** 注釈が持つのは route ごとの意味
> (`kind` / `kubun` / `reason`) だけで、割当は本ファイルの規約に従ってカードの前付けに書く。
> 目録 (`screens.md` / `operations.md`) の「割当ストーリー」列は前付けから逆引き生成される。
>
> 機械検査は `stories/test_story_front_matter.py` (前付けの契約) と
> `scripts/bug-hunt-inventory.py` (割当と目録の突合) の 2 本が分担する。前者は
> `tests/Architecture/BughuntStoryToolSelfTest.php` が `composer test` の配線に載せる。

## 1. 前付けの制限文法

カードの先頭に前付けを置く。YAML に見えるが**読むのは下記の制限文法だけ**であり、
汎用 YAML パーサは使わない (読み取り器は `story_front_matter.py`)。

- **A1** 1 行目が厳密に `---`。次に現れる「行頭から `---` だけ」の行で閉じる
  (本文中の水平線・表の区切り行に影響されない)
- **A2** 1 行 1 項目。`key: value` (半角コロン + 半角空白 1 つ) だけを認める
- **A3** key は `^[a-z][a-z0-9_]*$`。**重複 key は fail**
- **A4** 値は 3 形のみ
  - **素のスカラー** — 前後空白なし・**引用符禁止**・`#` `:` 角括弧を含めない
  - **真偽値** — `true` / `false` のリテラルのみ
  - **配列** — `[]` または `[a, b, c]` (要素は `, ` 区切り。ネスト不可・引用符禁止)
- **A5** コメント行・空行・複数行スカラー・アンカー・参照・ネストマップは書けない。
  機械的には**値 (と配列要素) の先頭に `&` `*` `|` `>` `{` を置けない**ことで閉じる
  (YAML ならそこで構造が始まる記号である)。**位置で判定する**ので、`R&D` や
  `横幅 * 高さ` のように途中に現れる分には使ってよい
- **A6** key の並び順が下記の正準順序と一致する

## 2. 前付けの項目定義 (必須 13 key + 条件付き 1 key)

正準順序はこの表の並びである。

| # | key | 値 | 説明 |
|---|---|---|---|
| 1 | `id` | スカラー `^S[1-9][0-9]*$` | カード番号 (ゼロ埋め禁止)。**一意** |
| 2 | `title` | スカラー (非空) | H1 見出し `# {id}: {title}` と機械一致させる |
| 3 | `surface` | スカラー | 対象面。**表 A に実在する語だけ** (未登録は fail) |
| 4 | `lane` | `parallel_browser` / `serial_parent` | 実行方式 |
| 5 | `priority` | `P1` / `P2` / `P3` | 落ちたときに走行全体が無意味になるかで決める |
| 6 | `applicability` | `applicable` / `not_applicable` | 本アプリに該当する面か |
| 7 | `not_applicable_reason` | スカラー (非空) | **`not_applicable` のときだけ**この位置に置く。`applicable` にあれば fail |
| 8 | `depends_on` | 配列 (他カードの `id`) | 先に走らせる必要があるカード。無ければ `[]` |
| 9 | `reseed_before` | 真偽値 | 開始前に初期データへ戻すか |
| 10 | `accounts` | 配列 (下記のトークン語彙) | 使用アカウント |
| 11 | `setup` | 配列 (一行の準備事項) | 無ければ `[]` |
| 12 | `covers_screens` | 配列 (route 名) | 消化する画面 (safe method の web route) |
| 13 | `covers_operations` | 配列 (route 名) | 消化する操作 (非 safe method の web route) |
| 14 | `covers_capabilities` | 配列 `^[A-Z]+-[0-9]{2}$` | 消化する capability (`capability-catalog.md` の id) |

**`covers_*` の値の実在は本ファイル側の検査では見ない** (見るのは形だけである)。
実在の突合は目録側 (`scripts/bug-hunt-inventory.py`) の責務で、同じ規則を 2 か所に持たない。

## 3. `covers_*` の 3 欄に何を書くか

| 欄 | 母集合 | 検査 |
|---|---|---|
| `covers_screens` | **safe method (GET / HEAD / OPTIONS) の web route** | 実在 / 欄の意味 / 対象外でないこと / 分母の被覆 |
| `covers_operations` | **非 safe method の web route** | 同上 |
| `covers_capabilities` | `capability-catalog.md` の `capability_id 索引` の id | **実在・形・一意まで** (分母・被覆は見ない) |

- **対象内 (`kubun` が `外` でない) の web route は、1 枚以上の `applicable` なカードに載ること。**
  **区分 `終` は対象内**である (`外` だけが対象外)。載せる先が無い route は、注釈の区分を
  `外` にして理由を書くこと (目録に見える形で宣言する)。
- 1 つの route を複数のカードが挙げてよい (別視点で踏むのは正常)。**1 枚のカードの配列の中で
  同じ値を 2 回書くことはできない**。
- 対象面 (`surface`) が `admin_console` / `cli_or_api` の語彙は**予約**である。分母は
  ブラウザ (web 面) に閉じているので、該当するカードは今は無い。

## 4. 表 A: 対象面 (surface) の語彙

家系必須の 11 語は**削除・改名しない** (追記は自由)。

<!-- STORY-SURFACE-VOCABULARY:BEGIN -->

| surface | 面 | 由来 |
|---|---|---|
| `signup_funnel` | 登録・ログインファネル | テンプレート同梱 |
| `invitation` | 招待フロー | テンプレート同梱 |
| `core_journey` | アプリ中核ジャーニー (AI-CUE = SOP からマニュアル動画まで) | テンプレート同梱 |
| `org_project_admin` | 組織・プロジェクト管理 | テンプレート同梱 |
| `billing` | 課金 | テンプレート同梱 |
| `account_security` | セキュリティ (2FA / プロフィール) | テンプレート同梱 |
| `authz_boundary` | 認可境界 (IDOR) | テンプレート同梱 |
| `result_view` | 結果・レポートの閲覧 | 予約 |
| `admin_console` | 管理画面 | 予約 |
| `cli_or_api` | CLI / REST 面 | 予約 |
| `public_share` | 未認証で到達する共有リンク面 | 予約 |

<!-- STORY-SURFACE-VOCABULARY:END -->

## 5. 表 B: カード目録

実在するカードと 1 対 1 にする。`lane` / `priority` / `depends_on` の写しは**置かない**
(第二の正本を作らないため。正本はカードの前付けである)。

<!-- STORY-CARD-INVENTORY:BEGIN -->

| id | surface |
|---|---|
| S1 | `signup_funnel` |
| S2 | `invitation` |
| S3 | `core_journey` |
| S4 | `org_project_admin` |
| S5 | `billing` |
| S6 | `account_security` |
| S7 | `authz_boundary` |

<!-- STORY-CARD-INVENTORY:END -->

## 6. 番号規約と S8 以降の識別規約

- **D1** 番号は識別子であって意味を持たない。家系間の対応は `surface` で取る
- **D2** 既存番号の面を付け替えない (S1〜S7 の `(id, surface)` は家系で固定)
- **D3** `id` は一意
- **D4** 欠番を作らない。`S1` から最大番号まで連番。該当面が無くてもカードを消さず
  `applicability: not_applicable` で残す
- **D5** ファイル名は `S{n}-{任意の kebab}.md`。機械一致するのは**先頭セグメント `S{n}`** だけ
- **D7** S8 以降は番号でなく**対象面**で識別する。足すときは 3 か所を同じ変更で直す —
  表 A に面を 1 行 / 表 B に 1 行 / カードを 1 枚

## 7. 使用アカウントのトークン (`accounts`)

`guest` / `owner` / `admin` / `member` / `platform_admin` の 5 語だけ。**語彙を拡張しない**
(増やすと家系間の突合が緩む)。AI-CUE の ProjectRole (編集者 / 撮影者) のような
アプリ固有の役割は、トークンではなく**本文の散文**で表す。

> `accounts` と `database/seeders/ManualTestSeeder.php` の一致は機械検査しない
> (家系の正典も同じ。PR レビューの義務である)。

## 8. カード本文の確定形

前付けを閉じたあとの本文は次の形にする。

```markdown
# {id}: {title}

## 目的
(このジャーニーで利用者が達成したいこと。散文)

## 手順
1. (操作) → (期待)

## 逸脱アイデア (--deviate 時)
- (IDOR 探索・二重送信・戻る/リロード・隣接 ID 書き換え 等)
```

> 見出しの直後に空行を置くかは契約ではない (節の中身が空でなければよい)。
> ただし `## 手順` 節の中身は移行で 1 バイトも変えていないので、既存カードの形を保つこと。

- **J1** H1 見出しは `# {id}: {title}` に固定し、前付けと機械一致させる
- **J2** `## 目的` をちょうど 1 個持ち、節の中身が空でない
- **J3** `## 逸脱アイデア (--deviate 時)` をちょうど 1 個持ち、節の中身が空でない
- **H1** 旧メタ節 (`- 前提状態:` / `- 目的:` の箇条 /
  `## このストーリーで消化する screens / operations`) を**残さない**。同じ事実が前付けと
  散文の 2 か所に並ぶと、カード 1 枚の中に二重の正本ができる
- **F2** `applicability: not_applicable` のカードは `## 手順` 節を持たない
- **G6** 兆候番号 (`H{n}`) の**意味**はカードに書かない (語彙の正本は `SKILL.md` の
  横断ヒューリスティクス表)。カードは `H4` のような参照だけを持つ

## 9. 実行方式・依存・初期化要否の正本

- **E4** `lane` / `depends_on` / `reseed_before` の**正本はカードの前付け**である。
  本ファイルは写しを持たない
- **E1** `depends_on` の参照は実在し、自己参照でなく、循環しない
- **E2** `depends_on` を持つなら `reseed_before` は `false` (片方向のみ)
- **E3** `parallel_browser` のカードは `serial_parent` のカードに依存しない
- **E5** `scripts/bug-hunt-shard.sh` の固定 fan-out マップは**前付けからの派生キャッシュ**である。
  両者の一致は**機械検査しない** (家系の正典も未達)。カードの `lane` / `depends_on` を
  変えたら固定マップを手で追随させること

## 10. 本アプリが正典から外している契約

家系の正典が持つ契約のうち、本アプリが**採らない**ものを明示する
(逸脱の登録は `docs/template-divergence.md` **D40**)。

| 外している契約 | 理由 | 再判定の条件 |
|---|---|---|
| ステップ表の書式 (正準 4 列ヘッダ `\| step \| 操作 \| 期待 \| 注目 \|` / 疎な step 識別子 `{id}-{3 桁}` / 副ブロック / 期待欄・注目欄の書き分け) | 所見台帳の finding は story までしか指さず **step を指す欄を持たない**ため、ステップ識別子を入れても読む機械が 1 つも無い。手順は散文の番号付きリストのまま置く | `ledger/findings.schema.json` に step を指す欄が入ったとき / 家系の正典が t2 以降でステップ表を版の名前に含めたとき |
| `not_applicable` のカードを実走対象から外す契約 (`SKILL.md` 側) | 該当カードが **0 枚**であり、読む対象が 1 枚も無い契約を先回りして置かない | `applicability` に `not_applicable` を取るカードを 1 枚でも置くことになったとき (そのときの置き場は `SKILL.md`) |

正典との差で**採る側**にしたものは次の 2 点である (いずれも既存 **D20** が説明する)。

| 観点 | 家系の正典 | 本アプリ |
|---|---|---|
| `covers_screens` の母集合 | `kind` が `screen` / `read` / `redirect` の web route | **safe method の web route** (`kind` の語彙が `画面` / `JSON` で違うため、`kind` に依存させない) |
| `covers_capabilities` の検査 | 実在 / 欄の意味 / 分母 / 被覆の 4 段 | **実在・形・一意まで** (機能カタログが継承宣言の欄 `no_route` / `coverage_surface` / `covered_via` を持たないため、分母・被覆は見ない) |

## 11. 保証しないもの

- **割当が痩せたこと**は検出できない。目録側が見るのは「1 枚以上のカードに載っていること」
  だけなので、ある route が `S3 S7` から `S3` へ減っても緑のままである (PR レビューの義務)
- `web` group を宣言していない面 (機械向け API / Filament 管理画面 / MCP / 現在の webhook の
  大半) には沈黙する (既存 **D20** の保証境界)
- カードの前付けと `scripts/bug-hunt-shard.sh` の固定マップの一致 (上記 E5)
- `accounts` と seeder の一致 (上記 7 節)
