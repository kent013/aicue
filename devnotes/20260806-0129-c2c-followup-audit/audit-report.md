# c2c 追従 3 件 (T115 / T116 / T117) の事後監査

- 監査日時: 2026-08-06 01:29 JST
- 監査対象コミット: main `0c8f85b` (merge commit `81aab9f` / `4c0ab21` / `cfcf17c` を含む範囲 `112c2c7~1..HEAD`)
- 監査者: 独立監査担当 (実装担当・マージ担当の自己申告は根拠として採用しない)
- 正本: `AGENTS.md` / 各 c2c 裁定ブリーフ (AG-033 / AG-005 / AG-048b)

## 0. 監査で自分が実行した検証

| 検証 | 結果 |
|---|---|
| `composer phpstan` | 791/791 files, **[OK] No errors** (level 10)。差分に `@phpstan-ignore` / baseline / 型 widen の追加は **0 件** (`git diff` 実測) |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `vendor/bin/pest tests/Architecture/{ModelDirectFetchInvariantTest,BughuntShardCapInvariantTest,AccountDeletionBlockerActionTsSyncInvariantTest}.php` | tests=50 / passed=50 / assertions=187 / 2.2s |
| `scripts/bug-hunt-shard.sh self-test` | `self-test: all passed` (exit 0)。実資源に触れない |
| **走査器の独立 mutation 検査** (自前 fixture を `PrimaryKeyStaticQueryScanner` に直接投入) | 後述 §2-B。gate が実際に検出することを自分の手で確認 |
| 既存テストの削除 / skip | **0 件**。`git diff --numstat` の削除行はすべて prop 名リネーム・コメント書き換えに伴うもので、テストケース数は増加のみ |
| dev DB 破壊操作 | 一切実行していない (`drop-test-db.php --apply` 不使用) |
| `docker-compose.yml` の未コミット変更 | 触れていない (監査後も `M docker-compose.yml` のまま) |

`composer test` はマージ担当が HEAD で実行済み (3247/3245 passed/0 failed) のため再実行せず、
疑わしい箇所のみ名指しで流した (グローバルロックの無駄な占有を避ける)。

---

## 1. T115 / `account-deletion-billing-guard` (裁定 AG-033)

### 裁定の要求 vs 実装の実態

| # | 裁定の要求 | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | 有効なサブスクリプション / オーナーの課金中組織があれば退会をブロック | `AccountDeletionBillingGuard::hasLiveBillingObligation()` を新設し、`organizationsBlockingDeletion()` が「唯一 Owner かつ (他メンバー残存 ∨ 生きた課金責務)」で blocker を返す。**最終権威は `deleteAccount()` のロック下再評価** | 適合 | `app/Services/Billing/AccountDeletionBillingGuard.php:38-52` / `app/Services/Organization/OrganizationMembershipService.php:552-608,695-706` |
| 2 | **何をすれば退会できるかを提示する** | `AccountDeletionBlockerDto` が理由 → action (`transfer_ownership` / `open_billing` / `switch_organization_then_open_billing`) を導出。非現在組織の課金は「切替 → 成功時のみ /billing」導線を用意し、失敗時はその場でエラー表示 (押しても何も起きない詰みを作らない) | 適合 | `app/DataTransferObjects/Organizations/AccountDeletionBlockerDto.php:50-75` / `resources/js/pages/Settings/Index.svelte:337-378` |
| 3 | 禁止事項 8 (必須条件未充足で disabled にしない) | 削除ボタンは常に活性。JS テスト「削除ボタンは常に有効 (disabled 不使用)」が固定 | 適合 | `tests/js/pages/SettingsIndex.test.ts:304` |
| 4 | AG-033 追加 3 点 (1) 決済事業者側に残るデータの扱いを明記 | `docs/architecture.md` §退会 (アカウント削除) の課金ガード に「退会経路から事業者 API を呼ばない原則」「redaction は作成から 90 日後のみ・処理に最大 30 日」「アプリから自動化しない」を明記。**一次情報 URL が台帳に無いことも含めて明記**し、推測 URL を書いていない | 適合 (ただし §5 Info-3) | `docs/architecture.md` 差分 (+43 行) |
| 5 | AG-033 追加 3 点 (2) 猶予期間つき削除 | **今回入れない**。理由「`users` の物理削除前提 (FK cascade / CipherSweet / 監査 null 化) を作り替える大工事」を詳細設計に明記 | 適合 (明示スコープ外) | `devnotes/20260805-2315-account-deletion-billing-guard/detailed-design.md:845-853` |
| 6 | AG-033 追加 3 点 (3) 保持期間の実装 | **今回入れない**。理由「利用規約の正式文面確定が前提 (`/terms` は現在プレースホルダで保持年数の記述なし)」を明記 | 適合 (明示スコープ外) | 同上 |
| 7 | 退会処理から決済事業者 API を呼ばない | 「退会成功経路では決済事業者 API を呼ばない」「課金中でブロックされる経路でも決済事業者 API を呼ばない (解約を代行しない)」の 2 本で固定 | 適合 | `tests/Feature/Auth/AccountDeletionTest.php:229,242` |
| 8 | 穴 (オーナー不在の課金中組織) の検知 | `organizationsWithoutOwner()` + `billing:detect-orphan-billing-organizations` (daily) を新設。PII 非出力・1 実行 1 回の `report()`。`MembershipWriteLockInventoryTest` の `$exempt` へ理由付き登録 | 適合 | `routes/console.php:42-77` / `tests/Architecture/MembershipWriteLockInventoryTest.php:29` |

