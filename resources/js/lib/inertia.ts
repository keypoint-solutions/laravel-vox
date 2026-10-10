/**
 * The first validation error of a failed Inertia request.
 */
export function firstError(errors: Record<string, string>, fallback = 'Request failed.'): string {
    return Object.values(errors)[0] ?? fallback;
}

/**
 * The success message flashed by the server, or the fallback when none was sent.
 */
export function flashSuccess(page: { flash?: Record<string, unknown> }, fallback: string): string {
    return (page.flash?.success as string | undefined) ?? fallback;
}

/**
 * Fill the `__name__` placeholder of a route template shared by the server.
 */
export function routeUrl(template: string | undefined, placeholder: string, id: number | string): string {
    return template?.replace(`__${placeholder}__`, String(id)) ?? '';
}
