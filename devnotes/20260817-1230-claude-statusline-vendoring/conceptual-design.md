# 概念設計: 状態表示行 (claude-statusline) の正典取り込み

## 背景・課題

Claude Code のステータスラインが表示されない、という報告から着手した。

### 症状の機序

`scripts/claude` は状態表示行の実行ファイルが**実在して実行可能なときだけ** `--settings` で
`statusLine` の設定を前置する (110 行)。

```sh
if [ "$CTX" = "1" ] && [ -x "$STATUSLINE_BIN" ]; then
  STATUSLINE_JSON='{"statusLine":{"type":"command","command":"'"$STATUSLINE_BIN"'","padding":0}}'
  set -- --settings "$STATUSLINE_JSON" "$@"
fi
```

`STATUSLINE_BIN` は `$SCRIPT_DIR/claude-statusline` を指すが、**このファイルは HEAD に存在しない**
(git の履歴にも 1 度も現れない)。条件が偽になるので設定は前置されず、警告も出ない。
`.claude/settings.json` にも `~/.claude/settings.json` にも `statusLine` の宣言は無い。
したがって**何のエラーも出ないままステータスラインだけが出ない**。

### 台帳 (lctl) の記録

feature `vscode-cli-wrappers` の aicue セルは **`update_pending`** で、残る欠落として
まさにこの 1 点が記録されている (差分巡回 2026-08-16、観測点 aicue@bac558f)。

> **残る欠落**: 状態表示行のスクリプト (`scripts/claude-statusline`) は現 HEAD に不在である。
> 起動ラッパの側は状態表示行の実行ファイルを指す設定を組み立てる記述を持つので、
> 指し先だけが無い状態になっている。

aicue:T181 は同じ feature の他の 2 点 (起動ラッパの乖離・回帰テストの不在) を解消したが、
状態表示行だけは**意図して見送った**。その理由は 2 つである。

1. 正典は 106 行の Python で、**そのソースはこのマシンに無い**
2. 書けば自作の別実装になるが、家系はこの資産で既に 3 状態
   (正典形 / 旧形 / 不在) に割れており、**4 つ目を足すのは害である**

## 前提の再検証 — 見送りの理由は両方とも解消した

本設計で**入手元を特定できた**ため、T181 の見送り根拠は成立しなくなった。

- 入手元: `rio-development/laravel-claude-template`
  (`engraphia/laravel-claude-template` / `engraphia/laravel-claude-template-ledger` にも同一 blob)
- 取得物: 106 行 / 3623 バイト / mode 100755
- **md5 `fa9b3828181b4dec8a487827b728f260`** — これは台帳が差分巡回 2026-08-10 で
  「正典 HEAD と byte 一致」として記録した md5 と**完全一致**する
- 直近コミット `e1536708d` (2026-08-08) = 台帳が引く `laravel-claude-template@e153670`

したがって:

- 理由 1 (ソースが無い) は解消した
- 理由 2 (4 つ目の形が増える) も解消した。**byte 一致で持ち込む**ので新しい形は増えず、
  aicue は 3 状態のうち「正典形」の側へ入る

なお、この家系のリポジトリのうち GitHub から読めるもの (aigenba / motivation-survey /
kent013 側の台帳リポジトリ) は**いずれも 2026-06〜08-07 で更新が止まっており**、
台帳が引く 8 月のコミットを持たない。それらが持つ状態表示行は 41 行の旧形 (md5
`5ca2425062ea7de53b62916ff4c10c4f`) である。**正典として採るのは
`rio-development/laravel-claude-template` の側だけ**とする (md5 が台帳の記録と一致するのはこちら)。

## 改善アイデア

**正典を byte 一致でベンダリングする。自作しない。**

正典の中身は以下を 1 行に連結して出す。

- モデル名 (`display_name` → `id` の順に採る)
- コンテキスト使用率と窓の大きさ (`ctx 42% / 200k`)
- 累計費用 (`$1.23`)
- ログイン中アカウントのメールとプラン (`k.isitoya@gmail.com (max 20x)`)

加えて、**未登録のアカウントを見つけたら同ディレクトリの `claude-account autosave` を呼ぶ**
(ステータスラインは定期的に再実行されるので、`/login` した直後のアカウントが手動操作なしで
切替対象に入る)。

この依存は本リポジトリで**満たされている** — `scripts/claude-account` は `autosave`
サブコマンドを実装済みである (`cmd_autosave` / `autosave_live`)。

## 期待効果

1. ステータスラインが復旧する (報告された症状が直る)
2. `claude-account autosave` の自動呼び出しが効くようになる。
   現在 `scripts/README.md` は「**本リポジトリは `claude-statusline` を持たないため
   `autosave` の自動呼び出しは効かない**」と明記しており、この注記ごと解消する
3. 台帳 `vscode-cli-wrappers` の aicue セルの残欠落 1 件が消える

## 制約・前提

- **byte 一致を保つ**。ローカル改変を 1 文字も入れない
  (入れた瞬間に「4 つ目の形」になり、T181 が避けた害をこちらが作ることになる)
- **実行ビットが要る**。起動ラッパは `-x` で判定するので mode 644 で置くと**無音で効かない**
  (症状が変わらないので、確認を忘れると直したつもりで直っていない)
- 依存する `claude-account autosave` が実在すること — 確認済み
- 禁止する文の検査 (`ForbiddenStatementTokenInvariantTest`) は
  **git 追跡下の `*.php` だけ**を走査するので、Python の `print` は対象外である
  (`declare(strict_types=1)` の全数検査も PHP 限定)

## スコープ外

- **正典への還流提案**。aigenba が挙げた 5 件 (既定経路のテスト / 後始末 / 期待環境名の求め方 /
  `sort -V` の macOS 可用性 / 代替経路の機種検査) は本タスクでは扱わない。取り込みだけを行う
- **状態表示行の内容の改良**。表示項目を足す・減らすのは byte 一致を壊す
- **`scripts/codex`**。台帳が正典群と byte 一致と観測しているので触らない
- **正典の変更への自動追随**。指紋の照合は鏡を持つキュレーター巡回の仕事であり、
  ここで突合の機械を作らない (台帳がその責務を持つ)
- **状態表示行を必須資産とするか任意とするかの家系の判断**。台帳が設計確定へ送って保留中の
  論点であり、本タスクはその結論を先取りしない (どちらに転んでも「正典形を持っている」は不利にならない)
