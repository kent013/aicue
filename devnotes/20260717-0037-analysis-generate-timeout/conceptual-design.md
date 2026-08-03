# 概念設計: analysis-generate-timeout — generate timeout 調査・解消計画

> Round 1 レビュー (CHANGES_REQUESTED) を受けてリフレーム。
> 旧版は「streaming 導入案」だったが、実測 0 件で最も重い手段を第一候補に据えており、
> 設計文書自身の「値ではなく計測器を正すべき」という主張と自己矛盾していた。
> **本版の第一マイルストーンは「失敗した generate を測れるようにする」**。

## 設計目標

**generate を完走可能にし、その失敗要因を事実で分解できる状態を作る。**

(「streaming を入れる」ではない。streaming は実測が要求した場合にのみ採る手段のひとつ)

## 背景・課題

AI 解析パイプライン (`AnalysisPipeline`) の generate 段 (シナリオ生成) が、実測 2/2 で必ず失敗する。

```
cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received
for https://api.anthropic.com/v1/messages
```

`extract` (progress 10) → `decompose` (35) → `generate` (65) と進み、generate だけが落ちる。
結果、**このアプリの使命の中核である「AI が撮るべきカットを設計した動画シナリオを生成する」が一度も完走できていない**。

### 現状の構成

| 要素 | 値 | 根拠 |
|---|---|---|
| 呼び出し方 | 非ストリーミング (`$builder->asText()`) | `vendor/kent013/laravel-prism-prompt/src/Prompt.php:748` |
| max_tokens | 16,000 | `resources/prompts/scenario-generation.yaml:8` |
| client timeout | 120 秒 | `resources/prompts/scenario-generation.yaml:10` |

非ストリーミングでは **TTFB = 生成完了までの全時間**。かつ `client_options.timeout` は Guzzle CurlHandler 経由で
`CURLOPT_TIMEOUT_MS = 120000` にマップされる (`vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:1569`) =
**リクエスト全体の壁時計上限**。

したがって `0 bytes received` は構成上ありうる帰結であり、**「生成が 120 秒を超えた」以上の情報を持たない**。
extract / decompose が通るのは実出力が小さいためと推定される (3 段とも同じ `timeout:120` / `max_tokens:16000`)。

### 課題の本体 — 事実が構造的に観測できない

`llm_call_logs` は `output_tokens` / `duration_ms` 列を持つ
(`database/migrations/2026_06_11_090000_create_llm_call_logs_table.php:47,63`) が、記録契機である
`PromptExecutionCompleted` は `asText()` の **成功後にのみ発火する** (`Prompt.php:747-760`)。

**いま失敗している当の generate 呼び出しは、構造的に観測に載らない。** リポジトリ内に実測値は 0 件。

この状態では失敗要因が分解できない:

| 候補要因 | 現状 |
|---|---|
| 生成が単に 120 秒より長い (TTFB 起因) | 未確認 |
| 出力が max_tokens 16,000 に張り付いている | 未確認 |
| モデル応答が不安定 / provider 側の問題 | 未確認 |

そして要因によって妥当な打ち手が割れる:

| generate の実所要時間 | 妥当な打ち手 |
|---|---|
| 125 秒程度 | timeout 延長で解決。**streaming 不要** |
| 600 秒程度 | streaming + デッドライン + retries 削減 |
| max_tokens 張り付き | 出力量削減か段分割。timeout も streaming も延命にすぎない |

**どれなのかを誰も知らない。** `AGENTS.md` 思考原則「仕組みが機能していない段階で値を弄るな」
「データに真摯に向き合え」に照らし、**先に決められるのは観測だけ**である。

## 改善アイデア — 3 フェーズに分解

### Phase 1: 観測フェーズ ← **今回の実装スコープはここのみ**

**1-a. 失敗した LLM 呼び出しを観測に載せる**

