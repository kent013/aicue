# Round 3: Round 2 の指摘への対応（全件対応・反論なし）

Round 2 の指摘 3 Warning / 2 Suggestion に**すべて対応**しました。反論はありません。
最終判定をお願いします。

---

## 1. [Warning] `DashboardPageData` の enum import — **対応した**

施策 1 に注記ブロックを追加しました。

> **import 必須**: `DashboardPageData.php` は現在 `OnboardingBillingState` を import していない。
> PHPDoc の型名も現在の namespace で解決されるため、
> **`use App\Enums\Billing\OnboardingBillingState;` を必ず追加する**
> （追加しないと `App\DataTransferObjects\Dashboard\OnboardingBillingState` と解釈されて
> PHPStan が未知クラスとして落ちる）。完全修飾名で書く選択肢もあるが、
> `BillingSummaryData` 側と書式を揃えるため import する。

## 2. [Warning] 新規テスト 5 の fixture に組織生成行を残す — **対応した**

```php
5. test('expired checkout org: billing_state=expired_checkout')
   [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
   BillingCheckoutSession::factory()->for($organization)->expired()->create();
   （**組織生成行を省略しない**。grandfatherFreePlan: false の取り違えがこのテストの空振りに直結する）
```

## 3. [Warning] mutation #8 は PHPDoc ではなく実出力を変える — **対応した**

mutation 表 #8 を次のように具体化しました。

> | 8 | `BillingSummaryData::toArray()` の**返り値配列に**
> `'has_billing_access' => $this->billingState->grantsAccess()` を併記して並走させる
> （**PHPDoc だけ変えても Inertia payload は変わらず `missing()` は赤くならない**。実出力を変えること）
> | Feature 新規 1 本目の `->missing(...)`（旧 prop 残置の検出） |

## 4. [Suggestion] 「外部消費者は存在しえない」の断定 — **対応した**

施策 1 のリスク節を次に書き換えました。

> → **リポジトリ内の**参照は `rg 'has_billing_access|hasBillingAccess'` で全数確認済み
> （アプリ 2 + テスト 2 ファイル + docs 2 ファイル）。dashboard props は Inertia page prop であり
> 公開 API 契約ではないため、破壊の影響はリポジトリ内に閉じると**期待**できる。
> ただし**リポジトリ外の消費者（外部 E2E スクリプト・ブラウザ拡張・運用スクリプト等）の
> 不存在は機械的には保証できない**（断定しない）。

## 5. [Suggestion] Browser テスト 2 本の統合 — **採用した**

§E を統合 1 本に書き換えました（mutation 表 #2 の参照先も更新済み）。

> **テストは 1 本に統合する**。同一 fixture・同一画面を 2 本に分けても検出力は変わらず、
> グローバルテストロック下の実行時間だけが増えるため。
>
> | `未契約 org の dashboard は「プランを選ぶ」callout を出し、旧「支払いが確認できない」文言を出さず、
> CTA でプラン選択に着地する` | `createOrganizationWithOwner(grandfatherFreePlan: false)` の owner で
> `/dashboard` を開き、同一セッション内で (1) `[data-testid="billing-callout-body"]` が
> 「プランの選択が必要」文言、(2) ページ本文に旧文言「お支払いが確認できないため」が**含まれない**、
> (3) CTA クリック → `/onboarding/checkout` に到達、の 3 点を assert する |

---

## 最終確認のお願い

- 残る Critical / Warning があるか
- 過剰に作っている施策・テストが残っていないか（思考原則 2）
- 「保証しないもの」に誇張・書き漏れがないか

**全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。**
