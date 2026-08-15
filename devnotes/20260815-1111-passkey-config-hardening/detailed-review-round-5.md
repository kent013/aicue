## Round 5 レビュー

実装ロジック上のCriticalはありません。大文字origin/RP IDの扱い、Fortify写像、config cache、版pin、production guardの責務分担は整合しています。

ただし、テスト計画に1件だけ記載漏れが残っています。

### 施策1: passkeys設定ブロック

**判定: APPROVE**

config側でscheme・hostを小文字化し、Fortifyの拡張点である `fortify.passkeys.*` へ宣言する設計は妥当です。

sentinelを使うReflection契約テストも、fallbackへの退行を検出できます。`register()` 全体を再実行せず `configurePasskeys()` に限定する判断も適切です。

[Suggestion] 波及変更欄にはまだ「vendor既定キーの残存」とあります。後段と統一して「Fortify結線後の実効キー」に直すと、`management_middleware` と `throttle` の供給元を誤解させません。

### 施策2: 設定事故ガード

**判定: REQUEST_CHANGES**

[Warning] 対応マトリクスではRP IDの負のdatasetに `APP.example.com` を追加したとありますが、全文のUnitテスト計画に含まれていません。

現在列挙されているRP IDの負値は次までです。

```text
localhost
192.0.2.1
192.168.001.001
-example.com
example-.com
example..com
.example.com
example.com.
exam ple.com
2001:db8::1
```

大文字originの `https://APP.example.com` は別のdatasetにありますが、これはorigin正規表現の検査であり、RP ID側の `isDnsName()` が小文字限定であることを固定しません。

修正案として、RP IDの負のdatasetへ明示的に追加してください。

```text
APP.example.com
```

これにより、次の両契約を別々に固定できます。

- env由来のRP IDはconfig側で小文字化される
- 別経路から未正規化のRP IDが入った場合はvalidatorが拒否する

validator本体とProductionEnvGuardの設計には、それ以外の問題はありません。

### 施策3: `.env.example`

**判定: APPROVE**

必須キーをコメントではなく宣言行として固定できています。移行時の鍵維持手順とも整合します。

### 施策4: `laravel/passkeys` 版pin

**判定: APPROVE**

composer.jsonの許容制約とlockの実解決値を正しく分離して検査しています。Fortifyをpin対象に含めない担当範囲も明確です。

### 施策5: 運用契約

**判定: APPROVE**

宣言場所、破壊的変更、PSL未検査、FortifyとPasskeysの保証方法が正確に記述されています。

## 全体判定

**CHANGES_REQUESTED**

残るWarningは、RP ID側の大文字入力 `APP.example.com` をUnitテストの負のdatasetへ明記する1点だけです。実装設計そのものには残存するCritical/Warningはありません。