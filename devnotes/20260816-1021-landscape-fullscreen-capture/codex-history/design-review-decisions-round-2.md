# 対応マトリクス: design-review Round 2

## [Critical] 施策 C: 変更後 markup が存在しない `shootingGuideText` を参照している

- 判断: **対応する** (単純な取り残し。指摘のとおり `pnpm typecheck` が落ちる)
- 根拠: Round 1 の対応で `shootingGuideText` を `hasShootingGuide` へ置き換えたのに、
  markup 側の `text={shootingGuideText}` を直し忘れていた。
- 対応内容: `<ShootingGuideOverlay text={shootingPoint ?? ""} />` に修正。
  これで「trim は空判定にのみ使い、描画には元文字列を渡す」契約とも一致する。

## [Critical] 施策 D: 初回ちらつきは解消されていない (`$effect` は初期 DOM 構築後に走る)

- 判断: **対応する** (指摘は正しい。同期化は effect 実行**後**の中間描画しか消していなかった)
- 根拠: Svelte 5 の `$effect` はマウント後に走るため、
  最初の描画時点では `landscapeMatches === false` のままである。
- 対応内容: **`$state` の初期値を `matchesLandscapeCapture()` にする**。
  component の script はテンプレートの初回描画**前**に評価されるので、
  最初に描かれる DOM が既に全画面になる (中間描画そのものが存在しなくなる)。
  - Codex が懸念した hydration 不一致は、**このリポジトリでは発生しない**。
    Inertia SSR は配線されていない — `config/inertia.php` が無く、
    `resources/js/ssr.*` エントリも `vite.config` の ssr build も
    `inertia:start-ssr` の起動も存在しない。`resources/js/app.ts` の
    `el.dataset.serverRendered === "true"` 分岐は防御的に置かれているだけで、
    SSR サーバが無い以上この属性が真になる経路が無い。
  - それでも将来 SSR を入れたときに壊れないよう、`matchesLandscapeCapture()` は
    `typeof window === "undefined"` で **false (= 既存レイアウト)** に倒す既存の実装のまま使う。
    SSR 時の初期 HTML は inline レイアウトになり、クライアント側の最初の描画で
    全画面へ移る = **安全側の縮退**であり、開示や詰みを生む向きには倒れない。
    この前提を設計へ明記した。
  - 購読の `$effect` は**変化の追従だけ**を担う形に整理し、
    初期値の決定 (script 評価時) と変化の追従 (effect) の責務を分けた。

## [Critical] 施策 D: D-3b の告知消去が D-4 の `CameraRecorder` 呼び出しに反映されていない

- 判断: **対応する**
- 根拠: 指摘のとおり D-4 が `onCaptureActiveChange={(active) => (captureActive = active)}` の
  ままで、設計書内で自己矛盾していた。テスト計画にある「停止後に録画中エラーが消える」も
  この形では通らない。
- 対応内容: D-4 の呼び出しを D-3b の block callback に統一した。
  あわせて D-3b の説明を「D-4 の該当箇所がこの形であること」と読める書き方に直した。

## [Warning] 施策 B: 前後ボタン上のドラッグでスワイプと click が二重発火しうる

- 判断: **対応する**
- 根拠: ボタンの上で `pointerdown` → 48px 以上動かして `pointerup` すると、
  親の `pointerup` ハンドラが移動を起こし、その後ブラウザが `click` を出せば
  ボタンの `onclick` でもう一度移動する。**2 カット進む**という実害がある。
  (ブラウザはポインタが要素外へ出ると `click` を出さないが、
  ボタンが十分大きい場合や斜めの動きでは同一要素内に留まりうる。)
- 対応内容: `handlePointerDown()` で
  `(event.target as Element | null)?.closest("button") !== null` なら gesture を開始しない
  (= ボタンの上で始まった操作は**ボタンの責務**として扱う)。
  「ボタン上でドラッグしても `onNavigate` が 1 回しか呼ばれない」component テストを追加した。

## [Warning] 施策 D: 録画中に props 更新で選択中カットが消えると `CameraRecorder` が unmount される

