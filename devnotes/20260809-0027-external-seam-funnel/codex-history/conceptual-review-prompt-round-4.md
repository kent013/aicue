# Round 4: Round 3 の Warning 1 件への対応

### [Warning] (観点 3) §2-1 と §4 S3 で委譲検査の仕様が一致していない

**対応する。** ご指摘のとおり、Round 2 で §2-1 を 2 層契約へ書き換えた際に §4 S3 の旧記述が残っていた。§4 S3 を §2-1 と同一契約へ統一した。改訂後の該当箇所は次のとおり。

```
- **委譲の結線** (§2-1 と同一契約。2 層):
  1. **母集団の生存確認 (behavioral・主要保証)**: 委譲先が見ている母集団の導出を本 gate 側で**実行**し、
     空でないことを assert する (`config('template.social_providers')` /
     `ExternalClientBoundaryScanner` の app/ 走査 / `PrismDirectDispatchScanner` の走査根)
  2. **委譲先 gate の同定 (主要保証)**: 委譲先ファイルの実在 + **test 名の固定**
  - 識別子の文字列検索は**補助検査**であり主要保証には数えない (単独では §2-1 の契約を満たさない)
```

### [Suggestion] (観点 5) 抑制 site のパス・呼び出し位置を失敗メッセージに含める
→ **詳細設計で対応する** (概念設計の記述は据え置き)

### [Suggestion] (観点 7) コレクション要素型を PHPDoc generics で閉じ `mixed` / 未指定 array を残さない
→ **詳細設計で対応する**

その他の Suggestion は肯定的評価のため対応不要と判断した。

---

## 改訂後の概念設計 (全文)

# 概念設計: external-seam-funnel (外部到達点の既定拒否目録)

> 一次入力: `devnotes/20260809-0027-external-seam-funnel/recon-brief.md` (2026-08-08 実査)
> 本設計は実コードを自分で再実査したうえで、ブリーフの記述の**誤りを 3 点訂正**している (§0)。

## 0. 実査による訂正 (ブリーフを鵜呑みにしない)

| # | ブリーフの記述 | 実査結果 | 影響 |
|---|---------------|---------|------|
| A | 「RecaptchaVerifier が bug-hunt レーンから実 Google へ出る」 | **条件付きで誤り**。`.env.bughunt.local` / `.env.bughunt.local.example` のどちらにも `RECAPTCHA_SECRET_KEY` が無い。secret 未設定時 `RecaptchaVerifier::verify()` は `missing_secret` で **HTTP を出す前に** fail-open (`app()->environment('production')` が false) して `true` を返す。したがって**現状の既定 sandbox では Google へ出ない** | 「今出ている」ではなく「secret を 1 行足した瞬間に無言で出る」= **潜在**。誇張して書かない |
| B | 「Stripe 到達点は 4 箇所」 | **過少**。実測は `Cashier::stripe()` / `$organization->stripe()` が **6 クラス 23 site** (`EnsurePortalConfiguration` / `StripeScheduleGateway` / `CashierTicketCheckoutGateway` / `CashierAutoRechargeGateway` / `CashierStripeGateway` / `AppServiceProvider`) | 目録の母集団が 1.5 倍。`StripeScheduleGateway` は **fake 配線に無い** (後述 §4) |
| C | 「FakeClassReferenceInvariantTest の 4-2 / 4-4 の件数 assert を更新」 | **不要**。4-2 は `placementExceptions()` (2 件)、4-4 は `FAKE_REFERENCE_ALLOWED` (4 ファイル) を固定しており、captcha fake を **既存の許可ファイル** `FakeExternalsServiceProvider.php` に配線するだけでは**どちらも動かない** | 波及 2 件を削減 |

