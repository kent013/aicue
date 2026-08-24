## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

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

# あなたの役割

Laravel + Svelte アプリ「AI-CUE」の**実装レビュアー**である。TODO T246 (ヘルプ機構の aicue 追従) の実装差分をレビューせよ。

## レビュー観点

1. **設計との一致性**: 詳細設計書が定めた不変条件 (I1〜I19) を実装が実際に満たしているか。設計から逸れた箇所があれば、その逸脱が正当か。
2. **正確性**: 論理の誤り・境界条件・TOCTOU・例外の握り潰し・fail-open になっている経路。
3. **PHPStan level 10 適合性**: 型の緩め・`mixed` の漏れ・`@phpstan-ignore` の混入が無いか (`composer phpstan` は緑である)。
4. **DTO / JsonResource パターン**: 配列を Service の戻り値にしていないか。`response()->json()` の直書きが無いか (本件は HTTP 面を持たない)。
5. **テスト網羅性**: 走査器・gate の共通規約 4 点 (負例と正例 / 解決できない形を落とす分岐 / 走査が空振りしていないことの検査 / docblock に走査対象と保証しないものを書く) を満たすか。**負例が空振りしていないか**を特に見よ。
6. **セキュリティ**: パス走査 (path traversal) / symlink 経由の置き場外への書き込み / 実体検査の抜け。
7. **DESIGN.md 準拠 / Atomic Design 準拠**: 本差分は `resources/js/` の Svelte / CSS を **1 行も変更していない** (触れたのは `tests/js/architecture/enum-ts-sync-discovery.test.ts` の目録登録 1 行のみ) ため、design token / atomic 階層の観点は非該当である。もし該当する変更を見つけたら指摘せよ。
8. **過剰実装**: 正典 (裁定 AG-100) が求めていないものを作っていないか (思考原則「今必要なものだけ作る」)。

## 出力形式

- **ファイルごとに判定**を書く。
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する。
  - Critical: 不変条件の破れ・セキュリティ・データ破壊・設計との重大な不一致
  - Warning: 検出力の抜け・可読性を損なう構造・将来壊れる形
  - Suggestion: 好みの範囲
- 最後に **全体判定** を `APPROVED` または `CHANGES_REQUESTED` の 1 語で書く。

## 補足情報 (レビュー時に前提としてよい)

- `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` は**すべて緑**である。
- 対象の 118 テスト (`composer test -- --filter='Help|McpTool'`) は **118 passed / 299 assertions** で緑である。
- `docs/template-fingerprints.json` の 281 キーに、本差分が触れたパスは **1 件も無い** (実測確認済み) ため、`docs/template-divergence.md` への登録追加と `LedgerPins` の件数変更は不要である。
- 設計からの意図的な差分は 3 点ある。妥当性を判定せよ:
  1. `McpToolMetadata::fromSchema()` を **public** にした (設計は `fromTool()` のみ)。理由: 設計のテスト計画が要求する I14 の負例 8 種 (vendor が実際には出しえない形を含む) を検査から与える境界が要るため。`fromTool()` は `fromSchema()` へ委譲するので、検査した経路と実行時の経路は同一である。
  2. `HelpRepository::writeGenerated()` の書き込み後の再検査を private メソッド `assertWrittenFileIsRegular()` へ切り出し、`clearstatcache()` を先に呼ぶようにした。理由: PHP の stat キャッシュを消さないと「書いた後の姿」を見たことにならない (かつ PHPStan が同一式の再評価を常に false と推論して落ちた)。
  3. `HelpBuildService` が生成器を取り出す箇所を添字参照から `generatorFor()` (不在なら例外) へ変えた。理由: 不在を暗黙に許す添字参照を書かないため。
- 設計に無い追加が 1 点ある。妥当性を判定せよ:
  4. `tests/js/architecture/enum-ts-sync-discovery.test.ts` の `PHP_ENUM_EXEMPTIONS` へ `app/Services/Help/HelpArtifactState.php` を登録し、件数 pin を 87 → 88 にした。理由: ドメイン固有規約 19 により `app/` の文字列付き列挙は全数走査で既定拒否されるため、登録しないと `pnpm test` が赤くなる。


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
 *   実体の検査 (`resolveRealDirectory` / `readResolved`) を**やり直す**。
 *   片方だけを通した結果を使い回さない。
 * ★**未検査の絶対パスを外へ出さない**。読み書きの両方を本クラスに閉じ込める
 *   (`read()` / `writeGenerated()`)。呼び出し側が絶対パスを組み立てる口は持たない
 *   — 持たせると「字句だけ通したパスへ書く」経路が必ず生まれる。
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

    /** 読める manifest の schema 版 (厳密一致。未知の版は読まずに落とす)。 */
    private const int SCHEMA_VERSION = 1;

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
        $seenGenerators = [];

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
            // ★generator は 1 節につき 1 本 (I10 の「完全一致」を集合一致へ弱めない)。
            //   `HelpGenerator::generate()` は節を引数に取らないので、
            //   同じ生成器を 2 節が参照する形は「同じ内容を 2 か所へ書く」意味しか持たない。
            if ($generatorKey !== null && isset($seenGenerators[$generatorKey])) {
                throw new HelpManifestException(
                    "manifest の generator が重複しています: {$generatorKey} — ".
                    '1 つの生成器を参照できる節は 1 つだけである。',
                );
            }
            $seenSlugs[$slug] = true;
            $seenPaths[$path] = true;
            if ($generatorKey !== null) {
                $seenGenerators[$generatorKey] = true;
            }

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
        $rootReal = $this->resolveRealDirectory($this->root, 'ヘルプの置き場');
        $dir = $rootReal.'/'.self::GENERATED_DIR;

        if (is_link($dir)) {
            throw new HelpManifestException(
                "生成物ディレクトリに symlink は使えません: {$dir} — 実ディレクトリに置き換えること。",
            );
        }
        if (! file_exists($dir)) {
            return [];
        }

        $dirReal = $this->resolveRealDirectory($dir, '生成物ディレクトリ');
        if ($dirReal !== $dir) {
            throw new HelpManifestException("生成物ディレクトリが置き場の外を指しています: {$dir}");
        }

        $entries = scandir($dirReal);
        if ($entries === false) {
            throw new HelpManifestException("生成物ディレクトリを走査できません: {$dirReal}");
        }

        $paths = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $dirReal.'/'.$entry;

            // ★symlink / FIFO / socket / ディレクトリを「通常の生成物候補」に混ぜない。
            //   `.md` で終わる symlink を Orphan として静かに返すと、
            //   「通常ファイルでない実体は例外」という規約が字句だけの飾りになる。
            if (is_link($absolute)) {
                throw new HelpManifestException(
                    "生成物ディレクトリに symlink があります: {$absolute} — 削除すること。",
                );
            }
            if (is_dir($absolute)) {
                throw new HelpManifestException(
                    "生成物ディレクトリは階層を許しません: {$absolute} — ".
                    'ディレクトリを削除し、生成物は '.self::GENERATED_DIR.'/ 直下に置くこと。',
                );
            }
            if (! is_file($absolute)) {
                throw new HelpManifestException(
                    "生成物ディレクトリに通常ファイルでない実体があります: {$absolute} — 削除すること。",
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

    /**
     * 生成物を書き込む。**書き込み経路の実体検査も本クラスに閉じ込める**。
     *
     * ★字句検査だけを通した絶対パスを呼び出し側へ渡さない (`absolutePathFor()` は持たない)。
     *   渡すと「`_generated` が外部ディレクトリへの symlink」で置き場の外へ書けてしまう。
     * ★ディレクトリ作成は**非再帰**である (階層を作れない = I11 を作成側でも守る)。
     * ★書き込みの**後**にもう一度実体を検査する (作成の途中で入れ替えられた形を残さない)。
     *
     * @throws HelpManifestException
     */
    public function writeGenerated(HelpSection $section, string $contents): void
    {
        if (! $section->isGenerated()) {
            throw new HelpManifestException("手書きページを生成物として書き込めません: {$section->path}");
        }

        $this->assertRelativePath($section->path, true, null);

        $rootReal = $this->resolveRealDirectory($this->root, 'ヘルプの置き場');
        $dir = $rootReal.'/'.self::GENERATED_DIR;

        if (is_link($dir)) {
            throw new HelpManifestException(
                "生成物ディレクトリに symlink は使えません: {$dir} — 実ディレクトリに置き換えること。",
            );
        }
        if (! is_dir($dir) && ! mkdir($dir, 0o755) && ! is_dir($dir)) {
            throw new HelpManifestException("生成物ディレクトリを作成できません: {$dir}");
        }

        $dirReal = $this->resolveRealDirectory($dir, '生成物ディレクトリ');
        if ($dirReal !== $dir) {
            throw new HelpManifestException("生成物ディレクトリが置き場の外を指しています: {$dir}");
        }

        $absolute = $dirReal.'/'.basename($section->path);

        if (is_link($absolute)) {
            throw new HelpManifestException("生成物に symlink は使えません: {$section->path}");
        }
        if (file_exists($absolute) && ! is_file($absolute)) {
            throw new HelpManifestException("生成物の実体が通常ファイルではありません: {$section->path}");
        }

        if (file_put_contents($absolute, $contents) === false) {
            throw new HelpManifestException("生成物を書き込めません: {$section->path}");
        }

        // 書き込み後の再検査 (字句 → 実体 → 書き込み → 実体、の 4 段で閉じる)
        if (is_link($absolute) || ! is_file($absolute)) {
            throw new HelpManifestException("書き込んだ生成物が通常ファイルではありません: {$section->path}");
        }
    }

    /**
     * ディレクトリの実体を解決する (symlink を辿った後の絶対パス)。
     *
     * @return non-empty-string
     *
     * @throws HelpManifestException
     */
    private function resolveRealDirectory(string $path, string $label): string
    {
        $real = realpath($path);

        if ($real === false || $real === '' || ! is_dir($real)) {
            throw new HelpManifestException("{$label}をディレクトリとして解決できません: {$path}");
        }

        return $real;
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

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new HelpManifestException('manifest の最上位が object ではありません。');
        }

        // ★宣言しておいて読まない値を作らない (fail-open の温床)。
        //   厳密比較なので文字列 "1" も未知の 2 も弾く。schema を変えるときは
        //   このコードを同じ変更で直すことになる = 旧コードが新 schema を誤読しない。
        $schemaVersion = $decoded['schema_version'] ?? null;
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new HelpManifestException(
                'manifest の schema_version が '.self::SCHEMA_VERSION.' ではありません — '.
                'このコードが読めるのは schema_version '.self::SCHEMA_VERSION.' だけである。',
            );
        }

        if (! array_key_exists('sections', $decoded)) {
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

- [x] 戻り値の型が明示されている（`list<HelpSection>` / `?string` / `list<non-empty-string>` / `void`）
- [x] null 安全（`realpath` / `file_get_contents` / `scandir` / `file_put_contents` / `mkdir` の失敗を全て分岐）
- [x] DTO を返している（`HelpSection`。配列返却は「パスの一覧」だけで `list<non-empty-string>` に絞っている）
- [x] Generics の型パラメータが正しい（`array<array-key, mixed>` / `list<mixed>`）
- [x] `mixed` を上流へ漏らさない（`json_decode` の結果は `requireNonEmptyString` で型を確定してから DTO へ入れる）

### テスト計画

- [x] テストファースト: **先に赤くしてから**本体を書く（走査器・gate 規約 1）
- [x] 新規テスト: `tests/Feature/Help/HelpRepositoryTest.php`
  - 正例: 生成物 1 件 + 手書き 0 件の manifest を読める / 手書き 1 件も読める
  - **字句の負例 6 種（I12）**: `..` を含む path / 絶対パス / `pages` `_generated` 以外のディレクトリ /
    `_generated/sub/x.md`（階層化） / 実体が symlink / 実体が置き場の外を指す
  - **manifest の負例**: 不在 / JSON 破損 / 最上位が list / `sections` が list でない /
    slug 重複 / path 重複 / `generator` が空文字 / **`generator` の重複**（S3 の指摘）
  - **`schema_version` の負例 3 種**: 欠落 / 文字列 `"1"`（型違い） / 未知の `2`
  - **実体の負例**: `_generated` 自体が symlink / `.md` の symlink / FIFO /
    `.md` 以外のファイル / `_generated` 直下のディレクトリ
  - **書き込み経路の負例（Critical の再発防止）**: `_generated` を置き場の外の実ディレクトリへの
    symlink にした状態で `writeGenerated()` が**例外で止まり**、
    **外部ディレクトリのファイルが 1 バイトも変化しない**こと
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
  - **負例（重複）**: **同じ generator key を 2 つの section が参照したら赤**
    （集合一致へ弱まっていないこと。指摘 S3 の再発防止）
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

            // ★走査したファイルと、autoload が解決したクラスの実体が同じであることを要求する。
            //   `class_exists()` は Composer autoload から**別のファイル**をロードしうるので、
            //   これを見ないと「一時 root の fixture を走査しているつもりで本物を見ている」
            //   状態に気付けず、負例が空振りする (検出力の主張が崩れる)。
            $declaredIn = $reflection->getFileName();
            $declaredReal = $declaredIn === false ? false : realpath($declaredIn);
            $scannedReal = realpath($absolute);

            if ($declaredReal === false || $scannedReal === false || $declaredReal !== $scannedReal) {
                throw new RuntimeException(
                    "{$class} の実体が走査中のファイルと一致しません ".
                    '(走査: '.$absolute.' / 解決: '.var_export($declaredIn, true).') — '.
                    'ファイル名とクラス名 / namespace が一致しているか、'.
                    '同名クラスが別の場所から autoload されていないか確認すること。',
                );
            }

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
        $hint = 'vendor (laravel/mcp / illuminate/json-schema) の出力形が変わった可能性がある。'.
            'McpToolMetadata の正規化を新しい形に合わせて直すこと。';

        // vendor 実読: properties は 0 件のとき、required は必須 0 件のとき、いずれもキーごと消える。
        $hasProperties = array_key_exists('properties', $schema);
        $hasRequired = array_key_exists('required', $schema);

        // ★「required はあるが properties が無い」は vendor では起きえない形である。
        //   これを 0 件として黙って受けると、必須パラメータが**静かに欠ける**。
        if ($hasRequired && ! $hasProperties) {
            throw new RuntimeException(
                "{$className}: schema に required があるのに properties がありません — {$hint}",
            );
        }

        $properties = $hasProperties ? $schema['properties'] : [];
        if (! is_array($properties)) {
            throw new RuntimeException("{$className}: schema の properties が配列ではありません — {$hint}");
        }

        $required = [];
        $rawRequired = $hasRequired ? $schema['required'] : [];
        if (! is_array($rawRequired) || ! array_is_list($rawRequired)) {
            throw new RuntimeException("{$className}: schema の required が list ではありません — {$hint}");
        }
        foreach ($rawRequired as $key) {
            if (! is_string($key) || $key === '') {
                throw new RuntimeException(
                    "{$className}: schema の required に非空の文字列でない要素があります — {$hint}",
                );
            }
            if (isset($required[$key])) {
                throw new RuntimeException(
                    "{$className}: schema の required に重複があります: {$key} — {$hint}",
                );
            }
            if (! array_key_exists($key, $properties)) {
                throw new RuntimeException(
                    "{$className}: schema の required `{$key}` が properties にありません — {$hint}",
                );
            }
            $required[$key] = true;
        }

        $parameters = [];
        foreach ($properties as $name => $definition) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException(
                    "{$className}: schema の properties にパラメータ名が非空の文字列でない要素があります — {$hint}",
                );
            }
            if (! is_array($definition)) {
                throw new RuntimeException(
                    "{$className}: schema のパラメータ `{$name}` の定義が配列ではありません — {$hint}",
                );
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
            // ★union / nullable は **list** で来る (vendor 実読)。
            //   associative を受けてキーを捨てると、形の変化が静かに通る。
            if (! array_is_list($type) || $type === []) {
                throw new RuntimeException(
                    "{$className}: パラメータ `{$name}` の type が非空の list ではありません — ".
                    'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
                );
            }

            $parts = [];
            foreach ($type as $part) {
                if (! is_string($part) || $part === '') {
                    throw new RuntimeException(
                        "{$className}: パラメータ `{$name}` の type に非空の文字列でない要素があります — ".
                        'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
                    );
                }
                $parts[] = $part;
            }

            return implode('|', $parts);
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
      **基底を継承していない具象** / **実体が symlink** /
      **`ReflectionClass::getFileName()` が走査中のファイルと一致しない**
      （同名クラスが別の場所から autoload 済み。fixture は明示 `require` して
      Reflection の実体が一時 root を指す形にする）
  - `tests/Architecture/McpToolReferencePopulationTest.php`
    - 負例: 走査集合・登録集合・enum 集合のいずれかがずれたら赤（合成入力で両方向）
- [x] **2. 解決できない形を落とす分岐（fail-closed）**: 上記負例が「例外で止まる」ことを固定する
      （空を返して緑になる形を作らない）
- [x] **3. 走査が空振りしていないことの検査**:
  - 走査根 `app/Mcp/Tools/` が実在すること
  - 母集団が**非空**であること
  - **「違反 0 件」と「母集団 0 件」を区別する**（3 集合がすべて空でも一致になる形を禁じる）
  - 床値・代表クラス名は pin しない（正典が求めていない拘束を足さない。上の理由参照）
- [x] **4. docblock に走査対象と保証しないものを書く**: 上のコードに記載済み。
      **正本は docblock 側**であり本書・`AGENTS.md` へは写さない
- [x] `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php`
  - 決定性: 2 回生成して byte 一致
  - 正規化: `type` が配列（union/nullable）でも `|` 連結の表示文字列になる / `type` 未宣言は `(未宣言)`
  - **I14 の負例**: 以下がすべて **例外で止まる**（静かに欠けない）。
    メッセージへの要求は**負例の種類ごとに分ける**（一律の曖昧な assert を置かない）:
    1. **全負例で共通**: 対象クラス名 / 不正だった箇所 / 直し方（`$hint`）の 3 点
    2. **パラメータを特定できる負例のみ**: 追加でパラメータ名
    3. **キーが判明している負例**（`required` の重複 / `properties` に無い required 名）:
       追加でそのキー名
    - `type` が数値・object
    - `type` が **associative array**（list でない union）／空配列
    - `required` が **associative array**（list でない）
    - `required` の要素が **空文字**／**重複**／**`properties` に無い名前**
    - **`required` はあるのに `properties` が無い**（必須パラメータが静かに欠ける形）
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
        ->and($scanned)->toBe($registered)
        ->and($registered)->toBe($enum);
});

