-- Huella de la estructura de una base (tablas, columnas, restricciones, índices, disparadores, políticas RLS, funciones,
-- permisos, dueños y comentarios), una línea por objeto y en orden. Sirve para comprobar que database/schema_main.sql
-- (o schema_medical.sql) arma la misma base que las migraciones:
--   psql -X -d <base_desde_el_esquema> -f database/tools/structure.sql > a.txt
--   psql -X -d <base_migrada>          -f database/tools/structure.sql > b.txt
--   diff a.txt b.txt        (sin diferencias = el esquema está al día)
-- Se omiten las tablas propias de Laravel (migrations, failed_jobs, personal_access_tokens), que crean sus migraciones,
-- y las particiones mensuales. El rol con que se corrieron las migraciones (escudo_owner_login) cuenta como escudo_owner.
\pset format unaligned
\pset tuples_only on
with rels as (
  select c.oid, c.relname, c.relkind, c.relrowsecurity, c.relforcerowsecurity, c.relacl, c.relispartition, c.relowner
  from pg_class c join pg_namespace n on n.oid=c.relnamespace
  where n.nspname='public' and c.relkind in ('r','p','v','m','S','f') and not c.relispartition
    and c.relname not in ('migrations','migrations_id_seq','failed_jobs','failed_jobs_id_seq','personal_access_tokens','personal_access_tokens_id_seq')
)
select line from (
  select 'T '||relname||' '||relkind::text||' rls='||relrowsecurity||'/'||relforcerowsecurity||' part='||relispartition as line from rels
  union all
  select 'A '||relname||' '||coalesce((select string_agg(replace(regexp_replace(x::text,'/.*$',''),'escudo_owner_login','escudo_owner'), ',' order by x::text) from unnest(relacl) x),'') from rels
  union all
  select 'C '||r.relname||'.'||a.attname||' '||format_type(a.atttypid,a.atttypmod)||' nn='||a.attnotnull||' def='||coalesce(pg_get_expr(d.adbin,d.adrelid),'')||' gen='||a.attgenerated::text||' id='||a.attidentity::text
  from rels r join pg_attribute a on a.attrelid=r.oid and a.attnum>0 and not a.attisdropped
  left join pg_attrdef d on d.adrelid=a.attrelid and d.adnum=a.attnum where r.relkind in ('r','p','v','m','f')
  union all
  select 'D '||r.relname||'.'||coalesce(a.attname,'')||' '||md5(coalesce(col_description(r.oid,a.attnum),''))
  from rels r join pg_attribute a on a.attrelid=r.oid and a.attnum>0 and not a.attisdropped where col_description(r.oid,a.attnum) is not null
  union all
  select 'D '||r.relname||' '||md5(obj_description(r.oid,'pg_class')) from rels r where obj_description(r.oid,'pg_class') is not null
  union all
  select 'K '||r.relname||' '||k.conname||' '||pg_get_constraintdef(k.oid) from pg_constraint k join rels r on r.oid=k.conrelid
  union all
  select 'I '||tablename||' '||indexname||' '||indexdef from pg_indexes where schemaname='public' and tablename in (select relname from rels)
  union all
  select 'G '||r.relname||' '||t.tgname||' '||pg_get_triggerdef(t.oid) from pg_trigger t join rels r on r.oid=t.tgrelid where not t.tgisinternal
  union all
  select 'P '||tablename||' '||policyname||' '||permissive||' '||roles::text||' '||cmd||' q='||coalesce(qual,'')||' w='||coalesce(with_check,'') from pg_policies where schemaname='public'
  union all
  select 'F '||p.proname||'('||pg_get_function_identity_arguments(p.oid)||') '||md5(pg_get_functiondef(p.oid))
     ||' acl='||coalesce((select string_agg(regexp_replace(x::text,'/.*$',''), ',' order by x::text) from unnest(p.proacl) x where x::text !~ ('^'||pg_get_userbyid(p.proowner)||'=') and x::text !~ '^escudo_owner(_login)?='),'')
     ||case when p.prosecdef then ' owner='||pg_get_userbyid(p.proowner) else '' end
  from pg_proc p join pg_namespace n on n.oid=p.pronamespace where n.nspname='public' and p.prokind in ('f','p')
  union all
  select 'V '||viewname||' '||md5(definition) from pg_views where schemaname='public'
  union all
  select 'O '||r.relname||' owner='||replace(pg_get_userbyid(r.relowner),'escudo_owner_login','escudo_owner') from rels r
  union all
  select 'E '||extname from pg_extension
  union all
  select 'Y '||t.typname||' '||t.typtype::text from pg_type t join pg_namespace n on n.oid=t.typnamespace where n.nspname='public' and t.typtype in ('e','d','c') and not exists (select 1 from pg_class c where c.reltype=t.oid)
) s order by 1;
