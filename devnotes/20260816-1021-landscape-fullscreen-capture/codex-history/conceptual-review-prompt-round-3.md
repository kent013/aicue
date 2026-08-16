# Round 3: Round 2 指摘への対応

Round 2 の [Warning] 2 件 (どちらも `ShootingGuideOverlay` の入力契約) と
[Suggestion] 2 件を反映しました。再判定をお願いします。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Warning] `ShootingGuideOverlay` の入力契約が本文と矛盾 (「`visible` だけを受ける」)

- 判断: **対応する** (指摘のとおり矛盾していた。Codex の推す「後者」を採る)
- 根拠: `GridOverlay` は表示する内容を持たない純粋な装飾なので `visible` だけで足りるが、
  撮影ガイドは**カットごとに変わる文字列**を表示する。同じ形に揃える、と書いたのは誤り。
  空文字列と非表示の 2 状態を子に持ち込むと、`SubtitleOverlay` が既に抱えている
  「`visible` かつ非空のときだけ描く」という内部判定を 1 つ増やすことになる。
  親 (`CameraRecorder`) は `layout === "fullscreen"` の判定を既に持っているので、
  表示可否の判定を親に集約するほうが状態の置き場所が減る。
- 対応内容: `ShootingGuideOverlay` の props を **`{ text: string }` の 1 つだけ**に確定し、
  「非空の `shooting_point` があり、かつ全画面のときだけ親が描画する」と明記した。
  実装方針の表から「`GridOverlay` と同じ `visible` だけを受ける薄い表示 component に揃える」
  という記述を削除した。あわせて `LayoutMode` union と props の型を概念設計に書いた。

## [Warning] 型安全性: props 未確定のため表示データの型契約を評価できない

- 判断: **対応する** (上と同一の修正で解消)
- 根拠: 同上。
- 対応内容: 概念設計に TypeScript の型契約を明示した
  (`LayoutMode` / `ShootingGuideOverlayProps` / `CutSwipeBar` の props)。
  サーバ側 DTO / JsonResource / PHPStan level 10 への影響が無いことは Codex も同意。

## [Suggestion] 面積の測定では「録画開始・停止操作が同時に viewport 内へ収まること」も受入値に含める

- 判断: **対応する**
- 根拠: 面積だけ増えても操作が折り返しの下に隠れていたら現行の問題は解けていない。
  「使命に沿った評価」という指摘は正しい。
- 対応内容: 期待効果の測定条件に、映像面積に加えて
  **「映像・カット名バー・録画開始/停止ボタンが同時に viewport 内へ収まる」**を追加した。

## [Suggestion] デスクトップの負のコントロールに「`pointer: coarse` 相当かつ高さ 540px 超」も加える

- 判断: **対応する**
- 根拠: 通常のデスクトップ context だけでは `pointer` 条件を実際に検証したことにならない
  (`max-height` 条件だけで落ちている可能性を排除できない = 空振りするテスト)。
  Playwright の context option (`hasTouch` / `isMobile`) で `pointer: coarse` 相当の
  context は作れるため、実現可能。
- 対応内容: テスト方針の Browser の項に、負のコントロールを 2 本
  (通常デスクトップ / タッチ対応かつ高さ 540px 超) にすると明記した。
  ハーネス側で `pointer: coarse` 相当の context を作れなかった場合の扱い
  (テストを緑にせず、実機受入確認の項目へ明示的に降ろす) も書いた。

## [Suggestion] 使命整合 / 禁止事項 / 実現可能性 / リスク / スコープの肯定的評価

- 判断: **見送る** (対応不要)
- 根拠: 指摘ではなく評価。


---

## 修正後の概念設計 (全文)

# 概念設計: landscape-fullscreen-capture (横持ち全画面撮影とカット間スワイプ)

## 背景・課題

`doc/05 §5.2 「撮影 UI (縦持ち / 横持ち)」` は撮影 UI に 2 つのモードを定めている。

