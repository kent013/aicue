# 対応マトリクス: design-review Round 2

全体判定: **CHANGES_REQUESTED**（S2 のみ）。`aria-controls` 削除で APPROVED になる。

## [Warning] S2: `aria-controls` が条件付き描画で不在 IDREF を参照 + 固定 id 重複リスク
- 判断: 対応する（Round 1 で自ら入れた aria-controls を撤回）
- 根拠: 妥当。overlay は `{#if shown}` で条件付き描画のため OFF/字幕なし時に `subtitle-overlay-panel` が DOM に存在せず IDREF が切れる。固定 id は CameraRecorder 複数インスタンス時に重複。トグルの状態・目的は `aria-pressed` + 状態連動 `aria-label` で十分。
- 対応内容: トグルボタンから `aria-controls` を削除、`SubtitleOverlay` ルートから `id="subtitle-overlay-panel"` を削除。設計本文（S1 設計・S2 DS チェック）に「aria-controls は使わない」理由を明記。

その他（S1/S3/S4/S5 の Warning/Suggestion 反映）は Round 1 で妥当と確認済み。追加の Critical/Warning なし。
