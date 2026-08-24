# Round 2: Round 1 指摘への対応と再レビュー依頼

## Claude 側の対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] 5. kind 別 ability に分岐する順序を明記せよ
- 判断: **対応する**(一部反論)
- 根拠: 指摘のとおり、既存の一律 `Gate::authorize('render', $manual)` のままでは
  「完成動画の受け取りは `download` ability」という設計が成立しない。
- 対応内容: 概念設計 §改善アイデア 2 に順序を明記した (層 2 の 404 三段 → kind から ability を
  写して authorize → kind=render のみ published 404 → current 照合 404)。
  **反論**: 提案コードの `else { abort(404); }` は採らない。`RenderKind` は
  `Preview` / `Render` の 2 値 enum であり、`match ($renderJob->kind) { ... }` で書けば
  網羅性は型で保証され、到達不能な dead branch を作らない (PHPStan level 10 でも
  不要分岐は指摘対象になりうる)。詳細設計では match 式で ability 名を写す形にする。

## [Critical] 8. project / manual / renderJob の所属確認 (層 2) を曖昧にするな
- 判断: **対応する**
- 根拠: セキュリティ不変条件 2/10 の要求そのもの。設計書に書いていないと実装で落ちる。
- 対応内容: 概念設計 §制約・前提 に三段の担保機構 (middleware + inline guard /
  `Route::scopeBindings()` / `video_manual_id` inline 再検査) を実コードの語彙で明記した。

## [Warning] 1. 期待効果が過大 (撮影者は観られないまま)
- 判断: **対応する**
- 根拠: 事実そのとおり。誇張は本リポジトリの規約 (保証しないものを明記する) に反する。
- 対応内容: 期待効果を「編集者がアプリ内で確認できる」に限定し、撮影者への視聴開放は
  完了条件に含めないと明記した。

## [Warning] 2. DL ボタンの表示条件から canManage を外すな
- 判断: **対応する**(専用 props の新設は見送り)
- 根拠: `canManage` は既に props にあり UI もそれで分岐している。
  `canDownloadFinishedVideo` を新設するのは同じ意味の値の二重管理であり思考原則 2 に反する。
- 対応内容: 条件を `finishedJob !== null && canManage` と明記。新 props は作らない。

## [Warning] 3. CurrentRenderArtifact の責務境界が曖昧
- 判断: **対応する**
- 根拠: 妥当。published / ability を service に混ぜると「成果物選択」以外の意味が入り、
  名前が役割を示さなくなる (思考原則: 機能の名前に立ち返れ)。
- 対応内容: メソッドを `currentSucceeded(VideoManual, RenderKind): ?RenderJob` の 1 本に限定。
  published 判定と ability 判定は呼び出し側に置く。

## [Warning] 4. route 側でも current job との同一性を確認せよ
- 判断: **対応する**(元設計の意図を明文化)
- 対応内容: 順序 ④ として「`currentSucceeded()` の結果と同一行か」を照合すると明記。

## [Warning] 5. published 404 は authorize の後に置け (download と同順)
- 判断: **対応する**
- 対応内容: 順序 ② → ③ の並びで明記。

## [Warning] 6. Architecture gate の対象を絞れ
- 判断: **対応する**
- 対応内容: 「`JobStatus::Succeeded` + 最新 1 件取得を併用した `render_jobs` からの成果物選択」を
  母集団とし、exact-fit の目録で `CurrentRenderArtifact` に限定すると明記した。

## [Warning] 7. output_path の非 null 性を呼び出し側で再確認せよ
- 判断: **対応する**(詳細設計で具体化)
- 対応内容: service は `?RenderJob` を返し、署名 URL 発行の直前に
  `$path = $job->output_path; if ($path === null) { abort(404); }` を置く (専用 VO は作らない)。

## [Warning] 8. inline disposition で開く経路が増えることをテストで固定せよ
- 判断: **対応する**
- 対応内容: 指摘のテストケース 5 種を詳細設計のテスト計画に入れる。

## [Suggestion] 3 / 6 / 7
- 判断: 参考として受領 (設計変更なし)。


---

## 修正後の概念設計 (全文)

# 概念設計: render-playback (完成動画をアプリ内で観られるようにする)

## 背景・課題

実査ブリーフ (`recon-brief.md`) が「中核体験の最後の一歩が片道で切れている」と判定した項目。
実コードで確認した事実:

- `ManualRenderController::playback()` は `kind !== RenderKind::Preview` を **404** にする
  (L106-110)。**アプリ内で観られるのはプレビューだけ**。
- 完成動画の受け取り口は `projects.manuals.download` の 1 本だけで、署名 URL は
  `attachment` disposition (`RenderObjectStorage::temporaryDownloadUrl`)。**手元に落として
  外部プレイヤーで開く**しか結果に到達する手段がない。
- `RenderPanel.svelte` も `status === "published" && canManage` のときに DL ボタンを出すだけで、
  再生要素 (`<video>`) はプレビュー用の 1 つしかない。

「思考ゼロ・編集ゼロ」(AGENTS.md 使命) を掲げながら、**制作フローの最終段だけが
アプリ外の手作業**になっている。

### 併せて見つけた不整合 (副次課題)

「今どの成果物を受け取れるか」の判定式が **3 箇所に複製**されており、しかも**同一でない**:

| 場所 | 式 |
|---|---|
| `ManualRenderController::isLatestSucceededPreview()` | 「より新しい succeeded preview が存在しない」 |
| `VideoManualController::show()` の `$playbackJob` | 「`output_path` 非 NULL の succeeded preview のうち最新」 |
| `ManualDownloadController::show()` | 「`output_path` 非 NULL の succeeded render のうち最新」+ published |

保持ポリシーの実体 (`RenderJobService::newerSucceededExists()` / `DeleteRenderOutputsJob`) は
**「同 manual・同 kind でより新しい succeeded が 1 件でもあれば実体を消す」**である。
よって「最新 succeeded の `output_path` が NULL」という異常データでは、props 側の式だけが
**削除済みの旧世代**を選び、`<video>` が 404 を踏む。頻度は低いが、式が 3 本ある限り
今回の追加でさらに 1 本増える。

## 改善アイデア

1. **「今受け取れるレンダ成果物」を決める式を 1 ファイルに集約する**
   (`App\Services\Manual\CurrentRenderArtifact`)。**メソッドは 1 本だけ**:
   `currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob`
   =「同 manual・同 kind の最新 succeeded を 1 件取り、その `output_path` が NULL なら `null`」。
   保持ポリシー (`newerSucceededExists` / `DeleteRenderOutputsJob`) と**同じ世代定義**である。
   **published 判定も ability 判定もこの service に入れない** (Round 1 [Warning] 3)。
   消費者は 3 つ: playback / download / 詳細画面 props。
2. **既存 playback route を kind=render にも開く** (新 route を増やさない)。
   完成動画の再生条件は **download と同一**にする (published + 現行の完成成果物 + `download` ability)。
   preview 側の 404 条件・ability は**一切変えない**。
   認可・404 の順序は既存 download と揃える (Round 1 [Critical] 5 / [Warning] 5):
   ① 層 2 の 404 三段 (project ∈ current org / manual ∈ project / renderJob ∈ manual)
   → ② `kind` から ability を写して `Gate::authorize` (preview→`render` / render→`download`)
   → ③ kind=render のみ `status !== Published` を 404
   → ④ `currentSucceeded($manual, $renderJob->kind)` と**同一行か**を照合し、違えば 404。
   ④ が「旧世代 job id の直叩き」も同時に閉じる。
3. **詳細画面に完成動画のプレイヤーを出す** (`RenderPanel.svelte`)。props は T148 で導入済みの
   `playbackJob: RenderJobProps` と**同じ形**の `finishedJob: RenderJobProps | null` を足す
   (独自形を作らない)。DL ボタンは残し、表示条件を `status === "published" && canManage` から
   **`finishedJob !== null && canManage`** へ変える (`canManage` は外さない。Round 1 [Warning] 2。
   押すと 404 になる異常データの穴も同時に閉じる)。完成動画プレイヤーの表示条件も同じ。

### なぜ job 単位の URL のままにするか

manual 単位の URL (`.../manuals/{manual}/watch`) にすると、再レンダ後も URL 文字列が
変わらないため、**ブラウザが古いメディアを再生し続けうる** (`router.reload()` は
`src` 文字列を変えない)。job id を URL に含める既存の形 (`render-jobs/{renderJob}/playback`) は
世代が変われば URL が変わるので、この問題が構造的に起きない。既存 route を使う根拠でもある。

## 期待効果

