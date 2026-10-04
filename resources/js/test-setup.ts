import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Sin `globals`, Testing Library no desmonta solo: un hook montado en un test
// seguiría escuchando eventos en el siguiente.
afterEach(() => {
    cleanup();
});
