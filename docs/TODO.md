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
| T052 | capture.manuals.sync のフロント配線 or 廃止判断 | general | sync endpoint を配線せず廃止・inventory/doc 整合 | Medium | standalone | [設計](devnotes/20260715-0021-capture-sync-wire-or-remove/) | 2026-07-15 |
| T053 | 動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示 | backend | 一覧に並替(PC)/自作filter/作成者・更新日メタ追加 | Medium | standalone | [設計](devnotes/20260715-0037-manual-list-sort-filter/) | 2026-07-15 |
| T054 | PC編集面から該当マニュアルの撮影ナビ面への文脈リンク | frontend | 編集面から撮影ナビへ文脈リンク追加(純フロント) | Low | incremental | [設計](devnotes/20260715-0048-pc-capture-context-link/) | 2026-07-15 |
| T055 | 招待経由登録フォームでの招待メールアドレス自動入力 | frontend | 招待メールを登録フォームにprefill(readonly) | Low | incremental | [設計](devnotes/20260715-0100-invite-email-prefill/) | 2026-07-15 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
