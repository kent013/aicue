全体として Round 1 の主要指摘は適切に解消されています。ただし、施策 3 の保証範囲の表現と mutation D にまだ齟齬があります。

## 施策 1: REQUEST_CHANGES

[Warning] 「status でも認可でも出し分けない」「status にも認可にも依らず常に出す」は、認可を無視して表示するようにも読めます。

実際には `Capture/Show` 自体へ到達するまでに `Gate::authorize('view', $manual)` が成立しています。正確な契約は「`Capture/Show` の描画後は、追加の status・ability 条件でリンクを出し分けない」です。

修正案:

```svelte
<!-- Capture/Show 到達後は、追加の status / ability 条件で出し分けない。 -->
```

文書も以下のような表現が適切です。

> 復路は `Capture/Show` へ到達済みの利用者に対し、追加の status / ability 条件を設けず常に表示する。

アイコン、文言、DOM順、Atomic Design、DS tokenについては問題ありません。

## 施策 2: REQUEST_CHANGES

[Warning] mutation D は現在のテストでは赤くなりません。

`BookOpen` から `aria-hidden` を外しても、Lucide の `<svg>` にアクセシブルネームを構成する `title` 等がなければ、リンクの accessible name は通常「マニュアル詳細へ」のままです。さらに `/マニュアル詳細へ/` は部分一致なので、名前に別の文字列が加わっても通ります。

したがって、現在のテストが固定するのは「リンクのアクセシブルネームに指定文言が含まれること」であって、「アイコンが名前を汚さないこと」ではありません。

修正案は契約を分離することです。

```ts
const detail = screen.getByRole("link", {
    name: "マニュアル詳細へ",
    exact: true,
});

expect(detail.querySelector("svg")).toHaveAttribute("aria-hidden", "true");
```

そのうえで mutation D は「`aria-hidden` を外すと SVG 属性の assertion が落ちる」としてください。accessible name の mutation とするなら、アイコンに `<title>書籍</title>` 相当を加える mutation が必要です。

[Suggestion] `toBeTruthy()` より、ビット契約を明示すると失敗時の意図が読みやすくなります。

```ts
expect(
    back.compareDocumentPosition(detail) &
        Node.DOCUMENT_POSITION_FOLLOWING,
).toBe(Node.DOCUMENT_POSITION_FOLLOWING);
```

status dataset の反論は妥当です。`as const satisfies readonly VideoManualStatus[]` より、全数性を持つ `Record<VideoManualStatus, ...>` のキーを採る方が強い設計です。

ただし、保証の連鎖には次の前提があります。

- `VIDEO_MANUAL_STATUS_LABELS` が実際に object literal に対して `satisfies Record<VideoManualStatus, string>` されている
- `VideoManualStatus` と PHP の `VideoManualStatus` enum の同期が別のテストまたは生成機構で保証されている

前者は提示内容で満たしています。後者が未保証なら、フロント内部では全数でも、PHP enumとのドリフトは検出できません。既存の型同期テストがあるなら追加対応は不要です。

mutation A、B、C は想定どおりです。

- A: 3本目の非 navigable status ケースだけが落ちる
- B: href を検査する1本目だけが落ちる
- C: DOM順を検査する2本目だけが落ちる
- D: 現状は落ちないため修正が必要

## 施策 3: REQUEST_CHANGES

Critical 指摘への `assertInertia()->component(...)` 対応は十分です。302だけでなく「200の別画面」も検出できます。

[Warning] ただし、このFeatureテストが証明するのは「到達条件が同じ」ことではなく、次の限定された含意です。

> 用意した project_member principal とFactoryデータについて、全statusで撮影画面と詳細画面の両方へ到達できる。

例えば将来、PC側だけにmanualの別属性、organization設定、ユーザー属性を使った制限が追加されても、現在のFactory値で制限に掛からなければテストは緑のままです。また、撮影側だけが厳しくなった場合も「条件が同一」ではなくなりますが、このテストでは検出できない場合があります。

修正案は、主張を実際の保証に合わせることです。

- Featureテスト名・説明を「最弱principalに対する復路到達契約」に変更する
- 「同一条件を機械保証」ではなく「現在サポートする最弱principalについて復路が詰まらないことを保証」と記載する
- middleware・認可構造そのものの同一性まで不変条件にするなら、route action/middlewareとcontrollerの認可方式を固定するArchitectureテストを別途追加する

この機能に必要なのは厳密な集合の同値ではなく、基本的に次の片方向です。

```text
capture.manuals.show に到達可能
    ⇒ projects.manuals.show に到達可能
```

そのため、設計全体を「同一性」ではなく「復路到達の含意」として記述する方が正確です。

mutation E は `ProjectRole::Member` が確実に `update` 不可なら成立します。mutation F も成立しますが、「別Inertia画面へ差し替える」だけでは変更方法が曖昧なので、実測手順では返すcomponent名を一時的に変更するなど、最小mutationを具体化すると再現性が上がります。

## 施策 4: REQUEST_CHANGES

施策1・3と同じく、「認可にも依らず」「同じ層を同じ順序で通るため403が構造的に起きない」は少し強すぎます。

修正案:

> `Capture/Show` 到達後は追加のstatus・ability条件で復路を出し分けない。現在サポートする最弱principalであるproject_memberについて、全statusで両画面へ到達できることをFeatureテストで固定する。

route/controllerの構造比較は設計根拠として残して構いませんが、Featureテストがその構造的同一性そのものを証明するとは書かない方が正確です。

Browser laneを追加しない判断は妥当です。実レイアウト、実タップ、standalone PWAを保証外として明示したため、手抜きではなくテスト境界の選択になっています。

## 全体判定: CHANGES_REQUESTED

残る必須修正は次の3点です。

- 「認可に依らず」を「到達後に追加のability条件で出し分けない」へ修正
- 「到達条件の同一性」を「最弱principalに対する復路到達の含意」へ狭める
- mutation Dを、実際に赤くなるSVGの`aria-hidden` assertionへ変更

これらを直せば、実装着手可能な設計です。