# 詳細設計レビュー依頼 (Round 1): missing-operation-ui

【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 (AGENTS.md)】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(招待送信等は `back()->with(...)` で完結させる)
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
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【補足】
- 本設計は bug-hunt finding F-10 (リカバリコード再生成 UI 欠落) / F-12 (オーナー移譲 UI 不可視) への UI 限定対応。概念設計は gpt-5.4 レビューで APPROVED 済み (devnotes/20260712-0949-missing-operation-ui/conceptual-design.md)。
- Vitest のテスト規約: tests/js/setup.ts に animate/ResizeObserver スタブあり。Inertia v3 useForm は plain object。既存のモックパターンは tests/js/pages/PurchaseTickets.test.ts (router.post を vi.mock)。

---

## 詳細設計書

# 詳細設計: missing-operation-ui (F-10 リカバリコード再生成 / F-12 オーナー移譲)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本設計はバックエンド変更なしのため影響なし
- **Pest**テストフレームワーク（`composer test`）— 既存テストの回帰確認のみ
- **RefreshDatabase** + `--parallel` 並列実行（個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（本設計は Vitest のみのため該当なし）
- **DTO + JsonResource** パターン（バックエンド変更なし）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロント固有: DS token/ramp のみ (ds-purity)、atomic-import-graph の単方向 import、
  アイコンは `@lucide/svelte` のみ、Inertia v3 useForm は plain object ($form 構文禁止)

## 概念設計リファレンス

[devnotes/20260712-0949-missing-operation-ui/conceptual-design.md](./conceptual-design.md)
（conceptual-review Round 2 で APPROVED。持ち越し事項: `tick()` 後 focus / 選択値の候補実在検証 /
string↔number 変換地点の明示 / 失敗分岐の Vitest 固定）

## 前提事実（コード調査結果）

- `POST /user/two-factor-recovery-codes` = Fortify 登録の `two-factor.regenerate-recovery-codes`。
  成功時は 302 back (Inertia visit で処理可能)。`GET /user/two-factor-recovery-codes` は
  `string[]` の JSON を返す (既存 `loadRecoveryCodes()` が使用中)。
- `POST /organizations/{organization:slug}/transfer-ownership` = `organizations.transfer-ownership`
  (routes/web.php L241-243)。**`recent-auth` middleware 付き**。Controller は
  `Gate::authorize('transferOwnership')` (= owner のみ、`OrganizationPolicy` L63-66)。
  Service (`OrganizationMembershipService::transferOwnership`) が非メンバー/自己移譲を
  ValidationException で拒否。成功時 `back()->with('success', 'オーナーを移譲しました')`
  → 既存の flash → toast 表示機構で成功フィードバックが出る。
- `Organizations/Settings.svelte` にはオーナー移譲 UI (DangerZone + select + ConfirmDialog +
  `withRecentAuth` precheck + `RecentAuthModal`) が**既に存在** (L230-268)。
  問題は表示条件 `{#if isOwner && transferCandidates.length > 0}` のみ
  (候補 0 人で無言非表示 = F-12 の観測原因)。
- **F-12 は「UI 未実装」ではない**ため、shard-report の「operations.md に未実装フラグを
  付ける」提案は不要。operations.md は変更しない (finding への回答として本設計に記録)。
- `Settings/Security.svelte` には再生成ボタンが存在しない (F-10 は真の UI 欠落)。
- Fortify 2FA 管理エンドポイントに step-up (recent-auth) は未配線
  (config/fortify.php `confirmPassword => false` + TODO(template))。後付け配線は
  バックエンド変更のためスコープ外。UI は既存「2FA 無効化」と同水準 (ConfirmDialog 必須) に揃える。
- 部品 API (確認済み): `Button` は `loading`/`variant`/`testId` を持つ。`ConfirmDialog` は
  `open ($bindable)`/`title`/`message`/`confirmLabel`/`confirmVariant`/`processing`/`onConfirm`/`testId`。
  `addToast(type: "success"|"info"|"warning"|"error", message: string)`。
  `DangerZone` は `title`/`description`/`testId`/`children` の presentational molecule。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | リカバリコード再生成導線の追加 (F-10) | `resources/js/pages/Settings/Security.svelte` | High |
| 2 | 施策1の Vitest (新規) | `tests/js/pages/SettingsSecurity.test.ts` | High |
| 3 | オーナー移譲セクションの常時表示化 (F-12) | `resources/js/pages/Organizations/Settings.svelte` | High |
| 4 | 施策3の Vitest (更新) | `tests/js/pages/OrganizationsSettings.test.ts` | High |

実装順序は 1→2→3→4 だが相互依存はない (テストファーストで 2/4 の fail 確認 → 1/3 実装でも可。
UI 記述量が多いためテスト先行を推奨)。

---

## 施策 1: リカバリコード再生成導線の追加 (F-10)

### 変更箇所

- ファイル: `resources/js/pages/Settings/Security.svelte`
  - `<script>`: L1-14 (import に `tick` 追加)、L36-75 (状態 + `loadRecoveryCodes` の返り値化)、
    L110-129 の後 (再生成用の状態・関数を追加)
  - テンプレート: L154-192 (2FA 有効ブロック)、L272-281 の後 (再生成 ConfirmDialog 追加)

### 波及変更

- TypeScript 型定義: なし (ページ内ローカル状態のみ。`string[]` / `boolean` / `HTMLDivElement | null`)
- API Resource/DTO: なし (バックエンド変更なし)
- テストファイル: `tests/js/pages/SettingsSecurity.test.ts` を新規作成 (施策 2)

### 現行コード (抜粋)

```svelte
<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    // ...
    let loadingRecoveryCodes = $state(false);

    async function loadRecoveryCodes(): Promise<void> {
        loadingRecoveryCodes = true;
        try {
            recoveryCodes = await fetchJson<string[]>("/user/two-factor-recovery-codes");
        } catch {
            addToast("error", "リカバリコードの取得に失敗しました。");
        } finally {
            loadingRecoveryCodes = false;
        }
    }
</script>

{#if twoFactorEnabled}
    <div class="mt-4 flex flex-col gap-4">
        {#if recoveryCodes.length > 0}
            <div class="rounded-md border border-border bg-neutral p-4">
                <p class="text-caption text-text-secondary">
                    リカバリコードは安全な場所に保管してください。各コードは一度だけ使えます。
                </p>
                <ul
                    class="mt-2 grid grid-cols-2 gap-1 text-body font-mono"
                    data-testid="recovery-codes"
                >
                    {#each recoveryCodes as code (code)}
                        <li>{code}</li>
                    {/each}
                </ul>
            </div>
        {:else}
            <div>
                <Button
                    variant="ghost"
                    onclick={() => void loadRecoveryCodes()}
                    loading={loadingRecoveryCodes}
                >
                    リカバリコードを表示
                </Button>
            </div>
        {/if}
        <div>
            <Button variant="danger-outline" onclick={() => { disableDialogOpen = true; }}
                testId="disable-two-factor-button">
                2要素認証を無効化
            </Button>
        </div>
    </div>
{:else if confirming}
```

