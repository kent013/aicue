# 実装レビュー依頼 (T245 / Round 1)

【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項 — AGENTS.md より】
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Laravel + Svelte アプリ「AI-CUE」のコードレビュアーである。
本変更は **PHP/Svelte のアプリ本体ではなく、bug-hunt (LLM 探索的バグハント) 基盤の
「分母の正しさ」を守る機構** (Python ツール + Architecture テスト + 文書の正本) の作り替えである。

### レビュー観点

1. **詳細設計との一致性** — 設計書の施策 1〜11 が実装されているか。設計から意図的に外した点
   (後述の「設計からの逸脱」) の判断は妥当か
2. **正確性** — 判定ロジックの穴 (見逃し・誤検知)、境界条件、例外経路、終了コード規約の一貫性
3. **PHPStan level 10 適合性** — 新規 PHP 2 本 (`BughuntStoryToolSelfTest.php` /
   `StoryFrontMatterPins.php`) と変更 1 本 (`BugHuntInventoryCheckInvariantTest.php`)
4. **テスト網羅性** — 負例が「正しい理由で」落ちるか。空振り (母集団 0 件で緑) が無いか
5. **静的検査 (gate) の共通規約への適合** (AGENTS.md §静的検査 (gate) と走査器の共通規約):
   - (a) 名前解決は完全修飾で / (b) 解決できない形は落とす (fail-closed)。未解決を解決済みへ混ぜない。
     保証範囲の外は docblock へ明記する。「違反 0 件」と「母集団 0 件」を区別する /
     (c) 検出力は負例で裏取り / (d) 集めた走査結果を判定に使わない形を作らない /
     (e) 語彙一致の否定形はトークンの完全一致で判定する
6. **二重の正本を作っていないか** — 本作業の主目的は「割当の正本を 1 つにする」ことである。
   同じ規則が 2 か所に書かれて食い違う余地が残っていないか
7. **保証範囲の誇張が無いか** — docstring / 乖離台帳 / README が「実際より広く」書いていないか
8. **不必要な複雑化が無いか** (AGENTS.md 思考原則 2「今必要なものだけ作る」)

DESIGN.md / Atomic Design 観点は**対象外**である (本差分は `resources/js` / `resources/css` を
1 バイトも触っていない)。

### 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する

---

## user: 背景と差分

### 何をしたか (1 段落)

bug-hunt のシナリオカード (`.claude/skills/app-bug-hunt/stories/S1〜S7*.md`) に
**制限文法の前付け (front matter)** を導入し、「どの route をどのカードが消化するか (割当)」の
正本を、注釈ファイル `inventory/annotations.toml` の `story` キーから**カードの前付け**
(`covers_screens` / `covers_operations` / `covers_capabilities`) へ移した。
目録 `screens.md` / `operations.md` の「割当ストーリー」列は前付けから逆引き生成される。
これにより 1 route を複数カードが消化できるようになり (セルが `S3 S7` のように並ぶ)、
S7 (認可境界 IDOR) が実際に踏む 9 操作 + 11 画面が「S3/S4 だけ」に潰れていた穴が塞がった。

### 設計からの逸脱 (レビューしてほしい判断)

1. **`Assignment.cards` フィールドを持たせなかった**。詳細設計は
   `cards: tuple[str, ...]` を持つ形だったが、目録の生成にも突合にも使わないため、
   共通規約 (d)「集めた走査結果を判定に使わない形を作らない」に従って落とした。
2. **`tests/Architecture/BugHuntInventoryCheckInvariantTest.php` を変更した**。
   このファイルは「採用時債務一覧」(`adoption-debt.tsv`) に在り、設計は触れていなかった。
   sandbox に**カードと読み取り器を置かないと生成器が段 2 を成立させられない**ため変更が必須になり、
   乖離台帳の 3 択のうち「登録を書いて債務から削る」を採って D20 の対象パスへ移した
   (`ADOPTION_DEBT_COUNT` 171 → 168、`DIVERGENCE_ENTRY_COUNT` 36 → 37)。
3. **`.claude/skills/app-bug-hunt/SKILL.md` と `scripts/README.md` は 1 バイトも触っていない**。
   どちらも採用時債務に在り、設計が「触らない」と定めている。帰結として SKILL.md の
   「注釈 (割当・区分・理由)」という記述は**古くなった**。ただし注釈へ `story` を書き戻すと
   deny-by-default で `未知の項目: story` として exit 3 になるため、静かに壊れることはない。
   この残置の是非を判定してほしい。
4. **`correlate.py` に `FatalError` クラスを新設した**。詳細設計は既存の `FatalError` を
   使う前提で書かれていたが、実物には存在しなかったため新設し、`main()` で捕捉して
   終了コード 3 (主入力の契約違反) へ写像した。

### 検証結果

- `composer test`: 6428 tests / 6426 passed / 0 failed / 2 skipped (30809 assertions)
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed / `pnpm lint` / `pnpm typecheck` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages`: すべて green
- `python3 -m unittest test_story_front_matter` (stories/): 73 tests OK
- `python3 -m unittest test_bug_hunt_inventory` (scripts/tests/): 75 tests OK
- `python3 -m unittest test_correlate` (coverage/): 58 tests OK
- `python3 scripts/bug-hunt-inventory.py check`: exit 0 (画面 71 件 / 操作 79 件)
- 移行の検算 (`devnotes/.../migrate_story_assignment.py verify`): **成功**
  (「変換前のみ (割当が落ちた)」0 件 / 「変換後のみ」= S7 の追加分 11 画面 + 9 操作と完全一致 /
  対象外 route は両側とも空集合 / 7 枚の `## 手順` 節の sha256 が移行前後で全件一致)

### 詳細設計書

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
| I1 | 対象内（`kubun` が **外** でない）の web route は **1 枚以上の `applicable` なカードに載る**。**`終` は対象内**として扱う（実測 0 件） | 採用 | 施策 7（`DRIFT_STORY_UNASSIGNED` 相当） |
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
  （施策 11 の `EXPECTED_S7_PRIOR_SCREENS` が正本。検算が完全一致で判定する）。
  `covers_operations` の 9 件も同様に `EXPECTED_S7_PRIOR_OPERATIONS` で固定する。
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

**AC-14 自身も「正例 1 + 負例 1 以上」の規約に従わせる**。定数をそのまま assert するだけだと、
AC-14 を `SUBJECT_TO_TESTS` へ登録した瞬間に「accepts / rejects が無い」で自分が落ちる。
判定を**入力付きの純関数へ抽出**して、検出分岐そのものを負例で裏取りする。

```python
def partition_violations(
    all_invariants: tuple[str, ...],
    adopted: tuple[str, ...],
    differences: tuple[str, ...],
    not_adopted: tuple[str, ...],
    bearers: tuple[str, ...],
    expected_total: int,
) -> list[str]:
    """分類と担い手の整合を見て違反の並びを返す (実データにも合成入力にも使う純関数)。"""
    violations: list[str] = []
    if len(all_invariants) != expected_total:
        violations.append(f"全数が {expected_total} 件でない: {len(all_invariants)}")
    if len(all_invariants) != len(set(all_invariants)):
        violations.append("全数の一覧に重複がある")

    classified = [*adopted, *differences, *not_adopted]
    if len(classified) != len(set(classified)):
        violations.append("分類が重複している")
    if set(classified) != set(all_invariants):
        missing = sorted(set(all_invariants) - set(classified))
        extra = sorted(set(classified) - set(all_invariants))
        violations.append(f"分類の和が全数と一致しない (不足 {missing} / 余分 {extra})")

    for key in adopted:
        if key not in bearers:
            violations.append(f"担い手の無い採用項目: {key}")
    for key in sorted(set(bearers) - set(all_invariants)):
        violations.append(f"担い手集合に未知の ID: {key}")

    return violations
```

```python
    # --- 正例 ---
    def test_ac_14_accepts_complete_partition(self) -> None:
        """実データの 58 項目が 3 分類へ過不足なく割れ、採用項目に担い手が居ること。"""
        self.assertEqual([], partition_violations(
            ALL_INVARIANTS, ADOPTED, DIFFERENCES, NOT_ADOPTED,
            (*STORY_SIDE, *INVENTORY_SIDE, *NON_MECHANICAL), 58,
        ))
        # 非機械保証は「保証しないもの」の節と 1 対 1 にする (黙って落とさない)。
        self.assertEqual(("E5", "G6"), NON_MECHANICAL)

    def test_ac_14_accepts_explicit_subject_to_test_mapping(self) -> None:
        """stories 側が担う項目が、実在する検査へ**明示的に**紐づいていること。

        ★ 主題名からテスト名を**推測しない**。`AC-01` から作った `test_ac_01` は
          実際の `test_ac_01_rejects_quoted_scalar` と一致せず、hasattr が常に偽になる。
        """
        for key in STORY_SIDE:
            self.assertIn(key, INVARIANT_TO_SUBJECT, f"{key} に主題が無い")
            self.assertIn(INVARIANT_TO_SUBJECT[key], SUBJECT_TO_TESTS)

        for subject, names in SUBJECT_TO_TESTS.items():
            for name in names:
                self.assertTrue(callable(getattr(self, name, None)), f"{name} が実在しない")
            # 各主題に正例と負例が 1 本以上ある (負例だけ / 正例だけを許さない)。
            self.assertTrue(any("accepts" in n for n in names), f"{subject} に正例が無い")
            self.assertTrue(any("rejects" in n for n in names), f"{subject} に負例が無い")

    # --- 負例 (検出分岐そのものを裏取りする) ---
    def test_ac_14_rejects_missing_invariant(self) -> None:
        """分類のどれかから項目が落ちたら検出すること (I 群の丸ごと欠落が実例)。"""
        self.assertNotEqual([], partition_violations(
            ("A1", "A2"), ("A1",), (), (), ("A1",), 2,
        ))

    def test_ac_14_rejects_duplicate_classification(self) -> None:
        """同じ ID を 2 つの分類へ入れたら検出すること。"""
        self.assertNotEqual([], partition_violations(
            ("A1",), ("A1",), ("A1",), (), ("A1",), 1,
        ))

    def test_ac_14_rejects_adopted_without_bearer(self) -> None:
        """採用したのに担い手が居ない項目を検出すること。"""
        self.assertNotEqual([], partition_violations(
            ("A1",), ("A1",), (), (), (), 1,
        ))

    def test_ac_14_rejects_unknown_bearer_id(self) -> None:
        """担い手集合に全数一覧に無い ID があったら検出すること (綴り間違い)。"""
        self.assertNotEqual([], partition_violations(
            ("A1",), ("A1",), (), (), ("A1", "Z9"), 1,
        ))

    def test_ac_14_rejects_wrong_total(self) -> None:
        """全数が宣言した件数と違ったら検出すること。"""
        self.assertNotEqual([], partition_violations(
            ("A1",), ("A1",), (), (), ("A1",), 58,
        ))
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

**`applicability: not_applicable` のカードの扱い**: 割当の母集団から**外す**。

理由は「実走しないから」**ではない** — 実走除外の契約 (D6) は本作業では**未採用**であり、
現行の SKILL.md はそれを保証しない。正しい理由は次である。

> `not_applicable` のカードは F2 により `## 手順` 節を持たない。手順が無いカードは
> **coverage の消化カードとして数えるべきではない**ので、割当の母集団から外す。
> 実走対象から除外されること自体は D6 のとおり未採用であり、**現在該当カードは 0 枚**である。

この扱いに合わせ、全数対応表の I1 も「1 枚以上の **applicable** カードに載る」と読む
（生成器の仕様と一致させる）。

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
# **route 名で**固定する (件数だけの判定にしない)。変換前の割当は
# EXPECTED_S7_PRIOR_OPERATIONS (後述) が持つ。
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
# S7 が組織 B 視点で踏み直す画面。全 11 件を route 名で固定する
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

### 変換前の割当も固定する（S7 を「元から誰も消化していない route」へ付けさせない）

「変換後のみに現れた S7 関係が期待リストと一致」だけでは、**変換前が空だった route に
S7 だけを足しても合格する**（`before: []` → `after: [S7]` が通ってしまう）。
設計が意図しているのは `after == before ∪ {S7}` であり、`before` が空でないことが前提である。
そこで **route ごとの変換前集合も固定**する。

```python
# S7 が踏み直す画面の「変換前の割当」。全 11 件が元から S3 または S4 の消化対象である
# ことを機械で閉じる (実測で確認済み)。
EXPECTED_S7_PRIOR_SCREENS = {
    "capture.manuals.show": frozenset({"S3"}),
    "capture.takes.playback": frozenset({"S3"}),
    "projects.categories.index": frozenset({"S4"}),
    "projects.edit": frozenset({"S4"}),
    "projects.manuals.download": frozenset({"S3"}),
    "projects.manuals.edit": frozenset({"S3"}),
    "projects.manuals.jobs.show": frozenset({"S3"}),
    "projects.manuals.render-jobs.playback": frozenset({"S3"}),
    "projects.manuals.render-jobs.show": frozenset({"S3"}),
    "projects.manuals.show": frozenset({"S3"}),
    "projects.show": frozenset({"S3"}),
}
EXPECTED_S7_PRIOR_OPERATIONS = {
    "capture.takes.adopt": frozenset({"S3"}),
    "capture.takes.destroy": frozenset({"S3"}),
    "projects.categories.destroy": frozenset({"S4"}),
    "projects.categories.reorder": frozenset({"S4"}),
    "projects.categories.update": frozenset({"S4"}),
    "projects.manuals.destroy": frozenset({"S3"}),
    "projects.manuals.duplicate": frozenset({"S3"}),
    "projects.manuals.scenario.update": frozenset({"S3"}),
    "projects.manuals.update": frozenset({"S3"}),
}
```

判定は次の 4 つすべてが成り立つときだけ成功とする。

1. 「変換前のみ」が **0 件**（既存 6 カードの割当が 1 件も落ちていない）
2. 「変換後のみ」が **`EXPECTED_S7_PRIOR_*` のキー集合と完全一致**（欄別に集合として比較する）
3. その各 route について **`before == EXPECTED_S7_PRIOR_*[route]`** かつ
   **`after == before | {"S7"}`**（`before` が空の route に S7 を足していないこと）
4. 対象外（`kubun` = 外）の route が**両側とも空集合**

### 11 画面の選定根拠を検算資料へ残す

`projects.edit` と `projects.show` は「nested child」ではなく project 自身の画面なので、
「全 nested screen」という現行カードの散文とのずれが読み手に見える。境界の種別で分類して残す。

| 境界の種別 | route |
|---|---|
| project 自身の current-org 境界 | `projects.show` / `projects.edit` |
| project 配下 manual の親子境界 | `projects.manuals.show` / `projects.manuals.edit` / `projects.manuals.download` |
| manual 配下の take / render / job の親子境界 | `projects.manuals.jobs.show` / `projects.manuals.render-jobs.show` / `projects.manuals.render-jobs.playback` |
| project 配下 category の親子境界 | `projects.categories.index` |
| capture 経由で manual / take へ到達する境界 | `capture.manuals.show` / `capture.takes.playback` |

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
- [ ] 「変換後のみ」が `EXPECTED_S7_PRIOR_SCREENS` / `EXPECTED_S7_PRIOR_OPERATIONS` の
      **キー集合と欄別に完全一致**すること（件数だけの判定にしない）
- [ ] 各 route について `before == EXPECTED_S7_PRIOR_*[route]` かつ `after == before | {"S7"}` であること
      （`before` が空の route に S7 を足していないこと）
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

---

## 実装差分 (git diff)

