## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えてから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。本件は開発基盤 (Claude Code の hook 配線 + bash スクリプト + Architecture テスト) の設計です。

【前提環境】
- PHP 8.4 + Laravel 12 + PHPStan level 10 + Pest
- Linux 開発コンテナ (bash 5 / flock / timeout / python3 あり)
- Claude Code の hook 仕様: PreToolUse の終了コード 2 がツール呼び出しをブロックする。それ以外の非ゼロはブロックしない。設定はセッション開始時に 1 度だけ読まれる。hook の command 文字列はシェル経由で実行され、$CLAUDE_PROJECT_DIR が環境変数として渡される

【レビュー観点】
1. シェルスクリプトの正確性 (ロジックエラー、エッジケース、fail-open / fail-closed の向き)
2. 既存コードとの整合性 (命名規約、パターン)
3. PHPStan level 10 適合性 (Architecture テスト側)
4. テスト計画の網羅性 (各施策にテストがあるか。実起動層が本当に不変条件を固定できているか)
5. 副作用・後退リスク (とくに「hook の故障がセッション全体を壊す」経路が残っていないか)
6. セキュリティ (symlink / TOCTOU / パス traversal / 検索パス汚染 / 排他)
7. 保証範囲の記述が誇張になっていないか
8. オーバーエンジニアリングになっていないか (今必要なものだけか)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 概念設計 (承認済み。参考)

# 概念設計: claude-hooks-settings-wiring

対象 lctl feature: `claude-hooks-wiring` + `code-index-update-hook` (2 件を 1 タスクに束ねる)

## 背景・課題

### 観測された事実 (HEAD 8f0dddd で再確認済み)

1. **`.claude/settings.json` が存在しない**。追跡されている設定は
   `.claude/settings.bughunt-hook.example.json` (見本) だけで、**常設された hook は 0 本**である。
2. その結果、既に存在する `scripts/bughunt-worktree-hook.sh` (bug-hunt の main 直叩きを
   止める PreToolUse ガード) は**誰の手元でも 1 度も起動していない**。見本ファイル自身が
   「ユーザーが手動でマージすること」と書いたまま放置されている。
3. **コード索引 (code-review-graph) の自動更新が無い**。`AGENTS.md` §コードベース探索は
   「自プロジェクトのコードベース探索は code-review-graph MCP を優先する」と規約化しているのに、
   索引を最新に保つ仕組みは「hook で自動更新されない場合 `code-review-graph update`」という
   人手前提の 1 行しかない。索引更新スクリプトの実体も無い。
4. 索引ツールは `docker/Dockerfile` で導入されていない。この開発コンテナに入っているのは
   手で `uv tool install` した結果であり、コンテナを作り直すと消える。

### なぜ 2 feature を 1 件に束ねるか

どちらも**「`.claude/settings.json` が無い」という同じ 1 つの穴**に帰着する。別々の TODO にすると
同じファイルを 2 回作ることになり、2 本目が 1 本目の全数申告の台帳を壊す。lctl の台帳でも
`code-index-update-hook` は `claude-hooks-wiring` に `depends_on` を宣言しており
(配線が無ければ hook 本体は起動しない)、実装順序は分離できない。

### 何が損なわれているか

- **ガードが効いていない**: bug-hunt の main 直叩きは実際に発生した事故 (スクリプト冒頭のコメントが
  実発生の run を名指ししている)。権威層 (`bug-hunt-shard.sh` 本体の `assert_worktree_context`) は
  残っているが、早期に気づける層は結線されていないので機能していない。
- **索引が育たない**: 探索の第一選択と規約が定めた MCP に、更新されない索引が供給される。
  追従元の別リポジトリでは、この形の無音停止が**約 1 か月**続いた実測がある
  (更新コマンドが未導入で 127 を返すのを `|| true` が握り潰していた)。
- **家系の中で aicue だけが t0 に取り残されている**: 追従元 (laravel-claude-template) を含む
  4 リポジトリが常設方式に到達済みで、見本ファイル方式のまま残っているのは aicue と
  もう 1 つだけである。

## 改善アイデア

`.claude/settings.json` を新設し、**hook を 2 本だけ常設**する。あわせて索引更新スクリプトの実体を
新設し、配線そのものを Architecture テストで台帳化する。見本ファイルは同じ変更で削除する
(思考原則 3「後方互換の並走を残さない」)。

| # | やること | 対応する正典要求 |
|---|---|---|
| 1 | `.claude/settings.json` 新設・hook 2 本常設・起動子は絶対パス | claude-hooks-wiring t1 |
| 2 | 見本 `.claude/settings.bughunt-hook.example.json` を削除 | 同上 (並走を残さない) |
| 3 | `scripts/code-review-graph-update-hook.sh` 新設 (実行契約つき) | code-index-update-hook v1 (1) |
| 4 | 検索パス安全化・作業ファイル置き場の symlink 拒否 | claude-hooks-wiring t2 |
| 5 | 配線の台帳化 (`tests/Architecture/ClaudeHooksWiringTest.php`) | claude-hooks-wiring t2 |
| 6 | 索引ツールの導入必須化 (Dockerfile 版固定 + 文書) | code-index-update-hook v1 (2) |
| 7 | 索引ツール自身に配線を書かせない規約の明文化 | code-index-update-hook v1 (3) |

### 最重要の設計原則: 失敗しても作業を止めない

`.claude/settings.json` の hooks は**このリポジトリで動く LLM セッション全体の挙動を変える**。
hook がツール呼び出しを止めてしまうと、以降のあらゆる作業が壊れる。したがって次を不変条件に置く。

**(a) ブロックする力を起動子の側で封じる。** Claude Code では PreToolUse の**終了コード 2 だけ**が
ツール呼び出しを止める。ところが bash は**構文エラーでも 2 を返す**。つまり hook スクリプトに
タイプミスを 1 つ入れただけで、そのセッションの Bash が全滅する。これをスクリプトの正しさに
委ねてはならないので、`.claude/settings.json` の起動文字列そのものを**終了コードの写像器**にする。

写像器は (i) 起動先の検証、(ii) 終了コードの写像、の 2 つだけを行う。

```
d=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/bughunt-worktree-hook.sh; s=0
if [ -n "$d" ] && 絶対パス($d) && ".." を含まない($d) \
   && [ -d "$d/scripts" ] && [ ! -L "$d/scripts" ] && [ -f "$f" ] && [ ! -L "$f" ]; then
  /bin/bash -p "$f"; s=$?
fi
if [ "$s" = 97 ]; then exit 2; fi
exit 0
```

- **起動先の検証**: `CLAUDE_PROJECT_DIR` が空・相対パス・`..` を含む場合、`scripts/` が
  ディレクトリでない・symlink である場合、組み立てたパスが通常ファイルでない・symlink である
  場合は、**内側を起動せずに** 0 で終える。これが無いと、相対値や差し替えられた `scripts/` の
  ときに別の場所の同名スクリプトが起動され、それが 97 を返すことで無関係な Bash 呼び出しを
  ブロックできてしまう。
- スクリプトが**意図して 97 を返したときだけ** 2 になる。構文エラー (2)・ファイル不在 (127)・
  実行不能 (126)・予期しない失敗はすべて 0 に畳まれ、ブロックしない。
- 写像器は設定ファイルに直書きされ、台帳テストが完全一致で固定する。**スクリプト側の退行から
  独立している**ことが、この形の値打ちである。
- 外部コマンドを 1 つも使わない (`[` / `exit` は組み込み)。
- PostToolUse 側も同型にして、末尾を無条件の `exit 0` にする。**索引更新 hook は、
  スクリプトが存在しなくても壊れていても、編集作業を止められない**。
- 代償: ガードのスクリプトが壊れていると拒否が黙って止まる。これは意図した取引である
  — 権威層は `bug-hunt-shard.sh` 本体の `assert_worktree_context` であり、本 hook は
  早期に気づける層にすぎない。スクリプトの実在と構文は静的層の検査が守る。

**(b) 索引更新 hook は、何が起きても終了コード 0 で終わり、標準出力に何も出さない。**
索引ツールが無い・壊れた JSON が来た・検索パスが敵対的・ロックが取れない・更新が終わらない
— そのすべてで編集作業は素通りする。告知が要る場合は**標準エラーに 1 行だけ**出す。

**(c) ガードは判定を bash の組み込みだけで完結させる。** 判定経路に外部コマンドを置くと、
検索パスが壊れているだけで拒否対象が黙って通る (追従元で実測された欠陥)。組み込みだけで
判定すればこの穴自体が無くなる。あわせて、`bug-hunt-shard.sh` に言及しない払い出しは
**外部コマンドを 1 つも起こさずに** 0 で抜けるので、無関係なコマンドは構造的に影響を受けない。

