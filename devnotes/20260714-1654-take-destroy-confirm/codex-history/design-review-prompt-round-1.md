# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

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
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / vitest
- DTO + JsonResource パターン / Laratrust RBAC

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（本施策はPHP変更なし）
4. テスト計画の網羅性
5. DTO/JsonResource パターンの遵守（本施策はPHP変更なし）
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション）
10. DESIGN.md準拠（design token 経由か、hex直書きを増やさないか）
11. Atomic Design準拠（features→organisms の単方向import、Lucideアイコン前提、SVG直書き新設なし）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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

1. 「削除ボタン押下では即 DELETE しない」
   - `render(TakeStrip, ...)` → `fireEvent.click(getByTestId("take-delete-10"))`
   - `expect(fetchMock).not.toHaveBeenCalled()`
   - `expect(getByTestId("take-delete-dialog")).toBeInTheDocument()`（確認ダイアログ表示）

2. 「確認で DELETE 発火」
   - `fetchMock.mockResolvedValueOnce(jsonResponse(200, {}))`
   - 削除ボタン click → ダイアログの「削除する」ボタン（`take-delete-dialog` 内の confirm、`getByRole("button", { name: "削除する" })` 相当）を click
   - `waitFor(() => expect(onChanged).toHaveBeenCalled())`
   - `expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10")`
   - `expect(fetchMock.mock.calls[0][1].method).toBe("DELETE")`

3. 「キャンセルで未発火」
   - 削除ボタン click → 「キャンセル」ボタン click
   - `expect(fetchMock).not.toHaveBeenCalled()`
   - `expect(onChanged).not.toHaveBeenCalled()`

4. 既存「DL 済み 422」更新
   - `makeTake({ downloaded: true })` で render
   - 削除ボタンが `not.toBeDisabled()`（DESIGN.md 事前 disabled 禁止）を維持
   - 削除ボタン click → 「削除する」click → `take-strip-error` に 422 メッセージ表示 / `onChanged` 未呼び出し

confirm/cancel ボタンの取得は、`take-delete-dialog` を root として `within(dialog).getByRole("button", { name: "削除する" })` / `{ name: "キャンセル" }` を用いる（`ConfirmDialog` の footer が Button atom を描画する）。

### 型安全性チェック

- [x] `Partial<CaptureTake>` など既存ヘルパの型を踏襲
- [x] 追加の `any` なし

### リスク

- ダイアログ内ボタンのクエリが文言依存になる。`within` + role/name で堅牢に取得する。文言変更時は本テストも追従する必要がある。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 単一コンポーネント (`TakeStrip.svelte`) + その vitest に閉じた小規模 UI 変更。既存 organism 再利用のみで新規モデル・API・型定義の追加なし。他施策との依存もない |
| 競合リスク | 低。`TakeStrip.svelte` / `TakeStrip.test.ts` のみ触る。同ファイルを触る他の未マージ作業が無ければ競合しない |


## 関連する現行コード

### resources/js/components/features/capture/TakeStrip.svelte（現行）
```svelte
<script lang="ts">
    import { Check, ChevronDown, ChevronUp, Download, Pencil, Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import type { CaptureCut, CaptureTake } from "@/types/capture";

    /**
     * カット配下のテイク一覧 (先頭 = 採用候補。doc/05)。
     * 採用・並べ替え・コメント・削除・DL 済み ACK を XHR で行う。
     * 失敗 (DL 済み削除不可・処理中等) は押下時にエラー表示する (disabled 禁止)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        cut: CaptureCut;
        onChanged: () => void;
    }

    let { projectId, manualId, cut, onChanged }: Props = $props();

    let error = $state<string | null>(null);
    let busyTakeId = $state<number | null>(null);
    let commentTarget = $state<CaptureTake | null>(null);
    let commentDialogOpen = $state(false);
    let commentSaving = $state(false);
    let commentError = $state<string | null>(null);

    function takeUrl(take: CaptureTake, suffix = ""): string {
        return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}${suffix}`;
    }

    async function run(take: CaptureTake, action: () => Promise<Response>): Promise<void> {
        error = null;
        busyTakeId = take.id;
        try {
            const response = await action();
            if (!response.ok) {
                error = await extractErrorMessage(response);
                return;
            }
            onChanged();
        } catch {
            error = "通信に失敗しました。ネットワークを確認してください。";
        } finally {
            busyTakeId = null;
        }
    }

    const adopt = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take, "/adopt"), "POST"));
    const remove = (take: CaptureTake) => run(take, () => captureJson(takeUrl(take), "DELETE"));
    const move = (take: CaptureTake, position: number) =>
        run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));

    function openComment(take: CaptureTake): void {
        commentTarget = take;
        commentError = null;
        commentDialogOpen = true;
    }

    async function saveComment(comment: string): Promise<void> {
        if (commentTarget === null) return;
        commentSaving = true;
        commentError = null;
        try {
            const response = await captureJson(takeUrl(commentTarget), "PATCH", {
                comment: comment === "" ? null : comment,
            });
            if (!response.ok) {
                commentError = await extractErrorMessage(response);
                return;
            }
            commentDialogOpen = false;
            onChanged();
        } catch {
            commentError = "通信に失敗しました。";
        } finally {
            commentSaving = false;
        }
    }

    /** 採用テイクの DL 完了 ACK (概念設計 D6): DL 開始後にトークンを送る */
    async function downloadAndAck(take: CaptureTake): Promise<void> {
        if (take.playback_url === null || take.download_ack_token === null) {
            error = "この端末からダウンロードできるのは採用テイクのみです。";
            return;
        }
        window.open(take.playback_url, "_blank", "noopener");
        await run(take, () =>
            captureJson(takeUrl(take, "/downloaded"), "POST", { ack_token: take.download_ack_token }),
        );
    }

    function sizeLabel(bytes: number): string {
        if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }
</script>

<div class="flex flex-col gap-2" data-testid={`take-strip-${cut.id}`}>
    {#if cut.takes.length === 0}
        <p class="text-caption text-text-secondary">テイクはまだありません。撮影してください。</p>
    {/if}
    {#each cut.takes as take, index (take.id)}
        <div
            class="flex items-center gap-2 rounded-md border border-border bg-surface px-3 py-2 {take.downloaded
                ? 'border-border-strong'
                : ''}"
            data-testid={`take-item-${take.id}`}
        >
            <div class="flex flex-col gap-1">
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="上へ"
                    onclick={() => move(take, index - 1)}
                    testId={`take-up-${take.id}`}
                >
                    <ChevronUp class="size-4" aria-hidden="true" />
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="下へ"
                    onclick={() => move(take, index + 1)}
                    testId={`take-down-${take.id}`}
                >
                    <ChevronDown class="size-4" aria-hidden="true" />
                </Button>
            </div>
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 text-body">
                    テイク {index + 1}
                    {#if cut.adopted_take_id === take.id}
                        <Badge tone="success" testId={`take-adopted-${take.id}`}>採用中</Badge>
                    {/if}
                    {#if take.downloaded}
                        <Badge tone="neutral">DL 済み</Badge>
                    {/if}
                </p>
                <p class="text-caption text-text-secondary">
                    {sizeLabel(take.size_bytes)}
                    {#if take.duration_ms !== null}
                        ・{Math.round(take.duration_ms / 1000)} 秒
                    {/if}
                    {#if take.comment}
                        ・{take.comment}
                    {/if}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <Button
                    variant="neutral"
                    size="sm"
                    loading={busyTakeId === take.id}
                    onclick={() => adopt(take)}
                    testId={`take-adopt-${take.id}`}
                >
                    <Check class="size-4" aria-hidden="true" />
                    採用
                </Button>
                {#if take.playback_url !== null}
                    <Button
                        variant="ghost"
                        size="sm"
                        iconOnly
                        ariaLabel="ダウンロード"
                        onclick={() => downloadAndAck(take)}
                        testId={`take-download-${take.id}`}
                    >
                        <Download class="size-4" aria-hidden="true" />
                    </Button>
                {/if}
                <Button
                    variant="ghost"
                    size="sm"
                    iconOnly
                    ariaLabel="コメント"
                    onclick={() => openComment(take)}
                    testId={`take-comment-${take.id}`}
                >
                    <Pencil class="size-4" aria-hidden="true" />
                </Button>
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
            </div>
        </div>
    {/each}
    {#if error}
        <p class="text-caption text-danger" role="alert" data-testid="take-strip-error">{error}</p>
    {/if}
</div>

<TakeCommentDialog
    bind:open={commentDialogOpen}
    initial={commentTarget?.comment ?? ""}
    saving={commentSaving}
    error={commentError}
    onSave={saveComment}
/>

```

### resources/js/components/organisms/ConfirmDialog.svelte（再利用元）
```svelte
<script lang="ts">
    import Button from "@/components/atoms/Button.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import type { ConfirmDialogProps } from "./ConfirmDialog.types";

    let {
        open = $bindable(false),
        title,
        message,
        confirmLabel = "確認",
        cancelLabel = "キャンセル",
        confirmVariant = "primary",
        processing = false,
        onConfirm,
        onCancel,
        testId,
    }: ConfirmDialogProps = $props();

    // Modal 側 (ESC / overlay / X) で閉じた時も onCancel を発火させるための function binding。
    // processing 中の ESC / overlay 抑止は Modal が担う。
    function setOpen(next: boolean): void {
        open = next;
        if (!next) {
            onCancel?.();
        }
    }

    // cancel ボタンは自前で閉じる (setOpen を経由しないため onCancel をここで呼ぶ)
    function handleCancel(): void {
        if (processing) return;
        open = false;
        onCancel?.();
    }
</script>

<Modal bind:open={() => open, setOpen} {title} size="sm" {processing} {testId}>
    <p>{message}</p>
    {#snippet footer()}
        <Button variant="ghost" onclick={handleCancel} disabled={processing}>
            {cancelLabel}
        </Button>
        <Button variant={confirmVariant} onclick={() => onConfirm()} loading={processing}>
            {confirmLabel}
        </Button>
    {/snippet}
</Modal>

```

### resources/js/components/organisms/ConfirmDialog.types.ts
```ts
/**
 * ConfirmDialog organism の仕様の真実。意味論は DESIGN.md §Components > ConfirmDialog を参照。
 */

/**
 * 確認ボタンの variant。2 値に限定する:
 * - primary: 通常の確認 (可逆な操作)
 * - danger: irreversible / destructive な操作 (削除・revoke 等。DESIGN.md §色の意味的割り当てルール)
 */
export type ConfirmVariant = "primary" | "danger";

export interface ConfirmDialogProps {
    /** 開閉状態 (bindable)。呼び出し側が $state で保持し bind:open する */
    open: boolean;
    title: string;
    /** 確認メッセージ本文 */
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    /** 既定 primary。irreversible / destructive な操作は danger を指定する */
    confirmVariant?: ConfirmVariant;
    /** true の間 confirm を loading 表示し、ESC / overlay / cancel での close を抑止する */
    processing?: boolean;
    /** 確認時に呼ばれる。close は呼び出し側の責務 (処理完了後に open=false にする) */
    onConfirm: () => void;
    /** キャンセル・ESC・overlay・X での close 時に呼ばれる */
    onCancel?: () => void;
    testId?: string;
}

```

### tests/js/components/features/capture/TakeStrip.test.ts（現行・更新対象）
```ts
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
import type { CaptureCut, CaptureTake } from "@/types/capture";

/*
 * TakeStrip: 採用・削除・DL 済み ACK。
 * - ボタンは事前条件で disabled にしない (押下時にサーバの 422 メッセージを表示。DESIGN.md)
 * - 採用テイクの DL 完了で download_ack_token を POST .../downloaded へ送る
 */

const fetchMock = vi.fn();

function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
    return {
        id: 10,
        client_take_id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
        status: "ready",
        size_bytes: 1024 * 1024,
        duration_ms: 4000,
        comment: null,
        captured_at: null,
        sort_order: 0,
        downloaded: false,
        playback_url: null,
        download_ack_token: null,
        ...overrides,
    };
}

function makeCut(takes: CaptureTake[], adopted: number | null = null): CaptureCut {
    return {
        id: 3,
        type: "step",
        parent_cut_id: null,
        scene: "作業台を準備する",
        shot_type: "hiki",
        shooting_point: null,
        narration: "作業台の準備を行います",
        subtitle_primary: null,
        subtitle_secondary: "作業台を準備",
        adopted_take_id: adopted,
        takes,
    };
}

function jsonResponse(status: number, body: unknown = {}): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}

beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock);
    vi.stubGlobal("open", vi.fn());
    document.cookie = "XSRF-TOKEN=test-token";
});

afterEach(() => {
    cleanup();
    fetchMock.mockReset();
    vi.unstubAllGlobals();
});

describe("TakeStrip", () => {
    it("採用ボタン押下で POST .../adopt が飛び onChanged が呼ばれる", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {}));
        const onChanged = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged,
        });

        await fireEvent.click(screen.getByTestId("take-adopt-10"));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10/adopt");
        expect(fetchMock.mock.calls[0][1].method).toBe("POST");
    });

    it("DL 済みテイクの削除ボタンは disabled にせず、押下時に 422 メッセージを表示する", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(422, { message: "ダウンロード済みのテイクは削除できません。" }),
        );
        const onChanged = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake({ downloaded: true })]),
            onChanged,
        });

        const deleteButton = screen.getByTestId("take-delete-10");
        expect(deleteButton).not.toBeDisabled(); // 事前条件 disabled 禁止 (DESIGN.md)

        await fireEvent.click(deleteButton);

        await waitFor(() =>
            expect(screen.getByTestId("take-strip-error").textContent).toContain(
                "ダウンロード済みのテイクは削除できません",
            ),
        );
        expect(onChanged).not.toHaveBeenCalled();
    });

    it("採用テイクの DL ボタンで playback_url を開き、ACK トークンを POST .../downloaded へ送る", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {}));
        const onChanged = vi.fn();
        const take = makeTake({
            playback_url: "https://s3.example.test/signed",
            download_ack_token: "sealed-ack-token",
        });
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([take], take.id),
            onChanged,
        });

        await fireEvent.click(screen.getByTestId("take-download-10"));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect(window.open).toHaveBeenCalledWith("https://s3.example.test/signed", "_blank", "noopener");
        expect(fetchMock.mock.calls[0][0]).toBe(
            "/app/projects/1/manuals/2/cuts/3/takes/10/downloaded",
        );
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            ack_token: "sealed-ack-token",
        });
    });

    it("採用中バッジと DL 済みバッジを表示する", () => {
        const take = makeTake({ downloaded: true });
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([take], take.id),
            onChanged: vi.fn(),
        });

        expect(screen.getByTestId("take-adopted-10")).toBeInTheDocument();
        expect(screen.getByText("DL 済み")).toBeInTheDocument();
    });
});

```