```diff
diff --git a/.claude/skills/app-bug-hunt/coverage/correlate.py b/.claude/skills/app-bug-hunt/coverage/correlate.py
index ee2307e4..e3ac7bd0 100644
--- a/.claude/skills/app-bug-hunt/coverage/correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/correlate.py
@@ -62,6 +62,14 @@ EXIT_OK = 0
 EXIT_INPUT_ERROR = 1        # 読み込み・parse の失敗 (従来どおり)
 EXIT_INPUT_UNAVAILABLE = 3  # 主入力の可用性違反 = 検査を成立させられない
 
+
+class FatalError(Exception):
+    """主入力が契約に反していて検査を成立させられない状態 (終了コード 3)。
+
+    目録は生成物なので、契約外の割当セルが出る状況は「目録を手編集した」か
+    「生成器が壊れた」のどちらかである。どちらも黙って進んではいけない。
+    """
+
 # 記録器が書く status の語彙。ok|blocked の 2 値だけを受け付ける。
 VALID_STATUSES = {"ok", "blocked"}
 
@@ -80,6 +88,42 @@ _NAME_HEADERS = ("name", "api route name", "route name", "route_name")
 _STORY_HEADERS = ("story",)
 _KUBUN_HEADERS = ("区分",)
 
+# 割当セルの値域 (書き出し側の正本は scripts/bug-hunt-inventory.py。規則の散文は
+# .claude/skills/app-bug-hunt/stories/README.md)。**寛容に正規化しない** —
+# str.split() は前後空白も連続空白も黙って吸収するので、それだけで済ませると書式違反を見逃す。
+#
+# ★ 照合は fullmatch() で行う (Python の `$` は末尾改行の直前にも一致するため、
+#   match() + `$` は「厳密一致」と同義ではない)。
+STORY_CELL_RE = re.compile(r"(S[1-9][0-9]*( S[1-9][0-9]*)*|-)")
+STORY_CELL_SEPARATOR = " "
+STORY_CELL_EMPTY = "-"
+
+
+def parse_story_cell(cell: str, route_name: str) -> list[str]:
+    """割当セルを分解する。文法・昇順・重複を検証し、反したら FatalError。
+
+    実在 (そのカードが在るか) は**見ない**。目録は生成物であり、割当列は実在するカードの
+    前付けからしか作られない。手編集で紛れ込んだ id は目録の byte 一致検査が落とす。
+    ここに実在検査を足すと照合器が stories/README.md を新たな入力に取ることになり、
+    同じ規則が 2 か所に増える。
+    """
+    if STORY_CELL_RE.fullmatch(cell) is None:
+        raise FatalError(
+            f"割当セルが契約外: route={route_name} cell={cell!r} "
+            "(S{n} を番号の昇順で半角空白 1 つ区切りに並べるか '-')"
+        )
+    if cell == STORY_CELL_EMPTY:
+        return []
+
+    ids = cell.split(STORY_CELL_SEPARATOR)
+    numbers = [int(i[1:]) for i in ids]
+    if numbers != sorted(set(numbers)):
+        raise FatalError(
+            f"割当セルが昇順でないか重複している: route={route_name} cell={cell!r}"
+        )
+
+    return ids
+
 
 # --------------------------------------------------------------------------- #
 # 入力ロード
@@ -502,9 +546,12 @@ def correlate(routes, operations, executed, findings, tb_index, *,
     # capability_tag -> 機構群 (operations.md には capability 列が無いので、
     # finding の route 直結を優先しつつ、route 不明 finding は story 一致の機構へ
     # capability 経由でブロードキャストする)。
+    # 割当セルは複数値を取りうる (1 route を複数カードが消化する)。セルをそのまま
+    # キーにすると `S3 S7` の行が `S3` の finding と一致しなくなるので、検証してから分解する。
     rows_by_story: dict[str, list[MechanismRow]] = defaultdict(list)
     for row in rows:
-        rows_by_story[row.story].append(row)
+        for story in parse_story_cell(row.story, row.route_name):
+            rows_by_story[story].append(row)
 
     # finding 紐付け。species_key 単位で二重計上を防ぐ。
     counted: dict[str, set[str]] = defaultdict(set)  # route_name -> {species_key}
@@ -726,11 +773,16 @@ def main(argv=None) -> int:
               file=sys.stderr)
         return EXIT_INPUT_UNAVAILABLE
 
-    corr = correlate(
-        routes, operations, executed, findings, tb_index,
-        run_id=args.run_id, hotspot_threshold=args.hotspot_threshold,
-        dropped_other_run=dropped,
-    )
+    try:
+        corr = correlate(
+            routes, operations, executed, findings, tb_index,
+            run_id=args.run_id, hotspot_threshold=args.hotspot_threshold,
+            dropped_other_run=dropped,
+        )
+    except FatalError as e:
+        # 目録は生成物である。契約外の割当セルは手編集か生成器の故障なので成功にしない。
+        print(f"ERROR: 主入力が契約に反している: {e}", file=sys.stderr)
+        return EXIT_INPUT_UNAVAILABLE
 
     if args.json:
         print(json.dumps(to_summary(corr), ensure_ascii=False, indent=2))
diff --git a/.claude/skills/app-bug-hunt/coverage/test_correlate.py b/.claude/skills/app-bug-hunt/coverage/test_correlate.py
index 3ad3c3d7..29d45bf6 100644
--- a/.claude/skills/app-bug-hunt/coverage/test_correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/test_correlate.py
@@ -368,6 +368,38 @@ class CorrelateTest(unittest.TestCase):
             self.assertTrue(r.via_capability)
             self.assertIn("AUTH-03", r.capability_tags)
 
+    def test_複数値行は両方のstoryへブロードキャストされる(self):
+        operations = dict(self.operations)
+        operations["organizations.store"] = {
+            "operation": "organizations", "story": "S1 S4", "kubun": "◎",
+        }
+        findings = [
+            {"finding_id": "F-1", "run_id": self.run_id, "story_id": "S4",
+             "capability_tag": "ORG-04", "species_key": "x", "severity": "high"},
+        ]
+        corr = C.correlate(self.routes, operations, self._executed([]), findings, self.tb,
+                           run_id=self.run_id)
+        row = next(r for r in corr.rows if r.route_name == "organizations.store")
+        self.assertEqual(1, row.finding_count)
+        self.assertTrue(row.via_capability)
+        # 単一値の S4 機構にも同じ finding が届く (従来の挙動が変わっていない)。
+        transfer = next(r for r in corr.rows if r.route_name == "organizations.transfer")
+        self.assertEqual(1, transfer.finding_count)
+        # S1 の finding も複数値行へ届く。
+        s1 = [{"finding_id": "F-2", "run_id": self.run_id, "story_id": "S1",
+               "capability_tag": "AUTH-03", "species_key": "y", "severity": "low"}]
+        corr = C.correlate(self.routes, operations, self._executed([]), s1, self.tb,
+                           run_id=self.run_id)
+        row = next(r for r in corr.rows if r.route_name == "organizations.store")
+        self.assertEqual(1, row.finding_count)
+
+    def test_契約外の割当セルを持つ目録は走行を止める(self):
+        operations = dict(self.operations)
+        operations["login.store"] = {"operation": "login", "story": "S1  S4", "kubun": "◎"}
+        with self.assertRaises(C.FatalError):
+            C.correlate(self.routes, operations, self._executed([]), [], self.tb,
+                        run_id=self.run_id)
+
     def test_cross_unexec_findingful(self):
         # 未実行 ∧ finding≥2 の積集合
         findings = [
@@ -698,5 +730,39 @@ class RenderWorklistTest(unittest.TestCase):
         self.assertNotIn("未実行 candidate", out)
 
 
+class StoryCellParseTest(unittest.TestCase):
+    """割当セルの分解 (目録が複数値セルを書けるようになったことへの追従)。
+
+    実在 (そのカードが在るか) は見ない。目録は生成物であり、割当列は実在するカードの
+    前付けからしか作られない (生成器側の検査が担う)。
+    """
+
+    def test_単一値は従来どおり(self):
+        self.assertEqual(["S3"], C.parse_story_cell("S3", "r"))
+
+    def test_複数値は全部に索引される(self):
+        self.assertEqual(["S3", "S7"], C.parse_story_cell("S3 S7", "r"))
+
+    def test_対象外はどのstoryにも索引されない(self):
+        self.assertEqual([], C.parse_story_cell("-", "r"))
+
+    def test_実在しないカードでも通す(self):
+        # 責務外 (生成器側が出さないことを test_bug_hunt_inventory.py が固定する)。
+        self.assertEqual(["S8"], C.parse_story_cell("S8", "r"))
+
+    def test_契約外のセルは致命(self):
+        # **寛容に正規化しない**。str.split() は前後空白も連続空白も黙って吸収する。
+        for cell in (" S3", "S3 ", "S3  S7", "", "SX", "S0", "S03", "s3", "S3,S7", "S3 S7 "):
+            with self.subTest(cell=cell):
+                with self.assertRaises(C.FatalError):
+                    C.parse_story_cell(cell, "r")
+
+    def test_降順と重複は致命(self):
+        for cell in ("S7 S3", "S3 S3"):
+            with self.subTest(cell=cell):
+                with self.assertRaises(C.FatalError):
+                    C.parse_story_cell(cell, "r")
+
+
 if __name__ == "__main__":
     unittest.main()
diff --git a/.claude/skills/app-bug-hunt/inventory/annotations.toml b/.claude/skills/app-bug-hunt/inventory/annotations.toml
index ba4e1e64..98138f66 100644
--- a/.claude/skills/app-bug-hunt/inventory/annotations.toml
+++ b/.claude/skills/app-bug-hunt/inventory/annotations.toml
@@ -4,133 +4,113 @@
 # メソッド / 画面題名) は生成器が入れるので、ここには**実装から導けない意味だけ**を書く。
 #
 #   kind   画面表の route で必須 (画面 / JSON)。操作表の route には書けない
-#   story  区分が 通常 / 逸 のとき必須 (S1..S7)。区分が 外 / 終 には書けない
 #   kubun  常に必須 (通常 / 逸 / 終 / 外)
 #   reason 区分が 外 / 終 のとき必須・30 文字以上。それ以外には書けない
 #
-# 許すのはこの 4 項目だけで、未知の項目・未知の語彙・定義域のずれは
+# 許すのはこの 3 項目だけで、未知の項目・未知の語彙・定義域のずれは
 # `scripts/bug-hunt-inventory-check.sh` が exit 3 (ドリフト) で落とす。
+#
+# ★ **割当 (どのカードが消化するか) はここには書かない**。正本はシナリオカードの前付け
+#   (`../stories/S*.md` の covers_screens / covers_operations) である。目録の割当列は
+#   そこから逆引き生成される。ここに書くと二重の正本になるので、`story` は未知の項目として
+#   exit 3 (ドリフト) で落ちる。
+#
+# ★ 区分 **終** は**対象内**である (対象外は **外** だけ)。`終` の route も
+#   1 枚以上のカードの covers_* に載せること (載せる先が無いなら区分を `外` にする)。
 schema_version = 1
 
 [routes."billing.auto-recharge.setup"]
-story = "S5"
 kubun = "通常"
 
 [routes."billing.auto-recharge.update"]
-story = "S5"
 kubun = "通常"
 
 [routes."billing.checkout"]
-story = "S5"
 kubun = "通常"
 
 [routes."billing.contact.update"]
-story = "S5"
 kubun = "通常"
 
 [routes."billing.index"]
 kind = "画面"
-story = "S5"
 kubun = "通常"
 
 [routes."billing.plan.change"]
-story = "S5"
 kubun = "通常"
 
 [routes."billing.plans"]
 kind = "画面"
-story = "S5"
 kubun = "通常"
 
 [routes."billing.portal"]
-story = "S5"
 kubun = "通常"
 
 [routes."billing.tickets.checkout"]
-story = "S5"
 kubun = "通常"
 
 [routes."billing.tickets.show"]
 kind = "画面"
-story = "S5"
 kubun = "通常"
 
 [routes."capture.account"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."capture.csrf-cookie"]
 kind = "JSON"
-story = "S3"
 kubun = "通常"
 
 [routes."capture.home"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."capture.manuals.index"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."capture.manuals.show"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."capture.takes.adopt"]
-story = "S3"
 kubun = "通常"
 
 [routes."capture.takes.destroy"]
-story = "S3"
 kubun = "通常"
 
 [routes."capture.takes.downloaded"]
-story = "S3"
 kubun = "通常"
 
 [routes."capture.takes.playback"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."capture.takes.store"]
-story = "S3"
 kubun = "通常"
 
 [routes."capture.takes.thumbnail"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."capture.takes.update"]
-story = "S3"
 kubun = "通常"
 
 [routes."capture.takes.upload-url"]
-story = "S3"
 kubun = "通常"
 
 [routes."contact"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."contact.store"]
-story = "S1"
 kubun = "通常"
 
 [routes."contact.thanks"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."dashboard"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."debug.bfcache-trial"]
@@ -149,211 +129,164 @@ kubun = "外"
 reason = "開発環境専用のログイン補助画面であり探索は POST の debug.login-as で前提を組むため分母に載せない"
 
 [routes."debug.login-as"]
-story = "S1"
 kubun = "通常"
 
 [routes."home"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."invitations.accept"]
 kind = "画面"
-story = "S2"
 kubun = "通常"
 
 [routes."invitations.accept-in-app"]
-story = "S2"
 kubun = "通常"
 
 [routes."invitations.accept.store"]
-story = "S2"
 kubun = "通常"
 
 [routes."legal.commerce-disclosure"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."legal.privacy"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."legal.terms"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."login"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."login.store"]
-story = "S1"
 kubun = "通常"
 
 [routes."logout"]
-story = "S1"
 kubun = "通常"
 
 [routes."manage.users.index"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."notifications.index"]
 kind = "画面"
-story = "S6"
 kubun = "通常"
 
 [routes."notifications.open"]
-story = "S6"
 kubun = "通常"
 
 [routes."notifications.read"]
-story = "S6"
 kubun = "通常"
 
 [routes."notifications.read-all"]
-story = "S6"
 kubun = "通常"
 
 [routes."onboarding.activate-personal"]
-story = "S1"
 kubun = "通常"
 
 [routes."onboarding.billing-required"]
 kind = "画面"
-story = "S2"
 kubun = "通常"
 
 [routes."onboarding.checkout"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."organizations.api-keys.index"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.api-keys.revoke"]
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.api-keys.sessions.index"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.api-keys.sessions.revoke"]
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.api-keys.store"]
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.create"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.invitations.revoke"]
-story = "S2"
 kubun = "通常"
 
 [routes."organizations.invitations.store"]
-story = "S2"
 kubun = "通常"
 
 [routes."organizations.members.destroy"]
-story = "S2"
 kubun = "通常"
 
 [routes."organizations.members.two-factor.reset"]
-story = "S2"
 kubun = "通常"
 
 [routes."organizations.members.update"]
-story = "S2"
 kubun = "通常"
 
 [routes."organizations.onboarding.cli"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.onboarding.mcp"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.settings"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.store"]
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.switch"]
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.transfer-ownership"]
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.two-factor-requirement.update"]
-story = "S4"
 kubun = "通常"
 
 [routes."organizations.update"]
-story = "S4"
 kubun = "通常"
 
 [routes."passkey.confirm"]
-story = "S6"
 kubun = "通常"
 
 [routes."passkey.confirm-options"]
 kind = "JSON"
-story = "S6"
 kubun = "通常"
 
 [routes."passkey.destroy"]
-story = "S6"
 kubun = "通常"
 
 [routes."passkey.login"]
-story = "S1"
 kubun = "通常"
 
 [routes."passkey.login-options"]
 kind = "JSON"
-story = "S1"
 kubun = "通常"
 
 [routes."passkey.registration-options"]
 kind = "JSON"
-story = "S6"
 kubun = "通常"
 
 [routes."passkey.store"]
-story = "S6"
 kubun = "通常"
 
 [routes."password.confirm"]
 kind = "画面"
-story = "S6"
 kubun = "通常"
 
 [routes."password.confirm.store"]
-story = "S6"
 kubun = "通常"
 
 [routes."password.confirmation"]
@@ -362,198 +295,154 @@ kubun = "外"
 reason = "再認証が有効かどうかだけを返す状態問い合わせであり画面として開く経路ではないため分母に載せない"
 
 [routes."password.email"]
-story = "S1"
 kubun = "通常"
 
 [routes."password.request"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."password.reset"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."password.update"]
-story = "S1"
 kubun = "通常"
 
 [routes."pricing"]
 kind = "画面"
-story = "S5"
 kubun = "通常"
 
 [routes."projects.categories.destroy"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.categories.index"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."projects.categories.reorder"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.categories.store"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.categories.update"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.create"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."projects.destroy"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.edit"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."projects.index"]
 kind = "画面"
-story = "S4"
 kubun = "通常"
 
 [routes."projects.items.destroy"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.items.store"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.items.update"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.manuals.analyze"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.create"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.cuts.takes.index"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.destroy"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.download"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.duplicate"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.edit"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.jobs.show"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.preview"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.render"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.render-jobs.playback"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.render-jobs.show"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.scenario.update"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.show"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.source-documents.store"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.store"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.manuals.update"]
-story = "S3"
 kubun = "通常"
 
 [routes."projects.members.destroy"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.members.store"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.show"]
 kind = "画面"
-story = "S3"
 kubun = "通常"
 
 [routes."projects.store"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.update"]
-story = "S4"
 kubun = "通常"
 
 [routes."recent-auth.confirm"]
 kind = "画面"
-story = "S6"
 kubun = "通常"
 
 [routes."recent-auth.password"]
-story = "S6"
 kubun = "通常"
 
 [routes."recent-auth.status"]
 kind = "画面"
-story = "S6"
 kubun = "通常"
 
 [routes."register"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."register.store"]
-story = "S1"
 kubun = "通常"
 
 [routes."seo.ai"]
@@ -578,33 +467,26 @@ reason = "クローラ向けの機械可読 route であり人が操作する画
 
 [routes."session.status"]
 kind = "JSON"
-story = "S6"
 kubun = "通常"
 
 [routes."settings"]
 kind = "画面"
-story = "S6"
 kubun = "通常"
 
 [routes."settings.account.deletion-request.destroy"]
-story = "S6"
 kubun = "通常"
 
 [routes."settings.account.deletion-request.store"]
-story = "S6"
 kubun = "通常"
 
 [routes."settings.account.destroy"]
-story = "S6"
 kubun = "通常"
 
 [routes."settings.password.store"]
-story = "S6"
 kubun = "通常"
 
 [routes."settings.security"]
 kind = "画面"
-story = "S6"
 kubun = "通常"
 
 [routes."social.callback"]
@@ -618,24 +500,19 @@ kubun = "外"
 reason = "外部の識別提供者へ出ていく遷移であり隔離した探索環境の外へ出てしまうため分母に載せない"
 
 [routes."two-factor.confirm"]
-story = "S6"
 kubun = "通常"
 
 [routes."two-factor.disable"]
-story = "S6"
 kubun = "通常"
 
 [routes."two-factor.enable"]
-story = "S6"
 kubun = "通常"
 
 [routes."two-factor.login"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."two-factor.login.store"]
-story = "S1"
 kubun = "通常"
 
 [routes."two-factor.qr-code"]
@@ -649,7 +526,6 @@ kubun = "外"
 reason = "復旧コードを返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない"
 
 [routes."two-factor.regenerate-recovery-codes"]
-story = "S6"
 kubun = "通常"
 
 [routes."two-factor.secret-key"]
@@ -658,25 +534,20 @@ kubun = "外"
 reason = "第二要素の秘密そのものを返す開示 endpoint であり単独で開くと秘密が走行記録に残るため分母に載せない"
 
 [routes."user-password.update"]
-story = "S6"
 kubun = "通常"
 
 [routes."user-profile-information.update"]
-story = "S6"
 kubun = "通常"
 
 [routes."verification.notice"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."verification.send"]
-story = "S1"
 kubun = "通常"
 
 [routes."verification.verify"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."webhooks.ses"]
diff --git a/.claude/skills/app-bug-hunt/operations.md b/.claude/skills/app-bug-hunt/operations.md
index 3ef45657..8f43e628 100644
--- a/.claude/skills/app-bug-hunt/operations.md
+++ b/.claude/skills/app-bug-hunt/operations.md
@@ -1,8 +1,10 @@
 # 操作インベントリ (operations.md) — AI-CUE
 
 > **このファイルは生成物である。手で編集しない。**
-> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か
-> `inventory/notes-*.md` (散文) を直してから `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
+> 直し方: 割当ストーリー列は `.claude/skills/app-bug-hunt/stories/S*.md` の前付け
+> (`covers_screens` / `covers_operations`) を、区分・理由・種別は
+> `inventory/annotations.toml` を、散文は `inventory/notes-*.md` を直してから
+> `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
 > 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
 > ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。
 
@@ -19,8 +21,8 @@ ## 操作一覧 (web セッション面)
 | POST | billing/plan | billing.plan.change | S5 | 通常 |
 | POST | billing/portal | billing.portal | S5 | 通常 |
 | POST | purchase-tickets/checkout | billing.tickets.checkout | S5 | 通常 |
-| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt | capture.takes.adopt | S3 | 通常 |
-| DELETE | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.destroy | S3 | 通常 |
+| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt | capture.takes.adopt | S3 S7 | 通常 |
+| DELETE | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.destroy | S3 S7 | 通常 |
 | POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded | capture.takes.downloaded | S3 | 通常 |
 | POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes | capture.takes.store | S3 | 通常 |
 | PATCH | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.update | S3 | 通常 |
@@ -55,23 +57,23 @@ ## 操作一覧 (web セッション面)
 | POST | user/confirm-password | password.confirm.store | S6 | 通常 |
 | POST | forgot-password | password.email | S1 | 通常 |
 | POST | reset-password | password.update | S1 | 通常 |
-| DELETE | projects/{project}/categories/{category} | projects.categories.destroy | S4 | 通常 |
-| PATCH | projects/{project}/categories/reorder | projects.categories.reorder | S4 | 通常 |
+| DELETE | projects/{project}/categories/{category} | projects.categories.destroy | S4 S7 | 通常 |
+| PATCH | projects/{project}/categories/reorder | projects.categories.reorder | S4 S7 | 通常 |
 | POST | projects/{project}/categories | projects.categories.store | S4 | 通常 |
-| PATCH | projects/{project}/categories/{category} | projects.categories.update | S4 | 通常 |
+| PATCH | projects/{project}/categories/{category} | projects.categories.update | S4 S7 | 通常 |
 | DELETE | projects/{project} | projects.destroy | S4 | 通常 |
 | DELETE | projects/{project}/items/{item} | projects.items.destroy | S4 | 通常 |
 | POST | projects/{project}/items | projects.items.store | S4 | 通常 |
 | PATCH | projects/{project}/items/{item} | projects.items.update | S4 | 通常 |
 | POST | projects/{project}/manuals/{manual}/analyze | projects.manuals.analyze | S3 | 通常 |
-| DELETE | projects/{project}/manuals/{manual} | projects.manuals.destroy | S3 | 通常 |
-| POST | projects/{project}/manuals/{manual}/duplicate | projects.manuals.duplicate | S3 | 通常 |
+| DELETE | projects/{project}/manuals/{manual} | projects.manuals.destroy | S3 S7 | 通常 |
+| POST | projects/{project}/manuals/{manual}/duplicate | projects.manuals.duplicate | S3 S7 | 通常 |
 | POST | projects/{project}/manuals/{manual}/preview | projects.manuals.preview | S3 | 通常 |
 | POST | projects/{project}/manuals/{manual}/render | projects.manuals.render | S3 | 通常 |
-| PUT | projects/{project}/manuals/{manual}/scenario | projects.manuals.scenario.update | S3 | 通常 |
+| PUT | projects/{project}/manuals/{manual}/scenario | projects.manuals.scenario.update | S3 S7 | 通常 |
 | POST | projects/{project}/manuals/{manual}/source-documents | projects.manuals.source-documents.store | S3 | 通常 |
 | POST | projects/{project}/manuals | projects.manuals.store | S3 | 通常 |
-| PATCH | projects/{project}/manuals/{manual} | projects.manuals.update | S3 | 通常 |
+| PATCH | projects/{project}/manuals/{manual} | projects.manuals.update | S3 S7 | 通常 |
 | DELETE | projects/{project}/members/{user} | projects.members.destroy | S4 | 通常 |
 | POST | projects/{project}/members | projects.members.store | S4 | 通常 |
 | POST | projects | projects.store | S4 | 通常 |
diff --git a/.claude/skills/app-bug-hunt/screens.md b/.claude/skills/app-bug-hunt/screens.md
index 63609c1b..38c98022 100644
--- a/.claude/skills/app-bug-hunt/screens.md
+++ b/.claude/skills/app-bug-hunt/screens.md
@@ -1,8 +1,10 @@
 # 画面インベントリ (screens.md) — AI-CUE
 
 > **このファイルは生成物である。手で編集しない。**
-> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か
-> `inventory/notes-*.md` (散文) を直してから `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
+> 直し方: 割当ストーリー列は `.claude/skills/app-bug-hunt/stories/S*.md` の前付け
+> (`covers_screens` / `covers_operations`) を、区分・理由・種別は
+> `inventory/annotations.toml` を、散文は `inventory/notes-*.md` を直してから
+> `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
 > 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
 > ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。
 
@@ -19,8 +21,8 @@ ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 | app/csrf-cookie | capture.csrf-cookie | JSON | - | S3 | 通常 |
 | app | capture.home | 画面 | - | S3 | 通常 |
 | app/projects/{project}/manuals | capture.manuals.index | 画面 | 撮影するマニュアルを選ぶ | S3 | 通常 |
-| app/projects/{project}/manuals/{manual} | capture.manuals.show | 画面 | - | S3 | 通常 |
-| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | 画面 | - | S3 | 通常 |
+| app/projects/{project}/manuals/{manual} | capture.manuals.show | 画面 | - | S3 S7 | 通常 |
+| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | 画面 | - | S3 S7 | 通常 |
 | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail | capture.takes.thumbnail | 画面 | - | S3 | 通常 |
 | contact | contact | 画面 | お問い合わせ | S1 | 通常 |
 | contact/thanks | contact.thanks | 画面 | お問い合わせ完了 | S1 | 通常 |
@@ -52,19 +54,19 @@ ## GET × web 一覧 (画面 + 画面に付随する JSON GET)
 | forgot-password | password.request | 画面 | パスワードリセット | S1 | 通常 |
 | reset-password/{token} | password.reset | 画面 | パスワードリセット | S1 | 通常 |
 | pricing | pricing | 画面 | - | S5 | 通常 |
-| projects/{project}/categories | projects.categories.index | 画面 | カテゴリ管理 | S4 | 通常 |
+| projects/{project}/categories | projects.categories.index | 画面 | カテゴリ管理 | S4 S7 | 通常 |
 | projects/create | projects.create | 画面 | プロジェクトの作成 | S4 | 通常 |
-| projects/{project}/edit | projects.edit | 画面 | プロジェクトの編集 | S4 | 通常 |
+| projects/{project}/edit | projects.edit | 画面 | プロジェクトの編集 | S4 S7 | 通常 |
 | projects | projects.index | 画面 | プロジェクト | S4 | 通常 |
 | projects/{project}/manuals/create | projects.manuals.create | 画面 | 動画マニュアルの作成 | S3 | 通常 |
 | projects/{project}/manuals/{manual}/cuts/{cut}/takes | projects.manuals.cuts.takes.index | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/download | projects.manuals.download | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/edit | projects.manuals.edit | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/jobs/{analysisJob} | projects.manuals.jobs.show | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback | projects.manuals.render-jobs.playback | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual}/render-jobs/{renderJob} | projects.manuals.render-jobs.show | 画面 | - | S3 | 通常 |
-| projects/{project}/manuals/{manual} | projects.manuals.show | 画面 | - | S3 | 通常 |
-| projects/{project} | projects.show | 画面 | - | S3 | 通常 |
+| projects/{project}/manuals/{manual}/download | projects.manuals.download | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual}/edit | projects.manuals.edit | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual}/jobs/{analysisJob} | projects.manuals.jobs.show | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback | projects.manuals.render-jobs.playback | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual}/render-jobs/{renderJob} | projects.manuals.render-jobs.show | 画面 | - | S3 S7 | 通常 |
+| projects/{project}/manuals/{manual} | projects.manuals.show | 画面 | - | S3 S7 | 通常 |
+| projects/{project} | projects.show | 画面 | - | S3 S7 | 通常 |
 | recent-auth/confirm | recent-auth.confirm | 画面 | 本人確認 | S6 | 通常 |
 | recent-auth/status | recent-auth.status | 画面 | - | S6 | 通常 |
 | register | register | 画面 | アカウント登録 | S1 | 通常 |
@@ -141,9 +143,7 @@ ## 課金ゲート着地 (P4 ゲート反転) の画面遷移
 > §サブスク契約 Checkout とオンボーディング着地)。
 
 - `onboarding.checkout` は**離脱ガード付き**: 契約済み (有効 sub / free personal) は
-  `manageBilling` 保持者 → `billing.index` / 非保持メンバー → `dashboard` へ寄せる
-  (非保持メンバーに操作できない請求画面を見せず業務入口へ着地させる。Q-2-01)。
-  未契約で `manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
+  `billing.index` へ、`manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
 - `onboarding.billing-required` も同様に、利用可なら `dashboard`、`manageBilling` 保持者なら
   `onboarding.checkout` へ逃がす。**どちらの画面も「行き先のない詰み」を作らないこと**が契約で、
   ここでループ・403・空画面が出たら finding (H4/H10)。
diff --git a/.claude/skills/app-bug-hunt/stories/README.md b/.claude/skills/app-bug-hunt/stories/README.md
index 730d570b..7b317379 100644
--- a/.claude/skills/app-bug-hunt/stories/README.md
+++ b/.claude/skills/app-bug-hunt/stories/README.md
@@ -1,44 +1,196 @@
-# ストーリーカード (stories/) — スケルトン
+# シナリオカード (stories/) — 書式の正本
 
-> **これはテンプレート同梱のスケルトンである。** 各カードはユーザーが実際に辿るジャーニーを 1 本ずつ記述する。
-> bug-hunt はこのカードを 1 枚ずつ実走する。テンプレートは共通コア (認証・組織/プロジェクト・招待・課金・
-> 2FA・認可境界) の骨子だけを置く。**アプリ固有のジャーニー (ドメイン中核フロー) は S3 を中心に肉付けし、
-> 画面/操作を screens.md / operations.md と対応させること。**
+bug-hunt はここに置いたカードを 1 枚ずつ実走する。カードは「利用者が実際に辿るジャーニー」を
+1 本ずつ記述したもので、**どの画面・操作・機能を消化するか (割当) の正本もカードの前付け**である。
 
-## カードのフォーマット
+> **割当を `inventory/annotations.toml` に書かない。** 注釈が持つのは route ごとの意味
+> (`kind` / `kubun` / `reason`) だけで、割当は本ファイルの規約に従ってカードの前付けに書く。
+> 目録 (`screens.md` / `operations.md`) の「割当ストーリー」列は前付けから逆引き生成される。
+>
+> 機械検査は `stories/test_story_front_matter.py` (前付けの契約) と
+> `scripts/bug-hunt-inventory.py` (割当と目録の突合) の 2 本が分担する。前者は
+> `tests/Architecture/BughuntStoryToolSelfTest.php` が `composer test` の配線に載せる。
+
+## 1. 前付けの制限文法
+
+カードの先頭に前付けを置く。YAML に見えるが**読むのは下記の制限文法だけ**であり、
+汎用 YAML パーサは使わない (読み取り器は `story_front_matter.py`)。
+
+- **A1** 1 行目が厳密に `---`。次に現れる「行頭から `---` だけ」の行で閉じる
+  (本文中の水平線・表の区切り行に影響されない)
+- **A2** 1 行 1 項目。`key: value` (半角コロン + 半角空白 1 つ) だけを認める
+- **A3** key は `^[a-z][a-z0-9_]*$`。**重複 key は fail**
+- **A4** 値は 3 形のみ
+  - **素のスカラー** — 前後空白なし・**引用符禁止**・`#` `:` 角括弧を含めない
+  - **真偽値** — `true` / `false` のリテラルのみ
+  - **配列** — `[]` または `[a, b, c]` (要素は `, ` 区切り。ネスト不可・引用符禁止)
+- **A5** コメント行・空行・複数行スカラー・アンカー・参照・ネストマップは書けない
+- **A6** key の並び順が下記の正準順序と一致する
+
+## 2. 前付けの項目定義 (必須 13 key + 条件付き 1 key)
+
+正準順序はこの表の並びである。
+
+| # | key | 値 | 説明 |
+|---|---|---|---|
+| 1 | `id` | スカラー `^S[1-9][0-9]*$` | カード番号 (ゼロ埋め禁止)。**一意** |
+| 2 | `title` | スカラー (非空) | H1 見出し `# {id}: {title}` と機械一致させる |
+| 3 | `surface` | スカラー | 対象面。**表 A に実在する語だけ** (未登録は fail) |
+| 4 | `lane` | `parallel_browser` / `serial_parent` | 実行方式 |
+| 5 | `priority` | `P1` / `P2` / `P3` | 落ちたときに走行全体が無意味になるかで決める |
+| 6 | `applicability` | `applicable` / `not_applicable` | 本アプリに該当する面か |
+| 7 | `not_applicable_reason` | スカラー (非空) | **`not_applicable` のときだけ**この位置に置く。`applicable` にあれば fail |
+| 8 | `depends_on` | 配列 (他カードの `id`) | 先に走らせる必要があるカード。無ければ `[]` |
+| 9 | `reseed_before` | 真偽値 | 開始前に初期データへ戻すか |
+| 10 | `accounts` | 配列 (下記のトークン語彙) | 使用アカウント |
+| 11 | `setup` | 配列 (一行の準備事項) | 無ければ `[]` |
+| 12 | `covers_screens` | 配列 (route 名) | 消化する画面 (safe method の web route) |
+| 13 | `covers_operations` | 配列 (route 名) | 消化する操作 (非 safe method の web route) |
+| 14 | `covers_capabilities` | 配列 `^[A-Z]+-[0-9]{2}$` | 消化する capability (`capability-catalog.md` の id) |
+
+**`covers_*` の値の実在は本ファイル側の検査では見ない** (見るのは形だけである)。
+実在の突合は目録側 (`scripts/bug-hunt-inventory.py`) の責務で、同じ規則を 2 か所に持たない。
+
+## 3. `covers_*` の 3 欄に何を書くか
+
+| 欄 | 母集合 | 検査 |
+|---|---|---|
+| `covers_screens` | **safe method (GET / HEAD / OPTIONS) の web route** | 実在 / 欄の意味 / 対象外でないこと / 分母の被覆 |
+| `covers_operations` | **非 safe method の web route** | 同上 |
+| `covers_capabilities` | `capability-catalog.md` の `capability_id 索引` の id | **実在・形・一意まで** (分母・被覆は見ない) |
+
+- **対象内 (`kubun` が `外` でない) の web route は、1 枚以上の `applicable` なカードに載ること。**
+  **区分 `終` は対象内**である (`外` だけが対象外)。載せる先が無い route は、注釈の区分を
+  `外` にして理由を書くこと (目録に見える形で宣言する)。
+- 1 つの route を複数のカードが挙げてよい (別視点で踏むのは正常)。**1 枚のカードの配列の中で
+  同じ値を 2 回書くことはできない**。
+- 対象面 (`surface`) が `admin_console` / `cli_or_api` の語彙は**予約**である。分母は
+  ブラウザ (web 面) に閉じているので、該当するカードは今は無い。
+
+## 4. 表 A: 対象面 (surface) の語彙
+
+家系必須の 11 語は**削除・改名しない** (追記は自由)。
+
+<!-- STORY-SURFACE-VOCABULARY:BEGIN -->
+
+| surface | 面 | 由来 |
+|---|---|---|
+| `signup_funnel` | 登録・ログインファネル | テンプレート同梱 |
+| `invitation` | 招待フロー | テンプレート同梱 |
+| `core_journey` | アプリ中核ジャーニー (AI-CUE = SOP からマニュアル動画まで) | テンプレート同梱 |
+| `org_project_admin` | 組織・プロジェクト管理 | テンプレート同梱 |
+| `billing` | 課金 | テンプレート同梱 |
+| `account_security` | セキュリティ (2FA / プロフィール) | テンプレート同梱 |
+| `authz_boundary` | 認可境界 (IDOR) | テンプレート同梱 |
+| `result_view` | 結果・レポートの閲覧 | 予約 |
+| `admin_console` | 管理画面 | 予約 |
+| `cli_or_api` | CLI / REST 面 | 予約 |
+| `public_share` | 未認証で到達する共有リンク面 | 予約 |
+
+<!-- STORY-SURFACE-VOCABULARY:END -->
+
+## 5. 表 B: カード目録
+
+実在するカードと 1 対 1 にする。`lane` / `priority` / `depends_on` の写しは**置かない**
+(第二の正本を作らないため。正本はカードの前付けである)。
+
+<!-- STORY-CARD-INVENTORY:BEGIN -->
+
+| id | surface |
+|---|---|
+| S1 | `signup_funnel` |
+| S2 | `invitation` |
+| S3 | `core_journey` |
+| S4 | `org_project_admin` |
+| S5 | `billing` |
+| S6 | `account_security` |
+| S7 | `authz_boundary` |
+
+<!-- STORY-CARD-INVENTORY:END -->
+
+## 6. 番号規約と S8 以降の識別規約
+
+- **D1** 番号は識別子であって意味を持たない。家系間の対応は `surface` で取る
+- **D2** 既存番号の面を付け替えない (S1〜S7 の `(id, surface)` は家系で固定)
+- **D3** `id` は一意
+- **D4** 欠番を作らない。`S1` から最大番号まで連番。該当面が無くてもカードを消さず
+  `applicability: not_applicable` で残す
+- **D5** ファイル名は `S{n}-{任意の kebab}.md`。機械一致するのは**先頭セグメント `S{n}`** だけ
+- **D7** S8 以降は番号でなく**対象面**で識別する。足すときは 3 か所を同じ変更で直す —
+  表 A に面を 1 行 / 表 B に 1 行 / カードを 1 枚
+
+## 7. 使用アカウントのトークン (`accounts`)
+
+`guest` / `owner` / `admin` / `member` / `platform_admin` の 5 語だけ。**語彙を拡張しない**
+(増やすと家系間の突合が緩む)。AI-CUE の ProjectRole (編集者 / 撮影者) のような
+アプリ固有の役割は、トークンではなく**本文の散文**で表す。
+
+> `accounts` と `database/seeders/ManualTestSeeder.php` の一致は機械検査しない
+> (家系の正典も同じ。PR レビューの義務である)。
+
+## 8. カード本文の確定形
+
+前付けを閉じたあとの本文は次の形にする。
 
 ```markdown
-# S{n}: {ジャーニー名}
+# {id}: {title}
 
-- 前提状態: (どのアカウント・どの初期データから始めるか。reseed 要否)
-- 目的: (このジャーニーでユーザーが達成したいこと)
+## 目的
+(このジャーニーで利用者が達成したいこと。散文)
 
 ## 手順
 1. (操作) → (期待)
-2. ...
-
-## このストーリーで消化する screens / operations
-- screens: (screens.md の該当行)
-- operations: (operations.md の該当行)
 
 ## 逸脱アイデア (--deviate 時)
 - (IDOR 探索・二重送信・戻る/リロード・隣接 ID 書き換え 等)
 ```
 
-## 並列 fan-out マップ (scripts/bug-hunt-shard.sh の stories_for_shard)
+> 見出しの直後に空行を置くかは契約ではない (節の中身が空でなければよい)。
+> ただし `## 手順` 節の中身は移行で 1 バイトも変えていないので、既存カードの形を保つこと。
+
+- **J1** H1 見出しは `# {id}: {title}` に固定し、前付けと機械一致させる
+- **J2** `## 目的` をちょうど 1 個持ち、節の中身が空でない
+- **J3** `## 逸脱アイデア (--deviate 時)` をちょうど 1 個持ち、節の中身が空でない
+- **H1** 旧メタ節 (`- 前提状態:` / `- 目的:` の箇条 /
+  `## このストーリーで消化する screens / operations`) を**残さない**。同じ事実が前付けと
+  散文の 2 か所に並ぶと、カード 1 枚の中に二重の正本ができる
+- **F2** `applicability: not_applicable` のカードは `## 手順` 節を持たない
+- **G6** 兆候番号 (`H{n}`) の**意味**はカードに書かない (語彙の正本は `SKILL.md` の
+  横断ヒューリスティクス表)。カードは `H4` のような参照だけを持つ
+
+## 9. 実行方式・依存・初期化要否の正本
 
-固定マップは S3↔S7 の状態依存を shard-1 に閉じ込める。cap=4、`--parallel` は 2/4。
-S1..S7 は browser story。CLI/REST 面・管理画面など特殊 guard を要する面は subagent fan-out に含めず親が直列追走する
-(アプリが追加する場合は S8 以降として本 README とカードに記述する)。
+- **E4** `lane` / `depends_on` / `reseed_before` の**正本はカードの前付け**である。
+  本ファイルは写しを持たない
+- **E1** `depends_on` の参照は実在し、自己参照でなく、循環しない
+- **E2** `depends_on` を持つなら `reseed_before` は `false` (片方向のみ)
+- **E3** `parallel_browser` のカードは `serial_parent` のカードに依存しない
+- **E5** `scripts/bug-hunt-shard.sh` の固定 fan-out マップは**前付けからの派生キャッシュ**である。
+  両者の一致は**機械検査しない** (家系の正典も未達)。カードの `lane` / `depends_on` を
+  変えたら固定マップを手で追随させること
 
-## テンプレート初期カード (共通コアの骨子)
+## 10. 本アプリが正典から外している契約
 
