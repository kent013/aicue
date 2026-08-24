# 詳細設計レビュー Round 4

Round 3 の残り 2 点 (デプロイ順序の正本関係 / AGENTS.md の述語共有の文言) を反映した。

---

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

---

## 修正後の詳細設計 — 変更した 4 箇所の差分 (該当節のみ)

### 施策 4: drop migration の docblock (該当部分)

 * ★**破壊条件の要約 (この 2 行だけをここに置く)**:
 *   **コード先行が必須**である (drop 先行にすると、まだ動いている旧コードの
 *   `MAX(carried_forward_through)` の集計と繰越行の INSERT が `Undefined column` で落ちる)。
 *   **drop 後に旧コードへ単純 rollback できない**。
 *   → **手順・rollback・maintenance window の判断の正本は
 *   `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**である。
 *   ここに手順を写さない (2 か所に書くと必ず食い違う)。
 * ★`down()` は列を戻すだけで**値は復元しない** (新形の繰越行は集約終端を `created_at` で
 *   表すので、復元すると嘘の値になる)。旧コードを再稼働させると既存の繰越行は
 *   「終端が未記録 (null)」として扱われる。

### 施策 4: 波及変更 / リスク節の正本関係

1012:  **正本関係を次のとおり固定する** —
1013-  **手順・rollback・maintenance window の判断の正本は
1014-  `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**。
1015-  **migration の docblock は「コード先行が必須」「drop 後に単純 rollback 不可」の
1016-  2 行だけを破壊条件の要約として持ち、詳細は runbook を参照する**
1017-  (migration を単独で読んだ人にも drop-first の危険が見えるようにするため)。
1018-  **`docs/architecture.md` は順序を一切書かず runbook を参照するだけ**にする。
1019-- **rollback の非対称**: `down()` は列を戻すが値は戻さない。旧コードへ戻すと
1020-  既存の繰越行は終端が null として扱われる。この事実を runbook に書く。

1544:- **デプロイ順序は「新コード → drop migration」に固定する**。
1545-  drop 先行は旧コードを壊し (旧コードが `carried_forward_through` を SELECT / INSERT する)、
1546-  **drop 後に旧コードへ単純 rollback することもできない** (戻すなら先に `down()` で列を戻す)。
1547-  **正本関係**: 手順・rollback の正本は
1548-  `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節。
1549-  **migration の docblock は破壊条件の 2 行だけを要約**として持つ (単独で読んでも危険が見える)。
1550-  **`docs/architecture.md` は順序を書かず runbook を参照するだけ**。
1551-- **dev DB への破壊操作はしない** (禁止事項 3)。`migrate:fresh` 等はエージェント判断で実行しない。

### 施策 9: 旧結論の全数点検の成功条件

1464:- **旧結論の残骸を全数で点検する**。実装の最後に
1465-  `grep -rn "順序制約\|migration 先行\|コード先行\|drop 先行" docs/ database/migrations/ devnotes/20260824-1019-ticket-ledger-carry-forward-v1/`
1466-  を走らせる。**これは「0 件であること」の検査ではない** — 順序に触れてよい場所が 2 つあるため、
1467-  **許容される hit を明示し、それ以外を人が分類する確認**である。
1468-  - 許容 1: `docs/billing-retention-runbook.md` の
1469-    「`carried_forward_through` 撤去のデプロイ順序」節 (手順の正本)
1470-  - 許容 2: drop migration の docblock (破壊条件の 2 行の要約)
1471-  - 許容 3: 本設計ディレクトリ配下の設計・レビュー履歴 (記録であり運用文書ではない)
1472-  上記以外に hit があれば消すか正本への参照へ書き換える。
1473-  とくに `docs/architecture.md` に hit が出たら**必ず消す** (順序を書かない側に決めたため)。
1474-- **「唯一の例外」の語も全数で点検する**。`grep -rn "唯一の例外" app/ tests/ docs/ AGENTS.md` を走らせ、
1475-  台帳に関する記述が「行の物理削除・残高スナップショットへの置換を行う唯一の経路」へ
1476-  限定されていることを確認する (「台帳への変更の唯一の経路」と読める記述を残さない)。

### 施策 9-a: AGENTS.md 規約 21 の決着対象の述語の文言

1437:    - **決着対象の定義は 1 つとする** (取引明細 + **失効した繰越行**。
1438-      寄与中の繰越行だけが対象外)。**組織の列挙・件数・監視は同じ述語を直接共有し、
1439-      処理側は「失効済み」と「寄与中」の厳密な補集合となる 2 枝で実装する**
1440-      (削除は 1 本の DELETE、集約は集約キーごとの GROUP BY で必要な形が違うため)。
1441-      **補集合性は Feature テストと変異表が固定する**。定義がずれると
1442-      「数えているのに処理されない行」が生まれ、`horizon` が恒久的に NG になる。
1443-    - **列を落とす migration はコード先行**である (drop 先行にすると旧コードが

上記 4 箇所以外は Round 3 提示分から変更していない。
