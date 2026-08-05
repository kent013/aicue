# 対応マトリクス: design-review Round 3 (APPROVED)

Round 3 で全体判定 APPROVED。Suggestion 4 件はいずれも正本間の齟齬を減らすものであり、
すべて設計に反映した (次ラウンドの再レビューは不要)。

## [Suggestion] S3/S4 に「implicit binding されていない」という旧表現が残っている

- 判断: 対応する
- 対応内容: S3-b の根拠文と S4 のモード対応表を
  「**binding 段で解決されないこと**」(implicit / explicit の両方を含む) に統一した。

## [Suggestion] S3-b の応答同一性テストは `Location` 等の非 volatile ヘッダも比較すべき

- 判断: 対応する
- 対応内容: 302 同士でも遷移先が違えば観測可能な差になるため、
  S1 で定義する normalize helper (volatile ヘッダ除外) を web 応答にも転用し、
  `Location` を含む非 volatile ヘッダまで比較する、とテスト計画に明記した。

## [Suggestion] S5 節内の「変更箇所」に `app/Support/TrustedProxyToken.php` が欠落

- 判断: 対応する
- 対応内容: S5 の変更箇所リストに追記した (施策一覧側には既に記載済み)。

## [Suggestion] S7 のテスト計画「上記 4 検査」は検査 5 追加後なので 5 検査

- 判断: 対応する
- 対応内容: 「上記 5 検査」に修正した。
