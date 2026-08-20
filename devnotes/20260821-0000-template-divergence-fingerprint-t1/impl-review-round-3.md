## ファイル別判定

### [Critical] `docs/template-divergence.md` / `AdoptionDebtInventory.php` / F12

D34 の機械的な同定は修正されていますが、D34 のライフサイクルが設計上まだ矛盾しています。

D34 は次の二つを対象にしています。

- `adoption-debt.tsv`
- `AdoptionDebtInventory.php`

pin が 0 になっても `AdoptionDebtInventory.php` と採用時債務の生成・判定機構は残ります。それにもかかわらず、「母集合外なので gate が沈黙する」ことを理由にD34全体を削除してよい、としています。

しかし、機械検査が沈黙することと、登録簿上の説明が不要になることは別です。D34 はもともとクラスを「本アプリ固有の追加」として明示的に登録しています。同じクラスと機構が残るのに、債務件数だけを理由に説明を消すのは、登録簿の意味と一致しません。

次のどれかへ設計を確定する必要があります。

- 債務0件時に採用時債務のクラス・生成ロジック・テストも含めて機構全体を削除し、D34も削除する
- 一覧の一時的な存在と恒久的な採用機構を別登録へ分け、D34だけを引退させる
- D34を残し、引退時は一覧パスだけを対象から外すよう再判定条件を修正する

現在の「機構は残すが、gateが見ないので登録だけ消す」は採用できません。

### [Warning] `FingerprintGenerationService.php`

引退後の安定状態が生成器に反映されていません。

service は `$built['debt'] === []` でも `AtomicTextWriter` でヘッダだけの `adoption-debt.tsv` を生成します。そのため、一度 pin 0・一覧削除・D34削除へ移行しても、将来テンプレート台帳を更新すると一覧が再作成され、F12が赤になります。

少なくとも次の経路をテストしてください。

1. 債務0件、一覧なしの引退済み状態
2. 新しい正典台帳を取り込む
3. 新規債務は発生しない
4. 一覧ファイルが再作成されず、引退状態を維持する

新しい債務が発生した場合に機構を再開するのか拒否するのかも、D34のライフサイクルと合わせて決める必要があります。

### [Warning] `AdoptionDebtInventory::retirementViolations()`

5つの真偽値の矛盾した組合せを受理します。

例えば次の入力は実在状態として不可能ですが、pin 0なら合格します。

```php
inventoryPathExists: false
inventoryIsRegularFile: true
```

共通規約 (b) に沿うなら、`inventoryIsRegularFile && ! inventoryPathExists` は入力矛盾として例外または違反にすべきです。状態を二つの bool ではなく enum/value object にすると、矛盾した組合せ自体を作れません。

### [Warning] `TemplateDivergenceFingerprintRulesTest.php`

等式の各項を独立して発火させる負例が二つ不足しています。

- pin > 0、D34はないが別の登録が一覧パスを対象にしている  
  `isRegisteredAsTargetPath=true / divergenceEntryExists=false`
- pin = 0、D34はないが別の登録が一覧パスを対象にしている  
  `isRegisteredAsTargetPath=true / divergenceEntryExists=false`

現在の「登録そのものが無い」は両方を `false` にしているため、`divergenceEntryExists` 側の判定が壊れても対象パス側の違反だけでテストが通ります。集約結果が非空かだけでなく、各条件が単独で検出力を持つことを固定してください。

### [Warning] `scripts/update-template-fingerprints.php`

指紋台帳の通常読み取りはgateで `RegularFileReader` に集約されましたが、CLIの既存台帳読み取りはまだ以下のままです。

```php
if (is_file($fingerprintPath)) {
    $existingRaw = file_get_contents($fingerprintPath);
}
```

これはvalid symlinkを追跡します。D33で「指紋台帳自身はregular file必須」と宣言した以上、生成器も同じ読み取り口を使うべきです。`RegularFileReader::read()` へ寄せれば判定の正本も一つになります。

### [Warning] `RegularFileReader.php`

今回の中心的な新規クラスですが、提示された累積差分にファイル本体が含まれていません。テストから期待される挙動は妥当ですが、次を確認できません。

- `declare(strict_types=1)`
- `is_link()` と `is_file()` の判定順
- `file_get_contents()` 失敗時の処理
- 戻り値型と例外契約
- 保証範囲のdocblock

最終承認にはこのファイル本体のレビューが必要です。

### 指摘なし

- D34を番号で同定する `ADOPTION_DEBT_DIVERGENCE_ID` は、対象パスだけを見る穴を適切に塞いでいます。
- 残置とregular fileを分離した判定は正しいです。壊れたsymlinkも考慮されています。
- seedingの3 blockerは独立した負例になりました。
- role判定の純関数化と両方向テストは十分です。role固有の実プロセステストは不要です。
- `PathObservation` の件数表現と `@throws` の修正も適切です。

Round 2の直接的な判定漏れは解消されていますが、D34を削除した後も採用時債務機構を残すというライフサイクルと、生成器が一覧を再作成する挙動を先に整合させる必要があります。

CHANGES_REQUESTED