# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。v1 スコープ: 字幕のみ / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行 / 既存テストの削除・上書き
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# 思考原則

まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- PHPStan level 10 / Pest / vitest + @testing-library/svelte
- 本タスクは**フロントのみ**（サーバ/DTO/DB 非変更）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）。特に Svelte5 $effect のエッジ検知（prevOpen）とループ/ちらつきリスク
2. 既存コードとの整合性（命名規約、パターン、Inertia useForm の onSuccess/onError、Button/Modal atom）
3. TypeScript 型安全性（$props / useForm shape / defaultCategory: number|null）
4. テスト計画の網羅性（各施策に vitest。reactiveUseForm フェイクで onSuccess close / 多重送信ガード / 再seed をどう観測するか妥当か）
5. Inertia Props vs API Response の使い分け（本件は redirect 遷移）
6. 副作用・後退リスク（onSuccess で open=false と redirect の競合、$effect の初回マウント seed）
7. 波及変更の網羅性（Manuals/Show.svelte / 共有 test helper reactiveUseForm への変更が漏れなく施策化されているか）
8. セキュリティ（フロント二重送信ガードはサーバ冪等性を代替しない旨の整理が妥当か）
9. DESIGN.md 準拠 / Atomic Design 準拠（新規 SVG・hex 直書きを増やさないか。component 階層の単方向 import）
10. 禁止事項8（未充足 disabled）と「送信中 disabled」の境界が正しく設計されているか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（devnotes/20260715-1123-manual-duplicate-dialog-close/detailed-design.md 全文）

# 詳細設計: manual-duplicate-dialog-close

bug-hunt run 20260715-084108 F-1-01 (High) / T049 複製機能の不具合

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の
3 ハードルを AI とナビ撮影で肩代わりする。競合と異なり標準作業を起点に AI が教材設計し撮影を指示する。
熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。v1 スコープ: 字幕のみ / 撮影は PWA /
動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（本設計に関係する核）

- (1) テストなしの実装完了報告 → 各施策に vitest を伴う。
- (8) 必須条件未充足を理由にボタンを disabled にする UI → **本設計は「送信中(form.processing)の submit
  多重防止」のみを disabled 化に使い、入力未充足では disabled を使わない**（空タイトルでも押下でき、
  押下時にエラー表示）。
- (2) PHPStan widen / (3) dev DB 破壊操作 / (4) response()->json() 直書き / (5)(6) Prompt 系 → 本設計は
  フロントのみで無関係だが違反しない。

### コーディングルール

- フロントは Svelte 5 runes（`$props` / `$state` / `$bindable` / `$effect`）+ DS token/ramp のみ
  （`DESIGN.md` canonical）。フォームは FormField / atom 経由。
- component 階層は単方向 import。アイコンは `@lucide/svelte` のみ。新規 SVG 内包・hex 直書きを増やさない。
- 検証コマンド: `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build`（全 green でコミット）。
- 本タスクは PHP 変更を含まないため `composer phpstan` / `composer test` は対象外（回帰確認のみ任意）。

## 概念設計リファレンス

- `devnotes/20260715-1123-manual-duplicate-dialog-close/conceptual-design.md`（APPROVED / conceptual-review Round 2）

## 根本原因（確定）

`DuplicateManualDialog.svelte` の `submit()` は `form.post()` に `onError` のみを渡し、成功時に
ダイアログを閉じない。複製成功時サーバは新 VideoManual への redirect を返し、Inertia は**同じ
`Manuals/Show` コンポーネントへ props 差し替えで遷移**する（再マウントしない）。そのため親
`Manuals/Show.svelte` の `duplicateDialogOpen`（`$state`）は `true` のまま生存し、確認モーダルが
新マニュアル画面に開いたまま残る。`form.processing` は完了で false に戻りボタンが再有効化されるため、
再クリックすると**現在のマニュアル（=直前のコピー）を無言で再複製**する（H6/H7）。
副次的に、生存したダイアログを再オープンすると `useForm`（マウント時 1 回初期化）の値が旧マニュアルの
`{旧タイトル} のコピー`のまま残る。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 複製成功時にダイアログを確実に閉じる（onSuccess: open=false） | `resources/js/components/features/manual/DuplicateManualDialog.svelte` | High |
| 2 | 送信中の多重送信ガード（ハンドラ冒頭 `if (form.processing) return`）| 同上 | High |
| 3 | 再オープン(false→true エッジ)時に現 props で再 seed + `clearErrors()` | 同上 | High |
| 4 | vitest 追加（close / 多重送信抑止 / 再 seed / 既存維持）| `tests/js/components/features/manual/DuplicateManualDialog.test.ts` | High |
| 5 | test support: `reactiveUseForm` の `processing` を反応化（DOM disabled を観測可能に）| `tests/js/support/reactiveUseForm.svelte.ts` | Med |