**訂正 A に代わる、条件なしで成立する標準形 (4) 違反を 1 件見つけた**:
`config/template.php` の `social_providers` は `google` を**無条件で有効**にし、ログイン画面が SSO ボタン (`sso-login-google`) を描画する。bug-hunt がこれを押すと `SocialAuthController::redirect()` → `Socialite::driver('google')->redirect()` が `https://accounts.google.com/o/oauth2/auth?...` への 302 を返し、**Playwright のブラウザ自身が実 Google へ出る**。AGENTS.md 自身が「Browser lane で Playwright のブラウザ自身が出す外部取得」を `StrayHttpRequestGuard` の保証外と明記しているとおりで、fake も目録も直呼び禁止も無い。

## 1. 背景・課題

裁定 AG-112 の標準形 v1 は 4 点を要求する。

1. 外部到達を正規経路へ集約し、直呼びを構文解析で禁止する
2. 外向きの目印を静的走査し、新経路に「守る対象」か「身元検査不要」の明示登録を強制する**既定拒否**目録 (登録済みだけ調べる逆向きは禁止)
3. 静的 gate の責務は**新経路の検知だけ**。不変条件の証明は動的テスト
4. bug-hunt で出口の既定拒否を採らない**種類**は、目録か直呼び禁止の配下にあること

aicue の実査結果 (自分で確認):

| 種別 | 既定拒否目録 | 実態 |
|------|------------|------|
| LLM | **あり** — `PromptGuardrailTest` の `PrismDirectDispatchScanner` (`ALLOWED_FILES` 空 = 完全直呼び禁止) | 充足 |
| ファイル保存 | **あり** — `ExternalClientTimeoutInventoryTest::EXTERNAL_CLIENT_BOUNDARY_INVENTORY` (13 クラス・対称差ゼロ・空振り防止・30 字根拠・免除前提突合) | 目的は待ち上限の pin だが、**母集団は「AWS/Flysystem へ到達する app/ クラス」そのもの**で身元検査の母集団と一致する |
| 決済 | **無い** | 6 クラス 23 site が無登録。`STRIPE_GLOBAL_SETTER_EXPECTATION` は大域 setter 3 シンボルのパス×件数固定であって到達点の目録ではない |
| 外部ログイン | **無い** | `SocialAuthController` が `Socialite::driver()` を 2 箇所で直呼び。**bug-hunt のブラウザが実 Google へ出る** |
| captcha | **無い** | `RecaptchaVerifier` が `Http::` で Google siteverify 直叩き。`RecaptchaVerifierTestFake` は実在するが `FakeExternalsServiceProvider` にも `ExternalFakeWiringInventory` にも未配線 (唯一の参照点は unit テスト 1 行) |
| メール送信 | **無い** | `Mail::to()->queue()` 1 箇所 + `Notification::route('mail', …)` 2 箇所 |
| (標準形の 6 種に無い) | — | `FxRateService` が `Http::` で為替 API を叩く。どの目録にも入らない |

課題は 3 つある。

- **検知が無い**: 決済・外部ログイン・captcha・メール・為替の 12 クラスは、新しい到達点が増えても機械では誰も気づかない。
- **登録したが素通り**: captcha は fake が存在するのに配線されておらず、目録に載せるだけでは標準形 (4) を満たさない。
- **二重管理の罠**: ファイル保存を新目録にも載せると、同じ到達点が 2 箇所で宣言され片方だけ更新されて割れる。

## 2. 改善アイデア

### 2-1. 論点への結論 (ブリーフが「設計で最初に決めるべき」とした 3 点)

**論点 1: 既存目録と新目録を 1 本に束ねるか、2 目録にするか。**

→ **2 目録・1 走査基盤。分離の境界は「クラス」ではなく「走査規則 (= 何の外部へ出るか)」に置く。**

