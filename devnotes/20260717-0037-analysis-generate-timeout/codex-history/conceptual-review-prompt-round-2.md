# Round 2: 概念設計の修正版レビュー

Round 1 の指摘 (CHANGES_REQUESTED) を受けて概念設計を全面的にリフレームしました。
**Critical 4件・Warning 9件すべてを「対応する」で受け、反論はゼロです。**

最大の変更点: 「streaming 導入案」→「generate timeout 調査・解消計画」へのリフレーム。
第一マイルストーンを「失敗した generate を測れるようにする」に置き直し、
**今回の実装スコープを Phase 1 (観測 + timeout 意味論スパイク) のみに限定**しました。
streaming は実測が要求した場合にのみ進む Phase 3 へ後退させています。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

Codex 判定: `CHANGES_REQUESTED`。Critical 4件・Warning 9件・Suggestion 5件。
**全 Critical を「対応する」で受ける。反論はゼロ。** 指摘は設計文書自身の主張との自己矛盾を突いており、正当。

## [Critical] 実測 0 件で streaming 化を決めるのは正しくない / observability を先に

- 判断: **対応する**
- 根拠: 指摘のとおり自己矛盾していた。設計文自身が「値ではなく計測器を正すべき」「データに真摯に向き合え」と
  書きながら、実測 0 件で upstream 拡張という最も重い手段を第一候補に据えていた。
  数字で見ても方針が割れる: generate の実所要が 125 秒なら timeout 延長だけで解決し streaming は不要、
  600 秒なら streaming + retries 削減が要る。**どちらかを誰も知らない**以上、先に決められるのは観測だけ。
- 対応内容: 概念設計を「streaming 導入案」から **「generate timeout 調査・解消計画」** にリフレーム。
  第一マイルストーンを「失敗した generate を測れるようにする」に置き直した。
  streaming は「実測が要求した場合にのみ進む Phase 3」へ後退させ、今回のスコープから外した。

## [Critical] 実現可能性の中核前提が未確定 (stream context の timeout 意味論)

- 判断: **対応する**
- 根拠: 「概念設計の成立条件そのもの」という位置づけに同意。ここが壁時計だと `read_timeout` 明示なしには
  streaming 化しても何も解決せず、Phase 3 の前提が崩れる。詳細設計と並行にはできない。
- 対応内容: 先行スパイク (timeout / read_timeout / abort 挙動の実測) を **Phase 1 のスコープに含めた**。
  Phase 3 への進入条件を「スパイクで意味論が per-read と確認できた場合のみ」と明文化。

## [Critical] 期待効果が強すぎる (「streaming 化すれば完走可能になる」)

- 判断: **対応する**
- 根拠: 現状の事実は「120 秒壁時計で 0 bytes」のみ。TTFB 起因か出力量過大かモデル応答不安定かが未分離。
- 対応内容: 期待効果を仮説として記述し直した。Phase 1 の期待効果は「事実が手に入ること」に限定し、
  完走可能性は Phase 3 の仮説へ移した。

## [Critical] 観測改善と送信方式変更の同時投入 / スコープ過大

- 判断: **対応する**
- 根拠: 「何が効いたか分からなくなる」は思考原則「結果から学ぶ」に直接反する。
- 対応内容: 3 フェーズに分解し、**今回の実装スコープを Phase 1 のみ**に限定した。

## [Warning] 設計目標を「streaming を入れる」から置き直せ

- 判断: **対応する**
- 対応内容: 目標を「generate を完走可能にし、その失敗要因を事実で分解できる状態を作る」に変更。

## [Warning] upstream 改修の理由を「guardrail 回避」ではなく「責務分離と観測経路の維持」と言い換えよ

- 判断: **対応する**
- 根拠: 指摘のとおり。guardrail は結果であって理由ではない。
- 対応内容: 文言を修正。あわせて **upstream 化の採否基準** を明文化した
  (「アプリ内 workaround では観測責務・責務分離を満たせない場合のみ package 変更」)。

## [Warning] timeout 延長 + retries 削減を「一時診断策」として位置づけよ

- 判断: **対応する**
- 根拠: 恒久策として退けたのは妥当だが、切り分け手段としてまで退けたのは強すぎた。
- 対応内容: Phase 2 の診断手段として明示的に位置づけた (恒久策ではないと併記)。

## [Warning] 段分割を実測後の有力代替として残せ

- 判断: **対応する**
- 対応内容: スコープ外の記述を「実測で 1 回生成が製品予算に収まらないと判明した場合の有力代替」に変更。

## [Warning] deadline 到達時の provider 側キャンセル・課金

- 判断: **対応する（Phase 3 の論点として記録）**
- 根拠: 重要な指摘。ジョブを fail させても外部 API 側で生成継続 → 課金だけ発生しうる。
  ただし Phase 3 でしか発生しない論点であり、今回の実装スコープには入らない。
- 対応内容: 「Phase 3 で決める論点」に明記。Phase 1 では deadline を導入しないため発生しない。

## [Warning] CurlHandler → StreamHandler 切替の受容条件を先に置け

- 判断: **対応する（Phase 1 スパイクの観測項目に落とす）**
- 対応内容: スパイクの観測項目に `connect_timeout` の扱い・接続失敗時の fail fast 時間を追加。
  受容条件は実測後に Phase 3 の設計で確定する。

## [Warning] 失敗イベントの payload 形状差と PHPStan level 10

- 判断: **対応する**
- 対応内容: 失敗イベント用に明示的 DTO を切り、成功系と union にせず保存責務側で分岐を閉じる方針を明記。
  `duration_ms` / `output_tokens` / `failure_reason` の nullable 条件を詳細設計で確定する旨を記載。

## [Warning] アプリ内で解ける範囲を切り分けよ / 失敗観測は streaming と分離して評価せよ

- 判断: **対応する**
- 根拠: フェーズ分割そのもの。
- 対応内容: Phase 1 = 観測、として分離済み。ただし失敗観測も upstream 側の実装になる点は変わらない
  (`asText()` の例外経路が vendor 側にあるため)。この点は設計に明記した。

## [Suggestion] 各種

- 判断: 概ね取り込み。`LlmCallLog` の nullable 条件整理は詳細設計フェーズで扱う。


---

## 修正後の概念設計（全文）

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


---

## Round 2 で確認してほしいこと

1. Round 1 の Critical 4件が実際に解消されているか (形だけの対応になっていないか)
2. Phase 1 のスコープは適切か。**過小になっていないか** — 「観測だけ入れて終わり、解析は依然 failed のまま」
   という状態でマージすることの是非。使命への貢献が間接にとどまる点をどう評価するか
3. Phase 1 でも結局 upstream (別リポジトリ) に手を入れる点。「アプリ内で解ける範囲を切り分けよ」という
   Round 1 の指摘に対し、「失敗イベントは vendor の例外経路にしか置けない」という理由で
   upstream 改修を維持しています。この理由は妥当か。アプリ側で解く道 (例: AnalysisPipeline の catch 節で
   自前記録) を不当に退けていないか
4. Phase 3 への「進入条件」の置き方は妥当か
5. 新たに見落としている Critical はないか

全体判定: APPROVED / CHANGES_REQUESTED で答えてください。
