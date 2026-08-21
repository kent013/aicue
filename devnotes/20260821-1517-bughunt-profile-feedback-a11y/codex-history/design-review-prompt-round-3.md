# 詳細設計レビュー Round 3 — 施策2b/2-T の指摘への対応

Round 2 で施策1/1-T/2/横断は APPROVE をいただきました。REQUEST_CHANGES だった施策2b と施策2-T を
Codex 推奨の方向で再設計しました。全体判定の再判定をお願いします。

## 施策2b [Warning] `{#if message}` 内 aria-live は動的読み上げを安定保証しない + 共有 atom 変更は波及大
→ 対応 (**推奨 option 2 = 局所化を採用**)。**施策 2b (FormError atom の変更) を撤回**しました。
代わりに施策2 (AutoRechargeCard) 内に、**常時 DOM 常在の visually-hidden (`sr-only`) な polite live region**
を 1 つ置きます。

```svelte
<p class="sr-only" aria-live="polite" data-testid="auto-recharge-range-error">
    {inputErrorShown ? (thresholdError ?? maxError ?? "") : ""}
</p>
```

- 要素は常在し**本文だけが更新される**ため、押下後のエラー出現が確実に通知される (空要素を先に置く方式)。
- 可視エラーは各 FormField(FormError) が per-field で表示。sr-only 領域は読み上げ専用で可視の重複を作らない。
- テキストは threshold-first 短絡で常に高々 1 つのアクティブ文言 (可視 FormError と同一)。
- 共有 atom (FormError/FormField/Input) は一切変更せず、変更を F-3-01 に完全局所化 (アプリ全体への波及なし)。
- testId `auto-recharge-range-error` はこの sr-only live region に付け替えて残す。

これにより「本文を持つ live region の新規挿入」問題も、共有 atom の間接利用波及の懸念も解消されます。

## 施策2-T [Warning] live region テストが静的属性確認だけ
→ 対応。状態遷移を検査します。同一 live region 要素 (`getByTestId("auto-recharge-range-error")`) について:
(a) 押下前は要素が DOM に存在し `textContent` が空 →
(b) max "0" 入力 + 押下後、**同一要素**の `textContent` に `/リチャージ後の残高は/` が入る →
(c) max "50" へ訂正後、**同一要素**の `textContent` が空に戻る。
要素同一性を保ったまま本文が更新されること (通知可能な更新構造) を固定します。sr-only であることも確認。

## 施策2-T [Warning] aria-invalid 不在は `not.toHaveAttribute("aria-invalid")` (値指定なし)
→ 対応。Input atom は false 時に属性省略 (`aria-invalid={error || undefined}`) のため、「付かない」契約は
`expect(input).not.toHaveAttribute("aria-invalid")` (値なし) で固定。`("aria-invalid","true")` は使わない。

## 施策2-T [Suggestion] threshold 不正値は "-1" に確定 ("abc" 削除)
→ 対応。`type=number` の非数値 sanitize は DOM 依存のため負数 "-1" のみに確定。

## 施策1-T [Suggestion] × 2 (承認済みだが反映)
→ `Notification::assertSentTo($user, ...)` に (fresh() 不要)。Inertia アサーションに
`->component('Auth/VerifyEmail')` を追加し「正しい props だが誤った画面」も検出。

---

更新後の施策2 / 施策2-T セクション (全文) を添付します。施策1/1-T/横断は Round 2 承認版から不変です。

## 施策2 セクション (更新後・全文)

## 施策2: F-3-01 オートリチャージ範囲エラーの aria-invalid

### 変更箇所
- ファイル: `resources/js/components/features/billing/AutoRechargeCard.svelte`
  - 派生値 (L78-103 付近): `rangeError` / `inputError` を per-field 派生へ再構成
  - threshold FormField (L335-353) / max FormField (L354-373): `error` prop を追加
  - 統合エラー `<p>` (L384-392): 撤去

### 波及変更
- TypeScript 型定義: なし (props/型は不変)。
- 共有 molecule `FormField.svelte` / atom `Input.svelte` / atom `FormError.svelte`: **いずれも改変しない**
  (FormField の既存 `error` prop を使うだけ。共有 atom への波及はゼロ)。
- テストファイル: `tests/js/components/features/billing/AutoRechargeCard.test.ts` を施策2-T で更新。

### 現行コード（要点）
```svelte
const rangeError = $derived.by<string | null>(() => {
    if (parsedThreshold === null) return "リチャージ開始残高は 0 以上の整数で入力してください";
    if (parsedMax === null) return `リチャージ後の残高は ${minCount} 〜 ${maxCountLimit} の整数で入力してください`;
    if (parsedMax <= parsedThreshold) return "リチャージ後の残高は開始残高より大きい値を指定してください";
    return null;
});
const inputError = $derived(inputErrorShown ? rangeError : null);
```
```svelte
<FormField label="リチャージ開始残高 ..." id="auto-recharge-threshold">
    {#snippet children({ id, describedBy, invalid })}
        <Input {id} type="number" ... error={invalid} aria-describedby={describedBy} .../>
    {/snippet}
</FormField>
<!-- max も同型。error={invalid} だが FormField.error 未指定のため invalid 常に false -->
...
{#if inputError !== null}
    <p class="mt-2 text-caption text-danger" aria-live="polite" data-testid="auto-recharge-range-error">
        {inputError}
    </p>
{/if}
```

