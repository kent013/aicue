【アプリの使命 (North Star)】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項】
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件に固有の前提 — 必ず踏まえること】
- これは「小さな掃除の束」である。オーバーエンジニアリング禁止 (思考原則 2)。設計を大きくする方向の指摘は、それが本当に必要な理由を示せる場合にのみ行うこと。
- 追従元テンプレート (laravel-claude-template) のソースはこのマシンに存在しない。機能台帳 (lctl) から読めるのは設計・観測・報告のテキストだけで、正典のコードは読めない。
- 施策のうち「実作業が無い / 前提が崩れている / 既存機構と重複する」ものは落とすのが正しい判断である。落とす判断の是非も評価してほしい。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: p3-housekeeping (小さな掃除 3 件の束)

## 背景・課題

lctl 台帳の 3 つの feature について、aicue セルに残っている「数行〜数十行で片づく掃除」を
1 件にまとめて処理する。個別に設計 4 ラウンドと実装セッションを立てるのは過剰である
(思考原則 2)。

着手前に 3 件とも `mcp__lctl__get_feature` で正典設計と全セルの観測を読み、
本リポジトリの HEAD (1b6a041) を実読して前提を検証した。結果、**3 件のうち 2 件だけが
実作業を持ち、1 件は本日入った T176 の構造と衝突するため落とす**。

### 施策 1: stripe-skills-vendoring — 無視設定の欠落行 (実作業あり)

- 台帳の裁定 (2026-08-06) により aicue は**都度取得を維持する側**である。この判断は変えない。
- 版固定ファイル `skills-lock.json` の登録は 3 件
  (`stripe-best-practices` / `stripe-projects` / `upgrade-stripe`)。
  一方 `.gitignore` は `/.claude/skills/stripe-*` の 1 行しか持たない。
  **`upgrade-stripe` だけ名前が `stripe-` で始まらないため、この glob に入らない。**
- 実測: `git check-ignore -v .claude/skills/upgrade-stripe` は exit 1 (どの規則にも一致しない)。
  都度取得でスキルを復元した瞬間に、追跡候補が 1 本開く。
- 正典 (laravel-claude-template) は理由コメントつきの個別行を持ち、さらに
  「版固定ファイルの全キーが除外されている」ことを機械検査で固定している。
- **決済用外部コマンド (Stripe Projects CLI) の既定導入は採らない**。都度取得を維持する裁定に
  従うと、導入本体・退避と復元・3 入口への結線・機械検査 2 本が付いてくる大工事になり、
  本束の趣旨 (小さな掃除) から外れる。

### 施策 2: vscode-cli-wrappers — 起動ラッパの乖離 (一部だけ実作業あり)

- 台帳の aicue セル: 「もう 1 本のラッパ (`scripts/codex`) は正典群と byte 一致だが
  **起動ラッパ (`scripts/claude`) が乖離**しており、状態表示行と回帰テストはいずれの置き場にも
  存在しない」。乖離の中身は「正典が持つ**拡張の探索をまとめた関数**と、
  **環境が完全一致しなければどの環境でも拾い直して警告する代替経路**を持たない旧形」。
- HEAD の `scripts/claude` (94 行) を実読して裏を取った。探索は関数化されておらず本文に直書きで、
  platform 完全一致の拡張が無ければ即 exit 1 で終わる (代替経路が無い)。
- 回帰テストは 0 本。94 行のうち引数の再構築 (`eval "set -- $new_args"`) と
  クォートのエスケープという壊れやすい箇所を、誰も検査していない。
- **状態表示行 (`scripts/claude-statusline`) は本束では作らない** — 理由は後述。

### 施策 3: bughunt-story-structure — 前付けの追加 (落とす)

- 台帳の aicue セル: カード 7 枚 + 書式定義は実在し、前付けは 0 枚。
  手順が route 名で書かれており移行コストは低い側、と観測されている。
- **しかし本日 T176 が bug-hunt 目録を生成器化し、前提が変わった**。現物を読んだ結果:
  - route → シナリオの割当は `inventory/annotations.toml` の `story = "S5"` が正本で、
    生成器がそこから目録の `story` 列を作る。
  - 生成器 `scripts/bug-hunt-inventory.py` は `STORY_IDS = ("S1".."S7")` をリテラルで持ち、
    未知のシナリオ名を exit 3 (ドリフト) で落とす。
  - 正典の前付けは `covers_screens` / `covers_operations` に route 名を持ち、
    目録側の割当列を**前付けから逆引き生成する** = **割当の向きが逆である**。
