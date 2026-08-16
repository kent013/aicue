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

【補足: このリポジトリの前提 (レビュー時に踏まえること)】
- リポジトリは /workspace。必要ならファイルを読んでよい (AGENTS.md / doc/04 / doc/10 /
  routes/web.php / app/Services/Capture/ / resources/js/components/features/ 等)。
- 追加のセキュリティ不変条件 (AGENTS.md §セキュリティ不変条件) のうち本件に関わるもの:
  「子は親に属する = nested route の不整合は認可より前に 404 (NestedRouteIdorDefenseTest の
  inventory 登録必須)」「変更系 route は Gate::authorize を通す」「tenant/actor キーを
  payload から受け取らない」。
- ドメイン固有規約 1 (シナリオ整合の共有ロック規約): cuts / scenario_version / status を書く
  経路は対象 VideoManual を lockForUpdate した同一 tx 内でのみ反映し、
  経路は ScenarioWritePathInventoryTest の inventory に登録する。
- ドメイン固有規約 4 (課金ゲート): 新しい業務 route は require-active-subscription group の中。
- フロントは Svelte 5 runes + DS token のみ。component 階層は
  atoms → molecules → organisms → features/{domain} → templates → pages の単方向 import で、
  features の domain 間横参照は禁止 (機械検査あり)。アイコンは @lucide/svelte のみ。
- 必須条件未充足を理由にボタンを disabled にする UI は禁止 (押下時にエラー表示する)。

---

## 概念設計

# 概念設計: pc-take-selection-and-adoption (PC 側のテイク選択・採用画面)

## 背景・課題

doc/04 §PC サイト機能仕様が定める **「テイクのプレビュー / 選択画面」** と
**「動画シナリオ画面の動画列」** は、PC 編集者にとっての中核機能でありながら**まったく存在しない**。

現状 (2026-08-16 時点の実装を実読して確認):

| 事実 | 根拠 |
|---|---|
| テイクの採用 / 並べ替え / コメント / 削除 / プレビュー再生の UI は撮影 PWA にしか無い | `resources/js/pages/Capture/Show.svelte` → `components/features/capture/TakeStrip.svelte` |
| シナリオ編集画面にはテイクを見る手段も採用する手段も無い | `components/features/manual/ScenarioEditor.svelte` (1049 行) にテイクの語が 1 つも無い |
| PC ブラウザからローカル動画を登録する経路が事実上無い | `CaptureFileFallback.svelte` は `supportsMediaRecorder()` が false のときだけ描画され、PC Chrome/Firefox/Safari では出ない (`Capture/Show.svelte` L51-53) |
| API 側 (採用・削除・presigned アップロード・プレビュー 302) は既に完成している | `routes/web.php` L594-622 の `capture.takes.*` 7 本 |
| 認可も既に編集者を通す | `TakePolicy` → `ProjectPolicy::capture()` は org owner/admin と project_admin を先に true にする |

つまり**足りないのは PC 側の画面と導線だけ**であり、サーバ側の業務ロジックはほぼ揃っている。
にもかかわらず編集者は「AI が設計したシナリオ」を見ながら「撮れた素材」を選ぶという
編集作業を PC 上でまったく行えず、採用作業を現場のスマホ側に押し付けている。

これは使命 (「編集ゼロ」= 台本作成・撮影判断・編集の 3 ハードルを肩代わりする) の
**編集ハードルの部分が PC で未着地**であることを意味する。素材の採否は現場作業者ではなく
標準を定める側 (編集者) の判断であり、そこを PC で完結できないことが最大の欠落である。

## 改善アイデア

**シナリオ編集画面に「動画」列を足し、そこから独立した「テイク選択・採用画面」へ遷移する。**

1. **動画列** (`ScenarioEditor` 内): 各手順 / 急所の行に、そのカットの登録済みテイク状況
   (件数・採用有無・採用テイクの状態) を表示し、「テイクを選択」で選択画面へ遷移する。
2. **テイク選択・採用画面** (独立した Inertia ページ): 左にテイク一覧、中央に大きな
   プレビュー再生、右 (または下) に採用・削除・アップロードの操作。
   - 「このテイクを採用する」で `cuts.adopted_take_id` を確定する
   - 採用テイクは視覚的に区別する (青枠 = DS token の primary 系 ring)
   - プレビュー上の**字幕表示 ON/OFF**、**ナレーション原稿表示 ON/OFF** (初期は両方オフ)
   - **PC ローカル動画の追加アップロード** (既存 presigned PUT フローの再利用)
   - 各テイクの削除 (確認ダイアログ。復元不可を明記)
