# 詳細設計: 状態表示行 (claude-statusline) の正典取り込み

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

本タスクはアプリ本体 (`app/` / `resources/` / `routes/`) を 1 行も変えない。
開発道具 (`scripts/`) の欠落資産を追従元から取り込むだけである。使命への影響は無い。

### 禁止事項（AGENTS.md より。本設計に効くもの）

- **禁止事項 1 (テストなしの実装完了報告)**: 新規ファイルは既存の deny-by-default 検査
  (`ScriptsReadmeInventoryTest`) の母集団に自動で入る。**fail-first を実測してから**
  台帳行を足す (後述のテスト計画)
- **worktree 運用ルール**: main 直接実装は禁止。`scripts/setup-worktree.sh <task-id>` で作る
- **一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ (昇格時は
  `scripts/README.md` の台帳に追記する)**: 本件はまさにこの追記が必須の経路である

### コーディングルール

- 取り込むファイルは**追従元の byte 列そのまま**である。整形・命名・コメントの規約は
  こちら側で当てない (当てた瞬間に byte 一致が壊れ、目的を失う)
- `declare(strict_types=1)` の全数検査と禁止する文の字句検査は
  **git 追跡下の `*.php`** だけを母集団にするので、Python ファイルは対象外

## 概念設計リファレンス

`devnotes/20260817-1230-claude-statusline-vendoring/conceptual-design.md`

## 変更箇所

| # | ファイル | 種別 | 内容 |
|---|---|---|---|
| 1 | `scripts/claude-statusline` | 新規 | 追従元から byte 一致で取り込む (106 行 / 3623 バイト / mode 100755) |
| 2 | `scripts/README.md` | 変更 | 台帳へ `claude-statusline` の行を 1 行追加する |
| 3 | `scripts/README.md` | 変更 | `claude-account` 行の「本リポジトリは `claude-statusline` を持たないため `autosave` の自動呼び出しは効かない」を削除し、効くようになった旨へ書き換える |
| 4 | `scripts/claude-wrapper.test.ts` | 変更 | W6 の 2 本目のテスト名の注記「(本リポジトリの実態)」が偽になるので直す |

## 1. `scripts/claude-statusline` の取り込み

### 取得手順 (再現できる形で残す)

```sh
gh api repos/rio-development/laravel-claude-template/contents/scripts/claude-statusline \
  --jq '.content' | base64 -d > scripts/claude-statusline
chmod 755 scripts/claude-statusline
```

### 受け入れ条件 (取り込んだものが本当に正典か)

| 検査 | 期待値 |
|---|---|
| md5 | `fa9b3828181b4dec8a487827b728f260` (台帳が差分巡回 2026-08-10 で記録した正典の md5) |
| 行数 / バイト数 | 106 行 / 3623 バイト |
| mode | 755 (起動ラッパが `-x` で判定するため**必須**) |
| 追従元の直近コミット | `e1536708d` (= 台帳が引く `laravel-claude-template@e153670`) |

**md5 が一致しなければ取り込みを中止する。** 一致しないものを置くのは
「4 つ目の別実装を足す」ことと同じで、T181 が避けた害そのものになる。

### 中身 (取り込むものの要約。改変はしない)

- `main()`: 標準入力の JSON を読み、モデル名 / コンテキスト使用率と窓の大きさ / 累計費用 /
  ログイン中アカウントのメールとプランを ` · ` で連結して 1 行出す。
  JSON として読めなければ**何も出さずに戻る** (壊れた入力でラッパを巻き込まない)
- `oauth_account()`: 標準入力にはアカウントが載らないので `<config>/.claude.json` の
  `oauthAccount` を読む (`CLAUDE_CONFIG_DIR` を尊重する)。読めなければ空扱い
- `plan_of()`: `organizationType` と `organizationRateLimitTier` だけからプラン名を作る。
  **鍵束 (Keychain) を触らない** — 描画のたびに走るため
- `autosave_if_new()`: 同一性を `(accountUuid, organizationUuid)` の対で見て、
  保存済みプロファイルに無ければ同ディレクトリの `claude-account autosave` を
  10 秒の時間切れ付きで起こす。失敗は握り潰す (ステータスラインは止めない)

### 依存の確認

`autosave_if_new()` が呼ぶ `claude-account autosave` は本リポジトリに実在する
(`scripts/claude-account` の `cmd_autosave` / `autosave_live`)。**追加の配線は要らない。**

## 2〜3. `scripts/README.md` の台帳更新

`ScriptsReadmeInventoryTest` は `scripts/` 配下を**再帰的に**走査し、
表に無いファイルを違反として挙げる (除外は `README.md` 自身の 1 件だけ)。
したがって行の追加は**任意ではなく必須**である。

追加する行 (`claude` 行と `claude-wrapper.test.ts` 行の並びに合わせる):

