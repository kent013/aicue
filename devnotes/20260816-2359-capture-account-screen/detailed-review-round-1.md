**全体判定: CHANGES_REQUESTED**

設計の方向性は妥当です。`/app/account` を project 非依存の Inertia 画面として追加し、共有 props だけでログイン ID を省略なく表示する判断は North Star と既存構造に合っています。ただし、テストと Svelte 実装まわりに実装時に赤くなる可能性が高い箇所があります。

## 施策別判定

### 施策 1: `capture.account` route の追加
**APPROVE**

[Suggestion] `CaptureAccountController` の import 追加位置は既存 import 群の並びに合わせれば十分です。route 自体は GET / Inertia / project parameter なしなので、IDOR・authorization gate の母集団外という整理で問題ありません。

### 施策 2: 表示専用 controller の追加
**APPROVE**

[Suggestion] `resolveMemberCurrentOrganization()` の戻り値を捨てる設計は成立しますが、静的解析やレビュー上の意図をより明確にするなら `$this->resolveMemberCurrentOrganization($request);` の直前コメントだけでなく、変数名 `_organization` のように受けてもよいです。ただし必須ではありません。

### 施策 3: アカウント確認画面の追加
**REQUEST_CHANGES**

[Warning] `Button loading={loggingOut}` が内部で `disabled={disabled || loading}` になる前提なら、Vitest の「送信中は再送しない」テストは DOM の disabled 挙動に依存して通る可能性があります。これは `logout()` 内の `if (loggingOut) return;` を検証できません。  
修正案: テストでは button click 2 回ではなく、`routerPostMock` の `onStart` 後にコンポーネント側の handler が再実行される経路を検証するか、テスト名を「loading により再送操作できない」に寄せる。実装契約としては `if (loggingOut) return;` と `loading` の両方があるので問題ありませんが、テストがどちらを固定しているかを明確にしてください。

[Warning] `TextLink href="/app"` / `TextLink href="/app/account"` の直書きは既存フロントの慣習次第ですが、Laravel route 名からの Ziggy 等を使う規約がある場合は不整合になります。  
修正案: 既存 `Capture/Show.svelte` や `AppLayout.svelte` が固定 URL を使っているなら現設計で可。route helper を使う規約なら `route("capture.home")` / `route("capture.account")` に寄せる、と設計に明記してください。

[Suggestion] `currentOrganization` が null の場合に組織行を出さない方針は妥当ですが、この route はサーバ側で非 null 保証を置く設計なので、UI テストは「防御的表示」ではなく「偽の既定値を出さない」意図の補助テストとして扱うのがよいです。

### 施策 4: 撮影一覧からの入口
**APPROVE**

[Suggestion] `PageHeaderSection` への置換で既存 `PageHeader` のマークアップ差分が出る可能性があります。既存テストが `capture-heading` だけを見ているなら十分ですが、スナップショットや role/name を見るテストがある場合は確認対象に含めてください。

### 施策 5: ログアウト呼び出し箇所の目録登録 + docs 更新
**APPROVE**

[Suggestion] docs の「3 箇所」更新は `rg` 前提の確認まで設計に書けており十分です。`/logout` 文字列を本文に増やすことで architecture test の走査対象が docs を含まないことも前提として明示済みならなおよいです。

### 施策 6: bug-hunt 目録への route 注釈追加と再生成
**APPROVE**

[Warning] ユーザー向け作業ではなく実装設計としては、生成物に本 route 以外の差分が出た場合の扱いが正しいです。ただし「差分を報告する」だけでは、最終的に PR を green にできない可能性があります。  
修正案: 本タスクでは本 route 由来の生成差分のみを含める。既存ドリフトがある場合は実装を止めて別タスク化、または設計レビューに戻す、という判定基準を明記してください。

### 施策 7: テスト
**REQUEST_CHANGES**

[Warning] Feature テストの「撮影者 (project_member) でも 200」は、この画面が project 非依存である以上、project role ではなく organization membership を検証しているだけです。`attachProjectMember()` は本 route の到達条件に効かず、テスト名と実体が少しずれています。  
修正案: テスト名を「organization member なら 200」へ変えるか、撮影者 role が org member でもあることを確認する目的としてコメントに「project role 自体はこの route の認可条件ではない」と明記してください。

[Warning] `expect($owner->id)->not->toBe($stranger->id);` は「非所属なら 404」の前提として弱いです。確認したいのは user id の相違ではなく、`$stranger` が `$organization` に所属していないことです。  
修正案: `$organization->users()->whereKey($stranger->getKey())->exists()` が false であることを前提確認に使う、または不要なら削除してください。

[Warning] JS テストの Inertia mock が `page` を plain object として差し替えています。既存テストが同じ形ならよいですが、`@inertiajs/svelte` の `page` が rune/store 互換の shape を要求する版では壊れやすいです。  
修正案: 既存の Svelte page テストで使っている mock helper があるならそれに合わせる。なければこの設計内で「既存テストの mock 方式に合わせる」と明記してください。

[Suggestion] `auth.user.id を描画に使わない` テストは `container.textContent` 全体に `"42"` が含まれないことを見るため、将来 appName や組織名などに偶然 `42` が入ると偽陽性になります。  
修正案: id を `"INTERNAL-USER-ID-DO-NOT-RENDER"` のような文字列にできない型なら、`4242424242` など衝突しにくい値を使うか、表示対象要素単位で検証してください。

## セキュリティ・設計整合性

認可・tenant 境界については大きな問題はありません。project を持たない account 画面を project 配下に置かない判断は正しく、余計な nested route IDOR 義務を作っていません。`response()->json()` も使わず、Inertia page と共有 props で完結している点も妥当です。

一方で、テストの一部が「何を固定したいか」と実際の検証対象が少しずれています。ここを直せば、実装可能な詳細設計として承認できる水準です。