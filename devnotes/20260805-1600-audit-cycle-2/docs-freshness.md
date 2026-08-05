# 多角監査 サイクル 2 — ドキュメント鮮度 (Documentation Freshness)

対象: `4cbdff8..HEAD` (T099〜T106 の 8 TODO、382 files changed)
実施日: 2026-08-05 / 検出のみ (コード・ドキュメントは一切変更していない)

### ドキュメント鮮度: STALE_FOUND

今サイクルのドキュメント更新は**全体として非常に高品質**である。実装と同一 PR で
`docs/architecture.md` / `docs/app-integration-guide.md` / `docs/auth-security-mechanisms.md` /
`docs/supported-browsers.md` / `docs/template-divergence.md` / `docs/testing-browser.md` /
`docs/worktree-isolation-strategy.md` / `scripts/README.md` / `docs/supply-chain/review-checklist.md`
が更新されており、指示で挙げられた「陳腐化した記述」(PostgreSQL 不在 / composer test 非実行 /
Codex 旧モデル / passkey 不在) は **1 件も残っていない**。

一方で **7 件の乖離**を検出した。うち **3 件は重要 (即座に修正)**。
そのうち 2 件は「LLM エージェントが従う正本 (AGENTS.md / bug-hunt インベントリ)」の側にあり、
`docs/` 配下ではなく**規約・台帳の側が実装に追いついていない**という共通パターンを持つ。

---

## 1. 重要 (即座に修正)

### 1-1. bug-hunt インベントリが T106 の passkey route 7 本を取りこぼしている

- 対象: `/workspace/.claude/skills/app-bug-hunt/screens.md` / `/workspace/.claude/skills/app-bug-hunt/operations.md`
- 検出方法: `bash scripts/bug-hunt-inventory-check.sh` → **exit 3 (drift 検出)**

```
== screens (GET×inertia) ==
  [新ルート未追記] passkey.confirm-options が screens.md に無い
  [新ルート未追記] passkey.login-options が screens.md に無い
  [新ルート未追記] passkey.registration-options が screens.md に無い
== operations (非GET×web) ==
  [新ルート未追記] passkey.confirm が operations.md に無い
  [新ルート未追記] passkey.destroy が operations.md に無い
  [新ルート未追記] passkey.login が operations.md に無い
  [新ルート未追記] passkey.store が operations.md に無い
```

- 何が嘘になっているか: `scripts/README.md:33` は本スクリプトの実行タイミングを
  「**route 追加・削除時** / bug-hunt 実行前」と規定し、`AGENTS.md:172-173` は
  screens/operations を bug-hunt の正本と位置づけている。T106 は Fortify + laravel/passkeys が
  登録する route 7 本を実質的にアプリ面へ追加したが、インベントリを更新していない。
- 実害: **次回の `/app-bug-hunt` が passkey 面 (登録 / ログイン / 削除 / 再認証) を
  探索対象から丸ごと落とす**。T106 は「唯一のログイン手段を消せない」guard を導入した
  変更であり、詰み・IDOR が最も出やすい面がカバレッジ外になる。
- 修正方針: `screens.md` / `operations.md` に 7 route を追記し、
  `stories/` にパスキー登録→ログアウト→パスキーログイン→削除の詰み検証ストーリーを足す。
  (`docs/supported-browsers.md:127-129` の iOS 実機受入シナリオ 5 手がそのまま雛形になる)

### 1-2. T105/T106 の設計 devnotes がコミットされていない (D13 の参照先が dangling)

- 対象: `/workspace/devnotes/20260805-1244-auth-method-and-passkey/` — **22 ファイルすべて untracked**
  (`git ls-files` = 0 件 / `find -type f` = 22 件)
- 参照元: `/workspace/docs/template-divergence.md:541`
  `- 設計: \`devnotes/20260805-1244-auth-method-and-passkey/\` (施策 2)`
- 何が嘘になっているか: D13 (SSO phantom password 撤去) の「設計」リンク先が
  **リポジトリに存在しない**。clone した第三者・CI・将来の自分から見ると参照が切れている。
  同サイクルの他 6 ディレクトリ (`20260805-1243-ci-lane-integration` 17 件 /
  `20260805-1244-controller-authorization-gate` 19 件 / `20260805-1319-todo-T103` 8 件 /
  `20260805-1329-todo-T104` 15 件 / `20260805-1337-todo-T105` 3 件 /
  `20260805-1459-todo-T106` 8 件) は**すべて追跡済み**なので、本件は取りこぼしと判断できる。
