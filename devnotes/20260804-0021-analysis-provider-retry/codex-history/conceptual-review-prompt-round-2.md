# Round 2: Round 1 指摘への対応と修正版概念設計

Round 1 の [Critical] 2 件・[Warning] 6 件・[Suggestion] 3 件をすべて捌きました。
対応方針は下記マトリクスのとおりです。とくに:

- **[Critical] budget 算術**: deadline の起点/終点を一意に定義し直し、`+180` の二重計上を解消。
  さらに旧案 (deadline=900) には「3 段飽和時に最終段が starve する」欠陥があったため、
  `deadline = 3 × client timeout` へ再定義しました。
- **[Critical] 40 token/s の根拠**: **本番 Anthropic API に対して実測しました** (n=3)。
  実測 53.4〜59.1 token/s。これを根拠に ceiling を 360s に決め直しています。

以下、対応マトリクスと修正版の概念設計全文です。再レビューをお願いします。
とくに (a) 新しい budget 連鎖の算術に穴がないか、(b) 実測の使い方が妥当か、
(c) retryable 例外の写像表に漏れ/誤りがないか、(d) 依然としてオーバーエンジニアリング/
過小になっていないか、を厳しく見てください。

---

# 対応マトリクス: conceptual-review Round 1

## [Critical] 時間 budget の算術が自己完結していない (deadline の起点/終点と `+180` の二重計上)
- 判断: **対応する**
- 根拠: 指摘は正しい。deadline が pipeline 全体 (抽出含む) を覆うのに、worst-case で
  「抽出/解析余裕 180s」を別枠で足していたのは旧モデル (試行回数の積) のラベルを
  引きずった二重計上だった。
- 対応内容: deadline の時計を「`AnalysisPipeline::run()` 入口 T0 から、各 LLM 試行の**開始判定**まで」
  と一意に定義し直した。deadline **超過後に走りうるのは「最後に開始した 1 回の LLM 呼び出し」だけ**なので
  worst-case は `deadline + client timeout + finalize マージン`。
  `+120` は抽出ではなく **deadline 通過後の finalize/failJob/通知/ロック待ちのマージン**として
  ラベルを付け替えた (抽出は deadline の内側。実測 0.4 秒未満)。
  さらに **「全 3 段が最低 1 回はフル ceiling で試行できる」** を構造的に保証するため
  `deadline = 3 × client timeout` と定義した (旧案の 900s は 3 段飽和時に第 3 段を
  starve させる欠陥があったため破棄)。

## [Critical] `40 token/s` を不変条件に固定する根拠が弱い (実測がない)
- 判断: **対応する (実測した)**
- 根拠: 指摘は正しい。推測値を CI に pin するのは AGENTS.md 思考原則に反する。
- 対応内容: **実測した**。`claude-sonnet-4-5-20250929` (prompt YAML の設定モデル) に対し
  非ストリーミングで日本語 JSON を `max_tokens: 4000` 飽和生成させ、3 回計測:
  **68.6s / 67.7s / 74.9s → 58.3 / 59.1 / 53.4 token/s** (2026-08-04, 本番 API)。
  この実測から ceiling を **360s** (= 16,000 token を 44.4 token/s 以上で完走できる時間、
  実測下限 53.4 に対し約 20% のマージン) と決め直した。同時に
  「120s は最大 6,400〜7,100 token 分しかカバーしない」ことが定量的に確定し、
  generate 段が決定論的に落ちる根本原因の裏取りになった。計測手順は設計書に記載する。

## [Warning] `retry_after` の余白 120s が薄い
- 判断: **反論する (現行値と同型であることを明記する)**
- 根拠: `retry_after` の役割は「worker の SIGALRM kill (`$timeout`) が redelivery より先に起きる」
  ことの保証のみ。`$tries = 1` なので redelivery は即 failed 化され (failJob は冪等)、
  二重処理は起きない。既存のレンダレーンも `1500 < 1680 < 1800` (余白 180/120) で運用実績があり、
  Laravel 既定 (`timeout 60 / retry_after 90` = 余白 30s) より厚い。
- 対応内容: 設計書に上記の根拠を明記。値は `1560 < 1680 < 1800` (余白 120/120) とする。

