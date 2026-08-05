# 多角監査 サイクル2 — 使命整合性 (Mission Alignment)

対象: `4cbdff8..HEAD` (T099〜T106、29 コミット)
監査日: 2026-08-05
基準: `AGENTS.md` §使命 (North Star) および v1 スコープ宣言

---

### 使命整合性: DRIFT_DETECTED

全体としては「使命を守るための地盤」に該当する変更が多数を占めるが、
**2 件 (T100 の CLI トラック / T106 の passkey) が「c2c 台帳が言ったから」を主因とする
プロダクト外投資**であり、v1 スコープ宣言に照らして正当化が弱い。

---

## 1. 各 TODO の判定

| ID | 判定 | 根拠 (1 行) |
|---|---|---|
| T099 グローバルテストロック | **OK (コスト過大)** | worktree 並走の偽赤除去は自走ループの実効速度に直結。ただし `scripts/global-test-lock.sh` 379 行 + `verify-global-test-lock.sh` 1376 行 + `GlobalTestLockInventoryTest` 423 行 = 開発補助 1 機能に約 2,200 行は明らかに重い |
| T100-A Codex モデル統一 | **OK (低コスト・間接)** | 実コード差分は skill 4 ファイル + テスト 129 行のみ。設計書自身が「使命に直接寄与しない」と明記しており過大主張がない点は良い |
| T100-B CLI (`packages/cli`) | **DRIFT** | 下記 §2-A。**最大の逸脱** |
| T101 Architecture gate 4 本 | **OK** | `CarbonOverflowArithmeticGateTest` は課金期日の月末ズレという実バグ種の予防、`DocumentTitleCoverageTest` は現場作業者が見るタブタイトルの網羅。既存違反 8 + 2 + 4 件を実際に是正しており空振りではない |
| T102 フロント baseline | **OK (使命に最も近い基盤投資)** | `videoConstraints()` を `CameraRecorder.svelte` から `lib/capture/camera.ts` へ移設 = **撮影 PWA の白画面事故**の予防。`danger` を red-600→red-700 に是正して AA 割れ解消 = 明るい工場現場での可読性に直結 |
| T103 認可 gate + 存在オラクル封じ | **OK (実バグ是正あり。ただし §2-C の留保)** | `ControllerAuthorizationGateTest` が変更系 61 route を deny-by-default で機械強制。AGENTS.md セキュリティ不変条件 #2/#3 の実装。ただし**実際に塞いだ穴はテンプレ見本リソース `Item` の API** であり、`VideoManual`/`Cut`/`Take` 側に穴は無かった |
| T104 CI レーン統合 | **OK (本サイクル最重要。使命防衛そのもの)** | 設計書の実測: **CI で走っていた PHP テストは 0 件** (`ensure-test-db` で exit 1)、**Browser テストも 0 件**。つまり T001〜T098 で積んだシナリオ整合ロック規約・容量 Quota 規約・bfcache 3 枚セットの回帰網が**丸ごと偽グリーンだった**。これを 2704 件 + 14 件×2 レーンへ回復。加えて `audit-gate.sh` の偽グリーン 4 経路を実測再現して修正 |
| T105 SSO email trust policy | **OK** | nOAuth (IdP 主張 email の無条件信頼) の継ぎ目。実コード約 190 行と小さく、fail-closed 既定 + `SocialProviderTrustPolicyTest` で機械固定。google は挙動不変。費用対効果良好 |
| T106 passkey + ログイン手段保持 guard | **部分 DRIFT** | 下記 §2-B。`EnsureLoginMethodRemains` は OK、passkey 本体は v1 スコープ外の機能追加 |

---

## 2. 逸脱の詳細と対応

### A. 【最重要】T100-B: 未ブランディングのテンプレ CLI への投資

**事実 (実測)**:

- `packages/cli/src/branding.ts:15` — `export const APP_SLUG = "app";` (aicue へ未初期化)
- `packages/cli/package.json` — `"name": "@app/cli"` / `"bin": "app"`
- `config/template.php:22` — `'slug' => env('TEMPLATE_APP_SLUG', 'app')` (既定のまま)
- `packages/cli/README.md` — 「ドメイン固有コマンドはテンプレートには含めない」= **aicue ドメインのコマンドは 1 本も無い**

