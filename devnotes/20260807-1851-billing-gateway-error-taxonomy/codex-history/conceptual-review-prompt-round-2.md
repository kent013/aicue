# Round 2: 対応マトリクスと修正後の概念設計

Round 1 の指摘 ([Critical] 0 / [Warning] 8) に対する判断と修正内容を報告する。
7 件は概念設計を修正し、1 件 (AGENTS.md 追記) は根拠を添えて反論した。

## 対応マトリクス

| # | Round 1 の指摘 | 判断 | 対応内容 |
|---|---|---|---|
| W1 | 「禁止事項 3 = 旧語彙を並走させない」は誤参照 (実際は思考原則 3) | 対応 | 2 箇所を思考原則 3 へ修正。一次入力 (recon-brief) 側の誤りである旨も注記 |
| W2 | 「次の 4 つ」と書いて 5 項目 | 対応 | 「次の 5 つ」に修正 |
| W3 | 分類表の具体が不足 (Stripe 13 + Cashier 8 の class-to-case を先に決めよ) | 対応 | **vendor 21 + framework 3 = 24 entry の class-to-case 表を追記**。併せて `provider_rejected` の定義を「同じ要求を再送しても収束しない (要求内容・認証情報・利用者操作のいずれかが要る)」へ精緻化し、Cashier の `IncompletePayment` (SCA) を **case を増やさずに**収容した。写像の解決規則 (実クラス → `get_parent_class()` の連鎖で最初の一致 / グローバル SPL クラスは表に入れない) も明記 |
| W4 | 「外部生成文字列がログ基盤に載らなくなる」は過大主張 (3 job は catch なしで伝播) | 対応 | 期待効果を `AutoRechargeService` の構造化ログ context と `report()` 文言に**限定**。伝播側 3 job では `failed_jobs` / 例外ハンドラに vendor message が載る非対称を明記し、**目録の免除根拠にこの非対称を書かせる**ことを設計要件へ格上げした (免除欄を「catch しないから安全」ではなく「catch しない結果どこに何が残るか」を書く欄にする) |
| W5 | `failure_class=unknown` の監視・初動が運用に載らないと放置される | 対応 | 「未知の扱い」節に**運用契約の表** (検知条件 / 初動 / 例外 / 記載先) を追記 |
| W6 | `error_class` を「有界」と書くのは不正確 | 対応 | 「外部サービスが生成する文字列ではない class-string (値域はコードベース + vendor のクラス名に閉じる)」へ表現を改め、有界性が gate で保証されるのは**写像表の側**であると分けて書いた |
| W7 | `AGENTS.md` への追記まで同 PR に含めるのは重いかもしれない | **反論** | 既存 6 項目はいずれも「deny-by-default gate で恒久的に守る不変条件」であり、本設計 (gateway 注入クラスは観測か伝播のどちらかに目録登録が必須 + gate) は同じ性格を持つ。書かないと次に gateway を注入する人は gate が赤くなるまで規約を知らない。ただし**分量は 1 項目・数行に抑える**制約を明記し、詳細は `docs/architecture.md` へ置く |
| W8 | PHPStan level 10: classifier の public API と写像表の型を固定せよ | 対応 | `classify()` / `context()` / `map()` の 3 メソッドを型付きで確定して概念設計に記載。`is_a()` は使わず `get_parent_class()` の決定的走査にする |
| S1 | 使命への貢献の書き方が遠い | 対応 | 「撮影が止まる時間」を削り「決済障害の一次切り分けがログ 1 行で決まる」に抑えた |
| S2 | vendor 走査の除外条件を固定せよ | 対応 | 母集団定義 (直下の具象クラスのみ / interface・abstract 除外 / サブ名前空間は `OAuth` の 1 つだけで、2 つ以上になったら gate が赤くなる) を概念設計に明記 |

## 追加で判断したこと (Round 1 では書いていなかった点)

- `Stripe\Exception\UnknownApiErrorException` は **`unknown` へ明示的に写像する**。
  SDK 自身が「未知の API エラー」と言っているものを既知のふりで `provider_unavailable` に
  倒すと、運用に「待てば直る」という誤った指示を出すことになる。
  写像表に entry があるので gate の全域性は満たし、実行時は検知対象になる。

## 確認したい点

1. 24 entry の写像表に、運用上の行動を誤らせる分類が含まれていないか。
2. `provider_rejected` の定義拡張 (利用者操作が要る場合を含める) が、
   case を増やさない判断として妥当か。それとも `user_action_required` を分けるべきか
   (分けると `OffSessionChargeResultDto::requiresAction()` という**既存の typed 語彙**と
   二重管理になる、というのが分けない理由である)。
3. 反論した W7 (AGENTS.md 追記) を受け入れられるか。

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
| `invariant_violation` | アプリ自身の不変条件違反 (`Assert` / SDK・Cashier の誤用 / 金額不一致) | 該当データとコードを調べる |
| `local_infrastructure` | 自 infra の失敗 (DB / cache lock) | 自インフラを調べる |
| `unknown` | 上記のどれにも当てはまらない | **分類器を直す** (この case が出ること自体が欠陥の通知) |

