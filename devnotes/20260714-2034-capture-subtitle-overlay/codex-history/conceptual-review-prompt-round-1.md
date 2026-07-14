# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(factory 経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか（v1 スコープ: 字幕のみ / TTS 後回し / PWA / ffmpeg 合成 / 単一 Default Project を尊重しているか）
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか（本件は frontend 中心のため、TypeScript 型・Svelte 5 runes の妥当性も含む）

【本設計の重要な事実（レビュー時の前提）】
- 字幕データ `subtitle_primary: string | null` / `subtitle_secondary: string` は既存の `CaptureCut` 型・Inertia props に**既に含まれており**、バックエンド/DTO/API 変更は不要（frontend のみの変更）。
- 焼込（完成動画への字幕合成）は既存 `app/Services/Render/AssSubtitleWriter.php` の責務で本件は変更しない。本件は「撮影中プレビュー上のガイド overlay 表示」のみ（DOM オーバーレイであり MediaRecorder が録る MediaStream には混入しない = 焼込にならない）。
- primary/secondary の表示位置は焼込（ASS）と一致させる: secondary=下部中央（メイン）、primary=上部中央（帯）。
- DS 制約（ds-purity テストで強制）: raw palette 色・hex 直書き・box-shadow・任意 z-index・静的 inline style・raw text-size 禁止。色は DS token（`bg-text/70`・`text-surface`）、z-index は ramp、typography は ramp。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260714-2034-capture-subtitle-overlay/conceptual-design.md の全文）

# 概念設計: capture-subtitle-overlay（撮影中カメラプレビューへの字幕オーバーレイ表示）

## 背景・課題

ユースケース・カバレッジ監査ギャップ #2（High）。

- **仕様**: `doc/05 §5.2` の撮影 UI は「**字幕表示**: 録画ボタン右の字幕アイコンで、カメラ映像上に字幕を重畳（構図確認用。再タップで非表示）」を主要機能として掲げる。横持ち全画面でも「撮影ガイド・字幕は透過オーバーレイで表示」とある。
- **v1 の中核価値**: 「字幕のみ（TTS 後回し）」。字幕は AI-CUE のマニュアル動画の中核成果物であり、撮影者が**字幕の載る位置を構図に織り込める**ことは撮影品質（フレーミング）を直接左右する。
- **現状の欠落**: `resources/js/components/features/capture/CameraRecorder.svelte` には字幕（subtitle）描画が一切無い（`grep subtitle` = 0 件）。完成動画への字幕**焼込**は `app/Services/Render/AssSubtitleWriter.php`（ASS 字幕生成）が担うが、これは合成後の話であり、**撮影中のライブプレビュー上のガイド表示**が存在しない。撮影者は「どこに字幕が出るか」を撮影時に確認できない。

**仮説**: カメラプレビュー領域に当該カットの字幕を（焼込ではなく）ガイドとして重畳すれば、撮影者は字幕とかぶらない構図を撮れ、再撮影の手戻りが減る。成功判定 = subtitle prop に応じてオーバーレイ要素が出/非表示になり、primary/secondary が仕様どおりの位置（上部帯/下部メイン）に表示され、vitest / typecheck / lint / build が green。

## 改善アイデア

撮影中（`CameraRecorder` のカメラプレビュー領域）に、**選択中カット（selectedCut）の字幕**（`subtitle_primary` / `subtitle_secondary`）を重畳表示する**字幕オーバーレイ・レイヤー**を追加する。

- **焼込はしない**。あくまで撮影ガイドの overlay 表示（録画される blob には一切影響しない。overlay は DOM 上の別レイヤーで、`MediaRecorder` は `stream` を録るため映像に混入しない）。
- 字幕データは**既に cut の prop 経由で供給されている**（`Capture/Show.svelte` の `selectedCut` → `CutNavigator` と同じ源）。**バックエンド変更は不要**。`CaptureCut` 型は既に `subtitle_primary: string | null` / `subtitle_secondary: string` を保持している（`resources/js/types/capture.ts`）。
- **primary / secondary の位置**は焼込（ASS）と一致させる（`AssSubtitleWriter` docblock の確定仕様）:
  - `subtitle_secondary` = **画面下部・中央（メイン字幕）**
  - `subtitle_primary` = **上部・中央（名称・数値の帯）**
