# 実装レビュー Round 3 (aicue:T210)

Round 2 の唯一の Warning (A2 の扱い) へ対応した。対応マトリクスは
`codex-history/impl-review-decisions-round-2.md` に保存済み。

## 判断: 提示された 3 択のうち 2 と 3 を採り、1 (詳細設計の改訂) は採らない

- 1 を採らない理由: 詳細設計は設計レビュー Round 4 で APPROVED 済みの確定文書であり、
  実装ブランチで受け入れ条件を書き換えると「達成できるように条件を直した」形になる。
  また本 run は `docs/TODO.md` に触れない前提 (クローズは後段の統合エージェントの仕事) なので、
  条件を改訂しても実体は変わらない。
- 採った形: **A2 は本ブランチでは未達**と明記し、`app-todo-close` が T210 の行を
  `docs/TODO-closed.md` (設計の A2 が既に除外しているファイル) へ移した時点で
  追加作業なしに満たされる後続条件として扱う。実装側で確認できる範囲は
  **A2' (代替確認)** として別に採番し、A2 達成とは呼ばない。
- 実装完了報告の deviations にも同じ内容を記載する。

## verification.md の該当部分 (修正後の全文)

```markdown
# 検証記録: aicue:T210 (scripts 台帳の CI 検査の撤去)

実装ブランチ: `todo/T210` / 実行場所: `.claude/worktrees/tasks/T210`

本書は詳細設計の「テストファースト計画」「受け入れ条件」を実測した記録である。

## 1. 着手前 (赤) の実測

| 順 | コマンド | 実測 |
|---|---|---|
| R1 | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md'` | 5 件ヒット (設計時の 4 件 + 設計後に登録された `docs/TODO.md` の作業項目行 1 件) |
| R2 | `test -e tests/Architecture/ScriptsReadmeInventoryTest.php` | 真 (存在する。211 行 / テスト 5 本) |
| R3 | `grep -c 'scripts/README' .claude/skills/app-update-docs/SKILL.md` | 0 |
| R4 | `grep -c '台帳の対象範囲' scripts/README.md` | 0 |
| A3〜A5 判定スクリプト | 設計 §受け入れ条件 | exit 1 (SKILL.md / README / AGENTS.md の全語が欠落) |
| R5 | `composer test` (撤去前の基準測定) | green: tests=5631 / passed=5629 / skipped=2 / assertions=24741 |

> R5 の基準測定は**撤去前**の状態で開始した (`ScriptsReadmeInventoryTest.php` は存在したまま)。
> 実行中に文書 2 本 (`.claude/skills/app-update-docs/SKILL.md` / `scripts/README.md`) を編集しているが、
> 前者を読む PHP テストは無く、後者を読むのは撤去対象の検査自身
> (`| ` 始まりの表の行だけを解析する = 追記した `>` 引用ブロックは解析対象外) である。
> 実測でも failure 0 件だった。

> **受け入れ条件 A2 の扱い (本ブランチでは未達。クローズ後に達成される後続条件)**:
> 設計の A2 は「履歴以外に名前の参照が残っていない」を
> `':!devnotes' ':!docs/TODO-closed.md'` の 2 つの除外で表していた。
> しかし設計を書いた後に登録された `docs/TODO.md` の作業項目行 (T210) が、
> 撤去対象の**ファイル名そのもの**を作業内容の説明として含んでいる。
> `docs/TODO.md` は履歴ではなく現行の作業一覧だが、**本ブランチでは触らない**規則であり
> (クローズ時に `app-todo-close` が `docs/TODO-closed.md` へ移す = 履歴になる)、
> 実装側で消せる参照ではない。
>
> したがって **A2 は本ブランチでは未達である** (設計どおりのコマンドは 1 件ヒットして exit 0)。
> 条件を実装側で書き換えて達成扱いにはしない。A2 は
> **`app-todo-close` が T210 の行を `docs/TODO-closed.md` へ移した時点で自動的に達成される後続条件**として扱う
> (移動先は設計の A2 が既に除外しているファイルであり、追加の作業は要らない)。
> 本ブランチで確認できるのは、**その 1 件を除いた残存参照が 0 件であること**までである。
> これを A2 とは区別して **A2' (代替確認)** と呼び、下記 §4 に両方の実測を載せる。

## 2. 台帳の実態 (形態 A の実走)

着手前 (作業ツリー無改変) の実測:

```
追跡下: 32 / 表の識別子: 32
--- 未記載 (実体にあるが表に無い) ---
--- 残骸 (表にあるが実体に無い) ---
--- 重複した識別子 ---
--- 空欄・書式不正 ---
```

未記載 0 件 / 残骸 0 件 / 重複 0 件 / 空欄・書式不正 0 件 (受け入れ条件 A6 を満たす)。
実装後 (`scripts/README.md` へ「台帳の対象範囲」を追記した後) も同じ 32 / 32 / 0 / 0 / 0 / 0 であることを再実走で確認した
(追記は `>` 引用ブロックであり、`| ` 始まりの表の行を 1 つも増やさない)。

## 3. 負のコントロール (照合が空振りしていないことの確認)

