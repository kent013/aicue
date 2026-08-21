## アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」を AI とナビ撮影で実現する。

## 禁止事項
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO/JsonResource/Inertia。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での redirect()->intended()(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示)
9. Artifact の使用

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリ改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO+JsonResource / Laratrust RBAC

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、null安全性)
2. 既存コードとの整合性
3. PHPStan level 10 適合性
4. テスト計画の網羅性(各施策にPestテスト、RefreshDatabaseグローバル)
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ(認可・入力バリデーション・OWASP)
10. DESIGN.md準拠(token経由、hex直書きを増やさないか)
11. Atomic Design準拠(atoms/molecules/organisms の責務分離、Lucide前提)

【出力形式】各施策ごと APPROVE/REQUEST_CHANGES、[Critical][Warning][Suggestion]分類、Critical/Warningに修正案、全体判定 APPROVED/CHANGES_REQUESTED、日本語。

【確認済みの事実(コード読了)】
- Fortify ProfileInformationController は `$updater->update($request->user(), $request->all())` 後に `app(ProfileInformationUpdatedResponse::class)` を返す。レスポンスの toResponse($request) は同一 $request の user() を見る(memo 同一インスタンス)ため wasChanged('email') が読める。
- UpdateUserProfileInformation: email 変更時のみ forceFill(['name','email','email_verified_at'=>null])->save()。同一email early-return と氏名のみは email を変えない。
- /settings は ['auth','verified','not-pending-deletion']。verified = Laravel 素 EnsureEmailIsVerified(flash keep しない)。verification.notice=/email/verify は auth のみ。VerifyEmail.svelte は AuthLayout+consumeFlash。
- FormField(molecule): error prop → invalid=Boolean(error) を snippet に渡す。Input(atom): error?:boolean → aria-invalid={error||undefined}。FormError(atom) は testId 対応済みだが FormField は testId を FormError へ forward しない。
- 対象 2 ファイルは docs/template-fingerprints.json に無い(テンプレート非共有)。

---

## 詳細設計書

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
| 1-T | 施策1 の Feature テスト | `tests/Feature/Auth/FortifyResponseTest.php` | Medium |
| 2 | F-3-01: オートリチャージ範囲エラーで対象 spinbutton に aria-invalid | `resources/js/components/features/billing/AutoRechargeCard.svelte` | Low |
| 2-T | 施策2 の JS コンポーネントテスト更新 | `tests/js/components/features/billing/AutoRechargeCard.test.ts` | Low |

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
対象: `tests/Feature/Auth/FortifyResponseTest.php` (既存の「プロフィール更新は success flash を返す (web)」
「JSON リクエストに 200」テストの隣に追加)。Factory (`User::factory()`) でユーザー生成。`Notification::fake()`。
- [ ] **バグ再現 → 修正確認 (fresh + メール変更, web)**: `recent_auth_at` を fresh にした状態で
      `put('/user/profile-information', ['name'=>..., 'email'=>'new@example.com'])` →
      `assertRedirect(route('verification.notice'))` かつ `assertSessionHas('success', EMAIL_CHANGED_MESSAGE 相当)`。
      さらに redirect 先 GET `/email/verify` が `success` flash を 1 hop で保持することを
      `->assertSessionHas('success', ...)` の後に follow して確認 (中間ホップ廃棄が起きないことの回帰)。
- [ ] **氏名のみ更新 (web) は従来どおり**: `email` を現行と同一で put → `assertRedirect` が `back()` 相当
      (元 URL / `/`) かつ `assertSessionHas('success', 'プロフィールを更新しました。')`。`verification.notice`
      へは飛ばないこと。
- [ ] **expectsJson は 200 JSON 維持**: `putJson('/user/profile-information', ['name'=>..., 'email'=>'new@...'])`
      → `assertOk()`、本文が現行契約 (`''` = 空 JSON) のままであること (Codex Round 2 補足: 本文値まで固定)。
- [ ] **stale + recent-auth 完了後もフィードバックが残る (統合)**: 既存
      `ProfileEmailChangeRecentAuthTest` の流儀で、stale から `recent-auth/password` 通過後に元 put を再送した
      ケースでも最終着地 `/email/verify` に `success` flash が載ることを固定
      (recent-auth フロー後退の防止)。※実装難度により FortifyResponseTest 側の直接 put で代替可
      (recent_auth_at fresh = モーダル通過後と同じ状態)。
- [ ] `DatabaseTransactions` を個別使用していないこと (グローバル `RefreshDatabase` に従う)。
- 既存テスト `ProfileEmailChangeRecentAuthTest::3` は Location が `recent-auth.confirm` でないことを assert する
      のみで、`verification.notice` への redirect は許容 → **本施策で壊れない** (回帰なし)。

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

### テスト計画 (施策2-T)
対象: `tests/js/components/features/billing/AutoRechargeCard.test.ts`。既存 `auto-recharge-range-error`
testId 参照 (6 箇所, L123/135/144/148/158/164) を「利用者視点」の assert に更新 (カバレッジは削除せず移設)。
- [ ] **max 範囲外で max spinbutton に aria-invalid が付く (F-3-01 本体)**: `hasPaymentMethod:true` で render →
      max-input に "0" 入力 → enable 押下 →
      `expect(screen.getByTestId("auto-recharge-max-input")).toHaveAttribute("aria-invalid","true")`。
      かつ `screen.getByText(/リチャージ後の残高は/)` が存在。
