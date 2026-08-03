# アプリの使命（North Star）

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# AGENTS.md 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【特に厳しく見てほしい点】
- 本設計は「upstream の自作 composer パッケージ (kent013/laravel-prism-prompt) に streaming API を足し、
  アプリ側はそれを factory 経由で使う」という、リポジトリ外に作業が及ぶ方針である。
  この方針選択そのものが妥当か。より単純な代替 (client timeout 延長 + retries 削減、段分割) を
  不当に退けていないか。「オーバーエンジニアリング禁止」に抵触しないか
- 「未検証の前提」セクションに挙げた 3 点は、設計を進める前に潰すべきものか、詳細設計と並行でよいか
- 実測値が 1 件も無い状態でストリーミング化という大きな方針を決めるのは、思考原則
  「仕組みが機能していない段階で値を弄るな」「データに真摯に向き合え」に照らして正しいか。
  それとも「まず計測だけ入れる」を先行させるべきか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: analysis-generate-timeout

## 背景・課題

AI 解析パイプライン (`AnalysisPipeline`) の generate 段 (シナリオ生成) が、実測 2/2 で必ず失敗する。

```
cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received
for https://api.anthropic.com/v1/messages
```

`extract` (progress 10) → `decompose` (35) → `generate` (65) と進み、generate だけが落ちる。
結果、**このアプリの使命の中核である「AI が撮るべきカットを設計した動画シナリオを生成する」が一度も完走できていない**。

### 直接原因 — 「遅すぎる」のではなく「誤った計測器で切っている」

| 要素 | 値 | 根拠 |
|---|---|---|
| 呼び出し方 | 非ストリーミング (`$builder->asText()`) | `vendor/kent013/laravel-prism-prompt/src/Prompt.php:748` |
| max_tokens | 16,000 | `resources/prompts/scenario-generation.yaml:8` |
| client timeout | 120 秒 | `resources/prompts/scenario-generation.yaml:10` |

非ストリーミングでは **TTFB = 生成完了までの全時間**。かつ `client_options.timeout` は Guzzle CurlHandler 経由で
`CURLOPT_TIMEOUT_MS = 120000` にマップされる (`vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:1569`) =
**リクエスト全体の壁時計上限**。

したがって `0 bytes received` は異常ではなく構成上の必然であり、「生成が 120 秒を超えた」以上の情報を持たない。
extract / decompose が通るのは実出力が小さいためで、3 段とも同じ `timeout:120` / `max_tokens:16000` を持つ
(`sop-extract.yaml` / `work-decomposition.yaml`) にもかかわらず generate だけが落ちるのはこの差による。

### 課題の本体 — 事実が構造的に観測できない

`llm_call_logs` は `output_tokens` / `duration_ms` 列を持つ
(`database/migrations/2026_06_11_090000_create_llm_call_logs_table.php:47,63`) が、記録契機である
`PromptExecutionCompleted` は `asText()` の **成功後にのみ発火する** (`Prompt.php:747-760`)。

**いま失敗している当の generate 呼び出しは、構造的に観測に載らない。** リポジトリ内に実測値は 0 件。
この状態では「timeout を何秒にすべきか」も「分割が必要か」も事実で決められない。
`AGENTS.md` の思考原則「仕組みが機能していない段階で値を弄るな」「データに真摯に向き合え」に照らすと、
**値のチューニングではなく計測器そのものの設計を正すのが本筋**である。

### なぜ timeout 延長ではないのか

`AnalysisTimeBudgetInvariantTest` が固定する式は
`3段 × attempts × T + 180 <= job timeout <= retry_after < 予約TTL(1800) <= stale閾値` である。
`attempts` (= `config/manual.php` の `analysis_llm_max_retries`) は TTL と独立した自由変数のため、
retries を 2→1 に下げれば TTL に触れずに T_generate <= 360 秒まで取れる (予算連鎖の再設計は不要)。