### PreToolUse ガードの失敗モード (この表を実装と台帳テストで固定する)

| 状況 | 判定 | 理由 |
|---|---|---|
| 標準入力が空 / 読めない | **通す** | `bug-hunt-shard.sh` を含まない = 影響半径の外 |
| JSON でない・壊れている。`bug-hunt-shard.sh` を含まない | **通す** | 同上。組み込みの部分一致 1 回で抜ける |
| JSON でない・壊れている。`bug-hunt-shard.sh <空白> provision` を含み許可シグナルが無い | **拒否 (97→2)** | 既に疑わしい集合の中でだけ拒否側へ倒す。`BUGHUNT_ALLOW_MAIN=1` で解除できる |
| `"command"` の値を抽出できた | 抽出値だけで判定 (許可シグナル 2 種とも有効) | 現行スクリプトと同じ意味。説明文への言及では誤発火しない |
| 抽出できない。生入力に `BUGHUNT_ALLOW_MAIN=` がある | **通す** | 明示の解除は逃げ道として残す (詰みを作らない)。ただしどのフィールドにあっても成立する = 限定的な fail-open (受容) |
| 抽出できない。上記が無く対象語を含む | **拒否 (97→2)** | 偶然混入しうる痕跡 (`.claude/worktrees/`) は抽出失敗時には評価しない |
| 入力が 1 MiB を超える / 標準入力が閉じられない | 先頭 1 MiB または 5 秒で**読み取りを打ち切り**、読めた分で判定 | 待ち時間・メモリ・正規表現の実行時間に上限を置く |
| 改行・エスケープ・多バイト文字を含む | `LC_ALL=C` のバイト列として照合 | 探す語 `bug-hunt-shard.sh` に `/` は無いので `\/` 形式の影響を受けない。許可シグナル側は `/` の前の `\` を任意にして受ける。`\uXXXX` 形式は取りこぼす (受容。下記「保証しないもの」) |
| `CLAUDE_PROJECT_DIR` が空 / 相対 / `..` を含む | **通す** | 写像器が起動先を検証して内側を起動しない |
| 起動先が通常ファイルでない / symlink | **通す** | 同上 |
| スクリプトが構文エラー / 実行できない | **通す** | 写像器が 2 を 0 に畳む。静的層の `bash -n` 検査が別途守る |
| 検索パスが空 / `.` を含む / 存在しない | 判定に影響しない | 判定経路に外部コマンドが無い |

### 遅くしないこと

索引更新は編集のたびに同期実行されるため、体感の遅さは直接の失敗である。

- 実測 (本リポジトリ 2209 ファイル): **全体構築 14.6 秒 / 差分更新 0.5 秒**。
- 排他は**非ブロッキング**にする。既に別の更新が走っていれば待たずに諦める (待ち行列を作らない)。
- 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める。
- 索引の対象外の拡張子 (文書・設定ファイル等) では更新を起動しない。

## 期待効果

- **使命への貢献 (間接)**: 使命は現場作業者向けの動画マニュアル生成であり、本件は開発の進め方の
  基盤である。寄与の経路は 2 つ — (a) bug-hunt の main 直叩きガードが実際に効くようになり、
  探索的バグハントが dev 環境を壊す事故を早期に止める、(b) 規約が第一選択と定めた探索手段
  (コード索引) が実際に最新に保たれ、改修時の見落とし (呼び出し元の取りこぼし) が減る。
- **家系の追従**: aicue が t0 から、追従元と同じ常設方式へ移る。
- **無音の劣化を作らない**: 索引ツールが無い環境では、黙って何もしないのではなく
  セッションごとに 1 行だけ告知する (追従元で実測された 1 か月の無音停止を再現しない)。

効果の書き方について: (b) の索引鮮度は改修時の見落としを**減らす可能性がある**という補助的な
効果であり、断定しない。確実に言えるのは「規約が第一選択と定めた探索手段に、更新されない索引が
供給される状態が終わる」ことまでである。

## 実装方針（概要）

### 1. `.claude/settings.json` (新設・git 追跡)

hooks キーだけを持つ。

- `PreToolUse` / matcher `Bash` → `scripts/bughunt-worktree-hook.sh` / timeout 10 秒
- `PostToolUse` / matcher `Write|Edit` → `scripts/code-review-graph-update-hook.sh` / timeout 30 秒

起動子は上記「終了コードの写像器」の形に固定する。

- **絶対パス**: `bash` を検索パスから解決させない (偽の `bash` を経由して安全化ごと迂回されない)。
- **`-p`**: bash の privileged モード。環境変数から**シェル関数を継承しない**・起動ファイルを
  読まない。組み込みコマンドと同名の関数を注入して判定を乗っ取る経路が、1 語で閉じる。
- **写像器**: 終了コード 2 を出せる条件をスクリプトの意図的な 97 だけに限る。

追従元の起動子は `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` (`-p` も写像器も無い) なので、
`docs/template-divergence.md` へ逸脱として記録してから行う。

### 2. `scripts/code-review-graph-update-hook.sh` (新設)

実行契約 (スクリプト冒頭にそのまま宣言する):

1. 何が起きても終了コード 0 で終わる (編集作業を止めない)
2. **標準出力は常に空**。成功時は標準エラーも無出力
3. 告知は**標準エラーに 1 行だけ**。かつ**セッションごと・理由ごとに 1 回だけ**
   (1 セッションあたりの上限は理由の種類数になる)
4. 更新は必ず排他する。安全に排他できない環境では更新しない
5. 呼び出し側の時間切れより内側で自分から諦める。諦めと本当の失敗は**理由語で区別する**
   (終了コードでは区別しない。0 以外を返さないことの方が優先度が高い)
6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
7. 最初の外部コマンド呼び出しより前に、検索パスから作業ディレクトリを意味する要素を落とす
8. 作業ファイル置き場は `.claude/code-review-graph-update-hook/` に固定し、**リポジトリルートから
   置き場までの各段**と、置き場・ロック・告知フラグの各ファイルが symlink でないことを
   作成の前後 2 回検査する。安全に作れなければ何も書かずに終える。
   ファイル名に使うセッション識別子は `^[A-Za-z0-9._-]{1,64}$` に合致し `.` / `..` でない
   ものだけを採り、合致しなければ固定語に落とす (パスの外へ出る経路を作らない)
9. 索引の対象外の拡張子では更新を起動しない (このとき状態ファイル・ロック・告知フラグを
   ひとつも作らない = 副作用ゼロ)

### 3. `scripts/bughunt-worktree-hook.sh` (最小限の改修)

判定条件 (何を拒否し、どの許可シグナルを通すか) は**1 文字も変えない**。変えるのは実装だけ:

- 標準入力の読み取り・JSON からのコマンド抽出・パターン照合を、すべて bash の組み込みで行う
  (現在は `cat` / `python3` / `grep` に依存しており、検索パスが壊れているだけで拒否対象が
  黙って通る = 無音の fail-open)。判定は上の失敗モード表の 3 段に従う。
- 拒否の終了コードを 2 から **97** へ変える (起動子の写像器が 2 へ翻訳する)。
- 2 本の hook で共有する検索パス安全化の前置きを持つ (台帳テストが 2 本の byte 一致を固定する)。
  ガード自身は判定に外部コマンドを使わないので前置きは現時点では空振りだが、後から外部コマンドを
  足したときに穴が開かないよう 2 本で同じものを持たせる。

### 4. 索引ツールの導入必須化

- `docker/Dockerfile` で版を固定して導入し (`uv tool install code-review-graph==2.3.7`)、
  導入先 (`/home/vscode/.local/bin`) を `ENV PATH` に載せる。
- hook 側は**導入先を知らない**。継承した検索パスを安全化するだけにする
  (家系で未決の「導入先を検索パスへ載せる層」の選択について、aicue はコンテナ側を採る。
  理由: 本リポジトリの Dockerfile は索引ツールを導入していないので、どのみち Dockerfile を
  触る必要があり、hook に環境固有の知識を埋める理由が無い)。
- `AGENTS.md` に実行環境前提 (`flock` / `timeout` を持つ Linux 開発コンテナ) を明示する。

### 5. 索引ツール自身に配線を書かせない規約

`code-review-graph install` は MCP 設定・**hook 配線**・`AGENTS.md` への指示注入まで行う
(`--no-hooks` / `--no-instructions` を持つことを実読で確認済み)。`uninstall` は逆に配線を消す。
配線の正本が二重化するので、**この 2 つを実行しない**規約を `AGENTS.md` に明文で置き、
台帳テストがその明文の存在と、追跡ファイル内に呼び出しが無いことの両方を検査する。

### 6. 配線の台帳化 `tests/Architecture/ClaudeHooksWiringTest.php` (新設)

2 層構成にする。

- **静的層** (9 項目):
  1. `.claude/settings.json` が実在し、git 追跡下にあり、有効な JSON である
  2. トップレベルキーが台帳の申告と完全一致 (既定拒否。`permissions` 等を黙って足せない)
  3. hook 種別・matcher・起動コマンド文字列・timeout が台帳と完全一致。台帳に無い hook は違反
  4. 起動文字列が「終了コードの写像器」の形であること (絶対パス起動 / `-p` / 97 の写像 /
     PostToolUse は無条件 0)
  5. `.claude/settings.local.json` が存在する場合、`hooks` キーを持たないこと
     (常設配線をローカル層から無効化できない)
  6. 見本 `.claude/settings.bughunt-hook.example.json` が存在しないこと (復活の禁止)
  7. 2 本の hook スクリプトが実在し、`bash -n` を通ること
  8. 2 本が共有する検索パス安全化の前置きが byte 一致であること
  9. 索引の対象外拡張子の一覧が台帳と完全一致であること、および `AGENTS.md` に
     「索引ツール自身に配線を書かせない」明文があり、追跡ファイル内に
     `code-review-graph install` / `uninstall` の呼び出しが無いこと
- **実起動層**: hook を別プロセスとして実際に起動し、fail-safe の保証を実挙動で固定する
  (必須ケースは下の「受け入れ条件」、網羅表は詳細設計)。

## 受け入れ条件

### 配線系 (索引ツールが未導入でも成立しなければならない)

- 索引更新 hook は次のすべてで**終了コード 0・標準出力は空**: 索引ツール未導入 / 壊れた JSON /
  空の標準入力 / 検索パスが空・`.`・存在しないディレクトリ / ロック競合 / 更新が終わらない /
  置き場が symlink / 置き場の親が書けない / セッション識別子が不正
- 索引更新 hook は成功時に**標準エラーにも何も出さない**。告知はセッションごと・理由ごとに 1 行だけ
- 索引更新 hook は対象外拡張子の編集では更新を起動せず、**副作用をひとつも作らない**
- ガードは拒否対象で 97 を返し、固定起動文字列を通したときに 2 になる
- ガードは非対象コマンドで**外部コマンドを 1 つも起こさず** 0 で抜ける (検索パスが壊れていても同じ)
- ガードのスクリプトを構文エラーにしても、固定起動文字列の終了コードは 2 にならない
- `CLAUDE_PROJECT_DIR` が「空 / 相対値 / `..` を含む値 / 97 を返す同名スクリプトを置いた別ディレクトリ」
  のいずれでも、また `scripts/` が symlink でその先の同名スクリプトが 97 を返す場合でも、
  固定起動文字列の終了コードは 2 にならない
- ガードは標準入力を閉じない相手に対して待ち続けない — hook プロセスが自分で終了し、
  終了コードが 0 で、経過時間が 5 秒 + 余裕の範囲に収まる。1 MiB より後ろに置かれた
  拒否対象は読まない (= 通す)
- `.claude/settings.local.json` に `hooks` を書くと静的層が落ちる
- 見本ファイルを復活させると静的層が落ちる

### 導入系 (コンテナを作り直した後にしか確認できない)

- 新しいセッションで `Write` / `Edit` を行うと索引が実際に前進する
- 索引ツール未導入の告知が出なくなる
- これは**統合後の新しいセッションでの確認事項**である (設定はセッション開始時にしか読まれず、
  worktree 内では実スモークできない)

## 保証しないもの (誇張しない)

- **リポジトリルート自体のすり替えは防げない**。写像器は `CLAUDE_PROJECT_DIR` が絶対パスで
  `..` を含まず、その配下の起動先が symlink でない通常ファイルであることまでしか確かめない。
  **Claude Code が渡す絶対パスを信頼境界とする**という前提の上に立っている。
- **既定拒否が成立するのは `"command"` を正常に抽出できた経路までである**。抽出に失敗した
  経路では、`BUGHUNT_ALLOW_MAIN=` が払い出しのどのフィールドにあっても解除が成立する。
  これは限定的な fail-open であり、早期に気づける層という位置付けの上で受容する。
- **ガードは敵対的な入力に対する安全境界ではない**。`\uXXXX` 形式のエスケープで
  `bug-hunt-shard.sh` を書けば段 0 を素通りする。権威層は `bug-hunt-shard.sh` 本体の
  `assert_worktree_context` であり、本 hook は**早期に気づける層**である。この取りこぼしは
  受容する (脅威モデルは意図的な回避ではなく、うっかりの main 直叩きである)。
- **索引更新 hook は索引の正しさを保証しない**。保証するのは「編集作業を止めないこと」と
  「更新できなかったときに黙らないこと」だけである。索引ツール側の解析漏れは対象外。
- **配線が効いているかは worktree 内では確認できない**。設定はセッション開始時にしか
  読まれないため、統合後の新しいセッションでの確認が要る。
- **ガードのスクリプトが壊れているときは拒否が止まる**。写像器が 2 を 0 に畳むためである
  (意図した取引。静的層が実在と構文を守る)。

## 制約・前提

- `.claude/settings.json` は**セッション開始時にしか読まれない**。したがって worktree 内で
  配線変更の実スモークはできない。main へ統合した後の新しいセッションで確認する必要がある
  (追従元でも同じ申し送りが出ている)。
- 索引ツールの `update` は git の差分 (既定で `HEAD~1` 起点) から変更ファイルを決める。
  したがって「編集した 1 ファイルだけ」ではなく「直前のコミット以降の差分」を再解析する。
  実測 0.5 秒なので問題にならない。
- 既存コンテナは作り直すまで Dockerfile の導入が効かない。その間は hook が
  セッションごとに 1 行だけ告知する (黙って何もしない状態にはしない)。
- 本リポジトリの `.claude/skills/` 配下には外部 skill (git 管理外) が同居する。設定ファイルの
  全数申告は `.claude/settings.json` と `.claude/settings.local.json` のみを対象にする。

## スコープ外

- **SessionStart の索引状態表示 hook**。家系でも任意扱いで、1 リポジトリだけが持つ。
  今必要なものではない (思考原則 2)。
- **`permissions` キーの導入**。実行許可の一覧は別 feature の関心事であり、本件では触らない。
- **code-review-graph MCP サーバの設定**。git 管理外で、本 feature の境界外。
- **bug-hunt ガードの判定条件そのものの変更**。拒否対象・許可シグナルの集合は不変に保つ。
- **家系の未決事項の裁定**。「導入先を検索パスへ載せる層」をどちらに揃えるかは家系全体の
  裁定事項であり、本件は aicue の選択を決めるだけで、他リポジトリへ還流させない。
- **索引更新スクリプトの中身を家系 4 本で揃えること**。裁定 (2026-08-13) はファイル名だけを
  揃える対象とし、中身の収斂は対象外としている。

---

## 詳細設計書

# 詳細設計: claude-hooks-settings-wiring

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本施策は開発の進め方の基盤であり、使命への寄与は間接である (概念設計「期待効果」参照)。

### 禁止事項

`AGENTS.md` の禁止事項が正本。本設計に直結するもの:

1. テストなしの実装完了報告 (不変条件は Architecture テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

思考原則 3「**後方互換の並走を残さない**」も本件の中心にある — 見本ファイルは同じ変更で削除する。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。`RefreshDatabase` はグローバル適用、個別 `DatabaseTransactions` 禁止
- 本件のテストは **DB を触らない** (ファイル読み取りと別プロセス起動のみ)
- シェルスクリプトは `bash -n` を通すこと。`shellcheck` は本リポジトリに無いので導入しない
- PHP 8.4 + Laravel 12。テストは `tests/Architecture/` 配下

## 概念設計リファレンス

`devnotes/20260815-1539-claude-hooks-settings-wiring/conceptual-design.md`

## 設計中に実測で確認した挙動 (この設計の前提)

| # | 確認したこと | 結果 |
|---|---|---|
| M1 | 索引の全体構築 (2209 ファイル) | 14.6 秒 |
| M2 | 索引の差分更新 `code-review-graph update -q --repo …` | 0.5 秒 |
| M3 | `IFS= read -r -N 1048576 -t 2 input` は時間切れでも読めた分を変数に残す | 残す (7 バイト読めた状態で 2 秒で復帰) |
| M4 | 標準入力を閉じない相手に対して待ち続けないか | 待たない (`-t` の秒数で復帰し exit 0) |
| M5 | `[[ … =~ \"command\"…\"((\\.|[^\"\\])*)\" ]]` によるエスケープ込みの抽出 | `ls -la \"x\" && bug-hunt-shard.sh provision` を正しく取り出せる |
| M6 | `${d//../}` による `..` の検出 / `${d#/}` による絶対パス判定 / `${ext,,}` の小文字化 | すべて期待どおり |
| M7 | 起動子の写像 (97→2、2→0、0→0、変数なし→0、相対値→0、`..` 入り→0、`scripts/` が symlink→0) | 7 ケースすべて期待どおり |
| M8 | `bash -p` が環境からのシェル関数の継承を止めるか | 止める (`-p` 無しでは `printf` が乗っ取られ、`-p` 有りでは乗っ取られない) |
| M9 | 起動子の文字列を JSON へ入れて読み戻したときの同一性 | 一致する (エスケープは `\"` のみ) |

M1〜M9 の再現手順は本設計の各節に書いたコマンドそのものである。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 索引更新 hook の実体を新設する | `scripts/code-review-graph-update-hook.sh` (新規) | 高 |
| 2 | bug-hunt ガードの判定を組み込みだけで完結させる | `scripts/bughunt-worktree-hook.sh` | 高 |
| 3 | `.claude/settings.json` を新設し見本を削除する | `.claude/settings.json` (新規) / `.claude/settings.bughunt-hook.example.json` (削除) | 高 |
| 4 | 配線を台帳化する | `tests/Architecture/ClaudeHooksWiringTest.php` (新規) | 高 |
| 5 | 索引ツールの導入を必須化する | `docker/Dockerfile` / `.gitignore` | 中 |
| 6 | 規約と台帳の文書を更新する | `AGENTS.md` / `README.md` / `scripts/README.md` / `docs/template-divergence.md` | 中 |

実装順序は 1 → 2 → 3 → 4 (テストは 4 を先に赤くしてから 1〜3 を通す形でもよい) → 5 → 6。

---

## 施策 1: 索引更新 hook の実体を新設する

### 変更箇所

- 新規: `scripts/code-review-graph-update-hook.sh`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/ClaudeHooksWiringTest.php` (施策 4 で新設)
- `.gitignore`: 作業ファイル置き場の追加 (施策 5)
- `scripts/README.md`: 台帳行の追加 (施策 6。`ScriptsReadmeInventoryTest` が deny-by-default で強制)

### 実行契約 (スクリプト冒頭にそのまま書く)

1. 何が起きても終了コード 0 で終わる (編集作業を止めない)
2. 標準出力は常に空
3. 告知は標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ
4. 更新は必ず `flock` で排他する。安全に排他できない環境では更新しない
5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
7. 最初の外部コマンド呼び出しより前に検索パスを安全化する
8. 置き場・ロック・告知フラグが symlink なら何も書かずに終える
9. 索引の対象外の拡張子では更新を起動しない (副作用ゼロ)

### 処理順序 (この順序自体が契約である)

| 段 | 何をするか | 失敗したら |
|---|---|---|
| 0 | 検索パス安全化 (共有プロローグ) | 落とせる要素が無ければ最小のシステムパスに倒す |
| 1 | 標準入力を **最大 1 MiB / 最大 5 秒**で 1 回だけ読む | 読めなくても続行 (以降の抽出が空になるだけ) |
| 2 | `file_path` を抽出し、**対象外拡張子なら即 exit 0** | 抽出できなければ更新側へ倒す |
| 3 | リポジトリルートを `BASH_SOURCE` から解決する | 解決できなければ exit 0 |
| 4 | `.claude` と置き場の symlink 検査 → 置き場作成 → **再検査** | 作れない/symlink なら **黙って** exit 0 |
| 5 | ロックを開いて `flock -n` で取る | 取れない (他が更新中) なら **黙って** exit 0 |
| 6 | セッション識別子を抽出・検証する | 不正なら固定語 `unknown` に落とす |
| 7 | 索引ツール・`timeout` の在否を見る | 無ければ告知 1 行 → exit 0 |
| 8 | `timeout 20 code-review-graph update -q --repo <root>` | 124 なら `update-timeout`、他の非 0 なら `update-failed` を告知 |
| 9 | 常に exit 0 | — |

**段 4・5 で黙る理由**: 置き場が作れなければ告知の重複抑止機構そのものが無く、編集のたびに
1 行出て邪魔になる。ロック競合は劣化ではなく正常動作 (他のプロセスが同じ仕事をしている)。
**告知はすべてロック取得後に行う**ので、重複抑止の判定に競合が起きない。

### 実装 (全文)

```bash
#!/usr/bin/env bash
# PostToolUse(Write|Edit) — コード索引 (code-review-graph) の差分更新。
#
# 実行契約 (tests/Architecture/ClaudeHooksWiringTest.php が実挙動で固定する):
#  1. 何が起きても終了コード 0 で終わる (編集作業を止めない)
#  2. 標準出力は常に空
#  3. 告知は標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ
#  4. 更新は必ず flock で排他する。安全に排他できない環境では更新しない
#  5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
#  6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
#  7. 最初の外部コマンド呼び出しより前に検索パスを安全化する
#  8. 置き場・ロック・告知フラグが symlink なら何も書かずに終える
#  9. 索引の対象外の拡張子では更新を起動しない (副作用ゼロ)
#
# 索引ツール自身の install / uninstall は実行しないこと (配線の正本が二重化する。AGENTS.md)。

# ---8< SHARED_PATH_PROLOGUE (bughunt-worktree-hook.sh と byte 一致。台帳テストが固定する) >8---
# set -e は使わない: 途中の失敗で暗黙に終了すると「常に 0 で終わる」契約を守れない。
set -uo pipefail
export LC_ALL=C
_hook_sanitize_path() {
    local element out=''
    local -a elements=()
    IFS=':' read -r -a elements <<< "${PATH-}"
    for element in ${elements[@]+"${elements[@]}"}; do
        # 絶対パスでない要素 (空要素・"." ・相対パス) を落とす
        case "${element}" in
            /*) ;;
            *) continue ;;
        esac
        # 正規化前の別表記も落とす (//, /./, /../, 末尾の /. と /..)
        case "${element}" in
            *//*|*/./*|*/../*|*/.|*/..) continue ;;
        esac
        out="${out:+${out}:}${element}"
    done
    # 空の PATH はカレントディレクトリと解釈されうるので、最小のシステムパスに倒す
    PATH="${out:-/usr/local/bin:/usr/bin:/bin}"
    export PATH
}
_hook_sanitize_path
# ---8< /SHARED_PATH_PROLOGUE >8---

# 呼び出し側 (.claude/settings.json) の 30 秒より内側で自分から諦める
readonly INNER_TIMEOUT_SECONDS=20
# 索引の対象外の拡張子 (台帳テストが完全一致で固定する。索引ツール更新時は棚卸しすること)
readonly SKIP_EXTENSIONS=' md txt json yaml yml lock log '

state_dir=''
session_id='unknown'

# 告知: 標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ。
# 判定はすべてロック取得後に呼ばれるので、重複抑止の読み書きに競合は起きない。
emit_warning() {
    local reason="$1" message="$2" flag recorded=''
    flag="${state_dir}/warned-${reason}"
    [ -L "${flag}" ] && return 0
    [ -f "${flag}" ] && IFS= read -r recorded < "${flag}" 2>/dev/null
    [ "${recorded}" = "${session_id}" ] && return 0
    printf '%s\n' "${session_id}" > "${flag}" 2>/dev/null || return 0
    printf 'code-review-graph: %s\n' "${message}" >&2
    return 0
}

# --- 段 1: 標準入力を 1 回だけ読む (最大 1 MiB / 最大 5 秒) -------------------
input=''
IFS= read -r -N 1048576 -t 5 input || true

# --- 段 2: 対象外拡張子なら副作用ゼロで終わる --------------------------------
file_path=''
if [[ "${input}" =~ \"file_path\"[[:space:]]*:[[:space:]]*\"([^\"]*)\" ]]; then
    file_path="${BASH_REMATCH[1]}"
fi
case "${file_path}" in
    *.*)
        extension="${file_path##*.}"
        case "${SKIP_EXTENSIONS}" in
            *" ${extension,,} "*) exit 0 ;;
        esac
        ;;
esac

# --- 段 3: リポジトリルートを自分の位置から解決する ---------------------------
script_path="${BASH_SOURCE[0]}"
script_dir="${script_path%/*}"
[ "${script_dir}" = "${script_path}" ] && script_dir='.'
repo_root="$(cd -- "${script_dir}/.." && pwd -P)" || exit 0
[ -n "${repo_root}" ] || exit 0

# --- 段 4: 置き場の symlink 検査 → 作成 → 再検査 ------------------------------
claude_dir="${repo_root}/.claude"
state_dir="${claude_dir}/code-review-graph-update-hook"
[ -L "${claude_dir}" ] && exit 0
[ -L "${state_dir}" ] && exit 0
mkdir -p "${state_dir}" 2>/dev/null || exit 0
[ -L "${claude_dir}" ] && exit 0
[ -L "${state_dir}" ] && exit 0
[ -d "${state_dir}" ] || exit 0

# --- 段 5: 排他 (非ブロッキング。取れなければ黙って終わる) --------------------
lock_file="${state_dir}/update.lock"
[ -L "${lock_file}" ] && exit 0
command -v flock > /dev/null 2>&1 || { session_id='unknown'; emit_warning 'no-flock' \
    'flock が無いため索引を更新しません (排他できない環境では更新しない契約です)'; exit 0; }
exec 9> "${lock_file}" 2>/dev/null || exit 0
flock -n 9 || exit 0

# --- 段 6: セッション識別子 --------------------------------------------------
if [[ "${input}" =~ \"session_id\"[[:space:]]*:[[:space:]]*\"([A-Za-z0-9._-]{1,64})\" ]]; then
    case "${BASH_REMATCH[1]}" in
        .|..) ;;
        *) session_id="${BASH_REMATCH[1]}" ;;
    esac
fi

# --- 段 7: 前提コマンドの在否 ------------------------------------------------
if ! command -v code-review-graph > /dev/null 2>&1; then
    emit_warning 'tool-missing' \
        'コード索引ツールが未導入です (uv tool install code-review-graph==2.3.7 → code-review-graph build)'
    exit 0
fi
if ! command -v timeout > /dev/null 2>&1; then
    emit_warning 'no-timeout' 'timeout が無いため索引を更新しません (時間切れを保証できないためです)'
    exit 0
fi

# --- 段 8: 差分更新 ----------------------------------------------------------
timeout -k 5 "${INNER_TIMEOUT_SECONDS}" \
    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
status=$?
case "${status}" in
    0) ;;
    124|137) emit_warning 'update-timeout' \
        "索引の差分更新が ${INNER_TIMEOUT_SECONDS} 秒で終わらなかったため中断しました" ;;
    *) emit_warning 'update-failed' \
        "索引の差分更新に失敗しました (終了コード ${status}。code-review-graph build を試してください)" ;;
