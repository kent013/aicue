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

### リスク / 不変条件
- **不変条件で記述**（タイミング断定を避ける）: 「複製成功（`onSuccess`）時は `open = false` を必ず実行し、
  同一 `Manuals/Show` コンポーネント再利用時にモーダルが開きっぱなしになることを防ぐ」。`onSuccess` が
  redirect と競合することはない（open=false は残存した親状態を閉じるだけ）。Inertia 実装差異・将来変更に
  依存しない不変条件として扱う。

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
// (入力途中の上書きを防ぐ)。代入対象は useForm の shape と一致する title / category の
// 2 キーのみ (他キー拡張時の事故防止)。
function seedFromDefaults(): void {
    form.title = defaultTitle;
    form.category = defaultCategory === null ? "" : String(defaultCategory);
    form.clearErrors();
}

// prevOpen は非 reactive なローカル変数（初回 open で同期）。$effect の依存は open だけに限定し、
// prevOpen の読み書きを追跡対象にしない（$state 化すると effect が自己依存し余分に再実行されるため避ける）。
let prevOpen = open;
$effect(() => {
    const isOpen = open;
    if (isOpen && !prevOpen) {
        seedFromDefaults();
    }
    prevOpen = isOpen;
});
```

> 注: `prevOpen` を初回 `open` で同期するため、初回マウントが `open:true`（既存 prefill テスト）でも
> `!prevOpen===false` となり seed は走らない（useForm 初期値をそのまま使う）。実運用のマウントは `open:false`
> 始点で、ユーザー操作の false→true で初めて seed される。依存は reactive な `open` のみ。

### 型安全性チェック
- [x] `seedFromDefaults(): void` は明示戻り値型。`defaultCategory: number | null` の union を崩さず
  `useForm<{ title: string; category: string }>` の shape（`category` は string）と一致させる。
- [x] `form.title` / `form.category` への代入は既存 `bind:value` と同じ string 型。
- [x] effect 依存は boolean `open` を基点にし、`prevOpen` エッジ判定で「開いている間の props 変化での
  再 seed」を構造的に排除（`form` オブジェクト全体は依存に含めない）。

### リスク
- 初回マウント時は `prevOpen = open`（非 reactive ローカル）で同期されるため seed は走らない（`open:true`
  直接マウントの既存 prefill テストでも `!prevOpen===false` で seed されず、`useForm` 初期値がそのまま使われる
  → prefill テスト維持）。`prevOpen` を `$state` 化しないことで effect の自己依存・余分な再実行も避ける。
- `useForm` の `defaults`/`reset()` 基準値は本コンポーネントで未使用（`form.reset()` 呼び出しなし）のため、
  値代入 + `clearErrors()` で十分（conceptual Round2 指摘の確認事項に対する結論）。

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

2a. **関数ガード（送信中は `submit()` 冒頭で return）** — UI 抑止と分離して検証
   - `holder.last.processing = true` にセット。
   - フォーム（`#duplicate-manual-form`）へ `submit` イベントを直接 `fireEvent.submit` で発火（ボタン
     disabled に依らず handler を直接叩く経路 = Enter 相当）。
   - `expect(holder.last.post).not.toHaveBeenCalled()`（ハンドラ冒頭 `if (form.processing) return` で発火しない）。
   - 注: ボタンクリック経路は disabled により抑止されるため、この関数ガードは submit イベント経路で検証し、
     「UI 抑止」ではなく「関数ガード」であることを切り分ける（Codex 指摘の分離）。

2b. **UI ガード（送信中は confirm ボタンが disabled / aria-busy）** — 施策5で processing 反応化後に観測
   - `holder.last.processing = true` にセット → `waitFor` で `duplicate-manual-confirm` が
     `aria-busy="true"`（Button atom が loading 中に立てる）かつ `toBeDisabled()`。
   - `SettingsSecurity.test.ts` の aria-busy 回帰固定と同じ流儀。

3. **再オープン(false→true)で現 props に再 seed + clearErrors + エラーDOM消滅**
   - 偽陽性を避けるため、**エラー文言が一度 DOM 表示されたことを確認してから消滅を観測**する（Codex 指摘）:
     1. `render` を `open:true` で開始し、`holder.last.errors.title = "サーバエラー"` を注入 →
        `waitFor` で `screen.getByText("サーバエラー")` が表示されることを確認。
     2. `await rerender({ ...baseProps, open:false })` し、`waitFor` で
        `queryByTestId("duplicate-manual-dialog")` が unmount されたことを確認してから再オープンする
        （Svelte の更新が同一 tick でまとめられ false 状態を effect が観測できない事態を防ぐ = Codex 補足）。
     3. `await rerender({ ...baseProps, open:true, defaultTitle:"新タイトル のコピー", defaultCategory:1 })`
        （false→true エッジで seedFromDefaults 発火）。
     4. `holder.last.title === "新タイトル のコピー"`、`holder.last.category === "1"`、
        `holder.last.clearErrors` が呼ばれ、`screen.queryByText("サーバエラー")` が null。
   - **エッジ限定の不変条件**: 続けて open=true のまま `rerender({ ..., defaultTitle:"別タイトル" })` →
     `holder.last.title` が `"新タイトル のコピー"` のまま（開いている間の props 変化では再 seed しない）。

4. **onSuccess close（施策1の直接検証）**
   - confirm クリック → `holder.last.post` 1 回 → 捕捉した `post.mock.calls[0][1].onSuccess()` を発火 →
     `waitFor` で `screen.queryByTestId("duplicate-manual-dialog")` が null（open=false で unmount）。

5. **（既存維持）** 「送信ボタンは必須未充足でも disabled にしない（禁止事項8）」がそのまま green。

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
- 既存 consumer: `tests/js/pages/ManualsCreate.test.ts`（`reactiveUseForm` 利用）。`processing` を getter/setter
  化しても読み書き API は不変（`form.processing` の read / `form.processing = true` の write 双方互換）。
  同テストは `processing` へ依存しないため回帰なし（要 `pnpm test` 確認）。

### 変更後コード（該当部）
型ガード: `processing` / `errors` を data 型に持ち込めないよう generic 制約で衝突をコンパイル時に禁止する
（`...initial` と accessor の衝突を型で防ぐ）。

```ts
export function reactiveUseForm<
  TData extends Record<string, unknown> & { processing?: never; errors?: never },
>(
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
