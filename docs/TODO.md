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
| T092 | 認証ファネルの離脱導線修復 | frontend | 踏破不能な CTA 撤去と離脱導線の規約化 | High | incremental | [設計](devnotes/20260804-0021-auth-funnel-exits/) | 2026-08-04 01:22 |
| T093 | /billing 着地 feedback の one-shot 化 | backend | 着地 query を畳み feedback を flash 化 | High | standalone | [設計](devnotes/20260804-0021-billing-feedback-oneshot/) | 2026-08-04 01:22 |
| T095 | 2FA 手動キー表示と破壊的操作の成功 toast 補完 | frontend | 2FA 手動キー表示と削除系 toast 補完 | Medium | incremental | [設計](devnotes/20260804-0021-ux-small-gaps/) | 2026-08-04 01:22 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
