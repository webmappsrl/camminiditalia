<?php

namespace Tests\Feature;

use App\Services\PassportShare\StageShareLayout;
use Tests\TestCase;

/**
 * Regressione sull'immagine di condivisione della tappa (oc:8703): il codice
 * comune con l'immagine del cammino è stato estratto da
 * StageShareImageService in PassportShareCommon, e la firma del layout della
 * tappa non deve cambiare, altrimenti ogni immagine già salvata verrebbe
 * ricomposta alla prossima condivisione. Il valore atteso è stato registrato
 * sul codice di oc:8702, prima dell'estrazione.
 *
 * Durante l'estrazione l'immagine della tappa è stata verificata anche byte
 * per byte (md5 del PNG); quel controllo è stato tolto perché l'md5 dipende
 * dalle versioni di GD, FreeType e zlib e in CI non coincide con il Docker
 * locale. Il disegno resta coperto da StageShareImageServiceTest.
 */
class StageShareImageRegressionTest extends TestCase
{
    /** Firma del layout della tappa, registrata prima dell'estrazione. */
    private const EXPECTED_LAYOUT_SIGNATURE = 'd759644633fd8dfe56cf0fadeba8051f2f57b3a48a24b108b78ada6fb1b52b5b';

    /**
     * La firma del layout della tappa non cambia: cambiandola, ogni immagine
     * già salvata verrebbe ricomposta alla prossima condivisione.
     */
    public function test_layout_signature_is_unchanged(): void
    {
        $this->assertSame(self::EXPECTED_LAYOUT_SIGNATURE, StageShareLayout::signature());
    }
}