-| カード | 面 | 概要 (アプリが肉付け) |
+家系の正典が持つ契約のうち、本アプリが**採らない**ものを明示する
+(逸脱の登録は `docs/template-divergence.md` **D40**)。
+
+| 外している契約 | 理由 | 再判定の条件 |
 |---|---|---|
-| S1 | 登録/ログインファネル | ゲスト → 新規登録 → メール認証 → 初回ログイン |
-| S2 | 招待フロー | 組織オーナーがメンバーを招待 → 招待受諾 (別 cookie) |
-| S3 | **アプリ中核ジャーニー** | ドメインの主要価値フロー (アプリが定義。最重要) |
-| S4 | 組織・プロジェクト管理 | 組織/プロジェクトの作成・編集・切替・削除 |
-| S5 | 課金 | プラン選択 → checkout → サブスク状態確認 (Stripe fake) |
-| S6 | セキュリティ (2FA/プロフィール) | 2FA 有効化・プロフィール編集・パスワード変更・セッション管理 |
-| S7 | 認可境界 (IDOR) | 組織 A/B を跨いだ read/write が弾かれるか (S3 後の状態を使う) |
+| ステップ表の書式 (正準 4 列ヘッダ `\| step \| 操作 \| 期待 \| 注目 \|` / 疎な step 識別子 `{id}-{3 桁}` / 副ブロック / 期待欄・注目欄の書き分け) | 所見台帳の finding は story までしか指さず **step を指す欄を持たない**ため、ステップ識別子を入れても読む機械が 1 つも無い。手順は散文の番号付きリストのまま置く | `ledger/findings.schema.json` に step を指す欄が入ったとき / 家系の正典が t2 以降でステップ表を版の名前に含めたとき |
+| `not_applicable` のカードを実走対象から外す契約 (`SKILL.md` 側) | 該当カードが **0 枚**であり、`SKILL.md` は採用時債務にあるため触らない | `applicability` に `not_applicable` を取るカードを 1 枚でも置くことになったとき |
+
+正典との差で**採る側**にしたものは次の 2 点である (いずれも既存 **D20** が説明する)。
+
+| 観点 | 家系の正典 | 本アプリ |
+|---|---|---|
+| `covers_screens` の母集合 | `kind` が `screen` / `read` / `redirect` の web route | **safe method の web route** (`kind` の語彙が `画面` / `JSON` で違うため、`kind` に依存させない) |
+| `covers_capabilities` の検査 | 実在 / 欄の意味 / 分母 / 被覆の 4 段 | **実在・形・一意まで** (機能カタログが継承宣言の欄 `no_route` / `coverage_surface` / `covered_via` を持たないため、分母・被覆は見ない) |
+
+## 11. 保証しないもの
+
+- **割当が痩せたこと**は検出できない。目録側が見るのは「1 枚以上のカードに載っていること」
+  だけなので、ある route が `S3 S7` から `S3` へ減っても緑のままである (PR レビューの義務)
+- `web` group を宣言していない面 (機械向け API / Filament 管理画面 / MCP / 現在の webhook の
+  大半) には沈黙する (既存 **D20** の保証境界)
+- カードの前付けと `scripts/bug-hunt-shard.sh` の固定マップの一致 (上記 E5)
+- `accounts` と seeder の一致 (上記 7 節)
diff --git a/.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md b/.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md
index 6f1f4d73..788b9053 100644
--- a/.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md
+++ b/.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md
@@ -1,7 +1,23 @@
+---
+id: S1
+title: 登録/ログインファネル
+surface: signup_funnel
+lane: parallel_browser
+priority: P1
+applicability: applicable
+depends_on: []
+reseed_before: true
+accounts: [guest]
+setup: []
+covers_screens: [contact, contact.thanks, dashboard, home, legal.commerce-disclosure, legal.privacy, legal.terms, login, onboarding.checkout, passkey.login-options, password.request, password.reset, register, two-factor.login, verification.notice, verification.verify]
+covers_operations: [contact.store, debug.login-as, login.store, logout, onboarding.activate-personal, passkey.login, password.email, password.update, register.store, two-factor.login.store, verification.send]
+covers_capabilities: [AUTH-01, AUTH-02, AUTH-03, AUTH-04, PLAT-02, PUB-01, PUB-02, QUO-01]
+---
+
 # S1: 登録/ログインファネル
 
-- 前提状態: 未ログイン(ゲスト)。reseed 済み。
-- 目的: ゲストがトップ/公開ページから新規登録 → メール認証 → 初回ログインまで詰まらず到達できるか。公開導線(料金・問い合わせ・法務)が破綻しないか。
+## 目的
+未ログインのゲストがトップ/公開ページから新規登録 → メール認証 → 初回ログインまで詰まらず到達できるか。公開導線(料金・問い合わせ・法務)が破綻しないか。
 
 ## 手順
 1. `home`(トップページ)を開く → プロダクト価値と CTA(登録/ログイン/料金)が見える。`pricing`/`contact`/`legal.privacy`/`legal.terms`/`legal.commerce-disclosure` へ遷移できる。
@@ -44,10 +60,6 @@ ## 手順
 7. パスワード忘れ: `password.request` → `password.email` → `password.reset` → `password.update` → 再ログイン。
 8. `logout` でログアウト。
 
-## このストーリーで消化する screens / operations
-- screens: home, register, login, dashboard, onboarding.checkout, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms, passkey.login-options
-- operations: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, debug.login-as, onboarding.activate-personal, passkey.login
-
 ## 逸脱アイデア (--deviate 時)
 - 認証前ページ(dashboard 等)へ直アクセス → login へ誘導されるか。認証後に login/register を開くと dashboard へ戻るか。
 - register/contact を二重送信 → 二重作成/二重送信されないか。
diff --git a/.claude/skills/app-bug-hunt/stories/S2-invitation-flow.md b/.claude/skills/app-bug-hunt/stories/S2-invitation-flow.md
index 9d5070f0..2365424d 100644
--- a/.claude/skills/app-bug-hunt/stories/S2-invitation-flow.md
+++ b/.claude/skills/app-bug-hunt/stories/S2-invitation-flow.md
@@ -1,7 +1,23 @@
+---
+id: S2
+title: 招待フロー(メンバー招待 → 受諾)
+surface: invitation
+lane: parallel_browser
+priority: P1
+applicability: applicable
+depends_on: []
+reseed_before: false
+accounts: [owner, member]
+setup: [招待先は別 cookie セッション (別ブラウザコンテキスト) で開く]
+covers_screens: [invitations.accept, onboarding.billing-required]
+covers_operations: [invitations.accept-in-app, invitations.accept.store, organizations.invitations.revoke, organizations.invitations.store, organizations.members.destroy, organizations.members.two-factor.reset, organizations.members.update]
+covers_capabilities: [MEM-01, MEM-02, MEM-03, MEM-04, MEM-05, MEM-06]
+---
+
 # S2: 招待フロー(メンバー招待 → 受諾)
 
-- 前提状態: 組織オーナー/管理者でログイン済み。招待先は別 cookie セッション(別ブラウザコンテキスト)。
-- 目的: オーナーがメンバー(編集者/撮影者ロール)を招待し、被招待者が受諾して組織に参加し、AI-CUE の役割(編集者=マニュアル編集・撮影者=撮影)に応じた権限で入れるか。
+## 目的
+組織オーナー/管理者がメンバー(編集者/撮影者ロール)を招待し、被招待者が受諾して組織に参加し、AI-CUE の役割(編集者=マニュアル編集・撮影者=撮影)に応じた権限で入れるか。
 
 ## 手順
 1. `organizations.invitations.store` でメールとロール(編集者/撮影者)を指定して招待 → 招待一覧に載る。
@@ -18,10 +34,6 @@ ## 手順
      `manageBilling` 保持者で直叩き → `onboarding.checkout` へ逃がされるか
      (2 画面を往復する無限リダイレクトにならないか。H10)。
 
-## このストーリーで消化する screens / operations
-- screens: invitations.accept, onboarding.billing-required
-- operations: invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke, organizations.members.update, organizations.members.destroy, organizations.members.two-factor.reset
-
 ## 逸脱アイデア (--deviate 時)
 - 取り消し済み/期限切れ/受諾済みの招待リンクを再利用 → 弾かれるか。
 - 別組織の招待トークンを自分のセッションで受諾 → 想定組織にのみ参加するか(トークン改竄)。
diff --git a/.claude/skills/app-bug-hunt/stories/S3-core-journey.md b/.claude/skills/app-bug-hunt/stories/S3-core-journey.md
index 17dc7d2a..5d32327a 100644
--- a/.claude/skills/app-bug-hunt/stories/S3-core-journey.md
+++ b/.claude/skills/app-bug-hunt/stories/S3-core-journey.md
@@ -1,8 +1,23 @@
+---
+id: S3
+title: アプリ中核ジャーニー — SOP から完成マニュアル動画まで
+surface: core_journey
+lane: parallel_browser
+priority: P1
+applicability: applicable
+depends_on: []
+reseed_before: true
+accounts: [admin]
+setup: [Default Project とチケット残高を用意する (不足なら S5 の手順でチャージする), real-llm 既定 (実 Anthropic 接続) + fake storage + ffmpeg 導入済みの環境で走らせる]
+covers_screens: [capture.account, capture.csrf-cookie, capture.home, capture.manuals.index, capture.manuals.show, capture.takes.playback, capture.takes.thumbnail, projects.manuals.create, projects.manuals.cuts.takes.index, projects.manuals.download, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.playback, projects.manuals.render-jobs.show, projects.manuals.show, projects.show]
+covers_operations: [capture.takes.adopt, capture.takes.destroy, capture.takes.downloaded, capture.takes.store, capture.takes.update, capture.takes.upload-url, projects.manuals.analyze, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.preview, projects.manuals.render, projects.manuals.scenario.update, projects.manuals.source-documents.store, projects.manuals.store, projects.manuals.update]
+covers_capabilities: [CAP-01, CAP-02, CAP-03, CAP-04, CAP-05, CAP-06, REN-01, REN-02, REN-03, REN-04, SCEN-01, SCEN-02, SCEN-03, SCEN-05, SOP-01, SOP-02, SOP-03, SOP-04, SOP-05]
+---
+
 # S3: アプリ中核ジャーニー — SOP から完成マニュアル動画まで
 
-- 前提状態: 編集者(project_admin)でログイン済み、Default Project あり、チケット残高あり(なければ S5 でチャージ)。reseed 推奨。
-- 目的: AI-CUE の North Star フロー全体が破綻なく通るか。手順書(SOP)を起点に AI がカット設計 → 撮影 → 完成動画まで、ユーザーが「次に何をすべきか」を見失わないか。
-- 環境: real-llm 既定(実 Anthropic 接続)+ fake storage(take upload はローカル emulate)+ ffmpeg 導入済み。中核チェーンはエンドツーエンドで通る前提。
+## 目的
+AI-CUE の North Star フロー全体が破綻なく通るか。手順書(SOP)を起点に AI がカット設計 → 撮影 → 完成動画まで、編集者(project_admin)が「次に何をすべきか」を見失わないか。中核チェーンはエンドツーエンドで通る前提とする。
 
 ## 手順
 1. `projects.show`(動画一覧)を開く → カテゴリ/状態/検索の絞り込みが効き、空状態でも「動画を追加」導線が見える。**並べ替え(更新日/タイトル × 昇順/降順)**・**「自分の作成分のみ」フィルタ**・行の**作成者/更新日メタ表示**が機能する(T053)。並べ替え/フィルタ切替が一覧に正しく反映されるか(H10)。
@@ -20,10 +35,6 @@ ## 手順
 8. `projects.manuals.preview`(チケット非消費)で確認 → `projects.manuals.render`(video_render チケット消費) → status=rendering → `projects.manuals.render-jobs.show` ポーリング → 完了で published。ffmpeg で実際に合成されるか。複数の失敗 alert(プレビュー失敗/採用テイク未設定/レンダ失敗)が**帰属明示**されるか(T040)。
 9. `projects.manuals.render-jobs.playback` / `projects.manuals.download` で完成 mp4 を再生・DL(byte 一致)。
 
-## このストーリーで消化する screens / operations
-- screens: projects.show, projects.manuals.create, projects.manuals.show, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.show, projects.manuals.render-jobs.playback, projects.manuals.download, capture.home, capture.csrf-cookie, capture.manuals.index, capture.manuals.show, capture.takes.playback
-- operations: projects.manuals.store, projects.manuals.update, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.source-documents.store, projects.manuals.analyze, projects.manuals.scenario.update, projects.manuals.preview, projects.manuals.render, capture.takes.upload-url, capture.takes.store, capture.takes.update, capture.takes.destroy, capture.takes.adopt, capture.takes.downloaded
-
 ## 逸脱アイデア (--deviate 時)
 - 解析失敗(実 AI/レート制限由来)を UX バグと環境ハザードで区別して記録する(Anthropic 429/5xx)。環境ハザードは比較可能性のため `HTTP status / 再試行回数 / 待機秒 / 発生 route` の 1 行フォーマットで残す。
 - analyze/render を二重送信 → 同時 in-flight が 1 本に抑えられるか(冪等)。失敗後のみ再実行できるか。
diff --git a/.claude/skills/app-bug-hunt/stories/S4-org-project-management.md b/.claude/skills/app-bug-hunt/stories/S4-org-project-management.md
index fba9e36b..0872421b 100644
--- a/.claude/skills/app-bug-hunt/stories/S4-org-project-management.md
+++ b/.claude/skills/app-bug-hunt/stories/S4-org-project-management.md
@@ -1,7 +1,23 @@
+---
+id: S4
+title: 組織・プロジェクト・カテゴリ・ユーザー管理
+surface: org_project_admin
+lane: parallel_browser
+priority: P2
+applicability: applicable
+depends_on: []
+reseed_before: false
+accounts: [owner, admin]
+setup: []
+covers_screens: [manage.users.index, organizations.api-keys.index, organizations.api-keys.sessions.index, organizations.create, organizations.onboarding.cli, organizations.onboarding.mcp, organizations.settings, projects.categories.index, projects.create, projects.edit, projects.index]
+covers_operations: [organizations.api-keys.revoke, organizations.api-keys.sessions.revoke, organizations.api-keys.store, organizations.store, organizations.switch, organizations.transfer-ownership, organizations.two-factor-requirement.update, organizations.update, projects.categories.destroy, projects.categories.reorder, projects.categories.store, projects.categories.update, projects.destroy, projects.items.destroy, projects.items.store, projects.items.update, projects.members.destroy, projects.members.store, projects.store, projects.update]
+covers_capabilities: [AK-01, AK-02, AK-03, MEM-07, ORG-01, ORG-02, ORG-03, ORG-04, ORG-05, PROJ-01, PROJ-02, PROJ-03, PROJ-04]
+---
+
 # S4: 組織・プロジェクト・カテゴリ・ユーザー管理
 
-- 前提状態: 組織オーナー/管理者(project_admin)でログイン済み。
-- 目的: 組織/プロジェクトの作成・編集・切替・削除、カテゴリ管理(専用画面)、管理者向けユーザー管理が反映され矛盾しないか。管理者専用機能が非管理者に漏れないか。
+## 目的
+組織オーナー/管理者(project_admin)による組織/プロジェクトの作成・編集・切替・削除、カテゴリ管理(専用画面)、管理者向けユーザー管理が反映され矛盾しないか。管理者専用機能が非管理者に漏れないか。
 
 ## 手順
 1. `organizations.create` → `organizations.store` で組織作成、`organizations.switch` で切替(ヘッダーの組織スイッチャーで往復できるか)、`organizations.settings` で設定確認。オーナー移譲 `organizations.transfer-ownership`(移譲先 select で空値エラー後に有効値を選ぶとエラーが消えるか=stale invalid 解消, T044)、2FA 必須化 `organizations.two-factor-requirement.update`。
@@ -11,10 +27,6 @@ ## 手順
 5. API キー/オンボーディング: `organizations.api-keys.index`/`store`/`revoke`、`organizations.api-keys.sessions.*`、`organizations.onboarding.cli`/`mcp`。
 6. サンプルリソース Item(テンプレ見本): `projects.items.store`/`update`/`destroy`(存在する場合)。
 
-## このストーリーで消化する screens / operations
-- screens: organizations.create, organizations.settings, organizations.api-keys.index, organizations.api-keys.sessions.index, organizations.onboarding.cli, organizations.onboarding.mcp, manage.users.index, projects.index, projects.create, projects.edit, projects.categories.index
-- operations: organizations.store, organizations.update, organizations.switch, organizations.transfer-ownership, organizations.two-factor-requirement.update, organizations.api-keys.store, organizations.api-keys.revoke, organizations.api-keys.sessions.revoke, projects.store, projects.update, projects.destroy, projects.categories.store, projects.categories.update, projects.categories.destroy, projects.categories.reorder, projects.members.store, projects.members.destroy, projects.items.store, projects.items.update, projects.items.destroy, debug.login-as
-
 ## 逸脱アイデア (--deviate 時)
 - 撮影者(project_member)/一般ユーザーで `manage.users.index` やカテゴリ管理に直アクセス → 403・サイドバー非表示になるか。
 - カテゴリ reorder を二重送信/古い集合で送る → sort_order が壊れないか(Project 行ロックで直列化)。
diff --git a/.claude/skills/app-bug-hunt/stories/S5-billing.md b/.claude/skills/app-bug-hunt/stories/S5-billing.md
index 6b4cc4e1..3e0c730c 100644
--- a/.claude/skills/app-bug-hunt/stories/S5-billing.md
+++ b/.claude/skills/app-bug-hunt/stories/S5-billing.md
@@ -1,7 +1,23 @@
+---
+id: S5
+title: 課金・チケット(残高/チャージ/消費)
+surface: billing
+lane: parallel_browser
+priority: P1
+applicability: applicable
+depends_on: []
+reseed_before: false
+accounts: [owner]
+setup: [Stripe は fake 実装で走らせる]
+covers_screens: [billing.index, billing.plans, billing.tickets.show, pricing]
+covers_operations: [billing.auto-recharge.setup, billing.auto-recharge.update, billing.checkout, billing.contact.update, billing.plan.change, billing.portal, billing.tickets.checkout]
+covers_capabilities: [BILL-01, BILL-02, BILL-03, BILL-04, BILL-05, PUB-01]
+---
+
 # S5: 課金・チケット(残高/チャージ/消費)
 
-- 前提状態: 代表ユーザー(組織オーナー/管理者)でログイン済み。Stripe fake。
-- 目的: プラン選択 → checkout → サブスク状態確認、およびチケット残高の確認・チャージ・消費が二重課金/無反応/残高不整合なく進むか。料金表(pricing)の表示が実際の課金と矛盾しないか。
+## 目的
+組織オーナー/管理者によるプラン選択 → checkout → サブスク状態確認、およびチケット残高の確認・チャージ・消費が二重課金/無反応/残高不整合なく進むか。料金表(pricing)の表示が実際の課金と矛盾しないか。
 
 ## 手順
 1. `pricing`(料金表)を開く → 三層(個人バナー / 法人グリッド / 大規模利用バナー)とチケット価格表が表示され、CTA(申込/チャージ)導線が見える。未ログインでも閲覧でき、申込はログインへ誘導。**月次付与は廃止済み(D28)なので「月 N 枚のチケット付与」表記が復活していないか**も見る。
@@ -34,10 +50,6 @@ ## 手順
      `auto-recharge-status` / `auto-recharge-max-amount` の表示が設定値と矛盾しないか。
 10. チケット消費との整合(S3 と連動): analyze で 1、render で N 消費され、残高が減る。preview は非消費。ジョブ失敗時は予約が解放され残高が戻る(reserve→commit/release の 2 フェーズ)。
 
-## このストーリーで消化する screens / operations
-- screens: pricing, billing.index, billing.plans, billing.tickets.show
-- operations: billing.checkout, billing.portal, billing.tickets.checkout, billing.contact.update, billing.auto-recharge.update, billing.auto-recharge.setup
-
 ## 逸脱アイデア (--deviate 時)
 - checkout を二重送信/戻る→再送 → 二重課金・二重チャージにならないか(冪等マシン/webhook)。
 - 残高不足のまま analyze/render を強行 → 押下時エラーで詰まないか、残高がマイナスにならないか。
diff --git a/.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md b/.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md
index a2c52f39..f7966262 100644
--- a/.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md
+++ b/.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md
@@ -1,7 +1,23 @@
+---
+id: S6
+title: セキュリティ (2FA/プロフィール/機微操作の再認証)
+surface: account_security
+lane: parallel_browser
+priority: P1
+applicability: applicable
+depends_on: []
+reseed_before: false
+accounts: [owner]
+setup: []
+covers_screens: [notifications.index, passkey.confirm-options, passkey.registration-options, password.confirm, recent-auth.confirm, recent-auth.status, session.status, settings, settings.security]
+covers_operations: [notifications.open, notifications.read, notifications.read-all, passkey.confirm, passkey.destroy, passkey.store, password.confirm.store, recent-auth.password, settings.account.deletion-request.destroy, settings.account.deletion-request.store, settings.account.destroy, settings.password.store, two-factor.confirm, two-factor.disable, two-factor.enable, two-factor.regenerate-recovery-codes, user-password.update, user-profile-information.update]
+covers_capabilities: [NOTI-01, SEC-01, SEC-02, SEC-03, SEC-04, SEC-05, SEC-06, SEC-07]
+---
+
 # S6: セキュリティ (2FA/プロフィール/機微操作の再認証)
 
-- 前提状態: 代表ユーザーでログイン済み。
-- 目的: 2FA 有効化/無効化・プロフィール編集・パスワード変更・機微操作前の再認証(recent-auth / confirm-password)・アカウント削除が正しく反映され、無防備に実行されないか。
+## 目的
+ログイン済みの代表ユーザーによる 2FA 有効化/無効化・プロフィール編集・パスワード変更・機微操作前の再認証(recent-auth / confirm-password)・アカウント削除が正しく反映され、無防備に実行されないか。
 
 ## 手順
 1. `settings` → プロフィール編集 `user-profile-information.update`(表示名/メール。PII は保護)、パスワード変更 `user-password.update`。パスワード入力に**「表示」トグル**があるか(T042)。保存成功のトーストが出るか(T026)。
@@ -35,10 +51,6 @@ ## 手順
      詰まないこと。H4)。**iOS Safari / WebKit レーンが主戦場**なので WebKit で必ず見る。
 6. 通知センター `notifications.index`(`/notifications`): 通知一覧・空状態の説明、既読化 `notifications.read` / 一括既読 `notifications.read-all` / 開封遷移 `notifications.open`。ブラウザタブ title が固有(「通知 | AI-CUE」)か(T034)。
 
-## このストーリーで消化する screens / operations
-- screens: settings, settings.security, password.confirm, recent-auth.confirm, recent-auth.status, notifications.index, session.status, passkey.registration-options, passkey.confirm-options
-- operations: user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes, password.confirm.store, recent-auth.password, settings.account.destroy, notifications.read, notifications.read-all, notifications.open, passkey.store, passkey.destroy, passkey.confirm, settings.password.store
-
 ## 逸脱アイデア (--deviate 時)
 - 再認証(recent-auth/confirm-password)を経ずに機微操作(2FA無効化・アカウント削除・オーナー移譲)を直 POST → ブロックされるか。
 - パスワード変更後に旧セッションが無効化されるか。2FA 無効化直後に必須組織(two-factor-requirement)へアクセスできるか。
diff --git a/.claude/skills/app-bug-hunt/stories/S7-authz-boundaries.md b/.claude/skills/app-bug-hunt/stories/S7-authz-boundaries.md
index 1eae9e31..89d5fbb4 100644
--- a/.claude/skills/app-bug-hunt/stories/S7-authz-boundaries.md
+++ b/.claude/skills/app-bug-hunt/stories/S7-authz-boundaries.md
@@ -1,9 +1,27 @@
+---
+id: S7
+title: 認可境界 (IDOR) — AI-CUE ドメイン横断
+surface: authz_boundary
+lane: parallel_browser
+priority: P1
+applicability: applicable
+depends_on: [S3]
+reseed_before: false
+accounts: [owner, member]
+setup: [組織 A と組織 B の 2 アカウントを別 cookie セッションで用意する, S3 実行後の状態 (A 側の manual/cut/take/category/render) を残したまま始める]
+covers_screens: [capture.manuals.show, capture.takes.playback, projects.categories.index, projects.edit, projects.manuals.download, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.playback, projects.manuals.render-jobs.show, projects.manuals.show, projects.show]
+covers_operations: [capture.takes.adopt, capture.takes.destroy, projects.categories.destroy, projects.categories.reorder, projects.categories.update, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.scenario.update, projects.manuals.update]
+covers_capabilities: [CAP-01, CAP-03, CAP-04, PROJ-03, REN-03, REN-04, SCEN-02, SCEN-03, SCEN-05, SOP-01, SOP-04, SOP-05]
+---
+
 # S7: 認可境界 (IDOR) — AI-CUE ドメイン横断
 
-> S3 実行後の状態を意図的に使う。組織 A/B・プロジェクト・ロール(編集者/撮影者)を跨いだ read/write が認可より前に 404/403 で弾かれるか(セキュリティ不変条件: 子は親に属する・cross-org 不可・tenant キー不信)。
+## 目的
+組織 A/B・プロジェクト・ロール(編集者/撮影者)を跨いだ read/write が認可より前に 404/403 で弾かれるか(セキュリティ不変条件: 子は親に属する・cross-org 不可・tenant キー不信)。nested route の子解決が親 relation 経由で、越境は 403 でなく 404(存在を漏らさない)であること。存在オラクル(422/404 差分)が無いこと。A に S3 で作った manual/cut/take/category/render があり、B からは何も見えてはならない。
 
-- 前提状態: 組織 A/B の 2 ユーザー。A に S3 で作った manual/cut/take/category/render がある。B からは何も見えてはならない。
-- 目的: nested route の子解決が親 relation 経由で、越境は 403 でなく 404(存在を漏らさない)であること。存在オラクル(422/404 差分)が無いこと。
+**画面側は「新規消化」ではなく B 視点での再走査**である。上の `covers_screens` 11 件は
+いずれも S3 / S4 が既に消化しているものを、越境で 404 になることの確認として踏み直す
+(1 route を複数カードが挙げてよい。目録のセルは `S3 S7` のように並ぶ)。
 
 ## 手順
 1. B のログインで、A の URL を直叩き: `projects.show`/`projects.manuals.show`/`projects.manuals.edit`/`projects.manuals.jobs.show`/`projects.manuals.render-jobs.show`/`capture.manuals.show` → いずれも 404(403 でも Blade エラーでもなく)。
@@ -14,10 +32,6 @@ ## 手順
 6. 撮影者(project_member)ロールで編集者専用操作(manuals.store/update/destroy, categories.*, analyze, render, manage.users)→ 403。編集者は撮影者専用でない全操作可。
 7. tenant/protected キーを payload に混入(project_id/created_by/category_id/parent_cut_id/adopted_take_id/ticket_reservation_id/video_manual_id/cut_id)→ 422(ProhibitsProtectedKeys)。`category` 別名は許容、`category_id` 直送は 422。
 
-## このストーリーで消化する screens / operations
-- screens: (S3/S4 の全 nested screen を B 視点で 404 確認。新規消化はしないが再走査)
-- operations: projects.manuals.update, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.scenario.update, projects.categories.update, projects.categories.destroy, projects.categories.reorder, capture.takes.adopt, capture.takes.destroy(いずれも越境で 404)
-
 ## 逸脱アイデア (--deviate 時)
 - 隣接 ID 総当り(manual/cut/take/category/render-job の id を ±1)→ 他組織・他プロジェクトのリソースに到達できないか。
 - 署名 URL(download/playback)の manual/lang を差し替え → 他 manual の完成動画が取れないか。
