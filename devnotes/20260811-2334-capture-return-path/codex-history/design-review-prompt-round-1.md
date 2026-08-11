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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 13.18 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク (RefreshDatabase グローバル適用・--parallel)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にPestテスト）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（design token 経由か、hex 直書きを増やしていないか）
11. Atomic Design準拠（atoms/molecules/organisms/templates の責務分離、アイコンは Lucide のみ）

【この設計に固有の、特に厳しく見てほしい点】
- 施策 3 の Feature テストは「到達条件が同じ」を本当に固定できているか。抜けている経路は無いか。
- 施策 2 の Vitest が、後日「往路の述語で包む」退行を確実に赤くできるか。
- Browser lane を追加しないという判断は妥当か、それとも手抜きか。
- 文言・アイコン・DOM 順の判断に見落としは無いか (a11y 含む)。
- サーバ側 0 行という主張に嘘は無いか。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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

**サーバ側のコード変更は 0 件**(route / controller / DTO / policy いずれも触らない)。
**新規モデルなし** = Factory 追加は不要。**インターフェース変更なし** =
TypeScript 型定義 / Inertia Props / JsonResource への波及なし。

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
            <!--
                PC 側マニュアル詳細への復路。往路 (Manuals/Show の「この手順書を撮影する」) と対になる。
                **出し分けない**: 遷移先 projects.manuals.show の到達条件は本画面と同一
                (同一 middleware 2 本 + 同じ Gate::authorize('view', $manual)) であり、
                403 の行き止まりが構造的に生じないため。
                往路の isCaptureNavigable は「撮影を始めてよい相か」の述語であり復路とは別概念なので
                流用しない (rendering 中こそ進み具合を見に戻りたい)。復路専用の述語も作らない。
            -->
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
  折り返しで吸収される(既存構造のまま。新しい overflow 経路を作らない)。
  施策 2 で mobile 幅の overflow ガードを再確認する既存テストが緑のままであることを見る。

---

## 施策 2: DOM 契約の固定(Vitest)

### 変更箇所

- ファイル: `tests/js/pages/CaptureShow.test.ts`(末尾に describe を 1 つ追加)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし

### 変更後コード(追加分)

```ts
describe("Capture/Show マニュアル詳細への復路 (capture-return-path)", () => {
    it("ヘッダーに PC 側マニュアル詳細へのリンクを出す", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        const link = screen.getByTestId("manual-detail-link");
        expect(link.getAttribute("href")).toBe("/projects/1/manuals/5");
        expect(link.textContent).toContain("マニュアル詳細へ");
    });

    it("既存の「一覧へ戻る」(撮影 PWA 一覧) は置き換えず併置する", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        const back = screen.getByRole("link", { name: /一覧へ戻る/ });
        expect(back.getAttribute("href")).toBe("/app/projects/1/manuals");
    });

    it.each(["draft", "analyzing", "ready", "rendering", "published"])(
        "status=%s でも復路は消えない (往路の isCaptureNavigable を流用していないこと)",
        (status) => {
            stubCameraSupported(false);
            render(CaptureShow, {
                props: { ...baseProps, manual: { ...makeManual(), status } },
            });

            expect(screen.getByTestId("manual-detail-link")).toBeTruthy();
        },
    );
});
```

**3 本目が本命**である。`rendering` を含む全 status で復路が残ることを固定するので、
後日だれかが `{#if isCaptureNavigable(manual.status)}` で包むと**必ず赤くなる**
(概念設計で否定した実装が、機械的に禁止される)。

### テスト計画(fail 先行の確認手順)

1. 施策 2 のテストだけを先に置いて `pnpm test` を走らせ、3 本すべてが
   「`manual-detail-link` が見つからない」で落ちることを確認する(2 本目は既存 DOM で通るので、
   **1・3 本目が落ちる**ことを確認する)。
2. 施策 1 を実装して緑にする。
3. mutation: 施策 1 のリンクを `{#if isCaptureNavigable(manual.status)}` で包み、
   3 本目の `rendering` / `draft` / `analyzing` ケースだけが落ちることを実測する。
