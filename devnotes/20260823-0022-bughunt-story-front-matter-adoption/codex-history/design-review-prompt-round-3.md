# Round 3: Round 2 指摘への対応と再レビュー依頼

Round 2 の全指摘 (Critical 4 / Warning 6 / Suggestion 2) を捌いた。

**Critical 4 件の対応**:
1. AC-14 を **58 項目の分割 (partition)** へ作り直した。`ALL_INVARIANTS` を点呼の基準として先に固定し、
   `ADOPTED` / `DIFFERENCES` / `NOT_ADOPTED` の排他性と全数一致、`len == 58`、
   `ADOPTED` 全件に担い手があること、担い手集合に未知 ID が無いこと、
   `NON_MECHANICAL` の完全一致を assert する形にした。I 群の漏れと I6 の欠落も埋めた。
2. 主題からテスト名を推測する形をやめ、`SUBJECT_TO_TESTS` の明示対応 + `callable` 確認 +
   各主題に accepts / rejects が 1 本以上あることの assert へ直した。
3. `終` の対象内化を全 consumer へ波及させた。`KUBUN_NEEDS_REASON` の**全利用箇所の棚卸し表**を作り、
   「reason 要否」と「scope 判定」を分離する規律を明記した (render の対象外件数 / 対象外節を含む)。
4. `load_assignment()` の戻り値を `tuple[Assignment | None, list[str]]` へ直し、
   違反時に `None` を返して呼び出し側がレンダリングへ進まないことを明記した。

**Warning への対応**:
- `終` の因果説明を「スカラー模型の制約」から「**意図的な意味変更**」へ訂正した。
- `load_assignment()` が自分で見る範囲 (id 形式・一意性 / applicability 語彙 / 配列要素の型・形式・重複) を表にした。
- 終了コードを原因別に固定した (形式違反 = exit 3 / 検査成立不能 = exit 2)。
- AC-15 に「節の中身が空でないこと」を足し、空節の負例を計画へ加えた。
- 「保証しないもの」と `NON_MECHANICAL` の 1 対 1 の範囲を E5 / G6 の 2 件へ限定した。
- `MIN_TESTS > 0` の専用テストを足した。
- 施策 1 の「3 点」を「4 点」へ訂正した。
- **`EXPECTED_S7_ADDED_SCREENS` を 11 件の route 名で確定**させた (全件の実在と区分を実測で確認)。
  「新規消化はしない」という散文の意味も書き分けた。

**Suggestion への対応**: `CORE_NEGATIVES` の名前を実態へ合わせ、手順節ハッシュの抽出境界を明文化した。

**再レビューしてほしい点**:
- (a) AC-14 の partition 設計が、指摘された「一覧から項目を落としても気づかない」問題を本当に解いているか。
- (b) `終` の波及確認 (棚卸し表) に漏れている consumer が無いか。
- (c) `EXPECTED_S7_ADDED_SCREENS` の 11 件の選び方に誤りが無いか
  (S3 / S4 が消化している nested screen のうち、組織 B 視点で踏み直すもの)。
- (d) 残っている Critical / Warning があるか。無ければ APPROVED を返してほしい。

---

# 対応マトリクス: design-review Round 2

Codex 判定: CHANGES_REQUESTED (gpt-5.6-sol / high)。施策別 APPROVE 6 / REQUEST_CHANGES 5。
Critical 4 / Warning 6 / Suggestion 2。**すべて対応した**。

## [Critical] 施策 4: AC-14 がまだ全数点呼になっていない (I 群が丸ごと漏れ / NON_MECHANICAL が未使用)

- 判断: **対応する**
- 根拠: 指摘が正確。`ADOPTED_INVARIANTS` 自体が手書きの一覧なので、そこから項目を落とせば点呼も気づかない。
  実際に提示した一覧から I 群が丸ごと抜けており、`INVENTORY_SIDE` に I1〜I5 を書いても
  点呼のループは `ADOPTED_INVARIANTS` しか走らないので意味を持っていなかった。I6 はどの集合にも無かった。
  `NON_MECHANICAL` も assert に使っていなかった。
- 対応内容: **58 項目の分割 (partition) を検査側が独立に持つ**形へ全面的に書き直した。
  - `ALL_INVARIANTS`（全 58 件。点呼の基準）を先に固定する。
  - 分類を `ADOPTED` / `DIFFERENCES` / `NOT_ADOPTED` の**互いに排他な 3 集合**にし、
    和が `ALL_INVARIANTS` と一致することを assert する。`len(ALL_INVARIANTS) == 58` も固定する。
  - 担い手を `STORY_SIDE` / `INVENTORY_SIDE` / `NON_MECHANICAL` の 3 集合にし、
    **集合同士の重複を許す**（B16 のように両側に現れる項目を表せる）。
  - `ADOPTED` の全件が担い手のいずれかに属すること、担い手集合に未知 ID が無いことを assert する。
  - `NON_MECHANICAL` を assert に使う（`("E5", "G6")` と完全一致）。

## [Critical] 施策 4: 検査メソッドの存在確認が実際の命名と一致しない

- 判断: **対応する**
- 根拠: 確定的な誤り。`AC-01` から作った `test_ac_01` は
  実際の `test_ac_01_rejects_quoted_scalar` と一致せず、`hasattr` が常に偽になる。
- 対応内容: 主題からテスト名を**推測しない**形へ直した。
  `SUBJECT_TO_TESTS` で主題 → テスト名の並びを明示対応させ、
  各名前が `callable` であること・各主題に `accepts`（正例）と `rejects`（負例）が
  1 本以上あることを assert する。

## [Critical] 施策 7: `終` の対象内化がレンダリング・対象外節まで波及していない

- 判断: **対応する**
- 根拠: 指摘が正確。`render_screens()` / `render_operations()` の「うち対象外」件数と
  `_out_of_scope_section()` が `KUBUN_NEEDS_REASON` をスコープ判定に使っている。
  `validate_assignment()` だけを直すと「割当必須なのに対象外件数へ入る」矛盾が生まれる。
- 対応内容: **`KUBUN_NEEDS_REASON` の全利用箇所の棚卸し表**を設計へ足し、
  「reason 要否」と「scope 判定」を分類した。規律を明記した —
  **`KUBUN_NEEDS_REASON` は reason 要否だけに使う。scope 判定はすべて `KUBUN_OUT_OF_SCOPE` に統一する。**
  統合テスト（`終` が通常の一覧・対象内件数へ入り、対象外節へ入らないこと）をテスト計画へ足した。

## [Critical] 施策 7: `load_assignment()` の非 optional 戻り値と「構築しない」契約が矛盾

- 判断: **対応する**
- 根拠: 指摘が正確。`tuple[Assignment, list[str]]` では違反時にも必ず `Assignment` を返す必要があり、
  空の Assignment を返すと呼び出し側の確認漏れで生成できてしまう。
- 対応内容: 戻り値を **`tuple[Assignment | None, list[str]]`** へ直し、
  違反が 1 件でもあれば `None` を返すこと、`_prepare()` / `run_check()` / `run_generate()` は
  `None` を受けたらレンダリングへ進まず目録を 1 バイトも書かないことを明記した。

## [Warning] 施策 7: 「`終` にstory を書けなかったのはスカラー模型の制約」という説明に根拠が無い

- 判断: **対応する（説明を訂正する）**
- 根拠: 指摘のとおり。単一値でも `終` に 1 枚割り当てることはできた。
  データ構造上自然に消える制約ではなく、**意図的な意味変更**である。
- 対応内容: 因果説明を次へ書き直した —
  「現行は `終` を割当の対象外としていたが、正典の『**`外` 以外は対象内**』へ**意図的に意味を変更する**。
  変更後の `終` は `reason` 必須かつカード割当必須になる。」
  あわせて全 consumer の波及確認（棚卸し表）を明記した。

## [Warning] 施策 7: 型だけの検査では生成器単体の fail-closed として不足

- 判断: **対応する**
- 根拠: 妥当。特に不正な `id: SX` は `int(card_id[1:])` で例外になり、違反として報告されない。
- 対応内容: `load_assignment()` が**自分が消費する項目**について見る範囲を表にした —
  `id` の形式（`fullmatch`）と一意性 / `applicability` の語彙 / `covers_*` の要素が文字列であること /
  route 名・capability id の形式（`fullmatch`）/ **配列内の重複**（`frozenset` 化する前に見る）。
  見ないもの（正準順序 / 表 A・表 B / `lane` / `priority` / `depends_on` / H1 / 旧メタ節）も明記した。

## [Warning] 施策 7: 終了コードの期待が「exit 3 または exit 2」では広すぎる

- 判断: **対応する**
- 根拠: 妥当。どちらでも合格すると終了コード契約の後退を検出できない。
- 対応内容: 原因別に固定した —
  形式違反・語彙違反・配列内重複・割当のドリフト → **exit 3** /
  `stories/` が無い・カードが読めない → **exit 2**（検査成立不能）。
  どちらでも生成物が 1 バイトも変わらないことを併せて確認する。

## [Warning] 施策 4: AC-15 が空節を許す

- 判断: **対応する**
- 根拠: 妥当。見出し数だけでは J2 の「散文を持つ」を保証しない。
- 対応内容: AC-15 の定義へ「見出しの直後から次の H2 見出しの直前までを取り、
  空白を除いて非空であること」を足し、`## 目的` / `## 逸脱アイデア` の**空節の負例**を計画へ加えた。

## [Warning] 施策 4: 「保証しないもの」と `NON_MECHANICAL` が 1 対 1 ではない

- 判断: **対応する（範囲を限定する）**
- 根拠: 妥当。表には I5 と ID なしの項目も含まれている。
- 対応内容: 「1 対 1 に対応するのは**採用と分類した非機械保証の 2 件（E5 / G6）だけ**」と範囲を明示し、
  I5 は分類が「差」で担い手が目録側であること、ID を持たない 4 件は機構全体の保証境界であって
  不変条件の分類ではないことを書き分けた。

## [Warning] 施策 5: `MIN_TESTS = 0` の置き忘れを検出する仕組みが無い

- 判断: **対応する**
- 根拠: 妥当。0 のままだと件数 pin が常に成功し、機構ごと無効になる。
- 対応内容: PHP 側に `expect(StoryFrontMatterPins::MIN_TESTS)->toBeGreaterThan(0);` の
  専用テストを足した（PHPDoc の `positive-int` だけでは実行時の 0 を防げない旨も明記）。

## [Warning] 施策 1: 本文が「差は 3 点だけ」のまま、表は 4 行

- 判断: **対応する**
- 対応内容: 「次の 4 点だけ」へ訂正した。

## [Warning] 施策 11: `EXPECTED_S7_ADDED_SCREENS` がプレースホルダーのまま

- 判断: **対応する（route 名を確定させた）**
- 根拠: 妥当。手作業で起こす S7 画面の誤割当を防ぐのが今回の強化の目的なので、
  空のままでは目的を果たさない。
- 対応内容: **11 件を route 名で列挙**した（`capture.manuals.show` / `capture.takes.playback` /
  `projects.categories.index` / `projects.edit` / `projects.manuals.download` /
  `projects.manuals.edit` / `projects.manuals.jobs.show` /
  `projects.manuals.render-jobs.playback` / `projects.manuals.render-jobs.show` /
  `projects.manuals.show` / `projects.show`)。
  全件が `annotations.toml` に実在し区分が `通常` であることを実測で確認した。
  あわせて現行カードの「新規消化はしない」という散文の意味を書き分けた —
  それは「目録の未割当を埋める新規消化が無い」であって「S7 が何も開かない」ではない。
  集計表の `N 件` も `11 件` へ確定させ、施策 9 の差分記述も「操作 9 件 + 画面 11 件」へ直した。

## [Suggestion] 施策 5: `CORE_NEGATIVES` の `test_ac_06_family_surface_pin` は名前上は負例でない

- 判断: **対応する**
- 対応内容: `test_ac_06_rejects_reassigned_family_surface` へ置き換えた（定数名と実態を一致させた）。

## [Suggestion] 施策 11: 手順節ハッシュの抽出境界を明文化せよ

- 判断: **対応する**
- 対応内容: 「`## 手順` の見出し行の次の行から、次に現れる H2 見出しの直前の行まで。
  末尾の空行は落とさない。次の H2 が無ければファイル末尾まで」と明文化し、
  旧メタ節が別の H2 節なのでこの境界に入らないことを明記した。


---

## 改訂後の詳細設計 (全文)

# 詳細設計: bughunt-story-front-matter-adoption

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. **Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)**

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成（本作業は DB を使わないため該当なし）
- **DTO + JsonResource** パターン（本作業は HTTP 応答を作らないため該当なし）
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **Python は標準ライブラリのみ**（AGENTS.md §bug-hunt）

