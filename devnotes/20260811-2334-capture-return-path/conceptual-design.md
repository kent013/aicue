# 概念設計: capture-return-path (撮影 PWA からマニュアル詳細への戻り導線)

> 未起票のまま残っていた項目。aicue:T154 の設計が「**撮影 PWA からの戻り導線は含まない
> (別 TODO)**」と明示的に先送りし、`docs/architecture.md` §完成レンダ成果物の選択と受け取り口の
> 「保証しないもの」にも同文が残っている。本設計はその先送り分を扱う。

## 背景・課題

### 実コードで確認した事実 (2026-08-11 実査)

PC 側マニュアル詳細 → 撮影 PWA の**往路は存在する**。

`resources/js/pages/Manuals/Show.svelte:79-89`:

```svelte
{#if captureNavigable}
    <!-- canManage 内外を問わず表示 (撮影者=project_member も撮影ナビ view 可) -->
    <Button variant="primary" href={`/app/projects/${project.id}/manuals/${manual.id}`} inertia
        testId="capture-manual-link">
        <Camera class="size-4" aria-hidden="true" />
        この手順書を撮影する
    </Button>
{/if}
```

`Manuals/Edit.svelte:67` にも同じ往路がある。

**復路が無い。** `resources/js/pages/Capture/Show.svelte:228-231` のヘッダーにあるリンクは 1 本だけで、
行き先は**撮影 PWA 自身の一覧** (`/app/projects/{project}/manuals` = `capture.manuals.index`) である:

```svelte
<TextLink href={`/app/projects/${project.id}/manuals`}>
    <ArrowLeft class="inline size-3" aria-hidden="true" />
    一覧へ戻る
</TextLink>
```

PC 側マニュアル詳細 (`/projects/{project}/manuals/{manual}` = `projects.manuals.show`) への導線は
撮影 PWA のどの画面にも無い。`grep -rn "/app/projects" resources/js` と
`resources/js/pages/Capture/*.svelte` の全リンク走査で確認済み。

### 阻害されているユーザージョブ

**撮り終わった人がその手順書の状態へ戻る。** 撮影ナビで最後のカットを撮り終えた時点で、
その画面から行けるのは「別のマニュアルを選ぶ一覧」だけである。いま撮ったマニュアルの
プレビュー生成・完成動画・シナリオ・撮影の進み具合が集まっているのは PC 側詳細画面であり、
そこへは **URL を手で打ち替えるか、一覧 → PC 側へ自力で移動する**しかない。

aicue:T154 で「完成動画をアプリ内で観られる」ようにして受け取り口は開いたが、
**撮影を終えた地点からその受け取り口までの経路が繋がっていない**。
使命 (「思考ゼロ・編集ゼロ」) に対して、往路だけ舗装して復路を舗装していない状態である。

### なぜ「詰み」ではないが直す価値があるか

永久の詰みではない (ブラウザの戻る・一覧経由で到達はできる)。しかし:

- 撮影 PWA は **standalone 表示**を想定している (`public/manifest.webmanifest` の
  `"display": "standalone"`)。ホーム画面から起動した窓には**ブラウザの戻るボタンが無い**。
  戻る手段がアプリ内の導線に依存する度合いが、PC ブラウザより構造的に高い。
- 往路が存在する以上、利用者は「行けたのだから戻れる」と期待する。片道であることは
  実際に押してみるまで分からない。

## 改善アイデア

**撮影ナビ (`Capture/Show`) のヘッダーに、いま撮っているマニュアルの PC 側詳細画面への
リンクを 1 本追加する。** それだけを行う。

- 既存の「一覧へ戻る」は**残す**。撮影者が続けて別のマニュアルを撮る導線であり、
  行き先が違う (別ジョブ)。置き換えではなく併置する。
- サーバ側の変更は無い。route も props も増やさない
  (`project.id` / `manual.id` は `Capture/Show` の props に既にある)。

### 出し分けをしない — その根拠

**無条件に表示する** (認可・状態による出し分けを行わない)。根拠は
**遷移先の到達条件が現在地とまったく同じ**であることを実コードで確認したためである:

| | 撮影ナビ `capture.manuals.show` | PC 詳細 `projects.manuals.show` |
|---|---|---|
| 外側 group | `['auth','verified','not-pending-deletion']` (routes/web.php:189) | 同左 (同一 group 内) |
| 内側 group | `['require-active-subscription','project.in-route-org']` (:593) | `['require-active-subscription','project.in-route-org']` (:453) |
| テナント境界 | `resolveOrganizationProject()` = 認可より前に 404 | 同左 |
| 認可 | `Gate::authorize('view', $manual)` | `Gate::authorize('view', $manual)` |

`VideoManualPolicy::view()` は `ProjectPolicy::view()` へ委譲し、docblock どおり
**撮影者 (project_member) も可**である。よって「撮影ナビを開けている利用者は、必ず PC 詳細も開ける」。
**403 の行き止まりは構造的に生じない**ため、出し分ける理由が無い (禁止事項 8 の精神とも一致する)。

この主張は人間の実読 1 回に依存させない。**撮影者 (project_member) が両画面を 200 で開ける**ことを
Feature テストで固定する (下記「実装方針」)。片方だけを検査しても「同じ条件である」ことは言えないため、
1 本のテストで両方を通す。

**復路専用の状態述語も新設しない。** 表示条件を持たないことがこの設計の要点であり、
「`isCaptureNavigable` を流用しない」の代わりに別の述語を作れば、それが次の二重管理になる。
認可はサーバに任せ、UI は無条件のリンクに留める。