しかしこれは **generate を 1 秒も速くせず、同じ誤った計測器の目盛りを伸ばすだけ**。
「120 秒無応答で待つ」が「360 秒無応答で待つ」に変わるにすぎず、実出力が 16,000 トークンに張り付いていれば
360 秒でも届かない可能性がある (未計測のため判定不能)。恒久策にならない。

## 改善アイデア

**generate 段をストリーミング化し、壁時計上限をアプリ層のデッドラインへ移す。あわせて失敗を観測に載せる。**

1. **upstream (`kent013/laravel-prism-prompt`) に `executeStream()` 相当を追加する**
   - 現状 upstream は最新 0.20.1 でも `asStream` を持たない (`src/` に 0 件。あるのは `Prompt.php:748` の `asText()`)
   - **factory 経由を維持できるため `PromptGuardrailTest` / `AGENTS.md` 禁止事項 5 (Prism 直呼び禁止) と衝突しない**。
     アプリから `Prism::text()->asStream()` を直接書く案は `TARGET_METHODS` に `'stream'` を含み
     `ALLOWED_FILES = []` であるため確実に落ちる (`tests/.../PromptGuardrailTest.php:31,37`)
   - `PromptExecutionCompleted` 発火 / `computeCost` / `Prompt::fake()` 分岐を基底側に持てるので、
     観測とテスト基盤が保たれる (直呼び案ではこれらの再実装が必要になる)

2. **アプリ側: generate 段をストリーミング消費 + 壁時計デッドライン**
   - ストリーミングでは Guzzle が `Proxy::wrapStreaming` により CurlHandler ではなく StreamHandler へ
     ルーティングされ、`timeout` の意味が壁時計から変わる
   - **これは「時間制約が消える」ことを意味しない**。1 試行の壁時計を縛るものが job timeout 以外に無くなるため、
     アプリ層の消費ループに明示的なデッドラインを置き、**それを時間予算式の新しい `T` とする**

3. **失敗した LLM 呼び出しを観測に載せる**
   - `PromptExecutionFailed` 相当を追加し、`llm_call_logs` に失敗も記録する
   - これにより generate の実所要時間・実出力トークンが初めて事実になる

4. **時間予算の不変条件を 2 パラメータへ再設計**
   - 現行の単一 `T` (壁時計上限) から、`T_idle` (ストール検知) と `T_total` (アプリ層デッドライン) へ
   - `AnalysisTimeBudgetInvariantTest` の worst-case 式は `T_total` を用いて再定義する

## 期待効果

- **使命への貢献**: 「SOP を起点に AI が動画シナリオを生成する」という中核機能が初めて完走可能になる。
  現状この機能は 100% 失敗しており、アプリの価値提案そのものが成立していない
- generate の実所要時間・実出力トークンが観測可能になり、以降の判断が事実ベースになる
- 計測器が「全体所要」から「provider 無応答」の検知へ正され、遅い応答と落ちた接続を区別できる
- extract / decompose も同じ構成のため、将来同じ問題が顕在化する経路を先に塞げる

## 実装方針（概要）

| # | 対象 | 変更概要 |
|---|---|---|
| 1 | upstream `Prompt.php` | `executeStream()` 追加。`asStream()` を消費し、`TextDeltaEvent.delta` を連結して全文を返す。`PromptExecutionCompleted` / `computeCost` / fake 分岐を新経路にも通す |
| 2 | upstream | `PromptExecutionFailed` 相当のイベント追加 (失敗も観測に載せる) |
| 3 | upstream | `read_timeout` (ストール検知) を `client_options` から渡せるようにする |
| 4 | upstream | README 更新 → タグ切り → push |
| 5 | `resources/prompts/scenario-generation.yaml` | `client_options` に `read_timeout` を追加。`timeout` の意味論変更を反映 |
| 6 | `AnalysisPipeline::runGenerateStep` | ストリーミング経路へ切替 + 壁時計デッドライン |
| 7 | `AnalysisTimeBudgetInvariantTest` | worst-case 式を `T_total` ベースへ再設計 |
| 8 | `AnalysisTokenBudgetInvariantTest` | 3 本一律 `toBe(120)` を段ごとの許容値へ |
| 9 | `LlmCallLog` 周辺 | 失敗記録の受け口 |

