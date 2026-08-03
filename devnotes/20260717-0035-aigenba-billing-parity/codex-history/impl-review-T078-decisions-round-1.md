# 対応マトリクス: impl-review T078 (P7) Round 1

対象: `devnotes/20260717-0035-aigenba-billing-parity/impl-review-T078-round-1.md`
差分: `git diff main...HEAD`（T078 = 決済 parity P7）

---

## [Critical] `?plan=` 契約違反：`free` が handoff されてしまう

- 判断: **部分的に対応する（指摘の因果は反証、根本の test 空振りは修正）**
- 根拠:
  - 本番の料金表 CTA が出す code は fixture ではなく **DB の `plans` 行**である。
    `PricingController::__invoke` → `PricingService::listPublicPlans()`（`Plan::query()->where('is_active', true)`）
    が code を供給し、`plans` から `free` 行は **P1/D11 で撤去済み**。
    実データ側は `tests/Feature/Marketing/PricingPageTest.php:35-48` が
    `page.plans.0.code === 'personal'` / `.1 === 'starter'` / `.2 === 'standard'`（3 件）で pin している。
  - したがって `/register?plan=free` は**本番で発生し得ない**。指摘の失敗シナリオ
    （無料プラン CTA → `intendedPlan=null` → 意図喪失）は成立しない。
    実際に発生するのは `/register?plan=personal` で、`normalizeRaw` は Personal を**受理する**
    （`IntendedPlanResolver::normalizeRaw`。除外は Enterprise のみ）。
    `tests/Feature/Auth/RegisterPlanHandoffTest.php` の dataset `'personal' => ['personal','personal']` が
    これを HTTP レベルで固定済み。
  - ただし指摘は**別の実在する欠陥**を掘り当てている: `tests/js/pages/Pricing.test.ts` の
    fixture が P1 以前の語彙（`code: "free"`）のままで、本差分で追加した href 期待が
    **到達しない code (`?plan=free`) を pin していた** = 「テストが不変条件を実際に固定していない
    （空振り）」に該当する。
- 対応内容:
  - `tests/js/pages/Pricing.test.ts` の fixture を `code: "free" / name: "Free"` →
    `code: "personal" / name: "Personal"` に更新。
  - href 期待を `["?plan=personal", "?plan=standard"]` に修正（PlanCode allowlist に実在し
    `normalizeRaw` が null 化しない値のみを期待値にする）。
  - testId 参照 `pricing-plan-free` → `pricing-plan-personal`、テスト名・ファイル冒頭コメントを更新し、
    「fixture の code は seed 値と対でなければならない」根拠（`PricingPageTest` が実データを pin）を明記。
  - **プロダクションコードは変更していない**（`Pricing.svelte` の `?plan=${plan.code}` は設計どおり
    aigenba verbatim。`free -> personal` マッピングのような追加変換は入れない = 原則 2/3）。
  - 再検証: `pnpm vitest run tests/js/pages/Pricing.test.ts` → 6 passed。

---

## [Warning] D16 の「3 箇所 inertia 遷移」要件が 1 箇所未達（Welcome nav）

- 判断: **反論する（意図的な逸脱として記録）**
- 根拠:
  - 設計 P7 の D16 は「`inertia` 属性を付ける（既存 :360 の `/pricing` Button と同じ SPA 遷移作法）」
    と書くが、その作法は **`Button` component の `inertia` prop** であり、hero (:160) と
    landing-pricing-cta (:358) は実際に `Button ... inertia` へ揃えてある。
  - nav (:137) は `GuestLayout` の `nav` snippet 内の**素の `<a>`** で、同じ snippet の
    兄弟リンク（`/pricing`「料金プラン」:132 / `/login` / `/dashboard`）はすべて素の `<a>` =
    full reload である。ここだけ SPA 化すると nav 内で遷移作法が割れる。
  - 素の `<a>` を SPA 化するには `@inertiajs/svelte` の `use:inertia` action が必要だが、
    **本コードベースに `use:inertia` の使用箇所は 0 件**（`grep -rn "use:inertia" resources/js` → 該当なし）。
    レビュー対象フェーズで新しい遷移パターンを 1 箇所だけ導入するのは原則 2（今必要なものだけ作る）
    および「タコツボ実装を避ける」に反する。
  - D16 の本質要件（LP から `/register` 直リンクを無くし料金表を必ず経由させる）は満たしており、
    `tests/js/pages/Welcome.test.ts` が「3 箇所とも `/pricing`」+「`a[href^="/register"]` が 0 本」で固定済み。
- 対応内容: コード変更なし。**逸脱として報告に記載**（nav リンクの SPA 化は nav snippet 全体の
  遷移作法統一として別タスクで扱うべき）。

---

## [Warning] 設計テスト計画の一部（既存テスト更新）が差分上で未実施

