## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

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


あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上記に挿入済み）

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

## 概念設計

# 概念設計: manual-cover-thumbnail (マニュアル代表サムネイルの表示)

## 背景・課題

`doc/05 §5.2 シナリオ選択画面` は撮影 PWA の一覧要件を
「シナリオをカード形式で一覧表示 (サムネイル / タイトル / カテゴリ / 作成者 / 更新日 / **撮影進捗**)」
と定めている。

現行 `resources/js/pages/Capture/Index.svelte` は 6 要素のうち 5 つ
(タイトル / カテゴリ / 作成者 / 更新日 / 撮影進捗バッジ) を出しているが、
**サムネイルだけが無い**。カードは文字だけで、現場作業者が「どのマニュアルか」を
一目で判別する手がかりが無い。

### 現行コードで検証した前提 (ブリーフの前提の検証結果)

ブリーフの前提を鵜呑みにせず、現行コードを読んで 1 件ずつ確認した。

| ブリーフの主張 | 検証結果 | 根拠 |
|---|---|---|
| Capture/Index にサムネイルが無い | **正しい** | `resources/js/pages/Capture/Index.svelte` L106-134 にカード。画像要素なし |
| 一覧は他 5 要素を出している | **正しい** | 同 L111-129 (title / category_name / creator_name / updated_at / 進捗バッジ) |
| T183 でテイク単位のサムネイル生成と配信 endpoint が入っている | **正しい** | `takes.thumbnail_path` / `capture.takes.thumbnail` (`CaptureTakeController::thumbnail`) |
| 「代表する 1 枚」の決め方が残っている | **正しい** | 代表を選ぶコードは app/ に 1 つも無い |

**ブリーフに書かれていなかったが設計に効く事実** (現行コードを読んで判明したもの):

1. **PC 側 (シナリオ編集画面の動画列) には既にサムネイルがある**。
   `CutTakeSummaryData::$adoptedHasThumbnail` → `ScenarioEditor.svelte` L1085-1090 が
   `TakeThumbnail.svelte` へ `capture.takes.thumbnail` の URL を渡している。
   よって「サムネイル表示そのものが未実装」ではなく、**マニュアル単位の代表を決める層だけ**が無い。
2. **URL 導出の規則は既に 1 箇所にある** — `resources/js/lib/capture/take-endpoints.ts` の
   `takeUrl(target, takeId, "/thumbnail")`。props に URL 文字列を入れる必要はない。
3. **撮影 PWA の一覧は「撮影できない人」も見られる**。`CaptureManualController::index` の認可は
   `Gate::authorize('view', $project)` = 組織メンバーなら可。一方
   `capture.takes.thumbnail` は `Gate::authorize('preview', $take)` →
   `ProjectPolicy::capture` = 管理権限者または project メンバーのみ。
   **project メンバーでない組織メンバーは一覧を見られるがサムネイルは 403** になる
   (既存テスト `CaptureManualBrowsingTest`「撮影者 (project_member) も org member (非 project member) も閲覧はできる」)。
   → 素朴に img を貼ると、その利用者には行数ぶんの 403 と壊れた画像が並ぶ。
4. **`takes.thumbnail_path` が非 null になるのは `status=ready` の行だけ**である
   (`TakeThumbnailPipeline` の条件付き UPDATE `where status=ready and thumbnail_path is null`)。
   かつ take の status を `ready` 以外へ遷移させる経路は app/ に無い
   (`TakeRegistrationService` が INSERT 時に `ready` を明示代入するのが唯一の代入)。
5. **「採用済みかつ ready」の判定式はドメイン固有規約 12 (T148) で 1 ファイルに固定**されている
   (`Services/Manual/AdoptedReadyTakeCoverage`)。`adoptedTake` を参照する app/ 配下ファイルは
   `AdoptedTakeReferenceInventory` への登録が必須で、**`adoptedTake` と `TakeStatus::Ready` が
   同居するファイルは Canonical 1 件しか許されない** (検出 B)。
   → 代表サムネイルの選択を書く場所は、この gate を壊さない形に**設計段階で**寄せる必要がある。

## 改善アイデア

撮影 PWA のシナリオ選択画面のカードに、**そのマニュアルを代表する 1 枚**を出す。

### D1. 代表サムネイルの決め方 (決定的で説明できる規則)

> **表示順で最初に来る「採用テイクのサムネイルが出来ているカット」の、その採用テイクのサムネイル**

