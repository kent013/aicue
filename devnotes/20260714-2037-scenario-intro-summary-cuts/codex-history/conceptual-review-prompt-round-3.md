Round 2 の Warning 4 点＋Suggestion に対応しました。再評価をお願いします。

## 対応（要約）

- [Warning] 再掲元の読み取り: **今回生成の list<ScenarioStepInput>（toScenarioSteps() の戻り値）から抽出**すると明記。DB 既存 cuts は参照しない（再生成で旧シナリオを総括する事故を構造的に排除）。
- [Warning] 責務一本化: terminal tx 内で `ScenarioBookendBuilder::wrap($lockedManual, $generatedSteps): list<ScenarioStepInput>` を呼び、戻り値を既存 `materializeIntoLockedManual()` に渡す。AnalysisPipeline=呼び出し位置提供 / Builder=文面組み立て / ScenarioService=汎用 materialize、と 3 者非重複に修正。
- [Warning] 抽出規則確定: N=`config('manual.summary_recap_max_points')` 既定 3。走査=steps 先頭から深さ優先で point の subtitlePrimary 非空を収集。区切り「／」。上限超過は件数優先で削減。非空 subtitle_primary が 0 件のときは「作業名＋完了確認」の定型フォールバック総括（別 lang キー）。scene は撮影指示のため再掲元に使わない。
- [Warning] 識別不変条件: Feature テストは builder が返す DTO のフィールド値・位置（先頭/末尾）を検証。ハードコード翻訳文字列でなく builder と同じ lang キー/定数で照合。恒久識別子は独立型導入時の後続課題。
- [Suggestion] config/lang 責務分離: 文面=lang、構造値(N・truncate 長)=config。lang 取得は string 確定の typed accessor（PHPStan L10）。

修正差分の該当節（改善アイデア / 実装方針 / 不変条件）は下記のとおりです。他節は Round 2 から変更していません。

---

（改善アイデア節・抜粋）
- 総括の subtitle_secondary は今回生成の ScenarioStepInput リストから決定的抽出（DB 既存 cuts 不参照）。走査=深さ優先で point.subtitlePrimary 非空、先頭 N 件（config 既定 3）、区切り「／」、上限超過は件数優先削減、0 件はフォールバック定型総括。
- terminal tx 内で ScenarioBookendBuilder::wrap($lockedManual, $generatedSteps) を呼び、戻り値を materializeIntoLockedManual() に渡す。DB 既存 cuts は不参照。
- ScenarioBookendBuilder は ScenarioStepInput のみ生成・返却。lang 取得は string 保証の typed accessor。

（実装方針節・抜粋）
- 文面=lang / 構造値(N・truncate 長)=config。

（不変条件節・抜粋）
- 識別検証は builder 戻り値の DTO フィールド値・位置で行い、翻訳文言変更に強くする。
- 検証ケース: 初回生成 / 再生成（全置換で重複なし） / 手動 save 後再生成 / point 不在フォールバック。
</content>
