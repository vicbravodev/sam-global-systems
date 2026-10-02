/** Paginación que el servidor manda en toda lista paginada. */
export interface ListPagination {
    page: number;
    perPage: number;
    total: number;
    lastPage: number;
}

/** Valor por defecto mientras la prop aún no llega (o en tests). */
export const EMPTY_PAGINATION: ListPagination = {
    page: 1,
    perPage: 50,
    total: 0,
    lastPage: 1,
};
