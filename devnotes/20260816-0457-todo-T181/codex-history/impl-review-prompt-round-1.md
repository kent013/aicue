【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーとしての役割

あなたは Laravel + Svelte アプリのコードレビュアーである。以下の実装差分を、詳細設計書と突き合わせてレビューせよ。

### レビュー観点

- **設計との一致性**: 詳細設計書に書かれた振る舞い・保証範囲・「あえて固定しないこと」と実装が一致しているか。設計に無い作業へ広がっていないか
- **正確性**: shell (POSIX sh) の記述に誤りが無いか。特に (a) コマンド置換の中でしか呼ばない前提で `local` 無しの関数を使うこと、(b) glob と `ls -d` の組み合わせ、(c) 代替経路で採用したパスが実在ディレクトリであることの保証
- **PHPStan level 10 適合性**: 新規 PHP テストの型注記 (`list<string>` 等)、`Webmozart\Assert\Assert` による絞り込みが十分か
- **テスト網羅性**: 検出器が空振りしていないか (正のコントロール・負のコントロールの有無)。外部コマンド (`git check-ignore`) の失敗を skip でごまかしていないか。fixture の後始末をしているか
- **セキュリティ**: 除外設定の追加が意図しない範囲を隠していないか。テストが実 `HOME` や実リポジトリを書き換えていないか
- **DESIGN.md 準拠 / Atomic Design 準拠**: 本差分は `resources/js` / `resources/css` を 1 行も触らないため該当なし。該当なしと判断した場合はその旨だけ書けばよい
- **保証範囲の記述**: コメントが実際より強い保証を主張していないか (誇張の検出)

### 出力形式

- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示する

---

## user: 実装の材料

### 背景 (このリポジトリ固有の前提)

- 本リポジトリは laravel-claude-template から生成されている。追従元のソースはこのマシンに無く、
  正典設計は共有台帳 (lctl) の記述としてのみ読める。よって**追従元との byte 一致は確認できないし主張もしない**。
- `scripts/` 配下は `tests/Architecture/ScriptsReadmeInventoryTest.php` が再帰的に全数走査して
  `scripts/README.md` の台帳との一致を deny-by-default で強制する。よってテストファイルにも台帳行が要る。
- vitest の include には `scripts/**/*.test.ts` が既に入っており、`tsconfig.json` の include にも
  `scripts/**/*.ts` が入っている。設定変更は不要である。
- 外部 skill (Stripe 公式) の実体は追跡せず都度取得する側の裁定が出ている。


### 詳細設計書 (devnotes/20260816-0409-p3-housekeeping/detailed-design.md)

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


### 実装差分 (git diff HEAD)

```diff
diff --git a/.gitignore b/.gitignore
index 709d76f..7e431a6 100644
--- a/.gitignore
+++ b/.gitignore
@@ -58,6 +58,9 @@ __pycache__/
 # 外部 skill (skills-lock.json から再インストール可能。.claude/skills/* は symlink)
 /.agents
 /.claude/skills/stripe-*
+# `upgrade-stripe` だけ名前が `stripe-` で始まらず上の glob に入らないため、個別行で閉じる
+# (skills-lock.json の全キーが閉じていることは SkillsLockIgnoreCoverageTest が強制する)
+/.claude/skills/upgrade-stripe
 
 # template repo specific
 /tmp
diff --git a/scripts/README.md b/scripts/README.md
index eae608d..74037b7 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -37,6 +37,7 @@ ## スクリプト一覧
 | `tests/test_bug_hunt_inventory.py` | `bug-hunt-inventory.py` の自己テスト (標準ライブラリのみ)。実 `php` を呼ばず fake scanner で段 1..4 と差し替えの失敗経路を検証する | `composer test` (`tests/Architecture/BughuntInventoryToolSelfTest.php` が起動) |
 | `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する (拒否は終了コード 97。起動子が 97 だけを 2 へ写す)。判定は bash の組み込みだけで完結し、外部コマンドを 1 つも使わない | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
 | `code-review-graph-update-hook.sh` | PostToolUse(Write/Edit) hook。コード索引 (code-review-graph) を `flock` 排他 + 内側 20 秒の時間切れ付きで差分更新する。何が起きても終了コード 0 で終わり、標準出力は常に空。告知はセッションごと・理由ごとに標準エラー 1 行だけ | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
