## 施策 0: APPROVE

新たな Critical / Warning はありません。既存挙動を固定した純粋な抽出として妥当です。

## 施策 A: REQUEST_CHANGES

[Warning] `navigateToPanelIfNeeded` の単体テストだけでは、受入条件 4 のページ配線まで保証できません。

副作用関数の粒度自体は適切です。受入条件 3 は Browser テストと vitest の組み合わせで十分守れます。一方、受入条件 4 は、将来 `Show.svelte` が誤って `captureActive: false` を渡しても、helper のテストは緑のままです。

修正案: `Show.svelte` の component test で、`captureActive=true` の状態からカットを選択し、見出しの `focus` と `scrollIntoView` が呼ばれないことを1本固定してください。CameraRecorder の実カメラ起動まで再現する必要はなく、子コンポーネントまたは active-change callback をテスト用に制御できれば足ります。component test が難しい場合は、「受入条件4を完全に検証」ではなく「helper の抑止契約を検証」と保証範囲を明記する必要があります。

[Warning] 「cuts 14件以上」は、テストが必ず失敗経路を通る保証になっていません。

行の高さ、文言の折り返し、mobile viewport の実寸が変われば、14件でも撮影パネルが初期 viewport 内に収まる可能性があります。

修正案: クリック前に次の前提を Browser テストで明示的に検査してください。

```js
rightPaneRect.top >= window.innerHeight
```

または、選択後かつナビゲーション実行前の対象見出しが viewport 外であることを、テスト用の安定したレイアウト条件で保証します。件数は「14件固定」ではなく「viewport 外になるためのデータ量」と位置づけるべきです。

[Suggestion] `navigateToPanelIfNeeded` の引数オブジェクトには名前付き型を付けると、テスト fixture と実装の契約が読みやすくなります。

## 施策 B: REQUEST_CHANGES

[Warning] 提示されたコードだけでは、`playbackId` が常に preview 由来という事実を完全には認定できません。

実行中の代入経路については確認できます。しかし初期値について提示されているのは Controller のコメントと代入キー名だけで、実際の query 条件が省略されています。コメントは不変条件の根拠にはなりません。

修正案: 設計書に少なくとも次の実コードを提示してください。

- render job の `kind` が preview に限定される条件
- `status === succeeded` の条件
- どの job を選ぶかの順序
- その query 結果が `playbackJobId` に代入される全体

これらが確認できれば、固定文言「プレビュー動画」は妥当です。実行中の preview/render 分岐については現在の認定で問題ありません。

## 施策 C: REQUEST_CHANGES

[Warning] note の存在と対象は青枠に一致していますが、初期文言とCTAの意味上の食い違いは残っています。

「初期選択されています」は依然として「選択済み」を表します。一方、CTAは「選択」であり、まだ選択操作が必要だと表します。基準を分離したことで状態遷移は整理されましたが、利用者に伝わる意味は一致していません。

修正案: 未押下時は選択済みを意味しない文言にしてください。

```text
Starter プランが初期候補として表示されています
```

または、URLパラメータによる明確な事前選択を製品上「選択済み」と扱うなら、CTA側も同じ状態定義に合わせる必要があります。現在のCTAを維持するなら、「初期候補」が最も誤認の少ない表現です。押下後の「選択中です」は妥当です。

[Warning] 受入条件11で異なるカード同士を比較する方法は、レイアウト不変性の検査として成立しません。

プランごとに名前、価格、機能数、CTA内容が異なるため、選択状態と無関係に高さや相対位置が違い得ます。また、gridのstretchによってカード全体の高さだけ一致し、内部の折り返しが隠れる可能性もあります。

修正案: 同じカードを状態変更の前後で測定してください。

1. 初期状態でStarterとStandardそれぞれの主要要素の矩形を保存
2. Standardを選択
3. StarterとStandardについて、同一カードの変更前後を比較
4. `h3`、価格、CTAのカード上端からの相対`top`と`height`を許容差1px以内で検査
5. note自体の視覚的非表示も別途検査

カード全体のheightは補助検査として残せますが、主要な不変条件にはしない方が堅実です。

[Warning] `contractPaidPlan()` を呼ばない方針は妥当ですが、提示された情報だけでは現在のfixtureが「未契約」を保証しません。

`createOrganizationWithOwner()` の既定値が `grandfatherFreePlan = true` です。この状態がCheckoutの表示・リダイレクト条件に影響しないことは、提示コードから確認できません。また「契約済みなら `/billing` へリダイレクト」も、AGENTS.mdのallowlist記述だけでは証明されません。

修正案: テストでは意図を明示して次のように生成し、Checkoutの前提条件を固定してください。

```php
[$organization, $owner] = createOrganizationWithOwner(
    grandfatherFreePlan: false,
);
```

加えて、Checkout Controllerまたはmiddlewareの実際のリダイレクト条件を確認し、「未契約でCheckoutを表示できる」ことを事前assertしてください。`PlanSeeder` についても「走るなら」ではなく、Browserレーンの共通設定を確認して明示的にseedするか否かを設計段階で確定すべきです。

## 全体判定: CHANGES_REQUESTED

Round 1の主要な構造問題はほぼ解消されています。残る変更要求は以下の5点です。

- 受入条件4のページ配線を検証する
- 14件という件数ではなく、viewport外という前提を直接検査する
- `playbackJobId` の実queryを提示してpreview限定を証明する
- 初期noteを「選択済み」ではなく「候補」と表現する
- 受入条件11を異なるカード間比較から、同一カードの状態変更前後比較へ直す
- Checkout fixtureで未契約状態とSeeder実行を明示的に固定する