4. mutation: href を `/app/projects/...`(撮影 PWA 側)に戻し、1 本目が落ちることを実測する。

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

### 変更後コード

```php
<?php

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

/*
 * 撮影 PWA → PC 側マニュアル詳細の復路 (capture-return-path)。
 *
 * 固定する契約:
 * - 撮影ナビ (capture.manuals.show) を開ける利用者は、復路の行き先
 *   (projects.manuals.show) も**必ず**開ける = UI に出す無条件リンクが 403 にならない。
 * - もっとも弱い principal である**撮影者 (project_member)** で確認する
 *   (編集者で通っても撮影者で通る保証にならないため)。
 * - 片側だけの検査では「到達条件が同じ」を言えないので、1 本のテストで両方を叩く。
 */

test('撮影者は撮影ナビと PC 側マニュアル詳細の両方を 200 で開ける', function (): void {
    [$organization, ] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)
        ->get("/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk();

    // 復路の行き先。ここが 403/404 になるなら施策 1 の無条件リンクは詰みの導線になる
    $this->actingAs($member)
        ->get("/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk();
});

test('シナリオ合成中 (rendering) でも復路の行き先は開ける', function (): void {
    // 往路 (isCaptureNavigable) は rendering で消えるが、復路は状態に依存しない。
    // 「往路の述語を復路へ流用しない」という設計判断のサーバ側の裏づけ。
    …(status を rendering にした同形の 2 本立て)
});
```

**Factory 経由**でデータを作り、`Model::create()` 手組みをしない。
ヘルパー(`createOrganizationWithOwner` / `attachOrganizationMember` / `attachProjectMember`)は
`tests/Feature/Manual/FinishedVideoPlaybackTest.php` の先例と同じものを使う。

### PHPStan 適合チェック

- [x] テストのみ。戻り値型は `void` を明示
- [x] `Model::create()` 手組みなし(Factory 経由)
- [x] 個別の `DatabaseTransactions` を使わない(グローバル `RefreshDatabase`)

### テスト計画(fail 先行の確認手順)

- このテストは**現行コードでも緑**である(サーバ側を変えないため)。よって「fail 先行」は
  施策 2 が担う。本テストの意義は**将来の退行検出**であり、それを実測で示すために
  mutation を 1 つ行う: `routes/web.php` の PC 側 group に一時的に
  `Gate::authorize('update', $manual)` 相当の制限(= 撮影者を弾く条件)を足すと
  **2 本目の PC 側 assert が落ちる**ことを確認し、元に戻す。

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
  復路は `Capture/Show` ヘッダーの「マニュアル詳細へ」(**status に依らず常に出す**)。
  **2 つの述語を共有しない**: 往路は「いま撮影を始めてよい相か」、復路は「元の画面へ戻れるか」で
  意味が違い、合成中 (`rendering`) こそ進み具合を見に戻る場面である。
  復路を無条件にできる根拠は、行き先 `projects.manuals.show` の到達条件が
  `capture.manuals.show` と同一(同じ middleware 2 本 + 同じ `Gate::authorize('view', $manual)`)で
  403 が構造的に起きないことで、これは `CaptureReturnPathTest` が撮影者 (project_member) で
  両方 200 を実測して固定する。
  **保証しないもの**: インストール済み PWA (standalone) で同一窓に留まることは保証しない
  (`public/manifest.webmanifest` に `scope` 宣言が無く、既定 scope が `/` になるという
  仕様の読みに基づくだけで実機観測がない)。撮影完了の検知・自動遷移も行わない
  (ヘッダーの常設リンクのみ)。撮影者が完成動画を観られるようにもならない (認可は不変)。
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
- **施策 3 が固定するのは 2 route の到達可否だけ**である。PC 詳細画面の中身
  (どのパネルが出るか) は対象外で、既存テストの担当である。


---

## 関連する現行コード (抜粋)

### resources/js/pages/Capture/Show.svelte (L1-10 / L226-245 / L275-290)

