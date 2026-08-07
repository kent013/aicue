# 概念設計レビュー依頼 (aicue / T126 外部 SDK の client timeout を pin する)

## アプリの使命・禁止事項 (AGENTS.md より転記。全判断の基準とせよ)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは Web アプリケーション (Laravel 12 + Svelte 5 + Inertia.js + TypeScript, PHP 8.4, PHPStan level 10, Pest) の改善に関する概念設計レビュアーです。

### レビュー観点

1. 使命との整合性: この改善はアプリの使命 (North Star) に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か (Laravel 12 + Svelte 5 + Inertia.js)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

### 出力形式

- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

### 補足コンテキスト (レビュー時に前提としてよい事実)

- 本リポジトリでは「不変条件は deny-by-default の目録型 Architecture テストで固定する」ことが確立した作法である
  (見本: `tests/Architecture/ThrottleCoverageInventoryTest.php` / `QueuedJobLeaseInventoryTest.php` /
   `BillingGatewayFailureTaxonomyInventoryTest.php`)。免除は型付き enum + 30 文字以上の根拠が必須。
- 既存の関連 gate:
  - `QueueWorkerLeaseInvariantTest` (規則 1: worker `--timeout` < `retry_after`。`retry_after` はリテラル固定・env 上書き禁止)
  - `QueuedJobLeaseInventoryTest` (規則 2 + キューに載る全クラスの接続目録)
  - `JobExclusionOrderingInvariantTest` (入口排他 TTL / `uniqueFor` < 既定接続の `retry_after`)
  - `PromptClientTimeoutInvariantTest` (LLM prompt YAML の `client_options.timeout` を必須化)
- 直前にマージされた T132 が `app/Support/Billing/GatewayFailureClassifier` を導入済み
  (Stripe/Cashier 例外 → `GatewayFailureClass` の写像。`ApiConnectionException => ProviderUnavailable`)。
- `docs/TODO.md` の T127 は条件付き TODO (既定キュー接続の分割)。条件は「回収遅延が実害として観測されたとき」。

---

## 概念設計

# 概念設計: external-sdk-client-timeout (T126 外部 SDK の client timeout を pin する)

## 背景・課題

### 事実確認 (実コードを読んで確認した内容)

1. **Stripe SDK の待ち上限は vendor 既定のまま**である。
   `vendor/stripe/stripe-php/lib/HttpClient/CurlClient.php` の
   `DEFAULT_TIMEOUT = 80` / `DEFAULT_CONNECT_TIMEOUT = 30` が効いており、
   アプリ側に `setTimeout()` / `setConnectTimeout()` / `ApiRequestor::setHttpClient()` を
   呼ぶ箇所は **1 つも無い** (`rg` で確認)。
   `Stripe\Stripe::$maxNetworkRetries` も既定 0 のまま (明示 pin なし)。
   `Laravel\Cashier\Cashier::stripe()` は `StripeClient` に `api_key` / `stripe_version` /
   `api_base` しか渡さず、**`StripeClient` の config に timeout を渡す口はそもそも無い**
   (`BaseStripeClient::DEFAULT_CONFIG` に timeout 系のキーが存在しない)。
   したがって Stripe の待ち上限を動かせる層は **`ApiRequestor` の HTTP client (プロセス大域)** だけである。

2. **AWS SDK の待ち上限は「無指定 = 無制限」**である。
   `vendor/aws/aws-sdk-php/src/ClientResolver.php` の `'http'` 引数は
   `'default' => []` で、`connect_timeout` / `timeout` の既定値を**持たない**
   (値が入るのは `defaults mode` を明示したときだけ)。
   本アプリの AWS クライアント構築点は 3 つあり、いずれも `http` を渡していない:
   - `config/filesystems.php` の `disks.s3` → `FilesystemManager::createS3Driver()` が
     `new S3Client($s3Config)` に素通しする (`TakeObjectStorage` / `RenderObjectStorage` が使う)
   - `config/services.php` の `ses` → `MailManager::createSesV2Transport()` が
     `new SesV2Client(...)` に素通しする (キュー投入されたメール送信)
   - `app/Providers/AppServiceProvider.php` の `SnsClient` singleton (SNS 購読確認)

   `retries` も既定のまま = legacy mode / max_attempts 3
   (`Aws\Retry\ConfigurationProvider::DEFAULT_MAX_ATTEMPTS = 3`)。
   つまり **1 操作の最悪待ち時間は「無制限 × 3」**である。

