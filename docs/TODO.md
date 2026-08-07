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
| T124 | 2FA 秘密を返す GET の recent-auth 化 | backend | 秘密 GET 3 本に step-up を要求する | Medium | standalone | [設計](devnotes/20260806-1403-throttle-coverage-inventory/) | 2026-08-06 18:40 |
| T125 | inline throttle 群の bucket 共有の見直し | backend | 同一 actor が 1 bucket 共有の是正 | Medium | standalone | [設計](devnotes/20260806-1634-throttle-unauthenticated-get/) | 2026-08-06 18:40 |
| T126 | 外部 SDK の client timeout を pin する | backend | Stripe/AWS SDK の待ち上限を固定 | Medium | incremental | [設計](devnotes/20260806-1635-queue-lease-timeout/) | 2026-08-06 18:40 |
| T129 | Inertia の 4xx/5xx を Error 画面へ差替 | backend | Inertia XHR のエラーを Error 画面へ差替 | High | incremental | [設計](devnotes/20260807-1235-inertia-error-screen-contract/) | 2026-08-07 12:35 |
| T130 | テストレーンの HTTP 出口既定拒否 | test | テストレーンのHTTP出口を既定拒否 | Medium | standalone | [設計](devnotes/20260807-1235-stray-http-egress-deny/) | 2026-08-07 12:35 |
| T131 | ジョブ二重実行の所有権再検証を追加 | backend | ジョブ二重実行の所有権再検証と横断gate | High | incremental | [設計](devnotes/20260807-1235-job-execution-dedup/) | 2026-08-07 12:35 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
| T109 | MCP の idempotency replay をリソース解決より後へ | backend | AppMcpTool::handle() の replay 判定が runTool() より前。REST 側で api.project-in-org < idempotent として閉じたのと同型のハザードが構造的に残る(現時点で write tool 0 本のため実害なし) | MCP に write tool を 1 本でも追加するとき | Medium | incremental | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
| T127 | 既定キュー接続の分割 (課金系を別接続へ) | infrastructure | 短命ジョブと Stripe 課金ジョブで retry_after を分ける。T122 で database の retry_after を 90→600 にした代償として、短命ジョブの回収が最大 510 秒遅れる | その回収遅延が実害として観測されたとき(滞留の苦情・監視アラート) | Medium | standalone | [設計](devnotes/20260806-1635-queue-lease-timeout/) | 2026-08-06 18:40 |
| T128 | CI に workflow_dispatch を追加 | infrastructure | T123 で on.schedule を除去した結果、供給網監査を手動で叩く口が無い。追加する場合は W12 のトリガー集合への登録が gate により必須 | CI 外の定期実行枠組み(オーナー側の宿題)が決まったとき | Low | incremental | [設計](devnotes/20260806-1634-ci-schedule-removal/) | 2026-08-06 18:40 |
