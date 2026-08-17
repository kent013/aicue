# 概念設計: bug-hunt 網羅監査文書 (coverage-audit) の新設

## 背景・課題

機能台帳 lctl の feature `bughunt-coverage-audit` において、本リポジトリ (aicue) の状態は
**pending** である。裁定 2026-08-05 (AG-034 による bug-hunt 機能の 5 分割) が付けた条件は
「網羅監査文書を持つこと」で、現 HEAD でも未解消である (実読で確認: `.claude/skills/app-bug-hunt/`
直下に `coverage-audit.md` は無い / 対象外の理由と代替検証を宣言したデータも無い)。

一方で**計測基盤はすでに動いている**:

- `docker/Dockerfile` に pcov が入っており (L46-49)、`BughuntCoverageMiddleware` が実際に働いた。
- 2026-08-12 の走行 (`devnotes/20260812-100645-bug-hunt/report.md`) で
  **対象ファイル 429 / 一度も到達しないファイル 46 / uncovered 行 3752 / 参考 line_pct 59.9%** が出た。
- 報告本文はその 46 の内訳を「Filament 管理画面 17 / SEO 静的応答 4 / webhook・provider・binder 系が中心」
  と散文で書き、顧客が触る面で未到達だったのは `AcceptInvitationInAppController` と
  `SessionStatusController` の 2 本だと記録している。

問題は、**この判断が 1 回の走行報告の中にしか無い**ことである。次の走行の報告は別のディレクトリに
書かれるので、「Filament は設計上ブラウザ探索の対象にしていない。代わりに何が検査しているのか」という
**実装から導けない人間の判断**は、走行のたびに書き直され、書かれないこともあり、蓄積しない。
その結果として起きるのが「触れていない範囲を触れたつもりで放置する」ことで、これは本 feature が
無いと困る理由そのものである。

副次的な事実として、**実体の無いファイルへの参照が既に 3 か所ある**:

- `.claude/skills/app-bug-hunt/ledger/README.md` L5 (「全体像・運用は SKILL.md と `coverage-audit.md` を参照」)
- `.claude/skills/app-bug-hunt/coverage/README.md` L9 (「静的棚卸しの `coverage-audit.md` とは役割が違う」)
- `.claude/skills/app-bug-hunt/ledger/validate_findings.py` L12 (設計根拠のヘッダ)

読み手はこの参照を辿れない。文書の不在は「まだ作っていない」ではなく「既に壊れている参照」である。

### 家系が既に踏んだ失敗を繰り返さない

参照実装 (aigenba) の初版は 96 行あったが、その大半は「何画面・何操作が分母か」「どのシナリオで
消化するか」という**走行のたびに機械で出せる情報の手書きの写し**で、2026-06-12 で更新が止まり腐った。
正典 t1 が要求するのは残りの 1 節、すなわち
**「設計上ブラウザでは検査できない面はどれで、なぜか、代わりに何で検査するか」だけ**であり、
その部分は**データ化**して機械で守ることになっている (aigenba は 96 行 → 49 行へ刈り込み、
`coverage/out-of-scope.json` を唯一の正本にした)。

## 改善アイデア

**「対象外の面の宣言」をデータで持ち、監査文書はその読み方と増やし方だけを書く薄い文書にする。**

1. `.claude/skills/app-bug-hunt/coverage-audit.md` (新規・薄い)
   - 役割 / 何を書かないか (件数・一覧・route 名・シナリオ割当・率) / 対象外の正本はどこか /
     対象外を増やすときの手順 / この文書が保証しないこと、だけを書く。
   - 走行のたびに変わる数値は 1 つも書かない (腐る場所を作らない)。
