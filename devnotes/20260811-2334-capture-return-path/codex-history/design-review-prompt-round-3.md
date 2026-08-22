# Round 3: Round 2 の必須 3 点への対応と再レビュー依頼

Round 2 の必須 3 点 (「認可に依らず」の言い換え / 主張を片方向の含意へ狭める /
mutation D を svg 属性 assert へ) と Suggestion 1 点、および PHP enum 同期の指摘を
すべて対応しました。**反論はありません。**

再レビューの観点:
- 3 点の修正が指摘どおりになっているか (言い換えが別の誤読を生んでいないか)
- 「保証しないもの」の列挙に、まだ誇張や漏れが残っていないか
- 実装着手可能か (APPROVED にできるか)

---

# 対応マトリクス: design-review Round 2

全体判定 CHANGES_REQUESTED。必須 3 点 + Suggestion 1 点。すべて**対応**した(反論なし)。

## [Warning] (施策 1/4) 「認可にも依らず出し分けない」は認可を無視して表示するようにも読める

- 判断: **対応する**
- 根拠: 指摘のとおり。実際には `Capture/Show` へ到達する時点で
  `Gate::authorize('view', $manual)` が成立している。正確な契約は
  「**`Capture/Show` へ到達済みの利用者に対して、追加の status / ability 条件を設けない**」であり、
  「認可に依らない」と書くと**認可を素通しする実装**に読める。セキュリティ面の文としてまずい。
- 対応内容: Svelte コメント・`docs/architecture.md` 追記・施策 1 の見出し文をすべて
  「到達済みの利用者に対し、追加の status / ability 条件を設けず常に出す」へ改めた。

## [Warning] (施策 3/4) Feature テストは「到達条件の同一性」を証明しない

- 判断: **対応する**
- 根拠: 妥当。テストが言えるのは「用意した principal と Factory 既定データについて、
  全 status で両画面に到達できた」までで、PC 側だけに新しい属性由来の制限が入っても
  Factory 既定値が引っかからなければ緑のままになる。「同一性を機械保証」は誇張である。
  また本 TODO に必要なのは同値ではなく**片方向の含意**
  (`capture.manuals.show` 到達可 ⇒ `projects.manuals.show` 到達可) だけである。
- 対応内容:
  - 施策一覧の施策 3 名を「最弱 principal に対する復路到達契約の固定」へ変更。
  - 「到達条件が同じとは何と何が同じか」節を「**何を主張するのか (同一性ではなく片方向の含意)**」へ
    書き換え、層の対応表は**設計根拠であってテストの証明対象ではない**と明記。
  - テスト名を「最弱 principal (撮影者) は…」へ変更し、docblock に「何を証明しないか」を追記。
  - 「保証しないもの」に、構造の同一性を不変条件にするなら Architecture テストが別途要ること、
    ただし**本 TODO では作らない**(リンク 1 本に対して過大 = 思考原則 2)ことを明記。
  - `docs/architecture.md` 追記も同じ言い方に揃えた。

## [Warning] (施策 2) mutation D は現在のテストでは赤くならない

- 判断: **対応する**
- 根拠: そのとおり。Lucide の `svg` は `title` を持たないため、`aria-hidden` を外しても
  リンクの accessible name は「マニュアル詳細へ」のままで、名前の検査では検出できない。
  部分一致の正規表現だったことも弱かった。
- 対応内容:
  - 名前の取得を `{ name: "マニュアル詳細へ", exact: true }` に変更(名前が汚れたら落ちる)。
  - **アイコンが名前を汚さないこと**は別契約として
    `expect(link.querySelector("svg")).toHaveAttribute("aria-hidden", "true")` で見る。
  - mutation D の記述を「**1 本目の `svg` 属性 assert だけ**が落ちる。accessible name は
    変わらないので名前の検査では検出できない」へ書き換えた。

## [Suggestion] (施策 2) `compareDocumentPosition` はビット契約を明示せよ

- 判断: **対応する**
- 対応内容: `toBeTruthy()` → `.toBe(Node.DOCUMENT_POSITION_FOLLOWING)` に変更。

