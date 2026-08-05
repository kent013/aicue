# 概念設計: devtool-template-followup

c2c 台帳の追従タスク 4 件 (`skill-codex-integration` / `skill-design-flow` /
`skill-implement-flow` / `cli-package-oclif`) を 1 バッチに統合した、
開発ツール層のテンプレート追従。

> **日付表記について**: 本書に現れる日付・時刻は**すべて JST (UTC+9)** である。
> 本リポジトリの devnotes 命名規約が `TZ=Asia/Tokyo date +%Y%m%d-%H%M`
> (`.claude/skills/app-design/SKILL.md` §1-1) であり、c2c 台帳の inbox も JST で出力されるため。
> 設計着手は JST 2026-08-05 01:01 (= UTC 2026-08-04 16:01)。

## 背景・課題

### 課題 A: Codex レビューモデルが旧世代のまま (skill 3 feature)

c2c 台帳 (JST 2026-08-05 巡回) は aicue を以下と判定している:

| feature | 台帳判定 | 差分の実体 (台帳が機械確認済み) |
|---------|---------|--------------------------------|
| `skill-codex-integration` | t0 → t1 追従待ち | gpt-5.3/5.4 混在の旧版。codex-vscode には gpt-5.1/5.2 記述も残存 |
| `skill-design-flow` | pre-t0 → t0 追従待ち | **差分はレビューモデル指定のみ** (aicue=gpt-5.4/gpt-5.3-codex、template=gpt-5.5) |
| `skill-implement-flow` | pre-t0 → t0 追従待ち | **差分はレビューモデル指定のみ** (aicue=gpt-5.3-codex、template=gpt-5.5) |

3 件は独立した機能差ではなく「**gpt-5.5 一本化という同一テーマへの未追従**」である。
台帳自身が `skill-design-flow` の note で
「skill-codex-integration の t0→t1 と同一テーマの未追従」と明記している。

本リポジトリ実査 (JST 2026-08-05) で確認した現状 = **`gpt-5.x` 記述は 9 箇所**:

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

1. **レビュー品質は開発ループ全体の品質天井を決める**。設計レビュー・実装レビューは
   `app-autopilot` の自走ループに組み込まれており、ここでの見落としがそのまま main に入る。
   モデル世代の更新には**一般的な改善を期待するが、本リポジトリのレビュー品質への効果は未検証**
   である (判断 2 の逆転条件で観測する)
2. **同一テーマの差分が 3 feature に散っている** = 個別対応すると
   「app-design だけ更新して app-implement を忘れる」という部分追従が起きる。
   実際、台帳が 3 件を別 feature として立てている構造がそのリスクを示している

### 課題 B: `packages/cli` に `profile:delete` が無い (cli-package-oclif)

テンプレ t0 のコマンド 10 本のうち、aicue は 9 本しか持たない。
欠けているのは `profile/delete` ただ 1 本 (台帳: 「起草の『aicue に profile/delete あり』は方向誤り」
と敵対的検証で訂正済み)。

台帳 history によれば `profile:delete` はテンプレ側の `template:T022` で
「新設 + 同期的な認証情報アクセスの封鎖」としてセットで入っている。
aicue は JST 2026-08-01〜03 のテンプレ進化に未追従で、この 1 本だけが落ちている。

実査で確認した aicue 側の現状:

- `packages/cli/src/profile/writer.ts:156-176` に **`deleteProfile()` は既にある**
  (`ProfileWriter` interface にも宣言済み。`clearDefault` オプション付き)
- 実際の呼び出し元は `profile/add.ts` の**ロールバック 2 箇所のみ**
  (L109 = 確認プロンプト拒否時、L131 = verify 失敗時の best-effort rollback)
- `CredentialStore.clearProfile()` (`src/credential/store.ts:227-235`) も既にある
- つまり「**部品は全部あるのに、ユーザーが呼べる入口が無い**」状態

これは単なる機能欠落ではなく、**運用上の詰み**を作っている:

- ユーザーが誤って本番 API キー / OAuth トークンを登録したとき、
  CLI からそれを消す手段が無い。`~/.app/credentials/{profile_hash12}/` を
  手で `rm -rf` させるしかない
- 手動削除は user config 側の `profiles` エントリと `default_profile` を残すので、
  「プロファイルは見えるが credential だけ無い」不整合状態を作る
- `profile:add` は既存名を拒否 (`ExitCode.ProfileAlreadyExists`) し、
  エラーメッセージで **``Run `profile:delete {name}` first to recreate.``**
  と存在しないコマンドを案内している (`src/oclif/commands/profile/add.ts:60-63`)