### 偽グリーン検査

- **アサーションは緩んでいない**。`ProfileSettingsPropsTest` は 2 test → 4 test に増え、
  `soleOwnedOrganizations` の存在確認が `accountDeletionBlockers` の **actions 配列完全一致** に強化されている。
- `tests/js/pages/SettingsIndex.test.ts` は削除された `soleOwnedOrganizations` 系 3 ケースに対し
  blocker 系 6 ケース (+ 切替失敗経路) が入り、**カバレッジは純増**。
- PHPStan L10 の narrowing は **型 widen ではなく `Assert::isInstanceOf` による fail-closed**
  (想定外型を読み飛ばさない)。禁止事項 2 に抵触しない。
- 「唯一 Owner」条件による絞り込みは AG-033 の rationale (「オーナー不在の課金中組織が残る」) と
  整合しており、2 人目 Owner が居れば課金の引受先が残るため穴にならない。
  テスト「課金中でも 2 人目オーナーがいれば退会できる (課金の引受先が残る)」で意図が固定されている。
- `ends_at !== null` を通す判断は「解約したのに退会できない詰み」を避けるためで、
  テストと `docs/architecture.md` の両方で理由が固定されている。fail-open ではなく設計判断。

**判定: PASS**

---

## 2. T116 / `model-direct-fetch-gate` (裁定 AG-005 / feature `nested-route-idor-defense` t1)

### 裁定の要求 vs 実装の実態

| # | 裁定の要求 | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | `ModelDirectFetchInvariantTest` の新設 (t1 の欠落分ただ 1 本) | 新設 (18 test)。走査器 `PrimaryKeyStaticQueryScanner` (2490 行) + 走査器自体の positive/negative 39 test を別ファイルで固定 | 適合 | `tests/Architecture/ModelDirectFetchInvariantTest.php` / `tests/Unit/Architecture/PrimaryKeyStaticQueryScannerTest.php` |
| 2 | deny-by-default (未登録の直 fetch は fail) | test 1 が候補 ∖ inventory を fail させる。加えて **stale 検出** (inventory ∖ 候補) も fail | 適合 | 同 `:172-196` |
| 3 | 「今あるコードを全部 allowlist に入れて緑にする」を禁止 | 分類 9 種それぞれに **機械検証可能な副条件**が付く (private/引数由来/request accessor 不在/`calledBy` 実在/`enqueuedBy` が実際に dispatch している/route が local 限定ブロック内 等)。理由文は 30 文字以上必須 (`REASON_MIN_LENGTH`) | 適合 | 同 `:198-431` / `tests/Support/Security/DirectFetchJustificationEntry.php:21,31` |
| 4 | 母集団を層で絞らない (Service 側 global fetch の抜け道を残さない) | `scannedPaths() = ['app','routes']`。ノイズ落としはディレクトリではなく **provenance (fail-closed)** | 適合 | `tests/Support/Security/DirectFetchInventory.php:18-22,33-36` |
| 5 | 危険な直 fetch は直す / 直せないなら理由付き登録 | 34 候補中 31 件は分類済み、**3 件は「債務」として登録し修正は `aicue:T118` へ繰り延べ** | **一部未達 → §5 Warning-1** | `tests/Support/Security/DirectFetchInventory.php:316-337` |
| 6 | AGENTS.md への反映 (項目名で参照) | 不変条件 3「cross-org 不可」に gate を追記。`docs/app-integration-guide.md` §7-3 / `docs/architecture.md` にも追記し、`NestedRouteIdorDefenseTest` と母集団が交わらないことを明記 | 適合 | `AGENTS.md:49-53` |

