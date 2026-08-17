## Round 2: Round 1 の指摘への対応 (Critical 1 / Warning 2 / Suggestion 1 をすべて反映)

### [Critical] 施策 6: `new_api_key` の検証が偽陽性になる → 観測点ごと書き直した

指摘は正しく、設計の中心にある主張を空振りさせるものだった。着地画面はそもそも
`new_api_key` を prop に出さないので「着地画面に無い」では `reflash()` のまま緑になる。
`withSession([...])` が一時メッセージの世代情報を作らない点もそのとおり。

テスト計画を次のように固定した。

> **観測点の置き方**
> 「着地画面の共有 prop に `new_api_key` が無いこと」を見るテストは意味がない
> (着地画面はもともとその prop を公開しないので偽陽性になる)。見るべきは
> **跳ね返り応答の直後の session** である。また `withSession([...])` は値を置くだけで
> **一時メッセージの世代情報を作らない**ため、`keep()` / `reflash()` / 要求終了時の失効を
> 正しく再現できない。**必ず本物の要求境界を跨いで一時メッセージを作る**。

- 新規 `tests/Feature/Inertia/FlashNotificationRelayBounceTest.php`。
  2 つの middleware の**それぞれ**について次の順で 1 本の流れを固定する:
  1. テスト専用の web route で `redirect(<跳ね返る先>)->with('success', …)` を実行し、
     同じ要求で `session()->flash('new_api_key', […])` も積む
  2. 次の要求で対象 middleware の跳ね返りを発生させる
  3. **跳ね返り応答の直後の session** を直接 assert する —
     `success` は残っている / **`new_api_key` は無い**
     (この 1 行が `reflash()` では確実に赤くなり、`relayTo()` でだけ緑になる)
  4. 着地の GET で `flash.success` が Inertia 共有 prop に載る
  5. 着地 GET の**後**にもう 1 度読むと `success` が失効している (延命は 1 hop だけ)。
     このとき**再び中継を通る route を使わない**
- リスク節にも「実装前に `reflash()` のままで新テストを走らせ、`new_api_key` の行が
  赤になることを目で確認してから置き換える」を追加した。

### [Warning] 施策 3: `RELAYABLE_ERROR_KEYS = []` の fail-closed 契約がテスト計画に無い → 4 件足した

施策 6 のテスト計画に置いた (クラス単体ではなく実経路で見るため)。

- 検証エラー (`errors` の default bag) を積んで跳ね返りを通し、跳ね返り直後の session に
  `errors` が**残っていない**
- 名前付き bag (default 以外) も**残っていない**
- `errors` に `ViewErrorBag` でない値が入っていても再 flash されない (置き直しをしない)
- 将来 `RELAYABLE_ERROR_KEYS` へキーを足すときは、**同じ変更で**
  「許可キーだけ残る (正例) / それ以外と名前付き bag は残らない (負例)」を足すことを
  テストの docblock に契約として書く

施策 3 のテスト計画からもこの節を参照するようにした。

### [Warning] 施策 6: 通知の延命検査が `success` だけ → 代表値 + 保証範囲の明記を選んだ

4 キーすべてを `keep()` することは `NOTIFICATION_KEYS` を回す実装で一意に決まるため、
2 middleware × 4 キーへ広げるより、代表値 1 つと保証範囲の明記の方が読み手に正確だと判断した。
テスト計画に「代表値として `success` を検査し、4 キー全部が対象であることは定数を回す実装で
一意に決まる旨を docblock へ書く」と明記した (dataset で回してもよいと併記)。

### [Suggestion] 施策 1: 「`app/` の flash 書き手をすべて検査」は広すぎる → 文言を直した

検査表を「**走査器が拾えるリテラル書き手**のキーがすべて…」に直し、直後に母集団の但し書きを置いた。

> **母集団の言い方を広げない**: 2 番が見るのは「`app/` の flash 書き手すべて」ではなく
> 「**走査器が拾えるリテラル書き手**」である。動的キー (`BillingFeedbackKind::FLASH_KEY` /
> 変数経由) と `[a-z_]+` 以外のキー (camelCase) は母集団に入らない。
> gate の docblock にもこの言い方で書く。

---

以上で全体判定 APPROVED にできるか答えてほしい。残る [Critical] / [Warning] があれば
修正案付きで指摘すること。
