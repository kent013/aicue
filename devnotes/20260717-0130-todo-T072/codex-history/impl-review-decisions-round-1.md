# 対応マトリクス: impl-review Round 1（CHANGES_REQUESTED / Critical 1・Warning 1・Suggestion 1）

## [Critical] `StripeWebhookProcessor` が marker を claim せず `grantSignupGrant()` を直呼び（真実源との整合が崩れる）
- 判断: **対応する**（指摘が正しい。**設計の内部矛盾**が実装時に露見した）
- 事実確認（自分で検証）:
  - P1 設計は `CreateNewUser` に移行期規約（claim できた時のみ grant）を**適用**する一方、webhook は
    「**引数適合のみ**。claim 追加は P6」としていた。実装者は設計どおりに書いており、実装ミスではない。
  - しかし **登録経由でない org（`Organizations/Create` の追加組織）は登録時 grant を受けない**ため、
    その org の初回契約で `invoice.paid` が**初回付与**になり、**marker が NULL のまま**残る。
  - P3 で activate-personal が配線された後、その org が解約 → personal 有効化すると
    `claimSignupGrantMarker` が成功して **`granted=true` を返すのに、ledger の org スコープ UNIQUE が実 insert を止める**
    → **残高は動かないのに「付与した」と応答する**（ユーザーに見える嘘）。
- 対応: **P1 で webhook にも移行期規約を適用**（`PersonalPlanService` を DI し、claim できたときのみ grant）。
  **金銭の結果は不変**（ledger UNIQUE で元から冪等）で、marker が整合するだけ。
- 設計側も訂正: P1 の webhook 行を「移行期規約を適用」へ / P1 のリスク行「P1〜P5 は marker を立てない」を**解消**へ /
  P6 の「(b) paid webhook に claim+grant ブロック追加」を「**P1 で適用済み**」へ。
  なお **P6 で付与契機が `customer.subscription.created` へ移る（D29）ため、この `invoice.paid` 側 claim は P6 で退役する**。

## [Warning] `SignupGrantOncePerOrgTest` が上記の不整合を「正」として固定している
- 判断: **対応する**（指摘どおり。当該テストが `granted=true`（= marker 未更新）を期待して不整合を固定していた）
- 対応: 逆順テストの期待を **`granted=false` + `webhook 時点で signup_tickets_granted_at が非 null`** へ更新。
  併せて **新規テスト**を追加: 「登録経由でない組織の初回契約（paid webhook）でもマーカーが立つ
  （付与実績と真実源が一致する）」（= 今回の Critical の直接の回帰テスト）。

## [Suggestion] `MembershipWriteLockInventoryTest` に `laratrust_team_id` 書換の検査も足すと堅い
- 判断: **見送る**（v2 原則: 設計に無いものを足さない。本 PR のスコープは P1 のプラン基盤であり、
  arch guard の拡張は別途 test テーマの TODO で扱うのが筋）。
- なお Codex は当該テストの変更自体を「妥当・guard 緩和には見えない」と APPROVE 判定している。
  負のコントロール（書き込み API 注入 → fail / 除去 → pass / probe 残留なし）は**実行検証済み**。
