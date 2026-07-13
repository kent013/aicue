了解しました。提示された**差分テキストのみ**を対象に、T028 を設計 S1〜S4 とレビュー観点で監査します。

**`app/Http/Controllers/Projects/ProjectController.php`**
- [Suggestion] `show()` で `memberRows` を一度算出して `members` と `assignableUsers` に再利用する構造は妥当です（S1一致、重複計算回避）。
- [Suggestion] `assignableUserRows()` の `canManage=false => []` は PII 最小開示の意図に整合しています（設計どおり）。
- [Suggestion] `@param`/`@return` shape と `list<int> $memberIds` 注釈は PHPStan L10 向けに適切です。widen の痕跡は見当たりません。
- [Warning] `assignableUsers` のゲートを `canManage` に統一する方針は設計に沿っていますが、将来 `memberRows` の shape 変更時に `array_column(..., 'id')` が silently 崩れるリスクがあるため、契約テスト（S3）依存が強い点は注意（現状は問題なし）。
- [Suggestion] `response()->json()` 直書き追加なし、Inertia props のみで禁止事項4に適合。

**`resources/js/pages/Projects/Show.svelte`**
- [Suggestion] `Props` へ `members/canViewMemberEmails/assignableUsers` を追加し、UI表示と型を一致させた点は S2 と整合。
- [Suggestion] 追加フォームで未選択時に `setError` し、`disabled` に依存しない実装は禁止事項8に適合。
- [Suggestion] `memberForm.processing` / `changingRoleId` / `removingMember` による二重送信ガードは妥当です。
- [Critical] `changeMemberRole` は送信中ガードが**グローバル1件**（`changingRoleId !== null`）のため、別行を素早く連続変更した場合に後続操作が無視されます。仕様として許容なら問題ないですが、UX的には「入力を受け付けたように見えて反映されない」可能性があり、誤解を招きます（行単位ロック or トースト通知がない）。  
- [Suggestion] Atomic/DS 観点は良好です。既存 atom/molecule/organism のみ使用、hex 直書きなし、Lucide/SVG追加なし。

**`tests/Feature/Projects/ProjectShowMemberManagementTest.php`**
- [Suggestion] S3 要件（shape、明示/暗黙除外、他組織除外、canManage=false時空配列、email null）を概ね満たしています。
- [Suggestion] `assignableRows()` ヘルパ分離で可読性が高く、契約意図が明確です。
- [Warning] `where('assignableUsers', fn (...) => assignableRows($rows) === [])` は strict 比較で明快ですが、Inertia 側の表現差異（Collection/array）に helper で吸収している前提。現状は問題ないものの、将来 serializer 変更時に brittle になりやすいです（許容範囲）。
- [Suggestion] store/destroy を既存テストに委譲して重複回避している点は適切。

**`tests/js/pages/ProjectsShow.test.ts`**
- [Suggestion] S4 の操作契約（描画、追加、未選択エラー、ロール変更、削除確認、email非表示、候補なし案内、暗黙メンバー表示）を十分にカバーしています。
- [Suggestion] `router.post/delete` spy + `vi.restoreAllMocks()` の運用は妥当です。
- [Warning] 「ロール変更時の二重送信ガード」を直接検証するテストがありません。`changingRoleId` の退行検知を入れると堅牢性が上がります。
- [Suggestion] `canManage=false` で UI 全体非表示を明示検証しており、権限ゲート回帰に有効です。

**総評**
- 設計一致性: S1〜S4 はほぼ実装どおり。
- 正確性: 大筋良好。  
- セキュリティ: cross-org/PII ゲートは意図どおり。  
- 規約準拠: `response()->json()` 直書きなし、Atomic/DS 準拠、禁止事項8への配慮あり。  
- 懸念は主に UX/操作競合の扱い（`changeMemberRole` の単一グローバル送信ロック）。

**判定: CHANGES_REQUESTED**  
（理由: `changeMemberRole` の送信中挙動がユーザー操作を無言で捨てうるため、少なくとも挙動を明示するUIフィードバックか行単位制御を追加し、対応テストを1件補強したい）