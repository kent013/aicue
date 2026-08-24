# 詳細設計レビュー Round 5

Round 4 の残り 1 点 (デプロイ順序の正本関係から `AGENTS.md` が漏れている) を、
**提案 2 (AGENTS.md に警告を残す)** で反映した。

---

# 対応マトリクス: design-review Round 4

## 施策 9

### [Warning] デプロイ順序の正本関係と全数点検から `AGENTS.md` が漏れている

- 判断: 対応する (**提案 2 を採用**)
- 根拠: 開発者が最初に読む規約で危険が見えることに意味がある。要約を消すのではなく、
  「要約を持つ場所」として正本関係に明示的に入れる方が実態と一致する。
- 対応内容:
  1. **正本関係を 4 層で明記**した。
     (1) `docs/billing-retention-runbook.md` の該当節 = **手順・rollback・maintenance window の正本** /
     (2) drop migration の docblock = **破壊条件の要約 2 行** /
     (3) **`AGENTS.md` 規約 21 = 破壊条件の要約 1 行** /
     (4) `docs/architecture.md` = **順序を書かず参照のみ**
  2. `AGENTS.md` 規約 21 の文面を「要約であること」+「正本は runbook の当該節」+
     「本書に手順を写さない」を明示する形へ書き換えた。
  3. 全数点検の grep の走査域へ **`AGENTS.md` を追加**し、許容一覧に
     「(3) `AGENTS.md` 規約 21 (破壊条件の要約 1 行)」を入れた。

### [Suggestion] 「順序に触れてよい場所が 2 つある」と許容 3 つの食い違い

- 判断: 対応する
- 対応内容: 「**運用文書として許容されるのは 3 つだけ**」+
  「**設計・レビュー履歴 (本設計ディレクトリ配下) は別枠** (記録であり運用文書ではない)」
  と書き分けた。

## 施策 1〜8 / 10 / 主キー取得 gate の非登録判断

- 判定: APPROVE。追加対応なし。

---

## 修正後の該当箇所 (変更した 4 箇所のみ)

### 1. 施策 4 波及変更: 正本関係 (4 層)
```
  (旧 `aggregateGroup()` が `MAX(carried_forward_through)` を SELECT し、
  旧 `carryForwardGroup()` が同列を INSERT するため `Undefined column`)。
  **正本関係を次のとおり固定する (4 層)** —
  1. **手順・rollback・maintenance window の判断の正本** =
     `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節
  2. **drop migration の docblock** = 「コード先行が必須」「drop 後に単純 rollback 不可」の
     **2 行だけ**を破壊条件の要約として持ち、詳細は runbook を参照する
     (migration を単独で読んだ人にも drop-first の危険が見えるようにするため)
  3. **`AGENTS.md` 規約 21** = 同じ**破壊条件の要約 1 行**を持ち、詳細は runbook を参照する
     (開発者が最初に読む規約で危険を見せる意味がある)
  4. **`docs/architecture.md`** = 順序を**一切書かず** runbook を参照するだけ
- **rollback の非対称**: `down()` は列を戻すが値は戻さない。旧コードへ戻すと
  既存の繰越行は終端が null として扱われる。この事実を runbook に書く。

---

```

### 2. 施策 4 リスク節: 正本関係 (4 層)
```
- **schema の歴史は残す**: 列を足した migration (2026_08_10_114500) は消さず、
  drop migration を新規に足す (消すと新規環境で drop が失敗する)。
- **デプロイ順序は「新コード → drop migration」に固定する**。
  drop 先行は旧コードを壊し (旧コードが `carried_forward_through` を SELECT / INSERT する)、
  **drop 後に旧コードへ単純 rollback することもできない** (戻すなら先に `down()` で列を戻す)。
  **正本関係 (4 層)**: 手順・rollback の正本は
  `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節。
  **drop migration の docblock と `AGENTS.md` 規約 21 は破壊条件の要約だけ**を持つ
  (単独で読んでも危険が見える)。**`docs/architecture.md` は順序を書かず runbook を参照するだけ**。
- **dev DB への破壊操作はしない** (禁止事項 3)。`migrate:fresh` 等はエージェント判断で実行しない。

## docs/template-divergence.md の登録/更新/削除の要否 (乖離台帳の確認段)

```

### 3. 施策 9: 旧結論の全数点検 (走査域と許容一覧)
```
- **旧結論の残骸を全数で点検する**。実装の最後に
  `grep -rn "順序制約\|migration 先行\|コード先行\|drop 先行" AGENTS.md docs/ database/migrations/ devnotes/20260824-1019-ticket-ledger-carry-forward-v1/`
  を走らせる。**これは「0 件であること」の検査ではない** — 順序に触れてよい場所があるため、
  **許容される hit を明示し、それ以外を人が分類する確認**である。
  - **運用文書として許容されるのは 3 つだけ**:
    (1) `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節
    (手順の正本) / (2) drop migration の docblock (破壊条件の要約) /
    (3) `AGENTS.md` 規約 21 (破壊条件の要約 1 行)
  - **設計・レビュー履歴 (本設計ディレクトリ配下) は別枠**である
    (記録であり運用文書ではないので、順序の記述が残っていてよい)
  上記以外に hit があれば消すか正本への参照へ書き換える。
  とくに `docs/architecture.md` に hit が出たら**必ず消す** (順序を書かない側に決めたため)。
```

### 4. AGENTS.md 規約 21 の該当行
```
    - **列を落とす migration はコード先行**である (drop 先行にすると旧コードが
      `Undefined column` で落ちる。これは破壊条件の要約であり、
      **順序・rollback・maintenance window の判断の正本は
      `docs/billing-retention-runbook.md` の「`carried_forward_through` 撤去のデプロイ順序」節**である。
      本書に手順を写さない)。
```

上記以外は Round 4 提示分から変更していない。