---

## 施策1: 複製成功時にダイアログを確実に閉じる

### 変更箇所
- ファイル: `resources/js/components/features/manual/DuplicateManualDialog.svelte`（`submit()` L41-54 付近）

### 波及変更
- TypeScript 型定義: なし（Props 不変）
- API Resource/DTO: なし（サーバ非変更）
- `Manuals/Show.svelte`: **変更不要**。`bind:open={duplicateDialogOpen}` により子で `open=false` にすると
  親 `$state` へ双方向反映される。波及なしを確認済み。
- テストファイル: 施策4で対応

### 現行コード
```ts
function submit(): void {
    form
        .transform((data) => ({
            title: data.title,
            category: data.category === "" ? null : Number(data.category),
        }))
        .post(`/projects/${projectId}/manuals/${manualId}/duplicate`, {
            // 成功時は redirect で新 manual へ遷移するため onSuccess で閉じる必要はない
            onError: () => {
                /* エラーは FormField 経由で表示 (ダイアログは開いたまま) */
            },
        });
}
```

### 変更後コード
```ts
function submit(): void {
    // 送信中の再入 (二重クリック / Enter 連打 / redirect 完了前の再クリック) を塞ぐ。
    // これは「必須未充足で disabled」(禁止事項8) ではなく、送信中の submit 多重防止。
    if (form.processing) return;

    form
        .transform((data) => ({
            title: data.title,
            category: data.category === "" ? null : Number(data.category),
        }))
        .post(`/projects/${projectId}/manuals/${manualId}/duplicate`, {
            // 成功時は新 manual へ redirect するが、遷移先も同一 Manuals/Show のため
            // 親の open state が生存しモーダルが残る。ここで明示的に閉じる (F-1-01)。
            onSuccess: () => {
                open = false;
            },
            onError: () => {
                /* エラーは FormField 経由で表示 (ダイアログは開いたまま) */
            },
        });
}
```

### リスク
- `onSuccess` と redirect の競合はない（Inertia は成功 visit 完了後に onSuccess を呼ぶ。open=false は
  残存した親状態を閉じるだけ）。

---

## 施策2: 送信中の多重送信ガード

### 変更箇所
- 施策1のコード内 `if (form.processing) return;`（`submit()` 冒頭）。`submit()` は confirm ボタンの
  `onclick` とフォームの `onsubmit`（Enter）双方から呼ばれるため、両経路の再入をこの 1 箇所で塞ぐ。

### 既存の disabled 挙動（変更なし・確認のみ）
- confirm ボタンは既に `loading={form.processing}`。Button atom は `disabled={disabled || loading}` のため
  **送信中のみ** disabled になる。空タイトル等の未充足では disabled にしない（禁止事項8 遵守を維持）。
- Modal は `processing={form.processing}` で送信中の Esc / 外側クリック / ✕ を抑止済み（変更なし）。

### リスク
- なし。ガードは既存 `Projects/Show.svelte`（`if (memberForm.processing) return;`）と同一流儀。

---

## 施策3: 再オープン時の defaults 追従（false→true エッジのみ）

### 意図
生存したダイアログを新マニュアル上で再度開いたとき、`useForm`(マウント時 1 回初期化)が握る旧値・旧エラーを
現在の props に揃える。**open=true 中の props 変化では入力途中の値を上書きしない**（seed 契機を
閉→開エッジに限定）。

