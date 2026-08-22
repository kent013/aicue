Round 1 の指摘への対応が完了した。対応マトリクスと修正後の概念設計を示すので、再レビューせよ。

【重要】Round 1 で指摘した Warning 2 件がすべて解消しているかを確認し、全体判定 (APPROVED / CHANGES_REQUESTED) を出せ。新たな指摘がある場合のみ [Critical] [Warning] [Suggestion] で分類して挙げよ。スコープの過大化には特に注意すること — 本件は「正典 v1 に忠実な最小追従」であり、正典が求めていない要素を足す提案は採用しない。

---

## 対応マトリクス (Round 1)

# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: CHANGES_REQUESTED (Critical 0 / Warning 2 / Suggestion 6)

## [Warning] 観点3 — 走査集合とサーバ登録集合の一致保証が無い

- 判断: **対応する**
- 根拠: 正当な指摘。`app/Mcp/Tools/` を走査するだけだと
  「具象クラスは在るがサーバ未登録」= 文書には載るが MCP からは呼べない、が通る。
  既存 `ToolNameInvariantTest` は **登録 ↔ enum** の 2 者しか見ておらず、
  **ディレクトリ実在** という第 3 の集合は誰も見ていない (aicue 実読で確認)。
- 対応内容: 走査の母集団定義を「`app/Mcp/Tools/` 配下の `AppMcpTool` 具象サブクラス」に
  据え置いたうえで (deny-by-default: ファイルを足したら必ず説明責任が生じる形を保つ)、
  **3 集合の完全一致** — 走査集合 = `AppMcpServer::$tools` = `ToolName::cases()` — を
  固定する検査を施策に追加する。既存 `ToolNameInvariantTest` は無改変のまま残し、
  新しい検査が「走査集合」という辺を足す形にする (既存テストの上書き禁止に抵触しない)。

## [Warning] 観点4 — 実リポジトリの生成物に対する `--check` が CI で走る保証が無い

- 判断: **対応する**
- 根拠: 正当な指摘。一時ディレクトリだけで検査を完結させると、
  コミット済みの `docs/help/_generated/mcp-tools.md` が古びても CI は緑のままになる。
  それは正典が名指しした失敗の形 (「生成だけ還流して検査を落とすと、
  生成物が古いまま気付かれない」) そのものである。
- 対応内容: 鮮度検査を **2 系統に分ける**ことを概念設計に明記する。
  (a) **鮮度ゲート本体** = 実リポジトリルートに対する `help:build --check` を 1 本、
      読み取りだけで実行して終了コード 0 を要求する (これが CI を赤くする本体)。
  (b) **振る舞いの検査** = 書き込みを伴う負例 (Stale / Missing / Orphan / 生成で緑へ戻る) は
      一時ディレクトリを root に差し替えて実行し、リポジトリ本体を汚さない。
  併せて「コマンドが root を引数で受け取れる (テストで差し替え可能な) 設計にする」ことを
  制約へ書く (Round 1 の Suggestion 観点3-2 も同時に解消する)。

## [Suggestion] 観点3-2 — テスト隔離のための root 差し替え設計

- 判断: **対応する** (上の Warning 2 の対応に統合)

## [Suggestion] 観点5-1 — 例外メッセージに tool クラス・箇所・修復方法を含める

- 判断: **対応する**
- 対応内容: 「停止するときは対象 tool クラス / 不正なメタデータの箇所 / 直し方を必ず出す」を
  実装方針へ明記する。

## [Suggestion] 観点5-2 — パス検査の負例 (symlink / `..` / 絶対パス / 階層化 / 未登録)

- 判断: **対応する**
- 対応内容: 検査の表に負例の母集団を明記する。

## [Suggestion] 観点7 — vendor 由来の値は `mixed` 境界として正規化し DTO へ閉じ込める

- 判断: **対応する**
- 対応内容: 「`mixed` を上流へ漏らさない」を型安全の制約として明記する。

## [Suggestion] 観点1 / 観点2 / 観点6

- 判断: **対応不要** (肯定的評価。スコープ境界が正典と一致している旨の追認)


---

## 修正後の概念設計 (全文)

# 概念設計: help-system-aicue-propagation

