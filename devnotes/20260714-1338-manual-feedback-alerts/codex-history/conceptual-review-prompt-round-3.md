# conceptual-review Round 3

Round 2 の [Warning] (justSaved の状態遷移矛盾) を修正しました。

## 修正内容: justSaved の状態遷移を固定
- **true にするのは `applySaved()` (保存成功パス) のみ。**
- **false にする**: `save()` 開始 / 保存失敗 (`saveFailure` set) / dirty へ転じた瞬間 (編集) / 初期化 /
  `reseed()` (理由を問わず = 409 競合後・明示リロード後も含む)。
- 実装順序で保証: `reseed()` は常に `justSaved = false` を行い、`applySaved()` は `reseed()` を呼んだ
  **後**に `justSaved = true` を立てる。→ 保存成功のみ true、409/明示リロード reseed は false。

これで「409 競合後に偽の成功表示」は起こりません。他の観点は Round 2 で APPROVE 相当と確認済みです。
残懸念が無ければ APPROVED をお願いします。
