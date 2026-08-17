Round 3 の指摘への対応を報告します。対応マトリクスと変更箇所の抜粋を示します。再判定をお願いします。

---

# 対応マトリクス: design-review Round 3

## [Warning] 施策 1: 「同じ正規化関数」の責務が `covers()` のシグネチャと合わない
- 判断: 対応する
- 根拠: そのとおりで、`covers(declaration, rel_path)` は `repo_root` を受け取らないのに、
  4 段の検査には基点が要る。このままでは実装時に必ず食い違う。
- 対応内容: 検証を 2 層へ分けた。
  - **層 1 (字句・リポジトリに依存しない)**: `normalize(raw) -> tuple[str, ...]`。
    生の文字列のまま非正規形を拒否し、`PurePosixPath.parts` を返す。実在検査・包含検査・
    循環検査・`covers()` の**すべてが共用する**。
  - **層 2 (`load()` のときだけ)**: `repo_root` 内への収まり / 実在 / 各要素の symlink 拒否。
  `covers()` は層 1 だけを使い、非正規形の引数を拒否する (公開シグネチャは変えない)。
  インターフェース表にも `normalize()` を明示した。

## [Suggestion] 施策 1: symlink は未解決のパスの各要素で見る
- 判断: 対応する
- 対応内容: 「**未解決の `repo_root / rel_path` を先頭から 1 要素ずつたどり、各要素**について
  拒否する (先に完全解決すると、どの要素が symlink だったかが失われる)」と明記した。

## [Warning] 施策 2: 「stderr に 1 行」は argparse の既定動作と一致しない
- 判断: 対応する (提示された 2 案のうち後者を採る)
- 根拠: `ArgumentParser.error()` の上書きは標準ライブラリの作法から外れる。「フレームワークの
  レンジ内でやる」(思考原則 1) にも反する。
- 対応内容: 契約を 2 つに分けた。
  - 宣言不正 (`DeclarationError`): stderr に **1 行**、traceback なし。
  - 引数エラー (argparse): stderr のみ (複数行でよい)、traceback なし。
  - どちらも exit 2 で **stdout には 1 バイトも書かない**。

## [Warning] 施策 3: stderr 契約に対応する検査が無い
- 判断: 対応する
- 対応内容: 20 番を「終了コード 2 / stdout が空 / **stderr が 1 行の非空で traceback を含まない**」へ、
  21 番を「終了コード 2 / stdout が空 / **stderr に traceback を含まない** (行数は問わない)」へ拡張した。

## [Suggestion] 施策 3: テスト 9 と 15 を層ごとの責務へ分ける
- 判断: 対応する
- 対応内容: 9 番を**層 2 (`load()`)** の検査 (リポジトリ外へ出る / 不在 / symlink。親ディレクトリが
  symlink の検体を含む) に、15 番を**層 1 (`normalize()` / `covers()`)** の検査 (絶対パス / `.` /
  `..` / 空セグメント / 末尾スラッシュ / バックスラッシュ / 実在するが正規形でない / セグメント境界。
  `covers()` は `repo_root` を要求しない) に分けた。

## [Suggestion] 施策 6: 抜粋の括弧が閉じていない
- 判断: 見送る (修正不要)
- 根拠: 実設計書では次の行で閉じている (`(デプロイ定義が無い。AGENTS.md の route:cache の
  運用要件と同じ立場)。`)。抜粋時の行分割による見え方であり、本文に欠落は無い。


---

## 変更後の該当箇所 (抜粋)

### 施策 1: パス検証の 2 層化

- **パスの検証は 2 層に分ける** (層を混ぜると `covers()` が `repo_root` を要求する形になり、
  公開インターフェースと契約が食い違う)。

  **層 1: 字句の正規形 (リポジトリに依存しない)** — `normalize(raw) -> tuple[str, ...]`。
  実在検査・包含検査・循環検査・`covers()` の**すべてがこの 1 本を共用する**。
  1. **生の文字列のまま**拒否する: 絶対パス / 先頭スラッシュ / 末尾スラッシュ / バックスラッシュ /
     空セグメント (`a//b`) / セグメントが `.` または `..`。
     `PurePosixPath` へ入れた後では `a//b` や `.` が畳まれて**元の非正規形を検出できない**ため、
     変換より前に見る。
  2. その後で `PurePosixPath.parts` を作って返す。

  **層 2: リポジトリ依存の検証 (`load()` のときだけ)** —
  3. `repo_root` を基点に解決し、結果が **`repo_root` の外へ出るものを拒否**する。
     `path_prefixes` はさらに **`repo_root/app` の内側**であることを確認する。
  4. 実在を確認する。
  5. **未解決の `repo_root / rel_path` を先頭から 1 要素ずつたどり、各要素**について
     シンボリックリンクを拒否する (先に完全解決すると、どの要素が symlink だったかが失われる。
     最後の要素だけを見ると、親ディレクトリが symlink の場合を通してしまう)。

  `covers()` は**層 1 だけ**を使い、非正規形の引数は拒否する。
  「`app/../tests` は先頭が `app` だから通る」といった迂回は層 1 で閉じ、
  「repo の外・不在・symlink」は層 2 で閉じる。