> **横持ち（全画面）**: 上部カット名エリアを**左右スワイプで手順を前後移動**。録画制御は同様。
> 録画後は下部サムネイルをタップして即再生確認 → 再撮影も即開始可能。
> 撮影ガイド・字幕は透過オーバーレイで表示。

現行の `resources/js/pages/Capture/Show.svelte` は **レスポンシブな 1〜2 カラムのみ**である:

- `grid grid-cols-1 lg:grid-cols-2` の左右 pane。左 = `CutNavigator` の縦リスト、右 = 撮影パネル。
- 端末を横に倒しても**レイアウトは変わらない**。スマホ横持ち (例 844×390) では
  `lg` 未満なので 1 カラムのまま = 縦に長い画面を横長 viewport で見ることになり、
  カメラプレビュー (`aspect-video w-full`) が画面高さの大半を食って撮影ボタンが折り返しの
  下に押し出される。
- **カット移動の手段はリスト行のタップだけ**である。横持ちでリストへ戻るには
  `back-to-cut-list` で縦スクロールして一覧へ戻り、行をタップして再びパネルへ運ばれる、
  という往復が要る。現場で「手順 1 → 手順 2」と連続撮影する動線としては遠い。

**現場の作業者はカメラを構えたまま片手で操作する**。使命 (「思考ゼロ・編集ゼロ」) に照らすと、
撮る対象へカメラを向けたまま次の手順へ進めないことは、撮影判断以外の操作負荷を残している。

## 改善アイデア

横向き表示のときに撮影パネルを**全画面相当**へ切り替え、上部のカット名エリアを
**左右スワイプ / 前後ボタン / 左右矢印キー**の 3 手段で前後のカットへ移動できるようにする。
撮影ガイド (撮影方法 = `shooting_point`) と字幕は既存の overlay 機構に載せて映像へ重ねる。

### 中核となる 6 つの判断

#### D1. 全画面 API は使わない (CSS で全画面相当にする)

**判断: `Element.requestFullscreen()` / `webkitRequestFullscreen()` を使わない。**
`position: fixed; inset: 0` + `h-dvh` + z ramp + 背後スクロール抑止による **CSS 全画面**を
唯一の経路とする。

根拠:

1. **撮影 PWA の主戦場である iPhone の Safari は Fullscreen API を持たない**
   (`Element.requestFullscreen` / `webkitRequestFullscreen` とも非提供。
   iOS で全画面になれるのは `<video>` の `webkitEnterFullscreen()` = ネイティブプレイヤー UI
   だけで、これは live な `srcObject` プレビューの上に自前 overlay を重ねる用途に使えない)。
   `docs/supported-browsers.md` の対象ブラウザ方針でも iOS Safari が第一級である。
2. API 経路と CSS 経路を**両方**持つと、iOS では常に CSS 経路しか通らないため
   **API 経路が恒久的に未検証**になる。後方互換の並走を残さない (思考原則 3)。
3. PWA standalone (ホーム画面から起動) ではブラウザ UI が無いので、CSS 全画面がそのまま
   実質的な全画面になる。ブラウザタブで開いた場合はアドレスバーが残るが、
   **撮影に必要な面積は確保できており、機能の欠落ではない**。

「フォールバックまで設計する」というブリーフの要求に対する答えは
**「フォールバックを主経路に昇格させ、分岐そのものを持たない」**である。

#### D2. 横持ち判定は「向き」だけで決めない

`(orientation: landscape)` だけで切り替えると、**デスクトップ PC も常に landscape** なので
2 カラムの既存レイアウトが失われる。判定は次の合成条件を 1 か所 (`lib/capture/` の純関数) に置く:

```
(orientation: landscape) かつ (max-height: 540px) かつ (pointer: coarse)
```

- `max-height` … 横持ちスマホの短辺 (iPhone SE 320 / 15 Pro 393 / 大型 Android 412) を含み、
  タブレット横持ち (iPad 768) とノート PC は含まない高さ。
