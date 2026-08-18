# Round 2: 詳細設計レビュー (Round 1 指摘への対応)

Round 1 の Critical / Warning をすべて捌きました。反論しているのは 1 件
(F の `203.0.113.10`) だけで、そこも安全策 (正のコントロール) は入れています。
対応マトリクスと修正後の詳細設計全文を送ります。再判定してください。

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


---

## 修正後の詳細設計 (全文)

# 詳細設計: SES/SNS 署名検証の証明書取得経路の強化 (正典 t1 追従、裁定 AG-199)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

本施策に直接効くセキュリティ不変条件:

- **不変条件 8**: 外部 URL 取得は SSRF 検査経由 (`UrlSafetyInspector` / `PinnedHttpClient`)
- **不変条件 11**: キャッシュに入れるのは素のデータだけ。読み戻しは明示的に検査し、
  失敗したら `forget` する

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。**RefreshDatabase** はグローバル適用、個別 `DatabaseTransactions` 禁止
- テストデータは Factory で生成 (本施策は新モデルを追加しないので Factory 追加は無い)
- `declare(strict_types=1)` + 日本語コメント。アーリーリターン推奨
- `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12
- **テストレーンは外向き HTTP が既定拒否** (`StrayHttpRequestGuard`)。`Http::fake()` を必ず書く

## 概念設計リファレンス

`devnotes/20260818-1756-sns-certificate-fetch-hardening/conceptual-design.md` (APPROVED / Round 4)

## 参照実装 (家系の原本を実読済み)

- `motivation@d1eabfbc` の `app/Services/Mail/Sns/SnsCertificateFetcher.php` /
  `SnsCertificateUrl.php` / `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` /
  `tests/Feature/Mail/SnsCertificateFetcherTest.php` — **構造**の出所
- `spirux@eabfab1b` の `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` —
  **(2) 取得直前の URL 同一性検査 / (6b) 検証済みのみキャッシュ昇格 / (7) 通信系限定の例外写像**
  の出所

### vendor 実挙動 (aicue の `vendor/` で実読して確認した事実)

`aws/aws-php-sns-message-validator` 1.10.0:

- `MessageValidator::isLambdaStyle()` は `isset($message['SigningCertUrl'])` を見る。
  真なら `convertLambdaMessage()` が `SigningCertUrl` の値で `SigningCertURL` を**上書き**する
- `validateUrl()` が見るのは `scheme === 'https'` / 末尾 `.pem` / host が
  `sns.<region>.amazonaws.com(.cn)` の 3 点だけ。port も query も path 形式も見ない
- `certClient` が `false` を返すと `InvalidSnsMessageException` に吸収される
  (= 一時障害が 403 に化ける)。**必ず例外を投げる実装にする**
- `Message::$requiredKeys` は `['SigningCertURL', 'SigningCertUrl']` のどちらか一方でよい
  (= lambda キー単独の封筒も構築できる)
- `getStringToSign()` は public。SignatureVersion 1 は `OPENSSL_ALGO_SHA1`

`kent013/laravel-ssrf-pin` ^0.2:

- `PinnedHttpClient::fetch()` の成功結果 `PinnedResponse` は `status` / `headers` /
  `finalUrl` / `hopUrls` しか持たず、**本文を返さない** (証明書取得には使えない)
- `UrlSafetyInspector` の拒否 CIDR (v4) は `0.0.0.0/8` `10/8` `100.64/10` `127/8`
  `169.254/16` `172.16/12` `192.0.0.0/24` `192.168/16` `198.18/15` `224/4` `240/4`
  `255.255.255.255/32`。**`203.0.113.0/24` (TEST-NET-3) は入っていない**ので許可される
  (テスト fixture の前提。施策 F の F0 が正のコントロールとして毎回確かめる)
- `Kent013\SsrfPin\Testing\FakeDnsResolver` が出荷されている (host → IP の固定用)

Laravel 12:

- `Cache::lock()` は `CacheManager::__call` → `Repository::__call` →
  `$this->store->lock(...)` と転送される。`lock()` を持たない store では
  PHP の `Error` (未定義メソッド呼び出し) になる
- `Response::throw()` が投げるのは 4xx / 5xx だけである
  (`Response::failed()` = `serverError() || clientError()`)。
  **`withoutRedirecting()` と併用すると 3xx は例外にならない**

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 検証済み証明書 URL の値オブジェクト新設 | `app/Services/Mail/Sns/SnsCertificateUrl.php` (新規) | 高 |
| B | 証明書取得の予算を config へ置く | `config/services.php` | 高 |
| C | 証明書取得口の新設 | `app/Services/Mail/Sns/SnsCertificateFetcher.php` / `SnsCertificate.php` (新規) | 高 |
| D | 署名検証器の責務を絞る | `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` | 高 |
| E | 取得口の契約を機械固定する | `tests/Architecture/SnsCertificateFetchContractTest.php` (新規) | 高 |
| F | 取得口の振る舞いテスト | `tests/Feature/Mail/SnsCertificateFetcherTest.php` (新規) | 高 |
| G | 署名検証器のテスト改修 | `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` | 高 |
| H | middleware の end-to-end テスト追加 | `tests/Feature/Mail/SesSignatureMiddlewareTest.php` | 高 |
| I | テスト部品の拡張 | `tests/Support/SnsTestData.php` / `tests/Pest.php` | 高 |
| J | 既存目録の更新 | `tests/Architecture/CachePayloadPlainDataGateTest.php` ほか 3 本 | 高 |
| K | 文書更新 | `docs/ses-mail-runbook.md` / `docs/architecture.md` | 中 |

---

## A. 検証済み証明書 URL の値オブジェクト新設

### 変更箇所

- 新規: `app/Services/Mail/Sns/SnsCertificateUrl.php`
- 移設元: `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` L80-112 (`isValidSnsCertUrl`)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` の URL 境界テストに
  credential / fragment の 2 行を追加

### 現行コード

`AwsSnsSignatureVerifier` の private メソッド (呼び出し側の作法で守られている)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

/**
 * **検証済み**の SNS 証明書 URL (値オブジェクト)。
 *
 * 「呼び出し側が検査してから渡す」という**契約ではなく型**で担保する。外部取得の防御は
 * 取得口のクラスの中にも閉じていなければ、経路が 1 本であることを検査で保証しても
 * 「経路の中で検査が抜けた」ことに気付けない。
 * `SnsCertificateFetcher` はこの型しか受け取らない。
 *
 * 検証内容 (`AwsSnsSignatureVerifier::isValidSnsCertUrl()` をここへ移設。二重実装を作らない):
 *  - scheme は https 固定
 *  - credential (user / pass) を持たない
 *  - port 未指定 or 443
 *  - query / fragment を持たない
 *  - host は `sns.{region}.amazonaws.com` (`sns.` 接頭辞必須、region の区間あり)
 *  - path は `/SimpleNotificationService-*.pem`
 *
 * **vendor (`MessageValidator::validateUrl`) より厳しい**ことがこの型の価値である
 * (vendor は `.pem` 終端 + `sns.<region>.amazonaws.com(.cn)` しか見ないため、
 * 同一 host 上の任意の `.pem` と中国パーティションを許してしまう)。
 *
 * 中国パーティション (amazonaws.com.cn) は対象外。利用予定が出たら明示的に広げる。
 */