2. `.claude/skills/app-bug-hunt/coverage/out-of-scope.json` (新規・データ)
   - 1 件 = 1 つの**面**。`id` / `title` / `reason` (なぜブラウザ走行では検査できないか) /
     `alternative_verification` (代わりに何が検査するか。散文) /
     `verification_refs` (代替検証の実体。リポジトリ相対パスの列。実在を機械で検査する) /
     `path_prefixes` (`app/` 配下の実在パス接頭辞)。
   - 軸は**コード到達 (どのファイルが未到達でよいか)**。走行で得た 46 の内訳に説明を与える。
   - **面の定義**: 「ブラウザ走行では検査できない理由と代替検証を共有する `app/` 配下のコード群」。
     理由か代替検証が違うなら別の面にする (粒度をこの一文で揃える)。
3. 読み取り器と自己テスト (`coverage/out_of_scope.py` / `coverage/test_out_of_scope.py`)
   - 宣言不正は終了コードで落とす (fail-closed)。標準ライブラリのみ。検証済みの型
     (`dataclass`) へ変換してから使い、生の `dict` を持ち回らない。
   - 検査する契約: 必須キーの存在と非空 / 未知キーの拒否 / `id` の一意性と書式 /
     無内容な値 (「対象外」「なし」等) の拒否 / `path_prefixes` と `verification_refs` の**実在** /
     prefix の包含関係の禁止 / prefix が `app/` 配下でありかつ幹 (`app` / `app/Http` のような
     浅い節) でないこと / **宣言済みの面と接頭辞を凍結値と完全一致で pin** (増減のどちらでも
     赤くなる = 対象外を静かに広げられない)。
   - 既存の `tests/Architecture/BughuntCoverageToolSelfTest.php` の module 一覧へ 1 本足すだけで
     `composer test` の下で実走する (新しい PHP 検査を書かない)。
   - **保証範囲を誇張しない**: 機械が見るのは宣言の形式と参照先の**実在**までである。
     代替検証がその面を本当に守っているか (意味の十分性) は人のレビューの担当であり、
     この点は監査文書にも自分から書く。

### 二重管理を作らないための軸の分離 (本設計の要)

本リポジトリには**すでに対象外宣言がある** — `.claude/skills/app-bug-hunt/inventory/annotations.toml`
の区分 `外` (20 件。30 文字以上の理由が必須で、`scripts/bug-hunt-inventory-check.sh` が
未注釈・未知語彙を exit 3 で落とす)。これは **route 単位・操作/画面の分母**の正本である。

したがって新しい宣言は**同じことを二度書かない**。役割はこう分ける:

| 軸 | 単位 | 正本 | 何を守るか |
|---|---|---|---|
| 操作到達 (分母) | route 名 | `inventory/annotations.toml` の区分 `外` + 理由 | 目録の分母から外す判断 |
| コード到達 (未到達) | `app/` 配下のパス接頭辞 | `coverage/out-of-scope.json` | 未到達ファイルに説明を与える判断 |

同じ面が両方に現れることはある (例: SEO)。しかし**キーも問いも違う** (「探索の分母に載せるか」と
「コードが未到達でよいか」)。annotations.toml へ代替検証の欄を足す案は採らない — 目録生成器・
ドリフト検査・注釈スキーマ (別 feature `bughunt-inventory-generation` / D20 の担当) へ波及し、
本タスクの範囲を超えるからである。

### 対象外にしないものを、対象外にしない

走行報告が名指しした顧客 UX 面の未到達 2 本 (`AcceptInvitationInAppController` /
`SessionStatusController`) は**宣言に入れない**。理由と代替検証が書けない未到達は対象外ではなく
**未着手の穴**であり、次の走行で埋める対象として worklist に残るのが正しい。
「対象外を増やすことは分母を縮めることである」を文書に明記する。

## 期待効果

- 使命への貢献: bug-hunt は「思考ゼロ・編集ゼロ」を支える品質側の装置である。どの面を機械で
  守り、どの面をブラウザ走行で守るかが 1 か所に定まると、**未検査の面を検査済みと誤認しない**。
- 裁定 2026-08-05 の条件が解消し、feature `bughunt-coverage-audit` が pending から進む。
- 実体の無い参照 3 か所が解消する。
- 走行報告は、**既知の設計上の対象外について理由と代替検証を書き直さず宣言を指せる**ようになる。
  走行ごとの未到達の確認そのものは無くならない (件数も新規の未到達も毎回見る)。効果は
  「宣言済みの面と、説明の無い穴とを分けて読めるようになる」ことに限る。

