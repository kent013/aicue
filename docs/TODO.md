# TODO リスト

<!--
運用規約 (app-todo-add / app-todo-close スキルが操作する):
- このファイルには Open / Conditional のみを置く。完了・廃止した TODO は
  TODO-closed.md (Closed / Obsoleted) へ移動する (/app-todo-close の責務)
- ID 採番: T001 から連番。Open / Conditional / Closed / Obsoleted 全体を通した
  既存最大 ID + 1 を採番する (/app-todo-add の責務)
- 追加には devnotes/{design_dir}/ に conceptual-design.md と detailed-design.md の
  両方が存在することが必須 (なければ Reject)
- テーマ: frontend / backend / infrastructure / test / docs / general
- 優先度: Critical / High / Medium / Low
- モード: incremental (他施策と並行可) / standalone (個別実装セッション)
- 設計列: [設計](devnotes/{design_dir}/) 形式のディレクトリリンク
- Conditional はトリガー条件を満たしたら Open へ昇格させてから着手する
  (Conditional の直接クローズ不可。obsolete は可)
- テーブルが空になってもヘッダー行は残す
-->

## Open

| ID | タイトル | テーマ | 概要 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|
| T085 | bfcache 実復元の iOS 実機受入確認 | test | Playwright 不可のため実機で確認+記録 | High | standalone | [設計](devnotes/20260803-0053-aigenba-alignment/) | 2026-08-03 03:10 |
| T096 | SOP PDF の文字化けが LLM に素通りする問題の是正 | backend | SJIS 化けの復元と日本語本文ゲート | Critical | standalone | [設計](devnotes/20260804-0900-sop-pdf-mojibake/) | 2026-08-04 09:46 |
| T097 | T089/T090 の残存リスク 6 論点の確定と最小実装 | general | 未確定 6 論点を決定し恒久文書へ固定 | High | standalone | [設計](devnotes/20260804-0900-t089-t090-residual-risk/) | 2026-08-04 09:46 |
| T098 | bug-hunt driver に一過性フィードバック捕捉を追加 | test | toast 誤検知を probe で構造的に防ぐ | Medium | standalone | [設計](devnotes/20260804-0900-bughunt-toast-capture/) | 2026-08-04 09:46 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
