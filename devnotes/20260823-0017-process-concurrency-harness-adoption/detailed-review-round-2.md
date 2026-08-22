# 全体判定: CHANGES_REQUESTED

`response_status` の反論は正当です。後続 migration により nullable であり、Round 1 の当該指摘は撤回します。P1 の追加で追跡可能性も確保されています。

Round 1 の主要な穴はほぼ塞がれましたが、修正版には実装不能または実行時エラーになる不整合が残っています。特に go token の closure capture、runner 内部値の返却契約、タイムアウト後の reap が Critical です。

## 施策1: REQUEST_CHANGES

[Critical] `name()` で検証しても、`signal()`、`await()`、`path()` は任意の `string $name` を直接受け取ります。呼び出し側は `name()` を経由せず `../outside` を渡せるため、「任意文字列を受け付けない」は構造的に成立していません。

修正案:

- `signal()`、`await()`、`path()` の入口すべてで名前を再検証する。
- または `SignalName` 値オブジェクトを導入し、`name()` だけが生成できる形にする。
- 許可形は、例えば次へ限定する。

```text
go
release
ready-a / ready-b
entered-a / entered-b
out-a / out-b
```

[Warning] 現在の `name()` は `ready`、`go-a`、`release-b` のような、語彙としては正しいがプロトコル上不正な組合せを生成できます。

修正案: `go`/`release` は child ID 禁止、`ready`/`entered`/`out` は child ID 必須として検証してください。

[Warning] reader のプロパティを `mixed` にしており、型安全性の説明と一致しません。tests が PHPStan 対象外だからこそ、不要な `mixed` は避けるべきです。

修正案: constructor で `?callable` を受け、内部では `?Closure` として保持してください。

```php
private readonly ?Closure $reader;
```

## 施策2: APPROVE

FK の実読、物理削除、削除順、8表の残留検査、失敗時の接続回収まで具体化されており、Round 1 の問題は解消されています。

[Suggestion] `cleanup()` 内で残留ゼロを検査することを実装契約にしてください。別テストだけに任せると、見本テスト自身の cleanup 完全性が弱くなります。

## 施策3: REQUEST_CHANGES

[Warning] `error_code` の勝者側の型が未定義です。成功応答にはエラーコードがないため、JSON 上は `null` にするのか空文字にするのか明示が必要です。

修正案: `?string` とし、勝者は `null`、敗者は `idempotency_in_progress` の完全一致としてください。

[Warning] DB 座標を `array<string, string>` としていますが、観測の `db_port` は `int` です。strict 比較時の正規化が曖昧です。

修正案: `ProbeDatabaseCoordinates` のような型付き DTO を使い、port は最初から `int`、その他は `string` としてください。外部観測のキャスト禁止とも整合します。

## 施策4: REQUEST_CHANGES

[Critical] 単一 deadline を reap にも使う設計では、タイムアウト発生時点で残時間が0です。その後の `wait(残り)` が即失敗し、SIGKILL 後の wait ができず、子を残す可能性があります。

修正案:

- 論理的なテスト deadline と、回収専用の短い deadline を分ける。
- 通常処理は全体 deadline で打ち切る。
- `finally` は残時間にかかわらず TERM → 短い猶予 → KILL → bounded wait を必ず実行する。
- 「60秒以内」は通常処理の締切とし、異常回収に最大1～2秒上乗せされることを明記する。

あるいは60秒の中に reap 用時間を事前予約し、通常処理の deadline をその分だけ短くしてください。

[Critical] runner の戻り値は観測配列だけですが、施策6では runner 内部で生成した `$nonces` と `$goToken` をテストから参照しています。現在の API では取得できません。

修正案: 次のどちらかに統一してください。

- runner 内で identity 検査を完結させ、見本テストから `$nonces`/`$goToken` の再検査を削除する。
- `ConcurrentProbeResult` DTO を返し、observations、nonces、goToken、routeName、URI、requestHash を保持する。

前者の方が内部プロトコルを外へ漏らさず簡潔です。

[Warning] `wait(float $seconds)` は Symfony Process の同名 API をそのまま包めません。Symfony の `wait()` は秒数を受け取る API ではありません。

修正案: `SymfonyProbeProcess` が `isRunning()` と単調時計で独自に bounded wait を実装することを明記してください。

[Warning] 「未知 child ID の entered を拒否する」とありますが、`present()` は割り当て済み2ファイルだけを見るため、`entered-c` は検出されず無視されます。

修正案:

- 未知ファイルを明示的に拒否するなら、完成ファイル用ディレクトリを列挙し、許可集合との差分を検査する。
- 列挙しない方針なら、「未知 child は期待 signal が来ないため timeout で fail-closed」と保証を狭め、施策7の #9 を削除する。

## 施策5: REQUEST_CHANGES

[Critical] route closure は `$goToken` を値キャプチャしていますが、closure の定義時点ではまだ go を待っておらず、`$goToken` は後で代入されています。値キャプチャされた closure には後の値が反映されません。

修正案:

```php
$goToken = null;

Route::post($uri, function () use (
    $barrier,
    $childId,
    $nonce,
    &$goToken,
    $remainingSeconds,
    &$handlerExecutions,
): JsonResponse {
    Assert::stringNotEmpty($goToken);
    // ...
});

$goToken = $barrier->await(...);
```

より明確にするなら、可変状態を保持する小さな DTO を参照させてください。

