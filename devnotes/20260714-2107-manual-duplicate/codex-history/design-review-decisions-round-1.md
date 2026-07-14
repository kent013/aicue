# 対応マトリクス: design-review Round 1

## [Critical] 施策4: category 再解決 firstOrFail が race 時 404 で契約後退
- 判断: **反論（404 を維持）** + 明文化で緩和
- 根拠: 指摘は「既存 store 契約（422系）と合わせよ」だが、**既存 `VideoManualService::create()` は同じ race を `$locked->categories()->whereKey($categoryId)->firstOrFail()` で 404 にしている**（現行コード確認済み）。つまり 404 化こそが store/create の既存契約であり、422 に変える方が既存コードとの不整合になる。通常の不正・他 project category は FormRequest の `Rule::exists` で **422**（検証時）に落ち、404 は「検証通過後に category が削除/移動された」ごく稀な競合のみ。これは create と完全一致で後退ではない。前段の概念レビュー（gpt-5.4 Round 2）も同結論（race=404）。
- 対応内容: 設計に「firstOrFail(404) は既存 create() と同一挙動。通常不正は FormRequest で 422、404 は検証後競合のみ」と根拠を明記（値の変更はしない）。

## [Warning] 施策4: copyCuts の point 順序が全体順序依存
- 判断: 対応する（明確化）
- 根拠: 現状 `orderBy('sort_order')->orderBy('id')->get()` 後に `Collection::where('type', Point)` で filter。Eloquent Collection の filter は順序を保持するため、親内 point は sort_order 順に保たれる（CutSequencer と同順）。
- 対応内容: copyCuts のコメントに「initial orderBy(sort_order,id) を filter が保持するため親内 point 順序は sort_order 準拠」と明記。将来差分耐性のため step/point 抽出を `->where('type', ...)->sortBy(...)` でなく初期クエリ orderBy 依存であることを固定コメント化。

## [Warning] 施策4: 孤児 point skip がデータ破損を黙殺
- 判断: 対応する
- 根拠: skip は防御的だが観測不能だと不整合を見逃す。
- 対応内容: skip 時に `Log::warning('duplicate: orphan point skipped', ['manual_id'=>..., 'cut_id'=>...])` を出す。`use Illuminate\Support\Facades\Log;` 追加。施策9 に「孤児 point が複製されない + warning ログ」テストを 1 ケース追加。

## [Warning] 施策9: 共有ロック新経路の退行検知が薄い / adopted_take_id 非複製を両層で
- 判断: 対応する
- 根拠: reset 確認を step/point 両方で明示すると退行検知が強化される。
- 対応内容: Feature テストに「複製された step **および** point の adopted_take_id=null・cut_length_ms=null」を両層で明示 assertion 化。scenario_version=0/status=draft も維持。

## [Suggestion] 施策2: categoryId() の numeric string 許容コメント
- 判断: 対応する（軽微）
- 対応内容: `categoryId()` に「Select 由来の数値文字列も許容（nullOrIntegerish）」コメント追記。

## [Suggestion] 施策6/10: Select 固定で Number 変換が安全な旨コメント
- 判断: 対応する（軽微）
- 対応内容: DuplicateManualDialog の transform に「category は Select 固定値のため Number 変換は安全（'' のみ null）」コメント追記。
