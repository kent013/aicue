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
| T040 | manual画面のシナリオ保存トースト帰属確認とrender/preview失敗alertの発生源明示 | frontend | scenario保存成功のその場残留インジケータ追加 + render/preview失敗alertをsource+phaseで帰属明示 | Medium | incremental | [設計](devnotes/20260714-1338-manual-feedback-alerts/) | 2026-07-14 |
| T042 | 軽微UI: manage/usersのタブレット名切れとsettingsのパスワード表示トグル | frontend | タブレット名切れ改善+パスワード表示トグル追加。概念設計はCodex(gpt-5.4) Round1でAPPROVED、詳細設計はCodex(gpt-5.3-codex) Round2でAPPROVED。設計のみ(実装・TODO登録なし)。 | Low | incremental | [設計](devnotes/20260714-1338-minor-ui-polish/) | 2026-07-14 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
