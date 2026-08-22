Round 1 の具体的な4反例は閉じていますが、fence 判定に同種の fail-open が残っています。

### `tests/Architecture/IntegrationGuideGateTableSyncTest.php`

[Critical] `integrationGuideFenceMask()` が CommonMark の fence を正しく識別せず、コードブロック内の表を再び本物として受理できます。

主に2経路あります。

- CommonMarkで有効な1〜3スペース字下げ fence を認識しません。
- fenceの長さと閉じ方を追跡せず、先頭3文字だけで開閉します。

例えば、次の ` ```not-a-close` はCommonMarkでは閉じ fence ではありませんが、実装は `$marker === $openMarker` として閉じます。

```markdown
```markdown
```not-a-close
#### 新規リソースで必ず踏む Architecture ゲート
| ゲート | 何を落とすか | 何をどこへ登録するか |
|---|---|---|
...
```

実際の表示ではアンカーと表はコードブロック内ですが、走査器では構造行として扱われます。閉じ忘れ検査も、走査器上は既に閉じているため発火しません。

同様に、4バッククォートで開いた fence を3バッククォートで閉じたと誤認します。CommonMarkでは短い列は閉じ fence にならないため、同じバイパスが成立します。

さらに、docblockは「3スペースまでの字下げを受理せず例外にする」と述べていますが、実装は字下げされた fence 自体を検出して例外にしません。字下げ fence 内の列0アンカーや表が候補になり得るため、保証表現とも食い違います。

対応は次のいずれかが必要です。

- fence開始時に文字種と実際の連続長を記録し、同じ文字種・開始時以上の長さ・後続が空白だけの行で閉じる。
- 列0限定文法を維持するなら、CommonMark上の1〜3スペース字下げ fenceを検出した時点で例外にする。

最低限、以下の負例を追加してください。

- 3スペース字下げ fence 内の列0アンカー・表
- 4バッククォート開始後の3バッククォート行
- fence内の `` ```not-a-close `` の後に置かれたアンカー・表

先頭 `|` 省略行の拒否、表開始後の非準拠行の例外化、通常の列0・3文字 fence、4スペース字下げへの対応は適切です。PHPStan level 10を意識した型処理にも新たな問題は見当たりません。

### `docs/app-integration-guide.md`

判定: Round 1から変更なし。設計との一致に問題ありません。

### `docs/template-divergence.md`

[Warning] D40の保証文は、上記fenceバイパスが残る間は引き続き実態より強い状態です。fence判定を閉じれば文言変更は不要です。

### `LedgerPins.php` / `adoption-debt.tsv`

判定: 適合。件数と採用時債務の付け替えに問題ありません。

提示された全検証コマンドのgreenは確認材料として十分です。ただし、現在のテスト集合が上記反例を含まないため、greenでも同期検査の不変条件はまだ成立していません。

CHANGES_REQUESTED