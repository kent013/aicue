# 全体判定: CHANGES_REQUESTED

基本方針、特に「パスキー削除だけを transaction 外で明示 flush する」という構成は妥当です。`EnsureLoginMethodRemains` 内部のロック順序・投影評価・reject/pass 分岐も、提示された変更では維持されています。

ただし、PHPStan level 10 で止まる可能性が高いコールバック定義と、rollback 統合テストの欠落があります。また、「commit と通知が 1:1」という保証表現は実装の best-effort 契約と一致しません。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1. enum・Notification・Notifier | REQUEST_CHANGES |
| 2. パスキー削除の commit 後発火 | REQUEST_CHANGES |
| 3. イベント購読 listener・DI | REQUEST_CHANGES |
| 4. パスワード・SSO の直接配線 | APPROVE |
| 5. deny-by-default 目録 | REQUEST_CHANGES |
| 6. テンプレート差分登録 | REQUEST_CHANGES |
| 7. 運用ドキュメント | REQUEST_CHANGES |
| 8. テスト | REQUEST_CHANGES |

---

## 施策 1: REQUEST_CHANGES

[Critical] `notifyAfterCommit()` の arrow function は、`Closure(): void` 契約と整合しません。

```php
$this->postCommitCallbacks->push(
    fn (): mixed => $this->notify($user, $event, $context),
);
```

`notify()` は `void` なので、その結果を arrow function の戻り値として扱う形はPHPStanで問題になります。また、collector側のプロパティは `list<Closure(): void>` なのに、`push()` の引数には callable signature のPHPDocがありません。

修正案:

```php
public function notifyAfterCommit(
    User $user,
    AuthMethodChangeEvent $event,
    ?string $context = null,
): void {
    $this->postCommitCallbacks->push(
        function () use ($user, $event, $context): void {
            $this->notify($user, $event, $context);
        },
    );
}
```

```php
/** @param Closure(): void $callback */
public function push(Closure $callback): void
{
    $this->callbacks[] = $callback;
}
```

[Warning] テスト計画の「期待した `AuthMethodChangeEvent` か確認する」は、現在のNotification APIでは直接実行できません。`$event` と `$context` はprivateでgetterがありません。

修正案は次のいずれかです。

- `event()` / `context()` / `occurredAt()` のreadonly getterを追加する
- enumそのものではなく、`toMail()` の件名・本文を検査する方針へ変更する

イベントとenumの対応を直接固定したいならgetterが明確です。

[Suggestion] 「`SerializesModels` はQueueable経由」という説明は不正確です。notifiableの再取得はqueued notificationを包むqueue job側のモデル直列化に依存します。結論である「worker実行時の現在のメールアドレスへ送る」は妥当ですが、根拠の記述を直してください。

---

## 施策 2: REQUEST_CHANGES

内部のtransaction構造は保たれています。次の点は正しいです。

- Userロック取得順序を変更していない
- ロック後の投影評価を変更していない
- `$next()` は引き続きtransaction内
- rejectでは削除イベントが発火せず、flushはno-op
- 例外時にはdiscardして再送出

[Warning] 「commitが成立した場合にのみ実行」は片方向の保証ですが、「commit成否と通知が1:1」は保証できません。

commit後に次のいずれかが起きれば、変更は確定しているのに通知は投入されません。

- flush前のプロセス終了
- queue投入失敗
- `notify()` が例外をcatchして継続

修正案:

> rollbackした場合は通知投入を試みない。transaction呼び出しの正常終了後、通知投入をbest-effortで1回試みる。

という保証に統一してください。厳密な1:1が本当に必要ならtransactional outbox等の再設計が必要ですが、本設計のbest-effort方針とは別物なので、現時点では保証表現の修正が適切です。

[Warning] `DB::transaction()` の正常終了が物理commitを意味するのは、それが最外transactionの場合だけです。`RefreshDatabase` 下では外側transactionが存在するため、テスト中のflushは物理commit前になります。

修正案:

- productionのWeb経路では本middlewareが最外transactionを所有する、という前提を明記する
- テスト結果を「物理commit後の耐久性の証明」とは表現しない
- 規約11との整合は「rollback経路では投入しない」「`DB::afterCommit()`系を追加しない」という範囲で記述する

[Warning] collectorには「現在このmiddlewareのtransaction中である」という状態がありません。将来、`PasskeyDeleted` が別経路から発火すると、callbackがflushされず消えるか、同一scope内の後続処理で誤ってflushされる可能性があります。

修正案として、少なくとも次を追加してください。

- middleware開始時の明示的な初期化
- activeでない状態の`push()`を拒否する仕組み
- active外push、discard後、二重flushのテスト

汎用化は不要ですが、middleware専用であることをコード上でも強制すべきです。

---

## 施策 3: REQUEST_CHANGES

subscriberの構成とイベント対応は既存`RecordSecurityEvent`と整合しています。認可・tenant境界・CipherSweetに対する新たな迂回もありません。

[Warning] `handlePasskeyDeleted()` は、そのイベントが必ず`EnsureLoginMethodRemains`内で発火することを暗黙に前提としています。しかしlistener自身はその前提を検証できません。

修正案:

- 施策2のcollectorにactive状態を持たせる
- active外の`notifyAfterCommit()`をfail-fastまたはreportして破棄する
- deny-by-default route gateが保証する範囲と、イベントを直接dispatchする経路は保証外であることをdocblockへ明記する

[Suggestion] パスワードリセットについて、`ResetUserPassword`が`PasswordCredentialService::change()`を経由しないことを実装時に再確認してください。経由する場合は`PasswordChanged`と`PasswordReset`の二重通知になります。テストは単に「PasswordResetが送られた」ではなく、対象Notificationの総数が1件であることまで固定すべきです。