test('走査根が生きていること', function (): void {
    expect(is_dir(base_path('app/Mcp/Tools')))->toBeTrue();
});
```

> **床値・代表クラス名を pin しない理由**: `AGENTS.md` の走査規約 3 が要求するのは
> 「母集団が空でないこと / 走査根がそれぞれ生きていること」までである。
> 「4 件以上」や現行 4 クラスの名指し pin は、**将来ツールを 1 本正当に廃止しただけで赤くなる**。
> 正典が求めていない拘束であり、本件の大前提（正典 v1 に忠実な最小）に反するので置かない。
> 母集団の非空は `McpToolScanner` 自身の契約（0 件で例外）と、
> 一致相手が first-party の `ToolName` enum であることが支える。

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

            // ★絶対パスをこの層で組み立てない。書き込み先の実体検査ごと Repository に閉じる
            //   (`_generated` が外部への symlink でも置き場の外へ書けない)。
            $this->repository->writeGenerated($section, $generators[$section->generatorKey]->generate());
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
use Throwable;

/**
 * ヘルプ生成物の**生成と鮮度検査の唯一の入口**（正典 I7）。
 *
 * ★**終了コードは 0 と 1 の 2 値だけ**（正典 I8）。例外も 1 へ畳む。
 *   捕捉は `Throwable` である — `RuntimeException` だけでは
 *   `Webmozart\Assert` の `InvalidArgumentException`・container の
 *   `BindingResolutionException`・`TypeError` 等の `Error` 系が素通りし、
 *   0/1 以外の終わり方が生まれる。
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
        } catch (Throwable $e) {
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
- [x] null 安全（`read()` の `null` を Missing に写像。書き込みの失敗分岐は `HelpRepository::writeGenerated()` に閉じている）
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
  - **I8**: 終了コードが `0` と `1` の 2 値だけ（緑 / Stale / Missing / Orphan / 例外 の全経路で確認）。
    **`HelpManifestException` 以外の `Throwable` でも 1 になること** —
    Registry の container binding を誤った型へ差し替えて
    `Assert::isInstanceOf()` の `InvalidArgumentException` を起こす経路で確認する
    （`RuntimeException` だけを捕捉していた欠陥の再発防止）
  - **書き込みが置き場の外へ出ないこと**: `_generated` を外部への symlink にした状態で
    `help:build` が 1 で止まり、**外部ファイルが 1 バイトも変化しない**こと
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
- **`mkdir` の競合**: `HelpRepository::writeGenerated()` が
  `! is_dir() && ! mkdir() && ! is_dir()` の 3 段で TOCTOU を吸収する。
  **非再帰**なので `_generated` の下に階層を作れない（I11 を作成側でも守る）。
- **書き込み経路の実体検査**: `_generated` が置き場の外への symlink でも、
  `writeGenerated()` が「symlink 拒否 → realpath の完全一致 → 書き込み → 再検査」で止める。
  `HelpBuildService` は絶対パスを一度も見ない（未検査のパスへ書く経路が型で存在しない）。

---

## S6: 検査（一覧）

| # | ファイル | 種別 | 固定する不変条件 |
|---|---|---|---|
| T1 | `tests/Architecture/HelpGeneratorRegistryTest.php` | Architecture | I10（台帳と manifest の完全一致・**generator key の重複拒否**・免除の口が無い・母集団非空） |
| T2 | `tests/Architecture/McpToolReferencePopulationTest.php` | Architecture | I3（基底の差し替え）+ 3 集合の完全一致 + 走査根の生存 + 母集団非空（床値・代表名は pin しない） |
| T3 | `tests/Unit/Architecture/McpToolScannerTest.php` | Unit | 走査器の自己検査（正例 + 負例 6 種。fail-closed。**Reflection の実体一致**を含む） |
| T4 | `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php` | Unit | I2・I14（生成・正規化・決定性・**形が変われば止まる**負例 8 種） |
| T5 | `tests/Feature/Help/HelpRepositoryTest.php` | Feature | I1・I11・I12（読み取り／**書き込み**の閉じるパス検査。字句 6 種 + manifest 8 種 + `schema_version` 3 種 + 実体 5 種 + **root 外書き込み拒否**） |
| T6 | `tests/Feature/Help/HelpBuildCommandTest.php` | Feature | I6・I7・I8・I9・I13（振る舞い。一時 root。**`Throwable` 全般で終了コード 1**・**root 外を書き換えない**を含む） |
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
| `app/Services/Help/*`（新規 13 本） | **無い** | 無い | 共有ファイルでない |
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
| 判断根拠 | 新規ファイルが **24 本**（`app/Services/Help/` 13 / `app/Console/Commands/Help/` 1 / `docs/` 3 / `tests/` 7）と多く、`app/Services/Help/` という新しい名前空間を丸ごと立てる。既存コードへの変更は `app/Providers/AppServiceProvider.php` の `register()` に binding 2 本を足すだけで、他施策と重なる面がほぼ無い。テストファースト（T3→S4→T4→…）の順序を保ったまま一気通貫で進めるほうが、途中の中間状態（走査器だけ在って生成器が無い等）を main に置かずに済む |
| 競合リスク | **低**。変更する既存ファイルは `AppServiceProvider.php` の 1 本のみ（`register()` の末尾に追加）。他の TODO が同じメソッドを触ると軽い衝突が起きうるが、追加行が独立しているため解消は容易。`app/Mcp/` は**読むだけで変更しない**ので MCP 系 TODO とは競合しない。`docs/TODO.md` / `docs/template-divergence.md` / `LedgerPins.php` は触らない |


## 実装差分 (git diff)

```diff
diff --git a/app/Console/Commands/Help/HelpBuildCommand.php b/app/Console/Commands/Help/HelpBuildCommand.php
new file mode 100644
index 00000000..70f50615
--- /dev/null
+++ b/app/Console/Commands/Help/HelpBuildCommand.php
@@ -0,0 +1,73 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Help;
+
+use App\Services\Help\HelpArtifactState;
+use App\Services\Help\HelpBuildReport;
+use App\Services\Help\HelpBuildService;
+use Illuminate\Console\Command;
+use Throwable;
+
+/**
+ * ヘルプ生成物の**生成と鮮度検査の唯一の入口**。
+ *
+ * ★**終了コードは 0 と 1 の 2 値だけ**。例外も 1 へ畳む。
+ *   捕捉は `Throwable` である — `RuntimeException` だけでは
+ *   `Webmozart\Assert` の `InvalidArgumentException`・container の
+ *   `BindingResolutionException`・`TypeError` 等の `Error` 系が素通りし、
+ *   0/1 以外の終わり方が生まれる。
+ * ★`--check` は**作業ツリーを 1 バイトも変えない**。
+ * ★手書きページが 0 件でも成功する (ヘルプ本文の未整備を赤字扱いしない)。
+ */
+final class HelpBuildCommand extends Command
+{
+    /** @var string */
+    protected $signature = 'help:build {--check : 生成せず鮮度だけを検査する (作業ツリーを変更しない)}';
+
+    /** @var string */
+    protected $description = 'docs/help/ の生成物を組み立てる (--check は生成せず鮮度だけを検査する)';
+
+    public function handle(HelpBuildService $service): int
+    {
+        $checkOnly = (bool) $this->option('check');
+
+        try {
+            $report = $checkOnly ? $service->check() : $service->build();
+        } catch (Throwable $e) {
+            $this->components->error($e->getMessage());
+
+            return self::FAILURE;
+        }
+
+        $this->render($report, $checkOnly);
+
+        return $report->isClean() ? self::SUCCESS : self::FAILURE;
+    }
+
+    private function render(HelpBuildReport $report, bool $checkOnly): void
+    {
+        foreach ($report->observations as $observation) {
+            $this->components->twoColumnDetail(
+                $observation->relativePath,
+                $observation->state->value,
+            );
+        }
+
+        if ($report->isClean()) {
+            $this->components->info($checkOnly ? 'ヘルプ生成物は鮮度が保たれている。' : 'ヘルプ生成物を組み立てた。');
+
+            return;
+        }
+
+        foreach ($report->problems() as $problem) {
+            $this->components->error(match ($problem->state) {
+                HelpArtifactState::Stale => "生成物が古い: {$problem->relativePath} — `php artisan help:build` を実行すること。",
+                HelpArtifactState::Missing => "生成物が無い: {$problem->relativePath} — `php artisan help:build` を実行すること。",
+                HelpArtifactState::Orphan => "manifest に無い生成物が残っている: {$problem->relativePath} — 削除するか manifest へ宣言すること。",
+                HelpArtifactState::UpToDate => '',
+            });
+        }
+    }
+}
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index c9ad56a5..274a3ae7 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -32,6 +32,8 @@
 use App\Services\Billing\TicketCheckoutGateway;
 use App\Services\Capture\FfmpegTakeThumbnailExtractor;
 use App\Services\Capture\TakeThumbnailExtractor;
+use App\Services\Help\HelpRepository;
+use App\Services\Help\McpToolScanner;
 use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
 use App\Services\Mail\Sns\SnsSignatureVerifier;
 use App\Services\Render\FfmpegVideoComposer;
@@ -140,6 +142,23 @@ public function register(): void
         // (ChannelManager::createDatabaseDriver は container 解決のため binding が効く。
         // AppNotification 以外の通知は素通し = 後方互換)
         $this->app->bind(DatabaseChannel::class, OrganizationScopedDatabaseChannel::class);
+
+        // ヘルプ機構 (T246) の 2 つの根。運用者が触る値ではないので CLI の knob には出さない
+        // (出すと「別の場所を検査させて緑にする」経路ができる)。テストは container の
+        // rebind で差し替える。
+        $this->app->singleton(HelpRepository::class, static function (): HelpRepository {
+            $root = base_path('docs/help');
+            Assert::stringNotEmpty($root);
+
+            return new HelpRepository($root);
+        });
+
+        $this->app->singleton(McpToolScanner::class, static function (): McpToolScanner {
+            $root = base_path('app/Mcp/Tools');
+            Assert::stringNotEmpty($root);
+
+            return new McpToolScanner($root);
+        });
     }
 
     public function boot(): void
