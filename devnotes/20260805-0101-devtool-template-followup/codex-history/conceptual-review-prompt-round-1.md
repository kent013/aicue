## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の補足】
- 本バッチは開発ツール層（.claude/skills/ の手順書 + packages/cli の TypeScript CLI）であり、PHP/Laravel のプロダクションコード変更を含まない。観点 7 は主に TypeScript の型安全性・既存 API の再利用として読み替えて評価すること
- 本リポジトリ aicue は laravel-claude-template から生成されており、複数リポジトリ共有の機能台帳 c2c が「追従タスク」として本件 4 feature を挙げている
- 特に以下 7 つの「設計判断」の妥当性を厳しく検証してほしい（設計書の §設計判断 を参照）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---
## 概念設計

# 概念設計: devtool-template-followup

c2c 台帳の追従タスク 4 件 (`skill-codex-integration` / `skill-design-flow` /
`skill-implement-flow` / `cli-package-oclif`) を 1 バッチに統合した、
開発ツール層のテンプレート追従。

## 背景・課題

### 課題 A: Codex レビューモデルが旧世代のまま (skill 3 feature)

c2c 台帳 (2026-08-05 巡回) は aicue を以下と判定している:

| feature | 台帳判定 | 差分の実体 (台帳が機械確認済み) |
|---------|---------|--------------------------------|
| `skill-codex-integration` | t0 → t1 追従待ち | gpt-5.3/5.4 混在の旧版。codex-vscode には gpt-5.1/5.2 記述も残存 |
| `skill-design-flow` | pre-t0 → t0 追従待ち | **差分はレビューモデル指定のみ** (aicue=gpt-5.4/gpt-5.3-codex、template=gpt-5.5) |
| `skill-implement-flow` | pre-t0 → t0 追従待ち | **差分はレビューモデル指定のみ** (aicue=gpt-5.3-codex、template=gpt-5.5) |

3 件は独立した機能差ではなく「**gpt-5.5 一本化という同一テーマへの未追従**」である。
台帳自身が `skill-design-flow` の note で
「skill-codex-integration の t0→t1 と同一テーマの未追従」と明記している。

本リポジトリ実査 (2026-08-05) で確認した現状 = **`gpt-5.x` 記述は 9 箇所**:

| ファイル:行 | 記述 |
|------------|------|
| `.claude/skills/app-codex-vscode/SKILL.md:36` | 利用可能モデル表 `gpt-5.3-codex` = デフォルト |
| 同 `:37` | 利用可能モデル表 `gpt-5.4` = 自然言語中心・概念設計 |
| 同 `:51` | xhigh 対応表 `gpt-5.3-codex`, `gpt-5.4`, `gpt-5.2-codex`, `gpt-5.1-codex-max` |
| 同 `:53` | 旧モデル注記 `gpt-5-codex`, `gpt-5.1-codex`, `gpt-5` は xhigh 非対応 |
| `.claude/skills/app-codex-review/SKILL.md:100` | `-m {model}` の例示 (`gpt-5.3-codex` / `gpt-5.4` 等) |
| `.claude/skills/app-design/SKILL.md:58` | 重要原則: 概念=gpt-5.4 / 詳細=gpt-5.3-codex |
| 同 `:113` | Phase 1-3 概念設計レビュー **model**: `gpt-5.4` |
| 同 `:283` | Phase 2-3 詳細設計レビュー **model**: `gpt-5.3-codex` |
| `.claude/skills/app-implement/SKILL.md:178` | Phase A-2 実装レビュー **model**: `gpt-5.3-codex` |

`gpt-5.5` は `/workspace` 内に 1 箇所も存在しない。

**「単なる文字列の古さ」ではない**理由が 2 つある:

1. **旧世代モデルは実際に劣る**。レビュー品質は開発ループ全体の品質天井を決める。
   設計レビュー・実装レビューは `app-autopilot` の自走ループに組み込まれており、
   ここでの見落としがそのまま main に入る
2. **同一テーマの差分が 3 feature に散っている** = 個別対応すると
   「app-design だけ更新して app-implement を忘れる」という部分追従が起きる。
   実際、台帳が 3 件を別 feature として立てている構造がそのリスクを示している

### 課題 B: `packages/cli` に `profile:delete` が無い (cli-package-oclif)