## [Warning] 期待効果を「290KB PDF の finding 解消」まで言うのは過大
- 判断: **対応する**
- 根拠: 同意。同 PDF は抽出テキストが文字化けしており、完走しても内容は無意味になりうる。
- 対応内容: 成功条件を「**timeout 起因の失敗の解消**」に限定して書き直し、
  文字化け defect を **blocking follow-up (別 TODO 必須)** として明記した。

## [Warning] retryable 例外集合が粗い (5xx / read timeout の写像が未確認)
- 判断: **対応する (ソースで写像表を作った)**
- 根拠: 指摘は正しい。型の写像を確認せずに集合を決めていた。
- 対応内容: vendor を読んで写像表を作成:
  - `CURLE_OPERATION_TIMEOUTED(28)` / `COULDNT_RESOLVE_HOST` / `COULDNT_CONNECT` /
    `SSL_CONNECT_ERROR` / `GOT_NOTHING(52)` → Guzzle `ConnectException`
    (`vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:711-717,765`)
    → Laravel `Illuminate\Http\Client\ConnectionException`
    (`vendor/laravel/framework/.../PendingRequest.php:1091-1092`)
  - HTTP 429 → `PrismRateLimitedException` / 529 → `PrismProviderOverloadedException` /
    413 → `PrismRequestTooLargeException` / **それ以外の 4xx・5xx は一律
    `PrismException::providerRequestErrorWithDetails`** (`Providers/Anthropic/Anthropic.php:79-95`)
  → 5xx を型で判別できないため、generic `PrismException` は retryable に含めない (fail-fast)。
  この判断と写像表を設計書に明記し、Architecture テストで retryable 集合を pin する。

## [Warning] 429 を timeout と同じ文言に混ぜない
- 判断: **対応する**
- 根拠: 同意。原因が違えばユーザーの次アクションも違う。
- 対応内容: 文言を 3 系統に分ける — timeout/deadline / provider 混雑 (429・529) / 入力過大 (413)。

## [Warning] 3 本の prompt を一律 360s にする根拠
- 判断: **対応する (根拠を明記)**
- 根拠: 3 本とも `max_tokens: 16000` で同一なので、出力上限から導出される ceiling は
  構造的に同一。段ごとに分けるのは根拠のない値の増殖 (思考原則 2)。
- 対応内容: 「3 本の `max_tokens` が一致することを Architecture テストで固定し、
  timeout も同値であることを固定する」を施策に追加。

## [Warning] `config()` 由来の値が PHPStan level 10 で `mixed` になる
- 判断: **対応する (既存イディオムに揃える)**
- 根拠: 本リポジトリは既に `config()->integer('manual.analysis_llm_max_retries')` の
  typed accessor イディオムで統一されている (`AnalysisPipeline.php:120,245` 他)。
  専用 value object を新設するのはオーバーエンジニアリング。
- 対応内容: 新設 config も `config()->integer('manual.analysis_deadline_seconds')` で読む、と明記。

## [Suggestion] 失敗理由を enum / reason code で持つ
- 判断: **見送る**
- 根拠: 表示側 (`AnalysisPanel.svelte:294-297`) は `failedJob.error` の文字列をそのまま
  `Alert` に出すだけで、種別による分岐を持たない。enum を足しても消費者がいない
  (思考原則 2)。サーバ側の分岐は既存の `userMessageFor()` の `match(true)` による
  **例外型ディスパッチ**で行うため文字列比較は発生せず、型安全性の懸念も無い。
- 対応内容: 見送る旨を設計書の「却下した代案」に明記。

## [Suggestion] 「`client_options.timeout` が `config('prism.request_timeout')` を上書きする」を docs/test に明文化
- 判断: **対応する**
- 対応内容: `docs/architecture.md` の解析節と Architecture テストのコメントに明記する、を施策に追加。

## [Suggestion] 実装時も `response()->json()` 直書きに逃げない
- 判断: **対応する (該当なしを明記)**
- 対応内容: 本設計は Controller / Resource / route を一切変更しない旨を明記。


---

# 修正版 概念設計 (全文)

# 概念設計: AI 解析の時間 budget 是正と provider 例外の有界リトライ (F-1-01)

- 対象 finding: `devnotes/20260803-203721-bug-hunt/report.md` §High F-1-01
  (shard 詳細: `devnotes/20260803-203721-bug-hunt/shard-1/shard-report.md#F-1`)
- task_key: C
- 改訂: Round 1 レビュー反映 (時間 budget の再定義 / 生成レートの実測 / 例外写像表)

## 背景・課題