つまり `packages/cli` は **テンプレート由来の足場のまま一度も aicue 化されておらず、
配布もされていない**。にもかかわらず本サイクルで以下を投資している:

| コミット | 内容 | 行数 |
|---|---|---|
| `6ab5402` | config 保存の atomic replacement 化 | 95 |
| `0040511` | `profile:delete` コマンド新設 | 実装 352 / テスト 1,074 |
| `fb062cd` | Codex 指摘反映 (Round 3) | 実装 25 / テスト 253 |
| `4f9e7e8` `d560d93` | `typecheck:packages` / `test:packages` / `build:packages` を CI へ配線 | — |

**使命との関係**: AI-CUE の利用者は「専門知識ゼロの現場作業者」であり、
接点はスマホ PWA と PC 編集面。**OAuth プロファイル管理 CLI + keychain は
この使命の受益者を 1 人も持たない**。v1 スコープ (字幕のみ / PWA 撮影 / 自前 ffmpeg /
単一 Default Project) にも CLI は一切現れない。

設計書 (`devnotes/20260805-0101-devtool-template-followup/conceptual-design.md:410-418`) は
正直に「使命に直接寄与しない」「本番 API キーを消せない状態は事故時の封じ込め手段が無い」と
書いているが、**そもそも配布されていない CLI に本番 API キーは入っていない**ため
この論拠は成立しない。

**思考原則 2「今必要なものだけ作る (オーバーエンジニアリング禁止)」に抵触**する。

**提案 (次サイクルの設計課題)**:

1. `packages/cli` の**存続判断を先に下す**。aicue に CLI 面が要るのか (要るなら誰が使うのか) を
   決めずに機能を足し続けない。T052 (`capture.manuals.sync` の「フロント配線 or 廃止判断」) と
   同じ形の判断 TODO を立てるのが本リポジトリの作法に沿う。
2. 存続させるなら**まず `init.sh` 相当を通して aicue へブランディングする**
   (`APP_SLUG = "aicue"` / `TEMPLATE_APP_SLUG`)。未ブランディングのまま機能を足すのは
   「テンプレの機能をテンプレのまま太らせている」だけで、aicue の資産になっていない。
3. 廃止するなら `packages/` ごと削除し、CI の 3 レーン配線も同時に落とす
   (思考原則 3「後方互換の並走を残さない」)。

### B. T106: passkey は v1 スコープ外の機能追加

**内訳の切り分けが必要**:

- `EnsureLoginMethodRemains` / `LoginMethodInventory` / SSO の phantom password 撤去
  → **OK**。「唯一のログイン手段を消して自分を締め出す」は現場を止める実害であり、
  T025 (唯一オーナー削除ガード) と同じ系統の正当な防御。
- passkey 本体 (`Passkey` モデル + migration + `PasskeyServiceProvider` +
  `SelfScopedPasskeyBinder` + Response contract 4 本 + `PasskeySection.svelte` 256 行 +
  `lib/passkeys.ts` 391 行 + テスト 8 ファイル)
  → **v1 スコープ外の新機能**。

**根拠**:

- v1 スコープは「撮影は PWA (同一オリジン・**セッション認証**)」と宣言している。
  passkey はセッションを張る手段の追加であり、スコープ宣言が要求したものではない。
- 設計書自身が §0 で「**passkey route は 1 本も生えていない / 露出は現時点で存在しない**」と
  実測している。つまり**塞ぐべき穴は無かった**。着手理由は
  `devnotes/20260805-1244-auth-method-and-passkey/conceptual-design.md:3-9` の
  「c2c 台帳 `auth-passkey` = `pending`」という**家系台帳の都合**である。
- 「手袋・保護具で現場入力の摩擦が減る」という使命論拠 (同 :644-652) は筋は通るが、
  これは**プロダクト側から一度も要求されていない仮説**であり、
  bug-hunt findings にも TODO にも「ログインの入力摩擦」の報告は無い。

