# Frontend (resources/js/)

- Páginas en `pages/`, primitivas en `components/ui/`, componentes de producto en `components/sam/`. Reutiliza antes de crear.
- Rutas tipadas con Wayfinder: importa de `@/actions/` (controladores) o `@/routes/` (rutas con nombre). Son generados y gitignored: tras cambiar rutas, `php artisan wayfinder:generate --with-form` (o `npm run build`).
- **Inertia v3:** Axios fue removido (no lo añadas): usa `useForm` / `useHttp`. `Inertia::lazy()` ya no existe (`Inertia::optional()`). Eventos renombrados: `invalid` → `httpException`, `exception` → `networkError`; `router.cancel()` → `router.cancelAll()`. Props diferidas llevan skeleton animado.
- **Tokens de diseño** en `@theme` de `resources/css/app.css`. Usa las utilities (`text-3xs`…`text-3xl`, `tracking-label`, `tracking-caps`, `rounded-sm/md/lg/xl`), nunca valores arbitrarios (`text-[12px]`). Ojo: `text-sm` = 13px y `text-base` = 14px (no los defaults de Tailwind).
- Si un cambio no se ve en la UI, falta `npm run dev` / `npm run build`. Error "Unable to locate file in Vite manifest" → `npm run build`.
- Gate: `npm run types:check && npm run lint:check && npm run format:check`.