### 課題 C (Round 1 レビューで発見): `packages/cli` のテストは CI で 1 度も走らない

`.github/workflows/ci.yml` は `php` / `frontend` の 2 job のみで、
**`pnpm test:packages` も packages 側 `typecheck` も実行されない**。
AGENTS.md §実装規約 の検証コマンド一覧にも `test:packages` は無い。

この状態のまま施策 4 のテストを書いても「置いてあるだけ」になり、
AGENTS.md 禁止事項 1 (テストなしの実装完了報告 = 不変条件は対応するテストへの
登録まで含めて「実装済み」) を満たさない。既存の `packages/cli/tests/` 7 本も
同じ穴に落ちている。

## 改善アイデア

### 施策 1: Codex レビューモデルを `gpt-5.5` に一本化する

`.claude/skills/app-*/SKILL.md` に現れる `gpt-5.x` 記述 9 箇所すべてを
`gpt-5.5` 単一モデルへ収束させる。用途別のモデル使い分け (自然言語 vs コード) を廃止する。

### 施策 2: `codex-model-consistency` アーキテクチャテストを新設する

`.claude/skills/app-*/SKILL.md` に canonical (`gpt-5.5`) 以外の `gpt-5.x`
が現れないことを deny-by-default で機械固定する。
走査対象は**明示 inventory と実測 glob の集合一致**で守る (drift ガード)。

### 施策 3: `app profile:delete <name>` コマンドを新設する

既存の `CredentialStore.clearProfile()` と `ProfileWriter.deleteProfile()` を
再利用し、**credential と config エントリを同時に落とす**単一の入口を作る。
(`FileProfileWriter` は `ProfileWriter` の実装として `deleteProfile` を拡張する側であり、
コマンドが直接名指しすることはない)

### 施策 4: `profile/delete` の 3 backend 横断テストを新設する

keychain / file-encrypted / file-plaintext の 3 backend それぞれで
credential が確実に消えること、`default_profile` が正しく処理されること、
**他プロファイルの master key / credential が生存すること**を固定する。

### 施策 5: `packages/cli` の検証を CI と規約に配線する

施策 4 のテストが実際に走る状態を作る。**新 job は作らず**、既存 `frontend` job に
2 ステップ追加するだけに留める (`ci-multi-lane-workflow` の裁定を先取りしない)。

### 施策 6: `saveConfigToPath` を atomic replacement にする (+ その不変条件テスト)

`config/saver.ts:13-21` の JSDoc は
「**Atomically** write a RootConfigInput to the given path」と宣言しているが、
実装は素の `writeFileSync` で **tmp+rename を行っていない** (実査で確認)。
同じパッケージ内に `credential/atomic-write.ts` の
`atomicWriteFile()` (tmp write → fsync → rename) が既にあるのに使っていない。

施策 3 の安全性は「config への書き込みは 1 回だけ」に依存しており、
その 1 回が途中で切れると**全プロファイルを一度に失う**。
`atomicWriteFile()` に差し替えて、**宣言と実装を一致させる**。

> **用語**: これは **atomic replacement** (対象ファイルを中途半端な内容で置換しない)
> であって、**クラッシュ後の durability ではない**。`atomicWriteFile()` は
> 一時ファイルを fsync するが**親ディレクトリを fsync しない**ため、
> 電源断後に rename 結果が残る保証まではしない。
> 完全な durability が要るなら親ディレクトリ fsync を別途検討する = **本バッチのスコープ外**。

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
   一般的な世代更新による改善を期待するが、
   **本リポジトリのレビュー品質への効果は未検証**であり、断定はしない
3. **後方互換の並走を残さない** (思考原則 3)。「概念設計だけ旧モデル」を残すと、
   次のモデル世代でも同じ判断を再度迫られ、追従コストが恒久化する
4. 実測: `scripts/codex exec -m gpt-5.5 -c model_reasoning_effort="xhigh"` は
   本 devcontainer で正常応答する (JST 2026-08-05 検証、session `019fcd82`)。
   一本化後も xhigh を含む全 effort が使える

#### 逆転条件 (逸失欠陥 = escaped defect の追跡で測る)

「指摘件数」は品質指標にならない。**良い設計なら Warning ゼロが正常**であり、
「指摘が出ない = モデルが痩せた」は論理として成立しないためである。
代わりに**概念設計レビューをすり抜けた欠陥**を数える:

- **観測対象**: 一本化後**最初の 5 件**の概念設計について、
  後続フェーズ (詳細設計レビュー / 実装レビュー / 実装中の手戻り) で
  **初めて発見された「概念設計段階で気づけたはずの欠陥」**
- **分類**: その欠陥が「自然言語的な設計論点 (使命整合・スコープ・リスク)」に属するか、
  「コード寄りの論点」に属するかを devnotes に記録する
- **トリガー (判定ではない)**: 前者 (自然言語的な論点) の逸失が **5 件中 3 件以上**で
  発生したら、**divergence の検討を開始する安全トリガー**とする。
  逸失欠陥は設計難易度やテーマの偏りでも増減するため、
  これ単体では「一本化が原因」と結論できない
- **戻す最終判断には比較確認を必須とする**: トリガーが引かれたら、
  該当する概念設計を旧 `gpt-5.4` に**再レビューさせ**、
  逸失した論点を旧モデルなら捕まえられたかを確認する。
  捕まえられなければモデルは原因ではない (プロンプト・観点セット側を疑う)
- **判定時期**: 5 件到達時点。母数がそれ未満のうちは判断しない
- **戻し方**: 上記の比較確認を通ったときのみ、`docs/template-divergence.md` に
  「保証し続ける不変条件」付きで起票してから概念設計だけ別モデルへ戻す。
  **観測前に戻さない**

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

### 判断 6: 既存 2 メソッドの再利用を基本とし、config 側だけは原子的な状態遷移に拡張する

コマンド本体は `CredentialStore.clearProfile()` (credential 側) と
`ProfileWriter.deleteProfile()` (config 側) を**この順で**呼ぶ薄い
`ProfileCommand` サブクラスとする。新しい削除ロジックは書かない。
コマンドが依存するのは**インターフェース `ProfileWriter`** であり、
具体実装 `FileProfileWriter` には依存しない (実装は `resolveContext()` が注入する)。

ただし **「2 メソッドの再利用のみ」という当初の制約は撤回する**。理由は原子性である
(Round 2 レビューの Critical):

- `deleteProfile(name, { clearDefault: true })` は
  `profiles` からの除去と `default_profile` の削除を**1 回の `save()`** で行う
  (`writer.ts:156-176`)
- そのあとに `useDefaultProfile(next)` を呼ぶと**2 回目の `save()`** になる。
  1 回目と 2 回目の間で config は「default_profile 不在」の状態でディスクに書かれ、
  2 回目が失敗すればその中間状態が永続化する

したがって `ProfileWriter.deleteProfile()` に **`nextDefault?: string` を追加**し、
削除と default 遷移を**1 回の `save()` に畳む**:

```ts
deleteProfile(
    name: string,
    opts?: { clearDefault?: boolean; nextDefault?: string },
): void;
```

**`nextDefault` の受理条件 (writer 側で強制。不正なら保存前に throw)**:

| 条件 | 判定 |
|------|------|
| `nextDefault` 指定 + 削除対象が現在の `default_profile` + `clearDefault === true` | **受理** (唯一の正当な組合せ) |
| `nextDefault` 指定 + 削除対象が `default_profile` でない | throw (default に触る理由がない) |
| `nextDefault` 指定 + `clearDefault !== true` | throw (default 変更の意思表示が無い) |
| `nextDefault` が `profiles` に存在しない | throw |
| `nextDefault === name` (削除対象自身) | throw |

throw 時は **`save()` を一切呼ばない** = config は 1 バイトも変わらない。

- コマンド層は `RootConfigInput` / `ProfileEntry` を直接組み立てない
  (`ProfileWriter` 抽象を迂回しない)
- 既存呼び出し元 (`profile/add.ts` のロールバック 2 箇所) は opts 省略で**挙動不変**。
  後方互換の並走は生まれない (思考原則 3 に抵触しない)
- `store.ts` / `file-store.ts` / `master-key-registry.ts` は**無変更**

#### 「原子的」の定義 (物理的原子性とは別物)

本設計で「1 回の `save()` に畳む」と言うのは **単一の論理更新**
(`profiles` 除去と `default_profile` 遷移が同じ 1 回の書き込みで反映される) のことであり、
**ファイル書き込みそのものの物理的原子性ではない**。

書き込みの **atomic replacement** (中途半端な内容で置換しない) は施策 6 で別途担保する。
さらにその atomic replacement も**クラッシュ後の durability ではない** (施策 6 の注記)。
3 者を分けて扱うのは、どれか 1 つで安心しないためである。

