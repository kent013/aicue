# 対応マトリクス: conceptual-review Round 2

## [Critical] 「クライアント取得点」の目録では S3 の 900s 継承を塞げない (母集団は S3 到達境界であるべき)

- 判断: **対応する** (指摘どおり母集団を張り替える。ただし保証範囲は誇張せず明記する)
- 根拠: 指摘は正しい。`Storage::disk('s3')->exists()` / `->delete()` /
  `->readStream()` は `getClient()` を通らず内部 `S3Client` を使う。
  実査すると `app/` 配下の `Storage::disk(` 呼び出しは **6 site / 4 クラス**しかなく
  (`TakeObjectStorage` 4 / `RenderObjectStorage` 1 / fake 2)、
  「S3 到達境界」を exact-fit の母集団にすることは十分に現実的である。
  また `Aws\Sdk::createClient()` 経路の指摘も受け、走査対象に `Aws\Sdk` を加える。
- 対応内容:
  1. **Gate A (到達境界)**: `app/` 全体を token 走査し
     `Storage::disk(...)` / `->getClient(` / `new \Aws\…Client(` / `Aws\Sdk` 参照 の
     **全 site** を目録と対称差ゼロで突き合わせる。disk 名は文字列リテラル必須
     (動的名は違反)。免除は型付き enum + 30 文字以上の根拠。
  2. **Gate B (面分類)**: 到達境界に登録された実装 adapter
     (`TakeObjectStorage` / `RenderObjectStorage`) の **public メソッド全数**を
     Reflection で列挙し、`S3OperationSurface` enum
     (`NoNetwork` / `BoundedControl` / `Bulk`) のいずれか + 30 文字以上の根拠で
     目録登録を必須にする (対称差ゼロ)。
     `BoundedControl` は per-command 制御系 option を積むことを behavioral に固定する。
  3. 「クライアントを得る口は 2 パターンしかない」という**断定は削除**する。
  4. **保証範囲を誇張しない断り書き**を設計に入れる:
     機械で証明できるのは (i) 到達境界が目録に閉じている (ii) 全 public メソッドが
     面分類を持つ (iii) `BoundedControl` が短い option を実際に積む、の 3 点であり、
     「`Bulk` を web 同期経路から呼ばない」は**規約であって証明ではない**。
  5. 補助として、`app/Http/` 配下で adapter 型を参照するファイルを目録化する
     (現状 2 本。新規追加時にレビューを強制できて偽陽性が出ない粒度)。
- 補足 (実装可能性の実査): Flysystem の write 経路 (`AwsS3V3Adapter::upload()`) は
  `createOptionsFromConfig()` が `AVAILABLE_OPTIONS` / `MUP_AVAILABLE_OPTIONS` しか
  転送しないため、**`@http` を注入できない**。したがって
  「client 既定を短くして bulk だけ長くする」という fail-safe 反転は取れない。
  client 既定はデータ系の値を持たざるを得ない — これが `Bulk` を面分類で
  明示する必要がある根拠でもある。

## [Warning] `timeout × attempts` は「実効上限」ではない (DNS / credential / endpoint discovery / backoff が外側にある)

- 判断: **対応する**
- 対応内容: 表の列名を「**HTTP 試行 timeout 予算**」へ変更し、
  「SDK 操作全体の wall-clock deadline ではない」旨を明記する。
  php-fpm 枯渇の有限化という主張は維持するが、厳密な deadline とは書かない。

## [Warning] getter-only では Stripe 大域 pin の独立検査にならない

- 判断: **対応する** (Round 1 の反論を撤回する)
- 根拠: 指摘が正しい。`ApiRequestor::$_httpClient` / `Stripe::$maxNetworkRetries` は
  **PHP プロセス大域**でアプリ再生成では戻らないため、「配線を消しても ambient state で green」
  という余地を論証で排除しきれない。
- 対応内容:
  1. pin を **専用 provider `ExternalClientTimeoutServiceProvider`** へ切り出す
     (`AppServiceProvider::boot()` に混ぜない)。これによりテストが
     **provider の `boot()` だけを再実行**でき、他の副作用 (Event::listen 等の二重登録) を
     踏まない。
  2. 専用テストで「既知の初期状態へ戻す → provider を boot → pin 値を検査 →
     `finally` で元の client と `maxNetworkRetries` を復元」を行う。
  3. `setHttpClient` / `setMaxNetworkRetries` / `CurlClient::instance()` の
     **呼び出し site を Gate A の目録に含める** (app/ 側は provider の 1 箇所だけ、
     tests/ 側は許可された 2 本だけ、と exact-fit で固定する)。

## [Warning] `240 < 300` は外部予算だけの序列で、ジョブ全体の上限を証明していない / 呼び出し数のドリフト

- 判断: **対応する** (値を組み直し、呼び出し回数を behavioral に固定する)
- 根拠: 指摘が正しい。60s の余白は薄く、呼び出しが 9 回になれば 30s まで縮む。
  Cashier 内部 (`createOrGetStripeCustomer`) の呼び出しは静的計数では数えられないため、
  **静的な site 計数ではなく実行時の HTTP 呼び出し回数**で固定するのが正しい。
- 対応内容:
  1. Stripe の timeout を **30s → 20s** へ引き下げる (実測 p99 の 10 倍のヘッドルーム)。
  2. `DEFAULT_CONNECTION_STRIPE_CALL_BUDGET = 10` を定数化 → 外部予算 **200s**。
  3. `DEFAULT_CONNECTION_LOCAL_HEADROOM_SECONDS = 100` を定数化 (非外部処理の余白) し、
     gate が `外部予算 + 余白 <= worker --timeout < retry_after` を機械固定する。
     余白 100s の根拠は docs に書く。
  4. 呼び出し回数のドリフト固定は **`Stripe\HttpClient\ClientInterface` を実装した
     計数 fake を `ApiRequestor::setHttpClient()` で差し込み**、実 `CashierAutoRechargeGateway`
     経由で `executeAttempt` を走らせて **HTTP 呼び出し回数 <= 10** を assert する
     (Stripe SDK 公式の seam。送信は発生しないので egress 規約に抵触しない)。
- 新しい帯: `外部予算 200 + 余白 100 = 300 = worker --timeout 300 < retry_after 360`。

## [Warning] ローリングデプロイ条件 (旧コード混在中に retry_after を縮めない)

- 判断: **対応する**
- 対応内容: `docs/architecture.md` の値表の直下に「**帯を変更するときのデプロイ順序**」を新設する:
  1. **先に** supervisor の `--timeout` を 540 → 300 に変更して worker を再起動する
     (このときコードは旧のまま = `retry_after 600`。`300 < 600` で規則 1 は成立)
  2. 新コード (pin + `retry_after 360`) をデプロイし、**全 worker を入れ替える**
  3. 旧 worker が残っていないことを確認する
  この順序なら「`retry_after 360` の期間に `--timeout 540` の worker が居る」窓は開かない。
  手順 1 で旧 Stripe 80s 前提のジョブが 300s で SIGALRM されうる点は
  `$tries = 1` + リコンサイルが受け止める (受容済み・明記する)。

## [Suggestion] shape の `mode` を literal string まで狭める

- 判断: **対応する**。`array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: 'legacy', max_attempts: int}}` にする。
