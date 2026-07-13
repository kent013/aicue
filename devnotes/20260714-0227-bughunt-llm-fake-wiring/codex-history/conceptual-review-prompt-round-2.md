Round 1 の指摘に対する対応を反映しました。対応マトリクスと、更新後の概念設計の該当箇所を示します。全体判定の再評価をお願いします。

## 対応サマリー (Round 1 指摘への回答)

- **[Critical] 成功判定を serve に限定するのは危険 (queue worker)**: 対応。`RunManualAnalysis` は `ShouldQueue`。bughunt は `QUEUE_CONNECTION=sync`(同一 HTTP プロセス) 構成も、T018 の専用 queue worker で async 実行する構成もある。`FakeExternalsServiceProvider::boot()` は **全アプリプロセス (HTTP serve / queue worker / artisan) の bootstrap で走る**ため queue driver に依存せず両経路で有効。成功判定を「HTTP プロセス + queue worker の双方で実 API に出ない」に修正し、queue 経路の検証を必須にした。

- **[Critical] LLM allowlist に local を含めるのはスコープ過大**: 対応。LLM runtime allowlist を **`['bughunt.local']` のみ**に絞った (local 削除)。Stripe allowlist (`['local','testing','bughunt.local']`) とは別に LLM 専用の狭い allowlist を新設。将来 local 必要時は別フラグ opt-in。

- **[Warning] signature の変更耐性**: 対応 (一部反論)。vendor の `PromptFake::record()` は `prompt_class/messages/provider/model` のみ記録し YAML の `name` を fake 解決時に渡さない。`name` 分岐は vendor 改修が必要でスコープ外。よって system message signature を採るが、抽出ロジックを canned 応答クラス内の単一メソッドに閉じ、各 prompt と signature の 1:1 対応をテストで固定する。

- **[Warning] 期待効果の誇張**: 対応。「S3 全域」→「AI 解析 3 段の主要 UX 導線」に下げ、`PromptExecutionCompleted` 非発火 = `llm_call_logs` 非生成によりログ依存 UI/監査/運用導線は未検証領域である旨を明示。

- **[Warning] testing 除外だけでは不十分**: 対応。Browser lane と同一の install/uninstall API を維持し、`testing` では provider が `Prompt::$fake` に一切触れないことを回帰テストで固定する旨を明記。

- **[Warning] rename スコープを広げない**: 対応。改名は 3 クラス + tests/Pest.php import 追随のみに限定、互換 alias なしで旧名を同 PR で消す旨を明記。

- **[Warning] 型安全性: fromLlmText 保証を前面に**: 対応。「実 prompt render → fake 実行 → 該当 DTO `fromLlmText()` 成功」を主保証テストとして前面に明記。

## 更新後の概念設計 (全文)

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

- **H1**: FakeExternalsServiceProvider の **boot() で canned Prompt fake を配線すれば、bughunt の全アプリプロセス (HTTP serve / queue worker) で** S3 中核チェーンが実 API に到達せず完走する。
  - `RunManualAnalysis` は `ShouldQueue`。bughunt は `QUEUE_CONNECTION=sync` (同一 HTTP プロセス実行) の構成も、T018 で追加した専用 queue worker で async 実行する構成もある。`FakeExternalsServiceProvider::boot()` は **全アプリプロセスの bootstrap で走る** (serve / worker / artisan)。よって sync でも async でも fake は有効で、queue driver に依存しない。
- **H2**: 実プロンプトが全て `TextPrompt::class` として記録される以上、canned 応答は**クラス名ではなくプロンプトの識別子 (SystemMessage の役割文) で返し分ける**必要がある。返し分けができれば各 DTO 検証 (`fromLlmText`) を通過し、リトライ・ジョブ失敗なく完走する。
- **成功判定**: bughunt の **HTTP プロセスと queue worker の双方**で AI 解析ジョブが実 API に出ず (StrayLlmCallGuard 思想に反しない)、`ExtractedSopData` / `WorkDecompositionData` / `GeneratedScenarioData` が全て検証を通過してシナリオが materialize される。既存 Feature / Browser テストの Prism fake 経路は不変 (`testing` で provider は `Prompt::$fake` に一切触れない)。

## 改善アイデア

