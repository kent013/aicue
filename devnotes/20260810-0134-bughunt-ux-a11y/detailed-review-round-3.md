## 施策 0: APPROVE

新たな指摘はありません。

## 施策 A: REQUEST_CHANGES

[Warning] 受入条件4に「component testが成立しなければ保証範囲を下方修正する」という逃げ道を残したままでは、設計上の受入条件を満たしたことにはできません。

保証範囲を正直に報告する姿勢自体は正しいですが、受入条件4は録画中のUX不変条件です。ページ配線を検証できなかった場合は「実装完了」ではなく、テスト可能な境界へ設計を変える必要があります。

修正案: 2段構えを次の契約に確定してください。

- helperテストでは、`captureActive=true` により副作用が抑止されることを検証する
- `Show.svelte` のcomponent testでは、副作用自体ではなく、`navigateToPanelIfNeeded` に渡される `captureActive` が `true` であることを検証する
- `panel-navigation.ts` をmodule mockし、カット選択後の呼び出し引数を確認する

これならjsdomの矩形・focus・`scrollIntoView` 実装に依存せず、ページ配線を直接固定できます。mockが難しい場合は、配線をテスト可能な関数へさらに抽出する必要があります。「成立しなければ未固定として完了」は不可です。

[Suggestion] クリック前の

```js
rightPane.getBoundingClientRect().top >= window.innerHeight
```

という前提assertは妥当です。これは「実装前の画面では対象がviewport外」という反証条件を直接固定しています。クリック後のsmooth scroll完了を待ってから受入条件1・2を評価する点だけ、Browserテストで明示してください。

## 施策 B: APPROVE

提示された実queryにより、初期値は以下に限定されています。

- `kind = Preview`
- `status = Succeeded`
- `output_path IS NOT NULL`
- 最新ID

実行中もpreview分岐だけが`playbackId`を更新しています。固定文言「プレビュー動画」という事実認定は成立しています。

## 施策 C: REQUEST_CHANGES

[Warning] 同一カードの前後比較へ直した方向は正しいですが、StandardカードのCTA比較には既存のラベル変更が混入します。

Standard選択時には、新しいsr-only noteの追加と同時にCTAが「選択」から「選択中」へ変わります。したがってCTAの`height`が変わった場合、それがheaderBadgesによる退行なのか、既存CTA文言の差なのか判別できません。

修正案: 測定を次のように分けてください。

- Starter（note 有→無、CTA文言不変）: `h3`・価格・CTAの相対`top`と`height`を前後比較
- Standard（note 無→有、CTA文言変化）: `h3`・価格・CTAの相対`top`を前後比較
- StandardのCTA `height` は不変条件から外すか、CTA自体に既存の固定寸法契約がある場合だけ検査する
- 両カードでnoteの`sr-only`状態を別途検査する

これで新規headerBadgesによるレイアウト変化を、既存CTA状態遷移と切り分けられます。

「初期候補として表示されています」は妥当です。「選択済み」や操作完了を含意せず、青枠の対象とCTAの「選択」を矛盾なく説明しています。

fixtureの修正も正しいです。`grandfatherFreePlan: false`、自動Seeder、org-scoped sessionを経由する303 canonical redirectまで前提が具体的に固定されています。

[Suggestion] `assertPathIs('/onboarding/checkout')` は通常pathしか検査しないため、query消失も契約に含めるなら、併せて`window.location.search === ""`を検査してください。

## 全体判定: CHANGES_REQUESTED

残るWarningは2件です。

- 受入条件4のページ配線テストを必須とし、「失敗時は保証を下げて完了」という分岐を削除する
- 受入条件11からStandard CTAの`height`比較を分離し、既存ラベル変更との交絡を除く

この2点以外は、Round 2の指摘に対して十分に修正されています。