### A. 偽グリーン検査 (静的)

- **degenerate PASS 防止**: 候補数の下限 20 を固定 (`modelDirectFetchCandidateFloor`)。実測 34、inventory も 34 entry
  (`rg -c "=> DirectFetchJustificationEntry::"` = 34) で一致。走査器が壊れて 0 件になれば fail する。
- **債務の増殖防止**: `modelDirectFetchDebtCap() = 3` で 4 件目が入った瞬間に fail。
  設計は上限 2 を想定していたが実測 3 件だったため 3 へ引き上げ、**その逸脱を `implementation-notes.md:19-23` に記録**している (黙って上げていない)。
- **回避経路の封鎖**: 動的列名 (`where($col, $id)`) は候補にならないため、`reviewedDynamicColumnPredicates()` で
  双方向整合 (未登録も stale も fail) を取っている。`whereRaw` / `whereIntegerInRaw` / 非主キー一意列解決は
  **0 件固定**で、生えた瞬間に赤くなる。
- **provenance の時間順序**: 「後段の安全な代入で前段の危険な値を安全扱いする」回避を封じるため、
  代入イベントを時系列で持ち再代入で証明を失効させている (`provenTimeline`)。走査器テストで固定済み。

### B. 独立 mutation 検査 (監査者が自分で実施)

実装担当のテストを信用せず、`PrimaryKeyStaticQueryScanner` に監査者自作の fixture を直接投入した:

| 投入した形 | 期待 | 実測 |
|---|---|---|
| `User::findOrFail($request->integer('user_id'))` (Controller) | 候補として検出 | `candidates=1` (`Fake.php#store#User.findOrFail:$request->integer('user_id')#1`) |
| `DB::table('users')->where('id', $id)` (Service) | 候補として検出 | `candidates=1` |
| `$col='id'; User::query()->where($col,$id)` (gate 黙らせ狙い) | 動的列名として別枠で捕捉 | `candidates=0 / dynamic=1` (= 未登録なら test 11 が fail) |
| `Organization::query()->whereKey($payload['org_id'])` | 候補として検出 | `candidates=1` |

**gate は実際に検出する。allowlist 全入りによる無害化は起きていない。**

### C. gate が保証しないと明示している範囲 (誇張なし)

到達可能性 / provenance 第 2 段 / relation 強制の一般証明 / `exists:` rule の存在漏れ は
**保証しないとテスト冒頭 docblock に明記**されている (`:31-36`)。監査としてもこの限定は妥当と判断する
(代償措置として「非主キー一意列によるモデル解決 0 件」を固定している)。

**判定: PASS (ただし §5 Warning-1 の債務 3 件を `aicue:T118` で追跡すること)**

---

## 3. T117 / `bughunt-shard-cap-4` (裁定 AG-048b)

### 裁定の要求 vs 実装の実態

