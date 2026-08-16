# 概念設計: manual-list-duration-and-row-actions (動画一覧の再生時間表示と行内操作)

## 背景・課題

`doc/04 §動画一覧ページ（ホーム）` の要件は「動画リスト（No / 状態 / タイトル / カテゴリ /
**再生時間** / 更新日 / **DL** / **削除**）を表示」であり、一覧の行から直接ダウンロード・削除ができる。

現行の `resources/js/pages/Projects/Show.svelte` が内包する動画マニュアル一覧は
**タイトル / カテゴリ / 作成者 / 更新日 / 状態バッジ**しか持たない。

- **再生時間**が出ない。`video_manuals.total_length_ms` はレンダ完了時
  (`RenderJobService::completeRenderIntoLockedManual()`) に確定して DB にあるが、props に載っていない。
- **行内 DL が無い**。完成 mp4 を受け取るには、行 → 詳細 (`Manuals/Show`) → RenderPanel の
  「完成動画をダウンロード」まで 2 遷移が要る。公開済みマニュアルが 10 件並ぶ運用で、
  現場配布のたびに詳細画面を開き直すことになる。
- **行内削除が無い**。削除も詳細画面まで降りないとできない。

一方で絞り込み (カテゴリ / 状態 / キーワード / 自分の作成分) ・並べ替え・ページネーションは
実装済みであり、**これは維持する**。

## 改善アイデア

一覧の行を「見る場所」から「受け取って片付ける場所」にする。既存 route / Policy /
ability をそのまま使い、**新しい route も新しい ability も作らない**。

1. **再生時間を出す** — `total_length_ms` から派生した `duration_ms` を行 props に載せ、
   人間可読 (`M:SS` / `H:MM:SS`) で表示する。未確定は「—」。
2. **行から DL** — 既存 `projects.manuals.download` (302 → S3 署名 URL) へ張るリンクを、
   サーバが「受け取れる」と判断した行にだけ出す。
3. **行から削除** — 既存 `projects.manuals.destroy` + `VideoManualPolicy::delete` を、
   `ConfirmDialog` (既存 organism) を挟んで呼ぶ。削除後は**絞り込み条件とページ位置を維持**して
   一覧へ戻す。

### 1. 再生時間の表示規則 (何を「再生時間」と呼ぶか)

`total_length_ms` は**レンダ完了時にだけ**書かれる。公開後にシナリオを保存すると
`ScenarioService` が `published → ready` へ戻す (`ScenarioService` L267-269) が、
`total_length_ms` は**古い値のまま残る**。よって「列に生の DB 値を出す」と、
最新シナリオと対応しない尺を「再生時間」として見せることになる。

**規則**: 一覧の再生時間は「**いま公開されている完成動画の長さ**」と定義する。

| 行の状態 | `duration_ms` prop | 表示 |
|---|---|---|
| `status = published` かつ `total_length_ms != null` | その値 | `M:SS` / 1 時間以上は `H:MM:SS` |
| `status = published` かつ `total_length_ms = null` | `null` | `—` |
| `status != published` (draft / analyzing / ready / rendering) | `null` | `—` |

- 判定は**サーバ側で 1 回**行い、props は派生値 `duration_ms` として渡す
  (`RenderPanel` の `finishedJob` と同じ流儀 = 条件を UI 側で再判定しない)。
  生カラム名 `total_length_ms` を prop 名に使わないのは、**生の DB 値ではない**ことを名前で示すため。
- 整形 (ms → `M:SS`) は TS 側の共有ヘルパ (`resources/js/lib/manual/format-duration.ts`) が行う。
  既存 `resources/js/lib/format-bytes.ts` と同じ位置づけで、Vitest 単体テストを持てる。
  日時と違いタイムゾーンに依存しないため、サーバ整形にする理由が無い。
- 再生時間は**権限で隠さない** (撮影者も見てよい)。DL 可否とは別概念。

### 2. 行からのダウンロード

- 既存 route `projects.manuals.download` (`ManualDownloadController`) と ability `download`
  (`VideoManualPolicy::download` = 編集者のみ。撮影者は不可) を**そのまま**使う。
- 行 props に `downloadable: bool` を載せる (**行ごとのサーバ判定**が UI 契約)。サーバ側で
  **`download` ability × `status = published` × 「いま受け取れる完成動画」の実在**を畳み込んだ結果である。
  「いま受け取れる完成動画」の定義は `CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)`
  と**同一世代定義** (同 manual・同 kind の最新 succeeded で、`output_path` が NULL なら旧世代へ
  フォールバックしない) を使う。つまり `downloadable = true` の行は、その瞬間の
  download endpoint が 302 を返す条件と 1 対 1 になる。
- **導線は `downloadable = true` の行にだけ出す**。押せない DL ボタン (disabled) は作らない
  (禁止事項 8)。出さない行の理由はその行が既に語っている — 状態バッジ (下書き / 準備完了 /
  書き出し中) と 再生時間「—」。書き出しの CTA は詳細画面 (RenderPanel) が唯一持ち続け、
  行のタイトルリンクから 1 クリックで届く。**一覧に書き出し操作は増やさない**。
