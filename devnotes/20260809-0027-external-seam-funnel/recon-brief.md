# 実査ブリーフ: external-seam-funnel

> lctl 台帳 (feature id: `external-seam-funnel`) の正典設計と aicue の実コードを突き合わせた調査結果。
> 2026-08-08 の実査 (T124〜T135 マージ後の main = c71061e 時点)。
> 設計フェーズの入力であり、設計そのものではない。

## 序列 (候補 7 件中)

- 順位: #2 / 想定タイトル: 外部到達点の既定拒否目録と経路集約
- テーマ / 優先度 / モード: backend / High / standalone
- value=8 effort=8 self_contained=True
- 選定理由: 標準形 (4) の明確な違反が実在する — RecaptchaVerifier が Http:: で Google siteverify を直叩きし、既存の RecaptchaVerifierTestFake が FakeExternalsServiceProvider にも ExternalFakeWiringInventory にも配線されていないため bug-hunt レーンから実 Google へ出る (StrayHttpRequestGuard は別プロセス実行に無言で効かないと AGENTS.md 自体が明記)。加えて Socialite は集約先クラスすら無く SocialAuthController が直呼びし、決済 (Cashier::stripe() 系 4 箇所) とメール送信は既定拒否の走査母集団に一切入っていない。裁定 AG-112 は確定済みで、aicue はファイル保存 fake を家系で唯一持つため一般化の最初の適用先に指名されている。走査器・目録・免除語彙・gate 5 系統の見本 (ExternalClientTimeoutInventoryTest / ExternalClientBoundaryScanner) が T126 で既に揃っており、effort 8 の見積もりより実質は軽い。

## 設計で最初に決めるべき論点

既存 EXTERNAL_CLIENT_BOUNDARY_INVENTORY (待ち上限の pin 目的) と新目録 (到達点の身元検査目的) を 1 本の走査 + 2 分類軸に束ねるか、object_storage だけ既存へ委譲する 2 目録にするかを AG-112 確定 3 (静的 gate の責務は新経路の検知だけ) に照らして決める。ここを決めないと同じクラスを 2 箇所で宣言する二重管理になる。併せて種別語彙を閉じた enum にするか (FxRateService が 6 種のどれにも属さない) を確定し、Stripe\ を素の接頭辞で走査したときの偽陽性 (GatewayFailureClassifier の例外 14 クラス / StripePriceCatalogEntry の値オブジェクト) を規則コードで分離する方針を先に置く。

## 台帳が確定させた標準形

標準形 v1 (裁定 AG-112) の必須は 4 点。(1) 外部到達を正規経路へ集約し、直呼びを構文解析で禁止。(2) 外向きの目印を静的走査し、新経路に「守る対象」か「身元検査不要」の明示登録を強制する既定拒否目録 (逆向き = 登録済みだけ調べる形は禁止。登録し忘れが永久に見えなくなるため)。(3) 責務分離をそのまま採る — 静的 gate は新経路の検知だけ、不変条件の証明は動的テスト。(4) 探索的検査 (bug-hunt) で出口の既定拒否を採らない種類は、目録か直呼び禁止の配下にあることを必須とする。対象は LLM・決済・外部ログイン・ファイル保存・captcha・メール送信。各リポジトリに委ねたのは走査規則・免除語彙・集約先クラスの具体形で、aicue はファイル保存 fake を家系で唯一持つため一般化の最初の適用先に指名されている。

## aicue の現状 (実在確認済み)

台帳の観測 (2026-08-06) から T126 で状況が変わっている。実査結果は以下。

