# 概念設計: capture-subtitle-overlay（撮影中カメラプレビューへの字幕オーバーレイ表示）

## 背景・課題

ユースケース・カバレッジ監査ギャップ #2（High）。

- **仕様**: `doc/05 §5.2` の撮影 UI は「**字幕表示**: 録画ボタン右の字幕アイコンで、カメラ映像上に字幕を重畳（構図確認用。再タップで非表示）」を主要機能として掲げる。横持ち全画面でも「撮影ガイド・字幕は透過オーバーレイで表示」とある。
- **v1 の中核価値**: 「字幕のみ（TTS 後回し）」。字幕は AI-CUE のマニュアル動画の中核成果物であり、撮影者が**字幕の載る位置を構図に織り込める**ことは撮影品質（フレーミング）を直接左右する。
- **現状の欠落**: `resources/js/components/features/capture/CameraRecorder.svelte` には字幕（subtitle）描画が一切無い（`grep subtitle` = 0 件）。完成動画への字幕**焼込**は `app/Services/Render/AssSubtitleWriter.php`（ASS 字幕生成）が担うが、これは合成後の話であり、**撮影中のライブプレビュー上のガイド表示**が存在しない。撮影者は「どこに字幕が出るか」を撮影時に確認できない。

**仮説**: カメラプレビュー領域に当該カットの字幕を（焼込ではなく）ガイドとして重畳すれば、撮影者は**字幕とかぶらない構図判断を支援**できる。成功判定 = (1) subtitle prop に応じてオーバーレイ要素が出/非表示になる、(2) 撮影者が字幕占有領域を事前認識できる（primary=上部帯 / secondary=下部メインの位置に表示される）、(3) vitest / typecheck / lint / build が green。

## 改善アイデア

撮影中（`CameraRecorder` のカメラプレビュー領域）に、**選択中カット（selectedCut）の字幕**（`subtitle_primary` / `subtitle_secondary`）を重畳表示する**字幕オーバーレイ・レイヤー**を追加する。

- **焼込はしない**。あくまで撮影ガイドの overlay 表示（録画される blob には一切影響しない。overlay は DOM 上の別レイヤーで、`MediaRecorder` は `stream` を録るため映像に混入しない）。
- 字幕データは**既に cut の prop 経由で供給されている**（`Capture/Show.svelte` の `selectedCut` → `CutNavigator` と同じ源）。**バックエンド変更は不要**。`CaptureCut` 型は既に `subtitle_primary: string | null` / `subtitle_secondary: string` を保持している（`resources/js/types/capture.ts`）。
- **primary / secondary の位置**は焼込（ASS）と一致させる（`AssSubtitleWriter` docblock の確定仕様）:
  - `subtitle_secondary` = **画面下部・中央（メイン字幕）**
  - `subtitle_primary` = **上部・中央（名称・数値の帯）**
  これにより「撮影ガイド」と「完成動画」で字幕位置がずれない。
- **ON/OFF はトグル**（`doc/05 §5.2` 準拠）: 録画コントロール行に字幕トグルアイコン（`@lucide/svelte` の `Captions` / `CaptionsOff`。両アイコンとも依存パッケージに実在することを確認済み）を置き、タップで overlay の表示/非表示を切り替える。状態は `aria-pressed={showSubtitles}` で表現し、icon-only 操作のため状態連動 `aria-label`（ON 時「字幕を非表示」/ OFF 時「字幕を表示」）を必須とする。
  - **既定状態**: v1 中核価値が「字幕」であること、および「撮影者が字幕位置を構図に織り込む」という本機能の目的から、**既定 ON（表示）**とする。`doc/05` の「（再タップで非表示）」はトグルの往復挙動の説明であり、初期状態を OFF に限定する記述ではないと解釈する（トグルで OFF にできるため spec の操作要件は満たす）。
  - **disabled 禁止（禁止事項 8）**: 字幕が空でもトグルを disabled にしない。ON でも中身が無ければ overlay を描画しないだけ。
- 字幕が**両方とも空**（`trim()` 後に `primary` が空 かつ `secondary` が空）の場合は、トグル ON でもオーバーレイ要素を**描画しない**（空白のみも空扱い。空の帯を出さない）。

### 表示レイアウト契約（焼込との構図一致のため）

「最終焼込と同じ構図判断」を可能にするための表示契約（レビュー Critical 対応）:

- **overlay コンテナ**: `absolute inset-0 flex flex-col justify-between p-3 pointer-events-none`。この overlay の `p-3`（0.75rem）が**プレビュー端からの inset**（DS spacing ramp。ASS の MarginV=36〜48px/1080 ≒ 3.3〜4.4% に対応する上下余白の役割）。`justify-between` で primary を上端・secondary を下端に固定するため、両帯は構造的に**重ならない**。
- **位置**: `subtitle_primary` → 上端の帯、`subtitle_secondary` → 下端の帯（ASS の Alignment 8 / 2 に対応）。中央領域は常に空く。
- **帯（テキストボックス）**: `max-w-[90%] mx-auto text-center`、内側余白 `px-3 py-1`。`bg-text/70`（帯単位。空の帯は描画しない）、`text-surface`（白）、ramp `text-body`。
- **表示上限（中央領域を侵食しない保証）**: 各帯に**最大行数**を設ける — primary は `line-clamp-2`、secondary は `line-clamp-3`（超過分は省略記号）。これにより長文・多数改行でも帯が中央まで伸びず、上下帯の同時表示でも中央領域が空くことを保証する。本文は `whitespace-pre-line` で改行・折返しを保持しつつ、line-clamp で高さを上限化する。
- **表示 vs 空判定の分離**: `trim()` は**空判定のみ**に使う（両帯が trim 後空なら overlay 非描画）。**描画には元の文字列をそのまま使う**（trim で内容を書き換えない）。
- **契約の性質**: overlay は**位置・占有領域の確認用であり全文確認用ではない**（長文は line-clamp で省略される）。全文は焼込結果で確認する。ライブは端末アスペクト比差があるため「占有領域の目安」であり、ピクセル単位の厳密一致は保証しない。
- **被写体の非隠蔽**: overlay は上下の帯のみで、映像中央を覆わない（`bg-text/70` の半透過帯のみ + line-clamp による高さ上限）。
- **safe-area**: プレビューはカード内 `aspect-video`（フルスクリーンではない）ため端末 safe-area（ホームインジケータ等）は当たらない。フルスクリーン横持ち UI の safe-area 対応は**スコープ外**（当該 UI 自体がスコープ外）。カード内は上記 `p-3` inset で足りる。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」で標準化された動画を作るには、撮影時点で完成形（字幕込み）の構図が見えている必要がある。本機能は**字幕とかぶらない構図判断を支援する**（期待効果。再撮影率等の定量効果は v1 計測基盤スコープ外のため観測指標としては別途）。
- **仕様適合**: `doc/05 §5.2` の**うち字幕重畳要件**を満たし、カバレッジ監査ギャップ #2 を解消する（同節の他機能はスコープ外）。
- **焼込との一貫性**: ライブガイドと最終焼込で字幕の上下配置が揃い、撮影者の期待と成果物が近づく。

## 実装方針（概要）

実装 3 ファイル + テスト新規/追記（すべて frontend、バックエンド・DTO・API・PHPStan 影響なし）:

1. **新規 `resources/js/components/features/capture/SubtitleOverlay.svelte`**
   純粋な表示コンポーネント（無状態・presentational）。Props: `{ primary: CaptureCut["subtitle_primary"]; secondary: CaptureCut["subtitle_secondary"]; visible: boolean }`（型は `CaptureCut` の indexed access で束ね、手書き `string | null` の独立定義による型ドリフトを避ける）。
   - `visible === false`、または primary/secondary が `trim()` 後に両方空なら**何も描画しない**（`{#if}` ガード）。空判定（trim 正規化）は本コンポーネントの 1 箇所に集約する。
   - カメラプレビューの相対配置コンテナ内に `absolute inset-0` で重ね、`pointer-events-none`（録画ボタン等の操作を妨げない）。
   - `subtitle_primary` → 上部中央の帯、`subtitle_secondary` → 下部中央の帯として配置（上記「表示レイアウト契約」に従う）。
   - **視認性**: 帯（テキストボックス）単位にスクリム（`bg-text/70`、DESIGN.md の overlay 系＝墨色半透過。box-shadow 禁止のため帯で担保）＋ `text-surface`（白）で高コントラスト。ramp は `text-body`。`whitespace-pre-line` で改行保持・折返し、`max-w-[90%] mx-auto text-center`。inset は `px-3` / 上下 `py-2` 等。
   - `data-testid="subtitle-overlay"` / `subtitle-primary` / `subtitle-secondary` を付与しテスト可能にする。

