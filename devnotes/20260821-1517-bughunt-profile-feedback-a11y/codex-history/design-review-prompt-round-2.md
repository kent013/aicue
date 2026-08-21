# 詳細設計レビュー Round 2 — 指摘への対応

Round 1 の Warning (Critical は 0 件) を全て反映しました。全体判定の再判定をお願いします。

## 施策1 [Warning] 「認証メールを送信しました」の前提が未テスト
→ 対応。施策1-T に `Notification::assertSentTo($user->fresh(), \Illuminate\Auth\Notifications\VerifyEmail::class)`
を追加 (`UpdateUserProfileInformation` が email 変更時 `sendEmailVerificationNotification()` を呼ぶ経路を裏付け)。
確定表現「認証メールを送信しました」をテストで保証。

## 施策1-T [Warning] session だけでなく Inertia props の flash.success を検査
→ 対応。PUT の 302 を追って `/email/verify` を Inertia GET し、
`assertInertia(fn (AssertableInertia $page) => $page->where('flash.success', EMAIL_CHANGED_MESSAGE))` で
consumeFlash が読む共有 prop 値まで固定 (HandleInertiaRequests の share 配線が将来壊れても検出)。

## 施策1-T [Warning] recent-auth 統合を「fresh 直接 PUT で代替可」は不十分
→ 対応。代替表現を撤回。`ProfileEmailChangeRecentAuthTest` に統合テストを追加:
stale で email 変更 PUT=409 → `POST /recent-auth/password` (正しいパスワード)=204 → 元 PUT 再送 →
`assertRedirect(verification.notice)` + session success。再認証完了後の元操作再送の実経路を server 側で通す。

## 施策1-T [Warning] JSON 本文を正確に固定
→ 対応。「空 JSON」を撤回。HTTP 200 / Content-Type application/json / 本文が正確に `""`
(`expect($response->getContent())->toBe('""')`) を固定。

## 施策1-T [Suggestion] Factory state / recent_auth fresh を明示
→ 対応。変更前は verified 済み・`withSession(['recent_auth_at' => time()])` を明示設定する旨をテスト計画に記載。

## 施策2 [Warning] 統合 `<p aria-live>` 撤去で動的読み上げが消える (a11y 後退)
→ 対応 (option (b) 採用)。施策 2b を新設し **FormError atom に `aria-live="polite"` を付与**。
FormError は現状 `aria-live`/`role="alert"` を持たない (読了確認)。FormField 経由に寄せるだけだと AutoRecharge の
動的読み上げが失われるため、FormError 自体に polite live region を付ける (全フォーム共通の底上げ、後退なし)。
`role="alert"` (assertive) ではなく `polite` を選択 (撤去する `<p>` も polite で挙動一致)。
`{#if message}` により live region はメッセージ出現と同時挿入 = 静的初期エラーの過剰通知は起きない。
FormField.test.ts に aria-live の assert を追加 (既存 assert は不変)。

## 施策2-T [Warning] getByText は誤検出・関連付け未保証
→ 対応。`getByRole("spinbutton", { name })` で入力取得 + `toHaveAttribute("aria-invalid","true")` +
`toHaveAccessibleDescription(正確な文言)` (jest-dom 6.9.1 導入済み) で describedby 関連付けまで検査。

## 施策2-T [Warning] max<=threshold の具体値・3 分岐区別
→ 対応。props 既定 (thresholdCount=5, minCount=1, maxCountLimit=1000) で 3 ケースを別個に固定:
(1) threshold 解析エラー: threshold="-1" → threshold のみ invalid /
(2) max 解析エラー: max="0" → max のみ invalid /
(3) 個別有効だが max<=threshold: threshold="5"・max="3" (3 は minCount..limit で有効かつ 3<=5) → max のみ invalid。

## 横断 [Warning] テストファースト順序・検証コマンド一覧
→ 対応。「横断: テストファースト順序と検証コマンド」節を追加。実行順序 (テスト先行 fail → 実装 → green →
全検証) と AGENTS.md L336-338 の一覧 (`composer test`/`composer phpstan`/`vendor/bin/pint --test`/`pnpm lint`/
`pnpm typecheck`/`pnpm test`/`pnpm build` + package 系 3 コマンド) を明記。`composer fix`/`pnpm lint:fix` は
完了条件でないことも記載。

