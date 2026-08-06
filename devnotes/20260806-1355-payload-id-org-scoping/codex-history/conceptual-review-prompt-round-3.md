Round 2 の指摘に対する対応マトリクスと、修正差分を提示します。再レビューし全体判定を出してください。

## 実測ログ (PHP 8.4, 本リポジトリの実行環境)

php -r 'foreach (["001"," 1","1 ","0","-1","1.5","1e5","+1","07"] as $v) { var_dump($v, filter_var($v, FILTER_VALIDATE_INT, ["options"=>["min_range"=>1]])); }'

"001" => false / " 1" => int(1) / "1 " => int(1) / "0" => false / "-1" => false
"1.5" => false / "1e5" => false / "+1" => int(1) / "07" => false

# 対応マトリクス: conceptual-review Round 2

## [Critical] `'001'` が FILTER_VALIDATE_INT を通過する前提が誤り (観点 3)
- 判断: **対応する (指摘が正しい)**
- 根拠: PHP 8.4 で実測した結果、`filter_var('001', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]])`
  は **false**。逆に `'1 '` / `' 1'` / `'+1'` は **int(1) として受理**される。
  設計書の記述が実挙動と逆だった。`sprintf('%03d', $id)` が id >= 100 でゼロ埋めにならない
  という指摘も正しい。
- 対応内容:
  - §4-3 に **実測表**を追加し、`'001'` / `'07'` を 422 側へ移動。
  - 先頭ゼロ受理の要件は無いので正規化は入れない (今必要ないものを作らない) と明記。
  - §7-1 の境界値テストを「`'001'` → 422」「member 組織 id の前後空白付き文字列 → 通過」に差し替え
    (ゼロ埋めではなく空白で「受理される表記ゆれ」を固定する)。
- 副産物: **実コードのコメントが誤っている**ことが判明した
  (`McpConsentOrganizationBinder` の「`"1 "` を reject」)。挙動は変えずコメントを訂正する
  施策を §4-5 に追加した。

## [Warning] MCP の形式境界が不正確なので 422/403 契約を承認できない (観点 8)
- 判断: 対応する (上記 Critical と同一。実測表で同期済み)

## [Warning] 検証コマンドが AGENTS.md の必須セットを満たしていない (観点 2)
- 判断: **対応する**
- 根拠: AGENTS.md は `VERIFICATION_COMMANDS` マーカーで正本を持ち、
  `verification-commands-doc-sync.test.ts` が package.json と同期を強制している。
  設計書に部分列挙を書くと二重管理になる。
- 対応内容: §7-2 を「開発中の絞り込み実行」と「完了条件 = AGENTS.md の
  VERIFICATION_COMMANDS 全部が green」に分け、写経をやめた。

## [Suggestion] 使命 / 効果 / スコープ / 型安全性 (Round 1 の対応を評価)
- 判断: 見送る (肯定的評価。PHPStan level 10 を完了条件から外さない点は §7-2 で担保)

## 修正後の該当セクション (§4-3 / §4-5 / §7-1 / §7-2)

### 4-3. 施策 C: `McpConsentOrganizationBinder::handle`

- `Organization::query()->find($orgId)` を **削除**する (結果を使っていない)。
- 既存の `$user->organizations()->whereKey($orgId)->exists()` 1 本に集約し、
  false なら **403 一択** (不在 id も非メンバー組織も同じ 403・同じ文言)。
- `filter_var` による形式検証 422 は据え置き (形式不正は存在情報を含まないため
  統一の必要がない)。
- **入力分類の境界を明文化する** (将来ここを触ったときに新しい判定差を生まないため):

  | 入力 | 判定 | 応答 |
  |---|---|---|
  | 欠落 / 空文字 | 非 MCP フロー | 素通し (attribute を set しない) |
  | bool | 形式不正 | 422 `Invalid organization_id.` |
  | `filter_var(FILTER_VALIDATE_INT, min_range=1)` が false | 形式不正 | 422 `Invalid organization_id.` |
  | 整数として受理された値 | **すべて membership 判定へ流す** | member なら通過 / それ以外は **403 一択** |

  `filter_var` の実挙動を **PHP 8.4 で実測**した (推測で書かない):

  | 入力 | 結果 |
  |---|---|
  | `'1'` / `' 1'` / `'1 '` / `'+1'` | **int(1) = 受理** (前後空白と符号は許容される) |
  | `'001'` / `'07'` | **false = 422** (先頭ゼロは拒否される) |
  | `'0'` / `'-1'` | false = 422 (`min_range => 1`) |
  | `'1.5'` / `'1e5'` / `'abc'` / 配列 | false = 422 |

  先頭ゼロを受理する要件は無い (UI は Blade の dropdown で素の id を送る) ので、
  **`'001'` は 422 のまま**とし、正規化は入れない (今必要ないものを作らない)。

  **規約**: 「整数として受理されたものは 1 つ残らず membership 判定に流す」。
  422 側に落ちるのは id として成立しない形式だけであり、**実在情報を一切含まない**
  (存在する id かどうかで分岐しない) ため、403 と統一する必要がない。
- `use App\Models\Organization;` が不要になるので削除する。

### 4-4. 施策 D: gate の債務解消 (完了条件)

