# 対応マトリクス: impl-review Round 1

## [Critical] tests/js/pages/ManualsShow.test.ts に「複製する押下で /duplicate に POST」検証が無い (施策10 未達)
- 判断: 対応する
- 根拠: 施策10 の UI 契約 (POST 発火) を検証していないのは事実。ただし Show ページのテストで useForm を global mock すると、同ページに同居する SourceDocumentUpload 等の useForm 子コンポーネントの描画に副作用が及ぶリスクがある (reactiveUseForm は get/reset/put 等を持たないため)。
- 対応内容: POST 検証は dialog を単体マウントできる専用コンポーネントテスト `tests/js/components/features/manual/DuplicateManualDialog.test.ts` (新規) に置いた。useForm を `reactiveUseForm(init)` へ差し替え (init 尊重で prefill も観測)、生成フォームを holder に退避し、`複製する` 押下で `form.post` が `/projects/1/manuals/5/duplicate` を引数に 1 回呼ばれることをアサート。加えて prefill (title/category) と「必須未充足でも disabled にしない」も同テストで検証。3 test 追加・green。

## [Warning] Feature テスト: 元 cuts の id 保持を明示アサートしていない
- 判断: 対応する
- 根拠: 「元 manual の cuts は不変 (件数・id 保持)」とコメントしつつ件数のみ検証で id 保持が未アサート。要件文言との差分を埋める。
- 対応内容: seedScenario 直後に元 cut id 配列を退避し、複製後に `$source->cuts()->orderBy('id')->pluck('id')->all()` が一致することを 1 アサート追加。

## [Warning] copyCuts の step/point 2 段走査は同一 sort_order 混在時に全体順を厳密再現しない
- 判断: 見送る (現仕様で問題なし。将来の明文化は docblock 済み)
- 根拠: CutSequencer も「step を sort_order 順 → 直後に配下 point を sort_order 順」で並べ、複製もこれと同順を再現している (後続接続テストで検証済み)。step/point が同一 sort_order で「混在した全体順」を要求する仕様は存在しない (親子は type で層別)。過剰実装を避ける。

## [Suggestion] 各種 (categories 露出・順序・onclick 送信・孤児 skip 等)
- 判断: 対応不要 (Codex も妥当と評価)
- 根拠: いずれも設計意図どおりで Codex は肯定的コメント。
