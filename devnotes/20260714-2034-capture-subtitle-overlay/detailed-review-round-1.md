以下、**提示された詳細設計のみ**を対象にレビューします（実装コード実査・コマンド実行なし）。

**施策別判定**

- **S1 `SubtitleOverlay.svelte` 新設: APPROVE**
  - [Suggestion] `hasSecondary = $derived(secondary.trim() !== "")` は型上 `secondary: string` 前提で成立。設計どおりだが、防御的に `(secondary ?? "").trim()` にすると将来 props 契約変更時も安全。
  - [Suggestion] `line-clamp` 非対応ブラウザでの高さ増大許容は妥当。ただし「中央領域を必ず空ける」を強保証したいなら `max-h` 系トークン運用の可否を DS 側と事前合意するとより堅い。

- **S2 `CameraRecorder.svelte` 組込: APPROVE**
  - [Warning] icon-only トグルに `aria-label` / `aria-pressed` は良いが、**トグル対象の明示**（`aria-controls`）がない。`PasswordInput` 先例の踏襲観点では、`SubtitleOverlay` ルートに安定 id を与えて関連付けると一貫性が増す。  
    **修正案**: `SubtitleOverlay` ルートに `id="subtitle-overlay-panel"` を付与し、トグルに `aria-controls="subtitle-overlay-panel"` を追加。
  - [Suggestion] `showSubtitles` を録画状態と独立させたのは副作用分離として正しい。既定 ON も North Star 整合。

- **S3 `Capture/Show.svelte` 配線: APPROVE**
  - [Suggestion] `selectedCut !== null` 分岐内の non-null 前提は妥当。将来のリファクタで崩れないよう、`selectedCut` をローカル定数に束縛する書き方（可読性目的）は検討余地あり。

- **S4 `SubtitleOverlay.test.ts` 新規: APPROVE**
  - [Warning] 「前後空白が `textContent` に保持される」検証は DOM 正規化やレンダラ差異で不安定化しやすい。  
    **修正案**: 「trim が空判定にのみ使われる」ことは、`"  a  "` を渡して表示自体が行われること + `toContain("a")` を中心に検証し、空白完全一致は避ける（必要なら snapshot ではなくノード存在/非存在中心）。
  - [Suggestion] `visible=false` 時に `subtitle-primary` / `subtitle-secondary` も両方不在を明示すると回帰耐性が上がる。

- **S5 `CameraRecorder.test.ts` 追記: APPROVE**
  - [Warning] アイコン切替をコンポーネント名依存で検証すると脆い。  
    **修正案**: `aria-pressed` と `aria-label` の状態遷移を主アサーションにし、アイコンは補助的（存在確認のみ）に留める。
  - [Suggestion] 「disabled でない」検証は `toHaveAttribute("disabled")` 不在に加え、実際にクリックで状態遷移することを同ケースで確認すると禁止事項 8 への適合証跡が強い。

**観点別サマリ**

- 正確性 / null安全 / runes: 概ね良好（`$props/$state/$derived` の使い方妥当）。
- 既存整合性: `PasswordInput` 先例の取り込みは適切。命名も一貫。
- 型安全: indexed access 採用と既定値で後方互換を確保できている。
- DS / ds-purity: 記載の class 群は方針上妥当。`max-w-[90%]` も禁止対象外整理は合理的。
- Atomic / import graph: `features/capture` 配置で適合。raw button は先例準拠で許容。
- A11y: 概ね良いが `aria-controls` 追加でさらに整う。
- 副作用 / 後退: 録画ロジック非改変・overlay 非焼込の責務分離は適切。
- 波及網羅: 型・呼び出し元・テストまで計画に含まれ、抜けは少ない。
- 表示契約: 2スロット + clamp 方針は妥当。極小画面時の実測確認を必須化すると盤石。

**全体判定**

- **APPROVED**（上記 Warning は実装前に取り込むと品質がさらに安定）