upstream (`kent013/laravel-prism-prompt`) に `PromptExecutionFailed` 相当を追加し、
`asText()` の例外経路でも `llm_call_logs` に記録する。

- **なぜ upstream 側か**: 例外経路が vendor の `Prompt.php` にあり、アプリ側からは触れない。
  かつ **既存の責務分離と観測経路を維持するため** — `PromptExecutionCompleted` / `computeCost` /
  `Prompt::fake()` 分岐は基底側にあり、アプリ側に観測を再実装すると二重管理になる
- **upstream 化の採否基準** (本設計で新たに明文化): **アプリ内 workaround では観測責務・責務分離を
  満たせない場合にのみ package を変更する**。今回はこれに該当する
- 失敗イベント用に明示的な DTO を切り、成功系と union にせず保存責務側で分岐を閉じる (PHPStan level 10)

**1-b. timeout 意味論の先行スパイク**

streaming 時に Guzzle が `Proxy::wrapStreaming` で CurlHandler ではなく StreamHandler へ
ルーティングされる際の挙動を **実測で確定する**。これは Phase 3 の成立条件そのもの。

観測項目:
- PHP http stream context の `timeout` は per-read (ストール検知) か壁時計か
  — `StreamHandler::add_timeout` は `$options['http']['timeout']` に渡すのみ
  (`vendor/guzzlehttp/guzzle/src/Handler/StreamHandler.php:849-853`) でコード上は確定不能
- `read_timeout` の効き方 (`StreamHandler.php:434-438` が `stream_set_timeout` で適用)
- `connect_timeout` の扱い (`StreamHandler` に `add_connect_timeout` は存在しない)。接続失敗時に何秒で fail するか
- abort (途中中断) 時の挙動

**成果物はスパイクの記録**であり、本番コードへの streaming 導入は含まない。

### Phase 2: 検証フェーズ（今回スコープ外）

Phase 1 で得た実測をもとに、**一時診断策**として client timeout を延長し、
generate を完走させて実所要時間・実出力トークン数を確定する。

- `attempts` (= `config/manual.php` の `analysis_llm_max_retries`) は予約 TTL と独立した自由変数のため、
  retries を 2→1 に下げれば **予約 TTL 1800 に触れずに** T_generate <= 360 秒まで取れる
  (`AnalysisTimeBudgetInvariantTest` の式より)
- **これは恒久策ではない**。generate を 1 秒も速くせず、同じ壁時計の目盛りを伸ばすだけ。
  「生成が成立するか」を切り分けるための診断手段として位置づける

### Phase 3: 解決フェーズ（今回スコープ外）

Phase 1・2 の事実が要求した場合にのみ進む。**進入条件**:

- スパイクで timeout 意味論が per-read と確認できた (壁時計だった場合は本方針は崩れる)
- 実測が「timeout 延長では原理的に届かない」ことを示した

内容: upstream に `executeStream()` を追加 → アプリ側で generate 段をストリーミング化 +
アプリ層の壁時計デッドライン → 時間予算の不変条件を `T_idle` / `T_total` の 2 パラメータへ再設計。

**Phase 3 で決めるべき論点** (今回は決めない):
- デッドライン到達時に provider 側リクエストをどうキャンセルするか。
  **ジョブを fail させても外部 API 側で生成が継続し課金だけ発生する可能性がある**
- CurlHandler → StreamHandler 切替の受容条件 (接続失敗時の fail fast 時間など)
- ストリーミング中断を新例外で表現する場合、`withBoundedRetry` の対象に含めるか
  (含めると worst-case 式の係数が変わる)

## 期待効果

**Phase 1 の期待効果は「事実が手に入ること」に限定する**（完走可能性は Phase 3 の仮説）。

- generate の実所要時間・実出力トークン数・失敗理由が初めて観測可能になる。
  以降の全判断が事実ベースになる
- 失敗が `llm_call_logs` に載ることで、extract / decompose を含む 3 段すべての LLM 呼び出しについて
  同種の問題を将来検知できる