3. **サーバ側の新設は GET 1 本だけ**。採用・削除・アップロード・プレビュー再生は
   既存 `capture.takes.*` を編集者からもそのまま使う (新しい書き込み経路を作らない)。

### 設計上の確定判断

#### D1. 画面の形態 = 独立した Inertia ページ (モーダルにしない)

- doc/04 の記述が「テイク選択画面へ**遷移**する」であり、中央に大きなプレビューを置く
  レイアウト要件 (一覧 + 中央プレビュー + 操作) はモーダルでは窮屈になる。
- `ScenarioEditor` は**クライアント側の作業コピー (`DraftStep[]`) を保持し、
  「シナリオを更新」で document 全体を 1 回の PUT で送る**設計である。
  モーダル内から `router.reload({only:[...]})` を撃つと、`ScenarioEditor` が登録している
  `router.on("before")` の dirty 離脱確認 (L652-660) が**巻き添えで発火する**。
  この guard の抑止フラグ (`reloading`) はコンポーネント内部の private state であり、
  外から握れない。独立ページなら再取得は自ページ内で完結し、この干渉が構造的に消える。
- 置き場所は規約どおり `resources/js/pages/Manuals/Takes.svelte` +
  `resources/js/components/features/manual/*` (features の domain 間横参照はしない)。

#### D2. 使う endpoint = 既存 `capture.takes.*` を再利用し、新設は GET 1 本のみ

- 新設: `GET /projects/{project}/manuals/{manual}/cuts/{cut}/takes`
  (`projects.manuals.cuts.takes.index`) — 画面の Inertia props を返すだけ。
- 再利用: `capture.takes.upload-url` / `capture.takes.store` / `capture.takes.adopt` /
  `capture.takes.destroy` / `capture.takes.playback` (XHR / video src としてそのまま叩く)。
- **再利用の根拠**:
  - 両 group の middleware は**完全に同一** (`['require-active-subscription', 'project.in-current-org']`)。
    課金ゲートの内側という要件も自動的に満たす。
  - `TakePolicy` は全 ability を `ProjectPolicy::capture()` に委譲しており、
    編集者 (org owner/admin・project_admin) は既に通る。**認可の変更が 1 行も要らない。**
  - `cuts.adopted_take_id` を書くのは `Capture/CaptureTakeService::adopt()` のみで、
    ここは `ScenarioWritePathInventoryTest` 検出 4 の allowlist に登録済み。
    **PC 用に別の書き込み経路を作れば inventory 登録が要るだけでなく、
    AGENTS.md ドメイン固有規約 1 の共有ロック規約を守る実装が 2 本になる**
    (思考原則 3・4 に反する)。
  - PC 用に同等の 5 本を複製すると、同じ Service を呼ぶだけの Controller と
    `NestedRouteDefenseInventory` 登録が 5 route 分増える。得られるものは URL の見た目だけである。
- **代償として引き受けること**: `/app/*` は doc/10 §10.8-3 で「撮影 PWA 専用の URL 空間」と
  書かれている。本設計はこれを**「テイク資源の唯一の API 面であり、PC 面もここを叩く」**と
  読み替える。読み替えは `docs/architecture.md` §撮影 PWA の運用契約 と
  `routes/web.php` の group コメントに明記し、Feature テストで
  「編集者が `/app` の take API を使える」ことを固定する (暗黙の前提にしない)。

#### D3. 権限境界 (編集者 project_admin / 撮影者 project_member)

| 対象 | 認可 | 撮影者 (project_member) | 編集者 (project_admin / org admin) |
|---|---|---|---|
| PC テイク選択画面 (新 GET) | `Gate::authorize('update', $manual)` (`VideoManualPolicy::update` → `ProjectPolicy::update`) | **403** | 可 |
| 採用 / 削除 / アップロード / プレビュー (既存) | `TakePolicy` → `ProjectPolicy::capture` | 可 (PWA から) | 可 |

- **非対称は意図的**。PC 編集面は編集者の面 (doc/10 §10.5 の権限表と一致。`analyze` /
  `render` / `download` と同じ扱い)、テイク資源そのものの操作は撮影者にも開いている
  (撮影者が撮った直後に PWA で採用できる既存仕様を壊さない)。
- 撮影者が PC 画面に入れなくても**詰まない**: 撮影 PWA に採用導線があり、
  `Capture/Show` にはマニュアル詳細への復路もある (T155)。

