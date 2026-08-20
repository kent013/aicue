## ファイル別判定

### [Critical] `tests/Architecture/TemplateDivergenceFingerprintTest.php`

[F12](/workspace/.claude/worktrees/tasks/T236/tests/Architecture/TemplateDivergenceFingerprintTest.php) は債務が 0 件になると無条件で成功するため、D34 が要求する「一覧ファイルと登録の掃除漏れ」を検出しません。

さらに `fingerprintDebt()` と F0 は一覧ファイルを常に要求するため、次の正しい最終状態を作れません。

- `ADOPTION_DEBT_COUNT = 0`
- `adoption-debt.tsv` は削除済み
- D34 の対象パスまたは D34 自体も削除済み

現状は逆に、債務 0 件でもヘッダだけの一覧と D34 が残った状態を緑にします。TODO 名にある「掃除漏れ検出」と D34 の再判定条件に直接反します。

`ADOPTION_DEBT_COUNT` を状態の判定軸にし、次の両方向を固定すべきです。

- 1 件以上: 一覧ファイルと登録が必須
- 0 件: 一覧ファイルと登録が存在したら失敗し、突合には空の債務集合を渡す

この遷移には正例・負例の追加も必要です。

### [Warning] `tests/Architecture/TemplateDivergenceFingerprintTest.php`

[fingerprintLedgerRaw()/F0/F6](/workspace/.claude/worktrees/tasks/T236/tests/Architecture/TemplateDivergenceFingerprintTest.php) は `docs/template-fingerprints.json` が symlink でも受理します。

`file_get_contents()` はリンク先を読み、F6 の必須メンバにも指紋台帳自身は含まれません。安全機構の母集合を決める正本なので、債務一覧と同じく `is_file() && ! is_link()` を要求すべきです。自己ハッシュは循環するため不要ですが、regular file 条件は独立して検査できます。

### [Warning] `tests/Support/TemplateDivergence/AppFingerprintBuilder.php`

[初回生成分岐](/workspace/.claude/worktrees/tasks/T236/tests/Support/TemplateDivergence/AppFingerprintBuilder.php) は、指紋台帳を working tree から消すだけで再び seeding に入ります。自己申告どおり、未登録の新しい相違を債務へ再基準化できる経路です。

保証外として書く判断だけでは、今回の「fail-closed」という目的には弱いです。より狭く塞げます。例えば初回生成を次の場合だけ許可します。

- `previousLedger === null`
- 指紋台帳と債務一覧の両方が `git ls-files` に存在しない
- 既存債務も空

出力先が既に追跡されているのに working tree で読めない場合は「初回」ではなく「削除・検査不能」として `GenerationRefused` にすべきです。これなら本当の導入時は許し、導入後の単純な削除による再採用は拒否できます。index からの削除まで伴う検査改変は、従来どおり PR レビューの限界にできます。

### [Warning] `tests/Support/TemplateDivergence/GenerationRefused.php`

[例外の説明](/workspace/.claude/worktrees/tasks/T236/tests/Support/TemplateDivergence/GenerationRefused.php) は role 違反を `GenerationRefused` の4経路の一つとしていますが、実装は CLI 内で直接 `exit(3)` しており、この例外型を使いません。

### [Warning] `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php`

「service の拒否4経路」テストの role ケースは、`FingerprintGenerationContext` が投げる通常の `RuntimeException` を `toThrow(RuntimeException::class)` で受理しているだけです。したがって、設計上重要な次の事項を裏取りしていません。

- role 違反が実行不能ではなく拒否として分類される
- CLI が exit 3 を返す
- `GenerationRefused` と `RuntimeException` が正しく写像される

role 判定を service 側の例外へ寄せるか、書き込みに到達しない形で実 CLI の exit 3 を検査してください。少なくとも拒否4件の dataset は、期待する例外型を個別に検査する必要があります。

### [Suggestion] `tests/Support/TemplateDivergence/PathObservation.php`

docblock は「落とす7形」としていますが、実装はそれに加えて不正なハッシュ書式も拒否します。

### [Suggestion] `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php`

上記と対応し、7件 dataset の外に「ハッシュ書式違反」の独立した8件目があります。「dataset 名が件数の正本」「PathObservation = 7形」という宣言と一致しません。

設計上の7形が「状態の組合せだけ」を意味するなら、その限定を docblock に明記してください。全入力違反の件数なら8形へ訂正が必要です。

### [Suggestion] `tests/Support/TemplateDivergence/FingerprintLedger.php`

[matchesIgnoringGeneratedCommit()](/workspace/.claude/worktrees/tasks/T236/tests/Support/TemplateDivergence/FingerprintLedger.php) は app 側で呼び出されず、鮮度比較も本変更の保証範囲ではありません。思考原則2に従い、対応する単体テストと一緒に削除するのが妥当です。移植差分を小さく保つこと自体は、未使用機能を維持する理由にはなりません。

## その他のファイル

以下は提示差分上、阻害的な問題を認めませんでした。

- `composer.json` / `composer.lock`: `name` による content-hash 更新は妥当です。パッケージ更新を伴わないという自己申告とも整合します。
- `FingerprintLedger.php` の object decode: `entries: []` と `entries: {}` を区別するため妥当です。設計書内の「1行差」より、明記された負例と fail-closed 要求を優先した判断に賛成します。
- `GenerationRefused.php` の独立ファイル化: 例外分類の要求と1クラス1ファイルに沿っており妥当です。
- `scripts/update-template-fingerprints.php` の shebang 省略: 既存の strict-types gate とスクリプト規約に沿っており、D33 にも記録されています。
- `FingerprintReconciler.php` の母集合完全一致・状態整合性検査: 過剰検出側の適切な上積みで、両方向の負例もあります。
- `AtomicLedgerWriter.php` / `AtomicTextWriter.php`: 戻り値の無視を service が失敗に変換しており、失敗注入も概ね十分です。
- `RepoRelativePath.php` / `TrackedRepositoryFiles.php`: 解決不能を黙って除外せず、利用側が母集団非空を検査する分担は妥当です。
- `LedgerPins.php`: 3件の pin の集約と「増加そのものは止めない」という保証範囲の記述は適切です。
- HTTP、DTO、JsonResource、Svelte、Atomic Design は変更対象外です。
- 新規 PHP は `tests/` / `scripts/` なので、提示された `composer phpstan` 成功をもってこれらが PHPStan 解析済みとは扱っていません。

制約に従いコマンド実行・書き込みは行っていません。環境に非コマンド型のローカルファイル読取手段がなかったため、詳細設計ファイルそのものの独立読取ではなく、提示された設計要点・受入条件・全差分を根拠に判定しています。

CHANGES_REQUESTED