# アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

# セキュリティ不変条件 (抜粋) — AGENTS.md より

7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

さらに本リポジトリ固有の思考原則:
1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

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
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【追加でとくに厳しく見てほしい点】
- 「実効タイムアウト 120s の出所」の特定が正しいか (Laravel HTTP client の options 上書き順序)
- 時間 budget の連鎖 (client timeout / deadline / job timeout / retry_after / 予約 TTL / stale 閾値) の算術に穴がないか
- 有界リトライがチケット 2 フェーズ (reserve→commit/release) の会計を壊さないという論証に穴がないか
- retryable 例外集合の切り分け (transient か決定論的か) が妥当か
- ストリーミング化を却下した判断が妥当か
- オーバーエンジニアリングになっていないか / 逆に過小ではないか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: AI 解析の時間 budget 是正と provider 例外の有界リトライ (F-1-01)

- 対象 finding: `devnotes/20260803-203721-bug-hunt/report.md` §High F-1-01
  (shard 詳細: `devnotes/20260803-203721-bug-hunt/shard-1/shard-report.md#F-1`)
- task_key: C

## 背景・課題

bug-hunt が「リポジトリ同梱のサンプル SOP (`doc/reference/sample-sop/AS_作業手順書.pdf`) で
AI 解析の generate 段が 120,002ms でタイムアウトし 2/2 で失敗する」を観測した。
`cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received`。
North Star の起点 (「SOP から AI がカット設計する」) が実運用サイズで機能しない。

### 事実確認 (すべてソースで裏取り済み。推測なし)

**(1) 実効タイムアウト 120s の出所 = 確定した。**

bug-hunt が「`config/prism.php` の `request_timeout` は 30s なのに観測は 120s。
`laravel-ssrf-pin` の transport 側 deadline が絡む可能性」としていたが、**ssrf-pin は無関係**。
実際の経路は以下 (全て vendor / repo のコードを読んで確認):

| # | 位置 | 内容 |
|---|---|---|
| 1 | `resources/prompts/{sop-extract,work-decomposition,scenario-generation}.yaml` | `client_options: { timeout: 120 }` |
| 2 | `vendor/kent013/laravel-prism-prompt/src/Prompt.php:1076` `resolveClientOptions()` | YAML の `client_options` をそのまま返す |
| 3 | 同 `Prompt.php:742-745` `executePrism()` | `$builder->withClientOptions($resolvedClientOptions)` |
| 4 | `vendor/echolabsdev/prism/src/Concerns/InitializesClient.php:16-21` | `Http::...->timeout(config('prism.request_timeout'))->connectTimeout(...)` = 30s |
| 5 | `vendor/echolabsdev/prism/src/Providers/Anthropic/Anthropic.php:114-120` | `$this->baseClient()->...->withOptions($options)` |

Laravel HTTP client の `timeout()` は Guzzle option `timeout` を書くだけなので、
**手順 5 の `withOptions(['timeout' => 120])` が手順 4 の 30 を上書きする**。
よって実効タイムアウトは **YAML の 120 秒**。観測値 120,002ms と完全に一致する。
`config/prism.php` の 30s は解析経路では一切効いていない。

なお 120 という値は **`AnalysisTokenBudgetInvariantTest` が YAML 側を、
`AnalysisTimeBudgetInvariantTest` が worst-case 算術側を** 二重に pin しており、
片方だけ動かすと CI が落ちる (= 設計として連鎖している値)。

**(2) 120s では原理的に足りない。**

- 解析 3 プロンプトはいずれも `max_tokens: 16000`。
- 呼び出しは **非ストリーミング**の単発 HTTP (`Prism::text()->asText()`)。
  Anthropic は非ストリーミングでは生成完了までレスポンス本体を返さないため、
  1 回の呼び出しの実時間 ≒ **最大 16,000 output token を生成し切るまでの時間**。
- 観測ログの `with 0 bytes received` は、まさに「生成中でまだ 1 バイトも返っていない」状態。
  ネットワーク瞬断ではなく **deadline 不足の署名**である。
