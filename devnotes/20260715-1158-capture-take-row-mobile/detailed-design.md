# 詳細設計: capture-take-row-mobile（撮影テイク行の mobile 375px レイアウト崩れ修正）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示。DESIGN.md）** ← 本件で維持すべき原則

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本件は **PHP 変更なし**のため PHPStan 影響なし。
- **Pest**（`composer test`）— 本件は **PHP テスト変更なし**。
- フロント検証: `pnpm typecheck` / `pnpm lint` / `pnpm test`（vitest）/ `pnpm build` を green にする。
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` canonical、ds-purity テストが検出）。
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。
  アイコンは `@lucide/svelte` のみ。
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript。

## 概念設計リファレンス

`devnotes/20260715-1158-capture-take-row-mobile/conceptual-design.md`（conceptual-review R1 **APPROVED**）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | テイク行を mobile で 2 段化する responsive flex 化 | `resources/js/components/features/capture/TakeStrip.svelte` | High |
| 2 | 構造検証用の data-testid 付与 + vitest 構造テスト追加 | 同上 / `tests/js/components/features/capture/TakeStrip.test.ts` | High |

> 施策 1・2 は同一ファイルの同一 DOM への変更で不可分。1 コミットで実施する。

---

## 施策 1: テイク行を mobile で 2 段化する responsive flex 化

### 変更箇所

- ファイル: `resources/js/components/features/capture/TakeStrip.svelte`
  - 行コンテナ（L190-195, `take-item-${id}`）
  - chevron 列（L196）
  - バッジ行 `<p>`（L219）
  - 操作ボタン列（L247）

### 波及変更

- TypeScript 型定義: **なし**（props / 型は不変）。
- API Resource/DTO: **なし**（フロント表示のみ。サーバ・DTO 波及なし）。
- テストファイル: `tests/js/components/features/capture/TakeStrip.test.ts`（施策 2 で構造検証を追加）。
- 他コンポーネント: **なし**（TakeStrip 内 DOM に閉じる。親 `CaptureShow`/`CameraRecorder` の props 契約は不変）。

### 現行コード（要点）

```svelte
<!-- 行コンテナ (L190-195): nowrap -->
<div
    class="flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 {take.downloaded
        ? 'border-border-strong'
        : ''}"
    data-testid={`take-item-${take.id}`}
