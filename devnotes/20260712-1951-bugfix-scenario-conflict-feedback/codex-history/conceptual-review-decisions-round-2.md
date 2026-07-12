# 対応マトリクス: conceptual-review Round 2

## [Warning] 禁止事項/テスト: 403・汎用エラーでもフォーカス機構が働く不変条件が未登録
- 判断: 対応する
- 根拠: 禁止事項 1 (テストなし実装完了) の趣旨。フォーカス処理は全 kind 共通の単一処理だが、分岐網羅テストがなければ後退を検出できない
- 対応内容: テスト計画を「全 kind (conflict/forbidden/generic) の表示網羅 + 各分岐でのフォーカス/スクロール検証」に拡張。フォーカス発火は全 kind 共通の単一処理である旨も設計に明記

## [Warning] 実現可能性: focus() 既定スクロールとの競合
- 判断: 対応する
- 根拠: focus() はブラウザ既定で対象へスクロールするため、順序と preventScroll を固定しないと挙動が環境依存になる
- 対応内容: `focus({ preventScroll: true })` → `scrollIntoView({ block: "nearest" })` の順序を設計に明記。Vitest で呼び出し順も検証対象にする

## [Warning] 期待効果: 「ビューポート内に必ず表示」が期待効果セクションに残存
- 判断: 対応する
- 対応内容: 「操作点近傍に表示し、フォーカスおよび必要最小限のスクロールによって知覚可能性を高める」へ統一

## [Warning] 型安全性: union 導入だけでは Svelte テンプレートの分岐網羅を型検査できない
- 判断: 対応する
- 根拠: `{#if}` 分岐は kind 追加時にコンパイルエラーにならない
- 対応内容: 表示モデルを `$derived` の `switch (saveFailure.kind)` + `default: assertNever(saveFailure)` で導出する設計に変更 (網羅性をコンパイルエラーで固定)

## [Suggestion] scrollIntoView の「可視時 no-op」表現の正確化
- 判断: 対応する
- 対応内容: 「完全可視ならスクロールは原則発生せず、連続失敗時のジャンプを起こしにくい」に修正

## [Suggestion] $effect 不使用方針 / スコープ判断
- 判断: 現状維持 (肯定的評価)