#### 順序は credential → config (逆順にしない)

config を先に消すと、その瞬間に `api_url` を失う。
credential の物理位置は `deriveProfileHash12(canonicalOrigin(api_url), name)` から
導出されるため、`api_url` を失うと**credential ディレクトリを二度と特定できない**
= 永久に孤児化する。

`profile/add.ts` のロールバックが `deleteProfile()` のみを呼ぶのは
「credential 書き込み**前**の巻き戻し」だからで、正常系の削除とは前提が違う。

#### 冪等性契約 (部分失敗時の収束)

config 側は 1 回の `save()` で原子的になったが、**credential ストアと config は依然 2 ストア**
なので全体の原子性は得られない。代わりに**再実行で必ず収束する**ことを契約にする:

| 状態 | 挙動 |
|------|------|
| credential 不在 (既に消えている / そもそも登録していない) | `clearProfile()` が no-op 成功。コマンドも成功で終える |
| credential 破棄成功 → config 保存失敗 | `catch` して stderr に**再実行コマンド文字列を実文字で出力**し、**元の例外を再 throw** する (oclif 既定 exit 1)。新しい例外型は作らない。再実行時は credential 不在パスを通り成功する |
| config 削除成功後の再実行 | `writer.get(name)` が undefined → `ExitCode.ProfileNotFound` (11)。既存の `profile:use` と同じ作法 |

> **収束契約の限定 (詳細設計 Round 3 の refinement)**: 「再実行で収束する」が成り立つのは
> 通常経路と config 保存失敗まで。**keychain の credential index 破損**だけは
> `fail-closed` = config を残して exit 18 で停止し、OS keychain の手動清掃という
> 外部操作を要求する。config を消すと api_url を失い、取りこぼした秘密が
> 到達不能な孤児として固定されるため (詳細設計 §施策 3)。

**3 backend すべてで冪等である根拠** (実装実査):

| backend | not-found 時の挙動 | 根拠 |
|---------|------------------|------|
| keychain | `deletePassword()` の not-found を `isNotFoundError()` で握って `return` | `credential/keychain.ts:130-152` |
| file-encrypted / file-plaintext | `clearProfile()` はディレクトリを `existsSync` guard 付きで `rmSync` | `credential/file-store.ts:198-202` |
| 共通 (index) | index 不在なら `readIndex()` が `[]` を返し空ループ。meta index の `delete` も backend 側で not-found を吸収 | `credential/store.ts:227-235` |

**この冪等性を施策 4 のテストで 3 backend 共通ケースとして固定する**。

### 判断 7: master key registry の共有 master key を壊さない

`MasterKeyRegistry` は `deriveProfileHash12(origin, profile)` をキーとする
**プロセス内キャッシュ**であり、プロセスをまたいで永続化しない
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

> **偽陽性の排除 (必須)**: この検証は**プロセス内キャッシュを必ず捨ててから**行う。
> `resetGlobalMasterKeyRegistryForTests()` を呼び、`MasterKeyRegistry` /
> `FileStore` / `CredentialStore` を新しいインスタンスで組み直してから B を読む。
> 削除前に読んだ B の鍵がキャッシュに残ったまま検証すると、
> 実際には壊れていても緑になる。

### 判断 8: default_profile を指すプロファイルの削除挙動

`resolveProfile()` は `default_profile` 不在時に builtin `"production"` へ
フォールバックし、未登録なら `ProfileResolutionError.notFound` で止まる
(`src/profile/resolve.ts:158-181`)。案内なしで剥がすと詰みに近い。

| ケース | 挙動 |
|--------|------|
| 削除対象が default、`--clear-default` **無し** | `ExitCode.ProfileConflict` (10) で拒否。既存 `writer.deleteProfile()` のエラー文言と同じ趣旨 (``--clear-default か profile:use を先に``) を stderr へ |
| 削除対象が default、`--clear-default` 有り、**残 1 件** | `deleteProfile(name, { clearDefault: true, nextDefault: 残 1 件 })` で**1 回の save** に畳んで付け替える。曖昧さがゼロなので自動でよい。付け替え先を stdout に明示ログ |
| 削除対象が default、`--clear-default` 有り、**残 0 件** | `default_profile` を未設定のまま。「`profile:add` から始めよ」と案内 |
| 削除対象が default、`--clear-default` 有り、**残 2 件以上** | `default_profile` を未設定のまま。候補一覧 + `profile:use <name>` を案内 (勝手に選ばない) |
| 削除対象が default でない | `default_profile` に触れない |

