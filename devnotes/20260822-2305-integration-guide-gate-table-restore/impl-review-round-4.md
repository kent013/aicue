HTMLを§2内で拒否する経路は閉じていますが、§2の開始位置より前にHTML blockを置くバイパスが残っています。

### `tests/Architecture/IntegrationGuideGateTableSyncTest.php`

[Critical] `integrationGuideRejectHtml()` の呼び出しが§2切り出し後なので、HTML block内の偽の§2を検出できません。

例えば文書を次の形にすると、

```markdown
<pre>
## 2. ドメインモデルの配置

#### 新規リソースで必ず踏む Architecture ゲート
...8行の表...

#### 条件付きで発火するゲート
...13行の表...

## 3. 偽の終端
</pre>

## 3. 本来の章
```

処理は次のようになります。

1. `integrationGuideHiddenMask()` はHTML blockを分類しないため、`<pre>` 内の `## 2.` を構造行と判断する。
2. コメント内ではない偽の `## 3.` で§2を終了する。
3. 切り出した文字列は `## 2.` から始まるため、その前の `<pre>` は含まれない。
4. `integrationGuideRejectHtml()` にはHTML開始行も `</pre>` も渡らない。
5. HTML block内の2アンカー・2表が正常な索引として抽出される。

描画上はすべてpreformatted block内ですが、同期検査は緑になります。今回追加された「HTML blockの中のアンカーと表」の負例は `<pre>` を切り出し結果に含めているため、この境界越えを裏取りできていません。

HTML構文を全面拒否する方針なら、§2を探す前に全文へ拒否検査を適用するのが最小の修正です。現行文書全体にHTMLがないという実測とも整合します。その場合、docblockも「§2内」ではなく「§2の境界を安全に確定するため契約文書全体で拒否」とする必要があります。

恒久テストには、`<pre>` の開始行を偽の `## 2.` より前に置き、偽の `## 3.` もblock内に置いた負例を追加してください。

### その他の変更ファイル

`docs/app-integration-guide.md`、`docs/template-divergence.md`、`LedgerPins.php`、`adoption-debt.tsv` に新たな問題はありません。D40の保証は上記バイパスを閉じれば妥当になります。

[Warning] `pnpm test` と `pnpm test:packages` の修正後再実行はまだ完了していません。最終完了時には今回の状態に対する全コマンドgreenを確定してください。

CHANGES_REQUESTED