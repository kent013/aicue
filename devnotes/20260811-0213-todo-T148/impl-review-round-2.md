## Round 2 レビュー

### `app/Support/Security/AdoptedTakeReferenceInventory.php` — OK

Round 1 の Warning は解消されています。

`PipelineSmokeCommand.php` の rationale は、「`adoptedTake` を使う集計」と「別途行う登録テイク自身の ready 確認」を明確に区別しています。同一ファイルに `TakeStatus::Ready` が存在する事実とも矛盾せず、免除判断の材料として正確です。

### `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` — 要修正

[Critical] 外部変数に切り出した relation callback が前提 2 を通過します  
該当: `hasCriterionInRelationArgument()` 付近（提示差分 L125 前後）

直接記述した DB クエリ形は検出できるようになり、Round 1 で示した具体的な穴は閉じています。ただし、一般的な Eloquent の次の形は依然としてケース 8 を通過します。

```php
$scope = fn ($take) =>
    $take->where('status', TakeStatus::Ready->value);

$query->whereHas('adoptedTake', $scope);
```

この場合：

- 検出 B: 同じファイルに `adoptedTake` と `TakeStatus::Ready` があるため該当
- 前提 1: `->adoptedTake` はないため通過
- 前提 2: `whereHas()` の引数リストには `'status'` も `TakeStatus::Ready` もないため通過
- ファイルは名指し免除されているため、ケース 4・8とも green

同様に、配列やローカル scope に切り出した callback も通過します。これは「relation の id を別クエリで取り出して後段で判定する形」ではなく、通常の `whereHas()` callback の書き方です。

修正案は、免除ファイルについて既存の benign な参照形を狭く固定することです。例えば、免除対象の `'adoptedTake'` を含む呼び出しについて：

- 呼び出し名を現在実在する `doesntHave` に限定
- 引数を文字列リテラル `'adoptedTake'` だけに限定
- 第2引数、named argument、変数 callback があれば premise を失効

こうすれば簡易 token scanner でデータフロー解析を始めずに、現在の免除理由だけを exact-fit で許可できます。M10 に加えて、callback 変数形の mutation も赤化確認が必要です。

[Warning] 「保証しないもの」が callback 切り出し形を説明していません  
該当: ファイル冒頭「保証しないもの」L37 前後

現在の説明では、前提 2 が「呼び出しの引数リストだけ」を見ることは書かれていますが、上記 callback 変数形が具体的な帰結として読み取りにくく、「DB クエリ形を閉じた」という説明と衝突します。

scanner を狭い benign 形の allowlist に直さない場合、少なくとも次を保証外として明記する必要があります。

> relation callback や条件式を変数・ローカル scope に切り出し、`whereHas('adoptedTake', $scope)` のように渡す形には沈黙する。

ただし、記載だけでは「Canonical だけが判定式を持つ」という不変条件は守れないため、Critical の解消にはなりません。

括弧対応そのものは、提示された直接 callback・`whereRelation`・通常のネスト呼び出しに対して妥当です。主な問題は括弧解析ではなく、引数リスト内だけを見るという検出境界です。

M10 により Round 1 で指摘した直接記述形が閉じたことは確認できますが、名指し免除全体としてはまだ Canonical-only を実質保証できていません。

**全体判定: CHANGES_REQUESTED**