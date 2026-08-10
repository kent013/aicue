Round 1 の指摘への対応マトリクスと、修正後の概念設計を送る。全体判定を再度出してほしい。

## 対応マトリクス (Round 1)

# 対応マトリクス: conceptual-review Round 1

## [Warning] 禁止事項 8 との整合を明文化せよ (プレビューボタンを disabled にしない)
- 判断: 対応する
- 根拠: 実装者が「未撮影なら押させない」に倒す余地を残さないため。設計の意図の核でもある。
- 対応内容: 「改善アイデア 判断 1」に *プレビューボタンは disabled にしない / 確認ダイアログも足さない* を
  明示条件として追記。詳細設計の受入条件にも引き継ぐ。

## [Warning] カット単位の判定 API が無いと manifest 側で判定が再実装される
- 判断: 対応する (指摘のとおり。判定単一化の目的を満たさない設計だった)
- 根拠: `RenderPipeline::clipSpecFor()` はカット単位で placeholder か否かを決める。集計 DTO しか
  提供しないと `adoptedTake === null || status !== Ready` が再び 2 箇所に書かれる。
- 対応内容: `AdoptedReadyTakeCoverage::isMissing(Cut $cut): bool` を**唯一の述語**とし、
  `for(VideoManual): TakeCoverageData` はその述語を畳むだけの実装にする。
  `clipSpecFor()` / `trigger()` の 422 / props の 3 消費者がすべて同じ述語を通る。

## [Warning] クラス名が「adopted 有無だけの判定」と混同されうる
- 判断: 対応する
- 根拠: 同リポジトリに `whereDoesntHave('adoptedTake')` (ready を見ない別基準) が既にあり、
  名前で区別できたほうが誤用が減る (思考原則「機能の名前に立ち返れ」)。
- 対応内容: `AdoptedTakeCoverage` → **`AdoptedReadyTakeCoverage`** に改名して記述を統一。

## [Warning] 成功条件が「事前表示と実 placeholder 数が常に一致」と読める
- 判断: 対応する
- 根拠: 事前表示は描画時点のスナップショットであり、常時一致は保証できない (嘘になる)。
- 対応内容: 成功条件を 2 本に分離し、「一致を期待するのは同一操作直後の通常ケースのみ」と明記。
  機械で固定するのは **render 422 の件数と同時点 coverage の件数の一致** (同一 tx・同一述語) に限定する。

## [Warning] `placeholder_cut_count` の値の意味 (null 契約) が曖昧
- 判断: 対応する
- 根拠: 既存行・失敗行・running 行・render 行で意味が違うと UI が誤読する。
- 対応内容: 値契約表 (historical/running/failed=null、succeeded preview=実数、succeeded render=0) を
  概念設計に追記し、DTO/TS も `?int` / `number | null` で受けると明記。

## [Warning] finalize が「現在状態から再計算」してはならない
- 判断: 対応する (設計意図どおりだが明文化されていなかった)
- 対応内容: `RenderManifest::placeholderCutCount` を必須条件として明記し、
  finalize は manifest 由来の値を書くだけであることを制約に格上げ。

## [Warning] Architecture gate の母集団が広すぎるとノイズになる
- 判断: 対応する (ただし「別基準の経路も母集団に入れて理由付き登録」は維持)
- 根拠: deny-by-default の価値は「新しい経路が黙って増えないこと」なので母集団は落とさない。
  一方でノイズ懸念は正しいので、登録済み経路には *別基準である* 旨を目録の理由に書く。
- 対応内容: gate の目的を「レンダ系の採用済み ready 判定を 1 箇所に閉じる」と定義し直し、
  ダッシュボード / 撮影ナビの `whereDoesntHave('adoptedTake')` は
  **`DifferentCriterion` 区分で理由付き登録**する形にする (母集団からは外さない)。

## [Warning] PHPStan level 10 のための型明示
- 判断: 対応する
- 対応内容: `missingLabels: list<string>` / `missingCutIds: list<int>` (持つ場合) /
  `placeholder_cut_count: ?int` を概念設計の時点で明記。


---

