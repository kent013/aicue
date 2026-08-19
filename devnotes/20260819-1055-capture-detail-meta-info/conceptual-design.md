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

TIME 合計にいたっては PWA のどこにも出ていない。撮影者は、いま採用されている素材が
合わせて何分あるのかを知らないまま撮影を続けている。標準作業を撮って標準化された動画マニュアルを作る、
という使命から見ると、**手元にある素材の長さは撮影中の手掛かり**である
(レンダの尺上限 20 分に近づいていることにその場で気付ける)。

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
合計時間 / カテゴリ / 作成者 / 更新日を出す。値は撮影詳細 DTO へ **5 キー**を足して
サーバ側で解決済みの形で渡す (UI 側で判定を組み立て直さない)。
5 キーの内訳は後述の「実装方針」3 のとおり
(合計時間だけが「確定分の合計」と「未確定カット数」の 2 キーになる)。

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
  **未確定のカット数を常に併記する** (件数を出さないと、`—` が「カットが無い」のか
  「全カット未撮影」なのか区別できない)。
- **1 カットも確定していないときは合計を `null` にし、UI は `—` を出す**
  (`lib/manual/format-duration.ts` の `DURATION_UNKNOWN` と同じ思想。
  未確定を `0:00` と書くと「長さゼロの動画がある」という別の嘘になる)。
- **`config('manual.render_default_take_duration_ms')` (60 秒) は使わない**。
  あれはレンダの尺上限ソフトゲートが**上界を安全側に見積もるための代用値**であって、
  利用者に見せる長さではない。表示に使うと「撮っていないカットが 1 分ある」と嘘をつく。
  切り出すのは**カット単位の式だけ**で、この代用値の代入は `RenderJobService` に残す
  (表示式とゲートの政策を同じクラスへ混ぜると、レンダ上限の安全側の性質が表示都合で動きうる)。

**この値は「完成動画の見込み尺」ではない**。未撮影の動画カットの尺は v1 では原理的に出せないので、
本設計が出すのは**いま確定している素材の合計**である。期待効果もその範囲でだけ主張する。

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
- 未確定が 1 件以上あるときは **「合計時間 3:20（確定分・未確定 2 カット）」**と書き、
  値が部分和であることを値の隣で言う。未確定 0 件のときだけ **「合計時間 3:20」**
  (このときは部分和ではなく全体の合計なので但し書きが要らない)。
  全カット未確定なら **「合計時間 —（未確定 5 カット）」**、カットが 0 件なら **「合計時間 —」**。
  ラベル自体を「確定済み素材の合計時間」にはしない — 未確定 0 件は正常な完成状態であり、
  そのときまで但し書きを付けると読み手に不要な条件を探させる。
- カテゴリ null は「未分類」、作成者 null は「不明」、日付は更新日 —
  **いずれも一覧カードで既に使っている語彙をそのまま使う** (同じ意味を別の言葉で言わない)。

### 日付の選択

要件書は「日付」としか書いていない。一覧カードは更新日 (`updated_at`) を出しており、
撮影者が知りたいのは「シナリオがいつ更新されたか」(自分が持っている理解が古くないか) である。
よって**更新日**を出す。作成日は保持しているが要件にも現場の判断にも要らないので出さない。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」で撮り切るために、撮影者が撮影中に
  「いま何を撮っているのか」「手元の素材は合わせて何分あるのか」を画面上で確かめられる。
  レンダの尺上限 20 分に近づいていることにも撮影中に気付ける
  (**分割するかどうかの判断は PC 側の編集者の仕事であり、撮影 PWA は気付きを与えるだけ**)。
- 一覧 → 詳細で情報が消える段差が無くなる (同じ語彙・同じ並びで連続する)。
- 要件カバレッジ監査の指摘 1 件が閉じる。

## 実装方針（概要）

**テストファーストで進める**。各施策は対応するテストを先に書いて**赤を確認してから**実装に入る
(思考原則 5)。既存の抽出器・component を流用して最初から緑になる場合は、
負例が押さえる分岐を一時的に壊して赤を確認する。