### 変更後コード

`<script>` 側 (追加/変更分):

```svelte
<script lang="ts">
    import { tick } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    // ... (既存 import は変更なし)

    let loadingRecoveryCodes = $state(false);
    /** 新コード一覧へのフォーカス移動用 (再生成成功時に再保管を促す) */
    let recoveryCodesPanel = $state<HTMLDivElement | null>(null);

    /**
     * リカバリコードを取得する。成否を返し、失敗時の文言は呼び出し側が文脈に応じて出す
     * (通常表示: 単純な取得失敗 / 再生成直後: 旧コード失効済みの注意)。
     */
    async function loadRecoveryCodes(): Promise<boolean> {
        loadingRecoveryCodes = true;
        try {
            recoveryCodes = await fetchJson<string[]>("/user/two-factor-recovery-codes");
            return true;
        } catch {
            return false;
        } finally {
            loadingRecoveryCodes = false;
        }
    }

    /** 「リカバリコードを表示」押下時 (既存挙動の維持: 失敗は取得失敗トースト) */
    async function showRecoveryCodes(): Promise<void> {
        if (!(await loadRecoveryCodes())) {
            addToast("error", "リカバリコードの取得に失敗しました。");
        }
    }

    /* ---- リカバリコード再生成 (F-10) ----
       POST 成功 = 旧コードは既に失効。表示中の旧コードを即クリアしてから GET で
       新コードを取得し、成功時のみ成功トースト + 一覧へフォーカス (再保管を促す)。
       GET 失敗時は「旧コードは無効」を明示し、既存の「リカバリコードを表示」ボタンが
       再試行導線になる (recoveryCodes が空に戻るため自然に表示される)。 */
    let regenerateDialogOpen = $state(false);
    let regenerating = $state(false);

    function regenerateRecoveryCodes(): void {
        router.post(
            "/user/two-factor-recovery-codes",
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    regenerating = true;
                },
                onSuccess: () => {
                    regenerateDialogOpen = false;
                    // 旧コードは失効済み。誤保管を防ぐため画面から即クリアする
                    recoveryCodes = [];
                    void (async () => {
                        if (await loadRecoveryCodes()) {
                            addToast(
                                "success",
                                "リカバリコードを再生成しました。新しいコードを保管してください。",
                            );
                            await tick();
                            recoveryCodesPanel?.focus();
                        } else {
                            addToast(
                                "error",
                                "新しいコードの取得に失敗しました。以前のコードは既に無効です。「リカバリコードを表示」から再取得してください。",
                            );
                        }
                    })();
                },
                onError: () => {
                    regenerateDialogOpen = false;
                    addToast("error", "リカバリコードの再生成に失敗しました。");
                },
                onFinish: () => {
                    regenerating = false;
                },
            },
        );
    }
</script>
```

テンプレート側 (2FA 有効ブロック。`tabindex="-1"` は programmatic focus 用):

```svelte
{#if twoFactorEnabled}
    <div class="mt-4 flex flex-col gap-4">
        {#if recoveryCodes.length > 0}
            <div
                class="rounded-md border border-border bg-neutral p-4"
                tabindex="-1"
                bind:this={recoveryCodesPanel}
                data-testid="recovery-codes-panel"
            >
                <p class="text-caption text-text-secondary">
                    リカバリコードは安全な場所に保管してください。各コードは一度だけ使えます。
                </p>
                <ul
                    class="mt-2 grid grid-cols-2 gap-1 text-body font-mono"
                    data-testid="recovery-codes"
                >
                    {#each recoveryCodes as code (code)}
                        <li>{code}</li>
                    {/each}
                </ul>
            </div>
        {:else}
            <div>
                <Button
                    variant="ghost"
                    onclick={() => void showRecoveryCodes()}
                    loading={loadingRecoveryCodes}
                    testId="show-recovery-codes-button"
                >
                    リカバリコードを表示
                </Button>
            </div>
        {/if}
        <div class="flex flex-wrap gap-3">
            <Button
                variant="ghost"
                onclick={() => {
                    regenerateDialogOpen = true;
                }}
                testId="regenerate-recovery-codes-button"
            >
                リカバリコードを再生成
            </Button>
            <Button
                variant="danger-outline"
                onclick={() => {
                    disableDialogOpen = true;
                }}
                testId="disable-two-factor-button"
            >
                2要素認証を無効化
            </Button>
        </div>
    </div>
{:else if confirming}
```

再生成 ConfirmDialog (既存 disable 用ダイアログの直後に追加):

```svelte
<ConfirmDialog
    bind:open={regenerateDialogOpen}
    title="リカバリコードの再生成"
    message="リカバリコードを再生成しますか？ 既存のリカバリコードは直ちにすべて失効します。新しいコードを必ず保管し直してください。"
    confirmLabel="再生成する"
    confirmVariant="danger"
    processing={regenerating}
    onConfirm={regenerateRecoveryCodes}
    testId="regenerate-recovery-codes-dialog"
/>
```

### 設計判断メモ

- **旧コードのクリアは `onSuccess` で行う**: POST が失敗した場合は旧コードはまだ有効なため
  クリアしない (誤って「無効になった」と見せない)。
- **成功トーストは GET 成功時のみ**: POST 成功 + GET 失敗で成功を報じると
  「新コードを確認できないのに成功」という誤案内になる (conceptual-review Round 1 対応)。
- **`showRecoveryCodes` への分離**: 失敗文言が文脈依存 (通常表示 vs 再生成直後) のため、
  `loadRecoveryCodes` は成否 `boolean` を返す純粋な取得関数に変更し、トーストは呼び出し側が出す。
- **フォーカス移動は `await tick()` 後**: `recoveryCodes` 反映 → DOM 生成後に
  `recoveryCodesPanel?.focus()` (conceptual-review Round 2 持ち越し対応)。
  `tabindex="-1"` は負値のため svelte a11y 警告 (`a11y_no_noninteractive_tabindex`) の対象外。
