全体判定: **CHANGES_REQUESTED**

Round 1 の Critical は適切に解消されています。Idempotency-Key を全 write テストへ付与しない判断も妥当です。ただし、ケース16は現在の期待値では「403が再生されていない」ことを証明できません。また、認可マーカー解析器そのものの自動回帰テストが不足しています。

## 施策1: exemption 分類 enum

判定: **APPROVE**

適用条件が十分に限定され、`NoAuthorizableSubject` の親テナント付き create への濫用もドキュメントで防止されています。

## 施策2: ControllerAuthorizationGateTest

判定: **REQUEST_CHANGES**

[Warning] 状態機械の誤合格防止が恒久テストになっていません。

一時的なコメントアウトによる確認だけでは、後から解析器を変更した際の回帰を検出できません。今回の gate 自体がセキュリティ機構なので、少なくとも以下を自動テストとして固定してください。

- `Gate::forUser($user)->authorize(...)` は合格
- 複数行チェーンは合格
- `Gate::forUser($user); $other->authorize(...)` は不合格
- コメントや文字列中の `Gate::authorize` は不合格
- import のない同名 `Gate` は不合格

解析処理を入力トークン列に対する純粋 helper として切り出すと、route inventory に依存せず直接テストできます。

[Warning] import 検査の解析範囲を明記する必要があります。

メソッド断片には通常 `use Illuminate\Support\Facades\Gate;` が含まれません。修正案は、認可呼び出しをメソッド断片から検出し、import はファイル全文を別途 token 化して名前空間レベルの `T_USE` として検出することです。クロージャの lexical `use` や trait use を import と誤認しないようにしてください。

[Suggestion] テスト計画の「その後 施策3で認可を足す」は、番号変更後は「施策4」です。

## 施策3: API project 存在オラクル封じ

判定: **REQUEST_CHANGES**

Round 1 の Critical に対する設計変更は正しいです。middleware による project 境界と `scopeBindings()` による item 境界を FormRequest より前へ移す構造も妥当です。

[Warning] middleware の存在だけでなく順序も Architecture テストで固定してください。

設計上、以下の順序はセキュリティ・動作上の契約ですが、現状は docblock にしか残っていません。

```text
resolve.api-actor
  < api-key.ability
  < api.project-in-org
  < idempotent
```

少なくとも `{project}` を持つ API route について、`gatherMiddleware()` 上で次を検証してください。

- `resolve.api-actor` が `api.project-in-org` より前
- `idempotent` がある場合、`api.project-in-org` がその前

これにより、attribute 未設定による500とcross-orgでの冪等性行作成を構造的に防げます。

## 施策4: ItemController の認可追加

判定: **APPROVE**

`Gate::forUser($this->apiActor($request)->user)`、ability、認可対象はいずれも妥当です。施策3を先行させ、controller inline guardも二重防御として残す判断にも問題ありません。

## 施策5: ItemAuthorizationTest

判定: **REQUEST_CHANGES**

[Warning] ケース16は現在の期待値では非再生を証明できません。

同じキーで1回目も2回目も403なら、2回目が再実行されたのか、保存済み403を再生したのか観測できません。

修正案:

1. viewerで同一キーを使い403を確認
2. viewerへ`project_admin`を付与
3. 同一キー・同一payloadで再送
4. 201とItem作成を確認

これなら、最初の403が保存されていた場合だけ2回目が403になり、事故を確実に検出できます。

Idempotency-Keyを他の全ケースへ付けない判断自体は **妥当** です。提示されたmiddleware実装ではヘッダなしが明示的なpass-throughであり、probe結果と既存テストの慣行も一致しています。

[Suggestion] ケース12と15の「同一応答」は、個別に404と`error.code`を確認するだけでなく、正規化したJSON bodyの一致をassertすると主張とテストが一致します。

## 施策6: OAuth helper 昇格

判定: **APPROVE**

global関数を削除して静的helperへ一本化する変更は、PHPStan level 10と「後方互換の並走を残さない」の両方に適合します。

[Suggestion] 変更箇所の説明に残っている「既存 global 関数を委譲に変更」は「削除し、全呼び出しを静的メソッドへ置換」に修正してください。

## 施策7: ドキュメント更新

判定: **APPROVE**

FormRequestより前に層2を閉じる要件、再現方法、`NoAuthorizableSubject`の制限まで記載されており、Round 1 の懸念を解消しています。

最終的な承認条件は次の2点です。

- 認可マーカー状態機械のpositive/negative自動テスト追加
- middleware順序のArchitectureテストと、権限変更を使ったケース16への修正