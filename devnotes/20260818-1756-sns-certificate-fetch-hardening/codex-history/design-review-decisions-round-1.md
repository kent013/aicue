# 対応マトリクス: design-review Round 1

## A. 値オブジェクト

### [Suggestion] `is_string()` による fail-closed な型絞り込み / 「振る舞いは変わらない」の不正確さ
- 判断: 両方とも対応する
- 対応内容: `scheme` / `host` / `path` を `is_string()` で確定してから比較する形に書き換えた。
  リスク欄も「credential と fragment の拒否は意図した後方非互換。既存の正当な SNS URL の
  振る舞いは維持する」に直した。

## B. config

### [Warning] `timeout()` は読み取り timeout ではなくリクエスト全体の timeout
- 判断: 対応する
- 根拠: 指摘のとおり Guzzle の `timeout` は転送全体の上限である。
- 対応内容: キー名を `request_timeout_seconds` に変え、契約を
  `0 < connect <= request` と `request + post <= lock 寿命` の 2 本に書き直した。
  値も 2 / 5 / 2 / 8 に取り直した。

### [Warning] `services.ses` へ足すと AWS SDK が未知キーを無視することへ依存する
- 判断: 対応する
- 根拠: 依存を作らずに済むならそのほうがよい。既存キーの同居は新規キーの根拠にならない。
- 対応内容: `config/services.php` の**トップレベルに `sns_certificate` を新設**し、
  `services.ses` には一切足さないことにした (`services.ses` は `SesV2Client` の構築引数へ
  素通しされるが、`services.sns_certificate` は素通しされない)。
  参照実装 (motivation) は `services.ses.webhook.*` に置いているが、
  置き場所は裁定 AG-199 の 8 要件に含まれないので合わせない。

## C. 取得口

### [Critical] ロック寿命の予算に DNS / SSRF 検査時間が入っていない
- 判断: 対応する (指摘の修正案 2「SSRF 検査をロック取得前へ移す」を採る)
- 根拠: `UrlSafetyInspector` の DNS 解決には明示的な時間上限が無く、
  ロック寿命の予算に入れられない。ロックの外へ出せば予算の話から消える。
  副次的な利点として、**SSRF で拒否される要求がロックを 1 度も触らなくなる**
  (攻撃者がロックを占有できる面が減る)。
- 対応内容: `fetchSerialized()` の冒頭でロックを取る**前**に `inspect()` を掛ける形にした。
  ロックが包むのは「キャッシュ再確認 + HTTP 取得 + サイズ / PEM 確認」だけになる。
  併せて `post_fetch_budget` を「保証値ではなく安全余裕」と明記した
  (キャッシュ再確認の I/O と PEM 解析には強制上限が無い)。
  DNS 解決に時間上限が無いことは「保証しないもの」へ書いた。

### [Critical] 「署名検証済みだけをキャッシュ」が呼び出し規約に留まっている
- 判断: 対応する (指摘の修正案 2 + 3 を採る)
- 根拠: 取得・署名検証・昇格を 1 つの API に閉じるには取得口へ署名検証を呼び戻す
  制御反転が要る (概念設計 Round 1 で反論済み)。代わりに、本リポジトリの作法である
  **既定拒否の目録**で呼び出し site を固定するほうが構造に合う。
- 対応内容:
  - `remember()` を `rememberVerified()` へ改名 (名前が前提条件を言う)
  - 契約テストに「`app/` の中で `rememberVerified(` を呼ぶ site が**ちょうど 1 件**で、
    それが `AwsSnsSignatureVerifier` の中の `$validator->validate(` **より後の行**である」
    を追加した (exact-fit)
  - 取得口のテスト (F) は `rememberVerified()` を使わず、
    キャッシュを直接 `Cache::put()` で仕込む形に変えた。
    昇格の経路は実 verifier 経由 (G9 / H4) で確認する

### [Warning] `withoutRedirecting()` + `throw()` は 3xx を弾かない
- 判断: 対応する
- 根拠: `Response::failed()` は 4xx / 5xx だけなので、3xx の本文が PEM として読めれば
  受理されてしまう。実際の穴である。
