<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `validated_ec_tracks` diventa `passport_stages` (oc:8165).
 *
 * Il nome vecchio diceva una cosa sola — le tappe validate — e da questo ticket la tabella ne
 * contiene due: le tappe validate e quelle ancora a metà, con la loro percentuale. Un nome che
 * mente è il modo più facile per contare come percorse delle tappe che non lo sono, in una
 * query scritta a mano fra sei mesi.
 *
 * `passport_stages` dice quello che la tabella è: le tappe nel passaporto di una persona,
 * ciascuna con il suo stato. Sta anche nella famiglia che esiste già (`passport_shares`).
 *
 * Il rename è istantaneo e non tocca i dati: chiavi esterne e indici seguono la tabella da sé,
 * perché puntano al suo identificativo interno. Si rinominano però anche indici, vincoli e
 * sequenza, che altrimenti conserverebbero il nome vecchio e la stessa confusione.
 *
 * Al deploy: riavviare i worker Horizon subito dopo. Girano in un container che il deploy non
 * riavvia, e fino al riavvio cercano il nome vecchio.
 */
return new class extends Migration
{
    /** @var array<string, string> vecchio nome => nuovo nome */
    private const CONSTRAINTS = [
        'validated_ec_tracks_pkey' => 'passport_stages_pkey',
        'validated_ec_tracks_user_id_ec_track_id_unique' => 'passport_stages_user_id_ec_track_id_unique',
        'validated_ec_tracks_certification_request_id_foreign' => 'passport_stages_certification_request_id_foreign',
        'validated_ec_tracks_ec_track_id_foreign' => 'passport_stages_ec_track_id_foreign',
        'validated_ec_tracks_layer_id_foreign' => 'passport_stages_layer_id_foreign',
        'validated_ec_tracks_user_id_foreign' => 'passport_stages_user_id_foreign',
    ];

    private const INDEXES = [
        'validated_ec_tracks_certification_request_id_index' => 'passport_stages_certification_request_id_index',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE validated_ec_tracks RENAME TO passport_stages');
        DB::statement('ALTER SEQUENCE validated_ec_tracks_id_seq RENAME TO passport_stages_id_seq');

        foreach (self::CONSTRAINTS as $from => $to) {
            DB::statement("ALTER TABLE passport_stages RENAME CONSTRAINT {$from} TO {$to}");
        }

        foreach (self::INDEXES as $from => $to) {
            DB::statement("ALTER INDEX {$from} RENAME TO {$to}");
        }

        DB::statement('ALTER TABLE validated_ec_track_ugc_track RENAME TO passport_stage_ugc_track');
        DB::statement('ALTER TABLE passport_stage_ugc_track RENAME COLUMN validated_ec_track_id TO passport_stage_id');
        DB::statement('ALTER TABLE passport_stage_ugc_track RENAME CONSTRAINT validated_ec_track_ugc_track_validated_ec_track_id_foreign TO passport_stage_ugc_track_passport_stage_id_foreign');
        DB::statement('ALTER TABLE passport_stage_ugc_track RENAME CONSTRAINT validated_ec_track_ugc_track_ugc_track_id_foreign TO passport_stage_ugc_track_ugc_track_id_foreign');
        DB::statement('ALTER INDEX validated_ec_track_ugc_track_pkey RENAME TO passport_stage_ugc_track_pkey');
        DB::statement('ALTER INDEX validated_ec_track_ugc_track_ugc_track_id_index RENAME TO passport_stage_ugc_track_ugc_track_id_index');
    }

    public function down(): void
    {
        DB::statement('ALTER INDEX passport_stage_ugc_track_ugc_track_id_index RENAME TO validated_ec_track_ugc_track_ugc_track_id_index');
        DB::statement('ALTER INDEX passport_stage_ugc_track_pkey RENAME TO validated_ec_track_ugc_track_pkey');
        DB::statement('ALTER TABLE passport_stage_ugc_track RENAME CONSTRAINT passport_stage_ugc_track_ugc_track_id_foreign TO validated_ec_track_ugc_track_ugc_track_id_foreign');
        DB::statement('ALTER TABLE passport_stage_ugc_track RENAME CONSTRAINT passport_stage_ugc_track_passport_stage_id_foreign TO validated_ec_track_ugc_track_validated_ec_track_id_foreign');
        DB::statement('ALTER TABLE passport_stage_ugc_track RENAME COLUMN passport_stage_id TO validated_ec_track_id');
        DB::statement('ALTER TABLE passport_stage_ugc_track RENAME TO validated_ec_track_ugc_track');

        foreach (array_reverse(self::INDEXES, true) as $from => $to) {
            DB::statement("ALTER INDEX {$to} RENAME TO {$from}");
        }

        foreach (array_reverse(self::CONSTRAINTS, true) as $from => $to) {
            DB::statement("ALTER TABLE passport_stages RENAME CONSTRAINT {$to} TO {$from}");
        }

        DB::statement('ALTER SEQUENCE passport_stages_id_seq RENAME TO validated_ec_tracks_id_seq');
        DB::statement('ALTER TABLE passport_stages RENAME TO validated_ec_tracks');
    }
};