- したがって正典形の前付けをそのまま入れると、route → シナリオの割当が
  注釈 TOML と前付けの 2 か所に並ぶ。生成器の byte 比較は注釈側しか見ないので、
  食い違っても誰も気づかない。**これは禁じられた二重の正本そのものである。**
- 割当欄を落とした前付け (識別子 / 対象面 / 実行方式 など) だけ入れる案も検討したが、
  読む機械が 1 つも無い宣言が増えるだけで、意味を持たせるには読み取り器と検査 (正典は 1349 行) が
  要る。「小さな掃除」ではなくなる。**本施策は落とす。**

## 改善アイデア

採る施策は 2 つ。どちらも既存の形に沿った最小の追加である。

1. `.gitignore` に欠落している 1 行を理由コメントつきで足し、
   同じ抜けが再発しないよう「版固定ファイルの全キーが除外されている」ことを
   Architecture テストで固定する (正典の検査と同じ関心事)。
2. `scripts/claude` の拡張探索を関数へまとめ、完全一致が無い環境でも拾い直して警告する
   代替経路を足す。あわせて回帰テストを新設し、`scripts/README.md` の台帳に 1 行足す。

## 期待効果

- 使命への貢献は間接的である (どちらも開発の足回りであって製品機能ではない)。
  誇張せずに言うと、次の 2 つの事故を止める。
  - 都度取得でスキルを復元したときに、意図せず追跡候補が 1 本開くこと
    (追跡すべきでないものを誤ってコミットする入口)。今は人が気づくしかない。
  - 拡張の置き場や接尾辞が想定と 1 文字でも違う環境で、開発の入口 (`scripts/claude`) が
    起動不能になること。今は exit 1 で終わり、回避手段が案内されない。
- 台帳の乖離が 2 マス減る (aicue セルの観測が更新できる状態になる)。

## 実装方針（概要）

| # | 変更対象 | 変更の中身 |
|---|---|---|
| 1 | `.gitignore` | `/.claude/skills/upgrade-stripe` を理由コメントつきで追加 |
| 1 | `tests/Architecture/` | 版固定ファイルの全キーが git の除外規則で閉じられていることを検査する新規テスト |
| 2 | `scripts/claude` | 探索の関数化 + 完全一致が無いときの代替経路 (警告つき) |
| 2 | `scripts/claude-wrapper.test.ts` | 新規の回帰テスト (vitest) |
| 2 | `scripts/README.md` | 上記テストファイルの台帳行を追加 |

- 施策 2 のテストの置き場は**正典と同じ `scripts/` 直下**にする。aigenba が
  `tests/js/scripts/` へ動かした理由 3 点は本リポジトリでは成立しない —
  `tsconfig.json` の include に `scripts/**/*.ts` が入っており、
  vitest の include (`scripts/test-inventory-config.ts`) にも `scripts/**/*.test.ts` が入っている。
  同じ置き場の先例も 4 本ある (`audit-gate.test.ts` ほか)。
- `scripts/` 配下は `tests/Architecture/ScriptsReadmeInventoryTest.php` が**再帰的に全数**
  走査して台帳との一致を強制するので、テストファイルにも README 行が要る (既存 4 本と同じ形)。

## 制約・前提

- **正典のソースはこのマシンに無い**。lctl の `get_feature` が返すのは設計・観測・報告であって
  コードではない。したがって施策 2 で到達できるのは**台帳が記述した振る舞いの復元**までで、
  **byte 一致は確認できないし主張もしない**。実装報告にもそう書く
  (byte 差の照合はミラーを持つキュレーター巡回の側でしかできない)。
- `scripts/codex` は台帳が「正典群と byte 一致」と観測している。**触らない**
  (触れば乖離を新設することになる)。
- 施策 2 の代替経路は arch を検査しないため、異機種のバイナリを起動して
  実行形式の不一致で落ちる余地が残る。これは**正典が持つ既知の穴**であり、
  aigenba も「正典が exit 0 を固定しているためローカルでは直せない」と報告している。
  本リポジトリで独自に塞ぐと新しい乖離になるので、**塞がずにテストのコメントへ書き残す**。

## スコープ外

