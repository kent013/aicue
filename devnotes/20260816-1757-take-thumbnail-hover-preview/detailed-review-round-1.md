**全体判定: CHANGES_REQUESTED**

大筋の方向性は妥当です。署名 URL を props に載せず既存の認可付き 302 endpoint を使う判断、`has_thumbnail` を DTO に足して 404 を踏まない表示条件にする判断、ホバー再生を補助機能として静かに失敗させる方針はいずれも良いです。

ただし、設計として直すべき点がいくつかあります。

**施策 1: REQUEST_CHANGES**

[Warning] `AdoptedTakeReferenceInventory` の区分は `DifferentCriterion` のままでよいが、根拠文は更新すべきです。  
現行 rationale は「id と status を表示するため」と書いており、`thumbnail_path` の参照が増えると説明が事実とずれます。

修正案:

```php
'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id / status / '
    .'サムネイル生成有無を表示条件として読むだけで、採用済み ready テイクの充足判定はしない。'
    .'レンダの充足判定(AdoptedReadyTakeCoverage)とは基準が違うため意図的に統合しない。',
```

[Warning] UI 側の `status === "ready" && has_thumbnail` は、用途を明確に限定すればドメイン規約 12 違反ではありません。  
これは「このカットは動画化に充足しているか」ではなく、「`/thumbnail` と `/playback` が 404 にならない状態か」という表示・URL 発行条件です。ただし、命名やコメントで `ready coverage` / `adopted ready coverage` のような表現を使うと概念が混ざります。

修正案: コメント・テスト名では「サムネイル表示条件」「endpoint availability」「404 を踏まない条件」と表現し、充足判定とは書かない。

**施策 2: REQUEST_CHANGES**

[Warning] `onDestroy` で無条件に `document.removeEventListener(...)` を呼ぶのは SSR 耐性が弱いです。  
Svelte の `onDestroy` はサーバ側レンダリングで実行され得るため、Inertia SSR を使う構成では `document is not defined` のリスクがあります。

修正案:

```ts
onDestroy(() => {
    if (typeof document !== "undefined") {
        document.removeEventListener("visibilitychange", onVisibilityChange);
    }
    clearDwell();
});
```

または listener 登録済みフラグを持つ。

[Warning] `startPreview()` で `playbackUrl !== null` を再確認していません。  
通常の画面では props が 200ms の間に変わる可能性は低いですが、設計上は「満了時に現在の条件を再確認する」としているため、URL の有無も再確認した方が一貫します。

修正案:

```ts
function startPreview(): void {
    dwellTimer = null;
    if (!hovering) return;
    if (playbackUrl === null) return;
    if (prefersReducedMotion()) return;
    playing = true;
}
```

[Suggestion] `prefersReducedMotion` が `@/lib/capture/panel-navigation` にあるため、manual feature から capture panel の実装名へ依存しています。既存の置き場に合わせるなら許容ですが、今後も使う汎用 browser helper なら `@/lib/browser/motion` などへ移す方が名前と責務が合います。

**施策 3: APPROVE**

設計は妥当です。`adopted !== null` / `status === "ready"` / `has_thumbnail` の 3 条件で URL を張る判断は、既存 endpoint の 404 条件と合っています。

[Suggestion] テストでは「サムネイル component が存在しない」だけでなく、非 ready / `has_thumbnail=false` のケースで `/thumbnail` / `/playback` の文字列が DOM に存在しないことも固定すると、404 URL を張らない設計意図がより強く守れます。

**施策 4: REQUEST_CHANGES**

[Warning] Architecture テストへの新規登録不要という判断は一部修正が必要です。  
新規登録は不要でよいですが、`AdoptedTakeReferenceInventory` の既存登録の rationale 更新は必要です。これは「登録不要」ではなく「区分は維持、根拠文は更新」です。

[Warning] `TakeHoverPreview` のテスト計画に SSR / non-browser 耐性がありません。  
実装側に `typeof document !== "undefined"` guard を入れるなら、最低限その設計判断をテストまたは既存 SSR 方針に紐づけておくべきです。SSR テスト基盤が無いなら、テスト計画に「SSR lane が無いためコード guard で担保」と明記してください。

**セキュリティ確認**

署名 URL を Inertia props に載せず、`/thumbnail` / `/playback` の認可付き endpoint を `<img>` / `<video>` の `src` にする設計は妥当です。各リクエストで session 認証、nested binding、`project.in-route-org`、`Gate::authorize('preview', $take)` を通るため、props 経由で期限付き URL が漏れる設計より安全です。

ただし、`no-store, private` と「署名 URL を JS 側でキャッシュしない」は維持してください。ここを緩めると、この施策の安全性の前提が崩れます。