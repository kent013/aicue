<?php

declare(strict_types=1);

/*
 * 使用済みの識別名への改名は 422 (家系裁定 AG-039 / AG-046)。
 */

test('他組織が使用中の識別名へは改名できない (422)', function (): void {
    [$mine, $owner] = createOrganizationWithOwner('自分の組織');
    [$other] = createOrganizationWithOwner('他人の組織');

    $this->actingAs($owner)
        ->patch("/organizations/{$mine->slug}/slug", ['slug' => $other->slug])
        ->assertSessionHasErrors('slug');

    expect($mine->fresh()?->slug)->not->toBe($other->slug);
});

test('大文字違いでも一意性は成立する (大文字小文字を区別しない)', function (): void {
    [$mine, $owner] = createOrganizationWithOwner('自分の組織');
    [$other] = createOrganizationWithOwner('他人の組織');

    $this->actingAs($owner)
        ->patch("/organizations/{$mine->slug}/slug", ['slug' => strtoupper($other->slug)])
        ->assertSessionHasErrors('slug');
});
