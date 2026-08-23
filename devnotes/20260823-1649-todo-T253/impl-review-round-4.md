## 全体判定

`CHANGES_REQUESTED`

Round 3 の主要な実装不具合は解消しています。

- メール更新と監査は同一 transaction になった
- commit と適用の間の順序を、実際の二段の継ぎ目で検査できるようになった
- G2 の通常のネスト呼び出し誤認は修正された
- lock release の best-effort + 固定 warning は妥当

承認を阻むのは次の2点です。

1. `consumeToken()` / `applyConfirmedEmail()` の公開により、token 検証を経ずに任意の確認済みメールを適用できる新しい口が生まれた
2. scanner の深さ管理が `T_ATTRIBUTE` と文字列内挿の開始 token を扱わず、特定構文でネストした引数を外側のものと誤認し得る

それ以外は Suggestion として分離します。

# 承認を阻む項目

### [EmailPromotionService.php](/workspace/.claude/worktrees/tasks/T253/app/Services/Auth/EmailPromotionService.php)

メール更新と監査の原子性は `APPROVE` です。

`recordOrFail()` がメール保存と同じ第2段 transaction 内にあるため、監査記録が失敗すると次がまとめて rollback されます。

- `email`
- `email_verified_at`
- security audit 行

token の消費は第1段で確定済みなので戻りません。これは意図した二段構成と整合しています。

blind index 競合時も、監査へ到達する前または同じ savepoint 内で transaction が戻るため、監査行だけが残る問題はありません。

[Warning] 二段の公開により、`confirm()` が担っていた token 検証と本人結合を迂回できるようになりました。

現在は任意の app コードから次を実行できます。

```php
$service->applyConfirmedEmail(
    $user,
    VerifiedEmail::afterConfirmation('attacker@example.com'),
);
```

この呼び出しは以下を必要としません。

- promotion 行
- token の指紋照合
- token の期限
- token と利用者の結合
- `consumeToken()` の先行実行

`email === null` だけを満たす利用者なら、メールと確認時刻を設定し監査まで記録できます。

また、`consumeToken()` だけを呼べば、適用せずに他人の確認 token を不可逆に消費する実装も書けます。現時点の controller はそのように呼んでいませんが、公開APIとしては `confirm()` より弱い契約を二つ追加しています。

「二段構成がサービス内部の契約である」ことと、「各段を誰でも個別に呼べること」は別です。テスト容易性を理由に本番の操作面を広げるのは避けるべきです。

推奨する修正は、両メソッドを private に戻し、テスト専用の順序注入を production API にしない形です。例えば次のいずれかです。

- 第1段終了後・第2段開始前に呼ぶ内部 collaborator を注入し、通常実装は no-op、テスト実装だけが割り込む
- 二段の orchestration を専用の内部クラスへ分離し、公開入口は `confirm()` 一つのままにする
- テストから private method を直接呼ばず、別接続と同期点で実際の間隙を作る

どうしても public の二段APIを採るなら、少なくとも次が必要です。

- `applyConfirmedEmail()` が、`consumeToken()` だけが生成できる capability を要求する
- capability が利用者ID・promotion ID・消費対象を結合する
- 任意の `VerifiedEmail` だけでは適用できない
- 両メソッドの呼び出し元を Architecture gate で exact-fit に固定する

現在の `VerifiedEmail` だけでは「token を正当に消費した結果」であることを表せません。

---

### [EnterpriseSsoSourceScanner.php](/workspace/.claude/worktrees/tasks/T253/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php)

通常の丸括弧・角括弧・波括弧によるネスト修正は正しく、提示された以下の形は拒否できます。

```php
fetch($this->build(followRedirects: false), $deadline)
```

[Warning] PHP token 上の opener を文字列だけで判定しているため、attribute と文字列内挿で深さが崩れます。

PHP の attribute 開始は通常の `[` ではなく `T_ATTRIBUTE` の `#[` token です。現在の実装はこれを opener として数えませんが、閉じ側の `]` は decrement します。その時点で深さが1から0へ崩れます。

例えば attribute 付き closure/arrow function を引数に含む形では、その後の内側呼び出しが再び深さ1になり、外側の引数として誤認され得ます。概念的には次の形です。

```php
$this->pinned->fetch(
    #[Probe]
    fn () => $this->build(followRedirects: false),
    $deadline,
);
```

token の推移は概ね次になります。

```text
fetch(       depth=1
#[           現実装では増えない
]            depth=0
build(       depth=1
followRedirects: false  ← 外側の引数と誤認し得る
```

文字列内挿も同様です。PHP は補間文字列を常に単一 token にするわけではなく、`T_CURLY_OPEN` や `T_DOLLAR_OPEN_CURLY_BRACES` を含む token 列になります。通常の `{` だけを opener として数え、対応する `}` を decrement すると深さが崩れます。

次を opener として明示的に扱ってください。

- 通常の `(`、`[`、`{`
- `T_ATTRIBUTE`
- `T_CURLY_OPEN`
- `T_DOLLAR_OPEN_CURLY_BRACES`

あるいは、delimiter の種類を stack で管理し、対応しない閉じ delimiter が出たら未解決として fail-closed にする方が堅牢です。単一の整数だけだと、`([)]` のような壊れた対応関係も検出できません。

attribute と文字列内挿を「保証外」とする選択も理論上はできますが、これらを使って `fetch()` の外側引数検査を迂回できるなら、G2-4 の「すべての fetch」という主張を狭めるか、未解決として落とす必要があります。

---

### [RedirectFollowingSample.php.txt](/workspace/.claude/worktrees/tasks/T253/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt)  
### [EnterpriseSsoSourceScannerTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php)