- `DirectFetchInventory::inventory()` から債務 3 エントリ (と「★債務」節見出し) を削除。
- `modelDirectFetchDebtCap()` を **3 → 0**、doc コメントを「実測 0 件」に更新。
- `DirectFetchJustification::PayloadIdWithGlobalExistenceRuleDebt` と
  `DirectFetchJustificationEntry::globalExistenceRuleDebt()` は**残す**。
  cap 0 のまま case を残すことで「新しい債務は inventory 登録 + cap 引き上げの
  2 段のレビューを通さないと緑にならない」= deny-by-default が維持される
  (削除すると分類語彙ごと消え、次に同じ形が生えたときの裁定履歴が失われる)。
- 候補総数は 34 → 31 で floor (20) を下回らないことを確認する。

### 4-5. 施策 E: ドキュメント・コメントの同期 (陳腐化させない)

- `ProjectMemberController` のクラス docblock (「同一組織メンバーでなければ 403」) を更新。
- `resources/js/pages/Organizations/Settings.svelte` L124-126 のコメント
  (「最終ゲートはサーバ (Policy + exists:users,id + Service のメンバーシップ検証)」) を更新。
- `tests/Feature/Projects/ProjectMemberTest.php` の冒頭コメント
  (「追加対象 (payload user_id) の cross-org は 403」) を更新。
- **`McpConsentOrganizationBinder` の `filter_var` コメントの誤りを訂正する**:
  現行コメントは「`"1 "` を reject」と書いているが、実測では `'1 '` / `' 1'` / `'+1'` は
  **受理される** (拒否されるのは先頭ゼロ `'001'` の方)。挙動は変えず、
  コメントを実挙動に合わせる (誤った記述が次の実装者の判断材料になるのを防ぐ)。

---

## 5. 代替案と却下理由
### 7-1. 機械検証 (テスト)

**完了の定義**: 新規テスト・既存テストの期待値更新・Architecture テストが
**すべて緑になって初めて完了**とする (AGENTS.md 禁止事項 1)。

1. **新規**: `tests/Feature/Security/PayloadIdExistenceOracleTest.php`
   「実在の非メンバー id」と「不在 id」の応答一致を 3 経路で表明する
   (`MemberRouteExistenceOracleTest` の `mreoAssertNoOracle` と同じ主張形式)。
   表明は 2 段:
   (a) `ResponseSignature::of()` の一致 (status + ヘッダ + body)、
   (b) **`session('errors')->get('user_id')` の文言一致** (302 では body が空なので
   signature だけでは文言差を検出できないため)。
   - transfer-ownership (422 相当の redirect + 同一 `errors.user_id`)
   - projects.members.store (同上)
   - McpConsentOrganizationBinder (403 + 同一メッセージ。middleware 直呼びの単体形)
1-b. **新規 (同ファイル)**: **層 3 の前置固定**。
   - 権限の無い actor (非 Owner) が transfer-ownership に
     実在メンバー / 実在非メンバー / 不在 id を送っても**すべて同一 403**
   - 権限の無い actor (project 更新権限なし) が projects.members.store に
     同 3 パターンを送っても**すべて同一 403**
   これで「Gate が payload 検証より前」が回帰で壊れたら落ちる。
1-c. **新規 (同ファイル or ConsentOrganizationBinderTest)**: MCP binder の
   入力分類境界 (§4-3 の表) を固定する。
   `'0'` / `'-1'` / `'1.5'` / `'001'` (先頭ゼロ) / 配列 / bool → **422**、
   member 組織 id の**前後に空白を付けた文字列** (`' '.$id`) → 通過して attribute に int が入る
   (`filter_var` が空白を許容する実挙動の固定)、
   実在非 member / 不在 → **同一 403**。
2. **更新**: `tests/Feature/Projects/ProjectMemberTest.php` の
   「他組織のユーザーは追加できない (cross-org は 403)」→ 422 + `errors.user_id` へ期待値変更
   (テストの削除ではない)。冒頭コメントも更新。
3. **更新**: `tests/Feature/Mcp/ConsentOrganizationBinderTest.php` の
   「存在しない organization_id は 422」→ 403 へ期待値変更。
4. **不変**: `tests/Feature/Organization/OwnershipTransferTest.php` は
   `assertSessionHasErrors('user_id')` のままで緑 (メッセージ統一のみのため)。
   ただし「非メンバーへは移譲できない」に**メッセージ一致**の表明を足す。
5. `ModelDirectFetchInvariantTest` が cap 0 で緑 (stale 検出 / floor / 双方向整合を含む)。

### 7-2. コマンド

開発中の絞り込み実行:

- `php artisan test tests/Feature/Security/PayloadIdExistenceOracleTest.php`
- `php artisan test tests/Architecture/ModelDirectFetchInvariantTest.php`
- `php artisan test tests/Feature/Projects/ProjectMemberTest.php tests/Feature/Organization/OwnershipTransferTest.php tests/Feature/Mcp/ConsentOrganizationBinderTest.php`

**完了条件は AGENTS.md の `VERIFICATION_COMMANDS` マーカー間に列挙された全コマンドが green**
(ここに写経して二重管理しない。`composer test` はグローバルロック配下で待つのが正常)。
本件はバックエンドのみの変更だが、フロントの検証コマンドも省略しない
(`resources/js` のコメント 1 箇所を触るため)。

### 7-3. 手動確認 (UI 非後退)
