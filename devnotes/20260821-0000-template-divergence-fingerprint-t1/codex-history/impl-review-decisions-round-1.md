# 対応マトリクス: impl-review Round 1

## [Critical] F12 が債務 0 件のとき無条件で成功し、掃除漏れを検出しない

- 判断: **対応する**
- 根拠: 指摘のとおりである。TODO の名前そのものが「掃除漏れ検出」であり、
  D34 の再判定の条件は「一覧が 0 件になったとき (一覧ファイルと本登録を同じ変更で消す)」と
  書いてある。現状の F12 は 0 件で `expect(true)->toBeTrue()` に落ちるので、
  **ヘッダだけの一覧と D34 が残った状態を緑にする** = 守りたい遷移をちょうど守れていない。
  さらに `fingerprintDebt()` と F0 が一覧ファイルを常に要求するため、
  正しい最終状態 (pin 0 / 一覧ファイル削除 / 登録削除) を**表現できない**。
- 対応内容:
  1. 判定を純関数 `AdoptionDebtInventory::retirementViolations(int $pinnedCount,
     bool $inventoryExists, bool $isRegistered): list<string>` へ切り出した。
     pin > 0 なら「一覧ファイルと登録が必須」、pin === 0 なら
     「一覧ファイルと登録が存在したら違反」を両方向で返す (負の pin も違反)。
  2. gate 側は `LedgerPins::ADOPTION_DEBT_COUNT` を状態の軸にして、
     0 件なら一覧を読まず**空の債務集合**を突合へ渡す (F0 も 0 件では一覧を要求しない)。
     F14 も 0 件では世代識別子の突き合わせへ進まない (ヘッダが存在しないため)。
  3. 両方向の負例と正例を
     `TemplateDivergenceFingerprintRulesTest` へ足した (4 通りの組み合わせ全数)。

## [Warning] 指紋台帳が symlink でも受理される

- 判断: **対応する**
- 根拠: 母集合を決める正本なので、債務一覧と同じ強さで守るべきである。
  `file_get_contents()` はリンク先を読むので、リンクを差し替えれば母集合ごと入れ替えられる。
- 対応内容: `fingerprintLedgerRaw()` を `is_file() && ! is_link()` 必須にし、
  F0 で指紋台帳と (0 件でなければ) 債務一覧が regular file であることを明示的に検査した。
  自己ハッシュは循環するので取らない (指摘どおり regular file 条件だけを独立に見る)。

## [Warning] 初回生成 (seeding) の抜け道を保証外の記述だけで済ませている

- 判断: **対応する**
- 根拠: 「fail-closed を持ち込む」という本 TODO の目的に対して、
  塞げるのに保証外へ逃がすのは弱い。指摘された条件は実装可能である。
- 対応内容: `FingerprintGenerationService` に seeding のガードを足した。
  `previousLedger === null` の生成を許すのは、**指紋台帳と債務一覧のどちらも
  `git ls-files` に無く、既存債務も空**のときだけである。
  出力先が追跡済みなのに working tree で読めない場合は「初回」ではなく
  **削除・検査不能**として `GenerationRefused` にする。
  これで本当の導入時は通り、導入後の単純な削除による再採用は拒否される。
  index からの削除まで伴う改変は従来どおり PR レビューの限界として docblock に残した。

## [Warning] `GenerationRefused` の docblock が実装と食い違う (role 違反は CLI が直接 exit 3)

- 判断: **対応する**
- 根拠: docblock が 4 経路と書いているのに、そのうち 1 つが例外型を使っていない。
  型の説明が実装を説明していない状態である。
- 対応内容: CLI の role ガードを `throw new GenerationRefused(...)` へ変え、
  例外から終了コードへの写像を**1 か所の catch** に集約した
  (拒否 = 3 / 実行不能 = 1)。docblock の 4 経路の記述が実装と一致するようになった。

## [Warning] 拒否 4 経路のテストが例外型を区別していない / exit 3 の写像を裏取りしていない

- 判断: **対応する**
- 根拠: `toThrow(RuntimeException::class)` は `GenerationRefused` も素の
  `RuntimeException` も通すので、「拒否として分類されること」を固定できていない。
- 対応内容:
  1. service 側の拒否 3 経路 (sha256 不一致 / 債務への新規追加 / 母集合の縮小) と
     新設の seeding ガードは **`GenerationRefused::class` を名指しで**検査するようにした。
  2. `role` ケースは `FingerprintGenerationContext` の負例 (形 5) が既に担当なので
     拒否 4 経路の dataset から外し、そちらへ寄せた。
  3. **実プロセスで exit 3 の写像を裏取りする**テストを新設した。
     入力の sha256 が pin と違う正当な台帳を `--adopt-new-template-ledger` 無しで渡すと
     `GenerationRefused` になり、これは**書き込みに一切到達しない**ので
     本物の生成物に触れずに終了コード 3 を確認できる (生成物の byte 不変も併せて見る)。
  4. **裏取りできない範囲は明記した**: CLI の role ガード自身の exit 3 は、
     本物の指紋台帳を `role: template` へ書き換えないと到達できないため
     プロセスでは検査していない (型は docblock と context の形 5 が固定する)。

## [Suggestion] `PathObservation` の docblock が「7 形」と言いつつハッシュ書式も落とす

- 判断: **対応する**
- 根拠: 件数の主張と実装が食い違っている。設計書の 7 形は
  「状態・ハッシュ・理由の**組み合わせ**」の数であり、ハッシュの**書式**は別の軸である。
- 対応内容: docblock を「組み合わせの 7 形」と「加えて値の書式 (64 桁小文字 hex)」に
  書き分け、テスト側の宣言も同じ書き分けにした (dataset は組み合わせ 7 件のまま、
  書式違反は独立したテストであることを明記)。

## [Suggestion] `FingerprintLedger::matchesIgnoringGeneratedCommit()` は未使用なので消す

- 判断: **対応する**
- 根拠: 当初は「正典との差を 1 点に留める」という詳細設計 S2 の方針に従って残したが、
  役割 (鮮度比較) は role: template 側の検査のためのもので、受け手側には**呼び出し元が無い**。
  「移植差分を小さく保つことは未使用機能を維持する理由にならない」という指摘に同意する
  (思考原則 2)。差は D33 で既に登録済みなので、行を 1 つ足すだけで済む。
- 対応内容: メソッドと対応する単体テストを削除し、D33 の観点表へ
  「鮮度比較」の行を足した。
