## Round 2 レビュー

### 施策 1: パスキー設定ブロックの明示

**判定: REQUEST_CHANGES**

[Warning] `mergeConfigFrom` と `config:cache` の契約テストが施策一覧にはありますが、具体的なテストケースが設計本文にありません。

この施策の中心的な前提は「アプリ側で指定していない vendor 既定キーが残ること」です。版 pin だけでは、同じ 0.2 系の patch 変更や Laravel 側の挙動変更を検出できません。

修正案:

- `passkeys` 設定を `var_export()` → `eval()` で往復させ、次を検査する
- アプリ側キー:
  - `relying_party_id`
  - `allowed_origins`
  - `raw_allowed_origins`
  - `user_handle_secret_declared`
- vendor 既定キー:
  - `timeout`
  - `guard`
  - `middleware`
  - `management_middleware`
  - `throttle`
  - `redirect`

特に「vendor 既定キーが残る」テストは、この設計で明示された不変条件なので省略できません。

[Warning] 「既存環境では現行 `APP_KEY` の値をそのまま宣言すれば維持できる」という契約と、`PASSKEYS_USER_HANDLE_SECRET` に対する `trim()` が厳密には一致しません。

通常の Laravel `APP_KEY` に空白はありませんが、設計上は任意の文字列です。既存の HMAC 鍵に先頭・末尾空白が含まれていた場合、コピーしても `trim()` により別の鍵になります。

修正案は次のどちらかです。

- 移行互換性を優先し、secret の値自体は `trim()` せず、空白のみかどうかだけ `trim($value) !== ''` で判定する
- 空白を含む鍵は非対応と明記し、「そのまま」という表現を限定する

前者が自然です。

---

### 施策 2: 設定事故ガード

**判定: APPROVE**

#### Public suffix

PSL 依存を追加せず、保証範囲を明示する判断に同意できます。

想定される直接的な被害は、運用者が `co.uk` などを RP ID に指定し、production 起動は成功するもののブラウザが WebAuthn ceremony を拒否して、パスキーの登録・認証が全面的に利用不能になることです。設定値が信頼できる運用入力である限り、提示された構成から権限昇格や別組織の資格情報受入れにつながる具体的経路は見当たりません。

したがってこれはセキュリティ境界の欠落というより、fail-fast の検出範囲に残る既知の可用性リスクです。依存追加コストとの比較も妥当です。

ただし、例外メッセージの次の表現は実装と矛盾します。

```text
is not a registrable domain name
```

`co.uk` を通す以上、registrable であることは検査していません。

[Warning] エラーメッセージが実際の保証を過大に表現しています。

修正案:

```text
is not an accepted dotted DNS name
```

または:

```text
is not a valid production DNS name
```

コメントの「登録可能なドメイン名」も「production で許可する dotted DNS 名」へ変更してください。

「既知の限界として `co.uk` が通る」テストは設計判断の可視化として成立します。ただし、将来の防御強化を回帰として扱うテストになるため、テスト名に `documented limitation` を明記する現在案が重要です。

#### 末尾ラベルの英字検査

現在一般に利用される公開 DNS 名、IDNA A-label、`.onion` などを誤って落とすケースは見当たりません。Punycode は `xn--` を含むため通ります。

ただし、DNS の構文そのものは末尾ラベルが数字だけであることを全面的には禁止していません。そのため、これは純粋な `isDnsName()` 判定ではなく「本番 WebAuthn 用に採用する DNS 名」のポリシーです。将来の数字 TLDや内部 DNS 名を偽陽性にする可能性は理論上あります。

[Suggestion] メソッド名を維持するなら docblock に「一般的な DNS 構文検証より厳しい production policy」と明記してください。現行の本番用途では妥当です。

#### `isStringList()`

追加された helper は passkeys 分岐内でしか使われないため、既存の trusted hosts/proxies を含む13項目の挙動には影響しません。空配列は `list<string>` として通り、その後 validator が適切な violation にするため、空値も取り逃しません。

`@phpstan-assert-if-true list<string> $value` と `array_is_list()` の組み合わせも PHPStan level 10 に適合する設計です。

既存テストへの意図的な影響は、passkeys が有効な baseline に新しい violation が加わる点だけです。提示された5キーを `beforeEach` に設定すれば既存の件数アサートは維持できます。

---

### 施策 3: `.env.example` への提示

**判定: APPROVE**

空欄が production 用の有効値ではないことが明確になりました。Architecture テストも既存の形式に整合しています。

[Suggestion] テストは単なる部分一致なので、コメント行の `# PASSKEYS_USER_HANDLE_SECRET=` でも通ります。宣言行を固定する意図なら、行単位の正規表現でコメントアウトされていないことを検査するとより正確です。

```php
expect($contents)->toMatch('/^PASSKEYS_USER_HANDLE_SECRET=/m');
```

---

### 施策 4: `laravel/passkeys` の版 pin

**判定: REQUEST_CHANGES**

[Warning] `str_starts_with($constraint, '^0.2')` は設計で許可していない制約も通します。

例えば以下が通過します。

```text
^0.20
^0.2 || ^1.0
^0.2.1 || ^0.3
^0.2@dev
```

特に `^0.2.1 || ^0.3` は、0.3 系への更新を防ぐというテストの目的を破ります。

修正案として、依存を追加せず完全一致の正規表現で固定できます。

```php
expect(preg_match('/^\^0\.2(?:\.\d+)?$/', $constraint))->toBe(1);
```

許容範囲を本当に `^0.2` と `^0.2.1` の2種類だけにするなら、より明確に次で十分です。

```php
expect($constraint)->toBeIn(['^0.2', '^0.2.1']);
```

将来の任意 patch 起点を許すなら前者が適切です。

composer.json と composer.lock の両方を検査する方針、および patch 完全固定を避ける判断自体は妥当です。

---

### 施策 5: 運用契約の記述

**判定: APPROVE**

PSL未検査、DNS名限定、起動前に必要な移行作業、キルスイッチとの関係が明確になっています。

施策1の指摘に合わせて、`APP_KEY` を「そのまま」移行できる条件と secret の `trim()` の扱いだけ同期してください。

## 全体判定

**CHANGES_REQUESTED**

PSL依存を追加しない判断には同意します。残る変更要求は次の3点です。

1. `mergeConfigFrom` と config cache 往復後の vendor 既定キー残存テストを具体化する
2. user handle secret の `trim()` と「現行 `APP_KEY` をそのまま移行」の契約を一致させる
3. composer 制約検査を前方一致ではなく、許容する caret 表現との完全一致にする

いずれも設計方針を変更するものではなく、契約と実装・テストを一致させる修正です。