<?php

namespace App\Modules\REC;

use App\Core\Auth\Permissions;
use App\Core\Http\ApiProblem;
use App\Http\Controllers\Controller;
use App\Modules\FIR\Otp;
use App\Modules\FIR\SignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aprobación de la política SST con firma OTP (M-POL RN-POL-02, flujo §7 paso 3):
 *  POST /pol/policies/{id}/approval-code  envía a gerencia un código de un solo uso a su correo
 *  POST /pol/policies/{id}/approve {otp}  con el código correcto aprueba la versión y deja su firma (método OTP) en M-FIR
 * El cambio a «aprobada» por la API común de registros se rechaza: solo se aprueba con el código.
 */
class PolicyApprovalController extends Controller
{
    public function code(string $id): JsonResponse
    {
        $p = $this->policy($id);

        return response()->json(['data' => Otp::send($this->key($p->id), (string) auth()->id(), 'aprobar la política SST')]);
    }

    public function approve(Request $request, string $id, RecordsService $records): JsonResponse
    {
        $p = $this->policy($id);
        $uid = (string) auth()->id();
        Otp::check($this->key($p->id), (string) $request->input('otp'));
        $out = DB::transaction(function () use ($records, $p, $uid) {
            $request = request();
            $request->attributes->set('pol_otp_verified', $p->id);
            $row = $records->update(Registry::resource('POL', 'sst_policies'), $p->id, ['status' => 'approved']);
            [$reqId] = SignatureService::signNow('sst_policies', $p->id, 'POL', 'approver', $uid, 'otp');
            DB::update('update sst_policies set signature_request_id = ? where id = ?', [$reqId, $p->id]);

            return $row;
        });

        return response()->json(['data' => $out]);
    }

    private function policy(string $id): object
    {
        if (! in_array('pol.sst_policies.approve', Permissions::effective(), true)) {
            throw ApiProblem::forbidden('La política la aprueba la gerencia (su rol tiene el permiso de aprobar la política).');
        }
        $p = Str::isUuid($id) ? DB::selectOne('select id, status, prepared_by from sst_policies where id = ? and deleted_at is null', [$id]) : null;
        if (! $p) {
            throw ApiProblem::notFound('La política no existe.');
        }
        if (! in_array($p->status, ['draft', 'in_review'], true)) {
            throw new ApiProblem(409, 'conflict', 'No se aprueba ahora', 'Solo se aprueba una versión en borrador o en revisión.');
        }
        if ((string) $p->prepared_by === (string) auth()->id()) {
            throw new ApiProblem(409, 'conflict', 'Aprueba otra persona', 'Quien elaboró la versión no puede aprobarla.');
        }

        return $p;
    }

    private function key(string $policyId): string
    {
        return "pol-otp:{$policyId}:".auth()->id();
    }
}