## [Warning 相当の指摘] PHP enum とフロント union の同期は別途保証が要る

- 判断: **対応する**(記録のみ。新しい gate は作らない)
- 根拠: 現状 `resources/js/types/manual.ts` の docblock が「乖離検知は当面手動確認」と
  宣言済みで、これは本 TODO 以前からある既知の穴である。ここで同期 gate を新設するのは
  スコープ外(思考原則 2)。
- 対応内容: 「保証しないもの」に**ドリフトを検出しない**ことを明記した。

## [その他] mutation F の最小化

- 判断: **対応する**
- 対応内容: 「別 Inertia 画面へ差し替える」を
  「`Inertia::render('Manuals/Show', …)` を `'Manuals/Edit'` へ 1 語だけ変える」へ具体化した。


---

## 修正後の詳細設計書 (全文)

# 詳細設計: capture-return-path (撮影 PWA からマニュアル詳細への戻り導線)

## 使命・制約(絶対遵守)

### アプリの使命(North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest**(`composer test`)。**RefreshDatabase** はグローバル適用(個別 `DatabaseTransactions` 禁止)
- **テストデータは必ず Factory で生成**
- **DTO + JsonResource** パターン / **アーリーリターン** 推奨
- フロントは Svelte 5 runes + DS token/ramp のみ(`DESIGN.md` が canonical、ds-purity テストが検出)
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の
  単方向 import のみ。アイコンは `@lucide/svelte` のみ(SVG 内包は `components/atoms/icons/` に限る)
- `composer fix`(Pint)/ `pnpm lint:fix`
- PHP 8.4 + Laravel 13.18 + Svelte 5 + Inertia.js + TypeScript + PostgreSQL 単一

## 概念設計リファレンス

- `devnotes/20260811-2334-capture-return-path/conceptual-design.md`(Round 1 APPROVED)
- 合議履歴: `devnotes/20260811-2334-capture-return-path/conceptual-review-round-1.md` /
  `codex-history/conceptual-review-decisions-round-1.md`

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `Capture/Show` ヘッダーに詳細画面への無条件リンクを 1 本追加 | `resources/js/pages/Capture/Show.svelte` | High |
| 2 | DOM 契約の固定(href / ラベル / **status に依存しないこと**) | `tests/js/pages/CaptureShow.test.ts` | High |
| 3 | 最弱 principal に対する復路到達契約の固定 | `tests/Feature/Capture/CaptureReturnPathTest.php`(新規) | High |
| 4 | 文書同期(導線契約の記載 / T154 の「含まない」の解消) | `docs/architecture.md` | Medium |

**アプリ実装コード (route / controller / DTO / policy / Service) の変更は 0 行**。
PHP 側に増えるのは施策 3 の Feature テスト 1 ファイルのみである。
**新規モデルなし** = Factory 追加は不要。**インターフェース変更なし** =
TypeScript 型定義 / Inertia Props / JsonResource への波及なし。

### 何を主張するのか (「同一性」ではなく「片方向の含意」)

主張は集合の同値ではなく、**片方向の含意**である:

> `capture.manuals.show` に到達できた ⇒ `projects.manuals.show` にも到達できる

これで足りる。復路リンクが詰みにならないために要るのは、この向きだけだからである
(逆向き = PC 詳細に来られる人が撮影ナビにも来られるか、は本 TODO の関心事ではない)。

含意の**根拠**は、両 route が以下の**すべて**を同じ順序で通ることである。設計・テスト・文書では
この列挙を省略形 (「middleware 2 本」等) に丸めない — 省略すると、次に読む人が抜けた層を見落とす。

| 層 | `capture.manuals.show` | `projects.manuals.show` |
|---|---|---|
| 外側 group | `auth` / `verified` / `not-pending-deletion` (routes/web.php:189) | 同左 (同一 group 内) |
| 内側 group | `require-active-subscription` / `project.in-route-org` (:593) | 同左 (:453) |
| 親子整合 | `Route::scopeBindings()` = `$project->manuals()` 経由 | 同左 |
| テナント境界 | controller の `resolveOrganizationProject()` (**認可より前に 404**) | 同左 |
| 認可 | `Gate::authorize('view', $manual)` | `Gate::authorize('view', $manual)` |
| status による絞り込み | **無し** (一覧 `index` にはあるが詳細には無い) | **無し** |

