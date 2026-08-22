# 全体判定: CHANGES_REQUESTED

設計の方向性は正典 v1 と合っていますが、現状は次の3仮説を満たしません。

- 実際の競合分岐を証明できるか: **否**。409 の種類を観測していないため、`conflict` でも緑になります。
- 子が安全なテスト DB だけへ接続するか: **否**。`env -i` の実効性と全 DB 座標を、接続前に検証できていません。
- commit 済み検体を必ず除去できるか: **否**。削除対象が列挙されず、fixture 作成失敗時の接続回収も不足しています。

正典6要素では、(6)「実プロセス版を1本に絞る」は満たしますが、(1)〜(5)には修正が必要です。

## 施策1: REQUEST_CHANGES

[Critical] `present('entered-')` を `glob($prefix.'*')` で実装すると、`signal()` が作る `entered-a.<random>.partial` も検出対象になります。原子的 rename を採用していても、列挙側が書きかけを観測するため、二重実行判定が壊れます。

修正案:

- `present()` は任意 prefix の glob ではなく、親が割り当てた2つの完成ファイル名だけを調べる。
- `.partial` を候補に含めない。
- 未知 child ID、未知ファイル、重複 signal をプロトコル例外にする。
- `entered-*` の内容も nonce と後述の go token に照合する。

[Critical] `ready` は単に存在を待つだけでは不十分です。別 child のファイル、空内容、誤った nonce でも全員準備済みと判定できます。

修正案: `ready-{childId}` の内容を、その child に割り当てた nonce と完全一致で検査してください。

[Warning] `path()` が任意の名前を受け入れるため、誤った child ID や `/`、`..` を含む値が barrier ディレクトリ外を指せます。

修正案: signal 名を固定された語彙と child ID 正規表現に限定し、パス区切りを拒否してください。

## 施策2: REQUEST_CHANGES

[Critical] cleanup の内容が「cascade を利用する」という説明に留まり、`OrganizationProvisioningService` が commit する全行を除去できる根拠がありません。Organization、Team、Project、membership、Laratrust 関連、API key、User などのどれが cascade されるか不明なままです。

修正案:

- fixture が作成した主キーを専用 DTO に保持する。
- 実効 FK に基づき削除順と cascade 対象を明示する。
- cleanup 後に、作成した全主キーが別名接続から消えたことを検査する。
- 見本テストと `OutOfTransactionFixturesTest` の双方で、完全な fixture graph の残留ゼロを確認する。

[Warning] `create()` の callback が例外になった場合、既定接続名は戻りますが、登録した別名接続の disconnect/purge が保証されません。

修正案: `create()` 自身が失敗時に別名接続を disconnect/purge し、成功時だけ後続の読み取り・cleanup 用に維持してください。

[Warning] 「テストデータは必ず Factory」と書かれていますが、提示された `issueApiKey()` は Factory ではなく `ApiKey::createForOrganization()` を使っています。

修正案: 既存規約上このドメイン生成 API が許可されるなら、「Factory または既存の正規ドメイン生成 helper」と正確に記述してください。Factory 必須が本当に絶対条件なら設計を変更する必要があります。

## 施策3: REQUEST_CHANGES

[Critical] 観測に HTTP status しかなく、409 のエラーコードがありません。以下の誤実装でもテストが緑になります。

- 2子の body が異なる
- 敗者が `idempotency_conflict` を返す
- handler 実行回数は 0
- status は 409
- 勝者は 201、行は1件 completed

これは本テストが主張する `idempotency_in_progress` を証明しません。

修正案: 観測に少なくとも次を追加し、敗者について完全一致で検査してください。

- API エラーコード `idempotency_in_progress`
- 実際に送った request hash
- route name、URI、actor/API key ID
- 観測した go token

[Critical] `observed_go: bool` は自己申告にすぎません。子が go を待たずに処理を始め、終了時に `true` を出しても親は検出できません。

修正案: 親が全 ready 検証後に初めてランダムな go token を生成して `go` に書き、子はその値を `entered` と最終観測へ含めてください。go token は事前の引数に渡してはいけません。

[Warning] `entered_handler` と `handler_executions` の整合性が定義されていません。

修正案: シナリオ層で勝者を `entered=true / executions=1`、敗者を `entered=false / executions=0` と固定してください。

## 施策4: REQUEST_CHANGES

