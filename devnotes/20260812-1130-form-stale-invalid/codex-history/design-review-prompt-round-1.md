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

あなたは経験豊富な Web アプリケーションアーキテクトです。Svelte 5 runes + Inertia の詳細設計をレビューしてください。

【レビュー観点】
1. コードの正確性 (エッジケース・競合) 2. 既存コードとの整合性 3. 型安全性
4. テスト計画の網羅性と mutation の妥当性 5. 副作用・後退リスク 6. 波及変更
7. DESIGN.md 準拠 8. Atomic Design 準拠

【特に見てほしい点】
- 送信世代 (submissionId) の判定は本当に必要か。多重送信ガード (submitting) があるのに冗長でないか。
  冗長でないなら、契約 11 のテストは実際に書けるか (jsdom で古い応答を後から発火できるか)。
- `onError` / `onSuccess` の両方で解除する設計に穴は無いか (両方呼ばれるケース・どちらも呼ばれないケース)。
- mutation M1〜M7 の予測は妥当か。予測が外れそうなものを指摘してほしい。
- テストのスタブ方針 (useForm / router を差し替える) は、契約を実際に固定できているか。
  実装詳細に寄りすぎて偽陽性・偽陰性にならないか。

【出力形式】
- 各施策ごとに APPROVE / REQUEST_CHANGES、[Critical] [Warning] [Suggestion] 分類、全体判定、日本語

---

## 詳細設計書

# 詳細設計: form-stale-invalid

## 使命・制約(絶対遵守)

### アプリの使命(North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」/ 標準作業を起点に AI が教材設計し撮影を指示する / SECI の装置。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作
4. `response()->json()` の直書き 5. LLM 呼び出しの Prism 直呼び 6. prompt 文字列のコード直書き
7. 操作系 POST の `redirect()->intended()` 8. **必須条件未充足を理由にボタンを disabled にする UI**
9. Artifact の使用

### コーディングルール

