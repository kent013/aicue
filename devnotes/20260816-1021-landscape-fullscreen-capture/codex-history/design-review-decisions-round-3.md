# 対応マトリクス: design-review Round 3

## [Critical] 施策 D: `selectedCutId` の初期化が `initialLandscape` を宣言前に参照している

- 判断: **対応する** (指摘のとおり TDZ / TS エラーになる)
- 根拠: 「定義はこの行より前に置く」とコメントで書いただけで、
  提示コードの並びは直っていなかった。設計書は実装者がそのまま写す前提なので、
  並び順まで正しく示す必要がある。
- 対応内容: `$props()` の直後に `initialLandscape` を置き、その次に `selectedCutId`、
  さらに後の横持ち節では `let landscapeMatches = $state(initialLandscape);` だけを置く、
  という**宣言順を明示した 1 ブロック**に書き直した (再宣言しない)。

## [Warning] 施策 C: 撮影ガイドと primary 字幕が同じ上端レーンで重なる

- 判断: **対応する** (指摘は正しい。中核の構図指示が読めなくなるのは致命的)
- 根拠: `SubtitleOverlay` は `absolute inset-0 flex flex-col justify-between p-3` で、
  primary は**上端行**、secondary は**下端行**にある。
  `ShootingGuideOverlay` を `top-2` に置くと primary と同じ帯を奪い合う。
  DOM 順で字幕が上なので、**撮影ガイドが字幕に隠れる**。
- 対応内容: 撮影ガイドのレーンを **`top-1/3` (三分割の上ライン)** へ移した。
  - 上端帯 (primary、`p-3` + `line-clamp-2`) と下端帯 (secondary、`line-clamp-3`) の
    **どちらとも交差しない中間帯**である。横持ち 390px 高の stage で、
    primary の下端は概ね 68px、guide は 130px から始まり ~186px で終わり、
    secondary の上端は概ね 294px。余裕をもって非交差になる。
  - 三分割線に沿うのは**構図指示として意味がある**位置でもある
    (`GridOverlay` が同じ `top-1/3` に線を引いている)。
  - 「非交差」を主張だけで終わらせないため、**Browser テストで矩形の非交差を assert する**
    (両方とも非空のカットで `shooting-guide-overlay` と `subtitle-primary` の
    `getBoundingClientRect()` が交差しないことを見る)。jsdom はレイアウトを持たないので
    この検査は Browser レーンにしか置けない。

## [Warning] 施策 B: 二重発火テストが `pointerup` だけではブラウザの後続 `click` を再現できない

- 判断: **対応する**
- 根拠: 指摘のとおり、jsdom / Testing Library の pointer event は `click` を合成しない。
  `pointerup` だけのテストは「二重発火しないこと」を証明できていない
  (そもそも 1 回しか起きない条件で緑になる = 空振り)。
- 対応内容: テストで **`pointerdown` → (48px 移動した) `pointerup` → `click` を明示的に発火**し、
  `onNavigate` の呼び出しが**合計 1 回**であることを固定する。
  あわせて **`event.target` がボタン内の Lucide アイコン要素であるケース**も 1 件足し、
  `closest("button")` による除外が子孫からでも効くことを直接固定する。

## [Warning] 施策 D: SSR に関する「将来も安全側の縮退」という主張

- 判断: **対応する** (主張を撤回する)
- 根拠: 指摘のとおり、hydration 不一致は「安全」とは言えない
  (サーバが inline、クライアント初期評価が fullscreen なら DOM 構造が食い違う)。
  保証範囲を誇張しないという本リポジトリの作法にも反する。
- 対応内容: 「将来 SSR を入れても安全側の縮退になる」という文を削除し、
  **「現状 SSR は配線されていない。この初期判定方式はその前提に依存する」**と書き換えた。
  そのうえで **再確認条件**を 1 行足した — 「Inertia SSR を導入する PR は、
  この初期判定方式 (`$state` の初期値を `matchesLandscapeCapture()` で決める) を
  再設計しなければならない」。

## [Warning] 施策 E: `MutationObserver` の記録取りこぼし

- 判断: **対応する**
- 根拠: callback は microtask で通知されるため、`render()` 直後に同期で `disconnect()` すると
  記録が届かないことがある。取りこぼすと**テストが常に緑になる** (最悪の空振り)。
- 対応内容: assertion の前に **`observer.takeRecords()` で保留分を回収**してから判定し、
  さらに microtask を 1 回進めてから `disconnect()` する。
  探索は `addedNodes` 自身だけでなく**その子孫**も見る
  (`node.querySelector('[data-testid="capture-recording-heading"]')`)。

## [Warning] 施策 F: `docs/supported-browsers.md` の追記文に版番号を複製している

- 判断: **対応する**
- 根拠: 「正本は詳細設計」と書きながら `14 / 15.4 / 15.5` を写しており、自己矛盾していた。
- 対応内容: 追記文から具体的な版番号を削除し、**節名への参照だけ**にした。

## 施策 A: APPROVE

- 判断: **対応不要**。