-| `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 | (内部スクリプト) |
+| `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 (2 つの置き場 `~/.vscode` / `~/.vscode-server` から最新版を選ぶ。platform が完全一致する拡張が無ければ拾い直して警告する) | (内部スクリプト) |
+| `claude-wrapper.test.ts` | `claude` の回帰テスト (最新版の選択 / 完全一致が無いときの拾い直しと警告 / 未検出時の終了 / 既定フラグの前置と opt-out / 引数のそのまま転送) | `pnpm test` |
 | `claude-account` | Claude Code のログインアカウントのプロファイル保存・切替・一覧 (Python 3 標準ライブラリのみ)。`(claudeAiOauth, oauthAccount)` のペアを `~/.claude/account-profiles/` に 0600 でスナップショットし、`switch` で書き戻す (切替直前に現アカウントを再スナップショットするのでトークン回転で失効しない)。`add` は使い捨ての `CLAUDE_CONFIG_DIR` で claude を起動し、現ログイン・起動中セッション無影響で別アカウントを登録する。**本リポジトリは `claude-statusline` を持たないため `autosave` の自動呼び出しは効かない** — 登録は `save` / `add` で手動に行う | 人間が実行 (`scripts/claude-account switch` 等) / `switch-account` スキルから |
 | `codex` | Codex CLI を VSCode 拡張のネイティブバイナリ経由で起動。`app-codex-review` / `app-codex-vscode` スキルの呼び出しラッパを兼ねる | スキルから自動呼び出し / 直接起動 |
diff --git a/scripts/claude b/scripts/claude
index c33f941..18de491 100755
--- a/scripts/claude
+++ b/scripts/claude
@@ -17,22 +17,47 @@ esac
 PLATFORM="$OS-$ARCH"
 
 # Search both extension roots (local VSCode and remote vscode-server),
-# pick the highest version across both.
-LATEST_EXT=$(ls -d \
-    "$HOME/.vscode/extensions/anthropic.claude-code-"*"-$PLATFORM" \
-    "$HOME/.vscode-server/extensions/anthropic.claude-code-"*"-$PLATFORM" \
-    2>/dev/null \
-  | sed 's|.*/anthropic\.claude-code-||' \
-  | sort -t- -k1 -V \
-  | tail -1)
-if [ -n "$LATEST_EXT" ]; then
-  for root in "$HOME/.vscode/extensions" "$HOME/.vscode-server/extensions"; do
-    cand="$root/anthropic.claude-code-$LATEST_EXT"
-    [ -d "$cand" ] && LATEST_EXT="$cand" && break
+# pick the highest version across both. The search lives in one function so the
+# exact-match lookup and the fallback cannot drift apart.
+#
+# Argument: the extension directory name suffix. Prints the path of the newest
+# matching extension directory under $HOME, or returns non-zero if there is none.
+# POSIX sh has no `local`, but this function is only ever called inside a command
+# substitution, so its assignments stay in the subshell. Names are prefixed with
+# `_` anyway; do not reuse those names in the caller.
+# Version comparison relies on GNU `sort -V` (same as before this change).
+find_claude_extension() {
+  _suffix="$1"
+  _version=$(ls -d \
+      "$HOME/.vscode/extensions/anthropic.claude-code-"*"$_suffix" \
+      "$HOME/.vscode-server/extensions/anthropic.claude-code-"*"$_suffix" \
+      2>/dev/null \
+    | sed 's|.*/anthropic\.claude-code-||' \
+    | sort -t- -k1 -V \
+    | tail -1)
+  [ -n "$_version" ] || return 1
+  for _root in "$HOME/.vscode/extensions" "$HOME/.vscode-server/extensions"; do
+    if [ -d "$_root/anthropic.claude-code-$_version" ]; then
+      printf '%s\n' "$_root/anthropic.claude-code-$_version"
+      return 0
+    fi
   done
+  return 1
+}
+
+LATEST_EXT=$(find_claude_extension "-$PLATFORM")
+
+if [ -z "$LATEST_EXT" ]; then
+  # No exact match: pick up whatever is installed instead of giving up. This can
+  # grab a binary built for another platform, so always warn.
+  LATEST_EXT=$(find_claude_extension "")
+  if [ -n "$LATEST_EXT" ]; then
+    echo "Warning: no Claude Code extension for platform $PLATFORM;" >&2
+    echo "         falling back to $LATEST_EXT (it may not run on this machine)." >&2
+  fi
 fi
 