**ただしこの表は設計根拠であって、テストが証明するものではない。** 施策 3 の Feature テストが
固定するのは「**現在サポートする最弱 principal (project_member) について、全 status で
両画面へ到達できる**」までである (構造そのものの同一性は Architecture テストの領分であり、
本 TODO では作らない。理由は「保証しないもの」に書く)。

---

## 施策 1: `Capture/Show` ヘッダーに詳細画面への無条件リンクを追加

### 変更箇所

- ファイル: `resources/js/pages/Capture/Show.svelte`(import 行 L3 付近、ヘッダー L228-232)

### 波及変更

- TypeScript 型定義: **なし**(`project.id` / `manual.id` は既存 Props にある)
- API Resource/DTO: **なし**(サーバ応答を変えない)
- テストファイル: 施策 2(Vitest)・施策 3(Feature)

### 現行コード

```svelte
    import { ArrowLeft, Video } from "@lucide/svelte";
```

```svelte
        <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
            <TextLink href={`/app/projects/${project.id}/manuals`}>
                <ArrowLeft class="inline size-3" aria-hidden="true" />
                一覧へ戻る
            </TextLink>
        </PageHeaderSection>
```

### 変更後コード

```svelte
    import { ArrowLeft, BookOpen, Video } from "@lucide/svelte";
```

```svelte
        <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
            <TextLink href={`/app/projects/${project.id}/manuals`}>
                <ArrowLeft class="inline size-3" aria-hidden="true" />
                一覧へ戻る
            </TextLink>
            <!-- PC 側詳細への復路。**この画面へ到達できた利用者に対しては、追加の
                 status / ability 条件で出し分けない**。根拠は
                 docs/architecture.md §撮影 PWA の運用契約。 -->
            <TextLink
                href={`/projects/${project.id}/manuals/${manual.id}`}
                testId="manual-detail-link"
            >
                <BookOpen class="inline size-3" aria-hidden="true" />
                マニュアル詳細へ
            </TextLink>
        </PageHeaderSection>
```

### 設計判断

- **DOM 順は既存を先、新規を後**にする。`PageHeaderSection` の children は
  `flex flex-wrap justify-end gap-2` に並ぶため、追加分が右端に来る。既存要素の順序を動かさない
  (既存の見え方・タブ順を変えない)。
- **文言に「一覧」「戻る」を使わない**。本画面には既に 2 つの "戻る" が存在する —
  ヘッダーの「一覧へ戻る」(撮影 PWA の一覧へ) と、1 カラム時の右ペイン内「カット一覧へ戻る」
  (ページ内パネル移動、`panel-navigation.ts`)。3 つ目を "戻る" 語で足すと区別できなくなるため、
  **行き先をそのまま言う**「マニュアル詳細へ」にする(aicue:T148 の告知文の原則と同じ)。
- **アイコンは `BookOpen`**。PC 側詳細画面 `Manuals/Show` のヘッダーアイコンと同じ記号で
  行き先を示す。`aria-hidden="true"` はテキストが行き先を語っているため(既存 `ArrowLeft` と同形)。
- **`TextLink` atom をそのまま使う**。内部リンクモードは Inertia `Link` を描画するため
  SPA 遷移になる(既存「一覧へ戻る」と同じ機構)。色・下線は atom 側の DS 準拠クラスのみで、
  hex 直書きを増やさない。
- **Props も route も増やさない**。href はテンプレート文字列で組み立てる(既存 `Capture/Show`・
  `Manuals/Show` 双方の既存流儀。ziggy 等の route helper はこのリポジトリで使っていない)。

### テスト計画

施策 2 で固定する。

### リスク

