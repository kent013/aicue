# 詳細設計: p3-housekeeping (小さな掃除 2 件 + 落とす判断 1 件)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 本設計は開発の足回り (git の除外設定 / 開発ツールの起動ラッパ) の掃除であり、
> 製品機能には触れない。使命への貢献は間接的である (誇張しない)。

### 禁止事項（AGENTS.md より。本設計に効くもの）

1. テストなしの実装完了報告 (不変条件は対応する Architecture / Feature テストへの登録まで含めて
   「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** テストフレームワーク (`composer test`)。`declare(strict_types=1)` は
  git 追跡下の PHP 全数が対象 (`StrictTypesDeclarationGateTest`)
- 新規 Architecture テストは DB を触らない (ファイル / 外部コマンドの読み取りのみ)
- shell は `#!/bin/sh` (POSIX) を維持する。TypeScript は `pnpm lint` / `pnpm typecheck` を通す
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test`

## 概念設計リファレンス

`devnotes/20260816-0409-p3-housekeeping/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 版固定ファイルの登録キーを git の除外設定で全数閉じる | `.gitignore` / `tests/Architecture/SkillsLockIgnoreCoverageTest.php` (新規) | Low |
| 2 | 起動ラッパの探索を関数化し、完全一致が無い環境の代替経路を足す | `scripts/claude` / `scripts/claude-wrapper.test.ts` (新規) / `scripts/README.md` | Low |
| — | (落とす) bug-hunt シナリオの前付け | 変更なし | — |

---

## 施策 1: 版固定ファイルの登録キーを git の除外設定で全数閉じる

### 変更箇所

- `.gitignore` (58-60 行目の外部 skill の節)
- `tests/Architecture/SkillsLockIgnoreCoverageTest.php` (新規)

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: 新規 1 本 (上記)。既存テストの変更なし
- `scripts/README.md`: なし (`scripts/` 配下を触らないため)

### 現行の状態

`skills-lock.json` の登録キーは 3 件:

```
stripe-best-practices / stripe-projects / upgrade-stripe
```

`.gitignore` (58-60 行目):

```
# 外部 skill (skills-lock.json から再インストール可能。.claude/skills/* は symlink)
/.agents
/.claude/skills/stripe-*
```

`upgrade-stripe` は名前が `stripe-` で始まらないため、この glob に入らない。実測:

```
$ git check-ignore -v .claude/skills/upgrade-stripe
$ echo $?
1   ← どの除外規則にも一致しない
```

都度取得でスキルを復元した瞬間に、`.claude/skills/upgrade-stripe/` が追跡候補として現れる。

### 変更後

```
# 外部 skill (skills-lock.json から再インストール可能。.claude/skills/* は symlink)
/.agents
/.claude/skills/stripe-*
# `upgrade-stripe` だけ名前が `stripe-` で始まらず上の glob に入らないため、個別行で閉じる
# (skills-lock.json の全キーが閉じていることは SkillsLockIgnoreCoverageTest が強制する)
/.claude/skills/upgrade-stripe
```

- 既存 2 行の形は変えない (正典側の検査もキーごとに見る形なので、まとめ直す必要が無い)。
- 登録キーが増えるたびに人が気づけるよう、次のテストで deny-by-default にする。

### 新規テスト `tests/Architecture/SkillsLockIgnoreCoverageTest.php`

**見るもの**: `skills-lock.json` の全登録キー `k` について、`.claude/skills/{k}` が
git の除外規則に一致すること。

**判定の作り** (既存 `GitIndexNormalizationTest` / `ScriptsReadmeInventoryTest` と同じ形):

1. 純関数 `skillsLockIgnoreViolations(array $keys, array $ignoredPaths): array`
   — キー一覧と「git が除外と答えたパス集合」から違反行を作る。実ファイルも git も読まないので
   正・負のコントロールを fixture で書ける。
2. 実測部 — `skills-lock.json` を読み、`skills` のキーを取り出す。
   `git check-ignore --no-index -z --stdin` を `proc_open` で **1 回だけ**起動し、
   NUL 区切りでパスを流し込み、返ってきたパス集合を純関数へ渡す。
3. 正のコントロール: 未登録キーを混ぜた fixture が違反として現れること。
   負のコントロール: 全キーが除外されている fixture では空配列になること。

**外部コマンドの扱い (Codex Round 1 の Warning に対応)**:

- `--no-index` を必ず付ける。付けないと「追跡されているから報告されない」という
  **追跡状態**の影響を受ける。本テストが見たいのは**除外規則そのもの**である。
- `git check-ignore` の終了コードは **0 = 一致あり / 1 = 一致なし / それ以外 = エラー**。
  0 と 1 の両方を正常応答として扱い、それ以外は **skip せず fail** させる (偽グリーン禁止)。
- `proc_open` の手順は **stdin へ書く → stdin を閉じる → stdout / stderr を読む → `proc_close`**
  の順にする (閉じる前に読むと相手が入力待ちのまま止まる)。
- 流し込むのは**リポジトリルート相対の `.claude/skills/{key}` だけ**である
  (絶対パスや `.gitignore` の行そのものは渡さない)。この限定をテスト冒頭のコメントに書く。
- `.gitignore` の glob 評価を PHP 側で再実装しない (git の挙動とズレたら検査の意味が消える)。
- 空振り防止: 登録キーが 1 件も無いときは fail させる
  (`skills-lock.json` が空になったら検査が素通りするため)。
- 保証範囲を誇張しない: 見るのは**版固定ファイルに登録されたキーだけ**である。
  `/.agents` や外部コマンドが生成する状態ファイルの除外は本テストの対象外で、
  「外部由来のものが 1 つも追跡されない」とは読めない。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`@return list<string>` / `@param list<string>`)
