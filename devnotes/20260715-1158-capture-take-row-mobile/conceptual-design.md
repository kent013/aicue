# 概念設計: capture-take-row-mobile（撮影テイク行の mobile 375px レイアウト崩れ修正）

## 背景・課題

bug-hunt run 20260715-084108 の **F-1-05 (Medium, H11/H13)**。

`resources/js/components/features/capture/TakeStrip.svelte` のテイク行が、mobile 375px 幅で
レイアウト崩れを起こす。

- 症状: テイク行のラベル（「テイク N」＋「採用中」「DL 済み」バッジ）が横方向に収まらず、
  再生ボタン（T050 で追加したインラインプレビュー Play アイコン）などの操作ボタン群と**重なる**。
  ラベルが縦に折り返して見える。
- tablet 768 では正常。
- 原因候補: T050（インラインプレビュー再生ボタン追加）/ T056（撮影 UX 拡充）で
  テイク行内の**要素数が増えた**影響。テイク行は 1 行 flex（chevron 列 / ラベル・メタ列 /
  操作ボタン列）で、操作ボタン列に Play・採用（テキスト付）・DL・コメント・削除の 5 ボタンが
  `shrink-0` で並ぶ。375px では操作ボタン列が幅を占有し、`min-w-0 flex-1` のラベル列が
  極端に狭くなる。ラベル内のバッジ行 `<p class="flex items-center gap-2">` は
  **wrap も min-w-0 も無い**ため、狭い親の幅を無視して右方向へはみ出し、操作ボタンと重なる。

### 現行構造（TakeStrip.svelte L190-302 の要点）

```
<div class="flex items-center gap-2 ...">          <!-- 行: nowrap -->
  <div class="flex flex-col gap-1"> up/down </div>  <!-- chevron 列 -->
  <div class="min-w-0 flex-1">                       <!-- ラベル・メタ列 -->
    <p class="flex items-center gap-2 text-body">テイク N + 採用中Badge + DL済みBadge</p>
    <p class="text-caption ...">サイズ・秒・コメント</p>
    ...not-ready 補助文言...
  </div>
  <div class="flex shrink-0 items-center gap-1">     <!-- 操作ボタン列: Play/採用/DL/コメント/削除 -->
</div>
```

- 行コンテナは `nowrap`（`flex-wrap` 無し）。
- 操作ボタン列は `shrink-0`（縮まない）。5 ボタン＋テキスト「採用」で mobile では ~190px 占有。
- ラベル列は `min-w-0 flex-1` で残り幅（375px では ~100px 前後）に潰される。
- **バッジ行 `<p>` は `flex nowrap` かつ `min-w-0` 無し** → 親幅を無視してはみ出し、
  操作ボタンと視覚的に重なる（本バグの直接原因）。

## 改善アイデア

**テイク行の flex レイアウトを 375px でも要素が重ならないよう見直す。** 方向性は 2 点:

1. **行を mobile で 2 段に分ける（レスポンシブ wrap）**
   操作ボタン列を、mobile では**ラベル行の下**へ折り返して full-width で右寄せ配置し、
   tablet 以上（`sm:` = 640px 以上、768 を含む）では**従来どおり 1 行**に戻す。
   → 操作ボタン列がラベル幅を奪わなくなり、Play ボタンとバッジの重なりが構造的に解消する。

   **ブレークポイント根拠（`sm` = 640px を採用。conceptual-review R1 Warning 反映）**:
   操作ボタン列（Play/採用/DL/コメント/削除 ≈ 190px）＋ chevron 列（≈ 30px）＝ ≈ 220px。
   640px で 1 行復帰しても残り ≈ 400px がラベル列に確保され、375px のような窮屈化は起きない。
   全スマホ幅（320-430px 級）を 2 段に寄せ、tablet/PC を 1 行に保つ境界として `sm` が最適。
   `md`（768）採用だと 640-767px の横持ち/小型タブレットまで 2 段になり冗長。

2. **ラベル内バッジ行を wrap・縮小可能にする**
   バッジ行 `<p>` に `flex-wrap` と `min-w-0` を付け、狭い幅では「テイク N」の下に
   バッジが折り返るようにする（親幅をはみ出さない）。「テイク N」ラベル本体は
   `min-w-0` 側で truncate 余地を残す。

この 2 つで「はみ出して重なる」→「素直に段落ちする」挙動へ変える。
tablet 768 は `sm:` 分岐で従来レイアウトを維持（非退行）。

字幕トグル（T050）は `TakePreviewDialog` 側の機能でありテイク行内には存在しないため、
本修正の対象外（プレビュー起動ボタン=Play アイコンのみが行内要素）。録画/撮影 UX ボタン（T056）は
`CameraRecorder.svelte` 側で、TakeStrip とは別コンポーネント。TakeStrip 内の操作ボタン
（Play/採用/DL/コメント/削除）の共存だけを扱う。