- **保留アップロード中に離脱すると再送が止まる**。ただしこれは既存の「一覧へ戻る」と
  **完全に同じ性質**であり(どちらも Inertia 遷移で `Capture/Show` が unmount される)、
  本変更が新しい種類の危険を作るわけではない。保留は IndexedDB に残り、撮影画面へ戻れば
  `visibilitychange` / `online` / SW message で再開する。よって離脱警告は足さない(思考原則 2)。
- **ヘッダーの要素が 2 つになり横幅を食う**。`PageHeaderSection` の children 側は
  `flex-wrap` + `min-w-0 shrink` を持ち、タイトル側も `truncate` するため、狭い画面では
  折り返しで吸収される**設計**である。ただし**これは実測ではない** — jsdom (Vitest) は
  flex-wrap も truncate も実 overflow も計算せず、既存の「レイアウト overflow ガード」テストが
  見ているのも**クラス名の存在だけ**である。狭幅ヘッダーの実レイアウトは本 TODO の保証外とする
  (「保証しないもの」に再掲)。

---

## 施策 2: DOM 契約の固定(Vitest)

### 変更箇所

- ファイル: `tests/js/pages/CaptureShow.test.ts`(末尾に describe を 1 つ追加)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし

### 変更後コード(追加分)

```ts
// status の全数は「型で全数が保証されている写像」のキーから採る。
// VIDEO_MANUAL_STATUS_LABELS は Record<VideoManualStatus, string> なので、status が増えたら
// 写像側がコンパイルエラーになり、直すと本 dataset も自動で増える (二重管理をつくらない)。
const ALL_STATUSES = Object.keys(VIDEO_MANUAL_STATUS_LABELS) as VideoManualStatus[];

describe("Capture/Show マニュアル詳細への復路 (capture-return-path)", () => {
    it("ヘッダーに PC 側マニュアル詳細へのリンクを出す", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        // 利用者が認識する名前 (accessible name) で取る。exact 指定なので、
        // 名前に余計な文字列が混ざった場合もここで落ちる。
        const link = screen.getByRole("link", { name: "マニュアル詳細へ", exact: true });
        expect(link).toHaveAttribute("href", "/projects/1/manuals/5");
        // アイコンが名前を汚さないことは**別契約**として明示的に見る
        // (Lucide の svg は title を持たないので、aria-hidden を外しても名前は変わらない =
        //  名前の検査だけでは aria-hidden の消失を検出できないため)
        expect(link.querySelector("svg")).toHaveAttribute("aria-hidden", "true");
    });

    it("既存の「一覧へ戻る」(撮影 PWA 一覧) は置き換えず、その後ろに併置する", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        const back = screen.getByRole("link", { name: "一覧へ戻る", exact: true });
        const detail = screen.getByRole("link", { name: "マニュアル詳細へ", exact: true });

        expect(back).toHaveAttribute("href", "/app/projects/1/manuals");
        // DOM 順 = タブ順。既存要素を動かさないことを固定する
        expect(back.compareDocumentPosition(detail) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
    });

    it.each(ALL_STATUSES)(
        "status=%s でも復路は消えない (往路の isCaptureNavigable を流用していないこと)",
        (status) => {
            stubCameraSupported(false);
            render(CaptureShow, {
                props: { ...baseProps, manual: { ...makeManual(), status } },
            });

            expect(screen.getByRole("link", { name: "マニュアル詳細へ", exact: true })).toBeTruthy();
        },
    );
});
```

**3 本目が本命**である。`rendering` を含む全 status で復路が残ることを固定するので、
後日だれかが `{#if isCaptureNavigable(manual.status)}` で包むと**必ず赤くなる**
(概念設計で否定した実装が、機械的に禁止される)。

import は `@/types/manual` から `VIDEO_MANUAL_STATUS_LABELS` / `VideoManualStatus` を足す
(`CaptureManualDetail.status` は `string` 型なので、props へ渡すときの型エラーは起きない)。

### テスト計画(fail 先行の確認手順)

1. 施策 2 のテストだけを先に置いて `pnpm test` を走らせ、**全ケースが落ちる**ことを確認する
   (1・3 本目は「マニュアル詳細へ」が無いため、2 本目も同じ理由で落ちる)。