【実在する】
1. LLM 直呼び禁止: /workspace/tests/Architecture/PromptGuardrailTest.php の PrismDirectDispatchScanner (token_get_all ベース。alias / カンマ区切り use / 完全修飾名 / case-insensitive を解決、ALLOWED_FILES は空)。加えて「Prompt::load の呼び出し箇所は app/Prompts/ に限る」テスト。
2. ファイル保存の既定拒否目録は実在する (台帳の「無い」は stale)。/workspace/tests/Architecture/ExternalClientTimeoutInventoryTest.php の const EXTERNAL_CLIENT_BOUNDARY_INVENTORY (13 クラス。surface=adapter が TakeObjectStorage / RenderObjectStorage の 2 件 = 守る対象、surface=exempt が 11 件 = 身元検査不要側で reason + rationale 付き)。走査器は /workspace/tests/Support/ExternalClientBoundaryScanner.php で TARGET_PREFIXES が Aws / League\Flysystem / Illuminate\Filesystem、TARGET_EXACT が Storage facade・Storage 属性・Filesystem 契約。検出規則は fqn_reference / imported_name_reference / new_external_object / disk_call / get_client_call / stripe_global_setter。テスト「到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ」が missing と stale の両方向を落とし、「到達境界: 走査母集団が空でない」が空振り防止、「到達境界: 免除には 30 文字以上の根拠がある」「到達境界: 免除理由の適用条件が走査結果と矛盾しない」(const EXTERNAL_CLIENT_EXEMPTION_PRECONDITIONS の forbidden/required を走査結果と突合) が付く。免除語彙は /workspace/app/Enums/Storage/ExternalClientBoundaryExemption.php の 6 case。面分類の正本は /workspace/tests/Support/Storage/S3SurfaceInventory.php。運用契約は /workspace/docs/architecture.md の「外部 SDK の待ち上限の規約 (T126)」(989 行〜) と「S3 到達境界と面分類」(1021 行〜)。
3. fake 配線側 (別 feature): /workspace/tests/Support/ExternalFakes/ExternalFakeWiringInventory.php (bindings() は課金 3 + storage 2 の計 5 件)、/workspace/tests/Architecture/ExternalFakeWiringInvariantTest.php (3-1〜3-12)、/workspace/tests/Architecture/FakeClassReferenceInvariantTest.php (4-1〜4-4)。
4. テストレーンの出口既定拒否 (T130): /workspace/tests/Support/StrayHttpRequestGuard.php + /workspace/tests/Architecture/StrayHttpEgressLaneGateTest.php。AGENTS.md 自身が「bug-hunt の別プロセス実行 / Socialite (Guzzle 直) / Stripe SDK / AWS SDK には無言で効かない」と明記している。

【実在しない (grep + ファイル実読で確認)】
5. 決済の到達点目録は無い。ExternalClientBoundaryScanner の TARGET_PREFIXES に Stripe\ は入っておらず、const STRIPE_GLOBAL_SETTER_EXPECTATION が setHttpClient / setMaxNetworkRetries / instance の 3 シンボルをパス×件数で固定するだけ。実際の Stripe client 取得点 — /workspace/app/Providers/AppServiceProvider.php:87 の Cashier::stripe()->prices、/workspace/app/Services/Billing/CashierStripeGateway.php:38 の Cashier::stripe()、同 182 行の $organization->stripe()、同 202 行の Cashier::stripe() — はどの目録にも載っていない。/workspace/tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php は deny-by-default だが対象は失敗分類の語彙 (T132) であって外向き経路の身元ではない。
6. 外部ログイン: /workspace/app/Http/Controllers/Auth/SocialAuthController.php:65,88 が Socialite::driver() を直呼びしている。目録も直呼び禁止も無い。/workspace/tests/Architecture/SocialProviderTrustPolicyTest.php は capability / email_trust の宣言漏れ検査のみで外向き経路には触れない。
7. captcha: /workspace/app/Services/Captcha/RecaptchaVerifier.php:47 が Http::asForm()->timeout(5)->post() で Google siteverify を直叩き。/workspace/app/Services/Captcha/Testing/RecaptchaVerifierTestFake.php は実在するが FakeExternalsServiceProvider にも ExternalFakeWiringInventory::bindings() にも登録されておらず、参照点は /workspace/tests/Unit/Services/Captcha/RecaptchaVerifierTest.php:100 だけ = bughunt.local レーンでは実 Google へ出る。
8. メール送信: 目録なし。到達点は /workspace/app/Actions/Inquiry/CreateInquiryAction.php:60,66 の Mail::to()->queue()、/workspace/app/Actions/Fortify/UpdateUserProfileInformation.php:73 と /workspace/app/Services/Organization/OrganizationMembershipService.php:92 の Notification::route('mail', ...)。
9. 標準形に列挙されていない外向き経路も 1 本ある: /workspace/app/Services/FxRateService.php:68 の Http::connectTimeout()。これも目録外。