通常のネストに対する負例・正例は `APPROVE` です。

- 内側にしか同名引数がない → 違反
- 外側が literal `false` で、内側にも同名引数がある → 正常

この対で通常の深さ判定は両方向固定されています。

ただし scanner 修正に合わせ、少なくとも次の負例が必要です。

- attribute を含む引数の内側に `followRedirects: false`
- `"{$value}"` または `"${value}"` 型の文字列内挿を含む引数の後に、内側の同名引数
- delimiter が対応しない合成入力を「読み切れない」として落とす

正例として、attribute／補間文字列を含む引数があっても、外側に literal `followRedirects: false` があれば通る形も置いてください。

first-class callable の `fetch(...)` は、現在の scanner では安全なHTTP呼び出しとは確定できず違反側になります。G2 の狭い走査根では fail-closed として妥当です。その扱いを docblock に一行明記すると保証範囲が明確になります。

# 承認を阻まない項目

### [EmailPromotionTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Feature/Auth/EmailPromotionTest.php)

二段間の割り込みテストは、順序の検査としては改善されています。

`consumeToken()` が戻った後に別更新を行い、その後に `applyConfirmedEmail()` を呼んでいるため、少なくともアプリコード上の次の順序を正確に作れています。

```text
消費 transaction 終了
別経路の更新
適用 transaction 開始
```

ただしグローバル `RefreshDatabase` の外側 transaction があるため、テスト内でいう「commit 済み」は厳密には「第1段が開始した transaction/savepoint を閉じて基準の段へ戻った」です。本番の独立 transaction の commit と同じ可視性を証明しているわけではありません。

これは今回の再ロック分岐を検査する目的には十分ですが、docblock は次のように書く方が正確です。

```text
第1段が開いた transaction を閉じ、呼び出し前の transaction level に戻った後
```

[Suggestion] 第1段の前後で `DB::transactionLevel()` が baseline に戻ることを確認すると、「段を抜けた」ことを直接固定できます。

---

### [EmailPromotionTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Feature/Auth/EmailPromotionTest.php) の監査失敗テスト

監査 rollback の壊し方は `APPROVE` です。

`created` listener 内で行の存在を確認してから例外を投げているため、

1. INSERT は一度実行された
2. その後に例外が起きた
3. transaction rollback 後には行がない

という因果を固定できています。`creating` で止めるより明確です。

[Suggestion] `SecurityAuditEvent::flushEventListeners()` は現在のモデル構成では実害がないという調査は妥当です。ただし将来 trait や observer が追加された瞬間にテスト汚染へ変わります。

新しい Architecture gate まで作る必要はありませんが、可能なら listener を追加・削除できる局所的な test seam、または `SecurityEventRecorder` の失敗 collaborator を使う方が長期的には安全です。少なくともモデルに event trait／observer が追加された場合にこのテストを見直すコメントは残っています。

---

### [SecurityEventRecorder.php](/workspace/.claude/worktrees/tasks/T253/app/Services/Security/SecurityEventRecorder.php)

docblock の修正は `APPROVE` です。

「認証名前空間かどうか」ではなく、

- 状態変更と監査を原子的に確定する操作 → `recordOrFail()`
- ログイン成功／失敗などの観測 → `record()`

という意味上の境界になっており、現在の二つの利用箇所を正しく説明しています。

[Suggestion] `recordOrFail()` の使用者集合は exact-fit で固定する価値があります。

このメソッドをログイン試行の記録へ誤用すると、監査基盤障害がログイン障害になります。一方で `record()` を属性変更へ誤用すると、「変更済み・監査なし」が復活します。メソッド名だけでは呼び分けの意味が十分強くありません。

既存 scanner で呼び出しファイルを列挙できるなら、現在の二箇所を pin する小さな Architecture テストは有効です。ただし今回の T253 実装の正確性は、実挙動テストで既に固定されているため、これは承認阻害とはしません。

---

### [OidcDiscoveryService.php](/workspace/.claude/worktrees/tasks/T253/app/Services/EnterpriseSso/OidcDiscoveryService.php)

`APPROVE`

release を best-effort のまま維持し、固定 warning だけを残す判断は適切です。

ログには以下を含めていません。

- issuer／URL
- connection ID
- JWK／kid
- vendor exception message

したがって秘密・接続情報の漏洩面を増やしていません。

---

## 再確認事項への回答

1. メール更新と監査の原子性  
   **閉じています。** `recordOrFail()` の失敗でメール更新と監査INSERTが同じ第2段 transaction から rollback されます。blind index 競合時に監査も残らないのが正しい結果です。

2. 割り込みテストと公開API  
   順序の再現は改善されています。ただし、二段をそのまま public にしたことで token 検証を迂回する新しい操作面が生まれています。この点は承認阻害です。

3. scanner の深さ判定  
   通常の括弧・配列・波括弧には効きます。`T_ATTRIBUTE`、`T_CURLY_OPEN`、`T_DOLLAR_OPEN_CURLY_BRACES` を扱わないため、PHPの全 token 形にはまだ対応していません。

4. 負例・正例  
   通常ネストについては十分です。attribute と文字列内挿の両方向が不足しています。

5. `recordOrFail()`／`flushEventListeners()` の gate  
   `recordOrFail()` の caller pin は推奨しますが承認阻害ではありません。`flushEventListeners()` 全体を新しい scanner で縛る必要まではありません。現在の一件はモデル構成変化に弱いので、将来的には局所 seam が望ましいです。

6. 新しい欠陥  
   承認を阻む新しい欠陥は、公開された二段APIによる token 検証迂回と、scanner の特殊 token での深さ誤認です。

`CHANGES_REQUESTED`