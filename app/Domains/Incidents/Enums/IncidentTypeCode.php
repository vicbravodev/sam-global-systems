<?php

namespace App\Domains\Incidents\Enums;

enum IncidentTypeCode: string
{
    case PanicEmergency = 'panic_emergency';
    case Collision = 'collision';
    case CameraObstructed = 'camera_obstructed';
    case RouteDeviation = 'route_deviation';
    case GeofenceBreach = 'geofence_breach';
    case DriverFatigue = 'driver_fatigue';
    case SuspiciousStop = 'suspicious_stop';
    case EmergencyAlert = 'emergency_alert';
    case SafetyViolation = 'safety_violation';
    case ComplianceViolation = 'compliance_violation';
    case OperationalAlert = 'operational_alert';
    case Other = 'other';
}
