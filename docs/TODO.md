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
| T076 | 決済parity P5: 残高会計精緻化 | backend | per-bucket会計(clamp verbatim)+消費優先 | High | standalone | [設計](devnotes/20260717-0035-aigenba-billing-parity/) | 2026-07-17 02:12 |
| T077 | 決済parity P6: grant契機変更 | backend | 有効化時付与へ+LP文言修正 | High | standalone | [設計](devnotes/20260717-0035-aigenba-billing-parity/) | 2026-07-17 02:12 |
| T078 | 決済parity P7: 新規登録経路 | backend | IntendedPlanResolver+?plan=handoff | Medium | standalone | [設計](devnotes/20260717-0035-aigenba-billing-parity/) | 2026-07-17 02:12 |
| T079 | 決済parity P8a: 裏チャージ | backend | オートリチャージ+リコンサイル | Medium | standalone | [設計](devnotes/20260717-0035-aigenba-billing-parity/) | 2026-07-17 02:12 |
| T080 | 決済parity P8b: 課金UI parity | frontend | Plans/PlanCard/Pricing/per-bucket表示 | Medium | standalone | [設計](devnotes/20260717-0035-aigenba-billing-parity/) | 2026-07-17 02:12 |
| T085 | bfcache 実復元の iOS 実機受入確認 | test | Playwright 不可のため実機で確認+記録 | High | standalone | [設計](devnotes/20260803-0053-aigenba-alignment/) | 2026-08-03 03:10 |
| T081 | 決済parity P9: checkout冪等+請求先+PM流用 | backend | 冪等マシン+contact暗号化+T1004 | Low | standalone | [設計](devnotes/20260717-0035-aigenba-billing-parity/) | 2026-07-17 02:12 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
