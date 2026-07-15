# 対応マトリクス: conceptual-review Round 1

判定: CHANGES_REQUESTED（Critical なし。Warning 5 / Suggestion 多数）

## [Warning] 禁止事項8境界の明文化（観点2）
- 判断: 対応する
- 対応内容: 「disabled は form.processing だけを理由に使い、入力未充足では使わない」を制約セクションに明記。

## [Warning] テスト観点が概念設計に欠落（観点2 / 禁止事項1）
- 判断: 対応する
- 対応内容: 「テスト計画（概要）」節を追加。close / 多重送信抑止 / 再オープン seed+error clear / 既存禁止事項8維持の 4 テストをスコープ内に明記。

## [Warning] 期待効果の言い切り過ぎ（観点4）
- 判断: 対応する
- 対応内容: 「少なくとも同一画面・同一 UI インスタンス上の accidental re-submit を防ぐ。サーバ側冪等化は別タスク」と表現を限定。

## [Warning] $effect の defaults 追従が粗い（観点5）
- 判断: 対応する
- 対応内容: seed 契機を open の false→true エッジに限定。prevOpen 検知 or 明示 seedFromDefaults() に寄せ、依存は boolean open のみ、form 全体を effect 依存にしない旨を明記。open=true 中の props 変化で入力途中値を上書きしないことを固定。

## [Warning] 再オープン時に errors 等の基準状態が古いまま（観点5）
- 判断: 対応する
- 対応内容: seed 時に form.clearErrors() でエラー状態も初期化することを要件化。

## [Suggestion] Inertia preserveState 前提の妥当性 / onSuccess・onError 切替 / 型の閉じ込め
- 判断: 反映（設計の裏付けとして採用）
- 対応内容: seedFromDefaults を型付き小関数に閉じ、defaultCategory: number|null を崩さない旨を明記。Inertia の onSuccess/onError 切替前提は現行実装通りで妥当と確認。
