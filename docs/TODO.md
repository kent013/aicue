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
| T085 | bfcache 実復元の iOS 実機受入確認 | test | Playwright 不可のため実機で確認+記録。**T178 (同期判定の前置) のマージ後に実施する** — guard 本体・プローブ応答契約・秘匿状態の語彙が変わり、`docs/supported-browsers.md` の実機受入確認の再確認条件に当たるため。失効セッション経路の観測は「秘匿を維持したまま読み直す」に変わるが、合格終端 (`unauthenticated-redirected` = /login 到達の目視確認込み) は変わらない | High | standalone | [設計](devnotes/20260803-0053-aigenba-alignment/) | 2026-08-03 03:10 |
| T110 | 認証手段変更のメール通知ポリシーの統一設計 | backend | passkey/2FA/SSO の増減通知を一貫したポリシーとして設計する(T108 S7 は監査ログのみで通知は見送り) | Low | standalone | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
| T182 | 動画一覧の再生時間表示と行内操作 | frontend | 一覧に再生時間とDL/削除の行内操作を追加 | Medium | standalone | [設計](devnotes/20260816-1021-manual-list-duration-and-row-actions/) | 2026-08-16 11:02 |
| T183 | テイクのサムネイル生成 | backend | テイク登録後にサムネイルを生成し配信する | Medium | standalone | [設計](devnotes/20260816-1021-take-thumbnail-generation/) | 2026-08-16 11:02 |
| T184 | PC 側のテイク選択・採用画面 | frontend | PCでテイクを確認し採用・追加できる画面 | High | standalone | [設計](devnotes/20260816-1026-pc-take-selection-and-adoption/) | 2026-08-16 11:02 |
| T185 | シナリオ行とテイクのドラッグ&ドロップ並べ替え | frontend | 手順行とテイクをD&Dで並べ替え可能にする | Low | standalone | [設計](devnotes/20260816-1021-drag-and-drop-reordering/) | 2026-08-16 11:02 |
| T186 | 横持ち全画面撮影とカット間スワイプ | frontend | 横持ちで全画面撮影しスワイプでカット移動 | Medium | incremental | [設計](devnotes/20260816-1021-landscape-fullscreen-capture/) | 2026-08-16 11:02 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
| T109 | MCP の idempotency replay をリソース解決より後へ | backend | AppMcpTool::handle() の replay 判定が runTool() より前。REST 側で api.project-in-org < idempotent として閉じたのと同型のハザードが構造的に残る(現時点で write tool 0 本のため実害なし。**起票条件は T139 の trip-wire `McpWriteToolIdempotencyEnforcementTest` が機械化済み** = 最初の write tool 追加で赤くなり必要作業が失敗メッセージで提示される) | MCP に write tool を 1 本でも追加するとき | Medium | incremental | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
| T127 | 既定キュー接続の分割 (課金系を別接続へ) | infrastructure | 短命ジョブと Stripe 課金ジョブで retry_after を分ける。T122 で database の retry_after を 90→600 にした代償として、短命ジョブの回収が最大 510 秒遅れる | その回収遅延が実害として観測されたとき(滞留の苦情・監視アラート) | Medium | standalone | [設計](devnotes/20260806-1635-queue-lease-timeout/) | 2026-08-06 18:40 |
| T128 | CI に workflow_dispatch を追加 | infrastructure | T123 で on.schedule を除去した結果、供給網監査を手動で叩く口が無い。追加する場合は W12 のトリガー集合への登録が gate により必須 | CI 外の定期実行枠組み(オーナー側の宿題)が決まったとき | Low | incremental | [設計](devnotes/20260806-1634-ci-schedule-removal/) | 2026-08-06 18:40 |
