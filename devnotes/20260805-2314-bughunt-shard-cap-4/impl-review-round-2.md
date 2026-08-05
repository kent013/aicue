`tests/Architecture/BughuntShardCapInvariantTest.php`: **問題なし**

共通区切りにより `--parallel 8`、`N は 8`、`cap は 8` が Tier A で検出され、負のコントロールも追加されています。既存の Tier A/Tier B 分離や `cap-defense-ok` の非免除規則にも影響しません。

再代入追跡を見送る判断も妥当です。トップレベル定義を静的検査し、実効 allowlist は self-test `[c]` で固定できています。

Critical 0 / Warning 0

全体判定: **APPROVED**