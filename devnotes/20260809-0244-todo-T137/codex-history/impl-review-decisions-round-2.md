# 対応マトリクス: impl-review Round 2

全体判定: `CHANGES_REQUESTED` (Critical 0 / Warning 3 / Suggestion 1)

## [Warning] BillingCustomerSynchronizer: 呼び出し元 2 経路のうち 1 本しか固定していない
- 判断: **対応する**
- 根拠: 指摘のとおり。`dispatchFor()` の呼び出し元は `RenameOrganizationAction` と
  `UpdateBillingContactAction` の 2 本で、片方だけを観測すると他方が tx の外へ出ても緑のまま。
  「窓口が 1 クラス」を固定するのは `BillingSyncDispatchInvariantTest` だが、それは
  **dispatch 元クラス**を閉じるだけで**呼び出し元の tx 位置**は見ない。
- 対応内容: `実呼び出し元 (UpdateBillingContactAction) 経由でも SyncBillingCustomerDetails は
  業務 tx の内側で投入される` を追加し、2 経路とも tx level 観測で固定した。

## [Warning] `defersAfterCommit()` の判定が PHP の真偽値文脈と一致していない
- 判断: **対応する**
- 根拠: 指摘が正しい。`!== null && !== false` は `0` / `''` / `[]` も違反にするが、vendor 側
  (`Queue::shouldDispatchAfterCommit()`) は真偽値文脈で評価するため、これらでは commit 後ずらしは
  起きない。「vendor と同じ意味論」という docblock の主張が実装と食い違っており、
  将来 falsy な既定値で偽陽性になる。
- 対応内容: 判定を `(bool) $value` へ変更し、docblock も「vendor と同じ真偽値文脈」に揃えた。
  偽陰性コントロールへ `public $afterCommit = 0;` のケースを追加した。

## [Warning] constructor promotion の検査が deny-by-default になっていない
- 判断: **対応する**
- 根拠: 指摘が正しい。promoted parameter は呼び出し側が `new Job(afterCommit: true)` で
  任意に渡せるため、**既定値が false でも 0 件 pin の穴**になる。既定値だけを見る実装では
  「constructor promotion も見る」という docblock が保証範囲の誇張になっていた。
- 対応内容: promoted な `afterCommit` parameter は**値に依らず違反**へ変更。
  負のコントロールへ `public function __construct(public bool $afterCommit = false)` のケースを
  追加し、既定値 false でも検出されることを固定した。docblock もその主張へ書き換えた。

## [Suggestion] D5 のテスト名・失敗メッセージが実装より狭い契約を表示している
- 判断: **対応する**
- 根拠: 妥当。`1` や promoted parameter も検出するようになったのに、名前が「既定値が true」の
  ままでは表示上の契約が実装より狭い (次の読者が誤解する)。
- 対応内容: テスト名を `D5: 母集団に commit 後ずらしを発動する $afterCommit を持つクラスは 1 件も無い`
  へ、負のコントロール名を「D5 (プロパティ)」へ変更。失敗メッセージも
  「truthy な既定値 / promoted parameter」を明示する文言に更新した。
  併せて `docs/architecture.md` の D5 行と `AGENTS.md` の記述も実装に合わせて更新した
  (ドキュメントが実装より強くも弱くもならないようにする)。

## [参考] mutation #24 への対応は妥当との評価
- 判断: 対応不要 (Codex が妥当と評価)
- 対応内容: なし。trip-wire と残存リスクの明示はそのまま維持する。
