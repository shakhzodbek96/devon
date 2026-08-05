<?php

it('reports ok status with a working database', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok'],
        ])
        ->assertJsonStructure(['status', 'timestamp', 'checks' => ['database']]);
});

it('requires no authentication', function () {
    $this->getJson('/api/v1/health')->assertOk();
});