- 順序は `cuts.sort_order` 昇順、同値は `cuts.id` 昇順 (シナリオ編集・撮影ナビの表示順と同じ規則)。
- 条件は「そのカットに採用テイクがあり、その採用テイクの `thumbnail_path` が非 null」。
- 「最初のカット固定」にはしない。最初のカットが未撮影のまま 2 番目以降を撮る運用は普通にあり、
  固定にすると**撮影が進んでいるのに代表が出ない**行が大量に出る。
  「先頭から探して最初に見つかったもの」なら、説明も 1 行で済み、撮影が進むほど安定する。
- 撮り直し・採用差し替えで代表が変わるのは**仕様**である (代表は「いま採用されている素材」を映す)。

### D2. フォールバック

代表が決まらない (採用テイクが 1 つも無い / サムネイル未生成 / 生成失敗 / 過去分) 場合は
**同じ寸法のプレースホルダタイル**を描く。空欄にしない。
`TakeThumbnail.svelte` が既に採っている作法 (「生成完了後の再取得で同じ枠が画像へ置き換わる =
レイアウトが跳ねない」) をカード側でも踏襲する。撮影進捗バッジ (未撮影 / 撮影中 / 撮影完了) が
既にあるので、プレースホルダに文言を足して二重に説明しない (アイコンのみ)。

### D3. 配信は既存 endpoint をそのまま使う (route を増やさない)

`GET /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail`
(`capture.takes.thumbnail`) をそのまま使う。**新しい route は 1 本も足さない** (思考原則 2)。

- 代表サムネイルは「特定のテイクのサムネイル」以上のものではない。専用 endpoint を作ると
  同じ資源に 2 本目の API 面が生える (T184 が明示的に避けた形)。
- props には URL ではなく **`cut_id` / `take_id`** を載せ、URL は既存の
  `take-endpoints.ts#takeUrl()` で組む (規則の置き場所を増やさない)。

### D4. props と endpoint の 1 対 1 (秘匿境界を props 側に置く)

`docs/architecture.md` は「props の `has_thumbnail` はこの 302 条件と 1 対 1 である」を
既存契約として持つ。代表サムネイルも同じ形にする。すなわち **props に代表が入っている
⇔ その URL を叩けば 302 が返る**とし、UI は `cover !== null` **だけ**で判断する。

そのために props 側で 2 つを閉じる:

1. **状態条件**: 「採用済みかつ ready」の判定は `AdoptedReadyTakeCoverage::readyTakeId()` へ
   **委譲**する (自前で書かない = 規約 12)。加えて `thumbnail_path` 非 null を見る。
2. **権限条件**: `Gate::allows('capture', $project)` が false の利用者には
   全行の代表を `null` にする (= プレースホルダ)。**判定は 1 リクエストにつき 1 回**で、
   行数に比例しない。これで 3.の「見えるが撮れない人」に 403 の壁紙を見せずに済む。

### D5. 出す面は撮影 PWA の一覧だけ (PC 一覧には出さない)

- 撮影 PWA (`Capture/Index`) は `doc/05 §5.2` が明示的にサムネイルを要求している → **出す**。
- PC 一覧 (`doc/04 動画一覧ページ`) の列は「No / 状態 / タイトル / カテゴリ / 再生時間 /
  更新日 / DL / 削除」で、**サムネイル列は要件に無い**。PC 側は既に
  ①行内プレビュー (T189 のオーバーレイ) で中身を確認でき、
  ②シナリオ編集画面の動画列でカットごとのサムネイルを見られる。
  代表 1 枚を足しても新しい判断材料にならない一方、転送量と props 面積は増える。
  → **出さない**。要件が無いものを作らない (思考原則 2)。

### D6. 転送量と署名 URL の取得回数 (現場の通信環境)

- 生成物は `capture.thumbnail_max_edge=640` / `thumbnail_jpeg_quality=5` の JPEG。
  実測は取っていないが、この設定なら 1 枚あたり数十 KB のオーダーである。
- **`loading="lazy"` を付ける** (既存 `TakeThumbnail.svelte` と同じ)。
  一覧は現状ページネーションを持たないため、これが実質的な上限装置になる
  (画面外の行は取りに行かない)。
- 1 枚の表示につき **アプリへの GET 1 回 (302) + S3 への GET 1 回**。
  302 は `no-store, private` なので、画面を再訪するたびに署名 URL を取り直す
  (= 期限切れ URL を握らない代わりに、回数は「表示した枚数」ぶん発生する)。
  署名 URL の発行はローカル計算 (S3 への往復なし) なので、サーバ側費用は無視できる。
