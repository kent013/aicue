# 対応マトリクス: conceptual-review Round 4

## [Critical] 「家系への還流」に撤回済みの主張が残っている

- 判断: **対応する**
- 根拠: 妥当。Round 3 で本文を直したが、還流セクションの一文を直し漏れていた。
  `running` が `sending` の役割を果たす / 同じ競合が再発する、はどちらも撤回済みの主張。
- 対応内容: Codex 提案の文面をそのまま台帳へ返す判断として採用。
  「aicue は独立した送信権状態を持たず、timeout/stale 序列によって送信前競合の発生可能性を
  抑えている。`sending` CAS は送信権競合を閉じられるが、送信結果不明を扱う新しい回収契約と
  状態機械の波及コストを伴うため、現時点では preflight suppression と明示的なリスク受容を選ぶ。」

## [Warning] 「再検証 SELECT と外部送信の間の窓は CAS で閉じられる」は対象が広すぎる

- 判断: **対応する**
- 根拠: 妥当。CAS が閉じるのは**送信権競合**であって、DB と外部送信の一般的な非原子性ではない。
- 対応内容: 残余窓 1 の見出しを
  「**`recoverStale` と送信開始の間の「送信権競合」**」へ限定。
  **CAS の成立条件**「`running` と競合しうるすべての terminal 遷移が `status = running` を
  条件とし、`sending` を書き換えないこと」を明記
  (1 経路でも `sending → failed` を許すと元の競合が復活する)。
  「CAS が閉じないもの = `sending` 獲得後のプロセス死」を残余窓 2 へ整理した。

## [Warning] 「解析の残り最大 3 段 × リトライを呼ばない」は現在地点に依存する

- 判断: **対応する**
- 根拠: 妥当。再検証は各 `$attempt()` の直前なので、抑止できる回数は検出時点の残り段数と
  retry budget に依存する。「最大 3 段」は最良ケースの数字を一般化していた。
- 対応内容: 「**再検証後に予定されていた残りの LLM 呼び出しを 1 回も行わない**」へ改め、
  具体的な最大回数は詳細設計で retry budget から算出すると明記した。

## [Warning] invoice 作成成功 → `stripe_invoice_id` 保存前のワーカー死亡という残余窓がある

- 判断: **対応する (残余窓として記録 + 運用契約へ登録)**
- 根拠: 完全に妥当。この順序では 2 回目の再検証に到達しないため preflight では閉じない。
  停止側の `tryTerminateInvoice()` は `stripe_invoice_id === null` を
  「invoice 未作成 = 課金され得ない」と解釈するため素通りする。
  実査で確認: invoice の metadata には `recharge_attempt_ulid` が入っている
  (`AutoRechargeService::metadataFor()`) ので **Stripe 側からの逆引きは可能**。
  しかし `reconcile()` の 5 分岐は DB の pending attempt を走査する設計なので母集団外。
- 対応内容: 残余窓 2 に「auto-recharge の同型窓」として明記。
  恒久回収 (Stripe 起点の逆走査分岐) は今回作らないと明示し、
  監視・手動収束の所有者と手順を S5 の運用契約へ登録することにした。
  また「invoice が無いので収束は自明」を **1 回目の preflight で停止した場合だけ**に限定した。

## [Warning] Canceled 後の void 失敗で残る open invoice は reconcile 母集団外。所有者が要る

- 判断: **対応する**
- 根拠: 妥当。恒久回収を作らない判断自体は許されるが、運用上の所有者が居ない状態は許されない。
- 対応内容: S5 の運用契約に (a) void 失敗で残った open invoice / (b) 上記の
  invoice_id 未保存で残った open invoice の 2 件について、監視と手動収束の所有者・初動手順を
  登録することを明記。**「恒久回収を作らない判断は許すが、所有者が居ない状態は許さない」**と書いた。

## [Warning] PHP の interface だけでは `PreflightRequirement` の実装型を 2 種類に閉じられない

- 判断: **対応する**
- 根拠: 妥当。sealed type が無い以上、閉じるのは gate の役目。
- 対応内容: 「`PreflightRequirement` の実装クラス集合が `PreflightCheckpoint` と
  `NoExternalCall` に**完全一致**する」ことを gate 自身が deny-by-default で検査する、と明記。
  `NoExternalCall` の 30 文字要件は **constructor (`Assert`) と gate の両方**で固定する。
