<?php

namespace App\Http\Controllers;

use App\Models\V5ResearchScannerRun;
use App\Models\V5ShadowPrediction;
use App\Models\V5ResearchSelection;
use Illuminate\Http\JsonResponse;

final class V5ResearchController extends Controller
{
    private function latestRun(): ?V5ResearchScannerRun { return V5ResearchScannerRun::query()->latest('as_of')->with('candidates')->first(); }
    private function displayedRun(): ?V5ResearchScannerRun { $run=$this->latestRun(); if($run){$run->setRelation('candidates',$run->candidates->sortBy('rank')->take(3)->values());} return $run; }
    public function overview(): JsonResponse { $run=$this->displayedRun(); return response()->json(['status'=>'RESEARCH_ONLY','execution_authorized'=>false,'latest_run'=>$run]); }
    public function latest(): JsonResponse { return response()->json(['execution_authorized'=>false,'run'=>$this->displayedRun()]); }
    public function candidates(): JsonResponse { $run=$this->displayedRun(); return response()->json(['execution_authorized'=>false,'candidates'=>$run?->candidates ?? []]); }
    public function status(): JsonResponse { $run=$this->latestRun(); return response()->json(['execution_authorized'=>false,'data_sources'=>$run?->source_status ?? ['status'=>'NO_RUN'],'model_status'=>'UNCALIBRATED_RESEARCH_ONLY']); }
    public function history(): JsonResponse { return response()->json(['execution_authorized'=>false,'predictions'=>V5ShadowPrediction::query()->latest('forecast_at')->limit(100)->get()]); }
    public function selections(): JsonResponse { return response()->json(['execution_authorized'=>false,'selections'=>V5ResearchSelection::where('user_id',auth()->id())->latest()->get()]); }
    public function select(\Illuminate\Http\Request $request): JsonResponse { $data=$request->validate(['symbol'=>'required|string|max:30','side'=>'required|in:LONG,SHORT','selection_price'=>'nullable|numeric|min:0','horizon'=>'nullable|string|max:20','expires_at'=>'nullable|date','notes'=>'nullable|string|max:1000']); $active=V5ResearchSelection::where('user_id',auth()->id())->where('status','TRACKING')->count(); if($active>=3) return response()->json(['message'=>'Maximum three active research selections'],422); $s=V5ResearchSelection::create($data+['id'=>(string)\Illuminate\Support\Str::uuid(),'user_id'=>auth()->id(),'selected_at'=>now(),'status'=>'TRACKING']); return response()->json(['execution_authorized'=>false,'selection'=>$s],201); }
    public function remove(string $id): JsonResponse { $s=V5ResearchSelection::where('user_id',auth()->id())->findOrFail($id); $s->update(['status'=>'REMOVED_BY_USER']); return response()->json(['execution_authorized'=>false,'selection'=>$s->fresh()]); }
    public function rankingHistory(): JsonResponse { $runs=V5ResearchScannerRun::with('candidates')->latest('as_of')->limit(20)->get()->reverse()->values(); $previous=[]; $snapshots=[]; foreach($runs as $run){$current=[]; foreach($run->candidates as $c){$current[$c->symbol]=(int)$c->rank;} $rows=[]; foreach($current as $symbol=>$rank){$rows[]=['symbol'=>$symbol,'current_rank'=>$rank,'previous_rank'=>$previous[$symbol]??null,'rank_movement'=>isset($previous[$symbol])?$previous[$symbol]-$rank:null,'new_entrant'=>!isset($previous[$symbol]),'execution_authorized'=>false];} foreach(array_diff_key($previous,$current) as $symbol=>$rank){$rows[]=['symbol'=>$symbol,'current_rank'=>null,'previous_rank'=>$rank,'rank_movement'=>null,'removed'=>true,'execution_authorized'=>false];} $snapshots[]=['as_of'=>$run->as_of,'rows'=>$rows]; $previous=$current;} return response()->json(['execution_authorized'=>false,'snapshots'=>$snapshots]); }
    public function selectionMetrics(): JsonResponse { $items=V5ResearchSelection::where('user_id',auth()->id())->get()->map(function($s){ $price=$s->selection_price; $latest=V5ResearchScannerRun::with('candidates')->latest('as_of')->first(); $c=$latest?->candidates->firstWhere('symbol',$s->symbol); $current=data_get($c?->evidence,'assessment.horizons.15m.reference_close'); $gross=($price && $current!==null)?(($s->side==='LONG'?1:-1)*(($current/$price)-1)):null; $history=[['at'=>$s->selected_at,'return'=>0.0]]; if($gross!==null){$history[]=['at'=>now(),'return'=>$gross];} return ['selection_id'=>$s->id,'symbol'=>$s->symbol,'side'=>$s->side,'selection_price'=>$price,'current_reference_price'=>$current,'return_since_selection'=>$gross,'estimated_after_cost_return'=>null,'history'=>$history,'status'=>$s->status,'execution_authorized'=>false]; }); return response()->json(['execution_authorized'=>false,'metrics'=>$items]); }
}
