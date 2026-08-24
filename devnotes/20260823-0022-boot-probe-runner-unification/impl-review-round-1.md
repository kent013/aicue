仮説は「共通 runner への載せ替え自体は正しいが、新設 gate の名前解決と書き出し先検査に fail-open があり得る」です。提示差分をその観点で確認しました。コマンド実行は禁止されているため、テスト結果は申し送りを前提としています。

### `tests/Architecture/ExternalFakeBootProbeTest.php`

- [Critical] P-14 は `BootProbeRunner::isInside()` に正規化していない書き出し先を渡しています。同メソッドの契約は「両引数とも realpath 済み」ですが、キャッシュファイルなどは未作成なので、そのままの文字列です。例えば一時 root が `/tmp/boot-x` のとき、`/tmp/boot-x/../../repo/bootstrap/cache/x` は一時 root 配下かつ repository 外として検査を通りますが、実際には repository 内へ解決されます。予約パス生成に `..` が混入する退行を緑のまま見逃します。未作成パスでも正規化できる方法を使うか、少なくとも `.` / `..` セグメントを拒否する必要があります。P-11 も同じ前提に依存しています。

- [Warning] P-10d は `$base` を再帰作成した場合に `$base` 自身しか削除しません。`storage/framework` などの親もこのテストが作った環境では親ディレクトリが残ります。「新規作成した環境でも親階層へ生成物を残さない」という詳細設計の確認事項を満たしていません。

- [Suggestion] P-10b は失敗と残骸なしを確認しますが、「子を起こさなかった」ことを直接観測していません。P-10d の `$bodyCalled` と同様の番兵がないため、将来「子を起こした後に例外」という実装になっても通り得ます。

P-7/P-8 の強化、Pest matcher の修正、timeout 判定に `timedOut` を使う変更は妥当です。

### `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

- [Critical] G-6 はクラス参照を末尾名だけで照合しており、AGENTS.md の規約 (a) に反します。例えば `use Other\BootProbeRunner; BootProbeRunner::run(...)` へ差し替えても通るため、「共通の起動器を実際に呼んでいる」という検査名どおりの保証になりません。別名 import を明示的に射程外とする記述でも、この規約違反は解消されません。`use`、group use、alias を解決した完全修飾名との一致が必要です。

- [Critical] 軸B/Cは文字列に対する素の部分一致で、規約 (e) が要求する区切り文字の宣言と、接頭辞・打ち消し・接尾辞の負例を持ちません。現在の見本は「コメント」と「分割文字列」だけです。例えば `not-bootstrap/app.php.bak` や `fake-wiring-probe.php.disabled` をどう分類するかが固定されておらず、新設 gate の必須4点を満たしていません。

- [Warning] 軸Aで `T_NAME_*` を扱う拡張自体は正当ですが、末尾要素だけの一致は `Foo\PHP_BINARY` という別定数まで同一視します。「実際の `PHP_BINARY`」ではなく「末尾が同名の字句」の inventory です。これは保守的な過検出として使うことはできますが、現在の名称・説明とは意味がずれています。

軸Bの5件→8件は正当な適応です。診断文は実行経路ではないため、`inventory` を「検査定義・診断文として保持するだけ」と明記した分類も妥当です。Pest matcher 2件の変更も正しいです。

### `tests/Support/ExternalFakes/FakeWiringProbeRunner.php`

指摘なし。外側ディレクトリの realpath による境界検査、0700/0600、予約鍵の委譲、timeout の fail-closed、使い捨て鍵の分離は詳細設計と一致しています。

### `tests/Support/ExternalFakes/fake-wiring-probe.php`

指摘なし。marker による実体検査、8書き出し先の報告、鍵を平文で出さない digest 化はいずれも妥当です。Fake 用の一時環境ファイルへ切り替えてから起動するため、この経路では repository の `.env` を読ませない構造も維持されています。

### `tests/Support/Process/BootProbeResult.php`

- [Warning] PHPDoc の「強制終了なら `TIMEOUT_EXIT_CODE`」は実体と食い違います。runner は timeout 後の SIGTERM を受けて子が `exit(0)` した場合、その非負終了コードを保持します。詳細設計自身も `timedOut === true && exitCode === 0` を認めています。型は正しいものの、意味上の契約が誤っています。共有ファイルなので、本TODOで修正できない上流課題として扱う必要があります。

### `tests/Support/Process/BootProbeRunner.php`

指摘なし。共有ファイルの取り込みと Fake 経路との接続は妥当です。呼び出し側で予約鍵を渡していないこと、外側・内側双方が repository 外であることも確認できます。

### `tests/Support/StrictTypesRuntimeProbe.php`

指摘なし。アプリ起動を測らない経路を共通 runner に載せない判断と再判定条件は正当です。

### `tests/Unit/Support/Process/BootProbeRunnerTest.php`

- [Critical] S9/S10 の `BOOT_PROBE_PATH_REPORT` は repository の `bootstrap/app.php` を直接起動し、環境ファイルの置き場所を差し替えていません。runner が制限するのは `proc_open` に渡すプロセス環境だけなので、Laravel は repository のローカル `.env` を読み、そこにある外部サービス資格情報を子の設定へ取り込めます。「子プロセスへ実資格情報が渡らない」という今回のセキュリティ要件を構造的に満たしません。Fake 経路が安全でも、この自己検査の子は別経路です。共有ファイルを編集できないなら、上流の正典修正・再取り込みを含めて扱う必要があります。

- [Warning] S11 は `storage/framework/testing` が無い場合に作成しますが、作成したディレクトリを戻しません。単独実行や新しい checkout では ignored な生成物を残す可能性があります。

### 受入条件

- [Critical] 提示された証跡では、`composer test` の並列2回、`pnpm test`、`pnpm test:packages`、全体走行3系列の中央値と性能判定がまだ完了していません。AGENTS.md 禁止事項1および詳細設計の受入条件上、結果が揃うまではマージ可能とは判定できません。

全体判定: CHANGES_REQUESTED