2. 施策 1 を実装して緑にする。
3. mutation A: 施策 1 のリンクを `{#if isCaptureNavigable(manual.status)}` で包み、
   3 本目の `draft` / `analyzing` / `rendering` ケースだけが落ちることを実測する。
4. mutation B: href を `/app/projects/...`(撮影 PWA 側)に戻し、1 本目が落ちることを実測する。
5. mutation C: 新リンクを既存リンクの**前**へ移し、2 本目だけが落ちることを実測する。
6. mutation D: アイコンの `aria-hidden="true"` を外し、**1 本目の `svg` の属性 assert だけ**が
   落ちることを実測する。**accessible name は変わらない** (Lucide の `svg` は `title` を持たない)
   ため、名前の検査では検出できない — だから属性を別契約として見ている。

### リスク

- `screen.getByRole("link", …)` は `TextLink` の内部リンクモードが `<a>` を描画することに依存する。
  現行 `TextLink` は Inertia `Link` を使い `<a>` を描画するため成立する。ボタンモード
  (href 無し)に変わると落ちるが、それは意図した検出である。

---

## 施策 3: 「到達条件が同じ」の機械保証(Feature テスト)

### 変更箇所

- ファイル: `tests/Feature/Capture/CaptureReturnPathTest.php`(新規)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし / アプリコード: なし(テストのみ)

### なぜ要るか

概念設計は「**撮影ナビを開けている利用者は必ず PC 詳細も開ける**(だから出し分けない)」という
含意の上に立っている。根拠は現状**人間の実読 1 回**しかなく、将来
`projects.manuals.show` 側に middleware や認可が 1 本足された瞬間に、**UI は黙って
403 への導線に化ける**。片方だけ検査しても含意は言えないので、**1 本のテストで両方を叩く**。

**このテストが証明する範囲を誇張しない。** 固定できるのは
「**現在サポートする最弱 principal (project_member) と Factory 既定のデータについて、
全 status で両画面へ到達できる**」までである。将来 PC 側だけに organization 設定や
ユーザー属性を使った制限が入り、Factory 既定値がその制限に掛からなければ、このテストは
緑のままになる。**構造そのものの同一性は証明しない**。

さらに **200 だけでは足りない**。200 を返す別画面(案内ページ等)へ逃がす実装に置き換わっても
緑になってしまうため、**着地した Inertia component まで assert する**。

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\VideoManual;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 撮影 PWA → PC 側マニュアル詳細の復路 (capture-return-path)。
 *
 * 固定する契約 (片方向の含意):
 * - 撮影ナビ (capture.manuals.show) を開ける利用者は、復路の行き先
 *   (projects.manuals.show) も開ける = UI に出す無条件リンクが 403 にならない。
 * - もっとも弱い principal である**撮影者 (project_member)** で確認する
 *   (編集者で通っても撮影者で通る保証にならないため)。
 * - 片側だけの検査では「到達条件が同じ」を言えないので、1 本で両方を叩く。
 * - **status に依らない**。復路リンクは status で出し分けないため、全 status で固定する
 *   (往路の isCaptureNavigable は rendering で消えるが、それは別の述語である)。
 * - 200 だけでなく**着地した画面 (Inertia component)** まで見る。200 を返す別画面へ
 *   逃がす実装に置き換わったとき、200 だけの検査は沈黙するため。
 *
 * 何を証明しないか: 構造そのものの同一性は証明しない。固定できるのは、下記の principal と
 * Factory 既定データについての到達可否だけである (設計根拠としての層の対応は下記)。
 *
 * 両 route が同じく通る層 (省略形で書かない):
 *   auth / verified / not-pending-deletion (外側 group)
 *   → require-active-subscription / project.in-route-org (内側 group)
 *   → Route::scopeBindings() ($project->manuals() 経由)
 *   → controller の resolveOrganizationProject() (認可より前に 404)
 *   → Gate::authorize('view', $manual)
 * 詳細 GET はどちらも status による絞り込みを持たない (一覧 index だけが持つ)。
 */