1. **実行時への canned fake 配線 (全アプリプロセス)**: `FakeExternalsServiceProvider::boot()` で、`config('testing.fake_externals') === true` かつ **LLM 用の環境 allowlist (`['bughunt.local']` のみ)** に属するとき、既存 registrar の `install()` を呼び、`Prompt::$fake` に canned fake をインストールする。`Prompt::$fake` は static なので各プロセス起動時に 1 度インストールすれば以降の全リクエスト/ジョブで有効。boot() は HTTP serve・queue worker・artisan の全 bootstrap で走るため queue driver (sync/async) に依存しない。
2. **canned 応答の返し分け機構**: canned 応答の解決キーを「Prompt クラス名」から「**SystemMessage の役割文 (signature)**」へ変更する。全実プロンプトが `TextPrompt::class` に潰れる問題を、trusted な static system prompt の一意な役割文で判別して回避する。
3. **S3 中核 Prompt の canned 応答追加**: `sop-extract` / `work-decomposition` / `scenario-generation` の 3 プロンプトについて、各 DTO の `fromLlmText()` を通過する**決定論的な最小妥当 JSON** を canned 応答として追加する (`example-summary` の既存応答は維持)。
4. **命名の是正 (対象を 3 クラスに限定)**: 上記機構は Browser テストだけでなく bughunt 実行時にも共有されるため、`Browser*` の名称 (`BrowserCannedResponses` / `BrowserPromptFake` / `BrowserPromptFakeRegistrar`) を用途を表す中立名へ改名する (機能の名前に立ち返る原則)。**改名は LLM fake 配線に直接関わるこの 3 クラス + `tests/Pest.php` の import 追随のみ**に限定し、周辺名称の整理には広げない。互換 alias は残さず同 PR で旧名を消す。

### signature による返し分けの理由 (なぜ user message ではなく system message か)

- user message には UserInput 経由の入力が埋め込まれる。特に `scenario-generation` の入力は work-decomposition の出力 JSON (`"action"` 等を含む) であり、user message 本文で token マッチすると **段間で誤判定する** (`scenario` を `work-decomposition` と誤認)。
- system prompt は各 YAML の静的な役割定義であり、ユーザ入力が混入しない。役割文 (例: 「マニュアル動画の演出家」) は YAML 横断で一意 = **衝突しない安定した判別子**。
- **なぜ YAML の `name` を使わないか**: vendor (`kent013/laravel-prism-prompt`) の `PromptFake::record()` は `prompt_class / messages / provider / model` のみを記録し、YAML の `name` を fake 解決時に渡さない。`name` で分岐するには vendor 改修が必要でスコープ外。よって trusted な static system prompt の役割文を判別子とする。
- **変更耐性の担保**: signature 抽出ロジックは canned 応答クラス内の**単一メソッドに閉じる**。各 prompt と signature の **1:1 対応をテストで固定**する。判別子が YAML から drift した場合に silent green にならないよう、**drift-guard テスト** (実プロンプトを render → signature が一意に一致 → 返された canned が該当 DTO の `fromLlmText()` を通過することを assert) を追加する。この 1 本のテストが「canned JSON が DTO の現不変条件に追随し続けること」を PHPStan L10 の外側で担保する主保証となる。

## 期待効果

- **使命への貢献**: S3 (AI 解析 → 撮影 → レンダー) は本アプリの中核価値 (SOP 起点で AI がシナリオを生成する) そのもの。bughunt で **AI 解析 3 段の主要 UX 導線** (SOP 抽出 → 作業分解 → シナリオ生成 → materialize) を実走検証できるようになり、その導線上の UX 破綻・詰み・IDOR を発見できるようになる (bug-hunt の網羅性回復)。
- **未検証領域の明示**: fake 分岐は `PromptExecutionCompleted` を発火せず `llm_call_logs` を書かない (現行 Browser lane と同じ)。よって **LLM 呼び出しログに依存する UI・監査・運用導線 (llm_call_logs 表示・コスト集計等) は本番同等には検証できない**。この領域は bughunt 網羅の対象外として認識する。
- **本番挙動は不変**: 変更は `fake_externals === true` かつ LLM allowlist 環境 (`bughunt.local`) でのみ発火。production は `ProductionEnvGuard` が flag=true を deploy 時に fail-fast で拒否 (二重防御) するため到達しない。
- **テスト基盤の健全性**: 未登録プロンプトは fail-fast を維持 (silent green 防止)。実 API へは一切出さない (StrayLlmCallGuard の思想に沿う)。

## 実装方針（概要）

| 対象 | 変更概要 |
|------|---------|
| `FakeExternalsServiceProvider` | `boot()` を追加。`fake_externals===true` ∧ 環境 ∈ LLM allowlist (`['bughunt.local']`) のとき registrar の `install()` を呼ぶ。**`testing` / `local` は allowlist から除外** (phpunit harness が `Prompt::$fake` static を per-test で占有する / local 開発の実 API 検証を潰さないため。詳細は下記)。 |
| canned 応答クラス (改名後) | 解決キーを class → system message signature へ変更。`sop-extract` / `work-decomposition` / `scenario-generation` / `example-summary` の 4 signature を登録。未一致は fail-fast。 |
| canned fake クラス (改名後) | `nextResponse()` を、最新 record の messages を signature 解決へ渡すよう変更。 |
| registrar クラス (改名後) | `install()` / `uninstall()` はそのまま (単一箇所での `Prompt::installFake` 封じ込め)。実行時 serve と Browser lane の両方から共有。 |
| `tests/Pest.php` | 改名に伴う import / 呼び出しの追随のみ (Browser lane の挙動は不変)。 |

