# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

# 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`（ログイン直後フロー専用）
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。先人の知恵を探せ（Laravel/Svelte の公式作法を優先）。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js + Fortify）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

# 補足コンテキスト（既存コード事実）

- toast 正本: `HandleInertiaRequests::share()` が `flash.{success,error,info,warning}` + 一意 `visitKey` を共有。`consumeFlash()`（`resources/js/lib/stores/flash-to-toast.ts`）が visitKey de-dup しつつ `addToast()`。`AppLayout` / `AuthLayout` の両方が `ToastContainer` を mount + `consumeFlash` を呼ぶ。
- `consumeFlash` は `success/error/info/warning` のみ toast 化し、Fortify 既定の `status` キーは意図的に無視（gating）。
- 既存カスタム Fortify Response（`EnumerationSafePasswordResetLinkResponse` / `TwoFactorDisabledResponse` / `VerificationNotificationSentResponse` / `RecoveryCodesGeneratedResponse`）は Fortify 既定の `status` flash を `success` キーへ寄せ替え、`FortifyServiceProvider::register()` で bind 済み。この寄せ替えが確立パターン。
- Fortify 既定 `ProfileInformationUpdatedResponse` / `PasswordUpdateResponse` は `wantsJson() ? JsonResponse('',200) : back()->with('status', ...)`。`PasswordResetResponse` は constructor に `$status` を取り `wantsJson() ? JsonResponse(['message'=>...],200) : redirect(route('login'))->with('status', ...)`。
- 再生成 POST は Inertia（`router.post`）で発火 → `wantsJson()` は false → `back()->with('success')` 分岐 → toast。加えて `Security.svelte::handleRegenerateSuccess()` が client 側 `addToast('success', ...)` も発火 → 二重。

## 概念設計

（本文は別添 conceptual-design.md。以下に全文貼付）

---

# 概念設計: save-success-toast

## 背景・課題

bug-hunt findings **F-M1 (Medium, ux)** + **F-L1 (Low)** への対応。

このアプリには稼働中の toast 機構がある。正本は **サーバ flash → toast 変換**:
`HandleInertiaRequests::share()` が `flash.{success,error,info,warning}` と一意な
`visitKey` を共有 → クライアントの `consumeFlash()`（`resources/js/lib/stores/flash-to-toast.ts`）が
`visitKey` で de-dup しながら `addToast()` する。`AppLayout` / `AuthLayout` の両テンプレートが
`ToastContainer` を mount し `consumeFlash` を呼ぶ。

**重要な設計事実**: `consumeFlash` は `success/error/info/warning` のみを toast 化し、
Fortify 既定の `status` キーは**意図的に gating（toast 化しない）**。この方針は
`tests/Feature/Auth/FortifyResponseTest.php` の doc と既存 3 クラス
（`EnumerationSafePasswordResetLinkResponse` / `TwoFactorDisabledResponse` /
`VerificationNotificationSentResponse` / `RecoveryCodesGeneratedResponse`）で確立済み。
これらは Fortify 既定の `back()->with('status', ...)` を **`success` キーへ寄せ替える**
カスタム Response を `FortifyServiceProvider` に bind して実現している。

### F-M1（フィードバック欠落）
以下 3 フォームは機能的に成功するが、Fortify 既定 Response が `status` キーで
flash するため toast が出ず、ユーザーが成否を判断できない（二重送信を誘発）:

| 操作 | route | 現在の Response（未 bind = Fortify 既定） | 症状 |
|------|-------|------|------|
| プロフィール更新 | `user-profile-information.update` | `ProfileInformationUpdatedResponse`（`status`） | toast なし |
| パスワード変更 | `user-password.update` | `PasswordUpdateResponse`（`status`） | toast なし |
| パスワードリセット | `password.update` | `PasswordResetResponse`（login へ redirect + `status`） | toast なし |

### F-L1（二重 toast）
リカバリコード再生成（`two-factor.regenerate-recovery-codes` = POST `/user/two-factor-recovery-codes`）
で同一操作に success toast が **2 つ**出る:
1. **サーバ flash**: `RecoveryCodesGeneratedResponse` が `back()->with('success', 'リカバリコードを再生成しました。')`
   → Inertia redirect → `AppLayout` の `consumeFlash` が toast 化。
2. **クライアント楽観 toast**: `Security.svelte` の `handleRegenerateSuccess()` が
   `addToast('success', 'リカバリコードを再生成しました。新しいコードを保管してください。')` を直接発火。

同一 POST 成功に対しサーバ flash と client の二重発火が起きている。

## 改善アイデア

**トースト機構の正本を「サーバ flash → toast」に一貫させる**。

