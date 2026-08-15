# 対応マトリクス: conceptual-review Round 1

## [Critical] 猶予切れ後の無料枠すり抜け: 条件が past_due / paused だけでは不足
- 判断: 対応する
- 根拠: `unpaid` は Stripe 上「契約は残り請求は未払い」であり、`canceled` (終了) と
  無料枠へ降りてよいかの答えが逆になる。現行 `SubscriptionState` は両者を `Inactive` に
  畳んでいるため、状態ごとの意味を固定しないと指摘どおり回帰する。
- 対応内容: `SubscriptionState::allowsFreePlanFallback()` を網羅 `match` で新設し、
  状態ごとに可否を明示。`Unpaid` を 1 case 追加して `canceled` と分離した
  (`grantsAccess()` は両方 false のままなので遮断挙動は不変)。各 case の理由を概念設計に列挙し、
  詳細設計で `SubscriptionState::cases()` を回すテーブル駆動テストを施策に含める。

## [Warning] PM 有無の単調修復では「両方向の回復」という主張とずれる
- 判断: 対応する (主張を弱める側に寄せる)
- 根拠: `recordPaymentMethodSnapshot` の単調性は「Stripe payload が PM を expand しない周期がある」
  ことへの防御であり、これを崩すと trial 終了判定が誤発火する。突き合わせのためだけに
  同じ列へ 2 つ目の書込意味を持ち込むほうが危険。
- 対応内容: 概念設計 (c) に「true 方向のみ修復。PM 削除の取りこぼしは対象外」と明記し、
  なぜ権利判定としては閉じるか (PM が実際に無ければ次の請求が失敗し past_due → 猶予で遮断)
  を書いた。期待効果の文も「stripe_status は両方向・PM は true 方向のみ」に修正。

## [Warning] reconcile が同じ snapshot 経路を通る保証が曖昧 / 列直書きの抜け道
- 判断: 対応する
- 根拠: 指摘のとおり、正規化の形を決めないと command 側で列を直接更新する実装になりうる。
- 対応内容: gateway は SDK オブジェクトを返さず、webhook が payload から組むのと同じ
  `SubscriptionSnapshot` + PM 有無を包んだ DTO を返す形に固定。コマンドは
  `applySubscriptionSnapshot` / `recordPaymentMethodSnapshot` 以外の書込をしないと明記した
  (詳細設計では Architecture テストで `past_due_since` の書込単一化を機械固定する)。

## [Warning] past_due_since が「観測日」になるズレ / 効果の主張が強すぎる
- 判断: 対応する
- 根拠: 事実として観測時刻であり、実際の失敗時刻ではない。誇張しない規約に合わせる。
- 対応内容: 「期待効果として主張しないこと」節を新設し、保証するのは
  「無期限の利用が止まること」と「起点が観測時刻として必ず残ること」だけだと明記。
  Stripe の請求履歴から起点を復元する案は、外部 API 呼び出しを増やす割に得るものが
  数日の厳密さしかないため採らない、と判断理由を残した。

## [Warning] AG-035 (5) の「残高切れ」側の扱いが未記録
- 判断: 対応する
- 根拠: スコープ外にするのは妥当でも、標準形としての決定を残さないと未実装と区別できない。
- 対応内容: 「残高切れの猶予は 0 (予約時点で即拒否)」を決定として `docs/architecture.md` に
  書く作業を施策に含め、`PaymentGracePolicy` が答えるのは支払い失敗の猶予だけだと明示した。

## [Warning] 二重契約防止を controller に寄せると別経路で漏れる
- 判断: 対応する (元から service 層本体の意図だったので明文化)
- 対応内容: 「拒否の本体は `startCheckout` 段 1 (最下流)。controller の変更は遷移先の選択だけで
  判定を二重に持たない」と概念設計 (e) に明記した。

## [Warning] backfill が課金データ補正になる / 手動手順を前提にしない
- 判断: 対応する
- 対応内容: 移行節に「補正は migration の中だけで完結させる。runbook にも手動 SQL / tinker で
  `past_due_since` を書かないと明記する」を追加した。

## [Warning] スコープが大きい / 途中状態を作らない
- 判断: 対応する
- 対応内容: 制約に「1 PR で完結させる (段階分割すると、その間だけ課金回避が成立する)」を追加。
  実装モードは standalone とする。

## [Warning] Stripe 読み取りの戻り値型が未定義 (PHPStan level 10 / fake の重さ)
- 判断: 対応する
- 対応内容: 上記のとおり gateway は DTO を返す形に固定。テストは interface のスタブを
  container に bind して駆動する (Stripe SDK は Laravel HTTP client を通らないため
  `Http::fake` では止められない、という前提も制約に書いた)。

## [Suggestion] 使命の位置づけを「原価管理」として書く
- 判断: 対応する
- 対応内容: 期待効果の 1 項目目を「体験を続けられる形で提供するための原価管理」と書き直した。

## [Suggestion] enum 値を UI へ直接渡さない
- 判断: 見送る (現状すでに満たしている)
- 根拠: `EntitlementDeniedReason` は `SubscriptionEntitlementDto` の中だけで使われ、
  Inertia props に露出している箇所は無い (`BillingAccess` は `->entitled` しか読まない)。
  画面へ出る文言は `RequireActiveSubscription` の既存定数と着地ページが持つ。
  今回 props へ露出させる予定も無いため、追加の変換層は作らない (今必要なものだけ作る)。
