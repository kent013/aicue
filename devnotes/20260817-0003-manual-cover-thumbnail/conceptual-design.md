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
  同値時のタイブレークは**テストで固定する** (実装依存の順序に寄りかからない)。
- 条件は「そのカットに採用テイクがあり、その採用テイクの `thumbnail_path` が非 null」。
- 「最初のカット固定」にはしない。最初のカットが未撮影のまま 2 番目以降を撮る運用は普通にあり、
  固定にすると**撮影が進んでいるのに代表が出ない**行が大量に出る。
  「先頭から探して最初に見つかったもの」なら、説明も 1 行で済み、撮影が進むほど安定する。
- 撮り直し・採用差し替えで代表が変わるのは**仕様**である (代表は「いま採用されている素材」を映す)。

#### D1-1. 責務の分離 (規約 12 を壊さないための必須条件)

代表の決定は**意図的に 3 層に分ける**。1 か所に寄せると、
`adoptedTake` と `TakeStatus::Ready` が同居するファイルが増えて T148 の検出 B に触れる。

| 層 | 置き場所 | 持つもの | 持たないもの |
|---|---|---|---|
| (a) 候補選択 | `VideoManual` の relation | 表示順で 1 件に絞る規則 + 「採用テイクの `thumbnail_path` が非 null」 | **状態 (ready) の判定を書かない** (`TakeStatus::Ready` をこのファイルに書かない) |
| (b) 状態判定 | `AdoptedReadyTakeCoverage::readyTakeId()` へ**委譲** | 「採用済みかつ ready のテイク id」 | 新しい述語を作らない (既存の唯一の式をそのまま呼ぶ) / **DB へ問い合わせない** |
| (c) 合成 | `CaptureManualSummaryData` | (a) が選んだカット + (b) が返した take id から cover を組む | 自前の ready 判定・自前の順序規則 |

- **(b) は行ごとの追加クエリを 1 本も出さない**。`readyTakeId()` の実体は
  `$cut->adoptedTake` のプロパティ読み出しであり、relation が eager load 済みなら
  DB へ行かない。これは canonical 自身の docblock が明文化している前提でもある
  (「一覧の直列化では eager load 必須 (`with('adoptedTake')`)。無いと N+1 になる」)。
  したがって **(a) の relation と (c) が使う採用テイクを同じ 1 回の eager load で載せることが、
  (b) を呼ぶ側の義務**である。複数 cut を一括判定する新 API は作らない
  (状態述語の入口が 2 つになり、規約 12 が閉じた「1 ファイル 1 述語」を弱めるため)。
- (a) と (b) の条件が食い違った場合 (= (a) が選んだカットで (b) が null を返した場合) は
  **cover を出さない** (次のカットへ探しに行かない)。安全側 = 壊れた画像を出さない側に倒す。
- この食い違いは**現行コードでは到達不能**である: `thumbnail_path` が非 null になるのは
  `TakeThumbnailPipeline` の条件付き UPDATE (`where status=ready`) だけで、
  take の status を `ready` 以外へ遷移させる経路が app/ に存在しないため。
  到達不能であることに寄りかからず、Feature テストで「食い違ったら cover が出ない」ことを固定する
  (将来 status 遷移が増えたときに壊れた画像ではなくプレースホルダへ落ちる)。

### D2. フォールバック

代表が決まらない (採用テイクが 1 つも無い / サムネイル未生成 / 生成失敗 / 過去分) 場合は
**同じ寸法のプレースホルダタイル**を描く。空欄にしない。
`TakeThumbnail.svelte` が既に採っている作法 (「生成完了後の再取得で同じ枠が画像へ置き換わる =
レイアウトが跳ねない」) をカード側でも踏襲する。撮影進捗バッジ (未撮影 / 撮影中 / 撮影完了) が
既にあるので、プレースホルダに文言を足して二重に説明しない (アイコンのみ)。

