# 対応マトリクス: design-review Round 2

## [Critical] 施策1/6: 102 件 materialize を手動保存(save)できるか未保証（タコツボ破綻）
- 判断: 対応する
- 根拠: UpdateScenarioRequest L81 が steps を max:MAX_STEPS(100) に制限。102 件 materialize されたシナリオを編集画面から再保存すると 422 になる。導入/総括は独立判別できないため save 側で除外もできない。
- 対応内容: ScenarioLimits に MAX_TOP_LEVEL_CUTS = MAX_STEPS + 2(=102) を追加。施策4.5 で UpdateScenarioRequest の steps max を MAX_TOP_LEVEL_CUTS に整合。MAX_STEPS(生成 DTO 上限)は据え置き。施策6 に「102 件 round-trip 保存」正常系テスト、施策7 に ScenarioUpdateTest の境界更新(101→103)を追加。

## [Warning] 施策3/5: 長さ判定が recap 本文のみで lang 接頭辞「要点の再確認：」を含まない
- 判断: 対応する
- 根拠: 接頭辞込みの完成文が上限超過し得るのに、件数削減が本文だけで判定され、最後の clamp が末尾候補を途中切断する。
- 対応内容: summarySecondary() で lang 完成文（接頭辞込み）を基準に件数削減 →1 件でも超過なら完成文を文字 truncate。recapCandidates() は候補 list<string> のみ返す純関数に分離。施策5 の長さテストを (a) 複数→件数削減 / (b) 1 件→完成文 truncate に分離し、完成文常時 ≤2000 を検証。

## [Suggestion] 施策3: preg_replace の失敗(null)を (string) で握りつぶさない
- 判断: 対応する
- 対応内容: normalize() の preg_replace 結果を Assert::string で閉じる。truncatedTitle も normalize 経由に統一。

## [Suggestion] 施策7: $generatedStep/$point の null 型を Assert で閉じる
- 判断: 対応する
- 対応内容: firstWhere / get(1) の結果を Assert::isInstanceOf(Cut::class) で閉じる旨を施策7 に明記。

## [確認] D（inventory 登録不要）: 妥当の再確認を受領（変更なし）
</content>