家系の機能台帳 lctl の feature `help-system` (feature_revision `15-c3ee5e7c9ef0` /
ledger_revision `81f0e624363b0c707a424c0695253eb6d1536451`) への aicue 追従設計。

## 背景・課題

### 正典が定めるもの

`help-system` は「アプリ内のヘルプページを Markdown 文書から自動生成する機構」の家系標準形である。
発生元は aigenba、裁定 **AG-100 (2026-08-06)** で **2 部分ともテンプレートへ還流**すると決まり、
`laravel-claude-template` が **v1 実装済み** (観測点 `laravel-claude-template@2b75053` /
再確認 `@1b6920b`)。aicue セルは **status: pending / target version: v1**。

正典の型は **t 系 (テンプレート形)** である — 起点は aigenba だが、家系が追従すべき正本は
テンプレート側の v1 実装であり、aicue はその子アプリである
(`docs/template-fingerprints.json` の `role: app`、出自 pin は
laravel-claude-template `0597a0c24d7fa7a054e3337704ccc97e4409b866` / 947 キー)。

### 正典の必須要素 (裁定 AG-100 の逐語)

1. **ヘルプ本文の取り込み基盤** — `HelpRepository` / `HelpSection` / `help:build` コマンド /
   `docs/help/` の置き場と manifest の規約
2. **MCP ツール一覧の自動生成** — `McpToolReferenceGenerator`。
   **基底クラス参照は各リポジトリの MCP ツール基底へ差し替える** (aicue は `AppMcpTool`)
3. **【必須条件】生成物の鮮度検査** — `help:build --check` にあたるもの。
   > 「生成だけ還流して検査を落とすと、生成物が古いまま気付かれない — 本セッションで反復して
   > 確認した失敗の形である」
   > 「本機構の価値は『生成できること』ではなく『古い生成物を落とせること』にある」

### triage の要約に対する訂正 (重要)

本設計の起票時の triage 要約は正典の必須要素を
「ヘルプ記事のデータモデル / **配信 route** / **MCP ヘルプ tool** / 検査」と記述していたが、
**正典全文を読むと、そのうち 2 つは正典の要求ではない**:

| triage の記述 | 正典の実際 |
|---|---|
| ヘルプ記事の**データモデル** | DB モデルではない。**Markdown ファイルの置き場と manifest.json** である (`docs/help/`) |
| ヘルプ記事の**配信 route** | **正典に無い**。テンプレートは「表示面 (HTTP でヘルプを配る画面) は入れていない。裁定の還流 2 部分に含まれておらず、裁定が『仕組みと置き場の規約のみ』と限っているため」と明記して実装していない |
| **MCP ヘルプ tool** | **正典に無い**。正典の MCP 部分は「MCP **ツール一覧を自動生成する側**」(ヘルプ文書を生成する生成器) であって「ヘルプを返す MCP tool」ではない |
| 検査 | 正しい。**必須条件**である |

したがって本設計は **配信 route も MCP ヘルプ tool も作らない**。これはスコープの過小ではなく、
**正典に忠実な最小**である (テンプレート自身が同じ範囲で `implemented v1` と判定されている)。

### aicue の現状 (実読で確認)

| 観点 | 現状 |
|---|---|
| `app/Services/Help/` | **不在** (0 件) |
| `docs/help/` | **不在** (0 件) |
| `help:build` 相当の Console コマンド | **不在** |
| MCP 基盤 | **在る** — `app/Mcp/Servers/AppMcpServer.php` + `app/Mcp/Tools/` に基底 `AppMcpTool` と read 系 4 tool (`WhoamiTool` / `ListProjectsTool` / `ShowProjectTool` / `ListItemsTool`) |
| MCP の canonical 名 | `App\Enums\Mcp\ToolName` enum (4 case)。`tests/Feature/Mcp/ToolNameInvariantTest.php` が enum ↔ サーバ登録の 1:1 を強制済み |
| 手書きの MCP ツール一覧文書 | **無い** (spirux の `docs/mcp.md` にあたるものは aicue に存在しない) |
| Markdown ライブラリ | **無い** (`league/commonmark` 未導入)。正典も「新規依存を要求しない」と実測している |