## 実装方針 (概要)

| # | 変更 | 種別 |
|---|---|---|
| 1 | `.claude/skills/app-bug-hunt/coverage-audit.md` 新規 (薄い監査文書) | 文書 |
| 2 | `.claude/skills/app-bug-hunt/coverage/out-of-scope.json` 新規 (面の宣言) | データ |
| 3 | `.claude/skills/app-bug-hunt/coverage/out_of_scope.py` 新規 (読み取り器・検証器・出力器) | 実装 |
| 4 | `.claude/skills/app-bug-hunt/coverage/test_out_of_scope.py` 新規 (自己テスト) | テスト |
| 5 | `tests/Architecture/BughuntCoverageToolSelfTest.php` の module 一覧へ 1 本追加 | テスト配線 |
| 6 | `coverage/README.md` と `merge_pcov.py` の冒頭にある「pcov は本環境未導入」の記述の訂正 | 文書 |
| 7 | `docs/template-divergence.md` へ登録 (対象外の正本を軸で 2 本に分ける判断) | 文書 |

詳細設計では、**守るべき不変条件と、それを落とす検査の対応表**を作る (参照実装の行数ではなく
不変条件で採否を説明する)。採らなかった検査は 1 文で理由を残す。

施策 6 は付随ではなく必要である。監査文書は「今この環境で何が測れるか」を前提に書くため、
**同じスキル配下に「pcov 未導入」と書いた記述が残っていると、新設文書と正面から矛盾する**
(実際には 2026-08-12 の走行で pcov は働いた)。

## 制約・前提

- **走行はしない**。bug-hunt の再走行は費用 (実 LLM 呼び出し) と時間がかかるうえ、本タスクが
  必要とするのは実測値ではなく**面の分類**である。分類の入力は、実装の構造 (`app/Filament` /
  `app/Http/Controllers/Seo` / `app/Http/Controllers/Webhooks` / `app/Providers` /
  `app/Http/Routing` / `app/Mcp` / `app/Console` / `app/Jobs` / `app/Mail` / `app/Notifications`) と、
  既存の走行報告 (2026-08-12) と、実在するテスト群である。
- 代替検証は**実在するものだけ**を書く (`tests/Feature/Filament` / `tests/Feature/Admin` /
  `tests/Feature/Mcp` / `tests/Feature/Api` / `tests/Feature/Console` などの実在を確認して書く)。
  書けない面は宣言しない。
- Python は標準ライブラリのみ (既存 `coverage/` の規約)。
- 新規 `.md` / `.py` は `coverage/test_naming_no_stale.py` の禁止語検査の対象に入る
  (旧 Stage 付番・旧 fail-open 文言を書かない)。
- 数値・一覧・率を監査文書に書かない (家系が腐らせた失敗の再演を避ける)。

## スコープ外

- **bug-hunt の再走行**と、走行結果の更新 (別作業)。
- `merge_pcov.py` へ宣言を読ませて未到達一覧を選別する機能 (「あったら便利」。まず宣言と文書が
  成立してから、必要になった時点で作る)。
- `inventory/annotations.toml` のスキーマ変更 (代替検証欄の追加)。別 feature の担当。
- 割合を目標にする語 (「機能カバレッジ%」等) を機械で禁じる検査。現状は散文の規約で運用しており、
  禁止語そのものを説明文に書く必要があるため素朴な語句一致では偽陽性になる。別途設計が要る。
- 家系の参照実装が持つ 400〜1300 行規模の PHP Architecture 検査の移植。本リポジトリは
  Python 自己テストを `composer test` から実走させる配線を既に持っており、同じ保証を安く得られる。
- 対象外の面を route 名接頭辞で表す形 (テンプレート / aigenba の形)。本リポジトリでは
  route 単位の判断は annotations.toml が正本のため、写すと二重管理になる。
