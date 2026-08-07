# Round 3: 対応マトリクスと修正後の概念設計

Round 2 の指摘 ([Critical] 1 / [Warning] 5) に対する判断と修正内容を報告する。全件対応した。

## [Critical] `UnknownApiErrorException → unknown` と運用契約の矛盾

**対応した。ただし提示された 3 案 (定義を緩める / `classification_status` を足す /
`provider_unknown` を足す) はいずれも採らず、より単純な解に変えた。**

vendor の実装を読み直した結果、**こちらの前提が誤っていた**ことが分かった。
`vendor/stripe/stripe-php/lib/ApiRequestor.php` の `_specificV1APIError()` は
**HTTP status の switch** であり、`UnknownApiErrorException` はその **`default:` 分岐**である:

```php
switch ($rcode) {
    case 400:  // 'rate_limit' code → RateLimitException / 'idempotency_error' type → IdempotencyException
    case 404:  return InvalidRequestException::factory(...);
    case 401:  return AuthenticationException::factory(...);
    case 402:  return CardException::factory(...);
    case 403:  return PermissionException::factory(...);
    case 429:  return RateLimitException::factory(...);
    default:   return UnknownApiErrorException::factory(...);   // ← 5xx は全部ここ
}
```

つまり「未知」なのは **error type** であって **status は分かっている**。
**Stripe の 5xx 障害はすべて `UnknownApiErrorException` に来る**。
これを `unknown` へ落とす設計は、決済 gateway で最も頻度が高く、かつ
「待てば直る」という行動が明確な失敗モードを分類できないという致命的な穴だった。

修正:

1. `UnknownApiErrorException → unknown` の写像を**撤回**し、**HTTP status による 2 分岐**にした
   (表に載せた唯一の特別規則)。`getHttpStatus() >= 500` → `provider_unavailable` /
   それ以外 → `provider_rejected`。HTTP status class は Stripe が生成する可変文字列ではなく
   標準の有界な語彙なので、「外部語彙をそのまま採らない」方針と矛盾しない。
2. 結果として **写像表の値に `unknown` を持つ entry が 0 件**になった。
   `unknown` は「写像表に一致が無かった」ことと 1:1 に対応し、
   運用契約 (`unknown` = 分類器の欠落 → 表へ追加せよ) がそのまま無矛盾に成立する。
3. これを機械で固定した: gate に
   **「写像表の値に `GatewayFailureClass::Unknown` が現れてはならない」**を追加。
   案 2/3 が解こうとした「明示的 unknown と未登録 unknown の区別」は、
   **明示的 unknown を禁止する**ことで区別する必要そのものを消した (思考原則 2)。
4. 特別規則の増殖を防ぐため、gate に
   「status で細分するクラスは `UnknownApiErrorException` **ちょうど 1 件**」を exact fit で置いた。

## [Warning] `QueryException → local_infrastructure` は運用行動を誤らせる

案 1 を採用。case 名を **`local_failure`** に改名し、定義を
「**自インフラ層 (DB / cache) が返した失敗**。障害・SQL 不備・制約違反のいずれもありうる。
DB / cache 層と直前のクエリを調べる」とした。
`invariant_violation` との区別軸を「**誰が検出したか**」(アプリ自身の `Assert` / 明示的例外か、
DB・cache 層が返した失敗か) として明文化した。

## [Warning] vendor 21 クラスの写像を実際の throw 条件で裏付けよ

この場で vendor のソースを読んで裏付け、**根拠欄に throw site を書いた**。名指しされた 4 件:

- `Cashier\InvalidPaymentMethod` — `vendor/laravel/cashier/src/PaymentMethod.php:31`
  `InvalidPaymentMethod::invalidOwner()`。PM が owner に属さない → `invariant_violation` で正しい。
- `Cashier\SubscriptionUpdateFailure` — `Subscription.php:910` `duplicatePrice()` /
  `1021` `cannotDeleteLastPrice()` / `1532` `incompleteSubscription()`。
  いずれも要求の前提が崩れている → `invariant_violation` で正しい。