### 変更後コード（追加分）
```ts
// 閉→開エッジでのみ現 props を再 seed する。open=true 中の props 変化では seed しない
// (入力途中の上書きを防ぐ)。ガードの不変条件は「prevOpen===false かつ open===true のときだけ seed」。
function seedFromDefaults(): void {
    form.title = defaultTitle;
    form.category = defaultCategory === null ? "" : String(defaultCategory);
    form.clearErrors();
}

let prevOpen = false;
$effect(() => {
    const isOpen = open;
    if (isOpen && !prevOpen) {
        seedFromDefaults();
    }
    prevOpen = isOpen;
});
```

### 型安全性チェック
- [x] `seedFromDefaults(): void` は明示戻り値型。`defaultCategory: number | null` の union を崩さず
  `useForm<{ title: string; category: string }>` の shape（`category` は string）と一致させる。
- [x] `form.title` / `form.category` への代入は既存 `bind:value` と同じ string 型。
- [x] effect 依存は boolean `open` を基点にし、`prevOpen` エッジ判定で「開いている間の props 変化での
  再 seed」を構造的に排除（`form` オブジェクト全体は依存に含めない）。

### リスク
- 初回マウント時（既存 prefill テストは `open:true` で直接マウント）は prevOpen=false→isOpen=true の
  エッジとして seed が 1 回走るが、`useForm` 初期値と同値のため無害（prefill テストは通る）。
- `useForm` の `defaults`/`reset()` 基準値は本コンポーネントで未使用（`form.reset()` 呼び出しなし）のため、
  値代入 + `clearErrors()` で十分（Codex Round2 指摘の確認事項に対する結論）。

### 既存コメントの整理
`useForm` 初期化コメント（L32-33 の「複製後は redirect で画面遷移するため props の再供給は起きない」）は
本施策で前提が変わるため、「マウント時 1 回初期化しつつ、閉→開エッジで seedFromDefaults により現 props へ
揃える」と実態に合わせて更新する。

---

## 施策4: vitest 追加

### 変更箇所
- `tests/js/components/features/manual/DuplicateManualDialog.test.ts`

### テスト計画
既存 3 テスト（prefill / POST 先 URL / 禁止事項8 = 未充足でも disabled にしない）は**維持**（削除・上書き
禁止=禁止事項3）。以下を追加する:

1. **複製 submit → onSuccess でダイアログが閉じる**
   - confirm クリック → `form.post` が 1 回呼ばれる（既存と重複可）。
   - `post.mock.calls[0][1].onSuccess()` を発火 → `waitFor` で
     `screen.queryByTestId("duplicate-manual-dialog")` が null（bits-ui Dialog が open=false で unmount）。
   - 補足: `reactiveUseForm` の `post` は callback を自動実行しないため、捕捉した `onSuccess` を手動発火して
     close を観測する（`SettingsSecurity.test.ts` の `lastVisitOptions().onFinish?.()` と同流儀）。

2. **送信中は二重送信されない（多重送信ガード / Enter 経路含む）**
   - `holder.last.processing = true` にセット。
   - confirm クリック **および** フォーム（`#duplicate-manual-form`）へ `submit` イベントを直接発火。
   - `expect(form.post).not.toHaveBeenCalled()`（ハンドラ冒頭ガードで 2 回目が発火しない）。
   - 併せて DOM: `waitFor` で confirm ボタンが `aria-busy="true"`（= disabled）を持つ（施策5で processing
     反応化後に観測。SettingsSecurity と同じ aria-busy 回帰固定の流儀）。

3. **再オープン(false→true)で現 props に再 seed + clearErrors**
   - `render` を `open:false` で開始 → `rerender({ ...baseProps, open:true, defaultTitle:"新タイトル のコピー",
     defaultCategory:1 })`。
   - `holder.last.title === "新タイトル のコピー"`、`holder.last.category === "1"`、
     `holder.last.clearErrors` が呼ばれたことを assert。
   - **エッジ限定の不変条件**: 続けて open=true のまま `rerender({ ..., defaultTitle:"別タイトル" })` →
     `holder.last.title` が `"新タイトル のコピー"` のまま（開いている間の props 変化では再 seed しない）。