- **ボタン variant**: 再生成は「復旧手段の再発行」であり主 destructive 操作ではないため
  `ghost` + ダイアログ `confirmVariant="danger"` (失効の重みはダイアログで表現)。
  無効化 (`danger-outline`) との視覚的序列を保つ。
- disabled は使わない。多重送信防止は既存パターンどおり `processing`/`loading`
  (禁止事項 8 の「必須条件未充足 disabled」とは別物)。
- recent-auth: Fortify 側に step-up が未配線のため UI にも precheck を入れない
  (サーバが要求しないのに UI だけ要求すると偽のセキュリティ表示になる)。
  後付け配線は config/fortify.php の既存 TODO(template) の課題。

### PHPStan適合チェック

- [x] バックエンド変更なし (PHPStan 対象外)
- [x] TS: `loadRecoveryCodes(): Promise<boolean>` / `recoveryCodesPanel: HTMLDivElement | null` /
      `recoveryCodes: string[]` と型を明示。`any` 不使用
- [x] `fetchJson<string[]>` の generics は既存実装を踏襲

### テスト計画

施策 2 (`tests/js/pages/SettingsSecurity.test.ts`) に記載。

### リスク

- `loadRecoveryCodes` の返り値変更は同ファイル内 2 呼び出し (表示/再生成) のみで完結し、
  外部への波及なし。
- Fortify の regenerate POST は 302 back を返すため Inertia の `onSuccess` が発火する
  (既存 enable/disable と同一パターンで実績あり)。
- jsdom 非対応 API は使わない (`focus()` は jsdom 実装あり)。

---

## 施策 2: SettingsSecurity.test.ts (新規)

### 変更箇所

- ファイル: `tests/js/pages/SettingsSecurity.test.ts` (新規)

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 本体 (新規)

### 変更後コード (全文設計)

```typescript
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import Security from "@/pages/Settings/Security.svelte";

/*
 * セキュリティ設定画面 (F-10: リカバリコード再生成導線)。
 * - 2FA 有効時のみ再生成ボタンが出る (非権限者非表示)
 * - ConfirmDialog 経由でのみ POST される
 * - POST 成功 → GET 成功: 新コード表示 + success トースト
 * - POST 成功 → GET 失敗: 旧コード非表示のまま error トースト + 再試行導線
 * - disabled 不使用 (AGENTS.md 禁止事項 8)
 */

// router.post をモックし、page は 2FA 状態を書き換えられる可変オブジェクトにする
const { routerPostMock, pageState, addToastMock } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings/security",
    },
    addToastMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock, delete: vi.fn() },
    page: pageState,
}));

vi.mock("@/lib/stores/toast", () => ({
    addToast: addToastMock,
}));

const fetchMock = vi.fn();

function setTwoFactor(enabled: boolean): void {
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: enabled } },
    };
}

beforeEach(() => {
    setTwoFactor(true);
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerPostMock.mockReset();
    addToastMock.mockReset();
    fetchMock.mockReset();
});

/** Inertia visit options (第3引数) を取り出す */
function lastVisitOptions(): {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
} {
    const call = routerPostMock.mock.calls.at(-1);
    if (!call) throw new Error("router.post が呼ばれていない");
    return call[2] as ReturnType<typeof lastVisitOptions>;
}

describe("Settings/Security リカバリコード再生成 (F-10)", () => {
    it("2FA 有効時に再生成ボタンが表示され、disabled ではない", () => {
        render(Security, { props: {} });

        const button = screen.getByTestId("regenerate-recovery-codes-button");
        expect(button).toBeInTheDocument();
        expect(button).not.toBeDisabled();
    });

    it("2FA 無効時は再生成ボタンを表示しない (有効化ボタンのみ)", () => {
        setTwoFactor(false);
        render(Security, { props: {} });

        expect(screen.queryByTestId("regenerate-recovery-codes-button")).toBeNull();
        expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
    });

    it("再生成ボタン押下で確認ダイアログが開き、確認までは POST しない", async () => {
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));

        expect(screen.getByText(/既存のリカバリコードは直ちにすべて失効します/)).toBeInTheDocument();
        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it("ダイアログ確認で POST /user/two-factor-recovery-codes が発火する", async () => {
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));

        expect(routerPostMock).toHaveBeenCalledWith(
            "/user/two-factor-recovery-codes",
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it("POST 成功 → GET 成功で新コードを表示し success トーストを出す", async () => {
        fetchMock.mockResolvedValue({
            ok: true,
            json: () => Promise.resolve(["new-code-1", "new-code-2"]),
        });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
        lastVisitOptions().onSuccess?.();

        await waitFor(() => {
            expect(screen.getByTestId("recovery-codes")).toHaveTextContent("new-code-1");
        });
        expect(addToastMock).toHaveBeenCalledWith("success", expect.stringContaining("再生成しました"));
    });

    it("POST 成功 → GET 失敗では旧コードを残さず error トースト + 再試行導線に戻る", async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500 });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
        lastVisitOptions().onSuccess?.();

        await waitFor(() => {
            expect(addToastMock).toHaveBeenCalledWith(
                "error",
                expect.stringContaining("以前のコードは既に無効です"),
            );
        });
        expect(screen.queryByTestId("recovery-codes")).toBeNull();
        expect(screen.getByTestId("show-recovery-codes-button")).toBeInTheDocument();
    });
});
```

### 設計判断メモ

- `page` を可変 hoisted オブジェクトでモックするのは、shared props (`auth.user.twoFactorEnabled`)
  がテスト単位で変わるため (`PurchaseTickets.test.ts` の router モックパターンの拡張)。
- `router.post` の visit options (`onSuccess` 等) を手動発火して Inertia サーバ応答を再現する
  (Inertia 本体を起動しない unit テストの定石)。
- トーストは module mock で検証 (実 ToastContainer の描画に依存しない)。
- ConfirmDialog は実物を描画 (Modal 含む。`tests/js/setup.ts` の animate/ResizeObserver
  スタブで描画可能なことは既存テストで実証済み)。

### PHPStan適合チェック

- [x] 対象外 (Vitest)。TS 型は `Record<string, unknown>` / 明示 interface で `any` 不使用

### テスト計画

- [x] 本施策自体がテスト。実装前に fail することを確認する (テストファースト:
      `regenerate-recovery-codes-button` が存在せず fail)

### リスク

