【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel の詳細設計レビュアーです。

【レビュー観点】
1. 正確性 2. 既存整合 3. PHPStan level 10 4. テスト網羅性と mutation 5. 副作用 6. セキュリティ

【特に見てほしい点】
- 契約 7 (metadata) の検査方法は妥当か。凍結していない削除でしか観測できないが、それでよいか
  (凍結中は削除されないので metadata も残らない)。
- 「赤くなるのは契約 7 だけ」という fail 先行の見立ては正しいか。
- mutation M1..M5 の予測は妥当か。
- `request()` を service 内で呼ぶことの是非 (テスト容易性・純粋性)。

【出力形式】施策ごとに APPROVE / REQUEST_CHANGES、[Critical][Warning][Suggestion]、全体判定、日本語

---

## 詳細設計書

# 詳細設計: freeze-destroy-xhr

## 使命・制約(絶対遵守)

### アプリの使命(North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 2. PHPStan の widen 3. dev DB への破壊操作
4. `response()->json()` の直書き 5. Prism 直呼び 6. prompt 直書き
7. `redirect()->intended()` 8. 必須条件未充足での disabled 9. Artifact の使用

### コーディングルール

`declare(strict_types=1)` + 日本語コメント / PHPStan level 10 / Pest (RefreshDatabase グローバル) /
テストデータは Factory 経由 / 既存テストの削除禁止。

## 概念設計リファレンス

- `devnotes/20260812-1410-freeze-destroy-xhr/conceptual-design.md` (Round 4 APPROVED)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 監査 metadata に削除時点の凍結状態・route・method を残す | `app/Services/Organization/OrganizationMembershipService.php` | High |
| 2 | 契約 6 件をテストで固定 | `tests/Feature/Auth/AccountDeletionFreezeTest.php` (既存へ追記) | High |
| 3 | 運用契約の記載 | `docs/architecture.md` §退会の猶予期間つき削除 | Medium |

**防御は増やさない。** 凍結判定の二重化・削除直前の再チェックは作らない。

---

## 施策 1: 監査 metadata

### 現行コード

```php
// 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)
$this->recorder->record(SecurityEventType::AccountDeleted, $freshUser);
$freshUser->delete();
```

### 変更後コード

```php
// 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)。
//    **削除実行時点の凍結状態と到達経路を残す** (bug-hunt F-4-Q1)。
//    再現しなかった「凍結中なのに削除された」観測に対し、**再発時に原因へ到達できる**ようにする。
//    ★これは観測であって防御ではない — この値で分岐する処理は 1 つも無い。
$request = request();
$this->recorder->record(SecurityEventType::AccountDeleted, $freshUser, [
    // 行ロック下で読み直した $freshUser から取る (削除と同一トランザクション内)
    'deletion_requested' => $freshUser->deletion_requested_at !== null,
    // HTTP コンテキスト外 (猶予期間の日次執行など) からも呼ばれるので null を正常値として許す
    'route' => $request?->route()?->getName(),
    'method' => $request?->method(),
]);
$freshUser->delete();
```

- **PII は載せない** (bool と route 名と HTTP メソッドのみ)。
- `deletion_requested_at` の読み出しは既にロック下の `$freshUser` から。

### PHPStan 適合

- `request()` は常にインスタンスを返すが、コンソール実行では `route()` が null。
  `?->` で辿るため `string|null` に落ちる。metadata は `array<string, mixed>` を受ける。

---

## 施策 2: 契約 (既存 `AccountDeletionFreezeTest` へ追記)

| # | 契約 | 検査 |
|---|---|---|
| 1 | **XHR/JSON の DELETE で 409**、かつ**その user が消えていない** | `deleteJson('/settings/account')` → 409 / `User::whereKey($id)->exists()` が true |
| 2 | **recent-auth を満たしていても 409** (step-up を通過しても凍結が優先) | `withSession(freshRecentAuthSession())` つきで 409 |
| 3 | **recent-auth を満たしていなくても 409** (順序の決定。step-up challenge を先に返さない) | session 無しで 409 (302/401 ではない) |
| 4 | 凍結中に即時削除を試みた後、**取消 → 削除ができる** | 409 → `deletion-request` の DELETE → 即時削除が通る |
| 5 | `AccountDeletionFreezeAllowance` に `settings.account.destroy` が**入っていない** | enum の全 case を集めて名指しで不在を assert (allowlist へ足した瞬間に赤くなる) |
| 6 | **2FA 必須組織 × 凍結中**でも即時削除は 409 | 2FA 必須組織の未準拠メンバーで JSON DELETE → 409 |
| 7 | 監査 metadata に `deletion_requested` / `route` / `method` が載る | 通常の (凍結していない) 即時削除で `AccountDeleted` イベントの metadata を検査 |

**契約 3 が「順序の決定」を固定する**。実行順が変わっても 409 が正であり、
middleware priority の偶然を追認しない (概念設計の決定)。

### fail 先行

契約 1..4 / 6 は**現行実装でも緑**の見込み (実装は既に正しい)。**赤くなるのは契約 7 だけ**である。
これは「観測ギャップを閉じる」TODO の性質上正しい —
**テストは既存の正しい挙動を固定するために足す**。実測して記録する。

### mutation 計画

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | `AccountDeletionFreezeAllowance` に `settings.account.destroy` を足す | 契約 1・2・3・5・6 |
| M2 | middleware の `expectsJson()` 分岐を消し常に redirect にする | 契約 1・3 |
| M3 | metadata の `deletion_requested` を落とす | 契約 7 |
| M4 | metadata の `route` / `method` を落とす | 契約 7 |
| M5 | `deletion_requested` の値を常に false にする | 契約 7 (**凍結中の削除を記録する意味が消える**ため、値まで見る) |

## 実装モード

incremental (1 サービス 1 箇所 + テスト + docs)。競合リスクなし。

## 保証しないもの (誇張しない)

- **観測された 1 件の原因は特定していない**。本 TODO は契約テストと監査 metadata を足すだけで、
  原因特定や防御追加は行わない。
- **並行実行 (ブラウザ遷移と fetch の競合) は再現しない**。Feature テストは 1 リクエストずつ
  順に実行するため、探索エージェントが疑った競合そのものは検査できない。
  その代わりが監査 metadata である。
- **防御は増えない**。`deletion_requested` の値で分岐する処理は作らない。
