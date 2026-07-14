**全体判定**
- **APPROVED**

**総評**
- 原因切り分け（「サーバ既存でValidationException返却済み」vs「フロントのDOM乖離」）は妥当です。  
- 修正スコープを `Admin/Users.svelte` に閉じ、バックエンドは回帰アサーション強化のみに留める判断は、既存契約維持・影響最小化の観点で適切です。  
- DESIGN/Atomic/セキュリティ不変条件にも概ね整合しています。  

---

**S1 ロール変更 UX 修正**: **APPROVE**
- [Suggestion] `{#key}` による Select 再マウントで「admin→admin同値のためDOMが戻らない」問題を断つ設計は正しいです。
- [Suggestion] `onFinish` 後 `tick()` でフォーカス復帰する順序は、disabled解除後フォーカス不可問題を回避できており妥当です。
- [Suggestion] `error` + `aria-describedby` を Select に接続し、`FormError` を近接配置する方針はアクセシビリティ上適切です。
- [Suggestion] `disabled={changingRole}` は「必須未充足だから無効化」ではなく「通信中直列化」なので禁止事項8に抵触しません。
- [Warning] `pageErrors.role` が配列化される将来変更に弱い可能性があります。  
  **修正案**: `const roleMessage = Array.isArray(pageErrors.role) ? pageErrors.role[0] : pageErrors.role;` を介して表示・`aria-describedby` 条件判定を一本化すると堅牢です（現状契約が文字列固定なら即時必須ではない）。

---

**S2 フロント回帰テスト（6ケース）**: **APPROVE**
- [Suggestion] 6ケースは不具合再現点（DOM乖離、invalid接続、失敗行限定、in-flight直列化、フォーカス復帰）を十分カバーしており、回帰ネットとして適切です。
- [Suggestion] `router.patch` の `options` 手動発火で時系列（onError→onFinish）を固定する方針は、今回バグ特性に合っています。
- [Warning] フォーカス復帰テストは `tick()` 依存で不安定化しうるため、待機を明示したほうが安全です。  
  **修正案**: `await waitFor(() => expect(screen.getByTestId("member-role-2")).toHaveFocus())` を使用し、テストの非決定性を下げる。

---

**S3 バックエンド回帰 assertion 強化**: **APPROVE**
- [Suggestion] `assertSessionHasErrors('role')` + ロール不変に `assertSessionMissing('success')` を足すのは、「silent successではない」を明示固定でき、有効です。
- [Suggestion] 既存の Inertia リダイレクト契約（HTML mutation, error bag）を崩さずに防波堤を増やしており、設計意図と一致します。

---

**レビュー観点別チェック**
- 正確性: 問題の主因に対して対症でなく根治（DOM再同期）になっている  
- 既存整合: Inertia/Controller契約維持、DTO/JsonResource非変更で整合  
- PHPStan L10: PHP本体変更なし、悪化要素なし  
- テスト網羅: 失敗/成功/並行操作/アクセシビリティまで押さえている  
- Inertia Props vs API: Props契約不変で適切  
- 副作用/後退: 変更範囲限定、F-14不変条件への配慮あり  
- セキュリティ: 認可・IDOR・tenant境界に影響なし  
- DESIGN.md/Atomic: 既存atomの組み合わせで準拠、階層逆流なし  

必要なら次段で、上記 Warning 2点（`role`メッセージ正規化 / フォーカステスト待機の安定化）だけを「任意の堅牢化パッチ」として提案できます。