- [ ] **threshold 不正で threshold spinbutton のみ aria-invalid**: threshold-input に不正値 (負/非整数) →
      押下 → threshold-input が `aria-invalid=true`、max-input は `aria-invalid` なし
      (`not.toHaveAttribute("aria-invalid")` 相当) を固定 (原因フィールド限定の契約)。
- [ ] **大小関係違反 (max<=threshold) は max のみ aria-invalid**: max を threshold 以下に → 押下 →
      max-input のみ invalid、threshold-input は invalid でない。
- [ ] **押下前は aria-invalid が付かない (禁止事項 #8)**: 入力しても押下前は max-input に `aria-invalid` なし
      (既存 L128 の意図を aria-invalid で再表現)。
- [ ] **有効値へ直すと aria-invalid が消える (F-3-05 stale invalid 継続)**: 既存 L138 の意図を維持し
      max-input の `aria-invalid` が消えることを確認。
- [ ] **文言追随 (既存 L151)**: 範囲外→大小違反へ理由が変わると max-input 近傍の文言が追随
      (getByText の正規表現で確認)。
- 既存の他 assert (`auto-recharge-consent` を開かない 等) は据え置き。

### リスク
- 統合 `<p>` 撤去で `auto-recharge-range-error` testId を参照する箇所が壊れる → 施策2-T で更新。
  他 (Pest/Browser) からの参照が無いことは確認済み (grep: 当該 svelte とその JS テストのみ)。
- FormField 内 FormError には testId が無い → テストは `aria-invalid` 属性 + `getByText` で assert する
  (testId 依存を外す。FormField は改変しない)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は独立した 2 ファイル (PHP レスポンス 1 / Svelte 1) + それぞれのテストのみ。既存構造への小さな追従で、他 TODO と競合する共有基盤 (middleware・共有 molecule・route 定義) に触れない。段階的コミットで安全に入る。 |
| 競合リスク | 低。`ProfileUpdatedResponse` / `AutoRechargeCard` はいずれも局所ファイルで、他グループ (F-1/F-2 系) の変更対象と重ならない。 |

## 乖離台帳の確認
- 変更ファイル (`app/Http/Responses/Fortify/ProfileUpdatedResponse.php`,
  `resources/js/components/features/billing/AutoRechargeCard.svelte`, および各テスト) が
  `docs/template-fingerprints.json` の共有ファイルキーに含まれるかは実装着手時に確認する。
  これらはアプリ固有 (AI-CUE ドメインの Fortify レスポンス差し替え / 課金オートリチャージ UI) であり
  テンプレート同梱ファイルではないと見込まれるため、乖離台帳・LedgerPins の更新は不要と判断
  (実装時に fingerprints キーを最終確認)。

## 関連する現行コード

### ProfileUpdatedResponse.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;

/**
 * プロフィール更新後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
 * status を意図的に gating (toast 化しない)。更新完了を toast でフィードバック
 * するため web のみ `success` キーへ寄せる。expectsJson (XHR / API) は
 * Fortify 既定どおり JSON 200 を維持する。
 */
final class ProfileUpdatedResponse implements ProfileInformationUpdatedResponseContract
{
    private const string SUCCESS_MESSAGE = 'プロフィールを更新しました。';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse('', 200);
        }

        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
```
### FormField.svelte (全文)
```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import FormError from "@/components/atoms/FormError.svelte";

    /**
     * ラベル + 入力 + エラー + ヘルプの複合 molecule。
     *
     * 入力 atom (Input/Textarea/Select) は最小責務に保ち、ラベル・エラー文言・
     * aria-describedby の配線は本 molecule が担う (関心分離)。
     * children snippet に { id, describedBy, invalid } を渡すので、呼び出し側は
     * それを入力 atom へ流し込む。
     *
     * 使用例:
     *   <FormField label="名前" id="name" required error={form.errors.name}>
     *       {#snippet children({ id, describedBy, invalid })}
     *           <Input {id} bind:value={form.name} error={invalid} aria-describedby={describedBy} />
     *       {/snippet}
     *   </FormField>
     */
    interface Props {
        label: string;
        id: string;
        error?: string | null;
        help?: string;
        required?: boolean;
        children: Snippet<[{ id: string; describedBy: string | undefined; invalid: boolean }]>;
    }

    let { label, id, error, help, required = false, children }: Props = $props();

    const errorId = $derived(error ? `${id}-error` : undefined);
    const helpId = $derived(help ? `${id}-help` : undefined);
    const describedBy = $derived(
        [errorId, helpId].filter(Boolean).join(" ") || undefined,
    );
</script>

<div class="flex flex-col gap-1.5">
    <label for={id} class="text-caption font-medium text-text">
        {label}
        {#if required}<span class="text-danger" aria-hidden="true">*</span>{/if}
    </label>
    {@render children({ id, describedBy, invalid: Boolean(error) })}
    {#if help}
        <p id={helpId} class="text-caption text-text-secondary">{help}</p>
    {/if}
    <FormError id={errorId} message={error} />
</div>
```
### AutoRechargeCard.svelte 派生値+FormField 部 (L78-392 抜粋済み、詳細設計に転記)
