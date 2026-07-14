# 対応マトリクス: conceptual-review Round 2

## [Warning] locked phase の再掲元カット読み取りが不明確（旧シナリオ総括の危険）
- 判断: 対応する
- 根拠: materialize 前に新 cuts は未存在。既存 relation から読むと再生成時に旧シナリオを総括する。
- 対応内容: 総括は「今回生成の list<ScenarioStepInput>（toScenarioSteps() 戻り値）」から抽出すると明記。DB 既存 cuts は参照しない設計に固定。

## [Warning] 責務記述の相互矛盾
- 判断: 対応する
- 対応内容: terminal tx 内で ScenarioBookendBuilder::wrap($lockedManual, $generatedSteps): list<ScenarioStepInput> を呼び、戻り値を materializeIntoLockedManual() に渡す、と呼び出し位置・責務を一本化。AnalysisPipeline=呼び出し位置提供、Builder=文面組み立て、ScenarioService=汎用 materialize。

## [Warning] 決定的な要点抽出規則が未確定
- 判断: 対応する
- 対応内容: N=config('manual.summary_recap_max_points') 既定3。走査=steps 先頭から深さ優先で point.subtitlePrimary の非空を収集。区切り「／」。上限超過は件数優先で削減。point 不在/非空 subtitle_primary 0 件はフォールバック定型総括（作業名＋完了確認、別 lang キー）。scene は撮影指示のため再掲元に使わない。

## [Warning] intro/summary 識別不変条件の定義不足
- 判断: 対応する
- 対応内容: Feature テストは builder が返す DTO のフィールド値・位置を検証。ハードコード翻訳文字列でなく builder と同じ lang キー/定数で照合。恒久識別子は独立型導入時の後続課題。

## [Suggestion] config と lang の責務分離 / typed accessor
- 判断: 対応する
- 対応内容: 文面=lang、構造値(N・truncate 長)=config。lang 取得は string 確定の typed accessor（PHPStan L10）。
</content>
