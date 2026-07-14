Round 3 の a11y 1 点に対応しました。

## [Warning] aria-haspopup 削除 → 対応
disclosure パターンに合わせ、トリガー button の a11y 属性を `aria-expanded` + `aria-controls` のみに
変更しました (`aria-haspopup` を削除)。パネルは通常コンテナ (role=menu なし)。

これで Round 1〜3 の全 Critical/Warning に対応済みです。APPROVED 可否をご判定ください。