粒度をこれ以上細かくしない (思考原則 2)。case を足す条件は
「運用担当が取る行動が既存 case と異なる」ことに限る。

### 写像表 (概念設計で確定する。詳細設計はこれを実装するだけ)

母集団の定義: `Stripe\Exception\` **直下**の具象クラス (interface / abstract は除く。
サブ名前空間 `Stripe\Exception\OAuth\` は Connect OAuth 専用で本アプリは未使用のため母集団外。
**サブ名前空間が 2 つ以上になったら gate が赤くなる**ようにして、母集団定義の再検討を強制する) と
`Laravel\Cashier\Exceptions\` の具象クラス。

| vendor 例外クラス | case | 根拠 |
|---|---|---|
| `Stripe\Exception\ApiConnectionException` | `provider_unavailable` | 接続断・タイムアウト。再送で収束しうる |
| `Stripe\Exception\RateLimitException` | `provider_unavailable` | 流量制限。待てば通る |
| `Stripe\Exception\InvalidRequestException` | `provider_rejected` | 要求パラメータ / 対象 id の不正 |
| `Stripe\Exception\AuthenticationException` | `provider_rejected` | API キー不正・失効。待っても直らない |
| `Stripe\Exception\PermissionException` | `provider_rejected` | 権限不足。同上 |
| `Stripe\Exception\IdempotencyException` | `provider_rejected` | 同一キーに異なるパラメータ。再送では収束しない |
| `Stripe\Exception\CardException` | `provider_rejected` | 通常は `payOffSessionInvoice` が typed 結果へ変換する。ここへ来るのは他メソッド経由の想定外だが、意味としては「受理されず利用者操作が要る」 |
| `Stripe\Exception\TemporarySessionExpiredException` | `provider_rejected` | セッション期限切れ。再認証が要る |
| `Stripe\Exception\SignatureVerificationException` | `provider_rejected` | webhook 署名不一致 = 秘密の設定不整合。gateway 経路では発生しないが、表は母集団を全域で埋める |
| `Stripe\Exception\UnknownApiErrorException` | `unknown` | **SDK 自身が「未知の API エラー」と言っている**。既知のふりをしない |
| `Stripe\Exception\BadMethodCallException` | `invariant_violation` | SDK の誤用 = 自コードの欠陥 |
| `Stripe\Exception\InvalidArgumentException` | `invariant_violation` | 同上 |
| `Stripe\Exception\UnexpectedValueException` | `invariant_violation` | 同上 |
| `Laravel\Cashier\Exceptions\IncompletePayment` | `provider_rejected` | 追加認証 (SCA) が要る = 再送では収束せず利用者操作が要る |
| `Laravel\Cashier\Exceptions\CustomerAlreadyCreated` | `invariant_violation` | 自アプリの状態管理の齟齬 |
| `Laravel\Cashier\Exceptions\InvalidCustomer` | `invariant_violation` | 同上 |
| `Laravel\Cashier\Exceptions\InvalidPaymentMethod` | `invariant_violation` | PM が customer に属さない = 取り違え |
| `Laravel\Cashier\Exceptions\InvalidInvoice` | `invariant_violation` | invoice が customer に属さない = 取り違え |
| `Laravel\Cashier\Exceptions\InvalidCoupon` | `invariant_violation` | 本アプリは coupon を使わない。到達したら前提崩れ |
| `Laravel\Cashier\Exceptions\InvalidCustomerBalanceTransaction` | `invariant_violation` | 同上 |
| `Laravel\Cashier\Exceptions\SubscriptionUpdateFailure` | `invariant_violation` | 更新の前提 (数量・proration) が崩れている |

framework 側 (母集団は vendor 走査ではなく**明示宣言**。`reconcile()` の `catch (Throwable)` が
実際に受けうるものだけを載せる):

| クラス | case |
|---|---|
| `Illuminate\Database\QueryException` | `local_infrastructure` |
| `Illuminate\Contracts\Cache\LockTimeoutException` | `local_infrastructure` |
| `Webmozart\Assert\InvalidArgumentException` | `invariant_violation` |

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

    /** @return array{failure_class: string, error_class: class-string} */
    public static function context(Throwable $throwable): array;

    /** 写像表の正本 (Architecture gate がこれを読む) @return array<class-string, GatewayFailureClass> */
    public static function map(): array;
}
```

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
| 初動 | 出た `error_class` を写像表へ追加し、gate の件数 cap を更新する (= 必ず差分に現れる) |
| 例外 | 追加が妥当でない (一過性のアプリ例外) と判断したら、その判断を写像表のコメントに残す |
| 記載先 | `docs/architecture.md` §オートリチャージの失敗分類 (新設) |

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
  **決済障害の一次切り分け (待つのか / 直すのか / 調べるのか) がログ 1 行で決まる**。
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
