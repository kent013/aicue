全体判定: **CHANGES_REQUESTED**

主要な設計判断は妥当になっています。特に CAS を採らない理由は、効果の否定ではなく、発生確率と導入後の回収契約を比較したリスク受容として成立しています。

ただし、本文に撤回済みの主張がもう1箇所残っています。また、「CASで閉じられる窓」の記述範囲と AutoRecharge の残余窓に修正が必要です。

## 1. 使命との整合性

[Suggestion] North Star との整合性に問題はありません。

成果物・LLM課金・Stripe課金の信頼性を支える改善として、間接的ですが必要な基盤整備です。

## 2. 禁止事項違反

[Suggestion] 明示的な禁止事項違反はありません。

Feature/Architecture テスト、mutation による赤化確認、AGENTS.md とテストの対応付けまで完了条件に含まれています。

## 3. 実現可能性

[Critical] 「家系への還流」に、撤回済みの主張が残っています。

該当箇所は次です。

> 単調な状態機械では `running` が既に sending の役割を果たしており、`sending` を分離しても回収閾値のところに同じ競合が現れる

これは今回修正した本文と再び矛盾します。

- `running` は送信権を保護する `sending` と同じ役割ではない
- `sending` の回収で生じるのは同じ競合ではなく、送信結果不明という別問題

修正提案: 次の趣旨へ置き換えてください。

> aicue は独立した送信権状態を持たず、timeout/stale 序列によって送信前競合の発生可能性を抑えている。`sending` CAS は競合を閉じられるが、送信結果不明を扱う新しい回収契約と状態機械の波及コストを伴うため、現時点では preflight suppression と明示的なリスク受容を選ぶ。

[Warning] 「再検証 SELECT と外部送信の間の窓は CAS で閉じられる」という表現は対象が広すぎます。

`running → sending` CAS が閉じるのは、すべての競合する terminal 遷移が `status = running` を条件とし、`sending` を変更できない場合の**送信権競合**です。DB と外部送信の一般的な非原子性までは閉じません。

修正提案:

- 見出しを「`recoverStale` と送信開始の間の送信権競合」に限定する。
- CAS 後にも送信結果不明が残ることは残余窓 2 に整理する。
- CAS の成立条件として「すべての競合遷移が `sending` を尊重する」を明記する。

## 4. 期待効果の妥当性

[Suggestion] 結果の一回性、外部呼び出し回数の一回性、preflight suppression の分離は適切です。効果も「terminal を検出できた場合」に限定され、過大主張はありません。

[Warning] 「解析の残り最大3段×リトライを呼ばない」は、再検証が各 `$attempt()` の直前なら、現在地点以降の試行数に依存します。

修正提案: 「再検証後に予定されていた残りの LLM 呼び出しを行わない」と表現し、具体的な最大回数は詳細設計の実際の retry budget から算出してください。

## 5. リスク

[Warning] AutoRecharge には、invoice 作成後・`stripe_invoice_id` 保存前に worker が死亡する残余窓があります。

順序は次のとおりです。

```text
Stripe invoice 作成成功
→ worker 死亡
→ DB に stripe_invoice_id がない
→ attempt が canceled
→ 停止側は invoice 未作成と判断
```

この場合、今回追加する2回目の再検証にも到達せず、terminal attempt に紐付かない open invoice が残り得ます。操作別 idempotency key があっても、DB側が invoice IDを知らなければ自動 void には使えません。

修正提案:

- 本件で閉じないなら、残余窓 2 に明記する。
- Stripe idempotency keyから既存 invoiceを再取得できる、または metadataの `attempt_ulid` で照会できるなら、既存 reconcile の対象可否を詳細設計で確認する。
- 回収不能なら、open invoice の監視・手動収束を運用契約へ登録する。
- 「invoice が無いので収束は自明」は、1回目の preflight で停止した場合だけに限定する。

[Warning] Canceled 後の void 処理について、終端失敗時にログだけで終えるなら、その open invoice は reconcile の母集団外です。

修正提案: 固定ログ event だけでなく、誰がどの条件で再試行または手動終端するかを S5 に明記してください。恒久回収を作らない判断自体は可能ですが、運用上の所有者が必要です。

## 6. スコープの適切さ

[Suggestion] CAS、専用キュー、ffmpeg前検査を今回のスコープから除外する判断は妥当です。CAS の除外理由も、スコープ外表では正しく修正されています。

[Suggestion] 18件全体を母集団とし、配信系も個別裁定させる設計は deny-by-default と整合します。

## 7. 型安全性

[Suggestion] `PreflightRequirement` を非 nullable にし、`PreflightCheckpoint` と `NoExternalCall` の閉じた型へ分けた点は妥当です。PHPStan level 10 とも整合します。

[Warning] PHP の interface だけでは実装型を2種類に閉じられません。

修正提案: Architecture テストで `PreflightRequirement` の実装クラス集合が `PreflightCheckpoint` と `NoExternalCall` に完全一致することを deny-by-default で検査してください。併せて `NoExternalCall` の根拠が30文字以上であることを constructor とテストの両方で固定してください。

結論として、設計の中心部分はほぼ承認可能です。**「家系への還流」に残った旧主張の削除、CASが閉じる対象の限定、AutoRecharge の invoice作成後・DB保存前の死亡窓の記録**が必要です。これらが反映されれば概念設計として APPROVED と判断できます。