- **押した瞬間には受け取れなくなっていた場合** (props はレンダ時点のスナップショット。
  例: 保持ポリシーで実体が消えた / 別タブでシナリオを保存して published が外れた):
  endpoint は現行どおり **404** を返す (契約は変えない)。行内 DL は素の `<a>`
  (RenderPanel の DL と同じ非 Inertia 遷移) なので、
  `InertiaExceptionRenderer` の passthrough 判定 (`NonInertiaRequest`) により
  **Laravel 既定の 404 ページ**に着地する。ブラウザバックで一覧へ戻れるため詰みではないが、
  Inertia の Error 画面 (戻り先 CTA つき) では受けられない。これは
  **受容するリスク**として明記する (成功時は attachment disposition のため画面は遷移しない)。
  ここを Inertia 化するには download endpoint の応答契約 (302/404) を変える必要があり、
  今回のスコープ外。**この契約 (published が外れた / 現行世代の成果物が無いときは 404) は
  Feature テストで pin する** (今回の変更で退行しないことを機械で示す)。

### 3. 行からの削除

- 既存 route `projects.manuals.destroy` + `VideoManualPolicy::delete` (= 編集者のみ) を使う。
- `ConfirmDialog` (既存 organism。Item 削除・メンバー削除と同じ) を挟む。
  文面はタイトルを含め、取り消せないことを明示する。
- 導線を出す条件は**サーバが評価した `delete` ability** を畳んだ行 prop `deletable: bool` であり、
  UI 側で役割を推論しない (DL と粒度を揃える。page 級の `canDeleteManuals` は作らない)。
- **削除後の着地**: 現行の `destroy` は `redirect()->route('projects.show', $project)` で、
  クエリを持たないため**絞り込みもページ位置も失われる**。一覧から連続で片付ける動線では
  毎回フィルタが飛ぶ。そこで:
  - 行の削除リクエストは**現在の一覧クエリを付けて**送る
    (`DELETE /projects/{p}/manuals/{m}?category=…&status=…&q=…&sort=…&mine=1&page=2`)。
  - `destroy` は受け取ったクエリを**一覧と同じ allowlist で再解析**してから
    `projects.show` の redirect に載せ直す。生のユーザー入力を素通しで Location に載せない。
  - 詳細画面からの削除 (クエリ無し) は**現行と完全に同じ**着地になる (退行なし)。
  - allowlist 解析は現在 `ProjectController::parseManualFilters()` に private で埋まっているため、
    **一覧クエリの値オブジェクトとして切り出して 2 か所で共有**する (判定を 2 か所に書かない)。
- **ページ位置の扱い**: 最終ページの最後の 1 件を消すと、維持した `page` が範囲外になり
  「空の一覧」に着地しうる。そこで一覧側で **範囲外ページを最終ページへ丸める**
  (`current_page > last_page` かつ総件数 > 0 なら最終ページで引き直す)。
  これは削除フローだけでなく、古いブックマーク・別タブで件数が減った場合にも効く。
- 削除の応答は Inertia の redirect なので、**一覧は redirect 先の再描画でそのまま最新化される**
  (別途の再取得呼び出しは持たない)。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」で作った動画マニュアルは、**現場へ配る**まで含めて
  価値になる。一覧から直接受け取れることで配布のクリック数が減る (行 → 詳細 → DL が 行 → DL)。
- **完成動画の尺を一覧で把握できる**: 「朝礼で流せる長さか」「作業前に見せられるか」といった
  配布判断に要る情報は尺であり、状態バッジでは代替できない (バッジは完成の有無しか語らない)。
- **doc/04 の一覧要件との差の解消**: 再生時間 / DL / 削除の 3 列が揃う。
- 片付け (削除) が一覧で完結し、絞り込みが飛ばないので、カテゴリ単位の整理が実務的になる。

## 実装方針（概要）

| 層 | 変更 |
|---|---|
| Model | `VideoManual` に「最新 succeeded な render job」の HasOne 関係 (`ofMany`) を足し、行ごとの N+1 を避ける (eager load 可能にする)。`CurrentRenderArtifact` と同一世代定義であることをテストで固定する |
| DTO | 行 props を `App\DataTransferObjects\Manual\ManualListItemData` (readonly + `toArray()` の array shape 明示) にする。一覧クエリを `App\DataTransferObjects\Manual\ManualListQuery` 値オブジェクトにする |
| Service | `App\Services\Manual\ManualRowAbilities` (download / delete の可否をページで 1 回評価し、行 props へ供給する) |
| Controller | `ProjectController::show` の行生成を DTO 化し `duration_ms` / `downloadable` / `deletable` を追加、範囲外ページの丸め。`VideoManualController::destroy` の redirect に一覧クエリを維持 |
| TS 型 | `resources/js/types/manual.ts` の `ManualListItem` に `duration_ms` / `downloadable` / `deletable` を追加 |
| JS lib | `resources/js/lib/manual/format-duration.ts` (ms → `M:SS` / `H:MM:SS`) を新設 |
| UI | 一覧の行を `components/features/manual/ManualListRow.svelte` へ切り出し、再生時間 / DL / 削除を配置。`Projects/Show.svelte` は行の描画をこれに委ね、ConfirmDialog と削除呼び出しを持つ |
| テスト | Feature (props shape / 認可 / 削除後の着地 / ページ丸め / 世代定義の一致) と Vitest (整形ヘルパ / 行コンポーネント / ページ結線) の両方 |

