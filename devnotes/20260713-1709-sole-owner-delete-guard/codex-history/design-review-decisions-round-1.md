# 対応マトリクス: design-review Round 1

## 施策2 [Critical] `fresh() ?? $user` の null フォールバックが想定外状態を飲み込む
- 判断: 対応する
- 対応内容: `$freshUser = $user->fresh(); Assert::isInstanceOf($freshUser, User::class);` で即中断。フォールバック禁止。

## 施策2 [Warning] `pluck(...)->all()` の `list<int>` 保証が弱い
- 判断: 対応する
- 対応内容: `->pluck('organizations.id')->map(fn ($id): int => (int) $id)->values()->all()` で明示 `list<int>` 化。

## 施策3 [Critical] changeRole/removeMember が「事前チェック→transaction」で TOCTOU 残存
- 判断: 対応する
- 対応内容: 事前チェックを廃し、**transaction 内先頭で `lockForMembershipWrite` → `fresh()` 再取得 → 判定 → 変更**へ全面統一。changeRole/removeMember を再構成（既存の非メンバー/最終Owner/Owner削除拒否の契約は不変、評価位置のみロック下へ移動）。

## 施策3 [Warning] transferOwnership の `$organization` stale 可能性
- 判断: 対応する
- 対応内容: ロック後に `Organization::query()->whereKey($organization->id)->firstOrFail()` と `User::query()->whereKey(...)->firstOrFail()` で最新インスタンスを再取得し、同一 tx 内の最新同士で判定。

## 施策4 [Suggestion] 例外 key 'account' をテスト名に明記
- 判断: 対応する（テスト名に反映）

## 施策5 [Warning] routes/web.php クロージャ肥大化 → controller へ
- 判断: 対応する
- 対応内容: `GET /settings` を新規 `app/Http/Controllers/Settings/ProfileController@index` へ移し DI/型安全を明確化。施策一覧・波及に反映。

## 施策6 [Critical] `(page.props as unknown as ...)` 多重キャストが型安全を崩す
- 判断: 対応する
- 対応内容: ページ専用 `PageProps` 型を定義し `const props = page.props as PageProps` を1箇所化。

## 施策6 [Warning] errors.account が配列で来るケース未考慮
- 判断: 対応する
- 対応内容: `Array.isArray(err) ? err[0] : err` で表示文字列へ正規化するヘルパを設計に明記。

## 施策6 [Warning] 押下後のダイアログ挙動 (閉じる/残る) を明確化
- 判断: 対応する
- 対応内容: `router.delete` の `onError` で `deleteDialogOpen = false` にしエラー Alert を DangerZone に表示。JS テストで固定。

## 施策7 [Critical] drift-guard が「未分類検出」のみで lock 呼び出しを保証しない
- 判断: 対応する
- 対応内容: inventory に加え、`ReflectionMethod` でメソッドソースを取得し **direct-lock 群は `lockForMembershipWrite(` を含む**、**delegated 群は locked 内部メソッド(`joinOrganization`)呼び出しを含む**ことを文字列検査する。

## 施策7 [Warning] acceptInvitation* の間接依存分類が壊れやすい
- 判断: 対応する
- 対応内容: `directLock` / `delegatedToLocked` / `exempt` の 3 配列に分離し意図を明文化。

## 施策8 [Critical] ロック後再評価を通る回帰テスト不足
- 判断: 対応する
- 対応内容: 実 DB 状態遷移テストを追加: (a) 唯一Owner+メンバー→ブロック→service で 2 人目 Owner 追加→削除成功、(b) 2 Owner→`changeRole` で片方降格→唯一Owner+メンバーで削除ブロック。deleteAccount が現在 DB 状態で判定することを検証。

## 施策8 [Warning] 複数オーナーテストの addRole 直叩き
- 判断: 対応する
- 対応内容: 既存ヘルパ `attachOrganizationMember($org, OrganizationRole::Owner)` で 2 人目 Owner を生成（addRole 直叩きを排除。owner を増やすドメイン正規経路は存在しないため、既存の attach ヘルパ経路に統一）。

## 施策8 [Suggestion] JS: toBeEnabled + warning/error 同時表示
- 判断: 対応する（テストに追加）

## 施策1 / 施策5 [Suggestion] owner 集約でのクエリ最適化
- 判断: 見送る（現状の所属組織数規模で許容。過度な最適化はしない）
