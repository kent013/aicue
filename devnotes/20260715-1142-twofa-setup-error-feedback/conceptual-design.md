# 概念設計: twofa-setup-error-feedback

bug-hunt run 20260715-084108 / F-2-02 (High, validation_gap)

## 背景・課題

2FA 有効化の確認ステップ (`two-factor.confirm` / `POST /user/confirmed-two-factor-authentication`) で
**誤った TOTP コードを送信すると、画面にエラーが一切表示されず無言で失敗**する。ユーザーは
「なぜ有効化できないのか」が分からず、詰みに近い体験になる (High)。

対照的に、2FA **ログインチャレンジ** (`two-factor.login.store` / `POST /two-factor-challenge`) は
誤コード時に該当入力直下へエラーメッセージを正しく表示する。両画面は同じ `FormField` +
`useForm().errors.code` パターンを使っているのに挙動が異なる。この非対称の原因を特定し、
確認ステップでもログインチャレンジと同等のエラー表示を実現する。

### 根本原因 (コード確認済み)

Fortify の確認アクションとログインアクションで **ValidationException の error bag が異なる**:

| 経路 | 投げ方 | error bag |
|------|--------|-----------|
| ログインチャレンジ (`AttemptToAuthenticate` / `RedirectIfTwoFactorAuthenticatable`) | `ValidationException::withMessages(['code'=>...])` | **default** (無名) |
| 有効化確認 (`ConfirmTwoFactorAuthentication`) | `...->errorBag('confirmTwoFactorAuthentication')` | **名前付き** `confirmTwoFactorAuthentication` |

`vendor/laravel/fortify/src/Actions/ConfirmTwoFactorAuthentication.php` L44-46:

```php
throw ValidationException::withMessages([
    'code' => [__('The provided two factor authentication code was invalid.')],
])->errorBag('confirmTwoFactorAuthentication');
```

Inertia Laravel middleware (`vendor/inertiajs/inertia-laravel/src/Middleware.php`
`resolveValidationErrors`) は **default バッグが無い**場合、各バッグを名前付きでネストして共有する:

```php
// default バッグが無い → バッグ名でネストしたまま返す
return $bags->toArray();   // => { confirmTwoFactorAuthentication: { code: "..." } }
```

一方、Inertia core (`@inertiajs/core` 3.3.1 `dist/index.js` L3285) は visit の `errorBag`
オプションでエラーをスコープする:

```js
const scopedErrors = params.errorBag ? errors[params.errorBag || ""] || {} : errors;
```

現状の `Security.svelte` の確認フォーム送信 (L212-221) は `errorBag` を **指定していない**ため、
`scopedErrors = errors = { confirmTwoFactorAuthentication: { code } }` となり、
`confirmForm.errors.code` は `undefined`。よって `FormField error={confirmForm.errors.code}` は
何も描画しない = 無言失敗。

ログインチャレンジ側は default バッグに載るため `form.errors.code` が正しく解決され、表示される。

## 改善アイデア

**確認フォームの Inertia POST に `errorBag: "confirmTwoFactorAuthentication"` を指定する。**

これにより Inertia core が `errors["confirmTwoFactorAuthentication"]` を取り出して
`confirmForm.errors` に載せ、`confirmForm.errors.code` が正しく解決される。既存の
`FormField error={confirmForm.errors.code}` がそのまま該当入力直下にエラーを描画する。

これは Laravel Jetstream が同じ Fortify 確認エンドポイントに対して採る正攻法と同一
(Jetstream も `errorBag: 'confirmTwoFactorAuthentication'` でスコープする)。フレームワークの
レンジ内 (思考原則 1) で、Inertia の公式作法に沿った最小変更。

- サーバ側 (Fortify のアクション/レスポンス) は一切変更しない。バッグ名は Fortify が固定して
  投げる契約なので、クライアントがその契約に合わせる。
- UI 要素の追加は不要 (`FormField` / `FormError` は既にエラー表示能力を持つ)。
- 成功時の挙動 (confirming 解除 → QR クリア → リカバリコード表示) は現状維持。

## 期待効果

- **使命への貢献**: 2FA は現場アカウントの保護に不可欠。有効化確認で無言失敗すると
  ユーザーはセキュリティ機能を有効化できず離脱する。エラーを明示することで「思考ゼロ」で
  設定を完了できる導線を回復する (UX 破綻・詰みの解消)。
- 誤コード時に「認証コードが無効です」等のメッセージが入力直下に出る → ユーザーは
  再入力すればよいと即座に理解できる。
- ログインチャレンジと確認ステップでエラー表示挙動が一致し、体験の一貫性が回復する。

## 実装方針（概要）

1. `resources/js/pages/Settings/Security.svelte` の `confirmTwoFactor()` 内
   `confirmForm.post("/user/confirmed-two-factor-authentication", {...})` に
   `errorBag: "confirmTwoFactorAuthentication"` を追加する (1 行)。
2. 成功時コールバック (`onSuccess`) は現状維持。エラー時は Inertia が自動で
   `confirmForm.errors` を更新するため追加コールバック不要。
3. vitest 回帰テストを追加:
   - 誤コード送信 → `errors.code` が載る → 入力直下にエラーメッセージが描画される
   - 正コード送信 → `onSuccess` 経路 (有効化完了) が走る / errorBag が正しく渡る
   - POST 呼び出しに `errorBag: "confirmTwoFactorAuthentication"` が含まれることを固定

## 制約・前提

- Fortify のバッグ名 `confirmTwoFactorAuthentication` は vendor 側で固定された契約。
  これに依存する形になるが、Fortify の公開挙動であり Jetstream も同名に依存しているため妥当。
  マジック文字列化を避けるため、必要ならローカル定数化を検討 (詳細設計で判断)。
- `AGENTS.md` 禁止事項に抵触しない: サーバ変更なし (JSON 直書き・DTO 無関係)、
  テスト付き、既存テスト非削除。
- `DESIGN.md` / Atomic Design: 既存の `FormField`/`FormError`/`Input` atom/molecule を
  そのまま使用。新規コンポーネント・SVG・hex 直書きなし。
- Svelte 5 runes + Inertia useForm の既存パターンを踏襲。

## スコープ外

- Fortify の error bag 設計そのものの変更 (vendor には手を入れない)。
- ログインチャレンジ側の挙動変更 (既に正しい)。
- 2FA の他エンドポイント (有効化開始・無効化・リカバリコード再生成/表示) のエラー表示。
  これらは recent-auth トースト等で別途フィードバックがあり、本 F-2-02 の対象外。
- サーバ側の Feature テスト追加 (バッグ名は Fortify の契約であり、本修正はクライアント側。
  ただし詳細設計で「バッグ名契約の回帰を server 側で固定すべきか」を検討する)。
</content>
</invoke>