すなわち aicue は正典が要求する 3 要素を **1 つも持っていない**。一方で
`mcp-server-core` 側の前提 (走査対象になる基底クラスと登録場所) は完全に揃っている
(正典の `depends_on: mcp-server-core` は充足済み)。

### 課題 (なぜ今やるか)

現状 aicue の MCP ツールは 4 本だが、AI-CUE の使命 (SOP を起点に AI がシナリオを設計し撮影を指示する)
の性質上、外部 AI からアプリ機能を道具として呼ばせる面は今後増える。
**手書きのツール一覧は実装から必ずずれていく** — spirux は「ずれはまだ起きていないだけ」と
台帳に記録されている。aicue は手書き一覧すら持たないので、
「実在しない操作の説明を読まされる」以前に「実在する道具の説明が無い」状態である。
機構を先に入れておけば、tool を足した瞬間に検査が赤くなり、生成 1 回で追従する。

## 改善アイデア

正典 v1 の 3 要素を、aicue の既存 MCP 基盤の上に**最小で**載せる。

1. **置き場と規約** — `docs/help/manifest.json` を宣言の正本、`docs/help/_generated/` を
   生成物の置き場 (**直下のみ。階層を許さない**) とする。手書きページ用の `docs/help/pages/` は
   **規約としてのみ定義し、記事は 0 件で始める** (ヘルプ本文はアプリ固有 = 正典のスコープ外)。
2. **取り込み基盤** — `HelpRepository` が manifest を読み、`HelpSection` の一覧として返す。
   読み取り API 自身が閉じる側に倒れ、パスを組み立てるたびに字句の検査と実体の検査をやり直す。
3. **生成器と台帳** — `HelpGeneratorRegistry::GENERATORS` を
   **許可一覧も除外の口も持たない定数配列**とし、manifest の生成 entry と**完全一致**を強制する
   (deny-by-default)。生成器は `McpToolReferenceGenerator` **1 本だけ**。
   走査対象は aicue 自身の基底 `App\Mcp\Tools\AppMcpTool` である。
4. **唯一の入口と鮮度検査** — `help:build [--check]` を生成と検査の唯一の入口にする。
   `--check` は**作業ツリーを 1 バイトも変えず**比較だけ行い、食い違いがあれば終了コード 1。
   **終了コードは 0 と 1 の 2 値だけ**。この検査を通常のテストレーンから叩き、CI で赤くする。
5. **手書き 0 件でも緑** — 手書きページが 0 件でも `--check` は成功する
   (裁定の「ヘルプ本文の未整備を赤字扱いしない」をそのまま実装する)。
6. **走査集合が実際に配れる道具と一致していること** — 生成器の母集団は
   `app/Mcp/Tools/` 配下の `AppMcpTool` **具象サブクラス**とする (deny-by-default:
   ファイルを足したら必ず表に載るか説明が要る)。ただしそれだけでは
   「具象クラスは在るがサーバ未登録」= 文書には載るのに MCP からは呼べない、が通ってしまう。
   そこで **3 集合の完全一致**を検査で固定する —
   **走査集合 = `AppMcpServer::$tools` = `ToolName::cases()`**。
   既存の `ToolNameInvariantTest` は 2 者 (登録 ↔ enum) しか見ておらず、
   「ディレクトリ実在」という辺が誰にも見られていない。本設計はその辺を足す
   (既存テストは無改変で残す)。

## 期待効果

- **使命への貢献 (間接だが構造的)**: AI-CUE は「思考ゼロ・編集ゼロ」を掲げる。
  その裏返しとして、外部 AI (MCP クライアント) がアプリの道具を正しく理解できることが前提になる。
  ツール一覧が実装から自動生成されることで、**道具の説明が実装からずれない**状態を機構で保証する。
- **「古い生成物を落とせる」ことの獲得**: tool を 1 本足して生成を忘れると CI が赤くなり、
  `php artisan help:build` 1 回で緑へ戻る。追従漏れが人の注意力ではなく機械で止まる。
- **家系の追従**: lctl `help-system` の aicue セルが pending → implemented v1 になる。
  spirux・motivation を残して aicue が先行することで、家系全体の追従率が上がる。
