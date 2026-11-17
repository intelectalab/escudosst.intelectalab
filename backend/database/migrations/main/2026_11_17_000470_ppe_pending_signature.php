<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-EPP RN-EPP-02 y §9: toda entrega requiere la firma del trabajador; sin firma queda «pendiente de firma» y no cuenta como
 * entregada. Las entregas ya registradas sin firma pasan a ese estado.
 * Sin transacción envolvente: la restricción se cambia con el rol dueño y los datos con la conexión de la aplicación (la política RLS
 * de la tabla es para ella), empresa por empresa; así ninguna espera a la otra.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private function status(array $values): void
    {
        DB::statement('alter table ppe_deliveries drop constraint if exists ppe_deliveries_status_check');
        DB::statement("alter table ppe_deliveries add constraint ppe_deliveries_status_check check (status in ('".implode("', '", $values)."'))");
    }

    private function eachCompany(string $sql): void
    {
        foreach (DB::connection('platform')->select('select id from companies') as $c) {
            $app = DB::connection('pgsql');
            $app->transaction(function () use ($app, $c, $sql) {
                $app->select("select set_config('app.company_id', ?, true)", [$c->id]);
                $app->update($sql);
            });
        }
    }

    public function up(): void
    {
        $this->status(['pending_signature', 'delivered', 'due_for_replacement', 'returned', 'replaced', 'lost']);
        $this->eachCompany("update ppe_deliveries set status = 'pending_signature' where status in ('delivered', 'due_for_replacement') and signature_id is null and deleted_at is null");
    }

    public function down(): void
    {
        $this->eachCompany("update ppe_deliveries set status = 'delivered' where status = 'pending_signature'");
        $this->status(['delivered', 'due_for_replacement', 'returned', 'replaced', 'lost']);
    }
};