### 認可の評価回数について (N+1 を作らない)

**UI 契約は「行ごとのサーバ判定」**である (`downloadable` / `deletable` は行 props)。
一方、**評価回数**はページで 1 回に畳む。理由:

- `VideoManualPolicy::download` / `::delete` は対象 manual からは `project` しか読まず、
  `ProjectPolicy::update($user, $project)` に委譲する。
- その `ProjectPolicy` の内部は毎回 DB を見る。`Project::memberRole()` は
  `members()->whereKey()->first()` を memo 無しで撃ち、Laratrust のキャッシュは
  `config/laratrust.php` で `'enabled' => env('LARATRUST_ENABLE_CACHE', env('APP_ENV') === 'production')`
  = **production 以外では無効**なので `hasRole()` も毎回 DB を見る。
- per_page=10 × 2 ability = 最大 20 回の権限解決 = 数十クエリ。行数に比例する。
- `Project::memberRole()` 側に memo を入れる案は採らない。既存テスト
  (`ConsoleRoleTransitionTest`) が同一インスタンスに対しロール変更の前後で値を読むため、
  インスタンス memo は既存の期待を壊す。

**controller で `ProjectPolicy::update` を直接評価する形は採らない**。それは
「`download` / `delete` という ability 名を問うのをやめて、委譲先を controller に
ハードコードする」ことであり、policy が分岐した日に**赤くならずに間違う**。
ability 名は正しいまま、多重度だけを畳む。

畳み込みは**名前のある場所**に置く: `App\Services\Manual\ManualRowAbilities`
(「同一 project の manual に対する download / delete の可否は project 単位で決まる」ことを
名前と docblock で宣言する。代表行の選び方はこのクラスの内部に閉じ、controller / UI からは見えない)。

前提は**機械で固定**する:

| テスト | 固定する事実 |
|---|---|
| `ManualRowAbilityPremiseTest` (Feature) | 同一 project の 2 manual (status / creator / category が異なる) で `download` / `delete` の可否が一致する。撮影者は両方 false、編集者は両方 true |
| `ManualListQueryCountTest` (Feature) | 1 行のページと 10 行のページで発行クエリ数が同数 (行数に比例しない) |

前提が崩れる変更 (policy が manual 依存になる) をした人には、前者が赤で知らせる。

## 制約・前提

- 新しい route / ability / DB カラムを追加しない (既存 `projects.manuals.download` /
  `projects.manuals.destroy` / `download` / `delete` をそのまま使う)。
- 撮影者 (project_member) には DL / 削除の導線を出さない (ability の評価結果が false になるため、
  UI の分岐ではなく props で決まる)。
- 絞り込み・検索・並べ替え・ページネーションの現行挙動を変えない
  (唯一の例外はキーワードの長さ上限。下記)。
- `response()->json()` は使わない (Inertia props と redirect のみ)。
- PHPStan level 10 / Pest / RefreshDatabase グローバル適用 / Factory 生成のテストデータ。
- フロントは Svelte 5 runes + DS token のみ、アイコンは `@lucide/svelte`
  (`Download` / `Trash2`)、component 階層は `pages → features/manual` の単方向 import。
- **キーワード `q` の上限**: redirect に載せ直す関係で `q` に上限を設ける (200 文字。
  `title` の validation `max:200` と一致)。契約は「**先頭 200 文字だけを検索語として使う
  (truncate)**」であり、破棄 (= 絞り込み無し = 全件表示) にはしない。破棄は「全件が出る」
  驚きの方向へ倒れるが、切り詰めは「より広く当たる」方向にしか倒れず詰みを作らない。
  絞り込みと削除後 redirect は**同じ値オブジェクトを通る**ため、両者の結果は必ず一致する。

## スコープ外

- 一覧の「No」列 (doc/04 の連番)。ページネーションと並べ替えがあるため通し番号の意味が定まらず、
  別途の設計判断が要る。
- 詳細画面 (`Manuals/Show`) への再生時間表示、ダッシュボードの再生時間。
- 再生時間での並べ替え・絞り込み (`ManualSortOption` は増やさない)。
- 一括選択・一括削除・一括ダウンロード。
- download endpoint の応答契約 (302 / 404) の変更、および 404 を Inertia Error 画面で受ける対応。
- 一覧からの書き出し (レンダ) 実行。
