# 対応マトリクス: design-review Round 4

## [Warning] 施策5/6: 新設した stale 語彙 gate が実行レーンに登録されていない

- 判断: **対応する**
- 根拠: 施策 6 の実行対象が 2 モジュールのままだと、禁止語が再混入しても `composer test` が
  緑になる。「不変条件はテストへの登録まで含めて実装済み」(禁止事項 1) を満たさない。
- 対応内容: 施策 6 の実行対象を
  `test_correlate` / `test_build_executed` / `test_naming_no_stale` の **3 本**にした。
  `test_merge_pcov` はコード到達カバレッジ (別 feature) の担当なので本 TODO では加えない、
  と理由付きで明記した。

## [Suggestion] 施策5: README に旧語彙そのものを書かない

- 判断: **対応する**
- 根拠: README に旧値を書くと、自分で足した文言 gate が自分の文書で赤くなる。
- 対応内容: README には「status は `ok|blocked` の 2 値であり生成器が写像する」と
  **肯定形で**書く方針を設計へ明記した。