## 概念設計リファレンス

- [devnotes/20260823-0022-bughunt-story-front-matter-adoption/conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 5 で APPROVED）

---

## 正典の不変条件の全数対応表

書式の正本の**原本実読**（`get_source(laravel-claude-template, .claude/skills/app-bug-hunt/stories/README.md)`、
resolved_commit `5dd85a6da620e1c957885c694f8be56d40425af2`）と、
正典の機械検査の原本実読（同 `test_story_front_matter.py`）から、契約を **10 群 58 項目**に分解して全数を宣言する (A6 + B16 + C5 + D7 + E5 + F2 + G6 + H1 + I7 + J3 = 58)。

分類は **採用** / **差**（aicue 固有差。既存 D で説明済み）/ **未採用**（新規 D で登録）の 3 つ。

### A 群: 前付けの制限文法

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| A1 | 1 行目が厳密に `---`、次に現れる「行頭から `---` だけ」の行で閉じる（本文中の水平線・表の区切り行に影響されない） | 採用 | 施策 3 / 4 |
| A2 | 1 行 1 項目。`key: value`（半角コロン + 半角空白 1 つ）だけを認める | 採用 | 同上 |
| A3 | key は `^[a-z][a-z0-9_]*$`。**重複 key は fail** | 採用 | 同上 |
| A4 | 値は 3 形のみ — 素のスカラー（前後空白なし・**引用符禁止**・`#` `:` 角括弧を含めない）/ 真偽値（`true` `false` リテラルのみ）/ 配列（`[]` または `[a, b, c]`。ネスト不可） | 採用 | 同上 |
| A5 | コメント行・空行・複数行スカラー・アンカー・参照・ネストマップは書けない | 採用 | 同上 |
| A6 | key の並び順が正準順序と一致する | 採用 | 同上 |

### B 群: 項目定義（必須 13 key + 条件付き 1 key）

正準順序は `id` / `title` / `surface` / `lane` / `priority` / `applicability` /
（`not_applicable_reason`）/ `depends_on` / `reseed_before` / `accounts` / `setup` /
`covers_screens` / `covers_operations` / `covers_capabilities`。

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| B1 | 必須 13 key の全数と正準順序 | 採用 | 施策 4 |
| B2 | `id` が `^S[1-9][0-9]*$`（ゼロ埋め禁止） | 採用 | 同上 |
| B3 | `title` が空でなく、H1 見出し `# {id}: {title}` と機械一致 | 採用 | 同上 |
| B4 | `surface` が表 A の語彙に実在（未登録は fail = deny-by-default） | 採用 | 同上 |
| B5 | `lane` ∈ `parallel_browser` / `serial_parent` | 採用 | 同上 |
| B6 | `priority` ∈ `P1` / `P2` / `P3` | 採用 | 同上 |
| B7 | `applicability` ∈ `applicable` / `not_applicable` | 採用 | 同上 |
| B8 | `not_applicable_reason` は `not_applicable` のときだけ、正準順序の 7 番目に置く。`applicable` にあれば fail | 採用 | 同上 |
| B9 | `depends_on` は他カードの `id` の配列。無ければ `[]` | 採用 | 同上 |
| B10 | `reseed_before` は真偽値 | 採用 | 同上 |
| B11 | `accounts` は閉じたトークン語彙（`guest` / `owner` / `admin` / `member` / `platform_admin`） | 採用 | 同上 |
| B12 | `setup` は一行の準備事項の配列。無ければ `[]` | 採用 | 同上 |
| B13 | `covers_screens` は route 名の形の配列（safe method の web route） | 採用 | 同上 |
| B14 | `covers_operations` は route 名の形の配列（非 safe method の web route） | 採用 | 同上 |
| B15 | `covers_capabilities` は `^[A-Z]+-[0-9]{2}$` の配列 | 採用 | 同上 |
| B16 | `covers_*` の値の**実在**は stories 側の検査では見ない（**形だけ**を見る）。実在の突合は目録側の責務 | 採用 | 施策 4（見ない）/ 施策 7（見る） |

### C 群: 表 A / 表 B の構造契約

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| C1 | 表 A はマーカー区間 `STORY-SURFACE-VOCABULARY` の中。正準ヘッダ `\| surface \| 面 \| 由来 \|` → 同じ列数の区切り行 → 残りはすべてデータ行（読み飛ばし一切なし） | 採用 | 施策 1 / 4 |
| C2 | 表 A のデータ行は 3 列、surface は snake_case 1 語、重複行なし、装飾は 1 対のバッククォートだけ | 採用 | 同上 |
| C3 | 家系必須 11 語（`signup_funnel` / `invitation` / `core_journey` / `org_project_admin` / `billing` / `account_security` / `authz_boundary` / `result_view` / `admin_console` / `cli_or_api` / `public_share`）の削除・改名は fail。追記は自由 | 採用 | 同上 |
| C4 | 表 B はマーカー区間 `STORY-CARD-INVENTORY` の中。ヘッダ `\| id \| surface \|` の **2 列だけ**（`lane` / `priority` / `depends_on` の写しを置かない = 第二の正本を作らない） | 採用 | 同上 |
| C5 | 表 B は実在カードと 1 対 1。`id` は重複しない | 採用 | 同上 |

### D 群: 番号規約

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| D1 | 番号は識別子であって意味を持たない。家系間の対応は `surface` で取る | 採用 | 施策 1 / 2 |
| D2 | 既存番号の面を付け替えない（S1〜S7 の `(id, surface)` の家系固定） | 採用 | 施策 4（リテラル pin） |
| D3 | `id` は一意 | 採用 | 施策 4 |
| D4 | 欠番を作らない。`S1` から最大番号まで連番。該当面が無くてもカードを消さず `applicability: not_applicable` で残す | 採用 | 同上 |
| D5 | ファイル名は `S{n}-{任意の kebab}.md`。機械一致は**先頭セグメント `S{n}`** だけ | 採用 | 同上 |
| D6 | `not_applicable` のカードは実走対象から外れる（契約は SKILL.md 側） | **未採用** | 新規 D。aicue に該当カードが 0 枚。SKILL.md が採用時債務にあるため触らない |
| D7 | S8 以降は番号でなく対象面で識別する（表 A に面を足し、表 B に 1 行、カードを 1 枚） | 採用（規約として記載。実際の追加は行わない） | 施策 1 |

### E 群: 依存・実行方式の整合

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| E1 | `depends_on` の参照が実在し、自己参照でなく、循環しない | 採用 | 施策 4 |
| E2 | `depends_on` を持つなら `reseed_before` は `false`（片方向のみ） | 採用 | 同上 |
| E3 | `parallel_browser` のカードが `serial_parent` のカードに依存しない | 採用 | 同上 |
| E4 | `lane` / `depends_on` / `reseed_before` の**正本はカードの前付け**。書式の正本は写しを持たない | 採用 | 施策 1 / 2 |
| E5 | `scripts/bug-hunt-shard.sh` の固定マップは前付けからの派生キャッシュであり、両者の一致は**機械検査しない** | 採用（正典も未達） | **施策 1（文書のみ。前付けとの一致は非機械保証）** |

### F 群: `not_applicable` カードの中身

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| F1 | `not_applicable` のカードは手順表も `## 手順` 見出しも持たず、本文のどこにも step 識別子を書かない | **未採用** | 新規 D。G 群（ステップ表）を採らないため step 識別子の概念が無い。`## 手順` 見出しの禁止だけは採用する（下記 F2） |
| F2 | `not_applicable` のカードが `## 手順` 節を持たないこと | 採用 | 施策 4 |

### G 群: ステップ表の書式

| # | 不変条件 | 分類 |
|---|---|---|
| G1 | `## 手順` 節ちょうど 1 個 + 正準 4 列ヘッダ `\| step \| 操作 \| 期待 \| 注目 \|` + 直後に 4 列区切り行 + データ行 1 行以上 | **未採用** |
| G2 | step 識別子は疎な文字列 `{id}-{3 桁}`（既定 10 刻み）。一度書いた識別子を再採番しない | **未採用** |
| G3 | 表の外に step 識別子を書かない（例外は副ブロック見出し `#### {step}` の 1 形だけ） | **未採用** |
| G4 | 副ブロックは同一 step につき最大 1 個。副ブロック内の項目には識別子を振らない | **未採用** |
| G5 | 期待欄は `H{n}` を 1 つ以上含む散文。注目欄は `H{n}` の半角空白区切りか明示の `-` だけ（散文を混ぜない） | **未採用** |
| G6 | H 番号の意味をカードに書かない（語彙の正本は SKILL.md の横断ヒューリスティクス表） | **採用（文書規約。機械保証しない）** — 正典もこれ単独の検査は持たず、禁止形の検出は採らない MC-11 の一部である。カードは `H4` 等の参照だけを持つ現状が既に規約を満たす |

**G1〜G5 を採らない理由と再判定条件**は概念設計のスコープ外 1 と新規 D に置く。

### H 群: 旧メタ節の撤去

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| H1 | 旧メタ節（`前提状態` / `目的` の箇条・`## このストーリーで消化する screens / operations`）が残っていないこと | 採用 | 施策 2 / 4 |

同じ事実が前付けと散文の 2 か所に並ぶのを防ぐ**必須の項目**である。前付けを足すだけで旧節を残すと、
カード 1 枚の中に二重の正本ができる。

### I 群: `covers_*` と目録の突合

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| I1 | 対象内（`kubun` が **外** でない）の web route は **1 枚以上のカードに載る**。**`終` は対象内**として扱う（実測 0 件） | 採用 | 施策 7（`DRIFT_STORY_UNASSIGNED` 相当） |
| I2 | `covers_screens` / `covers_operations` の route 名が**実在する** | 採用 | 施策 7 |
| I3 | 欄の意味に従う（`covers_screens` は safe method、`covers_operations` は 非 safe method） | 採用 | 施策 7 |
| I4 | 対象外（`kubun` = **外 のみ**）の route をカードに載せない | 採用 | 施策 7 |
| I5 | `covers_capabilities` を 4 段で見る（実在 / 欄の意味 / 分母 / 被覆） | **差** | 既存 D20。aicue の機能カタログは markdown 3 列で継承宣言（`no_route` / `coverage_surface` / `covered_via`）の欄を持たない。**実在・形・一意まで**を施策 7 で見る。分母・被覆は見ない |
| I6 | 分母はブラウザ（web 面）に閉じている。`admin_console` / `cli_or_api` は予約語彙のまま | 採用 | 施策 1（語彙は置くがカードは足さない） |
| I7 | `kind` の語彙で `covers_screens` の母集合を決める | **差** | 既存 D20。正典は `screen` / `read` / `redirect`、aicue は `画面` / `JSON`。aicue は **HTTP method（safe / 非 safe）**で母集合を決める（`kind` に依存させない）ので、正典が言う「表に無いが本欄には必要」な route は aicue には構造的に存在しない |

### J 群: カード本文の確定形

| # | 不変条件 | 分類 | 担い手 |
|---|---|---|---|
| J1 | H1 見出しは `# {id}: {title}` に固定し、前付けと機械一致させる | 採用 | 施策 2 / 4 |
| J2 | `## 目的`（散文）を持つ | 採用 | 施策 2 / 4（主題 **AC-15**） |
| J3 | `## 逸脱アイデア (--deviate 時)` を持つ | 採用 | 施策 2 / 4（主題 **AC-15**） |

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 書式の正本を置く | `.claude/skills/app-bug-hunt/stories/README.md` | 1 |
| 2 | 7 枚のカードへ前付けを付与し旧メタ節を撤去する | `.claude/skills/app-bug-hunt/stories/S1`〜`S7`*.md | 1 |
| 3 | 前付けの読み取り器を置く | `.claude/skills/app-bug-hunt/stories/story_front_matter.py`（新規） | 1 |
| 4 | 書式契約の自己テストを置く | `.claude/skills/app-bug-hunt/stories/test_story_front_matter.py`（新規） | 1 |
| 5 | 自己テストを `composer test` の配線に載せる | `tests/Architecture/BughuntStoryToolSelfTest.php`（新規）/ `tests/Support/Bughunt/StoryFrontMatterPins.php`（新規） | 1 |
| 6 | 注釈から `story` を撤去する | `.claude/skills/app-bug-hunt/inventory/annotations.toml` | 2 |
| 7 | 生成器の入力を前付けへ付け替える | `scripts/bug-hunt-inventory.py` / `scripts/tests/test_bug_hunt_inventory.py` | 2 |
| 8 | 割当セルの複数値化に照合器を追従させる | `.claude/skills/app-bug-hunt/coverage/correlate.py` / `test_correlate.py` | 2 |
| 9 | 目録を再生成する | `.claude/skills/app-bug-hunt/screens.md` / `operations.md` | 3 |
| 10 | 乖離台帳を更新する | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` / `adoption-debt.tsv` | 3 |
| 11 | 移行の検算を残す | `devnotes/{dir}/migrate_story_assignment.py` / `migration-verification.md` | 2 |

### 実施順序（1 本に統一する）

```
1 → 11(生成) → 2 → 4(負例を置いて fail を確認) → 3 → 5 → 6 → 7 → 11(検算) → 8 → 9 → 10
```

- **テストファースト**（AGENTS.md 思考原則 5）に従い、**施策 4 を施策 3 より先**に置く。
  負例が fail することを確認してから読み取り器を実装する。
- 施策 7 / 8 も同じで、**それぞれ負例を先に足して fail を確認してから**本体を実装する。
- 施策 11 は 2 回走る — 施策 2 の入力を作る「生成」と、施策 7 の完了後の「検算」。

---

## 施策 1: 書式の正本を置く

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/stories/README.md`（現在 44 行 = 旧テンプレートのスケルトン）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 4 が本ファイルの表 A / 表 B をマーカー区間から機械抽出して突合する

### 変更内容

正典の書式定義（原本実読）と同じ構成にする。節の構成は次のとおり。

1. **前付けの制限文法**（A 群 A1〜A6 を逐語で）
2. **前付けの項目定義**（B 群。必須 13 key + 条件付き 1 key の表）
3. **`covers_*` の 3 欄に何を書くか** — aicue 版に書き換える（後述）
4. **表 A: 対象面（surface）の語彙** — マーカー区間 `STORY-SURFACE-VOCABULARY`。家系必須 11 語を逐語で置く
5. **表 B: カード目録** — マーカー区間 `STORY-CARD-INVENTORY`。S1〜S7 の 7 行
6. **番号規約と S8 以降の識別規約**（D 群）
7. **使用アカウントのトークン**（B11 の 5 語）
8. **カード本文の確定形**（J 群）
9. **実行方式・依存・初期化要否の正本**（E4 / E5）
10. **本アプリが正典から外している契約**（新設節。G 群と D6 を理由・再判定条件つきで明記）

正典との差は次の 4 点だけを明記する。他は逐語一致させる。

| 観点 | 正典 | 本アプリ | 根拠 |
|---|---|---|---|
| `covers_screens` の母集合 | `kind` が `screen` / `read` / `redirect` の web route | **safe method（GET / HEAD / OPTIONS）の web route**。`kind`（`画面` / `JSON`）に依存させない | 既存 D20。`kind` の語彙が違う |
| `covers_capabilities` の検査 | 実在 / 欄の意味 / 分母 / 被覆の 4 段 | **実在・形・一意まで**。継承宣言の欄が無いため分母・被覆は見ない | 既存 D20 |
| ステップ表 | 正準 4 列 + 疎な step 識別子 + 副ブロック | **採らない**（散文の番号付きリストのまま） | 新規 D |
| `not_applicable` の実走除外契約 | SKILL.md 側が持つ | **持たない**（該当カードが 0 枚。SKILL.md は採用時債務にあり触らない） | 新規 D（同一エントリ） |

表 A / 表 B は次の形にする（マーカーごと。空行の位置も契約）。

```markdown
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
```

```markdown
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
```

### PHPStan 適合チェック

- [x] 対象外（Markdown）

### テスト計画

- [ ] 施策 4 が表 A / 表 B をマーカー区間から機械抽出し、カードの `surface` / `id` と突合する
- [ ] 施策 4 が家系必須 11 語の実在を deny-by-default で固定する（削除・改名で fail）
- [ ] 施策 4 が表の構造契約（正準ヘッダ / 区切り行 / 残りは全部データ行 / 3 列・2 列 / 重複なし）を固定する

### リスク

- 書式の正本と機械検査が食い違ったまま両方緑になる → マーカー区間からの機械抽出で構造的に防ぐ（正典と同じ手法）

---

## 施策 2: 7 枚のカードへ前付けを付与し旧メタ節を撤去する

### 変更箇所

- `.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md`（65 行）
- `.claude/skills/app-bug-hunt/stories/S2-invitation-flow.md`（29 行）
- `.claude/skills/app-bug-hunt/stories/S3-core-journey.md`（35 行）
- `.claude/skills/app-bug-hunt/stories/S4-org-project-management.md`（22 行）
- `.claude/skills/app-bug-hunt/stories/S5-billing.md`（53 行）
- `.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md`（52 行）
- `.claude/skills/app-bug-hunt/stories/S7-authz-boundaries.md`（25 行）

ファイル名はすべて `S{n}-{kebab}.md` を満たしているので**改名しない**（D5 が機械一致を要求するのは先頭セグメントだけ）。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 4（前付けの契約）/ 施策 7（`covers_*` と目録の突合）

### 現行コード（S7 の先頭。7 枚に共通する形）

```markdown
# S7: 認可境界 (IDOR) — AI-CUE ドメイン横断

> S3 実行後の状態を意図的に使う。組織 A/B・プロジェクト・ロール(編集者/撮影者)を跨いだ read/write が…

- 前提状態: 組織 A/B の 2 ユーザー。A に S3 で作った manual/cut/take/category/render がある。B からは何も見えてはならない。
- 目的: nested route の子解決が親 relation 経由で、越境は 403 でなく 404(存在を漏らさない)であること。…

## 手順
1. …

## このストーリーで消化する screens / operations
- screens: (S3/S4 の全 nested screen を B 視点で 404 確認。新規消化はしないが再走査)
- operations: projects.manuals.update, projects.manuals.destroy, …(いずれも越境で 404)

## 逸脱アイデア (--deviate 時)
- …
```

### 変更後コード（S7）

```markdown
---
id: S7
title: 認可境界 (IDOR)
surface: authz_boundary
lane: parallel_browser
priority: P1
applicability: applicable
depends_on: [S3]
reseed_before: false
accounts: [owner, member]
setup: [組織 A と組織 B の 2 アカウントを別 cookie セッションで用意する]
covers_screens: [projects.show, projects.manuals.show, ...]
covers_operations: [projects.manuals.update, projects.manuals.destroy, ...]
covers_capabilities: [PROJ-01, ...]
---

# S7: 認可境界 (IDOR)

## 目的

組織 A / B・プロジェクト・ロール (編集者 / 撮影者) を跨いだ read/write が認可より前に
404 / 403 で弾かれるか (H9)。nested route の子解決が親 relation 経由で、
越境は 403 でなく 404 (存在を漏らさない) であること。存在オラクル (422/404 差分) が無いこと。
カード S3 実行後の状態を意図的に使うため、開始前に初期データへは戻さない。

## 手順

1. …(現行の手順をそのまま残す。散文の番号付きリスト = ステップ表は採らない)

## 逸脱アイデア (--deviate 時)

- …
```

### 7 枚の前付けの値（確定）

`covers_*` の値は施策 11 の機械変換で埋める。ここで確定させるのはそれ以外の項目である。

| id | surface | lane | priority | depends_on | reseed_before | accounts |
|---|---|---|---|---|---|---|
| S1 | `signup_funnel` | `parallel_browser` | `P1` | `[]` | `true` | `[guest]` |
| S2 | `invitation` | `parallel_browser` | `P1` | `[]` | `false` | `[owner, member]` |
| S3 | `core_journey` | `parallel_browser` | `P1` | `[]` | `true` | `[admin]` |
| S4 | `org_project_admin` | `parallel_browser` | `P2` | `[]` | `false` | `[owner, admin]` |
| S5 | `billing` | `parallel_browser` | `P1` | `[]` | `false` | `[owner]` |
| S6 | `account_security` | `parallel_browser` | `P1` | `[]` | `false` | `[owner]` |
| S7 | `authz_boundary` | `parallel_browser` | `P1` | `[S3]` | `false` | `[owner, member]` |

- **`lane` はすべて `parallel_browser`**。現在の `stories_for_shard` は S1〜S7 をすべて browser story として
  並列に配っており（`4-1) S3 S7` / `4-2) S1 S2` / `4-3) S4 S5` / `4-4) S6`）、直列追走する面（CLI / 管理画面）の
  カードは 1 枚も無い。`serial_parent` は語彙として残すが、使うカードは今は無い。
- **`depends_on: [S3]` は S7 だけ**。既存のスキル本文が「S7 は S3 の状態を前提にするため S3 の後」と
  明記しており、固定マップも同じ shard に閉じ込めている。E2（依存があるなら `reseed_before` は false）を満たす。
- `accounts` は家系必須 5 語だけを使う。aicue の ProjectRole（編集者 / 撮影者）は
  **トークン語彙を拡張せず**、本文の散文で表す（語彙を増やすと家系の突合が緩む）。
- `priority` は「落ちたときに走行全体が無意味になるか」で決めた。S4（組織・プロジェクト管理）は
  他カードの前提を作らず単独で失敗しうるので `P2`。

### PHPStan 適合チェック

- [x] 対象外（Markdown）

### テスト計画

- [ ] 施策 4 が 7 枚すべての前付け（A 群 / B 群 / D 群 / E 群 / F2 / H1 / J 群）を検査する
- [ ] 施策 4 が `(id, surface)` の 7 組を**検査側のリテラル**と完全一致で突き合わせる（D2 の家系固定）
- [ ] 施策 7 が `covers_*` と目録を突合する（I1〜I4）
- [ ] 旧メタ節（`- 前提状態:` / `- 目的:` / `## このストーリーで消化する screens / operations`）が
      1 枚も残っていないことを施策 4 が検査する（H1）

### リスク

- **手順の中身を書き換えてしまう** → 手順節は**1 文字も触らない**を実装の規律にする。
  前付け・H1 見出し・`## 目的` 節・旧メタ節の撤去だけを行う。
  目視だけに委ねず、**施策 11 の検算資料へ 7 枚の `## 手順` 節の移行前後 sha256 を記録**し、
  全 7 件が一致することを示す。
- S7 の `covers_screens` は現行が散文（`(S3/S4 の全 nested screen を B 視点で 404 確認…)`）なので、
  機械変換の対象にならない。**実在する route 名 11 件を本文から起こして列挙する**
  （施策 11 の `EXPECTED_S7_ADDED_SCREENS` が正本。検算が完全一致で判定する）。
  `covers_operations` の 9 件も同様に `EXPECTED_S7_ADDED_OPERATIONS` で固定する。
  この 20 件が「S7 の追加分」として検算で明示的に除外される唯一の差分になる。

---

## 施策 3: 前付けの読み取り器を置く

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/stories/story_front_matter.py`（新規）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 4 が本モジュールを import して検査する。施策 7（生成器）も本モジュールを使う

### 変更後コード（骨子）

```python
#!/usr/bin/env python3
"""シナリオカードの前付け (制限文法) の読み取り器。

文法の**正本は `README.md`** であり、ここは**従う読み手**である。
読み取り器を書き換えて文法を広げてはならない (広げるなら README と自己テストを同じ変更で直す)。

依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path

CANONICAL_KEYS = (
    "id", "title", "surface", "lane", "priority", "applicability",
    "not_applicable_reason",
    "depends_on", "reseed_before", "accounts", "setup",
    "covers_screens", "covers_operations", "covers_capabilities",
)
CONDITIONAL_KEY = "not_applicable_reason"
REQUIRED_KEYS = tuple(k for k in CANONICAL_KEYS if k != CONDITIONAL_KEY)

SCALAR_KEYS = frozenset({"id", "title", "surface", "lane", "priority", "applicability", CONDITIONAL_KEY})
BOOL_KEYS = frozenset({"reseed_before"})
ARRAY_KEYS = frozenset({"depends_on", "accounts", "setup",
                        "covers_screens", "covers_operations", "covers_capabilities"})

LANE_VOCABULARY = ("parallel_browser", "serial_parent")
PRIORITY_VOCABULARY = ("P1", "P2", "P3")
APPLICABILITY_VOCABULARY = ("applicable", "not_applicable")
ACCOUNT_VOCABULARY = ("guest", "owner", "admin", "member", "platform_admin")

CARD_ID_RE = re.compile(r"^S[1-9][0-9]*$")
KEY_RE = re.compile(r"^[a-z][a-z0-9_]*$")
FILENAME_RE = re.compile(r"^S[1-9][0-9]*-.+\.md$")
ROUTE_TOKEN_RE = re.compile(r"^[a-z0-9]+([._-][a-z0-9]+)*$")
CAPABILITY_TOKEN_RE = re.compile(r"^[A-Z]+-[0-9]{2}$")
SURFACE_TOKEN_RE = re.compile(r"^[a-z][a-z0-9_]*$")

# 除外は**閉じたリテラル集合**にする (パターン除外を作らない)。
EXCLUDED_FILENAMES = frozenset({"README.md"})


@dataclass(frozen=True)
class Card:
    """1 枚のカード。値は制限文法で読めた形のまま持つ。"""

    filename: str
    text: str
    front_matter: dict[str, object]
    keys_in_order: tuple[str, ...]
    body: str


def parse_front_matter(text: str) -> tuple[dict[str, object], tuple[str, ...], list[str], str]:
    """前付けを読み、(値, 出現順の key, 違反, 本文) を返す。**例外を投げない**。"""
    ...


def stories_dir() -> Path:
    return Path(__file__).resolve().parent


def read_cards(directory: Path | None = None) -> tuple[list[Card], list[str]]:
    """候補母集団 (`*.md` から `EXCLUDED_FILENAMES` を引いた全件) を読む。

    **パターンで発見しない**。`S8.md` のような命名違反を「存在しないもの」にしないため、
    全件走査してから命名契約を検査する。
    """
    ...
```

**設計上の決めごと**:

- **正規表現の照合は `fullmatch()` に統一する**。Python の `$` は**末尾改行の直前にも一致する**ため、
  `match()` + `$` は「厳密一致」と同義ではない。本作業で新設・変更するすべての照合
  （施策 3 / 4 / 7 / 8）でこの規律を守る。
- **例外を投げず違反の並びを返す**。違反を 1 件目で止めると、直すたびに再実行が要る。
- **`Path` を引数で受けられる**ようにする（自己テストが合成入力を渡すため）。
  既定は自分の置き場（`Path(__file__).parent`）。
- 候補母集団は `*.md` の**全件走査**から閉じたリテラル除外集合を引く。パターン除外は作らない。

### PHPStan 適合チェック

- [x] 対象外（Python）

### テスト計画

- [ ] 施策 4 が本モジュールを import して、制限文法の全分岐（A1〜A6）を合成入力で 1 件ずつ走らせる
- [ ] 実ファイル母集団が 0 件になっても違反分岐が走ること（合成入力を検査側がリテラルで持つ）

### リスク

- 読み取り器を書き換えて文法を広げる → docstring で明文禁止し、施策 4 が README との突合で捕まえる

---

## 施策 4: 書式契約の自己テストを置く

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/stories/test_story_front_matter.py`（新規）

### 波及変更

- テストファイル: 施策 5（`composer test` への配線）が本モジュールを名指しで起動する

### 検査の骨格

正典のテストを**コピーして一部を無効化するのではなく、採用範囲だけを新規に書く**（Codex Round 1 の Critical）。

母集団の定義:

- **候補母集団**: `stories/*.md` から `EXCLUDED_FILENAMES`（`README.md` のみ）を引いた全件
- **カード母集団 A**: 候補のうち命名契約（MC-3 相当）を通過した全件
- **固定母集団 B**: S1〜S7 の 7 枚。家系固定のリテラル pin 専用
- **合成入力 C**: 本ファイルがリテラルで持つ前付け・本文。実ファイル母集団が 0 件になりうる違反分岐を必ず 1 件ずつ走らせる

検査の主題（正典の MC 番号に対応させる。採らないものは**置かない**）:

| 主題 | 内容 | 正典の対応 |
|---|---|---|
| AC-01 | 制限文法 + 必須 key 全数 + 正準順序 + 重複なし | MC-1 |
| AC-02 | 閉じた語彙と値の書式（`lane` / `priority` / `applicability` / `accounts` / `id` / route 名 / capability id） | MC-2 |
| AC-03 | 命名・`id` の一意性・欠番の有無。適合分だけをカード母集団 A にする | MC-3 |
| AC-04 | 表 A（許可語彙）の構造契約と家系必須 11 語の実在 | MC-4 |
| AC-05 | `surface` が表 A に所属し、表 B とカードが 1 対 1 | MC-4b |
| AC-06 | **家系固定** — `(id, surface)` の 7 組を検査側のリテラルと完全一致 | MC-5 |
| AC-07 | `depends_on` の実在・自己参照・循環 | MC-6 |
| AC-08 | 依存があるなら初期化しない（片方向） | MC-7 |
| AC-09 | 並列カードが直列カードを待たない | MC-8 |
| AC-10 | `not_applicable` のカードが `## 手順` 節を持たない | MC-9（F2 のみ） |
| AC-11 | H1 見出しと前付けの機械一致 | MC-13 |
| AC-12 | 旧メタ節が残っていない | MC-14 |
| AC-13 | `covers_*` の値の**形**だけを見る（実在は目録側の責務）。**配列要素の重複も禁じる** | MC-15 |
| AC-15 | **カード本文の確定形** — `## 目的` と `## 逸脱アイデア (--deviate 時)` がそれぞれちょうど 1 個で、**節の中身が空でない**（見出しの直後から次の H2 見出しの直前までを取り、空白を除いて非空） | J2 / J3（正典は本文の確定形として散文で示す） |
| AC-14 | **全数点呼** — 採用した不変条件 ID の全リストと、ID → 主題の対応表を定数で持ち、**未割当 ID が 0 件**であること | MC-16 を強化 |

**置かない検査**: 正典の MC-10（ステップ表の存在条件）/ MC-11（兆候番号の記入）/ MC-12。
理由と再判定条件は README の「本アプリが正典から外している契約」節と新規 D に置く。

### 変更後コード（家系固定 AC-06 の骨子）

**S8 以降の追加を阻害しない形にする**。家系固定の本体は「既存番号の面を付け替えない」であって
「7 枚しか置けない」ではない（D7 が S8 以降の追加手続きを定めている）。
したがって pin は **`FAMILY_SURFACE_PIN` の id 集合に属するカードだけ**を対象にする。

```python
FAMILY_SURFACE_PIN = (
    ("S1", "signup_funnel"),
    ("S2", "invitation"),
    ("S3", "core_journey"),
    ("S4", "org_project_admin"),
    ("S5", "billing"),
    ("S6", "account_security"),
    ("S7", "authz_boundary"),
)
PINNED_IDS = frozenset(card_id for card_id, _ in FAMILY_SURFACE_PIN)


class StoryFrontMatterContractTest(unittest.TestCase):
    def test_ac_06_family_surface_pin(self) -> None:
        """S1 から S7 の (id, surface) を家系で固定する。

        番号は識別子であって意味を持たないが、**既存番号の面を付け替えない**ことが
        家系固定の本体である。検査側のリテラルと完全一致で突き合わせる
        (カード側だけを直しても緑にならない)。

        ★ **pin の対象は PINNED_IDS に属するカードだけ**である。S8 以降を正規の手続き
          (表 A に面を足し、表 B に 1 行、カードを 1 枚) で足しても落ちない。
          S8 以降の一意性・連番・表 B との一致は AC-03 / AC-05 の担当である。
        """
        actual = tuple(sorted(
            (str(card.front_matter["id"]), str(card.front_matter["surface"]))
            for card in self.cards
            if str(card.front_matter.get("id")) in PINNED_IDS
        ))
        self.assertEqual(tuple(sorted(FAMILY_SURFACE_PIN)), actual)
```

### 変更後コード（全数点呼 AC-14 の骨子）

**58 項目の分割 (partition) を検査側が独立に持つ**。前版は「採用」の一覧が手書きで、
そこから項目を落とせば点呼も気づかなかった（実際に I 群が丸ごと抜けていた）。
そこで **全 58 件の ID を先に固定し、分類と担い手を別々の集合で表して整合を assert する**。

```python
# 詳細設計の全数対応表の全 58 項目。**ここが点呼の基準**である。
ALL_INVARIANTS = (
    "A1", "A2", "A3", "A4", "A5", "A6",
    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
    "C1", "C2", "C3", "C4", "C5",
    "D1", "D2", "D3", "D4", "D5", "D6", "D7",
    "E1", "E2", "E3", "E4", "E5",
    "F1", "F2",
    "G1", "G2", "G3", "G4", "G5", "G6",
    "H1",
    "I1", "I2", "I3", "I4", "I5", "I6", "I7",
    "J1", "J2", "J3",
)

# --- 分類 (互いに排他。和が ALL_INVARIANTS と一致する) ---
ADOPTED = (
    "A1", "A2", "A3", "A4", "A5", "A6",
    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
    "C1", "C2", "C3", "C4", "C5",
    "D1", "D2", "D3", "D4", "D5", "D7",
    "E1", "E2", "E3", "E4", "E5",
    "F2",
    "G6",
    "H1",
    "I1", "I2", "I3", "I4", "I6",
    "J1", "J2", "J3",
)
DIFFERENCES = ("I5", "I7")                      # aicue 固有差 (既存 D20 が説明)
NOT_ADOPTED = ("D6", "F1", "G1", "G2", "G3", "G4", "G5")   # 新規 D が説明

# --- 担い手 (集合同士の重複を許す。B16 のように両側に現れる項目がある) ---
STORY_SIDE = (...)      # stories/test_story_front_matter.py が見る
INVENTORY_SIDE = (...)  # scripts/bug-hunt-inventory.py が見る
NON_MECHANICAL = ("E5", "G6")   # 文書規約として採るが機械保証しない

# --- 主題 → テスト名 (推測しない。明示で対応させる) ---
SUBJECT_TO_TESTS = {
    "AC-01": (
        "test_ac_01_accepts_canonical_front_matter",
        "test_ac_01_rejects_quoted_scalar",
        "test_ac_01_rejects_duplicate_key",
        "test_ac_01_rejects_key_out_of_canonical_order",
    ),
    ...
    "AC-15": (
        "test_ac_15_accepts_canonical_body",
        "test_ac_15_rejects_missing_purpose_section",
        "test_ac_15_rejects_empty_purpose_section",
        "test_ac_15_rejects_duplicate_deviation_section",
    ),
}
INVARIANT_TO_SUBJECT = {"A1": "AC-01", ..., "J2": "AC-15", "J3": "AC-15"}
```

```python
    def test_ac_14_invariant_partition_is_total(self) -> None:
        """58 項目が 3 分類へ**過不足なく**割れていること。"""
        self.assertEqual(58, len(ALL_INVARIANTS))
        self.assertEqual(len(ALL_INVARIANTS), len(set(ALL_INVARIANTS)))

        classified = [*ADOPTED, *DIFFERENCES, *NOT_ADOPTED]
        self.assertEqual(len(classified), len(set(classified)), "分類が重複している")
        self.assertEqual(set(ALL_INVARIANTS), set(classified), "分類の和が全数と一致しない")

    def test_ac_14_every_adopted_invariant_has_a_bearer(self) -> None:
        """採用した項目のすべてに担い手が居ること。担い手集合に未知 ID が無いこと。"""
        bearers = set(STORY_SIDE) | set(INVENTORY_SIDE) | set(NON_MECHANICAL)
        self.assertEqual([], [k for k in ADOPTED if k not in bearers], "担い手の無い採用項目")
        self.assertEqual(
            set(), bearers - set(ALL_INVARIANTS), "担い手集合に未知の ID がある"
        )
        # 非機械保証は「保証しないもの」の節と 1 対 1 にする (黙って落とさない)。
        self.assertEqual(("E5", "G6"), NON_MECHANICAL)

    def test_ac_14_every_story_side_invariant_maps_to_a_real_test(self) -> None:
        """stories 側が担う項目が、実在する検査へ**明示的に**紐づいていること。

        ★ 主題名からテスト名を**推測しない**。`AC-01` から作った `test_ac_01` は
          実際の `test_ac_01_rejects_quoted_scalar` と一致せず、hasattr が常に偽になる。
        """
        for key in STORY_SIDE:
            self.assertIn(key, INVARIANT_TO_SUBJECT, f"{key} に主題が無い")
            subject = INVARIANT_TO_SUBJECT[key]
            self.assertIn(subject, SUBJECT_TO_TESTS, f"{subject} にテストの割当が無い")

        for subject, names in SUBJECT_TO_TESTS.items():
            for name in names:
                self.assertTrue(callable(getattr(self, name, None)), f"{name} が実在しない")
            # 各主題に正例と負例が 1 本以上ある (負例だけ / 正例だけを許さない)。
            self.assertTrue(any("accepts" in n for n in names), f"{subject} に正例が無い")
            self.assertTrue(any("rejects" in n for n in names), f"{subject} に負例が無い")
```

### PHPStan 適合チェック

- [x] 対象外（Python）

### テスト計画

- [ ] 各主題に**正例 1 本 + 負例 1 本以上**を置く（負例は合成入力 C で作る）
- [ ] AC-14（全数点呼）が **58 項目の分割**を検査する — `len(ALL_INVARIANTS) == 58` /
      ID の重複なし / `ADOPTED` `DIFFERENCES` `NOT_ADOPTED` が互いに排他 / 3 集合の和が全数と一致
- [ ] AC-14 が **`ADOPTED` の全件に担い手がある**ことを検査する
      （`STORY_SIDE` / `INVENTORY_SIDE` / `NON_MECHANICAL` のいずれか。集合同士の重複は許す）
- [ ] AC-14 が担い手集合に**未知 ID が無い**ことを検査する
- [ ] AC-14 が `NON_MECHANICAL` を assert に使う（「保証しないもの」の 2 件と 1 対 1）
- [ ] AC-14 が `SUBJECT_TO_TESTS` の**明示対応**でテストの実在を確認する
      （主題名からメソッド名を推測しない）。各主題に `accepts` と `rejects` が 1 本以上あること
- [ ] AC-15 の負例: `## 目的` の欠落 / 重複 / 表記揺れ（`## 目的:` など）が fail すること
- [ ] AC-15 の負例: `## 目的` が**空節**（見出しだけで本文が無い）でも fail すること
- [ ] AC-15 の負例: `## 逸脱アイデア (--deviate 時)` の欠落 / 重複 / 表記揺れ / 空節が fail すること
- [ ] AC-13 の負例: `covers_operations: [a.b, a.b]` のような**配列要素の重複**が fail すること
- [ ] `python3 -m unittest test_story_front_matter` が `stories/` を作業ディレクトリとして通ること

### リスク

- **検査を飛ばして緑に見せる** → 施策 5 が「活きている検査の件数の下限」を実測値で pin し、
  中核の負例を名前で照合する（aigenba が家系への還流候補として挙げた形をここで採る）

---

## 施策 5: 自己テストを `composer test` の配線に載せる

### 変更箇所

- ファイル: `tests/Architecture/BughuntStoryToolSelfTest.php`（新規）

先例は `tests/Architecture/BughuntCoverageToolSelfTest.php`（`.claude/skills/app-bug-hunt/coverage` を
作業ディレクトリにして `python3 -m unittest` を実走する）。aicue の様式は
**CI の専用ステップではなく `composer test` の配線**（既存乖離 D21）なので、その形をそのまま踏襲する。
**新しい乖離は作らない**。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイル自身がテストである

### 変更後コード

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Bughunt\StoryFrontMatterPins;

/*
 * Architecture invariant: シナリオカードの書式契約の自己テスト (Python) を
 * `composer test` の下で実走させる。
 *
 * 対象は 1 モジュール:
 *   - test_story_front_matter … 前付けの制限文法・番号規約・表 A / 表 B との突合
 *
 * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない (禁止事項 1)。
 *
 * 先例は BughuntCoverageToolSelfTest: python3 の不在は **skip ではなく fail** で
 * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
 */

/** カードの書式契約の自己テストの置き場 (作業ディレクトリ)。 */
function bstStoriesDir(): string
{
    return base_path('.claude/skills/app-bug-hunt/stories');
}

/**
 * stories ディレクトリで `python3 -m unittest -v <modules...>` を実走し [exitCode, output] を返す。
 *
 * @param  list<string>  $modules
 * @return array{0: int|null, 1: string}
 */
function bstRunUnittest(array $modules): array
{
    $process = new Process(
        ['python3', '-m', 'unittest', '-v', ...$modules],
        bstStoriesDir(),
        ['PYTHONDONTWRITEBYTECODE' => '1'],
    );
    $process->setTimeout(120);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
    expect((new Process(['which', 'python3']))->run())->toBe(
        0,
        'python3 が PATH に無い。カードの書式契約の自己テストは python3 必須 (stdlib のみ)。'
    );
});

test('カードの書式契約の自己テストが composer test の下で通ること', function (): void {
    expect(is_dir(bstStoriesDir()))->toBeTrue('stories ディレクトリが見つからない: '.bstStoriesDir());

    [$code, $out] = bstRunUnittest(['test_story_front_matter']);

    expect($code)->toBe(0, "カードの書式契約の自己テストが失敗した:\n".$out);
});

test('件数の下限が実測値へ差し替えられていること (0 のままだと検査が無効化される)', function (): void {
    // MIN_TESTS = 0 の置き忘れは、件数 pin を常に成功させて機構ごと無効にする。
    // PHPDoc の positive-int だけでは実行時の 0 を防げないので assert で固定する。
    expect(StoryFrontMatterPins::MIN_TESTS)->toBeGreaterThan(0);
});

test('活きている検査の件数が下限を下回らないこと (検査を飛ばして緑に見せない)', function (): void {
    // 件数の下限を実測値で pin する。検査を削って緑にする道を塞ぐ。
    [$code, $out] = bstRunUnittest(['test_story_front_matter']);

    expect($code)->toBe(0, $out);
    expect((int) (preg_match('/^Ran (\d+) tests?/m', $out, $m) === 1 ? $m[1] : 0))
        ->toBeGreaterThanOrEqual(
            StoryFrontMatterPins::MIN_TESTS,
            '活きている検査が下限 ('.StoryFrontMatterPins::MIN_TESTS.") を下回った:\n".$out,
        );
});

test('中核の負例が名前と成功表示の両方で実在すること (skip 逃げを塞ぐ)', function (): void {
    // 名前だけを見ると skip でも緑になる。`... ok` まで照合する。
    // ★ 終了コードもここで確認する。別テストが確認していても**実行は別プロセス**であり、
    //   同一結果とは限らない。
    [$code, $out] = bstRunUnittest(['test_story_front_matter']);

    expect($code)->toBe(0, $out);

    foreach (StoryFrontMatterPins::CORE_NEGATIVES as $name) {
        expect($out)->toMatch('/'.preg_quote($name, '/').'.*\.\.\. ok$/m', "負例 {$name} が ok で実行されていない");
    }
});
```

**定数の置き場**: `tests/Support/Bughunt/StoryFrontMatterPins.php` に**クラス定数**として置く
（Pest のテストファイルに書いた `const` はそのファイルが読み込まれた後にしか見えないため。
`tests/Support/TemplateDivergence/LedgerPins.php` と同じ理由・同じ作法）。
**グローバル定数として参照してはならない**（`Undefined constant` になる）。

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Bughunt;

/**
 * シナリオカードの書式契約の自己テストに対する固定値 (不変の scalar / 配列定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・プロセス実行を一切持たない**。値の置き場所を 1 か所にするための型である。
 * ★**これは免除の一覧ではない**。個別の検査を名指しして無効化する仕組みは本機構のどこにも無い。
 */
final class StoryFrontMatterPins
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /**
     * 活きている検査の件数の下限 (実測値)。
     *
     * ★**下限**である (上振れは許す)。減ることだけを禁じ、検査を削って緑にする道を塞ぐ。
     */
    public const int MIN_TESTS = 0;   // ★ 実装時に実測値へ差し替える (0 のままだと検査が無効化される)

    /**
     * 中核の負例。名前だけでなく `... ok` の成功表示まで照合して skip 逃げを塞ぐ。
     *
     * @var list<string>
     */
    public const array CORE_NEGATIVES = [
        'test_ac_01_rejects_quoted_scalar',
        'test_ac_01_rejects_duplicate_key',
        'test_ac_01_rejects_key_out_of_canonical_order',
        'test_ac_02_rejects_unknown_lane',
        'test_ac_03_rejects_gap_in_card_numbers',
        'test_ac_04_rejects_removed_family_surface',
        'test_ac_05_rejects_card_missing_from_inventory',
        'test_ac_06_rejects_reassigned_family_surface',
        'test_ac_07_rejects_dependency_cycle',
        'test_ac_08_rejects_reseed_with_dependency',
        'test_ac_09_rejects_parallel_depending_on_serial',
        'test_ac_10_rejects_steps_in_not_applicable_card',
        'test_ac_11_rejects_heading_mismatch',
        'test_ac_12_rejects_legacy_meta_section',
        'test_ac_13_rejects_duplicate_array_element',
        'test_ac_15_rejects_missing_purpose_section',
    ];
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`bstRunUnittest(): array{0: int|null, 1: string}`）
- [x] null 安全（`getExitCode()` は `int|null` なので `toBe(0, ...)` の前に型を宣言で示す）
- [x] DTO を返している（配列返却なし）→ **本ファイルはテストであり DTO の対象外**。
      `array{0: int|null, 1: string}` の shape を phpdoc で明示して level 10 を通す（先例と同形）
- [x] Generics の型パラメータが正しい（`list<string>` を phpdoc で明示）
- [x] **クラス定数を `use` して参照している**（グローバル定数として書かない = `Undefined constant` を作らない）
- [x] `preg_match()` の戻り値を `=== 1` で判定している（`int|false` を真偽で潰さない）
- [x] `StoryFrontMatterPins` は `declare(strict_types=1)` + `final class` + `private __construct()` +
      `public const int` / `public const array`（`@var list<string>`）

### 変更ファイル（追加）

- `tests/Support/Bughunt/StoryFrontMatterPins.php`（新規。定数の置き場）

### テスト計画

- [ ] 新規テスト: `カードの書式契約の自己テストが composer test の下で通ること`
- [ ] 新規テスト: `件数の下限が実測値へ差し替えられていること` (`MIN_TESTS > 0`)
- [ ] 新規テスト: `活きている検査の件数が下限を下回らないこと`
- [ ] 新規テスト: `中核の負例が名前と成功表示の両方で実在すること`
- [ ] 新規テスト: `python3 が PATH にあること`
- [x] 個別の `DatabaseTransactions` を使っていない（DB を触らない）

### リスク

- 件数 pin が増減のたびに更新されて形骸化する → **下限**にして上振れは許す（減ることだけを禁じる）

---

## 施策 6: 注釈から `story` を撤去する

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/inventory/annotations.toml`（684 行 / 150 route）

### 波及変更

- テストファイル: 施策 7 の自己テスト（未知項目の拒否）

### 現行コード

```toml
#   kind   画面表の route で必須 (画面 / JSON)。操作表の route には書けない
#   story  区分が 通常 / 逸 のとき必須 (S1..S7)。区分が 外 / 終 には書けない
#   kubun  常に必須 (通常 / 逸 / 終 / 外)
#   reason 区分が 外 / 終 のとき必須・30 文字以上。それ以外には書けない

[routes."billing.index"]
kind = "画面"
story = "S5"
kubun = "通常"
```

### 変更後コード

```toml
#   kind   画面表の route で必須 (画面 / JSON)。操作表の route には書けない
#   kubun  常に必須 (通常 / 逸 / 終 / 外)
#   reason 区分が 外 / 終 のとき必須・30 文字以上。それ以外には書けない
#
# ★ **割当 (どのカードが消化するか) はここには書かない**。正本はシナリオカードの前付け
#   (`../stories/S*.md` の covers_screens / covers_operations) である。
#   目録の割当列はそこから逆引き生成される。ここに書くと二重の正本になるので、
#   `story` は未知項目として exit 3 (ドリフト) で落ちる。

[routes."billing.index"]
kind = "画面"
kubun = "通常"
```

- 全 136 件の `story = "..."` 行を削除する（`kubun = 外` の 14 件は元から持たない）。
- `ANNOTATION_KEYS` から `story` を外すので、書き戻しは**未知項目**として段 2 が落とす（deny-by-default）。

### PHPStan 適合チェック

- [x] 対象外（TOML）

### テスト計画

- [ ] 施策 7 の自己テスト: `story` を書いた注釈が **exit 3** で落ちること（未知項目）
- [ ] 施策 11 の検算: 撤去前の `story` から作った関係と、撤去後の前付けから作った関係が一致すること

### リスク

- **撤去と前付けの付与がずれると、その間だけ割当が消える** → 同一コミットで行う（後方互換の並走を残さない）

---

## 施策 7: 生成器の入力を前付けへ付け替える

### 変更箇所

- ファイル: `scripts/bug-hunt-inventory.py`
  - L59-64 付近: 定数（`KUBUN_NEEDS_STORY` / `STORY_IDS` / `ANNOTATION_KEYS`）
  - L287-365 付近: `validate_annotations()`
  - L394-396: `_story_cell()`
  - L414-476: `render_screens()` / `render_operations()`
  - L634-641: `_prepare()`
  - L480-: `check_catalog()`（`covers_capabilities` の実在照合を足す）
- ファイル: `scripts/tests/test_bug_hunt_inventory.py`（追加分の自己テスト）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `scripts/tests/test_bug_hunt_inventory.py`（**採用時債務にあるので施策 10 で債務から削る**）
- 生成物: `screens.md` / `operations.md`（施策 9）
- 下流: `coverage/correlate.py`（施策 8）

### 現行コード

```python
KUBUN_NEEDS_STORY = ("通常", "逸")
STORY_IDS = tuple(f"S{i}" for i in range(1, 8))
ANNOTATION_KEYS = ("kind", "story", "kubun", "reason")

def _story_cell(entry: dict[str, object]) -> str:
    story = _annotation_value(entry, "story")
    return story if story is not None else "-"
```

```python
        story = _annotation_value(entry, "story")
        if kubun in KUBUN_NEEDS_STORY:
            if story is None:
                violations.append(f"{prefix} 区分 {kubun} には story が要る")
            elif story not in STORY_IDS:
                violations.append(f"{prefix} 未知のストーリー: {story}")
        elif kubun in KUBUN_NEEDS_REASON and story is not None:
            violations.append(f"{prefix} 区分 {kubun} に story は書けない")
```

### 変更後コード

```python
# 注釈が持つのは「route ごとの意味」だけである。割当 (どのカードが消化するか) は
# シナリオカードの前付けが正本なので、ここには持たない。
ANNOTATION_KEYS = ("kind", "kubun", "reason")

# 割当セルの値域。**書き出し側の正本はここ**であり、規則の散文は stories/README.md にある。
# `-` は「載せるカードが 0 枚 (= 対象外)」を表す。
STORY_CELL_EMPTY = "-"
STORY_CELL_SEPARATOR = " "
# 照合は fullmatch() で行う (Python の `$` は末尾改行の直前にも一致するため)。
STORY_CELL_RE = re.compile(r"(S[1-9][0-9]*( S[1-9][0-9]*)*|-)")

# 前付けの読み取り器の置き場 (stories/ に居る。文法の正本はその隣の README.md)。
STORIES_DIR = SKILL_DIR / "stories"
```

```python
def _story_cell(assignment: frozenset[str]) -> str:
    """割当カードの集合をセルの表記へ落とす (番号の昇順・半角空白 1 つ区切り)。"""
    if not assignment:
        return STORY_CELL_EMPTY

    return STORY_CELL_SEPARATOR.join(sorted(assignment, key=lambda s: int(s[1:])))
```

```python
@dataclass(frozen=True)
class Assignment:
    """カードの前付けから逆引きした route → 割当カード集合 (欄ごと)。"""

    screens: dict[str, frozenset[str]]
    operations: dict[str, frozenset[str]]
    capabilities: frozenset[str]
    cards: tuple[str, ...]          # applicable なカードの id (昇順)


def load_assignment(stories_dir: Path) -> tuple[Assignment | None, list[str]]:
    """カードの前付けを読み、欄ごとの割当と違反を返す。

    ★ **生成器単体で fail-closed にする**。書式の全契約は
      stories/test_story_front_matter.py の責務だが、それは**別プロセス**である。
      生成器を直接叩いた走行が緑になってはいけないので、ここでも次を見る:

        - parse_front_matter() が返した違反を**必ず伝播する**
        - `id` / `applicability` / `covers_*` が期待型でなければ**割当を構築しない**
        - 不正なカードを**飛ばして目録を生成しない** (段 2 の違反として exit 3 にする)

      逆に、語彙・正準順序・表 A / 表 B との突合といった「目録に関係しない契約」は
      ここでは見ない (二重に持つと必ず食い違う)。

    ★ **失敗を型で表す**。違反が 1 件でもあれば `None` を返す。空の Assignment を返すと、
      呼び出し側が違反の並びを見落としたときに**そのまま目録を生成できてしまう**。
    """
    ...
```

**戻り値が `Assignment | None` である**ことに合わせ、`_prepare()` / `run_check()` / `run_generate()` は
`None` を受けたら**レンダリングへ進まない**。`run_generate()` は目録を 1 バイトも書かない。

### `load_assignment()` が自分で見る範囲（生成器単体の fail-closed）

「型だけ見る」では足りない。**自分が消費する項目**については、形式・語彙・重複まで見る。
特に `id` の形式は `int(card_id[1:])` の入力になるので、見ないと例外で落ちる（違反として報告されない）。

| 見るもの | 理由 |
|---|---|
| `id` が `^S[1-9][0-9]*$`（`fullmatch`） | 昇順ソートの `int(id[1:])` の入力になる |
| `id` がカード間で一意 | 割当の逆引きが曖昧になる |
| `applicability` が閉じた語彙（`applicable` / `not_applicable`） | 割当の母集団を決める |
| `covers_*` の要素が**文字列**であること | 制限文法上は配列要素が空文字になりうる |
| `covers_screens` / `covers_operations` の要素が route 名の形（`fullmatch`） | 目録との join キー |
| `covers_capabilities` の要素が `^[A-Z]+-[0-9]{2}$`（`fullmatch`） | カタログとの join キー |
| **配列内の重複が無いこと** | `frozenset` 化すると消えるので、その前に見る |

**見ないもの**（stories 側だけの責務）: 正準順序 / 表 A・表 B との突合 / `lane` / `priority` /
`depends_on` の整合 / H1 見出しとの一致 / 旧メタ節。二重に持つと必ず食い違う。

**`applicability: not_applicable` のカードの扱い**: 割当の母集団から**外す**
（実走しないカードが route を消化することにはならない）。I1 の「1 枚以上の **applicable** カードに載る」は
この扱いから来ている。

`validate_annotations()` の `story` 節を削除し、代わりに突合を足す。

```python
def validate_assignment(facts: Facts, annotations: Annotations, assignment: Assignment) -> list[str]:
    """前付けの割当と目録の母集合を突き合わせる (段 2 の一部)。

    見るのは 4 つ:
      I2 実在   … 載せた route 名が web 面の母集合に在る
      I3 欄     … covers_screens は safe method / covers_operations は 非 safe method
      I4 対象外 … 区分 **外** の route を載せていない (`終` は対象内である)
      I1 未割当 … 対象内の route が 1 枚以上のカードに載っている

    ★ **欄ごとに明示的にループする**。`fact in facts.screens` のような所属判定に頼ると、
      将来 GET と非 GET を併せ持つ route (compound) を両方の表へ入れる形にした瞬間に、
      操作側の未割当を静かに見逃す。
    ★ **判定の順序は expected → other → 不明**である。other を先に見ると、
      両方の母集合に在る route を「欄違い」と誤って報告する。
    """
    violations: list[str] = []
    screen_names = {f.name for f in facts.screens}
    operation_names = {f.name for f in facts.operations}

    for label, cell, expected, other in (
        ("covers_screens", assignment.screens, screen_names, operation_names),
        ("covers_operations", assignment.operations, operation_names, screen_names),
    ):
        for name in sorted(cell):
            if name in expected:
                entry = annotations.routes.get(name)
                # 未注釈 route は既存の「未注釈の route」違反の担当。ここでは黙って飛ばす
                # (KeyError で全体を落とすと、他の違反を集め終える前に走行が止まる)。
                if entry is not None and _annotation_value(entry, "kubun") == KUBUN_OUT_OF_SCOPE:
                    violations.append(f"[{STAGE2}] {label} に対象外の route: {name}")
            elif name in other:
                violations.append(f"[{STAGE2}] {label} に欄違いの route: {name}")
            else:
                violations.append(f"[{STAGE2}] {label} に実在しない route: {name}")

    for label, route_facts, pool in (
        ("画面", facts.screens, assignment.screens),
        ("操作", facts.operations, assignment.operations),
    ):
        for fact in route_facts:
            entry = annotations.routes.get(fact.name)
            if entry is None or _annotation_value(entry, "kubun") == KUBUN_OUT_OF_SCOPE:
                continue
            if not pool.get(fact.name):
                violations.append(
                    f"[{STAGE2}] 対象内なのにどのカードにも載っていない{label}: {fact.name} "
                    "(消化するカードの covers_* へ足すこと)"
                )

    return violations
```

### `終` の意味を正典へ揃える（意図的な仕様変更として扱う）

**因果を正しく書く**。「単一値だったから `終` に割り当てられなかった」は成り立たない
（単一値でも `終` に 1 枚割り当てることはできた）。データ構造の都合で自然に消える制約ではない。

> 現行は `終`（実行すると後続の手順が成立しなくなる終端）を**割当の対象外**としていたが、
> 正典の「**`外` 以外は対象内**」へ**意図的に意味を変更する**。
> 変更後の `終` は **`reason` 必須かつカード割当必須**になる。

正典の書式定義が「対象内 (`kubun` が **外** でない) の web route は 1 枚以上のカードに載ること」と
明文で定めており、`終` を対象外にしたままだと「実行はするのに分母に数えない route」ができる。
実測で `終` の route は現在 **0 件**（内訳: 通常 136 / 外 14）なので、寄せても作業は増えない。

### `KUBUN_NEEDS_REASON` の全利用箇所を棚卸しする（波及漏れを作らない）

`KUBUN_NEEDS_REASON = ("外", "終")` を**スコープ判定にも使っている箇所が残っている**。
`validate_assignment()` だけを直すと、「`終` は割当必須なのに生成物では対象外件数・対象外節へ入る」
という矛盾が生まれる（現在 0 件でも、`終` を 1 件足した瞬間に静かに顕在化する）。

| 利用箇所 | 現在の用途 | 変更後 |
|---|---|---|
| `validate_annotations()` の `reason` 節 | **reason 要否** | そのまま（`外` + `終`） |
| `validate_annotations()` の `story` 節 | scope 判定 | **節ごと削除**（施策 6 / 7） |
| `render_screens()` の「うち対象外」件数 | scope 判定 | **`kubun == KUBUN_OUT_OF_SCOPE` へ** |
| `render_operations()` の「うち対象外」件数 | scope 判定 | **同上** |
| `_out_of_scope_section()` の抽出条件 | scope 判定 | **同上** |
| `validate_assignment()`（新設） | scope 判定 | **同上** |

規律: **`KUBUN_NEEDS_REASON` は reason 要否だけに使う。scope 判定はすべて
`kubun == KUBUN_OUT_OF_SCOPE` に統一する。**

`check_catalog()` に `covers_capabilities` の実在照合を足す（**実在・形・一意まで**。分母・被覆は見ない）。

```python
    # covers_capabilities の実在照合 (段 4)。**被覆漏れは見ない** (機能カタログが
    # 継承宣言の欄を持たないため。既存乖離 D20 / 保証境界は README に書く)。
    for cap in sorted(assignment.capabilities - set(seen)):
        violations.append(f"[{STAGE4}] カードが実在しない capability を挙げている: {cap}")
```

**「一意」が何の一意性かを書き分ける**（3 つを混同しない）。

| 対象 | 判定 | 担い手 |
|---|---|---|
| 機能カタログの id が重複しない | **禁じる** | 既存の段 4（`check_catalog`） |
| **1 枚のカードの `covers_capabilities` 配列内**で同じ id が 2 回出る | **禁じる** | 施策 4 の AC-13（`frozenset` 化する**前**に検査する） |
| **複数のカード**が同じ capability を挙げる | **禁じない** | — （S3 と S7 が同じ機能を別視点で踏むのは正常） |

`Assignment.capabilities` は `frozenset` なので、配列内の重複は集合化の時点で消える。
だから重複検査は**カード側（施策 4）**が持つ。

### PHPStan 適合チェック

- [x] 対象外（Python）。ただし `scripts/bug-hunt-inventory.py` は型注釈を持つ規律を維持する

### テスト計画

`scripts/tests/test_bug_hunt_inventory.py` に追加する（既存の検査は 1 本も消さない）。

- [ ] `story` を書いた注釈が **exit 3**（未知項目）で落ちること
- [ ] `covers_screens` に実在しない route を載せると **exit 3**
- [ ] `covers_screens` に非 safe method の route を載せると **exit 3**（欄違い）
- [ ] `covers_operations` に safe method の route を載せると **exit 3**（欄違い）
- [ ] 区分 `外` の route をカードに載せると **exit 3**
- [ ] 対象内の route がどのカードにも載っていないと **exit 3**
- [ ] 実在しない capability id をカードが挙げると **exit 3**
- [ ] `_story_cell()` の出力が値域に収まること — 単一値 / 複数値（昇順）/ 空集合は `-` /
      **`S10` と `S9` の並びが辞書順でなく数値順**になること
- [ ] 段 3（生成物の byte 一致）が複数値セルでも成立すること
- [ ] 区分 `終` の route が**どのカードにも載っていないと exit 3** になること（`終` は対象内）
- [ ] 区分 `終` の route を**カードに載せても通る**こと（対象外扱いしない）
- [ ] **複合 method route**（GET と POST を併せ持つ）で欄判定が誤らないこと
- [ ] **未注釈 route** があっても `KeyError` で落ちず、既存の「未注釈の route」違反として
      exit 3 になること
- [ ] `applicability: not_applicable` のカードの `covers_*` が割当に**数えられない**こと
- [ ] **終了コードを原因別に固定する**（「3 か 2 のどちらか」では後退を検出できない）
      - 前付けの形式違反・語彙違反・配列内重複・割当のドリフト → `generate` / `check` とも **exit 3**
      - `stories/` が無い / カードが 1 枚も読めない / ファイルが読み取り不能 → **exit 2**（検査成立不能）
      - どちらの場合も **`screens.md` / `operations.md` が 1 バイトも変わらない**こと
- [x] 個別の `DatabaseTransactions` を使っていない（DB を触らない）

### リスク

- **`scripts/` から `.claude/skills/.../stories/` を import する経路が要る** →
  生成器は既に `SKILL_DIR` を持っているので `sys.path.insert(0, str(STORIES_DIR))` で足りる。
  読み取り器は stdlib だけに依存するので副作用は無い。
- **`sorted()` の既定が辞書順で `S10 < S9` になる** → `key=lambda s: int(s[1:])` を明示し、
  自己テストで固定する（現在 7 枚なので実害は無いが、S10 を足した瞬間に壊れる形を残さない）。
- **`終` の意味を変えることの影響** → 現在 0 件なので実害は無い。ただし「対象外だった `終` が
  対象内になった」ことは `annotations.toml` 冒頭の説明と `stories/README.md` の
  `covers_*` の節に明記する（意味を静かに変えない）。

---

## 施策 8: 割当セルの複数値化に照合器を追従させる

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/coverage/correlate.py`（L505-507 / L533-534 付近）
- ファイル: `.claude/skills/app-bug-hunt/coverage/test_correlate.py`（**採用時債務にあるので施策 10 で削る**）

### 波及変更

- 乖離台帳: 既存 **D14** の拡張（施策 10）

### 現行コード

```python
    rows_by_story: dict[str, list[MechanismRow]] = defaultdict(list)
    for row in rows:
        rows_by_story[row.story].append(row)
```

セルをそのままキーにするため、`"S3 S7"` は `"S3"` の finding と一致しない。
現在は全行が単一値なので効いているが、複数値化すると**後退**する。

### 変更後コード

```python
# 割当セルの値域 (書き出し側の正本は scripts/bug-hunt-inventory.py。規則の散文は
# .claude/skills/app-bug-hunt/stories/README.md)。**寛容に正規化しない** —
# str.split() は前後空白も連続空白も黙って吸収するので、それだけで済ませると書式違反を見逃す。
#
# ★ 照合は fullmatch() で行う (Python の `$` は末尾改行の直前にも一致するため、
#   match() + `$` は「厳密一致」と同義ではない)。
STORY_CELL_RE = re.compile(r"(S[1-9][0-9]*( S[1-9][0-9]*)*|-)")
STORY_CELL_SEPARATOR = " "
STORY_CELL_EMPTY = "-"


def parse_story_cell(cell: str, route_name: str) -> list[str]:
    """割当セルを分解する。文法・昇順・重複を検証し、反したら FatalError。

    実在 (そのカードが在るか) は**見ない**。目録は生成物であり、割当列は実在するカードの
    前付けからしか作られない。手編集で紛れ込んだ id は目録の byte 一致検査が落とす。
    ここに実在検査を足すと照合器が stories/README.md を新たな入力に取ることになり、
    同じ規則が 2 か所に増える。
    """
    if STORY_CELL_RE.fullmatch(cell) is None:
        raise FatalError(
            f"割当セルが契約外: route={route_name} cell={cell!r} "
            "(S{n} を番号の昇順で半角空白 1 つ区切りに並べるか '-')"
        )
    if cell == STORY_CELL_EMPTY:
        return []

    ids = cell.split(STORY_CELL_SEPARATOR)
    numbers = [int(i[1:]) for i in ids]
    if numbers != sorted(set(numbers)):
        raise FatalError(
            f"割当セルが昇順でないか重複している: route={route_name} cell={cell!r}"
        )

    return ids
```

```python
    rows_by_story: dict[str, list[MechanismRow]] = defaultdict(list)
    for row in rows:
        for story in parse_story_cell(row.story, row.route_name):
            rows_by_story[story].append(row)
```

**`STORY_CELL_SEPARATOR` は correlate 側にも置く**。施策 7 の定数は別モジュール
（`scripts/bug-hunt-inventory.py`）にあり、そのまま参照すると `NameError` になる。
共有モジュール化は概念設計 Round 2 で「採らない」と確定済み
（CLI スクリプトはハイフンを含み import 対象にならない / 照合器は共有ファイルなので
アプリ固有モジュールへの依存を増やすと乖離が深くなる）。値域が 2 形に閉じていることと、
両側の同一ケース列挙のテストで担保する。

### PHPStan 適合チェック

- [x] 対象外（Python）

### テスト計画

`test_correlate.py` に追加する（既存の検査は 1 本も消さない）。

| ケース | 期待 |
|---|---|
| 単一値 `S3` | `S3` に索引される（現行と同じ挙動） |
| 複数値 `S3 S7` | `S3` と `S7` の**両方**に索引される |
| 対象外 `-` | どの story にも索引されない |
| 前後に空白 | **FatalError** |
| 連続空白 `S3  S7` | **FatalError** |
| 空セル | **FatalError** |
| 未知の綴り `SX` / `S0` / `S03` | **FatalError** |
| 降順 `S7 S3` | **FatalError** |
| 重複 `S3 S3` | **FatalError** |
| 実在しないカード `S8` | **通す**（責務外。生成器側が出さないことを施策 7 が固定する） |
| `route_name` を持たない finding が複数値行へブロードキャストされること | `via_capability` が立つ |

- [x] 個別の `DatabaseTransactions` を使っていない（DB を触らない）

### リスク

- **`FatalError` を投げると走行が止まる** → 目録は生成物であり、契約外のセルが出る状況は
  「目録を手編集した」か「生成器が壊れた」のどちらかである。どちらも黙って進んではいけない
  （`correlate.py` は既に「主入力が揃わない走行を成功にしない」を D14 の不変条件として持つ。同じ方針）。

---

## 施策 9: 目録を再生成する

### 変更箇所

- `.claude/skills/app-bug-hunt/screens.md`（71 件 / うち対象外 13 件）
- `.claude/skills/app-bug-hunt/operations.md`（79 件 / うち対象外 1 件）

### 波及変更

- なし（いずれも生成物。指紋台帳のキーに無いので乖離台帳の登録は不要）

### 変更内容

`python3 scripts/bug-hunt-inventory.py generate` を走らせる。差分は次の 2 種類に限る。

1. 割当ストーリー列に **S7 が加わる**（操作 9 件 + 画面 11 件。いずれもセルが `S3` → `S3 S7` /
   `S4` → `S4 S7` へ変わる。新しい行は増えない）
2. 前書きの説明文が「割当の直し方」を注釈からカードの前付けへ書き換わる

### テスト計画

- [ ] `scripts/bug-hunt-inventory-check.sh` が **exit 0** を返すこと（**このシェルは 1 バイトも変更しない**）
- [ ] `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` が通ること（既存）

### リスク

- 前書きの説明文を直し忘れると、目録が「annotations.toml を直せ」と嘘を言い続ける →
  `GENERATED_NOTICE` を同じ変更で直す

---

## 施策 10: 乖離台帳を更新する

### 変更箇所

- `docs/template-divergence.md`（冒頭の「登録エントリ: 36 件」/ D14 / D20 / 新規 D）
- `tests/Support/TemplateDivergence/LedgerPins.php`（`DIVERGENCE_ENTRY_COUNT` / `ADOPTION_DEBT_COUNT`）
- `tests/Support/TemplateDivergence/adoption-debt.tsv`（2 行削除）

### 波及変更

- テストファイル: `TemplateDivergenceLedgerFormatTest` / `TemplateDivergenceFingerprintTest`（既存。変更なし）

### 実測に基づく判定（唯一の根拠は `docs/template-fingerprints.json` のキーの実在）

| 対象 | 共有 | 採用時債務 | 扱い |
|---|---|---|---|
| `.claude/skills/app-bug-hunt/stories/README.md` / `stories/test_story_front_matter.py` | 無 | 無 | **新規 D の対象パス**（差が宣言される場所 = 書式の正本と、その差が実装に現れる場所 = 検査） |
| `.claude/skills/app-bug-hunt/stories/S1`〜`S7`*.md / `stories/story_front_matter.py` | 無 | 無 | 登録不要（カードと読み取り器は差の**結果**であって宣言ではない） |
| `scripts/bug-hunt-inventory.py` | 有 | 無 | 既存 D20 の対象パス。本文を拡張 |
| `.claude/skills/app-bug-hunt/inventory/annotations.toml` | 無 | 無 | 既存 D20 の対象パス。本文を拡張 |
| `scripts/tests/test_bug_hunt_inventory.py` | 有 | **有** | 債務から削り D20 の対象パスへ移す |
| `.claude/skills/app-bug-hunt/coverage/correlate.py` | 有 | 無 | 既存 D14 の対象パス。本文を拡張 |
| `.claude/skills/app-bug-hunt/coverage/test_correlate.py` | 有 | **有** | 債務から削り D14 の対象パスへ移す |
| `screens.md` / `operations.md` / `capability-catalog.md` | 無 | 無 | 登録不要 |
| `scripts/bug-hunt-inventory-check.sh` / `scripts/bug-hunt-shard.sh` / `.claude/skills/app-bug-hunt/SKILL.md` | 有 | **有** | **1 バイトも触らない** |

対象パスは**全登録の和集合で重複しないこと**が機械強制されているので、
`correlate.py` / `bug-hunt-inventory.py` / `annotations.toml` を持つ新規エントリは書けない。
新規は 1 件だけである。

### 変更後コード（新規 D。番号は実装時の最大 +1 = D40 を想定）

```markdown
## D40 シナリオカードの前付けは採るが、ステップ表の書式は採らない

| 行 | 内容 |
|---|---|
| 対象パス | `.claude/skills/app-bug-hunt/stories/README.md` / `.claude/skills/app-bug-hunt/stories/test_story_front_matter.py` |
| 業務要件起因の説明 | 所見台帳の finding は story までしか指さず step を指す欄を持たないため、ステップ識別子を入れても読む機械が 1 つも無い |
| 揃え続ける不変条件と保証機構 | 前付けの制限文法・番号規約・表 A / 表 B との突合は `stories/test_story_front_matter.py` が強制し、`BughuntStoryToolSelfTest` が composer test の配線に載せる |
| 再判定の条件 | `ledger/findings.schema.json` に step を指す欄が入ったとき / 家系の正典が t2 以降でステップ表を版の名前に含めたとき / `applicability` に `not_applicable` を取るカードを 1 枚でも置くことになったとき |
| 決めた日 | 2026-08-23 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260823-0022-bughunt-story-front-matter-adoption/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

観点表・「なぜ正当な差分か」・「揃えている不変条件」・「保証しないもの」・「関連」の各節を、
概念設計の該当箇所から転記する。**`not_applicable` の再判定条件は
`stories/README.md` と概念設計のスコープ外 7 と本エントリで同じ文言にする**。

### D14 の拡張（理由ごとに別行にする）

```markdown
| 観点 | テンプレート | 本アプリ |
|---|---|---|
| (既存 4 行はそのまま) | … | … |
| 目録の割当列の読み方 (理由 2) | セルをそのままキーにするので `S3 S7` の行は `S3` の finding と一致しない | **セルを検証してから分解**し、各 story へ索引する (単一値の挙動は不変。正典に無い上乗せ = 家系への還流候補) |
```

保証表と再判定の条件も理由ごとに 1 行ずつ足す
（理由 1 = 実行した route の記録 / 理由 2 = 割当列の分解）。
将来どちらか一方だけを解消するときに、もう一方が落ちないようにするためである。

### D20 の拡張

```markdown
| 観点 | 家系の正典 / テンプレート | 本アプリ |
|---|---|---|
| (既存 3 行はそのまま) | … | … |
| 割当の正本 | カードの前付け (`covers_screens` / `covers_operations`) | **同じ** (2026-08-23 に注釈の `story` を撤去して一本化した。以前は注釈側が正本だった) |
```

対象パスへ `scripts/tests/test_bug_hunt_inventory.py` を追加する。

### pin の更新

```php
    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
    public const int DIVERGENCE_ENTRY_COUNT = 37;   // 36 → 37 (D40 の新設)

    /** 採用時債務の件数。 */
    public const int ADOPTION_DEBT_COUNT = 169;     // 171 → 169 (2 件を登録簿へ移した)
```

`docs/template-divergence.md` 冒頭の「登録エントリ: 36 件」も **37 件**へ直す（3 点一致）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（定数のみ。`public const int` / `public const string`）
- [x] null 安全（該当なし）
- [x] DTO を返している（該当なし。`LedgerPins` は定数の置き場であり解析・I/O を持たない）

### テスト計画

- [ ] `TemplateDivergenceLedgerFormatTest` が通ること（登録メタ表 9 行ちょうど / 値域 / 対象パスの重複なし）
- [ ] `TemplateDivergenceFingerprintTest` が通ること（差分に登録があること / 債務の増減が pin と一致すること）
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- **3 点一致（宣言行 / 見出しの実数 / 定数）を 1 つ直し忘れる** → 形式検査が落とす（意図した設計）
- **番号の再利用** → 台帳は「番号を再利用しない」を人が守る規約としており、
  実装時に既存の最大番号を実測してから +1 する

---

## 施策 11: 移行の検算を残す

### 変更箇所

- `devnotes/20260823-0022-bughunt-story-front-matter-adoption/migrate_story_assignment.py`（新規・**使い捨て**）
- `devnotes/20260823-0022-bughunt-story-front-matter-adoption/migration-verification.md`（新規・検算の出力）

**`scripts/` へは置かない**。1 度きりの移行に恒久のテストを背負わせない（「今必要なものだけ作る」）。

### 波及変更

- なし（`devnotes/` はアプリの実行経路に入らない）

### 変更内容

2 つの機能を持つ。

1. **生成**: 撤去前の `annotations.toml` の `story` と `bughunt:inventory-scan` の HTTP method から、
   6 枚分の `covers_screens` / `covers_operations` の初期値を作る（手写しをしない）。
2. **検算**: 撤去後の前付けから逆引きした関係と、撤去前の関係を突き合わせる。

### 検算の仕様（Codex Round 3 の Critical への対応）

`route → 割当カード集合` の 1 本では**足りない**。同じ route が同じカードに載ったまま
`covers_screens` と `covers_operations` の間で移動しても集合が一致してしまうためである。
比較する関係を欄ごとに 2 本に分ける。

| 関係 | 変換前（注釈由来） | 変換後（前付け由来） |
|---|---|---|
| 画面側 | `story` を持ち、かつ **safe method の web route** | 各カードの `covers_screens` の和 |
| 操作側 | `story` を持ち、かつ **非 safe method の web route** | 各カードの `covers_operations` の和 |

- 期待の欄は**変換前の HTTP method から導く**（前付け側の値を根拠にしない。
  根拠にすると自分で自分を検算することになる）。
- **母集合は対象内 route の全数で固定**し、対象外（`kubun` = 外）の route は
  **両側とも空集合**であることを明示的に assert する
  （「双方が単に省略したので一致した」を成立させない）。
- 差分は **S7 の追加分だけ**であることを確認する。それ以外の差分が 1 件でもあれば移行は失敗である。

この形にすると「未知 story」「対象外 route」「safe / 非 safe の誤振り分け」「複数割当」の
4 つをすべて検出できる。

### S7 の追加分は件数ではなく route 名で固定する

「変換後のみに現れたのが S7 ならよい」だけでは、**誤った route へ S7 を付けても合格する**。
S7 の `covers_screens` は現行が散文なので手作業で起こす — まさにここに穴が残る。
そこで検算スクリプトが**期待する S7 追加分を欄別の明示リストとして持ち、完全一致でなければ非 0 終了する**。

```python
# S7 が新たに消化を宣言する route。カード本文 (現行の散文) から起こしたものを
# **route 名で**固定する (件数だけの判定にしない)。
EXPECTED_S7_ADDED_OPERATIONS = (
    "capture.takes.adopt",
    "capture.takes.destroy",
    "projects.categories.destroy",
    "projects.categories.reorder",
    "projects.categories.update",
    "projects.manuals.destroy",
    "projects.manuals.duplicate",
    "projects.manuals.scenario.update",
    "projects.manuals.update",
)
# S7 が組織 B 視点で踏み直す nested screen。全 11 件を route 名で固定する
# (実測で全件が annotations.toml に実在し、区分は 通常 であることを確認済み)。
EXPECTED_S7_ADDED_SCREENS = (
    "capture.manuals.show",
    "capture.takes.playback",
    "projects.categories.index",
    "projects.edit",
    "projects.manuals.download",
    "projects.manuals.edit",
    "projects.manuals.jobs.show",
    "projects.manuals.render-jobs.playback",
    "projects.manuals.render-jobs.show",
    "projects.manuals.show",
    "projects.show",
)
```

**S7 の `covers_screens` は空集合ではない**。現行カードの散文は
「(S3/S4 の全 nested screen を B 視点で 404 確認。**新規消化はしない**が再走査)」と書いているが、
これは「目録の未割当を埋める新規消化は無い」という意味であって、
「S7 が何も開かない」ではない。前付けは 1 route → N カードを表せるので、
**S7 が実際に開く画面を宣言する**のが正しい（それが正典が `covers_screens` を配列にした理由である）。
上の 11 件はいずれも既に S3 / S4 が消化しているので、目録のセルは `S3` → `S3 S7` /
`S4` → `S4 S7` へ変わる。

判定は次の 3 つすべてが成り立つときだけ成功とする。

1. 「変換前のみ」が **0 件**（既存 6 カードの割当が 1 件も落ちていない）
2. 「変換後のみ」が **`EXPECTED_S7_ADDED_*` と完全一致**（欄別に集合として比較する）
3. 対象外（`kubun` = 外）の route が**両側とも空集合**

### 手順節の不変を機械で残す

施策 2 は「`## 手順` 以降を 1 文字も触らない」を規律にしているが、目視の差分確認だけに委ねない。
検算資料へ **7 枚の `## 手順` 節の移行前後 sha256** を記録し、全 7 件が一致することを示す。

**抽出境界を明文で定める**（旧メタ節の撤去分をハッシュ対象へ誤って含めないため）:

> `## 手順` の**見出し行の次の行**から、**次に現れる H2 見出し (`## ` で始まる行) の直前の行**まで。
> 末尾の空行は落とさない（空行の増減も差分として検出する）。次の H2 が無ければファイル末尾まで。

旧メタ節（`- 前提状態:` / `- 目的:`）は `## 手順` より**前**に、
`## このストーリーで消化する screens / operations` は**後**にあるが、いずれも別の H2 節なので
この境界には入らない。

### 出力（`migration-verification.md`）

件数だけでなく**全差分**を `欄 / route / 変換前 / 変換後` の 4 列で出す。

```markdown
# 移行の検算

- 実行日時: (JST)
- 変換前の観測点: (commit)
- 判定: 成功 / 失敗

## 全差分 (欄 / route / 変換前 / 変換後)

| 欄 | route | 変換前 | 変換後 | 判定 |
|---|---|---|---|---|
| operations | projects.manuals.update | S3 | S3 S7 | S7 の追加分 (期待どおり) |
| screens | dashboard | S1 | S1 | 一致 |
…

## 集計

| 欄 | 一致 | 変換前のみ (落ちた) | 変換後のみ (S7 の追加分) |
|---|---|---|---|
| screens | 58 | 0 | 11 |
| operations | 78 | 0 | 9 |

## 期待する S7 追加分との完全一致

| 欄 | 期待 | 実測 | 判定 |
|---|---|---|---|
| operations | 9 件 (route 名を列挙) | 同左 | 一致 |
| screens | 11 件 (route 名を列挙) | 同左 | 一致 |

## 対象外 route (両側とも空集合であること)

| route | 変換前 | 変換後 |
|---|---|---|
| debug.login | (空) | (空) |
…

## `## 手順` 節の不変 (移行前後の sha256)

| カード | 移行前 | 移行後 | 判定 |
|---|---|---|---|
| S1 | … | … | 一致 |
…
```

### PHPStan 適合チェック

- [x] 対象外（Python / devnotes）

### テスト計画

- [ ] 検算の出力で「変換前のみ」が **0 件**であること
- [ ] 「変換後のみ」が `EXPECTED_S7_ADDED_SCREENS` / `EXPECTED_S7_ADDED_OPERATIONS` と
      **欄別に完全一致**すること（件数だけの判定にしない）
- [ ] 対象外 route が両側とも空集合であること
- [ ] 7 枚の `## 手順` 節の sha256 が移行前後で一致すること
- [ ] 施策 7 の恒久検査（F4 / F5）が緑であること

### リスク

- **変換器にテストが無い** → 検算 1 本が指摘された 4 ケースを内包するので、単体テストは置かない。
  変換器が間違えれば関係が一致せず、移行が止まる。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 施策 6（注釈からの `story` 撤去）と施策 2（前付けの付与）は**同一コミットで行わないと割当が消える**ため、途中で他の作業が挟まると壊れる。(2) `screens.md` / `operations.md` は生成物であり、他の TODO が route を足すと byte 一致検査が競合する。(3) 乖離台帳の件数 pin（`DIVERGENCE_ENTRY_COUNT` / `ADOPTION_DEBT_COUNT`）は他の作業も動かすので、worktree を分けて最後に main を取り込み直す方が安全である。 |
| 競合リスク | **route を追加・削除する作業**と競合する（`annotations.toml` / `screens.md` / `operations.md` / カードの `covers_*` の 4 か所が同時に動く）。マージ前に `scripts/bug-hunt-inventory-check.sh` を再実行し、`generate` をかけ直す手順を実装時のチェックリストに入れる。乖離台帳の pin も同様に、main 取り込み後に実測し直す。 |

---

## 保証しないもの（詳細設計としての宣言）

全数対応表で採用・差と分類した項目のうち機械保証しないものと、ID を持たない保証境界を、
ここに 1 か所へ集める。

**1 対 1 の範囲を限定する**: 施策 4 の `NON_MECHANICAL` 定数と 1 対 1 に対応するのは
**「採用」と分類した非機械保証の 2 件（E5 / G6）だけ**である。
`I5` は分類が「差」であり担い手は目録側（実在・形・一意までは見る）。ID を持たない 4 件は
機構全体の保証境界であって不変条件の分類ではない。

| 項目 | 内容 | 再判定の条件 |
|---|---|---|
| E5 | `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定マップの一致は見ない（固定マップは派生キャッシュ）。**正典も未達**である | 家系の正典が前付けからの導出を実装したとき |
| G6 | 「H 番号の意味をカードに書かない」は文書規約であり機械検査を持たない。正典もこれ単独の検査は持たず、禁止形の検出は本作業が採らない MC-11 の一部である | ステップ表の書式を採るとき |
| I5 | `covers_capabilities` の**被覆漏れ**（正典の C4 = 直接分母 ∪ 間接分母 − 実効被覆）は見ない。機能カタログが継承宣言の欄（`no_route` / `coverage_surface` / `covered_via`）を持たないため（既存 D20） | 機能カタログが継承宣言の欄を持つ形になったとき |
| — | **割当が痩せたこと**は検出できない。F5 は「1 枚以上に載っていること」しか見ないので、ある route が S3 と S7 の両方から S3 だけへ減っても緑のままである | — （移行時は施策 11 で構造的に防ぐ。以後は PR レビューの義務） |
| — | `accounts` と `database/seeders/ManualTestSeeder.php` の一致は見ない。**正典も同じく機械検査していない**（PR レビューの義務） | — |
| — | `web` group を宣言していない面には沈黙する（機械向け API / Filament / MCP / webhook の大半）。既存 D20 の保証境界をそのまま引き継ぐ | — |
| — | step 識別子の再採番の禁止は見ない。ステップ表を採らないため対象外である | 新規 D の再判定の条件と同じ |

## 使命・禁止事項チェック

| 項目 | 判定 |
|---|---|
| 全施策が使命に寄与するか | ○ — bug-hunt は AI-CUE の中核パイプライン（SOP → シナリオ → 撮影 → レンダ）の詰み・認可漏れを見つける唯一の探索的手段であり、その**分母の正しさ**を機械で守る。特に S7（認可境界）が消化する 9 操作が現状 0 件として扱われている穴を塞ぐ |
| 禁止事項 1（テストなしの実装完了報告） | ○ — 全 6 の CI 失敗条件に担い手を割り当て、施策 5 で `composer test` の配線に載せる |
| 禁止事項 2（PHPStan の widen / baseline） | ○ — 新規 PHP は Architecture テスト 1 本と定数クラス 1 本のみ。`array{0: int|null, 1: string}` を phpdoc で明示して level 10 を通す（先例と同形） |
| 禁止事項 3（dev DB への破壊操作） | ○ — DB を触らない |
| 禁止事項 4（`response()->json()` 直書き） | ○ — HTTP 応答を作らない |
| 禁止事項 5 / 6（LLM / prompt） | ○ — LLM を呼ばない |
| 禁止事項 7 / 8（redirect / disabled UI） | ○ — UI を触らない |
| 禁止事項 9（Artifact の使用） | ○ — 成果物はすべて `devnotes/` 配下のファイルとして出力する |
| 個別 `DatabaseTransactions` の不使用 | ○ — DB を触らない |
| 既存テストの削除・上書き | ○ — 既存の検査は 1 本も消さない（施策 7 / 8 はいずれも追加のみ） |
| 既存の検査を壊さない | ○ — `scripts/bug-hunt-inventory-check.sh` は 1 バイトも触らず、終了コードの契約（0 / 2 / 3）も変えない |
