【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 補足: 現行実装の要点 (レビューの前提)

- 一覧は専用 index を持たず `Projects/Show` に内包される。行 props は
  `App\DataTransferObjects\Manual\ManualListItemData`
  (`id / title / status / category / creator / created_at / updated_at / duration_ms / downloadable / deletable`)。
  `downloadable` は「download endpoint が 302 を返す条件と 1 対 1」と docblock で明記された値で、
  `ManualRowAbilities`(ページで 1 回だけ ability 評価) × published × `VideoManual::latestSucceededRender`
  (kind=render ∧ succeeded の最新 1 行を eager load) × `output_path !== null` から作られる。
- 完成動画の再生/DL の受け取り口:
  - `GET .../manuals/{manual}/download` → 302 (attachment 署名 URL)
  - `GET .../manuals/{manual}/render-jobs/{renderJob}/playback` → 302 (disposition 無し署名 URL)。
    kind=preview は render ability、kind=render は download ability + published + 現行世代一致。
  - 選択式は `App\Services\Manual\CurrentRenderArtifact::currentSucceeded($manual, $kind)` ただ 1 箇所
    (ドメイン規約 13 = T154。「受け取り口 route を増やさない」も同規約)。
- 詳細画面 `RenderPanel.svelte` は `finishedJob`(props) が非 null のときだけ
  `<video controls preload="none" src=".../render-jobs/{id}/playback">` と DL ボタンを出す。
- Modal は `components/organisms/Modal.svelte` (bits-ui Dialog。閉じている間は content を DOM に載せない)。
- 行 component は `components/features/manual/ManualListRow.svelte`。削除は行がイベントを上へ返し、
  ページ (`Projects/Show.svelte`) が `ConfirmDialog` を 1 つ持つ。

## 概念設計

（以下、devnotes/20260816-1754-manual-list-overlay-preview/conceptual-design.md の全文）

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

### 決定 4: 行 props は `downloadable: bool` を `finished_render_job_id: int|null` へ**置き換える**

playback route は render job id を要求するので、行に id が要る。
ここで `downloadable`(bool) を残したまま id を足すと、**同じ条件を 2 つの props で表す**ことになり、
どちらかが欠けた日に「DL は出るが再生は出ない」といった食い違いが**赤くならずに**発生する。
後方互換の並走を残さない (思考原則 3) / 判断を 2 箇所に持たない (T154 の流儀) に従い、
**1 本の props に統合する**:

- `finished_render_job_id: int|null` — 「いま受け取れる完成動画」の render job id。
  `null` = 受け取れない。**非 null であること自体が従来の `downloadable === true` と同値**
  (download ability × published × 現行世代の succeeded render に `output_path` がある)。
- UI は `finished_render_job_id !== null` **だけ**で DL 導線とプレビュー導線の両方を出す
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
| DTO | `ManualListItemData`: `downloadable: bool` → `finished_render_job_id: int\|null` (判定式は現行のまま。値を id にするだけ) |
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

## スコープ外

- **言語切替 / 言語別 DL** (決定 2)。
- 一覧からの **preview 成果物 (kind=preview) の再生**。一覧は「完成した動画の棚卸し」の面であり、
  制作途中のプレビューは詳細画面 (RenderPanel) の担当。受け取り口を跨いだ 2 種類の再生を一覧に持ち込まない。
- 自前の再生 UI (再生/停止/音量/シーク/速度)、字幕トラック切替 (字幕は焼き込み済み)。
- オーバーレイ内での DL / 削除 / 編集など**再生以外の操作**。
- 一覧の行からのサムネイル表示・ホバー再生 (doc/04 のシナリオ編集「動画列」の要件であり別面)。
- 視聴履歴・再生回数の計測。
