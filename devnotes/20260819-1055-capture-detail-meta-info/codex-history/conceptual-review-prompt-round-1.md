# 前提 (AGENTS.md より)

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

【リポジトリの実情 (レビューの前提となる既存実装)】
- 撮影 PWA 詳細画面: resources/js/pages/Capture/Show.svelte
- 撮影詳細 DTO: app/DataTransferObjects/Capture/CaptureManualDetailData.php (現在 id/title/status/cuts の 4 キー)
- 撮影一覧 DTO: app/DataTransferObjects/Capture/CaptureManualSummaryData.php (category_name / creator_name / updated_at を既に持つ)
- 尺の式が現在埋まっている場所: app/Services/Manual/RenderJobService.php の assertTotalSourceDurationWithinLimit()
- 静止画表示秒の唯一の所在: app/Services/Manual/StillDisplayDuration.php
- 実効素材種別の唯一の所在: app/Services/Manual/EffectiveMaterialType.php (adoptedTake を引数で受け relation を読まない)
- 採用済みかつ ready の述語の唯一の所在: app/Services/Manual/AdoptedReadyTakeCoverage.php (ドメイン規約 12)
- adoptedTake を参照する app/ 配下ファイルは app/Support/Security/AdoptedTakeReferenceInventory.php へ deny-by-default で登録が必要
- 尺の可読表記: resources/js/lib/manual/format-duration.ts (null は "—")

---

## 概念設計

# 概念設計: 撮影 PWA シナリオ詳細画面のメタ情報表示

## 判断の出所

- **要件**: `doc/05_スマホアプリ機能仕様.md` §5.2「シナリオ詳細画面（アプリの中心）」L37
  「上部にプレビューエリア、中央にシナリオメタ情報（タイトル / TIME 合計 / カテゴリ・日付・作成者）、
  その下に **手順/急所リスト**。」
- **ギャップの検出**: 要件カバレッジ監査 (2026-08-18)。撮影 PWA の詳細画面
  (`resources/js/pages/Capture/Show.svelte`) はタイトルとカット一覧しか出しておらず、
  TIME 合計 / カテゴリ / 日付 / 作成者の 4 つが未実装であると指摘された。
- **着手の決定**: オーナー判断 (2026-08-19)「要件どおり作る」。
  よって本設計は「出すか出さないか」を再検討しない。決めるのは**何をどう出すか**である。

## 背景・課題

撮影者は一覧画面 (`Capture/Index.svelte`) でカテゴリ・作成者・更新日を見てから詳細へ入るが、
詳細画面に入った瞬間にその手掛かりが消える。同名・類似タイトルのシナリオが複数ある現場で
「いま開いているのはどれか」を確かめる手段が、タイトル 1 行しかない。

TIME 合計にいたっては PWA のどこにも出ていない。撮影者は「この 1 本を撮り切ると何分の動画になるか」を
知らないまま撮影に入る。標準作業を撮って標準化された動画マニュアルを作る、という使命から見ると、
**成果物の長さは撮影中の判断材料**である (長すぎるなら分割する / 短いカットの撮り直しを優先する)。

現行の実装状況:

| 情報 | 一覧カード | 詳細画面 | DTO (`CaptureManualDetailData`) |
|---|---|---|---|
| タイトル | あり | あり | `title` |
| カテゴリ | あり | **なし** | なし |
| 作成者 | あり | **なし** | なし |
| 日付 (更新日) | あり | **なし** | なし |
| TIME 合計 | なし | **なし** | なし |

DTO は `id / title / status / cuts` の 4 キーしか持たない (`status` は現状どの画面でも使っていない)。

## 改善アイデア

`Capture/Show` のヘッダ直下に**シナリオメタ情報の 1 ブロック**を置き、
合計時間 / カテゴリ / 作成者 / 更新日を出す。値は撮影詳細 DTO へ 4 フィールドを足して
サーバ側で解決済みの形で渡す (UI 側で判定を組み立て直さない)。

中心の論点は **TIME 合計を何から算出するか**である。

### TIME 合計の定義 (本設計の核)

要件書の「TIME 合計」は元モックの表記で、意味は「このシナリオを通したときの尺の合計」である。
v1 のドメインでは、カット 1 本の尺は次のように決まる (`Services/Manual/StillDisplayDuration` /
`Services/Manual/EffectiveMaterialType` / `Services/Manual/RenderJobService` の尺上限ゲートが現行の実装):