### 変更後コード（要点）
```svelte
// 原因フィールドを 1 つに特定する raw 派生 (inputErrorShown 非依存 = 妥当性ゲート用)。
// threshold-first 短絡により thresholdErrorText と maxErrorText が同時に非 null にはならない。
const thresholdErrorText = $derived.by<string | null>(() =>
    parsedThreshold === null ? "リチャージ開始残高は 0 以上の整数で入力してください" : null,
);
const maxErrorText = $derived.by<string | null>(() => {
    if (parsedThreshold === null) return null; // 原因は threshold 側。max は巻き込まない
    if (parsedMax === null) {
        return `リチャージ後の残高は ${autoRecharge.minCount} 〜 ${autoRecharge.maxCountLimit} の整数で入力してください`;
    }
    if (parsedMax <= parsedThreshold) {
        return "リチャージ後の残高は開始残高より大きい値を指定してください";
    }
    return null;
});

// 妥当性ゲート (ensureValidRange が参照)。単一 SoT: per-field の合流で従来の threshold-first と同値。
const rangeError = $derived(thresholdErrorText ?? maxErrorText);

// 表示は押下後に初めて提示する現行契約を維持 (禁止事項 #8)。提示開始後は現在入力に追随。
const thresholdError = $derived(inputErrorShown ? thresholdErrorText : null);
const maxError = $derived(inputErrorShown ? maxErrorText : null);
```
```svelte
<FormField label="リチャージ開始残高 ..." id="auto-recharge-threshold" error={thresholdError}>
    {#snippet children({ id, describedBy, invalid })}
        <Input {id} type="number" min="0" step="1" value={thresholdText}
               error={invalid} aria-describedby={describedBy}
               readonly={!autoRecharge.canManage} testId="auto-recharge-threshold-input"
               oninput={...} />
    {/snippet}
</FormField>
<FormField label="リチャージ後の残高 ..." id="auto-recharge-max" error={maxError}>
    {#snippet children({ id, describedBy, invalid })}
        <Input {id} type="number" min={autoRecharge.minCount} max={autoRecharge.maxCountLimit} step="1"
               value={maxText} error={invalid} aria-describedby={describedBy}
               readonly={!autoRecharge.canManage} testId="auto-recharge-max-input"
               oninput={...} />
    {/snippet}
</FormField>
<!-- 可視の統合エラー <p> は撤去 (文言は各 FormField 内の FormError が per-field で描画する)。
     読み上げ専用として、常時 DOM 常在の visually-hidden な polite live region を 1 つ置く。
     要素は常在し本文だけが更新されるため、押下後のエラー出現が確実に通知される
     (要素と本文の同時挿入だと SR が読み落とすことがあるため空要素を先に置く)。
     テキストは提示中の単一アクティブエラー (threshold-first 短絡で常に高々 1 つ)。 -->
<p class="sr-only" aria-live="polite" data-testid="auto-recharge-range-error">
    {inputErrorShown ? (thresholdError ?? maxError ?? "") : ""}
</p>
```
- `inputError` は削除。`rangeError` は残し (妥当性ゲート `ensureValidRange` の `rangeError === null` 判定で使用)。
- sr-only live region の本文は可視 FormError と同一の単一アクティブ文言 (可視の重複は作らない。SR への
  読み上げは、focus 時に効く `aria-describedby` (FormError) と、変化時に効く live region が別タイミングで働く)。
- `ensureValidRange()` / `openConsent()` / `confirmEnable()` / `handleUpdate()` / `handleSaveDraft()` /
  `handleDisable()` のロジックは不変 (`rangeError`/`parsedThreshold`/`parsedMax` を参照する形は維持)。

### 設計上の判断
- **canonical パターンへ寄せる (DESIGN.md §FormField)**: エラー文言・`aria-invalid`・`aria-describedby` の
  配線は FormField の責務。per-field エラー文字列を FormField の `error` に渡すことで、
  `invalid`(=`Boolean(error)`)→snippet→`Input` の `aria-invalid`、`describedBy`→FormError id が既存機構で通る。
- **原因フィールドを 1 つに限定** (Codex Round 1 [Warning] 反映): 大小関係違反 (`parsedMax<=parsedThreshold`) は
  文言が指す max のみ invalid。両欄同時 invalid は起こさない。
