# 対応マトリクス: design-review Round 3

## [Critical] (S5) `PathObservation` が検査不能状態を型で表せない (`ComparisonState` は 3 値しかない)
- 判断: 対応する
- 根拠: 指摘のとおり。検査不能に `MissingCurrent` を入れると「検査不能を消滅へ畳まない」という
  不変条件そのものを破る。状態を必須にしたまま検査不能を表す方法が無かった
- 対応内容: `state` を **nullable** にし、「状態が付かない観測 = 検査不能」を型で表す形へ変更。
  許す組み合わせを**4 形**に固定 (Matched + 64 桁 hex + null / ContentMismatch + 64 桁 hex + null /
  MissingCurrent + null + null / null + null + 空でない理由)。コンストラクタで例外にする
  **7 形の負例**も列挙し、S7 の dataset へ追加した

## [Warning] (S3) `AppFingerprintBuilder` の docblock が旧い母集合定義のまま
- 判断: 対応する
- 対応内容: docblock を 2 通りの定義へ書き換えた (初回 = 新正典キー ∩ 現在の追跡パス /
  2 回目以降 = 新正典キー ∩ (現在の追跡パス ∪ 旧アプリ台帳キー))。和集合を取る理由
  (ローカル削除で母集合から外せないようにする) も書いた

## [Warning] (S3/S4) `FingerprintGenerationService.php` が施策一覧と変更ファイルに無い
- 判断: 対応する / 対応内容: 施策一覧の S4 と変更箇所へ追加。S5 の一覧にも `PathObservation` を追加

## [Warning] (S4) exit 表に「件数 pin が合わなくなるので必ず赤」の旧記述が残っている
- 判断: 対応する
- 対応内容: 「**F5 (出自 pin) / F9・F10 (突合) / F14 (世代識別子) のいずれかで必ず不合格になる。
  とくに件数が変わらない部分更新は F14 が検出する**」へ訂正 (F14 を置いた理由と整合させた)

## [Warning] (S4) service への pin の注入方法が未定義で、合成入力の正常系テストが書けない
- 判断: 対応する
- 対応内容: service は **`LedgerPins` を直接読まない**。readonly の context DTO
  (`expectedTemplateLedgerSha256` / `expectedSourceCommit` / `adoptNewTemplateLedger` /
  `previousLedger` / `fingerprintOutputPath` / `debtOutputPath`) を受け取り、
  `LedgerPins` を読むのは CLI だけにした

## [Warning] (S6) F14 が母集合外の債務パスで未定義キー参照になる
- 判断: 対応する
- 対応内容: F14 は**母集合内であることを確認できた債務だけ** hash 比較へ進める。
  母集合外は F10 の `debtPathsOutsidePopulation` が担当し、未定義キー例外で途中終了させない
  (全違反を一度に出す方針を保つ)

## [Warning] (S6) gate の docblock / F11 に C2 時点の「176 件」が固定で書かれている
- 判断: 対応する
- 対応内容: gate の本文と docblock から具体件数を落とし、「件数の正本は
  `LedgerPins::ADOPTION_DEBT_COUNT`。フェーズごとの数値は設計書の表だけを正本にする」と明記。
  C3 で gate を再編集せずに済む形にした

## [Warning] (S7) `PathObservation` の不変条件テストが計画に無い
- 判断: 対応する / 対応内容: S7 の表へ「不正な組み合わせ 7 形すべてで例外 / 許容する 4 形は構築できる」を追加

## [Warning] (S10) AGENTS.md 案の母集合説明が旧定義のまま
- 判断: 対応する
- 対応内容: 「母集合は正典の指紋台帳のキーを起点に生成し、**採用後にローカルで消しても
  既存のキーは母集合から外れない** (正典側から消えたときだけ外れる)。生成規則の正本は
  `AppFingerprintBuilder` の docblock」へ書き換えた (詳細式は複製しない)

## [Warning] (S11) S11 の変更ファイル欄がスキル 2 本だけ / テスト計画が「文書の変更のみ」で誤り
- 判断: 対応する
- 対応内容: 施策一覧の S11 の変更ファイルへ登録簿 / `LedgerPins` / 債務一覧 / S7 の期待値を追加。
  テスト計画を**C3 の受入条件 9 項目**へ書き換えた (D35 が 2 パスを登録 / 債務 2 行削除 /
  pin 174・33・281 / D34 本文 174 / `mutatedDebtPaths` と `doubleDeclaredPaths` が空 /
  S7 の期待値更新 / 追跡下の `176` の棚卸し / 検証コマンド 10 本を C2 と分けて記録 /
  整合式 `281 = 78 + 29 + 174`)

## [Warning] (S11) C3 後も「176 件」の固定記述が残る可能性
- 判断: 対応する
- 対応内容: C3 の受入条件に「追跡下の `176` を棚卸しし、フェーズ説明として意図的に残すもの以外を
  174 へ直す」を入れた。あわせて設計書側の `176` も、フェーズ説明として意味を持つ箇所
  (実測データ表 / 件数表 / D34 の業務要件起因 = C2 176・C3 174 の併記) だけに整理した

## [Suggestion] (S9) 「上記 9 パス」が D33 単独の件数に読める
- 判断: 対応する / 対応内容: 「**D33 の 7 パスと D34 の 2 パスを合わせた計 9 パス**」へ書き換え
