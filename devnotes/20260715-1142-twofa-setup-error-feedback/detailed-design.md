# 詳細設計: twofa-setup-error-feedback

bug-hunt run 20260715-084108 / F-2-02 (High, validation_gap)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）— AGENTS.md より
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを
生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも
標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg /
単一 Default Project。

### 禁止事項 — AGENTS.md より
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

### コーディングルール
- PHPStan level 10 必須 (`composer phpstan`) — 本修正は PHP 非変更のため影響なし
- Pest (`composer test`) / RefreshDatabase + `--parallel` グローバル適用 (個別 DatabaseTransactions 禁止)
- テストデータは Factory で生成
- DTO + JsonResource パターン (本修正は該当なし)
- アーリーリターン推奨 / `composer fix` (Pint) / `pnpm lint:fix`
- フロントは Svelte 5 runes + DS token/ramp のみ (DESIGN.md canonical)。フォームは FormField / Input atom 経由
- component 階層: atoms → molecules → organisms → features → templates → pages の単方向 import
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス
[devnotes/20260715-1142-twofa-setup-error-feedback/conceptual-design.md](./conceptual-design.md)

## 根本原因（確定）

| 経路 | error bag | フロントの解決 |
|------|-----------|---------------|
| 2FA ログインチャレンジ (`AttemptToAuthenticate` / `RedirectIfTwoFactorAuthenticatable`) | default (無名) | `form.errors.code` に載る → 表示される |
| 2FA 有効化確認 (`ConfirmTwoFactorAuthentication`) | 名前付き `confirmTwoFactorAuthentication` | 現状 errorBag 未指定 → `errors = { confirmTwoFactorAuthentication: { code } }` → `errors.code` が undefined → 無言失敗 |

- `vendor/laravel/fortify/src/Actions/ConfirmTwoFactorAuthentication.php` L44-46 が
  `->errorBag('confirmTwoFactorAuthentication')` で名前付きバッグに投げる。
- `vendor/inertiajs/inertia-laravel/src/Middleware.php` `resolveValidationErrors` は default バッグが
  無いとき `$bags->toArray()` を返す → `{ confirmTwoFactorAuthentication: { code: "..." } }`。
- `@inertiajs/core` 3.3.1 `dist/index.js` L3285:
  `const scopedErrors = params.errorBag ? errors[params.errorBag || ""] || {} : errors;`
  → visit の `errorBag` を指定すると named bag からスコープして `form.errors` に載る。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 確認フォーム POST に errorBag を指定し誤コードエラーを表示 | `resources/js/pages/Settings/Security.svelte` | High |
| 2 | vitest 回帰テスト追加 (errorBag 指定 / 誤コード表示 / 正コード成功) | `tests/js/pages/SettingsSecurity.test.ts` (または新規 `SettingsSecurityTwoFactorConfirm.test.ts`) | High |

---

## 施策1: 確認フォーム POST に errorBag を指定し誤コードエラーを表示

### 変更箇所
- ファイル: `resources/js/pages/Settings/Security.svelte`
  - `confirmForm` 宣言付近 (L82-84) に bag 名の literal const を追加
  - `confirmTwoFactor()` の `confirmForm.post(...)` (L210-221) に `errorBag` オプションを追加

### 波及変更
- TypeScript 型定義: なし (Inertia visit options の `errorBag` は既存の型に含まれる)
- API Resource/DTO: なし (サーバ変更なし)
- テストファイル: `tests/js/pages/SettingsSecurity.test.ts` に確認フロー test を追加 (施策2)
- ルート/コントローラ/Fortify 設定: なし (バッグ名は Fortify が固定して投げる契約に合わせるのみ)

### 現行コード
```svelte
const confirmForm = useForm({
    code: "",
});
```
```svelte
function confirmTwoFactor(event: SubmitEvent): void {
    event.preventDefault();
    confirmForm.post("/user/confirmed-two-factor-authentication", {
        preserveScroll: true,
        onSuccess: () => {
            confirming = false;
            qrSvg = null;
            confirmForm.reset();
            showRecoveryCodes();
        },
    });
}
```

