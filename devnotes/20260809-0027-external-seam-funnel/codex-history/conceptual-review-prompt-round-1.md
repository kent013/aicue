【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

なお AGENTS.md の思考原則には次も含まれる (本設計の判断根拠として頻出する):
1. フレームワークのレンジ内でやる 2. **今必要なものだけ作る (オーバーエンジニアリング禁止。「あったら便利」は作らない)** 3. 後方互換の並走を残さない 4. **別物の概念を「似ているから」で統合しない** 5. テストファースト 6. タコツボ実装を避ける

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

【本件固有の追加観点】
8. **既定拒否 (deny-by-default) の設計として穴が無いか**: 「登録し忘れが永久に見えなくなる」逆向き (登録済みだけ調べる形) になっていないか。走査母集団が 0 件で緑にならないか。検査が空振りしないことの保証があるか。
9. **二重管理**: 既存目録 (T126 `EXTERNAL_CLIENT_BOUNDARY_INVENTORY`) と新目録の責務分離は、同じ到達事実を 2 箇所で宣言させない形になっているか。
10. **保証範囲の誠実さ**: 「保証しないもの」の記述が実態より控えめ／過大になっていないか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 参考: 既存コードの実測事実 (設計者が自分で確認済み)

- `app/Services/Captcha/RecaptchaVerifier.php:47` が `Http::asForm()->timeout(5)->post()` で Google siteverify を直叩き。ただし secret 未設定時は L37-44 で HTTP 前に fail-open して return する。
- `.env.bughunt.local` / `.env.bughunt.local.example` に `RECAPTCHA_SECRET_KEY` の宣言が無い。`MAIL_MAILER=log`。`TESTING_FAKE_EXTERNALS=true`。`STRIPE_SECRET` の宣言も無い。
- `app/Services/Captcha/Testing/RecaptchaVerifierTestFake.php` は `RecaptchaVerifier` を継承し `verify()` が常に true。参照点は `tests/Unit/Services/Captcha/RecaptchaVerifierTest.php:100` のみ。
- `app/Http/Requests/StoreInquiryRequest.php` が `new Recaptcha(app(RecaptchaVerifier::class), $this->ip())` で container 解決する (= container bind で差し替え可能)。
- `config/template.php` の `social_providers` は `google` を無条件で有効化。`app/Providers/FortifyServiceProvider.php` がログイン画面へ渡し、Svelte が `sso-login-google` ボタンを描画する。
- `Cashier::stripe()` / `$organization->stripe()` の実測: `EnsurePortalConfiguration` (2) / `StripeScheduleGateway` (6) / `CashierTicketCheckoutGateway` (2) / `CashierAutoRechargeGateway` (8) / `CashierStripeGateway` (4) / `AppServiceProvider` (1) = 6 クラス 23 site。
- `Http` facade を import するのは `FxRateService` と `RecaptchaVerifier` の 2 クラスのみ。`Mail` facade は `CreateInquiryAction` のみ、`Notification` facade は `OrganizationMembershipService` と `UpdateUserProfileInformation` の 2 クラスのみ。`Socialite` facade は `SocialAuthController` のみ。
- `ExternalFakeWiringInventory::bindings()` は現在 5 本 (課金 3 + storage 2)。`ExternalFakeWiringInvariantTest` の 3-1/3-2/3-3 は dataset 駆動なので entry 追加で検査が自動増加する。3-8 / 3-10 は集合一致なので provider と inventory を同時に更新すれば緑のまま。
- `FakeClassReferenceInvariantTest` の 4-2 (配置例外 2 件) と 4-4 (参照 allowlist 4 件) は、既存の許可ファイル `app/Providers/FakeExternalsServiceProvider.php` に配線を足すだけでは変化しない。

## 概念設計

（以下、devnotes/20260809-0027-external-seam-funnel/conceptual-design.md の全文）

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

- 新目録 `ExternalSeamInventory` の母集団は **決済 / 外部ログイン / captcha / メール / 市場データ**。
- **ファイル保存 (object_storage) と LLM は新目録の母集団に入れない**。前者は `EXTERNAL_CLIENT_BOUNDARY_INVENTORY`、後者は `PrismDirectDispatchScanner` が既に deny-by-default で検知しており、AG-112 確定 3 が静的 gate に求めるのは**検知だけ**だから、既存 gate で要求は充足している。
- 委譲は散文で書かず**機械的に結線**する: 種別 enum の全 case が「本目録が 1 件以上検出する」か「委譲表に載る」かのどちらかであることを強制し、委譲先については**ファイルが実在し当該識別子を含むこと**まで検査する (委譲先の消滅・改名で無言に穴が開かない)。
- **重要な注記**: `AppServiceProvider` は AWS SNS クライアント構築 (既存目録) と `Cashier::stripe()->prices` (新目録) の**別々の事実**で両目録に載る。これは二重管理ではない。二重管理を禁じるのは「同じ到達事実の二重宣言」であり、規則で分離しているので構造的に起きない。

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