**判定**: 有害ではない (実装品質は高く、Codex APPROVED / Critical 4 件是正済み)。
ただし **「露出が無いと自分で実測した機能を、台帳が pending だからという理由で
約 2,000 行かけて実装した」**のは投資順序として逆。

**提案 (記録に留める)**:

- 次サイクル以降、**c2c 台帳の `pending` は着手理由にしない**という運用線を引く。
  台帳項目は「aicue のプロダクト課題に翻訳できたときだけ Open へ昇格」させる
  (TODO.md の Conditional テーブルはまさにこの用途に使える)。
- passkey は既に入ったので削除は求めない (思考原則 3 に照らしても後戻りのほうが高コスト)。
  ただし**実利用データを観測する**こと。現場作業者の passkey 登録率が有意に立たなければ、
  この判断が誤りだった証拠として次の台帳追従の判断材料にする。

### C. T103 の留保 (逸脱ではない)

塞いだ存在オラクル・認可漏れは `Api\V1\ItemController` = **テンプレ見本リソース**のもので、
使命の中核ドメイン (`VideoManual` / `Cut` / `Take` / `RenderJob`) には穴が無かった。
gate 自体は変更系 61 route 全体に効くので価値は本物だが、
「実バグを 1 件是正した」という説明は**見本リソースの実害度**を割り引いて読むべき。

---

## 3. 「基盤整備に偏りすぎ」という懸念の評価

### 結論: **懸念は半分だけ妥当**。偏り自体は妥当だが、偏りの中身に 2 件の無駄がある。

#### 3-1. 定量的な事実

```
全体             382 files  +139,168 / -843
devnotes 除く    177 files  + 17,863 / -843
  app+resources+routes+database+config   62 files  + 2,762
  tests+scripts+.github                  67 files  +11,718
  packages/                              22 files  + 1,891
  docs+AGENTS/DESIGN/.claude             16 files  +   691
```

使命の中核ドメイン (SOP / シナリオ / 解析 / 撮影 / レンダ) に触れた行は
**`CameraRecorder.svelte` 10 行 + `lib/capture/camera.ts` 15 行 = 25 行のみ**。
テスト・スクリプト・CI が実装の **4.2 倍**。額面どおりに見れば極端な偏りである。

#### 3-2. それでも「偏りは妥当」と判定する根拠

**根拠 1 — プロダクト側のバックログが実際に枯れていた**。
サイクル開始時点の `docs/TODO.md` の Open は **T085 (bfcache の iOS 実機受入確認) 1 件のみ**で、
これは `standalone` かつ**実機が要る = エージェントが着手できないタスク**。
Conditional は空。直近の bug-hunt (`devnotes/20260803-203721-bug-hunt/findings.jsonl`) の
findings も T089〜T098 で全件消化済み。
**「プロダクト機能を後回しにして基盤をやった」のではなく「消化できるプロダクト作業が無かった」**。

**根拠 2 — T104 で発覚した事実が、直前サイクルまでの成果を無効化しかけていた**。
`devnotes/20260805-1243-ci-lane-integration/conceptual-design.md` の実測表:

| 指標 | Before | After |
|---|---|---|
| CI で実行される PHP テスト | **0 件** (`ensure-test-db` で exit 1) | 2,704 件 |
| CI で実行される Browser テスト | **0 件** | 14 件 × 2 レーン |
| supply-chain gate | 人手 (思い出したときだけ) | PR blocking + nightly |

T001〜T098 で積んだ「シナリオ整合の共有ロック規約」「容量 Quota 予約規約」
「bfcache 3 枚セット」の回帰網は、**CI 上では 1 本も走っていなかった**。
これは使命に対する直接の脅威であり (現場で撮った素材が壊れる回帰を誰も検知できない状態)、
本サイクルで最優先に是正されたのは**投資判断として正しい**。
加えて未受容 high advisory 26 → 4 件 (high 15 → 0)、accept-risk 登録 0 件という
実質的な供給網リスク低減も伴っている。

