<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PendingCertificationRequestExistsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCertificationRequestRequest;
use App\Models\CertificationRequest;
use App\Services\CertificationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wm\WmPackage\Models\Layer;

class CertificationRequestController extends Controller
{
    public function __construct(private readonly CertificationRequestService $service) {}

    public function store(StoreCertificationRequestRequest $request, Layer $layer): JsonResponse
    {
        try {
            $certificationRequest = $this->service->submit(
                $request->user(),
                $layer,
                $request->file('images'),
                $request->input('serial_number'),
            );
        } catch (PendingCertificationRequestExistsException) {
            return response()->json([
                'message' => __('A certification request is already pending for this route.'),
            ], 409);
        }

        return $this->pendingResponse($certificationRequest, 201);
    }

    public function show(Request $request, Layer $layer): JsonResponse
    {
        $certificationRequest = $this->service->currentFor($request->user(), $layer);

        if ($certificationRequest === null) {
            return response()->json(['status' => CertificationRequest::STATUS_NONE]);
        }

        return $this->pendingResponse($certificationRequest);
    }

    private function pendingResponse(CertificationRequest $certificationRequest, int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => $certificationRequest->status,
            'submitted_at' => $certificationRequest->created_at->toIso8601String(),
        ], $status);
    }
}
