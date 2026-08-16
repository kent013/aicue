全体判定: **CHANGES_REQUESTED**

Round 2 の主要指摘は解消されていますが、施策 D に実装不能な宣言順が残っています。

### 施策 A: APPROVE

判定ロジック、縮退方針、テスト計画に追加の必須修正はありません。

### 施策 B: REQUEST_CHANGES

[Warning] 二重発火テストは、`pointerup` だけではブラウザの後続 `click` を再現できない可能性があります。Testing Library/jsdom の pointer event が実ブラウザ同様に click を合成する保証はありません。

修正案: ボタン上で `pointerdown` → 移動した `pointerup` → `click` を明示的に発火し、合計1回であることを確認してください。併せて、ボタン内の Lucide 要素を `event.target` にしたケースも含めると `closest("button")` の契約を直接固定できます。

### 施策 C: REQUEST_CHANGES

[Warning] 撮影ガイドと上部字幕が同じ上端領域を使用しています。`ShootingGuideOverlay` は `top-2`、`SubtitleOverlay` の primary も `inset-0 p-3` の上部です。DOM順で字幕を上にしても、両方が表示されるのではなく、撮影ガイドが字幕に覆われる可能性があります。字幕が常時ONで `subtitle_primary` があるカットでは、中核の構図指示が実質読めません。

修正案: 撮影ガイドと primary 字幕に重ならない固定レーンを割り当てるか、同一上部コンテナ内で縦に積んでください。少なくとも両方が非空の状態を Browser のスクリーンショットまたは要素矩形の非交差 assertion で固定する必要があります。

### 施策 D: REQUEST_CHANGES

[Critical] `selectedCutId` の初期化で `initialLandscape` を参照していますが、提示コードではその宣言が後ろにあります。

```ts
let selectedCutId = $state(
    initialLandscape ? (manual.cuts[0]?.id ?? null) : null,
);

// 後で宣言
const initialLandscape = matchesLandscapeCapture();
```

これは block-scoped variable の宣言前参照となり、TypeScriptエラーまたは実行時のTDZエラーになります。「定義はこの行より前に置く」というコメントとコードが一致していません。

修正案: `manual` props の受領直後、`selectedCutId` より前に配置してください。

```ts
let { project, manual }: Props = $props();

const initialLandscape = matchesLandscapeCapture();

let selectedCutId = $state<number | null>(
    initialLandscape ? (manual.cuts[0]?.id ?? null) : null,
);
```

その後の横持ち状態節では再宣言せず、次だけを置きます。

```ts
let landscapeMatches = $state(initialLandscape);
```

[Warning] SSRに関する説明の「将来SSRを入れても安全側の縮退」は、hydration不一致が安全という意味にはなりません。SSRではサーバーがinline、クライアント初期評価がfullscreenとなり、DOM構造が異なる可能性があります。

修正案: 現状SSR非対応であることだけを前提として明記し、将来SSR導入時は初期判定方式を再設計する再確認条件にしてください。「将来も壊れない」という主張は削除します。

### 施策 E: REQUEST_CHANGES

[Warning] `MutationObserver` はcallbackをmicrotaskで通知するため、render直後にobserverを外して結果を見ると変更記録を取りこぼす可能性があります。

修正案: assertion前に `observer.takeRecords()` を回収するか、少なくともmicrotaskを1回進めてから切断してください。観測対象は追加ノード自身だけでなく、その子孫の `capture-recording-heading` も探索します。

同期 assertion とMutationObserverの二段構成自体は妥当です。

### 施策 F: REQUEST_CHANGES

[Warning] 「バージョン一覧は詳細設計が正本で、本書には写さない」としながら、追記文中に `14 / 15.4 / 15.5` を複製しています。正本を一つにする説明と矛盾します。

修正案: `docs/supported-browsers.md` から具体的な版番号を削除し、見出しへの参照だけにします。

```markdown
依存するWeb機能と最低バージョン前提は、詳細設計の
「依存する Web 機能と最低バージョン前提」を正本とする。
```

宣言順の修正が唯一の実装ブロッカーです。加えて、撮影ガイドと字幕の同時可視性、テストイベントの再現性、SSR保証範囲、ドキュメント正本の重複を整理すれば承認可能です。