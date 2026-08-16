全体判定: **CHANGES_REQUESTED**

## 施策 A: REQUEST_CHANGES

[Warning] `subscribeLandscapeCapture()` が `addEventListener` のみ前提です。設計内では Safari 13.x を対象外としていますが、`docs/supported-browsers.md` の対象範囲と実際に一致しているかが設計書上だけでは閉じていません。  
修正案: 対象ブラウザの最低バージョンをこの設計内で明示し、`supported-browsers` 側の記述と同期する。古い Safari を明示的に捨てるならテスト名にも「legacy MediaQueryList は非対応」と残す。

[Suggestion] `resolveSwipe()` は `viewportWidth <= SWIPE_EDGE_EXCLUSION_PX * 2` の異常値で常に右端除外相当になります。実害は小さいですが、テストに極小 viewport を 1 件入れると仕様が固定できます。

## 施策 B: REQUEST_CHANGES

[Warning] `CutSwipeBar` のルートが `<div role="group" tabindex="0">` でキーボードイベントを持ちますが、内部に前後ボタンもあります。フォーカス可能な group と内部 button が重なり、Tab 順が増えて操作が冗長になります。  
修正案: 矢印キー操作を本当に必要とするなら `aria-label` 付きの `region`/`toolbar` 相当として整理し、テストで Tab 順とボタン操作を確認する。もしくはキーハンドラを親の全画面 container に寄せ、バー自体をフォーカス対象にしない。

[Warning] `window.innerWidth` を `handlePointerUp()` 内で直接読んでいます。SSR では到達しない前提ですが、component テストや将来の非ブラウザ実行で壊れやすいです。  
修正案: `typeof window === "undefined" ? 0 : window.innerWidth` にするか、`viewportWidthProvider` を内部関数化してテストで固定する。

## 施策 C: REQUEST_CHANGES

[Critical] `ShootingGuideOverlay` の `class="... max-w-[90%] ..."` は設計書が引用している ds-purity の禁止対象とは限らない一方、同じ設計内で「任意値の w-/max-w- は禁止対象に含まれない」としています。しかしレビュー観点には「design token 経由」があり、`max-w-[90%]` は token ではありません。既存 `SubtitleOverlay` にあるから新設可、とはならない可能性があります。  
修正案: 既存の許容パターンとして architecture test が明示的に通すならその根拠を書く。そうでなければ `max-w-full` + `mx-*` / container 側 padding など token/utility の既存許容範囲に寄せる。

[Warning] `shootingGuideText = (shootingPoint ?? "").trim()` を描画にも渡しており、コメントの「描画には元文字列を渡す」と矛盾しています。  
修正案: 空判定用を `const hasShootingGuide = $derived((shootingPoint ?? "").trim() !== "")` にし、描画は `text={shootingPoint ?? ""}` にする。

[Warning] 全画面時の操作行が `absolute bottom-0` で背景なしです。設計内でも可読性リスクを認識していますが、自動テストにも受入基準にも落ちていません。  
修正案: 初期設計から token 背景 (`bg-surface/90` 等、既存 DS で許容されるもの) を持たせるか、実機受入の必須合格条件として明文化する。

## 施策 D: REQUEST_CHANGES

[Critical] `fullscreenActive` が `selectedCut !== null` に依存し、横持ち時の auto select effect が後から `selectedCutId` を書くため、初回描画で一瞬 inline レイアウトが出ます。Browser テストが最終状態だけを見ると見逃します。  
修正案: `landscapeMatches && !fullscreenDismissed && (selectedCut !== null || manual.cuts.length > 0)` のように「全画面に入る意図」を先に立てるか、初期選択完了まで placeholder を出す設計にする。少なくともちらつき・背後 Tab 侵入のテスト観点を追加する。

[Critical] `enterFullscreen` ボタンの可視条件が `landscapeMatches && !fullscreenActive` なので、カット 0 件でも表示され、押しても何も起きません。設計自身が「押しても何も起きない」を避けると言っているため矛盾です。  
修正案: `landscapeMatches && !fullscreenActive && manual.cuts.length > 0` にする。カット 0 件の Browser/component テストを追加する。

[Warning] `inert={fullscreenActive}` を PageHeader wrapper と left pane に付けていますが、`capture-grid` 自体や PageContainer 全体は inert ではありません。固定 overlay の外側に残る DOM へフォーカスが漏れる可能性があります。  
修正案: 背後領域を 1 つの wrapper にまとめて `inert` を付ける。全画面 section は wrapper の外へ置く構造にするか、Tab 移動テストを追加する。

[Warning] `navigationNotice` がカット移動以外で消えません。スワイプで端 notice を出した後、選択や録画開始など別操作で古い notice が残る可能性があります。  
修正案: `selectedCutId` 変更時、`fullscreenActive` false 化時、録画開始 active 化時に notice を clear する effect を追加する。

## 施策 E: REQUEST_CHANGES

[Warning] 「録画中の抑止はページテストでは固定しない」とありますが、ページが `captureActive` を `decideCutNavigation` へ渡していることをテストしないと、配線ミスを見逃します。  
修正案: `CameraRecorder` を mock/stub して `onCaptureActiveChange(true)` を発火できるページテストを追加し、全画面の次/前ボタンで alert が出て選択が変わらないことを確認する。

[Warning] Browser の負のコントロールは有効ですが、`pointer: coarse` の判定は Playwright context の設定依存です。  
修正案: テスト内で `matchMedia('(pointer: coarse)').matches` と対象 media query の評価結果を assertion し、前提が崩れたら原因が分かるようにする。

## 施策 F: APPROVE

[Suggestion] `docs/supported-browsers.md` への追記は妥当です。ただし施策 A の Safari 対象外判断と同じ節で読めるよう、MediaQueryList / `inert` / `h-dvh` の最低バージョン前提をまとめるとレビューしやすいです。

## まとめ

設計の方向性は妥当です。特に「録画中は自動停止しない」「CameraRecorder を remount しない」「判定ロジックを純関数化する」は良い判断です。

ただし、現状はカット 0 件の再入ボタン、`shootingPoint` の trim 矛盾、録画中 navigation のページ配線未テストがそのまま実装に入ると回帰を見逃します。上記 Critical/Warning を直せば、実装に進める設計になります。