esac

# --- 段 9: 常に成功で終わる --------------------------------------------------
exit 0
```

### 設計上の注意 (実装者向け)

- `set -e` を**使わない**。使うと段 4 の `mkdir` 失敗などで暗黙終了し、契約 1 を破る
  (追従元が実装時に踏んだ罠として台帳に記録がある)。
- `exec 9> …` の後は `flock -n 9` が失敗しても fd は開いたままだが、プロセス終了で解放される。
- `${extension,,}` は bash 4 以降の小文字化。`.MD` のような大文字拡張子も落とす。
- `timeout -k 5` は TERM の 5 秒後に KILL する。KILL された場合の終了コード 137 も時間切れ扱いにする。
- 標準出力へ書く箇所がひとつも無いことを、実装後に目視でも確認すること (契約 2)。

### テスト計画 (施策 4 の実起動層で固定する)

| ID | ケース | 期待 |
|---|---|---|
| B01 | 索引ツールを含む stub PATH で正常な入力 | exit 0 / stdout 空 / stderr 空 / stub が `update` で 1 回起動される |
| B02 | 索引ツール未導入 (stub の無い PATH) | exit 0 / stdout 空 / stderr 1 行 / 文言に `tool-missing` の理由が対応する語を含む |
| B03 | B02 と同じセッションでもう 1 回 | stderr 0 行 (重複抑止) |
| B04 | B02 と同じ理由で別セッション識別子 | stderr 1 行 (セッションが変われば再告知) |
| B05 | 同一セッションで別の理由 (`no-timeout`) | stderr 1 行 (理由ごとに 1 回) |
| B06 | `PATH=` (空) | exit 0 / stdout 空 / カレントに置いた偽 `code-review-graph` が起動されない |
| B07 | `PATH=.` および `PATH=/nonexistent` | 同上 |
| B08 | 壊れた JSON | exit 0 / stdout 空 |
| B09 | 標準入力が空 | exit 0 |
| B10 | 標準入力を閉じない producer | プロセスが自分で終了 / exit 0 / 経過 5 秒 + 余裕以内 |
| B11 | 1 MiB より後ろにだけ `file_path` を置いた入力 | exit 0 / 読み取りが上限で打ち切られる (待ち続けない) |
| B12 | `.claude` が symlink | exit 0 / リンク先に何も書かれない |
| B13 | 置き場が symlink | exit 0 / リンク先に何も書かれない |
| B14 | ロックファイルが symlink | exit 0 / 更新が起動しない |
| B15 | 置き場の親が書けない (0500) | exit 0 / stdout 空 |
| B16 | ロックを別プロセスが保持している | exit 0 / 更新が起動しない / 即座に返る (1 秒以内) |
| B17 | 5 並列起動 | 全部 exit 0 / 更新の起動は 1 回だけ / 合計が呼び出し側 timeout 未満 |
| B18 | 更新が終わらない stub (60 秒 sleep) | exit 0 / 経過が内側 timeout + 余裕以内 / `update-timeout` の告知 1 行 |
| B19 | 更新が非 0 で失敗する stub | exit 0 / `update-failed` の告知 1 行 |
| B20 | `session_id` が `../../etc/passwd` | exit 0 / 置き場の外にファイルが作られない |
| B21 | `file_path` が `docs/x.md` (対象外拡張子) | exit 0 / 更新が起動しない / **置き場・ロック・告知フラグが 1 つも作られない** |
| B22 | `file_path` が `x.MD` (大文字) | B21 と同じ |
| B23 | `file_path` が `resources/views/x.blade.php` | 更新が起動する |
| B24 | `file_path` が拡張子なし (`Makefile`) / 抽出できない | 更新が起動する (対象外側へ倒さない) |
| B25 | cwd を `/` にし `CLAUDE_PROJECT_DIR` を外して起動 | 更新が sandbox のリポジトリルートを `--repo` で受け取る |

実起動は **sandbox に実スクリプトを複製した木** (`$sandbox/scripts/…`) に対して行う
(`BASH_SOURCE` 解決の結果、置き場が sandbox 側になり、本物のリポジトリを汚さない)。
`code-review-graph` / `flock` / `timeout` は sandbox の `bin/` に置いた stub を PATH で見せる
(stub は起動された事実と引数を記録するファイルを書く)。

### リスク

- B18 は内側 timeout (20 秒) の実測を伴うため、この 1 ケースだけ約 21 秒かかる。
  値を小さくすると実運用で早すぎる中断が起きるため、テスト時間より運用の正しさを採る。
- stub 方式なので**実際の索引更新の正しさは検査しない** (それは索引ツールの責務)。

---

## 施策 2: bug-hunt ガードの判定を組み込みだけで完結させる

### 変更箇所

- `scripts/bughunt-worktree-hook.sh` (全面差し替え。拒否対象と許可シグナルの集合は不変)

### 現行コード (判定部)

```bash
set -euo pipefail