- `pointer: coarse` … 指で操作する端末に限定する (スワイプ操作を前提にした UI のため)。
- 判定は `matchMedia` の変化購読で行い、**Tailwind の breakpoint 値をコピーしない**
  (既存 `isStackedLayout` が座標実測で二重管理を避けているのと同じ思想。
  ここは座標では表現できない条件を含むため media query 文字列そのものを唯一の正本とする)。

#### D3. 全画面の切替は「CSS class の切替だけ」で行う (最重要の不変条件)

`CameraRecorder` は `idle / recording / paused / stopping` の phase マシンと
`MediaStream` / `MediaRecorder` / タイマー / pause-resume の in-flight ガードを
**インスタンス内部に**持つ。向きが変わるたびに component を別ブランチへ描き直すと
**unmount → 録画中の stream と recorder が破棄され、録ったデータが消える**。

したがって:

- 全画面かどうかで **`CameraRecorder` の DOM 上の位置 (component tree の位置) を変えない**。
  ラッパ要素の `class` と、`CameraRecorder` 内部の映像要素の `class` だけを差し替える。
- 追加要素 (上部カット名スワイプバー・撮影ガイド overlay・全画面終了ボタン) は
  `{#if}` で足すが、`CameraRecorder` 自身はその `{#if}` を跨がない。
- **既存の phase マシンには一切手を入れない**。`CameraRecorder` への追加は
  表示用の props (レイアウト種別・撮影ガイド文言) のみで、状態遷移に触れない。

これを設計の不変条件として明文化し、テストで固定する
(向き変更の前後で `video` 要素の同一性が保たれること)。

#### D4. 録画中のカット移動は「止めてから移動する」— ただし自動では止めない

**判断: 録画中 (`captureActive`) はカットを移動せず、押下/スワイプ時にその場でエラーを表示する。
自動停止もしない。**

- 自動停止は採らない。誤スワイプで**録画が確定してしまう**のは現場で取り返しがつかない。
  既存 `CameraRecorder.releaseForPreview()` が録画中は no-op (= 暗黙終了しない) という
  確立済みの契約とも一致する。
- 移動禁止でボタンを `disabled` にはしない (AGENTS.md 禁止事項 8)。押下時に
  「録画中はカットを移動できません。録画を停止してから移動してください。」を出す。
- **行き先のない詰みを作らない**: この文言が指す「録画停止」ボタンは全画面上でも
  常時可視である (既存 `CameraRecorder` の停止ボタンをそのまま全画面に出す)。
  つまり**告知された次の操作が同じ画面上に必ず存在する**。
- 抑止対象は `captureActive` = `starting || resuming || phase !== "idle"`。
  getUserMedia の grant 待ち 2 窓を含むので、権限ダイアログ中の移動も起きない
  (既存 `panel-navigation.ts` の抑止条件と**同じ判断基準を再利用**する)。

#### D5. カットのラベル導出は既存の唯一の正本を共有する

`手順 N` / `急所 N-M` は `lib/capture/cut-labels.ts` の `buildCutLabels()` が唯一の導出元で、
すでに `CutNavigator` の行ラベル・撮影パネル見出し・テイクプレビューの
アクセシブルネームが共有している。**全画面のカット名バーも同じ関数の結果を使う**
(ラベル文字列を新しく組み立てない)。

移動の対象順序も `manual.cuts` の配列順そのもの (= `CutNavigator` の表示順) で、
別のソート規則を導入しない。

#### D6. 撮影ガイド・字幕は既存 overlay 機構に載せる

- **字幕**: 既存 `SubtitleOverlay` をそのまま使う (`CameraRecorder` が既に描画している)。
  全画面でも同じ component が同じ位置に載るため、追加実装はゼロ。