#### D4. PC ローカル動画のアップロード = 既存 presigned フローの再利用

`upload-url` (Quota 予約) → S3 presigned PUT → `POST takes` (HeadObject 三点照合 → 予約 completed)
の 3 段は `resources/js/lib/capture/upload-queue.ts` の `UploadQueue` に実装済みで、
SHA-256 算出・422 quota・409 in-flight backoff まで含む。**PC 側はこれをそのまま使う**。

- PC には IndexedDB による再送キューは要らない (オフライン撮影の要件が無い)。
  `UploadQueue` は `PendingStore` を注入で受けるので、**メモリ実装を渡すだけ**でよい
  (新しいアップロード実装を書かない)。
- ファイル選択は `MediaRecorder` の有無に依存しない `<input type="file" accept="video/*">`
  (`capture` 属性は付けない = PC ではファイルダイアログが開く)。
- 尺 1 分の上限 (doc/04) は**クライアント側の事前案内**として実装する
  (`<video>` の `loadedmetadata` で duration を読み、超過なら押下時にエラー表示)。
  サーバ側の強制は行わない — `duration_ms` はクライアント申告値であって信用できず、
  真の尺はエンコード段 (別タスク) でしか確定しないためである。
  **「1 分を超える動画は登録できない」とは書かない** (保証範囲を誇張しない)。
- **disabled にしない**: 処理中・quota 超過・尺超過はいずれも押下を受けてエラー表示する
  (AGENTS.md 禁止事項 8)。

#### D5. 採用の書き込み経路 = 既存 `CaptureTakeService::adopt()` をそのまま使う

`adopt()` は対象 VideoManual を `lockForUpdate()` した同一トランザクション内で
`adopted_take_id` を書き、`rendering` / `analyzing` 中は 409、`ready` 以外は 422 を返す
(AGENTS.md ドメイン固有規約 1 (i) 準拠)。**新しい書き込み経路を作らないので
`ScenarioWritePathInventoryTest` への追加登録は発生しない。**
PC 側 UI の責務は 409 / 422 を利用者に伝えることだけである。

#### D6. ナレーション ON/OFF の読み替え (理由付き)

doc/04 は「プレビューにナレーション/字幕を ON/OFF」と書くが、**v1 は字幕のみで TTS を
実装しない確定スコープ** (doc/10 / AGENTS.md 使命の v1 スコープ注記) である。
ナレーション音声そのものが存在しないため、「ナレーション ON/OFF」を音声再生の切替として
実装すると**存在しない機能のスイッチ**になる。

したがって本設計では **「ナレーション原稿 (cuts.narration) のテキスト表示 ON/OFF」**
に読み替える。編集者がテイクを見ながら「この原稿の画がこれでよいか」を判断する用途は満たし、
TTS が入った時点で同じスイッチに音声を後付けできる。
**UI の文言も「ナレーション原稿」と書き、音声が出ると誤解させない。**

#### D7. サムネイルのフォールバック (thumbnail_path は当面 null)

`takes.thumbnail_path` は schema に存在するが**現在どこからも書かれていない**
(生成は別タスク)。書かれるまでの表示を先に決める:

- テイクのタイル (動画列 / 選択画面の一覧) は、サムネイル画像ではなく
  **状態タイル** を描く: Lucide の動画アイコン + テイク番号 + 状態バッジ
  (`uploading` / `processing` / `ready` / `failed`) + 尺 (`duration_ms` があれば)。
- **今回は `thumbnail_url` フィールドを DTO に足さない** (常に null の項目を先回りで
  作らない = 思考原則 2)。サムネイル生成タスクが `thumbnail_url` を DTO と
  `TakeThumbnail` コンポーネントの prop として足し、タイルの中身だけを差し替える。
  この差し替え点 (コンポーネント 1 つ) が今回作る**唯一の受け口**である。
- doc/04 の「ホバーで自動再生」は今回のスコープ外。一覧の全テイクに署名 URL を
  先出しすることになり、秘匿と負荷の両面で v1 に必要ない。

#### D8. 動画列と未保存行の扱い

`ScenarioEditor` の作業コピーでは、追加直後の行は `id === null` (= cut がまだ無い)。
テイクは cut に紐づくため、**未保存行の動画セルは遷移リンクを出さず
「シナリオを更新すると動画を登録できます」と案内する** (押せるのに詰むボタンを作らない)。

保存済み行から選択画面へ遷移するとき、未保存の編集があれば `ScenarioEditor` 既存の
dirty 離脱確認が発火する。これは**正しい保護**であり抑止しない。

