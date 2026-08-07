# 対応マトリクス: impl-review Round 4 (確認ラウンド)

Round 3 の [Warning] 3 件に対する対応が実際にコードへ入っているかを確認するラウンド。
Codex の判定は **APPROVED**（[Critical] 0 件 / [Warning] 0 件 / [Suggestion] 1 件）。

## 事前の自己検証 (Codex へ投げる前に実施)

Round 3 の 3 件が実装へ入っていることを、コミット `a5e0433` の実体で確認した。

| # | Round 3 の指摘 | 実体の所在 | 判定 |
|---|---|---|---|
| W1 | `report($exception)` で外部生成メッセージが例外ログへ流れる | `app/Services/Billing/AutoRechargeService.php` `terminateInvoiceBestEffort()` — `report(new RuntimeException("auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})"))`。原例外を渡さず `previous` にも繋がない | 入っている |
| W2 | 例外報告先を固定するテストが無い | `tests/Feature/Billing/AutoRechargeServiceTest.php` — `後始末の例外報告にも外部由来のメッセージを渡さない (サニタイズ済み例外のみ)` (`Exceptions::fake()` + `assertReported()` + `assertReportedCount(1)`) | 入っている |
| W3 | docs の「放置してよい」 | `docs/architecture.md` §運用契約 (b) 行 — 「**原則すべて手動終端の対象**」+ idempotency key 保持期間の引用ブロック | 入っている |

### 自己検証で見つけた齟齬 (Codex へ投げる前に修正)

`docs/architecture.md` の **(a) 行**に
「メッセージ本文は `report()` 側の例外報告に残る」という記述が残っていた。
これは W1 の対応 (サニタイズ済み例外) によって**偽になった記述**であり、
運用担当に「原メッセージを追える」という誤った期待を与える。次のように改めた:

```diff
-     (原因の分類は同ログの `error` = 例外クラス名。メッセージ本文は `report()` 側の例外報告に残る)
+     (原因の分類は同ログの `error` = 例外クラス名。`report()` 側にも invoice id と例外クラス名だけを
+      持つサニタイズ済み例外しか流れないため、…原メッセージはアプリのどこにも残らない。
+      詳細が要るときは `invoice_id` で Stripe 側を直接確認する)
```

## Codex Round 4 の判定

- **W1 解消** — 原例外を report せず、`previous` も繋いでいない。「保存場所を移しただけ」問題は塞がった。
- **W2 解消** — `Exceptions::fake()` で報告先を fake し、サニタイズ済みであること・外部由来文字列を
  含まないこと・報告が 1 回だけであること・`previous` が無いことまで固定できている。
- **W3 解消** — 「放置してよい」が削除され、保持期間と一時保留の例外条件が明記されている。
- **スコープ判断も妥当と認定** — gateway 境界でのドメイン例外化 / 例外報告基盤での redact を
  本 PR 外の独立 TODO とする線引きについて、Codex は
  「今回の失敗モードは呼び出し側の `report()` で閉じており、interface 契約変更なしに解消できている。
  詳細設計が interface を変更しない前提なら、この PR で境界例外化まで広げる根拠は弱い」と同意。
  既存経路 `tryTerminateInvoice()` の `$e->getMessage()` も「別 TODO でよい」と認定。
- **新規欠陥なし** — 今回の対応そのものが持ち込んだ [Critical] / [Warning] は無し。

## [Suggestion] docs の「アプリのどこにも残らない」を cleanup 経路に限定してはどうか

- 判断: **対応する**
- 根拠: 指摘は正しい。既存経路 `tryTerminateInvoice()` は `$e->getMessage()` を構造化ログへ
  入れており、無限定に「アプリのどこにも残らない」と書くと**この PR で触っていない経路まで
  安全であるかのように読める**。事実に反する記述は運用契約として残せない。
  docs 1 行の限定であり、スコープも増えない。
- 対応内容:
  ```diff
  -     **Stripe が生成した原メッセージはアプリのどこにも残らない**
  +     **この cleanup 経路では Stripe が生成した原メッセージはアプリのどこにも残らない**
  +     (別経路の `tryTerminateInvoice()` は対象外)
  ```

## 再検証 (Round 4 の追加変更に対して)

Round 4 で加えた変更は **`docs/architecture.md` の 1 行のみ**（コード変更なし）。
それでも回帰が無いことを実測で確認した:

- `composer phpstan` (level 10): **No errors** (798 files)
- `composer test`: **3486 tests / 3484 passed / 0 failed / 2 skipped** (13311 assertions, 241s)

## 最終判定

**APPROVED** ([Critical] 0 / [Warning] 0)。
Round 3 時点の `CHANGES_REQUESTED` は、対応後の確認をもって解消した。
`codex_verdict` は **APPROVED** として報告する。

## 残課題 (独立 TODO 起票が妥当。Codex も同意)

1. **gateway 境界で Stripe 例外を安定した分類 (エラーコード / HTTP status / request id) を持つ
   ドメイン例外へ変換する** — `AutoRechargeGatewayInterface` の契約変更を伴い、詳細設計が
   同 interface を「変更しない」と明記しているため設計の再合議が要る。
2. **既存経路 `tryTerminateInvoice()` の `$e->getMessage()` を構造化ログから外す** —
   T131 が新設した経路ではない。観測語彙を揃えるための独立 TODO。
