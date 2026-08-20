# 対応マトリクス: impl-review Round 2

## [Critical] F12 が D34 の存在ではなく対象パスだけを見ている

- 判断: **対応する**
- 根拠: 指摘のとおりの抜けがある。債務が 0 件になったときに
  「一覧ファイルを消す + D34 の対象パスから `adoption-debt.tsv` だけ削る + D34 は残す」
  という変更をすると、D34 はもう 1 本 (`AdoptionDebtInventory.php`) を対象に持つので
  形式検査 (TD3 = 対象パスが 1 件以上・実在) にも抵触せず、**緑になる**。
  D34 の再判定の条件と F12 の失敗メッセージはどちらも「D34 ごと削除」を要求しているので、
  検査が要求と一致していない。
- 対応内容: 判定を望ましい等式そのままへ書き換えた。
  - pin > 0: 一覧ファイルが regular file として実在し、**D34 が存在し**、
    D34 が一覧パスを対象に含む
  - pin = 0: 一覧のパスがどんな形でも残っておらず、**D34 自体が存在しない**
  D34 の同定は**番号**で行う。番号は `LedgerPins::ADOPTION_DEBT_DIVERGENCE_ID` に
  他の pin と同じ場所へ置き、gate は解析済みの登録一覧から `id === 34` を探す。
- 併せて指摘された「D34 削除後の `AdoptionDebtInventory.php` の扱いが曖昧」について:
  **同クラスは正典の指紋台帳のキーではない** (実測で確認済み。母集合 281 件の外)。
  したがって D34 を消しても**未登録の逸脱は 1 件も残らない**し、突合 gate は同クラスに
  沈黙する。曖昧なのは記述だったので、D34 の本文と一覧クラスの docblock に
  「一覧クラスは母集合の外であり、D34 を消しても登録先を探す必要は無い」ことを明記した。

## [Warning] `fingerprintDebtInventoryExists()` が「残置」と「regular file」を混同している

- 判断: **対応する**
- 根拠: 掃除の判定では symlink も残置である。現状は pin = 0 のときに
  `adoption-debt.tsv` を symlink にすると `false` を返し「一覧なし」と判定して緑になる。
- 対応内容: 指摘どおり 2 つに分けた。
  - `inventoryPathExists` = `file_exists($path) || is_link($path)`
    (壊れた symlink も残置として数えるため `is_link` を or で足す)
  - `inventoryIsRegularFile` = `is_file($path) && ! is_link($path)`
  引退前は後者を要求し、引退後は前者が false であることを要求する。

## [Warning] 指紋台帳の symlink 拒否に負例が無い

- 判断: **対応する**
- 根拠: 走査条件を変えたのだから共通規約 (c) の対象である。
  「現物が regular file である」ことの確認は正例にすぎず、検出力の裏取りではない。
- 対応内容: 「パスを受け取り regular file か検査して読む」処理を
  `Tests\Support\TemplateDivergence\RegularFileReader` へ切り出し、
  一時 symlink / ディレクトリ / 不在 の負例と通常ファイルの正例を足した。
  gate の `fingerprintLedgerRaw()` と `AdoptionDebtInventory::read()` の
  どちらも同じ読み取り口を通るようにしたので、判定は 1 か所になった。

## [Warning] seeding ガードの blocker「債務一覧だけが追跡済み」に負例が無い

- 判断: **対応する**
- 根拠: 3 つの blocker のうち 1 つが裏取りされていないのは (c) の不足である。
  指摘どおり「ヘッダだけの旧一覧が残っている」状態は引退遷移の近くで現実に起こり得る。
- 対応内容: 負例を dataset の独立ケースへ足した
  (`previousLedger === null` / 指紋台帳は未追跡 / `adoption-debt.tsv` だけ追跡済み /
  既存債務は空)。あわせて既存の 2 ケース (指紋台帳が追跡済み / 既存債務が非空) も
  独立した dataset 名で並べ、**3 blocker が 1 件ずつ裏取りされる**形にした。

## [Warning] CLI の role 分岐そのものに負例が無い

- 判断: **対応する**
- 根拠: `FingerprintGenerationContext` の形 5 は別の分岐であり、CLI が読んだ既存台帳を
  検査する分岐の検出力は裏取りできていない。コメントで保証外にするのは、
  **新設・変更した判定分岐**に対する (c) の代わりにはならない。
- 対応内容: 指摘の案をそのまま採った。判定を
  `FingerprintGenerationService::assertAppLedgerRole(FingerprintLedger $ledger): void`
  へ切り出し、CLI とテストが同じ処理を呼ぶ。`Template` なら `GenerationRefused`、
  `App` なら正常終了の両方向を固定した。
  実プロセスの写像 (`GenerationRefused` → exit 3) は sha256 経路 1 本で確認済みなので、
  指摘のとおり role ごとのプロセステストは足していない。

## [Suggestion] `retirementViolations()` の `@throws` が docblock に無い

- 判断: **対応する**
- 根拠: fail-closed の入力条件なので契約として書くべきである。
- 対応内容: `@throws RuntimeException` を足し、負の pin を落とすことを本文にも書いた。
