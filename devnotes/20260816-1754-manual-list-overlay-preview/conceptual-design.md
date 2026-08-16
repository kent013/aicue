# 概念設計: manual-list-overlay-preview (動画一覧からのオーバーレイプレビュー)

## 背景・課題

`doc/04_PCサイト機能仕様.md` §動画一覧ページ（ホーム）の**操作**要件は
「プレビュー（オーバーレイ、再生/停止/音量/言語切替）、ダウンロード、削除、新規動画作成、編集画面遷移、ページネーション」である。

T182 で一覧に「再生時間 / DL / 削除」が入り、T184/T185/T186 で撮影・採用系が揃ったが、
**一覧から完成動画を再生する導線だけが無い**。現状の再生導線は

- マニュアル詳細画面 `Manuals/Show` の `RenderPanel.svelte` 内 `<video>` (完成動画 = `finishedJob`、プレビュー = `playbackJob`)

の 1 か所だけで、一覧から「この動画で合っているか」を確かめるには
**行 → 詳細画面へ遷移 → RenderPanel までスクロール**という往復が要る。
一覧は「作った動画マニュアルを棚卸しする面」であり、確認のたびに画面遷移するのは
使命の「編集ゼロ（作った後の確認・配布までを迷わせない）」に対する素直な後退である。

## 改善アイデア

一覧の行に**プレビュー導線**を 1 つ足し、押すと**オーバーレイ (Modal)** の中で完成動画を再生できるようにする。

決定事項は 5 つ。

### 決定 1: 再生制御はブラウザ標準の `video controls` で足りる (自前の再生制御を作らない)

doc/04 が挙げる「再生/停止/音量」は `<video controls>` が標準で備え、全画面・シークも付いてくる。
既存の `RenderPanel.svelte` も同じ判断で `<video controls>` を使っている (自前プレイヤーは無い)。
**今必要なものだけ作る**(思考原則 2) に従い、再生ボタン・音量スライダ・シークバーを自作しない。
=> 一覧側に新しい再生制御コードは 1 行も増えない。増えるのは「どの URL を、いつ DOM に載せるか」だけ。

### 決定 2: 言語切替は作らない (v1 確定スコープの読み替え)

doc/04 の「言語切替」「言語選択して mp4」は多言語版が存在する前提の要件だが、v1 は

- `AGENTS.md` v1 スコープ = **字幕のみ (TTS 後回し)**
- `config/app.php` の `locale` = `ja`
- `doc/10_実装仕様.md` は `feature_multilang` を **Quota キーとして予約しているだけ**で、
  多言語のレンダ経路・原稿・成果物の実装は無い (§144 / §264)。
  同 §166 の `.../download?lang=` も**現行実装には無いパラメータ**である
  (`ManualDownloadController` は `lang` を一切参照しない)

であり、**切り替える対象の成果物が 1 つも無い**。言語セレクタを置けば
「選べるのに 1 つしかない / 選んでも何も変わらない」UI になるため作らない。
多言語が入る日に、その PR が「成果物の選択軸」として一覧・詳細・DL を**まとめて**設計する。
本設計は再生する成果物を「現行世代の完成動画 (kind=render) ただ 1 本」に固定する。

### 決定 3: 再生 URL は既存 playback endpoint を使う (受け取り口を増やさない)

- **DL endpoint (`projects.manuals.download`) は再生に使えない**。
  `RenderObjectStorage::temporaryDownloadUrl()` は署名に
  `ResponseContentDisposition: attachment; filename=...` を含めるため、
  `<video src>` に与えると「再生」ではなく「保存」の意味の応答になる。
  再生用は `temporaryPlaybackUrl()` (disposition 無し) 側である。
- ドメイン規約 13 (T154) は **「成果物の受け取り口は route を増やさない。`playback` が preview と
  完成動画の両方を扱う」** と定めている。よって manual 単位の新 route
  (`.../manuals/{manual}/playback` 等) は**新設しない**。
- 使うのは既存の `projects.manuals.render-jobs.playback`
  (`GET /projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback`)。
  kind=render の再生条件は download と**完全同一**
  (published + 現行世代 + download ability + 同じ評価順序) であり、
  「一覧に DL を出している行」と「一覧に再生を出せる行」は**同じ集合**になる。
  `RenderPanel` の完成動画 `<video>` もこの route を使っている (作法をそのまま踏襲する)。

### 決定 4: 行 props は `downloadable: bool` を `current_finished_render_job_id: int|null` へ**置き換える**

playback route は render job id を要求するので、行に id が要る。
ここで `downloadable`(bool) を残したまま id を足すと、**同じ条件を 2 つの props で表す**ことになり、
どちらかが欠けた日に「DL は出るが再生は出ない」といった食い違いが**赤くならずに**発生する。
後方互換の並走を残さない (思考原則 3) / 判断を 2 箇所に持たない (T154 の流儀) に従い、
**1 本の props に統合する**:

- `current_finished_render_job_id: int|null` — 「いま受け取れる完成動画」の render job id。
  `null` = 受け取れない。**非 null であること自体が従来の `downloadable === true` と同値**
  (download ability × published × 現行世代の succeeded render に `output_path` がある)。
- UI は `current_finished_render_job_id !== null` **だけ**で DL 導線とプレビュー導線の両方を出す
  (published も権限も UI 側で再判定しない = 現行 `ManualListRow` の約束を維持)。
