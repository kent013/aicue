全体判定: **CHANGES_REQUESTED**

以下は提示された設計文面ベースのレビューです。`F-1-1` の再定義と、`scenario.update` を backend flash で触らない判断は概ね妥当ですが、`F-1-2` の状態設計にまだ穴があります。

**1. 使命との整合性**
- [Suggestion] 方向性は使命に整合しています。保存結果と失敗発生源をその場で明確にするのは、「思考ゼロ・編集ゼロ」の阻害要因を減らす改善として筋が良いです。
- [Suggestion] `F-1-1` を「success toast が無い」ではなく「4 秒で消え、画面上に確認が残らない」と再定義した点は、提示された証拠と整合しており妥当です。これは「データに真摯に向き合った」修正として評価できます。

**2. 禁止事項違反**
- [Suggestion] 現状の設計文面では禁止事項への明確な抵触は見当たりません。frontend 内部状態の改善に閉じ、`response()->json()` 直書きや Prism 直呼びのような地雷にも触れていません。
- [Suggestion] `scenario.update` に backend flash を足さない判断は正しいです。XHR + `JsonResource` + 409 楽観ロック契約を維持する方が、この画面の責務分離として自然です。

**3. 実現可能性**
- [Warning] `F-1-2` の `startError: { source, message, showPurchaseLink } | null` は、`render` と `preview` の起動失敗を同時に保持できません。後から起きた失敗で先の失敗が上書きされ、帰属問題を別形で残します。  
  修正提案: `renderStartError` / `previewStartError` を分離するか、`Record<'render' | 'preview', StartError | null>` にしてください。
- [Suggestion] `F-1-1` の `justSaved` 追加は Svelte 5 の局所 state で十分実現可能です。backend 非変更で収まる点も良いです。

**4. 期待効果の妥当性**
- [Warning] Alert の `title` を「完成動画」「プレビュー」にするだけだと、同一小節内で「起動失敗」と「ジョブ失敗」が併存した場合の識別がまだ弱いです。`render-start-error` と `render-error` が同時に見えるケースでは、同じ見出しの赤 alert が 2 つ並び得ます。  
  修正提案: title を phase-aware にしてください。例: `完成動画の開始に失敗` / `完成動画の生成に失敗`、`プレビューの開始に失敗` / `プレビューの生成に失敗`。代替として、同一 source 内で排他表示ルールを明文化してもよいです。
- [Suggestion] `F-1-1` については、「toast を残しつつ、その場に残る確認を追加する」という期待効果は合理的です。既存 toast を捨てず補完に徹している点も過不足ありません。

**5. リスク**
- [Suggestion] `justSaved` は「次の編集まで残る」だけでなく、409 競合やサーバ側の別更新を受けたときにどう消すかを明文化した方が安全です。誤って古い成功表示が残ると、安心のための表示が逆に誤認を生みます。
- [Suggestion] `showPurchaseLink` の出し分けは source 別 state に載せる方が安全です。共有 state のままだと、preview 起動失敗の購入導線が render 側に出る類の回帰を起こしやすいです。

**6. スコープの適切さ**
- [Suggestion] incremental で適切です。toast の global TTL 変更や backend 契約変更に踏み込まず、finding に対して局所修正で返している点は良いです。
- [Suggestion] ただし「1 小節あたり赤 alert は最大 1」という記述は、現行案のままだと達成保証が弱いです。ここは期待効果を少し弱めるか、排他ルールを設計に足してください。

**7. 型安全性**
- [Warning] `startError` を単一 nullable object にする案は、型としては書けても UI 状態空間を正しく表現しきれていません。strict TS 下では「表現できる型」と「正しい状態モデル」は分けて考えるべきです。  
  修正提案: `type StartSource = 'render' | 'preview'` を定義し、`Record<StartSource, StartError | null>` か明示的な 2 プロパティ構成にしてください。DTO / `JsonResource` 非変更という方針自体は妥当です。

総評として、`F-1-1` の問題再定義と `scenario.update` の契約維持判断は承認できます。一方で `F-1-2` は「共有 state を source 付き 1 個へ置換する」案だと状態表現が不足しており、設計を 1 段だけ詰める必要があります。そこを直せば APPROVED に寄せられます。