| # | ブリーフの要求 | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | `SHARD_RE` を `^[0-8]$` → `^[0-4]$` | `BUGHUNT_SHARD_CAP=4` を **唯一の導出点**にして `SHARD_RE="^[0-${BUGHUNT_SHARD_CAP}]$"` へ。数字の写経を排除 | 適合 (要求以上) | `scripts/bug-hunt-shard.sh:68,73` |
| 2 | `--parallel` 受理値 2/4/6/8 → 2/4 | `valid_parallel_n()` を case 列挙から **算術判定** (`n>=2 && n<=CAP && n%2==0`) へ | 適合 | 同 `:149-153` |
| 3 | ポート `:8011..8018` → `:8011..8014` (スクリプト + ドキュメント) | `shard_port()` は `BASE_PORT + n` の導出。散文は AGENTS.md / SKILL.md / ledger / coverage / `.env.bughunt.local.example` を全面更新 | 適合 | `AGENTS.md:215` ほか |
| 4 | DB 名 regex `_[1-8]` → `_[1-4]` (pin 側も同時に) | **allowlist 側** (`SHARD_DB_RE` / `.env.bughunt.local.example` / `BughuntEnvExampleContractTest` コメント) は 4 化。**守る側** (`DetectsBughuntDatabase` / `DEV_DB_DENYLIST` / browser lane の `{8010..8018}`) は **意図的に 8 のまま据え置き** | 適合 (§5 参照。黙って落としていない) | `scripts/bug-hunt-shard.sh:105` / `tests/Support/Ci/TestDatabaseEnv.php:43` / `database/seeders/Concerns/DetectsBughuntDatabase.php:21` |
| 5 | 隔離不変条件 (枠ごと DB / ポート / role 分離、`require_orchestrator`、`env -i`) を緩めないこと | 該当箇所に変更なし。self-test の `[b]/[c]` が範囲外 shard と DB 名バリアントの abort を実測で固定 | 適合 | self-test 実行結果 (all passed) |
| 6 | 検証: self-test + Architecture テスト群 | 監査者自身が self-test を実行し `all passed` (exit 0) を確認 | 適合 | §0 |

### 「守る側は cap と同期させない」判断の妥当性

ブリーフ項目 3 を字義どおり読むと `DetectsBughuntDatabase` の `_[1-8]` も 4 へ下げることになるが、
実装は「**allowlist (触れてよい対象) は狭め、denylist / 検出 (守る対象) は cap より広く保つ**」という
方向性の違いで据え置いた。これは:

- 詳細設計 §設計の中心原則に表 (攻める面 / 守る面) として明記されている
  (`devnotes/20260805-2314-bughunt-shard-cap-4/detailed-design.md:55-70,423-430`)
- `AGENTS.md:220-221` に「判定側の regex は残留 DB も検出するため cap より広い」として利用者向けにも明文化
- `BughuntShardCapInvariantTest` が **値を直接固定**し、cap 追従で狭める改変を fail させる
  (`:515-535`)

狭めると過去 cap=8 期の残留 `bug_hunt_5..8` を bughunt DB と認識できず dev DB 扱いになるため、
**据え置きの方が dev DB 防御として正しい**。黙って落としたのではなく、逆向きの検査として明示的に固定している。
監査として妥当と判断する。

### 偽グリーン検査

- `BughuntShardCapInvariantTest` は **負のコントロールを 6 本持つ** (Tier B literal / Tier A 割り当て値 /
  マーカー付き Tier A / allowlist 外マーカー / 守りの語なしマーカー / 6-\*・8-\* case を戻した script fixture)。
  さらに **偽陽性防止**を 4 本持ち、cap を 6 に上げたら検出が追従することまで固定している。
- 免除マーカー `cap-defense-ok` は **Tier A (割り当て値) には効かない**設計で、bypass にならない。
  使用可能ファイルも 2 本に限定され、「守りの語」を含まないマーカー行自体が違反になる。
- self-test の `[m]` セクションは cap 導出へ書き換えられたが、**「旧 cap の 6/8 と奇数 3 が die 1 する」
  正のアサーションが追加**されている (アサーションは緩んでいない)。
- 監査者が `.claude/skills/app-bug-hunt/` / `docs/` / `.env.bughunt.local.example` を独自に `rg` した結果、
  残留 cap=8 表記は **守る側 3 ファイル + `docs/TODO-closed.md` の履歴記述のみ**。

