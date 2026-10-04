import { create as createDemoRequest } from '@/routes/demo-request';

export const CONTACT_EMAIL = 'contacto@samglobaltechnologies.com';
export const CONTACT_PHONE = '+52 81 1765 8890';
/** Formulario público de demo: se guarda para la consola y avisa por correo. */
export const DEMO_HREF = createDemoRequest.url();