- 補足: `devnotes/20260805-1337-todo-T105/` は impl-review 3 ファイルのみで、
  T105 の概念設計・詳細設計の実体は untracked 側にある = **T105/T106 の設計 SoT がローカル限定**。
- 修正方針: 当該ディレクトリをコミットする (`docs/TODO-closed.md` の T105/T106 行は既に閉じている)。

### 1-3. AGENTS.md のセキュリティ不変条件に T103 の新不変条件 2 本が入っていない

- 対象: `/workspace/AGENTS.md:41-57` (§セキュリティ不変条件)
- 現状: 1〜8 の 8 項目 (8 = SSRF 検査経由)。`AGENTS.md:43` は
  「詳細と実装手順は `docs/app-integration-guide.md` §7」と宣言している。
- 実装側: `docs/app-integration-guide.md:200-221` の §7 は T103 で **10 項目に拡張**され、
  - `8. 変更系 route は認可を通る` (`ControllerAuthorizationGateTest` が deny-by-default 強制)
  - `9. 層 2 は FormRequest より前で閉じる` (`api.project-in-org` / `Route::scopeBindings()`)
  が新設された。
- 何が嘘になっているか: AGENTS.md 側は 8 項目のままで、**新設された 2 本の不変条件が
  一切書かれていない**。AGENTS.md は全エージェントが最初に読む正本であり、かつ
  `app-codex-review` スキルが「使命・禁止事項」の参照元にしている。加えて
  AGENTS.md の番号と guide §7 の番号は元々 1:1 でない (AGENTS #6=PII / guide #6=逆シリアライズ、
  AGENTS #8=SSRF / guide #8=認可 gate) ため、**「§7 の 8 番」という相互参照が
  guide 側 (`docs/app-integration-guide.md:71`) にある今、番号衝突で誤読を招く**。
- 実害: 新規 route を足すエージェントが AGENTS.md だけを読むと、
  `Gate::authorize` 必須 / 層 2 を FormRequest より前に閉じる、という T103 の中核契約を知らない。
  `ControllerAuthorizationGateTest` が CI で止めるので事故にはならないが、
  「テスト赤 → 事後に規約を知る」動線になり、テストファースト原則 (AGENTS.md 思考原則 5) と逆行する。
- 修正方針: AGENTS.md §セキュリティ不変条件に 2 項目を追記する
  (または番号を guide §7 と揃える方針を明記する)。

---

## 2. 軽微 (次サイクルで `/app-update-docs`)

### 2-1. `.env.example` の cross-reference 先が存在しない

- 対象: `/workspace/.env.example:186`
  `#    運用契約は docs/architecture.md §パスキー (WebAuthn)。`
- 事実: `docs/architecture.md` に **§パスキー (WebAuthn) というセクションは無い**
  (見出し一覧を全走査して確認。passkey の言及は `:124` のモデル表と `:169` の Service 表のみ)。
- 正しい参照先: `docs/auth-security-mechanisms.md` §5「パスキー (WebAuthn)」の
  「運用上の注意」(`:294-303`)。APP_KEY ローテートで user handle が変わり全件無効になる旨は
  そこに正確に書かれているので、**内容は存在するがリンクだけ間違っている**。

### 2-2. `docs/architecture.md` の CUSTOM_BINDER 列挙が古い

- 対象: `/workspace/docs/architecture.md:85-87`
  `**5 分類 (deny-by-default)**: ... / \`CUSTOM_BINDER\` (\`{organization}\`。...)`
- 事実: T106 で `app/Http/Routing/RouteBindingTypes.php` の `CUSTOM_BINDER` は
  `organization` + **`passkey` (`SelfScopedPasskeyBinder`)** の 2 件になった。
- 何が嘘になっているか: 「単一 SoT の全 binding param 型 inventory」を説明する節が
  1 件しか挙げていない。`{passkey}` は「他人の passkey を 404 に倒す」= セキュリティ不変条件 2 の
  実装点なので、ここに載っていない意味は小さくない。
  (`docs/auth-security-mechanisms.md:253` には binder 差し替えの理由が正確に書かれている
  = 内容の欠落ではなく inventory 表現の陳腐化)

### 2-3. 検証コマンド一覧が CI と揃っていない (AGENTS.md / app-implement スキル)

- 対象: `/workspace/AGENTS.md:77-79` および
  `/workspace/.claude/skills/app-implement/SKILL.md:158`
- 事実: T104 で CI `frontend` job は `typecheck:packages` → **`build:packages`** →
  `test:packages` → `build` を順に回すようになった (`.github/workflows/ci.yml:193-204`)。
  AGENTS.md は T100 で `typecheck:packages` / `test:packages` を追記したが
  **`build:packages` が抜けている**。
- さらに `app-implement/SKILL.md:158` の品質チェック行は
  `vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build` のままで、
  **packages 系 3 コマンドを 1 つも含まない**。
- 実害: `app-implement` の手順どおりに実装した worktree は
  `packages/cli` のビルド破壊・テスト破壊をローカルで検出できず、CI で初めて赤くなる。
  T100 で `packages/cli` に profile:delete (281 行 + テスト 1041 行) が入った直後であり、
  今後 packages を触る頻度は上がる。

### 2-4. グローバルテストロック (T099) の周知が AGENTS.md 側にゼロ

- 事実: `docs/testing-browser.md:188-206` の「グローバルテストロックの手動復旧 runbook」は
  **内容として完璧**である。指示で確認を求められた 3 点はすべて満たしている:
  - 待ち時間が出ること → `:182-184`「**エラーにはならず待つ**ので、
    待機の heartbeat が出ている間はそのまま待てばよい」
  - heartbeat が正常であること → `:195`「待機中の heartbeat は 30 秒ごとに ... stderr へ出す」
  - kill してはいけない / 正しい止め方 → `:196-201` (`kill -TERM <pid>` はロック保持者に対してのみ、
    グループが空になるまで解放されない)、`:205`「**ロックファイルを消さない**」
- 乖離: それが **`docs/testing-browser.md` (ブラウザテスト専用ドキュメント) にしか無い**。
  `AGENTS.md` はロックに一言も触れておらず (grep で 0 件)、
  `AGENTS.md:77-79` の検証コマンド (`composer test` / `pnpm test`) を素直に実行した
  エージェントは「**数分無反応 → ハングと誤認 → 中断/kill**」に倒れうる。
  ロックは全レーン共通 (`composer test` / `composer test:browser` / `pnpm test` /
  `pnpm test:packages` / `pnpm test:coverage`) なのに、周知先がブラウザ専用ドキュメントに
  閉じているのは配置として弱い。
- 併せて `AGENTS.md:154` は分離設計を「vendor / node_modules / テスト DB / 実行時ファイルの **4 軸**」と
  要約しているが、`docs/worktree-isolation-strategy.md:36-48` は既に
  「リソース名前空間 / **実行そのもの**」の 2 層構造へ拡張済みで、要約が 1 段古い。

---

## 3. `docs/template-divergence.md` の採番整合性 — **問題なし**

| ID | 内容 | 対応 TODO |
|---|---|---|
| D1〜D8 | 既存 (Tier B / 循環 FK / sort_order / project guard / document 単位保存 / presigned / preview 直列化 / 管理メニュー) | 既存 |
| D9 | BillingAccess entitlement (✅→解消) | 既存 |
| D10 | テストレーンのグローバルロック | **T099** ✓ |
| D11 | svelte-no-undef-gate を config 静的検査型で別実装 | **T102** ✓ |
| D12 | ページタイトル / description はサーバ単一 SoT | **T101** ✓ |
| D13 | SSO 登録ユーザーの password を保存しない | **T106** ✓ |

- **欠番なし・重複なし** (D1〜D13 が連番で 1 回ずつ出現)。
- `## D1` の grep ヒットが 2 件あるが、1 件目 (`:17`) は §エントリ形式の**コードフェンス内の雛形**で
  実エントリではない。採番の重複ではない。
- 各エントリの `### 関連` から張られた実装パスは実在を確認した
  (`scripts/global-test-lock.sh` / `tests/js/architecture/svelte-no-undef-gate.test.ts` /
  `app/Services/Auth/SocialAccountService.php` 等)。
  ただし D13 の「設計」リンク先のみ dangling (→ §1-2)。
- D10 は `### 保証しないこと (明示)` / `### スコープ外の観測` を持ち、
  D13 は `### 射程 (既知の制約として残すもの)` で「前方修正のみ・遡及是正しない」を明記していて、
  逸脱記録として求められる水準を満たしている。

## 4. `scripts/README.md` と実ファイルの突き合わせ — **完全一致**

- 実ファイル 25 件 (`find scripts -type f`)、うち台帳対象 24 件 (`README.md` 自身は明示 exempt)。
- README の表の行 24 件と **過不足なく 1:1 対応**。孤児行・未記載ファイルともゼロ。
- T104 の解消内容も確認できた:
  - 未配線だった `ci/make-shard-phpunit.php` は**ファイルごと削除**され、README の行も消えている
    (「CI から自動呼び出し」という嘘の記述が消滅)。
  - 新規 8 件 (`global-test-lock.sh` / `with-global-test-lock.sh` / `verify-global-test-lock.sh` /
    `audit-gate.contract.test.ts` / `test-inventory-config.ts` / `vitest-inventory-gate.test.ts` /
    `run-browser-test.contract.test.ts` / — ) がすべて用途・実行タイミング付きで追記済み。
  - `run-test.sh` / `run-vitest.sh` / `run-browser-test.sh` の説明が
    「worktree-local flock」から「**グローバルテストロック配下**」へ書き換えられている
    (旧記述が残っていれば嘘になっていた箇所)。
- さらに `tests/Architecture/ScriptsReadmeInventoryTest.php` が S1 (実ファイル → 表) /
  S2 (表 → 実ファイル) の**両方向を deny-by-default で機械強制**するようになったため、
  この台帳は今後ドリフトしない。ドキュメント鮮度の観点では**本サイクル最大の前進**。

## 5. 陳腐化記述の探索結果 — **該当なし**

指示で挙げられた 4 パターンを全文検索で確認した (`docs/*.md` / `AGENTS.md` / `CLAUDE.md` /
`README.md` / `.claude/skills/`)。加えて `git show 4cbdff8:<各 md>` で**変更前の記述**も
走査し、「今回の変更で嘘になった文」が残っていないかを逆方向からも確認した。

| 疑い | 結果 |
|---|---|
| 「PostgreSQL が無い」「CI は sqlite」 | 該当なし。旧版にも存在せず。`docs/testing-browser.md:76` が CI の postgres service を正しく記述 |
| 「composer test が CI で走らない」 | 該当なし。旧版で唯一近い `docs/testing-browser.md:84`「(browser lane は) `composer test` からは実行されない」は**現在も真**(browser lane は別レーン) |
| Codex 旧モデル (gpt-5.3-codex / gpt-5.4) | 生きた規約からは一掃済み。`app-codex-vscode/SKILL.md:36-39` が「唯一の指定モデル `gpt-5.5`」と宣言し、`tests/js/architecture/codex-model-consistency.test.ts` が deny-by-default で他モデル名を検出。`docs/TODO-closed.md` の旧モデル記述は**過去の実績記録なので陳腐化ではない** |
| 「passkey が無い」 | 該当なし。`docs/architecture.md:124,169` / `docs/factories.md:19` (PasskeyFactory) / `docs/supported-browsers.md:98-129` / `docs/auth-security-mechanisms.md` §5・§6 / `config/fortify.php` / `.env.example` がすべて追記済み |
| 削除された `make-shard-phpunit.php` / matrix sharding | 参照残存なし (`docs/TODO-closed.md` の実績記録を除く) |
| CI job 名 (browser-tests / supply-chain-audit / build:packages) | `browser-tests` = `docs/testing-browser.md:73-82` に専用節。`supply-chain-audit` = `AGENTS.md:119` + `docs/supply-chain/review-checklist.md:53-66` (§6「CI での実行と運用責任」= 指示どおり明文化済み、nightly の `if: github.event_name != 'schedule'` まで記述)。**`build:packages` のみ AGENTS.md 側に欠落** (→ §2-3) |

## 6. Architecture gate 群のドキュメント説明状況 — **良好**

今サイクルで新設された Architecture テストは 14 本。主要なものは説明が用意されている。

| gate | 説明の所在 |
|---|---|
| `ControllerAuthorizationGateTest` | `docs/architecture.md:25-63` (§変更系 route の 3 層、ASCII 図つき) + `docs/app-integration-guide.md:200-241` (不変条件 8 + 新規 route チェックリスト 6 項) |
| `ProjectRouteCurrentOrgGuardTest` (API 拡張) | 同上 (不変条件 9) + `routes/api.php` の middleware 順序契約コメント |
| `CarbonOverflowArithmeticGateTest` | `AGENTS.md:62-65` (実装規約に `*NoOverflow` 既定を明記) |
| `DocumentTitleCoverageTest` / `svelte-head-no-title` | `docs/template-divergence.md` D12 |
| `svelte-no-undef-gate` | `docs/template-divergence.md` D11 |
| `GlobalTestLockInventoryTest` | `docs/worktree-isolation-strategy.md:48` |
| `PasskeyPackageContractTest` / `PasskeyRouteProtectionTest` / `PasswordConfirmMiddlewareAbsenceTest` | `docs/auth-security-mechanisms.md` §5 (4 つの不変条件表) |
| `LoginMethodRemovalRouteTest` | `docs/auth-security-mechanisms.md` §6 (§適用範囲の機械強制、両方向固定を明記) |
| `SocialProviderTrustPolicyTest` | `docs/auth-security-mechanisms.md` §4 + `config/template.php` のコメント |
| `ScriptsReadmeInventoryTest` | `scripts/README.md` 冒頭の規約ブロック + テスト自身の docblock |
| `PhpunitBrowserConfigParityTest` / `ci-workflow-inventory.test.ts` | `docs/testing-browser.md:80-82` |
| `NoNonCompoundGlobalUseTest` / `contrast-invariant.test.ts` | `DESIGN.md:104-110` (contrast のみ)。`NoNonCompoundGlobalUseTest` は**散文の説明なし** (軽微。テスト自身が自己文書化されており、AGENTS.md 実装規約に足すかは要否判断) |

---

## 7. 分類サマリ

### 重要 (即座に修正)

1. **bug-hunt インベントリの passkey 7 route 欠落** — `scripts/bug-hunt-inventory-check.sh` が
   exit 3 で検出済み。次回 bug-hunt のカバレッジ穴に直結する
2. **`devnotes/20260805-1244-auth-method-and-passkey/` (22 files) が untracked** —
   `docs/template-divergence.md:541` の参照が dangling、T105/T106 の設計 SoT がローカル限定
3. **`AGENTS.md` §セキュリティ不変条件に T103 の不変条件 8/9 が無い** —
   全エージェントが読む正本と `docs/app-integration-guide.md` §7 の乖離 + 番号衝突

### 軽微 (次サイクルで `/app-update-docs`)

4. `.env.example:186` の参照先セクション不在 (→ `docs/auth-security-mechanisms.md` §5)
5. `docs/architecture.md:85-87` の `CUSTOM_BINDER` 列挙に `{passkey}` が無い
6. `AGENTS.md:77-79` に `build:packages` 欠落 / `app-implement/SKILL.md:158` に packages 系 3 コマンド欠落
7. グローバルテストロックの周知が `docs/testing-browser.md` 限定 (AGENTS.md に導線なし) +
   `AGENTS.md:154` の「4 軸」要約が 1 段古い

### 所見

乖離 3・4・5・7 はいずれも「**新機能側のドキュメントは丁寧に書かれたが、
既存の要約・列挙・台帳を更新し忘れた**」という同一の失敗モードである
(架空の記述ではなく、正しい記述が別の場所にあって古い側が残っている)。
`ScriptsReadmeInventoryTest` (台帳 ↔ 実ファイル) と
`ci-workflow-inventory.test.ts` (CI job ↔ 期待) は今サイクルでまさにこの型の
ドリフトを機械強制へ昇格させた成功例であり、同じ手が
「`RouteBindingTypes::CUSTOM_BINDER` ↔ `docs/architecture.md` の列挙」
「`package.json` の検証系 script ↔ AGENTS.md / app-implement の検証コマンド列」
にも適用できる。次サイクルの候補として記録しておく。