- 判断: **一部対応する + 保証範囲の主張を撤回する**
- 根拠: 指摘のとおり、`{#if selectedCut === null}` の外側にいる限り
  選択が消えれば `CameraRecorder` は unmount される。
  ただしこれは**本設計が新設した経路ではなく、現行 `Show.svelte` に既に在る挙動**である
  (現行も `reloadManual()` は録画中に走りうる: `handleOnline` → `runAutoDownload` →
  `changed` なら reload)。本設計で新たに悪化する点は無い。
  「reload で選択中カットが消えたときの出口」を売りにすると、
  **録画データが保護されているかのように読める** = 保証範囲を広げすぎている。
- 対応内容:
  1. 終了ボタンを `fullscreenActive` の直下に置く配置は**そのまま維持する**
     (出口が消えないこと自体は正しい。ただし理由づけを「選択が消えても出口が残る」から
     「出口を選択状態に依存させない」へ言い換える)。
  2. 「reload で選択中カットが消える」ケースを**録画データ保護の文脈で語らない**。
     リスク節に「これは本設計が持ち込んだものではない既存の挙動であり、
     本設計は改善も悪化もさせない」と明記し、別タスクの候補として残す
     (**TODO は起票しない**)。
  3. 不変条件 1 の主張範囲を「**向きの変化に伴う切替では** `CameraRecorder` を remount しない」
     と限定した (「いかなる場合も remount しない」ではない)。

## [Warning] 施策 E: 初回ちらつきは最終状態の assertion では検出できない

- 判断: **対応する**
- 根拠: 指摘のとおり。最終状態だけ見るテストは、`$effect` で状態を入れる実装でも緑になる。
- 対応内容: 2 段で固定する。
  1. **同期 assertion**: `render()` の直後、`await tick()` を挟まずに
     `data-fullscreen === "true"` を見る。`$state` 初期値で決めていれば真、
     `$effect` で入れる実装なら偽になる = **fail-first が成立する**。
  2. **「一度も現れない」の観測**: `render()` の**前**に `document.body` へ MutationObserver を
     張り、`capture-recording-heading` (= inline レイアウト固有の要素) が
     一度も追加されないことを固定する。中間描画があれば必ず捕まる。

## [Warning] 施策 E: Browser の media query assertion は正負で期待値が違う

- 判断: **対応する**
- 根拠: 「評価結果を assert する」だけでは期待値が書かれておらず、テストの意図が曖昧。
- 対応内容: ケースごとの期待値表を書いた。
  正のケース = 対象 query `true` / `(pointer: coarse)` `true`。
  負 1 (desktop) = 両方 `false`。負 2 (mobile + 高さ 900) = 対象 `false` / coarse `true`。
  負 3 (desktop + 844×390) = 対象 `false` / coarse `false`。
  **どの条件で落ちているのかが失敗メッセージから分かる**形にした。

## [Warning] 施策 D: Tab テストの期待値は `AppLayout` chrome への到達を許容する

- 判断: **対応する**
- 根拠: 不変条件 6 で「chrome は覆わない」と決めた以上、テストの期待値もそう書くべき。
  「どこへも到達しない」と書くと不変条件 6 と矛盾する。
- 対応内容: テスト計画を「`cut-row-*` / `manual-detail-link` へ到達しない
  (= page 自身のコンテンツは覆われている)。`AppLayout` の chrome への到達は**許容する**」
  と明記した。

## [Suggestion] 施策 A: バージョン表の「9 以前 / 全版」という表現が曖昧

- 判断: **対応する**
- 根拠: 「対応開始バージョン」を書く表に「9 以前」「全版」が混ざると読み手が迷う。
- 対応内容: 該当行を「**本設計の対象版 (iOS Safari 15.5 以降 / Android Chrome 108 以降) では
  対応済み**」という表現へ統一した。表の見出しは施策 F からの参照先なので**変えない**。

## [Suggestion] 施策 B: PHPStan 適合チェックの契約文に `tabindex` が残っている

- 判断: **対応する**
- 根拠: 実装から `tabindex` を外したのにチェック項目が古いままだった。
- 対応内容: 「`role="group"` + `aria-label` を持ち、**`tabindex` を持たない**
  (Tab 停止は内側の 2 ボタンだけ)」へ書き換えた。

## 施策 A / F: APPROVE

- 判断: **対応不要** (Round 2 で APPROVE)。
