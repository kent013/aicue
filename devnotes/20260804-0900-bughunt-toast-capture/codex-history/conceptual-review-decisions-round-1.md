# 対応マトリクス: conceptual-review Round 1

## [Critical] `present` 非空で finding を抑止する規約は新種の偽陰性を作る (観点 4)
- 判断: **対応する**
- 根拠: 指摘は実コードで裏が取れた。(a) `resources/js/components/atoms/Alert.svelte:16-17` の
  `Alert` atom は `role=alert` (danger) / `role=status` (他) を持ち、**常駐 UI**として使われる。
  (b) `resources/js/lib/stores/toast.ts:27` により **error toast は自動消去しない** (ttl=null) ので、
  前操作の error toast がページに残り続ける。素朴な `present` 判定はこれらを
  「今回の操作のフィードバック」と誤認する。
- 対応内容: probe に**基線 (baseline)** を導入。probe 実行のたびに「可視な live region」へ
  `{gen, text}` を刻み、次回 probe では「基線が無い」または「テキストが変わった」要素だけを
  `present_new` として返す。基線一致の要素は `present_preexisting` (件数のみ) に落とし判定に使わない。
  判定表を `present` → `present_new` に差し替え、`installed_now:true` のときは
  基線が無いため `present_new` を参考情報に格下げ (未検証に倒す) と明記した。

## [Warning] 既存 live region のテキスト更新 / 属性による hidden→visible の観測範囲が曖昧 (観点 3)
- 判断: **対応する** (観測契約を先に固定)
- 根拠: 「先に固定せよ」という要求は正当。ただし観測範囲は**実測で絞る**。
  AI-CUE の非単調 UI 2 件は Svelte の `{#each}` / `{#if}` による mount/unmount
  (`ToastContainer.svelte:34-53` / `CodeSnippet.svelte:58-62`) で、属性トグル方式は存在しない。
- 対応内容: 概念設計の実装方針 1 に「probe の観測契約」を追加。
  `childList` + `characterData` + `subtree` を監視 / **属性は監視しない** (根拠つき) /
  基線が可視要素のみを刻む副作用で hidden→visible は `present_new` に出るため、
  残余は「hidden→visible かつテキスト不変」のみ、と残余を明記した。
  可視性フィルタも記録時 (layout 非依存) と probe 時 (`getClientRects` 等) で分けて規定。

## [Warning] jsdom では live run の挙動を担保できない / 受入条件が弱い (観点 3・7)
- 判断: **対応する**
- 対応内容: 「次回 bug-hunt run の受入条件 (必須観測ケース)」節を新設。
  L1 = F-1-02 同型の SPA 削除導線で `seen` に `toast-success` が入ること、
  L2 = `CodeSnippet` コピー導線 (2 秒窓、`organizations.onboarding.cli` / `.mcp` は screens.md に実在)
  で `role=status` の「コピー完了/失敗」が入ること。
  **どちらかが取れなければ方式不成立として設計を見直す** (値のチューニングに逃げない) と明記。

## [Warning] 対象の一般化 (live region 全般) が強すぎる (観点 4)
- 判断: **対応する**
- 対応内容: 「観測対象の概念は**非単調 UI**。ARIA live region はその**手段**」と節タイトルごと書き換え、
  常駐 live region は基線差分で明示的に除外する旨を先頭に置いた。

## [Warning] `installed_now:true` を未検証に倒すと H7 が無言で機能停止する (観点 5)
- 判断: **対応する**
- 対応内容: 「H7 の適用条件」節を新設。(a) H7 の「フィードバック無し」判定は
  `installed_now:false` の操作にのみ適用、(b) `installed_now:true` の操作は shard-report の
  「未検証」節に**操作名つきで必ず列挙**、(c) 再実行は 1 回まで・非冪等な破壊操作は再実行しない、
  (d) AI-CUE の書き込み操作はほぼ全て Inertia SPA visit なので実カバレッジ低下は限定的
  (根拠 `Manuals/Show.svelte:55-65`)、ただし限定的であることを report に書かせる。

## [Warning] hidden / aria-hidden / 非接続の live region を拾う偽陰性源 (観点 5)
- 判断: **対応する**
- 対応内容: 上記「可視性フィルタ」に統合。記録時は `el.hidden` / 祖先 `aria-hidden="true"` /
  未接続を除外、probe 時はさらに `getClientRects().length > 0` + `display`/`visibility` を見る
  (`tests/Browser/FlashToastTest.php:50-58` と同じ判定)。

## [Warning] spec-ledger の file:line 実在チェックは保守負債になりやすい (観点 7)
- 判断: **対応する**
- 対応内容: テスト対象を「**根拠ファイルの実在**」と「機械 registry に『登録済』と書いた `A-NNN` の実在」
  に限定し、**行番号は検証しない**と明記した。

## [Suggestion] spec-ledger の書式拡張と template-divergence 追記は最小限か再確認せよ (観点 6)
- 判断: **一部反論 / 一部対応**
- 根拠と対応:
  - **spec-ledger の書式拡張は必要** (反論)。現行 spec-ledger.md:42-44 の節見出しは
    `SPEC 確定` / `DOC 確定` / `実装で解消` / `CLOSED` の 4 種のみで、
    **`verdict: false_positive` を置ける節が無い**。A-001 は `false_positive` なので、
    節を足さないと F-1-02 を書けない。加えて「driver 側の再発防止」欄の追加は、
    aigenba が 4 回踏んだ根本原因 (申し送りが人手の心構えで終わり機構化されない) を
    構造で塞ぐためのもので、本件の主目的に直結する。
  - **template-divergence の追記は不要と判断を改めた** (対応)。根拠は
    (a) D1〜D9 は全てドメイン構造の逸脱で判定軸が異なる、(b) SKILL.md は T036 (`a9074f0`) で
    既に AI-CUE 都合の追記を受けており divergence 記録の先例が無い、(c) probe 機構はアプリ非依存で
    「逸脱」ではなく**上流還流候補**。概念設計の実装方針 5 をその判断に書き換えた。

## [Suggestion] 観点 1・2・6 の肯定コメント
- 判断: 対応不要 (方向性維持)
