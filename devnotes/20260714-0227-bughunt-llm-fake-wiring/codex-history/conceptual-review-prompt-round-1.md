【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも全て判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから微調整せよ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション (Laravel 12 + Svelte 5 + Inertia) の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命 (North Star) に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か (Laravel 12 + Svelte 5 + Inertia.js)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか (特に「既存 Feature/Browser テストの Prism fake 経路を壊さないこと」)
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【本設計の技術的背景 (レビューの参考)】
- 全ての実プロンプト (`SopExtractPrompt` 等) は `Prompt::load()` 経由で generic な `Kent013\PrismPrompt\TextPrompt` を返す。`PromptFake::record()` は `static::class` を記録するため、全プロンプトが `TextPrompt::class` に潰れる。よって canned 応答をクラス名で返し分けられず、SystemMessage の役割文で判別する設計にしている。
- `Prompt::$fake` はプロセスグローバルな static。phpunit の `testing` 環境では tests/Pest.php の beforeEach/afterEach と StrayLlmCallGuard がこの static を per-test で管理しているため、LLM fake の allowlist から `testing` を除外している (Stripe fake は container bind なので `testing` を含めたまま安全)。
- AI 解析は `AnalysisPipeline` が `SopExtractPrompt::make(...)->executeSync()` の text を各 DTO の `fromLlmText()` で検証し、失敗すると `withBoundedRetry` でリトライ → ジョブ失敗となる。canned 応答は各 DTO 検証を通過する決定論 JSON である必要がある。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下 devnotes/20260714-0227-bughunt-llm-fake-wiring/conceptual-design.md の内容）

# 概念設計: bughunt-llm-fake-wiring

## 背景・課題

bug-hunt (LLM 探索的バグハント) の隔離環境 (`APP_ENV=bughunt.local`、`php artisan serve` の実行時 serve プロセス) で、Prism (`echolabsdev/prism` + `kent013/laravel-prism-prompt`) 経由の LLM 呼び出しが **fake されずに実 Anthropic API へ飛び 401** で失敗する:

```
Anthropic Error [401]: authentication_error - x-api-key header is required
```

その結果、S3 (AI 解析 → 撮影 → レンダー) 中核チェーンの後半:

```
SOP 抽出 → 作業分解 → シナリオ生成 → 撮影 → レンダー
```

の「AI 解析 3 段」が bughunt で常に失敗し、**bug-hunt の網羅性が S3 で恒常的に欠ける**。

### 根本原因

- `app/Providers/FakeExternalsServiceProvider.php` は **Stripe (`TicketCheckoutGateway` / `SubscriptionCheckoutGateway`) のみ** を fake bind し、**LLM (Prism) の fake を配線していない**。
- canned 応答機構は既に存在する:
  - `app/Services/AI/Testing/BrowserCannedResponses.php` — Prompt クラス → canned text のマップ。`Kent013\PrismPrompt\Testing\TextResponseFake` を返す。未登録クラスは fail-fast。
  - `app/Services/AI/Testing/BrowserPromptFakeRegistrar.php` — `install()` で `Kent013\PrismPrompt\Prompt::installFake()` に canned fake をインストールする。
- ただし `install()` は **`tests/Pest.php:90` の pest-plugin-browser テストプロセス内 (Browser lane の beforeEach) でしか呼ばれず**、bughunt の実行時 serve には適用されない。
- 加えて、`app/Prompts/*` の factory (`SopExtractPrompt` 等) は全て `Prompt::load()` 経由で **generic な `Kent013\PrismPrompt\TextPrompt` インスタンス**を返す。`PromptFake::record()` は `static::class` を記録するため、**全ての実プロンプトが同一の `TextPrompt::class` として記録される**。現行 `BrowserCannedResponses` はクラス名キーの map で、`TextPrompt::class => (要約用 1 文)` のエントリしか持たない = **AI 解析 3 段の各 DTO スキーマ (`sections` / `steps` / `cuts`) を満たす応答を返し分けられない**。

### 検証したい仮説

- **H1**: FakeExternalsServiceProvider の boot で canned Prompt fake を実行時 serve にもインストールすれば、bughunt の S3 中核チェーンが実 API に到達せず完走する。
- **H2**: 実プロンプトが全て `TextPrompt::class` として記録される以上、canned 応答は**クラス名ではなくプロンプトの識別子 (SystemMessage の役割文) で返し分ける**必要がある。返し分けができれば各 DTO 検証 (`fromLlmText`) を通過し、リトライ・ジョブ失敗なく完走する。
- **成功判定**: bughunt.local serve で AI 解析ジョブが実 API に出ず (StrayLlmCallGuard 思想に反しない)、`ExtractedSopData` / `WorkDecompositionData` / `GeneratedScenarioData` が全て検証を通過してシナリオが materialize される。既存 Feature / Browser テストの Prism fake 経路は不変。

## 改善アイデア

1. **実行時 serve への canned fake 配線**: `FakeExternalsServiceProvider::boot()` で、`config('testing.fake_externals') === true` かつ **LLM 用の環境 allowlist** に属するとき、既存 registrar の `install()` を呼び、`Prompt::$fake` に canned fake をインストールする。`Prompt::$fake` は static なので serve プロセス起動時に 1 度インストールすれば以降の全リクエストで有効。
2. **canned 応答の返し分け機構**: canned 応答の解決キーを「Prompt クラス名」から「**SystemMessage の役割文 (signature)**」へ変更する。全実プロンプトが `TextPrompt::class` に潰れる問題を、trusted な static system prompt の一意な役割文で判別して回避する。
3. **S3 中核 Prompt の canned 応答追加**: `sop-extract` / `work-decomposition` / `scenario-generation` の 3 プロンプトについて、各 DTO の `fromLlmText()` を通過する**決定論的な最小妥当 JSON** を canned 応答として追加する (`example-summary` の既存応答は維持)。
4. **命名の是正**: 上記機構は Browser テストだけでなく bughunt serve 実行時にも共有されるため、`Browser*` の名称 (`BrowserCannedResponses` / `BrowserPromptFake` / `BrowserPromptFakeRegistrar`) を用途を表す中立名へ改名する (機能の名前に立ち返る原則)。