- `page` のモックはモジュール単位のため、本ファイル内の全テストが同じ `pageState` を共有する
  → `beforeEach` で毎回リセットして汚染を防ぐ。

---

## 施策 3: オーナー移譲セクションの常時表示化 (F-12)

### 変更箇所

- ファイル: `resources/js/pages/Organizations/Settings.svelte`
  - `<script>`: L97-104 (`openTransferDialog` の検証強化)
  - テンプレート: L230-268 (表示条件変更 + 候補 0 人時の案内文)

### 波及変更

- TypeScript型定義: なし (既存 `Member` interface / Props を再利用)
- API Resource/DTO: なし (Controller の `members` props は従来から全メンバーを返しており変更不要)
- テストファイル: `tests/js/pages/OrganizationsSettings.test.ts` 更新 (施策 4)

### 現行コード (抜粋)

```svelte
function openTransferDialog(event: SubmitEvent): void {
    event.preventDefault();
    if (transferForm.user_id === "") {
        transferForm.setError("user_id", "移譲先のメンバーを選択してください。");
        return;
    }
    transferDialogOpen = true;
}
```

```svelte
{#if isOwner && transferCandidates.length > 0}
    <DangerZone
        title="オーナー移譲"
        description="組織のオーナー権限を別のメンバーへ移譲します。移譲後、あなたは管理者になります。この操作にはパスワードの再確認が必要です。"
    >
        <form onsubmit={openTransferDialog} class="flex flex-col gap-4">
            <FormField label="移譲先のメンバー" id="transfer-target" error={transferForm.errors.user_id}>
                ...(select: 「選択してください」 + transferCandidates)...
            </FormField>
            <div>
                <Button type="submit" variant="danger-outline" testId="transfer-ownership-button">
                    オーナーを移譲
                </Button>
            </div>
        </form>
    </DangerZone>
{/if}
```

### 変更後コード

`openTransferDialog` (候補 0 人の早期リターン + 選択値の候補実在検証):

```svelte
/**
 * 移譲確認ダイアログを開く。成立し得ない操作は ConfirmDialog まで進めず、
 * 押下時にエラー表示する (disabled 禁止 = AGENTS.md 8)。
 * 選択値の実在検証は DOM 改変・stale 値の早期排除で、最終ゲートはサーバ
 * (Policy + exists:users,id + Service のメンバーシップ検証)。
 * select の value は string のため、Member.id (number) は String() に揃えて比較する。
 */
function openTransferDialog(event: SubmitEvent): void {
    event.preventDefault();
    if (transferCandidates.length === 0) {
        transferForm.setError(
            "user_id",
            "移譲先にできるメンバーがいません。先にメンバーを招待してください。",
        );
        return;
    }
    const isValidTarget = transferCandidates.some(
        (member) => String(member.id) === transferForm.user_id,
    );
    if (!isValidTarget) {
        transferForm.setError("user_id", "移譲先のメンバーを選択してください。");
        return;
    }
    transferDialogOpen = true;
}
```

テンプレート (表示条件を `isOwner` のみに変更 + 候補 0 人時の案内文):

```svelte
{#if isOwner}
    <DangerZone
        title="オーナー移譲"
        description="組織のオーナー権限を別のメンバーへ移譲します。移譲後、あなたは管理者になります。この操作にはパスワードの再確認が必要です。"
    >
        {#if transferCandidates.length === 0}
            <p class="text-caption text-text-secondary" data-testid="transfer-no-candidates">
                移譲先にできるメンバーがいません。先に
                {#if usersUrl !== null}
                    <TextLink href={usersUrl}>管理メニュー &gt; ユーザー管理</TextLink>
                    からメンバーを招待してください。
                {:else}
                    メンバーを招待できる管理者に依頼してください。
                {/if}
            </p>
        {/if}
        <form onsubmit={openTransferDialog} class="flex flex-col gap-4">
            <FormField
                label="移譲先のメンバー"
                id="transfer-target"
                error={transferForm.errors.user_id}
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Select
                        {id}
                        bind:value={transferForm.user_id}
                        error={invalid}
                        aria-describedby={describedBy}
                    >
                        <option value="">選択してください</option>
                        {#each transferCandidates as candidate (candidate.id)}
                            <option value={String(candidate.id)}>
                                {candidate.name}
                            </option>
                        {/each}
                    </Select>
                {/snippet}
            </FormField>
            <div>
                <Button
                    type="submit"
                    variant="danger-outline"
                    testId="transfer-ownership-button"
                >
                    オーナーを移譲
                </Button>
            </div>
        </form>
    </DangerZone>
{/if}
```

(form / FormField / Select / ConfirmDialog / `transferOwnership()` / `withRecentAuth` /
`RecentAuthModal` は**変更なし**。差分は `{#if}` 条件・案内文 `<p>`・`openTransferDialog` のみ)

### 設計判断メモ

- **`isOwner` 条件は維持**: Policy `transferOwnership` = owner のみ。非権限者に UI を
  出さない (受け入れ条件 3)。禁止事項 8 は「権限があるのに前提未充足で無言」の禁止であり、
  権限外ユーザーへの露出義務ではない (conceptual-review Round 1 合意)。
- **フォームは候補 0 人でも描画**: 操作の存在自体を可視化する (F-12 の本質対応)。
  押下時は専用エラー文言で「次に何をすべきか」を示す。ConfirmDialog は開かない
  (成立し得ない destructive 操作を確認まで進めない)。
- **案内文の IA 名称**: 「管理メニュー > ユーザー管理」は実画面 (`/manage/users`、
  組織設定内の既存導線カードと同じ呼称) に一致。`usersUrl` は owner なら
  manageMembers 権限により常に非 null だが、props 型上 nullable のためフォールバック文言を持つ。
- **string↔number 変換地点**: select の option value / 比較は `String(member.id)` に統一
  (既存実装踏襲)。送信は `user_id: string` のまま (サーバ側 `integerish` → `(int)` が既存契約)。
- `Organization.isPersonal` による出し分けは行わない (現行仕様に personal 組織の移譲禁止は
  存在せず、Policy/Service も制限していない。仕様追加はスコープ外)。

### PHPStan適合チェック

- [x] バックエンド変更なし (PHPStan 対象外)
- [x] TS: 既存 `Member { id: number; name: string }` を再利用。`setError` のキーは
      useForm data のキー `"user_id"` に閉じる

### テスト計画

施策 4 に記載。

### リスク

