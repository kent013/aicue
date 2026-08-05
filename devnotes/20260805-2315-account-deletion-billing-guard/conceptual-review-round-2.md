全体判定: **CHANGES_REQUESTED**

Round 1 の大半は適切に解消されています。ただし、退会トランザクションと webhook の競合について、§4.4 が主張する保証と実際のロック挙動が一致していません。一次目的である「新たな課金孤児を防ぐ」を破る経路が残ります。

## 1. 使命との整合性

[Suggestion] 課金事故の防止は North Star の直接機能ではありませんが、継続利用の信頼を守る基盤として妥当です。変更不要です。

## 2. 禁止事項・セキュリティ不変条件

[Warning] 検証コマンドが AGENTS.md の正本より不足しています。`pnpm build`、`pnpm typecheck:packages`、`pnpm build:packages`、`pnpm test:packages` がありません。

修正提案: §7 の検証コマンドを AGENTS.md の `VERIFICATION_COMMANDS` と完全に同期させてください。

[Suggestion] Stripe API 非呼び出し、DTO/Inertia 利用、ボタンを disabled にしない方針、課金解釈を Billing 配下へ閉じる方針は規約に整合しています。

## 3. 実現可能性

[Warning] 非 current 組織の「switch 成功後にクライアントで `/billing` へ遷移」は実現可能ですが、切替失敗時の挙動と認可条件が未定義です。

修正提案: `POST /organizations/{slug}/switch` の成功・403・404・validation error を Feature テストに追加し、成功時だけ `/billing` へ進むことを固定してください。`isCurrent` は表示時点のヒントであり、切替時にはサーバで所属・認可を再評価します。

## 4. 述語の正しさ

[Suggestion] `trialing`、`past_due`、`paused`、`cancel_at_period_end` の整理は妥当です。base price + quantity の前提も明文化され、Round 1 の懸念は解消しています。

[Warning] `incomplete` の通過は実務的には許容できますが、「完了できるのは本人だけ」「事実上 active 化しない」は保証として強すぎます。非同期決済や、既に開始済みの PaymentIntent 処理を概念上排除できていません。

修正提案: 判断は通過のままでよいですが、根拠を次のように弱めてください。

> 通常のカード Checkout では追加操作未完了の `incomplete` は23時間以内に失効する。退会後に決済が完了する低確率の残存リスクはあるが、確実な解消導線なしにブロックする害を優先して通過させる。

加えて、aicue が許可する決済手段と非同期決済の有無を設計前提に固定してください。

[Suggestion] live pending checkout を一律 blocker にしない判断は妥当です。既存の「live」は退会安全性を表す概念ではないため、1日閾値を流用しない判断も思考原則4に整合します。

## 5. リスク・競合

[Critical] §4.4 のロック保証が不正確です。次の順序が成立します。

1. 退会処理が organization 行をロックする。
2. webhook が subscription 行を INSERT/UPDATEする。
3. webhook は organization 更新で待機する。
4. 退会処理は webhook の未コミット subscription を読めず、ユーザーを削除して commit する。
5. webhook が再開して subscription と organization 更新を commit する。

つまり、organization 行ロックは webhook の完了を待たせますが、subscription の変更開始を防ぎません。「支払い完了から webhook INSERT まで」だけでなく、**webhook トランザクション実行中そのもの**が残存窓です。

修正提案: 両経路が課金状態変更前に取得する共通の排他点を設けてください。第一候補は webhook のロック順を `organization → subscription` に統一し、退会側も同順序にすることです。変更範囲が大きい場合は、organization ID をキーとする transaction-scoped advisory lock など、既存順序と循環しない共通ロックを Billing 層で設計できます。少なくとも、この競合を受容するなら「新規発生を止める deny-by-default」という記述は削除し、一次目的を完全には満たさない設計として裁定が必要です。

[Warning] この競合は秒単位でも、発生時の結果が「退会後も請求継続かつ自己解約不能」なので、発生確率だけで受容するには損失が大きいです。後続の検知 TODO は予防策の代替になりません。

修正提案: 共通排他を今回スコープへ含めるか、少なくとも実装と同時に検知手段を必須化してください。

## 6. スコープ

[Suggestion] 猶予期間つき削除、保持期間実装、redaction 自動化を外す理由は十分です。特に保持期間を規約確定に依存させる判断は妥当です。

[Warning] 「オーナー不在の課金中組織の検知」を後続へ送る判断は、上記競合を残す場合には過小スコープです。

修正提案: 共通排他で競合を閉じられない場合、検知と運用通知を本機能の完了条件へ昇格してください。

## 7. 型安全性

[Suggestion] 理由 enum の PHP⇔TS 同期、DTO の一元化、props 形状の Feature テストで PHPStan level 10 と Svelte の型安全性を満たせる設計です。

[Warning] `isCurrent` は時間で変化する派生値なので、DTO のドメイン事実として扱うと陳腐化します。

修正提案: DTO の意味を「表示時点の action hint」と明記するか、`isCurrent` ではなく `action: 'open_billing' | 'switch_then_open_billing'` の判別共用体にすると、フロント側の分岐漏れを型で閉じられます。

特に指定された4点への回答は、`incomplete` 通過は前提を弱めれば妥当、subscription 非ロックは現状の説明では不十分、pending checkout 非ブロックは妥当、スコープ外3点は妥当、です。最大の未解決点は webhook トランザクションとの共通排他です。