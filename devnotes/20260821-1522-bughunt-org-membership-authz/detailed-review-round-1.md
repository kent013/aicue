# 全体判定: CHANGES_REQUESTED

F-2-02 の対策方針、F-2-01 で `disabled` を採用しない判断、F-2-03 を production 変更なしで回帰テスト化する方針はいずれも妥当です。

ただし、招待先メール照合がメンバー追加と同じ排他区間に入っていない点と、いくつかのテストが意図した認可境界を実際には検証できない点は修正が必要です。以下は提供テキストのみを対象としたレビューです。

## 施策 1: F-2-02 招待受諾の宛先 email 照合

判定: REQUEST_CHANGES

- [Critical] email 照合が `joinOrganization()` のトランザクション／ロックより前にあり、照合と参加成立が原子的ではありません。照合後に招待またはユーザーの email が変更された場合、異なる宛先状態で参加が成立する TOCTOU が残ります。さらに、渡された `$user` が古いモデルなら開始時点から判定が stale です。

  修正案: `lockForUpdate()` 後の参加成立直前に、ロック下で再取得した招待と権威的なユーザー email を再照合してください。ユーザー email が変更可能ならユーザー行も一貫した順序でロックします。新しい主キー直接取得が必要な場合は `ModelDirectFetchInvariantTest` の分類・目録も設計対象に含めます。少なくとも「email が受諾中に変更されない」ことを別の機械的不変条件で証明できない限り、ロック外照合だけでは Service を唯一の権威とは呼べません。

- [Warning] 同一性規則が `acceptInvitation()`、`acceptInvitationIfValid()`、Controller に三重実装されます。「同じ規則」と文書化しても将来の正規化変更で分岐します。

  修正案: `OrganizationInvitation::isAddressedTo(User $user): bool` など、招待宛先判定そのものを表す単一の domain predicate に集約してください。これは異なる概念の統合ではなく、同一規則の単一出典化です。厳密比較を仕様とするなら、大小文字や前後空白をどう扱うかもテストで固定します。

- [Suggestion] `Assert::string()` が本当に必要かはモデルの PHPStan 型定義次第です。既に `string` と推論される場合、冗長な narrow よりモデル側の型を正本にする方が明瞭です。

## 施策 2: 受諾確認画面の不一致分岐

判定: REQUEST_CHANGES

- [Warning] `canAccept` は email 一致しか表していません。既に組織メンバーである場合など、`canAccept=true` でも Service は受諾を拒否します。prop 名が実際の意味より強いです。

  修正案: `isIntendedRecipient` や `recipientEmailMatches` へ改名するか、本当に全受諾条件を評価して `canAccept` を返してください。後者でも Service の再検証は必須です。

- [Warning] Controller が Service と別の比較式を持つため、補助 UX が権威的判定から乖離し得ます。

  修正案: 施策1の共通 predicate を Controller でも使用してください。GET の値はあくまで表示補助であり、POST では必ず再検証するという説明は維持して構いません。

guest 分岐より後に追加するため、T055 の register 誘導を直接壊さない構造は妥当です。また、不一致時も招待 email を返さない判断も適切です。

## 施策 3: Accept 画面

判定: REQUEST_CHANGES

- [Warning] UI を変更するのに、Svelte DOM テストが「必要なら」と任意扱いです。Feature テストで `canAccept=false` の prop 到達を確認しても、ボタンが消えること、説明が表示されること、正常時にフォームが残ることは証明できません。

  修正案: 少なくとも次を必須テストにしてください。

  - 一致時: 受諾ボタンが表示され、不一致文言がない
  - 不一致時: 受諾フォーム／ボタンがなく、案内文が表示される
  - PageHeader の description が両分岐で期待文言になる

- [Warning] PageHeader の文言を「実装時に調整」としており、詳細設計として期待値が未確定です。

  修正案: 一致時・不一致時それぞれの具体的な文言とテスト期待値を設計書に記載してください。

不一致者は認可対象外の主体であり、説明を表示して操作自体を出さない判断は、入力不足を理由に操作を無効化する禁止事項8とは区別できます。Service 側で直 POST を拒否する前提なら妥当です。

## 施策 4: 解決経路目録の説明更新

判定: APPROVE

token 解決の起点は変わらないため、`TokenHashLookup` を維持する判断は妥当です。email 不変条件は Feature テストで機械的に固定されるため、説明文だけの更新でも目録の責務には反しません。

ただし施策1をロック下再検証へ直した場合、説明も「解決後」ではなく「参加成立と同じ排他区間で再照合」と実態に合わせてください。

## 施策 5: F-2-02 Feature テスト

判定: REQUEST_CHANGES

