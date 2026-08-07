# 概念設計レビュー Round 4 (T126)

Round 3 の [Critical] 1 件・[Warning] 4 件・[Suggestion] 1 件をすべて反映しました。
指摘のうち反論したものはありません (すべて受け入れています)。

---

## 対応マトリクス (Round 3)

# 対応マトリクス: conceptual-review Round 3

## [Critical] Gate A に迂回経路がある (`app('filesystem')->disk('s3')` / DI 注入 / attribute 注入)

- 判断: **対応する** (指摘どおり「呼び出しの発見」から「クラスの allowlist」へ張り替える)
- 根拠: 指摘の 3 例はいずれも `Storage::disk(` 固定の走査を通らない。
  提案された「S3/Flysystem に触れてよいクラスを adapter へ限定する」方が
  deny-by-default として安定する、という判断に同意する。
- 対応内容: Gate A の母集団を **「次のいずれかを持つ app/ 配下のクラス集合」** へ変更した:
  - `Illuminate\Support\Facades\Storage` / `Illuminate\Container\Attributes\Storage` の参照
  - `->disk(` / `::disk(` の呼び出し (**receiver を問わない**)
  - `Illuminate\Contracts\Filesystem\Filesystem` / `Illuminate\Filesystem\FilesystemManager` /
    `Illuminate\Filesystem\FilesystemAdapter` / `Illuminate\Filesystem\AwsS3V3Adapter` /
    `League\Flysystem\` の型参照 (**DI 注入でも型は必ず現れる**)
  - `Aws\` 名前空間への任意の参照
  - `->getClient(`

  登録できるのは adapter とその fake だけで、他は型付き enum 免除 + 30 文字以上の根拠が要る。
  加えて **保証範囲の断り書き**を入れた (文字列キーの container 解決だけで型参照も
  `disk(` も出さない経路は検出できない。規約として docs に書く)。

## [Warning] `NoNetwork` という名称・説明が強すぎる (credential provider はネットワークへ出うる)

- 判断: **対応する**
- 対応内容: case 名を **`NoObjectRequest`** へ変更し、定義を
  「S3 オブジェクト API を送信しない。**credential 解決は保証外**」に改めた。

## [Warning] `app/Http/` の参照ファイル目録は弱い (Controller → Service → adapter で抜ける)

- 判断: **対応する** (補助目録を Feature テストへ差し替える)
- 根拠: 指摘のとおり、参照ファイル目録では中間 Service を挟むと検出できない。
  提案された「既存 web 経路については Feature テストで `BoundedControl` だけが
  呼ばれることを固定する」方が、同じコストで実効性が高い。
- 対応内容: 補助を **Feature テスト**に置き換えた。撮影テイク登録エンドポイントを実行し、
  spy adapter が記録した呼び出しメソッド集合が `NoObjectRequest` ∪ `BoundedControl` に
  含まれる (= `Bulk` を 1 つも呼ばない) ことを assert する。
  保証範囲の断り書き (「規約であって証明ではない」) はそのまま残す。

## [Warning] `200 + 100 = 300 = worker --timeout` は等号で不十分

- 判断: **対応する** (指摘の値をそのまま採用)
- 根拠: worker は `--timeout` 到達で SIGALRM に落とされるため、
  「許容予算を使い切っても完了できる」関係でなければ不変条件として意味がない。
- 対応内容: 序列を
  **`外部予算 200 + 局所処理予算 90 = 290 < worker --timeout 300 < retry_after 360`**
  へ改めた。gate は厳密不等号で検査する。デプロイ手順の値 (`--timeout=300`) は変わらない。

## [Warning] 計数 fake の単一成功経路では最長経路を証明できない

- 判断: **対応する**
- 対応内容: 代表経路をデータセット化して各経路で `<= 10` を検証する:
  (a) 成功 (customer 新規) / (b) 成功 (customer 既存) /
  (c) カード拒否 → invoice void の後始末 / (d) 既存 invoice の再利用 (finalize 済み)。
  将来分岐が増えたらデータセットへの追加を要求できる形にする。

## [Suggestion] `try/finally` の範囲を退避直後から取る

- 判断: **対応する**。詳細設計のテストコードで退避直後に `try` を開く形を明示する。


---

## 改訂後の概念設計 (全文)

# 概念設計: external-sdk-client-timeout (T126 外部 SDK の client timeout を pin する)

> **目的の主従 (最初に固定する)**
> **主**: web リクエスト経路の外部 SDK 待ちを有限化し、php-fpm ワーカーの枯渇を防ぐ。
> **従**: その結果として過大になっていた既定キュー接続の帯 (`retry_after` / worker `--timeout`) を縮める。
> この順序は施策の並び・テスト名・docs の記述にも反映する。T127 (キュー接続の分割) は
> **従**の側の話であり、本設計の主目的ではない。

## 背景・課題

### 事実確認 (実コードと vendor を読んで確認した内容)

1. **Stripe SDK の待ち上限は vendor 既定のまま**である。
   `vendor/stripe/stripe-php/lib/HttpClient/CurlClient.php` の
   `DEFAULT_TIMEOUT = 80` / `DEFAULT_CONNECT_TIMEOUT = 30` が効いており、
   アプリ側に `setTimeout()` / `setConnectTimeout()` / `ApiRequestor::setHttpClient()` を
   呼ぶ箇所は **1 つも無い**。`Stripe\Stripe::$maxNetworkRetries` も既定 0 のまま (明示 pin なし)。
   `Laravel\Cashier\Cashier::stripe()` は `StripeClient` に `api_key` / `stripe_version` /
   `api_base` しか渡さず、**`StripeClient` の config に timeout 系のキーはそもそも無い**
   (`BaseStripeClient::DEFAULT_CONFIG`)。したがって Stripe の待ち上限を動かせる層は
   **`ApiRequestor` の HTTP client (プロセス大域)** だけである。

2. **AWS SDK の待ち上限は「無指定 = 無制限」、かつ既定で 3 回試行する**。
   `vendor/aws/aws-sdk-php/src/ClientResolver.php` の `'http'` 引数は `'default' => []` で、
   `connect_timeout` / `timeout` の既定値を**持たない**。
   `retries` の既定は `Aws\Retry\ConfigurationProvider` の `DEFAULT_MODE = 'legacy'` /
   `DEFAULT_MAX_ATTEMPTS = 3` で、`_apply_retries()` は legacy のとき `maxAttempts - 1` を
   retry 数に使う = **3 attempts**。つまり **1 操作の HTTP 待ちは「無制限 × 3」**である。

   本アプリの AWS クライアント構築点・取得点:
   - `config/filesystems.php` の `disks.s3` → `FilesystemManager::createS3Driver()` が
     `new S3Client($s3Config)` に素通しする
   - `config/services.php` の `ses` → `MailManager::createSesV2Transport()` が
     `new SesV2Client(...)` に素通しする (キュー投入されたメール送信)
   - `app/Providers/AppServiceProvider.php` の `SnsClient` singleton (SNS 購読確認)
   - `app/Services/Capture/TakeObjectStorage::client()` の `Storage::disk('s3')->getClient()`

   **さらに `Storage::disk('s3')` 経由の Filesystem API (`exists` / `delete` /
   `readStream` / `writeStream` / `temporaryUrl`) は `getClient()` を通らずに
   内部の `S3Client` を使う**。`app/` 配下の `Storage::disk(` 呼び出しは
   **6 site / 4 クラス**である (`TakeObjectStorage` 4 / `RenderObjectStorage` 1 / fake 2)。

3. **キューの帯はこの既定値に引きずられている**。`config/queue.php` の `database` 接続は
   `retry_after = 600` で、コメントの根拠がそのまま
   「Stripe 4〜5 呼び出し × SDK 上限 80s = 約 400s」である。
   `docs/architecture.md` §キューのリース期間とワーカー制限時間の規約 の値表も
   `database` = retry_after 600 / worker `--timeout` 540 とこの前提で書かれている。

### 課題 A (主・可用性): web リクエスト経路の外部待ちが実質無制限である

キュー経路には worker の `--timeout` (SIGALRM) というハード上限があるが、
web リクエスト経路には**それが無い**。PHP の `max_execution_time` は Unix では
ソケット待ちの時間を数えないため、外部 SDK が応答しない間 php-fpm ワーカーは占有され続ける。

| web 経路 | 呼び出し | 現状の HTTP 試行 timeout 予算 |
|---|---|---|
| `TakeRegistrationService::register()` (撮影テイク登録) | S3 `HeadObject` (`TakeObjectStorage`) | **無制限 × 3 attempts** |
| `CashierAutoRechargeGateway::createSetupCheckout()` ほか Checkout / Portal 導線 | Stripe API | 80s × 1 attempt |
| `StripeWebhookProcessor` (Stripe webhook) | Stripe API | 80s × 1 attempt |
| SNS 購読確認 (`SnsClient::confirmSubscription`) | AWS SNS | **無制限 × 3 attempts** |

撮影 PWA の登録 API が S3 の応答待ちで詰まると、**php-fpm プール枯渇 = 全画面の停止**に
発展しうる。これは「思考ゼロ・編集ゼロ」で現場作業者が撮る、という使命の一次経路を止める。

### 課題 B (従・回収遅延): 既定キュー接続の帯が SDK 既定値のせいで過大である

`retry_after = 600` は「Stripe SDK が 80s も待つから」という理由だけで置かれている。
上限をアプリ側で固定すれば帯を縮められ、リース切れジョブの回収遅延が縮む。

## 改善アイデア

**「外部 SDK の待ち上限をアプリの単一出典から pin し、その値をキューの帯の根拠にする」**。

### 1. 値の単一出典を 1 クラスに置く

`app/Support/ExternalClientTimeouts.php` (final class + `public const int` + shape 付き
static メソッド) に pin する値を集約する。**config ファイルからも参照できる**ことが選定理由である
(config ファイルの中で `config()` を呼ぶのは読み込み順に依存して壊れるが、
オートロード済みクラスの定数参照は安全)。env で上書きできる口は作らない —
`config/queue.php` の `retry_after` が「gate が嘘をつく」という理由でリテラル固定されているのと
同じ理屈で、**静的 gate が読む値と本番の実値を一致させる**ためである。

AWS 側は

```
array{
    http: array{connect_timeout: int, timeout: int},
    retries: array{mode: 'legacy', max_attempts: int},
}
```

という shape を返す static メソッド経由で配線し、config 3 箇所へ定数を手で撒かない
(綴りずれを PHPStan level 10 で落とす)。

### 2. 「制御系」と「データ系」で上限を分ける

一律の総時間上限は誤りである。S3 の `readStream` / `writeStream` は
**本文サイズに比例して時間がかかる**ため、短い総時間上限は正当なレンダ素材の
ダウンロード/アップロードを殺す。

> **用語**: 以下の「HTTP 試行 timeout 予算」は **cURL / Guzzle に与える 1 試行あたりの
> 上限 × attempts** であり、**SDK 操作全体の wall-clock deadline ではない**。
> DNS 解決・credential provider・endpoint discovery・retry backoff はこの外側にある。
> php-fpm 枯渇を有限化する主張は維持するが、厳密な deadline とは書かない。

| 面 | connect_timeout | timeout | attempts | HTTP 試行 timeout 予算 | 根拠 |
|---|---|---|---|---|---|
| Stripe (プロセス大域) | 5s | 20s | 1 (`max_network_retries = 0`) | **20s** | 単一オブジェクトの create/retrieve/pay のみ。SDK 既定 80s は大きな一括処理向けの値。実測 p99 (数秒) の 10 倍のヘッドルーム |
| AWS 制御系 (SES 送信 / SNS) | 5s | 15s | 2 | **30s** | 転送量が有界。既存の SNS 証明書取得 pin (`AwsSnsSignatureVerifier`: connect 5 / timeout 10) と同じ帯 |
| AWS データ系 (s3 disk のクライアント既定) | 10s | 900s | 2 | 1800s (worker `--timeout` が実際の上限) | 転送量が本体サイズに比例する。ハングの有界化が目的で、正当な転送を殺さない値 |
| S3 制御系 (per-command 上書き) | 5s | 15s | **0 retries** | **15s** | web 同期 API では SDK 内で粘らせず、アプリ側で失敗を返して再操作を促す |

`@http` / `@retries` の per-command 上書きが効くことは vendor で確認済み
(`Aws\AwsClient::getCommand()` が `@http` を `+=` で合成 = 渡した側が勝つ /
`Aws\RetryMiddleware` と `RetryMiddlewareV2` が `$command['@retries']` を読む)。

**逆向き (client 既定を短くして bulk だけ長くする) は取れない**。
Flysystem の write 経路 (`AwsS3V3Adapter::upload()` → `createOptionsFromConfig()`) は
`AVAILABLE_OPTIONS` / `MUP_AVAILABLE_OPTIONS` しか転送せず、**`@http` を注入できない**
(vendor 実査)。client 既定はデータ系の値を持たざるを得ない。

### 3. 「S3 到達境界」と「操作の面」を deny-by-default の目録にする

per-command 上書きだけでは、**将来 web 経路に足された metadata 操作が
データ系の 900s を継承する**という穴が残る。母集団を「クライアント取得点」ではなく
**S3 能力への到達境界**に置いて塞ぐ。

**Gate A (到達境界 = S3/Flysystem に触れてよい「クラス」の allowlist)**:
「S3 の全呼び出しを発見する」のではなく、**S3/Flysystem/AWS SDK に触れてよいクラスを
adapter へ限定する**。`app/` 配下の全 PHP ファイルを token 走査し、次のいずれかを持つ
**クラスの集合**を目録と**対称差ゼロ**で突き合わせる:

- `Illuminate\Support\Facades\Storage` / `Illuminate\Container\Attributes\Storage` の参照
- `->disk(` / `::disk(` の呼び出し (**receiver を問わない**。
  `app('filesystem')->disk('s3')` / `resolve(FilesystemManager::class)->disk('s3')` を拾うため)
- `Illuminate\Contracts\Filesystem\Filesystem` / `Illuminate\Filesystem\FilesystemManager` /
  `Illuminate\Filesystem\FilesystemAdapter` / `Illuminate\Filesystem\AwsS3V3Adapter` /
  `League\Flysystem\` の型参照 (**DI 注入でも必ず型が現れる**)
- `Aws\` 名前空間への任意の参照 (`new` / 型宣言 / static 呼び出し / `::class`)
- `->getClient(`

登録できるのは adapter (`TakeObjectStorage` / `RenderObjectStorage` / それらの fake /
`FakeObjectStore`) だけで、それ以外は**型付き enum 免除 + 30 文字以上の根拠**が要る。
業務層 (Controller / Service / Job) は `TakeObjectStorage` / `RenderObjectStorage` しか
参照できない、という境界がこれで deny-by-default になる。

Stripe 側も同じ目録に載せる — `ApiRequestor::setHttpClient()` /
`Stripe::setMaxNetworkRetries()` / `CurlClient::instance()` の呼び出し site は
**app/ では pin 用 provider の 1 箇所だけ**であることを exact-fit で固定する。

> **保証範囲 (誇張しない)**: 検出できるのは**ソースに現れる型参照とメソッド名**である。
> 文字列キーの container 解決を経由し、`disk(` も上記の型参照も一切出さない経路
> (例: 事前に bind した別名を文字列で引いて Filesystem を得る) は検出できない。
> この抜け道が実在しないことは目録の対称差ゼロでは証明できないため、規約として docs に書く。

**Gate B (面分類)**: 到達境界に登録された実装 adapter
(`TakeObjectStorage` / `RenderObjectStorage`) の **public メソッド全数**を Reflection で列挙し、
`S3OperationSurface` enum のいずれか + 30 文字以上の根拠で目録登録を必須にする (対称差ゼロ)。

| case | 意味 | 例 |
|---|---|---|
| `NoObjectRequest` | **S3 オブジェクト API を送信しない** (ローカル署名 / 文字列生成)。**credential 解決がネットワークへ出る可能性は保証外** | `presignUpload` / `temporaryPlaybackUrl` / `temporaryDownloadUrl` / `keyPrefixFor` / `contentDisposition` |
| `BoundedControl` | 転送量が有界なメタデータ操作。**per-command 制御系 option を積むことが必須**。web 同期経路から呼んでよい | `headObject` |
| `Bulk` | 本文転送、または Flysystem 経由で option を注入できない操作。disk 既定 (900s) を継承する。**web 同期経路から呼ばない** | `downloadToLocal` / `upload` / `delete` / `exists` |

> **保証範囲を誇張しない**。機械で証明できるのは
> (i) S3/Flysystem/AWS への到達クラスが目録に閉じていること、
> (ii) adapter の全 public メソッドが面分類を持つこと、
> (iii) `BoundedControl` が実際に短い option を積むこと、の 3 点である。
> **「`Bulk` を web 同期経路から呼ばない」は規約であって証明ではない**
> (Controller → Service → adapter の呼び出しグラフ解析が要り、静的近似は偽陰性が静かに増える)。
> 代わりに **既存の web 経路については Feature テストで固定する** —
> 撮影テイク登録エンドポイントを実行し、spy adapter が記録した呼び出しメソッド集合が
> `NoObjectRequest` ∪ `BoundedControl` に含まれる (= `Bulk` を 1 つも呼ばない) ことを assert する。

### 4. 帯 (retry_after / worker --timeout) を pin 値から導く

pin 後の既定接続 `database` の外部呼び出し予算:

- Stripe: 20s × **呼び出し予算 10 回** = **200s**
  (`ExecuteAutoRechargeAttemptJob` の最長経路。customer retrieve / invoice create /
  invoiceItem create / finalize / pay / paymentIntent retrieve / 後始末の void 等)
- SES (メール送信ジョブ): 15s × 2 attempts = 30s

**呼び出し予算 10 回はドリフトする**ため、静的な site 計数ではなく
**実行時の HTTP 呼び出し回数**で固定する — `Stripe\HttpClient\ClientInterface` を実装した
計数 fake を `ApiRequestor::setHttpClient()` で差し込み、実 `CashierAutoRechargeGateway`
経由で `executeAttempt` を走らせて **HTTP 呼び出し回数 <= 10** を assert する
(Stripe SDK 公式の seam。実送信は発生しないので egress 規約に抵触しない)。
**単一の成功経路では最長経路を証明できない**ため、外部呼び出し列が異なる**代表経路を
データセット化**して各経路で `<= 10` を検証する:
(a) 成功 (customer 新規)、(b) 成功 (customer 既存)、
(c) カード拒否 → invoice void の後始末、(d) 既存 invoice の再利用 (finalize 済み) 経路。
将来分岐が増えたらデータセットへの追加を要求できる形にする。

さらに **非外部処理の余白**を定数で持ち、gate が序列を機械固定する:

```
外部予算 200s + 局所処理予算 90s = 290s < worker --timeout 300s < retry_after 360s
```

**厳密な不等号にする**のが要点である。worker は `--timeout` 到達時に SIGALRM で
落とされるため、「許容予算を使い切っても完了できる」関係
(`外部予算 + 局所予算 < worker --timeout`) でなければ意味がない
(等号だとタイマー精度・起動処理の分だけ足りない)。

(現行は `400 < 540 < 600`)。回収遅延の代償は **最大 510s → 最大 270s** に縮む。
既存 gate の前提は新値でも成立する
(`AutoRechargeService::LOCK_TTL_SECONDS = 180 < 360` / `uniqueFor = 30 < 360`)。

### 5. gate の空振り防止

- 母集団 0 件は fail (到達境界 site / adapter public メソッド / driver=database 接続)
- **負のコントロール**: pin 値が SDK 既定と**異なる**ことを固定する
  (`STRIPE_TIMEOUT_SECONDS !== CurlClient::DEFAULT_TIMEOUT` 等)。
  「pin していないのに green」を検出する
- **exact fit**: 走査結果と目録の対称差ゼロ (件数一致ではなくキー同一性)
- **behavioral**: config を読むだけで終わらせず、構築したクライアントに値が届いていることを
  `AwsClient::getCommand()` の `@http` / `@retries` で確認する
- **mutation で赤化を確認**する手順を詳細設計に書く (素の main では赤にならない gate の受け入れ)

## 期待効果

### 主 (可用性) — HTTP 試行 timeout 予算

| 経路 | 現状 | 変更後 |
|---|---|---|
| 撮影テイク登録の S3 `HeadObject` (web) | 無制限 × 3 attempts | **15s** (timeout 15s / retry なし) |
| SNS 購読確認 (web) | 無制限 × 3 attempts | **30s** (15s × 2 attempts) |
| Stripe API (web / webhook) | 80s × 1 attempt | **20s** × 1 attempt |
| SES 送信 (queue) | 無制限 × 3 attempts | **30s** (15s × 2 attempts) |
| S3 本文 read/write (queue) | 無制限 × 3 attempts | 900s × 2 attempts (worker `--timeout` が実際の上限) |

### 従 (回収遅延)

- 既定キュー接続のリース切れ回収遅延: 最大 600s → **最大 360s**
  (T127 が問題にした代償 510s → 270s)

### 構造

- 「外部 SDK の待ち上限が SDK の既定値に依存している」という**無宣言の状態を解消**し、
  SDK バージョン更新で既定値が動いても静かに帯が壊れなくなる。
- S3 到達境界と操作の面が目録化され、新しい S3 操作の追加が必ずレビューを通る。

## 実装方針（概要）

Codex の助言に従い、**同一 PR 内で実装順を分ける** (中間状態を残さないため PR は 1 本)。

| 順 | 施策 | 対象 |
|---|---|---|
| 1 | pin 値の単一出典クラス新設 | `app/Support/ExternalClientTimeouts.php` |
| 2 | Stripe pin の専用 provider 新設 | `app/Providers/ExternalClientTimeoutServiceProvider.php` + `bootstrap/providers.php` |
| 3 | AWS クライアント 3 構築点へ `http` / `retries` を配線 | `config/filesystems.php` / `config/services.php` / `AppServiceProvider` (SnsClient) |
| 4 | `headObject` の per-command `@http` + `@retries` 上書き | `app/Services/Capture/TakeObjectStorage.php` |
| 5 | 面分類 enum + 免除 enum + deny-by-default 目録 gate | `app/Enums/Storage/S3OperationSurface.php` ほか + `tests/Architecture/ExternalClientTimeoutInventoryTest.php` |
| 6 | Stripe 呼び出し回数の behavioral 固定 | `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php` |
| 7 | worst-case 表とデプロイ順序の更新 (帯を動かす前に根拠を先に置く) | `docs/architecture.md` |
| 8 | 既定接続の帯の張り替え | `config/queue.php` / `mprocs.yaml` |
| 9 | 既存 lease invariant の更新 (literal 600 の assert 等) | `tests/Architecture/QueueWorkerLeaseInvariantTest.php` |

## 制約・前提

- **Stripe の pin はプロセス大域にしか置けない**。`Cashier::stripe()` /
  `$organization->stripe()` / `AppServiceProvider` の `PriceService` bind という
  3 系統の呼び出し口があるが、いずれも `ApiRequestor::httpClient()` (static) を通るため、
  大域 pin 1 本で全経路を覆える。**「クライアントごとに違う timeout」は SDK が支えない**
  (この非対称を誇張して書かない)。**テナント別 timeout は設計として持たない**。
- **大域状態はテストで復元する**。`ApiRequestor::$_httpClient` / `Stripe::$maxNetworkRetries` は
  **PHP プロセス大域**でアプリ再生成では戻らない。setter を使うテストは
  「pin 配線の検査」と「Stripe 呼び出し回数の検査」の **2 本だけ**に限定し、
  いずれも `finally` で元の値へ復元する。この 2 本以外に setter を呼ばないことを
  Gate A の目録が exact-fit で固定する。pin を **専用 provider** に切り出すのは、
  テストが `boot()` を単独で再実行しても他の副作用を踏まないようにするためである。
- **テストレーンから実 API を叩かない**。`Http::` 経由の egress guard は AGENTS.md の記述どおり
  Stripe SDK / AWS SDK には効かないため、gate は `getCommand()` (送信しない) /
  getter 参照 / 計数 fake だけで完結させる。Stripe/S3 の fake 配線
  (`FakeExternalsServiceProvider` / `FakeStorageGate`) は変更しない。
- **既存 gate の前提を壊さない**。`QueueWorkerLeaseInvariantTest` は `retry_after` が
  リテラルであることと env 上書き不能であることを固定し、`600` を直接 assert している。
  `JobExclusionOrderingInvariantTest` は `LOCK_TTL_SECONDS = 180` / `uniqueFor = 30` が
  `retry_after` を下回ることを固定している。新値 360 でも両方成立するが、
  前者の literal assert は更新が要る。
- **T132 (`GatewayFailureClassifier`) との整合**: Stripe の timeout 由来例外は cURL エラーであり
  `ApiConnectionException` に写る。`directMap()` は既に
  `ApiConnectionException => ProviderUnavailable` を持つため、**分類表の変更は不要**。
  ただし「timeout が `ProviderUnavailable` に落ちる」ことは現状どこにも明文化されておらず、
  timeout を短くすると出現頻度が上がるため、**この対応関係を固定するテストを 1 本足す**
  (分類表を変えないことの根拠を CI に残す)。
- **帯の変更は運用上の破壊的変更 (ローリングデプロイ条件)**。本番 supervisor はリポジトリ外で
  CI が検知できない。`docs/architecture.md` の値表直下に手順を明記する:
  1. **先に** supervisor の `--timeout` を 540 → 300 に変更して worker を再起動する
     (このときコードは旧のまま = `retry_after 600`。`300 < 600` で規則 1 は成立)
  2. 新コード (pin + `retry_after 360`) をデプロイし、**全 worker を入れ替える**
  3. 旧 worker が残っていないことを確認する

  この順序なら「`retry_after 360` の期間に `--timeout 540` の旧 worker が居る」窓は開かない。
  手順 1 で旧 Stripe 80s 前提のジョブが 300s で SIGALRM されうる点は、
  `ExecuteAutoRechargeAttemptJob` の `$tries = 1` + リコンサイルが受け止める (受容する)。
- **`max_network_retries = 0` を pin する理由も docs に残す**: 課金の一回性は
  Stripe idempotency key とリコンサイルで担保する設計 (AGENTS.md ドメイン規約 6) であり、
  SDK 自動 retry に寄せない。

## スコープ外

- **T127 (既定キュー接続の分割) の昇格はしない**。本設計で代償が 510s → 270s に縮むため、
  条件 (「回収遅延が実害として観測されたとき」) は依然未成立である。
  接続を割ると `JobExclusionOrderingInvariantTest` の
  「auto-recharge の 2 ジョブは既定接続で動く」前提も同時に張り替えることになり、
  今必要でない複雑さを持ち込む (AGENTS.md 思考原則 2)。
- **`GatewayFailureClass` への新 case 追加はしない**。timeout は
  「決済事業者側の一時的な不能」であり `ProviderUnavailable` で語彙が足りている。
  分類は観測専用で制御フローを変えないという T132 の裁定を維持する。
- **Socialite の timeout**。Socialite は AWS/Stripe のような SDK ではなく **Guzzle 直呼び**で、
  pin を置く層 (Guzzle client の生成点) がまったく別である。T126 の題名どおり
  「外部 **SDK** の client timeout」に閉じる (混ぜると 1 タスクで 2 つの機構を作ることになる)。
- **LLM (Prism) の timeout**。既に `resources/prompts/*.yaml` の `client_options.timeout` で
  pin 済みで `PromptClientTimeoutInvariantTest` が固定している。二重管理にしない。
- **`Http::` (Laravel HTTP client) 経由の外部呼び出し**。reCAPTCHA / SNS 証明書取得は
  すでに呼び出し側で timeout を指定している。
- **timeout 超過時のリトライ戦略の変更**。`ExecuteAutoRechargeAttemptJob` は `$tries = 1` で
  リコンサイルに再試行を一本化する設計であり、これを触らない。
- **`AWS_DEFAULT_REGION` の既定値付与など、s3 disk の設定不備の是正**。別件。


---

## Round 4 で確認したいこと

Round 3 で承認の残件として挙げられた 2 点
(「S3/Flysystem 到達を adapter へ限定する規則」「`外部予算 + 局所予算 < worker timeout` への修正」)
は上記のとおり反映しました。**全体判定**を示してください。
まだ残件があれば、概念設計の段階で解くべきものか、詳細設計に送ってよいものかも併せて示してください。
