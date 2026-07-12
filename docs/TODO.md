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
| T011 | confirm-password 直アクセス500の修正(F-11) | backend | confirm直アクセス500をフォールバック | High | standalone | [設計](devnotes/20260712-0925-bugfix-auth-confirm-password-500/) | 2026-07-12 10:19 |
| T012 | コピー崩れの修正(F-01 APP_NAME未展開 / F-02 未翻訳キー) | frontend | APP_NAME展開+バリデーション訳キー補完 | Medium | standalone | [設計](devnotes/20260712-0926-bugfix-i18n-copy/) | 2026-07-12 10:19 |
| T013 | UX整備(F-03/F-06 feedback欠落・F-08 ナビ不統一・F-14 モバイル横スクロール) | frontend | 成功flash補完+ナビ統一+モバイル横スクロール対応 | Medium | standalone | [設計](devnotes/20260712-0953-bugfix-ux-feedback-nav-responsive/) | 2026-07-12 10:19 |
| T014 | 欠落UIの追加(F-10 リカバリコード再生成 / F-12 オーナー移譲) | frontend | 定義済みでUI欠落の2操作の画面追加 | Medium | standalone | [設計](devnotes/20260712-0949-missing-operation-ui/) | 2026-07-12 10:19 |
| T015 | bug-hunt基盤整備(F-05 Stripe fake配線・F-13 filamentアセット・seeder subscription) | test | bughunt基盤・Stripe/アセット/sub整備 | High | standalone | [設計](devnotes/20260712-0949-bugfix-bughunt-infra/) | 2026-07-12 10:19 |

完了した TODO は [TODO-closed.md](TODO-closed.md) を参照。

## Conditional (条件付き待機)

| ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
|---|---|---|---|---|---|---|---|---|