- [x] null 安全 — `file_get_contents` / `json_decode` / `proc_open` の戻りは
      `Webmozart\Assert\Assert` で絞る
- [x] DTO を返す設計ではない (Architecture テストのローカル純関数)
- [x] Generics の型パラメータ (`list<string>` / `array<string, string>`) を書く

### テスト計画

- [x] バグ修正の再現: `.gitignore` に行を足す**前**に本テストを走らせて
      `upgrade-stripe` が違反として出ることを確認する (fail を見てから直す。思考原則 5)
- [x] 新規テスト: 実測 1 本 + 正のコントロール 1 本 + 負のコントロール 1 本 + git 起動失敗の扱い
- [x] 既存テストの更新: なし
- [x] 個別の `DatabaseTransactions` を使わない (DB を触らない)
- [x] 実行: `composer test`

### リスク

- git が無い環境ではテストが fail する。既存の `GitIndexNormalizationTest` が同じ前提に
  立っており、本リポジトリの開発コンテナ・CI はどちらも git を持つので後退にはならない。
- 将来 `skills-lock.json` が正典と同じ新形式 (取得元の種別 / スキルのパス指定) へ再生成されても、
  キーの位置 (`skills` 直下) は変わらないため本テストはそのまま効く。

---

## 施策 2: 起動ラッパの探索を関数化し、完全一致が無い環境の代替経路を足す

### 変更箇所

- `scripts/claude` (19-39 行目の探索部)
- `scripts/claude-wrapper.test.ts` (新規)
- `scripts/README.md` (`claude` 行の用途を更新 + 新規テストの台帳行を追加)

### 波及変更

- TypeScript 型定義: なし (テスト自身が `.ts`。`tsconfig.json` の include に
  `scripts/**/*.ts` が既に入っているので設定変更は不要)
- vitest の収集: `scripts/test-inventory-config.ts` の root project が
  `scripts/**/*.test.ts` を既に含むので**設定変更は不要**。
  `scripts/vitest-inventory-gate.test.ts` が自動で拾う