**判定: PASS**

---

## 4. AGENTS.md 不変条件の遵守

| 観点 | 結果 |
|---|---|
| 禁止事項 1 (テストなし実装) | 3 件とも Architecture / Feature テストへの登録まで完了。新規 Architecture テスト 3 本 + Feature テスト 4 本 |
| 禁止事項 2 (PHPStan の widen / baseline) | 差分に `@phpstan-ignore` / baseline 追加 **0 件**。L10 clean を監査者が再実行して確認 |
| 禁止事項 3 (dev DB 破壊操作) | T117 は `--orphans` dry-run のみ。`--apply` 不実行の旨が TODO 記録にもある |
| 禁止事項 4 (`response()->json()` 直書き) | 差分に追加 **0 件** (Inertia props + DTO で構成) |
| 禁止事項 8 (disabled UI) | 削除ボタンは常時活性。JS テストで固定 |
| 禁止事項 9 (Artifact) | 本監査でも未使用。成果物は本ファイル |
| セキュリティ不変条件 3 (cross-org 不可) | gate 新設により**強化**された。ただし既存債務 3 件は残る (§5 Warning-1) |
| セキュリティ不変条件 5 (team 明示) | `organizationsWithoutOwner()` は `role_user.team_id` を `organizations.laratrust_team_id` と `whereColumn` で突き合わせており準拠 |
| 検証コマンド marker | `VERIFICATION_COMMANDS` マーカーは無傷 |

---

## 5. Findings

### Warning-1 — 直 fetch 債務 3 件が未修正のまま main に残っている (T116)

`DirectFetchInventory` の `PayloadIdWithGlobalExistenceRuleDebt` 3 件は、
ブリーフが求めた「(a) 危険なら直す / (b) 安全な理由があるなら inventory 登録」の 2 択に対し
**第 3 の選択肢 (債務として登録し修正は後続へ)** を採ったもの:

| 箇所 | 形 | 残る露出 |
|---|---|---|
| `app/Http/Controllers/Organizations/OrganizationOwnershipController.php:29,35` | `exists:users,id` + `User::query()->findOrFail($payloadUserId)` | 組織管理者が任意の `users.id` について「実在する (403) / 実在しない (422)」を判別できる |
| `app/Http/Controllers/Projects/ProjectMemberController.php:41,50` | 同上 | 同上 |
| `app/Http/Middleware/McpConsentOrganizationBinder.php:59` | `Organization::query()->find($payloadOrgId)` | consent 経路で `organizations.id` の実在を判別しうる |

- いずれも **T116 が新規に作った穴ではなく、gate 導入によって可視化された既存債務**である。
- 追跡は適正: `aicue:T118` が `docs/TODO.md:26` に Open で存在し、`todoRef` の実在性はテストが機械検証している
  (プレースホルダを許さない)。件数上限 3 により 4 件目で CI が落ちる。
- 影響度は「認証済みの組織管理者による全体 id 空間の存在オラクル」に留まり、cross-org のデータ read/write は起きない。

**是正すべきこと**: T118 で relation 起点 (`$organization->users()->whereKey(...)`) へ寄せ、
`exists:users,id` を組織スコープ付き rule へ見直す。gate 側の `modelDirectFetchDebtCap()` も 3 → 0 へ下げること。

### Info-1 — cap 散文 gate の走査対象が固定 10 ファイルの列挙 (T117)

`CAP_ALLOCATION_DOCS` は 10 本のハードコード列挙で、**新規に追加されたドキュメントが
`:8011..8018` / `cap=8` と書いても検出されない**。列挙内では deny-by-default だが、
ファイル集合そのものは allowlist である。

現状の bug-hunt 関連ドキュメントは網羅されており実害はない。将来 `.claude/skills/app-bug-hunt/**` を
glob 走査へ広げるかは、偽陽性コストとの兼ね合いで後続判断でよい (今回是正不要)。

### Info-2 — c2c 台帳への `status_reported` 書き戻しが未了 (3 feature 共通)

