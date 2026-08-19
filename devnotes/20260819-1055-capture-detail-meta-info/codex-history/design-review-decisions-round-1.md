# 対応マトリクス: design-review Round 1

## [Critical] 施策3: `CaptureCutData::fromCut()` のカット単位クエリ (takes 取得)

- 判断: 対応する
- 根拠: 現行 `CaptureCutData::fromCut()` は `$cut->takes()->orderBy(...)->get()` を
  カットごとに実行しており、`adoptedTake` の eager load だけではこの N+1 を解消できない。
  施策6 の新設クエリ数テストは修正なしでは必ず赤くなる。指摘は事実。
- 対応内容: `CaptureCutData::fromCut()` の**シグネチャを変更**し、`takes` を relation 経由の
  再クエリではなく**呼び出し側が渡す `Collection<int, Take>` で受ける**形にする
  (`DeterminedCutDuration` / `EffectiveMaterialType` と同じ「解決済みの値を引数で受ける」作法)。
  - `CaptureManualDetailData::fromManual()` は `cuts()->with(['adoptedTake', 'takes'])` で
    両 relation を eager load し、`$cut->takes` (メモリ上の Collection) を渡す。
  - 単一カット応答の `CaptureTakeController::adopt()` は `$adoptedCut->load('takes')` を
    明示してから渡す (単一行なので N+1 の懸念はない。1 度の追加クエリを呼び出し側で明示する)。
  - 並び順 (`sort_order` → `id`) は `fromCut()` 内でメモリ上の `sortBy` に変更し、
    呼び出し側が並び順を意識しなくてよい形を維持する。

## [Warning] 施策1: `DeterminedCutDuration` が「式の唯一の所在」であることが機械固定されていない

- 判断: 対応する
- 根拠: 将来 `RenderJobService` に式が再実装されても既存テストは検出できない。
  ただし全数走査の新設スキャナは過大 (AGENTS.md の走査器共通規約 5 条をフルに満たすコストに見合わない、
  対象は `RenderJobService` の 1 メソッドのみ)。
- 対応内容: 汎用スキャナではなく、`RenderJobService::assertTotalSourceDurationWithinLimit()` の
  実装本文を対象にした狭い Architecture テストを新設する
  (`tests/Architecture/DeterminedCutDurationSingleSourceInvariantTest.php`)。
  本文に `DeterminedCutDuration::milliseconds(` を含み、`EffectiveMaterialType::of(` /
  `StillDisplayDuration::secondsFor(` を直接含まないことをソース文字列で確認する。
  負例 (旧式の 3 分岐をわざと書き戻したメソッド文字列) で検出できることを自己テストで固定し、
  docblock に「保証対象は `RenderJobService` の当該メソッド 1 つのみ。他クラスでの写経は検出しない」
  と明記する (走査器共通規約 (b)(c)(d) に沿う。(a)(e) は名前解決・語彙分割を伴わないため対象外)。

## [Warning] 施策2: `array_sum()` の int/float 契約と桁溢れ

- 判断: 対応する
- 根拠: `PHP_INT_MAX` 超で `array_sum()` は float を返しうる。readonly コンストラクタの `int` と
  静的に矛盾しうるという指摘は妥当。
- 対応内容: `array_filter` + `array_sum` をやめ、1 パスの明示ループへ変更する。
  加算前に `Assert::greaterThanOrEqual($ms, 0)` と `Assert::lessThanOrEqual($ms, PHP_INT_MAX - $total)`
  を置き、逸脱は例外にする (クランプしない)。負値テストと桁溢れテストを追加する。

## [Warning] 施策3: 「`readyTakeId()` の評価は 1 カットにつき 1 回」という記述が実装と矛盾

