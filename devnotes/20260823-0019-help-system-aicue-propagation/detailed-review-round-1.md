# 全体判定: CHANGES_REQUESTED

正典のスコープ選択と全体構成は妥当です。HTTP/UI/DB を追加せず、取り込み基盤・MCP 一覧生成・鮮度検査だけに限定した判断も適切です。

ただし、現状の設計どおり実装すると、I8・I10・I12・I14 が成立しない経路があります。特に、生成先ディレクトリが symlink の場合に `docs/help/` 外へ書き込める点は実装前に修正が必要です。

| 施策 | 判定 |
|---|---|
| S1 置き場と規約 | APPROVE |
| S2 取り込み基盤 | REQUEST_CHANGES |
| S3 生成器の台帳 | REQUEST_CHANGES |
| S4 MCP 走査・正規化・生成 | REQUEST_CHANGES |
| S5 唯一の入口と鮮度検査 | REQUEST_CHANGES |
| S6 検査 | REQUEST_CHANGES |

## S1: 置き場と規約 — APPROVE

`manifest.json`、生成物直下限定、手書きページ 0 件、表示面を持たないという境界は I1・I11・I13・I15・I16 と整合しています。

[Suggestion] 実装モード欄の新規ファイル数が一致していません。列挙された内容では、Service 13、Command 1、docs 3、tests 7 の合計 24 本です。テストを除けば 17 本ですが、「app 9 / console 1 / docs 3 / tests 7」はどちらにも一致しません。

## S2: 取り込み基盤 — REQUEST_CHANGES

[Critical] 書き込み先の実体検査がなく、`docs/help/` 外へ書き込めます。

`absolutePathFor()` は字句検査だけです。たとえば `_generated` が外部ディレクトリへの symlink なら、S5 の処理は `is_dir()` を通過し、`file_put_contents()` が外部へ書き込みます。これは「パスを組み立てるたびに字句と実体を検査する」という I12 と、コードの docblock に反します。

修正案:

- `absolutePathFor()` をそのまま書き込みに使わない。
- Repository に生成物専用の書き込み先解決メソッドを設ける。
- root の `realpath` を境界とし、`_generated` 自体が symlink でないことを確認する。
- 親ディレクトリの `realpath` が root 内であることを確認する。
- 既存の対象ファイルは「symlink でない通常ファイルかつ root 内」を再検査する。
- `_generated` が未作成なら、root の実体確認後に直下へ作成し、作成後に再検査する。
- 実際の書き込みも Repository に閉じ込めるのが安全です。

[Warning] `generatedArtifactPaths()` が symlink・FIFO・socket などを通常の生成物候補として扱います。

`.md` で終わる symlink は `is_dir()` を通らず、Orphan として返されます。仕様では「通常ファイルでない実体は例外」であり、I11/I12 の fail-closed にも合いません。

修正案:

- `_generated` 自体について `is_link()` を拒否する。
- 各 entry について `is_link($absolute) || !is_file($absolute)` を例外にする。
- `realpath` と root 境界も確認する。
- symlink、FIFO、ディレクトリの負例を追加する。

[Warning] `schema_version` が宣言されているのに一切検証されません。

現在は欠落、文字列 `"1"`、未知の `2` でも `sections` があれば受理します。将来の schema 変更を旧コードが誤読する fail-open になります。

修正案:

- top-level が object 相当であることを確認する。
- `schema_version` が整数 `1` と完全一致することを必須にする。
- 欠落・型違い・未知バージョンの負例を追加する。

## S3: 生成器の台帳 — REQUEST_CHANGES

[Warning] 同じ `generator` を複数 section が参照しても「完全一致」と判定されます。

`$declared[$section->generatorKey] = true` により重複が消えるため、次の manifest が通ります。

```json
[
  {"path":"_generated/a.md","generator":"mcp-tools"},
  {"path":"_generated/b.md","generator":"mcp-tools"}
]
```

`HelpGenerator::generate()` は section 引数を持たないため、1 generator が複数 artifact を生成する意味も定義されていません。これは I10 の完全一致を集合一致へ弱めています。

修正案:

- 非 null の `generatorKey` を一意にする。
- 重複を見つけた時点で `HelpManifestException` にする。
- 「同じ generator を 2 section が参照する」負例を追加する。
- そのうえで registry と manifest のキーを両方向比較する。

## S4: MCP ツールの走査・正規化・生成 — REQUEST_CHANGES

