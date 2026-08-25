# 詳細設計レビュー Round 3 — Warning 2 件への対応報告

Round 2 の Warning 2 件に対応した。再判定を求める。

1. **[Warning] `$targets` の iterable value type** → 設計のコード片の docblock
   `@param list<array{absolute: string, relative: string}>|null $targets` を維持した上で、
   `TrackedPhpSourceFiles::all()` の戻り型 (実読で確認:
   `@return list<array{absolute: string, relative: string}> relative の昇順`) と
   **完全一致**させることを本文と PHPStan 適合チェック欄へ明記した。
   なお `all()` は index に残った削除済みファイルを `is_file` で除外してから返すため、
   実運用で読めないパスが注入されるのは「実在するが読めない」異常系に限られ、
   自己検査は存在しないパスの注入でこの分岐を発火させる (設計に記載済み)。

2. **[Warning] `mutatedDebtPaths` の赤の時点** → 実装手順の冒頭へ
   「`NoNonCompoundGlobalUseTest.php` は採用時債務の対象なので、同ファイルへ最初の変更を
   入れる手順 2 の時点で `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で赤になり、
   手順 6 (D54 登録 + 債務削除 + pin 更新) まで意図的に赤のままである」と明記し、
   各手順の状態を「手順 1: 全体 green / 手順 2: 配線検査 red→green + 突合 gate 赤開始 /
   手順 3〜5: 対象 gate は順次 green・突合 gate は赤のまま / 手順 6: 全体 green」へ
   書き直した。

全体判定を APPROVED にできるか、残る Critical/Warning があれば指摘されたい。
