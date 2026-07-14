# 対応マトリクス: conceptual-review Round 1

## [Critical] B: 導入/総括を step として見せる UX（手順番号誤認・番号ズレ）
- 判断: 対応する（期待効果の scope ダウン + 限界の明示）
- 根拠: 手順番号を intro/summary に譲ると SOP 由来の実手順が 手順2 以降にズレる。使命「思考ゼロ」に対し負債。ただし frontend へ type を通す（独立 CutType/flag）のは v1 データモデル(step/point 限定, doc/10 §10.1)を破る過剰実装。合議相手の代替案「v1 期待効果を教材構造補強に限定し、撮影ナビ改善までは主張しない」を採用。
- 対応内容: 期待効果を「レンダ動画に導入/総括の俯瞰＋字幕が必ず入る（教材構造の補強）」に限定。撮影ナビ手順番号の改善は主張しない。手順番号が intro に消費される点を「既知の v1 限界」として明記し、専用ラベル/独立 CutType はスコープ外（後続）と再確認。

## [Critical] D: 総括文面が「要点・安全ポイント再掲」を満たさない
- 判断: 対応する（過大評価の是正 + 非 LLM の決定的再掲を追加）
- 根拠: 「振り返りましょう」は締め句であって再掲でない。doc/03 §3.5 の総括=安全/要点再掲を名乗るなら決定的な再掲が要る。LLM を使わずとも生成済み cut から決定的抽出できる。
- 対応内容: 総括カットの subtitle_secondary を、生成済みカット（急所=point の subtitle_primary/scene）から決定的に連結した「要点再掲」で構成する（ScenarioLimits 上限内で truncate、件数上限）。導入は「作業名提示＋俯瞰」に v1 要件を明示し「設備と立ち位置」までは v1 要件外と文書化。

## [Warning] C: タイトル補間を lock 前に行うと一貫性が崩れる
- 判断: 対応する
- 根拠: 最終シナリオ確定に使う値（タイトル・再掲元 cut）はロック後の確定状態から読むべき。
- 対応内容: intro/summary の文面組み立ては finalize の locked manual（terminal tx 内・lockForUpdate 済み）を参照して行うと明記。AnalysisPipeline は「挿入する」意思決定のみ、実文面は locked phase で確定。

## [Warning] 再解析/編集後再 materialize/削除の round-trip 未定義
- 判断: 対応する
- 根拠: 「毎回 2 カットだけ」の利用者期待を壊さないため不変条件をテストで固定。
- 対応内容: materializeIntoLockedManual は全置換のため再生成で重複しない（全削除→再挿入）。不変条件「materialize 直後は先頭1・末尾1のみ・順序固定・重複なし」を Feature テストで固定（初回生成/再生成/手動 save 後再生成の 3 ケース）。手動編集後は通常 step として round-trip する v1 挙動を明記。

## [Warning] 型安全性: 生配列で足すと第2の非型付け生成経路が生まれる
- 判断: 対応する
- 根拠: PHPStan L10。既存 DTO を再利用すべき。
- 対応内容: 追加カットは既存 ScenarioStepInput（typed）を返す専用 builder で生成。array<string,mixed> の直組みを禁止。

## [Suggestion] A / 禁止事項1 / スコープ
- 判断: 対応する（テスト不変条件化）
- 対応内容: 構造保証を専用 Feature テスト（+ 必要なら Architecture テスト）で固定。ScenarioWritePathInventoryTest への追加は不要（新書き込み経路は増えない）。
</content>
