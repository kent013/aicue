**`app/Http/Controllers/Settings/AccountController.php`**
- [Warning] `destroy()` で `OrganizationMembershipService` に責務移譲し、`deleteAccount(..., beforeDelete)` で `logout` を削除直前へ寄せた判断は、提示された FK/トランザクション破綻再現に対して妥当です。
- [Suggestion] `beforeDelete` が認証層副作用を持つため、将来の誤用防止として `@param` 説明に「例外を投げないこと（投げると削除全体 rollback）」を明記すると安全です。

**`app/Http/Controllers/Settings/ProfileController.php`**
- [Warning] Inertia props をプレーン配列で返しており規約適合です。`soleOwnedOrganizations` は snapshot で、最終判定を削除時再評価する設計説明も一貫しています。
- [Suggestion] `slug` は UI 導線専用なので、将来 API 併用時は Resource 化対象かどうかを明文化しておくと境界がぶれにくいです（現状は問題なし）。

**`app/Services/Organization/OrganizationMembershipService.php`**
- [Critical] `deleteAccount()` 内で `organizationsBlockingDeletion($freshUser)` を呼ぶ実装は、内部で `hasAnotherOwner()` が別クエリを撃つため、ロック粒度が「users/org 行」に留まる点は要注意です。`owner 判定` が参照する laratrust 関連テーブル側に同時更新が入り得るなら、厳密には同一 tx での追加ロック（または判定クエリの `FOR UPDATE` 戦略統一）が必要です。現状コメントは「直列化済み」を強く主張しているため、実際のロック到達範囲とズレる可能性があります。
- [Warning] `lockForMembershipWrite()` の canonical 順序（users→organizations）導入は deadlock 回避として非常に良いです。`joinOrganization/applyConsoleRole/changeRole/removeMember/transferOwnership/deleteAccount` で揃えているのも一貫。
- [Warning] `keyOf(Model): int` による mixed narrowing は PHPStan L10 対応として適切です。
- [Suggestion] `organizationsBlockingDeletion()` は filter 内で `organizationRole()` + `hasAnotherOwner()` により N+1 化しやすいので、将来組織数増加時のためにクエリ集約版を検討余地あり（現状スコープでは許容）。

**`resources/js/pages/Settings/Index.svelte`**
- [Warning] UI で disabled にせず、押下時にサーバーエラー表示へ落とす設計は AGENTS.md 禁止事項 8 に適合しています。
- [Warning] `errors.account` の `string | string[]` 正規化、`onError` でダイアログを閉じて Danger Alert 露出、`preserveScroll` は UX 的に妥当です。
- [Suggestion] `initialUser` だけ旧キャスト `(page.props as unknown as SharedProps)` が残っているため、`props` へ統一すると可読性が上がります（不具合ではない）。

**`routes/web.php`**
- [Warning] `/settings` を `ProfileController@index` へ置換し、ページ props 供給点を明確化したのは適切です。

**`tests/Architecture/MembershipWriteLockInventoryTest.php`**
- [Warning] inventory + drift-guard 追加は非常に有効で、退行検知として価値が高いです。
- [Suggestion] 文字列検索ベース（`str_contains`）はリファクタ時に偽陰性/偽陽性があり得るため、長期的には AST ベース検査への移行余地があります。

**`tests/Feature/Auth/AccountDeletionTest.php`**
- [Warning] 正常系・異常系・回帰（ブロック→再評価）まで押さえており網羅性は高いです。
- [Critical] 並行前提の要件（TOCTOU/ロック順序）に対する実証テストが不足しています。今回の核心は「同時実行下でも唯一 Owner ガードが破れないこと」なので、最低でも並行シナリオ（削除とロール変更/招待受諾競合）の統合テストを1本は追加したいです。

**`tests/Feature/Settings/ProfileSettingsPropsTest.php`**
- [Warning] `soleOwnedOrganizations` の有無を最小ケースで検証しており妥当です。

**`tests/js/pages/SettingsIndex.test.ts`**
- [Warning] 要件（警告表示、errors.account の型差、同時表示、disabled 不使用）を過不足なくカバーしています。
- [Suggestion] `router.delete` 呼び出し時の `onError` 動作まで検証すると、実装意図（ダイアログを閉じる）をより固定できます。

**全体判定**
**CHANGES_REQUESTED**

主理由は2点です。  
1) サービス層の「直列化保証」主張に対し、owner 判定が参照する実テーブルのロック到達範囲がコード上で明示し切れていない点。  
2) 並行性が本件の本丸にもかかわらず、並行実証テストが不足している点。  

それ以外（設計意図、PHPStan 対応、UI 方針、通常系/回帰系テスト）は全体的に良い実装です。