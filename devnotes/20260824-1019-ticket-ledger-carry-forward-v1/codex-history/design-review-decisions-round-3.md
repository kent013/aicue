# 対応マトリクス: design-review Round 3

## 施策 4

### [Warning] 「runbook が唯一の正本 / migration は参照するだけ」が実際の docblock と一致しない

- 判断: 対応する (**提案 2 を採用**)
- 根拠: migration を単独で読んだ人にも drop-first の危険が見えるべきなので、
  「要約ゼロ」にはしない。ただし手順を再掲すると 2 か所管理になる。
- 対応内容: **正本関係を明文で固定した**。
  - **手順・rollback・maintenance window の判断の正本** =
    `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節
  - **migration の docblock** = 「**コード先行が必須**」「**drop 後に単純 rollback 不可**」の
    **2 行だけ**を破壊条件の要約として持ち、詳細は runbook を参照する
  - **`docs/architecture.md`** = 順序を**一切書かず** runbook を参照するだけ
  docblock の本文もこの形へ書き換えた (逆順時の障害の詳述・rollback 手順・
  maintenance window の記述を削り、要約 2 行 + 参照へ縮めた)。

### [Warning] 旧結論確認用の grep は migration docblock 自体にヒットするので成功条件にできない

- 判断: 対応する
- 対応内容: 「0 件であることの検査」ではなく
  **「許容される hit を明示し、それ以外を人が分類する確認」**だと書き換えた。
  許容は 3 つ (runbook の当該節 / drop migration の docblock の要約 /
  本設計ディレクトリ配下の記録)。**`docs/architecture.md` に hit が出たら必ず消す**ことも明記した。

## 施策 9

### [Warning] AGENTS.md の規約案に「処理も同じ述語を共有する」が残っている

- 判断: 対応する
- 対応内容: 提案文をほぼそのまま採用した。
  「**決着対象の定義は 1 つとする。組織の列挙・件数・監視は同じ述語を直接共有し、
  処理側は失効済みと寄与中の厳密な補集合となる 2 枝で実装する**
  (削除は 1 本の DELETE、集約は集約キーごとの GROUP BY で必要な形が違うため)。
  **補集合性は Feature テストと変異表が固定する**」へ書き換えた。

## 施策 1 / 2 / 3 / 5 / 6 / 7 / 8 / 10 と主キー取得 gate

- 判定: APPROVE。追加対応なし。
