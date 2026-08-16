【アプリの使命 (North Star)】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

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
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 参考: 現行コードの要点 (レビューの前提。リポジトリは /workspace で読み取り可)

- 撮影 PWA の画面: `resources/js/pages/Capture/Show.svelte` (671 行)。個別テイク再生は
  `components/features/capture/TakePreviewDialog.svelte` (Modal + video + cut 固定字幕 overlay)。
- 撮影 PWA の route (prefix `/app`, name `capture.`): `routes/web.php` L610 付近。
  `capture.takes.playback` = `GET /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback`
  → `CaptureTakeController::playback` は `Gate::authorize('preview', $take)` (TakePolicy::preview → ProjectPolicy::capture = 撮影者可)、
  ready 以外は 404、302 + `Cache-Control: no-store, private`。
- PC 側 preview: `POST /projects/{project}/manuals/{manual}/preview` → `ManualRenderController::preview`
  (`Gate::authorize('render', $manual)` = 編集者専用) → `RenderJobService::triggerPreview()`
  (チケット非消費 / manual status を触らない / org 同時 preview 上限 `manual.render_max_inflight_previews_per_org`=3 を
   Organization 行ロックで直列化)。成果物再生は `GET .../render-jobs/{renderJob}/playback` で
  kind→ability は網羅 match (`preview`→`render` / `render`→`download`)。
- 「使用できる採用テイクがあるか」の判定式は `app/Services/Manual/AdoptedReadyTakeCoverage::isMissing(Cut)`
  **ただ 1 ファイル**が正本 (AGENTS.md ドメイン固有規約 12 / T148)。`adoptedTake` を参照する app/ 配下の
  ファイルは `app/Support/Security/AdoptedTakeReferenceInventory` へ区分 + 30 文字以上の根拠で登録が必須
  (`AdoptedReadyTakeCriterionInventoryTest` が deny-by-default + exact-fit で強制)。
- 撮影 PWA の props DTO: `App\DataTransferObjects\Capture\CaptureManualDetailData` /
  `CaptureCutData` (`toArray()` に array shape の docblock を持つ) / `CaptureTakeData`。
  TS 側は `resources/js/types/capture.ts` と対で保守し、キー集合は
  `tests/Feature/Capture/CaptureManualBrowsingTest` が完全一致で固定している。
- カットの表示順とラベル (手順N / 急所N-M) は `Services/Manual/CutSequencer::orderedWithLabels()` (サーバ) と
  `resources/js/lib/capture/cut-labels.ts` の `buildCutLabels()` (クライアント) の 2 実装が既に存在する。
- 採用は撮影者にも開いている (`TakePolicy::adopt`)。テイク登録時の自動採用は**無い** (明示的な採用操作が必要)。

---

## 概念設計

# 概念設計: capture-full-scenario-preview (撮影 PWA の全体連結プレビュー)

## 背景・課題

`doc/05 §5.2 シナリオ詳細画面` には **[プレビュー] = 「各カットの先頭（左端）テイクを連結した全体映像を確認」** が
要件として書かれているが、現在の撮影 PWA (`resources/js/pages/Capture/Show.svelte`) には
**個別テイクの再生 (`TakePreviewDialog`) しか無い**。

帰結として、現場の撮影者は次のことを自分で判断できない:

1. **撮り漏れの検出** — どのカットに「使用できる採用テイク」が無いのかを、通しで確認する手段が無い
   (カットを 1 つずつ開いてテイクの有無を目視するしかない)。
2. **通しの流れの確認** — 手順の順序・つながりがおかしくないかを、完成物に近い並びで確認できない。
3. **撮り終わったかの判断** — 「もう帰ってよいか」を撮影者が現場で決められず、PC 側の編集者の確認待ちになる。

