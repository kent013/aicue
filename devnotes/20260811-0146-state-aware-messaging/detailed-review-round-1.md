**施策 1: REQUEST_CHANGES**

[Warning] `pending_checkout` / `expired_checkout` の Feature テスト fixture が不明確です。  
`createOrganizationWithOwner()` は既定で `free_plan_code='personal'` を付けるため、そのまま `BillingCheckoutSession` を作ると `BillingAccess::state()` は `ActiveFreePlan` で先に返り、pending / expired に到達しません。

修正案: 新規テスト 4 / 5 は明示的に次で作る。

```php
[$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
BillingCheckoutSession::factory()->for($organization)->create();
```

[Warning] `has_billing_access` を「残さない」方針をテストで固定していません。  
実装者が `billing_state` と `has_billing_access` を並走させても、現行テスト計画では通る可能性があります。

修正案: Feature の代表ケースで `dashboard.billing.has_billing_access` が存在しないことも assert してください。Inertia Assert の `missing()` が使えるならそれで十分です。

[Suggestion] PHPDoc の `billing_state: string` は動きますが、型精度が弱いです。  
PHPStan が許すなら `value-of<OnboardingBillingState>` を使うと、DTO の意図がより明確になります。

---

**施策 2: APPROVE**

設計方針は妥当です。`OnboardingBillingState` を TS union に通し、`satisfies Record<BillingStateValue, ...>` で分岐漏れを型で落とす構成は、この finding の範囲に対して過不足がありません。

[Suggestion] `BILLING_CALLOUTS` の `no_subscription` / `pending_checkout` の CTA がどちらも `/onboarding/checkout` である点は妥当ですが、Browser / Feature のどちらかで「非 manageBilling が billing-required に流れる」ことを維持する現在の計画は必ず入れてください。フロントで権限分岐しない判断の根拠になります。

---

**施策 3: APPROVE**

429 だけを特別扱いし、`PasskeyOutcome` を変えない判断は適切です。呼び出し側を増やさず、サーバ側 throttle に触れないため、前提条件にも合っています。

[Warning] 「保証しないもの」に書き漏れがあります。  
この施策は 429 到達後の文言を改善するだけで、連打そのもの、in-flight 多重送信、rate limit 到達を防ぐものではありません。

修正案: 「passkey ボタンの連打抑止・cooldown 表示・429 発生防止は保証しない。閾値と limiter は変更しない」を保証しないものへ追加してください。

---

**施策 4: REQUEST_CHANGES**

[Warning] 2 本目の Architecture テストの closure 型が PHPStan level 10 で落ちる可能性があります。

```php
expect(fn (): array => TsUnionValues::extract(...))
```

`array` の value type 未指定として検出される可能性が高いです。

修正案: 戻り型を消して PHPStan に推論させるか、PHPDoc 付きの closure にしてください。

```php
expect(static fn () => TsUnionValues::extract('resources/js/types/billing.ts', 'NoSuchUnionName'))
    ->toThrow(RuntimeException::class, 'degenerate PASS');
```

[Suggestion] この gate は直接 literal union を regex 抽出する前提なので、`billing.ts` を `const array` 由来の union へ変えた場合は helper 更新が必要です。テストは fail-closed なので大きな問題ではありませんが、「保証しないもの」に入れると誇張が避けられます。

---

**テスト・検証計画**

[Warning] 検証コマンドが AGENTS.md の canonical list と同期していません。  
設計書の G には package 系が抜けています。

修正案: 少なくとも以下を追加してください。

```bash
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

`composer test:browser` を追加している点は UI 変更として妥当です。

---

**全体判定: CHANGES_REQUESTED**

設計の方向性は良いです。課金ゲート判定・throttle 閾値に触れず、既存 enum を正本にして Inertia props へ状態を渡す判断も妥当です。

ただし、テスト fixture の作り方、旧 prop 非残存の固定、PHPStan level 10 対応、保証しないものの書き漏れ、検証コマンドの不足は修正が必要です。これらを直せば APPROVED に近い内容です。