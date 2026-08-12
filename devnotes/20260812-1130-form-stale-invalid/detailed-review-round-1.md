**全体判定: REQUEST_CHANGES**

設計の方向性は概ね妥当ですが、`BillingContactForm` の stale 応答対策が現在の `submissionId` では守りたい競合を守れていません。ここは実装前に直すべきです。

**施策 1: Projects/Create**
APPROVE

[Suggestion] `oninput` で `form.clearErrors("name")` / `"description"` を呼ぶ方針は既存 9 箇所と同じなら妥当です。フィールド単位で消す点も正しいです。

[Suggestion] テストは `clearErrors` の呼び出しだけでなく、可能なら「該当フィールドのエラー表示が DOM から消える」も 1 本見ると、`FormField` / `Input` 連携の退行を拾えます。ただし今回の契約固定としては呼び出し検証でも最低限は足ります。

**施策 2: BillingContactForm**
REQUEST_CHANGES

[Critical] `submissionId` は、この設計が説明している競合を解決していません。

問題の時系列はこれです。

1. 古いサーバエラーが表示されている
2. ユーザーが入力して `emailEdited = true`
3. submit する。`attempt = ++submissionId`
4. 送信中にユーザーがさらに入力する。`emailEdited = true` のまま
5. その後、送信時点の古い値に対する `onError` が返る
6. `attempt === submissionId` なので `emailEdited = false`
7. 最新入力に対して stale なエラーが再表示される

つまり `submissionId` は「後続 submit」しか検知できず、「submit 後の編集」を検知できません。一方で `submitting` ガードがあるため、同一フォームから後続 submit は通常起きません。したがって現状の `submissionId` は冗長に近く、必要な競合には効いていません。

修正するなら、送信世代ではなく編集世代または送信時スナップショットを使うべきです。例:

```ts
const submittedEmail = emailText;
const submittedName = nameText;

onError: () => {
    if (emailText === submittedEmail) emailEdited = false;
    if (nameText === submittedName) nameEdited = false;
}
```

より厳密には `emailEditVersion` / `nameEditVersion` を `oninput` で増やし、submit 時の version と一致するフィールドだけ解除する形が堅いです。

[Warning] `onError` / `onSuccess` の両方で解除する設計自体は妥当です。Inertia の通常ライフサイクルでは成功・エラーは排他的で、`onFinish` は後段なので、解除に使わない判断も正しいです。キャンセル・通信失敗でどちらも呼ばれない場合に stale 抑制を解除しないのも設計意図と合っています。

[Warning] 入力中もフィールドは編集可能なので、「送信成功したが、その間にユーザーが値を変えていた」ケースを設計上明示した方がよいです。現在の案だと成功応答で編集済み状態を全解除し、画面上の値がサーバ保存値と違う可能性を無視します。

**施策 3: テスト計画**
REQUEST_CHANGES

[Critical] 契約 11 は、現在の `submissionId` 実装に対する妥当なテストとしては書きにくいです。jsdom で古い callback を後から発火すること自体は可能ですが、`submitting` ガード下で「後続 submit により `submissionId` が進んだ後、古い `onError` が来る」状況を作るには、`onFinish` 後に `onError` を呼ぶような Inertia の通常順序と違うスタブになりがちです。そのテストは実装詳細に寄りすぎた偽陽性になります。

契約 11 は次のように変えるべきです。

「submit 後にユーザーが同じフィールドを再編集した場合、その submit の `onError` はそのフィールドの抑制を解除しない」

これは jsdom で自然に書けます。`router.patch` の options を捕まえ、`onStart` → input → `onError` の順に発火すればよいです。

[Warning] M7 の予測は現状だと外れます。`submissionId` 判定を削除しても、現実的な単一 in-flight の流れでは差が出ません。M7 を有効な mutation にするなら、対象は `submissionId` ではなく「送信時 editVersion/snapshot 判定の削除」に変更するべきです。

[Warning] M6 はテストの作り方次第で生き残ります。`onSuccess` 後に編集済みフラグが残っていることを観測するには、次のサーバエラー props 到着をリアクティブに再現する必要があります。単に callback を呼ぶだけでは固定できません。`page.props.errors` を差し替えて DOM に反映されるスタブにするか、component を再描画して契約を観測してください。

[Suggestion] `useForm` / `router` stub 方針は妥当ですが、呼び出しだけを見るテストに寄せすぎると UI 契約を固定できません。重要契約 6〜11 は、最終的に「エラー文言が表示される/消える」を DOM で見る方が信頼できます。

**mutation 評価**

M1〜M5 は概ね妥当です。M6 は観測設計が弱いと赤くならない可能性があります。M7 は現在の設計対象が不適切です。`submissionId` ではなく、送信時点以降の編集を検出する仕組みを mutation 対象にしてください。