bug-hunt が「リポジトリ同梱のサンプル SOP (`doc/reference/sample-sop/AS_作業手順書.pdf`) で
AI 解析の generate 段が 120,002ms でタイムアウトし 2/2 で失敗する」を観測した。
`cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received`。
North Star の起点 (「SOP から AI がカット設計する」) が実運用サイズで機能しない。

### 事実確認 (すべてソース or 実測で裏取り済み。推測なし)

**(1) 実効タイムアウト 120s の出所 = 確定した。ssrf-pin は無関係。**

bug-hunt は「`config/prism.php` の `request_timeout` は 30s なのに観測は 120s。
`laravel-ssrf-pin` の transport 側 deadline が絡む可能性」としていたが、**ssrf-pin は経路にいない**。
実際の経路 (全て vendor / repo のコードを読んで確認):

| # | 位置 | 内容 |
|---|---|---|
| 1 | `resources/prompts/{sop-extract,work-decomposition,scenario-generation}.yaml` | `client_options: { timeout: 120 }` |
| 2 | `vendor/kent013/laravel-prism-prompt/src/Prompt.php:1076-1089` `resolveClientOptions()` | YAML の `client_options` をそのまま返す |
| 3 | 同 `Prompt.php:742-745` `executePrism()` | `$builder->withClientOptions($resolvedClientOptions)` |
| 4 | `vendor/echolabsdev/prism/src/Concerns/InitializesClient.php:16-21` | `Http::...->timeout(config('prism.request_timeout'))->connectTimeout(...)` = 30s |
| 5 | `vendor/echolabsdev/prism/src/Providers/Anthropic/Anthropic.php:112-121` | `$this->baseClient()->...->withOptions($options)` |

Laravel HTTP client の `timeout()` は Guzzle option `timeout` を書くだけなので、
**手順 5 の `withOptions(['timeout' => 120])` が手順 4 の 30 を上書きする**。
よって実効タイムアウトは **YAML の 120 秒**。観測値 120,002ms と完全に一致する。
`config/prism.php` の 30s は解析経路では一切効いていない。

なお 120 という値は `AnalysisTokenBudgetInvariantTest` (YAML 側) と
`AnalysisTimeBudgetInvariantTest` (worst-case 算術側) が二重に pin しており、
片方だけ動かすと CI が落ちる (= 設計として連鎖している値)。

**(2) 120s では原理的に足りない。生成レートを実測して定量確定させた。**

- 解析 3 プロンプトはいずれも `max_tokens: 16000`。
- 呼び出しは **非ストリーミング**の単発 HTTP (`Prism::text()->asText()`)。
  Anthropic は非ストリーミングでは生成完了までレスポンス本体を返さないため、
  1 回の呼び出しの実時間 ≒ **出力 token を生成し切るまでの時間**。
- 観測ログの `with 0 bytes received` は「生成中でまだ 1 バイトも返っていない」状態であり、
  ネットワーク瞬断ではなく **deadline 不足の署名**である。

**実測 (2026-08-04, 本番 Anthropic API, `claude-sonnet-4-5-20250929` = prompt YAML の設定モデル)**:
日本語 JSON を `max_tokens: 4000` まで飽和生成させ、非ストリーミングで 3 回計測。

| run | 実時間 | output_tokens | stop_reason | レート |
|---|---|---|---|---|
| 1 | 68.6s | 4,000 | max_tokens | 58.3 token/s |
| 2 | 67.7s | 4,000 | max_tokens | 59.1 token/s |
| 3 | 74.9s | 4,000 | max_tokens | 53.4 token/s |

→ 実測レンジ **53.4〜59.1 token/s**。ここから:

- **120s がカバーできるのは約 6,400〜7,100 output token** に過ぎない。
  `max_tokens: 16000` の 45% 未満であり、**上限まで使う段は必ず落ちる**。
- generate 段の出力は `GeneratedScenarioData` のスキーマ上、cut 1 件あたり
  scene(≤1000 字) / narration(≤2000 字) / subtitle_secondary(≤2000 字) 等を持つ。
  現実的な SOP (数十手順) では容易に 7,000 token を超える。
- 194 バイトの極小 SOP が 50 秒で成功したのは出力が数百 token で済んだためであり、
  **入力サイズ依存ではなく「出力 token 量依存」**である (bug-hunt の「サイズ依存」の正体)。

