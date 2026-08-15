# 対応マトリクス: design-review Round 5

## [Warning] RP ID の負の dataset に `APP.example.com` が入っていない (対応マトリクスと全文が不一致だった)
- 判断: 対応する
- 根拠: 指摘のとおり。Round 4 で追加したつもりの行が**施策 1 のテスト計画に誤配置**されており、
  施策 2 の Unit テスト（`isDnsName()` の負のコントロール）に入っていなかった。
  origin 側の `https://APP.example.com` は正規表現の検査であって、
  身元の識別子側の小文字限定を固定しない（別の契約である）。
- 対応内容: 誤配置していた行を削除し、施策 2 の検査 2 の dataset に `APP.example.com` を追加した。
  「env 由来は config が小文字化する」と「別経路の未正規化値は validator が拒否する」の
  2 契約を別々に固定する理由も併記した。

## [Suggestion] 波及変更欄がまだ「vendor 既定キーの残存」表記
- 判断: 対応する
- 対応内容: 「Fortify の写像が生きていることを sentinel で検査 + config cache 往復 +
  Fortify 結線後の実効キーが揃っていること」に統一した。
