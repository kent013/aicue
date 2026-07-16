# 対応マトリクス: design-review Round 4（CHANGES_REQUESTED / Critical 6・Warning 4）

**重要な自己認識**: Round 4 の指摘のうち 2 件（非 active 有償契約が NoPlan へ落ちる / ticket と subscription の attempt token 混同）は
**私が Round 3 で当てたパッチが作り込んだバグ**だった。240KB の並列起草物への文字列パッチが限界に達したと判断し、
**該当セクション（P1/P2/P4/P5/P8b）を確定決定（D1-D27 + Round 4 の修正案）を埋め込んで再生成**する方式に切り替えた。

## [Critical] 非 active の有償契約が `NoPlan` へ落ち P2 の挙動不変を破る（**私のパッチによる作り込み**）
- 判断: 対応する / 対応: 提示された **5 段の解決順を正規契約として全セクションに埋め込み再生成**:
  `1. active/trialing sub → PaidSubscriptionPlan（同期ラグ）` / `2. plan_code 非 null → PaidSubscriptionPlan（entitlement は
  denied を含む = 支払い不健全は遮断）` / `3. personal + declarer あり → ActivatedPersonalPlan` /
  `4. personal + declarer なし → GrandfatheredLegacyFreePlan` / `5. それ以外 → NoPlan`。
  `plan_code 非 null + sub 不在/past_due/paused` が **P2・P4 とも遮断**されるテストを必須化。

## [Critical] D26 の「解決不能なら grandfather 側」が型の意味を壊す
- 判断: 対応する / 対応: **`PaidSubscriptionPlan.planCode` を nullable に**（`grantsAccess=true` / `planCode=null` /
  fallback quota 適用 / **ログ・監視必須**）。`free_plan_code='personal'` でない組織を Grandfathered variant へ入れない、を拘束条件化。

## [Critical] D21 が `BillingRequiredController` へ未適用
- 判断: 対応する / 対応: **3 Controller すべて**から `Organization` 引数を削除し共通 current-org resolver で解決。
  **current org 不在 / 非所属の 404 テストを 3 route すべてに**適用（不変条件 #2）。

## [Critical] D27 と D24 で debt の正規数式が二重化
- 判断: 対応する / 対応: **D27 を唯一の正本**とし、D24 の記述を「数式の正本は D27」へ改訂。
  **hold 込みの旧式 `debt = min(purchasedRaw - purchasedHold, 0)` は誤り（hold で DTO 上の債務が増減する）として使用禁止**を明記。
  正規: `debtAmount = max(-purchasedRaw, 0)` / `monthlySpendable = max(monthlyPositive - debtAmount, 0)` /
  `availableTrueBalance = monthlySpendable + purchasedPositive`。この snapshot を balance / reserve / legacy 再配賦 /
  auto-recharge で共有し**旧式は全文削除**。

## [Critical] D25 で subscription token と ticket token を混同（**私のパッチによる作り込み**）
- 判断: 対応する（**重大な取り違え。指摘に感謝**）
- 対応: **チケット決済の attempt token は既存冪等性に必要なので維持**（`PurchaseTicketsPageDto.attemptToken` /
  resume 処理 / `TicketPurchaseResumeStateTest` の token 再利用）。**P8b から削除するのは subscription checkout 用のみ**
  （`Billing/Plans` の POST は `{plan_code}` のみ）。**型名で区別**（`ticketAttemptToken` / `subscriptionAttemptToken`）。
  subscription 用は **P9 で導入**。

## [Critical] P8b から billingContact / feedback がまだ除去されていない
- 判断: 対応する / 対応: P8b を**再生成**し、消化台帳 #9・Index の feedback バナー・`BillingContactForm.svelte`・
  TS の `billingContact`/`feedback` を**一切登場させない**。**P9 セクションを新規に書き起こし**、関連 DTO/props/UI/テストを移した。

## [Warning] P4 に旧変更対象が残っている / [Warning] P6 の activate 重複が変更表に残る
- 判断: 対応する / 対応: P4 は再生成（`NoPlan` へ統一・`state()` 表記なし）。P6 の変更表の当該行を
  「**変更なし・回帰確認のみ**（activate は P1 完成済み）+ `claimSignupGrantMarker()` の private 化」へ置換。

## [Warning] free 削除 migration の `down()` で config は戻せない
- 判断: 対応する（正しい。migration は repo 内 config を書き換えられない）
- 対応: rollback を **「コード/config revert → migration down」の順序の運用手順として分離**し、`down()` で config を戻すとは書かない。

## [Warning] personal/starter 再公開が P8b 変更一覧にない
- 判断: 対応する / 対応: P8b の変更一覧に**再公開 data migration + 残余検証 + `/pricing` 露出テスト**を含めて再生成。
