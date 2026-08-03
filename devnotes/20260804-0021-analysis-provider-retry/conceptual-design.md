# 概念設計: AI 解析の時間 budget 是正と provider 例外の有界リトライ (F-1-01)

- 対象 finding: `devnotes/20260803-203721-bug-hunt/report.md` §High F-1-01
  (shard 詳細: `devnotes/20260803-203721-bug-hunt/shard-1/shard-report.md#F-1`)
- task_key: C
- 改訂: Codex 概念設計レビュー Round 1〜4 反映 (時間 budget の再定義と導出向きの修正 /
  生成レートの実測 (max_tokens 16000 飽和を含む) / 例外写像表と 500-504 の型安全な判定 /
  SIGALRM 時の会計を eventual guarantee へ / 360s を「運用上限」へ格下げ / pre-pipeline 予算 P の明示)

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

**500/502/503/504 の status 取得方法 (型安全性)**: Anthropic provider は 429/529/413 以外を
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
  具体的には (a) **観測レンジ (実測 274 秒) と設定した運用上限 (360 秒) の範囲では**
  1 回の呼び出しが打ち切られない、(b) 単発の接続断・500/502/503/504・529 で即 failJob しない、
  (c) 失敗時に理由別の次アクションが提示される。
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
- **`startJob()` (予約作成・行ロック) は T0 の後**なので deadline の内側。
  一方 **job の `$timeout` は `handle()` 入口起点**なので、
  `handle()` 入口 → `run()` 入口の時間 (P: payload deserialize / DI 解決 / `findOrFail`) は
  D にも C にも含まれない。P は下表の **安全余白 S (90 秒) の内側**として扱う
  (受容条件: `P + その他モデル外要因 ≤ 90 秒`)。P はいずれも単一行 SELECT と
  コンテナ解決であり、通常はミリ秒オーダー。
- 時計は `CarbonImmutable::now()` (wall clock。テスト容易性優先。理由は「却下した代案」参照)。
  deadline は**ハード上限ではない** — 総実時間の上限は worker の `$timeout` (SIGALRM) である。

| 項目 | 現行 | 新 | 根拠 |
|---|---|---|---|
| 1 呼び出しの client timeout (C) | 120s | **360s** | `max_tokens: 16000` 飽和の実測 274 秒に対し約 1.31 倍の運用上限 |
| パイプライン deadline (D) | (なし) | **1,080s** = 3C | 3 段すべてにフル ceiling の 1 回を許す最小値 |
| finalize モデル予算 (M₁) | — | **30s** | deadline 通過後の terminal tx (2 行ロック) + チケット commit/release + 通知 + `report()`。実処理は秒未満 |
| pre-pipeline 予算 (P) | — | **S に含める** | `RunManualAnalysis::handle()` 入口から `AnalysisPipeline::run()` 入口まで (job payload の deserialize / DI 解決 / `AnalysisJob::findOrFail`)。**deadline の T0 より手前**なので D には入らない |
| 安全余白 (S) | — | **90s** | P + タイマー精度・シグナル配送・ログ処理などモデル外要因。**受容条件: `P + その他モデル外要因 ≤ 90s`** |
| job `$timeout` | 1,380s | **1,560s** = P + D + C + M₁ + S′ (P ⊆ S) | モデル上限 `D + C + M₁ = 1,470s` に対し **90 秒の明示的余白**。job の実時間は `handle()` 入口起点なので P を余白側で見る |
| queue `retry_after` | 1,560s | **1,680s** | job timeout < retry_after (レンダレーンと同値・運用実績あり) |
| 予約 TTL | 1,800s | **1,800s (据置)** | `TicketLedgerService::RESERVATION_TTL_MINUTES` を触らない |
| stale 閾値 | 30 分 | **30 分 (据置)** | `manual.analysis_stale_after_minutes` を触らない |

**C = 360 の決め方 (導出の向き)**:

1. **まず実測から C を決める**。`max_tokens: 16000` 飽和の実測は 274 秒。
   provider 遅延の分散を吸収するマージンとして約 1.31 倍を取り、**C = 360 秒**とする。
   (これは保証ではなく運用上限。§改善アイデア 1)
2. **その結果として** `D = 3C = 1,080`、`T = 4C + M₁ + S = 1,560` になる。
3. `T = 1,560 < retry_after 1,680 < TTL 1,800 ≤ stale 1,800` が成立し、
   **TTL と stale 閾値を据え置ける**。

参考までに、TTL 据え置き (`T = 4C + 120 < retry_after < 1,800`) から来る C の上界は
**`C < 390`** であり、実測から決めた 360 はその内側に収まっている
(= 実測起点で決めた値が、たまたま制約にも適合した)。

(TTL を伸ばして C をさらに大きく取る案は、`RenderTimeBudgetInvariantTest` が
`TTL ≤ render stale 閾値` を固定しているため **レンダレーンの stale 閾値まで巻き込む**。
レーン横断の影響を避けるため採らない。)

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

→ `$failOnTimeout` の設定に依存せず、`$tries = 1` の帰結として **`failed()` の呼び出しが試みられる**。

**ただし「即時 release」は保証しない (best-effort)**。SIGALRM ハンドラは同一プロセス内で走り、
進行中のトランザクションを明示 rollback してから `fail()` を呼ぶわけではないため、
(a) release が既存 tx に巻き込まれて kill と一緒に rollback される、
(b) 接続状態により `failed()` 自体が失敗する、
(c) 行ロック待ちのままプロセスが終了する、が起こりうる。

**したがって保証するのは eventual guarantee** — 「最終的に会計状態が正しく収束する」こと:

| 中断のタイミング | 最終的な予約状態 | 収束させる主体 |
|---|---|---|
| terminal tx の commit **前** | `Released` | `failed()` が成功すればその場で。失敗しても `analysis:recover-stale-jobs` cron (30 分) → `failJob` が release。さらに漏れても `TicketLedgerService::releaseStale` が TTL 超過分を回収 |
| terminal tx の commit **後** | `Committed` のまま | `failJob` の terminal guard が `false` を返して no-op (release しない) |
| `failed()` が失敗 / 未実行 | `Released` | `recoverStale` + `releaseStale` |

いずれの分岐でも「無課金 succeeded」「課金済み failed」は最終的に生じない。
**cron 2 種 (`analysis:recover-stale-jobs` / `releaseStale`) は補助ではなく保証の一部**である。
この 3 分岐の**最終**会計状態を Feature テストで固定する (即時性は要求しない)。

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
| **すべての `PrismException` を retryable にする** | 400 系 (リクエスト不正) を無駄に投げ直す。`previous` の `RequestException` から status を型安全に読める場合のみ 408/500/502/503/504 を retryable にし、読めないものは fail-fast にする (写像表)。 |
| **deadline に単調増加時計 (`hrtime`) を使う** | (1) `CarbonImmutable::now()` は `travelTo()` でテストできるが、`hrtime()` にすると deadline 打ち切りの Feature テストが書けなくなり禁止事項 1 (テストなし) に近づく。**テスト容易性を優先する**。(2) 時計補正による soft deadline の揺れは、**worker の `$timeout` (SIGALRM) が総実時間を上限する**ため受容できる (deadline はハード上限ではない)。 |
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
