<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modello unico per qualunque classificazione (oc:8910): una riga senza parent_id è una
        // tassonomia, una riga con parent_id è un termine di quella tassonomia. Così una classificazione
        // nuova non richiede una migration.
        if (! Schema::hasTable('taxonomy_terms')) {
            Schema::create('taxonomy_terms', function (Blueprint $table) {
                $table->id();
                // Senza `nullOnDelete`: cancellare una tassonomia lascerebbe i suoi termini con
                // `parent_id` nullo, cioè li promuoverebbe a tassonomie, e le loro relazioni
                // punterebbero a una tassonomia che non esiste più. NO ACTION rifiuta la
                // cancellazione finché ci sono termini dentro, come fanno le tassonomie storiche
                // (`taxonomy_activityables` e le altre). Il controllo è a fine statement, quindi
                // svuotare la tabella in un colpo solo continua a funzionare, al contrario di
                // `restrictOnDelete()` (oc:8910).
                $table->foreignId('parent_id')->nullable()->constrained('taxonomy_terms');
                // Di sistema = portato dal package, acceso in ogni app appena nasce. I
                // tassonomie creati da uno shard sono custom: nascono spenti e si accendono
                // nelle app che li vogliono (oc:8910).
                $table->boolean('is_system')->default(false);
                $table->string('identifier')->unique();
                $table->jsonb('name')->nullable();
                $table->jsonb('description')->nullable();
                $table->jsonb('excerpt')->nullable();
                $table->string('icon')->nullable();
                $table->smallInteger('sort_order')->default(0);
                $table->boolean('single_choice')->default(false);
                $table->jsonb('properties')->nullable();
                $table->timestamps();

                $table->index('parent_id');
            });

            // Due livelli e basta: un termine non può essere il padre di un altro termine.
            // Un CHECK non può interrogare un'altra riga, quindi serve un trigger. La regola
            // viveva nel codice e nei test: qui la dice il database, per chiunque scriva.
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION taxonomy_terms_two_levels() RETURNS trigger AS $$
                BEGIN
                    IF NEW.parent_id IS NOT NULL
                       AND (SELECT parent_id FROM taxonomy_terms WHERE id = NEW.parent_id) IS NOT NULL
                    THEN
                        RAISE EXCEPTION 'taxonomy_terms: un termine non può avere figli (parent_id=%)', NEW.parent_id;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::statement(<<<'SQL'
                CREATE TRIGGER taxonomy_terms_two_levels_trigger
                BEFORE INSERT OR UPDATE OF parent_id ON taxonomy_terms
                FOR EACH ROW EXECUTE FUNCTION taxonomy_terms_two_levels();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_terms');
        DB::statement('DROP FUNCTION IF EXISTS taxonomy_terms_two_levels()');
    }
};
