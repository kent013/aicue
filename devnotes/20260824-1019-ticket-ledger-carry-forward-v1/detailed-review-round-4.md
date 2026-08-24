# 全体判定: CHANGES_REQUESTED

実装設計は承認可能です。残っているのは、デプロイ順序の正本関係における `AGENTS.md` の扱い1点だけです。

## 施策別判定

- 施策1: APPROVE
- 施策2: APPROVE
- 施策3: APPROVE
- 施策4: APPROVE
- 施策5: APPROVE
- 施策6: APPROVE
- 施策7: APPROVE
- 施策8: APPROVE
- 施策9: REQUEST_CHANGES
- 施策10: APPROVE
- 主キー取得 gate の非登録判断: APPROVE

## 施策9の残存指摘

- [Warning] 正本関係と全数点検から `AGENTS.md` が漏れています。

  正本関係は現在、次の3層とされています。

  - runbook: 詳細手順の正本
  - drop migration: 破壊条件2行の要約
  - architecture: runbook への参照だけ

  しかし `AGENTS.md` 規約21にも次の順序が残っています。

  > 列を落とす migration はコード先行である  
  > drop 先行にすると旧コードが落ちる

  つまり実際には `AGENTS.md` も破壊条件の要約を持つ4つ目の場所です。一方、全数点検コマンドは `AGENTS.md` を走査せず、許容一覧にも含めていません。

  修正案はどちらかです。

  1. 重複を減らす場合

     `AGENTS.md` を次のような参照だけに変更します。

     ```text
     `carried_forward_through` の drop に関するデプロイ順序と rollback は
     `docs/billing-retention-runbook.md` の手順節を必ず参照する。
     ```

  2. AGENTS.md に警告を残す場合

     - 全数点検コマンドへ `AGENTS.md` を追加
     - 許容一覧へ「許容4: AGENTS.md 規約21の破壊条件要約」を追加
     - 正本関係を「runbookが詳細手順の正本、migrationとAGENTS.mdが破壊条件の要約、architectureは参照のみ」と明記

  開発者が最初に読む規約で危険を見せる意味があるため、私は2を推奨します。

また、「順序に触れてよい場所が2つある」としながら許容を3つ列挙している箇所は、「運用文書上は2つ、設計・レビュー履歴は記録として別枠」と書くと明確です。

この1点を整合させれば、全体判定は `APPROVED` です。