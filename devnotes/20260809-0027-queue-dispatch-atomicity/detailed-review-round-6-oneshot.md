**全体判定: CHANGES_REQUESTED**

1. **M9 の collector 方式**
成立しています。`capture()` が同一 `JobQueueingTransactionRecords` オブジェクトを返し、listener 側もその同一オブジェクトへ `record()` するため、`finally` の `$collector->active = false` を削った mutation #18 は「capture 後の dispatch で `collector->all()` が増える」として赤化できます。copy-on-write の偽グリーンは潰れています。

2. **M10 との同期**
M10 の記述は M9 の本質には同期しています。`Queue::fake()` では原子性を検証できないこと、実 `jobs` 表と `JobQueueing` の tx level 観測で見ること、pin 済み接続の注意も書かれており、collector の内部実装まで architecture 文書へ出す必要はありません。

3. **残る Warning**
M7 の deny-by-default gate が Laravel のもう 1 つの deferral 経路を捕捉していません。

Laravel の queued job は `ShouldQueueAfterCommit` や `->afterCommit()` だけでなく、`Queueable` の `$afterCommit` プロパティでも commit 後 dispatch を指定できます。例えば job class 側で `public $afterCommit = true;`、または constructor 等で `$this->afterCommit = true;` とすると、現在の D1-D4 では漏れます。

これは「0 件 pin」の主張を弱めるので Suggestion ではなく Warning 扱いが妥当です。

修正案:

- M7 に D5 を追加: `ShouldQueue` 実装クラスで `afterCommit` プロパティの default が `true` のものを 0 件 pin
- 可能なら文字列検出も追加: `$this->afterCommit = true` / `->afterCommit = true` 相当
- 負のコントロール追加: ダミー job に `$afterCommit = true` を持たせて D5 が落ちることを確認
- M10 の禁止列挙にも `$afterCommit = true` を追加

この 1 点を反映すれば、実装フェーズへ進めてよい設計だと判断します。