## Round 2 レビュー

### `resources/js/components/features/manual/TakePreviewPanel.svelte`

実装修正自体は妥当です。`take.id` と `playbackUrl` の両方を購読するため、同じテイクの署名 URL が再取得された場合にも `imageFailed` が解除されます。Round 1 の論理バグは解消されています。

**Warning**: この修正に対応する回帰テストが提示差分にありません。

なぜ問題か: 今回の不具合は「take は同一、URL だけ変更」という境界条件です。通常のテイク切替テストでは検出できず、将来 `playbackUrl` の購読が消えてもテストが緑になります。禁止事項の「不変条件は対応するテストへの登録まで含めて実装済み」に達していません。

どう直すか: `TakePreviewPanel` の component test に、同じ `take.id` のまま `playbackUrl` を変更し、失敗表示から画像表示へ戻るケースを追加してください。`TakePreviewDialog` にある同種テストと対になる形が適切です。

### `tests/Architecture/FfmpegProcessLaunchInventoryTest.php`

ファイル単位から起動点数の検査へ進めた方向は妥当で、Round 1 より明確に強くなっています。実在した3ファイルを母集団に含めた判断も正しいです。

**Warning**: `substr_count($contents, 'run([')` は表記揺れで無検出になります。

なぜ問題か: 同じファイルへ次のような起動点を追加すると、`launches` も `guarded` も既存件数のままでテストを通過できます。

```php
->run ([
```

```php
->run(
    [
```

動的コマンドを保証しない旨は記載されていますが、これは動的構築ではなく同じ `run()` 配列呼び出しの空白・改行差です。「起動点を足したら黙って通らない」という検査の主張と一致しません。

どう直すか: 少なくとも `->run\s*\(\s*\[` を正規表現で数えてください。より堅くするなら PHP tokenizer または AST で `run()` 呼び出しを抽出し、各呼び出し単位で安全引数を確認します。字句検査に留める場合も、その限界を「動的構築」に加えて「別の呼び出し表現」に明記する必要があります。

### `tests/js/components/features/manual/TakeFileUpload.test.ts`

Round 1 の Warning は解消されています。以下の重要な契約が固定されています。

- 計画種別による `accept` 切替
- JPEG 正規化後の Blob を送る
- 静止画の `durationMs` が `null`
- 正規化失敗時に enqueue しない
- 種別違いの入力を両方向で拒否する

テストも component の公開挙動を検査しており、degenerate PASS は見当たりません。

### `tests/Architecture/ManualEnumTsSyncInvariantTest.php`

統合しない判断は妥当です。境界を分けた型ファイル間に依存を作らず、両方を PHP enum と照合することで drift を機械的に防いでいます。Suggestion への十分な対応です。

### `tests/Feature/Manual/StillMaterialConsistencyTest.php`

C1 の追加は妥当です。presign、予約行、登録時のサーバ確定、採用、マニフェスト生成までを接続しており、個別テストだけでは見えなかった S1・S2・S3 間の契約を固定できています。

**Suggestion**: ファイル冒頭のコメントは現在のテスト内容と食い違っています。

現在も「C1 は各所の既存テストが持つため、ここでは新経路だけを固定する」とありますが、同じファイル内で C1 通しを追加しています。レビュー時の保証範囲を誤読させるため、「C1 は本ファイルで通しを固定する」旨へ更新するのが適切です。

## 判定

Round 1 の実装上の3問題は解消方向ですが、`TakePreviewPanel` の境界条件テストが不足し、ffmpeg 起動点 inventory には容易な表記揺れによる検査漏れが残っています。新たな Critical はありません。

CHANGES_REQUESTED