4. **（既存維持）** 「送信ボタンは必須未充足でも disabled にしない（禁止事項8）」がそのまま green。

### DatabaseTransactions 確認
- フロント vitest のため無関係（`RefreshDatabase` / `--parallel` は PHP 側）。

---

## 施策5: test support の `processing` 反応化

### 変更箇所
- `tests/js/support/reactiveUseForm.svelte.ts`

### 意図
現行 `reactiveUseForm` の `processing` は非反応な plain boolean のため、`processing=true` にしても Button の
`disabled`/`aria-busy` が再評価されない。`errors` を `$state` + getter で反応化しているのと同じ流儀で
`processing` も反応化し、施策4 テスト2 の DOM 観測（送信中 disabled）を可能にする。

### 波及変更
- 既存консьюmer: `tests/js/pages/ManualsCreate.test.ts`（`reactiveUseForm` 利用）。`processing` を getter/setter
  化しても読み書き API は不変（`form.processing` の read / `form.processing = true` の write 双方互換）。
  同テストは `processing` へ依存しないため回帰なし（要 `pnpm test` 確認）。

### 変更後コード（該当部）
```ts
export function reactiveUseForm<TData extends Record<string, unknown>>(
  initial: TData,
  initialErrors: Record<string, string> = {},
): TData & {
  errors: Record<string, string>;
  processing: boolean;
  clearErrors: (...keys: string[]) => void;
  transform: (fn: (data: TData) => unknown) => { post: ReturnType<typeof vi.fn> };
  post: ReturnType<typeof vi.fn>;
} {
  const errors = $state<Record<string, string>>({ ...initialErrors });
  let processing = $state(false);
  const post = vi.fn();

  const form = {
    ...initial,
    get errors() {
      return errors;
    },
    get processing() {
      return processing;
    },
    set processing(value: boolean) {
      processing = value;
    },
    clearErrors: vi.fn((...keys: string[]) => {
      if (keys.length === 0) {
        for (const key of Object.keys(errors)) delete errors[key];
        return;
      }
      for (const key of keys) delete errors[key];
    }),
    transform() {
      return { post };
    },
    post,
  };

  return form;
}
```

### 型安全性チェック
- [x] 戻り値型の `processing: boolean` は不変（getter/setter で満たす）。
- [x] `...initial` に `processing` キーが含まれる可能性はない（フォーム data 型に processing は無い）ため
  getter/setter がスプレッド値と衝突しない。

### リスク
- 低。反応化は既存 `errors` と同型パターン。`ManualsCreate.test.ts` の緑を確認する。

---

## セキュリティ / 認可

- サーバ非変更。複製の認可（`canManage`）・IDOR 防御（nested route / org-scoped 解決）は既存のままで、
  bug-hunt でも「S7 IDOR/認可（実 take + 複製込み）漏れなし」と確認済み。フロントの二重送信ガードは
  サーバ側の冪等性を代替しない（本タスク対象外）。

## DESIGN.md / Atomic Design 準拠

- 既存 `Button`(atom) / `Modal`(organism) をそのまま利用。新規 SVG・hex 直書き・token 逸脱なし。
- component 階層（features/manual → organisms/atoms への単方向 import）を維持。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 1 コンポーネント + その専用テスト + 共有 test helper の小さな反応化に閉じ、他機能と独立。既存 main の DuplicateManualDialog / test に対する追記型で、standalone にする分離価値がない |
| 競合リスク | 低。`reactiveUseForm.svelte.ts` は `ManualsCreate.test.ts` と共有だが API 互換の追加のみ。同時並行で同ファイルを触る他タスクが無ければ衝突しない |

## 最終確認（使命・禁止事項）

- [x] 全施策が使命（複製フローの信頼性回復 = 思考ゼロ・編集ゼロの体験を毀損しない）に寄与。
- [x] 禁止事項8: disabled は送信中(processing)のみ。未充足では使わない（既存テストで固定）。
- [x] 禁止事項1: 施策4 で close / 多重送信抑止 / 再 seed をテスト化。
- [x] 禁止事項3（既存テスト削除・上書き禁止）: 既存 3 テストは維持し追記のみ。
- [x] サーバ / DTO / prompt / DB 非変更。