## ギャップ

1. 決済の到達点目録が無い — ExternalClientBoundaryScanner の TARGET_PREFIXES に Stripe\ が無く、Cashier::stripe() / $organization->stripe() / new StripeClient のどれも既定拒否の走査母集団に入っていない。
2. 外部ログイン (Socialite) の目録も直呼び禁止も無く、SocialAuthController が Socialite::driver() を直接呼んでいる (集約先クラスすら存在しない)。
3. captcha の目録が無く、RecaptchaVerifier が Http:: で Google を直叩きし、既存の RecaptchaVerifierTestFake が FakeExternalsServiceProvider に配線されていないため bug-hunt レーンで実 Google へ出る (標準形 (4) 違反)。
4. メール送信 (SES / Mail facade / Notification::route) の到達点目録が無い。
5. ファイル保存の目録は実在するが、目的が待ち上限の pin であって外部到達の身元検査ではなく、正規経路への集約と直呼び禁止 (Storage facade 直用は免除登録で通る) が伴っていない。
6. 標準形 (4) が要求する「bug-hunt レーンで出口既定拒否を採らない種類 × 目録配下かどうか」の対応表がどこにも無く、種類の数え落としを機械検出できない。

## 想定スコープ

【新規】(a) tests/Support/ExternalSeam/ExternalSeamScanner.php — ExternalClientBoundaryScanner を種別ごとの TARGET 定義で一般化した走査器 (既存を壊さず切り出すか、ExternalClientBoundaryScanner を内部で再利用する)。(b) tests/Support/ExternalSeam/ExternalSeamInventory.php — 種別 (payment / social_login / captcha / mail / object_storage / llm) × クラス × 分類 (funnel = 守る対象 / exempt = 身元検査不要) の正本。static メソッド形式にする (S3SurfaceInventory のコメントにある --parallel 対応の規律)。(c) app/Enums/Security/ExternalSeamKind.php と ExternalSeamExemption.php — 種別と免除理由の型付き語彙。(d) tests/Architecture/ExternalSeamInventoryTest.php — 対称差ゼロ / 空振り防止 / 30 文字根拠 / 免除前提の突合 / 種別網羅 (bug-hunt レーン対応表) の 5 系統。(e) 集約先クラス: app/Services/Auth/SocialiteGateway.php (仮) など Socialite の funnel。
【変更】(f) app/Http/Controllers/Auth/SocialAuthController.php を funnel 経由へ。(g) app/Providers/FakeExternalsServiceProvider.php + tests/Support/ExternalFakes/ExternalFakeWiringInventory.php に captcha binding を追加 (標準形 (4) を満たすなら必須)。(h) tests/Architecture/FakeClassReferenceInvariantTest.php の 4-2 / 4-4 の件数 assert (現在 2 件 / 4 件固定) を更新。(i) docs/architecture.md に「外部到達点の目録」節を新設し、既存の「S3 到達境界と面分類」から相互参照。(j) AGENTS.md のドメイン固有規約に 1 項追加 (現行 8 項 → 9 項)。(k) docs/TODO.md への登録。
【gate の書き方の当たり】ExternalClientTimeoutInventoryTest.php が最良の見本。const 目録 + 走査結果との対称差ゼロ + 走査 0 件を落とす空振り防止 + 免除の 30 文字根拠 + 「免除理由ごとの forbidden / required 規則」を走査結果と突合する前提表、という 5 点セットをそのまま踏襲する。BillingGatewayFailureTaxonomyInventoryTest.php の冒頭にある「この gate が保証するもの / 保証しないもの」の明記と mutation coverage 表も同 repo の作法なので併せて採る。

## リスク

