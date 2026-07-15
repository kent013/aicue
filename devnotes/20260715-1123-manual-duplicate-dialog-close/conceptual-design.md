# 概念設計: manual-duplicate-dialog-close

bug-hunt run 20260715-084108 F-1-01 (High) / T049 複製機能の不具合

## 背景・課題

`DuplicateManualDialog.svelte`（T049 で追加）はマニュアルの別名複製ダイアログ。複製成功時、サーバは
新しい VideoManual への `redirect` を返し、Inertia が新マニュアルの `Manuals/Show` 画面へ遷移する。

現行の `submit()` は `form.post(...)` に `onError` しか渡しておらず、コメントで「成功時は redirect で
新 manual へ遷移するため onSuccess で閉じる必要はない」と明記している。しかしこの前提が誤っている:

- 遷移先も同じ `Manuals/Show` ページコンポーネントであるため、Inertia は**同一コンポーネントを
  再マウントせず props だけ差し替える**。ダイアログの開閉状態 `duplicateDialogOpen` は親
  (`Manuals/Show.svelte`) の `$state` として保持され、遷移をまたいで `true` のまま残る。
- 結果、複製成功後は「今開いていた**新マニュアル**の Show 画面」に確認モーダルが開いたまま重なり、
  「複製する」ボタンも `form.processing` が false に戻って再び有効化される。
- ユーザーがそのまま再クリックすると、今度は**現在のマニュアル（＝直前に作られたコピー）を無言で
  再複製**し、意図しないリソースが増殖する（bug-hunt 仮説 H6=詰み感 / H7=無言の副作用）。

さらに副次的に、ダイアログが遷移をまたいで生存するため、複製後の新マニュアル上でダイアログを開くと
`useForm` がマウント時 1 回だけ初期化される実装のせいで、タイトルは**旧マニュアルの
「{旧タイトル} のコピー」**のまま残る（新しい props `defaultTitle` を反映しない）。これは F-1-01 が
「ダイアログが遷移後も生存する」ことで新たに表面化する不整合。

## 改善アイデア

ダイアログを「遷移をまたいで生存し得る永続コンポーネント」として正しく扱う。3 点を修正する:

1. **成功時に必ず閉じる**: `form.post` の `onSuccess` で `open = false` にし、redirect 完了に依存せず
   モーダルを確実にクローズする。
2. **送信中の多重送信ガード**: 送信ハンドラ冒頭に `if (form.processing) return;` を置く。ボタンは
   既に `loading={form.processing}` により送信中だけ disabled になる（Button atom が
   `disabled={disabled || loading}`）ため、二重クリックは押下段階で無効化される。ハンドラ冒頭ガードは
   フォーム `submit`（Enter キー）経由の再入も塞ぐ。これは「必須未充足で disabled」（禁止事項 8）とは
   別で、**送信中の submit 多重防止**であり許容される（既存 `Projects/Show.svelte` と同じ流儀）。
3. **再オープン時の defaults 追従（open の false→true エッジのみ）**: ダイアログが閉→開に遷移した
   瞬間だけ、その時点の props（`defaultTitle` / `defaultCategory`）でフォーム値を seed し直し、
   合わせて `form.clearErrors()` で前回のエラー状態も初期化する。**open=true のまま props が変わっても
   入力途中の値は上書きしない**（seed 契機はエッジに限定）。これにより「永続コンポーネントが古い初期値・
   古いエラーを握り続ける」不整合を解消する。

   実装は汎用 `$effect(props 依存)` にせず、`prevOpen` を保持したエッジ検知、または明示的な
   `seedFromDefaults(): void` 関数に寄せる（依存は boolean `open` のみとし、`form` オブジェクト全体を
   effect 依存に含めない＝意図しない再実行を避ける）。`seedFromDefaults` は props 型
   （`defaultCategory: number | null`）を崩さず `useForm` の shape と一致させる。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」で現場作業者が迷わずマニュアルを作れることが AI-CUE の核。
  複製後にモーダルが残り無言で複製が増える挙動は、ユーザーを混乱させ不要リソースを生む UX 破綻であり、
  これを塞ぐことで複製フローの信頼性（意図した 1 回だけ複製される）を回復する。