[Warning] scanner が、走査したファイルと Reflection で解決したクラスが同じ実体か確認していません。

`class_exists($class)` は Composer autoload から別のファイルをロードできます。差し替えた一時 root に `WhoamiTool.php` という壊れたファイルを置いても、既存の本物の `WhoamiTool` がロード済みなら正常と誤認する可能性があります。これは「解決できない形を落とす」の負例を弱めます。

修正案:

- `ReflectionClass::getFileName()` が `false` でないことを確認する。
- Reflection が返したファイルと、走査中の `$absolute` の `realpath` が完全一致することを要求する。
- 「同名クラスが別ファイルから既にロードされている」負例を追加する。
- 一時 fixture はファイルを明示的にロードし、Reflection の実体も一時 root に向く形にする。

[Warning] vendor metadata の形が変わっても静かに正規化される経路があります。

具体的には以下です。

- `required` が associative array でも受理する。
- union `type` が associative array でも値だけを連結する。
- `required` に空文字や `properties` に存在しない名前があっても無視する。
- `required` が存在するのに `properties` が欠落しても、パラメータ 0 件になる。

これは I14 の「形が変われば生成を止める」に反します。

修正案:

- `required` と配列型 `type` に `array_is_list()` を要求する。
- `required` の各要素を非空文字列に限定する。
- 重複した required 名を拒否する。
- `required` の全要素が `properties` に存在することを検証する。
- associative array、空 required 名、未知 required 名の負例を追加する。

[Suggestion] Population test の「4 件以上」と現行 4 クラスの個別 pin は正典の要件を超えています。

I2/I3 と走査規約が必要とするのは、走査根の生存、母集団非空、走査・登録・enum の完全一致です。現行ツールを将来正当に廃止した場合まで赤くする床値・代表クラス pin は、確定済みの最小スコープには不要です。

## S5: 唯一の入口と鮮度検査 — REQUEST_CHANGES

[Critical] 「例外も終了コード 1 に畳む」という I8 を実装できていません。

`catch (RuntimeException)` では、少なくとも以下を捕捉できません。

- Webmozart Assert の `InvalidArgumentException`
- container 解決時の例外
- Laravel のエラーハンドラによって変換された `ErrorException`
- `TypeError` などの `Error`

したがって、例外経路で 0/1 以外になる、またはテストへ例外が伝播する可能性があります。

修正案:

```php
use Throwable;

try {
    $report = $checkOnly ? $service->check() : $service->build();
} catch (Throwable $e) {
    $this->components->error($e->getMessage());

    return self::FAILURE;
}
```

併せて、Registry の container binding を意図的に誤った型へ差し替え、`Assert::isInstanceOf()` が投げる `InvalidArgumentException` も終了コード 1 になるテストを追加してください。`HelpManifestException` だけのテストではこの欠陥を検出できません。

[Critical] S2 の `absolutePathFor()` を使うため、生成処理に root 外書き込みがあります。

修正案は S2 と同じです。書き込み先の検査と書き込み自体を Repository の生成物専用 API に閉じ込め、`HelpBuildService` が未検査の絶対パスへ直接 `file_put_contents()` しない構造にしてください。

## S6: 検査 — REQUEST_CHANGES

7 本という構成と、実リポジトリを読む freshness gate／一時 root を書く振る舞いテストの分離は妥当です。ただし、上記の欠陥を検出する負例が不足しています。

[Warning] 次のテストを同じ PR に追加する必要があります。

修正案:

- `HelpRepositoryTest`
  - `schema_version` 欠落・型違い・未知バージョン
  - `_generated` 自体が symlink
  - `.md` symlink、FIFO など通常ファイルでない entry
  - symlink を介した root 外書き込みを拒否し、外部ファイルが変化しないこと
- `HelpGeneratorRegistryTest`
  - 同じ generator key を複数 section が参照した場合に失敗
- `McpToolScannerTest`
  - 走査ファイルと `ReflectionClass::getFileName()` が一致しない場合に失敗
- `McpToolReferenceGeneratorTest`
  - associative な `required` / union `type`
  -空文字・重複・未知の required 名
- `HelpBuildCommandTest`
  - `RuntimeException` 以外の `Throwable` でも終了コード 1
  - build が symlink 経由で root 外を書き換えないこと

これらは新機能の追加ではなく、既に設計が主張している I8・I10・I12・I14 と走査器共通規約の検出力を成立させるための修正です。修正後は、確定済みスコープを広げずに APPROVED へ移行できます。