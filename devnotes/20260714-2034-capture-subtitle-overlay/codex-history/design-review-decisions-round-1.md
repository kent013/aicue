# 対応マトリクス: design-review Round 1

全体判定: **APPROVED**（全施策 APPROVE）。Warning を実装品質のため反映する。

## [Warning] S2: トグルに `aria-controls` が無い（観点7）
- 判断: 対応する
- 根拠: PasswordInput 先例（aria-controls で input を関連付け）の一貫性。a11y 向上。
- 対応内容: `SubtitleOverlay` ルート div に `id="subtitle-overlay-panel"` を付与、トグルボタンに `aria-controls="subtitle-overlay-panel"` を追加。OFF 時は overlay 非描画で id 対象が一時的に不在になるが `aria-pressed` が状態を補完（AT 許容）と設計に明記。

## [Warning] S4: 空白完全一致検証は不安定（観点4）
- 判断: 対応する
- 根拠: DOM 正規化・レンダラ差異でフレーク化。
- 対応内容: 「trim は空判定のみ」の検証を「`"  a  "` を渡して要素が描画される + `textContent` が `toContain("a")`」に変更。空白完全一致は避ける。`visible=false` で全 testid 不在も追加。

## [Warning] S5: アイコン名依存の検証は脆い（観点4）
- 判断: 対応する
- 対応内容: 主アサーションを `aria-pressed` / `aria-label` の状態遷移にし、アイコンは補助（存在確認のみ）。「disabled 不在 + 実クリック遷移」を同一ケースで確認し禁止事項 8 の証跡を強化。

## [Suggestion] S1: `secondary.trim()` を防御的に `(secondary ?? "").trim()`
- 判断: 採用（安価・将来安全）
- 対応内容: `hasSecondary = $derived((secondary ?? "").trim() !== "")` に変更。

## [Suggestion] S1: line-clamp 非対応時に max-h で強保証
- 判断: 見送り（現状維持）
- 根拠: line-clamp は主要モバイルブラウザで広くサポート。非対応時も `whitespace-pre-line` 折返し + `max-w` は効き致命破綻しない（ガイド用途で許容）。任意 max-h トークンは DS に無く、追加は過剰。

## [Suggestion] S3: selectedCut をローカル定数に束縛
- 判断: 見送り（現状維持）
- 根拠: 既存 `{#if selectedCut === null}{:else}` 構造に沿った non-null 参照で足りる。可読性目的の追加束縛は範囲外の微修正。

## [Suggestion] 極小画面の実測確認を必須化
- 判断: 採用済み（S2 に最小プレビュー寸法確認 + verify/手動を記載済み）。
