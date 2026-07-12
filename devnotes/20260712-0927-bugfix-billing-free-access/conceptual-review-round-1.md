全体判定: **APPROVED**

1. 使命との整合性
- [Critical] なし
- [Warning] なし
- [Suggestion] `Free で試せる` を実際の業務導線まで貫通させる設計で、North Star への寄与は大きいです。受け入れ条件として「`/projects` `POST /projects` `/app` が Free 組織で通る」を明文化すると、実装後の判定がぶれません。

2. 禁止事項違反
- [Critical] なし
- [Warning] なし
- [Suggestion] DTO/TS のリネームは後方互換 alias を残さず一括で行う前提を維持してください。これは「後方互換の並走を残さない」という原則と整合します。

3. 実現可能性
- [Critical] なし
- [Warning] `plan_code === null` を free entitlement の根拠にする設計は v1 では妥当ですが、`plan_code 非 null は有償課金ライフサイクル管理下だけで使う` という暗黙契約に依存しています。将来、請求不要の特別プラン等で `plan_code` を使い始めると誤遮断になります。  
  修正提案: `PlanSeeder`・`StripeWebhookProcessor`・`docs/architecture.md` にこの不変条件を明記し、Feature/Architecture テストで固定してください。
- [Suggestion] `RequireActiveSubscription` の理由文言は middleware 内に散らさず、単一メソッドか value object に寄せると HTML redirect と JSON 402 の整合を保ちやすいです。

4. 期待効果の妥当性
- [Critical] なし
- [Warning] なし
- [Suggestion] 期待効果は合理的です。加えて「Free 組織では課金 callout は消えるが、Quota 超過やチケット不足の導線は別途残る」を受け入れ条件に入れると、entitlement と resource gate の分離が実装で崩れにくくなります。

5. リスク
- [Critical] なし
- [Warning] `JSON/XHR の 402 応答は現行メッセージを維持する` は危険です。現行文言が「有効なサブスクリプションが必要です」系なら、修正後の唯一の失敗理由である `支払い不健全` を説明できず、H1 を API/XHR 経路で温存します。  
  修正提案: browser redirect の flash と JSON 402 のメッセージを同じ意味論にそろえ、「お支払い確認ができないため一時停止中」で統一し、HTML/JSON 両経路を Feature test で固定してください。
- [Suggestion] webhook 順序逆転を fail-closed にする判断は妥当です。`plan_code set + subscription 行なし` を遮断する回帰テストは明示しておくべきです。

6. スコープの適切さ
- [Critical] なし
- [Warning] なし
- [Suggestion] スコープは適切です。F-07 の解消に必要な変更へ収束しており、F-05 や価格設計へ広げていない点は良いです。`routes/web.php` コメント更新も gate 意味論の説明に限定するのが妥当です。

7. 型安全性
- [Critical] なし
- [Warning] `has_active_subscription` → `has_billing_access` のリネームは正しいですが、Inertia payload key・TS 型・Svelte 側分岐・テストのどこかが旧名のまま残ると、PHP 側型は通っても画面で不整合が出ます。  
  修正提案: DTO を唯一の source of truth にして、Feature test で payload key を `has_billing_access` に固定し、`Dashboard.svelte` 側も旧キー参照が残らないことを JS test で保証してください。
- [Suggestion] `BillingAccess::hasActiveAccess()` の名前は残してよいですが、docblock には「subscription の有無そのものではなく billing entitlement を返す」と明記した方が将来の誤用を減らせます。

設計の方向性自体は妥当です。主な修正ポイントは、`plan_code` 依存の不変条件を明文化してテストで固定することと、HTML/XHR で失敗理由の意味論を一致させることです。