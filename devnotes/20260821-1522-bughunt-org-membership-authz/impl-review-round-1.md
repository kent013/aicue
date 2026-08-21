## 主要指摘

[Warning] [InvitationAcceptanceController.php](/workspace/.claude/worktrees/tasks/T237/app/Http/Controllers/Organizations/InvitationAcceptanceController.php)

`recipientEmailMatches=false` でも `organizationName` を Inertia prop として送信しています。Svelte が DOM に描画しなくても、初期 Inertia payload やブラウザの開発者ツールから組織名を取得できます。

したがって、コードコメントとテスト名の「非受信者に組織名を出さない」は現在成立していません。例えば不一致時は `organizationName: null` とし、型も `string | null` にする必要があります。

```php
'organizationName' => $recipientEmailMatches ? $organization->name : null,
```

この情報開示上の欠落により、全体判定は `CHANGES_REQUESTED` です。

## ファイル別判定

- [InvitationAcceptanceController.php](/workspace/.claude/worktrees/tasks/T237/app/Http/Controllers/Organizations/InvitationAcceptanceController.php)
  - [Warning] 上記の Inertia payload 経由の組織名開示。
  - `Request::user()` に対する `Assert::isInstanceOf` は、guest 分岐後の PHPStan narrow として妥当です。
  - 独自 email 比較を持たず domain predicate を使用している点は設計どおりです。

- [OrganizationInvitation.php](/workspace/.claude/worktrees/tasks/T237/app/Models/OrganizationInvitation.php)
  - 判定: OK。
  - `Assert::string` は CipherSweet 復号属性の `mixed` を実行時検査しつつ narrow しており、widen ではありません。
  - `===` による大小区別・非正規化の比較も詳細設計と一致しています。

- [MatchesInvitationEmail.php](/workspace/.claude/worktrees/tasks/T237/app/Rules/MatchesInvitationEmail.php)
  - 判定: OK。
  - 比較規則が `isAddressedToEmail()` に集約されています。

- [OrganizationMembershipService.php](/workspace/.claude/worktrees/tasks/T237/app/Services/Organization/OrganizationMembershipService.php)
  - 判定: OK。
  - 早期照合は副作用前、最終照合はトランザクション・ロック下にあり、役割分担が明確です。
  - ロック読みした最新 `User` と、ロック読みした招待を比較しているため、stale model による TOCTOU を封じています。
  - 不一致時は membership、role、project pivot、招待受諾状態の更新前に `false` へ畳まれます。

  DirectFetchInventory を追加しなかった判断も、この実装については正当です。重要なのは「型付き引数だから安全」だけではなく、次の条件です。

  - キーは payload ではなく、既に解決された `$user` モデル自身から取得している。
  - `joinOrganization()` は private で、User を任意の route ID から解決する入口ではない。
  - クエリは権限範囲を拡張せず、同一 actor 行のロック付き再取得に限定される。
  - User は organization 配下の子リソースを主キーで探索しているのではない。

  したがって scanner の候補外であり、存在しない候補を inventory に登録すると stale 裁定になる点も申告どおりです。ただし、型宣言そのものが認可を証明するわけではないため、将来このメソッドの可視性や User の取得元が変われば再評価が必要です。

- [Admin/Users.svelte](/workspace/.claude/worktrees/tasks/T237/resources/js/pages/Admin/Users.svelte)
  - 判定: OK。
  - option は disabled にされておらず、禁止事項8を遵守しています。
  - `$derived` により `hasDefaultProject` の変化にも追随します。
  - DS token、SVG、Atomic Design に関する新規違反はありません。

- [Invitations/Accept.svelte](/workspace/.claude/worktrees/tasks/T237/resources/js/pages/Invitations/Accept.svelte)
  - [Warning] DOM 上では組織名を隠していますが、親から既に `organizationName` を受け取っているため、セキュリティ上の非開示にはなっていません。Controller 修正に合わせて `string | null` を扱う必要があります。
  - 受諾ボタンを disabled にする実装ではなく、不一致案内へ分岐している点は設計どおりです。

- [InvitationResolutionInventoryTest.php](/workspace/.claude/worktrees/tasks/T237/tests/Architecture/InvitationResolutionInventoryTest.php)
  - 判定: OK。
  - token 解決とロック下 email 再照合の説明更新は実装と一致しています。

- [ConsoleRoleTransitionTest.php](/workspace/.claude/worktrees/tasks/T237/tests/Feature/Organization/ConsoleRoleTransitionTest.php)
  - 判定: OK。
  - サーバ側が権威であること、redirect-back、role error、副作用なしを検証しています。

- [InvitationTest.php](/workspace/.claude/worktrees/tasks/T237/tests/Feature/Organization/InvitationTest.php)
  - [Warning] T3 の名称は「組織名を出さない」ですが、実際には `recipientEmailMatches=false` しか確認していません。現在の漏えいを検出できないテストです。
  - Controller 修正後、少なくとも `organizationName === null` を Inertia assertion に追加してください。
  - 早期拒否、直接POST、副作用ゼロ、stale User、大小区別、成功回帰の網羅性は良好です。
  - T4b は実並行テストではありませんが、「古いインスタンスで早期照合を通過し、DB最新値で拒否する」ことを証明しており、静的なロック inventory と合わせれば施策1の検証として妥当です。

- [MemberRemovalAccessTest.php](/workspace/.claude/worktrees/tasks/T237/tests/Feature/Organization/MemberRemovalAccessTest.php)
  - 判定: OK。
  - membership、Laratrust role、project pivot、current organization の除去と、404/403の層分けを直接検証しています。
  - [Suggestion] ファイル冒頭の表は自然除名・stale状態の dashboard=200 も契約として記載していますが、その2ケースは実際には assertion されていません。表を検証済み範囲に狭めるか、dashboard の期待値を追加すると説明とテストが一致します。

- [OrganizationAccessRevocationTest.php](/workspace/.claude/worktrees/tasks/T237/tests/Feature/Organizations/OrganizationAccessRevocationTest.php)
  - 判定: OK。既存テスト fixture の email 更新は仕様変更に必要な正当な修正です。

- [AdminUsers.test.ts](/workspace/.claude/worktrees/tasks/T237/tests/js/pages/AdminUsers.test.ts)
  - 判定: OK。
  - false/true 両側のラベルと、select/option が操作可能であることを検証しています。

- [InvitationsAccept.test.ts](/workspace/.claude/worktrees/tasks/T237/tests/js/pages/InvitationsAccept.test.ts)
  - [Suggestion] DOM 非表示のテストとしては正しい一方、「非受信者への開示」を保証するテストではありません。Inertia payload の機密性は Feature テスト側で保証してください。

## 施策0〜8

施策0、1、4、6、7、8は実装・テストとも一致しています。施策2、3、5は表示分岐自体は実装されていますが、不一致利用者にも `organizationName` がクライアントへ渡るため、非開示要件とその回帰テストが未完了です。

禁止されている `response()->json()`、disabled 化、hex/SVG直書き、PHPStanの型 widen は、この差分にはありません。

**全体判定: CHANGES_REQUESTED**