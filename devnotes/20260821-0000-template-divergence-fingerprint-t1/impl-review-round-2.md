## ファイル別判定

### [Critical] `tests/Architecture/TemplateDivergenceFingerprintTest.php`

F12 の方向性は正しいですが、「D34 ごと削除」をまだ機械的に保証できません。

現在の `isRegistered` は、全登録の対象パスに `adoption-debt.tsv` が含まれるかだけを見ています。そのため、債務が 0 件になった際に次の変更をすると緑になります。

- 一覧ファイルを削除
- D34 の対象パスから `adoption-debt.tsv` だけ削除
- D34 自体は残す

D34 はもう一つ `AdoptionDebtInventory.php` を対象に持つので、この状態は形式検査にも抵触しません。しかし、D34 の再判定条件とエラーメッセージは「D34 ごと削除」を要求しています。

D34 の存在自体を番号または安定した識別子で判定する必要があります。望ましい等式は以下です。

- pin > 0: 一覧ファイルが存在し、D34 が存在し、D34 が一覧パスを対象に含む
- pin = 0: 一覧ファイルが存在せず、D34 自体が存在しない

D34 削除後も `AdoptionDebtInventory` を残す設計なら、同クラスが引き続きテンプレートと相違する場合の登録先も整理する必要があります。現在 `retirementViolations()` を同クラスへ追加したことで、D34 を削除した後のクラス自身の扱いが曖昧です。

### [Warning] `tests/Architecture/TemplateDivergenceFingerprintTest.php`

`fingerprintDebtInventoryExists()` は「regular file として存在するか」と「パスが何らかの形で残っているか」を混同しています。

pin が 0 のとき `adoption-debt.tsv` を symlink にすると、この関数は `false` を返し、F12 は「一覧なし」と判定して成功します。掃除判定では symlink も残置です。

次の二つを分けてください。

- `inventoryPathExists`: `file_exists($path) || is_link($path)`
- `inventoryIsRegularFile`: `is_file($path) && ! is_link($path)`

引退前は後者を要求し、引退後は前者が `false` であることを要求するのが適切です。

### [Warning] `tests/Architecture/TemplateDivergenceFingerprintTest.php` / `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php`

指紋台帳の symlink 拒否には負例が追加されていません。

現物が regular file であることを確認する F0 は正例であり、「symlink を置いたら検出できる」という検出力の裏取りではありません。走査条件を変更した今回の変更は、共通規約 (c) の対象です。

実ファイルを差し替えずに検査できるよう、例えば「パスを受け取り regular file か検査して読む」処理を support class へ切り出し、一時 symlink の負例と通常ファイルの正例を追加してください。

### [Warning] `tests/Support/TemplateDivergence/FingerprintGenerationService.php` / `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php`

seeding ガードの条件は過剰ではなく、設計として妥当です。ただし、新設した3 blocker のうち「債務一覧だけが追跡済み」の分岐が負例で裏取りされていません。

現在の負例は以下です。

- 指紋台帳が追跡済み
- 既存債務が非空

不足しているのは次です。

- `previousLedger === null`
- 指紋台帳は未追跡
- `adoption-debt.tsv` だけが追跡済み
- `existingDebt === []`

これは特に、ヘッダだけの旧一覧や引退遷移付近で現実に起こり得る形です。dataset に独立ケースとして追加してください。

### [Warning] `scripts/update-template-fingerprints.php` / `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php`

`GenerationRefused` へ寄せて exit code の写像を一か所にした実装は適切です。しかし、CLI の role 分岐を変更したのに、その分岐自体の負例がありません。

`FingerprintGenerationContext` の形5は別の分岐です。CLI が読み込んだ既存台帳を検査して `GenerationRefused` を投げるコードの検出力は裏取りしていません。コメントで保証外にするだけでは、今回新設・変更した判定分岐に対する共通規約 (c) を満たしません。

本物の台帳を書き換える必要はありません。role 判定を例えば次の純粋な処理へ切り出せます。

```php
assertAppLedgerRole(FingerprintLedger $ledger): void
```

CLI とテストから同じ処理を呼び、`Template` なら `GenerationRefused`、`App` なら正常という両方向を固定してください。実プロセスでは sha256 経路一つで `GenerationRefused → exit 3` の共通写像を確認できているため、role ごとのプロセステストまでは不要です。

### [Suggestion] `tests/Support/TemplateDivergence/AdoptionDebtInventory.php`

`retirementViolations()` は負の pin で `RuntimeException` を投げますが、docblock に `@throws RuntimeException` がありません。fail-closed の重要な入力条件なので明記すると契約が明確になります。

### 指摘なし

- `docs/template-divergence.md`: 鮮度比較削除、regular-file 条件、seeding 条件の追加は妥当です。ただしD34引退後の `AdoptionDebtInventory.php` の扱いは上記Criticalと合わせて再整理が必要です。
- `FingerprintLedger.php`: 未使用の鮮度比較削除と「差は3点」の更新は適切です。
- `PathObservation.php` / 対応テスト: 「組合せ7形」と「値書式の別軸」の区別で曖昧さは解消されています。
- exit 3 の実プロセステスト: sha256 拒否を使って共通の例外写像と生成物不変を確認する範囲は適切です。
- 修正後検証結果について、`tests/` と `scripts/` が PHPStan の解析対象外であるという区別も引き続き必要ですが、報告自体に誇張はありません。

全体としてRound 1の主要な設計方向は正しく修正されていますが、掃除判定がD34の存在ではなく対象パスだけを見ている点は、TODOの中心的な受入条件に残る穴です。

CHANGES_REQUESTED