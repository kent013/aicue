## Round 2 レビュー

仮説「S2↔S3 の非空契約が実装・文書・テストの三層で固定されたか」に対し、提示差分を確認しました。問題ありません。

### ファイル別判定

- `app/Providers/FortifyServiceProvider.php`: **OK**
  - `null`・空文字の双方を除外し、PII実在時のみ `no-store` を付与。
  - frontendとの判定基準も一致。
  - Inertia Response経由で、`response()->json()` 直書きなし。

- `app/Services/Organization/OrganizationMembershipService.php`: **OK**
  - 非nullなら非空文字列という戻り契約が明文化されています。
  - 空emailはtoken破棄＋null返却でfail-secure。
  - token_hash照合のみで、平文email検索や列挙面の追加なし。
  - PHPStan上の型緩和・不要castなし。

- `tests/Feature/Auth/RegistrationInvitationPrefillTest.php`: **OK**
  - 空email時のnull返却とsession破棄を直接検証し、S2の契約を固定。
  - 既存のHTTP正常系テストにより、非空email時の`no-store`も担保されています。
  - 報告された全品質ゲートもgreen。

### 指摘分類

- **Critical**: なし
- **Warning**: なし
- **Suggestion**: なし

bearer tokenによる限定的PII開示、列挙面非拡大、readonlyをセキュリティ境界としない設計判断にも整合しています。

## 全体判定

**APPROVED**