つまり根本原因は **「120s は max_tokens 16000 の生成時間をカバーしていない」**。
リトライの有無以前に、成功しうる時間予算が与えられていない。

> 計測スクリプトは使い捨てのため保存していない。再現手順:
> `POST https://api.anthropic.com/v1/messages` に
> `{model: claude-sonnet-4-5-20250929, max_tokens: 4000, messages:[日本語 JSON を大量生成させる指示]}`
> を非ストリーミングで送り、wall-clock と `usage.output_tokens` の比を取る。

**(3) provider/connection 例外はリトライ対象外 = 事実。ただしコメント矛盾の指摘は半分不正確。**

`AnalysisPipeline::withBoundedRetry` は `LlmOutputInvalidException` のみ catch する
(`app/Services/Manual/AnalysisPipeline.php:243-255`)。`ConnectionException` は素通りして
`run()` の catch → `failJob` へ落ちる。ここは bug-hunt の指摘どおり。

一方 `RunManualAnalysis` の「LLM 3 段 × 3 試行 × client timeout 120s = 1,080s」コメントは
**JSON 検証失敗リトライ (`analysis_llm_max_retries=2` = 計 3 試行) の worst-case 予算**として
書かれており、実装と矛盾はしていない (`AnalysisTimeBudgetInvariantTest` が同じ式を CI 固定)。
誤解を招く書き方ではあるので文言は直すが、「実装とコメントが矛盾している」という前提で
設計判断を組み立てるのは誤り。

**(4) 290KB PDF は token budget 内に収まっている (= 事前拒否は正解ではない)。**

`SopTextExtractor` は PDF → テキスト抽出 → 正規化後の `strlen` を
`manual.analysis_max_text_bytes` (150,000) と比較する。
実測 (`smalot/pdfparser` で同一処理を再現) の結果、
`AS_作業手順書.pdf` (290,498 bytes) の抽出テキストは **6,451 bytes / 3,292 文字**
(抽出所要時間 0.4 秒未満)。上限の 5% 未満であり、**入力側の budget 超過ではない**。
よって「事前の拒否」は本 finding の解ではない。

**(5) [scope 外・blocking follow-up] 同 PDF の抽出テキストは文字化けしている。**

同じ再現で、抽出テキストが CP932 バイト列を Latin-1 として解釈した典型的な mojibake
(`ì‹ÆŽè‡‘` … CP1252 → CP932 で復元すると `作業手順書`) であることを確認した。
これは **valid な UTF-8 になる**ため `SopTextExtractor::ensureUtf8()` の
`mb_check_encoding($text, 'UTF-8')` を通過し、そのまま LLM に渡っている。

本設計の scope 外 (別 defect) だが、**本設計だけでは同 PDF の解析結果は
「時間内に完走するが内容は無意味」になる**。したがって本施策の成功条件は
**「timeout 起因の失敗の解消」に限定**し、文字化けは **blocking follow-up として
必ず別 TODO を起票する** (§スコープ外 / open questions)。

## 改善アイデア

**「1 回の LLM 呼び出しに、出力 token 上限を生成し切れるだけの時間を与える」** ことを
主軸に据え、増えた時間予算が既存の連鎖 (job timeout < retry_after < 予約 TTL ≤ stale) を
壊さないように、**リトライの打ち切りを『試行回数』から『試行回数 ∧ 実時間 deadline』へ**
変える。そのうえで provider/connection 例外を有界リトライ対象に含め、
失敗時はユーザーが次に取れる行動を提示する。

1. **client timeout を出力 token 上限と実測レートから導出した値へ引き上げる** (120 → **360 秒**)。
   360s は 16,000 token を **44.4 token/s 以上**で完走できる時間。実測下限 53.4 token/s に対し
   約 20% のマージンがある (実測 53.4 なら 16,000 token は 300 秒)。
   360s を超える段は出力が実質 `max_tokens` 到達 = JSON が途中で切れて
   どのみち検証に落ちる状態なので、**fail-fast させるのが正しい**。
2. **有界リトライを deadline 制にする**。パイプライン開始時刻 T0 から
   `manual.analysis_deadline_seconds` の実時間予算を持ち、
   **各試行の開始前**に残予算を検査する。残っていなければ即 timeout 扱いで failJob。
   → worst-case が「3 段 × 試行数 × timeout」の**積**ではなく
   **`deadline + client timeout 1 回分`** に変わり、時間予算の爆発を防げる。
   deadline は **`3 × client timeout` (= 1,080 秒)** と定義する。これにより
   **「3 段すべてが最低 1 回はフル ceiling で試行できる」**ことが構造的に保証される
   (deadline を ceiling の 3 倍未満にすると、飽和ジョブで最終段が starve する)。