最大のリスクは既存 EXTERNAL_CLIENT_BOUNDARY_INVENTORY との二重管理。Aws / Flysystem 系を新目録にも入れると同じクラスを 2 箇所で宣言することになり、片方だけ更新して割れる。ファイル保存は既存目録に委譲し、新目録側は「object_storage は ExternalClientTimeoutInventoryTest の目録が正本」と機械的に結ぶ (既存の「到達境界: adapter 集合は面分類目録のクラスキーと一致する」と同型の結線テスト) のが安全。
次に偽陽性の量。Stripe\ を素の接頭辞で走査すると app/Support/Billing/GatewayFailureClassifier.php が 14 個の Stripe 例外クラスを import しており、app/DataTransferObjects/Billing/StripePriceCatalogEntry.php も Stripe\Price / StripeObject を参照する。既存走査器が AwsValueObjectOnly 免除で解いたのと同じ問題なので、例外・値オブジェクトの参照と client 構築・取得を規則コードで分けないと目録が肥大して信号が死ぬ。
波及: captcha fake を配線すると FakeExternalsServiceProvider の register/boot 構造と ExternalFakeWiringInvariantTest の 3-8 / 3-10 (集合一致)、FakeClassReferenceInvariantTest の 4-2 / 4-4 (件数固定 assert) が同時に赤くなる。SocialAuthController を funnel 経由へ変える場合は SSO ログイン・登録・連携の 3 intent と recent-auth の再 SSO satisfier (AGENTS.md ドメイン規約 8) に波及するため、tests/Feature/Auth 配下の既存テストの再走が要る。
既存挙動を壊しうる箇所はほぼ (f)(g) に限られ、目録と gate の追加自体は本番挙動を変えない。

## 実装者への申し送り (台帳と実コードの食い違いを含む)

台帳と実コードの食い違いが 1 件ある。aicue セルの「現状は LLM の直呼び禁止のみで、決済・ファイル保存の目録は無い」のうち、**ファイル保存は誤り**。T126 (devnotes/20260807-2032-todo-T126-design/ と devnotes/20260807-2127-todo-T126/) で、AWS SDK / Flysystem へ到達する app/ のクラスを deny-by-default で登録強制する EXTERNAL_CLIENT_BOUNDARY_INVENTORY が入っており、対称差ゼロ・空振り防止・30 文字根拠・免除前提の機械検査まで揃っている。台帳の観測点は 2026-08-06 で T126 は 2026-08-07 実装なので、単に観測が古い。決済の目録が無いことは正しい (STRIPE_GLOBAL_SETTER_EXPECTATION は 3 シンボルのパス×件数固定のみで到達点の目録ではない)。

もう 1 点、台帳の gates 欄にある「外部ログイン・ファイル保存・captcha・メール送信については目録も直呼び禁止も 5 リポジトリのいずれにも無い」も、ファイル保存については aicue で崩れている。実装後に append_event する際は、この 2 点を status_reported で明示的に是正しておくとよい。

一般化の設計判断として重要なのは、T126 の目録が「待ち上限を pin するための到達境界」という**別の目的**で作られている点。標準形 v1 が要求するのは「差し替え・監視のための到達点の身元検査」で、走査対象の名前空間は大きく重なるが分類軸 (adapter / exempt vs funnel / no-identity-check) が違う。同じ目録を 2 目的で使い回すか分けるかは、実装前に裁定 (AG-112) の「静的 gate の責務は新経路の検知だけ」に照らして決めること — 検知だけが責務なら 1 本の走査 + 2 つの分類軸で足りる可能性が高い。

captcha については、標準形 (4) を素直に読むと bug-hunt レーンで実 Google に出ている現状が明確な違反なので、目録追加だけでなく RecaptchaVerifierTestFake の配線まで含めないと「登録したが素通り」になる。ここは「今必要なものだけ作る」(AGENTS.md 思考原則 2) と衝突しないので同一 PR に入れてよい。

FxRateService (app/Services/FxRateService.php:68) は標準形が列挙する 6 種類のどれにも属さない外向き経路。目録の種別語彙を閉じた enum にすると必ずここで詰まるので、種別に other / market_data のような case を用意するか、種別を持たず「外向きであること」だけを母集団条件にするかを設計時に決めること。
