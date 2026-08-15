## Round 2 判定

### 施策 2: REQUEST_CHANGES

[Warning] `StrictTypesRuntimeProbe` が、プローブ自身の実行結果ではなく「プロセス全体の標準出力」を判定しています。

例えば次の完全ソースでは、型不一致プローブへ到達していないのに `true` になります。

```php
<?php
echo 'STRICT';
exit;
```

より問題になるのは、正準宣言を含む次の検体です。

```php
<?php
declare(strict_types=1);
echo 'STRICT';
exit;
```

この場合、scanner と runtime probe が両方 `true` になり、「scanner が受理したソースでは実際にプローブが strict を観測した」という照合が成立しません。完全ソース化によって、`echo`、`exit`、例外、関数名衝突、末尾 `?>`、`__halt_compiler()` などの影響を受けるようになっています。

修正案: ソース全体へプローブを末尾追加する方式ではなく、検査対象となる宣言部分とプローブ本体だけで独立した実測ファイルを組み立ててください。scanner と同一文字列を渡す要件を維持するなら、実測器が受け付ける検体を「プローブ挿入可能な宣言形式」に限定し、プローブ到達をランダムな nonce または専用終了コードで確認する必要があります。

少なくとも以下を固定してください。

- プローブ到達前の `echo 'STRICT'; exit;` を `true` にしない
- 対象ソースの既存出力を判定材料にしない
- `Process::isSuccessful()` または終了コードを検査し、起動失敗と型検査結果を区別する
- 固定名 `probeTarget` の衝突を避ける
- `?>`、`exit`、構文エラーでプローブ未到達の場合は、strict の観測結果として扱わない

`tempnam()` の扱い自体は修正されており、元ファイルが残る問題と `false` 未処理は解消しています。

[Suggestion] `hasLaterStrictTypesDeclare()` は、今回想定している有効な PHP ソースに対して妥当です。

コメントと文字列は正規化時に除外または別トークンになるため、次を再宣言とは誤認しません。

- 文字列リテラル中の `strict_types`
- 配列キー `'strict_types'`
- コメント中の記述
- 通常のクラス定数・メソッド・関数名

ただし「対応する `)`」というコメントに対し、実装は単に最初の `)` までを見ています。現在の `declare` 文法と正準化対象では実害はありませんが、正確な構造走査を名乗るなら括弧深度を追跡する方が堅牢です。これは現時点では Warning ではありません。

### 施策 3: APPROVE

Round 1 の Critical は解消されています。

- 後続の `strict_types` 再宣言を gate 自己検査でも拒否
- prefix ごとの走査域消失を明示的に検出
- 空集合、床値、代表ディレクトリの三重 pin
- 読み込み失敗を例外化
- baseline・allow-list なし

施策2の実測照合器はscannerの自己検査上の問題であり、gate本体の静的判定を直接 fail-open にするものではありません。

### その他の施策

- 施策 1: APPROVE
- 施策 4: APPROVE
- 施策 5: APPROVE

`config:clear → route:list → config:cache → config:clear` の順序、import差し替え、一時ディレクトリ削除guard、文書への再宣言拒否追記は、Round 1 の指摘を満たしています。

## 全体判定

**CHANGES_REQUESTED**

残る修正必須事項は、`StrictTypesRuntimeProbe` が「プローブへ到達して型不一致を観測したこと」を、対象ソース自身の出力や制御フローと分離して証明できるようにすることです。`hasLaterStrictTypesDeclare()` と gate の静的判定については、Round 1 の問題は解消されています。