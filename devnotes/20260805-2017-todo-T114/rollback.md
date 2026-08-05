# T114 施策 C1/C2 (git index の NFC/NFD 是正) のロールバック手順

対象コミットは「`doc/reference/` の NFD 形 index entry 58 件を `git rm --cached` で除去し、
`tests/Architecture/GitIndexNormalizationTest.php` を新設したコミット」。

**基点**: `BASE_SHA` は `rollback-base.txt` を参照 (実装開始時 = `7bced53`)。

> **ロールバック手順に破壊的コマンドを既定で置かない**。ロールバックは*復元*操作であり、
> それ自体が新しい損失を生んではならない。`--hard` 系は既定手順から外し、
> **人間の明示承認を要する例外**に限定する。

## 前提: なぜ安全に戻せるか

本施策は **blob を 1 つも削除しない** (index entry の付け替えのみ)。
`index-before.txt` が施策前の全 entry (`<mode> <blob> <stage>\t<path>`、197 行) を保持しており、
blob は object DB に残っているので、最悪でも手順 0 時点の index を機械的に再構成できる。

## R1 — 未コミット (`git rm --cached` 直後)

```bash
git reset HEAD -- doc/reference          # index を HEAD の状態へ復元 (--hard ではない)
git status --porcelain=v1 -uall -- doc/reference   # → 空になること
```

作業ツリーのファイルは一度も触っていないので復元は不要。

## R2 — コミット済み・未マージ (task branch 上)

```bash
git revert <commit>                      # 履歴を残す非破壊のロールバック (原則こちら)
# 直前コミットのみを取り消す場合:
git reset --soft HEAD^                   # 作業ツリーに触れない
```

`git reset --hard` は**使わない** (task branch 上でも未コミットの作業を消しうる)。
必要な場合は**人間の明示承認を得てから**実行する。

## R3 — main へマージ済み

```bash
git revert <merge-commit> -m 1           # index entry が復活する (blob は object DB に残っている)
```

## R4 — 最終手段 (index が壊れた)

```bash
git update-index --index-info < devnotes/20260805-2017-todo-T114/index-before.txt
```

手順 0 で保存した index を丸ごと再構成する (作業ツリーは触らない)。

## 補足: 作業ツリーの on-disk 名について

本 worktree では、**修正前の index から checkout された**ことにより
`doc/reference/mockups/動画アプリ/SP_アプリ/` が **NFD 形のディレクトリ名で作成**されていた
(正規化非依存 lookup の FS なので checkout 時に先に書かれた形が残る)。
index 是正後はこのディレクトリだけが untracked として残るため、
**ディレクトリ名のみを NFC へ改名**した (中身のファイルは触っていない / blob も index も不変)。

修正後の index から checkout する worktree では NFC entry しか存在しないので、
この改名は不要になる (main = `/workspace` の on-disk NFD path は実測 **0 件**だった)。
ロールバック時にこの改名を戻す必要はない (NFC 名は NFD entry からも lookup できる)。

## `core.precomposeunicode` について

`git config core.precomposeunicode true` は**任意の補助手順**であり、
**受入条件にもロールバックにも含めない**。`.git/config` のローカル設定なので
clone した各人が設定しない限り効かず、**リポジトリの恒久対策にはならない**。
恒久対策は index 正規化 + `GitIndexNormalizationTest` の側に置く。

## 是正コミットを既存チェックアウトへ取り込んだときの実測 (main へのマージ時)

**正規化非依存 FS では、マージ直後に `doc/reference/` の実体が 58 件消える。**
git は index から落ちた NFD path を「削除されたファイル」として `unlink` するが、
FS 上ではそれが NFC path と**同一の inode** だからである。

実測 (main へ `git merge todo/T114 --no-ff` した直後):

```
index doc/reference : 139   (正しい)
実体ファイル        : 81    (139 - 58。消えている)
git status          : 58 件の ' D '
on-disk NFD path    : 0
```

**中身は失われていない** (blob は index にも object DB にも残っている)。復旧は 1 コマンド:

```bash
git checkout -- doc/reference
```

実行後の実測: index 139 / 実体 139 / `git status -- doc/reference` **0 行** /
`git diff HEAD -- doc/reference` **0 件** (= バイト一致で復元)。
以後は index に NFC entry しか無いので二度と起きない。
**新規 clone / 新規 worktree では最初から発生しない**
(是正後のコミットから checkout した検証用 worktree は dirty 0 行 / on-disk NFD 0 件)。

この注意書きは `docs/worktree-isolation-strategy.md` にも記載した
(他の開発者が pull したときに同じ状況に遭遇するため)。