## 修正後の概念設計 (全文)

# 概念設計: preview-render-parity (プレビューと完成生成の判断基準を揃える)

> 対応 finding: bug-hunt run `20260811-003230` の **F-1-01 (High)**。
> 一次入力: `devnotes/20260811-0146-preview-render-parity/recon-brief.md`
> 実査で確認済みの再現: 67 カット中 1 カットだけ採用 → プレビュー生成が
> **約 201 秒の全編黒画面 mp4 を無警告で完了**。完成動画生成 (`projects.manuals.render`) は
> 同じ状態を **422** で明示ブロックし未採用カットを列挙する。

## 背景・課題

### 事実 (コードを読んで確認したもの)

| 経路 | 未採用カットの扱い | 実装位置 |
|---|---|---|
| `render` トリガー | **422** (`採用テイクが未設定のカットがあります: {label 列挙}`) | `RenderJobService::assertAllCutsHaveAdoptedReadyTakes()` (L363-377) |
| `preview` トリガー | **判定なし** (cuts が 1 件でもあれば 201) | `RenderJobService::triggerPreview()` (L126-168) |
| 合成 (manifest) | render=防御例外 / preview=`RenderClipSource::Placeholder` | `RenderPipeline::clipSpecFor()` (L240-273) |
| 合成 (ffmpeg) | Placeholder = `color=black` × `manual.preview_placeholder_seconds` (=3秒) + 字幕焼き込み | `FfmpegVideoComposer::planPlaceholder()` (L148-163) |

66 カット × 3 秒 = 198 秒 + 採用済み 1 カット ≒ **実査で観測された 201 秒**と一致する。
つまり黒画面は故障ではなく**設計どおりの出力**であり、壊れているのは
「その出力が何であるかをユーザーに一言も伝えていない」点である。

判定 (「採用テイクが ready で揃っているか」) は現在 **3 箇所に散っている**:
`RenderJobService::assertAllCutsHaveAdoptedReadyTakes()` / `RenderPipeline::clipSpecFor()` /
(preview には存在しない)。**乖離したのは基準が 1 箇所に無いから**である。

### 仮説

**プレビューの不具合は「止めなかったこと」ではなく「未撮影が結果に何をもたらすかを、
事前にも事後にも言わなかったこと」である。**

- 検証したいこと: 判定を 1 箇所に集約し、preview は**止めずに知らせる**形にすれば、
  「途中経過を見る」というプレビュー本来の用途を壊さずに F-1-01 を閉じられるか。
- 成功の判断 (Round 1 指摘を受けて 2 本に分離。**両者の常時一致は主張しない**):
  1. **同時点の一貫性 (機械で固定する)**: 同一の前提状態に対し、render の 422 が列挙する
     未採用カット数と、同じ時点の coverage が返す `missingCount` が**一致する**
     (= 同一述語から出ている)。
  2. **告知の存在 (機械で固定するのは存在と件数まで)**: (a) 押す前に未撮影件数が画面にあり、
     (b) 出来上がった動画の隣に**その動画が実際に含んだプレースホルダ件数**がある。
     1 と 2-(b) の値が一致するのは**同一操作直後の通常ケースだけ**であり、
     生成後に撮影が進めば当然ずれる (それが 2-(b) を別値として持つ理由である)。

## 改善アイデア

### 判断 1: プレビューはブロックしない (第三の道を採る)

recon-brief の設問 1 への結論。**preview を 422 にしない**。理由:

1. プレビューは「途中経過を見る」機能であり、**未撮影があること自体が正常な状態**である。
   422 にすると、撮影を 1 カットずつ進めながら仕上がりを確かめる中核ジョブが消える。
2. プレビューはチケット非消費 (無料) かつ manual status を触らない (編集と並走する) 設計で、
   render (課金・成果物確定・編集ロック) とは**リスクが違う**。同じ扱いにするのは
   「別物の概念を似ているから統合する」(思考原則 4) にあたる。
3. AGENTS.md 禁止事項 8 の思想 (必須条件未充足を理由に行き先を塞がない) と整合する。