これは使命 (「思考ゼロ・編集ゼロ」で専門知識ゼロの現場作業者が標準化されたマニュアル動画を作れる) の
**撮影ハードルの肩代わり**が撮影現場で閉じていないことを意味する。撮影者が現場を離れた後に欠落が判明すると
再訪コストが発生し、「撮影者・教える人のスキルに品質を依存させない」という差別化も弱まる。

PC 側には既にプレビュー生成の仕組みがある:

- `POST /projects/{project}/manuals/{manual}/preview` — チケット**非消費**、`RenderJobService::triggerPreview()`
- `GET .../render-jobs/{renderJob}/playback` — 最新 succeeded preview のみ 302 (署名 URL)
- 採用テイク欠落カットは `config('manual.preview_placeholder_seconds')` のプレースホルダ尺 (黒背景) になる
- `Gate::authorize('render', $manual)` = **編集者専用** (`VideoManualPolicy::render` → `ProjectPolicy::update`)
- org あたりの同時 in-flight preview は `config('manual.render_max_inflight_previews_per_org')` = **3 本**

## 実現方式の比較 (必須検討)

### 案 A: サーバ生成プレビュー (既存 render パイプライン) を撮影者からも起動できるようにする

撮影 PWA に「プレビュー生成」ボタンを置き、`POST .../preview` を叩き、`render-jobs/{id}` をポーリングし、
succeeded になったら `playback` の mp4 を `<video>` で再生する。認可は `render` ability を
撮影者 (`project_member`) にも開くか、`preview` 専用 ability を新設して分離する必要がある。

### 案 B: 端末側でカット順に連結再生する (テイクを順に切り替えて 1 本に見せる)

既存の `GET /app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}/playback`
(`CaptureTakeController::playback` = 302 → S3 署名 URL、`TakePolicy::preview` で**撮影者に開いている**) を
カット順に 1 つの `<video>` へ差し替えながら再生する。**新しい route も新しい ability も作らない**。

### 比較

| 観点 | 案 A: サーバ生成 | 案 B: 端末側連結再生 |
|---|---|---|
| **費用** | ffmpeg worker の実行時間 + プレビュー成果物の S3 容量が撮影者の押下回数だけ増える (チケットは非消費だが**インフラ費用はゼロではない**)。撮影は「撮る→確認」を何度も繰り返す面なので押下頻度は編集面より高い | **追加費用ゼロ**。生成物を作らないので worker も保存容量も増えない。既に存在するテイク実体を再生するだけ |
| **権限** | `render` ability を撮影者へ開く = `doc/10 §10.5` の権限表 (レンダ/プレビューの実行は編集者のみ) と、`docs/architecture.md` の T154 記述 (撮影者は完成レンダ成果物を観られない) を**両方書き換える**。`playback` の kind→ability 網羅 match (`preview`→`render` / `render`→`download`) も割り直しになり、preview 専用 ability を新設すると本番 policy で初めて意味を持つ権限差が生まれる (テスト・目録・doc の同時更新が必要) | **変更ゼロ**。`TakePolicy::preview` は既に撮影者に開いており、この機能で新たに見えるようになる情報は 1 バイトも無い (同じ画面が既に個別再生で同じテイクを再生している) |
| **待ち時間** | queue 待ち + ffmpeg 実行で**分単位**。しかも org 同時 preview 上限 3 本を編集者と食い合うため、現場が繰り返し押すと**編集者のプレビュー生成が枯渇** (409 OrgPreviewLimit) する。撮影者の確認が編集者の作業を止める構造になる | **即時**。先頭クリップのバッファリングだけで始まる。生成キューを一切使わないので `render_max_inflight_previews_per_org` の消費は**常に 0**。撮影者が何度通し再生しても編集者のプレビュー生成枠は減らない |
| **オフライン適性** | 生成にも取得にも接続が必須。現場が圏外に入ると**何もできない** | **どちらも接続は必要** (署名 URL は S3 へ取りに行く) が、直前に自動 DL (`AdoptedTakeAutoDownloader`) が同じ実体を取得しているぶんブラウザ HTTP キャッシュに乗る可能性がある。**保証はしない** (`doc/05 §5.3`: `downloaded_at` はオフライン再生を保証しない) |
| **忠実度** | 字幕焼き込み・解像度正規化・プレースホルダ尺込みで**完成物にいちばん近い** | 生映像の連結 + 字幕は overlay。クリップ境界に読み込みの間が入り、テイクごとに解像度・向きが違えば表示サイズが変わる |
| **通信量** | 完成 mp4 1 本 (エンコード済みなので概して小さい) | 各テイクの原寸を都度取得する (署名 URL は毎回変わるため HTTP キャッシュに当たらない場合がある)。**案 A より不利になりうる** |