- 新目録 `ExternalSeamInventory` の母集団は **決済 / 外部ログイン / captcha / メール / 市場データ**の**コード到達点**。
- **ファイル保存 (object_storage) と LLM は新目録の母集団に入れない**。前者は `EXTERNAL_CLIENT_BOUNDARY_INVENTORY`、後者は `PrismDirectDispatchScanner` が既に deny-by-default で検知しており、AG-112 確定 3 が静的 gate に求めるのは**検知だけ**だから、既存 gate で要求は充足している。
- **検知は「種別 × 次元」で数える** (conceptual-review Round 1 [Critical] 反映)。外部到達には *コード到達点* (どのクラスが外へ出るか) と *宛先集合* (どこへ出るか) の 2 次元がある。SSO は**宛先が `config/template.php` の `social_providers` で増える**ため、コード到達点 (`SocialAuthController` 1 クラス) を固定しただけでは新 IdP の追加を検知できない。ここは既存の `SocialProviderTrustPolicyTest` が deny-by-default (provider ごとに `capability` / `email_trust` の明示宣言必須 + `social_providers` が空なら fail) で押さえているため、**3 本目の委譲先**として結線する。同じ provider を 3 箇所で宣言させない (二重管理を避ける理由は object_storage と同じ)。
- 委譲は散文で書かず**機械的に結線**する (conceptual-review Round 2 [Warning] 反映で強化)。種別 enum の全 case が、必要な次元ごとに「本目録が 1 件以上検出する」か「委譲表に載る」かのどちらかであることを強制したうえで、委譲先については次の 2 層で検査する。
  1. **母集団の生存確認 (behavioral)**: 委譲先が見ている母集団の導出を**本 gate 側で実行**し、空でないことを assert する (`config('template.social_providers')` が空 / `ExternalClientBoundaryScanner` が 0 件 / `PrismDirectDispatchScanner` の走査根が消滅、のいずれも赤くなる)。文字列検索ではなく実行なので、走査条件の破壊を検出できる。
  2. **委譲先 gate の同定**: 委譲先ファイルの実在に加え、**その test 名**を固定する (例: `'全 SSO provider が capability / email_trust を明示宣言している'`)。テストごと消える / 名前が変わると赤くなる。
  ただし**委譲先の assert の中身を弱める改変は検出できない** (§6 に明記する)。委譲先ごとに型付き descriptor API を新設する案は、既存の緑 gate 3 本を書き換える割に得るものが「意味の再宣言」だけなので採らない (思考原則 2)。
- **重要な注記**: `AppServiceProvider` は AWS SNS クライアント構築 (既存目録) と `Cashier::stripe()->prices` (新目録) の**別々の事実**で両目録に載る。これは二重管理ではない。二重管理を禁じるのは「同じ到達事実の二重宣言」であり、規則で分離しているので構造的に起きない。**この点は gate の失敗メッセージにも明記する** (将来のレビューで「重複」と誤読されないため)。