- **SSO の集約先クラス (`SocialiteGateway`) を新設しない。** 標準形 (1) の「正規経路へ集約し直呼びを構文解析で禁止」は、目録が `Socialite` facade の参照を **`SocialAuthController` 1 クラスに exact-fit で固定する**ことで同値に満たせる。集約先クラスが要るのは**差し替え (fake) のため**だが、SSO fake は今回作らない (下記)。差し替え先が無い中間層は「あったら便利」であり AGENTS.md 思考原則 2 に反する。
- **SSO の fake を作らない。** 作ると `SocialAuthTest` / `RecentAuthTest` / `RecentAuthMethodStampingTest` の `Socialite::shouldReceive()` mock 3 ファイルを全面書き換えることになり、かつ bug-hunt にとって「SSO ログインを実際に試せること」は探索価値そのもの。bug-hunt のブラウザが Google へ出ている事実は**目録の「保証しないもの」として明記**し、遮断の要否は別 TODO とする。
- **`StripeScheduleGateway` を fake 配線に追加しない。** 唯一の消費点は artisan コマンド `ReconcileSubscriptionSchedules` で、ブラウザ走行の bug-hunt からは到達しない。目録は「守る対象」として登録し検知だけ行う (AG-112 確定 3)。
- **メール送信の遮断機構を作らない。** `.env.testing` は `MAIL_MAILER=array`、`.env.bughunt.local.example` は `MAIL_MAILER=log` で既に外部へ出ない。目録登録 + example env の `MAIL_MAILER` を pin する 1 assert で足りる。

## 3. 期待効果

- **使命への貢献 (間接だが具体的)**: bug-hunt は「思考ゼロ・編集ゼロ」を守るための UX 破綻検出装置である。その走行が実 Google / 実 Stripe / 実 SES へ副作用を出すと、bug-hunt は**安心して回せない道具**になり、探索の頻度が落ちて使命への寄与が減る。到達点を機械で数え切ることは、探索を安全に回し続けるための前提整備である。
- **標準形 (4) が captcha について実際に成立する** (登録しただけの状態にしない)。
- 新しい外部到達点 (決済・SSO・captcha・メール・市場データ) が**登録なしでは CI を通らない**。現在 12 クラスの母集団が、以後は増減が必ず差分に現れる。
- 「どの種別を、どの gate が見ているか」の対応表が**機械可読**になり、種類の数え落とし (ギャップ 6) が検出可能になる。

## 4. 実装方針 (概要)

### S1 走査基盤

`ExternalClientBoundaryScanner` の内部 (namespace / `use` alias 解決 / クラス・関数スコープ追跡 / 名前参照と呼び出しの列挙) を `Tests\Support\PhpReferenceScanner` へ**振る舞い保存で抽出**する。既存 `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php` (268 行) と T126 gate が回帰の証拠になる。`ExternalSeamScanner` は同じ基盤の上に §2-1 の規則だけを載せる。走査根は `app/` のみ (既存と同じ)。

### S2 語彙と目録

- `app/Enums/Security/ExternalSeamKind.php`: `Payment` / `SocialLogin` / `Captcha` / `Mail` / `MarketData` / `ObjectStorage` / `Llm` (後 2 者は**委譲専用**で母集団には現れないことを gate が固定する)
- `app/Enums/Security/ExternalSeamExemption.php`: 免除理由 (適用条件を docblock に書き、gate が前提表で機械検査する)
- `tests/Support/ExternalSeam/ExternalSeamInventory.php`: `static` メソッド形式 (`S3SurfaceInventory` の `--parallel` 規律に倣う)。分類は **`guarded` (守る対象) / `exempt` (身元検査不要)** の 2 値

予測母集団 (実測ベース・12 クラス): payment 6 / social_login 1 / captcha 1 / mail 3 / market_data 1。

### S3 gate

