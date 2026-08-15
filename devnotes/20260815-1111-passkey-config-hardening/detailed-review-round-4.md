## Round 4 レビュー

origin 正規化をconfig側の責務とし、validatorを正規化済み実効値の検査にする整理は妥当です。Reflectionで `configurePasskeys()` だけを再実行する範囲も適切です。

ただし、大文字hostの検査が設計どおり動かないため、1点は実装修正が必要です。

### 施策 1: passkeys設定ブロック

**判定: REQUEST_CHANGES**

[Warning] validatorは大文字schemeを拒否しますが、大文字hostは拒否しません。

現在の正規表現は大文字を許可しています。

```php
#^https://([A-Za-z0-9.-]+)(?::(\d{1,5}))?$
```

さらに取得後に小文字化するため、次の値は正常終了します。

```text
https://APP.example.com
```

```php
$host = strtolower($m[1]);
```

したがって、テスト計画の「`https://APP.example.com` をreject」は失敗します。また「validatorでは大文字をrejectし続ける」という契約にも一致しません。

修正案は、正規表現を小文字ASCIIだけに限定することです。

```php
if (preg_match('#^https://([a-z0-9.-]+)(?::(\d{1,5}))?$#', $origin, $m) !== 1) {
    // violation
}

$host = $m[1];
```

または書式検査前に明示的に検査できます。

```php
if ($origin !== strtolower($origin)) {
    throw new RuntimeException(/* normalized origin required */);
}
```

config側では大文字入力を正規化し、別経路で設定された未正規化の実効値はvalidatorが拒否する、という役割分担がこれで成立します。

[Suggestion] `raw_allowed_origins` の説明がまだ「trimのみ」になっていますが、実際にはtrimと小文字化を行っています。PHPDocの `$rawAllowedOrigins` とconfigコメントを次のように直すと正確です。

```text
フィルタ前の正規化済み接続元列（trim・小文字化済み、空要素保持）
```

ここでの `raw` は「envの完全な原文」ではなく「空要素を除去する前」という意味になります。

[Suggestion] リスク欄の次の表現は担当範囲が不正確です。

```text
施策4の版 pin と、PasskeyPackageContractTestで二重に守る
```

施策4でpinするのは `laravel/passkeys` だけであり、`configurePasskeys()` の写像を持つ `laravel/fortify` はpinしません。次の整理が正確です。

- `laravel/passkeys` の契約: 0.2系pin
- Fortifyの写像: 1.x semverとsentinel契約テスト

### Reflection契約テスト

Reflectionで `configurePasskeys()` だけを呼ぶ方法に、設計を阻害する副作用は見当たりません。メソッドは `passkeys.*` を再設定しますが、Response contractやcontainer bindingを再登録しないため、`register()` 全体の再実行より範囲が適切です。

Laravelの通常のテストライフサイクルではapplication/configはテストごとに再構築されるため、sentinelも後続テストへ残りません。

[Suggestion] `ReflectionMethod::setAccessible(true)` は現在のPHPでは非publicメソッド呼び出しに必須ではありませんが、使用しても機能上の問題はありません。vendor protected APIへの意図的な結合を示す目的なら現状でも許容できます。

### 施策 2: 設定事故ガード

**判定: REQUEST_CHANGES**

大文字hostをrejectする実装だけ修正が必要です。それ以外のvalidator、`isStringList()`、2系統のconfig読み出し、baselineおよび破損テストは承認できます。

### 施策 3: `.env.example`

**判定: APPROVE**

必須宣言、空欄時のfail-fast、既存環境の移行方法が明確で、行頭一致テストも適切です。

### 施策 4: 版pin

**判定: APPROVE**

composer.jsonの制約とlock解決値を別々に検査できています。完全一致の正規表現も目的に合っています。

### 施策 5: 運用契約

**判定: APPROVE**

FortifyとPasskeysの担当範囲、PSL未検査、破壊的な初回設定要件が明確です。施策1のリスク説明だけ、Fortifyはpin対象でないことに合わせて修正してください。

## 確認事項への回答

1. configで正規化し、validatorで未正規化値を拒否する構造自体に矛盾はありません。ただし現在のコードでは大文字hostを許可しているため、正規表現または明示検査の修正が必要です。

2. Reflectionによる対象メソッド限定の再実行は妥当です。想定外のcontainer再登録は発生せず、sentinelによってfallbackへの退行も正しく検出できます。

3. 残るWarningは、大文字hostをvalidatorが拒否しない点です。併せて `raw_allowed_origins` の説明と、Fortify写像に対する版pinの担当範囲を修正してください。

## 全体判定

**CHANGES_REQUESTED**

実質的な変更要求は、大文字hostを確実にrejectする1点です。これを直せば、設計全体は承認可能です。