### 開発フロー (aigenba の前例に倣う)

開発中は submodule + composer path 参照で upstream のソースを直接参照する:

```
.gitmodules:   packages/laravel-prism-prompt → git@github.com:kent013/laravel-prism-prompt.git
composer.json: {"type": "path", "url": "./packages/laravel-prism-prompt"}
require:       "kent013/laravel-prism-prompt": "@dev"
```

動作確認 → README 更新 → タグ切り → push → 動作確認 の後、**マージ時には path 参照を切って
通常のバージョン依存 (`^0.21.0` 等) に戻す**。submodule / path repository はマージ対象に残さない。

## 制約・前提

- **`AGENTS.md` 禁止事項 5**: LLM 呼び出しの Prism 直呼び禁止 (factory 経由のみ)。
  → upstream 側に実装することで遵守する。`ALLOWED_FILES` に穴を開けない
- **`AGENTS.md` 禁止事項 6**: prompt 文字列のコード直書き禁止 (`resources/prompts/*.yaml`)。
  → timeout 値も YAML 側に置く方針を維持
- **`AGENTS.md` 思考原則 1**: フレームワークのレンジ内でやる。自前機構の前に公式作法を確認
- **予約 TTL 1800 は変更しない** (`AnalysisTimeBudgetInvariantTest.php:22` に「(変更しない)」と明記)。
  本方針は TTL に触れない
- **部分結果の途中永続化は採らない**。`finalize` の Running guard (`AnalysisPipeline.php:199-201`) と
  `failJob` の冪等性が支える「stale 回収先勝ち = 無課金 succeeded 排除」が崩れるため
- **structured output との排他はブロッカーにならない**。generate 段は `TextPrompt` を返し schema 無し経路
  (`Prompt.php:437-448`) のため、`TextDeltaEvent.delta` の連結で `LlmJson::decode(string)` は無改造で成立する

## 未検証の前提（詳細設計までに実測で確定させる）

1. **PHP http stream context の `timeout` が per-read (ストール検知) か壁時計か**。
   `StreamHandler::add_timeout` は `$options['http']['timeout']` に値を渡すのみ
   (`vendor/guzzlehttp/guzzle/src/Handler/StreamHandler.php:849-853`) で、コード上では確定不能。
   **ここが壁時計だと `read_timeout` 明示なしにはストリーミング化しても何も解決しない**。本方針の前提条件
2. **generate 段の実出力トークン数・実所要時間**。施策 2・3 の投入後に実測する
3. **CurlHandler → StreamHandler 切替の実害**。`connect_timeout` が無視される
   (`StreamHandler` に `add_connect_timeout` は存在しない)、Keep-Alive 不在、SSE の 1 バイト read 等の副作用

## スコープ外

- **extract / decompose のストリーミング化**。同じ構成だが現に通っている。実測を得てから別途判断する
  (今回は upstream に口を用意するところまで)
- **`ScenarioLimits::MAX_STEPS=100 × MAX_POINTS_PER_STEP=20` (最大 2,100 カット) の見直し**。
  16,000 token では理論上限に届かないが、これが製品要件か DoS guard かは別論点
- **generate 段の分割 (段分割案)**。1 呼び出しは短くなるが総所要は延び、予算連鎖はむしろ厳しくなる。
  実測で「1 回では原理的に無理」と確定した場合に再検討する
- **`analysis_llm_max_retries` の 2→1 削減**。timeout 延長案とセットの緩和策であり、本方針では不要
- **render 段 (`RunManualRender`) の同種問題**。`database-render` 側は別 TODO

