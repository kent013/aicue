# 全体判定: CHANGES_REQUESTED

中核ロジックの方向性は妥当です。特に `wasChanged('email')` による分岐と、FormField 単位へのエラー分割は正しい設計です。一方、ユーザーに約束する挙動をテストが十分に観測していない点と、既存の `aria-live` を失う可能性が残っています。

## 施策1: F-4-01 — REQUEST_CHANGES

[Critical] 該当なし。

[Warning] 「認証メールを送信しました」というメッセージの前提がテストされていません。

`Notification::fake()` を置くだけでは、通知処理が欠落してもテストが通ります。次のいずれかを設計に明記してください。

- 同じ Feature テストで、メール変更時に認証通知が新しいユーザーへ dispatch されたことを `Notification::assertSentTo()` で確認する。
- 既存テストが保証しているなら、そのテスト名と保証内容を明記する。

通知を保証できない場合は、「認証メールを送信しました」という確定表現を避ける必要があります。

[Suggestion] それ以外の実装判断は妥当です。

- 同一 Request・同一 User インスタンスという確認済み事実から、現在の `wasChanged('email')` は成立します。
- `instanceof User` による narrowing は PHPStan level 10 に適合します。
- `redirect()->route()` は `redirect()->intended()` の禁止に抵触しません。
- 新アドレスを flash に載せないため、不要な PII 複製もありません。
- Fortify contract の固定レスポンスなので、ここでの DTO/JsonResource 新設は不要です。
- JSON/Inertia の責務分離も維持されています。

## 施策1-T: Feature テスト — REQUEST_CHANGES

[Warning] `/email/verify` GET 後の `assertSessionHas()` だけでは、Inertia 画面が成功メッセージを受け取ったことを直接保証できません。

今回の機能名は「認証画面で成功フィードバックを出す」です。着地レスポンスについて、`consumeFlash` が読む Inertia props の `success` 値まで `assertInertia()` で検査してください。セッションだけを見るテストでは、共有 props の配線が将来壊れても緑になる可能性があります。

[Warning] recent-auth 統合テストを「fresh な直接 PUT で代替可」とするのは不十分です。

直接 PUT はレスポンス分岐を検査できますが、stale 判定→recent-auth 完了→元操作再送という経路を保証しません。次のいずれかに確定してください。

- パスワード確認後にプロフィール PUT を再送し、最終的に `verification.notice` と Inertia flash まで確認する。
- 統合保証をスコープから外し、どの既存テストへ保証を委ねるのか明記する。

現在説明されている既存テストは「再認証へ戻されない」ことしか見ておらず、最終着地とフィードバックの代替にはなりません。

[Warning] JSON 本文の表現を正確に固定してください。

`new JsonResponse('', 200)` のワイヤ上の本文は空ボディではなく、JSON文字列の `""` です。テスト計画の「空 JSON」は曖昧なので、少なくとも次を固定するのが適切です。

- HTTP 200
- JSON Content-Type
- 本文が正確に `""`

[Suggestion] メール変更テストの前提として、利用者が変更前には認証済みであり、`recent_auth_at` が fresh であることを明示的に Factory state／属性で設定してください。Factory の暗黙のデフォルトに依存させない方が、`verified` middleware を含む回帰テストとして安定します。

## 施策2: F-3-01 — REQUEST_CHANGES

[Critical] 該当なし。

[Warning] 統合エラーの `<p aria-live="polite">` を撤去した後の動的通知手段が確認できません。

`aria-invalid` と `aria-describedby` は入力とエラーの関連付けには有効ですが、ボタン押下後にフォーカスがボタンへ残る場合、新しく現れたエラーが即時に読み上げられるとは限りません。提示された事実からは、`FormError` が `role="alert"` または `aria-live` を持つことを確認できません。

修正案は次のいずれかです。

- `FormError` が既に live-region semantics を持つ事実を設計へ追記し、コンポーネントテストで保証する。
- 持たない場合は、共有 atom の影響範囲を調べたうえで `FormError` に適切な通知 semantics を追加し、atom のテストも更新する。
- 共有 atom を変更しないなら、カード内に重複表示を生まないスクリーンリーダー向け live region を残す。

この確認なしに既存の `aria-live` を消すと、別のアクセシビリティ後退を作る可能性があります。

[Suggestion] per-field 派生のロジック自体は正しいです。

- threshold-first の排他性が保たれています。
- 大小関係違反を max 側だけに帰属させる判断も適切です。
- `rangeError` を raw validity、各 `*Error` を表示状態として分離するため、押下時表示の契約も維持できます。
- FormField→Input の既存配線を利用しており、Atomic Design、DS token、Lucide の規約にも抵触しません。

## 施策2-T: JSテスト — REQUEST_CHANGES

[Warning] `getByText(/リチャージ後の残高は/)` は、ラベルにも一致し得るため誤検出または複数一致の可能性があります。また、その文言が max 入力へ関連付けられたことを保証しません。

修正案として、対象 spinbutton をラベルで取得し、次を一体で検査してください。

- `aria-invalid="true"`
- `aria-describedby` がエラー要素の ID を参照する
- `toHaveAccessibleDescription(正確なエラー文言)` などで関連付けられた説明を確認する

これにより、単に画面のどこかに同じ文字列があるだけの false positive を防げます。

[Warning] 大小関係違反テストでは、個別範囲としては有効だが `max <= threshold` になる具体値を明記してください。

そうしないと、`parsedMax === null` の分岐を再度踏んでいるだけでも「大小関係違反テスト」が通る可能性があります。少なくとも以下の3分岐を区別すべきです。

- threshold の解析・範囲エラー
- max の解析・範囲エラー
- 両方とも個別には有効だが、max が threshold 以下

[Suggestion] `data-testid` より `getByRole("spinbutton", { name: ... })` を優先すると、label と input の配線も同時に回帰検査できます。

## 横断的な修正

[Warning] テストファーストの実行順を詳細設計に明記してください。

1. Feature/JS テストを先に変更し、対象テストが期待理由で失敗することを確認
2. 実装
3. 対象テストを green
4. リポジトリ既定の全検証コマンドを実行

最終検証は `composer test`、`composer phpstan`、`vendor/bin/pint --test`、`pnpm lint`、`pnpm typecheck`、`pnpm test`、`pnpm build` と package 系3コマンドを含む既定一覧に合わせる必要があります。`composer fix`／`pnpm lint:fix` だけでは完了条件になりません。