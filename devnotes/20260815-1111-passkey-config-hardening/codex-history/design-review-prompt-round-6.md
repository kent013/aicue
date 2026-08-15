# Round 6 (最終確認): Round 5 の残件 1 点を反映した

app-design スキルの合議は最大 5 ラウンドだが、Round 5 の残件が
「Codex が文面まで指定した 1 行のテスト dataset 追加」だけだったため、
反映の確認のみを目的に 1 ラウンドだけ追加する。

## 対応マトリクス

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

---

## 該当箇所 (修正後)

施策 2 の Unit テスト計画:

```
  - 検査 2: `localhost` / `192.0.2.1` / `192.168.001.001` / `-example.com` / `example-.com` /
    `example..com` / `.example.com` / `example.com.` / `exam ple.com` / `2001:db8::1` /
    **`APP.example.com`（大文字）**
    を **dataset** で回して全て reject（**DNS ラベル検査の負のコントロール**。
    `192.168.001.001` は `filter_var` が IP と認めないため、末尾ラベルの英字要求で落ちることを固定する。
    `APP.example.com` は **身元の識別子側の小文字限定**を固定する
    = 「env 由来の値は config が小文字化する」と「別経路の未正規化値は validator が拒否する」の
    2 つの契約を別々に固定するため、origin 側の大文字テストとは**別に必要**）
```

施策 1 の波及変更欄:

```
- テストファイル: `tests/Feature/Config/ConfigHardeningTest.php`（env 派生の固定）、
  `tests/Architecture/PasskeyPackageContractTest.php`（**Fortify の写像が生きていること**を
  sentinel で検査 + config cache 往復 + **Fortify 結線後の実効キーが揃っていること**）
```

施策 1 の誤配置行は削除済み。

---

これで残件は無いか。施策ごとの判定と全体判定を明示してほしい。
