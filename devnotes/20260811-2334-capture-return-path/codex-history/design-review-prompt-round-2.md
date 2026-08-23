# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の [Critical] 1 件・[Warning] 6 件・[Suggestion] 2 件をすべて捌きました。
以下が対応マトリクスと、修正後の詳細設計書の全文です。

**1 点だけ反論しています** — 施策 2 の status dataset について、提案された
`as const satisfies readonly VideoManualStatus[]` は「各要素が妥当な status か」しか見ず、
status が増えたときにテストが自動追従しないため採りませんでした。代わりに
`Record<VideoManualStatus, …>` を `satisfies` で固定済みの既存写像のキーから採る形にしています。
この判断が妥当か確認してください。

再レビューの観点:
- Critical 指摘への対応 (Inertia component の assert) は十分か
- dataset を「型で全数保証された写像のキー」から採る判断は妥当か、それとも別の穴があるか
- mutation 計画 (A〜F) は、それぞれ本当にその 1 本だけを赤くできるか
- 新たに生じた問題は無いか

---

# 対応マトリクス: design-review Round 1

全体判定 CHANGES_REQUESTED。Critical 1 / Warning 6 / Suggestion 2。以下のとおり捌いた。

## [Critical] (施策 3) `assertOk()` だけでは「到達条件が同じ」を固定できない — Inertia component まで assert せよ

- 判断: **対応する**
- 根拠: 妥当。200 を返す別画面 (例: 何かの案内ページへ逃がす実装) に置き換わっても緑になる。
  復路が「行き先として成立している」ことを言うなら、着地した画面まで見る必要がある。
- 対応内容: 両 route の assert に `->assertInertia(fn (Assert $page) => $page->component('Capture/Show'))` /
  `->component('Manuals/Show')` を追加した。**リダイレクトも塞がる**
  (`assertOk` は 302 を弾くので既に塞がっているが、200 の別画面はこれで初めて塞がる)。

## [Warning] (施策 2) DOM 順を固定していない

- 判断: **対応する**
- 根拠: 設計文が「既存を先・新規を後」と判断しているのにテストが見ていない = 設計と検査の乖離。
- 対応内容: `compareDocumentPosition` で「一覧へ戻る」→「マニュアル詳細へ」の順を固定するケースを追加。

## [Warning] (施策 2) 1 本目はアクセシブルネームで取れ

- 判断: **対応する**
- 根拠: 文言を契約にするなら、利用者が認識する名前 (accessible name) で取るのが正しい。
  `getByTestId` + `textContent` はアイコンの `aria-hidden` が外れても気づけない。
- 対応内容: `screen.getByRole("link", { name: /マニュアル詳細へ/ })` + `toHaveAttribute("href", …)` に変更
  (`@testing-library/jest-dom/vitest` は `tests/js/setup.ts` で読み込み済みなので `toHaveAttribute` が使える)。
  `data-testid` 自体は残す (他のテストや bug-hunt からの参照手段として既存流儀に合わせる)。

## [Warning] (施策 2) status dataset の型が広がる / 網羅性が弱い

- 判断: **対応する** (ただし Codex の `as const satisfies readonly VideoManualStatus[]` 案は採らない)
- 根拠: `satisfies readonly VideoManualStatus[]` は「各要素が妥当な status か」しか見ず、
  **status が増えたときにテストが自動追従しない** (Codex の懸念そのものが残る)。
  このリポジトリには既に `Record<VideoManualStatus, …>` を `satisfies` で固定した写像が
  `resources/js/types/manual.ts` に 4 つあり、その**キー集合は型で全数が保証されている**。
- 対応内容: `Object.keys(VIDEO_MANUAL_STATUS_LABELS) as VideoManualStatus[]` を dataset にした。
  status が増えれば写像側がコンパイルエラーになり、修正すると dataset も自動で増える
  (二重管理をつくらない)。

## [Warning] (施策 3) `ready` / `rendering` の 2 状態では不足。全 status で固定せよ

- 判断: **対応する**
- 根拠: 「status に依らず常に出す」が設計主張なのだから、サーバ側の到達可否も全 status で見るのが対。
- 対応内容: `VideoManualStatus::cases()` を Pest の dataset にして、全 status で両 route の
  200 + component を固定する形に書き換えた。テストは 2 本 → 1 本 (dataset 化) になる。

