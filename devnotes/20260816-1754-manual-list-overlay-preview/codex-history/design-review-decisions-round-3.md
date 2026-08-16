# 対応マトリクス: design-review Round 3

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 3 / Suggestion 3)。
施策 1 / 3 / 4 / 6 は APPROVE。残件は保証範囲の表現と、新設 Unit テストの反映漏れ。

## [Warning] 施策 2: `preload="none"` を「playback 要求ゼロ」の保証として書いている
- 判断: **対応する**
- 根拠: 指摘が正しい。`preload` は仕様上**ヒント**であり、`none` でもブラウザが要求を
  出さないことは保証されない。Vitest が見るのは属性であって HTTP 要求数ではない。
  保証しないものを保証すると書くのは、このリポジトリで最も避けたい種類の誤りである。
- 対応内容: 契約を「ブラウザに事前取得しないよう指示する / 意図しない先読みを抑制する」に弱め、
  「保証範囲を誇張しない」注記を追加した。Vitest の記述も
  「`preload="none"` の指定が付いていることの固定 (HTTP 要求数の証明ではない)」に変更。
  併せて「強い保証が要るなら再生操作まで `src` を設定しない別設計が要るが、今回は不要」
  (署名 URL は 302 の先で、受け取れる相手かは endpoint が毎回判定する) と根拠を残した。

## [Warning] 施策 5: 新設 Unit テストが施策一覧・変更箇所・検証コマンドに無い
- 判断: **対応する**
- 根拠: 波及変更の正本としての不備。設計書の一覧が実際の変更集合と食い違うと、
  実装時に落ちる。
- 対応内容: 施策一覧の施策 5 を「テスト (Unit + Feature + Architecture)」に改め
  `tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php` を明記。
  施策 5 の「変更箇所」にも追加し、個別検証へ
  `composer test -- --filter=CurrentRenderArtifactLoadedCandidate` を追加した。

## [Warning] 文書全体: 実装モードの「新規 2 本」が実数と合わない
- 判断: **対応する**
- 対応内容: 「新規 3 本 (component 1 / Vitest 1 / Unit テスト 1)」に修正した。

## [Suggestion] 施策 1: 未ロード時の例外型を明記する / `use Webmozart\Assert\Assert;` が要る
- 判断: **対応する**
- 根拠: 「何かが落ちた」テストにしないため、投げる型を固定する方が契約が明確になる。
- 対応内容: Unit テストの期待を `InvalidArgumentException` (Webmozart Assert が投げる型) と明記し、
  実装側の `use` 追加も設計へ書いた。

## [Suggestion] 施策 5: 「追加クエリ 0 本」の観測区間を明確にする
- 判断: **対応する**
- 対応内容: fixture 生成と `$manual->load('latestSucceededRender')` を**終えてから**カウンタを開始し、
  `fromLoadedRenderCandidate()` の呼び出しだけを測ることを明記。既存の query-count helper
  (無ければ `ManualListQueryCountTest` と同じ `DB::listen` の流儀) を優先する旨も書いた。

## [Suggestion] 施策 6: `preload` テストの説明を「属性指定の固定」へ
- 判断: **対応する** (施策 2 の修正と同じ文言に揃えた)