- これは `RenderPanel` の `finishedJob` (行そのものを渡し、UI は `!== null` だけ見る) と同じ形である。
  一覧は 1 ページ 10 行のリストなので、行オブジェクトではなく**必要な 1 値 (id) だけ**を運ぶ。

### 決定 5: 署名 URL はページに載せない (3 枚セットを壊さない)

`<video src>` に入れるのは**同一オリジンのアプリ route** (302 で S3 署名 URL へ飛ぶ) であり、
Inertia props にも HTML にも署名 URL は現れない。よって

- (A) no-store baseline / (B) bfcache 秘匿 / (C) Inertia history 暗号化 (ドメイン規約 3)

のいずれの前提も変わらない (履歴に残るのは同一オリジンのパスだけで、失効しない外部 URL ではない)。
署名 URL の発行は**オーバーレイを開いた時だけ**起きる (閉じている間 `<video>` は DOM に無い)。
TTL は `config/manual.php` の `render_playback_url_ttl_minutes` (10 分) をそのまま使う。

## 期待効果

- **使命への貢献**: 「編集ゼロ」で作った動画マニュアルを、一覧のその場で確認できる。
  棚卸し (どれが完成しているか / 中身は合っているか) が画面遷移なしで回る。
- doc/04 の一覧ページ操作要件のうち、未充足だった「プレビュー（オーバーレイ）」が埋まる
  (言語切替は決定 2 の理由で v1 スコープ外と明記して閉じる)。
- 実装量が小さい: 新しい route / Service / ジョブは 0 本。
  増えるのは行 props 1 本の置換、モーダル component 1 本、行のボタン 1 つ。

## 実装方針（概要）

| 層 | 変更 |
|----|------|
| DTO | `ManualListItemData`: `downloadable: bool` → `current_finished_render_job_id: int\|null` (判定式は現行のまま。値を id にするだけ) |
| Controller | `ProjectController::manualRows()` の PHPDoc array shape を追随 |
| TS 型 | `resources/js/types/manual.ts` の `ManualListItem` を追随 |
| UI (feature) | `ManualListRow.svelte`: 「プレビュー」ボタンを追加 (`onRequestPreview` で上へ返す)。DL の出し分けを新 props へ |
| UI (feature) | `ManualPreviewModal.svelte` (新規): 既存 organism `Modal` + `<video controls>`。開いている間だけ `<video>` を描画 |
| UI (page) | `Projects/Show.svelte`: 削除ダイアログと同じ流儀で、対象行 state + モーダルをページが 1 つ持つ |
| テスト | Feature (props の値契約 / endpoint との整合) + Vitest (行の出し分け・モーダルの src) |

再生対象の選択式・条件判定は**サーバ側の既存実装 (`CurrentRenderArtifact` / `latestSucceededRender`) をそのまま使う**。
新しい判定式は 1 つも書かない。

## 制約・前提

- ドメイン規約 13 (T154): 受け取り口 (route) を増やさない / 完成動画の再生条件は download と同一 /
  秘匿境界は props 側に置く。本設計はいずれも維持する。
- ドメイン規約 3: 認証済み画面の 3 枚セットを壊さない (決定 5)。
- 禁止事項 8: 必須条件未充足でボタンを disabled にしない。**出さない**か**押せる**かの 2 択で、
  サーバが可と判断した行にだけ導線を出す (現行 `ManualListRow` の方針を継承)。
- component 階層: `features/manual` から `organisms/Modal` の import は単方向 (下層→上層) で合法。
  アイコンは `@lucide/svelte` のみ。色・角丸は DS token 経由 (DESIGN.md)。
- ability 評価はページで 1 回 (`ManualRowAbilities`)。本設計は評価回数もクエリ本数も増やさない
  (`latestSucceededRender` は既に eager load 済みで、`->id` を読むだけ)。

## 機械で固定する契約 (概念レビュー Round 1 の Warning 反映)

置換 (rename) は「片方だけ残る」形の事故が起きうるため、次の 5 点をテストで赤くする
(具体化は詳細設計のテスト計画):

1. Feature: 行 props に **旧キー `downloadable` が存在しない** (残置を赤くする)
2. Feature: `current_finished_render_job_id` は
   「published × 現行世代の succeeded render × `output_path` あり × download ability」で id、
   1 つでも欠ければ null (撮影者は null)
3. Feature: 非 null の行の id は **playback endpoint が 302 を返す id と一致**し、
   旧世代 job id の直叩きは 404 (既存 parity テストの拡張)
4. Vitest: `current_finished_render_job_id === null` のとき
   **プレビュー / DL の要素が DOM に存在しない** (禁止事項 8 の退行封じ)
5. Vitest: どちらの導線も `disabled` 属性を持たない / オーバーレイが閉じている間は
   `<video>` が DOM に無い (署名 URL 要求を出さない)

## スコープ外

- **言語切替 / 言語別 DL** (決定 2)。
- 一覧からの **preview 成果物 (kind=preview) の再生**。一覧は「完成した動画の棚卸し」の面であり、
  制作途中のプレビューは詳細画面 (RenderPanel) の担当。受け取り口を跨いだ 2 種類の再生を一覧に持ち込まない。
- 自前の再生 UI (再生/停止/音量/シーク/速度)、字幕トラック切替 (字幕は焼き込み済み)。
- オーバーレイ内での DL / 削除 / 編集など**再生以外の操作**。
- 一覧の行からのサムネイル表示・ホバー再生 (doc/04 のシナリオ編集「動画列」の要件であり別面)。
- 視聴履歴・再生回数の計測。