- `Stripe\TemporarySessionExpiredException` — `_specificV2APIError()` の
  `temporary_session_expired` type のみ。再認証が要る → `provider_rejected`。
- `Stripe\CardException` — status 402。ただし本経路では `payOffSessionInvoice` が
  typed 結果 (`OffSessionChargeResultDto::failed`) へ変換するため、例外として来るのは
  他メソッド経由の想定外 → `provider_rejected`。

Cashier の 8 クラスはすべて具象 (abstract は無い) であることも確認した。
詳細設計の受入条件に「各 entry の根拠欄が throw site または公式仕様を指すこと」を加えた。

## [Warning] `provider_rejected` は「一次切り分けが決まる」に対して広すぎる

期待効果の表現を「**再送で収束するか否かの一次切り分けが決まる**」に限定し、
「待つ / 直す / 調べる」という 3 分法の書き方をやめた
(`provider_rejected` の中で誰が直すかまでは決まらない、と明記した)。

## [Warning] `unknown` の受容判断をコメントに残すだけでは機械判定できない

Critical の対応で**明示的 `unknown` が存在しなくなった**ため、
「受容済みか未対応か」という状態そのものが消えた。
運用契約から「写像表のコメントへ残す」という逃げ道を削除し、
「`unknown` が出たら必ず表へ足す (件数 cap が動くので差分に必ず現れる)」に一本化。
owner (課金基盤の担当) を `docs/architecture.md` に書く要件は残した。

## [Warning] PHPDoc の型を `class-string<Throwable>` に絞れ

`context()` を `array{failure_class: string, error_class: class-string<Throwable>}`、
`map()` を `array<class-string<Throwable>, GatewayFailureClass>` に修正。
「enum を保持できる場所では `GatewayFailureClass` のまま扱い、`->value` にするのは
ログ境界 (`context()`) だけ」という実装契約も明記した。

## 確認したい点

1. `UnknownApiErrorException` の status 2 分岐を「表の唯一の特別規則」として認めるか。
   (認めない場合の代替は「5xx を分類できないまま `unknown` に落とす」しかなく、
    それは本設計の目的を損なうと考えている)
2. 「写像表の値に `unknown` を禁止する」という gate が、
   `unknown` の意味 (写像の不在) を機械で一意にする手段として十分か。

---

## 修正後の概念設計 (全文)

# 概念設計: billing-gateway-error-taxonomy

> 一次入力: [`recon-brief.md`](./recon-brief.md)
> 出自: aicue:T131 (job-execution-deduplication 保証側実装) の Codex 合議で
> 「本 PR の範囲外・独立 TODO 起票が妥当」と決着した残課題 2 件。

## 背景・課題

`AutoRechargeService` は決済 gateway (`AutoRechargeGatewayInterface`) の失敗を
`catch (Throwable)` で受けるしかない。interface の docblock が
「Stripe 障害・設定不備は例外のまま伝播 (fail-closed)」と宣言しているためである。

その結果、**同一クラスの中で観測語彙が 2 系統に割れている**:

| 経路 | 行 | 記録している値 | 性質 |
|------|----|---------------|------|
| `terminateInvoiceBestEffort()` (T131 新設) | L705 / L712-720 | `$exception::class` (有界) + サニタイズ済み例外の `report()` | 有界な語彙のみ |
| `tryTerminateInvoice()` (姉妹経路) | L833 | `$e->getMessage()` | **外部生成の可変文字列** |
| `reconcile()` attempt 処理失敗 | L994 | `$e->getMessage()` | 同上 |
| `reconcile()` 取りこぼし起票失敗 | L1014 | `$e->getMessage()` | 同上 |

問題は 2 つある。

1. **次に触る人がどちらに倣うべきか決まらない**。T131 が「クラス名だけ記録する」形を
   選んだのは合議の結論だが、その判断は同じクラスの隣の 3 箇所へ適用されていない。
   規約として書かれていないので、次の実装者は隣の行を真似る。
