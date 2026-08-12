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

あなたは経験豊富なコードレビュアーです。Svelte 5 runes + Inertia の実装をレビューしてください。

【レビュー観点】
1. 詳細設計との一致性 2. 正確性 (競合・エッジケース) 3. 型安全性
4. テストが実際に退行を検出できるか (mutation 実測を添付) 5. 副作用・後退リスク
6. DESIGN.md 準拠 7. Atomic Design 準拠

【特に見てほしい点】
- 設計からの乖離 1 点 (下記) の判断は妥当か
- 編集世代の実装 (`$state` にしていないローカル変数) で反応性の問題は起きないか
- テストが実装詳細に寄りすぎていないか

【出力形式】
ファイルごとに APPROVE / REQUEST_CHANGES、[Critical] [Warning] [Suggestion]、全体判定、日本語

---

## 詳細設計書 (APPROVED 済み)

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


---

## テスト結果 (worktree 内)

`pnpm test` 1357 passed / `composer test` 4511 passed・2 skipped / `composer phpstan` No errors /
`pnpm lint` / `pnpm typecheck` / `vendor/bin/pint --test` / `pnpm build` / packages 106: 全緑。

### fail 先行 (設計の予測と実測)

予測「契約 0..3 と 6..12 が赤、契約 4 と 5 だけ緑」→ **実測一致** (14 件中 10 赤 / 4 緑)。
ただし最初の版では契約 11 (2 件) が**偽の緑**になっていた。callback を同期的に呼んだ後
DOM 反映を待っていなかったためで、`await tick()` を足して 12 赤 / 2 緑
(契約 4・5 のみ緑) に是正した。**この偽緑は fail 先行の段階で捕まえた**。

### 設計からの乖離 1 点

**契約 0 のテストスタブを `reactiveUseForm` (既存 `tests/js/support/reactiveUseForm.svelte.ts`) に変えた。**
当初は plain object の useForm stub を書いたが、`errors` が非反応なため
`clearErrors` の削除が再描画に繋がらず、契約 0 (DOM から文言が消える) を観測できなかった
(実測: `expected <p …> to be null` で赤のまま)。リポジトリに既にある反応的フェイクへ差し替えて解決。

### mutation の実測 (予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | `clearErrors("name")` → 引数なし | 契約 1 と 3 | **一致** (2 件) |
| M2 | `Projects/Create` の `oninput` 削除 | 契約 1 | **一致** (契約 0・1・3 の 3 件) |
| M3 | `emailEdited` を抑制条件から外す | 契約 6 | **一致** (8 件) |
| M4 | email/name を共有フラグにする | 契約 7 | **一致** (契約 7・12) |
| M5 | 解除を `onError` から `onFinish` へ移す | 契約 10 | **一致** (1 件) |
| M6 | `onSuccess` の解除を削除 | 契約 9 | **一致** (契約 8/9 の onSuccess 側 1 件) |
| M7 | 編集世代の一致判定を外す | 契約 11 (両 callback) | **一致** (契約 11 ×2・12 の 3 件) |
| M8 | 「どちらかが動いていたら両方解除しない」に変える | 契約 12 | **一致** (契約 12 のみ 1 件) |

**8 種すべて予測どおり。** 特に M8 が契約 12 だけを赤くしたことで、
「フィールドごとの編集世代」を採った中核の理由が機械的に守られていることを実測できた。

---

## 実装差分 (git diff)