### (1) F-M1: 3 操作に success flash を返すカスタム Fortify Response を追加・bind
既存パターン（`EnumerationSafePasswordResetLinkResponse` 等）と同型で、
`status` ではなく `success` キーへ寄せ替える 3 クラスを新設し `FortifyServiceProvider` で bind:

- `App\Http\Responses\Fortify\ProfileUpdatedResponse`
  → `ProfileInformationUpdatedResponse` contract。`back()->with('success', 'プロフィールを更新しました。')`
- `App\Http\Responses\Fortify\PasswordUpdatedResponse`
  → `PasswordUpdateResponse` contract。`back()->with('success', 'パスワードを変更しました。')`
- `App\Http\Responses\Fortify\PasswordResetResponse`
  → `PasswordResetResponse` contract（constructor に `$status` を取るため `bind`。
  login へ redirect + `->with('success', 'パスワードを変更しました。ログインしてください。')`）

いずれも **`wantsJson()` 分岐は Fortify 既定どおり JSON 200 を維持**（XHR/API 契約を壊さない）。
リセット後は未認証で login ページへ遷移するが、`AuthLayout` も `consumeFlash` を持つため
login 画面で success toast が出る。

### (2) F-L1: 再生成 toast の発火元を「サーバ flash」に一本化
`Security.svelte` の `handleRegenerateSuccess()` から
**クライアント側 `addToast('success', ...)` を削除**し、サーバ flash
（`RecoveryCodesGeneratedResponse` の `success`）を唯一の成功 toast 源とする。
保管を促す文言はサーバメッセージへ集約:
`RecoveryCodesGeneratedResponse::SUCCESS_MESSAGE` を
`'リカバリコードを再生成しました。新しいコードを保管してください。'` に更新。

クライアントは引き続き「旧コードの即時クリア → 新コード GET → パネルへ focus」を担い、
**GET 失敗時のみ** error toast（表示失敗＝再生成成功とは別事象）を出す。これは重複ではなく
別メッセージ（再生成は成功したが表示に失敗＝再取得導線あり）であり許容する。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」を掲げる本アプリで、設定変更の成否が
  即座に伝わることは現場作業者の操作不安・二重送信を減らす基礎 UX。フレームワーク
  （Fortify + 既存 flash→toast 正本）のレンジ内で自前機構を足さずに解決する。
- F-M1: profile/password/reset の 3 操作すべてで成功 toast が出る（二重送信抑止）。
- F-L1: 再生成 toast が happy path で 1 つに収束（二重解消）。
- toast 発火の正本が「サーバ flash」に一貫し、以後の操作追加も同じ型で拡張できる。

## 実装方針（概要）

| 変更 | 対象 |
|------|------|
| 新規 Response 3 クラス | `app/Http/Responses/Fortify/{ProfileUpdated,PasswordUpdated,PasswordReset}Response.php` |
| bind 追加 | `app/Providers/FortifyServiceProvider.php`（singleton 2 + bind 1） |
| メッセージ集約 | `app/Http/Responses/Fortify/RecoveryCodesGeneratedResponse.php`（`SUCCESS_MESSAGE`） |
| client toast 削除 | `resources/js/pages/Settings/Security.svelte`（`handleRegenerateSuccess` の `addToast('success')`） |
| Feature テスト | `tests/Feature/Auth/FortifyResponseTest.php`（3 操作の success flash + `wantsJson` JSON 契約） |
| vitest 更新 | `tests/js/pages/SettingsSecurity.test.ts`（再生成 happy path で client success toast を出さないこと） |

- `response()->json()` 直書きはしない（Fortify Response は `JsonResponse`／`RedirectResponse` を返す
  contract 実装で、AGENTS §禁止4 の対象外＝Fortify 既定と同じ形。DTO/JsonResource は不要）。
- flash-to-toast / toast store 自体は変更しない（正本は既に妥当）。

## 制約・前提

- Fortify Response contract の bind は `FortifyServiceProvider::register()` の既存ブロックに追加。
  `PasswordResetResponse` は constructor 引数 `$status` があるため **`bind`（非 singleton）**。
- profile/password update は `back()`（`/settings`）へ戻る → `AppLayout` の `consumeFlash` が発火。
  reset は login へ redirect → `AuthLayout` の `consumeFlash` が発火。両テンプレとも配線済み。
- `status` gating（flash-to-toast）は変更しない。既存の他 `status` 利用箇所に影響を与えない。
- DESIGN.md / Atomic Design: UI コンポーネントの新設・token 変更なし（既存 `ToastContainer` を流用）。

## スコープ外

- flash-to-toast / toast store のロジック変更（正本は妥当なため触らない）。
- 他の Fortify 操作（login/register/verify 等、既に success 化済み or 別 finding）。
- toast のデザイン・表示時間・アクセシビリティ改修。
- 2FA 有効化/無効化フローのメッセージ見直し（別スコープ）。

