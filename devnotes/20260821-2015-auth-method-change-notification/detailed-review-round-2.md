## 全体判定: CHANGES_REQUESTED

Round 1のCriticalは解消されています。ほとんどのWarningも適切に対応されていますが、今回追加したcollectorの状態管理に1点未確定事項があります。

### 施策別判定

| 施策 | 判定 |
|---|---|
| 1. enum・Notification・Notifier | APPROVE |
| 2. commit後発火機構 | REQUEST_CHANGES |
| 3. listener・DI | APPROVE（施策2修正前提） |
| 4. パスワード・SSO配線 | APPROVE |
| 5. deny-by-default目録 | APPROVE |
| 6. テンプレート差分 | APPROVE |
| 7. 運用ドキュメント | APPROVE |
| 8. テスト | REQUEST_CHANGES |

## 残る指摘

[Warning] `start()` の二重呼び出し時の契約とテストが不足しています。

今回の修正では非アクティブ時の`push()`はfail-fastになりましたが、既にアクティブな状態で`start()`が再度呼ばれた場合の挙動が明確になっていません。

仮に次のような実装だと、積まれていたcallbackを無言で消します。

```php
public function start(): void
{
    $this->callbacks = [];
    $this->active = true;
}
```

これは、nested middlewareや同一request scope内の誤った再利用が起きたとき、検出すべき通知欠落を正常系に見せてしまいます。

修正案:

```php
public function start(): void
{
    if ($this->active) {
        throw new LogicException(
            'LoginMethodRemovalPostCommitCallbacks::start() called while already active.',
        );
    }

    $this->callbacks = [];
    $this->active = true;
}
```

状態遷移を次のように固定してください。

| 現在状態 | 操作 | 結果 |
|---|---|---|
| inactive | `start()` | active |
| active | `push()` | activeのまま追加 |
| active | `flush()` | 実行してinactive |
| active | `discard()` | 破棄してinactive |
| inactive | `push()` | `LogicException` |
| active | `start()` | `LogicException` |
| inactive | `flush()` / `discard()` | no-op |

追加すべきUnitテスト:

- active中の二重`start()`が例外になる
- 二重`start()`失敗後も、先に積んだcallbackが失われていない
- `flush()`後に再度`start()`できる
- `discard()`後に再度`start()`できる
- inactive状態の`flush()`がno-opで、既存の二重flush契約と整合する

これを施策2のコードと施策8のテスト計画へ加えれば、Round 1の全Critical/Warningは解消と判断できます。

なお、コマンド実行禁止に従ったため、今回は提示された対応マトリクスと前回提示された設計全文との差分照合です。非コマンドのローカルテキスト読取手段が利用できなかったため、ファイル実体とマトリクスの一致までは検証していません。