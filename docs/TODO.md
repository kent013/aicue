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
| T167 | declare(strict_types=1) の全数強制 gate 新設 | test | strict_types 宣言の全数 gate 新設 | Medium | standalone | [設計](devnotes/20260815-1534-strict-types-baseline-gate/) | 2026-08-15 16:18 |
| T168 | 禁止する文 (echo / goto / global / 開始タグ付き出力記法) の字句走査 gate | test | 禁止する文の字句走査 gate を追加する | Medium | incremental | [設計](devnotes/20260815-1537-forbidden-statement-token-gate/) | 2026-08-15 16:18 |
| T169 | プロンプトインジェクション防御の窓口方式一式を追加する | backend | LLM プロンプト防御の窓口方式一式を追加 | High | standalone | [設計](devnotes/20260815-1537-prompt-injection-defense/) | 2026-08-15 16:18 |
| T170 | worktree へ供給する秘密ファイルの作成時モード確定 (t1 化) | infrastructure | worktree 秘密ファイルを 0600 供給 | Medium | standalone | [設計](devnotes/20260815-1536-worktree-secret-file-mode/) | 2026-08-15 16:18 |
| T171 | 滞留回収 5 経路を共通基盤へ寄せ替える (AG-083 標準形 v1) | backend | 滞留回収5経路を共通基盤へ寄せ替え | Medium | standalone | [設計](devnotes/20260815-1538-stuck-job-recovery/) | 2026-08-15 16:18 |
| T172 | .claude/settings.json の常設 hook 配線とコード索引更新 hook の新設 | infrastructure | hook 常設配線と索引更新 hook 新設 | Medium | standalone | [設計](devnotes/20260815-1539-claude-hooks-settings-wiring/) | 2026-08-15 16:18 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
| T109 | MCP の idempotency replay をリソース解決より後へ | backend | AppMcpTool::handle() の replay 判定が runTool() より前。REST 側で api.project-in-org < idempotent として閉じたのと同型のハザードが構造的に残る(現時点で write tool 0 本のため実害なし。**起票条件は T139 の trip-wire `McpWriteToolIdempotencyEnforcementTest` が機械化済み** = 最初の write tool 追加で赤くなり必要作業が失敗メッセージで提示される) | MCP に write tool を 1 本でも追加するとき | Medium | incremental | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
| T127 | 既定キュー接続の分割 (課金系を別接続へ) | infrastructure | 短命ジョブと Stripe 課金ジョブで retry_after を分ける。T122 で database の retry_after を 90→600 にした代償として、短命ジョブの回収が最大 510 秒遅れる | その回収遅延が実害として観測されたとき(滞留の苦情・監視アラート) | Medium | standalone | [設計](devnotes/20260806-1635-queue-lease-timeout/) | 2026-08-06 18:40 |
| T128 | CI に workflow_dispatch を追加 | infrastructure | T123 で on.schedule を除去した結果、供給網監査を手動で叩く口が無い。追加する場合は W12 のトリガー集合への登録が gate により必須 | CI 外の定期実行枠組み(オーナー側の宿題)が決まったとき | Low | incremental | [設計](devnotes/20260806-1634-ci-schedule-removal/) | 2026-08-06 18:40 |
