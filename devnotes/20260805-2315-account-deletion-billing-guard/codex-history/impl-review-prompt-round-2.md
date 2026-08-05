## Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の 3 件の Warning に対する対応を報告する。対応マトリクスの要旨は以下。

### [Warning] hasLiveBillingObligation の instanceof fail-open → **対応した**

想定外型を黙って読み飛ばす形をやめ、`Assert::isInstanceOf()` で fail-closed にした。差分は下記。

### [Warning] redaction 注記の出典 URL → **一部対応 (URL は書かない)**

一次情報の URL は**リポジトリにも c2c 台帳にも存在しない**ことを確認した。台帳
(feature account-deletion-billing-guard の handover / 裁定 AG-033、2026-08-05) は
「決済事業者は…と規定」という要約のみで URL を pin していない。ここで URL を推測して書くと
「確認していない一次情報を確認したかのように固定する」ことになり、設計意図 (外部仕様を鵜呑みに
しない) に反する。よって出典を台帳の該当 handover/裁定 + 確認日で明記し、
**一次情報 URL が pin されていない事実**と「数値を運用に効かせる前に一次情報を引き直して
URL と確認日をここへ追記すること」を注記した。この判断の是非も含めて評価してほしい。

### [Warning] composer test 全体の green 未確認 → **対応した (完走を確認)**

