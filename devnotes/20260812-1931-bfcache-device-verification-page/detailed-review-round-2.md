## 全体判定: CHANGES_REQUESTED

Round 1 の指摘は概ね適切に反映されています。ただし、terminal window と軸2の観測が競合しており、失効セッション経路を正しく PASS 判定できない可能性があります。ここは実装前に修正が必要です。

### 施策1: REQUEST_CHANGES

[Critical] terminal window 確定後に lifecycle 記録をすべて止めると、失効セッション経路の軸2に必要な `page-hide` が失われます。

想定される時系列は次です。

```text
A → B の離脱
page-hide（軸1の往路）

Aをbfcache復元
page-show（ここでterminal window確定）
pending → verifying
location.replace('/login')
page-hide（軸2の「秘匿維持のまま離脱」を示す観測）
```

現在の設計では、復元時の `page-show` で窓が確定した直後に lifecycle 自動追記を停止するため、最後の `page-hide` を記録できません。その結果、`unauthenticated-redirected` / `hidden-then-left` の導出根拠が欠けます。

修正案:

- terminal window は「軸1が参照する lifecycle の範囲を固定する」概念に限定する。
- 窓確定後も、軸2が終端するまでは lifecycle と guard 状態を記録する。
- 軸1は最初の `started < away-started < hide < show` だけを参照し、後続イベントを無視する。
- 軸2は復元 `page-show` より後の guard 遷移と、その後の `page-hide` を参照する。
- 自動観測停止は次のいずれかの軸2終端後とする。
  - `authenticated-unhidden`
  - `retry-hidden`
  - 秘匿維持状態での復元後 `page-hide`
  - 利用者による `trial-aborted`

つまり「軸1の窓の確定」と「試行全体の観測終了」を別状態として設計してください。

[Warning] 軸2の `page-hide` が往路と復元後リダイレクトで区別されていません。

現在の規則では、A→B の最初の `page-hide` を誤って `pending → verifying → 秘匿維持のまま page-hide` の終端として拾う可能性があります。

修正案: 軸2では、terminal window の `page-show.sequence` より後に発生した `page-hide` のみを対象にしてください。テストにも「往路の hide は軸2の redirect hide として使わない」を追加します。

[Warning] derive 関数の複数 `trialId` 契約がシグネチャから決定できません。

`deriveTrialVerdict(events)` には対象 `trialId` が渡されないため、「対象 trialId 以外を無視する」の対象を一意に選べません。

修正案は次のいずれかです。

- 推奨: derive 関数は単一 trialId の配列だけを受け、複数 ID があれば `inconsistent` とする。
- または `deriveTrialVerdict(trialId, events)` のように対象 ID を明示する。

`loadTrials()` が既に trialId ごとの分離を担うため、前者が単純です。

### 施策2: APPROVE

`LocalOnly + auth + Inertia propsなし` の構成、実効条件を正負のコントロールで固定するテスト計画ともに妥当です。DTO / JsonResource の適用対象外という判断にも問題ありません。

### 施策3: REQUEST_CHANGES

[Critical] 施策1と同じく、terminal window 確定直後に lifecycle 自動追記を止める記述を修正する必要があります。

修正案: 「terminal window 確定後は軸1への採用を停止するが、軸2終端までは観測イベントを追記する」としてください。保存側で後続 lifecycle 自体を捨てる必要はなく、導出側で軸ごとの観測範囲を固定する方が証跡としても完全です。

[Warning] `typeof crypto?.randomUUID` は安全な feature detection として書かない方がよいです。

修正案:

```ts
const randomUUID = globalThis.crypto?.randomUUID;

if (typeof randomUUID !== "function") {
    // 検証不能を表示
}
```

実際の呼び出しでは receiver を失わないよう、素直に次を使う方が確実です。

```ts
const contextToken = globalThis.crypto.randomUUID();
```

[Warning] 「進行中試行」の定義が不足しています。

terminal window が確定していて軸2が未終端の試行、軸2まで終端した試行、手動 `redirect-observed` 待ちの試行を区別する必要があります。

修正案: 保存する status を追加するのではなく、純粋導出関数で以下を判定してください。

- `collecting-axis1`
- `collecting-axis2`
- `awaiting-manual-confirmation`
- `complete`
- `aborted`

この導出状態をもとに listener の追記可否を決めれば、保存値の stale 化も避けられます。

### 施策4: APPROVE

失効セッション経路の再ログインから stored report 回収まで明記され、手動確認フローとして成立しています。既存 logout 導線のみを使う判断も維持されています。

### 施策5: REQUEST_CHANGES

[Critical] terminal window のテストに、軸2で必要な復元後 `page-hide` がありません。

修正案として、少なくとも以下を追加してください。

- 往路 `page-hide` → 復元 `page-show` → `pending → verifying` → 復元後 `page-hide` の順で `hidden-then-left`
- 同じ列に `redirect-observed` を追加すると `unauthenticated-redirected`
- 往路 `page-hide` を軸2の redirect hide として採用しない
- terminal window 確定後の復元後 `page-hide` が保存されても、軸1は `valid-bfcache` を維持
- 軸2終端後の fresh load イベントが追加されても両軸の判定が崩れない

[Warning] 軸1の真理値表 #1〜#7 などが、新しい必須条件 `away-navigation-started` を含んでいません。

修正案: terminal window を扱う全テスト列を `started → away-started → hide → show` に統一してください。意図的に `away-started` を欠落させるケースは、別の負のテストとして期待値を明示します。

[Warning] `away-started` 直後に `page-hide` がまだ発生していない瞬間を、即座に `invalid-wrong-route` と判定できます。

リンク押下と `pagehide` の間には正常な短い時間差があります。その間に画面を再描画すると、正常な遷移を失敗表示する可能性があります。

修正案: `invalid-wrong-route` は、同じ Document が継続していることを明示的に確認した場合に限定してください。例えば、リンク押下後の次タスクでまだ `document.visibilityState !== "hidden"` なら `away-navigation-failed` を追記し、その明示イベントを判定根拠にします。単なる「away-started があり hide がまだない」状態は `incomplete` が安全です。

### 施策6: APPROVE

`AppLayout`、`app.ts`、`bfcache-guard.ts` まで unload 禁止対象に含める判断は妥当です。これは debug ページ固有の都合ではなく、認証済み Inertia 画面で経路Bを成立させるためのアーキテクチャ制約です。

ただし文書では「`beforeunload` があれば必ず bfcache 対象外」とブラウザ横断で断定せず、「対象外になる、または適格性を不安定にするため禁止」と表現するのが正確です。

### 施策7: APPROVE

T085 をクローズせず、設備追加と実機確認完了を分離している点、manual confirmation の表現統一、HTTPS とトンネル運用規律はいずれも妥当です。

## 修正必須事項

実装前に次の3点を設計へ反映すれば、承認可能な状態になります。

1. terminal window の確定と自動観測の終了を分離する。
2. 軸2では復元 `page-show` 後の `page-hide` だけをリダイレクト離脱として扱う。
3. `invalid-wrong-route` は `away-started` の欠落待ちではなく、Document 継続を確認した明示的な失敗イベントから導出する。