2. **`$e->getMessage()` は外部サービスが生成する可変文字列**であり、構造化ログに
   何が混ざるかの契約が無い (T131 の Codex 指摘の本体)。3 箇所に残っている。

さらに、クラス名は「有界」ではあるが**分類ではない**。運用担当が知りたいのは
「待てば直るのか / 我々が直すのか / データを調べるのか」であって、
`Stripe\Exception\ApiConnectionException` という文字列そのものではない。

## 仮説

**検証したいこと**: 決済 gateway 由来の失敗を「呼び出し側が取れる行動」で 5 つに分類した
**有界な語彙 1 系統**に統一すれば、(a) 構造化ログから外部生成文字列が消え、
(b) 同一クラス内の観測語彙の分裂が解消し、(c) 次に触る人が倣うべき形が
機械 (Architecture gate) で決まる。

**成功判定**: 次の 5 つがすべて満たされること。
1. `AutoRechargeService` から `$e->getMessage()` が 0 件になる
   (AGENTS.md **思考原則 3**「後方互換の並走を残さない」= 旧語彙を残さない。
   ブリーフはこれを「禁止事項 3」と書いているが誤りで、禁止事項 3 は dev DB 破壊操作である)。
2. gateway を注入される app 側の全クラスが「観測する / 伝播させる」のどちらかに
   deny-by-default で分類され、未分類が fail する。
3. Stripe SDK / Cashier が定義する例外クラスが**すべて明示的に分類**されており、
   ライブラリ更新で未知クラスが増えたら**赤くなる** (無音で `unknown` に落ちない)。
4. **fake が本物と同じ分類を返す**ことが機械で固定される。
5. 課金の**制御フローが 1 バイトも変わらない** (前後の等価性を Feature テストで固定)。

## 改善アイデア

### 中心の判断: interface 契約は変えない。分類器 1 本に寄せる

ブリーフの論点 1 は「9 メソッド全部を変換するか / 取り消せない副作用の 3 つに絞るか」だが、
**そのどちらも採らない**。`AutoRechargeGatewayInterface` の契約は変更しない。

根拠は 3 つある。

- **変換だけでは閉じない**。`reconcile()` の 2 箇所は gateway 以外の例外
  (DB の `QueryException` / `Assert` の不変条件違反 / lock timeout) も受ける。
  境界でドメイン例外へ変換しても、この 2 箇所は依然として `catch (Throwable)` のままで、
  「ドメイン例外の分類」と「それ以外の何か」という**新しい 2 系統**が生まれる。
  本設計の目的 (語彙を 1 系統にする) と正面から衝突する。
- **3 gateway の非対称**。本 interface だけ契約が変わると
  「狭い gateway + gateway 単位の Fake bind」規約 (サブスク系 `StripeGatewayInterface` /
  チケット checkout 系 `TicketCheckoutGateway`) の中で 1 つだけ作法が違う状態になる。
  規約を揃えるなら 3 つ全部を変える必要があり、スコープが跳ねる (思考原則 2 に反する)。
- **必要なのは分類であって型ではない**。呼び出し側は分類で分岐しない (制御フローは変えない)。
  型に載せる価値は「分岐の網羅性を型で守れる」ことだが、分岐しない以上その価値は無い。

代わりに、**`Throwable` → 有界な分類 enum の純関数**を 1 本置き、
gateway 由来かどうかにかかわらず**観測点で分類する**。

### 分類語彙 (`GatewayFailureClass`)

Stripe の error code をそのまま採らない。**呼び出し側 / 運用担当が取れる行動**で切る。