### 環境 allowlist の設計 (Stripe とは別 allowlist にする)

- Stripe fake は **container bind** (`$this->app->bind(...)`)。Pest はテスト毎に app を再構築するため `testing` 環境で bind しても per-test で隔離され安全。よって Stripe の allowlist は既存の `['local', 'testing', 'bughunt.local']` のまま (本 item では変更しない)。
- LLM fake は **プロセスグローバルな static (`Prompt::$fake`)** を書き換える。影響範囲が広く、以下 2 環境で問題になる:
  - `testing` (phpunit): tests/Pest.php の beforeEach/afterEach と `StrayLlmCallGuard` がこの static を per-test で占有・管理している。**provider boot でここに触れると harness と衝突し、未 fake 呼び出しの検知 (StrayLlmCallGuard) や per-test の `Prompt::fake()` を壊す**。
  - `local` (開発 serve): 手元で実 API 連携を確認したいケースを canned 応答が潰してしまう。
- したがって LLM fake の allowlist は **`['bughunt.local']` のみ** とする (Stripe allowlist の流用ではなく、LLM 専用の狭い allowlist を新設)。fail-secure は維持され、`testing` / `local` 除外により既存テスト経路・手元検証を壊さない。将来 local で必要になれば別フラグの明示 opt-in として分離する (本 item ではやらない)。
- **既存 install/uninstall API の維持**: provider boot から呼ぶのは Browser lane と同一の registrar `install()` (= `Prompt::installFake`)。API を分岐させない。`testing` では provider が `Prompt::$fake` に一切触れないことを回帰テストで固定する。

## 制約・前提

- **AGENTS.md 禁止事項の遵守**: LLM 呼び出しは `app/Prompts/` factory 経由のみ (本変更は factory を触らず、fake の配線先を追加するだけ)。`response()->json()` 直書きなし。PHPStan L10 / Pest テスト必須 / RefreshDatabase グローバル。
- **canned 応答は決定論的で DTO 検証を通過**すること。通過しないと `AnalysisPipeline::withBoundedRetry` がリトライ → ジョブ失敗となり、fake の意味がない。
- `Prompt::$fake` の fake 分岐は `PromptExecutionCompleted` イベントを発火せず `llm_call_logs` を書かない (現行 Browser lane と同じ挙動)。bughunt は UX 検証が目的なので許容 (既知の効果として明記)。
- `php artisan serve` は単一プロセスで逐次リクエストを処理するため、起動時 1 回の static インストールで全リクエストに有効。

## 検証・テスト方針 (概念段階。詳細は詳細設計の テスト計画)

必須成果物として以下の回帰・統合テストを設計に織り込む (テストなしの完了報告は禁止事項):

1. **DTO 通過統合テスト (主保証)**: 4 プロンプト (`sop-extract` / `work-decomposition` / `scenario-generation` / `example-summary`) それぞれについて「実 factory で prompt を build → registrar で canned fake install → `executeSync()` → 該当 DTO の `fromLlmText()` が成功 (example-summary は非空 string)」を 1 本で担保。canned JSON の DTO 追随と signature 1:1 対応を同時に固定する。
2. **provider 発火条件テスト**: `bughunt.local` 環境 ∧ flag=true で boot() すると `Prompt::isFaking()===true` かつ代表 prompt が canned を返す (stray call 0)。`testing` / `local` 環境 ∧ flag=true では boot() が `Prompt::$fake` に**触れない** (`Prompt::isFaking()===false`)。flag=false では触れない。既存 `FakeExternalsServiceProviderTest` の env 差し替えパターン (`$this->app['env']` を try/finally で復元 + `Prompt::stopFaking()`) を踏襲。
3. **未登録 prompt fail-fast テスト**: どの signature にも一致しない system message を canned 解決に渡すと `RuntimeException` (silent green 防止)。
4. **既存経路非破壊**: 既存 Browser lane (tests/Pest.php) と StrayLlmCallGuard 系テストが緑のまま (改名追随のみで挙動不変)。

## スコープ外

- **ffmpeg 不在** (Q1 の残り 1 件): 別 item で対応。
- **S3 互換ストレージ region 未設定** (Q1 の残り 1 件): 別 item で対応。
- レンダー (ffmpeg) 段のチェーンは本 item では扱わない。本 item は「AI 解析 3 段が実 API に出ず完走する」ところまで。
- production / staging 等の非 allowlist 環境の挙動 (既存 `ProductionEnvGuard` / register() の warning 経路は不変)。
- prism-prompt (vendor) 本体の改修 (record に prompt name を載せる等) はしない。
