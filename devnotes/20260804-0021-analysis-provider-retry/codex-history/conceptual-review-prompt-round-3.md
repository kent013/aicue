# Round 3: Round 2 指摘への対応と修正版概念設計

Round 2 の [Critical] 1 件・[Warning] 6 件・[Suggestion] 3 件をすべて捌きました。

とくに:
- **[Critical] 360 秒の根拠**: `max_tokens: 16000` を実際に飽和させる追加実測を行い、
  **273.9 秒 / 16,000 token / 58.4 token/s** を得ました (4,000 token 時と同レンジ = 線形性の確認)。
  そのうえで 360 秒を「保証 ceiling」から **「観測に基づく運用上限 (実測 274 秒の 1.31 倍)」** へ
  格下げし、「360 秒超過 = JSON 途中切れ」という誤った因果の主張を削除しました。
  CI では生成レートを pin せず、timeout の一致と budget 順序のみ固定します。
- **[Warning] job timeout の余白**: M を `finalize モデル予算 30s` + `安全余白 90s` に分解し、
  モデル上限 1,470s に対し job timeout 1,560s = **明示的に 90 秒の余白**があることを示しました。
- **[Warning] SIGALRM 時の予約 release**: Laravel の `Worker::registerTimeoutHandler` →
  `markJobAsFailedIfWillExceedMaxAttempts` (`$tries=1` かつ `attempts()=1` で成立) →
  `$job->fail()` → `RunManualAnalysis::failed()` → `AnalysisJobService::failJob()` という経路を
  ソースで確定させ、commit 前/後/重複実行の 3 分岐を明記しました。
- **[Warning] 5xx の status 取得**: `PrismException` の `previous` が
  `Illuminate\Http\Client\RequestException` であることを確認し、
  型安全に status を読めるので **408/500/502/503/504 を retryable に追加**しました。

以下、対応マトリクスと修正版の概念設計全文です。
残る [Critical]/[Warning] があれば指摘してください。無ければ APPROVED をお願いします。

---

# 対応マトリクス: conceptual-review Round 2

## [Critical] n=3 / 4,000 token の測定から 16,000 token の ceiling を断定するのは強すぎる
- 判断: **対応する (追加実測 + 表現の格下げ)**
- 根拠: 指摘は正しい。線形性・実プロンプト・分散のいずれも未検証で、
  さらに「360 秒超過 = どのみち JSON 途中切れ」という因果は成立しない
  (provider 遅延で 16,000 未満の正常出力が 360 秒を超えうる)。
- 対応内容:
  1. **`max_tokens: 16000` を実際に飽和させる追加実測を行った** (2026-08-04 JST):
     **273.9 秒 / output_tokens 16,000 / 58.4 token/s**。4,000 token 時の 53.4〜59.1 token/s と
     同レンジで、少なくとも 4,000→16,000 の範囲でレートはほぼ一定だった。
     → 「16,000 token 飽和呼び出しの実測所要時間 = 約 274 秒」という**直接の観測**が取れた。
  2. 360 秒を **「保証 ceiling」ではなく「観測に基づく運用上限」** と表現し直した
     (実測 274 秒に対し約 1.31 倍の余裕)。
  3. **「360 秒超過 = JSON 途中切れ」という因果の主張を削除**した。
     360 秒超過は「今回の観測レンジを外れた遅延」であり、fail-fast はその場合の
     打ち切り方針にすぎない、と書き換えた。
  4. **CI では生成レートを不変条件にしない**。Architecture テストで固定するのは
     (a) 解析 3 本の `max_tokens` と `client_options.timeout` の一致、
     (b) budget の順序関係と `D = 3C` / `T ≥ D + C + M` の一貫性 のみ。
     実測値は設計書と実装コメントに根拠として残す (CI では pin しない)。
- 残る未検証 (設計書に明記): 実 3 プロンプトでの実測、混雑時間帯の分散、TTFT の分布。
  ceiling の 1.31 倍マージンでこれを吸収する判断とし、超過時は timeout 文言で
  ユーザーに次アクションを出す。

## [Suggestion] 計測日にタイムゾーンを付けよ
- 判断: **対応する**
- 対応内容: 全計測を `2026-08-04 JST (= 2026-08-03 UTC)` と表記。

