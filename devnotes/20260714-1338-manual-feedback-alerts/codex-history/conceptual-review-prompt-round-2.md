# conceptual-review Round 2

Round 1 の指摘への対応を反映しました。対応マトリクスと更新点は以下です。

## 対応サマリー

### [Warning] F-1-2 単一 startError は同時保持できない → 対応
- `startError` を **source 別 2 state** に分離: `renderStartError: StartError | null` /
  `previewStartError: StartError | null`。`type StartError = { message: string; showPurchaseLink: boolean }`。
- render 起動エラーは「完成動画」小節、preview 起動エラーは preview 小節に表示。購入導線も source 別に載せる。

### [Warning] title が source だけでは 起動失敗 vs ジョブ失敗 を識別できない → 対応 (phase-aware title)
- 完成動画: 起動失敗=「完成動画の生成を開始できませんでした」/ ジョブ失敗=「完成動画の生成に失敗しました」
- プレビュー: 起動失敗=「プレビューの生成を開始できませんでした」/ ジョブ失敗=「プレビューの生成に失敗しました」

### [Warning] 型安全性 → 対応
- 明示 2 プロパティ構成を採用 (Record より局所差分が小さく参照が素直)。`StartError` を component 内に型定義。

### [Suggestion] justSaved のクリア条件を明文化 → 対応
- クリア条件を明記: (1) dirty 転換, (2) 次の save() 開始, (3) saveFailure set, (4) reseed(409/明示リロード)。
  実質「reseed 成功で true・それ以外の状態遷移で false」。

### [Suggestion] showPurchaseLink を source 別に → 対応 (上記 StartError に内包)。

### [Suggestion] 「1 小節 最大 1 alert」主張が弱い → 主張を修正
- 「各 alert が source+phase の見出しを持ち帰属が一義」に置換。「最大 1」の記述は削除。

## 更新後の該当セクション (conceptual-design.md 抜粋)

### F-1-1 (改善アイデア)
- 既存 success toast は維持。加えて「シナリオを更新」ボタン横に、dirty インジケータの鏡像として
  永続的な「保存しました」インジケータを出す (`!dirty && justSaved` のとき)。
- `justSaved`: reseed 成功で true、上記 4 条件で false (level-triggered)。backend flash は追加しない
  (409 JSON/XHR 契約維持)。

### F-1-2 (改善アイデア)
- source 別 2 state (`renderStartError`/`previewStartError`) + phase-aware title。
- render 起動エラーは完成動画小節、preview 起動エラーは preview 小節。
- 全 danger Alert に上記 phase-aware title を付与。各 alert が source+phase で帰属一義。

これらで Round 1 の Warning はすべて解消したと考えます。残る懸念があれば指摘してください。無ければ
APPROVED をお願いします。