- 既存テスト「オーナー移譲 select は members (id/name) で動く」は候補ありのケースのため
  影響なし (表示条件の緩和は既存表示ケースを壊さない)。
- 候補 0 人 + submit 時に `setError` を使うのは既存経路 (未選択時) と同じで新規リスクなし。
- `myId` が null (shared props 欠落) の場合 `transferCandidates` に自分自身が混入し得るが、
  これは**既存挙動のまま** (本設計で変更しない)。自己移譲はサーバが
  「自分自身には移譲できません。」で拒否する既存契約で担保。

---

## 施策 4: OrganizationsSettings.test.ts 更新 (F-12 回帰固定)

### 変更箇所

- ファイル: `tests/js/pages/OrganizationsSettings.test.ts` (テストケース追加。既存ケースは変更しない)

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 本体

### 変更後コード (追加分の設計)

```typescript
// 追加 import
import { fireEvent } from "@testing-library/svelte";

describe("Organizations/Settings オーナー移譲の常時表示 (F-12)", () => {
    // 自分 (id:1) しかいない組織 = 移譲候補 0 人。
    // 実運用では members に自分が必ず含まれる (controller は全メンバーを返す) が、
    // 本テストは page 未モックで myId=null のため members: [] で候補 0 人を表現する
    // (どちらでも transferCandidates.length === 0 の同一分岐)。
    const soloProps = { ...baseProps, members: [] };

    it("候補 0 人でもオーナーにはセクションと案内文が表示される", () => {
        render(Settings, { props: soloProps });

        expect(screen.getByRole("heading", { name: "オーナー移譲" })).toBeInTheDocument();
        expect(screen.getByTestId("transfer-no-candidates")).toBeInTheDocument();
        expect(screen.getByTestId("transfer-no-candidates")).toHaveTextContent("ユーザー管理");
        const button = screen.getByTestId("transfer-ownership-button");
        expect(button).toBeInTheDocument();
        expect(button).not.toBeDisabled();
    });

    it("候補 0 人で押下すると確認ダイアログを開かずエラーを表示する", async () => {
        render(Settings, { props: soloProps });

        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));

        expect(
            screen.getByText("移譲先にできるメンバーがいません。先にメンバーを招待してください。"),
        ).toBeInTheDocument();
        // ConfirmDialog (Modal) は開いていない
        expect(screen.queryByRole("button", { name: "移譲する" })).toBeNull();
    });

    it("未選択のまま押下すると確認ダイアログを開かず選択エラーを表示する", async () => {
        render(Settings, { props: baseProps });

        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));

        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "移譲する" })).toBeNull();
    });

    it("非オーナーにはオーナー移譲セクションを表示しない", () => {
        render(Settings, {
            props: { ...baseProps, currentUserRole: "organization_admin" },
        });

        expect(screen.queryByTestId("transfer-ownership-button")).toBeNull();
        expect(screen.queryByRole("heading", { name: "オーナー移譲" })).toBeNull();
    });
});
```

### 設計判断メモ

- 既存 6 ケース (組織名/移設回帰/導線/権限/2FA トグル/移譲 select) は**無変更で green のまま**
  であることが受け入れ条件 (表示条件の緩和が既存ケースを壊さない検証を兼ねる)。
- `members: []` で候補 0 人を表現する理由はコメントとしてテストに残す
  (page 未モックのため myId=null。`String(member.id) === transferForm.user_id` の分岐は
  施策 2 側でなく本ファイルの「未選択エラー」ケースが担う)。

### PHPStan適合チェック

- [x] 対象外 (Vitest)

### テスト計画

- [x] 本施策自体がテスト。実装前に fail することを確認する
      (現行は候補 0 人でセクション自体が消えるため `transfer-no-candidates` が fail)

### リスク

- なし (追加のみ。既存ケース無変更)。

---

## 検証コマンド (全 green でコミット)

- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
- `composer test` / `composer phpstan` / `vendor/bin/pint --test` (バックエンド無変更の回帰確認)
- Architecture テスト (ds-purity / atomic-import-graph / svg-inline-allowlist) は
  `pnpm test` に含まれる (新規 import は tick / 既存部品のみで違反なし)

## bug-hunt finding への回答 (記録)

- **F-10**: UI 実装漏れ (真陽性)。本設計で解消。
- **F-12**: 「UI が存在しない」は誤検知 (T006 で実装済み・bug-hunt 走行コミットに含まれる)。
  真因は「移譲候補 0 人時にセクションを無言で隠す」空状態設計であり、本設計で
  常時表示 + 案内文に変更。shard-report の「operations.md に未実装フラグを追加」提案は
  不要 (operations.md は変更しない)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は Svelte ページ 2 ファイル + Vitest 2 ファイルに閉じ、バックエンド・共有コンポーネント・型定義に触れない。単一 worktree タスクで完結する規模 |
| 競合リスク | 低。`Settings/Security.svelte` / `Organizations/Settings.svelte` を触る他 TODO は現在 Open に存在しない。bug-hunt 由来の他 finding 対応 (F-03/F-06 の flash トースト等) が同ファイルに触れる可能性があるため、同時実装する場合は本タスクを先行させる |

---

## 関連する現行コード