3. **provider/connection 例外を有界リトライ対象に含める** (transient と型で断定できるものだけ)。
4. **失敗文言を理由で 3 系統に分岐する (H4)**。
   表示側 (`AnalysisPanel.svelte:294-297` は `failedJob.error` をそのまま `Alert` に出す) は
   **フロント変更不要**。
5. **時間 budget の連鎖を引き直し、CI 固定を新しい算術に合わせる**。
   予約 TTL (1,800s) と stale 閾値 (30 分) は **据え置ける値**を選ぶ (下表)。

### retryable 例外集合 (vendor のソースから写像を確定させた)

| provider 側の事象 | 到達する例外型 | 写像の根拠 | 扱い |
|---|---|---|---|
| cURL 28 (operation timed out) / 6 (resolve) / 7 (connect) / 35 (SSL connect) / 52 (got nothing) | `Illuminate\Http\Client\ConnectionException` | `guzzlehttp/guzzle/src/Handler/CurlFactory.php:711-717,765` が上記 errno を `ConnectException` にし、`laravel/framework/.../Http/Client/PendingRequest.php:1091-1092` が `ConnectionException` へ marshal | **retryable** |
| HTTP 529 (overloaded) | `Prism\Prism\Exceptions\PrismProviderOverloadedException` | `Providers/Anthropic/Anthropic.php:79-95` | **retryable** |
| LLM 出力 JSON の検証失敗 | `App\Exceptions\Manual\LlmOutputInvalidException` | 既存 | **retryable** (現行維持) |
| HTTP 429 (rate limited) | `PrismRateLimitedException` | 同上 | 非 retryable (専用文言) |
| HTTP 413 (request too large) | `PrismRequestTooLargeException` | 同上 | 非 retryable (専用文言) |
| その他の 4xx / 5xx | `PrismException` (generic) | `handleResponseErrors()` が status を問わず同一型 | **非 retryable** |

**5xx を retryable にできない理由**: Anthropic provider は 429/529/413 以外を
status に依らず generic `PrismException` に潰すため、
「一時的な 502」と「決定論的な 400 リクエスト不正」を**型で区別できない**。
区別できないものを retryable に入れると 400 を無駄に投げ直すので、fail-fast に倒す
(区別が必要になったら Prism 側の型追加を待つ)。

## 期待効果

- **成功条件 (本施策のスコープ)**: **timeout / provider 例外起因の解析失敗が解消される**こと。
  具体的には (a) `max_tokens` 上限まで使う段でも 1 回の呼び出しが打ち切られない、
  (b) 単発の接続断・529 で即 failJob しない、(c) 失敗時に次アクションが提示される。
- **使命への貢献**: North Star の起点である「SOP → カット設計」が
  timeout を理由に落ちなくなる。
  ただし **「サンプル PDF で有意味なシナリオが得られる」ことは本施策では保証しない**
  (文字化け defect の解消が前提。§スコープ外)。
- 単発の外部 API 瞬断でチケット消費フローが止まらない。
- `config/prism.php` の 30s が解析経路で効いていないという**現行実装の誤解の余地**を、
  docs とテストのコメントで解消する。

## 実装方針（概要）

