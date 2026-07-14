## 概念設計レビュー Round 3

Round 2 の Warning への対応を反映しました。判定を更新してください。

### Round 2 指摘への対応

| 指摘 | 分類 | 対応 |
|------|------|------|
| sort に一意 tie-breaker が無くページ間で重複/欠落 | Warning | **対応**。全 sort に id 安定 tie-breaker を追加: updated_desc=`updated_at desc, id desc` / updated_asc=`updated_at asc, id asc` / title_asc=`title asc, id asc` / title_desc=`title desc, id desc`。既定は `created_at desc, id desc`。同値データでページ境界テストを追加 |
| creator.name 表示の認可根拠が弱い / null になり得る | Warning | **対応**。認可前提を明記(project を view できる利用者に作成者名を表示。一覧は既に `Gate::authorize('view', $project)` 通過済みのため追加ゲート無し)。creator 不在時は null 契約 + null テストを追加 |
| creator の shape 契約が PC/PWA/TS で割れて曖昧 | Warning | **対応**。画面別 DTO を意図として明記。PC=`creator: {id: number, name: string} \| null`、PWA=`creator_name: string \| null`。欠損時 UI は「不明」表示に固定 |
| 「自分の担当」→「自分が作成した」に修正 | Suggestion | **対応**。期待効果の文言を「自分が作成したシナリオ」に修正 |

### 修正後の該当箇所(抜粋)

**並べ替え (PC のみ)** — 全 sort に id tie-breaker:
- updated_desc → `updated_at desc, id desc`
- updated_asc → `updated_at asc, id asc`
- title_asc → `title asc, id asc`
- title_desc → `title desc, id desc`
- 既定(sort 無し)→ `created_at desc, id desc`。allowlist 外はフォールバック。

**メタ表示: 作成者・更新日**:
- 認可前提: manual の作成者名は project を view できる利用者に表示(一覧は `Gate::authorize('view', $project)` 通過済み)。email のような最小化ゲートは設けず member.name と同じ閲覧可能情報として扱う。
- null 契約: creator 解決不可(退会/削除)は null。UI は「不明」表示。
- 画面別 shape: PC=`creator: {id: number, name: string} | null`、PWA=`creator_name: string | null`。User.name は CipherSweet PII だが表示のみ(検索しない)、read で自動復号。

**テスト方針** に追加:
- sort: 同値(同一 updated_at / 同一 title)データで id tie-breaker のページ境界重複/欠落無しを検証。
- 作成者 props: creator 解決不可時に null で返ることを検証。

上記で概念設計を確定してよいか判定してください。
