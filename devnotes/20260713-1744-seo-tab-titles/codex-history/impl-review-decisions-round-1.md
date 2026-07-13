# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Round 1)。Critical / Warning はゼロ。

## [Suggestion] config/seo.php コメントの運用契約 (SeoManagerTest が固有 title を固定)
- 判断: 見送る (対応不要)
- 根拠: Codex は「このままで問題なし」と評価。既にコメントで drift 追随の運用契約を明示済み。追加変更不要。

## drift 検出の設計逸脱 (config dot 記法 → 配列リテラルキー参照)
- 判断: 対応済み (実装時に既に修正)
- 根拠: route name が dot を含むため `config("seo.app_titles.{$routeName}")` は Laravel の dot 解釈で null になる。`config('seo.app_titles')` を取得し `Assert::isArray()` で narrow 後リテラルキー参照する形に修正。Codex も「妥当かつ必要な逸脱」と承認。
- 対応内容: `$appTitles = config('seo.app_titles'); Assert::isArray($appTitles); expect($appTitles[$routeName] ?? null)->toBe($expectedFragment);`

## 結論
APPROVED につき合議ループ終了 (Round 1 で確定)。修正なしで Phase B (コミット) へ進む。
