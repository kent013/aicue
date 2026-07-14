# 詳細設計: take-destroy-confirm

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（本施策は PHP 変更なし → 影響なし）
- **Pest** / **vitest**（本施策は vitest）
- **RefreshDatabase** + `--parallel`（PHP テスト。本施策では対象外）
- テストデータは Factory 生成（PHP。本施策では対象外）
- **DTO + JsonResource** パターン（PHP。本施策では対象外）
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` canonical）
- component 階層は単方向 import。アイコンは `@lucide/svelte` のみ
- 検証コマンド: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（全 green）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260714-1654-take-destroy-confirm/conceptual-design.md`
- 概念レビュー: `devnotes/20260714-1654-take-destroy-confirm/conceptual-review-round-1.md`（APPROVED, Round 1）
- 対応マトリクス: `devnotes/20260714-1654-take-destroy-confirm/codex-history/conceptual-review-decisions-round-1.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | テイク削除に確認ダイアログ (ConfirmDialog) を挟む | `resources/js/components/features/capture/TakeStrip.svelte` | High |
| S2 | vitest: 確認フロー (即発火しない / confirm で DELETE / cancel で未発火) を検証 + 既存 422 テストを confirm 経由へ更新 | `tests/js/components/features/capture/TakeStrip.test.ts` | High |

本施策は UI (TypeScript/Svelte) に閉じる。バックエンド・ルート・API・DTO の変更は無い。

---

## S1: テイク削除に確認ダイアログを挟む

### 変更箇所

- ファイル: `resources/js/components/features/capture/TakeStrip.svelte`
  - script 部: 削除確認用 state と `requestDelete` / `confirmDelete` の追加、`ConfirmDialog` の import
  - 削除ボタン (L187-196): `onclick` を即 `remove(take)` から `requestDelete(take, index)` へ変更
  - template 末尾: `ConfirmDialog` の追加

### 波及変更

- TypeScript 型定義: なし（`CaptureTake` / `CaptureCut` は既存のまま。追加する state は component ローカル）
- API Resource/DTO: なし（PHP 変更なし）
- テストファイル: `tests/js/components/features/capture/TakeStrip.test.ts` を更新（S2）。既存「DL 済みテイクの削除ボタンは押下時に 422 を表示する」テストは、押下 → 確認ダイアログ → 確定 → 422 の 2 ステップに更新する必要がある（現状は押下即 fetch を前提にしているため、変更しないと fail する）

### 現行コード（抜粋）

```svelte
<script lang="ts">
    import { Check, ChevronDown, ChevronUp, Download, Pencil, Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import type { CaptureCut, CaptureTake } from "@/types/capture";

    // ...
    let error = $state<string | null>(null);
    let busyTakeId = $state<number | null>(null);
    // ...
    const remove = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take), "DELETE"));
</script>

<!-- 削除ボタン (L187-196) -->
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel="削除"
    onclick={() => remove(take)}
    testId={`take-delete-${take.id}`}
>
    <Trash2 class="size-4" aria-hidden="true" />
</Button>