- 判断: 対応する (表現の訂正。実装の複雑化はしない)
- 根拠: `appendCut()` が 1 回呼び、`CaptureCutData::fromCut()` 内部でも
  `AdoptedReadyTakeCoverage::readyTakeId($cut)` を呼ぶため実際は 2 回。ここを型で 1 回に強制すると
  `CaptureCutData` の既存設計 (呼び出し側が渡し忘れない = T148 が閉じた形) を壊す。
  Codex 自身も「本質的な不変条件 (判定実装は 1 か所) へ表現を戻す」ことを推奨している。
- 対応内容: 詳細設計の docblock 文言を「判定式の実装は `AdoptedReadyTakeCoverage` 1 か所」へ訂正し、
  評価回数 1 回という誤った主張を削除する。

## [Warning] 施策3: 「採用済みだが ready でないテイク」が尺でも未確定になることの Feature テスト欠落

- 判断: 対応する
- 対応内容: 採用済み `processing`/`failed` テイクを持つカットで、
  `playback_url` / `download_ack_token` が null・`total_duration_ms` から除外・
  `undetermined_cut_count` が増える、の 3 点を同じテストで確認するケースを追加する。

## [Warning] 施策5: 全件未確定時の表示分岐がテスト期待値と矛盾

- 判断: 対応する
- 根拠: 現行 `$derived` は `undeterminedCutCount !== 0` なら常に「確定分・」を前置するため、
  全件未確定 (`totalDurationMs === null`) でも「確定分・未確定 5 カット」になり、
  テスト計画の「未確定 5 カット」(確定分表記なし) と矛盾する。指摘は事実。
- 対応内容: Codex 提示のとおり `totalDurationMs === null` かどうかで分岐する
  3 値の `$derived` に変更する。

## [Suggestion] 施策4: PHP キー集合 pin だけでは TS 型との 1:1 同期を保証しない

- 判断: 対応する
- 対応内容: `tests/js/pages/CaptureShow.test.ts` の fixture に
  `satisfies CaptureManualDetail` を付け、5 キーの欠落を型エラーとして検出できるようにする。

## [Suggestion] 施策5: アクセシビリティ (time要素・dl構造)

- 判断: 見送る
- 根拠: Codex 自身が「重大なアクセシビリティ欠陥ではない」と明記しており、
  今回の要件 (doc/05 §5.2) はメタ情報の表示そのものでマークアップの意味構造までは求めていない
  (今必要なものだけ作る)。将来 UI 全体で構造化マークアップの方針を決めるときに合わせて検討する。

## [Critical] 施策6: クエリ数テストが施策3の N+1 により必ず失敗する

- 判断: 対応する
- 対応内容: 施策3の修正 (takes の eager load + 引数渡し) により解消する。
  クエリ数テストは修正後の取得方式を前提に書き直す。

## [Warning] 施策6: クエリ数テストが「カット数」しか変えておらず「テイク数」の比例を固定していない

- 判断: 対応する
- 対応内容: 2 軸を独立に検証する2ケースへ分ける。
  (1) カット 1 本 / 10 本、各カットのテイク数は同じに揃える。
  (2) カット数は同じに揃え、テイクが 1 本のカットと複数本のカットを比較する。
  どちらも GET 1 回分の SQL 総数が同じであることを固定する。

## [Warning] 施策6: URL/ACK 回帰と尺集計を別々に確認するだけでは同じ ready 判定に従うことを固定しない

- 判断: 対応する
- 対応内容: 「採用済みだが ready でないテイク」の 1 fixture に対して
  URL・ACK・合計尺・未確定数の 4 点をまとめて確認するテストへ統合する
  (施策3 のテスト追加と実質同じテストとして 1 本化する)。

## セキュリティ総評: NestedRouteIdorDefenseTest inventory の確認

- 判断: 対応する (確認のみ。設計変更なし)
- 対応内容: 既存 route (`capture.manuals.show` 相当) であり新規 route ではないため
  inventory 追加は不要。実装時に既存登録が対象カバーしていることを確認する旨を
  詳細設計のリスク節へ明記する。
