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
| T057 | 撮影PWAのカメラ許可 (Permissions-Policy に camera/microphone=(self) 例外) | backend | 撮影ルートのみcamera/mic許可 | High | standalone | [設計](devnotes/20260715-1122-capture-permissions-policy/) | 2026-07-15 |
| T061 | published マニュアルでシナリオパネルが未作成表示に戻る不具合 | frontend | 確定相でシナリオ有り判定に修正 | Medium | incremental | [設計](devnotes/20260715-1153-published-scenario-panel/) | 2026-07-15 |
| T062 | 撮影テイク行の mobile 375px レイアウト崩れ修正 | frontend | テイク行のmobile崩れ修正 | Medium | incremental | [設計](devnotes/20260715-1158-capture-take-row-mobile/) | 2026-07-15 |
| T063 | 未確定2FAメンバーに「2FA解除」ボタンを表示しない | frontend | 確定2FAのみ解除ボタン表示 | Medium | incremental | [設計](devnotes/20260715-1211-twofa-unconfirmed-reset-button/) | 2026-07-15 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