[Critical] `ALLOWED_PROCESS_ENV_KEYS` は宣言されているだけで、子が実際に受け取った環境変数集合を検査していません。`env -i` が削除・破損すると、親の `DB_URL` が子へ継承されます。phpdotenv の immutable 読み込みでは、env ファイルの空の `DB_URL` より既存環境変数が優先される可能性があり、接続前ガードを迂回します。

修正案:

- 子の最初期、Laravel 起動前に `getenv()` のキー集合を検査する。
- 許可する3キーとの完全一致または、明示した最小集合だけを許可する。
- 不明な `DB_*`、`APP_*` が1つでもあれば bootstrap 前に終了する。
- failure-path test に、継承された `DB_URL`/`DB_DATABASE` を拒否する負例を追加する。

[Critical] DB 座標の観測項目を用意しながら、見本テストは database 名しか比較していません。同名 DB が別 host/port/user に存在する場合でも通ります。

修正案: driver、host、port、database、username、charset、sslmode を親が渡した値と完全一致で検査してください。`url` は `null` または空文字以外を拒否し、配列など非文字列値も fail-closed にしてください。

[Critical] `?array $processes` の注入だけでは、偽 process が runner 内で生成された一時ディレクトリや barrier の場所を知れません。したがって ready、entered、out を生成する failure-path test の構成が成立しません。

修正案: `ProbeProcessFactory` に起動仕様を渡す設計にしてください。起動仕様には workspace、child ID、nonce、script arguments を含め、偽物も同じ仕様を受け取れるようにします。あるいは workspace/barrier 自体を runner へ注入してください。

[Critical] 正常終了コードの検査が明記されていません。正しい out を書いた後に子が非ゼロ終了しても、観測だけで通る可能性があります。

修正案: 両 process の exit code が必ず 0 であること、stdout JSON と原子的 out ファイルが同一であることを回収条件に追加してください。

[Warning] 各 `await()` に30秒を渡す方式では、ready 1、ready 2、entered、out、終了待ちのたびに締切が更新され、総時間が30秒を大幅に超えます。「全体の締切」という記述とも不一致です。

修正案: runner 開始時に単一の絶対 deadline を作り、すべての待機へ残時間を渡してください。release 後の終了待ちと reap にも同じ deadline を適用します。

[Warning] interface は `stopAndReap()` という一操作だけです。この抽象から「runner が停止・kill・wait をそれぞれ要求した」とは証明できません。

修正案: 保証文を「runner が `stopAndReap()` を要求する」に狭めるか、停止・強制終了・wait を個別操作へ分けてください。

## 施策5: REQUEST_CHANGES

[Critical] 提示コードの route closure は `$nonce` と `$timeoutSeconds` を `use` へ取り込んでいません。勝者が handler に入ると未定義変数になり、strict type 下で `signal()` または `await()` が失敗します。

修正案:

```php
function () use (
    $barrier,
    $childId,
    $nonce,
    $timeoutSeconds,
    &$handlerExecutions,
): JsonResponse
```

[Critical] `loadEnvironmentFrom()` は env ファイルをその場で解析するメソッドではありません。bootstrap 前の `$databaseFromEnvFile` をどう取得・検証するかが未定義です。

修正案: Laravel 起動前に専用 env ファイルを明示的に解析し、キー集合、型、DB 名、改行を含む値の round-trip を検証してください。env ファイル生成側は password や key に引用符、`#`、空白、改行があっても別キーを注入できない形式にする必要があります。

[Critical] cache の array 固定は最終観測だけでなく、`ready` を出す前に子自身が検査すべきです。測定後に array でなかったと判明して赤になるだけでは、「守りたい層以外を無効化してから測る」という正典 (3) を満たしません。

修正案: kernel bootstrap 後、request 前に default store と解決した driver がともに `array` であることを検査し、違えば ready を出さず終了してください。

[Warning] plain API key をコマンドライン引数で渡すと、プロセス一覧から読めます。

修正案: 0600 の保護済み入力ファイルか pipe/stdin で渡してください。ready/go のパスなど秘密でない値だけを引数にします。

[Warning] middleware 列を「本番同等」としていますが、提示された本番順序にある throttle、project-in-org、ability は含まれていません。

修正案: 「冪等 middleware の前提を満たす最小 probe 経路」と表現を狭めてください。本当に本番同等を主張するなら、不足 middleware を追加する必要があります。

## 施策6: REQUEST_CHANGES