テンプレ t0 のコマンド 10 本のうち、aicue は 9 本しか持たない。
欠けているのは `profile/delete` ただ 1 本 (台帳: 「起草の『aicue に profile/delete あり』は方向誤り」
と敵対的検証で訂正済み)。

台帳 history によれば `profile:delete` はテンプレ側の `template:T022` で
「新設 + 同期的な認証情報アクセスの封鎖」としてセットで入っている。
aicue は 2026-08-01〜03 のテンプレ進化に未追従で、この 1 本だけが落ちている。

実査で確認した aicue 側の現状:

- `packages/cli/src/profile/writer.ts:156-176` に **`deleteProfile()` は既にある**
  (`ProfileWriter` interface にも宣言済み。`clearDefault` オプション付き)
- 実際の呼び出し元は `profile/add.ts` の**ロールバック 2 箇所のみ**
  (L109 = 確認プロンプト拒否時、L131 = verify 失敗時の best-effort rollback)
- `CredentialStore.clearProfile()` (`src/credential/store.ts:227-235`) も既にある
- つまり「**部品は全部あるのに、ユーザーが呼べる入口が無い**」状態

これは単なる機能欠落ではなく、**セキュリティ上の詰み**を作っている:

- ユーザーが誤って本番 API キー / OAuth トークンを登録したとき、
  CLI からそれを消す手段が無い。`~/.app/credentials/{profile_hash12}/` を
  手で `rm -rf` させるしかない
- 手動削除は `~/.config` 側の `profiles` エントリと `default_profile` を残すので、
  「プロファイルは見えるが credential だけ無い」不整合状態を作る
- `profile:add` は既存名を拒否 (`ExitCode.ProfileAlreadyExists`) し、
  エラーメッセージで **``Run `profile:delete {name}` first to recreate.``**
  と存在しないコマンドを案内している (`src/oclif/commands/profile/add.ts:60-63`)

## 改善アイデア

### 施策 1: Codex レビューモデルを `gpt-5.5` に一本化する

`.claude/skills/app-*/SKILL.md` に現れる `gpt-5.x` 記述 9 箇所すべてを
`gpt-5.5` 単一モデルへ収束させる。用途別のモデル使い分け (自然言語 vs コード) を廃止する。

### 施策 2: `codex-model-consistency` アーキテクチャテストを新設する

`.claude/skills/app-*/SKILL.md` に canonical (`gpt-5.5`) 以外の `gpt-5.x`
が現れないことを deny-by-default で機械固定する。
検査対象ファイルが 0 件なら fail させ、パス変更によるガード無効化 (drift) を防ぐ。

### 施策 3: `app profile:delete <name>` コマンドを新設する

既存の `FileProfileWriter.deleteProfile()` と `CredentialStore.clearProfile()` を
再利用し、**config エントリと credential を同時に落とす**単一の入口を作る。

### 施策 4: `profile/delete` の 3 backend 横断テストを新設する

keychain / file-encrypted / file-plaintext の 3 backend それぞれで
credential が確実に消えること、`default_profile` が正しく処理されること、
**他プロファイルの master key / credential が生存すること**を固定する。

## 設計判断 (明示すべき論点)

### 判断 1: reasoning effort は現状維持する

台帳の版差 (t0 → t1) は**モデル名のみ**であり、reasoning effort を版差として挙げていない。
現行値は以下で、すべて維持する:

| 用途 | reasoning | 根拠 |
|------|----------|------|
| 概念設計レビュー (app-design 1-3) | `medium` | app-codex-vscode の規約「議論・分析・ブレスト用 = Claude が評価・選別する場面」に合致 |
| 詳細設計レビュー (app-design 2-3) | `high` | 同「コードレビュー・安全性判定用 = Codex 判断が直接品質に影響する場面」 |
| 実装レビュー (app-implement A-2) | `high` | 同上 |

**「モデルが上がったから effort も上げる」はしない**。思考原則
「仕組みが機能していない段階で値を弄るな」に従い、モデル一本化の効果を
先に確認する。effort は独立した軸であり、同一 PR で 2 つの変数を同時に動かすと
どちらが効いたか判定できない。

### 判断 2: 用途別使い分けの廃止は「レビューの性格の変化」を伴う — それでも追従する

aicue の現状は **意図的な使い分け**である:

- 概念設計 = `gpt-5.4` (自然言語中心の議論)
- 詳細設計 / 実装 = `gpt-5.3-codex` (コード分析・レビュー)

