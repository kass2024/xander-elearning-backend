<?php



namespace App\Support;



use App\Models\Course;

use App\Models\User;

use Illuminate\Database\Eloquent\Builder;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\Schema;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;



class PlatformTenantScope

{

    public static function resolveActorEmail(Request $request): string

    {

        return strtolower(trim((string) (

            $request->input('user_email')

            ?? $request->query('user_email')

            ?? $request->query('email')

            ?? $request->input('email')

            ?? $request->header('X-User-Email')

            ?? ''

        )));

    }



    public static function resolveTenantId(Request $request): ?int

    {

        $partnerId = self::resolvePartnerTenantId($request);

        if ($partnerId !== null) {

            return $partnerId;

        }



        $email = self::resolveActorEmail($request);

        if ($email === '') {

            return null;

        }



        $user = User::query()

            ->whereRaw('LOWER(email) = ?', [$email])

            ->first();



        if (!$user) {

            return null;

        }



        if (PlatformInstitutionHelper::isMainPlatformAdmin($user)) {

            $explicit = $request->input('platform_institution_id') ?? $request->query('platform_institution_id');

            if ($explicit !== null && $explicit !== '') {

                return (int) $explicit;

            }



            return null;

        }



        if (!empty($user->platform_institution_id)) {

            return (int) $user->platform_institution_id;

        }



        return null;

    }



    public static function isPartnerRequest(Request $request): bool

    {

        return self::resolvePartnerTenantId($request) !== null;

    }



    public static function resolvePartnerTenantId(Request $request): ?int

    {

        $email = self::resolveActorEmail($request);

        if ($email === '') {

            return null;

        }



        $user = User::query()

            ->whereRaw('LOWER(email) = ?', [$email])

            ->first();



        if ($user && PlatformInstitutionHelper::isPartnerCompanyAdmin($user)) {

            return (int) $user->platform_institution_id;

        }



        return null;

    }



    /** @return list<int> */

    public static function tenantCourseIds(?int $tenantId): array

    {

        if ($tenantId === null) {

            return [];

        }



        return Course::query()

            ->where('platform_institution_id', $tenantId)

            ->pluck('id')

            ->map(fn ($id) => (int) $id)

            ->all();

    }



    /**

     * @param  Builder<Model>  $query

     */

    public static function applyToQuery(

        Builder $query,

        Request $request,

        string $column = 'platform_institution_id',

    ): Builder {

        $tenantId = self::resolveTenantId($request);

        if ($tenantId === null) {

            return $query;

        }



        $table = $query->getModel()->getTable();

        if (!Schema::hasColumn($table, $column)) {

            return $query;

        }



        return $query->where($table . '.' . $column, $tenantId);

    }



    public static function stampInstitutionId(Request $request, array &$data, string $key = 'platform_institution_id'): void

    {

        $tenantId = self::resolvePartnerTenantId($request);

        if ($tenantId !== null) {

            $data[$key] = $tenantId;

        }

    }



    public static function assertCanAccess(

        Request $request,

        Model $model,

        string $column = 'platform_institution_id',

    ): void {

        $tenantId = self::resolveTenantId($request);

        if ($tenantId === null) {

            return;

        }



        if (!Schema::hasColumn($model->getTable(), $column)) {

            return;

        }



        $recordTenant = $model->getAttribute($column);

        if ($recordTenant === null || (int) $recordTenant !== (int) $tenantId) {

            throw new AccessDeniedHttpException('This resource belongs to another institution.');

        }

    }

}


