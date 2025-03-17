<?php

namespace Wm\LayerFeatures\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class LayerFeatureController extends Controller
{
    public function sync(Request $request, $layerId)
    {
        $validated = $request->validate([
            'features' => 'required|array',
            'model' => 'required|string'
        ]);

        // TODO: Implement sync logic

        return response()->json(['message' => 'Features synchronized']);
    }
}