`gpt-5.5` 一本化は、この使い分けを捨てる。**概念設計レビューの性格が変わる**
(自然言語志向の議論相手 → コード志向を併せ持つ単一モデル) 可能性を認める。

それでも追従する理由:

1. **テンプレート/motivation が t1 で揃っている**。AGENTS.md「テンプレートとの関係」は
   「テンプレート構造からの**意図的な逸脱**は `docs/template-divergence.md` に
   logic-driven な理由と『保証し続ける不変条件』を記録してから行う」と定める。
   使い分けを維持するなら divergence として起票する必要があるが、
   その logic-driven な理由 = 「gpt-5.4 の方が概念設計で優れる」という**データを我々は持っていない**。
   思考原則「データに真摯に向き合え / 思い込みで機能を追加するな」に照らし、
   計測なき使い分けを divergence として固定するのは筋が悪い
2. **`gpt-5.4` / `gpt-5.3-codex` は `gpt-5.5` より旧世代**である。
   使い分けの利得より世代差の利得が大きいと見るのが自然
3. **後方互換の並走を残さない** (思考原則 3)。「概念設計だけ旧モデル」を残すと、
   次のモデル世代でも同じ判断を再度迫られ、追従コストが恒久化する
4. 実測: `scripts/codex exec -m gpt-5.5 -c model_reasoning_effort="xhigh"` は
   本 devcontainer で正常応答する (2026-08-05 検証、session `019fcd82`)。
   一本化後も xhigh を含む全 effort が使える

**逆転条件 (この判断を見直す基準)**: 一本化後の概念設計レビューで
「自然言語的な設計論点 (使命整合・スコープ妥当性) の指摘が痩せた」と
複数回観測されたら、`docs/template-divergence.md` に理由付きで起票のうえ
概念設計だけ別モデルへ戻す。**観測前に戻さない**。

### 判断 3: aicue 固有のレビュー観点は全て維持する

`app-implement/SKILL.md` A-2 の DESIGN.md 準拠 / Atomic Design 準拠、
`app-design/SKILL.md` 2-3 のレビュー観点 10 (DESIGN.md 準拠) / 11 (Atomic Design 準拠) は
**aicue 独自資産**であり、モデル指定の追従とは無関係。1 文字も触らない。
(AGENTS.md 実装規約: `DESIGN.md` が canonical、component 階層は
`atoms → molecules → organisms → features/{domain} → templates → pages` の単方向)

### 判断 4: `devnotes/` は絶対に一括置換しない

`devnotes/` 配下 **148 ファイル**が `gpt-5.4` / `gpt-5.3-codex` を含む。
これらは**過去のレビュー実績の記録** = 「どのモデルが何を指摘したか」という
再現不能な事実である。書き換えは履歴の改竄にあたる。

したがって施策 2 のアーキテクチャテストは **`devnotes/` を走査対象から外す**。
走査対象は `.claude/skills/app-*/SKILL.md` に限定する。

### 判断 5: `app-codex-vscode` の旧モデル注記は「残す」ではなく「消す」

台帳 origin note によれば、テンプレ t1 の codex-vscode には
「gpt-5.5 に一本化された」旨の**履歴記述**として旧モデル名が残っている。
aicue はこれを**採らない**:

- 施策 2 のテストは deny-by-default であり、履歴記述であっても
  旧モデル名の残存を許すと allowlist 例外が必要になる。
  例外は必ず腐る (次の世代で「これは履歴かどうか」の判断が必要になる)
- 思考原則 3「後方互換の並走を残さない」。単一モデルになった以上、
  xhigh 対応表の「モデルによって使えない」という**分岐そのものが消える**
- 一本化の経緯は本 devnotes と git history が保持する。SKILL.md は
  「今どう呼ぶか」の手順書であり、履歴の置き場ではない

これはテンプレからの逸脱ではなく**同じ結論への到達手段の差**
(テンプレは注記を残し、aicue は機械ガードで代替する) であり、
`docs/template-divergence.md` 起票の対象外とする。

### 判断 6: `profile:delete` は既存の 2 メソッドを合成するだけにする

新しい削除ロジックを書かない。`FileProfileWriter.deleteProfile()` (config 側) と
`CredentialStore.clearProfile()` (credential 側) を**この順で**呼ぶ薄い
`ProfileCommand` サブクラスとして実装する。

**順序は credential → config が正**。理由は下記「credential が孤児化しない順序」。

