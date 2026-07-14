# 対応マトリクス: conceptual-review Round 1

## [Critical] フロント回帰テストが不足（PHP assertion 追加だけ）
- 判断: 対応する
- 根拠: フロント起因の不具合はフロントで回帰固定すべき。禁止事項1(テストなし完了)回避。
- 対応内容: 概念設計に「テスト計画(フロント回帰)」節を新設。`AdminUsers.test.ts` に4ケース明記:
  (1) 拒否時に対象行 Select 表示値が権威値へ戻る (2) 拒否時に対象行のみ aria-invalid
  (3) 成功時に新ロール反映 (4) 他行にエラーが出ない。`vi.mock("@inertiajs/svelte")` で router.patch を制御。

## [Warning] どの行の失敗か識別できない懸念（errors.role が複数行に出る）
- 判断: 対応する（既に設計に内在するが明示不足）
- 根拠: 現行コードは既に `roleErrorMemberId` で失敗行を1つに限定し、`roleErrorMemberId === member.id && pageErrors.role` で表示している。errors.role は文言ソース、表示対象はローカル状態で絞る = Codex 提案と一致。
- 対応内容: 設計に「失敗行の特定」節を追加し、`roleErrorMemberId` の役割(文言=errors.role / 対象=ローカル状態)を明示。

## [Warning] {#key} remount のフォーカス喪失
- 判断: 対応する
- 根拠: 支援技術/キーボード操作でエラーが伝わらないのは UX 後退。
- 対応内容: remount 後に `await tick()` → 当該 Select へフォーカス復帰。加えて Select に aria-invalid(error prop)と aria-describedby(FormError の id)を接続し読み上げ対象化。設計に「アクセシビリティ・フォーカス」節を追加。

## [Warning] 同一行の連打で古いエラー応答による remount 競合
- 判断: 対応する（既存ガードで担保、明示する）
- 根拠: 現行 `changingRole`(グローバル boolean)が in-flight 中の再操作を全行で抑止し、常に in-flight は1件 = stale 応答レースは発生しない。
- 対応内容: 設計に「送信直列化」を明記。`changingRole` は onFinish まで再入を防ぐ旨を記述。

## [Suggestion] 用語「controlled-input 欠陥」は Svelte 文脈で誤解を招く
- 判断: 対応する
- 対応内容: 「一方向 value 伝播と DOM 選択状態の乖離」に表現を統一。

## [Suggestion] 行単位状態を明示的に型付け
- 判断: 対応する
- 対応内容: `Record<number, number>`(member.id -> remount token) / `number | null`(roleErrorMemberId) と型を設計に明記。

## [Suggestion] 既存 Feature テストへ success flash なし/ロール不変を追加
- 判断: 対応する（既に方針に含む、維持）
- 対応内容: バックエンド回帰固定として `ConsoleRoleTransitionTest` に assertion 追加を継続。