---

更新後の詳細設計書 (全文) を添付します。

## 詳細設計書 (更新後・全文)

# 詳細設計: bughunt-profile-feedback-a11y

> Codex 合議ステータス: 概念設計は gpt-5.6-terra との合議で **APPROVED (Round 2)**。
> 詳細設計はこのファイルで gpt-5.6-sol と合議する。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項
1. テストなしの実装完了報告 (不変条件は Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()` (ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示する)
9. Artifact の使用

### コーディングルール
- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)、**RefreshDatabase** グローバル + `--parallel` (個別 `DatabaseTransactions` 禁止)
- テストデータは **Factory** で生成
- **DTO + JsonResource** パターン
- コードフォーマット: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス
- [conceptual-design.md](./conceptual-design.md) — APPROVED (conceptual-review-round-2.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | F-4-01: メール変更成功時に認証画面で成功フィードバックを出す | `app/Http/Responses/Fortify/ProfileUpdatedResponse.php` | Medium |
| 1-T | 施策1 の Feature テスト | `tests/Feature/Auth/FortifyResponseTest.php` / `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php` | Medium |
| 2 | F-3-01: オートリチャージ範囲エラーで対象 spinbutton に aria-invalid | `resources/js/components/features/billing/AutoRechargeCard.svelte` | Low |
| 2b | F-3-01: FormError の動的読み上げ (aria-live) を保証 (統合 `<p aria-live>` 撤去の後退防止) | `resources/js/components/atoms/FormError.svelte` | Low |
| 2-T | 施策2/2b の JS コンポーネントテスト更新 | `tests/js/components/features/billing/AutoRechargeCard.test.ts` / `tests/js/components/molecules/FormField.test.ts` | Low |

---

## 施策1: F-4-01 メール変更成功時のフィードバック

### 変更箇所
- ファイル: `app/Http/Responses/Fortify/ProfileUpdatedResponse.php` (全 35 行、`toResponse` L27-34)

### 波及変更
- TypeScript 型定義: なし (Inertia props / API レスポンス形状は不変。redirect 先が変わるだけ)。
- API Resource/DTO: なし (`expectsJson` 経路の `JsonResponse('', 200)` は不変。DTO 新設なし)。
- テストファイル: `tests/Feature/Auth/FortifyResponseTest.php` に施策1-T を追加 (下記)。
- フロントエンド: `Settings/Index.svelte` / `RecentAuthModal.svelte` / `lib/recent-auth.ts` は変更不要
  (Inertia が 302 を追って `/email/verify` を GET する既存挙動に乗る。トーストは `flash-to-toast` が既存機構で出す)。

### 現行コード
```php
public function toResponse($request): JsonResponse|RedirectResponse
{
    if ($request->expectsJson()) {
        return new JsonResponse('', 200);
    }

    return back()->with('success', self::SUCCESS_MESSAGE);
}
```

### 変更後コード
```php
final class ProfileUpdatedResponse implements ProfileInformationUpdatedResponseContract
{
    private const string SUCCESS_MESSAGE = 'プロフィールを更新しました。';

    /**
     * メール変更時の成功メッセージ。着地は /email/verify (verification.notice) で、
     * そこで「変更は成功した・次は認証」を明示する。新アドレス文字列は載せない
     * (画面の auth.user.email が既に新アドレスを保持しており、メッセージへの埋め込みは冗長)。
     */
    private const string EMAIL_CHANGED_MESSAGE = 'メールアドレスを変更しました。新しいアドレスに認証メールを送信しましたので、認証を完了してください。';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse('', 200);
        }

        // メール変更時は UpdateUserProfileInformation が email_verified_at を null 化する。
        // その状態で back() (= /settings) へ戻すと、/settings の 'verified' (素の
        // EnsureEmailIsVerified) が verification.notice へもう一段 302 し、素の verified は
        // flash を keep しないため success flash がこの中間ホップで期限切れ廃棄される
        // (bug-hunt F-4-01)。着地画面 (/email/verify、auth のみで verified ゲート外) へ
        // 直接寄せ、そこで成功を明示する。$request->user() はこのリクエストで action が
        // save() した同一インスタンスを memo 返しするため wasChanged('email') が読める。
        $user = $request->user();
        if ($user instanceof User && $user->wasChanged('email')) {
            return redirect()->route('verification.notice')
                ->with('success', self::EMAIL_CHANGED_MESSAGE);
        }

        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
```
- import 追加: `use App\Models\User;`

### 設計上の判断
- **判定は `wasChanged('email')`** (`!hasVerifiedEmail()` 単独ではない)。Codex Round 1 [Warning] 反映。
  「今このリクエストで email が変わった」という操作事実を Eloquent の変更追跡で直接見る。氏名のみ変更・
  同一 email early-return は `wasChanged('email')=false` で従来 `back()` を維持する。
- **`redirect()->route('verification.notice')` は禁止事項 #7 (redirect()->intended()) に抵触しない**
  (intended は使わない。名前付き route への明示 redirect)。
- **着地の妥当性**: メール変更後は現状でも必ず `/email/verify` へ到達している (verified 302 の連鎖)。
  本施策は「到達先を 1 ホップ短縮し、その画面で成功 flash を出す」だけで、ユーザーが見る最終画面は不変。
- **flash 生存の根拠**: `verification.notice` (= `/email/verify`) は `auth` のみ。verified ゲート配下でないため
  中間 302 が発生せず、302 に載せた `success` flash が GET `/email/verify` の 1 hop で正しく消費される。
  `VerifyEmail.svelte` は `AuthLayout` を使い `consumeFlash` が `success` をトースト化する (既存機構)。

### PHPStan適合チェック
- [x] 戻り値型 `JsonResponse|RedirectResponse` を維持
- [x] `$request->user()` は `User|Authenticatable|null` union のため `instanceof User` で narrowing してから
      `wasChanged()` を呼ぶ (null 安全)
- [x] DTO を返す箇所ではない (Fortify contract の RedirectResponse/JsonResponse 契約に従う)
- [x] Generics 不使用

### テスト計画 (施策1-T)
対象: `tests/Feature/Auth/FortifyResponseTest.php` (レスポンス分岐) と
`tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php` (recent-auth 統合)。Factory (`User::factory()`) で
ユーザー生成。変更前は **verified 済み**、`->withSession(['recent_auth_at' => time()])` で fresh を明示設定
(Factory の暗黙 default に依存させない)。`Notification::fake()`。

FortifyResponseTest 側:
- [ ] **バグ再現 → 修正確認 (fresh + メール変更, web)**: fresh recent_auth で
      `put('/user/profile-information', ['name'=>$user->name, 'email'=>'new@example.com'])` →
      `assertRedirect(route('verification.notice'))` かつ `assertSessionHas('success', EMAIL_CHANGED_MESSAGE)`。
- [ ] **着地画面が flash.success を Inertia prop として受け取る**: 上記の後、`/email/verify` を Inertia GET
      (`->get('/email/verify')` を Inertia アサーションで) し、
      `assertInertia(fn (AssertableInertia $page) => $page->where('flash.success', EMAIL_CHANGED_MESSAGE))`
      で `consumeFlash` が読む共有 prop 値まで固定 (session だけでなく props 配線の回帰。将来 share が壊れても検出)。
- [ ] **認証メール送信の裏付け** (メッセージ「認証メールを送信しました」の前提): 上記メール変更 put の後に
      `Notification::assertSentTo($user->fresh(), \Illuminate\Auth\Notifications\VerifyEmail::class)`。
- [ ] **氏名のみ更新 (web) は従来どおり**: `email` を現行と同一で put → `assertRedirect`(`back()` 相当)
      かつ `assertSessionHas('success', 'プロフィールを更新しました。')`。`verification.notice` へは飛ばない。
- [ ] **expectsJson は 200 JSON 維持**: `putJson('/user/profile-information', ['name'=>..., 'email'=>'new@...'])`
      → `assertOk()`、Content-Type が `application/json`、本文が正確に `""`
      (`expect($response->getContent())->toBe('""')`。「空 JSON」の曖昧表現は使わない)。

ProfileEmailChangeRecentAuthTest 側 (recent-auth 統合、直接 PUT 代替を撤回):
- [ ] **stale → 再認証完了 → 元操作再送 → 認証画面で成功**: stale セッション (remember 復元相当、
      `recent_auth_at` 未 stamp) で email 変更 PUT (Inertia mutation) = 409 (既存 1a で担保) → 同一セッションで
      `POST /recent-auth/password` (正しいパスワード) = 204 → 元の email 変更 PUT を再送 →
      `assertRedirect(route('verification.notice'))` + `assertSessionHas('success', EMAIL_CHANGED_MESSAGE)`。
      再認証完了後の元操作再送という実経路を server 側で通し、最終着地とフィードバックまで固定する。

- [ ] 全テストで `DatabaseTransactions` を個別使用しない (グローバル `RefreshDatabase` に従う)。
- 既存テスト `ProfileEmailChangeRecentAuthTest::3` は Location が `recent-auth.confirm` でないことを assert する
      のみで `verification.notice` への redirect は許容 → **本施策で壊れない** (回帰なし)。

### リスク
- `$request->user()` が action の save した instance と別 instance だと `wasChanged('email')` が false になり
  従来 `back()` に落ちる (= 現状のバグに戻るだけで、悪化はしない fail-safe)。Fortify の
  `ProfileInformationController` は `$request->user()` を action に渡し、レスポンスも同 request の
  `$request->user()` を見るため同一インスタンス。テストで固定する。
- `verification.notice` route 名が将来変わると 500。Fortify 標準の安定した route 名であり、テストが
  `route('verification.notice')` で参照するため名称変更時はテストが落ちて気付ける。

---

## 施策2: F-3-01 オートリチャージ範囲エラーの aria-invalid

### 変更箇所
- ファイル: `resources/js/components/features/billing/AutoRechargeCard.svelte`
  - 派生値 (L78-103 付近): `rangeError` / `inputError` を per-field 派生へ再構成
  - threshold FormField (L335-353) / max FormField (L354-373): `error` prop を追加
  - 統合エラー `<p>` (L384-392): 撤去

### 波及変更
- TypeScript 型定義: なし (props/型は不変)。
- 共有 molecule `FormField.svelte` / atom `Input.svelte`: **改変しない** (既存の `error` prop を使うだけ)。
- 共有 atom `FormError.svelte`: 施策 2b で `aria-live="polite"` を付与 (統合 `<p aria-live>` 撤去に伴う動的
  読み上げ後退の防止。下記施策 2b 参照)。
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
<!-- 統合エラー <p auto-recharge-range-error> は撤去。文言は各 FormField 内の FormError が描画する。 -->
```
- `inputError` は削除。`rangeError` は残し (妥当性ゲート `ensureValidRange` の `rangeError === null` 判定で使用)。
- `ensureValidRange()` / `openConsent()` / `confirmEnable()` / `handleUpdate()` / `handleSaveDraft()` /
  `handleDisable()` のロジックは不変 (`rangeError`/`parsedThreshold`/`parsedMax` を参照する形は維持)。

### 設計上の判断
- **canonical パターンへ寄せる (DESIGN.md §FormField)**: エラー文言・`aria-invalid`・`aria-describedby` の
  配線は FormField の責務。per-field エラー文字列を FormField の `error` に渡すことで、
  `invalid`(=`Boolean(error)`)→snippet→`Input` の `aria-invalid`、`describedBy`→FormError id が既存機構で通る。
- **原因フィールドを 1 つに限定** (Codex Round 1 [Warning] 反映): 大小関係違反 (`parsedMax<=parsedThreshold`) は
  文言が指す max のみ invalid。両欄同時 invalid は起こさない。
- **禁止事項 #8 維持**: `inputErrorShown` による「押下時に初めて提示」の現行契約を変えない。
- **DESIGN.md / Atomic Design**: `text-danger` 等 token 参照は FormError atom 内で既存どおり。hex 直書き・
  SVG 新設・階層逆流なし。共有 molecule/atom は改変しない。

### リスク
- 統合 `<p>` 撤去で `auto-recharge-range-error` testId を参照する箇所が壊れる → 施策2-T で更新。
  他 (Pest/Browser) からの参照が無いことは確認済み (grep: 当該 svelte とその JS テストのみ)。
- FormField 内 FormError には testId が無い → テストは `getByRole("spinbutton", {name})` の `aria-invalid` +
  `toHaveAccessibleDescription` で assert する (testId 依存を外す。FormField は改変しない)。

---

## 施策2b: F-3-01 FormError の動的読み上げ (aria-live)

### 変更箇所
- ファイル: `resources/js/components/atoms/FormError.svelte` (全 18 行、`<p>` 要素)

### 波及変更
- 影響範囲: FormError は `FormField.svelte` と `Checkbox.svelte` から利用される (grep 確認)。いずれも
  フォームエラー文脈であり、動的読み上げ付与は全体の a11y 底上げになる (後退なし)。
- テスト: `tests/js/components/molecules/FormField.test.ts` に aria-live の assert を追加 (既存 assert は不変)。

### 現行コード
```svelte
{#if message}
    <p {id} class="text-caption text-danger" data-testid={testId}>{message}</p>
{/if}
```

### 変更後コード
```svelte
{#if message}
    <!-- 押下/送信後に現れるエラーを支援技術へ動的通知する (polite = 現在の読み上げを妨げない)。
         静的な初期エラーは live region 挿入時に読み上げられないため過剰通知にはならない。 -->
    <p {id} class="text-caption text-danger" aria-live="polite" data-testid={testId}>{message}</p>
{/if}
```

### 設計上の判断
- AutoRecharge が現在持つ独立 `<p aria-live="polite">` を FormField 経由に統合すると、その動的読み上げが
  失われる (FormError は現状 `aria-live`/`role="alert"` を持たない = 読了確認)。これを補うため FormError 自体に
  `aria-live="polite"` を付ける。Codex 詳細レビュー [Warning] の option (b) 準拠。
- `role="alert"` (= assertive) ではなく `aria-live="polite"` を選ぶ: 入力訂正フィードバックは現在の
  読み上げを中断してまで割り込む性質ではない。撤去する AutoRecharge の `<p>` も `polite` であり挙動一致。
- 1 属性の追加のみで over-engineering ではない。DESIGN.md の token/atom 責務にも整合 (色は既存 `text-danger`)。

### PHPStan適合チェック
- 該当なし (Svelte)。`pnpm typecheck` (tsc --noEmit) は .svelte テンプレートを型検査しないため、契約は
  `pnpm test` (vitest) のコンポーネントテストで固定する。

### テスト計画 (施策2-T に含める)
- [ ] **FormError の aria-live**: `tests/js/components/molecules/FormField.test.ts` の「error 時」ケースで、
      エラー要素 (`#name-error`) が `aria-live="polite"` を持つことを追加 assert。既存 assert (aria-invalid /
      describedBy / id) はそのまま維持。

### リスク
- 全 FormError に live region が付くため、ページ初期表示時に既にエラーがある稀なケースで読み上げ挙動が変わりうる。
  ただし `{#if message}` により live region はメッセージ出現と同時に DOM 追加される (初期からの静的表示は
  live region 挿入時読み上げ対象外) ため、既存フォームでの過剰通知リスクは実質ない。`pnpm test` で回帰確認。

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
      threshold spinbutton は `not.toHaveAttribute("aria-invalid","true")`。
- [ ] **(1) threshold 解析/範囲エラー → threshold のみ invalid**: threshold spinbutton に "-1"(または非整数
      "abc") → 押下 → threshold spinbutton が `aria-invalid=true` +
      `toHaveAccessibleDescription(/リチャージ開始残高は 0 以上の整数/)`、max spinbutton は invalid でない。
- [ ] **(3) 個別有効だが max<=threshold → max のみ invalid**: threshold spinbutton="5"(既定)・
      max spinbutton="3" (3 は minCount..limit で個別有効かつ 3<=5) → 押下 → max spinbutton が
      `aria-invalid=true` + `toHaveAccessibleDescription(/開始残高より大きい値/)`、threshold は invalid でない。
      (この具体値により `parsedMax===null` 分岐を踏むだけで通ってしまう false pass を防ぐ。)
- [ ] **押下前は aria-invalid が付かない (禁止事項 #8)**: max spinbutton に "0" 入力しても押下前は
      `not.toHaveAttribute("aria-invalid","true")` (既存 L128 の意図を aria-invalid で再表現)。
- [ ] **有効値へ直すと aria-invalid が消える (既存 F-3-05 の意図)**: max "0" → 押下 (invalid) → max "50" →
      max spinbutton の `aria-invalid` が消えることを確認 (既存 L138 の移設)。
- [ ] **FormError の aria-live** (施策2b): `FormField.test.ts` にエラー要素が `aria-live="polite"` を持つ
      assert を追加。
- 既存の他 assert (`auto-recharge-consent` を開かない 等) は据え置き。

---

## 横断: テストファースト順序と検証コマンド

### テストファースト実行順序 (AGENTS.md 禁止事項 #1 / 思考原則 5)
1. 施策1-T / 2-T / 2b のテストを**先に**追加・更新し、対象テストが**期待どおりの理由で fail** することを確認
   (F-4-01: verification.notice への redirect と flash が無いこと / F-3-01: spinbutton に aria-invalid が
   付かないこと)。
2. 施策1 / 2 / 2b の実装を入れる。
3. 対象テストを green にする。
4. 下記の既定検証コマンド一覧を全て実行して緑を確認する。

### 検証コマンド (AGENTS.md L336-338 が正本。`composer fix`/`pnpm lint:fix` は完了条件ではない)
- `composer test` (Pest、グローバル `RefreshDatabase` + `--parallel`)
- `composer phpstan` (level 10)
- `vendor/bin/pint --test` (差分整形の確認)
- `pnpm lint` (eslint)
- `pnpm typecheck` (tsc --noEmit)
- `pnpm test` (vitest。JS の gate 正本レーン。施策2/2b の aria-invalid/aria-live 契約はここで固定)
- `pnpm build` (vite build)
- package 系: `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
  (本設計は packages/ を触らないが、既定一覧に含まれるため実行して緑を確認する)

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は PHP レスポンス 1 / Svelte 2 (AutoRechargeCard + FormError atom) + テスト。既存構造への小さな追従で、middleware・route 定義・DB スキーマには触れない。段階的コミットで安全に入る。 |
| 競合リスク | 低〜中。`ProfileUpdatedResponse` / `AutoRechargeCard` は局所で他グループ (F-1/F-2 系) と重ならない。唯一 `FormError.svelte` は共有 atom (1 属性追加) のため、同時に FormError を触る TODO があれば軽微な競合可能性 → その場合は施策 2b を先行 merge するか rebase で吸収 (追加は 1 行のため衝突解消は容易)。 |

## 乖離台帳の確認
- 変更ファイル (`app/Http/Responses/Fortify/ProfileUpdatedResponse.php`,
  `resources/js/components/features/billing/AutoRechargeCard.svelte`, および各テスト) が
  `docs/template-fingerprints.json` の共有ファイルキーに含まれるかは実装着手時に確認する。
  これらはアプリ固有 (AI-CUE ドメインの Fortify レスポンス差し替え / 課金オートリチャージ UI) であり
  テンプレート同梱ファイルではないと見込まれるため、乖離台帳・LedgerPins の更新は不要と判断
  (実装時に fingerprints キーを最終確認)。

## 現行 FormError.svelte (全文)
```svelte
<script lang="ts">
    /**
     * フォームエラー表示 atom。FormField / Checkbox から composition される。
     * 単体でも使えるが、aria-describedby の配線は呼び出し側の責務。
     */
    interface Props {
        message?: string | null;
        id?: string;
        testId?: string;
    }

    let { message, id, testId }: Props = $props();
</script>

{#if message}
    <p {id} class="text-caption text-danger" data-testid={testId}>{message}</p>
{/if}
```