## [Warning] `job timeout = D + C + M` の完全一致には安全余白がない
- 判断: **対応する (M を分解して余白を明示)**
- 根拠: 指摘は正しい。「M=120」を finalize のモデル値として書いていたため、
  モデル外要因 (タイマー精度・シグナル配送・ログ) の余白がゼロに見えた。
- 対応内容: M を **`finalize モデル予算 30 秒` + `安全余白 90 秒`** に分解した。
  モデル上の上限は `D + C + 30 = 1,470 秒`、job `$timeout` は `1,560 秒`で、
  **明示的に 90 秒の余白**がある。値そのものは変えていない (TTL 据え置きを維持できる)。
  finalize 実処理 (行ロック 2 本の tx + チケット commit/release + 通知) は
  ミリ秒〜秒オーダーであり 30 秒予算で十分。

## [Warning] SIGALRM 強制終了時の予約 release が証明されていない
- 判断: **対応する (Laravel のソースで証明 + テストで固定)**
- 根拠: 指摘は正しい。`$tries = 1` だけでは証明になっていなかった。
- 対応内容: `vendor/laravel/framework` を読んで経路を確定させた:
  - `Queue/Worker.php:292-321` `registerTimeoutHandler()` が `pcntl_alarm($timeout)` を張り、
    SIGALRM で **`markJobAsFailedIfWillExceedMaxAttempts()` を `kill()` より前に**呼ぶ。
  - `Worker.php:665-676`: `$maxTries = $job->maxTries()` = **1** (`RunManualAnalysis::$tries`)、
    `$job->attempts()` = 1 (初回配送) なので `1 >= 1` が成立し `failJob($job, $e)` に入る。
  - `failJob` → `$job->fail($e)` → `CallQueuedHandler::failed()` → **`RunManualAnalysis::failed()`**
    → `AnalysisJobService::failJob()` (行ロック + terminal guard + 予約 release)。
  - よって **`$failOnTimeout` の設定に依存せず、`$tries = 1` の帰結として `failed()` が必ず走る**。
  - terminal tx の途中で SIGALRM が来た場合は tx が commit されないまま接続が落ち、
    その後 `failed()` が release する。commit 済みなら `failJob` の terminal guard が
    no-op を返し release されない。どちらも会計不変条件を満たす。
  - 最終防壁は `analysis:recover-stale-jobs` cron (30 分)。
- 追加で固定するテスト (詳細設計のテスト計画に記載):
  - commit 前 timeout → 予約が Released (または stale 回復で Released) になる
  - commit 後 timeout → Released にならない (Committed のまま)
  - `failed()` の重複実行で会計状態が変わらない (冪等)

## [Warning] deadline 判定の意図 (残時間 < C でも開始する) を実装で壊されないようにせよ
- 判断: **対応する**
- 対応内容: 「deadline の判定は **『deadline を過ぎたか』の真偽のみ**であり、
  残時間を HTTP timeout に反映しない」を設計上の明示ルールとし、
  Feature テストで **「deadline 直前 (残り 1 秒) に開始した試行にも client timeout 全体が
  許容される」**ことを固定する。実装コメントにも同旨を書く。

## [Warning] deadline は単調増加時計を使うべき
- 判断: **反論する (wall clock を維持。根拠を明記)**
- 根拠:
  1. **ハード上限は deadline ではなく worker の `$timeout` (pcntl_alarm)** である。
     deadline は「次の試行を始めてよいか」を決める**ソフト予算**にすぎず、
     NTP による時計の巻き戻しが起きても総実時間は SIGALRM が必ず打ち切る。
  2. 周辺の同種の判断 — 予約 TTL (`TicketReservation::expires_at`)、
     stale 回復閾値 (`recoverStale` の `updated_at` 比較) — は**すべて wall clock**。
     deadline だけ別時計にすると「同じ予算の話をしている 3 つの機構が別の時計で動く」
     という不整合を作る (思考原則 4)。
  3. `CarbonImmutable::now()` は `travelTo()` でテスト可能。`hrtime()` を使うと
     deadline 打ち切りの Feature テストが書けなくなり、禁止事項 1 (テストなし) に近づく。
