# Recomendación importante para Laravel 13

A nivel implementación, no metería todo en carpetas genéricas de `app/Models`, `app/Services`, etc.

Para SAM conviene una estructura **modular por dominio**, por ejemplo:

app/  
└── Domains/  
    ├── Tenancy/  
    ├── Access/  
    ├── Integrations/  
    ├── Assets/  
    ├── Drivers/  
    ├── Events/  
    ├── AI/  
    ├── Decisions/  
    ├── Incidents/  
    ├── Notifications/  
    ├── Audit/  
    └── Analytics/

Y dentro de cada dominio:

Domain/  
├── Actions/  
├── Data/  
├── Enums/  
├── Events/  
├── Jobs/  
├── Listeners/  
├── Models/  
├── Policies/  
├── Services/  
├── DTOs/  
├── Queries/  
└── Support/

Eso te ayuda muchísimo cuando el proyecto crezca.