# 対応マトリクス: design-review Round 4

Codex 判定: 施策 B **APPROVE** / 全体 **APPROVED**（Critical 0 / Warning 0 / Suggestion 2、いずれも非ブロッキング）。
Round 3 で反論した `password_confirmation` の件は「反論は妥当」と受理された。

## [Suggestion] `assertGuest()` は TestResponse のメソッドではない
- 判断: **対応する**
- 根拠: 指摘のとおり。`assertGuest()` は `Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication`
  （TestCase 側）のメソッドで、`TestResponse` にはチェーンできない。
- 対応内容: 設計を `$response->assertRedirect('/'); $this->assertGuest();` の 2 文に修正した。

## [Suggestion] DoD に「SSO 専用ユーザー」が残っている / 「この 1 本が Welcome→login を保証」は不正確
- 判断: **対応する**
- 対応内容:
  - DoD の記述を「password 未設定かつ利用可能な再認証 provider が無いユーザー」に統一した。
  - B-2 の説明にあった「SSO 専用ユーザーは使えない」も「パスワード未設定のユーザーは使えない」に修正。
  - 「この 1 本が回復手順の終端を保証する」を、
    「Feature テストはサーバ側の終端（ログアウト着地 → reset → `canSatisfy=true`）を保証し、
    画面上の導線は既存の `Welcome.test.ts` / `Login.test.ts` が保証する = **テスト群全体で担保**」
    という表現に改めた。

## 最終確認（app-design Phase 2-5）
- 全施策が使命に寄与する: 認証ファネルの行き止まり・偽の導線を除去することは、
  「専門知識ゼロの現場作業者でも使える」ための前提条件（入口が壊れていれば SOP → シナリオ →
  撮影の価値提供に到達できない）。
- 禁止事項違反なし: テスト必須（#1）を各施策で満たし、`response()->json()` 直書きなし（#4）、
  `redirect()->intended()` の新規追加なし（#7）、disabled による抑止をしない（#8）、
  Artifact 不使用（#9）。
- コーディングルール反映: PHPStan level 10 適合チェック節あり、Pest/vitest/architecture の
  テスト層を各施策に割り当て、Factory 生成（`User::factory()->ssoOnly()` /
  `createOrganizationWithOwner()`）を明記。
