# 実査ブリーフ: legal-consent (同意バージョンの単一出典化)

> lctl 台帳 (feature id: `legal-consent`) の正典設計と aicue の実コードを突き合わせた調査結果。
> 2026-08-08 の実査 (main = c71061e 時点)。

## オーナーの指示 (2026-08-09。設計の前提)

**正典 t1 の本体のみを実装する。上積み 3 点はすべて実装しない。**

- (a) `config/legal.php` の env 口を外す → **やらない**
- (b) `ProductionEnvGuard` でプレースホルダ `draft-1` を拒否する → **やらない**
- (c) 法務ページへの適用版表示・規約文面の確定 → **やらない**

理由 (オーナーへの説明として提示し、了承された内容):
(a) は本番の `.env` に既に設定があると変更後に無言で無視される。(b) は既定値が
`draft-1` のままなので本番起動が落ちる破壊的変更であり、**リリース前に規約文面を確定する
タイミングで入れるのが自然**。(c) の文面確定は法務の領域でこちらでは決められない。

**したがって本設計のスコープは「版番号を決める場所を 1 箇所にして全員がそこを呼ぶ」ことに
限定される。アプリの振る舞いは変わらない。** 上積みを将来入れるときの前提条件は
設計の「スコープ外」節に理由つきで残すこと。

## 序列 (候補 7 件中)

- 順位: #3 / 想定タイトル: 同意バージョン解決点の単一化とgate
- テーマ / 優先度 / モード: backend / Medium / incremental
- value=6 effort=4 self_contained=True
- 選定理由: 正典 t1 の必須は 3 点だけで、うち t0 (config/legal.php・法務ページ blade 3 本・LegalPagesTest) は実在済み。残るのは LegalConsent クラス 1 本・呼び出し側 3 本・Architecture gate 1 本で effort 4 と最小。表示版と記録版のドリフト (users.consent_version / inquiries.consent_version は forceFill で書かれる証跡列) を構造的に塞ぐ話なので、正しさへの寄与も小さくない。裁定 AG-078 の範囲が明確で、法務文面や env 口の扱いといったオーナー判断の要る上積みを切り離せば単独完了できる。触るファイルが上位 2 件と完全に分かれるのも並行実装向き。

## 設計で最初に決めるべき論点

正準形を 1 形に決めること。aicue には他リポに無い 5 つ目の形 (CreateInquiryAction の Assert::stringNotEmpty 経由でローカル変数へ受ける形) が実在し、しかも fail-fast の役割を持っているので、LegalConsent へ寄せるときに空版 fail-fast を弱めない設計にする。同時に走査規則を legal. 名前空間または LEGAL_CONSENT_VERSION に限定し、素の 'consent_version' で走査して config('billing.auto_recharge.consent_version') 系 (BillingCheckoutRequest / ActivatePersonalRequest / ticket_auto_recharges) を巻き込まないことを最初に固定する。

## 台帳が確定させた標準形

t1 の必須は 3 点のみ。(1) 法務ページ設定 config/legal.php (t0 由来)、(2) 同意バージョンの解決点を単一クラス App\Support\Legal\LegalConsent へ集約、(3) app 配下が設定キー legal.consent_version を直読していないことを固定する Architecture gate (tests/Architecture/LegalConsentVersionSingleSourceTest.php)。spirux の「版↔描画結果 hash」台帳と CI の追記専用検査は正典 t1 に無い上積み、motivation の ProductionEnvGuard 同意版検査と blade への版表示も各リポジトリ裁量。規約改定時の再同意フロー・同意履歴表・文面と版番号の対応づけ・SSO 往復中の版固定は motivation が明示的にスコープ外とし、正典も要求していない。

## aicue の現状 (実在確認済み)