### resources/js/pages/Settings/Security.svelte (現行全文)
```svelte
<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { useForm } from "@inertiajs/svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import { providerLabel } from "@/lib/social";
    import { addToast } from "@/lib/stores/toast";

    interface Props {
        socialProviders?: string[];
        linkedProviders?: string[];
    }

    let { socialProviders = [], linkedProviders = [] }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const twoFactorEnabled = $derived(shared.auth?.user?.twoFactorEnabled ?? false);

    /* ----------------------------------------------------------------
     * 2FA 管理
     * 未有効 → 有効化開始 (POST) → QR + コード確認 (confirming)
     * → リカバリコード表示 → 有効。無効化は ConfirmDialog 経由。
     * 注: Fortify の password.confirm は撤去済み (generic recent-auth へ統一)。
     * 2FA エンドポイントへの recent-auth 配線はアプリ側の課題
     * (config/fortify.php の TODO(template) 参照)。
     * ---------------------------------------------------------------- */

    /** QR 確認待ち (有効化開始済みだが未確認) */
    let confirming = $state(false);
    let enabling = $state(false);
    let qrSvg = $state<string | null>(null);
    let recoveryCodes = $state<string[]>([]);
    let loadingRecoveryCodes = $state(false);

    const confirmForm = useForm({
        code: "",
    });

    async function fetchJson<T>(url: string): Promise<T> {
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return (await response.json()) as T;
    }

    async function loadQrCode(): Promise<void> {
        try {
            const data = await fetchJson<{ svg: string }>("/user/two-factor-qr-code");
            qrSvg = data.svg;
        } catch {
            addToast("error", "QR コードの取得に失敗しました。再読み込みしてください。");
        }
    }

    async function loadRecoveryCodes(): Promise<void> {
        loadingRecoveryCodes = true;
        try {
            recoveryCodes = await fetchJson<string[]>("/user/two-factor-recovery-codes");
        } catch {
            addToast("error", "リカバリコードの取得に失敗しました。");
        } finally {
            loadingRecoveryCodes = false;
        }
    }

    function enableTwoFactor(): void {
        router.post(
            "/user/two-factor-authentication",
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    enabling = true;
                },
                onSuccess: () => {
                    confirming = true;
                    void loadQrCode();
                },
                onFinish: () => {
                    enabling = false;
                },
            },
        );
    }

    function confirmTwoFactor(event: SubmitEvent): void {
        event.preventDefault();
        confirmForm.post("/user/confirmed-two-factor-authentication", {
            preserveScroll: true,
            onSuccess: () => {
                confirming = false;
                qrSvg = null;
                confirmForm.reset();
                void loadRecoveryCodes();
            },
        });
    }

    let disableDialogOpen = $state(false);
    let disabling = $state(false);

    function disableTwoFactor(): void {
        router.delete("/user/two-factor-authentication", {
            preserveScroll: true,
            onStart: () => {
                disabling = true;
            },
            onSuccess: () => {
                disableDialogOpen = false;
                confirming = false;
                qrSvg = null;
                recoveryCodes = [];
            },
            onFinish: () => {
                disabling = false;
            },
        });
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">設定</h1>

    <nav aria-label="設定メニュー" class="mt-4 flex gap-4 border-b border-border pb-2">
        <TextLink href="/settings">プロフィール</TextLink>
        <TextLink href="/settings/security">セキュリティ</TextLink>
    </nav>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
        <Card padding="lg">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-h3">2要素認証</h2>
                {#if twoFactorEnabled}
                    <Badge tone="success">有効</Badge>
                {:else}
                    <Badge tone="neutral">無効</Badge>
                {/if}
            </div>
            <p class="mt-1 text-caption text-text-secondary">
                認証アプリのワンタイムコードでログインを保護します。
            </p>

            {#if twoFactorEnabled}
                <div class="mt-4 flex flex-col gap-4">
                    {#if recoveryCodes.length > 0}
                        <div class="rounded-md border border-border bg-neutral p-4">
                            <p class="text-caption text-text-secondary">
                                リカバリコードは安全な場所に保管してください。各コードは一度だけ使えます。
                            </p>
                            <ul
                                class="mt-2 grid grid-cols-2 gap-1 text-body font-mono"
                                data-testid="recovery-codes"
                            >
                                {#each recoveryCodes as code (code)}
                                    <li>{code}</li>
                                {/each}
                            </ul>
                        </div>
                    {:else}
                        <div>
                            <Button
                                variant="ghost"
                                onclick={() => void loadRecoveryCodes()}
                                loading={loadingRecoveryCodes}
                            >
                                リカバリコードを表示
                            </Button>
                        </div>
                    {/if}
                    <div>
                        <Button
                            variant="danger-outline"
                            onclick={() => {
                                disableDialogOpen = true;
                            }}
                            testId="disable-two-factor-button"
                        >
                            2要素認証を無効化
                        </Button>
                    </div>
                </div>
            {:else if confirming}
                <div class="mt-4 flex flex-col gap-4">
                    <p class="text-body text-text-secondary">
                        認証アプリで以下の QR コードを読み取り、表示されたコードを入力して設定を完了してください。
                    </p>
                    {#if qrSvg}
                        <!-- QR はサーバ提供の SVG をそのまま描画する -->
                        <div class="self-start rounded-md border border-border bg-surface p-4">
                            <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                            {@html qrSvg}
                        </div>
                    {/if}
                    <form onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
                        <FormField
                            label="認証コード"
                            id="two-factor-code"
                            error={confirmForm.errors.code}
                        >
                            {#snippet children({ id, describedBy, invalid })}
                                <Input
                                    {id}
                                    type="text"
                                    inputmode="numeric"
                                    bind:value={confirmForm.code}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                    autocomplete="one-time-code"
                                />
                            {/snippet}
                        </FormField>
                        <div>
                            <Button type="submit" loading={confirmForm.processing}>
                                確認して有効化
                            </Button>
                        </div>
                    </form>
                </div>
            {:else}
                <div class="mt-4">
                    <Button
                        onclick={enableTwoFactor}
                        loading={enabling}
                        testId="enable-two-factor-button"
                    >
                        有効化
                    </Button>
                </div>
            {/if}
        </Card>

        <Card padding="lg">
            <h2 class="text-h3">ソーシャルログイン連携</h2>
            <p class="mt-1 text-caption text-text-secondary">
                外部アカウントを連携すると、そのアカウントでもログインできます。
            </p>
            <ul class="mt-4 flex flex-col gap-3">
                {#each socialProviders as provider (provider)}
                    <li
                        class="flex items-center justify-between gap-4 rounded-md border border-border p-3"
                    >
                        <span class="text-body">{providerLabel(provider)}</span>
                        {#if linkedProviders.includes(provider)}
                            <Badge tone="success" testId={`linked-${provider}`}>連携済み</Badge>
                        {:else}
                            <Button
                                variant="ghost"
                                size="sm"
                                href={`/auth/${provider}/redirect/link`}
                                testId={`link-${provider}`}
                            >
                                連携する
                            </Button>
                        {/if}
                    </li>
                {/each}
            </ul>
        </Card>
    </div>

    <ConfirmDialog
        bind:open={disableDialogOpen}
        title="2要素認証の無効化"
        message="2要素認証を無効化しますか？ リカバリコードも無効になります。"
        confirmLabel="無効化する"
        confirmVariant="danger"
        processing={disabling}
        onConfirm={disableTwoFactor}
        testId="disable-two-factor-dialog"
    />
</AppLayout>
```

