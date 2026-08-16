# 概念設計: manual-status-display-vocabulary (動画マニュアル状態の表示語彙の写像)

## 背景・課題

`doc/` が定める「動画マニュアルの状態」は 3 値だが、実装の `VideoManualStatus` は 5 値で
制作パイプラインの進行状態も兼ねている。両者の写像がどこにも定義されておらず、
UI は 5 値をそのまま出している。さらに撮影 PWA の一覧は**状態を使わずに**撮影進捗から
別の 3 値バッジを出しているため、「3 値バッジ」という見た目だけが 2 系統ある。

### 棚卸し: いま何がどこで語彙を決めているか (現行コードを実読した結果)

| # | 決定点 | 場所 | 値 | 用途 |
|---|--------|------|----|------|
| 1 | 制作状態の値集合 (正本) | `app/Enums/Manual/VideoManualStatus.php` | draft / analyzing / ready / rendering / published | DB 列 `video_manuals.status` の cast |
| 2 | 状態の日本語ラベル | `resources/js/types/manual.ts` `VIDEO_MANUAL_STATUS_LABELS` | 下書き / 解析中 / 準備完了 / 書き出し中 / 公開済み | 一覧行・詳細・ダッシュボードのバッジ、一覧の絞り込み select |
| 3 | 状態バッジの意味色 | 同 `STATUS_TONES` | neutral / tertiary / success / warning / primary | 上と同じ 4 箇所 |
| 4 | 一覧の絞り込み allowlist | `app/DataTransferObjects/Manual/ManualListQuery::fromRequest()` L76-77 | `VideoManualStatus::tryFrom()` を通った 5 値のみ (他は null=すべて) | `?status=` の解析。一覧 (`ProjectController::show`) と行内削除の着地先 (`VideoManualController::destroy`) の**唯一の解析点** |
| 5 | 一覧の WHERE | `app/Http/Controllers/Projects/ProjectController.php` L181-182 | `where('status', $listQuery->status)` の完全一致 | 絞り込み |
| 6 | 一覧行の payload | `app/DataTransferObjects/Manual/ManualListItemData` `$status` → `toArray()['status']` | 5 値の文字列 | 行バッジ (**この行 props の `status` は行バッジ以外に使われていない**) |
| 7 | 一覧の絞り込み select 選択肢 | `resources/js/pages/Projects/Show.svelte` L431-435 | `Object.entries(VIDEO_MANUAL_STATUS_LABELS)` を全件展開 = 5 選択肢 + 「すべて」 | 絞り込み UI |
| 8 | 撮影 PWA 一覧のバッジ | `resources/js/pages/Capture/Index.svelte` L122-129 | 撮影完了 / 撮影中 / 未撮影 | **`status` ではなく** `cuts_total` / `cuts_adopted` / `cuts_with_takes` の三項式から導出 |
| 9 | 撮影 PWA の対象絞り込み | `app/Http/Controllers/Capture/CaptureManualController::index()` L65 | `whereIn('status', [Ready, Published])` | 撮影対象の母集団 (画面に語彙としては出ない) |
| 10 | ダッシュボードの区分 | `app/Services/Dashboard/DashboardService.php` L111 / L190 | 進行中 = [Analyzing, Rendering] / 撮影できる = [Ready, Published] | ダッシュボードの 2 セクション。行バッジは #2 の 5 値 |
| 11 | doc の値 (仕様側) | `doc/04 §動画一覧ページ` | 作成済 / 作成中 / 未着手 | 一覧の絞り込み要件 |
| 12 | doc の値 (仕様側) | `doc/02 §2.4 動画マニュアル` | 撮影完了 / 着手中 / 未着手 | データモデルの `状態` |

### ブリーフの前提の検証 (食い違いの訂正)

現行コードを読んだ結果、ブリーフの前提のうち **2 点を訂正**し、**3 点を追加**する。