監査時点で `get_feature` を実行した結果、aicue の status は
`bug-hunt-exec-infra` / `account-deletion-billing-guard` とも **`pending` のまま**で、
aicue からの `status_reported` イベントは 1 件も存在しない。

原因は手順違反ではなく順序の問題: `main` は `origin/main` より **11 commits ahead で未 push**
(`git status -sb` 実測) のため、`refs` に必須の `<repo>@<commit>` を pin できない。
push 後に 3 feature へ `status_reported` を追記すること
(T116 の詳細設計 §後続 TODO 候補 3 にも同じ申し送りがある)。

### Info-3 — 決済事業者 redaction の数値 (90 日 / 30 日) の一次情報 URL が未 pin (T115)

`docs/architecture.md` は出典を「c2c 台帳 AG-033 の handover (確認日 2026-08-05)」とし、
**一次情報 URL が台帳側に存在しない事実まで明記**したうえで
「運用に効かせる前に一次情報を引き直し URL と確認日を追記すること」と書いている。
推測 URL を捏造していない点は適切な処理であり、**今回是正すべき欠陥ではない**。
運用手順として実際に redaction を回す前に必ず引き直すこと。

### Info-4 — 直 fetch gate の provenance 証明は第 1 段まで (T116)

「変数が `App\Models\*` である」ことは証明するが、「その元モデルが保証済み provenance に属する」
第 2 段は v1 では実装していない。代償措置として
「クラス起点クエリが非主キー一意列 (uuid/slug/public_id/ulid/code) でモデルを解決する箇所 **0 件**」
を固定し、前提が崩れた瞬間に fail する設計になっている。
**限界を docblock で明示しており誇張はない**。前提が崩れたら設計 §4-2(c) に戻ること。

---

## 6. 残タスク (裁定のうち今回入らなかったもの)

| # | 内容 | 追跡先 | 前提 |
|---|---|---|---|
| 1 | payload 由来 id の org 相対化 (直 fetch 債務 3 件の解消) + `exists:` rule 見直し | **`aicue:T118` (Open)** | 403/404/422 の振る舞い変更を伴う |
| 2 | AG-033 (2) 猶予期間つき削除 (誤操作救済 + 即時削除の併存) | 未起票 | `users` 物理削除前提 (FK cascade / CipherSweet / 監査 null 化) の作り替え |
| 3 | AG-033 (3) 保持期間の実装 (規約の宣言年数 ⇔ 匿名化処理) | 未起票 | **利用規約の正式文面確定** (`/terms` は現在プレースホルダ) |
| 4 | 検知された孤児組織の回収手順 (運用 runbook) | 未起票 | 組織削除 (feature boundary 外) と事業者 API の話。まず検知を運用に載せる |
| 5 | c2c 台帳への `status_reported` × 3 feature | 未実施 | **main の push** (`refs` に `aicue@<commit>` が必須) |
| 6 | cap 散文 gate の走査対象を glob 化するか | 判断保留 | 偽陽性コストとの兼ね合い。現状実害なし |

---

## 7. 総合判定

**PASS_WITH_FOLLOWUP**

- 3 件とも裁定の必須スコープを満たしており、スコープ外にしたものは詳細設計に「やらない理由」が明記されている
  (黙って落としているものは無い)。
- 偽グリーンは検出されなかった。特に T116 の gate は監査者自身の mutation 検査で
  実際に検出することを確認しており、「allowlist 全入りで無害化」は起きていない。
  T117 の gate も負のコントロール 6 本 + 偽陽性防止 4 本を持ち、免除マーカーは Tier A を bypass できない。
- 既存テストの削除・skip・アサーション緩和は **0 件**。すべて純増または強化。
- PHPStan L10 / pint は監査者の再実行でも clean。`@phpstan-ignore` / baseline / 型 widen の追加は 0 件。
- 残るのは Warning-1 (既存債務 3 件 = `aicue:T118` で追跡済み) と、
  裁定の残り 2 点 (猶予期間つき削除 / 保持期間) および c2c 書き戻しの follow-up。