final readonly class SnsCertificateUrl
{
    private function __construct(public string $value) {}

    /**
     * @throws SnsSignatureInvalidException 書式が SNS 証明書 URL でない (恒久 = 403)
     */
    public static function fromString(string $url): self
    {
        if (! self::isValid($url)) {
            throw new SnsSignatureInvalidException('untrusted SigningCertURL');
        }

        return new self($url);
    }

    private static function isValid(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        // parse_url の要素は string になるはずだが、想定外の型は**拒否側へ倒す**
        // (値オブジェクトなので「読めなかったら通さない」が正しい)。
        $scheme = $parts['scheme'] ?? null;
        if (! is_string($scheme) || $scheme !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (($parts['port'] ?? 443) !== 443) {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $host = $parts['host'] ?? null;
        if (! is_string($host) || preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) !== 1) {
            return false;
        }
        $path = $parts['path'] ?? null;

        return is_string($path)
            && preg_match('#^/SimpleNotificationService-[A-Za-z0-9]+\.pem$#', $path) === 1;
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`self` / `bool`)
- [x] `parse_url()` の `array|false|null` を `is_array()` で narrowing
- [x] 各要素を `is_string()` で確定してから比較する (cast も `mixed` の持ち回りもしない)
- [x] 配列を返さない (値オブジェクトを返す)

### テスト計画

- [x] 既存 `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` の「cert URL 境界」データセットが
      そのまま通る
- [x] 追加行: credential つき (`https://user:pass@sns.us-east-1.amazonaws.com/…`)
- [x] 追加行: fragment つき (`…/SimpleNotificationService-x.pem#frag`)

### リスク

- **credential と fragment の拒否は意図した後方非互換**である
  (t0 は credential を拒否していなかった)。既存の正当な SNS 証明書 URL は
  どちらも持たないので、正常系の振る舞いは維持される。
  credential 拒否は SSRF 検査の `SsrfDenyReason::CredentialInUrl` とも整合する

---

## B. 証明書取得の予算を config へ置く

### 変更箇所

- `config/services.php` の**トップレベル**に `'sns_certificate' => [...]` を新設する

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/SnsCertificateFetchContractTest.php` が値を読む

### 変更後コード

```php
    // SNS 署名検証で使う証明書取得の予算 (App\Services\Mail\Sns\SnsCertificateFetcher)。
    //
    // ★**`services.ses` の中に置かない**。`services.ses` は Laravel の MailManager が
    //   `new SesV2Client(...)` の構築引数へ**素通しする**配列であり、そこへアプリ専用キーを
    //   足すと「AWS SDK が未知キーを無視する」ことに依存が増える。ここは素通しされない。
    //   (参照実装 motivation は services.ses.webhook.* に置いているが、置き場所は
    //    裁定 AG-199 の 8 要件に含まれないので合わせない。)
    //
    // ★**env にしない** — 環境ごとに変えてよい運用値ではなく、大小関係そのものが契約である
    //   (冪等キーの保持期間と同じ理由)。
    //
    // 大小関係の契約 (裁定 AG-199):
    //   0 < connect (2) <= request (5)
    //   request (5) + 後処理余裕 (2) = 7 <= ロック寿命 (8)
    //   待ち上限 = 0 (取れなければ待たない)
    // 待ち上限が 0 であることは値ではなく**実装の形**として
    // tests/Architecture/SnsCertificateFetchContractTest.php が固定する (`->block(` を持たない)。
    // 右の不等号は同テストが算術で固定する。
    //
    // ★SSRF 検査 (DNS 解決) は**ロックの外**で行うためこの予算に入らない
    //   (DNS 解決には明示的な時間上限が無く、予算に入れられないため)。
    'sns_certificate' => [
        // 接続確立の上限 (Guzzle connect_timeout)。
        'connect_timeout_seconds' => 2,
        // リクエスト**全体**の上限 (Guzzle timeout。接続も含む)。
        'request_timeout_seconds' => 5,
        // ロックを保持したまま行う後処理 (キャッシュ再確認・サイズ判定・PEM 解析) の**安全余裕**。
        // ★保証値ではない — これらの処理に強制上限は無い。ロック寿命を決めるための見積である。
        'post_fetch_budget_seconds' => 2,
        // ロックの寿命。処理中に切れると「前の worker が通信中なのに次が取れる」= permit 1 が壊れる。
        // 逆に無期限にすると worker の異常終了で恒久的な 503 になるので有限にする。
        'lock_ttl_seconds' => 8,
        // 証明書名が変われば cache キーも変わるので、差し替え事故の窓を短く保つ意味で 1 時間。
        'cache_ttl_seconds' => 3600,
        // PEM は数 KB。上限を超えた応答は検証もキャッシュもしない
        // (**メモリの上界ではない**。詳細は SnsCertificateFetcher の docblock)。
        'max_bytes' => 16384,
    ],
```

### PHPStan 適合チェック

- [x] 読み出しは `Config::integer('services.sns_certificate.…')` で int を確定させる

### テスト計画

- [x] 施策 E の C2 が大小関係を検査する

### リスク

- `services.ses` を触らないので、`ExternalClientTimeoutInventoryTest` の
  「MailManager は services.ses を SesV2Client の構築引数へ素通しする」テストへの影響は無い

---

## C. 証明書取得口の新設

### 変更箇所

- 新規: `app/Services/Mail/Sns/SnsCertificate.php` (取得結果の値オブジェクト)
- 新規: `app/Services/Mail/Sns/SnsCertificateFetcher.php`

### 波及変更

- container: `AppServiceProvider::bind(SnsSignatureVerifier::class, AwsSnsSignatureVerifier::class)`
  はそのままでよい (コンストラクタ依存は自動解決される。`UrlSafetyInspector` は
  `SsrfPinServiceProvider` が singleton 登録済み)
- テストファイル: 施策 F (新規) / 施策 J (キャッシュ目録)

### 変更後コード (1): 取得結果の値オブジェクト

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

/**
 * 証明書取得の結果。
 *
 * `fromCache` を持つのは、**署名検証が通ったあとに昇格させるのは新しく取得した PEM だけ**
 * だからである (キャッシュから返ってきたものを再度書き戻すと、寿命が伸びるだけでなく
 * 「新しく取得したものだけを昇格させる」という説明とコードが食い違う)。
 */
final readonly class SnsCertificate
{
    public function __construct(
        public string $pem,
        public bool $fromCache,
    ) {}
}
```

### 変更後コード (2): 取得口

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kent013\SsrfPin\Enums\SsrfDenyReason;
use Kent013\SsrfPin\UrlSafetyInspector;
use Throwable;

/**
 * SNS 署名検証用の証明書 (PEM) の取得口。
 * **SNS 署名検証まわりで外部 HTTP を行うのはこのクラスだけ**である
 * (`tests/Architecture/SnsCertificateFetchContractTest.php` が走査根の範囲で固定する)。
 *
 * 責務を 1 クラスへ閉じる理由: 「無認証のリクエストが外部取得を誘発する」経路であり、
 * 防御が散ると必ずどれかが抜ける。ここが持つ防御は 7 点:
 *
 *  1. **取得先の限定** — 引数の型 `SnsCertificateUrl` が SNS 証明書 URL の厳格な書式を
 *     保証する (呼び出し側の作法ではなく**型**で担保する)
 *  2. **SSRF 検査** — `UrlSafetyInspector::inspect()` (セキュリティ不変条件 8)。
 *     `PinnedHttpClient` は本文を返さないので証明書取得には使えず、inspect → fetch の形にする。
 *     **ロックを取る前に行う** — DNS 解決には明示的な時間上限が無くロック寿命の予算に
 *     入れられないため、そして SSRF で拒否される要求にロックを触らせないためである
 *  3. **redirect 禁止** (`withoutRedirecting`) + **2xx 以外を受理しない**
 *  4. **時間予算** — 接続とリクエスト全体を短く取る
 *  5. **応答サイズ上限** — PEM は数 KB。超えた応答は**検証もキャッシュもしない**
 *  6. **PEM 確認** — `openssl_x509_read()` が通ったものだけを扱う
 *  7. **キャッシュと同時取得の抑止** — 下記
 *
 * ## 同時取得の抑止 (ロックの契約)
 *
 * ロックキーは `CERT_FETCH_LOCK_KEY` **1 本だけ**で、`CERT_FETCH_PERMITS = 1` と
 * 1 対 1 に対応する。2 以上へ増やすならロックキーを permit 数へ分割する実装が同時に要る
 * (定数だけ書き換えても実効挙動は変わらない = 検査が偽の安心を与える) ため、
 * 契約テストが `=== 1` と「`Cache::lock()` の site がちょうど 1 つ」を要求する。
 *
 * **URL ごとにロックキーを分けない**。厳格な書式検証を通る URL の末尾は攻撃者が
 * 自由に変えられるため、分けると「存在しない証明書名を並べるだけで同時取得数を増やせる」。
 *
 * **取れなければ待たずに一時障害 (503) へ倒す**。待つと待ち時間ぶん worker を占有し、
 * このクラスが作ろうとしている上界の議論が成立しなくなる。503 は SNS の再送対象なので、
 * 即時の恒久ドロップにはならない (ただし再送期間を超えて競合が続けば配送断念はありうる)。
 *
 * 時間の大小関係 (`config/services.php` の `services.sns_certificate`):
 *
 *   待ち上限 (0) < 接続 (2) <= リクエスト全体 (5)、リクエスト全体 (5) + 後処理余裕 (2) <= 寿命 (8)
 *
 * 右の不等号は「取得中にロックが失効して 2 人目が取り始めない」ためである。
 * ★**後処理余裕は保証値ではなく見積である** — キャッシュ再確認の I/O と PEM 解析に
 *   強制上限は無い。したがって permit 1 は「1 要求のロック保持が寿命を超えない限り」の
 *   条件付きの性質である。
 *
 * ## キャッシュの規律
 *
 * - キーは `CACHE_PREFIX` + URL の sha256 (**キーに URL の平文を残さない**)
 * - 載せるのは**署名検証が通った PEM だけ**である。昇格は `rememberVerified()` で、
 *   呼ぶのは `AwsSnsSignatureVerifier` が `validate()` を通したあとの 1 箇所だけである
 *   (この唯一性は契約テストが `app/` 全体で exact-fit に固定する)。
 *   未検証の応答を載せると、壊れた証明書を寿命のあいだ配り続けて正当な通知を
 *   403 にし続ける = 自作の fail-closed になる
 * - **キャッシュの読み書きで署名検証を失敗させない**。読みの失敗は miss 扱い、
 *   書きの失敗はログのみで続行する。
 *   ★ただしこれは「読みだけが失敗し、ロック基盤は生きている」場合の話である。
 *     同じ store が読み書きとロックの両方を担うので、**store ごと落ちればロック取得も
 *     失敗して 503 になる**
 * - 読み戻しは「文字列 + 空でない + PEM として読める」を検査し、失敗したら `forget` して
 *   miss 扱いにする (セキュリティ不変条件 11)
 *
 * ## 例外の写像 (出所で境界を分ける)
 *
 * | 出所 | 意味 | 扱い |
 * |---|---|---|
 * | `Cache::lock()` の例外 | ロック非対応 store 等の**設定・実装の誤り** | **fail-fast** (握り潰さない) |
 * | `Lock::get()` の `Throwable` | ロック基盤の一時障害 | **503** (排他できない状態で取りに行かない) |
 * | 取得できなかった (競合) | 正常な競合 | **503** |
 * | `ConnectionException` | 接続 / DNS / TLS / timeout | **503** |
 * | 2xx 以外の応答 (3xx / 4xx / 5xx) | 取得先が期待と違う | **503** |
 * | それ以外の `Throwable` (TypeError 等) | **プログラム不具合** | **写像せず伝播** (503 で隠さない) |
 * | SSRF 判定の DNS 解決失敗 | 一時障害 | **503** |
 * | SSRF 判定のその他の拒否 / サイズ超過 / PEM 不正 | 恒久 | **403** |
 * | cache の `get` / `put` / `forget` / `release` の `Throwable` | 最適化の障害 | **best-effort** |
 *
 * ## 保証しないもの (誇張しない)
 *
 * - **DNS rebinding は解消しない**。検査時と接続時で名前解決が変わる TOCTOU は残り、
 *   private IP への TCP 接続と TLS ClientHello そのものは発生する。HTTP 層での取得を
 *   制限するのは「通常の CA 検証が有効であること」を前提とした TLS であり、
 *   取得先の host が型で `sns.<region>.amazonaws.com` に固定されていることに依存する
 * - **DNS 解決そのものに時間の上限は無い**。ロックの外で行うので permit 1 は壊さないが、
 *   1 要求の worker 占有時間は SSRF 検査の分だけ伸びうる
 * - **応答サイズ上限も時間予算もメモリ使用量の上界ではない**。Laravel の HTTP client は
 *   既定で非 stream なので本文は先に全部メモリへ載り、長さを測る位置を変えても上界にならない。
 *   時間の上限も、帯域が大きければ受信バイト数を制限しない。上限の役割は
 *   「**期待と違う応答を検証・キャッシュに固定しない**」ことだけである
 * - **permit 1 は条件付き**である (上記のとおり後処理に強制上限が無い)。
 *   worker 停止やキャッシュ基盤の長時間停止で保持が伸びれば取得は重なりうる。
 *   所有者つきの解放で誤解放は防ぐが、重なり自体は防がない
 * - **キャッシュ store が共有されない構成 (file 等) ではホストごとに 1 回取りに行く**
 *   (既定 `database` は共有される)
 */
final readonly class SnsCertificateFetcher
{
    /** キャッシュキーの接頭辞 (URL は sha256 にする = キーに平文を残さない) */
    public const string CACHE_PREFIX = 'sns:cert:';

    /**
     * 同時取得数。**単一ロックキーと 1 対 1 に対応する** (上の docblock 参照)。
     * 2 以上へ増やすならロックキーの分割が同時に要る。
     */
    public const int CERT_FETCH_PERMITS = 1;

    /** 取得ロックのキー (1 本だけ持つ) */
    private const string CERT_FETCH_LOCK_KEY = 'sns:cert:fetch';

    public function __construct(
        private HttpFactory $http,
        private UrlSafetyInspector $inspector,
    ) {}

    /**
     * キャッシュ済みの PEM。無いとき / キャッシュ障害のとき / 読み戻せない値だったときは null。
     */
    public function cached(SnsCertificateUrl $url): ?string
    {
        $key = self::cacheKey($url);

        try {
            /** @var mixed $value */
            $value = Cache::get($key);
        } catch (Throwable) {
            // キャッシュは最適化である。読みの障害で署名検証を止めない (miss 扱い)。
            Log::warning('mail.sns.cert_cache_read_failed');

            return null;
        }

        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '' && self::isReadablePem($value)) {
            return $value;
        }

        // 読み戻せない値が残っていたら消して miss 扱いにする (不変条件 11)。
        $this->forgetQuietly($key);

        return null;
    }

    /**
     * SSRF 検査 → 同時 1 本に直列化した取得。
     *
     * 手順: SSRF 検査 (ロックの外) → 非ブロッキングでロック →
     * **ロック保持中にキャッシュ再確認** → 取得 → finally で所有者つき解放。
     *
     * @throws SnsSignatureInvalidException SSRF 判定 / サイズ / PEM 不正 (恒久 = 403)
     * @throws SnsVerificationUnavailableException 競合 / ロック基盤障害 / 取得失敗 / DNS 解決失敗 (503)
     */
    public function fetchSerialized(SnsCertificateUrl $url): SnsCertificate
    {
        // ★SSRF 検査はロックの**外**で行う。(a) DNS 解決に時間の上限が無くロック寿命の
        //   予算へ入れられない、(b) 拒否される要求にロックを触らせない、の 2 つが理由である。
        $this->inspect($url);

        // ★ここで投げるのは「ロック非対応 store」等の設定・実装の誤りだけなので**捕まえない**
        //   (可用性の退避に飲み込ませない = fail-fast)。
        $lock = Cache::lock(
            self::CERT_FETCH_LOCK_KEY,
            Config::integer('services.sns_certificate.lock_ttl_seconds'),
        );

        try {
            $acquired = $lock->get();
        } catch (Throwable $e) {
            // ロック基盤の一時障害。排他できない状態では取りに行かない
            // (同時取得数の上界を黙って壊すより、再送に任せるほうが安全である)。
            throw new SnsVerificationUnavailableException('certificate lock unavailable', 0, $e);
        }

        if ($acquired !== true) {
            // 待たない (上の docblock 参照)。
            throw new SnsVerificationUnavailableException('certificate fetch is busy');
        }

        try {
            // ロックを取るまでの間に別の要求が埋めているかもしれない。
            $cached = $this->cached($url);
            if ($cached !== null) {
                return new SnsCertificate($cached, fromCache: true);
            }

            return new SnsCertificate($this->fetchRemote($url), fromCache: false);
        } finally {
            // 取得しても hit で返しても**必ず**解放する (所有者つきの比較削除なので
            // 他所有者の鍵は消さない)。解放の失敗は飲む (finally で投げると元の例外を壊す)。
            $this->releaseQuietly($lock);
        }
    }

    /**
     * **署名検証が通った** PEM をキャッシュへ昇格させる (best-effort)。
     *
     * ★呼んでよいのは `AwsSnsSignatureVerifier` が `MessageValidator::validate()` を
     *   通したあとだけである。この唯一性は
     *   `tests/Architecture/SnsCertificateFetchContractTest.php` が
     *   `app/` 全体で exact-fit に固定する (名前も前提条件を言う形にしてある)。
     *
     * 保存に失敗しても署名検証は済んでいる。次回また取りに行くだけなので落とさない。
     */
    public function rememberVerified(SnsCertificateUrl $url, string $pem): void
    {
        try {
            Cache::put(
                self::cacheKey($url),
                $pem,
                Config::integer('services.sns_certificate.cache_ttl_seconds'),
            );
        } catch (Throwable) {
            Log::warning('mail.sns.cert_cache_write_failed');
        }
    }

    /**
     * SSRF 検査 (取得より前・ロックより前)。
     *
     * @throws SnsSignatureInvalidException 恒久の拒否 (403)
     * @throws SnsVerificationUnavailableException DNS 解決失敗 (503)
     */
    private function inspect(SnsCertificateUrl $url): void
    {
        $decision = $this->inspector->inspect($url->value);
        if ($decision->allowed) {
            return;
        }

        // DNS 解決失敗だけが一時障害である。書式検証を通った host が private IP へ
        // 解決される状態は DNS rebinding か split-horizon DNS であり、再送では直らない。
        if ($decision->reason === SsrfDenyReason::DnsResolutionFailed) {
            throw new SnsVerificationUnavailableException('certificate host is not resolvable');
        }

        throw new SnsSignatureInvalidException('certificate URL rejected by SSRF inspection');
    }

    /**
     * キャッシュに一切触らない実取得 (HTTP → 応答コード → サイズ → PEM 確認)。
     *
     * @throws SnsSignatureInvalidException
     * @throws SnsVerificationUnavailableException
     */
    private function fetchRemote(SnsCertificateUrl $url): string
    {
        try {
            $response = $this->http
                ->connectTimeout(Config::integer('services.sns_certificate.connect_timeout_seconds'))
                ->timeout(Config::integer('services.sns_certificate.request_timeout_seconds'))
                ->withoutRedirecting()
                ->get($url->value);
        } catch (ConnectionException $e) {
            // 接続 / DNS / TLS / timeout **だけ**を一時障害へ写像する。
            // TypeError や LogicException は写像しない (プログラム不具合を 503 で隠さない)。
            throw new SnsVerificationUnavailableException('certificate fetch failed', 0, $e);
        }

        // ★`->throw()` は使わない。4xx / 5xx しか例外化しないので、`withoutRedirecting()` と
        //   併用すると **3xx の本文が証明書として扱われうる**。2xx 以外は一様に拒否する。
        if (! $response->successful()) {
            throw new SnsVerificationUnavailableException('certificate response is not successful');
        }

        $body = $response->body();

        if (strlen($body) > Config::integer('services.sns_certificate.max_bytes')) {
            // 証明書としてあり得ない大きさ = 取得先が期待と違う。恒久扱いにする。
            throw new SnsSignatureInvalidException('certificate response is too large');
        }

        if (! self::isReadablePem($body)) {
            throw new SnsSignatureInvalidException('certificate response is not a valid PEM');
        }

        return $body;
    }

    /**
     * PEM として読めるか。
     *
     * `openssl_x509_read()` は失敗時に false を返しつつ warning も出す。warning は
     * Laravel のエラーハンドラが `ErrorException` へ昇格させるため、**戻り値と例外の両方**を
     * 「読めなかった」に畳む (エラー抑制演算子は使わない)。
     * 戻り値の `OpenSSLCertificate` は**ここから外へ出さない** (キャッシュ境界は常に string)。
     */
    private static function isReadablePem(string $pem): bool
    {
        try {
            return openssl_x509_read($pem) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function cacheKey(SnsCertificateUrl $url): string
    {
        return self::CACHE_PREFIX.hash('sha256', $url->value);
    }

    private function forgetQuietly(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            Log::warning('mail.sns.cert_cache_forget_failed');
        }
    }

    private function releaseQuietly(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // 解放できなくても寿命の失効で回復する。**その間は後続が 503 になる**ことを
            // 観測できるようにするためのログである (平文は出さない)。
            Log::warning('mail.sns.cert_lock_release_failed');
        }
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型がすべて明示されている
- [x] `Cache::get()` の `mixed` は `is_string()` で narrowing してから返す
- [x] `Lock::get()` の戻り値は `mixed` なので `!== true` で判定する
- [x] `Config::integer()` で int を確定させる (`config()` の mixed を持ち回らない)
- [x] `openssl_x509_read()` の `OpenSSLCertificate|false` は `!== false` の比較にしか使わず外へ出さない
- [x] 配列を返さない (値オブジェクト `SnsCertificate` を返す)

### テスト計画

施策 F 参照。

### リスク

- **ロック基盤の障害で 503 になる**。裁定は「排他なしの取得へ退避してよい」(任意) と
  しているが、aicue は退避しない — 退避するとこの施策が作ろうとしている上界が
  その瞬間に消えるためで、かつキャッシュ / ロック基盤 (`database` store) が
  落ちている状況ではアプリ全体がすでに機能していない
- **`->throw()` をやめたことで例外の種類が変わる**。t0 は HTTP エラー応答で
  `RequestException` を経由していたが、今後は応答コードの判定で 503 になる。
  外から見た挙動 (503) は同じである

---

## D. 署名検証器の責務を絞る

### 変更箇所

- `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` (全面書き換え)

### 波及変更

- **コンストラクタ引数が変わる** (`HttpFactory` → `SnsCertificateFetcher`)。
  `AppServiceProvider::bind()` は自動解決なので変更不要だが、
  `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` の `makeSnsVerifier()` は変更が要る (施策 G)
- `tests/Architecture/ExternalClientTimeoutInventoryTest.php` の rationale 文言 (施策 J)
- `tests/Architecture/ValidationAttributeCoverageTest.php` の**行番号キー** (施策 J)
- `tests/Support/StrayHttpRequestGuard.php` の説明コメント (施策 J)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;

/**
 * AWS SDK ベースの SNS 署名検証実装。
 *
 * 署名の暗号検証 (canonical string / SignatureVersion / 証明書検証) は AWS SDK の
 * `MessageValidator` に委譲し、自前で再実装しない。wrapper の責務は 4 点:
 *  1. **SDK が実際に取りに行く URL** を特定し、値オブジェクトで書式検証する
 *     (両キー同時送信は拒否する。下の `effectiveCertUrl()` の docblock 参照)
 *  2. 証明書取得を `SnsCertificateFetcher` へ委譲する (SSRF 検査 / 直列化 / キャッシュ / PEM 確認)
 *  3. SDK が閉じ込めた URL 以外を要求してきたら取りに行かない (fail-closed)
 *  4. **署名検証が通った証明書だけ**をキャッシュへ昇格させる
 *
 * `MessageValidator` は証明書取得を `certClient` へ委譲できる。これを使い
 * **取得失敗 (一時障害) と署名不一致 (恒久) を確実に分ける**: certClient が投げた
 * `SnsVerificationUnavailableException` は `validate()` を素通りして伝播し、
 * `validate()` が投げる `InvalidSnsMessageException` は取得済みの証明書での検証失敗
 * = 署名不一致だけになる。SDK 既定の `file_get_contents` 再取得にも
 * 例外メッセージの判定にも依存しない。
 *
 * ★**certClient は決して false を返さない**。vendor は false を
 *   `InvalidSnsMessageException` に吸収するため、返すと一時障害が 403 に化ける。
 *
 * ★**解放から昇格までの窓**: ロックが包むのは外向き通信ちょうどで、署名検証はその後に走る。
 *   この間に届いた別の要求は同じ証明書をもう一度取りに行きうる。窓の長さは署名検証 1 回ぶん
 *   (取得に比べて無視できる) で、起きても取得が 1 回余分に走るだけである。
 *   ロックを署名検証まで伸ばしても同時外向き通信数の上界は改善せず、
 *   ロック寿命を伸ばす必要が出る (= 障害時に後続が 503 になる時間が延びる) ので伸ばさない。
 */
final class AwsSnsSignatureVerifier implements SnsSignatureVerifier
{
    public function __construct(private readonly SnsCertificateFetcher $certificates) {}

    public function verify(Message $message): void
    {
        // 1) SDK が実際に取りに行く URL を特定し、型で書式検証する
        //    (不正は SnsCertificateUrl::fromString が SnsSignatureInvalidException を投げる = 403)。
        $url = SnsCertificateUrl::fromString($this->effectiveCertUrl($message));

        // **新しく取得した** PEM だけをここに載せる (キャッシュから返ったものは載せない)。
        /** @var string|null $fetched */
        $fetched = null;

        $validator = new MessageValidator(
            function (string $requested) use ($url, &$fetched): string {
                // SDK が検証済みの URL 以外を要求したら取りに行かない (最後の砦)。
                if ($requested !== $url->value) {
                    throw new SnsSignatureInvalidException('unexpected SigningCertURL requested');
                }

                $cached = $this->certificates->cached($url);
                if ($cached !== null) {
                    return $cached; // 正常時はここ。ロックも外向き通信も無い。
                }

                $certificate = $this->certificates->fetchSerialized($url);
                if (! $certificate->fromCache) {
                    $fetched = $certificate->pem;
                }

                return $certificate->pem;
            }
        );

        try {
            $validator->validate($message);
        } catch (InvalidSnsMessageException $e) {
            throw new SnsSignatureInvalidException('signature mismatch', 0, $e);
        }

        // ★昇格はここだけ。`validate()` を通ったあとであることが唯一の条件である。
        if (is_string($fetched)) {
            $this->certificates->rememberVerified($url, $fetched);
        }
    }

    /**
     * SDK (`MessageValidator`) が**実際に取得する** 証明書 URL。
     *
     * vendor の `MessageValidator::isLambdaStyle()` は `isset($message['SigningCertUrl'])` を
     * 先に判定し、真なら `convertLambdaMessage()` が `SigningCertUrl` の値で
     * `SigningCertURL` を**上書き**する。したがって `SigningCertURL` を先に読む実装だと、
     * **両キーを同時に送られたときに「検査した URL」と「取得する URL」が食い違い、
     * アプリ側の追加検証 (port 443 固定 / query 禁止 / path 形式 / 中国パーティション排除) を
     * 回避できる** (vendor 自身の検査は host 形式と `.pem` 終端しか見ない)。
     * この上書き順序は aws/aws-php-sns-message-validator 1.10.0 を実読して確認し、
     * `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` の vendor 契約テストが固定する。
     *
     * 対策は 2 段:
     *  1. **両キー同時存在は拒否する** (正当な SNS 通知はどちらか一方しか持たない。
     *     両方あるのは検査を食い違わせる意図しか無い)
     *  2. 単独のときは SDK と同じ実効キー (Lambda キー優先) を返す
     *
     * @throws SnsSignatureInvalidException
     */
    private function effectiveCertUrl(Message $message): string
    {
        $canonical = $message['SigningCertURL'] ?? null;
        $lambda = $message['SigningCertUrl'] ?? null;

        if ($canonical !== null && $lambda !== null) {
            throw new SnsSignatureInvalidException('conflicting SigningCertURL / SigningCertUrl');
        }

        // SDK は Lambda キーがあればそれで上書きするため、同じ優先順を採る。
        $url = $lambda ?? $canonical ?? '';

        return is_string($url) ? $url : '';
    }
}
```

### PHPStan 適合チェック

- [x] `Message::offsetGet()` は `mixed` を返すので `is_string()` で narrowing
- [x] 参照渡しの `$fetched` は `@var string|null` を宣言し、`is_string()` で narrowing
- [x] closure の戻り値型 `: string` を明示
- [x] 配列を返さない

### テスト計画

施策 G / H 参照。

### リスク

- **「SDK が別 URL を要求する」分岐は現行 vendor (1.10.0) では到達しない**。
  lambda キー単独の封筒は `convertLambdaMessage()` で同じ値が `SigningCertURL` に入るため、
  certClient が受け取る文字列は必ず検証済み URL と一致する。
  この分岐は**将来の vendor 変更に対する砦**であり behavioral テストを持てない。
  「保証しないもの」として docblock と `docs/architecture.md` に明記する
  (到達可能な半分 = 一致するとき取得へ進むこと、はテストで固定する)
- `certUrl()` が `SigningCertURL` を先に読む t0 の挙動は消える。
  **後方互換の並走を残さない** (思考原則 3) ので、旧メソッドは同じ変更で削除する

---

## E. 取得口の契約を機械固定する

### 変更箇所

- 新規: `tests/Architecture/SnsCertificateFetchContractTest.php`

### 設計方針

AGENTS.md「静的検査 (gate) と走査器の共通規約」に従う。
**汎用の走査器は新設しない** — 守る対象が名指しの数ファイルなので、
既存の中立走査器 `Tests\Support\PhpReferenceScanner` (namespace / use / group use を解いて
完全修飾名で返す) と `PhpToken::tokenize()` を使い、判定条件だけを本テストに持つ。

#### 走査根 (docblock に明記する)

```
app/Services/Mail/Sns/            (ディレクトリ全体)
app/Http/Middleware/VerifySnsSignature.php
app/Http/Controllers/Webhooks/SesNotificationController.php
```

昇格 site の唯一性 (C11) だけは `app/` **全体**を走査根にする。
根が実在しないときは **fail-fast** (無言で空集合にしない)。

#### 検査一覧

| # | 検査 | 内容 |
|---|------|------|
| C1 | 取得口の唯一性 | 走査根の中で `Illuminate\Http\Client\Factory` または `Illuminate\Support\Facades\Http` を参照するファイルが `SnsCertificateFetcher.php` **ちょうど 1 件** (exact-fit。未登録も残骸も赤) |
| C2 | 時間の大小関係 | `0 < connect`、`connect <= request`、`0 < post`、`request + post <= lock_ttl`、`0 < cache_ttl`、`0 < max_bytes` |
| C3 | 単一ロックキー = permit 1 | `SnsCertificateFetcher::CERT_FETCH_PERMITS === 1`。取得口で `Cache::lock(` の site が **1 件**、第 1 引数が `self::CERT_FETCH_LOCK_KEY` **ちょうど**、クラスが持つロックキー定数が **1 本** |
| C4 | 待ち上限は構造的に 0 | 取得口に `->block(` の site が **0 件** (待たない実装であることの機械固定。AG-199 の左の不等号はこれで満たす) |
| C5 | TLS 検証を無効化していない | 走査根に `withoutVerifying` の T_STRING、`->verify(` のメソッド呼び出し、`'verify' =>` / `"verify" =>` の配列キーが **0 件** |
| C10 | 取得の配線 | 取得口に `connectTimeout(` / `timeout(` / `withoutRedirecting(` が**それぞれちょうど 1 件**あり、前 2 つの引数が `Config::integer('services.sns_certificate.…')` の形であること。**`->throw(` が 0 件**であること (3xx を素通しさせないため) |
| C11 | 昇格 site の唯一性 | `app/` 全体で `rememberVerified(` の呼び出し site が**ちょうど 1 件**で、`app/Services/Mail/Sns/AwsSnsSignatureVerifier.php` の中にあり、同ファイルの `validate(` の site**より後ろの行**であること |
| C12 | 未解決を落とす | 走査根に部分修飾名 (`T_NAME_QUALIFIED`) が現れたら**未解決として失敗**させる (走査器が解決しないため。規約 (b)) |
| C13 | 通信の原語を持たない | 走査根に `file_get_contents` / `fopen` / `curl_init` / `curl_exec` / `stream_context_create` / `GuzzleHttp\Client` / `Symfony\Component\HttpClient\HttpClient` の参照が **0 件** |
| C6 | 空振り検知 | 走査根が 3 つとも解決でき、走査ファイル数 > 0、C1 の母集団が空でない、C3 / C10 の token 走査が対象 site を 1 件以上検出している、C11 の `app/` 走査ファイル数 > 0 |
| C7 | 解決できない形を落とす | 走査根のファイルが読めない / `PhpToken::tokenize()` が空を返す場合は**未解決として失敗**させる |
| C8 | 検出器の自己検査 (負例) | 合成入力 (nowdoc) に対し、C1 / C3 / C4 / C5 / C10 / C11 / C12 / C13 の各判定器が**違反を検出する** |
| C9 | 検出器の自己検査 (正例) | 合成入力に対し、規定どおりの書き方を**違反と判定しない**。C5 / C13 の語彙は接頭辞つき (`myWithoutVerifying`) ・打ち消しつき (`notWithoutVerifying`) ・接尾辞つき (`withoutVerifyingSomething`) の 3 形を置き、いずれも一致しないこと |

★C8 / C9 は**検出器そのものの自己検査**であり、施策 C の実装前でも**緑であるべき**である。
施策 C 実装前に赤くなるのは本番コードに対する契約 assertion (C1〜C7 / C10〜C13) のほうである。

#### 各防御が何に守られているか (突然変異一覧)

| 外した防御 | 赤くなる検査 |
|---|---|
| `UrlSafetyInspector::inspect()` を消す | **F3** (private IP → 403) / **F4** (DNS 解決失敗 → 503) |
| `connectTimeout` / `timeout` を消す | **C10** |
| `withoutRedirecting()` を消す | **C10** / **F18** (3xx を受理しない) |
| `->throw()` を足して 2xx 判定を消す | **C10** / **F18** |
| 応答サイズ上限を消す | **F6** |
| PEM 確認を消す | **F5** |
| 昇格を `validate()` の前へ動かす | **C11** (行の前後関係) / **G8** (署名失敗でキャッシュに載る) |
| 昇格を別クラスから呼ぶ | **C11** (exact-fit) |
| ロックキーを 2 本に増やす | **C3** |
| `->block(` で待つようにする | **C4** |
| TLS 検証を切る | **C5** |
| 取得を `file_get_contents` へ変える | **C13** / **C1** (母集団が 0 件になる = C6 の空振り検知) |
| 取得を別クラスへ移す | **C1** (exact-fit) |
| 予算の大小関係を壊す | **C2** |

#### 規約 (a)(b)(d)(e) への適合

- **(a) 完全修飾名で突き合わせる**: C1 / C13 は `PhpReferenceScanner::references()` が返す
  完全修飾名で照合する (短名一致にしない)
- **(b) 解決できない形は落とす**: C7 (読めない・トークン化できない) と
  C12 (部分修飾名) を**失敗**として扱う。無言で候補から外さない
- **(d) 集めた結果は必ず判定に使う**: 走査結果はすべて assertion の入力になる
- **(e) 語彙一致はトークンの完全一致**: C5 / C13 は `PhpToken` の値の**完全一致**で判定する
  (部分文字列一致も正規表現の語境界も使わない)。区切りは
  **`PhpToken::tokenize()` が返すトークン境界**であると docblock で宣言する

#### 保証しないもの (docblock に書く)

- 変数経由の指定 (`$m = 'withoutVerifying'; $req->{$m}()`)、
  オプション配列を実行時に組み立てて渡す形
- 走査根の**外**にある証明書取得 (根を 3 つに限定しているため。
  app/ 全体の `Http::` facade は `ExternalSeamInventory` の担当で、
  **注入された `HttpFactory` は同目録の母集団に入らない**という非対称は
  `docs/architecture.md` に書く)
- C13 の語彙は列挙であり、通信の原語を網羅しているとは主張しない
- リポジトリの外にある設定 (php.ini の `openssl.cafile` など)

### PHPStan 適合チェック

- [x] Architecture レーンは DB を使わない。本テストは config とファイル走査だけを見る
- [x] `config()` は `config()->integer(...)` で int を確定させる
- [x] `PhpToken::tokenize()` の戻り値は `list<PhpToken>` として扱う

### テスト計画

本施策そのものがテストである。**先に赤くしてから本体を書く** (思考原則 5)。

### リスク

- 走査根を限定しているので、SNS 証明書取得を**別のディレクトリ**に新設されたら沈黙する。
  docblock と `docs/architecture.md` に明記する

---

## F. 取得口の振る舞いテスト

### 変更箇所

- 新規: `tests/Feature/Mail/SnsCertificateFetcherTest.php`

### 共通の下ごしらえ

```php
beforeEach(function (): void {
    // ★テスト間でキャッシュを持ち越さない (同じ CERT_URL を G / H と共有するため)。
    Cache::flush();
    bindSnsDnsResolver(['203.0.113.10']);
});
```

`bindSnsDnsResolver()` は施策 I で `tests/Pest.php` へ置く共用ヘルパである
(`UrlSafetyInspector` は `ExternalFakeDeclaration::neverSwapped()` により偽物にできないので、
差し替えるのはその依存の `DnsResolverInterface` である)。

### テスト一覧

| # | テスト名 | 検証内容 |
|---|---------|---------|
| F0 | **正のコントロール**: 正常系 fixture は SSRF 検査を通る | `app(UrlSafetyInspector::class)->inspect(CERT_URL)->allowed === true` (境界が変わったらここが最初に赤くなる) |
| F1 | キャッシュに載っていれば取りに行かない | `Cache::put(key, pem, ttl)` で仕込み → `cached()` が返す / `Http::assertNothingSent()` |
| F2 | 昇格しなければキャッシュに載らない | `fetchSerialized` を 2 回呼ぶと HTTP は 2 回 (**要件 6 の負例**) |
| F3 | private IP に解決される host は 403 系 | `SnsSignatureInvalidException` + `Http::assertNothingSent()` |
| F4 | DNS 解決失敗は 503 系 | `SnsVerificationUnavailableException` + `Http::assertNothingSent()` |
| F5 | PEM でない応答は 403 系でキャッシュしない | 2 回とも取りに行く |
| F6 | サイズ超過は 403 系でキャッシュしない | `config(['services.sns_certificate.max_bytes' => 16])` |
| F7 | HTTP エラー応答 (500) は 503 系 | |
| F8 | 接続失敗は 503 系 | `Http::fake(fn () => throw new ConnectionException('boom'))` |
| F9 | **プログラム不具合は写像しない** | `Http::fake(fn () => throw new LogicException('boom'))` → `LogicException` が伝播する (**要件 7 の核**) |
| F10 | キャッシュ読みの例外は miss 扱い | 読みが投げる store を差し込んでも PEM が返る |
| F11 | キャッシュ書きの例外は握る | `rememberVerified()` が投げない |
| F12 | 読み戻せない値は forget して miss 扱い | `Cache::put(key, 'not a pem')` → HTTP へ行き、キーが消えている |
| F13 | ロック保持中は 503 で自分では取りに行かない | 先に `Cache::lock('sns:cert:fetch', 10)->get()` |
| F14 | ロック取得後の再確認で hit したら `fromCache === true` で返し解放する | 1 回目の read は miss、2 回目は hit を返す store |
| F15 | ロック非対応 store は fail-fast | `LockProvider` を実装しない store → 例外が**そのまま伝播**する (`SnsVerificationUnavailableException` にならない。具体型は Laravel 12 の実挙動に合わせる) |
| F16 | ロック基盤の例外は 503 (退避しない) | `get()` が投げる Lock を返す store → `Http::assertNothingSent()` |
| F17 | ロックは成功時も失敗時も解放される | 成功 / HTTP 失敗 / PEM 不正の 3 ケースそれぞれの後で `Cache::lock('sns:cert:fetch', 10)->get()` が true |
| F18 | 3xx を受理せず Location へ追従しない | `Http::response('', 302, ['Location' => 'https://evil.example/x.pem'])` → 503、送信は 1 回だけ、宛先は CERT_URL |
| F19 | キャッシュ寿命が設定値どおり | `rememberVerified()` → `travel(ttl + 1)->seconds()` → `cached()` が null |
| F20 | URL が違えばキーも違う | 2 つの正当な URL でキャッシュが混ざらない |

F10 / F12 / F14 / F15 / F16 のために、テストファイル内に無名クラスの `Store` 実装を置く
(motivation の準拠テストと同型)。

★**リクエストオプション (connect / total timeout / TLS 検証) は Laravel の `Http::fake()` からは
観測できない**ため、その配線は施策 E の C10 / C5 が字句で固定する。ここでは扱わない。

### PHPStan 適合チェック

- [x] 無名 `Store` 実装は `Illuminate\Contracts\Cache\Store` の全メソッドを実装する
- [x] `expect(...)->toThrow(...)` で例外型を固定する

### リスク

- **F9 は `Http::fake()` のコールバックが投げた例外が包まれないことに依存する**。
  実装時に実測し、包まれる場合は「`ConnectionException` 以外は写像しない」ことを
  別の注入点 (`HttpFactory` の差し替え) で確かめる形に変える

---

## G. 署名検証器のテスト改修

### 変更箇所

- `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php`

### 現行コード

```php
function makeSnsVerifier(): AwsSnsSignatureVerifier
{
    return new AwsSnsSignatureVerifier(app(HttpFactory::class));
}
```

### 変更後コード

```php
function makeSnsVerifier(): AwsSnsSignatureVerifier
{
    return new AwsSnsSignatureVerifier(app(SnsCertificateFetcher::class));
}

beforeEach(function (): void {
    Cache::flush();
    bindSnsDnsResolver(['203.0.113.10']);
});
```

### テスト一覧 (既存 + 追加)

| # | テスト名 | 状態 |
|---|---------|------|
| G1 | cert ホストが不正なら 403 系で HTTP 取得すらしない | 既存 (維持) |
| G2 | cert URL 境界: http / port / host / path / query を拒否 | 既存 + **credential / fragment の 2 行を追加** |
| G3 | cert 取得失敗は 503 系 | 既存 (維持) |
| G4 | cert 到達後に署名検証が落ちれば 403 系 | **fixture を `SnsTestData::certificatePem()` へ差し替える** (PEM としては読めるが署名と一致しない証明書。`cert-body` や壊れた PEM では PEM 確認で落ちてしまい「署名段まで到達した」ことを示せない)。HTTP を出したこと + キャッシュに載っていないことも検査する |
| G5 | 正当な cert URL は HTTP 取得まで進む | **同上の fixture 差し替え** |
| G6 | **両キー同時送信は 403 系で HTTP 取得すらしない** | **新規 (要件 1)** |
| G7 | lambda キー単独でも同じ URL を取りに行く | **新規** (`SnsTestData::lambdaStyleNotification()` を使う) |
| G8 | **署名検証が落ちたらキャッシュに載らない** | **新規 (要件 6)**。同じ通知を 2 回検証すると HTTP が 2 回 |
| G9 | **署名検証が通ったらキャッシュに載る** | **新規 (要件 6)**。署名済み通知を 2 回検証すると HTTP は 1 回 |
| G10 | **vendor 契約**: 両キーがあるとき vendor は lambda キーの値を取りに行く | **新規**。`MessageValidator` を直接使い、要求された URL を記録する certClient で確認する。**両キー拒否という対策の前提**そのものなので、vendor が変わったら赤くなる |

### リスク

- G9 / G10 は施策 I の部品に依存する

---

## H. middleware の end-to-end テスト追加

### 変更箇所

- `tests/Feature/Mail/SesSignatureMiddlewareTest.php`

### 追加するテスト

| # | テスト名 | 検証内容 |
|---|---------|---------|
| H1 | 実 verifier: 両キー同時送信は 403 で外向き通信をしない | 要件 1 が HTTP ステータスまで通ること |
| H2 | 実 verifier: 証明書取得の HTTP 失敗は 503 | 要件 7 の 503 側が middleware の写像まで通ること |
| H3 | 実 verifier: SSRF 拒否は 403 | 要件 4 の 403 側が middleware の写像まで通ること |
| H4 | **実 verifier: 署名済みのバウンス通知は受理され抑止が記録される** | DI / 自動解決 / 実証明書検証 / controller 到達までの成功系。同じ通知をもう 1 回送っても HTTP 取得が増えない (昇格が効いている) ことも同じテストで見る |

`app()->instance(SnsSignatureVerifier::class, ...)` を**呼ばない**ことで実 verifier を通す。
`beforeEach` で `Cache::flush()` + `bindSnsDnsResolver()` + `Http::fake()` を張る。

### リスク

- 受け口 route には `throttle:webhook-ses` (300/分・IP 単位) が付いている。
  1 テストあたりの POST は 1〜2 回なので上限には当たらない
- H4 は `SesNotificationController` の TopicArn allowlist に依存するので、
  既存 `beforeEach` の `config(['services.ses.sns_topic_arns' => [SnsTestData::TOPIC_ARN]])` を維持する

---

## I. テスト部品の拡張

### I-1. `tests/Pest.php` へ共用ヘルパを足す

複数のテストファイルから使うので `tests/Pest.php` に置く (AGENTS.md の実装規約)。

```php
/**
 * SNS 証明書取得テスト用に DNS 解決を固定する。
 *
 * `UrlSafetyInspector` そのものは ExternalFakeDeclaration::neverSwapped() により
 * 偽物にできないので、差し替えるのは**その依存**である DnsResolverInterface である。
 * inspector は singleton なので、bind 後に作り直させる。
 *
 * @param  list<string>  $ips  空配列なら「DNS 解決失敗」を模す
 */
function bindSnsDnsResolver(array $ips): void
{
    app()->bind(
        DnsResolverInterface::class,
        fn (): DnsResolverInterface => new FakeDnsResolver(['sns.us-east-1.amazonaws.com' => $ips]),
    );
    app()->forgetInstance(UrlSafetyInspector::class);
}
```

### I-2. `tests/Support/SnsTestData.php` へ追加

```php
    /**
     * テスト用の鍵と自己署名証明書 (プロセス内で 1 度だけ作る)。
     *
     * 鍵生成は数百 ms かかるため静的に持ち回す。`openssl_x509_read()` は有効期限を見ないので、
     * 期限そのものはテストの成否に影響しない。
     *
     * @return array{key: \OpenSSLAsymmetricKey, pem: string}
     */
    private static function keyPair(): array
    {
        /** @var array{key: \OpenSSLAsymmetricKey, pem: string}|null $cached */
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        Assert::notFalse($key, 'テスト用の鍵を生成できません');

        $csr = openssl_csr_new(['commonName' => 'sns.us-east-1.amazonaws.com'], $key, ['digest_alg' => 'sha256']);
        Assert::notFalse($csr, 'テスト用の証明書要求を生成できません');

        $certificate = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256']);
        Assert::notFalse($certificate, 'テスト用の証明書を生成できません');

        $pem = '';
        Assert::true(openssl_x509_export($certificate, $pem), 'テスト用の証明書を PEM へ書き出せません');

        return $cached = ['key' => $key, 'pem' => $pem];
    }

    /**
     * PEM としては読めるが、`signedNotification()` の署名とは**一致しない**証明書。
     * 「取得は成功したが署名段で落ちる」ことを示すテストで使う。
     */
    public static function certificatePem(): string
    {
        return self::keyPair()['pem'];
    }

    /**
     * 署名検証が**通る**通知と、それに対応する証明書 PEM。
     *
     * 署名対象の文字列は vendor の `MessageValidator::getStringToSign()` から得る
     * (署名仕様を自前で再実装しない)。SignatureVersion 1 は SHA1 が仕様である。
     *
     * @param  array<string, mixed>  $overrides
     * @return array{payload: array<string, mixed>, pem: string}
     */
    public static function signedNotification(string $sesMessageJson, array $overrides = []): array
    {
        $pair = self::keyPair();
        $payload = self::notification($sesMessageJson, $overrides);

        $stringToSign = (new MessageValidator)->getStringToSign(new Message($payload));

        $signature = '';
        Assert::true(
            openssl_sign($stringToSign, $signature, $pair['key'], OPENSSL_ALGO_SHA1),
            'テスト用の署名を作れません',
        );

        $payload['Signature'] = base64_encode($signature);

        return ['payload' => $payload, 'pem' => $pair['pem']];
    }

    /**
     * Lambda 形式の封筒 (`SigningCertURL` を持たず `SigningCertUrl` だけを持つ)。
     *
     * ★`notification()` は常に `SigningCertURL` を入れるため、override で
     *   `SigningCertUrl` を足すと**両キー同時**になってしまう。ここで明示的に外す。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function lambdaStyleNotification(string $sesMessageJson, array $overrides = []): array
    {
        $payload = self::notification($sesMessageJson, $overrides);
        unset($payload['SigningCertURL']);
        $payload['SigningCertUrl'] = self::CERT_URL;

        return $payload;
    }
```

### PHPStan 適合チェック

- [x] `openssl_pkey_new()` / `openssl_csr_new()` / `openssl_csr_sign()` の `false` を
      `Assert::notFalse()` で narrowing する
- [x] `openssl_x509_export()` / `openssl_sign()` の bool 戻り値を `Assert::true()` で扱い、
      出力変数 `$pem` / `$signature` を**先に初期化**する (PHPStan の未定義変数を出さない)
- [x] 静的キャッシュの shape を `@var` で宣言する
- [x] 戻り値の shape を `@return array{...}` で宣言する
- [x] warning が `ErrorException` へ昇格しても `Assert` の前に throw されるだけで、
      握り潰しは起きない (テスト部品なので fail-fast でよい)

### リスク

- `openssl_csr_new()` は openssl の設定ファイルを必要とする実装がある。
  この環境で動くことは実測済みだが、CI で失敗した場合は
  **固定の PEM 文字列 + 固定の秘密鍵**をテスト部品に埋め込む形へ切り替える

---

## J. 既存目録の更新

### J-1. `tests/Architecture/CachePayloadPlainDataGateTest.php`

L2 (書き込み経路) へ 1 件追加:

```php
    'app/Services/Mail/Sns/SnsCertificateFetcher.php::put' => [
        'count' => 1,
        'payload' => 'SNS 署名検証用の証明書 (PEM) の素の文字列。オブジェクトは渡さない',
        'proof' => 'tests/Feature/Mail/SnsCertificateFetcherTest.php',
        'rationale' => '署名検証が通った証明書だけを URL の sha256 をキーにして寿命つきで保存する。読み戻しは is_string + 非空 + PEM として読めることを検査し、失敗したら Cache::forget して miss 扱いにする',
    ],
```

L3 (面) へ追加:

```php
    'app/Services/Mail/Sns/SnsCertificateFetcher.php' => [
        'role' => 'write',
        'rationale' => 'SNS 証明書の取得口。get / put / forget と Cache::lock を持つ唯一のファイルで、payload は PEM の素の文字列だけである',
    ],
    'tests/Feature/Mail/SnsCertificateFetcherTest.php' => [
        'role' => 'write',
        'rationale' => 'キャッシュ障害・ロック競合・読み戻し不能を再現するため cache store を差し替えて put / forget / lock を直接使う',
    ],
```

`tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` /
`tests/Feature/Mail/SesSignatureMiddlewareTest.php` は `Cache::flush()` を呼ぶので
面目録への登録 (`role: no-payload-write`) が要る見込みである。
**実装時に gate を回して実測で確定する** (目録は exact-fit なので、
不要な登録は「残骸」として赤くなる)。

### J-2. `tests/Architecture/ExternalClientTimeoutInventoryTest.php`

`AwsSnsSignatureVerifier` の rationale を実態へ合わせる (免除理由は変えない):

```php
    AwsSnsSignatureVerifier::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
        'rationale' => 'MessageValidator 自身は transport を構築せず送信もしない。証明書取得は SnsCertificateFetcher へ委譲する',
    ],
```

**`SnsCertificateFetcher` / `SnsCertificateUrl` / `SnsCertificate` は本目録へ登録しない**。
同目録の母集団は `Aws\` / `League\Flysystem\` / `Illuminate\Filesystem\` / `Storage` を
参照するファイルであり、新設 3 クラスはどれも参照しないため。
登録すると「実在しない登録 = 残骸」として赤くなる。

### J-3. `tests/Architecture/ValidationAttributeCoverageTest.php`

`UNPARSEABLE_CALL_INVENTORY` のキーは `{相対パス}@{行番号}#validate` である。
`$validator->validate($message)` の行が変わるので、**実装後の実際の行番号へ更新する**
(この目録は行番号ずれで fail する = 再確認を強制する設計)。

### J-4. `tests/Support/StrayHttpRequestGuard.php` の説明コメント

L32-36 の「`AwsSnsSignatureVerifier::certClient` は `catch (\Throwable)` で例外を握り潰す」は
**事実でなくなる** (施策 C で `ConnectionException` だけに絞る)。
「証明書取得の失敗を `SnsVerificationUnavailableException` へ写像するため、
取りに行った事実が 503 に化ける」へ書き換える (guard の存在意義の説明としては成立する)。
握り潰しの実例としては `FxRateService::fetchFromFrankfurter` が残る。

---

## K. 文書更新

### K-1. `docs/ses-mail-runbook.md`

- 「構成概要」に証明書取得口 (`SnsCertificateFetcher`) を足す
- 「障害一次切り分け」に新しいログキーを足す:
  `mail.sns.cert_cache_read_failed` / `mail.sns.cert_cache_write_failed` /
  `mail.sns.cert_cache_forget_failed` / `mail.sns.cert_lock_release_failed`
- 「注意点」に「証明書取得が競合すると 503 を返す (SNS が再送する)。403 と混ぜない」を足す

### K-2. `docs/architecture.md`

新しい節「SNS 署名検証の証明書取得」を足し、**保証しないものの正本**にする。
**次の 4 点は条件付きの書き方にする** (実際より強く保証しない):

1. **permit 1 はロック寿命による条件付きの性質**である。SSRF 検査はロックの外
   (DNS 解決に時間の上限が無いため)、ロック内の後処理 (キャッシュ再確認・PEM 解析) にも
   強制上限は無い。1 要求の保持が寿命を超えれば取得は重なりうる
2. **「検証済みのみ昇格」は 2 段で守られている** — 昇格メソッドの名前と、
   `app/` 全体で呼び出し site を 1 件に固定する契約テスト (C11) である。
   言語の可視性で閉じてはいない
3. **取得口の唯一性は「指定した走査根の中の、解決可能な HTTP client 参照の範囲」**である。
   走査根の外・部分修飾名・変数経由・列挙していない通信の原語には効かない。
   3 つの目録の役割分担 (`SnsCertificateFetchContractTest` /
   `ExternalSeamInventory` = `Http::` facade / `ExternalClientTimeoutInventoryTest` =
   AWS / Flysystem) と、**注入された `HttpFactory` は `ExternalSeamInventory` の母集団に
   入らない**という非対称もここに書く
4. **キャッシュ読みの障害で止まらないのは「読みだけが失敗した場合」**である。
   同じ store がロックも担うので、store ごと落ちればロック取得も失敗して 503 になる

併せて「DNS rebinding は解消しない」「サイズ上限も時間予算もメモリの上界ではない」
「『SDK が別 URL を要求する』分岐は現行 vendor では到達しないため behavioral テストを持たない」
も書く。

---

## 実装順序 (テストファースト)

1. 施策 E の検出器自己検査 (C8 / C9) を書き、**緑であること**を確認する
2. 施策 E の契約 assertion (C1〜C7 / C10〜C13) を書き、**赤いこと**を確認する
3. 施策 I (テスト部品) → 施策 F / G / H のテストを書き、**赤いこと**を確認する
4. 施策 A / B / C / D を実装して緑にする
5. 施策 J (目録) を回して exact-fit のずれを潰す (行番号キーはここで確定する)
6. 施策 K (文書)
7. `composer test` / `composer phpstan` / `vendor/bin/pint --test` を全 green にする

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `app/Services/Mail/Sns/` と 4 本の Architecture 目録に閉じた変更で、他ドメインと接触しない。ただし目録が exact-fit なので、他タスクが同じ目録へ足すと必ず競合する。単独ブランチで一気に緑にするのが安全 |
| 競合リスク | `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録 (件数 exact-fit) / `config/services.php` / `tests/Pest.php` (共用ヘルパの追加) / `tests/Support/SnsTestData.php` |

