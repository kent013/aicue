# TODO — Closed / Obsoleted

<!--
運用規約:
- Open / Conditional の TODO は TODO.md を参照
- このファイルへの行追加は /app-todo-close スキル経由で行う
  - close:    TODO.md Open → Closed (完了日を記入。タイトル列に実装サマリーを追記してよい)
  - obsolete: TODO.md Open/Conditional → Obsoleted (廃止日と理由を記入)
- ID は再利用しない (採番は全テーブルを通した最大 ID + 1)
- テーブルが空になってもヘッダー行は残す
-->

Open リストは [TODO.md](TODO.md) を参照。

## Closed

| ID | タイトル | テーマ | 完了日 |
|---|---|---|---|
| T001 | AI-CUE ドメイン基盤(Category/VideoManual)。Category/VideoManual CRUD + Tier B スキーマ先取り (Enum/migration/Model/Factory/Service/Policy/route/UI/テスト一式)。cross-org {project} の FormRequest DB ルール存在オラクルを project.in-current-org middleware で封じる修正込み | backend | 2026-07-10 23:46 |
| T002 | シナリオ編集(document一括保存・楽観ロック)。scenario document 一括 PUT (expected_version 楽観ロック 409 + 行別 422 + protected キー拒否)、ScenarioService reconcile (id 保全・並べ替え・削除 cascade)、ScenarioEditor (Svelte 5 作業コピー編集・409 復帰は onSuccess で reseed・419 自動リトライ)。Codex impl-review Critical なし | backend | 2026-07-11 01:32 |

## Obsoleted

| ID | タイトル | テーマ | 廃止日 | 理由 |
|---|---|---|---|---|
