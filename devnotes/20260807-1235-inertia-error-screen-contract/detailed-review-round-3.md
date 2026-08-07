## 再レビュー結果

### S1: APPROVE

Round 2から追加指摘はありません。401除外と履歴復元契約の境界も明確です。

### S2: APPROVE

4利用点の列挙に修正され、API本文・APIヘッダ・Inertia props・InertiaヘッダのSoTが一致しました。

### S3: APPROVE

非空、同一オリジン、named route、key重複禁止まで契約化されています。追加指摘はありません。

### S4: REQUEST_CHANGES

[Warning] キャッシュ表現の分岐に対して`Vary: Accept`が不足しています。

この設計では同じURLの例外応答が、少なくとも次の入力で変化します。

- `X-Inertia`によってBladeとInertia pageが分岐
- `Accept: application/json`によってJSONと画面応答が分岐

現在追加するのは`Vary: X-Inertia`だけです。特に未認証画面では、名称上`NoStoreCacheHeadersForAuthenticatedPages`の対象外となる可能性があり、共有キャッシュが先に受け取ったHTMLまたはJSONを別のクライアントへ返す余地が残ります。

修正案:

- まず既存middlewareが未認証の4xx/5xxにも`no-store`を付けるか、Laravel既定応答が`Vary: Accept`を付けるかを実装時に確認する。
- `no-store`で閉じていない場合、最終callbackで表現が`Accept`に依存する応答へ`Vary: Accept`を追加する。
- Featureテストで、Inertia Error応答の`Vary`に`X-Inertia`と`Accept`が両方含まれることを固定する。
- JSON側も同じURLでキャッシュされ得るため、Inertia応答だけへの追加で契約済みとしない。

[Suggestion] 419の基本テストだけ、まだ`destinations.0.href === '/login'`というliteral期待が残っています。S3と同様に`route('login', absolute: false)`へ統一してください。

短絡評価と`report()`の契約化自体は適切に解消されています。

### S5: APPROVE

eager/lazy境界、非空tuple、native navigationの設計とテストが整合しています。

### S6: APPROVE

`PhpToken`化、コメント除外、3件の正負コントロール、保証範囲の記述はいずれも整合しています。

[Suggestion] mutation説明の「残るM4〜M12」は、追加後の表に合わせて「M4〜M15」へ更新してください。

## 全体判定

**CHANGES_REQUESTED**

実装上の残件は、S4のキャッシュ表現契約だけです。`Accept`による応答差を既存の`no-store`または`Vary: Accept`のどちらが閉じているか確認し、その保証をテストへ登録できれば、設計全体は承認可能です。