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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (解析 paths は app / config / database / routes。tests/ と scripts/ は解析対象外)
- Pestテストフレームワーク (RefreshDatabase グローバル適用 + --parallel 並列実行)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠 / 11. Atomic Design準拠 — 本件は UI/frontend 変更を含まないため該当しない

【本件の特殊事情 — 必ず踏まえること】
本件は「家系の機能台帳 (lctl) の feature `help-system` への aicue 追従設計」である。正典が定めた不変条件 (詳細設計の I1〜I19) を満たす**最小**であることが要求されている。
- **正典が求めていないものを足す提案は採用しない** (過大化の禁止)。
- **正典の必須条件を落とす提案も採用しない** (過小の禁止)。
- 概念設計は Codex gpt-5.6-terra の Round 3 で APPROVED 済みであり、スコープ境界は確定している。

また AGENTS.md の「静的検査 (gate) と走査器の共通規約」が本件に発火する。関連節を末尾に添付する。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: help-system-aicue-propagation

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
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本設計の抵触判定**: 4 (HTTP 面を持たない・DTO を返す) / 5・6 (LLM を一切呼ばない) /
7・8 (HTTP 面・UI を持たない) はいずれも構造的に非該当。
1 は施策 S6 (検査 7 本) が担保する。2 は施策ごとの PHPStan 適合チェック欄で担保する。
3 は DB に一切触れないため非該当。9 は本設計フローの成果物を devnotes 配下のファイルとして出す。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。
  `phpstan.neon` の `paths` は `app / config / database / routes` なので、
  **`app/Services/Help/` と `app/Console/Commands/Help/` は解析対象**である（`tests/` は対象外）。
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
  → 本設計は **Eloquent モデルを 1 つも追加しない**（ヘルプはファイル系）ため Factory 追加は無い
