全体判定: **CHANGES_REQUESTED**

前提: コマンド実行禁止の条件に従い、提示された設計文のみを根拠にレビューしています。実コード・ドナー実装との差分確認までは行っていません。

**1. 使命との整合性**
- [Warning] LP と料金表の刷新、402 からの購入導線追加は North Star に素直に沿っていますが、`cheapestPlanAmountJpy` を「有効プラン最安月額」で出すと `free` がある以上ほぼ `¥0` になり、実際にはチケット消費が必要な体験と訴求がずれる可能性があります。  
  修正提案: `lowestBasePlanAmountJpy` と `signupGrantTickets` を分けて見せるか、「無料開始 + チケット制」の文言を Hero/料金 CTA/JSON-LD で一貫させてください。

**2. 禁止事項違反**
- [Warning] 設計上は `response()->json()` 直書き回避、disabled 禁止、Prism 直呼びなしで整合しています。ただし `POST /purchase-tickets/checkout` の Stripe 遷移を `Inertia::location` に寄せるなら、エラー系も含めて Inertia 応答へ統一しないと実装時に例外的 JSON を生やしやすいです。  
  修正提案: 成功は `Inertia::location($checkoutUrl)`、失敗は `back()->withErrors(...)` / `back()->with(...)` に固定する方針を詳細設計で明文化してください。
- [Suggestion] `InquirySource` 追加はよいですが、`pricing` を増やす以上、既存 `/contact` の source バリデーションと集計系の列挙漏れを同時に確認対象へ入れておくべきです。

**3. 実現可能性**
- [Critical] `attempt_token` 冪等だけでは「同一画面の再送」は防げても、「別タブ」「リロード後の新 token」「二重クリックで別 request」が残ります。設計文自身が resume window / pending 再利用を外すと言っているため、同一 org で複数 Checkout Session を合法的に作れてしまい、「二重課金ゼロ」という期待効果を満たせません。Webhook/台帳 idempotency は“同じ session の再送防止”であって、“複数 session 作成”は防げません。  
  修正提案: `organization_id` 単位で active pending を再利用する仕組みを最低限戻してください。少なくとも `organization_id + purpose + status=pending` の一意運用、または `organization_id + ticket_count` の短い resume window を設け、既存 pending があれば checkout URL を replay する設計にしてください。
- [Warning] `amount_total === ticket_count × unit_amount` 照合は、Stripe 側で税・割引・promotion code・shipping が有効になると簡単に壊れます。概念上は fail-closed で正しいですが、運用設定ドリフトで購入不能になりやすいです。  
  修正提案: Checkout 作成時に tax/discount/shipping/promo を明示的に無効化するか、照合対象を `amount_subtotal` に限定し、通貨も DB pin 値と合わせて検証してください。
- [Warning] `ticket_checkout_sessions` が `pending/completed` の 2 状態だけだと、期限切れ・放棄・Stripe 側 expired の扱いが曖昧です。v1 でも stale pending が UX と運用を濁します。  
  修正提案: `expired/cancelled` を追加するか、少なくとも「pending を何分で再生成可にするか」を仕様化してください。

**4. 期待効果の妥当性**
- [Warning] LP/料金表の改善で獲得・転換が良くなる仮説は合理的です。ただし「料金の透明性」は、`free` と `ticket 別売り` の関係を誤解なく見せられた場合に限ります。`¥0〜` だけが前面に出ると逆に期待値ギャップを作ります。  
  修正提案: FAQ と料金注記に「AI解析1枚 / 動画レンダ3枚」「初回10枚」「追加は段階単価」を常に同じ粒度で併記してください。
- [Suggestion] 402 導線の効果は高いので、設計段階で「不足時に必要枚数を示す」まで入れると、購入判断の摩擦をさらに下げられます。

**5. リスク**
- [Warning] 402 から `/purchase-tickets` へ全員を誘導すると、`manageBilling` 権限のない一般メンバーが購入画面で詰まる恐れがあります。技術的には問題なくても UX 上は行き止まりの再発です。  
  修正提案: 権限なしユーザーには「管理者に購入を依頼」の分岐を出すか、CTA を role-aware にしてください。
- [Warning] `require-active-subscription` の allowlist を「billing 系 group」へ広げると、今回必要な購入導線以上の billing 画面まで未契約 org に開く副作用がありえます。  
  修正提案: allowlist は route 名ベースで最小化し、今回必要な `purchase-tickets` 系だけを明示許可してください。

**6. スコープの適切さ**
- [Suggestion] LP・料金表・スポット購入を同時に入れるスコープ自体は妥当です。North Star への入口から継続導線まで一本で閉じており、分割しすぎるより良いです。
- [Warning] ただし価格改定（Standard `¥1,980→¥4,980`）はマーケ改善と課金導線追加とは別のプロダクト判断です。設計上は一緒に書かれていますが、レビュー観点と検証観点が増えます。  
  修正提案: 実装 PR では「導線実装」と「価格改定」を論理的に分けるか、少なくともテスト観点を分離してください。

**7. 型安全性**
- [Warning] DTO 採用の方向は正しいですが、Inertia props が増える割に array shape の固定方法がまだ粗いです。`contactUrl` の nullable、`contactIsExternal` の整合、`ticketTiers`/`plans` の exact shape が曖昧なままだと PHPStan level 10 と TS 側の契約がずれやすいです。  
  修正提案: DTO ごとに `@phpstan-type` か厳密な `array{...}` を持つ `toArray()` を定義し、TS 側も同名 interface を exact に合わせて、Feature テストで props key/type を固定してください。
- [Suggestion] `Billing/PlanDto` を pricing 表示専用に使うなら、将来の billing 内部 DTO と責務が混ざらないよう `Marketing/PricingPlanDto` に寄せる方が保守しやすいです。

総評として、**方向性はかなり良く、使命整合も高い**です。ただし現状のままでは **スポット購入の冪等設計が「同一 token 再送」にしか効かず、二重課金防止の主張を支えられません**。ここを設計レベルで補強できれば、APPROVED に近い内容です。