| case | 定義 | 運用の行動 |
|------|------|-----------|
| `provider_unavailable` | 決済事業者側の一時的な不能 (接続断・タイムアウト・レート制限)。**同じ要求の再送で収束しうる** | 待つ (リコンサイルが再試行する) |
| `provider_rejected` | 決済事業者が要求を受理しなかった。**同じ要求を再送しても収束しない** (要求内容・認証情報・利用者操作のいずれかが要る) | 要求内容 / 設定 / 利用者導線を直す |
| `invariant_violation` | **アプリ自身が検出した**不変条件違反 (`Assert` / 明示的な例外 / SDK・Cashier の誤用 / 金額不一致) | 該当データとコードを調べる |
| `local_failure` | **自インフラ層 (DB / cache) が返した失敗**。障害・SQL 不備・制約違反のいずれもありうる | DB / cache 層と直前のクエリを調べる |
| `unknown` | **写像表に一致が無かった** | **分類器を直す** (この case が出ること自体が欠落の通知) |

`invariant_violation` と `local_failure` の区別軸は「**誰が検出したか**」である
(アプリ自身の `Assert` / 明示的例外か、DB・cache 層が返した失敗か)。
`local_failure` を「インフラ障害」と名乗らないのは、`QueryException` が
接続障害だけでなく SQL 不備・制約違反も包むため、「DB のメトリクスだけ見て終わる」
誤誘導を作らないためである。

粒度をこれ以上細かくしない (思考原則 2)。case を足す条件は
「運用担当が取る行動が既存 case と異なる」ことに限る。

**`unknown` は写像表の値として使わない**。`unknown` は「写像の不在」専用であり、
表に `unknown` を書く entry を作ると「登録済みなのに unknown」という状態が生まれ、
運用契約 (`unknown` → 表へ追加せよ) と矛盾する。gate がこれを機械で禁止する。

### 写像表 (概念設計で確定する。詳細設計はこれを実装するだけ)

