# 対応マトリクス: design-review Round 3

## 施策1-4: APPROVE (対応不要)

## 施策5 (REQUEST_CHANGES)

### [Warning] canonicalize が null、pendingIntent も null のとき `null === null` で誤許可
- 判断: 対応する
- 対応: 許可判定を `visitKey !== null && pendingIntent !== null && visitKey === pendingIntent` に明記。
  intent 生成側 (visitExplicitly) も canonicalize が null なら intent を立てず visit しない。
  テスト追加: malformed + pendingIntent null は拒否 / intent 生成 null で許可しない / null===null を使わない。

### [Warning] canonical キーが hash を除外し「URL 完全一致」契約と不一致
- 判断: 対応する
- 対応: canonicalize に `${url.hash}` を含め「完全一致」契約に合わせる。search/hash 違いを別遷移として
  区別するテストを追加。

### [Suggestion] docblock の popstace → popstate
- 判断: 対応する (誤字修正)
