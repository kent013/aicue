# 対応マトリクス: impl-review Round 4

Round 4 の Codex 判定は **CHANGES_REQUESTED**。
「Round 3 の実害 (S9 / S10 の子がリポジトリの `.env` を読む) は解消しており、
バイト一致を崩した判断も妥当・正典 v1 (2) への適合という説明も成立する」と認めたうえで、
**新しく書いた実挙動テストに偽グリーンと資格情報の取り扱いの問題が残る**という指摘である。

---

## [Critical] S9 が未正規化の `env_file_path` を `isInside()` に渡している (Round 1 の P-14 と同じ穴)

- 判断: **対応する**
- 根拠: 指摘のとおり。`BootProbeRunner::isInside()` は両引数が正規化済みであることを契約にしており、
  `<temporaryRoot>/x/../../repo/.env` を配下と誤判定できる。
  配下判定は**そもそも要らない** — 期待値は起動器が予約鍵で渡した一時ディレクトリから
  一意に決まる (`LARAVEL_STORAGE_PATH = <root>/storage` なので `dirname()` は `<root>`、
  `environmentFilePath()` は `<root>/.env`)。
- 対応内容: 配下判定を捨て、**完全一致**にした。
  `expect($report['env_file_path'])->toBe($result->temporaryRoot.'/.env', …)`。
  「正規化の前提が要らないので、完全一致が最も強い」ことをコメントに書いた。

## [Critical] 番兵抽出が Dotenv の構文と一致せず、実資格情報を digest で出力し、偽レッドにもなる

- 判断: **対応する** (番兵の作り方を全面的に替える)
- 根拠: 3 つとも正しい。
  1. `preg_match('/^KEY=(.+)$/m')` は `export` 付き・インラインコメント・変数展開・
     引用・重複定義を解釈できない。`DB_PASSWORD=secret # local` では
     `secret # local` を hash するので、子が `secret` を読んでいても不一致になり**漏洩を見逃す**。
  2. 追跡外の `.env` に実資格情報が在ることをテスト成立条件にすると、
     見本から起こしたチェックアウト (`.env.example` は `CIPHERSWEET_KEY=` が空で
     `DB_PASSWORD` を持たない — 実測) や秘密を置かない CI で**偽レッド**になる。
  3. 実 DB パスワードの無塩 SHA-256 を期待値として表示すると、
     失敗時の出力が**オフライン推測の検証器**になる。
- 対応内容: `.env` を読むのをやめ、**制御された非秘密の番兵**へ替えた。
  子は digest ではなく**真偽 2 つ**を報告する —
  `ciphersweet_key_present` / `db_password_present`。
  この 2 つの設定値は**環境ファイルからしか来ない** (`config/ciphersweet.php` は既定を持たず、
  `config/database.php` は空文字を既定にする) ので、**非空なら環境ファイルが読まれた証拠**になる。
  S9 は両方が偽であることを**無条件で**測る。
  - 秘密も digest もテスト出力に出ない
  - `.env` の中身・存在に依存しないので偽レッドにならない
  - 条件分岐が消えたので空振りの緑も無くなった (Round 4 時点の実装は
    「値が在るときだけ測る」形で、そこも指摘の余地があった)
- 負の裏取り (実測。同一の起動器で退避の有無だけを替えて比較):

  ```
  退避なし: env_file_path=<repo>/.env.testing  env_file_exists=true
            ciphersweet_key_present=true  db_password_present=true   ← 4 つとも赤側
  退避あり: env_file_path=/tmp/boot-probe-…/.env  env_file_exists=false
            ciphersweet_key_present=false db_password_present=false  ← 4 つとも緑側
  ```

## [Critical] `idempotency-claim-probe.php` の `behaviour_proof` が裏取りになっていない

- 判断: **一部対応する** (申告文を正確にし、機械の裏打ちを 1 つ足す。実挙動の測定は足さない)
- 根拠:
  - 指摘は正しい。実効 DB 座標の確認は、DB 値がプロセス環境で上書きされていれば
    `.env` から別の資格情報を読んでいても通る。**あの申告文は裏取りを過大に述べていた。**
  - ただし当該ファイルは**別 feature (lctl: `process-concurrency-test-harness`) の持ち物**で、
    観測は fail-closed な DTO (`ConcurrentProbeObservation`) を通る。項目を足すには
    子 → DTO → runner → 呼び出し側の 4 段を変えることになり、
    本 TODO の boundary (「子を 2 本立てて合図で同期させる並行テストは含まない」) を越える。
    T249 で他 feature の契約を書き換えるのは思考原則 2 (今必要なものだけ作る) に反する。
