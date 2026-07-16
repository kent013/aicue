## 施策判定

- P1/P2/P5/P7/P8a/P9: **APPROVE**
- P3/P4/P6/P8b: **REQUEST_CHANGES**

## Findings

- **[Critical] Personal 公開契約が P8b rollback で再び壊れます。** P8b は「Starter のみ」としつつ、migration が Personal/Starter の両方を更新し、`down()` も両方を `false` にします。P4 後にP8bだけ rollbackすると無料導線が消え、F-07変種が再発します。
  - **修正案:** P8b migrationをStarter専用にし、更新・残余検証・`down()`すべて `code='starter'` のみに限定。Personalは「P3でtrueであること」を検証するだけにしてください。

- **[Warning] P3/P4本文に旧契約が残っています。**
  - P3: 「DB列/index/migration: P3は0」
  - P3リスク: Personal/Starterともfalse、P4前に両方true
  - P4非スコープ・一覧・リスク: PersonalはP8bまでfalse、Standardのみ露出
  - **修正案:** P3 migration 1本、Personal=true、Starter=falseへ統一し、P4はPersonal公開済みを前提として記述してください。

- **[Warning] P6の重複変更が未修正です。** 変更表は依然として `grantSignupGrant()` のシグネチャ変更、PHPStan節も「追加引数」と記載しています。
  - **修正案:** P6ではコード変更なし・prefix契約の回帰確認のみと明記してください。

## 総合判定

**CHANGES_REQUESTED**

ロジック上の残件は、P8b rollbackがPersonalを非公開へ戻す点です。ここをStarter専用migrationへ直せば承認可能です。