```svelte
<script lang="ts">
    import { onMount, tick } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, Video } from "@lucide/svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
    import type CameraRecorderType from "@/components/features/capture/CameraRecorder.svelte";
    import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
...
        <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
            <TextLink href={`/app/projects/${project.id}/manuals`}>
                <ArrowLeft class="inline size-3" aria-hidden="true" />
                一覧へ戻る
            </TextLink>
        </PageHeaderSection>

        <div class="mt-3">
        <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section
            bind:this={leftPaneEl}
            class="min-w-0 rounded-md border border-border bg-surface"
            data-testid="capture-left-pane"
        >
            <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
                 フォーカス可能にする (Tab 順には入れない)。 -->
            <h2
                bind:this={cutListHeadingEl}
...
                        class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                        data-testid="capture-recording-heading"
                    >
                        {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
                    </h2>
                    {#if stacked}
                        <!-- 1 カラムのときだけ出す (2 カラムでは一覧が常に見えているので不要)。
                             TextLink のボタンモード (href なし + onclick) = <button type="button">。 -->
                        <TextLink onclick={backToCutList} testId="back-to-cut-list">
                            カット一覧へ戻る
                        </TextLink>
                    {/if}
                </div>

                <div class="rounded-md border border-border bg-surface p-3">
                    <p class="text-caption text-text-secondary">ナレーション</p>
                    <p class="mt-1 text-body">{selectedCut.narration}</p>
                    {#if selectedCut.shooting_point}
                        <p class="mt-2 text-caption text-text-secondary">
```

### resources/js/components/molecules/PageHeaderSection.svelte (children の並び)

```svelte
<div
    class="-mx-4 -mt-8 border-b border-border bg-surface px-4 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8"
>
    <div class="py-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex min-w-0 items-center gap-3">
                {#if icon}
                    {@const Icon = icon}
                    <Icon class="size-10 shrink-0 text-primary" aria-hidden="true" />
                {/if}
                <h1 class="truncate text-h2 text-text" data-testid={testId}>{title}</h1>
            </div>
            {#if children}
                <div class="flex min-w-0 shrink flex-wrap justify-end gap-2">
                    {@render children()}
                </div>
            {/if}
        </div>
    </div>
```

### resources/js/components/atoms/TextLink.svelte (内部リンクモード)

```svelte
</script>

{#if href !== undefined && external}
    <!-- (b) 外部リンク: ネイティブ <a> + 別タブ + tabnabbing 防止 -->
    <a
        {href}
        target="_blank"
        rel="noopener noreferrer"
        class={computedClass}
        data-testid={testId}
        {onclick}
    >
        {@render children?.()}
        <IconComponent class="size-3.5 shrink-0" aria-hidden="true" />
    </a>
{:else if href !== undefined}
    <!-- (a) 内部リンク: Inertia Link で SPA 遷移 -->
    <Link {href} class={computedClass} data-testid={testId} {onclick}>
        {@render children?.()}
    </Link>
{:else}
    <!-- (c) ボタンモード: <a> にできない遷移トリガ用のリンク風 button -->
    <button type="button" class={computedClass} data-testid={testId} {onclick}>
        {@render children?.()}
    </button>
```

### routes/web.php (PC 業務 group と 撮影 PWA group の middleware)

