仮説は確認されました。通常系・静的検査・DTO 構造は概ね設計どおりですが、I12 と I14 を破る経路が残っています。

## 主要な指摘

### [Critical] `app/Services/Help/HelpRepository.php`

symlink と TOCTOU に対する「置き場外へ読み書きしない」という保証が成立していません。

- `$root` 自体が symlink でも `resolveRealDirectory()` はそのまま辿ります。例えば `docs/help` がリポジトリ外への symlink なら、`writeGenerated()` は外側へ `_generated` を作成して書き込みます。
- 書き込み先についても、`is_link()` / `is_file()` と `file_put_contents()` は別操作です。検査後にファイルや親ディレクトリを symlink へ差し替えられると、外部ファイルを変更した後で `assertWrittenFileIsRegular()` が例外を投げます。事後検査では変更を取り消せません。
- `readResolved()` と `readManifest()` も、実体検査後にパスを再度開くため同様の差し替え競合があります。

既存テストが確認しているのは「操作開始前から存在する symlink」だけです。I12 とセキュリティ要件を満たすには、少なくとも root symlink を拒否し、読み書きを descriptor-relative な no-follow 操作へまとめる必要があります。そこまでを脅威モデルに含めない判断なら、「TOCTOU 下でも置き場外へ出ない」という保証を設計・docblock から明示的に狭める必要があります。

`clearstatcache()` の追加と private メソッドへの切り出し自体は妥当ですが、この問題の解決にはなっていません。

### [Critical] `app/Services/Help/McpToolMetadata.php`

I14 の「vendor のメタデータ形状が変われば生成を止める」を満たしていません。

`properties` と `required` が両方無ければ、ほかの形を確認せずパラメータ 0 件として受理します。例えば以下が静かに通ります。

```php
['type' => 'array']
['type' => 'object', 'fields' => ['project_id' => [...]]]
```

vendor が `properties` を別キーへ変更した場合、全パラメータが消えた生成物を正常としてコミットできてしまいます。現在の serializer 契約として、少なくとも top-level `type === 'object'` と許容する top-level キー集合を固定し、変更は例外にする必要があります。

### [Warning] `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php`

上記の top-level schema drift の負例がありません。

また、想定外形状の dataset は全ケースでほぼ同じ `vendor` / `McpToolMetadata` 検査を使っています。設計が要求した「負例の種類ごとに不正箇所を確認する」を満たしておらず、例えば `properties` の負例が別の共通例外へ誤って流れても緑になり得ます。各ケースで `type`、`properties`、`required`、対象キーなど、その分岐固有の文言を要求してください。

### [Warning] `app/Services/Help/McpToolScanner.php`

走査根自体の symlink を拒否していません。`is_dir()`、`scandir()`、Composer autoload、Reflection の `realpath()` はすべて symlink を辿るため、`app/Mcp/Tools` が外部への symlink でも、Reflection の実体一致検査を通過し得ます。

固定された first-party コードの走査としては、root の `is_link()` 拒否と canonical path の一致検査を追加するのが適切です。

### [Warning] `app/Services/Help/Generators/McpToolReferenceGenerator.php`

パラメータ名を Markdown 表へ未加工で埋め込んでいます。

```php
'| `%s` | ...'
```

schema のプロパティ名に `|`、改行、backtick が含まれると生成表が壊れます。description と type は無害化されているため、名前についても表示用正規化または許容形式の明示が必要です。

## 意図的な差分の判定

1. `McpToolMetadata::fromSchema()` の public 化: 妥当です。`fromTool()` が同じ経路へ委譲しており、負例の検査境界として合理的です。
2. `assertWrittenFileIsRegular()` と `clearstatcache()`: 妥当です。ただし上述のとおり TOCTOU 防止にはなりません。
3. `generatorFor()`: 妥当です。暗黙の未定義添字を排除し、fail-closed になっています。
4. `HelpArtifactState` の enum exemption: 妥当です。CLI 内部語彙で TypeScript 同期対象ではなく、理由と件数 pin も整合しています。

## ファイルごとの判定

- `app/Console/Commands/Help/HelpBuildCommand.php`: OK。I6〜I9 の構造と例外の終了コード変換は妥当。
- `app/Providers/AppServiceProvider.php`: OK。固定 root の singleton 配線は適切。
- `app/Services/Help/Generators/HelpGenerator.php`: OK。
- `app/Services/Help/Generators/McpToolReferenceGenerator.php`: [Warning] パラメータ名の Markdown 無害化不足。
- `app/Services/Help/HelpArtifactObservation.php`: OK。
- `app/Services/Help/HelpArtifactState.php`: OK。4状態に閉じている。
- `app/Services/Help/HelpBuildReport.php`: OK。DTO 戻り値になっている。
- `app/Services/Help/HelpBuildService.php`: OK。ただし Repository の安全性問題を継承する。
- `app/Services/Help/HelpGeneratorRegistry.php`: OK。manifest との両方向一致と不在時例外は妥当。
- `app/Services/Help/HelpManifestException.php`: OK。
- `app/Services/Help/HelpRepository.php`: [Critical] root symlink と検査・I/O 間の TOCTOU。
- `app/Services/Help/HelpSection.php`: OK。
- `app/Services/Help/McpToolMetadata.php`: [Critical] top-level schema drift がパラメータ 0 件へ fail-open。
- `app/Services/Help/McpToolParameter.php`: OK。
- `app/Services/Help/McpToolScanner.php`: [Warning] 走査根 symlink を受理する。
- `docs/help-system.md`: [Warning] 想定外 schema と symlink/TOCTOU に関する保証が実装より強い。
- `docs/help/_generated/mcp-tools.md`: OK。現行実装との内容整合に問題なし。
- `docs/help/manifest.json`: OK。
- `tests/Architecture/HelpGeneratorRegistryTest.php`: OK。正負例・母集団非空を確認している。
- `tests/Architecture/McpToolReferencePopulationTest.php`: OK。3集合の両方向差分と空集合を確認している。
- `tests/Feature/Help/HelpBuildCommandTest.php`: OK。通常のコマンド動作と静止状態の symlink 拒否は十分。
- `tests/Feature/Help/HelpBuildFreshnessTest.php`: OK。実リポジトリを読み取りだけで検査している。
- `tests/Feature/Help/HelpRepositoryTest.php`: [Warning] root symlink と差し替え競合の負例が無い。
- `tests/Support/Help/HelpTestTree.php`: OK。テスト支援として過剰実装ではない。
- `tests/Unit/Architecture/McpToolScannerTest.php`: [Warning] 走査根そのものが symlink の負例が無い。
- `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php`: [Warning] top-level schema drift と分岐固有メッセージの裏取り不足。
- `tests/js/architecture/enum-ts-sync-discovery.test.ts`: OK。exemption と件数 pin の更新は必要かつ妥当。

PHPStan の型緩和、baseline、`@phpstan-ignore`、配列 Service 戻り値、`response()->json()`、HTTP/UI/Svelte/CSS の追加、不要なモデルや MCP help tool は見当たりません。スコープ判断と乖離台帳を変更しない判断も妥当です。

CHANGES_REQUESTED