```markdown
| `claude-statusline` | Claude Code のステータスライン (Python 3 標準ライブラリのみ)。標準入力の JSON からモデル名・コンテキスト使用率・累計費用を、`<config>/.claude.json` の `oauthAccount` からログイン中アカウントのメールとプランを組み立てて 1 行で出す。未登録のアカウントを見つけたら同ディレクトリの `claude-account autosave` を呼ぶので、`/login` した直後のアカウントが手動操作なしで切替対象に入る | `claude` ラッパが `--settings` で自動注入 (実行ビットが無いと**無音で注入されない**) |
```

`claude-account` 行の書き換え (太字の注記を差し替える):

- 変更前: **本リポジトリは `claude-statusline` を持たないため `autosave` の自動呼び出しは効かない** — 登録は `save` / `add` で手動に行う
- 変更後: `claude-statusline` が描画のたびに未登録アカウントを検出して `autosave` を呼ぶため、`/login` した分は自動で登録される (手動の `save` / `add` も引き続き使える)

## 4. `scripts/claude-wrapper.test.ts` の注記

W6 の 2 本目はテスト名に「(本リポジトリの実態)」と書いてあるが、本タスクの後は偽になる。
**テストの中身は変えない** — このケースは scratch ディレクトリの中だけで完結しており
(`installStatusline()` を呼ばないことで不在を作る)、実ファイルが増えても判定は変わらない。
名前の注記だけを「(不在の環境の負のコントロール)」へ直す。

## テスト計画

### fail-first の実測 (禁止事項 1)

1. `scripts/claude-statusline` **だけ**を置いた状態で
   `composer test -- --filter=ScriptsReadmeInventoryTest` を走らせ、
   「台帳に行が無い」で**赤になることを確認する**
2. 台帳行を足して緑に戻す

この 1 往復が本タスクの fail-first である。新しい検査は作らない
(既存の deny-by-default が既にこの不変条件を持っているため、足すと二重になる)。

### 回帰

| レーン | 期待 |
|---|---|
| `composer test` | 全数 green (`ScriptsReadmeInventoryTest` を含む) |
| `pnpm test` | 全数 green。**特に `claude-wrapper.test.ts` の 9 ケース** (W6 の 2 本が scratch 内で完結しており、実ファイルの出現に影響されないことの実測になる) |
| `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build` | 変更が PHP / TS に及ばないので現状維持の確認 |

### 手で見る確認 (自動レーンの外)

`scripts/claude` を新しいセッションで起動し、ステータスラインに
モデル名 / `ctx N%` / `$N.NN` / メールとプランが出ることを目で見る。
**自動レーンはここを見ない** — ラッパが `--settings` を前置することは W6 が固定するが、
Claude Code 本体がそれを受けて実際に描画することは本リポジトリの検査の外にある。

## リスク

| # | リスク | 扱い |
|---|---|---|
| 1 | mode 644 で置くと**無音で効かない** (症状が変わらないので直したつもりで直らない) | 受け入れ条件に mode 755 を入れ、取り込み直後に `-x` を確認する |
| 2 | 追従元が今後変わっても byte 一致は自動追随しない | 受け入れる。指紋の照合は鏡を持つキュレーター巡回の仕事であり、こちらに突合の機械を作らない (概念設計のスコープ外) |
| 3 | 描画のたびに `claude-account` を子プロセスで起こしうる | 追従元の設計どおり。呼ぶのは**未登録アカウントを見つけたときだけ**で、時間切れ 10 秒と例外の握り潰しが入っている |
| 4 | `~/.claude.json` を描画のたびに読む | 同上。追従元と同じ挙動であり、こちらで変えると byte 一致が壊れる |
| 5 | macOS 実機での動作は確認できない | 本開発機は Linux。**確認していないと書く** (追従元も同じ前提で運用されている) |

## 保証しないこと (誇張しない)

- **描画されることそのもの**は保証しない。保証できるのは「ラッパが設定を前置すること」までで、
  Claude Code 本体の描画は本リポジトリの検査の外である
- **追従元との byte 一致は取り込み時点のもの**である。以後の追随は台帳の巡回に委ねる
- 家系の論点 (状態表示行を必須資産とするか任意とするか) には**答えていない**。
  本タスクは正典形を持つ側へ回るだけで、結論を先取りしない

## やらないこと (理由つき)

- **自作の状態表示行を書く**: 4 つ目の形を増やす害。取り込みが成立した以上、選ぶ理由が無い
- **正典への還流提案の実施**: 取り込みと混ぜると、どちらの検証か分からなくなる
- **`scripts/codex` への波及**: 台帳が byte 一致と観測しているので触らない
- **`.claude/settings.json` への `statusLine` 直書き**: 起動ラッパ経由の注入と二重の正本になる。
  ラッパを使わない起動でも出したいという要求が出てから考える (思考原則 2)
