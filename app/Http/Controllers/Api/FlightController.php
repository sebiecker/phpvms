<?php

namespace App\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Exceptions\AssetNotFound;
use App\Http\Resources\Flight as FlightResource;
use App\Http\Resources\Navdata as NavdataResource;
use App\Models\SimBrief;
use App\Repositories\Criteria\WhereCriteria;
use App\Repositories\FlightRepository;
use App\Services\FlightService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class FlightController extends Controller
{
    private $flightRepo;
    private $flightSvc;

    /**
     * FlightController constructor.
     *
     * @param FlightRepository $flightRepo
     * @param FlightService    $flightSvc
     */
    public function __construct(
        FlightRepository $flightRepo,
        FlightService $flightSvc
    ) {
        $this->flightRepo = $flightRepo;
        $this->flightSvc = $flightSvc;
    }

    /**
     * Return all the flights, paginated
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        return $this->search($request);
    }

    /**
     * @param $id
     *
     * @return FlightResource
     */
    public function get($id)
    {
        $user = Auth::user();
        $flight = $this->flightRepo->with([
            'airline',
            'subfleets',
            'subfleets.aircraft',
            'subfleets.fares',
            'field_values',
            'simbrief' => function ($query) use ($user) {
                return $query->where('user_id', $user->id);
            },
        ])->find($id);

        $this->flightSvc->filterSubfleets(Auth::user(), $flight);

        return new FlightResource($flight);
    }

    /**
     * @param Request $request
     *
     * @return mixed
     */
    public function search(Request $request)
    {
        $user = Auth::user();
        $where = [
            'active'  => true,
            'visible' => true,
        ];

        // Allow the option to bypass some of these restrictions for the searches
        if (!$request->filled('ignore_restrictions')
            || $request->get('ignore_restrictions') === '0'
        ) {
            if (setting('pilots.restrict_to_company')) {
                $where['airline_id'] = Auth::user()->airline_id;
            }

            if (setting('pilots.only_flights_from_current')) {
                $where['dpt_airport_id'] = Auth::user()->curr_airport_id;
            }
        }

        try {
            $this->flightRepo->resetCriteria();
            $this->flightRepo->searchCriteria($request);
            $this->flightRepo->pushCriteria(new WhereCriteria($request, $where));
            $this->flightRepo->pushCriteria(new RequestCriteria($request));

            $flights = $this->flightRepo
                ->with([
                    'airline',
                    'subfleets',
                    'subfleets.aircraft',
                    'subfleets.fares',
                    'field_values',
                    'simbrief' => function ($query) use ($user) {
                        return $query->where('user_id', $user->id);
                    },
                ])
                ->paginate();
        } catch (RepositoryException $e) {
            return response($e, 503);
        }

        // TODO: Remove any flights here that a user doesn't have permissions to
        foreach ($flights as $flight) {
            $this->flightSvc->filterSubfleets(Auth::user(), $flight);
        }

        return FlightResource::collection($flights);
    }

    /**
     * Output the flight briefing from simbrief or whatever other format
     *
     * @param string $id The flight ID
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function briefing($id)
    {
        $user = Auth::user();
        $w = [
            'user_id'   => $user->id,
            'flight_id' => $id,
        ];

        $simbrief = SimBrief::where($w)->first();

        if ($simbrief === null) {
            throw new AssetNotFound(new Exception('Flight briefing not found'));
        }

        return response($simbrief->acars_xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }

    /**
     * Get a flight's route
     *
     * @param         $id
     * @param Request $request
     *
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function route($id, Request $request)
    {
        $flight = $this->flightRepo->find($id);
        $route = $this->flightSvc->getRoute($flight);

        return NavdataResource::collection($route);
    }
}
