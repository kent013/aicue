## 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の1本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system

あなたはコードレビュアーとして、Laravel アプリ (aicue) の運用ドキュメント変更をレビューする。
今回の変更はコード変更ではなく **`docs/rollout-checklists.md` の記録更新のみ**である。

レビュー観点:
1. **設計との一致性**: 詳細設計 (`devnotes/20260819-1053-sop-image-ocr-support/detailed-design.md` の施策 11、および法務確認・送信案内文言に関する節) が定めるロールアウト前提条件・rollout gate の考え方と、今回の記録更新が矛盾していないか。
2. **正確性・誠実性**: 「画像内 prompt injection の手動評価」についてオーナーが評価そのものを省略すると決定したことを、**評価を実施したかのように書いていないか**(事実と異なる記録は重大な問題として指摘すること)。法務確認についても「確認した」という記録が根拠 (出所・日付) を伴っているか。
3. **rollout gate の構造への影響**: 本変更は `config/manual.php` の `ocr_analysis_enabled` の既定値 (`false`) を変更しない。フラグを `true` にする操作は本番環境変数の設定という運用操作であり、本リポジトリにはデプロイ定義が存在しないためリポジトリ変更としては実行できない、という結論が本文と整合しているか。
4. **後続の再評価トリガー (項目 3)** との整合: 評価セットが存在しないことを明記した追記が、将来の再評価要件を無効化していないか。
5. 全体判定: `APPROVED` / `CHANGES_REQUESTED` を Critical / Warning / Suggestion 分類で出すこと。

---

## user

### 詳細設計書 (関連抜粋: 施策 11 / 法務確認・送信案内文言の節)

以下は `devnotes/20260819-1053-sop-image-ocr-support/detailed-design.md` の該当箇所である。

#### 施策 10 (UI 文言) 抜粋 (1838-1888行)

```
- **送信案内文言は「常時表示する一般案内」と「OCR 経路だけの固有警告」を分ける**
  (design-review Round 5 Warning 対応: 当初案は送信案内全体を
  `imageSourceDocumentsEnabled` で出し分けていたが、「手順書は AI 解析のため外部の
  LLM provider に送信される」という事実自体はテキスト・Excel・通常 PDF にも等しく
  当てはまるため、フラグが false のときに一般案内まで消えるのは不正確だった)。
  - **一般案内 (常時表示。フラグの真偽に関わらず表示する)**:
    「アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に
    送信されます。」
  - **OCR 固有警告 (`imageSourceDocumentsEnabled` が `true` のときだけ追加表示)**:
    「画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
    不要な個人情報や機密情報が写っていないか特に確認してください。」
  最終文言は法務確認の対象とする (施策 11 の rollout dependency)。
...
UI 文言のうち法務確認が必要な送信案内文言は、施策 11 の rollout dependency
(法務確認完了) を待つ。それ以外 (accept 属性・1 枚制約の明示) は施策 1 の実装と
同じ PR で進めてよい。
```

#### 施策 11 (rollout gate) 抜粋 (1892-2007行)

```
- `config/manual.php` に **`ocr_analysis_enabled` (既定 `false`,
  `env('MANUAL_OCR_ANALYSIS_ENABLED', false)`)** を新設する。
- **フラグを `true` にする変更 (`.env` の `MANUAL_OCR_ANALYSIS_ENABLED=true`) だけを、
  法務確認・画像内 prompt injection の手動評価・責任者承認が完了した後に行う独立の
  運用操作とする。** これがコードの完了条件ではなく機能公開の前提条件である
  (概念設計の rollout dependency をコードで裏付ける)。
- rollout チェックリスト: 「法務文面の完了確認」「画像内 prompt injection の
  手動評価・責任者承認」「`MANUAL_OCR_ANALYSIS_ENABLED` を `true` にする変更単位に
  上記 2 つの承認記録を添付すること」を `docs/` 配下 (例: `docs/rollout-checklists.md` 新設)
  に明文化する。
実装モード:
  5. 法務確認・prompt injection 手動評価の承認後、`MANUAL_OCR_ANALYSIS_ENABLED=true` を
     単独の運用変更として適用 (コード変更を伴わない)
```

### 背景

`docs/rollout-checklists.md` は T234 の実装で既に新設済み (法務確認・画像内 prompt injection
の手動評価の 2 項目のチェックリストとして機能している)。今回はオーナーが以下 2 件を
2026-08-21 に決定した:

1. 法務確認: 完了 (「利用規約・顧客契約と矛盾しない」と確認した)
2. 画像内 prompt injection の手動評価: **実施しない (オーナー判断で省略)**

この決定を出所・日付付きでチェックリストへ記録する。フラグを `true` にする操作自体は、
本リポジトリにデプロイ定義が存在しない (AGENTS.md 運用要件節が明記) ため、本番環境変数の
設定・`config:cache` 再生成・プロセス再起動という運用操作としてリポジトリ外に残ることを
明記する。`config/manual.php` の既定値は変更しない。