残プロファイルの判定 (`writer.list()`) は**削除前**に済ませ、
`nextDefault` を確定させてから 1 回だけ `deleteProfile()` を呼ぶ。

「残 1 件のときだけ自動で付け替える」のは、AGENTS.md ドメイン固有規約 4 が示す
**「行き先のない詰みを作らない」**の適用である
(403 で突き放さず専用画面で受ける、と同じ思想)。2 件以上で勝手に選ばないのは、
どれを選んでも根拠がないため。

## 型安全方針

本件は PHP を含まないため PHPStan の縛りが効かない。代わりに以下を受け入れ条件に含める:

- `any` / ad-hoc な `as` cast を**新規に導入しない**
- `ExitCode` / `ProfileWriter` / `CredentialStore` / `ProfileCommand` の
  **既存の型定義をそのまま使う** (削除のためだけの新しい型・例外クラスを作らない)。
  `ProfileWriter.deleteProfile` の `opts` 拡張のみ既存インターフェース上で行う
- **削除と default 遷移は `ProfileWriter` の型付き API 経由のみ**。
  コマンド層は `RootConfigInput` / `ProfileEntry` を直接組み立てない
- `packages/cli/tsconfig.json` は **`exactOptionalPropertyTypes: true`** かつ
  `noUncheckedIndexedAccess: true`。`nextDefault` が無いときは
  **プロパティ自体を省略**する (`nextDefault: undefined` を渡さない)。
  既存コードも同じ作法を取っている (`profile/add.ts:77-87` の `init` 組み立て)
- 施策 2 の検出結果は `readonly string[]` / `ReadonlySet<string>` で扱い、
  strict mode 下で意図を明示する
- `pnpm typecheck` (アプリ側) と `pnpm typecheck:packages` (施策 5 で新設) が green

## 期待効果

### 使命への貢献 (間接資産としての正直な位置づけ)

本バッチは**開発ツール層**であり、AI-CUE の使命
(SOP → シナリオ生成 → ナビ撮影 → 標準化マニュアル動画) に直接寄与しない。
寄与は間接的で、以下 2 経路に限られる:

1. **レビュー品質の底上げ** — 設計レビュー / 実装レビューは `app-autopilot`
   自走ループの品質ゲートであり、ここの見落としがシナリオ整合の共有ロック規約
   (AGENTS.md ドメイン固有規約 1) や容量 Quota 予約規約 (同 2) のような
   壊れると致命的な不変条件に届く
2. **CLI の開発運用リスク低減** — 現場作業者向けの価値ではなく、
   開発者/運用者の面。本番 API キーを消せない状態は事故時の封じ込め手段が無いことを意味する

**過大主張しない**: 「動画品質が上がる」等の効果は主張しない。

### 確定する効果 (実装すれば必ず得られる)

- c2c 台帳の追従タスク **4 件が同時にクローズ**する
  (skill 3 件は同一テーマなので個別対応より部分追従リスクが低い)
- 次のモデル世代 (gpt-5.6 等) への追従が **1 箇所 grep + テスト 1 本**で完結する
  (現状は 9 箇所を人手で探す必要がある)。inventory 方式なのでスキル追加時も守備範囲が痩せない
- `profile:add` が案内する `profile:delete` が実在するようになり、
  **エラーメッセージの嘘が消える**
- 誤登録した credential の**自己回復手段**が生まれる (手動 `rm -rf` の不整合が消える)
- `packages/cli` の 9 本のテスト (既存 7 + 新設 2 = `delete.test.ts` / `saver.test.ts`) が**初めて CI で走る**

### 仮説 (要観測。確定効果として主張しない)

- **レビュー品質が上がる** — 判断 2 の逆転条件で 5 件分を観測して判定する。
  「モデル世代が新しいほうがよい」は妥当だが、我々のプロンプト・観点セットの上で
  実際に指摘が改善するかは計測していない

## 実装方針 (概要)

### Track A: skill モデル一本化 (施策 1・2)

#### 施策 1: モデル一本化 (4 ファイル・9 箇所)

