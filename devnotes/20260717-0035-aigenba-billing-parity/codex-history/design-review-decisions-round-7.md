# 対応マトリクス: design-review Round 7（CHANGES_REQUESTED / Critical 1・Warning 2）

## [Critical] Personal 公開契約が P8b rollback で壊れる（**F-07 変種の再発経路**）
- 判断: 対応する（指摘のとおり。「P8b は Starter のみ」と書きながら migration が両方を対象にしていた）
- 対応: **P8b の再公開 migration を Starter 専用に限定**:
  - 事前検証は **`code='starter'`** のみ（無ければ fail-closed）。更新も **`code='starter'` のみ**。
  - 末尾検証: `code='starter' AND is_active=true` が 1 件 + **`code='personal' AND is_active=true` が 1 件であることも検証**
    （P3 で公開済みの前提を守る。**ここでは更新しない**）。
  - **`down()` は `code='starter'` のみ false へ戻す（Personal は絶対に触らない）**。
  - `PlanSeeder` も **`starter` のみ** true 化（**P4 後に P8b だけ rollback すると無料導線が消えて F-07 変種が再発するため**）。

## [Warning] P3/P4 本文に旧契約が残る
- 判断: 対応する / 対応: P3 の「DB 列/index/migration: P3 は 0」→「**migration は 1 本 = `activate_personal_plan`**
  （`code='personal'` のみ true / Starter は false のまま）」へ。P3 リスク欄の「P3〜P8a は両方 false」と
  P4 の「Personal は P8b まで false・Standard のみ露出」→ **D10 改訂で解消**（Personal は P3 で公開済み、
  P4 時点で personal + standard が露出、Starter のみ P8b まで非公開）へ統一。

## [Warning] P6 の重複変更が未修正
- 判断: 対応する / 対応: 変更表を「**P1 で 2 引数化済み → P6 はコード変更なし・`signup_grant:` prefix 契約の回帰確認のみ**」へ。
  PHPStan 節の「追加引数」表現も「**P1 で導入済み。P6 での型変更はない**」へ。