---

## 関連する現行コード

### resources/js/components/features/manual/DuplicateManualDialog.svelte（現行）

```svelte
<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import type { CategoryOption } from "@/types/manual";

    /**
     * マニュアル複製 (別名保存) ダイアログ。保存済みシナリオを新タイトル・カテゴリで複製する。
     * タイトルは「{元タイトル} のコピー」、カテゴリは元 category をプリフィルする。
     */
    interface Props {
        open: boolean;
        projectId: number;
        manualId: number;
        defaultTitle: string; // 「{元タイトル} のコピー」
        defaultCategory: number | null; // 元 category id (null = 未分類)
        categories: CategoryOption[];
    }

    let {
        open = $bindable(false),
        projectId,
        manualId,
        defaultTitle,
        defaultCategory,
        categories,
    }: Props = $props();

    // useForm はマウント時 1 回だけ初期化する (Manuals/Edit と同じ流儀。複製後は redirect で
    // 画面遷移するため props の再供給は起きない = 初期値のみ参照で足りる)。
    const form = useForm<{ title: string; category: string }>({
        title: defaultTitle,
        category: defaultCategory === null ? "" : String(defaultCategory),
    });

    // 送信本体。form 送信 (Enter) と footer ボタン onclick の双方から呼ぶ
    // (Button atom は form 属性を持たないため footer は onclick で発火させる)。
    function submit(): void {
        form
            .transform((data) => ({
                title: data.title,
                // category は Select 固定値 (option value=id 文字列 or "") のため Number 変換は安全 ('' のみ null)
                category: data.category === "" ? null : Number(data.category),
            }))
            .post(`/projects/${projectId}/manuals/${manualId}/duplicate`, {
                // 成功時は redirect で新 manual へ遷移するため onSuccess で閉じる必要はない
                onError: () => {
                    /* エラーは FormField 経由で表示 (ダイアログは開いたまま) */
                },
            });
    }

    function onFormSubmit(event: SubmitEvent): void {
        event.preventDefault();
        submit();
    }
</script>

<Modal bind:open title="動画マニュアルを複製" size="sm" processing={form.processing} testId="duplicate-manual-dialog">
    <form id="duplicate-manual-form" onsubmit={onFormSubmit} class="flex flex-col gap-4">
        <p class="text-caption text-text-secondary">
            シナリオ（カット）を引き継いだ新しい動画マニュアルを作成します。撮影データ・手順書（SOP）は引き継がれません。
        </p>
        <FormField label="タイトル" id="duplicate-title" error={form.errors.title} required>
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    bind:value={form.title}
                    error={invalid}
                    aria-describedby={describedBy}
                    oninput={() => {
                        if (form.errors.title) form.clearErrors("title");
                    }}
                />
            {/snippet}
        </FormField>
        <FormField label="カテゴリ" id="duplicate-category" error={form.errors.category}>
            {#snippet children({ id, describedBy, invalid })}
                <Select
                    {id}
                    bind:value={form.category}
                    error={invalid}
                    aria-describedby={describedBy}
                    testId="duplicate-category-select"
                >
                    <option value="">未分類</option>
                    {#each categories as category (category.id)}
                        <option value={String(category.id)}>{category.name}</option>
                    {/each}
                </Select>
            {/snippet}
        </FormField>
    </form>
    {#snippet footer()}
        <Button variant="ghost" onclick={() => (open = false)} disabled={form.processing}>キャンセル</Button>
        <Button
            variant="primary"
            loading={form.processing}
            onclick={submit}
            testId="duplicate-manual-confirm">複製する</Button
        >
    {/snippet}
</Modal>

```

### resources/js/components/atoms/Button.svelte（抜粋: disabled 挙動）

```svelte
// button モード
<button
    {type}
    disabled={disabled || loading}
    aria-busy={loading || undefined}
    {onclick}
>
// anchor モードは loading 中 handleAnchorClick で click 抑止
```

### resources/js/components/organisms/Modal.svelte（抜粋）