### 判断 7: master key registry の共有 master key を壊さない

`MasterKeyRegistry` は `deriveProfileHash12(origin, profile)` をキーとする
プロセス内キャッシュであり、**プロセスをまたいで永続化しない**
(`src/credential/master-key-registry.ts:34-51`)。
また `T148/YAGNI-09` で per-profile の `.salt` サイドカーは廃止済みで、
salt は各暗号化ファイルのヘッダに同梱される。

したがって「master key」は環境変数
(`<PREFIX>_CREDENTIAL_KEY` / `<PREFIX>_MASTER_PASSWORD`) 由来の
**全プロファイル共有の入力**であり、削除で壊れる永続資産は存在しない。
`FileStore.clearProfile()` が消すのは
`{baseDir}/{profile_hash12}/` ディレクトリ 1 つだけ
(`src/credential/file-store.ts:198-202`) で、他プロファイルの
ディレクトリには届かない。

**この性質を施策 4 のテストで固定する** = 「プロファイル A を消しても
プロファイル B の暗号化 credential が同じ master key で復号できる」ことを検証する。
将来 per-profile 鍵材料が導入されたとき、このテストが破れて設計判断を強制する。

## 期待効果

### 使命への貢献 (間接資産としての正直な位置づけ)

本バッチは**開発ツール層**であり、AI-CUE の使命
(SOP → シナリオ生成 → ナビ撮影 → 標準化マニュアル動画) に直接寄与しない。
寄与は間接的で、以下 2 経路に限られる:

1. **レビュー品質の底上げ** — 設計レビュー / 実装レビューは `app-autopilot`
   自走ループの品質ゲートであり、ここの見落としがシナリオ整合の共有ロック規約
   (AGENTS.md ドメイン固有規約 1) や容量 Quota 予約規約 (同 2) のような
   壊れると致命的な不変条件に届く
2. **CLI の運用安全性** — 現場作業者ではなく開発者/運用者が使う面だが、
   本番 API キーを消せない状態は事故時の封じ込め手段が無いことを意味する

**過大主張しない**: 「動画品質が上がる」等の効果は主張しない。

### 具体的な改善見込み

- c2c 台帳の追従タスク **4 件が同時にクローズ**する
  (skill 3 件は同一テーマなので個別対応より部分追従リスクが低い)
- 次のモデル世代 (gpt-5.6 等) への追従が **1 箇所 grep + テスト 1 本**で完結する
  (現状は 9 箇所を人手で探す必要がある)
- `profile:add` が案内する `profile:delete` が実在するようになり、
  **エラーメッセージの嘘が消える**
- 誤登録した credential の**自己回復手段**が生まれる

## 実装方針 (概要)

### 施策 1: モデル一本化 (4 ファイル・9 箇所)

| ファイル | 変更内容 |
|---------|---------|
| `.claude/skills/app-codex-vscode/SKILL.md` | 利用可能モデル表を `gpt-5.5` 単一行へ。xhigh 対応表の「対応モデル」列を全 effort 「全モデル (= gpt-5.5)」へ収束。旧モデル注記を削除 |
| `.claude/skills/app-codex-review/SKILL.md` | `-m {model}` 例示を `gpt-5.5` へ |
| `.claude/skills/app-design/SKILL.md` | 重要原則 (:58)・Phase 1-3 (:113)・Phase 2-3 (:283) を `gpt-5.5` へ。reasoning は medium/high のまま |
| `.claude/skills/app-implement/SKILL.md` | Phase A-2 (:178) を `gpt-5.5` へ。reasoning は high のまま |

レビュー観点・出力形式・セッション管理・ファイル保存規約は**一切触らない**。

### 施策 2: アーキテクチャテスト

`tests/js/architecture/codex-model-consistency.test.ts` を新設。
root `vitest.config.ts` の `include` は `tests/js/**/*.test.ts` を含むため配線不要。

- 走査: `.claude/skills/app-*/SKILL.md` (glob)
- 検知: `/gpt-5(?:\.\d+)?(?:-[a-z0-9-]+)?/` にマッチする全トークンを抽出し、
  `gpt-5.5` 以外を offender とする
- drift ガード: 走査できた SKILL.md が 0 件なら fail
- `devnotes/` は走査しない

### 施策 3: `profile:delete` コマンド

`packages/cli/src/oclif/commands/profile/delete.ts` を新設。

