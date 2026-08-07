app-codex-review / app-design スキルはこの環境の利用可能スキル一覧に無いため、通常の設計レビューとして確認しました。コマンド実行・書き込みは行っていません。

**全体判定: CHANGES_REQUESTED**

## 施策 1: REQUEST_CHANGES

[Warning] `scopeActivePendingForEmail()` の email 大小文字完全一致契約は fail-secure ではありますが、ユーザーのログイン email 正規化契約との接続が設計内で弱いです。  
修正案: `User` の email 保存・検証時の正規化有無を明記し、「既存ユーザーの verified email と招待 email が大小差で不一致になる」運用を許容するのか、送信時に正規化するのかを施策 7 または docs に明記してください。

## 施策 2: APPROVE

[Suggestion] `roleLabel` のみだと将来 UI 側で value が必要になった時に DTO 追加が必要ですが、現時点の開示最小化としては妥当です。

## 施策 3: REQUEST_CHANGES

[Warning] `InvitationAcceptRaceTest` の `OrganizationInvitation::retrieved` 回数依存は壊れやすいです。特に `acceptPendingInvitation()` は preliminary / locked re-resolve / join 内取得で回数が経路ごとに変わります。  
修正案: 経路ごとに「何回目の `OrganizationInvitation` retrieved を改変するか」を明示し、リスナ解除を `try/finally` で保証してください。可能ならテスト専用 subclass/mock ではなく、DB 状態をロック直前に変更する明示フックを使う形にしてください。

[Warning] `acceptPendingInvitation()` が nested transaction 内で `joinOrganization()` を呼ぶ設計は成立しますが、`joinOrganization()` が既存 2 経路でも使われるため、戻り値 false の消費漏れを静的に守る gate がありません。  
修正案: `joinOrganization()` 呼び出し箇所を抽出する Architecture test を追加し、「戻り値を条件分岐で消費している」ことを検査対象にしてください。

## 施策 4: REQUEST_CHANGES

[Critical] `NestedRouteDefenseInventory` への登録案が不整合です。`invitations.accept-in-app` は親子 nested route ではなく、提案の `['invitation' => $manual]` は対象モデルも違います。これだと意図した存在秘匿を検証できないか、誤った fixture で gate が形骸化します。  
修正案: nested inventory には入れず、`RouteBindingTypes::MANUALLY_RESOLVED` と feature test の 404 parity で守る。もし既存 inventory に「個人スコープ単一 param」枠があるなら、`OrganizationInvitation` factory で「自分宛 / 他人宛 / 不在」を作る専用登録にしてください。

[Warning] `abort_if($organization === null, 404)` 後の PHPStan narrowing は設計内で懸念済みですが、実装コード例はそのままです。  
修正案: 最初から `if ($organization === null) { abort(404); }` にしてください。

## 施策 5: REQUEST_CHANGES

[Warning] `PendingInvitationList.svelte` は `loading={acceptingId === invitation.id}` を `Button` に渡していますが、既存 `Button` atom が loading 時に `disabled` を出す実装だと、禁止事項 8 とテスト計画に反します。  
修正案: `Button` の loading 契約を確認し、disabled を出すなら `aria-busy` や表示テキストだけで in-flight を表現する別 prop / wrapper にしてください。

[Warning] `class="..."` のままでは DESIGN.md 準拠をレビューできません。  
修正案: 実装設計に既存 token / atom / spacing class の使用方針を具体化し、hex・独自 radius・カード入れ子を増やさないことを明記してください。

## 施策 6: APPROVE

[Suggestion] 全 Inertia 共有 prop の追加なので、`only: ['notifications']` 等の partial reload 時に `invitations.pendingCount` が更新されないケースを UX 上許容する旨を docs に一言入れるとよいです。

## 施策 7: REQUEST_CHANGES

[Critical] `project_role` drop は破壊的 schema 変更ですが、ローリングデプロイ中に旧コードが `project_role` を read/write すると即時に 500 になります。設計は「後方互換を残さない」方針としては理解できますが、デプロイ手順の安全境界が未定義です。  
修正案: maintenance window 前提、または「コード反映と migration の順序」「旧プロセスが残らないことの確認」「rollback 時に値は復元不能」を runbook として明記してください。

[Warning] DB 側テスト計画の `php artisan migrate` は、実行先が dev DB だと列 drop になり禁止事項 3 に抵触します。  
修正案: worktree の test DB / CI DB に限定して実行する、と明記してください。dev DB への適用はユーザー承認または通常デプロイ手順に限定すべきです。

## 施策 8: REQUEST_CHANGES

[Warning] regex + Reflection の gate は妥当ですが、保証範囲が「app/ 内のメソッド本文にリテラルとして現れる query 起点」だけです。設計本文には限界が書かれていますが、完了条件では mutation log のみで、抽出結果の実測 inventory 固定が曖昧です。  
修正案: 初回実装時の抽出結果一覧、floor、RecipientScopedPendingSet cap の値を devnotes に固定し、レビュー対象に含めてください。

## 施策 9: REQUEST_CHANGES

[Warning] 完了条件の検証コマンドが AGENTS.md の正本から不足しています。`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が抜けています。  
修正案: 完了条件を AGENTS.md の `VERIFICATION_COMMANDS` と一致させてください。

---

主な修正必須点は、施策 4 の誤った nested inventory 登録、施策 7 の破壊的 migration の運用設計不足、施策 5 の disabled 回帰リスク、検証コマンド不足です。ここを直せば、中心方針である「受信者視点の単一 scope」「一律 404」「未ログイン・未 verified・email 空で DB を引かない」はかなり堅くなります。