**画像の読み込みに失敗したときも同じプレースホルダへ戻す**。props が非 null でも、
署名 URL の期限切れ (PWA を開いたまま放置)・S3 側の失敗・通信断は起こりうる。
壊れた画像アイコンを現場に出さないため、component 側で読み込み失敗を捕まえて
プレースホルダへ落とす (再取得の再試行は入れない — 画面の再訪で新しい署名 URL を取り直せる)。

### D3. 配信は既存 endpoint をそのまま使う (route を増やさない)

`GET /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail`
(`capture.takes.thumbnail`) をそのまま使う。**新しい route は 1 本も足さない** (思考原則 2)。

- 代表サムネイルは「特定のテイクのサムネイル」以上のものではない。専用 endpoint を作ると
  同じ資源に 2 本目の API 面が生える (T184 が明示的に避けた形)。
- props には URL ではなく **`cut_id` / `take_id`** を載せ、URL は既存の
  `take-endpoints.ts#takeUrl()` で組む (規則の置き場所を増やさない)。
  **props に署名 URL も endpoint URL 文字列も載せない**ことは契約としてテストで固定する
  (props 面積を増やさない / 署名 URL を HTML に焼き付けない)。

### D4. props と endpoint の整合契約 (秘匿境界を props 側に置く)

`docs/architecture.md` は「props の `has_thumbnail` はこの 302 条件と 1 対 1 である」を
既存契約として持つ。代表サムネイルも同じ発想を採り、UI は `cover !== null` **だけ**で判断する
(`canManage` 等の第 2 の条件を UI 側に積まない = 判断を 2 箇所に持たない。T154 の作法)。

ただし**双条件としては書かない**。`capture.takes.thumbnail` は「その take が代表か」を
判定する endpoint ではなく、代表でない take でも条件を満たせば 302 を返す。
また `cover === null` のときは叩く URL 自体が props に無い。よって契約は**独立した 3 本**にする:

| # | 契約 | 固定の仕方 |
|---|---|---|
| (i) | **配信可能性**: `cover !== null` なら、その id から組んだ URL は同一利用者に対して 302 を返す | Feature テスト (props を取り → その URL を叩く) |
| (ii) | **代表選択の完全性**: 「capture 権限あり ∧ D1 の候補あり ∧ canonical の ready 判定成立」がすべて成り立つときちょうど `cover !== null`。いずれか不成立なら `cover === null` | Feature テスト (3 条件を 1 つずつ落とす) |
| (iii) | **認可委譲の drift 検出**: `TakePolicy::preview` の判定が `ProjectPolicy::capture` と同値である | 代表の有無とは独立したテスト |

そのために props 側で 2 つを閉じる:

1. **状態条件**: 「採用済みかつ ready」の判定は `AdoptedReadyTakeCoverage::readyTakeId()` へ
   **委譲**する (自前で書かない = 規約 12)。加えて `thumbnail_path` 非 null を見る。
2. **権限条件**: `Gate::allows('capture', $project)` が false の利用者には
   全行の代表を `null` にする (= プレースホルダ)。**判定は 1 リクエストにつき 1 回**で、
   行数に比例しない。これで 3.の「見えるが撮れない人」に 403 の壁紙を見せずに済む。

**この同値は「`ProjectPolicy::capture` が endpoint 側の唯一の判定源である」ことに依存する**
(`TakePolicy` は全 ability を `capture` へ委譲するだけのクラスである)。
行ごとに `Gate::allows('preview', $take)` を評価する案は採らない — `TakePolicy::preview` が
`$take->cut?->videoManual?->project` を辿るため**行数ぶんの lazy load** を生み、
本設計の主目的 (N+1 回避) と正面衝突するからである。
代わりに**依存を機械で固定する**: 上表 (i)(iii) の 2 本がこの依存のトリップワイヤになる。
`preview` 側に `capture` 以外の条件が増えたら、(iii) が直接赤くなり、
(i) も「props は cover を出すのに endpoint が 403」で赤くなる。

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
  **保証範囲を誇張しない**: lazy loading は初期表示時の取得を抑制する**ヒント**であり、
  取得枚数の上限は保証しない (viewport 近傍の先読み量はブラウザ実装依存)。
  一覧が現状ページネーションを持たないことと合わせ、行数が非常に多い組織では
  取得枚数が大きくなりうる。これは受容する (ページネーションは別タスク)。
