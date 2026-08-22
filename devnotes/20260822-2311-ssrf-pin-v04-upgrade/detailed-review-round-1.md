仮説は「版上げの方向と責務分離は正しいが、回帰 gate の偽グリーン防止と波及確認に穴がないか」です。結論として、設計の骨格は妥当ですが、テスト保証範囲と検証手順に修正が必要です。

## 施策 0: 触らないことの宣言

判定: APPROVE

- 対象3債務パスと呼び出し側を変更しない判断は、I2/I3/I4/I10と整合しています。
- `mergeConfigFrom()` により `max_body_bytes` のpackage既定が補完される説明も正確です。
- `PinnedHttpClient` 未使用の現状では、`max_body_bytes` をアプリ設定へ追加しない判断も過小スコープではありません。
- `git diff --name-only` だけでなく、受け入れ基準で対象4ファイルを直接指定した差分確認を行うと、既存の無関係なdirty差分があっても判定しやすくなります。

## 施策 A: `^0.4` への版制約変更

判定: APPROVE

- VCS repositoryを維持し、制約だけを変更するためAG-003に適合しています。
- `^0.4`により0.5系を自動取得しない説明も正確です。
- AとBを不可分に扱う受け入れ条件も妥当です。

## 施策 B: composer.lockの限定再解決

判定: REQUEST_CHANGES

[Warning] 提示された「名前→版」比較スクリプトだけでは、許容差分を機械的に保証できません。

同じ版の別パッケージで、`source.reference`、`dist`、`require`、`autoload`などだけが変化しても、現在のスクリプトは検出しません。設計上は「対象エントリ全体とcontent-hash以外は不変」としていますが、検証手段がその条件より弱い状態です。

修正案:

- 更新前後のlockを構造比較し、次だけを除外して残りが完全一致することを検査してください。
  - ルートの`content-hash`
  - `kent013/laravel-ssrf-pin`のエントリ全体
  - 事前承認した新規依存エントリ
- あわせて対象エントリの`version`、`source.type`、`source.url`、`source.reference`、`require`を個別にassertしてください。
- 新規依存が見積りに反して出た場合は、自動的に許容せず設計へ戻る条件を明記してください。

[Suggestion] `composer update kent013/laravel-ssrf-pin`を実行するComposer自身のバージョンも実装記録へ残すと、lockメタデータ差分の原因を追いやすくなります。

## 施策 C: 特殊用途区間の回帰gate

判定: REQUEST_CHANGES

IP literalを避けてDNS応答経由にする点、`bind → forgetInstance → resolve`、Pest datasetの引数形、グローバル定数の使い方はいずれも正確です。

特に以下は問題ありません。

- S1のscalar datasetは1引数として正しい
- S2の配列datasetは2引数として正しい
- enum caseをdataset値に使うことも問題ない
- `const`はファイル内限定参照であり、読み込み順依存を外へ漏らしていない
- 名前空間つき`use`のみなので`NoNonCompoundGlobalUseTest`に抵触しない
- resolverは`bind`でありsingletonではないため、inspectorを忘却後にresolveすれば新しいresolverが注入される
- R2は`forgetInstance()`脱落による偽グリーンを適切に検出する

ただし、次の修正が必要です。

[Warning] S4の主張と検査対象が一致していません。

docblockは「A + AAAAの全件を検査する」と主張していますが、実際のテストはAレコード内の2件だけです。例えば「Aが1件でも公開ならAAAAを無視する」という後退が入っても、現在のS4は緑になります。

修正案:

- 少なくとも次をdatasetで固定してください。
  - A内で公開＋特殊用途
  - 公開A＋特殊用途AAAA
  - 特殊用途A＋公開AAAA
- すべて`NotGloballyReachable`になることを確認してください。
- 1ケースだけに留めるなら、docblockの保証を「同一レコード集合内の全件検査」まで狭める必要があります。ただしセキュリティ境界としては交差familyケースを追加する方を推奨します。

[Warning] gate整合表で`print`を禁止語として挙げている点がAGENTS.mdと矛盾します。

AGENTS.mdは`print`を明示的に対象外とし、「語彙を勝手に増やさない」と定めています。テストコード自体に`print`はないため実装違反ではありませんが、検出力の説明として誤っています。

修正案:

- 整合表から`print`を削除し、実際の対象である`echo`、`goto`、`global`、開始タグ付き出力記法だけを記載してください。

[Suggestion] `bind → forgetInstance → resolve`は安全な順序ですが、厳密には`bind`と`forgetInstance`の相互順序より「両方がresolveより前」が本質です。docblockの「順序が本質」はそのように書くと正確です。

## 施策 D: TEST-NET-3 fixtureの置換

判定: REQUEST_CHANGES

変更内容そのものは検査を緩めていません。

正常系fixtureは「分類上allowされるDNS応答」を表すため、公開到達可能と分類される値へ置き換えるのが正しいです。TEST-NET-3の拒否はC/S1へ移され、private拒否とDNS失敗のassertionも維持されます。拒否検査を正常系へ書き換える変更ではありません。

ただし、波及確認方法を補強してください。

[Warning] 設計に書かれたliteral検索だけでは、版上げの全影響を列挙したことになりません。

影響対象はTEST-NET系文字列だけでなく、次を含みます。

- `UrlSafetyInspector::inspect()`の全呼び出し
- `PinnedHttpClient`の利用
- `DnsResolverInterface`の差し替え
- `FakeDnsResolver`の全生成箇所
- `bindSnsDnsResolver()`の全呼び出し
- 定数やfixture関数を介して特殊用途アドレスを返す箇所

修正案:

- 実装記録に上記シンボル単位の全呼び出し元調査を追加してください。
- 新しく拒否される8区間すべてについて、既存fixtureとしての利用がないことを確認してください。
- 「他のTEST-NET等の出現」だけでなく、「分類を通るDNS応答fixture全体」を母集団にしてください。

`tests/Pest.php`へhelperを置く判断は、既存の`bindSnsDnsResolver()`に隣接しSNS用DNS fixtureとしてまとまるため許容できます。単なる債務回避による不自然な配置にはなっていません。

## 施策 E: AGENTS.mdの記述更新

判定: REQUEST_CHANGES

[Warning] 「安全境界はconfigにpinする」という既存文と、その直後の「安全境界の一部は同梱登録簿」という説明がやや矛盾して読めます。

また「package `^0.4`以降」は、自然言語では0.5以降も同じ方式であると保証するように読めます。現在固定できるのは0.4系の契約です。

修正案:

- 次のように責務を分けて記載してください。
  - アプリ設定の5値は`config/ssrf-pin.php`と`SsrfPinBoundaryTest`でpin
  - 分類実装と登録簿は`composer.lock`のpackage revisionと新規回帰gateで受ける
- 「`^0.4`以降」ではなく「現在採用する0.4系」としてください。
- 将来版については、gateが赤くなった時点で再評価する、という監視条件に留めてください。

## 乖離台帳

判定: APPROVE

新規テストを逸脱登録しない判断は妥当です。

- ドメイン固有のlogic-driven divergenceではない
- 正典側も同じtargetへ進む途中である
- 指紋突合の母集合外で、登録しても機械保証が増えない

この3点が揃っており、「登録を避けたいから登録しない」という判断にはなっていません。正典側に同関心の別名gateが追加された場合を再判定条件にしている点も適切です。

## 第二層契約検査を作らない判断

判定: APPROVE

既存の境界pin、呼び出し経路gate、新規の実挙動回帰gateで今回のtarget_versionを受けられます。別の`SsrfPinPackageContractTest`を加えると、source/reference/API surfaceの重複固定になりやすく、t0の必須要素でもありません。現時点では過小スコープではありません。

## 横断的な受け入れ条件

[Warning] AGENTS.mdで要求される検証コマンドが完了条件から漏れています。

現在の完了条件には次がありません。

- `pnpm test`
- `pnpm typecheck:packages`
- `pnpm build:packages`
- `pnpm test:packages`

修正案:

AGENTS.mdの`VERIFICATION_COMMANDS`にある全コマンドを受け入れ条件へそのまま反映してください。frontend無変更でも、リポジトリの完了契約として省略できません。

## 全体判定

CHANGES_REQUESTED

Criticalはありません。版上げの方向、責務分離、fixture変更、台帳判断、第二層を増やさない判断は妥当です。主な修正点は、S4のA/AAAA交差検査、lock差分の完全比較、波及調査の母集団明確化、AGENTS.mdの境界表現、検証コマンドの全数化です。