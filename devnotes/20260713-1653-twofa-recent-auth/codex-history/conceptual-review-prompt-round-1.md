# アプリの使命・禁止事項・思考原則（レビュー基準）

## アプリの使命（North Star — AGENTS.md より）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告（不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件（抜粋・アプリ都合で緩めない）

- 権限判定は常に `laratrust_team_id` を明示。tenant キー不信。cross-org 不可。機微操作 route は recent-auth（step-up）で保護し、付与漏れは Architecture テストで固定。

## 思考原則
まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵（Laravel/Fortify の作法）を探せ。今必要なものだけ作る（オーバーエンジニアリング禁止）。後方互換の並走を残さない。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# レビュアーとしての役割・観点

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Fortify + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（例: 2FA 必須組織の enrollment 動線、SSO-only ユーザーの詰み、ログイン直後の二重再認証）
6. スコープの適切さ: 過大または過小になっていないか（disable のみに絞るのは妥当か。enable/confirm/qr-code/secret-key を除外する判断は正当か）
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 補足コンテキスト（レビュー判断の材料。現行コードの事実）

- `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` は全 provider boot 後の `booted` callback で名前解決し、Fortify 登録ルートへ `recent-auth` middleware を idempotent に後付けする既存機構。現状の対象 const 配列は `['two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes']`。本設計はこれに `'two-factor.disable'` を足すだけ。
- `RequireRecentAuth` middleware は fresh (`recent_auth_at` が 15 分窓内) なら通過、stale の XHR/Inertia mutation は 409 + `RecentAuthRequiredResource`（`{code:'recent_auth_required', message, redirect}`）、通常遷移は 302 で `recent-auth.confirm` へ。
- `StampRecentAuthOnLogin` listener が web guard の非 recaller login を recent_auth として stamp する（ログイン直後は fresh）。
- フロント `Settings/Security.svelte` には既に `guardWithRecentAuth(action)` があり、regenerate 系はこれで precheck 済み。disable は現状 precheck なしで `router.delete("/user/two-factor-authentication")` を直呼びしている。
- `RecentAuthRouteTest`（Architecture）が allowlist の付与漏れを CI 固定。`TwoFactorRecoveryCodesStepUpTest`（Feature）が stale 遮断 / fresh 通過を HTTP 検証する既存テスト形式。

---

# 概念設計

（以下、devnotes/20260713-1653-twofa-recent-auth/conceptual-design.md の内容）

---
# 概念設計: twofa-recent-auth

## 背景・課題

bug-hunt finding **F-H3 (High, authz_bypass)**。

2 要素認証の管理操作のうち、**第二要素の無効化 (`two-factor.disable`, `DELETE /user/two-factor-authentication`)** が step-up 再認証 (recent-auth) を一切要求せず、通常セッション認証のみで実行できる。セッションハイジャック時、攻撃者はパスワードを知らないまま被害者の 2FA を無効化でき、以後アカウント乗っ取りの障壁が消える。

### 根本原因

- 2FA 管理操作は Fortify 標準ルート (`vendor/laravel/fortify/routes/routes.php`) で定義される。
- 本アプリは Fortify 標準の `password.confirm` を撤去 (`config/fortify.php` の `twoFactorAuthentication(['confirmPassword' => false])`) し、step-up を generic recent-auth (15 分窓・password または再SSO) へ統一している。
- Fortify 登録ルートへ recent-auth を後付けする配線 `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` は存在するが、その対象 `RECENT_AUTH_ROUTE_NAMES` に含まれるのは **`two-factor.recovery-codes` / `two-factor.regenerate-recovery-codes` のみ**で、`two-factor.disable` が抜けている。

### finding の記述との差分 (重要)

finding F-H3 は「disable と regenerate-recovery-codes が step-up を要求しない」と記述するが、**現行コードでは `two-factor.regenerate-recovery-codes` (および `two-factor.recovery-codes`) は既に recent-auth 配線済み** (`RECENT_AUTH_ROUTE_NAMES` 登録済み・`RecentAuthRouteTest` / `TwoFactorRecoveryCodesStepUpTest` で CI 固定)。したがって finding のうち regenerate 部分は既に解消済みで、**残存する真の bypass は `two-factor.disable` の 1 経路**である。本設計はこの残存ギャップを塞ぐ。

## 改善アイデア

`two-factor.disable` を既存の recent-auth 後付け配線に載せ、無効化を step-up 必須にする。実装は **既存の確立パターンをそのまま踏襲**する（新機構は作らない）:

1. `FortifyServiceProvider::RECENT_AUTH_ROUTE_NAMES` に `'two-factor.disable'` を追加 → `attachRecentAuthToSensitiveRoutes()` の booted callback が recent-auth middleware を後付けする。
2. `RecentAuthRouteTest` の allowlist (`recentAuthRequiredRouteNames()`) に `'two-factor.disable'` を追加 → 付与漏れを CI で固定。
3. Feature テストで stale 遮断 / fresh 通過を HTTP 経由検証（`TwoFactorRecoveryCodesStepUpTest` と同形式）。
4. フロント `Settings/Security.svelte` の `disableTwoFactor()` を既存の `guardWithRecentAuth()` でラップし、stale 時に再認証モーダルを挟んでから無効化を再開する（UX を regenerate と揃える）。

## 期待効果

- **セキュリティ不変条件の回復**: セッションハイジャック単独では 2FA を無効化できなくなる（password 再入力 or 再SSO を強制）。姉妹操作 `organizations.members.two-factor.reset`（他人の 2FA 解除）と同一基準に揃い、「自分の 2FA 解除だけ無防備」という非対称を解消。
- **使命への貢献**: 現場作業者の標準化マニュアル資産を守るテナントの認証境界を堅牢化する。2FA 必須組織のガバナンス（`organizations.two-factor-requirement`）が、セッション奪取による一撃無効化で骨抜きにされるのを防ぐ。
- **UX 一貫性**: disable も regenerate/API キー失効と同じ再認証モーダル導線になり、SSO-only ユーザーも fail-closed で再SSO に誘導され詰まない。

## 実装方針（概要）

| 層 | 変更 | 方式 |
|----|------|------|
| ルート配線 | `two-factor.disable` に recent-auth 付与 | `FortifyServiceProvider` の既存 const 配列に 1 要素追加（新規コードなし） |
| Architecture テスト | allowlist へ登録 | `RecentAuthRouteTest` の関数に 1 行追加 |
| Feature テスト | stale 遮断 / fresh 通過 | `TwoFactorRecoveryCodesStepUpTest` に倣い新規テスト |
| フロント | disable 前段 precheck | 既存 `guardWithRecentAuth()` で `router.delete` をラップ |
| ドキュメント | config TODO の追従 | `config/fortify.php` の TODO コメントから `disable` を「対応済み」へ更新 |

DTO/JsonResource は既存の `RecentAuthRequiredDto` + `RecentAuthRequiredResource`（middleware が 409 応答に使用）をそのまま利用。新規 DTO/Resource は不要。

## 制約・前提

- **login は recent_auth を stamp する** (`StampRecentAuthOnLogin` listener)。ログイン直後は 15 分窓内のため、正当ユーザーの通常フロー（ログイン → 設定画面で 2FA 無効化）で余計な再認証は発生しない。stale（ログイン後 15 分超）でのみ再認証を要求する。
- middleware `RequireRecentAuth` は Inertia mutation（`X-Inertia` + 非 GET）を 409 + `{code, message, redirect}` で返す。`router.delete` は Inertia mutation のため、precheck を通さず stale で叩くと 409 を受ける。既存 `guardWithRecentAuth()` で precheck して UX を成立させる（最終ゲートは常に server middleware）。
- route:cache 下でも `attachRecentAuthToSensitiveRoutes()` は同一 Route instance に memoize 反映されるため有効（既存 docblock 参照）。
- PHPStan L10 / Pest / RefreshDatabase グローバル適用に準拠。

## スコープ外

- **`two-factor.enable` / `two-factor.confirm`**: 第二要素の *追加* はセキュリティのダウングレードではなく（2FA を付与する行為でアカウントが弱くなることはない）、bypass ではない。加えて 2FA 必須組織のオンボーディング（enrollment）動線と絡むため、`config/fortify.php` の TODO(template) が明示的に「衝突しない設計を決めてから固める」と保留している。本設計では触らない（finding F-H3 の対象外でもある）。
- **`two-factor.qr-code` / `two-factor.secret-key`**: TOTP secret を露出するが、意味を持つのは enrollment（confirm 前）フェーズであり、確立済み第二要素の bypass ではない。enable/confirm の enrollment 再設計と一体で扱うべきで、本設計では触らない。
- recent-auth の窓幅・method policy・モーダル UI 自体の変更。既存機構をそのまま使う。
- 他の Fortify エンドポイント（passkey 等）の step-up 見直し。

