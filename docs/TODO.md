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
| T110 | 認証手段変更のメール通知ポリシーの統一設計 | backend | passkey/2FA/SSO の増減通知を一貫したポリシーとして設計する(T108 S7 は監査ログのみで通知は見送り) | Low | standalone | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
| T112 | 監査フォロー A: PCRE /u 是正 + pgid race 修正 | test | \R の /u 欠落とpgid raceを是正 | High | incremental | [設計](devnotes/20260805-1813-audit-followup-maintenance/) | 2026-08-05 18:50 |
| T114 | 監査フォロー C: NFC/NFD index 是正 + 孤児DB回収経路 | infrastructure | index正規化と孤児DB回収 | Medium | standalone | [設計](devnotes/20260805-1813-audit-followup-maintenance/) | 2026-08-05 18:50 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
| T109 | MCP の idempotency replay をリソース解決より後へ | backend | AppMcpTool::handle() の replay 判定が runTool() より前。REST 側で api.project-in-org < idempotent として閉じたのと同型のハザードが構造的に残る(現時点で write tool 0 本のため実害なし) | MCP に write tool を 1 本でも追加するとき | Medium | incremental | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
