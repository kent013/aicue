# 対応マトリクス: impl-review Round 2 (item2)

## [Warning] scenario_version 契約が write を保証していない (containsScenarioVersionToken は read でも真)
- 判断: 対応
- 根拠: 妥当。displayXxxJob の read があるため token 出現では write を区別できず、
  `'scenario_version' => 0` を消してもテストが pass してしまう。
- 対応内容: 検出 4b (containsAdoptedTakeIdWrite) と同型の `containsScenarioVersionWrite()` を
  scanner に追加 (配列キー `'scenario_version' =>` / プロパティ `->scenario_version =` の write 形のみ検出、
  read は非検出)。契約テストを `containsScenarioVersionToken` → `containsScenarioVersionWrite` に変更。
  併せて scanner 自己検証テスト (array write / property write=true、read / comment=false) を追加。

## [Suggestion] テスト名/コメントを検査範囲 (ファイル全体) に合わせる
- 判断: 対応
- 対応内容: テスト名を「VideoManualService に status/scenario_version の明示 write が実在する」に変更し、
  コメントも「VideoManualService 内に write 形が実在」と検査範囲に一致させた。