- generate 段の出力は `GeneratedScenarioData` のスキーマ上、cut 1 件あたり
  scene(≤1000 字) / narration(≤2000 字) / subtitle_secondary(≤2000 字) 等を持ち、
  現実的な SOP (数十手順) では容易に数千〜16,000 token に達する。
  194 バイトの極小 SOP が 50 秒で成功したのは、出力が数百 token で済んだためであり、
  **入力サイズ依存ではなく「出力 token 量依存」**である (bug-hunt の「サイズ依存」の正体)。

つまり **「120s は max_tokens 16000 の生成時間を一切カバーしていない」** ことが根本原因。
リトライの有無以前に、成功しうる時間予算が与えられていない。

**(3) provider/connection 例外はリトライ対象外 = 事実。ただしコメント矛盾の指摘は半分不正確。**

`AnalysisPipeline::withBoundedRetry` は `LlmOutputInvalidException` のみ catch する
(`app/Services/Manual/AnalysisPipeline.php:243-255`)。`ConnectionException` は素通りして
`run()` の catch → `failJob` へ落ちる。ここは bug-hunt の指摘どおり。

一方 `RunManualAnalysis` の「LLM 3 段 × 3 試行 × client timeout 120s = 1,080s」コメントは
**JSON 検証失敗リトライ (`analysis_llm_max_retries=2` = 計 3 試行) の worst-case 予算**として
書かれており、実装と矛盾はしていない (`AnalysisTimeBudgetInvariantTest` が同じ式を CI 固定)。
誤解を招く書き方ではあるので文言は直すが、「実装とコメントが矛盾」という前提で
設計判断を組み立てるのは誤り。

**(4) 290KB PDF は token budget 内に収まっている (= 事前拒否は正解ではない)。**

`SopTextExtractor` は PDF → テキスト抽出 → 正規化後の `strlen` を
`manual.analysis_max_text_bytes` (150,000) と比較する。
実測 (`smalot/pdfparser` で同一処理を再現) の結果、
`AS_作業手順書.pdf` (290,498 bytes) の抽出テキストは **6,451 bytes / 3,292 文字**。
上限の 5% 未満であり、**入力側の budget 超過ではない**。
よって「事前の拒否」は本 finding の解ではない。

**(5) [scope 外・重要な副産物] 同 PDF の抽出テキストは文字化けしている。**

同じ再現で、抽出テキストが CP932 バイト列を Latin-1 として解釈した典型的な mojibake
(`ì‹ÆŽè‡‘` … CP1252 → CP932 で復元すると `作業手順書` になる) であることを確認した。
これは **valid な UTF-8** になってしまうため `SopTextExtractor::ensureUtf8()` の
`mb_check_encoding($text, 'UTF-8')` を通過し、そのまま LLM に渡っている。
本設計の scope 外 (別 finding) だが、**本設計の施策だけでは同 PDF の解析結果は
「時間内に完走するが内容は無意味」になる**ため、必ず別 TODO として起票する
(§スコープ外 / open questions 参照)。

## 改善アイデア

**「1 回の LLM 呼び出しに、出力 token 上限を生成し切れるだけの時間を与える」** ことを
主軸に据え、増えた時間予算が既存の連鎖 (job timeout < retry_after < 予約 TTL ≤ stale) を
壊さないように、**リトライの打ち切りを『試行回数』から『試行回数 ∧ 実時間 deadline』へ**
変える。そのうえで provider/connection 例外を有界リトライ対象に含め、
失敗時はユーザーが次に取れる行動を提示する。

1. **client timeout を出力 token 上限から導出した値へ引き上げる** (120 → 480 秒)。
   導出: `max_tokens 16000 ÷ 最低生成レート 40 token/s = 400s` + TTFT/往復マージン 60s = 460 ≤ 480。
   「40 token/s を下回る provider 応答は失敗として扱う」という前提を明示し、テストで pin する。
2. **有界リトライを deadline 制にする**。パイプライン開始時刻から
   `manual.analysis_deadline_seconds` (900 秒) の実時間予算を持ち、
   各試行の**開始前**に残予算を検査する。残っていなければ即 timeout 扱い。
   → worst-case が「3 段 × 試行数 × timeout」の**積**ではなく
   **`deadline + 1 回分の timeout`** に変わり、時間予算の爆発を防げる。
3. **provider/connection 例外を有界リトライ対象に含める** (transient のみ)。
   対象: `Illuminate\Http\Client\ConnectionException` /
   `Prism\Prism\Exceptions\PrismProviderOverloadedException` (529)。
   非対象 (決定論的・即 fail): `PrismRequestTooLargeException` (413) /
   `PrismRateLimitedException` (429。retry-after 準拠の待機を本 scope では作らない) /
   その他 `PrismException`。