<!-- template 末尾 -->
<TakeCommentDialog ... />
```

### 変更後コード

script 部（追加分）:

```svelte
<script lang="ts">
    import { Check, ChevronDown, ChevronUp, Download, Pencil, Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import type { CaptureCut, CaptureTake } from "@/types/capture";

    // ... 既存 state ...

    // 削除確認ダイアログ。id をスナップショット保持し、ラベルは開いた時点の値で確定する
    // (親の再取得・並べ替えで参照内容がずれないように object 参照ではなく id + label を持つ)。
    let deleteTargetId = $state<number | null>(null);
    let deleteLabel = $state("");
    let deleteDialogOpen = $state(false);

    // 削除ボタン押下: 即 DELETE せず、対象を確定して確認ダイアログを開く。
    function requestDelete(take: CaptureTake, index: number): void {
        deleteTargetId = take.id;
        deleteLabel = `テイク ${index + 1}`;
        deleteDialogOpen = true;
    }

    // 「削除する」確定時のみ DELETE を送る。null ガードを明示 (optional 連鎖任せにしない)。
    async function confirmDelete(): Promise<void> {
        const id = deleteTargetId;
        if (id === null) return;
        const target = cut.takes.find((t) => t.id === id);
        if (target === undefined) {
            // 既に一覧から消えている等: ダイアログを閉じるだけ
            deleteDialogOpen = false;
            deleteTargetId = null;
            return;
        }
        // 成功・失敗いずれも解決後に閉じる。失敗 (422 等) は既存の take-strip-error (role="alert") に表示。
        await remove(target);
        deleteDialogOpen = false;
        deleteTargetId = null;
        deleteLabel = ""; // 再オープン時の古い文言混入を防ぐ (design-review S1 Suggestion)
    }
</script>
```

削除ボタンの onclick 差し替え:

```svelte
<Button
    variant="danger-ghost"
    size="sm"
    iconOnly
    ariaLabel="削除"
    onclick={() => requestDelete(take, index)}
    testId={`take-delete-${take.id}`}
>
    <Trash2 class="size-4" aria-hidden="true" />
</Button>
```

template 末尾に ConfirmDialog を追加（既存 `TakeCommentDialog` の隣）:

```svelte
<ConfirmDialog
    bind:open={deleteDialogOpen}
    title="テイク削除"
    message={`${deleteLabel}を削除しますか？ この操作は取り消せません。`}
    confirmLabel="削除する"
    confirmVariant="danger"
    processing={busyTakeId === deleteTargetId}
    onConfirm={confirmDelete}
    testId="take-delete-dialog"
/>
```

補足:
- `remove` / `run` は既存のまま流用（`busyTakeId` の設定・解除、`onChanged`、`error` 表示は変更しない）。
- `processing={busyTakeId === deleteTargetId}` により、DELETE 送信中は ConfirmDialog が loading 表示となり、ESC/overlay/cancel での close を Modal 側が抑止する（`ConfirmDialog` → `Modal` の既存契約）。
- 確認文言・variant・「削除する」ラベルは `pages/Manuals/Show.svelte` の動画マニュアル削除に合わせる。

### ConfirmDialog 契約の充足確認（概念レビュー Warning 対応）

`resources/js/components/organisms/ConfirmDialog.svelte` / `ConfirmDialog.types.ts` を確認済み。必要な API はすべて既存で充足し、organism の拡張は不要:

- `open: boolean`（bindable）/ `title` / `message` / `confirmLabel` / `cancelLabel`
- `confirmVariant: "primary" | "danger"` → `danger` を使用
- `processing?: boolean` → true の間 confirm を loading 表示し ESC/overlay/cancel close を抑止
- `onConfirm: () => void` → close は呼び出し側責務（本設計は `confirmDelete` 内で完了後に `open=false`）
- `onCancel?`（未使用でよい。cancel/ESC/overlay/X で閉じても DELETE は発火しない）
- `testId`

### 型安全性チェック（TypeScript）

- [x] `deleteTargetId: number | null` / `deleteLabel: string` / `deleteDialogOpen: boolean` を明示型で宣言
- [x] `confirmDelete` は `deleteTargetId` の null ガード + `cut.takes.find` の `undefined` ガードを明示（optional 連鎖任せにしない）
- [x] `requestDelete(take: CaptureTake, index: number)` 引数型を明示
- [x] `ConfirmDialogProps` に沿った props（`onConfirm: () => void`。`confirmDelete` は `Promise<void>` だが `() => void` へ代入可能 = fire-and-forget、Modal 側 `processing` で二重発火を抑止）
- [x] 新規 `any`・型緩めなし。`pnpm typecheck` green を条件とする

### テスト計画（S2 で実装）

- [x] 新規: 「削除ボタン押下では即 DELETE しない（fetch 未発火）／確認ダイアログが表示される」
- [x] 新規: 「確認ダイアログの『削除する』押下で DELETE .../takes/{id} が飛び onChanged が呼ばれる」
- [x] 新規: 「キャンセル押下では DELETE が発火しない（fetch 未呼び出し・ダイアログが閉じる）」
- [x] 更新: 既存「DL 済みテイクの削除ボタンは disabled にせず、押下時に 422 を表示」→ 押下でダイアログ表示 → 「削除する」で 422 → `take-strip-error` に表示、へ 2 ステップ化。削除ボタンが disabled でないことの確認は維持
- [x] 個別 `DatabaseTransactions` 非使用（vitest のため DB 非依存 = 該当なし）

### リスク

- 破壊操作に 1 タップ増える（UX コスト）。テイク削除の不可逆性・撮り直し困難性から許容範囲（概念レビューでも許容と判断）。
- 既存 vitest（DL 済み 422 テスト）が現行のまま fail する。S2 で必ず同一 PR 内で更新する（後方互換の並走を残さない: AGENTS.md 思考原則 #3）。
- ESC/overlay での close はデフォルトの「削除しない」に倒れる（安全側）。誤操作でのデータ喪失は起きない。
- 失敗時にダイアログを閉じてもエラーは `take-strip-error`（role="alert"、strip 直下）に残るため文脈は失われない（概念レビュー Warning への対応）。

---

## S2: vitest テスト更新

### 変更箇所

- ファイル: `tests/js/components/features/capture/TakeStrip.test.ts`

### テスト詳細

既存のテストユーティリティ（`makeTake` / `makeCut` / `jsonResponse` / `fetchMock`）をそのまま利用する。

ダイアログ内の confirm/cancel ボタンは、必ず `take-delete-dialog` を root にしたスコープ付きクエリで取得する（全体 `screen` で `getByRole("button", { name: "削除する" })` を引くと将来同名ボタン追加時に曖昧化するため。design-review S2 Warning）:

```ts
const dialog = screen.getByTestId("take-delete-dialog");
const confirmBtn = within(dialog).getByRole("button", { name: "削除する" });
const cancelBtn = within(dialog).getByRole("button", { name: "キャンセル" });
```

1. 「削除ボタン押下では即 DELETE しない」
   - `render(TakeStrip, ...)` → `fireEvent.click(getByTestId("take-delete-10"))`
   - `expect(fetchMock).not.toHaveBeenCalled()`
   - `expect(screen.getByTestId("take-delete-dialog")).toBeInTheDocument()`（確認ダイアログ表示）

2. 「確認で DELETE 発火」
   - `fetchMock.mockResolvedValueOnce(jsonResponse(200, {}))`
   - 削除ボタン click → `within(dialog)` で取得した「削除する」ボタンを click
   - `waitFor(() => expect(onChanged).toHaveBeenCalled())`
   - `expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10")`
   - `expect(fetchMock.mock.calls[0][1].method).toBe("DELETE")`

3. 「キャンセルで未発火」
   - 削除ボタン click → `within(dialog)` の「キャンセル」ボタン click
   - `await waitFor(() => expect(fetchMock).not.toHaveBeenCalled())`（非同期揺らぎ対策。design-review S2 Warning）
   - `expect(onChanged).not.toHaveBeenCalled()`
   - `expect(screen.queryByTestId("take-delete-dialog")).not.toBeInTheDocument()`（閉じたことを確認）

4. 既存「DL 済み 422」更新
   - `makeTake({ downloaded: true })` で render
   - 削除ボタンが `not.toBeDisabled()`（DESIGN.md 事前 disabled 禁止）を維持
   - 削除ボタン click → `within(dialog)` の「削除する」click → `take-strip-error` に 422 メッセージ表示 / `onChanged` 未呼び出し
   - 回帰耐性のため fetch の URL/メソッドも検証: `expect(fetchMock.mock.calls[0][0]).toBe(".../takes/10")` / `.method === "DELETE"`（design-review S2 Suggestion）

注: 422 等の失敗後の再試行は、削除ボタンの再押下 → 再度確認ダイアログ、で行う（ダイアログは失敗時も閉じるため。design-review S1 Suggestion で明文化）。

### 型安全性チェック

- [x] `Partial<CaptureTake>` など既存ヘルパの型を踏襲
- [x] 追加の `any` なし

### リスク

- ダイアログ内ボタンのクエリが文言依存になる。`within(dialog)` + role/name で堅牢に取得する（全体 `screen` 引きは避ける）。文言変更時は本テストも追従する必要がある。
- 「未発火」系アサーションは `await waitFor(...)` で非同期揺らぎに備える。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 単一コンポーネント (`TakeStrip.svelte`) + その vitest に閉じた小規模 UI 変更。既存 organism 再利用のみで新規モデル・API・型定義の追加なし。他施策との依存もない |
| 競合リスク | 低。`TakeStrip.svelte` / `TakeStrip.test.ts` のみ触る。同ファイルを触る他の未マージ作業が無ければ競合しない |
