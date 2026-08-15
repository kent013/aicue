# 対応マトリクス: design-review Round 1

## [Critical] 施策 1 / 5 抽出条件の失敗系がテストできない

- 判断: **反論 (実装を読んで確認) + テスト手順を明記**
- 根拠: Laravel 12 の実装 (`vendor/laravel/framework/src/Illuminate/Foundation/Application.php`) は
  `isLocal()` が `$this['env'] === 'local'`、`runningUnitTests()` が
  `$this->bound('env') && $this['env'] === 'testing'` である。どちらも**同じコンテナ束縛 `env` を読む**ので、
  テスト内で `env` を差し替えれば両方 false にできる。
- 対応内容: テスト手順を「`$this->app->instance('env', 'production')` で束縛を差し替えてからコマンドを実行し、
  非 0 終了と標準出力が空であることを確認する」と明記する
  (`detectEnvironment()` は `$_SERVER['argv']` を見る経路があるので使わない)。
  判定を service へ切り出す案は、条件が 1 行の述語なので採らない (層を増やすだけになる)。

## [Warning] 施策 1 エラー出力が stdout に混ざる

- 判断: 対応する
- 対応内容: エラーは**標準エラー**へ書く (`$this->output->getErrorStyle()`)。
  Feature テストで「失敗時に標準出力が空」であることを固定する。

## [Warning] 施策 1 `list<non-empty-string>` の根拠が弱い

- 判断: 対応する
- 対応内容: `is_string($v) && $v !== ''` で絞る。空文字を落とした結果 `methods` が空になる route は
  抽出契約違反として**生成器の段 1 で致命 (exit 2)** にする (PHP 側は事実を出すだけ、という責務分担を保つ)。

## [Warning] 施策 2 未知キーを拒否できない

- 判断: 対応する
- 対応内容: 注釈の許可キーを `kind` / `story` / `kubun` / `reason` に固定し、
  未知キーは段 2 の drift にする (トップレベルは `schema_version` と `routes` のみ)。

## [Warning] 施策 2 区分 `外` / `終` に `story` が残る

- 判断: 対応する
- 対応内容: 区分 `外` / `終` では `story` を**禁止**する (書いてあれば drift)。
  render 側で `-` に潰すだけだと古い割当が見えないまま残るため。

## [Suggestion] 施策 2 移行ログに集合一致を残す

- 判断: 対応する
- 対応内容: 移行手順に「旧表の route 集合と新 `annotations.toml` の route 集合が一致することを
  移行スクリプトが出力し、その出力を devnotes に残す」を明文化。

## [Critical] 施策 3 面の除外条件が「保証しないもの」と矛盾

- 判断: **一部対応 (文言を直す) + 除外リストの再追加は反論**
- 根拠: 除外を 2 つに絞ったのは概念設計の裁定であり、根拠は
  「死んだ除外規則を並べると、将来 `api/` 配下に `web` group を宣言した route ができたときに
  **黙って落とす**」ことにある。今それらが母集合に入らないのは `web` group を宣言していないからで、
  実測 0 件。もし宣言するようになったら、それは**人が判断すべき事象**なので未注釈 drift として出す。
- 対応内容: 矛盾していたのは「保証しないもの」の書き方なので、そちらを直す —
  「**`web` group を宣言していない面 (機械向け API / Filament 管理画面 / MCP / webhook 等) には沈黙する**」
  と書き、`api/` 等を無条件に沈黙対象と読める表現をやめる。
  併せて「除外表の 2 面 (oauth / livewire) は面の定義として除く」ことを 1 行で併記する。

## [Critical] 施策 3 `generate` の部分更新が fail-closed に反する

- 判断: 一部対応 (窓を縮める。ロールバックは作らない)
- 対応内容: 手順を「**2 つの一時ファイルを書き切る → 検証 → 2 回の `os.replace()` を連続実行**」に変え、
  窓を replace と replace の間だけにする。replace が失敗したら **exit 2** で
  「再実行してください」を出す (旧内容へ戻す機構は作らない = 生成物の性質に対して過剰)。
  自己テストで「2 本目の `os.replace` が失敗したとき exit 2 になり、次の `check` が段 3 で
  drift を報告する」ことを固定する。

## [Warning] 施策 3 `check_catalog()` の契約が曖昧

- 判断: 対応する
- 対応内容: 段 4 の契約を明文化する。
  (a) 対象はヘッダが `| id | 機能 (actor→outcome) | 代表機構 (route name) |` の表だけ、
  (b) id は `^[A-Z]{2,5}-[0-9]{2}$`、重複したら drift、
  (c) 代表機構セルは**バッククォートで囲まれた token だけ**を route 名候補とし、
      `/` 区切りの複数記載を許す。丸括弧の説明 (`(機構横断)` 等) とパス (`routes/api.php`) は無視、
  (d) `*` で終わる token は前方一致で 1 件以上の route に当たれば良い、
  (e) 実在判定の母集合は**抽出した全 route 名** (web 面に限らない)、
  (f) 当たらない token があれば drift。

## [Warning] 施策 3 セルの `|` / 改行

- 判断: 対応する
- 対応内容: 表のセルに入る値 (uri / route 名 / 題名 / 区分 / story) に
  `|` / CR / LF が含まれていたら段 2 の drift にする (エスケープ規約は作らない。
  下流 `correlate.py` が `split("|")` で読むため、禁止する方が安全)。
  理由 (`reason`) は表の外の箇条書きに出すので改行のみ禁止。

## [Warning] 施策 4 薄い shell から `cd` が消えた

- 判断: 対応する (cwd 非依存を契約にする)
- 対応内容: 生成器は `Path(__file__).resolve().parent.parent` をリポジトリルートとして確定し、
  `subprocess` の `cwd` も明示する (cwd 非依存)。
  シェルは起動だけを行い、**別の cwd から起動しても同じ結果になること**を sandbox テストで固定する。

## [Suggestion] 施策 4 「判定語を持たない」テストの対象範囲

- 判断: 対応する
- 対応内容: 静的検査の対象を**コメント行を除いた実装行**に限定する。

## [Warning] 施策 5 生成器の entry を注入可能にする

- 判断: 対応する
- 対応内容: `run_check(repo_root, scanner=scan)` / `run_generate(repo_root, scanner=scan)` を公開 API にし、
  CLI (`main`) はそれを呼ぶだけにする。自己テストは fake scanner を注入する。

## [Warning] 施策 5 sandbox の shim 契約が曖昧

- 判断: 対応する
- 対応内容: sandbox の契約を明記する — `PATH` の先頭に sandbox の `bin` を置き、
  `php` shim は**引数を無視して**固定 JSON を標準出力へ出す。実 `php` / DB / APP_KEY に依存しない。

## [Warning] 施策 6 文書の線引き

- 判断: 対応する
- 対応内容: 「沈黙する対象 (= `web` group を宣言していない面)」と
  「注釈で `外` として可視化する対象 (= web 面内)」の線引きを AGENTS.md / SKILL.md / D19 に書く。
