# 対応マトリクス: design-review Round 3

Critical はゼロ。Warning 2 件 + Suggestion 1 件、すべて対応した（反論なし）。

## [Warning/A] 「component test が成立しなければ保証範囲を下方修正する」という逃げ道は不可
- 判断: **対応する**
- 根拠: 指摘のとおり。受入条件 4 は**録画中の UX 不変条件**であり、
  「検証できなかったので保証を下げて完了」は受入条件を満たしたことにならない。
  正直に書けば済む話ではなく、**テスト可能な境界まで設計を動かすべき**という指摘は正しい。
  また Codex の代案（副作用ではなく「helper に渡った引数」を見る）は、
  jsdom の矩形・`focus`・`scrollIntoView` の実装差に依存しないという点で明確に優れている。
- 対応内容: 受入条件 4 を **(i)(ii) 両方必須**に確定し、逃げ道の記述を削除した。
  (ii) の検証方法を「副作用の有無」から
  **`vi.mock("@/lib/capture/panel-navigation")` で module mock し、
  `navigateToPanelIfNeeded` の呼び出し引数の `captureActive` が `true` であることを assert する**
  に変更した（= 配線だけを直接固定。副作用の抑止は (i) が担う、と責務を分けた）。
  module mock が難しい場合の指示も「未固定で完了」ではなく
  **「渡す値を組み立てる部分をさらに純関数（例 `buildPanelNavigationInput`）へ抽出し、
  テスト可能な境界まで設計を動かす」**に書き換えた。

## [Warning/C] Standard カードの CTA 比較には既存のラベル変更が交絡する
- 判断: **対応する**
- 根拠: 指摘のとおり。Standard を押すと note の増加と CTA ラベル変更（「選択」→「選択中」）が
  同時に起きるため、CTA の `height` が変わっても原因を切り分けられない。
  交絡した量を不変条件にすると、テストが赤くなったとき何を直せばよいか分からない。
- 対応内容: 測定を **2 枚のカードで非対称**にした:
  - **Starter**（note 有→無、**CTA ラベル不変**）= `h3` / 価格 / CTA の相対 `top` **と** `height`。
    CTA が動かないので「`headerBadges` の有無」を**単独要因**で測れる = 主役の検査
  - **Standard**（note 無→有、CTA ラベル変化）= 相対 `top` **のみ**。**CTA の `height` は不変条件から外す**
  - 両カードで note の `sr-only` 状態を別途検査
  併せて「Standard の CTA `height` を検査してよいのは、`Button` atom の `SIZE_CLASSES` のような
  **固定寸法の契約が既にあるときだけ**。実装時に `Button.types.ts` を見て判断し、
  契約が無ければ検査しない」と条件を明記した。受入条件マップにも反映した。

## [Suggestion/C] `assertPathIs` は path しか見ないので query 消失も検査すべき
- 判断: **対応する**
- 対応内容: fixture の例に `->script('window.location.search') === ''` の併用を注記した。

## [Suggestion/A] smooth scroll 完了を待ってから受入条件 1・2 を評価すること
- 判断: **対応する**
- 根拠: `behavior: "smooth"` は非同期なので、クリック直後に測ると移動途中の座標を拾って flaky になる。
  指摘されるまで設計に無かった実害のある穴。
- 対応内容: 受入条件 1 に「**smooth scroll の完了を待ってから評価する**」を明記し、
  `window.scrollY` が 2 フレーム連続で変化しなくなるまで待つ（または対象が viewport 内に入るまで polling する）
  waiter を**テストヘルパとして 1 つ用意し、受入条件 1・2・6 で共有する**と定めた。

## 施策 B: APPROVE を受領
- 実 query（`kind = Preview` / `status = Succeeded` / `output_path IS NOT NULL` / 最新 ID）と
  実行中の preview 分岐のみが `playbackId` を更新する事実により、
  固定文言「プレビュー動画」の認定が成立したと確認された。追加対応なし。