- timeout 意味論が確定し、Phase 3 の可否が机上ではなく実測で判定できる

**使命への貢献**: 直接ではなく間接。ただし「AI が動画シナリオを生成する」が 100% 失敗している現状で、
その原因を事実で分解できる状態を作ることは、解消への必須の前提である。

### 仮説（Phase 3 で検証する）

> generate の失敗が「120 秒間 1 byte も返らないこと」(TTFB 起因) を主因とするならば、
> streaming 化により回避できる可能性がある。

この仮説は Phase 1・2 の実測でのみ検証・反証できる。

## 実装方針（Phase 1 の概要）

| # | 対象 | 変更概要 |
|---|---|---|
| 1 | upstream: 失敗イベント | `PromptExecutionFailed` 相当を追加。`asText()` の例外経路で発火。失敗専用 DTO |
| 2 | upstream: リスナ | 失敗イベントを `llm_call_logs` に記録 (`failure_reason` 等) |
| 3 | アプリ: `llm_call_logs` | 失敗記録に必要な列の追加 (migration)。nullable 条件を詳細設計で確定 |
| 4 | スパイク | timeout 意味論の実測。記録を devnotes に残す (本番コード変更なし) |
| 5 | upstream: README | 追加イベントの記載 → タグ切り → push |

### 開発フロー (aigenba の前例に倣う)

開発中は submodule + composer path 参照で upstream のソースを直接参照する:

```
.gitmodules:   packages/laravel-prism-prompt → git@github.com:kent013/laravel-prism-prompt.git
composer.json: {"type": "path", "url": "./packages/laravel-prism-prompt"}
require:       "kent013/laravel-prism-prompt": "@dev"
```

動作確認 → README 更新 → タグ切り → push → 動作確認 の後、**マージ時には path 参照を切って
通常のバージョン依存に戻す**。submodule / path repository はマージ対象に残さない。

## 制約・前提

- **`AGENTS.md` 禁止事項 5**: LLM 呼び出しの Prism 直呼び禁止 (factory 経由のみ)。
  → upstream 側に実装することで遵守。`PromptGuardrailTest.ALLOWED_FILES` に穴を開けない
- **`AGENTS.md` 禁止事項 6**: prompt 文字列のコード直書き禁止 (`resources/prompts/*.yaml`)
- **`AGENTS.md` 思考原則 2**: 今必要なものだけ作る。→ Phase 1 に限定する理由そのもの
- **予約 TTL 1800 は変更しない** (`AnalysisTimeBudgetInvariantTest.php:22` に明記)。Phase 1 は TTL に触れない
- **Phase 1 は時間予算の不変条件を変更しない**。`AnalysisTimeBudgetInvariantTest` /
  `AnalysisTokenBudgetInvariantTest` は無変更で PASS し続ける
- **部分結果の途中永続化は採らない**。`finalize` の Running guard (`AnalysisPipeline.php:199-201`) と
  `failJob` の冪等性が支える「stale 回収先勝ち = 無課金 succeeded 排除」が崩れるため

## スコープ外

- **Phase 2 / Phase 3 の実装**。実測を得てから別途設計する
- **client timeout / max_tokens / retries の値変更**。Phase 2 の診断で扱う
- **generate 段の分割**。ただし **実測で「1 回生成の出力/時間が製品予算に収まらない」と判明した場合の
  有力代替として残す**。`ScenarioLimits::MAX_STEPS=100 × MAX_POINTS_PER_STEP=20` (最大 2,100 カット) は
  16,000 token では理論上限に届かず、この線は現実味がある
- **extract / decompose のストリーミング化**。同じ構成だが現に通っている
- **`ScenarioLimits` の見直し** (製品要件か DoS guard かの判断が別途必要)
- **render 段 (`RunManualRender`) の同種問題**。`database-render` 側は別 TODO
