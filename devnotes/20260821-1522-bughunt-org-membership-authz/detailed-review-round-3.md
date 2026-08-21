# 全体判定: CHANGES_REQUESTED

実装方針そのものは承認可能な水準です。特にロック読みした `$lockedUser` の使用、DirectFetchInventory 登録、403/404 の確定、Inertia validation 契約の修正は妥当です。

ただし、T4b の記述には実行不能または誤った失敗理由になり得る点が残っています。セキュリティ対策の機械的証明なので、ここは実装前に確定が必要です。

## 施策 0: domain predicate

判定: APPROVE

インメモリ比較の単一出典として保証範囲が正確になりました。CipherSweet のDB検索とは別レイヤであることも適切に明記されています。

[Suggestion] T5 が blind-index検索との同値まで固定すると記載するなら、T5の入力表に `activePendingForEmail()` の結果も含めてください。現状の T5 は register と token POST の比較であり、DB検索を直接検証していません。含めない場合は「DB検索との同値は T5 が固定」という記述を、既存の `PendingInvitationScopeTest` が担保する範囲に合わせて狭めてください。

## 施策 1: ロック下再照合

判定: APPROVE

`lockForUpdate()` で取得した `$lockedUser` を最終判定へ使う設計により、Round 2 の問題は解消されています。既存の canonical lock を取得済みで、同一行を再取得するため新しいロック順序を作らないという説明も妥当です。

DirectFetchInventoryへの登録と、既存 Architecture テストによる call-site 固定も波及変更に含まれています。

[Suggestion] 既にロックを取る helper がモデルを取得しているなら、その `$lockedUser` を返す方が重複クエリを避けられます。ただし、現在の再ロック読みも正確性上は問題ありません。

文書内に旧記述が残っています。実装時の取り違えを防ぐため、次を `$lockedUser` に統一してください。

- 二段照合節の「fresh 再取得したユーザー」
- PHPStanチェックの「`$user->fresh()` は `Assert::isInstanceOf`」
- T4b末尾の「fresh なロック値」

また「デッドラインを導入しない」は「デッドロックを導入しない」の誤記です。

## 施策 2: Controller 補助UX

判定: APPROVE

`recipientEmailMatches` の意味が限定され、共通 predicate を利用しています。Serviceを権威とする構造、Inertia propsの使用、非受信者への組織名非表示も適切です。

変更箇所説明に残る「`canAccept => false`」だけ、`recipientEmailMatches => false` に更新してください。

## 施策 3: Accept画面

判定: APPROVE

一致／不一致の文言、DOM分岐、Svelteテストが具体化されています。DESIGN.md、Atomic Design、禁止事項8のいずれにも問題はありません。

## 施策 4: 解決経路目録

判定: APPROVE

解決起点の分類を変えず、排他区間での再照合を説明へ反映する設計は妥当です。

## 施策 5: F-2-02 Featureテスト

判定: REQUEST_CHANGES

- [Critical] T4bの手順2にある `User::query()->whereKey(...)->update(['email' => ...])` は使用できません。モデルをロードしない一括更新では、CipherSweet の暗号化処理やモデルイベントを迂回して平文または不正な値を書き込む可能性があります。その場合、最終照合による拒否ではなく復号失敗を検証するテストになります。PII不変条件にも反します。

  修正案: staleインスタンスとは別のモデルインスタンスを使い、通常のモデル保存経路で更新してください。

  ```php
  $staleUser = $user->fresh();
  Assert::isInstanceOf($staleUser, User::class);

  $persistedUser = $staleUser->fresh();
  Assert::isInstanceOf($persistedUser, User::class);

  $persistedUser->email = 'changed@example.com';
  $persistedUser->save();

  // $staleUser は invitee@example.com のまま
  $membership->acceptInvitation($token, $staleUser);
  ```

  実際のコードでは既存の email更新Serviceがあるなら、それを優先してください。

- [Critical] T4bはServiceを直接呼ぶ設計なのに、結果として「dashboard + error flash」を期待しています。直接呼び出しではControllerを通らないため、redirectもflashも発生しません。一方、HTTP経由では認証ユーザーがDBから再解決され、意図したstaleモデルをServiceへ渡せません。

  修正案: T4bはService直接呼び出しのFeatureテストとして、`ValidationException` とDB状態不変を確認してください。HTTPのredirect／flashはT4で別途検証済みです。

  ```php
  try {
      $membership->acceptInvitation($token, $staleUser);
      test()->fail('ValidationException が必要');
  } catch (ValidationException $e) {
      expect($e->errors())->toHaveKey('token');
  }
  ```

  あわせて、早期照合を本当に通過したことを示すため、呼び出し直前に次を確認します。

  ```php
  expect($invitation->isAddressedTo($staleUser))->toBeTrue();
  ```

  そのうえで保存済みの別インスタンスが不一致であることも確認すれば、失敗理由を分離できます。

- [Warning] T4bの一括更新案は新たな `User.whereKey` 検出や DirectFetchInventory 登録を必要とする可能性もあります。

  修正案: 上記の `fresh()` で別インスタンスを得る方法へ一本化し、T4bの一括更新案を削除してください。

T2、T3、T4、T5、T1、T6の残りの計画は妥当です。

## 施策 6: 除名／未割当テスト

判定: APPROVE

状態ごとの期待値が確定され、層2の404と層3の403を明確に区別できています。自然除名、stale current-org、未割当の3状態も適切に分離されています。

使い捨てテストで得た期待値を正式な回帰テストへ移し、使い捨て側を残さないことだけ確認してください。

## 施策 7: optionラベル注記

判定: APPROVE

非disabled、サーバ側権威、事前説明という設計は妥当です。禁止事項8の適用判断も正しいです。

## 施策 8: Svelte／Featureテスト

判定: APPROVE

302 redirect、session error、DB不変へ修正され、Inertiaの通常契約と整合しました。`page.props.errors.role` と対象行の結び付けも具体化されています。

## 承認に必要な最終修正

T4bを次の契約へ一本化すれば承認できます。

1. CipherSweetを通る別モデルインスタンスで保存emailを変更する
2. staleインスタンスが早期predicateを通ることを明示assertする
3. Serviceを直接呼び、`ValidationException` を確認する
4. redirect／flashは期待しない
5. membership、role、project、`accepted_at`、current organizationの不変をDBで確認する

この修正以外に、承認を妨げる実装設計上の問題はありません。