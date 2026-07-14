# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
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
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（対象は UI のみの変更。テイク削除に確認ダイアログを挟む。現行コードは `resources/js/components/features/capture/TakeStrip.svelte`、再利用元は `resources/js/components/organisms/ConfirmDialog.svelte`。）

# 概念設計: take-destroy-confirm

## 背景・課題

bug-hunt (real-llm 2nd run 20260714-154640) の F-1-2 (Medium, H7) として報告。

テイク動画の削除 (`capture.takes.destroy`, `DELETE /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}`) が、アプリの他の破壊的操作 (動画マニュアル削除・プロジェクト削除・メンバー削除・API キー revoke 等) と異なり、**確認ダイアログ無しで即削除**される。

- 該当 UI: `resources/js/components/features/capture/TakeStrip.svelte` L187-196 の削除ボタン (`take-delete-{id}`)。`onclick={() => remove(take)}` が押下と同時に `captureJson(takeUrl(take), "DELETE")` を発火する。
- 撮影は PWA (スマホ) で行われるため、**モバイル誤タップによるテイク喪失リスク**が高い。テイクは現場で撮り直しが困難な素材であり、消失は使命の中核データを失うことを意味する。
- 他の破壊的操作は既に `ConfirmDialog` organism で確認を挟んでいる (例: `pages/Manuals/Show.svelte` L138-147)。テイク削除だけがこの一貫性から逸脱している。

## 改善アイデア

テイク削除ボタン押下時に、他の destructive 操作と**同じ確認ダイアログ** (既存の `ConfirmDialog` organism) を挟み、「削除する」確定後に初めて `DELETE` を送る。UI/文言は既存の削除確認 (動画マニュアル削除等) のパターンに合わせる。

- 押下 → 削除対象のテイクを保持して `ConfirmDialog` を開く。
- 確認モーダルで「削除する」(danger variant) を押した時のみ、既存の `remove(take)` (XHR DELETE) を実行する。
- 「キャンセル」/ESC/オーバーレイ/X で閉じた場合は DELETE を発火しない。
- 送信中 (`busyTakeId === target.id`) は `processing` を立て、二重送信・誤操作での close を抑止する。

## 期待効果

- **使命への貢献**: 現場で撮り直し困難なテイク素材の誤削除喪失を防ぎ、標準化マニュアル動画の生成基盤 (撮影データ) を保全する。
- 破壊的操作 UX の**アプリ全体での一貫性**を回復する。
- モバイル誤タップによる不可逆なデータ喪失インシデントの削減。

## 実装方針（概要）

`TakeStrip.svelte` に閉じた変更で完結させる。新規コンポーネントは作らず、既存 organism を再利用する。

1. `TakeStrip.svelte` に `ConfirmDialog` organism を import し、削除確認用 state を追加 (`deleteTarget`, `deleteDialogOpen`)。
2. 削除ボタンの `onclick` を「即 remove」から「確認ダイアログを開く」へ変更 (`requestDelete(take)`)。
3. `ConfirmDialog` の `onConfirm` で、保持した target に対し既存の `remove(target)` を実行し、完了後にダイアログを閉じる。
   - 文言: title「テイク削除」, message「テイク {n} を削除しますか？ この操作は取り消せません。」, confirmLabel「削除する」, confirmVariant「danger」。
   - `processing` は `busyTakeId === deleteTarget?.id`。
4. 既存の `remove` / `run` (XHR + `onChanged` + エラー表示) を流用。DL 済みテイクの 422 エラー表示 (押下時にサーバメッセージ) の挙動も維持 (確認後に DELETE を送り、422 なら従来どおり `take-strip-error` に表示)。

Inertia ではなく XHR (`captureJson`) 経由のまま (テイク操作は SPA 内 XHR で `onChanged` により再取得する既存設計)。

## 制約・前提

- Atomic Design: `TakeStrip` (features/capture) から `ConfirmDialog` (organisms) への import は単方向 import に適合。新規 SVG 内包なし。
- DESIGN.md 準拠: `ConfirmDialog` は DS token 経由。hex 直書きを増やさない。confirmVariant は既存の `danger`。
- 禁止事項 #8: 削除ボタンは従来どおり常時押下可能で、DL 済み等の制約は確認後の DELETE 応答 (422) で表示する挙動を維持。
- バックエンド (`CaptureTakeController::destroy` / ルート / 認可) は変更しない。UI のみ。
- 型安全性: TypeScript のみの変更で PHP 側変更なし。PHPStan への影響なし。

## スコープ外

- テイク以外の破壊的操作の変更。
- 削除の undo / ゴミ箱 / ソフトデリート化。
- コメント削除・並べ替え等への確認追加。
- バックエンド・API・ルートの変更。