[Critical] request hash の正本となる request body のバイト列が未定義です。`Request::create($uri, 'POST', $body, ...)` の第3引数は通常 form parameter であり、JSON の raw body と同じではありません。親の期待 hash と middleware が見る内容が食い違う可能性があります。

修正案:

- 親で `json_encode(..., JSON_THROW_ON_ERROR)` した raw JSON を1回だけ生成する。
- 同じ raw bytes を両子の入力ファイルへ書く。
- `Request::create()` の content 引数へその bytes を渡し、`CONTENT_TYPE=application/json` を設定する。
- middleware と同じ規則で、method、正規化された path、同じ raw bytes から期待 hash を算出する。
- 子の観測値だけを信用せず、親の期待 hash と照合する。

[Warning] env の独自 encoder/parser と phpdotenv の二重解釈があります。特に double quote 内の `$`/`${...}` は dotenv 展開の影響を受ける可能性があります。

修正案:

- `$` を含めた escaping 規則を定義する。
- 空文字、`\`、`"`、`#`、空白、`$`、`${NAME}` を round-trip する正例を追加する。
- 自前 parser の結果と Laravel bootstrap 後の実効値が一致することを検査する。

[Warning] env/input ファイルを「作成時点から0600」にする手順がまだ明記されていません。書き込み後の chmod では短時間の露出が残ります。

修正案: 排他的作成と制限 umask、または0600での安全な作成手順を明示してください。

## 施策6: REQUEST_CHANGES

[Critical] fixture callback 内の `$routeName` が未定義かつ `use` されていません。また route name は runner が後で決める設計なので、この時点では取得できません。

修正案: cleanup は `api_key_id` で行っているため、`ConcurrencyFixtureKeys::$routeName` を削除してください。必要なら runner 実行前に route name を生成し、runner へ明示的に渡します。

[Critical] `$nonces` と `$goToken` は見本テストのスコープに存在しません。現在のコードは実装できません。

修正案: 施策4のとおり、identity 検査を runner 内で完結させるか、結果 DTO に含めてください。

[Critical] 「同一 body」を証明する request hash の生成規則が施策5と同様に未確定です。子2本の hash が同じでも、両方が同じ誤った body を送っている可能性があります。

修正案: 親が期待 hash を計算し、次の3点をすべて一致させてください。

- winner の request hash
- loser の request hash
- 親の期待 request hash

[Warning] idempotency 行の裏取りが `api_key_id` だけです。route と key のスコープまで確認していません。

修正案: `api_key_id + route_name + key` で絞り、保存された `request_hash` も親の期待値と一致させてください。

[Warning] 観測した `api_key_id` は2子間の一致だけでなく、fixture の `$keys->apiKeyId` と一致させる必要があります。

修正案:

```php
expect($winner->apiKeyId)->toBe($keys->apiKeyId);
expect($loser->apiKeyId)->toBe($keys->apiKeyId);
```

`api_key_id` は入力値のコピーではなく、認証後の `ApiActorContext` から観測してください。

[Suggestion] 「プロセス間で共有されるアプリ側ロックは1つも無い」は、default cache が array であることから直接証明できる範囲を超えます。「Laravel default cache を使うプロセス間共有ロックは利用できない状態」と狭める方が正確です。unique 制約だけを使う事実はP2の実読根拠として分離してください。

### `response_status` について

この施策に対する Round 1 の schema 指摘は撤回します。後続 migration により nullable であり、設計上の問題はありません。

## 施策7: REQUEST_CHANGES

[Warning] #9「未知 child ID の entered を通さない」は現在の `present()` では検出できません。

修正案: 施策4と同様、未知完成ファイルを列挙して拒否するか、timeout による fail-closed へ保証を狭めてください。

[Warning] env encoder/parser の安全性検査として、改行拒否だけでは不足します。

修正案: `"`、`\`、`$`、`${VAR}`、`#`、先頭末尾空白、空文字の round-trip 正例を追加してください。拒否だけでなく正規入力を誤検出しないことも固定する必要があります。

[Suggestion] failure-path の25件は、ProcessBarrier、ProbeEnvironment、Observation、Runner の4グループに分けてテスト名を付けると、失敗時の原因を追いやすくなります。

## 施策8: APPROVE

施策3・5・6への依存が明記され、保証範囲も `claim` の可視性と in-progress 分岐の2点へ限定されています。

## 施策9: REQUEST_CHANGES

[Warning] ファイル数がまだ不一致です。

- 見出しは「新規ファイル（16本）」
- 表は1〜17
- 内訳は Support 14 + Feature 2 + Unit 1 = 17
- 実装モードも新規17本

修正案: 見出しを「新規ファイル（17本）」へ直してください。Round 2 冒頭の「新規16本 = 支援13 + テスト3」という記述も「17本 = 支援14 + テスト3」へ統一が必要です。

[Suggestion] 「テンプレートが同じ feature を実装済み」である一方、「先行実装」と表現している点は意味が逆に読めます。これは aicue 側から見た「追従実装」であり、将来取り込む対象との衝突を避ける判断だと表現する方が明確です。

## 修正後に再確認すべき4点

1. go token は route closure の実行時に確定済みの値を参照しているか。
2. runner 内部の nonce/go token/route/request hash と、見本テストの公開 API が一致しているか。
3. 通常 deadline を使い切った後でも、TERM/KILL/wait が必ず完遂されるか。
4. 親が作った raw request bytes と middleware が hash した bytes が完全に同じか。

この4点と signal 名検証の入口統一が直れば、正典 v1 の6要素について APPROVED にできる水準です。