### resources/js/pages/Organizations/Settings.svelte (現行全文)
```svelte
<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * 組織設定 (名称 / 2FA 必須方針 / API キー導線 / オーナー移譲)。
     * メンバー管理 (一覧・招待・ロール・削除・2FA リセット) は管理メニュー > ユーザー管理
     * (/manage/users) へ移設済み。members はオーナー移譲 select 用の最小 shape (id/name)。
     */
    interface Member {
        id: number;
        name: string;
    }

    interface Props {
        organization: {
            id: number;
            name: string;
            slug: string;
            isPersonal: boolean;
            twoFactorRequired: boolean;
        };
        members: Member[];
        currentUserRole: string | null;
        /** API キー / 接続セッション管理画面への導線を出すか (境界は manageApiKeys と同一) */
        canManageApiKeys: boolean;
        /** ユーザー管理画面 (管理メニュー) への導線 URL (manageMembers 権限なしは null = 非表示) */
        usersUrl: string | null;
    }

    let { organization, members, currentUserRole, canManageApiKeys, usersUrl }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const myId = $derived(shared.auth?.user?.id ?? null);

    const OWNER = "organization_owner";
    const ADMIN = "organization_admin";

    const canManage = $derived(currentUserRole === OWNER || currentUserRole === ADMIN);
    const isOwner = $derived(currentUserRole === OWNER);

    /* ---- 組織名 ---- */
    // 初期値として現在の組織名を取り込む (以後はフォーム側が真実)
    // svelte-ignore state_referenced_locally
    const nameForm = useForm({ name: organization.name });

    function submitName(event: SubmitEvent): void {
        event.preventDefault();
        nameForm.patch(`/organizations/${organization.slug}`, { preserveScroll: true });
    }

    /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
    let recentAuthOpen = $state(false);
    let recentAuthStatus = $state<RecentAuthStatus | null>(null);
    let pendingAction: (() => void) | null = null;

    function guardWithRecentAuth(action: () => void): void {
        void withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
        });
    }

    function resumePendingAction(): void {
        const action = pendingAction;
        pendingAction = null;
        action?.();
    }

    /* ---- オーナー移譲 (recent-auth 必須。precheck で鮮度を確認してから送る) ---- */
    const transferForm = useForm({ user_id: "" });
    let transferDialogOpen = $state(false);

    const transferCandidates = $derived(members.filter((member) => member.id !== myId));
    const transferTargetName = $derived(
        transferCandidates.find((member) => String(member.id) === transferForm.user_id)?.name ??
            "",
    );

    function openTransferDialog(event: SubmitEvent): void {
        event.preventDefault();
        if (transferForm.user_id === "") {
            transferForm.setError("user_id", "移譲先のメンバーを選択してください。");
            return;
        }
        transferDialogOpen = true;
    }

    function transferOwnership(): void {
        guardWithRecentAuth(() => {
            transferForm.post(`/organizations/${organization.slug}/transfer-ownership`, {
                preserveScroll: true,
                onFinish: () => {
                    transferDialogOpen = false;
                },
            });
        });
    }

    /* ---- 組織の 2FA 必須方針 (Owner 専権。recent-auth 必須) ----
       押下前の precheck はしない (未準拠 Owner の有効化はサーバが validation error で拒否し、
       ここに表示する)。 */
    const twoFactorRequirementForm = useForm({ enabled: false });

    function toggleTwoFactorRequirement(): void {
        twoFactorRequirementForm.enabled = !organization.twoFactorRequired;
        guardWithRecentAuth(() => {
            twoFactorRequirementForm.patch(
                `/organizations/${organization.slug}/two-factor-requirement`,
                { preserveScroll: true },
            );
        });
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">組織設定</h1>
    <p class="mt-1 text-caption text-text-secondary">
        {organization.name} の設定を管理します。
    </p>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
        <Card padding="lg">
            <h2 class="text-h3">組織名</h2>
            {#if canManage}
                <form onsubmit={submitName} class="mt-4 flex flex-col gap-4">
                    <FormField label="組織名" id="organization-name" error={nameForm.errors.name}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="text"
                                bind:value={nameForm.name}
                                error={invalid}
                                aria-describedby={describedBy}
                            />
                        {/snippet}
                    </FormField>
                    <div>
                        <Button type="submit" loading={nameForm.processing}>保存</Button>
                    </div>
                </form>
            {:else}
                <p class="mt-2 text-body">{organization.name}</p>
            {/if}
        </Card>

        {#if isOwner}
            <Card padding="lg">
                <h2 class="text-h3">セキュリティ</h2>
                <p class="mt-1 text-caption text-text-secondary">
                    2 段階認証を必須にすると、未設定のメンバーは設定が完了するまで他のページを利用できなくなります。
                </p>
                <div class="mt-4 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-body">2 段階認証の必須化</span>
                        <Badge tone={organization.twoFactorRequired ? "success" : "neutral"}>
                            {organization.twoFactorRequired ? "有効" : "無効"}
                        </Badge>
                    </div>
                    <Button
                        variant={organization.twoFactorRequired ? "danger-outline" : "primary"}
                        size="sm"
                        loading={twoFactorRequirementForm.processing}
                        onclick={toggleTwoFactorRequirement}
                        testId="toggle-two-factor-requirement"
                    >
                        {organization.twoFactorRequired ? "必須化を解除" : "必須にする"}
                    </Button>
                </div>
                {#if twoFactorRequirementForm.errors.enabled}
                    <p class="mt-2 text-caption text-danger" role="alert">
                        {twoFactorRequirementForm.errors.enabled}
                    </p>
                {/if}
            </Card>
        {/if}

        {#if usersUrl !== null}
            <Card padding="lg">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-h3">ユーザー管理</h2>
                        <p class="mt-1 text-caption text-text-secondary">
                            メンバーの一覧・招待・ロール変更・削除は管理メニューのユーザー管理画面で行います。
                        </p>
                    </div>
                    <TextLink href={usersUrl} testId="link-manage-users">
                        管理画面を開く
                    </TextLink>
                </div>
            </Card>
        {/if}

        {#if canManageApiKeys}
            <Card padding="lg">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-h3">API キー / 接続セッション</h2>
                        <p class="mt-1 text-caption text-text-secondary">
                            REST API / MCP アクセスのキー発行・失効と、CLI / MCP の接続セッション管理は専用画面で行います。
                        </p>
                    </div>
                    <TextLink
                        href={`/organizations/${organization.slug}/api-keys`}
                        testId="link-api-keys"
                    >
                        管理画面を開く
                    </TextLink>
                </div>
            </Card>
        {/if}

        {#if isOwner && transferCandidates.length > 0}
            <DangerZone
                title="オーナー移譲"
                description="組織のオーナー権限を別のメンバーへ移譲します。移譲後、あなたは管理者になります。この操作にはパスワードの再確認が必要です。"
            >
                <form onsubmit={openTransferDialog} class="flex flex-col gap-4">
                    <FormField
                        label="移譲先のメンバー"
                        id="transfer-target"
                        error={transferForm.errors.user_id}
                    >
                        {#snippet children({ id, describedBy, invalid })}
                            <Select
                                {id}
                                bind:value={transferForm.user_id}
                                error={invalid}
                                aria-describedby={describedBy}
                            >
                                <option value="">選択してください</option>
                                {#each transferCandidates as candidate (candidate.id)}
                                    <option value={String(candidate.id)}>
                                        {candidate.name}
                                    </option>
                                {/each}
                            </Select>
                        {/snippet}
                    </FormField>
                    <div>
                        <Button
                            type="submit"
                            variant="danger-outline"
                            testId="transfer-ownership-button"
                        >
                            オーナーを移譲
                        </Button>
                    </div>
                </form>
            </DangerZone>
        {/if}
    </div>

    <ConfirmDialog
        bind:open={transferDialogOpen}
        title="オーナー移譲"
        message={`オーナー権限を ${transferTargetName} さんへ移譲しますか？ あなたは管理者に降格します。`}
        confirmLabel="移譲する"
        confirmVariant="danger"
        processing={transferForm.processing}
        onConfirm={transferOwnership}
        testId="transfer-ownership-dialog"
    />

    <RecentAuthModal
        bind:open={recentAuthOpen}
        passwordSet={recentAuthStatus?.passwordSet ?? false}
        availableProviders={recentAuthStatus?.availableProviders ?? []}
        canSatisfy={recentAuthStatus?.canSatisfy ?? true}
        onConfirmed={resumePendingAction}
    />
</AppLayout>
```