- **静止画として合成されるカット** … `cuts.static_display_seconds ?? config('manual.default_still_display_seconds')`。
  **撮影前でも確定する** (編集者がシナリオ編集で入れる計画値だから)。
- **動画として合成されるカット** … 採用済みかつ ready のテイクの `duration_ms`。
  **撮影して採用するまで決まらない**。
- **ナレーション尺からの推定は v1 では存在しない**。`StillDisplayDuration` の docblock が
  「v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という属性が存在しない」と明記しており、
  doc/09 の v1 尺算出も `cut_length = material_ms` である。
  ナレーション尺推定をここで新設するのは、**要件に無い推定器を作ること**であり
  (思考原則 2 / 禁止事項「今必要なものだけ作る」)、しかも根拠となる音声が無い以上ただの当て推量になる。

したがって本設計は次を採る:

- **合計は「尺が確定しているカットだけ」を足す**。確定していないカットは合計に入れず、
  **未確定のカット数を併記する**。
- **1 カットも確定していないときは合計を `null` にし、UI は `—` を出す**
  (`lib/manual/format-duration.ts` の `DURATION_UNKNOWN` と同じ思想。
  未確定を `0:00` と書くと「長さゼロの動画がある」という別の嘘になる)。
- **`config('manual.render_default_take_duration_ms')` (60 秒) は使わない**。
  あれはレンダの尺上限ソフトゲートが**上界を安全側に見積もるための代用値**であって、
  利用者に見せる長さではない。表示に使うと「撮っていないカットが 1 分ある」と嘘をつく。

この式は現在 `RenderJobService::assertTotalSourceDurationWithinLimit()` の中に埋まっている
(採用テイク前提・保守的代用あり)。同じ式を撮影 PWA 側へ写経すると、
bug-hunt F-1-01 (充足判定の写経で render と preview が乖離した実測事故) と同じ構図を作る。
よって**式の唯一の所在を 1 クラスへ切り出し、ゲートも撮影 PWA も同じクラスを通す**。
保守的代用値の代入は「ゲートの政策」なので呼び出し側 (`RenderJobService`) に残す。

### 表示語彙

- ラベルは **「合計時間」** とする。「TIME 合計」は元モックの表記であり、
  日本語で書く規律 (造語を作らない) に従って同じ情報を日本語で表す。
- PC 一覧の **「再生時間」とは別の量**なので同じ語を使わない
  (PC 一覧の再生時間は `video_manuals.total_length_ms` = **公開済み完成動画の実尺**。
  撮影 PWA の合計時間は**いま採用されている素材から見込まれる長さ**で、
  導出元・確定タイミング・更新契機が違う。思考原則「別物の概念を似ているからで統合しない」)。
- 未確定の書き方は「合計時間 3:20（未確定 2 カット）」。全カット未確定なら「合計時間 —」。
- カテゴリ null は「未分類」、作成者 null は「不明」、日付は更新日 —
  **いずれも一覧カードで既に使っている語彙をそのまま使う** (同じ意味を別の言葉で言わない)。

### 日付の選択

要件書は「日付」としか書いていない。一覧カードは更新日 (`updated_at`) を出しており、
撮影者が知りたいのは「シナリオがいつ更新されたか」(自分が持っている理解が古くないか) である。
よって**更新日**を出す。作成日は保持しているが要件にも現場の判断にも要らないので出さない。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」で撮り切るために、撮影者が撮影前・撮影中に
  「何を撮っているのか」「撮り切ると何分になるのか」を画面上で確かめられる。
  長すぎる (レンダの尺上限 20 分に迫る) ことにも撮影中に気付ける。
- 一覧 → 詳細で情報が消える段差が無くなる (同じ語彙・同じ並びで連続する)。
- 要件カバレッジ監査の指摘 1 件が閉じる。

## 実装方針（概要）

1. **尺の式を 1 クラスへ**: `App\Services\Manual\CutDurationEstimate` を新設し、
   「1 カットの尺 (ms)、決まらないなら null」を返す唯一の所在にする。
   引数で `Cut` と `?Take` (採用済みかつ ready のテイク) を受け、
   **`adoptedTake` relation を自分では読まない** (`EffectiveMaterialType` と同じ作法 =
   `AdoptedTakeReferenceInventory` の登録を増やさない)。
   `RenderJobService::assertTotalSourceDurationWithinLimit()` を同クラス経由へ書き換える
   (`?? $defaultMs` はゲート側に残す = 挙動は 1 ビットも変えない)。
