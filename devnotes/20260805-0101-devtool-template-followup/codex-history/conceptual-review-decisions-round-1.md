# 対応マトリクス: conceptual-review Round 1

## [Critical] 観点3: codex-model-consistency の drift guard が不十分 (4 件中 1 件が移動しても緑になる)

- 判断: **対応する**
- 根拠: 正当な指摘。「0 件なら fail」は「全滅」しか検出しない。本リポジトリには既に
  deny-by-default の inventory 方式が定着している (`NestedRouteIdorDefenseTest` の inventory、
  `tests/js/architecture/logout-call-site-inventory.test.ts`)。同じ作法に揃えるのが筋。
- 対応内容: 施策 2 を「glob 実測集合 と 明示 inventory の**集合一致**検査」に変更。
  - inventory に無い SKILL.md を発見 → fail (新規スキルのモデル記述が野放しになるのを防ぐ)
  - inventory にあるのに実在しない → fail (移動・改名・削除で守備範囲が痩せるのを防ぐ)
  - 併せて「0 件 fail」も自明に含まれる
  - inventory は 9 スキル全ての SKILL.md を登録する (モデル記述を持たない 5 本も対象。
    将来そこにモデル指定が生えたときも捕まえるため)

## [Critical] 観点4: 証拠の日付が未来 (2026-08-05) — 現在は 2026-08-04 UTC

- 判断: **反論する** (ただし誤解を招く記述は修正する)
- 根拠: 本リポジトリの devnotes 命名規約は `TZ=Asia/Tokyo date +%Y%m%d-%H%M`
  (`.claude/skills/app-design/SKILL.md` §1-1) であり、**日付は一貫して JST (UTC+9)** で記す。
  設計着手時刻は JST 2026-08-05 01:01 = UTC 2026-08-04 16:01 で、未来ではない。
  c2c 台帳の inbox も `生成: 2026-08-05 キュレーター巡回` と JST で出力される。
  実測 (codex gpt-5.5 / xhigh の疎通、session `019fcd82`) も同時刻に本 devcontainer で実行済み。
- 対応内容: 設計書冒頭に「本書の日付は全て JST」と明記し、タイムゾーン差による
  未来日付の誤読を防ぐ。事実そのものは取り下げない。

## [Warning] 観点3: 判断 6 の削除順序が自己矛盾

- 判断: **対応する**
- 根拠: 指摘どおり。「`deleteProfile()` と `clearProfile()` をこの順で」と書いた直後に
  「credential → config が正」と書いており、読者が逆順に実装しうる。
- 対応内容: 設計書全体を **credential → config** に統一し、判断 6・施策 3・テスト観点の
  文言を揃えた。メソッド名の列挙順も入れ替えた。

## [Warning] 観点3: 2 ストア跨ぎ削除の部分失敗契約が未定義

- 判断: **対応する**
- 根拠: 正当。credential 破棄成功後に config 保存が失敗すると「credential 無しの profile」が残る。
  逆順にすると孤児 credential が残る (こちらは復旧不能なので順序自体は変えない)。
- 対応内容: 判断 6 に**冪等性契約**を追加。
  - credential 不在でも `clearProfile()` は no-op として成功扱い (既存実装が `existsSync` guard 済み)
  - 部分失敗後の再実行で必ず収束する (re-run 安全)
  - config 保存失敗時は「credential は破棄済み。再実行して config を掃除せよ」と案内し
    `ExitCode.CredentialStoreFailure` ではなく例外を伝播させる (oclif の既定 exit 1)
  - 施策 4 に「credential 不在プロファイルの削除が成功する (冪等性)」テストを追加

## [Warning] 観点5: 判断 7 のテストが同一プロセスの MasterKeyRegistry キャッシュで偽陽性になる

- 判断: **対応する**
- 根拠: 鋭い指摘。`MasterKeyRegistry` は `profile_hash12` キーのプロセス内キャッシュを持つため、
  削除前に読んだ B の鍵がキャッシュに残り「生存している」ように見えうる。
- 対応内容: 施策 4 の生存検証を
  「`resetGlobalMasterKeyRegistryForTests()` → 新しい `MasterKeyRegistry` /
  `FileStore` / `CredentialStore` インスタンスを組み直して読む」に変更 (別プロセス相当)。
  設計書に明記した。

## [Warning] 観点5: default_profile 削除後の利用者体験が未定義

- 判断: **対応する**
- 根拠: 正当。実装を読むと `resolveProfile()` は `default_profile` 不在時に
  builtin `"production"` へフォールバックし、未登録なら `ProfileResolutionError.notFound`
  で止まる (`src/profile/resolve.ts:158-181`)。案内なしで剥がすと詰みに近い。