### 決定: 案 B (端末側連結再生) を採る

理由は次の 3 点で、いずれも撮影者という**利用者の目的**に対して効く:

1. **撮影者の目的は「完成品質の確認」ではなく「撮り漏れと流れの確認」である**。案 B の忠実度の低さ
   (字幕 overlay・クリップ境界の間) はこの目的を損なわない。完成品質の確認は編集者の仕事であり、
   そのための手段 (サーバ生成プレビュー・完成動画) は PC 面に既にある。
2. **待ち時間が撮影の作業を止めない**。撮影は「撮る→確認→撮り直す」の短いループであり、確認に分単位が
   かかる方式は現場では使われない (使われなければ撮り漏れは減らない = 課題が解決しない)。
3. **費用と権限の面を一切増やさない**。案 A は課金面 (worker/容量) と認可面 (`render` ability の開放) の
   両方を広げる。今必要なのは「撮り終わったかを撮影者が判断できること」だけであり、その要件は
   既存の面の組み替えだけで満たせる (思考原則 2)。

**案 A を将来採らないとは言わない**。撮影者が完成物に近い映像を確認したい要求が実際に出てきたら、
そのときに `preview` 専用 ability の新設と org 上限の分離 (撮影者枠と編集者枠) を設計すればよい。
本設計はその道を塞がない (端末側連結再生は独立した面であり、後からサーバ生成を足しても衝突しない)。

## 「先頭 (左端) テイク」か「採用テイク」か

`doc/05` の文面は「各カットの**先頭 (左端)** テイクを連結」だが、**本設計は採用テイク (`adopted_take_id`) を連結する**。

- `doc/05 §5.2` 自身が「**一番左のテイクが全体プレビュー・採用候補**として扱われる」と書いており、
  元資料は「左端 = 採用候補 = プレビューに使われるもの」という**1 つの概念**を指していた。
  本実装はその概念を `adopted_take_id` という明示的な状態として持つ (T184/T185 で採用操作と並べ替えが分かれた)。
  よって**意味の食い違いではなく、同じ意味の明示化**である。
- 決定的な理由は**嘘をつかないこと**である。完成動画とサーバ生成プレビューは `adopted_take_id` を素材にする
  (`RenderPipeline::clipSpecFor`)。撮影者向けの通し再生だけが別の規則で素材を選ぶと、
  「通しで見て問題なかったのに完成動画は別物」という**確認したことが確認になっていない**状態を作る。
  撮り漏れの検出という目的そのものが壊れる。
- 帰結として「録画したが採用していないカット」は通し再生でも**欠落として見える**。これは仕様であり、
  そのカットのプレースホルダ表示に「使用できる採用テイクがありません」と述語の意味をそのまま出すことで、
  撮影者が次に何をすればよいか (テイクを採用する) が分かるようにする。
- この食い違いは実装後に `doc/05` へ注記する (資料の文面と実装の対応を残す)。

## 改善アイデア (何をどう変えるか)

撮影 PWA のシナリオ詳細画面に **「通し再生」** を 1 つ足す。押すとダイアログが開き、
カットの表示順 (手順 → 配下の急所) に沿って採用テイクを 1 本の `<video>` で順に再生する。

