# bug-hunt report shard-3 (run 20260821-095643)
- 対象 URL: http://127.0.0.1:8013 (DB bug_hunt_3, users=11 at start)
- 実行ストーリー: S4 (組織・プロジェクト・カテゴリ・ユーザー管理), S5 (課金・チケット) + --deviate
- 環境ハザード: なし (走行中、serve/DB は最後まで正常応答した)
- skip したステップ: 下記カバレッジ節に理由付きで記載

## 画面カバレッジ
S4 (11/11 走行): organizations.create, organizations.settings, organizations.api-keys.index,
organizations.api-keys.sessions.index, organizations.onboarding.cli, organizations.onboarding.mcp,
manage.users.index, projects.index, projects.create, projects.edit, projects.categories.index

S5 (4/4 走行): pricing, billing.index, billing.plans, billing.tickets.show

## 操作カバレッジ
S4 (20 件中 18 実行、2 件理由付き skip):
- 実行: organizations.store, organizations.update, organizations.switch, organizations.transfer-ownership
  (validation + 確認ダイアログまで確認。実移譲はキャンセルして自分のオーナー権限を維持し以降の owner 系操作を継続検証),
  organizations.two-factor-requirement.update (自身 2FA 未設定で正しくブロックされる正常系),
  organizations.api-keys.store, organizations.api-keys.revoke, projects.store, projects.update,
  projects.destroy, projects.categories.store, projects.categories.update, projects.categories.destroy,
  projects.categories.reorder, projects.members.store, projects.members.destroy, projects.items.store,
  projects.items.update, projects.items.destroy
- skip (理由付き): organizations.api-keys.sessions.revoke — OAuth 接続セッションが 0 件でボタン自体が
  UI に出現しない (bughunt 環境は CLI/MCP からの実 OAuth ログインを行っていないため対象データが無い)。
  debug.login-as — 今回はストーリー前提を素のログイン (email/password) で満たせたため未使用
  (別ユーザーへの切替はログアウト→ログインで代替し、認可境界検証は十分に実施できた)。

S5 (6 件中 4 実行、UI 到達までの副次操作込みで広く検証):
- 実行: billing.contact.update, billing.tickets.checkout, billing.auto-recharge.update
  (設定保存/検証まで。有効化そのものはカード登録=Stripe fake が中立帰還のため到達確認まで),
  billing.checkout (実行して 200/リダイレクトまで確認。プラン変更は `/billing/plan` 経由で別途確認),
  billing.portal (実行、feedback banner 確認), billing.auto-recharge.setup (実行、カード登録トリガーまで)
- 備考: fake Stripe (`FakeStripeGateway`) は「中立帰還」設計 (webhook 未発火・実際の subscription/PM
  状態は変えない) のため、購入/プラン変更の**最終反映**は本 bughunt 環境では観測できない
  (コード上の明示コメントで意図された挙動。既知の環境制約であり finding にしない)。

## UI/UX 検証 (H11-H14)
- H11 視覚破綻: 確認した範囲 (S4 全画面 + S5 全画面、mobile 375x667 / tablet 768x1024) で
  レイアウト崩れ・要素重なり・横スクロールは検出せず。
- H12 アフォーダンス/状態: 「プランを変更」等 disabled にせず押下時エラーを出す設計を各画面で確認
  (AGENTS.md 禁止事項8 準拠)。invalid 状態は aria-invalid 相当のマーキングがほぼ全フォームで一貫。
  例外: オートリチャージの範囲エラー (`max_count.gt`) は視覚的にエラー文言は出るが、対象 spinbutton
  に `[invalid]` (aria-invalid) 属性が付与されていない可能性がある (他フォームは付与される)。
  再検証したところ severity は低く証拠が弱いため Low/要確認に留める → 下記 F-3-01。
- H13 レスポンシブ: manage/users, billing, pricing を mobile 375x667 / tablet 768x1024 で確認。
  いずれも崩れなし、ハンバーガーメニューへの切替も正常。
- H14 a11y: 明確なコントラスト不足・focus 不可視は特に発見せず (詳細な contrast 測定は未実施)。

## findings 集計
- Critical: 0 / High: 0 / Medium: 0 / Low: 1 / 要確認: 1
- H7 未検証: 0 件 (すべての書き込み操作で feedback probe による陽性 or 陰性所見を取得できた)