3. **キューの帯はこの既定値に引きずられている**。`config/queue.php` の `database` 接続は
   `retry_after = 600` で、コメントの根拠がそのまま
   「Stripe 4〜5 呼び出し × SDK 上限 80s = 約 400s」である。
   `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 の値表も
   `database` = retry_after 600 / worker `--timeout` 540 とこの前提で書かれている。
   T127 (条件付き TODO) は「その代償として短命ジョブの回収が最大 510 秒遅れる」を扱う。

### 課題 (2 つ。優先度はこの順)

**課題 A (可用性 / 主): web リクエスト経路の外部待ちが無制限である。**
キュー経路には worker の `--timeout` (SIGALRM) というハード上限があるが、
web リクエスト経路には**それが無い**。PHP の `max_execution_time` は
Unix ではソケット待ちの時間を数えないため、外部 SDK が応答しない間
php-fpm ワーカーは占有され続ける。実際に web リクエスト内で外部 SDK を呼ぶ経路:

| 経路 | 呼び出し | 現状の上限 |
|---|---|---|
| `TakeRegistrationService::register()` (撮影テイク登録) | S3 `HeadObject` | **無制限 × 3 回試行** |
| `CashierAutoRechargeGateway::createSetupCheckout()` 等 (Checkout / Portal 導線) | Stripe API | 80s / 呼び出し |
| `StripeWebhookProcessor` (Stripe webhook) | Stripe API | 80s / 呼び出し |
| SNS 購読確認 (`SnsClient::confirmSubscription`) | AWS SNS | **無制限 × 3 回試行** |

撮影 PWA の登録 API が S3 の応答待ちで詰まると、**php-fpm プール枯渇 = 全画面の停止**に
発展しうる。これは「思考ゼロ・編集ゼロ」で現場作業者が撮る、という使命の一次経路を止める。

**課題 B (回収遅延 / 従): 既定キュー接続の帯が SDK 既定値のせいで過大である。**
`retry_after = 600` は「Stripe SDK が 80s も待つから」という理由だけで置かれている。
上限をアプリ側で固定すれば帯を縮められ、リース切れジョブの回収遅延が縮む。

## 改善アイデア

**「外部 SDK の待ち上限をアプリの単一出典から pin し、その値をキューの帯の根拠にする」**。
3 つの層に分けて置く。

### 1. 値の単一出典を 1 クラスに置く

`app/Support/ExternalClientTimeouts.php` (final class + `public const int`) に
pin する値を集約する。**config ファイルからも参照できる**ことが選定理由である
(config ファイルの中で `config()` を呼ぶのは読み込み順に依存して壊れるが、
オートロード済みクラスの定数参照は安全)。env で上書きできる口は作らない —
`config/queue.php` の `retry_after` が「gate が嘘をつく」という理由でリテラル固定されているのと
同じ理屈で、**静的 gate が読む値と本番の実値を一致させる**ためである。

### 2. 「制御系」と「データ系」で上限を分ける

一律の総時間上限は誤りである。S3 の `readStream` / `writeStream` は
**本文サイズに比例して時間がかかる**ため、短い総時間上限は正当なレンダ素材の
ダウンロード/アップロードを殺す。したがって:

| 面 | connect_timeout | timeout | 根拠 |
|---|---|---|---|
| Stripe (プロセス大域) | 10s | 30s | 単一オブジェクトの create/retrieve/pay のみ。SDK 既定 80s は「大きな一括処理」向けの値 |
| AWS 制御系 (SES 送信 / SNS / S3 メタデータ) | 5s | 15s | 転送量が有界。既存の SNS 証明書取得 pin (`AwsSnsSignatureVerifier`: connect 5 / timeout 10) と同じ帯 |
| AWS データ系 (s3 disk の本文 read/write) | 10s | 900s | 転送量が本体サイズに比例する。ハングの有界化が目的で、正当な転送を殺さない値 |

s3 disk はデータ系の値を持ち、**web リクエストから呼ばれる唯一の S3 ネットワーク操作**である
`TakeObjectStorage::headObject()` だけが AWS SDK 標準の per-command `@http` 上書きで
制御系の値へ絞る。これで「php-fpm を 900 秒占有しうる唯一の口」を塞ぐ。

### 3. 帯 (retry_after / worker --timeout) を pin 値から導く

pin 後の既定接続 `database` の外部呼び出し worst case:

- Stripe: 30s × 最大 8 呼び出し (`ExecuteAutoRechargeAttemptJob` の最長経路。
  customer retrieve / invoice create / invoiceItem create / finalize / pay /
  paymentIntent retrieve / 後始末の void 等) = **240s**
- SES (メール送信ジョブ): 15s × 3 attempts = 45s

したがって既定接続の外部予算を **240s** と宣言し、序列を
`外部予算 240 < worker --timeout 300 < retry_after 360` へ張り替える
(現行 `400 < 540 < 600`)。回収遅延の代償は **最大 510s → 最大 270s** に縮む。

### 4. deny-by-default の目録 gate を置く

`tests/Architecture/ExternalClientTimeoutInventoryTest.php` を新設し、
既存の `ThrottleCoverageInventoryTest` / `QueuedJobLeaseInventoryTest` /
`BillingGatewayFailureTaxonomyInventoryTest` と同じ形で

- **外部 SDK クライアント構築点の全数**を目録に登録させる (対称差ゼロ = exact fit)
- 免除は型付き enum + 30 文字以上の根拠を必須にする
- pin 値が **SDK 既定値と異なる**ことを負のコントロールとして固定する
  (「pin していないのに green」を検出する)
- config を読むだけで終わらせず、**実際に構築したクライアントに値が届いている**ことを
  behavioral に検査する (`AwsClient::getCommand()` の `@http` / `ApiRequestor::httpClient()->getTimeout()`)
- 帯の序列 (外部予算 < worker `--timeout` < `retry_after`) を機械で固定する

## 期待効果

- **使命への貢献**: 撮影 PWA のテイク登録 (SOP → シナリオ → 撮影の一次経路) が
  S3 の無応答で php-fpm を占有し続ける経路を塞ぐ。現場作業者が「録れたのに登録が固まる」
  を踏まないことは、思考ゼロ・編集ゼロの前提そのものである。
- **具体的な改善見込み**:
  - web 経路の外部待ち上限: 無制限 (S3/SNS) / 80s (Stripe) → **15s / 30s**
  - 既定キュー接続のリース切れ回収遅延: 最大 600s → **最大 360s**
    (T127 が問題にした代償 510s → 270s)
  - 「外部 SDK の待ち上限が SDK の既定値に依存している」という**無宣言の状態を解消**し、
    SDK バージョン更新で既定値が動いても静かに帯が壊れなくなる。

## 実装方針（概要）

| # | 変更 | 対象 |
|---|---|---|
| 1 | pin 値の単一出典クラス新設 | `app/Support/ExternalClientTimeouts.php` |
| 2 | Stripe のプロセス大域 pin | `app/Providers/AppServiceProvider.php` (`boot()`) |
| 3 | AWS クライアント 3 構築点へ `http` / `retries` を配線 | `config/filesystems.php` / `config/services.php` / `AppServiceProvider`(SnsClient) |
| 4 | `headObject` の per-command `@http` 上書き | `app/Services/Capture/TakeObjectStorage.php` |
| 5 | 既定接続の帯の張り替え | `config/queue.php` / `mprocs.yaml` / `docs/architecture.md` |
| 6 | deny-by-default 目録 gate 新設 | `tests/Architecture/ExternalClientTimeoutInventoryTest.php` + 免除 enum |

## 制約・前提

- **Stripe の pin はプロセス大域にしか置けない**。`Cashier::stripe()` /
  `$organization->stripe()` / `AppServiceProvider` の `PriceService` bind という
  3 系統の呼び出し口があるが、いずれも `ApiRequestor::httpClient()` (static) を通るため、
  大域 pin 1 本で全経路を覆える。**逆に「クライアントごとに違う timeout」は SDK が支えない**
  (この非対称を誇張して書かない)。
- **テストレーンから実 API を叩かない**。`Http::` 経由の egress guard は
  AGENTS.md の記述どおり Stripe SDK / AWS SDK には効かないため、gate は
  `getCommand()` (送信しない) と getter 参照だけで完結させる。
  Stripe/S3 の fake 配線 (`FakeExternalsServiceProvider` / `FakeStorageGate`) は変更しない。
- **既存 gate の前提を壊さない**。`QueueWorkerLeaseInvariantTest` は
  `retry_after` がリテラルであることと env 上書き不能であることを固定しており、
  `600` を直接 assert している箇所がある。`JobExclusionOrderingInvariantTest` は
  `AutoRechargeService::LOCK_TTL_SECONDS = 180` / `uniqueFor = 30` が
  `retry_after` を下回ることを固定している。新値 360 でも両方成立するが、
  前者の literal assert は更新が要る。
- **T132 (`GatewayFailureClassifier`) との整合**: Stripe の timeout 由来例外は
  cURL エラーであり `ApiConnectionException` に写る
  (`ApiRequestor` の cURL 失敗経路)。`directMap()` は既に
  `ApiConnectionException => ProviderUnavailable` を持つため、**分類表の変更は不要**。
  ただし「timeout が ProviderUnavailable に落ちる」ことは現状どこにも明文化されておらず、
  timeout を短くすると出現頻度が上がるため、**この対応関係を behavioral に固定するテストを 1 本足す**
  (分類表を変えないことの根拠を CI に残す)。
- **本番 supervisor の `--timeout` はリポジトリ外**。値表 (`docs/architecture.md`) が正本で
  CI は検知できない。帯を変える以上、docs の値表更新は施策から外せない。

## スコープ外

- **T127 (既定キュー接続の分割) の昇格はしない**。本設計で代償が 510s → 270s に縮むため、
  条件 (「回収遅延が実害として観測されたとき」) は依然未成立である。
  接続を割ると `JobExclusionOrderingInvariantTest` の
  「auto-recharge の 2 ジョブは既定接続で動く」前提も同時に張り替えることになり、
  今必要でない複雑さを持ち込む (AGENTS.md 思考原則 2)。
- **`GatewayFailureClass` への新 case 追加はしない**。timeout は
  「決済事業者側の一時的な不能」であり `ProviderUnavailable` で語彙が足りている。
  分類は観測専用で制御フローを変えないという T132 の裁定を維持する。
- **LLM (Prism) の timeout**。既に `resources/prompts/*.yaml` の `client_options.timeout` で
  pin 済みで `PromptClientTimeoutInvariantTest` が固定している。二重管理にしない。
- **`Http::` (Laravel HTTP client) 経由の外部呼び出し**。reCAPTCHA / SNS 証明書取得は
  すでに呼び出し側で timeout を指定している。今回は SDK 層に閉じる。
- **timeout 超過時のリトライ戦略の変更**。`ExecuteAutoRechargeAttemptJob` は `$tries = 1` で
  リコンサイルに再試行を一本化する設計であり、これを触らない。
- **`AWS_DEFAULT_REGION` の既定値付与など、S3 disk の設定不備の是正**。別件。


---

## 特に判断を求めたい論点

1. **層の選択**: Stripe をプロセス大域 (`ApiRequestor::setHttpClient` / `CurlClient::instance()`) で pin する以外に、SDK が支える選択肢はあるか。無いなら「大域である」ことのリスク (テスト間の状態漏れ・多テナント差異) をどう受け止めるべきか。
2. **制御系 / データ系で timeout を分ける判断**は妥当か。それとも `headObject` の per-command 上書きは過剰 (思考原則 2 違反) で、s3 disk 全体を短い値にするか、逆に全体を長いままにすべきか。
3. **帯の張り替え (retry_after 600 → 360, worker --timeout 540 → 300) を同一 PR に含める判断**は妥当か。分離すべきか。分離すると「pin だけ入って帯が古いまま」という中間状態が残るが、それは許容できるか。
4. **T127 を昇格させない判断**の妥当性。
5. 見落としている外部 SDK 経路はあるか (この設計は Stripe / AWS S3 / AWS SES / AWS SNS の 4 面を対象としている)。