2. **`resources/js/components/features/capture/CameraRecorder.svelte`（改修）**
   - Props に `subtitlePrimary: CaptureCut["subtitle_primary"]` / `subtitleSecondary: CaptureCut["subtitle_secondary"]` を追加（親から供給）。
   - `let showSubtitles = $state(true)`（既定 ON）のトグル状態を持つ。
   - `<video>` を `relative` なコンテナで包み、その中に `<SubtitleOverlay primary={subtitlePrimary} secondary={subtitleSecondary} visible={showSubtitles} />` を重ねる。
   - 録画コントロール行に字幕トグルアイコンボタン（`Captions` / `CaptionsOff`、`aria-pressed={showSubtitles}`、状態連動 `aria-label`、`testId="toggle-subtitles"`）を追加。字幕が空でも disabled にしない（禁止事項 8）。
   - 既存の録画ロジック・カメラ失敗ハンドリング・再入ガードには一切手を入れない（overlay は表示レイヤーのみ）。

3. **`resources/js/pages/Capture/Show.svelte`（配線）**
   - `<CameraRecorder>` に `subtitlePrimary={selectedCut.subtitle_primary}` / `subtitleSecondary={selectedCut.subtitle_secondary}` を渡す（`selectedCut` は既に導出済み。この分岐は `selectedCut !== null` 内なので non-null）。

**テスト（vitest, test-first）**:
- `SubtitleOverlay.test.ts`（新規）: `visible=true` かつ字幕ありでオーバーレイ要素が表示される / `visible=false` で非表示 / primary・secondary 両方が空なら非表示 / **空白のみ（trim 後空）でも非表示** / primary のみ・secondary のみの表示 / **長文 JP・多数改行の同時表示でも primary(上)・secondary(下) が別要素として存在し line-clamp クラスが適用される（中央侵食しない構造）** / **描画文字列が trim で書き換えられない（元テキストが表示される）** / primary が上部・secondary が下部に出る（testid で検証）。
- `CameraRecorder.test.ts`（追記）: 字幕 prop を渡し、トグルボタン押下で overlay が表示/非表示に切り替わる（`aria-pressed` と要素の有無）。既存テストは変更しない（props 追加はオプションではなく必須にするため、既存 render 呼び出しに subtitle props を足す最小修正が必要な場合は追記のみ・削除なし）。

## 制約・前提

- **DESIGN.md / tokens.css 準拠**: 色は DS token のみ（`bg-text/70`・`text-surface`）。hex 直書き・raw palette・box-shadow・任意 z-index・静的 inline style は ds-purity テストが禁止。z-index は ramp（`z-10` 等）を使う。typography は ramp（`text-body`）。
- **Atomic Design / import graph**: `SubtitleOverlay` は字幕（capture ドメイン）固有の presentational component のため `components/features/capture/` に配置（molecules はドメイン非依存であるべき）。`features/capture → atoms/@lucide` の単方向 import のみ。アイコンは `@lucide/svelte`（`Captions`/`CaptionsOff`）。
- **禁止事項**: UI で `disabled` によるガードをしない（本機能は disabled を使わない）。テストなし完了報告をしない（各コンポーネントに vitest）。
- **録画への非干渉**: overlay は DOM オーバーレイであり `MediaRecorder` が録る `MediaStream` には含まれない = 焼込にならないことを設計不変条件とする。

## スコープ外（v1 / 本チケットで扱わない）

- **字幕の焼込**（完成動画への合成）は既存 `AssSubtitleWriter` の責務で、変更しない。
- **横持ち全画面（フルスクリーン）撮影 UI・左右スワイプでのカット送り・グリッド表示切替・カメラ内外反転・録画一時停止/再開**（`doc/05 §5.2` の別機能群）。本チケットは「撮影中プレビューへの字幕重畳」に限定する。
- **ナレーション試聴（発話アイコン・TTS）**: v1 は TTS 後回し。ナレーションは `Show.svelte` で既にテキスト表示済み。
- **ファイル選択フォールバック時の overlay**: ライブカメラプレビューが無いため字幕ガイドの対象外。
- **字幕タイミング（時間軸同期）**: 撮影ガイドはカット単位の静的表示で足りる。焼込は 1 カット全尺表示（ASS は `0:00:00`〜終端）であり、ライブガイドもカット全体に対して静的表示で一致する。
