# impl-review Round 2 (T028)

Round 1 の指摘への対応を報告します。判定の再評価をお願いします。

## [Critical] `changeMemberRole` のグローバル送信ロック — 反論 + 一部対応

現行のグローバル直列ロック（`changingRoleId !== null` で早期 return）を**維持**します。根拠:

1. **既存の出荷済みパターンとの一致**: 本実装は詳細設計が明示参照する `Admin/Users.svelte` の
   `changeRole`（`changingRole` boolean 単一ロック）と同一セマンティクス。同ページはレビュー済み・出荷済みで
   「別行の連続変更は直列化」される同じ挙動を持つ。ここだけ行単位並行制御を入れるのは、レビュー済み
   パターンからの不整合な逸脱になる。
2. **Inertia の visit キャンセル意味論から見て直列ロックの方が安全**: Inertia は同時 visit を基本
   キャンセルするため、行単位で並行 `router.post` を許すと**先行（実行中）の保存が後続にキャンセルされて
   失われる**。グローバルロックは実行中の 1 件を確定させ後続を弾くので、少なくとも 1 件を決定論的に完了させる
   （「両方失う」より安全）。
3. **表示ずれは自己修復する**: store は `back()->with('success', ...)` を返し、成功時に Inertia が props を
   再取得して members を再描画するため、弾かれた行の native select も次の props refresh でサーバ真値に戻る
   （恒久的な desync は残らない）。error 時は `router.reload({ only: ['members','assignableUsers'] })` で明示再同期。
4. **禁止事項8 / 設計判断の尊重**: 詳細設計は「ロール select に disabled を付けない」を design-review Round 1
   Critical への対応として明文化済み。in-flight での disabled 追加はこの確定済み設計判断に反する。

その上で、挙動を「無言・未検証」から「文書化・検証済み」へ格上げするため、in-flight ガードの回帰テストを追加しました。

## [Warning] 二重送信ガードの直接テスト不在 — 対応済み

`tests/js/pages/ProjectsShow.test.ts` に以下を追加:

```ts
it("ロール変更処理中は次のロール変更を受け付けない (二重送信ガード)", async () => {
    // router.post は onFinish を発火させない mock なので changingRoleId が張られたまま。
    // 連続 change しても 1 回しか送信されないこと (in-flight ロックの退行検知) を固定する。
    const postSpy = vi.spyOn(router, "post").mockImplementation(() => {});
    render(Show, { props: baseProps });

    await fireEvent.change(screen.getByTestId("project-member-role-2"), {
        target: { value: "project_member" },
    });
    await fireEvent.change(screen.getByTestId("project-member-role-3"), {
        target: { value: "project_admin" },
    });

    expect(postSpy).toHaveBeenCalledTimes(1);
    expect(postSpy.mock.calls[0][1]).toEqual({ user_id: 2, role: "project_member" });
});
```

## [Warning] `array_column`/serializer に対する brittleness — 見送り

`memberRows` の shape は同一 Controller の PHPDoc で固定、S3 Feature テストが shape/除外契約を回帰固定、
Collection/array 差異はテスト helper が吸収済み。将来 serializer 変更時はテストが落ちて検知できるため、
予防的な複雑化は禁止事項6（不必要な複雑化）に反すると判断し見送り。

## テスト結果（再実行）
- `pnpm exec vitest run tests/js/pages/ProjectsShow.test.ts`: 23 passed（+1）。
- 既存の全ゲートは Round 1 時点で green（composer test 1561 passed / phpstan OK / pint passed / lint・typecheck clean / build OK）。追加はテスト 1 件のみでプロダクションコード不変。

この対応で全体判定の再評価をお願いします。
