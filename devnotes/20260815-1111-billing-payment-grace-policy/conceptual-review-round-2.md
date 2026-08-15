全体判定: **CHANGES_REQUESTED**

Round 1 の指摘は概ね適切に解消されています。特に DTO による snapshot 正規化、service 層での二重契約防止、PM 修復範囲の限定、観測時刻としての保証への修正は、指摘の意図を満たしています。

ただし、無料枠 fallback の状態分解に未解消の穴があり、「無期限利用を止める」という保証も現在の fail-open 方針とは両立していません。

## 1. 使命との整合性

[Suggestion] 原価管理を使命の「持続条件」と位置づけた修正は妥当です。課金自体をプロダクト価値として過大評価せず、LLM・動画合成を継続提供するための基盤として整理されています。

## 2. 禁止事項違反

[Suggestion] 明示的な違反はありません。

migration による backfill と通常時の単一 writer を区別し、手動 SQL / tinker を禁止した点も妥当です。Architecture テストでは、migration を無条件に許可するのではなく、対象 migration ファイルだけを理由付きで exempt にする必要があります。

## 3. 実現可能性

[Critical] `Incomplete` を `Inactive` に畳んだまま無料枠 fallback を許可する設計は、今回塞ぐべき穴を残します。

Stripe の `incomplete` は、初回請求の支払いが完了していない状態です。終了済みの `canceled` / `incomplete_expired` とは異なり、支払い解決待ちの契約を表します。その状態で `free_plan_code` により `ActiveFreePlan` へ落とすと、「支払いに失敗した利用者を無料枠として許可しない」という設計目的に反します。

修正提案: 少なくとも次の意味を区別してください。

- `Incomplete` → fallback 不可
- `IncompleteExpired` → fallback 可
- `Canceled` → fallback 可
- `Unpaid` → fallback 不可

`Unpaid` だけを追加するのでは足りず、現行 `Inactive` も分割する必要があります。詳細設計では Stripe の全 status 文字列から内部状態への変換と `allowsFreePlanFallback()` を同じテーブル駆動テストで固定してください。

[Warning] gateway DTO の構造は妥当ですが、PM 情報の「観測できた」と「false」を区別できる必要があります。

expand されていない payload を `false` と解釈すると、既存の単調更新を守れません。

修正提案: PM は単純な `bool` ではなく、`true / false / unknown` を表現する enum または nullable な専用値にしてください。今回書き込むのは明示的な `true` の場合だけです。

## 4. 期待効果の妥当性

[Warning] 仮説の成功条件 3 は、修正後の保証範囲とまだ矛盾しています。

「許可 / 遮断のどちらの向きにも収束する」と書く一方、PM の false 方向は対象外です。また、404・Stripe API 障害・scheduler 停止では翌日に収束しません。

修正提案: 成功条件 3 を次の程度に限定してください。

> 日次突き合わせが正常完了した契約について、`stripe_status` は Stripe の観測値へ収束し、PM 登録の取りこぼしは true 方向に修復される。

「翌日」も実行成功を前提とするため、「次回の正常完了時」が正確です。

[Warning] 「無期限の利用が止まること」は無条件には保証できません。

ローカルが `active` のまま webhook を落とし、その後 reconcile が継続的に 404 または失敗すると、`past_due_since` は永遠に作られません。設計自身が 404 時の状態変更を禁止しているためです。

修正提案: 保証を「`past_due` をアプリが観測した契約では無期限利用を止める」に限定してください。そのうえで reconcile の未確認件数・連続失敗を監視対象として `docs/architecture.md` に定義してください。

## 5. リスク

[Warning] reconcile の運用上の収束条件が不足しています。

日次コマンドを追加するだけでは、重複起動、途中失敗、Stripe rate limit、大量契約時の走査打ち切りによって一部契約が長期間未確認になる可能性があります。

修正提案: 概念設計の範囲で、少なくとも以下を契約として追加してください。

- 重複実行を抑止する
- pagination または chunk 処理を行う
- 1 件の失敗で全走査を止めない
- 成功・更新・404・失敗件数を集約報告する
- コマンド全体が正常完了しなかった場合は失敗終了する

閾値や通知基盤の新設までは不要ですが、未確認を成功扱いしないことは AG-035 (6) の成立条件です。

## 6. スコープの適切さ

[Suggestion] 1 PR で猶予判定、reconcile、無料枠 bypass、二重契約を同時に閉じる判断は妥当です。これらは独立した追加機能ではなく、猶予遮断を安全に導入するための一つの変更単位です。

一方、`Inactive` の分割はスコープ追加ではなく、無料枠 fallback を正しく実装するための必須修正として同じ PR に含めるべきです。

## 7. 型安全性

[Warning] DTO 化の方向は PHPStan level 10 に適合しますが、DTO の PM フィールドが `bool` だけでは観測不能を型で表現できません。

修正提案: `PaymentMethodObservation` のような enum、または意味を明示した nullable DTO を使い、`unknown` を `false` に縮約しないでください。Stripe SDK 型を gateway 内に閉じ込める方針と、`EntitlementDeniedReason` を Inertia props に露出させない判断は妥当です。