## 施策別判定

### 施策1: APPROVE

前回Suggestionへの対応で十分です。

### 施策2: APPROVE

成功時の画面遷移を前提とした状態管理で問題ありません。

### 施策3: APPROVE

変更一覧、日時型の絞り込み、SSRに関する契約が明確になりました。

### 施策4: APPROVE

母集団非空、共通emitter、禁止destinationの負のコントロールが揃い、空振り問題は解消されています。

### 施策5: REQUEST_CHANGES

ほぼ解消されていますが、nullableなcanonical keyに1点穴があります。

[Warning] `canonicalize()` が失敗時に`null`を返し、`pendingIntent`も未設定時に`null`なら、単純な一致判定で「解析不能URLとintentなし」が一致する危険があります。

修正案:

```ts
const visitKey = canonicalize(visit.url, visit.method);

const explicitlyAllowed =
    visitKey !== null &&
    pendingIntent !== null &&
    visitKey === pendingIntent;
```

次のテストも追加してください。

- malformed URLかつ`pendingIntent === null`は拒否
- intent生成側のcanonicalizeが`null`ならintentを設定せず、visitを許可しない
- `null === null`を許可判定に使わない

[Warning] canonical keyがhashを除外しており、「URL完全一致」という契約と一致していません。

修正案は次のいずれかです。

- 厳密な遷移先一致なら`${url.search}${url.hash}`まで含める
- fragmentを意図的に同一視するなら、「origin + pathname + search一致。hashは判定対象外」と契約を狭め、その理由とテストを追加する

[Suggestion] docblockの`popstace`は`popstate`へ修正してください。

## 全体判定

**CHANGES_REQUESTED**

施策1〜4は承認です。施策5で「canonicalize失敗の`null`を一致値として扱わない」ことを明記し、hashの契約を確定すれば、全体をAPPROVEDにできます。