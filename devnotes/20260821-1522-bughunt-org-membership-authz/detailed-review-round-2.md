# 全体判定: CHANGES_REQUESTED

Round 1 の指摘はほぼ適切に反映されています。特に predicate の単一化、prop の改名、Svelte テスト必須化、未割当テストの前提修正は妥当です。

残る主な問題は、ロック済みユーザーではなく `$user->fresh()` を最終判定に使っている点です。加えて、HTTPステータスや Inertia validation の期待値が未確定／不正確な箇所があります。

## 施策 0: 招待宛先判定の単一 domain predicate

判定: APPROVE

厳密一致を既存仕様として維持し、インメモリでの宛先判定をモデル predicate に集約する設計は妥当です。PHPStan の narrow を predicate 内へ閉じる判断も適切です。

[Suggestion] `scopeActivePendingForEmail()` は blind index によるDB検索であり、この predicate を直接使えません。「email 同一性規則すべての単一出典」ではなく「復号後のインメモリ宛先比較の単一出典」と表現すると保証範囲が正確です。DB検索との同値性は T5 などで固定してください。

## 施策 1: Service のロック下再照合

判定: REQUEST_CHANGES

- [Critical] `$user->fresh()` は通常の非ロック SELECT であり、先行する `lockForUpdate()` が取得した値そのものとは限りません。MVCC の分離レベルと、同一トランザクション内で consistent-read snapshot がいつ作られたかによっては、ロック読みと通常読みで異なる版を見る可能性があります。「user 行をロック済みだから `fresh()` は committed 権威値」という説明は一般には成立しません。

  修正案: `lockForMembershipWrite()` が実際に `lockForUpdate()` で取得した `User` インスタンスを返し、その `$lockedUser` を最終照合に使用してください。

  ```php
  $lockedUser = $this->lockForMembershipWrite(...);

  // 招待行も既存順序でロック
  $lockedInvitation = ...->lockForUpdate()->firstOrFail();

  if (! $lockedInvitation->isAddressedTo($lockedUser)) {
      return false;
  }
  ```

  現行 helper が `void` なら、ロック順序を変えずに戻り値を `User` または専用のロック結果DTOへ変更し、全呼び出し元を更新します。別途主キー取得を追加する場合は `ModelDirectFetchInvariantTest` の分類も変更対象へ含めてください。

- [Warning] `joinOrganization()` の共通コアに email 照合を入れるため、全呼び出し元が必ず「招待宛先本人だけが参加する」契約であることを目録化する必要があります。現在列挙された3経路以外から呼ばれている場合、意図しない挙動変更になります。

  修正案: 既存 call-site inventory、構造検索、または Architecture テストで `joinOrganization()` の全呼び出し元が3経路だけであることを確認し、設計書に根拠を記載してください。

二段照合自体は妥当です。早期照合を UX、ロック下照合を権威と明確に分離した点も適切です。

## 施策 2: Controller の補助 UX

判定: APPROVE

`recipientEmailMatches` への改名により、既メンバーなどを含む総合的な受諾可否と混同しなくなりました。Controller が共通 predicate を使用し、Service を権威として維持する構造も適切です。

不一致ユーザーへ組織名を表示しない変更は露出を減らす方向であり、問題ありません。

## 施策 3: Accept 画面と Svelte テスト

判定: APPROVE

文言が確定され、一致／不一致の両方向についてDOMテストが必須化されています。組織名を不一致 description に含めない設計も適切です。

不一致者は受諾主体ではないため、明確な理由を表示して受諾フォームを出さないことは、F-2-01 のような入力前提不足による `disabled` とは区別できます。

## 施策 4: 解決経路目録

判定: APPROVE

`TokenHashLookup` と `LockedRowReload` の責務を維持したまま説明を更新する設計は妥当です。施策1を `$lockedUser` 利用へ修正した後、説明中の「ロック下再照合」が実装と一致することを確認してください。

## 施策 5: F-2-02 Feature テスト

判定: REQUEST_CHANGES