- テストファイル: 新規 1 本
- `scripts/README.md`: **必須** — `ScriptsReadmeInventoryTest` が `scripts/` 配下を
  再帰的に全数走査して台帳との一致を強制するため、テストファイルにも 1 行が要る

### 現行コード (`scripts/claude` 19-39 行)

```sh
# Search both extension roots (local VSCode and remote vscode-server),
# pick the highest version across both.
LATEST_EXT=$(ls -d \
    "$HOME/.vscode/extensions/anthropic.claude-code-"*"-$PLATFORM" \
    "$HOME/.vscode-server/extensions/anthropic.claude-code-"*"-$PLATFORM" \
    2>/dev/null \
  | sed 's|.*/anthropic\.claude-code-||' \
  | sort -t- -k1 -V \
  | tail -1)
if [ -n "$LATEST_EXT" ]; then
  for root in "$HOME/.vscode/extensions" "$HOME/.vscode-server/extensions"; do
    cand="$root/anthropic.claude-code-$LATEST_EXT"
    [ -d "$cand" ] && LATEST_EXT="$cand" && break
  done
fi

if [ -z "$LATEST_EXT" ] || [ ! -d "$LATEST_EXT" ]; then
  echo "Error: Claude Code VSCode extension not found (platform: $PLATFORM)." >&2
  echo "Install it from the VSCode marketplace first." >&2
  exit 1
fi
```

探索が本文へ直書きで、platform 完全一致が無ければ即 exit 1 で終わる (代替経路が無い)。

### 変更後コード

```sh
# 拡張の探索は 1 か所にまとめる (完全一致の探索と拾い直しで同じ規則を 2 度書かないため)。
# 引数は拡張ディレクトリ名の接尾辞。最も版が新しい拡張ディレクトリの $HOME 配下のパスを
# 標準出力へ返し、1 つも無ければ非ゼロで返る。
# POSIX sh に local は無いが、この関数はコマンド置換の中でのみ呼ぶため変数代入は親シェルへ
# 漏れない。将来の直接呼び出しとの衝突を避けるため関数内の変数は `_` 始まりとし、
# 呼び出し側では同じ名前を使わない。版の比較は GNU の `sort -V` に依存する (現行ラッパと同じ)。
find_claude_extension() {
  _suffix="$1"
  _version=$(ls -d \
      "$HOME/.vscode/extensions/anthropic.claude-code-"*"$_suffix" \
      "$HOME/.vscode-server/extensions/anthropic.claude-code-"*"$_suffix" \
      2>/dev/null \
    | sed 's|.*/anthropic\.claude-code-||' \
    | sort -t- -k1 -V \
    | tail -1)
  [ -n "$_version" ] || return 1
  for _root in "$HOME/.vscode/extensions" "$HOME/.vscode-server/extensions"; do
    if [ -d "$_root/anthropic.claude-code-$_version" ]; then
      printf '%s\n' "$_root/anthropic.claude-code-$_version"
      return 0
    fi
  done
  return 1
}

LATEST_EXT=$(find_claude_extension "-$PLATFORM")

if [ -z "$LATEST_EXT" ]; then
  # 環境が完全一致しないときは諦めずに拾い直す。別 platform のバイナリを掴みうるので必ず警告する。
  LATEST_EXT=$(find_claude_extension "")
  if [ -n "$LATEST_EXT" ]; then
    echo "Warning: no Claude Code extension for platform $PLATFORM;" >&2
    echo "         falling back to $LATEST_EXT (it may not run on this machine)." >&2
  fi
fi

if [ -z "$LATEST_EXT" ]; then
  echo "Error: Claude Code VSCode extension not found (platform: $PLATFORM)." >&2
  echo "Install it from the VSCode marketplace first." >&2
  exit 1
fi
```

- 探索規則 (2 系統の横断 / 版の比較 / ディレクトリの復元) が関数 1 つに収まり、
  完全一致と拾い直しで**同じ規則**が使われる。