4. **失敗文言を理由で分岐する (H4)**。timeout/deadline 起因なら
   「時間内に終わらなかった。手順書を分割して短くするか時間をおいて再実行」を提示する。
   表示側 (`AnalysisPanel.svelte` の `failedJob.error`) はサーバ文言をそのまま出すため
   **フロント変更は不要**。
5. **時間 budget の連鎖を引き直し、CI 固定を新しい算術に合わせる**。
   予約 TTL (1,800s) と stale 閾値 (30 分) は **据え置き**にできる値を選ぶ
   (下記「制約・前提」の表)。

## 期待効果

- **使命への貢献 (直接)**: 現実的サイズの SOP で AI 解析が完走できるようになり、
  North Star の起点である「SOP → カット設計」が実運用で成立する。
- 単発の外部 API 瞬断でチケット消費フローが止まらない (有界リトライ)。
- 失敗時にユーザーが「分割する / 時間をおく」という次の行動を取れる (詰みの解消・H4)。
- `config/prism.php` の 30s が解析経路で効いていないという**現行実装の誤解の余地**を、
  テストとコメントで解消する。

## 実装方針（概要）

| 変更対象 | 内容 |
|---|---|
| `resources/prompts/*.yaml` (解析 3 本) | `client_options.timeout: 120 → 480` |
| `config/manual.php` | `analysis_deadline_seconds` (900) を追加 |
| `config/queue.php` | `database-analysis.retry_after: 1560 → 1680` |
| `app/Jobs/Manual/RunManualAnalysis.php` | `$timeout: 1380 → 1560`、budget コメントを新算術へ |
| `app/Services/Manual/AnalysisPipeline.php` | deadline の生成と伝播 / `withBoundedRetry` の retryable 判定と deadline guard / `userMessageFor` の分岐 |
| `app/Exceptions/Manual/AnalysisFailedException.php` | `timedOut()` / `providerBusy()` を追加 |
| `tests/Architecture/AnalysisTimeBudgetInvariantTest.php` | 新しい連鎖と導出をテストで固定 |
| `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | YAML timeout の pin 値を新値へ |
| `tests/Feature/Projects/AnalysisPipelineTest.php` | provider 例外リトライ / deadline 打ち切り / チケット会計不変条件 |
| `tests/Support/` | 例外を投げられる `PromptFake` 派生 (テスト用 double) |
| `docs/architecture.md` | 解析の時間 budget 連鎖の記述 (L191/L195) を更新 |

フロントエンド (Svelte/TS) の変更は無し。DTO / JsonResource / route の変更も無し。

## 制約・前提

### 時間 budget の連鎖 (新旧)

| 項目 | 現行 | 新 | 根拠 |
|---|---|---|---|
| 1 呼び出しの client timeout | 120s | **480s** | `16000 token ÷ 40 token/s + 60s` = 460 ≤ 480 |
| パイプライン deadline | (なし) | **900s** | 試行開始の可否を決める実時間予算 |
| worst-case 実時間 | 3×3×120+180 = 1,080+180 | **900 + 480 + 180 = 1,560** | deadline 直前に開始した 1 呼び出し + 抽出/簿記マージン |
| job `$timeout` | 1,380s | **1,560s** | worst-case と一致 |
| queue `retry_after` | 1,560s | **1,680s** | job timeout < retry_after (render レーンと同値。実績あり) |
| 予約 TTL | 1,800s | **1,800s (据置)** | `TicketLedgerService::RESERVATION_TTL_MINUTES` を触らない |
| stale 閾値 | 30 分 | **30 分 (据置)** | `manual.analysis_stale_after_minutes` を触らない |

**この選び方の要点**: 予約 TTL と stale 閾値を据え置けたことで、
チケット台帳 (`TicketLedgerService`) と stale 回復 cron の運用契約に**一切手を入れない**。
影響範囲は解析レーンの中に閉じる。

### チケット 2 フェーズとの関係 (セキュリティ不変条件 #7)

リトライがチケット会計を壊さないことは、現行の構造から**構造的に保証される**:

- 予約 (`reserve`) は `startJob()` の中で 1 回だけ行われ、冪等キーは
  `analysis_jobs.ticket_reservation_id` (`ensureReservation`)。
- 本設計で増えるリトライは **`runExtractStep`/`runDecomposeStep`/`runGenerateStep` の内側**、
  すなわち `startJob()` の後・`finalize()` の前に閉じている。
  リトライ経路は予約行を読みも書きもしない。
- `commit` は `finalize()` の terminal tx 内で 1 回、`release` は `failJob()` の中で 1 回。
  どちらも `analysis_jobs` 行ロック + terminal guard を通るため、
  何回リトライしても commit/release は高々 1 回。
- したがって「無課金 succeeded」「課金済み failed」「二重課金」はいずれも発生しない。
  これを Feature テストで明示的に固定する (下記テスト計画)。

**逆に、ジョブ再配送 (`tries`) を増やす案は採らない**。再配送はチケット会計と
stale 回復の直列化点を跨ぐため、リトライ予算を増やすには最も危険な軸である
(`$tries = 1` は §10.8-1 の意図的な設計。据え置く)。

### フレームワークのレンジ内で収める (思考原則 1)

- タイムアウト値は `resources/prompts/*.yaml` の `client_options` = **prism-prompt 公式の作法**
  (`Prompt::resolveClientOptions()` が読む場所) で指定する。自前の HTTP 層は作らない。
- **ストリーミング化は今回採らない** (下記「却下した代案」)。
- リトライは `AnalysisPipeline` 内の既存 `withBoundedRetry` を拡張するだけで、
  Laravel の queue retry / Prism の `clientRetry` には手を出さない
  (どちらもチケット 2 フェーズや deadline 制御と噛み合わない)。

### 却下した代案

| 代案 | 却下理由 |
|---|---|
| **ストリーミング化** (`Prism::text()->asStream()`) | 本命の正攻法だが**現在のレンジ外**。`kent013/laravel-prism-prompt` の `Prompt` はストリーム実行 API を公開していない (`executeSync`/`execute`/`executePrism` のみ。`grep` で確認)。使うには Prism を直呼びするしかなく **AGENTS.md 禁止事項 5 (Prism 直呼び禁止 / `PromptGuardrailTest` が検出)** に抵触する。パッケージ側に stream 実行が入ったら再検討する (§スコープ外)。 |
| **`max_tokens` を下げて生成時間を縮める** | 出力が途中で切れる → JSON 不正 → リトライしても同じ位置で切れる、という決定論的失敗に変わるだけ。悪化。 |
| **`analysis_max_text_bytes` を下げて事前拒否する** | 今回の PDF は上限の 5% 未満で、拒否しても救われない。「入力バイト → 出力 token」の実測した換算係数を持っていない以上、根拠のない上限を置くと**正当な SOP を誤って拒否する**。採らない。 |
| **すべての `PrismException` をリトライ対象にする** | 400 系 (リクエスト不正) を無限に投げ直す。retryable は transient と断定できる型だけに限定する。 |
| **429 を `retry-after` 秒スリープして再試行** | worker を占有したまま眠るのは deadline 予算と相性が悪く、今必要でもない。明示文言で「時間をおいて再実行」に倒す。 |
| **予約 TTL / stale 閾値を伸ばす** | 上表の値選びで不要になった。伸ばすと worker 異常終了時に manual が `analyzing` で長時間ロックされ UX が悪化する。 |
| **ジョブ再配送 (`tries` > 1) でリトライ** | チケット 2 フェーズと stale 回復の直列化点を跨ぐ。§10.8-1 の意図に反する。 |

## スコープ外

- **PDF テキスト抽出の文字化け (上記 (5))**。本 finding とは独立した defect。
  `SopTextExtractor::fromPdf()` / `ensureUtf8()` と `smalot/pdfparser` の CMap 処理が対象。
  **別 finding / 別 TODO として起票する**。本設計の施策はこれを直さない。
- LLM 呼び出しのストリーミング化 (パッケージ側の対応待ち)。
- レンダ (`RenderPipeline`) 側の時間 budget。今回は触らない。
- `analysis_max_text_bytes` / `max_tokens` の見直し。
- フロントエンド (`AnalysisPanel.svelte`) の表示ロジック。サーバ文言をそのまま出すため不要。