- **DTO + JsonResource** パターン（配列を Service の戻り値にしない）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **走査器・gate の共通規約 4 点**（`AGENTS.md` L273-300）— 本設計は走査ロジックを新設するため発火する

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.6-terra` Round 3 で **APPROVED**）
- 正典: lctl feature `help-system` / feature_revision `15-c3ee5e7c9ef0` /
  ledger_revision `81f0e624363b0c707a424c0695253eb6d1536451` / 裁定 **AG-100 (2026-08-06)**
- 追従先の型: **t 系（テンプレート形）**。正本は `laravel-claude-template` の v1 実装
  （観測点 `@2b75053` / 再確認 `@1b6920b`）。aicue セルは **pending → 目標 v1**

---

## 正典の不変条件（全数列挙）と本設計の対応

裁定 AG-100 と正典 note から抽出できる不変条件を**すべて**挙げ、
各々をどの施策・どの検査が満たすかを対応づける。**これが最小スコープの根拠である。**

| # | 正典の不変条件（出典） | 満たす施策 | 固定する検査 |
|---|---|---|---|
| I1 | ヘルプ本文の**取り込み基盤**を持つ（読み取り層 + 置き場と manifest の規約）<br>出典: AG-100「還流するのは (1) ヘルプ本文の取り込み基盤 (HelpRepository / HelpSection / help:build コマンド / docs/help/ の置き場と manifest の規約)」 | S1・S2 | `HelpRepositoryTest` |
| I2 | **MCP ツール一覧を実装から自動生成**する<br>出典: AG-100「(2) MCP ツール一覧の自動生成 (McpToolReferenceGenerator)」 | S4 | `McpToolReferenceGeneratorTest` |
| I3 | **基底クラス参照は自リポジトリの MCP ツール基底へ差し替える**<br>出典: AG-100「移植時はこの 1 行を各リポジトリの基底クラスへ差し替える」（aicue は `AppMcpTool`） | S4 | `McpToolReferencePopulationTest` |
| I4 | **【必須条件】生成物の鮮度検査**を持つ<br>出典: AG-100「【必須条件】生成物の鮮度検査 (aigenba の help:build --check にあたるもの) を含めて還流すること」 | S5 | `HelpBuildFreshnessTest` |
| I5 | 鮮度検査は **CI で赤くなる**（＝生成物が古いまま気付かれない形を作らない）<br>出典: AG-100「生成だけ還流して検査を落とすと、生成物が古いまま気付かれない — 本セッションで反復して確認した失敗の形」 | S5・S6 | `HelpBuildFreshnessTest`（通常テストレーンから実リポジトリを検査） |
| I6 | **検査モードは作業ツリーを変えない**（比較だけ行う分岐を持つ）<br>出典: 正典 note「HelpBuildCommand の --check オプションが書き込みをせず比較のみ行う分岐を持つこと」 | S5 | `HelpBuildCommandTest` |
| I7 | **生成と鮮度検査の唯一の入口**が 1 つの artisan コマンドである<br>出典: テンプレート報告「検査モードを持つ artisan コマンドを唯一の入口とし」 | S5 | `HelpBuildCommandTest` |
| I8 | **終了コードは 0 と 1 の 2 値だけ**<br>出典: 正典 note「終了コードは0と1の2値だけ」 | S5 | `HelpBuildCommandTest` |
| I9 | 報告は **4 種別**である<br>出典: テンプレート報告「報告が 4 種別」 | S5 | `HelpBuildCommandTest` |
| I10 | 生成器の台帳は **allowlist / skip を持たない定数配列**で、manifest と**完全一致**を強制する（deny-by-default）<br>出典: 正典 note「HelpGeneratorRegistry::GENERATORS が allowlist/skip を持たない定数配列で verifyRegistryIsFullyReferenced() が manifest との完全一致を強制 (deny-by-default)」 | S3 | `HelpGeneratorRegistryTest` |
| I11 | **生成物は生成物用ディレクトリの直下だけ**（階層を許さない）<br>出典: テンプレート報告「生成物は生成物用ディレクトリの直下だけに限った (階層を許すと孤児の検査に再帰走査が要るため)」 | S1・S2 | `HelpRepositoryTest` |
| I12 | 読み取り API は**閉じる側に倒れる**。パスを組み立てるたびに**字句の検査と実体の検査をやり直す**<br>出典: テンプレート報告「読み取り API 自身が閉じる側に倒れており、パスを組み立てるたびに字句の検査と実体の検査をやり直す」 | S2 | `HelpRepositoryTest`（負例 6 種） |
| I13 | **手書きページが 0 件でも検査モードは成功する**（ヘルプ本文の未整備を赤字扱いしない）<br>出典: AG-100「ヘルプ本文の未整備を赤字扱いしないことを明記する」／正典 note「0件でも--checkは通る設計」 | S1・S5 | `HelpBuildCommandTest` |
| I14 | パラメータの形は**閉じた集合で弾かず表示用の文字列へ正規化**する。vendor のメタデータの形が変われば**生成は止まる**（静かに欠けない）<br>出典: テンプレート報告「実装しなかったこと・残差分」3 | S4 | `McpToolReferenceGeneratorTest` |
| I15 | ヘルプ本文の**中身はアプリ固有**であり、還流するのは**機構と置き場の規約だけ**<br>出典: AG-100「ヘルプ本文の中身は各アプリ固有のままとし、還流するのは機構と置き場の規約だけである」 | スコープ外の根拠 | — |
| I16 | **表示面（HTTP でヘルプを配る画面）は含まない**<br>出典: テンプレート報告「表示面 (HTTP でヘルプを配る画面) は入れていない。裁定の還流 2 部分に含まれておらず、裁定が『仕組みと置き場の規約のみ』と限っているため」 | スコープ外の根拠 | — |
| I17 | **`VideoStepsGenerator`（動画手順の生成器）は含まない**<br>出典: テンプレート報告「動画手順の生成器は入れていない。裁定が名指ししておらず、汎用かどうかを台帳から判断できない」 | スコープ外の根拠 | — |
| I18 | 生成物と本文は **per-app のデータ**として扱い、逸脱の検査では共有扱いにしない<br>出典: テンプレート報告「生成物と本文は per-app のデータとして扱い、逸脱の検査では共有扱いにしていない」 | Phase 3-0 の判定根拠 | — |
| I19 | 前提 feature `mcp-server-core` が在ること（基底 Tool クラスと登録場所が走査対象）<br>出典: relations `depends_on: mcp-server-core` | 充足済み（aicue は `AppMcpTool` + `AppMcpServer` を保有） | 既存 `ToolNameInvariantTest` |

### 正典が求めていないもの（起票時 triage の誤読の訂正）

| triage の記述 | 正典の実際 | 本設計の扱い |
|---|---|---|
| ヘルプ記事の**データモデル** | DB モデルではなく **Markdown の置き場 + manifest.json** | Eloquent モデル・migration を作らない |
| ヘルプ記事の**配信 route** | **正典に無い**（I16） | 作らない |
| **MCP ヘルプ tool** | **正典に無い**。正典の MCP 部分は「MCP ツール一覧を**生成する側**」 | 作らない |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 置き場と規約（manifest / 生成物ディレクトリ / 運用契約文書） | `docs/help/manifest.json`(新) / `docs/help/_generated/mcp-tools.md`(新) / `docs/help-system.md`(新) | High |
| S2 | 取り込み基盤（読み取り層と閉じるパス検査） | `app/Services/Help/HelpSection.php`(新) / `HelpRepository.php`(新) / `HelpManifestException.php`(新) | High |
| S3 | 生成器の台帳（deny-by-default の全数申告） | `app/Services/Help/Generators/HelpGenerator.php`(新) / `app/Services/Help/HelpGeneratorRegistry.php`(新) | High |
| S4 | MCP ツールの走査・正規化・生成 | `app/Services/Help/McpToolScanner.php`(新) / `McpToolMetadata.php`(新) / `McpToolParameter.php`(新) / `Generators/McpToolReferenceGenerator.php`(新) | High |
| S5 | 唯一の入口と鮮度検査 | `app/Services/Help/HelpArtifactState.php`(新) / `HelpArtifactObservation.php`(新) / `HelpBuildReport.php`(新) / `HelpBuildService.php`(新) / `app/Console/Commands/Help/HelpBuildCommand.php`(新) / `app/Providers/AppServiceProvider.php`(改) | High |
| S6 | 検査 7 本 | `tests/Architecture/HelpGeneratorRegistryTest.php`(新) / `tests/Architecture/McpToolReferencePopulationTest.php`(新) / `tests/Unit/Architecture/McpToolScannerTest.php`(新) / `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php`(新) / `tests/Feature/Help/HelpRepositoryTest.php`(新) / `tests/Feature/Help/HelpBuildCommandTest.php`(新) / `tests/Feature/Help/HelpBuildFreshnessTest.php`(新) | High |

---

## S1: 置き場と規約

### 変更箇所

- 新規: `docs/help/manifest.json` — 宣言の正本
- 新規: `docs/help/_generated/mcp-tools.md` — 生成物（S4 の出力）
- 新規: `docs/help-system.md` — 置き場と規約の運用契約（正本）
- **`docs/help/pages/` は作らない**（手書きページ 0 件で始める。I13/I15）

### 波及変更

- TypeScript型定義: **なし**（HTTP 面もフロントも持たない）
- API Resource/DTO: **なし**（S2 で DTO を新設するが既存 Resource への波及は無い）
- テストファイル: `tests/Feature/Help/HelpRepositoryTest.php`（新規。S6）

### 現行コード

`docs/help/` は**存在しない**（aicue 実読で 0 件を確認）。

### 変更後（`docs/help/manifest.json`）

```json
{
    "schema_version": 1,
    "sections": [
        {
            "slug": "mcp-tools",
            "title": "MCP ツールリファレンス",
            "path": "_generated/mcp-tools.md",
            "generator": "mcp-tools"
        }
    ]
}
```

### 置き場の規約（`docs/help-system.md` に書く内容の骨子）

- `docs/help/manifest.json` が**宣言の正本**。ここに無い節は存在しない。
- `path` の値域は `_generated/<name>.md` または `pages/<name>.md` の **2 通りだけ**。
  `<name>` は `[A-Za-z0-9][A-Za-z0-9._-]*`。**どちらも直下のみで階層を許さない**（I11）。
- `generator` キーを**持つ節が生成物**、持たない節が**手書きページ**。
  `generator` の値は `HelpGeneratorRegistry::GENERATORS` のキーと**完全一致**する（I10）。
- 生成物は `php artisan help:build` が書き、**手で編集しない**（先頭にその旨のコメントを持つ）。
- **手書きページは 0 件でよい**。0 件でも `help:build --check` は成功する（I13）。
- `docs/help/_generated/` 直下に manifest 未宣言の `.md` があれば **Orphan**（I9）。
  ディレクトリや `.md` 以外の実体があれば**例外で止まる**（I11 を字句でなく実体で守る）。
- **保証しないもの**: 本機構は表示面を持たない（I16）。Markdown を HTML へ変換しない。
  ヘルプ本文の中身の品質・網羅性は検査しない（I15）。

### リスク

- `docs/help/_generated/mcp-tools.md` は生成物なので、実装変更のたびに差分が出る。
  → これは**不具合ではなく本機構の目的**である（ツール追加が文書へ自動反映される）。
- ディレクトリを新設するので `.gitignore` に掛かっていないことを確認する
  （`.gitignore` に `docs` を含む行は無いことを実読で確認済み）。

---

## S2: 取り込み基盤（`HelpSection` / `HelpRepository` / `HelpManifestException`）

### 変更箇所

- 新規: `app/Services/Help/HelpSection.php`
- 新規: `app/Services/Help/HelpRepository.php`
- 新規: `app/Services/Help/HelpManifestException.php`

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: `HelpSection` を新設（配列を返さない）
- テストファイル: `tests/Feature/Help/HelpRepositoryTest.php`（新規）

### 現行コード

なし（新規）。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

use RuntimeException;

/** manifest / 置き場の規約に反する形を表す。**沈黙して空を返さないための型**である。 */
final class HelpManifestException extends RuntimeException {}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

/**
 * ヘルプの 1 節（manifest の 1 エントリ）。
 *
 * ★`generatorKey` が null なら手書きページ、非 null なら生成物である。
 */
final readonly class HelpSection
{
    /**
     * @param  non-empty-string  $slug
     * @param  non-empty-string  $title
     * @param  non-empty-string  $path  `docs/help/` からの相対パス
     * @param  non-empty-string|null  $generatorKey
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $path,
        public ?string $generatorKey,
    ) {}

    public function isGenerated(): bool
    {
        return $this->generatorKey !== null;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

use JsonException;

/**
 * ヘルプの置き場 (`docs/help/`) の読み取り層。
 *
 * ★**閉じる側に倒れる**。パスを組み立てるたびに字句の検査 (`assertRelativePath`) と
 *   実体の検査 (`resolveExisting`) を**やり直す**。片方だけを通した結果を使い回さない。
 * ★**走査対象**: `manifest.json` が宣言した節と、`_generated/` 直下の `*.md` だけ。
 * ★**保証しないもの**:
 *   - 本文の内容（Markdown の妥当性・網羅性）は一切見ない。
 *   - `pages/` 配下の未宣言ファイルは孤児として扱わない
 *     （手書きの下書きを赤にしないため。孤児検査の対象は生成物ディレクトリだけである）。
 *   - 生成物ディレクトリの**階層は許さない**。下位ディレクトリを見つけたら例外で止まる
 *     （再帰走査を持たないので「見えない場所に孤児が居る」を作らない）。
 */
final class HelpRepository
{
    /** 生成物のディレクトリ名（直下のみ）。 */
    public const string GENERATED_DIR = '_generated';

    /** 手書きページのディレクトリ名（直下のみ）。0 件でよい。 */
    public const string PAGES_DIR = 'pages';

    private const string MANIFEST_FILE = 'manifest.json';

    /** @param non-empty-string $root `docs/help/` の絶対パス */
    public function __construct(private readonly string $root) {}

    /**
     * manifest が宣言した節（宣言順）。
     *
     * @return list<HelpSection>
     *
     * @throws HelpManifestException
     */
    public function sections(): array
    {
        $manifest = $this->readManifest();

        $sections = [];
        $seenSlugs = [];
        $seenPaths = [];

        foreach ($manifest as $index => $entry) {
            if (! is_array($entry)) {
                throw new HelpManifestException("manifest の sections[{$index}] が object ではありません。");
            }

            $slug = $this->requireNonEmptyString($entry, 'slug', $index);
            $title = $this->requireNonEmptyString($entry, 'title', $index);
            $path = $this->requireNonEmptyString($entry, 'path', $index);

            $generatorKey = null;
            if (array_key_exists('generator', $entry)) {
                $generatorKey = $this->requireNonEmptyString($entry, 'generator', $index);
            }

            $this->assertRelativePath($path, $generatorKey !== null, $index);

            if (isset($seenSlugs[$slug])) {
                throw new HelpManifestException("manifest の slug が重複しています: {$slug}");
            }
            if (isset($seenPaths[$path])) {
                throw new HelpManifestException("manifest の path が重複しています: {$path}");
            }
            $seenSlugs[$slug] = true;
            $seenPaths[$path] = true;

            $sections[] = new HelpSection($slug, $title, $path, $generatorKey);
        }

        return $sections;
    }

    /**
     * 節の本文。存在しなければ null（**不在は例外にしない** — Missing として報告するため）。
     *
     * @throws HelpManifestException 実体が置き場の外・regular file でない・symlink のとき
     */
    public function read(HelpSection $section): ?string
    {
        $this->assertRelativePath($section->path, $section->isGenerated(), null);

        $absolute = $this->root.'/'.$section->path;

        if (! file_exists($absolute) && ! is_link($absolute)) {
            return null;
        }

        return $this->readResolved($absolute, $section->path);
    }

    /**
     * 生成物ディレクトリ直下の `*.md` の相対パス（昇順）。孤児検査の母集団である。
     *
     * @return list<non-empty-string>
     *
     * @throws HelpManifestException
     */
    public function generatedArtifactPaths(): array
    {
        $dir = $this->root.'/'.self::GENERATED_DIR;

        if (! is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir);
        if ($entries === false) {
            throw new HelpManifestException("生成物ディレクトリを走査できません: {$dir}");
        }

        $paths = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $dir.'/'.$entry;

            if (is_dir($absolute)) {
                throw new HelpManifestException(
                    "生成物ディレクトリは階層を許しません: {$absolute} — ".
                    'ディレクトリを削除し、生成物は '.self::GENERATED_DIR.'/ 直下に置くこと。',
                );
            }
            if (! str_ends_with($entry, '.md')) {
                throw new HelpManifestException(
                    "生成物ディレクトリに Markdown 以外の実体があります: {$absolute} — 削除すること。",
                );
            }

            $relative = self::GENERATED_DIR.'/'.$entry;
            $this->assertRelativePath($relative, true, null);
            $paths[] = $relative;
        }

        sort($paths, SORT_STRING);

        /** @var list<non-empty-string> $paths */
        return $paths;
    }

    public function absolutePathFor(HelpSection $section): string
    {
        $this->assertRelativePath($section->path, $section->isGenerated(), null);

        return $this->root.'/'.$section->path;
    }

    /**
     * 字句の検査。ディレクトリは 2 つだけ・直下のみ・`.md` のみ・`.`/`..` を含まない。
     *
     * @throws HelpManifestException
     */
    private function assertRelativePath(string $path, bool $generated, ?int $index): void
    {
        $where = $index === null ? '' : " (sections[{$index}])";

        $expectedDir = $generated ? self::GENERATED_DIR : self::PAGES_DIR;
        $pattern = '#^'.preg_quote($expectedDir, '#').'/[A-Za-z0-9][A-Za-z0-9._-]*\.md$#';

        if (preg_match($pattern, $path) !== 1) {
            throw new HelpManifestException(
                "path が規約に合いません{$where}: {$path} — ".
                "期待する形は `{$expectedDir}/<name>.md` (直下のみ・階層不可) である。",
            );
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new HelpManifestException("path に相対指定を含められません{$where}: {$path}");
            }
        }
    }

    /**
     * 実体の検査。symlink 不可・regular file のみ・realpath が置き場の内側にあること。
     *
     * @throws HelpManifestException
     */
    private function readResolved(string $absolute, string $relative): string
    {
        if (is_link($absolute)) {
            throw new HelpManifestException("ヘルプの実体に symlink は使えません: {$relative}");
        }

        $real = realpath($absolute);
        $rootReal = realpath($this->root);

        if ($real === false || $rootReal === false) {
            throw new HelpManifestException("ヘルプの実体を解決できません: {$relative}");
        }
        if (! is_file($real)) {
            throw new HelpManifestException("ヘルプの実体が通常ファイルではありません: {$relative}");
        }
        if (! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR)) {
            throw new HelpManifestException("ヘルプの実体が置き場の外を指しています: {$relative}");
        }

        $contents = file_get_contents($real);
        if ($contents === false) {
            throw new HelpManifestException("ヘルプの実体を読めません: {$relative}");
        }

        return $contents;
    }

    /**
     * @return list<mixed>
     *
     * @throws HelpManifestException
     */
    private function readManifest(): array
    {
        $absolute = $this->root.'/'.self::MANIFEST_FILE;

        if (is_link($absolute) || ! is_file($absolute)) {
            throw new HelpManifestException("manifest が通常ファイルとして存在しません: {$absolute}");
        }

        $raw = file_get_contents($absolute);
        if ($raw === false) {
            throw new HelpManifestException("manifest を読めません: {$absolute}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HelpManifestException("manifest の JSON が壊れています: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($decoded) || ! array_key_exists('sections', $decoded)) {
            throw new HelpManifestException('manifest に sections がありません。');
        }

        $sections = $decoded['sections'];
        if (! is_array($sections) || ! array_is_list($sections)) {
            throw new HelpManifestException('manifest の sections が配列 (list) ではありません。');
        }

        return $sections;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return non-empty-string
     *
     * @throws HelpManifestException
     */
    private function requireNonEmptyString(array $entry, string $key, int $index): string
    {
        $value = $entry[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new HelpManifestException("manifest の sections[{$index}].{$key} が非空の文字列ではありません。");
        }

        return $value;
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`list<HelpSection>` / `?string` / `list<non-empty-string>`）
- [x] null 安全（`realpath` / `file_get_contents` / `scandir` の `false` を全て分岐）
- [x] DTO を返している（`HelpSection`。配列返却は「パスの一覧」だけで `list<non-empty-string>` に絞っている）
- [x] Generics の型パラメータが正しい（`array<array-key, mixed>` / `list<mixed>`）
- [x] `mixed` を上流へ漏らさない（`json_decode` の結果は `requireNonEmptyString` で型を確定してから DTO へ入れる）

### テスト計画

- [x] テストファースト: **先に赤くしてから**本体を書く（走査器・gate 規約 1）
- [x] 新規テスト: `tests/Feature/Help/HelpRepositoryTest.php`
  - 正例: 生成物 1 件 + 手書き 0 件の manifest を読める / 手書き 1 件も読める
  - **負例 6 種（I12）**: `..` を含む path / 絶対パス / `pages` `_generated` 以外のディレクトリ /
    `_generated/sub/x.md`（階層化） / 実体が symlink / 実体が置き場の外を指す
  - 追加負例: manifest 不在 / JSON 破損 / `sections` が list でない / slug 重複 / path 重複 /
    `generator` が空文字 / 生成物ディレクトリに `.md` 以外・ディレクトリが在る
  - 不在は例外にせず `null` を返すこと（Missing 判定と例外を混同しない）
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（DB を使わない）

### リスク

- symlink 判定は `is_link()` に依存する。Windows は対象外（開発は devcontainer / Linux）。
- `realpath` は不在時に `false` を返すため、**不在（Missing）と検査不能を混同しない**よう
  `file_exists`/`is_link` の分岐を先に置いている。

---

## S3: 生成器の台帳（`HelpGenerator` / `HelpGeneratorRegistry`）

### 変更箇所

- 新規: `app/Services/Help/Generators/HelpGenerator.php`
- 新規: `app/Services/Help/HelpGeneratorRegistry.php`

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/HelpGeneratorRegistryTest.php`（新規）

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Help\Generators;

