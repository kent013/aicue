# T134 gate mutation ログ (InvitationResolutionInventoryTest + 戻り値消費トークナイザ検査)

対象: `tests/Architecture/InvitationResolutionInventoryTest.php` (施策 8) /
`tests/Architecture/MembershipWriteLockInventoryTest.php` の
「joinOrganization() の戻り値を破棄している呼び出しが無い」検査 (施策 3)。

**素の実装では常に green の gate であるため、mutation を当てて赤化を確認した**。
入れた mutation はすべて元に戻し、`git diff` で残っていないことを確認済み。

---

## (a) 初回の抽出結果 (クエリ起点の全リスト。パス#メソッド名)

セレクタ (`invitationResolutionSelectors()` / モデルファイル限定の
`invitationResolutionModelSelectors()`) で抽出した **12 件**。

| # | 目録キー | 分類 |
|---|---|---|
| 1 | `Http/Controllers/Admin/UserManagementController.php#index` | OrganizationScoped |
| 2 | `Http/Controllers/Organizations/InvitationAcceptanceController.php#show` | TokenHashLookup |
| 3 | `Models/OrganizationInvitation.php#findActiveByPlainToken` | ModelInternal |
| 4 | `Models/OrganizationInvitation.php#scopeActive` | ModelInternal |
| 5 | `Models/OrganizationInvitation.php#scopeActivePendingForEmail` | ModelInternal |
| 6 | `Rules/MatchesInvitationEmail.php#validate` | TokenHashLookup |
| 7 | `Services/Organization/OrganizationMembershipService.php#acceptInvitation` | TokenHashLookup |
| 8 | `Services/Organization/OrganizationMembershipService.php#acceptInvitationIfValid` | TokenHashLookup |
| 9 | `Services/Organization/OrganizationMembershipService.php#hasPendingInvitation` | OrganizationScoped |
| 10 | `Services/Organization/OrganizationMembershipService.php#joinOrganization` | LockedRowReload |
| 11 | `Services/Organization/OrganizationMembershipService.php#pendingInvitationsQuery` | **RecipientScopedPendingSet** |
| 12 | `Services/Organization/OrganizationMembershipService.php#resolveRegisterPrefillEmail` | TokenHashLookup |

> **実装前 (施策 1・3 の本体が無い状態) の実測は 10 件**で、その時点では
> floor (12) 下回り + stale 2 件で **fail** することを確認している (テストファースト)。

## (b) `invitationResolutionSiteFloor()` の確定値

**12** (実測ちょうど)。

## (c) `RecipientScopedPendingSet` の exact-fit cap

**1** (`pendingInvitationsQuery` のみ)。

> **設計との差分**: 設計の enum は 4 case だったが、抽出結果に
> `joinOrganization` の「既に解決済みの招待を主キーでロック下再取得する」経路が現れ、
> 4 分類のどれにも意味的に収まらなかったため 5 番目の case
> `LockedRowReload` を追加した (詳細は実装報告の deviations)。

---

## (d) mutation M1〜M7 の赤化結果

いずれも `composer test -- tests/Architecture/InvitationResolutionInventoryTest.php` で確認。

| # | mutation | 結果 | 赤化したテスト |
|---|---|---|---|
| M1 | `AcceptInvitationInAppController::__invoke` に `OrganizationInvitation::query()->whereKey($invitation)->first();` を一時的に足す | **FAILED (期待どおり)** | 「クエリ起点はすべて目録へ分類登録されている (未登録は fail)」 |
| M2 | `pendingInvitationsQuery()` の本文を `activePendingForEmail(...)` から `->active()->whereBlind(...)` の手書きへ置換 | **FAILED (期待どおり)** | 「受信者視点に分類した箇所は scopeActivePendingForEmail を再利用している」 |
| M3 | 目録から `pendingInvitationsQuery` の行を削除 | **FAILED (期待どおり)** | 未登録 fail + exact-fit cap + 各 case の実体 (3 本) |
| M4 | 目録に実在しないキー (`Services/Foo.php#bar`) を足す | **FAILED (期待どおり)** | 「目録に実在しないクエリ起点が残っていない (stale 検出)」 |
| M5 | 理由文を `'短い'` に置換 | **FAILED (期待どおり)** | 「目録の理由は 30 文字以上で分類は enum である」 |
| M6 | `invitationResolutionSiteFloor()` を実測 +1 (13) に上げる | **FAILED (期待どおり)** | 「抽出件数が floor を下回らない (セレクタ空振りの検出)」 |
| M7 | `RecipientScopedPendingSet` 分類の 2 件目を目録に足す (実在サイト `joinOrganization` を再分類) | **FAILED (期待どおり)** | exact-fit cap + 受信者視点の本文検査 + 各 case の実体 |

## (e) 戻り値消費トークナイザ検査の負のコントロール

`acceptPendingInvitation` 内の呼び出しを
`$this->joinOrganization(...);` (戻り値破棄) へ一時的に書き換えて実行:

```
composer test -- tests/Architecture/MembershipWriteLockInventoryTest.php
→ FAILED: joinOrganization() の戻り値 (false = ロック下再検証で受諾不能) を
   破棄している呼び出しがあります。line 368
```

期待どおり赤化した。書き換えは元に戻し済み。

## (f) LockedRowReload の構造検査 (impl-review Round 1 [Warning] の対応で追加)

`LockedRowReload` は「新しい到達経路を作らない」ことを根拠に存在秘匿の視点を免除される
分類であり、放置すると**外部入力 id の直引きの逃げ道**になる。そこで免除の根拠そのもの
(a) ロック下であること (b) 主キーが解決済みモデル由来であること を機械検証する検査
「ロック下再読取に分類した箇所は『解決済みモデルの主キー + lockForUpdate』の形をしている」
を追加し、2 通の mutation で赤化を確認した。

| # | mutation | 結果 | 赤化したテスト |
|---|---|---|---|
| M8a | `joinOrganization` の `whereKey($invitation->id)` を `whereKey((string) $invitation->id)` へ (= 未解決の外部入力 id を渡す形の模擬) | **FAILED (期待どおり)** | 「主キーが解決済みモデル由来 ($model->id) の whereKey になっていない」 |
| M8b | 同箇所から `->lockForUpdate()` を削除 | **FAILED (期待どおり)** | 「lockForUpdate() が無い = ロック下の再検証になっていない」 |

いずれも元に戻し、`composer test -- tests/Architecture/InvitationResolutionInventoryTest.php`
が 8 tests passed になることを確認済み。

---

## 後始末の確認

- mutation 適用対象ファイル (`AcceptInvitationInAppController.php` /
  `OrganizationMembershipService.php` / `InvitationResolutionInventoryTest.php`) は
  ドライバが必ず元の内容へ復元する構造 (try/finally) にしてある
- 実行後に `git diff` / `git status` で mutation の残留が無いことを確認した