input="$(cat)"
cmd="$(printf '%s' "${input}" | python3 -c 'import sys,json
try: print(json.load(sys.stdin).get("tool_input",{}).get("command",""))
except Exception: print("")' 2>/dev/null || true)"

printf '%s' "${cmd}" | grep -qE 'bug-hunt-shard\.sh[[:space:]]+provision' || exit 0

if printf '%s' "${cmd}" | grep -qE '\.claude/worktrees/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN='; then
    exit 0
fi

cat >&2 <<'MSG'
⛔ …
MSG
exit 2
```

**この形の欠陥**: 判定経路が `cat` / `python3` / `grep` に依存する。検索パスからこれらを解決
できない環境ではいずれも 127 で終わり、`|| true` と `2>/dev/null` により**拒否対象が黙って通る**
(追従元で実測された無音 fail-open と同型)。配線されていない今は誰も踏んでいないが、
常設した瞬間から実害になる。

### 変更後コード (判定部)

```bash
# ---8< SHARED_PATH_PROLOGUE (code-review-graph-update-hook.sh と byte 一致) >8---
（施策 1 と完全に同じブロックをここに置く）
# ---8< /SHARED_PATH_PROLOGUE >8---

# 拒否は終了コード 97 で表す。.claude/settings.json の起動子が 97 だけを 2 へ写像するため、
# 構文エラー (2) や実行不能 (126/127) が Bash ツールをブロックすることは無い。
readonly DENY_EXIT_CODE=97