- [Critical] ロック外照合のままでは、提示された T1〜T6 は TOCTOU を検出できません。

  修正案: 照合後・参加確定前に招待またはユーザーの email が変化する競合を再現し、join、role、`accepted_at` 更新が起きないテストを追加してください。実装上 email が不変なら、その不変性を担保するテストへ置き換えます。

- [Warning] T5 の「双方成功」は同じ招待を順番に使うと、先に成功した側が `accepted_at` を更新して後続が失敗します。

  修正案: 経路ごとに独立した招待・ユーザー・組織 fixture を生成し、同じ入力表を適用してください。一致、完全不一致、大小文字のみ相違を含めると「厳密比較」を固定できます。

- [Warning] T1 と T6 が「既存テストがあれば」「重複する場合」という未確定な計画です。回帰保証の所在が決まりません。

  修正案: 再利用する既存テスト名と必要な assertion を設計書で特定するか、この変更で明示的に追加してください。少なくとも T055 の session token、prefill email、AG-113 の recipient-scoped 一覧とアプリ内受諾成功をどのテストが担保するか確定させます。

- [Warning] T4 の「role が付かない」は Laratrust の team context やキャッシュ状態によって偽陽性になり得ます。

  修正案: `laratrust_team_id` を対象組織として明示し、必要なキャッシュ／relation をリセットした上で role pivot を DB assertion でも確認してください。`accepted_at`、organization pivot、project pivot、`current_organization_id` がすべて不変であることも確認すると副作用境界が明確です。

既存成功テストの受諾者 email を招待先へ合わせ、旧仕様の成功期待を残さない判断は正しいです。

## 施策 6: 除名／未割当 fail-closed テスト

判定: REQUEST_CHANGES

- [Critical] T8 が attach のみで `current_organization_id` を対象組織に設定しない場合、「role がないため403」ではなく「現在組織がないため403」になる可能性があります。これでは未割当 role の fail-closed を検証できません。

  修正案: membership は存在し、`current_organization_id` も対象組織である一方、Laratrust role だけがない状態を明示的に作ってください。各 route が role 不在により拒否されることを検証します。

- [Warning] T7 も除名後に `current_organization_id=null` となるため、`/projects` と `/billing` の403は membership 境界ではなく current-org 不在で成立し得ます。

  修正案: 自然な除名結果として null 化をまず検証した後、別ケースまたは明示的な stale-state fixture で `current_organization_id` を除名済み組織へ向け、membership／role がない状態でもアクセスできないことを確認してください。

- [Warning] `organizationRole === null` は relation や Laratrust キャッシュが残ると結果が不安定です。

  修正案: DB pivot の不存在を直接 assert し、モデル relation と Laratrust キャッシュをクリアしてから HTTP assertion を行ってください。

新規 PHP テストには `declare(strict_types=1);` を含め、グローバル `RefreshDatabase` を利用する方針を明記してください。

## 施策 7: F-2-01 option ラベル注記

判定: APPROVE

禁止事項8を根拠に、option を `disabled` にせず制約を事前表示する判断は妥当です。色、余白、SVG、component import を追加しないため、DESIGN.md と Atomic Design への新たな違反も見当たりません。

`ROLE_OPTIONS` は props に依存する値になるため、命名規約を重視するなら静的定数風の大文字名から `roleOptions` へ変更するのは検討できます。

## 施策 8: F-2-01 Svelte テスト

判定: REQUEST_CHANGES

- [Warning] option が disabled でないことだけでは、禁止事項8の「押下時にエラー表示する」まで担保できません。submit 側が無効化されていたり、サーバの validation error が画面に出なかったりしてもテストが通ります。

  修正案: 次を追加してください。

  - Default Project がない状態でも該当 role を選択でき、変更操作を開始できる
  - 変更ボタンなど最終操作も prerequisite を理由に disabled にならない
  - サーバが拒否した `role` エラーが画面に表示される
  - Feature テストで `applyConsoleRole` が実際に拒否し、role／project pivot を変更しない

T9a/T9b のラベル対称テスト自体は適切です。

## 横断評価

DTO／JsonResource と Inertia の使い分けは適切です。今回の画面応答は Inertia props であり、`response()->json()` や新規 JsonResource は不要です。Service が内部ユースケースの結果として `Organization` を返すことも、HTTP境界で配列を直接返す問題とは別です。

F-2-01 で禁止事項8を設計根拠にした判断は妥当です。ただし成立条件は「選択肢を disabled にしない」だけではなく、ユーザーが操作を実行でき、Service が権威として拒否し、その理由が画面に表示されるところまでです。ここを施策8のテストで閉じれば承認可能です。