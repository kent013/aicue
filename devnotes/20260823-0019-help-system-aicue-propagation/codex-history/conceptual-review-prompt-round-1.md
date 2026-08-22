【アプリの使命 (North Star)】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件の特殊事情 — 必ず踏まえること】
本件は「家系の機能台帳 (lctl) の feature への追従設計」である。aicue は laravel-claude-template の子アプリであり、正典 (canon) が定めた必須要素を満たす最小のスコープであることが求められる。**正典が求めていないものを足すのは過大**であり、**正典の必須条件を落とすのは過小**である。正典全文を末尾に添付するので、概念設計のスコープ判断が正典と一致しているかを最重要観点として見よ。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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
| `tests/Feature/Help/HelpBuildCommandTest.php` | **鮮度検査が緑であること (これが CI を赤くする本体)** / `--check` が作業ツリーを変えないこと / 報告 4 種別 / 終了コード 2 値 / 手書き 0 件でも緑 |
| `tests/Feature/Help/HelpRepositoryTest.php` | manifest の読み取りと閉じる側に倒れたパス検査 |
| `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php` | 走査・整形・正規化 |

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


---

## 参考: 家系の機能台帳 lctl における feature `help-system` の全文 (get_feature の生出力)

```json
{
 "ok": true,
 "feature": "help-system",
 "feature_revision": "15-c3ee5e7c9ef0",
 "ledger_revision": "81f0e624363b0c707a424c0695253eb6d1536451",
 "feature_yaml": "id: help-system\ntitle: アプリ内ヘルプ生成システム (MCP ツール参照自動生成含む)\nstatus: active\nscope: app\nboundary: '含む: HelpRepository/HelpSection、HelpBuildCommand (help:build)、Generators (HelpGenerator/McpToolReferenceGenerator/VideoStepsGenerator)、docs/help/\n  コーパス (pages 16 + _generated)。ヘルプ本文の内容は [domain] だが生成機構は汎用。含まない: SEO/公開ページ'\ncanonical_version: v1 (ヘルプ取り込み基盤 + MCP ツール一覧の自動生成 + 生成物の鮮度検査。ヘルプ本文の中身は各アプリ固有)\norigin:\n  repo: aigenba\n  refs: app/Services/Help/ + docs/help/ + docs/help-system.md\nrelations:\n- kind: depends_on\n  target: manual-video-toolchain\n  note: aigenba の app/Services/Help/Generators/VideoStepsGenerator.php は tools/manual-video/ 配下の scene\n    spec からナレーション文言を抽出してヘルプの操作手順を生成する (クラス冒頭コメントに「動画の字幕ナレーションとテキスト手順を同一ソースに保つ」と明記)。scene spec が無いと設計意図が成立しない。実装コメントから明白\n    (領域深掘り ui 2026-08-13、devnotes/20260813-2050-lctl-deep-dive-ui/)\n- kind: depends_on\n  target: mcp-server-core\n  note: MCP ツール一覧の自動生成 (McpToolReferenceGenerator / McpToolMetadata) は mcp-server-core が定義する基底 Tool クラスと登録場所を走査対象にしており、mcp-server-core\n    が無いと生成器は何も走査できない。両 feature は既に互いを note で参照している。実装から明白 (領域深掘り ui 2026-08-13、devnotes/20260813-2050-lctl-deep-dive-ui/)\narea: ui\nprojects:\n  laravel-claude-template:\n    status: implemented\n    note: HelpGeneratorRegistry::GENERATORS が allowlist/skip を持たない定数配列でverifyRegistryIsFullyReferenced()がmanifestとの完全一致を強制\n      (deny-by-default)、HelpBuildCommandの--checkオプションが書き込みをせず比較のみ行う分岐を持つこと、docs/help/配下がmanifest.jsonと_generated/mcp-tools.mdのみで手書きpages/が存在しない\n      (0件でも--checkは通る設計) こと、McpToolMetadata.phpの走査対象がlaravel-claude-template自身のMCPツール基底クラスに差し替え済みであることを再確認。既存記述は実装と整合、変更なし。観測点\n      laravel-claude-template@1b6920b。\n    version: v1\n    verification:\n      refs:\n      - laravel-claude-template@2b75053\n      files_touched:\n      - app/Services/Help/HelpRepository.php\n      - app/Services/Help/HelpGeneratorRegistry.php\n      - app/Services/Help/Generators/McpToolReferenceGenerator.php\n      - app/Services/Help/McpToolMetadata.php\n      - app/Console/Commands/Help/HelpBuildCommand.php\n      - docs/help/manifest.json\n      - docs/help/_generated/mcp-tools.md\n      tests:\n      - tests/Architecture/HelpGeneratorRegistryTest.php\n      - tests/Feature/Help/HelpBuildCommandTest.php\n      - tests/Feature/Help/HelpRepositoryTest.php\n      - tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php\n      checked_by: curator (差分巡回 2026-08-12, mirrors 実読)\n    reported:\n      ts: '2026-08-12T17:21:57+09:00'\n      by: laravel-claude-template (claude-code ultracode pipeline B14)\n      refs:\n      - laravel-claude-template@2b75053\n      claimed_status: implemented\n      claimed_version: v1\n  aigenba:\n    status: implemented\n    version: v1\n    note: 規模の実測値 (HelpRepository 223 / HelpSection 22 / McpToolReferenceGenerator 68) は history.md・agenda_resolved\n      の数字とすべて一致。一方でツール一覧の生成物 (_generated/mcp-tools.md) が申告する現在のツール数は31件であり、note/agenda_resolved に書かれた『aigenba\n      32』は陳腐化している (app/Mcp/Tools/直下の*Tool.phpを数えても非Admin系で31件、生成物の申告数と一致)。Admin系ツールは意図的に生成対象から除外。note\n      を『aigenba 31 (2026-08-13時点の生成物実測。運営専用ツールは意図的に除外)』へ更新する。観測点 aigenba@daf0220df。\n    assessment: improvement_candidate\n    verification:\n      refs:\n      - aigenba@f33d7f41\n      files_touched:\n      - app/Services/Help/HelpRepository.php\n      - app/Services/Help/HelpSection.php\n      - app/Services/Help/Generators/HelpGenerator.php\n      - app/Services/Help/Generators/McpToolReferenceGenerator.php\n      - app/Services/Help/Generators/VideoStepsGenerator.php\n      - docs/help/manifest.json\n      - docs/help/_generated/mcp-tools.md\n      tests:\n      - aigenba:tests/Feature/Help/HelpBuildCommandTest.php\n      - aigenba:tests/Feature/Help/HelpControllerTest.php\n      - aigenba:tests/Unit/Services/Help/HelpRepositoryTest.php\n      - aigenba:tests/js/pages/help/HelpShowRender.test.ts\n      no_tests_reason: テストは実在するが本セッションでは実走していない (mirrors の実読によるファイル存在確認のみ)\n      checked_by: curator (裁定 2026-08-06, mirrors 実読でパス・行数・ツール数を確認)\n  spirux:\n    status: pending\n    note: 両方導入の裁定 (2026-08-06) 待ちのまま、docs/mcp.md (9tool のツール表) と app/Mcp/Tools/配下の非Admin Toolクラス9本が1対1で一致することを再確認。app/Services/Help/相当のディレクトリ・docs/help/はいずれも不在。手書きの一覧はまだ実装9toolと一致しておりずれはまだ起きていない。観測点\n      spirux@8b1667dd (app/Services/Help/不在・docs/mcp.md 9件と実装9toolの一致を実読で確認)。\n  aicue:\n    status: pending\n    note: app/Mcp/Tools/の雛形4tool (AppMcpTool基底・WhoamiTool・ListProjectsTool・ShowProjectTool・ListItemsTool)\n      を再確認。app/Services/Help/・docs/help/は0件。ヘルプ機構・MCPツール一覧とも未着手。観測点 aicue@a5553b5、Mcp/Tools雛形4本・help系ディレクトリ不在を実読で確認。\n  motivation:\n    status: pending\n    note: 'motivation:docs/template-divergence.md のD10エントリ (外部連携面=REST API v1/MCPサーバ/APIキー/OAuthセッション/冪等キー基盤の撤去、決めた日2026-08-10、根拠T125)、drop_external_integration_tables.php\n      migration、ExternalIntegrationSurfaceAbsenceGateTest.php (REST API v1/MCPサーバへの参照が再流入しないことを固定するgate)\n      を実読で確認した。app/Mcp/・app/Services/Help/・docs/help/はいずれも0件。旧noteはAG-100裁定時点 (2026-08-06) の『両方導入予定』のままだが、その4日後\n      (2026-08-10) にD10/T125でMCPサーバーを丸ごと撤去しており、(2) MCPツール一覧自動生成は前提が崩れている (存在しない道具の一覧を生成する仕組みを持ち込むことになる)。(1)\n      ヘルプ本文の取り込み基盤はMCPと独立なのでpendingのまま据え置くが、(2) はnot_applicable相当 (理由: D10でMCP撤去。再開条件: motivationが将来MCPを再導入したとき)\n      として扱うべきかは次の裁定で決めること。status自体は現在値の再確認 (pending) に留める。観測点 motivation@312fe806。'\n  metamovics:\n    status: implemented\n    note: 'app/Services/Help/・docs/help/は不在、help:buildに相当するConsoleコマンドもgrepで0件であることを再確認。MCPツールはapp/Mcp/Tools/の雛形4本\n      (AppMcpTool+ListItems/ListProjects/ShowProject/Whoami) のまま。既存記述と完全一致、変更なし。観測点 metamovics@0ac114b。\n\n\n      【差分巡回 2026-08-20】テンプレート 0597a0c の一括取り込み (metamovics@9748106) で本 feature の機構が正典と同一内容で入った (ツリー全数照合:\n      正典 6,841 パスの全数が実在し 6,831 が blob 一致。差分 10 ファイルはすべて意図的逸脱として metamovics:D1〜D4 登録済みか shared 外)。metamovics\n      は現在アプリコードを 1 行も自作しておらず、採用の判断は個別の要件ではなくテンプレートを全量取り込む子アプリ運用そのものによる (docs/template-update.md が手順と見直し条件を持つ)。CI\n      は metamovics:D1 により自動実行されないため、implemented の意味は「機構が実在し、取り込み時に devcontainer で一度緑を確認した」までであり「CI で守られ続けている」ではない。観測点\n      metamovics@c753177 実読。 生成機構一式と docs/help/manifest.json は実在するが、ヘルプ本文は正典のコーパスそのままで metamovics のドメイン記事は\n      0 件である。'\n    verification:\n      refs:\n      - metamovics@9748106\n      - metamovics@c753177\n      files_touched:\n      - app/Services/Help/HelpRepository.php\n      - app/Services/Help/HelpGeneratorRegistry.php\n      - app/Services/Help/Generators/McpToolReferenceGenerator.php\n      tests:\n      - tests/Architecture/HelpGeneratorRegistryTest.php\n      - tests/Feature/Help/HelpBuildCommandTest.php\n      checked_by:\n        method: curator (差分巡回 2026-08-20, mirrors 実読 + ツリー全数 blob 照合)\n        commit: c753177\nsummary: アプリ内のヘルプページをMarkdown文書から自動生成する仕組みで、発生元はaigenba。AI連携機能 (MCP) のツール一覧リファレンスも実装から自動生成するため、手書きの説明が実装とずれていく問題を構造的に防げる。テンプレートへ両部分とも還流する裁定\n  (AG-100) が下り、laravel-claude-templateでは実装済み。残るspirux・aicue・motivationへの追従は未着手で、metamovicsには仕組みが無い。motivationは2026-08-10にMCPサーバー自体を撤去しており\n  (motivation:D10)、MCPツール一覧生成の部分は前提が崩れている。\nagenda_resolved: 【裁定 2026-08-06】aigenba のヘルプ生成機構を 2 部分ともテンプレートの標準機能として還流する (オーナー判断。キュレーターの起草推奨は『(2) のみ還流』だったが、オーナーが『両方還流させて』と判断した)。還流するのは\n  (1) ヘルプ本文の取り込み基盤 (HelpRepository / HelpSection / help:build コマンド / docs/help/ の置き場と manifest の規約) と、(2)\n  MCP ツール一覧の自動生成 (McpToolReferenceGenerator) の両方である。ヘルプ本文の中身は各アプリ固有のままとし、還流するのは機構と置き場の規約だけである。【必須条件】生成物の鮮度検査\n  (aigenba の help:build --check にあたるもの) を含めて還流すること。生成だけ還流して検査を落とすと、生成物が古いまま気付かれない — 本セッションで反復して確認した失敗の形である。【適用順】テンプレートへ入れてから\n  4 リポジトリへ追従させる。spirux は既存の手書きのツール表 (spirux:docs/mcp.md) を生成へ差し替える。【基底クラス名の差】McpToolReferenceGenerator\n  は各リポジトリの MCP ツール基底クラスを直接参照しているため (aigenba は AigenbaMcpTool、他は AppMcpTool / SpiruxMcpTool)、移植時はこの 1 行を各リポジトリの基底クラスへ差し替える。\n  — 【実測 (2026-08-06、mirrors 実読)】MCP の仕組みは 5 リポジトリすべてが保有しているが、ツール数は aigenba 32 / spirux 9 / 残る 3 つ (laravel-claude-template・aicue・motivation)\n  は雛形のまま 4 と大きく違う。手書きのツール一覧が存在するのは spirux だけで (spirux:docs/mcp.md に 9 件の表)、現時点では実装と一致している — ずれは「まだ起きていない」だけである。ヘルプページの仕組み\n  (docs/help/) は aigenba にしかなく、他 4 リポジトリにはディレクトリ自体が存在しない。規模は (1) が約 245 行 (HelpRepository 223 + HelpSection\n  22)、(2) が 68 行で、いずれも新規依存を要求しない (言語標準の反射でクラスを走査するだけ)。【起草推奨との差】キュレーターは『(2) だけ還流し (1) は還流しない』を推奨した。理由は『ヘルプ本文の中身は製品ごとに全く違い、docs/help/\n  が存在しないのは必要が無いからだと考えるのが自然で、仕組みだけ配っても使われなければ維持対象が増える』というもの。オーナーは両方還流を選んだ。この判断は『後で必要になったときすぐ使える』ことを取るもので、代償として使われない仕組みが\n  4 リポジトリへ増える。ヘルプ本文の未整備を赤字扱いしないことを明記する (仕組みの保有とページの執筆は別である)。【検査を必須にする根拠】本機構の価値は「生成できること」ではなく「古い生成物を落とせること」にある。aigenba\n  の実装コメント自体が『ツールを追加すると自動で表に載る (= ヘルプ側の追従漏れを help:build --check で検知)』と明記しており、検査が対で初めて機能する設計である。\n",
 "design_md": null,
 "history": {
  "overview": "**位置付け**: 画面 (アプリ内のヘルプ) / 配備時 (ヘルプの組み立て) と CI 実行時 (生成物の鮮度検査) / 無いと機能を変えるたびに手書きの説明が置き去りになり、利用者が実在しない操作の説明を読まされる。\n\nヘルプの本文を Markdown の文書として置き、専用のビルドコマンドが読み取り層と生成器を通してアプリ内のヘルプページを組み立てる仕組み。AI 連携 (MCP — 外部の AI からアプリの機能を道具として呼べるようにする仕組み) のツール一覧は、手で書かずに実装のクラスを走査して生成する。同じコマンドが検査の役目も兼ねていて、生成物が実装から古びたままなら CI が赤くなる — 生成できることより、古い生成物を落とせることの方がこの仕組みの値打ちである。\n\n置き場・生成器・ビルドコマンドという骨格は汎用で、アプリ固有なのはヘルプの本文だけ、という切り分けになっているのが家系で共有できる理由である。とりわけ手書きのツール一覧は実装から必ずずれていくので、MCP を持つ系列すべてに効く。発生元は aigenba で、ツール 32 件の一覧を自動生成し本文 16 ページを抱えている。テンプレート側は台帳の記述をもとに同等の取り込み基盤を実装しており (原本との同一性は主張していない)、家系 6 リポジトリのうち実装済みはこの 2 つ。spirux・aicue・motivation は導入が決まったまま未着手で、metamovics には仕組みが無い。",
  "background": "aigenba が自アプリのヘルプ整備のために作った機構で、文書置き場・生成器・ビルドコマンドという骨格は汎用、ヘルプの本文だけがアプリ固有という切り分けの良い設計になっている。当初のサーベイでは全プロジェクト「検討中」のまま評価欄が空欄で放置されていたが、共通化再精査 (2026-08-04) で「テンプレート行の備考に『MCP ツール参照の自動生成は MCP 保有全系列で有効』と書かれながら候補として起票されていない、拾い漏れの典型」として発見された。精査レポートは効果「大」(全アプリでヘルプ整備が機構化され、ツールリファレンスのずれが消滅)・移植コスト「中」・優先度「高」と評価し、ツール参照生成器は基底クラス 1 行の差でテンプレートへ移植できるという実測も添えて、テンプレート標準機能への還流可否を正式に議題化した。裁定はまだ下っておらず、議題化の経緯と根拠がこの feature の現在の記録である。",
  "work_log": "（作業ログをここに追記する。書式: `- YYYY-MM-DD <やったこと> — 実装: <repo>@<commit> / 台帳: lctl@<commit>`）\n\n- 2026-08-13 領域深掘り (ui) で観測を洗い替え、aigenba の note の「ツール数 32」を実測「31」へ訂正し、motivation の note を D10/T125 (MCP サーバー撤去) との整合を反映した内容へ全面書き直しした — 実装: なし (台帳の観測のみ) / 観測: laravel-claude-template@1b6920b / aigenba@daf0220df / spirux@8b1667dd / aicue@a5553b5 / motivation@312fe806 / metamovics@0ac114b / 台帳: lctl@c428ddbe"
 },
 "recent_events": [
  {
   "ts": "2026-08-07T10:41:49+09:00",
   "type": "survey_recorded",
   "mode": "patch",
   "actor": "curator (lctl-curate 巡回)",
   "feature": "help-system",
   "note": "metamovics 初回観測サーベイ (新規登録プロジェクト。mirrors/metamovics@0ac114b 実読)",
   "observed": [
    {
     "project": "metamovics",
     "set": {
      "status": "pending",
      "note": "app/Services/Help/ も docs/help/ も存在せず、help:build に相当する Console コマンドも grep で 0 件 (metamovics@0ac114b)。MCP ツールは app/Mcp/Tools/ の雛形 4 本 (AppMcpTool + ListItems/ListProjects/ShowProject/Whoami) のままで、手書きのツール一覧文書も docs/ に無い。"
     }
    }
   ]
  },
  {
   "ts": "2026-08-09T12:00:56+09:00",
   "type": "survey_recorded",
   "actor": "curator",
   "feature": "help-system",
   "note": "徹底再サーベイ (mirrors=origin 基準) による台帳再構築",
   "group": "platform-api-mcp",
   "baseline": {
    "catalog_ref": "devnotes/20260804-0130-thorough-survey/catalog",
    "generator_version": "0.2"
   }
  },
  {
   "ts": "2026-08-12T19:20:00+09:00",
   "type": "survey_recorded",
   "mode": "patch",
   "actor": "curator (lctl-curate 巡回)",
   "feature": "help-system",
   "note": "差分巡回 2026-08-12 (mirrors 実読。所見: devnotes/20260812-1440-lctl-curate/sweep-laravel-claude-template.md)",
   "observed": [
    {
     "project": "laravel-claude-template",
     "set": {
      "status": "implemented",
      "version": "v1",
      "note": "裁定 2026-08-06 (aigenba のヘルプ生成機構を2部分とも還流する。基底クラス参照は自リポジトリの MCP ツール基底クラスへ差し替える) の必須条件 (生成物の鮮度検査を含めて還流すること) を含めて満たした。取り込み基盤 app/Services/Help/HelpRepository.php (631行) / HelpSection.php / HelpManifestException.php、鮮度検査の対象の全数申告を持つ app/Services/Help/HelpGeneratorRegistry.php (台帳に載っていない生成器は検査の対象から外れ、許可一覧も除外の口も持たない)、MCP ツール一覧の自動生成 app/Services/Help/Generators/McpToolReferenceGenerator.php / McpToolReferenceRenderer.php / app/Services/Help/McpToolMetadata.php、生成と鮮度検査の唯一の入口 app/Console/Commands/Help/HelpBuildCommand.php (405行。終了コードは0と1の2値だけ) を確認した。docs/help/manifest.json と docs/help/_generated/mcp-tools.md が生成物として実在する。**裁定どおりヘルプ本文の執筆は範囲外**で、手書きページは0件である (docs/help/ にあるのは manifest.json と _generated/mcp-tools.md のみ)。基底クラス参照はテンプレート自身の MCP ツール基底クラスへ差し替え済みである。【差分巡回 2026-08-12】上記ファイル群と検査6本の実在を実読で確認した。(観測点 laravel-claude-template@2b75053)",
      "verification": {
       "refs": [
        "laravel-claude-template@2b75053"
       ],
       "files_touched": [
        "app/Services/Help/HelpRepository.php",
        "app/Services/Help/HelpGeneratorRegistry.php",
        "app/Services/Help/Generators/McpToolReferenceGenerator.php",
        "app/Services/Help/McpToolMetadata.php",
        "app/Console/Commands/Help/HelpBuildCommand.php",
        "docs/help/manifest.json",
        "docs/help/_generated/mcp-tools.md"
       ],
       "tests": [
        "tests/Architecture/HelpGeneratorRegistryTest.php",
        "tests/Feature/Help/HelpBuildCommandTest.php",
        "tests/Feature/Help/HelpRepositoryTest.php",
        "tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php"
       ],
       "checked_by": "curator (差分巡回 2026-08-12, mirrors 実読)"
      }
     }
    }
   ]
  },
  {
   "ts": "2026-08-12T16:21:19+09:00",
   "type": "survey_recorded",
   "actor": "curator",
   "feature": "help-system",
   "note": "徹底再サーベイ (mirrors=origin 基準) による台帳再構築",
   "group": "platform-api-mcp",
   "baseline": {
    "catalog_ref": "devnotes/20260804-0130-thorough-survey/catalog",
    "generator_version": "0.2"
   }
  },
  {
   "ts": "2026-08-12T17:20:05+09:00",
   "type": "agenda_opened",
   "actor": "lctl-mcp-server",
   "feature": "help-system",
   "conflict_id": "0c784131c03f",
   "topic": "楽観ロック競合: 提出イベント (status_reported, actor laravel-claude-template (claude-code ultracode pipeline B14)) が revision 不一致で不受理 (expected 8-c96faf4f0b0e / current 9-6ef96cbaa4a5)",
   "submitted_event": {
    "type": "status_reported",
    "actor": "laravel-claude-template (claude-code ultracode pipeline B14)",
    "status": "implemented",
    "version": "v1",
    "refs": [
     "laravel-claude-template@2b75053"
    ],
    "note": "laravel-claude-template でヘルプの取り込み基盤を実装した (laravel-claude-template:T100、B14 第 1 波)。ドナー (aigenba) の mirror は本環境に無く、本実装は台帳記述からの等価実装である。原本との同一性は主張しない。\n\n【裁定 AG-100 との逐語対応】裁定が必須とした 3 点をすべて入れた。1. ヘルプ本文の取り込み基盤 — 置き場と宣言ファイルを読む層と、その読み取り API を実装した。読み取り API 自身が閉じる側に倒れており、パスを組み立てるたびに字句の検査と実体の検査をやり直す。生成物は生成物用ディレクトリの直下だけに限った (階層を許すと孤児の検査に再帰走査が要るため)。2. MCP ツール一覧の自動生成 — ツールの基底クラスを走査してツール一覧を組み立てる生成器を入れた。裁定が指摘していた「基底クラス名はリポジトリごとに違う」点は、テンプレートの基底クラスへ差し替える形で解決している。3. 生成物の鮮度検査 — 検査モードを持つ artisan コマンドを唯一の入口とし、その検査を通常のテストレーンから叩いて CI で赤くする。裁定が書いた「本機構の価値は生成できることではなく古い生成物を落とせること」をそのまま検査の形にした。あわせて裁定の要求どおり「ヘルプ本文の未整備は赤にしない」を実装と規約の両方に書いた。手書きページが 0 件でも検査モードは成功する。対の動きは実測済みである — MCP ツールを 1 本仮に足すと検査モードが種別と直し方を出して赤くなり、生成コマンド 1 回で緑へ戻る。\n\n【実装しなかったこと・残差分】1. 表示面 (HTTP でヘルプを配る画面) は入れていない。裁定の還流 2 部分に含まれておらず、裁定が「仕組みと置き場の規約のみ」と限っているためである。2. 動画手順の生成器は入れていない。裁定が名指ししておらず、汎用かどうかを台帳から判断できない。3. パラメータの形の扱いを設計から変えた。「閉じた集合で弾く」ではなく「表示用の文字列へ正規化する」にした。vendor の実行時出力は first-party の型保証の外にあり、閉集合で弾くと正当なツール定義が生成を止めるためである (確認ラウンドの実測で確定)。vendor のメタデータの形が変われば生成は止まる — 静かに欠けるより止まるほうがよい。4. 生成物と本文は per-app のデータとして扱い、逸脱の検査では共有扱いにしていない。テンプレート必須の生成 entry を守るのは生成器の台帳の名指しの固定だけである。\n\n【新設・変更した機械ゲート】新設: 生成器の全数申告と走査結果の完全一致を強制する Architecture の検査 1 本 (許可一覧も除外の口も持たない) / 生成と鮮度検査のコマンドの振る舞いを見る Feature の検査 2 本 (検査モードが作業ツリーを変えないこと、報告が 4 種別、終了コードが 2 値) / メタデータの読み取り・生成・整形の単体検査 3 本。変更: 手で叩くコマンドの分類台帳へ 1 件、逸脱の分類規則へ 5 本の規則を追加。\n\n【実測】マージ後 main の全レーン緑 (composer test 4230 passed / phpstan level 10 No errors / pint / pnpm 系すべて)。追従リポジトリへの展開は未了 (各セルは pending のまま)。",
    "feature": "help-system",
    "ts": "2026-08-12T17:20:05+09:00",
    "project": "laravel-claude-template"
   },
   "expected_revision": "8-c96faf4f0b0e",
   "current_revision": "9-6ef96cbaa4a5"
  },
  {
   "type": "status_reported",
   "actor": "laravel-claude-template (claude-code ultracode pipeline B14)",
   "status": "implemented",
   "version": "v1",
   "refs": [
    "laravel-claude-template@2b75053"
   ],
   "note": "laravel-claude-template でヘルプの取り込み基盤を実装した (laravel-claude-template:T100、B14 第 1 波)。ドナー (aigenba) の mirror は本環境に無く、本実装は台帳記述からの等価実装である。原本との同一性は主張しない。\n\n【裁定 AG-100 との逐語対応】裁定が必須とした 3 点をすべて入れた。1. ヘルプ本文の取り込み基盤 — 置き場と宣言ファイルを読む層と、その読み取り API を実装した。読み取り API 自身が閉じる側に倒れており、パスを組み立てるたびに字句の検査と実体の検査をやり直す。生成物は生成物用ディレクトリの直下だけに限った (階層を許すと孤児の検査に再帰走査が要るため)。2. MCP ツール一覧の自動生成 — ツールの基底クラスを走査してツール一覧を組み立てる生成器を入れた。裁定が指摘していた「基底クラス名はリポジトリごとに違う」点は、テンプレートの基底クラスへ差し替える形で解決している。3. 生成物の鮮度検査 — 検査モードを持つ artisan コマンドを唯一の入口とし、その検査を通常のテストレーンから叩いて CI で赤くする。裁定が書いた「本機構の価値は生成できることではなく古い生成物を落とせること」をそのまま検査の形にした。あわせて裁定の要求どおり「ヘルプ本文の未整備は赤にしない」を実装と規約の両方に書いた。手書きページが 0 件でも検査モードは成功する。対の動きは実測済みである — MCP ツールを 1 本仮に足すと検査モードが種別と直し方を出して赤くなり、生成コマンド 1 回で緑へ戻る。\n\n【実装しなかったこと・残差分】1. 表示面 (HTTP でヘルプを配る画面) は入れていない。裁定の還流 2 部分に含まれておらず、裁定が「仕組みと置き場の規約のみ」と限っているためである。2. 動画手順の生成器は入れていない。裁定が名指ししておらず、汎用かどうかを台帳から判断できない。3. パラメータの形の扱いを設計から変えた。「閉じた集合で弾く」ではなく「表示用の文字列へ正規化する」にした。vendor の実行時出力は first-party の型保証の外にあり、閉集合で弾くと正当なツール定義が生成を止めるためである (確認ラウンドの実測で確定)。vendor のメタデータの形が変われば生成は止まる — 静かに欠けるより止まるほうがよい。4. 生成物と本文は per-app のデータとして扱い、逸脱の検査では共有扱いにしていない。テンプレート必須の生成 entry を守るのは生成器の台帳の名指しの固定だけである。\n\n【新設・変更した機械ゲート】新設: 生成器の全数申告と走査結果の完全一致を強制する Architecture の検査 1 本 (許可一覧も除外の口も持たない) / 生成と鮮度検査のコマンドの振る舞いを見る Feature の検査 2 本 (検査モードが作業ツリーを変えないこと、報告が 4 種別、終了コードが 2 値) / メタデータの読み取り・生成・整形の単体検査 3 本。変更: 手で叩くコマンドの分類台帳へ 1 件、逸脱の分類規則へ 5 本の規則を追加。\n\n【実測】マージ後 main の全レーン緑 (composer test 4230 passed / phpstan level 10 No errors / pint / pnpm 系すべて)。追従リポジトリへの展開は未了 (各セルは pending のまま)。なお本報告の初回提出はキュレーター巡回との競合で不受理になったための再提出であり、内容は同一である (conflict_id 0c784131c03f)。",
   "feature": "help-system",
   "ts": "2026-08-12T17:21:57+09:00",
   "project": "laravel-claude-template"
  },
  {
   "ts": "2026-08-12T22:43:48+09:00",
   "type": "area_assigned",
   "actor": "curator",
   "feature": "help-system",
   "area": "ui",
   "rationale": "利用者向けヘルプというコンテンツの提供",
   "refs": [
    "devnotes/20260812-2229-area-classification/assignments.yaml"
   ]
  },
  {
   "ts": "2026-08-13T21:40:00+09:00",
   "type": "survey_recorded",
   "mode": "patch",
   "actor": "curator (lctl-deep-dive ui)",
   "feature": "help-system",
   "note": "領域深掘り 2026-08-13 (mirrors 実読。所見: devnotes/20260813-2050-lctl-deep-dive-ui/features/help-system.md)",
   "observed": [
    {
     "project": "laravel-claude-template",
     "set": {
      "status": "implemented",
      "note": "HelpGeneratorRegistry::GENERATORS が allowlist/skip を持たない定数配列でverifyRegistryIsFullyReferenced()がmanifestとの完全一致を強制 (deny-by-default)、HelpBuildCommandの--checkオプションが書き込みをせず比較のみ行う分岐を持つこと、docs/help/配下がmanifest.jsonと_generated/mcp-tools.mdのみで手書きpages/が存在しない (0件でも--checkは通る設計) こと、McpToolMetadata.phpの走査対象がlaravel-claude-template自身のMCPツール基底クラスに差し替え済みであることを再確認。既存記述は実装と整合、変更なし。観測点 laravel-claude-template@1b6920b。"
     }
    },
    {
     "project": "aigenba",
     "set": {
      "status": "implemented",
      "note": "規模の実測値 (HelpRepository 223 / HelpSection 22 / McpToolReferenceGenerator 68) は history.md・agenda_resolved の数字とすべて一致。一方でツール一覧の生成物 (_generated/mcp-tools.md) が申告する現在のツール数は31件であり、note/agenda_resolved に書かれた『aigenba 32』は陳腐化している (app/Mcp/Tools/直下の*Tool.phpを数えても非Admin系で31件、生成物の申告数と一致)。Admin系ツールは意図的に生成対象から除外。note を『aigenba 31 (2026-08-13時点の生成物実測。運営専用ツールは意図的に除外)』へ更新する。観測点 aigenba@daf0220df。"
     }
    },
    {
     "project": "spirux",
     "set": {
      "status": "pending",
      "note": "両方導入の裁定 (2026-08-06) 待ちのまま、docs/mcp.md (9tool のツール表) と app/Mcp/Tools/配下の非Admin Toolクラス9本が1対1で一致することを再確認。app/Services/Help/相当のディレクトリ・docs/help/はいずれも不在。手書きの一覧はまだ実装9toolと一致しておりずれはまだ起きていない。観測点 spirux@8b1667dd (app/Services/Help/不在・docs/mcp.md 9件と実装9toolの一致を実読で確認)。"
     }
    },
    {
     "project": "aicue",
     "set": {
      "status": "pending",
      "note": "app/Mcp/Tools/の雛形4tool (AppMcpTool基底・WhoamiTool・ListProjectsTool・ShowProjectTool・ListItemsTool) を再確認。app/Services/Help/・docs/help/は0件。ヘルプ機構・MCPツール一覧とも未着手。観測点 aicue@a5553b5、Mcp/Tools雛形4本・help系ディレクトリ不在を実読で確認。"
     }
    },
    {
     "project": "motivation",
     "set": {
      "status": "pending",
      "note": "motivation:docs/template-divergence.md のD10エントリ (外部連携面=REST API v1/MCPサーバ/APIキー/OAuthセッション/冪等キー基盤の撤去、決めた日2026-08-10、根拠T125)、drop_external_integration_tables.php migration、ExternalIntegrationSurfaceAbsenceGateTest.php (REST API v1/MCPサーバへの参照が再流入しないことを固定するgate) を実読で確認した。app/Mcp/・app/Services/Help/・docs/help/はいずれも0件。旧noteはAG-100裁定時点 (2026-08-06) の『両方導入予定』のままだが、その4日後 (2026-08-10) にD10/T125でMCPサーバーを丸ごと撤去しており、(2) MCPツール一覧自動生成は前提が崩れている (存在しない道具の一覧を生成する仕組みを持ち込むことになる)。(1) ヘルプ本文の取り込み基盤はMCPと独立なのでpendingのまま据え置くが、(2) はnot_applicable相当 (理由: D10でMCP撤去。再開条件: motivationが将来MCPを再導入したとき) として扱うべきかは次の裁定で決めること。status自体は現在値の再確認 (pending) に留める。観測点 motivation@312fe806。"
     }
    },
    {
     "project": "metamovics",
     "set": {
      "status": "pending",
      "note": "app/Services/Help/・docs/help/は不在、help:buildに相当するConsoleコマンドもgrepで0件であることを再確認。MCPツールはapp/Mcp/Tools/の雛形4本 (AppMcpTool+ListItems/ListProjects/ShowProject/Whoami) のまま。既存記述と完全一致、変更なし。観測点 metamovics@0ac114b。"
     }
    },
    {
     "feature_set": {
      "summary": "アプリ内のヘルプページをMarkdown文書から自動生成する仕組みで、発生元はaigenba。AI連携機能 (MCP) のツール一覧リファレンスも実装から自動生成するため、手書きの説明が実装とずれていく問題を構造的に防げる。テンプレートへ両部分とも還流する裁定 (AG-100) が下り、laravel-claude-templateでは実装済み。残るspirux・aicue・motivationへの追従は未着手で、metamovicsには仕組みが無い。motivationは2026-08-10にMCPサーバー自体を撤去しており (motivation:D10)、MCPツール一覧生成の部分は前提が崩れている。"
     }
    }
   ]
  },
  {
   "type": "relation_declared",
   "ts": "2026-08-14T10:43:00+09:00",
   "actor": "curator (lctl-deep-dive ui 2026-08-13)",
   "op": "add",
   "relations": [
    {
     "kind": "depends_on",
     "target": "mcp-server-core",
     "note": "MCP ツール一覧の自動生成 (McpToolReferenceGenerator / McpToolMetadata) は mcp-server-core が定義する基底 Tool クラスと登録場所を走査対象にしており、mcp-server-core が無いと生成器は何も走査できない。両 feature は既に互いを note で参照している。実装から明白 (領域深掘り ui 2026-08-13、devnotes/20260813-2050-lctl-deep-dive-ui/)"
    },
    {
     "kind": "depends_on",
     "target": "manual-video-toolchain",
     "note": "aigenba の app/Services/Help/Generators/VideoStepsGenerator.php は tools/manual-video/ 配下の scene spec からナレーション文言を抽出してヘルプの操作手順を生成する (クラス冒頭コメントに「動画の字幕ナレーションとテキスト手順を同一ソースに保つ」と明記)。scene spec が無いと設計意図が成立しない。実装コメントから明白 (領域深掘り ui 2026-08-13、devnotes/20260813-2050-lctl-deep-dive-ui/)"
    }
   ],
   "feature": "help-system"
  },
  {
   "ts": "2026-08-21T00:30:00+09:00",
   "type": "survey_recorded",
   "mode": "patch",
   "actor": "curator (lctl-curate 差分巡回 2026-08-20)",
   "feature": "help-system",
   "note": "差分巡回 2026-08-20 (所見: devnotes/20260820-2322-lctl-curate/sweep-metamovics.md — テンプレート 0597a0c 一括取り込みの全数判定)",
   "observed": [
    {
     "project": "metamovics",
     "set": {
      "status": "implemented",
      "note": "app/Services/Help/・docs/help/は不在、help:buildに相当するConsoleコマンドもgrepで0件であることを再確認。MCPツールはapp/Mcp/Tools/の雛形4本 (AppMcpTool+ListItems/ListProjects/ShowProject/Whoami) のまま。既存記述と完全一致、変更なし。観測点 metamovics@0ac114b。\n\n【差分巡回 2026-08-20】テンプレート 0597a0c の一括取り込み (metamovics@9748106) で本 feature の機構が正典と同一内容で入った (ツリー全数照合: 正典 6,841 パスの全数が実在し 6,831 が blob 一致。差分 10 ファイルはすべて意図的逸脱として metamovics:D1〜D4 登録済みか shared 外)。metamovics は現在アプリコードを 1 行も自作しておらず、採用の判断は個別の要件ではなくテンプレートを全量取り込む子アプリ運用そのものによる (docs/template-update.md が手順と見直し条件を持つ)。CI は metamovics:D1 により自動実行されないため、implemented の意味は「機構が実在し、取り込み時に devcontainer で一度緑を確認した」までであり「CI で守られ続けている」ではない。観測点 metamovics@c753177 実読。 生成機構一式と docs/help/manifest.json は実在するが、ヘルプ本文は正典のコーパスそのままで metamovics のドメイン記事は 0 件である。",
      "verification": {
       "refs": [
        "metamovics@9748106",
        "metamovics@c753177"
       ],
       "files_touched": [
        "app/Services/Help/HelpRepository.php",
        "app/Services/Help/HelpGeneratorRegistry.php",
        "app/Services/Help/Generators/McpToolReferenceGenerator.php"
       ],
       "tests": [
        "tests/Architecture/HelpGeneratorRegistryTest.php",
        "tests/Feature/Help/HelpBuildCommandTest.php"
       ],
       "checked_by": {
        "method": "curator (差分巡回 2026-08-20, mirrors 実読 + ツリー全数 blob 照合)",
        "commit": "c753177"
       }
      }
     }
    }
   ]
  }
 ],
 "sources": [
  "devnotes/20260804-0130-thorough-survey/asset-matrix-summary.md",
  "devnotes/20260804-0130-thorough-survey/enum-aigenba.md",
  "devnotes/20260804-0846-agenda-execution/analysis-commonization-aigenba.md",
  "devnotes/20260804-0846-agenda-execution/evidence-commonization-aigenba-packages-docs.md",
  "devnotes/20260804-1426-c2c-curate/asset-matrix-summary.md",
  "devnotes/20260805-0007-agenda-owner-rulings/question-queue.md",
  "devnotes/20260805-2223-c2c-curate/asset-matrix-summary.md",
  "devnotes/20260806-1153-c2c-curate/asset-matrix-summary.md",
  "devnotes/20260806-1153-c2c-curate/notes-agenda-audit.md",
  "devnotes/20260806-1330-owner-rulings-queue/question-queue.md",
  "devnotes/20260807-1031-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260808-0116-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260808-1326-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260809-0909-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260809-1640-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260810-0934-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260812-1440-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260812-1440-lctl-curate/feature-index.md",
  "devnotes/20260812-1440-lctl-curate/sweep-laravel-claude-template.md",
  "devnotes/20260812-2128-todo-t006/trial-classification.md",
  "devnotes/20260813-2030-lctl-deep-dive-api/features/mcp-server-core.md",
  "devnotes/20260813-2050-lctl-deep-dive-ui/README.md",
  "devnotes/20260813-2050-lctl-deep-dive-ui/coverage.md",
  "devnotes/20260813-2050-lctl-deep-dive-ui/drafts/coverage.md",
  "devnotes/20260813-2050-lctl-deep-dive-ui/drafts/fixes.md",
  "devnotes/20260813-2050-lctl-deep-dive-ui/features/help-system.md",
  "devnotes/20260813-2050-lctl-deep-dive-ui/features/manual-video-toolchain.md",
  "devnotes/20260813-2050-lctl-deep-dive-ui/features/markdown-renderer.md",
  "devnotes/20260813-2125-lctl-deep-dive-async/README.md",
  "devnotes/20260813-2125-lctl-deep-dive-async/drafts/fixes.md",
  "devnotes/20260813-2125-lctl-deep-dive-async/features/external-client-timeout-pinning.md",
  "devnotes/20260814-1900-adjudication-batch/batch.md",
  "devnotes/20260814-1900-adjudication-batch/type5-application.md",
  "devnotes/20260815-1227-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260816-1605-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260816-2013-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260817-0905-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260817-1338-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260817-1523-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260818-0925-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260818-2017-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260819-2221-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260820-2322-lctl-curate/asset-matrix-summary.md",
  "devnotes/20260820-2322-lctl-curate/sweep-metamovics.md",
  "devnotes/20260821-1649-lctl-curate/asset-matrix-summary.md",
  "digest/2026-08-04-v3.md",
  "digest/2026-08-06-v2.md",
  "digest/2026-08-13.md"
 ],
 "relations": [
  {
   "kind": "depends_on",
   "target": "manual-video-toolchain",
   "note": "aigenba の app/Services/Help/Generators/VideoStepsGenerator.php は tools/manual-video/ 配下の scene spec からナレーション文言を抽出してヘルプの操作手順を生成する (クラス冒頭コメントに「動画の字幕ナレーションとテキスト手順を同一ソースに保つ」と明記)。scene spec が無いと設計意図が成立しない。実装コメントから明白 (領域深掘り ui 2026-08-13、devnotes/20260813-2050-lctl-deep-dive-ui/)"
  },
  {
   "kind": "depends_on",
   "target": "mcp-server-core",
   "note": "MCP ツール一覧の自動生成 (McpToolReferenceGenerator / McpToolMetadata) は mcp-server-core が定義する基底 Tool クラスと登録場所を走査対象にしており、mcp-server-core が無いと生成器は何も走査できない。両 feature は既に互いを note で参照している。実装から明白 (領域深掘り ui 2026-08-13、devnotes/20260813-2050-lctl-deep-dive-ui/)"
  }
 ],
 "related_by": [
  {
   "feature": "markdown-renderer",
   "kind": "distinct_from",
   "note": "help-system は Markdown 文書からヘルプページを自動生成する機構であり、本 feature の HTML 変換サービスとは別の層。aigenba の app/Services/Help/HelpRepository.php は独自に CommonMarkConverter を持ち本 feature を再利用していない (未移行の重複として観測の note に記載済み)。境界は明確 (領域深掘り ui 2026-08-13、devnotes/20260813-2050-lctl-deep-dive-ui/)"
  }
 ],
 "mentions": {
  "outgoing": [],
  "incoming": [
   "markdown-renderer"
  ]
 }
}
```
