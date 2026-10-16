<?php

namespace App\Http\Controllers;

use App\Models\V5ResearchScannerRun;
use App\Models\V5ShadowPrediction;
use Illuminate\Http\JsonResponse;

final class V5ResearchController extends Controller
{
    private function latestRun(): ?V5ResearchScannerRun { return V5ResearchScannerRun::query()->latest('as_of')->with('candidates')->first(); }
    public function overview(): JsonResponse { $run=$this->latestRun(); return response()->json(['status'=>'RESEARCH_ONLY','execution_authorized'=>false,'latest_run'=>$run]); }
    public function latest(): JsonResponse { return response()->json(['execution_authorized'=>false,'run'=>$this->latestRun()]); }
    public function candidates(): JsonResponse { $run=$this->latestRun(); return response()->json(['execution_authorized'=>false,'candidates'=>$run?->candidates ?? []]); }
    public function status(): JsonResponse { $run=$this->latestRun(); return response()->json(['execution_authorized'=>false,'data_sources'=>$run?->source_status ?? ['status'=>'NO_RUN'],'model_status'=>'UNCALIBRATED_RESEARCH_ONLY']); }
    public function history(): JsonResponse { return response()->json(['execution_authorized'=>false,'predictions'=>V5ShadowPrediction::query()->latest('forecast_at')->limit(100)->get()]); }
}
