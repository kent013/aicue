# Round 3: Round 2 指摘への対応と再レビュー依頼

## Claude 側の対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Warning] 1. unpublished manual でも `finishedJob` が生成され「押すと 404」が再発する
- 判断: **対応する**(UI 側の二重判定のみ見送り)
- 根拠: 指摘のとおり。`CurrentRenderArtifact` から published を外した結果、条件を
  呼び出し側で書く責任が生じており、props 側にそれを明記していなかったのは設計の穴。
- 対応内容: 概念設計に「props は `status === Published` のときだけ `finishedJob` を組み立てる」
  を明記。Feature テスト「ready に戻った manual では `finishedJob=null`」を計画に入れる。
  **見送り**: UI 側で `status === "published"` を再判定する案は採らない。同じ規則を props と
  UI の 2 箇所に持つと、どちらが正かが曖昧になり drift する (T148 が「注記と URL を同一
  オブジェクトから出す」で潰したのと同じ種類の穴)。判断は props で 1 回に閉じる。
  書き出し中 (`rendering`) の除外は、完成動画ブロックを既存 DL ボタンと同じ
  `{#if rendering}…{:else}` の else 枝に置くことで構造的に行う。

## [Warning] 2. `canManage` は `download` ability の恒久的な代理にならない (誇張)
- 判断: **対応する**(前者 = 主張を削る)
- 根拠: 指摘が正しい。UI は `canManage` のままなので「自動追従」は嘘になる。
  `canDownload` props の新設は現時点で可否が完全同値であり、思考原則 2 に反する。
- 対応内容: 「将来自動追従する」記述を削除し、「現行では完全に同値」「policy が分岐した日には
  props と UI も併せて変える必要がある (自動では追従しない)」と明記した。

## [Suggestion] 3. Architecture gate の検出条件を詳細設計で実証せよ
- 判断: **対応する**
- 対応内容: 詳細設計のテスト計画に、(a) 移設**前**に既存 3 経路すべてが母集団へ入ること
  (負のコントロール)、(b) 母集団 0 件なら gate 自身が fail すること、(c) 移設後は
  `CurrentRenderArtifact` だけが残ること (exact-fit) を、mutation 手順つきで書く。


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

### props の `finishedJob` は endpoint と**同じ条件**で組み立てる (Round 2 [Warning] 1)

`CurrentRenderArtifact` は published 判定を持たないため、props 側で
**`$manual->status === VideoManualStatus::Published` のときだけ** `finishedJob` を組み立て、
それ以外は `null` にする。これを守らないと、シナリオ編集で `ready` に戻った manual で
「プレイヤーと DL ボタンは出るが押すと 404」が**再発する** (本設計が閉じると言った穴と同種)。
UI 側で `status === "published"` を再判定する二重管理はしない
(判断は props で 1 回。UI 条件は `finishedJob !== null && canManage`)。
書き出し中 (`rendering`) は、完成動画ブロックを既存 DL ボタンと同じ
`{#if rendering}…{:else}` の else 枝に置くことで**構造的に**除外する。

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
  現状どちらも `ProjectPolicy::update` に落ちるため**現時点の可否は完全に同値**である。
  - **誇張しない** (Round 2 [Warning] 2): これは「将来 download を視聴者へ開けば UI も
    自動追従する」という保証**ではない**。UI は既存の `canManage` (= `update` ability) を
    使い続けるため、policy が分岐した日には props と UI も併せて変える必要がある。
    ここで正しい ability 名を使うのは、サーバ側の意味を実装に残すためである。
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

全体判定 (APPROVED / CHANGES_REQUESTED) を出せ。残る指摘は分類と修正案を添えること。
