# 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

# 役割: 実装レビュア (impl-review, 最終確認ラウンド)

あなたは Laravel/Svelte のシニアレビュアです。T004「撮影PWA (presigned アップロード + テイク管理 + 容量 Quota)」の main マージ直前の最終修正をレビューしてください。

観点:
- 正確性・並行制御 (READ COMMITTED 下の競合、reserve→commit の 2 フェーズ、Quota 判定の TOCTOU)
- この修正が指摘 Warning を実際に解消しているか
- この修正が新たな問題(過剰な二重計上による恒常的 Quota 誤拒否など)を持ち込んでいないか
- テストが不変条件を実際に固定できているか

出力形式: `[Critical]` / `[Warning]` / `[Suggestion]` の見出し付き箇条書き。問題がなければ「Critical なし」と明言すること。

必要ならリポジトリ内ファイルを読んでよい (作業ツリー: `/workspace/.claude/worktrees/tasks/T004/`)。特に:
- `/workspace/.claude/worktrees/tasks/T004/app/Services/Capture/StorageUsageService.php`
- `/workspace/.claude/worktrees/tasks/T004/app/Services/Capture/TakeUploadService.php`
- `/workspace/.claude/worktrees/tasks/T004/app/Services/Capture/TakeRegistrationService.php`
- `/workspace/.claude/worktrees/tasks/T004/tests/Feature/Capture/StorageUsageServiceTest.php`

---

# 修正対象となった前段レビューの Warning

> `occupiedBytes()` が `bytesUsed()`→`bytesPending()` の順で 2 本の別クエリを読むため、その隙間で並行するテイク登録の finalize がコミットすると当該予約が「どちらにも数えられず」占有量を過少計上し、容量 Quota を一時的に超過できる (PostgreSQL READ COMMITTED)。`issue()` は Organization 行ロックを取るが finalize は VideoManual ロックしか取らず両者は直列化されない。

# 適用した修正 (working diff)

```diff
diff --git a/app/Services/Capture/StorageUsageService.php b/app/Services/Capture/StorageUsageService.php
@@
      * Quota 判定に渡す占有量 (bytes_used + bytes_pending) の安全合成。
      * 呼び出し側で生の加算をさせない (overflow は上限側に丸める。checkAddition の
      * 事前条件 current >= 0 も本メソッドが保証する)。
+     *
+     * 読み取り順は **pending → used** を維持すること (並行制御上の不変条件):
+     * issue() は Organization 行ロック下で呼ばれるが、テイク登録の finalize
+     * (verifying 予約→Take 確定) は VideoManual ロックしか取らず直列化されない。
+     * READ COMMITTED では 2 本の読み取りの隙間に finalize がコミットしうるため、
+     * used→pending の順だと当該予約が「どちらにも数えられず」過少計上になり
+     * Quota を一時的にバイパスできる。pending→used の順なら同じ競合は
+     * 二重計上 (= 拒否側・安全側) に倒れる。
      */
     public function occupiedBytes(Organization $organization): int
     {
-        $used = $this->bytesUsed($organization);
-        $pending = $this->bytesPending($organization);
+        $pending = $this->bytesPending($organization);
+        $used = $this->bytesUsed($organization);

         return $used > PHP_INT_MAX - $pending ? PHP_INT_MAX : $used + $pending;
     }
```

加えて回帰テストを追加 (`tests/Feature/Capture/StorageUsageServiceTest.php`):
匿名サブクラスで `bytesPending` / `bytesUsed` の呼び出し順を記録し、`['pending', 'used']` であることを固定。

# 質問

1. この修正は Warning の競合シナリオを安全側 (二重計上 = 拒否側) に倒せているか。
2. 逆方向の競合 (pending 読取後、used 読取前に **新規 issue** が入る等) で新たな穴は生まれないか。
3. Critical に相当する未解決問題はあるか。マージ可否の判断材料として明言すること。