```svelte
<Dialog.Content
    escapeKeydownBehavior={processing ? "ignore" : "close"}
    interactOutsideBehavior={processing ? "ignore" : "close"}
    data-testid={testId}
>
// ✕ ボタンも disabled={processing}
```

### tests/js/support/reactiveUseForm.svelte.ts（現行）

```ts
import { vi } from "vitest";

/**
 * 反応的な useForm フェイク (.svelte.ts なので $state が使える)。
 *
 * fakeUseForm は errors が非反応な plain object のため「clearErrors で赤枠/文言が消える」
 * 再描画を観測できない。本フェイクは errors を $state で持ち、clearErrors がキーを削除すると
 * バインド先 (FormField の error prop) が再評価される = ユーザー体験と同じ挙動を検証できる。
 */
export function reactiveUseForm<TData extends Record<string, unknown>>(
  initial: TData,
  initialErrors: Record<string, string> = {},
): TData & {
  errors: Record<string, string>;
  processing: boolean;
  clearErrors: (...keys: string[]) => void;
  transform: (fn: (data: TData) => unknown) => { post: ReturnType<typeof vi.fn> };
  post: ReturnType<typeof vi.fn>;
} {
  const errors = $state<Record<string, string>>({ ...initialErrors });
  const post = vi.fn();

  const form = {
    ...initial,
    get errors() {
      return errors;
    },
    processing: false,
    clearErrors: vi.fn((...keys: string[]) => {
      if (keys.length === 0) {
        for (const key of Object.keys(errors)) delete errors[key];
        return;
      }
      for (const key of keys) delete errors[key];
    }),
    transform() {
      return { post };
    },
    post,
  };

  return form;
}

```

### tests/js/components/features/manual/DuplicateManualDialog.test.ts（現行）

```ts
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { reactiveUseForm } from "../../../support/reactiveUseForm.svelte";

// useForm を反応的フェイクへ差し替える (init 値を尊重するため prefill も観測できる)。
// 生成したフォームを holder に退避し、POST の呼び出し (URL) をアサートする。
const { holder } = vi.hoisted(() => ({ holder: { last: null as unknown } }));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    useForm: (init: Record<string, unknown>) => {
        const form = reactiveUseForm(init);
        holder.last = form;
        return form;
    },
}));

import DuplicateManualDialog from "@/components/features/manual/DuplicateManualDialog.svelte";

const baseProps = {
    open: true,
    projectId: 1,
    manualId: 5,
    defaultTitle: "ネジ締め作業 のコピー",
    defaultCategory: 2 as number | null,
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
};

describe("features/manual/DuplicateManualDialog", () => {
    beforeEach(() => {
        holder.last = null;
    });

    it("タイトルは defaultTitle、カテゴリは defaultCategory をプリフィルする", async () => {
        render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-dialog")).toBeInTheDocument();
        });
        const title = screen.getByLabelText(/タイトル/) as HTMLInputElement;
        expect(title.value).toBe("ネジ締め作業 のコピー");
        const category = screen.getByTestId("duplicate-category-select") as HTMLSelectElement;
        expect(category.value).toBe("2");
    });

    it("『複製する』押下で /projects/{id}/manuals/{id}/duplicate に POST する", async () => {
        render(DuplicateManualDialog, { props: baseProps });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByTestId("duplicate-manual-confirm"));

        const form = holder.last as { post: ReturnType<typeof vi.fn> };
        expect(form.post).toHaveBeenCalledTimes(1);
        expect(form.post.mock.calls[0][0]).toBe("/projects/1/manuals/5/duplicate");
    });

    it("送信ボタンは必須未充足でも disabled にしない (禁止事項8)", async () => {
        render(DuplicateManualDialog, { props: { ...baseProps, defaultTitle: "" } });

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-confirm")).not.toBeDisabled();
        });
    });
});

```

### Manuals/Show.svelte（該当箇所: bind:open）

```svelte
let duplicateDialogOpen = $state(false);
// ...
<DuplicateManualDialog
    bind:open={duplicateDialogOpen}
    projectId={project.id}
    manualId={manual.id}
    defaultTitle={`${manual.title} のコピー`}
    defaultCategory={manual.category?.id ?? null}
    {categories}
/>
```