**揃えるのは「判定」であって「制裁」ではない**。render は 422、preview は告知 — 入力が同じ
1 つの判定関数から出ていることを機械で固定する。

**明示条件 (実装が倒れてはいけない方向)**: プレビューボタンを未撮影を理由に `disabled` にしない。
押下前の確認ダイアログも足さない (無料・可逆・途中確認が正常用途のため摩擦を足す理由がない)。
= AGENTS.md 禁止事項 8 に抵触しない形を設計時点で固定する。

### 判断 2: 判定を 1 箇所に置く (recon-brief 設問 2)

`App\Services\Manual\AdoptedReadyTakeCoverage` (新規・状態なし) を**唯一の基準**とする。
名前で「adopted があるか」だけの別基準 (`whereDoesntHave('adoptedTake')`) と区別する。

- **述語 (唯一の基準の実体)**: `isMissing(Cut $cut): bool`
  = `cut->adoptedTake === null || adoptedTake->status !== TakeStatus::Ready`
  (現行 `assertAllCutsHaveAdoptedReadyTakes` と**同一の式**。基準そのものは変えない)
- **集計**: `for(VideoManual $manual): TakeCoverageData` は `CutSequencer::orderedWithLabels()` を
  走らせて上の述語を畳むだけ。`TakeCoverageData` は
  `totalCuts: int` / `missingLabels: list<string>` / `missingCount` (= `count(missingLabels)`)。
- **消費者は 3 つとも同じ述語を通る**:
  1. render トリガーの 422 (`RenderJobService::trigger`) — 集計 API 経由
  2. 詳細画面 props の事前告知 (`VideoManualController::show`) — 集計 API 経由
  3. manifest の Placeholder 分岐 (`RenderPipeline::clipSpecFor`) — **カット単位述語** `isMissing()` 経由
     (Round 1 指摘: 集計 API しか無いと 3 で式が再実装され、再び乖離する)

### 判断 3: 黒背景プレースホルダは残す (recon-brief 設問 3)

意図的な仕様なので消さない。代わりに**その存在を 2 段で告知**する:

- **事前** (押す前): `Manuals/Show` の props に coverage を載せ、`RenderPanel` の
  プレビューボタン近傍に「未撮影が n 件あります。プレビューは該当区間が黒背景になります」を出す。
  押下時の確認ダイアログは**足さない** (無料・可逆・途中確認が正常用途 = 摩擦を足す理由がない)。
- **事後** (出来たものの説明): `render_jobs.placeholder_cut_count` (nullable int) を新設し、
  **finalize (terminal tx) が `RenderManifest::placeholderCutCount` の値をそのまま書く**。
  プレビュー `<video>` の直上にこの値で注記する。

事後を props から再計算しないのは、**再計算はその動画の説明として誤りうる**からである
(プレビュー生成後に撮影が進めば「未撮影 2 件」と書きながら 66 件黒い動画を指すことになり、
今回直しているのと同じ種類の嘘を新規に作る)。**finalize でも「現在の manual 状態から数え直す」ことを
禁じる** — 値の出所は manifest (読み取り一貫性の確定点) 1 つに限る。

**値の契約 (Round 1 指摘。UI の誤読を防ぐため必須)**:

| 行の状態 | `placeholder_cut_count` |
|---|---|
| 本変更より前から存在する行 (historical) | `null` |
| queued / running | `null` |
| failed (finalize 未到達) | `null` |
| succeeded / kind=preview | 実際に含んだプレースホルダ数 (0 以上) |
| succeeded / kind=render | `0` (欠落は 422 とパイプライン防御例外で構造的に起き得ない) |

`null` は「その動画について**言えることが無い**」を意味する。UI は `null` のとき注記を出さない
(0 と null を同一視しない)。DTO/TS も `?int` / `number | null` で受ける。

### 判断 4: 再発防止 (recon-brief 設問 4)