`ExternalClientTimeoutInventoryTest` の 5 点セット (対称差ゼロ / 空振り防止 / 30 字根拠 / 免除前提の突合 / 2 目録の結線) に、`BillingGatewayFailureTaxonomyInventoryTest` の作法 (冒頭に「保証するもの / 保証しないもの」、mutation coverage 表) を重ねる。追加で:

- **種別網羅**: `ExternalSeamKind::cases()` の全 case が「目録に 1 件以上」か「委譲表に載る」
- **委譲の実在**: 委譲先ファイルが存在し、指定の識別子 (`EXTERNAL_CLIENT_BOUNDARY_INVENTORY` / `PrismDirectDispatchScanner`) を含む
- **規則 → 名乗ってよい種別**の突合
- **負のコントロール**: 走査器の unit テストで「Stripe 例外の import だけのソースは 0 件」「値オブジェクト参照だけのソースは 0 件」「`Cashier::stripe()` は 1 件」を positive/negative 両方向で固定

### S4 captcha 配線

`FakeExternalsServiceProvider` に `RecaptchaVerifier` → `RecaptchaVerifierTestFake` の bind を追加し、`ExternalFakeWiringInventory::bindings()` に 6 本目として登録する (data-driven なので 3-1 / 3-2 / 3-3 の検査が自動で増える)。capability flag は既存 `testing.fake_externals` を使う — `.env.bughunt.local` のコメントが既に「外部サービス (LLM/Stripe/**Captcha**/SSO 等) を fake 化する capability flag」と宣言しており、実効値も `true`。新 flag は provision script / example env / `ProductionEnvGuard` を巻き込む (思考原則 2 に反する)。

ただし `PAYMENT_FLAG` / `PAYMENT_ENVIRONMENTS` / `registerPaymentFakes()` という名前は captcha を含んだ時点で**嘘になる**ため、`EXTERNALS_FLAG` / `EXTERNAL_FAKE_ENVIRONMENTS` / `registerExternalServiceFakes()` へ改名する (思考原則「機能の名前に立ち返れ」+ 3「後方互換の並走を残さない」)。config キー `testing.fake_externals` は変えない (env 契約を壊さない)。

**本番挙動は変わらない**: flag 既定 false + 環境 allowlist + `ProductionEnvGuard` の三重 guard は据え置き。`testing` レーンでも `TESTING_FAKE_EXTERNALS` は未設定 (既定 false) なので既存テストの解決結果は不変。

### S5 記録

- `docs/architecture.md` に「外部到達点の目録 (標準形 v1)」節を新設し、既存「S3 到達境界と面分類」から相互参照
- `AGENTS.md` ドメイン固有規約に 1 項追加 (現行 8 項 → 9 項)

## 5. 制約・前提

- PHPStan level 10 / Pest / `RefreshDatabase` グローバル適用 + `--parallel` / 個別 `DatabaseTransactions` 禁止
- Architecture レーンは `RefreshDatabase` を持たない。新 binding の abstract / real / fake の constructor が DB に触れないこと (`RecaptchaVerifier` は引数なし = 充足)
- 走査は `PhpTokenScan::normalize()` の結果 (コメント・DocComment 除去済み) に対して行う。AST (nikic/php-parser) は transitive 依存なので使わない (既存 gate と同じ裁定)

## 6. 保証しないもの (誇張しない)

- **出口を塞ぐ機構ではない**。目録は「新しい到達点の検知」であり、実行時の外部通信を止めない。bug-hunt のブラウザが SSO で `accounts.google.com` へ出ることは**目録では止まらない** (種別が目録配下にあることだけを保証する)。
- 文字列キーの container 解決だけで型名も呼び出しも出さない経路 (`app('foo')` の戻りを別メソッドへ渡す等) は検出できない (既存 T126 と同じ非対称)。
- 走査根は `app/` のみ。`routes/` / `config/` に直書きされた到達点は見ない。
- vendor 内部から出る通信 (Cashier / Socialite の内部実装) は app/ 走査では見えない。
- `.env.bughunt.local` は git 管理外であり、pin できるのは `.env.bughunt.local.example` まで。実 sandbox の env が example から乖離していることは検出できない。
- 決済の検出は「client の取得・構築」に限る。Stripe の別 API 表面 (例: 新しい静的 helper) が増えたときは規則の追加が要る。

## 7. スコープ外

- SSO の集約先クラスと fake (§2-3)
- `StripeScheduleGateway` の fake 配線 (§2-3)
- 実行時に外部出口を遮断する機構 (T130 の `StrayHttpRequestGuard` を別プロセスへ広げる話)
- lctl 台帳への `status_reported` 追記 (実装後の別作業)
