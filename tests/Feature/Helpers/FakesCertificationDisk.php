<?php

namespace Tests\Feature\Helpers;

use Illuminate\Support\Facades\Storage;

trait FakesCertificationDisk
{
    /**
     * Disco fake per le foto delle richieste di certificazione (oc:8653):
     * la media library scrive sul disco `wmfe`.
     */
    protected function fakeCertificationDisk(): void
    {
        Storage::fake('wmfe');
        config(['media-library.disk_name' => 'wmfe']);
    }
}