## [Warning] (施策 3/4) 「同じ middleware 2 本」は不正確

- 判断: **対応する**
- 根拠: そのとおり。外側 group (`auth` / `verified` / `not-pending-deletion`)、内側 group
  (`require-active-subscription` / `project.in-route-org`)、`scopeBindings()`、
  controller の `resolveOrganizationProject()`、`Gate::authorize('view', $manual)` の合成である。
  セキュリティ不変条件に関わる説明を省略形で書くと、次に読む人が省略された層を見落とす。
- 対応内容: 詳細設計・テストコメント・`docs/architecture.md` 追記のすべてを具体名の列挙に置き換えた。

## [Warning] (施策 4/施策 1 リスク) Vitest にレイアウト保証を背負わせるな

- 判断: **対応する**
- 根拠: jsdom は flex-wrap も truncate も実際の overflow も計算しない。既存の
  「レイアウト overflow ガード」テストが見ているのはクラス名の存在であって実レイアウトではない。
- 対応内容: 施策 1 のリスク欄から「mobile 幅の overflow を再確認する」を削除し、
  **クラス名の存在しか見ていない / 実レイアウトは保証しない**と書き直した。
  「保証しないもの」にも狭幅ヘッダーの実レイアウトを明記。

## [Suggestion] (施策 1) Svelte 内コメントが重い

- 判断: **対応する**
- 根拠: 判断理由の正本は `docs/architecture.md` とテスト名に置くべきで、コンポーネントに
  設計文を複写すると乖離の種になる。
- 対応内容: コメントを 2 行に圧縮し、詳細は docs 参照へ委ねた。

## [Suggestion] 「サーバ側 0 行」は「アプリ実装コード 0 行」と書くべき

- 判断: **対応する**
- 対応内容: 施策一覧の脚注を「**アプリ実装コード (route / controller / DTO / policy / Service) の
  変更は 0 行**。PHP 側に増えるのは Feature テスト 1 ファイルのみ」に修正した。

## [Suggestion] 施策 1 は DESIGN / Atomic Design 的に問題なし / Browser lane 非追加は妥当

- 判断: **対応不要** (肯定的評価)


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
| 3 | 「到達条件が同じ」の機械保証(撮影者が両画面 200) | `tests/Feature/Capture/CaptureReturnPathTest.php`(新規) | High |
| 4 | 文書同期(導線契約の記載 / T154 の「含まない」の解消) | `docs/architecture.md` | Medium |

**アプリ実装コード (route / controller / DTO / policy / Service) の変更は 0 行**。
PHP 側に増えるのは施策 3 の Feature テスト 1 ファイルのみである。
**新規モデルなし** = Factory 追加は不要。**インターフェース変更なし** =
TypeScript 型定義 / Inertia Props / JsonResource への波及なし。

### 「到達条件が同じ」とは何と何が同じなのか (省略形で書かない)

以下の**すべて**を両 route が同じ順序で通る。設計・テスト・文書ではこの列挙を省略形
(「middleware 2 本」等) に丸めない — 省略すると、次に読む人が抜けた層を見落とす。