### signature による返し分けの理由 (なぜ user message ではなく system message か)

- user message には UserInput 経由の入力が埋め込まれる。特に `scenario-generation` の入力は work-decomposition の出力 JSON (`"action"` 等を含む) であり、user message 本文で token マッチすると **段間で誤判定する** (`scenario` を `work-decomposition` と誤認)。
- system prompt は各 YAML の静的な役割定義であり、ユーザ入力が混入しない。役割文 (例: 「マニュアル動画の演出家」) は YAML 横断で一意 = **衝突しない安定した判別子**。
- 判別子が YAML から drift した場合に silent green にならないよう、**drift-guard テスト** (実プロンプトを render して signature が一意に一致し、返された canned が DTO 検証を通過することを assert) を追加する。

## 期待効果

- **使命への貢献**: S3 (AI 解析 → 撮影 → レンダー) は本アプリの中核価値 (SOP 起点で AI がシナリオを生成する) そのもの。bughunt でこの後半チェーンを実走検証できるようになり、UX 破綻・詰み・IDOR を **S3 全域で**発見できるようになる (bug-hunt の網羅性回復)。
- **本番挙動は不変**: 変更は `fake_externals === true` かつ LLM allowlist 環境 (bughunt.local 等) でのみ発火。production は `ProductionEnvGuard` が flag=true を deploy 時に fail-fast で拒否 (二重防御) するため到達しない。
- **テスト基盤の健全性**: 未登録プロンプトは fail-fast を維持 (silent green 防止)。実 API へは一切出さない (StrayLlmCallGuard の思想に沿う)。

## 実装方針（概要）

| 対象 | 変更概要 |
|------|---------|
| `FakeExternalsServiceProvider` | `boot()` を追加。`fake_externals===true` ∧ 環境 ∈ LLM allowlist のとき registrar の `install()` を呼ぶ。**`testing` 環境は allowlist から除外** (phpunit の Pest harness が `Prompt::$fake` static を per-test で占有するため。詳細は下記)。 |
| canned 応答クラス (改名後) | 解決キーを class → system message signature へ変更。`sop-extract` / `work-decomposition` / `scenario-generation` / `example-summary` の 4 signature を登録。未一致は fail-fast。 |
| canned fake クラス (改名後) | `nextResponse()` を、最新 record の messages を signature 解決へ渡すよう変更。 |
| registrar クラス (改名後) | `install()` / `uninstall()` はそのまま (単一箇所での `Prompt::installFake` 封じ込め)。実行時 serve と Browser lane の両方から共有。 |
| `tests/Pest.php` | 改名に伴う import / 呼び出しの追随のみ (Browser lane の挙動は不変)。 |

### 環境 allowlist の設計 (Stripe とは別 allowlist にする)

- Stripe fake は **container bind** (`$this->app->bind(...)`)。Pest はテスト毎に app を再構築するため `testing` 環境で bind しても per-test で隔離され安全。よって Stripe の allowlist は `['local', 'testing', 'bughunt.local']` のまま。
- LLM fake は **プロセスグローバルな static (`Prompt::$fake`)** を書き換える。`testing` (phpunit) では tests/Pest.php の beforeEach/afterEach と `StrayLlmCallGuard` がこの static を per-test で占有・管理しているため、**provider boot でここに触れると harness と衝突し、未 fake 呼び出しの検知 (StrayLlmCallGuard) や per-test の `Prompt::fake()` を壊す**。
- したがって LLM fake の allowlist は **`['local', 'bughunt.local']` (= `testing` を除外)** とする。これは「allowlist guard の流用 (パターン踏襲)」だが対象環境は狭める。fail-secure は維持され、`testing` 除外により既存テスト経路を壊さない。

## 制約・前提

- **AGENTS.md 禁止事項の遵守**: LLM 呼び出しは `app/Prompts/` factory 経由のみ (本変更は factory を触らず、fake の配線先を追加するだけ)。`response()->json()` 直書きなし。PHPStan L10 / Pest テスト必須 / RefreshDatabase グローバル。
- **canned 応答は決定論的で DTO 検証を通過**すること。通過しないと `AnalysisPipeline::withBoundedRetry` がリトライ → ジョブ失敗となり、fake の意味がない。
- `Prompt::$fake` の fake 分岐は `PromptExecutionCompleted` イベントを発火せず `llm_call_logs` を書かない (現行 Browser lane と同じ挙動)。bughunt は UX 検証が目的なので許容 (既知の効果として明記)。
- `php artisan serve` は単一プロセスで逐次リクエストを処理するため、起動時 1 回の static インストールで全リクエストに有効。

## スコープ外

- **ffmpeg 不在** (Q1 の残り 1 件): 別 item で対応。
- **S3 互換ストレージ region 未設定** (Q1 の残り 1 件): 別 item で対応。
- レンダー (ffmpeg) 段のチェーンは本 item では扱わない。本 item は「AI 解析 3 段が実 API に出ず完走する」ところまで。
- production / staging 等の非 allowlist 環境の挙動 (既存 `ProductionEnvGuard` / register() の warning 経路は不変)。
- prism-prompt (vendor) 本体の改修 (record に prompt name を載せる等) はしない。
