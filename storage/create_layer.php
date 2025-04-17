<?php

use Wm\WmPackage\Models\Layer;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

$layer = new Layer();
$layer->app_id = 1;
$layer->name = 'Layer Test ' . date('Y-m-d H:i:s');
$layer->geometry = DB::raw("ST_GeomFromText('POLYGON((12.4 41.9, 12.5 41.9, 12.5 42.0, 12.4 42.0, 12.4 41.9))', 4326)");
$layer->properties = json_encode(['description' => 'Layer di test creato via Tinker']);
$layer->rank = 1;
$layer->save();

echo "Layer creato con ID: " . $layer->id . "\n";