- **状態表示行 (`scripts/claude-statusline`) の新設**。落とす理由は 3 つ。
  (a) 正典は 106 行の Python で、手元にソースが無いため写せない。振る舞いの記述も
  「ログイン中アカウントのメールとプランの表示」「未登録アカウントの自動登録の呼び出し」までしか
  台帳に無く、書けば**自作の別実装**になる。家系はこの資産が既に 3 状態
  (正典形 2 本 / 旧形 2 本 / 不在 2 本) に割れており、4 つ目の形を足すのは害である。
  (b) 台帳自身が「状態表示行を必須資産とするか任意とするかは**本 feature の設計確定へ送る**」と
  書いており、家系の判断がまだ出ていない。
  (c) 不在は既に既知の状態として `scripts/README.md` の `claude-account` 行に
  「本リポジトリは `claude-statusline` を持たないため `autosave` の自動呼び出しは効かない」と
  明記済みで、隠れていない。
- **Stripe Projects CLI の既定導入** (施策 1 のもう一方の未追従点)。都度取得を維持する裁定に従う。
- **bug-hunt シナリオの前付け** (施策 3)。T176 と二重の正本になるため落とす。
- `scripts/codex` の変更、bug-hunt 目録の生成器・注釈 TOML の変更、`docs/TODO.md` の編集。

---

## 参考: 現行コード (レビューの材料)

### scripts/claude (94 行。施策 2 の変更対象)

```sh
#!/bin/sh
# claude: Launch Claude Code using the VSCode extension's native binary.
# Dynamically finds the latest installed version so it follows extension updates.

# Detect OS/arch so this works both locally (macOS, ~/.vscode) and on the
# remote dev box (Linux, ~/.vscode-server).
case "$(uname -s)" in
  Darwin) OS=darwin ;;
  Linux)  OS=linux ;;
  *)      OS=linux ;;
esac
case "$(uname -m)" in
  arm64|aarch64) ARCH=arm64 ;;
  x86_64|amd64)  ARCH=x64 ;;
  *)             ARCH=arm64 ;;
esac
PLATFORM="$OS-$ARCH"

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

CLAUDE="$LATEST_EXT/resources/native-binary/claude"

if [ ! -x "$CLAUDE" ]; then
  echo "Error: Native binary not found at $CLAUDE" >&2
  exit 1
fi

export CLAUDE_CODE_DISABLE_AUTO_MEMORY=1
#export CLAUDE_AUTOCOMPACT_PCT_OVERRIDE=75

# Defaults applied by this wrapper:
#   --dangerously-skip-permissions : bypass permission prompts
#   statusLine via --settings      : show model, context window %, cost in info bar
# Opt-out flags handled (and stripped) by this wrapper:
#   --no-bypass : drop --dangerously-skip-permissions
#   --no-ctx    : drop the injected statusLine config

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
STATUSLINE_BIN="$SCRIPT_DIR/claude-statusline"

BYPASS=1
CTX=1

# Forward all args except our wrapper-only flags
set -- "$@"
forwarded_count=0
# Use a temp positional rebuild
new_args=""
for arg in "$@"; do
  case "$arg" in
    --no-bypass) BYPASS=0 ;;
    --no-ctx)    CTX=0 ;;
    *)
      esc=$(printf '%s' "$arg" | sed "s/'/'\\\\''/g")
      new_args="$new_args '$esc'"
      forwarded_count=$((forwarded_count + 1))
      ;;
  esac
done

eval "set -- $new_args"

# Build prepended flags as proper positional args (so JSON braces don't get
# brace-expanded by the shell when passed inline).
if [ "$CTX" = "1" ] && [ -x "$STATUSLINE_BIN" ]; then
  STATUSLINE_JSON='{"statusLine":{"type":"command","command":"'"$STATUSLINE_BIN"'","padding":0}}'
  set -- --settings "$STATUSLINE_JSON" "$@"
fi

if [ "$BYPASS" = "1" ]; then
  set -- --dangerously-skip-permissions "$@"
fi

exec "$CLAUDE" "$@"
```

### .gitignore の該当部 (施策 1 の変更対象)

```
# 外部 skill (skills-lock.json から再インストール可能。.claude/skills/* は symlink)
/.agents
/.claude/skills/stripe-*
```

### skills-lock.json の登録キー

stripe-best-practices / stripe-projects / upgrade-stripe

### 施策 3 で衝突する側 (T176 が本日入れた構造)

- .claude/skills/app-bug-hunt/inventory/annotations.toml が route ごとに `story = "S5"` を持つ (人が書く正本)
- scripts/bug-hunt-inventory.py が `STORY_IDS = ("S1".."S7")` をリテラルで持ち、注釈から目録の story 列を生成し、生成物と byte 比較する
- 正典の前付けは covers_screens / covers_operations に route 名を持ち、目録の割当列を前付けから逆引き生成する (割当の向きが逆)