## 期待効果

- **使命への貢献**: 撮影 PWA はスマホ（375px 級）が主戦場。「思考ゼロ・編集ゼロ」で
  現場作業者がテイクを採用・確認する UI が最小幅で崩れると、採用操作が誤タップ・詰みに繋がる。
  レイアウト崩れ解消は撮影→採用フローの実機体験を直接改善する。
- **具体的改善**: 375px でラベル/バッジと操作ボタンが重ならず、各操作ボタンのタップ領域が
  確保される。tablet 以上は現状維持（非退行）。

### 成功条件（conceptual-review R1 Warning 反映）

1. 375px（および 320px）で、ラベル/バッジ行と操作ボタン列が**視覚的に分離**する（重なり 0）。
2. 操作ボタンが横方向に潰れて**アイコン欠け・テキスト「採用」の切れ**が起きない。
3. 「採用中」＋「DL 済み」の**両バッジ同時**表示の最悪ケースでも 1・2 を満たす。
4. tablet（768）は従来の 1 行レイアウトを維持（非退行）。

## 実装方針（概要）

`TakeStrip.svelte` の各テイク行（`take-item-${take.id}`）のみを変更する。CSS/クラスの調整が主。

1. 行コンテナ: `flex items-center gap-2` → mobile で wrap 可能にし、`sm:` で nowrap 復帰。
   `flex flex-wrap items-center gap-x-2 gap-y-2 sm:flex-nowrap`。
2. 操作ボタン列: mobile では full-width で次段へ落として右寄せ、`sm:` で従来幅。
   `flex w-full shrink-0 items-center justify-end gap-1 sm:w-auto sm:justify-start`。
   （`w-full` が flex-wrap 下で確実に次行へ送る）。
3. ラベル列: `min-w-0 flex-1`（現状維持。mobile wrap 時も chevron 列と 1 行目を共有）。
4. バッジ行 `<p>`: `flex items-center gap-2` → `flex flex-wrap items-center gap-x-2 gap-y-1 min-w-0`。
5. テスト検証と証跡のため、操作ボタン列とバッジ行に **data-testid を付与**
   （`take-actions-${take.id}` / `take-label-${take.id}`）。挙動は不変（テスト構造検証用の hook）。

DS token / Atomic Design 準拠: 変更は Tailwind ユーティリティ（レイアウト）のみで、
hex 直書き・新規 SVG・新規 atom を増やさない。Badge/Button atom はそのまま利用。
アイコンは既存 Lucide のまま。

## 制約・前提

- **DESIGN.md 準拠**: 必須条件未充足でボタンを disabled にしない原則は維持（本修正はレイアウトのみ）。
  color/radius/typography の token 参照方針を崩さない（レイアウトユーティリティのみ追加）。
- **Atomic Design 準拠**: TakeStrip は `features/capture` の分子/有機体レベル。atom（Badge/Button）の
  責務は変えず、features 層でのレイアウト調整に閉じる。import 単方向を崩さない。
- **非退行**: tablet 768（`sm:` 以上）は従来の 1 行レイアウトを維持する。
- Svelte 5 runes / 既存 props・XHR ロジックは一切変更しない（DOM 構造のクラスと testid のみ）。

### 検証マトリクス（conceptual-review R1 Warning 反映）

| 幅 | 期待レイアウト | 確認観点 |
|----|--------------|---------|
| 320px | 2 段（操作列は下段 full-width 右寄せ） | 重なり 0 / アイコン欠け無し |
| 375px | 2 段 | 重なり 0 / 「採用」テキスト切れ無し / **採用中+DL済み 両バッジ同時** |
| 640px | 1 行復帰 | ラベル列に十分幅 / 窮屈化無し |
| 667px | 1 行 | 非退行 |
| 768px | 1 行（従来） | **非退行（現状維持）** |

- `min-w-0` は **ラベル列（`min-w-0 flex-1`）** と **バッジ行（`<p>`）** の両方に適用し、
  将来の長文言・翻訳・バッジ増でもはみ出さないようにする。
- 実装時の証跡: 375px / 768px の screenshot を取得（採用中+DL済み 両バッジ状態を含む）。

## スコープ外

- `TakePreviewDialog.svelte`（字幕トグル・プレビュー本体）のレイアウト。
- `CameraRecorder.svelte`（T056 撮影 UX ボタン）のレイアウト。
- テイク行の情報設計・操作の追加/削除（ボタン数や機能は変えない）。
- F-1-04（Permissions-Policy によるカメラ不能）等、他 bug-hunt 指摘。
- サーバ側・API・DTO（本件はフロント表示のみ、波及なし）。
</content>
</invoke>