- `composer test` (全体): **3160 tests / 3158 passed / 0 failed / 2 skipped、assertions 12243**
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm test` (vitest): 124 files / 1216 tests passed
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: 10 files / 106 tests passed
- 変更後の再実行 (対象 filter): 45 passed / 0 failed

### [Suggestion] DTO が空 reasons を許す → **見送り**

唯一の呼び出し元 (`organizationsBlockingDeletion`) が `$reasons === []` を先に弾いており、
空 reasons の DTO は生成されない。追加の防御は「今必要なものだけ作る」(AGENTS.md 思考原則 2) に反する。

## Round 1 からの修正差分

```diff
diff --git a/app/Services/Billing/AccountDeletionBillingGuard.php b/app/Services/Billing/AccountDeletionBillingGuard.php
new file mode 100644
index 0000000..1170139
--- /dev/null
+++ b/app/Services/Billing/AccountDeletionBillingGuard.php
@@ -0,0 +1,85 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\Enums\Billing\SubscriptionState;
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use Illuminate\Support\Collection;
+use Webmozart\Assert\Assert;
+
+/**
+ * 退会 (アカウント削除) ガードのための **課金責務** 判定。
+ *
+ * **これは entitlement (利用可否) の判定ではない**。利用可否の唯一の窓口は BillingAccess /
+ * SubscriptionService::deriveEntitlement であり、本クラスはそれとは別の問い
+ * 「**この組織に、将来の請求を発生させうる subscription が残っているか**」に答える。
+ * 両者は一致しない (例: PastDue かつ PM 無しは entitlement 上 denied だが請求責務は残りうる)。
+ *
+ * 判定は subscriptions 行のみを入力にする **読み取り専用**。決済事業者 API は呼ばない
+ * (退会処理から Stripe を呼ばない原則。自 DB と外部サービスの二重書き込みを避ける)。
+ */
+final class AccountDeletionBillingGuard
+{
+    /**
+     * 生きた課金責務があるか。
+     *
+     *   ある := SubscriptionState::fromSubscription($sub)->grantsAccess()
+     *           (= Active / UpgradeRecovery / PastDue) かつ $sub->ends_at === null
+     *           を満たす subscription 行が 1 つでも存在する
+     *
+     * - `paused` / `canceled` / `unpaid` / `incomplete*` は Paused / Inactive に写像されて通過
+     *   (請求が発生しない or 終端)。
+     * - `ends_at !== null` (= 期末解約予約済み / 終了済み) は通過。Stripe が自動終了させるため
+     *   追加請求が発生せず、ここで止めると「解約したのに退会できない」詰みを作る。
+     */
+    public function hasLiveBillingObligation(Organization $organization): bool
+    {
+        // Cashier の relation は基底 Model 型を返すため narrowing する。想定外の型は
+        // **黙って読み飛ばさず落とす** (課金ガードで fail-open すると宙づり課金を通してしまう。
+        // モデル差し替えは Cashier::useSubscriptionModel 済みなので通常は起きない)。
+        foreach ($organization->subscriptions()->whereNull('ends_at')->get() as $subscription) {
+            Assert::isInstanceOf($subscription, Subscription::class);
+
+            if (SubscriptionState::fromSubscription($subscription)->grantsAccess()) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * Owner が 1 人も居ないのに生きた課金責務が残っている組織 (= 課金孤児)。
+     * 検知バッチ専用の読み取り経路。
+     *
+     * 入力は「Owner 不在の組織」だけ (通常 0 件の異常系集合) なので、組織ごとに
+     * subscription を引く N+1 を許容する。件数が増えたら exists subquery 化する
+     * (判断の記録は docs/architecture.md)。
+     *
+     * **入力契約**: 呼び出し側が「Owner 不在の組織」を渡す。本メソッドは Owner の有無を判定せず、
+     * 渡された集合を課金責務でフィルタするだけ (Owner 判定の責務は
+     * OrganizationMembershipService::organizationsWithoutOwner() 側)。
+     *
+     * @param  Collection<int, Organization>  $ownerlessOrganizations
+     * @return list<int> organization id のみ (組織名・メール等の PII を載せない)
+     */
+    public function orphanBillingOrganizationIds(Collection $ownerlessOrganizations): array
+    {
+        $ids = $ownerlessOrganizations
+            ->filter(fn (Organization $org): bool => $this->hasLiveBillingObligation($org))
+            ->map(function (Organization $org): int {
+                // getKey() の mixed を PHPStan L10 で narrowing (黙って (int) キャストせず
+                // 想定外の型を検出する)
+                $key = $org->getKey();
+                Assert::integer($key);
+
+                return $key;
+            })
+            ->all();
+
+        return array_values($ids);
+    }
+}
diff --git a/docs/architecture.md b/docs/architecture.md
index 8b6118f..1ae0cbb 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -599,6 +599,44 @@ ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
   再取得 1 回リトライ)。SW (`public/capture-sw.js`) は同一オリジン GET `/build/*` のみ
   stale-while-revalidate (アプリ応答・S3 は素通し)
 
+## 退会 (アカウント削除) の課金ガード (T115)
+
+- **不変条件**: 「**唯一 Owner** かつ (**他メンバーが残る** ∨ **生きた課金責務がある**) 組織」が
+  1 つでもあれば退会をブロックし、**次の一手を提示する** (押下時にエラー = 削除ボタンは
+  disabled にしない)。**通常のアプリ経路の**判定の権威は
+  `OrganizationMembershipService::deleteAccount()` のロック下再評価 (canonical 順序
+  users → organizations)。表示用の `/settings` props (`accountDeletionBlockers`) は
+  スナップショットに過ぎない
+- **「生きた課金責務」の定義**: `Services/Billing/AccountDeletionBillingGuard::hasLiveBillingObligation()`
+  = `SubscriptionState::fromSubscription()->grantsAccess()` (Active / UpgradeRecovery / PastDue)
+  **かつ `ends_at === null`**。`ends_at` 付き (期末解約予約済み) を**通す**のが要点で、ここを塞ぐと
+  「解約したのに退会できない」最長 1 課金周期の詰みが出る。`paused` / `canceled` / `unpaid` /
+  `incomplete*` も通す。**これは entitlement (利用可否) の判定ではない** (利用可否の窓口は
+  `BillingAccess` / `SubscriptionService::deriveEntitlement`)
+- **退会処理から決済事業者 API を呼ばない**原則 (自 DB と外部サービスの二重書き込みを避ける)。
+  固定しているのは `tests/Feature/Auth/AccountDeletionTest.php` の
+  「退会成功経路では決済事業者 API を呼ばない」「課金中でブロックされる経路でも決済事業者 API を
+  呼ばない (解約を代行しない)」の 2 本 (並べ替えに耐えるよう番号ではなく**テスト名**で参照する)
+- **予防 + 検知の 2 枚構成**: webhook トランザクションとの競合は排他しない
+  (subscription 行を作るのは Cashier の `WebhookController` = vendor 側で、自前 listener の
+  排他では覆えない)。検知は daily の `billing:detect-orphan-billing-organizations`
+  (Owner 不在かつ生きた課金責務が残る組織を 1 実行につき**集約して 1 回だけ** `report()`。
+  内容は件数と organization id のみ = PII 非出力。ガード導入前から存在する孤児も拾う)。
+  **監視対象**: 本コマンドの `report()`
+- **検知バッチの N+1 の判断記録**: `orphanBillingOrganizationIds()` は組織ごとに subscription を
+  引くが、入力が「Owner 不在の組織」= 通常 0 件の異常系集合のため許容する。件数が増えたら
+  exists subquery 化する
+- **決済事業者側データの運用注記**: 顧客データの消去は削除ではなく**非表示化 (redaction)** で、
+  非表示化は**作成から 90 日後のみ**・処理に**最大 30 日**を要する。**アプリからは自動化しない**
+  (退会経路から事業者 API を呼ばない原則と整合)。必要時は運用手順で実施する。
+  **外部仕様のため鵜呑みで固定しない**: 出典は c2c 台帳 feature `account-deletion-billing-guard`
+  の handover / 裁定 AG-033 (**確認日 2026-08-05**。一次情報は決済事業者 (Stripe) の公式
+  ドキュメントだが、**台帳側に一次情報の URL が pin されていない**)。数値を運用に効かせる前に
+  一次情報を引き直し、URL と確認日をここへ追記すること。事業者仕様変更時に更新する対象である
+- **決済手段の前提**: subscription Checkout は `payment_method_types` を指定せず Stripe
+  ダッシュボード設定に委ねている。**非同期決済 (コンビニ払い等) を有効化する場合、`incomplete` を
+  退会ガードで通過させている判断を再確認すること** (滞留時間が伸びるため)
+
 ## 管理メニュー (/manage/users・/projects/{project}/categories)
 
 doc/04 §4.2 の管理者専用画面 (T006)。書き込みは既存 endpoint を再利用し、GET 画面のみ新設。
```

上記対応で残課題があるか、全体判定 (APPROVED / CHANGES_REQUESTED) を返してほしい。
