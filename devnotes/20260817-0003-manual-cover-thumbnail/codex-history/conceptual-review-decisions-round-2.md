# 対応マトリクス: conceptual-review Round 2

## [Critical] 3. `readyTakeId()` が行ごとの追加クエリを起こさない保証が設計に無い
- 判断: **対応する (方式 = 提案 1「eager load 済みモデルだけを使い問い合わせない」)**
- 根拠: `AdoptedReadyTakeCoverage::readyTakeId()` の実体は `$cut->adoptedTake` の
  **プロパティ読み出し 1 つ**であり、DB 問い合わせを書いていない。relation が eager load 済みなら
  クエリは 0 本になる。この前提は canonical 自身の docblock が既に明文化している —
  「前提 ($cut の adoptedTake の鮮度。3 段で読むこと): 1. **一覧の直列化では eager load 必須**
  (`with('adoptedTake')`)。無いと N+1 になる」。
  よって新しい一括判定 API (提案 3) を足す必要はなく、**足すと状態述語の入口が 2 つになる**
  (規約 12 が閉じた「1 ファイル 1 述語」を弱める)。map を controller で組む案 (提案 2) も、
  eager load で 0 クエリになる以上は中間層を増やすだけである (思考原則 2)。
- 対応内容: D1-1 に「(b) の呼び出しは **eager load 済みの relation だけを読む** =
  DB 問い合わせを 1 本も出さない。eager load を張ることが (b) を使う側の義務であり、
  canonical の docblock の前提 1 と同じ契約である」を明記した。
  クエリ数テストの条件も「0 件・1 件・複数件で追加クエリ数が一定」に具体化した。

## [Warning] 2. D4 の「双方向 parity」が endpoint の意味と一致しない
- 判断: **対応する**
- 根拠: 指摘のとおり。`capture.takes.thumbnail` は「代表かどうか」を判定しない
  (代表でない take でも条件を満たせば 302 を返す)。また `cover === null` のときは
  叩く URL 自体が props に無いため、逆方向を HTTP で評価できない。
  双条件として書いたのは誤りだった。
- 対応内容: D4 の契約を独立した 3 本に分割した。
  (i) **配信可能性**: `cover !== null` なら、その id から組んだ URL は同一利用者に対して 302。
  (ii) **代表選択の完全性**: 「capture 権限あり ∧ D1 の候補あり ∧ canonical の ready 判定成立」が
  すべて成り立つときちょうど `cover !== null`、いずれか不成立なら `cover === null`。
  (iii) **認可委譲の drift 検出**: 代表の有無とは独立に、`TakePolicy::preview` の判定が
  `ProjectPolicy::capture` と同値であることを固定する (props 側が `capture` で
  代理判定していることの前提)。

## [Warning] 5. `loading="lazy"` を「実質的な上限装置」と書くのは強すぎる
- 判断: **対応する**
- 根拠: lazy loading は取得抑制のヒントであり、取得枚数の上限を保証しない
  (viewport 近傍の先読み・実装差がある)。保証範囲を誇張しない (AGENTS.md の作法)。
- 対応内容: D6 を「初期表示時の取得を抑制するが、取得枚数の上限は保証しない」に書き換えた。
  ページネーションをスコープ外にする判断は維持。

## [Suggestion] 7. TS 側の shape をテストで固定する
- 判断: **対応する**
- 対応内容: 既存の「summary shape は TS `CaptureManualSummary` と対のキー集合」テストに
  `cover` を足し、`cover` 非 null 時の内側キー (`cut_id` / `take_id`) と int 型も固定する旨を
  テストの軸へ追記した。

## [Suggestion] 4. 代表画像が識別に適したカットとは限らない
- 判断: **見送る (現行表現のまま)**
- 根拠: Codex 自身が「現状の表現なら許容範囲」と述べており、期待効果は既に
  「保証」ではなく「向上」として書いてある。過剰な留保を足すと読みにくくなる。

## [Suggestion] 1 / 6 (使命整合・スコープ)
- 判断: 指摘なし (肯定的評価)。変更しない。
