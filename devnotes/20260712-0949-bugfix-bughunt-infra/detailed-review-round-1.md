ありがとうございます。提示いただいた詳細設計は、全体として **方向性は妥当** で、特に「fail-secure の三重ガード」「A→B→C の依存順」「既存挙動を崩さず抽象化する方針」が良いです。  
一方で、実装前に潰しておくべき論点がいくつかあります。

**全体判定: CHANGES_REQUESTED**

---

**A1 fake externals capability flag 導入**  
判定: **APPROVE**
- [Suggestion] `ProductionEnvGuard` の violation 文言は既存トーンと合わせて environment 条件を明示（`in production`）しておくと運用ログで読みやすい。
- [Suggestion] `config/testing.php` 新設に伴い、`config:cache` 前提の運用手順に `TESTING_FAKE_EXTERNALS` を明示追記すると事故予防になる。

---

**A2 サブスク checkout/portal の gateway 抽象化**  
判定: **REQUEST_CHANGES**
- [Warning] `CashierSubscriptionCheckoutGateway` のコード例に `PortalConfigurationSpec` の `use` 記載が抜けています（現行 Controller から移すため必要）。  
  **修正案**: `use App\Services\Billing\PortalConfigurationSpec;` を明示し、PHPStan で未解決参照を防ぐ。
- [Warning] `BillingController::checkout()` の戻り型が `SymfonyResponse|RedirectResponse` のままですが、実際は `Inertia::location` か `back()` で成立するため問題はない一方、抽象化後は型意図をより明確にした方が保守性が上がります。  
  **修正案**: 既存維持でも可だが、docblock に「`back()` 分岐のため RedirectResponse を含む」旨を追記。
- [Suggestion] `ExternalBillingRedirect` で URL 妥当性（`filter_var(..., FILTER_VALIDATE_URL)` 相当）まで見るかは設計判断。内部 DTO なので必須ではないが、将来 fake 実装拡張時の誤値混入を早期検知できる。

---

**A3 fake 実装と条件付き bind**  
判定: **REQUEST_CHANGES**
- [Critical] `FakeTicketCheckoutGateway` の `sessionId` 生成が `idempotencyKey` 文字列をほぼ生で使っており、文字種/長さ次第で downstream 制約に触れる可能性があります。`:` 除去だけでは不十分。  
  **修正案**: `hash('sha256', $idempotencyKey)` の先頭 N 桁で固定長トークン化（例: 32 桁）し、`cs_bughuntfake_{token}` を生成。
- [Warning] `FakeExternalsServiceProvider` サンプルに未使用 import（`CashierTicketCheckoutGateway`）が残っています。  
  **修正案**: 未使用 `use` を削除（Pint/静的解析ノイズ防止）。
- [Warning] `FakeExternalUrl::neutralReturn()` は文字列結合のみのため、`$appUrl` が空文字の場合の防御がありません。  
  **修正案**: `Assert::stringNotEmpty($appUrl)` を追加し、契約違反を即 fail-fast。
- [Suggestion] provider テストで `register()` 再実行時の container state 汚染を避けるため、テストごとに app refresh を明示するとより堅牢。

---

**B1 BughuntBillingSeeder**  
判定: **REQUEST_CHANGES**
- [Critical] `paidPlanCodes()` の `pluck('code')->all()` は PHPStan level 10 で `list<mixed>` 扱いになりやすく、`whereIn` 入力の型保証が弱いです。  
  **修正案**: `->map(fn (Plan $plan): string => $plan->code)->values()->all()` に変更して `list<string>` を実型でも保証。
- [Warning] `run(TicketLedgerService $tickets): void` の method injection は Seeder でも動作しますが、プロジェクト慣習次第で可読性が割れるポイント。  
  **修正案**: このままでも可。懸念があるなら `app(TicketLedgerService::class)` に統一（ただし型安全性は method injection 優位）。
- [Warning] `ensureActiveSubscription()` で既存行が `default` 以外のみ存在するケースは新規 `default` を作るため正しいが、`stripe_id` の決定論値衝突を避ける説明を doc に一行足すと運用時に安心。  
  **修正案**: 「`sub_bughunt_{orgId}` は org 単位一意」旨をコメント追記。
- [Suggestion] `BughuntOAuthSeederGuardTest` は A1 点火影響の回帰固定として非常に良い。加えて「`fake_externals=true` でも env=testing なら no-op」を明示1ケース追加すると境界がさらに強固。

---

**C1 AdminUserSeeder bughunt.local 対応**  
判定: **APPROVE**
- [Suggestion] `shouldSeed()` の意図（local は無条件、bughunt.local は DB guard 必須）を class doc にも反映すると将来差分レビューが楽になる。

---

**C2 Filament アセット publish 冪等化**  
判定: **REQUEST_CHANGES**
- [Warning] `filament_version_from_lock()` が空文字を返す場合、marker による skip 最適化が効かず毎回判定コストが増える（機能上は問題なし）。  
  **修正案**: 空文字時のログを debug 出力（任意）し、原因調査を容易化。
- [Warning] 必須アセットパス固定はメジャー更新時に壊れる前提なので妥当ですが、`die` メッセージに「現在見ているパス」を既に含めているのは良い。加えて「filament:assets の実行ログ確認」を案内すると運用しやすい。  
  **修正案**: エラーメッセージを1行補強。
- [Suggestion] `self-test` 非接触方針は妥当。将来の退行防止として `cmd_assets_check` に filament marker 整合チェックを足す余地あり（今回必須ではない）。

---

**総評（重点リスク）**
- 最大リスクは **A3 の fake sessionId 生成** と **B1 の list<string> 型保証不足** です。  
- この2点は品質ゲート（PHPStan10/将来互換）に直結するため、先に設計へ反映してから実装へ進むのを推奨します。  
- それ以外は、セキュリティ不変条件・DTO/JsonResource 方針・Inertia/API 境界とも整合しています。  

必要ならこのまま、次ラウンド用に「修正反映済みの設計差分（そのまま貼れる追記案）」を施策ごとに短く作成します。