`app/Services/Billing/AutoRechargeService.php`

[Warning] `error` を `$exception::class` に限定した構造化ログ自体は安全ですが、直後の `report($exception)` により外部生成メッセージは通常の例外ログへそのまま流れます。

したがって、現状は「集計用 warning context に外部 payload を載せない」は満たしますが、「ログ全体に外部 payload を漏らさない」は満たしません。Laravel の標準 exception handler は例外メッセージとスタックトレースを記録するため、保存場所を移しただけです。

具体的には次のいずれかが必要です。

- この箇所では原例外を `report()` せず、固定メッセージと例外クラスだけを持つ sanitized な例外を報告する
- gateway 境界で Stripe 例外を、安定したエラー分類・HTTP status・Stripe request IDなどだけを持つドメイン例外へ変換する
- 例外報告基盤で当該例外の外部生成メッセージを確実に redact する

sanitized 例外に原例外を `previous` として渡すと、レポータが previous chain を出力する可能性があるため、この目的では避ける必要があります。

`JobOwnershipLostException` を report しない整理との概念的な矛盾はありません。所有権喪失は観測対象の正常中断、invoice 終端失敗は異常事象です。問題は report の有無ではなく、報告内容の安全性です。

`tests/Feature/Billing/AutoRechargeServiceTest.php`

[Warning] 新設テストは cleanup warning の `error` がクラス名であることを正しく固定していますが、`report($exception)` 経由で原メッセージが別ログへ流れないことは固定できていません。

安全性を不変条件にするなら、例外報告先を fake/spying し、外部由来メッセージが渡されないことまでテスト対象にする必要があります。

`docs/architecture.md`

[Warning] (b) の検知手順は実装と整合しています。Stripe 起点で `purpose=auto_recharge` を絞り、`recharge_attempt_ulid` から attempt を照合し、保存済み invoice ID と比較する流れは妥当です。

ただし、次の記述は無条件には正しくありません。

> attempt が pending なら次の executeAttempt が同一 idempotency key で同じ invoice に収束するため放置してよい

attempt 固定キーで同じ invoice に収束するのは、Stripe がその idempotency key の結果を保持している期間内に限られます。保持期間を越えた再実行では、新しい invoice が作られる可能性があります。状態検査で期限なく冪等化される `terminateInvoice()` とは性質が異なります。

運用記述は、例えば次のように限定する必要があります。

- pending でも孤児 invoice は原則として手動終端対象とする
- または、idempotency key の保持期間内かつ再実行が確実に予定されている場合だけ一時保留できる
- 次回実行後に DB の `stripe_invoice_id` と一致しなければ旧 invoice を終端する

現状の「放置してよい」は、長期間残った pending attempt に対して偽の安全性を示すため修正が必要です。

その他の Round 1・2 の対応、7 キー schema の維持、preflight 配置、条件付き UPDATE、S7 の対応表について新たな指摘はありません。

全体判定: CHANGES_REQUESTED