- Svelte 5 runes + DS token のみ (`DESIGN.md` canonical)。TypeScript (JS 禁止)。
- component 階層は単方向 import。アイコンは `@lucide/svelte` のみ。
- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` が green。
- 既存テストの削除禁止 (期待値更新は可)。

## 概念設計リファレンス

- `devnotes/20260812-1130-form-stale-invalid/conceptual-design.md` (Round 4 APPROVED)
- 合議: `conceptual-review-round-{1..4}.md` / `codex-history/conceptual-review-decisions-round-{1..3}.md`

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `Projects/Create` の入力でエラー値を消す (F-1-01) | `resources/js/pages/Projects/Create.svelte` | High |
| 2 | `BillingContactForm` の編集済み抑制と送信結果での解除 (F-3-02) | `resources/js/components/features/billing/BillingContactForm.svelte` | High |
| 3 | 契約をテストで固定 | `tests/js/pages/ProjectsCreate.test.ts` (新規) / `tests/js/components/features/billing/BillingContactForm.test.ts` (新規) | High |

**サーバ側の変更は 0 行**。`FormField` も変更しない。

### 既存 9 箇所との重複が無いことの確認 (概念設計の宿題)

`grep -rn "clearErrors" resources/js/pages/Projects/Create.svelte
resources/js/components/features/billing/BillingContactForm.svelte` → **0 件**。
よって今回の 2 箇所は既存 9 箇所と**重複しない**。修正後に同種の挙動が残るのは
65 − 9 − 2 = **54 箇所**である。

---

## 施策 1: `Projects/Create` の入力でエラー値を消す

### 現行コード

```svelte
<FormField label="プロジェクト名" id="project-name" error={form.errors.name} required>
    {#snippet children({ id, describedBy, invalid })}
        <Input {id} type="text" bind:value={form.name} error={invalid} aria-describedby={describedBy} />
    {/snippet}
</FormField>
<FormField label="説明" id="project-description" error={form.errors.description}>
    {#snippet children({ id, describedBy, invalid })}
        <Textarea {id} bind:value={form.description} error={invalid} aria-describedby={describedBy} />
    {/snippet}
</FormField>
```

### 変更後コード

```svelte
<FormField label="プロジェクト名" id="project-name" error={form.errors.name} required>
    {#snippet children({ id, describedBy, invalid })}
        <Input
            {id}
            type="text"
            bind:value={form.name}
            error={invalid}
            aria-describedby={describedBy}
            oninput={() => {
                // 入力し始めたらその場でエラーを消す (次 submit を待たない)。
                // **値そのものを消す**ので、同じ文言が再び返れば必ず再表示される。
                if (form.errors.name) form.clearErrors("name");
            }}
        />
    {/snippet}
</FormField>
```

`description` も同形 (`form.errors.description` / `clearErrors("description")`)。

### 設計判断

- **既存 9 箇所と同じ定型句を使う** (`Manuals/Create.svelte` が見本)。新しい書き方を持ち込まない。
- **フィールド単位で消す** (`clearErrors()` を引数なしで呼ばない)。
  片方の編集が他方のエラーを消してはならない。

### 波及変更

TypeScript 型定義: なし / API Resource・DTO: なし / サーバ: なし

---

## 施策 2: `BillingContactForm` の編集済み抑制と送信結果での解除

### 現行コード (要点)

```ts
let emailText = $state(billingContact.email ?? "");
let nameText = $state(billingContact.name ?? "");
let submitting = $state(false);

const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);
const emailError = $derived(!submitting ? (serverErrors.billing_contact_email ?? null) : null);
const nameError = $derived(!submitting ? (serverErrors.billing_contact_name ?? null) : null);

function submit(): void {
    if (submitting) return; // 多重送信ガード (disabled にはしない)
    router.patch(updateUrl, { billing_contact_email: emailText, billing_contact_name: nameText }, {
        preserveScroll: true,
        onStart: () => { submitting = true; },
        onFinish: () => { submitting = false; },
    });
}
```

### 変更後コード

```ts
let emailText = $state(billingContact.email ?? "");
let nameText = $state(billingContact.name ?? "");
let submitting = $state(false);

/**
 * 「前回の検証結果を、ユーザーが編集したので stale とみなして隠す」フラグ。
 * **値の正誤は見ていない** (クライアント検証ではない)。
 */
let emailEdited = $state(false);
let nameEdited = $state(false);

/**
 * 送信世代。**古い応答が新しい編集状態を解除しないため**に使う
 * (多重送信ガードがあっても、失敗応答と次の編集が交差しうる)。
 */
let submissionId = 0;

const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);
// 抑制条件に emailEdited を足す。**page props の同一性には依存しない** (概念設計 Round 3)
const emailError = $derived(
    !submitting && !emailEdited ? (serverErrors.billing_contact_email ?? null) : null,
);
const nameError = $derived(
    !submitting && !nameEdited ? (serverErrors.billing_contact_name ?? null) : null,
);

function submit(): void {
    if (submitting) return; // 多重送信ガード (disabled にはしない)
    const attempt = ++submissionId;
    router.patch(updateUrl, { billing_contact_email: emailText, billing_contact_name: nameText }, {
        preserveScroll: true,
        onStart: () => { submitting = true; },
        // **この送信の検証結果が届いた**ので抑制を解く (同じ文言でも必ず再表示される)。
        // onFinish は使わない (キャンセル・通信失敗でも動くため再表示の根拠にならない)。
        onError: () => {
            if (attempt !== submissionId) return; // 古い応答は新しい編集状態を触らない
            emailEdited = false;
            nameEdited = false;
        },
        onSuccess: () => {
            if (attempt !== submissionId) return;
            emailEdited = false;
            nameEdited = false;
        },
        onFinish: () => { submitting = false; },
    });
}
```

markup 側は各 `Input` に `oninput` を足すだけ:

```svelte
<Input … oninput={() => { emailEdited = true; }} />
<Input … oninput={() => { nameEdited = true; }} />
```

### 設計判断

- **解除契機は自分の送信の結果だけ** (`onError` / `onSuccess`)。
  `router.on('finish')` / page props の同一性は**使わない** — どちらも無関係な visit で
  古いエラーを復活させる (概念設計の棄却理由)。
- **`onFinish` は `submitting` を戻すためだけに使う** (従来どおり)。解除には使わない。
- **送信世代 (`submissionId`)** で、古い応答が新しい編集状態を解除しないようにする。
- **フィールド単位のフラグ**にする (email の編集が name のエラーを消さない)。
- `submitting` 中の抑制は**既存挙動**であり変更しない。

### 波及変更

TypeScript 型定義: なし / props: なし / サーバ: なし

---

## 施策 3: 契約をテストで固定

### `tests/js/pages/ProjectsCreate.test.ts` (新規)

Inertia の `useForm` を実物のまま使い、`errors` を差し込むために
`@inertiajs/svelte` の `useForm` を薄い stub に差し替える (既存テストの流儀に合わせる。
`router` を mock している既存例は `tests/js/pages/CaptureShow.test.ts`)。

| # | 契約 |
|---|---|
| 1 | `name` に入力すると `clearErrors("name")` が **`"name"` 引数つき**で呼ばれる |
| 2 | `description` に入力すると `clearErrors("description")` が呼ばれる |
| 3 | `name` の入力で **`description` のエラーは消えない** (引数なし `clearErrors()` を使っていない) |
| 4 | エラーが無いときは `clearErrors` を呼ばない (無駄な呼び出しをしない) |

**「同じ文言の再到着で再表示される」は `clearErrors` が値を消す挙動そのもの**なので、
stub の呼び出し検証で足りる (Inertia 本体の再代入を再実装して検証しない)。

### `tests/js/components/features/billing/BillingContactForm.test.ts` (新規)

`@inertiajs/svelte` の `router` と `page` を stub する (`AutoRechargeCard.test.ts` の流儀に合わせる)。

| # | 契約 |
|---|---|
| 5 | page props にエラーがあると表示される (現状維持の回帰) |
| 6 | email に入力するとエラー表示が消える |
| 7 | **name のエラーは email の入力では消えない** (フィールド独立) |
| 8 | 送信して `onError` が呼ばれると、**同じ文言でも**エラーが再表示される |
| 9 | `onSuccess` でも編集済みが初期化される (次の操作へ持ち越さない) |
| 10 | **`onFinish` だけでは再表示されない** (キャンセル・通信失敗で復活させない) |
| 11 | 古い送信の `onError` は、その後に始まった新しい編集を解除しない (送信世代) |

### fail 先行

施策 3 のテストだけを先に置き、**契約 1..3 と 6..11 が赤くなる**ことを確認してから実装する
(5 は現状でも緑。実測して記録する)。

### mutation 計画

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | `clearErrors("name")` を引数なし `clearErrors()` にする | 契約 3 |
| M2 | `Projects/Create` の `oninput` を削除 | 契約 1 |
| M3 | `emailEdited` を抑制条件から外す | 契約 6 |
| M4 | `emailEdited` / `nameEdited` を 1 つの共有フラグにする | 契約 7 |
| M5 | 解除を `onError` から `onFinish` へ移す | 契約 10 |
| M6 | `onSuccess` の解除を削除 | 契約 9 |
| M7 | 送信世代 (`attempt !== submissionId`) の判定を削除 | 契約 11 |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 2 ファイル + テスト 2 本。サーバ 0 行、props 不変、他 63 箇所は無変更 |
| 競合リスク | なし (どちらも他 TODO が触っていない) |

## 保証しないもの (誇張しない)

- **同種の挙動が残る呼び出しは 54 箇所**。本 TODO は直さないし gate も入れない。
- **クライアント検証はしない**。「編集された」ことしか見ていない。
- **`BillingContactForm` の解除は自分の送信結果だけに反応する**。別画面での更新や
  partial reload では解除しない (無関係な visit で古いエラーを復活させないための代償)。
- **Vitest は DOM と呼び出しの契約のみ**。実ブラウザでの見え方は確認しない。


---

## 関連する現行コード

### resources/js/pages/Projects/Create.svelte

```svelte
<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import { FolderPlus } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * プロジェクト作成。Team 選択は出さない (Default Team パターン:
     * 所属はサーバ側の ProjectService が組織の Default Team に自動割当する)。
     */
    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const form = useForm({ name: "", description: "" });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/projects");
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="プロジェクトの作成"
            description="新しいプロジェクトを作成します。"
            icon={FolderPlus}
            testId="project-create-heading"
        />
        <PageContent>
            <Card padding="lg">
                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
                    <FormField label="プロジェクト名" id="project-name" error={form.errors.name} required>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="text"
                                bind:value={form.name}
                                error={invalid}
                                aria-describedby={describedBy}
                            />
                        {/snippet}
                    </FormField>
                    <FormField label="説明" id="project-description" error={form.errors.description}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Textarea
                                {id}
                                bind:value={form.description}
                                error={invalid}
                                aria-describedby={describedBy}
                            />
                        {/snippet}
                    </FormField>
                    <div class="flex items-center gap-2">
                        <Button type="submit" loading={form.processing} testId="project-submit">
                            作成
                        </Button>
                        <Button variant="ghost" href="/projects" inertia>キャンセル</Button>
                    </div>
                </form>
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
```

### resources/js/components/features/billing/BillingContactForm.svelte

```svelte
<script lang="ts">
    import { page as inertiaPage, router } from "@inertiajs/svelte";
    import { Receipt } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import type { BillingContactShape } from "@/types/billing";

    /**
     * BillingContactForm — 請求先情報 (メール / 宛名) の更新セクション (P9)。
     *
     * 請求書・支払い失敗などの請求通知の宛先になる。未設定の間は組織オーナーのメールへ
     * 送られるため、その旨を help に出す (fallbackEmail はサーバ確定値)。
     *
     * **ボタンは未入力でも disabled にしない** (AGENTS.md 禁止事項 #8) — 押下してサーバの
     * validation 文言を表示する。in-flight の多重送信抑止は Button の loading で表現する。
     */
    interface Props {
        billingContact: BillingContactShape;
        /** 更新 PATCH 先 (billing.contact.update) */
        updateUrl: string;
        canManage: boolean;
    }

    let { billingContact, updateUrl, canManage }: Props = $props();

    let emailText = $state(billingContact.email ?? "");
    let nameText = $state(billingContact.name ?? "");
    let submitting = $state(false);

    const serverErrors = $derived(
        (inertiaPage.props.errors ?? {}) as Record<string, string>,
    );
    const emailError = $derived(!submitting ? (serverErrors.billing_contact_email ?? null) : null);
    const nameError = $derived(!submitting ? (serverErrors.billing_contact_name ?? null) : null);

    const helpText = $derived(
        billingContact.email === null && billingContact.fallbackEmail !== null
            ? `未設定のため、現在は組織オーナー (${billingContact.fallbackEmail}) 宛に送信しています。`
            : "請求書・お支払いに関するご連絡をこのメールアドレスへお送りします。",
    );

    function submit(): void {
        if (submitting) return; // 多重送信ガード (disabled にはしない)
        router.patch(
            updateUrl,
            { billing_contact_email: emailText, billing_contact_name: nameText },
            {
                preserveScroll: true,
                onStart: () => {
                    submitting = true;
                },
                onFinish: () => {
                    submitting = false;
                },
            },
        );
    }
</script>

<Card padding="lg" testId="billing-contact-card">
    <div class="flex items-center gap-2">
        <Receipt class="size-5 text-text-secondary" aria-hidden="true" />
        <h2 class="text-h3">請求先情報</h2>
    </div>

    {#if canManage}
        <form
            novalidate
            class="mt-4 flex flex-col gap-4"
            data-testid="billing-contact-form"
            onsubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <FormField
                label="請求先メールアドレス"
                id="billing-contact-email"
                required
                error={emailError}
                help={helpText}
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="email"
                        bind:value={emailText}
                        error={invalid}
                        aria-describedby={describedBy}
                        testId="billing-contact-email-input"
                    />
                {/snippet}
            </FormField>

            <FormField label="宛名 (任意)" id="billing-contact-name" error={nameError}>
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        bind:value={nameText}
                        error={invalid}
                        aria-describedby={describedBy}
                        testId="billing-contact-name-input"
                    />
                {/snippet}
            </FormField>

            <div>
                <Button type="submit" loading={submitting} testId="billing-contact-submit">
                    請求先情報を保存
                </Button>
            </div>
        </form>
    {:else}
        <dl class="mt-4 grid gap-4 md:grid-cols-2">
            <div>
                <dt class="text-caption text-text-secondary">請求先メールアドレス</dt>
                <dd class="mt-1 text-body text-text" data-testid="billing-contact-email-readonly">
                    {billingContact.email ?? "未設定"}
                </dd>
            </div>
            <div>
                <dt class="text-caption text-text-secondary">宛名</dt>
                <dd class="mt-1 text-body text-text" data-testid="billing-contact-name-readonly">
                    {billingContact.name ?? "未設定"}
                </dd>
            </div>
        </dl>
        <p class="mt-4 text-caption text-text-secondary">
            請求先情報の変更には組織の管理者権限が必要です。
        </p>
    {/if}
</Card>
```
