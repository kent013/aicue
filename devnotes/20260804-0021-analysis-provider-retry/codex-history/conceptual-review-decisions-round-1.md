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
