<?php

namespace App\Domains\AI\Enums;

enum AIModelType: string
{
    case Llm = 'llm';
    case MultimodalLlm = 'multimodal_llm';
    case Classifier = 'classifier';
    case VisionModel = 'vision_model';
    case AnomalyModel = 'anomaly_model';
    case HeuristicPipeline = 'heuristic_pipeline';
}