**根拠 3 — 基盤投資のうち使命に近い側が実際に実バグを捕まえている**。
T101 は既存違反 8+2+4 件、T102 は `danger` の AA コントラスト割れ (明るい現場での可読性)、
T103 は cross-org 存在オラクル、T104 は audit-gate の偽グリーン 4 経路を、
いずれも**空振りではなく実測された欠陥として**是正している。
「gate を作ったが違反ゼロ」という無価値な gate はこのサイクルには無い。

#### 3-3. それでも懸念が半分妥当な理由

1. **T100-B (CLI) と T106 (passkey 本体) は上記 3 根拠のどれにも当てはまらない**。
   バックログ枯渇の穴埋めとして c2c 台帳の未着手項目を拾った結果であり、
   プロダクト課題からの逆算ではない。合計で実装 + テスト約 3,400 行。
2. **プロセス副産物のリポジトリ肥大**。devnotes が +121,305 行 (全体の 87%)。
   `impl-review-prompt-round-1.md` 単体で 7,556 行 (T106) / 5,064 行 (T101) など、
   Codex へ投げたプロンプト全文がリポジトリに恒久堆積している。
   AGENTS.md はレビュー機械出力の記録を求めているが、
   **プロンプト全文の保存までは要求していない**。次サイクルで
   「codex-history に残すのは decisions と review 応答のみ、prompt は要約」等の
   運用縮小を検討する価値がある (これは使命逸脱ではなく純粋な運用コスト)。
3. **プロダクト側のロードマップが不在**という構造問題。
   v1 スコープは宣言されているが「v1 の残りは何か」を追跡する場所が無い。
   TODO.md が空になると自動的に c2c 台帳に吸い寄せられる構造になっている。

---

## 4. 次サイクルへの申し送り (優先順)

1. **プロダクト側の Open を先に作る**。v1 宣言 (字幕のみ / TTS 後回し / PWA 撮影 /
   自前 ffmpeg / 単一 Default Project) に対する「未達の残り」と
   「v1 の次に着手する候補 (TTS / 複数 Project / 音声)」を棚卸しし、TODO.md に載せる。
   これが無い限り、次サイクルも台帳追従に吸い寄せられる。
2. **`packages/cli` の存続/廃止判断 TODO を立てる** (§2-A)。判断が出るまで機能追加を凍結。
3. **c2c 台帳 `pending` を直接の着手理由にしない運用線**を AGENTS.md か
   `app-todo-add` の受理条件へ明文化する (§2-B)。
   「aicue のプロダクト課題に翻訳できたものだけ Open へ昇格」。
4. **codex-history の保存粒度を縮小する検討** (§3-3-2)。
5. **T085 (iOS 実機受入確認) の実行計画**。撮影 PWA の主戦場 iOS Safari の
   bfcache 保証は使命の中核面でありながら、High のまま 2 サイクル滞留している。
   エージェントが着手できないタスクなので、人間側の段取りを明示的に置く。

---

## 5. v1 スコープ逸脱チェック (逐条)

| v1 宣言 | 本サイクルでの逸脱 |
|---|---|
| 字幕のみ (TTS 後回し) | **なし**。TTS / 音声合成に触れた変更は 0 |
| 撮影は PWA (同一オリジン・セッション認証) | **軽微**。passkey はセッションを張る手段の追加であり同一オリジン・セッション認証の前提は維持。ただし v1 が要求した機能ではない (§2-B) |
| 動画合成は自前 ffmpeg | **なし**。レンダ経路への変更 0。T104 が ffmpeg/fonts provision と `fc-match` fail-fast を CI で維持したのはむしろ保護 |
| 単一 Default Project | **なし**。Project モデルの多重化・切替 UI の追加は無い。T103 の `EnsureProjectBelongsToApiOrganization` は cross-org 遮断であり多 Project 化ではない |
| (スコープ外) CLI 面 | **逸脱**。v1 宣言に存在しない面へ約 1,900 行を投資 (§2-A) |