test('最弱 principal (撮影者) は全 status で撮影ナビと PC 側マニュアル詳細の両方へ到達できる', function (VideoManualStatus $status): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => $status->value]);

    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);

    // 現在地 (復路リンクを描画する画面)
    $this->actingAs($member)
        ->get("/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Capture/Show'));

    // 復路の行き先。ここが 403/404/別画面になるなら施策 1 の無条件リンクは詰みの導線になる
    $this->actingAs($member)
        ->get("/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Manuals/Show'));
})->with(VideoManualStatus::cases());
```

**Factory 経由**でデータを作り、`Model::create()` 手組みをしない。
ヘルパー(`createOrganizationWithOwner` / `attachOrganizationMember` / `attachProjectMember`)は
`tests/Feature/Manual/FinishedVideoPlaybackTest.php` の先例と同じものを使う。
dataset は `VideoManualStatus::cases()` なので、**PHP enum に status が増えれば自動で増える**
(施策 2 の Vitest 側も型で全数が保証された写像のキーから採る = 両側とも二重管理にならない)。

### PHPStan 適合チェック

- [x] テストのみ。戻り値型は `void` を明示
- [x] `Model::create()` 手組みなし(Factory 経由)
- [x] 個別の `DatabaseTransactions` を使わない(グローバル `RefreshDatabase`)

### テスト計画(fail 先行の確認手順)

- このテストは**現行コードでも緑**である(アプリ実装を変えないため)。よって「fail 先行」は
  施策 2 が担う。本テストの意義は**将来の退行検出**であり、それを実測で示すために
  mutation を 2 つ行う:
  - mutation E: `VideoManualController::show()` の `Gate::authorize('view', $manual)` を
    `'update'` に変えると、**PC 側 assert が全 status で落ちる**(撮影者が弾かれる)。
  - mutation F: `VideoManualController::show()` の `Inertia::render('Manuals/Show', …)` を
    `Inertia::render('Manuals/Edit', …)` へ 1 語だけ変えると、`assertOk()` は通るが
    **`component('Manuals/Show')` が落ちる** (= Critical 指摘への対応が実際に効いていることの実測。
    最小 mutation にして再現性を上げる)。
  いずれも確認後に元へ戻す。

### リスク

- テスト用の principal 生成ヘルパーの仕様変更に追従が要る(既存の先例と同じものを使うため、
  波及したときは他テストと同時に落ちる = 気づける)。

---

## 施策 4: 文書同期

### 変更箇所

- `docs/architecture.md` §撮影 PWA (presigned アップロード + 容量 Quota) の運用契約 — 導線契約を追記
- `docs/architecture.md` §完成レンダ成果物の選択と受け取り口 (T154) 「保証しないもの」 L1876 — 解消

### 変更後(追記分)

§撮影 PWA の運用契約に以下を追加:

```markdown
- **PC 面との導線は往復で 1 対**: 往路は `Manuals/Show` / `Manuals/Edit` の
  「この手順書を撮影する」(`isCaptureNavigable` = ready / published のときだけ出す)、
  復路は `Capture/Show` ヘッダーの「マニュアル詳細へ」(**`Capture/Show` へ到達済みの利用者に対し、
  追加の status / ability 条件を設けず常に出す**)。
  **2 つの述語を共有しない**: 往路は「いま撮影を始めてよい相か」、復路は「元の画面へ戻れるか」で
  意味が違い、合成中 (`rendering`) こそ進み具合を見に戻る場面である。復路専用の述語も作らない。
  復路を無条件にできる根拠は、行き先 `projects.manuals.show` が `capture.manuals.show` と
  **同じ層を同じ順序で通る**ことである — 外側 group の `auth` / `verified` /
  `not-pending-deletion`、内側 group の `require-active-subscription` / `project.in-route-org`、
  `Route::scopeBindings()`、controller の `resolveOrganizationProject()` (認可より前に 404)、
  `Gate::authorize('view', $manual)`。詳細 GET はどちらも status で絞り込まない (一覧だけが絞る)。
  よって復路が 403 になる経路が見当たらない。**ただしテストが固定するのはこの構造的同一性ではなく、
  現在サポートする最弱 principal である撮影者 (project_member) について
  全 status で両 route の 200 + 着地 component が成立すること**である
  (`CaptureReturnPathTest`)。
  **保証しないもの**: インストール済み PWA (standalone) で同一窓に留まることは保証しない
  (`public/manifest.webmanifest` に `scope` 宣言が無く、既定 scope が `/` になるという
  仕様の読みに基づくだけで実機観測がない)。狭幅ヘッダーの実レイアウト (折り返し・truncate) も
  保証しない (Vitest は jsdom でクラス名しか見ない。Browser lane は追加していない)。
  撮影完了の検知・自動遷移も行わない (ヘッダーの常設リンクのみ)。
  撮影者が完成動画を観られるようにもならない (認可は不変)。
