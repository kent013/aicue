# Round 2: Round 1 の指摘への対応と再レビュー依頼

Round 1 の指摘 5 Warning / 3 Suggestion に対する対応です。**1 件のみ反論**しています。
対応後の詳細設計で再レビューをお願いします（全体判定を明示してください）。

---

## 1. [Warning] 施策 1: pending_checkout / expired_checkout の fixture — **対応した**

指摘は正しく、しかも「テストが空振りしたまま緑になる」種類の穴でした。
テスト計画 A に以下の必須注意ブロックと具体コードを入れました。

> **fixture の必須注意**: `createOrganizationWithOwner()` は既定で `free_plan_code='personal'` を立てる
> (`tests/Pest.php:173-180`)。`BillingAccess::state()` は entitled subscription → `free_plan_code` の順で
> 判定するため、既定のまま `BillingCheckoutSession` を作っても **`ActiveFreePlan` で先に返り
> pending / expired に到達しない**。未契約系の state を作るテストは
> **必ず `createOrganizationWithOwner(grandfatherFreePlan: false)` を使う**。

```php
// 新規 1 (F-2-01 再現)
[$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
Project::factory()->forOrganization($organization)->create();
$this->actingAs($owner)->get('/dashboard')->assertOk()
    ->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.billing.billing_state', 'no_subscription')
        ->missing('dashboard.billing.has_billing_access'));

// 新規 4 (pending)
[$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
BillingCheckoutSession::factory()->for($organization)->create(); // 既定 = live pending

// 新規 5 (expired)
BillingCheckoutSession::factory()->for($organization)->expired()->create();
```

## 2. [Warning] 施策 1: 旧 prop 非残存の固定 — **対応した**

新規 Feature 1 本目に `->missing('dashboard.billing.has_billing_access')` を追加しました。
さらに mutation 表に **#8「`has_billing_access` を併記して並走させる変異 → この assert が赤くなること」**
を追加し、assert が実効であることを確認する手順にしました。

## 3. [Suggestion] 施策 1: `value-of<OnboardingBillingState>` — **対応した**

リポジトリに先例がありました
(`app/DataTransferObjects/Billing/BillingFeedbackDto.php:14` の
`@phpstan-type BillingFeedbackShape array{kind: value-of<BillingFeedbackKind>, message: string}`)。
`BillingSummaryData::toArray()` と `DashboardPageData::toArray()` の `@return` shape を
`billing_state: value-of<OnboardingBillingState>` に変更しました。

## 4. [Warning] 施策 4: closure 戻り型 `array` が PHPStan level 10 で落ちる — **反論します**

証拠 2 点です。

1. `phpstan.neon` の解析対象は `paths: [app, config, database, routes]` であり、
   **`tests/` は含まれていません**（`excludePaths: [vendor]`、level: 10）。
   したがって tests 配下の closure 戻り型は level 10 の検査を受けません。
2. 仮に対象だったとしても、**まったく同じ書き方**が既にリポジトリにあり CI が緑です:

```php
// tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php (現行・そのまま)
test('account.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => TsUnionValues::extract('resources/js/types/account.ts', 'NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
```

新規 gate は既存 3 つの sync gate と**同じ書式に揃える**ほうが、目録として読み比べやすいと判断しました。
この反論に異論があれば、`tests/` が解析対象に入るという反証を示してください。

## 5. [Warning] 施策 3: 「保証しないもの」の書き漏れ — **対応した**

「保証しないもの」に追加しました:

> 8. **429 の「発生」は減らない**。本施策が変えるのは 429 到達**後**の文言だけである。
>    passkey ボタンの連打抑止・cooldown 表示・in-flight 多重送信の抑制・
>    流量制限に到達しにくくすることは**一切保証しない**（limiter と閾値は 1 文字も変えない）。

## 6. [Suggestion] 施策 4: literal union の regex 抽出前提 — **対応した**

> 9. **enum ⇔ TS union gate は `export type X = "a" | "b";` の書式に依存する**。
>    `const array` 由来の派生 union へ書き換えると `TsUnionValues::extract()` は抽出できず、
>    gate は **fail-closed で赤くなる**（silent PASS にはならない）が、helper の更新が必要になる。

## 7. [Warning] 検証コマンドの canonical list との非同期 — **対応した**

§G を AGENTS.md の `VERIFICATION_COMMANDS` マーカー内の全 10 本
（`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を含む）
＋ UI 変更のための `composer test:browser` に置き換えました。
部分引用しない理由（`verification-commands-doc-sync.test.ts` が同期を強制する list である）も注記しています。

## 8. [Suggestion] 施策 2: 非 manageBilling → billing-required のテスト維持 — **維持します**

Feature 新規 3 本目がそれです。フロントで権限分岐しない判断（禁止事項 8 の思想）の
behavioral な裏付けとして削りません。

---

## 再レビュー依頼

上記の対応を踏まえ、以下を確認してください。

- 反論 4 の妥当性（`tests/` が PHPStan の解析対象に入るという反証があるか）
- fixture 修正で「テストが空振りしないこと」が実際に担保されたか
- mutation 表 #8 が旧 prop 残置を本当に検出できるか（`missing()` の意味論を含めて）
- 「保証しないもの」にまだ書き漏れがないか（誇張していないか）
- 過剰に作っていないか（思考原則 2 に照らして、削るべき施策・テストがないか）

全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