根拠: AGENTS.md 思考原則 4 (別物の概念を「似ているから」で統合しない)。T126 の目録は「待ち上限を pin する」ための分類軸 (`adapter` / `exempt` + 面分類) を持っており、そこへ「差し替え・監視の対象か」という第 2 軸を足すと、失敗メッセージ (「S3 集約 adapter へ寄せるか…」) も免除 enum (`App\Enums\Storage\`) も意味が壊れる。

**論点 2: 種別語彙を閉じた enum にするか (FxRateService が 6 種に属さない)。**

→ **閉じた enum `ExternalSeamKind` にする。ただし aicue の語彙は標準形の 6 種に閉じない。** `MarketData` case を足す。

決め手は「規則から自然に落ちてくるか」。captcha の外向きの目印は SDK ではなく `Http` facade である。`Http` facade 参照を規則にすると `FxRateService` は**必然的に**同じ母集団へ入る (除外する方が不自然な規則になる)。よって種別を足す。

さらに、種別が「登録者の言い値」にならないよう **規則 → 名乗ってよい種別の対応表**を持ち、走査結果と突合する (免除前提表と同型)。`http_facade_reference` が名乗れるのは `{Captcha, MarketData}` だけなので、新しい `Http::` 直呼びは**必ず enum に case を足す判断**を通る = 新しい外向きの種類が黙って増えない。

**論点 3: `Stripe\` 素の接頭辞走査による偽陽性をどう分ける。**

→ **接頭辞走査をしない。** 決済の規則は「**client の取得・構築**」に限定する。

| 規則 | 検出対象 |
|------|---------|
| `payment_client_call` | 静的呼び出し `Cashier::stripe()` (receiver が `Laravel\Cashier\Cashier` に解決される) / メソッド呼び出し `->stripe()` |
| `payment_client_construction` | `new Stripe\StripeClient` (**完全一致**) |

これにより `GatewayFailureClassifier` の Stripe 例外 14 クラス import も、`StripePriceCatalogEntry` の `Stripe\Price` / `StripeObject` 値オブジェクト参照も、`StripeWebhookProcessor` / `Organization` / `Subscription` / `SyncBillingCustomerDetails` も**母集団に入らない** (実測で 0 件を確認する = 負のコントロール)。

`->stripe()` は receiver 非依存の名前一致なので、既存 `dropOrphanGetClientSites()` と同型の抑制を採る: **同一ファイルに決済名前空間 (`Laravel\Cashier\` / `Stripe\`) の参照がある場合のみ**登録する。

ただし抑制は**偽陰性の口**になる (conceptual-review Round 1 [Warning] 反映)。`Organization` を受け取るだけで Cashier / Stripe を import しないクラスが `$organization->stripe()` を呼ぶと、抑制されて目録に載らない。そこで **抑制した site 数が app/ 全走査で 0 件であること**を gate の assert にする。抑制が実際に働いた瞬間に赤くなるので、抑制規則が「静かに効いている」状態を作らせない。

`Stripe\HttpClient\CurlClient` (`ExternalClientTimeoutServiceProvider` の大域 pin) は `new Stripe\StripeClient` 完全一致に当たらないため新目録に入らない。既存 `stripe_global_setter` 規則が正本であり続ける = 責務が交わらない。

### 2-2. 施策 (5 本)

| # | 施策 | 性質 |
|---|------|------|
| S1 | 走査基盤の共通化 (`PhpReferenceScanner` 抽出) + `ExternalSeamScanner` 新設 | テスト基盤。本番挙動不変 |
| S2 | 型付き語彙 (`ExternalSeamKind` / `ExternalSeamExemption`) + 目録 `ExternalSeamInventory` | 宣言。本番挙動不変 |
| S3 | gate `ExternalSeamInventoryTest` (既定拒否 5 系統 + 種別網羅 + 委譲結線 + 負のコントロール) | 検査。本番挙動不変 |
| S4 | captcha fake の配線 (標準形 (4) を実際に満たす) + flag 名の是正 | **非本番挙動を変える** |
| S5 | ドキュメント (AGENTS.md 1 項 / docs/architecture.md 1 節) | 記録 |

### 2-3. やらないと決めたこと (根拠付き)

- **SSO の集約先クラス (`SocialiteGateway`) を新設しない。** ただし「目録に登録すれば通る」形では標準形 (1) の直呼び禁止・集約にならない (conceptual-review Round 2 [Warning])。そこで **`SocialLogin` 種別は `SocialAuthController` 1 クラスを名指しで固定し、他クラスは `guarded` でも `exempt` でも登録不能にする** (`TwoFactorStepUpInventoryTest` の「exemption にできない 6 本」と同型の作法)。これにより「正規経路は `SocialAuthController` 唯一・他クラスからの `Socialite::driver()` は登録手段が無く必ず赤」= 集約と直呼び禁止が機械化される。集約先を**別クラスとして切り出す**のは差し替え (fake) のためだが、SSO fake は今回作らない (下記) ので、差し替え先が無い中間層は「あったら便利」であり AGENTS.md 思考原則 2 に反する。
- **SSO の fake を作らない (= bug-hunt の SSO 外部遷移は本 PR では塞がらない)。** 作ると `SocialAuthTest` / `RecentAuthTest` / `RecentAuthMethodStampingTest` の `Socialite::shouldReceive()` mock 3 ファイルを全面書き換えることになり、かつ bug-hunt にとって「SSO ログインを実際に試せること」は探索価値そのもの。**本 PR は「検知 v1」であり「遮断」ではない**。bug-hunt のブラウザが Google へ出ている事実は §6 に明記し、遮断の要否は独立 TODO (`bughunt-sso-egress`) として起票する。
- **`StripeScheduleGateway` を fake 配線に追加しない。** 唯一の消費点は artisan コマンド `ReconcileSubscriptionSchedules` で、ブラウザ走行の bug-hunt からは到達しない。目録は「守る対象」として登録し検知だけ行う (AG-112 確定 3)。
- **メール送信の遮断機構を作らない。** `.env.testing` は `MAIL_MAILER=array`、`.env.bughunt.local.example` は `MAIL_MAILER=log` で既に外部へ出ない。目録登録 + example env の `MAIL_MAILER` を pin する 1 assert で足りる。

## 3. 期待効果

本 PR の位置づけは **「検知 v1」** である (遮断ではない)。効果は検知の範囲に限って主張する。

- **app/ のコード到達点**について、§2-1 の規則に合う新しい外部到達 (決済 client 取得・構築 / `Socialite` facade / `Http` facade / `Mail`・`Notification` facade) が**登録なしでは CI を通らない**。現在 12 クラスの母集団が、以後は増減が必ず差分に現れる。
- **SSO の宛先集合**について、`social_providers` への provider 追加が既存 gate で必ず `capability` / `email_trust` の宣言を要求され、その結線が機械で切れないことが保証される。**これは「provider 集合の増加と信頼属性の宣言漏れの検知」であって、宛先の許可制 (allowlist) でも bug-hunt での遷移可否の審査でもない** (任意の新 IdP に既存 enum 値を付ければ gate は通る)。
- **標準形 (4) が captcha について実際に成立する** (登録しただけの状態にしない)。secret を 1 行足しただけで bug-hunt が無言で Google を叩く潜在経路が閉じる。
- 「どの種別を、どの次元で、どの gate が見ているか」の対応表が**機械可読**になり、**登録済みの種別 × 次元の対応に対する欠落**が検出可能になる (ギャップ 6)。**新しい「次元」そのものの登録忘れは検出できない** — 次元の定義は人手であり、これは仕組み上避けられない (§6)。
- **使命への貢献 (間接)**: bug-hunt は「思考ゼロ・編集ゼロ」を守るための UX 破綻検出装置であり、その走行が外部へ副作用を出すほど回す頻度が落ちる。到達点を機械で数え切ることは、探索を回し続けるための前提整備である。ただし本 PR 後も **SSO のブラウザ外部遷移は残る**ため、「bug-hunt が外部に一切出なくなる」とは言えない (§6)。

## 4. 実装方針 (概要)

### S1 走査基盤

`ExternalClientBoundaryScanner` の内部 (namespace / `use` alias 解決 / クラス・関数スコープ追跡 / 名前参照と呼び出しの列挙) を `Tests\Support\PhpReferenceScanner` へ**振る舞い保存で抽出**する。既存 `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php` (268 行) と T126 gate が回帰の証拠になる。`ExternalSeamScanner` は同じ基盤の上に §2-1 の規則だけを載せる。走査根は `app/` のみ (既存と同じ)。

### S2 語彙と目録

- `app/Enums/Security/ExternalSeamKind.php`: `Payment` / `SocialLogin` / `Captcha` / `Mail` / `MarketData` / `ObjectStorage` / `Llm` (後 2 者は**委譲専用**で母集団には現れないことを gate が固定する)
- `app/Enums/Security/ExternalSeamExemption.php`: 免除理由 (適用条件を docblock に書き、gate が前提表で機械検査する)
- `tests/Support/ExternalSeam/ExternalSeamInventory.php`: `static` メソッド形式 (`S3SurfaceInventory` の `--parallel` 規律に倣う)。分類は **`guarded` (守る対象) / `exempt` (身元検査不要)** の 2 値
- entry は array shape ではなく **`readonly` value object** (`ExternalSeamEntry` / `ExternalSeamExemptionEntry`) にする (conceptual-review Round 1 [Suggestion] 反映)。`ExternalFakeBinding` / `GatewayObservationEntry` と同じ作法で、kind / classification / reason / rationale の欠落を PHPStan level 10 が静的に落とす

予測母集団 (実測ベース・12 クラス): payment 6 / social_login 1 / captcha 1 / mail 3 / market_data 1。

### S3 gate

`ExternalClientTimeoutInventoryTest` の 5 点セット (対称差ゼロ / 空振り防止 / 30 字根拠 / 免除前提の突合 / 2 目録の結線) に、`BillingGatewayFailureTaxonomyInventoryTest` の作法 (冒頭に「保証するもの / 保証しないもの」、mutation coverage 表) を重ねる。追加で:

- **種別 × 次元の網羅**: `ExternalSeamKind::cases()` の全 case が、必要な次元 (コード到達点 / 宛先集合) ごとに「目録に 1 件以上」か「委譲表に載る」
- **委譲の結線** (§2-1 と同一契約。2 層):
  1. **母集団の生存確認 (behavioral・主要保証)**: 委譲先が見ている母集団の導出を本 gate 側で**実行**し、空でないことを assert する (`config('template.social_providers')` / `ExternalClientBoundaryScanner` の app/ 走査 / `PrismDirectDispatchScanner` の走査根)
  2. **委譲先 gate の同定 (主要保証)**: 委譲先ファイルの実在 + **test 名の固定**
  - 識別子の文字列検索は**補助検査**であり主要保証には数えない (単独では §2-1 の契約を満たさない)
- **規則 → 名乗ってよい種別**の突合
- **抑制が効いていないこと**: `->stripe()` の同一ファイル条件で落とした site 数 = 0
- **SocialLogin の名指し固定**: `SocialLogin` 種別の登録は `SocialAuthController` **1 件のみ**。他クラスは `guarded` でも `exempt` でも登録できない (標準形 (1) の集約・直呼び禁止の機械化)
- **負のコントロール**: 走査器の unit テストで「Stripe 例外の import だけのソースは 0 件」「値オブジェクト参照だけのソースは 0 件」「`Cashier::stripe()` は 1 件」を positive/negative 両方向で固定

型の方針 (conceptual-review Round 2 [Suggestion] 反映): `classification` (`guarded` / `exempt`) と「次元」(`CodeReachPoint` / `DestinationSet`) も文字列ではなく enum にし、委譲表も array shape を残さず value object にする。走査器は**採用した site** と**抑制した site** を別のコレクションとして返し、抑制後に情報を復元しない構造にする (抑制 0 件検査が「復元して数え直す」実装にならないようにする)。

### S4 captcha 配線

`FakeExternalsServiceProvider` に `RecaptchaVerifier` → `RecaptchaVerifierTestFake` の bind を追加し、`ExternalFakeWiringInventory::bindings()` に 6 本目として登録する (data-driven なので 3-1 / 3-2 / 3-3 の検査が自動で増える)。capability flag は既存 `testing.fake_externals` を使う — 実効値は bug-hunt で既に `true` であり、新 flag は provision script / example env / `ProductionEnvGuard` を巻き込む (思考原則 2 に反する)。

ただし `PAYMENT_FLAG` / `PAYMENT_ENVIRONMENTS` / `registerPaymentFakes()` という名前は captcha を含んだ時点で**嘘になる**ため、`EXTERNALS_FLAG` / `EXTERNAL_FAKE_ENVIRONMENTS` / `registerExternalServiceFakes()` へ改名する (思考原則「機能の名前に立ち返れ」+ 3「後方互換の並走を残さない」)。config キー `testing.fake_externals` は変えない (env 契約を壊さない)。

**説明文の是正も同じ PR で行う** (conceptual-review Round 1 [Warning] 反映)。追跡対象の `.env.bughunt.local.example` は現在「Stripe 課金 fake の capability flag」としか書いておらず、captcha 追加後は不正確になる。また git 管理外の `.env.bughunt.local` には「LLM/Stripe/Captcha/SSO 等を fake 化する」という**実態より広い**説明が残っている (SSO fake は存在しない)。example と `config/testing.php` の docblock を「Stripe 課金 gateway + captcha 検証器を fake 化する。**SSO は fake しない**」に直し、名前・説明・実態を一致させる。

**本番挙動は変わらない**: flag 既定 false + 環境 allowlist + `ProductionEnvGuard` の三重 guard は据え置き。`testing` レーンでも `TESTING_FAKE_EXTERNALS` は未設定 (既定 false) なので既存テストの解決結果は不変。

### S5 記録

- `docs/architecture.md` に「外部到達点の目録 (標準形 v1)」節を新設し、既存「S3 到達境界と面分類」から相互参照
- `AGENTS.md` ドメイン固有規約に 1 項追加 (現行 8 項 → 9 項)

## 5. 制約・前提

- PHPStan level 10 / Pest / `RefreshDatabase` グローバル適用 + `--parallel` / 個別 `DatabaseTransactions` 禁止
- Architecture レーンは `RefreshDatabase` を持たない。新 binding の abstract / real / fake の constructor が DB に触れないこと (`RecaptchaVerifier` は引数なし = 充足)
- 走査は `PhpTokenScan::normalize()` の結果 (コメント・DocComment 除去済み) に対して行う。AST (nikic/php-parser) は transitive 依存なので使わない (既存 gate と同じ裁定)

## 6. 保証しないもの (誇張しない)

- **出口を塞ぐ機構ではない**。目録は「新しい到達点の検知」であり、実行時の外部通信を止めない。**bug-hunt のブラウザが SSO で `accounts.google.com` へ出る現状は本 PR では変わらない**。目録が保証するのは「その種別が検知の配下にある」ことだけである。
- **走査するのは `app/` のコード到達点だけ**。`routes/` / `config/` に書かれた到達コードは見ない (SSO の宛先集合だけは委譲で押さえるが、これは SSO 固有の措置であって一般化された config 走査ではない)。この非対称ゆえ、目録の名前は「外部到達点の目録」と呼ぶが、その実体は **「app/ のコード到達点 + 明示的に委譲した宛先集合」**である。docs / gate 冒頭でこの定義を先に置く。
- 文字列キーの container 解決だけで型名も呼び出しも出さない経路 (`app('foo')` の戻りを別メソッドへ渡す等) は検出できない (既存 T126 と同じ非対称)。
- vendor 内部から出る通信 (Cashier / Socialite の内部実装) は app/ 走査では見えない。
- `.env.bughunt.local` は git 管理外であり、pin できるのは `.env.bughunt.local.example` まで。実 sandbox の env が example から乖離していることは検出できない。
- 決済の検出は「client の取得・構築」に限る。Stripe の別 API 表面 (例: 新しい静的 helper) が増えたときは規則の追加が要る。
- 宛先集合を検知するのは SSO (`social_providers`) だけである。他種別の宛先 (Stripe の API キーが指す account、SES の region、為替 API の URL) は本 gate の対象外。
- **委譲先の検査の「中身」は保証しない**。委譲は (1) 母集団の生存確認 (実行) と (2) 委譲先 test 名の固定 の 2 層で結ぶが、委譲先の assert を弱める改変 (例: 必須宣言のうち 1 つを検査しなくする) は本 gate では検出できない。
- **次元そのものの数え落としは検出できない**。「コード到達点 / 宛先集合」という次元の定義は人手であり、未知の設定面や新しい SDK 表面が第 3 の次元を作った場合、本 gate は沈黙する。保証は**登録済みの種別 × 次元**の網羅に限る。
- `.env.bughunt.local` は git 管理外のため**本 PR の完了条件に含めない**。是正できるのは追跡対象の `.env.bughunt.local.example` と `config/testing.php` の docblock まで。

## 7. スコープ外

- SSO の集約先クラスと fake (§2-3)。**bug-hunt の SSO 外部遷移を塞ぐ話は独立 TODO `bughunt-sso-egress` として起票する** (本 PR の完了条件に含めない)
- `StripeScheduleGateway` の fake 配線 (§2-3)
- 実行時に外部出口を遮断する機構 (T130 の `StrayHttpRequestGuard` を別プロセスへ広げる話)
- config 走査の一般化 (SSO 以外の宛先集合)
- lctl 台帳への `status_reported` 追記 (実装後の別作業)


---

再レビューして全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。