- **グリッド**: 既存 `GridOverlay` をそのまま使う (同上)。
- **撮影ガイド (撮影方法)**: 現行は右 pane の本文中に「撮影ポイント: …」と平文で出ている。
  全画面では映像が面積を占めるので、`shooting_point` を **透過オーバーレイ**として
  映像上に重ねる。z 順は `映像 < グリッド < 撮影ガイド < 字幕帯` とし、
  **字幕を最優先で可読**にする (v1 の中核価値が字幕であるため)。
  縦持ち (既存レイアウト) では従来どおり本文表示のままにし、二重に出さない。
  **表示可否の判定は親 (`CameraRecorder`) に置く**。`ShootingGuideOverlay` は
  `text: string` の 1 props だけを受け、「非空 かつ 全画面」のときだけ親が描画する。
  `GridOverlay` の `visible` 形には**揃えない** —
  グリッドは内容を持たない装飾だが撮影ガイドはカットごとに変わる文字列であり、
  「空文字列」と「非表示」の 2 状態を子に持ち込む理由が無いためである。

### TypeScript の型契約 (新規に増える型はこれだけ)

```ts
/** 撮影パネルのレイアウト種別。既存 Phase union と同じ先例に揃える */
type LayoutMode = "inline" | "fullscreen";

/** 撮影ガイド overlay。表示可否は親が決めるので nullable にしない */
type ShootingGuideOverlayProps = { text: string };

/** 上部カット名バー。ラベル文字列は buildCutLabels() の結果を受け取るだけ */
type CutSwipeBarProps = {
    label: string;               // 例: "手順 2" / "急所 2-1"
    scene: string;               // カット内容 (CutNavigator と同じ出所)
    hasPrevious: boolean;        // 端の告知に使う (ボタンの disabled には使わない)
    hasNext: boolean;
    onNavigate: (direction: -1 | 1) => void;
};
```

サーバ側の型 (`CaptureCut` / DTO / JsonResource) は**一切変わらない**。

### 操作の設計 (アクセシビリティを含む)

| 手段 | 前のカット | 次のカット |
|---|---|---|
| スワイプ (カット名バー上) | 右へスワイプ | 左へスワイプ |
| ボタン (カット名バーの左右) | 「前のカット」 | 「次のカット」 |
| キーボード (バーにフォーカス時) | `ArrowLeft` | `ArrowRight` |

- **スワイプだけにしない**。スワイプはキーボード・スクリーンリーダー利用者に到達不能で、
  手袋を着けた現場作業者にも失敗しやすい。ボタンとキー操作を同格の第一手段として置く。
- **端 (最初 / 最後) でも前後ボタンは押下可能にする**。移動先が無いことを理由に
  `disabled` にしない (AGENTS.md 禁止事項 8)。押下・スワイプ・キー操作のいずれでも
  「これが最初のカットです」/「これが最後のカットです」を `role="status"` で短く伝える
  (無反応にすると故障と区別できない)。告知文の出所は 1 か所に集約し、
  3 つの操作手段で同じ文言を共有する。
- 端末の**戻るジェスチャとの競合**を避けるため、画面左右端から始まったスワイプは
  カット移動として扱わない (iOS Safari の edge swipe back は JS から抑止できない)。

### 全画面への出入り

- 横持ち判定が真になった時点で全画面へ入る。このとき `selectedCutId` が未選択なら
  **先頭カットを自動選択する** (空の全画面 = 詰みを作らない)。
- 全画面には「全画面を終了」ボタンを常時置く。押すと横持ちのまま既存レイアウトへ戻る。
- 一度手動で終了したら、**縦に戻すまで自動で全画面へ入り直さない** (ラッチ)。
  ユーザーの明示的な意思を向き変化で上書きしない。
- **ラッチには手動の再入路を必ず対にする**。既存レイアウト側に「全画面で撮影」ボタンを置き
  (横持ち判定が真のときだけ表示)、押すとラッチを解除して全画面へ入る。
  これが無いと「端末を一度縦に倒し直さないと全画面へ帰れない」という行き止まりになる。
- **終了後の面が痩せていないこと**を受入条件にする。終了直後は既存の
  `navigateToPanelIfNeeded` (視点とフォーカスの移送) を再利用して撮影パネル見出しへ運び、
  横持ち 1 カラムのままでも次の 4 つがすべて到達可能であることを固定する:
  録画の開始/停止 / テイク操作 (`TakeStrip`) / カット一覧への復帰 / マニュアル詳細への復路。