- 対応内容: 上記を設計書の「却下した代案」に明記。時計は `CarbonImmutable::now()`。

## [Warning] 「3 段すべてにフル ceiling」の保証には非 LLM 処理 < C の前提が要る
- 判断: **対応する (保証を条件付きに弱める)**
- 根拠: 同意。抽出 0.4 秒だけでは根拠として不足。
- 対応内容: 保証を **「LLM 以外の処理の合計が deadline に対して無視できる限り」** と条件付きにし、
  非 LLM 処理の内訳と上限根拠を明記した:
  - SOP テキスト抽出: 実測 0.4 秒未満 (290KB PDF)。入力は `source_document_max_bytes` (20MB) で有界
  - DTO 検証 (`*Data::fromLlmText`): 純メモリ処理。入力は `max_tokens` で有界
  - `updateProgress` / `extracted_json` 保存: 単一行 UPDATE
  - `finalize` は deadline の外側 (M で見ている)
  これらは合計で秒オーダーであり、`D = 3C` の設計意図を壊さない。

## [Warning] generic `PrismException` から status を型安全に取れるか確認せよ
- 判断: **対応する (取得可能だったので 5xx を retryable に含める)**
- 根拠: 確認した結果、**取得できる**:
  - `PrismException::providerRequestErrorWithDetails()` は
    `new self($message, $statusCode, $previous)` で **HTTP status を例外 code に載せている**
    (`vendor/echolabsdev/prism/src/Exceptions/PrismException.php:71-87`)。
    ただし他の factory (`toolNotFound` 等) は code 0 なので `getCode()` は多義的。
  - より確実なのは `previous`: `handleResponseErrors()` は
    `previous: $e` (= `Illuminate\Http\Client\RequestException`) を渡している
    (`Providers/Anthropic/Anthropic.php:95-106`)。
    `$e->getPrevious() instanceof Illuminate\Http\Client\RequestException` を判定してから
    `->response->status()` を読めば **型安全 (PHPStan level 10 適合)** に status が取れる。
- 対応内容: retryable 集合に
  **「`PrismException` かつ previous が `RequestException` かつ status ∈ {408, 500, 502, 503, 504}」**
  を追加した。単発 502/503 を救えるという期待効果を満たせるようになった。
  それ以外 (status 取得不能 / 4xx) は fail-fast。
  retryable / non-retryable は Architecture テストではなく **例外型ごとの Feature テスト**で固定する
  (指摘のとおり、集合の宣言だけでなく挙動を固定する)。


---

# 修正版 概念設計 (全文)

# 概念設計: AI 解析の時間 budget 是正と provider 例外の有界リトライ (F-1-01)

- 対象 finding: `devnotes/20260803-203721-bug-hunt/report.md` §High F-1-01
  (shard 詳細: `devnotes/20260803-203721-bug-hunt/shard-1/shard-report.md#F-1`)
- task_key: C
- 改訂: Round 1・Round 2 レビュー反映 (時間 budget の再定義 / 生成レートの実測 /
  例外写像表 / SIGALRM 経路の証明 / 360s の位置づけを「運用上限」へ格下げ)

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

**実測 (2026-08-04 JST = 2026-08-03 UTC, 本番 Anthropic API,
`claude-sonnet-4-5-20250929` = prompt YAML の設定モデル、非ストリーミング)**:
日本語 JSON を `max_tokens` まで飽和生成させて wall-clock と `usage.output_tokens` を計測。

| run | max_tokens | 実時間 | output_tokens | stop_reason | レート |
|---|---|---|---|---|---|
| 1 | 4,000 | 68.6s | 4,000 | max_tokens | 58.3 token/s |
| 2 | 4,000 | 67.7s | 4,000 | max_tokens | 59.1 token/s |
| 3 | 4,000 | 74.9s | 4,000 | max_tokens | 53.4 token/s |
| 4 | **16,000** | **273.9s** | **16,000** | max_tokens | **58.4 token/s** |

→ 実測レンジ **53.4〜59.1 token/s**。run 4 が示すとおり
**4,000 → 16,000 token の範囲でレートはほぼ一定**であり、
**`max_tokens: 16000` を飽和させる 1 回の呼び出しは実測 約 274 秒**かかる。ここから:

- **120s がカバーできるのは約 6,400〜7,100 output token** に過ぎない。
  `max_tokens: 16000` の 45% 未満であり、**上限まで使う段は落ちる**。
- generate 段の出力は `GeneratedScenarioData` のスキーマ上、cut 1 件あたり
  scene(≤1000 字) / narration(≤2000 字) / subtitle_secondary(≤2000 字) 等を持つ。
  現実的な SOP (数十手順) では容易に 7,000 token を超える。
- 194 バイトの極小 SOP が 50 秒で成功したのは出力が数百 token で済んだためであり、
  **入力サイズ依存ではなく「出力 token 量依存」**である (bug-hunt の「サイズ依存」の正体)。

つまり根本原因は **「120s は max_tokens 16000 の生成時間 (実測 274 秒) をカバーしていない」**。
リトライの有無以前に、成功しうる時間予算が与えられていない。

> 計測スクリプトは使い捨てのため保存していない。再現手順:
> `POST https://api.anthropic.com/v1/messages` に
> `{model: claude-sonnet-4-5-20250929, max_tokens: 16000,
> messages:[日本語 JSON カット一覧を 100 件以上生成させる指示]}` を非ストリーミングで送り、
> wall-clock と `usage.output_tokens` の比を取る。
>
> **この実測で未検証の点 (受容するリスク)**: 実 3 プロンプト (システムプロンプト込み) での
> レート、混雑時間帯の分散、TTFT の分布。後述の ceiling は実測 274 秒に対し
> **約 1.31 倍のマージン**を持たせてこれを吸収する。超過した場合は timeout 文言で
> ユーザーに次アクションを出す (無言の失敗にしない)。

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

1. **client timeout を実測に基づく運用上限へ引き上げる** (120 → **360 秒**)。
   **360s は「保証 ceiling」ではなく「観測に基づく運用上限」**である:
   `max_tokens: 16000` 飽和の実測 **274 秒**に対し約 **1.31 倍**のマージンを取った値。
   これを超える呼び出しは「今回の観測レンジを外れた provider 遅延」であり、
   打ち切って timeout 文言を出す (= 予算を無限に伸ばさない) という方針の表明である。
   *(「360 秒を超える = どのみち JSON が途中で切れる」という因果は成立しないので主張しない。
   16,000 token 未満の正常な JSON が provider 遅延で 360 秒を超えることはありうる。)*
2. **有界リトライを deadline 制にする**。パイプライン開始時刻 T0 から
   `manual.analysis_deadline_seconds` の実時間予算を持ち、
   **各試行の開始前**に残予算を検査する。残っていなければ即 timeout 扱いで failJob。
   → worst-case が「3 段 × 試行数 × timeout」の**積**ではなく
   **`deadline + client timeout 1 回分`** に変わり、時間予算の爆発を防げる。
   deadline は **`3 × client timeout` (= 1,080 秒)** と定義する。これにより
   **「LLM 以外の処理の合計が deadline に対して無視できる限り、3 段すべてが最低 1 回は
   フル ceiling で試行できる」**が成り立つ (deadline を ceiling の 3 倍未満にすると、
   飽和ジョブで最終段が starve する)。
   非 LLM 処理の内訳と有界性 — SOP テキスト抽出 (実測 0.4 秒未満。入力は
   `source_document_max_bytes` 20MB で有界) / DTO 検証 (純メモリ。入力は `max_tokens` で有界) /
   `updateProgress`・`extracted_json` 保存 (単一行 UPDATE) — はいずれも秒オーダーであり、
   `finalize` は deadline の外側 (後述 M で見る)。
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
| HTTP 408 / 500 / 502 / 503 / 504 | `PrismException` (generic) だが **`previous` に `Illuminate\Http\Client\RequestException` を保持** | `Providers/Anthropic/Anthropic.php:95-106` が `previous: $e` を渡す。`$e->getPrevious()->response->status()` で status を型安全に取得できる | **retryable** |
| HTTP 429 (rate limited) | `PrismRateLimitedException` | 同上 | 非 retryable (専用文言) |
| HTTP 413 (request too large) | `PrismRequestTooLargeException` | 同上 | 非 retryable (専用文言) |
| その他の 4xx / status 取得不能 | `PrismException` (generic) | 同上 | **非 retryable** |