>
    <!-- chevron 列 (L196) -->
    <div class="flex flex-col gap-1">
        ...上へ / 下へ Button...
    </div>
    <!-- ラベル・メタ列 (L218) -->
    <div class="min-w-0 flex-1">
        <!-- バッジ行 (L219): flex nowrap かつ min-w-0 無し → はみ出す (直接原因) -->
        <p class="flex items-center gap-2 text-body">
            テイク {index + 1}
            {#if cut.adopted_take_id === take.id}<Badge tone="success" ...>採用中</Badge>{/if}
            {#if take.downloaded}<Badge tone="neutral">DL 済み</Badge>{/if}
        </p>
        <p class="text-caption text-text-secondary">...サイズ・秒・コメント...</p>
        ...not-ready 文言...
    </div>
    <!-- 操作ボタン列 (L247): shrink-0 で 5 ボタンが幅を占有 -->
    <div class="flex shrink-0 items-center gap-1">
        ...Play / 採用 / DL / コメント / 削除...
    </div>
</div>
```

### 変更後コード（要点）

```svelte
<!-- 行コンテナ: mobile は flex-wrap で 2 段可、sm(640)+ で nowrap 復帰 -->
<div
    class="flex flex-wrap items-center gap-x-2 gap-y-2 rounded-md border border-border bg-surface px-3 py-2 sm:flex-nowrap {take.downloaded
        ? 'border-border-strong'
        : ''}"
    data-testid={`take-item-${take.id}`}
>
    <!-- chevron 列: 圧縮させない -->
    <div class="flex shrink-0 flex-col gap-1">
        ...上へ / 下へ Button...
    </div>
    <!-- ラベル・メタ列: 現状維持 (min-w-0 flex-1) -->
    <div class="min-w-0 flex-1">
        <!-- バッジ行: flex-wrap + min-w-0 で狭幅時はバッジが段落ち (はみ出さない) -->
        <p
            class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-body"
            data-testid={`take-label-${take.id}`}
        >
            テイク {index + 1}
            {#if cut.adopted_take_id === take.id}<Badge tone="success" ...>採用中</Badge>{/if}
            {#if take.downloaded}<Badge tone="neutral">DL 済み</Badge>{/if}
        </p>
        <p class="text-caption text-text-secondary">...サイズ・秒・コメント...</p>
        ...not-ready 文言...
    </div>
    <!-- 操作ボタン列: mobile は w-full で下段へ折り返し右寄せ + 総幅増の failsafe として wrap 許可、sm(640)+ で従来 (inline 左寄せ・1 行) -->
    <div
        class="flex w-full shrink-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:w-auto sm:flex-nowrap sm:justify-start"
        data-testid={`take-actions-${take.id}`}
    >
        ...Play / 採用 / DL / コメント / 削除...
    </div>
</div>
```

**レイアウト挙動**:
- mobile（< 640px）: 行が `flex-wrap`。1 段目に `chevron 列 + ラベル列(flex-1)`、`w-full` の操作列が 2 段目へ折り返し（`justify-end` で右寄せ）。操作列がラベル幅を奪わず、Play とバッジの重なりが構造的に消える。バッジ行も `flex-wrap` で自段内に折り返す。操作列自身も `flex-wrap`（failsafe）で、翻訳・将来のボタン増でボタン総幅が段幅を超えても右端で溢れず段内で折り返す（design-review R1 Warning 反映）。
- tablet/PC（≥ 640px, 768 含む）: `sm:flex-nowrap` + 操作列 `sm:w-auto sm:flex-nowrap sm:justify-start` で従来の 1 行に復帰（**非退行**）。

**ブレークポイント根拠（`sm` = 640px。design-review R1 Warning 反映）**: 操作列（≈190px）＋ chevron 列（≈30px）＝ ≈220px。640px で 1 行復帰しても残り ≈400px がラベル列に確保され、375px のような窮屈化は起きない（640-767px 帯も余裕をもって 1 行成立）。全スマホ幅（320-430px 級）を 2 段へ寄せ tablet/PC を 1 行に保つ境界として `sm` が最適で、`md`（768）だと 640-767px の小型端末まで冗長に 2 段化する。

### 変更後の DOM 差分（クラスのみ）

| 要素 | before | after |
|------|--------|-------|
| 行 | `flex items-center gap-2 ...` | `flex flex-wrap items-center gap-x-2 gap-y-2 ... sm:flex-nowrap` |
| chevron 列 | `flex flex-col gap-1` | `flex shrink-0 flex-col gap-1` |
| バッジ `<p>` | `flex items-center gap-2 text-body` | `flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-body` + `data-testid` |
| 操作列 | `flex shrink-0 items-center gap-1` | `flex w-full shrink-0 flex-wrap items-center justify-end gap-x-1 gap-y-1 sm:w-auto sm:flex-nowrap sm:justify-start` + `data-testid` |

### PHPStan 適合チェック

- 本施策は **PHP を一切変更しない**（Svelte テンプレートのクラス文字列と data-testid のみ）。PHPStan 対象外。
- TS ロジック（`<script>` 部）は不変 → `pnpm typecheck` 影響なし。

### DESIGN.md / Atomic Design 準拠チェック

- [x] color/radius/typography の token は既存のまま（`border-border` / `bg-surface` / `text-body` 等）。hex 直書き追加なし。
- [x] 追加はレイアウトユーティリティ（`flex-wrap` / `w-full` / `sm:` / `min-w-0` / `gap-*` / `justify-*` / `shrink-0`）のみ → ds-purity 非抵触。
- [x] atom（Badge/Button）は責務変更なし。features/capture 層のレイアウト調整に閉じ、import 単方向を崩さない。
- [x] 新規 SVG / 新規 atom 追加なし。アイコンは既存 Lucide のまま。
- [x] disabled 禁止原則（DESIGN.md / 禁止事項 8）は維持（本施策はレイアウトのみ、押下時エラー方式を崩さない）。

### テスト計画

施策 2 で担保（同一 DOM 変更のため一体でテスト）。

### リスク

- **tablet 非退行**: `sm:flex-nowrap` / `sm:w-auto` / `sm:justify-start` の付け忘れで tablet が 2 段化する後退リスク → vitest でクラス存在を検証、実装時に 768 screenshot で確認。
- `w-full` は `min-w-0 flex-1` のラベル列とは別軸（操作列側）なので、ラベル列の flex 計算に干渉しない。
- JSDOM は media query を評価しないため、vitest では「`sm:` クラスが付与されている」ことの検証に留める（実際の 640px 挙動は実装時 screenshot で担保）。この限界は許容（構造検証＋目視証跡の二段構え）。

---

## 施策 2: 構造検証用の data-testid 付与 + vitest 構造テスト追加

### 変更箇所

- `resources/js/components/features/capture/TakeStrip.svelte`: バッジ行 `<p>` に `take-label-${id}`、操作列に `take-actions-${id}` を付与（施策 1 に含む）。
- `tests/js/components/features/capture/TakeStrip.test.ts`: 構造検証の describe を追加。

### 波及変更

- TypeScript 型定義 / API Resource / DTO: **なし**。
- 既存テスト: 既存 15 ケースは DOM 挙動（testid ベース）を検証しており、クラス変更・testid 追加で**壊れない**（既存 testid は不変）。更新不要（非退行確認のみ）。

### 受け入れ基準（二段構え。design-review R1 Critical 反映）

JSDOM/vitest は media query もピクセル寸法も評価しないため、**「実際の重なり」は vitest 単独では検知できない**。
そこで受け入れを 2 段で担保する:

1. **自動回帰ガード（vitest 構造契約テスト）**: 重なり回避の DOM 契約（`flex-wrap` / `w-full` /
   `min-w-0` / `sm:` の付与）を回帰から守る。CI（`pnpm test`）で常時実行。
2. **受け入れゲート（実装時の実ブラウザ screenshot 証跡）**: 実装 PR で **320px / 375px / 640px / 641px / 768px** の
   スクリーンショットを取得し、**「採用中」＋「DL 済み」両バッジ同時**の最悪ケースで確認する:
   - 320/375px: 2 段レイアウトで「重なり 0・アイコン欠け無し・『採用』テキスト切れ無し」。
   - **640px / 641px（`sm` 境界の実証。design-review R2 Warning 反映）**: `sm` は viewport 基準で
     TakeStrip の実効幅は親（`CaptureShow` のカード/カラム余白）の影響を受けるため、**実際の親レイアウト内で**
     「両バッジ・全操作表示・1 行維持・窮屈化無し」を目視で実証する。
   - 768px: 従来 1 行（非退行）。
   - **成立しなかった場合の contingency**: 640-767px 帯で 1 行が窮屈なら、行の `sm:flex-nowrap` /
     操作列の `sm:w-auto sm:flex-nowrap sm:justify-start` を **`md:` 版へ差し替える**（768 境界へ後退）。
     その場合も 375/320 の 2 段改善は不変。この差し替えはクラス prefix 変更のみで施策構造は変えない。

> **Playwright ビジュアル回帰 CI の新設はスコープ外**とする。現状アプリに E2E/Playwright harness は無く
> （`package.json` に e2e スクリプト無し・`tests/e2e` 無し・playwright は bug-hunt スキルの隔離環境のみ）、
> 単一レイアウト回帰のためにビジュアル回帰基盤を新設するのは思考原則 2「今必要なものだけ作る
> （オーバーエンジニアリング禁止）」に反する。将来ビジュアル回帰 CI を導入する際に本行を再訪する。

### テスト方針（design-review R1 Warning 反映）

- `data-testid`（`take-label-*` / `take-actions-*`）は **レイアウト契約点のみ**に付与する。
- 文言・表示有無の検証は **role / text クエリを優先**し、testid への過度な依存で DOM リファクタ耐性を落とさない。

### テスト計画（追加ケース）

`describe("mobile 375px レイアウト構造 (F-1-05)")` を追加:

- [ ] **行コンテナが responsive wrap**: `take-item-${id}` の className が `flex-wrap` と `sm:flex-nowrap` を含む。
- [ ] **操作列が mobile full-width 右寄せ + wrap failsafe / tablet 復帰**: `take-actions-${id}` の className が
  `w-full` `justify-end` `flex-wrap` `sm:w-auto` `sm:flex-nowrap` `sm:justify-start` を含む。
- [ ] **バッジ行が wrap・縮小可能**: `take-label-${id}` の className が `flex-wrap` と `min-w-0` を含む。
- [ ] **両バッジ最悪ケースがラベル行内に収まる**: `downloaded:true` かつ adopted で render し、
  `within(getByTestId("take-label-10"))` 内に「採用中」`take-adopted-10` と「DL 済み」が両方存在する
  （重なりではなく同一ラベル要素内の兄弟として配置されることを構造で担保）。
- [ ] **最小ケースでバッジが混入しない**: `adopted=null` かつ `downloaded:false` で render し、
  ラベル行内に `take-adopted-*` と「DL 済み」が**存在しない**（過剰 DOM 混入防止。design-review R1 Suggestion）。

非退行:
- [ ] 既存 15 ケース（adopt / delete 確認 / preview / DL ACK / not-ready 等）が全て green のまま。
- [ ] 個別 `DatabaseTransactions` は無関係（フロント vitest。PHP 側変更なし）。

### テストコード（追加 describe イメージ）

```ts
describe("mobile 375px レイアウト構造 (F-1-05)", () => {
    it("行コンテナは mobile で wrap し sm で 1 行復帰する", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const row = screen.getByTestId("take-item-10");
        expect(row.className).toContain("flex-wrap");
        expect(row.className).toContain("sm:flex-nowrap");
    });

    it("操作列は mobile full-width 右寄せ+wrap failsafe・sm で従来 1 行に戻る", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const actions = screen.getByTestId("take-actions-10");
        for (const c of ["w-full", "justify-end", "flex-wrap", "sm:w-auto", "sm:flex-nowrap", "sm:justify-start"]) {
            expect(actions.className).toContain(c);
        }
    });

    it("ラベル(バッジ)行は wrap・min-w-0 で段落ちできる", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const label = screen.getByTestId("take-label-10");
        expect(label.className).toContain("flex-wrap");
        expect(label.className).toContain("min-w-0");
    });

    it("採用中+DL済み 両バッジがラベル行内に収まる (重なりでなく段落ち構造)", () => {
        const take = makeTake({ downloaded: true });
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([take], take.id), onChanged: vi.fn() });
        const label = within(screen.getByTestId("take-label-10"));
        expect(label.getByTestId("take-adopted-10")).toBeInTheDocument();
        expect(label.getByText("DL 済み")).toBeInTheDocument();
    });

    it("最小ケース (未採用・未DL) ではバッジが混入しない", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const label = within(screen.getByTestId("take-label-10"));
        expect(label.queryByTestId("take-adopted-10")).not.toBeInTheDocument();
        expect(label.queryByText("DL 済み")).not.toBeInTheDocument();
    });
});
```

### リスク

- クラス文字列の検証はリファクタ耐性が低い（Tailwind クラス名変更で false negative）。ただし本件は
  「重なり回避の構造契約（wrap / w-full / min-w-0 / sm:）」を回帰から守る目的で、契約となるクラスに限定しているため妥当。
- 実際のピクセル重なりは JSDOM では測れない → 実装時に 375/768 の screenshot 証跡で補完（概念設計の検証マトリクス準拠）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 単一ファイル（TakeStrip.svelte）＋対応テストの小規模レイアウト修正。既存 props/API/DTO へ波及なし。main への増分マージが安全で、他 bug-hunt 施策と競合しない。 |
| 競合リスク | 低。TakeStrip.svelte を触る他 Open タスクが無ければ衝突なし（実装前に確認）。T050/T056 は既にクローズ済み。 |

## 最終確認（使命・禁止事項チェック）

- [x] 全施策が使命に寄与（スマホ主戦場の撮影→採用 UI 崩れ解消 = 「思考ゼロ・編集ゼロ」の実機体験回復）。
- [x] 禁止事項非抵触（PHP/DTO/Prism/prompt/redirect 無関係。disabled 禁止原則を維持）。
- [x] テスト必須を満たす（vitest 構造テスト追加 + 既存非退行）。
- [x] DESIGN.md / Atomic Design 準拠（token 維持・レイアウトユーティリティのみ・atom 責務不変）。
</content>
