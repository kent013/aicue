# Round 2: Round 1 指摘への対応差分

Round 1 の判定 CHANGES_REQUESTED に対し、Warning 2 件・Suggestion 1 件をすべて **対応** した。
反論・見送りは 0 件である。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 2 / Suggestion 1)

## [Warning] 編集者が `capture.takes.store` / `playback` / `thumbnail` を実行できることが未固定

- 判断: **対応する**
- 根拠: 指摘は正しい。PC 面の設計の肝は「新しい API 面を作らず `capture.takes.*` を再利用する」ことで、
  その再利用が認可上成立することは**テストが唯一の証拠**である。とくに
  - `store` は `upload-url` の続きであり、これが通らなければ PC アップロード (施策 4) は成立しない
  - `playback` / `thumbnail` は画面の `<video src>` / `<img src>` の出所そのものである
    (`thumbnail` は逸脱 1 で今回 PC 面が使い始めたため、Round 1 の指摘どおり固定対象に入る)
- 対応内容: `tests/Feature/Manual/PcTakeOperationTest.php` に 2 本追加した。
  - 「編集者は presigned 発行の続き (POST takes = 登録) まで通せる」
    予約行 + 署名チケット + HeadObject 一致の container mock で 201 を固定し、
    併せて**応答に `playback_url` / `download_ack_token` が載らない**ことも固定した
    (PC 面が署名 URL を受け取らないという shape 契約の裏取り)。
  - 「編集者は playback / thumbnail の 302 を受け取れる」
    302 と `Cache-Control: no-store`、`thumbnail` の Location が `thumbnail_path` を指すことを固定した。

## [Warning] `analyzing` 中 adopt の 409 が未テスト

- 判断: **対応する**
- 根拠: 設計は `rendering / analyzing` の両方を 409 と明示しており、
  `TakePreviewPanel` の事前告知も 2 状態を出し分けている。片方だけの固定では
  告知と実挙動の対応が半分しか担保されない。
- 対応内容: 「analyzing 中の adopt も 409 (rendering と同じ扱い)」を追加した。

## [Suggestion] DL 済みテイクの「削除できない」理由が押下前の説明として弱い

- 判断: **対応する**
- 根拠: `SelectableTakeData` の `downloaded` は「**理由を押下前に説明するため**に出す」と
  自分でコメントしている。バッジ 1 個ではその意図を満たしていないという指摘は妥当で、
  かつ禁止事項 8 (disabled にしない) とも両立する (押下は従来どおり受け、サーバ文言を出す)。
- 対応内容: `TakePickerList.svelte` の DL 済みテイクに
  「ダウンロード済みのため削除できません。」の補足行 (`take-downloaded-note-{id}`) を追加し、
  **削除ボタンが disabled でないこと**と併せて `ManualsTakes.test.ts` で固定した。
  ツールチップではなく本文にしたのは、hover を持たない端末でも読めるようにするため。

## 追加・変更した差分 (Round 1 からの増分のみ)