### 変更後コード
```svelte
/**
 * Fortify の 2FA 確認アクション (ConfirmTwoFactorAuthentication) は検証失敗を
 * 名前付き error bag "confirmTwoFactorAuthentication" に投げる
 * (login チャレンジ側は default bag)。Inertia は default bag が無いと named bag を
 * ネストしたまま共有するため、client 側で同名の errorBag を指定しないと
 * confirmForm.errors.code が解決されず、誤コード時に無言失敗する (F-2-02)。
 */
const CONFIRM_TWO_FACTOR_ERROR_BAG = "confirmTwoFactorAuthentication" as const;

const confirmForm = useForm({
    code: "",
});
```
```svelte
function confirmTwoFactor(event: SubmitEvent): void {
    event.preventDefault();
    confirmForm.post("/user/confirmed-two-factor-authentication", {
        preserveScroll: true,
        // Fortify の named error bag からエラーをスコープする (未指定だと errors.code が解決されない)
        errorBag: CONFIRM_TWO_FACTOR_ERROR_BAG,
        onSuccess: () => {
            confirming = false;
            qrSvg = null;
            confirmForm.reset();
            showRecoveryCodes();
        },
    });
}
```

### 表示経路 (変更不要・確認のみ)
- `FormField label="認証コード" id="two-factor-code" error={confirmForm.errors.code}` (L350-354) は
  既にエラー表示能力を持つ。`FormField` → `FormError` atom が `error` を `<p class="text-caption text-danger" id="two-factor-code-error">` として描画し、`Input` に `error={invalid}` (赤枠) と
  `aria-describedby` を配線する。errorBag 修正で `confirmForm.errors.code` が解決されれば、
  追加の UI 変更なしにログインチャレンジと同等の表示になる。

### リスク
- Fortify のバッグ名 `confirmTwoFactorAuthentication` に文字列依存する。literal const 化と
  施策2の回帰テスト (errorBag 指定を固定) で typo/契約ドリフトを検出可能にする。
- 成功時の挙動 (confirming 解除 → QR クリア → reset → リカバリコード表示) は非変更。errorBag は
  visit option であり payload/データには影響しないため、成功パスに副作用なし。
- サーバ・他 2FA エンドポイントへの波及なし。

---

## 施策2: vitest 回帰テスト追加

### 変更箇所
- ファイル: `tests/js/pages/SettingsSecurity.test.ts` に新規 describe ブロックを追加
  (既存の F-10 テスト群と同居。テスト数が多くなる場合は新規ファイル
  `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` に分離してもよい)

### テスト戦略
確認フォームは内部 state `confirming === true` かつ `twoFactorEnabled === false` のときのみ描画される。
`confirming` は `enableTwoFactor()` の `router.post` onSuccess で true になる (router はモック済み)。
また確認送信は `confirmForm` (= `useForm`) の `.post()` を呼ぶ。既存テストは `useForm` を実物のまま
使い router を差し替えているが、本テストでは **`useForm` を制御可能なフェイクに差し替える**ことで
「post の visit options 検証」と「named bag エラーからの表示」を分離して検証する。

- 既存の support helper `tests/js/support/reactiveUseForm.svelte.ts` を利用する
  (`errors` を `$state` で保持し、キー追加で FormField が再評価される)。
  現状の `reactiveUseForm` は `reset` を持たず `processing` が固定値のため、以下を後方互換に拡張する
  (設計レビュー Round 1 Warning 反映):
  - `reset: vi.fn()` を追加 (confirm の onSuccess が `confirmForm.reset()` を呼ぶため)。
  - `processing` を `$state` + getter 化し、`setProcessing(bool)` ヘルパを公開する
    (`loading={confirmForm.processing}` の onStart→onFinish 遷移を将来検証できるようにする。
    既存利用箇所は `processing` を読むだけなので getter 化は後方互換)。
  - `post`/`clearErrors` は既存どおり `vi.fn()` で呼び出し引数を保持する。加えて
    **`respondWithErrors(next: Record<string,string>)`** を公開し、リアクティブな `errors`
    ($state) へ `Object.assign(errors, next)` で反映する (設計レビュー Round 2 反映)。
    これは Inertia がレスポンス受領後に form.errors を更新する挙動を模倣するもの。
    注意: 現行 `confirmTwoFactor()` は post options に `onError` を渡していないため、
    テストで `options.onError` を直接発火する方式は取らない (何も起きない)。errors 反映は
    `respondWithErrors` 経由で行う。
- `vi.mock("@inertiajs/svelte", ...)` で `useForm` をこのフェイクへ差し替え、`router.post` /
  `page` は既存テスト同様にモックする。フェイクは `code` フィールドと `errors`・`processing`・
  `post`・`reset`・`clearErrors` を備える。

