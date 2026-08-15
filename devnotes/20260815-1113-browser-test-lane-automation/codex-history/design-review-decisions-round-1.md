# 対応マトリクス: design-review Round 1

## [Critical] 施策 1: `detect_privilege()` を常に呼ぶのは設計・契約と矛盾する

- 判断: **対応する** (指摘のとおり実装が契約に反していた)
- 根拠: 「要求がある場合だけ権限を見る」「充足済みなら sudo を起動しない」(成功条件 (a)) と
  提示コードが食い違っていた。契約テスト S1 を正しく書けば即赤になる。
- 対応内容: `privilege` の既定値を `none` とし、**Linux かつ `deps=missing` のときだけ**
  `detect_privilege()` を呼ぶ。`decide_install` の決定表はそのまま
  (`satisfied` / `darwin` / `unsupported` の分岐は privilege を参照しない)。

## [Critical] 施策 6: S1 が施策 1 の実装と矛盾する

- 判断: 対応する (上の修正で解消)
- 対応内容: S1 (充足済みで sudo 未起動) を**契約として維持**し、実装側を直した。

## [Warning] 施策 1: `BROWSER_TARGETS` は配列にすべき

- 判断: **対応する**
- 根拠: 未クォート展開に頼るより配列の方が堅い。`shellcheck disable` を各所に撒くより読みやすい。
- 対応内容: `BROWSER_TARGETS=(chromium webkit)` にし、渡すときは `"${BROWSER_TARGETS[@]}"`、
  メッセージに埋めるときは `"${BROWSER_TARGETS[*]}"` を使う。
  これに伴い静的契約 (契約テスト P1 / 施策 5 の単一情報源チェック) の照合文字列を
  `BROWSER_TARGETS=(chromium webkit)` に変更する。

## [Warning] 施策 2: `cp -R` の失敗が `set -e` でレーン結果を上書きしうる

- 判断: 対応する (リスク欄の記述どおりにコードを直す)
- 対応内容: `if ! cp -R ...; then echo "WARNING: ..." >&2; return 0; fi` にする。
  握り潰さず警告を 1 行出す。証跡退避は診断の補助であって合否ではない。

## [Warning] 施策 2: C11 は「WebKit 起動後も Chromium の証跡が残る」まで見るべき

- 判断: 対応する (指摘のとおり、これが施策の本質)
- 対応内容: C11 を 2 レーン走行にする。1 レーン目のスタブが
  `tests/Browser/Screenshots/chromium-x.png` を書いて exit 1、
  2 レーン目のスタブが**起動時に `tests/Browser/Screenshots` を消してから**
  `webkit-y.png` を書く (pest-plugin-browser の実挙動を模す)。
  実行後に `storage/browser-test-artifacts/chromium/chromium-x.png` と
  `.../webkit/webkit-y.png` の**両方**が存在することを検査する。
  負のコントロール: `collect_lane_artifacts` の呼び出しをループ外へ移した改変では
  chromium 側が消えること。

## [Warning] 施策 5: `composer.json` の `scripts` は配列形式も取る

- 判断: 対応する
- 対応内容: `scripts` の値を `string | list<string>` として正規化してから走査する
  (`composer.json` は実際に配列形式である)。
  想定外の型 (dict 等) は **違反として列挙する** (静かに素通りさせない)。

## [Warning] 施策 5: token の単純部分一致は空白差分を見逃す

- 判断: 対応する
- 対応内容: コメント除去後の実行行に対して `/\bplaywright\s+install\b/` で検出する。
  `install-deps` も `\binstall\b` に一致するので同じ規則で捕まる (意図どおり)。

## [Warning] 施策 5: executable bit の設定を実装手順に書くこと

- 判断: 対応する
- 対応内容: 実装順序に `chmod +x scripts/setup-browser-testing.sh` を明記する
  (git は実行ビットを追跡するので、付け忘れると本 Architecture テストだけが落ちる)。
  実行ビットの契約自体は残す (既存 `scripts/*.sh` はすべて実行可能である)。

## [Warning] 施策 6: 実 Playwright smoke が別理由で赤くなりうる

- 判断: 対応する
- 対応内容: smoke の対象を **Linux かつ `apt-get` が PATH にある環境**に限定し、
  対象外は**理由を出して skip** する (silent skip にしない)。
  `spawnSync` の `status === null` (シグナルで死んだ / 起動できなかった) は
  marker 照合へ進めず、**理由を明示して失敗**させる。

## [Suggestion] 施策 4: W18 は key の 3 要素すべてを見るべき

- 判断: 対応する
- 対応内容: `runner.os` / `runner.arch` / `hashFiles('pnpm-lock.yaml')` の 3 つを個別に検査する。

## [Suggestion] 施策 3: action の版の実在確認

- 判断: 対応する (設計に既記載。実装順序にも再掲する)

## [Suggestion] 施策 7: `storage/browser-test-artifacts/` の扱いを手順書に 1 行

- 判断: 対応する
- 対応内容: トラブルシュートに「CI アップロード用の退避先で `.gitignore` 済み。
  ローカルでは消してよい (次の実行が作り直す)」を追記する。