- 対応内容: `->throw()` をやめ、**`$response->successful()` (2xx) でなければ 503** に
  写像する形へ変えた (これで 3xx も 4xx も 5xx も一様に扱える)。
  帰結として捕まえる例外は `ConnectionException` だけになる (`RequestException` は
  `throw()` が投げるものなので出番が無くなる)。
  「3xx を受理せず Location へ追従しない」テストを追加した。

### [Warning] 「キャッシュ読み障害で署名検証を止めない」は条件付き
- 判断: 対応する
- 対応内容: 「読みだけが失敗しロック基盤が生きている場合は miss として続行する。
  同じ store が両方を担うので、store ごと落ちればロック取得も失敗して 503 になる」に
  記述を狭めた。

## D. 署名検証器

### [Critical] C と同じ (昇格が呼び出し順の作法に留まる)
- 判断: 対応する (C と同じ対応)

### [Warning] `$fresh` は「実際に HTTP 取得した値」とは限らない
- 判断: 対応する
- 対応内容: `fetchSerialized()` の戻り値を値オブジェクト
  `SnsCertificate { string $pem; bool $fromCache; }` にした。
  verifier は `fromCache === false` のときだけ昇格させる。

### [Suggestion] vendor 契約の pin
- 判断: 対応する
- 対応内容: 「両キーを同時に持つ封筒に対し、vendor は **lambda キーの値**を取りに行く」
  ことを `MessageValidator` を直接使って固定するテスト (G10) を追加した。
  これは**両キー拒否という対策の前提**そのものなので、vendor が変わったら赤くなる。

## E. 契約テスト

### [Critical] 主要防御の配線を固定していない
- 判断: 対応する
- 対応内容:
  - 「この検査を外すと何が赤くなるか」の**突然変異一覧**を設計へ入れ、
    各防御が最低 1 つの検査に対応していることを表で示した
  - SSRF 検査 / サイズ上限 / PEM 確認は**振る舞いテストが赤くなる** (F3 / F4 / F5 / F6)
    ので、それを表に明記した
  - `connectTimeout` / `timeout` / `withoutRedirecting` は Laravel の `Http::fake()` から
    リクエストオプションを観測できないため、**字句で配線を固定する検査 (C10)** を足した
    (それぞれちょうど 1 回、時間の引数が config 由来であること)
  - 昇格 site の exact-fit (C11) を足した

### [Critical] 部分修飾名を解決しないまま「唯一性」を名乗るのは規約 (b) と緊張する
- 判断: 対応する (指摘の修正案 2 + 3 を採る)
- 対応内容: 走査根の中に部分修飾名 (`T_NAME_QUALIFIED`) が現れたら
  **未解決として gate を失敗させる**検査 (C12) を足し、
  テスト名と保証を「走査根の中の、解決可能な参照の範囲」に明示的に狭めた。

### [Warning] `file_get_contents` / Guzzle / curl を検出していない
- 判断: 対応する
- 対応内容: 走査根で通信の原語 (`file_get_contents` / `fopen` / `curl_init` /
  `curl_exec` / `stream_context_create` / `GuzzleHttp\Client` /
  `Symfony\Component\HttpClient\HttpClient`) が 0 件であることを検査する C13 を足した。
  それでも網羅は主張せず、保証範囲を docblock に書く。

### [Warning] C8 / C9 の書き分け
- 判断: 対応する
- 対応内容: 「検出器の自己検査 (合成入力に対する正負) は最初から緑であるべき」
  「施策 C 実装前に赤いのは本番コードに対する契約 assertion のほう」と書き分けた。

## F. 取得口の振る舞いテスト