**作業ツリーには 1 バイトも触っていない。** `mktemp -d` した作業用ディレクトリへ
`scripts/README.md` と `git ls-files scripts/` の出力を複製し、その複製の上だけで崩した。

| # | 崩し方 (複製の上) | 期待 | 実測 |
|---|---|---|---|
| 1 | 表から `phpstan.sh` の行を消す | 未記載 1 件 | `未記載: scripts/phpstan.sh` (追跡下 32 / 識別子 31) |
| 2 | 実在しない `no-such-script.sh` の行を足す | 残骸 1 件 | `残骸: scripts/no-such-script.sh` (追跡下 32 / 識別子 33) |
| 3 | `phpstan.sh` の行を複製する | 重複 1 件 | `重複: scripts/phpstan.sh` (併せて残骸側にも同じパスが出る = 設計の注記どおり) |
| 4 | `phpstan.sh` の実行タイミング列を空にする | 空欄 1 件 | `空欄: phpstan.sh` |

4 ケースとも意図どおり 1 件ずつ検出した (受け入れ条件 A7)。
ケース 3 で残骸側にも同じパスが並ぶのは `comm` が重複行を差分として数えるためで、
スキルの段にもその読み方 (まず重複を畳んでから読み直す) を書いてある。

## 4. 着手後 (緑) の実測

| # | 条件 | 実測 |
|---|---|---|
| A1 | `test ! -e tests/Architecture/ScriptsReadmeInventoryTest.php` | exit 0 (削除済み) |
| A2 (設計どおりの形) | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md'` | **未達**: 1 件 (`docs/TODO.md:32` の T210 作業項目行のみ)。クローズで行が `docs/TODO-closed.md` へ移った時点で 0 件になる後続条件 |
| A2' (代替確認。A2 の達成とは呼ばない) | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md' ':!docs/TODO.md'` | ヒット 0 件 (exit 1) = **実装が消せる参照は 1 件残らず消えている** |
| A3〜A5 | 判定スクリプト (SKILL.md 10 語 / README 3 語 / AGENTS.md 1 語) | exit 0 |
| A6 | 形態 A の実走 | 追跡下 32 / 表の識別子 32 / 未記載 0 / 残骸 0 / 重複 0 / 空欄・書式不正 0 |
| A7 | 負のコントロール 4 ケース | 上記 §3 のとおり検出 |
| A8 | 全検証コマンド | §5 のとおり 10 本すべて exit 0 |

> **A2 だけが本ブランチでは未達**である (上表と §1 のとおり)。他の受け入れ条件はすべて満たしている。

## 5. 全検証コマンドの実測

worktree `.claude/worktrees/tasks/T210` で 10 コマンドを順に実行し、**全て exit 0** を確認した。

| コマンド | 終了 | 実測 |
|---|---|---|
| `composer test` | 0 | tests=5626 / passed=5624 / skipped=2 / assertions=24733 |
| `composer phpstan` | 0 | `[OK] No errors` (level 10) |
| `vendor/bin/pint --test` | 0 | `{"tool":"pint","result":"passed"}` |
| `pnpm lint` | 0 | eslint 指摘なし |
| `pnpm typecheck` | 0 | `tsc --noEmit` 指摘なし |
| `pnpm test` | 0 | Test Files 160 passed / Tests 1967 passed |
| `pnpm build` | 0 | built |
| `pnpm typecheck:packages` | 0 | 指摘なし |
| `pnpm build:packages` | 0 | 成功 |
| `pnpm test:packages` | 0 | Test Files 10 passed / Tests 106 passed |

### 撤去前後の件数差 (受け入れ条件 R5)

| | 撤去前 (着手時に実測) | 撤去後 | 差 |
|---|---|---|---|
| tests | 5631 | 5626 | **-5** |
| passed | 5629 | 5624 | **-5** |
| skipped | 2 | 2 | 0 |
| assertions | 24741 | 24733 | -8 |

減った 5 本は撤去したファイルが定義していたテスト (本体 1 本 + 負のコントロール 4 本) と一致する。
**5 本以外の増減は無い** = 撤去が他の検査を巻き込んでいない。

## 6. 保証しないもの (この記録で主張しないこと)

- 本記録は「撤去後も台帳が実態と一致していること」を**この時点で**確認したにすぎない。
  撤去により毎 push の強制は失われ、以後のドリフトは文書更新スキルを回した時点でしか検出されない。
- 形態 B (記述の実態ずれ) は人が実ファイルで裏取りする手順であり、本記録では
  表の全 32 行を機械的に裏取りしてはいない (本作業は表の内容を 1 行も変更していない)。
- 母集団は git 追跡下である。未追跡のスクリプトは照合の母集団に入らない (現時点で未追跡 0 件)。
- **受け入れ条件 A2 は本ブランチでは達成していない** (§1 / §4 のとおり `docs/TODO.md` の
  作業項目行 1 件が残る)。「残存参照 0 件」と書けるのは `docs/TODO.md` を除いた A2' の範囲までである。
  クローズ (`app-todo-close` が行を `docs/TODO-closed.md` へ移す) の後に A2 が満たされる。
```

## 質問

この扱いで Round 2 の Warning は解消しているか。
残る問題があれば分類して指摘し、全体判定 (APPROVED / CHANGES_REQUESTED) を返してほしい。