```

L1876 は「含まない (別 TODO)」を消し、実装済みの事実と**残る非保証**へ置き換える:

```markdown
- **撮影 PWA からの戻り導線は `Capture/Show` ヘッダーの常設リンクとして実装済み**
  (§撮影 PWA の運用契約)。ただし**完成動画へ直接着地するわけではない** — 行き先はマニュアル
  詳細画面で、そこに完成動画が出るかは本節の認可条件がそのまま決める
  (撮影者には出ない)。
```

### リスク

- 文書だけが先に進むと嘘になるため、施策 1〜3 と**同一コミット**で入れる。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更は 1 ファイル 8 行 + テスト 2 本 + 文書。サーバ側 0 行。他施策と衝突しにくい |
| 競合リスク | `resources/js/pages/Capture/Show.svelte` は aicue:T141 が触った面と同一。T141 は完了済みだが、撮影 PWA 面を触る別 TODO が並走する場合は後に回す |

## 検証コマンド

`pnpm test`(Vitest)/ `composer test`(Pest)/ `pnpm lint` / `pnpm typecheck` /
`composer phpstan` / `vendor/bin/pint --test` / `pnpm build`。
**Browser lane は追加しない**(DOM 契約は Vitest、到達性は Feature テストで足りる。
Browser テストを足すなら Chromium + WebKit の 2 レーン契約に従う必要があり、
リンク 1 本に対して過大)。

## 保証しないもの(誇張しない)

- **施策 3 は「到達条件の構造的同一性」を証明しない**。証明するのは
  「現在サポートする最弱 principal (project_member) と Factory 既定データについて、
  全 status で両画面へ到達できる」という**片方向の含意の実測**までである。
  将来 PC 側だけに organization 設定・ユーザー属性由来の制限が入り、Factory 既定値が
  その制限に掛からなければテストは緑のままになる。構造の同一性まで不変条件にしたいなら
  route action / middleware / 認可方式を固定する Architecture テストが別途要る
  (**本 TODO では作らない** — リンク 1 本に対して過大であり、思考原則 2 に反する)。
- **フロントの `VideoManualStatus` union と PHP enum のドリフトは検出しない**。
  施策 2 の dataset はフロント内部では全数だが、両者の同期は現状
  「当面手動確認」(`resources/js/types/manual.ts` の docblock) であり、本 TODO はそれを変えない。
- **インストール済み PWA (standalone) で同一窓に留まることは保証しない**(概念設計と同文)。
- **「撮り終わった瞬間に気づける」ことは保証しない**。常設リンクであり完了検知も促しもしない。
- **撮影者が完成動画を観られるようにはならない**。復路が届くのは詳細画面までで、
  そこに何が出るかは aicue:T154 の認可がそのまま決める。
- **Vitest は DOM 契約だけを見る**。実際にタップして遷移が成立すること・iOS Safari の
  standalone 窓での見え方は確認していない。
- **狭幅ヘッダーの実レイアウトは保証しない**。jsdom は flex-wrap も truncate も
  実 overflow も計算せず、既存の「レイアウト overflow ガード」テストが見ているのも
  クラス名の存在だけである。Browser lane は追加していない。
- **施策 3 が固定するのは 2 route の到達可否だけ**である。PC 詳細画面の中身
  (どのパネルが出るか) は対象外で、既存テストの担当である。