- **禁止事項 #8 維持**: `inputErrorShown` による「押下時に初めて提示」の現行契約を変えない。
- **動的読み上げは局所 sr-only live region で担保** (design-review Round 2 反映): 撤去する可視 `<p>` の
  動的通知機能を、AutoRechargeCard 内に常時常在する visually-hidden な polite live region で置き換える。
  共有 atom (FormError) は変更せず、変更を F-3-01 に完全局所化する (アプリ全体への波及回避)。
- **DESIGN.md / Atomic Design**: 色 token (`text-danger`) は FormError atom 内で既存どおり。`sr-only` は
  Tailwind 標準ユーティリティ。hex 直書き・SVG 新設・階層逆流なし。共有 molecule/atom は改変しない。

### リスク
- 可視統合 `<p>` 撤去で `auto-recharge-range-error` testId を参照する箇所が壊れる → testId は sr-only live
  region に付け替えて残す (施策2-T で状態遷移テストへ更新)。他 (Pest/Browser) からの参照が無いことは確認済み。
- FormField 内 FormError には testId が無い → 可視エラーのテストは `getByRole("spinbutton", {name})` の
  `aria-invalid` + `toHaveAccessibleDescription` で assert (testId 非依存。FormField/FormError は不変)。
- sr-only live region と可視 FormError で文言が DOM に 2 箇所出るが、live region は sr-only・単一アクティブ文言
  ミラーで、focus 時 (describedby) と変化時 (live) の別タイミング動作のため実害の重複読み上げは起きない。

---

## 施策2-T: JS コンポーネントテスト更新

対象: `tests/js/components/features/billing/AutoRechargeCard.test.ts`。既存 `auto-recharge-range-error`
testId 参照 (6 箇所, L123/135/144/148/158/164) を「利用者視点」の assert に更新 (カバレッジは削除せず移設)。
入力取得は `getByRole("spinbutton", { name: ... })` (label と input の配線も同時に回帰検査)。
props 既定は `autoRechargeProps` (thresholdCount=5, minCount=1, maxCountLimit=1000)。

3 分岐を**別個の値で**区別して固定する:
- [ ] **(2) max 解析/範囲エラー → max のみ invalid (F-3-01 本体)**: `hasPaymentMethod:true` で render →
      max spinbutton に "0" (< minCount 1 → parsedMax null) → enable 押下 →
      max spinbutton が `toHaveAttribute("aria-invalid","true")` かつ
      `toHaveAccessibleDescription(/リチャージ後の残高は 1 〜 1000 の整数/)` (describedby 関連付けまで検査)。
      threshold spinbutton は `not.toHaveAttribute("aria-invalid")` (値指定なし。Input は false 時に属性省略)。
- [ ] **(1) threshold 解析/範囲エラー → threshold のみ invalid**: threshold spinbutton に "-1" (負数。
      非数値文字列 "abc" は `type=number` の sanitize が DOM 依存のため使わない) → 押下 → threshold spinbutton
      が `aria-invalid=true` + `toHaveAccessibleDescription(/リチャージ開始残高は 0 以上の整数/)`、
      max spinbutton は **`not.toHaveAttribute("aria-invalid")`** (値指定なし。Input は false 時に属性省略)。
- [ ] **(3) 個別有効だが max<=threshold → max のみ invalid**: threshold spinbutton="5"(既定)・
      max spinbutton="3" (3 は minCount..limit で個別有効かつ 3<=5) → 押下 → max spinbutton が
      `aria-invalid=true` + `toHaveAccessibleDescription(/開始残高より大きい値/)`、threshold は
      `not.toHaveAttribute("aria-invalid")`。(この具体値で `parsedMax===null` 分岐を踏むだけの false pass を防ぐ。)
- [ ] **押下前は aria-invalid が付かない (禁止事項 #8)**: max spinbutton に "0" 入力しても押下前は
      `expect(maxInput).not.toHaveAttribute("aria-invalid")` (既存 L128 の意図を aria-invalid で再表現)。
- [ ] **有効値へ直すと aria-invalid が消える (既存 F-3-05 の意図)**: max "0" → 押下 (invalid) → max "50" →
      max spinbutton が `not.toHaveAttribute("aria-invalid")` (既存 L138 の移設)。
- [ ] **sr-only live region の状態遷移** (可視 `<p>` 撤去の後退防止): 同一 live region 要素
      (`getByTestId("auto-recharge-range-error")`) について
      (a) 押下前は要素が DOM に存在し `textContent` が空 →
      (b) max "0" 入力 + 押下後、**同一要素**の `textContent` に `/リチャージ後の残高は/` が入る →
      (c) max "50" へ訂正後、**同一要素**の `textContent` が空に戻る。要素同一性を保ったまま本文が更新される
      ことを固定する (「属性を付けた」だけでなく「通知可能な更新構造」を保証)。要素が sr-only であることも確認。
- 既存の他 assert (`auto-recharge-consent` を開かない 等) は据え置き。

---

## 横断: テストファースト順序と検証コマンド
