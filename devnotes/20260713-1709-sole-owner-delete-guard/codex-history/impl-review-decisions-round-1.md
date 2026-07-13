# 対応マトリクス: impl-review Round 1

## [Critical] サービス層の「直列化保証」主張とロック到達範囲のズレ (OrganizationMembershipService)
- 判断: 一部対応する + 反論する (エンフォースを追加)
- 根拠: owner 判定は `role_user` を読むが、直列化は「organizations 行ロックを owner 変更の共通 mutex にする」設計 (集約ルート行で子テーブル書き込みを直列化する既存パターン。cf. AGENTS.md ドメイン規約1 の VideoManual lockForUpdate)。owner 集合を変える全経路 (changeRole/transferOwnership/removeMember/applyConsoleRole/joinOrganization) は自 tx 冒頭で対象 org 行を `lockForMembershipWrite` する。deleteAccount は自所属組織行を全ロックするため、それらの org の owner 数を変える並行書き込みはブロックされる。role_user を直接ロックしなくても org 行が mutex になる。ただし「この前提が将来も守られる」保証がコードに無かった点は正当な指摘。
- 対応内容:
  1. deleteAccount の docblock に直列化メカニズム (org 行 = owner 変更の共通 mutex) を明記。
  2. **role-grant sole-gateway drift-guard テストを追加** (MembershipWriteLockInventoryTest 2本目)。`->addRole/removeRole/syncRoles(` が OrganizationMembershipService (全経路ロック済み) と OrganizationProvisioningService (新規組織の creator への Owner 付与のみ = 既存組織の owner 集合を変えない bootstrap 例外) 以外に現れないことを app/ 全体で静的強制。未ロック経路の混入 = 直列化の破れを機械検出する。
  - 調査結果: 現状 owner を付与し得るロール書き込みは上記2ファイルのみ (grep 済み)。provisioning は新規 org の creator にのみ Owner を付与し、既存 org の owner 集合は変えない → deleteAccount のガード対象外。

## [Critical] 並行実証テストの不足 (AccountDeletionTest)
- 判断: 反論する (infeasible) + 代替エンフォースで担保
- 根拠: 本アプリのテストは `RefreshDatabase` グローバル + `--parallel` (AGENTS.md / Pest.php)。各テストは単一の共有トランザクション内で走るため、**真に並行な 2 トランザクションで実ロック競合を再現することは構造的に不可能** (別コネクションからのコミット済みデータが共有 tx から見えない)。これは詳細設計レビュー (design-review Round 3 APPROVED) で確定した検証方針でもある: 直列化は「ロック実装 + Architecture drift-guard + fresh 状態での再評価 Feature テスト」で担保する。
- 対応内容:
  1. 直列化の前提を破る変更を検出する sole-gateway テスト (上記) を追加 = 「未ロック経路が入り込めない」ことを恒久保証。
  2. 既存の fresh 状態再評価テスト 2 本 (「ブロック→2人目オーナー追加後は削除できる (現在状態で再評価)」「2オーナー→片方降格後はブロック」) が TOCTOU 防御の observable な証拠 (事前取得値ではなくロック下の現在 DB 状態で判定していること) を固定している。
  3. 施策7 の drift-guard が「新しい mutating メソッドは lockForMembershipWrite を呼ぶ」ことを強制。
  → 実行時タイミング依存の flaky な並行テストを足すより、静的エンフォース + 再評価テストの方が退行検知として確実 (思考原則「仕組みが機能しているかを見よ」)。

## [Warning] canonical ロック順序の一貫性 / keyOf narrowing / UI disabled 不使用 / errors 正規化 / route 置換
- 判断: 対応不要 (肯定的評価)
- 根拠: 設計どおり実装済み。指摘なし。

## [Suggestion] beforeDelete が例外を投げると全 rollback
- 判断: 対応する
- 対応内容: `@param` と docblock に「フックは例外を投げてはならない (投げると削除全体が rollback)」を明記。

## [Suggestion] initialUser の旧キャスト残存
- 判断: 対応する
- 対応内容: `initialUser` を統一した `props` 参照に変更 (多重キャスト排除)。

## [Suggestion] JS で onError 動作 (ダイアログを閉じる) の検証追加
- 判断: 対応する
- 対応内容: recent-auth precheck を fresh でスタブし、削除ボタン→確認→router.delete の onError を取り出して発火 → 確認ダイアログが閉じることを検証するテストを追加 (483 tests)。

## [Suggestion] slug の Resource 化 / N+1 集約 / AST ベース検査
- 判断: 見送る (現状スコープ外・許容)
- 根拠: Inertia props はプレーン配列が既存慣習 (REST 併用時に再検討)。所属組織数は小さく N+1 は実用問題なし (既存 organizationsProp と同水準)。文字列検査は軽量 drift-guard として意図的 (設計 施策7 リスク欄に明記済み)。
