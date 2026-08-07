# 対応マトリクス: impl-review Round 2

Round 2 は Round 1 の [Critical] 1 件・[Warning] 3 件への対応を提示し、
残った 2 件の [Warning] を受けたラウンド。

## Round 1 由来で **Round 2 で解消** と判定されたもの

| 指摘 | Round 2 での Codex 判定 |
|---|---|
| [Critical] `writeProgress()` が cast を通らない | **指摘なし** (`forceFill()->getAttributes()` は妥当) |
| [Warning] `queue.default` のハードコード | **指摘なし** (ソースの production fallback を固定する方式に合理性あり) |
| [Warning] `docs/architecture.md` の S7 差分が無い | **指摘なし** (実在を確認。AGENTS.md も「S7 は実在し設計を十分反映」) |

## [Warning] cleanup ログの `error` に例外メッセージをそのまま入れている

- 判断: **対応する (Round 1 の反論を撤回する)**
- 根拠: 反論の軸は「PII が入らないこと」だったが、レビュー観点の禁止対象は
  **PII だけでなく「外部 payload をログへ漏らさないこと」**である。
  Stripe SDK の例外メッセージは**外部サービスが生成する可変文字列**であり、
  いま既知の内容 (invoice id / status) だけに留まるという契約はどこにも無い。
  「現状の実装がそうなっている」は将来の安全性の根拠にならない、という指摘は正しい。
  既存 `tryTerminateInvoice()` との一貫性は、**新規経路を安全側へ倒さない理由にはならない**。
- 対応内容:
  - `terminateInvoiceBestEffort()` の `error` に入れる値を
    **`$exception::class` (例外クラス名) だけ**に変更した。
    構造化ログにはアプリが決めた有界な語彙のみを載せる。
  - 失われる原因の詳細は **`report($exception)`** で既存の例外報告経路へ渡す
    (`RenderPipeline` の後始末失敗が `report()` しているのと同じ作法)。
    抑止ログ (`JobOwnershipLostException`) を `report()` しないのは
    「正常だが観測したい事象」だからであり、**invoice 終端の失敗は異常事象**なので
    ここで `report()` することは設計の意図と矛盾しない。
  - **7 キー schema は変えない** (`error` のキー名も維持)。
    値の性質は新設テスト
    `後始末ログの error は例外クラス名のみで、外部由来のメッセージを含まない`
    が固定する (fake の例外メッセージ「fake gateway: …」が混入しないことを検査)。
  - 判断の理由を `terminateInvoiceBestEffort()` の docblock に残した。
  - `docs/architecture.md` の検知手順を「`error` = 例外クラス名。メッセージ本文は
    `report()` 側の例外報告に残る」と更新した。
- 再検証: `composer test -- tests/Feature/Billing/AutoRechargeServiceTest.php` 33 passed /
  `composer phpstan` OK / mutation **M13 / M14 / M15 / M16 / M17 の赤化を再確認**。

## [Warning] `docs/architecture.md`: open invoice (b) の**検知方法**が書かれていない

- 判断: **対応する**
- 根拠: 指摘のとおり。(b) は `stripe_invoice_id` 保存前の死亡なので
  **アプリログにも DB にも痕跡が残らない**。「metadata から逆引きできる」は
  収束手順であって発見手順ではなく、書いた運用契約が実行できない状態だった。
- 対応内容: (a) / (b) を表に分け、**発生条件 / 検知元 / 収束手順**を列にした。
  - (a) 検知元 = アプリログ (`job_ownership_lost_cleanup` かつ `terminated=false`)
  - (b) 検知元 = **Stripe 側を起点にする**。metadata `purpose=auto_recharge` を持つ
    `draft` / `open` invoice を列挙し、`recharge_attempt_ulid` に対応する
    `ticket_auto_recharge_attempts` 行の `stripe_invoice_id` が
    **NULL または別 id** のものを孤児として抽出する。
    収束は「attempt が terminal なら手動 void / attempt が pending なら
    次の `executeAttempt` が同一 idempotency key で同じ invoice に収束するため放置可」。
  - 照合の実施主体 (課金運用担当) と、自動化を今回スコープ外とする理由も明記した。
