# 対応マトリクス: design-review Round 3

## 施策 5
- [Critical] `Llm` 判定が全段へ漏れる → **対応する** (指摘どおり。段の切り分けという目的自体を壊す誤分類)。
  `LLM_ATTRIBUTABLE_STAGES = [Analysis, LlmEvidence]` に閉じ、他段では `Llm` を返さない。
- [Critical] ffprobe 非 0 終了を写像できない → **対応する** (観測値が引数に無いという指摘は正しい)。
  `bool $ffprobeFailed` を追加。`artifact` の 2 分岐を確定: 読み出し不能 = `Storage` /
  読めたが ffprobe 失敗 = `Render`。
- [Warning] 「成功した段は分類しない」を classifier テストで証明できない → **対応する**。
  `bool $stageSucceeded` を入力に加え、戻り値を **`?SmokeFailureClass`** にする
  (成功時 null)。呼び出し側の契約を型で表現し、単体テストで直接固定できるようにした
  (Codex が挙げた 3 案のうち 1 案目を採る)。

## 施策 9
- [Warning] 回帰ケース追加 → **対応する**。判定表を 12 行へ拡張し、
  fixture/capture 失敗 → `Unknown` (Llm に漏らさない負のコントロール 2 件)、
  artifact + readable + ffprobe failure → `Render`、artifact + unreadable → `Storage`、
  最終成功 → `null` を含めた。

## 施策 1/2/3/4/6/7/8
- APPROVE。変更なし (施策 8 は `Llm` / `Render` の意味を分類表どおり維持する旨を既に反映済み)。