### `isCaptureNavigable` を復路に流用しない

往路 (`Manuals/Show` / `Manuals/Edit`) は `isCaptureNavigable(manual.status)` で出し分けており、
`rendering` では往路リンクが消える (`resources/js/types/manual.ts:48`)。
**これを復路の条件に流用しない** (思考原則 4「別物の概念を似ているからで統合しない」)。

- 往路の述語は「**いま撮影を始めてよい相か**」である。合成中 (`rendering`) に新しいテイクを
  撮らせない、という業務判断が入っている。
- 復路の述語は「**元の画面へ戻れるか**」であり、状態に依存しない。むしろ `rendering` 中は
  「進み具合を見に戻りたい」場面そのものである。ここで消すのは誤りになる。

同じ関数を使い回すと、往路の業務判断を変えた日に復路が巻き添えで壊れる。共有しない。

## 期待効果

- **使命への貢献**: 「撮った人が結果に到達する」の最後の 1 区間が繋がる。aicue:T154 が
  受け取り口 (完成動画の再生) を開けたのに対し、本件はそこへ**歩いて行ける道**を作る。
- **ブラウザの戻るボタンに依存せず**、アプリ内の導線だけで元の文脈へ戻れる
  (standalone 窓に戻るボタンが無いことが効いてくる場面で特に意味を持つ)。
- 往路と復路が対になり、「行けたのに戻れない」という驚きが消える。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `resources/js/pages/Capture/Show.svelte` | ヘッダーに詳細画面へのリンクを 1 本追加 (既存 `TextLink` atom + Lucide アイコン) |
| `tests/js/pages/CaptureShow.test.ts` | リンクの href / ラベル / testid を固定 |
| Feature テスト (新規) | **撮影者 (project_member) が `capture.manuals.show` と `projects.manuals.show` の両方を 200 で開ける**ことを 1 本で固定 = 「到達条件が同じ」という設計主張の機械保証 |

- 文言は行き先をそのまま言う (aicue:T148 の「告知文は述語の意味をそのまま言う」に倣う)。
  「マニュアル詳細へ」を基本案とする (PC 側画面の呼称。`Manuals/Show` の見出しは manual.title、
  パンくずは `プロジェクト名 > マニュアル名`)。
- アイコンは Lucide のみ (`AGENTS.md` 実装規約)。PC 詳細画面のヘッダーが `BookOpen` を使っているため
  同じ記号で行き先を示す。SVG 直書きはしない。
- DS token 経由のみ。`TextLink` atom をそのまま使い、色・下線の hex 直書きをしない (DESIGN.md)。
- component 階層は変えない (pages 内で既存 atom / molecule を使うだけ)。

## 制約・前提

- **Service Worker は無関係**: `public/capture-sw.js` は `/build/*` の GET しかキャッシュせず、
  navigation は `respondWith` せず素通しする。遷移経路に SW は介在しない。
- **アップロードキューの性質は変えない**: 保留アップロードは IndexedDB に残り、撮影画面へ戻った
  ときに `visibilitychange` / `online` / SW message で再開する。撮影画面を離れている間は
  再送が進まないが、**これは既存の「一覧へ戻る」とまったく同じ性質**であり、本変更は新しい
  種類の危険を作らない。よって離脱警告は足さない (思考原則 2「今必要なものだけ作る」)。

## 保証しないもの（誇張しない）

- **インストール済み PWA (standalone) で同一窓に留まることは保証しない。**
  `public/manifest.webmanifest` に `scope` 宣言が無いことは実読で確定しているが、
  「既定 scope が `start_url` (`/app`) から最後のパス要素を除いた `/` になる」のは
  **仕様の読みであって実機の観測ではない**。ブラウザによってはアプリ内ブラウザで開く可能性が残る。
  本設計はこれを効果の前提に置かない (リンクが押せて詳細へ着けば目的は果たされる)。
  実機での見え方は、必要になった時点で受入確認の項目として扱う。
- **「撮り終わった瞬間に気づける」ことは保証しない。** 本件はヘッダーに常設のリンクを置くだけで、
  完了検知も促しも行わない (下記スコープ外)。撮影完了地点の近くにも導線が要るかは
  実利用の観察に委ねる後続論点である。
- **撮影者が完成動画を観られるようにはならない。** 詳細画面まで到達できるだけで、
  そこに何が出るかは既存の認可 (aicue:T154) が決める。

## スコープ外（今回やらないこと）

- **認可の変更**: 撮影者 (project_member) が完成動画を観られない点は aicue:T154 の据え置きのままで、
  本変更は 1 mm も緩めない。復路が届くのは「詳細画面」までで、そこに何が表示されるかは
  既存の props 出し分け (`finishedJob` = published + download ability + 現行世代) が決める。
- **`Capture/Index` からの戻り導線**: 別画面・別ジョブ (「撮影をやめて管理画面へ」)。
  今回の欠落申告 (aicue:T154) は `Capture/Show` についてのものであり、広げない。
- **離脱時の未送信警告 / beforeunload**: 上記のとおり既存導線と同性質のため作らない。
- **撮影完了の検知と自動遷移**: 「全カット採用済みになったら詳細へ促す」は状態判定を伴う別施策。
  本件は導線の欠落を埋めるだけに留める。
- **PC 側 `Manuals/Show` の変更**: 往路は既に在り、変更しない。
