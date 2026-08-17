提示差分を静的にレビューした結果、主要な設計施策は実装されていますが、対象外宣言の検証に迂回路が残っています。Critical はありませんが、deny-by-default 契約に関わる Warning があるため修正が必要です。

### `.claude/skills/app-bug-hunt/coverage-audit.md`

判定: 概ね一致

静的判断と走行時集計の責務分離、二つの対象外軸、保証しない範囲は明確です。集計器が宣言を読まず、人が突合するという限界も正直に記述されています。

ただし「自己言及でない参照」という保証は、後述する `out_of_scope.py` の実装では完全には成立していません。

### `.claude/skills/app-bug-hunt/coverage/README.md`

判定: 問題なし

pcov の「拡張が存在すること」と「収集が有効であること」を分離し、リポジトリ外の本番構成を断定していません。二重 guard の必要性や no-op 条件も実装範囲を越えていません。

### `coverage/correlate.py` / `coverage/merge_pcov.py`

判定: 問題なし

古い「pcov 未導入」という前提だけを除去し、純ロジックであることや入力が生成されない条件は維持されています。

### `coverage/out-of-scope.json`

判定: Warning

[Warning] `queued-job` は `app/Jobs` 全体を対象外にしていますが、代替検証の説明は「待ち時間の扱いと重複実行の目録」に限定されています。これだけでは各 Job のドメイン副作用や失敗時挙動を代替検証しているとは読めません。

`tests/Feature/Queue` が全 Job の挙動を実際に検査しているなら、その契約を説明と参照に明記してください。そうでなければ、対象パスを狭めるか、Job ごとの挙動を担うテスト参照を追加する必要があります。

ほかのエントリは、提示された理由と参照先の対応に明白な矛盾はありません。

### `coverage/out_of_scope.py`

判定: Warning

[Warning] 自己言及検査に祖先パスによる迂回があります。

現在は次だけを拒否しています。

- 宣言ファイルまたは監査文書との完全一致
- `verification_ref` が対象外 prefix の配下にある場合

そのため、例えば以下は通過します。

- `verification_refs: ["app"]` により `app/Jobs` 自体を内包する親を代替検証にする
- `verification_refs: [".claude/skills/app-bug-hunt/coverage"]` により宣言自身を含むディレクトリを参照する

これは D27 の「代替検証が自己言及でない」という保証と一致しません。パスの重なりを子方向だけでなく、親方向も含めて拒否してください。

[Warning] JSON の重複キーを検出できません。

`json.loads()` は同一 object 内の重複キーを黙って最後の値に上書きします。そのため、必須キー・未知キーの厳密検査があっても、重複した `entries`、`reason`、`verification_refs` などは拒否されません。レビュー表示と実際に採用される値が異なり得るため、deny-by-default の宣言形式として不十分です。

`object_pairs_hook` 等を用いて、トップレベルと entry の双方で重複キーを拒否する必要があります。

[Warning] すべてのパス境界障害が `DeclarationError` へ収束しません。

`Path.resolve()` は symlink loop 等で `RuntimeError` や `OSError` を送出できますが、CLI は `DeclarationError` しか捕捉しません。この場合、文書化された「終了コード 2・Traceback なし」という契約ではなく、未処理例外と終了コード 1 になります。

`repo_root` の解決と `_resolve_within()` 内の解決失敗を `DeclarationError` に変換してください。

[Suggestion] `_single_line()` は CR/LF しか除去しません。NEL、U+2028、U+2029 などは `splitlines()` 上の改行として残るため、「診断を1行に保つ」という契約を厳密には満たしません。

[Suggestion] `ID_PATTERN` は `alpha-` や `alpha--beta` を許します。ID を canonical な kebab-case とする意図なら、末尾ハイフンと連続ハイフンも拒否してください。

### `coverage/test_out_of_scope.py`

判定: Warning

[Warning] 実装上の主要な迂回に対応する負のテストがありません。

少なくとも以下が必要です。

- 対象 prefix の祖先を `verification_refs` にするケース
- 宣言ファイル・監査文書を含む親ディレクトリを参照するケース
- トップレベルおよび entry 内の JSON 重複キー
- symlink loop 等で `resolve()` が失敗しても、終了コード 2・stdout 空・Traceback なしになるケース
- Unicode の行区切りを含む入力でも stderr が一行になるケース

既存テストは広範ですが、自己言及について完全一致と子方向しか検査していないため、現在の実装と同じ穴を共有しています。

### `coverage/test_naming_no_stale.py`

判定: 問題なし

古い断定の再混入に限定した検査であり、新しい正しい文章の完全一致を pin していません。射程がスキル配下だけであることも文書で明示されています。

### `ledger/README.md` / `ledger/validate_findings.py`

判定: 問題なし

Finding 台帳と静的対象外監査の役割分担が明確になっています。

### `app/Http/Middleware/BughuntCoverageMiddleware.php`

判定: Suggestion

[Suggestion] `coverage/README.md` は実際の guard を `config('bughunt.pcov.enabled')` と記述していますが、docblock は `env (BUGHUNT_PCOV)` としています。設定値の由来と実行時の判定窓口は別なので、実装が `config()` を参照しているなら docblock も `config` guard と表現を揃える方が正確です。

strict_types、PHPStan level 10、製品挙動への影響については、このコメント差分に問題はありません。

### `docs/template-divergence.md`

判定: Warning

D27 の登録形式、件数更新、保証しない範囲は整っています。

ただし「代替検証が自己言及でないこと」を保証済みと記述している点は、祖先パスによる迂回が残る現在の実装より強い保証です。`out_of_scope.py` と負のテストを修正すれば記述どおりになります。

### `tests/Architecture/BughuntCoverageToolSelfTest.php`

判定: 問題なし

新しい Python テストが `composer test` に結線されており、「テストへの登録まで含めて実装済み」という要件を満たしています。PHP の strict_types、日本語コメント、型の扱いにも問題はありません。

### `tests/Architecture/TemplateDivergenceLedgerFormatTest.php`

判定: 問題なし

登録件数 26 と文書側の件数が同期しています。

## 全体判定

施策の配置と結線は概ね詳細設計の方向に一致しています。しかし、対象外宣言の自己言及迂回、JSON 重複キー、パス解決例外の未収束は、deny-by-default と fail-closed の契約に直接関わります。

**CHANGES_REQUESTED**