```diff
diff --git a/resources/js/components/features/manual/TakePickerList.svelte b/resources/js/components/features/manual/TakePickerList.svelte
new file mode 100644
index 0000000..f2c1845
--- /dev/null
+++ b/resources/js/components/features/manual/TakePickerList.svelte
@@ -0,0 +1,175 @@
+<script lang="ts">
+    import { Trash2 } from "@lucide/svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import TakeThumbnail from "@/components/features/manual/TakeThumbnail.svelte";
+    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
+    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
+    import { formatBytes } from "@/lib/format-bytes";
+    import { TAKE_STATUS_LABELS, type SelectableTake } from "@/types/manual";
+
+    /**
+     * テイク一覧 (PC テイク選択画面の左ペイン)。選択とテイク削除を担う。
+     * 採用の確定はプレビュー側 (TakePreviewPanel) が行う。
+     * 削除は撮影 PWA と同じ capture.takes.destroy を叩き、DL 済み (422) の理由は
+     * サーバ供給の文言をそのまま表示する (UI 側で理由を再実装しない)。
+     */
+    interface Props {
+        takes: SelectableTake[];
+        /** 現在の採用テイク id (青枠の対象) */
+        adoptedTakeId: number | null;
+        selectedTakeId: number | null;
+        onSelect: (takeId: number) => void;
+        projectId: number;
+        manualId: number;
+        cutId: number;
+        /** 削除成功後の再取得 */
+        onChanged: () => void;
+    }
+
+    let {
+        takes,
+        adoptedTakeId,
+        selectedTakeId,
+        onSelect,
+        projectId,
+        manualId,
+        cutId,
+        onChanged,
+    }: Props = $props();
+
+    let error = $state<string | null>(null);
+    let busyTakeId = $state<number | null>(null);
+
+    // 削除確認: id と表示ラベルをスナップショット保持する (再取得で参照内容がずれないため)
+    let deleteTargetId = $state<number | null>(null);
+    let deleteLabel = $state("");
+    let deleteDialogOpen = $state(false);
+
+    function thumbnailUrl(take: SelectableTake): string | null {
+        return take.has_thumbnail
+            ? buildTakeUrl({ projectId, manualId, cutId }, take.id, "/thumbnail")
+            : null;
+    }
+
+    function requestDelete(take: SelectableTake, index: number): void {
+        error = null;
+        deleteTargetId = take.id;
+        deleteLabel = `テイク ${index + 1}`;
+        deleteDialogOpen = true;
+    }
+
+    /** 「削除する」確定時のみ DELETE を送る (押下は常に受け、422 はサーバ文言を表示する) */
+    async function confirmDelete(): Promise<void> {
+        const id = deleteTargetId;
+        if (id === null) return;
+        busyTakeId = id;
+        try {
+            const response = await captureJson(
+                buildTakeUrl({ projectId, manualId, cutId }, id),
+                "DELETE",
+            );
+            if (!response.ok) {
+                error = await extractErrorMessage(response);
+                return;
+            }
+            onChanged();
+        } catch {
+            error = "通信に失敗しました。ネットワークを確認してください。";
+        } finally {
+            busyTakeId = null;
+            deleteDialogOpen = false;
+            deleteTargetId = null;
+            deleteLabel = "";
+        }
+    }
+</script>
+
+<div class="flex flex-col gap-2" data-testid="take-picker-list">
+    {#if takes.length === 0}
+        <p class="text-caption text-text-secondary" data-testid="take-picker-empty">
+            このカットにはまだ動画がありません。スマホで撮影するか、下のフォームから動画ファイルを追加してください。
+        </p>
+    {/if}
+    <ul class="flex flex-col gap-2">
+        {#each takes as take, index (take.id)}
+            <li
+                class="flex items-start gap-2 rounded-md border p-2 {adoptedTakeId === take.id
+                    ? 'border-primary'
+                    : 'border-border'} {selectedTakeId === take.id ? 'bg-primary-soft' : 'bg-surface'}"
+                data-testid={`take-tile-${take.id}`}
+            >
+                <button
+                    type="button"
+                    class="flex min-w-0 flex-1 items-start gap-2 text-left"
+                    aria-current={selectedTakeId === take.id ? "true" : undefined}
+                    onclick={() => onSelect(take.id)}
+                    data-testid={`take-select-${take.id}`}
+                >
+                    <TakeThumbnail
+                        {index}
+                        status={take.status}
+                        durationMs={take.duration_ms}
+                        thumbnailUrl={thumbnailUrl(take)}
+                        testId={`take-thumbnail-${take.id}`}
+                    />
+                    <span class="flex min-w-0 flex-col gap-1">
+                        <span class="flex flex-wrap items-center gap-1 text-body text-text">
+                            テイク {index + 1}
+                            {#if adoptedTakeId === take.id}
+                                <Badge tone="primary" testId={`take-adopted-${take.id}`}>採用中</Badge>
+                            {/if}
+                            {#if take.downloaded}
+                                <Badge tone="neutral">DL 済み</Badge>
+                            {/if}
+                        </span>
+                        <span class="text-caption text-text-secondary">
+                            {TAKE_STATUS_LABELS[take.status]}・{formatBytes(take.size_bytes)}
+                            {#if take.duration_ms !== null}
+                                ・{Math.round(take.duration_ms / 1000)} 秒
+                            {/if}
+                        </span>
+                        {#if take.downloaded}
+                            <!-- 削除は 422 になる。押下は止めないが理由は押す前に見せる -->
+                            <span
+                                class="text-caption text-text-secondary"
+                                data-testid={`take-downloaded-note-${take.id}`}
+                            >
+                                ダウンロード済みのため削除できません。
+                            </span>
+                        {/if}
+                        {#if take.comment}
+                            <span class="text-caption text-text-secondary">{take.comment}</span>
+                        {/if}
+                    </span>
+                </button>
+                <Button
+                    variant="danger-ghost"
+                    size="sm"
+                    iconOnly
+                    ariaLabel={`テイク ${index + 1} を削除`}
+                    loading={busyTakeId === take.id}
+                    onclick={() => requestDelete(take, index)}
+                    testId={`take-delete-${take.id}`}
+                >
+                    <Trash2 class="size-4" aria-hidden="true" />
+                </Button>
+            </li>
+        {/each}
+    </ul>
+    {#if error}
+        <p class="text-caption text-danger" role="alert" data-testid="take-picker-error">{error}</p>
+    {/if}
+</div>
+
+<ConfirmDialog
+    bind:open={deleteDialogOpen}
+    title="テイクの削除"
+    message={`${deleteLabel}を削除しますか？ この操作は取り消せません。動画は完全に削除されます。`}
+    confirmLabel="削除する"
+    confirmVariant="danger"
+    processing={deleteTargetId !== null && busyTakeId === deleteTargetId}
+    onConfirm={confirmDelete}
+    testId="take-delete-dialog"
+/>
diff --git a/tests/Feature/Manual/PcTakeOperationTest.php b/tests/Feature/Manual/PcTakeOperationTest.php
new file mode 100644
index 0000000..3b192d2
--- /dev/null
+++ b/tests/Feature/Manual/PcTakeOperationTest.php
@@ -0,0 +1,226 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Capture\ObjectMetadataData;
+use App\DataTransferObjects\Capture\PresignedUploadData;
+use App\DataTransferObjects\Capture\UploadTicketClaims;
+use App\Enums\Manual\TakeStatus;
+use App\Enums\ProjectRole;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\TakeUploadReservation;
+use App\Models\VideoManual;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Capture\UploadTicketCodec;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Str;
+
+/*
+ * PC テイク選択画面からのテイク操作。
+ *
+ * PC 面は**新しい API 面を持たない** — 採用・削除・アップロード・再生はすべて
+ * 撮影 PWA と共用の capture.takes.* を叩く。本テストが固定するのは 2 つ:
+ *
+ *   1. 編集者 (org owner / project_admin) が capture.takes.* を実行できること
+ *      (PC 導線でも認可が通る)
+ *   2. **撮影者 (project_member) も capture.takes.adopt を実行できること**
+ *      = 画面 (projects.manuals.cuts.takes.index) は 403 だが API は開いている、という
+ *      意図的な非対称。**この test が消えたら非対称が事故で壊れたと分かる**
+ *      (撮影者の採用は doc/10 §10.5 の確定仕様)。
+ */
+
+function pcTakePath(Project $project, VideoManual $manual, Cut $cut, ?Take $take = null, string $suffix = ''): string
+{
+    $base = "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes";
+
+    return $take === null ? $base.$suffix : "{$base}/{$take->id}{$suffix}";
+}
+
+test('編集者 (org owner) は adopt を実行でき、採用が反映される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertOk();
+
+    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);
+});
+
+test('編集者 (project_admin) も adopt / destroy を実行できる', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $editor = attachOrganizationMember($organization);
+    attachProjectMember($project, $editor, ProjectRole::Admin);
+    $editor->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($editor)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertOk();
+    $this->actingAs($editor)
+        ->deleteJson(pcTakePath($project, $manual, $cut, $take))
+        ->assertNoContent();
+
+    expect(Take::query()->whereKey($take->id)->exists())->toBeFalse();
+});
+
+test('編集者は presigned upload-url を発行できる (PC からのファイル追加の入口)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+
+    // S3 は叩かない (presign は fake 値を返す container mock に差し替える)
+    $storage = Mockery::mock(TakeObjectStorage::class);
+    $storage->shouldReceive('presignUpload')->andReturn(new PresignedUploadData(
+        url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
+        headers: ['Content-Type' => 'video/mp4', 'x-amz-checksum-sha256' => 'fake='],
+        expiresAt: CarbonImmutable::now()->addMinutes(30),
+    ));
+    app()->instance(TakeObjectStorage::class, $storage);
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut, null, '/upload-url'), [
+            'client_take_id' => (string) Str::ulid(),
+            'size_bytes' => 1_000_000,
+            'content_type' => 'video/mp4',
+            'checksum_sha256' => base64_encode(hash('sha256', 'blob', true)),
+        ])
+        ->assertOk()
+        ->assertJsonStructure(['upload_url', 'headers', 'ticket', 'client_take_id', 'expires_at']);
+});
+
+test('**撮影者 (project_member) も adopt を実行できる** (画面は 403 だが API は開いている)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $shooter = attachOrganizationMember($organization);
+    attachProjectMember($project, $shooter, ProjectRole::Member);
+    $shooter->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    // 画面は編集者限定 (403)
+    $this->actingAs($shooter)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes")
+        ->assertForbidden();
+
+    // API は撮影者にも開いている (PWA の採用導線。doc/10 §10.5)
+    $this->actingAs($shooter)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertOk();
+
+    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);
+});
+
+test('rendering 中の adopt は 409 (画面の事前告知と同じ理由)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'rendering']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertStatus(409);
+});
+
+test('ready でないテイクの adopt は 422', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['status' => TakeStatus::Processing->value]);
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertStatus(422);
+});
+
+test('DL 済みテイクの削除は 422 (画面はサーバ文言をそのまま出す)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->downloaded()->create();
+
+    $this->actingAs($owner)
+        ->deleteJson(pcTakePath($project, $manual, $cut, $take))
+        ->assertStatus(422);
+
+    expect(Take::query()->whereKey($take->id)->exists())->toBeTrue();
+});
+
+test('編集者は presigned 発行の続き (POST takes = 登録) まで通せる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+
+    // upload-url が作る予約行と、それに対応する署名チケットを用意する
+    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();
+    $reservation->refresh(); // DB 保存後の秒精度 expires_at で claims を作る
+    $ticket = app(UploadTicketCodec::class)->seal(UploadTicketClaims::fromReservation($reservation));
+
+    // S3 は叩かない (HeadObject は予約行と一致する値を返す container mock)
+    $storage = Mockery::mock(TakeObjectStorage::class);
+    $storage->shouldReceive('headObject')->with($reservation->video_path)->andReturn(new ObjectMetadataData(
+        contentLength: $reservation->size_bytes,
+        contentType: $reservation->content_type,
+        checksumSha256: $reservation->checksum_sha256,
+    ));
+    app()->instance(TakeObjectStorage::class, $storage);
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut), [
+            'ticket' => $ticket,
+            'client_take_id' => $reservation->client_take_id,
+            'duration_ms' => 5_000,
+            'captured_at' => now()->toIso8601String(),
+        ])
+        ->assertCreated()
+        // PC 画面は署名 URL を受け取らない (再生は playback の 302 経由のみ)
+        ->assertJsonPath('playback_url', null)
+        ->assertJsonPath('download_ack_token', null);
+
+    expect($cut->takes()->where('client_take_id', $reservation->client_take_id)->exists())->toBeTrue();
+});
+
+test('編集者は playback / thumbnail の 302 を受け取れる (画面の video と img の出所)', function (): void {
+    enableFakeStorage();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->withThumbnail()->create();
+
+    $playback = $this->actingAs($owner)->get(pcTakePath($project, $manual, $cut, $take, '/playback'));
+    $playback->assertStatus(302);
+    expect($playback->headers->get('Cache-Control'))->toContain('no-store');
+
+    $thumbnail = $this->actingAs($owner)->get(pcTakePath($project, $manual, $cut, $take, '/thumbnail'));
+    $thumbnail->assertStatus(302);
+    expect($thumbnail->headers->get('Location'))->toContain(urlencode((string) $take->thumbnail_path));
```

## 再検証結果 (全 green)

- composer test: 5416 tests, 5414 passed, 0 failed, 2 skipped (Round 1 時点 5413 → 追加 3 本)
- composer phpstan (level 10): No errors
- vendor/bin/pint --test / pnpm lint / pnpm typecheck: passed
- pnpm test: ManualsTakes.test.ts 24 passed (Round 1 時点 23 → 追加 1 本)

## 質問

上記の対応で Round 1 の指摘は解消しているか。
他に **完了条件を満たさない欠落** があれば指摘し、無ければ全体判定を APPROVED で明示せよ。
