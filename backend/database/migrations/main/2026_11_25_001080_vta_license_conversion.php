<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-VTA RN-VTA-23 y M-AUL RN-AUL-33: la compra de cupos de una empresa compradora (course_licenses, nace al pagarse el pedido y no
 * tiene matrícula propia) también convierte la recomendación del mismo curso hecha a esa empresa (por su RUC: recomendación a la
 * empresa o a su representante), si la vista o el clic fue dentro de recommendation_attribution_days antes de la compra; se atribuye
 * a una sola recomendación, la más reciente, con el pedido vinculado (converted_order_id).
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
create or replace function vta_attribute_license_conversion() returns trigger language plpgsql as $$
declare
    v_days int;
    v_rec uuid;
    v_order uuid;
begin
    if new.buyer_ruc is null or new.order_item_id is null then
        return new;
    end if;
    select case when value ~ '^[0-9]+$' then value::int end into v_days
      from legal_parameters
     where key = 'recommendation_attribution_days' and (company_id is null or company_id = new.company_id)
       and valid_from <= current_date and (valid_to is null or valid_to >= current_date) and deleted_at is null
     order by company_id nulls last, valid_from desc limit 1;
    select i.order_id into v_order from order_items i where i.id = new.order_item_id;
    select r.id into v_rec
      from course_recommendations r
      left join external_learners xl on xl.id = r.external_learner_id
     where r.company_id = new.company_id and r.course_id = new.course_id and r.deleted_at is null
       and r.status in ('sent', 'viewed', 'clicked')
       and coalesce(r.buyer_company_ruc, xl.buyer_company_ruc) = new.buyer_ruc
       and coalesce(r.clicked_at, r.viewed_at) is not null
       and coalesce(r.clicked_at, r.viewed_at) <= coalesce(new.created_at, now())
       and (v_days is null or coalesce(r.clicked_at, r.viewed_at) >= coalesce(new.created_at, now()) - make_interval(days => v_days))
     order by coalesce(r.clicked_at, r.viewed_at) desc
     limit 1;
    if v_rec is not null then
        update course_recommendations
           set status = 'converted', converted_at = now(), converted_order_id = v_order, updated_at = now()
         where id = v_rec;
    end if;
    return new;
end $$;
SQL);
        DB::statement('drop trigger if exists trg_vta_attribute_license_conversion on course_licenses');
        DB::statement('create trigger trg_vta_attribute_license_conversion after insert on course_licenses for each row execute function vta_attribute_license_conversion()');
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists trg_vta_attribute_license_conversion on course_licenses');
        DB::statement('drop function if exists vta_attribute_license_conversion()');
    }
};
