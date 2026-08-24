Round 3 のテスト証跡とP-16は受け入れます。ただし、G-8は危険面を可視化しただけで、現在の資格情報読み込みを封じ込めてはいません。T249は保留し、先に正典側を修正すべきです。

### `tests/Architecture/ExternalFakeBootProbeTest.php`

P-16は妥当です。正常例、`..`、`.`、相対パス、紛らわしい正常名を両方向で固定しており、Round 2 のSuggestionは解消されています。

判定: 指摘なし。

### `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

- [Critical] G-8は「構造的境界」ではなく、自己申告された目録です。`boots_repository_env` の値と実際の起動挙動を結び付ける検査がありません。

例えば以下の退行はG-8を通ります。

- `fake-wiring-probe.php` から `useEnvironmentPath()` を削除するが、inventoryは`false`のままにする
- 新しい`child_entry`がrepositoryの`.env`を読むが、inventoryでは`false`と申告する
- S9/S10以外の検体文字列へrepository起動を追加するが、ファイル単位の`true`件数は変わらない

G-8が機械的に保証するのは「`true`と申告したentryが1件」という事実だけで、「repositoryの`.env`を読む子が1件だけ」というテスト名の主張ではありません。したがって「危険面が申告なしに増えない」という説明はfail-openです。

G-8は上流課題を可視化する暫定台帳としては有用ですが、セキュリティ境界または現在の問題の緩和策とは扱えません。

### `tests/Unit/Support/Process/BootProbeRunnerTest.php`

- [Critical] S9/S10が実際のDBパスワードと`CIPHERSWEET_KEY`を読み込むことが実測で確定しました。Round 2 の懸念は仮説ではなく、現実の資格情報露出です。

「外部へ出力しない」だけでは安全境界になりません。

- 子プロセス内のproviderや将来のboot処理から利用可能
- runner自身が子の外部通信を保証していない
- 親テストレーンの`phpunit.xml`による無害化が子には効かない
- 子プロセス側では親のテストガード配線も前提にできない

バイト一致制約は重要ですが、セキュリティ不変条件より優先できません。危険な正典をそのまま取り込むことは、正典追従としても受け入れられません。

結論として、T249を保留し、先に正典側でS9/S10を専用の一時環境ファイルへ固定してください。資格情報の番兵をrepositoryの`.env`へ置いても子設定へ現れない自己検査を追加し、その修正版を再びバイト一致で取り込むのが整合的です。

### その他の変更ファイル

`FakeWiringProbeRunner.php`、`fake-wiring-probe.php`、`BootProbeRunner.php`、`BootProbeResult.php`、`StrictTypesRuntimeProbe.php`について、新しい指摘はありません。`BootProbeResult`のPHPDoc不整合は上流課題として残りますが、呼び出し側は依存していません。

### 受入条件

全体テスト2回連続greenを含む検証証跡は受け入れます。性能測定も「新規分12.4秒は安定／全体比較は雑音により判定不能」という結論で妥当です。

ただし、テストがgreenであることはS9/S10の資格情報読み込みを無害化しません。今回の実測は、むしろ正典側の修正を先行させる根拠です。

全体判定: CHANGES_REQUESTED