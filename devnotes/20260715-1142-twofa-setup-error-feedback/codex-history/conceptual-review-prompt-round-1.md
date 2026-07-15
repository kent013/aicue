【アプリの使命 (North Star) — AGENTS.md より】

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ(Laravel/Svelte エコシステムの既存解を使え)。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション(Laravel + Svelte)の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命(North Star)に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か(Laravel 12 + Svelte 5 + Inertia.js)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【特に検証してほしい点】
- 根本原因の診断(Fortify の名前付き error bag `confirmTwoFactorAuthentication` と Inertia の
  errorBag スコープの不一致)が正しいか。私の読んだ vendor コードの解釈に誤りはないか。
- 修正案(クライアント側 `errorBag: "confirmTwoFactorAuthentication"` 追加)がこの症状を確実に
  解消するか。より適切な代替(サーバ側での対応等)があるか。
- スコープ外の切り方は妥当か。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260715-1142-twofa-setup-error-feedback/conceptual-design.md の内容）

# 概念設計: twofa-setup-error-feedback

bug-hunt run 20260715-084108 / F-2-02 (High, validation_gap)

## 背景・課題

2FA 有効化の確認ステップ (two-factor.confirm / POST /user/confirmed-two-factor-authentication) で
誤った TOTP コードを送信すると、画面にエラーが一切表示されず無言で失敗する。ユーザーは
「なぜ有効化できないのか」が分からず、詰みに近い体験になる (High)。

対照的に、2FA ログインチャレンジ (two-factor.login.store / POST /two-factor-challenge) は
誤コード時に該当入力直下へエラーメッセージを正しく表示する。両画面は同じ FormField +
useForm().errors.code パターンを使っているのに挙動が異なる。

### 根本原因 (コード確認済み)

Fortify の確認アクションとログインアクションで ValidationException の error bag が異なる:

- ログインチャレンジ (AttemptToAuthenticate / RedirectIfTwoFactorAuthenticatable):
  ValidationException::withMessages(['code'=>...]) → default(無名)バッグ
- 有効化確認 (ConfirmTwoFactorAuthentication):
  ...->errorBag('confirmTwoFactorAuthentication') → 名前付きバッグ

vendor/laravel/fortify/src/Actions/ConfirmTwoFactorAuthentication.php L44-46:
    throw ValidationException::withMessages([
        'code' => [__('The provided two factor authentication code was invalid.')],
    ])->errorBag('confirmTwoFactorAuthentication');

Inertia Laravel middleware (resolveValidationErrors) は default バッグが無い場合、
各バッグを名前付きでネストして共有する: return $bags->toArray();
=> { confirmTwoFactorAuthentication: { code: "..." } }

Inertia core (@inertiajs/core 3.3.1 dist/index.js L3285) は visit の errorBag オプションで
エラーをスコープする:
    const scopedErrors = params.errorBag ? errors[params.errorBag || ""] || {} : errors;

現状の Security.svelte 確認フォーム送信 (L212-221) は errorBag を指定していないため、
scopedErrors = errors = { confirmTwoFactorAuthentication: { code } } となり、
confirmForm.errors.code は undefined。FormField error={confirmForm.errors.code} は
何も描画しない = 無言失敗。ログインチャレンジ側は default バッグに載るため form.errors.code が
正しく解決され表示される。

## 改善アイデア

確認フォームの Inertia POST に errorBag: "confirmTwoFactorAuthentication" を指定する。
これにより Inertia core が errors["confirmTwoFactorAuthentication"] を取り出して
confirmForm.errors に載せ、confirmForm.errors.code が解決される。既存の
FormField error={confirmForm.errors.code} がそのまま入力直下にエラーを描画する。

これは Laravel Jetstream が同じ Fortify 確認エンドポイントに採る正攻法と同一。
フレームワークのレンジ内で Inertia 公式作法に沿った最小変更。サーバ側は変更しない。

## 期待効果

- 使命への貢献: 2FA は現場アカウント保護に不可欠。有効化確認で無言失敗するとユーザーは
  設定を完了できず離脱する。エラー明示で「思考ゼロ」で完了できる導線を回復。
- 誤コード時に「認証コードが無効です」等が入力直下に出る → 再入力すればよいと即座に理解。
- ログインチャレンジと確認で挙動が一致し体験の一貫性が回復。

## 実装方針（概要）

1. Security.svelte の confirmTwoFactor() 内 confirmForm.post(...) に
   errorBag: "confirmTwoFactorAuthentication" を追加 (1 行)。
2. 成功時 onSuccess は現状維持。エラー時は Inertia が自動で confirmForm.errors を更新。
3. vitest 回帰テスト追加: 誤コード→errors.code 表示 / 正コード→成功 / POST に errorBag 含む。

## 制約・前提

- Fortify のバッグ名は vendor 側で固定された契約。Jetstream も同名に依存。マジック文字列化を
  避けるため定数化を検討 (詳細設計)。
- AGENTS.md 禁止事項非抵触: サーバ変更なし、テスト付き、既存テスト非削除。
- DESIGN.md / Atomic Design: 既存 FormField/FormError/Input を使用。新規コンポーネント・
  SVG・hex 直書きなし。

## スコープ外

- Fortify の error bag 設計変更 (vendor 非改変)。
- ログインチャレンジ側 (既に正しい)。
- 2FA 他エンドポイントのエラー表示 (別フィードバックあり)。
- サーバ側 Feature テスト追加 (詳細設計でバッグ名契約の回帰を検討)。
</content>