- 全画面中は背後ページのスクロールを抑止し、離脱時 (終了ボタン / 縦復帰 / ページ離脱) に
  必ず解除する。解除漏れは「スクロールできない詰み」になるため、
  解除は単一のクリーンアップ点に集約する。

## 期待効果

- **使命への貢献**: 「撮影判断を AI が肩代わりする」ナビ撮影で、作業者に残る操作は
  「向ける・録る・次へ」の 3 つだけになる。カット移動のために画面をスクロールして
  一覧へ戻る往復が消え、シナリオの順に連続撮影できる (doc/05 が想定した動線への到達)。
- **要件ギャップの解消**: doc/05 §5.2 の横持ち要件のうち「全画面」「上部カット名エリアの
  左右スワイプ」「撮影ガイド・字幕の透過オーバーレイ」の 3 点を満たす。
- **画面面積**: 横持ちスマホで映像の表示面積が拡がり、構図 (引き / 寄り) の判断がしやすくなる。
  **倍率は端末差が大きいので概念設計では断定しない**。代表 viewport
  (iPhone 15 Pro 横 = 852×393 / iPhone SE 横 = 568×320) での実測値は詳細設計で確定する。
  測定の受入値は**面積だけにしない** — 「映像・カット名バー・録画開始/停止ボタンが
  **同時に viewport 内へ収まる**」ことを併せて条件にする
  (面積が増えても操作が折り返しの下に隠れていたら現行の問題は解けていないため)。
- **既存資産の再利用**: `SubtitleOverlay` / `GridOverlay` / `buildCutLabels` /
  `UploadQueueBar` / `CameraRecorder` の phase マシンをそのまま使うため、
  新規の状態機械を 1 つも増やさない。

## 実装方針（概要）

| # | 変更対象 | 内容 |
|---|---|---|
| 1 | `resources/js/lib/capture/landscape-capture.ts` (新規) | 横持ち判定の media query 文字列と購読、スワイプ判定の純関数、カット移動先の解決 (端の扱い含む)。副作用ごとここに置き、page からは配線だけ行う (`panel-navigation.ts` と同じ設計) |
| 2 | `resources/js/components/features/capture/CutSwipeBar.svelte` (新規) | 上部カット名エリア。ラベル表示 + 前後ボタン + スワイプ/キー操作の受け口。ラベルは `buildCutLabels` の結果を props で受ける (自前で組み立てない) |
| 3 | `resources/js/components/features/capture/ShootingGuideOverlay.svelte` (新規) | `shooting_point` の透過オーバーレイ。props は **`text: string` の 1 つだけ**で、表示可否 (非空 かつ 全画面) は親が判定する |
| 4 | `resources/js/components/features/capture/CameraRecorder.svelte` | 表示用 props を 2 つ追加 (レイアウト種別 / 撮影ガイド文言)。レイアウト種別は string ではなく **union 型 (`"inline" \| "fullscreen"`)** で受ける (既存 `Phase` union と同じ先例)。**phase マシン・stream 管理には触れない** |
| 5 | `resources/js/pages/Capture/Show.svelte` | 全画面ラッパの class 切替、カット移動ハンドラ (`captureActive` 抑止 + エラー表示)、全画面の出入りとラッチ + 手動再入ボタン、背後スクロール抑止 |

**サーバ側 (PHP) の変更は無い**。`CaptureCut` はすでに `shooting_point` / `subtitle_primary` /
`subtitle_secondary` を持ち、Inertia props の形も変わらない。
DTO / JsonResource / route / migration のいずれも触らない。

### テスト方針 (概要)

- **vitest (純関数)**: 横持ち判定・スワイプ判定 (しきい値・縦方向の除外・端からの開始の除外)・
  移動先解決 (端の扱い)。