| ファイル | 変更内容 |
|---------|---------|
| `.claude/skills/app-codex-vscode/SKILL.md` | 利用可能モデル表を `gpt-5.5` 単一行へ。xhigh 対応表の「対応モデル」列を全 effort 「全モデル」へ収束。旧モデル注記を削除 |
| `.claude/skills/app-codex-review/SKILL.md` | `-m {model}` 例示を `gpt-5.5` へ |
| `.claude/skills/app-design/SKILL.md` | 重要原則 (:58)・Phase 1-3 (:113)・Phase 2-3 (:283) を `gpt-5.5` へ。reasoning は medium/high のまま |
| `.claude/skills/app-implement/SKILL.md` | Phase A-2 (:178) を `gpt-5.5` へ。reasoning は high のまま |

レビュー観点・出力形式・セッション管理・ファイル保存規約は**一切触らない**。

#### 施策 2: アーキテクチャテスト

`tests/js/architecture/codex-model-consistency.test.ts` を新設。
root `vitest.config.ts` の `include` は `tests/js/**/*.test.ts` を含むため配線不要。

- **走査対象の固定 (drift ガード)**: `.claude/skills/app-*/SKILL.md` の実測 glob 結果と、
  テスト内の明示 inventory (9 スキル全ての SKILL.md) を**集合として突き合わせる**
  - inventory に無い SKILL.md を発見 → fail (新スキルのモデル記述が野放しになるのを防ぐ)
  - inventory にあるのに実在しない → fail (移動・改名・削除で守備範囲が痩せるのを防ぐ)
  - 「検査件数 0 なら fail」はこの一致検査に自明に含まれる
- **検知**: `gpt-5` で始まるモデルトークンを抽出し、`gpt-5.5` 以外を offender とする
- `devnotes/` は走査しない (判断 4)

### Track B: CLI `profile:delete` (施策 3・4・5・6)

#### 施策 3: `profile:delete` コマンド

`packages/cli/src/oclif/commands/profile/delete.ts` を新設。

- `ProfileCommand` 継承、`persistentRequired = false`、`resolveMode = "if-needed"`
  (`profile/use.ts` と同じ作法。削除は**ローカル config への操作**であり、
  サーバ疎通も既存プロファイルの解決も要らない)
- flags: `--yes` (確認プロンプトのスキップ)、`--clear-default`
  (default_profile を指しているプロファイルの削除を許可)
  > 当初 `--force` としていたが、`profile:add` が既に
  > 「`--yes` = skip confirmations / `--force` = bypass environment_tag mismatch」
  > という語彙を持つため `--yes` に統一した (詳細設計 §施策 3 の refinement)。
- 順序 (判断 6 / 判断 8 と厳密に一致させる。**config への書き込みは 1 回だけ**):
  1. 名前検証 (`assertProfileName`) → 存在確認 → 現在の `default_profile` 判定
  2. **残プロファイルを列挙して `nextDefault` を確定する** (判断 8 の表)
  3. 確認プロンプト (`--force` でスキップ)
  4. **credential 破棄** (`store.clearProfile()`)
  5. **`writer.deleteProfile(name, { clearDefault, nextDefault })` を 1 回だけ呼ぶ**
  6. 結果案内 (付け替え先 / 未設定になった旨と `profile:use` 案内)
- exit codes: `ExitCode.ProfileNotFound` (11) / `ExitCode.ProfileConflict` (10)。
  **新しい exit code は足さない** (`exit-codes.ts` の予約穴の趣旨を守る)
- `package.json` の oclif `topics.profile` は既にあるので追加不要

#### 施策 4: `profile/delete` テスト

`packages/cli/tests/profile/delete.test.ts` を新設。
`packages/cli/vitest.config.ts` の `include: ["tests/**/*.test.ts"]` に自動で乗る。

3 backend × 検証軸:

| backend | 実現方法 |
|---------|---------|
| keychain | in-memory Fake `Entry` ctor を `KeychainStore` に注入 (`tests/setup/credential-backend.ts` の `DISABLE_KEYCHAIN` を自スコープで解除) |
| file-encrypted | `<PREFIX>_CREDENTIAL_KEY` 投入 + `MasterKeyRegistry.ensure()` |
| file-plaintext | `setGlobalAllowPlaintextFlag(true)` + `CI` 除去 |

検証軸:

1. credential が消える (`store.read()` が null、file backend では実ファイル/ディレクトリも消滅)
2. config エントリが消える (`writer.get(name)` が undefined)
3. `default_profile` が判断 8 の 5 ケースどおりに処理される
4. **他プロファイルの credential が生存し、同じ master key で復号できる**
   (registry リセット + 全インスタンス再構築後に検証。判断 7 の注記)
