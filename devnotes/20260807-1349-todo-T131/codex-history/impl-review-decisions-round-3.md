# 対応マトリクス: impl-review Round 3 (最終)

Round 3 が合議の**上限ラウンド** (app-implement SKILL A-3: 最大 3 ラウンド)。
Codex の最終判定は **CHANGES_REQUESTED**（[Critical] は 0 件、[Warning] 3 件）。
3 件とも**内容を検討したうえで対応済み**だが、**対応後の確認ラウンドは回していない**ため、
本タスクの `codex_verdict` は実態どおり `CHANGES_REQUESTED` として報告する。

## [Warning] `report($exception)` により外部生成メッセージが通常の例外ログへ流れる

- 判断: **対応する**
- 根拠: 指摘は正しい。Round 2 の対応は「構造化ログの context から外した」だけで、
  標準の exception handler は message とスタックトレースを記録するため、
  外部 (Stripe SDK) 生成文字列の**保存場所を移しただけ**だった。
  Codex が挙げた 3 案のうち、gateway 境界でのドメイン例外化と例外報告基盤の redact は
  T131 のスコープを超える (前者は `AutoRechargeGatewayInterface` の契約変更 =
  詳細設計が「変更しない」と明記した箇所、後者は横断基盤の変更)。
  **「この箇所ではサニタイズ済み例外を報告する」**が最小で正しい。
- 対応内容:
  ```php
  $error = $exception::class;
  report(new RuntimeException(
      "auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})",
  ));
  ```
  - 原例外は渡さず、**`previous` にも繋がない** (reporter が previous chain を
    出力しうるという指摘に従う)。
  - トリアージに必要な「どの invoice が / どの種類の失敗か」は保たれる
    (`RuntimeException` = 自前 `Assert` (paid 等の状態不正) /
     `Stripe\Exception\*` = API 側の拒否・接続失敗、という切り分けができる)。
  - 判断理由を `terminateInvoiceBestEffort()` の docblock に残した。

## [Warning] 新設テストが「report 経由で原メッセージが流れないこと」を固定できていない

- 判断: **対応する**
- 根拠: 指摘のとおり。安全性を不変条件として主張する以上、例外報告先まで検査対象にすべき。
- 対応内容: テストを 1 本追加した
  `後始末の例外報告にも外部由来のメッセージを渡さない (サニタイズ済み例外のみ)`。
  `Illuminate\Support\Facades\Exceptions::fake()` で例外報告先を fake し、
  `assertReported()` で (a) 期待する固定文言を含む / (b) fake gateway が生成した
  文字列 (`'fake gateway'`) を**含まない** / (c) `getPrevious() === null` を検査し、
  `assertReportedCount(1)` で報告が 1 回だけであることも固定した。

## [Warning] `docs/architecture.md` (b) の「放置してよい」は無条件には正しくない

- 判断: **対応する**
- 根拠: 指摘は正しい。Stripe の idempotency key は**保持期間**があり、期限を過ぎた再実行では
  別の invoice が作られうる。状態検査で期限なく冪等化される `terminateInvoice()` とは
  性質が違う。長期間残った pending attempt に対して偽の安全性を与える記述だった。
- 対応内容: (b) の収束手順を **「原則すべて手動終端の対象」** に改め、
  引用ブロックで「『次の実行が拾うから放置してよい』と書かない」理由 (idempotency key の
  保持期間) と、例外的に一時保留してよい条件 (保持期間内かつ再実行が確実に予定されている /
  その場合も再実行後に DB の `stripe_invoice_id` と一致しない旧 invoice は終端する) を明記した。

## 再検証

- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `composer test -- tests/Feature/Billing/AutoRechargeServiceTest.php`: **34 passed**
- mutation **M13 / M14 / M15 / M16 / M17 を再実施し、いずれも赤化を再確認**
- 最終確認として `composer test` 全件 + `pnpm` 系検証コマンドを再実行

## 残課題 (次のタスクへ持ち越し。本 PR のスコープ外と判断した)

- Codex が代替案として挙げた **「gateway 境界で Stripe 例外を安定した分類
  (エラーコード / HTTP status / request id) を持つドメイン例外へ変換する」** は、
  `AutoRechargeGatewayInterface` の契約変更を伴う。詳細設計は同 interface を
  「変更しない」と明記しており、変更するなら設計の再合議が要る。
  本 PR では呼び出し側でのサニタイズに留めた。
- 同じ理由で、既存の `tryTerminateInvoice()` が `$e->getMessage()` を
  構造化ログへ入れている点は**本 PR では触っていない** (T131 が新設した経路ではないため)。
  観測語彙を揃えるなら独立の TODO として起票するのが筋。