- **使命**: **編集者 (project_admin / 組織管理者)** が完成動画をアプリ内で確認できるようになり、
  「DL → 外部プレイヤーで開く」という最後の手作業が消える。
  - **誇張しない**: 本 TODO の完了条件に「撮影者 (project_member) 本人が完成物を観られる」ことは
    **含まない**。`download` ability は編集者のみのままであり、撮影者への視聴開放は別 TODO である
    (Round 1 [Warning] 1 を受けて限定)。
- **副次**: 成果物選択式が 1 本になり、props と route が別世代を指す穴が構造的に消える
  (T148 が「注記と動画 URL は同一オブジェクトから出す」でやったことの、成果物選択側の対応物)。
- **回帰の縮小**: DL ボタンが「押すと 404」になる状態 (published だが succeeded render 無し) が
  UI から消える。

## 実装方針 (概要)

| # | 変更 | 対象 |
|---|---|---|
| 1 | 成果物選択式の集約 | `app/Services/Manual/CurrentRenderArtifact.php` (新規) |
| 2 | playback を kind=render へ拡張 | `ManualRenderController::playback()` |
| 3 | download を集約式へ載せ替え | `ManualDownloadController::show()` |
| 4 | props に `finishedJob` 追加 | `VideoManualController::show()` / `types/manual.ts` |
| 5 | 完成動画プレイヤー | `RenderPanel.svelte` / `Manuals/Show.svelte` |
| 6 | 不変条件の機械化 | `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (新規) |

施策 6 の**機械化対象は 1 つに絞る** (Round 1 [Warning] 6): 「`app/` 配下で
`JobStatus::Succeeded` と最新 1 件取得 (`latest('id')` / `orderByDesc('id')`) を併用して
`render_jobs` から成果物を選ぶファイル」を deny-by-default の目録で固定し、
登録できるのは `CurrentRenderArtifact` と根拠付き例外だけにする (exact-fit)。
「曖昧な広い目録」にはしない。

route は**増やさない**。DTO は既存 `RenderJobData` をそのまま使う (新 shape を作らない)。

## 制約・前提

- **テナント境界・認可を緩めない** (AGENTS.md セキュリティ不変条件 2/3/9)。
  層 2 の 404 は**三段すべて authorize より前**のまま (Round 1 [Critical] 8):
  ① `{project}` ∈ current organization = `project.in-route-org` middleware +
  `resolveOrganizationProject()` の inline guard、② `{manual}` ∈ `{project}` =
  `routes/web.php` の `Route::scopeBindings()` (`$project->manuals()` 経由)、
  ③ `{renderJob}` ∈ `{manual}` = scopeBindings + controller の
  `$renderJob->video_manual_id !== $manual->id` inline 再検査。
  `projects.manuals.render-jobs.playback` は `NestedRouteDefenseInventory` に登録済み
  (route を増やさないので inventory も不変)。
- **ability は成果物の性質に従う**: kind=preview は `render`、kind=render は `download`。
  現状どちらも `ProjectPolicy::update` に落ちるため**現時点の可否は不変**だが、将来
  download を視聴者へ開くときに再生が自動追従する結線にしておく。
- **保持ポリシーと矛盾しない**: 署名 URL を出してよいのは「実体が消されない世代」だけ
  (`newerSucceededExists` が false の行) である。
- **T148 の告知契約**: 完成動画の `placeholder_cut_count` は値契約上 `0` (または本列以前の
  行の `null`)。UI の既存規則 (`> 0` のときだけ注記) をそのまま適用すれば**完成動画には
  注記が出ない**ため、分岐を足さない。

## スコープ外 (今回やらないこと)

- **ダウンロード経路の削除**: 残す (手元に落とす需要は別)。
- **撮影者 (project_member) への視聴開放**: `download` ability は編集者のみのままにする。
  視聴面 (配信・受講) は別機能であり、本 TODO で認可を緩めない。
- **published でない manual の旧完成動画の再生**: シナリオを編集すると `status` が `ready` に
  戻り、既存 DL も 404 になる。**この非対称は変えない** (DL と同じ条件に揃えるだけ)。
- **撮影 PWA からの戻り導線**: 別ユーザージョブ (ナビゲーション) であり変更ファイルも
  検証レーンも別。**別 TODO とする** (本設計には含めない)。
- **多言語 (`?lang=`)**: v1 の扱いが未確定のため触らない。


---

Round 1 の指摘に対する上記の対応 (および match 式に関する反論) を評価し、
全体判定 (APPROVED / CHANGES_REQUESTED) を出せ。
残る指摘があれば [Critical] [Warning] [Suggestion] で分類し、修正案を添えること。
