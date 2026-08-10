# 対応マトリクス: impl-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 2 / Suggestion 1)

## [Warning] `carryForwardGroup()` の集計対象と削除対象が固定されていない

- 判断: **対応する** (指摘は正しい)
- 根拠: `organizations` 行ロックは台帳への insert を止めない。`grantMonthly` /
  `grantPurchased` / `grantAutoRecharge` は**ロックを取らない冪等 insert** であり、
  backfill / 取り込みも同様である。READ COMMITTED では文ごとに snapshot が変わるため、
  `sum()` と `delete()` の間に `created_at <= 閾値` の行が commit されると
  **合計に入っていない行を削除が巻き込む** = その枚数ぶん残高が消える。
  PR-C2 の最重要不変条件 (残高を 1 枚も動かさない) に直接触れる。
- 対応内容 (3 点):
  1. **件数・合計・前回終端を 1 文で取る** (`aggregateGroup()` の
     `COUNT(*) / COALESCE(SUM(delta),0) / MAX(carried_forward_through)`)。
     3 回に分けると「合計には入っていないが件数には入っている」行が生まれ、
     検査そのものが壊れるため。
  2. **削除件数と集計件数の一致を検査**し、不一致ならトランザクションごと巻き戻す
     (`$deleted !== $aggregate['rows']` で `RuntimeException`)。
     ID 集合を固定する案 (`whereIn('id', …)`) は採らなかった —
     `ModelDirectFetchInvariantTest` の主キー同一性クエリの母集団に入り、
     目録登録という別の摩擦を課金バッチに持ち込むことになる。
     件数一致検査は同じ窓を fail-closed で閉じ、主キー述語を増やさない。
  3. **決定的な挙動テストを追加**
     (`集計の後に古い行が割り込んだら fail-closed`)。繰越行の INSERT を `DB::listen` で
     観測した瞬間に「閾値より古い行」を差し込み、`unexpectedFailures = 1` /
     `processed = 0` / 元の残高が 1 枚も減らないことを固定した。
     mutation で guard を外すと赤くなることも実測した (MU11)。

## [Warning] `TicketLedgerKind::CarryForward` 追加に対する TS 側確認の証跡が無い

- 判断: **対応する** (証跡を機械固定へ格上げする)
- 根拠: 実読では `resources/js` に台帳 kind の対応型も表示分岐も存在せず
  (`ledger` / `reserve_commit` / `clawback` の grep が 0 件)、`label()` の呼び出し元も 0 件で
  あった。よって TS 同期テストの**追加は不要**だが、「確認した」が差分に残らないのは
  指摘のとおりである。散文で書くより機械で固定する方が腐らない。
- 対応内容: `TicketLedgerReaderInventoryTest` に 2 検査を追加した。
  - 検査 7: `resources/js` (ts / svelte) に `TicketLedgerKind` / `reserve_commit` /
    `carry_forward` が現れないことを deny-by-default で固定する。
    フロントへ持ち込むなら PHP enum ⇔ TS union の同期テストを同時に足させる。
    空振り検知として `types/manual.ts` に `export type` が実在することも見る。
  - 検査 8: 全 case が非空の表示ラベルを持ち、case 数を**現在値ちょうど** (6) で pin する
    (case を足したら必ずこの数字を書き換える = 表示分岐を見直す契機になる)。

## [Suggestion] `TicketLedgerEntryFactory::legacy()` のコメントが誤読を招く

- 判断: **対応する**
- 根拠: 指摘のとおり。`source IS NULL` は**表示残高の集計では purchased バケットに含まれる**
  が、**畳み込みでは独立した group** として扱う。1 行のコメントで両方を混同させていた。
- 対応内容: docblock を「表示残高では purchased に含まれるが、畳み込み group としては
  null のまま扱う」と両側を書き分ける形へ直した (`sumBalance()` への参照つき)。

## 補足 (次ラウンドで伝える)

- `--apply` / horizon / PII / registry の子→親順・runbook については Codex も OK 判定。
- mutation 記録に「設計の予測と実測がずれた点」5 件を残しており、今回の修正で
  MU11 (削除集合の guard) を追加実測した。
