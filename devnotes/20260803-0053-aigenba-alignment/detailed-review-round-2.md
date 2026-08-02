## 全体判定

**CHANGES_REQUESTED**

Round 1 の主要問題はかなり解消されています。ただし、施策 6 の `sessionStorage` フォールバックに新しい誤動作経路があり、セキュリティ機能としては未確定です。あわせて、施策 2・4・8 に設計上の修正が必要です。

---

## 施策別判定

### 施策 1: route binding 型制約

**APPROVE**

`[0-9]{1,18}` は、現在の目的である 22P02 / 22003 の防止として成立します。11 個の binder を追加しない判断も妥当です。

- [Warning] DB の `bigint` が許容する一部の19桁IDを意図的に排除するため、「URL形状・適合値は不変」という波及評価は正しくない。  
  修正案: 「AI-CUE の route key は最大18桁」というドメイン制約を明記し、Architecture テストで `BIGINT_PATTERN` を固定する。
- [Warning] ケース4の「18桁値が route にマッチした」は、最終結果が404だけでは証明できない。  
  修正案: regex 単体テストで18桁成功・19桁失敗を直接検証する。Feature テストは「DB例外にならない」確認に分離する。
- [Suggestion] 実行環境が64bit PHPである前提も明記すると、`PHP_INT_MAX` の説明が閉じる。

### 施策 2: route binding inventory gate

**REQUEST_CHANGES**

- [Warning] `NormalizesRouteBindingInput` が空の marker interface のため、入力正規化を何も保証していない。空実装でも IV-5 を通過する。  
  修正案: marker interface は分類用途と明記し、実際の保証は custom binder ごとの Feature テストへ移す。少なくとも `{organization}` に非数値、19桁、30桁、未許可 field を渡して404になることを inventory 付きで固定する。
- [Warning] 実装スケッチが「IV-1〜IV-6」のままで IV-7 が抜けている。  
  修正案: コメント、テスト一覧、負のコントロールを IV-1〜IV-7 に統一する。
- [Suggestion] 「登録元判定」の具体的な取得方法を実装前に確定する。Laravel Route は通常、route file の出自を直接保持しないため、action metadata や明示的な route group marker が必要になり得る。

### 施策 3: 非適合セグメント実挙動テスト

**APPROVE**

- [Warning] 「適合値なら404以外」は、対象モデルや親子関係の fixture が不完全だと認可・nested binding に吸われる。  
  修正案: 対比ケースは実在する親子関係を Factory で構築し、期待ステータスを具体値で固定する。
- [Suggestion] PostgreSQL 固有事故なので、SQLite 等へ切り替わった場合に空振りしないこともテスト環境契約として確認する。

### 施策 4: no-store middleware

**REQUEST_CHANGES**

- [Warning] コメント内で「本 middleware は最内側」と「外側の本 middleware」が混在している。web group 内では末尾でも、route middleware を含む全体では外側になり得る。  
  修正案: 位置関係の説明を削り、「`$next` 後に得た最終応答を確認し、既存 `no-store` を維持する」というコード上の契約だけを書く。
- [Suggestion] middleware 順そのものではなく、「内側が設定した `no-store` 完全値を変更しない」Feature テストを正本にする。

### 施策 5: no-store 契約テスト

**APPROVE**

- [Suggestion] 完全一致と集合比較のどちらが失敗したか、メッセージを分けると変更理由を判断しやすい。

### 施策 6: bfcache 秘匿・再検証

**REQUEST_CHANGES**

- [Critical] `sessionStorage` はタブ単位で共有されるため、履歴エントリ単位の復元判定には使えない。ページAの `pagehide` が立てたフラグを、通常遷移先ページBが読み、誤って秘匿・プローブする可能性がある。  
  修正案: `sessionStorage` を廃止し、bfcache snapshot に含まれる `documentElement` の秘匿属性そのものを復元マーカーにする。履歴単位の補助識別が必要なら、既存 `history.state` を保持したまま名前空間付きIDを追加する。
- [Critical] プローブの HTTP キャッシュ契約が応答側だけで、クライアント要求側が未定義。古い `authenticated:true` を再利用してはならない。  
  修正案: `fetch('/session/status', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })` を契約として明記し、Resource 応答にも `no-store, private` を必ず付与する。
- [Warning] `JsonResource` は設定によって `{data:{authenticated:true}}` に wrap される。設計記載の応答形と一致する保証がない。  
  修正案: プロジェクトの `withoutWrapping()` 契約を確認し、実際の JSON shape を TypeScript 型・Feature テストで完全一致固定する。
- [Warning] プローブ失敗時の「再試行 or login」が未決定で状態遷移が一意でない。  
  修正案: 例えば「初回失敗は秘匿維持＋再試行ボタン、ユーザー操作時は現在URLを hard reload」のように一意化する。自動無限再試行は避ける。
- [Warning] login 遷移先を固定しないと intended URL や open redirect の扱いが曖昧。  
  修正案: サーバ生成または固定相対パスの login URLだけを使用し、任意URLを受け取らない。

### 施策 7: ブラウザ方針

**APPROVE**

Current / Target の分離で保証範囲が明確になりました。

- [Suggestion] Current の実機確認は「一度きり」ではなく、bfcache guard 変更時の再確認条件も記載すると運用しやすい。

### 施策 8: Browser E2E

**REQUEST_CHANGES**

- [Critical] WebKit を「第一候補」、実機確認を代替可能にすると、恒久的な自動回帰なしでセキュリティ不変条件を完了扱いできる。  
  修正案: WebKit レーンを実装完了条件にする。iOS実機確認は WebKit の代替ではなく、PWA standalone 差異を確認する補完条件にする。
- [Warning] 「WebKitなら再現できる見込み」段階であり、bfcache 復元が実際に成立する成功条件がない。  
  修正案: `pageshow.persisted === true` を観測できた場合のみシナリオ2・4を有効とし、観測できなければテストを失敗させる負のコントロールを明記する。
- [Warning] fail-first のシナリオ4は Chromium では施策4適用後に再現不能になる。  
  修正案: fail-first は WebKit レーンで確認するか、guard の vitest で「秘匿なしなら旧DOMが可視」の負のコントロールを先行させる。

### 施策 9〜14

**すべて APPROVE**

施策9の見送り理由も妥当です。F-5 として双方向整列へ回すことで、局所的な乖離を増やしていません。

---

修正必須点は、施策2の空interfaceの保証整理、施策4の順序説明、施策6の `sessionStorage` 廃止とキャッシュ契約、施策8の WebKit 恒久レーン化です。