母集団の定義: `Stripe\Exception\` **直下**の具象クラス (interface / abstract は除く。
サブ名前空間 `Stripe\Exception\OAuth\` は Connect OAuth 専用で本アプリは未使用のため母集団外。
**サブ名前空間が 2 つ以上になったら gate が赤くなる**ようにして、母集団定義の再検討を強制する) と
`Laravel\Cashier\Exceptions\` の具象クラス。

写像の根拠は**推測ではなく vendor の throw site** に置く。Stripe 側の正本は
`vendor/stripe/stripe-php/lib/ApiRequestor.php` の `_specificV1APIError()` で、
**HTTP status の `switch`** になっている
(400→InvalidRequest / 400 かつ `idempotency_error`→Idempotency / 400 かつ `rate_limit`→RateLimit /
401→Authentication / 402→Card / 403→Permission / 404→InvalidRequest / 429→RateLimit /
**default→UnknownApiError**)。`_specificV2APIError()` は `temporary_session_expired` type だけを
`TemporarySessionExpiredException` に振り、残りは V1 へ委譲する。

| vendor 例外クラス | case | 根拠 (throw site / 意味) |
|---|---|---|
| `Stripe\Exception\ApiConnectionException` | `provider_unavailable` | HTTP 到達前の接続断・タイムアウト。再送で収束しうる |
| `Stripe\Exception\RateLimitException` | `provider_unavailable` | status 429 (および 400+`rate_limit`)。待てば通る |
| `Stripe\Exception\InvalidRequestException` | `provider_rejected` | status 400 / 404。要求パラメータか対象 id の不正 |
| `Stripe\Exception\AuthenticationException` | `provider_rejected` | status 401。API キー不正・失効 |
| `Stripe\Exception\PermissionException` | `provider_rejected` | status 403。権限不足 |
| `Stripe\Exception\IdempotencyException` | `provider_rejected` | 400 かつ `idempotency_error`。同一キーに異なるパラメータ |
| `Stripe\Exception\CardException` | `provider_rejected` | status 402。通常は `payOffSessionInvoice` が typed 結果へ変換するため、ここへ来るのは他メソッド経由の想定外 |
| `Stripe\Exception\TemporarySessionExpiredException` | `provider_rejected` | V2 API の `temporary_session_expired`。再認証が要る |
| `Stripe\Exception\SignatureVerificationException` | `provider_rejected` | webhook 署名不一致 = 秘密の設定不整合。gateway 経路では発生しないが、母集団を全域で埋める |
| `Stripe\Exception\UnknownApiErrorException` | **status で 2 分岐 (下記)** | `switch` の `default:` = 上記以外の status。**Stripe の 5xx はすべてここに来る** |
| `Stripe\Exception\BadMethodCallException` | `invariant_violation` | SDK の誤用 = 自コードの欠陥 |
| `Stripe\Exception\InvalidArgumentException` | `invariant_violation` | 同上 |
| `Stripe\Exception\UnexpectedValueException` | `invariant_violation` | 同上 |
| `Laravel\Cashier\Exceptions\IncompletePayment` | `provider_rejected` | `HandlesPaymentFailures` 経由。追加認証 (SCA) が要る = 再送では収束しない |
| `Laravel\Cashier\Exceptions\CustomerAlreadyCreated` | `invariant_violation` | `ManagesCustomer.php:69` `exists()`。自アプリの状態管理の齟齬 |
| `Laravel\Cashier\Exceptions\InvalidCustomer` | `invariant_violation` | `ManagesCustomer.php:53` `notYetCreated()` |
| `Laravel\Cashier\Exceptions\InvalidPaymentMethod` | `invariant_violation` | `PaymentMethod.php:31` `invalidOwner()`。PM が owner に属さない |
| `Laravel\Cashier\Exceptions\InvalidInvoice` | `invariant_violation` | `Invoice.php:77` `invalidOwner()`。invoice が owner に属さない |
| `Laravel\Cashier\Exceptions\InvalidCoupon` | `invariant_violation` | 本アプリは coupon を使わない。到達したら前提崩れ |
| `Laravel\Cashier\Exceptions\InvalidCustomerBalanceTransaction` | `invariant_violation` | 同上 (balance transaction を使わない) |
| `Laravel\Cashier\Exceptions\SubscriptionUpdateFailure` | `invariant_violation` | `Subscription.php:910/1021/1532`。更新の前提が崩れている |

**唯一の特別規則 — `UnknownApiErrorException` は HTTP status で細分する**:

| 条件 | case | 理由 |
|---|---|---|
| `getHttpStatus() >= 500` | `provider_unavailable` | **Stripe 側の一時障害**。決済 gateway で最も頻度の高い障害であり、正しい行動は「待つ」 |
| それ以外 (0 / 4xx / その他) | `provider_rejected` | status は分かっており、再送では収束しない |

「未知」なのは **error type** であって status ではない。ここを `unknown` に落とすと、
**Stripe の 5xx 障害という最も重要な失敗モードが分類できない**。
HTTP status class は Stripe が生成する可変文字列ではなく標準の有界な語彙なので、
「外部語彙をそのまま採らない」という方針と矛盾しない。
特別規則が増殖しないよう、gate が「status で細分するクラスはちょうど 1 件」を exact fit で固定する。

framework 側 (母集団は vendor 走査ではなく**明示宣言**。`reconcile()` の `catch (Throwable)` が
実際に受けうるものだけを載せる):

| クラス | case | 根拠 |
|---|---|---|
| `Illuminate\Database\QueryException` | `local_failure` | DB 層が返した失敗。障害・SQL 不備・制約違反のいずれもありうる |
| `Illuminate\Contracts\Cache\LockTimeoutException` | `local_failure` | cache 層のロック取得失敗 |
| `Webmozart\Assert\InvalidArgumentException` | `invariant_violation` | アプリ自身の `Assert` が検出した違反 (本物 gateway の paid invoice 拒否がこれ) |

**写像の解決規則**: 例外の実クラスから `get_parent_class()` で親を辿り、
**最初に表に一致した entry**を採る。一致が無ければ `unknown`。
`\RuntimeException` / `\InvalidArgumentException` などグローバル SPL クラスは
**表に入れない** (広すぎて分類にならない。`Stripe\Exception\InvalidArgumentException` と
`Webmozart\Assert\InvalidArgumentException` が同じ祖先を持つため、入れると意味が壊れる)。

### 分類器の public API (PHPStan level 10 前提で確定)

```php
final class GatewayFailureClassifier
{
    public static function classify(Throwable $throwable): GatewayFailureClass;