- 1 枚の表示につき **アプリへの GET 1 回 (302) + S3 への GET 1 回**。
  302 は `no-store, private` なので、画面を再訪するたびに署名 URL を取り直す
  (= 期限切れ URL を握らない代わりに、回数は「表示した枚数」ぶん発生する)。
  署名 URL の発行はローカル計算 (S3 への往復なし) なので、サーバ側費用は無視できる。
- 描画サイズは小さく固定する (カード左のタイル)。**ホバー自動再生 (T190) は PWA 一覧には
  持ち込まない** — 動画本体の転送が発生し、現場の通信環境では割に合わない。

## 期待効果

- **主効果 — 識別性の向上**: 「思考ゼロ」で撮る導線の入口で、現場作業者が**読まずに**目的の
  マニュアルを選べるようになる。文字だけのカードは、手袋・屋外・小さい画面という
  撮影現場の条件で読みにくい。
- `doc/05 §5.2` の要件を満たす (6 要素中 5 → 6)。
- **副次効果 — 進捗の補助**: 撮影が進むと代表が付く。ただし**進捗表現としては穴がある**
  (過去分・生成失敗・生成待ちはプレースホルダのまま) ため、進捗の正本は既存の
  撮影進捗バッジのままとし、代表サムネイルにその役割を負わせない。

## 実装方針 (概要)

| 層 | 変更 |
|---|---|
| Model | `VideoManual` に代表カットの `HasOne` relation (`ofMany` で 1 件確定) を足す。`latestSucceededRender` (T182) と同じ作法 |
| Controller | `CaptureManualController::index` の eager load に代表カット + その採用テイクを足す。`Gate::allows('capture', $project)` を 1 回だけ評価して DTO へ渡す |
| DTO | `CaptureManualCoverData` (readonly / `cutId` / `takeId`) を新設し、`CaptureManualSummaryData::$cover` を `?CaptureManualCoverData` にする。ready 判定は `AdoptedReadyTakeCoverage` へ委譲 |
| TS 型 | `types/capture.ts` の `CaptureManualSummary` に `cover` を追加 |
| UI | `features/capture/` に代表サムネイルのタイル component を 1 つ追加し、`pages/Capture/Index.svelte` のカードへ差し込む |
| 目録 | `AdoptedTakeReferenceInventory` に新規参照ファイルを登録 (deny-by-default) |
| テスト | Feature (props 契約 / 選択規則 / 権限 / 境界 404 / parity / クエリ数の行数非依存) + Vitest |

`cover` を小 DTO にするのは、**2 つの別の行 (cut と take) から合成する 2 つの id** を
「両方 null か両方非 null」という型の形で表せるからである。既存 `CutTakeSummaryData` は
同種の合成を配列 shape のまま持ったため `toArray()` に防御的な三重 null 判定が残っている
(同じ形を増やさない)。

### テストの軸 (境界系は 3 つに分ける)

1. **index 自体の境界**: 別 org / 別 project の `{project}` で撮影一覧が 404 になる (既存の回帰)。
2. **cover の id を使った endpoint の境界**: props から得た `cut_id` / `take_id` を
   別 org・別 project の URL に嵌めて叩くと**認可より前に 404** になる。
3. **props の内容**: 他 org / 他 manual の take id が cover に混入しない
   (代表は必ずその manual 配下のカット・その採用テイクである)。

加えて **shape の契約**: 既存の「summary shape は TS `CaptureManualSummary` と対のキー集合」
テストに `cover` を足し、非 null 時の内側キー (`cut_id` / `take_id`) が int であることも固定する。
**クエリ数テストは「候補 0 件・1 件・複数件」で追加クエリ数が一定であることを見る**
(行数だけでなく候補の有無でもクエリが増えないこと)。

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