[Critical] 前述のとおり `[201, 409]` と handler 合計1だけでは `in_progress` を証明できません。異なる body による conflict でも同じ結果になります。

修正案: 敗者の JsonResource 応答を decode し、エラーコードが `idempotency_in_progress` であることを一次観測と release 前条件の双方で検査してください。

[Critical] 提示された migration では `response_status` が NOT NULL ですが、`claim()` は processing 行に `null` を insert しています。後続 migration で nullable 化されていなければ、初回 claim 自体が失敗します。

修正案: 実効 schema を明示してください。

- 後続 migration があるなら設計書から参照する。
- 無いなら本設計の前提不成立として別修正を先行させる。
- 「アプリコード・database を1バイトも変更しない」というスコープを黙って破らない。

[Critical] cleanup closure が省略記号のままで、後続テストを汚さないことをレビューできません。

修正案: 削除対象、条件、順序、cascade の根拠を詳細設計へ展開し、cleanup 後の全残留検査を追加してください。

[Warning] 「最大4接続」はテスト全体については誤りです。paratest の他 worker も同時に DB 接続しています。このテストが追加するのは概ね alias 1本と子2本です。

修正案: 「当該 worker 内で最大4本、suite 全体では他 worker 接続に最大3本を加算」と記述し、CI PostgreSQL の接続上限との関係を確認してください。

## 施策7: REQUEST_CHANGES

[Critical] 現在の注入点では fake process が barrier workspace を知らないため、表の #4〜#7 を実装できません。

修正案: 施策4の process factory/workspace 注入へ変更し、fake が start/poll の各段階で signal を生成できる決定的な状態機械にしてください。

[Warning] 「ファイルは存在するが読めない」のテストは、root 実行環境では chmod 000 でも読めるため不安定です。

修正案: filesystem abstraction を注入するか、読み取り処理を fail-closed な小メソッドへ分離し、`file_get_contents() === false` を決定的に返す fake で検査してください。

[Warning] 不足している failure-path があります。

修正案として、少なくとも次を追加してください。

- ready nonce 不一致
- 未知 child ID の entered
- `.partial` が完成 signal として扱われない
- go token 未観測
- stdout と out の不一致
- process の非ゼロ終了
- 全体 deadline が待機段階ごとに更新されない
- 実 process 環境への未知 `DB_*` 混入
- env 値の改行によるキー注入拒否

## 施策8: REQUEST_CHANGES

[Warning] 変更後 docblock は「実プロセス版が claim の commit 可視性を測っている」と記述しますが、現状の409観測では conflict と in-progress を区別できないため、この主張はまだ成立しません。

修正案: 施策3・5・6で `idempotency_in_progress` の完全一致観測を実装した後に、この文書変更を入れてください。それが済めば記述の保証範囲は妥当です。

## 施策9: REQUEST_CHANGES

[Warning] ファイル数の台帳が内部で一致していません。

- `tests/Support/Concurrency` の新規は記載内容から数えると10本で、「11本」ではありません。
- 新規全体は `OutOfTransactionFixturesTest.php` を含めると13本で、「12本」ではありません。
- 施策一覧にも `OutOfTransactionFixturesTest.php` が変更ファイルとして載っていません。

修正案: 新規・変更ファイルを全件列挙し直し、fingerprint 判定と実装モードの件数を同じ母集団から算出してください。

[Suggestion] D7を完了扱いせず、preview 上限の逐次境界を残す判断自体は妥当です。実際の更新日と feature revision を再判定記録へ併記すると追跡しやすくなります。

## 修正後に成立すべき最小プロトコル

1. 親が child ID と nonce を割り当てる。
2. 子は実 process 環境と env ファイルを bootstrap 前に検証する。
3. 子は framework 起動後、DB 座標と array cache を検証してから ready nonce を出す。
4. 親は全 ready nonce を検証後、初めてランダムな go token を生成する。
5. entered は `child ID + nonce + go token` を持つ。
6. 敗者 out は同じ識別情報に加え、`409 + idempotency_in_progress + handler 0` を持つ。
7. これらを検証するまで release しない。
8. 最終観測、out、stdout、exit code、DB 行を相互照合する。
9. 単一の全体 deadline 内で全 process を reap する。
10. fixture graph を finally で全削除し、残留ゼロを別名接続から確認する。

この形なら、正典 v1 の6要素と「緑のまま嘘になる経路」の双方をかなり強く閉じられます。