- **vitest (component)**: `CutSwipeBar` のラベル表示・前後ボタン・キー操作・端の告知。
  `Show.svelte` の配線 = 録画中の移動抑止とエラー表示、未選択時の先頭自動選択、
  全画面切替で `video` 要素が同一のままであること (D3 の不変条件)。
- **Browser (Chromium + WebKit の 2 レーン)**: 横持ち viewport で全画面レイアウトになること、
  前後ボタンでカットが移動すること、終了ボタンで既存レイアウトへ戻り「全画面で撮影」ボタンから
  戻れること。さらに**負のコントロールを 2 本**置く:
  (a) 通常のデスクトップ横長 viewport で既存 2 カラムが維持されること、
  (b) **タッチ対応 (`pointer: coarse` 相当) かつ高さ 540px 超**の context でも
  既存 2 カラムが維持されること。(b) が無いと `max-height` 条件だけで落ちている可能性を
  排除できず、`pointer` 条件を検証したことにならない。
  ハーネス側で `pointer: coarse` 相当の context を作れないと分かった場合は、
  **テストを緑にせず**、その項目を実機受入確認 (タッチ対応 PC) へ明示的に降ろす。
  **WebKit レーンは落とさない** (`docs/testing-browser.md` / AGENTS.md ドメイン規約 3)。
- **実機でしか確認できない項目**は `docs/supported-browsers.md` の実機受入確認の作法に倣い、
  「何を実機で確認する必要があるか」を詳細設計に列挙して残す
  (**この設計では TODO を起票しない**)。

## 制約・前提

- **フロント規約**: Svelte 5 runes + DS token のみ。`z-` は ramp (`z-0/10/20/30/40/50`) のみで
  arbitrary z-index は禁止 (`ds-purity`)。静的 inline style も禁止。
  アイコンは `@lucide/svelte` のみ。
- **component 階層**: 新規 component は `components/features/capture/` に置く
  (`atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。
  `features/capture` から `atoms` / `organisms` は import 可、逆流不可)。
- **禁止事項 8**: 必須条件未充足を理由にボタンを `disabled` にしない。録画中のカット移動も
  「押せるがエラーを出す」で表現する。
- **既存契約**: `page-shell-structure.test.ts` の `PAGECONTENT_ALLOWLIST` に
  `Capture/Show.svelte` が登録済み (理由文は現行の 2 カラム構成を説明している)。
  構成が変わるので理由文の更新が要る。
- **セキュリティ**: 本改善は表示層のみで、認可・テナント境界・LLM 経路・課金に触れない。
  `docs/architecture.md` §撮影 PWA の運用契約 (no-store / bfcache 秘匿 / Inertia 履歴暗号化の
  3 枚セット) にも影響しない (新しいログアウト導線も非 Inertia 経路も作らない)。

## スコープ外

- **下部テイクサムネイルからの即再生 / 即再撮影** (doc/05 §5.2 の後半)。
  `TakeStrip` は preview ダイアログと `CameraRecorder` の資源競合制御 (`captureActive` /
  `releaseForPreview` / `resumeAfterPreview`) を伴い、全画面へ持ち込むと
  「全画面 × ダイアログ × カメラ解放」の 3 者が絡む。今回は**全画面を終了すれば
  既存のテイク操作面へ必ず到達できる**ことをもって行き止まりを作らない設計とし、別タスクにする
  (思考原則 2: 今必要なものだけ作る)。
- **画面の向きのロック** (`screen.orientation.lock()`)。iOS Safari は非対応で、
  Android でも全画面 API 下でしか効かない。D1 で全画面 API を採らない以上、手段が無い。
- **ナレーション試聴 (TTS)**。v1 スコープ外 (AGENTS.md: 字幕のみ / TTS 後回し)。
- **PC ブラウザでの全画面撮影**。`pointer: coarse` の条件から外れる。
  PC は既存の 2 カラムのままで要件を満たしている。
- **サーバ側の変更全般** (DTO / JsonResource / route / migration / PHPStan 対象コード)。

