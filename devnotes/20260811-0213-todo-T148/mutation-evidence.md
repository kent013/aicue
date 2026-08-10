# T148 mutation 実測記録

詳細設計 `devnotes/20260811-0146-preview-render-parity/detailed-design.md` §「mutation で赤化を確認する手順」の
M1〜M9 に加え、Codex 実装レビューの指摘で追加した M10・M11 を実施した記録。**入れた mutation はすべて元に戻した** (最終 diff に mutation は残っていない。
実施後に対象テスト群を再実行し全 green を確認済み)。

**設計の予測と実測がずれた箇所は辻褄を合わせず記録する** (M7)。

| # | 変異 | 設計の予測 | 実測 | 一致 |
|---|---|---|---|---|
| M1 | `RenderPipeline::clipSpecFor()` の `isMissing()` 呼び出しを元の `$take === null \|\| $take->status !== TakeStatus::Ready` に戻す | 検出 B が 2 ファイルになりケース 4 が fail | ケース 4 が fail (`Services/Manual/RenderPipeline.php` が検出 B に混入) | ✅ |
| M2 | 新ファイル `app/Services/Manual/DummyProbe.php` に `$cut->adoptedTake` を 1 行書く (目録に足さない) | ケース 1 が fail | ケース 1 が fail (`Services/Manual/DummyProbe.php` が未登録として検出) | ✅ |
| M3 | 目録に `Models/Cut.php` を残したまま relation 名を `adoptedTakeRenamed` へ変える | ケース 2 が fail | ケース 2 が fail (stale entry `Models/Cut.php`) | ✅ |
| M4 | 走査ルートを参照を持たないディレクトリ (`app/Enums/Manual`) へ差し替える | ケース 3・5 が fail | ケース 2・3・5・8 が fail (**設計の予測より広く赤くなる**。exact-fit のケース 2 と免除の stale 検査ケース 8 も同時に落ちる = 空振り検出としてはより強い) | ⚠ 予測より広い |
| M5 | `AdoptedReadyTakeCoverage::isMissing()` から `status !== Ready` を落とす | `PreviewCoverageParityTest` A-6 が fail | A-6 (uploading/processing/failed の 3 データセット) + A-3 が fail | ✅ |
| M6 | `triggerPreview()` に render と同じ 422 を足す | A-2「preview は 201」が fail | A-2 が fail | ✅ |
| M7 | `finalize` を manifest 由来ではなく現在状態からの再計算に変える | `RenderPlaceholderCountTest` B-4「生成後に採用しても件数が変わらない」が fail | **1 回目は全 green (予測はずれ)**。詳細は下記 | ❌ → 対処後 ✅ |
| M8 | `VideoManualController::show` から `coverage` を落とす | Feature の props テストと `RenderPanel.test.ts` が fail | `PreviewCoverageParityTest` が 8 件 fail、`ManualsShow.test.ts` が 10 件 fail | ✅ |
| M9 | 注記を `playbackJob` ではなく最新 `preview` job の値から出すように戻す | `RenderPanel.test.ts` D-6 が fail | D-4 と D-6 が fail | ✅ |
| M10 | (設計に無い追加。Codex Round 1 [Critical] 対応の確認) 免除ファイル `PipelineSmokeCommand.php` の `doesntHave('adoptedTake')` を `whereDoesntHave('adoptedTake', fn ($take) => $take->where('status', TakeStatus::Ready->value))` へ変える (**直接 callback 形**) | — (設計は前提 2 を持っていなかった) | ケース 8 が fail。**前提 2 を足す前は green だった** = Codex の指摘どおり穴が実在した | ✅ 対処後 |
| M11 | (設計に無い追加。Codex Round 2 [Critical] 対応の確認) 同ファイルで callback を変数へ切り出す `$scope = fn ($take) => $take->where('status', TakeStatus::Ready->value); $manual->cuts()->whereHas('adoptedTake', $scope)` (**変数 callback 形**) | — | ケース 8 が fail。**引数リスト検査版の前提 2 では green だった** = Codex Round 2 の指摘どおり穴が実在した。前提 2 を「参照形の exact-fit」へ作り直して閉じた | ✅ 対処後 |