5. **冪等性 (3 backend 共通)**: credential 不在プロファイルの削除が成功する (判断 6)
6. **writer の原子性**: 1 回の `deleteProfile(name, { clearDefault, nextDefault })` で
   `profiles` 除去と `default_profile` 付け替えが**同時に**反映される。
   `nextDefault` が不正 (不在 / 削除対象自身) なら **config が一切変更されない**
7. **部分失敗の収束**: config 保存を失敗させたとき、
   (a) config 側に profile が残る、(b) **具体的な再実行コマンド文字列**が stderr に出る、
   (c) 同じコマンドの再実行で収束する — の 3 点

#### 施策 5: `packages/cli` の検証を CI と規約に配線する

packages の検証契約は **`typecheck:packages` + `test:packages` のセット**として扱い、
**ローカル受け入れ条件 / CI / AGENTS.md の 3 箇所すべてに両方を登録**する
(片方だけ登録すると規約と実行が食い違う)。

| 対象 | 変更 |
|------|------|
| root `package.json` | `"typecheck:packages": "pnpm -F \"./packages/*\" typecheck"` を追加 (`build:packages` / `test:packages` と対称) |
| `.github/workflows/ci.yml` | 既存 `frontend` job にステップ 2 本追加 (`pnpm typecheck:packages` / `pnpm test:packages`)。**新 job は作らない** |
| `AGENTS.md` §実装規約 | 検証コマンド行に `pnpm typecheck:packages` と `pnpm test:packages` の**両方**を追記 |

**`ci-multi-lane-workflow` (c2c 裁定待ち) を先取りしない**。
job を増やさず lane も割らない = 多レーン化の設計自由度を一切奪わない。
既存 job へのステップ追加は「新設テストが走らない」という本バッチ固有の穴を塞ぐ最小手段である。

#### 施策 6: `saveConfigToPath` の atomic replacement 化 + 不変条件テスト

| 対象 | 変更 |
|------|------|
| `packages/cli/src/config/saver.ts` | `writeFileSync` → `atomicWriteFile(path, yaml, 0o600)` (`credential/atomic-write.ts` の既存ヘルパ)。1 行の差し替え |
| `packages/cli/tests/config/saver.test.ts` | **新設**。atomic replacement の不変条件を固定する |

- **新しいヘルパを作らない**。同一パッケージ内の既存実装を使う
- 挙動差: 一時ファイル `{path}.{pid}.tmp` を経由し fsync + rename する。
  同一ファイルシステム前提はヘルパの JSDoc で明示済み (user config は常に同一 FS 上)
- 併せて JSDoc の「Atomically」が**実装と一致した状態**になる

**テスト (実装前に赤くなること = テストファースト。AGENTS.md 禁止事項 1 / 思考原則 5)**:

| # | 検証 | 実装前の状態 |
|---|------|------------|
| 1 | **振る舞い**: 一時ファイル書き込みが失敗したとき、**既存 config が旧内容のまま残る**。`{path}.{process.pid}.tmp` を**ディレクトリとして先に作っておく**と tmp 書き込みが必ず失敗する (決定的に再現できる) | 現行は tmp を経由せず直接上書きするので、**旧内容が失われて赤くなる** |
| 2 | **振る舞い**: 正常保存後に `{path}.{pid}.tmp` の残骸が無く、内容が読み戻せる | 現行でも緑 (回帰用) |
| 3 | **構造 (deny-by-default)**: `src/config/saver.ts` のソースが `writeFileSync` を直接 import / 呼び出しせず、`atomicWriteFile` 経由であること | 現行は `writeFileSync` を使うので**赤くなる** |

検証 1 が「不変条件を振る舞いで固定する」本体、検証 3 が「将来の書き戻しを防ぐ」ガードである。
**「電源断」の物理シミュレーションはしない** (テストできないものをテストしたふりをしない)。

> **後始末 (必須)**: 検証 1 で作る `{path}.{process.pid}.tmp` ディレクトリは
> `finally` で確実に除去する。残すと**同一 pid の後続テストの `atomicWriteFile` を
> 巻き添えで失敗させる** (vitest は同一プロセスで複数ファイルを走らせうる)。

## 受け入れ条件

### Track A (skill モデル一本化)

- [ ] `grep -rn "gpt-5" .claude/skills/` の結果が **`gpt-5.5` のみ**
- [ ] `devnotes/` 配下の `gpt-5.4` / `gpt-5.3-codex` 記述が **148 ファイルのまま無変更**
      (`git status` に devnotes の変更が出ない)
