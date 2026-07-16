# 対応マトリクス: design-review Round 9（CHANGES_REQUESTED / Critical 0・Warning 4）

Critical 0。残件は記述統一のみで、全て対応した（設計ロジックの変更なし）。

- **[Warning] P3 Plan 集合節 / P4 非スコープ節の「personal・starter の再公開は P8b」** → **「Starter のみ P8b。Personal は
  P3 で公開済み」** へ統一。
- **[Warning] P4 一覧の矛盾（「personal + standard が露出」の直後に「personal/starter は false」）** →
  **「`personal` は true（P3 で公開済み）/ `starter` のみ false」** へ統一。
- **[Warning] P7 の `CreateNewUser`「signup grant 呼び出しは触らない」と `RegistrationTest`「signup grant 期待を維持」が
  P6 後の契約と矛盾** → **「P7 は P6 の後に入るため grant 呼び出しは既に存在しない（P6 で撤去済み。P7 で復活させない）」**、
  テストは **「登録時は未付与の期待を維持し、session キー（intended plan）の期待を追加」** へ修正。
