**施策別レビュー**

- **施策1（`isCaptureNavigable` 追加）: APPROVE**
  - [Suggestion] `CAPTURE_NAVIGABLE_BY_STATUS` の意図（`CaptureManualController::index` と一致）をコメントに残す方針は妥当。将来の enum 追加時にズレ検知しやすいです。

- **施策2（Show に撮影導線追加）: APPROVE**
  - [Warning] `canManage=false` かつ `captureNavigable=false` で空の action コンテナが残る設計は軽微ですが、将来スタイル崩れの温床になり得ます。  
    修正案: `captureNavigable || canManage` のときだけ action コンテナ自体を描画。
  - [Suggestion] Show で `captureNavigable` を `$derived` にするのは Svelte 5 方針に沿っていて良いです。

- **施策3（Edit に撮影導線追加）: APPROVE**
  - [Warning] 未保存変更の破棄は既存「キャンセル」と同挙動で整合していますが、ユーザーにとっては主CTA遷移なので誤操作確率が上がります。  
    修正案: 本施策内では現状維持でよいが、別チケットで共通 dirty-navigation ガード（遷移確認）を明示的に計画化。
  - [Suggestion] ヘッダ配置で「保存」と「撮影開始」を分離する判断は UX 的に妥当です。

- **施策4（predicate 単体テスト新規）: APPROVE**
  - [Suggestion] `Object.keys(...).sort()` の比較に加え、`expectTypeOf`（導入済みなら）で `Record<VideoManualStatus, boolean>` を型レベル確認すると回帰耐性がさらに上がります。

- **施策5（Show/Edit テスト拡充）: APPROVE**
  - [Warning] `href` 検証を `toMatch(/...$/)` のみで行うと、将来クエリやprefix変更時の意図しない通過/失敗が混ざる可能性があります。  
    修正案: 期待値を定数化し `toBe("/app/projects/1/manuals/5")` で厳密一致、必要なら別ケースでクエリ付きも明示。
  - [Suggestion] Show 側で `published` ケースも1本追加すると、`ready/published=true` の仕様意図がテストから読み取りやすくなります。

**観点別総評**

- ロジック正確性: 良好（状態判定の単一ソース化、null安全問題なし）
- 既存整合性: 良好（`STATUS_TONES` と同じ `satisfies` 戦略、既存 Button API 活用）
- PHPStan level 10: 影響なし（PHP未変更）
- テスト網羅: 概ね十分（状態×権限×画面の主要分岐をカバー）
- Inertia Props/API 使い分け: 適切（既存 props 活用、サーバ変更なし）
- セキュリティ/組織スコープ: 問題なし（最終認可は既存サーバ側、cross-org 追加リスクなし）
- DESIGN.md / Atomic Design: 準拠（token前提、Lucide利用、Button atom経由、SVG直書きなし）

**全体判定**

- **APPROVED**

実装着手して問題ない設計です。上記 Warning は「今すぐ差し戻し必須」ではなく、品質向上のための軽微修正提案です。