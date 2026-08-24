# 対応マトリクス: impl-review Round 3

Round 3 の Codex 判定は **CHANGES_REQUESTED**。指摘は 2 件 (いずれも [Critical]) で、
どちらも「取り込んだ自己検査 S9 / S10 の子がリポジトリの `.env` を読んで起動する」という
**同一の根**から出ている。以下はその 2 件を**この TODO の中で解消した**記録である。

> 補足 (経緯): Round 3 の時点の実装は「バイト一致の取り込みを崩せない」ことを理由に
> 除去ではなく目録 (G-8) での封じ込めを選び、Codex は「G-8 は自己申告であって境界ではない /
> 正典側を先に直せ」と裁定した。**正典側 (laravel-claude-template) を本セッションから
> 変更する手段が無い**ため、裁定の趣旨 (= 子がリポジトリの `.env` を読まないことを
> **実挙動で**固定する) を aicue 側で満たす形へ切り替えた。

---

## [Critical] S9 / S10 が実際の DB パスワードと `CIPHERSWEET_KEY` を読み込む

- 判断: **対応する** (バイト一致の制約を捨てて修正する)
- 根拠:
  - Codex の指摘のとおり、**バイト一致はセキュリティ不変条件より優先できない**
    (AGENTS.md §セキュリティ不変条件「アプリ都合で緩めない」)。
  - さらに強い根拠として、**この漏れは正典 v1 (2) 自身に反している**。正典 (2) は
    「開発者ローカルの環境変数を入力集合から外す」ことを求めており、
    `proc_open` の環境配列を統制点にすることでそれを担保する設計である。
    ところが子が `bootstrap/app.php` を素で読むと Laravel は**環境ファイル**という
    別経路で開発者ローカルの値を設定へ載せてしまう。
    **したがってこの修正は「正典からの逸脱」ではなく「正典への適合」である。**
  - 機械的な代価も確認した: 取り込む 3 パスは aicue の
    `docs/template-fingerprints.json` のキーにも `adoption-debt.tsv` にも**無い**ので、
    編集しても突合 gate は赤くならず、意図的逸脱の登録も `LedgerPins` の件数更新も**発生しない**
    (将来 指紋台帳を再生成したときに 1 件の登録が要るだけである)。
- 対応内容:
  1. `tests/Unit/Support/Process/BootProbeRunnerTest.php` の検体
     `BOOT_PROBE_PATH_REPORT` に **1 行**足した —
     `$app->useEnvironmentPath(dirname((string) getenv('LARAVEL_STORAGE_PATH')));`。
     予約鍵から起動器の一時ディレクトリを導き、環境ファイルの置き場所をそこへ逃がす。
     一時ディレクトリに `.env` は無いので `safeLoad()` は何も読まず、
     **設定の入力は `proc_open` へ渡した環境配列だけ**になる。
     併せて S9 / S10 のケース別上書きへ `APP_ENV=testing` を足した
     (`.env` を読まないと `app.env` の既定が `production` になり `ProductionEnvGuard` が
     起動を止めるため。**ケース別上書き = 正典 (2) の第 3 段**であり、統制点は 1 つのままである)。
  2. 検体の報告に `env_file_path` / `ciphersweet_key_digest` / `db_password_digest` を足し、
     **S9 が実挙動で 2 方向から測る**ようにした —
     (a) 子が読んだ環境ファイルの場所が一時ディレクトリ配下であること
     (Laravel は `environmentFilePath()` の 1 本しか読まないので、これが決定的である)、
     (b) **番兵**: リポジトリの `.env` に実在する `CIPHERSWEET_KEY` / `DB_PASSWORD` の値が
     子の設定に**現れない**こと。番兵が `.env` に無い / 空のときは
     「この検査が空振りする」と明示して**赤にする** (空振りの緑を作らない)。
  3. 逸脱の理由・実測・限界を当該 const の docblock に逐語で書いた
     (バイト一致からの意図的な逸脱であること、なぜセキュリティを優先したか)。