### [Critical] `203.0.113.10` は TEST-NET-3 なので拒否されうる
- 判断: 反論する + 安全策は対応する
- 根拠: 本リポジトリが使う `UrlSafetyInspector` (kent013/laravel-ssrf-pin ^0.2) の
  拒否 CIDR は `0.0.0.0/8` `10/8` `100.64/10` `127/8` `169.254/16` `172.16/12`
  `192.0.0.0/24` `192.168/16` `198.18/15` `224/4` `240/4` `255.255.255.255/32` であり、
  **`203.0.113.0/24` (TEST-NET-3) は入っていない**ので許可される (vendor 実読で確認)。
  `config/ssrf-pin.php` の `additional_deny_cidrs` も空である。
- 対応内容: それでも fixture の前提を人の記憶に頼らないよう、
  **正常系 fixture について `inspect()->allowed === true` を先に固定する正のコントロール**
  (F0) をテスト計画へ足した。境界が変わったらここが最初に赤くなる。

### [Warning] F15 の例外型
- 判断: 対応する
- 根拠: `Cache::lock()` は `CacheManager::__call` → `Repository::__call` →
  `$this->store->lock(...)` と転送されるので、`lock()` を持たない store では
  PHP の `Error` (未定義メソッド呼び出し) になる (`BadMethodCallException` ではない。
  Laravel 12 の実物を読んで確認)。
- 対応内容: 本質は「握り潰さず伝播すること」なので、
  「`SnsVerificationUnavailableException` にならずそのまま伝播する」ことを検査し、
  具体型は実装時の実測に合わせると書いた。

### [Warning] 不足しているテスト
- 判断: すべて対応する
- 対応内容: 3xx 非追従 / TLS 検証が有効なまま (C5 の字句 + 配線 C10) /
  成功時・HTTP 失敗時・PEM 不正時のロック解放 / キャッシュ寿命が設定値どおり /
  URL が違えばキーも違う / 各テストでキャッシュを隔離する (`beforeEach` で `Cache::flush()`) を
  テスト一覧へ足した。
  リクエストオプションの behavioral な観測は Laravel の fake からはできないので、
  そこは字句の配線検査 (C10) が担うと明記した。

## G. 署名検証器テスト

### [Warning] G4 / G5 の fixture が新実装では PEM 確認で落ちる
- 判断: 対応する (実際の穴の指摘)
- 対応内容: `SnsTestData::certificatePem()` (PEM としては読めるが署名とは一致しない証明書) を
  使う形に変え、HTTP を出したことと**キャッシュに載っていないこと**も併せて検査する。

### [Warning] G9 のキャッシュ隔離
- 判断: 対応する (F と同じ `beforeEach` の `Cache::flush()`)

## H. middleware E2E

### [Warning] 成功系が無い
- 判断: 対応する
- 対応内容: 署名済み通知で実 verifier を通し、**200 と `EmailSuppression` の作成**まで
  確認する H4 を足した。2 回目で HTTP 取得が増えないことも同じテストで見る。

## I. テスト部品

### [Warning] 擬似コードのままで PHPStan level 10 の要点が確定していない
- 判断: 対応する
- 対応内容: 完全なコードを書いた (`openssl_*` の `false` narrowing、
  `$signature` の初期化、静的キャッシュの型、warning 例外化の扱いを含む)。

### [Warning] lambda キー単独の fixture の作り方
- 判断: 対応する
- 対応内容: `SnsTestData::lambdaStyleNotification()` を足した
  (`SigningCertURL` を持たず `SigningCertUrl` だけを持つ封筒を作る)。
  両キー同時の封筒は `notification()` に `SigningCertUrl` を override で足せば作れる。

## J. 目録

### [Warning] rationale 文言 / 昇格 site の登録
- 判断: 対応する
- 対応内容: rationale を
  「MessageValidator 自身は transport を構築せず、証明書取得は SnsCertificateFetcher へ委譲する」に。
  昇格 site の唯一性は施策 E の C11 が持つ。

## K. 文書

### [Warning] 保証の書きすぎ
- 判断: 対応する
- 対応内容: 4 点 (ロック寿命による permit 1 / 検証済みのみ昇格 / 取得口の唯一性 /
  キャッシュ読み障害時の継続) をすべて条件付きの書き方に直し、
  `docs/architecture.md` の節に「保証しないもの」を正本として置くことにした。
