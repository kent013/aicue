**レビュー結果**

- 依頼内容（`seeder + PHPテストのみ`）と提示 diff・テスト結果を前提に、設計一致性 / 正確性 / PHPStan / テスト網羅 / ドメイン規約を確認しました。
- 結論は **APPROVED** です（Critical/Warning なし、Suggestion のみ軽微）。

**`database/seeders/ManualTestSeeder.php`**
- **判定: OK**
- **設計一致性**: `createOrganization()` で `currentPrice(PlanPriceKind::Base) !== null` のときのみ `plan_code` を設定し、同時に `attachFakeActiveSubscription()` を付与しており、施策1に一致。
- **ドメイン規約**: プラン code 文字列で能力分岐せず、「Price を持つか」という値で判定できているため準拠。
- **正確性/冪等性**: `attachFakeActiveSubscription()` は `subscription('default') !== null` で早期 return し重複作成を防止。`plan_code 非 null ⇔ active subscription` の seed 側整合を満たす。
- **Suggestion**: `subscriptions()->create([...])` の最小カラム方針は妥当。将来 Cashier 側の必須列が増える変更に備え、Seeder の意図（テスト専用最小列）を維持する運用注記があるとさらに安全。

**`tests/Feature/Database/ManualTestSeederTest.php`**
- **判定: OK**
- **設計一致性**: 有償/Free を `currentPrice(Base)` で分岐し、期待値を両側で検証（有償: `plan_code + active subscription`、Free: `plan_code null + subscription 無し`）できている。
- **PHPStan適合**: `first()`→`firstOrFail()` で null narrow が改善され、level 10 方針に整合。
- **正確性**: `BillingAccess::hasActiveAccess($organization)` を両 tier で `true` 検証しており、F-C3 の再発防止に直結。
- **Suggestion**: 既に十分だが、将来 `Plan` が増える前提なら「有償が最低1件存在」確認をこのテスト内でも明示すると失敗理由がより直感的になる。

**`tests/Feature/Billing/SeededFreePlanBillingAccessTest.php`**
- **判定: OK**
- **設計一致性**: 施策3どおり、Free 組織の owner/admin/member が `/projects` 到達（`assertOk` + `Projects/Index`）を固定できている。
- **回帰再現性**: 旧不具合（302→billing）を検出可能なアサーション構成で、回帰テストとして有効。
- **有償側補完**: 同ファイル内で有償組織の `plan_code` と active subscription を確認しており、対称性がある。
- **PHPStan観点**: `seededFreePlan()` の `?? throw` により null 安全は良好。
- **Suggestion**: `paid` の取得も `?? throw` にすると、失敗時メッセージがより明示的になる（現状でも `expect($paid)->not->toBeNull()` で実害はなし）。

**`tests/Feature/Billing/PlanSeederPriceInvariantTest.php`**
- **判定: OK**
- **設計一致性**: 施策4どおり、fixture 不変条件（`standard` は base Price あり、`free` は Price なし）を独立テストとして固定できている。
- **ドリフト耐性**: 施策2の判定式が将来変化しても、seedデータ不変条件の崩れを別軸で検知可能。
- **ドメイン規約**: ここでの code 参照は fixture 仕様検証用途で妥当（本番能力分岐ではない）。

**指摘一覧**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `ManualTestSeeder.php`: Cashier 必須列変更時の保守メモを追加すると長期運用が安定。
  - `SeededFreePlanBillingAccessTest.php`: 有償 plan 取得を `?? throw` にすると失敗時可読性が上がる。

**全体判定**
- **APPROVED**