- **将来の表示面への土台**: 置き場と規約が先に決まっているので、後で画面を足すときに
  取り込み層をもう一度設計し直さずに済む (ただし画面は本設計では作らない)。

## 実装方針（概要）

### 新規追加

| 区分 | ファイル | 役割 |
|---|---|---|
| 取り込み | `app/Services/Help/HelpRepository.php` | manifest を読み、`HelpSection` の一覧を返す。パス検査を内蔵 |
| 取り込み | `app/Services/Help/HelpSection.php` | 1 節を表す不変 DTO |
| 取り込み | `app/Services/Help/HelpManifestException.php` | manifest / パスの不正 |
| 台帳 | `app/Services/Help/HelpGeneratorRegistry.php` | 生成器の全数申告 (定数配列)。manifest との完全一致検査 |
| 生成 | `app/Services/Help/Generators/HelpGenerator.php` | 生成器の interface (key / 生成) |
| 生成 | `app/Services/Help/Generators/McpToolReferenceGenerator.php` | MCP ツール一覧の Markdown を生成 |
| 生成 | `app/Services/Help/McpToolMetadata.php` | `AppMcpTool` の具象を走査してメタデータを取り出し、表示用文字列へ正規化 |
| 入口 | `app/Console/Commands/Help/HelpBuildCommand.php` | `help:build [--check]`。生成と鮮度検査の唯一の入口 |
| 生成物 | `docs/help/manifest.json` | 宣言の正本 |
| 生成物 | `docs/help/_generated/mcp-tools.md` | 生成物 |
| 文書 | `docs/help-system.md` | 置き場と規約の運用契約 |

### 検査 (テスト)

| ファイル | 何を固定するか |
|---|---|
| `tests/Architecture/HelpGeneratorRegistryTest.php` | 生成器の全数申告と manifest の完全一致 (許可一覧も除外の口も無いこと) |
| `tests/Architecture/McpToolReferencePopulationTest.php` | **3 集合の完全一致** — 走査集合 = `AppMcpServer::$tools` = `ToolName::cases()` |
| `tests/Feature/Help/HelpBuildFreshnessTest.php` | **鮮度ゲート本体** — 実リポジトリルートに対する `help:build --check` が終了コード 0 (読み取りだけ。これが CI を赤くする) |
| `tests/Feature/Help/HelpBuildCommandTest.php` | 振る舞い — `--check` が作業ツリーを変えないこと / 報告 4 種別 (一致 / Stale / Missing / Orphan) / 終了コード 2 値 / 手書き 0 件でも緑 / 生成 1 回で緑へ戻ること。**書き込みを伴う負例は一時ディレクトリを root に差し替えて実行する** |
| `tests/Feature/Help/HelpRepositoryTest.php` | manifest の読み取りと閉じる側に倒れたパス検査。負例の母集団 = symlink / `..` / 絶対パス / `docs/help/` の外 / `_generated/` の階層化 / manifest 未登録 |
| `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php` | 走査・整形・正規化。vendor メタデータが想定外の形のとき**静かに欠けず停止する**こと |

**鮮度検査を 2 系統に分ける理由**: 一時ディレクトリだけで検査を完結させると、
コミット済みの `docs/help/_generated/mcp-tools.md` が古びても CI は緑のままになる。
それは正典が名指しした失敗の形そのものである。
よって (a) **実リポジトリに対する読み取りだけの `--check`** を鮮度ゲート本体として 1 本立て、
(b) 書き込みを伴う振る舞いの検査は一時 root で行う、と分ける。

### 走査の設計 (aicue 固有の差し替え点)

正典が名指しした「移植時に差し替える 1 行」は **基底クラス参照**である。
aicue では `App\Mcp\Tools\AppMcpTool` を走査対象の基底とし、
`app/Mcp/Tools/` 配下の**具象サブクラス**を母集団にする。
メタデータは vendor (`Laravel\Mcp\Server\Primitive` / `Tool`) の実行時出力
(`name()` / `description()` / `schema()`) から取る。