- 対応内容: **判断 8** を新設して挙動を定義した。
  - `--clear-default` 無しで default を消そうとしたら `ExitCode.ProfileConflict` (10) で拒否
  - `--clear-default` 有りで削除後、**残プロファイルがちょうど 1 件ならそこへ付け替える**
    (曖昧さゼロ・詰み回避)
  - 残 0 件 / 2 件以上なら `default_profile` を未設定のまま残し、
    候補一覧と `profile:use` の案内を出す
  - 施策 4 に 3 分岐すべての回帰テストを追加

## [Warning] 観点6: skill 系と CLI 系で関心が異なる。受け入れ条件と実装順を分けよ

- 判断: **対応する**
- 根拠: 正当。1 バッチで設計する意義 (同一 devnotes / 同一 TODO) は保ちつつ、
  受け入れ条件を分ければ失敗の切り分けが効く。
- 対応内容: §受け入れ条件 を Track A (skill モデル一本化) / Track B (CLI profile:delete)
  の 2 本に分割。§実装順 で「Track A → Track B の 2 コミット」を明示した
  ([Suggestion] 観点5-3 のロールバック単位分離も同時に満たす)。

## [Warning] 観点7: TypeScript の型安全方針が設計に無い

- 判断: **対応する**
- 根拠: 正当。本件は PHP を含まないため PHPStan の縛りが効かず、型の縛りが設計から抜けていた。
- 対応内容: §型安全方針 を新設。`any` / ad-hoc cast 禁止、`ExitCode` / `ProfileWriter` /
  `CredentialStore` / 既存テストヘルパの型をそのまま使う、検出結果は
  `readonly string[]` / `ReadonlySet<string>` で扱う ([Suggestion] 観点7-2 も反映) を明記。

## [Suggestion] 観点2: Definition of Done に `pnpm typecheck` を明示せよ

- 判断: **対応する** + **設計を 1 つ追加する**
- 根拠: 対応する過程で**より重い問題**を発見した。`.github/workflows/ci.yml` は
  `php` / `frontend` の 2 job のみで、**`packages/cli` のテストも typecheck も CI で 1 度も走らない**。
  AGENTS.md §実装規約 の検証コマンド一覧にも `test:packages` は無い。
  この状態では施策 4 のテストが「置いてあるだけ」になり、AGENTS.md 禁止事項 1
  (テストなしの実装完了報告 = 不変条件は対応するテストへの登録まで含めて実装済み) を満たさない。
- 対応内容: **施策 5** を新設。
  - root `package.json` に `typecheck:packages` を追加 (`build:packages` / `test:packages` と対称)
  - `ci.yml` の既存 `frontend` job に 2 ステップ追加 (新 job は作らない)
  - AGENTS.md §実装規約 の検証コマンド行に `pnpm test:packages` を追記
  - **`ci-multi-lane-workflow` (c2c 裁定待ち) を先取りしない**ことを明記
    (job を増やさず lane も割らない。既存 job へのステップ追加のみ)

## [Suggestion] 観点1: profile:delete の位置づけを「開発運用リスク低減」と明記せよ

- 判断: **対応する**
- 根拠: 焦点がぶれるのを防ぐ妥当な整理。
- 対応内容: §期待効果 の文言を「現場価値」ではなく「開発運用リスク低減」に統一した。

## [Warning] 観点1 / 観点4: 判断 2 の逆転条件が粗い (「指摘が痩せた」の判定基準が無い)

- 判断: **対応する**
- 根拠: 正当。仮説なら検証手段が要る (思考原則「まず仮説を立てろ」)。
- 対応内容: 判断 2 の逆転条件を測定可能に書き直した。
  - 観測対象: 一本化後**最初の 5 件**の概念設計レビュー (`conceptual-review-round-1.md`)
  - 指標: 「使命整合 / スコープ妥当性 / リスク」の 3 観点で
    [Critical] または [Warning] が 1 件も出ない回が **5 件中 3 件以上**
  - 判定時期: 5 件到達時点。母数不足のうちは判断しない
  - 逸脱起票: 上記を満たしたら `docs/template-divergence.md` に理由付きで起票してから戻す

## [Suggestion] 観点4: 「レビュー品質の底上げ」は仮説であって確定効果ではない

- 判断: **対応する**
- 根拠: 正当。
- 対応内容: §期待効果 を「確定する効果」と「仮説 (要観測)」に分節した。

## [Suggestion] 観点6: スコープ外の線引きは妥当

- 判断: 対応不要 (肯定的評価)
