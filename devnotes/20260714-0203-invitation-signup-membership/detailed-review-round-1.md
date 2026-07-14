以下、提示された**詳細設計**に対するレビュー結果です（実装は未実施、設計レビューのみ）。

**施策1 判定（CreateNewUserで招待成立時にcurrent組織確定）: REQUEST_CHANGES**
- [Critical] `current_organization_id` 確定の条件が `=== null` のみだと、理論上「登録前に値が入っている異常状態」を温存します。登録フローの責務としては「招待成立時は招待先を現在組織にする」を強制した方が不変条件に強いです。  
  修正案: `if ($user->current_organization_id !== $joined->id) { forceFill... }` への変更を検討（少なくとも register 経路では上書き許容を明文化）。
- [Warning] `acceptInvitationIfValid()` と `forceFill(...)->save()` が別操作のため、同一トランザクション内でも将来 `acceptInvitationIfValid` 側の実装変更で整合が崩れる余地があります。  
  修正案: register専用の service メソッド（例: `acceptInvitationForRegistration(...)`）を追加し、「参加付与＋current確定」までを1ユースケースとして閉じる設計にする。
- [Suggestion] `else` 節の意図は妥当で、`joinOrganization()` に昇格しない判断も正しいです（POST受諾経路の副作用回避）。

**施策2 判定（Featureテスト追加）: REQUEST_CHANGES**
- [Critical] テストファースト要件に対し、設計文書では「失敗確認」を記述していますが、実際にどのテストが現行で落ちるかの粒度が不足しています。レビュー可能性のため、失敗期待を各テスト単位で明記すべきです。  
  修正案: 2-1/2-2/2-4それぞれに「現行での失敗点（expected null / expected mismatch）」を1行追記。
- [Warning] 2-2 が `verification.notice` のInertia実装に依存しており、Fortify差し替えで壊れやすいです。設計内で代替観測点の優先順が未定義。  
  修正案: 「観測点選定ルール」を追記（1. 未検証アクセス可 2. Dashboard resolver非経由 3. Inertia応答）し、候補ルートを1つ以上列挙。
- [Suggestion] 2-5 fallbackテストにも `current_organization_id` assert を追加する方が、A/B排他の証明としてより強固です（設計でも“必要に応じて”ではなく“必須”推奨）。

**レビュー観点別サマリ**
- 正確性: 方向性は正しいが、register責務の強制度が弱い。
- 既存整合: `joinOrganization` 非変更は妥当。
- PHPStan L10: 型ナローイングの説明は十分。
- テスト網羅: 主要ケースは押さえているが、失敗期待の明文化不足。
- DTO/JsonResource/Inertia: 逸脱なし。
- セキュリティ: tenantキー不信・cross-org配慮は概ね良好。
- 波及網羅: TS/Props不変の整理は適切。

**全体判定: CHANGES_REQUESTED**  
設計の主方向は妥当で実装価値も高いですが、**(1) register経路でのcurrent組織確定ポリシーの強化**と**(2)テストファースト証跡の明確化**を反映してから承認が適切です。