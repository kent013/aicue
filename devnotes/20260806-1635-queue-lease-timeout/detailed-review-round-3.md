## 施策別判定

### 施策 0: APPROVE

リテラル固定と env 後退検出が対応している。

### 施策 1: APPROVE

規則 1 を満たし、接続網羅も明確である。

### 施策 2: APPROVE

未定義キー、非数値、設定評価失敗をそれぞれ安全に検出できる。

### 施策 3: REQUEST_CHANGES

[Warning] `extractBashFunction()` の実装規定が不足している。

単純な波括弧カウントでは `${...}` を関数ブロックと誤認する。対象スクリプトの整形規約を利用し、次のような行単位の抽出規則を明記する必要がある。

- 開始: `^start_shard_workers\(\)[[:space:]]*\{$`
- 終了: 開始後、列頭の `^}$` が最初に現れる行
- 開始・終了が一意でなければ fail

または Bash の構文を正しく扱える既存 parser を使う。

### 施策 4: REQUEST_CHANGES

[Critical] クラススコープの pop 条件に off-by-one がある。

`{` を処理して `braceDepth` が `bodyDepth` になった状態で push した場合、対応する `}` の処理後は `bodyDepth - 1` になる。「braceDepth が push 時の bodyDepth に戻ったら pop」では、メソッド終了時などクラス内部の `}` で誤って pop し得る。

修正案として、更新順序を固定する。

```text
「}」を処理する前:
  stack 最上位の bodyDepth === braceDepth なら、その「}」はクラス終端なので pop
その後:
  braceDepth--
```

または decrement 後に `braceDepth === bodyDepth - 1` で pop する。

[Critical] 明示的な `public ?int $timeout = null` が規則 2 を素通りする。

現在は `declaredJobTimeout()` が明示 `null` を未宣言と同じ `null` に正規化するため、ケース 5、6、7のすべてを通過する。しかし許容形は「正の int デフォルト値を持つ宣言のみ」であり矛盾する。

修正案:

- `array_key_exists('timeout', $defaults) === false` の場合だけ `null`を返す
- 宣言されている値が `null`、非 int、0以下なら fail
- PHPDocも「値が null → fail」へ変更する

これにより未宣言と不正な明示宣言を区別できる。

### 施策 5: APPROVE

`ReflectionMethod::invoke()` により Worker の構築依存とPHPStan上の曖昧さが解消されている。DB接続の明示も適切である。

### 施策 6: APPROVE

運用契約、CIで保証できない範囲、Laravel更新時の確認事項が明確である。

### 施策 7: APPROVE

コメントの実値ドリフトを適切に解消している。

## 全体判定

**CHANGES_REQUESTED**

Round 2 の問題は解消されている。残る必須修正は、施策4のクラススコープpop条件と、明示`$timeout = null`の素通りである。いずれも実装前に規定を直せば局所的に解決できる。