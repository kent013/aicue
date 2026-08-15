# 対応マトリクス: design-review Round 2

## [Critical] 施策 1: 宣言から entry を消す変異を検出できない
- 判断: 対応する (ただし提案された `ExternalSeamInventory` との集合一致は採らない)
- 根拠:
  - 指摘は正しい。宣言が唯一の正本になると、削除時に provider の bind もデータセットも
    同時に縮むため、どの検査にも映らない。旧 3-8 が持っていた検出力である。
  - 一方 `ExternalSeamInventory` との集合一致は採れない。同目録は
    **ファイル保存 (AWS / Flysystem) と LLM (Prism) を意図的に母集団へ入れない**
    (AGENTS.md ドメイン規約 9。理由は「同じ到達事実を 2 箇所で宣言しない」)。
    採ると 7 件中 5 件しか覆えず、覆うために目録の母集団を歪めることになる (思考原則 4)。
- 対応内容: 3-16 を新設し、`swaps()` の abstract 一覧を**件数付きで gate 側に写して固定**する。
  本リポジトリに同じ作法の先例が 2 つある (`FakeClassReferenceInvariantTest` の
  「配置例外は 2 件から増えていない」「参照 allowlist は 5 件から増えていない」)。
  リスク欄に「3-16 を消すと削除が無音になる」ことと、上の不採用理由を明記した。

## [Warning] 施策 1: 「DTO を返す」の記述が設計と不一致
- 判断: 対応する
- 対応内容: 「外部応答ではなく内部の宣言データなので DTO / JsonResource の対象外。
  差し替え 1 本は値オブジェクト `ExternalFakeBinding` を使う」へ書き換えた。

## [Warning] 施策 1c: `bindPairs()` は `app()->bind(…)` を読めない
- 判断: 対応する
- 根拠: 指摘のとおり。現行の `bindPairs()` は `$this->app->bind(…)` だけを読み、
  `app()->bind(…)` は別経路 (`disallowedIndirectAccess`) で「間接到達」として扱われている。
  レーン側は素直に `app()->bind(…)` と書くため、そのままでは素通りする。
- 対応内容: `bindPairs()` を container へ到達する 4 形 (`$this->app->bind` / `app()->bind` /
  `App::bind` / `Container::getInstance()->bind`) に対応させ、`use function app as …` の
  別名解決も使う。自己検査に 4 形 + 別名の正例と、`::class` でない負例を入れる。
  provider 側の検査 (`disallowedContainerCalls` / `disallowedIndirectAccess`) は変えない。

## [Warning] 施策 2: S-9 が実在確認だけで対応を保証しない
- 判断: 対応する
- 対応内容: S-9 を 3 条件へ (実在 / `tests/Feature/` 配下 / **テストのソースが対象 seeder
  クラスを参照している**)、加えて S-10 (無関係な既存テストへ差し替えると赤くなる負のコントロール)
  を追加した。ガードを要求しない区分は `null` 固定 (値があったら赤) も明記。

## [Warning] 施策 2: 前提テストの紐づけが entry の一部か別 mapping か曖昧
- 判断: 対応する
- 対応内容: **entry のフィールド**に固定した
  (`array{role: BughuntSeedRole, reason: string, guardPremiseTest: non-empty-string|null}`)。
  別 mapping にするとキー集合の一致検査が別途要り、目録が 2 つに割れるため。

## [Critical] 施策 4: 子プロセスの環境隔離の説明が矛盾 / `.env` 経由で実資格情報が入る
- 判断: 対応する
- 根拠: 指摘のとおり。「明示分だけ上書き」は親の環境を継承する意味になり、
  かつ Laravel が `.env` を読む限り実資格情報は子の設定へ入る。
- 対応内容: 隔離を 3 段で定義し直した。
  1. プロセスの環境変数は `env -i` で空にしてから必要分だけ渡す
     (bug-hunt スクリプトが DB 資格情報を遮断するときと同じ手)
  2. 設定の出所を**専用の一時環境ファイル 1 つだけ**にする
     (子が `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定。親の `.env` /
     `.env.bughunt.local` は読ませない)
  3. 書いてよいキーを 7 つに限り (`ALLOWED_ENV_KEYS`)、P-6 が集合で固定する。
     受入の変異にも「一時環境ファイルへ `STRIPE_SECRET` を足すと赤くなる」を追加した

## [Critical] 施策 4: probe の `$provider` が未定義
- 判断: 対応する
- 対応内容: `config('template.social_providers')` (現行 shape は 種類名 => 設定 の連想配列) から
  `array_key_first()` で取り、`Assert::isArray()` / `Assert::stringNotEmpty()` で
  空・非文字列を fail-closed に落とすコードを probe へ書いた。

## [Warning] 施策 4: 設定キャッシュ有無の期待動作が未定義
- 判断: 対応する (2 択のうち「キャッシュ無しの隔離条件で観測する」を選ぶ)
- 根拠: 共有の `bootstrap/cache/config.php` を作ったり消したりする方式は、指摘のとおり
  並列実行と衝突する。
- 対応内容: `APP_CONFIG_CACHE` を存在しない一時パスへ向け、**キャッシュ無しの起動**として
  観測することを明記。あわせて「キャッシュ有りの起動は観測しない (その事故は施策 3 の
  二重判定が受け持つ)」を保証しないものとして書いた。

## [参考] P-3 / P-4 の再判定
- Round 2 で「反論は妥当」と再判定され、追加の変更要求は無し。設計はそのまま維持する。