正典の実装報告が明記した設計判断をそのまま踏襲する:
> パラメータの形の扱いを「閉じた集合で弾く」ではなく「**表示用の文字列へ正規化する**」にした。
> vendor の実行時出力は first-party の型保証の外にあり、閉集合で弾くと正当なツール定義が
> 生成を止めるためである。vendor のメタデータの形が変われば生成は止まる —
> **静かに欠けるより止まるほうがよい**。

**停止するときの報告義務**: 停止は「止まったこと」だけでは直せない。
例外には **対象 tool クラス / 不正だったメタデータの箇所 / 直し方** を必ず含める。

**型境界**: vendor 由来の値 (`name()` / `description()` / `schema()`) は `mixed` 境界として扱い、
正規化関数の中で型検査 → 例外化 → DTO へ閉じ込める。
**`mixed` を戻り値や配列形状として上流へ漏らさない** (PHPStan level 10 を通す前提)。

## 制約・前提

- **PHPStan level 10**: `phpstan.neon` の `paths` は `app / config / database / routes`。
  新規ファイルのうち `app/Services/Help/` と `app/Console/Commands/Help/` は**解析対象**なので
  level 10 を通す設計にする (`tests/` 側は対象外)。
- **新規依存を入れない**: 正典も「言語標準の反射でクラスを走査するだけ」と実測している。
  aicue に Markdown ライブラリは無く、**入れない** (HTML 変換は表示面の仕事であり本設計の範囲外)。
- **`response()->json()` 直書き禁止・DTO 必須** (AGENTS.md 禁止事項 4): 本設計は HTTP 面を持たないが、
  Service の戻り値は配列でなく `HelpSection` / 結果 DTO にする。
- **cache に入れるのは素のデータだけ** (セキュリティ不変条件 11): 本設計は cache を使わない。
- **`RefreshDatabase` グローバル適用 / `--parallel`**: 新規テストは DB を使わない
  (ファイル系) が、並列実行下でも作業ツリーを共有するため、
  **書き込みを伴うテストは一時ディレクトリで行い、リポジトリ本体には書かない**。
  そのために **`help:build` は走査/読み書きの root を引数 (または注入) で受け取れる形**にし、
  既定値だけが本番の `docs/help/` を指すようにする
  (判定ロジックは Service 側に閉じ、コマンドは薄い引数解析層にする)。
- **既存の MCP 不変条件を壊さない**: `ToolNameInvariantTest` / `McpAuthorizationChokePointTest` /
  `McpWriteToolIdempotencyEnforcementTest` は無改変。本設計は MCP 側のコードを 1 行も変えない。
- **乖離台帳**: `app/Services/Help/*` `app/Console/Commands/Help/*` `docs/help/*` `docs/help-system.md` は
  いずれも `docs/template-fingerprints.json` の**キーに存在しない** (母集合 281 件に含まれない)。
  詳細設計の Phase 3-0 で最終判定する。

## スコープ外

正典の裁定・テンプレート実装が**明示的に範囲外としたもの**を、そのまま範囲外にする:

1. **表示面 (HTTP でヘルプを配る画面・route・Svelte ページ)** —
   裁定は「仕組みと置き場の規約のみ」と限っており、テンプレートも入れていない。
2. **MCP のヘルプ参照 tool** — 正典に存在しない要求 (triage の誤読。上の訂正表を参照)。
3. **ヘルプ本文の執筆** — 「ヘルプ本文の中身は各アプリ固有のまま」。
   手書きページは 0 件で始め、**未整備を赤字扱いしない**。
4. **`VideoStepsGenerator` (動画手順の生成器)** — 裁定が名指ししておらず、テンプレートも入れていない。
   正典の `depends_on: manual-video-toolchain` はこの生成器由来であり、v1 の必須要素ではない。
   (aicue は動画マニュアルが本業なので将来の候補ではあるが、**今必要なものだけ作る**)
5. **Markdown → HTML 変換 / `markdown-renderer` feature との統合** —
   正典が `distinct_from` で別層と裁定している。表示面が無い以上、変換する相手がいない。
6. **既存 MCP tool の増設・変更** — 本設計は MCP を**走査するだけ**で変更しない。
7. **`docs/mcp.md` 相当の手書き一覧の差し替え** — aicue には元から無い (spirux 固有の作業)。
8. **lctl への書き込み** — 本設計フローでは行わない (後段の責務)。

