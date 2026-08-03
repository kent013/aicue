全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] 方向性は妥当です。課金導線そのものは North Star の中核機能ではありませんが、「直前操作と矛盾する状態を再提示しない」ことは「思考ゼロ」を支える土台として合理的です。

**2. 禁止事項違反**
- [Suggestion] 設計文書上、明確な禁止事項違反は見当たりません。`response()->json()` を増やさず DTO/Inertia を維持し、`redirect()->intended()` も使わない前提は適切です。

**3. 実現可能性**
- [Critical] `/billing?...` に 303 を 1 段追加すると、既存 flash、特に `?portal` 系で既に積まれている error flash をその場で失う危険があります。設計には「error flash を取りこぼさない」とありますが、保持方法が未規定です。修正提案: landing redirect では `billing_feedback_kind` 以外の既存 flash を `keep([...])` か必要最小限の `reflash()` で明示的に次リクエストへ持ち越す方針を追記してください。テストも props だけでなく flash 継続を直接検証すべきです。
- [Critical] canonical URL を常に素の `/billing` にすると、`return_to` など billing 画面の別契約の query state を消す可能性があります。本文で `resolveOnboardingContinue()` の順序に触れていますが、順序だけでは state は保存されません。修正提案: 「feedback 専用 query だけ除去し、許可された他 query は保持する」方針を明記するか、Stripe 戻り URL と共存しないことを明文化して feature test で固定してください。
- [Suggestion] Laravel 12 + Svelte 5 + Inertia.js での実装自体は十分可能です。同一 controller の既存着地パターンへ揃える判断も妥当です。

**4. 期待効果の妥当性**
- [Warning] 「履歴・ブックマークのいずれからも古いバナーが復活しない」はやや強すぎます。303 で通常の履歴・通常のブックマーク汚染は防げますが、元の query 付き URL の手入力や外部保存までは防げません。修正提案: 効果表現を「通常のリロード・履歴・ブックマーク起点での再発を構造的に防ぐ」に狭め、直接 query 再入力は非スコープと明記してください。
- [Suggestion] `replayed` / `retry` を query から flash へ寄せる整理は、期待効果に対して過不足ありません。

**5. リスク**
- [Warning] `/billing` に着地処理が 3 系統ある以上、複数 query が同時に来たときの優先順位が未定義だと将来の回帰源になります。修正提案: `setup_session_id` / `session_id|portal` / `highlight` 系の優先順位と相互排他前提を 1 箇所に明記し、競合ケースを少なくとも 1 本テストで固定してください。
- [Suggestion] bfcache については今回の finding 本体ではありませんが、「one-shot」は UX 契約として誤解されやすいので、docs では「サーバ再主張を防ぐ one-shot」であることを明示した方が安全です。

**6. スコープの適切さ**
- [Suggestion] スコープは概ね適切です。`/purchase-tickets` を切り離した判断も、結合度の説明があり妥当です。
- [Suggestion] docs 更新は「one-shot の担保方式」だけでなく、「他 flash を落とさない」「他 query 契約を壊さない」という副作用境界まで固定した方が再発防止に効きます。

**7. 型安全性**
- [Warning] flash に載せる値をスカラ 1 個に絞る方針はよいですが、復元経路が未記述です。`string|null` をそのまま DTO へ流すと DTO 境界が緩みます。修正提案: session から取り出した値は `BillingFeedbackKind::tryFrom((string) $value)` で enum に復元し、`null` は feedback なしとして落とし、DTO は `fromKind(BillingFeedbackKind $kind)` だけを受ける形にしてください。
- [Suggestion] `toArray()` の shape 不変を守る前提は PHPStan level 10 と相性が良いです。可能なら DTO 側で array-shape も明示するとさらに堅くなります。

基本方針は正しいですが、**追加 redirect による flash 消失**と**他 query 契約の喪失**が未解決です。そこを設計に明文化してから進めるべきです。