1. **カット単位の尺の式を 1 クラスへ**: `App\Services\Manual\DeterminedCutDuration` を新設し、
   「1 カットの尺 (ms)、確定していなければ null」を返す唯一の所在にする。
   引数で `Cut` と `?Take` (採用済みかつ ready のテイク) を受け、
   **`adoptedTake` relation を自分では読まない** (`EffectiveMaterialType` と同じ作法 =
   `AdoptedTakeReferenceInventory` の登録を増やさない)。
   `RenderJobService::assertTotalSourceDurationWithinLimit()` を同クラス経由へ書き換える。
   **`?? config('manual.render_default_take_duration_ms')` はゲート側に残す** =
   ゲートの挙動は 1 ビットも変えない (境界値テストで固定する)。
2. **合計の式も 1 クラスへ**: `App\Services\Manual\DeterminedScenarioDuration` を
   **`final readonly class` の結果型**として置き、`?int $totalDurationMs` と
   `int $undeterminedCutCount` の 2 つを持つ。生成は次の 1 本だけで、
   **入力はカット 1 本ずつの確定尺 (`DeterminedCutDuration` の戻り値) を並べた配列**である
   (要素型は PHP の引数型宣言では書けないので PHPDoc で固定する)。

   ```php
   /**
    * @param  list<int|null>  $perCutDurationsMs
    */
   public static function fromCutDurations(array $perCutDurationsMs): self
   ```

   つまりこのクラスは `Cut` も `Take` も受け取らないので、`adoptedTake` relation を
   **読みようがない** (`AdoptedTakeReferenceInventory` の登録は増えない)。
   採用済みかつ ready のテイクの解決は呼び出し側 (`CaptureManualDetailData` が既に持っている
   `AdoptedReadyTakeCoverage::readyTakeId()` 経由の解決) が行い、その結果を
   `DeterminedCutDuration::milliseconds($cut, $take)` へ渡して `list<int|null>` を作る。
3. **DTO 拡張**: `CaptureManualDetailData` に次の 5 キーを足す。
   - `category_name: ?string` / `creator_name: ?string`
   - `updated_at: ?string` … **ISO 8601 文字列へ正規化**する (`?->toIso8601String()` =
     一覧 DTO `CaptureManualSummaryData` と同じ形。Carbon をそのまま props へ渡さない)
   - `total_duration_ms: ?int` … 確定分の合計。1 件も確定していなければ `null`
   - `undetermined_cut_count: int` … 尺が確定していないカット数
   合計は**既に取得済みのカット列と採用テイク**から作るので**追加クエリは 0**。
   カテゴリ・作成者は `belongsTo` 2 本で、controller 側で `loadMissing(['category','creator'])` する
   (**追加は最大 2 件・既にロード済みなら 0 件**)。詳細画面が扱う manual は 1 行なので、
   カット数・テイク数に比例するクエリは増えない (Pest で固定する)。
4. **TS 型**: `resources/js/types/capture.ts` の `CaptureManualDetail` に同じ 5 キーを
   **同じ nullable 契約で**足す。
5. **UI**: `components/features/capture/ManualMetaSummary.svelte` を新設し、
   `Capture/Show.svelte` のヘッダ直下 (全画面時に `inert` になる既存 div の中) に置く。
   DS token のみ (`text-caption` / `text-text-secondary` / `border-border` / `bg-surface`)。
6. **テスト**: PHP 側 (Pest) は
   (a) `DeterminedCutDuration` のカット単位の分岐、
   (b) `DeterminedScenarioDuration` の集計分岐 —
   空配列 → 合計 null / 未確定 0 件、全件 null → 合計 null / 未確定は全件、
   混在 → 確定分だけの合計と null の件数、全件確定 → 全件合計 / 未確定 0 件、
   (c) レンダ上限ゲートの境界値 (挙動不変)、
   (d) 撮影詳細 props のキー集合契約と値、
   (e) 詳細画面 1 回ぶんのクエリ数がカット数・テイク数に比例しないこと。
   TS 側 (vitest) はメタ情報の表示規則 (4 通りの書き分け) と `Capture/Show` の配線。
   **UI テストは渡された props の表示規則だけを見る**ので、集計式の検証は (b) が担う。

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
