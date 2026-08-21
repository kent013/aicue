## Round 4: 施策5 の Round 3 Warning に対応しました

施策1-4 は APPROVE をいただきました。施策5 の 2 つの Warning + Suggestion に対応しました。改訂後の helper と該当テスト計画を提示します。全体判定をお願いします。

### 改訂後 canonicalize + 許可判定 + テスト
```ts
/** url+method の canonical キー (完全一致契約: origin+pathname+search+hash)。
 *  **例外を外へ漏らさず失敗は null で返す** (before handler が止まらない) */
export function canonicalize(value: string | URL, method: string): string | null {
    try {
        const url = new URL(value, window.location.href);
        // 「URL 完全一致」契約に合わせ hash まで含める (Codex Round3 [Warning])
        return `${method.toLowerCase()} ${url.origin}${url.pathname}${url.search}${url.hash}`;
    } catch {
        return null;
    }
}

// before handler の許可判定 (★ null === null を許可に使わない。Codex Round3 [Warning]):
//   const visitKey = canonicalize(visit.url, visit.method);
//   const explicitlyAllowed =
//       visitKey !== null && pendingIntent !== null && visitKey === pendingIntent;
// intent 生成側 (visitExplicitly) も canonicalize が null なら intent を立てず visit しない。
```

### navigation-guard テスト計画 (追加分)
```
- [ ] `canonicalize 失敗 (null) を許可判定に使わない` (Codex Round3 [Warning]) —
  malformed URL かつ `pendingIntent === null` は拒否 / intent 生成側の canonicalize が null なら
  intent を立てず visit を許可しない / `null === null` を許可に使わない。
- [ ] `canonical キーは hash まで含む` — search/hash 違いを別遷移として区別する (完全一致契約)。
```

docblock の popstace は popstate へ修正済み。

---

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