- **素材の選択はサーバが決める**。「使用できる採用テイクがあるか」の判定式は
  `Services/Manual/AdoptedReadyTakeCoverage::isMissing()` **ただ 1 ファイル**が正本である
  (AGENTS.md ドメイン固有規約 12 / T148)。撮影 PWA の props に
  **`adopted_ready_take_id` (採用済みかつ ready なテイクの id。無ければ null)** を 1 つ足し、
  端末側は**その値を読むだけ**にする (TypeScript 側で述語を書き直さない = 乖離の再発を作らない)。
- **欠落カットはプレースホルダ画面**を出す。尺はサーバ生成プレビューと同じ
  `config('manual.preview_placeholder_seconds')` を props で渡して使う (2 つのプレビューの構造を揃える)。
- **再生前に件数を告知する**。「N / M 件のカットに、撮影・処理が完了した採用テイクがありません」を
  ダイアログの冒頭に出す。ただし**ボタンは disabled にしない**し確認ダイアログも足さない (禁止事項 8)。
- **待ち時間の見せ方**: この方式は生成待ちが構造的に存在しない。残る待ちはクリップ境界の読み込みだけなので、
  切り替え中は「読み込み中」を出し、現在位置 (n / M) と カットラベルを常時表示する。
  撮影中 (`captureActive`) の押下は開かずにその場でエラーを出す (カメラ資源の競合を避ける既存 `TakeStrip` と同じ規則)。
  開くときは既存の `onRequestCameraRelease` / `onCameraResume` に相乗りして待機中の live stream を解放する。

## 権限 (この設計での結論)

- **新しい route を作らない / 新しい ability を作らない / 既存 ability の対象者を変えない**。
  使うのは既存の `capture.takes.playback` (`GET /app/projects/{p}/manuals/{m}/cuts/{c}/takes/{t}/playback`) だけで、
  これは `Gate::authorize('preview', $take)` → `TakePolicy::preview` → `ProjectPolicy::capture` = 撮影者に開いている。
  同じ画面が既に個別再生で同じ URL を叩いており、**新たに露出する情報は無い**。
- 変更系 route を 1 本も足さないので、AGENTS.md セキュリティ不変条件 9 (変更系は `Gate::authorize`) と
  10 (層 2 は層 3 より前) に対する新しい登録は発生しない。nested route の inventory
  (`NestedRouteIdorDefenseTest`) も**既存の登録のまま**である (route が増えないため)。
- **`render_max_inflight_previews_per_org` との関係**: 端末側連結再生は `render_jobs` を 1 行も作らないので、
  この上限を**消費しない**。撮影者が何度通し再生しても編集者のプレビュー生成枠 (3 本) は減らない。
  逆に言えば、撮影者は org 上限に阻まれて確認できない状況にも陥らない。
  **サーバ生成プレビューの起動は編集者専用のまま据え置く** (課金・権限の判断を勝手に変えない)。
- **チケット消費は現行どおり発生しない**。preview は非消費であり、本設計はそもそも preview ジョブを起動しない。

## 期待効果

- **使命への貢献**: 撮影ハードルの肩代わりが撮影現場で閉じる。撮影者が「撮り終わったか」を自分で判断でき、
  欠落に気づくのが現場を離れた後ではなくなる (再訪の削減 = 標準作業の教材化コストの低減)。
- 「採用テイクが無いカット」が通しの中で見えるので、**採用操作の抜け**という現行 UI の落とし穴が現場で解消される。
- 編集者のプレビュー枠・チケット・ffmpeg worker を 1 つも消費せずに上記を達成する。
- PC 側の面 (RenderPanel の事前告知/事後説明) と**同じ語彙**「撮影・処理が完了した採用テイクがありません」を使うため、
  撮影者と編集者が同じ言葉で会話できる。

## 実装方針 (概要)