### 波及変更
- `tests/js/support/reactiveUseForm.svelte.ts` に `reset: vi.fn()` 追加 + `processing` の
  `$state`/getter 化 + `setProcessing` 公開 (いずれも後方互換な拡張)
- 既存テストの削除・改変はしない (禁止事項3)。既存 `reactiveUseForm` 利用箇所
  (OrganizationsSettings 等) は読み取り専用参照のみで影響しないことを確認する

### テスト計画
- [ ] バグ修正の再現テスト (fail → pass):
  - **(a) errorBag 指定の固定**: 2FA 無効状態で render → 有効化ボタン押下 → `router.post` の
    onSuccess を呼んで `confirming = true` にする → 認証コード入力 → 確認フォーム submit →
    `confirmForm.post` が `"/user/confirmed-two-factor-authentication"` と
    `expect.objectContaining({ errorBag: "confirmTwoFactorAuthentication" })` で呼ばれることを assert。
  - **(b) 誤コード submit → errors 反映でエラー表示** (設計レビュー Round 1/2 反映):
    (a) と同様に確認フォームを表示 → 確認フォーム submit (この時点で errorBag 付き post が飛ぶ)
    → フェイクの **`respondWithErrors({ code: "認証コードが無効です" })`** を呼び、Inertia が
    レスポンス受領後に form.errors を更新する挙動を模倣する (リアクティブ `errors` $state に反映)。
    → 入力直下 (`#two-factor-code-error` / `screen.getByText(...)`) に文言が描画され、`Input` が
    `error` (aria-invalid/赤枠) になることを assert。
    現行 `confirmTwoFactor()` は post options に `onError` を渡さないため、`options.onError` の
    直接発火方式は採らない (何も起きない)。責務分離: (a)=コンポーネント→Inertia の visit option、
    (b)=Inertia が反映した form errors→UI 表示、(c)=成功 callback 後の状態遷移。
    修正前は errors が named bag にネストされ `errors.code` が解決されないため、このテストが回帰を守る。
  - **(c) 正コードで成功**: 確認フォーム submit で `confirmForm.post` の onSuccess を実行 →
    `confirming` が解除され確認フォームが消える (`showRecoveryCodes` の fetch はモックで stub)。
    `confirmForm.reset()` が呼ばれることも確認可能。
  - **(補) processing 遷移** (任意・helper 拡張の活用例): `setProcessing(true)` で
    「確認して有効化」ボタンが `aria-busy` になり、`setProcessing(false)` で解除されることを
    確認できる (F-10 群の processing テストと同型)。
- [ ] 既存テスト `tests/js/pages/SettingsSecurity.test.ts` (F-10 群) はそのまま green を維持
- [ ] 個別の `DatabaseTransactions` は使用しない (フロントテストにつき該当なし)
- [ ] `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build` green

### 参考: 表示要素のセレクタ
- エラー文言: `FormError` が `<p id="two-factor-code-error" class="text-caption text-danger">{message}</p>`
  を描画 → `screen.getByText("認証コードが無効です")` で取得可能
- 入力: `Input` は `aria-invalid={error || undefined}` を出力する (L41)。テストの主要アサーションは
  **`aria-invalid`** を用いる (赤枠 class は実装詳細のため安定性が低い。設計レビュー Round 3 Suggestion 反映)。

### リスク
- `reactiveUseForm` へ `reset` 追加が既存利用箇所 (OrganizationsSettings 等) に影響しないこと。
  → プロパティ追加のみで既存の呼び出しは変わらないため後方互換。
- 確認フォーム描画のために enable→onSuccess のドライブが必要。既存テストと同じ
  `lastVisitOptions().onSuccess?.()` パターンで安定して再現できる。

## サーバ側テストについて (検討結果)
バッグ名 `confirmTwoFactorAuthentication` は Fortify (vendor) が固定して投げる契約であり、本修正は
クライアント側のスコープ指定のみ。サーバ側の挙動は変更しないため Feature テストの新規追加は
本 F-2-02 のスコープ外とする (概念設計スコープ外の判断を維持)。回帰の主眼は「client が named bag を
正しくスコープして表示する」ことであり、vitest の施策2 (a)(b) で十分固定できる。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 単一 Svelte ファイル + テストの局所修正。新規ファイル・スキーマ・API 変更なし。既存 2FA/セキュリティ設定機能への increment。 |
| 競合リスク | 低。`Security.svelte` と `SettingsSecurity.test.ts` / `reactiveUseForm.svelte.ts` のみ。他施策との干渉可能性は小さい。 |
</content>