# 標準入力は 1 回だけ読む。最大 1 MiB / 最大 5 秒 (閉じない相手に待ち続けない)。
input=''
IFS= read -r -N 1048576 -t 5 input || true

# 段 0: 対象語が無ければ外部コマンドを 1 つも起こさずに通す (無関係なコマンドは構造的に無影響)
case "${input}" in
    *bug-hunt-shard.sh*) ;;
    *) exit 0 ;;
esac

# 段 1: tool_input.command を取り出す (JSON エスケープは我々が探すバイト列を増やす方向にしか働かない)
command_text=''
extracted=0
if [[ "${input}" =~ \"command\"[[:space:]]*:[[:space:]]*\"((\\.|[^\"\\])*)\" ]]; then
    command_text="${BASH_REMATCH[1]}"
    extracted=1
fi

# 段 2: 判定
#  - 抽出できた: 抽出値だけで判定する (許可シグナル 2 種とも有効)
#  - 抽出できない: 明示解除 BUGHUNT_ALLOW_MAIN= だけを生入力で見る
#    (痕跡 .claude/worktrees/ は偶然そこにあり得るので抽出失敗時は評価しない)
if [ "${extracted}" -eq 1 ]; then
    subject="${command_text}"
    allow_regex='(\.claude\\?/worktrees\\?/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN=)'
else
    subject="${input}"
    allow_regex='BUGHUNT_ALLOW_MAIN='
fi

# 実行の検出は「bug-hunt-shard.sh の直後の空白 + provision」に限る
# (コミットメッセージ等の文字列言及では誤発火しない)。JSON の \n \t \r 表記も空白として受ける。
[[ "${subject}" =~ bug-hunt-shard\.sh([[:space:]]|\\[nrt])+provision ]] || exit 0
[[ "${subject}" =~ ${allow_regex} ]] && exit 0

cat >&2 <<'MSG'
⛔ bug-hunt provision を worktree 外から直叩きしようとしています …（現行の文面のまま）
MSG
exit "${DENY_EXIT_CODE}"
```

- 拒否メッセージの出力に使う `cat` は**判定の後**なので、失敗しても判定結果は変わらない
  (終了コードは `exit 97` で決まる)。念のため `cat` ではなく `printf` で出す形に変えてもよいが、
  ヒアドキュメントの可読性を採り `cat` のままとする。**判定経路には外部コマンドが 1 つも無い**。
- `set -euo pipefail` は `set -uo pipefail` に変える (`-e` があると `[[ … ]]` の偽で暗黙終了しうる)。

### 波及変更

- `.claude/settings.json` (施策 3) — 97 → 2 の写像を持つ起動子
- `.claude/skills/app-bug-hunt/SKILL.md` — 拒否時の終了コードに言及があるか確認し、
  あれば「97 を起動子が 2 へ写す」旨に直す (無ければ変更なし)
- `AGENTS.md` §bug-hunt の「配線は `.claude/settings.bughunt-hook.example.json` を
  `.claude/settings.json` にマージ」という記述 → 常設済みの記述へ差し替える (施策 6)

### テスト計画 (施策 4 の実起動層)

| ID | ケース | 期待 |
|---|---|---|
| B26 | 無関係なコマンド (`ls -la`) | exit 0 |
| B27 | 無関係なコマンド + `PATH=/nonexistent` + カレントに偽 `grep`/`python3` | exit 0 / 偽コマンドが起動されない |
| B28 | `scripts/bug-hunt-shard.sh provision --shard 1` | exit 97 / stderr に拒否文面 |
| B29 | B28 + `PATH=` (空) | exit 97 (無音 fail-open が無い) |
| B30 | worktree パスを含む provision | exit 0 |
| B31 | `BUGHUNT_ALLOW_MAIN=1` 付き provision | exit 0 |
| B32 | `BUGHUNT_SELFTEST_DRYRUN=1` 付き provision | exit 0 |
| B33 | JSON の `description` にだけ provision の文字列があり command は別物 | exit 0 (抽出値で判定している証拠) |
| B34 | 壊れた JSON + provision 文字列 + 許可シグナル無し | exit 97 |
| B35 | 壊れた JSON + provision 文字列 + `BUGHUNT_ALLOW_MAIN=` | exit 0 |
| B36 | 壊れた JSON + provision 文字列 + `.claude/worktrees/` のみ | exit 97 (痕跡は抽出失敗時に評価しない) |
| B37 | `.claude\/worktrees\/` (エスケープ形式) を含む provision | exit 0 (許可を取りこぼさない) |
| B38 | 標準入力が空 / 閉じない producer | exit 0 / 自分で終了 / 5 秒 + 余裕以内 |
| B39 | 1 MiB より後ろにだけ provision を置いた入力 | exit 0 (読まない = 通す。受容済みの限界) |
| B40 | `bug-hunt-shard.sh scaffold … provision` (間に別語) | exit 0 (誤発火しない) |

### リスク

- 抽出正規表現 `\"command\"…` は `tool_input` の外にある同名キーにも当たりうる。現行の
  `tool_input.command` 限定より広い。ただし PreToolUse の matcher が `Bash` に限られており、
  払い出しに `command` キーは 1 つしか現れない。B33 が「説明文では誤発火しない」ことを固定する。
- `\uXXXX` 形式のエスケープは取りこぼす (概念設計「保証しないもの」で受容済み)。

---

## 施策 3: `.claude/settings.json` を新設し見本を削除する

### 変更箇所

- 新規: `.claude/settings.json` (git 追跡)
- 削除: `.claude/settings.bughunt-hook.example.json`

### 変更後コード

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/bughunt-worktree-hook.sh; s=0; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; s=$?; fi; if [ \"$s\" = 97 ]; then exit 2; fi; exit 0'",
            "timeout": 10
          }
        ]
      }
    ],
    "PostToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/code-review-graph-update-hook.sh; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; fi; exit 0'",
            "timeout": 30
          }
        ]
      }
    ]
  }
}
```

起動子が持つ 3 つの役割:

1. **起動先の検証** — `CLAUDE_PROJECT_DIR` が絶対パスで `..` を含まないこと、`scripts/` が
   symlink でない実ディレクトリであること、起動先が symlink でない通常ファイルであること。
   1 つでも欠ければ内側を起動しない。
2. **終了コードの写像** — PreToolUse は 97 だけを 2 へ写し、それ以外はすべて 0。
   PostToolUse は無条件に 0。
3. **環境からのシェル関数の遮断** — `-p` (privileged mode)。組み込みと同名の関数を
   注入して判定を乗っ取る経路を閉じる。

検査はすべて bash の組み込み (`[` / パラメータ展開) で、外部コマンドを 1 つも使わない。

### 波及変更

- `.claude/settings.bughunt-hook.example.json` の削除に伴い、参照している文書
  (`AGENTS.md` §bug-hunt) を施策 6 で直す。
- `.claude/settings.local.json` は作らない (存在すれば `hooks` を持たないことを台帳が検査する)。

### テスト計画 (施策 4)

静的層 S01〜S12 と、起動子そのものを起動する実起動層 B41〜B48 (下記)。

### リスク

- 設定はセッション開始時にしか読まれないため、**worktree 内では実配線を確認できない**。
  main 統合後の新しいセッションで確認する (申し送り事項)。
- `matcher` に `Bash` を選ぶことで、すべての Bash 呼び出しに hook が挟まる。段 0 の
  組み込み 1 回で抜けるため実測上のコストは無視できるが、`timeout` を 10 秒に据えるのは
  「万一固まっても 10 秒で解放される」ための上限である。

---

## 施策 4: 配線を台帳化する

### 変更箇所

- 新規: `tests/Architecture/ClaudeHooksWiringTest.php`

### 台帳 (deny-by-default の正本)

```php
/**
 * 配線台帳。ここに書かれた形と .claude/settings.json が**完全一致**しなければ落ちる。
 * 台帳に無い hook・イベント・トップレベルキーはすべて違反 (既定拒否)。
 */
const CLAUDE_HOOKS_TOP_LEVEL_KEYS = ['hooks'];

const CLAUDE_HOOKS_WIRING = [
    'PreToolUse' => [
        ['matcher' => 'Bash', 'script' => 'scripts/bughunt-worktree-hook.sh', 'timeout' => 10, 'deny_exit_code' => 97],
    ],
    'PostToolUse' => [
        ['matcher' => 'Write|Edit', 'script' => 'scripts/code-review-graph-update-hook.sh', 'timeout' => 30, 'deny_exit_code' => null],
    ],
];

/** 索引の対象外拡張子 (scripts/code-review-graph-update-hook.sh の SKIP_EXTENSIONS と一致すること) */
const CLAUDE_HOOKS_SKIP_EXTENSIONS = ['md', 'txt', 'json', 'yaml', 'yml', 'lock', 'log'];
```

起動コマンド文字列は**台帳側で組み立てて完全一致**を要求する
(`claudeHooksExpectedCommand(string $script, ?int $denyExitCode): string`)。
設定ファイルを書き換えたら必ずテストが落ちる = 配線の正本が 1 か所になる。

### 静的層

| ID | 検査 |
|---|---|
| S01 | `.claude/settings.json` が実在し、有効な JSON である |
| S02 | `.claude/settings.json` が git 追跡下にある (`git ls-files` で確認) |
| S03 | トップレベルキーが `CLAUDE_HOOKS_TOP_LEVEL_KEYS` と完全一致 (順不同・過不足なし) |
| S04 | hooks のイベント集合が台帳と完全一致 |
| S05 | 各イベントの matcher / command / timeout が台帳と完全一致 (1 文字でも違えば落ちる) |
| S06 | 起動文字列が `/bin/bash -p -c ` で始まり、`$CLAUDE_PROJECT_DIR` を検証する 5 条件をすべて含み、PreToolUse は `= 97` の写像を、PostToolUse は無条件 `exit 0` を持つ |
| S07 | `.claude/settings.local.json` が存在する場合、`hooks` キーを持たない |
| S08 | `.claude/settings.bughunt-hook.example.json` が存在しない (見本方式の復活禁止) |
| S09 | 台帳の 2 スクリプトが実在し `bash -n` を通る |
| S10 | 2 スクリプトの `SHARED_PATH_PROLOGUE` ブロックが byte 一致し、かつ**両方で最初の外部コマンド呼び出しより前**にある |
| S11 | `scripts/code-review-graph-update-hook.sh` の `SKIP_EXTENSIONS` が `CLAUDE_HOOKS_SKIP_EXTENSIONS` と完全一致 |
| S12 | `AGENTS.md` に「索引ツール自身に配線を書かせない」明文がマーカー付きで存在し、追跡ファイル内に `code-review-graph install` / `code-review-graph uninstall` / `code-review-graph init` の呼び出しが無い (文書内の禁止の言及は、マーカー区間として除外する) |

S10 の「最初の外部コマンド呼び出しより前」は、プロローグ終端マーカーより前の行に
外部コマンドらしき呼び出し (`printf` `[` `read` などの組み込みを除いた語) が無いことを
機械検査する。組み込みの一覧は台帳に持ち、増やすときはレビューで見える形にする。

### 実起動層 (起動子の写像を固定する)

| ID | ケース | 期待 |
|---|---|---|
| B41 | sandbox の `scripts/bughunt-worktree-hook.sh` が 97 を返す | 起動子の終了コード 2 |
| B42 | 同スクリプトが 0 を返す | 0 |
| B43 | 同スクリプトが 2 を返す (構文エラーの模倣) | **0** (ブロックしない) |
| B44 | 同スクリプトが存在しない | 0 |
| B45 | `CLAUDE_PROJECT_DIR` を外す | 0 |
| B46 | `CLAUDE_PROJECT_DIR` が相対値で、その先に 97 を返すスクリプトを置く | 0 |
| B47 | `CLAUDE_PROJECT_DIR` に `..` を含め、解決先に 97 を返すスクリプトを置く | 0 |
| B48 | `scripts/` を symlink にし、その先の同名スクリプトが 97 を返す | 0 |
| B49 | 起動先スクリプトが symlink で、その先が 97 を返す | 0 |
| B50 | PostToolUse の起動子: 内側が 97 でも 2 でも 1 でも | 常に 0 |
| B51 | `printf` を上書きするシェル関数を export した環境で起動子を走らせる | 内側に継承されない (`-p` が効いている証拠。M8 の機械化) |

実起動は `.claude/settings.json` から**実際に読んだ文字列**を `bash -c` で走らせる
(台帳の写しではなく本物を走らせる = 設定を直したら必ずここも動く)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (ヘルパ関数はすべて `: string` / `: array` 等)
- [x] null 安全 (`file_get_contents` の `false` は `Assert::string()` で narrow する)
- [x] 配列の形は PHPDoc の `array{...}` / `list<string>` で明示する
- [x] `Process::run()` の結果は `exitCode(): ?int` を `Assert::integer()` で narrow する
- 関数名は他の Architecture テストと衝突しないよう `claudeHooks` 接頭辞で始める
  (Pest は全テストを 1 プロセスで読み込むため、素の名前は衝突する)

### テスト計画

本施策そのものがテストである。加えて:

- **変異検出**: 実装後に設定ファイルの timeout を 1 文字変える / matcher を変える /
  見本ファイルを復活させる / プロローグを片方だけ変える、の 4 つで**実際に落ちること**を
  手で確認し、結果を devnotes に残す (台帳が空振りしていないことの確認)。

### リスク

- sandbox を大量に作るため、テストは `sys_get_temp_dir()` 配下に作り `finally` で必ず消す。
- 実起動層は `Process::timeout()` を必ず指定する (無限待ちを作らない)。

---

## 施策 5: 索引ツールの導入を必須化する

### 変更箇所

- `docker/Dockerfile`: 版を固定した導入と `ENV PATH` への追加
- `.gitignore`: `/.claude/code-review-graph-update-hook/` の追加

### 変更後コード

```dockerfile
# コード索引 (code-review-graph)。AGENTS.md がコードベース探索の第一選択と定めており、
# .claude/settings.json の PostToolUse hook が差分更新を回すため、版を固定して導入する。
# 版を上げるときは scripts/code-review-graph-update-hook.sh の対象外拡張子の棚卸しも行うこと。
RUN mise exec -- uv tool install code-review-graph==2.3.7

# uv tool の導入先を検索パスへ載せる (hook 側は導入先を知らない = 環境固有の知識を持たせない)
ENV PATH="/home/vscode/.local/bin:$PATH"
```

`.gitignore`:

```
# PostToolUse 索引更新 hook の作業ファイル置き場 (ロック / 告知フラグ)
/.claude/code-review-graph-update-hook/
```

### 波及変更

- `README.md` セットアップ節: 索引ツールが image に入っていること、既存コンテナは
  作り直すか手で `uv tool install` することを 2 行で書く。
- `AGENTS.md` §コードベース探索: 「hook で自動更新されない場合」という人手前提の記述を、
  「PostToolUse hook が自動更新する。前提コマンドは `flock` / `timeout`」へ直す。

### テスト計画

- `tests/Architecture/DockerfileProvisioningTest.php` に 1 ケース追加する
  (既存の ffmpeg / fonts-noto-cjk と同じ形の静的 guard):
  「`docker/Dockerfile` が `code-review-graph==2.3.7` を版固定で導入している」。
  版を上げるときはこのテストも同時に直す = 棚卸しがレビューで見える。
- `ENV PATH` に `/home/vscode/.local/bin` が含まれることも同テストで固定する
  (これが消えると hook が無音で「未導入」告知に落ちるため)。

### リスク

- **既存コンテナには効かない**。作り直すまでは hook がセッションごとに 1 行告知する
  (黙って何もしない状態にはならない)。この受け入れ条件は「導入系」として分けてある。
- image のビルド時間が `uv tool install` の分だけ伸びる (数十秒)。ネットワーク依存が 1 つ増える。

---

## 施策 6: 規約と台帳の文書を更新する

### 変更箇所

| ファイル | 変更内容 |
|---|---|
| `AGENTS.md` | (a) §bug-hunt の「見本をマージ」記述を「常設済み」へ差し替え (b) §コードベース探索を自動更新前提へ書き換え + 実行環境前提の明示 (c) **新設**「常設 hook 配線」節 — 2 本の一覧と、索引ツール自身に配線を書かせない明文 (マーカー付き) |
| `README.md` | セットアップ節に索引ツールの前提を 2 行追記 |
| `scripts/README.md` | `code-review-graph-update-hook.sh` の台帳行を追加。`bughunt-worktree-hook.sh` の行の「見本をマージ」を「常設配線」へ更新 |
| `docs/template-divergence.md` | **D15** として起動子の逸脱を記録 |

### `AGENTS.md` に置く明文 (マーカー付き)

```markdown
<!-- CLAUDE_HOOKS_WIRING:BEGIN -->
## 常設 hook 配線

`.claude/settings.json` は git 追跡下の**配線の正本**である。配線されている hook は 2 本:

| イベント | 対象 | スクリプト | 役割 |
|---|---|---|---|
| PreToolUse | Bash | `scripts/bughunt-worktree-hook.sh` | bug-hunt provision の main 直叩きを止める |
| PostToolUse | Write / Edit | `scripts/code-review-graph-update-hook.sh` | コード索引の差分更新 |

- 起動子は終了コードの写像器を兼ねる。**PreToolUse をブロックできるのはスクリプトが
  意図して返す 97 だけ**で、構文エラー・ファイル不在・実行不能はすべて 0 に畳まれる
  (hook の故障がセッションの Bash 操作を止めない)。
- 前提コマンド: `flock` / `timeout` (どちらも欠けると索引更新は走らず、セッションごとに
  1 行だけ告知する)。
- **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
  hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
  `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
  変更で直す。
- 配線を変えたら**新しいセッションを開始するまで反映されない** (設定はセッション開始時に
  1 度だけ読まれる)。
<!-- CLAUDE_HOOKS_WIRING:END -->
```

マーカーは S12 が存在を検査する (明文ごと消せない)。

### `docs/template-divergence.md` D15 の骨子

- **逸脱**: hook の起動子を追従元の `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` ではなく、
  起動先を検証して終了コードを写像する形にした。
- **なぜ正当か (logic-driven)**: bash は構文エラーでも 2 を返し、PreToolUse の 2 は
  Bash ツールをブロックする。追従元の形では hook スクリプトの 1 文字のタイプミスが
  そのセッションの Bash 操作を全滅させうる。写像器は設定ファイル側にあるため、
  スクリプトの退行から独立している。
- **揃えている不変条件**: 常設配線であること / 起動子が絶対パスであること /
  排他がスクリプト内にあること / 配線が台帳テストで完全一致 pin されていること。
- **関連**: lctl feature `claude-hooks-wiring` (t2) / `code-index-update-hook` (v1)。

### テスト計画

- `AGENTS.md` のマーカーと明文の存在は S12 が検査する。
- `scripts/README.md` の台帳行は既存の `ScriptsReadmeInventoryTest` が deny-by-default で強制する
  (新スクリプトを足して行を書かなければ落ちる = 追加のテストは不要)。
- `docs/template-divergence.md` は機械検査を持たない (既存の運用どおり)。

### リスク

- `AGENTS.md` は churn が大きいファイルなので、マーカー区間の位置は §bug-hunt の直前に固定し、
  他節との重複記述を作らない (二重管理の回避)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `.claude/settings.json` は**セッション開始時にしか読まれない**ため、worktree 内での実配線確認ができず、統合後の新セッションでの確認が要る。さらに hook はセッション全体の挙動を変えるので、他の実装と同じ worktree に混ぜると、失敗したときに原因の切り分けができない。設定・スクリプト・台帳テストの 3 点は同時に入らないと台帳が落ちるため、分割もできない |
| 競合リスク | `AGENTS.md` / `scripts/README.md` / `.gitignore` / `docker/Dockerfile` は他タスクも触りうる。いずれも追記中心なので衝突は行単位で解消できる。`tests/Architecture/` は新規ファイルのみで衝突しない |

## 実装後の申し送り (完了報告に必ず含めること)

1. main へ統合した**後の新しいセッション**で、`Write` / `Edit` を 1 回行い、
   索引が実際に前進すること (`code-review-graph status` の Last updated が進む) を確認する。
2. 同じセッションで `ls` などの無関係な Bash を数回叩き、遅延・警告が出ないことを確認する。
3. 既存の開発コンテナは作り直すまで索引ツールが入らない。作り直さない場合は
   `uv tool install code-review-graph==2.3.7` を手で 1 度実行する。
4. 台帳の変異検出 (施策 4 のリスク欄) の結果を devnotes に残す。

---

## 関連する現行コード: scripts/bughunt-worktree-hook.sh (変更対象)
```bash
#!/usr/bin/env bash
# PreToolUse(Bash) ガード — bug-hunt の provision / provision-all を worktree 外から
# 直叩きする (= skill app-bug-hunt の Phase 0a worktree 作成をスキップする) のを
# harness レベルで provision 実行前に止める。
#
# 背景 (2026-06-20, app B1 移植): skill は一度ロードするとコンテキストに手順が展開され、
# 後続ターンで scripts/bug-hunt-shard.sh を main から直叩きすると Phase 0a を飛ばして main を汚す
# (app run 20260620-094245 S10 で実発生)。「skill 経由か」は機械判定できないが、その正しいフロー
# (Phase 0a) を通った観測可能な指紋 = 「worktree 文脈で起動しているか」を call-site で検査する。
# 早期・気づける層 (スクリプト本体ガード require_orchestrator は別軸 = 親セッション判定)。
#
# 判定: bug-hunt-shard.sh の provision/provision-all 呼び出しのうち、コマンド文字列に
#   - worktree パス (.claude/worktrees/) … 正しい Phase 0a フロー
#   - 明示オーバーライド (BUGHUNT_ALLOW_MAIN=) … 意図的 main 走行 (--keep-db 連続再走等)
#   - self-test dryrun (BUGHUNT_SELFTEST_DRYRUN=) … 自己検証
# のいずれの指紋も無いものを「main 直叩きの疑い」として拒否 (exit 2 + stderr=拒否理由)。
set -euo pipefail

input="$(cat)"
cmd="$(printf '%s' "${input}" | python3 -c 'import sys,json
try: print(json.load(sys.stdin).get("tool_input",{}).get("command",""))
except Exception: print("")' 2>/dev/null || true)"

# 対象は bug-hunt-shard.sh の provision / provision-all の**実行**のみ (subcommand は第1引数固定)。
# `bug-hunt-shard.sh<空白>provision` に限定する = コミットメッセージ等の**文字列言及**
# ("bug-hunt-shard.sh scaffold ... provision" のように間に別語が入る形) では誤発火しない。
printf '%s' "${cmd}" | grep -qE 'bug-hunt-shard\.sh[[:space:]]+provision' || exit 0

# 許可シグナルがあれば通す
if printf '%s' "${cmd}" | grep -qE '\.claude/worktrees/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN='; then
    exit 0
fi

# それ以外 = worktree 外からの直叩きの疑い → 拒否
cat >&2 <<'MSG'
⛔ bug-hunt provision を worktree 外から直叩きしようとしています (skill app-bug-hunt の Phase 0a スキップ)。
bug-hunt は worktree から走るのが既定です (main を直接汚さず todo/ ブランチに隔離するため)。次のいずれかで起動してください:
  1) /app-bug-hunt 経由 (推奨。Phase 0a が worktree を自動で切る)
  2) scripts/setup-worktree.sh bughunt-<task-id> で worktree を切り、その worktree 内
     (cd .claude/worktrees/tasks/bughunt-<task-id>) から本スクリプトを実行
  3) 意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ コマンド先頭に BUGHUNT_ALLOW_MAIN=1 を付ける
MSG
exit 2
```

## 関連する現行コード: .claude/settings.bughunt-hook.example.json (削除対象)
```json
{
  "//": "bug-hunt PreToolUse ガードの配線例。この内容を .claude/settings.json の hooks にマージすること。",
  "//_why": "bug-hunt-shard.sh provision を worktree 外 (main) から直叩きするのを harness レベルで早期にブロックする (scripts/bughunt-worktree-hook.sh)。スクリプト本体の assert_worktree_context が権威層、本フックは早期・観測層の二段防御。",
  "//_apply": "settings.json は agent 起動設定のため自動編集は保留された。ユーザーが下記 hooks ブロックを .claude/settings.json に手動マージすること。",
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "bash \"$CLAUDE_PROJECT_DIR/scripts/bughunt-worktree-hook.sh\"",
            "timeout": 10
          }
        ]
      }
    ]
  }
}
```