- 関数は実在するディレクトリしか返さないので、後段の `[ ! -d … ]` は不要になる。
- 警告文には **期待した platform** と **採用した拡張のパス** の 2 つを必ず入れる
  (Codex Round 1 の Warning に対応)。
- 返すのは `$HOME` 配下のパスであり、**それが絶対パスであることは `$HOME` が絶対パスで
  設定されていることに依存する** (現行ラッパが `$HOME` から組み立てているのと同じ前提で、
  本束が新しく持ち込む前提ではない)。「任意の環境で絶対パスを返す」とは書かない。
  回帰テストは絶対パスの偽 `HOME` を使う (Codex Round 2 の Warning に対応)。
- `#!/bin/sh` を維持する。POSIX sh に `local` が無く関数内の変数はグローバルになるが、
  **この関数はコマンド置換 (`$( … )`) の中でしか呼ばない**ので、代入は部分シェルに閉じ、
  呼び出し側のシェルへは漏れない。それでも将来の追記で取り違えないよう変数名は `_` 始まりにし、
  「呼び出し側で `_` 始まりの名前を使わない」旨をコメントに残す (Codex Round 1 の Warning)。
- 版の比較は現行ラッパと同じ `sort -t- -k1 -V` で、**GNU の `sort -V` に依存する**。
  この前提は本束が持ち込むものではないため変えない (テスト側のコメントにも書く)。
- 41 行目以降 (`CLAUDE="$LATEST_EXT/resources/native-binary/claude"` 以降) は**触らない**。

### 新規テスト `scripts/claude-wrapper.test.ts`

**実行の作り**: 一時ディレクトリに偽の `HOME` を組み、偽の拡張ディレクトリと
偽のネイティブバイナリ (受け取った引数を NUL 区切りで `$ARGV_OUT` へ書いて exit 0 する
sh スクリプト) を置く。`scripts/claude` を一時ディレクトリへ複製して `spawnSync` で起動し、
終了コード・stderr・記録された引数列を検査する。

- **後始末をする** — `afterEach` で一時ディレクトリを消す
  (aigenba が正典への還流提案 (ii) として挙げた点。作りっぱなしの残骸を作らない)。
- **期待 platform は `uname` を spawn して求める** — Node の `process.platform` /
  `process.arch` から作るとラッパ本体の情報源 (`uname`) とズレた環境で偽陽性になる
  (同 (iii))。
- `--no-ctx` を付けない既定経路も必ず通す (同 (i))。statusline の有無は、複製先に
  偽の `claude-statusline` を置くかどうかで作る。

| # | ケース | 固定する内容 |
|---|--------|------------|
| W1 | 2 つの置き場に別々の版があるとき | 版が大きい方の拡張のバイナリが起動される |
| W2 | 完全一致が無く別 platform の拡張だけがあるとき | **拾い直した拡張のバイナリまで到達する** (偽バイナリが引数を記録する) / stderr に期待 platform と採用パスの両方が出る。終了コードが 0 になるのは**テスト用の偽バイナリが 0 で終わるから**であって、実機の別 platform バイナリが動くという意味ではない |
| W3 | 拡張が 1 つも無いとき | exit 1 / stderr のエラー文に platform 名が出る |
| W4 | 拡張はあるがネイティブバイナリが実行可能でないとき | exit 1 / そのパスがメッセージに出る |
| W5 | 既定の bypass | 先頭に `--dangerously-skip-permissions` が付く。`--no-bypass` を渡すと付かず、`--no-bypass` 自体も転送されない |
| W6 | statusline の注入 | 複製先に実行可能な `claude-statusline` があると `--settings` と JSON が前置される。`--no-ctx` で付かない。**statusline が無ければ付かない** (本リポジトリの実態) |
| W7 | 引数のそのまま転送 | 空文字 / 空白入り / シングルクォート入り / `{"a":1}` 風 / `--` / 日本語 が、順序も内容も変わらず渡る |
| W8 | 負のコントロール | 完全一致で見つかった通常経路では warning を **1 文字も出さない** |

