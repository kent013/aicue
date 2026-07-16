# 対応マトリクス: design-review Round 10（CHANGES_REQUESTED / Critical 0・Warning 2）

Critical 0。残っていた記述矛盾 2 箇所を修正（設計ロジックの変更なし）。

- **[Warning] P3 Plan 集合節**: 「P8b の再公開 data migration（`personal`/`starter` を `is_active=true` へ）で card と step が
  同時に露出する」→ **「Personal は P3 の `activate_personal_plan` で `is_active=true` になり、card も自己申告 step も P3 完了時点で
  露出する（= P4 のゲート反転の前提条件）。**Starter のみ** P8b の再公開 data migration（`code='starter'` のみ）で card が露出する」** へ。
- **[Warning] P4 非スコープ節**: 「`personal`・`starter` の `is_active=true` **Starter** の再公開」という旧新混在文言 →
  **「**Starter の再公開（`is_active=true`）のみ**（D10 の解除 = P8b。**Personal は P3 の `activate_personal_plan` で公開済み**）」** へ置換。