- **一致はパス要素の境界で行う**: `app/Foo` は `app/Foobar` を覆わない

### 施策 2: 公開インターフェースと診断の契約

| 関数 / CLI | 契約 |
|---|---|
| `load(path, repo_root)` | 検証に通れば `OutOfScopeDeclaration` を返す。通らなければ `DeclarationError` |
| `normalize(raw)` | 層 1 (字句)。正規形の相対パスの要素列を返す。非正規形は `DeclarationError` |
| `covers(declaration, rel_path)` | そのパスがどの面に覆われるか (`OutOfScopeEntry` か `None`) を **`normalize()` の結果同士**でセグメント境界比較して判定 (`repo_root` を要求しない)。宣言は antichain なので結果は並び順に依存しない |
| `--declaration PATH` | 宣言ファイル。既定は本ファイルと同じディレクトリの `out-of-scope.json` |
| `--repo-root PATH` | 実在検査の基点。既定は `Path(__file__).resolve().parents[4]` (`coverage` → `app-bug-hunt` → `skills` → `.claude` → リポジトリルート) |
| `--emit markdown` | 面 / 理由 / 代替検証 / 対象パスの表を stdout へ (人が読む用) |
| `--emit json` | 正規化済みトップレベル object を stdout へ (UTF-8・`ensure_ascii=False`・`indent=2`・末尾改行・**宣言の並び順を保つ**) |
| 終了コード | `0` = 成功 / `2` = 宣言不正 (**stdout に何も出さない** fail-closed)。argparse の引数エラーも 2 |

- `--declaration` / `--repo-root` は**自己テストのために必須**である。実ファイルを書き換えずに
  一時ディレクトリの不正な宣言を CLI へ渡せないと、終了コードの契約 (2 かつ stdout が空) を
  実プロセスで確かめられない。
- 読み込み失敗 (`OSError` / `UnicodeError`) と JSON parse 失敗 (`JSONDecodeError` /
  `RecursionError`) は**理由を問わず `DeclarationError` へ落とす** (traceback つきの exit 1 に
  漏らさない = 呼び出し側は「stdout を信用しない」だけでよい)。
- 失敗時の診断は 2 種類あり、**契約を分ける** (argparse の既定動作は usage を含む複数行なので、
  1 行に揃えるには `ArgumentParser.error()` の上書きが要る。標準ライブラリの作法から外れないよう
  上書きはしない)。
  - **宣言不正 (`DeclarationError`)**: stderr に **1 行**の短いメッセージ。traceback は出さない。
  - **引数エラー (argparse)**: stderr のみ (複数行でよい)。traceback は出さない。
  - どちらも **exit 2** で、**stdout には 1 バイトも書かない** (これが fail-closed の実体である)。
- **markdown 出力のセル正規化**: 値に含まれる `\` と `|` を退避し、改行を空白へ畳んでから 1 セルへ
  入れる (表を壊さない)。`|` や改行を含む値を持つ検体で表の列数が保たれることを自己テストで見る。
- 置き場所は `.claude/skills/` 配下のみ。**`app/` からは参照しない** (製品実行経路に混入させない)。

### 施策 3: 層ごとに分けたテスト行と stderr 契約

331:| 9 | **層 2 (`load()`)**: リポジトリ外へ出る (`app/../../etc`) / 不在 / シンボリックリンク (**最終要素だけでなく親ディレクトリが symlink の検体も含める**) を拒否 | 境界検査の迂回 |
338:| 15 | **層 1 (`normalize()` / `covers()`)**: 絶対パス / `.` / `..` / 空セグメント / 末尾スラッシュ / バックスラッシュ / **実在するが正規形でない** (`app/../app/Filament`) を拒否し、セグメント境界で判定する (`app/Foo` は `app/Foobar` を覆わない)。`covers()` は `repo_root` を要求しない | 素の前方一致の混入と層の混線 |
343:| 20 | CLI: 不正な宣言 (`--declaration` で一時ファイルを渡す) は **終了コード 2 / stdout が空 / stderr が 1 行の非空で traceback を含まない** | fail-open と診断の垂れ流し |
344:| 21 | CLI: 未知の `--emit` 値も **終了コード 2 / stdout が空 / stderr に traceback を含まない** (行数は問わない) | 引数エラーの取り違え |
618:| 9 | 「pcov 未導入」という古い断定が戻らない | `test_naming_no_stale` への 3 語追加 (施策 6) | 採る |
