【アプリの使命（North Star）— AGENTS.md より】

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での redirect()->intended()(back()->with(...) で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件（抜粋）】
- tenant キー不信 / 子は親に属する(不整合は認可より前に 404) / cross-org 不可
- PII(email/name)は CipherSweet、検索は whereBlind()
- 権限判定は常に laratrust_team_id を明示(strict_check=true)

【思考原則】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。オーバーエンジニアリング禁止(今必要なものだけ作る)。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: twofa-unconfirmed-reset-button

bug-hunt run 20260715-084108 F-2-03 (Medium, H10)

## 背景・課題

組織メンバー管理画面 (`manage.users` / `resources/js/pages/Admin/Users.svelte`) で、
TOTP の QR/secret を生成しただけで **TOTP 確認を完了していない** (`two_factor_confirmed_at`
が null = `pending`) メンバーに対して「2FA 解除」ボタンが表示される。
これによりオーナー/管理者は、そのメンバーが 2FA を有効化済みと誤認する。
一方でメンバー本人の設定画面は「無効」と正しく表示しており、管理画面と本人画面で
2FA 状態の見え方が食い違う。

### 原因の所在 (調査済み)

- バックエンドは確定状態を既に露出している。
  `App\Models\User::twoFactorStatus()` は Fortify の 2 カラム
  (`two_factor_secret` / `two_factor_confirmed_at`) から 3 値
  (`disabled` / `pending` / `enabled`) を導出する状態機械 (`App\Enums\TwoFactorStatus`)。
  `App\DataTransferObjects\Admin\MemberRowData` はこの 3 値を `twoFactorStatus` として
  Inertia props に載せており、TS 側 `MemberRow.twoFactorStatus` も
  `"disabled" | "pending" | "enabled"` を受けている。
  → props に confirmed 状態は既に含まれている。Resource/DTO/Controller の追加露出は不要。

- バグはフロントの判定条件にある。
  `Users.svelte` の `canResetTwoFactor()` は `member.twoFactorStatus === "disabled"` の
  ときだけ false を返す。`pending` はこの early-return を通り抜け、role 条件を満たせば
  ボタンが表示される。すなわち pending を enabled と同一に扱っているのが直接原因。
  2FA バッジ (L276) は `=== "enabled"` でのみ表示され正しい → バッジと解除ボタンで不一致。

- サーバ側 guard も pending を通す (副次)。
  `OrganizationMemberController` の 2FA リセット経路は
  `twoFactorStatus() === TwoFactorStatus::Disabled` のときだけ拒否し、pending は素通しで
  `$disableTwoFactorAuthentication()` を実行する。結果、未確認 secret のクリアに対して
  「2 段階認証を解除しました」という誤解を招く監査記録 + セキュリティ通知が発生し得る。

## 改善アイデア

「2FA が確定 (enabled) しているメンバーにのみ『2FA 解除』ボタンを表示する。
未確認 (pending) は 2FA 無効として扱い、解除ボタンを出さない。」

1. (主) フロント表示条件の修正: `canResetTwoFactor()` を「enabled のときだけ true」に。
   pending / disabled はともに false。ボタン・バッジ・本人設定画面が一致する。
2. (副・防御) サーバ guard の一貫化: リセット拒否条件を `=== Disabled` から `!== Enabled` へ
   広げ、pending も明示拒否。UI と API の意味論を揃え、誤解を招く監査記録/通知を防ぐ。

バックエンドの props 露出 (Resource/DTO/Controller) は変更しない (既に十分)。

## 期待効果

- 使命への貢献: 現場運用者がメンバーのセキュリティ状態を誤認しない管理 UX の正確性を回復。
- 具体的改善: 管理画面 (バッジ/解除ボタン) と本人設定画面が一致。未確認メンバーへの
  誤操作と誤解を招く監査ログ/通知が発生しなくなる。

## 実装方針（概要）

- `resources/js/pages/Admin/Users.svelte`: `canResetTwoFactor()` を
  `member.twoFactorStatus !== "enabled"` で早期 false (isSelf / role 境界は現状維持)。
- `app/Http/Controllers/Organizations/OrganizationMemberController.php`: 拒否 guard を
  `!== TwoFactorStatus::Enabled` に広げる (エラー key は現状 `two_factor` 踏襲)。
- テスト: vitest (pending 出ない / enabled 出る)、Feature (props が pending を返す)、
  Feature (pending リセット拒否)、必要なら UserFactory に pending state 追加。

## 制約・前提

- v1 スコープ尊重。PII/tenant 境界: 本画面は manageMembers 権限者のみ到達 (403)。
  既存 props の解釈変更のみで新規 PII 露出・cross-org read/write は増やさない。
- DTO + JsonResource / Inertia パターン維持。PHPStan L10 / Pest / DS token / Atomic Design 維持。
- 後方互換の並走を残さない (旧判定を残さず置換)。

## スコープ外

- 3 値 enum の表現変更 (既に正しい)。本人 2FA 設定フロー (Fortify) の変更。
- pending 状態の可視化 (「設定中」バッジ追加等) — 今回は「無効として扱う」に留める。
- backend の Resource/DTO/Controller への confirmed フラグ追加 (不要と判明)。