| 層 | `capture.manuals.show` | `projects.manuals.show` |
|---|---|---|
| 外側 group | `auth` / `verified` / `not-pending-deletion` (routes/web.php:189) | 同左 (同一 group 内) |
| 内側 group | `require-active-subscription` / `project.in-route-org` (:593) | 同左 (:453) |
| 親子整合 | `Route::scopeBindings()` = `$project->manuals()` 経由 | 同左 |
| テナント境界 | controller の `resolveOrganizationProject()` (**認可より前に 404**) | 同左 |
| 認可 | `Gate::authorize('view', $manual)` | `Gate::authorize('view', $manual)` |
| status による絞り込み | **無し** (一覧 `index` にはあるが詳細には無い) | **無し** |

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
            <!-- PC 側詳細への復路。**status でも認可でも出し分けない** (行き先の到達条件が
                 本画面と同一のため)。根拠は docs/architecture.md §撮影 PWA の運用契約。 -->
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

        // 利用者が認識する名前 (accessible name) で取る。アイコンの aria-hidden が
        // 外れて名前が汚れた場合もここで落ちる。
        const link = screen.getByRole("link", { name: /マニュアル詳細へ/ });
        expect(link).toHaveAttribute("href", "/projects/1/manuals/5");
    });

    it("既存の「一覧へ戻る」(撮影 PWA 一覧) は置き換えず、その後ろに併置する", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        const back = screen.getByRole("link", { name: /一覧へ戻る/ });
        const detail = screen.getByRole("link", { name: /マニュアル詳細へ/ });

        expect(back).toHaveAttribute("href", "/app/projects/1/manuals");
        // DOM 順 = タブ順。既存要素を動かさないことを固定する
        expect(
            back.compareDocumentPosition(detail) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it.each(ALL_STATUSES)(
        "status=%s でも復路は消えない (往路の isCaptureNavigable を流用していないこと)",
        (status) => {
            stubCameraSupported(false);
            render(CaptureShow, {
                props: { ...baseProps, manual: { ...makeManual(), status } },
            });

            expect(screen.getByRole("link", { name: /マニュアル詳細へ/ })).toBeTruthy();
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
6. mutation D: アイコンの `aria-hidden="true"` を外し、accessible name が汚れて
   `getByRole("link", { name: /マニュアル詳細へ/ })` が引き続き引けるか観測する
   (引けてしまうなら「名前で固定した」という主張を弱く書き直す。引けないなら固定できている)。

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
主張の上に立っている。この主張の根拠は現状**人間の実読 1 回**しかなく、将来
`projects.manuals.show` 側に middleware や認可が 1 本足された瞬間に、**UI は黙って
403 への導線に化ける**。片方だけ検査しても「同じ条件である」ことは言えないので、
**1 本のテストで両方を叩く**。

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
 * 固定する契約:
 * - 撮影ナビ (capture.manuals.show) を開ける利用者は、復路の行き先
 *   (projects.manuals.show) も**必ず**開ける = UI に出す無条件リンクが 403 にならない。
 * - もっとも弱い principal である**撮影者 (project_member)** で確認する
 *   (編集者で通っても撮影者で通る保証にならないため)。
 * - 片側だけの検査では「到達条件が同じ」を言えないので、1 本で両方を叩く。
 * - **status に依らない**。復路リンクは status で出し分けないため、全 status で固定する
 *   (往路の isCaptureNavigable は rendering で消えるが、それは別の述語である)。
 * - 200 だけでなく**着地した画面 (Inertia component)** まで見る。200 を返す別画面へ
 *   逃がす実装に置き換わったとき、200 だけの検査は沈黙するため。
 *
 * 両 route が同じく通る層 (省略形で書かない):
 *   auth / verified / not-pending-deletion (外側 group)
 *   → require-active-subscription / project.in-route-org (内側 group)
 *   → Route::scopeBindings() ($project->manuals() 経由)
 *   → controller の resolveOrganizationProject() (認可より前に 404)
 *   → Gate::authorize('view', $manual)
 * 詳細 GET はどちらも status による絞り込みを持たない (一覧 index だけが持つ)。
 */

test('撮影者は全 status で撮影ナビと PC 側マニュアル詳細の両方へ到達できる', function (VideoManualStatus $status): void {
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
  - mutation F: PC 側 controller を 200 の別 Inertia 画面へ差し替えると、
    `assertOk()` は通るが **`component('Manuals/Show')` が落ちる**
    (= Critical 指摘への対応が実際に効いていることの実測)。
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
  復路は `Capture/Show` ヘッダーの「マニュアル詳細へ」(**status にも認可にも依らず常に出す**)。
  **2 つの述語を共有しない**: 往路は「いま撮影を始めてよい相か」、復路は「元の画面へ戻れるか」で
  意味が違い、合成中 (`rendering`) こそ進み具合を見に戻る場面である。復路専用の述語も作らない。
  復路を無条件にできる根拠は、行き先 `projects.manuals.show` が `capture.manuals.show` と
  **同じ層を同じ順序で通る**ことである — 外側 group の `auth` / `verified` /
  `not-pending-deletion`、内側 group の `require-active-subscription` / `project.in-route-org`、
  `Route::scopeBindings()`、controller の `resolveOrganizationProject()` (認可より前に 404)、
  `Gate::authorize('view', $manual)`。詳細 GET はどちらも status で絞り込まない (一覧だけが絞る)。
  よって 403 が構造的に起きない。これは `CaptureReturnPathTest` が撮影者 (project_member) で
  **全 status について両 route の 200 + 着地 component** を実測して固定する。
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
