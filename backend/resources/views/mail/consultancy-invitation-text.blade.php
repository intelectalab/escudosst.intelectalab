Hola:

{{ $consultancy }} te invita a gestionar la seguridad y salud en el trabajo de {{ $companyName }} (RUC {{ $ruc }}) en EscudoSST.

@if ($companyExists)
Tu empresa ya usa EscudoSST. Si aceptas, la consultora podrá trabajar en ella; puedes quitarle el acceso cuando quieras.
@else
Crea la cuenta de tu empresa y elige tu plan{{ $planName ? ' (la consultora te sugiere el plan '.$planName.')' : '' }}. Luego vuelve a este enlace para aceptar.
@endif

Ver la invitación: {{ $url }}

El enlace vence en {{ $days }} días y sirve una sola vez.

Si no esperabas este correo, ignóralo.
— EscudoSST
