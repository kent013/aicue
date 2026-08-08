# 対応マトリクス: design-review Round 4

Warning 4 件（Critical 0）。**全件対応**した。反論は 0 件。いずれも「設計本文で新設したものが変更ファイル台帳へ反映されていない」という同期漏れの指摘で、実装時の取りこぼしに直結するため機械的に是正した。

## [Warning] S1 `ReferenceScanResult` が変更ファイル一覧に無い

- 判断: **対応する**
- 対応内容: 施策一覧 S1・S1 変更箇所に `tests/Support/ReferenceScanResult.php` を追加。PHPStan 適合チェックを「`references()` は `ReferenceScanResult` を返す」「`$sites` = `list<ReferenceSite>` / `$imports` = `array<string,string>`」へ更新。波及変更の「API Resource/DTO: なし」にも、value object が `tests/Support/` にあり HTTP 応答へ現れないことを明記した。

## [Warning] S5 `PestTestNameScanner` とその unit test が変更ファイル一覧に無い

- 判断: **対応する**
- 対応内容: 施策一覧 S5 を「gate `ExternalSeamInventoryTest` + 補助 scanner」に改め、`tests/Support/PestTestNameScanner.php` と `tests/Unit/Architecture/PestTestNameScannerTest.php` を施策一覧・S5 変更箇所の双方へ追加した。

## [Warning] S5 テストファースト手順に旧テスト名が残っている

- 判断: **対応する**
- 対応内容: テスト計画と実装順序 4 を新名称 `外部到達: 走査 site と目録は (クラス, 種別) で双方向に一致する` へ同期し、期待結果も「12 クラス」から **「12 個の分類済み到達 `(class, kind)`」** へ改めた。あわせてテスト 10（排他的被覆）が `payment|code_reach_point` 等を「覆われていない」で赤にすることも確認手順に加えた。

## [Warning] S8 `docs/app-integration-guide.md` が変更箇所一覧から漏れている

- 判断: **対応する**
- 対応内容: 施策一覧 S8 と S8 変更箇所へ `docs/app-integration-guide.md` を追加し、「§7 の不変条件番号は動かさない・番号ではなく項目名で参照する」（AGENTS.md の採番注意）を明記した。波及変更には `docs/TODO.md` の登録が `app-todo-add` の責務であり本 PR の変更ファイルではないことも足した。