-if [ -z "$LATEST_EXT" ] || [ ! -d "$LATEST_EXT" ]; then
+if [ -z "$LATEST_EXT" ]; then
   echo "Error: Claude Code VSCode extension not found (platform: $PLATFORM)." >&2
   echo "Install it from the VSCode marketplace first." >&2
   exit 1
diff --git a/scripts/claude-wrapper.test.ts b/scripts/claude-wrapper.test.ts
new file mode 100644
index 0000000..f3535f9
--- /dev/null
+++ b/scripts/claude-wrapper.test.ts
@@ -0,0 +1,271 @@
+/**
+ * scripts/claude の回帰テスト。
+ *
+ * このラッパは開発者が Claude Code を起動する唯一の入口であり、壊すと開発が止まる。
+ * にもかかわらず引数の再構築 (`eval "set -- $new_args"` とクォートのエスケープ) という
+ * 壊れやすい箇所を誰も検査していなかったので、探索・既定フラグ・引数転送を実プロセスで固定する。
+ *
+ * 作り: 一時ディレクトリに偽の `HOME` と偽の拡張ディレクトリを組み、`scripts/claude` を
+ * 複製して起動する。拡張のネイティブバイナリは「自分のパスと受け取った引数を NUL 区切りで
+ * `$ARGV_OUT` へ書いて exit 0 する」偽物なので、どこまで到達したかと何が渡ったかが分かる。
+ * 偽 `HOME` は毎回 `afterEach` で消す (残骸を作らない)。
+ *
+ * 期待する platform は `uname` を起動して求める。Node の `process.platform` /
+ * `process.arch` から作るとラッパ本体の情報源 (`uname`) とズレた環境
+ * (Rosetta やコンテナ) で正常なラッパが赤くなる。
+ *
+ * **あえて固定しないこと (誇張しない)**:
+ * - 同じ版が `~/.vscode` と `~/.vscode-server` の両方にあるときにどちらを優先するか。
+ *   これは探索ループの順序から生じる副次的性質で、追従元も固定していない。
+ *   下流だけで固定すると追従元が探索順を変えたとき本リポジトリだけ落ちる。
+ * - 代替経路が掴んだバイナリが実際にこの機械で動くこと。代替経路は arch を検査しないので
+ *   異機種のバイナリを起動しうる (`[ -x ]` を通っても `exec` が実行形式の不一致で落ちうる)。
+ *   これは追従元が持つ既知の穴であり、ここだけ塞ぐと新しい乖離になるので塞がない。
+ *   W2 の終了コードが 0 になるのは**テスト用の偽バイナリが 0 で終わるから**であって、
+ *   実機の別 platform のバイナリが動くという意味ではない。
+ * - 版の比較は `sort -t- -k1 -V` (GNU 拡張) に依存する。これは現行ラッパが既に持つ前提で、
+ *   本テストが持ち込むものではないため、macOS 実機での可用性はここでは扱わない。
+ *
+ * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
+ */
+import { afterEach, describe, expect, it } from "vitest";
+import { spawnSync, type SpawnSyncReturns } from "node:child_process";
+import { chmodSync, copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
+import { tmpdir } from "node:os";
+import { dirname, join, resolve } from "node:path";
+
+const REPO_ROOT = process.cwd();
+const WRAPPER_PATH = resolve(REPO_ROOT, "scripts/claude");
+
+/** 偽のネイティブバイナリ。自分のパスと引数を NUL 区切りで書き出して正常終了する。 */
+const RECORDING_BINARY = `#!/bin/sh
+printf '%s\\0' "$0" "$@" > "$ARGV_OUT"
+exit 0
+`;
+
+/** 偽の状態表示行スクリプト (中身は使われない。実行可能かどうかだけが効く)。 */
+const STATUSLINE_STUB = `#!/bin/sh
+exit 0
+`;
+
+const scratchRoots: string[] = [];
+
+afterEach(() => {
+    for (const root of scratchRoots.splice(0)) {
+        rmSync(root, { recursive: true, force: true });
+    }
+});
+
+function uname(flag: string): string {
+    const result = spawnSync("uname", [flag], { encoding: "utf-8" });
+    if (result.status !== 0) throw new Error(`uname ${flag} が失敗した`);
+    return result.stdout.trim();
+}
+
+/** ラッパ本体と同じ写像で platform 文字列を作る (情報源は uname に揃える)。 */
+function expectedPlatform(): string {
+    const os = uname("-s") === "Darwin" ? "darwin" : "linux";
+    const machine = uname("-m");
+    const arch = machine === "x86_64" || machine === "amd64" ? "x64" : "arm64";
+    return `${os}-${arch}`;
+}
+
+/** 期待する platform とは必ず異なる platform 文字列 (代替経路の入力に使う)。 */
+function foreignPlatform(): string {
+    return expectedPlatform() === "linux-arm64" ? "darwin-x64" : "linux-arm64";
+}
+
+interface Scratch {
+    /** 一時ディレクトリの根。afterEach で消す。 */
+    readonly root: string;
+    /** 偽 HOME (絶対パス)。ラッパは $HOME 配下から拡張を探す。 */
+    readonly home: string;
+    /** 複製した scripts/claude の絶対パス。 */
+    readonly wrapper: string;
+    /** 偽バイナリが引数を書き出す先。 */
+    readonly argvOut: string;
+}
+
+function createScratch(): Scratch {
+    const root = mkdtempSync(join(tmpdir(), "claude-wrapper-"));
+    scratchRoots.push(root);
+
+    const home = join(root, "home");
+    mkdirSync(home, { recursive: true });
+
+    const binDir = join(root, "bin");
+    mkdirSync(binDir, { recursive: true });
+    const wrapper = join(binDir, "claude");
+    copyFileSync(WRAPPER_PATH, wrapper);
+    chmodSync(wrapper, 0o755);
+
+    return { root, home, wrapper, argvOut: join(root, "argv") };
+}
+
+/** 偽の状態表示行をラッパと同じディレクトリへ置く (置かなければ注入は起きない)。 */
+function installStatusline(scratch: Scratch): string {
+    const path = join(dirname(scratch.wrapper), "claude-statusline");
+    writeFileSync(path, STATUSLINE_STUB, "utf-8");
+    chmodSync(path, 0o755);
+
+    return path;
+}
+
+interface ExtensionOptions {
+    /** 置き場 (`.vscode` = 手元 / `.vscode-server` = リモート開発機)。 */
+    readonly root: ".vscode" | ".vscode-server";
+    readonly version: string;
+    readonly platform: string;
+    /** ネイティブバイナリの状態。既定は記録する実行可能ファイル。 */
+    readonly binary?: "recording" | "not-executable";
+}
+
+/** 偽の拡張を組み、ネイティブバイナリの絶対パスを返す。 */
+function installExtension(scratch: Scratch, options: ExtensionOptions): string {
+    const extensionDir = join(
+        scratch.home,
+        options.root,
+        "extensions",
+        `anthropic.claude-code-${options.version}-${options.platform}`,
+    );
+    const binary = join(extensionDir, "resources", "native-binary", "claude");
+    mkdirSync(dirname(binary), { recursive: true });
+    writeFileSync(binary, RECORDING_BINARY, "utf-8");
+    chmodSync(binary, options.binary === "not-executable" ? 0o644 : 0o755);
+
+    return binary;
+}
+
+function runWrapper(scratch: Scratch, args: readonly string[] = []): SpawnSyncReturns<string> {
+    return spawnSync(scratch.wrapper, [...args], {
+        env: { ...process.env, HOME: scratch.home, ARGV_OUT: scratch.argvOut },
+        encoding: "utf-8",
+    });
+}
+
+/** 偽バイナリが記録した [起動されたパス, ...引数] を読む。起動されていなければ throw する。 */
+function recordedInvocation(scratch: Scratch): { readonly binary: string; readonly args: string[] } {
+    const raw = readFileSync(scratch.argvOut, "utf-8");
+    const parts = raw.split("\0");
+    parts.pop(); // 末尾の NUL による空要素
+    const binary = parts.shift();
+    if (binary === undefined) throw new Error("記録が空 (偽バイナリが起動されていない)");
+
+    return { binary, args: parts };
+}
+
+describe("scripts/claude の拡張探索", () => {
+    it("W1: 2 つの置き場に別々の版があるとき、版が大きい方のバイナリを起動する", () => {
+        const scratch = createScratch();
+        const platform = expectedPlatform();
+        installExtension(scratch, { root: ".vscode", version: "1.2.3", platform });
+        const newer = installExtension(scratch, { root: ".vscode-server", version: "1.10.0", platform });
+
+        const result = runWrapper(scratch);
+
+        expect(result.status).toBe(0);
+        expect(recordedInvocation(scratch).binary).toBe(newer);
+    });
+
+    it("W2: 完全一致が無ければ別 platform の拡張を拾い直し、期待 platform と採用パスを警告する", () => {
+        const scratch = createScratch();
+        const fallback = installExtension(scratch, {
+            root: ".vscode-server",
+            version: "1.0.0",
+            platform: foreignPlatform(),
+        });
+
+        const result = runWrapper(scratch);
+
+        // 拾い直した拡張のバイナリまで到達している (現行の即 exit 1 との違いはここ)
+        expect(recordedInvocation(scratch).binary).toBe(fallback);
+        expect(result.stderr).toContain(expectedPlatform());
+        expect(result.stderr).toContain(fallback.replace("/resources/native-binary/claude", ""));
+    });
+
+    it("W3: 拡張が 1 つも無ければ platform 名つきのエラーで終了する", () => {
+        const scratch = createScratch();
+
+        const result = runWrapper(scratch);
+
+        expect(result.status).toBe(1);
+        expect(result.stderr).toContain(expectedPlatform());
+    });
+
+    it("W4: ネイティブバイナリが実行可能でなければそのパスを示して終了する", () => {
+        const scratch = createScratch();
+        const binary = installExtension(scratch, {
+            root: ".vscode-server",
+            version: "1.0.0",
+            platform: expectedPlatform(),
+            binary: "not-executable",
+        });
+
+        const result = runWrapper(scratch);
+
+        expect(result.status).toBe(1);
+        expect(result.stderr).toContain(binary);
+    });
+
+    it("W8 負のコントロール: 完全一致で見つかったときは警告を 1 文字も出さない", () => {
+        const scratch = createScratch();
+        installExtension(scratch, { root: ".vscode-server", version: "1.0.0", platform: expectedPlatform() });
+
+        const result = runWrapper(scratch);
+
+        expect(result.status).toBe(0);
+        expect(result.stderr).toBe("");
+    });
+});
+
+describe("scripts/claude の引数の組み立て", () => {
+    function scratchWithExtension(): Scratch {
+        const scratch = createScratch();
+        installExtension(scratch, { root: ".vscode-server", version: "1.0.0", platform: expectedPlatform() });
+
+        return scratch;
+    }
+
+    it("W5: 既定で権限確認の回避を前置し、--no-bypass では前置も転送もしない", () => {
+        const withDefault = scratchWithExtension();
+        expect(runWrapper(withDefault, ["--print"]).status).toBe(0);
+        expect(recordedInvocation(withDefault).args).toEqual(["--dangerously-skip-permissions", "--print"]);
+
+        const optedOut = scratchWithExtension();
+        expect(runWrapper(optedOut, ["--no-bypass", "--print"]).status).toBe(0);
+        expect(recordedInvocation(optedOut).args).toEqual(["--print"]);
+    });
+
+    it("W6: 状態表示行があれば --settings と JSON を前置し、--no-ctx で前置しない", () => {
+        const scratch = scratchWithExtension();
+        const statusline = installStatusline(scratch);
+
+        expect(runWrapper(scratch, ["--print"]).status).toBe(0);
+        expect(recordedInvocation(scratch).args).toEqual([
+            "--dangerously-skip-permissions",
+            "--settings",
+            `{"statusLine":{"type":"command","command":"${statusline}","padding":0}}`,
+            "--print",
+        ]);
+
+        const optedOut = scratchWithExtension();
+        installStatusline(optedOut);
+        expect(runWrapper(optedOut, ["--no-ctx", "--print"]).status).toBe(0);
+        expect(recordedInvocation(optedOut).args).toEqual(["--dangerously-skip-permissions", "--print"]);
+    });
+
+    it("W6: 状態表示行が無ければ --settings は付かない (本リポジトリの実態)", () => {
+        const scratch = scratchWithExtension();
+
+        expect(runWrapper(scratch, ["--print"]).status).toBe(0);
+        expect(recordedInvocation(scratch).args).not.toContain("--settings");
+    });
+
+    it("W7: 壊れやすい引数を順序も内容も変えずに転送する", () => {
+        const scratch = scratchWithExtension();
+        const args = ["", "a b", "it's", '{"a":1}', "--", "日本語 の 引数"];
+
+        expect(runWrapper(scratch, args).status).toBe(0);
+        expect(recordedInvocation(scratch).args).toEqual(["--dangerously-skip-permissions", ...args]);
+    });
+});
diff --git a/tests/Architecture/SkillsLockIgnoreCoverageTest.php b/tests/Architecture/SkillsLockIgnoreCoverageTest.php
new file mode 100644
index 0000000..d134966
--- /dev/null
+++ b/tests/Architecture/SkillsLockIgnoreCoverageTest.php
@@ -0,0 +1,190 @@
+<?php
+
+declare(strict_types=1);
+
+use Webmozart\Assert\Assert;
+
+/*
+ * 版固定ファイル `skills-lock.json` に登録された外部 skill が、**全キー** git の除外規則で
+ * 閉じられていることを deny-by-default で固定する。
+ *
+ * なぜ必要か: 本リポジトリは外部 skill の実体を追跡せず都度取得する側である
+ * (AGENTS.md §設計・TODO・devnotes の運用)。`.gitignore` は
+ * `/.claude/skills/stripe-*` の glob を持つが、登録キー `upgrade-stripe` は名前が
+ * `stripe-` で始まらないためこの glob に入らない。都度取得でスキルを復元した瞬間に
+ * 追跡候補が 1 本開く (誤ってコミットする入口になる)。
+ * 個別行を足すだけでは同じ抜けが再発するので、「登録キーの全数が閉じている」ことを
+ * 機械で固定する。
+ *
+ * 走らせるもの: `git check-ignore --no-index -z --stdin` を 1 回だけ起動し、
+ * **リポジトリルート相対の `.claude/skills/{キー}` だけ**を NUL 区切りで流し込む
+ * (絶対パスや `.gitignore` の行そのものは渡さない)。
+ * `--no-index` は必須である — 付けないと「追跡されているから報告されない」という
+ * **追跡状態**の影響を受けるが、本テストが見たいのは**除外規則そのもの**である。
+ * `.gitignore` の glob 評価を PHP 側で再実装しない (git の挙動とズレたら検査の意味が消える)。
+ *
+ * 保証範囲を誇張しない: 見るのは**版固定ファイルに登録されたキーだけ**である。
+ * `/.agents` や外部コマンドが生成する状態ファイルの除外は本テストの対象外で、
+ * 「外部由来のものが 1 つも追跡されない」とは読めない。
+ *
+ * 本テストは DB を触らない (ファイルと git の読み取りのみ)。
+ */
+
+/**
+ * 登録キーのうち git の除外規則に一致しなかったものを違反として列挙する (純関数)。
+ *
+ * 実ファイルも git も読まないので、正・負のコントロールを fixture で書ける。
+ *
+ * @param  list<string>  $keys  版固定ファイルの登録キー
+ * @param  list<string>  $ignoredPaths  git が「除外される」と答えたリポジトリ相対パス
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function skillsLockIgnoreViolations(array $keys, array $ignoredPaths): array
+{
+    // 空振り防止: 登録キーが 1 件も無いと検査が素通りする (常に緑になる) ため違反にする。
+    if ($keys === []) {
+        return ['L0: skills-lock.json の登録キーが 0 件 (検査が空振りしている)'];
+    }
+
+    $violations = [];
+
+    foreach ($keys as $key) {
+        $path = '.claude/skills/'.$key;
+        if (! in_array($path, $ignoredPaths, true)) {
+            $violations[] = "L1: {$path} が git の除外規則に一致しない"
+                .' (.gitignore に理由コメントつきの行を足すこと)';
+        }
+    }
+
+    return $violations;
+}
+
+/**
+ * `skills-lock.json` の `skills` 直下のキーを昇順で返す。
+ *
+ * @return list<string>
+ */
+function skillsLockKeys(string $lockFilePath): array
+{
+    $json = file_get_contents($lockFilePath);
+    Assert::string($json, "skills-lock.json を読めない: {$lockFilePath}");
+
+    $decoded = json_decode($json, true);
+    Assert::isArray($decoded, 'skills-lock.json が JSON オブジェクトでない');
+    Assert::keyExists($decoded, 'skills', 'skills-lock.json に skills が無い');
+    Assert::isArray($decoded['skills'], 'skills-lock.json の skills がオブジェクトでない');
+
+    $keys = array_keys($decoded['skills']);
+    Assert::allString($keys, 'skills-lock.json の登録キーが文字列でない');
+    sort($keys);
+
+    return array_values($keys);
+}
+
+/**
+ * 渡したパスのうち git が「除外される」と答えたものを返す。
+ *
+ * 失敗したら **skip せず fail** させる (偽グリーン禁止)。`git check-ignore` の終了コードは
+ * 0 = 一致あり / 1 = 一致なし / それ以外 = エラーで、0 と 1 の両方を正常応答として扱う。
+ * NUL 区切りを壊さないため shell を介さず proc_open で引数配列のまま起動する。
+ *
+ * @param  list<string>  $paths  リポジトリルート相対パス
+ * @return list<string>
+ */
+function gitIgnoredPaths(string $repositoryRoot, array $paths): array
+{
+    if ($paths === []) {
+        return [];
+    }
+
+    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+    $process = proc_open(
+        ['git', 'check-ignore', '--no-index', '-z', '--stdin'],
+        $descriptors,
+        $pipes,
+        $repositoryRoot,
+    );
+    Assert::true(is_resource($process), 'git check-ignore を起動できなかった (テスト環境に git が無い?)');
+
+    // 手順を固定する: stdin へ書く → stdin を閉じる → stdout / stderr を読む → proc_close。
+    // 閉じる前に読むと相手が入力待ちのまま止まる。
+    fwrite($pipes[0], implode("\0", $paths)."\0");
+    fclose($pipes[0]);
+
+    $stdout = stream_get_contents($pipes[1]);
+    $stderr = stream_get_contents($pipes[2]);
+    fclose($pipes[1]);
+    fclose($pipes[2]);
+    $exitCode = proc_close($process);
+
+    Assert::inArray(
+        $exitCode,
+        [0, 1],
+        'git check-ignore が失敗した (exit '.$exitCode.'): '.(is_string($stderr) ? $stderr : ''),
+    );
+    Assert::string($stdout, 'git check-ignore の出力を取得できなかった');
+
+    return array_values(array_filter(
+        explode("\0", $stdout),
+        static fn (string $path): bool => $path !== '',
+    ));
+}
+
+// ── L1: 実測 (版固定ファイルの全キーが git の除外規則で閉じている) ──
+
+test('skills-lock.json の全登録キーが git の除外規則で閉じられていること', function (): void {
+    $keys = skillsLockKeys(base_path('skills-lock.json'));
+
+    $violations = skillsLockIgnoreViolations(
+        $keys,
+        gitIgnoredPaths(base_path(), array_map(
+            static fn (string $key): string => '.claude/skills/'.$key,
+            $keys,
+        )),
+    );
+
+    expect($violations)->toBe([], "skills-lock.json の登録キーに除外漏れがあります:\n".implode("\n", $violations));
+});
+
+// ── L2: 外部コマンドが本当に効いていること (空振り検出) ──
+
+test('git check-ignore の呼び出しが除外される path と されない path を区別すること', function (): void {
+    // 負のコントロール込みの前提検査。すべて空を返す / すべて返す実装になっていたら落ちる。
+    $ignored = gitIgnoredPaths(base_path(), ['vendor', 'AGENTS.md']);
+
+    expect($ignored)->toContain('vendor')
+        ->and($ignored)->not->toContain('AGENTS.md');
+});
+
+// ── L3: 正のコントロール (検出器が本当に検出できること) ──
+
+test('L1 正のコントロール: 除外されていない登録キーを検出すること', function (): void {
+    $violations = skillsLockIgnoreViolations(
+        ['stripe-projects', 'upgrade-stripe'],
+        ['.claude/skills/stripe-projects'],
+    );
+
+    expect($violations)->toBe([
+        'L1: .claude/skills/upgrade-stripe が git の除外規則に一致しない'
+            .' (.gitignore に理由コメントつきの行を足すこと)',
+    ]);
+});
+
+test('L0 正のコントロール: 登録キーが 0 件なら空振りとして検出すること', function (): void {
+    expect(skillsLockIgnoreViolations([], []))
+        ->toBe(['L0: skills-lock.json の登録キーが 0 件 (検査が空振りしている)']);
+});
+
+// ── L4: 負のコントロール ──
+
+test('L1 負のコントロール: 全キーが除外されていれば違反が無いこと', function (): void {
+    expect(skillsLockIgnoreViolations(
+        ['stripe-best-practices', 'stripe-projects', 'upgrade-stripe'],
+        [
+            '.claude/skills/stripe-best-practices',
+            '.claude/skills/stripe-projects',
+            '.claude/skills/upgrade-stripe',
+            '.claude/skills/unrelated',
+        ],
+    ))->toBe([]);
+});
```

### テスト結果

- `composer test`: 5311 tests / 5309 passed / 0 failed / 2 skipped
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: exit 0
- `pnpm test`: 138 files / 1551 passed (新規 scripts/claude-wrapper.test.ts の 9 ケースを含む)
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: すべて成功

### 実装前に赤を確認した記録 (テストファースト)

- `tests/Architecture/SkillsLockIgnoreCoverageTest.php` を `.gitignore` 修正**前**に実行 → 5 件中 1 件 fail。
  失敗内容は `L1: .claude/skills/upgrade-stripe が git の除外規則に一致しない`。修正後に 5 件 green。
- `scripts/claude-wrapper.test.ts` を `scripts/claude` 修正**前**に実行 → 9 件中 W2 (拾い直し) が fail
  (偽バイナリが起動されず記録ファイルが存在しない)。修正後に 9 件 green。
  なお W8 (完全一致では警告を 1 文字も出さない) は修正前から緑である
  (現行実装は警告を持たないため)。設計は W8 も赤になると書いていたが実測は緑だった。
  W8 は「新設した警告が通常経路へ漏れないこと」の負のコントロールとして機能する。

### 実 HOME での動作確認

変更前後の `scripts/claude` を、`exec` 行だけ `echo` に差し替えた複製で実 HOME に対して走らせ、
同じネイティブバイナリ (`anthropic.claude-code-2.1.233-linux-arm64`) に解決することを確認した。
