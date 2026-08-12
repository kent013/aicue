# Round 3: Round 2 指摘への対応

Warning 2 件・Suggestion 3 件を**すべて対応**しました (契約 12 と mutation M8 の追加、
両 callback のパラメータ化、fail 先行の対象修正、改名、callback 順序の明示)。反論はありません。

APPROVED にできるか確認してください。

---

# 対応マトリクス: design-review Round 2

判定 REQUEST_CHANGES (Warning 2 / Suggestion 3。実装方式の再設計は不要との評価)。**すべて対応**。

## [Warning] 「送信中に片方だけ編集した場合、もう片方は解除される」契約が無い

- 判断: **対応する**
- 根拠: 妥当。契約 11 だけだと「どれか 1 つでも編集されたら両方解除しない」という誤実装が通る。
  フィールドごとの編集世代を採った**中核の理由**がテストで守られていなかった。
- 対応内容: **契約 12** を追加 (送信中に email だけ編集 → email は抑制継続・name は解除)。
  対応 mutation **M8** (「どちらかが動いていたら両方解除しない」に変える) も追加した。

## [Warning] 契約 11 は `onError` / `onSuccess` の両経路を実際に試す必要がある

- 判断: **対応する**
- 対応内容: 契約 11 に「**両経路をパラメータ化して試す**」と明記した
  (片方だけだと、片方から世代判定を削除する mutation が生き残る)。

## [Suggestion] fail 先行の対象に契約 0 が抜けている

- 判断: **対応する**
- 対応内容: 「契約 0..3 と 6..12 が赤くなる。**契約 4 と 5 だけが既存実装でも緑**」に修正した。

## [Suggestion] M1 は契約 1 でも検出される

- 判断: **対応する**
- 対応内容: M1 の予測を「契約 1 (引数を直接検証) と 契約 3」に修正した。

## [Suggestion] `emailAtSubmit` は値のスナップショットに見える

- 判断: **対応する**
- 対応内容: `emailVersionAtSubmit` / `nameVersionAtSubmit` に改名した。

## [Suggestion] 契約 8..11 の callback 順序を明示せよ

- 判断: **対応する**
- 対応内容: `入力 → submit → onStart → (送信中の追加入力) → onError | onSuccess → onFinish` を
  設計に明記し、jsdom で `router.patch` の options を捕捉して同期的に呼べば再現できることも書いた。
\n\n---\n\n## 改訂後の詳細設計 (全文)\n\n# 詳細設計: form-stale-invalid

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
 * フィールドごとの**編集世代**。編集のたびに増える。
 * 送信時の値を控えておき、**応答が返った時点で世代が変わっていなければ**解除する
 * (= その応答は今画面に出ている値に対する検証結果である)。
 * 送信中にさらに編集された場合は解除しない — その応答は**古い値**への検証結果であり、
 * それを根拠に stale なエラーを出し直すのは誤りだから。
 */
let emailEditVersion = 0;
let nameEditVersion = 0;

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
    // 送信時点の編集世代を控える (応答が返るまでに編集されたかを判定するため)
    const emailVersionAtSubmit = emailEditVersion;
    const nameVersionAtSubmit = nameEditVersion;

    // **この送信の検証結果が届いた**フィールドだけ抑制を解く (同じ文言でも必ず再表示される)。
    // onFinish は使わない (キャンセル・通信失敗でも動くため再表示の根拠にならない)。
    const release = (): void => {
        if (emailEditVersion === emailVersionAtSubmit) emailEdited = false;
        if (nameEditVersion === nameVersionAtSubmit) nameEdited = false;
    };

    router.patch(updateUrl, { billing_contact_email: emailText, billing_contact_name: nameText }, {
        preserveScroll: true,
        onStart: () => { submitting = true; },
        onError: release,
        onSuccess: release,
        onFinish: () => { submitting = false; },
    });
}
```

markup 側は各 `Input` に `oninput` を足すだけ:

```svelte
<Input … oninput={() => { emailEdited = true; emailEditVersion += 1; }} />
<Input … oninput={() => { nameEdited = true; nameEditVersion += 1; }} />
```

### 設計判断

- **解除契機は自分の送信の結果だけ** (`onError` / `onSuccess`)。
  `router.on('finish')` / page props の同一性は**使わない** — どちらも無関係な visit で
  古いエラーを復活させる (概念設計の棄却理由)。
- **`onFinish` は `submitting` を戻すためだけに使う** (従来どおり)。解除には使わない。
- **守るべき競合は「後続 submit」ではなく「送信中の編集」である** (Round 1 [Critical])。
  `submitting` ガードがある以上、同一フォームからの後続 submit は通常起きない。
  一方「送信 → 応答待ちの間にさらに入力 → 古い値への検証結果が返る」は起こりうる。
  そこで**送信世代ではなくフィールドごとの編集世代**を使い、
  **送信時から世代が変わっていないフィールドだけ**解除する。
- この判定は「送信成功したが、その間にユーザーが値を変えていた」ケースも同時に扱う —
  世代が動いているので `onSuccess` でも解除せず、**画面の値とサーバ保存値の食い違いを
  「検証済み」に見せない**。
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
| 0 | `name` のエラーが表示されている状態で `name` に入力すると、**DOM からエラー文言が消える** (`FormField`/`Input` 連携ごと固定する) |
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
| 9 | `onSuccess` でも編集済みが初期化される (次の操作へ持ち越さない)。**テストでは page props のエラーを据え置いて観測する** (実運用では成功応答で errors は消えるため、観測可能にするための約束事) |
| 10 | **`onFinish` だけでは再表示されない** (キャンセル・通信失敗で復活させない) |
| 11 | **送信中にさらに編集した**フィールドは、その送信の `onError` / `onSuccess` では解除されない (編集世代)。**`onError` と `onSuccess` の両経路をパラメータ化して試す** |
| 12 | **送信中に email だけ編集**した場合、応答後も email は抑制されたままで、**編集していない name の抑制は解除される** (フィールドごとの世代である根拠。M4 とは別の分岐) |

契約 8..12 の callback 順序は次で固定する (jsdom で `router.patch` の options を捕捉し、
保存した callback を任意の順で同期的に呼べば再現できる。特殊なブラウザ機能は要らない):

```text
入力 → submit → onStart → (送信中の追加入力) → onError | onSuccess → onFinish
```

### fail 先行

施策 3 のテストだけを先に置き、**契約 0..3 と 6..12 が赤くなる**ことを確認してから実装する
(実装前は DOM 上のエラーも消えないので契約 0 も赤い。**契約 4 と 5 だけが既存実装でも緑**の想定。
実測して記録する)。

### mutation 計画

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | `clearErrors("name")` を引数なし `clearErrors()` にする | 契約 1 (引数を直接検証) と 契約 3 |
| M2 | `Projects/Create` の `oninput` を削除 | 契約 1 |
| M3 | `emailEdited` を抑制条件から外す | 契約 6 |
| M4 | `emailEdited` / `nameEdited` を 1 つの共有フラグにする | 契約 7 |
| M5 | 解除を `onError` から `onFinish` へ移す | 契約 10 |
| M6 | `onSuccess` の解除を削除 | 契約 9 |
| M7 | 編集世代の一致判定を外し、常に解除する | 契約 11 (両 callback) |
| M8 | 編集世代の判定を「どちらかが動いていたら両方解除しない」に変える | 契約 12 |

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