- **訂正 1**: ブリーフは doc の 3 値を「doc/04 = 作成済/作成中/未着手」「doc/02.4 = 撮影完了/着手中/未着手」と
  並記しているが、これは**同じ 1 つの状態に対して doc 内部で語が食い違っている**という事実である
  (棚卸し #11 と #12 は同じ `動画マニュアル.状態` を指す)。つまり写像すべき「doc の 3 値」は
  **1 組ではなく 2 組**あり、どちらを採るかの決定が先に要る。しかも doc/02.4 の「撮影完了」は
  撮影 PWA の進捗語彙 (#8) と語が衝突しており、**doc 側が既に 2 系統混線の発生源になっている**。
- **訂正 2**: ブリーフは「撮影 PWA の一覧は既に撮影進捗から 3 値バッジを出しており、語彙が 2 系統に分かれている」と
  書くが、PWA のバッジは `status` を一切参照しない (棚卸し #8)。**同じ量の別表現ではなく、別の量の別表現**である。
  「2 系統に分かれている」のは**語彙の見た目**であって写像の重複ではない
  (写像規則は現状どこにも存在せず、**0 か所**である)。
- **追加 1**: `ManualListItem.status` は**一覧行のバッジ以外に使われていない**
  (`ManualListRow.svelte` を実読。プレビュー / DL / 削除の出し分けは
  `current_finished_render_job_id` と `deletable` が担う = T154/T182 の設計どおり)。
  したがって行 payload の状態表現を差し替えても、行の操作系には波及しない。
- **追加 2**: `VideoManualStatus` は **PHP enum ⇔ TS union の値集合同期テストの対象外**である
  (`tests/Architecture/ManualEnumTsSyncInvariantTest.php` は RenderKind / RenderStep /
  RenderErrorCode / RenderConflictType / JobStatus / MaterialType の 6 本だけを固定。
  `types/manual.ts` L5 のコメントも「乖離検知は当面手動確認」と明記)。
  語彙を増やすなら、この穴を塞がずに増やすと drift が 2 本になる。
- **追加 3**: 撮影 PWA の行 payload (`CaptureManualSummaryData`) は `status` を積んでいるが、
  **`Capture/Index.svelte` はそれを表示にも分岐にも使っていない** (dead payload)。
  5 値語彙が、別語彙を表示する画面の props に載ったまま残っている。

### 何が問題か (利用者から見た症状)

1. **doc の要件が実装されていない**: doc/04 の絞り込みは 3 値だが、実 UI は 5 選択肢を出す。
2. **一覧の 5 値は陳腐化する**: 一覧はポーリングしない (詳細画面の AnalysisPanel / RenderPanel だけがポーリングする)。
   「解析中」「書き出し中」は数十秒で終わる遷移状態なので、一覧のバッジは**再読込するまで嘘を表示し続ける**。
   その値で絞り込める UI は「絞った直後に結果が実態と合わない」体験を作る。
3. **語が重なって見える**: PC の「作成中」候補と PWA の「撮影中」、doc/02.4 の「撮影完了」と PWA の「撮影完了」。
   別の量なのに語が近く、**統合したくなる引力**が常に働いている (思考原則 4 の逆方向)。

## 改善アイデア

### 決定 1: doc の 3 値は **doc/04 の語 (作成済 / 作成中 / 未着手)** を採り、doc/02.4 を合わせる

理由: (a) 3 値が絞り込み UI の要件として書かれているのは doc/04 であり、実装対象はそちら。
(b) doc/02.4 の「撮影完了」は撮影 PWA の進捗語彙と衝突し、**混線の発生源そのもの**である。
語を残したまま写像だけ作ると、次に読む人が必ずまた混ぜる。
よって `doc/02 §2.4` の `状態` の値を doc/04 の語へ揃える (仕様の語彙統一。振る舞いの変更ではない)。

### 決定 2: 写像の正本は **PHP の新 enum `App\Enums\Manual\ManualProgress` ただ 1 か所**

```
ManualProgress: not_started (未着手) / in_progress (作成中) / completed (作成済)
ManualProgress::forStatus(VideoManualStatus): self   ← 網羅 match。写像規則はここだけ
ManualProgress::statuses(): list<VideoManualStatus>  ← forStatus から導出 (逆写像を別表で持たない)
ManualProgress::statusValues(): list<string>         ← statuses() から導出。クエリへ渡すのはこちら
```

`statusValues()` を分けるのは、**型 (enum) と SQL (文字列) の境界をコード上で閉じる**ため
(Laravel の binding は BackedEnum を value 化するが、それに依存すると境界が読めない。
概念レビュー Round 1 [Warning] 対応)。

写像:

| VideoManualStatus | ManualProgress | 根拠 |
|---|---|---|
| draft | not_started | シナリオ (cuts) が未確定。解析失敗時も cuts が無ければ draft へ戻る (`AnalysisJobService` L216-218) |
| analyzing | in_progress | 解析実行中 |
| ready | in_progress | シナリオ確定・撮影/書き出し待ち |
| rendering | in_progress | 書き出し実行中 |
| published | completed | 現行世代の完成動画がある。シナリオを保存すると ready へ戻る (`ScenarioService` L267-268) = 「完成済み」の意味と一致 |

- **逆写像を別に書かない**: `statuses()` は `VideoManualStatus::cases()` を `forStatus()` で振り分けて導出する。
  match 文はリポジトリ内に 1 つだけになり、新しい status を追加したときに
  **網羅 match が PHPStan level 10 で落ちる** (widen せずに気づける)。
- **ラベル (日本語) は TS 側に 1 か所だけ置く** (`MANUAL_PROGRESS_LABELS`)。PHP はラベルを持たない
  (値の意味は enum、表示文字列は UI という現行の分担を崩さない)。
- **PHP enum ⇔ TS union の値集合は Architecture テストで固定**する。ついでに現状穴が空いている
  `VideoManualStatus` 自身も同テストへ登録する (追加 2 の穴を塞ぐ)。

### 決定 3: **一覧画面 (絞り込み + 行バッジ) は 3 値へ統一**する。詳細画面とダッシュボードは 5 値のまま

| 画面 | 語彙 | 理由 |
|---|---|---|
| 一覧 (Projects/Show) 絞り込み | 3 値 | doc/04 の要件。遷移状態で絞る利用価値がほぼ無い (数十秒で消える) |
| 一覧 (Projects/Show) 行バッジ | 3 値 | **絞り込みと同じ語彙でないと結果が説明できない** (「作成中」で絞ったのに行が「解析中」だと絞り込みの故障に見える)。一覧はポーリングしないので遷移状態の表示は陳腐化する |
| 詳細 (Manuals/Show) | 5 値 | 同じ画面の AnalysisPanel / RenderPanel が**ポーリングして進行を実況する**。バッジがその実況と同じ語であるべき |
| ダッシュボード | 5 値 | 「進行中のマニュアル」セクションは analyzing / rendering **だけ**を集めた面であり、区別が消えると意味が無くなる |

- 「両方出す」(8 選択肢) は採らない。選択肢が倍になるだけで、どちらで絞るべきかの判断を利用者に押し付ける (禁止事項 6 / 思考原則 2)。
- 一覧が失う情報 (「いま解析中/書き出し中か」) は**詳細画面とダッシュボードが持っている**ため、
  行き先のない情報消失にはならない。
- **`ready` と `rendering` を畳んでも一覧の操作は変わらない**: 一覧行の操作
  (プレビュー / DL / 削除) は現行でも `status` ではなく `current_finished_render_job_id` と
  `deletable` だけで決まっており、`ready` / `rendering` の差で出し分けている導線は
  一覧に 1 つも無い (現行コード実読)。次の一手の CTA は詳細画面が唯一持つ (T148 / T154 の設計)。
  よって**一覧に CTA を足さない**。代わりに「畳んでも失われていない」ことを
  詳細画面側の既存テストの維持で担保する (概念レビュー Round 1 [Warning] 対応)。
- 一覧と詳細で語が変わる点は意図的である: **一覧は「仕上がっているか (資産の状態)」、詳細は「いま何をしているか (作業の実況)」**
  という別の問いに答える面である。これを 1 語彙に潰すと、どちらかの面で必ず嘘か過剰になる。

### 決定 4: URL クエリは `?status=` を **`?progress=` へ置き換え、旧値の互換は残さない**

- 値集合が変わる (5 値 → 3 値) 時点で `?status=ready` は意味を保てない。互換のために
  「旧 5 値も受けて progress に畳む」経路を残すと、**写像規則が ManualListQuery にも生まれ**、
  「正本を 1 か所」という本設計の目的そのものを壊す (思考原則 3)。
- キー名も `status` → `progress` にする。同じコードベースで `status` が
  「5 値 (VideoManualStatus)」と「3 値 (絞り込み)」の 2 つの値域を指す状態を作らないため。
- 旧 URL (`?status=ready` のブックマーク / 履歴) は**未知キーとして無視され「すべて」になる**。
  これは `ManualListQuery` の既存方針 (allowlist 外は null = 絞り込み無し = **より広く当たる方向へ倒す**) と一致する。
  403 や 404 で突き放さず、一覧は必ず開く。
- 日本語ラベルは「状態」のまま (doc/04 の語)。内部識別子だけを `progress` にする。

### 決定 5: 撮影 PWA の進捗語彙は **別物として維持**し、コードで別物と宣言する

- PC の 3 値は `video_manuals.status` (制作の到達段階) の写像。
  PWA の 3 値は `cuts_adopted / cuts_with_takes / cuts_total` (**この 1 本のマニュアルの撮影がどこまで進んだか**) の導出。
  母集団も更新契機も違い、値が独立に動く (例: `ready` かつ全カット採用済み = PC「作成中」/ PWA「撮影完了」は正常な組合せ)。
  **統合しない** (思考原則 4)。
- ただし「別物である」ことを散文でしか書かないと必ずまた混ざるので、
  PWA 側の三項式を `types/capture.ts` の名前付き導出 (`CaptureProgress` + ラベル表) へ切り出し、
  そこに「PC の ManualProgress とは別の量である」ことを明記する。Vitest で
  「PWA のバッジ語は 撮影完了 / 撮影中 / 未撮影 のままである」ことを回帰として固定する。
- 併せて `CaptureManualSummaryData.status` (dead payload。追加 3) を落とす。
  別語彙を表示する画面へ 5 値を送り続ける理由が無く、混ぜる引力を残さない。

## 期待効果

- **使命への貢献**: 「思考ゼロ」で使える現場向け UI であるために、一覧が答えるべき問いは
  「どれがまだ出来ていないか」1 つである。制作パイプラインの内部状態を現場に見せない方向へ寄せる。
- doc/04 の絞り込み要件が実装される (仕様と実装の乖離が 1 件解消)。
- 「3 値 ⇔ 5 値」の写像規則がリポジトリに 1 か所だけ存在する状態になり、
  新しい status を足したときに**網羅 match と値集合同期テストの両方が落ちる** (無音の drift が消える)。
- 一覧のバッジの**陳腐化の幅が小さくなる**: 数十秒で消える遷移状態 (解析中 / 書き出し中) を
  一覧に出さないため、その分だけ「再読込するまで嘘」の窓が消える。
  **陳腐化そのものは無くならない** — 一覧は依然ポーリングしないので、`rendering → published` の
  遷移も再読込までは反映されない (誇張しない。概念レビュー Round 1 [Warning] 対応)。
- PC と PWA の語彙の違いがコードとテストで宣言され、次の変更で統合される事故を防ぐ。

## 実装方針 (概要)

1. `app/Enums/Manual/ManualProgress.php` を新設 (3 case + `forStatus()` + `statuses()`)。
2. `ManualListQuery`: `$status: ?string` → `$progress: ?ManualProgress`、`?status=` の解析を `?progress=` へ置換。
   `toProps()` / `toQueryParams()` のキーも `progress` へ。他の allowlist (category / q / sort / mine / page) は触らない。
3. `ProjectController::manualRows()`: `where('status', …)` → `whereIn('status', $progress->statuses())`。行 shape の PHPDoc を更新。
4. `ManualListItemData`: `$status: VideoManualStatus` → `$progress: ManualProgress`、`toArray()['status']` → `['progress']`。
5. `resources/js/types/manual.ts`: `ManualProgress` union + `MANUAL_PROGRESS_LABELS` + `MANUAL_PROGRESS_TONES` を追加。
   `ManualListItem.status` → `progress`、`ManualFilters.status` → `progress`。
   `VIDEO_MANUAL_STATUS_LABELS` / `STATUS_TONES` は**詳細・ダッシュボード用として残す** (役割をコメントで明記)。
6. `ManualListRow.svelte` / `Projects/Show.svelte`: 3 値のラベル表を使う。select は 3 選択肢 + 「すべて」。
7. `types/capture.ts` + `Capture/Index.svelte`: 撮影進捗の導出を名前付きにし、別物であることを明記。
   `CaptureManualSummaryData.status` を撤去。
8. `tests/Architecture/ManualEnumTsSyncInvariantTest.php` に `ManualProgress` と `VideoManualStatus` の 2 本を追加
   (**両方必須**。片方だけだと drift が残る)。
9. 写像の網羅性・排他性・和集合一致を固定する Pest テストと、
   `ManualListItemData::toArray()` の shape (キー集合) を固定する Feature テストを新設
   (行 props は Inertia 契約の破壊的変更のため。概念レビュー Round 1 [Warning] 対応)。
10. `doc/02 §2.4` の状態の語を doc/04 に揃え、`doc/04` に「3 値と実装 5 値の写像の正本は `ManualProgress`」の 1 行を足す。

## 制約・前提

- `ManualListQuery` は一覧と行内削除の着地先が共有する**唯一の解析点**である (T182 / T189)。
  この性質を壊さない = 解析点を増やさず、同じ VO の中でキーと型だけを置き換える。
- `Capture/Index` の母集団 (`whereIn('status', [Ready, Published])`) は撮影対象の定義であり、
  表示語彙とは別問題なので**触らない**。
- ダッシュボードの 2 セクション (`[Analyzing, Rendering]` / `[Ready, Published]`) も
  「いま動いているもの」「撮影できるもの」の定義であり、3 値写像とは別軸なので**触らない**
  (3 値へ寄せると「進行中」= in_progress ∩ 未 ready という別定義が必要になり、写像が 2 本になる)。
- 後方互換の並走を残さない (思考原則 3)。旧 `?status=` の受理経路は同じ変更で消す。
- Feature (Pest) と Vitest の両方でテストする。

## スコープ外

- `VideoManualStatus` の値集合そのものの変更 (5 値は制作パイプラインの必要から来ており、減らさない)。
- 一覧のポーリング化 (遷移状態を一覧で正しく見せるための別手段。今は必要ない = 思考原則 2)。
- 公開範囲 (doc/02.4 の `公開範囲`) — T193 で「今は作らない」結論済み。
- 多言語ラベル (i18n 基盤の導入)。
- ダッシュボード / 詳細画面の語彙変更。