```php
Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(function (): void {
    // ログイン直後の着地点 (課金ゲート外のまま。未契約でも状況把握と復帰導線を提供)
...
    Route::middleware(['require-active-subscription', 'project.in-current-org'])->group(function (): void {
        /*
...
        Route::post('/projects/{project}/manuals', [VideoManualController::class, 'store'])
            ->name('projects.manuals.store');
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
                ->name('projects.manuals.show');
            Route::get('/projects/{project}/manuals/{manual}/edit', [VideoManualController::class, 'edit'])
                ->name('projects.manuals.edit');
...
    */
    Route::middleware(['require-active-subscription', 'project.in-current-org'])
        ->prefix('app')->as('capture.')->group(function (): void {
            // PWA エントリ (manifest start_url)。current org の先頭 project へ redirect
            Route::get('/', [CaptureManualController::class, 'home'])->name('home');
            // CSRF cookie 再発行 (419 リトライ用の軽量 GET。web group を通るだけで
            // XSRF-TOKEN cookie が更新される。204 = 仕様固定 endpoint、body なし)
            Route::get('/csrf-cookie', fn (): Response => response()->noContent())
                ->name('csrf-cookie');
            Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])
                ->name('manuals.index');
            Route::scopeBindings()->group(function (): void {
                Route::get('/projects/{project}/manuals/{manual}', [CaptureManualController::class, 'show'])
                    ->name('manuals.show');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url', [TakeUploadUrlController::class, 'store'])
```

### app/Http/Controllers/Capture/CaptureManualController.php (show)

```php
    /** 撮影ナビ (cuts + 全 take メタ + 採用テイク署名 DL URL / ACK トークン) */
    public function show(
        Request $request,
        Project $project,
        VideoManual $manual,
        TakeObjectStorage $storage,
        UploadTicketCodec $codec,
        SeoManager $seo,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $manual); // 読み取りは撮影者含む org member

        // 撮影 PWA であることをタブ上で判別可能にする動的固有名
        $seo->setPrivateTitle($manual->title.' の撮影');

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

```

### app/Http/Controllers/Projects/VideoManualController.php (show 冒頭)

```php
    public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo, VideoManualService $manuals): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('view', $manual);

        // 動的固有名の per-page タイトル (noindex 維持。projects.show の参考実装踏襲)
        $seo->setPrivateTitle($manual->title);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $category = $manual->category;

        // stale な失敗 (失敗確定後に scenario 保存が成立) は job=null で抑制する (T032 / F-1-1)
        $analysisJob = $manuals->displayAnalysisJob($manual);
        $renderJob = $manuals->displayRenderJob($manual);
        $previewJob = $manuals->displayPreviewJob($manual);
        // 再生できるプレビュー (最新 succeeded preview)。**id だけでなく行そのもの**を props に載せる:
        // 動画 URL と「黒背景が何カット分か」の注記が同一オブジェクトから出るため、
        // 最新 preview job と再生対象が別世代になる穴が構造的に消える (T148)。
        // succeeded preview のみを見るため staleness 抑制の対象外 (不変)。
        // 選択式は CurrentRenderArtifact に集約済み (route 側と同一の行を指す = T154)。
        $playbackJob = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Preview);
        // 受け取れる完成動画。**endpoint が 302 を返す条件と 1 対 1**にする:
        // published + download ability + 現行世代。UI の canManage は表示制御であって
        // 秘匿境界ではないため、ここで ability を評価する (条件を UI 側に持たせない)。
        $finishedJob = $manual->status === VideoManualStatus::Published && $user->can('download', $manual)
            ? CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)
            : null;

```

### app/Policies/VideoManualPolicy.php (view)

```php

    /** 閲覧: プロジェクトを閲覧できる人 (撮影者も可) */
    public function view(User $user, VideoManual $manual): bool
    {
        $project = $manual->project;

        return $project !== null && $this->projectPolicy->view($user, $project);
    }
```

### resources/js/pages/Manuals/Show.svelte (往路)

```svelte
<AppLayout {appName}>
    <PageContainer>
        <PageHeaderSection
            title={manual.title}
            icon={BookOpen}
            testId="manual-title"
            breadcrumbs={[
                { label: project.name, href: `/projects/${project.id}` },
                { label: manual.title },
            ]}
        >
            {#if captureNavigable}
                <!-- canManage 内外を問わず表示 (撮影者=project_member も撮影ナビ view 可) -->
                <Button
                    variant="primary"
                    href={`/app/projects/${project.id}/manuals/${manual.id}`}
                    inertia
                    testId="capture-manual-link"
                >
                    <Camera class="size-4" aria-hidden="true" />
                    この手順書を撮影する
                </Button>
            {/if}
            {#if canManage}
                <Button
```