```diff
diff --git a/resources/js/components/features/billing/BillingContactForm.svelte b/resources/js/components/features/billing/BillingContactForm.svelte
index 9fb6755..eeb9d04 100644
--- a/resources/js/components/features/billing/BillingContactForm.svelte
+++ b/resources/js/components/features/billing/BillingContactForm.svelte
@@ -29,11 +29,33 @@
     let nameText = $state(billingContact.name ?? "");
     let submitting = $state(false);
 
+    /**
+     * 「前回の検証結果を、ユーザーが編集したので stale とみなして隠す」フラグ (T157)。
+     * **値の正誤は見ていない** (クライアント検証ではない)。
+     */
+    let emailEdited = $state(false);
+    let nameEdited = $state(false);
+
+    /**
+     * フィールドごとの**編集世代**。編集のたびに増える。送信時の世代を控えておき、
+     * **応答時に世代が変わっていないフィールドだけ**抑制を解く。
+     * 送信中にさらに編集された場合は解かない — その応答は**古い値**への検証結果であり、
+     * それを根拠に stale なエラーを出し直すのは誤りだから。
+     */
+    let emailEditVersion = 0;
+    let nameEditVersion = 0;
+
     const serverErrors = $derived(
         (inertiaPage.props.errors ?? {}) as Record<string, string>,
     );
-    const emailError = $derived(!submitting ? (serverErrors.billing_contact_email ?? null) : null);
-    const nameError = $derived(!submitting ? (serverErrors.billing_contact_name ?? null) : null);
+    // 抑制条件に編集済みフラグを足す。**page props の同一性や router の finish には依存しない**
+    // (どちらも無関係な visit で古いエラーを復活させるため)
+    const emailError = $derived(
+        !submitting && !emailEdited ? (serverErrors.billing_contact_email ?? null) : null,
+    );
+    const nameError = $derived(
+        !submitting && !nameEdited ? (serverErrors.billing_contact_name ?? null) : null,
+    );
 
     const helpText = $derived(
         billingContact.email === null && billingContact.fallbackEmail !== null
@@ -43,6 +65,17 @@
 
     function submit(): void {
         if (submitting) return; // 多重送信ガード (disabled にはしない)
+        // 送信時点の編集世代を控える (応答が返るまでに編集されたかを判定するため)
+        const emailVersionAtSubmit = emailEditVersion;
+        const nameVersionAtSubmit = nameEditVersion;
+
+        // **この送信の検証結果が届いた**フィールドだけ抑制を解く (同じ文言でも必ず再表示される)。
+        // onFinish は使わない (キャンセル・通信失敗でも動くため再表示の根拠にならない)。
+        const release = (): void => {
+            if (emailEditVersion === emailVersionAtSubmit) emailEdited = false;
+            if (nameEditVersion === nameVersionAtSubmit) nameEdited = false;
+        };
+
         router.patch(
             updateUrl,
             { billing_contact_email: emailText, billing_contact_name: nameText },
@@ -51,6 +84,8 @@
                 onStart: () => {
                     submitting = true;
                 },
+                onError: release,
+                onSuccess: release,
                 onFinish: () => {
                     submitting = false;
                 },
@@ -90,6 +125,10 @@
                         error={invalid}
                         aria-describedby={describedBy}
                         testId="billing-contact-email-input"
+                        oninput={() => {
+                            emailEdited = true;
+                            emailEditVersion += 1;
+                        }}
                     />
                 {/snippet}
             </FormField>
@@ -102,6 +141,10 @@
                         error={invalid}
                         aria-describedby={describedBy}
                         testId="billing-contact-name-input"
+                        oninput={() => {
+                            nameEdited = true;
+                            nameEditVersion += 1;
+                        }}
                     />
                 {/snippet}
             </FormField>
diff --git a/resources/js/pages/Projects/Create.svelte b/resources/js/pages/Projects/Create.svelte
index e943ae9..35b2080 100644
--- a/resources/js/pages/Projects/Create.svelte
+++ b/resources/js/pages/Projects/Create.svelte
@@ -46,6 +46,11 @@
                                 bind:value={form.name}
                                 error={invalid}
                                 aria-describedby={describedBy}
+                                oninput={() => {
+                                    // 入力し始めたらその場でエラーを消す (次 submit を待たない)。
+                                    // **値そのものを消す**ので、同じ文言が再び返れば必ず再表示される。
+                                    if (form.errors.name) form.clearErrors("name");
+                                }}
                             />
                         {/snippet}
                     </FormField>
@@ -56,6 +61,11 @@
                                 bind:value={form.description}
                                 error={invalid}
                                 aria-describedby={describedBy}
+                                oninput={() => {
+                                    // フィールド単位で消す (引数なし clearErrors は使わない =
+                                    // 片方の編集で他方のエラーを消さない)
+                                    if (form.errors.description) form.clearErrors("description");
+                                }}
                             />
                         {/snippet}
                     </FormField>
```