2. **合計の式も 1 クラスへ**: `App\Services\Manual\ScenarioDurationSummary` (仮) が
   カット列から「確定分の合計 ms (1 件も無ければ null)」と「未確定カット数」を作る。
3. **DTO 拡張**: `CaptureManualDetailData` に
   `category_name` / `creator_name` / `updated_at` / `total_duration_ms` / `undetermined_cut_count`
   を足す。合計は**既に取得済みのカット列と採用テイク**から作るので**追加クエリは 0**。
   カテゴリ・作成者は `belongsTo` 2 本で、controller 側で `loadMissing(['category','creator'])` して
   **1 行あたり固定 2 クエリ** (詳細画面は 1 行なので N+1 にならない)。
4. **TS 型**: `resources/js/types/capture.ts` の `CaptureManualDetail` に同じ 5 キーを足す。
5. **UI**: `components/features/capture/ManualMetaSummary.svelte` を新設し、
   `Capture/Show.svelte` のヘッダ直下 (全画面時に `inert` になる既存 div の中) に置く。
   DS token のみ (`text-caption` / `text-text-secondary` / `border-border` / `bg-surface`)。
6. **テスト**: PHP 側 (Pest) で DTO のキー集合契約・尺算出の分岐・クエリ数、
   TS 側 (vitest) でメタ情報の表示規則と `Capture/Show` の配線。

## 制約・前提

- **作成者名は CipherSweet 復号済みの表示名**。`$manual->creator?->name` を読むだけで、
  一覧の `CaptureManualSummaryData::fromManual()` と**同じ形**にする
  (検索には使わない = 平文 where を作らない)。退会・削除で解決不能なら null。
- **`adoptedTake` 参照の目録 (ドメイン規約 12)**: 新設クラスは relation を読まず引数で受けるので
  `AdoptedTakeReferenceInventory` は増えない。合計の集計は既に登録済みの
  `CaptureManualDetailData` が持つ採用テイク解決 (`AdoptedReadyTakeCoverage::readyTakeId()`) を
  そのまま使う。**ready 判定を自前で書かない**。
- **秘匿境界は props 側** (既存規約)。UI は渡された値の null 判定だけで描き、
  権限・状態の条件を足さない。詳細画面は `Gate::authorize('view', $manual)` を通っており、
  カテゴリ名・作成者名・更新日は同じ利用者が一覧で既に見られる情報なので、
  この画面で新たに漏れるものは無い。
- 撮影 PWA の 3 枚セット (no-store / bfcache / Inertia 履歴暗号化) は変更しない。
- DESIGN.md が token の正本。hex 直書きを増やさない。
- component 階層は `features/capture` に置く (pages を import しない・他 domain を横参照しない)。

## スコープ外

- **ナレーション尺の推定 / TTS の導入**。v1 は字幕のみ。再検討条件は
  `StillDisplayDuration` の docblock が持つ「TTS を導入してナレーション音声の実尺が確定したとき」。
- **詳細画面での編集機能** (カテゴリ変更・タイトル変更・静止表示秒の変更)。要件に無い。
  シナリオの編集は PC 側 (`ScenarioEditor`) の責務で、撮影 PWA は撮る画面である。
- **プレビューエリアの新設**。要件書は「上部にプレビューエリア」と書くが、
  撮影 PWA には既に通し再生 (`ScenarioPreviewDialog`) と撮影パネルがあり、
  本件はメタ情報の欠落を埋める作業である。プレビュー配置の再設計は別件。
- **一覧カードへの合計時間の追加**。一覧は行数ぶんの集計になり N+1 とクエリ設計の話が別に立つ。
  要件が求めているのは詳細画面である。
- **`video_manuals.total_length_ms` (完成動画の実尺) の併記**。別の量であり、
  2 つの尺を並べると撮影者がどちらを見ればよいか分からなくなる。
- **`manual.status` (制作状態) の表示**。要件のメタ情報に含まれず、
  撮影 PWA は既に ready/published だけを母集団にしている (T197 と同じ判断)。