t0 部分は実在する。config/legal.php:22 に 'consent_version' => env('LEGAL_CONSENT_VERSION', 'draft-1') があり、.env.example:168 に LEGAL_CONSENT_VERSION=draft-1、法務ページ 3 本 (resources/views/legal/terms.blade.php / privacy.blade.php / commerce-disclosure.blade.php、routes/web.php:139-141 の Route::view) と tests/Feature/LegalPagesTest.php も実在する。terms.blade.php には既に data-testid="legal-terms" のラッパーがある (文面自体はプレースホルダ、最終改定日も未記入)。t1 の欠落分は以下。(a) App\Support\Legal\LegalConsent は不在 — app/Support/ 配下に Legal ディレクトリは無く、LegalConsent / PendingTermsConsent の文字列は app/ tests/ resources/ config/ database/ 全体で 0 件。(b) config('legal.consent_version') の読み手が app/ 配下に 3 本散在する: app/Actions/Fortify/CreateNewUser.php:94 (forceFill 内 'consent_version' => config()->string(...))、app/Services/Auth/SocialAccountService.php:75 (同形)、app/Actions/Inquiry/CreateInquiryAction.php:32-33,52 (config('legal.consent_version') をローカル変数へ取り Assert::stringNotEmpty してから forceFill)。(c) gate は不在 — tests/Architecture/ の 81 ファイルに LegalConsentVersionSingleSourceTest.php は無い。(d) app/Support/ProductionEnvGuard.php (168 行) の violations() に同意版の検査は無い (APP_KEY / CIPHERSWEET_KEY / STRIPE_WEBHOOK_SECRET / SESSION_SECURE_COOKIE / APP_DEBUG / HSTS / CSP / DEBUG_LOGIN_* / TESTING_FAKE_* / TrustHosts / TrustProxies のみ)。(e) 法務 blade に適用バージョンの表示は無い (resources/views/legal/ に version / バージョン / 版 の出現 0 件)。DB 側は users.consent_version (database/migrations/0001_01_01_000000_create_users_table.php:27) と inquiries.consent_version (database/migrations/2026_07_02_000000_create_inquiries_table.php:36) が nullable で実在し、どちらも $guarded/$fillable 外で forceFill 代入されている。テスト側の読み手は tests/Feature/Auth/RegistrationTest.php:25 と tests/Feature/Inquiry/ContactSubmissionTest.php:51。SSO の同意意思検証は app/Http/Controllers/Auth/SocialAuthController.php:49 (intent=register で terms_accepted=1 を要求) にあり、版は書かない。

## ギャップ

1. 同意バージョンの解決点となる App\Support\Legal\LegalConsent (DB 参照も状態も持たない設定アクセサ + 空版の fail-fast) が存在しない。
2. config('legal.consent_version') の直読が app/ 配下に 3 本 (CreateNewUser / SocialAccountService / CreateInquiryAction) 散在しており、表示版と記録版のドリフトを構造的に防げていない。
3. 古い設定キーの直接参照を禁じる tests/Architecture/LegalConsentVersionSingleSourceTest.php が無く、新しい書き込み経路が増えても機械的に検出されない。
4. database/factories/InquiryFactory.php:30 が 'draft-1' を literal で持ち、config と独立した 4 つ目の版の出所になっている。
5. app/Support/ProductionEnvGuard.php に同意版の検査 (空・未設定・プレースホルダ 'draft-1' の拒否) が無く、プレースホルダ版のまま本番起動できる (正典 t1 の必須ではなく motivation 形の上積み)。
6. 法務ページに適用中の同意版が表示されておらず、利用者が同意した版を確認する手段が無い (spirux が legal-document-version-display として別起票した論点)。

## 想定スコープ

新規 3 本: app/Support/Legal/LegalConsent.php (version() の 1 本化 + 空版 fail-fast)、tests/Architecture/LegalConsentVersionSingleSourceTest.php (G1 設定キーの読み手が 1 本 / G2 env の読み手は config/legal.php のみ / G3 書き手は正準形 1 形のみ + 正規表現の自己検証)、tests/Unit/Support/Legal/LegalConsentTest.php。変更 3 本 (呼び出し側): app/Actions/Fortify/CreateNewUser.php:94、app/Services/Auth/SocialAccountService.php:75、app/Actions/Inquiry/CreateInquiryAction.php:32-33,52 (ここだけローカル変数 + Assert 経由なので正準形へ揃える必要がある)。変更 (テスト/fixture): database/factories/InquiryFactory.php:30 の literal 'draft-1'、tests/Feature/Auth/RegistrationTest.php:25、tests/Feature/Inquiry/ContactSubmissionTest.php:51。任意 (上積みを取る場合): config/legal.php:22 の env 口を外す + .env.example:168 の削除 + docs 側の記述、app/Support/ProductionEnvGuard.php + tests/Feature/Support/ProductionEnvGuardTest.php への同意版検査追加、resources/views/legal/terms.blade.php / privacy.blade.php への適用版表示 + tests/Feature/LegalPagesTest.php の追補。gate の書き方は tests/Architecture/CheckoutLiveThresholdSingleSourceTest.php (単一出典の literal 検出 = ファイル一覧 + toContain/not->toContain の素朴形) が最小形の見本で、より厳密にやるなら tests/Architecture/ScenarioWritePathInventoryTest.php の token_get_all ベース走査 (コメント/文字列リテラル内容を無視して識別子・配列キーだけを見る deny-by-default scanner + allowlist) が既存の見本になる。母集団は app/ 配下の .php とし、tests/ と database/factories/ は母集団外にするか別 assertion で扱う。