    /** @return array{failure_class: string, error_class: class-string<Throwable>} */
    public static function context(Throwable $throwable): array;

    /** 写像表の正本 (Architecture gate がこれを読む) @return array<class-string<Throwable>, GatewayFailureClass> */
    public static function map(): array;
}
```

**enum を保持できる場所では `GatewayFailureClass` のまま扱い、
`->value` にするのはログ境界 (`context()`) だけ**にする。

`is_a()` の文字列判定は使わない (level 10 で `class-string` を保ちにくく、
表の宣言順に依存する)。`context()` を用意するのは、**4 つの catch 箇所が同じ 2 キーを
同じ綴りで出すことをコードの構造で担保する**ためである
(gate は「宣言した catch 箇所の数 == `context(` の出現回数」を exact fit で検査する)。

### 未知の扱い (ブリーフ論点 2 への結論)

**deny-by-default を「写像表の全域性」で実現し、実行時は `unknown` へ落とすが検出可能にする**
という二段構えにする。

- **設計時 (機械)**: Stripe SDK (`Stripe\Exception\` 直下) と Cashier
  (`Laravel\Cashier\Exceptions\`) が定義する**具象例外クラスをすべて明示的に分類**する。
  Architecture gate が vendor を走査して「写像表の集合 == 実在クラスの集合」を要求するため、
  ライブラリ更新で新しい例外クラスが増えた瞬間に**赤くなる**。
  つまり「外部の語彙が増えたら無音で `unknown` へ落ちる」という失敗モードを構造的に閉じる。
- **実行時 (アプリ)**: それでも未知の `Throwable` は来る (アプリ自身の新しい例外など)。
  ここで例外を投げると**課金の制御フローを変えてしまう**ため、`unknown` へ落とす。
  ただし**必ず `error_class` を併記**するので、ログ基盤で `failure_class=unknown` を
  検索すれば分類器に足すべきクラスが一意に分かる。
  - `error_class` は「**外部サービスが生成する文字列ではない class-string**」である
    (値域はコードベース + vendor のクラス名に閉じる)。**有界**と言えるのは
    「写像表に載っている vendor 例外」の側であって、`error_class` が取りうる値の集合ではない。
    この 2 つを混同して書かない。

**運用契約 (これを書かないと `unknown` は通知になっていない)**:

| 項目 | 内容 |
|---|---|
| 検知 | ログ基盤で `failure_class=unknown` を検索し、`error_class` で facet する |
| 初動 | 出た `error_class` を写像表へ**必ず**追加し、gate の件数 cap を更新する (= 必ず差分に現れる) |
| owner | 課金基盤の担当 (`docs/architecture.md` に明記する) |
| 記載先 | `docs/architecture.md` §オートリチャージの失敗分類 (新設) |

「写像表のコメントに受容判断を残す」という逃げ道は**置かない**。
コメントでは「未対応なのか受容済みなのか」が機械判定できず、同じ例外が再発するたびに
判断をやり直すことになる。`unknown` が出たら表に足す、の一本にする。

**Stripe の error code / request id は採らない**。error code は外部語彙そのもので
増えたときに追随できず、request id は外部生成の文字列である。既に typed 結果で扱っている
カード拒否 (`OffSessionChargeResultDto::failureCode`) との二重管理にもなる。

### fake と本物の分類一致 (ブリーフ論点 4 への結論)

「fake が本物と違う例外を投げると、分類を使う経路がテストで一度も本物と同じ値を見ない」
という偽グリーンを、**fake が実ライブラリの例外クラスそのものを投げる**ことで閉じる。

- テスト用 spy (`Tests\Support\FakeAutoRechargeGateway`) の失敗注入を
  「分類を指定して投げる」形に変え、投げる実体は
  **共有 fixture (`Tests\Support\Billing\GatewayFailureFixtures`) が返す実ライブラリ例外**
  (`Stripe\Exception\ApiConnectionException` / `Webmozart\Assert\InvalidArgumentException` 等)
  にする。fake が独自の `RuntimeException` を投げる現状を消す。
- gate が (i) 全 case に fixture がある、(ii) `classify(fixture(case)) === case`、
  (iii) fixture が返すクラスが実ライブラリ名前空間に属する、(iv) spy のソースに
  fixture 経由でない `throw` が無い、を deny-by-default で固定する。
- runtime fake (`App\Services\Billing\Fakes\FakeAutoRechargeGateway`) は
  **例外を 1 つも投げない**契約 (bughunt 環境で決済を成立させない) なので、
  「`throw` を持たないこと」をソース走査で固定する = 分岐しようがない。

現状 spy の `terminateInvoice` は `RuntimeException` を投げ、本物は `Assert` 由来の
`Webmozart\Assert\InvalidArgumentException` を投げる。分類は前者が `unknown`、
後者が `invariant_violation` で**実際に食い違っている**。これは実在する偽グリーンである。

### (b) の 3 箇所 (ブリーフ論点 3 への結論)

**同じ PR で 3 箇所すべて直す**。interface を変えないと決めた以上、(a) の完成を待つ理由が無い
(「(a) から落ちてくる」という前提自体が消える)。思考原則 3 (後方互換の並走を残さない) により、
`$e->getMessage()` を残したまま新語彙を足す形は採らない。
T131 が作った `terminateInvoiceBestEffort()` も同じ 2 キー (`failure_class` / `error_class`) へ揃える
(T131 で確定した「原例外を `previous` に繋がない / `report()` はサニタイズ済み例外だけ」という
性質は**維持する。蒸し返さない**)。

## 期待効果

- **使命への貢献 (間接)**: 使命は SOP からの動画マニュアル生成だが、その前提として
  「オートリチャージが静かに壊れない」ことが要る。分類が付くと、
  **「再送で収束するのか否か」の一次切り分けがログ 1 行で決まる**
  (`provider_rejected` の中で「誰が直すか」までは決まらない。そこまで主張しない)。
- **`AutoRechargeService` の構造化ログ context と `report()` 文言から
  外部生成文字列が消える** (T131 が自経路で閉じた性質を、同じクラスの残り 3 経路へ広げる)。
  - **誇張しない**: 母集団のうち 3 job は gateway 例外を catch せず**伝播させる**設計であり、
    その経路では Laravel の例外ハンドラ / `failed_jobs` に vendor 例外の message が載る。
    これは意図した非対称 (伝播は queue の再試行と `failed_jobs` が可観測性を担う) であり、
    例外報告基盤での横断 redact はスコープ外である。
    **この非対称を目録の免除根拠に書かせる** — 免除は「catch しないから安全」ではなく
    「catch しない結果どこに何が残るか」を書く欄にする。
- **次に触る人の判断が機械で決まる**。gateway を注入されるクラスを増やしたら、
  gate が「観測するのか伝播させるのか」を必ず問う。
- **ライブラリ更新で語彙が壊れたら CI が赤くなる** (今は無音で通る)。

## 実装方針（概要）

| # | 何を | どこに |
|---|------|--------|
| 1 | 分類 enum `GatewayFailureClass` (5 case) | `app/Enums/Billing/` (新規) |
| 2 | 純関数の分類器 + ログ context ヘルパ | `app/Support/Billing/GatewayFailureClassifier.php` (新規) |
| 3 | 免除分類 enum `GatewayFailureObservationExemption` | `app/Enums/Security/` (新規) |
| 4 | `AutoRechargeService` の 4 catch 箇所を分類器へ統一 (`getMessage()` 全廃) | 既存変更 |
| 5 | spy の失敗注入を fixture 経由へ / fixture 新設 | `tests/Support/Billing/` (新規) + 既存変更 |
| 6 | deny-by-default 目録 gate | `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` (新規) |
| 7 | Feature テスト: 制御フロー等価性 + ログ語彙の固定 | 既存 `tests/Feature/Billing/` 更新 |
| 8 | 運用契約の記述 | `docs/architecture.md` + `AGENTS.md` ドメイン固有規約 |

制御フローは変えない。`catch` の構造・戻り値・状態遷移・Stripe 呼び出し回数はすべて現状のまま、
**ログの context と `report()` の文言だけ**が変わる。

## 制約・前提

- **PHPStan level 10** / Pest / `RefreshDatabase` グローバル適用 / `--parallel`。
- **課金の振る舞いを変えない**。分類は観測のためであり、制御フローを変えない
  (変える箇所は無い。等価性は Feature テストで固定する)。
- **T131 の確定判断を蒸し返さない**: `terminateInvoiceBestEffort()` の
  「原例外を `previous` に繋がない」「`report()` はサニタイズ済み例外のみ」
  「`tryTerminateInvoice()` を再利用しない (L671 の理由)」はそのまま維持する。
- **思考原則 3**: 旧語彙 (`getMessage()`) は同じ PR で消す (後方互換の並走を残さない)。
- **`AGENTS.md` ドメイン固有規約への追記は行うが 1 項目・数行に抑える**。
  既存 6 項目はいずれも「deny-by-default gate で恒久的に守る不変条件」であり、
  本設計 (gateway を注入されるクラスは観測か伝播のどちらかに目録登録が必須) は
  同じ性格を持つ。書かないと、次に gateway を注入する人は gate が赤くなるまで規約を知らない。
  詳細 (写像表・運用契約) は `docs/architecture.md` 側に置く。
- `tests/Architecture/ExternalFakeWiringInvariantTest.php` の既存契約
  (runtime fake の厳密クラス一致 / provider の bind 組と inventory の集合一致) を壊さない。
  本設計は runtime fake の**クラスも bind も変えない**ため、既存 gate への影響は無い。
- **新しい gate は vendor ディレクトリを走査する**。`composer update` で
  stripe-php / cashier の例外クラスが増減すると CI が赤くなる。これは意図した副作用であり、
  「増えたことを人間に必ず知らせる」ための費用として受け入れる。

## スコープ外（意図的に外したもの）

| 外したもの | 理由 |
|-----------|------|
| `AutoRechargeGatewayInterface` の 9 メソッドの契約変更 (境界でのドメイン例外化) | 上記「中心の判断」の 3 根拠。特に reconcile が gateway 以外の例外も受けるため**変換だけでは閉じず**、語彙が 2 系統になる |
| 他 2 gateway (`StripeGatewayInterface` / `TicketCheckoutGateway`) への横展開 | 今そこに割れた語彙が無い。必要になってから広げる (思考原則 2)。分類器自体は依存を持たないので後から流用できる |
| 例外報告基盤 (`app/Exceptions/`) での横断 redact | 横断基盤の変更。T131 の合議で既に「スコープ外」と決着済み |
| Stripe の error code / decline code / request id の記録 | 外部語彙。カード拒否は既に `OffSessionChargeResultDto` の typed 結果で扱っており、二重管理になる |
| 分類による制御フローの分岐 (再試行の出し分け等) | 「分類は観測のため」が本設計の前提。分岐が要るなら別 TODO で、そのとき初めて型 (ドメイン例外) の価値が出る |
| `app/` 全体での `getMessage()` 禁止 | 自前ドメイン例外の固定文言を利用者向けに出す正当な用途が多数ある (`BillingController` 等)。走査対象は gateway 消費クラスの目録に限定する |
| `SubscriptionService` / `StripeWebhookProcessor` の `getMessage()` | 本 interface の消費者ではない。母集団の定義 (gateway を注入されるクラス) の外 |
