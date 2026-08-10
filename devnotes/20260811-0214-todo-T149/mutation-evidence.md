# mutation evidence — T149 (blocked-action-context / F-4-01)

> 詳細設計 `devnotes/20260811-0146-blocked-action-context/detailed-design.md` §「mutation で赤化を確認する手順」の実施記録。
> 各 mutation は **1 つずつ**当て、確認後に必ず戻した (`git status` で作業ツリーが意図した差分のみであることを確認済み)。
> 実行レーン: `composer test -- --filter=…` (worktree `.claude/worktrees/tasks/T149`)。

## 修正前の再現 (テストファースト)

施策 1/2 を入れる**前**に、新規・更新テストだけを走らせて赤を確認した
(`--filter="AccountDeletionFreeze|TwoFactorEnforcement"` → 92 tests / 4 failed / 3 errors)。

| 赤くなったテスト | 観測された値 |
|---|---|
| Feature `2FA 未準拠でも退会予約を取り消せる (救済は 2FA ゲートも凍結も通る)` | 取消 DELETE が `/settings` ではなく **`/settings/security`** へ 302 (= F-4-01 の再現そのもの) |
| Feature `退会取消は allowlist 経由でゲートを通り 2FA 状態を変えない` | 同上 (`/settings/security`) |
| Arch `allowlist の件数を exact-fit で pin する` | actual size **20** / expected 21 |
| Arch `救済 route は allowlist にあり、予約・即時削除は無い (名指し pin)` | `settings.account.deletion-request.destroy` を含まない |
| Feature 3 本 (書き込み prefix / GET 負のコントロール / XHR) | `Undefined constant …::BLOCKED_WRITE_PREFIX` |

> 負のコントロール `2FA 未準拠ユーザーの即時削除は通らない` は**修正前から緑**である
> (これは「変わってはいけないこと」の固定であり、赤くなるべきテストではない)。

## mutation 実施結果

| # | mutation | 設計の予測 | 実測 | 一致 |
|---|---|---|---|---|
| M1 | `ALLOWED_ROUTE_NAMES` から `settings.account.deletion-request.destroy` を削除 | Feature「2FA 未準拠でも取消できる」/ Arch 検査 2 / 施策 4 の名指し pin・件数 pin | **5 failed**: Feature `2FA 未準拠でも退会予約を取り消せる` (`/settings/security`)、Feature `退会取消は allowlist 経由で…`、Arch 検査 2、件数 pin (20≠21)、名指し pin | ✅ |
| M2 | 施策 2 の条件を `if (false)` に固定 | Feature「書き込みには実行されていませんが付く」/ XHR 版 | **2 failed**: 同 2 本 (message が prefix で始まらない) | ✅ |
| M3 | 施策 2 の条件を常時 true (`if (true)`) に | Feature「GET には付けない」(負のコントロール) | **1 failed**: `遮断された GET には「実行されていません」を付けない` | ✅ |
| M4 | enum から `RequireTwoFactor` case を削除 (match 3 箇所も同時に削除) | Arch 検査 1 (exact-fit) / 検査 3 (名指し) / 件数 pin | **1 failed + 2 errors**: 検査 1 が `undeclared` を検出、検査 3 と空振り検知が `Undefined constant …::RequireTwoFactor` で error | ⚠ 下記「ずれ 1」 |
| M5 | enum の `RequireTwoFactor` を `NeverShortCircuitsRescueRoute` に変更 | Arch 検査 3 | **1 failed**: 検査 3 のみ | ✅ |
| M6 | 母集団抽出から vendor 名指し 3 本を外す | Arch 検査 1 / 検査 5 / 件数 pin | **3 failed**: 検査 1 (死に登録 3 件)、検査 5 (母集団から欠落)、件数 pin (6≠9) | ✅ |
| M7 | `AccountDeletionFreezeAllowance` から `DeletionRequestDestroy` を削除 | Arch 検査 2 + 既存 `AccountDeletionFreezeRouteGateTest` の件数 pin | **2 failed**: 検査 2 (`EnsureAccountNotPendingDeletion`)、既存 gate の件数 pin (16≠17) | ✅ |
| M8 | `permitsRouteName()` を `return true;` に置換 | Arch 検査 7 (負のコントロール) | **1 failed**: 空振り検知テスト内の負のコントロール (`settings.account.destroy` が true) | ✅ |

## 設計の予測と実測のずれ (辻褄を合わせずに記録する)

### ずれ 1 — M4 で「件数 pin」は点灯しない

設計は M4 の期待赤に「件数 pin」を挙げているが、**`RESCUE_GATE_POPULATION_COUNT` は母集団 `U`
(= route の resolve 済み middleware から導出) を数えており、enum の case 数は数えていない**。
よって enum から case を 1 つ消しても件数 pin 自体は動かない (実測: 空振り検知テストは赤くなったが、
理由は件数の不一致ではなく `RescueRouteGateDisposition::RequireTwoFactor` が
**存在しない定数**になったことによる error である)。

- 実装を設計に合わせて「enum の件数も pin する」ようにはしていない。**検査 1 の exact-fit が
  enum 側の増減を両方向で捕まえる**ため、件数 pin を二重に置くと同じことを 2 回書くだけになる
  (思考原則 2)。
- ただし「M4 の帰結として空振り検知テストも赤くなる」のは**定数消失の副作用**であって
  件数検査の成果ではない。ここを混同しないよう本節に残す。

### ずれ 2 — M4 は「case を消すだけ」では適用できない

`disposition()` / `rationale()` / `permitsRouteName()` の 3 つの `match` が全 case 網羅なので、
case だけを消すと **PHP の構文/網羅性で先に落ちる**。mutation は 4 箇所 (case + match 3 本) を
同時に削って適用した。「1 mutation = 1 箇所」の原則からはやや外れるため明記する。

### ずれ 3 — 検査 5 は設計の記述より 1 段強い

設計の検査 5 は「名指し vendor 3 本が**実際に route に付いている**」だけだが、それだけだと
M6 (母集団抽出から vendor を外す) で検査 5 は緑のままになる (route は変わらないため)。
設計の M6 予測 (検査 5 も赤) を満たすため、実装では検査 5 を
「**route に付いている** かつ **母集団の導出に入っている**」の 2 段にした。
機能的な保証範囲は広がっていない (どちらも「分類の前提が崩れたら気づく」ための検査)。

## 戻し確認

全 mutation 適用後、各対象ファイルをバックアップから復元し `git status --porcelain` で
**実装差分 6 ファイル + 新規 3 ファイル**だけが残っていることを確認した
(mutation 対象だった `app/Enums/Account/AccountDeletionFreezeAllowance.php` は
modified に現れない = 完全に戻っている)。