**5xx の status 取得方法 (型安全性)**: Anthropic provider は 429/529/413 以外を
status に依らず generic `PrismException` に潰すが、
`PrismException::providerRequestErrorWithDetails()` は
`new self($message, $statusCode, $previous)` で **HTTP status を例外 code に載せている**
(`vendor/echolabsdev/prism/src/Exceptions/PrismException.php:71-87`)。
ただし `getCode()` は他の factory (`toolNotFound` 等) で 0 になるため多義的。
そこで **`$e->getPrevious() instanceof Illuminate\Http\Client\RequestException` を判定してから
`->response->status()` を読む**。これなら PHPStan level 10 でも narrowing が効き、
「一時的な 502」と「決定論的な 400」を確実に区別できる。
status を取得できない generic `PrismException` は fail-fast に倒す。

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
| `tests/Architecture/AnalysisTimeBudgetInvariantTest.php` | 新しい連鎖 (`D = 3C` / `T ≥ D + C + M₁ + S` / `T < retry_after < TTL ≤ stale`) を固定。**生成レートは CI で pin しない** |
| `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | YAML timeout の pin 値を新値へ / 解析 3 本の `max_tokens` と `timeout` の一致 |
| `tests/Feature/Projects/AnalysisPipelineTest.php` | 例外型ごとの retryable/非 retryable / deadline 打ち切り / deadline 直前開始 / SIGALRM 相当の会計不変条件 |
| `tests/Support/` | 例外を投げられる `PromptFake` 派生 (テスト用 double) |
| `docs/architecture.md` | 解析の時間 budget 連鎖 (L191/L195) と「YAML が prism.request_timeout を上書きする」旨を更新 |

**フロントエンド (Svelte/TS)・Controller・DTO / JsonResource・route の変更は無し**
(表示側はサーバ文言をそのまま出すため)。`response()->json()` の新規使用も無し。

## 制約・前提

### 時間 budget の連鎖 (新旧)

deadline の時計の定義を先に固定する:

- **T0** = `AnalysisPipeline::run()` の入口。
- deadline = `T0 + analysis_deadline_seconds`。
- **判定点は「各 LLM 試行の開始直前」のみ**。判定は **「deadline を過ぎたか」の真偽だけ**で行い、
  **残り時間を HTTP timeout に反映しない**。deadline を過ぎていれば試行を開始せず
  `AnalysisFailedException::timedOut()` を投げる。
  → 「deadline の 1 秒前に開始した試行にも client timeout の全体 (C) が許容される」。
  この意図は Feature テストで固定する (実装者が「残時間を timeout に設定する」方式に
  変えると `D + C` モデルが壊れるため)。
- 走行中の呼び出しを中断はしない (中断は Guzzle の client timeout が担う)。
  したがって **deadline 通過後に走りうるのは高々 1 回分の client timeout**。
- SOP テキスト抽出 (実測 0.4 秒未満) は deadline の**内側**に含む。
- 時計は `CarbonImmutable::now()` (wall clock)。理由は「却下した代案」参照。

| 項目 | 現行 | 新 | 根拠 |
|---|---|---|---|
| 1 呼び出しの client timeout (C) | 120s | **360s** | `max_tokens: 16000` 飽和の実測 274 秒に対し約 1.31 倍の運用上限 |
| パイプライン deadline (D) | (なし) | **1,080s** = 3C | 3 段すべてにフル ceiling の 1 回を許す最小値 |
| finalize モデル予算 (M₁) | — | **30s** | deadline 通過後の terminal tx (2 行ロック) + チケット commit/release + 通知 + `report()`。実処理は秒未満 |
| 安全余白 (S) | — | **90s** | タイマー精度・シグナル配送・ログ処理などモデル外要因 |
| job `$timeout` | 1,380s | **1,560s** = D + C + M₁ + S | モデル上限 `D + C + M₁ = 1,470s` に対し **90 秒の明示的余白** |
| queue `retry_after` | 1,560s | **1,680s** | job timeout < retry_after (レンダレーンと同値・運用実績あり) |
| 予約 TTL | 1,800s | **1,800s (据置)** | `TicketLedgerService::RESERVATION_TTL_MINUTES` を触らない |
| stale 閾値 | 30 分 | **30 分 (据置)** | `manual.analysis_stale_after_minutes` を触らない |

**C = 360 の上限性**: `T = 4C + M₁ + S < retry_after < TTL(1800)` を満たしつつ TTL を据え置くには
`4C ≤ 1440` すなわち **C ≤ 360** が必要。360 は「TTL/stale を動かさずに取れる最大の ceiling」であり、
同時に実測 274 秒に対して 1.31 倍のマージンを確保できる値でもある。
(TTL を伸ばす案は、`RenderTimeBudgetInvariantTest` が `TTL ≤ render stale 閾値` を固定しているため
**レンダレーンの stale 閾値まで巻き込む**。レーン横断の影響を避けるため採らない。)

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

#### SIGALRM (job `$timeout`) 強制終了時の予約の行方 — Laravel のソースで確定させた

内部リトライが予約行に触れないことは上で示したが、**`$timeout` によるプロセス強制終了は別経路**
なので個別に証明する:

1. `vendor/laravel/framework/src/Illuminate/Queue/Worker.php:292-321` `registerTimeoutHandler()` が
   `pcntl_alarm($timeout)` を張り、SIGALRM ハンドラで **`kill()` より前に**
   `markJobAsFailedIfWillExceedMaxAttempts()` を呼ぶ。
2. 同 `:665-676` — `$maxTries = $job->maxTries()` は **1** (`RunManualAnalysis::$tries = 1`)、
   `$job->attempts()` は初回配送で 1。よって `1 >= 1` が成立し `failJob($job, $e)` に入る。
3. `failJob` → `$job->fail($e)` → `CallQueuedHandler::failed()` →
   **`RunManualAnalysis::failed()`** → `AnalysisJobService::failJob()`
   (行ロック + terminal guard + `Reserved` のみ release)。

→ **`$failOnTimeout` の設定に依存せず、`$tries = 1` の帰結として `failed()` が必ず走る**。

- terminal tx (`finalize`) の途中で SIGALRM が来た場合: tx は commit されないまま接続が落ちるため
  ロールバックされ、その後 `failed()` が予約を release する (課金なし・失敗)。
- commit 済みの後に SIGALRM が来た場合: `failJob` の terminal guard が `false` を返して no-op。
  予約は `Committed` のまま (課金あり・成功)。
- いずれの分岐でも「無課金 succeeded」「課金済み failed」は生じない。
- 最終防壁は `analysis:recover-stale-jobs` cron (30 分) と
  `TicketLedgerService::releaseStale` (TTL 超過分の回収)。

この 3 分岐は Feature テストで固定する (詳細設計のテスト計画参照)。

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
| **すべての `PrismException` を retryable にする** | 400 系 (リクエスト不正) を無駄に投げ直す。`previous` の `RequestException` から status を型安全に読める場合のみ 408/5xx を retryable にし、読めないものは fail-fast にする (写像表)。 |
| **deadline に単調増加時計 (`hrtime`) を使う** | (1) **ハード上限は deadline ではなく worker の `$timeout` (pcntl_alarm)** であり、NTP の巻き戻しが起きても総実時間は SIGALRM が必ず打ち切る。deadline は「次の試行を始めてよいか」を決めるソフト予算にすぎない。(2) 予約 TTL (`expires_at`) と stale 回復 (`updated_at` 比較) は**すべて wall clock** で、deadline だけ別時計にすると同じ予算の話をする 3 機構が別の時計で動く不整合になる (思考原則 4)。(3) `CarbonImmutable::now()` は `travelTo()` でテストできるが `hrtime()` は deadline 打ち切りの Feature テストが書けなくなり禁止事項 1 に近づく。 |
| **予約 TTL / render stale 閾値を伸ばして C をさらに大きく取る** | `RenderTimeBudgetInvariantTest` が `TTL ≤ render stale 閾値` を固定しているため、TTL を伸ばすとレンダレーンの stale 閾値まで巻き込む。レーン横断の影響を避ける。 |
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
