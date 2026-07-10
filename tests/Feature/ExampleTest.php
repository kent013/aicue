<?php

declare(strict_types=1);

test('トップページが表示される', function (): void {
    $response = $this->get('/');

    $response->assertStatus(200);
});