| 変更対象 | 内容 |
|---|---|
| `resources/prompts/*.yaml` (解析 3 本) | `client_options.timeout: 120 → 360` |
| `config/manual.php` | `analysis_deadline_seconds` (1080) を追加 |
| `config/queue.php` | `database-analysis.retry_after: 1560 → 1680` |
| `app/Jobs/Manual/RunManualAnalysis.php` | `$timeout: 1380 → 1560`、budget コメントを新算術へ |
| `app/Services/Manual/AnalysisPipeline.php` | deadline の生成と伝播 / `withBoundedRetry` の retryable 判定 + deadline guard / `userMessageFor` の分岐 |
| `app/Exceptions/Manual/AnalysisFailedException.php` | `timedOut()` / `providerBusy()` を追加 |
| `tests/Architecture/AnalysisTimeBudgetInvariantTest.php` | 新しい連鎖・導出・retryable 集合を固定 |
| `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | YAML timeout の pin 値を新値へ / 3 本の値一致 |
| `tests/Feature/Projects/AnalysisPipelineTest.php` | provider 例外リトライ / deadline 打ち切り / チケット会計不変条件 |
| `tests/Support/` | 例外を投げられる `PromptFake` 派生 (テスト用 double) |
| `docs/architecture.md` | 解析の時間 budget 連鎖 (L191/L195) と「YAML が prism.request_timeout を上書きする」旨を更新 |

**フロントエンド (Svelte/TS)・Controller・DTO / JsonResource・route の変更は無し**
(表示側はサーバ文言をそのまま出すため)。`response()->json()` の新規使用も無し。

## 制約・前提

### 時間 budget の連鎖 (新旧)

deadline の時計の定義を先に固定する:

- **T0** = `AnalysisPipeline::run()` の入口。
- deadline = `T0 + analysis_deadline_seconds`。
- **判定点は「各 LLM 試行の開始直前」のみ**。deadline を過ぎていれば試行を開始せず
  `AnalysisFailedException::timedOut()` を投げる。
- 走行中の呼び出しを中断はしない (中断は Guzzle の client timeout が担う)。
  したがって **deadline 通過後に走りうるのは高々 1 回分の client timeout**。
- SOP テキスト抽出 (実測 0.4 秒未満) は deadline の**内側**に含む。

| 項目 | 現行 | 新 | 根拠 |
|---|---|---|---|
| 1 呼び出しの client timeout (C) | 120s | **360s** | 16,000 token を 44.4 token/s で完走できる時間。実測下限 53.4 token/s に対し約 20% マージン |
| パイプライン deadline (D) | (なし) | **1,080s** = 3C | 3 段すべてにフル ceiling の 1 回を保証する最小値 |
| finalize マージン (M) | — | **120s** | deadline 通過後の terminal tx (2 行ロック) + チケット commit/release + 通知 + `report()`。実処理は秒未満 |
| job `$timeout` | 1,380s | **1,560s** = D + C + M | worst-case wall-clock と一致 |
| queue `retry_after` | 1,560s | **1,680s** | job timeout < retry_after (レンダレーンと同値・運用実績あり) |
| 予約 TTL | 1,800s | **1,800s (据置)** | `TicketLedgerService::RESERVATION_TTL_MINUTES` を触らない |
| stale 閾値 | 30 分 | **30 分 (据置)** | `manual.analysis_stale_after_minutes` を触らない |

**C = 360 の上限性**: `T = 4C + M < retry_after < TTL(1800)` を満たしつつ TTL を据え置くには
`4C ≤ 1440` すなわち **C ≤ 360** が必要。360 は「TTL/stale を動かさずに取れる最大の ceiling」である。

**retry_after の余白 120s で十分な理由**: `retry_after` の役割は
「worker の SIGALRM kill (`$timeout`) が queue の再可視化より先に起きる」ことの保証のみ。
`$tries = 1` なので再配送されても即 `failed()` → `failJob` (冪等) で終わり二重処理にならない。
既存のレンダレーン (`1500 < 1680 < 1800` = 余白 180/120) と同型で、
Laravel 既定 (`timeout 60 / retry_after 90` = 余白 30s) より厚い。

**この値選びの要点**: 予約 TTL と stale 閾値を据え置けたことで、
チケット台帳 (`TicketLedgerService`) と stale 回復 cron の運用契約に**一切手を入れない**。
影響範囲は解析レーンの中に閉じる。

**受容するトレードオフ**: deadline は 3 段で共有するため、前段が retry を使うと
最終段が starve して `timedOut()` になりうる。これは「合計 18 分を超えた」ことの
正しい打ち切りであり、段ごとの個別予算 (機構の増殖) は今回作らない。

### チケット 2 フェーズとの関係 (セキュリティ不変条件 #7)

リトライがチケット会計を壊さないことは、現行の構造から**構造的に保証される**:

- 予約 (`reserve`) は `startJob()` の中で 1 回だけ行われ、冪等キーは
  `analysis_jobs.ticket_reservation_id` (`ensureReservation`)。
- 本設計で増えるリトライは **`runExtractStep` / `runDecomposeStep` / `runGenerateStep` の内側**、
  すなわち `startJob()` の後・`finalize()` の前に閉じている。
  リトライ経路は予約行を読みも書きもしない。
- `commit` は `finalize()` の terminal tx 内で 1 回、`release` は `failJob()` の中で 1 回。
  どちらも `analysis_jobs` 行ロック + terminal guard を通るため、
  何回リトライしても commit/release は高々 1 回。
- したがって「無課金 succeeded」「課金済み failed」「二重課金」はいずれも発生しない。
  これを Feature テストで明示的に固定する。

**逆に、ジョブ再配送 (`tries`) を増やす案は採らない**。再配送はチケット会計と
stale 回復の直列化点を跨ぐため、リトライ予算を増やす軸としては最も危険である
(`$tries = 1` は §10.8-1 の意図的な設計。据え置く)。

### フレームワークのレンジ内で収める (思考原則 1)

- タイムアウト値は `resources/prompts/*.yaml` の `client_options` = **prism-prompt 公式の作法**
  (`Prompt::resolveClientOptions()` が読む場所) で指定する。自前の HTTP 層は作らない。
- 設定値の読み出しは既存イディオムの `config()->integer(...)` (typed accessor) を使う。
  PHPStan level 10 で `mixed` にならない。
- リトライは `AnalysisPipeline` 内の既存 `withBoundedRetry` を拡張するだけで、
  Laravel の queue retry / Prism の `clientRetry` には手を出さない
  (どちらもチケット 2 フェーズや deadline 制御と噛み合わない)。
- **ストリーミング化は今回採らない** (下記「却下した代案」)。

### 却下した代案

| 代案 | 却下理由 |
|---|---|
| **ストリーミング化** (`Prism::text()->asStream()`) | 本命の正攻法だが**現在のレンジ外**。`kent013/laravel-prism-prompt` の `Prompt` はストリーム実行 API を公開していない (`executeSync` / `execute` / `executePrism` のみ。`grep` で確認)。使うには Prism 直呼びが必要で **AGENTS.md 禁止事項 5 (`PromptGuardrailTest` が検出)** に抵触する。パッケージ側に stream 実行が入ったら再検討 (§スコープ外)。 |
| **`max_tokens` を下げて生成時間を縮める** | 出力が途中で切れる → JSON 不正 → リトライしても同じ位置で切れる、という決定論的失敗に変わるだけ。悪化。 |
| **`analysis_max_text_bytes` を下げて事前拒否する** | 今回の PDF は上限の 5% 未満で、拒否しても救われない。「入力バイト → 出力 token」の実測換算係数を持たない以上、根拠のない上限は**正当な SOP を誤って拒否する**。 |
| **すべての `PrismException` を retryable にする** | 400 系 (リクエスト不正) を無限に投げ直す。型で transient と断定できるものだけに限定する (写像表)。 |
| **429 を `retry-after` 秒スリープして再試行** | worker を占有したまま眠るのは deadline 予算と相性が悪く、今必要でもない。専用文言で「時間をおいて再実行」に倒す。 |
| **予約 TTL / stale 閾値を伸ばす** | 上表の値選びで不要になった。伸ばすと worker 異常終了時に manual が `analyzing` で長時間ロックされ UX が悪化する。 |
| **ジョブ再配送 (`tries` > 1) でリトライ** | チケット 2 フェーズと stale 回復の直列化点を跨ぐ。§10.8-1 の意図に反する。 |
| **失敗理由の enum / reason code を新設** | 表示側は `failedJob.error` の文字列をそのまま出すだけで種別分岐を持たず、消費者がいない (思考原則 2)。サーバ側の分岐は既存 `userMessageFor()` の `match(true)` = **例外型ディスパッチ**で行うため文字列比較は発生しない。 |
| **段ごとに個別の時間予算を持つ** | 値と機構が増える割に、合計上限が守られていれば UX 上の差は無い。今は作らない。 |

## スコープ外

- **PDF テキスト抽出の文字化け (上記 (5))**。本 finding とは独立した defect。
  `SopTextExtractor::fromPdf()` / `ensureUtf8()` と `smalot/pdfparser` の CMap 処理が対象。
  **blocking follow-up として別 TODO を必ず起票する**。本設計の施策はこれを直さない。
- LLM 呼び出しのストリーミング化 (パッケージ側の対応待ち)。
- レンダ (`RenderPipeline`) 側の時間 budget。今回は触らない。
- `analysis_max_text_bytes` / `max_tokens` の見直し。
- フロントエンド (`AnalysisPanel.svelte`) の表示ロジック。サーバ文言をそのまま出すため不要。
