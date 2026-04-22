### 1. Frontend

Aplicación web SaaS donde cada cliente accede solo a su información.

**Incluye:**

- Dashboard operativo
- Vista de activos
- Bandeja de alertas e incidentes
- Historial y auditoría
- Configuración por cliente
- Gestión de usuarios y roles

---

### 2. Backend

Núcleo del sistema, encargado de recibir eventos, aplicar lógica de negocio y exponer información al frontend.

**Responsabilidades:**

- API de ingesta de eventos
- Gestión multi-tenant
- Integración con plataformas externas
- Normalización de datos
- Gestión de eventos e incidentes
- Reglas operativas
- Notificaciones
- Auditoría

---

### 3. Capa AI

Motor inteligente que actúa como monitorista autónomo.

**Funciones:**

- Analizar eventos telemáticos
- Clasificar severidad
- Detectar falsos positivos
- Priorizar incidentes
- Generar contexto y explicación
- Recomendar o ejecutar acciones

**Ejemplos:**

- Botón de pánico
- Colisión
- Cámara obstruida
- Fatiga o conducción riesgosa

---

### 4. Base de datos

Persistencia de toda la operación y trazabilidad del sistema.

**Almacena:**

- Clientes / tenants
- Usuarios
- Activos
- Conductores
- Integraciones
- Eventos crudos
- Eventos normalizados
- Incidentes
- Evaluaciones AI
- Notificaciones
- Logs y auditoría

---

### 5. Colas y workers

Procesamiento asíncrono para soportar volumen y no bloquear el sistema.

**Se usan para:**

- Procesar webhooks
- Normalizar eventos
- Ejecutar análisis AI
- Crear incidentes
- Enviar notificaciones
- Sincronizar datos externos

---

## Flujo principal

### Flujo resumido

**Proveedor telemático → API de SAM → Normalización → DB → Cola → AI → Motor de decisión → Incidente / alerta → Frontend / notificación**

---

## Flujo detallado

1. Un proveedor externo como Samsara o Motive genera un evento.
2. SAM recibe el evento por webhook o API.
3. El evento crudo se guarda para auditoría.
4. Se normaliza a un formato interno estándar.
5. Se persiste en base de datos.
6. Se envía a una cola para análisis.
7. La capa AI evalúa el evento con su contexto.
8. El motor de reglas decide si:
    - se descarta,
    - se marca como informativo,
    - se convierte en incidente,
    - se escala.
9. Se guardan decisión, evidencia y acciones ejecutadas.
10. El resultado aparece en el dashboard y/o dispara notificaciones.

---

## Diagrama simple

Usuario / Cliente  
      ↓  
   Frontend  
      ↓  
    Backend  
      ↓  
API de ingesta / servicios  
      ↓  
 Normalización  
      ↓  
      DB  
      ↓  
 Colas / Workers  
      ↓  
   AI Engine  
      ↓  
Motor de decisiones  
      ↓  
Incidentes / Alertas / Notificaciones  
      ↓  
 Dashboard / Operación

---

## Descripción corta para entregar

**SAM tiene una arquitectura compuesta por un frontend SaaS multiempresa, un backend centralizado, una capa de inteligencia artificial, una base de datos relacional y un sistema de colas para procesamiento asíncrono. Su flujo principal inicia con la ingesta de eventos desde plataformas telemáticas, continúa con su normalización y análisis inteligente, y termina con la generación de alertas, incidentes y acciones visibles para cada cliente en tiempo real.**