- 判断: **反論する（実質カバー済み）**
- 根拠:
  - `tests/Feature/Auth/EmailVerificationGateTest.php`: 設計本文が要求しているのは
    「continuation 無し時に既定着地が変わらない**非退行**（`assertRedirect` 群は**不変**）」。
    **変更しないこと自体が要件**であり、`composer test` で当該ファイルは green（= bind 追加後も既定着地が不変）。
  - `tests/Feature/Auth/FortifyResponseTest.php`: verify 着地のテストは元々存在しない
    （同ファイルは forgot-password / 再送 / プロフィール / パスワード変更・リセットのみ）。
    P7 の verify 着地は新規 `tests/Feature/Auth/RegisterVerifyFlowTest.php` が
    **両分岐**（continuation あり → `onboarding.checkout` / 無し → `config('fortify.home').'?verified=1'`）
    で固定しており、bind が外れれば必ず落ちる。テストの置き場所の差であり、カバレッジの穴ではない。
  - `tests/Feature/Marketing/PricingPageTest.php`: これは Inertia **props** の assert であり
    HTML を描画しないため CTA href を assert できない（href は `Pricing.svelte` 側）。
    href の固定先は `tests/js/pages/Pricing.test.ts`（本 round で `?plan=personal` に修正）が正しい。
    同 Feature テストは plan code の真実源（personal/starter/standard）を pin する役割を継続。
- 対応内容: コード変更なし。

---

## [Suggestion] P7 と無関係な `devnotes` 修正は分離推奨

- 判断: **対応済み（既に分離されている）**
- 根拠: `devnotes/20260717-0037-analysis-generate-timeout/{coverage-check,provider-quality-probe}.php` の
  変更は pint 整形のみで、実装コミット `845ef33` とは別コミット `9892231`（`style: pint 整形`）に
  分離済み。両ファイルは main の `1764e36` 由来の既存 pint 違反であり、
  `vendor/bin/pint --test` を green にするための最小整形。
- 対応内容: 追加変更なし。

---

## 自主レビュー（Codex 指摘外）で確認した点

| 観点 | 結論 |
|---|---|
| 禁止事項 #7（`redirect()->intended()`） | `VerifyEmailResponse` のみで使用。verify は **GET signed URL** の着地であり「操作系 POST の応答」ではない。Fortify 既定と同値を保つための意図的採用で docblock にも明記。`ActivatePersonalController`（POST）は `redirect()->to($continue ?? route('dashboard'))` で `intended()` を使っていない = 規約適合 |
| `RequireActiveSubscription` の return_to 保存条件 | 設計は `$canManage && GET/HEAD && ! $request->expectsJson()`。実装は `expectsJson` 条件を落としているが、**直前の 402 abort で expectsJson は到達しない**ため意味論は同値（判定の二重化回避）。`OnboardingReturnFlowTest` の「XHR は 402 で return_to を積まない」が実測で固定済み |
| open-redirect | `normalizePath` は raw + `rawurldecode` の二重判定 → 制御文字 / scheme / protocol-relative / バックスラッシュ / 先頭 `/` 必須 / `parse_url` の scheme・host・user・pass・port を全弾き。`peek` 側でも再正規化するため session 汚染でも外部遷移しない（`OnboardingReturnFlowTest` の「改ざんされた return_to は continueUrl に出ない」で固定） |
| IDOR / cross-org | `EmailVerificationContinuation::resolveUrl` は `is_int` → `$user->organizations()->whereKey()` の **relation 経由 fetch**。他組織 id 注入時 null を `RegisterVerifyFlowTest` が固定。org-scoped session key の組織はサーバ解決値であり payload 由来ではない（不変条件 #1/#3 適合） |
| SSO callback の linked-user 分岐 | `$linkedUser !== null`（連携済み）で `intent=register` の場合、pending は forget されず session に残る。ただし当該ユーザーは既存ユーザーとしてログインするだけで promote 契機が無く、logout（session invalidate）で消える。設計が列挙した 3 箇所（register 開始 / login 開始 / register 拒否・成功）は実装済み。**Warning 相当の残置**として報告に記載（設計非要求のため本タスクでは追加しない） |
| `billing.index` の `continueUrl` | 契約成立済み org が後日 `/billing` を開いた際に、過去の gate 由来 return_to が残っていると復帰 CTA が 1 回だけ出得る。これは設計が明記した aigenba parity 条件（`grantsAccess()` かつ peek 非 null で 1 回限り消費）そのもので、逸脱ではない |
| PHPStan level 10 | `composer phpstan` 691 files [OK]。`phpstan.neon` は main と差分なし（level 10 / baseline なし）、差分に `@phpstan-ignore` の追加なし = 禁止事項 #2 非抵触 |
| `Pricing.svelte` FAQ の「Free プラン」文言 | ページ本文の copy に旧プラン名 `Free` が残る（`tests/js/pages/Pricing.test.ts:94` が参照）。P7 の責務外（copy は P6/P8b 所管）のため本タスクでは変更せず、乖離候補として報告のみ |