- [ ] `app-design` / `app-implement` のレビュー観点 (DESIGN.md 準拠 / Atomic Design 準拠) が無変更
- [ ] reasoning effort (medium / high / high) が無変更
- [ ] `pnpm test` green (新設 `codex-model-consistency.test.ts` を含む)
- [ ] `codex-model-consistency.test.ts` が **意図的に壊すと赤くなる**ことを確認
      (テストファーストの fail 確認。AGENTS.md 思考原則 5)

### Track B (CLI profile:delete)

- [ ] `pnpm test:packages` green (新設 `delete.test.ts` を含む)
- [ ] `pnpm typecheck` / `pnpm typecheck:packages` green
- [ ] `pnpm lint` green
- [ ] `exit-codes.ts` に**新しいコードが増えていない**
- [ ] `file-store.ts` / `master-key-registry.ts` に**変更が無い** (再利用のみ)。
      `store.ts` の変更は `purgeProfile()` の追加のみ
      (詳細設計レビュー Round 1 の refinement。テスト専用 API `fileStoreOrNull()` を
      本番から呼ばないための正式 API 化)
- [ ] `writer.ts` の変更は `deleteProfile` の `opts` 拡張とその検証のみ。
      既存呼び出し元 (`profile/add.ts` 2 箇所) の挙動が不変
- [ ] `saver.ts` の変更は `atomicWriteFile` への差し替え 1 行のみ (新ヘルパ追加なし)
- [ ] `tests/config/saver.test.ts` の検証 1・3 が **`saver.ts` 変更前に赤い**ことを確認済み
- [ ] `nextDefault` 未指定時にプロパティを省略している (`exactOptionalPropertyTypes` 適合)
- [ ] コマンド層が `FileProfileWriter` / `RootConfigInput` / `ProfileEntry` を直接参照していない
      (依存は `ProfileWriter` インターフェースのみ)
- [ ] CI の `frontend` job に 2 ステップが入り、job 数は 2 のまま
- [ ] `pnpm typecheck:packages` / `pnpm test:packages` が
      受け入れ条件・CI・AGENTS.md の 3 箇所すべてに登録されている

## 実装順 (ロールバック単位を分ける)

関心とロールバック単位ごとに **4 コミットに分ける**。
特に施策 6 は「全 config 保存経路」への変更であり `profile:delete` とは独立に
戻せる必要がある (Round 4 レビュー指摘):

| # | 内容 | 手順 |
|---|------|------|
| 1 | **Track A**: skill モデル一本化 | 施策 2 (テスト) を書いて fail 確認 → 施策 1 で green |
| 2 | **Track B-0**: packages 検証の配線 | 施策 5 (`typecheck:packages` + `test:packages` を package.json / CI / AGENTS.md へ)。これを先に入れないと以降のテストが CI で走らない |
| 3 | **Track B-1**: config の atomic replacement | 施策 6 のテスト 3 本を書いて **1 と 3 が赤い**ことを確認 → `saver.ts` 1 行差し替えで green |
| 4 | **Track B-2**: `profile:delete` | 施策 4 (テスト) で fail 確認 → 施策 3 (コマンド + `writer.deleteProfile` の `nextDefault` 拡張) で green |

同一 worktree / 同一 TODO で進めるが、各コミットは独立に revert できる。

## 制約・前提

- **この devcontainer に PostgreSQL は無い** (`DB_HOST=db` は未起動の docker-compose サービス)。
  本バッチは DB を使わないため影響しないが、**検証手段として `composer test` に依存しない**。
  検証は `pnpm test` (architecture テスト) と `pnpm test:packages` (CLI テスト) で完結する
- `pnpm test` / `pnpm test:packages` は `scripts/with-global-test-lock.sh` 経由
  (aicue:T099 でマージ済み)。並列実行時のロック競合は基盤側が処理する
- `skills-lock.json` は外部 skill (Stripe 公式) のみを管理し `app-*` を含まない = **更新不要**
- インストール済み codex バイナリ (v0.146.0-alpha.9.2) は `gpt-5.5` を認識する
  (JST 2026-08-05 実測)。`scripts/codex` はモデル名をハードコードしない
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
- **CI の多レーン化** (`ci-multi-lane-workflow`)。施策 5 は既存 job へのステップ追加に留め、
  shard / browser / package / audit job の新設は c2c 裁定に委ねる
- **c2c 台帳への `status_reported` 書き戻し**。実装・push 完了後の別作業
- **他の追従タスク** (`atomic-design-gates` / `browser-test-lane` 等 11 件)