/** ヘルプの節を実装から組み立てる生成器。 */
interface HelpGenerator
{
    /** manifest の `generator` と突き合わせるキー。 */
    public function key(): string;

    /** 生成した Markdown 本文（末尾は改行 1 個で終わること）。 */
    public function generate(): string;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Services\Help\Generators\HelpGenerator;
use App\Services\Help\Generators\McpToolReferenceGenerator;
use Illuminate\Contracts\Container\Container;
use Webmozart\Assert\Assert;

/**
 * 生成器の**全数申告**。
 *
 * ★**許可一覧も除外の口も持たない**。この定数配列に載っているものが生成器のすべてであり、
 *   「台帳に載っていない生成器」は検査の対象から外れる = 存在できない (deny-by-default)。
 *   個別の生成器・個別の節を名指しして検査を免除する仕組みは本機構のどこにも無い。
 * ★**走査対象**: 本定数と `HelpRepository::sections()` が返す `generator` キーの 2 集合だけ。
 * ★**保証しないもの**: 生成器が出す本文の正しさは見ない (それは各生成器の単体検査の担当)。
 */
final class HelpGeneratorRegistry
{
    /**
     * 生成器のキー → 実装クラス。
     *
     * @var array<non-empty-string, class-string<HelpGenerator>>
     */
    public const array GENERATORS = [
        'mcp-tools' => McpToolReferenceGenerator::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<non-empty-string, HelpGenerator>
     */
    public function all(): array
    {
        $resolved = [];

        foreach (self::GENERATORS as $key => $class) {
            $generator = $this->container->make($class);
            Assert::isInstanceOf($generator, HelpGenerator::class);
            Assert::same($generator->key(), $key, "生成器の key() が台帳のキーと一致しません: {$key}");

            $resolved[$key] = $generator;
        }

        return $resolved;
    }

    /**
     * 台帳と manifest の生成 entry が**完全一致**することを強制する（両方向）。
     *
     * @throws HelpManifestException
     */
    public function verifyRegistryIsFullyReferenced(HelpRepository $repository): void
    {
        $declared = [];
        foreach ($repository->sections() as $section) {
            if ($section->generatorKey !== null) {
                $declared[$section->generatorKey] = true;
            }
        }

        $registered = array_map(static fn (): bool => true, self::GENERATORS);

        $missingInManifest = array_keys(array_diff_key($registered, $declared));
        $missingInRegistry = array_keys(array_diff_key($declared, $registered));

        if ($missingInManifest !== []) {
            throw new HelpManifestException(
                '台帳に在る生成器が manifest に宣言されていません: '.implode(', ', $missingInManifest).
                ' — docs/help/manifest.json へ節を足すこと。',
            );
        }
        if ($missingInRegistry !== []) {
            throw new HelpManifestException(
                'manifest が宣言した生成器が台帳に在りません: '.implode(', ', $missingInRegistry).
                ' — HelpGeneratorRegistry::GENERATORS へ足すこと。',
            );
        }
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（`Assert::isInstanceOf` で container の `mixed` を絞る）
- [x] DTO を返している（`array<non-empty-string, HelpGenerator>` はオブジェクトの写像であり素の配列返却ではない）
- [x] Generics の型パラメータが正しい（`class-string<HelpGenerator>`）
- [x] PHP 8.4 の型付きクラス定数 `public const array` を使う（既存 `LedgerPins::const int` に前例あり）

### テスト計画

- [x] テストファースト
- [x] 新規テスト: `tests/Architecture/HelpGeneratorRegistryTest.php`
  - **正例**: 実リポジトリの manifest に対し `verifyRegistryIsFullyReferenced()` が例外を投げない
  - **負例**: 台帳に在って manifest に無い / manifest に在って台帳に無い を合成入力で両方向に赤くする
  - **免除の口が無いこと**: `HelpGeneratorRegistry` の public 定数が `GENERATORS` **ちょうど 1 つ**であり、
    static プロパティを 1 つも持たないことを reflection で pin する（allowlist/skip の受け皿が生えない）
  - **母集団が非空**: `GENERATORS` が 1 件以上であること（0 件で「一致」になるのを防ぐ）
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 生成器を増やすときに manifest の追記を忘れると `help:build` 自体が止まる。
  → これは意図した fail-closed である（deny-by-default）。

---

## S4: MCP ツールの走査・正規化・生成

### 変更箇所

- 新規: `app/Services/Help/McpToolScanner.php`（走査器）
- 新規: `app/Services/Help/McpToolMetadata.php` / `McpToolParameter.php`（DTO）
- 新規: `app/Services/Help/Generators/McpToolReferenceGenerator.php`

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: `McpToolMetadata` / `McpToolParameter` を新設
- テストファイル: `tests/Unit/Architecture/McpToolScannerTest.php`（新規） /
  `tests/Architecture/McpToolReferencePopulationTest.php`（新規） /
  `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php`（新規）
- **既存 MCP コードは 1 行も変更しない**（`AppMcpTool` / `AppMcpServer` / `ToolName` / 既存 MCP テスト）

### 現行コード（走査対象。参考）

```php
// app/Mcp/Tools/AppMcpTool.php (抜粋) — 走査対象の基底 (正典 I3 の「差し替える 1 行」)
abstract class AppMcpTool extends Tool { /* ... */ }

// app/Mcp/Tools/ListItemsTool.php (抜粋)
final class ListItemsTool extends AppMcpTool
{
    protected string $description = 'List items of a project in the organization bound to the access token.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project ID')->required(),
            'page' => $schema->integer()->description('Page number (1..1000)'),
            'per_page' => $schema->integer()->description('Items per page (1..100)'),
        ];
    }
}
```

vendor 側の直列化（`Illuminate\JsonSchema\Serializer` 実読）が返す形:

```
{"type":"object","properties":{"project_id":{"type":"integer","description":"Project ID"}, ...},"required":["project_id"]}
```

- `properties` が空のときは **`properties` キーごと消える**（`WhoamiTool` は `{"type":"object"}`）
- `required` は**必須が 1 つも無いとキーごと消える**
- `type` は**文字列または文字列の配列**（nullable / union のとき配列）

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Mcp\Tools\AppMcpTool;
use ReflectionClass;
use RuntimeException;

/**
 * `app/Mcp/Tools/` を走査して MCP ツールの具象クラスを列挙する。
 *
 * ★**走査根は 1 本**（`app/Mcp/Tools/` の直下）。git 追跡下 PHP 全数より狭いので
 *   `Tests\Support\TrackedPhpSourceFiles` は共用しない。**存在しない根は fail-fast** で落とす。
 * ★**基底クラスは `App\Mcp\Tools\AppMcpTool`** — 正典 (裁定 AG-100) が
 *   「移植時に各リポジトリの基底クラスへ差し替える 1 行」と名指しした箇所である。
 * ★**deny-by-default**: 走査根の直下に置いた具象クラスは、基底を継承していなければ**例外で止まる**。
 *   「走査対象から外す」口を持たない（外したければファイルを別の場所へ移すしかない）。
 * ★**母集団の非空は本走査器の契約である**。0 件は「違反 0 件」ではなく走査の破損なので例外にする。
 * ★**保証しないもの**:
 *   - 下位ディレクトリは見ない（`app/Mcp/Tools/` は現に平坦であり、階層を作る予定も無い）。
 *   - サーバへの登録有無は見ない。走査集合と登録集合の一致は
 *     `tests/Architecture/McpToolReferencePopulationTest.php` の担当である。
 *   - git 未追跡（add 前）のファイルも走査する（gate の境界は commit / CI なので実効差は無い）。
 */
final class McpToolScanner
{
    private const string NAMESPACE_PREFIX = 'App\\Mcp\\Tools\\';

    /** @param non-empty-string $root `app/Mcp/Tools/` の絶対パス */
    public function __construct(private readonly string $root) {}

    /**
     * @return list<class-string<AppMcpTool>> クラス名の昇順
     *
     * @throws RuntimeException 走査根が無い / クラスを解決できない / 基底を継承しない / 母集団が空
     */
    public function concreteToolClasses(): array
    {
        if (! is_dir($this->root)) {
            throw new RuntimeException(
                "MCP ツールの走査根が存在しません: {$this->root} — ".
                'ディレクトリを移動・改名したなら McpToolScanner の配線を同じ変更で直すこと。',
            );
        }

        $entries = scandir($this->root);
        if ($entries === false) {
            throw new RuntimeException("MCP ツールの走査根を走査できません: {$this->root}");
        }

        $classes = [];

        foreach ($entries as $entry) {
            if (! str_ends_with($entry, '.php')) {
                continue;
            }

            $absolute = $this->root.'/'.$entry;
            if (! is_file($absolute) || is_link($absolute)) {
                throw new RuntimeException("MCP ツールの実体が通常ファイルではありません: {$absolute}");
            }

            $class = self::NAMESPACE_PREFIX.substr($entry, 0, -4);

            if (! class_exists($class)) {
                throw new RuntimeException(
                    "MCP ツールのクラスを解決できません: {$class} ({$absolute}) — ".
                    'ファイル名とクラス名 / namespace が一致しているか確認すること。',
                );
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->isSubclassOf(AppMcpTool::class)) {
                throw new RuntimeException(
                    "{$class} は ".AppMcpTool::class.' を継承していません — '.
                    'app/Mcp/Tools/ 直下には MCP ツールだけを置くこと '.
                    '(補助クラスは別の namespace へ移すこと)。',
                );
            }

            /** @var class-string<AppMcpTool> $class */
            $classes[] = $class;
        }

        if ($classes === []) {
            throw new RuntimeException(
                "MCP ツールが 1 件も見つかりません: {$this->root} — ".
                '母集団が空なのは「違反 0 件」ではなく走査の破損である。',
            );
        }

        sort($classes, SORT_STRING);

        return $classes;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

/**
 * ツール 1 本のパラメータ。**表示用に正規化済み**の値だけを持つ。
 *
 * ★`type` は vendor が返した型（文字列 or 文字列の配列）を `|` で連結した表示用文字列である。
 *   閉じた集合で弾かない（正典が名指しした設計判断）。
 */
final readonly class McpToolParameter
{
    /**
     * @param  non-empty-string  $name
     * @param  non-empty-string  $type
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required,
        public string $description,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Mcp\Tools\AppMcpTool;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use RuntimeException;

/**
 * MCP ツール 1 本のメタデータ。**vendor の実行時出力を first-party の型へ閉じ込める境界**である。
 *
 * ★**正規化する。閉じた集合で弾かない**（正典の設計判断）— vendor の実行時出力は
 *   first-party の型保証の外にあり、閉集合で弾くと正当なツール定義が生成を止めるためである。
 * ★**静かに欠けるより止まる**。想定外の形は握り潰さず例外にし、
 *   例外には**対象クラス / 不正だった箇所 / 直し方**を必ず含める。
 * ★**保証しないもの**: 説明文・パラメータ説明の内容の妥当性は見ない（存在と型だけを見る）。
 */
final readonly class McpToolMetadata
{
    /**
     * @param  class-string<AppMcpTool>  $className
     * @param  non-empty-string  $name
     * @param  list<McpToolParameter>  $parameters  schema の宣言順
     */
    public function __construct(
        public string $className,
        public string $name,
        public string $description,
        public array $parameters,
    ) {}

    /**
     * @param  class-string<AppMcpTool>  $className
     *
     * @throws RuntimeException vendor のメタデータが想定外の形のとき
     */
    public static function fromTool(AppMcpTool $tool, string $className): self
    {
        $name = $tool->name();
        if ($name === '') {
            throw new RuntimeException("{$className}: name() が空文字です — ToolName enum の値を返すこと。");
        }

        /** @var array<string, mixed> $schema */
        $schema = JsonSchemaFactory::object($tool->schema(...))->toArray();

        return new self(
            className: $className,
            name: $name,
            description: $tool->description(),
            parameters: self::parametersFrom($schema, $className),
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<McpToolParameter>
     *
     * @throws RuntimeException
     */
    private static function parametersFrom(array $schema, string $className): array
    {
        // properties は「必須が 0 件」ではなくキーごと消える形で来る (vendor 実読)
        $properties = $schema['properties'] ?? [];
        if (! is_array($properties)) {
            throw new RuntimeException(
                "{$className}: schema の properties が配列ではありません — ".
                'vendor (laravel/mcp / illuminate/json-schema) の出力形が変わった可能性がある。'.
                'McpToolMetadata::parametersFrom を新しい形に合わせて直すこと。',
            );
        }

        $required = [];
        $rawRequired = $schema['required'] ?? [];
        if (! is_array($rawRequired)) {
            throw new RuntimeException("{$className}: schema の required が配列ではありません。");
        }
        foreach ($rawRequired as $key) {
            if (! is_string($key)) {
                throw new RuntimeException("{$className}: schema の required に文字列でない要素があります。");
            }
            $required[$key] = true;
        }

        $parameters = [];
        foreach ($properties as $name => $definition) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException("{$className}: schema のパラメータ名が非空の文字列ではありません。");
            }
            if (! is_array($definition)) {
                throw new RuntimeException("{$className}: schema のパラメータ `{$name}` の定義が配列ではありません。");
            }

            $parameters[] = new McpToolParameter(
                name: $name,
                type: self::normalizeType($definition['type'] ?? null, $name, $className),
                required: isset($required[$name]),
                description: self::normalizeDescription($definition['description'] ?? null, $name, $className),
            );
        }

        return $parameters;
    }

    /**
     * 型を**表示用の文字列へ正規化する**（閉じた集合で弾かない）。
     *
     * @return non-empty-string
     *
     * @throws RuntimeException 文字列でも文字列の配列でもないとき
     */
    private static function normalizeType(mixed $type, string $name, string $className): string
    {
        if (is_string($type) && $type !== '') {
            return $type;
        }

        if (is_array($type)) {
            $parts = [];
            foreach ($type as $part) {
                if (! is_string($part) || $part === '') {
                    throw new RuntimeException(
                        "{$className}: パラメータ `{$name}` の type に文字列でない要素があります — ".
                        'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
                    );
                }
                $parts[] = $part;
            }
            if ($parts !== []) {
                return implode('|', $parts);
            }
        }

        if ($type === null) {
            return '(未宣言)';
        }

        throw new RuntimeException(
            "{$className}: パラメータ `{$name}` の type が文字列でも文字列の配列でもありません — ".
            'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
        );
    }

    /** @throws RuntimeException */
    private static function normalizeDescription(mixed $description, string $name, string $className): string
    {
        if ($description === null) {
            return '';
        }
        if (is_string($description)) {
            return $description;
        }

        throw new RuntimeException(
            "{$className}: パラメータ `{$name}` の description が文字列ではありません — ".
            'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeDescription を直すこと。',
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help\Generators;

use App\Mcp\Tools\AppMcpTool;
use App\Services\Help\McpToolMetadata;
use App\Services\Help\McpToolParameter;
use App\Services\Help\McpToolScanner;
use Illuminate\Contracts\Container\Container;
use Webmozart\Assert\Assert;

/**
 * MCP ツール一覧の Markdown を実装から生成する（正典 AG-100 の還流対象 (2)）。
 *
 * ★出力は**決定的**である（ツールは name 昇順、パラメータは schema の宣言順、
 *   日時・環境変数・件数以外の可変要素を一切含めない）。同じ実装からは同じバイト列が出る。
 * ★**保証しないもの**: 説明文の質は見ない。サーバに登録されているかも見ない
 *   （走査集合と登録集合の一致は `McpToolReferencePopulationTest` の担当）。
 */
final class McpToolReferenceGenerator implements HelpGenerator
{
    public function __construct(
        private readonly McpToolScanner $scanner,
        private readonly Container $container,
    ) {}

    public function key(): string
    {
        return 'mcp-tools';
    }

    public function generate(): string
    {
        $metadata = [];

        foreach ($this->scanner->concreteToolClasses() as $class) {
            $tool = $this->container->make($class);
            Assert::isInstanceOf($tool, AppMcpTool::class);

            $metadata[] = McpToolMetadata::fromTool($tool, $class);
        }

        usort($metadata, static fn (McpToolMetadata $a, McpToolMetadata $b): int => strcmp($a->name, $b->name));

        $lines = [
            '<!-- 自動生成: `php artisan help:build` が生成する。手で編集しない。 -->',
            '<!-- 生成器: mcp-tools ('.self::class.') -->',
            '',
            '# MCP ツールリファレンス',
            '',
            '本アプリが MCP サーバー (`App\Mcp\Servers\AppMcpServer`) 経由で公開しているツールの一覧である。',
            '実装 (`app/Mcp/Tools/`) から自動生成しているので、手書きの説明が実装からずれることはない。',
            '',
            '現在のツール数: '.count($metadata),
        ];

        foreach ($metadata as $tool) {
            $lines[] = '';
            $lines[] = '## `'.$tool->name.'`';
            $lines[] = '';
            $lines[] = self::escapeCell($tool->description);

            if ($tool->parameters === []) {
                $lines[] = '';
                $lines[] = 'パラメータなし。';

                continue;
            }

            $lines[] = '';
            $lines[] = '| パラメータ | 型 | 必須 | 説明 |';
            $lines[] = '|---|---|---|---|';
            foreach ($tool->parameters as $parameter) {
                $lines[] = self::parameterRow($parameter);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private static function parameterRow(McpToolParameter $parameter): string
    {
        return sprintf(
            '| `%s` | %s | %s | %s |',
            $parameter->name,
            self::escapeCell($parameter->type),
            $parameter->required ? '必須' : '任意',
            self::escapeCell($parameter->description),
        );
    }

    /** 表のセルを壊す縦棒と改行を無害化する（`docs/template-divergence.md` と同じ方針）。 */
    private static function escapeCell(string $value): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], $value);
    }
}
```

### 生成される `docs/help/_generated/mcp-tools.md`（現行 4 tool での期待値）

```markdown
<!-- 自動生成: `php artisan help:build` が生成する。手で編集しない。 -->
<!-- 生成器: mcp-tools (App\Services\Help\Generators\McpToolReferenceGenerator) -->

# MCP ツールリファレンス

本アプリが MCP サーバー (`App\Mcp\Servers\AppMcpServer`) 経由で公開しているツールの一覧である。
実装 (`app/Mcp/Tools/`) から自動生成しているので、手書きの説明が実装からずれることはない。

現在のツール数: 4

## `list-items`

List items of a project in the organization bound to the access token.

| パラメータ | 型 | 必須 | 説明 |
|---|---|---|---|
| `project_id` | integer | 必須 | Project ID |
| `page` | integer | 任意 | Page number (1..1000) |
| `per_page` | integer | 任意 | Items per page (1..100) |

...

## `whoami`

Return the authenticated user and the organization bound to the access token.

パラメータなし。
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`list<class-string<AppMcpTool>>` / `list<McpToolParameter>`）
- [x] null 安全（`scandir`/`class_exists` の失敗を全て分岐。`Assert::isInstanceOf` で container の `mixed` を絞る）
- [x] DTO を返している（`McpToolMetadata` / `McpToolParameter`）
- [x] Generics の型パラメータが正しい（`class-string<AppMcpTool>` の絞り込みは `@var` で明示）
- [x] `mixed` を上流へ漏らさない（`normalizeType` / `normalizeDescription` が `mixed` を受け、`non-empty-string`/`string` を返す）

### テスト計画（走査器・gate 規約 4 点を満たす）

- [x] **1. 負例と正例**（テストファースト。先に赤くする）
  - `tests/Unit/Architecture/McpToolScannerTest.php`（走査器の自己検査。合成の一時ディレクトリを根にする）
    - 正例: 基底を継承した具象 1 件以上を列挙できる
    - 負例: **走査根が存在しない** / **走査根が空**（母集団 0 件） /
      **クラスを解決できない**（ファイル名とクラス名の不一致） /
      **基底を継承していない具象** / **実体が symlink**
  - `tests/Architecture/McpToolReferencePopulationTest.php`
    - 負例: 走査集合・登録集合・enum 集合のいずれかがずれたら赤（合成入力で両方向）
- [x] **2. 解決できない形を落とす分岐（fail-closed）**: 上記負例が「例外で止まる」ことを固定する
      （空を返して緑になる形を作らない）
- [x] **3. 走査が空振りしていないことの検査**:
  - 走査根 `app/Mcp/Tools/` が実在すること
  - 母集団が**非空**であること + **床値 4 件以上** + **代表パス**
    （`WhoamiTool` / `ListProjectsTool` / `ShowProjectTool` / `ListItemsTool` の実在）を pin
  - **「違反 0 件」と「母集団 0 件」を区別する**（3 集合がすべて空でも一致になる形を禁じる）
- [x] **4. docblock に走査対象と保証しないものを書く**: 上のコードに記載済み。
      **正本は docblock 側**であり本書・`AGENTS.md` へは写さない
- [x] `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php`
  - 決定性: 2 回生成して byte 一致
  - 正規化: `type` が配列（union/nullable）でも `|` 連結の表示文字列になる / `type` 未宣言は `(未宣言)`
  - **I14 の負例**: `type` が数値・object のとき **例外で止まり**、メッセージに
    **クラス名・パラメータ名・直し方**が含まれる（静かに欠けない）
  - 表のセル: 説明に `|` や改行が入っても表が壊れない
  - パラメータ 0 件のツールは「パラメータなし。」になる
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### `McpToolReferencePopulationTest` の判定（3 集合の完全一致）

```php
test('走査集合・サーバ登録集合・ToolName enum が完全一致すること', function (): void {
    $scanned = array_map(
        static fn (string $class): string => app($class)->name(),
        app(McpToolScanner::class)->concreteToolClasses(),
    );
    sort($scanned);

    $registered = /* AppMcpServer::$tools を reflection で読み name() を取る */;
    sort($registered);

    $enum = array_map(static fn (ToolName $t): string => $t->value, ToolName::cases());
    sort($enum);

    expect($scanned)->not->toBeEmpty()             // 母集団 0 件を「一致」にしない
        ->and(count($scanned))->toBeGreaterThanOrEqual(4)
        ->and($scanned)->toBe($registered)
        ->and($registered)->toBe($enum);
});
```

**既存 `tests/Feature/Mcp/ToolNameInvariantTest.php` は無改変で残す**（禁止事項「既存テストの削除・上書き」）。
本テストは「ディレクトリ実在」という**誰も見ていなかった第 3 の辺**を足すものである。

### リスク

- `app/Mcp/Tools/` に MCP ツール以外の補助クラスを置くと走査が例外で止まる。
  → docblock と例外メッセージで「補助クラスは別 namespace へ」と誘導する。意図した deny-by-default。
- vendor（`laravel/mcp` / `illuminate/json-schema`）の出力形が変わると生成が止まる。
  → **静かに欠けるより止まるほうがよい**（正典 I14）。例外に直し方を書く。

---

## S5: 唯一の入口と鮮度検査

### 変更箇所

- 新規: `app/Services/Help/HelpArtifactState.php` / `HelpArtifactObservation.php` / `HelpBuildReport.php` / `HelpBuildService.php`
- 新規: `app/Console/Commands/Help/HelpBuildCommand.php`
- 変更: `app/Providers/AppServiceProvider.php`（`register()` に 2 本の binding を追加）

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: `HelpBuildReport` / `HelpArtifactObservation` を新設
- テストファイル: `tests/Feature/Help/HelpBuildCommandTest.php` / `tests/Feature/Help/HelpBuildFreshnessTest.php`（新規）
- **コマンドの自動登録**: Laravel 12 は `app/Console/Commands/` 配下を再帰的に自動登録する
  （既存の `app/Console/Commands/Billing/` 等が同じ形で動いている）ので、`bootstrap/app.php` の変更は無い

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

/**
 * 生成物 1 件の状態。**この 4 値がすべて**である（正典 I9）。
 *
 * ★`Orphan` は「manifest に無いのに生成物ディレクトリに居る」であり、
 *   「違反 0 件」に畳まずに独立の種別として残す（消滅と検査不能を混同しない）。
 */
enum HelpArtifactState: string
{
    case UpToDate = 'up_to_date';
    case Stale = 'stale';
    case Missing = 'missing';
    case Orphan = 'orphan';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

final readonly class HelpArtifactObservation
{
    /** @param non-empty-string $relativePath */
    public function __construct(
        public string $relativePath,
        public HelpArtifactState $state,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

final readonly class HelpBuildReport
{
    /** @param list<HelpArtifactObservation> $observations */
    public function __construct(public array $observations) {}

    public function isClean(): bool
    {
        return $this->problems() === [];
    }

    /** @return list<HelpArtifactObservation> */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->observations,
            static fn (HelpArtifactObservation $o): bool => $o->state !== HelpArtifactState::UpToDate,
        ));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

/**
 * 生成と鮮度検査の判定を閉じ込める層（コマンドは薄い引数解析層にする）。
 *
 * ★**`check()` は作業ツリーを 1 バイトも変えない**（正典 I6）。書き込みは `build()` にしかない。
 * ★**手書きページは判定の母集団に入れない**（正典 I13。0 件でも緑）。
 *   見るのは「manifest が宣言した生成 entry」と「生成物ディレクトリ直下の実体」の 2 集合だけ。
 * ★**保証しないもの**: 孤児を**削除しない**（人が消す）。生成器が出す本文の正しさは見ない。
 */
final class HelpBuildService
{
    public function __construct(
        private readonly HelpRepository $repository,
        private readonly HelpGeneratorRegistry $registry,
    ) {}

    /** 比較だけ行う（書き込みなし）。 */
    public function check(): HelpBuildReport
    {
        return $this->observe();
    }

    /** 生成物を書いてから、同じ規準でもう一度観測して返す。 */
    public function build(): HelpBuildReport
    {
        $this->registry->verifyRegistryIsFullyReferenced($this->repository);

        $generators = $this->registry->all();

        foreach ($this->repository->sections() as $section) {
            if ($section->generatorKey === null) {
                continue;
            }

            $absolute = $this->repository->absolutePathFor($section);
            $directory = dirname($absolute);

            if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
                throw new HelpManifestException("生成物ディレクトリを作成できません: {$directory}");
            }

            $written = file_put_contents($absolute, $generators[$section->generatorKey]->generate());
            if ($written === false) {
                throw new HelpManifestException("生成物を書き込めません: {$section->path}");
            }
        }

        return $this->observe();
    }

    private function observe(): HelpBuildReport
    {
        $this->registry->verifyRegistryIsFullyReferenced($this->repository);

        $generators = $this->registry->all();
        $observations = [];
        $declared = [];

        foreach ($this->repository->sections() as $section) {
            if ($section->generatorKey === null) {
                continue; // 手書きページは鮮度検査の母集団外 (I13)
            }

            $declared[$section->path] = true;
            $current = $this->repository->read($section);

            $state = match (true) {
                $current === null => HelpArtifactState::Missing,
                $current === $generators[$section->generatorKey]->generate() => HelpArtifactState::UpToDate,
                default => HelpArtifactState::Stale,
            };

            $observations[] = new HelpArtifactObservation($section->path, $state);
        }

        foreach ($this->repository->generatedArtifactPaths() as $path) {
            if (! isset($declared[$path])) {
                $observations[] = new HelpArtifactObservation($path, HelpArtifactState::Orphan);
            }
        }

        return new HelpBuildReport($observations);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Help;

use App\Services\Help\HelpArtifactState;
use App\Services\Help\HelpBuildReport;
use App\Services\Help\HelpBuildService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * ヘルプ生成物の**生成と鮮度検査の唯一の入口**（正典 I7）。
 *
 * ★**終了コードは 0 と 1 の 2 値だけ**（正典 I8）。例外も 1 へ畳む。
 * ★`--check` は**作業ツリーを 1 バイトも変えない**（正典 I6）。
 * ★手書きページが 0 件でも成功する（正典 I13）。
 */
final class HelpBuildCommand extends Command
{
    /** @var string */
    protected $signature = 'help:build {--check : 生成せず鮮度だけを検査する (作業ツリーを変更しない)}';

    /** @var string */
    protected $description = 'docs/help/ の生成物を組み立てる (--check は生成せず鮮度だけを検査する)';

    public function handle(HelpBuildService $service): int
    {
        $checkOnly = (bool) $this->option('check');

        try {
            $report = $checkOnly ? $service->check() : $service->build();
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->render($report, $checkOnly);

        return $report->isClean() ? self::SUCCESS : self::FAILURE;
    }

    private function render(HelpBuildReport $report, bool $checkOnly): void
    {
        foreach ($report->observations as $observation) {
            $this->components->twoColumnDetail(
                $observation->relativePath,
                $observation->state->value,
            );
        }

        if ($report->isClean()) {
            $this->components->info($checkOnly ? 'ヘルプ生成物は鮮度が保たれている。' : 'ヘルプ生成物を組み立てた。');

            return;
        }

        foreach ($report->problems() as $problem) {
            $this->components->error(match ($problem->state) {
                HelpArtifactState::Stale => "生成物が古い: {$problem->relativePath} — `php artisan help:build` を実行すること。",
                HelpArtifactState::Missing => "生成物が無い: {$problem->relativePath} — `php artisan help:build` を実行すること。",
                HelpArtifactState::Orphan => "manifest に無い生成物が残っている: {$problem->relativePath} — 削除するか manifest へ宣言すること。",
                HelpArtifactState::UpToDate => '',
            });
        }
    }
}
```

`app/Providers/AppServiceProvider.php` の `register()` へ追加する配線:

```php
$this->app->singleton(
    HelpRepository::class,
    static fn (): HelpRepository => new HelpRepository(base_path('docs/help')),
);

$this->app->singleton(
    McpToolScanner::class,
    static fn (): McpToolScanner => new McpToolScanner(base_path('app/Mcp/Tools')),
);
```

> **なぜ `--root=` オプションにしないか**: root は本番の運用者が触る値ではない。
> CLI に knob を出すと「別の場所を検査させて緑にする」経路ができる。
> テストの差し替えは container の rebind で足りる（`app()->instance(...)`）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`int` / `HelpBuildReport` / `list<HelpArtifactObservation>`）
- [x] null 安全（`file_put_contents` / `mkdir` の失敗を分岐。`read()` の `null` を Missing に写像）
- [x] DTO を返している（`HelpBuildReport`。`response()->json()` は使わない）
- [x] Generics の型パラメータが正しい（`list<HelpArtifactObservation>`）
- [x] `match (true)` は全分岐を持ち、`HelpArtifactState` の `match` は 4 case を網羅する

### テスト計画

- [x] テストファースト（先に赤くする）
- [x] 新規テスト: `tests/Feature/Help/HelpBuildFreshnessTest.php` — **鮮度ゲート本体（I4/I5）**
  - 実リポジトリの binding のまま `artisan('help:build', ['--check' => true])` が **終了コード 0**
  - **読み取りだけ**。ここでは何も書かない
  - これが CI（`composer test`）で赤くなる本体である
- [x] 新規テスト: `tests/Feature/Help/HelpBuildCommandTest.php` — **振る舞い**
  （**一時ディレクトリを root に差し替えて**実行し、リポジトリ本体を汚さない。`--parallel` 安全）
  - **I6**: `--check` の前後で一時ツリー全体の（パス→sha256）写像が**完全一致**する
  - **I8**: 終了コードが `0` と `1` の 2 値だけ（緑 / Stale / Missing / Orphan / 例外 の全経路で確認）
  - **I9**: 報告 4 種別が出る（UpToDate / Stale / Missing / Orphan）
  - **I13**: 手書きページ 0 件でも `--check` が 0 で通る
  - **対の動き**: 生成物を 1 バイト書き換える → `--check` が 1 → `help:build` 1 回 → `--check` が 0
  - **MCP ツール追加の再現**: 一時走査根にツールを 1 本足すと `--check` が Stale で 1 になる
  - **Orphan は削除しない**: `help:build` 実行後も孤児ファイルが**残っている**こと（人が消す）
  - **I10 の fail-closed**: manifest と台帳が食い違うと `--check` も `build` も 1 で止まる
- [x] 個別の `DatabaseTransactions` を使っていないことを確認（DB を使わない）

### リスク

- **並列実行**: 書き込みを伴うテストが実リポジトリの `docs/help/` を触ると `--parallel` で競合する。
  → 書き込み系は**必ず一時ディレクトリ**を root にする。実リポジトリを触るのは**読み取りだけ**の
  `HelpBuildFreshnessTest` に限る（並列でも安全）。
- **生成の二度手間**: `observe()` が生成器を呼び、`build()` でも呼ぶため生成が 2 回走る。
  → 現行 4 tool では無視できる（反射とメモリ内の文字列組み立てのみ、I/O なし）。
  早すぎる最適化はしない（思考原則 2）。
- **`mkdir` の競合**: `! is_dir() && ! mkdir() && ! is_dir()` の 3 段で TOCTOU を吸収する。

---

## S6: 検査（一覧）

| # | ファイル | 種別 | 固定する不変条件 |
|---|---|---|---|
| T1 | `tests/Architecture/HelpGeneratorRegistryTest.php` | Architecture | I10（台帳と manifest の完全一致・免除の口が無い・母集団非空） |
| T2 | `tests/Architecture/McpToolReferencePopulationTest.php` | Architecture | I3（基底の差し替え）+ 3 集合の完全一致 + 走査根の生存 + 母集団非空・床値・代表パス |
| T3 | `tests/Unit/Architecture/McpToolScannerTest.php` | Unit | 走査器の自己検査（正例 + 負例 5 種。fail-closed） |
| T4 | `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php` | Unit | I2・I14（生成・正規化・決定性・想定外で止まる） |
| T5 | `tests/Feature/Help/HelpRepositoryTest.php` | Feature | I1・I11・I12（読み取りと閉じるパス検査。負例 6 種） |
| T6 | `tests/Feature/Help/HelpBuildCommandTest.php` | Feature | I6・I7・I8・I9・I13（振る舞い。一時 root） |
| T7 | `tests/Feature/Help/HelpBuildFreshnessTest.php` | Feature | **I4・I5（鮮度ゲート本体。実リポジトリ・読み取りのみ）** |

**実装順（テストファースト）**: T3 → S4走査器 → T4 → S4生成器 → T5 → S2 → T1 → S3 → T6/T7 → S5 → S1（生成物を生成）。

---

## スコープ外（明記）

正典が**明示的に範囲外とした**もの、および正典に**存在しない**要求:

1. **表示面（HTTP route / Controller / Svelte ページ / Inertia props）** — I16。テンプレートも入れていない。
2. **MCP のヘルプ参照 tool** — 正典に存在しない（起票時 triage の誤読）。
3. **ヘルプ本文の執筆・`docs/help/pages/` の作成** — I15/I13。手書き 0 件で始め、未整備を赤にしない。
4. **`VideoStepsGenerator`（動画手順の生成器）** — I17。裁定が名指ししていない。
   （aicue の本業とは相性が良いが「今必要なものだけ作る」に従い作らない）
5. **Markdown → HTML 変換 / `league/commonmark` の導入** — 表示面が無い以上、変換先が無い。
   正典も「新規依存を要求しない」と実測している。
6. **`markdown-renderer` feature との統合** — 正典が `distinct_from` で別層と裁定している。
7. **既存 MCP コードの変更**（`AppMcpTool` / `AppMcpServer` / `ToolName` / 既存 MCP テスト）— 走査するだけ。
8. **Eloquent モデル・migration・Factory** — ヘルプはファイル系でありデータモデルを持たない。
9. **`docs/architecture.md` への追記** — 運用契約の正本は `docs/help-system.md`（正典の origin refs と同じ置き方）。
10. **lctl への書き込み（`append_event` / `status_reported`）** — 本設計フローの責務外。
11. **`docs/TODO.md` の変更** — `/app-todo-add` の責務。

---

## 乖離台帳の確認（Phase 3-0 の事前判定）

`docs/template-fingerprints.json` の**キーに在るか**で共有ファイルかどうかが決まる。
実読で確認した結果:

| パス | 指紋台帳のキー | 採用時債務 (`adoption-debt.tsv`) | 判定 |
|---|---|---|---|
| `app/Services/Help/*`（新規 9 本） | **無い** | 無い | 共有ファイルでない |
| `app/Console/Commands/Help/HelpBuildCommand.php`（新規） | **無い**（`app/Console/Commands` のキーは `Bughunt/InventoryScanCommand.php` の 1 件のみ） | 無い | 共有ファイルでない |
| `docs/help/*`（新規） | **無い**（`docs/` のキーは 13 件で help 系を含まない） | 無い | 共有ファイルでない |
| `docs/help-system.md`（新規） | **無い** | 無い | 共有ファイルでない |
| `app/Providers/AppServiceProvider.php`（**変更**） | **無い** | **無い** | 共有ファイルでない |
| `tests/**`（新規 7 本） | **無い**（新規パスなのでキーに存在しえない） | 無い | 共有ファイルでない |

**判定**: 変更・追加するパスのうち **`docs/template-fingerprints.json` のキーに在るものは 1 件も無い**。
したがって

- `docs/template-divergence.md` への**登録の追加は不要**
- `tests/Support/TemplateDivergence/LedgerPins.php` の
  `DIVERGENCE_ENTRY_COUNT` (36) / `FINGERPRINT_POPULATION_COUNT` (281) /
  `ADOPTION_DEBT_COUNT` (171) は**いずれも据え置き**
- 採用時債務一覧に在るパスを触らないので、突合 gate の 3 択（戻す / 同期する / 登録を書く）は発火しない

先例と同じ形である（`docs/TODO-closed.md` の T239 / T240:
「共有ファイル (template-fingerprints.json のキー) の変更なし = 乖離台帳の登録追加不要」）。

### 「登録するか迷ったら登録する」の適用可否

登録簿の記録の原則は「テンプレートに**無い**領域への上積みなら登録側へ倒す」である。
本件は**逆向き**である — テンプレートには本機構が **v1 として実在する**
（`laravel-claude-template@2b75053` / 正典 note の files_touched が
`app/Services/Help/HelpRepository.php` 以下を挙げている）。
本設計は aicue をテンプレートの形へ**近づける追従**であって、テンプレートの形から**外れる逸脱ではない**。
**逸脱でないものを逸脱として登録すると、登録簿が「差分の一覧」でなくなり判定力が落ちる**ので登録しない。

### 監視条件（実装時に必ず確認し、必要なら登録へ倒す）

1. **指紋台帳の再生成をこの TODO で行わない**。
   `scripts/update-template-fingerprints.php` を走らせると母集合が
   `{正典のキー} ∩ {現在の git 追跡ファイル}` で取り直され、
   **正典側の台帳に `app/Services/Help/*` のキーが在る場合**、
   本設計の実装（正典記述からの等価実装であり原本と byte 一致しない）が
   `unregisteredMismatches` として現れる。
   その場合は「内容を戻す」ことはできない（原本が手元に無い）ので、
   **意図的逸脱として `docs/template-divergence.md` へ登録し `DIVERGENCE_ENTRY_COUNT` を +1 する**のが正しい手である。
   **本 TODO のスコープでは再生成しない**（突合 gate は checked-in の台帳を読むので緑のまま。実読で確認済み）。
2. 正典 I18 が「生成物と本文は per-app のデータとして扱い、逸脱の検査では共有扱いにしない」と
   明記しているため、`docs/help/_generated/mcp-tools.md` は将来も共有扱いにしない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規ファイルが 17 本（app 9 / console 1 / docs 3 / tests 7）と多く、`app/Services/Help/` という新しい名前空間を丸ごと立てる。既存コードへの変更は `app/Providers/AppServiceProvider.php` の `register()` に binding 2 本を足すだけで、他施策と重なる面がほぼ無い。テストファースト（T3→S4→T4→…）の順序を保ったまま一気通貫で進めるほうが、途中の中間状態（走査器だけ在って生成器が無い等）を main に置かずに済む |
| 競合リスク | **低**。変更する既存ファイルは `AppServiceProvider.php` の 1 本のみ（`register()` の末尾に追加）。他の TODO が同じメソッドを触ると軽い衝突が起きうるが、追加行が独立しているため解消は容易。`app/Mcp/` は**読むだけで変更しない**ので MCP 系 TODO とは競合しない。`docs/TODO.md` / `docs/template-divergence.md` / `LedgerPins.php` は触らない |


---

## 関連する現行コード（走査対象・整合先）

### `app/Mcp/Tools/AppMcpTool.php`

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Mcp\ToolName;
use App\Exceptions\Mcp\InvalidParamsException;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use App\Services\Mcp\McpIdempotencyService;
use App\Values\Mcp\IdempotencyKey;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * MCP tool のテンプレートメソッド基底 (OAuth 対応)。
 *
 * 認証は HTTP 層の `auth:mcp-oauth` (Passport Bearer) が担い、認可
 * ({@see ToolName::requiredPermission()} 経由の runtime 再評価)、冪等性
 * (書き込み系のみ)、構造化ログを base で処理する。
 * 子クラスは `toolName()` と `runTool()` のみ実装する。
 *
 * handle() シグネチャは laravel/mcp の container->call 経由で以下が自動注入される:
 * - `\Laravel\Mcp\Request`    : MCP JSON-RPC arguments
 * - `\Illuminate\Http\Request`: HTTP Request (認可 context 取得用、Passport token 情報)
 */
abstract class AppMcpTool extends Tool
{
    public function __construct(
        protected readonly McpIdempotencyService $idempotency,
    ) {}

    public function shouldRegister(HttpRequest $httpRequest): bool
    {
        // guard 解決は `McpAuthorizationContext::resolveAuthenticatedUser` に一本化する。
        // Authorization ヘッダがある場合は `mcp-oauth` guard を必ず優先し、
        // default guard (session user) にはフォールバックしない。
        if (McpAuthorizationContext::resolveAuthenticatedUser($httpRequest) === null) {
            return false;
        }

        try {
            $ctx = McpAuthorizationContext::for($httpRequest);
        } catch (Throwable) {
            return false;
        }

        return $ctx->authorizeTool($this->toolName());
    }

    final public function handle(McpRequest $mcpRequest, HttpRequest $httpRequest): Response
    {
        $ctx = McpAuthorizationContext::for($httpRequest);

        if (! $ctx->authorizeTool($this->toolName())) {
            throw new AuthorizationException('Not permitted for this tool.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $mcpRequest->all();

        $idempotencyKey = null;
        if ($this->toolName()->isWriteTool()) {
            $idempotencyKey = $this->extractIdempotencyKey($mcpRequest);

            $replay = $this->idempotency->replay(
                organizationId: $ctx->organization->id,
                userId: $ctx->user->id,
                toolName: $this->toolName()->value,
                key: $idempotencyKey,
                payload: $payload,
            );
            if ($replay !== null) {
                $this->logInvocation($ctx, durationMs: 0, success: true, replay: true);

                return $this->toResponse($replay);
            }
        }

        $start = hrtime(true);
        try {
            $responsePayload = $this->runTool($mcpRequest, $ctx);
        } catch (Throwable $e) {
            $this->logInvocation(
                $ctx,
                durationMs: self::durationMs($start),
                success: false,
                errorCode: $e::class,
            );
            throw $e;
        }

        if ($idempotencyKey instanceof IdempotencyKey) {
            $this->idempotency->store(
                organizationId: $ctx->organization->id,
                userId: $ctx->user->id,
                toolName: $this->toolName()->value,
                key: $idempotencyKey,
                payload: $payload,
                response: $responsePayload,
            );
        }

        $this->logInvocation($ctx, durationMs: self::durationMs($start), success: true);

        return $this->toResponse($responsePayload);
    }

    abstract protected function toolName(): ToolName;

    /**
     * MCP server が tools/call で lookup する名前。`ToolName` enum の canonical 値
     * (e.g. `whoami`, `list-projects`) を返す。
     *
     * デフォルトの Primitive::name() は `Str::kebab(class_basename($this))` を返すため
     * `WhoamiTool` → `whoami-tool` になってしまい、tools/call との lookup が一致しない。
     * 本 override で enum canonical value に揃える。
     *
     * `#[\Override]` を付与し、laravel/mcp の Primitive::name() API が変化した際に
     * 早期に検知できるようにする。
     */
    #[\Override]
    public function name(): string
    {
        return $this->toolName()->value;
    }

    /**
     * 各子 tool の業務ロジック。返却は `array<string, mixed>` の正規化済 payload。
     * Response 化とログは base 側で行う。
     *
     * @return array<string, mixed>
     */
    abstract protected function runTool(McpRequest $request, McpAuthorizationContext $ctx): array;

    /** @param array<string, mixed> $payload */
    protected function toResponse(array $payload): Response
    {
        return Response::json($payload);
    }

    private function extractIdempotencyKey(McpRequest $request): IdempotencyKey
    {
```

### `app/Mcp/Tools/WhoamiTool.php`

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Mcp\ToolName;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request as McpRequest;
use Webmozart\Assert\Assert;

/**
 * whoami: 認証済み principal (User) と bound organization のエコー。
 */
final class WhoamiTool extends AppMcpTool
{
    protected string $description = 'Return the authenticated user and the organization bound to the access token.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function toolName(): ToolName
    {
        return ToolName::Whoami;
    }

    protected function runTool(McpRequest $request, McpAuthorizationContext $ctx): array
    {
        $role = $ctx->user->organizationRole($ctx->organization);
        Assert::notNull($role, 'User must be a member of the bound organization.');

        return [
            'user' => [
                'id' => $ctx->user->id,
                'name' => (string) $ctx->user->name,
            ],
            'organization' => [
                'id' => $ctx->organization->id,
                'name' => $ctx->organization->name,
                'role' => $role->value,
            ],
        ];
    }
}

```

### `app/Mcp/Tools/ListItemsTool.php`

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Mcp\ToolName;
use App\Models\Item;
use App\Models\Project;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request as McpRequest;

/**
 * list-items: bound organization 内プロジェクトの Item 一覧。
 */
final class ListItemsTool extends AppMcpTool
{
    protected string $description = 'List items of a project in the organization bound to the access token.';

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->description('Project ID')->required(),
            'page' => $schema->integer()->description('Page number (1..1000)'),
            'per_page' => $schema->integer()->description('Items per page (1..100)'),
        ];
    }

    protected function toolName(): ToolName
    {
        return ToolName::ListItems;
    }
```

### `app/Mcp/Servers/AppMcpServer.php`

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ListItemsTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ShowProjectTool;
use App\Mcp\Tools\WhoamiTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * テンプレートの MCP サーバー (read 系 tool 4 種)。
 *
 * 認証は OAuth 2.1 Bearer (auth:mcp-oauth middleware。routes/ai.php 参照)。
 * 認可 (permission runtime 再評価)・冪等性 (write 系)・構造化ログは基底
 * AppMcpTool + ToolName enum が配線する。tool を追加する場合は ToolName に
 * case を足して (write 系は isWriteTool=true) ここに登録する
 * (ToolNameInvariantTest が enum とサーバ登録の 1:1 対応を強制する)。
 */
final class AppMcpServer extends Server
{
    protected string $name = 'Application MCP Server';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server exposes read-only access to the organization bound to the OAuth access token:
        whoami, list-projects, show-project, and list-items.
    MARKDOWN;

    /** @var list<class-string<Tool>> */
    protected array $tools = [
        WhoamiTool::class,
        ListProjectsTool::class,
        ShowProjectTool::class,
        ListItemsTool::class,
    ];
}

```

### `app/Enums/Mcp/ToolName.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums\Mcp;

/**
 * MCP tool の canonical name 一覧。
 *
 * - `requiredPermission()`: 各 tool の Laratrust permission (null = member 基本権限)
 * - `isWriteTool()`: 書き込み系 (idempotency_key required) 判定
 *
 * 認可判定の single source。各 Tool class では override せず本 enum を参照する
 * (AppMcpTool::name() が enum 値を tools/call の lookup 名として返す)。
 * サーバ登録 (AppMcpServer::$tools) との 1:1 対応は ToolNameInvariantTest が強制する。
 */
enum ToolName: string
{
    case Whoami = 'whoami';
    case ListProjects = 'list-projects';
    case ShowProject = 'show-project';
    case ListItems = 'list-items';

    /**
     * tool 名 → 必要 Laratrust permission の対応。
     *
     * <!-- TEMPLATE-MARKER: アプリ固有の permission gate をここに追加する。
     *      例: self::RunEvaluation->value => 'evaluations-run'。permission は
     *      PermissionSeeder に定義し、McpAuthorizationContext::authorizeTool が
     *      laratrust_team_id 明示で評価する。テンプレートの read 系 tool は
     *      member 基本権限のみ (エントリなし = null)。 -->
     *
     * @return array<string, non-empty-string>
     */
    private static function permissionMap(): array
    {
        return [];
    }

    /**
     * tool 実行に必要な Laratrust permission (organization team scope で runtime 再評価)。
     * null = member 基本権限のみで呼べる。
     */
    public function requiredPermission(): ?string
    {
        return self::permissionMap()[$this->value] ?? null;
    }

    /**
     * 書き込み系 tool か (true なら idempotency_key (UUID v4) が必須になり、
     * AppMcpTool が McpIdempotencyService で replay/store を配線する)。
     *
     * match を網羅で書くことで、tool 追加時に write/read の判断を強制する。
     */
    public function isWriteTool(): bool
    {
        return match ($this) {
            self::Whoami,
            self::ListProjects,
            self::ShowProject,
            self::ListItems => false,
        };
    }
}

```

### `tests/Feature/Mcp/ToolNameInvariantTest.php`

```php
<?php

declare(strict_types=1);

use App\Enums\Mcp\ToolName;
use App\Mcp\Servers\AppMcpServer;
use App\Mcp\Tools\AppMcpTool;
use Laravel\Mcp\Server\Tool;

/*
 * ToolName enum と AppMcpServer のサーバ登録が 1:1 で対応する不変条件。
 * tool を追加して enum への case 追加 (= 認可/冪等の判断) を忘れる、
 * またはその逆をビルド時に検出する。
 */

/**
 * AppMcpServer に登録された tool class 一覧を reflection で取得する。
 *
 * @return list<class-string<Tool>>
 */
function registeredMcpToolClasses(): array
{
    $reflection = new ReflectionClass(AppMcpServer::class);
    $property = $reflection->getProperty('tools');

    /** @var list<class-string<Tool>> $tools */
    $tools = $property->getValue($reflection->newInstanceWithoutConstructor());

    return $tools;
}

test('ToolName の case とサーバ登録 tool は 1:1 に対応する', function (): void {
    $registeredNames = array_map(
        static fn (string $class): string => app($class)->name(),
        registeredMcpToolClasses(),
    );
    sort($registeredNames);

    $enumNames = array_map(static fn (ToolName $t): string => $t->value, ToolName::cases());
    sort($enumNames);

    expect($registeredNames)->toBe($enumNames);
});

test('登録済み tool はすべて AppMcpTool を継承する (認可/冪等/ログの配線保証)', function (): void {
    foreach (registeredMcpToolClasses() as $class) {
        expect(is_subclass_of($class, AppMcpTool::class))
            ->toBeTrue("{$class} は AppMcpTool を継承すること");
    }
});
```

### `phpstan.neon`

```php
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 10
    paths:
        - app
        - config
        - database
        - routes
    excludePaths:
        - vendor
    ignoreErrors:
        # AppliesCriticalActionContextToAudit は派生アプリの Auditable モデル向けに
        # テンプレートが同梱する trait (テンプレート本体は Auditable モデルを同梱しない
        # ため使用箇所ゼロ)。派生アプリで使用された時点で通常解析される。
        # 実挙動は tests/Feature/Audit/ModelAuditGatingTest.php が検証している。
        -
            identifier: trait.unused
            path: app/Models/Concerns/AppliesCriticalActionContextToAudit.php

```


---

## AGENTS.md 「静的検査 (gate) と走査器の共通規約」(L225-300 抜粋)

```
## 静的検査 (gate) と走査器の共通規約

**対象**: `tests/Support/` 配下の検出器 / gate の中に直接書かれた走査ロジック /
それらを使う gate (`tests/Architecture/` / `tests/js/architecture/`)。
次の 5 条を満たす。家系の機能台帳の正典 v1 をそのまま写したもので、5 条とも
**「検査は緑なのに穴が開いていた」実測事故**から出ている
(設計と既存の食い違いの棚卸しは `devnotes/20260818-0303-scanner-common-conventions/`)。

**条ごとの適用範囲**: (b)〜(d) は**該当するすべての走査**に適用する。
(a) は**クラス名・名前参照を解決する走査**、(e) は**語彙一致を判定する走査**にだけ適用する
(文字列だけを見る走査に (a) は無意味であり、名前を解決する走査に (e) は無関係である)。

- **(a) クラス参照は完全修飾名で突き合わせる**。`use` / group use / 別名つき取り込みを解いた
  完全修飾名で比べる。短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は
  同名の別クラスを拾う。**構文解析ライブラリの使用は必須ではない** (家系の裁定 AG-154 の (2))。
  字句走査 + 取り込み対応表でよく、条件は (b) と (c) を満たすことだけである
- **(b) 解決できない形は落とす (fail-closed)**。判定を拾いすぎる方向へ倒すのは可、
  見逃す方向へ倒すのは不可。ここでいう「落とす」は**見逃さない**という意味であり、
  正常なコードを違反と断定することではない。具体的には次の 3 つを守る。
  - **未解決を解決済みと同じ値へ混ぜない**。gate が保証すると宣言した範囲の中で参照を
    解決できなかったら、**未解決だと判別できる結果**か解析の失敗として利用側へ返し、
    gate を失敗させる。**無言で候補から外さない**
  - **保証範囲の外にする構文は docblock へ明記する**。明記したなら、その構文について
    **検出力を主張しない** (明記せずに落ちこぼすのは (b) 違反である)。
    ただし**保証範囲は走査器 1 本の docblock だけでは決まらない** — 利用側 gate の名前・
    守ると宣言した不変条件・検出力の主張まで含めて判定する。
    **走査器の限界を書き足すことは、既にある見逃しを規約適合へ変えない**。
    保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は
    **検出力の主張をその構文を除く形へ明示的に狭める**か、**未解決として失敗させる**かのどちらかにする
  - **「違反が 0 件」と「母集団が 0 件」を区別する**。落とすのは後者だけである。
    違反ゼロが正常な gate はいくらでもあるが、**判定に使う母集団が空**なのに緑になる形は、
    走査根の改名・ディレクトリ移動・抽出条件の綴り間違いで**走査が壊れても気付けない**。
    適用対象は「母集団の非空が不変条件である gate」で、**入力を受け取って候補を返し、
    母集団の非空を契約としない再利用可能な検出器は対象外**である
    (その場合は検出器を**使う側の gate** が母集団の非空を持つ)
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、
  規定どおりの入力を誤検出しないことの**両方向**を固定する
- **(d) 集めた走査結果を判定に使わない形を作らない**。収集するが誰も参照しない出力、
  数えるだけで比べない目録を作らない
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。
  正規表現の語境界や素の部分文字列一致に頼らない。
  **何を区切りとするかは走査ごとに宣言する** (準拠実装: `tests/js/support/ds-purity.ts` が
  スタイル記述を class トークンへ割る文字集合を宣言し、その文字集合で割れない書き方は
  許可一覧へ登録できないことも併せて書いている)。
  負例には最低でも**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く
  (許可語の除去を素の部分文字列で書いたため、この 3 形まで一緒に消えて検出漏れになっていた、
  が本リポジトリの実測である)

### 走査器・gate を新設・変更するときに同じ PR で揃える 4 点

**発火条件**: 走査ロジック・走査対象・名前解決・判定条件・目録のいずれかを新設または変更するとき。
**コメントや docblock を実態に合わせて訂正するだけで検出範囲を変えない変更は発火しない**
(既知の不適合はその場で直さず、棚卸しに記録して別 TODO で追跡する)。

1. **負例と正例**。テストファーストで**先に赤くしてから**本体を書く (思考原則 5)。
   既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する
2. **解決できない形を落とす分岐** ((b))
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」検査、
   `PromptGuardrailTest` の「各走査根が解決でき、いずれも空でない」検査)
4. **docblock に走査対象と保証しないものを書く**。中身の正本は docblock 側に置き、
   本書へ写さない

### 本リポジトリでの置き方

- **走査根の単一出典**: git 追跡下の PHP 全数を母集団にする走査は
  `Tests\Support\TrackedPhpSourceFiles` を使う。同じ列挙を 2 本持たない。
  母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
  (準拠実装 `PrismDirectDispatchScanner::roots()`)
- **負例の置き場は 3 通りとも認める**: 見本ファイル (`tests/Architecture/fixtures/`) /
  検出器の自己検査 (`tests/Unit/Architecture/`) / gate 内の合成入力。
  どこに置いてもよいが、**gate または検出器の docblock から辿れること**。
  1 つへ寄せる作業に見合う効果が無いため寄せない (思考原則 2)

### 検出力の主張の書き方


```
