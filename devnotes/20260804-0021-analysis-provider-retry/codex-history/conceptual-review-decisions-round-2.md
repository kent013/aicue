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