## 期待効果

- **使命への貢献**: 「編集ゼロ」の最後の 1 ピース。編集者が PC 上で
  「シナリオ (台本) を見る → 撮れた素材を見る → 採用する」を 1 本の導線で完結できる。
  採否の判断が現場作業者から標準を定める側へ戻り、品質が撮影者スキルに依存しなくなる。
- **具体的な改善見込み**:
  - 採用テイク未設定が原因のレンダ 422 (`AdoptedReadyTakeCoverage`) を、
    PC 上でその場で解消できるようになる (現在は PWA を開き直すしかない)。
  - PC 手元にある既存動画 (過去に撮った mp4 等) をマニュアルへ取り込めるようになる。
  - 撮影 PWA を持たない編集者 (PC のみの利用者) が業務を完了できるようになる。

## 実装方針 (概要)

| # | 施策 | 主な変更 | 優先度 |
|---|---|---|---|
| 1 | テイク選択・採用画面の新設 | 新 GET route + `Projects\CutTakeController` + `pages/Manuals/Takes.svelte` + `features/manual/{TakePickerList,TakePreviewPanel,TakeThumbnail}.svelte` + `NestedRouteDefenseInventory` 登録 | P1 |
| 2 | 字幕 overlay の共有化と表示 ON/OFF | `features/capture/SubtitleOverlay.svelte` → `molecules/SubtitleOverlay.svelte` へ昇格 (`CameraRecorder` の import とテストも移す)、ナレーション原稿トグル | P1 |
| 3 | シナリオ編集画面の「動画」列 | `VideoManualController::edit` に `takeSummaries` props 追加 + `ScenarioEditor` に動画セル | P1 |
| 4 | PC ローカル動画のアップロード | `UploadQueue` + メモリ `PendingStore` の再利用、`features/manual/TakeFileUpload.svelte`、尺の事前案内 | P2 |

- 施策 2 で `SubtitleOverlay` を molecules へ上げるのは、**features/manual から
  features/capture を横参照できない**規約 (atomic-import-graph) を、複製ではなく
  共有化で満たすためである。`SubtitleOverlay` は props だけで描画する無状態の
  表示部品であり molecules の要件を満たす。移設と同時に旧位置は消す (思考原則 3)。

## 制約・前提

- **課金ゲートの内側**: 新 route は `routes/web.php` の
  `require-active-subscription` group 内 (業務 route の group) に置く (ドメイン固有規約 4)。
- **nested route の 3 層**: `{project} ∈ current org` (middleware + inline guard) /
  `{manual} ∈ {project}` / `{cut} ∈ {manual}` (`Route::scopeBindings()`)。
  不整合は**認可より前に 404**。`NestedRouteDefenseInventory` へ 3 parameter を登録する
  (登録しないと `NestedRouteIdorDefenseTest` が deny-by-default で落ちる)。
- **`response()->json()` 直書き禁止**: 画面は Inertia props、書き込み応答は既存の
  `CaptureTakeResource` / `CaptureCutResource` をそのまま使う (新規 Resource は作らない)。
- **PHPStan level 10**: props 組み立ては既存 `CaptureCutData` / `CaptureTakeData` の
  `toArray()` (配列 shape が phpdoc で固定済み) を再利用する。
- **DS token / Atomic Design**: 青枠は `ring-primary` 等の token 経由 (hex 直書き禁止)。
  アイコンは `@lucide/svelte` のみ。
- **bug-hunt 目録**: web route を 1 本足すので
  `.claude/skills/app-bug-hunt/inventory/annotations.toml` に注釈を 1 行足して
  目録を再生成する (AGENTS.md bug-hunt 節)。
- **ドキュメント**: `docs/architecture.md` §撮影 PWA の運用契約 に
  「PC 面も `capture.takes.*` を叩く」ことと保証範囲を追記する。

## スコープ外

- サムネイル画像の生成と表示 (別タスク。今回は状態タイルのフォールバックのみ)
- ホバー自動再生
- PC 側からのテイク並べ替え・コメント編集 (doc/04 のテイク選択画面の要件に無い。PWA 側に既存)
- ナレーション音声の再生 (v1 は TTS 非実装。D6)
- サーバ側での尺 1 分の強制 (D4)
- 多言語 (字幕の言語切替)。doc/04 にはあるが v1 スコープ外
- 撮影者 (project_member) 向けの PC 編集面
- テイク資源の PC 専用 route の新設 (D2 で既存再利用と決定)
