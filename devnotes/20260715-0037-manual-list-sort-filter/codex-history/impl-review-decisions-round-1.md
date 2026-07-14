# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Critical / Warning ともになし)。以下は Suggestion 3 件の判断。

## [Suggestion] manualRows の updated_at を DTO 化して shape 固定
- 判断: 見送る
- 根拠: 現行は typed array PHPDoc + `?? ''` で string 確定しており PHPStan level 10 で shape は固定済み。設計 (施策 A) が typed array 継続を明示 (DTO 化は out-of-scope)。将来 null 許容へ戻す予定は無い。オーバーエンジニアリング禁止 (思考原則 2)。
- 対応内容: 変更なし。

## [Suggestion] LIKE エスケープを共通ヘルパ (titleLikePattern) に寄せる
- 判断: 見送る
- 根拠: 現状 PC (manualRows) / PWA (index) の 2 箇所のみ。`addcslashes($x,'%_\\')` の 1 行で自明。ヘルパ抽出は 3 箇所目が現れた時点で行う方が YAGNI に適う。ドリフトは本 diff で PWA を PC に統一済みで解消済み。
- 対応内容: 変更なし (将来 3 箇所目で再検討)。

## [Suggestion] Show.svelte の sort options を ManualSortOption 型で型付け
- 判断: 見送る
- 根拠: option value は空文字 ("" = 既定) を含むため `ManualSortOption` union そのものでは表現できず `ManualSortOption | ""` になる。現状 `{ value: string; label: string }` で vitest が option ラベルと GET クエリ値を固定しており実害なし。Codex も「現状でも実害なし」と明記。
- 対応内容: 変更なし。

## 結論
Round 1 で APPROVED。Suggestion は全て将来検討 (現状実害なし・設計スコープ外) として見送り、修正なしで Phase B (コミット) へ進む。