- `ProfileCommand` 継承、`persistentRequired = false`、`resolveMode = "if-needed"`
  (`profile/use.ts` と同じ作法。削除は**ローカル config への操作**であり、
  サーバ疎通も既存プロファイルの解決も要らない)
- flags: `--force` (確認プロンプトのスキップ)、`--clear-default`
  (default_profile を指しているプロファイルの削除を許可)
- 順序: (1) 存在確認 → (2) default 判定 → (3) 確認プロンプト →
  (4) **credential 破棄** → (5) config エントリ削除
- exit codes: `ExitCode.ProfileNotFound` (11) / `ExitCode.ProfileConflict` (10)
  = default_profile 指定なのに `--clear-default` 無し。**新しい exit code は足さない**
- `package.json` の oclif `topics.profile` は既にあるので追加不要

#### credential が孤児化しない順序

credential を先に消し、config を後に消す。逆順にすると config が消えた瞬間に
`api_url` を失い、`canonicalOrigin(api_url)` から導出される
`profile_hash12` が計算できなくなる = **credential ディレクトリが永久に孤児化する**。
`profile/add.ts` のロールバックが `deleteProfile` のみを呼ぶのは
「credential 書き込み前の巻き戻し」だからで、正常系の削除とは前提が違う。

### 施策 4: `profile/delete` テスト

`packages/cli/tests/profile/delete.test.ts` を新設。
`vitest.config.ts` の `include: ["tests/**/*.test.ts"]` に自動で乗る。

3 backend × 検証軸:

| backend | 実現方法 |
|---------|---------|
| keychain | in-memory Fake `Entry` ctor を `KeychainStore` に注入 (`tests/setup/credential-backend.ts` の `DISABLE_KEYCHAIN` を自スコープで解除) |
| file-encrypted | `<PREFIX>_CREDENTIAL_KEY` 投入 + `MasterKeyRegistry.ensure()` |
| file-plaintext | `setGlobalAllowPlaintextFlag(true)` + `CI` 除去 |

検証軸:
1. credential が消える (`store.read()` が null、file backend では実ファイルも消滅)
2. config エントリが消える (`writer.get(name)` が undefined)
3. `default_profile` が正しく処理される (`--clear-default` 無しなら拒否、有りなら剥がれる)
4. **他プロファイルの credential が生存し、同じ master key で復号できる**

## 制約・前提

- **この devcontainer に PostgreSQL は無い** (`DB_HOST=db` は未起動の docker-compose サービス)。
  本バッチは DB を使わないため影響しないが、**検証手段として `composer test` に依存しない**。
  検証は `pnpm test` (architecture テスト) と `pnpm test:packages` (CLI テスト) で完結する
- `pnpm test` / `pnpm test:packages` は `scripts/with-global-test-lock.sh` 経由
  (aicue:T099 でマージ済み)。並列実行時のロック競合は基盤側が処理する
- `skills-lock.json` は外部 skill (Stripe 公式) のみを管理し `app-*` を含まない = **更新不要**
- インストール済み codex バイナリ (v0.146.0-alpha.9.2) は `gpt-5.5` を認識する
  (2026-08-05 実測)。`scripts/codex` はモデル名をハードコードしない
- `packages/cli` は独立 vitest lane であり、app 側の inventory gate 対象外
  (c2c 台帳 `cli-package-oclif` の gates 節)
- PHP 側の変更は無い = PHPStan / Pint の対象外

## スコープ外

- **`devnotes/` 配下の書き換え** (判断 4)。過去のレビュー実績記録であり不可侵
- **reasoning effort の変更** (判断 1)
- **`app-design` / `app-implement` のレビュー観点・フロー変更** (判断 3)
- **他の CLI コマンド追加** (`profile:show` 等)。台帳の差分は `profile/delete` 1 本のみ
- **`cli-distribution` / `cli-shared-core-package`** = 別 feature (台帳 boundary で明示的に除外)
- **OAuth トークンのサーバ側 revoke**。`profile:delete` はローカル資産の破棄に限る
  (サーバ session revoke は `auth:logout` の責務。削除時に revoke を試みると
  「サーバ不達だと削除できない」という新しい詰みを作る)
- **c2c 台帳への `status_reported` 書き戻し**。実装・push 完了後の別作業
- **他の追従タスク** (`atomic-design-gates` / `browser-test-lane` 等 11 件)