- **Architecture gate (deny-by-default)**: gate の目的は
  **「レンダ系 (render / preview) の『採用済み ready』判定を 1 箇所に閉じる」**こと。
  `adoptedTake` に触れる app/ 配下のファイルを目録に固定し、各エントリを
  `Canonical` (述語の実体) / `Delegated` (述語を呼ぶだけ) / `DifferentCriterion`
  (ready を見ない別基準 = ダッシュボード等) のいずれかで**理由付き登録**する。
  母集団は落とさない (落とすと新経路が黙って増える) が、別基準の経路は区分で仕分けて
  ノイズにしない。`ScenarioWritePathInventoryTest` の検出 4 (`adopted_take_id` の
  ファイル allowlist) と同じ流儀。
- **behavioral な核**: 同一 fixture (n-1/n 未採用) に対し render 422 の列挙件数と
  preview 側 coverage の件数が**一致する**ことを Feature テストで固定する
  (「乖離しない」を型ではなく値で押さえる)。

## 期待効果

- **使命への貢献**: 「SOP → シナリオ → ナビ撮影 → 確認」の確認ステップが機能を取り戻す。
  黒画面を「アプリが壊れた」と誤解して離脱する経路を消す (中核体験の信頼)。
- プレビューを止めないため、**1 カットだけ撮って確かめる**という正常な使い方は温存される。
- 判定が 1 箇所になるので、今後 render / preview / 別経路が増えても基準が分岐しない。

## 実装方針（概要）

| # | 施策 | 主な変更 |
|---|---|---|
| 1 | 判定の単一化 | `AdoptedReadyTakeCoverage` (`isMissing()` + `for()`) + `TakeCoverageData` 新設。`RenderJobService` / `RenderPipeline` を委譲に置換 |
| 2 | 事前告知 | `VideoManualController::show` の `render` props に coverage 追加 → `RenderPanel` に注記 |
| 3 | 事後説明 | `render_jobs.placeholder_cut_count` 追加 (migration + model + factory)、`RenderManifest` から finalize で記録、`RenderJobData` 経由で UI へ |
| 4 | 再発防止 | Architecture gate + Feature/Browser テスト |

## 制約・前提

- **ロック順序**を変えない (`render_jobs → video_manuals → ticket_reservations → organizations`)。
  施策 3 の書き込みは buildManifest (manual を先にロックする) ではなく **finalize** (job → manual の
  正順) で行う。
- 422 の**既存メッセージ本文と契約は変えない** (`RenderTriggerTest` 等の既存テストを壊さない)。
- preview 201 のレスポンス shape (`RenderJobResource`) は**壊さない**。追加は
  `placeholder_cut_count` の 1 フィールドのみ (nullable。TS `RenderJobProps` と対で更新)。
- フロントは DESIGN.md token 経由。注記は既存 `Alert` atom (`type="warning"`) と
  `text-caption text-text-secondary` を使い hex 直書きを増やさない。層は
  `features/manual/RenderPanel.svelte` のみ (atom/molecule の新設なし)。

## スコープ外 / 保証しないもの

- **黒背景プレースホルダの仕組みは消さない**。プレースホルダに「未撮影」テロップを
  焼き込む案 (bug-hunt の候補 b) も**採らない** — 字幕合成の仕様変更であり、
  finding を閉じるのに必要な最小形を超える (思考原則 2)。
- 生成された動画の品質評価は扱わない。
- ダッシュボード / 撮影ナビの「撮影待ち」カウント (`whereDoesntHave('adoptedTake')` =
  **ready 状態を見ない別基準**) は本設計では統合しない。gate の母集団には含めるが
  `DifferentCriterion` 区分で登録し、統合の是非は別 TODO とする
  (この 2 面では「採用済みだが ready でないテイク」を撮影済みとして数える差が**残る**)。
- **型の明示 (PHPStan level 10)**: `missingLabels: list<string>` /
  `placeholder_cut_count: ?int` / TS 側 `number | null`。Resource は DTO の
  `toArray()` を返し暗黙 mixed を作らない。
- 事前告知の coverage は**ページ描画時点のスナップショット**であり、別タブ・別ユーザーの
  撮影で古くなる。押下を止めないので詰みは作らないが、「常に最新」とは言わない。
- 自然言語メッセージの**文意**は機械照合しない (testId と件数の一致までを固定する)。

