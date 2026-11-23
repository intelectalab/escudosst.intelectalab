<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-AUL:
 * - §5 course_sections.unlock_rule (siempre, al completar la anterior, desde fecha) y RN-AUL-05: cada sección respeta su regla.
 * - RN-AUL-33: la conversión de una recomendación se atribuye sola cuando la persona se matricula en el mismo curso (por su cuenta,
 *   por compra o asignada) después de verla o abrirla, dentro de recommendation_attribution_days si la empresa fija ese parámetro;
 *   se atribuye a una sola recomendación, la más reciente.
 * Se corre con el rol dueño: php artisan migrate --database=pgsql_owner
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement("alter table course_sections add column if not exists unlock_rule text not null default 'always', add column if not exists unlock_on date");
        DB::statement('alter table course_sections drop constraint if exists course_sections_unlock_rule_check');
        DB::statement("alter table course_sections add constraint course_sections_unlock_rule_check check (unlock_rule in ('always', 'after_previous', 'from_date') and (unlock_rule <> 'from_date' or unlock_on is not null))");

        DB::unprepared(<<<'SQL'
create or replace function aul_attribute_conversion() returns trigger language plpgsql as $$
declare
    v_days int;
    v_rec uuid;
begin
    if new.worker_id is null and new.external_learner_id is null then
        return new;
    end if;
    select case when value ~ '^[0-9]+$' then value::int end into v_days
      from legal_parameters
     where key = 'recommendation_attribution_days' and (company_id is null or company_id = new.company_id)
       and valid_from <= current_date and (valid_to is null or valid_to >= current_date) and deleted_at is null
     order by company_id nulls last, valid_from desc limit 1;
    select r.id into v_rec
      from course_recommendations r
     where r.company_id = new.company_id and r.course_id = new.course_id and r.deleted_at is null
       and r.status in ('sent', 'viewed', 'clicked')
       and ((new.worker_id is not null and r.worker_id = new.worker_id) or (new.external_learner_id is not null and r.external_learner_id = new.external_learner_id))
       and coalesce(r.clicked_at, r.viewed_at) is not null
       and coalesce(r.clicked_at, r.viewed_at) <= coalesce(new.enrolled_at, now())
       and (v_days is null or coalesce(r.clicked_at, r.viewed_at) >= coalesce(new.enrolled_at, now()) - make_interval(days => v_days))
     order by coalesce(r.clicked_at, r.viewed_at) desc
     limit 1;
    if v_rec is not null then
        update course_recommendations
           set status = 'converted', converted_at = now(), converted_enrollment_id = new.id,
               converted_order_id = (select i.order_id from order_items i where i.id = new.order_item_id), updated_at = now()
         where id = v_rec;
    end if;
    return new;
end $$;
SQL);
        DB::statement('drop trigger if exists trg_aul_attribute_conversion on course_enrollments');
        DB::statement('create trigger trg_aul_attribute_conversion after insert on course_enrollments for each row execute function aul_attribute_conversion()');
    }

    public function down(): void
    {
    }
};