## M7: 設計の予測と実測のずれ (辻褄を合わせずに記録)

**設計の予測**: 「finalize を現在状態からの再計算に変えると B-4 が fail する」。

**実測 (1 回目)**: `RenderPlaceholderCountTest` は **7 件すべて green のまま**だった。

**原因**: B-4 が固定していたのは「**finalize が終わった後**にテイクを採用しても記録済みの値が
書き換わらない」ことだけである。finalize 時点での再計算は、その時点の現在状態が manifest 時点と
一致していれば同じ値を出すため、B-4 では区別できない。**設計が「再計算禁止」の behavioral 固定と
みなしていたテストは、実際には「読み取り時の遅延再計算の禁止」しか固定していなかった。**

**対処**: fake composer に `duringCompose` hook を足し、**buildManifest の後・finalize の前**に
テイクを採用するテスト B-4b を追加した (manifest 由来なら 2、finalize 時点の再計算なら 1 になる
fixture)。B-4 は「読み取り時の再計算禁止」の固定として残し、削除も上書きもしていない。

**再実測**: 同じ M7 変異で `B-4b: 合成中に採用しても記録されるのは manifest 時点の件数である
(finalize での再計算禁止)` が `Failed asserting that 1 is identical to 2.` で fail した。
変異を戻すと 7 → 8 件すべて green。

## 設計からの逸脱 (実装時に判明した実在との差)

**検出 B の期待集合**: 設計は「検出 B == `{Services/Manual/AdoptedReadyTakeCoverage.php}`」(厳密に 1 件) と
書いていたが、実装時に `rg -n "adoptedTake" app/` と `rg -n "TakeStatus::" app/` を再実行したところ、
`Console/Commands/Development/PipelineSmokeCommand.php` が**本変更以前から**両者を同一ファイルに
持っていた (L576 の `doesntHave('adoptedTake')` による未採用件数集計と、L630 の
「登録したテイク自身が ready か」の確認。**両者は同じ式ではない**)。

設計どおり「厳密に 1 件」を assert すると、この既存の無関係な同居で常時赤になる。判定を弱めず、
かつ既存の同居を許すために、**名指し免除 + 機械検査される前提**の形にした
(`ThrottleExemptionPremiseTest` / `IdempotencyExemptionPremiseTest` と同じ流儀):

- `COOCCURRENCE_EXEMPT` に 30 文字以上の根拠付きで 1 件だけ登録する
- ケース 8 が前提を機械検査する (**2 層**)
  - 前提 1 (in-memory 形): 免除ファイルは `->adoptedTake` のプロパティフェッチ形を一切持たない
    (= relation の実体を参照しないので `$take->status !== TakeStatus::Ready` を書けない)
  - 前提 2 (参照形の exact-fit): `'adoptedTake'` の出現が**すべて** `->doesntHave('adoptedTake')` の
    単独引数形である (= callback も第 2 引数も持てないので DB 側の判定を書けない)
- ケース 8 は stale 免除も落とす (免除対象が検出 B から外れたら免除ごと削除させる)

**前提 2 は 2 度作り直している** (Codex 実装レビューの指摘 2 連続。経緯を残す):

1. 最初は前提 1 だけだった → Codex Round 1 [Critical]: 直接 callback 形
   (`whereHas('adoptedTake', fn ($q) => $q->where('status', ...))`) が素通りする (M10 で実証)
2. 「relation 引数リスト内に `TakeStatus::Ready` / `'status'` が無いこと」を足した → Codex Round 2
   [Critical]: **callback を変数へ切り出す形** (`$scope = fn (...) => ...;
   whereHas('adoptedTake', $scope)`) が素通りする (M11 で実証)。引数リストを見る限り
   データフロー解析なしには閉じない
3. 「参照形そのものの exact-fit」へ作り直した = 免除ファイルに許すのは
   `->doesntHave('adoptedTake')` の単独引数形だけ。M10・M11 の両方が ケース 8 を赤くする

保証しないもの: 前提 2 が固定するのは**免除ファイルの参照形**だけである。免除ファイルが relation を
一切使わずに (`Take::query()` 等で) 採用テイクの status を判定する経路には沈黙する
(テスト冒頭のコメントに明記済み)。