### tests/js/pages/OrganizationsSettings.test.ts (現行全文)
```typescript
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Settings from "@/pages/Organizations/Settings.svelte";

const baseProps = {
    organization: {
        id: 1,
        name: "テスト組織",
        slug: "test-org",
        isPersonal: false,
        twoFactorRequired: false,
    },
    // オーナー移譲 select 用の最小 shape (id/name)。email / 2FA は Admin/Users へ移設済み
    members: [
        { id: 1, name: "オーナー 太郎" },
        { id: 2, name: "メンバー 花子" },
    ],
    currentUserRole: "organization_owner",
    canManageApiKeys: true,
    usersUrl: "/manage/users",
};

describe("Organizations/Settings", () => {
    it("組織名フォームを描画する", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByRole("heading", { name: "組織設定" })).toBeInTheDocument();
        expect(screen.getByLabelText("組織名")).toBeInTheDocument();
    });

    it("メンバー管理 UI は存在しない (Admin/Users へ移設済み = 旧 UI 並走の回帰封じ)", () => {
        render(Settings, { props: baseProps });

        expect(screen.queryByTestId("member-list")).toBeNull();
        expect(screen.queryByTestId("invite-submit")).toBeNull();
        expect(screen.queryByRole("heading", { name: "メンバーを招待" })).toBeNull();
        expect(screen.queryByTestId("reset-two-factor-2")).toBeNull();
        expect(screen.queryByTestId("member-role-2")).toBeNull();
        expect(screen.queryByTestId("remove-member-2")).toBeNull();
    });

    it("manageMembers 権限者にはユーザー管理画面への導線リンクを出す", () => {
        render(Settings, { props: baseProps });

        const link = screen.getByTestId("link-manage-users");
        expect(link).toBeInTheDocument();
        expect(link.getAttribute("href")).toMatch(/\/manage\/users$/);
    });

    it("usersUrl が null (権限なし) ならユーザー管理導線を出さない", () => {
        render(Settings, {
            props: {
                ...baseProps,
                currentUserRole: "organization_member",
                canManageApiKeys: false,
                usersUrl: null,
            },
        });

        expect(screen.queryByTestId("link-manage-users")).toBeNull();
        expect(screen.queryByLabelText("組織名")).toBeNull();
        expect(screen.queryByTestId("link-api-keys")).toBeNull();
        expect(screen.queryByTestId("toggle-two-factor-requirement")).toBeNull();
    });

    it("owner には 2FA 必須化トグルが表示される", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByTestId("toggle-two-factor-requirement")).toBeInTheDocument();
    });

    it("オーナー移譲 select は members (id/name) で動く", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByTestId("transfer-ownership-button")).toBeInTheDocument();
        expect(screen.getByLabelText("移譲先のメンバー")).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "メンバー 花子" })).toBeInTheDocument();
    });

    it("canManageApiKeys の場合は API キー / 接続セッション管理画面への導線を出す", () => {
        render(Settings, { props: baseProps });

        const link = screen.getByTestId("link-api-keys");
        expect(link).toBeInTheDocument();
        // Inertia Link は jsdom で絶対 URL に解決するため末尾一致で検証する
        expect(link.getAttribute("href")).toMatch(/\/organizations\/test-org\/api-keys$/);
    });
});
```

### routes/web.php (関連抜粋 L236-243)
```php
    // 組織の 2FA 必須方針トグル (Owner 専権 + step-up)
    Route::patch('/organizations/{organization:slug}/two-factor-requirement', [OrganizationController::class, 'updateTwoFactorRequirement'])
        ->middleware('recent-auth')
        ->name('organizations.two-factor-requirement.update');
    // オーナー移譲は step-up (recent-auth) 必須
    Route::post('/organizations/{organization:slug}/transfer-ownership', [OrganizationOwnershipController::class, 'store'])
        ->middleware('recent-auth')
        ->name('organizations.transfer-ownership');

```

### app/Http/Controllers/Organizations/OrganizationOwnershipController.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Webmozart\Assert\Assert;

/**
 * オーナー移譲。password.confirm (step-up) を経由して到達する。
 */
class OrganizationOwnershipController extends Controller
{
    public function store(Request $request, Organization $organization, OrganizationMembershipService $membership): RedirectResponse
    {
        Gate::authorize('transferOwnership', $organization);

        $from = $request->user();
        Assert::isInstanceOf($from, User::class);

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $userId = $request->input('user_id');
        Assert::integerish($userId);

        /** @var User $to */
        $to = User::query()->findOrFail((int) $userId);

        $membership->transferOwnership($organization, $from, $to);

        return back()->with('success', 'オーナーを移譲しました');
    }
}
```