| 層 | 変更 |
|---|---|
| DTO | `CaptureCutData` に `adopted_ready_take_id` を追加 (判定は `AdoptedReadyTakeCoverage::isMissing()` へ委譲)。`CaptureManualDetailData` は `with('adoptedTake')` を張って N+1 を防ぐ |
| 目録 | `AdoptedTakeReferenceInventory` の該当エントリを更新 (区分と根拠。deny-by-default の exact-fit) |
| Controller | `CaptureManualController::show` に `previewPlaceholderSeconds` (config 由来) を props として渡す |
| TS 型 | `types/capture.ts` の `CaptureCut` に `adopted_ready_take_id` を追加 |
| lib | `lib/capture/scenario-preview.ts` — props から再生リスト (クリップ / プレースホルダ) を組み立て、次へ進む判断を持つ純関数群 (Vitest 対象) |
| component | `components/features/capture/ScenarioPreviewDialog.svelte` — `Modal` + `<video>` + 字幕 overlay + 位置表示 (既存 `TakePreviewDialog` の構造を踏襲) |
| page | `pages/Capture/Show.svelte` — 起動ボタンの配置とカメラ資源の解放/復帰の配線 |
| テスト | Feature (props の shape 契約と各 `TakeStatus` での `adopted_ready_take_id`)、Vitest (再生リスト構築・進行・境界)、component テスト |
| docs | `doc/05` に「先頭テイク = 採用テイク」の注記、`docs/architecture.md` §撮影 PWA に方式と保証しないものを追記 |

## 制約・前提

- Svelte 5 runes + DS token のみ。component 階層は `atoms → molecules → organisms → features/capture → templates → pages` の単方向 import。
  アイコンは `@lucide/svelte` のみ (`Play` / `SkipForward` 等の既存語彙)。
- 判定式の単一化 (ドメイン規約 12) を守る。`adopted_take_id` と `TakeStatus::Ready` の同居を
  PHP でも TypeScript でも**新たに書かない**。
- `adopted_take_id` の**書き込み**経路は増やさない (本設計は読み取りのみ)。共有ロック規約 (ドメイン規約 1) に触れない。
- `response()->json()` の直書きをしない。props は Inertia、既存 Resource は変更しない。
- PHPStan level 10。`CaptureCutData::toArray()` の array shape docblock を新キー込みで更新する。
- 撮影 PWA の 3 枚セット (no-store / bfcache 秘匿 / Inertia 履歴暗号化。ドメイン規約 3) に触れない
  (新しい route も新しい logout 導線も作らないため)。

## 保証しないもの (誇張しない)

- **オフライン再生を保証しない**。署名 URL の取得には接続が要る。ブラウザ HTTP キャッシュに当たれば
  再取得なしで再生できることがあるが、それは偶然であって契約ではない (`doc/05 §5.3` と同じ立場)。
- **完成動画と同じ見え方を保証しない**。字幕は overlay であって焼き込みではなく、解像度・向きの正規化も
  トランジションも無い。クリップ境界には読み込みの間が入る。
- **プレースホルダの尺がサーバ生成プレビューと厳密に一致することを保証しない** (config 値は共有するが、
  端末側はタイマー、サーバ側は ffmpeg の実尺である)。
- 通し再生の結果が「完成動画を生成できる」ことの保証にはならない (完成動画は全カット充足を 422 で要求する)。
  告知文はこの非対称をそのまま言う。

## スコープ外

- サーバ生成プレビューを撮影者に開くこと (上記のとおり据え置く)。`render` / `download` ability の変更もしない。
- 端末内に動画を保存する仕組み (IndexedDB への実体保存・真のオフライン再生)。
- ナレーション音声 (TTS) の再生。v1 は字幕のみ (AGENTS.md v1 スコープ)。
- 通し再生からの採用操作・並べ替え・コメント編集 (既存 `TakeStrip` / `TakePreviewDialog` の責務)。
- 横持ち全画面 (撮影モード) 中からの起動。全画面は撮影に専念する面であり、通し再生の導線は縦持ち/通常表示に置く。
- PC 側 (`Manuals/Show`) の RenderPanel の変更。