- 複製成功でモーダルが閉じ、新マニュアル画面がクリーンに表示される。
- **少なくとも同一画面・同一 UI インスタンス上の accidental re-submit（二重クリック・Enter 連打・
  redirect 完了前の再入）を防ぐ**。サーバ側の複製冪等化は本タスク対象外（別タスク）であり、
  効果はフロントの多重送信ガードで防げる範囲に限る。
- 遷移後にダイアログを再度開いても、新マニュアルの正しい既定タイトル/カテゴリが表示され、前回エラーも残らない。

## テスト計画（概要）

vitest（`tests/js/components/features/manual/DuplicateManualDialog.test.ts`）に以下を追加する。
概念段階でテストをスコープに明示する（禁止事項 1: テストなし完了不可）:

1. 複製 submit → `onSuccess` 発火でダイアログが閉じる（`bind:open` が false になる／`onSuccess` callback で
   `open=false`）。
2. 送信中（`form.processing=true`）は confirm ボタンが disabled であり、二重クリックしても `form.post` が
   2 回目は発火しない（ハンドラ冒頭ガード）。
3. open を false→true に再遷移させると、現在の props `defaultTitle` / `defaultCategory` が再 seed され、
   前回のエラーがクリアされる。
4. （既存テスト維持）「送信ボタンは必須未充足でも disabled にしない（禁止事項 8）」を壊さない。

## 実装方針（概要）

- 変更は基本的に `resources/js/components/features/manual/DuplicateManualDialog.svelte` に閉じる
  （フロントのみ、サーバ/DTO/ルート変更なし）。
- `submit()`:
  - 冒頭に `if (form.processing) return;`
  - `.post()` に `onSuccess: () => { open = false; }` を追加（`onError` は現状維持でダイアログを開いたまま）。
  - 誤解を招く既存コメント（「onSuccess で閉じる必要はない」）を実態に合わせて修正。
- open→defaults 追従: `$effect` で `open` の false→true 遷移を検知し、`form.title` / `form.category` を
  現在の props から再設定する（`useForm` は再初期化しない＝既存の「マウント時 1 回初期化」方針は維持しつつ、
  open のたびに値だけ seed し直す）。
- `Manuals/Show.svelte` は変更不要（`bind:open` で親 `$state` を子から false にできる）。ただし波及の
  有無は詳細設計で確認する。

## 制約・前提

- Svelte 5 runes（`$props` / `$state` / `$bindable` / `$effect`）。
- Inertia `useForm` の `processing` はライブラリが管理する（成功/失敗いずれでも onFinish 相当で false に戻る）。
  独自の submitting フラグは追加しない（過剰実装回避＝禁止事項 6）。
- 禁止事項 8（必須未充足で disabled 化）に抵触しない: **`disabled` は `form.processing`（送信中）だけを
  理由に使い、入力未充足では一切使わない**。空タイトルでもボタンは有効なまま押下でき、押下時に
  サーバ/クライアントのエラーが表示される。既存テスト「送信ボタンは必須未充足でも disabled にしない」を
  壊さないこと。
- DESIGN.md / Atomic Design: 既存の atom/organism 構成（Button / Modal）をそのまま使い、新規 SVG や
  hex 直書きは追加しない。

## スコープ外

- サーバ側（`duplicate` エンドポイント・DTO・認可・複製ロジック）は変更しない（F-1-01 はフロント UX の不具合）。
- 複製の冪等化（サーバ側で二重 POST を弾く仕組み）は今回対象外。フロントの多重送信ガードで足りる範囲に留める。
- F-1-01 以外の bug-hunt findings（F-1-03 published シナリオ表示 等）は別タスク。
- Modal / Button atom 自体の汎用挙動変更は行わない。
