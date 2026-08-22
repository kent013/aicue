【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
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

【この設計に固有の、特に厳しく見てほしい点】
- 「遷移先の到達条件が現在地とまったく同じなので出し分け不要」という主張は正しいか。
  見落としている middleware / policy / 状態の差は無いか（リポジトリを読んで検証してよい）。
- `isCaptureNavigable` を復路に流用しない、という判断は妥当か。それとも過剰な区別か。
- スコープが過小ではないか（本当にリンク 1 本で「復路が繋がった」と言えるか）。
- 逆に、警告や自動遷移まで作るのは過剰か。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（リポジトリ `/workspace` の `devnotes/20260811-2334-capture-return-path/conceptual-design.md` と同一内容。
関連する実コードは同リポジトリの `resources/js/pages/Capture/Show.svelte` /
`resources/js/pages/Manuals/Show.svelte` / `routes/web.php` / `app/Policies/VideoManualPolicy.php` /
`app/Http/Controllers/Capture/CaptureManualController.php` /
`app/Http/Controllers/Projects/VideoManualController.php` を読んで検証してよい。）

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
- 撮影 PWA を standalone で使う現場作業者が、ブラウザ chrome 無しでも自力で元の文脈へ戻れる。
- 往路と復路が対になり、「行けたのに戻れない」という驚きが消える。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `resources/js/pages/Capture/Show.svelte` | ヘッダーに詳細画面へのリンクを 1 本追加 (既存 `TextLink` atom + Lucide アイコン) |
| `tests/js/pages/CaptureShow.test.ts` | リンクの href / ラベル / testid を固定 |
| Feature テスト (新規または既存へ追記) | **撮影者 (project_member) が遷移先を 200 で開ける**ことを固定 = リンク先が詰みでないことの機械保証 |

- 文言は行き先をそのまま言う (aicue:T148 の「告知文は述語の意味をそのまま言う」に倣う)。
  「マニュアル詳細へ」を基本案とする (PC 側画面の呼称。`Manuals/Show` の見出しは manual.title、
  パンくずは `プロジェクト名 > マニュアル名`)。
- アイコンは Lucide のみ (`AGENTS.md` 実装規約)。PC 詳細画面のヘッダーが `BookOpen` を使っているため
  同じ記号で行き先を示す。SVG 直書きはしない。
- DS token 経由のみ。`TextLink` atom をそのまま使い、色・下線の hex 直書きをしない (DESIGN.md)。
- component 階層は変えない (pages 内で既存 atom / molecule を使うだけ)。

## 制約・前提

- **standalone 窓から外へ飛ばさない**: `public/manifest.webmanifest` に `scope` の宣言が無く、
  仕様上の既定 scope は `start_url` (`/app`) から最後のパス要素を除いた **`/`** になる。
  よって `/projects/...` は scope 内で、インストール済み PWA から遷移しても同一窓に留まる
  **はず**である。**これは仕様と manifest の読みから導いた前提であり、実機で確認していない**
  (「保証しないもの」に再掲する)。
- **Service Worker は無関係**: `public/capture-sw.js` は `/build/*` の GET しかキャッシュせず、
  navigation は `respondWith` せず素通しする。遷移経路に SW は介在しない。
- **アップロードキューの性質は変えない**: 保留アップロードは IndexedDB に残り、撮影画面へ戻った
  ときに `visibilitychange` / `online` / SW message で再開する。撮影画面を離れている間は
  再送が進まないが、**これは既存の「一覧へ戻る」とまったく同じ性質**であり、本変更は新しい
  種類の危険を作らない。よって離脱警告は足さない (思考原則 2「今必要なものだけ作る」)。

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