**あえて固定しないこと (誇張しない)**:

- 同じ版が両方の置き場にあるときにどちらを優先するか。これは正典の for ループ順から生じる
  副次的性質で、**正典自身が固定していない**。下流だけで固定すると正典が探索順を変えたときに
  本リポジトリだけ落ちる (aigenba が同じ理由で pin しないと判断した先例がある)。
- 代替経路が掴んだバイナリが**実際にこの機械で動くこと**。代替経路は arch を検査しないので
  異機種のバイナリを起動しうる (`[ -x ]` を通っても `exec` が実行形式の不一致で落ちうる)。
  これは**正典が持つ既知の穴**であり、正典が代替経路の成立を固定している以上ここだけ塞ぐと
  新しい乖離になる。**塞がずにテストのコメントへ書き残す**。
- `sort -V` が無い環境での版の比較。現行ラッパと同じ GNU 前提であることをコメントに書くに留める。

### `scripts/README.md` の追記

台帳の表に 1 行足し、`claude` 行の用途を代替経路の分だけ書き足す:

```
| `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 (2 つの置き場から最新版を選ぶ。platform が完全一致する拡張が無ければ拾い直して警告する) | (内部スクリプト) |
| `claude-wrapper.test.ts` | `claude` の回帰テスト (最新版の選択 / 完全一致が無いときの拾い直しと警告 / 未検出時の終了 / 既定フラグの前置と opt-out / 引数のそのまま転送) | `pnpm test` |
```

### PHPStan 適合チェック

- 本施策に PHP の変更は無い (shell + TypeScript のみ)。`pnpm lint` / `pnpm typecheck` /
  `pnpm test` を通す。

### テスト計画

- [x] 先に fail を見る: W2 (拾い直し) と W8 (警告を出さない) は現行 `scripts/claude` に対して
      赤になる。赤を確認してから実装へ入る (思考原則 5)
- [x] 新規テスト: 上表 W1..W8 の 8 ケース
- [x] 既存テストの更新: なし。ただし `ScriptsReadmeInventoryTest` (PHP) と
      `vitest-inventory-gate.test.ts` は**新規ファイルによって自動的に検査対象が増える**ので、
      `composer test` と `pnpm test` の両方を通すこと
- [x] 個別の `DatabaseTransactions` を使わない (DB を触らない)

### リスク

- 代替経路が「動かないバイナリを起動して分かりにくく失敗する」形になりうる。
  現行は exit 1 で終わるので、**失敗の見え方が変わる**。警告を必ず出すことで緩和する。
- ラッパは開発者が毎回使う入口なので、壊すと開発が止まる。回帰テストを先に置き、
  変更は探索部 (19-39 行) に閉じる。
- **byte 一致は確認できない**。正典のソースはこのマシンに無く、台帳が返すのは記述だけである。
  到達できるのは振る舞いの収束までで、実装報告にもそう書く
  (byte 差の照合はミラーを持つキュレーター巡回の側でしかできない)。

---

## 落とす判断: bug-hunt シナリオの前付け

**結論: 落とす。実装しない。**

台帳 (領域深掘り 2026-08-14) は「カード 7 枚 + 書式定義は実在するが前付けが 0 枚」
「手順が route 名で書かれており移行コストが低い側」と観測している。しかし**本日 T176 が
bug-hunt 目録を生成器化したことで前提が変わった**。現物を読んで確かめた事実:

1. route → シナリオの割当の正本は `.claude/skills/app-bug-hunt/inventory/annotations.toml` の
   `story = "S5"` である。生成器がここから目録 (`screens.md` / `operations.md`) の
   `story` 列を作り、生成物と byte 比較する。
2. 生成器 `scripts/bug-hunt-inventory.py` は `STORY_IDS = ("S1".."S7")` をリテラルで持ち、
   未知のシナリオ名を exit 3 (ドリフト) で落とす。
3. 正典の前付けは `covers_screens` / `covers_operations` に route 名を持ち、
   **目録側の割当列を前付けから逆引き生成する**。すなわち割当の向きが本リポジトリと逆である。

正典形の前付けをそのまま入れると、route → シナリオの割当が注釈 TOML と前付けの 2 か所に並ぶ。
生成器の byte 比較は注釈側しか見ないので、食い違っても誰も気づかない。
**これは禁じられた二重の正本そのものである** (AGENTS.md「二重の正本を作らない」)。

割当欄 (`covers_*`) を落とした前付けだけを入れる案も検討したが、読む機械が 1 つも無い宣言が
増えるだけで、意味を持たせるには読み取り器と検査 (正典は 1349 行) が要る。
「小さな掃除」ではなくなるので採らない (思考原則 2)。

**申し送り (実装するのではなく、台帳へ報告する材料)**: シナリオ識別子の集合 `S1..S7` は
現在 4 か所 (生成器のリテラル / `scripts/bug-hunt-shard.sh` の `stories_for_shard` /
`stories/README.md` の表 / `stories/` の実ファイル名) に分かれて存在する。
これは本 feature ではなく `bughunt-inventory-generation` / `bug-hunt-exec-infra` の関心事であり、
**本束では触らない**。

---

## やらないこと (理由つき)

| やらないこと | 理由 |
|---|---|
| `scripts/claude-statusline` の新設 | 正典は 106 行の Python でソースが手元に無く、書けば自作の別実装になる。家系はこの資産が既に 3 状態に割れており 4 つ目を足すのは害。台帳自身が「必須資産とするか任意とするかは設計確定へ送る」と書いており家系の判断が未了。不在は `scripts/README.md` に明記済みで隠れていない |
| 決済用外部コマンド (Stripe Projects CLI) の既定導入 | 都度取得を維持する裁定 (2026-08-06) に従う。導入本体・退避と復元・3 入口への結線・機械検査が付いてくる大工事で、本束の趣旨から外れる |
| `scripts/codex` の変更 | 台帳が「正典群と byte 一致」と観測している。触れば乖離を新設することになる |
| shell の `echo` を `printf` へ寄せる | 禁止文の走査対象は git 追跡下の `*.php` 全件であり shell は母集団外。既存ファイルは `echo … >&2` で統一されており、新しい行だけ形を変えると二形になる |
| `sort -V` の macOS 可搬性への対処 | 現行 `scripts/claude` に既にある形で本束が持ち込む依存ではない。手元は Linux で macOS 実機の確認手段が無い。当て推量で直さない (実装時の申し送りとして残す) |
| `eval "set -- $new_args"` の構造変更 | 正典由来の形。正典のソースが読めない状態で構造を変えると新しい乖離になる。bash 配列化は `#!/bin/sh` を捨てる後退である。代わりに壊れやすい入力を W7 で固定する |
| `docs/TODO.md` の編集 / lctl 台帳への書き込み | 本設計の責務外 (後段でまとめて採番登録する / 報告は監督セッションの責務) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は 5 ファイル・数十行で、施策 1 (`.gitignore` + Architecture テスト) と施策 2 (`scripts/` 配下) は互いに独立している。新規ファイル 2 本と既存 2 ファイルへの局所追記だけで、既存の実装コードには 1 行も触れない |
| 競合リスク | 低い。`app/` `resources/` `routes/` `database/` `config/` を 1 行も触らない。`scripts/README.md` の表と `.gitignore` は他タスクも触りうるが、追記位置が離れており衝突しても解決は容易。テストレーンはホスト全体で直列化されるので、`composer test` / `pnpm test` は待ち時間が出るのが正常 |