- 対応内容:
  1. 申告文を**事実へ直した** — 「環境ファイルの切り替えそのものが効いたことを直接測る検査は
     **無い**」と明記し、切り替えが段 8 の `useEnvironmentPath()` /
     `loadEnvironmentFrom()` で構造的に固定されていることを書いた。
  2. **機械の裏打ちを 1 つ足した (G-9 新設)** — `child_entry` の申告ファイルは
     正規化トークン (名前または文字列) に `useEnvironmentPath` を**必ず持つ**。
     Laravel が読む環境ファイルはこの呼び出しでしか動かないので、
     **持たない子入口は既定でリポジトリの `.env` を読む** = 新しい子入口を素直に足すと赤になる。
     併せて `behaviour_proof` の先頭語が**実在するパス**であることも機械で確かめる
     (実在しない検査名で申告を通す形を塞ぐ)。
  3. G-9 の限界を docblock に書いた — **呼び出しが効く位置に在ることは字句では見ない**。
     位置の正しさは各経路の実挙動の検査 (S9 / P-8) が担い、
     `idempotency-claim-probe.php` には実挙動の検査が無いことを申告に明記した。
  4. 走査器の見本検査を 9 件足した (名前トークン / 文字列トークン / ヒアドキュメント本文の正例、
     コメントのみの負例 2、接頭辞・打ち消し・接尾辞の 3 形の負例、
     「退避を持たない子入口」の負例 = G-9 で赤になる形)。

## [Warning] G-8 のテスト名が実測ではなく申告値の集計である

- 判断: **対応する**
- 対応内容: テスト名を
  「G-8 **申告上**リポジトリの .env を読む子は 0 件で、child_entry は裏取りの検査を名指ししている」
  へ改めた。docblock の「主張しないこと」も、実在しない名前は G-9 が落とすことを反映して書き直した。

## [Suggestion] `behaviour_proof` は任意の非空文字列で通る (機械の結び付きが無い)

- 判断: **一部対応する**
- 対応内容: G-9 で「先頭語が実在するパスであること」を機械で確かめるようにした。
  検査の**中身**との結び付きは依然として無いので、その旨を docblock に明記し
  「セキュリティ境界ではなく人間向けの目録である」という位置付けを維持した。

## [Warning] 起動器の docblock に「環境ファイルの隔離は呼び出し側の必須契約」と明記すべき

- 判断: **対応する** (ただし置き場所を替える)
- 根拠: 指摘の趣旨に同意する。ただし `tests/Support/Process/BootProbeRunner.php` は
  **取得時の sha256 と一致したまま**の共有ファイルであり、ここを編集すると
  2 本目のバイト一致も崩れる。S1 の設計は「取り込んだ docblock の訂正は
  `FakeWiringProbeRunner` の docblock に置く」と定めているので、そこへ書く。
- 対応内容: `FakeWiringProbeRunner` の訂正表へ 1 行追加
  (「統制点は `proc_open` の環境配列だけ」→ **プロセス環境はそれで唯一だが `.env` は別経路**) し、
  続けて **「呼び出し側の必須契約」** 節を新設した — Laravel を起こす子は環境ファイルの
  置き場所を自分で退避すること、退避の手段は 2 通り (専用の環境ファイル / 実在しない場所)、
  この契約を守る検査は G-8 / G-9 と各経路の実挙動の検査であること。

## [Warning] S11 が `storage/framework/testing` を再帰作成した場合に戻さない

- 判断: **対応する**
- 根拠: 指摘のとおり。バイト一致を既に意図的に崩しているので「共有ファイルだから見送る」は成り立たない。
- 対応内容: `$createdBase` を持ち、`finally` で**自分が作った場合だけ**戻す。
  `--parallel` の他 worker が同じ場所を使うので、**空でなければ触らない**。

## [Warning] `BootProbeResult` の PHPDoc の食い違い (`timedOut && exitCode === 0`)

- 判断: **見送る** (上流申し送り。Round 2 / Round 3 でも同じ判断を Codex が受け入れている)
- 根拠: 呼び出し側は `timedOut` を見る契約で誤記に依存しておらず、実行時のバグではない。
  当該ファイルは**バイト一致のまま**保つ方が価値が高い (実装 2 本の一致は維持できている)。

## [Warning] 詳細設計の受入条件が「取り込み 3 本すべてバイト一致」のままである

- 判断: **既に対応済み** (Round 4 のプロンプトを組んだ時点では反映が間に合っていなかった)
- 対応内容: `detailed-design.md` の S1 に **【実装時の変更】** 節を挿し、
  受入条件の「取り込みの同一性」も
  「実装 2 本は sha256 一致 / 自己検査 1 本はセキュリティ修正による意図的差分」へ書き換えてある。

## [Critical] 2 回目の `composer test` / `pnpm test` / `pnpm test:packages` が未完了

- 判断: **対応する**
- 根拠: 機械的な受入条件である。
- 対応内容: Round 4 の修正をすべて入れ終えたうえで、**最終形で** `composer test` を
  2 回連続 + `pnpm test` + `pnpm test:packages` を走らせ直し、結果を Round 5 のプロンプトと
  最終報告に載せる (Round 4 のプロンプトに載せた 2 回目は、その最中に修正を入れたため
  最終形の証跡として数えない)。
