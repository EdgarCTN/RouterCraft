<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;

class RouteController extends Controller
{
    public function calculateRoutes(Request $request)
    {
        $operation = Operation::find($request->operation_id);
        if (!$operation) {
            return response()->json(['error' => 'Operation not found'], 404);
        }

        $storage = $operation->storage;
        $clients = $operation->clients;

        $distances = $this->calculateDistances($storage, $clients);

        $routes = $this->buildRoutes($distances, $clients);

        return response()->json(compact('routes'), 200);
    }

    private function calculateDistances($storage, $clients)
    {
        $distances = [];
    
        foreach ($clients as $client) {
            $distances['A'][$client->id] = $this->getDistance($storage->latitude, $storage->longitude, $client->latitude, $client->longitude);
            $distances[$client->id]['A'] = $distances['A'][$client->id];
        }
    
        foreach ($clients as $client1) {
            foreach ($clients as $client2) {
                if ($client1->id != $client2->id) {
                    $distances[$client1->id][$client2->id] = $this->getDistance($client1->latitude, $client1->longitude, $client2->latitude, $client2->longitude);
                    $distances[$client2->id][$client1->id] = $distances[$client1->id][$client2->id];
                }
            }
        }
    
        return $distances;
    }

    private function getDistance($lat1, $lon1, $lat2, $lon2)
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json', [
            'origins' => "$lat1,$lon1",
            'destinations' => "$lat2,$lon2",
            'mode' => 'driving',
            'key' => env('APP_GOOGLE_MAPS_API_KEY'),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            if (isset($data['rows'][0]['elements'][0]['distance']['value'])) {
                return $data['rows'][0]['elements'][0]['distance']['value'];
            }
        }
        return null;
    }

    private function buildRoutes($distances, $clients)
    {
        $routes = [];
        $vehicleCapacity = 100;
        $vehicle = 1;
        $demands = $clients->pluck('demand', 'id')->toArray();
        $nodesVisited = [];
        
        $ahorros = [];
        foreach ($clients as $client1) {
            foreach ($clients as $client2) {
                if ($client1->id != $client2->id) {
                    $ahorro = $distances['A'][$client1->id] + $distances['A'][$client2->id] - $distances[$client1->id][$client2->id];
                    $ahorros[] = [
                        'ruta' => "{$client1->id}-{$client2->id}",
                        'ahorro' => $ahorro
                    ];
                }
            }
        }
    
        usort($ahorros, function($a, $b) {
            return $b['ahorro'] - $a['ahorro'];
        });
    
        $rutas = [];
        foreach ($clients as $client) {
            $rutas[$client->id] = [$client->id];
        }
    
        $changesMade = true;
    
        while ($changesMade) {
            $changesMade = false;
    
            foreach ($ahorros as $ahorro) {
                list($id1, $id2) = explode('-', $ahorro['ruta']);
    
                if (isset($rutas[$id1]) && isset($rutas[$id2]) && $rutas[$id1] !== $rutas[$id2]) {
                    $capacidad1 = array_sum(array_map(function($id) use ($demands) { return $demands[$id]; }, $rutas[$id1]));
                    $capacidad2 = array_sum(array_map(function($id) use ($demands) { return $demands[$id]; }, $rutas[$id2]));
    
                    if (($capacidad1 + $capacidad2) <= $vehicleCapacity) {
                        $rutas[$id1] = array_merge($rutas[$id1], $rutas[$id2]);
                        foreach ($rutas[$id2] as $node) {
                            $rutas[$node] = $rutas[$id1];
                        }
                        unset($rutas[$id2]);
                        $changesMade = true;
                    }
                }
            }
        }
    
        $uniqueRoutes = [];
        foreach ($rutas as $ruta) {
            $serialized = serialize(array_unique($ruta));
            if (!isset($uniqueRoutes[$serialized])) {
                $uniqueRoutes[$serialized] = array_unique($ruta);
            }
        }
    
        foreach ($uniqueRoutes as $ruta) {
            $capacidadRuta = array_sum(array_map(function($id) use ($demands) { return $demands[$id]; }, $ruta));
            $distanciaRuta = $distances['A'][$ruta[0]];
            for ($i = 0; $i < count($ruta) - 1; $i++) {
                $distanciaRuta += $distances[$ruta[$i]][$ruta[$i+1]];
            }
            $distanciaRuta += $distances[$ruta[count($ruta) - 1]]['A'];
    
            $routes[] = [
                'route' => 'A-' . implode('-', $ruta) . '-A',
                'capacity' => $capacidadRuta,
                'distance' => $distanciaRuta,
                'vehicle' => $vehicle++
            ];
            $nodesVisited = array_merge($nodesVisited, $ruta);
        }
    
        foreach ($clients as $client) {
            if (!in_array($client->id, $nodesVisited)) {
                $distance = $distances['A'][$client->id] * 2;
                $routes[] = [
                    'route' => "A-{$client->id}-A",
                    'capacity' => $demands[$client->id],
                    'distance' => $distance,
                    'vehicle' => $vehicle++
                ];
            }
        }
    
        return $routes;
    }
}