## リスク

(1) 同意版の書き込み経路が motivation の 2 本ではなく aicue は 3 本である (公開問い合わせフォームが現役。motivation は AG-075 で撤去済みだったため 2 本)。gate の正準形を 2 本前提で書くと CreateInquiryAction を取りこぼす。(2) 名前空間の衝突が実在する — 課金の自動購入同意 config('billing.auto_recharge.consent_version') (config/billing.php:92) が app/Http/Requests/Billing/BillingCheckoutRequest.php:74 / app/Http/Requests/Onboarding/ActivatePersonalRequest.php:48 / ticket_auto_recharges.consent_version で使われており、'consent_version' という素の識別子で走査すると別 feature を巻き込んで false positive になる。検出は legal. 名前空間または LEGAL_CONSENT_VERSION に限定すること。(3) CreateInquiryAction は現在 Assert::stringNotEmpty で fail-fast しているので、LegalConsent へ寄せるときに fail-fast を弱めないこと (弱めると空版で証跡が記録される。この gate が防ぎたい破れそのもの)。(4) config/legal.php の env 口を外す (spirux 形) と本番/staging の LEGAL_CONSENT_VERSION が無言で無視されるため運用告知が要る。また .env.example から削除する場合 tests/Architecture/EnvExampleInvariantTest.php の既存 assertion には LEGAL_CONSENT_VERSION が無いので機械的な衝突は無い (確認済み)。(5) ProductionEnvGuard にプレースホルダ拒否を足すと、既定値が 'draft-1' のままなので production 起動が落ちる破壊的変更になる (TRUSTED_PROXIES と同種)。上積みを取るなら AGENTS.md の運用要件へ追記まで含める。(6) 法務文面自体がプレースホルダ (terms.blade.php の「アプリ公開時に記入」) なので、spirux 形の「版↔文面 hash 台帳」まで踏み込むと空文面を pin することになる。正典 t1 の範囲に留めるのが妥当。

## 実装者への申し送り

台帳の記述と実コードの食い違いが 1 点ある。aicue セルの note は「AG-078 が求める成果物が実在しない (再確認 2026-08-06。観測点 aicue@ad8c6a3)」だが、実読では t0 側の成果物 (config/legal.php・法務ページ blade 3 本・tests/Feature/LegalPagesTest.php) は実在し、さらに consent_version キー自体も config/legal.php:22 に既にある。実在しないのは t1 の上積み分 (LegalConsent クラスと Architecture gate) だけである。metamovics セルと同じ update_pending (t0→t1) が実態に近い。motivation の申し送り「追従時は不在の確認ではなく読み手の数を数えるべきである」がそのまま当てはまり、aicue の読み手は 3 本 (CreateNewUser / SocialAccountService / CreateInquiryAction) だった。motivation の handover_hints にある gate の 4 形 (raw / canonical / property / indirect) はそのまま使えるが、aicue には Assert 経由でローカル変数に受けてから forceFill する 5 つ目の形 (CreateInquiryAction) が実在するので、正準形へ揃えるか検出形に足すこと。canonical 形の末尾カンマを pin から外さない、という motivation の注意もそのまま有効。なお T124〜T135 の直近実装はいずれも legal-consent に触れていない (config/legal.php の最終変更は f693dd2 の初期コミット、CreateNewUser.php は 5835f53 = T077 が最後。HEAD は c71061e)。docs/TODO.md にも legal / 同意バージョン / consent の起票は 0 件。AGENTS.md 禁止事項 9 により本調査の成果物を Artifact で公開してはならない。