diff --git a/app/Services/Help/Generators/HelpGenerator.php b/app/Services/Help/Generators/HelpGenerator.php
new file mode 100644
index 00000000..c292ed70
--- /dev/null
+++ b/app/Services/Help/Generators/HelpGenerator.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help\Generators;
+
+/** ヘルプの節を実装から組み立てる生成器。 */
+interface HelpGenerator
+{
+    /** manifest の `generator` と突き合わせるキー。 */
+    public function key(): string;
+
+    /** 生成した Markdown 本文 (末尾は改行 1 個で終わること)。 */
+    public function generate(): string;
+}
diff --git a/app/Services/Help/Generators/McpToolReferenceGenerator.php b/app/Services/Help/Generators/McpToolReferenceGenerator.php
new file mode 100644
index 00000000..09c0ee63
--- /dev/null
+++ b/app/Services/Help/Generators/McpToolReferenceGenerator.php
@@ -0,0 +1,100 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help\Generators;
+
+use App\Mcp\Tools\AppMcpTool;
+use App\Services\Help\McpToolMetadata;
+use App\Services\Help\McpToolParameter;
+use App\Services\Help\McpToolScanner;
+use Illuminate\Contracts\Container\Container;
+use Webmozart\Assert\Assert;
+
+/**
+ * MCP ツール一覧の Markdown を実装から生成する (正典 AG-100 の還流対象 (2))。
+ *
+ * ★出力は**決定的**である (ツールは name 昇順、パラメータは schema の宣言順、
+ *   日時・環境変数のような可変要素を一切含めない)。同じ実装からは同じバイト列が出る。
+ * ★**保証しないもの**: 説明文の質は見ない。サーバに登録されているかも見ない
+ *   (走査集合と登録集合の一致は `McpToolReferencePopulationTest` の担当)。
+ */
+final class McpToolReferenceGenerator implements HelpGenerator
+{
+    public function __construct(
+        private readonly McpToolScanner $scanner,
+        private readonly Container $container,
+    ) {}
+
+    public function key(): string
+    {
+        return 'mcp-tools';
+    }
+
+    public function generate(): string
+    {
+        $metadata = [];
+
+        foreach ($this->scanner->concreteToolClasses() as $class) {
+            /** @var mixed $tool */
+            $tool = $this->container->make($class);
+            Assert::isInstanceOf($tool, AppMcpTool::class);
+
+            $metadata[] = McpToolMetadata::fromTool($tool, $class);
+        }
+
+        usort($metadata, static fn (McpToolMetadata $a, McpToolMetadata $b): int => strcmp($a->name, $b->name));
+
+        $lines = [
+            '<!-- 自動生成: `php artisan help:build` が生成する。手で編集しない。 -->',
+            '<!-- 生成器: mcp-tools ('.self::class.') -->',
+            '',
+            '# MCP ツールリファレンス',
+            '',
+            '本アプリが MCP サーバー (`App\Mcp\Servers\AppMcpServer`) 経由で公開しているツールの一覧である。',
+            '実装 (`app/Mcp/Tools/`) から自動生成しているので、手書きの説明が実装からずれることはない。',
+            '',
+            '現在のツール数: '.count($metadata),
+        ];
+
+        foreach ($metadata as $tool) {
+            $lines[] = '';
+            $lines[] = '## `'.$tool->name.'`';
+            $lines[] = '';
+            $lines[] = self::escapeCell($tool->description);
+
+            if ($tool->parameters === []) {
+                $lines[] = '';
+                $lines[] = 'パラメータなし。';
+
+                continue;
+            }
+
+            $lines[] = '';
+            $lines[] = '| パラメータ | 型 | 必須 | 説明 |';
+            $lines[] = '|---|---|---|---|';
+            foreach ($tool->parameters as $parameter) {
+                $lines[] = self::parameterRow($parameter);
+            }
+        }
+
+        return implode("\n", $lines)."\n";
+    }
+
+    private static function parameterRow(McpToolParameter $parameter): string
+    {
+        return sprintf(
+            '| `%s` | %s | %s | %s |',
+            $parameter->name,
+            self::escapeCell($parameter->type),
+            $parameter->required ? '必須' : '任意',
+            self::escapeCell($parameter->description),
+        );
+    }
+
+    /** 表のセルを壊す縦棒と改行を無害化する (`docs/template-divergence.md` と同じ方針)。 */
+    private static function escapeCell(string $value): string
+    {
+        return str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], $value);
+    }
+}
diff --git a/app/Services/Help/HelpArtifactObservation.php b/app/Services/Help/HelpArtifactObservation.php
new file mode 100644
index 00000000..2690eab8
--- /dev/null
+++ b/app/Services/Help/HelpArtifactObservation.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+/** 生成物 1 件の観測結果 (相対パスと状態の対)。 */
+final readonly class HelpArtifactObservation
+{
+    /** @param non-empty-string $relativePath */
+    public function __construct(
+        public string $relativePath,
+        public HelpArtifactState $state,
+    ) {}
+}
diff --git a/app/Services/Help/HelpArtifactState.php b/app/Services/Help/HelpArtifactState.php
new file mode 100644
index 00000000..0e8b37fe
--- /dev/null
+++ b/app/Services/Help/HelpArtifactState.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+/**
+ * 生成物 1 件の状態。**この 4 値がすべて**である。
+ *
+ * ★`Orphan` は「manifest に無いのに生成物ディレクトリに居る」であり、
+ *   「違反 0 件」に畳まずに独立の種別として残す (消滅と検査不能を混同しない)。
+ */
+enum HelpArtifactState: string
+{
+    case UpToDate = 'up_to_date';
+    case Stale = 'stale';
+    case Missing = 'missing';
+    case Orphan = 'orphan';
+}
diff --git a/app/Services/Help/HelpBuildReport.php b/app/Services/Help/HelpBuildReport.php
new file mode 100644
index 00000000..e39eb192
--- /dev/null
+++ b/app/Services/Help/HelpBuildReport.php
@@ -0,0 +1,26 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+/** 1 回の生成 / 検査の観測結果一式。 */
+final readonly class HelpBuildReport
+{
+    /** @param list<HelpArtifactObservation> $observations */
+    public function __construct(public array $observations) {}
+
+    public function isClean(): bool
+    {
+        return $this->problems() === [];
+    }
+
+    /** @return list<HelpArtifactObservation> */
+    public function problems(): array
+    {
+        return array_values(array_filter(
+            $this->observations,
+            static fn (HelpArtifactObservation $o): bool => $o->state !== HelpArtifactState::UpToDate,
+        ));
+    }
+}
diff --git a/app/Services/Help/HelpBuildService.php b/app/Services/Help/HelpBuildService.php
new file mode 100644
index 00000000..8bd74c18
--- /dev/null
+++ b/app/Services/Help/HelpBuildService.php
@@ -0,0 +1,109 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+use App\Services\Help\Generators\HelpGenerator;
+
+/**
+ * 生成と鮮度検査の判定を閉じ込める層 (コマンドは薄い引数解析層にする)。
+ *
+ * ★**`check()` は作業ツリーを 1 バイトも変えない**。書き込みは `build()` にしかない。
+ * ★**手書きページは判定の母集団に入れない** (0 件でも緑)。
+ *   見るのは「manifest が宣言した生成 entry」と「生成物ディレクトリ直下の実体」の 2 集合だけ。
+ * ★**絶対パスをこの層で組み立てない**。読み書きの実体検査ごと `HelpRepository` に閉じる。
+ * ★**保証しないもの**: 孤児を**削除しない** (人が消す)。生成器が出す本文の正しさは見ない。
+ */
+final class HelpBuildService
+{
+    public function __construct(
+        private readonly HelpRepository $repository,
+        private readonly HelpGeneratorRegistry $registry,
+    ) {}
+
+    /** 比較だけ行う (書き込みなし)。 */
+    public function check(): HelpBuildReport
+    {
+        return $this->observe();
+    }
+
+    /** 生成物を書いてから、同じ規準でもう一度観測して返す。 */
+    public function build(): HelpBuildReport
+    {
+        $this->registry->verifyRegistryIsFullyReferenced($this->repository);
+
+        $generators = $this->registry->all();
+
+        foreach ($this->repository->sections() as $section) {
+            if ($section->generatorKey === null) {
+                continue;
+            }
+
+            $this->repository->writeGenerated(
+                $section,
+                $this->generatorFor($generators, $section->generatorKey)->generate(),
+            );
+        }
+
+        return $this->observe();
+    }
+
+    private function observe(): HelpBuildReport
+    {
+        $this->registry->verifyRegistryIsFullyReferenced($this->repository);
+
+        $generators = $this->registry->all();
+        $observations = [];
+        $declared = [];
+
+        foreach ($this->repository->sections() as $section) {
+            if ($section->generatorKey === null) {
+                continue; // 手書きページは鮮度検査の母集団外
+            }
+
+            $declared[$section->path] = true;
+            $current = $this->repository->read($section);
+
+            $state = match (true) {
+                $current === null => HelpArtifactState::Missing,
+                $current === $this->generatorFor($generators, $section->generatorKey)->generate() => HelpArtifactState::UpToDate,
+                default => HelpArtifactState::Stale,
+            };
+
+            $observations[] = new HelpArtifactObservation($section->path, $state);
+        }
+
+        foreach ($this->repository->generatedArtifactPaths() as $path) {
+            if (! isset($declared[$path])) {
+                $observations[] = new HelpArtifactObservation($path, HelpArtifactState::Orphan);
+            }
+        }
+
+        return new HelpBuildReport($observations);
+    }
+
+    /**
+     * 台帳から生成器を取り出す。
+     *
+     * ★`verifyRegistryIsFullyReferenced()` が先に完全一致を強制しているので不在は起こらないが、
+     *   **不在を暗黙に許す添字参照は書かない** (将来 verify を外したときに静かに壊れる)。
+     *
+     * @param  array<non-empty-string, HelpGenerator>  $generators
+     * @param  non-empty-string  $key
+     *
+     * @throws HelpManifestException
+     */
+    private function generatorFor(array $generators, string $key): HelpGenerator
+    {
+        $generator = $generators[$key] ?? null;
+
+        if ($generator === null) {
+            throw new HelpManifestException(
+                "manifest が宣言した生成器が台帳に在りません: {$key} — HelpGeneratorRegistry::GENERATORS へ足すこと。",
+            );
+        }
+
+        return $generator;
+    }
+}
diff --git a/app/Services/Help/HelpGeneratorRegistry.php b/app/Services/Help/HelpGeneratorRegistry.php
new file mode 100644
index 00000000..9a4508ca
--- /dev/null
+++ b/app/Services/Help/HelpGeneratorRegistry.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+use App\Services\Help\Generators\HelpGenerator;
+use App\Services\Help\Generators\McpToolReferenceGenerator;
+use Illuminate\Contracts\Container\Container;
+use Webmozart\Assert\Assert;
+
+/**
+ * 生成器の**全数申告**。
+ *
+ * ★**許可一覧も除外の口も持たない**。この定数配列に載っているものが生成器のすべてであり、
+ *   「台帳に載っていない生成器」は検査の対象から外れる = 存在できない (deny-by-default)。
+ *   個別の生成器・個別の節を名指しして検査を免除する仕組みは本機構のどこにも無い。
+ * ★**走査対象**: 本定数と `HelpRepository::sections()` が返す `generator` キーの 2 集合だけ。
+ * ★**保証しないもの**: 生成器が出す本文の正しさは見ない (それは各生成器の単体検査の担当)。
+ */
+final class HelpGeneratorRegistry
+{
+    /**
+     * 生成器のキー → 実装クラス。
+     *
+     * @var array<non-empty-string, class-string<HelpGenerator>>
+     */
+    public const array GENERATORS = [
+        'mcp-tools' => McpToolReferenceGenerator::class,
+    ];
+
+    public function __construct(private readonly Container $container) {}
+
+    /**
+     * @return array<non-empty-string, HelpGenerator>
+     */
+    public function all(): array
+    {
+        $resolved = [];
+
+        foreach (self::GENERATORS as $key => $class) {
+            /** @var mixed $generator */
+            $generator = $this->container->make($class);
+            Assert::isInstanceOf($generator, HelpGenerator::class);
+            Assert::same($generator->key(), $key, "生成器の key() が台帳のキーと一致しません: {$key}");
+
+            $resolved[$key] = $generator;
+        }
+
+        return $resolved;
+    }
+
+    /**
+     * 台帳と manifest の生成 entry が**完全一致**することを強制する (両方向)。
+     *
+     * @throws HelpManifestException
+     */
+    public function verifyRegistryIsFullyReferenced(HelpRepository $repository): void
+    {
+        $declared = [];
+        foreach ($repository->sections() as $section) {
+            if ($section->generatorKey !== null) {
+                $declared[$section->generatorKey] = true;
+            }
+        }
+
+        $missingInManifest = array_keys(array_diff_key(self::GENERATORS, $declared));
+        $missingInRegistry = array_keys(array_diff_key($declared, self::GENERATORS));
+
+        if ($missingInManifest !== []) {
+            throw new HelpManifestException(
+                '台帳に在る生成器が manifest に宣言されていません: '.implode(', ', $missingInManifest).
+                ' — docs/help/manifest.json へ節を足すこと。',
+            );
+        }
+        if ($missingInRegistry !== []) {
+            throw new HelpManifestException(
+                'manifest が宣言した生成器が台帳に在りません: '.implode(', ', $missingInRegistry).
+                ' — HelpGeneratorRegistry::GENERATORS へ足すこと。',
+            );
+        }
+    }
+}
diff --git a/app/Services/Help/HelpManifestException.php b/app/Services/Help/HelpManifestException.php
new file mode 100644
index 00000000..e696da65
--- /dev/null
+++ b/app/Services/Help/HelpManifestException.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+use RuntimeException;
+
+/**
+ * manifest / 置き場の規約に反する形を表す。
+ *
+ * ★**沈黙して空を返さないための型**である。規約違反を「節が 0 件」に畳むと、
+ *   鮮度検査が母集団 0 件のまま緑になってしまう。
+ */
+final class HelpManifestException extends RuntimeException {}
diff --git a/app/Services/Help/HelpRepository.php b/app/Services/Help/HelpRepository.php
new file mode 100644
index 00000000..1a87c85e
--- /dev/null
+++ b/app/Services/Help/HelpRepository.php
@@ -0,0 +1,411 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+use JsonException;
+
+/**
+ * ヘルプの置き場 (`docs/help/`) の読み取り層。
+ *
+ * ★**閉じる側に倒れる**。パスを組み立てるたびに字句の検査 (`assertRelativePath`) と
+ *   実体の検査 (`resolveRealDirectory` / `readResolved`) を**やり直す**。
+ *   片方だけを通した結果を使い回さない。
+ * ★**未検査の絶対パスを外へ出さない**。読み書きの両方を本クラスに閉じ込める
+ *   (`read()` / `writeGenerated()`)。呼び出し側が絶対パスを組み立てる口は持たない
+ *   — 持たせると「字句だけ通したパスへ書く」経路が必ず生まれる。
+ * ★**走査対象**: `manifest.json` が宣言した節と、`_generated/` 直下の `*.md` だけ。
+ * ★**保証しないもの**:
+ *   - 本文の内容 (Markdown の妥当性・網羅性) は一切見ない。
+ *   - `pages/` 配下の未宣言ファイルは孤児として扱わない
+ *     (手書きの下書きを赤にしないため。孤児検査の対象は生成物ディレクトリだけである)。
+ *   - 生成物ディレクトリの**階層は許さない**。下位ディレクトリを見つけたら例外で止まる
+ *     (再帰走査を持たないので「見えない場所に孤児が居る」を作らない)。
+ *   - 実体の検査は POSIX 前提である (`is_link()` / `realpath()`)。開発・CI は Linux で、
+ *     Windows は対象外。
+ */
+final class HelpRepository
+{
+    /** 生成物のディレクトリ名 (直下のみ)。 */
+    public const string GENERATED_DIR = '_generated';
+
+    /** 手書きページのディレクトリ名 (直下のみ)。0 件でよい。 */
+    public const string PAGES_DIR = 'pages';
+
+    private const string MANIFEST_FILE = 'manifest.json';
+
+    /** 読める manifest の schema 版 (厳密一致。未知の版は読まずに落とす)。 */
+    private const int SCHEMA_VERSION = 1;
+
+    /** @param non-empty-string $root `docs/help/` の絶対パス */
+    public function __construct(private readonly string $root) {}
+
+    /**
+     * manifest が宣言した節 (宣言順)。
+     *
+     * @return list<HelpSection>
+     *
+     * @throws HelpManifestException
+     */
+    public function sections(): array
+    {
+        $manifest = $this->readManifest();
+
+        $sections = [];
+        $seenSlugs = [];
+        $seenPaths = [];
+        $seenGenerators = [];
+
+        foreach ($manifest as $index => $entry) {
+            if (! is_array($entry)) {
+                throw new HelpManifestException("manifest の sections[{$index}] が object ではありません。");
+            }
+
+            $slug = $this->requireNonEmptyString($entry, 'slug', $index);
+            $title = $this->requireNonEmptyString($entry, 'title', $index);
+            $path = $this->requireNonEmptyString($entry, 'path', $index);
+
+            $generatorKey = null;
+            if (array_key_exists('generator', $entry)) {
+                $generatorKey = $this->requireNonEmptyString($entry, 'generator', $index);
+            }
+
+            $this->assertRelativePath($path, $generatorKey !== null, $index);
+
+            if (isset($seenSlugs[$slug])) {
+                throw new HelpManifestException("manifest の slug が重複しています: {$slug}");
+            }
+            if (isset($seenPaths[$path])) {
+                throw new HelpManifestException("manifest の path が重複しています: {$path}");
+            }
+            // ★generator は 1 節につき 1 本 (完全一致を集合一致へ弱めない)。
+            //   `HelpGenerator::generate()` は節を引数に取らないので、
+            //   同じ生成器を 2 節が参照する形は「同じ内容を 2 か所へ書く」意味しか持たない。
+            if ($generatorKey !== null && isset($seenGenerators[$generatorKey])) {
+                throw new HelpManifestException(
+                    "manifest の generator が重複しています: {$generatorKey} — ".
+                    '1 つの生成器を参照できる節は 1 つだけである。',
+                );
+            }
+            $seenSlugs[$slug] = true;
+            $seenPaths[$path] = true;
+            if ($generatorKey !== null) {
+                $seenGenerators[$generatorKey] = true;
+            }
+
+            $sections[] = new HelpSection($slug, $title, $path, $generatorKey);
+        }
+
+        return $sections;
+    }
+
+    /**
+     * 節の本文。存在しなければ null (**不在は例外にしない** — Missing として報告するため)。
+     *
+     * @throws HelpManifestException 実体が置き場の外・regular file でない・symlink のとき
+     */
+    public function read(HelpSection $section): ?string
+    {
+        $this->assertRelativePath($section->path, $section->isGenerated(), null);
+
+        $absolute = $this->root.'/'.$section->path;
+
+        if (! file_exists($absolute) && ! is_link($absolute)) {
+            return null;
+        }
+
+        return $this->readResolved($absolute, $section->path);
+    }
+
+    /**
+     * 生成物ディレクトリ直下の `*.md` の相対パス (昇順)。孤児検査の母集団である。
+     *
+     * @return list<non-empty-string>
+     *
+     * @throws HelpManifestException
+     */
+    public function generatedArtifactPaths(): array
+    {
+        $rootReal = $this->resolveRealDirectory($this->root, 'ヘルプの置き場');
+        $dir = $rootReal.'/'.self::GENERATED_DIR;
+
+        if (is_link($dir)) {
+            throw new HelpManifestException(
+                "生成物ディレクトリに symlink は使えません: {$dir} — 実ディレクトリに置き換えること。",
+            );
+        }
+        if (! file_exists($dir)) {
+            return [];
+        }
+
+        $dirReal = $this->resolveRealDirectory($dir, '生成物ディレクトリ');
+        if ($dirReal !== $dir) {
+            throw new HelpManifestException("生成物ディレクトリが置き場の外を指しています: {$dir}");
+        }
+
+        $entries = scandir($dirReal);
+        if ($entries === false) {
+            throw new HelpManifestException("生成物ディレクトリを走査できません: {$dirReal}");
+        }
+
+        $paths = [];
+        foreach ($entries as $entry) {
+            if ($entry === '.' || $entry === '..') {
+                continue;
+            }
+
+            $absolute = $dirReal.'/'.$entry;
+
+            // ★symlink / FIFO / socket / ディレクトリを「通常の生成物候補」に混ぜない。
+            //   `.md` で終わる symlink を Orphan として静かに返すと、
+            //   「通常ファイルでない実体は例外」という規約が字句だけの飾りになる。
+            if (is_link($absolute)) {
+                throw new HelpManifestException(
+                    "生成物ディレクトリに symlink があります: {$absolute} — 削除すること。",
+                );
+            }
+            if (is_dir($absolute)) {
+                throw new HelpManifestException(
+                    "生成物ディレクトリは階層を許しません: {$absolute} — ".
+                    'ディレクトリを削除し、生成物は '.self::GENERATED_DIR.'/ 直下に置くこと。',
+                );
+            }
+            if (! is_file($absolute)) {
+                throw new HelpManifestException(
+                    "生成物ディレクトリに通常ファイルでない実体があります: {$absolute} — 削除すること。",
+                );
+            }
+            if (! str_ends_with($entry, '.md')) {
+                throw new HelpManifestException(
+                    "生成物ディレクトリに Markdown 以外の実体があります: {$absolute} — 削除すること。",
+                );
+            }
+
+            $relative = self::GENERATED_DIR.'/'.$entry;
+            $this->assertRelativePath($relative, true, null);
+            $paths[] = $relative;
+        }
+
+        sort($paths, SORT_STRING);
+
+        return $paths;
+    }
+
+    /**
+     * 生成物を書き込む。**書き込み経路の実体検査も本クラスに閉じ込める**。
+     *
+     * ★字句検査だけを通した絶対パスを呼び出し側へ渡さない (`absolutePathFor()` は持たない)。
+     *   渡すと「`_generated` が外部ディレクトリへの symlink」で置き場の外へ書けてしまう。
+     * ★ディレクトリ作成は**非再帰**である (階層を作れない)。
+     * ★書き込みの**後**にもう一度実体を検査する (作成の途中で入れ替えられた形を残さない)。
+     *
+     * @throws HelpManifestException
+     */
+    public function writeGenerated(HelpSection $section, string $contents): void
+    {
+        if (! $section->isGenerated()) {
+            throw new HelpManifestException("手書きページを生成物として書き込めません: {$section->path}");
+        }
+
+        $this->assertRelativePath($section->path, true, null);
+
+        $rootReal = $this->resolveRealDirectory($this->root, 'ヘルプの置き場');
+        $dir = $rootReal.'/'.self::GENERATED_DIR;
+
+        if (is_link($dir)) {
+            throw new HelpManifestException(
+                "生成物ディレクトリに symlink は使えません: {$dir} — 実ディレクトリに置き換えること。",
+            );
+        }
+        if (! is_dir($dir) && ! mkdir($dir, 0o755) && ! is_dir($dir)) {
+            throw new HelpManifestException("生成物ディレクトリを作成できません: {$dir}");
+        }
+
+        $dirReal = $this->resolveRealDirectory($dir, '生成物ディレクトリ');
+        if ($dirReal !== $dir) {
+            throw new HelpManifestException("生成物ディレクトリが置き場の外を指しています: {$dir}");
+        }
+
+        $absolute = $dirReal.'/'.basename($section->path);
+
+        if (is_link($absolute)) {
+            throw new HelpManifestException("生成物に symlink は使えません: {$section->path}");
+        }
+        if (file_exists($absolute) && ! is_file($absolute)) {
+            throw new HelpManifestException("生成物の実体が通常ファイルではありません: {$section->path}");
+        }
+
+        if (file_put_contents($absolute, $contents) === false) {
+            throw new HelpManifestException("生成物を書き込めません: {$section->path}");
+        }
+
+        // 書き込み後の再検査 (字句 → 実体 → 書き込み → 実体、の 4 段で閉じる)
+        $this->assertWrittenFileIsRegular($absolute, $section->path);
+    }
+
+    /**
+     * 書き込んだ実体を**もう一度**検査する。
+     *
+     * ★`clearstatcache()` を先に呼ぶ。PHP は stat の結果をプロセス内で覚えるので、
+     *   書き込み前の観測を使い回すと「書いた後の姿」を見たことにならない。
+     *
+     * @throws HelpManifestException
+     */
+    private function assertWrittenFileIsRegular(string $absolute, string $relative): void
+    {
+        clearstatcache(true, $absolute);
+
+        if (is_link($absolute) || ! is_file($absolute)) {
+            throw new HelpManifestException("書き込んだ生成物が通常ファイルではありません: {$relative}");
+        }
+    }
+
+    /**
+     * ディレクトリの実体を解決する (symlink を辿った後の絶対パス)。
+     *
+     * @return non-empty-string
+     *
+     * @throws HelpManifestException
+     */
+    private function resolveRealDirectory(string $path, string $label): string
+    {
+        $real = realpath($path);
+
+        if ($real === false || ! is_dir($real)) {
+            throw new HelpManifestException("{$label}をディレクトリとして解決できません: {$path}");
+        }
+
+        return $real;
+    }
+
+    /**
+     * 字句の検査。ディレクトリは 2 つだけ・直下のみ・`.md` のみ・`.`/`..` を含まない。
+     *
+     * @throws HelpManifestException
+     */
+    private function assertRelativePath(string $path, bool $generated, ?int $index): void
+    {
+        $where = $index === null ? '' : " (sections[{$index}])";
+
+        $expectedDir = $generated ? self::GENERATED_DIR : self::PAGES_DIR;
+        $pattern = '#^'.preg_quote($expectedDir, '#').'/[A-Za-z0-9][A-Za-z0-9._-]*\.md$#';
+
+        if (preg_match($pattern, $path) !== 1) {
+            throw new HelpManifestException(
+                "path が規約に合いません{$where}: {$path} — ".
+                "期待する形は `{$expectedDir}/<name>.md` (直下のみ・階層不可) である。",
+            );
+        }
+
+        foreach (explode('/', $path) as $segment) {
+            if ($segment === '.' || $segment === '..') {
+                throw new HelpManifestException("path に相対指定を含められません{$where}: {$path}");
+            }
+        }
+    }
+
+    /**
+     * 実体の検査。symlink 不可・regular file のみ・realpath が置き場の内側にあること。
+     *
+     * @throws HelpManifestException
+     */
+    private function readResolved(string $absolute, string $relative): string
+    {
+        if (is_link($absolute)) {
+            throw new HelpManifestException("ヘルプの実体に symlink は使えません: {$relative}");
+        }
+
+        $real = realpath($absolute);
+        $rootReal = realpath($this->root);
+
+        if ($real === false || $rootReal === false) {
+            throw new HelpManifestException("ヘルプの実体を解決できません: {$relative}");
+        }
+        if (! is_file($real)) {
+            throw new HelpManifestException("ヘルプの実体が通常ファイルではありません: {$relative}");
+        }
+        if (! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR)) {
+            throw new HelpManifestException("ヘルプの実体が置き場の外を指しています: {$relative}");
+        }
+
+        $contents = file_get_contents($real);
+        if ($contents === false) {
+            throw new HelpManifestException("ヘルプの実体を読めません: {$relative}");
+        }
+
+        return $contents;
+    }
+
+    /**
+     * @return list<mixed>
+     *
+     * @throws HelpManifestException
+     */
+    private function readManifest(): array
+    {
+        $absolute = $this->root.'/'.self::MANIFEST_FILE;
+
+        if (is_link($absolute) || ! is_file($absolute)) {
+            throw new HelpManifestException("manifest が通常ファイルとして存在しません: {$absolute}");
+        }
+
+        $raw = file_get_contents($absolute);
+        if ($raw === false) {
+            throw new HelpManifestException("manifest を読めません: {$absolute}");
+        }
+
+        try {
+            /** @var mixed $decoded */
+            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
+        } catch (JsonException $e) {
+            throw new HelpManifestException("manifest の JSON が壊れています: {$e->getMessage()}", 0, $e);
+        }
+
+        if (! is_array($decoded) || array_is_list($decoded)) {
+            throw new HelpManifestException('manifest の最上位が object ではありません。');
+        }
+
+        // ★宣言しておいて読まない値を作らない (fail-open の温床)。
+        //   厳密比較なので文字列 "1" も未知の 2 も弾く。schema を変えるときは
+        //   このコードを同じ変更で直すことになる = 旧コードが新 schema を誤読しない。
+        /** @var mixed $schemaVersion */
+        $schemaVersion = $decoded['schema_version'] ?? null;
+        if ($schemaVersion !== self::SCHEMA_VERSION) {
+            throw new HelpManifestException(
+                'manifest の schema_version が '.self::SCHEMA_VERSION.' ではありません — '.
+                'このコードが読めるのは schema_version '.self::SCHEMA_VERSION.' だけである。',
+            );
+        }
+
+        if (! array_key_exists('sections', $decoded)) {
+            throw new HelpManifestException('manifest に sections がありません。');
+        }
+
+        /** @var mixed $sections */
+        $sections = $decoded['sections'];
+        if (! is_array($sections) || ! array_is_list($sections)) {
+            throw new HelpManifestException('manifest の sections が配列 (list) ではありません。');
+        }
+
+        return $sections;
+    }
+
+    /**
+     * @param  array<array-key, mixed>  $entry
+     * @return non-empty-string
+     *
+     * @throws HelpManifestException
+     */
+    private function requireNonEmptyString(array $entry, string $key, int $index): string
+    {
+        /** @var mixed $value */
+        $value = $entry[$key] ?? null;
+
+        if (! is_string($value) || $value === '') {
+            throw new HelpManifestException("manifest の sections[{$index}].{$key} が非空の文字列ではありません。");
+        }
+
+        return $value;
+    }
+}
diff --git a/app/Services/Help/HelpSection.php b/app/Services/Help/HelpSection.php
new file mode 100644
index 00000000..0e2ba0d4
--- /dev/null
+++ b/app/Services/Help/HelpSection.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+/**
+ * ヘルプの 1 節 (manifest の 1 エントリ)。
+ *
+ * ★`generatorKey` が null なら手書きページ、非 null なら生成物である。
+ *   「生成物かどうか」の判定はこの 1 か所だけが持つ (呼び出し側でパスの前綴りを見ない)。
+ */
+final readonly class HelpSection
+{
+    /**
+     * @param  non-empty-string  $slug
+     * @param  non-empty-string  $title
+     * @param  non-empty-string  $path  `docs/help/` からの相対パス
+     * @param  non-empty-string|null  $generatorKey
+     */
+    public function __construct(
+        public string $slug,
+        public string $title,
+        public string $path,
+        public ?string $generatorKey,
+    ) {}
+
+    public function isGenerated(): bool
+    {
+        return $this->generatorKey !== null;
+    }
+}
diff --git a/app/Services/Help/McpToolMetadata.php b/app/Services/Help/McpToolMetadata.php
new file mode 100644
index 00000000..ec59a76e
--- /dev/null
+++ b/app/Services/Help/McpToolMetadata.php
@@ -0,0 +1,218 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+use App\Mcp\Tools\AppMcpTool;
+use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
+use RuntimeException;
+
+/**
+ * MCP ツール 1 本のメタデータ。**vendor の実行時出力を first-party の型へ閉じ込める境界**である。
+ *
+ * ★**正規化する。閉じた集合で弾かない** (正典の設計判断) — vendor の実行時出力は
+ *   first-party の型保証の外にあり、閉集合で弾くと正当なツール定義が生成を止めるためである。
+ * ★**静かに欠けるより止まる**。想定外の形は握り潰さず例外にし、
+ *   例外には**対象クラス / 不正だった箇所 / 直し方**を必ず含める。
+ * ★**組み立ての入口は 2 つ**である。`fromTool()` が実行時の経路、`fromSchema()` が
+ *   直列化済みの schema を受ける境界そのものである (後者を public にしてあるのは、
+ *   vendor が出しえない形も含めた負例を検査から与えられるようにするためで、
+ *   **前者は後者へ委譲する** = 検査した経路と実行時の経路が同一になる)。
+ * ★**保証しないもの**: 説明文・パラメータ説明の内容の妥当性は見ない (存在と型だけを見る)。
+ */
+final readonly class McpToolMetadata
+{
+    /**
+     * @param  class-string<AppMcpTool>  $className
+     * @param  non-empty-string  $name
+     * @param  list<McpToolParameter>  $parameters  schema の宣言順
+     */
+    public function __construct(
+        public string $className,
+        public string $name,
+        public string $description,
+        public array $parameters,
+    ) {}
+
+    /**
+     * @param  class-string<AppMcpTool>  $className
+     *
+     * @throws RuntimeException vendor のメタデータが想定外の形のとき
+     */
+    public static function fromTool(AppMcpTool $tool, string $className): self
+    {
+        $name = $tool->name();
+        if ($name === '') {
+            throw new RuntimeException("{$className}: name() が空文字です — ToolName enum の値を返すこと。");
+        }
+
+        /** @var array<string, mixed> $schema */
+        $schema = JsonSchemaFactory::object($tool->schema(...))->toArray();
+
+        return self::fromSchema($schema, $className, $name, $tool->description());
+    }
+
+    /**
+     * 直列化済みの JSON Schema からメタデータを組み立てる (正規化の境界)。
+     *
+     * @param  array<string, mixed>  $schema
+     * @param  class-string<AppMcpTool>  $className
+     * @param  non-empty-string  $name
+     *
+     * @throws RuntimeException
+     */
+    public static function fromSchema(array $schema, string $className, string $name, string $description): self
+    {
+        return new self(
+            className: $className,
+            name: $name,
+            description: $description,
+            parameters: self::parametersFrom($schema, $className),
+        );
+    }
+
+    /**
+     * @param  array<string, mixed>  $schema
+     * @return list<McpToolParameter>
+     *
+     * @throws RuntimeException
+     */
+    private static function parametersFrom(array $schema, string $className): array
+    {
+        $hint = 'vendor (laravel/mcp / illuminate/json-schema) の出力形が変わった可能性がある。'.
+            'McpToolMetadata の正規化を新しい形に合わせて直すこと。';
+
+        // vendor 実読: properties は 0 件のとき、required は必須 0 件のとき、いずれもキーごと消える。
+        $hasProperties = array_key_exists('properties', $schema);
+        $hasRequired = array_key_exists('required', $schema);
+
+        // ★「required はあるが properties が無い」は vendor では起きえない形である。
+        //   これを 0 件として黙って受けると、必須パラメータが**静かに欠ける**。
+        if ($hasRequired && ! $hasProperties) {
+            throw new RuntimeException(
+                "{$className}: schema に required があるのに properties がありません — {$hint}",
+            );
+        }
+
+        /** @var mixed $properties */
+        $properties = $hasProperties ? $schema['properties'] : [];
+        if (! is_array($properties)) {
+            throw new RuntimeException("{$className}: schema の properties が配列ではありません — {$hint}");
+        }
+
+        $required = [];
+        /** @var mixed $rawRequired */
+        $rawRequired = $hasRequired ? $schema['required'] : [];
+        if (! is_array($rawRequired) || ! array_is_list($rawRequired)) {
+            throw new RuntimeException("{$className}: schema の required が list ではありません — {$hint}");
+        }
+        /** @var mixed $key */
+        foreach ($rawRequired as $key) {
+            if (! is_string($key) || $key === '') {
+                throw new RuntimeException(
+                    "{$className}: schema の required に非空の文字列でない要素があります — {$hint}",
+                );
+            }
+            if (isset($required[$key])) {
+                throw new RuntimeException(
+                    "{$className}: schema の required に重複があります: {$key} — {$hint}",
+                );
+            }
+            if (! array_key_exists($key, $properties)) {
+                throw new RuntimeException(
+                    "{$className}: schema の required `{$key}` が properties にありません — {$hint}",
+                );
+            }
+            $required[$key] = true;
+        }
+
+        $parameters = [];
+        /** @var mixed $definition */
+        foreach ($properties as $name => $definition) {
+            if (! is_string($name) || $name === '') {
+                throw new RuntimeException(
+                    "{$className}: schema の properties にパラメータ名が非空の文字列でない要素があります — {$hint}",
+                );
+            }
+            if (! is_array($definition)) {
+                throw new RuntimeException(
+                    "{$className}: schema のパラメータ `{$name}` の定義が配列ではありません — {$hint}",
+                );
+            }
+
+            $parameters[] = new McpToolParameter(
+                name: $name,
+                type: self::normalizeType($definition['type'] ?? null, $name, $className),
+                required: isset($required[$name]),
+                description: self::normalizeDescription($definition['description'] ?? null, $name, $className),
+            );
+        }
+
+        return $parameters;
+    }
+
+    /**
+     * 型を**表示用の文字列へ正規化する** (閉じた集合で弾かない)。
+     *
+     * @return non-empty-string
+     *
+     * @throws RuntimeException 文字列でも文字列の配列でもないとき
+     */
+    private static function normalizeType(mixed $type, string $name, string $className): string
+    {
+        if (is_string($type) && $type !== '') {
+            return $type;
+        }
+
+        if (is_array($type)) {
+            // ★union / nullable は **list** で来る (vendor 実読)。
+            //   associative を受けてキーを捨てると、形の変化が静かに通る。
+            if (! array_is_list($type) || $type === []) {
+                throw new RuntimeException(
+                    "{$className}: パラメータ `{$name}` の type が非空の list ではありません — ".
+                    'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
+                );
+            }
+
+            $parts = [];
+            /** @var mixed $part */
+            foreach ($type as $part) {
+                if (! is_string($part) || $part === '') {
+                    throw new RuntimeException(
+                        "{$className}: パラメータ `{$name}` の type に非空の文字列でない要素があります — ".
+                        'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
+                    );
+                }
+                $parts[] = $part;
+            }
+
+            return implode('|', $parts);
+        }
+
+        if ($type === null) {
+            return '(未宣言)';
+        }
+
+        throw new RuntimeException(
+            "{$className}: パラメータ `{$name}` の type が文字列でも文字列の配列でもありません — ".
+            'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
+        );
+    }
+
+    /** @throws RuntimeException */
+    private static function normalizeDescription(mixed $description, string $name, string $className): string
+    {
+        if ($description === null) {
+            return '';
+        }
+        if (is_string($description)) {
+            return $description;
+        }
+
+        throw new RuntimeException(
+            "{$className}: パラメータ `{$name}` の description が文字列ではありません — ".
+            'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeDescription を直すこと。',
+        );
+    }
+}
diff --git a/app/Services/Help/McpToolParameter.php b/app/Services/Help/McpToolParameter.php
new file mode 100644
index 00000000..eb80bf84
--- /dev/null
+++ b/app/Services/Help/McpToolParameter.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+/**
+ * ツール 1 本のパラメータ。**表示用に正規化済み**の値だけを持つ。
+ *
+ * ★`type` は vendor が返した型 (文字列 or 文字列の配列) を `|` で連結した表示用文字列である。
+ *   閉じた集合で弾かない (正典が名指しした設計判断)。
+ */
+final readonly class McpToolParameter
+{
+    /**
+     * @param  non-empty-string  $name
+     * @param  non-empty-string  $type
+     */
+    public function __construct(
+        public string $name,
+        public string $type,
+        public bool $required,
+        public string $description,
+    ) {}
+}
diff --git a/app/Services/Help/McpToolScanner.php b/app/Services/Help/McpToolScanner.php
new file mode 100644
index 00000000..816abde6
--- /dev/null
+++ b/app/Services/Help/McpToolScanner.php
@@ -0,0 +1,122 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Help;
+
+use App\Mcp\Tools\AppMcpTool;
+use ReflectionClass;
+use RuntimeException;
+
+/**
+ * `app/Mcp/Tools/` を走査して MCP ツールの具象クラスを列挙する。
+ *
+ * ★**走査根は 1 本** (`app/Mcp/Tools/` の直下)。git 追跡下 PHP 全数より狭いので
+ *   `Tests\Support\TrackedPhpSourceFiles` は共用しない。**存在しない根は fail-fast** で落とす。
+ * ★**基底クラスは `App\Mcp\Tools\AppMcpTool`** — 正典 (裁定 AG-100) が
+ *   「移植時に各リポジトリの基底クラスへ差し替える 1 行」と名指しした箇所である。
+ * ★**deny-by-default**: 走査根の直下に置いた具象クラスは、基底を継承していなければ**例外で止まる**。
+ *   「走査対象から外す」口を持たない (外したければファイルを別の場所へ移すしかない)。
+ * ★**母集団の非空は本走査器の契約である**。0 件は「違反 0 件」ではなく走査の破損なので例外にする。
+ * ★**保証しないもの**:
+ *   - 下位ディレクトリは見ない (`app/Mcp/Tools/` は現に平坦であり、階層を作る予定も無い)。
+ *   - 1 ファイルに複数のクラスを書いた場合、ファイル名と同名のクラスしか見ない。
+ *   - サーバへの登録有無は見ない。走査集合と登録集合の一致は
+ *     `tests/Architecture/McpToolReferencePopulationTest.php` の担当である。
+ *   - git 未追跡 (add 前) のファイルも走査する (gate の境界は commit / CI なので実効差は無い)。
+ *   - 実体の検査は POSIX 前提である (`is_link()` / `realpath()`)。
+ */
+final class McpToolScanner
+{
+    private const string NAMESPACE_PREFIX = 'App\\Mcp\\Tools\\';
+
+    /** @param non-empty-string $root `app/Mcp/Tools/` の絶対パス */
+    public function __construct(private readonly string $root) {}
+
+    /**
+     * @return list<class-string<AppMcpTool>> クラス名の昇順
+     *
+     * @throws RuntimeException 走査根が無い / クラスを解決できない / 基底を継承しない / 母集団が空
+     */
+    public function concreteToolClasses(): array
+    {
+        if (! is_dir($this->root)) {
+            throw new RuntimeException(
+                "MCP ツールの走査根が存在しません: {$this->root} — ".
+                'ディレクトリを移動・改名したなら McpToolScanner の配線を同じ変更で直すこと。',
+            );
+        }
+
+        $entries = scandir($this->root);
+        if ($entries === false) {
+            throw new RuntimeException("MCP ツールの走査根を走査できません: {$this->root}");
+        }
+
+        $classes = [];
+
+        foreach ($entries as $entry) {
+            if (! str_ends_with($entry, '.php')) {
+                continue;
+            }
+
+            $absolute = $this->root.'/'.$entry;
+            if (! is_file($absolute) || is_link($absolute)) {
+                throw new RuntimeException("MCP ツールの実体が通常ファイルではありません: {$absolute}");
+            }
+
+            $class = self::NAMESPACE_PREFIX.substr($entry, 0, -4);
+
+            if (! class_exists($class)) {
+                throw new RuntimeException(
+                    "MCP ツールのクラスを解決できません: {$class} ({$absolute}) — ".
+                    'ファイル名とクラス名 / namespace が一致しているか確認すること。',
+                );
+            }
+
+            $reflection = new ReflectionClass($class);
+
+            // ★走査したファイルと、autoload が解決したクラスの実体が同じであることを要求する。
+            //   `class_exists()` は Composer autoload から**別のファイル**をロードしうるので、
+            //   これを見ないと「一時 root の見本を走査しているつもりで本物を見ている」
+            //   状態に気付けず、負例が空振りする (検出力の主張が崩れる)。
+            $declaredIn = $reflection->getFileName();
+            $declaredReal = $declaredIn === false ? false : realpath($declaredIn);
+            $scannedReal = realpath($absolute);
+
+            if ($declaredReal === false || $scannedReal === false || $declaredReal !== $scannedReal) {
+                throw new RuntimeException(
+                    "{$class} の実体が走査中のファイルと一致しません ".
+                    '(走査: '.$absolute.' / 解決: '.var_export($declaredIn, true).') — '.
+                    'ファイル名とクラス名 / namespace が一致しているか、'.
+                    '同名クラスが別の場所から autoload されていないか確認すること。',
+                );
+            }
+
+            if ($reflection->isAbstract() || $reflection->isInterface()) {
+                continue;
+            }
+
+            if (! $reflection->isSubclassOf(AppMcpTool::class)) {
+                throw new RuntimeException(
+                    "{$class} は ".AppMcpTool::class.' を継承していません — '.
+                    'app/Mcp/Tools/ 直下には MCP ツールだけを置くこと '.
+                    '(補助クラスは別の namespace へ移すこと)。',
+                );
+            }
+
+            /** @var class-string<AppMcpTool> $class */
+            $classes[] = $class;
+        }
+
+        if ($classes === []) {
+            throw new RuntimeException(
+                "MCP ツールが 1 件も見つかりません: {$this->root} — ".
+                '母集団が空なのは「違反 0 件」ではなく走査の破損である。',
+            );
+        }
+
+        sort($classes, SORT_STRING);
+
+        return $classes;
+    }
+}
diff --git a/docs/help-system.md b/docs/help-system.md
new file mode 100644
index 00000000..564c6093
--- /dev/null
+++ b/docs/help-system.md
@@ -0,0 +1,83 @@
+# ヘルプ機構 (置き場と規約)
+
+`docs/help/` の置き場・宣言・生成物の運用契約の**正本**である。
+機構の実装は `app/Services/Help/` と `app/Console/Commands/Help/HelpBuildCommand.php`。
+
+## これは何か
+
+「ヘルプ本文を置く場所」と「実装から自動生成する節」を 1 つの宣言 (manifest) で扱い、
+**生成物が実装からずれたまま気付かれない形を作らない**ための機構である。
+
+- **取り込み基盤**: `HelpRepository` が `docs/help/` を読み書きする唯一の層。
+- **生成器の台帳**: `HelpGeneratorRegistry::GENERATORS` が生成器の全数申告。
+- **唯一の入口**: `php artisan help:build` (生成) / `php artisan help:build --check` (鮮度検査)。
+- **鮮度ゲート**: `tests/Feature/Help/HelpBuildFreshnessTest.php` が `composer test` (= CI) で
+  `--check` を走らせる。生成物が古いと**赤くなる**。
+
+## 置き場の規約
+
+- `docs/help/manifest.json` が**宣言の正本**である。ここに無い節は存在しない。
+- `schema_version` は `1` で**厳密一致**する (文字列 `"1"` も未知の `2` も読まずに落ちる)。
+- `path` の値域は `_generated/<name>.md` または `pages/<name>.md` の **2 通りだけ**。
+  `<name>` は `[A-Za-z0-9][A-Za-z0-9._-]*`。**どちらも直下のみで階層を許さない**
+  (階層を許すと孤児の検査に再帰走査が要る)。
+- `generator` キーを**持つ節が生成物**、持たない節が**手書きページ**である。
+  `generator` の値は `HelpGeneratorRegistry::GENERATORS` のキーと**完全一致**する
+  (両方向。片側にしか無ければ `help:build` も `--check` も止まる = deny-by-default)。
+  **1 つの生成器を参照できる節は 1 つだけ**である。
+- 生成物は `php artisan help:build` が書き、**手で編集しない**
+  (生成物の先頭にその旨のコメントが入る)。
+- **手書きページは 0 件でよい**。0 件でも `help:build --check` は成功する
+  (ヘルプ本文の未整備を赤字扱いしない)。
+- `docs/help/_generated/` 直下に manifest 未宣言の `.md` があれば **Orphan** として報告する。
+  **`help:build` は孤児を削除しない** — 人が消すか manifest へ宣言する。
+- 生成物ディレクトリに symlink・ディレクトリ・`.md` 以外・通常ファイルでない実体があれば
+  **例外で止まる** (字句の規約を実体でも守る)。
+
+## 報告の種別と終了コード
+
+| 種別 | 意味 | 対処 |
+|---|---|---|
+| `up_to_date` | 生成物が実装と一致している | — |
+| `stale` | 生成物が古い | `php artisan help:build` を実行して差分をコミットする |
+| `missing` | 宣言された生成物が無い | `php artisan help:build` を実行する |
+| `orphan` | manifest に無い生成物が残っている | 削除するか manifest へ宣言する |
+
+**終了コードは 0 と 1 の 2 値だけ**である (例外も 1 へ畳む)。
+`up_to_date` 以外が 1 件でもあれば 1 になる。
+
+## 生成器を足すとき
+
+1. `App\Services\Help\Generators\HelpGenerator` を実装する (`key()` と `generate()`)。
+2. `HelpGeneratorRegistry::GENERATORS` へ 1 行足す。
+3. `docs/help/manifest.json` へ節を 1 つ足す (`generator` に同じキー)。
+4. `php artisan help:build` を実行し、生成物を**同じコミットに含める**。
+
+2 と 3 のどちらかを忘れると `help:build` 自体が止まる (意図した fail-closed である)。
+
+## 現在の生成器
+
+| キー | 実装 | 生成物 | 入力 |
+|---|---|---|---|
+| `mcp-tools` | `App\Services\Help\Generators\McpToolReferenceGenerator` | `docs/help/_generated/mcp-tools.md` | `app/Mcp/Tools/` の具象ツール (`McpToolScanner` が走査) |
+
+`McpToolScanner` の走査根は `app/Mcp/Tools/` **直下だけ**で、基底
+`App\Mcp\Tools\AppMcpTool` を継承しない具象クラスを見つけたら**例外で止まる**
+(補助クラスは別の namespace へ置くこと)。母集団が 0 件になることも
+「違反 0 件」ではなく走査の破損として例外にする。
+
+vendor (`laravel/mcp` / `illuminate/json-schema`) が返すメタデータの形は
+**閉じた集合で弾かずに表示用へ正規化する**が、想定外の形は**静かに欠けずに止まる**
+(例外に対象クラス・不正だった箇所・直し方が入る)。
+
+## 保証しないもの (誇張しない)
+
+- **表示面を持たない**。HTTP でヘルプを配る route も画面も無く、Markdown を HTML へ
+  変換もしない (変換先が無い)。
+- **ヘルプ本文の中身の品質・網羅性は検査しない**。機構が見るのは置き場の規約と
+  生成物の鮮度だけである。
+- **`pages/` 配下の未宣言ファイルは孤児として扱わない** (手書きの下書きを赤にしないため)。
+  孤児検査の母集団は生成物ディレクトリの直下だけである。
+- 実体の検査は **POSIX 前提** (`is_link()` / `realpath()`) である。Windows は対象外。
+- **保証しないものの網羅的な正本は各クラスの docblock** であり、本書はその要約である
+  (2 か所に同じ一覧を書くと必ず食い違う)。
diff --git a/docs/help/_generated/mcp-tools.md b/docs/help/_generated/mcp-tools.md
new file mode 100644
index 00000000..e7eeb53f
--- /dev/null
+++ b/docs/help/_generated/mcp-tools.md
@@ -0,0 +1,42 @@
+<!-- 自動生成: `php artisan help:build` が生成する。手で編集しない。 -->
+<!-- 生成器: mcp-tools (App\Services\Help\Generators\McpToolReferenceGenerator) -->
+
+# MCP ツールリファレンス
+
+本アプリが MCP サーバー (`App\Mcp\Servers\AppMcpServer`) 経由で公開しているツールの一覧である。
+実装 (`app/Mcp/Tools/`) から自動生成しているので、手書きの説明が実装からずれることはない。
+
+現在のツール数: 4
+
+## `list-items`
+
+List items of a project in the organization bound to the access token.
+
+| パラメータ | 型 | 必須 | 説明 |
+|---|---|---|---|
+| `project_id` | integer | 必須 | Project ID |
+| `page` | integer | 任意 | Page number (1..1000) |
+| `per_page` | integer | 任意 | Items per page (1..100) |
+
+## `list-projects`
+
+List projects in the organization bound to the access token.
+
+| パラメータ | 型 | 必須 | 説明 |
+|---|---|---|---|
+| `page` | integer | 任意 | Page number (1..1000) |
+| `per_page` | integer | 任意 | Items per page (1..100) |
+
+## `show-project`
+
+Show a project in the organization bound to the access token.
+
+| パラメータ | 型 | 必須 | 説明 |
+|---|---|---|---|
+| `project_id` | integer | 必須 | Project ID |
+
+## `whoami`
+
+Return the authenticated user and the organization bound to the access token.
+
+パラメータなし。
diff --git a/docs/help/manifest.json b/docs/help/manifest.json
new file mode 100644
index 00000000..4dee637b
--- /dev/null
+++ b/docs/help/manifest.json
@@ -0,0 +1,11 @@
+{
+    "schema_version": 1,
+    "sections": [
+        {
+            "slug": "mcp-tools",
+            "title": "MCP ツールリファレンス",
+            "path": "_generated/mcp-tools.md",
+            "generator": "mcp-tools"
+        }
+    ]
+}
diff --git a/tests/Architecture/HelpGeneratorRegistryTest.php b/tests/Architecture/HelpGeneratorRegistryTest.php
new file mode 100644
index 00000000..e8c17cd9
--- /dev/null
+++ b/tests/Architecture/HelpGeneratorRegistryTest.php
@@ -0,0 +1,100 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Help\Generators\HelpGenerator;
+use App\Services\Help\HelpGeneratorRegistry;
+use App\Services\Help\HelpManifestException;
+use App\Services\Help\HelpRepository;
+use Tests\Support\Help\HelpTestTree;
+
+/*
+ * 生成器の台帳 (I10) — 許可一覧も除外の口も持たない全数申告であり、
+ * manifest の生成 entry と **完全一致** することを両方向で強制する (deny-by-default)。
+ *
+ * 負例は合成した一時 manifest で作る (実 `docs/help/manifest.json` は書き換えない)。
+ */
+
+afterEach(function (): void {
+    HelpTestTree::cleanup();
+});
+
+/** 一時 manifest を持つ HelpRepository を組み立てる。 */
+function helpRegistryRepository(array $sections): HelpRepository
+{
+    $root = HelpTestTree::makeDir('help-registry');
+    HelpTestTree::writeManifest($root, $sections);
+
+    return new HelpRepository($root);
+}
+
+test('実リポジトリの manifest は台帳と完全一致する', function (): void {
+    $registry = app(HelpGeneratorRegistry::class);
+
+    $registry->verifyRegistryIsFullyReferenced(app(HelpRepository::class));
+})->throwsNoExceptions();
+
+test('台帳の母集団は非空である (0 件どうしの「一致」を成立させない)', function (): void {
+    expect(HelpGeneratorRegistry::GENERATORS)->not->toBeEmpty();
+});
+
+test('台帳に載せた生成器はすべて解決でき、key() が台帳のキーと一致する', function (): void {
+    $generators = app(HelpGeneratorRegistry::class)->all();
+
+    expect(array_keys($generators))->toBe(array_keys(HelpGeneratorRegistry::GENERATORS));
+
+    foreach ($generators as $key => $generator) {
+        expect($generator)->toBeInstanceOf(HelpGenerator::class)
+            ->and($generator->key())->toBe($key);
+    }
+});
+
+test('負例: 台帳に在る生成器が manifest に無ければ赤くなる', function (): void {
+    $repository = helpRegistryRepository([
+        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
+    ]);
+
+    expect(fn () => app(HelpGeneratorRegistry::class)->verifyRegistryIsFullyReferenced($repository))
+        ->toThrow(HelpManifestException::class, '台帳に在る生成器が manifest に宣言されていません');
+});
+
+test('負例: manifest が宣言した生成器が台帳に無ければ赤くなる', function (): void {
+    $sections = [];
+    foreach (array_keys(HelpGeneratorRegistry::GENERATORS) as $key) {
+        $sections[] = ['slug' => $key, 'title' => $key, 'path' => '_generated/'.$key.'.md', 'generator' => $key];
+    }
+    $sections[] = ['slug' => 'ghost', 'title' => 'ghost', 'path' => '_generated/ghost.md', 'generator' => 'ghost'];
+
+    $repository = helpRegistryRepository($sections);
+
+    expect(fn () => app(HelpGeneratorRegistry::class)->verifyRegistryIsFullyReferenced($repository))
+        ->toThrow(HelpManifestException::class, 'manifest が宣言した生成器が台帳に在りません');
+});
+
+test('負例: 同じ生成器を 2 つの節が参照したら赤くなる (集合一致へ弱まっていない)', function (): void {
+    $key = (string) array_key_first(HelpGeneratorRegistry::GENERATORS);
+
+    $repository = helpRegistryRepository([
+        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => $key],
+        ['slug' => 'b', 'title' => 'b', 'path' => '_generated/b.md', 'generator' => $key],
+    ]);
+
+    expect(fn () => app(HelpGeneratorRegistry::class)->verifyRegistryIsFullyReferenced($repository))
+        ->toThrow(HelpManifestException::class, 'generator が重複しています');
+});
+
+test('免除の受け皿が生えていないこと (public 定数は GENERATORS ちょうど 1 つ / static プロパティ 0 件)', function (): void {
+    $reflection = new ReflectionClass(HelpGeneratorRegistry::class);
+
+    $publicConstants = array_map(
+        static fn (ReflectionClassConstant $c): string => $c->getName(),
+        array_filter(
+            $reflection->getReflectionConstants(),
+            static fn (ReflectionClassConstant $c): bool => $c->isPublic(),
+        ),
+    );
+    sort($publicConstants);
+
+    expect($publicConstants)->toBe(['GENERATORS'])
+        ->and($reflection->getProperties(ReflectionProperty::IS_STATIC))->toBe([]);
+});
diff --git a/tests/Architecture/McpToolReferencePopulationTest.php b/tests/Architecture/McpToolReferencePopulationTest.php
new file mode 100644
index 00000000..c6029b3a
--- /dev/null
+++ b/tests/Architecture/McpToolReferencePopulationTest.php
@@ -0,0 +1,133 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Mcp\ToolName;
+use App\Mcp\Servers\AppMcpServer;
+use App\Mcp\Tools\AppMcpTool;
+use App\Services\Help\McpToolScanner;
+use Laravel\Mcp\Server\Tool;
+
+/*
+ * ヘルプの MCP ツール一覧が **実装の全数** から生成されていることを固定する。
+ *
+ * 見るのは 3 集合の完全一致である:
+ *   (1) 走査集合   = McpToolScanner が `app/Mcp/Tools/` から拾う具象ツール
+ *   (2) 登録集合   = AppMcpServer::$tools
+ *   (3) 語彙集合   = ToolName enum の case
+ *
+ * 既存の `tests/Feature/Mcp/ToolNameInvariantTest.php` は (2) と (3) の辺を見ている。
+ * 本テストが足すのは **(1) の辺** — 「ディレクトリに在るのに登録されていない /
+ * 登録されているのにディレクトリから拾えない」を検出する。
+ *
+ * ★基底クラスは `App\Mcp\Tools\AppMcpTool` である (正典 AG-100 が
+ *   「移植時に各リポジトリの基底クラスへ差し替える 1 行」と名指しした箇所 = I3)。
+ * ★**保証しないもの**: ツールの中身・説明文の質は見ない。走査器自身の限界は
+ *   `McpToolScanner` の docblock と `tests/Unit/Architecture/McpToolScannerTest.php` が正本。
+ */
+
+/**
+ * 3 集合の食い違いを列挙する (判定の実体。負例はこの関数へ合成入力を与えて裏取りする)。
+ *
+ * @param  list<string>  $scanned
+ * @param  list<string>  $registered
+ * @param  list<string>  $vocabulary
+ * @return list<string>
+ */
+function helpMcpPopulationProblems(array $scanned, array $registered, array $vocabulary): array
+{
+    $problems = [];
+
+    if ($scanned === [] || $registered === [] || $vocabulary === []) {
+        $problems[] = '母集団が空である (「違反 0 件」ではなく走査・登録の破損として扱う)';
+    }
+
+    sort($scanned);
+    sort($registered);
+    sort($vocabulary);
+
+    if ($scanned !== $registered) {
+        $problems[] = '走査集合と登録集合が食い違う: '
+            .implode(', ', array_merge(array_diff($scanned, $registered), array_diff($registered, $scanned)));
+    }
+
+    if ($registered !== $vocabulary) {
+        $problems[] = '登録集合と ToolName の語彙が食い違う: '
+            .implode(', ', array_merge(array_diff($registered, $vocabulary), array_diff($vocabulary, $registered)));
+    }
+
+    return $problems;
+}
+
+/**
+ * AppMcpServer に登録された tool class 名一覧。
+ *
+ * @return list<class-string<Tool>>
+ */
+function helpMcpRegisteredToolClasses(): array
+{
+    $reflection = new ReflectionClass(AppMcpServer::class);
+
+    /** @var list<class-string<Tool>> $tools */
+    $tools = $reflection->getProperty('tools')->getValue($reflection->newInstanceWithoutConstructor());
+
+    return $tools;
+}
+
+test('走査根 app/Mcp/Tools が実在する', function (): void {
+    expect(is_dir(base_path('app/Mcp/Tools')))->toBeTrue();
+});
+
+test('走査集合・サーバ登録集合・ToolName の語彙が完全一致する', function (): void {
+    $scanned = array_map(
+        static fn (string $class): string => app($class)->name(),
+        (new McpToolScanner(base_path('app/Mcp/Tools')))->concreteToolClasses(),
+    );
+
+    $registered = array_map(
+        static fn (string $class): string => app($class)->name(),
+        helpMcpRegisteredToolClasses(),
+    );
+
+    $vocabulary = array_map(static fn (ToolName $t): string => $t->value, ToolName::cases());
+
+    expect($scanned)->not->toBeEmpty();
+    expect(helpMcpPopulationProblems($scanned, $registered, $vocabulary))->toBe([]);
+});
+
+test('走査で拾ったクラスはすべて基底 AppMcpTool を継承する (基底の差し替えが効いている)', function (): void {
+    $classes = (new McpToolScanner(base_path('app/Mcp/Tools')))->concreteToolClasses();
+
+    expect($classes)->not->toBeEmpty();
+
+    foreach ($classes as $class) {
+        expect(is_subclass_of($class, AppMcpTool::class))->toBeTrue("{$class} は AppMcpTool を継承すること");
+    }
+});
+
+test('負例: 走査集合が登録集合より多いと問題として現れる', function (): void {
+    expect(helpMcpPopulationProblems(['a', 'b'], ['a'], ['a']))
+        ->toHaveCount(1)
+        ->and(helpMcpPopulationProblems(['a', 'b'], ['a'], ['a'])[0])
+        ->toContain('走査集合と登録集合が食い違う');
+});
+
+test('負例: 登録集合が走査集合より多いと問題として現れる', function (): void {
+    expect(helpMcpPopulationProblems(['a'], ['a', 'b'], ['a', 'b']))
+        ->toHaveCount(1);
+});
+
+test('負例: ToolName の語彙だけがずれても問題として現れる', function (): void {
+    expect(helpMcpPopulationProblems(['a'], ['a'], ['a', 'b']))
+        ->toHaveCount(1)
+        ->and(helpMcpPopulationProblems(['a'], ['a'], ['a', 'b'])[0])
+        ->toContain('ToolName の語彙が食い違う');
+});
+
+test('負例: 3 集合がすべて空でも「一致」にはならない', function (): void {
+    expect(helpMcpPopulationProblems([], [], []))->toHaveCount(1);
+});
+
+test('正例: 一致していれば問題は 0 件である (誤検出しない)', function (): void {
+    expect(helpMcpPopulationProblems(['b', 'a'], ['a', 'b'], ['a', 'b']))->toBe([]);
+});
diff --git a/tests/Feature/Help/HelpBuildCommandTest.php b/tests/Feature/Help/HelpBuildCommandTest.php
new file mode 100644
index 00000000..5e519ef5
--- /dev/null
+++ b/tests/Feature/Help/HelpBuildCommandTest.php
@@ -0,0 +1,226 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Help\Generators\McpToolReferenceGenerator;
+use App\Services\Help\HelpArtifactObservation;
+use App\Services\Help\HelpArtifactState;
+use App\Services\Help\HelpBuildService;
+use App\Services\Help\HelpRepository;
+use App\Services\Help\McpToolScanner;
+use Tests\Support\Help\HelpTestTree;
+
+/*
+ * `help:build` の振る舞い (I6 / I7 / I8 / I9 / I13)。
+ *
+ * ★書き込みを伴うので **必ず一時ディレクトリ** を置き場に差し替えて実行する
+ *   (`composer test` は --parallel。実 `docs/help/` を触ると別レーンと競合する)。
+ *   実リポジトリを読むのは `HelpBuildFreshnessTest` (読み取りのみ) の担当である。
+ */
+
+afterEach(function (): void {
+    HelpTestTree::cleanup();
+});
+
+/** 一時置き場を container へ差し込み、その絶対パスを返す。 */
+function helpCommandRoot(): string
+{
+    $root = HelpTestTree::makeDir('help-build');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'mcp-tools', 'title' => 'MCP ツールリファレンス', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
+    ]);
+
+    app()->instance(HelpRepository::class, new HelpRepository($root));
+
+    return $root;
+}
+
+test('生成 → --check が 0 で通る (唯一の入口が生成と検査の両方を持つ)', function (): void {
+    $root = helpCommandRoot();
+
+    $this->artisan('help:build')->assertExitCode(0);
+
+    expect(is_file($root.'/_generated/mcp-tools.md'))->toBeTrue();
+
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
+});
+
+test('--check は作業ツリーを 1 バイトも変えない (I6)', function (): void {
+    $root = helpCommandRoot();
+    $this->artisan('help:build')->assertExitCode(0);
+
+    $before = HelpTestTree::snapshot($root);
+
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
+
+    expect(HelpTestTree::snapshot($root))->toBe($before);
+});
+
+test('生成物が無ければ --check は Missing を報告して 1 で終わる (作業ツリーは変えない)', function (): void {
+    $root = helpCommandRoot();
+
+    $before = HelpTestTree::snapshot($root);
+
+    $this->artisan('help:build', ['--check' => true])
+        ->expectsOutputToContain('missing')
+        ->assertExitCode(1);
+
+    expect(HelpTestTree::snapshot($root))->toBe($before);
+});
+
+test('生成物が古ければ --check は Stale を報告して 1、再生成すれば 0 に戻る (対の動き)', function (): void {
+    $root = helpCommandRoot();
+    $this->artisan('help:build')->assertExitCode(0);
+
+    $artifact = $root.'/_generated/mcp-tools.md';
+    HelpTestTree::put($artifact, (string) file_get_contents($artifact)."手で足した 1 行\n");
+
+    $this->artisan('help:build', ['--check' => true])
+        ->expectsOutputToContain('stale')
+        ->assertExitCode(1);
+
+    $this->artisan('help:build')->assertExitCode(0);
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
+});
+
+test('manifest に無い生成物は Orphan として報告し、削除はしない (人が消す)', function (): void {
+    $root = helpCommandRoot();
+    $this->artisan('help:build')->assertExitCode(0);
+
+    HelpTestTree::put($root.'/_generated/ghost.md', "孤児\n");
+
+    $this->artisan('help:build', ['--check' => true])
+        ->expectsOutputToContain('orphan')
+        ->assertExitCode(1);
+
+    $this->artisan('help:build')->assertExitCode(1);
+
+    expect(is_file($root.'/_generated/ghost.md'))->toBeTrue()
+        ->and(file_get_contents($root.'/_generated/ghost.md'))->toBe("孤児\n");
+});
+
+test('報告の種別は up_to_date / stale / missing / orphan の 4 つである (I9)', function (): void {
+    $states = array_map(
+        static fn (HelpArtifactState $s): string => $s->value,
+        HelpArtifactState::cases(),
+    );
+    sort($states);
+
+    expect($states)->toBe(['missing', 'orphan', 'stale', 'up_to_date']);
+});
+
+test('観測は 4 種別を実際に区別する (up_to_date / stale / missing / orphan)', function (): void {
+    $root = helpCommandRoot();
+    $service = app(HelpBuildService::class);
+
+    // 生成前は Missing
+    expect(array_map(
+        static fn (HelpArtifactObservation $o): string => $o->state->value,
+        $service->check()->observations,
+    ))->toBe(['missing']);
+
+    // 生成直後は UpToDate
+    expect($service->build()->isClean())->toBeTrue();
+    expect($service->check()->observations[0]->state)
+        ->toBe(HelpArtifactState::UpToDate);
+
+    // 書き換えると Stale
+    $artifact = $root.'/_generated/mcp-tools.md';
+    HelpTestTree::put($artifact, "手で書いた\n");
+    expect($service->check()->observations[0]->state)
+        ->toBe(HelpArtifactState::Stale);
+
+    // manifest に無い生成物は Orphan (別の観測として現れる)
+    HelpTestTree::put($root.'/_generated/ghost.md', "孤児\n");
+    $observations = $service->check()->observations;
+
+    expect($observations)->toHaveCount(2)
+        ->and($observations[1]->relativePath)->toBe('_generated/ghost.md')
+        ->and($observations[1]->state)->toBe(HelpArtifactState::Orphan)
+        ->and($service->check()->isClean())->toBeFalse()
+        ->and($service->check()->problems())->toHaveCount(2);
+});
+
+test('手書きページが 0 件でも --check は 0 で通る (未整備を赤字扱いしない / I13)', function (): void {
+    $root = helpCommandRoot();
+    $this->artisan('help:build')->assertExitCode(0);
+
+    expect(is_dir($root.'/pages'))->toBeFalse();
+
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
+});
+
+test('手書きページを宣言しても本文が無いまま --check は 0 で通る', function (): void {
+    $root = HelpTestTree::makeDir('help-build-pages');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'mcp-tools', 'title' => 'MCP', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
+        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
+    ]);
+    app()->instance(HelpRepository::class, new HelpRepository($root));
+
+    $this->artisan('help:build')->assertExitCode(0);
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
+});
+
+test('manifest と台帳が食い違うと --check も生成も 1 で止まる (I10 の fail-closed)', function (): void {
+    $root = HelpTestTree::makeDir('help-build-mismatch');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
+    ]);
+    app()->instance(HelpRepository::class, new HelpRepository($root));
+
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(1);
+    $this->artisan('help:build')->assertExitCode(1);
+});
+
+test('HelpManifestException 以外の Throwable でも終了コードは 1 である (I8)', function (): void {
+    helpCommandRoot();
+
+    // 生成器の解決結果を誤った型へ差し替えると Webmozart\Assert が
+    // InvalidArgumentException を投げる (RuntimeException ではない)。
+    app()->instance(McpToolReferenceGenerator::class, new stdClass);
+
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(1);
+    $this->artisan('help:build')->assertExitCode(1);
+});
+
+test('走査根が壊れていても終了コードは 1 である (0/1 の 2 値だけ)', function (): void {
+    helpCommandRoot();
+    $empty = HelpTestTree::makeDir('help-build-empty-scan');
+    app()->instance(McpToolScanner::class, new McpToolScanner($empty));
+
+    $this->artisan('help:build')->assertExitCode(1);
+});
+
+test('生成物ディレクトリが置き場の外への symlink なら 1 で止まり、外部ファイルは変わらない', function (): void {
+    $root = helpCommandRoot();
+    $outside = HelpTestTree::makeDir('help-build-outside');
+    HelpTestTree::put($outside.'/mcp-tools.md', "外部の中身\n");
+    symlink($outside, $root.'/_generated');
+
+    $before = HelpTestTree::snapshot($outside);
+
+    $this->artisan('help:build')->assertExitCode(1);
+
+    expect(HelpTestTree::snapshot($outside))->toBe($before)
+        ->and(file_get_contents($outside.'/mcp-tools.md'))->toBe("外部の中身\n");
+});
+
+test('MCP ツールが 1 本増えると --check が Stale になる (生成物が実装へ追従する)', function (): void {
+    helpCommandRoot();
+
+    $scanRoot = HelpTestTree::makeDir('help-build-scan');
+    HelpTestTree::writeToolFixture($scanRoot, 'BuildFixtureFirstTool', 'Whoami');
+    app()->instance(McpToolScanner::class, new McpToolScanner($scanRoot));
+
+    $this->artisan('help:build')->assertExitCode(0);
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
+
+    HelpTestTree::writeToolFixture($scanRoot, 'BuildFixtureSecondTool', 'ListProjects');
+
+    $this->artisan('help:build', ['--check' => true])
+        ->expectsOutputToContain('stale')
+        ->assertExitCode(1);
+
+    $this->artisan('help:build')->assertExitCode(0);
+});
diff --git a/tests/Feature/Help/HelpBuildFreshnessTest.php b/tests/Feature/Help/HelpBuildFreshnessTest.php
new file mode 100644
index 00000000..46c4ad63
--- /dev/null
+++ b/tests/Feature/Help/HelpBuildFreshnessTest.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Help\HelpBuildService;
+use Tests\Support\Help\HelpTestTree;
+
+/*
+ * ヘルプ生成物の**鮮度ゲート本体** (I4 / I5)。
+ *
+ * 実リポジトリの `docs/help/` を **読み取りだけ** で検査する。生成物が実装から
+ * ずれたまま気付かれない形を作らないための検査であり、これが `composer test`
+ * (= CI) で赤くなることが正典 AG-100 の【必須条件】である。
+ *
+ * ★書き込みは一切しない (`--parallel` の他レーンと競合しないのはこのためである)。
+ *   書き込みを伴う振る舞いの検査は `HelpBuildCommandTest` が一時ディレクトリで行う。
+ * ★赤くなったら `php artisan help:build` を実行して差分をコミットすること。
+ */
+
+test('実リポジトリのヘルプ生成物は鮮度が保たれている (php artisan help:build --check が 0)', function (): void {
+    $before = HelpTestTree::snapshot(base_path('docs/help'));
+
+    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
+
+    // 検査モードが作業ツリーを 1 バイトも変えないことを実ツリーでも確かめる
+    expect(HelpTestTree::snapshot(base_path('docs/help')))->toBe($before);
+});
+
+test('実リポジトリの観測はすべて up_to_date である (問題 0 件)', function (): void {
+    $report = app(HelpBuildService::class)->check();
+
+    expect($report->observations)->not->toBeEmpty()
+        ->and($report->problems())->toBe([])
+        ->and($report->isClean())->toBeTrue();
+});
diff --git a/tests/Feature/Help/HelpRepositoryTest.php b/tests/Feature/Help/HelpRepositoryTest.php
new file mode 100644
index 00000000..7ea64cc6
--- /dev/null
+++ b/tests/Feature/Help/HelpRepositoryTest.php
@@ -0,0 +1,362 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Help\HelpManifestException;
+use App\Services\Help\HelpRepository;
+use App\Services\Help\HelpSection;
+use Tests\Support\Help\HelpTestTree;
+
+/*
+ * ヘルプの置き場 (`docs/help/`) の読み取り層。
+ *
+ * I1 (取り込み基盤) / I11 (直下のみ・階層不可) / I12 (閉じる側へ倒れる:
+ * パスを組み立てるたびに字句の検査と実体の検査をやり直す) を負例で裏取りする。
+ *
+ * 書き込みを伴うので **必ず一時ディレクトリ** を root にする (実 `docs/help/` は触らない)。
+ */
+
+afterEach(function (): void {
+    HelpTestTree::cleanup();
+});
+
+/** 生成物 1 件を宣言した既定の manifest を持つ一時置き場。 */
+function helpRepoRoot(string $prefix = 'help-repo'): string
+{
+    $root = HelpTestTree::makeDir($prefix);
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'mcp-tools', 'title' => 'MCP ツールリファレンス', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
+    ]);
+
+    return $root;
+}
+
+test('manifest が宣言した節を宣言順に読める (生成物と手書きの区別を含む)', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-sections');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'mcp-tools', 'title' => 'MCP ツール', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
+        ['slug' => 'getting-started', 'title' => 'はじめに', 'path' => 'pages/getting-started.md'],
+    ]);
+
+    $sections = (new HelpRepository($root))->sections();
+
+    expect($sections)->toHaveCount(2)
+        ->and($sections[0]->slug)->toBe('mcp-tools')
+        ->and($sections[0]->generatorKey)->toBe('mcp-tools')
+        ->and($sections[0]->isGenerated())->toBeTrue()
+        ->and($sections[1]->slug)->toBe('getting-started')
+        ->and($sections[1]->generatorKey)->toBeNull()
+        ->and($sections[1]->isGenerated())->toBeFalse();
+});
+
+test('手書きページが 0 件の manifest も正常に読める (未整備を赤字にしない)', function (): void {
+    expect((new HelpRepository(helpRepoRoot()))->sections())->toHaveCount(1);
+});
+
+test('本文が無い節は例外ではなく null を返す (不在と検査不能を混同しない)', function (): void {
+    $root = helpRepoRoot();
+    $repository = new HelpRepository($root);
+
+    expect($repository->read($repository->sections()[0]))->toBeNull();
+});
+
+test('本文が在れば読める', function (): void {
+    $root = helpRepoRoot();
+    HelpTestTree::put($root.'/_generated/mcp-tools.md', "# 見本\n");
+
+    $repository = new HelpRepository($root);
+
+    expect($repository->read($repository->sections()[0]))->toBe("# 見本\n");
+});
+
+test('生成物ディレクトリが無ければ孤児の母集団は空である', function (): void {
+    expect((new HelpRepository(helpRepoRoot()))->generatedArtifactPaths())->toBe([]);
+});
+
+test('生成物ディレクトリ直下の Markdown だけを昇順で列挙する', function (): void {
+    $root = helpRepoRoot();
+    HelpTestTree::put($root.'/_generated/zebra.md', "z\n");
+    HelpTestTree::put($root.'/_generated/alpha.md', "a\n");
+    HelpTestTree::put($root.'/pages/draft.md', "下書き\n");
+
+    expect((new HelpRepository($root))->generatedArtifactPaths())
+        ->toBe(['_generated/alpha.md', '_generated/zebra.md']);
+});
+
+test('書き込みは生成物として宣言された節にしか行えない', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-write-page');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
+    ]);
+
+    $repository = new HelpRepository($root);
+    $section = $repository->sections()[0];
+
+    expect(fn () => $repository->writeGenerated($section, 'x'))
+        ->toThrow(HelpManifestException::class, '手書きページを生成物として書き込めません');
+});
+
+test('書き込みは生成物ディレクトリを非再帰に作り、読み戻せる', function (): void {
+    $root = helpRepoRoot();
+    $repository = new HelpRepository($root);
+    $section = $repository->sections()[0];
+
+    $repository->writeGenerated($section, "生成物\n");
+
+    expect($repository->read($section))->toBe("生成物\n")
+        ->and(is_dir($root.'/_generated'))->toBeTrue();
+});
+
+/*
+ * -------- 字句の負例 (I12 / I11) --------
+ */
+
+dataset('規約に反する path', [
+    '相対指定を含む' => ['_generated/../../etc/passwd.md'],
+    '絶対パス' => ['/etc/passwd.md'],
+    '許されないディレクトリ' => ['secrets/leak.md'],
+    '階層化した生成物' => ['_generated/sub/x.md'],
+    'Markdown でない' => ['_generated/x.txt'],
+    '名前が英数字以外で始まる' => ['_generated/-x.md'],
+]);
+
+test('path が規約に反する manifest は読めない', function (string $path): void {
+    $root = HelpTestTree::makeDir('help-repo-path');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'x', 'title' => 'x', 'path' => $path, 'generator' => 'mcp-tools'],
+    ]);
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class);
+})->with('規約に反する path');
+
+test('生成物の節が pages/ を指していたら読めない (generator の有無で期待するディレクトリが決まる)', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-dir-mismatch');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'x', 'title' => 'x', 'path' => 'pages/x.md', 'generator' => 'mcp-tools'],
+    ]);
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, '_generated/<name>.md');
+});
+
+/*
+ * -------- manifest の負例 --------
+ */
+
+test('manifest が無ければ例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-no-manifest');
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, '通常ファイルとして存在しません');
+});
+
+test('manifest の JSON が壊れていたら例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-broken-json');
+    HelpTestTree::writeRawManifest($root, '{ broken');
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, 'JSON が壊れています');
+});
+
+test('manifest の最上位が object でなければ例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-top-list');
+    HelpTestTree::writeRawManifest($root, '[]');
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, '最上位が object ではありません');
+});
+
+test('sections が list でなければ例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-sections-map');
+    HelpTestTree::writeRawManifest($root, '{"schema_version":1,"sections":{"a":1}}');
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, 'sections が配列 (list) ではありません');
+});
+
+test('sections が無ければ例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-no-sections');
+    HelpTestTree::writeRawManifest($root, '{"schema_version":1}');
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, 'sections がありません');
+});
+
+test('節が object でなければ例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-entry-scalar');
+    HelpTestTree::writeRawManifest($root, '{"schema_version":1,"sections":["x"]}');
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, 'object ではありません');
+});
+
+test('slug が重複したら例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-dup-slug');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => 'mcp-tools'],
+        ['slug' => 'a', 'title' => 'b', 'path' => 'pages/b.md'],
+    ]);
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, 'slug が重複しています');
+});
+
+test('path が重複したら例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-dup-path');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'a', 'title' => 'a', 'path' => 'pages/a.md'],
+        ['slug' => 'b', 'title' => 'b', 'path' => 'pages/a.md'],
+    ]);
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, 'path が重複しています');
+});
+
+test('同じ generator を 2 つの節が参照したら例外で止まる (完全一致を集合一致へ弱めない)', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-dup-generator');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => 'mcp-tools'],
+        ['slug' => 'b', 'title' => 'b', 'path' => '_generated/b.md', 'generator' => 'mcp-tools'],
+    ]);
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, 'generator が重複しています');
+});
+
+test('generator が空文字なら例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-empty-generator');
+    HelpTestTree::writeManifest($root, [
+        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => ''],
+    ]);
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, '非空の文字列ではありません');
+});
+
+dataset('読めない schema_version', [
+    '欠落' => ['{"sections":[]}'],
+    '型違いの文字列' => ['{"schema_version":"1","sections":[]}'],
+    '未知の版' => ['{"schema_version":2,"sections":[]}'],
+]);
+
+test('読める schema_version 以外は読まずに落ちる', function (string $raw): void {
+    $root = HelpTestTree::makeDir('help-repo-schema');
+    HelpTestTree::writeRawManifest($root, $raw);
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, 'schema_version');
+})->with('読めない schema_version');
+
+/*
+ * -------- 実体の負例 (字句だけの飾りにしない) --------
+ */
+
+test('manifest が symlink なら例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('help-repo-manifest-link');
+    $outside = HelpTestTree::makeDir('help-repo-manifest-outside');
+    HelpTestTree::put($outside.'/manifest.json', '{"schema_version":1,"sections":[]}');
+    symlink($outside.'/manifest.json', $root.'/manifest.json');
+
+    expect(fn (): array => (new HelpRepository($root))->sections())
+        ->toThrow(HelpManifestException::class, '通常ファイルとして存在しません');
+});
+
+test('本文が symlink なら例外で止まる', function (): void {
+    $root = helpRepoRoot();
+    $outside = HelpTestTree::makeDir('help-repo-body-outside');
+    HelpTestTree::put($outside.'/leak.md', "外\n");
+    mkdir($root.'/_generated', 0o755);
+    symlink($outside.'/leak.md', $root.'/_generated/mcp-tools.md');
+
+    $repository = new HelpRepository($root);
+
+    expect(fn (): ?string => $repository->read($repository->sections()[0]))
+        ->toThrow(HelpManifestException::class, 'symlink は使えません');
+});
+
+test('生成物ディレクトリ自体が symlink なら例外で止まる', function (): void {
+    $root = helpRepoRoot();
+    $outside = HelpTestTree::makeDir('help-repo-dir-outside');
+    symlink($outside, $root.'/_generated');
+
+    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
+        ->toThrow(HelpManifestException::class, 'symlink は使えません');
+});
+
+test('生成物ディレクトリ直下に階層があれば例外で止まる (再帰走査を持たない)', function (): void {
+    $root = helpRepoRoot();
+    HelpTestTree::put($root.'/_generated/sub/x.md', "x\n");
+
+    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
+        ->toThrow(HelpManifestException::class, '階層を許しません');
+});
+
+test('生成物ディレクトリ直下の symlink は Orphan に畳まず例外で止まる', function (): void {
+    $root = helpRepoRoot();
+    HelpTestTree::put($root.'/_generated/real.md', "r\n");
+    symlink($root.'/_generated/real.md', $root.'/_generated/linked.md');
+
+    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
+        ->toThrow(HelpManifestException::class, 'symlink があります');
+});
+
+test('生成物ディレクトリ直下の Markdown 以外は例外で止まる', function (): void {
+    $root = helpRepoRoot();
+    HelpTestTree::put($root.'/_generated/notes.txt', "t\n");
+
+    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
+        ->toThrow(HelpManifestException::class, 'Markdown 以外の実体があります');
+});
+
+test('生成物ディレクトリ直下の通常ファイルでない実体は例外で止まる', function (): void {
+    $root = helpRepoRoot();
+    mkdir($root.'/_generated', 0o755);
+    posix_mkfifo($root.'/_generated/pipe.md', 0o644);
+
+    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
+        ->toThrow(HelpManifestException::class, '通常ファイルでない実体があります');
+});
+
+/*
+ * -------- 書き込み経路が置き場の外へ出ないこと --------
+ */
+
+test('生成物ディレクトリが外部への symlink なら書き込みは止まり、外部ファイルは 1 バイトも変わらない', function (): void {
+    $root = helpRepoRoot();
+    $outside = HelpTestTree::makeDir('help-repo-write-outside');
+    HelpTestTree::put($outside.'/mcp-tools.md', "外部の中身\n");
+    symlink($outside, $root.'/_generated');
+
+    $before = HelpTestTree::snapshot($outside);
+
+    $repository = new HelpRepository($root);
+    $section = $repository->sections()[0];
+
+    expect(fn () => $repository->writeGenerated($section, '侵入'))
+        ->toThrow(HelpManifestException::class, 'symlink は使えません');
+
+    expect(HelpTestTree::snapshot($outside))->toBe($before)
+        ->and(file_get_contents($outside.'/mcp-tools.md'))->toBe("外部の中身\n");
+});
+
+test('生成物の実体が symlink なら書き込みは止まる', function (): void {
+    $root = helpRepoRoot();
+    $outside = HelpTestTree::makeDir('help-repo-file-outside');
+    HelpTestTree::put($outside.'/target.md', "外部\n");
+    mkdir($root.'/_generated', 0o755);
+    symlink($outside.'/target.md', $root.'/_generated/mcp-tools.md');
+
+    $repository = new HelpRepository($root);
+    $section = $repository->sections()[0];
+
+    expect(fn () => $repository->writeGenerated($section, '侵入'))
+        ->toThrow(HelpManifestException::class, '生成物に symlink は使えません');
+
+    expect(file_get_contents($outside.'/target.md'))->toBe("外部\n");
+});
+
+test('HelpSection は generatorKey の有無だけで生成物かどうかを決める', function (): void {
+    expect((new HelpSection('a', 'A', '_generated/a.md', 'k'))->isGenerated())->toBeTrue()
+        ->and((new HelpSection('a', 'A', 'pages/a.md', null))->isGenerated())->toBeFalse();
+});
diff --git a/tests/Support/Help/HelpTestTree.php b/tests/Support/Help/HelpTestTree.php
new file mode 100644
index 00000000..60248d18
--- /dev/null
+++ b/tests/Support/Help/HelpTestTree.php
@@ -0,0 +1,246 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Help;
+
+use RuntimeException;
+
+/**
+ * ヘルプ機構の検査が使う一時ツリーの組み立て・撤去。
+ *
+ * ★**実リポジトリの `docs/help/` を書き換えるテストを書かないための道具**である。
+ *   書き込みを伴う検査は必ず本クラスが作る一時ディレクトリを root にする
+ *   (`composer test` は `--parallel` なので、実ツリーを触ると別レーンと競合する)。
+ * ★作ったディレクトリはプロセス内に覚えておき、`cleanup()` で一括撤去する。
+ */
+final class HelpTestTree
+{
+    /** 本プロセスで作った一時ディレクトリ (cleanup の対象)。 */
+    /** @var list<string> */
+    private static array $created = [];
+
+    /** インスタンス化しない (道具の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 一意な一時ディレクトリを作って絶対パスを返す。
+     */
+    public static function makeDir(string $prefix): string
+    {
+        $base = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($base, 0o755, true) && ! is_dir($base)) {
+            throw new RuntimeException("一時ディレクトリを作成できません: {$base}");
+        }
+
+        $real = realpath($base);
+        if ($real === false) {
+            throw new RuntimeException("一時ディレクトリを解決できません: {$base}");
+        }
+
+        self::$created[] = $real;
+
+        return $real;
+    }
+
+    /**
+     * manifest を書く。`$sections` は連想配列の list をそのまま JSON にする。
+     *
+     * @param  list<array<string, mixed>>  $sections
+     */
+    public static function writeManifest(string $root, array $sections, mixed $schemaVersion = 1): void
+    {
+        $payload = ['schema_version' => $schemaVersion, 'sections' => $sections];
+
+        self::put($root.'/manifest.json', (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
+    }
+
+    /** 生の manifest 文字列を書く (JSON 破損などの負例用)。 */
+    public static function writeRawManifest(string $root, string $contents): void
+    {
+        self::put($root.'/manifest.json', $contents);
+    }
+
+    /** ファイルを書く (中間ディレクトリは作る)。 */
+    public static function put(string $path, string $contents): void
+    {
+        $dir = dirname($path);
+        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
+            throw new RuntimeException("ディレクトリを作成できません: {$dir}");
+        }
+
+        if (file_put_contents($path, $contents) === false) {
+            throw new RuntimeException("ファイルを書けません: {$path}");
+        }
+    }
+
+    /**
+     * ツリー全体の (相対パス → 内容の sha256) 写像。ディレクトリと symlink も種別として記録する。
+     *
+     * ★`--check` が 1 バイトも書かないことを見るために使う。
+     *
+     * @return array<string, string>
+     */
+    public static function snapshot(string $dir): array
+    {
+        $result = [];
+        self::walk($dir, $dir, $result);
+        ksort($result);
+
+        return $result;
+    }
+
+    /**
+     * MCP ツールの見本を一時走査根へ書き、その場で読み込む。
+     *
+     * ★`App\Mcp\Tools\` の名前空間で宣言し**明示的に読み込む**ので、
+     *   `ReflectionClass::getFileName()` は一時走査根を指す
+     *   (走査器の「実体の一致」検査が空振りしない)。
+     *
+     * @param  non-empty-string  $class  クラス名 (名前空間なし)。プロセス内で一意にすること
+     * @param  non-empty-string  $case  `App\Enums\Mcp\ToolName` の case 名
+     * @param  string  $schemaBody  `schema()` の本体 (return 文を含む PHP)
+     * @return string 書いたファイルの絶対パス
+     */
+    public static function writeToolFixture(
+        string $root,
+        string $class,
+        string $case,
+        string $description = 'fixture tool',
+        string $schemaBody = 'return [];',
+    ): string {
+        $path = $root.'/'.$class.'.php';
+
+        self::put($path, self::toolFixtureSource($class, $case, $description, $schemaBody));
+
+        require_once $path;
+
+        return $path;
+    }
+
+    /** 見本ツールの PHP ソース (読み込まずに書くだけの負例でも使う)。 */
+    public static function toolFixtureSource(
+        string $class,
+        string $case,
+        string $description = 'fixture tool',
+        string $schemaBody = 'return [];',
+    ): string {
+        $escapedDescription = var_export($description, true);
+
+        return <<<PHP
+<?php
+
+declare(strict_types=1);
+
+namespace App\\Mcp\\Tools;
+
+use App\\Enums\\Mcp\\ToolName;
+use App\\Services\\Mcp\\Auth\\McpAuthorizationContext;
+use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
+use Laravel\\Mcp\\Request as McpRequest;
+
+final class {$class} extends AppMcpTool
+{
+    protected string \$description = {$escapedDescription};
+
+    /** @return array<string, mixed> */
+    public function schema(JsonSchema \$schema): array
+    {
+        {$schemaBody}
+    }
+
+    protected function toolName(): ToolName
+    {
+        return ToolName::{$case};
+    }
+
+    /** @return array<string, mixed> */
+    protected function runTool(McpRequest \$request, McpAuthorizationContext \$ctx): array
+    {
+        return [];
+    }
+}
+
+PHP;
+    }
+
+    /** 本プロセスで作った一時ディレクトリをすべて撤去する。 */
+    public static function cleanup(): void
+    {
+        foreach (self::$created as $dir) {
+            self::remove($dir);
+        }
+
+        self::$created = [];
+    }
+
+    /** 再帰削除 (symlink は辿らずに外す)。 */
+    public static function remove(string $path): void
+    {
+        if (is_link($path)) {
+            unlink($path);
+
+            return;
+        }
+
+        if (! file_exists($path)) {
+            return;
+        }
+
+        if (! is_dir($path)) {
+            unlink($path);
+
+            return;
+        }
+
+        $entries = scandir($path);
+        if ($entries !== false) {
+            foreach ($entries as $entry) {
+                if ($entry === '.' || $entry === '..') {
+                    continue;
+                }
+
+                self::remove($path.'/'.$entry);
+            }
+        }
+
+        rmdir($path);
+    }
+
+    /**
+     * @param  array<string, string>  $result
+     */
+    private static function walk(string $root, string $dir, array &$result): void
+    {
+        $entries = scandir($dir);
+        if ($entries === false) {
+            throw new RuntimeException("ディレクトリを走査できません: {$dir}");
+        }
+
+        foreach ($entries as $entry) {
+            if ($entry === '.' || $entry === '..') {
+                continue;
+            }
+
+            $absolute = $dir.'/'.$entry;
+            $relative = ltrim(substr($absolute, strlen($root)), '/');
+
+            if (is_link($absolute)) {
+                $result[$relative] = 'link:'.(readlink($absolute) === false ? '?' : readlink($absolute));
+
+                continue;
+            }
+
+            if (is_dir($absolute)) {
+                $result[$relative] = 'dir';
+                self::walk($root, $absolute, $result);
+
+                continue;
+            }
+
+            $contents = file_get_contents($absolute);
+            $result[$relative] = $contents === false ? 'unreadable' : hash('sha256', $contents);
+        }
+    }
+}
diff --git a/tests/Unit/Architecture/McpToolScannerTest.php b/tests/Unit/Architecture/McpToolScannerTest.php
new file mode 100644
index 00000000..6e84b16d
--- /dev/null
+++ b/tests/Unit/Architecture/McpToolScannerTest.php
@@ -0,0 +1,136 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Mcp\Tools\AppMcpTool;
+use App\Services\Help\McpToolScanner;
+use Tests\Support\Help\HelpTestTree;
+
+/*
+ * McpToolScanner (ヘルプの MCP ツール走査器) の自己検査。
+ *
+ * 走査器・gate の共通規約 (AGENTS.md §静的検査 (gate) と走査器の共通規約) の
+ * (b) fail-closed / (c) 負例で裏取り を、合成した一時走査根で両方向に固定する。
+ * 実装の docblock が「保証しないもの」の正本である。
+ */
+
+afterEach(function (): void {
+    HelpTestTree::cleanup();
+});
+
+test('正例: 基底を継承した具象クラスをクラス名昇順で列挙する', function (): void {
+    $root = HelpTestTree::makeDir('mcp-scanner-ok');
+    HelpTestTree::writeToolFixture($root, 'ScannerFixtureZebraTool', 'Whoami');
+    HelpTestTree::writeToolFixture($root, 'ScannerFixtureAlphaTool', 'ListProjects');
+
+    $classes = (new McpToolScanner($root))->concreteToolClasses();
+
+    expect($classes)->toBe([
+        'App\Mcp\Tools\ScannerFixtureAlphaTool',
+        'App\Mcp\Tools\ScannerFixtureZebraTool',
+    ]);
+
+    foreach ($classes as $class) {
+        expect(is_subclass_of($class, AppMcpTool::class))->toBeTrue();
+    }
+});
+
+test('正例: 抽象クラスは母集団から外れるが具象は残る', function (): void {
+    $root = HelpTestTree::makeDir('mcp-scanner-abstract');
+    HelpTestTree::writeToolFixture($root, 'ScannerFixtureConcreteTool', 'Whoami');
+
+    $abstract = $root.'/ScannerFixtureAbstractTool.php';
+    HelpTestTree::put($abstract, <<<'PHP'
+<?php
+
+declare(strict_types=1);
+
+namespace App\Mcp\Tools;
+
+abstract class ScannerFixtureAbstractTool extends AppMcpTool {}
+
+PHP);
+    require_once $abstract;
+
+    expect((new McpToolScanner($root))->concreteToolClasses())
+        ->toBe(['App\Mcp\Tools\ScannerFixtureConcreteTool']);
+});
+
+test('負例: 走査根が存在しないと例外で止まる (空を返さない)', function (): void {
+    $root = HelpTestTree::makeDir('mcp-scanner-missing');
+    $missing = $root.'/not-there';
+
+    expect(fn (): array => (new McpToolScanner($missing))->concreteToolClasses())
+        ->toThrow(RuntimeException::class, '走査根が存在しません');
+});
+
+test('負例: 母集団が 0 件なら「違反 0 件」ではなく走査の破損として止まる', function (): void {
+    $root = HelpTestTree::makeDir('mcp-scanner-empty');
+
+    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
+        ->toThrow(RuntimeException::class, '1 件も見つかりません');
+});
+
+test('負例: クラス名とファイル名が一致しないと例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('mcp-scanner-unresolved');
+    HelpTestTree::put($root.'/ScannerFixtureNoSuchClassTool.php', <<<'PHP'
+<?php
+
+declare(strict_types=1);
+
+namespace App\Mcp\Tools;
+
+final class ScannerFixtureDifferentNameTool {}
+
+PHP);
+
+    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
+        ->toThrow(RuntimeException::class, 'クラスを解決できません');
+});
+
+test('負例: 基底を継承しない具象クラスがあると例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('mcp-scanner-not-a-tool');
+    $path = $root.'/ScannerFixtureHelperClass.php';
+    HelpTestTree::put($path, <<<'PHP'
+<?php
+
+declare(strict_types=1);
+
+namespace App\Mcp\Tools;
+
+final class ScannerFixtureHelperClass {}
+
+PHP);
+    require_once $path;
+
+    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
+        ->toThrow(RuntimeException::class, 'を継承していません');
+});
+
+test('負例: 実体が symlink だと例外で止まる', function (): void {
+    $root = HelpTestTree::makeDir('mcp-scanner-symlink');
+    HelpTestTree::writeToolFixture($root, 'ScannerFixtureLinkTargetTool', 'Whoami');
+    symlink($root.'/ScannerFixtureLinkTargetTool.php', $root.'/ScannerFixtureLinkedTool.php');
+
+    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
+        ->toThrow(RuntimeException::class, '通常ファイルではありません');
+});
+
+test('負例: 同名クラスが別の場所から読み込まれていると例外で止まる (走査が空振りしない)', function (): void {
+    $root = HelpTestTree::makeDir('mcp-scanner-shadow');
+
+    // 実在する `App\Mcp\Tools\WhoamiTool` と同名のファイルを一時根へ置く。
+    // class_exists() は composer autoload 経由で **本物** を読むため、
+    // Reflection の実体は app/Mcp/Tools/WhoamiTool.php を指し、走査中のファイルと食い違う。
+    HelpTestTree::put($root.'/WhoamiTool.php', "<?php\n\ndeclare(strict_types=1);\n\n// 中身は読まれない (autoload が本物を解決する)\n");
+
+    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
+        ->toThrow(RuntimeException::class, '実体が走査中のファイルと一致しません');
+});
+
+test('走査根が実在し、実装の母集団が非空であること', function (): void {
+    $root = base_path('app/Mcp/Tools');
+
+    expect(is_dir($root))->toBeTrue();
+    expect((new McpToolScanner($root))->concreteToolClasses())->not->toBeEmpty();
+});
diff --git a/tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php b/tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php
new file mode 100644
index 00000000..d1bd788a
--- /dev/null
+++ b/tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php
@@ -0,0 +1,226 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Mcp\ToolName;
+use App\Mcp\Tools\AppMcpTool;
+use App\Mcp\Tools\WhoamiTool;
+use App\Services\Help\Generators\McpToolReferenceGenerator;
+use App\Services\Help\McpToolMetadata;
+use App\Services\Help\McpToolScanner;
+use App\Services\Mcp\Auth\McpAuthorizationContext;
+use App\Services\Mcp\McpIdempotencyService;
+use Illuminate\Contracts\JsonSchema\JsonSchema;
+use Laravel\Mcp\Request;
+use Tests\Support\Help\HelpTestTree;
+
+/*
+ * MCP ツール一覧の生成 (I2) と、vendor のメタデータの形が変わったら
+ * **静かに欠けずに止まる** こと (I14) を固定する。
+ */
+
+afterEach(function (): void {
+    HelpTestTree::cleanup();
+});
+
+/** 一時走査根を使う生成器を組み立てる。 */
+function helpGeneratorOver(string $root): McpToolReferenceGenerator
+{
+    return new McpToolReferenceGenerator(new McpToolScanner($root), app());
+}
+
+test('生成器のキーは manifest と突き合わせる `mcp-tools` である', function (): void {
+    expect(app(McpToolReferenceGenerator::class)->key())->toBe('mcp-tools');
+});
+
+test('出力は決定的である (同じ実装からは同じバイト列が出る)', function (): void {
+    $generator = app(McpToolReferenceGenerator::class);
+
+    expect($generator->generate())->toBe($generator->generate());
+});
+
+test('出力は先頭に自動生成の断り書きを持ち、末尾は改行 1 個で終わる', function (): void {
+    $markdown = app(McpToolReferenceGenerator::class)->generate();
+
+    expect($markdown)->toStartWith('<!-- 自動生成:')
+        ->and($markdown)->toEndWith("\n")
+        ->and(str_ends_with($markdown, "\n\n"))->toBeFalse();
+});
+
+test('パラメータを持つツールは表で、持たないツールは「パラメータなし。」で書かれる', function (): void {
+    $root = HelpTestTree::makeDir('mcp-generator-shape');
+    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureNoParamTool', 'Whoami');
+    HelpTestTree::writeToolFixture(
+        $root,
+        'GeneratorFixtureParamTool',
+        'ListProjects',
+        'パラメータ付きの見本',
+        "return ['project_id' => \$schema->integer()->description('Project ID')->required(), 'page' => \$schema->integer()];",
+    );
+
+    $markdown = helpGeneratorOver($root)->generate();
+
+    expect($markdown)->toContain('現在のツール数: 2')
+        ->and($markdown)->toContain('パラメータなし。')
+        ->and($markdown)->toContain('| パラメータ | 型 | 必須 | 説明 |')
+        ->and($markdown)->toContain('| `project_id` | integer | 必須 | Project ID |')
+        ->and($markdown)->toContain('| `page` | integer | 任意 |  |');
+});
+
+test('説明の縦棒と改行は表を壊さないように無害化される', function (): void {
+    $root = HelpTestTree::makeDir('mcp-generator-escape');
+    HelpTestTree::writeToolFixture(
+        $root,
+        'GeneratorFixtureEscapeTool',
+        'Whoami',
+        "縦棒 | と\n改行を含む説明",
+    );
+
+    $markdown = helpGeneratorOver($root)->generate();
+
+    expect($markdown)->toContain('縦棒 \\| と 改行を含む説明')
+        ->and($markdown)->not->toContain("縦棒 | と\n改行");
+});
+
+test('ツールは name の昇順で並ぶ', function (): void {
+    $root = HelpTestTree::makeDir('mcp-generator-order');
+    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureOrderWhoamiTool', 'Whoami');
+    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureOrderListItemsTool', 'ListItems');
+
+    $markdown = helpGeneratorOver($root)->generate();
+
+    expect(strpos($markdown, '## `list-items`'))->toBeLessThan((int) strpos($markdown, '## `whoami`'));
+});
+
+test('type が文字列の配列 (union / nullable) なら縦棒連結の表示文字列へ正規化される', function (): void {
+    $metadata = McpToolMetadata::fromSchema(
+        ['properties' => ['nick' => ['type' => ['string', 'null']]]],
+        WhoamiTool::class,
+        'fixture',
+        '',
+    );
+
+    expect($metadata->parameters[0]->type)->toBe('string|null');
+});
+
+test('type が未宣言なら (未宣言) へ正規化される (閉じた集合で弾かない)', function (): void {
+    $metadata = McpToolMetadata::fromSchema(
+        ['properties' => ['loose' => ['description' => 'なんでも']]],
+        WhoamiTool::class,
+        'fixture',
+        '',
+    );
+
+    expect($metadata->parameters[0]->type)->toBe('(未宣言)')
+        ->and($metadata->parameters[0]->description)->toBe('なんでも')
+        ->and($metadata->parameters[0]->required)->toBeFalse();
+});
+
+test('properties も required も無い schema はパラメータ 0 件として受け入れる', function (): void {
+    $metadata = McpToolMetadata::fromSchema(['type' => 'object'], WhoamiTool::class, 'fixture', '');
+
+    expect($metadata->parameters)->toBe([]);
+});
+
+dataset('vendor メタデータの想定外の形', [
+    'type が数値' => [
+        ['properties' => ['a' => ['type' => 1]]],
+        ['a'],
+    ],
+    'type が object (連想配列)' => [
+        ['properties' => ['a' => ['type' => ['first' => 'string']]]],
+        ['a'],
+    ],
+    'type が空配列' => [
+        ['properties' => ['a' => ['type' => []]]],
+        ['a'],
+    ],
+    'type の要素が文字列でない' => [
+        ['properties' => ['a' => ['type' => ['string', 3]]]],
+        ['a'],
+    ],
+    'description が文字列でない' => [
+        ['properties' => ['a' => ['type' => 'string', 'description' => ['x']]]],
+        ['a'],
+    ],
+    'パラメータ定義が配列でない' => [
+        ['properties' => ['a' => 'string']],
+        ['a'],
+    ],
+    'properties が配列でない' => [
+        ['properties' => 'nope'],
+        [],
+    ],
+    'required が list でない' => [
+        ['properties' => ['a' => ['type' => 'string']], 'required' => ['a' => true]],
+        [],
+    ],
+    'required の要素が空文字' => [
+        ['properties' => ['a' => ['type' => 'string']], 'required' => ['']],
+        [],
+    ],
+    'required に重複がある' => [
+        ['properties' => ['a' => ['type' => 'string']], 'required' => ['a', 'a']],
+        ['a'],
+    ],
+    'required が properties に無い名前を指す' => [
+        ['properties' => ['a' => ['type' => 'string']], 'required' => ['b']],
+        ['b'],
+    ],
+    'required があるのに properties が無い' => [
+        ['required' => ['a']],
+        [],
+    ],
+]);
+
+test('想定外の形は静かに欠けず、対象クラス・不正箇所・直し方を添えて止まる', function (array $schema, array $expectedMentions): void {
+    $call = fn (): McpToolMetadata => McpToolMetadata::fromSchema($schema, WhoamiTool::class, 'fixture', '');
+
+    expect($call)->toThrow(RuntimeException::class);
+
+    try {
+        $call();
+    } catch (RuntimeException $e) {
+        $message = $e->getMessage();
+
+        // 全負例で共通: 対象クラス名 / 何が起きたか (vendor の形が変わった) / 直し方 (直す先の型)
+        expect($message)->toContain(WhoamiTool::class)
+            ->and($message)->toContain('vendor')
+            ->and($message)->toContain('McpToolMetadata');
+
+        // 特定できる負例のみ: パラメータ名 / キー名
+        foreach ($expectedMentions as $mention) {
+            expect($message)->toContain($mention);
+        }
+    }
+})->with('vendor メタデータの想定外の形');
+
+test('name が空文字のツールは止まる', function (): void {
+    $tool = new class(app(McpIdempotencyService::class)) extends AppMcpTool
+    {
+        public function name(): string
+        {
+            return '';
+        }
+
+        public function schema(JsonSchema $schema): array
+        {
+            return [];
+        }
+
+        protected function toolName(): ToolName
+        {
+            return ToolName::Whoami;
+        }
+
+        protected function runTool(
+            Request $request,
+            McpAuthorizationContext $ctx,
+        ): array {
+            return [];
+        }
+    };
+
+    expect(fn (): McpToolMetadata => McpToolMetadata::fromTool($tool, WhoamiTool::class))
+        ->toThrow(RuntimeException::class, 'name() が空文字です');
+});
diff --git a/tests/js/architecture/enum-ts-sync-discovery.test.ts b/tests/js/architecture/enum-ts-sync-discovery.test.ts
index be786f69..4b19dc60 100644
--- a/tests/js/architecture/enum-ts-sync-discovery.test.ts
+++ b/tests/js/architecture/enum-ts-sync-discovery.test.ts
@@ -158,11 +158,12 @@ const PHP_ENUM_EXEMPTIONS = [
     { path: "app/Enums/Storage/S3OperationSurface.php", reason: "S3 操作面の内部分類語彙。SSRF 検査など Architecture テストの目録だけが参照する" },
     { path: "app/Enums/Support/QueueAtomicityRule.php", reason: "キュー投入原子性判定の内部語彙 (ドメイン固有規約 11)。Architecture テストの目録だけが参照する" },
     { path: "app/Enums/TwoFactorStatus.php", reason: "2FA 状態の内部判定。画面は有効/無効の真偽値と個別の案内文だけを見る" },
+    { path: "app/Services/Help/HelpArtifactState.php", reason: "ヘルプ生成物の鮮度の内部語彙 (up_to_date/stale/missing/orphan)。artisan コマンドの報告にのみ使い画面へは出ない" },
     { path: "app/Services/Marketing/ContactDestinationKind.php", reason: "マーケティング問い合わせの送信先を表す内部種別。バッチ処理の内部でのみ使う" },
 ] as const satisfies readonly PhpEnumExemption[];
 
 /** `PHP_ENUM_EXEMPTIONS` の件数の pin。増えても減っても赤くする。 */
-const EXPECTED_EXEMPTION_COUNT = 87;
+const EXPECTED_EXEMPTION_COUNT = 88;
 
 interface UnresolvablePhpEnumEntry {
     readonly path: string;

```

## テスト結果

```
composer phpstan            -> [OK] No errors
vendor/bin/pint --test      -> passed
pnpm lint / typecheck       -> passed
pnpm build                  -> built
pnpm typecheck:packages     -> passed
pnpm build:packages         -> passed
composer test --filter='Help|McpTool'
  -> {"tool":"pest","result":"passed","tests":118,"passed":118,"assertions":299}
php artisan help:build      -> _generated/mcp-tools.md .. up_to_date / INFO ヘルプ生成物を組み立てた。
php artisan help:build --check -> exit 0
```
