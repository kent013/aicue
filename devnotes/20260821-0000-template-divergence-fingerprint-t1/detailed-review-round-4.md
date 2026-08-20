全体判定: **CHANGES_REQUESTED**

設計の中核は承認可能です。Round 3の指摘は適切に解消されています。残る実装上の欠落は、S4で新たに導入したreadonly context DTOの定義場所だけです。

## S1: 識別子の反転

判定: **APPROVE**

## S2: 指紋台帳DTOとパス検証

判定: **APPROVE**

## S3: 母集合の列挙と生成ロジック

判定: **APPROVE**

初回と2回目以降の母集合定義、ローカル削除の保持、正典側削除時のみ除外する規則が一貫しています。

## S4: 生成器と生成物

判定: **REQUEST_CHANGES**

[Critical] `FingerprintGenerationService`が受け取る「readonly context DTO」のクラス名と定義ファイルがありません。

設計は「新規PHP 18本・1クラス1ファイル」としていますが、context DTOを別クラスとして実装するなら19本目が必要です。同じファイルへ置くと「1クラス1ファイル」と矛盾します。

修正案:

- `tests/Support/TemplateDivergence/FingerprintGenerationContext.php`を追加
- 変更ファイル一覧と施策一覧へ追加
- 新規PHPを18本から19本へ修正
- DTOの境界検証を明記

最低限、次を検証してください。

- 期待sha256が64桁小文字hex
- 期待source commitが40桁小文字hex
- 出力先2つがroot配下の期待した固定パス
- 前世代台帳がある場合は`role: app`
- 非adopt時は前世代commitと期待source commitが一致
- 指紋出力先と債務出力先が同一でない

[Warning] serviceのテスト計画は「拒否3経路」としながら、4経路を列挙しています。

- 既存台帳がtemplate
- 入力sha256不一致
- 新規債務追加
- 同じ入力での母集合縮小

修正案: 「拒否4経路」へ訂正してください。

[Warning] TSVの書式説明のコードブロックに世代ヘッダがありません。

修正案: 正準形を次のように示してください。

```text
# template_ledger_commit=<40桁hex>
<repo-relative path>\t<採用時のアプリ側sha256>
```

## S5: 突合と債務判定

判定: **APPROVE**

nullable stateにより、正常3状態と検査不能を排他的に表現できています。4正例・7負例の方針も妥当です。

## S6: 突合gate

判定: **APPROVE**

F14の母集合外ガード、具体件数の排除、全結果種別の個別assertが反映されています。

## S7: 負例・正例

判定: **REQUEST_CHANGES**

[Warning] S4でcontext DTOを追加する場合、その不変条件の正負テストも必要です。

修正案: `FingerprintGenerationContext`について、正常系と次の負例をS7へ追加してください。

- 不正なsha256
- 不正なcommit
- 同一の出力先
- root外の出力先
- template roleの前世代台帳
- 非adopt時のcommit不整合

## S8: 件数pinの一本化

判定: **APPROVE**

## S9: D33/D34と保証範囲

判定: **APPROVE**

D33の7パス、D34の2パス、計9パスという記述も明確です。

## S10: AG-159の責務縮小

判定: **APPROVE**

母集合保持規則と正本への参照が正しく反映されています。

## S11: 登録の契機とD35

判定: **APPROVE**

D35への移管、フェーズ別pin、C3の変更対象と9項目の受入条件が整合しています。

## 実装モード

判定: **REQUEST_CHANGES**

[Warning] context DTOを1クラス1ファイルで追加すると、新規PHPは19本になります。

修正案: ファイル一覧、実装モード、S4の変更ファイルを19本へ揃えてください。母集合外の新規ファイルなので、`FINGERPRINT_POPULATION_COUNT = 281`には影響しません。

---

上記のcontext DTOを明示すれば、設計上の構造的な不足は解消します。債務モデル、fail-closed、部分更新、3a/3b、C2/C3のフェーズ分離については承認可能です。