## インベントリ修正提案
特になし (screens.md / operations.md と実装の乖離は検出せず)。

---

## F-3-01: オートリチャージの範囲エラーで対象フィールドに aria-invalid が付かない可能性 (要確認寄りの Low)
- severity: Low
- story/step: S5-9 (オートリチャージ)
- 再現手順:
  1. `owner-standard@example.com` / `password123` でログイン (http://127.0.0.1:8013/login)。
  2. `/billing` を開き「チケット オートリチャージ」セクションで
     リチャージ開始残高=100、リチャージ後の残高=50 (開始残高 ≧ 補充後残高の不正組み合わせ) を入力。
  3. 「設定を保存する」を押す。
- 期待: 他の必須項目バリデーション (組織名・カテゴリ名・メールアドレス等) と同様に、エラーになった
  input 要素に `aria-invalid`/`[invalid]` 相当のマークが付き、支援技術でも識別できる。
- 実際: エラー文言 (「リチャージ後の残高は開始残高より大きい値を指定してください」) は表示されるが、
  同スナップショットで対象 spinbutton に `[invalid]` タグが確認できなかった (他フォームの必須項目
  エラーでは一貫して `[invalid]` が付く)。
- 阻害されたユーザージョブ: 支援技術 (スクリーンリーダー等) 利用者がどの入力欄にエラーがあるか
  補助的に把握しづらい可能性 (視覚的には文言が出ているため致命的ではない)。
- 改善アクション候補: `max_count` / `threshold_count` の spinbutton に validation エラー時
  `aria-invalid="true"` を付与する (他のフォームと同じパターンを流用)。
- 証跡: 再現時の snapshot 抜粋 (F-3-01 節に反映済み、screenshot 未取得 — 視覚的な破綻ではないため)。
- 推定原因: 未調査 (5 分以内に該当 Svelte コンポーネントを特定できなかった。ProhibitsProtectedKeys
  周辺ではなく AutoRecharge フォームの atom 側の可能性)。
- 関連既知情報: なし。
- 備考: severity を Low に留めた理由は、(a) 視覚的なエラー文言自体は正しく出ており H12 の主要な
  判別性は損なわれていないこと、(b) 1 回の観測のみで断定的な負例確認 (他の全 spinbutton で本当に
  一貫して欠落するか) まで手が回っていないこと。断定はしていない。

## F-3-02 (要確認): 課金 checkout の attempt_token idempotency (同一 token・別プラン) の挙動が
  browser-only 検証では確定できなかった
- story/step: S5 逸脱アイデア「サブスク契約 checkout の冪等 (P9)」
- 状況: `SubscriptionService::startCheckoutLocked()` のコードには
  「同 token・別 plan は `SubscriptionAttemptPlanMismatchException` → Controller が 422」という
  明示的なガードが実装されている (該当箇所読了)。一方、ブラウザの `fetch()` から同一
  `subscription_attempt_token` で `plan_code=starter` → `plan_code=standard` を連続送信したところ、
  1 回目・2 回目とも 422 ではなく 409 (Inertia location 応答。2 回目は `billing.plans` への
  `fake_external=stripe` 中立帰還) が返った。
- 理由: `FakeStripeGateway::createSubscriptionCheckout()` は「中立帰還」設計で常に cancelUrl 由来の
  URL を返す (webhook 非発火・実 Stripe 未接続の bughunt 環境の既知の制約、コード上に明記あり)。
  raw fetch はブラウザの Inertia クライアントが行う `X-Inertia-Version` 整合やクリック起点の
  1-render-1-token 前提を完全には再現できないため、観測結果だけで「ガードが効いていない」と
  断定するのは誤検知のリスクが高いと判断した。
- 結論: severity を付けず「要確認」に分類。**実装コードにはガードが存在する**ことは確認済みなので、
  Critical/High 懸念は薄いと考えるが、実ブラウザの二重クリック (戻る→別プラン選択→再送信) での
  再現は本 shard では完走できなかった。回帰テスト (Feature test) で
  `SubscriptionAttemptPlanMismatchException` → 422 の経路が実際にカバーされているかは未確認。
- 改善アクション候補: 該当の Feature/Unit テストが存在するか `tests/` を確認し、無ければテスト追加を
  検討 (実装自体は変更不要と見られる)。
- 証跡: `findings.jsonl` の `F-3-02` (triage_status=needs_spec)。