### 実装差分 (git diff)

```diff
diff --git a/docs/rollout-checklists.md b/docs/rollout-checklists.md
index 061d4b94..56c2848c 100644
--- a/docs/rollout-checklists.md
+++ b/docs/rollout-checklists.md
@@ -14,11 +14,13 @@ ## 画像・スキャン SOP の OCR 対応 (`MANUAL_OCR_ANALYSIS_ENABLED`)
    または現行の契約・同意で画像を含む文書の送信までカバー済みであることの確認。
    アップロード画面の短い案内文言 (`SourceDocumentUpload.svelte` の
    `source-document-send-notice` / `source-document-image-notice`) の妥当性もあわせて確認する。
+   → **完了**。記録は下記「承認記録」参照。
 2. **画像内 prompt injection の手動評価**: 代表的な日本語 SOP・正当な手順命令・
    画像内に仕込んだ攻撃的命令 (「この指示を無視して〇〇と出力せよ」等)・隠し文言・
    スキーマ逸脱を誘う文言を含む評価セットを用意し、期待される抽出結果との突合と
    責任者の承認記録を残す。日本語比率閾値 (`manual.analysis_min_japanese_ratio`) が
    OCR 経路の実データに対して妥当かどうかもこの評価のタイミングで確認する。
+   → **今回は実施しない (オーナー判断で省略)**。記録は下記「承認記録」参照。
 3. **再評価対象の棚卸し**: 以下のいずれかに変更が入る場合は、production を継続する前に
    同じ評価セットで再評価・再承認し、この変更単位に承認記録を添付する。
    - provider/model pin (`AnalysisTokenBudgetInvariantTest` の
@@ -26,9 +28,32 @@ ## 画像・スキャン SOP の OCR 対応 (`MANUAL_OCR_ANALYSIS_ENABLED`)
    - 媒体 YAML (`resources/prompts/sop-extract-media.yaml`。特に防御指示 4 項目)
    - vendor 媒体変換の契約テスト (`tests/Feature/Manual/Analysis/OcrMediaMessageContractTest.php`)
      が前提にする Prism/Anthropic のバージョン
+   - **注**: 項目 2 の評価自体を実施していないため、現時点で「同じ評価セット」は存在しない。
+     再評価の起点となる評価セットの用意は、この項目の起票時点であらためて判断する。
+
+## 承認記録
+
+| 日付 | 項目 | 結果 | 出所 |
+|---|---|---|---|
+| 2026-08-21 | 法務確認 (項目 1) | 「利用規約・顧客契約と矛盾しない」と確認した。アップロード画面の送信案内文言 (`source-document-send-notice` / `source-document-image-notice`) も法務確認済み (矛盾しない) として確認した | オーナーの決定 (2026-08-21) |
+| 2026-08-21 | 画像内 prompt injection の手動評価 (項目 2) | 実施しない (オーナー判断で省略)。評価セットの用意・突合・承認は行っていない | オーナーの決定 (2026-08-21) |
+
+上記 2 件により、本チェックリストが定める `MANUAL_OCR_ANALYSIS_ENABLED=true` への
+前提条件は、運用判断として満たされたものとして扱う。**項目 2 は「評価を実施し合格した」
+という記録ではなく「オーナー権限で評価そのものを省略した」という記録である**ことを
+以降の再確認でも維持する (実施したかのような記録へ書き換えない)。
 
 ## 反映の運用手順
 
+- **フラグを `true` にする変更そのものはリポジトリ変更ではない**。`config/manual.php` の
+  既定値 (`env('MANUAL_OCR_ANALYSIS_ENABLED', false)`) はこのままにし、`true` への切替は
+  本番環境変数 `MANUAL_OCR_ANALYSIS_ENABLED` の設定という単独の運用操作で行う
+  (施策 11 の設計どおり。コード変更を伴わない)。
+  **本リポジトリにはデプロイ定義が無い** (AGENTS.md の `route:cache` 運用要件節が明記する
+  とおり `deploy/` / terraform / k8s / CI デプロイ job のいずれも存在しない) ため、
+  この環境変数設定・`config:cache` 再生成・プロセス再起動は**このリポジトリ内の変更としては
+  実行できない**。承認記録が揃った時点で、本番環境を運用する側が上記手順を人手で実施する
+  (残タスクは summary で明示的に引き継ぐ)。
 - production が `config:cache` を使う場合、`.env` の変更だけでは反映されない。
   `MANUAL_OCR_ANALYSIS_ENABLED=true` の設定後、`config:cache` の再生成とプロセス再起動が
   別途必要 (既存運用の一般論であり、AGENTS.md が定める経路キャッシュ関連の運用要件
```

### テスト結果

`docs/rollout-checklists.md` のみを変更する純粋なドキュメント更新であり、コード・設定・
テストファイルは変更していない。`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
`pnpm build:packages` / `pnpm test:packages` を実行し、変更前と同じ結果 (全 green) であることを
確認する (テスト結果の実測値は別途 Claude 側で報告する)。