- **ON/OFF はトグル**（`doc/05 §5.2` 準拠）: 録画コントロール行に字幕トグルアイコン（`@lucide/svelte` の `Captions` / `CaptionsOff`）を置き、タップで overlay の表示/非表示を切り替える。押下時状態は `aria-pressed` で表現。
  - **既定状態**: v1 中核価値が「字幕」であること、および本機能の目的（撮影者が字幕位置を構図に織り込む）から、**既定 ON（表示）**とする。`doc/05` の「（再タップで非表示）」はトグルの往復挙動の説明であり、初期状態を OFF に限定する記述ではないと解釈する（この解釈は本レビューで確認する論点）。
- 字幕が**両方とも空**（`primary` が null/空 かつ `secondary` が空）の場合は、トグル ON でもオーバーレイ要素を**描画しない**。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」で標準化された動画を作るには、撮影時点で完成形（字幕込み）の構図が見えている必要がある。字幕とかぶる失敗フレーミングを撮影時に防ぐ = 再撮影・手戻りの削減。
- **仕様適合**: `doc/05 §5.2` の未実装だった主要機能（字幕重畳）を satisfy し、カバレッジ監査ギャップ #2 を解消する。
- **焼込との一貫性**: ライブガイドと最終焼込の字幕位置が一致する。

## 実装方針（概要）

3 ファイルの変更（すべて frontend、バックエンド・DTO・API・PHPStan 影響なし）:

1. **新規 `SubtitleOverlay.svelte`（features/capture）**: 純粋な表示コンポーネント。Props `{ primary: string | null; secondary: string; visible: boolean }`。visible=false または両方空なら何も描画しない。`absolute inset-0 pointer-events-none`。primary→上部中央、secondary→下部中央。視認性: スクリム帯 `bg-text/70` + `text-surface`（白）、ramp `text-body`。testid 付与。
2. **`CameraRecorder.svelte`（改修）**: Props に `subtitlePrimary`/`subtitleSecondary` 追加。`showSubtitles=$state(true)` トグル。`<video>` を relative コンテナで包み overlay を重ねる。コントロール行に字幕トグルアイコンボタン（`Captions`/`CaptionsOff`, `aria-pressed`, testId）。録画ロジックには手を入れない。
3. **`Capture/Show.svelte`（配線）**: `<CameraRecorder>` に `subtitlePrimary={selectedCut.subtitle_primary}`/`subtitleSecondary={selectedCut.subtitle_secondary}` を渡す。

**テスト（vitest）**: `SubtitleOverlay.test.ts`（新規: visible/空/primary/secondary/位置）、`CameraRecorder.test.ts`（追記: トグルで表示切替）。既存テスト削除なし。

## 制約・前提

- DESIGN.md / tokens.css 準拠（DS token のみ、ds-purity 準拠）。Atomic Design: features/capture 配置、`@lucide/svelte` アイコン。禁止事項（disabled ガード禁止・テスト必須）遵守。overlay は MediaStream に混入しない（焼込にならない）を不変条件とする。

## スコープ外（v1 / 本チケットで扱わない）

- 字幕の焼込（既存 AssSubtitleWriter の責務）。
- 横持ち全画面 UI・左右スワイプ・グリッド表示・カメラ反転・録画一時停止/再開（doc/05 §5.2 の別機能群）。
- ナレーション試聴（TTS 後回し）。
- ファイル選択フォールバック時の overlay（ライブプレビューが無い）。
- 字幕タイミング同期（カット単位の静的表示で足りる。焼込も 1 カット全尺の静的表示）。
