## 全体判定: CHANGES_REQUESTED

### 1. 使命との整合性

[Suggestion] 両件とも動画作成の中核機能ではありませんが、「思考ゼロ・編集ゼロ」を支えるアカウント／課金操作の自己解決性とアクセシビリティを高める改善として妥当です。特に成功・失敗の認知不能を減らす点は、現場利用者の迷いを減らします。

### 2. 禁止事項違反

[Warning] 禁止事項への明示的な抵触はありません。ただし実装完了には、F-4-01 のリダイレクト・flash 到達と、F-3-01 の `aria-invalid`／`aria-describedby` を検証するテスト追加が必須です。

修正提案: 概念設計の実装方針に、PHP Feature テストと JS コンポーネントテストの追加を明記してください。

### 3. 実現可能性

[Critical] F-3-01 の実装方針に、既知のコンポーネント契約との矛盾があります。提供された事実では `Input` の `aria-invalid` は、`FormField` が `error` prop から導出した `invalid` を snippet 経由で渡す仕組みです。一方、設計は「各 `Input` の `error` prop に流し込む」としており、この prop が存在・利用される前提は確認済み事実と一致しません。

修正提案: per-field のエラー値は `Input` ではなく各 `FormField` の `error` prop に渡し、snippet が渡す `invalid` を既存どおり `Input` に接続してください。`aria-describedby` は対象の `Input` に統合エラー要素の id を付与します。

[Suggestion] F-4-01 は Laravel の named route と redirect flash の既存パターンだけで実現でき、Laravel 12 + Fortify + Inertia の構成でも妥当です。

### 4. 期待効果の妥当性

[Warning] F-4-01 の「未認証ならメール変更直後と実質同値」という判定は、現在の `/settings` の到達経路では成立しますが、レスポンスクラスの責務としては状態から操作原因を推測しています。将来の利用経路追加やテストで、未認証ユーザーの氏名更新が可能になると誤って認証案内へ遷移します。

修正提案: `! $user->hasVerifiedEmail()` 単独ではなく、「この更新で email が変更された」ことも併せて判定してください。例えば更新直後の Eloquent の変更追跡を用い、`wasChanged('email') && ! hasVerifiedEmail()` のように操作事実と状態を両方確認します。

[Suggestion] 最終着地で成功トーストが表示されること、余分な `/settings` ホップが消えることは、提示された原因分析から合理的に期待できます。

### 5. リスク

[Warning] F-4-01 は認証状態遷移に関わるため、氏名のみ更新、メール変更、JSON 応答の3経路を分けて固定しないと、既存の Fortify 契約や recent-auth フローを後退させるリスクがあります。

修正提案: 少なくとも以下をテストしてください。

- fresh なメール変更で `verification.notice` へ redirect し、success flash が載ること
- 氏名のみ更新では従来どおり `back()` と既存メッセージを維持すること
- `expectsJson()` では既存どおり空の 200 JSON 応答であること
- stale session の recent-auth 完了後にも最終画面で flash が消えないこと

[Warning] F-3-01 で両入力を常に invalid にすると、実際には一方だけ修正すればよいケースで支援技術に誤った修正対象を伝えます。

修正提案: 範囲エラーの種類ごとに原因フィールドを明示的に定義し、その入力だけを invalid にしてください。両者の関係自体がエラー対象なら、両方を invalid にする条件をテストで固定してください。

### 6. スコープの適切さ

[Suggestion] `EnsureEmailIsVerified` 全体への flash keep 一般化を避け、当該レスポンスの最終着地へ直接遷移させる方針は適切です。変更が局所的で、既存の `/email/verify` 導線も維持します。

[Suggestion] F-3-01 も共有 `FormField` を変更せず、呼び出し側の配線に限定する方針が適切です。

### 7. 型安全性

[Warning] `ProfileUpdatedResponse` の分岐に必要なユーザー取得方法と、メール変更検知 API を明示しないと、PHPStan level 10 で nullable な request user やモデル型の扱いが曖昧になります。

修正提案: Fortify contract の既存メソッドシグネチャに従い、認証済みユーザーを明確な型で取得してください。メール変更判定は型付き Eloquent API を使用し、JSON 応答・RedirectResponse の戻り値型も既存 contract に合わせて維持してください。DTO／JsonResource の新設は不要で、既存の仕様固定 JSON 応答を変更しないことが適切です。