- [Critical] T4b の案(a)は、ユーザー email の変更方法によっては早期照合で落ち、ロック下再照合を検証しません。

  修正案: stale model を明示的に作ってください。

  1. `$user` を招待宛先 email でロードしたまま保持する
  2. 別インスタンスまたはDB更新で保存値だけを異なる email に変更する
  3. stale な `$user` を `acceptInvitation()` に渡す
  4. 早期照合は stale 値で通過する
  5. ロック取得で得た `$lockedUser` による最終照合が拒否する
  6. generic な `ValidationException` と、全 pivot／`accepted_at` の不変を確認する

  これにより「単に早期照合が働いた」のではなく、最終照合が fresh なロック値を使ったことを証明できます。

- [Warning] T1 は session 保存と登録結果を名指ししていますが、「register 画面に招待 email が prefill される」保証の所在が明確ではありません。

  修正案: prefill prop または入力値を直接確認する既存テスト名を追加してください。存在しない場合は T1 に assertion を追加します。

- [Warning] T2では正常な token POST が既存の `current_organization_id` を変更しない契約も固定すべきです。

  修正案: 受諾前に別組織を current に設定し、受諾後もその値が維持される assertion を残してください。既存テストが担保するならテスト名を明記します。

T5 の独立 fixture と大小文字差の負例は適切です。

## 施策 6: 除名／未割当 fail-closed

判定: REQUEST_CHANGES

- [Warning] T7/T7b が `403 or 404`、リスク節が「実装時に確定」となっており、詳細設計として期待結果が未確定です。特に本リポジトリはテナント境界404と認可403の順序をセキュリティ不変条件として区別しています。

  修正案: 各 route の middleware／binding／Gate を確認し、次のように route ごとの期待値を確定してください。

  - current organization が null の自然状態
  - stale current organization が除名済み組織を指す状態
  - membership はあるが role がない状態

  `assertStatusIn([403, 404])` のような曖昧なテストにはせず、層2なら404、層3なら403を個別に固定します。

- [Warning] T8 本文は全 route で403としながら、リスク節では projects/billing が異なる可能性を残しており矛盾しています。

  修正案: 現行経路を確認して、T8本文とリスク節を同じ期待値へ統一してください。

membership、current org、role の条件を分離したテスト構造自体は Round 1 より大きく改善されています。

## 施策 7: option ラベル注記

判定: APPROVE

`roleOptions` への改名、非 disabled、既存 Select の維持はいずれも妥当です。DESIGN.md token や Atomic Design の責務にも影響しません。

禁止事項8を根拠とした判断も適切です。

## 施策 8: F-2-01 テスト

判定: REQUEST_CHANGES

- [Warning] Inertia の通常のwebフォーム validation は一般に422レスポンスを画面へ直接返すのではなく、redirect と session errorを経由して Inertia form errors に反映されます。T10 の「422拒否」は route の実際の契約とずれる可能性が高いです。

  修正案: 通常の Inertia request をテストするなら、redirect/back と `assertSessionHasErrors('role')`、role/project pivot 不変を確認してください。JSON endpoint として422を契約化している場合だけ、対応する request headers と JsonResource/DTO 契約を明記して422を期待します。

- [Warning] T9c が `page.props.errors.role` を前提にしていますが、画面が `useForm` 等の `form.errors` を表示している場合は実装経路と一致しません。

  修正案: `Admin/Users.svelte` が実際に参照する error sourceと、複数メンバー行で使用する error bag keyを設計書に確定してください。その同じ経路へエラーを注入して `FormError` を検証します。

- [Warning] 施策一覧の施策8は「施策5と同ファイル」と記載されていますが、本文では `ConsoleRoleTransitionTest.php` へ追記するとされています。

  修正案: 施策一覧を `tests/Feature/Organization/ConsoleRoleTransitionTest.php` に訂正してください。

## 最終所見

Round 1 の設計上の主要問題は解消方向にあります。承認までに必要なのは次の3点です。

1. `$user->fresh()` ではなく、実際にロック読みした `$lockedUser` を最終照合へ使う  
2. T4b を stale model と保存済み最新値の差を検出するテストとして確定する  
3. 403／404と Inertia validation の期待値を現行route契約に合わせて確定する  

これらを反映すれば、全体として APPROVED にできる設計です。