diff --git a/.claude/skills/app-bug-hunt/stories/story_front_matter.py b/.claude/skills/app-bug-hunt/stories/story_front_matter.py
new file mode 100644
index 00000000..dfa32552
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/stories/story_front_matter.py
@@ -0,0 +1,230 @@
+#!/usr/bin/env python3
+"""シナリオカードの前付け (制限文法) の読み取り器。
+
+文法の**正本は `README.md`** であり、ここは**従う読み手**である。
+読み取り器を書き換えて文法を広げてはならない (広げるなら README と自己テストを同じ変更で直す)。
+
+**この読み取り器が見るもの** (制限文法 = README §1):
+
+- 前付けの区切り (1 行目が厳密に `---` / 次に現れる「行頭から `---` だけ」の行で閉じる)
+- 1 行 1 項目の `key: value` (半角コロン + 半角空白 1 つ)
+- key の書式・重複・**この文法に無い key**
+- 値の 3 形 (素のスカラー / 真偽値 / 配列) と、key ごとにどの形を取るか
+
+**この読み取り器が見ないもの** (見るのは呼び出し側である):
+
+- 必須 key の全数と正準順序 / 閉じた語彙 / 表 A・表 B との突合 / 本文の確定形
+  … `test_story_front_matter.py` が見る
+- `covers_*` の値の実在 / 欄の意味 / 分母の被覆 … `scripts/bug-hunt-inventory.py` が見る
+
+**例外を投げない** (読み取り不能そのものを除く)。違反は並びで返す。1 件目で止めると
+直すたびに再実行が要るためである。
+
+依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
+"""
+from __future__ import annotations
+
+import re
+from dataclasses import dataclass
+from pathlib import Path
+
+CANONICAL_KEYS = (
+    "id", "title", "surface", "lane", "priority", "applicability",
+    "not_applicable_reason",
+    "depends_on", "reseed_before", "accounts", "setup",
+    "covers_screens", "covers_operations", "covers_capabilities",
+)
+CONDITIONAL_KEY = "not_applicable_reason"
+REQUIRED_KEYS = tuple(k for k in CANONICAL_KEYS if k != CONDITIONAL_KEY)
+
+SCALAR_KEYS = frozenset({
+    "id", "title", "surface", "lane", "priority", "applicability", CONDITIONAL_KEY,
+})
+BOOL_KEYS = frozenset({"reseed_before"})
+ARRAY_KEYS = frozenset({
+    "depends_on", "accounts", "setup",
+    "covers_screens", "covers_operations", "covers_capabilities",
+})
+
+LANE_VOCABULARY = ("parallel_browser", "serial_parent")
+PRIORITY_VOCABULARY = ("P1", "P2", "P3")
+APPLICABILITY_VOCABULARY = ("applicable", "not_applicable")
+ACCOUNT_VOCABULARY = ("guest", "owner", "admin", "member", "platform_admin")
+
+# 照合はすべて fullmatch() で行う (Python の `$` は**末尾改行の直前にも一致する**ため、
+# match() + `$` は「厳密一致」と同義ではない)。
+CARD_ID_RE = re.compile(r"S[1-9][0-9]*")
+KEY_RE = re.compile(r"[a-z][a-z0-9_]*")
+FILENAME_RE = re.compile(r"S[1-9][0-9]*-.+\.md")
+ROUTE_TOKEN_RE = re.compile(r"[a-z0-9]+([._-][a-z0-9]+)*")
+CAPABILITY_TOKEN_RE = re.compile(r"[A-Z]+-[0-9]{2}")
+SURFACE_TOKEN_RE = re.compile(r"[a-z][a-z0-9_]*")
+
+FRONT_MATTER_DELIMITER = "---"
+BOOLEAN_LITERALS = {"true": True, "false": False}
+ARRAY_SEPARATOR = ", "
+# スカラーと配列要素に許さない文字 (引用符・注釈・区切り・入れ子の記号)。
+FORBIDDEN_VALUE_CHARS = "#:[]'\""
+
+# 除外は**閉じたリテラル集合**にする (パターン除外を作らない)。
+EXCLUDED_FILENAMES = frozenset({"README.md"})
+
+
+class StoryReadError(Exception):
+    """カードを読むこと自体が成立しない状態 (置き場が無い / 候補が 0 件 / 読み取り不能)。"""
+
+
+@dataclass(frozen=True)
+class Card:
+    """1 枚のカード。値は制限文法で読めた形のまま持つ。"""
+
+    filename: str
+    text: str
+    front_matter: dict[str, object]
+    keys_in_order: tuple[str, ...]
+    body: str
+
+
+def _scalar_violation(key: str, value: str) -> str | None:
+    if value == "":
+        return f"{key}: スカラーが空である"
+    if value != value.strip():
+        return f"{key}: スカラーの前後に空白がある"
+    for char in FORBIDDEN_VALUE_CHARS:
+        if char in value:
+            return f"{key}: スカラーに使えない文字がある: {char!r}"
+
+    return None
+
+
+def _parse_array(key: str, value: str) -> tuple[list[str], list[str]]:
+    """配列を読む。`[]` か `[a, b, c]` だけを認める (ネスト不可・引用符禁止)。"""
+    if not (value.startswith("[") and value.endswith("]")):
+        return [], [f"{key}: 配列が角括弧で囲まれていない: {value!r}"]
+    inner = value[1:-1]
+    if inner == "":
+        return [], []
+
+    elements = inner.split(ARRAY_SEPARATOR)
+    violations: list[str] = []
+    for element in elements:
+        violation = _scalar_violation(key, element)
+        if violation is not None:
+            violations.append(f"{violation} (要素 {element!r})")
+        elif "," in element:
+            violations.append(f"{key}: 配列の区切りが '{ARRAY_SEPARATOR}' でない: {element!r}")
+
+    return elements, violations
+
+
+def parse_front_matter(
+    text: str,
+) -> tuple[dict[str, object], tuple[str, ...], list[str], str]:
+    """前付けを読み、(値, 出現順の key, 違反, 本文) を返す。**例外を投げない**。"""
+    violations: list[str] = []
+    lines = text.split("\n")
+
+    if not lines or lines[0] != FRONT_MATTER_DELIMITER:
+        violations.append(f"1 行目が {FRONT_MATTER_DELIMITER!r} でない")
+
+        return {}, (), violations, text
+
+    close = None
+    for index in range(1, len(lines)):
+        if lines[index] == FRONT_MATTER_DELIMITER:
+            close = index
+            break
+    if close is None:
+        violations.append(f"前付けが {FRONT_MATTER_DELIMITER!r} で閉じていない")
+
+        return {}, (), violations, text
+
+    values: dict[str, object] = {}
+    order: list[str] = []
+    for line in lines[1:close]:
+        if line == "":
+            violations.append("前付けに空行がある")
+            continue
+        key, separator, rest = line.partition(":")
+        if separator == "":
+            violations.append(f"key: value の形でない: {line!r}")
+            continue
+        if not rest.startswith(" "):
+            violations.append(f"半角コロンの後に半角空白 1 つが要る: {line!r}")
+            continue
+        value = rest[1:]
+        if KEY_RE.fullmatch(key) is None:
+            violations.append(f"key の書式が契約外: {key!r}")
+            continue
+        if key in values:
+            violations.append(f"key が重複している: {key}")
+            continue
+        if key not in CANONICAL_KEYS:
+            violations.append(f"この文法に無い key: {key}")
+            continue
+
+        if key in BOOL_KEYS:
+            if value not in BOOLEAN_LITERALS:
+                violations.append(f"{key}: 真偽値が true / false でない: {value!r}")
+                continue
+            values[key] = BOOLEAN_LITERALS[value]
+        elif key in ARRAY_KEYS:
+            elements, element_violations = _parse_array(key, value)
+            violations += element_violations
+            if element_violations:
+                continue
+            values[key] = elements
+        else:
+            violation = _scalar_violation(key, value)
+            if violation is not None:
+                violations.append(violation)
+                continue
+            values[key] = value
+        order.append(key)
+
+    return values, tuple(order), violations, "\n".join(lines[close + 1:])
+
+
+def parse_card(filename: str, text: str) -> tuple[Card, list[str]]:
+    """1 枚分の本文からカードを作る。違反があってもカードは返す (呼び出し側が判断する)。"""
+    values, order, violations, body = parse_front_matter(text)
+
+    return (
+        Card(filename=filename, text=text, front_matter=values, keys_in_order=order, body=body),
+        [f"{filename}: {v}" for v in violations],
+    )
+
+
+def stories_dir() -> Path:
+    return Path(__file__).resolve().parent
+
+
+def read_cards(directory: Path | None = None) -> tuple[list[Card], list[str]]:
+    """候補母集団 (`*.md` から `EXCLUDED_FILENAMES` を引いた全件) を読む。
+
+    **パターンで発見しない**。`S8.md` のような命名違反を「存在しないもの」にしないため、
+    全件走査してから命名契約を検査する (命名の判定は呼び出し側の責務)。
+
+    読むこと自体が成立しない場合 (置き場が無い / 候補が 0 件 / 読み取り不能) は
+    `StoryReadError` を投げる。**違反 0 件と母集団 0 件を混ぜない**ためである。
+    """
+    target = stories_dir() if directory is None else directory
+    if not target.is_dir():
+        raise StoryReadError(f"カードの置き場が無い: {target}")
+
+    candidates = [p for p in sorted(target.glob("*.md")) if p.name not in EXCLUDED_FILENAMES]
+    if not candidates:
+        raise StoryReadError(f"カードの候補が 1 件も無い: {target}")
+
+    cards: list[Card] = []
+    violations: list[str] = []
+    for path in candidates:
+        try:
+            text = path.read_text(encoding="utf-8")
+        except (OSError, UnicodeDecodeError) as exc:
+            raise StoryReadError(f"カードを読めない: {path} ({exc})") from exc
+        card, card_violations = parse_card(path.name, text)
+        cards.append(card)
+        violations += card_violations
+
+    return cards, violations
diff --git a/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py b/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py
new file mode 100644
index 00000000..91275109
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py
@@ -0,0 +1,1181 @@
+#!/usr/bin/env python3
+"""シナリオカードの書式契約の自己テスト (標準ライブラリのみ)。
+
+    cd .claude/skills/app-bug-hunt/stories && python3 -m unittest test_story_front_matter
+
+`composer test` からは `tests/Architecture/BughuntStoryToolSelfTest.php` が起動する。
+
+**走査対象**: `stories/*.md` から `story_front_matter.EXCLUDED_FILENAMES` を引いた全件と、
+書式の正本 `README.md` のマーカー区間 2 つ (表 A = 許可する対象面の語彙 /
+表 B = カード目録)。判定に使う純関数 (`card_violations` / `graph_violations` /
+`marker_table` / `partition_violations`) は**合成入力にも実データにも同じものを使う**ので、
+負例は実ファイル母集団が 0 件になっても走る。
+
+**保証しないもの**:
+
+- `covers_screens` / `covers_operations` / `covers_capabilities` の値の**実在**は見ない
+  (形だけを見る)。実在・欄の意味・分母の被覆は `scripts/bug-hunt-inventory.py` の責務で、
+  同じ規則を 2 か所に持たない (B16)。
+- `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定 fan-out マップの一致は見ない
+  (固定マップは派生キャッシュ。E5)。
+- 兆候番号 (`H{n}`) の意味がカードに書かれていないことは見ない (G6)。
+- 手順の書式 (ステップ表・step 識別子) は**採っていない**ので検査しない
+  (`docs/template-divergence.md` D40)。
+"""
+from __future__ import annotations
+
+import re
+import unittest
+
+import story_front_matter as sfm
+
+STORIES_DIR = sfm.stories_dir()
+README_PATH = STORIES_DIR / "README.md"
+
+SURFACE_MARKER = "STORY-SURFACE-VOCABULARY"
+INVENTORY_MARKER = "STORY-CARD-INVENTORY"
+SURFACE_TABLE_HEADER = ("surface", "面", "由来")
+INVENTORY_TABLE_HEADER = ("id", "surface")
+
+# 家系必須の対象面。削除・改名は fail (追記は自由)。
+FAMILY_REQUIRED_SURFACES = (
+    "signup_funnel", "invitation", "core_journey", "org_project_admin", "billing",
+    "account_security", "authz_boundary", "result_view", "admin_console",
+    "cli_or_api", "public_share",
+)
+
+# 家系固定: 既存番号の面を付け替えない。
+FAMILY_SURFACE_PIN = (
+    ("S1", "signup_funnel"),
+    ("S2", "invitation"),
+    ("S3", "core_journey"),
+    ("S4", "org_project_admin"),
+    ("S5", "billing"),
+    ("S6", "account_security"),
+    ("S7", "authz_boundary"),
+)
+PINNED_IDS = frozenset(card_id for card_id, _ in FAMILY_SURFACE_PIN)
+
+# 旧メタ節。前付けと散文の二重正本を残さない (H1)。
+LEGACY_META_PATTERNS = (
+    "- 前提状態:",
+    "- 目的:",
+    "## このストーリーで消化する",
+)
+
+PURPOSE_HEADING = "## 目的"
+DEVIATION_HEADING = "## 逸脱アイデア (--deviate 時)"
+STEPS_HEADING = "## 手順"
+
+
+# --------------------------------------------------------------------------- #
+# 判定の純関数 (合成入力にも実データにも同じものを使う)
+# --------------------------------------------------------------------------- #
+def marker_table(
+    text: str, marker: str, header: tuple[str, ...]
+) -> tuple[list[tuple[str, ...]], list[str]]:
+    """マーカー区間から表を抜き、構造契約を検査して (データ行, 違反) を返す。
+
+    契約: 区間がちょうど 1 対ある → 正準ヘッダ → 同じ列数の区切り行 →
+    **残りはすべてデータ行** (読み飛ばしを一切しない)。空行は区間の中に置いてよい。
+    """
+    violations: list[str] = []
+    begin, end = f"<!-- {marker}:BEGIN -->", f"<!-- {marker}:END -->"
+    if text.count(begin) != 1 or text.count(end) != 1:
+        violations.append(f"{marker}: マーカー区間がちょうど 1 対でない")
+        return [], violations
+
+    inner = text.split(begin, 1)[1].split(end, 1)[0]
+    lines = [line.strip() for line in inner.splitlines() if line.strip()]
+    if not lines:
+        violations.append(f"{marker}: 区間が空である")
+        return [], violations
+
+    expected_header = "| " + " | ".join(header) + " |"
+    if lines[0] != expected_header:
+        violations.append(f"{marker}: 正準ヘッダでない: {lines[0]!r} (期待 {expected_header!r})")
+        return [], violations
+    if len(lines) < 2:
+        violations.append(f"{marker}: 区切り行が無い")
+        return [], violations
+    separator = [c.strip() for c in lines[1].strip("|").split("|")]
+    if len(separator) != len(header) or any(set(c) != {"-"} for c in separator):
+        violations.append(f"{marker}: 区切り行の列数か書式が契約外: {lines[1]!r}")
+        return [], violations
+
+    rows: list[tuple[str, ...]] = []
+    for line in lines[2:]:
+        if not line.startswith("|") or not line.endswith("|"):
+            violations.append(f"{marker}: 区間に表以外の行がある: {line!r}")
+            continue
+        cols = tuple(c.strip() for c in line.strip("|").split("|"))
+        if len(cols) != len(header):
+            violations.append(f"{marker}: データ行の列数が {len(header)} でない: {line!r}")
+            continue
+        rows.append(cols)
+
+    if not rows:
+        violations.append(f"{marker}: データ行が 1 行も無い")
+
+    return rows, violations
+
+
+def unwrap_code(value: str) -> tuple[str, bool]:
+    """1 対のバッククォートを外す。装飾がそれ以外なら第 2 要素が False。"""
+    if len(value) >= 2 and value.startswith("`") and value.endswith("`") and "`" not in value[1:-1]:
+        return value[1:-1], True
+
+    return value, False
+
+
+def surface_vocabulary(text: str) -> tuple[list[str], list[str]]:
+    """表 A を読み、許可する対象面の語彙と違反を返す (C1 / C2 / C3)。"""
+    rows, violations = marker_table(text, SURFACE_MARKER, SURFACE_TABLE_HEADER)
+    surfaces: list[str] = []
+    for cols in rows:
+        token, decorated = unwrap_code(cols[0])
+        if not decorated:
+            violations.append(f"表 A: surface の装飾が 1 対のバッククォートでない: {cols[0]!r}")
+            continue
+        if sfm.SURFACE_TOKEN_RE.fullmatch(token) is None:
+            violations.append(f"表 A: surface が snake_case 1 語でない: {token!r}")
+            continue
+        if token in surfaces:
+            violations.append(f"表 A: surface が重複している: {token}")
+            continue
+        surfaces.append(token)
+
+    for required in FAMILY_REQUIRED_SURFACES:
+        if required not in surfaces:
+            violations.append(f"表 A: 家系必須の対象面が無い: {required}")
+
+    return surfaces, violations
+
+
+def card_inventory(text: str) -> tuple[list[tuple[str, str]], list[str]]:
+    """表 B を読み、(id, surface) の並びと違反を返す (C4 / C5)。"""
+    rows, violations = marker_table(text, INVENTORY_MARKER, INVENTORY_TABLE_HEADER)
+    entries: list[tuple[str, str]] = []
+    seen: set[str] = set()
+    for cols in rows:
+        card_id = cols[0]
+        token, decorated = unwrap_code(cols[1])
+        if sfm.CARD_ID_RE.fullmatch(card_id) is None:
+            violations.append(f"表 B: id の書式が契約外: {card_id!r}")
+            continue
+        if not decorated:
+            violations.append(f"表 B: surface の装飾が 1 対のバッククォートでない: {cols[1]!r}")
+            continue
+        if card_id in seen:
+            violations.append(f"表 B: id が重複している: {card_id}")
+            continue
+        seen.add(card_id)
+        entries.append((card_id, token))
+
+    return entries, violations
+
+
+def section_body(text: str, heading: str) -> str | None:
+    """H2 見出しの直後から次の H2 見出しの直前までを返す。無ければ None。"""
+    lines = text.splitlines()
+    start = None
+    for index, line in enumerate(lines):
+        if line == heading:
+            start = index + 1
+            break
+    if start is None:
+        return None
+    end = len(lines)
+    for index in range(start, len(lines)):
+        if lines[index].startswith("## "):
+            end = index
+            break
+
+    return "\n".join(lines[start:end])
+
+
+def card_violations(card: sfm.Card, surfaces: tuple[str, ...] | list[str]) -> list[str]:
+    """カード 1 枚の契約を検査する (B / F2 / H1 / J 群)。
+
+    ★ 前付けの**文法**違反は `story_front_matter.parse_card()` が既に返しているので、
+      ここでは重ねて見ない。ここが見るのは「読めた前付けの中身」と本文である。
+    """
+    violations: list[str] = []
+    prefix = f"{card.filename}:"
+    values = card.front_matter
+
+    # --- B1: 必須 key の全数と正準順序 (条件付き key は applicability で決まる) ---
+    applicability = values.get("applicability")
+    expected = list(sfm.REQUIRED_KEYS)
+    if applicability == "not_applicable":
+        expected.insert(sfm.CANONICAL_KEYS.index(sfm.CONDITIONAL_KEY), sfm.CONDITIONAL_KEY)
+    if list(card.keys_in_order) != expected:
+        violations.append(f"{prefix} key の全数か正準順序が契約外: {list(card.keys_in_order)}")
+        return violations
+
+    def scalar(key: str) -> str:
+        value = values.get(key)
+
+        return value if isinstance(value, str) else ""
+
+    def array(key: str) -> list[str]:
+        value = values.get(key)
+
+        return [str(v) for v in value] if isinstance(value, list) else []
+
+    # --- B2 / B4〜B7 / B10 / B11: 語彙と書式 ---
+    if sfm.CARD_ID_RE.fullmatch(scalar("id")) is None:
+        violations.append(f"{prefix} id の書式が契約外: {scalar('id')!r}")
+    if scalar("title") == "":
+        violations.append(f"{prefix} title が空である")
+    if scalar("surface") not in surfaces:
+        violations.append(f"{prefix} surface が表 A に無い: {scalar('surface')!r}")
+    if scalar("lane") not in sfm.LANE_VOCABULARY:
+        violations.append(f"{prefix} 未知の lane: {scalar('lane')!r}")
+    if scalar("priority") not in sfm.PRIORITY_VOCABULARY:
+        violations.append(f"{prefix} 未知の priority: {scalar('priority')!r}")
+    if scalar("applicability") not in sfm.APPLICABILITY_VOCABULARY:
+        violations.append(f"{prefix} 未知の applicability: {scalar('applicability')!r}")
+    if not isinstance(values.get("reseed_before"), bool):
+        violations.append(f"{prefix} reseed_before が真偽値でない")
+    for account in array("accounts"):
+        if account not in sfm.ACCOUNT_VOCABULARY:
+            violations.append(f"{prefix} 未知の accounts トークン: {account!r}")
+
+    # --- B8: 条件付き key の値 ---
+    if applicability == "not_applicable" and scalar(sfm.CONDITIONAL_KEY) == "":
+        violations.append(f"{prefix} not_applicable_reason が空である")
+
+    # --- B9 / B12〜B15 + AC-13: 配列の形と重複 ---
+    for key, pattern in (
+        ("depends_on", sfm.CARD_ID_RE),
+        ("covers_screens", sfm.ROUTE_TOKEN_RE),
+        ("covers_operations", sfm.ROUTE_TOKEN_RE),
+        ("covers_capabilities", sfm.CAPABILITY_TOKEN_RE),
+    ):
+        for element in array(key):
+            if pattern.fullmatch(element) is None:
+                violations.append(f"{prefix} {key} の要素の書式が契約外: {element!r}")
+    for key in sfm.ARRAY_KEYS:
+        elements = array(key)
+        duplicates = sorted({e for e in elements if elements.count(e) > 1})
+        if duplicates:
+            violations.append(f"{prefix} {key} に重複した要素がある: {', '.join(duplicates)}")
+    for element in array("setup"):
+        if element.strip() == "":
+            violations.append(f"{prefix} setup に空の要素がある")
+
+    # --- J1: H1 見出しと前付けの機械一致 ---
+    expected_heading = f"# {scalar('id')}: {scalar('title')}"
+    headings = [line for line in card.body.splitlines() if line.startswith("# ")]
+    if headings[:1] != [expected_heading]:
+        violations.append(f"{prefix} H1 見出しが前付けと一致しない (期待 {expected_heading!r})")
+
+    # --- F2: not_applicable のカードは手順を持たない ---
+    has_steps = any(line == STEPS_HEADING for line in card.body.splitlines())
+    if applicability == "not_applicable" and has_steps:
+        violations.append(f"{prefix} not_applicable のカードに {STEPS_HEADING} 節がある")
+
+    # --- H1: 旧メタ節が残っていない ---
+    for line in card.body.splitlines():
+        for pattern in LEGACY_META_PATTERNS:
+            if line.startswith(pattern):
+                violations.append(f"{prefix} 旧メタ節が残っている: {line!r}")
+
+    # --- J2 / J3: 本文の確定形 (ちょうど 1 個 + 中身が空でない) ---
+    for heading in (PURPOSE_HEADING, DEVIATION_HEADING):
+        count = sum(1 for line in card.body.splitlines() if line == heading)
+        if count != 1:
+            violations.append(f"{prefix} {heading} 節がちょうど 1 個でない ({count} 個)")
+            continue
+        body = section_body(card.body, heading)
+        if body is None or body.strip() == "":
+            violations.append(f"{prefix} {heading} 節の中身が空である")
+
+    return violations
+
+
+def graph_violations(cards: list[sfm.Card]) -> list[str]:
+    """カード横断の契約を検査する (D3 / D4 / D5 / E1 / E2 / E3)。"""
+    violations: list[str] = []
+    ids: list[str] = []
+    by_id: dict[str, sfm.Card] = {}
+
+    for card in cards:
+        # --- D5: ファイル名の先頭セグメントだけを機械一致させる ---
+        if sfm.FILENAME_RE.fullmatch(card.filename) is None:
+            violations.append(f"{card.filename}: ファイル名が S{{n}}-{{kebab}}.md でない")
+            continue
+        card_id = str(card.front_matter.get("id", ""))
+        if sfm.CARD_ID_RE.fullmatch(card_id) is None:
+            violations.append(f"{card.filename}: id の書式が契約外で番号規約を判定できない")
+            continue
+        if card.filename.split("-", 1)[0] != card_id:
+            violations.append(f"{card.filename}: ファイル名の先頭セグメントが id ({card_id}) と違う")
+            continue
+        # --- D3: id は一意 ---
+        if card_id in by_id:
+            violations.append(f"{card.filename}: id が重複している: {card_id}")
+            continue
+        ids.append(card_id)
+        by_id[card_id] = card
+
+    # --- D4: 欠番を作らない (S1 から最大番号まで連番) ---
+    if ids:
+        numbers = sorted(int(i[1:]) for i in ids)
+        if numbers != list(range(1, numbers[-1] + 1)):
+            violations.append(f"カード番号に欠番がある: {numbers}")
+
+    # --- E1: depends_on の実在・自己参照・循環 ---
+    for card_id, card in by_id.items():
+        for dependency in card.front_matter.get("depends_on", []) or []:
+            if dependency == card_id:
+                violations.append(f"{card.filename}: depends_on が自己参照している")
+            elif dependency not in by_id:
+                violations.append(f"{card.filename}: depends_on に実在しないカード: {dependency}")
+
+    def reaches_self(start: str) -> bool:
+        """start から depends_on を辿って start 自身へ戻れるか (自己参照を含む)。"""
+        stack, seen = [start], set()
+        while stack:
+            node = stack.pop()
+            for dependency in by_id[node].front_matter.get("depends_on") or []:
+                key = str(dependency)
+                if key == start:
+                    return True
+                if key in by_id and key not in seen:
+                    seen.add(key)
+                    stack.append(key)
+
+        return False
+
+    for card_id, card in by_id.items():
+        if reaches_self(card_id):
+            violations.append(f"{card.filename}: depends_on が循環している")
+
+    # --- E2 / E3 ---
+    for card_id, card in by_id.items():
+        dependencies = [str(d) for d in (card.front_matter.get("depends_on") or [])]
+        if dependencies and card.front_matter.get("reseed_before") is not False:
+            violations.append(f"{card.filename}: depends_on を持つなら reseed_before は false")
+        if card.front_matter.get("lane") == "parallel_browser":
+            for dependency in dependencies:
+                if dependency in by_id and by_id[dependency].front_matter.get("lane") == "serial_parent":
+                    violations.append(
+                        f"{card.filename}: parallel_browser のカードが serial_parent に依存している"
+                    )
+
+    return violations
+
+
+# --------------------------------------------------------------------------- #
+# AC-14: 全数点呼
+# --------------------------------------------------------------------------- #
+# 詳細設計の全数対応表の全 58 項目。**ここが点呼の基準**である。
+ALL_INVARIANTS = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D6", "D7",
+    "E1", "E2", "E3", "E4", "E5",
+    "F1", "F2",
+    "G1", "G2", "G3", "G4", "G5", "G6",
+    "H1",
+    "I1", "I2", "I3", "I4", "I5", "I6", "I7",
+    "J1", "J2", "J3",
+)
+EXPECTED_TOTAL = 58
+
+# --- 分類 (互いに排他。和が ALL_INVARIANTS と一致する) ---
+ADOPTED = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D7",
+    "E1", "E2", "E3", "E4", "E5",
+    "F2",
+    "G6",
+    "H1",
+    "I1", "I2", "I3", "I4", "I6",
+    "J1", "J2", "J3",
+)
+DIFFERENCES = ("I5", "I7")                                  # aicue 固有差 (既存 D20 が説明)
+NOT_ADOPTED = ("D6", "F1", "G1", "G2", "G3", "G4", "G5")    # 新規 D40 が説明
+
+# --- 担い手 (集合同士の重複を許す。B16 のように両側に現れる項目がある) ---
+STORY_SIDE = (
+    "A1", "A2", "A3", "A4", "A5", "A6",
+    "B1", "B2", "B3", "B4", "B5", "B6", "B7", "B8",
+    "B9", "B10", "B11", "B12", "B13", "B14", "B15", "B16",
+    "C1", "C2", "C3", "C4", "C5",
+    "D1", "D2", "D3", "D4", "D5", "D7",
+    "E1", "E2", "E3", "E4",
+    "F2", "H1", "J1", "J2", "J3",
+)
+INVENTORY_SIDE = ("B16", "I1", "I2", "I3", "I4", "I6")
+NON_MECHANICAL = ("E5", "G6")
+
+SUBJECT_TO_TESTS = {
+    "AC-01": (
+        "test_ac_01_accepts_canonical_front_matter",
+        "test_ac_01_accepts_horizontal_rule_in_body",
+        "test_ac_01_rejects_quoted_scalar",
+        "test_ac_01_rejects_duplicate_key",
+        "test_ac_01_rejects_key_out_of_canonical_order",
+        "test_ac_01_rejects_missing_required_key",
+        "test_ac_01_rejects_unknown_key",
+        "test_ac_01_rejects_blank_and_comment_line",
+        "test_ac_01_rejects_missing_delimiter",
+    ),
+    "AC-02": (
+        "test_ac_02_accepts_real_cards_vocabulary",
+        "test_ac_02_rejects_unknown_lane",
+        "test_ac_02_rejects_unknown_priority",
+        "test_ac_02_rejects_unknown_account",
+        "test_ac_02_rejects_zero_padded_id",
+        "test_ac_02_rejects_non_boolean_reseed",
+    ),
+    "AC-03": (
+        "test_ac_03_accepts_real_card_naming",
+        "test_ac_03_rejects_gap_in_card_numbers",
+        "test_ac_03_rejects_duplicate_id",
+        "test_ac_03_rejects_filename_without_id_segment",
+    ),
+    "AC-04": (
+        "test_ac_04_accepts_surface_vocabulary_table",
+        "test_ac_04_rejects_removed_family_surface",
+        "test_ac_04_rejects_wrong_table_header",
+        "test_ac_04_rejects_duplicate_surface_row",
+        "test_ac_04_rejects_prose_line_inside_marker",
+    ),
+    "AC-05": (
+        "test_ac_05_accepts_inventory_matching_cards",
+        "test_ac_05_rejects_card_missing_from_inventory",
+        "test_ac_05_rejects_inventory_row_without_card",
+        "test_ac_05_rejects_surface_outside_vocabulary",
+        "test_ac_05_rejects_inventory_table_with_extra_column",
+    ),
+    "AC-06": (
+        "test_ac_06_accepts_family_surface_pin",
+        "test_ac_06_rejects_reassigned_family_surface",
+    ),
+    "AC-07": (
+        "test_ac_07_accepts_real_dependencies",
+        "test_ac_07_rejects_dependency_cycle",
+        "test_ac_07_rejects_self_dependency",
+        "test_ac_07_rejects_unknown_dependency",
+    ),
+    "AC-08": (
+        "test_ac_08_accepts_dependency_without_reseed",
+        "test_ac_08_rejects_reseed_with_dependency",
+    ),
+    "AC-09": (
+        "test_ac_09_accepts_serial_depending_on_parallel",
+        "test_ac_09_rejects_parallel_depending_on_serial",
+    ),
+    "AC-10": (
+        "test_ac_10_accepts_not_applicable_card",
+        "test_ac_10_rejects_steps_in_not_applicable_card",
+        "test_ac_10_rejects_reason_on_applicable_card",
+        "test_ac_10_rejects_missing_reason_on_not_applicable_card",
+    ),
+    "AC-11": (
+        "test_ac_11_accepts_matching_heading",
+        "test_ac_11_rejects_heading_mismatch",
+        "test_ac_11_rejects_missing_heading",
+    ),
+    "AC-12": (
+        "test_ac_12_accepts_real_cards_without_legacy_meta",
+        "test_ac_12_rejects_legacy_meta_section",
+        "test_ac_12_rejects_legacy_purpose_bullet",
+    ),
+    "AC-13": (
+        "test_ac_13_accepts_covers_shape",
+        "test_ac_13_rejects_duplicate_array_element",
+        "test_ac_13_rejects_malformed_route_token",
+        "test_ac_13_rejects_malformed_capability_token",
+    ),
+    "AC-14": (
+        "test_ac_14_accepts_complete_partition",
+        "test_ac_14_accepts_explicit_subject_to_test_mapping",
+        "test_ac_14_rejects_missing_invariant",
+        "test_ac_14_rejects_duplicate_classification",
+        "test_ac_14_rejects_adopted_without_bearer",
+        "test_ac_14_rejects_unknown_bearer_id",
+        "test_ac_14_rejects_wrong_total",
+    ),
+    "AC-15": (
+        "test_ac_15_accepts_canonical_body",
+        "test_ac_15_rejects_missing_purpose_section",
+        "test_ac_15_rejects_duplicate_purpose_section",
+        "test_ac_15_rejects_empty_purpose_section",
+        "test_ac_15_rejects_missing_deviation_section",
+        "test_ac_15_rejects_duplicate_deviation_section",
+        "test_ac_15_rejects_empty_deviation_section",
+    ),
+}
+
+INVARIANT_TO_SUBJECT = {
+    "A1": "AC-01", "A2": "AC-01", "A3": "AC-01", "A4": "AC-01", "A5": "AC-01", "A6": "AC-01",
+    "B1": "AC-01",
+    "B2": "AC-02", "B5": "AC-02", "B6": "AC-02", "B7": "AC-02", "B10": "AC-02",
+    "B11": "AC-02", "B12": "AC-02",
+    "B3": "AC-11",
+    "B4": "AC-05",
+    "B8": "AC-10",
+    "B9": "AC-07",
+    "B13": "AC-13", "B14": "AC-13", "B15": "AC-13", "B16": "AC-13",
+    "C1": "AC-04", "C2": "AC-04", "C3": "AC-04",
+    "C4": "AC-05", "C5": "AC-05",
+    "D1": "AC-06", "D2": "AC-06",
+    "D3": "AC-03", "D4": "AC-03", "D5": "AC-03",
+    "D7": "AC-05",
+    "E1": "AC-07", "E2": "AC-08", "E3": "AC-09", "E4": "AC-05",
+    "F2": "AC-10",
+    "H1": "AC-12",
+    "J1": "AC-11", "J2": "AC-15", "J3": "AC-15",
+}
+
+
+def partition_violations(
+    all_invariants: tuple[str, ...],
+    adopted: tuple[str, ...],
+    differences: tuple[str, ...],
+    not_adopted: tuple[str, ...],
+    bearers: tuple[str, ...],
+    expected_total: int,
+) -> list[str]:
+    """分類と担い手の整合を見て違反の並びを返す (実データにも合成入力にも使う純関数)。"""
+    violations: list[str] = []
+    if len(all_invariants) != expected_total:
+        violations.append(f"全数が {expected_total} 件でない: {len(all_invariants)}")
+    if len(all_invariants) != len(set(all_invariants)):
+        violations.append("全数の一覧に重複がある")
+
+    classified = [*adopted, *differences, *not_adopted]
+    if len(classified) != len(set(classified)):
+        violations.append("分類が重複している")
+    if set(classified) != set(all_invariants):
+        missing = sorted(set(all_invariants) - set(classified))
+        extra = sorted(set(classified) - set(all_invariants))
+        violations.append(f"分類の和が全数と一致しない (不足 {missing} / 余分 {extra})")
+
+    for key in adopted:
+        if key not in bearers:
+            violations.append(f"担い手の無い採用項目: {key}")
+    for key in sorted(set(bearers) - set(all_invariants)):
+        violations.append(f"担い手集合に未知の ID: {key}")
+
+    return violations
+
+
+# --------------------------------------------------------------------------- #
+# 合成入力 (実ファイル母集団が 0 件になりうる違反分岐を必ず走らせる)
+# --------------------------------------------------------------------------- #
+BASE_VALUES: dict[str, object] = {
+    "id": "S1",
+    "title": "見本カード",
+    "surface": "signup_funnel",
+    "lane": "parallel_browser",
+    "priority": "P1",
+    "applicability": "applicable",
+    "depends_on": [],
+    "reseed_before": True,
+    "accounts": ["guest"],
+    "setup": [],
+    "covers_screens": ["home"],
+    "covers_operations": ["login.store"],
+    "covers_capabilities": ["AUTH-01"],
+}
+BASE_BODY = (
+    "# S1: 見本カード\n"
+    "\n"
+    "## 目的\n"
+    "見本のカードである。\n"
+    "\n"
+    "## 手順\n"
+    "1. 開く → 見える\n"
+    "\n"
+    "## 逸脱アイデア (--deviate 時)\n"
+    "- 二重送信してみる\n"
+)
+BASE_SURFACES = list(FAMILY_REQUIRED_SURFACES)
+
+
+def render_value(value: object) -> str:
+    if isinstance(value, bool):
+        return "true" if value else "false"
+    if isinstance(value, list):
+        return "[" + ", ".join(str(v) for v in value) + "]"
+
+    return str(value)
+
+
+def render_front_matter(values: dict[str, object], order: list[str] | None = None) -> str:
+    keys = order if order is not None else [k for k in sfm.CANONICAL_KEYS if k in values]
+
+    return "---\n" + "".join(f"{k}: {render_value(values[k])}\n" for k in keys) + "---\n"
+
+
+def build_card(
+    *,
+    values: dict[str, object] | None = None,
+    order: list[str] | None = None,
+    body: str | None = None,
+    filename: str = "S1-sample.md",
+    raw: str | None = None,
+) -> tuple[sfm.Card, list[str]]:
+    text = raw if raw is not None else render_front_matter(
+        dict(BASE_VALUES) if values is None else values, order
+    ) + "\n" + (BASE_BODY if body is None else body)
+
+    return sfm.parse_card(filename, text)
+
+
+def synthetic_violations(**kwargs: object) -> list[str]:
+    """合成カード 1 枚の文法違反と中身の違反を合わせて返す。"""
+    card, parse = build_card(**kwargs)  # type: ignore[arg-type]
+
+    return parse + card_violations(card, BASE_SURFACES)
+
+
+# --------------------------------------------------------------------------- #
+# 実データ (母集団)
+# --------------------------------------------------------------------------- #
+class StoryFrontMatterContractTest(unittest.TestCase):
+    """カードの書式契約。実データと合成入力の両方を同じ純関数で判定する。"""
+
+    @classmethod
+    def setUpClass(cls) -> None:
+        cls.readme = README_PATH.read_text(encoding="utf-8")
+        cls.cards, cls.parse_violations = sfm.read_cards(STORIES_DIR)
+        cls.surfaces, cls.surface_violations = surface_vocabulary(cls.readme)
+        cls.inventory, cls.inventory_violations = card_inventory(cls.readme)
+
+    # ----------------------------------------------------------------- #
+    # 母集団の非空 (走査が空振りしていないこと)
+    # ----------------------------------------------------------------- #
+    def test_population_is_not_empty(self) -> None:
+        """カード母集団と表 A / 表 B のデータ行がいずれも空でないこと。"""
+        self.assertNotEqual([], self.cards, "カード母集団が 0 件 (走査根が壊れている)")
+        self.assertNotEqual([], self.surfaces)
+        self.assertNotEqual([], self.inventory)
+
+    def test_real_cards_parse_without_violations(self) -> None:
+        """実カードの前付けが制限文法で読めること。"""
+        self.assertEqual([], self.parse_violations)
+
+    def test_real_cards_have_no_content_violations(self) -> None:
+        """実カードの中身が契約に反していないこと。"""
+        violations: list[str] = []
+        for card in self.cards:
+            violations += card_violations(card, self.surfaces)
+        self.assertEqual([], violations)
+
+    def test_real_cards_have_no_graph_violations(self) -> None:
+        """番号規約と依存の契約に反していないこと。"""
+        self.assertEqual([], graph_violations(self.cards))
+
+    # ----------------------------------------------------------------- #
+    # AC-01: 制限文法 + 必須 key 全数 + 正準順序 + 重複なし
+    # ----------------------------------------------------------------- #
+    def test_ac_01_accepts_canonical_front_matter(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_01_accepts_horizontal_rule_in_body(self) -> None:
+        """本文中の水平線で前付けが閉じたことにならないこと (A1)。"""
+        body = BASE_BODY.replace("## 手順\n", "## 手順\n---\n")
+        card, parse = build_card(body=body)
+        self.assertEqual([], parse)
+        self.assertEqual("S1", card.front_matter["id"])
+
+    def test_ac_01_rejects_quoted_scalar(self) -> None:
+        values = dict(BASE_VALUES, title='"見本カード"')
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_01_rejects_duplicate_key(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "id: S1\n", "id: S1\nid: S2\n"
+        ) + "\n" + BASE_BODY
+        self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_key_out_of_canonical_order(self) -> None:
+        order = [k for k in sfm.CANONICAL_KEYS if k in BASE_VALUES]
+        order[0], order[1] = order[1], order[0]
+        self.assertNotEqual([], synthetic_violations(order=order))
+
+    def test_ac_01_rejects_missing_required_key(self) -> None:
+        values = {k: v for k, v in BASE_VALUES.items() if k != "priority"}
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_01_rejects_unknown_key(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "---\nid: S1\n", "---\nid: S1\nowner: kento\n"
+        ) + "\n" + BASE_BODY
+        self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_blank_and_comment_line(self) -> None:
+        for injected in ("\n", "# コメント\n"):
+            with self.subTest(injected=injected):
+                raw = render_front_matter(dict(BASE_VALUES)).replace(
+                    "id: S1\n", "id: S1\n" + injected
+                ) + "\n" + BASE_BODY
+                self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    def test_ac_01_rejects_missing_delimiter(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES))[4:] + "\n" + BASE_BODY
+        self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    # ----------------------------------------------------------------- #
+    # AC-02: 閉じた語彙と値の書式
+    # ----------------------------------------------------------------- #
+    def test_ac_02_accepts_real_cards_vocabulary(self) -> None:
+        for card in self.cards:
+            with self.subTest(card=card.filename):
+                self.assertIn(card.front_matter["lane"], sfm.LANE_VOCABULARY)
+                self.assertIn(card.front_matter["priority"], sfm.PRIORITY_VOCABULARY)
+                self.assertIn(card.front_matter["applicability"], sfm.APPLICABILITY_VOCABULARY)
+
+    def test_ac_02_rejects_unknown_lane(self) -> None:
+        self.assertNotEqual([], synthetic_violations(values=dict(BASE_VALUES, lane=("serial"))))
+
+    def test_ac_02_rejects_unknown_priority(self) -> None:
+        self.assertNotEqual([], synthetic_violations(values=dict(BASE_VALUES, priority="P0")))
+
+    def test_ac_02_rejects_unknown_account(self) -> None:
+        values = dict(BASE_VALUES, accounts=["photographer"])
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_02_rejects_zero_padded_id(self) -> None:
+        values = dict(BASE_VALUES, id="S01")
+        body = BASE_BODY.replace("# S1: ", "# S01: ")
+        self.assertNotEqual([], synthetic_violations(values=values, body=body))
+
+    def test_ac_02_rejects_non_boolean_reseed(self) -> None:
+        raw = render_front_matter(dict(BASE_VALUES)).replace(
+            "reseed_before: true", "reseed_before: yes"
+        ) + "\n" + BASE_BODY
+        self.assertNotEqual([], synthetic_violations(raw=raw))
+
+    # ----------------------------------------------------------------- #
+    # AC-03: 命名・id の一意性・欠番
+    # ----------------------------------------------------------------- #
+    def test_ac_03_accepts_real_card_naming(self) -> None:
+        self.assertEqual([], graph_violations(self.cards))
+
+    def test_ac_03_rejects_gap_in_card_numbers(self) -> None:
+        first, _ = build_card(filename="S1-a.md")
+        third, _ = build_card(
+            values=dict(BASE_VALUES, id="S3"),
+            body=BASE_BODY.replace("# S1: ", "# S3: "),
+            filename="S3-c.md",
+        )
+        self.assertNotEqual([], graph_violations([first, third]))
+
+    def test_ac_03_rejects_duplicate_id(self) -> None:
+        first, _ = build_card(filename="S1-a.md")
+        clone, _ = build_card(filename="S1-b.md")
+        self.assertNotEqual([], graph_violations([first, clone]))
+
+    def test_ac_03_rejects_filename_without_id_segment(self) -> None:
+        card, _ = build_card(filename="story-one.md")
+        self.assertNotEqual([], graph_violations([card]))
+
+    # ----------------------------------------------------------------- #
+    # AC-04: 表 A の構造契約と家系必須 11 語
+    # ----------------------------------------------------------------- #
+    def test_ac_04_accepts_surface_vocabulary_table(self) -> None:
+        self.assertEqual([], self.surface_violations)
+        for required in FAMILY_REQUIRED_SURFACES:
+            self.assertIn(required, self.surfaces)
+
+    def test_ac_04_rejects_removed_family_surface(self) -> None:
+        broken = self.readme.replace("| `public_share` |", "| `shared_link` |")
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_wrong_table_header(self) -> None:
+        broken = self.readme.replace("| surface | 面 | 由来 |", "| surface | 面 |")
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_duplicate_surface_row(self) -> None:
+        broken = self.readme.replace(
+            "| `billing` | 課金 | テンプレート同梱 |",
+            "| `billing` | 課金 | テンプレート同梱 |\n| `billing` | 課金 (写し) | テンプレート同梱 |",
+        )
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    def test_ac_04_rejects_prose_line_inside_marker(self) -> None:
+        """区間の中の非表行を読み飛ばさないこと (読み飛ばしを一切しない)。"""
+        broken = self.readme.replace(
+            "| `billing` | 課金 | テンプレート同梱 |",
+            "| `billing` | 課金 | テンプレート同梱 |\nこの語彙はあとで整理する。",
+        )
+        _, violations = surface_vocabulary(broken)
+        self.assertNotEqual([], violations)
+
+    # ----------------------------------------------------------------- #
+    # AC-05: surface の所属と表 B とカードの 1 対 1
+    # ----------------------------------------------------------------- #
+    def inventory_mismatch(self, inventory: list[tuple[str, str]], cards: list[sfm.Card]) -> list[str]:
+        """表 B と実在カードの 1 対 1 を判定する (C5 / D7)。"""
+        violations: list[str] = []
+        declared = dict(inventory)
+        actual = {
+            str(c.front_matter.get("id")): str(c.front_matter.get("surface")) for c in cards
+        }
+        for card_id in sorted(set(actual) - set(declared)):
+            violations.append(f"表 B に載っていないカード: {card_id}")
+        for card_id in sorted(set(declared) - set(actual)):
+            violations.append(f"表 B の行に対応するカードが無い: {card_id}")
+        for card_id in sorted(set(declared) & set(actual)):
+            if declared[card_id] != actual[card_id]:
+                violations.append(f"表 B とカードの surface が違う: {card_id}")
+
+        return violations
+
+    def test_ac_05_accepts_inventory_matching_cards(self) -> None:
+        self.assertEqual([], self.inventory_violations)
+        self.assertEqual([], self.inventory_mismatch(self.inventory, self.cards))
+        for card in self.cards:
+            self.assertIn(card.front_matter["surface"], self.surfaces)
+
+    def test_ac_05_rejects_card_missing_from_inventory(self) -> None:
+        extra, _ = build_card(
+            values=dict(BASE_VALUES, id="S8", surface="result_view"),
+            body=BASE_BODY.replace("# S1: ", "# S8: "),
+            filename="S8-result.md",
+        )
+        self.assertNotEqual([], self.inventory_mismatch(self.inventory, [*self.cards, extra]))
+
+    def test_ac_05_rejects_inventory_row_without_card(self) -> None:
+        broken = self.readme.replace(
+            "| S7 | `authz_boundary` |",
+            "| S7 | `authz_boundary` |\n| S8 | `result_view` |",
+        )
+        inventory, violations = card_inventory(broken)
+        self.assertEqual([], violations)
+        self.assertNotEqual([], self.inventory_mismatch(inventory, self.cards))
+
+    def test_ac_05_rejects_surface_outside_vocabulary(self) -> None:
+        values = dict(BASE_VALUES, surface="not_registered")
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_05_rejects_inventory_table_with_extra_column(self) -> None:
+        """表 B に lane / priority / depends_on の写しを置けないこと (C4 / E4)。"""
+        broken = self.readme.replace("| id | surface |\n|---|---|", "| id | surface | lane |\n|---|---|---|")
+        _, violations = card_inventory(broken)
+        self.assertNotEqual([], violations)
+
+    # ----------------------------------------------------------------- #
+    # AC-06: 家系固定 (id, surface)
+    # ----------------------------------------------------------------- #
+    def family_pin_actual(self, cards: list[sfm.Card]) -> tuple[tuple[str, str], ...]:
+        return tuple(sorted(
+            (str(card.front_matter["id"]), str(card.front_matter["surface"]))
+            for card in cards
+            if str(card.front_matter.get("id")) in PINNED_IDS
+        ))
+
+    def test_ac_06_accepts_family_surface_pin(self) -> None:
+        """S1 から S7 の (id, surface) を家系で固定する。
+
+        番号は識別子であって意味を持たないが、**既存番号の面を付け替えない**ことが
+        家系固定の本体である (D1 / D2)。検査側のリテラルと完全一致で突き合わせる。
+
+        ★ pin の対象は PINNED_IDS に属するカードだけである。S8 以降を正規の手続き
+          (表 A に面を足し、表 B に 1 行、カードを 1 枚) で足しても落ちない。
+        """
+        self.assertEqual(tuple(sorted(FAMILY_SURFACE_PIN)), self.family_pin_actual(self.cards))
+
+    def test_ac_06_rejects_reassigned_family_surface(self) -> None:
+        swapped, _ = build_card(values=dict(BASE_VALUES, surface="billing"))
+        self.assertNotEqual(
+            tuple(sorted(FAMILY_SURFACE_PIN)),
+            self.family_pin_actual([swapped]),
+        )
+
+    # ----------------------------------------------------------------- #
+    # AC-07 / AC-08 / AC-09: 依存と実行方式
+    # ----------------------------------------------------------------- #
+    def two_cards(self, first: dict[str, object], second: dict[str, object]) -> list[sfm.Card]:
+        a, _ = build_card(
+            values=first, body=BASE_BODY.replace("# S1: ", f"# {first['id']}: "),
+            filename=f"{first['id']}-a.md",
+        )
+        b, _ = build_card(
+            values=second, body=BASE_BODY.replace("# S1: ", f"# {second['id']}: "),
+            filename=f"{second['id']}-b.md",
+        )
+
+        return [a, b]
+
+    def test_ac_07_accepts_real_dependencies(self) -> None:
+        self.assertEqual([], graph_violations(self.cards))
+
+    def test_ac_07_rejects_dependency_cycle(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", depends_on=["S2"], reseed_before=False),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    def test_ac_07_rejects_self_dependency(self) -> None:
+        card, _ = build_card(values=dict(BASE_VALUES, depends_on=["S1"], reseed_before=False))
+        self.assertNotEqual([], graph_violations([card]))
+
+    def test_ac_07_rejects_unknown_dependency(self) -> None:
+        card, _ = build_card(values=dict(BASE_VALUES, depends_on=["S9"], reseed_before=False))
+        self.assertNotEqual([], graph_violations([card]))
+
+    def test_ac_08_accepts_dependency_without_reseed(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1"),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertEqual([], graph_violations(cards))
+
+    def test_ac_08_rejects_reseed_with_dependency(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1"),
+            dict(BASE_VALUES, id="S2", depends_on=["S1"], reseed_before=True),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    def test_ac_09_accepts_serial_depending_on_parallel(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", lane="parallel_browser"),
+            dict(BASE_VALUES, id="S2", lane="serial_parent", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertEqual([], graph_violations(cards))
+
+    def test_ac_09_rejects_parallel_depending_on_serial(self) -> None:
+        cards = self.two_cards(
+            dict(BASE_VALUES, id="S1", lane="serial_parent"),
+            dict(BASE_VALUES, id="S2", lane="parallel_browser", depends_on=["S1"], reseed_before=False),
+        )
+        self.assertNotEqual([], graph_violations(cards))
+
+    # ----------------------------------------------------------------- #
+    # AC-10: not_applicable カードの中身
+    # ----------------------------------------------------------------- #
+    NOT_APPLICABLE_VALUES = {
+        "id": "S1",
+        "title": "見本カード",
+        "surface": "signup_funnel",
+        "lane": "parallel_browser",
+        "priority": "P3",
+        "applicability": "not_applicable",
+        "not_applicable_reason": "本アプリに該当する面が無いため実走しない",
+        "depends_on": [],
+        "reseed_before": False,
+        "accounts": [],
+        "setup": [],
+        "covers_screens": [],
+        "covers_operations": [],
+        "covers_capabilities": [],
+    }
+    NOT_APPLICABLE_BODY = (
+        "# S1: 見本カード\n"
+        "\n"
+        "## 目的\n"
+        "該当面が無いことを記録として残す。\n"
+        "\n"
+        "## 逸脱アイデア (--deviate 時)\n"
+        "- 該当面が生えていないか確認する\n"
+    )
+
+    def test_ac_10_accepts_not_applicable_card(self) -> None:
+        self.assertEqual([], synthetic_violations(
+            values=dict(self.NOT_APPLICABLE_VALUES), body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    def test_ac_10_rejects_steps_in_not_applicable_card(self) -> None:
+        body = self.NOT_APPLICABLE_BODY.replace(
+            "## 逸脱アイデア", "## 手順\n1. 開く\n\n## 逸脱アイデア"
+        )
+        self.assertNotEqual([], synthetic_violations(
+            values=dict(self.NOT_APPLICABLE_VALUES), body=body,
+        ))
+
+    def test_ac_10_rejects_reason_on_applicable_card(self) -> None:
+        values = dict(self.NOT_APPLICABLE_VALUES, applicability="applicable")
+        self.assertNotEqual([], synthetic_violations(
+            values=values, body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    def test_ac_10_rejects_missing_reason_on_not_applicable_card(self) -> None:
+        values = {
+            k: v for k, v in self.NOT_APPLICABLE_VALUES.items() if k != sfm.CONDITIONAL_KEY
+        }
+        self.assertNotEqual([], synthetic_violations(
+            values=values, body=self.NOT_APPLICABLE_BODY,
+        ))
+
+    # ----------------------------------------------------------------- #
+    # AC-11: H1 見出しと前付けの機械一致
+    # ----------------------------------------------------------------- #
+    def test_ac_11_accepts_matching_heading(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_11_rejects_heading_mismatch(self) -> None:
+        body = BASE_BODY.replace("# S1: 見本カード", "# S1: 別のタイトル")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_11_rejects_missing_heading(self) -> None:
+        body = BASE_BODY.replace("# S1: 見本カード\n\n", "")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    # ----------------------------------------------------------------- #
+    # AC-12: 旧メタ節が残っていない
+    # ----------------------------------------------------------------- #
+    def test_ac_12_accepts_real_cards_without_legacy_meta(self) -> None:
+        for card in self.cards:
+            with self.subTest(card=card.filename):
+                for pattern in LEGACY_META_PATTERNS:
+                    for line in card.body.splitlines():
+                        self.assertFalse(line.startswith(pattern), line)
+
+    def test_ac_12_rejects_legacy_meta_section(self) -> None:
+        body = BASE_BODY + "\n## このストーリーで消化する screens / operations\n- screens: home\n"
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_12_rejects_legacy_purpose_bullet(self) -> None:
+        for legacy in ("- 前提状態: ゲスト\n", "- 目的: 何かする\n"):
+            with self.subTest(legacy=legacy):
+                body = BASE_BODY.replace("## 目的\n", "## 目的\n" + legacy)
+                self.assertNotEqual([], synthetic_violations(body=body))
+
+    # ----------------------------------------------------------------- #
+    # AC-13: covers_* は形だけを見る (実在は目録側)
+    # ----------------------------------------------------------------- #
+    def test_ac_13_accepts_covers_shape(self) -> None:
+        """実在しない route 名でも**形が正しければ**ここでは通ること (B16)。"""
+        values = dict(BASE_VALUES, covers_screens=["not.a.real.route"])
+        self.assertEqual([], synthetic_violations(values=values))
+
+    def test_ac_13_rejects_duplicate_array_element(self) -> None:
+        values = dict(BASE_VALUES, covers_operations=["login.store", "login.store"])
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_13_rejects_malformed_route_token(self) -> None:
+        values = dict(BASE_VALUES, covers_screens=["Home Page"])
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    def test_ac_13_rejects_malformed_capability_token(self) -> None:
+        values = dict(BASE_VALUES, covers_capabilities=["auth-1"])
+        self.assertNotEqual([], synthetic_violations(values=values))
+
+    # ----------------------------------------------------------------- #
+    # AC-14: 全数点呼
+    # ----------------------------------------------------------------- #
+    def test_ac_14_accepts_complete_partition(self) -> None:
+        """実データの 58 項目が 3 分類へ過不足なく割れ、採用項目に担い手が居ること。"""
+        self.assertEqual([], partition_violations(
+            ALL_INVARIANTS, ADOPTED, DIFFERENCES, NOT_ADOPTED,
+            (*STORY_SIDE, *INVENTORY_SIDE, *NON_MECHANICAL), EXPECTED_TOTAL,
+        ))
+        # 非機械保証は「保証しないもの」の節と 1 対 1 にする (黙って落とさない)。
+        self.assertEqual(("E5", "G6"), NON_MECHANICAL)
+
+    def test_ac_14_accepts_explicit_subject_to_test_mapping(self) -> None:
+        """stories 側が担う項目が、実在する検査へ**明示的に**紐づいていること。
+
+        ★ 主題名からテスト名を**推測しない**。`AC-01` から作った `test_ac_01` は
+          実際の `test_ac_01_rejects_quoted_scalar` と一致せず、hasattr が常に偽になる。
+        """
+        for key in STORY_SIDE:
+            self.assertIn(key, INVARIANT_TO_SUBJECT, f"{key} に主題が無い")
+            self.assertIn(INVARIANT_TO_SUBJECT[key], SUBJECT_TO_TESTS)
+
+        for subject, names in SUBJECT_TO_TESTS.items():
+            for name in names:
+                self.assertTrue(callable(getattr(self, name, None)), f"{name} が実在しない")
+            self.assertTrue(any("accepts" in n for n in names), f"{subject} に正例が無い")
+            self.assertTrue(any("rejects" in n for n in names), f"{subject} に負例が無い")
+
+    def test_ac_14_rejects_missing_invariant(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1", "A2"), ("A1",), (), (), ("A1",), 2,
+        ))
+
+    def test_ac_14_rejects_duplicate_classification(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), ("A1",), (), ("A1",), 1,
+        ))
+
+    def test_ac_14_rejects_adopted_without_bearer(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), (), 1,
+        ))
+
+    def test_ac_14_rejects_unknown_bearer_id(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), ("A1", "Z9"), 1,
+        ))
+
+    def test_ac_14_rejects_wrong_total(self) -> None:
+        self.assertNotEqual([], partition_violations(
+            ("A1",), ("A1",), (), (), ("A1",), 58,
+        ))
+
+    # ----------------------------------------------------------------- #
+    # AC-15: カード本文の確定形
+    # ----------------------------------------------------------------- #
+    def test_ac_15_accepts_canonical_body(self) -> None:
+        self.assertEqual([], synthetic_violations())
+
+    def test_ac_15_rejects_missing_purpose_section(self) -> None:
+        for body in (
+            BASE_BODY.replace("## 目的\n見本のカードである。\n\n", ""),
+            BASE_BODY.replace("## 目的", "## 目的:"),
+        ):
+            with self.subTest(body=body[:40]):
+                self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_duplicate_purpose_section(self) -> None:
+        body = BASE_BODY + "\n## 目的\n2 つ目の目的。\n"
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_empty_purpose_section(self) -> None:
+        body = BASE_BODY.replace("## 目的\n見本のカードである。\n", "## 目的\n\n")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_duplicate_deviation_section(self) -> None:
+        body = BASE_BODY + "\n## 逸脱アイデア (--deviate 時)\n- もう 1 つ\n"
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_empty_deviation_section(self) -> None:
+        body = BASE_BODY.replace("## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n",
+                                 "## 逸脱アイデア (--deviate 時)\n\n")
+        self.assertNotEqual([], synthetic_violations(body=body))
+
+    def test_ac_15_rejects_missing_deviation_section(self) -> None:
+        for body in (
+            BASE_BODY.replace("## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n", ""),
+            BASE_BODY.replace("## 逸脱アイデア (--deviate 時)", "## 逸脱アイデア"),
+        ):
+            with self.subTest(body=body[-40:]):
+                self.assertNotEqual([], synthetic_violations(body=body))
+
+
+class ReadCardsTest(unittest.TestCase):
+    """候補母集団の作り方 (パターンで発見しない)。"""
+
+    def test_readme_is_excluded_and_others_are_not(self) -> None:
+        self.assertEqual(frozenset({"README.md"}), sfm.EXCLUDED_FILENAMES)
+        names = {card.filename for card in sfm.read_cards(STORIES_DIR)[0]}
+        self.assertNotIn("README.md", names)
+        self.assertEqual(7, len(names), sorted(names))
+
+    def test_missing_directory_is_a_read_error(self) -> None:
+        with self.assertRaises(sfm.StoryReadError):
+            sfm.read_cards(STORIES_DIR / "no-such-dir")
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 14198914..c412fd03 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 36 件
+登録エントリ: 37 件
 
 ## 記録の原則
 
@@ -717,10 +717,10 @@ ## D14 実行した route の記録をアプリ側の観測器で採る (退避
 
 | 行 | 内容 |
 |---|---|
-| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` |
-| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する |
-| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う |
-| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき |
+| 対象パス | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `bootstrap/app.php` / `config/bughunt.php` / `.claude/skills/app-bug-hunt/coverage/build_executed.py` / `.claude/skills/app-bug-hunt/coverage/correlate.py` / `.claude/skills/app-bug-hunt/coverage/test_correlate.py` |
+| 業務要件起因の説明 | 記録が採れていないことと本当に叩けていないことを取り違えると操作到達の一覧そのものが嘘になるため、遮断 middleware の内側で 1 要求 1 行を機械記録する。併せて、割当列が複数値になった目録を照合器が取り違えずに読む |
+| 揃え続ける不変条件と保証機構 | 主入力が揃わない走行は成功にしない。`BughuntExecutedRouteOrderingTest` が記録器の位置を、集約と照合の 2 つの Python ツールが終了コード 3 を担う。割当セルの分解は `test_correlate.py` が値域の両方向で固定する |
+| 再判定の条件 | 家系の正典が退避 → 正規化 → route 名解決の 3 段へ揃える裁定を出したとき / web グループ外の面を分母に載せるとき / 家系の正典が割当列の分解を実装したとき |
 | 決めた日 | 2026-08-15 |
 | 決めた人 | 開発者 |
 | 根拠 | T164 |
@@ -733,6 +733,7 @@ ## D14 実行した route の記録をアプリ側の観測器で採る (退避
 | 採取の起動 | 走行中の LLM (探索エージェント) が退避コマンドを呼ぶ | 起動時に `provision` が env で仕込み、以後は無条件 |
 | 遮断された要求の扱い | 通信履歴なので 302/403 も「叩いた」側に残り、後段で除外しきれない | 遮断 middleware より**内側**に置いてあるため、そもそも記録に現れない |
 | 主入力が欠けたとき | 照合器が「全 in_scope を未実行 candidate」として出力し 0 で終わる | **終了コード 3 で落ちる** (worklist を出さない) |
+| 目録の割当列の読み方 (理由 2) | セルをそのままキーにするので `S3 S7` の行は `S3` の finding と一致しない | **セルを検証してから分解**し、各 story へ索引する (単一値の挙動は不変。正典に無い上乗せ = 家系への還流候補) |
 
 ### なぜ正当な差分か(logic-driven)
 
@@ -770,6 +771,18 @@ ### 揃えている不変条件(これは保証し続ける)
 - 記録器が既定 no-op であること (env 既定 false + production 除外) と ok/blocked の写像は
   `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` が実 HTTP 要求で固定する
 
+理由 2 (割当列の分解) が揃え続けるのは次である。
+
+> 「**目録の割当列に載ったカードは、すべてその finding の索引先になる**」
+
+- 割当セルの値域 (`S{n}` を番号の昇順で半角空白 1 つ区切り、または `-`) は
+  書き出し側 (`scripts/bug-hunt-inventory.py`) が自分の出力を突き合わせ、
+  読み手 (`correlate.py`) が `fullmatch` で強制する。**寛容に正規化しない**
+- 契約外のセル (前後空白 / 連続空白 / 降順 / 重複 / 未知の綴り) は照合器が
+  **終了コード 3** で落ちる (目録の手編集と生成器の故障を黙って進めない)
+- 両側の定数が一致することと、生成側が書くセルを読み手が同じ値へ分解することは
+  `scripts/tests/test_bug_hunt_inventory.py` が同一ケースの列挙で固定する
+
 ### 保証しないもの (誇張しない)
 
 - **web グループ外は観測しない** (`api/*` / Filament `/admin` / MCP)。分母に載っていれば
@@ -777,6 +790,9 @@ ### 保証しないもの (誇張しない)
 - **部分欠測は検出しない**。分かるのは「名前付き route の行が 1 件も無い」「別 run が混ざった」
   「失敗マーカーが残せた」まで
 - **偽造耐性は無い**。記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない
+- 割当セルに書かれたカードが**実在するか**は照合器では見ない (目録は生成物であり、
+  割当列は実在するカードの前付けからしか作られない。手編集で紛れ込んだ id は
+  目録の byte 一致検査が落とす)
 
 ### 関連
 
@@ -1134,7 +1150,7 @@ ## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 
 
 | 行 | 内容 |
 |---|---|
-| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` |
+| 対象パス | `scripts/bug-hunt-inventory.py` / `app/Console/Commands/Bughunt/InventoryScanCommand.php` / `.claude/skills/app-bug-hunt/inventory/annotations.toml` / `scripts/tests/test_bug_hunt_inventory.py` / `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` |
 | 業務要件起因の説明 | 機能カタログの id 列が所見記録の語彙の正本であり、Python ツールを標準ライブラリだけで書く規約から注釈は TOML になる |
 | 揃え続ける不変条件と保証機構 | 目録は実装と注釈から再生成でき、ずれていたら CI が落ちる。`BugHuntInventoryCheckInvariantTest` と生成器の自己テストが 4 段の判定を固定する |
 | 再判定の条件 | 家系の正典が id 列を持つ形へ変わったとき / Python に依存を足す裁定が出たとき / 中間 JSON を読む道具が家系に現れたとき |
@@ -1153,6 +1169,9 @@ ## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 
 | 機能カタログ (`capability-catalog.md`) | 生成物。3 列は 機能 / 対応する画面 / 対応する操作 | **生成しない**。3 列は `id` / `機能 (actor→outcome)` / `代表機構 (route name)` を維持し、参照整合だけを検査する |
 | 注釈ファイル | `inventory/annotations.yaml` | **`inventory/annotations.toml`** |
 | 中間成果物 | `inventory/inventory.json` をコミットする | **持たない** (生成・検査の実行中にだけ存在する) |
+| 割当の正本 | カードの前付け (`covers_screens` / `covers_operations`) | **同じ** (2026-08-23 に注釈の `story` を撤去して一本化した。以前は注釈側が正本だった) |
+| `covers_screens` の母集合 | `kind` が `screen` / `read` / `redirect` の web route | **safe method (GET / HEAD / OPTIONS) の web route** (`kind` の語彙が `画面` / `JSON` で違うため `kind` に依存させない) |
+| `covers_capabilities` の検査 | 実在 / 欄の意味 / 分母 / 被覆の 4 段 | **実在・形・一意まで** (機能カタログが継承宣言の欄 `no_route` / `coverage_surface` / `covered_via` を持たないため、分母・被覆は見ない) |
 
 ### なぜ正当な差分か (logic-driven)
 
@@ -1178,9 +1197,11 @@ ### 揃えている不変条件 (これは保証し続ける)
 | 不変条件 | 担い手 |
 |---|---|
 | 抽出が成功し、宣言した抽出条件で走り、母集合が 0 件でないこと (段 1) | `scripts/bug-hunt-inventory.py` (exit 2) / `scripts/tests/test_bug_hunt_inventory.py` |
-| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない |
+| 注釈の集合が面の集合と一致し、語彙・必須・理由の長さを満たすこと (段 2) | 同上 (exit 3)。未注釈も残置注釈も許さない。割当を注釈へ書き戻す道は未知の項目として塞ぐ |
+| 対象内 (区分が `外` でない) の route が 1 枚以上のカードの `covers_*` に載っていること (段 2) | 同上 (exit 3)。載せた route の実在・欄の意味・対象外でないことも見る |
 | 生成物が再生成の結果と byte 一致すること (段 3) | 同上 (exit 3)。手編集と再生成の忘れをまとめて捕まえる |
 | 機能カタログの代表機構が実在し、id が重複しないこと (段 4) | 同上 (exit 3) |
+| カードが挙げる capability が実在すること (段 4) | 同上 (exit 3)。**被覆漏れは見ない** |
 | 検査シェルが判定を持たず、終了コード 0 / 2 / 3 を実際に返すこと | `tests/Architecture/BugHuntInventoryCheckInvariantTest.php` (sandbox 実走) |
 | 生成器の自己テストが `composer test` の下で実走すること | `tests/Architecture/BughuntInventoryToolSelfTest.php` |
 | 抽出コマンドが事実だけを書き出すこと (面の判定を持たない) | `tests/Feature/Bughunt/InventoryScanCommandTest.php` |
@@ -1194,6 +1215,8 @@ ### 揃えている不変条件 (これは保証し続ける)
 必ず目録に入り注釈を要求される。
 注釈の**内容**の妥当性 (割当が適切か) は見ない。画面題名の欠落も検出しない。
 機能カタログの網羅性も見ない (代表機構の実在と id の一意性まで)。
+**割当が痩せたこと**も検出できない — 見るのは「1 枚以上のカードに載っていること」だけなので、
+ある route が `S3 S7` から `S3` へ減っても緑のままである (PR レビューの義務)。
 目録の母集合は T164 の記録器が観測しうる route の**部分集合**であり、両者は一致しない。
 
 ### 再検討の条件 (解消条件)
@@ -1201,6 +1224,7 @@ ### 再検討の条件 (解消条件)
 - 家系の正典が id 列を持つ形へ変わったとき (機能カタログの生成を採り直す)
 - 本リポジトリの Python に依存を足す裁定が出たとき (注釈を YAML へ寄せる)
 - 中間 JSON を読む道具が家系に現れたとき
+- 機能カタログが継承宣言の欄を持つ形になったとき (`covers_capabilities` の被覆判定を採り直す)
 
 ### 関連
 
@@ -2259,3 +2283,77 @@ ### 関連
 
 - 実装: `tests/Architecture/PasskeyPackageContractTest.php`
 - 設計: `devnotes/20260821-2015-auth-method-change-notification/`
+
+---
+
+## D40 シナリオカードの前付けは採るが、ステップ表の書式は採らない
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `.claude/skills/app-bug-hunt/stories/README.md` / `.claude/skills/app-bug-hunt/stories/test_story_front_matter.py` |
+| 業務要件起因の説明 | 所見台帳の finding は story までしか指さず step を指す欄を持たないため、ステップ識別子を入れても読む機械が 1 つも無い |
+| 揃え続ける不変条件と保証機構 | 前付けの制限文法・番号規約・表 A / 表 B との突合は `stories/test_story_front_matter.py` が強制し、`BughuntStoryToolSelfTest` が composer test の配線に載せる |
+| 再判定の条件 | `ledger/findings.schema.json` に step を指す欄が入ったとき / 家系の正典が t2 以降でステップ表を版の名前に含めたとき / `applicability` に `not_applicable` を取るカードを 1 枚でも置くことになったとき |
+| 決めた日 | 2026-08-22 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260823-0022-bughunt-story-front-matter-adoption/ |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+家系の正典 (機能台帳 `bughunt-story-front-matter` の t1) は、シナリオカードに制限文法の前付けを
+置いて割当の正本にし、併せて**手順をステップ表の書式で書く**ことまでを 1 つの契約にしている。
+本アプリは**前付けは全面的に採る**が、次の 2 点は採らないので登録する。
+
+| 外している契約 | 本アプリの形 |
+|---|---|
+| ステップ表の書式 (正準 4 列ヘッダ `step / 操作 / 期待 / 注目` / 疎な step 識別子 `{id}-{3 桁}` / 副ブロック / 期待欄・注目欄の書き分け) | **散文の番号付きリストのまま**置く |
+| `not_applicable` のカードを実走対象から外す契約 (`SKILL.md` 側が持つ) | **持たない** |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **step 識別子を読む機械が 1 つも無い**。所見台帳の schema
+   (`.claude/skills/app-bug-hunt/ledger/findings.schema.json`) は finding の位置を
+   `story_id` / `route_name` / `capability_tag` で指し、**step を指す欄を持たない**。
+   識別子を振っても照合器・抑制機構・目録のどれもそれで join しないので、
+   増えるのは「振り直してはいけない番号」という保守債務だけである。
+   正典が step を切ったのは finding が step を指す形を前提にしているためで、
+   その前提が本アプリには無い。
+2. **`not_applicable` の実走除外は該当カードが 0 枚である**。本アプリは家系必須 7 面の
+   すべてに実カードがあり、`not_applicable` を取るカードは 1 枚も無い。契約の置き場は
+   `SKILL.md` だが、同ファイルは採用時債務 (D34) に在るため触らない。
+   **今必要なものだけ作る** (思考原則 2) に従い、該当カードが生まれるまで置かない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「**割当の正本はカードの前付けだけであり、前付けは制限文法と番号規約を機械で満たす**」
+
+| 不変条件 | 担い手 |
+|---|---|
+| 前付けの制限文法 (区切り / 1 行 1 項目 / key の書式と重複 / 値の 3 形) | `.claude/skills/app-bug-hunt/stories/story_front_matter.py` |
+| 必須 13 key の全数と正準順序・閉じた語彙・値の書式 | `stories/test_story_front_matter.py` (AC-01 / AC-02) |
+| 番号規約 (命名 / 一意 / 欠番なし / 家系固定の `(id, surface)`) | 同上 (AC-03 / AC-06) |
+| 表 A の構造と家系必須 11 語・表 B とカードの 1 対 1 | 同上 (AC-04 / AC-05) |
+| 依存と実行方式の整合 (実在 / 自己参照 / 循環 / 初期化 / 直列待ち) | 同上 (AC-07 / AC-08 / AC-09) |
+| 本文の確定形と旧メタ節の不在 (二重の正本を残さない) | 同上 (AC-10 / AC-11 / AC-12 / AC-15) |
+| 採用した不変条件の全数点呼 (未割当 0 件・担い手の実在) | 同上 (AC-14) |
+| 上記が `composer test` の下で実走し、検査を削って緑にできないこと | `tests/Architecture/BughuntStoryToolSelfTest.php` (件数の下限 + 中核負例の成功表示) |
+
+### 保証しないもの (誇張しない)
+
+- **ステップ表を採らない帰結**: step 識別子の再採番の禁止・副ブロックの個数・期待欄と注目欄の
+  書き分けは 1 つも検査しない (概念ごと持たない)
+- 兆候番号 (`H{n}`) の意味をカードに書かないことは**文書規約であり機械検査しない**
+  (正典もこれ単独の検査は持たない)
+- `lane` / `depends_on` と `scripts/bug-hunt-shard.sh` の固定 fan-out マップの一致は見ない
+  (固定マップは前付けからの派生キャッシュ。**正典も未達**)
+- `accounts` と `database/seeders/ManualTestSeeder.php` の一致は見ない (正典も同じ)
+- `covers_*` の値の**実在**は前付け側では見ない (形だけ)。実在・欄の意味・分母の被覆は
+  目録側 (D20) の責務である
+
+### 関連
+
+- 実装: `.claude/skills/app-bug-hunt/stories/` (README.md / story_front_matter.py /
+  test_story_front_matter.py / S1〜S7 のカード)
+- gate: `tests/Architecture/BughuntStoryToolSelfTest.php` /
+  `tests/Support/Bughunt/StoryFrontMatterPins.php`
+- 設計: `devnotes/20260823-0022-bughunt-story-front-matter-adoption/`
diff --git a/scripts/bug-hunt-inventory.py b/scripts/bug-hunt-inventory.py
index 7a1dab65..4a27b432 100644
--- a/scripts/bug-hunt-inventory.py
+++ b/scripts/bug-hunt-inventory.py
@@ -9,12 +9,20 @@
     check    … 段 1 → 2 → 3 → 4 を通す。**1 バイトも書かない**
 
     段 1 (抽出)         抽出コマンドが成功し、宣言した抽出条件で走り、母集合が 0 件でない
-    段 2 (注釈)         注釈の集合 = 面の集合。語彙・必須・形式・複合 method を検査する
+    段 2 (注釈・割当)   注釈の集合 = 面の集合。語彙・必須・形式・複合 method を検査し、
+                        シナリオカードの前付け (`stories/S*.md` の covers_*) と突き合わせる
     段 3 (生成物)       メモリ上で再生成した内容と現物を byte 比較する
-    段 4 (機能カタログ) capability-catalog.md の代表機構が実在し、id が重複しない
+    段 4 (機能カタログ) capability-catalog.md の代表機構が実在し、id が重複しない。
+                        カードが挙げる capability が実在する
+
+**割当 (どのカードが route を消化するか) の正本はシナリオカードの前付け**である
+(規則の散文は `.claude/skills/app-bug-hunt/stories/README.md`)。注釈は route ごとの意味
+(`kind` / `kubun` / `reason`) だけを持ち、`story` は未知の項目として落ちる。
 
 終了コード: 0=一致 / 2=致命 (抽出不能・抽出条件不一致・母集合 0 件・空名・重複名・
-入力ファイル不在・壊れた TOML・想定外例外) / 3=ドリフト (段 2 / 3 / 4 の違反)。
+入力ファイル不在・壊れた TOML・**カードの置き場が無い / 候補 0 件 / 読み取り不能**・
+想定外例外) / 3=ドリフト (段 2 / 3 / 4 の違反。**前付けの形式違反・語彙違反・
+配列内重複・割当のドリフトはこちら**)。
 **1 と 4 以上は使わない** (argparse が引数エラーで返す 2 は「致命」の側に落ちる)。
 
 保証しないもの: 見るのは web group を宣言した面だけである。web group を宣言していない面
@@ -22,6 +30,10 @@
 逆に web group を宣言した route は面の除外表の 2 つを除き必ず目録に入り、注釈を要求される
 (実例: `webhooks.ses` は web 面なので操作表に載り、区分 `外` として理由付きで宣言されている)。
 注釈の**内容**の妥当性・画面題名の欠落・機能カタログの網羅性も見ない。
+**割当が痩せたこと**も検出できない (見るのは「1 枚以上のカードに載っていること」だけなので、
+ある route が 2 枚から 1 枚へ減っても緑のままである)。カードの前付けの契約のうち
+目録に関係しないもの (正準順序 / 表 A・表 B との突合 / lane / priority / depends_on /
+H1 見出し / 旧メタ節) は `stories/test_story_front_matter.py` の責務で、ここでは見ない。
 
 依存は標準ライブラリのみ (AGENTS.md §bug-hunt)。
 """
@@ -56,14 +68,24 @@ SURFACE_EXCLUDED_PREFIXES = ("livewire",)       # 先頭セグメントの前方
 KUBUN_VOCABULARY = ("通常", "逸", "終", "外")
 # coverage/correlate.py の定数と一致させる (自己テストが import して照合する)。
 KUBUN_OUT_OF_SCOPE, KUBUN_DEVIATE = "外", "逸"
-KUBUN_NEEDS_STORY = ("通常", "逸")
+# **reason の要否だけ**に使う。スコープ判定は `kubun == KUBUN_OUT_OF_SCOPE` に統一する
+# (`終` は「実行すると後続が成立しない終端」であって**対象内**である)。
 KUBUN_NEEDS_REASON = ("外", "終")
 
-STORY_IDS = tuple(f"S{i}" for i in range(1, 8))
 SCREEN_KINDS = ("画面", "JSON")
-ANNOTATION_KEYS = ("kind", "story", "kubun", "reason")
+# 注釈が持つのは「route ごとの意味」だけである。割当 (どのカードが消化するか) は
+# シナリオカードの前付けが正本なので、ここには持たない。
+ANNOTATION_KEYS = ("kind", "kubun", "reason")
 REASON_MIN_LENGTH = 30
 
+# 割当セルの値域。**書き出し側の正本はここ**であり、規則の散文は
+# `.claude/skills/app-bug-hunt/stories/README.md` にある。
+# `-` は「載せるカードが 0 枚 (= 対象外)」を表す。
+STORY_CELL_EMPTY = "-"
+STORY_CELL_SEPARATOR = " "
+# 照合は fullmatch() で行う (Python の `$` は末尾改行の直前にも一致するため)。
+STORY_CELL_RE = re.compile(r"(S[1-9][0-9]*( S[1-9][0-9]*)*|-)")
+
 GET_LIKE_METHODS = ("GET", "HEAD", "OPTIONS")
 
 # 表のセルへ出る値に許さない文字 (correlate.py が split("|") で読むためエスケープ規約は作らない)。
@@ -79,6 +101,8 @@ BACKTICK_TOKEN_RE = re.compile(r"`([^`]+)`")
 PATH_TOKEN_RE = re.compile(r"^[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)+\.[A-Za-z0-9]+$")
 
 SKILL_DIR = Path(".claude/skills/app-bug-hunt")
+# 前付けの読み取り器の置き場 (stories/ に居る。文法の正本はその隣の README.md)。
+STORIES_DIR = SKILL_DIR / "stories"
 ANNOTATIONS_PATH = SKILL_DIR / "inventory" / "annotations.toml"
 NOTES_SCREENS_PATH = SKILL_DIR / "inventory" / "notes-screens.md"
 NOTES_OPERATIONS_PATH = SKILL_DIR / "inventory" / "notes-operations.md"
@@ -88,15 +112,24 @@ CATALOG_PATH = SKILL_DIR / "capability-catalog.md"
 
 GENERATED_NOTICE = (
     "> **このファイルは生成物である。手で編集しない。**\n"
-    "> 直し方: `.claude/skills/app-bug-hunt/inventory/annotations.toml` (割当・区分・理由) か\n"
-    "> `inventory/notes-*.md` (散文) を直してから "
-    "`python3 scripts/bug-hunt-inventory.py generate` を走らせる。\n"
+    "> 直し方: 割当ストーリー列は `.claude/skills/app-bug-hunt/stories/S*.md` の前付け\n"
+    "> (`covers_screens` / `covers_operations`) を、区分・理由・種別は\n"
+    "> `inventory/annotations.toml` を、散文は `inventory/notes-*.md` を直してから\n"
+    "> `python3 scripts/bug-hunt-inventory.py generate` を走らせる。\n"
     "> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。\n"
     "> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。\n"
 )
 
 Scanner = Callable[[Path], object]
 
+# 前付けの読み取り器を取り込む (ファイル名にハイフンを含む生成器からは通常の import ができない
+# ため、読み取り器の置き場を sys.path へ一時的に足す)。読み取り器は stdlib だけに依存する。
+sys.path.insert(0, str(Path(__file__).resolve().parent.parent / STORIES_DIR))
+try:
+    import story_front_matter  # noqa: E402 — 置き場を sys.path へ足した直後にしか読めない
+finally:
+    sys.path.pop(0)
+
 
 class FatalError(Exception):
     """検査を成立させられない状態 (終了コード 2)。"""
@@ -284,6 +317,162 @@ def _annotation_value(entry: dict[str, object], key: str) -> str | None:
     return value if isinstance(value, str) else None
 
 
+@dataclass(frozen=True)
+class Assignment:
+    """カードの前付けから逆引きした route → 割当カード集合 (欄ごと)。
+
+    ★ 持つのは**判定と生成に使う 3 つだけ**である (集めるが誰も参照しない出力を作らない)。
+      カードの一覧そのものは目録の生成にも突合にも要らないので持たない。
+    """
+
+    screens: dict[str, frozenset[str]]
+    operations: dict[str, frozenset[str]]
+    capabilities: frozenset[str]
+
+
+def load_assignment(stories_dir: Path) -> tuple[Assignment | None, list[str]]:
+    """カードの前付けを読み、欄ごとの割当と違反を返す。
+
+    ★ **生成器単体で fail-closed にする**。書式の全契約は
+      stories/test_story_front_matter.py の責務だが、それは**別プロセス**である。
+      生成器を直接叩いた走行が緑になってはいけないので、ここでも次を見る:
+
+        - parse_front_matter() が返した違反を**必ず伝播する**
+        - `id` / `applicability` / `covers_*` が期待型でなければ**割当を構築しない**
+        - 不正なカードを**飛ばして目録を生成しない** (段 2 の違反として exit 3 にする)
+
+      逆に、語彙・正準順序・表 A / 表 B との突合といった「目録に関係しない契約」は
+      ここでは見ない (二重に持つと必ず食い違う)。
+
+    ★ **失敗を型で表す**。違反が 1 件でもあれば `None` を返す。空の Assignment を返すと、
+      呼び出し側が違反の並びを見落としたときに**そのまま目録を生成できてしまう**。
+
+    ★ 読むこと自体が成立しない状態 (置き場が無い / 候補 0 件 / 読み取り不能) は
+      `FatalError` (終了コード 2) にする。**違反 0 件と母集団 0 件を混ぜない**。
+    """
+    try:
+        cards, violations = story_front_matter.read_cards(stories_dir)
+    except story_front_matter.StoryReadError as exc:
+        raise FatalError(f"[{STAGE2}] シナリオカードを読めない: {exc}") from exc
+
+    violations = [f"[{STAGE2}] {v}" for v in violations]
+    screens: dict[str, set[str]] = {}
+    operations: dict[str, set[str]] = {}
+    capabilities: set[str] = set()
+    card_ids: list[str] = []
+
+    for card in cards:
+        prefix = f"[{STAGE2}] {card.filename}:"
+        card_id = card.front_matter.get("id")
+        if not isinstance(card_id, str) or story_front_matter.CARD_ID_RE.fullmatch(card_id) is None:
+            violations.append(f"{prefix} id の書式が契約外: {card_id!r}")
+            continue
+        if card_id in card_ids:
+            violations.append(f"{prefix} id が重複している: {card_id}")
+            continue
+        card_ids.append(card_id)
+
+        applicability = card.front_matter.get("applicability")
+        if applicability not in story_front_matter.APPLICABILITY_VOCABULARY:
+            violations.append(f"{prefix} 未知の applicability: {applicability!r}")
+            continue
+
+        for key, pattern in (
+            ("covers_screens", story_front_matter.ROUTE_TOKEN_RE),
+            ("covers_operations", story_front_matter.ROUTE_TOKEN_RE),
+            ("covers_capabilities", story_front_matter.CAPABILITY_TOKEN_RE),
+        ):
+            elements = card.front_matter.get(key)
+            if not isinstance(elements, list):
+                violations.append(f"{prefix} {key} が配列でない")
+                continue
+            names: list[str] = []
+            for element in elements:
+                if not isinstance(element, str) or pattern.fullmatch(element) is None:
+                    violations.append(f"{prefix} {key} の要素の書式が契約外: {element!r}")
+                    continue
+                if element in names:
+                    # frozenset 化すると消えるので、集合にする**前**に見る。
+                    violations.append(f"{prefix} {key} に重複した要素がある: {element}")
+                    continue
+                names.append(element)
+            # not_applicable のカードは `## 手順` を持たない (F2) ため、消化カードとして
+            # 数えるべきではない。よって割当の母集団から外す。
+            if applicability != "applicable":
+                continue
+            if key == "covers_screens":
+                for name in names:
+                    screens.setdefault(name, set()).add(card_id)
+            elif key == "covers_operations":
+                for name in names:
+                    operations.setdefault(name, set()).add(card_id)
+            else:
+                capabilities.update(names)
+
+    if violations:
+        return None, violations
+
+    return Assignment(
+        screens={k: frozenset(v) for k, v in screens.items()},
+        operations={k: frozenset(v) for k, v in operations.items()},
+        capabilities=frozenset(capabilities),
+    ), []
+
+
+def validate_assignment(
+    facts: Facts, annotations: Annotations, assignment: Assignment
+) -> list[str]:
+    """前付けの割当と目録の母集合を突き合わせる (段 2 の一部)。
+
+    見るのは 4 つ:
+      I2 実在   … 載せた route 名が web 面の母集合に在る
+      I3 欄     … covers_screens は safe method / covers_operations は 非 safe method
+      I4 対象外 … 区分 **外** の route を載せていない (`終` は対象内である)
+      I1 未割当 … 対象内の route が 1 枚以上のカードに載っている
+
+    ★ **欄ごとに明示的にループする**。`fact in facts.screens` のような所属判定に頼ると、
+      将来 GET と非 GET を併せ持つ route (compound) を両方の表へ入れる形にした瞬間に、
+      操作側の未割当を静かに見逃す。
+    ★ **判定の順序は expected → other → 不明**である。other を先に見ると、
+      両方の母集合に在る route を「欄違い」と誤って報告する。
+    """
+    violations: list[str] = []
+    screen_names = {f.name for f in facts.screens}
+    operation_names = {f.name for f in facts.operations}
+
+    for label, cell, expected, other in (
+        ("covers_screens", assignment.screens, screen_names, operation_names),
+        ("covers_operations", assignment.operations, operation_names, screen_names),
+    ):
+        for name in sorted(cell):
+            if name in expected:
+                entry = annotations.routes.get(name)
+                # 未注釈 route は既存の「未注釈の route」違反の担当。ここでは黙って飛ばす
+                # (KeyError で全体を落とすと、他の違反を集め終える前に走行が止まる)。
+                if entry is not None and _annotation_value(entry, "kubun") == KUBUN_OUT_OF_SCOPE:
+                    violations.append(f"[{STAGE2}] {label} に対象外の route: {name}")
+            elif name in other:
+                violations.append(f"[{STAGE2}] {label} に欄違いの route: {name}")
+            else:
+                violations.append(f"[{STAGE2}] {label} に実在しない route: {name}")
+
+    for label, route_facts, pool in (
+        ("画面", facts.screens, assignment.screens),
+        ("操作", facts.operations, assignment.operations),
+    ):
+        for fact in route_facts:
+            entry = annotations.routes.get(fact.name)
+            if entry is None or _annotation_value(entry, "kubun") == KUBUN_OUT_OF_SCOPE:
+                continue
+            if not pool.get(fact.name):
+                violations.append(
+                    f"[{STAGE2}] 対象内なのにどのカードにも載っていない{label}: {fact.name} "
+                    "(消化するカードの covers_* へ足すこと)"
+                )
+
+    return violations
+
+
 def validate_annotations(facts: Facts, annotations: Annotations) -> list[str]:
     """注釈の定義域一致・語彙・形式・複合 method を検査し、違反行を全件返す。"""
     violations: list[str] = []
@@ -328,16 +517,6 @@ def validate_annotations(facts: Facts, annotations: Annotations) -> list[str]:
         elif kind is not None:
             violations.append(f"{prefix} 操作表の route に kind は書けない")
 
-        story = _annotation_value(entry, "story")
-        if kubun in KUBUN_NEEDS_STORY:
-            if story is None:
-                violations.append(f"{prefix} 区分 {kubun} には story が要る")
-            elif story not in STORY_IDS:
-                violations.append(f"{prefix} 未知のストーリー: {story}")
-        elif kubun in KUBUN_NEEDS_REASON and story is not None:
-            # 表では `-` に潰れて見えなくなる古い割当を残さない。
-            violations.append(f"{prefix} 区分 {kubun} に story は書けない")
-
         reason = _annotation_value(entry, "reason")
         if kubun in KUBUN_NEEDS_REASON:
             if reason is None:
@@ -351,7 +530,7 @@ def validate_annotations(facts: Facts, annotations: Annotations) -> list[str]:
         elif reason is not None:
             violations.append(f"{prefix} 区分 {kubun} に理由は書けない")
 
-        for key in ("kind", "story", "kubun"):
+        for key in ("kind", "kubun"):
             value = _annotation_value(entry, key)
             if value is not None and any(c in value for c in FORBIDDEN_CELL_CHARS):
                 violations.append(f"{prefix} {key} に表を壊す文字 (| / 改行) が入っている")
@@ -391,9 +570,21 @@ def check_notes(notes: dict[str, str]) -> list[str]:
 # --------------------------------------------------------------------------- #
 # 段 3 の素材: 生成物のレンダリング
 # --------------------------------------------------------------------------- #
-def _story_cell(entry: dict[str, object]) -> str:
-    story = _annotation_value(entry, "story")
-    return story if story is not None else "-"
+def _story_cell(assignment: frozenset[str]) -> str:
+    """割当カードの集合をセルの表記へ落とす (番号の昇順・半角空白 1 つ区切り)。
+
+    ★ 書き出す直前に**自分の出力を値域へ突き合わせる**。読み手 (`coverage/correlate.py`) は
+      同じ値域を fullmatch で強制するので、生成側が契約外のセルを書いたら
+      そこで走行を止める (黙って読めない目録を作らない)。
+    """
+    if not assignment:
+        cell = STORY_CELL_EMPTY
+    else:
+        cell = STORY_CELL_SEPARATOR.join(sorted(assignment, key=lambda s: int(s[1:])))
+    if STORY_CELL_RE.fullmatch(cell) is None:
+        raise FatalError(f"[{STAGE3}] 割当セルが契約外の表記になった: {cell!r}")
+
+    return cell
 
 
 def _out_of_scope_section(
@@ -403,7 +594,7 @@ def _out_of_scope_section(
     rows = [
         f"- `{fact.name}` — {_annotation_value(annotations.routes[fact.name], 'reason')}"
         for fact in routes
-        if _annotation_value(annotations.routes[fact.name], "kubun") in KUBUN_NEEDS_REASON
+        if _annotation_value(annotations.routes[fact.name], "kubun") == KUBUN_OUT_OF_SCOPE
     ]
     lines.extend(rows if rows else ["対象外に分類した route は無い。"])
 
@@ -411,12 +602,12 @@ def _out_of_scope_section(
 
 
 def render_screens(
-    facts: Facts, annotations: Annotations, notes: str
+    facts: Facts, annotations: Annotations, notes: str, assignment: Assignment
 ) -> str:
     out_of_scope = sum(
         1
         for fact in facts.screens
-        if _annotation_value(annotations.routes[fact.name], "kubun") in KUBUN_NEEDS_REASON
+        if _annotation_value(annotations.routes[fact.name], "kubun") == KUBUN_OUT_OF_SCOPE
     )
     lines = [
         "# 画面インベントリ (screens.md) — AI-CUE",
@@ -435,7 +626,8 @@ def render_screens(
         entry = annotations.routes[fact.name]
         lines.append(
             f"| {fact.uri} | {fact.name} | {_annotation_value(entry, 'kind')} | "
-            f"{fact.title or '-'} | {_story_cell(entry)} | {_annotation_value(entry, 'kubun')} |"
+            f"{fact.title or '-'} | {_story_cell(assignment.screens.get(fact.name, frozenset()))} | "
+            f"{_annotation_value(entry, 'kubun')} |"
         )
     body = "\n".join(lines) + "\n"
 
@@ -443,12 +635,12 @@ def render_screens(
 
 
 def render_operations(
-    facts: Facts, annotations: Annotations, notes: str
+    facts: Facts, annotations: Annotations, notes: str, assignment: Assignment
 ) -> str:
     out_of_scope = sum(
         1
         for fact in facts.operations
-        if _annotation_value(annotations.routes[fact.name], "kubun") in KUBUN_NEEDS_REASON
+        if _annotation_value(annotations.routes[fact.name], "kubun") == KUBUN_OUT_OF_SCOPE
     )
     lines = [
         "# 操作インベントリ (operations.md) — AI-CUE",
@@ -469,7 +661,8 @@ def render_operations(
         entry = annotations.routes[fact.name]
         lines.append(
             f"| {','.join(fact.write_methods)} | {fact.uri} | {fact.name} | "
-            f"{_story_cell(entry)} | {_annotation_value(entry, 'kubun')} |"
+            f"{_story_cell(assignment.operations.get(fact.name, frozenset()))} | "
+            f"{_annotation_value(entry, 'kubun')} |"
         )
     body = "\n".join(lines) + "\n"
 
@@ -479,11 +672,16 @@ def render_operations(
 # --------------------------------------------------------------------------- #
 # 段 4: 機能カタログの参照整合
 # --------------------------------------------------------------------------- #
-def check_catalog(catalog_text: str, facts: Facts) -> list[str]:
+def check_catalog(catalog_text: str, facts: Facts, assignment: Assignment) -> list[str]:
     """capability-catalog.md の代表機構が実在し、id が重複しないことを検査する。
 
     対象はヘッダが CAPABILITY_TABLE_HEADER の表**だけ** (責務境界・割当規則の表は見ない)。
     網羅性 (すべての route が id を持つか) は見ない (overlay なので網羅を主張しない)。
+
+    併せて、カードの `covers_capabilities` が**実在する id だけ**を挙げていることを見る。
+    **被覆漏れは見ない** (機能カタログが継承宣言の欄を持たないため。既存乖離 D20。
+    保証境界は stories/README.md に書いてある)。配列内の重複は `load_assignment()` が
+    集合化の**前**に見る。
     """
     violations: list[str] = []
     seen: list[str] = []
@@ -527,6 +725,9 @@ def check_catalog(catalog_text: str, facts: Facts) -> list[str]:
     if not seen:
         raise FatalError(f"[{STAGE4}] 機能カタログの表が見つからない (ヘッダが変わっていないか)")
 
+    for capability in sorted(assignment.capabilities - set(seen)):
+        violations.append(f"[{STAGE4}] カードが実在しない capability を挙げている: {capability}")
+
     return violations
 
 
@@ -630,14 +831,21 @@ def _replace_atomically(pairs: list[tuple[Path, str]]) -> None:
 # --------------------------------------------------------------------------- #
 # 公開 entry
 # --------------------------------------------------------------------------- #
-def _prepare(repo_root: Path, scanner: Scanner | None) -> tuple[Facts, Annotations, str, str]:
-    """段 1 と入力の読み込みまでを行う。"""
+def _prepare(
+    repo_root: Path, scanner: Scanner | None
+) -> tuple[Facts, Annotations, str, str, Assignment | None, list[str]]:
+    """段 1 と入力の読み込みまでを行う。
+
+    割当は読めなければ `None` になる (違反の並びを第 6 要素で返す)。**空の Assignment を
+    返さない** — 呼び出し側が違反を見落としたときにそのまま目録を生成できてしまうため。
+    """
     facts = split_surface((scanner or scan)(repo_root))
     annotations = load_annotations(repo_root / ANNOTATIONS_PATH)
     notes_screens = _read_text(repo_root / NOTES_SCREENS_PATH, STAGE2)
     notes_operations = _read_text(repo_root / NOTES_OPERATIONS_PATH, STAGE2)
+    assignment, assignment_violations = load_assignment(repo_root / STORIES_DIR)
 
-    return facts, annotations, notes_screens, notes_operations
+    return facts, annotations, notes_screens, notes_operations, assignment, assignment_violations
 
 
 def _report(violations: list[str]) -> int:
@@ -650,18 +858,24 @@ def _report(violations: list[str]) -> int:
 
 def run_check(repo_root: Path, *, scanner: Scanner | None = None) -> int:
     """段 1 → 2 → 3 → 4 を通す。**1 バイトも書かない**。"""
-    facts, annotations, notes_screens, notes_operations = _prepare(repo_root, scanner)
+    facts, annotations, notes_screens, notes_operations, assignment, assignment_violations = (
+        _prepare(repo_root, scanner)
+    )
 
     violations = validate_annotations(facts, annotations) + check_notes({
         NOTES_SCREENS_PATH.name: notes_screens,
         NOTES_OPERATIONS_PATH.name: notes_operations,
-    })
+    }) + assignment_violations
+    if assignment is None:
+        # 割当が読めない状態で段 3 / 4 へ進まない (レンダリングの入力が無い)。
+        return _report(violations)
+    violations += validate_assignment(facts, annotations, assignment)
     if violations:
         return _report(violations)
 
     for path, rendered in (
-        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens)),
-        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations)),
+        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens, assignment)),
+        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations, assignment)),
     ):
         if _read_text(path, STAGE3) != rendered:
             violations.append(
@@ -669,7 +883,7 @@ def run_check(repo_root: Path, *, scanner: Scanner | None = None) -> int:
                 "(python3 scripts/bug-hunt-inventory.py generate を走らせること)"
             )
 
-    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts)
+    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts, assignment)
     if violations:
         return _report(violations)
 
@@ -683,19 +897,25 @@ def run_check(repo_root: Path, *, scanner: Scanner | None = None) -> int:
 
 def run_generate(repo_root: Path, *, scanner: Scanner | None = None) -> int:
     """段 1 → 2 → 4 を通してから 2 ファイルを書き替える。"""
-    facts, annotations, notes_screens, notes_operations = _prepare(repo_root, scanner)
+    facts, annotations, notes_screens, notes_operations, assignment, assignment_violations = (
+        _prepare(repo_root, scanner)
+    )
 
     violations = validate_annotations(facts, annotations) + check_notes({
         NOTES_SCREENS_PATH.name: notes_screens,
         NOTES_OPERATIONS_PATH.name: notes_operations,
-    })
-    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts)
+    }) + assignment_violations
+    if assignment is None:
+        # 目録を 1 バイトも書かずに落とす。
+        return _report(violations)
+    violations += validate_assignment(facts, annotations, assignment)
+    violations += check_catalog(_read_text(repo_root / CATALOG_PATH, STAGE4), facts, assignment)
     if violations:
         return _report(violations)
 
     _replace_atomically([
-        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens)),
-        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations)),
+        (repo_root / SCREENS_PATH, render_screens(facts, annotations, notes_screens, assignment)),
+        (repo_root / OPERATIONS_PATH, render_operations(facts, annotations, notes_operations, assignment)),
     ])
     print(
         f"生成完了: 画面 {len(facts.screens)} 件 / 操作 {len(facts.operations)} 件 "
diff --git a/scripts/tests/test_bug_hunt_inventory.py b/scripts/tests/test_bug_hunt_inventory.py
index bf6675f5..519c6592 100644
--- a/scripts/tests/test_bug_hunt_inventory.py
+++ b/scripts/tests/test_bug_hunt_inventory.py
@@ -69,15 +69,12 @@ BASE_ANNOTATIONS = """schema_version = 1
 
 [routes."dashboard"]
 kind = "画面"
-story = "S1"
 kubun = "通常"
 
 [routes."projects.destroy"]
-story = "S4"
 kubun = "通常"
 
 [routes."projects.store"]
-story = "S4"
 kubun = "通常"
 
 [routes."seo.robots"]
@@ -87,10 +84,78 @@ reason = "クローラ向けの機械可読 route であり人が操作する画
 
 [routes."session.status"]
 kind = "JSON"
-story = "S6"
 kubun = "通常"
 """
 
+
+def card(
+    card_id="S1",
+    *,
+    title="見本カード",
+    surface="signup_funnel",
+    lane="parallel_browser",
+    priority="P1",
+    applicability="applicable",
+    reason=None,
+    depends_on=(),
+    reseed_before=True,
+    accounts=("guest",),
+    setup=(),
+    screens=(),
+    operations=(),
+    capabilities=(),
+    body=None,
+):
+    """合成のシナリオカード 1 枚 (前付けは正準順序で書く)。"""
+    def arr(values):
+        return "[" + ", ".join(values) + "]"
+
+    lines = [
+        "---",
+        f"id: {card_id}",
+        f"title: {title}",
+        f"surface: {surface}",
+        f"lane: {lane}",
+        f"priority: {priority}",
+        f"applicability: {applicability}",
+    ]
+    if reason is not None:
+        lines.append(f"not_applicable_reason: {reason}")
+    lines += [
+        f"depends_on: {arr(depends_on)}",
+        f"reseed_before: {'true' if reseed_before else 'false'}",
+        f"accounts: {arr(accounts)}",
+        f"setup: {arr(setup)}",
+        f"covers_screens: {arr(screens)}",
+        f"covers_operations: {arr(operations)}",
+        f"covers_capabilities: {arr(capabilities)}",
+        "---",
+        "",
+        f"# {card_id}: {title}",
+        "",
+        "## 目的",
+        "見本のカードである。",
+        "",
+    ]
+    if body is None:
+        lines += ["## 手順", "1. 開く → 見える", ""]
+    else:
+        lines += body
+    lines += ["## 逸脱アイデア (--deviate 時)", "- 二重送信してみる", ""]
+
+    return "\n".join(lines)
+
+
+# 既定のカード束: 対象内の 4 route (dashboard / session.status / projects.store /
+# projects.destroy) をちょうど覆う。`seo.robots` は区分 外 なのでどのカードにも載せない。
+BASE_CARDS = {
+    "S1-signup.md": card("S1", screens=("dashboard",), capabilities=("PROJ-01",)),
+    "S2-invitation.md": card(
+        "S2", surface="invitation", screens=("session.status",),
+        operations=("projects.store", "projects.destroy"),
+    ),
+}
+
 BASE_CATALOG = """# Capability Catalog
 
 ## capability_id 索引
@@ -120,7 +185,7 @@ def fake_scanner(routes=None, *, schema_version=1, condition=inv.EXTRACTION_COND
 
 
 class SandboxCase(unittest.TestCase):
-    """生成器が読む 6 ファイルを持つ sandbox を組み立てる。"""
+    """生成器が読む入力一式 (注釈 / 散文 / カタログ / カード) を持つ sandbox を組み立てる。"""
 
     def setUp(self):
         self.root = Path(tempfile.mkdtemp(prefix="bhi-"))
@@ -132,6 +197,16 @@ class SandboxCase(unittest.TestCase):
         self.write(inv.CATALOG_PATH, BASE_CATALOG)
         self.write(inv.SCREENS_PATH, "placeholder\n")
         self.write(inv.OPERATIONS_PATH, "placeholder\n")
+        self.write_cards(BASE_CARDS)
+
+    def write_cards(self, cards: dict) -> None:
+        """カードの置き場を作り直す (前付けが割当の正本)。"""
+        stories = self.root / inv.STORIES_DIR
+        if stories.is_dir():
+            shutil.rmtree(stories)
+        stories.mkdir(parents=True, exist_ok=True)
+        for name, text in cards.items():
+            (stories / name).write_text(text, encoding="utf-8", newline="\n")
 
     def write(self, relative: Path, content: str) -> Path:
         path = self.root / relative
@@ -267,15 +342,43 @@ class SurfaceTest(unittest.TestCase):
 
 
 class VocabularyParityTest(unittest.TestCase):
-    def test_区分の語彙が_correlate_と一致する(self):
+    def _correlate(self):
         sys.path.insert(0, str(REPO_ROOT / ".claude/skills/app-bug-hunt/coverage"))
         try:
             import correlate
         finally:
             sys.path.pop(0)
+
+        return correlate
+
+    def test_区分の語彙が_correlate_と一致する(self):
+        correlate = self._correlate()
         self.assertEqual(correlate.KUBUN_OUT_OF_SCOPE, inv.KUBUN_OUT_OF_SCOPE)
         self.assertEqual(correlate.KUBUN_DEVIATE, inv.KUBUN_DEVIATE)
 
+    def test_割当セルの値域が_correlate_と一致する(self):
+        # 書き出し側 (ここ) と読み手 (correlate) が別モジュールに同じ値域を持つ。
+        # 共有モジュール化は採らない (CLI スクリプトはハイフンを含み import 対象にならない /
+        # 照合器は共有ファイルなのでアプリ固有モジュールへの依存を増やすと乖離が深くなる)。
+        # 代わりに**両側の定数が一致すること**をここで固定する。
+        correlate = self._correlate()
+        self.assertEqual(correlate.STORY_CELL_RE.pattern, inv.STORY_CELL_RE.pattern)
+        self.assertEqual(correlate.STORY_CELL_SEPARATOR, inv.STORY_CELL_SEPARATOR)
+        self.assertEqual(correlate.STORY_CELL_EMPTY, inv.STORY_CELL_EMPTY)
+
+    def test_生成側が書くセルを読み手が同じ値に分解する(self):
+        # 同一ケースを両側で列挙する (値域が 2 形に閉じていることの担保)。
+        correlate = self._correlate()
+        for value, expected in (
+            (frozenset(), []),
+            (frozenset({"S3"}), ["S3"]),
+            (frozenset({"S7", "S3"}), ["S3", "S7"]),
+            (frozenset({"S10", "S9"}), ["S9", "S10"]),
+        ):
+            with self.subTest(value=value):
+                cell = inv._story_cell(value)
+                self.assertEqual(expected, correlate.parse_story_cell(cell, "r"))
+
 
 # --------------------------------------------------------------------------- #
 # 段 2: 注釈 (ドリフト)
@@ -290,16 +393,16 @@ class AnnotationTest(SandboxCase):
         self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS.replace(old, new))
 
     def test_未注釈のroute(self):
-        self.replace('[routes."projects.store"]\nstory = "S4"\nkubun = "通常"\n', "")
+        self.replace('[routes."projects.store"]\nkubun = "通常"\n', "")
         self.assert_drift("未注釈の route: projects.store")
 
     def test_実装に無いrouteの注釈残置(self):
-        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."gone.index"]\nkind = "画面"\nstory = "S1"\nkubun = "通常"\n')
+        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."gone.index"]\nkind = "画面"\nkubun = "通常"\n')
         self.assert_drift("実装に無い route の注釈が残っている: gone.index")
 
     def test_未知の区分(self):
-        self.replace('[routes."dashboard"]\nkind = "画面"\nstory = "S1"\nkubun = "通常"',
-                     '[routes."dashboard"]\nkind = "画面"\nstory = "S1"\nkubun = "重要"')
+        self.replace('[routes."dashboard"]\nkind = "画面"\nkubun = "通常"',
+                     '[routes."dashboard"]\nkind = "画面"\nkubun = "重要"')
         self.assert_drift("未知の区分")
 
     def test_未知の項目(self):
@@ -310,13 +413,10 @@ class AnnotationTest(SandboxCase):
         self.replace("クローラ向けの機械可読 route であり人が操作する画面ではないため探索の分母に載せない", "短い理由")
         self.assert_drift("30 文字未満")
 
-    def test_story欠落(self):
-        self.replace('[routes."projects.store"]\nstory = "S4"\n', '[routes."projects.store"]\n')
-        self.assert_drift("story が要る")
-
-    def test_区分外にstoryを書けない(self):
-        self.replace('[routes."seo.robots"]\nkind = "JSON"', '[routes."seo.robots"]\nkind = "JSON"\nstory = "S1"')
-        self.assert_drift("story は書けない")
+    def test_注釈にstoryを書き戻すと未知の項目(self):
+        # 割当の正本はカードの前付けなので、注釈へ書き戻す道は deny-by-default で塞ぐ。
+        self.replace('[routes."projects.store"]\n', '[routes."projects.store"]\nstory = "S1"\n')
+        self.assert_drift("未知の項目: story")
 
     def test_画面routeのkind欠落(self):
         self.replace('[routes."dashboard"]\nkind = "画面"\n', '[routes."dashboard"]\n')
@@ -327,7 +427,7 @@ class AnnotationTest(SandboxCase):
         self.assert_drift("kind は書けない")
 
     def test_セル値に表を壊す文字(self):
-        self.replace('story = "S1"\nkubun = "通常"', 'story = "S1|S2"\nkubun = "通常"')
+        self.replace('kind = "画面"\nkubun = "通常"', 'kind = "画|面"\nkubun = "通常"')
         self.assert_drift("表を壊す文字")
 
     def test_機械事実側のセル値に表を壊す文字(self):
@@ -338,7 +438,7 @@ class AnnotationTest(SandboxCase):
         self.assertIn("表を壊す文字", output)
 
     def test_複合methodはドリフト(self):
-        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."both"]\nstory = "S1"\nkubun = "通常"\n')
+        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."both"]\nkubun = "通常"\n')
         code, output = self.run_check(fake_scanner(BASE_ROUTES + [route("both", "both", ["GET", "HEAD", "POST"])]))
         self.assertEqual(inv.EXIT_DRIFT, code, output)
         self.assertIn("併せ持つ route", output)
@@ -516,6 +616,223 @@ class CatalogTest(SandboxCase):
         self.assertEqual(inv.EXIT_FATAL, code, output)
 
 
+# --------------------------------------------------------------------------- #
+# 段 2: 割当 (カードの前付けが正本)
+# --------------------------------------------------------------------------- #
+class AssignmentTest(SandboxCase):
+    """`covers_*` と目録の母集合の突合 (I1〜I4) と、生成器単体の fail-closed。"""
+
+    def with_cards(self, **overrides):
+        """S2 のカードを差し替えた束を置く (S1 は dashboard を覆ったまま)。"""
+        cards = dict(BASE_CARDS)
+        cards["S2-invitation.md"] = card("S2", surface="invitation", **overrides)
+        self.write_cards(cards)
+
+    def assert_drift(self, needle: str, scanner=None):
+        code, output = self.run_check(scanner)
+        self.assertEqual(inv.EXIT_DRIFT, code, output)
+        self.assertIn(needle, output)
+
+        return output
+
+    def test_実在しないrouteを載せるとドリフト(self):
+        self.with_cards(
+            screens=("session.status", "nowhere.index"),
+            operations=("projects.store", "projects.destroy"),
+        )
+        self.assert_drift("covers_screens に実在しない route: nowhere.index")
+
+    def test_画面欄に非safeなrouteを載せるとドリフト(self):
+        self.with_cards(
+            screens=("session.status", "projects.store"),
+            operations=("projects.store", "projects.destroy"),
+        )
+        self.assert_drift("covers_screens に欄違いの route: projects.store")
+
+    def test_操作欄にsafeなrouteを載せるとドリフト(self):
+        self.with_cards(
+            screens=("session.status",),
+            operations=("projects.store", "projects.destroy", "dashboard"),
+        )
+        self.assert_drift("covers_operations に欄違いの route: dashboard")
+
+    def test_対象外のrouteを載せるとドリフト(self):
+        self.with_cards(
+            screens=("session.status", "seo.robots"),
+            operations=("projects.store", "projects.destroy"),
+        )
+        self.assert_drift("covers_screens に対象外の route: seo.robots")
+
+    def test_どのカードにも載っていない対象内routeはドリフト(self):
+        self.with_cards(screens=(), operations=("projects.store", "projects.destroy"))
+        self.assert_drift("どのカードにも載っていない画面: session.status")
+
+    def test_実在しないcapabilityを挙げるとドリフト(self):
+        self.with_cards(
+            screens=("session.status",),
+            operations=("projects.store", "projects.destroy"),
+            capabilities=("ZZZ-99",),
+        )
+        self.assert_drift("実在しない capability を挙げている: ZZZ-99")
+
+    def test_not_applicableのカードの割当は数えない(self):
+        # 手順を持たないカードは消化カードとして数えない (F2)。
+        cards = {
+            "S1-signup.md": card(
+                "S1", applicability="not_applicable",
+                reason="本アプリに該当する面が無いため実走しない",
+                reseed_before=False, accounts=(), screens=("dashboard",), body=[],
+            ),
+            "S2-invitation.md": card(
+                "S2", surface="invitation", screens=("session.status",),
+                operations=("projects.store", "projects.destroy"),
+            ),
+        }
+        self.write_cards(cards)
+        self.assert_drift("どのカードにも載っていない画面: dashboard")
+
+    def test_複合methodのrouteは操作欄として扱われる(self):
+        routes = BASE_ROUTES + [route("both", "both", ["GET", "HEAD", "POST"])]
+        self.write(inv.ANNOTATIONS_PATH, BASE_ANNOTATIONS + '\n[routes."both"]\nkubun = "通常"\n')
+        self.with_cards(
+            screens=("session.status",),
+            operations=("projects.store", "projects.destroy", "both"),
+        )
+        output = self.assert_drift("併せ持つ route", fake_scanner(routes))
+        # 欄判定を誤らない (操作表に居るので covers_operations が正しい)。
+        self.assertNotIn("欄違い", output)
+        self.assertNotIn("どのカードにも載っていない", output)
+
+    def test_未注釈のrouteがあってもKeyErrorで落ちない(self):
+        self.write(
+            inv.ANNOTATIONS_PATH,
+            BASE_ANNOTATIONS.replace('[routes."dashboard"]\nkind = "画面"\nkubun = "通常"\n\n', ""),
+        )
+        output = self.assert_drift("未注釈の route: dashboard")
+        self.assertNotIn("Traceback", output)
+
+    def test_区分終は対象内なので割当が要る(self):
+        annotations = BASE_ANNOTATIONS.replace(
+            '[routes."projects.destroy"]\nkubun = "通常"',
+            '[routes."projects.destroy"]\nkubun = "終"\n'
+            'reason = "実行するとプロジェクトが消えて後続の手順が成立しなくなる終端の操作である"',
+        )
+        self.write(inv.ANNOTATIONS_PATH, annotations)
+        self.with_cards(screens=("session.status",), operations=("projects.store",))
+        self.assert_drift("どのカードにも載っていない操作: projects.destroy")
+
+    def test_複数値セルでも段3のbyte一致が成立する(self):
+        # 1 route を 2 枚のカードが消化する = セルが `S1 S2` になる。
+        cards = dict(BASE_CARDS)
+        cards["S1-signup.md"] = card(
+            "S1", screens=("dashboard",), operations=("projects.store",),
+            capabilities=("PROJ-01",),
+        )
+        self.write_cards(cards)
+        self.generate_then()
+        rows = [
+            line for line in self.read(inv.OPERATIONS_PATH).splitlines()
+            if "projects.store" in line and line.startswith("|")
+        ]
+        self.assertEqual(1, len(rows))
+        self.assertEqual("S1 S2", [c.strip() for c in rows[0].strip("|").split("|")][3])
+        code, output = self.run_check()
+        self.assertEqual(inv.EXIT_OK, code, output)
+
+    def test_区分終のrouteはカードに載せてよい(self):
+        annotations = BASE_ANNOTATIONS.replace(
+            '[routes."projects.destroy"]\nkubun = "通常"',
+            '[routes."projects.destroy"]\nkubun = "終"\n'
+            'reason = "実行するとプロジェクトが消えて後続の手順が成立しなくなる終端の操作である"',
+        )
+        self.write(inv.ANNOTATIONS_PATH, annotations)
+        code, output = self.run_generate()
+        self.assertEqual(inv.EXIT_OK, code, output)
+        rows = [
+            line for line in self.read(inv.OPERATIONS_PATH).splitlines()
+            if "projects.destroy" in line and line.startswith("|")
+        ]
+        self.assertEqual(1, len(rows))
+        self.assertEqual("S2", [c.strip() for c in rows[0].strip("|").split("|")][3])
+        # `終` は対象外件数にも対象外節にも入らない。
+        self.assertIn("うち対象外 1 件", self.read(inv.SCREENS_PATH))
+        self.assertNotIn("`projects.destroy` —", self.read(inv.OPERATIONS_PATH))
+
+
+class StoryCellTest(unittest.TestCase):
+    """割当セルの表記 (書き出し側の値域の正本)。"""
+
+    def test_単一値(self):
+        self.assertEqual("S3", inv._story_cell(frozenset({"S3"})))
+
+    def test_空集合はハイフン(self):
+        self.assertEqual(inv.STORY_CELL_EMPTY, inv._story_cell(frozenset()))
+
+    def test_複数値は番号の昇順で半角空白区切り(self):
+        self.assertEqual("S3 S7", inv._story_cell(frozenset({"S7", "S3"})))
+
+    def test_辞書順でなく数値順(self):
+        # sorted() の既定は辞書順で S10 < S9 になる。S10 を足した瞬間に壊れる形を残さない。
+        self.assertEqual("S9 S10", inv._story_cell(frozenset({"S10", "S9"})))
+
+    def test_出力が値域に収まる(self):
+        for value in (frozenset(), frozenset({"S1"}), frozenset({"S1", "S2", "S10"})):
+            with self.subTest(value=value):
+                self.assertIsNotNone(inv.STORY_CELL_RE.fullmatch(inv._story_cell(value)))
+
+
+class ExitCodeContractTest(SandboxCase):
+    """終了コードを原因別に固定する (「3 か 2 のどちらか」では後退を検出できない)。"""
+
+    def assert_untouched(self, before):
+        self.assertEqual(before, (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH)))
+
+    def both_entries(self, expected_code: int, needle: str):
+        before = (self.read(inv.SCREENS_PATH), self.read(inv.OPERATIONS_PATH))
+        for entry in (self.run_check, self.run_generate):
+            with self.subTest(entry=entry.__name__):
+                code, output = entry()
+                self.assertEqual(expected_code, code, output)
+                self.assertIn(needle, output)
+                self.assert_untouched(before)
+
+    def test_前付けの形式違反はドリフト(self):
+        cards = dict(BASE_CARDS)
+        cards["S1-signup.md"] = BASE_CARDS["S1-signup.md"].replace(
+            "title: 見本カード", 'title: "見本カード"'
+        )
+        self.write_cards(cards)
+        self.both_entries(inv.EXIT_DRIFT, "スカラーに使えない文字がある")
+
+    def test_前付けの語彙違反はドリフト(self):
+        cards = dict(BASE_CARDS)
+        cards["S1-signup.md"] = BASE_CARDS["S1-signup.md"].replace(
+            "applicability: applicable", "applicability: maybe"
+        )
+        self.write_cards(cards)
+        self.both_entries(inv.EXIT_DRIFT, "未知の applicability")
+
+    def test_配列内の重複はドリフト(self):
+        cards = dict(BASE_CARDS)
+        cards["S1-signup.md"] = BASE_CARDS["S1-signup.md"].replace(
+            "covers_screens: [dashboard]", "covers_screens: [dashboard, dashboard]"
+        )
+        self.write_cards(cards)
+        self.both_entries(inv.EXIT_DRIFT, "covers_screens に重複した要素がある")
+
+    def test_カードの置き場が無いのは致命(self):
+        shutil.rmtree(self.root / inv.STORIES_DIR)
+        self.both_entries(inv.EXIT_FATAL, "シナリオカードを読めない")
+
+    def test_カードが1枚も無いのは致命(self):
+        self.write_cards({})
+        self.both_entries(inv.EXIT_FATAL, "シナリオカードを読めない")
+
+    def test_カードが読み取り不能なのは致命(self):
+        (self.root / inv.STORIES_DIR / "S1-signup.md").write_bytes(b"\xff\xfe\x00broken")
+        self.both_entries(inv.EXIT_FATAL, "シナリオカードを読めない")
+
+
 # --------------------------------------------------------------------------- #
 # 下流ローダとの結合
 # --------------------------------------------------------------------------- #
@@ -530,7 +847,7 @@ class CorrelateIntegrationTest(SandboxCase):
 
         loaded = correlate.load_operations(str(self.root / inv.OPERATIONS_PATH))
         self.assertEqual({"projects.store", "projects.destroy"}, set(loaded))
-        self.assertEqual("S4", loaded["projects.store"]["story"])
+        self.assertEqual("S2", loaded["projects.store"]["story"])
         self.assertEqual("通常", loaded["projects.store"]["kubun"])
         self.assertEqual("projects", loaded["projects.store"]["operation"])
 
diff --git a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
index 92daf0a3..13d9dc48 100644
--- a/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
+++ b/tests/Architecture/BugHuntInventoryCheckInvariantTest.php
@@ -22,6 +22,10 @@
  * (boot + APP_KEY + DB) には依存させない: 一時 sandbox へ道具一式を複製し、`php` を
  * 固定の scan JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
  *
+ * 道具一式には**シナリオカードと前付けの読み取り器**が含まれる。割当 (どのカードが route を
+ * 消化するか) の正本はカードの前付けであり (`docs/template-divergence.md` D20 / D40)、
+ * 生成器はそれを読めないと段 2 を成立させられないためである。
+ *
  * ★空振り検査 (母集団非空) の付与対象外である。理由:
  *   本 gate は**ディレクトリを列挙して母集団を作らない**。見るのは名指しの 2 ファイル
  *   (`scripts/bug-hunt-inventory-check.sh` / `scripts/bug-hunt-inventory.py`) と、
@@ -44,9 +48,44 @@ function bhicGeneratorPath(): string
     return base_path('scripts/bug-hunt-inventory.py');
 }
 
+/** 前付けの読み取り器 (生成器が割当を読むのに使う。カードの隣に置く)。 */
+function bhicStoryReaderPath(): string
+{
+    return base_path('.claude/skills/app-bug-hunt/stories/story_front_matter.py');
+}
+
 /** sandbox 内の相対パス (生成器が持つ正本パスと同じ場所へ置く)。 */
 const BHIC_SKILL_DIR = '.claude/skills/app-bug-hunt';
 
+/**
+ * sandbox 用のシナリオカード 1 枚 (割当の正本は前付けである)。
+ *
+ * @param  list<string>  $screens
+ * @param  list<string>  $operations
+ */
+function bhicCard(string $id, string $surface, array $screens, array $operations): string
+{
+    return "---\n"
+        ."id: {$id}\n"
+        ."title: 見本カード {$id}\n"
+        ."surface: {$surface}\n"
+        ."lane: parallel_browser\n"
+        ."priority: P1\n"
+        ."applicability: applicable\n"
+        ."depends_on: []\n"
+        ."reseed_before: true\n"
+        ."accounts: [guest]\n"
+        ."setup: []\n"
+        .'covers_screens: ['.implode(', ', $screens)."]\n"
+        .'covers_operations: ['.implode(', ', $operations)."]\n"
+        ."covers_capabilities: []\n"
+        ."---\n\n"
+        ."# {$id}: 見本カード {$id}\n\n"
+        ."## 目的\n見本である。\n\n"
+        ."## 手順\n1. 開く → 見える\n\n"
+        ."## 逸脱アイデア (--deviate 時)\n- 二重送信してみる\n";
+}
+
 /**
  * sandbox を組み立てる。scripts/ に検査シェルと生成器、skill ディレクトリに注釈・散文ノート・
  * 機能カタログ、bin/ に `php` shim (固定の scan JSON を吐く) を置く。
@@ -60,10 +99,12 @@ function bhicMakeSandbox(bool $phpFails = false): string
     $sandbox = sys_get_temp_dir().'/bhic-'.bin2hex(random_bytes(6));
     mkdir($sandbox.'/scripts', 0o755, true);
     mkdir($sandbox.'/'.BHIC_SKILL_DIR.'/inventory', 0o755, true);
+    mkdir($sandbox.'/'.BHIC_SKILL_DIR.'/stories', 0o755, true);
     mkdir($sandbox.'/bin', 0o755, true);
 
     copy(bhicScriptPath(), $sandbox.'/scripts/bug-hunt-inventory-check.sh');
     copy(bhicGeneratorPath(), $sandbox.'/scripts/bug-hunt-inventory.py');
+    copy(bhicStoryReaderPath(), $sandbox.'/'.BHIC_SKILL_DIR.'/stories/story_front_matter.py');
 
     $scan = [
         'schema_version' => 1,
@@ -84,8 +125,17 @@ function bhicMakeSandbox(bool $phpFails = false): string
 
     file_put_contents(
         $sandbox.'/'.BHIC_SKILL_DIR.'/inventory/annotations.toml',
-        "schema_version = 1\n\n[routes.\"dashboard\"]\nkind = \"画面\"\nstory = \"S1\"\nkubun = \"通常\"\n\n"
-        ."[routes.\"projects.store\"]\nstory = \"S4\"\nkubun = \"通常\"\n"
+        "schema_version = 1\n\n[routes.\"dashboard\"]\nkind = \"画面\"\nkubun = \"通常\"\n\n"
+        ."[routes.\"projects.store\"]\nkubun = \"通常\"\n"
+    );
+    // 割当の正本はカードの前付けである (注釈には書かない)。対象内 2 route をちょうど覆う。
+    file_put_contents(
+        $sandbox.'/'.BHIC_SKILL_DIR.'/stories/S1-signup.md',
+        bhicCard('S1', 'signup_funnel', ['dashboard'], []),
+    );
+    file_put_contents(
+        $sandbox.'/'.BHIC_SKILL_DIR.'/stories/S2-admin.md',
+        bhicCard('S2', 'org_project_admin', [], ['projects.store']),
     );
     file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/inventory/notes-screens.md', "## 画面の散文\n\n人が書く。\n");
     file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/inventory/notes-operations.md', "## 操作の散文\n\n人が書く。\n");
diff --git a/tests/Architecture/BughuntStoryToolSelfTest.php b/tests/Architecture/BughuntStoryToolSelfTest.php
new file mode 100644
index 00000000..c3434852
--- /dev/null
+++ b/tests/Architecture/BughuntStoryToolSelfTest.php
@@ -0,0 +1,99 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+use Tests\Support\Bughunt\StoryFrontMatterPins;
+
+/*
+ * Architecture invariant: シナリオカードの書式契約の自己テスト (Python) を
+ * `composer test` の下で実走させる。
+ *
+ * 対象は 1 モジュール:
+ *   - test_story_front_matter … 前付けの制限文法・番号規約・表 A / 表 B との突合
+ *
+ * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない (禁止事項 1)。
+ *
+ * 先例は BughuntCoverageToolSelfTest: python3 の不在は **skip ではなく fail** で
+ * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
+ *
+ * 保証しないもの: 本ファイルが見るのは自己テストの**実走と件数と中核負例の成功表示**だけである。
+ * 契約の中身 (何を検査しているか) は Python 側の docstring が正本で、ここには写さない。
+ */
+
+/** カードの書式契約の自己テストの置き場 (作業ディレクトリ)。 */
+function bstStoriesDir(): string
+{
+    return base_path('.claude/skills/app-bug-hunt/stories');
+}
+
+/**
+ * stories ディレクトリで `python3 -m unittest -v <modules...>` を実走し [exitCode, output] を返す。
+ *
+ * @param  list<string>  $modules
+ * @return array{0: int|null, 1: string}
+ */
+function bstRunUnittest(array $modules): array
+{
+    $process = new Process(
+        ['python3', '-m', 'unittest', '-v', ...$modules],
+        bstStoriesDir(),
+        ['PYTHONDONTWRITEBYTECODE' => '1'],
+    );
+    $process->setTimeout(120);
+    $process->run();
+
+    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
+}
+
+test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
+    expect((new Process(['which', 'python3']))->run())->toBe(
+        0,
+        'python3 が PATH に無い。カードの書式契約の自己テストは python3 必須 (stdlib のみ)。'
+    );
+});
+
+test('カードの書式契約の自己テストが composer test の下で通ること', function (): void {
+    expect(is_dir(bstStoriesDir()))->toBeTrue('stories ディレクトリが見つからない: '.bstStoriesDir());
+
+    [$code, $out] = bstRunUnittest(['test_story_front_matter']);
+
+    expect($code)->toBe(0, "カードの書式契約の自己テストが失敗した:\n".$out);
+});
+
+test('負の対照: 存在しないモジュール名を渡すと非 0 になること (空振り gate を作らない)', function (): void {
+    [$code] = bstRunUnittest(['test_no_such_module_exists']);
+
+    expect($code)->not->toBe(0, '存在しないモジュールでも 0 が返る = 実走していない疑い');
+});
+
+test('件数の下限が実測値へ差し替えられていること (0 のままだと検査が無効化される)', function (): void {
+    // MIN_TESTS = 0 の置き忘れは、件数 pin を常に成功させて機構ごと無効にする。
+    // PHPDoc の positive-int だけでは実行時の 0 を防げないので assert で固定する。
+    expect(StoryFrontMatterPins::MIN_TESTS)->toBeGreaterThan(0);
+});
+
+test('活きている検査の件数が下限を下回らないこと (検査を飛ばして緑に見せない)', function (): void {
+    // 件数の下限を実測値で pin する。検査を削って緑にする道を塞ぐ。
+    [$code, $out] = bstRunUnittest(['test_story_front_matter']);
+
+    expect($code)->toBe(0, $out);
+    expect((int) (preg_match('/^Ran (\d+) tests?/m', $out, $m) === 1 ? $m[1] : 0))
+        ->toBeGreaterThanOrEqual(
+            StoryFrontMatterPins::MIN_TESTS,
+            '活きている検査が下限 ('.StoryFrontMatterPins::MIN_TESTS.") を下回った:\n".$out,
+        );
+});
+
+test('中核の負例が名前と成功表示の両方で実在すること (skip 逃げを塞ぐ)', function (): void {
+    // 名前だけを見ると skip でも緑になる。`... ok` まで照合する。
+    // ★ 終了コードもここで確認する。別テストが確認していても**実行は別プロセス**であり、
+    //   同一結果とは限らない。
+    [$code, $out] = bstRunUnittest(['test_story_front_matter']);
+
+    expect($code)->toBe(0, $out);
+
+    foreach (StoryFrontMatterPins::CORE_NEGATIVES as $name) {
+        expect($out)->toMatch('/'.preg_quote($name, '/').'.*\.\.\. ok$/m', "負例 {$name} が ok で実行されていない");
+    }
+});
diff --git a/tests/Support/Bughunt/StoryFrontMatterPins.php b/tests/Support/Bughunt/StoryFrontMatterPins.php
new file mode 100644
index 00000000..8b985f2e
--- /dev/null
+++ b/tests/Support/Bughunt/StoryFrontMatterPins.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Bughunt;
+
+/**
+ * シナリオカードの書式契約の自己テストに対する固定値 (不変の scalar / 配列定数だけを持つ)。
+ *
+ * ★**解析・ファイル I/O・プロセス実行を一切持たない**。値の置き場所を 1 か所にするための型である。
+ *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
+ *   固定値はクラス定数として置く (`Tests\Support\TemplateDivergence\LedgerPins` と同じ理由・同じ作法)。
+ * ★**これは免除の一覧ではない**。個別の検査を名指しして無効化する仕組みは本機構のどこにも無い。
+ */
+final class StoryFrontMatterPins
+{
+    /** インスタンス化しない (定数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 活きている検査の件数の下限 (実測値)。
+     *
+     * ★**下限**である (上振れは許す)。減ることだけを禁じ、検査を削って緑にする道を塞ぐ。
+     */
+    public const int MIN_TESTS = 73;
+
+    /**
+     * 中核の負例。名前だけでなく `... ok` の成功表示まで照合して skip 逃げを塞ぐ。
+     *
+     * @var list<string>
+     */
+    public const array CORE_NEGATIVES = [
+        'test_ac_01_rejects_quoted_scalar',
+        'test_ac_01_rejects_duplicate_key',
+        'test_ac_01_rejects_key_out_of_canonical_order',
+        'test_ac_02_rejects_unknown_lane',
+        'test_ac_03_rejects_gap_in_card_numbers',
+        'test_ac_04_rejects_removed_family_surface',
+        'test_ac_05_rejects_card_missing_from_inventory',
+        'test_ac_06_rejects_reassigned_family_surface',
+        'test_ac_07_rejects_dependency_cycle',
+        'test_ac_08_rejects_reseed_with_dependency',
+        'test_ac_09_rejects_parallel_depending_on_serial',
+        'test_ac_10_rejects_steps_in_not_applicable_card',
+        'test_ac_11_rejects_heading_mismatch',
+        'test_ac_12_rejects_legacy_meta_section',
+        'test_ac_13_rejects_duplicate_array_element',
+        'test_ac_15_rejects_missing_purpose_section',
+    ];
+}
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 80e882c9..9faa7d8e 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 36;
+    public const int DIVERGENCE_ENTRY_COUNT = 37;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 171;
+    public const int ADOPTION_DEBT_COUNT = 168;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index 1f239ab2..76e3931d 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -5,7 +5,6 @@
 .claude/skills/app-bug-hunt/coverage/fixtures/executed.sample.json	360f716d2f09e68d63963c7bac2254c6c2c5a91329a292a9b2ce9dff5cc79fc3
 .claude/skills/app-bug-hunt/coverage/fixtures/operations.sample.md	d7925e4f682fef426ad7836887d19459fd068687c20ec611414caef031bec1ad
 .claude/skills/app-bug-hunt/coverage/merge_pcov.py	58188a2395e3e6217e8a7c529747290a6b320c6a3258f9f4902ad2cc83fbe667
-.claude/skills/app-bug-hunt/coverage/test_correlate.py	586039bd67ac81145d990fcf398885f6809e561184c75aefde3b39a6b007d7aa
 .claude/skills/app-bug-hunt/coverage/test_merge_pcov.py	af796fa2dc20752f5022543cae3029de5a71f2b3a0474a9d8aafc155935388ab
 .claude/skills/app-bug-hunt/coverage/test_out_of_scope.py	4a8681e55ad4005f41578ebc308fa3983babf4547d03f8301fb33c8b5f9f6bb7
 .claude/skills/app-bug-hunt/ledger/README.md	8df5c3a8eec38e1ddaef93bcf8651b2fea84fdfbba8703cbbad897a5ff9eea52
@@ -77,7 +76,6 @@ scripts/setup-browser-testing.sh	eda46c5940927f2dcdf732762429213821388bbb87a4182
 scripts/setup-worktree.sh	cedd1213dcb5c00f5fe19993dfa408a845224750ff090c931b5de4fdc2223dd5
 scripts/teardown-worktree.sh	53fe7eec049a0fa4315ddb27b8b1e804c70f00bd9146006a3509f06bf78db086
 scripts/test-inventory-config.ts	208fbd5d727abf5776bff87ccdda4a7684a80073dfbfe863c6f9245bb368e61d
-scripts/tests/test_bug_hunt_inventory.py	17ed2e5d63cfbd4f6203732c5a52623bce7f0cc30d2bdcf7dfb3798ace564a59
 scripts/vitest-inventory-gate.test.ts	3c17589f7d309f13b542cf1b6ae962b5aad71fd06462cfbd43e4c617f58e807b
 skills-lock.json	3e8e488491111ba3736f79e7954f2c82a75f724edca77f500fe3225aebb07377
 tests/Architecture/AccountDeletionFreezeRouteGateTest.php	82d7b260ef3ae05555e0c08e9e1b1a3bc801e373c8dcf314a806e346cf5c80ac
@@ -90,7 +88,6 @@ tests/Architecture/BfcacheGuardClientContractSyncTest.php	1de798c9587d8d5d70eaa9
 tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php	a97127afa35977e75c350231d9a016758ee56879329217275b8ac48a87b02c6a
 tests/Architecture/BillingRetentionConfigSingleSourceTest.php	d03eb1ed368cb00545deacc424eb57cb0e0e8b6f4ae5442035e7bc0609bc4189
 tests/Architecture/BillingRetentionTargetInventoryTest.php	338da106bfe063adb4f23285933c59c76bb044c44cf802404eab605211b4719b
-tests/Architecture/BugHuntInventoryCheckInvariantTest.php	51195ac2fcd52cb53b21a808bbd62a72ea5b1829360221ec5df758dccc534fd9
 tests/Architecture/BugHuntSkillInvariantTest.php	7ac57d13113b5bb97c6aa252d30f825f8438f3c275281fedabc5e8fd41a837b4
 tests/Architecture/BughuntOrchestratorGateInvariantTest.php	d6c12c7a5faba29643a98f3b8bcabb31b10d957ea59845c4d6b34f0dfa2cc299
 tests/Architecture/CachePayloadPlainDataGateTest.php	c92f8a4b364fcad254869f43327bc5c99a2fa55b618c05428f7e90cbabd87508
```

---

## 移行の検算 (生成物)

# 移行の検算

`devnotes/20260823-0022-bughunt-story-front-matter-adoption/migrate_story_assignment.py verify`
の出力である (手で書かない)。

- 変換前の観測点: `3c9f32d4cdf2b200b60e8e623c0108d05704b0fb` の `.claude/skills/app-bug-hunt/inventory/annotations.toml`
- 判定: **成功**

## 全差分 (欄 / route / 変換前 / 変換後)

| 欄 | route | 変換前 | 変換後 | 判定 |
|---|---|---|---|---|
| screens | capture.manuals.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | capture.takes.playback | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.categories.index | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.edit | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.download | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.edit | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.jobs.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.render-jobs.playback | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.render-jobs.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.manuals.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| screens | projects.show | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | capture.takes.adopt | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | capture.takes.destroy | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.categories.destroy | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.categories.reorder | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.categories.update | S4 | S4 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.manuals.destroy | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.manuals.duplicate | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.manuals.scenario.update | S3 | S3 S7 | 変換後のみ (S7 の追加分) |
| operations | projects.manuals.update | S3 | S3 S7 | 変換後のみ (S7 の追加分) |

## 集計

| 欄 | 一致 | 変換前のみ (落ちた) | 変換後のみ (S7 の追加分) |
|---|---|---|---|
| screens | 60 | 0 | 11 |
| operations | 70 | 0 | 9 |

## 期待する S7 追加分との完全一致

| 欄 | 期待 | 実測 | 判定 |
|---|---|---|---|
| screens | 11 件 (capture.manuals.show, capture.takes.playback, projects.categories.index, projects.edit, projects.manuals.download, projects.manuals.edit, projects.manuals.jobs.show, projects.manuals.render-jobs.playback, projects.manuals.render-jobs.show, projects.manuals.show, projects.show) | 11 件 | 一致 |
| operations | 9 件 (capture.takes.adopt, capture.takes.destroy, projects.categories.destroy, projects.categories.reorder, projects.categories.update, projects.manuals.destroy, projects.manuals.duplicate, projects.manuals.scenario.update, projects.manuals.update) | 9 件 | 一致 |

## 対象外 route (両側とも空集合であること)

| route | 変換前 | 変換後 |
|---|---|---|
| debug.bfcache-trial | (空) | (空) |
| debug.bfcache-trial.away | (空) | (空) |
| debug.login | (空) | (空) |
| password.confirmation | (空) | (空) |
| seo.ai | (空) | (空) |
| seo.llms | (空) | (空) |
| seo.robots | (空) | (空) |
| seo.sitemap | (空) | (空) |
| social.callback | (空) | (空) |
| social.redirect | (空) | (空) |
| two-factor.qr-code | (空) | (空) |
| two-factor.recovery-codes | (空) | (空) |
| two-factor.secret-key | (空) | (空) |
| webhooks.ses | (空) | (空) |

## S7 が踏み直す 11 画面の選定根拠

| 境界の種別 | route |
|---|---|
| project 自身の current-org 境界 | `projects.show` / `projects.edit` |
| project 配下 manual の親子境界 | `projects.manuals.show` / `projects.manuals.edit` / `projects.manuals.download` |
| manual 配下の take / render / job の親子境界 | `projects.manuals.jobs.show` / `projects.manuals.render-jobs.show` / `projects.manuals.render-jobs.playback` |
| project 配下 category の親子境界 | `projects.categories.index` |
| capture 経由で manual / take へ到達する境界 | `capture.manuals.show` / `capture.takes.playback` |

## `## 手順` 節の不変 (移行前後の sha256。先頭 16 文字)

| カード | 移行前 | 移行後 | 判定 |
|---|---|---|---|
| S1 | `be9a3b695a3b8592` | `be9a3b695a3b8592` | 一致 |
| S2 | `b6a4ba3f9daaaf32` | `b6a4ba3f9daaaf32` | 一致 |
| S3 | `80ea3ae1b00418a2` | `80ea3ae1b00418a2` | 一致 |
| S4 | `75b0f67d66c270c4` | `75b0f67d66c270c4` | 一致 |
| S5 | `a3057bd74bc0d83a` | `a3057bd74bc0d83a` | 一致 |
| S6 | `e33bc4030adba9be` | `e33bc4030adba9be` | 一致 |
| S7 | `ebd4526f51f01afb` | `ebd4526f51f01afb` | 一致 |
