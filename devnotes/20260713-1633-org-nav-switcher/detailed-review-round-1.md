全体として方向性は良く、**運用導線の回復**という目的にも整合しています。ただし、セキュリティ不変条件と実装確実性の観点で修正必須点があります。

**施策別判定**

- **S1: 共有 prop 拡張** → **REQUEST_CHANGES**
  - [Critical] `currentOrganization.slug` を共有するだけでは「cross-org slug 非露出」を担保しきれません。`currentOrganization` が不整合（例: 退会済み組織ID残存）だった場合のフォールバックが設計に未記載です。  
    修正案: `currentOrganizationProp()` で `organizationsProp()` と同一スコープ（ユーザー所属）で再検証し、非所属なら `null` にフォールバック。加えて Feature テストで「current_organization_id が所属外」のケースを固定。
  - [Warning] `can()` 呼び出し2回追加の性能影響を「軽微」と断定しているが、N+1 的な副作用確認が不足。  
    修正案: `organizationRole()` 内部参照回数を確認し、必要なら1リクエスト内で role 解決をローカル変数化して重複評価を回避。
  - [Suggestion] array-shape を middleware 内 private メソッドで肥大化させるより、専用 Value Object 相当（内部専用）でキー定義を集約すると将来の差分漏れを減らせます。

- **S2: TS 型拡張** → **APPROVE**
  - [Suggestion] `role` を `string | null` のままにせず、既存 enum value のユニオン型化を推奨（`"organization_owner" | ... | null`）。型安全性と表示分岐の網羅性が上がります。

- **S3: OrganizationSwitcher 新設** → **REQUEST_CHANGES**
  - [Critical] `router.post(\`/organizations/${org.id}/switch\`)` の直書きはルート変更耐性が弱く、既存 Ziggy/route helper 方針とズレる可能性。  
    修正案: 既存プロジェクト標準に合わせて `route('organizations.switch', { organization: org.id })` を利用（または共通ルートヘルパ経由）。
  - [Warning] 現在組織行を押下可能(no-op)にする設計は禁止事項8には抵触しないが、UX的に誤操作を誘発。  
    修正案: disabled は使わず、現在組織行は `aria-current="true"` と視覚ラベル（例「現在の組織」）を付け、クリック時は送信せず即 close。
  - [Warning] click-outside を `pointerdown` のみで処理するとキーボード操作時のフォーカスアウト閉じが弱い。  
    修正案: `focusout` も併用、または disclosure のフォーカストラップ方針を明文化してテスト追加。
  - [Suggestion] `role="menu"` を避ける判断は妥当。代わりに `aria-labelledby` でトリガー関連付けを入れると a11y が安定。

- **S4: AppLayout 常設** → **APPROVE**
  - [Warning] ヘッダー折返しリスクは言及のみで検証観点不足。  
    修正案: JSテストに「トリガーが表示される」だけでなく、最低限クラス存在（`shrink-0`）を固定して回帰を防止。
  - [Suggestion] コメント更新方針は良い。陳腐化コメント削除を優先し、将来計画は docs に寄せると保守しやすいです。

- **S5: テスト計画** → **REQUEST_CHANGES**
  - [Critical] `OrganizationSwitchController::store()` の提示コードには明示的認可チェックがありません（binder 依存のみ）。設計側で「認可契約」を固定しきれていない。  
    修正案: `Gate::authorize('view', $organization)` もしくは Policy 呼び出しを controller に明示し、Feature テストで「他組織IDで404/403」を追加。
  - [Warning] S5-a は良いが、`currentOrganization=null` ケース（未所属/不整合）の shared prop 期待値テストが不足。  
    修正案: `currentOrganization` が null のとき `canManage*` が存在しないこと、S3のフォールバック表示を含めて結合的に検証。
  - [Suggestion] JS側で `/organizations/{slug}/api-keys` の href だけでなく、`canManageApiKeys=true` 時の表示復活テストも1ケース追加すると回帰耐性が上がります。

**横断レビュー**

- **PHPStan Lv10**
  - [Warning] PHP 側は shape 拡張で概ね問題なし。TS 側の role 文字列自由度が高く、型の厳密性が一段弱い（上記S2対応推奨）。

- **DTO / JsonResource / Inertia 使い分け**
  - [Suggestion] 本件は Inertia shared prop で妥当。新規 API を作らない判断は適切。

- **セキュリティ**
  - [Critical] cross-org 不可の担保が「設計説明」中心で、異常データ時の防御（current org 不整合）をコード/テストで固定できていない。
  - [Warning] 権限評価は current org 指定で妥当。ただし controller 側の明示認可を補強すべき。

- **DESIGN.md / Atomic Design**
  - [Suggestion] 記載上は準拠。最終的に `atomic-import-graph` と ds-purity テストで回帰固定を必須化してください（計画にコマンドはあるが検証観点の明文化を推奨）。

**全体判定**

- **CHANGES_REQUESTED**

主な差し戻し理由は3点です。  
1) cross-org 不変条件の“異常系”固定不足、2) switch 実行の認可明示不足、3) スイッチャーUIの操作契約（現在組織行・a11y）未確定。  
これらを反映すれば、設計は十分に承認可能レベルです。