- 描画サイズは小さく固定する (カード左のタイル)。**ホバー自動再生 (T190) は PWA 一覧には
  持ち込まない** — 動画本体の転送が発生し、現場の通信環境では割に合わない。

## 期待効果

- **使命への貢献**: 「思考ゼロ」で撮る導線の入口で、現場作業者が**読まずに**目的のマニュアルを
  選べるようになる。文字だけのカードは、手袋・屋外・小さい画面という撮影現場の条件で読みにくい。
- `doc/05 §5.2` の要件を満たす (6 要素中 5 → 6)。
- 撮影が進むと代表が付く = 一覧上で進捗が視覚的にも分かる (バッジの補強)。

## 実装方針 (概要)

| 層 | 変更 |
|---|---|
| Model | `VideoManual` に代表カットの `HasOne` relation (`ofMany` で 1 件確定) を足す。`latestSucceededRender` (T182) と同じ作法 |
| Controller | `CaptureManualController::index` の eager load に代表カット + その採用テイクを足す。`Gate::allows('capture', $project)` を 1 回だけ評価して DTO へ渡す |
| DTO | `CaptureManualSummaryData` に `cover` (`{cut_id, take_id}` or null) を追加。ready 判定は `AdoptedReadyTakeCoverage` へ委譲 |
| TS 型 | `types/capture.ts` の `CaptureManualSummary` に `cover` を追加 |
| UI | `features/capture/` に代表サムネイルのタイル component を 1 つ追加し、`pages/Capture/Index.svelte` のカードへ差し込む |
| 目録 | `AdoptedTakeReferenceInventory` に新規参照ファイルを登録 (deny-by-default) |
| テスト | Feature (props 契約 / 選択規則 / 権限 / cross-org 404 / クエリ数の行数非依存) + Vitest |

## 制約・前提

- **ドメイン固有規約 12 (T148)**: `adoptedTake` を参照する app/ ファイルは目録登録必須。
  `adoptedTake` と `TakeStatus::Ready` を**同じファイルに書かない** (検出 B)。
  → 状態判定は `AdoptedReadyTakeCoverage::readyTakeId()` への委譲で満たす。
- **T154 の `RenderArtifactSelectionInventory` は対象外**。あの目録の母集団は
  「`render_jobs` に対する succeeded 条件つきの直接クエリ」であり、本設計は `render_jobs` に
  一切触れない。**登録は不要**である (ブリーフの確認事項への回答)。
- **T182 の eager load 作法は踏襲する**: 一覧が行ごとに解決するとクエリが行数に比例するため、
  `ofMany` の relation として eager load 可能な形で持ち、クエリ数の行数非依存を
  Feature テストで固定する (`ManualListQueryCountTest` の撮影 PWA 版)。
- **認証済み画面の 3 枚セット (ドメイン固有規約 3) を壊さない**: 追加するのは Inertia props の
  1 キーと `<img>` 1 つだけで、no-store baseline / bfcache guard / history 暗号化のいずれにも
  触れない。302 の `no-store, private` も現状のまま (弱めない)。
- **`response()->json()` 直書き禁止**: 変更は Inertia props (DTO 経由) のみ。新規 endpoint なし。
- **PHPStan level 10**: relation の generics 注釈、null 安全 (`?->`)、DTO の配列 shape 注釈を明示。
- **DESIGN.md**: 色・角丸・余白は DS token のみ。アイコンは `@lucide/svelte`。
- **Atomic Design**: 新 component は `features/capture/` (pages から import。features 間の
  横参照はしない)。

## スコープ外

- PC 一覧へのサムネイル列追加 (D5 の理由により作らない)。
- 代表サムネイルの**手動選択 UI** (「この 1 枚を表紙にする」)。要件に無い。決め方が決定的なら
  まず自動で足りる。必要になったら別タスクで判断する。
- 専用の表紙画像生成 (別解像度・別トリミング)。既存のテイクサムネイルを流用する。
- 過去分テイクのサムネイル一括バックフィル (T183 が「行わない」と決めた方針を変えない)。
  古いマニュアルは代表なし = プレースホルダになる。
- 一覧のページネーション。現状の仕様を変えない (`loading="lazy"` で転送を抑える)。
- ホバー/タップでの自動再生 (T190) の PWA 一覧への移植。