- 負の裏取り (実測):
  - 修正**前**の形 (置き場所を移さない) で子を起こすと
    `env_file_path = <repo>/.env` かつ `ciphersweet_key_digest` が
    **リポジトリの `.env` の値の digest と一致**した (= 漏れの再現)。
  - 修正**後**は `env_file_path` が一時ディレクトリ配下になり、番兵の digest は一致しない。
  - 自己検査 14 本すべて緑。

## [Critical] G-8 は自己申告の目録であり、境界でも緩和策でもない

- 判断: **対応する** (指摘を全面的に受諾)
- 根拠: Round 3 の指摘は正しい。旧 G-8 は
  「`true` と申告した entry が 1 件」という事実しか固定しておらず、
  「リポジトリの `.env` を読む子が 1 件だけ」というテスト名の主張とは距離があった (fail-open)。
- 対応内容: 上の修正で危険面そのものが消えたので、G-8 を**目録から不変条件へ**書き換えた:
  1. `boots_repository_env` が真の entry は**ちょうど 0 件**である (完全一致 pin)。
  2. `child_entry` の entry は **`behaviour_proof` (裏取りの検査の名指し) を必ず持つ**
     (空では通らない)。子入口を足す人は「この子が `.env` を読まないことを何が測るのか」を
     書くことになる。
  3. `child_entry` 以外は `boots_repository_env` が偽 **かつ** `behaviour_proof` が空である
     ことを両方向で固定する (kind の取り違えの検出)。
  4. `child_entry` の母集団が空のまま緑になる形を塞いだ
     (AGENTS.md §静的検査の共通規約 (b) の 3 点目)。
  5. docblock の「主張しないこと」を書き直した — 本検査が機械で見るのは
     **申告と名指しの存在**までであり、名指しした検査が実際に何を測っているかは見ない。
     **実挙動の防壁は名指しされた 2 本 (S9 / P-8) そのものである**と明記した。

## [Warning] `BootProbeResult` の PHPDoc の食い違い (`timedOut && exitCode === 0`)

- 判断: **見送る** (Round 2 の判断を維持。Codex も「上流申し送りとする判断は受け入れる」と応答済み)
- 根拠: 呼び出し側は `timedOut` を見る契約 (詳細設計 E 節) で、誤記に依存していない。
  取り込み元の文面であり、実行時のバグではない。

## 受入条件・性能測定に関する指摘

- 判断: **対応する** (全体テストの連続 green を取り直す)
- 根拠: Round 2 の [Critical]「green / fail / green は連続条件未達」は機械的な受入条件である。
- 対応内容: 本ラウンドでは main を取り込み直した (`todo/T249` へ `main` をマージ) うえで
  全体テストを走らせ直し、連続 green を取得する。

---

## main の前進に追随した変更 (Round 3 以降に発生。Codex 未レビュー分)

`todo/T249` の分岐後に main が 4 タスク分前進し、**子プロセスを起こす別 feature の実装**
(`process-concurrency-test-harness`) が入った。本 gate は deny-by-default なので、
これを申告するまで赤になる (= 意図した摩擦が実際に働いた)。

| 変更 | 内容 |
|---|---|
| 軸 A に 1 件追加 | `tests/Support/Concurrency/SymfonyProbeProcessFactory.php` (`launches_app: true`) |
| **G-2 を 1 件固定 → 2 件の完全一致 pin へ** | 本 feature の boundary は「子を 2 本立てて合図で同期させる並行テスト」を明示的に**除く**。別 feature が自分の回収規約 (単一の絶対 deadline) を持つので、1 本の起動器へ統合するのは「別物の概念を似ているからで統合する」ことになる (AGENTS.md 思考原則 4)。固定するのは**申告先の集合そのもの**であり「起動経路が 1 本」ではない (それは字句走査では裏が取れない、と docblock が既に明記している) |
| 軸 B に 2 件追加 | `tests/Support/Concurrency/idempotency-claim-probe.php` (`child_entry`。専用の一時 env ファイルへ固定するので `boots_repository_env: false`、裏取りは子の終了コード 70 / 72 の自己検査) / `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` (`inventory`) |
