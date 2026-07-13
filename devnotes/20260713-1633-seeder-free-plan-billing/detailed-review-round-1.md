**施策1: `ManualTestSeeder::createOrganization()` 書き換え**  
判定: **REQUEST_CHANGES**

- [Critical] 冪等性の説明が不足しています。`run()` 側の「最初の Owner 存在で skip」前提なら通常は再seedで重複しませんが、将来 guard 条件変更・部分実行・手動呼び出し時に `subscriptions()->create()` が重複行を生む設計です。  
  修正案: `attachFakeActiveSubscription()` は `where('type','default')->first()` を確認し、未存在時のみ作成（または `firstOrCreate` 相当）にしてください。少なくともコメントで「idempotency relies on run-guard」ではなく、メソッド単体で冪等にするのが安全です。

- [Warning] 有償判定を `currentPrice(Base)!==null` に寄せる方針はドメイン規約（code分岐禁止）に整合しますが、「Base価格が未投入の有償プラン」を free 扱いに誤判定する運用リスクがあります。  
  修正案: 設計書に「seedデータ不変条件（有償プランは必ず current base price を持つ）」を明記し、必要なら PlanSeeder 側の整合テストを追加してください。

- [Suggestion] `stripe_id` の生成規則は既存 helper (`sub_test_...`) と完全一致しなくても問題ありませんが、テストデータ命名を揃えると保守性が上がります（例: `sub_seed_` を共通化）。

---

**施策2: `ManualTestSeederTest` 更新**  
判定: **APPROVE**

- [Warning] `Plan::currentPrice()` は毎回クエリになるため、ループ内で多用するとテストが冗長化しやすいです（機能的には問題なし）。  
  修正案: ループ内先頭で `isPaid = $plan->currentPrice(PlanPriceKind::Base) !== null` を変数化し、分岐と期待値を一元化すると可読性・保守性が向上します。

- [Suggestion] `hasActiveAccess` まで検証するのは良いです。加えて free 側で「`default` subscription が存在しない」を明示すると、今回の根本原因に対する回帰耐性がさらに高まります（設計には含まれているので実装で落とさないよう注意）。

---

**施策3: `SeededFreePlanBillingAccessTest` 新規追加**  
判定: **APPROVE**

- [Warning] `/projects` 到達確認は有効ですが、リダイレクト非発生の検証を `assertOk` のみで済ませると、将来 200 だが別画面になるケースを取りこぼす可能性があります。  
  修正案: 可能なら Inertia レスポンスのコンポーネント名や想定 props も1点検証してください（UI変更なしでもルーティング回帰を検出可能）。

- [Suggestion] dataset で owner/admin/member を回す方針は妥当です。役割付与が Laratrust team 文脈を正しく使っていること（`laratrust_team_id` 明示）を既存ヘルパーで担保できているかだけ確認してください。

---

**重点観点への回答**

- (a) 冪等性: **現状設計のままでも run-guard 前提なら概ね成立**。ただしメソッド単体冪等でないため **改善推奨（実質 Warning〜Critical 寄り）**。  
- (b) `currentPrice(Base)` 判定: **方針として妥当**（値ベースで規約適合）。ただし seed整合不変条件の明文化が必要。  
- (c) 既存テスト是正の「上書き禁止」抵触: **抵触しません**。これは誤った挙動固定テストの修正で、後方互換並走を残さない原則にも合致。  
- (d) fail→pass 回帰機能: **概ね機能する設計**。特に施策3が実動線を押さえており良い。より確実にするなら free 組織の subscription 不在検証を強化。

---

**全体判定**  
**CHANGES_REQUESTED**

最小修正は1点です: **`attachFakeActiveSubscription()` を冪等化**してください。  
それが入れば、他は方針・整合性・PHPStan適合性・回帰設計ともに高品質で、承認可能です。