---

## 施策 4: APPROVE

`setInitial()`と`change()`の区別、SSOの`register()`と`linkToUser()`の区別はいずれも妥当です。

特に次の点を評価できます。

- 既存SocialAccountに対するidempotentな`true`では通知しない
- 他ユーザーに連携済みの`false`では通知しない
- 新規アカウント登録では通知しない
- provider表示名は表示用途に限定され、tenant/ownershipキーとして使われない
- CipherSweet対象のemailへ平文検索を追加していない
- APIレスポンスを追加しないためDTO/JsonResourceは非該当

[Suggestion] パスワード変更テストでは、期待するenumの通知が1件であることに加えて、他のauth-method-change通知がないことも確認してください。リセット経路との二重発火を検出できます。

---

## 施策 5: REQUEST_CHANGES

2つの目録への登録方針と分類内容自体は妥当です。`ExternalSeamInventoryTest`がFQCNのFacade参照だけを対象とするという前提なら、`$user->notify()`を登録しない判断も整合します。

[Warning] 件数pinを最初から15→16、9→10へ変更する実装順は、テストファーストおよび「仕組みが機能していない段階で値を弄らない」に反します。

修正案として、詳細設計に次の順序を明記してください。

1. Notificationクラスを追加する
2. 既存gateが未登録クラスを検出して赤くなることを確認する
3. 検出された実数に基づいてexemptionを追加する
4. capをexact-fitで更新する
5. 緑を確認する

実装開始時には現在値が変わっている可能性があるため、15/9/16/10を固定的な真実として扱わないでください。

---

## 施策 6: REQUEST_CHANGES

採用時債務から削除し、意図的逸脱D36へ移す判断は妥当です。

[Warning] D36の「commitが成立した場合にのみ」「commit成否と通知が1:1」という説明が、best-effort実装より強い保証になっています。

修正案:

- 「正常終了後に投入を試みる」
- 「rollback時には投入を試みない」
- 「commit後のプロセス終了・queue投入失敗による通知欠落は保証しない」

と書き換えてください。

[Warning] 「既存`LoginMethodRemovalRouteTest`等がgreen」でロック順序・投影位置・transaction包含を保証したことにはなりません。routeへのmiddleware付与だけを確認するテストなら、内部構造の後退は検出できません。

修正案:

- 実際に同時削除またはロック下再評価を検証する既存テスト名をD36へ記載する
- 存在しない場合は、投影評価と削除が同一transactionであることを固定するcharacterization testを追加する
- D36の「保証機構」には、今回追加するrollback統合テストも記載する

---

## 施策 7: REQUEST_CHANGES

イベント対応表と対象外の整理は明瞭です。ただし、施策2・6と同じ保証の過大表現を修正する必要があります。

[Warning] 「queue投入の成功までが保証範囲」としながら、Notifierはqueue投入例外を吸収します。したがってqueue投入成功そのものは保証されません。

修正案:

> queue投入をbest-effortで試行するところまでを責務とし、投入成功およびメール配送成功は保証しない。

へ変更してください。

[Suggestion] `scoped()`はHTTPだけでなく、Laravelの長寿命workerではjob lifecycleごとにもscopeが更新される概念です。「HTTP request scope限定」ではなく、「HTTPリクエスト間・queue job間で共有しない。本機構の利用対象はHTTP middlewareだけ」と分けて記述する方が正確です。

---

## 施策 8: REQUEST_CHANGES

[Warning] 冒頭の波及変更には「削除後の後続処理が例外になった場合は0通」がありますが、最終テストケース一覧から抜けています。collector単体の`discard()`テストでは、middlewareが実際に例外を捕捉してdiscardすることを証明できません。

修正案として、実routeまたはmiddleware統合テストで次を固定してください。

1. `PasskeyDeleted` listenerがcallbackを積む
2. その後の同期処理が例外を投げる
3. passkey削除がrollbackされる
4. 通知jobが0件
5. 同じrequest scopeで後からflushしても通知されない

[Warning] `Notification::fake()`と「jobsテーブル件数」は同じテストでは両立しません。Notification fakeは通常、実際のqueued notification dispatchを止めます。

修正案:

- イベント→enum対応: `Notification::fake()`
- queue投入確認: `Queue::fake()`で`SendQueuedNotifications`を検査
- jobsテーブルを使う場合: database queueを局所設定し、`Notification::fake()`を使わない

というレーン分離を明記してください。単なる`jobs`総件数ではなく、対象Notificationを含むjobであることも確認すべきです。

[Warning] 「enqueue失敗時に認証操作が成功する」という要求に対し、Notifier単体で「例外が伝播しない」だけを確認するのは不足します。

修正案:

- 単体テストの主張を「Notifierが例外を吸収する」に狭める、または
- 実際のパスワード変更等のFeatureテストでdispatcherを例外化し、DB変更と正常応答を確認する

[Warning] 変更ファイル一覧にはFeatureテストしか載っていませんが、本文ではenum・ShouldQueue・collectorのUnitテストを新設するとしています。波及ファイル一覧へUnitテストファイルを追加してください。

[Warning] テストファーストの赤確認手順がありません。最低限、次の失敗を先に確認する計画を加えてください。

- 各実経路で通知が0件の失敗
- rollback後にcallbackが残る失敗
- 新しいqueued notificationが2つのdeny-by-default gateで未登録になる失敗

以上を修正すれば、collector方式を維持したまま実装へ進められます。